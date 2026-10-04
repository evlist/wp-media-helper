<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\MediaSource;

/**
 * Registers a file of the uploads directory in the Media Library, the way
 * WordPress does, without copying, moving or creating any file.
 */
class AttachmentRegistrar {

	private AttachmentRegistry $registry;

	public function __construct( AttachmentRegistry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * @param string $sourceId      Source that owns the file.
	 * @param string $canonicalPath Canonical path of a regular file inside a source root.
	 * @param array<string, mixed>|null $source The source: its name pattern and modification-time setting give the date.
	 * @return int|\WP_Error Attachment ID.
	 */
	public function register( string $sourceId, string $canonicalPath, ?array $source = null ) {
		$uploads = $this->registry->uploads();
		$relative = $this->registry->relativeKey( $canonicalPath );
		if ( null === $uploads || null === $relative ) {
			return new \WP_Error( 'outside_uploads', __( 'Only files inside the WordPress uploads directory can be registered.', 'wp-media-helper' ) );
		}

		$basename = wp_basename( $canonicalPath );
		$filetype = wp_check_filetype( $basename );
		$blocker  = FileTypes::importBlocker( $basename, $filetype );
		if ( null !== $blocker ) {
			return new \WP_Error( 'file_type_not_allowed', $blocker );
		}
		$mimeType = (string) $filetype['type'];

		$this->loadAdminIncludes();
		$filesize = (int) filesize( $canonicalPath );
		$readMetadata = $this->readMetadata( $mimeType, $canonicalPath, $relative, $filesize );
		$mtime = filemtime( $canonicalPath );
		$date = AttachmentDate::resolve(
			$readMetadata['capture'],
			$readMetadata['media'],
			$basename,
			false === $mtime ? null : (int) $mtime,
			wp_timezone(),
			time(),
			$source
		);

		$attachmentId = wp_insert_attachment(
			[
				'post_title'     => (string) preg_replace( '/\.[^.]+$/', '', $basename ),
				'post_content'   => '',
				'post_status'    => 'inherit',
				'post_mime_type' => $mimeType,
				'guid'           => UploadsPath::url( $uploads['baseurl'], $relative ),
				'post_date'      => $date['local'],
				'post_date_gmt'  => $date['gmt'],
			],
			$relative,
			0,
			true
		);
		if ( is_wp_error( $attachmentId ) ) {
			return $attachmentId;
		}
		if ( empty( $attachmentId ) ) {
			return new \WP_Error( 'insert_attachment_failed', __( 'Unable to register the media attachment.', 'wp-media-helper' ) );
		}

		$attachmentId = (int) $attachmentId;
		// Core does not slash this value, which would drop a backslash legal in a file name.
		update_post_meta( $attachmentId, '_wp_attached_file', wp_slash( $relative ) );
		update_post_meta( $attachmentId, AttachmentRegistry::SOURCE_ID_META, wp_slash( $sourceId ) );
		update_post_meta( $attachmentId, AttachmentRegistry::SOURCE_PATH_META, wp_slash( $relative ) );
		wp_update_attachment_metadata( $attachmentId, $readMetadata['metadata'] );

		/**
		 * Fires once a file is registered, with the attachment ID. The files themselves
		 * (thumbnails) are created later, in the background or when asked for.
		 */
		do_action( 'wp_media_helper_attachment_registered', $attachmentId );

		return $attachmentId;
	}

	/**
	 * Reads metadata with the core functions that only read the file.
	 * `wp_generate_attachment_metadata()` is deliberately not used: it writes
	 * sub-sizes, and `-scaled` or `-rotated` copies, next to the original.
	 *
	 * @return array{metadata:array<string, mixed>, capture:int|null, media:int|null}
	 */
	private function readMetadata( string $mimeType, string $file, string $relative, int $filesize ): array {
		$capture = null;
		$media = null;
		$metadata = AttachmentMetadata::forFile( $filesize );

		if ( str_starts_with( $mimeType, 'image/' ) ) {
			$size = wp_getimagesize( $file );
			if ( is_array( $size ) && ! empty( $size[0] ) && ! empty( $size[1] ) ) {
				$imageMeta = wp_read_image_metadata( $file );
				$imageMeta = is_array( $imageMeta ) ? $imageMeta : [];
				$metadata = AttachmentMetadata::forImage( (int) $size[0], (int) $size[1], $relative, $filesize, $imageMeta );
				$capture = isset( $imageMeta['created_timestamp'] ) ? (int) $imageMeta['created_timestamp'] : null;
			}
		} elseif ( str_starts_with( $mimeType, 'video/' ) || str_starts_with( $mimeType, 'audio/' ) ) {
			$read = str_starts_with( $mimeType, 'video/' ) ? wp_read_video_metadata( $file ) : wp_read_audio_metadata( $file );
			if ( is_array( $read ) ) {
				$metadata = AttachmentMetadata::forMedia( $read, $filesize );
				$media = isset( $read['created_timestamp'] ) ? (int) $read['created_timestamp'] : null;
			}
		}

		return [ 'metadata' => $metadata, 'capture' => $capture, 'media' => $media ];
	}

	private function loadAdminIncludes(): void {
		if ( ! function_exists( 'wp_read_image_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		if ( ! function_exists( 'wp_read_video_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
	}
}
