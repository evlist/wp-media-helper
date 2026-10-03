<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\MediaSource;

/**
 * Builds the `_wp_attachment_metadata` of a registered file without creating
 * any file: the keys core writes for the file itself, an empty `sizes`, and
 * no absolute path.
 */
class AttachmentMetadata {

	/**
	 * @param array<string, mixed> $imageMeta As returned by wp_read_image_metadata().
	 * @return array<string, mixed>
	 */
	public static function forImage( int $width, int $height, string $relative, int $filesize, array $imageMeta ): array {
		return [
			'width'      => $width,
			'height'     => $height,
			'file'       => $relative,
			'filesize'   => $filesize,
			'sizes'      => [],
			'image_meta' => $imageMeta,
		];
	}

	/**
	 * Audio and video: what the core readers return, plus the file size.
	 *
	 * @param array<string, mixed> $readerMetadata
	 * @return array<string, mixed>
	 */
	public static function forMedia( array $readerMetadata, int $filesize ): array {
		return array_merge( $readerMetadata, [ 'filesize' => $filesize ] );
	}

	/**
	 * Any other file type.
	 *
	 * @return array<string, mixed>
	 */
	public static function forFile( int $filesize ): array {
		return [ 'filesize' => $filesize ];
	}
}
