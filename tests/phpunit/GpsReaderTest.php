<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Geo\GpsReader;

class GpsReaderTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/wpmh_gps_' . uniqid();
		mkdir( $this->dir );
	}

	protected function tearDown(): void {
		array_map( 'unlink', glob( $this->dir . '/*' ) ?: [] );
		rmdir( $this->dir );
	}

	private function gpx( string $body, string $name = 't.gpx' ): string {
		$path = $this->dir . '/' . $name;
		file_put_contents( $path, '<?xml version="1.0" encoding="UTF-8"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1">' . $body . '</gpx>' );

		return $path;
	}

	public function test_the_exif_position_is_read_with_its_signs(): void {
		$position = GpsReader::exifPosition( [ 'GPSLatitude' => [ '43/1', '30/1', '0/1' ], 'GPSLatitudeRef' => 'N', 'GPSLongitude' => [ '3/1', '6/1', '36/1' ], 'GPSLongitudeRef' => 'E' ] );

		$this->assertSame( [ 'lat' => 43.5, 'lon' => 3.11 ], $position );

		$south = GpsReader::exifPosition( [ 'GPSLatitude' => [ '33/1', '51/1', '0/1' ], 'GPSLatitudeRef' => 'S', 'GPSLongitude' => [ '151/1', '12/1', '0/1' ], 'GPSLongitudeRef' => 'W' ] );
		$this->assertSame( [ 'lat' => -33.85, 'lon' => -151.2 ], $south );
	}

	public function test_rationals_with_big_denominators_and_plain_numbers_are_read(): void {
		$position = GpsReader::exifPosition( [ 'GPSLatitude' => [ '43/1', '3050/100', 0 ], 'GPSLatitudeRef' => 'N', 'GPSLongitude' => [ 3, '9/2', '0/1' ], 'GPSLongitudeRef' => 'E' ] );

		$this->assertEqualsWithDelta( 43.508333, $position['lat'], 0.000001 );
		$this->assertEqualsWithDelta( 3.075, $position['lon'], 0.000001 );
	}

	public function test_no_position_is_refused_when_missing_wrong_or_out_of_range(): void {
		$this->assertNull( GpsReader::exifPosition( [] ), 'No GPS.' );
		$this->assertNull( GpsReader::exifPosition( [ 'GPSLatitude' => [ '0/1', '0/1', '0/1' ], 'GPSLatitudeRef' => 'N', 'GPSLongitude' => [ '0/1', '0/1', '0/1' ], 'GPSLongitudeRef' => 'E' ] ), '0, 0 is a camera without a fix.' );
		$this->assertNull( GpsReader::exifPosition( [ 'GPSLatitude' => [ '95/1', '0/1', '0/1' ], 'GPSLatitudeRef' => 'N', 'GPSLongitude' => [ '3/1', '0/1', '0/1' ], 'GPSLongitudeRef' => 'E' ] ), 'Latitude out of range.' );
		$this->assertNull( GpsReader::exifPosition( [ 'GPSLatitude' => [ '43/0', '0/1', '0/1' ], 'GPSLatitudeRef' => 'N', 'GPSLongitude' => [ '3/1', '0/1', '0/1' ], 'GPSLongitudeRef' => 'E' ] ), 'Division by zero.' );
		$this->assertNull( GpsReader::exifPosition( [ 'GPSLatitude' => [ '43/1', '0/1', '0/1' ], 'GPSLatitudeRef' => 'X', 'GPSLongitude' => [ '3/1', '0/1', '0/1' ], 'GPSLongitudeRef' => 'E' ] ), 'A reference that is not N, S, E or W.' );
		$this->assertNull( GpsReader::exifPosition( [ 'GPSLatitude' => 'nope', 'GPSLatitudeRef' => 'N', 'GPSLongitude' => [ '3/1' ], 'GPSLongitudeRef' => 'E' ] ) );
	}

	public function test_a_track_gives_its_earliest_and_latest_points_whatever_the_order_of_the_file(): void {
		$path = $this->gpx(
			'<metadata><time>2026-10-20T00:00:00Z</time></metadata><trk><trkseg>'
			. '<trkpt lat="43.5" lon="3.1"><time>2026-10-08T10:05:00Z</time></trkpt>'
			. '<trkpt lat="43.4" lon="3.0"><time>2026-10-08T09:00:00Z</time></trkpt>'
			. '<trkpt lat="43.6" lon="3.2"><time>2026-10-08T12:30:00+02:00</time></trkpt>'
			. '<trkpt lat="43.55" lon="3.15"><time>2026-10-08T11:00:00Z</time></trkpt>'
			. '</trkseg></trk><wpt lat="10" lon="10"><time>2020-01-01T00:00:00Z</time></wpt>'
		);

		$track = GpsReader::gpxEndpoints( $path );

		$this->assertSame( [ 'lat' => 43.4, 'lon' => 3.0, 'at' => '2026-10-08 09:00:00' ], $track['start'] );
		// 12:30 at +02:00 is 10:30 UTC: the point of 11:00 UTC is later.
		$this->assertSame( [ 'lat' => 43.55, 'lon' => 3.15, 'at' => '2026-10-08 11:00:00' ], $track['end'] );
	}

	public function test_a_track_without_times_gives_its_first_and_last_points_of_the_file(): void {
		$path = $this->gpx( '<trk><trkseg><trkpt lat="43.1" lon="3.1"/><trkpt lat="43.2" lon="3.2"/><trkpt lat="43.3" lon="3.3"/></trkseg></trk>' );

		$this->assertSame(
			[ 'start' => [ 'lat' => 43.1, 'lon' => 3.1, 'at' => null ], 'end' => [ 'lat' => 43.3, 'lon' => 3.3, 'at' => null ] ],
			GpsReader::gpxEndpoints( $path )
		);
	}

	public function test_waypoints_are_used_only_without_track_points(): void {
		$path = $this->gpx( '<wpt lat="45" lon="5"><time>2026-01-01T00:00:00Z</time></wpt><wpt lat="46" lon="6"><time>2026-01-02T00:00:00Z</time></wpt>' );

		$track = GpsReader::gpxEndpoints( $path );

		$this->assertSame( 45.0, $track['start']['lat'] );
		$this->assertSame( 46.0, $track['end']['lat'] );
	}

	public function test_a_prefixed_namespace_and_a_single_point_are_read(): void {
		$path = $this->dir . '/p.gpx';
		file_put_contents( $path, '<g:gpx xmlns:g="http://www.topografix.com/GPX/1/1"><g:trk><g:trkseg><g:trkpt lat="43.5" lon="3.5"><g:time>2026-10-08T09:00:00Z</g:time></g:trkpt></g:trkseg></g:trk></g:gpx>' );

		$track = GpsReader::gpxEndpoints( $path );

		$this->assertSame( $track['start'], $track['end'] );
		$this->assertSame( '2026-10-08 09:00:00', $track['start']['at'] );
	}

	public function test_points_that_are_not_positions_are_ignored(): void {
		$path = $this->gpx( '<trk><trkseg><trkpt lat="x" lon="3"/><trkpt lat="0" lon="0"/><trkpt lat="91" lon="3"/><trkpt lat="43" lon="3"/></trkseg></trk>' );

		$track = GpsReader::gpxEndpoints( $path );

		$this->assertSame( 43.0, $track['start']['lat'] );
		$this->assertSame( 43.0, $track['end']['lat'] );
	}

	public function test_what_is_not_a_readable_gpx_gives_nothing(): void {
		$this->assertNull( GpsReader::gpxEndpoints( $this->dir . '/missing.gpx' ) );
		$this->assertNull( GpsReader::gpxEndpoints( $this->gpx( '' ) ), 'No point.' );
		file_put_contents( $this->dir . '/bad.gpx', '<gpx><trk><trkpt lat="43"' );
		$this->assertNull( GpsReader::gpxEndpoints( $this->dir . '/bad.gpx' ), 'Broken XML.' );
	}

	public function test_a_file_that_declares_entities_is_not_read(): void {
		$path = $this->dir . '/x.gpx';
		file_put_contents( $path, '<?xml version="1.0"?><!DOCTYPE gpx [<!ENTITY a "aaaa">]><gpx><trk><trkseg><trkpt lat="43" lon="3"/></trkseg></trk></gpx>' );

		$this->assertNull( GpsReader::gpxEndpoints( $path ) );
	}
}
