<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Geo;

use WP_Media_Helper\Index\IndexStore;
use WP_Media_Helper\MediaSource\UploadsPath;

/**
 * What the plugin knows of where a file was taken, for the filter `wp_media_helper_file_geo` (slice 052). Never public: this is read by the
 * code of the site (the module Index of Otherguise), not printed.
 *
 * The address of a file can be its path, the URL of the original, or the URL of a thumbnail of the cache
 * (`uploads/thumbnails/<dir>/<name>-<w>x<h>.<ext>`), which is mapped back to the original.
 */
final class FileGeo {

	/**
	 * The keys of the index that an address may stand for, the most likely first: the key of the address itself, then, for a thumbnail of the
	 * cache, that of its original.
	 *
	 * @param array{basedir:string, baseurl:string, contentdir:string, contenturl:string} $roots Uploads directory and URL, content directory and URL.
	 * @return string[]
	 */
	public static function candidateKeys( string $address, array $roots ): array {
		$address = trim( $address );
		if ( '' === $address || str_contains( $address, "\0" ) ) {
			return [];
		}

		$key = null;
		if ( 1 === preg_match( '#^(https?:)?//#i', $address ) ) {
			$url = (string) preg_replace( '#[?\#].*$#', '', $address );
			$key = self::fromUrl( rawurldecode( $url ), $roots );
		} elseif ( str_starts_with( $address, '/' ) ) {
			$key = UploadsPath::relativeKey( $address, $roots['basedir'] ) ?? UploadsPath::normalize( $address );
		} else {
			$key = ltrim( UploadsPath::normalize( $address ), '/' );
		}
		if ( null === $key || '' === $key || in_array( '..', explode( '/', $key ), true ) ) {
			return [];
		}

		$keys = [ $key ];
		// A thumbnail of the cache stands for its original; a size made by WordPress (`-300x200`, `-scaled`) too, the file itself first.
		$original = str_starts_with( $key, 'thumbnails/' ) ? substr( $key, strlen( 'thumbnails/' ) ) : $key;
		$original = (string) preg_replace( '#-(\d+x\d+|scaled)(\.[A-Za-z0-9]+)$#', '$2', $original );
		if ( $original !== $key && '' !== $original ) {
			$keys[] = $original;
		}

		return $keys;
	}

	/**
	 * A host without `www.`, lower case, for the comparison of the hosts of one site.
	 */
	private static function host( string $url ): string {
		$host = parse_url( 'http:' . ( str_starts_with( $url, '//' ) ? $url : '//' . preg_replace( '#^[a-z]+://#i', '', $url ) ), PHP_URL_HOST );

		return is_string( $host ) ? (string) preg_replace( '#^www\.#', '', strtolower( $host ) ) : '';
	}

	/**
	 * The key of an address of the site: the scheme (http or https) does not matter, nor `www.`; another host counts only when it is a host
	 * of the site (the URL of the uploads, of the content, `roots['hosts']`).
	 *
	 * @param array{basedir:string, baseurl:string, contentdir:string, contenturl:string, hosts?:string[]} $roots
	 */
	private static function fromUrl( string $url, array $roots ): ?string {
		$known = [];
		foreach ( array_merge( [ $roots['baseurl'], $roots['contenturl'] ], $roots['hosts'] ?? [] ) as $base ) {
			if ( '' !== (string) $base && '' !== self::host( (string) $base ) ) {
				$known[ self::host( (string) $base ) ] = true;
			}
		}
		if ( ! isset( $known[ self::host( $url ) ] ) ) {
			return null;
		}
		$path = (string) parse_url( 'http:' . ( str_starts_with( $url, '//' ) ? $url : '//' . preg_replace( '#^[a-z]+://#i', '', $url ) ), PHP_URL_PATH );

		$uploads = rtrim( (string) parse_url( $roots['baseurl'], PHP_URL_PATH ), '/' ) . '/';
		if ( '' !== $roots['baseurl'] && str_starts_with( $path, $uploads ) ) {
			return substr( $path, strlen( $uploads ) );
		}
		$content = rtrim( (string) parse_url( $roots['contenturl'], PHP_URL_PATH ), '/' ) . '/';
		if ( '' !== $roots['contenturl'] && str_starts_with( $path, $content ) ) {
			// A file outside the uploads directory is kept in the index by its absolute path.
			return UploadsPath::normalize( rtrim( $roots['contentdir'], '/' ) . '/' . substr( $path, strlen( $content ) ) );
		}

		return null;
	}

	/**
	 * What to give for the row of a file, or null when the plugin knows nothing of the file.
	 *
	 * @param array<string, mixed> $row           A row of `IndexStore::geoRowsByKeys()`.
	 * @param callable|null        $placeOf       `( float $lat, float $lon ): ?array`, the place of a position (none until the places are on).
	 * @return array<string, mixed>
	 */
	public static function describe( array $row, ?callable $placeOf = null ): array {
		$state = (int) ( $row['geo_state'] ?? 0 );
		$place = static fn ( $lat, $lon ): ?array => null !== $placeOf && null !== $lat && null !== $lon ? $placeOf( (float) $lat, (float) $lon ) : null;
		$known = 1 === $state;

		if ( 'gps' === ( $row['kind'] ?? '' ) ) {
			return [
				'type'  => 'track',
				'state' => 0 === $state ? 'unread' : ( $known ? 'positioned' : 'none' ),
				'start' => [ 'lat' => $row['gps_lat'] ?? null, 'lon' => $row['gps_lon'] ?? null, 'at' => $row['track_start_at'] ?? null, 'place' => $place( $row['gps_lat'] ?? null, $row['gps_lon'] ?? null ) ],
				'end'   => [ 'lat' => $row['gps_end_lat'] ?? null, 'lon' => $row['gps_end_lon'] ?? null, 'at' => $row['track_end_at'] ?? null, 'place' => $place( $row['gps_end_lat'] ?? null, $row['gps_end_lon'] ?? null ) ],
			];
		}

		return [
			'type'            => 'file',
			'state'           => 0 === $state ? 'unread' : ( $known ? 'positioned' : 'none' ),
			'lat'             => $known ? ( $row['gps_lat'] ?? null ) : null,
			'lon'             => $known ? ( $row['gps_lon'] ?? null ) : null,
			// The date the plugin shows for the file, in the time zone of the site, and where it comes from (name, embedded, mtime) with the
			// precision of the date of the name (a day alone is placed at noon).
			'taken_at'        => $row['effective_date'] ?? null,
			'taken_source'    => $row['date_source'] ?? null,
			'taken_precision' => $row['name_date_precision'] ?? null,
			'place'           => $known ? $place( $row['gps_lat'] ?? null, $row['gps_lon'] ?? null ) : null,
		];
	}

	/**
	 * The answer for an address, from the index.
	 *
	 * @param array{basedir:string, baseurl:string, contentdir:string, contenturl:string} $roots
	 * @return array<string, mixed>|null
	 */
	public static function lookup( IndexStore $store, string $address, array $roots, ?callable $placeOf = null ): ?array {
		return self::lookupMany( $store, [ $address ], $roots, $placeOf )[ $address ] ?? null;
	}

	/**
	 * The answers for several addresses with one query: `address => answer or null`, in the order given.
	 *
	 * @param string[] $addresses
	 * @param array{basedir:string, baseurl:string, contentdir:string, contenturl:string, hosts?:string[]} $roots
	 * @return array<string, array<string, mixed>|null>
	 */
	public static function lookupMany( IndexStore $store, array $addresses, array $roots, ?callable $placeOf = null ): array {
		$wanted = [];
		$all    = [];
		foreach ( $addresses as $address ) {
			if ( ! is_string( $address ) ) {
				continue;
			}
			$wanted[ $address ] = self::candidateKeys( $address, $roots );
			$all                = array_merge( $all, $wanted[ $address ] );
		}

		$byPath = [];
		foreach ( [] === $all ? [] : $store->geoRowsByKeys( array_values( array_unique( $all ) ) ) as $row ) {
			$byPath[ (string) $row['path'] ] ??= $row;
		}

		$answers = [];
		foreach ( $wanted as $address => $keys ) {
			$answers[ $address ] = null;
			foreach ( $keys as $key ) {
				if ( isset( $byPath[ $key ] ) ) {
					$answers[ $address ] = self::describe( $byPath[ $key ], $placeOf );
					break;
				}
			}
		}

		return $answers;
	}
}
