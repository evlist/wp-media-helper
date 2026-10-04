<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\MediaSource;

/**
 * Reads dates written inside files.
 */
final class EmbeddedDates {

	/**
	 * The capture date of a photo, read with the core reader (IPTC creation date, else
	 * EXIF digitized/original time). Like core, it returns the local clock time as if it
	 * were UTC, since EXIF has no time zone.
	 *
	 * @return callable(string): ?int
	 */
	public static function imageReader(): callable {
		return static function ( string $path ): ?int {
			if ( ! function_exists( 'wp_read_image_metadata' ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}

			$meta = wp_read_image_metadata( $path );
			$time = is_array( $meta ) ? (int) ( $meta['created_timestamp'] ?? 0 ) : 0;

			return $time > 0 ? $time : null;
		};
	}
}
