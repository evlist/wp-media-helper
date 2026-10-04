<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Thumbnails;

use WP_Media_Helper\Admin\HiddenFiles;
use WP_Media_Helper\Admin\MediaPanelState;
use WP_Media_Helper\MediaSource\ImageDimensions;
use WP_Media_Helper\MediaSource\PathConfinement;
use WP_Media_Helper\MediaSource\UploadsPath;
use WP_Media_Helper\Settings\ActiveSources;

/**
 * Small previews of the files listed in the editor panel, imported or not.
 *
 * The previews are stored in the same cache and layout as the sizes of attachments. The list gives the URL of the
 * file in the cache when it is already there, and otherwise the URL of an
 * authenticated endpoint that creates it and returns it. Only images of an active
 * source, resolved on the server, can be requested, and only by users who may upload.
 * A browser loads the previews of the visible rows only (lazy loading).
 */
final class PanelThumbnail {

	public const ACTION = 'wp_media_helper_thumbnail';
	public const NONCE  = 'wp_media_helper_media_panel';

	/**
	 * An image no larger than this is sent as it is when it cannot be made smaller.
	 */
	private const MAX_ORIGINAL_BYTES = 1048576;

	public static function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, [ self::class, 'handle' ] );
	}

	/**
	 * Preview sizes: the long edge in pixels. The pictures keep their proportions and are
	 * never enlarged. `small` serves two or three images per row of the gallery, `large` one
	 * per row (and screens with a high pixel density).
	 */
	public const SIZES = [ 'small' => 320, 'large' => 640 ];

	/**
	 * The dimensions of a preview, or null when the image is not larger than the size.
	 *
	 * @return array{0:int, 1:int}|null
	 */
	public static function previewDimensions( int $width, int $height, int $longEdge ): ?array {
		if ( $width < 1 || $height < 1 || max( $width, $height ) <= $longEdge ) {
			return null;
		}

		$ratio = $longEdge / max( $width, $height );

		return [ max( 1, (int) round( $width * $ratio ) ), max( 1, (int) round( $height * $ratio ) ) ];
	}

	/**
	 * URLs of the previews of a listed image, one per size: the cached file when it exists,
	 * else the endpoint. Empty for files that are not images.
	 *
	 * @param string   $canonicalPath Path of a file of an active source, as listed.
	 * @param int|null $width         Dimensions as displayed, when the index has them.
	 * @return array<string, string>  `thumbnail_url` and `thumbnail_large_url`.
	 */
	public static function urlsFor( string $canonicalPath, string $nonce, ?ThumbnailService $service, ?int $width = null, ?int $height = null ): array {
		if ( 'image' !== MediaPanelState::resolveMediaType( basename( $canonicalPath ) ) ) {
			return [];
		}

		$uploads  = wp_upload_dir( null, false );
		$relative = is_array( $uploads ) ? UploadsPath::relativeKey( $canonicalPath, (string) ( $uploads['basedir'] ?? '' ) ) : null;

		$urls = [];
		foreach ( self::SIZES as $name => $edge ) {
			$url = null;
			if ( null !== $service && null !== $relative && null !== $width && null !== $height ) {
				$dimensions = self::previewDimensions( $width, $height, $edge );
				$cached     = null === $dimensions ? null : $service->existingSize( $relative, $dimensions[0], $dimensions[1] );
				$url        = null === $cached ? null : $service->url( $cached );
			}

			$urls[ 'small' === $name ? 'thumbnail_url' : 'thumbnail_large_url' ] = $url ?? add_query_arg(
				[ 'action' => self::ACTION, 'nonce' => $nonce, 'size' => $name, 'path' => $canonicalPath ],
				admin_url( 'admin-ajax.php' )
			);
		}

		return $urls;
	}

	/**
	 * Creates the preview of a file if needed and sends it.
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			self::fail( 403 );
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['nonce'] ?? '' ) ), self::NONCE ) ) {
			self::fail( 403 );
		}

		// The path comes from the browser: it must belong to an active source.
		$path     = wp_unslash( $_GET['path'] ?? '' );
		$confined = is_string( $path ) ? PathConfinement::resolveFileInSources( ActiveSources::all(), '', $path ) : null;
		if ( null === $confined || 'image' !== MediaPanelState::resolveMediaType( basename( $confined['path'] ) ) ) {
			self::fail( 404 );
		}

		$file = $confined['path'];
		// A hidden file has no preview, and none is made.
		if ( HiddenFiles::isHidden( $file ) ) {
			self::fail( 404 );
		}
		$size = sanitize_key( wp_unslash( $_GET['size'] ?? 'small' ) );
		$send = self::previewFile( $file, self::SIZES[ $size ] ?? self::SIZES['small'] );
		if ( null === $send ) {
			self::fail( 404 );
		}

		// Raster images only: an SVG sent inline would run script in the site's origin.
		$type = wp_check_filetype( $send['path'] );
		if ( empty( $type['type'] ) || ! str_starts_with( (string) $type['type'], 'image/' ) || str_contains( (string) $type['type'], 'svg' ) ) {
			self::fail( 404 );
		}

		nocache_headers();
		header( 'Cache-Control: private, max-age=3600' );
		header( 'Content-Type: ' . $type['type'] );
		header( 'Content-Length: ' . (string) filesize( $send['path'] ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; sandbox" );
		readfile( $send['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * The file to send for a source file: its preview in the cache (created when
	 * missing), or the file itself when it is too small to be reduced and light.
	 *
	 * @return array{path:string}|null
	 */
	private static function previewFile( string $file, int $longEdge ): ?array {
		$read = ImageDimensions::reader()( $file );
		if ( null === $read ) {
			return null;
		}

		[ $width, $height ] = $read;
		if ( $width * $height > apply_filters( 'wp_media_helper_thumbnail_max_pixels', Thumbnails::MAX_PIXELS ) || ! Thumbnails::fitsInMemory( $width, $height ) ) {
			return null;
		}

		$target = self::previewDimensions( $width, $height, $longEdge );
		if ( null === $target ) {
			return filesize( $file ) <= self::MAX_ORIGINAL_BYTES ? [ 'path' => $file ] : null;
		}

		$service  = Thumbnails::serviceForWordPress();
		$uploads  = wp_upload_dir( null, false );
		$relative = is_array( $uploads ) ? UploadsPath::relativeKey( $file, (string) ( $uploads['basedir'] ?? '' ) ) : null;
		if ( null === $service || null === $relative ) {
			return null;
		}

		$entry = $service->ensure( $relative, $target[0], $target[1], false, (string) ( wp_check_filetype( $file )['type'] ?? '' ) );
		$path  = null === $entry ? null : $service->existing( $relative, $entry );

		return null === $path ? null : [ 'path' => $path ];
	}

	private static function fail( int $status ): void {
		status_header( $status );
		exit;
	}
}
