<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

use WP_Media_Helper\Index\DayIndex;
use WP_Media_Helper\MediaSource\UploadsPath;
use WP_Media_Helper\Thumbnails\Thumbnails;

/**
 * Hiding files from the panel, for the whole site (slice 027).
 *
 * A hidden file is not listed, not offered for import or attach, has no preview and no
 * thumbnail in the cache. Hiding changes nothing else: no attachment, no file, no post.
 * Who may hide and show, and who may see hidden files, is decided by two filters whose
 * default is the capability to upload, so a site can reserve them to some roles.
 */
final class HiddenFiles {

	/**
	 * Whether the current user may hide and show files.
	 */
	public static function canHide(): bool {
		return (bool) apply_filters( 'wp_media_helper_can_hide_files', current_user_can( 'upload_files' ) );
	}

	/**
	 * Whether the current user may list hidden files (to show them again).
	 */
	public static function canSeeHidden(): bool {
		return (bool) apply_filters( 'wp_media_helper_can_see_hidden_files', current_user_can( 'upload_files' ) );
	}

	/**
	 * Hides or shows one file of an active source (a canonical path) and, when hiding,
	 * deletes its thumbnails from the cache.
	 *
	 * @return int Number of thumbnail files deleted.
	 */
	public static function set( string $canonicalPath, bool $hidden ): int {
		DayIndex::forWordPress()->setHidden( [ $canonicalPath ], $hidden, get_current_user_id() );

		return $hidden ? self::deleteThumbnails( $canonicalPath ) : 0;
	}

	public static function isHidden( string $canonicalPath ): bool {
		return [] !== DayIndex::forWordPress()->hiddenAmong( [ $canonicalPath ] );
	}

	/**
	 * Deletes the cached sizes of a file. An attachment of the file keeps working: its sizes
	 * are created again when WordPress asks for them.
	 */
	private static function deleteThumbnails( string $canonicalPath ): int {
		$service  = Thumbnails::serviceForWordPress();
		$uploads  = wp_upload_dir( null, false );
		$relative = is_array( $uploads ) ? UploadsPath::relativeKey( $canonicalPath, (string) ( $uploads['basedir'] ?? '' ) ) : null;

		return null === $service || null === $relative ? 0 : $service->purgeFor( $relative );
	}
}
