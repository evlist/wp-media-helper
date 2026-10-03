<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\MediaSource;

use DateTimeInterface;
use InvalidArgumentException;

class ExternalMediaIndex {

	private string $storageDir;

	public function __construct( string $storageDir = '' ) {
		$this->storageDir = '' === $storageDir ? self::defaultStorageDir() : $storageDir;

		if ( ! is_dir( $this->storageDir ) ) {
			mkdir( $this->storageDir, 0700, true );
		}

		$this->protectStorageDir();
	}

	/**
	 * Default location of the index: a private folder in the WordPress uploads
	 * directory, never the world-writable system temp directory.
	 */
	public static function defaultStorageDir(): string {
		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir( null, false );
			if ( is_array( $uploads ) && ! empty( $uploads['basedir'] ) && is_string( $uploads['basedir'] ) ) {
				return rtrim( $uploads['basedir'], '/\\' ) . '/wp-media-helper-index';
			}
		}

		return sys_get_temp_dir() . '/wp-media-helper-index-' . ( function_exists( 'posix_getuid' ) ? (string) posix_getuid() : 'user' );
	}

	/**
	 * Keeps the index unreadable over HTTP and from other system users.
	 */
	private function protectStorageDir(): void {
		if ( ! is_dir( $this->storageDir ) || ! is_writable( $this->storageDir ) ) {
			return;
		}

		@chmod( $this->storageDir, 0700 );

		$guards = [
			'index.php' => "<?php\n// Silence is golden.\n",
			'.htaccess' => "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
		];
		foreach ( $guards as $name => $contents ) {
			$guard = rtrim( $this->storageDir, '/\\' ) . '/' . $name;
			if ( ! file_exists( $guard ) ) {
				@file_put_contents( $guard, $contents );
			}
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

		$cachePath = $this->pathForSource( $source, $sourceId, $date );
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
	public function needsRefresh( array $source, DateTimeInterface $date, ?string $context = null ): bool {
		$sourceId = (string) ( $context ?? $source['id'] ?? '' );
		if ( '' === $sourceId ) {
			throw new InvalidArgumentException( 'Source identifier is required for refresh evaluation.' );
		}

		$directory = ( new DatePatternResolver() )->resolvePath(
			(string) ( $source['root'] ?? '' ),
			(string) ( $source['path_pattern'] ?? '' ),
			$date,
			$sourceId
		);

		$cachePath = $this->pathForSource( $source, $sourceId, $date );
		$cached = $this->readCache( $cachePath );

		if ( null === $cached ) {
			return true;
		}

		return ! $this->isFresh( $cached, $directory );
	}

	/**
	 * @param array<string, mixed> $source
	 */
	public function pathForSource( array $source, ?string $context = null, ?DateTimeInterface $date = null ): string {
		$sourceId = (string) ( $context ?? $source['id'] ?? '' );
		if ( '' === $sourceId ) {
			throw new InvalidArgumentException( 'Source identifier is required for cache path generation.' );
		}

		$clean = preg_replace( '/[^a-z0-9._-]+/i', '-', $sourceId ) ?? $sourceId;
		$clean = trim( (string) $clean, '-_.' );

		$datePart = null === $date ? '' : '-' . $date->format( 'Ymd' );

		return rtrim( $this->storageDir, '/\\' ) . '/' . ( '' === $clean ? 'source' : $clean ) . $datePart . '.json';
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

		// The index is a cache, not a trusted source: keep only plain path strings.
		$files = array_values( array_filter( $payload['files'], static fn ( $file ): bool => is_string( $file ) && '' !== $file && ! str_contains( $file, "\0" ) ) );

		return [
			'directory' => $payload['directory'],
			'files' => $files,
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

		clearstatcache( true, $directory );
		$directoryMtime = filemtime( $directory );
		if ( false === $directoryMtime ) {
			return false;
		}

		return $directoryMtime <= $cache['mtime'];
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

		if ( false !== file_put_contents( $cachePath, json_encode( $payload, JSON_PRETTY_PRINT ), LOCK_EX ) ) {
			@chmod( $cachePath, 0600 );
		}
	}
}
