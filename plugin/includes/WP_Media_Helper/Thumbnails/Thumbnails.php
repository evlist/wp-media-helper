<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Thumbnails;

use WP_Media_Helper\MediaSource\AttachmentRegistry;

/**
 * Serves the sub-sizes of attachments from the thumbnail cache, and creates the
 * missing ones of the attachments registered by this plugin.
 *
 * - `image_downsize` returns the cache URL of a size that exists there (also for the
 *   files written by Thumbnails Folder) and, for an attachment registered by this
 *   plugin, creates the size when a named size is asked for and is missing.
 * - A request for a URL never creates anything: only the sizes WordPress asks for,
 *   or the background event below, do.
 * - After registration a single cron event per attachment creates the registered
 *   sizes within a time budget, and reschedules itself while sizes remain. With
 *   WordPress cron disabled nothing is scheduled and sizes are created when asked for.
 */
final class Thumbnails {

	public const CRON_HOOK    = 'wp_media_helper_generate_thumbnails';
	public const BUDGET       = 10;
	public const MAX_PIXELS   = 100000000;

	private ?ThumbnailService $service = null;
	private bool $serviceBuilt         = false;

	public function __construct() {
		add_filter( 'image_downsize', [ $this, 'downsize' ], 10, 3 );
		add_filter( 'wp_calculate_image_srcset', [ $this, 'srcset' ], 10, 5 );
		add_action( 'delete_attachment', [ $this, 'purge' ] );
		add_action( 'wp_media_helper_attachment_registered', [ $this, 'schedule' ] );
		add_action( self::CRON_HOOK, [ $this, 'generateInBackground' ] );
	}

	/**
	 * @param mixed      $out
	 * @param int        $attachmentId
	 * @param string|int[] $size
	 * @return mixed
	 */
	public function downsize( $out, $attachmentId, $size ) {
		if ( false !== $out || ! is_string( $size ) || 'full' === $size ) {
			return $out;
		}

		$service = $this->service();
		$attachmentId = (int) $attachmentId;
		$relative = $this->relativePath( $attachmentId );
		$metadata = wp_get_attachment_metadata( $attachmentId );
		if ( null === $service || null === $relative || ! is_array( $metadata ) ) {
			return $out;
		}

		$entry = $metadata['sizes'][ $size ] ?? null;
		if ( is_array( $entry ) ) {
			$path = $service->existing( $relative, $entry );
			$url  = null === $path ? null : $service->url( $path );
			if ( null !== $url ) {
				return [ $url, (int) ( $entry['width'] ?? 0 ), (int) ( $entry['height'] ?? 0 ), true ];
			}
		}

		if ( ! $this->isRegisteredByUs( $attachmentId ) ) {
			return $out;
		}

		$created = $this->createSize( $service, $attachmentId, $relative, $metadata, $size );
		if ( null === $created ) {
			return $out;
		}

		wp_update_attachment_metadata( $attachmentId, $created['metadata'] );
		$entry = $created['entry'];
		$path  = $service->existing( $relative, $entry );
		$url   = null === $path ? null : $service->url( $path );

		return null === $url ? $out : [ $url, (int) $entry['width'], (int) $entry['height'], true ];
	}

	/**
	 * Points the sources of an image's `srcset` to the cache when their files are there.
	 *
	 * @param mixed $sources
	 * @param mixed $imageMeta
	 * @return mixed
	 */
	public function srcset( $sources, $sizeArray, $imageSrc, $imageMeta, $attachmentId ) {
		$service  = $this->service();
		$relative = $this->relativePath( (int) $attachmentId );
		if ( null === $service || null === $relative || ! is_array( $sources ) || ! is_array( $imageMeta ) ) {
			return $sources;
		}

		$byFile = [];
		foreach ( (array) ( $imageMeta['sizes'] ?? [] ) as $entry ) {
			if ( is_array( $entry ) && isset( $entry['file'] ) && is_string( $entry['file'] ) ) {
				$byFile[ $entry['file'] ] = $entry;
			}
		}

		foreach ( $sources as $width => $source ) {
			$file = is_array( $source ) && isset( $source['url'] ) ? rawurldecode( basename( (string) wp_parse_url( (string) $source['url'], PHP_URL_PATH ) ) ) : '';
			if ( '' === $file || ! isset( $byFile[ $file ] ) ) {
				continue;
			}
			$path = $service->existing( $relative, $byFile[ $file ] );
			$url  = null === $path ? null : $service->url( $path );
			if ( null !== $url ) {
				$sources[ $width ]['url'] = $url;
			}
		}

		return $sources;
	}

	/**
	 * Removes the cached files of a deleted attachment, never its original.
	 */
	public function purge( $attachmentId ): void {
		$service  = $this->service();
		$relative = $this->relativePath( (int) $attachmentId );
		$metadata = wp_get_attachment_metadata( (int) $attachmentId );
		if ( null !== $service && null !== $relative && is_array( $metadata ) ) {
			$service->purge( $relative, $metadata );
		}
	}

	/**
	 * Asks for the registered sizes of a new attachment to be created in the background.
	 */
	public function schedule( $attachmentId ): void {
		$attachmentId = (int) $attachmentId;
		$enabled      = ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
		if ( ! apply_filters( 'wp_media_helper_background_thumbnails', $enabled, $attachmentId )
			|| ! wp_attachment_is_image( $attachmentId )
			|| false !== wp_next_scheduled( self::CRON_HOOK, [ $attachmentId ] ) ) {
			return;
		}

		// A few seconds apart, so a batch of imports does not start at the same moment.
		wp_schedule_single_event( time() + 10 + ( $attachmentId % 10 ) * 3, self::CRON_HOOK, [ $attachmentId ] );
	}

	public function generateInBackground( $attachmentId ): void {
		$attachmentId = (int) $attachmentId;
		$service      = $this->service();
		$relative     = $this->relativePath( $attachmentId );
		$metadata     = wp_get_attachment_metadata( $attachmentId );
		if ( null === $service || null === $relative || ! is_array( $metadata ) || ! $this->isRegisteredByUs( $attachmentId ) ) {
			return;
		}

		$deadline = microtime( true ) + self::BUDGET;
		$changed  = false;
		$complete = true;
		foreach ( array_keys( wp_get_registered_image_subsizes() ) as $size ) {
			if ( microtime( true ) >= $deadline ) {
				$complete = false;
				break;
			}
			$created = $this->createSize( $service, $attachmentId, $relative, $metadata, (string) $size );
			if ( null !== $created ) {
				$metadata = $created['metadata'];
				$changed  = true;
			}
		}

		if ( $changed ) {
			wp_update_attachment_metadata( $attachmentId, $metadata );
		}
		if ( ! $complete ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK, [ $attachmentId ] );
		}
	}

	/**
	 * Creates the file of a named size when it is smaller than the original and not there yet.
	 *
	 * @param array<string, mixed> $metadata
	 * @return array{entry: array<string, mixed>, metadata: array<string, mixed>}|null
	 */
	private function createSize( ThumbnailService $service, int $attachmentId, string $relative, array $metadata, string $size ): ?array {
		$definitions = wp_get_registered_image_subsizes();
		$width       = (int) ( $metadata['width'] ?? 0 );
		$height      = (int) ( $metadata['height'] ?? 0 );
		if ( ! isset( $definitions[ $size ] ) || $width < 1 || $height < 1 || $width * $height > apply_filters( 'wp_media_helper_thumbnail_max_pixels', self::MAX_PIXELS ) ) {
			return null;
		}

		$existing = $metadata['sizes'][ $size ] ?? null;
		if ( is_array( $existing ) && null !== $service->existing( $relative, $existing ) ) {
			return null;
		}

		$definition = $definitions[ $size ];
		$dimensions = image_resize_dimensions( $width, $height, (int) $definition['width'], (int) $definition['height'], $definition['crop'] );
		if ( ! is_array( $dimensions ) ) {
			// Not smaller than the original: WordPress falls back to the full image.
			return null;
		}

		$mimeType = (string) get_post_mime_type( $attachmentId );
		$entry    = $service->ensure( $relative, (int) $dimensions[4], (int) $dimensions[5], $definition['crop'], $mimeType );
		if ( null === $entry ) {
			return null;
		}

		$metadata['sizes']          = is_array( $metadata['sizes'] ?? null ) ? $metadata['sizes'] : [];
		$metadata['sizes'][ $size ] = $entry;

		return [ 'entry' => $entry, 'metadata' => $metadata ];
	}

	private function isRegisteredByUs( int $attachmentId ): bool {
		return '' !== (string) get_post_meta( $attachmentId, AttachmentRegistry::SOURCE_ID_META, true );
	}

	private function relativePath( int $attachmentId ): ?string {
		$relative = get_post_meta( $attachmentId, '_wp_attached_file', true );

		return is_string( $relative ) && '' !== $relative && ! str_starts_with( $relative, '/' ) ? $relative : null;
	}

	private function service(): ?ThumbnailService {
		if ( ! $this->serviceBuilt ) {
			$this->serviceBuilt = true;
			$this->service      = self::serviceForWordPress();
		}

		return $this->service;
	}

	/**
	 * The service for the uploads directory and the cache of this site, or null when
	 * there is no usable cache.
	 */
	public static function serviceForWordPress(): ?ThumbnailService {
		$uploads  = wp_upload_dir( null, false );
		$cacheDir = ThumbnailCache::directory();
		if ( null === $cacheDir || ! is_array( $uploads ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return null;
		}

		return new ThumbnailService( (string) ( realpath( (string) $uploads['basedir'] ) ?: $uploads['basedir'] ), (string) $uploads['baseurl'], $cacheDir, [ self::class, 'resize' ] );
	}

	/**
	 * Resizes with the WordPress image editor. The orientation of the photo is applied
	 * to the thumbnail only; the original is never written.
	 *
	 * @param bool|array<int, string> $crop
	 * @return array{0:int,1:int}|null
	 */
	public static function resize( string $source, string $destination, int $width, int $height, $crop ): ?array {
		$editor = wp_get_image_editor( $source );
		if ( is_wp_error( $editor ) ) {
			return null;
		}
		if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
			$editor->maybe_exif_rotate();
		}
		if ( is_wp_error( $editor->resize( $width, $height, $crop ) ) ) {
			return null;
		}
		$saved = $editor->save( $destination );

		return is_array( $saved ) && isset( $saved['width'], $saved['height'] ) ? [ (int) $saved['width'], (int) $saved['height'] ] : null;
	}
}
