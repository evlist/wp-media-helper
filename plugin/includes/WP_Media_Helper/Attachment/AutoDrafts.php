<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Attachment;

/**
 * Auto-drafts: WordPress creates one each time the screen of a new post is opened, and drops it after a while if it was never saved.
 * A media item attached to one (inserted in a post that was never saved) is attached to nothing a person would call a post, so
 * it must not count as "attached to another post", and its links are released when the media item is removed.
 */
final class AutoDrafts {

	private const CHUNK = 200;

	/**
	 * The posts, among these, that are auto-drafts.
	 *
	 * @param int[] $postIds
	 * @return int[]
	 */
	public static function among( array $postIds ): array {
		global $wpdb;

		$ids    = array_values( array_unique( array_filter( array_map( 'intval', $postIds ), static fn ( int $id ): bool => $id > 0 ) ) );
		$drafts = [];
		foreach ( array_chunk( $ids, self::CHUNK ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$found = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND ID IN ( {$placeholders} )", $chunk ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( is_array( $found ) ? $found : [] as $id ) {
				$drafts[] = (int) $id;
			}
		}

		return $drafts;
	}

	/**
	 * Detaches attachments from the posts that are auto-drafts, whichever method holds the link.
	 *
	 * @param int[]                  $attachmentIds
	 * @param array<string, Method>  $methods
	 */
	public static function release( array $attachmentIds, array $methods ): void {
		foreach ( $attachmentIds as $attachmentId ) {
			$attachmentId = (int) $attachmentId;
			foreach ( $methods as $method ) {
				$posts  = $method->posts_of( [ $attachmentId ] )[ $attachmentId ] ?? [];
				$drafts = [] === $posts ? [] : self::among( $posts );
				foreach ( $drafts as $draftId ) {
					$method->detach( $attachmentId, $draftId );
				}
			}
		}
	}

	/**
	 * The posts without the auto-drafts.
	 *
	 * @param int[] $postIds
	 * @return int[]
	 */
	public static function without( array $postIds ): array {
		$drafts = self::among( $postIds );

		return [] === $drafts ? array_values( $postIds ) : array_values( array_diff( $postIds, $drafts ) );
	}
}
