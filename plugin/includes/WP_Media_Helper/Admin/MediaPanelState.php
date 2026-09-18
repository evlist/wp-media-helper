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
