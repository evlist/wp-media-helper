<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Settings;

use WP_Media_Helper\MediaSource\PathConfinement;

/**
 * Lists the sub-directories of a directory below the allowed base, for the directory
 * picker of the settings page, so an administrator chooses a source root instead of typing it.
 *
 * Nothing outside the base is ever listed: the path asked for is resolved with `realpath()` and
 * must be the base or inside it, links and names starting with a dot are not listed, and the
 * number of entries is limited.
 */
final class DirectoryBrowser {

	public const LIMIT = 500;

	/**
	 * @param string   $base      The allowed base directory.
	 * @param string   $relative  A directory below it, as typed (`.` or empty for the base itself).
	 * @param string[] $reserved  Canonical directories that no source lists (built-in exclusions, the thumbnail cache): they are shown, marked.
	 * @return array{path:string, parent:string|null, directories:array<int, array{name:string, path:string, reserved:bool}>, truncated:bool}|null Null when the directory is not inside the base.
	 */
	public static function browse( string $base, string $relative, array $reserved = [] ): ?array {
		$realBase = realpath( $base );
		if ( false === $realBase || ! is_dir( $realBase ) ) {
			return null;
		}

		$relative = self::clean( $relative );
		if ( null === $relative ) {
			return null;
		}

		$directory = '' === $relative ? $realBase : realpath( $realBase . '/' . $relative );
		if ( false === $directory || ! is_dir( $directory ) || ( $directory !== $realBase && ! PathConfinement::isWithin( $realBase, $directory ) ) ) {
			return null;
		}

		$reservedSet = array_flip( $reserved );
		$entries     = scandir( $directory ) ?: [];
		natcasesort( $entries );

		$directories = [];
		$truncated   = false;
		foreach ( $entries as $name ) {
			if ( '' === $name || '.' === $name[0] ) {
				continue;
			}

			$path = $directory . '/' . $name;
			if ( is_link( $path ) || ! is_dir( $path ) ) {
				continue;
			}

			if ( count( $directories ) >= self::LIMIT ) {
				$truncated = true;
				break;
			}

			$real          = realpath( $path );
			$directories[] = [
				'name'     => $name,
				'path'     => '' === $relative ? $name : $relative . '/' . $name,
				'reserved' => false !== $real && isset( $reservedSet[ $real ] ),
			];
		}

		$parent = null;
		if ( '' !== $relative ) {
			$parent = false === strpos( $relative, '/' ) ? '' : substr( $relative, 0, (int) strrpos( $relative, '/' ) );
		}

		return [
			'path'        => $relative,
			'parent'      => $parent,
			'directories' => $directories,
			'truncated'   => $truncated,
		];
	}

	/**
	 * A relative path without leading `./` or slashes; null when it holds `..` or a NUL.
	 */
	public static function clean( string $relative ): ?string {
		// Before trim(), which would strip a trailing NUL byte.
		if ( str_contains( $relative, "\0" ) ) {
			return null;
		}

		$relative = trim( str_replace( '\\', '/', $relative ) );

		$parts = [];
		foreach ( explode( '/', $relative ) as $segment ) {
			if ( '..' === $segment ) {
				return null;
			}
			if ( '' !== $segment && '.' !== $segment ) {
				$parts[] = $segment;
			}
		}

		return implode( '/', $parts );
	}
}
