<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

use DateTimeInterface;
use InvalidArgumentException;
use WP_Media_Helper\MediaSource\TargetedRefreshCoordinator;

class MediaPanelState {

	private TargetedRefreshCoordinator $coordinator;

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
	 *   files:string[],
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

		return [
			'source_id' => $sourceId,
			'date' => $date->format( 'Y-m-d' ),
			'date_range' => null === $dateEnd ? null : [
				'start' => $date->format( 'Y-m-d' ),
				'end' => $dateEnd->format( 'Y-m-d' ),
			],
			'status' => $status,
			'refresh_required' => $result['refresh_required'],
			'stale' => $result['stale'],
			'reason' => $result['reason'],
			'files' => $result['files'],
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
	 *   files:string[],
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

		return [
			'source_id' => $sourceId,
			'date' => $date->format( 'Y-m-d' ),
			'date_range' => null === $dateEnd ? null : [
				'start' => $date->format( 'Y-m-d' ),
				'end' => $dateEnd->format( 'Y-m-d' ),
			],
			'status' => $status,
			'refresh_required' => $result['refresh_required'],
			'stale' => $result['stale'],
			'reason' => $result['reason'],
			'files' => $result['files'],
			'directory' => $result['directory'],
		];
	}
}
