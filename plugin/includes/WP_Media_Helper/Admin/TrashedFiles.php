<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

use WP_Media_Helper\Index\DayIndex;
use WP_Media_Helper\MediaSource\UploadsPath;
use WP_Media_Helper\Thumbnails\Thumbnails;

/**
 * The trash of the gallery, for the whole site (slice 027, renamed in slice 039).
 *
 * A trashed file stays on the disk, untouched, and may be in a read-only source. It is not listed,
 * not offered for import or attach, has no preview and no thumbnail in the cache; its attachments
 * are removed from the media library by the caller (see EditorMediaController). It is listed again
 * only in the trash view, from which it can be restored to the gallery. The flag is the `hidden`
 * column of the index. Who may trash and restore, and who may see the trash, is decided by two filters
 * whose default is the capability to upload, so a site can reserve them to some roles.
 */
final class TrashedFiles {

	/**
	 * Whether the current user may trash and restore files.
	 */
	public static function canTrash(): bool {
		// The former name of the filter still works.
		$default = (bool) apply_filters( 'wp_media_helper_can_hide_files', current_user_can( 'upload_files' ) );

		return (bool) apply_filters( 'wp_media_helper_can_trash_files', $default );
	}

	/**
	 * Whether the current user may look into the trash (to restore files).
	 */
	public static function canSeeTrash(): bool {
		$default = (bool) apply_filters( 'wp_media_helper_can_see_hidden_files', current_user_can( 'upload_files' ) );

		return (bool) apply_filters( 'wp_media_helper_can_see_trash', $default );
	}

	/**
	 * Trashes or restores one file of an active source (a canonical path) and, when trashing,
	 * deletes its thumbnails from the cache.
	 *
	 * @return int Number of thumbnail files deleted.
	 */
	public static function set( string $canonicalPath, bool $trashed ): int {
		DayIndex::forWordPress()->setHidden( [ $canonicalPath ], $trashed, get_current_user_id() );

		return $trashed ? self::deleteThumbnails( $canonicalPath ) : 0;
	}

	public static function isTrashed( string $canonicalPath ): bool {
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
