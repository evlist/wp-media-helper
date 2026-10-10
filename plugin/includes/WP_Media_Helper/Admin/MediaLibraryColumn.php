<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

use WP_Media_Helper\Attachment\Methods;

/**
 * The column "Uploaded to" of the media library list, with all the posts of a media item when the
 * attachment method in use lets one belong to several (slice 042).
 *
 * Core only knows `post_parent`, and offers no filter on what its column shows: the column is
 * replaced by one of ours (sortable the same way) only for such a method. With the native method
 * nothing changes.
 */
final class MediaLibraryColumn {

	public const COLUMN = 'wp_media_helper_posts';

	/**
	 * The posts of the attachments of the page being displayed, asked for once.
	 *
	 * @var array<int, int[]>|null
	 */
	private ?array $postsByAttachment = null;

	public function __construct() {
		add_filter( 'manage_media_columns', [ $this, 'columns' ], 20 );
		add_filter( 'manage_upload_sortable_columns', [ $this, 'sortable' ] );
		add_action( 'manage_media_custom_column', [ $this, 'render' ], 10, 2 );
	}

	/**
	 * @param mixed $columns
	 * @return mixed
	 */
	public function columns( $columns ) {
		if ( ! is_array( $columns ) || ! isset( $columns['parent'] ) || ! self::isMultiple() ) {
			return $columns;
		}

		$replaced = [];
		foreach ( $columns as $key => $label ) {
			$replaced[ 'parent' === $key ? self::COLUMN : $key ] = $label;
		}

		return $replaced;
	}

	/**
	 * @param mixed $columns
	 * @return mixed
	 */
	public function sortable( $columns ) {
		if ( is_array( $columns ) && isset( $columns['parent'] ) && self::isMultiple() ) {
			$columns[ self::COLUMN ] = $columns['parent'];
		}

		return $columns;
	}

	public function render( $column, $attachmentId ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$attachmentId = (int) $attachmentId;
		$posts        = $this->postsOfPage()[ $attachmentId ] ?? [];
		if ( [] === $posts ) {
			echo '(' . esc_html__( 'Unattached', 'wp-media-helper' ) . ')';

			return;
		}

		$items = [];
		foreach ( $posts as $postId ) {
			$items[] = [
				'title' => current_user_can( 'read_post', $postId ) ? (string) get_the_title( $postId ) : '',
				'url'   => current_user_can( 'edit_post', $postId ) ? (string) get_edit_post_link( $postId, 'raw' ) : '',
			];
		}

		echo wp_kses_post( self::format( $items ) );
	}

	/**
	 * The HTML of the list of posts: a title, linked to its edit screen when the user may edit it, one per line.
	 * A title the user may not read is not shown.
	 *
	 * @param array<int, array{title:string, url:string}> $items
	 */
	public static function format( array $items ): string {
		$lines = [];
		foreach ( $items as $item ) {
			$title = '' === $item['title'] ? __( '(no title)', 'wp-media-helper' ) : $item['title'];
			$lines[] = '' === $item['url']
				? '<span>' . esc_html( $title ) . '</span>'
				: '<a href="' . esc_url( $item['url'] ) . '">' . esc_html( $title ) . '</a>';
		}

		return implode( '<br />', $lines );
	}

	private static function isMultiple(): bool {
		$capabilities = Methods::active()->capabilities();

		return ! empty( $capabilities['multiple_posts'] );
	}

	/**
	 * The posts of every attachment on the page of the list, with one query.
	 *
	 * @return array<int, int[]>
	 */
	private function postsOfPage(): array {
		if ( null === $this->postsByAttachment ) {
			global $wp_query;

			$ids = [];
			foreach ( isset( $wp_query->posts ) && is_array( $wp_query->posts ) ? $wp_query->posts : [] as $post ) {
				if ( is_object( $post ) && isset( $post->ID ) ) {
					$ids[] = (int) $post->ID;
				}
			}

			$this->postsByAttachment = Methods::active()->posts_of( $ids );
			// The titles of all the posts of the page are read in one go.
			$postIds = array_merge( [], ...array_values( $this->postsByAttachment ) );
			if ( [] !== $postIds && function_exists( '_prime_post_caches' ) ) {
				_prime_post_caches( array_values( array_unique( $postIds ) ), false, false );
			}
		}

		return $this->postsByAttachment;
	}
}
