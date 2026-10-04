<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Thumbnails;

use WP_Media_Helper\Admin\MediaPanelState;
use WP_Media_Helper\MediaSource\PathConfinement;
use WP_Media_Helper\MediaSource\UploadsPath;
use WP_Media_Helper\Settings\ActiveSources;

/**
 * Small previews of the files listed in the editor panel, imported or not.
 *
 * The preview is the `thumbnail` size, stored in the same cache and layout as the
 * sizes of attachments, so importing a file reuses it. The list gives the URL of the
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
	 * The `thumbnail` size as defined by the site: width, height and whether it crops.
	 *
	 * @return array{width:int, height:int, crop:bool|array<int,string>}
	 */
	public static function size(): array {
		$sizes = function_exists( 'wp_get_registered_image_subsizes' ) ? wp_get_registered_image_subsizes() : [];
		$thumb = $sizes['thumbnail'] ?? [ 'width' => 150, 'height' => 150, 'crop' => true ];

		return [ 'width' => max( 1, (int) $thumb['width'] ), 'height' => max( 1, (int) $thumb['height'] ), 'crop' => $thumb['crop'] ];
	}

	/**
	 * URL of the preview of a listed file: the cached file when it exists, else the endpoint.
	 * Null for files that are not images.
	 *
	 * @param string $canonicalPath Path of a file of an active source, as listed.
	 */
	public static function urlFor( string $canonicalPath, string $nonce, ?ThumbnailService $service ): ?string {
		if ( 'image' !== MediaPanelState::resolveMediaType( basename( $canonicalPath ) ) ) {
			return null;
		}

		$uploads  = wp_upload_dir( null, false );
		$relative = is_array( $uploads ) ? UploadsPath::relativeKey( $canonicalPath, (string) ( $uploads['basedir'] ?? '' ) ) : null;
		if ( null !== $service && null !== $relative ) {
			$size   = self::size();
			$cached = $service->existingSize( $relative, $size['width'], $size['height'] );
			$url    = null === $cached ? null : $service->url( $cached );
			if ( null !== $url ) {
				return $url;
			}
		}

		return add_query_arg(
			[ 'action' => self::ACTION, 'nonce' => $nonce, 'path' => $canonicalPath ],
			admin_url( 'admin-ajax.php' )
		);
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
		$send = self::previewFile( $file );
		if ( null === $send ) {
			self::fail( 404 );
		}

		$type = wp_check_filetype( $send['path'] );
		if ( empty( $type['type'] ) || ! str_starts_with( (string) $type['type'], 'image/' ) ) {
			self::fail( 404 );
		}

		nocache_headers();
		header( 'Cache-Control: private, max-age=3600' );
		header( 'Content-Type: ' . $type['type'] );
		header( 'Content-Length: ' . (string) filesize( $send['path'] ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $send['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * The file to send for a source file: its preview in the cache (created when
	 * missing), or the file itself when it is too small to be reduced and light.
	 *
	 * @return array{path:string}|null
	 */
	private static function previewFile( string $file ): ?array {
		$size = self::size();
		$dims = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $file ) : getimagesize( $file );
		if ( ! is_array( $dims ) || empty( $dims[0] ) || empty( $dims[1] ) ) {
			return null;
		}

		$width  = (int) $dims[0];
		$height = (int) $dims[1];
		if ( $width * $height > apply_filters( 'wp_media_helper_thumbnail_max_pixels', Thumbnails::MAX_PIXELS ) ) {
			return null;
		}

		$resized = image_resize_dimensions( $width, $height, $size['width'], $size['height'], $size['crop'] );
		if ( ! is_array( $resized ) ) {
			return filesize( $file ) <= self::MAX_ORIGINAL_BYTES ? [ 'path' => $file ] : null;
		}

		$service  = Thumbnails::serviceForWordPress();
		$uploads  = wp_upload_dir( null, false );
		$relative = is_array( $uploads ) ? UploadsPath::relativeKey( $file, (string) ( $uploads['basedir'] ?? '' ) ) : null;
		if ( null === $service || null === $relative ) {
			return null;
		}

		$entry = $service->ensure( $relative, (int) $resized[4], (int) $resized[5], $size['crop'], (string) ( wp_check_filetype( $file )['type'] ?? '' ) );
		$path  = null === $entry ? null : $service->existing( $relative, $entry );

		return null === $path ? null : [ 'path' => $path ];
	}

	private static function fail( int $status ): void {
		status_header( $status );
		exit;
	}
}
