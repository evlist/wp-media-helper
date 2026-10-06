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
	public const FAILURE_TTL  = 600;

	/**
	 * Whether an image of this size can probably be decoded within the memory limit. The
	 * image editors hold the decoded picture (about four bytes a pixel) and a resized copy; a
	 * fatal error on a page view, repeated at every view, is worse than a missing thumbnail.
	 */
	public static function fitsInMemory( int $width, int $height ): bool {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		$limit = self::memoryLimitBytes();
		if ( $limit < 0 ) {
			return true;
		}

		$factor = (int) apply_filters( 'wp_media_helper_thumbnail_memory_factor', 6 );

		return $width * $height * max( 1, $factor ) + memory_get_usage( true ) <= $limit;
	}

	private static function memoryLimitBytes(): int {
		$value = trim( (string) ini_get( 'memory_limit' ) );
		if ( '' === $value || '-1' === $value ) {
			return -1;
		}

		$number = (int) $value;
		switch ( strtolower( substr( $value, -1 ) ) ) {
			case 'g':
				return $number * 1073741824;
			case 'm':
				return $number * 1048576;
			case 'k':
				return $number * 1024;
		}

		return $number;
	}

	private ?ThumbnailService $service = null;
	private bool $serviceBuilt         = false;

	public function __construct() {
		// Before other thumbnail plugins, so that the sizes of what this plugin registered are ours.
		add_filter( 'image_downsize', [ $this, 'downsize' ], 5, 3 );
		// After all of them: an address that is not absolute cannot work in a page.
		add_filter( 'image_downsize', [ $this, 'absolutize' ], 99, 3 );
		add_filter( 'wp_calculate_image_srcset', [ $this, 'srcset' ], 10, 5 );
		add_action( 'delete_attachment', [ $this, 'purge' ] );
		add_action( 'wp_media_helper_attachment_registered', [ $this, 'schedule' ] );
		add_action( self::CRON_HOOK, [ $this, 'generateInBackground' ] );
		add_filter( 'rest_prepare_attachment', [ $this, 'restSizes' ], 10, 3 );
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

		// Attachments made by another tool are left alone, unless the file that their metadata lists is not
		// beside the original: WordPress would then give the address of a file that does not exist.
		if ( ! $this->isRegisteredByUs( $attachmentId ) && ! ( is_array( $entry ) && $this->isMissingBesideOriginal( $relative, $entry ) ) ) {
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
	 * Turns an address given as a path relative to uploads, which a browser resolves against the
	 * address of the page, into the address of the file.
	 *
	 * @param mixed $out
	 * @return mixed
	 */
	public function absolutize( $out, $attachmentId = 0, $size = '' ) {
		if ( ! is_array( $out ) || ! isset( $out[0] ) || ! is_string( $out[0] ) || '' === $out[0]
			|| 1 === preg_match( '#^([a-z][a-z0-9+.-]*:|/)#i', $out[0] ) ) {
			return $out;
		}

		$service = $this->service();
		$url     = null === $service ? null : $service->urlForRelative( $out[0] );
		if ( null !== $url ) {
			$out[0] = $url;
		}

		return $out;
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

	/**
	 * The editor shows a picture from the sizes that the REST answer lists (the featured image
	 * panel uses them). A file registered by this plugin has none until they are made, so the
	 * panel would load the full original: for the one attachment asked for, the sizes are made now
	 * and added to the answer.
	 *
	 * @param mixed $response
	 * @param mixed $post
	 * @param mixed $request
	 * @return mixed
	 */
	public function restSizes( $response, $post, $request ) {
		if ( ! $response instanceof \WP_REST_Response || ! $post instanceof \WP_Post || ! $request instanceof \WP_REST_Request || ! $request->get_param( 'id' )
			|| ! wp_attachment_is_image( $post->ID ) || ! $this->isRegisteredByUs( $post->ID ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $response;
		}

		$metadata = wp_get_attachment_metadata( $post->ID );
		if ( ! is_array( $metadata ) || ! empty( $metadata['sizes'] ) ) {
			return $response;
		}

		$this->generateInBackground( $post->ID );
		$metadata = wp_get_attachment_metadata( $post->ID );
		$data     = $response->get_data();
		if ( ! is_array( $metadata ) || empty( $metadata['sizes'] ) || ! is_array( $data ) || ! isset( $data['media_details'] ) || ! is_array( $data['media_details'] ) ) {
			return $response;
		}

		$sizes = is_array( $data['media_details']['sizes'] ?? null ) ? $data['media_details']['sizes'] : [];
		foreach ( $metadata['sizes'] as $name => $entry ) {
			$source = wp_get_attachment_image_src( $post->ID, (string) $name );
			if ( ! is_array( $entry ) || ! is_array( $source ) ) {
				continue;
			}
			$sizes[ $name ] = [
				'file'       => (string) ( $entry['file'] ?? '' ),
				'width'      => (int) ( $entry['width'] ?? 0 ),
				'height'     => (int) ( $entry['height'] ?? 0 ),
				'mime_type'  => (string) ( $entry['mime-type'] ?? '' ),
				'source_url' => $source[0],
			];
		}
		$data['media_details']['sizes'] = $sizes;
		$response->set_data( $data );

		return $response;
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

		// A size that just failed is not tried again at every view of the page.
		$failureKey = 'wp_media_helper_thumb_failed_' . $attachmentId . '_' . $size;
		if ( false !== get_transient( $failureKey ) ) {
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
		if ( ! self::fitsInMemory( $width, $height ) ) {
			set_transient( $failureKey, 1, self::FAILURE_TTL );

			return null;
		}

		$entry = $service->ensure( $relative, (int) $dimensions[4], (int) $dimensions[5], $definition['crop'], $mimeType );
		if ( null === $entry ) {
			set_transient( $failureKey, 1, self::FAILURE_TTL );

			return null;
		}

		$metadata['sizes']          = is_array( $metadata['sizes'] ?? null ) ? $metadata['sizes'] : [];
		$metadata['sizes'][ $size ] = $entry;

		return [ 'entry' => $entry, 'metadata' => $metadata ];
	}

	/**
	 * @param array<string, mixed> $entry A size of the attachment metadata.
	 */
	private function isMissingBesideOriginal( string $relative, array $entry ): bool {
		$uploads = wp_upload_dir( null, false );
		$name    = isset( $entry['file'] ) && is_string( $entry['file'] ) ? $entry['file'] : '';
		if ( ! is_array( $uploads ) || empty( $uploads['basedir'] ) || '' === $name || $name !== basename( $name ) ) {
			return false;
		}
		$directory = dirname( $relative );

		return ! file_exists( rtrim( (string) $uploads['basedir'], '/' ) . ( '.' === $directory ? '' : '/' . $directory ) . '/' . $name );
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
