<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\MediaSource\FileDates;
use WP_Media_Helper\MediaSource\NamePattern;

class NamePatternTest extends TestCase {

	private DateTimeZone $paris;
	private int $now;

	protected function setUp(): void {
		$this->paris = new DateTimeZone( 'Europe/Paris' );
		$this->now = ( new DateTimeImmutable( '2026-10-03 09:00:00', $this->paris ) )->getTimestamp();
	}

	private function read( string $pattern, string $name ): ?string {
		$compiled = NamePattern::compile( $pattern );
		$this->assertNotNull( $compiled, $pattern );
		$found = $compiled->match( $name, $this->paris, $this->now );

		return null === $found ? null : $found['date']->format( 'Y-m-d H:i:s' ) . ' ' . $found['precision'];
	}

	public function test_a_date_pattern_reads_a_date_and_gives_it_the_median_time(): void {
		$this->assertSame( '2026-10-02 12:00:00 day', $this->read( '{date:Ymd}', '20261002-morning.png' ) );
		$this->assertSame( '2026-10-02 12:00:00 day', $this->read( '{date:Y-m-d}', 'trace 2026-10-02.gpx' ) );
	}

	public function test_a_pattern_with_a_time_reads_it(): void {
		$this->assertSame( '2026-10-02 12:15:49 second', $this->read( '{date:Ymd}_{date:His}', 'IMG_20261002_121549.jpg' ) );
		$this->assertSame( '2026-10-02 18:30:00 second', $this->read( '{date:d.m.Y} at {date:H-i}', 'trip-02.10.2026 at 18-30.jpg' ) );
	}

	public function test_literals_are_matched_as_text(): void {
		$this->assertNull( $this->read( 'PXL_{date:Ymd}', 'IMG_20261002.jpg' ) );
		$this->assertSame( '2026-10-02 12:00:00 day', $this->read( 'PXL_{date:Ymd}', 'PXL_20261002_121549123.jpg' ) );
		$this->assertSame( '2026-10-02 12:00:00 day', $this->read( 'a.b(c){date:Ymd}', 'a.b(c)20261002.jpg' ) );
	}

	public function test_a_run_of_digits_does_not_continue_a_date_field(): void {
		$this->assertNull( $this->read( '{date:Ymd}', '120261002.jpg' ) );
		$this->assertNull( $this->read( '{date:Ymd}', '202610021.jpg' ) );
		$this->assertNull( $this->read( '{date:Ymd}_{date:His}', '20261002_1215499.jpg' ) );
	}

	public function test_impossible_dates_and_times_are_not_read(): void {
		$this->assertNull( $this->read( '{date:Ymd}', '20261340.jpg' ) );
		$this->assertNull( $this->read( '{date:Ymd}', '20260230.jpg' ) );
		$this->assertNull( $this->read( '{date:Ymd}', '18000101.jpg' ) );
		$this->assertNull( $this->read( '{date:Ymd}', '30000101.jpg' ) );
		$this->assertNull( $this->read( '{date:Ymd}_{date:His}', '20261002_251549.jpg' ) );
	}

	public function test_invalid_patterns_are_refused(): void {
		foreach ( [ '', '   ', 'no placeholder', '{date:}', '{date:Ym}', '{date:Ymd}{date:Y}', '{date:YmdH}', '{date:Ymds}', '{date:y-m-d}', '{date:Ymd', 'a{b}{date:Ymd}', str_repeat( 'x', 250 ) . '{date:Ymd}' ] as $pattern ) {
			$this->assertNull( NamePattern::compile( $pattern ), $pattern );
		}
	}

	public function test_the_generic_recogniser_finds_common_forms(): void {
		$cases = [
			'IMG_20261002_121549.jpg'       => '2026-10-02 12:15:49',
			'20261002121549.jpg'            => '2026-10-02 12:15:49',
			'2026-10-02 12.15.49 trace.gpx' => '2026-10-02 12:15:49',
			'2026-10-02T12-15-49.jpg'       => '2026-10-02 12:15:49',
			'sous-titres-2026-10-02.vtt'    => '2026-10-02 12:00:00',
			'IMG-20261002-WA0001.jpg'       => '2026-10-02 12:00:00',
		];
		foreach ( $cases as $name => $expected ) {
			$found = NamePattern::generic()->match( $name, $this->paris, $this->now );
			$this->assertNotNull( $found, $name );
			$this->assertSame( $expected, $found['date']->format( 'Y-m-d H:i:s' ), $name );
		}
		foreach ( [ 'DSC_1234.jpg', '12345678.jpg', 'photo.jpg', 'a1234567890123.jpg' ] as $name ) {
			$this->assertNull( NamePattern::generic()->match( $name, $this->paris, $this->now ), $name );
		}
	}

	public function test_file_dates_use_the_configured_pattern_then_the_generic_one_then_the_modification_time(): void {
		$source = [ 'filter_pattern' => '{date:d.m.Y}' ];
		$patterns = FileDates::patterns( $source );
		$mtime = ( new DateTimeImmutable( '2026-09-01 08:00:00', $this->paris ) )->getTimestamp();

		$configured = FileDates::effective( 'trip 02.10.2026.jpg', $mtime, $patterns, true, $this->paris, $this->now );
		$generic = FileDates::effective( '20261003.jpg', $mtime, $patterns, true, $this->paris, $this->now );
		$fallback = FileDates::effective( 'notes.txt', $mtime, $patterns, true, $this->paris, $this->now );
		$none = FileDates::effective( 'notes.txt', $mtime, $patterns, false, $this->paris, $this->now );

		$this->assertSame( [ '2026-10-02', FileDates::SOURCE_NAME, 'day' ], [ $configured['day'], $configured['source'], $configured['precision'] ] );
		$this->assertSame( [ '2026-10-03', FileDates::SOURCE_NAME ], [ $generic['day'], $generic['source'] ] );
		$this->assertSame( [ '2026-09-01', FileDates::SOURCE_MTIME, '2026-09-01 08:00:00' ], [ $fallback['day'], $fallback['source'], $fallback['local'] ] );
		$this->assertSame( [ null, FileDates::SOURCE_NONE ], [ $none['day'], $none['source'] ] );
	}

	public function test_file_dates_config_hash_changes_with_the_pattern_and_the_fallback(): void {
		$base = FileDates::configHash( [] );

		$this->assertSame( $base, FileDates::configHash( [ 'mtime_fallback' => true ] ) );
		$this->assertNotSame( $base, FileDates::configHash( [ 'mtime_fallback' => false ] ) );
		$this->assertNotSame( $base, FileDates::configHash( [ 'filter_pattern' => '{date:Ymd}' ] ) );
	}
}
