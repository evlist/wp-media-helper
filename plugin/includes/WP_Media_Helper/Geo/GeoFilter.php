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
 * `$address` is a path, the URL of an original or the URL of a thumbnail of the cache. The answer is null when the plugin does not know the file,
 * else an array: see FileGeo::describe(). Coordinates are for the code of the site only: they must not be printed in a public page.
 */
final class GeoFilter {

	public static function register(): void {
		add_filter( 'wp_media_helper_file_geo', [ self::class, 'answer' ], 10, 2 );
	}

	/**
	 * @param mixed $previous What an earlier filter gave.
	 * @return mixed
	 */
	public static function answer( $previous, $address = '' ) {
		if ( null !== $previous || ! is_string( $address ) || '' === $address ) {
			return $previous;
		}

		$uploads = wp_upload_dir( null, false );
		if ( ! is_array( $uploads ) || empty( $uploads['basedir'] ) ) {
			return $previous;
		}

		return FileGeo::lookup(
			new WpdbIndexStore(),
			$address,
			[
				'basedir'    => (string) $uploads['basedir'],
				'baseurl'    => (string) ( $uploads['baseurl'] ?? '' ),
				'contentdir' => defined( 'WP_CONTENT_DIR' ) ? (string) WP_CONTENT_DIR : '',
				'contenturl' => function_exists( 'content_url' ) ? (string) content_url() : '',
			],
			/**
			 * The place of a position, once the places are on (phase B of slice 052). None until then.
			 *
			 * @param array<string, mixed>|null $place
			 * @param float                     $lat
			 * @param float                     $lon
			 */
			static fn ( float $lat, float $lon ): ?array => apply_filters( 'wp_media_helper_place_of', null, $lat, $lon )
		);
	}
}
