<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

// The functions of WordPress that the remapper calls, in its namespace so that no other test sees them.
namespace WP_Media_Helper\Maintenance {

	function get_post_meta( int $id, string $key, bool $single = false ) {
		$value = $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT meta_value FROM wp_postmeta WHERE post_id = %d AND meta_key = %s", $id, $key ) );

		return null === $value ? '' : $value;
	}

	function update_post_meta( int $id, string $key, $value ) {
		$wpdb = $GLOBALS['wpdb'];
		if ( null === $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM wp_postmeta WHERE post_id = %d AND meta_key = %s", $id, $key ) ) ) {
			$wpdb->insert( 'wp_postmeta', [ 'post_id' => $id, 'meta_key' => $key, 'meta_value' => $value ] );
		} else {
			$wpdb->query( $wpdb->prepare( "UPDATE wp_postmeta SET meta_value = %s WHERE post_id = %d AND meta_key = %s", $value, $id, $key ) );
		}

		return true;
	}

	function wp_slash( $value ) {
		return $value;
	}

	function wp_get_attachment_metadata( int $id ) {
		return $GLOBALS['wpmh_test_metadata'][ $id ] ?? false;
	}

	function wp_update_attachment_metadata( int $id, array $data ) {
		$GLOBALS['wpmh_test_metadata'][ $id ] = $data;

		return true;
	}

	function clean_post_cache( int $id ): void {}
}

namespace {

	use PHPUnit\Framework\TestCase;
	use WP_Media_Helper\Maintenance\PathMap;
	use WP_Media_Helper\Maintenance\PathRemapper;

	class PathRemapTest extends TestCase {

		private string $base;
		private FakeWpdb $db;

		protected function setUp(): void {
			$this->base = realpath( sys_get_temp_dir() ) . '/wpmh_remap_' . uniqid();
			mkdir( $this->base . '/cumulus/eric/2026/10/08', 0755, true );
			mkdir( $this->base . '/thumbnails/photos/2026/eric/10/08', 0755, true );
			touch( $this->base . '/cumulus/eric/2026/10/08/a.jpg' );
			touch( $this->base . '/cumulus/eric/2026/10/08/b.jpg' );
			touch( $this->base . '/cumulus/eric/2026/10/08/c.jpg' );
			touch( $this->base . '/thumbnails/photos/2026/eric/10/08/a-150x150.jpg' );

			$GLOBALS['wpmh_test_metadata'] = [ 7 => [ 'file' => 'photos/2026/eric/10/08/a.jpg', 'sizes' => [ 'thumbnail' => [ 'file' => 'a-150x150.jpg' ] ] ] ];
			$this->db = new FakeWpdb();
			$GLOBALS['wpdb'] = $this->db;

			$old = 'https://e.example/wp-content/uploads/photos/2026/eric/10/08/a.jpg';
			$this->db->insert( 'wp_posts', [ 'ID' => 7, 'guid' => $old, 'post_type' => 'attachment' ] );
			$this->db->insert( 'wp_posts', [ 'ID' => 8, 'guid' => 'x', 'post_type' => 'attachment' ] );
			$this->db->insert( 'wp_posts', [ 'ID' => 9, 'guid' => 'y', 'post_type' => 'attachment' ] );
			$this->db->insert( 'wp_posts', [ 'ID' => 10, 'guid' => 'z', 'post_type' => 'attachment' ] );
			$this->db->insert( 'wp_posts', [ 'ID' => 11, 'guid' => 'w', 'post_type' => 'attachment' ] );
			$this->db->insert( 'wp_posts', [ 'ID' => 60, 'guid' => 'f', 'post_type' => 'mgmlp_media_folder' ] );
			$this->db->insert( 'wp_posts', [ 'ID' => 50, 'guid' => 'p', 'post_type' => 'post', 'post_content' => '<img src="https://e.example/wp-content/uploads/photos/2026/eric/10/08/a.jpg">' ] );
			foreach ( [
				[ 7, '_wp_attached_file', 'photos/2026/eric/10/08/a.jpg' ],
				[ 7, '_wp_media_helper_source_id', 'eric-2026' ],
				[ 7, '_wp_media_helper_source_path', 'photos/2026/eric/10/08/a.jpg' ],
				[ 8, '_wp_attached_file', 'photos/2026/eric/10/08/gone.jpg' ],
				[ 9, '_wp_attached_file', 'photos/2026/eric/10/08/c.jpg' ],
				[ 10, '_wp_attached_file', 'cumulus/eric/2026/10/08/c.jpg' ],
				[ 11, '_wp_attached_file', 'photos/gisele/other.jpg' ],
				[ 60, '_wp_attached_file', 'photos/2026/eric/folder' ],
			] as $meta ) {
				$this->db->insert( 'wp_postmeta', [ 'post_id' => $meta[0], 'meta_key' => $meta[1], 'meta_value' => $meta[2] ] );
			}

			$this->db->insert( 'wp_media_helper_dirs', [ 'source_id' => 'eric-2026', 'parent_id' => 0, 'path_hash' => sha1( 'photos/2026/eric' ), 'path' => 'photos/2026/eric', 'mtime' => 100, 'scanned_run' => 3 ] );
			$this->db->insert( 'wp_media_helper_dirs', [ 'source_id' => 'eric-2026', 'parent_id' => 1, 'path_hash' => sha1( 'photos/2026/eric/10' ), 'path' => 'photos/2026/eric/10', 'mtime' => 100, 'scanned_run' => 3 ] );
			$this->db->insert( 'wp_media_helper_files', [ 'source_id' => 'eric-2026', 'dir_id' => 2, 'path_hash' => sha1( 'photos/2026/eric/10/08/a.jpg' ), 'path' => 'photos/2026/eric/10/08/a.jpg', 'name' => 'a.jpg', 'hidden' => 1, 'hidden_by' => 3, 'date_override' => '2026-10-08 10:00:00' ] );
		}

		protected function tearDown(): void {
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->base, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $file ) {
				$file->isDir() && ! $file->isLink() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
			}
			rmdir( $this->base );
		}

		private function map(): PathMap {
			return new PathMap( [ [ 'from' => 'photos/2026/eric', 'to' => 'cumulus/eric/2026', 'source' => 'cumulus-eric', 'from_source' => 'eric-2026', 'root' => 'cumulus/eric' ] ] );
		}

		private function remapper(): PathRemapper {
			return new PathRemapper( $this->base, 'https://e.example/wp-content/uploads', $this->base . '/thumbnails' );
		}

		public function test_the_longest_prefix_wins_and_only_whole_directories_match(): void {
			$map = new PathMap( [
				[ 'from' => 'photos', 'to' => 'a', 'source' => 's' ],
				[ 'from' => 'photos/eric', 'to' => 'b', 'source' => 's' ],
			] );

			$this->assertSame( 'b/10/x.jpg', $map->map( 'photos/eric/10/x.jpg' )['path'] );
			$this->assertSame( 'a/gisele/x.jpg', $map->map( 'photos/gisele/x.jpg' )['path'] );
			$this->assertSame( 'a/eric2/x.jpg', $map->map( 'photos/eric2/x.jpg' )['path'] );
			$this->assertNull( $map->map( 'other/x.jpg' ) );
		}

		public function test_the_inverse_map_puts_the_prefixes_and_sources_back(): void {
			$inverse = $this->map()->inverse();

			$this->assertSame( 'photos/2026/eric/10/a.jpg', $inverse->map( 'cumulus/eric/2026/10/a.jpg' )['path'] );
			$this->assertSame( 'eric-2026', $inverse->entries()[0]['source'] );
			$this->assertSame( 'photos/2026/eric', $inverse->entries()[0]['root'] );
		}

		public function test_paths_that_leave_the_uploads_directory_are_refused(): void {
			foreach ( [ '../x', 'a/../b', '/abs', 'a//b', '' ] as $bad ) {
				$refused = false;
				try {
					new PathMap( [ [ 'from' => $bad, 'to' => 'ok', 'source' => 's' ] ] );
				} catch ( InvalidArgumentException $exception ) {
					$refused = true;
				}
				$this->assertTrue( $refused, $bad );
			}
		}

		public function test_a_dry_run_reports_and_changes_nothing(): void {
			$report = $this->remapper()->run( $this->map(), false );

			$this->assertSame( 1, $report['attachments']['moved'] );
			$this->assertSame( [ 8 ], $report['attachments']['missing'] );
			$this->assertSame( [ 9 ], $report['attachments']['collisions'] );
			$this->assertSame( 1, $report['index']['files'] );
			$this->assertSame( 2, $report['index']['directories'] );
			$this->assertSame( 1, $report['index']['joined'] );
			$this->assertSame( 1, $report['cache']['moved'] );
			$this->assertSame( 1, $report['urls']['posts'] );
			$this->assertSame( 'photos/2026/eric/10/08/a.jpg', $GLOBALS['wpmh_test_metadata'][7]['file'] );
			$this->assertSame( 1, count( $this->db->get_results( "SELECT id FROM wp_media_helper_dirs WHERE path = 'photos/2026/eric'" ) ) );
			$this->assertTrue( is_dir( $this->base . '/thumbnails/photos/2026/eric' ) );
		}

		public function test_applying_rewrites_the_attachment_and_keeps_its_id(): void {
			$this->remapper()->run( $this->map(), true );

			$file = $this->db->get_var( "SELECT meta_value FROM wp_postmeta WHERE post_id = 7 AND meta_key = '_wp_attached_file'" );
			$this->assertSame( 'cumulus/eric/2026/10/08/a.jpg', $file );
			$this->assertSame( 'cumulus/eric/2026/10/08/a.jpg', $GLOBALS['wpmh_test_metadata'][7]['file'] );
			$this->assertSame( 'a-150x150.jpg', $GLOBALS['wpmh_test_metadata'][7]['sizes']['thumbnail']['file'] );
			$this->assertSame( 'cumulus-eric', $this->db->get_var( "SELECT meta_value FROM wp_postmeta WHERE post_id = 7 AND meta_key = '_wp_media_helper_source_id'" ) );
			$this->assertSame( 'cumulus/eric/2026/10/08/a.jpg', $this->db->get_var( "SELECT meta_value FROM wp_postmeta WHERE post_id = 7 AND meta_key = '_wp_media_helper_source_path'" ) );
			$this->assertSame( 'https://e.example/wp-content/uploads/cumulus/eric/2026/10/08/a.jpg', $this->db->get_var( 'SELECT guid FROM wp_posts WHERE ID = 7' ) );
		}

		public function test_an_attachment_without_file_or_in_collision_is_left_alone(): void {
			$this->remapper()->run( $this->map(), true );

			$this->assertSame( 'photos/2026/eric/10/08/gone.jpg', $this->db->get_var( "SELECT meta_value FROM wp_postmeta WHERE post_id = 8 AND meta_key = '_wp_attached_file'" ) );
			$this->assertSame( 'photos/2026/eric/10/08/c.jpg', $this->db->get_var( "SELECT meta_value FROM wp_postmeta WHERE post_id = 9 AND meta_key = '_wp_attached_file'" ) );
			$this->assertSame( 'photos/gisele/other.jpg', $this->db->get_var( "SELECT meta_value FROM wp_postmeta WHERE post_id = 11 AND meta_key = '_wp_attached_file'" ) );
		}

		public function test_the_index_is_rewritten_in_place_and_keeps_the_trash_and_the_dates_set_by_hand(): void {
			$this->remapper()->run( $this->map(), true );

			$row = $this->db->get_row( 'SELECT * FROM wp_media_helper_files WHERE id = 1' );
			$this->assertSame( 'cumulus/eric/2026/10/08/a.jpg', $row['path'] );
			$this->assertSame( sha1( 'cumulus/eric/2026/10/08/a.jpg' ), $row['path_hash'] );
			$this->assertSame( 'cumulus-eric', $row['source_id'] );
			$this->assertSame( '1', (string) $row['hidden'] );
			$this->assertSame( '2026-10-08 10:00:00', $row['date_override'] );

			$top = $this->db->get_row( 'SELECT * FROM wp_media_helper_dirs WHERE id = 1' );
			$this->assertSame( 'cumulus/eric/2026', $top['path'] );
			$this->assertSame( '100', (string) $top['mtime'] );
			$join = $this->db->get_row( "SELECT * FROM wp_media_helper_dirs WHERE path = 'cumulus/eric'" );
			$this->assertSame( (string) $join['id'], (string) $top['parent_id'] );
			$this->assertSame( '0', (string) $join['parent_id'] );
			$this->assertTrue( null === $join['mtime'] );
		}

		public function test_the_thumbnails_move_inside_the_cache(): void {
			$this->remapper()->run( $this->map(), true );

			$this->assertTrue( is_file( $this->base . '/thumbnails/cumulus/eric/2026/10/08/a-150x150.jpg' ) );
			$this->assertFalse( is_dir( $this->base . '/thumbnails/photos/2026/eric' ) );
		}

		public function test_the_cache_can_be_left_alone(): void {
			$this->remapper()->run( $this->map(), true, false );

			$this->assertTrue( is_file( $this->base . '/thumbnails/photos/2026/eric/10/08/a-150x150.jpg' ) );
		}

		public function test_the_inverse_map_undoes_a_run(): void {
			$this->remapper()->run( $this->map(), true );
			// The old mount is still there while a run can be undone.
			mkdir( $this->base . '/photos/2026/eric/10/08', 0755, true );
			touch( $this->base . '/photos/2026/eric/10/08/a.jpg' );
			$this->remapper()->run( $this->map()->inverse(), true );

			$this->assertSame( 'photos/2026/eric/10/08/a.jpg', $this->db->get_var( "SELECT meta_value FROM wp_postmeta WHERE post_id = 7 AND meta_key = '_wp_attached_file'" ) );
			$this->assertSame( 'eric-2026', $this->db->get_var( "SELECT meta_value FROM wp_postmeta WHERE post_id = 7 AND meta_key = '_wp_media_helper_source_id'" ) );
			$row = $this->db->get_row( 'SELECT * FROM wp_media_helper_files WHERE id = 1' );
			$this->assertSame( 'photos/2026/eric/10/08/a.jpg', $row['path'] );
			$this->assertSame( 'eric-2026', $row['source_id'] );
			$this->assertSame( '0', (string) $this->db->get_var( 'SELECT parent_id FROM wp_media_helper_dirs WHERE id = 1' ) );
		}

		public function test_an_index_row_already_at_the_new_path_is_reported_not_overwritten(): void {
			$this->db->insert( 'wp_media_helper_files', [ 'source_id' => 'cumulus-eric', 'dir_id' => 9, 'path_hash' => sha1( 'cumulus/eric/2026/10/08/a.jpg' ), 'path' => 'cumulus/eric/2026/10/08/a.jpg', 'name' => 'a.jpg' ] );

			$report = $this->remapper()->run( $this->map(), true );

			$this->assertSame( 1, $report['index']['collisions'] );
			$this->assertSame( 'photos/2026/eric/10/08/a.jpg', $this->db->get_var( 'SELECT path FROM wp_media_helper_files WHERE id = 1' ) );
		}

		public function test_the_dates_of_the_index_can_be_read_by_path_and_by_source(): void {
			$store = new WP_Media_Helper\Index\WpdbIndexStore();

			$rows = $store->rowsByKeys( [ 'photos/2026/eric/10/08/a.jpg', 'unknown.jpg' ] );
			$this->assertSame( 1, count( $rows ) );
			$this->assertSame( '2026-10-08 10:00:00', $rows[0]['date_override'] );
			$this->assertSame( 'photos/2026/eric/10/08/a.jpg', $store->sampleRows( 'eric-2026', 5 )[0]['path'] );
			$this->assertSame( [], $store->sampleRows( 'nobody', 5 ) );
		}

		public function test_the_contents_can_be_left_unexamined_and_the_lines_are_still_given(): void {
			$report = $this->remapper()->run( $this->map(), false, true, false );

			$this->assertFalse( $report['urls']['counted'] );
			$this->assertSame( 0, $report['urls']['posts'] );
			$this->assertSame( 1, count( $report['urls']['search_replace'] ) );
			$this->assertSame( 1, $report['attachments']['moved'] );
		}

		public function test_the_progress_is_told_and_the_old_and_new_paths_are_found_by_one_read_each(): void {
			$told = [];
			$remapper = $this->remapper();
			$remapper->onProgress( static function ( string $message ) use ( &$told ): void {
				$told[] = $message;
			} );
			$before = $this->db->queries;

			$remapper->run( $this->map(), false, true, false );

			$this->assertTrue( in_array( 'attachments: 3 checked, done', $told, true ), implode( '|', $told ) );
			// No query by attachment: the work does not grow with the number of attachments squared.
			$this->assertTrue( $this->db->queries - $before < 25, (string) ( $this->db->queries - $before ) );
		}

		public function test_posts_that_are_not_attachments_are_ignored_and_counted_by_type_not_reported_as_missing(): void {
			$report = $this->remapper()->run( $this->map(), false, true, false );

			$this->assertSame( [ 'mgmlp_media_folder' => 1 ], $report['ignored'] );
			$this->assertSame( [ 8 ], $report['attachments']['missing'] );
			$this->assertSame( 'photos/2026/eric/folder', $this->db->get_var( "SELECT meta_value FROM wp_postmeta WHERE post_id = 60 AND meta_key = '_wp_attached_file'" ) );
		}
	}
}
