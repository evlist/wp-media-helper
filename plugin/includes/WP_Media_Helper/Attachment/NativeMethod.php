<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Attachment;

/**
 * The WordPress way: an attachment belongs to one post, its `post_parent`.
 */
final class NativeMethod implements Method {

	public const ID = 'native';

	private const CHUNK_SIZE = 400;

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'WordPress (one post per media item)', 'wp-media-helper' );
	}

	public function capabilities(): array {
		return [ 'multiple_posts' => false, 'fields' => [] ];
	}

	public function describe( array $attachmentIds, int $postId ): array {
		$described = [];
		foreach ( $this->parents( $attachmentIds ) as $id => $parent ) {
			$here      = 0 !== $postId && $parent === $postId;
			$elsewhere = 0 !== $parent && ! $here ? [ $parent ] : [];

			$described[ $id ] = [
				'attached_here' => $here,
				'elsewhere'     => $elsewhere,
				'protected'     => [] === $elsewhere ? null : __( 'This media is attached to another post.', 'wp-media-helper' ),
				'data'          => [],
			];
		}

		return $described;
	}

	public function attach( int $attachmentId, int $postId, array $data ) {
		if ( ! current_user_can( 'edit_post', $attachmentId ) ) {
			return new \WP_Error( 'not_allowed', __( 'You are not allowed to attach this media.', 'wp-media-helper' ) );
		}

		$updated = wp_update_post( [ 'ID' => $attachmentId, 'post_parent' => $postId ], true );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return empty( $updated ) ? new \WP_Error( 'attach_failed', __( 'Unable to attach the media to the current post.', 'wp-media-helper' ) ) : true;
	}

	public function detach( int $attachmentId, int $postId ) {
		if ( $postId !== (int) get_post_field( 'post_parent', $attachmentId ) ) {
			return true;
		}
		if ( ! current_user_can( 'edit_post', $attachmentId ) ) {
			return new \WP_Error( 'not_allowed', __( 'You are not allowed to change this media.', 'wp-media-helper' ) );
		}

		$updated = wp_update_post( [ 'ID' => $attachmentId, 'post_parent' => 0 ], true );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return empty( $updated ) ? new \WP_Error( 'detach_failed', __( 'Unable to detach the media from the current post.', 'wp-media-helper' ) ) : true;
	}

	public function update( int $attachmentId, int $postId, array $data ) {
		// No data to change: the only link there is is the parent.
		return true;
	}

	public function attached( int $postId, array $args ): array {
		$mimeType = isset( $args['mime_type'] ) && is_string( $args['mime_type'] ) ? $args['mime_type'] : '';

		return array_map( 'intval', array_keys( get_attached_media( $mimeType, $postId ) ) );
	}

	public function posts_of( array $attachmentIds ): array {
		$posts = [];
		foreach ( $this->parents( $attachmentIds ) as $id => $parent ) {
			$posts[ $id ] = 0 === $parent ? [] : [ $parent ];
		}

		return $posts;
	}

	public function may_remove( int $attachmentId, string $context = 'remove' ) {
		// The trash asked the user first and removes the entries from every post.
		if ( 'trash' !== $context && 0 !== (int) get_post_field( 'post_parent', $attachmentId ) ) {
			return new \WP_Error( 'attached', __( 'This media is attached to a post.', 'wp-media-helper' ) );
		}

		return true;
	}

	/**
	 * The `post_parent` of each attachment asked for (0 when it has none, or does not exist), with a query per lot.
	 *
	 * @param int[] $attachmentIds
	 * @return array<int, int>
	 */
	private function parents( array $attachmentIds ): array {
		global $wpdb;

		$ids     = array_values( array_unique( array_filter( array_map( 'intval', $attachmentIds ), static fn ( int $id ): bool => $id > 0 ) ) );
		$parents = array_fill_keys( $ids, 0 );
		foreach ( array_chunk( $ids, self::CHUNK_SIZE ) as $lot ) {
			$placeholders = implode( ', ', array_fill( 0, count( $lot ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$query = $wpdb->prepare( "SELECT ID, post_parent FROM {$wpdb->posts} WHERE ID IN ( {$placeholders} )", $lot );
			$rows  = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( is_array( $rows ) ? $rows : [] as $row ) {
				$parents[ (int) $row['ID'] ] = (int) $row['post_parent'];
			}
		}

		return $parents;
	}
}
