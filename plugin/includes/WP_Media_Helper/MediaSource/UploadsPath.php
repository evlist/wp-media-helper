<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\MediaSource;

/**
 * Pure helpers for the way WordPress identifies a file of the uploads
 * directory: its path relative to the uploads base directory, as stored in the
 * `_wp_attached_file` meta, and the matching URL.
 */
class UploadsPath {

	/**
	 * Collapses repeated separators. Backslashes are separators on Windows only:
	 * on other systems a backslash is a legal character of a file name.
	 */
	public static function normalize( string $path ): string {
		$normalized = '\\' === DIRECTORY_SEPARATOR ? str_replace( '\\', '/', $path ) : $path;

		return (string) preg_replace( '#/+#', '/', $normalized );
	}

	/**
	 * Path of $path relative to $basedir (forward slashes), or null when the
	 * file is not below the base directory or the path contains `.` or `..`
	 * segments. A canonical (`realpath()`) comparison is tried when the plain
	 * string comparison does not apply, to follow symbolic links in the base.
	 */
	public static function relativeKey( string $path, string $basedir ): ?string {
		if ( '' === $path || '' === $basedir || str_contains( $path, "\0" ) ) {
			return null;
		}

		$relative = self::clean( self::stripPrefix( self::normalize( $path ), self::normalize( $basedir ) ) );
		if ( null !== $relative ) {
			return $relative;
		}

		$realPath = realpath( $path );
		$realBase = realpath( $basedir );
		if ( false === $realPath || false === $realBase ) {
			return null;
		}

		return self::clean( self::stripPrefix( self::normalize( $realPath ), self::normalize( $realBase ) ) );
	}

	/**
	 * Every value under which an attachment for $path may be recorded in
	 * `_wp_attached_file`: the relative key written by WordPress and by this
	 * plugin, and the absolute forms written by older versions of this plugin.
	 *
	 * @return string[]
	 */
	public static function lookupKeys( string $path, string $basedir ): array {
		$keys = [];
		$relative = self::relativeKey( $path, $basedir );
		if ( null !== $relative ) {
			$keys[] = $relative;
		}

		$keys[] = $path;
		$real = realpath( $path );
		if ( false !== $real ) {
			$keys[] = $real;
		}

		return array_values( array_unique( array_filter( $keys, static fn ( string $key ): bool => '' !== $key ) ) );
	}

	/**
	 * Percent-encodes each segment of a relative path (UTF-8), keeping `/`.
	 *
	 * WordPress appends `_wp_attached_file` to the uploads URL without encoding
	 * it, so names with spaces, `#`, `?`, `%` or non-ASCII characters would give
	 * wrong URLs. The stored path stays raw so it is encoded exactly once.
	 */
	public static function encodePath( string $relative ): string {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $relative ) ) );
	}

	public static function needsEncoding( string $relative ): bool {
		return self::encodePath( $relative ) !== $relative;
	}

	public static function url( string $baseUrl, string $relative ): string {
		return rtrim( $baseUrl, '/' ) . '/' . self::encodePath( $relative );
	}

	private static function stripPrefix( string $path, string $base ): ?string {
		$base = rtrim( $base, '/' );
		if ( '' === $base ) {
			return null;
		}

		return str_starts_with( $path, $base . '/' ) ? substr( $path, strlen( $base ) + 1 ) : null;
	}

	private static function clean( ?string $relative ): ?string {
		if ( null === $relative ) {
			return null;
		}

		$relative = trim( $relative, '/' );
		if ( '' === $relative ) {
			return null;
		}

		foreach ( explode( '/', $relative ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return null;
			}
		}

		return $relative;
	}
}
