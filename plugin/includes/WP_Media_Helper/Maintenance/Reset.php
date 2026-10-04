<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Maintenance;

use WP_Media_Helper\Admin\PostDateMeta;
use WP_Media_Helper\Index\Cron;
use WP_Media_Helper\Index\ScanState;
use WP_Media_Helper\Index\Schema;
use WP_Media_Helper\MediaSource\AttachmentRegistry;
use WP_Media_Helper\MediaSource\PathConfinement;
use WP_Media_Helper\Settings\ExternalSourceSettings;
use WP_Media_Helper\Settings\GeneralSettings;
use WP_Media_Helper\Thumbnails\ThumbnailCache;
use WP_Media_Helper\Thumbnails\Thumbnails;

/**
 * Removes what the plugin stored, so a site can start again from nothing (tests,
 * a fresh configuration, a clean uninstall).
 *
 * Nothing here ever deletes a media file or a source file: attachments are removed
 * from the Media Library only, and the only files deleted are thumbnails.
 */
final class Reset {

	public const SETTINGS    = 'settings';
	public const INDEX       = 'index';
	public const USER_DATA   = 'user_data';
	public const THUMBNAILS  = 'thumbnails';
	public const CACHE       = 'cache';
	public const ATTACHMENTS = 'attachments';

	/**
	 * @return string[]
	 */
	public static function items(): array {
		return [ self::SETTINGS, self::INDEX, self::USER_DATA, self::THUMBNAILS, self::CACHE, self::ATTACHMENTS ];
	}

	/**
	 * Runs the requested parts, attachments first so their thumbnails go with them.
	 *
	 * @param string[] $items Parts to reset (see items()); unknown values are ignored.
	 * @return array<string, int> What was done: a count per part.
	 */
	public static function run( array $items ): array {
		$items  = array_values( array_intersect( self::items(), $items ) );
		$report = [];

		foreach ( [ self::ATTACHMENTS, self::THUMBNAILS, self::CACHE, self::USER_DATA, self::INDEX, self::SETTINGS ] as $item ) {
			if ( ! in_array( $item, $items, true ) ) {
				continue;
			}

			$report[ $item ] = match ( $item ) {
				self::ATTACHMENTS => self::resetAttachments(),
				self::THUMBNAILS  => self::resetThumbnails(),
				self::CACHE       => self::resetCache(),
				self::USER_DATA   => self::resetUserData(),
				self::INDEX       => self::resetIndex(),
				default           => self::resetSettings(),
			};
		}

		return $report;
	}

	/**
	 * Sources and general settings.
	 */
	private static function resetSettings(): int {
		$removed = 0;
		foreach ( [ ExternalSourceSettings::optionKey(), GeneralSettings::optionKey() ] as $option ) {
			$removed += delete_option( $option ) ? 1 : 0;
		}

		return $removed;
	}

	/**
	 * The index tables, the scan state, the lock and the scheduled events. The tables
	 * are created again, empty, on the next request.
	 */
	private static function resetIndex(): int {
		Cron::clear();
		Schema::drop();
		delete_option( ScanState::OPTION );
		delete_option( Cron::LOCK_OPTION );

		return 1;
	}

	/**
	 * The date of each post and the filters and panel mode of each user.
	 */
	private static function resetUserData(): int {
		global $wpdb;

		$deleted  = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", PostDateMeta::META_KEY ) ); // phpcs:ignore WordPress.DB
		$deleted += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_wp_media_helper_' ) . '%' ) ); // phpcs:ignore WordPress.DB

		return $deleted;
	}

	/**
	 * The cached sizes of the attachments imported by this plugin. The previews of
	 * files that were never imported are only removed with the whole cache.
	 */
	private static function resetThumbnails(): int {
		$service = Thumbnails::serviceForWordPress();
		if ( null === $service ) {
			return 0;
		}

		$deleted = 0;
		foreach ( self::importedAttachmentIds() as $attachmentId ) {
			$relative = get_post_meta( $attachmentId, '_wp_attached_file', true );
			$metadata = wp_get_attachment_metadata( $attachmentId );
			if ( is_string( $relative ) && '' !== $relative && is_array( $metadata ) ) {
				$deleted += $service->purge( $relative, $metadata );
			}
		}

		return $deleted;
	}

	/**
	 * The whole cache directory, including files written by other tools.
	 */
	private static function resetCache(): int {
		$cache   = ThumbnailCache::directory();
		$uploads = wp_upload_dir( null, false );
		$base    = is_array( $uploads ) ? realpath( (string) ( $uploads['basedir'] ?? '' ) ) : false;
		if ( null === $cache || false === $base || ! is_dir( $cache ) || is_link( $cache ) ) {
			return 0;
		}

		return self::deleteTree( $cache, $base );
	}

	/**
	 * Removes the attachments imported by this plugin from the Media Library. Their
	 * files are never deleted.
	 */
	private static function resetAttachments(): int {
		$refuse = static fn (): string => '';
		add_filter( 'wp_delete_file', $refuse, PHP_INT_MAX );

		$removed = 0;
		try {
			foreach ( self::importedAttachmentIds() as $attachmentId ) {
				// Without its file path core cannot find the original to delete either.
				delete_post_meta( $attachmentId, '_wp_attached_file' );
				$removed += wp_delete_post( $attachmentId, true ) ? 1 : 0;
			}
		} finally {
			remove_filter( 'wp_delete_file', $refuse, PHP_INT_MAX );
		}

		return $removed;
	}

	/**
	 * @return int[]
	 */
	private static function importedAttachmentIds(): array {
		global $wpdb;

		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", AttachmentRegistry::SOURCE_ID_META ) ); // phpcs:ignore WordPress.DB

		return array_map( 'intval', is_array( $ids ) ? $ids : [] );
	}

	/**
	 * Deletes a directory and what is in it, without following links, and only when it
	 * is strictly inside `$within`. Returns the number of files deleted.
	 */
	public static function deleteTree( string $directory, string $within ): int {
		$real   = realpath( $directory );
		$inside = realpath( $within );
		if ( false === $real || false === $inside || is_link( $directory ) || ! PathConfinement::isWithin( $inside, $real ) ) {
			return 0;
		}

		$deleted = 0;
		foreach ( scandir( $real ) ?: [] as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $real . '/' . $entry;
			if ( is_link( $path ) || is_file( $path ) ) {
				// A link is removed as a link, whatever it points to.
				if ( @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
					++$deleted;
				}
			} elseif ( is_dir( $path ) ) {
				$deleted += self::deleteTree( $path, $real );
			}
		}
		@rmdir( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions

		return $deleted;
	}
}
