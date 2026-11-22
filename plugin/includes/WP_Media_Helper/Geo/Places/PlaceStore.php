<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Geo\Places;

/**
 * The cache of the answers of the geocoding service, by cell: the coordinates rounded to 3 decimals (about 100 m) and the language. Many files
 * share a cell and cost one request.
 */
final class PlaceStore {

	public const STATE_FOUND     = 1;
	public const STATE_NOTHING   = 2;
	public const STATE_FAILED    = 3;
	public const STATE_GAVE_UP   = 4;

	/** The decimals of the cell: 3 is about 100 m, 4 about 10 m, 5 about 1 m. */
	private int $precision;

	public function __construct( int $precision = 3 ) {
		$this->precision = max( 3, min( 5, $precision ) );
	}

	/**
	 * The cell of a position: the coordinates rounded to `$precision` decimals (3 by default: about 100 m).
	 *
	 * @return array{lat:float, lon:float}
	 */
	public static function cell( float $lat, float $lon, int $precision = 3 ): array {
		$precision = max( 3, min( 5, $precision ) );

		// `+ 0.0` turns a "-0" (-0.0004 rounded) into 0.
		return [ 'lat' => round( $lat, $precision ) + 0.0, 'lon' => round( $lon, $precision ) + 0.0 ];
	}

	/**
	 * The key of a cell in the cache. At 3 decimals it is the key of the first version; another precision has its own keys, so that cells of
	 * different sizes are never taken for one another.
	 */
	public static function key( float $lat, float $lon, string $language, int $precision = 3 ): string {
		$precision = max( 3, min( 5, $precision ) );
		$cell      = self::cell( $lat, $lon, $precision );
		$language  = strtolower( trim( $language ) );

		return 3 === $precision
			? sha1( sprintf( '%.3f,%.3f|%s', $cell['lat'], $cell['lon'], $language ) )
			: sha1( sprintf( '%.' . $precision . 'f,%.' . $precision . 'f|%s|p%d', $cell['lat'], $cell['lon'], $language, $precision ) );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find( string $key ): ?array {
		global $wpdb;

		$table = PlacesSchema::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE cell_key = %s", $key ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return is_array( $row ) ? self::typed( $row ) : null;
	}

	/**
	 * Records an answer, or a failure, for a cell (the cell is created when it is new).
	 *
	 * @param array<string, mixed> $fields `state`, and as they apply `attempts`, `next_try`, `osm_type`, `osm_id`, `place_id`, `display_name`, `response`, `service`, `fetched_at`.
	 */
	public function save( float $lat, float $lon, string $language, array $fields ): void {
		global $wpdb;

		$key  = self::key( $lat, $lon, $language, $this->precision );
		$cell = self::cell( $lat, $lon, $this->precision );
		$data = array_intersect_key( $fields, array_flip( [ 'state', 'attempts', 'next_try', 'osm_type', 'osm_id', 'place_id', 'display_name', 'response', 'service', 'fetched_at' ] ) );
		if ( null !== $this->find( $key ) ) {
			$wpdb->update( PlacesSchema::table(), $data, [ 'cell_key' => $key ] ); // phpcs:ignore WordPress.DB

			return;
		}

		$wpdb->insert( PlacesSchema::table(), array_merge( [ 'cell_key' => $key, 'lat' => $cell['lat'], 'lon' => $cell['lon'], 'lang' => strtolower( trim( $language ) ) ], $data ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Number of cells by state.
	 *
	 * @return array<int, int>
	 */
	public function counts(): array {
		global $wpdb;

		$table  = PlacesSchema::table();
		$rows   = $wpdb->get_results( "SELECT state, COUNT(*) AS total FROM {$table} GROUP BY state", ARRAY_A ); // phpcs:ignore WordPress.DB
		$counts = [ 1 => 0, 2 => 0, 3 => 0, 4 => 0 ];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$counts[ (int) $row['state'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * The answers found, decoded, in chunks (a library has tens of thousands of them).
	 *
	 * @return \Generator<int, array<string, mixed>>
	 */
	public function foundAnswers( int $chunk = 500 ): \Generator {
		global $wpdb;

		$table = PlacesSchema::table();
		$after = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, response FROM {$table} WHERE state = 1 AND id > %d ORDER BY id ASC LIMIT %d", $after, $chunk ), ARRAY_A ); // phpcs:ignore WordPress.DB
			$rows = is_array( $rows ) ? $rows : [];
			foreach ( $rows as $row ) {
				$after   = (int) $row['id'];
				$decoded = json_decode( (string) $row['response'], true );
				if ( is_array( $decoded ) ) {
					yield $after => $decoded;
				}
			}
		} while ( count( $rows ) === $chunk );
	}

	/**
	 * A few of the cells found, chosen at random.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function sample( int $count ): array {
		global $wpdb;

		$table = PlacesSchema::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE state = 1 ORDER BY RAND() LIMIT %d", max( 1, $count ) ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return array_map( [ self::class, 'typed' ], is_array( $rows ) ? $rows : [] );
	}

	/**
	 * Forgets the cells that failed or were given up (so that they are asked for again), or all of them.
	 */
	public function reset( bool $failedOnly = true ): int {
		global $wpdb;

		$table = PlacesSchema::table();

		return (int) $wpdb->query( $failedOnly ? "DELETE FROM {$table} WHERE state IN (3, 4)" : "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function typed( array $row ): array {
		foreach ( [ 'id', 'state', 'attempts', 'next_try', 'osm_id', 'place_id', 'fetched_at' ] as $column ) {
			if ( array_key_exists( $column, $row ) && null !== $row[ $column ] ) {
				$row[ $column ] = (int) $row[ $column ];
			}
		}
		foreach ( [ 'lat', 'lon' ] as $column ) {
			if ( array_key_exists( $column, $row ) && null !== $row[ $column ] ) {
				$row[ $column ] = (float) $row[ $column ];
			}
		}

		return $row;
	}
}
