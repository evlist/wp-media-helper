<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\MediaSource;

/**
 * Removes attachments from the media library, the records only: no file is deleted, none of the original, none
 * of its sizes, even when another attachment keeps the same path.
 *
 * `wp_delete_post()` deletes the attached file and its sub-sizes. The `_wp_attached_file` meta is removed first so
 * that core finds nothing to delete (and the thumbnails of the shared cache, which this plugin purges on
 * `delete_attachment` from that same meta, are not touched either), and a `wp_delete_file` filter refuses every
 * deletion for the duration of the call, in case another plugin restores that value. The index does not depend on
 * the attachments: a file is shown as registered as long as an attachment keeps its path.
 *
 * No capability check is made: the caller decides (a script runs without a user).
 */
final class RecordRemover {

	/**
	 * Removes the records of the attachments that every attachment method allows to remove (`Method::may_remove()` with the
	 * context `trash`: the file is gone, the user was asked, the entries of the attachment disappear from every post), and tells
	 * the methods, with the action `wp_media_helper_attachments_removed`, so that they can drop their own links.
	 *
	 * @param int[]                                                     $ids
	 * @param array<string, \WP_Media_Helper\Attachment\Method>         $methods
	 * @param ?callable(int[]): int[]                                   $remove  Removes records and returns the IDs removed (tests).
	 * @return array{removed:int[], refused:array<int, string>} The IDs removed, and for the others the reason, by ID.
	 */
	public static function removeGuarded( array $ids, array $methods, string $context = 'trash', ?callable $remove = null ): array {
		$allowed = [];
		$refused = [];
		foreach ( $ids as $id ) {
			$id = (int) $id;
			foreach ( $methods as $method ) {
				$answer = $method->may_remove( $id, $context );
				if ( is_wp_error( $answer ) ) {
					$refused[ $id ] = $method->id() . ': ' . $answer->get_error_message();
					continue 2;
				}
			}
			$allowed[] = $id;
		}

		$removed = ( $remove ?? [ self::class, 'remove' ] )( $allowed );
		if ( [] !== $removed ) {
			/**
			 * Fires once the records of attachments were removed from the media library (their files are untouched).
			 *
			 * @param int[]  $ids    The IDs removed.
			 * @param string $reason Why: `orphans` (the file was gone).
			 */
			do_action( 'wp_media_helper_attachments_removed', $removed, 'orphans' );
		}

		return [ 'removed' => $removed, 'refused' => $refused ];
	}

	/**
	 * @param int[] $ids
	 * @return int[] The IDs removed.
	 */
	public static function remove( array $ids ): array {
		$refuse  = static fn (): string => '';
		$removed = [];
		add_filter( 'wp_delete_file', $refuse, PHP_INT_MAX );

		try {
			foreach ( $ids as $id ) {
				$id = (int) $id;
				if ( 'attachment' !== get_post_type( $id ) ) {
					continue;
				}
				delete_post_meta( $id, '_wp_attached_file' );
				if ( ! empty( wp_delete_post( $id, true ) ) ) {
					$removed[] = $id;
				}
			}
		} finally {
			remove_filter( 'wp_delete_file', $refuse, PHP_INT_MAX );
		}

		return $removed;
	}
}
