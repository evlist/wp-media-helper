<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

class PostDateMeta {

	public const META_KEY = 'wp_media_helper_date';

	private const POST_TYPES = [ 'post', 'page' ];

	public function __construct() {
		add_action( 'init', [ $this, 'register' ] );
	}

	public function register(): void {
		foreach ( self::POST_TYPES as $postType ) {
			register_post_meta(
				$postType,
				self::META_KEY,
				[
					'type' => 'string',
					'single' => true,
					'default' => '',
					'show_in_rest' => true,
					'auth_callback' => static function ( bool $allowed, string $metaKey, int $postId ): bool {
						return current_user_can( 'edit_post', $postId );
					},
				]
			);
		}
	}
}
