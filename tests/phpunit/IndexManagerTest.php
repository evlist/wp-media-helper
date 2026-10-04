<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Index\DayIndex;
use WP_Media_Helper\Index\IndexManager;
use WP_Media_Helper\Index\IndexScanner;
use WP_Media_Helper\Index\KeyMapper;
use WP_Media_Helper\Index\ScanBudget;
use WP_Media_Helper\Index\ScanState;
use WP_Media_Helper\Index\WpdbIndexStore;

class IndexManagerTest extends TestCase {

	private string $base;
	private string $root;
	private WpdbIndexStore $store;
	private IndexManager $manager;
	private DayIndex $index;
	/** @var array<string, mixed> */
	private array $option = [];
	private int $now = 2000000000;
	private int $scheduled = 0;

	protected function setUp(): void {
		$this->base = realpath( sys_get_temp_dir() ) . '/wpmh_mgr_' . uniqid();
		$this->root = $this->base . '/photos';
		foreach ( [ '2026/10/01', '2026/10/02', '2026/10/03', '2026/09/30' ] as $directory ) {
			mkdir( $this->root . '/' . $directory, 0755, true );
		}
		touch( $this->root . '/2026/10/02/20261002_121549.jpg' );
		touch( $this->root . '/2026/10/03/20261003_080000.jpg' );
		touch( $this->root . '/2026/10/01/20261001_080000.jpg' );
		touch( $this->root . '/2026/09/30/20260930_080000.jpg' );
		$this->age( $this->root );

		$GLOBALS['wpdb'] = new FakeWpdb();
		$this->store = new WpdbIndexStore();
		$keys = new KeyMapper( $this->base );
		$scanner = new IndexScanner( $this->store, $keys, new DateTimeZone( 'Europe/Paris' ) );
		$state = new ScanState(
			fn() => $this->option,
			function ( array $value ): void {
				$this->option = $value;
			}
		);
		$this->manager = new IndexManager( $this->store, $scanner, $state, function ( int $delay ): void {
			++$this->scheduled;
		}, fn(): int => $this->now );
		$this->index = new DayIndex( $this->store, $this->manager, $keys );
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

	private function age( string $directory ): void {
		foreach ( scandir( $directory ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry && is_dir( $directory . '/' . $entry ) ) {
				$this->age( $directory . '/' . $entry );
			}
		}
		touch( $directory, time() - 1000 );
	}

	/**
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	private function source( array $extra = [] ): array {
		return array_merge( [ 'id' => 's', 'root' => $this->root, 'path_pattern' => '{date:Y}/{date:m}/{date:d}', 'filter_pattern' => '' ], $extra );
	}

	/**
	 * @return string[] Names of the files listed for a day.
	 */
	private function names( array $source, string $day, bool $force = false ): array {
		$result = $this->index->forDay( $source, new DateTimeImmutable( $day ), $force );

		return array_map( 'basename', $result['files'] );
	}

	private function finishBackground( array $source ): void {
		for ( $i = 0; $i < 10 && $this->manager->runBackground( [ $source ], new ScanBudget( 30 ) ); $i++ ) {
			continue;
		}
	}

	public function test_the_first_request_shows_the_hinted_directories_and_asks_for_a_full_pass(): void {
		$source = $this->source();

		$result = $this->index->forDay( $source, new DateTimeImmutable( '2026-10-02' ) );

		$this->assertSame( [ '20261002_121549.jpg' ], array_map( 'basename', $result['files'] ) );
		$this->assertTrue( $result['refresh_required'] );
		$this->assertSame( DayIndex::REASON_INCOMPLETE, $result['reason'] );
		$this->assertGreaterThan( 0, $this->scheduled );
		$this->assertFalse( $this->manager->isIndexed( $source ) );
	}

	public function test_the_background_pass_completes_the_index_and_the_panel_becomes_fresh(): void {
		$source = $this->source();
		$this->index->forDay( $source, new DateTimeImmutable( '2026-10-02' ) );

		$this->finishBackground( $source );

		$status = $this->manager->status( $source );
		$this->assertTrue( $status['indexed'] );
		$this->assertFalse( $status['in_progress'] );
		$this->assertSame( 4, $status['files'] );
		$result = $this->index->forDay( $source, new DateTimeImmutable( '2026-10-02' ) );
		$this->assertFalse( $result['refresh_required'] );
		$this->assertNull( $result['reason'] );
		$this->assertSame( [ '20261003_080000.jpg' ], $this->names( $source, '2026-10-03' ), 'Found by the pass, not only by hints.' );
	}

	public function test_a_budget_splits_the_first_pass_over_several_background_runs(): void {
		$source = $this->source();
		$this->index->forDay( $source, new DateTimeImmutable( '2026-10-02' ) );

		$runs = 0;
		do {
			$more = $this->manager->runBackground( [ $source ], new ScanBudget( 30, 3 ) );
			++$runs;
		} while ( $more && $runs < 20 );

		$this->assertGreaterThanOrEqual( 2, $runs );
		$this->assertTrue( $this->manager->status( $source )['indexed'] );
		$this->assertSame( 4, $this->manager->status( $source )['files'] );
	}

	public function test_hints_cover_the_neighbouring_days(): void {
		$source = $this->source();
		$this->index->forDay( $source, new DateTimeImmutable( '2026-10-02' ) );

		// The directories of the days around the requested one were read too.
		$this->assertSame( [ '20261001_080000.jpg' ], array_map( static fn ( array $row ): string => $row['name'], $this->store->filesForDay( [ 's' ], '2026-10-01' ) ) );
		$this->assertSame( [ '20261003_080000.jpg' ], array_map( static fn ( array $row ): string => $row['name'], $this->store->filesForDay( [ 's' ], '2026-10-03' ) ) );
		$this->assertSame( [], $this->store->filesForDay( [ 's' ], '2026-09-30' ), 'Two days away: left to the background pass.' );
	}

	public function test_a_source_without_path_pattern_is_scanned_from_its_root_until_indexed(): void {
		$source = $this->source( [ 'path_pattern' => '' ] );

		$first = $this->names( $source, '2026-10-02' );
		$this->assertSame( [ '20261002_121549.jpg' ], $first );

		$this->finishBackground( $source );
		$before = $GLOBALS['wpdb']->queries;
		$this->names( $source, '2026-10-02' );
		$this->assertLessThan( 12, $GLOBALS['wpdb']->queries - $before, 'No scan at request time once indexed.' );
	}

	public function test_a_new_file_in_a_hinted_directory_appears_at_once_and_others_after_a_pass(): void {
		$source = $this->source();
		$this->index->forDay( $source, new DateTimeImmutable( '2026-10-02' ) );
		$this->finishBackground( $source );

		touch( $this->root . '/2026/10/02/20261002_130000.jpg' );
		$this->assertSame( [ '20261002_121549.jpg', '20261002_130000.jpg' ], $this->names( $source, '2026-10-02' ) );

		touch( $this->root . '/2026/09/30/20260930_090000.jpg' );
		$this->manager->requestPass( $source );
		$this->finishBackground( $source );
		$this->assertSame( [ '20260930_080000.jpg', '20260930_090000.jpg' ], $this->names( $source, '2026-09-30' ) );
	}

	public function test_refresh_requests_an_incremental_pass(): void {
		$source = $this->source();
		$this->index->forDay( $source, new DateTimeImmutable( '2026-10-02' ) );
		$this->finishBackground( $source );
		$this->scheduled = 0;

		$result = $this->index->forDay( $source, new DateTimeImmutable( '2026-10-02' ), true );

		$this->assertSame( DayIndex::REASON_FORCED, $result['reason'] );
		$this->assertGreaterThan( 0, $this->scheduled );
		$this->assertSame( IndexManager::PASS_INCREMENTAL, $this->manager->status( $source )['pending'] );
	}

	public function test_passes_come_back_after_the_intervals(): void {
		$source = $this->source();
		$this->index->forDay( $source, new DateTimeImmutable( '2026-10-02' ) );
		$this->finishBackground( $source );
		$this->scheduled = 0;

		$this->manager->requestDuePasses( [ $source ] );
		$this->assertSame( 0, $this->scheduled, 'Nothing is due right after a pass.' );

		$this->now += IndexManager::INCREMENTAL_INTERVAL + 1;
		$this->manager->requestDuePasses( [ $source ] );
		$this->assertSame( 1, $this->scheduled );

		touch( $this->root . '/2026/10/02/20261002_140000.jpg' );
		$this->now += 1;
		$more = $this->manager->runBackground( [ $source ], new ScanBudget( 30 ) );
		$this->assertFalse( $more );
		$this->assertFalse( $this->manager->status( $source )['full'] );
		$this->assertSame( 5, $this->manager->status( $source )['files'] );

		$this->now += IndexManager::FULL_INTERVAL + 1;
		$this->manager->runBackground( [ $source ], new ScanBudget( 30 ) );
		$this->assertTrue( $this->manager->status( $source )['full'] );
		$this->assertSame( $this->now, $this->manager->status( $source )['last_full_at'] );
	}

	public function test_changing_the_name_pattern_or_the_fallback_recomputes_the_dates(): void {
		$source = $this->source( [ 'path_pattern' => '' ] );
		touch( $this->root . '/2026/10/02/notes.txt', ( new DateTimeImmutable( '2026-08-15 10:00:00', new DateTimeZone( 'Europe/Paris' ) ) )->getTimestamp() );
		$this->age( $this->root );
		$this->index->forDay( $source, new DateTimeImmutable( '2026-08-15' ) );
		$this->finishBackground( $source );
		$this->assertSame( [ 'notes.txt' ], $this->names( $source, '2026-08-15' ) );

		$changed = $this->source( [ 'path_pattern' => '', 'mtime_fallback' => false ] );
		$this->index->forDay( $changed, new DateTimeImmutable( '2026-08-15' ) );
		$this->finishBackground( $changed );

		$this->assertSame( [], $this->names( $changed, '2026-08-15' ) );
	}

	public function test_changing_the_root_resets_the_index_of_the_source(): void {
		$source = $this->source();
		$this->index->forDay( $source, new DateTimeImmutable( '2026-10-02' ) );
		$this->finishBackground( $source );
		$this->assertSame( 4, $this->manager->status( $source )['files'] );

		mkdir( $this->base . '/other', 0755 );
		touch( $this->base . '/other/20261002_100000.jpg' );
		$moved = $this->source( [ 'root' => $this->base . '/other', 'path_pattern' => '' ] );
		$this->manager->prepare( $moved );

		$this->assertSame( 0, $this->manager->status( $moved )['files'] );
		$this->assertFalse( $this->manager->isIndexed( $moved ) );
	}

	public function test_sources_that_are_no_longer_configured_are_forgotten(): void {
		$source = $this->source();
		$this->index->forDay( $source, new DateTimeImmutable( '2026-10-02' ) );
		$this->finishBackground( $source );

		$this->manager->forgetUnknownSources( [ 'another' ] );

		$this->assertSame( 0, $this->store->countFiles( 's' ) );
		$this->assertArrayNotHasKey( 's', $this->option );
	}

	public function test_a_path_pattern_cannot_point_outside_the_root(): void {
		mkdir( $this->base . '/secret', 0755 );
		touch( $this->base . '/secret/20261002_000000.jpg' );
		$source = $this->source( [ 'path_pattern' => '../secret' ] );

		$this->assertSame( [], $this->names( $source, '2026-10-02' ) );
	}

	public function test_an_invalid_path_pattern_does_not_fail(): void {
		$source = $this->source( [ 'path_pattern' => '{date:Y}/{nope}' ] );

		$this->assertSame( [], $this->names( $source, '2026-10-02' ) );
	}
}
