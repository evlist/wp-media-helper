<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Geo\Places;

/**
 * What the places found hold, level by level (slice 052): how many have a hamlet, a village, a town, a city, a county, a country... and what the
 * object found is. It tells whether the level of detail that a grouping needs is there.
 */
final class PlaceQuality {

	/** The levels of an address as Nominatim gives them, from the smallest to the largest. */
	public const LEVELS = [ 'house_number', 'road', 'isolated_dwelling', 'hamlet', 'neighbourhood', 'suburb', 'village', 'town', 'city', 'municipality', 'county', 'state_district', 'state', 'region', 'country' ];

	/**
	 * @param iterable<array<string, mixed>> $answers The whole answers of the service (decoded).
	 * @return array{total:int, levels:array<string, int>, types:array<string, int>, nowhere_named:int}
	 *         `levels`: for each level the number of answers that have it; `types`: how many answers by `addresstype` of the object found;
	 *         `nowhere_named`: answers with none of hamlet, village, town, city, municipality.
	 */
	public static function tally( iterable $answers ): array {
		$total  = 0;
		$levels = array_fill_keys( self::LEVELS, 0 );
		$types  = [];
		$bare   = 0;
		foreach ( $answers as $answer ) {
			++$total;
			$address = isset( $answer['address'] ) && is_array( $answer['address'] ) ? $answer['address'] : [];
			foreach ( self::LEVELS as $level ) {
				if ( isset( $address[ $level ] ) && '' !== (string) $address[ $level ] ) {
					++$levels[ $level ];
				}
			}
			if ( [] === array_filter( [ 'hamlet', 'village', 'town', 'city', 'municipality', 'isolated_dwelling' ], static fn ( string $level ): bool => ! empty( $address[ $level ] ) ) ) {
				++$bare;
			}
			$type           = (string) ( $answer['addresstype'] ?? $answer['type'] ?? '?' );
			$types[ $type ] = ( $types[ $type ] ?? 0 ) + 1;
		}
		arsort( $types );

		return [ 'total' => $total, 'levels' => $levels, 'types' => $types, 'nowhere_named' => $bare ];
	}
}
