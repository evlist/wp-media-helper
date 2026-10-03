<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Settings;

use WP_Media_Helper\MediaSource\PathConfinement;

/**
 * The directory under which every external source root must live.
 *
 * By default this is the WordPress uploads directory. The site owner can move
 * it from code (never from the admin UI, which would defeat the purpose) with
 * the `WP_MEDIA_HELPER_ALLOWED_BASE` constant or the
 * `wp_media_helper_allowed_base` filter. Setting either to `false` lifts the
 * restriction altogether.
 */
class AllowedBase {

	public const CONSTANT = 'WP_MEDIA_HELPER_ALLOWED_BASE';

	/**
	 * Returns the allowed base directory, or null when roots are unrestricted.
	 */
	public static function resolve(): ?string {
		$base = null;
		if ( defined( self::CONSTANT ) ) {
			$base = constant( self::CONSTANT );
		} elseif ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir( null, false );
			$base    = is_array( $uploads ) ? ( $uploads['basedir'] ?? null ) : null;
		}

		if ( function_exists( 'apply_filters' ) ) {
			$base = apply_filters( 'wp_media_helper_allowed_base', $base );
		}

		return is_string( $base ) && '' !== trim( $base ) ? rtrim( trim( $base ), '/\\' ) : null;
	}

	/**
	 * True when $root exists and lies strictly below $base, once both are
	 * canonicalised (so `..` and symbolic links cannot be used to escape).
	 */
	public static function contains( string $base, string $root ): bool {
		$realBase = realpath( $base );
		$realRoot = realpath( $root );

		return false !== $realBase && false !== $realRoot && PathConfinement::isWithin( $realBase, $realRoot );
	}
}
