<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

use DateTimeImmutable;
use WP_Media_Helper\Settings\ExternalSourceSettings;

class EditorMediaController {

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

	public function __construct() {
		add_action( 'wp_ajax_wp_media_helper_media_panel_state', [ $this, 'handle' ] );
		add_action( 'wp_ajax_wp_media_helper_import_media', [ $this, 'handleImport' ] );
		add_action( 'wp_ajax_wp_media_helper_remove_media', [ $this, 'handleRemove' ] );
	}

	public function handleRemove(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		check_ajax_referer( 'wp_media_helper_media_panel', 'nonce' );

		$sourceId = sanitize_text_field( wp_unslash( $_POST['source_id'] ?? '' ) );
		$path = sanitize_text_field( wp_unslash( $_POST['path'] ?? '' ) );

		if ( '' === $path ) {
			wp_send_json_error( [ 'message' => 'The selected media file path is required.' ], 400 );
		}

		$removed = $this->removeVirtualAttachment( $sourceId, $path );
		if ( is_wp_error( $removed ) ) {
			wp_send_json_error( [ 'message' => $removed->get_error_message() ], 400 );
		}

		$removedIds = array_values( array_unique( array_map( 'intval', $removed ) ) );
		wp_send_json_success( [
			'attachment_id' => $removedIds[0] ?? 0,
			'source_id' => $sourceId,
			'path' => $path,
			'is_imported' => false,
			'removed_ids' => $removedIds,
		] );
	}

	public function handleImport(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		check_ajax_referer( 'wp_media_helper_media_panel', 'nonce' );

		$sourceId = sanitize_text_field( wp_unslash( $_POST['source_id'] ?? '' ) );
		$path = sanitize_text_field( wp_unslash( $_POST['path'] ?? '' ) );

		if ( '' === $path || ! is_file( $path ) ) {
			wp_send_json_error( [ 'message' => 'The selected media file is not readable.' ], 400 );
		}

		$attachmentId = $this->registerVirtualAttachment( $sourceId, $path );
		if ( is_wp_error( $attachmentId ) ) {
			wp_send_json_error( [ 'message' => $attachmentId->get_error_message() ], 400 );
		}

		$attachmentId = (int) $attachmentId;
		wp_send_json_success( [
			'attachment_id' => $attachmentId,
			'source_id' => $sourceId,
			'path' => $path,
			'is_imported' => true,
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
	 * Removes the WordPress-side attachment record without deleting the original source file.
	 *
	 * @return int[]|\WP_Error
	 */
	public function removeVirtualAttachment( string $sourceId, string $path ) {
		$attachments = get_posts( [
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'posts_per_page' => -1,
			'post_parent' => 0,
			'fields' => 'ids',
		] );

		if ( empty( $attachments ) || is_wp_error( $attachments ) ) {
			return new \WP_Error( 'attachment_not_found', 'The selected media file is not currently imported into WordPress.' );
		}

		$matches = [];
		foreach ( $attachments as $attachmentId ) {
			$attachmentId = (int) $attachmentId;
			$attachedFile = get_attached_file( $attachmentId, true );
			$sourcePath = get_post_meta( $attachmentId, '_wp_media_helper_source_path', true );
			$metaSourceId = get_post_meta( $attachmentId, '_wp_media_helper_source_id', true );

			if ( '' !== $sourceId && '' !== (string) $metaSourceId && (string) $metaSourceId !== $sourceId ) {
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

		if ( [] === $matches ) {
			return new \WP_Error( 'attachment_not_found', 'The selected media file is not currently imported into WordPress.' );
		}

		foreach ( array_values( array_unique( $matches ) ) as $attachmentId ) {
			wp_delete_post( (int) $attachmentId, true );
		}

		return array_values( array_unique( $matches ) );
	}

	public function handle(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		check_ajax_referer( 'wp_media_helper_media_panel', 'nonce' );

		$sourceId = sanitize_text_field( wp_unslash( $_POST['source_id'] ?? '' ) );
		$dateValue = sanitize_text_field( wp_unslash( $_POST['date'] ?? current_time( 'Y-m-d' ) ) );
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
		$importedPaths = $this->resolveImportedPaths( $importedPaths );
		$merged['files'] = MediaPanelState::setImportState( $merged['files'], $importedPaths );
		$merged['status'] = $merged['refresh_required'] ? 'stale' : 'fresh';
		wp_send_json_success( $merged );
	}

	/**
	 * @param string[] $candidatePaths
	 * @return string[]
	 */
	private function resolveImportedPaths( array $candidatePaths ): array {
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
			return [];
		}

		$attachments = get_posts( [
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'posts_per_page' => -1,
			'post_parent' => 0,
			'fields' => 'ids',
		] );
		if ( empty( $attachments ) || is_wp_error( $attachments ) ) {
			return [];
		}

		$imported = [];
		foreach ( $attachments as $attachmentId ) {
			$path = get_attached_file( (int) $attachmentId, true );
			$sourcePath = get_post_meta( (int) $attachmentId, '_wp_media_helper_source_path', true );
			$checks = [];
			if ( is_string( $path ) && '' !== $path ) {
				$checks[] = $path;
			}
			if ( is_string( $sourcePath ) && '' !== $sourcePath ) {
				$checks[] = $sourcePath;
			}

			foreach ( $checks as $candidatePath ) {
				foreach ( MediaPanelState::pathSignatureCandidates( $candidatePath ) as $candidate ) {
					if ( isset( $known[ $candidate ] ) ) {
						$imported[] = $path;
						break 2;
					}
				}
			}
		}

		return array_values( array_unique( $imported ) );
	}
}
