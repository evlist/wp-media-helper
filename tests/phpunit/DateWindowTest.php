<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Admin\DateWindow;

class DateWindowTest extends TestCase {

	public function test_a_day_is_a_whole_day(): void {
		$this->assertSame(
			[ 'start' => '2026-10-08 00:00:00', 'end' => '2026-10-08 23:59:59', 'start_day' => '2026-10-08', 'end_day' => '2026-10-08', 'is_window' => false, 'normalized' => '2026-10-08' ],
			DateWindow::parse( '2026-10-08' )
		);
	}

	public function test_dates_alone_cover_whole_days(): void {
		$window = DateWindow::parse( '2026-10-08/2026-10-12' );

		$this->assertSame( [ '2026-10-08 00:00:00', '2026-10-12 23:59:59', true, '2026-10-08/2026-10-12' ], [ $window['start'], $window['end'], $window['is_window'], $window['normalized'] ] );
	}

	public function test_times_make_a_part_of_a_day_or_a_span_across_days(): void {
		$part = DateWindow::parse( '2026-10-08T09:00/2026-10-08T17:30' );
		$night = DateWindow::parse( '2026-10-08T22:00/2026-10-09T06:00' );
		$mixed = DateWindow::parse( '2026-10-08/2026-10-09T06:00' );

		$this->assertSame( [ '2026-10-08 09:00:00', '2026-10-08 17:30:59' ], [ $part['start'], $part['end'] ] );
		$this->assertSame( [ '2026-10-08 22:00:00', '2026-10-09 06:00:59', true ], [ $night['start'], $night['end'], $night['is_window'] ] );
		$this->assertSame( [ '2026-10-08 00:00:00', '2026-10-09 06:00:59' ], [ $mixed['start'], $mixed['end'] ] );
	}

	public function test_ends_in_the_wrong_order_are_swapped(): void {
		$window = DateWindow::parse( '2026-10-12/2026-10-08' );

		$this->assertSame( '2026-10-08/2026-10-12', $window['normalized'] );
		$this->assertSame( '2026-10-08 00:00:00', $window['start'] );
	}

	public function test_the_same_day_twice_without_times_is_just_that_day(): void {
		$this->assertSame( '2026-10-08', DateWindow::normalize( '2026-10-08/2026-10-08', '2000-01-01' ) );
		$this->assertSame( '2026-10-08T09:00/2026-10-08T09:00', DateWindow::normalize( '2026-10-08T09:00/2026-10-08T09:00', '2000-01-01' ) );
	}

	public function test_what_is_not_a_date_or_a_window_gives_the_fallback(): void {
		foreach ( [ '', 'today', '2026-13-01', '2026-02-30', '2026-10-08T25:00/2026-10-09', '2026-10-08T09:60/2026-10-09', '2026-10-08T09:00', '2026-10-08/', '/2026-10-08', '2026-10-08/2026-10-09/2026-10-10', '08/10/2026' ] as $value ) {
			$this->assertSame( '2000-01-01', DateWindow::normalize( $value, '2000-01-01' ), $value );
		}
	}
}
