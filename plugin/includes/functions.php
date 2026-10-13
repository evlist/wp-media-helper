<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * The public functions of Media Helper for themes, blocks and other plugins.
 */

if ( ! function_exists( 'wp_media_helper_get_attached_media' ) ) {
	/**
	 * The media items attached to a post, in order, as the attachment method in use for that post sees it
	 * (slice 042). With the native method this is what `get_attached_media()` finds, as a list of IDs. With both mechanisms in
	 * use (the cohabitation), the media items attached only the WordPress way are added when the filter
	 * `wp_media_helper_native_links_in_context` says so.
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

		$method = \WP_Media_Helper\Attachment\Methods::active( $post_id );
		$ids    = $method->attached( $post_id, $args );

		// With both mechanisms in use, the media items attached the WordPress way (and unknown to the method) are read too, in
		// the contexts that the filter says: by default only when no context is asked, since such a link says nothing about a context.
		if ( \WP_Media_Helper\Attachment\Methods::cohabitation( $post_id ) ) {
			$context = isset( $args['context'] ) && is_scalar( $args['context'] ) ? (string) $args['context'] : '';
			/**
			 * Whether the links made the WordPress way count for a context when both mechanisms are in use (slice 043).
			 *
			 * @param bool   $include Default: true when no context is asked.
			 * @param string $context The context asked for (for example the slug of a mode).
			 * @param int    $post_id The post.
			 */
			if ( apply_filters( 'wp_media_helper_native_links_in_context', '' === $context, $context, $post_id ) ) {
				$native = ( new \WP_Media_Helper\Attachment\NativeMethod() )->attached( $post_id, $args );
				$known  = $method->describe( $native, $post_id );
				foreach ( $native as $attachment_id ) {
					if ( empty( $known[ $attachment_id ]['attached_here'] ) ) {
						$ids[] = $attachment_id;
					}
				}
			}
		}

		return array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn ( int $id ): bool => $id > 0 ) ) );
	}
}
