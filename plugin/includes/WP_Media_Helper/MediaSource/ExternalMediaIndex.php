<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\MediaSource;

use DateTimeInterface;
use InvalidArgumentException;

class ExternalMediaIndex {

	private string $storageDir;

	public function __construct( string $storageDir = '' ) {
		$this->storageDir = '' === $storageDir ? sys_get_temp_dir() . '/wp-media-helper-index' : $storageDir;

		if ( ! is_dir( $this->storageDir ) ) {
			mkdir( $this->storageDir, 0755, true );
		}
	}

	/**
	 * @param array<string, mixed> $source
	 * @return string[]
	 */
	public function getForSource( array $source, DateTimeInterface $date, ?string $context = null, bool $forceRefresh = false ): array {
		$sourceId = (string) ( $context ?? $source['id'] ?? '' );
		if ( '' === $sourceId ) {
			throw new InvalidArgumentException( 'Source identifier is required for index lookup.' );
		}

		$directory = ( new DatePatternResolver() )->resolvePath(
			(string) ( $source['root'] ?? '' ),
			(string) ( $source['path_pattern'] ?? '' ),
			$date,
			$sourceId
		);

		$cachePath = $this->pathForSource( $source, $sourceId );
		$cached = $this->readCache( $cachePath );

		if ( ! $forceRefresh && null !== $cached && $this->isFresh( $cached, $directory ) ) {
			return $cached['files'];
		}

		$files = ( new SourceIndexer() )->findForDate( $source, $date, $sourceId );
		$this->writeCache( $cachePath, $directory, $files );

		return $files;
	}

	/**
	 * @param array<string, mixed> $source
	 */
	public function pathForSource( array $source, ?string $context = null ): string {
		$sourceId = (string) ( $context ?? $source['id'] ?? '' );
		if ( '' === $sourceId ) {
			throw new InvalidArgumentException( 'Source identifier is required for cache path generation.' );
		}

		$clean = preg_replace( '/[^a-z0-9._-]+/i', '-', $sourceId ) ?? $sourceId;
		$clean = trim( (string) $clean, '-_.' );

		return rtrim( $this->storageDir, '/\\' ) . '/' . ( '' === $clean ? 'source' : $clean ) . '.json';
	}

	/**
	 * @return array{directory:string, files:string[], mtime:float}|null
	 */
	private function readCache( string $cachePath ): ?array {
		if ( ! is_file( $cachePath ) ) {
			return null;
		}

		$contents = file_get_contents( $cachePath );
		if ( false === $contents ) {
			return null;
		}

		$payload = json_decode( $contents, true );
		if ( ! is_array( $payload ) ) {
			return null;
		}

		if ( ! isset( $payload['directory'], $payload['files'], $payload['mtime'] ) ) {
			return null;
		}

		if ( ! is_string( $payload['directory'] ) || ! is_array( $payload['files'] ) || ! is_numeric( $payload['mtime'] ) ) {
			return null;
		}

		return [
			'directory' => $payload['directory'],
			'files' => array_values( $payload['files'] ),
			'mtime' => (float) $payload['mtime'],
		];
	}

	/**
	 * @param array{directory:string, files:string[], mtime:float} $cache
	 */
	private function isFresh( array $cache, string $directory ): bool {
		if ( $cache['directory'] !== $directory ) {
			return false;
		}

		if ( ! is_dir( $directory ) ) {
			return false;
		}

		return filemtime( $directory ) <= $cache['mtime'];
	}

	/**
	 * @param string[] $files
	 */
	private function writeCache( string $cachePath, string $directory, array $files ): void {
		$payload = [
			'directory' => $directory,
			'mtime' => is_dir( $directory ) ? (float) filemtime( $directory ) : 0.0,
			'files' => array_values( $files ),
		];

		file_put_contents( $cachePath, json_encode( $payload, JSON_PRETTY_PRINT ), LOCK_EX );
	}
}
