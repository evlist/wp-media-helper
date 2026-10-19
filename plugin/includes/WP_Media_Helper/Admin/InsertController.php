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
		add_action( 'wp_ajax_wp_media_helper_insert_plan', [ $this, 'handlePlan' ] );
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

		$assignments = null;
		if ( isset( $_POST['tracks'] ) ) {
			$decoded     = json_decode( (string) wp_unslash( $_POST['tracks'] ), true );
			$assignments = is_array( $decoded ) ? $decoded : [];
		}

		wp_send_json_success( $this->answer( absint( $_POST['post_id'] ?? 0 ), sanitize_text_field( wp_unslash( $_POST['source_id'] ?? '' ) ), $items, $assignments ) );
	}

	/**
	 * What the screen that validates the subtitles shows: which video each subtitle goes with, and in which language, as the names
	 * suggest. Nothing is registered or changed here.
	 */
	public function handlePlan(): void {
		if ( ! current_user_can( 'edit_posts' ) || ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}
		check_ajax_referer( 'wp_media_helper_media_panel', 'nonce' );

		$encoded = wp_unslash( $_POST['items'] ?? '' );
		$items   = is_string( $encoded ) ? json_decode( $encoded, true ) : [];
		$items   = is_array( $items ) ? EditorMediaController::normalizeBulkItems( $items ) : [];
		if ( [] === $items || count( $items ) > self::MAX_ITEMS ) {
			wp_send_json_error( [ 'message' => 'At least one media item must be selected.' ], 400 );
		}

		$videos    = [];
		$subtitles = [];
		foreach ( $items as $item ) {
			$file = [ 'key' => (string) $item['id'], 'name' => basename( $item['path'] ), 'dir' => dirname( $item['path'] ) ];
			$type = MediaPanelState::resolveMediaType( $file['name'] );
			if ( 'video' === $type ) {
				$videos[] = $file;
			} elseif ( 'subtitles' === $type && null === InsertBlocks::refusal( $type, $file['name'] ) ) {
				$subtitles[] = $file;
			}
		}

		wp_send_json_success( [
			'videos'    => $videos,
			'subtitles' => $subtitles,
			'proposal'  => SubtitleMatcher::propose( $videos, $subtitles ),
		] );
	}

	/**
	 * @param array<int, array{id:string, path:string, source_id?:string}> $items
	 * @param array<int, array<string, mixed>>|null                        $assignments What the screen of validation sent: `subtitle` and `video` (item ids), `srclang`, `label`.
	 *                                                                                  Null: the proposal of the names is used.
	 * @return array{blocks:array<int, array<string, mixed>>, results:array<int, array<string, mixed>>, orphan_tracks:array<int, array<string, string>>}
	 */
	private function answer( int $postId, string $sourceId, array $items, ?array $assignments = null ): array {
		$registry  = new AttachmentRegistry();
		$registrar = new AttachmentRegistrar( $registry );
		$sources   = ActiveSources::all();
		$results   = [];
		$images    = [];
		$videos    = [];
		$subtitles = [];
		// The blocks in the order of the first file of each kind: the photos are one gallery, each video is a block.
		$order     = [];

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
			$mediaType = MediaPanelState::resolveMediaType( basename( $path ) );
			$refusal   = InsertBlocks::refusal( $mediaType, basename( $path ) );
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

			$data = 'image' === $mediaType ? $this->imageData( (int) $attachmentId ) : $this->fileData( (int) $attachmentId );
			if ( null === $data ) {
				$result['message'] = __( 'The address of this file could not be found.', 'wp-media-helper' );
				$results[] = $result;
				continue;
			}
			$data['key']  = (string) $item['id'];
			$data['name'] = basename( $path );
			$data['dir']  = dirname( $path );
			if ( 'image' === $mediaType ) {
				if ( [] === $images ) {
					$order[] = [ 'gallery' ];
				}
				$images[] = $data;
			} elseif ( 'video' === $mediaType ) {
				$order[]  = [ 'video', $data['key'] ];
				$videos[ $data['key'] ] = $data;
			} else {
				$subtitles[ $data['key'] ] = $data;
			}
			$result['success']       = true;
			$result['attachment_id'] = (int) $attachmentId;
			$result['is_imported']   = true;
			$results[] = $result;
		}

		// Which subtitles go with which video: what the screen of validation sent, else what the names suggest.
		$tracks = [];
		$orphans = [];
		if ( [] !== $subtitles ) {
			if ( null === $assignments ) {
				$assignments = SubtitleMatcher::propose( array_values( $videos ), array_values( $subtitles ) );
			}
			$seen = [];
			foreach ( $assignments as $assignment ) {
				$subtitleKey = (string) ( $assignment['subtitle'] ?? '' );
				if ( ! isset( $subtitles[ $subtitleKey ] ) || isset( $seen[ $subtitleKey ] ) ) {
					continue;
				}
				$seen[ $subtitleKey ] = true;
				$track = [
					'url'     => (string) $subtitles[ $subtitleKey ]['url'],
					'srclang' => sanitize_text_field( (string) ( $assignment['srclang'] ?? '' ) ),
					'label'   => sanitize_text_field( (string) ( $assignment['label'] ?? '' ) ),
				];
				if ( '' === $track['label'] ) {
					$track['label'] = (string) pathinfo( $subtitles[ $subtitleKey ]['name'], PATHINFO_FILENAME );
				}
				$videoKey = isset( $assignment['video'] ) ? (string) $assignment['video'] : '';
				if ( '' !== $videoKey && isset( $videos[ $videoKey ] ) ) {
					$tracks[ $videoKey ][] = $track;
				} else {
					$orphans[] = InsertBlocks::track( $track );
				}
			}
			// A subtitle the screen did not mention is not lost: it follows the names.
			foreach ( $subtitles as $subtitleKey => $subtitle ) {
				if ( isset( $seen[ $subtitleKey ] ) ) {
					continue;
				}
				$proposed = SubtitleMatcher::propose( array_values( $videos ), [ $subtitle ] )[0];
				$track    = [ 'url' => (string) $subtitle['url'], 'srclang' => $proposed['srclang'], 'label' => '' !== $proposed['label'] ? $proposed['label'] : (string) pathinfo( $subtitle['name'], PATHINFO_FILENAME ) ];
				if ( null !== $proposed['video'] && isset( $videos[ $proposed['video'] ] ) ) {
					$tracks[ $proposed['video'] ][] = $track;
				} else {
					$orphans[] = InsertBlocks::track( $track );
				}
			}
		}

		$blocks = [];
		foreach ( $order as $entry ) {
			if ( 'gallery' === $entry[0] ) {
				/**
				 * The block that is inserted for a list of photos: by default a gallery of image blocks. A site can change it.
				 *
				 * @param array<string, mixed>              $block   `block`, `attributes`, `innerBlocks`.
				 * @param array<int, array<string, mixed>>  $images  `id`, `url` (the size shown), `full_url`, `alt`.
				 * @param int                               $postId  The post being edited.
				 */
				$blocks[] = apply_filters( 'wp_media_helper_insert_block', InsertBlocks::gallery( $images ), $images, $postId );
			} else {
				$blocks[] = InsertBlocks::video( $videos[ $entry[1] ], $tracks[ $entry[1] ] ?? [] );
			}
		}

		return [ 'blocks' => $blocks, 'results' => $results, 'orphan_tracks' => $orphans ];
	}

	/**
	 * The address of the file of an attachment (a video, a subtitle file).
	 *
	 * @return array{id:int, url:string}|null
	 */
	private function fileData( int $attachmentId ): ?array {
		$url = wp_get_attachment_url( $attachmentId );

		return is_string( $url ) && '' !== $url ? [ 'id' => $attachmentId, 'url' => $url ] : null;
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
