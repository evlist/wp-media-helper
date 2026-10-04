<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

use WP_Media_Helper\Thumbnails\PanelThumbnail;
use WP_Media_Helper\Thumbnails\Thumbnails;
use DateTimeImmutable;
use WP_Media_Helper\MediaSource\AttachmentRegistrar;
use WP_Media_Helper\MediaSource\AttachmentRegistry;
use WP_Media_Helper\MediaSource\PathConfinement;
use WP_Media_Helper\Settings\ActiveSources;
use WP_Media_Helper\Settings\GeneralSettings;

class EditorMediaController {

	public const panelModeMetaKey = '_wp_media_helper_panel_mode';
	public const MAX_FILENAME_QUERY_LENGTH = 255;

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
	 * @return array<int, array{id:string, path:string, source_id?:string}>
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
			$sourceId = trim( (string) ( $item['source_id'] ?? '' ) );
			if ( '' !== $sourceId ) {
				$normalized[ $key ]['source_id'] = $sourceId;
			}
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
	 * Normalizes the set of broad media categories shown by the panel.
	 *
	 * @return array<int, string>
	 */
	public static function normalizeMediaTypeFilter( mixed $rawTypes ): array {
		$allowed = [ 'image', 'video', 'other' ];
		$values = is_array( $rawTypes ) ? array_map( 'strval', $rawTypes ) : [];
		$normalized = array_values( array_intersect( $allowed, $values ) );

		return [] === $normalized ? $allowed : $normalized;
	}

	public static function normalizeFilenameFilter( mixed $rawFilename ): string {
		if ( ! is_string( $rawFilename ) || strlen( $rawFilename ) > 4 * self::MAX_FILENAME_QUERY_LENGTH || 1 !== preg_match( '//u', $rawFilename ) ) {
			return '';
		}

		if ( 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $rawFilename ) ) {
			return '';
		}

		$characters = [];
		if ( false === preg_match_all( '/./us', $rawFilename, $characters ) || count( $characters[0] ) > self::MAX_FILENAME_QUERY_LENGTH ) {
			return '';
		}

		$normalized = preg_replace( '/\s+/u', ' ', trim( $rawFilename ) );
		if ( ! is_string( $normalized ) ) {
			return '';
		}

		$normalized = preg_replace( '/^[._\/\\\\-]+|[._\/\\\\-]+$/u', '', $normalized );

		return is_string( $normalized ) ? trim( $normalized ) : '';
	}

	/**
	 * Normalizes a source filter against the IDs of active configured sources.
	 * A single active source is implicit; multiple sources default to `all`.
	 *
	 * @param array<int, string> $activeSourceIds
	 * @return array<int, string>
	 */
	public static function normalizeSourceFilter( mixed $rawSource, array $activeSourceIds ): array {
		$activeSourceIds = array_values( array_unique( array_filter( $activeSourceIds, 'is_string' ) ) );
		if ( [] === $activeSourceIds ) {
			return [];
		}

		if ( 1 === count( $activeSourceIds ) ) {
			return $activeSourceIds;
		}

		if ( 'all' === $rawSource || null === $rawSource ) {
			return [ 'all' ];
		}

		$requested = is_array( $rawSource ) ? array_map( 'strval', $rawSource ) : [ (string) $rawSource ];
		if ( in_array( 'all', $requested, true ) ) {
			return [ 'all' ];
		}

		$selected = array_values( array_intersect( $activeSourceIds, $requested ) );

		return [] === $selected ? [ 'all' ] : $selected;
	}

	/**
	 * @param array<int, array<string, mixed>> $activeSources
	 * @param array<int, string>               $sourceFilter
	 * @return array<int, array<string, mixed>>
	 */
	public static function resolveSourcesForFilter( array $activeSources, array $sourceFilter ): array {
		if ( [] === $sourceFilter || in_array( 'all', $sourceFilter, true ) ) {
			return $activeSources;
		}

		$selectedIds = array_fill_keys( $sourceFilter, true );

		return array_values( array_filter( $activeSources, static function ( $source ) use ( $selectedIds ): bool {
			return is_array( $source ) && isset( $selectedIds[ (string) ( $source['id'] ?? '' ) ] );
		} ) );
	}

	/**
	 * Normalizes the structured `filters` request payload, falling back to the
	 * legacy top-level `date`/`source_id` parameters during migration.
	 *
	 * The attachment_scope value is taken from the payload when present;
	 * otherwise `$resolveStoredAttachmentScope` is called to look up the
	 * user's persisted preference.
	 *
	 * @return array{date:string, source:array<int, string>, attachment_scope:array<int, string>, media_type:array<int, string>, filename:string}
	 */
	public static function normalizeFilters( mixed $rawFilters, string $legacyDate, mixed $legacySource, array $activeSourceIds = [], ?callable $resolveStoredAttachmentScope = null, ?callable $resolveStoredSource = null, ?callable $resolveStoredMediaType = null, ?callable $resolveStoredFilename = null ): array {
		$decoded = is_string( $rawFilters ) ? json_decode( $rawFilters, true ) : null;
		$decoded = is_array( $decoded ) ? $decoded : [];

		$date = isset( $decoded['date'] ) ? trim( (string) $decoded['date'] ) : '';
		$date = '' !== $date ? $date : $legacyDate;

		if ( array_key_exists( 'source', $decoded ) ) {
			$rawSource = $decoded['source'];
		} elseif ( '' !== (string) $legacySource ) {
			$rawSource = $legacySource;
		} else {
			$rawSource = null === $resolveStoredSource ? null : $resolveStoredSource();
		}
		$source = self::normalizeSourceFilter( $rawSource, $activeSourceIds );

		if ( isset( $decoded['attachment_scope'] ) ) {
			$attachmentScope = self::normalizeAttachmentScope( $decoded['attachment_scope'] );
		} else {
			$stored = null === $resolveStoredAttachmentScope ? null : $resolveStoredAttachmentScope();
			$attachmentScope = self::normalizeAttachmentScope( $stored );
		}

		if ( isset( $decoded['media_type'] ) ) {
			$mediaType = self::normalizeMediaTypeFilter( $decoded['media_type'] );
		} else {
			$stored = null === $resolveStoredMediaType ? null : $resolveStoredMediaType();
			$mediaType = self::normalizeMediaTypeFilter( $stored );
		}

		$rawFilename = array_key_exists( 'filename', $decoded )
			? $decoded['filename']
			: ( null === $resolveStoredFilename ? '' : $resolveStoredFilename() );
		$filename = self::normalizeFilenameFilter( $rawFilename );

		return [
			'date' => $date,
			'source' => $source,
			'attachment_scope' => $attachmentScope,
			'media_type' => $mediaType,
			'filename' => $filename,
			// Not stored: asked for again by the panel, and only granted to who may see hidden files.
			'show_hidden' => ! empty( $decoded['show_hidden'] ),
		];
	}

	public function __construct() {
		add_action( 'wp_ajax_wp_media_helper_media_panel_state', [ $this, 'handle' ] );
		add_action( 'wp_ajax_wp_media_helper_bulk_media', [ $this, 'handleBulk' ] );
		add_action( 'wp_ajax_wp_media_helper_panel_mode', [ $this, 'handlePanelMode' ] );
		add_action( 'wp_ajax_wp_media_helper_save_filter', [ $this, 'handleSaveFilter' ] );
		PanelThumbnail::register();
	}

	private function makeMediaFilters(): MediaFilters {
		return new MediaFilters(
			static fn ( string $key ) => get_user_meta( get_current_user_id(), $key, true ),
			static function ( string $key, $value ): void {
				update_user_meta( get_current_user_id(), $key, $value );
			}
		);
	}

	/**
	 * Maximum number of entries per page and per bulk request.
	 */
	private function getMaxEntries(): int {
		$settings = new GeneralSettings(
			static fn(): mixed => get_option( GeneralSettings::optionKey(), [] ),
			static function ( array $value ): void {}
		);

		return $settings->getMaxEntries();
	}

	/**
	 * Returns enabled source configurations with the fields needed by the panel.
	 * Filesystem health is not rechecked here; settings validation owns that work.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function getActiveSources(): array {
		return ActiveSources::all();
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

		if ( ! in_array( $key, [ 'attachment_scope', 'source', 'media_type', 'filename' ], true ) ) {
			wp_send_json_error( [ 'message' => 'The requested filter is not supported.' ], 400 );
		}

		if ( 'attachment_scope' === $key ) {
			$value = self::normalizeAttachmentScope( $decodedValue );
		} elseif ( 'media_type' === $key ) {
			$value = self::normalizeMediaTypeFilter( $decodedValue );
		} elseif ( 'filename' === $key ) {
			$value = self::normalizeFilenameFilter( $decodedValue );
		} else {
			$activeSourceIds = array_column( $this->getActiveSources(), 'id' );
			$value = self::normalizeSourceFilter( $decodedValue, $activeSourceIds );
		}
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

		if ( ! in_array( $action, [ 'import', 'remove', 'attach', 'detach', 'hide', 'show' ], true ) ) {
			wp_send_json_error( [ 'message' => 'The requested bulk action is not supported.' ], 400 );
		}

		if ( in_array( $action, [ 'import', 'attach' ], true ) && ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		if ( in_array( $action, [ 'hide', 'show' ], true ) && ! HiddenFiles::canHide() ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
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

		$maxEntries = $this->getMaxEntries();
		if ( count( $items ) > $maxEntries ) {
			wp_send_json_error(
				[
					'message' => sprintf(
						/* translators: %d: maximum number of items per request. */
						__( 'At most %d media items can be processed at once.', 'wp-media-helper' ),
						$maxEntries
					),
				],
				400
			);
		}

		wp_send_json_success( [
			'action' => $action,
			'results' => $this->processBulkItems( $sourceId, $action, $items, $postId ),
		] );
	}

	/**
	 * @param array<int, array{id:string, path:string, source_id?:string}> $items
	 * @return array<int, array<string, mixed>>
	 */
	private function processBulkItems( string $sourceId, string $action, array $items, int $postId = 0 ): array {
		$results = [];
		$registry = new AttachmentRegistry();
		$registrar = new AttachmentRegistrar( $registry );
		$activeSources = in_array( $action, [ 'import', 'attach', 'hide', 'show' ], true ) ? $this->getActiveSources() : [];
		foreach ( $items as $item ) {
			$itemSourceId = (string) ( $item['source_id'] ?? $sourceId );
			$result = [
				'id' => $item['id'],
				'path' => $item['path'],
				'success' => false,
				'is_imported' => false,
				'is_attached_to_current_post' => false,
				'operation' => 'no_change',
			];

			if ( in_array( $action, [ 'attach', 'detach', 'remove' ], true ) ) {
				$otherPostId = self::findOtherPostAttachment( $registry->findByPath( $item['path'] ), $postId );
				if ( 0 !== $otherPostId ) {
					$result['message'] = __( 'This media is attached to another post.', 'wp-media-helper' );
					$result['operation'] = 'protected_other_post';
					$results[] = $result;
					continue;
				}
			}

			if ( in_array( $action, [ 'hide', 'show' ], true ) ) {
				// The flag is global: the file must belong to an active source, whoever claims it.
				$confined = PathConfinement::resolveFileInSources( $activeSources, $itemSourceId, $item['path'] );
				if ( null === $confined ) {
					$result['message'] = __( 'The selected media file is not readable.', 'wp-media-helper' );
					$results[] = $result;
					continue;
				}

				HiddenFiles::set( $confined['path'], 'hide' === $action );
				$result['path'] = $confined['path'];
				$result['success'] = true;
				$result['is_hidden'] = 'hide' === $action;
				$result['operation'] = 'hide' === $action ? 'hidden' : 'shown';
				// Hiding deleted the previews: a file shown again gets the addresses of new ones,
				// made when the browser asks for them, so its thumbnail comes back at once.
				if ( 'show' === $action && 'image' === MediaPanelState::resolveMediaType( basename( $confined['path'] ) ) && current_user_can( 'upload_files' ) ) {
					$result = array_merge( $result, PanelThumbnail::urlsFor( $confined['path'], wp_create_nonce( PanelThumbnail::NONCE ), Thumbnails::serviceForWordPress() ) );
				}
				$results[] = $result;
				continue;
			}

			if ( in_array( $action, [ 'import', 'attach' ], true ) ) {
				// The path comes from the browser: it must resolve to a regular
				// file below the root of an active configured source.
				$confined = PathConfinement::resolveFileInSources( $activeSources, $itemSourceId, $item['path'] );
				if ( null === $confined ) {
					$result['message'] = __( 'The selected media file is not readable.', 'wp-media-helper' );
					$results[] = $result;
					continue;
				}

				$itemSourceId = (string) $confined['source']['id'];
				$item['path'] = $confined['path'];
				$result['path'] = $confined['path'];

				// A hidden file is not offered: a list that was not refreshed must not import it.
				if ( HiddenFiles::isHidden( $confined['path'] ) ) {
					$result['message'] = __( 'This file is hidden. Show it first.', 'wp-media-helper' );
					$result['operation'] = 'hidden';
					$results[] = $result;
					continue;
				}

				// Reuse the attachment of this file, whichever tool created it.
				$existing = $registry->findByPath( $item['path'] );
				if ( [] !== $existing ) {
					$attachmentId = $existing[0]['id'];
					$relative = $registry->relativeKey( $item['path'] );
					foreach ( $existing as $row ) {
						// Attachments created by older versions of this plugin move to the native form.
						if ( $row['owned'] && null !== $relative ) {
							$registry->normalizeLegacy( $row['id'], $relative );
						}
					}
				} else {
					$attachmentId = $registrar->register( $itemSourceId, $item['path'], $confined['source'] );
				}
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
					if ( ! current_user_can( 'edit_post', (int) $attachmentId ) ) {
						$result['success'] = false;
						$result['message'] = __( 'You are not allowed to attach this media.', 'wp-media-helper' );
						$results[] = $result;
						continue;
					}

					$updated = wp_update_post( [
						'ID' => (int) $attachmentId,
						'post_parent' => $postId,
					], true );
					if ( is_wp_error( $updated ) || empty( $updated ) ) {
						$result['success'] = false;
						$result['message'] = is_wp_error( $updated ) ? $updated->get_error_message() : __( 'Unable to attach the media to the current post.', 'wp-media-helper' );
						$results[] = $result;
						continue;
					}

					$result['is_attached_to_current_post'] = true;
					$result['operation'] = 'attached';
				}

				$results[] = $result;
				continue;
			}

			$rows = $registry->findByPath( $item['path'] );
			$detached = $this->detachRows( $rows, $postId );
			if ( is_wp_error( $detached ) ) {
				$result['message'] = $detached->get_error_message();
				$results[] = $result;
				continue;
			}

			if ( 'detach' === $action ) {
				$result['success'] = true;
				$result['is_imported'] = [] !== $registry->findByPath( $item['path'] );
				$result['detached_ids'] = $detached;
				$result['operation'] = [] === $detached ? 'no_change' : 'detached';
				$results[] = $result;
				continue;
			}

			// Removing an attachment never deletes a file, so it applies to the
			// attachments of the file whichever tool created them.
			$removed = $this->removeRows( $rows );
			$result['success'] = true;
			$result['is_imported'] = [] !== $registry->findByPath( $item['path'] );
			$result['detached_ids'] = $detached;
			$result['removed_ids'] = $removed;
			$result['operation'] = [] !== $detached ? ( [] === $removed ? 'detached' : 'detached_and_removed' ) : ( [] === $removed ? 'no_change' : 'removed_from_library' );
			$results[] = $result;
		}

		return $results;
	}

	/**
	 * Deletes the attachments of a file, which are not attached to a post,
	 * whichever tool created them, without ever deleting a file.
	 *
	 * `wp_delete_post()` deletes the attached file and its sub-sizes. The
	 * `_wp_attached_file` meta is removed first so that core finds nothing to
	 * delete, and a `wp_delete_file` filter refuses every deletion for the
	 * duration of the call, in case another plugin restores that value.
	 *
	 * @param array<int, array{id:int, parent:int, owned:bool}> $rows
	 * @return int[]
	 */
	private function removeRows( array $rows ): array {
		$removed = [];
		$refuse = static fn (): string => '';
		add_filter( 'wp_delete_file', $refuse, PHP_INT_MAX );

		try {
			foreach ( $rows as $row ) {
				$attachmentId = (int) $row['id'];
				if ( 0 !== (int) get_post_field( 'post_parent', $attachmentId ) ) {
					continue;
				}
				if ( ! current_user_can( 'delete_post', $attachmentId ) ) {
					continue;
				}

				delete_post_meta( $attachmentId, '_wp_attached_file' );
				wp_delete_post( $attachmentId, true );
				$removed[] = $attachmentId;
			}
		} finally {
			remove_filter( 'wp_delete_file', $refuse, PHP_INT_MAX );
		}

		return $removed;
	}

	/**
	 * Removes the association between attachments and the current post.
	 *
	 * @param array<int, array{id:int, parent:int, owned:bool}> $rows
	 * @return int[]|\WP_Error
	 */
	private function detachRows( array $rows, int $postId ) {
		$detached = [];
		foreach ( $rows as $row ) {
			$attachmentId = (int) $row['id'];
			if ( $postId !== (int) get_post_field( 'post_parent', $attachmentId ) ) {
				continue;
			}

			if ( ! current_user_can( 'edit_post', $attachmentId ) ) {
				continue;
			}

			$updated = wp_update_post( [
				'ID' => $attachmentId,
				'post_parent' => 0,
			], true );
			if ( is_wp_error( $updated ) || empty( $updated ) ) {
				return is_wp_error( $updated ) ? $updated : new \WP_Error( 'detach_attachment_failed', __( 'Unable to detach the media from the current post.', 'wp-media-helper' ) );
			}

			$detached[] = $attachmentId;
		}

		return $detached;
	}

	/**
	 * Returns the ID of the post an attachment of the file is protected by, or 0
	 * when none is attached to a post other than $postId.
	 *
	 * @param array<int, array{id:int, parent:int, owned:bool}> $rows
	 */
	public static function findOtherPostAttachment( array $rows, int $postId ): int {
		foreach ( $rows as $row ) {
			$parent = (int) $row['parent'];
			if ( 0 !== $parent && $parent !== $postId ) {
				return $parent;
			}
		}

		return 0;
	}

	public function handle(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		check_ajax_referer( 'wp_media_helper_media_panel', 'nonce' );

		$legacySource = sanitize_text_field( wp_unslash( $_POST['source_id'] ?? '' ) );
		$postId = absint( $_POST['post_id'] ?? 0 );
		$today = current_time( 'Y-m-d' );
		$legacyDate = MediaPanelState::normalizeDate( sanitize_text_field( wp_unslash( $_POST['date'] ?? $today ) ), $today );
		$page = absint( $_POST['page'] ?? 1 );
		$rawFilters = wp_unslash( $_POST['filters'] ?? '' );
		$activeSources = $this->getActiveSources();
		$activeSourceIds = array_map( static fn ( array $source ): string => (string) $source['id'], $activeSources );
		$filters = self::normalizeFilters(
			$rawFilters,
			$legacyDate,
			$legacySource,
			$activeSourceIds,
			fn () => $this->makeMediaFilters()->resolveUserPostThenUser( $postId, 'attachment_scope', null ),
			fn () => $this->makeMediaFilters()->resolveUserPostThenUser( $postId, 'source', [ 'all' ] ),
			fn () => $this->makeMediaFilters()->resolveUserPostThenUser( $postId, 'media_type', [ 'image', 'video', 'other' ] ),
			fn () => $this->makeMediaFilters()->resolveUserPostThenUser( $postId, 'filename', '' )
		);
		$filters['date'] = MediaPanelState::normalizeDate( (string) $filters['date'], $legacyDate );
		$dateValue = $filters['date'];
		$maxEntries = $this->getMaxEntries();
		$forceRefresh = ! empty( $_POST['force_refresh'] );
		$selectedSources = self::resolveSourcesForFilter( $activeSources, $filters['source'] );
		$sourceOptions = array_map( static fn ( array $source ): array => [
			'id' => (string) $source['id'],
			'name' => (string) $source['name'],
		], $activeSources );

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
				'pagination' => [
					'page' => 1,
					'per_page' => $maxEntries,
					'total' => 0,
					'total_pages' => 1,
				],
				'available_sources' => $sourceOptions,
				'filters' => $filters,
			] );
		}

		$date = new DateTimeImmutable( $dateValue );
		$panelState = new MediaPanelState();
		$includeHidden = ! empty( $filters['show_hidden'] ) && HiddenFiles::canSeeHidden();
		$filters['show_hidden'] = $includeHidden;
		$results = [];
		foreach ( $selectedSources as $selected ) {
			$sourceKey = (string) ( $selected['id'] ?? '' );
			$results[] = $forceRefresh
				? $panelState->requestRefresh( $selected, $date, $sourceKey, null, $includeHidden )
				: $panelState->resolve( $selected, $date, $sourceKey, null, $includeHidden );
		}

		$merged = [
			'source_id' => 1 === count( $filters['source'] ) && 'all' !== $filters['source'][0] ? $filters['source'][0] : 'all',
			'date' => $dateValue,
			'date_range' => null,
			'status' => 'fresh',
			'refresh_required' => false,
			'stale' => false,
			'reason' => null,
			'files' => [],
			'directory' => '',
			'available_sources' => $sourceOptions,
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
		$merged['files'] = MediaPanelState::setAttachmentIds( $merged['files'], $attachmentStates['attachment_ids'] );
		$merged['files'] = MediaPanelState::filterByAttachmentScope( $merged['files'], $filters['attachment_scope'] );
		$merged['files'] = MediaPanelState::filterByMediaType( $merged['files'], $filters['media_type'] );
		$merged['files'] = MediaPanelState::filterByFilename( $merged['files'], $filters['filename'] );
		$pageData = MediaPanelState::paginate( $merged['files'], $page, $maxEntries );
		$merged['files'] = $this->addThumbnailUrls( $this->enrichOtherPostInfo( $pageData['items'] ) );
		unset( $pageData['items'] );
		$merged['pagination'] = $pageData;
		// Absolute server directories are not exposed to the browser.
		unset( $merged['directory'] );
		$merged['status'] = $merged['refresh_required'] ? 'stale' : 'fresh';
		$merged['filters'] = $filters;
		wp_send_json_success( $merged );
	}

	/**
	 * Adds the URL of a preview to the listed images. Only for users who may upload,
	 * since the preview shows what the file contains.
	 *
	 * @param array<int, array<string, mixed>> $files
	 * @return array<int, array<string, mixed>>
	 */
	private function addThumbnailUrls( array $files ): array {
		if ( ! current_user_can( 'upload_files' ) ) {
			return $files;
		}

		$nonce   = wp_create_nonce( PanelThumbnail::NONCE );
		$service = Thumbnails::serviceForWordPress();
		foreach ( $files as $index => $file ) {
			if ( 'image' === ( $file['media_type'] ?? '' ) && ! empty( $file['path'] ) && empty( $file['is_hidden'] ) ) {
				$files[ $index ] = array_merge(
					$file,
					PanelThumbnail::urlsFor( (string) $file['path'], $nonce, $service, isset( $file['width'] ) ? (int) $file['width'] : null, isset( $file['height'] ) ? (int) $file['height'] : null )
				);
			}
		}

		return $files;
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
			if ( 0 === $otherPostId ) {
				continue;
			}

			if ( ! current_user_can( 'edit_post', $otherPostId ) ) {
				$files[ $index ]['other_post_id'] = 0;
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
	 * Which listed files already have an attachment, and where it is attached.
	 * Paths in the result are the listed paths, so they match the items exactly.
	 *
	 * @param string[] $candidatePaths
	 * @return array{imported_paths:string[], attached_paths:string[], other_post_by_path:array<string, array{post_id:int}>}
	 */
	private function resolveAttachmentStates( array $candidatePaths, int $postId ): array {
		$imported = [];
		$attached = [];
		$otherPostByPath = [];
		$attachmentIds = [];

		foreach ( ( new AttachmentRegistry() )->statesFor( $candidatePaths ) as $path => $rows ) {
			$path = (string) $path;
			$imported[] = $path;
			$attachmentIds[ $path ] = array_values( array_map( static fn ( array $row ): int => (int) $row['id'], $rows ) );
			foreach ( $rows as $row ) {
				$parent = (int) $row['parent'];
				if ( 0 !== $postId && $postId === $parent ) {
					$attached[] = $path;
					unset( $otherPostByPath[ $path ] );
					break;
				}
				if ( 0 !== $parent ) {
					$otherPostByPath[ $path ] = [ 'post_id' => $parent ];
				}
			}
		}

		return [
			'imported_paths' => $imported,
			'attached_paths' => $attached,
			'other_post_by_path' => $otherPostByPath,
			'attachment_ids' => $attachmentIds,
		];
	}
}
