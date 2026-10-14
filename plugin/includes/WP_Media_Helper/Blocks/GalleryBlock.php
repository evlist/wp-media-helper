<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Blocks;

/**
 * The block `wp-media-helper/gallery` (slice 043): the standard WordPress gallery, given the images attached to a post.
 *
 * It does not draw a gallery of its own. The images come from `wp_media_helper_get_attached_media()`, for the post of the
 * page and a context (the mode of the page when the plugin Otherguise is there, else none), and are handed to
 * `gallery_shortcode()` as `ids`, with the order asked for explicitly (attribute `orderby`: `title` by default, as the old block forced it;
 * given `ids` alone, WordPress would keep the order of the `ids`, which is the order of the method).
 */
final class GalleryBlock {

	public const NAME = 'wp-media-helper/gallery';

	/**
	 * The orders that the block can ask of the gallery of WordPress: by title (the file name without its extension for the media
	 * items registered by this plugin and by Bulk Media Register), by `menu_order` then ID (WordPress's own default), by date.
	 */
	public const ORDERS = [ 'title', 'menu_order ID', 'date' ];

	public function __construct() {
		add_action( 'init', [ $this, 'register' ] );
	}

	public function register(): void {
		register_block_type( dirname( WP_MEDIA_HELPER_FILE ) . '/blocks/gallery' );
	}

	/**
	 * The HTML of the block.
	 *
	 * @param array<string, mixed> $attributes `columns`, `size`, `link` and `orderby` (`title`, `menu_order ID` or `date`).
	 * @param int                  $postId     The post of the block context; 0 means the post of the loop.
	 * @param callable|null        $gallery    Builds the gallery from its attributes (`gallery_shortcode()`); a seam for tests.
	 */
	public static function render( array $attributes, int $postId = 0, ?callable $gallery = null ): string {
		$postId = $postId > 0 ? $postId : (int) get_the_ID();
		if ( $postId <= 0 ) {
			return '';
		}

		$ids = wp_media_helper_get_attached_media( $postId, [ 'mime_type' => 'image', 'context' => self::context( $postId ) ] );
		if ( [] === $ids ) {
			return '';
		}

		$columns = isset( $attributes['columns'] ) ? max( 1, min( 9, (int) $attributes['columns'] ) ) : 2;
		$size    = isset( $attributes['size'] ) && is_string( $attributes['size'] ) && '' !== $attributes['size'] ? $attributes['size'] : 'gallery';
		$orderby = isset( $attributes['orderby'] ) && in_array( $attributes['orderby'], self::ORDERS, true ) ? (string) $attributes['orderby'] : 'title';
		$link    = isset( $attributes['link'] ) && in_array( $attributes['link'], [ 'file', 'post', 'none' ], true ) ? (string) $attributes['link'] : 'file';

		$gallery ??= 'gallery_shortcode';
		$html      = (string) $gallery( [
			'id'      => $postId,
			'ids'     => implode( ',', $ids ),
			// With `ids` and no `orderby`, WordPress orders the gallery as the `ids` are (`post__in`), which is the order of the method:
			// the order is always asked for, so it does not depend on the method. The old block forced `title`.
			'orderby' => $orderby,
			'order'   => 'ASC',
			'columns' => $columns,
			'size'    => $size,
			'link'    => $link,
		] );

		return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
	}

	/**
	 * What the attachments are asked for: the slug of the mode of the page when Otherguise is active, nothing otherwise. Otherguise
	 * answers the filter `modes_active_mode` (the PHP function of the same name returns an object, not a slug, and is not used).
	 * The filter `wp_media_helper_gallery_context` can set it (the ID of the post is the second argument).
	 */
	private static function context( int $postId ): string {
		$mode    = apply_filters( 'modes_active_mode', '' );
		$context = is_string( $mode ) ? $mode : '';

		return (string) apply_filters( 'wp_media_helper_gallery_context', $context, $postId );
	}
}
