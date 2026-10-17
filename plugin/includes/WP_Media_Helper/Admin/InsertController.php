<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

use WP_Media_Helper\MediaSource\AttachmentRegistrar;
use WP_Media_Helper\MediaSource\AttachmentRegistry;
use WP_Media_Helper\MediaSource\PathConfinement;
use WP_Media_Helper\Settings\ActiveSources;

/**
 * The answer to "what do I insert for these files?" (slice 051). The browser sends paths and sources only: the server confines them to
 * the active sources, registers what has no attachment yet (the code of *Import*), reads the addresses and the alternative text itself,
 * and returns the blocks to insert. It never attaches: inserting in the content and attaching to the post are two things.
 */
final class InsertController {

	private const MAX_ITEMS = 100;

	public function __construct() {
		add_action( 'wp_ajax_wp_media_helper_insert_data', [ $this, 'handle' ] );
	}

	public function handle(): void {
		if ( ! current_user_can( 'edit_posts' ) || ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}
		check_ajax_referer( 'wp_media_helper_media_panel', 'nonce' );

		$encoded = wp_unslash( $_POST['items'] ?? '' );
		$items   = is_string( $encoded ) ? json_decode( $encoded, true ) : [];
		$items   = is_array( $items ) ? EditorMediaController::normalizeBulkItems( $items ) : [];
		if ( [] === $items ) {
			wp_send_json_error( [ 'message' => 'At least one media item must be selected.' ], 400 );
		}
		if ( count( $items ) > self::MAX_ITEMS ) {
			wp_send_json_error( [ 'message' => sprintf( /* translators: %d: maximum number of files. */ __( 'At most %d files can be inserted at once.', 'wp-media-helper' ), self::MAX_ITEMS ) ], 400 );
		}

		wp_send_json_success( $this->answer( absint( $_POST['post_id'] ?? 0 ), sanitize_text_field( wp_unslash( $_POST['source_id'] ?? '' ) ), $items ) );
	}

	/**
	 * @param array<int, array{id:string, path:string, source_id?:string}> $items
	 * @return array{blocks:array<int, array<string, mixed>>, results:array<int, array<string, mixed>>}
	 */
	private function answer( int $postId, string $sourceId, array $items ): array {
		$registry  = new AttachmentRegistry();
		$registrar = new AttachmentRegistrar( $registry );
		$sources   = ActiveSources::all();
		$results   = [];
		$images    = [];

		foreach ( $items as $item ) {
			$result = [ 'id' => $item['id'], 'path' => $item['path'], 'success' => false ];
			$itemSourceId = (string) ( $item['source_id'] ?? $sourceId );

			$confined = PathConfinement::resolveFileInSources( $sources, $itemSourceId, $item['path'] );
			if ( null === $confined ) {
				$result['message'] = __( 'The selected media file is not readable.', 'wp-media-helper' );
				$results[] = $result;
				continue;
			}
			$path = $confined['path'];
			if ( TrashedFiles::isTrashed( $path ) ) {
				$result['message'] = __( 'This file is in the trash. Restore it first.', 'wp-media-helper' );
				$results[] = $result;
				continue;
			}
			$refusal = InsertBlocks::refusal( MediaPanelState::resolveMediaType( basename( $path ) ), basename( $path ) );
			if ( null !== $refusal ) {
				$result['message'] = $refusal;
				$results[] = $result;
				continue;
			}

			// Reuse the attachment of this file, whichever tool created it; register it otherwise (the files are never copied).
			$existing = $registry->findByPath( $path );
			$attachmentId = [] !== $existing ? (int) $existing[0]['id'] : $registrar->register( (string) $confined['source']['id'], $path, $confined['source'] );
			if ( is_wp_error( $attachmentId ) ) {
				$result['message'] = $attachmentId->get_error_message();
				$results[] = $result;
				continue;
			}

			$image = $this->imageData( (int) $attachmentId );
			if ( null === $image ) {
				$result['message'] = __( 'The address of this file could not be found.', 'wp-media-helper' );
				$results[] = $result;
				continue;
			}
			$images[] = $image;
			$result['success']       = true;
			$result['attachment_id'] = (int) $attachmentId;
			$result['is_imported']   = true;
			$results[] = $result;
		}

		$blocks = [];
		if ( [] !== $images ) {
			/**
			 * The block that is inserted for a list of photos: by default a gallery of image blocks. A site can change it.
			 *
			 * @param array<string, mixed>              $block   `block`, `attributes`, `innerBlocks`.
			 * @param array<int, array<string, mixed>>  $images  `id`, `url` (the size shown), `full_url`, `alt`.
			 * @param int                               $postId  The post being edited.
			 */
			$blocks[] = apply_filters( 'wp_media_helper_insert_block', InsertBlocks::gallery( $images ), $images, $postId );
		}

		return [ 'blocks' => $blocks, 'results' => $results ];
	}

	/**
	 * The addresses of an attachment: the size shown (made in the cache when needed) and the file.
	 *
	 * @return array{id:int, url:string, full_url:string, alt:string, width:int, height:int}|null
	 */
	private function imageData( int $attachmentId ): ?array {
		$full = wp_get_attachment_url( $attachmentId );
		if ( ! is_string( $full ) || '' === $full ) {
			return null;
		}
		$shown = image_downsize( $attachmentId, InsertBlocks::SIZE );

		return [
			'id'       => $attachmentId,
			'url'      => is_array( $shown ) && ! empty( $shown[0] ) ? (string) $shown[0] : $full,
			'full_url' => $full,
			'alt'      => (string) get_post_meta( $attachmentId, '_wp_attachment_image_alt', true ),
			'width'    => is_array( $shown ) ? (int) ( $shown[1] ?? 0 ) : 0,
			'height'   => is_array( $shown ) ? (int) ( $shown[2] ?? 0 ) : 0,
		];
	}
}
