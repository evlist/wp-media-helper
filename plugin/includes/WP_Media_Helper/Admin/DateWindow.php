<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

/**
 * The date of the panel: a day (`2026-10-08`, the usual case), or a window of dates and times written as two ends separated by a slash, each end a
 * day with an optional time (`2026-10-08/2026-10-12`, `2026-10-08T09:00/2026-10-08T17:00`, `2026-10-08T22:00/2026-10-09T06:00`).
 * An end without a time is the whole day: the start is 00:00:00, the end 23:59:59. An end with a time is that minute: the start at second 0, the
 * end at second 59. Two ends in the wrong order are swapped. The same string is stored with the post and sent to the server.
 */
final class DateWindow {

	private const END = '(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?';

	/**
	 * @return array{start:string, end:string, start_day:string, end_day:string, is_window:bool, normalized:string}|null `start` and `end` are written Y-m-d H:i:s.
	 */
	public static function parse( string $value ): ?array {
		$value = trim( $value );
		if ( 1 !== preg_match( '#^' . self::END . '(?:/' . self::END . ')?$#', $value, $found ) ) {
			return null;
		}

		$first = self::end( $found, 1 );
		if ( null === $first ) {
			return null;
		}
		if ( ! isset( $found[6] ) || '' === $found[6] ) {
			// A single end must be a day: a time alone says nothing about where it ends.
			if ( null !== $first['time'] ) {
				return null;
			}

			return [
				'start'      => $first['day'] . ' 00:00:00',
				'end'        => $first['day'] . ' 23:59:59',
				'start_day'  => $first['day'],
				'end_day'    => $first['day'],
				'is_window'  => false,
				'normalized' => $first['day'],
			];
		}

		$second = self::end( $found, 6 );
		if ( null === $second ) {
			return null;
		}

		$start = $first['day'] . ' ' . ( null === $first['time'] ? '00:00:00' : $first['time'] . ':00' );
		$end   = $second['day'] . ' ' . ( null === $second['time'] ? '23:59:59' : $second['time'] . ':59' );
		if ( $end < $start ) {
			[ $first, $second ] = [ $second, $first ];
			$start = $first['day'] . ' ' . ( null === $first['time'] ? '00:00:00' : $first['time'] . ':00' );
			$end   = $second['day'] . ' ' . ( null === $second['time'] ? '23:59:59' : $second['time'] . ':59' );
		}

		$write = static fn ( array $part ): string => $part['day'] . ( null === $part['time'] ? '' : 'T' . $part['time'] );
		// Both ends the same day without a time: just that day.
		$whole = $first['day'] === $second['day'] && null === $first['time'] && null === $second['time'];

		return [
			'start'      => $start,
			'end'        => $end,
			'start_day'  => $first['day'],
			'end_day'    => $second['day'],
			'is_window'  => ! $whole,
			'normalized' => $whole ? $first['day'] : $write( $first ) . '/' . $write( $second ),
		];
	}

	/**
	 * The value as it is kept, or `$fallback` (a day) when it is not a date or a window.
	 */
	public static function normalize( string $value, string $fallback ): string {
		$parsed = self::parse( $value );

		return null === $parsed ? $fallback : $parsed['normalized'];
	}

	/**
	 * @param array<int, string> $found
	 * @return array{day:string, time:string|null}|null
	 */
	private static function end( array $found, int $at ): ?array {
		$year  = (int) $found[ $at ];
		$month = (int) $found[ $at + 1 ];
		$day   = (int) $found[ $at + 2 ];
		if ( ! checkdate( $month, $day, $year ) ) {
			return null;
		}

		$time = null;
		if ( isset( $found[ $at + 3 ] ) && '' !== $found[ $at + 3 ] ) {
			$hours   = (int) $found[ $at + 3 ];
			$minutes = (int) $found[ $at + 4 ];
			if ( $hours > 23 || $minutes > 59 ) {
				return null;
			}
			$time = sprintf( '%02d:%02d', $hours, $minutes );
		}

		return [ 'day' => sprintf( '%04d-%02d-%02d', $year, $month, $day ), 'time' => $time ];
	}
}
