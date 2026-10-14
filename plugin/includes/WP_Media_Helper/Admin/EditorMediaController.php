<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

use WP_Media_Helper\Thumbnails\PanelThumbnail;
use WP_Media_Helper\Thumbnails\Thumbnails;
use DateTimeImmutable;
use WP_Media_Helper\Attachment\Fields;
use WP_Media_Helper\Attachment\Method;
use WP_Media_Helper\Attachment\Methods;
use WP_Media_Helper\Attachment\NativeMethod;
use WP_Media_Helper\MediaSource\AttachmentRegistrar;
use WP_Media_Helper\MediaSource\AttachmentRegistry;
use WP_Media_Helper\MediaSource\FileTypes;
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
			// The data of the link (slice 042): coerced to the declared fields by the controller before the method sees it.
			if ( isset( $item['data'] ) && is_array( $item['data'] ) ) {
				$normalized[ $key ]['data'] = $item['data'];
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

		// An empty list is a choice (nothing checked); anything unusable gives the default.
		return [] === $normalized && [] !== $rawScope ? [ 'unattached', 'current' ] : $normalized;
	}

	/**
	 * Normalizes the set of broad media categories shown by the panel.
	 *
	 * @return array<int, string>
	 */
	public static function normalizeMediaTypeFilter( mixed $rawTypes ): array {
		$allowed = FileTypes::categories();
		$values = is_array( $rawTypes ) ? array_map( 'strval', $rawTypes ) : [];
		$normalized = array_values( array_intersect( $allowed, $values ) );

		// An empty list is a choice (nothing checked); anything unusable gives everything.
		if ( [] === $normalized ) {
			return [] === $rawTypes ? [] : $allowed;
		}

		// Before the file categories, a choice of "everything" was these three values: it still means everything.
		return [ 'image', 'video', 'other' ] === $normalized ? $allowed : $normalized;
	}

	/**
	 * The selection of each filter that a method declares on one of its fields (`filter` => true), as the panel asked for
	 * it, else as the user last chose it for this post, else the default of the field. A selection holds values of the
	 * field and `_none` (the links with no value). An empty selection is a choice.
	 *
	 * @param array<int, array<string, mixed>> $fields Fields as returned by `Fields::normalize()`.
	 * @param mixed                            $raw    The `link` entry of the filters of the request.
	 * @param callable(string): mixed          $stored Returns what was stored for a key of the user and post, or null.
	 * @return array<string, string[]> Keyed by field key, filterable fields only.
	 */
	public static function normalizeLinkFilters( array $fields, mixed $raw, callable $stored, bool $cohabitation = false ): array {
		$raw      = is_array( $raw ) ? $raw : [];
		$selected = [];
		$nativeCarried = false;
		foreach ( $fields as $field ) {
			if ( empty( $field['filter'] ) ) {
				continue;
			}

			$key     = (string) $field['key'];
			$allowed = array_merge( array_column( $field['options'], 'value' ), [ Fields::NONE ], $cohabitation && ! $nativeCarried ? [ Fields::NATIVE ] : [] );
			$value   = array_key_exists( $key, $raw ) ? $raw[ $key ] : $stored( $key );
			if ( ! is_array( $value ) ) {
				$value = array_merge( $field['filter_default'], $cohabitation && ! $nativeCarried ? [ Fields::NATIVE ] : [] );
			}
			// With both mechanisms in use, the first filter also has the value `_native`: the files attached the WordPress way.
			$nativeCarried = $nativeCarried || $cohabitation;
			$selected[ $key ] = array_values( array_intersect( $allowed, array_map( 'strval', array_filter( $value, 'is_scalar' ) ) ) );
		}

		return $selected;
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

		// Nothing checked is a choice of its own: the panel lists nothing and says so.
		if ( [] === $rawSource ) {
			return [];
		}

		$requested = is_array( $rawSource ) ? array_map( 'strval', $rawSource ) : [ (string) $rawSource ];
		if ( in_array( 'all', $requested, true ) ) {
			return [ 'all' ];
		}

		$selected = array_values( array_intersect( $activeSourceIds, $requested ) );

		return [] === $selected ? [ 'all' ] : $selected;
	}

	/**
	 * The filters that have nothing checked.
	 *
	 * @param array<string, mixed> $filters
	 * @return string[] Among `source`, `attachment_scope` and `media_type`.
	 */
	public static function emptyFilters( array $filters, bool $hasSources = true ): array {
		$empty = [];
		foreach ( [ 'source', 'attachment_scope', 'media_type' ] as $key ) {
			if ( isset( $filters[ $key ] ) && is_array( $filters[ $key ] ) && [] === $filters[ $key ] && ( 'source' !== $key || $hasSources ) ) {
				$empty[] = $key;
			}
		}

		return $empty;
	}

	/**
	 * @param array<int, array<string, mixed>> $activeSources
	 * @param array<int, string>               $sourceFilter
	 * @return array<int, array<string, mixed>>
	 */
	public static function resolveSourcesForFilter( array $activeSources, array $sourceFilter ): array {
		if ( in_array( 'all', $sourceFilter, true ) ) {
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
			// Not stored: asked for again by the panel, and only granted to who may see the trash.
			'show_trash' => ! empty( $decoded['show_trash'] ),
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

		$linkField = str_starts_with( $key, 'link_' ) ? substr( $key, 5 ) : '';
		if ( '' !== $linkField ) {
			// A filter on a field of the attachment method of this post.
			$selection = self::normalizeLinkFilters(
				Fields::normalize( Methods::active( $postId )->capabilities()['fields'] ?? [] ),
				[ $linkField => $decodedValue ],
				static fn (): mixed => null,
				Methods::cohabitation( $postId )
			);
			if ( ! isset( $selection[ $linkField ] ) ) {
				wp_send_json_error( [ 'message' => 'The requested filter is not supported.' ], 400 );
			}
			$this->makeMediaFilters()->persistUserPost( $postId, $key, $selection[ $linkField ] );
			wp_send_json_success( [ 'key' => $key, 'value' => $selection[ $linkField ] ] );
		}

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
		$this->makeMediaFilters()->persistUserPost( $postId, $key, $value );
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

		if ( ! in_array( $action, [ 'import', 'remove', 'attach', 'detach', 'update', 'attach_native', 'attach_method', 'to_native', 'to_method', 'trash', 'restore' ], true ) ) {
			wp_send_json_error( [ 'message' => 'The requested bulk action is not supported.' ], 400 );
		}

		if ( in_array( $action, [ 'import', 'attach', 'attach_native', 'attach_method' ], true ) && ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		if ( in_array( $action, [ 'trash', 'restore' ], true ) && ! TrashedFiles::canTrash() ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		// The actions of the cohabitation (both mechanisms in use) are refused when it is not on.
		if ( in_array( $action, [ 'attach_native', 'attach_method', 'to_native', 'to_method' ], true ) && ! Methods::cohabitation( $postId ) ) {
			wp_send_json_error( [ 'message' => 'The requested bulk action is not supported.' ], 400 );
		}

		if ( in_array( $action, [ 'attach', 'detach', 'remove', 'update', 'attach_native', 'attach_method', 'to_native', 'to_method' ], true ) && ( 0 === $postId || ! get_post( $postId ) || ! current_user_can( 'edit_post', $postId ) ) ) {
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
		// How media items are attached to posts: the method in use for this post (slice 042).
		$method = Methods::active( $postId );
		$fields = Fields::normalize( $method->capabilities()['fields'] ?? [] );
		$cohabit = Methods::cohabitation( $postId );
		$native = new NativeMethod();
		// With both mechanisms in use, *attach* says which one: the native method or the method in use.
		$attachVia = null;
		if ( 'attach_native' === $action || 'attach_method' === $action ) {
			$attachVia = 'attach_native' === $action ? $native : $method;
			$action = 'attach';
		}
		$activeSources = in_array( $action, [ 'import', 'attach', 'trash', 'restore' ], true ) ? $this->getActiveSources() : [];
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

			if ( in_array( $action, [ 'attach', 'detach', 'remove', 'update' ], true ) ) {
				$blocked = $this->protectedMessage( $attachVia ?? $method, $registry->findByPath( $item['path'] ), $postId );
				if ( null !== $blocked ) {
					$result['message'] = $blocked;
					$result['operation'] = 'protected_other_post';
					$results[] = $result;
					continue;
				}
			}

			if ( in_array( $action, [ 'trash', 'restore' ], true ) ) {
				// The flag is global: the file must belong to an active source, whoever claims it.
				$confined = PathConfinement::resolveFileInSources( $activeSources, $itemSourceId, $item['path'] );
				if ( null === $confined ) {
					$result['message'] = __( 'The selected media file is not readable.', 'wp-media-helper' );
					$results[] = $result;
					continue;
				}

				$result['path'] = $confined['path'];
				if ( 'trash' === $action ) {
					// The attachments of the file go too, from every post, and this cannot be undone;
					// the file itself is never deleted. Nothing is done unless all of them may be.
					$rows = $registry->findByPath( $confined['path'] );
					foreach ( $rows as $row ) {
						if ( ! current_user_can( 'delete_post', (int) $row['id'] ) ) {
							$result['message'] = __( 'You are not allowed to remove this media from the library.', 'wp-media-helper' );
							$results[] = $result;
							continue 2;
						}
					}
					$result['removed_ids'] = $this->removeRows( $method, $rows, 'trash' );
					$result['is_imported'] = false;
				}

				TrashedFiles::set( $confined['path'], 'trash' === $action );
				$result['success'] = true;
				$result['is_trashed'] = 'trash' === $action;
				$result['operation'] = 'trash' === $action ? 'trashed' : 'restored';
				// Trashing deleted the previews: a restored file gets the addresses of new ones,
				// made when the browser asks for them, so its thumbnail comes back at once.
				if ( 'restore' === $action && 'image' === MediaPanelState::resolveMediaType( basename( $confined['path'] ) ) && current_user_can( 'upload_files' ) ) {
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

				// A trashed file is not offered: a list that was not refreshed must not import it.
				if ( TrashedFiles::isTrashed( $confined['path'] ) ) {
					$result['message'] = __( 'This file is in the trash. Restore it first.', 'wp-media-helper' );
					$result['operation'] = 'trashed';
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
					$attacher = $attachVia ?? $method;
					$viaNative = $attacher instanceof NativeMethod;
					// What the browser did not send takes the default of each field (the native method has none).
					$data     = $viaNative ? [] : Fields::sanitize( $fields, $item['data'] ?? null, true );
					$attached = $attacher->attach( (int) $attachmentId, $postId, $data );
					if ( is_wp_error( $attached ) ) {
						$result['success'] = false;
						$result['message'] = $attached->get_error_message();
						$results[] = $result;
						continue;
					}
					do_action( 'wp_media_helper_attached', (int) $attachmentId, $postId, $attacher->id(), $data );
					if ( ! $viaNative ) {
						$result['attachment_data'] = $data;
					}
					$result['attached_natively_only'] = $viaNative && $cohabit;

					$result['is_attached_to_current_post'] = true;
					$result['operation'] = 'attached';
				}

				$results[] = $result;
				continue;
			}

			$rows = $registry->findByPath( $item['path'] );
			if ( 'to_native' === $action ) {
				$results[] = $this->switchToNative( $method, $native, $rows, $postId, $result );
				continue;
			}

			if ( 'to_method' === $action ) {
				$results[] = $this->convertLink( $method, $fields, $rows, $postId, $item, $result );
				continue;
			}

			if ( 'update' === $action ) {
				$results[] = $this->updateLink( $method, $fields, $rows, $postId, $item, $result );
				continue;
			}

			$detached = $this->detachRows( $method, $rows, $postId, $cohabit );
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
			$removed = $this->removeRows( $method, $rows );
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
	 * Each removal is allowed by the method in use (`Method::may_remove()`); a refusal skips that attachment.
	 *
	 * @param array<int, array{id:int, parent:int, owned:bool}> $rows
	 * @param string $context `remove` (*Remove from library*) or `trash` (the file goes to the trash).
	 * @return int[]
	 */
	private function removeRows( Method $method, array $rows, string $context = 'remove' ): array {
		$removed = [];
		$refuse = static fn (): string => '';
		add_filter( 'wp_delete_file', $refuse, PHP_INT_MAX );

		try {
			foreach ( $rows as $row ) {
				$attachmentId = (int) $row['id'];
				if ( is_wp_error( $method->may_remove( $attachmentId, $context ) ) ) {
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
	 * Removes the link between the attachments and the current post, through the method in use.
	 *
	 * @param array<int, array{id:int, parent:int, owned:bool}> $rows
	 * @return int[]|\WP_Error The IDs that were detached.
	 */
	private function detachRows( Method $method, array $rows, int $postId, bool $cohabit = false ) {
		$ids        = array_map( static fn ( array $row ): int => (int) $row['id'], $rows );
		$described  = $method->describe( $ids, $postId );
		$native     = new NativeMethod();
		$nativeHere = $cohabit ? $native->describe( $ids, $postId ) : [];
		$detached   = [];
		foreach ( $rows as $row ) {
			$attachmentId = (int) $row['id'];
			if ( ! empty( $described[ $attachmentId ]['attached_here'] ) ) {
				$done = $method->detach( $attachmentId, $postId );
				$by   = $method->id();
			} elseif ( ! empty( $nativeHere[ $attachmentId ]['attached_here'] ) ) {
				// With both mechanisms in use, a file attached the WordPress way is detached too.
				$done = $native->detach( $attachmentId, $postId );
				$by   = $native->id();
			} else {
				continue;
			}

			if ( is_wp_error( $done ) ) {
				// The user may not change this attachment: it is skipped, as it always was.
				if ( 'not_allowed' === $done->get_error_code() ) {
					continue;
				}

				return $done;
			}

			$detached[] = $attachmentId;
			do_action( 'wp_media_helper_detached', $attachmentId, $postId, $by );
		}

		return $detached;
	}

	/**
	 * Switches a file attached to the current post through the method to the WordPress way (the action `to_native`):
	 * the method detaches it, then the attachment is attached natively. Nothing is changed when the file is attached
	 * natively to another post, since it could not be attached here.
	 *
	 * @param array<int, array{id:int, parent:int, owned:bool}> $rows
	 * @param array<string, mixed>                              $result
	 * @return array<string, mixed>
	 */
	private function switchToNative( Method $method, NativeMethod $native, array $rows, int $postId, array $result ): array {
		$ids      = array_map( static fn ( array $row ): int => (int) $row['id'], $rows );
		$current  = $method->describe( $ids, $postId );
		$switched = [];
		foreach ( $ids as $attachmentId ) {
			if ( empty( $current[ $attachmentId ]['attached_here'] ) ) {
				continue;
			}

			$before = $native->describe( [ $attachmentId ], $postId )[ $attachmentId ] ?? null;
			if ( null !== $before && null !== $before['protected'] ) {
				$result['message'] = (string) $before['protected'];

				return $result;
			}

			$done = $method->detach( $attachmentId, $postId );
			if ( is_wp_error( $done ) ) {
				if ( 'not_allowed' === $done->get_error_code() ) {
					continue;
				}
				$result['message'] = $done->get_error_message();

				return $result;
			}
			do_action( 'wp_media_helper_detached', $attachmentId, $postId, $method->id() );

			// The method may keep the post as the primary parent: then the media item is already attached the WordPress way.
			$after = $native->describe( [ $attachmentId ], $postId )[ $attachmentId ] ?? null;
			if ( null === $after || ! $after['attached_here'] ) {
				$attached = $native->attach( $attachmentId, $postId, [] );
				if ( is_wp_error( $attached ) ) {
					$result['message'] = $attached->get_error_message();

					return $result;
				}
				do_action( 'wp_media_helper_attached', $attachmentId, $postId, $native->id(), [] );
			}

			$switched[] = $attachmentId;
		}

		if ( [] === $switched ) {
			$result['message'] = __( 'Nothing to switch: this media is not attached to the current post through the method.', 'wp-media-helper' );

			return $result;
		}

		$result['success']                     = true;
		$result['is_imported']                 = true;
		$result['is_attached_to_current_post'] = true;
		$result['attached_natively_only']      = true;
		$result['attachment_data']             = null;
		$result['operation']                   = 'switched_to_native';

		return $result;
	}

	/**
	 * Makes the method in use know the links that exist only through `post_parent` (the action `to_method`, slice 042):
	 * a file attached to the current post the WordPress way is attached again through the method, with the data of the
	 * browser or the defaults of its fields. `post_parent` is not touched here; what the method does with it is its business.
	 *
	 * @param array<int, array<string, mixed>>                          $fields
	 * @param array<int, array{id:int, parent:int, owned:bool}>         $rows
	 * @param array{id:string, path:string, data?:array<string, mixed>} $item
	 * @param array<string, mixed>                                      $result
	 * @return array<string, mixed>
	 */
	private function convertLink( Method $method, array $fields, array $rows, int $postId, array $item, array $result ): array {
		$ids       = array_map( static fn ( array $row ): int => (int) $row['id'], $rows );
		$native    = ( new NativeMethod() )->describe( $ids, $postId );
		$current   = $method->describe( $ids, $postId );
		$data      = Fields::sanitize( $fields, $item['data'] ?? null, true );
		$converted = [];
		foreach ( $ids as $attachmentId ) {
			if ( empty( $native[ $attachmentId ]['attached_here'] ) || ! empty( $current[ $attachmentId ]['attached_here'] ) ) {
				continue;
			}

			$done = $method->attach( $attachmentId, $postId, $data );
			if ( is_wp_error( $done ) ) {
				$result['message'] = $done->get_error_message();

				return $result;
			}

			$converted[] = $attachmentId;
			do_action( 'wp_media_helper_attached', $attachmentId, $postId, $method->id(), $data );
		}

		if ( [] === $converted ) {
			$result['message'] = __( 'Nothing to convert: this media is not attached to the current post the WordPress way only.', 'wp-media-helper' );

			return $result;
		}

		$after                                 = $method->describe( $converted, $postId );
		$result['success']                     = true;
		$result['is_imported']                 = true;
		$result['is_attached_to_current_post'] = true;
		$result['attachment_data']             = $after[ $converted[0] ]['data'] ?? $data;
		$result['operation']                   = 'converted';
		$result['attached_natively_only']      = false;

		return $result;
	}

	/**
	 * Changes the data of the link between a file and the current post (the action `update`).
	 *
	 * @param array<int, array<string, mixed>>                                 $fields
	 * @param array<int, array{id:int, parent:int, owned:bool}>                $rows
	 * @param array{id:string, path:string, data?:array<string, mixed>}        $item
	 * @param array<string, mixed>                                             $result
	 * @return array<string, mixed>
	 */
	private function updateLink( Method $method, array $fields, array $rows, int $postId, array $item, array $result ): array {
		$ids       = array_map( static fn ( array $row ): int => (int) $row['id'], $rows );
		$described = $method->describe( $ids, $postId );
		$data      = Fields::sanitize( $fields, $item['data'] ?? null );
		$updated   = [];
		foreach ( $ids as $attachmentId ) {
			if ( empty( $described[ $attachmentId ]['attached_here'] ) ) {
				continue;
			}

			$done = $method->update( $attachmentId, $postId, $data );
			if ( is_wp_error( $done ) ) {
				$result['message'] = $done->get_error_message();

				return $result;
			}

			$updated[] = $attachmentId;
			do_action( 'wp_media_helper_attachment_updated', $attachmentId, $postId, $method->id(), $data );
		}

		if ( [] === $updated ) {
			$result['message'] = __( 'This media is not attached to the current post.', 'wp-media-helper' );

			return $result;
		}

		$after                        = $method->describe( $updated, $postId );
		$result['success']            = true;
		$result['is_imported']        = true;
		$result['is_attached_to_current_post'] = true;
		$result['attachment_data']    = $after[ $updated[0] ]['data'] ?? $data;
		$result['operation']          = 'updated';

		return $result;
	}

	/**
	 * The message of what blocks an action on a file (one of its attachments is protected for this post), or null.
	 *
	 * @param array<int, array{id:int, parent:int, owned:bool}> $rows
	 */
	private function protectedMessage( Method $method, array $rows, int $postId ): ?string {
		$described = $method->describe( array_map( static fn ( array $row ): int => (int) $row['id'], $rows ), $postId );
		foreach ( $described as $state ) {
			if ( null !== ( $state['protected'] ?? null ) ) {
				return (string) $state['protected'];
			}
		}

		return null;
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
			fn () => $this->makeMediaFilters()->resolveUserPost( $postId, 'attachment_scope', null ),
			fn () => $this->makeMediaFilters()->resolveUserPost( $postId, 'source', [ 'all' ] ),
			fn () => $this->makeMediaFilters()->resolveUserPost( $postId, 'media_type', FileTypes::categories() ),
			fn () => $this->makeMediaFilters()->resolveUserPost( $postId, 'filename', '' )
		);
		$filters['date'] = MediaPanelState::normalizeDate( (string) $filters['date'], $legacyDate );
		$dateValue = $filters['date'];
		$maxEntries = $this->getMaxEntries();
		$forceRefresh = ! empty( $_POST['force_refresh'] );
		$selectedSources = self::resolveSourcesForFilter( $activeSources, $filters['source'] );
		$method = Methods::active( $postId );
		$linkFields = Fields::normalize( $method->capabilities()['fields'] ?? [] );
		$decodedRequest = is_string( $rawFilters ) ? json_decode( $rawFilters, true ) : null;
		$filters['link'] = self::normalizeLinkFilters(
			$linkFields,
			is_array( $decodedRequest ) ? ( $decodedRequest['link'] ?? null ) : null,
			fn ( string $key ) => $this->makeMediaFilters()->resolveUserPost( $postId, 'link_' . $key, null ),
			Methods::cohabitation( $postId )
		);
		$sourceOptions = array_map( static fn ( array $source ): array => [
			'id' => (string) $source['id'],
			'name' => (string) $source['name'],
		], $activeSources );

		// A filter with nothing checked lists nothing, without reading any source; the panel says which one.
		$emptyFilters = self::emptyFilters( $filters, [] !== $activeSources );
		if ( [] === $selectedSources || [] !== $emptyFilters ) {
			wp_send_json_success( [
				'empty_filters' => $emptyFilters,
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
				'attachment_method' => self::methodForPanel( $method, Methods::cohabitation( $postId ) ),
			] );
		}

		$date = new DateTimeImmutable( $dateValue );
		$panelState = new MediaPanelState();
		$includeHidden = ! empty( $filters['show_trash'] ) && TrashedFiles::canSeeTrash();
		$filters['show_trash'] = $includeHidden;
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
		$merged['files'] = MediaPanelState::setAttachmentData( $merged['files'], $attachmentStates['data_by_path'] );
		$merged['files'] = MediaPanelState::setNativeOnly( $merged['files'], $attachmentStates['legacy_paths'] );
		$merged['files'] = MediaPanelState::filterByLinkFields( $merged['files'], $linkFields, $filters['link'], Methods::cohabitation( $postId ) );
		$merged['files'] = MediaPanelState::filterByAttachmentScope( $merged['files'], $filters['attachment_scope'] );
		$merged['files'] = MediaPanelState::filterByMediaType( $merged['files'], $filters['media_type'] );
		$merged['files'] = MediaPanelState::markImportable( $merged['files'], static fn ( string $name ): array => wp_check_filetype( $name ) );
		$merged['files'] = MediaPanelState::filterByFilename( $merged['files'], $filters['filename'] );
		$pageData = MediaPanelState::paginate( $merged['files'], $page, $maxEntries );
		$merged['files'] = $this->filterItems( $this->addThumbnailUrls( $this->enrichOtherPostInfo( $pageData['items'] ) ), $postId, $method );
		unset( $pageData['items'] );
		$merged['pagination'] = $pageData;
		// Absolute server directories are not exposed to the browser.
		unset( $merged['directory'] );
		$merged['status'] = $merged['refresh_required'] ? 'stale' : 'fresh';
		$merged['filters'] = $filters;
		$merged['attachment_method'] = self::methodForPanel( $method, Methods::cohabitation( $postId ) );
		wp_send_json_success( $merged );
	}

	/**
	 * What the panel needs to know of the method in use.
	 *
	 * @return array{id:string, label:string, multiple_posts:bool, cohabitation:bool, fields:array<int, array<string, mixed>>}
	 */
	public static function methodForPanel( Method $method, bool $cohabitation = false ): array {
		$capabilities = $method->capabilities();

		return [
			'id'             => $method->id(),
			'label'          => $method->label(),
			'multiple_posts' => ! empty( $capabilities['multiple_posts'] ),
			'cohabitation'   => $cohabitation,
			'fields'         => Fields::normalize( $capabilities['fields'] ?? [] ),
		];
	}

	/**
	 * Lets a plugin adjust each item of the page (filter `wp_media_helper_panel_item`).
	 *
	 * @param array<int, array<string, mixed>> $files
	 * @return array<int, array<string, mixed>>
	 */
	private function filterItems( array $files, int $postId, Method $method ): array {
		$context = [ 'post_id' => $postId, 'method' => $method->id() ];
		foreach ( $files as $index => $file ) {
			$filtered = apply_filters( 'wp_media_helper_panel_item', $file, $context );
			if ( is_array( $filtered ) ) {
				$files[ $index ] = $filtered;
			}
		}

		return $files;
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
			if ( 'image' === ( $file['media_type'] ?? '' ) && ! empty( $file['path'] ) && empty( $file['is_trashed'] ) ) {
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

			// A method that lets a media item belong to several posts lists them all (those the user may edit).
			if ( ! empty( $file['other_post_ids'] ) && is_array( $file['other_post_ids'] ) ) {
				$others = [];
				foreach ( array_slice( $file['other_post_ids'], 0, 8 ) as $otherId ) {
					$other = get_post( (int) $otherId );
					if ( $other instanceof \WP_Post && current_user_can( 'edit_post', (int) $otherId ) ) {
						$others[] = [ 'id' => (int) $otherId, 'title' => get_the_title( $other ), 'edit_url' => (string) get_edit_post_link( (int) $otherId, 'raw' ) ];
					}
				}
				$files[ $index ]['other_posts'] = $others;
				$files[ $index ]['other_posts_count'] = count( $file['other_post_ids'] );
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
		$dataByPath = [];

		$statesByPath = ( new AttachmentRegistry() )->statesFor( $candidatePaths );
		$ids          = [];
		foreach ( $statesByPath as $rows ) {
			foreach ( $rows as $row ) {
				$ids[] = (int) $row['id'];
			}
		}
		$method    = Methods::active( $postId );
		$multiple  = ! empty( $method->capabilities()['multiple_posts'] );
		$described = $method->describe( $ids, $postId );
		$elsewhere = [];
		// With a method other than the native one, files attached only the WordPress way (`post_parent`) are marked.
		$nativeHere = NativeMethod::ID === $method->id() ? [] : ( new NativeMethod() )->describe( $ids, $postId );
		$legacy     = [];
		$cohabit    = Methods::cohabitation( $postId );

		foreach ( $statesByPath as $path => $rows ) {
			$path = (string) $path;
			$imported[] = $path;
			$attachmentIds[ $path ] = array_values( array_map( static fn ( array $row ): int => (int) $row['id'], $rows ) );
			foreach ( $rows as $row ) {
				$state = $described[ (int) $row['id'] ] ?? null;
				if ( null === $state ) {
					continue;
				}
				if ( $state['attached_here'] && ! in_array( $path, $attached, true ) ) {
					$attached[] = $path;
					$dataByPath[ $path ] = is_array( $state['data'] ?? null ) ? $state['data'] : [];
				}
				foreach ( $state['elsewhere'] as $otherId ) {
					$elsewhere[ $path ][] = (int) $otherId;
				}
				if ( ! $state['attached_here'] && ! empty( $nativeHere[ (int) $row['id'] ]['attached_here'] ) ) {
					$legacy[ $path ] = true;
				}
				// With both mechanisms in use, the other posts of the WordPress way count too.
				if ( $cohabit ) {
					foreach ( $nativeHere[ (int) $row['id'] ]['elsewhere'] ?? [] as $otherId ) {
						$elsewhere[ $path ][] = (int) $otherId;
					}
				}
			}
		}
		// A file that one of its attachments attaches through the method is not "only the WordPress way".
		$legacy = array_values( array_diff( array_keys( $legacy ), $attached ) );
		// With both mechanisms in use, a file attached the WordPress way is attached to the post (the panel says how).
		if ( $cohabit ) {
			$attached = array_values( array_unique( array_merge( $attached, $legacy ) ) );
		}

		foreach ( $elsewhere as $path => $otherIds ) {
			// With one post per media item, being attached here means not being attached to another post.
			if ( ! $multiple && in_array( (string) $path, $attached, true ) ) {
				continue;
			}
			$otherIds = array_values( array_unique( $otherIds ) );
			$otherPostByPath[ (string) $path ] = [ 'post_id' => (int) end( $otherIds ), 'post_ids' => $otherIds ];
		}

		return [
			'imported_paths' => $imported,
			'attached_paths' => $attached,
			'other_post_by_path' => $otherPostByPath,
			'attachment_ids' => $attachmentIds,
			'data_by_path' => $dataByPath,
			'legacy_paths' => $legacy,
		];
	}
}
