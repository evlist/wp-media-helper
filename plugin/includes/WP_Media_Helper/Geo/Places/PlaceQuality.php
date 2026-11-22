<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Geo\Places;

/**
 * What the places found hold, level by level (slice 052): how many have a locality, a district, a settlement, a county, a state, a country, which numbered
 * administrative levels are there, and what the object found is. It tells whether the level of detail that a grouping needs is there.
 */
final class PlaceQuality {

	/** The levels under the names of PlaceAnswer::levels(), from the smallest to the largest. */
	public const LEVELS = [ 'name', 'locality', 'district', 'settlement', 'county', 'state', 'country' ];

	/**
	 * @param iterable<array<string, mixed>> $answers The whole answers of the service (decoded).
	 * @return array{total:int, levels:array<string, int>, admin:array<string, int>, kinds:array<string, int>, nowhere_named:int}
	 *         `levels`: for each level the number of answers that have it; `admin`: how many answers have each numbered administrative level (level2, level4...);
	 *         `kinds`: how many answers by kind of object found (`place hamlet`, `natural peak`...); `nowhere_named`: answers with no settlement and no locality.
	 */
	public static function tally( iterable $answers ): array {
		$total  = 0;
		$levels = array_fill_keys( self::LEVELS, 0 );
		$admin  = [];
		$kinds  = [];
		$bare   = 0;
		foreach ( $answers as $answer ) {
			++$total;
			$found = PlaceAnswer::levels( $answer );
			foreach ( self::LEVELS as $level ) {
				if ( '' !== (string) $found[ $level ] ) {
					++$levels[ $level ];
				}
			}
			foreach ( array_keys( (array) $found['admin'] ) as $level ) {
				$admin[ (string) $level ] = ( $admin[ (string) $level ] ?? 0 ) + 1;
			}
			if ( '' === $found['settlement'] && '' === $found['locality'] ) {
				++$bare;
			}
			$kind           = '' !== $found['kind'] ? $found['kind'] : '?';
			$kinds[ $kind ] = ( $kinds[ $kind ] ?? 0 ) + 1;
		}
		ksort( $admin, SORT_NATURAL );
		arsort( $kinds );

		return [ 'total' => $total, 'levels' => $levels, 'admin' => $admin, 'kinds' => $kinds, 'nowhere_named' => $bare ];
	}
}
