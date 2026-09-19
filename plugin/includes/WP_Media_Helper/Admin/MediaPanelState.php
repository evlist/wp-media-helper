<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

use DateTimeInterface;
use InvalidArgumentException;
use WP_Media_Helper\MediaSource\TargetedRefreshCoordinator;

class MediaPanelState {

	private TargetedRefreshCoordinator $coordinator;

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
				'source_id' => $sourceId,
				'date' => $date,
				'is_imported' => false,
				'is_attached_to_current_post' => false,
			];
		}

		return $entries;
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
	 * @param array<int, array<string, mixed>> $items
	 * @param array<int, string> $importedPaths
	 * @return array<int, array<string, mixed>>
	 */
	public static function pathSignatureCandidates( string $path ): array {
		$trimmed = trim( $path );
		if ( '' === $trimmed ) {
			return [];
		}

		$basename = basename( $trimmed );
		$signature = preg_replace( '/_[0-9]+(?=\.[^.]+$)/', '', $basename );
		$withoutSuffix = is_string( $signature ) ? $signature : $basename;
		$originalWithoutSuffix = preg_replace( '/_[0-9]+(?=\.[^.]+$)/', '', $trimmed );
		$withoutSuffixPath = is_string( $originalWithoutSuffix ) ? $originalWithoutSuffix : $trimmed;

		$candidates = array_values( array_filter( [
			$trimmed,
			md5( $trimmed ),
			$basename,
			md5( $basename ),
			$withoutSuffix,
			md5( $withoutSuffix ),
			$withoutSuffixPath,
			md5( $withoutSuffixPath ),
		], static fn ( $value ) => is_string( $value ) && '' !== $value ) );

		return array_values( array_unique( $candidates ) );
	}

	/**
	 * @return bool True when two paths refer to the same media item, even if WordPress renamed the uploaded file.
	 */
	public static function pathMatches( string $leftPath, string $rightPath ): bool {
		$leftPath = trim( $leftPath );
		$rightPath = trim( $rightPath );
		if ( '' === $leftPath || '' === $rightPath ) {
			return false;
		}

		if ( $leftPath === $rightPath ) {
			return true;
		}

		$leftCandidates = self::pathSignatureCandidates( $leftPath );
		$rightCandidates = self::pathSignatureCandidates( $rightPath );
		$known = [];
		foreach ( $rightCandidates as $candidate ) {
			$known[ $candidate ] = true;
		}

		foreach ( $leftCandidates as $candidate ) {
			if ( isset( $known[ $candidate ] ) ) {
				return true;
			}
		}

		return false;
	}

	public static function setImportState( array $items, array $importedPaths ): array {
		$known = [];
		foreach ( $importedPaths as $path ) {
			if ( ! is_string( $path ) ) {
				continue;
			}
			foreach ( self::pathSignatureCandidates( $path ) as $candidate ) {
				$known[ $candidate ] = true;
			}
		}

		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$path = (string) ( $item['path'] ?? $item['name'] ?? '' );
			$items[ $index ]['is_imported'] = false;
			foreach ( self::pathSignatureCandidates( $path ) as $candidate ) {
				if ( isset( $known[ $candidate ] ) ) {
					$items[ $index ]['is_imported'] = true;
					break;
				}
			}
		}

		return $items;
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @param array<int, string> $attachedPaths
	 * @return array<int, array<string, mixed>>
	 */
	public static function setAttachmentState( array $items, array $attachedPaths ): array {
		$known = [];
		foreach ( $attachedPaths as $path ) {
			if ( ! is_string( $path ) ) {
				continue;
			}
			foreach ( self::pathSignatureCandidates( $path ) as $candidate ) {
				$known[ $candidate ] = true;
			}
		}

		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$path = (string) ( $item['path'] ?? $item['name'] ?? '' );
			$items[ $index ]['is_attached_to_current_post'] = false;
			foreach ( self::pathSignatureCandidates( $path ) as $candidate ) {
				if ( isset( $known[ $candidate ] ) ) {
					$items[ $index ]['is_attached_to_current_post'] = true;
					break;
				}
			}
		}

		return $items;
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
