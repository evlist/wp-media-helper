<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

use DateTimeImmutable;
use WP_Media_Helper\Settings\ExternalSourceSettings;

class EditorMediaController {

	public const panelModeMetaKey = '_wp_media_helper_panel_mode';

	public static function normalizePanelMode( mixed $mode ): string {
		return 'advanced' === $mode ? 'advanced' : 'simple';
	}

	/**
	 * @param array<int, array<string, mixed>> $configuredSources
	 * @param string $requestedSourceId
	 * @return array<int, array<string, mixed>>
	 */
	public static function resolveRequestedSources( array $configuredSources, string $requestedSourceId ): array {
		if ( '' === $requestedSourceId ) {
			return $configuredSources;
		}

		$matches = [];
		foreach ( $configuredSources as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}

			if ( (string) ( $source['id'] ?? '' ) === $requestedSourceId || (string) ( $source['name'] ?? '' ) === $requestedSourceId ) {
				$matches[] = $source;
			}
		}

		return $matches;
	}

	/**
	 * @param array<int, mixed> $items
	 * @return array<int, array{id:string, path:string}>
	 */
	public static function normalizeBulkItems( array $items ): array {
		$normalized = [];
		foreach ( $items as $item ) {
			if ( is_string( $item ) ) {
				$item = [ 'path' => $item ];
			}
			if ( ! is_array( $item ) ) {
				continue;
			}

			$path = trim( (string) ( $item['path'] ?? '' ) );
			if ( '' === $path ) {
				continue;
			}

			$id = trim( (string) ( $item['id'] ?? $path ) );
			$key = $id . "\0" . $path;
			if ( isset( $normalized[ $key ] ) ) {
				continue;
			}

			$normalized[ $key ] = [
				'id' => $id,
				'path' => $path,
			];
		}

		return array_values( $normalized );
	}

	/**
	 * Normalizes an attachment_scope filter value to a non-empty set of
	 * `unattached`, `current`, and `other` states.
	 *
	 * @return array<int, string>
	 */
	public static function normalizeAttachmentScope( mixed $rawScope ): array {
		$allowed = [ 'unattached', 'current', 'other' ];
		$values = is_array( $rawScope ) ? array_map( 'strval', $rawScope ) : [];
		$normalized = array_values( array_intersect( $allowed, $values ) );

		return [] === $normalized ? [ 'unattached', 'current' ] : $normalized;
	}

	/**
	 * Normalizes the structured `filters` request payload, falling back to the
	 * legacy top-level `date`/`source_id` parameters during migration.
	 *
	 * The attachment_scope value is taken from the payload when present;
	 * otherwise `$resolveStoredAttachmentScope` is called to look up the
	 * user's persisted preference.
	 *
	 * @return array{date:string, source:string, attachment_scope:array<int, string>}
	 */
	public static function normalizeFilters( mixed $rawFilters, string $legacyDate, string $legacySource, ?callable $resolveStoredAttachmentScope = null ): array {
		$decoded = is_string( $rawFilters ) ? json_decode( $rawFilters, true ) : null;
		$decoded = is_array( $decoded ) ? $decoded : [];

		$date = isset( $decoded['date'] ) ? trim( (string) $decoded['date'] ) : '';
		$date = '' !== $date ? $date : $legacyDate;

		$source = isset( $decoded['source'] ) ? trim( (string) $decoded['source'] ) : $legacySource;

		if ( isset( $decoded['attachment_scope'] ) ) {
			$attachmentScope = self::normalizeAttachmentScope( $decoded['attachment_scope'] );
		} else {
			$stored = null === $resolveStoredAttachmentScope ? null : $resolveStoredAttachmentScope();
			$attachmentScope = self::normalizeAttachmentScope( $stored );
		}

		return [
			'date' => $date,
			'source' => $source,
			'attachment_scope' => $attachmentScope,
		];
	}

	public function __construct() {
		add_action( 'wp_ajax_wp_media_helper_media_panel_state', [ $this, 'handle' ] );
		add_action( 'wp_ajax_wp_media_helper_bulk_media', [ $this, 'handleBulk' ] );
		add_action( 'wp_ajax_wp_media_helper_panel_mode', [ $this, 'handlePanelMode' ] );
		add_action( 'wp_ajax_wp_media_helper_save_filter', [ $this, 'handleSaveFilter' ] );
	}

	private function makeMediaFilters(): MediaFilters {
		return new MediaFilters(
			static fn ( string $key ) => get_user_meta( get_current_user_id(), $key, true ),
			static function ( string $key, $value ): void {
				update_user_meta( get_current_user_id(), $key, $value );
			}
		);
	}

	public function handleSaveFilter(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		check_ajax_referer( 'wp_media_helper_media_panel', 'nonce' );

		$key = sanitize_key( wp_unslash( $_POST['key'] ?? '' ) );
		$postId = absint( $_POST['post_id'] ?? 0 );
		$rawValue = wp_unslash( $_POST['value'] ?? '' );
		$decodedValue = is_string( $rawValue ) ? json_decode( $rawValue, true ) : null;

		if ( 'attachment_scope' !== $key ) {
			wp_send_json_error( [ 'message' => 'The requested filter is not supported.' ], 400 );
		}

		$value = self::normalizeAttachmentScope( $decodedValue );
		$this->makeMediaFilters()->persistUserPostThenUser( $postId, $key, $value );
		wp_send_json_success( [ 'key' => $key, 'value' => $value ] );
	}

	public function handlePanelMode(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		check_ajax_referer( 'wp_media_helper_media_panel', 'nonce' );

		$mode = self::normalizePanelMode( sanitize_key( wp_unslash( $_POST['mode'] ?? '' ) ) );
		update_user_meta( get_current_user_id(), self::panelModeMetaKey, $mode );
		wp_send_json_success( [ 'mode' => $mode ] );
	}

	public function handleBulk(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		check_ajax_referer( 'wp_media_helper_media_panel', 'nonce' );

		$sourceId = sanitize_text_field( wp_unslash( $_POST['source_id'] ?? '' ) );
		$action = sanitize_key( wp_unslash( $_POST['bulk_action'] ?? '' ) );
		$postId = absint( $_POST['post_id'] ?? 0 );
		$encodedItems = wp_unslash( $_POST['items'] ?? '' );
		$items = is_string( $encodedItems ) ? json_decode( $encodedItems, true ) : [];

		if ( ! in_array( $action, [ 'import', 'remove', 'attach', 'detach' ], true ) ) {
			wp_send_json_error( [ 'message' => 'The requested bulk action is not supported.' ], 400 );
		}

		if ( in_array( $action, [ 'attach', 'detach', 'remove' ], true ) && ( 0 === $postId || ! get_post( $postId ) || ! current_user_can( 'edit_post', $postId ) ) ) {
			wp_send_json_error( [ 'message' => 'The current post must be saved before media can be changed.' ], 400 );
		}

		if ( ! is_array( $items ) ) {
			wp_send_json_error( [ 'message' => 'The selected media items are invalid.' ], 400 );
		}

		$items = self::normalizeBulkItems( $items );
		if ( [] === $items ) {
			wp_send_json_error( [ 'message' => 'At least one media item must be selected.' ], 400 );
		}

		wp_send_json_success( [
			'action' => $action,
			'results' => $this->processBulkItems( $sourceId, $action, $items, $postId ),
		] );
	}

	/**
	 * Creates a WordPress attachment entry without copying the original file into uploads.
	 *
	 * @return int|\WP_Error
	 */
	public function registerVirtualAttachment( string $sourceId, string $path ) {
		$basename = basename( $path );
		$filetype = wp_check_filetype( $basename, null );
		$mimeType = is_array( $filetype ) && ! empty( $filetype['type'] ) ? $filetype['type'] : 'application/octet-stream';

		$attachmentId = wp_insert_post( [
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'post_title' => $basename,
			'post_name' => sanitize_title( $basename ),
			'post_mime_type' => $mimeType,
			'guid' => $path,
			'post_content' => '',
			'post_parent' => 0,
		], true );

		if ( is_wp_error( $attachmentId ) ) {
			return $attachmentId;
		}

		if ( empty( $attachmentId ) ) {
			return new \WP_Error( 'insert_attachment_failed', 'Unable to register the media attachment.' );
		}

		update_post_meta( (int) $attachmentId, '_wp_media_helper_source_id', $sourceId );
		update_post_meta( (int) $attachmentId, '_wp_media_helper_source_path', $path );
		update_post_meta( (int) $attachmentId, '_wp_attached_file', $path );

		return (int) $attachmentId;
	}

	/**
	 * @param array<int, array{id:string, path:string}> $items
	 * @return array<int, array<string, mixed>>
	 */
	private function processBulkItems( string $sourceId, string $action, array $items, int $postId = 0 ): array {
		$results = [];
		foreach ( $items as $item ) {
			$result = [
				'id' => $item['id'],
				'path' => $item['path'],
				'success' => false,
				'is_imported' => false,
				'is_attached_to_current_post' => false,
				'operation' => 'no_change',
			];

			if ( in_array( $action, [ 'attach', 'detach', 'remove' ], true ) ) {
				$otherPostId = $this->findOtherPostAttachment( $sourceId, $item['path'], $postId );
				if ( 0 !== $otherPostId ) {
					$result['message'] = 'This media is attached to another post.';
					$result['operation'] = 'protected_other_post';
					$results[] = $result;
					continue;
				}
			}

			if ( in_array( $action, [ 'import', 'attach' ], true ) ) {
				if ( ! is_file( $item['path'] ) ) {
					$result['message'] = 'The selected media file is not readable.';
					$results[] = $result;
					continue;
				}

				$existing = $this->findVirtualAttachments( $sourceId, $item['path'] );
				$attachmentId = $existing[0] ?? $this->registerVirtualAttachment( $sourceId, $item['path'] );
				if ( is_wp_error( $attachmentId ) ) {
					$result['message'] = $attachmentId->get_error_message();
					$results[] = $result;
					continue;
				}

				$result['success'] = true;
				$result['is_imported'] = true;
				$result['attachment_id'] = (int) $attachmentId;
				$result['operation'] = 'imported';

				if ( 'attach' === $action ) {
					$updated = wp_update_post( [
						'ID' => (int) $attachmentId,
						'post_parent' => $postId,
					], true );
					if ( is_wp_error( $updated ) || empty( $updated ) ) {
						$result['success'] = false;
						$result['message'] = is_wp_error( $updated ) ? $updated->get_error_message() : 'Unable to attach the media to the current post.';
						$results[] = $result;
						continue;
					}

					$result['is_attached_to_current_post'] = true;
					$result['operation'] = 'attached';
				}

				$results[] = $result;
				continue;
			}

			if ( 'detach' === $action ) {
				$detached = $this->detachVirtualAttachments( $sourceId, $item['path'], $postId );
				if ( is_wp_error( $detached ) ) {
					$result['message'] = $detached->get_error_message();
					$results[] = $result;
					continue;
				}

				$result['success'] = true;
				$result['is_imported'] = [] !== $this->findVirtualAttachments( $sourceId, $item['path'] );
				$result['detached_ids'] = $detached;
				$result['operation'] = [] === $detached ? 'no_change' : 'detached';
				$results[] = $result;
				continue;
			}

			$detached = $this->detachVirtualAttachments( $sourceId, $item['path'], $postId );
			if ( is_wp_error( $detached ) ) {
				$result['message'] = $detached->get_error_message();
				$results[] = $result;
				continue;
			}

			$removed = $this->removeVirtualAttachment( $sourceId, $item['path'] );
			if ( is_wp_error( $removed ) ) {
				$result['message'] = $removed->get_error_message();
				$results[] = $result;
				continue;
			}

			$result['success'] = true;
			$result['is_imported'] = [] !== $this->findVirtualAttachments( $sourceId, $item['path'] );
			$result['detached_ids'] = $detached;
			$result['removed_ids'] = array_values( array_unique( array_map( 'intval', $removed ) ) );
			$result['operation'] = [] !== $detached ? 'detached_and_removed' : ( [] === $removed ? 'no_change' : 'removed_from_library' );
			$results[] = $result;
		}

		return $results;
	}

	/**
	 * Removes the WordPress-side attachment record without deleting the original source file.
	 *
	 * @return int[]|\WP_Error
	 */
	public function removeVirtualAttachment( string $sourceId, string $path ) {
		$matches = $this->findVirtualAttachments( $sourceId, $path );
		if ( [] === $matches ) {
			return [];
		}

		$removed = [];
		foreach ( $matches as $attachmentId ) {
			if ( 0 !== (int) get_post_field( 'post_parent', $attachmentId ) ) {
				continue;
			}

			delete_post_meta( (int) $attachmentId, '_wp_attached_file' );
			wp_delete_post( (int) $attachmentId, true );
			$removed[] = $attachmentId;
		}

		return $removed;
	}

	/**
	 * Removes the association between matching attachments and the current post.
	 *
	 * @return int[]|\WP_Error
	 */
	public function detachVirtualAttachments( string $sourceId, string $path, int $postId ) {
		$detached = [];
		foreach ( $this->findVirtualAttachments( $sourceId, $path ) as $attachmentId ) {
			if ( $postId !== (int) get_post_field( 'post_parent', $attachmentId ) ) {
				continue;
			}

			$updated = wp_update_post( [
				'ID' => $attachmentId,
				'post_parent' => 0,
			], true );
			if ( is_wp_error( $updated ) || empty( $updated ) ) {
				return is_wp_error( $updated ) ? $updated : new \WP_Error( 'detach_attachment_failed', 'Unable to detach the media from the current post.' );
			}

			$detached[] = $attachmentId;
		}

		return $detached;
	}

	/**
	 * Returns the ID of the post a matching attachment is protected by, or 0
	 * when no matching attachment is attached to a post other than $postId.
	 */
	private function findOtherPostAttachment( string $sourceId, string $path, int $postId ): int {
		foreach ( $this->findVirtualAttachments( $sourceId, $path ) as $attachmentId ) {
			$parent = (int) get_post_field( 'post_parent', $attachmentId );
			if ( 0 !== $parent && $parent !== $postId ) {
				return $parent;
			}
		}

		return 0;
	}

	/**
	 * @return int[]
	 */
	private function findVirtualAttachments( string $sourceId, string $path ): array {
		$attachments = get_posts( [
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'posts_per_page' => -1,
			'fields' => 'ids',
		] );

		if ( empty( $attachments ) || is_wp_error( $attachments ) ) {
			return [];
		}

		$matches = [];
		foreach ( $attachments as $attachmentId ) {
			$attachmentId = (int) $attachmentId;
			$attachedFile = get_attached_file( $attachmentId, true );
			$sourcePath = get_post_meta( $attachmentId, '_wp_media_helper_source_path', true );
			$metaSourceId = get_post_meta( $attachmentId, '_wp_media_helper_source_id', true );

			if ( ! is_string( $sourcePath ) || '' === $sourcePath ) {
				continue;
			}

			if ( '' !== $sourceId && (string) $metaSourceId !== $sourceId ) {
				continue;
			}

			$checks = [];
			if ( is_string( $attachedFile ) && '' !== $attachedFile ) {
				$checks[] = $attachedFile;
			}
			if ( is_string( $sourcePath ) && '' !== $sourcePath ) {
				$checks[] = $sourcePath;
			}

			foreach ( $checks as $candidatePath ) {
				if ( MediaPanelState::pathMatches( $path, $candidatePath ) ) {
					$matches[] = $attachmentId;
					break;
				}
			}
		}

		return array_values( array_unique( $matches ) );
	}

	public function handle(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		check_ajax_referer( 'wp_media_helper_media_panel', 'nonce' );

		$sourceId = sanitize_text_field( wp_unslash( $_POST['source_id'] ?? '' ) );
		$postId = absint( $_POST['post_id'] ?? 0 );
		$legacyDate = sanitize_text_field( wp_unslash( $_POST['date'] ?? current_time( 'Y-m-d' ) ) );
		$rawFilters = wp_unslash( $_POST['filters'] ?? '' );
		$filters = self::normalizeFilters(
			$rawFilters,
			$legacyDate,
			$sourceId,
			fn () => $this->makeMediaFilters()->resolveUserPostThenUser( $postId, 'attachment_scope', null )
		);
		$dateValue = $filters['date'];
		$sourceId = $filters['source'];
		$forceRefresh = ! empty( $_POST['force_refresh'] );

		$sources = new ExternalSourceSettings(
			static fn(): mixed => get_option( ExternalSourceSettings::optionKey(), [] ),
			static function ( array $value ): void {}
		);
		$allSources = $sources->getAll();
		$selectedSources = self::resolveRequestedSources( $allSources, $sourceId );

		if ( [] === $selectedSources ) {
			wp_send_json_success( [
				'source_id' => '',
				'date' => $dateValue,
				'date_range' => null,
				'status' => 'fresh',
				'refresh_required' => false,
				'stale' => false,
				'reason' => null,
				'files' => [],
				'directory' => '',
				'filters' => $filters,
			] );
		}

		$date = new DateTimeImmutable( $dateValue );
		$panelState = new MediaPanelState();
		$results = [];
		foreach ( $selectedSources as $selected ) {
			$sourceKey = (string) ( $selected['id'] ?? $sourceId );
			$results[] = $forceRefresh
				? $panelState->requestRefresh( $selected, $date, $sourceKey )
				: $panelState->resolve( $selected, $date, $sourceKey );
		}

		$merged = [
			'source_id' => $sourceId !== '' ? $sourceId : 'all',
			'date' => $dateValue,
			'date_range' => null,
			'status' => 'fresh',
			'refresh_required' => false,
			'stale' => false,
			'reason' => null,
			'files' => [],
			'directory' => '',
		];

		foreach ( $results as $result ) {
			$merged['refresh_required'] = $merged['refresh_required'] || $result['refresh_required'];
			$merged['stale'] = $merged['stale'] || $result['stale'];
			if ( null === $merged['reason'] && null !== $result['reason'] ) {
				$merged['reason'] = $result['reason'];
			}
			$merged['files'] = MediaPanelState::mergeFiles( $merged['files'], $result['files'] );
			if ( '' === $merged['directory'] ) {
				$merged['directory'] = $result['directory'];
			}
		}

		$importedPaths = [];
		foreach ( $merged['files'] as $file ) {
			$path = is_array( $file ) ? (string) ( $file['path'] ?? $file['name'] ?? '' ) : (string) $file;
			if ( '' !== $path ) {
				$importedPaths[] = $path;
			}
		}
		$attachmentStates = $this->resolveAttachmentStates( $importedPaths, $postId );
		$merged['files'] = MediaPanelState::setImportState( $merged['files'], $attachmentStates['imported_paths'] );
		$merged['files'] = MediaPanelState::setAttachmentState( $merged['files'], $attachmentStates['attached_paths'] );
		$merged['files'] = MediaPanelState::setOtherPostState( $merged['files'], $attachmentStates['other_post_by_path'] );
		$merged['files'] = MediaPanelState::filterByAttachmentScope( $merged['files'], $filters['attachment_scope'] );
		$merged['files'] = $this->enrichOtherPostInfo( $merged['files'] );
		$merged['status'] = $merged['refresh_required'] ? 'stale' : 'fresh';
		$merged['filters'] = $filters;
		wp_send_json_success( $merged );
	}

	/**
	 * Resolves parent post title and edit URL only for items the current user
	 * is allowed to see, and only after scope filtering has narrowed the list.
	 *
	 * @param array<int, array<string, mixed>> $files
	 * @return array<int, array<string, mixed>>
	 */
	private function enrichOtherPostInfo( array $files ): array {
		foreach ( $files as $index => $file ) {
			if ( empty( $file['is_attached_to_other_post'] ) ) {
				continue;
			}

			$otherPostId = (int) ( $file['other_post_id'] ?? 0 );
			if ( 0 === $otherPostId || ! current_user_can( 'edit_post', $otherPostId ) ) {
				continue;
			}

			$post = get_post( $otherPostId );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$files[ $index ]['other_post_title'] = get_the_title( $post );
			$files[ $index ]['other_post_edit_url'] = get_edit_post_link( $otherPostId, 'raw' );
		}

		return $files;
	}

	/**
	 * @param string[] $candidatePaths
	 * @return array{imported_paths:string[], attached_paths:string[], other_post_by_path:array<string, array{post_id:int}>}
	 */
	private function resolveAttachmentStates( array $candidatePaths, int $postId ): array {
		$known = [];
		foreach ( $candidatePaths as $path ) {
			if ( ! is_string( $path ) || '' === trim( $path ) ) {
				continue;
			}
			foreach ( MediaPanelState::pathSignatureCandidates( $path ) as $candidate ) {
				$known[ $candidate ] = true;
			}
		}

		if ( [] === $known ) {
			return [ 'imported_paths' => [], 'attached_paths' => [], 'other_post_by_path' => [] ];
		}

		$attachments = get_posts( [
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'posts_per_page' => -1,
			'fields' => 'ids',
		] );
		if ( empty( $attachments ) || is_wp_error( $attachments ) ) {
			return [ 'imported_paths' => [], 'attached_paths' => [], 'other_post_by_path' => [] ];
		}

		$imported = [];
		$attached = [];
		$otherPostByPath = [];
		foreach ( $attachments as $attachmentId ) {
			$attachmentId = (int) $attachmentId;
			$path = get_attached_file( $attachmentId, true );
			$sourcePath = get_post_meta( $attachmentId, '_wp_media_helper_source_path', true );
			if ( ! is_string( $sourcePath ) || '' === $sourcePath ) {
				continue;
			}

			$checks = [];
			if ( is_string( $path ) && '' !== $path ) {
				$checks[] = $path;
			}
			if ( is_string( $sourcePath ) && '' !== $sourcePath ) {
				$checks[] = $sourcePath;
			}

			$matches = false;
			foreach ( $checks as $candidatePath ) {
				foreach ( MediaPanelState::pathSignatureCandidates( $candidatePath ) as $candidate ) {
					if ( isset( $known[ $candidate ] ) ) {
						$matches = true;
						break;
					}
				}
				if ( $matches ) {
					break;
				}
			}

			if ( ! $matches ) {
				continue;
			}

			$imported[] = $sourcePath;
			$parent = (int) get_post_field( 'post_parent', $attachmentId );
			if ( 0 !== $postId && $postId === $parent ) {
				$attached[] = $sourcePath;
			} elseif ( 0 !== $parent ) {
				$otherPostByPath[ $sourcePath ] = [ 'post_id' => $parent ];
			}
		}

		return [
			'imported_paths' => array_values( array_unique( $imported ) ),
			'attached_paths' => array_values( array_unique( $attached ) ),
			'other_post_by_path' => $otherPostByPath,
		];
	}
}
