<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Settings;

/**
 * Directories of the uploads directory that hold private or technical files, and are
 * never listed by a source that contains them.
 *
 * This is a default, not a guarantee: a private directory that is not on the list can
 * still be listed. The site owner extends the list in code with the
 * `wp_media_helper_excluded_directories` filter, like the allowed base.
 */
final class BuiltInExclusions {

	/**
	 * Names relative to the uploads directory. `*` and `?` match within one name.
	 */
	public const DEFAULTS = [
		'woocommerce_uploads',
		'wc-logs',
		'wpcf7_uploads',
		'cache',
		'backup*',
		'backups',
		'ai1wm-backups',
		'wpforms',
		'elementor',
		'sucuri',
		'wp-media-helper-index',
	];

	/**
	 * The names in force: the defaults, changed by the filter. Unsafe entries (absolute,
	 * with `..` or empty segments) are dropped.
	 *
	 * @return string[]
	 */
	public static function names(): array {
		$names = function_exists( 'apply_filters' ) ? apply_filters( 'wp_media_helper_excluded_directories', self::DEFAULTS ) : self::DEFAULTS;

		return self::clean( is_array( $names ) ? $names : self::DEFAULTS );
	}

	/**
	 * @param array<int, mixed> $names
	 * @return string[]
	 */
	public static function clean( array $names ): array {
		$clean = [];
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}
			$name = trim( str_replace( '\\', '/', $name ), '/' );
			if ( '' === $name || str_contains( $name, "\0" ) ) {
				continue;
			}
			foreach ( explode( '/', $name ) as $segment ) {
				if ( '' === $segment || '.' === $segment || '..' === $segment ) {
					continue 2;
				}
			}
			$clean[] = $name;
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * The existing directories below `$base` that match the names, canonical.
	 *
	 * @param string[] $names
	 * @return string[]
	 */
	public static function directories( string $base, array $names ): array {
		$realBase = realpath( $base );
		if ( false === $realBase ) {
			return [];
		}

		$directories = [];
		foreach ( $names as $name ) {
			foreach ( glob( $realBase . '/' . $name, GLOB_ONLYDIR | GLOB_NOSORT ) ?: [] as $match ) {
				$real = realpath( $match );
				if ( false !== $real && is_dir( $real ) ) {
					$directories[] = $real;
				}
			}
		}

		sort( $directories );

		return array_values( array_unique( $directories ) );
	}
}
