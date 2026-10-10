<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

/**
 * Resolves and persists the filter values of a user for a post (scope `user_post`),
 * as defined by the extensible media filter contract (slice 015).
 *
 * Storage access is injected as closures so the resolution and pruning logic
 * can be unit tested without a WordPress runtime.
 */
class MediaFilters {

	public const MAX_USER_POST_ENTRIES = 200;

	/** @var callable(string):mixed */
	private $getUserMeta;

	/** @var callable(string, mixed):void */
	private $updateUserMeta;

	public function __construct( callable $getUserMeta, callable $updateUserMeta ) {
		$this->getUserMeta = $getUserMeta;
		$this->updateUserMeta = $updateUserMeta;
	}

	/**
	 * The current user's value for this post, or the filter default: the choices of a user on
	 * one post are not carried to another post (a new post starts from the defaults).
	 */
	public function resolveUserPost( int $postId, string $filterKey, mixed $default ): mixed {
		$map = $this->getPostMap( $filterKey );
		if ( 0 !== $postId && array_key_exists( (string) $postId, $map ) && is_array( $map[ (string) $postId ] ) && array_key_exists( 'value', $map[ (string) $postId ] ) ) {
			return $map[ (string) $postId ]['value'];
		}

		return $default;
	}

	/**
	 * Stores the value for the current user and this post, and for them only.
	 */
	public function persistUserPost( int $postId, string $filterKey, mixed $value ): void {
		if ( 0 === $postId ) {
			return;
		}

		$map = $this->getPostMap( $filterKey );
		$map[ (string) $postId ] = [ 'value' => $value, 'time' => time() ];
		$map = self::pruneMap( $map, self::MAX_USER_POST_ENTRIES );

		( $this->updateUserMeta )( self::postMapMetaKey( $filterKey ), $map );
	}

	/**
	 * Keeps the most recently used entries when the map grows past the limit.
	 *
	 * @param array<string, array{value:mixed, time:int}> $map
	 * @return array<string, array{value:mixed, time:int}>
	 */
	public static function pruneMap( array $map, int $maxEntries ): array {
		if ( count( $map ) <= $maxEntries ) {
			return $map;
		}

		uasort( $map, static fn ( array $left, array $right ): int => ( $left['time'] ?? 0 ) <=> ( $right['time'] ?? 0 ) );

		return array_slice( $map, -$maxEntries, null, true );
	}

	/**
	 * @return array<string, array{value:mixed, time:int}>
	 */
	private function getPostMap( string $filterKey ): array {
		$map = ( $this->getUserMeta )( self::postMapMetaKey( $filterKey ) );

		return is_array( $map ) ? $map : [];
	}

	public static function postMapMetaKey( string $filterKey ): string {
		return '_wp_media_helper_filter_' . $filterKey . '_by_post';
	}

	/**
	 * Meta key of the former "latest value of the user" fallback, no longer read nor written.
	 */
	public static function globalMetaKey( string $filterKey ): string {
		return '_wp_media_helper_filter_' . $filterKey . '_latest';
	}
}
