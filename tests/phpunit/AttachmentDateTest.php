<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\MediaSource\AttachmentDate;
use WP_Media_Helper\MediaSource\AttachmentMetadata;

class AttachmentDateTest extends TestCase {

	private DateTimeZone $paris;
	private int $now;

	protected function setUp(): void {
		$this->paris = new DateTimeZone( 'Europe/Paris' );
		$this->now = ( new DateTimeImmutable( '2026-10-03 09:00:00', $this->paris ) )->getTimestamp();
	}

	public function test_capture_date_is_reread_as_site_local_time(): void {
		// Core stored the local wall-clock time 12:15:49 as if it were UTC.
		$date = AttachmentDate::resolve( 1790943349, null, 'photo.jpg', null, $this->paris, $this->now );

		$this->assertSame( AttachmentDate::SOURCE_CAPTURE, $date['source'] );
		$this->assertSame( '2026-10-02 12:15:49', $date['local'] );
		$this->assertSame( '2026-10-02 10:15:49', $date['gmt'] );
	}

	public function test_media_creation_date_is_a_real_instant(): void {
		$date = AttachmentDate::resolve( null, 1790936149, 'clip.mp4', null, $this->paris, $this->now );

		$this->assertSame( AttachmentDate::SOURCE_MEDIA, $date['source'] );
		$this->assertSame( '2026-10-02 12:15:49', $date['local'] );
		$this->assertSame( '2026-10-02 10:15:49', $date['gmt'] );
	}

	public function test_filename_date_is_used_when_there_is_no_metadata(): void {
		$date = AttachmentDate::resolve( 0, null, '20261002_121549.jpg', 1000000000, $this->paris, $this->now );

		$this->assertSame( AttachmentDate::SOURCE_FILENAME, $date['source'] );
		$this->assertSame( '2026-10-02 12:15:49', $date['local'] );
		$this->assertSame( '2026-10-02 10:15:49', $date['gmt'] );
	}

	public function test_modification_time_then_now_are_the_last_resorts(): void {
		$mtime = ( new DateTimeImmutable( '2026-09-01 08:00:00', $this->paris ) )->getTimestamp();

		$fromMtime = AttachmentDate::resolve( null, null, 'subtitles.vtt', $mtime, $this->paris, $this->now );
		$this->assertSame( AttachmentDate::SOURCE_MTIME, $fromMtime['source'] );
		$this->assertSame( '2026-09-01 08:00:00', $fromMtime['local'] );

		$fromNow = AttachmentDate::resolve( null, null, 'subtitles.vtt', null, $this->paris, $this->now );
		$this->assertSame( AttachmentDate::SOURCE_NOW, $fromNow['source'] );
		$this->assertSame( '2026-10-03 09:00:00', $fromNow['local'] );
	}

	public function test_implausible_values_are_ignored(): void {
		$date = AttachmentDate::resolve( 86400, $this->now + 10 * 366 * 86400, 'photo.jpg', 5, $this->paris, $this->now );

		$this->assertSame( AttachmentDate::SOURCE_NOW, $date['source'] );
	}

	public function test_the_file_name_wins_over_the_metadata(): void {
		// A video assembled later by a tool that writes unrelated metadata, renamed by the user.
		$date = AttachmentDate::resolve( 1790943349, 1790936149, '20200101_000000.mp4', null, $this->paris, $this->now );

		$this->assertSame( AttachmentDate::SOURCE_FILENAME, $date['source'] );
		$this->assertSame( '2020-01-01 00:00:00', $date['local'] );
	}

	public function test_the_modification_time_fallback_can_be_turned_off_for_a_source(): void {
		$mtime = ( new DateTimeImmutable( '2026-09-01 08:00:00', $this->paris ) )->getTimestamp();

		$date = AttachmentDate::resolve( null, null, 'trace.gpx', $mtime, $this->paris, $this->now, [ 'mtime_fallback' => false ] );

		$this->assertSame( AttachmentDate::SOURCE_NOW, $date['source'] );
	}

	public function test_the_pattern_of_the_source_is_tried_before_the_generic_one(): void {
		$date = AttachmentDate::resolve( null, null, 'trip-02.10.2026 at 18-30.jpg', null, $this->paris, $this->now, [ 'filter_pattern' => '{date:d.m.Y} at {date:H-i}' ] );

		$this->assertSame( '2026-10-02 18:30:00', $date['local'] );
	}

	public function test_filename_patterns(): void {
		$cases = [
			'IMG_20261002_121549.jpg'        => '2026-10-02 12:15:49',
			'20261002121549.jpg'             => '2026-10-02 12:15:49',
			'2026-10-02 12.15.49 trace.gpx'  => '2026-10-02 12:15:49',
			'2026-10-02T12-15-49.jpg'        => '2026-10-02 12:15:49',
			'sous-titres-2026-10-02.vtt'     => '2026-10-02 12:00:00',
			'20261002.srt'                   => '2026-10-02 12:00:00',
			'20261002_12.jpg'                => '2026-10-02 12:00:00',
		];
		foreach ( $cases as $name => $expected ) {
			$date = AttachmentDate::fromFilename( $name, $this->paris, $this->now );
			$this->assertNotNull( $date, $name );
			$this->assertSame( $expected, $date->format( 'Y-m-d H:i:s' ), $name );
		}
	}

	public function test_filename_without_a_real_date_gives_nothing(): void {
		foreach ( [ 'DSC_1234.jpg', '12345678.jpg', '20261340_000000.jpg', '20260230.jpg', 'a1234567890123.jpg', '30000101.jpg', 'photo.jpg' ] as $name ) {
			$this->assertNull( AttachmentDate::fromFilename( $name, $this->paris, $this->now ), $name );
		}
	}

	public function test_metadata_builders_hold_no_path_and_no_sizes(): void {
		$image = AttachmentMetadata::forImage( 4000, 2252, 'photos/a.jpg', 2692302, [ 'camera' => 'x' ] );

		$this->assertSame( [ 'width', 'height', 'file', 'filesize', 'sizes', 'image_meta' ], array_keys( $image ) );
		$this->assertSame( [], $image['sizes'] );
		$this->assertSame( 'photos/a.jpg', $image['file'] );
		$this->assertSame( [ 'length' => 3, 'filesize' => 10 ], AttachmentMetadata::forMedia( [ 'length' => 3 ], 10 ) );
		$this->assertSame( [ 'filesize' => 7 ], AttachmentMetadata::forFile( 7 ) );
	}

	public function test_a_date_without_time_keeps_its_day_in_gmt_within_twelve_hours_of_utc(): void {
		foreach ( [ 'Europe/Paris', 'UTC', 'America/Los_Angeles', 'Asia/Kolkata', 'Asia/Tokyo' ] as $name ) {
			$date = AttachmentDate::resolve( null, null, 'sous-titres-2026-10-02.vtt', null, new DateTimeZone( $name ), $this->now );

			$this->assertSame( '2026-10-02 12:00:00', $date['local'], $name );
			$this->assertSame( '2026-10-02', substr( $date['gmt'], 0, 10 ), $name );
		}
	}

	public function test_beyond_twelve_hours_east_of_utc_only_the_gmt_day_differs(): void {
		// New Zealand daylight time is UTC+13: noon local is the previous evening in GMT.
		$date = AttachmentDate::resolve( null, null, 'sous-titres-2026-10-02.vtt', null, new DateTimeZone( 'Pacific/Auckland' ), $this->now );

		$this->assertSame( '2026-10-02 12:00:00', $date['local'] );
		$this->assertSame( '2026-10-01 23:00:00', $date['gmt'] );
	}

	public function test_a_name_with_a_day_only_takes_the_time_of_the_capture_on_the_same_day(): void {
		$capture = strtotime( '2026-10-02 18:45:10 UTC' ); // Camera clock time, read as site time.

		$same = AttachmentDate::resolve( $capture, null, 'trip-20261002.jpg', null, $this->paris, $this->now );
		$this->assertSame( '2026-10-02 18:45:10', $same['local'] );
		$this->assertSame( 'filename', $same['source'] );

		$other = AttachmentDate::resolve( $capture, null, 'trip-20260920.jpg', null, $this->paris, $this->now );
		$this->assertSame( '2026-09-20 12:00:00', $other['local'] );

		$withTime = AttachmentDate::resolve( $capture, null, 'trip-20261002_080000.jpg', null, $this->paris, $this->now );
		$this->assertSame( '2026-10-02 08:00:00', $withTime['local'] );
	}
}
