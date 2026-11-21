<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Geo\GeoPass;
use WP_Media_Helper\Index\KeyMapper;
use WP_Media_Helper\Index\WpdbIndexStore;

class GeoPassTest extends TestCase {

	private FakeWpdb $db;
	private WpdbIndexStore $store;
	private KeyMapper $keys;
	/** @var string[] The paths the readers were asked about. */
	private array $asked = [];

	protected function setUp(): void {
		$this->db        = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->db;
		$this->store     = new WpdbIndexStore();
		$this->keys      = new KeyMapper( '/up' );
		$dir             = $this->store->saveDirectory( 's', 'photos', 0, 100 );
		$this->store->insertFiles( 's', $dir, [
			$this->file( 'photos/a.jpg', 'a.jpg', 'jpg', 'image', '2026-10-08 10:00:00' ),
			$this->file( 'photos/b.jpg', 'b.jpg', 'jpg', 'image', '2026-10-08 11:00:00' ),
			$this->file( 'photos/c.png', 'c.png', 'png', 'image', '2026-10-08 12:00:00' ),
			$this->file( 'photos/t.gpx', 't.gpx', 'gpx', 'gps', '2026-10-08 09:00:00' ),
			$this->file( 'photos/k.kml', 'k.kml', 'kml', 'gps', '2026-10-08 08:00:00' ),
		], 100 );
	}

	private function file( string $key, string $name, string $ext, string $kind, string $date ): array {
		return [ 'key' => $key, 'name' => $name, 'ext' => $ext, 'kind' => $kind, 'size' => 10, 'mtime' => 5, 'effective_date' => $date, 'effective_day' => substr( $date, 0, 10 ), 'date_source' => 'name' ];
	}

	private function pass(): GeoPass {
		return new GeoPass(
			$this->store,
			$this->keys,
			function ( string $path ): ?array {
				$this->asked[] = $path;

				return '/up/photos/a.jpg' === $path ? [ 'lat' => 43.5, 'lon' => 3.1 ] : null;
			},
			function ( string $path ): ?array {
				$this->asked[] = $path;

				return [ 'start' => [ 'lat' => 43.4, 'lon' => 3.0, 'at' => '2026-10-08 09:00:00' ], 'end' => [ 'lat' => 43.6, 'lon' => 3.2, 'at' => '2026-10-08 12:30:00' ] ];
			}
		);
	}

	private function row( string $name ): array {
		foreach ( $this->store->filesForDay( [ 's' ], '2026-10-08' ) as $row ) {
			if ( $name === $row['name'] ) {
				return $row;
			}
		}

		return [];
	}

	public function test_a_pass_reads_the_photos_and_the_tracks_and_records_the_state_of_each_file(): void {
		$report = $this->pass()->run( 5.0 );

		$this->assertSame( [ 'read' => 5, 'with_position' => 2, 'without' => 3, 'left' => false ], $report );
		$this->assertSame( [ 0 => 0, 1 => 2, 2 => 3 ], $this->store->geoCounts() );

		$photo = $this->row( 'a.jpg' );
		$this->assertSame( [ 1, 43.5, 3.1 ], [ $photo['geo_state'], $photo['gps_lat'], $photo['gps_lon'] ] );
		$this->assertSame( 2, $this->row( 'b.jpg' )['geo_state'], 'A photo without GPS is recorded as read.' );
		$this->assertSame( 2, $this->row( 'c.png' )['geo_state'], 'A type PHP cannot read is not opened.' );
		$this->assertSame( 2, $this->row( 'k.kml' )['geo_state'], 'Only GPX tracks are read.' );

		$track = $this->row( 't.gpx' );
		$this->assertSame( [ 1, 43.4, 3.0, 43.6, 3.2, '2026-10-08 09:00:00', '2026-10-08 12:30:00' ], [ $track['geo_state'], $track['gps_lat'], $track['gps_lon'], $track['gps_end_lat'], $track['gps_end_lon'], $track['track_start_at'], $track['track_end_at'] ] );
		$this->assertSame( [ '/up/photos/b.jpg', '/up/photos/a.jpg', '/up/photos/t.gpx' ], array_values( array_diff( $this->asked, [] ) ), 'Newest first, and only what can be read is opened.' );
	}

	public function test_what_was_read_is_not_read_again_until_it_is_reset(): void {
		$this->pass()->run( 5.0 );
		$this->asked = [];

		$again = $this->pass()->run( 5.0 );

		$this->assertSame( 0, $again['read'] );
		$this->assertSame( [], $this->asked );

		$this->assertSame( 5, $this->store->resetGeo() );
		$this->assertSame( [ 0 => 5, 1 => 0, 2 => 0 ], $this->store->geoCounts() );
		$this->assertSame( 5, $this->pass()->run( 5.0 )['read'] );
	}

	public function test_a_limit_stops_the_pass_and_says_that_files_remain(): void {
		$report = $this->pass()->run( 5.0, 2 );

		$this->assertSame( 2, $report['read'] );
		$this->assertTrue( $report['left'] );
		$this->assertSame( 3, $this->store->geoCounts()[0] );
	}

	public function test_a_file_that_changed_is_read_again_and_a_position_that_disappeared_is_forgotten(): void {
		$this->pass()->run( 5.0 );
		$row = $this->row( 'a.jpg' );

		// What the scan does when the size or the time of a file changed.
		$this->store->updateFile( (int) $row['id'], [ 'size' => 11, 'geo_state' => 0 ], 200 );
		$this->assertSame( 0, $this->row( 'a.jpg' )['geo_state'] );

		$reader = new GeoPass( $this->store, $this->keys, static fn (): ?array => null, static fn (): ?array => null );
		$reader->run( 5.0 );

		$after = $this->row( 'a.jpg' );
		$this->assertSame( 2, $after['geo_state'] );
		$this->assertNull( $after['gps_lat'] );
		$this->assertNull( $after['gps_lon'] );
	}

	public function test_files_that_are_gone_are_not_read(): void {
		$row = $this->row( 'a.jpg' );
		$this->store->markFilesMissing( [ (int) $row['id'] ], 300 );

		$this->pass()->run( 5.0 );

		$this->assertNotContains( '/up/photos/a.jpg', $this->asked );
	}
}
