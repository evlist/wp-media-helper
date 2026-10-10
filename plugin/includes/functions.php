<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * The public functions of Media Helper for themes, blocks and other plugins.
 */

if ( ! function_exists( 'wp_media_helper_get_attached_media' ) ) {
	/**
	 * The media items attached to a post, in order, as the attachment method in use for that post sees it
	 * (slice 042). With the native method this is what `get_attached_media()` finds, as a list of IDs.
	 *
	 * A block or a theme that wants to work with or without Media Helper tests `function_exists()` and falls
	 * back to `get_attached_media()`.
	 *
	 * @param int                  $post_id The post.
	 * @param array<string, mixed> $args    `mime_type`: a type or a family such as `image`; `context`: a free string that
	 *                                      the method may use (for example the slug of a mode).
	 * @return int[] Attachment IDs.
	 */
	function wp_media_helper_get_attached_media( $post_id, $args = [] ) {
		$post_id = absint( $post_id );
		$args    = is_array( $args ) ? $args : [];

		$ids = \WP_Media_Helper\Attachment\Methods::active( $post_id )->attached( $post_id, $args );

		return array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn ( int $id ): bool => $id > 0 ) ) );
	}
}
