<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Geo\Places;

/**
 * The place of a position, from the cache only (never a request): what the contract of slice 052 gives as `place`.
 */
final class PlaceResolver {

	public const ATTRIBUTION = '© OpenStreetMap contributors';

	/**
	 * @param array<string, mixed> $row A row of the cache.
	 * @return array<string, mixed>
	 */
	public static function describe( array $row ): array {
		$answer = json_decode( (string) ( $row['response'] ?? '' ), true );
		$answer = is_array( $answer ) ? $answer : [];

		return [
			'state'        => PlaceStore::STATE_FOUND === (int) $row['state'] ? 'found' : 'nothing',
			'cell'         => [ 'lat' => (float) $row['lat'], 'lon' => (float) $row['lon'] ],
			'language'     => (string) ( $row['lang'] ?? '' ),
			'osm_type'     => $row['osm_type'] ?? null,
			'osm_id'       => $row['osm_id'] ?? null,
			'display_name' => $row['display_name'] ?? null,
			// The levels under names that mean the same in every country (see PlaceAnswer::levels()): name, locality, district, settlement, county,
			// state, country, and admin (the numbered administrative levels).
			'levels'       => PlaceAnswer::levels( $answer ),
			'answer'       => $answer,
			'service'      => (string) ( $row['service'] ?? '' ),
			'fetched_at'   => (int) ( $row['fetched_at'] ?? 0 ),
			// The data is OpenStreetMap's (ODbL): where it is shown, this must be shown with it.
			'attribution'  => self::ATTRIBUTION,
		];
	}

	/**
	 * @return array<string, mixed>|null Null when the cell was not looked for, or the attempt failed.
	 */
	public static function forPosition( float $lat, float $lon, string $language, ?PlaceStore $store = null ): ?array {
		$row = ( $store ?? new PlaceStore() )->find( PlaceStore::key( $lat, $lon, $language ) );
		if ( null === $row || ! in_array( (int) $row['state'], [ PlaceStore::STATE_FOUND, PlaceStore::STATE_NOTHING ], true ) ) {
			return null;
		}

		return self::describe( $row );
	}
}
