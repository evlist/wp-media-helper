<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Thumbnails;

/**
 * Finds, creates and deletes the sub-sizes of attachments in the cache.
 *
 * The original is only read. Files are written below the cache directory only, with a
 * name derived from the layout (never from client input), through a temporary file
 * that is renamed, so two requests creating the same size cannot leave a half-written
 * file.
 */
final class ThumbnailService {

	private string $baseDir;
	private string $baseUrl;
	private string $cacheDir;

	/**
	 * Writes a resized copy: (source, destination, width, height, crop) and returns the
	 * real [width, height] written, or null on failure.
	 *
	 * @var callable
	 */
	private $resizer;

	public function __construct( string $baseDir, string $baseUrl, string $cacheDir, callable $resizer ) {
		$this->baseDir  = rtrim( $baseDir, '/\\' );
		$this->baseUrl  = $baseUrl;
		$this->cacheDir = rtrim( $cacheDir, '/\\' );
		$this->resizer  = $resizer;
	}

	/**
	 * The file of a size, when the entry points to one in the cache and it exists.
	 *
	 * @param array<string, mixed> $entry
	 */
	public function existing( string $relative, array $entry ): ?string {
		$path = ThumbnailLayout::pathForEntry( $this->cacheDir, $relative, $entry );

		return null !== $path && is_file( $path ) ? $path : null;
	}

	/**
	 * Deletes every size of a file found in the cache (`<name>-<W>x<H>.<ext>` next to where
	 * its sizes are kept), whether or not an attachment lists them: the previews of the panel
	 * have none. Only regular files named like that inside the cache are removed.
	 *
	 * @param string $relative Path of the original relative to uploads.
	 * @return int Number of files deleted.
	 */
	public function purgeFor( string $relative ): int {
		$sample = ThumbnailLayout::pathForFile( $this->cacheDir, $relative, ThumbnailLayout::fileName( basename( $relative ), 1, 1 ) );
		if ( null === $sample || ! $this->isInsideCache( dirname( $sample ) ) || ! is_dir( dirname( $sample ) ) ) {
			return 0;
		}

		$name      = pathinfo( basename( $relative ), PATHINFO_FILENAME );
		$extension = pathinfo( basename( $relative ), PATHINFO_EXTENSION );
		$pattern   = '/^' . preg_quote( $name, '/' ) . '-\d+x\d+' . ( '' === $extension ? '' : '\.' . preg_quote( $extension, '/' ) ) . '$/';

		$deleted = 0;
		foreach ( scandir( dirname( $sample ) ) ?: [] as $entry ) {
			$path = dirname( $sample ) . '/' . $entry;
			if ( 1 === preg_match( $pattern, $entry ) && is_file( $path ) && ! is_link( $path ) && @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * The file a size of `$width` x `$height` has in the cache, when it exists.
	 */
	public function existingSize( string $relative, int $width, int $height ): ?string {
		$path = ThumbnailLayout::pathForFile( $this->cacheDir, $relative, ThumbnailLayout::fileName( basename( $relative ), $width, $height ) );

		return null !== $path && is_file( $path ) ? $path : null;
	}

	public function url( string $path ): ?string {
		return ThumbnailLayout::url( $this->baseDir, $this->baseUrl, $path );
	}

	/**
	 * Makes sure the file of a size exists and returns its metadata entry
	 * (`file`, `width`, `height`, `mime-type`, `filesize`: the keys core writes, no path).
	 *
	 * @param bool|array<int, string> $crop
	 * @return array<string, mixed>|null Null when the original is unusable or the copy fails.
	 */
	public function ensure( string $relative, int $width, int $height, $crop, string $mimeType ): ?array {
		$name   = ThumbnailLayout::fileName( basename( $relative ), $width, $height );
		$target = ThumbnailLayout::pathForFile( $this->cacheDir, $relative, $name );
		if ( null === $target || ! ThumbnailLayout::isSafeInside( $this->cacheDir, $target ) ) {
			return null;
		}

		if ( is_file( $target ) ) {
			return $this->entry( $name, $width, $height, $mimeType, $target );
		}

		$source = $this->baseDir . '/' . $relative;
		if ( is_link( $source ) || ! is_file( $source ) ) {
			return null;
		}

		$directory = dirname( $target );
		if ( ! $this->isInsideCache( $directory ) ) {
			return null;
		}
		if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return null;
		}
		$realDirectory = realpath( $directory );
		if ( false === $realDirectory || ! $this->isInsideCache( $realDirectory ) ) {
			return null;
		}

		$temporary = $realDirectory . '/.' . bin2hex( random_bytes( 4 ) ) . '-' . $name;
		$written   = ( $this->resizer )( $source, $temporary, $width, $height, $crop );
		if ( ! is_array( $written ) || ! is_file( $temporary ) ) {
			@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

			return null;
		}
		if ( ! @rename( $temporary, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

			return null;
		}

		return $this->entry( $name, (int) $written[0], (int) $written[1], $mimeType, $target );
	}

	/**
	 * Deletes the files of the cache listed by the sizes of an attachment. Only files
	 * inside the cache are touched, never the original.
	 *
	 * @param array<string, mixed> $metadata `_wp_attachment_metadata`
	 * @return int Number of files deleted.
	 */
	public function purge( string $relative, array $metadata ): int {
		$deleted = 0;
		foreach ( (array) ( $metadata['sizes'] ?? [] ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$path = $this->existing( $relative, $entry );
			if ( null !== $path && is_file( $path ) && ! is_link( $path ) && @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Whether a directory, existing or not, is the cache or below it once the
	 * links of its existing part are resolved. Checked before creating anything, so a
	 * link in the cache cannot make the files, or the directories, land elsewhere.
	 */
	private function isInsideCache( string $directory ): bool {
		$realCache = realpath( $this->cacheDir );
		if ( false === $realCache ) {
			// The cache does not exist yet: it will be created below an existing uploads directory.
			$realBase = realpath( $this->baseDir );
			if ( false === $realBase || ! ThumbnailLayout::isSafeInside( $this->baseDir, $this->cacheDir . '/x' ) ) {
				return false;
			}
			$realCache = $realBase . substr( $this->cacheDir, strlen( $this->baseDir ) );
		}

		$existing = $directory;
		$missing  = '';
		while ( false === realpath( $existing ) ) {
			if ( dirname( $existing ) === $existing ) {
				return false;
			}
			$missing  = '/' . basename( $existing ) . $missing;
			$existing = dirname( $existing );
		}

		$resolved = realpath( $existing ) . $missing;

		return $resolved === $realCache || ThumbnailLayout::isSafeInside( $realCache, $resolved );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function entry( string $name, int $width, int $height, string $mimeType, string $path ): array {
		return [
			'file'      => $name,
			'width'     => $width,
			'height'    => $height,
			'mime-type' => $mimeType,
			'filesize'  => (int) filesize( $path ),
		];
	}
}
