<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Attachment;

/**
 * The registered attachment methods and the active one (slice 042).
 */
final class Methods {

	public const OPTION = 'wp_media_helper_attachment_method';

	public const COHABITATION_OPTION = 'wp_media_helper_attachment_cohabitation';

	/**
	 * Every method: `native` and those added with the filter `wp_media_helper_attachment_methods`.
	 * Anything that is not a `Method` registered under its own identifier is ignored.
	 *
	 * @return array<string, Method>
	 */
	public static function all(): array {
		$native  = new NativeMethod();
		$methods = [ $native->id() => $native ];

		$added = function_exists( 'apply_filters' ) ? apply_filters( 'wp_media_helper_attachment_methods', $methods ) : $methods;
		if ( ! is_array( $added ) ) {
			return $methods;
		}

		foreach ( $added as $key => $method ) {
			if ( $method instanceof Method && (string) $key === $method->id() && '' !== $method->id() ) {
				$methods[ $method->id() ] = $method;
			}
		}

		// The built-in method cannot be replaced: it is what the site falls back to.
		$methods[ $native->id() ] = $native;

		return $methods;
	}

	/**
	 * The method in use for a post: the setting, which the filter `wp_media_helper_attachment_method`
	 * may change (arguments: the identifier, the ID of the post, its type). Falls back to `native`
	 * when the one chosen is not registered (a plugin was deactivated).
	 */
	public static function active( int $postId = 0 ): Method {
		$methods = self::all();
		$id      = function_exists( 'get_option' ) ? (string) get_option( self::OPTION, NativeMethod::ID ) : NativeMethod::ID;

		if ( function_exists( 'apply_filters' ) ) {
			$type = $postId > 0 && function_exists( 'get_post_type' ) ? (string) get_post_type( $postId ) : '';
			$id   = (string) apply_filters( 'wp_media_helper_attachment_method', $id, $postId, $type );
		}

		return $methods[ $id ] ?? $methods[ NativeMethod::ID ];
	}

	/**
	 * Whether both mechanisms are in use for a post: the attachments made the WordPress way (`post_parent` only) count as attached,
	 * and a media item can be switched from one mechanism to the other. Only with a method other than the native one. The
	 * setting may be changed by the filter `wp_media_helper_attachment_cohabitation` (the ID of the post is the second argument).
	 */
	public static function cohabitation( int $postId = 0 ): bool {
		if ( NativeMethod::ID === self::active( $postId )->id() ) {
			return false;
		}

		$on = function_exists( 'get_option' ) && (bool) get_option( self::COHABITATION_OPTION, false );

		return function_exists( 'apply_filters' ) ? (bool) apply_filters( 'wp_media_helper_attachment_cohabitation', $on, $postId ) : $on;
	}
}
