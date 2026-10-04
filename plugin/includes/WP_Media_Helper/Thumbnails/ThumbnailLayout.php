<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Thumbnails;

use WP_Media_Helper\MediaSource\UploadsPath;

/**
 * Where the sub-sizes of an attachment live in the cache.
 *
 * The layout is the one of Thumbnails Folder, so its files are reused as they are:
 * `<cache>/<directory of the original, relative to uploads>/<name>-<W>x<H>.<ext>`.
 * Everything here works on strings and never touches the disk.
 */
final class ThumbnailLayout {

	/**
	 * File name of a sub-size: `photo.jpg` at 1024x577 gives `photo-1024x577.jpg`.
	 */
	public static function fileName( string $original, int $width, int $height ): string {
		$name      = pathinfo( $original, PATHINFO_FILENAME );
		$extension = pathinfo( $original, PATHINFO_EXTENSION );

		return $name . '-' . $width . 'x' . $height . ( '' === $extension ? '' : '.' . $extension );
	}

	/**
	 * Absolute path of a file of the cache from the metadata entry of a size, or null
	 * when the entry gives nothing usable. An absolute `path` written by Thumbnails
	 * Folder is honored when it lies inside the cache; otherwise the location is derived
	 * from the layout, so the metadata does not have to hold any path.
	 *
	 * @param string               $relative Path of the original relative to uploads (`_wp_attached_file`).
	 * @param array<string, mixed> $entry    One entry of `sizes` in `_wp_attachment_metadata`.
	 */
	public static function pathForEntry( string $cacheDir, string $relative, array $entry ): ?string {
		$cacheDir = rtrim( UploadsPath::normalize( $cacheDir ), '/' );

		$path = isset( $entry['path'] ) && is_string( $entry['path'] ) ? UploadsPath::normalize( $entry['path'] ) : '';
		if ( '' !== $path && self::isSafeInside( $cacheDir, $path ) ) {
			return $path;
		}

		$file = isset( $entry['file'] ) && is_string( $entry['file'] ) ? $entry['file'] : '';
		if ( '' === $file || basename( $file ) !== $file || '.' === $file[0] ) {
			return null;
		}

		return self::pathForFile( $cacheDir, $relative, $file );
	}

	/**
	 * Absolute path of a sub-size file of the original at `$relative`, or null when
	 * `$relative` has empty, `.` or `..` segments.
	 */
	public static function pathForFile( string $cacheDir, string $relative, string $file ): ?string {
		if ( null === self::cleanRelative( $relative ) ) {
			return null;
		}

		$directory = dirname( $relative );

		return rtrim( UploadsPath::normalize( $cacheDir ), '/' ) . ( '.' === $directory ? '' : '/' . $directory ) . '/' . $file;
	}

	/**
	 * URL of a file of the cache, given the uploads directory and URL it is inside.
	 */
	public static function url( string $baseDir, string $baseUrl, string $path ): ?string {
		$relative = UploadsPath::relativeKey( $path, $baseDir );

		return null === $relative ? null : UploadsPath::url( $baseUrl, $relative );
	}

	/**
	 * True when `$path` is below `$directory` with no `.` or `..` segment.
	 */
	public static function isSafeInside( string $directory, string $path ): bool {
		$directory = rtrim( UploadsPath::normalize( $directory ), '/' );
		$path      = UploadsPath::normalize( $path );
		if ( '' === $directory || ! str_starts_with( $path, $directory . '/' ) || str_contains( $path, "\0" ) ) {
			return false;
		}

		foreach ( explode( '/', substr( $path, strlen( $directory ) + 1 ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return false;
			}
		}

		return true;
	}

	private static function cleanRelative( string $relative ): ?string {
		if ( '' === $relative || str_starts_with( $relative, '/' ) || str_contains( $relative, "\0" ) ) {
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
