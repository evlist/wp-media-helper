<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Geo\Places;

/**
 * Reads an answer of the geocoding service (slice 052). The plugin asks for `geocodejson`, whose fields are the same in every country
 * (`locality`, `district`, `city`, `county`, `state`, `country`, and `admin` with the numbered administrative levels), which the address keys of
 * `jsonv2` are not (`municipality` is an arrondissement in France, a commune in Italy, a district in Hungary). Answers of the older `jsonv2` format that
 * a cache may still hold are read too.
 */
final class PlaceAnswer {

	/**
	 * The object of a `geocodejson` answer (`features[0].properties.geocoding`), or null for any other answer.
	 *
	 * @param array<string, mixed> $answer
	 * @return array<string, mixed>|null
	 */
	public static function geocoding( array $answer ): ?array {
		$geocoding = $answer['features'][0]['properties']['geocoding'] ?? null;

		return is_array( $geocoding ) ? $geocoding : null;
	}

	/**
	 * Whether a decoded answer holds a place (and not "nothing there").
	 *
	 * @param array<string, mixed> $answer
	 */
	public static function hasPlace( array $answer ): bool {
		if ( isset( $answer['error'] ) ) {
			return false;
		}

		return null !== self::geocoding( $answer ) || isset( $answer['place_id'] ) || isset( $answer['osm_id'] ) || isset( $answer['address'] );
	}

	/**
	 * What the cache keeps beside the whole answer.
	 *
	 * @param array<string, mixed> $answer
	 * @return array{osm_type:?string, osm_id:?int, place_id:?int, display_name:?string}
	 */
	public static function summary( array $answer ): array {
		$source = self::geocoding( $answer ) ?? $answer;

		return [
			'osm_type'     => isset( $source['osm_type'] ) ? substr( (string) $source['osm_type'], 0, 10 ) : null,
			'osm_id'       => isset( $source['osm_id'] ) ? (int) $source['osm_id'] : null,
			'place_id'     => isset( $source['place_id'] ) ? (int) $source['place_id'] : null,
			'display_name' => isset( $source['label'] ) ? (string) $source['label'] : ( isset( $source['display_name'] ) ? (string) $source['display_name'] : null ),
		];
	}

	/**
	 * The levels of a place under stable names, whatever the country:
	 * `name` (what was found: a pass, a hamlet, a farm), `site` (the place name below the commune: see site()), `locality` (the hamlet or the place of a village), `district` (a quarter of a town),
	 * `settlement` (the village, town or city: the commune in most countries), `county` (département, province, powiat), `state` (région, land),
	 * `country`, and `admin` (the numbered administrative levels, `level2` the country, `level4`, `level6`, `level8`... as OpenStreetMap numbers them).
	 *
	 * @param array<string, mixed> $answer
	 * @return array<string, mixed>
	 */
	public static function levels( array $answer ): array {
		$geocoding = self::geocoding( $answer );
		if ( null !== $geocoding ) {
			$admin = isset( $geocoding['admin'] ) && is_array( $geocoding['admin'] ) ? $geocoding['admin'] : [];

			return self::withSite( [
				'name'       => self::text( $geocoding['name'] ?? null ),
				'kind'       => trim( self::text( $geocoding['osm_key'] ?? null ) . ' ' . self::text( $geocoding['osm_value'] ?? null ) ),
				'locality'   => self::text( $geocoding['locality'] ?? null ),
				'district'   => self::text( $geocoding['district'] ?? null ),
				'settlement' => self::text( $geocoding['city'] ?? null ) ?: self::text( $admin['level8'] ?? null ),
				'county'     => self::text( $geocoding['county'] ?? null ) ?: self::text( $admin['level6'] ?? null ),
				'state'      => self::text( $geocoding['state'] ?? null ) ?: self::text( $admin['level4'] ?? null ),
				'country'    => self::text( $geocoding['country'] ?? null ) ?: self::text( $admin['level2'] ?? null ),
				'admin'      => array_filter( array_map( [ self::class, 'text' ], $admin ), static fn ( string $name ): bool => '' !== $name ),
			] );
		}

		// The older format: the keys of the address, whose meaning changes with the country.
		$address = isset( $answer['address'] ) && is_array( $answer['address'] ) ? $answer['address'] : [];
		$first   = static function ( array $keys ) use ( $address ): string {
			foreach ( $keys as $key ) {
				if ( ! empty( $address[ $key ] ) ) {
					return (string) $address[ $key ];
				}
			}

			return '';
		};

		return self::withSite( [
			'name'       => self::text( $answer['name'] ?? null ),
			'kind'       => trim( self::text( $answer['category'] ?? null ) . ' ' . self::text( $answer['type'] ?? null ) ),
			'locality'   => $first( [ 'hamlet', 'isolated_dwelling' ] ),
			'district'   => $first( [ 'suburb', 'neighbourhood', 'quarter' ] ),
			'settlement' => $first( [ 'village', 'town', 'city' ] ),
			'county'     => $first( [ 'county' ] ),
			'state'      => $first( [ 'state' ] ),
			'country'    => $first( [ 'country' ] ),
			'admin'      => [],
		] );
	}

	/**
	 * Adds `site`: the name of the place below the commune, for a grouping finer than the commune (a hamlet, a place-name of the countryside). It is
	 * the name of what was found when that is a place (`place locality`, `place hamlet`, `place isolated_dwelling`...), not when it is a land use, a
	 * boundary or a building (a housing estate, a marsh: not a place name); else the locality, else the district. A name that is that of the
	 * settlement is not a finer level: the next candidate is taken, and `site` is empty when there is none.
	 *
	 * @param array<string, mixed> $levels
	 * @return array<string, mixed>
	 */
	private static function withSite( array $levels ): array {
		$candidates = [];
		if ( str_starts_with( (string) $levels['kind'], 'place ' ) ) {
			$candidates[] = (string) $levels['name'];
		}
		$candidates[] = (string) $levels['locality'];
		$candidates[] = (string) $levels['district'];

		$levels['site'] = '';
		foreach ( $candidates as $candidate ) {
			if ( '' !== $candidate && 0 !== strcasecmp( $candidate, (string) $levels['settlement'] ) ) {
				$levels['site'] = $candidate;

				break;
			}
		}

		return $levels;
	}

	/**
	 * @param mixed $value
	 */
	public static function text( $value ): string {
		return is_string( $value ) || is_numeric( $value ) ? trim( (string) $value ) : '';
	}
}
