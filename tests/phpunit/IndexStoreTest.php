<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Index\Schema;
use WP_Media_Helper\Index\WpdbIndexStore;

class IndexStoreTest extends TestCase {

	private FakeWpdb $db;
	private WpdbIndexStore $store;

	protected function setUp(): void {
		$this->db = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->db;
		$this->store = new WpdbIndexStore();
	}

	private function file( string $key, string $day = null, array $extra = [] ): array {
		return array_merge( [
			'key' => $key, 'name' => basename( $key ), 'ext' => 'jpg', 'kind' => 'image', 'size' => 10, 'mtime' => 1000,
			'name_date' => null === $day ? null : $day . ' 12:00:00', 'name_date_precision' => null === $day ? null : 'day',
			'effective_date' => null === $day ? null : $day . ' 12:00:00', 'effective_day' => $day,
			'date_source' => null === $day ? 'none' : 'name',
		], $extra );
	}

	/**
	 * @param array<string, array<string, mixed>> $rows
	 * @return array<int, array<string, mixed>>
	 */
	private function sortedByName( array $rows ): array {
		ksort( $rows );

		return array_values( $rows );
	}

	public function test_the_sqlite_tables_used_by_the_tests_have_the_columns_of_the_mysql_schema(): void {
		$statements = Schema::statements( 'wp_media_helper_dirs', 'wp_media_helper_files', '' );

		foreach ( [ 'directories' => 'wp_media_helper_dirs', 'files' => 'wp_media_helper_files' ] as $name => $table ) {
			preg_match_all( '/^  (?!PRIMARY|UNIQUE|KEY)(\w+) /m', $statements[ $name ], $found );
			$this->assertSame( $found[1], $this->db->columns( $table ), $name );
		}
	}

	public function test_directories_are_saved_found_and_revived(): void {
		$root = $this->store->saveDirectory( 's', 'photos', 0, 100 );
		$child = $this->store->saveDirectory( 's', 'photos/2026', $root, 100 );

		$this->assertSame( $root, $this->store->saveDirectory( 's', 'photos', 0, 200 ) );
		$this->assertSame( 'photos/2026', $this->store->findDirectory( 's', 'photos/2026' )['path'] );
		$this->assertNull( $this->store->findDirectory( 's', 'photos/2027' ) );
		$this->assertNull( $this->store->findDirectory( 'other', 'photos/2026' ) );
		$this->assertSame( [ $child ], array_column( $this->store->childDirectories( $root ), 'id' ) );

		$this->store->markDirectoryMissing( $child, 300 );
		$this->assertSame( 300, $this->store->findDirectory( 's', 'photos/2026' )['missing_since'] );

		$this->store->saveDirectory( 's', 'photos/2026', $root, 400 );
		$this->assertNull( $this->store->findDirectory( 's', 'photos/2026' )['missing_since'] );
	}

	public function test_the_queue_of_a_run_returns_directories_not_yet_read(): void {
		$a = $this->store->saveDirectory( 's', 'a', 0, 1 );
		$b = $this->store->saveDirectory( 's', 'b', 0, 1 );
		$this->store->queueDirectory( $a, 7 );
		$this->store->queueDirectory( $b, 7 );

		$this->assertSame( 2, $this->store->countPending( 's', 7 ) );
		$this->assertSame( [ $a ], array_column( $this->store->nextQueued( 's', 7, 1 ), 'id' ) );

		$this->store->markScanned( $a, 7, 555, 10 );
		$this->assertSame( [ $b ], array_column( $this->store->nextQueued( 's', 7, 10 ), 'id' ) );
		$this->assertSame( 1, $this->store->countPending( 's', 7 ) );
		$this->assertSame( 555, $this->store->findDirectory( 's', 'a' )['mtime'] );
		$this->assertSame( 0, $this->store->countPending( 's', 8 ) );

		// A directory read by another run is read again by this one.
		$this->store->queueDirectory( $a, 8 );
		$this->assertSame( 1, $this->store->countPending( 's', 8 ) );
	}

	public function test_files_are_inserted_listed_by_day_and_counted(): void {
		$dir = $this->store->saveDirectory( 's', 'photos', 0, 1 );
		$this->store->insertFiles( 's', $dir, [
			$this->file( 'photos/b.jpg', '2026-10-02' ),
			$this->file( 'photos/a.jpg', '2026-10-02' ),
			$this->file( 'photos/c.jpg', '2026-10-03' ),
			$this->file( 'photos/d.txt', null ),
		], 5 );

		$this->assertSame( [ 'a.jpg', 'b.jpg' ], array_column( $this->store->filesForDay( [ 's' ], '2026-10-02' ), 'name' ) );
		$this->assertSame( [], $this->store->filesForDay( [ 'other' ], '2026-10-02' ) );
		$this->assertSame( [], $this->store->filesForDay( [], '2026-10-02' ) );
		$this->assertSame( 4, $this->store->countFiles( 's' ) );
		$this->assertSame( 1, $this->store->countDirectories( 's' ) );
		$this->assertSame( [ 'a.jpg', 'b.jpg', 'c.jpg', 'd.txt' ], array_values( array_map( static fn ( array $row ): string => $row['name'], $this->sortedByName( $this->store->filesInDirectory( $dir ) ) ) ) );
		$row = $this->store->filesInDirectory( $dir )['d.txt'];
		$this->assertNull( $row['effective_day'] );
		$this->assertSame( 'none', $row['date_source'] );
		$this->assertSame( 10, $row['size'] );
	}

	public function test_a_large_insert_is_split_into_chunks(): void {
		$dir = $this->store->saveDirectory( 's', 'photos', 0, 1 );
		$files = [];
		for ( $i = 0; $i < 250; $i++ ) {
			$files[] = $this->file( sprintf( 'photos/f%03d.jpg', $i ), '2026-10-02' );
		}
		$before = $this->db->queries;

		$this->store->insertFiles( 's', $dir, $files, 5 );

		$this->assertSame( 3, $this->db->queries - $before );
		$this->assertCount( 250, $this->store->filesForDay( [ 's' ], '2026-10-02' ) );
	}

	public function test_values_with_quotes_and_percent_signs_are_stored_as_is(): void {
		$dir = $this->store->saveDirectory( 's', "photos/it's 100%", 0, 1 );
		$this->store->insertFiles( 's', $dir, [ $this->file( "photos/it's 100%/l'été #1 %20.jpg", '2026-10-02' ) ], 5 );

		$rows = $this->store->filesForDay( [ 's' ], '2026-10-02' );

		$this->assertSame( "l'été #1 %20.jpg", $rows[0]['name'] );
		$this->assertSame( "photos/it's 100%/l'été #1 %20.jpg", $rows[0]['path'] );
	}

	public function test_missing_files_are_not_listed_and_can_be_revived_by_an_update(): void {
		$dir = $this->store->saveDirectory( 's', 'photos', 0, 1 );
		$this->store->insertFiles( 's', $dir, [ $this->file( 'photos/a.jpg', '2026-10-02' ) ], 5 );
		$id = $this->store->filesInDirectory( $dir )['a.jpg']['id'];

		$this->store->markFilesMissing( [ $id ], 50 );
		$this->assertSame( [], $this->store->filesForDay( [ 's' ], '2026-10-02' ) );
		$this->assertSame( 0, $this->store->countFiles( 's' ) );
		$this->assertSame( 50, $this->store->filesInDirectory( $dir )['a.jpg']['missing_since'] );

		$this->store->updateFile( $id, [ 'missing_since' => null, 'size' => 99 ], 60 );
		$this->assertCount( 1, $this->store->filesForDay( [ 's' ], '2026-10-02' ) );
		$this->assertSame( 99, $this->store->filesInDirectory( $dir )['a.jpg']['size'] );
	}

	public function test_a_scan_never_overwrites_the_columns_of_the_user(): void {
		$dir = $this->store->saveDirectory( 's', 'photos', 0, 1 );
		$this->store->insertFiles( 's', $dir, [ $this->file( 'photos/a.jpg', '2026-10-02' ) ], 5 );
		$id = $this->store->filesInDirectory( $dir )['a.jpg']['id'];
		$this->db->query( "UPDATE wp_media_helper_files SET hidden = 1, hidden_by = 3, date_override = '2020-01-01 00:00:00' WHERE id = {$id}" );

		$this->store->updateFile( $id, [ 'hidden' => 0, 'hidden_by' => 9, 'date_override' => null, 'size' => 5 ], 70 );

		$row = $this->store->filesInDirectory( $dir )['a.jpg'];
		$this->assertSame( 1, $row['hidden'] );
		$this->assertSame( 3, $row['hidden_by'] );
		$this->assertSame( '2020-01-01 00:00:00', $row['date_override'] );
		$this->assertSame( 5, $row['size'] );
		$this->assertSame( [], $this->store->filesForDay( [ 's' ], '2026-10-02' ), 'A hidden file is not listed.' );
	}

	public function test_missing_rows_are_purged_after_the_retention_period_and_sources_can_be_deleted(): void {
		$dir = $this->store->saveDirectory( 's', 'photos', 0, 1 );
		$other = $this->store->saveDirectory( 'o', 'x', 0, 1 );
		$this->store->insertFiles( 's', $dir, [ $this->file( 'photos/a.jpg', '2026-10-02' ), $this->file( 'photos/b.jpg', '2026-10-02' ) ], 5 );
		$this->store->insertFiles( 'o', $other, [ $this->file( 'x/c.jpg', '2026-10-02' ) ], 5 );
		$files = $this->store->filesInDirectory( $dir );
		$this->store->markFilesMissing( [ $files['a.jpg']['id'] ], 100 );
		$this->store->markDirectoryMissing( $dir, 100 );

		$this->assertSame( 0, $this->store->purgeMissing( 100 ), 'Not older than the limit.' );
		$this->assertSame( 2, $this->store->purgeMissing( 101 ) );
		$this->assertSame( [ 'b.jpg' ], array_keys( $this->store->filesInDirectory( $dir ) ) );

		$this->store->deleteSource( 'o' );
		$this->assertSame( 0, $this->store->countFiles( 'o' ) );
		$this->assertSame( 0, $this->store->countDirectories( 'o' ) );
	}

	public function test_the_children_of_a_directory_are_queued_in_one_operation(): void {
		$root = $this->store->saveDirectory( 's', 'photos', 0, 1 );
		$a = $this->store->saveDirectory( 's', 'photos/a', $root, 1 );
		$b = $this->store->saveDirectory( 's', 'photos/b', $root, 1 );
		$gone = $this->store->saveDirectory( 's', 'photos/gone', $root, 1 );
		$deep = $this->store->saveDirectory( 's', 'photos/a/deep', $a, 1 );
		$this->store->markDirectoryMissing( $gone, 5 );
		$before = $this->db->queries;

		$this->store->queueChildDirectories( $root, 9 );

		$this->assertSame( 1, $this->db->queries - $before );
		$this->assertSame( [ $a, $b ], array_column( $this->store->nextQueued( 's', 9, 10 ), 'id' ), 'Only the direct, non-missing children.' );
		$this->assertNotContains( $deep, array_column( $this->store->nextQueued( 's', 9, 10 ), 'id' ) );
	}

	public function test_a_concurrent_insert_of_the_same_directory_returns_the_existing_row(): void {
		$first = $this->store->saveDirectory( 's', 'photos', 0, 1 );

		// A second request that did not see the row yet inserts the same key.
		$second = ( new class() extends WpdbIndexStore {
			public function findDirectory( string $sourceId, string $key ): ?array {
				static $calls = 0;

				return 1 === ++$calls ? null : parent::findDirectory( $sourceId, $key );
			}
		} )->saveDirectory( 's', 'photos', 0, 2 );

		$this->assertSame( $first, $second );
		$this->assertSame( 1, $this->store->countDirectories( 's' ) );
	}

	public function test_a_concurrent_insert_of_some_files_does_not_lose_the_others(): void {
		$dir = $this->store->saveDirectory( 's', 'photos', 0, 1 );
		$this->store->insertFiles( 's', $dir, [ $this->file( 'photos/a.jpg', '2026-10-02' ) ], 5 );

		// The second request read the directory before a.jpg was inserted and also found b.jpg.
		$this->store->insertFiles( 's', $dir, [ $this->file( 'photos/a.jpg', '2026-10-02' ), $this->file( 'photos/b.jpg', '2026-10-02' ) ], 6 );

		$this->assertSame( [ 'a.jpg', 'b.jpg' ], $this->day( '2026-10-02' ) );
	}

	/**
	 * @return string[]
	 */
	private function day( string $day ): array {
		return array_column( $this->store->filesForDay( [ 's' ], $day ), 'name' );
	}
}
