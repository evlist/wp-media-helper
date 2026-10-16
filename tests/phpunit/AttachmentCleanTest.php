<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Maintenance\AttachmentCleaner;

class AttachmentCleanTest extends TestCase {

	private string $base;
	private FakeWpdb $db;
	/** @var int[][] */
	private array $removedCalls = [];

	protected function setUp(): void {
		$this->base = realpath( sys_get_temp_dir() ) . '/wpmh_clean_' . uniqid();
		mkdir( $this->base . '/photos/eric', 0755, true );
		mkdir( $this->base . '/photos/empty', 0755, true );
		mkdir( $this->base . '/photos/lost', 0755, true );
		touch( $this->base . '/photos/eric/a.jpg' );
		touch( $this->base . '/photos/eric/dd.jpg' );
		touch( $this->base . '/photos/lost/keep-the-directory-readable.txt' );
		$this->db = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->db;

		$attachments = [
			[ 1, 'photos/eric/a.jpg', 100 ],
			[ 2, 'photos/eric/a.jpg', 100 ],
			[ 3, 'photos/eric/dd.jpg', 5 ],
			[ 4, 'photos/eric/dd.jpg', 6 ],
			[ 5, 'photos/eric/gone.jpg', 0 ],
			[ 6, 'photos/eric/gone2.jpg', 0 ],
			[ 8, 'photos/gisele/x.jpg', 0 ],
			[ 20, 'photos/lost/one.jpg', 0 ],
			[ 21, 'photos/lost/two.jpg', 0 ],
		];
		foreach ( $attachments as $row ) {
			$this->db->insert( 'wp_posts', [ 'ID' => $row[0], 'post_type' => 'attachment', 'post_parent' => $row[2], 'post_mime_type' => 'image/jpeg', 'post_title' => 't' . $row[0], 'post_date' => '2026-01-01 00:00:00', 'post_author' => 1 ] );
			$this->db->insert( 'wp_postmeta', [ 'post_id' => $row[0], 'meta_key' => '_wp_attached_file', 'meta_value' => $row[1] ] );
		}
		$this->db->insert( 'wp_posts', [ 'ID' => 7, 'post_type' => 'mgmlp_media_folder' ] );
		$this->db->insert( 'wp_postmeta', [ 'post_id' => 7, 'meta_key' => '_wp_attached_file', 'meta_value' => 'photos/eric/folder' ] );
		// 5 is the featured image of post 200 and in the content of 201; 55 is another image; 2 (a duplicate) is the featured image of 210.
		$this->db->insert( 'wp_postmeta', [ 'post_id' => 200, 'meta_key' => '_thumbnail_id', 'meta_value' => '5' ] );
		$this->db->insert( 'wp_postmeta', [ 'post_id' => 210, 'meta_key' => '_thumbnail_id', 'meta_value' => '2' ] );
		$this->db->insert( 'wp_posts', [ 'ID' => 201, 'post_type' => 'post', 'post_content' => '<img class="wp-image-5" src="x">' ] );
		$this->db->insert( 'wp_posts', [ 'ID' => 202, 'post_type' => 'post', 'post_content' => '<img class="wp-image-55" src="x">' ] );
		$this->db->insert( 'wp_media_helper_files', [ 'source_id' => 'other', 'dir_id' => 1, 'path_hash' => sha1( 'photos/other/gone2.jpg' ), 'path' => 'photos/other/gone2.jpg', 'name' => 'gone2.jpg' ] );
	}

	protected function tearDown(): void {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->base, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $file ) {
			$file->isDir() && ! $file->isLink() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->base );
	}

	private function cleaner(): AttachmentCleaner {
		return new AttachmentCleaner( $this->base, function ( array $ids ): array {
			$this->removedCalls[] = $ids;
			foreach ( $ids as $id ) {
				$this->db->query( "DELETE FROM wp_posts WHERE ID = {$id}" );
				$this->db->query( "DELETE FROM wp_postmeta WHERE post_id = {$id}" );
			}

			return $ids;
		} );
	}

	public function test_only_the_attachments_of_the_scope_are_looked_at_and_the_other_posts_are_counted_by_type(): void {
		$plan = $this->cleaner()->plan( [ 'photos/eric' ], true, true );

		$this->assertSame( 6, $plan['scanned'] );
		$this->assertSame( [ 'mgmlp_media_folder' => 1 ], $plan['ignored'] );
	}

	public function test_a_duplicate_has_the_same_path_and_parent_and_the_lowest_id_stays(): void {
		$plan = $this->cleaner()->plan( [ 'photos/eric' ], true, false );

		$this->assertSame( [ [ 'path' => 'photos/eric/a.jpg', 'keep' => 1, 'remove' => [ 2 ], 'parent' => 100 ] ], $plan['duplicates']['groups'] );
		$this->assertSame( [ 2 ], array_column( $plan['duplicates']['items'], 'id' ) );
		$this->assertSame( 1, $plan['duplicates']['items'][0]['duplicate_of'] );
		$this->assertSame( [], $plan['missing']['items'] );
	}

	public function test_the_same_path_with_different_parents_is_reported_and_not_removed(): void {
		$plan = $this->cleaner()->plan( [ 'photos/eric' ], true, false );

		$this->assertSame( [ [ 'path' => 'photos/eric/dd.jpg', 'ids' => [ 3, 4 ], 'parents' => [ 5, 6 ] ] ], $plan['duplicates']['parents_differ'] );
	}

	public function test_a_missing_file_is_found_with_where_it_is_used_and_where_the_same_name_is_elsewhere(): void {
		$plan = $this->cleaner()->plan( [ 'photos/eric' ], true, true );

		$this->assertSame( [ 5, 6 ], array_column( $plan['missing']['items'], 'id' ) );
		$five = $plan['missing']['items'][0];
		$this->assertSame( [ 200 ], $five['used']['featured'] );
		$this->assertSame( [ 201 ], $five['used']['content'] );
		$this->assertSame( 'image', $five['media'] );
		$six = $plan['missing']['items'][1];
		$this->assertSame( [ 'photos/other/gone2.jpg' ], $six['found_elsewhere'] );
		$this->assertSame( [], $six['used']['featured'] );
	}

	public function test_a_duplicate_already_removed_by_the_run_is_not_counted_as_missing(): void {
		mkdir( $this->base . '/photos/dup', 0755, true );
		touch( $this->base . '/photos/dup/other.jpg' );
		foreach ( [ 30, 31 ] as $id ) {
			$this->db->insert( 'wp_posts', [ 'ID' => $id, 'post_type' => 'attachment', 'post_mime_type' => 'image/jpeg' ] );
			$this->db->insert( 'wp_postmeta', [ 'post_id' => $id, 'meta_key' => '_wp_attached_file', 'meta_value' => 'photos/dup/absent.jpg' ] );
		}

		$plan = $this->cleaner()->plan( [ 'photos/dup' ], true, true );

		$this->assertSame( [ 31 ], array_column( $plan['duplicates']['items'], 'id' ) );
		$this->assertSame( [ 30 ], array_column( $plan['missing']['items'], 'id' ) );
	}

	public function test_applying_removes_the_records_repoints_the_featured_image_and_a_second_run_finds_nothing(): void {
		$cleaner = $this->cleaner();
		$plan    = $cleaner->plan( [ 'photos/eric' ], true, true );

		$removed = $cleaner->apply( $plan );

		$this->assertSame( [ 2, 5, 6 ], $removed );
		$this->assertSame( '1', (string) $this->db->get_var( "SELECT meta_value FROM wp_postmeta WHERE post_id = 210 AND meta_key = '_thumbnail_id'" ) );
		$again = $cleaner->plan( [ 'photos/eric' ], true, true );
		$this->assertSame( [], $again['duplicates']['items'] );
		$this->assertSame( [], $again['missing']['items'] );
		$this->assertSame( 1, count( $again['duplicates']['parents_differ'] ) );
		$this->assertTrue( is_file( $this->base . '/photos/eric/a.jpg' ), 'No file is ever removed.' );
	}

	public function test_an_empty_or_absent_directory_refuses_the_search_for_missing_files(): void {
		foreach ( [ 'photos/empty', 'photos/nowhere' ] as $prefix ) {
			$refused = false;
			try {
				$this->cleaner()->plan( [ $prefix ], false, true );
			} catch ( RuntimeException $exception ) {
				$refused = true;
				$this->assertTrue( str_contains( $exception->getMessage(), $prefix ) );
			}
			$this->assertTrue( $refused, $prefix );
		}
	}

	public function test_a_directory_whose_attachments_all_miss_their_file_refuses_the_search(): void {
		$refused = false;
		try {
			$this->cleaner()->plan( [ 'photos/lost' ], false, true );
		} catch ( RuntimeException $exception ) {
			$refused = true;
			$this->assertTrue( str_contains( $exception->getMessage(), 'photos/lost' ) );
		}
		$this->assertTrue( $refused );
	}

	public function test_duplicates_alone_do_not_need_the_directories(): void {
		$plan = $this->cleaner()->plan( [ 'photos/empty' ], true, false );

		$this->assertSame( [], $plan['duplicates']['items'] );
	}

	public function test_links_of_other_attachment_methods_are_reported_and_a_refusal_is_listed(): void {
		$native = new RecordRemoverFakeMethod( 'native' );
		$other  = new RecordRemoverFakeMethod( 'otherguise', [ 5 ], [ 5 => [ 300, 301 ] ] );
		$cleaner = new AttachmentCleaner( $this->base, null, fn(): array => [ 'native' => $native, 'otherguise' => $other ] );

		$plan = $cleaner->plan( [ 'photos/eric' ], false, true );

		$five = $plan['missing']['items'][0];
		$this->assertSame( [ 'otherguise' => [ 300, 301 ] ], $five['links'] );
		$this->assertSame( [], $plan['missing']['items'][1]['links'] );
	}
}
