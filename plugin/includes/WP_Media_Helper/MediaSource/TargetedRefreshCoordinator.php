<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\MediaSource;

use DateTimeInterface;
use InvalidArgumentException;

class TargetedRefreshCoordinator {

	private ExternalMediaIndex $index;

	public function __construct( ?ExternalMediaIndex $index = null ) {
		$this->index = $index ?? new ExternalMediaIndex();
	}

	/**
	 * @param array<string, mixed> $source
	 * @return array{refresh_required:bool, stale:bool, reason:string|null, files:string[], directory:string}
	 */
	public function resolve( array $source, DateTimeInterface $date, ?string $context = null, bool $forceRefresh = false ): array {
		$sourceId = (string) ( $context ?? $source['id'] ?? '' );
		if ( '' === $sourceId ) {
			throw new InvalidArgumentException( 'Source identifier is required for refresh orchestration.' );
		}

		$directory = ( new DatePatternResolver() )->resolvePath(
			(string) ( $source['root'] ?? '' ),
			(string) ( $source['path_pattern'] ?? '' ),
			$date,
			$sourceId
		);

		if ( $forceRefresh ) {
			$files = $this->index->getForSource( $source, $date, $sourceId, true );
			return [
				'refresh_required' => true,
				'stale' => true,
				'reason' => 'forced-refresh',
				'files' => $files,
				'directory' => $directory,
			];
		}

		$needsRefresh = $this->index->needsRefresh( $source, $date, $sourceId );
		if ( ! $needsRefresh ) {
			$files = $this->index->getForSource( $source, $date, $sourceId );
			return [
				'refresh_required' => false,
				'stale' => false,
				'reason' => null,
				'files' => $files,
				'directory' => $directory,
			];
		}

		$files = $this->index->getForSource( $source, $date, $sourceId, true );
		return [
			'refresh_required' => true,
			'stale' => true,
			'reason' => 'stale-directory',
			'files' => $files,
			'directory' => $directory,
		];
	}
}
