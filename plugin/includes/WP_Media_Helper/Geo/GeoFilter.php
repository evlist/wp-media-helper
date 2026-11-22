<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Geo;

use WP_Media_Helper\Index\WpdbIndexStore;

/**
 * The contract with the code that wants to know where a file was taken (slice 052):
 *
 *     $geo = apply_filters( 'wp_media_helper_file_geo', null, $address );
 *     $geo = wp_media_helper_get_file_geo( $address );   // the same
 *
 * `wp_media_helper_get_files_geo( $addresses )` answers for a list at once. `$address` is a path, the URL of an original or the URL of a thumbnail of the cache. The answer is null when the plugin does not know the file,
 * else an array: see FileGeo::describe(). Coordinates are for the code of the site only: they must not be printed in a public page.
 */
final class GeoFilter {

	public static function register(): void {
		add_filter( 'wp_media_helper_file_geo', [ self::class, 'answer' ], 10, 2 );
		add_filter( 'wp_media_helper_place_of', [ self::class, 'placeOf' ], 10, 3 );
	}

	/**
	 * The place of a position from the cache of the places (the language of the settings), when no earlier filter gave one.
	 *
	 * @param mixed $previous
	 * @return mixed
	 */
	public static function placeOf( $previous, $lat = null, $lon = null ) {
		if ( null !== $previous || ! is_numeric( $lat ) || ! is_numeric( $lon ) ) {
			return $previous;
		}

		$settings = Places\PlacesCron::settings();

		return Places\PlaceResolver::forPosition( (float) $lat, (float) $lon, $settings->language, null, $settings->precision );
	}

	/**
	 * @param mixed $previous What an earlier filter gave.
	 * @return mixed
	 */
	public static function answer( $previous, $address = '' ) {
		if ( null !== $previous || ! is_string( $address ) || '' === $address ) {
			return $previous;
		}

		$roots = self::roots();
		if ( null === $roots ) {
			return $previous;
		}

		return FileGeo::lookup( new WpdbIndexStore(), $address, $roots, self::placeOfCallback() );
	}

	/**
	 * Several addresses at once: `address => answer or null` (one query for all, not one per file).
	 *
	 * @param string[] $addresses
	 * @return array<string, array<string, mixed>|null>
	 */
	public static function answerMany( array $addresses ): array {
		$roots = self::roots();
		if ( null === $roots ) {
			return array_fill_keys( array_filter( $addresses, 'is_string' ), null );
		}

		return FileGeo::lookupMany( new WpdbIndexStore(), $addresses, $roots, self::placeOfCallback() );
	}

	/**
	 * @return array{basedir:string, baseurl:string, contentdir:string, contenturl:string, hosts:string[]}|null
	 */
	private static function roots(): ?array {
		$uploads = wp_upload_dir( null, false );
		if ( ! is_array( $uploads ) || empty( $uploads['basedir'] ) ) {
			return null;
		}

		$hosts = array_filter( [ function_exists( 'home_url' ) ? (string) home_url() : '', function_exists( 'site_url' ) ? (string) site_url() : '' ] );

		return [
			'basedir'    => (string) $uploads['basedir'],
			'baseurl'    => (string) ( $uploads['baseurl'] ?? '' ),
			'contentdir' => defined( 'WP_CONTENT_DIR' ) ? (string) WP_CONTENT_DIR : '',
			'contenturl' => function_exists( 'content_url' ) ? (string) content_url() : '',
			/**
			 * The URLs whose host is a host of this site, besides those of the uploads and the content (a CDN, another name of the site).
			 *
			 * @param string[] $urls
			 */
			'hosts'      => array_values( (array) apply_filters( 'wp_media_helper_site_hosts', $hosts ) ),
		];
	}

	private static function placeOfCallback(): callable {
		/**
		 * The place of a position, once the places are on (phase B of slice 052). None until then.
		 *
		 * @param array<string, mixed>|null $place
		 * @param float                     $lat
		 * @param float                     $lon
		 */
		return static fn ( float $lat, float $lon ): ?array => apply_filters( 'wp_media_helper_place_of', null, $lat, $lon );
	}
}
