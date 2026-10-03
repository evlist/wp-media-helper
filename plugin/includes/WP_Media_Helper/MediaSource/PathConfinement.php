<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\MediaSource;

/**
 * Keeps client-supplied file paths inside the roots of configured sources.
 *
 * Paths reaching the AJAX endpoints come from the browser and must never be
 * trusted: they are resolved with realpath() (so `..` segments and symbolic
 * links are collapsed) and accepted only when the result lies strictly below
 * a configured root.
 */
class PathConfinement {

	/**
	 * Returns the canonical path of an existing file located below $root, or
	 * null when the file does not exist, is not a regular file, or escapes the
	 * root (via `..`, an absolute path elsewhere, or a symbolic link).
	 */
	public static function resolveFileWithinRoot( string $root, string $path ): ?string {
		$realRoot = self::canonicalRoot( $root );
		if ( null === $realRoot || '' === trim( $path ) || str_contains( $path, "\0" ) ) {
			return null;
		}

		$realPath = realpath( $path );
		if ( false === $realPath || ! is_file( $realPath ) ) {
			return null;
		}

		return self::isWithin( $realRoot, $realPath ) ? $realPath : null;
	}

	/**
	 * Finds the configured source whose root contains $path.
	 *
	 * When $sourceId is not empty only that source is considered, so a client
	 * cannot claim a path belongs to a source it does not.
	 *
	 * @param array<int, array<string, mixed>> $sources Active configured sources.
	 * @return array{source: array<string, mixed>, path: string}|null
	 */
	public static function resolveFileInSources( array $sources, string $sourceId, string $path ): ?array {
		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}
			if ( '' !== $sourceId && (string) ( $source['id'] ?? '' ) !== $sourceId ) {
				continue;
			}

			$resolved = self::resolveFileWithinRoot( (string) ( $source['root'] ?? '' ), $path );
			if ( null !== $resolved ) {
				return [ 'source' => $source, 'path' => $resolved ];
			}
		}

		return null;
	}

	/**
	 * True when $realPath is strictly below $realRoot. Both must be canonical.
	 */
	public static function isWithin( string $realRoot, string $realPath ): bool {
		$prefix = rtrim( $realRoot, '/\\' ) . DIRECTORY_SEPARATOR;

		return strlen( $realPath ) > strlen( $prefix ) && str_starts_with( $realPath, $prefix );
	}

	private static function canonicalRoot( string $root ): ?string {
		if ( '' === trim( $root ) ) {
			return null;
		}

		$realRoot = realpath( $root );

		return false !== $realRoot && is_dir( $realRoot ) ? $realRoot : null;
	}
}
