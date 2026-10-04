<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Index\IndexScanner;
use WP_Media_Helper\Index\KeyMapper;
use WP_Media_Helper\Index\ScanBudget;
use WP_Media_Helper\Index\ScanResult;
use WP_Media_Helper\Index\WpdbIndexStore;

class IndexScannerTest extends TestCase {

	private string $base;
	private string $root;
	private string $outside;
	private FakeWpdb $db;
	private WpdbIndexStore $store;
	private int $run = 0;

	protected function setUp(): void {
		$this->base = realpath( sys_get_temp_dir() ) . '/wpmh_scan_' . uniqid();
		$this->root = $this->base . '/photos';
		$this->outside = $this->base . '/outside';
		mkdir( $this->root . '/2026/10/02', 0755, true );
		mkdir( $this->root . '/2026/10/03', 0755, true );
		mkdir( $this->outside, 0755 );

		touch( $this->root . '/2026/10/02/20261002_121549.jpg' );
		touch( $this->root . '/2026/10/02/trace.gpx', ( new DateTimeImmutable( '2026-09-01 08:00:00', new DateTimeZone( 'Europe/Paris' ) ) )->getTimestamp() );
		touch( $this->root . '/2026/10/03/20261003-subtitles.vtt' );
		touch( $this->root . '/.hidden.jpg' );
		touch( $this->root . '/Thumbs.db' );
		touch( $this->outside . '/20261002_000000.jpg' );
		symlink( $this->outside, $this->root . '/linked' );
		symlink( $this->outside . '/20261002_000000.jpg', $this->root . '/2026/10/02/link.jpg' );
		$this->age();

		$this->db = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->db;
		$this->store = new WpdbIndexStore();
	}

	protected function tearDown(): void {
		$this->remove( $this->base );
	}

	private function remove( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
		} elseif ( is_dir( $path ) ) {
			foreach ( scandir( $path ) as $entry ) {
				if ( '.' !== $entry && '..' !== $entry ) {
					$this->remove( $path . '/' . $entry );
				}
			}
			rmdir( $path );
		}
	}

	/**
	 * Gives every directory an old modification time, as after a while on a real disk.
	 */
	private function age( ?string $directory = null ): void {
		$directory ??= $this->root;
		foreach ( scandir( $directory ) as $entry ) {
			$path = $directory . '/' . $entry;
			if ( '.' !== $entry && '..' !== $entry && ! is_link( $path ) && is_dir( $path ) ) {
				$this->age( $path );
			}
		}
		touch( $directory, time() - 1000 );
	}

	/**
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	private function source( array $extra = [] ): array {
		return array_merge( [ 'id' => 's', 'root' => $this->root, 'filter_pattern' => '', 'mtime_fallback' => true ], $extra );
	}

	private function scanner(): IndexScanner {
		return new IndexScanner( $this->store, new KeyMapper( $this->base ), new DateTimeZone( 'Europe/Paris' ) );
	}

	/**
	 * @param array<string, mixed> $extra
	 */
	private function pass( bool $full = false, array $extra = [] ): ScanResult {
		return $this->scanner()->scan( $this->source( $extra ), ++$this->run, new ScanBudget( 30 ), $full );
	}

	/**
	 * @return string[]
	 */
	private function day( string $day, string $source = 's' ): array {
		return array_column( $this->store->filesForDay( [ $source ], $day ), 'name' );
	}

	public function test_a_first_scan_indexes_files_with_their_dates_and_ignores_links_and_dot_files(): void {
		$result = $this->pass();

		$this->assertTrue( $result->complete );
		$this->assertSame( 5, $result->directoriesRead );
		$this->assertSame( 3, $result->filesAdded );
		$this->assertSame( [ '20261002_121549.jpg' ], $this->day( '2026-10-02' ) );
		$this->assertSame( [ '20261003-subtitles.vtt' ], $this->day( '2026-10-03' ) );
		$this->assertSame( [ 'trace.gpx' ], $this->day( '2026-09-01' ), 'A file without a date in its name falls back to its modification time.' );
		$this->assertSame( 3, $this->store->countFiles( 's' ) );
		$this->assertSame( 5, $this->store->countDirectories( 's' ) );

		$row = $this->store->filesForDay( [ 's' ], '2026-10-02' )[0];
		$this->assertSame( 'photos/2026/10/02/20261002_121549.jpg', $row['path'], 'Keys are relative to the uploads directory.' );
		$this->assertSame( '2026-10-02 12:15:49', $row['effective_date'] );
		$this->assertSame( 'name', $row['date_source'] );
		$this->assertSame( 'image', $row['kind'] );
		$this->assertSame( '2026-10-03 12:00:00', $this->store->filesForDay( [ 's' ], '2026-10-03' )[0]['effective_date'], 'A date alone is placed at the median time.' );
	}

	public function test_an_unchanged_tree_is_not_read_again(): void {
		$this->pass();

		$second = $this->pass();

		$this->assertTrue( $second->complete );
		$this->assertSame( 0, $second->directoriesRead );
		$this->assertSame( 5, $second->directoriesUnchanged );
		$this->assertSame( 0, $second->filesAdded );
	}

	public function test_a_file_added_in_a_sub_directory_is_found_by_reading_only_that_directory(): void {
		$this->pass();
		touch( $this->root . '/2026/10/03/20261003_090000.jpg' );

		$result = $this->pass();

		$this->assertSame( 1, $result->directoriesRead );
		$this->assertSame( 1, $result->filesAdded );
		$this->assertSame( [ '20261003_090000.jpg', '20261003-subtitles.vtt' ], $this->day( '2026-10-03' ), 'Ordered by date: 09:00 then the median time.' );
	}

	public function test_a_file_added_in_the_same_second_as_the_scan_is_not_missed(): void {
		mkdir( $this->root . '/new' );
		touch( $this->root . '/new/20261010_080000.jpg' );
		$this->pass();

		touch( $this->root . '/new/20261010_090000.jpg' );
		$this->pass();

		$this->assertSame( [ '20261010_080000.jpg', '20261010_090000.jpg' ], $this->day( '2026-10-10' ) );
	}

	public function test_removed_files_and_directories_are_marked_missing_and_can_come_back(): void {
		$this->pass();

		unlink( $this->root . '/2026/10/02/20261002_121549.jpg' );
		$result = $this->pass();
		$this->assertSame( 1, $result->filesMissing );
		$this->assertSame( [], $this->day( '2026-10-02' ) );

		touch( $this->root . '/2026/10/02/20261002_121549.jpg' );
		$this->pass();
		$this->assertSame( [ '20261002_121549.jpg' ], $this->day( '2026-10-02' ) );

		$this->remove( $this->root . '/2026/10/03' );
		$this->pass();
		$this->assertSame( [], $this->day( '2026-10-03' ) );
		$this->assertSame( 4, $this->store->countDirectories( 's' ) );
	}

	public function test_a_file_edited_in_place_is_only_found_by_a_full_pass(): void {
		$this->pass();
		$path = $this->root . '/2026/10/02/trace.gpx';
		file_put_contents( $path, 'changed' );
		touch( $this->root . '/2026/10/02', time() - 1000 );

		$incremental = $this->pass();
		$this->assertSame( 0, $incremental->filesUpdated, 'A directory time does not change when a file is edited.' );

		$full = $this->pass( true );
		$this->assertSame( 1, $full->filesUpdated );
		$this->assertSame( 7, $this->store->filesForDay( [ 's' ], date( 'Y-m-d', filemtime( $path ) ) )[0]['size'] ?? 0 );
	}

	public function test_a_pass_split_in_resumable_runs_gives_the_same_index(): void {
		$expected = $this->pass();
		$one = [ $this->day( '2026-10-02' ), $this->day( '2026-10-03' ), $this->day( '2026-09-01' ) ];
		$this->store->deleteSource( 's' );

		$run = ++$this->run;
		$runs = 0;
		do {
			$result = $this->scanner()->scan( $this->source(), $run, new ScanBudget( 30, 2 ) );
			++$runs;
		} while ( ! $result->complete && $runs < 20 );

		$this->assertTrue( $expected->complete );
		$this->assertGreaterThanOrEqual( 3, $runs );
		$this->assertSame( $one, [ $this->day( '2026-10-02' ), $this->day( '2026-10-03' ), $this->day( '2026-09-01' ) ] );
		$this->assertSame( 5, $this->store->countDirectories( 's' ) );
	}

	public function test_a_full_pass_recomputes_the_dates_when_the_settings_change(): void {
		$this->pass();
		$this->assertSame( [ 'trace.gpx' ], $this->day( '2026-09-01' ) );

		$this->pass( true, [ 'mtime_fallback' => false ] );

		$this->assertSame( [], $this->day( '2026-09-01' ) );
		$this->assertSame( [ '20261002_121549.jpg' ], $this->day( '2026-10-02' ) );
	}

	public function test_hints_scan_only_the_given_directory(): void {
		$result = $this->scanner()->scan( $this->source(), ++$this->run, new ScanBudget( 30 ), false, [ $this->root . '/2026/10/03' ] );

		$this->assertTrue( $result->complete );
		$this->assertSame( 1, $result->directoriesRead );
		$this->assertSame( [ '20261003-subtitles.vtt' ], $this->day( '2026-10-03' ) );
		$this->assertSame( [], $this->day( '2026-10-02' ) );

		// A full pass later reads the directories the hint did not.
		$all = $this->pass();
		$this->assertTrue( $all->complete );
		$this->assertSame( [ '20261002_121549.jpg' ], $this->day( '2026-10-02' ) );
	}

	public function test_hints_outside_the_root_or_with_dot_dot_or_links_are_refused(): void {
		foreach ( [ $this->outside, $this->root . '/../outside', $this->root . '/linked', $this->base, '/etc' ] as $hint ) {
			$result = $this->scanner()->scan( $this->source(), ++$this->run, new ScanBudget( 30 ), false, [ $hint ] );

			$this->assertSame( 0, $result->directoriesRead, $hint );
		}
		$this->assertSame( [], $this->day( '2026-10-02' ) );
	}

	public function test_the_columns_of_the_user_survive_full_passes(): void {
		$this->pass();
		$this->db->query( "UPDATE wp_media_helper_files SET hidden = 1, hidden_by = 4 WHERE name = '20261002_121549.jpg'" );

		$this->pass( true );
		$this->pass( true, [ 'mtime_fallback' => false ] );

		$this->assertSame( [], $this->day( '2026-10-02' ), 'Still hidden.' );
		$this->assertSame( '1', (string) $this->db->get_var( "SELECT hidden FROM wp_media_helper_files WHERE name = '20261002_121549.jpg'" ) );
	}

	public function test_sources_are_indexed_separately(): void {
		$this->pass();
		$this->scanner()->scan( $this->source( [ 'id' => 'other' ] ), ++$this->run, new ScanBudget( 30 ) );

		$this->assertSame( [ '20261002_121549.jpg' ], $this->day( '2026-10-02', 'other' ) );
		$this->assertSame( 2, count( $this->store->filesForDay( [ 's', 'other' ], '2026-10-02' ) ) );
	}

	public function test_a_missing_root_does_not_fail(): void {
		$result = $this->scanner()->scan( $this->source( [ 'root' => $this->base . '/nowhere' ] ), ++$this->run, new ScanBudget( 30 ) );

		$this->assertTrue( $result->complete );
		$this->assertSame( 0, $result->directoriesRead );
	}

	public function test_unusual_file_names_are_stored_as_they_are(): void {
		touch( $this->root . "/2026/10/02/l'été #1 100% 20261002.jpg" );
		$this->age();

		$this->pass();

		$this->assertContains( "l'été #1 100% 20261002.jpg", $this->day( '2026-10-02' ) );
	}

	public function test_keys_stay_valid_when_the_uploads_directory_moves(): void {
		$this->pass();
		$row = $this->store->filesForDay( [ 's' ], '2026-10-02' )[0];

		$moved = new KeyMapper( '/srv/new-site/uploads' );

		$this->assertSame( '/srv/new-site/uploads/' . $row['path'], $moved->absolute( $row['path'] ) );
		$this->assertSame( '/etc/hosts', $moved->absolute( '/etc/hosts' ) );
		$this->assertSame( 'photos/a.jpg', $moved->key( '/srv/new-site/uploads/photos/a.jpg' ) );
		$this->assertSame( '/mnt/media/a.jpg', $moved->key( '/mnt/media/a.jpg' ) );
	}

	public function test_excluded_trees_are_skipped_and_forgotten_when_an_exclusion_appears(): void {
		$this->pass();
		$this->assertSame( [ '20261002_121549.jpg' ], $this->day( '2026-10-02' ) );

		// A source before this one now owns 2026/10/02: a full pass drops what was indexed there.
		$this->pass( true, [ 'exclusions' => [ $this->root . '/2026/10/02' ] ] );
		$this->assertSame( [], $this->day( '2026-10-02' ) );
		$this->assertSame( [ '20261003-subtitles.vtt' ], $this->day( '2026-10-03' ) );
	}

	public function test_a_first_scan_never_enters_an_excluded_tree_even_when_asked_by_a_hint(): void {
		$exclusions = [ 'exclusions' => [ $this->root . '/2026/10/02' ] ];
		$this->pass( false, $exclusions );
		$this->assertSame( [], $this->day( '2026-10-02' ) );

		$this->scanner()->scan( $this->source( $exclusions ), 99, new ScanBudget( 30 ), true, [ $this->root . '/2026/10/02' ] );
		$this->assertSame( [], $this->day( '2026-10-02' ) );
	}

	public function test_a_source_entirely_owned_by_another_lists_nothing(): void {
		$this->pass( false, [ 'exclusions' => [ $this->root ] ] );

		$this->assertSame( 0, $this->store->countFiles( 's' ) );
	}

	/**
	 * @param array<string, int|null> $timestamps Local clock time as if UTC, by file name.
	 * @param string[]                $reads      Receives the names of the files that were opened.
	 */
	private function embeddedScanner( array $timestamps, array &$reads ): IndexScanner {
		return new IndexScanner(
			$this->store,
			new KeyMapper( $this->base ),
			new DateTimeZone( 'Europe/Paris' ),
			null,
			static function ( string $path ) use ( $timestamps, &$reads ): ?int {
				$reads[] = basename( $path );

				return $timestamps[ basename( $path ) ] ?? null;
			}
		);
	}

	public function test_the_capture_date_of_a_photo_without_a_date_in_its_name_places_it_and_is_read_once(): void {
		touch( $this->root . '/2026/10/02/cam.jpg' );
		touch( $this->root . '/2026/10/02/blank.jpeg' );
		touch( $this->root . '/2026/10/02/clip.mp4' );
		$this->age();
		$reads = [];
		$scanner = $this->embeddedScanner( [ 'cam.jpg' => strtotime( '2026-08-15 10:00:00 UTC' ), 'IMG_20260101.jpg' => 1 ], $reads );

		$scanner->scan( $this->source(), 1, new ScanBudget( 30 ) );

		$this->assertContains( 'cam.jpg', $this->day( '2026-08-15' ) );
		$row = $this->store->filesForDay( [ 's' ], '2026-08-15' )[0];
		$this->assertSame( 'embedded', $row['date_source'] );
		$this->assertSame( '2026-08-15 10:00:00', $row['effective_date'], 'The local clock time of the camera is the site time.' );
		$this->assertSame( 1, $row['embedded_state'] );
		$this->assertContains( 'trace.gpx', $this->day( '2026-09-01' ), 'Not an image: the modification time applies and nothing is opened.' );
		$this->assertNotContains( 'clip.mp4', $reads );
		$this->assertNotContains( '20261002_121549.jpg', $reads, 'A date in the name wins: the file is not opened.' );

		// A photo with no capture date falls back to the modification time and is not asked again.
		$blank = array_filter( $this->store->filesForDay( [ 's' ], gmdate( 'Y-m-d' ) ), static fn ( array $r ): bool => 'blank.jpeg' === $r['name'] );
		$this->assertSame( 2, ( array_values( $blank )[0] ?? [ 'embedded_state' => 2 ] )['embedded_state'] );

		$reads = [];
		$scanner->scan( $this->source(), 2, new ScanBudget( 30 ), true );
		$this->assertSame( [], $reads, 'A full pass recomputes dates from what is stored, without opening the files.' );
		$this->assertContains( 'cam.jpg', $this->day( '2026-08-15' ) );
	}

	public function test_a_photo_is_read_again_when_its_size_or_time_changed(): void {
		touch( $this->root . '/2026/10/02/cam.jpg' );
		$this->age();
		$reads = [];
		$scanner = $this->embeddedScanner( [ 'cam.jpg' => strtotime( '2026-08-15 10:00:00 UTC' ) ], $reads );
		$scanner->scan( $this->source(), 1, new ScanBudget( 30 ) );
		$this->assertSame( [ 'cam.jpg' ], $reads );

		touch( $this->root . '/2026/10/02/cam.jpg', time() - 500 );
		$this->age();
		$scanner->scan( $this->source(), 2, new ScanBudget( 30 ), true );

		$this->assertSame( [ 'cam.jpg', 'cam.jpg' ], $reads );
	}

	public function test_a_photo_with_a_date_in_its_name_is_never_opened(): void {
		touch( $this->root . '/2026/10/02/holiday-20260720.jpg' );
		$this->age();
		$reads = [];
		$scanner = $this->embeddedScanner( [ 'holiday-20260720.jpg' => strtotime( '2026-08-15 10:00:00 UTC' ) ], $reads );
		// The generic recogniser finds the date in the name: the file is never opened.
		$scanner->scan( $this->source(), 1, new ScanBudget( 30 ) );

		$this->assertSame( [], $reads );
		$this->assertContains( 'holiday-20260720.jpg', $this->day( '2026-07-20' ) );
	}
}
