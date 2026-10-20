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
 *   sizes within a time budget, and reschedules itself while sizes remain. The event is
 *   scheduled even with `DISABLE_WP_CRON` (a system cron runs it); the filter
 *   `wp_media_helper_background_thumbnails` turns it off.
 */
final class Thumbnails {

	public const CRON_HOOK    = 'wp_media_helper_generate_thumbnails';
	public const CATCH_UP_HOOK = 'wp_media_helper_catch_up_thumbnails';
	public const FINGERPRINT_OPTION = 'wp_media_helper_sizes_fingerprint';
	private const CATCH_UP_BATCH = 100;
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
		add_action( 'wp_loaded', [ $this, 'checkDefinitions' ] );
		add_action( self::CATCH_UP_HOOK, [ $this, 'catchUp' ] );
		add_filter( 'rest_prepare_attachment', [ $this, 'restSizes' ], 10, 3 );
	}

	/**
	 * @param mixed      $out
	 * @param int        $attachmentId
	 * @param string|int[] $size
	 * @return mixed
	 */
	public function downsize( $out, $attachmentId, $size ) {
		if ( false !== $out || 'full' === $size || ( ! is_string( $size ) && ! is_array( $size ) ) ) {
			return $out;
		}

		$service = $this->service();
		$attachmentId = (int) $attachmentId;
		$relative = $this->relativePath( $attachmentId );
		$metadata = wp_get_attachment_metadata( $attachmentId );
		if ( null === $service || null === $relative || ! is_array( $metadata ) ) {
			return $out;
		}

		// A size given as `[ width, height ]` (the thumbnails of the media library, 60 x 60): WordPress chooses a size of the
		// metadata by its dimensions and then builds the address next to the original, ignoring the cache. The size chosen is
		// found again by its file name and handled as a named size.
		if ( is_array( $size ) ) {
			$intermediate = image_get_intermediate_size( $attachmentId, $size );
			$name         = is_array( $intermediate ) && isset( $intermediate['file'] ) ? ThumbnailLayout::sizeNameForFile( $metadata, (string) $intermediate['file'] ) : null;
			// An attachment just imported has no sizes in its metadata yet (they are made in the background): the size is chosen
			// among the registered ones, as WordPress would, and made now. Without it the list of the media library shows the
			// whole picture, squeezed, until the sizes exist.
			if ( null === $name && $this->isRegisteredByUs( $attachmentId ) && [] === (array) ( $metadata['sizes'] ?? [] ) ) {
				$name = ThumbnailLayout::sizeNameForDimensions( wp_get_registered_image_subsizes(), (int) ( $size[0] ?? 0 ), (int) ( $size[1] ?? 0 ) );
			}
			if ( null === $name ) {
				return $out;
			}
			$size = $name;
		}

		$entry   = $metadata['sizes'][ $size ] ?? null;
		$replace = false;
		$current = null;
		if ( is_array( $entry ) ) {
			$path = $service->existing( $relative, $entry );
			$url  = null === $path ? null : $service->url( $path );
			if ( null !== $url ) {
				$current = [ $url, (int) ( $entry['width'] ?? 0 ), (int) ( $entry['height'] ?? 0 ), true ];
				// A size of a file registered here whose definition changed since it was made (another page format, say) is made again.
				if ( ! $this->isRegisteredByUs( $attachmentId ) || ! $this->isStale( $metadata, (string) $size, $entry ) ) {
					return $current;
				}
				$replace = true;
			}
		}

		// Attachments made by another tool are left alone, unless the file that their metadata lists is not
		// beside the original: WordPress would then give the address of a file that does not exist.
		if ( ! $this->isRegisteredByUs( $attachmentId ) && ! ( is_array( $entry ) && $this->isMissingBesideOriginal( $relative, $entry ) ) ) {
			return $out;
		}

		$created = $this->createSize( $service, $attachmentId, $relative, $metadata, $size, $replace );
		if ( null === $created ) {
			// The old file is better than none.
			return $replace ? $current : $out;
		}

		wp_update_attachment_metadata( $attachmentId, $created['metadata'] );
		$entry = $created['entry'];
		$path  = $service->existing( $relative, $entry );
		$url   = null === $path ? null : $service->url( $path );

		return null === $url ? $out : [ $url, (int) $entry['width'], (int) $entry['height'], true ];
	}

	/**
	 * Whether the dimensions of an entry of the metadata differ from what the current definition of the size gives.
	 *
	 * @param array<string, mixed> $metadata
	 * @param array<string, mixed> $entry
	 */
	private function isStale( array $metadata, string $size, array $entry ): bool {
		$definition = wp_get_registered_image_subsizes()[ $size ] ?? null;
		if ( ! is_array( $definition ) ) {
			return false;
		}
		$expected = ThumbnailPlan::expected( (int) ( $metadata['width'] ?? 0 ), (int) ( $metadata['height'] ?? 0 ), $definition );

		return null !== $expected && ( (int) ( $entry['width'] ?? 0 ) !== $expected['width'] || (int) ( $entry['height'] ?? 0 ) !== $expected['height'] );
	}

	/**
	 * Notices that the definitions of the image sizes changed since the last look (a size added, removed or redefined: another page
	 * format, a theme or plugin update) and plans a catch-up of the images in batches. The first look only records them: for what
	 * exists already, `wp media-helper thumbnails generate` does it by hand. The filter `wp_media_helper_catch_up_thumbnails` turns this off.
	 */
	public function checkDefinitions(): void {
		$current = ThumbnailPlan::fingerprint( wp_get_registered_image_subsizes() );
		$known   = get_option( self::FINGERPRINT_OPTION, '' );
		if ( $known === $current ) {
			return;
		}
		update_option( self::FINGERPRINT_OPTION, $current );
		if ( '' === $known || ! apply_filters( 'wp_media_helper_catch_up_thumbnails', true ) ) {
			return;
		}
		if ( false === wp_next_scheduled( self::CATCH_UP_HOOK, [ 0 ] ) ) {
			wp_schedule_single_event( time() + 10, self::CATCH_UP_HOOK, [ 0 ] );
		}
	}

	/**
	 * A batch of the catch-up: the images after `$after`, by id, are given what they miss (the sizes they do not have, and those whose
	 * definition changed) until the time budget is used, then the event schedules itself again where it stopped.
	 *
	 * @param int $after The last attachment done.
	 */
	public function catchUp( $after = 0 ): void {
		global $wpdb;

		$after    = (int) $after;
		$deadline = microtime( true ) + self::BUDGET;
		$more     = true;
		while ( $more && microtime( true ) < $deadline ) {
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%%' AND ID > %d ORDER BY ID ASC LIMIT %d",
				$after,
				self::CATCH_UP_BATCH
			) ) );
			if ( [] === $ids ) {
				return;
			}
			foreach ( $ids as $id ) {
				if ( microtime( true ) >= $deadline ) {
					break 2;
				}
				$plan = $this->sizesToMake( $id );
				if ( null !== $plan && ( [] !== $plan['missing'] || [] !== $plan['stale'] ) ) {
					$report = $this->makeSizes( $id, $plan['missing'], $plan['stale'], $deadline );
					if ( [] !== $report['left'] ) {
						// The budget ended in the middle of this image: it is looked at again.
						break 2;
					}
				}
				$after = $id;
			}
			$more = count( $ids ) === self::CATCH_UP_BATCH;
		}

		if ( $more || microtime( true ) >= $deadline ) {
			wp_schedule_single_event( time() + 5, self::CATCH_UP_HOOK, [ $after ] );
		}
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
		// Scheduled whatever `DISABLE_WP_CRON` says: that constant only stops WP-Cron from starting at page views, a system cron
		// still runs the events, and an event nobody runs just waits. The filter turns the background creation off.
		if ( ! apply_filters( 'wp_media_helper_background_thumbnails', true, $attachmentId )
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

	/**
	 * The named sizes that an attachment registered by this plugin still needs: those it has not (`missing`) and, when asked, those it has
	 * with other dimensions than the current definition gives (`stale`). Null when the attachment is not concerned (another tool's, not an
	 * image, no usable cache).
	 *
	 * @param string[]|null $names Only these sizes (null: all the registered ones).
	 * @return array{missing: string[], stale: string[]}|null
	 */
	public function sizesToMake( int $attachmentId, ?array $names = null, bool $includeStale = true ): ?array {
		$service  = $this->service();
		$relative = $this->relativePath( $attachmentId );
		$metadata = wp_get_attachment_metadata( $attachmentId );
		if ( null === $service || null === $relative || ! is_array( $metadata ) || ! $this->isRegisteredByUs( $attachmentId ) || ! wp_attachment_is_image( $attachmentId ) ) {
			return null;
		}

		$plan = ThumbnailPlan::classify(
			$metadata,
			wp_get_registered_image_subsizes(),
			$names,
			static fn ( string $name, array $entry ): bool => null !== $service->existing( $relative, $entry )
		);

		return [ 'missing' => $plan['missing'], 'stale' => $includeStale ? $plan['stale'] : [] ];
	}

	/**
	 * Makes the given sizes of an attachment (the stale ones are made again at their new dimensions; the files of the old ones stay in
	 * the cache until the attachment is deleted). Stops at the deadline, if there is one.
	 *
	 * @param string[] $missing
	 * @param string[] $stale
	 * @return array{made: string[], failed: string[], left: string[]} `left`: what the deadline kept from being tried.
	 */
	public function makeSizes( int $attachmentId, array $missing, array $stale = [], ?float $deadline = null ): array {
		$report   = [ 'made' => [], 'failed' => [], 'left' => [] ];
		$service  = $this->service();
		$relative = $this->relativePath( $attachmentId );
		$metadata = wp_get_attachment_metadata( $attachmentId );
		if ( null === $service || null === $relative || ! is_array( $metadata ) ) {
			return [ 'made' => [], 'failed' => array_merge( $missing, $stale ), 'left' => [] ];
		}

		$changed = false;
		$todo    = array_merge( array_map( static fn ( string $name ): array => [ $name, false ], $missing ), array_map( static fn ( string $name ): array => [ $name, true ], $stale ) );
		foreach ( $todo as $index => [ $name, $replace ] ) {
			if ( null !== $deadline && microtime( true ) >= $deadline ) {
				$report['left'] = array_map( static fn ( array $entry ): string => $entry[0], array_slice( $todo, $index ) );
				break;
			}
			// A run asked for by hand tries again what failed a moment ago.
			delete_transient( 'wp_media_helper_thumb_failed_' . $attachmentId . '_' . $name );
			$created = $this->createSize( $service, $attachmentId, $relative, $metadata, $name, $replace );
			if ( null === $created ) {
				$report['failed'][] = $name;
				continue;
			}
			$metadata         = $created['metadata'];
			$report['made'][] = $name;
			$changed          = true;
		}

		if ( $changed ) {
			wp_update_attachment_metadata( $attachmentId, $metadata );
		}

		return $report;
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
	private function createSize( ThumbnailService $service, int $attachmentId, string $relative, array $metadata, string $size, bool $replace = false ): ?array {
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
		if ( ! $replace && is_array( $existing ) && null !== $service->existing( $relative, $existing ) ) {
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
