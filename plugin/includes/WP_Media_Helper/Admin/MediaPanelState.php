<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

use DateTimeInterface;
use InvalidArgumentException;
use WP_Media_Helper\MediaSource\TargetedRefreshCoordinator;

class MediaPanelState {

	private TargetedRefreshCoordinator $coordinator;
	private const IMAGE_EXTENSIONS = [ 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg' ];
	private const VIDEO_EXTENSIONS = [ 'mp4', 'mov', 'webm', 'avi', 'm4v' ];

	public static function resolveMediaType( string $filename ): string {
		$extension = strtolower( pathinfo( basename( $filename ), PATHINFO_EXTENSION ) );
		if ( in_array( $extension, self::IMAGE_EXTENSIONS, true ) ) {
			return 'image';
		}

		if ( in_array( $extension, self::VIDEO_EXTENSIONS, true ) ) {
			return 'video';
		}

		return 'other';
	}

	/**
	 * @param array<int, string> $filePaths
	 * @return array<int, array<string, mixed>>
	 */
	public static function enrichFiles( array $filePaths, ?string $sourceId = null, ?string $date = null ): array {
		$entries = [];
		foreach ( $filePaths as $path ) {
			if ( ! is_string( $path ) || '' === trim( $path ) ) {
				continue;
			}

			$name = basename( $path );
			$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			$type = '' === $extension ? 'other' : $extension;

			$entries[] = [
				'id' => md5( $path ),
				'name' => $name,
				'path' => $path,
				'type' => $type,
				'media_type' => self::resolveMediaType( $name ),
				'source_id' => $sourceId,
				'date' => $date,
				'is_imported' => false,
				'is_attached_to_current_post' => false,
			];
		}

		return $entries;
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @param array<int, string> $selectedTypes
	 * @return array<int, array<string, mixed>>
	 */
	public static function filterByMediaType( array $items, array $selectedTypes ): array {
		return array_values( array_filter( $items, static function ( $item ) use ( $selectedTypes ): bool {
			if ( ! is_array( $item ) ) {
				return false;
			}

			$mediaType = (string) ( $item['media_type'] ?? self::resolveMediaType( (string) ( $item['name'] ?? $item['path'] ?? '' ) ) );
			return in_array( $mediaType, $selectedTypes, true );
		} ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @return array<int, array<string, mixed>>
	 */
	public static function filterByFilename( array $items, string $query ): array {
		if ( '' === $query ) {
			return $items;
		}

		return array_values( array_filter( $items, static function ( $item ) use ( $query ): bool {
			if ( ! is_array( $item ) ) {
				return false;
			}

			$filename = (string) ( $item['name'] ?? $item['path'] ?? '' );
			$basename = basename( str_replace( '\\', '/', $filename ) );
			if ( function_exists( 'mb_stripos' ) ) {
				return false !== mb_stripos( $basename, $query, 0, 'UTF-8' );
			}

			return false !== stripos( $basename, $query );
		} ) );
	}

	/**
	 * @param array<int, array<string, mixed>|string> $current
	 * @param array<int, array<string, mixed>|string> $incoming
	 * @return array<int, array<string, mixed>>
	 */
	public static function mergeFiles( array $current, array $incoming ): array {
		$merged = [];
		foreach ( array_merge( $current, $incoming ) as $entry ) {
			if ( is_string( $entry ) ) {
				$normalized = self::enrichFiles( [ $entry ] );
				if ( [] === $normalized ) {
					continue;
				}
				$entry = $normalized[0];
			}

			if ( ! is_array( $entry ) ) {
				continue;
			}

			$path = (string) ( $entry['path'] ?? $entry['name'] ?? '' );
			if ( '' === $path ) {
				continue;
			}

			$id = (string) ( $entry['id'] ?? md5( $path ) );
			$merged[ $id ] = $entry;
		}

		return array_values( $merged );
	}

	/**
	 * Items are matched to attachments by exact file identity, never by file
	 * name: two files with the same name in different directories are different
	 * media. The paths given here are the paths of the listed items.
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @param array<int, string> $importedPaths
	 * @return array<int, array<string, mixed>>
	 */
	public static function setImportState( array $items, array $importedPaths ): array {
		return self::setFlag( $items, $importedPaths, 'is_imported' );
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @param array<int, string> $attachedPaths
	 * @return array<int, array<string, mixed>>
	 */
	public static function setAttachmentState( array $items, array $attachedPaths ): array {
		return self::setFlag( $items, $attachedPaths, 'is_attached_to_current_post' );
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @param array<string, array{post_id:int}> $otherPostByPath keyed by the path of the listed item
	 * @return array<int, array<string, mixed>>
	 */
	public static function setOtherPostState( array $items, array $otherPostByPath ): array {
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$path = (string) ( $item['path'] ?? $item['name'] ?? '' );
			$info = $otherPostByPath[ $path ] ?? null;
			$items[ $index ]['is_attached_to_other_post'] = is_array( $info );
			$items[ $index ]['other_post_id'] = is_array( $info ) ? (int) ( $info['post_id'] ?? 0 ) : 0;
		}

		return $items;
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @param array<int, string> $paths
	 * @return array<int, array<string, mixed>>
	 */
	private static function setFlag( array $items, array $paths, string $flag ): array {
		$known = array_fill_keys( array_filter( $paths, 'is_string' ), true );

		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$path = (string) ( $item['path'] ?? $item['name'] ?? '' );
			$items[ $index ][ $flag ] = isset( $known[ $path ] );
		}

		return $items;
	}

	/**
	 * Resolves the single attachment_scope state ("unattached", "current", or
	 * "other") that an enriched item currently belongs to.
	 *
	 * @param array<string, mixed> $item
	 */
	public static function resolveAttachmentScopeState( array $item ): string {
		if ( ! empty( $item['is_attached_to_current_post'] ) ) {
			return 'current';
		}

		if ( ! empty( $item['is_attached_to_other_post'] ) ) {
			return 'other';
		}

		return 'unattached';
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @param array<int, string> $selectedStates
	 * @return array<int, array<string, mixed>>
	 */
	public static function filterByAttachmentScope( array $items, array $selectedStates ): array {
		return array_values( array_filter( $items, static function ( $item ) use ( $selectedStates ): bool {
			if ( ! is_array( $item ) ) {
				return false;
			}

			return in_array( self::resolveAttachmentScopeState( $item ), $selectedStates, true );
		} ) );
	}

	/**
	 * Slices an item list into one page. The requested page is clamped to the
	 * available range so an out-of-date page number never yields an empty view.
	 *
	 * @param array<int, mixed> $items
	 * @return array{items:array<int, mixed>, page:int, per_page:int, total:int, total_pages:int}
	 */
	public static function paginate( array $items, int $page, int $perPage ): array {
		$perPage = max( 1, $perPage );
		$total = count( $items );
		$totalPages = max( 1, (int) ceil( $total / $perPage ) );
		$page = min( max( 1, $page ), $totalPages );

		return [
			'items' => array_slice( array_values( $items ), ( $page - 1 ) * $perPage, $perPage ),
			'page' => $page,
			'per_page' => $perPage,
			'total' => $total,
			'total_pages' => $totalPages,
		];
	}

	/**
	 * Returns $value when it is a real calendar date written as Y-m-d, else $fallback.
	 */
	public static function normalizeDate( string $value, string $fallback ): string {
		$value = trim( $value );
		$parsed = 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? \DateTimeImmutable::createFromFormat( '!Y-m-d', $value ) : false;

		return false !== $parsed && $parsed->format( 'Y-m-d' ) === $value ? $value : $fallback;
	}

	public function __construct( ?TargetedRefreshCoordinator $coordinator = null ) {
		$this->coordinator = $coordinator ?? new TargetedRefreshCoordinator();
	}

	/**
	 * @param array<string, mixed> $source
	 * @return array{
	 *   source_id:string,
	 *   date:string,
	 *   date_range:array{start:string, end:string}|null,
	 *   status:string,
	 *   refresh_required:bool,
	 *   stale:bool,
	 *   reason:string|null,
	 *   files:array<int, array<string, mixed>>,
	 *   directory:string
	 * }
	 */
	public function resolve( array $source, DateTimeInterface $date, ?string $context = null, ?DateTimeInterface $dateEnd = null ): array {
		$sourceId = (string) ( $context ?? $source['id'] ?? '' );
		if ( '' === $sourceId ) {
			throw new InvalidArgumentException( 'Source identifier is required for media panel state.' );
		}

		$result = $this->coordinator->resolve( $source, $date, $sourceId );
		$status = $result['refresh_required'] ? 'stale' : 'fresh';
		$dateValue = $date->format( 'Y-m-d' );

		return [
			'source_id' => $sourceId,
			'date' => $dateValue,
			'date_range' => null === $dateEnd ? null : [
				'start' => $date->format( 'Y-m-d' ),
				'end' => $dateEnd->format( 'Y-m-d' ),
			],
			'status' => $status,
			'refresh_required' => $result['refresh_required'],
			'stale' => $result['stale'],
			'reason' => $result['reason'],
			'files' => self::enrichFiles( $result['files'], $sourceId, $dateValue ),
			'directory' => $result['directory'],
		];
	}

	/**
	 * @param array<string, mixed> $source
	 * @return array{
	 *   source_id:string,
	 *   date:string,
	 *   date_range:array{start:string, end:string}|null,
	 *   status:string,
	 *   refresh_required:bool,
	 *   stale:bool,
	 *   reason:string|null,
	 *   files:array<int, array<string, mixed>>,
	 *   directory:string
	 * }
	 */
	public function requestRefresh( array $source, DateTimeInterface $date, ?string $context = null, ?DateTimeInterface $dateEnd = null ): array {
		$sourceId = (string) ( $context ?? $source['id'] ?? '' );
		if ( '' === $sourceId ) {
			throw new InvalidArgumentException( 'Source identifier is required for media panel refresh.' );
		}

		$result = $this->coordinator->resolve( $source, $date, $sourceId, true );
		$status = $result['refresh_required'] ? 'stale' : 'fresh';
		$dateValue = $date->format( 'Y-m-d' );

		return [
			'source_id' => $sourceId,
			'date' => $dateValue,
			'date_range' => null === $dateEnd ? null : [
				'start' => $date->format( 'Y-m-d' ),
				'end' => $dateEnd->format( 'Y-m-d' ),
			],
			'status' => $status,
			'refresh_required' => $result['refresh_required'],
			'stale' => $result['stale'],
			'reason' => $result['reason'],
			'files' => self::enrichFiles( $result['files'], $sourceId, $dateValue ),
			'directory' => $result['directory'],
		];
	}
}
