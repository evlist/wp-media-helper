<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Admin\OrphansPage;
use WP_Media_Helper\Maintenance\AttachmentCleaner;
use WP_Media_Helper\Maintenance\OrphanCheck;
use WP_Media_Helper\Maintenance\OrphanRegistry;
use WP_Media_Helper\Maintenance\OrphanReport;

class OrphanTest extends TestCase {

	private string $base;
	private FakeWpdb $db;
	/** @var array<string, mixed> */
	private array $stored = [];
	private float $time = 0.0;

	protected function setUp(): void {
		$this->base = realpath( sys_get_temp_dir() ) . '/wpmh_orphan_' . uniqid();
		foreach ( [ 'photos/eric', 'photos/gisele', 'photos/eric/nested', 'photos/empty', 'wp/2024' ] as $directory ) {
			mkdir( $this->base . '/' . $directory, 0755, true );
		}
		foreach ( [ 'photos/eric/a.jpg', 'photos/eric/nested/n1.jpg', 'photos/gisele/g1.jpg', 'photos/gisele/g2.jpg', 'wp/2024/w1.jpg' ] as $file ) {
			touch( $this->base . '/' . $file );
		}
		$this->db = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->db;

		// 1, 2: eric (2 has no file); 3: nested (no file); 4, 5: gisele (fine); 6: wp uploads (no file); 7: empty dir.
		foreach ( [
			[ 1, 'photos/eric/a.jpg' ],
			[ 2, 'photos/eric/b.jpg' ],
			[ 3, 'photos/eric/nested/n2.jpg' ],
			[ 31, 'photos/eric/nested/n1.jpg' ],
			[ 4, 'photos/gisele/g1.jpg' ],
			[ 5, 'photos/gisele/g2.jpg' ],
			[ 6, 'wp/2024/w2.jpg' ],
			[ 7, 'wp/2024/w1.jpg' ],
			[ 8, 'photos/empty/e1.jpg' ],
		] as $row ) {
			$this->db->insert( 'wp_posts', [ 'ID' => $row[0], 'post_type' => 'attachment', 'post_mime_type' => 'image/jpeg', 'post_title' => 't' . $row[0] ] );
			$this->db->insert( 'wp_postmeta', [ 'post_id' => $row[0], 'meta_key' => '_wp_attached_file', 'meta_value' => $row[1] ] );
		}
	}

	protected function tearDown(): void {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->base, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $file ) {
			$file->isDir() && ! $file->isLink() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->base );
	}

	private function registry(): OrphanRegistry {
		return new OrphanRegistry(
			fn(): mixed => $this->stored,
			function ( array $value ): void {
				$this->stored = $value;
			}
		);
	}

	private function check( float $seconds = 100.0, int $now = 1000, array $prefixes = [ 'photos/eric', 'photos/eric/nested', 'photos/gisele' ] ): bool {
		return OrphanCheck::step( $this->registry(), new AttachmentCleaner( $this->base ), $prefixes, $seconds, $now, fn(): float => $this->time );
	}

	public function test_a_check_finds_the_attachments_without_file_group_by_group(): void {
		$more = $this->check();

		$this->assertFalse( $more );
		$state = $this->registry()->get();
		$this->assertSame( [ 2, 3, 6, 8 ], array_keys( $state['ids'] ) );
		$this->assertSame( 1000, $state['checked_at'] );
		$this->assertSame( [], $state['unavailable'] );
		$this->assertSame( 'photos/eric', $state['where'][2] );
		$this->assertSame( 'photos/eric/nested', $state['where'][3] );
		$this->assertSame( '.', $state['where'][6] );
		$this->assertSame( '.', $state['where'][8] );
	}

	public function test_a_nested_source_is_checked_on_its_own_and_not_twice(): void {
		$this->check();

		$state = $this->registry()->get();
		$this->assertSame( 9, $state['scanned'], 'Every attachment is checked once, in the group of the longest source root that holds it.' );
	}

	public function test_an_orphan_seen_before_keeps_the_date_it_was_first_seen_and_a_file_that_came_back_leaves_the_list(): void {
		$this->check( 100.0, 1000 );
		touch( $this->base . '/photos/eric/b.jpg' );

		$this->check( 100.0, 2000 );

		$state = $this->registry()->get();
		$this->assertSame( [ 3, 6, 8 ], array_keys( $state['ids'] ) );
		$this->assertSame( 1000, $state['ids'][3] );
		$this->assertSame( 2000, $state['checked_at'] );
	}

	public function test_an_ignored_orphan_is_not_counted_and_is_forgotten_when_its_file_comes_back(): void {
		$this->check();
		$registry = $this->registry();
		$registry->ignore( [ 3, 6 ] );

		$this->assertSame( [ 2, 8 ], $registry->open() );
		$this->assertSame( [ 3, 6 ], $registry->ignored() );

		touch( $this->base . '/photos/eric/nested/n2.jpg' );
		$this->check( 100.0, 3000 );
		$this->assertSame( [ 6 ], $this->registry()->ignored() );
	}

	public function test_a_directory_that_is_not_mounted_is_unavailable_and_its_known_orphans_are_kept(): void {
		$this->check();
		rename( $this->base . '/photos/gisele', $this->base . '/photos/gisele-away' );
		$this->db->query( "UPDATE wp_postmeta SET meta_value = 'photos/gisele/g3.jpg' WHERE post_id = 5" );
		$this->check( 100.0, 2000 );
		$this->assertSame( [ 2, 3, 6, 8 ], array_keys( $this->registry()->get()['ids'] ) );

		// Now the group has no file for any attachment: nobody is reported.
		mkdir( $this->base . '/photos/gisele', 0755 );
		touch( $this->base . '/photos/gisele/other.txt' );
		$this->db->query( "UPDATE wp_postmeta SET meta_value = 'photos/gisele/g4.jpg' WHERE post_id = 4" );
		$this->check( 100.0, 3000 );

		$state = $this->registry()->get();
		$this->assertSame( [ 'photos/gisele' => 'no attachment has its file' ], $state['unavailable'] );
		$this->assertSame( [ 2, 3, 6, 8 ], array_keys( $state['ids'] ) );
	}

	public function test_an_unreadable_group_gives_no_orphan_and_is_named(): void {
		$this->check( 100.0, 1000, [ 'photos/eric', 'photos/eric/nested', 'photos/gisele', 'photos/empty' ] );

		$state = $this->registry()->get();
		$this->assertSame( [ 'photos/empty' => 'not readable or empty' ], $state['unavailable'] );
		$this->assertTrue( ! isset( $state['ids'][8] ) );
	}

	public function test_a_check_that_runs_out_of_time_goes_on_at_the_next_step(): void {
		$tick = 0.0;
		$more = OrphanCheck::step( $this->registry(), new AttachmentCleaner( $this->base ), [ 'photos/eric', 'photos/gisele' ], 5.0, 1000, function () use ( &$tick ): float {
			$tick += 10.0;

			return $tick;
		} );

		$this->assertTrue( $more );
		$this->assertSame( 0, $this->registry()->get()['checked_at'], 'The result is replaced only when the check is complete.' );
		$this->assertTrue( 1000 === $this->registry()->get()['started_at'] );

		$this->assertFalse( $this->check( 100.0, 1100, [ 'photos/eric', 'photos/gisele' ] ) );
		$this->assertTrue( 0 < $this->registry()->get()['checked_at'] );
	}

	public function test_the_health_report_says_good_recommended_or_unavailable(): void {
		$good = OrphanReport::health( [ 'checked_at' => 5, 'scanned' => 12 ], 10 );
		$this->assertSame( 'good', $good['status'] );

		$bad = OrphanReport::health( [ 'ids' => [ 2 => 1, 3 => 1 ], 'ignored' => [ 3 ] ], 10 );
		$this->assertSame( 'recommended', $bad['status'] );
		$this->assertTrue( str_contains( $bad['label'], '1 attachment ' ) );

		$off = OrphanReport::health( [ 'unavailable' => [ 'photos/x' => 'no attachment has its file' ] ], 10 );
		$this->assertSame( 'recommended', $off['status'] );
		$this->assertTrue( str_contains( $off['paragraphs'][0], 'photos/x' ) );
		$this->assertSame( [], OrphanReport::notice( [ 'checked_at' => 5 ] ) );
		$this->assertSame( 1, count( OrphanReport::notice( [ 'ids' => [ 2 => 1 ] ] ) ) );
	}

	public function test_the_cells_of_the_csv_cannot_be_taken_for_formulas(): void {
		$this->assertSame( "'=HYPERLINK(1)", OrphansPage::cell( '=HYPERLINK(1)' ) );
		$this->assertSame( "'-1+1", OrphansPage::cell( '-1+1' ) );
		$this->assertSame( 'photos/a.jpg', OrphansPage::cell( 'photos/a.jpg' ) );
		$this->assertSame( '12', OrphansPage::cell( 12 ) );
	}
}
