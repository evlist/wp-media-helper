<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\MediaSource;

/**
 * The size of an image as it is displayed, so the panel can lay thumbnails out with
 * their real proportions before loading them.
 */
final class ImageDimensions {

	/**
	 * Extensions whose header is read (an SVG has no pixel size).
	 */
	public const EXTENSIONS = [ 'jpg', 'jpeg', 'png', 'gif', 'webp' ];

	public static function isReadable( string $name ): bool {
		return in_array( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ), self::EXTENSIONS, true );
	}

	/**
	 * Swaps width and height for the EXIF orientations that turn the picture by a quarter
	 * (5 to 8), which the thumbnails apply and a viewer shows.
	 *
	 * @return array{0:int, 1:int}
	 */
	public static function oriented( int $width, int $height, int $orientation ): array {
		return $orientation >= 5 && $orientation <= 8 ? [ $height, $width ] : [ $width, $height ];
	}

	/**
	 * Reads the dimensions of an image file: the header only, never the whole picture.
	 *
	 * @return callable(string): ?array{0:int, 1:int}
	 */
	public static function reader(): callable {
		return static function ( string $path ): ?array {
			$size = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) ) {
				return null;
			}

			$orientation = 0;
			if ( IMAGETYPE_JPEG === ( $size[2] ?? 0 ) && function_exists( 'exif_read_data' ) ) {
				$exif        = @exif_read_data( $path, 'IFD0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$orientation = is_array( $exif ) ? (int) ( $exif['Orientation'] ?? 0 ) : 0;
			}

			return self::oriented( (int) $size[0], (int) $size[1], $orientation );
		};
	}
}
