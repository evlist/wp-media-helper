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
	 * Turns what the administrator typed into the absolute path that is stored.
	 *
	 * A value relative to $base is prefixed with it. A value starting with `/`
	 * is taken as an absolute path, and is left for validation to accept or
	 * reject, so a stored root outside the base stays visible instead of being
	 * silently re-rooted. Without a base, the value is returned unchanged.
	 */
	public static function toAbsolute( ?string $base, string $input ): string {
		$input = trim( $input );
		if ( null === $base || '' === $input || str_starts_with( $input, '/' ) ) {
			return $input;
		}

		$relative = trim( $input, '/\\' );

		return '' === $relative ? rtrim( $base, '/\\' ) : rtrim( $base, '/\\' ) . '/' . $relative;
	}

	/**
	 * Inverse of toAbsolute(): the part of $root below $base, or $root itself
	 * when it is not inside the base (or when there is no base).
	 */
	public static function toRelative( ?string $base, string $root ): string {
		if ( null === $base ) {
			return $root;
		}

		$prefix = rtrim( $base, '/\\' ) . '/';

		return str_starts_with( $root, $prefix ) ? substr( $root, strlen( $prefix ) ) : $root;
	}

	/**
	 * Canonical path of a directory that exists, or that can be created because
	 * its parent exists (`..` segments and symbolic links are resolved on the
	 * parent). Returns null when neither holds.
	 */
	public static function resolveDirectory( string $path ): ?string {
		$path = rtrim( trim( $path ), '/\\' );
		if ( '' === $path || str_contains( $path, "\0" ) ) {
			return null;
		}

		$real = realpath( $path );
		if ( false !== $real ) {
			return $real;
		}

		$name = basename( $path );
		$parent = realpath( dirname( $path ) );
		if ( false === $parent || '' === $name || '.' === $name || '..' === $name ) {
			return null;
		}

		return rtrim( $parent, '/\\' ) . '/' . $name;
	}

	/**
	 * Like contains(), for a directory that may not exist yet (a cache that
	 * will be created on first use).
	 */
	public static function containsDirectory( string $base, string $path ): bool {
		$realBase = realpath( $base );
		$resolved = self::resolveDirectory( $path );

		return false !== $realBase && null !== $resolved && PathConfinement::isWithin( $realBase, $resolved );
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
