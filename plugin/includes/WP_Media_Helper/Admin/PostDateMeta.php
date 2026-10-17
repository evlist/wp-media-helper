<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

class PostDateMeta {

	public const META_KEY = 'wp_media_helper_date';

	private const POST_TYPES = [ 'post', 'page' ];

	public function __construct() {
		add_action( 'init', [ $this, 'register' ], 99 );
		add_filter( 'is_protected_meta', [ self::class, 'protect' ], 10, 2 );
	}

	/**
	 * Hides the key from the Custom Fields box of the classic editor and makes saving a post ignore it. That box lists every key that
	 * does not start with an underscore, and its form, loaded with the old day, writes it back whenever the post is saved (the block
	 * editor sends the legacy boxes along with each save).
	 *
	 * @param mixed $protected
	 */
	public static function protect( $protected, $metaKey ): bool {
		return self::META_KEY === $metaKey ? true : (bool) $protected;
	}

	/**
	 * A calendar day written Y-m-d, or an empty string.
	 *
	 * @param mixed $value
	 */
	public static function clean( $value ): string {
		return is_string( $value ) && 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $day ) && checkdate( (int) $day[2], (int) $day[3], (int) $day[1] ) ? $value : '';
	}

	public function register(): void {
		$postTypes = array_unique( array_merge(
			self::POST_TYPES,
			get_post_types( [ 'show_in_rest' => true ] )
		) );

		foreach ( $postTypes as $postType ) {
			if ( ! post_type_supports( $postType, 'custom-fields' ) ) {
				continue;
			}

			register_post_meta(
				$postType,
				self::META_KEY,
				[
					'type' => 'string',
					'single' => true,
					'default' => '',
					// Only a calendar day (or nothing) is stored, whatever the client sends.
					'sanitize_callback' => [ self::class, 'clean' ],
					'show_in_rest' => true,
					'auth_callback' => static function ( bool $allowed, string $metaKey, int $postId ): bool {
						return current_user_can( 'edit_post', $postId );
					},
				]
			);
		}
	}
}
