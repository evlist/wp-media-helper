<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Thumbnails;

/**
 * The attachments that a post's content uses, found in its text: `wp-image-<id>` classes, the `id` of image, gallery, video, cover and
 * media-text blocks, the `ids` of galleries (blocks and `[gallery ids="…"]`). The featured image and the attached children are the
 * caller's business (they are not in the text).
 */
final class PostImages {

	/**
	 * @return int[] Sorted, without duplicates.
	 */
	public static function ids( string $content ): array {
		$ids = [];

		if ( preg_match_all( '/\bwp-image-(\d+)\b/', $content, $found ) ) {
			$ids = array_merge( $ids, $found[1] );
		}
		if ( preg_match_all( '/<!--\s*wp:(?:core\/)?(?:image|gallery|video|cover|media-text|audio|file)\b\s*(\{.*?\})\s*(?:\/)?-->/s', $content, $blocks ) ) {
			foreach ( $blocks[1] as $json ) {
				$attributes = json_decode( $json, true );
				if ( ! is_array( $attributes ) ) {
					continue;
				}
				foreach ( [ 'id', 'mediaId', 'posterId' ] as $key ) {
					if ( isset( $attributes[ $key ] ) && is_numeric( $attributes[ $key ] ) ) {
						$ids[] = $attributes[ $key ];
					}
				}
				if ( isset( $attributes['ids'] ) && is_array( $attributes['ids'] ) ) {
					$ids = array_merge( $ids, $attributes['ids'] );
				}
			}
		}
		if ( preg_match_all( '/\[gallery\b[^\]]*\bids\s*=\s*["\']?([0-9,\s]+)/i', $content, $galleries ) ) {
			foreach ( $galleries[1] as $list ) {
				$ids = array_merge( $ids, explode( ',', $list ) );
			}
		}

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn ( int $id ): bool => $id > 0 ) ) );
		sort( $ids );

		return $ids;
	}
}
