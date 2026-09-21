<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

/**
 * Resolves and persists "user, then user-post override" scoped filter values,
 * as defined by the extensible media filter contract (slice 015).
 *
 * Storage access is injected as closures so the resolution and pruning logic
 * can be unit tested without a WordPress runtime.
 */
class MediaFilters {

	public const MAX_USER_POST_ENTRIES = 50;

	/** @var callable(string):mixed */
	private $getUserMeta;

	/** @var callable(string, mixed):void */
	private $updateUserMeta;

	public function __construct( callable $getUserMeta, callable $updateUserMeta ) {
		$this->getUserMeta = $getUserMeta;
		$this->updateUserMeta = $updateUserMeta;
	}

	/**
	 * Resolution order: current user's value for this post, then the current
	 * user's latest global value, then the filter default.
	 */
	public function resolveUserPostThenUser( int $postId, string $filterKey, mixed $default ): mixed {
		$map = $this->getPostMap( $filterKey );
		if ( 0 !== $postId && array_key_exists( (string) $postId, $map ) && is_array( $map[ (string) $postId ] ) ) {
			return $map[ (string) $postId ]['value'];
		}

		$global = ( $this->getUserMeta )( self::globalMetaKey( $filterKey ) );
		if ( is_array( $global ) && array_key_exists( 'value', $global ) ) {
			return $global['value'];
		}

		return $default;
	}

	/**
	 * Stores the value as both the current user's value for this post and
	 * the current user's latest global value.
	 */
	public function persistUserPostThenUser( int $postId, string $filterKey, mixed $value ): void {
		( $this->updateUserMeta )( self::globalMetaKey( $filterKey ), [ 'value' => $value ] );

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

	public static function globalMetaKey( string $filterKey ): string {
		return '_wp_media_helper_filter_' . $filterKey . '_latest';
	}
}
