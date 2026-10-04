<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Thumbnails;

use WP_Media_Helper\MediaSource\UploadsPath;
use WP_Media_Helper\Settings\AllowedBase;

/**
 * The site-wide thumbnail cache directory.
 *
 * It is `<uploads>/thumbnails` (the folder Thumbnails Folder uses), so its files have
 * public URLs and existing ones are reused. The site owner can move it, still inside
 * the uploads directory, with the `wp_media_helper_thumbnail_cache_dir` filter. Like
 * the allowed base it is set in code, not in the admin screens.
 */
final class ThumbnailCache {

	/**
	 * Canonical path of the cache directory (which may not exist yet), or null when it
	 * would not be strictly inside the uploads directory.
	 */
	public static function directory(): ?string {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return null;
		}

		$uploads = wp_upload_dir( null, false );
		$baseDir = is_array( $uploads ) ? (string) ( $uploads['basedir'] ?? '' ) : '';
		$realBase = '' === $baseDir ? false : realpath( $baseDir );
		if ( false === $realBase ) {
			return null;
		}

		$directory = apply_filters( 'wp_media_helper_thumbnail_cache_dir', $realBase . '/thumbnails', $realBase );
		if ( ! is_string( $directory ) ) {
			return null;
		}

		$real = AllowedBase::resolveDirectory( $directory );

		return null !== $real && null !== UploadsPath::relativeKey( $real, $realBase ) ? $real : null;
	}
}
