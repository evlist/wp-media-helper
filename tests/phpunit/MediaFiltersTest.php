<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Admin\MediaFilters;

class MediaFiltersTest extends TestCase {

	private function makeFilters( array &$store ): MediaFilters {
		return new MediaFilters(
			static function ( string $key ) use ( &$store ) {
				return $store[ $key ] ?? false;
			},
			static function ( string $key, $value ) use ( &$store ): void {
				$store[ $key ] = $value;
			}
		);
	}

	public function test_resolve_returns_default_when_nothing_is_stored(): void {
		$store = [];
		$filters = $this->makeFilters( $store );

		$this->assertSame( 'default', $filters->resolveUserPostThenUser( 42, 'attachment_scope', 'default' ) );
	}

	public function test_resolve_prefers_current_post_value_over_global_value(): void {
		$store = [
			MediaFilters::postMapMetaKey( 'attachment_scope' ) => [
				'42' => [ 'value' => 'post-value', 'time' => 100 ],
			],
			MediaFilters::globalMetaKey( 'attachment_scope' ) => [ 'value' => 'global-value' ],
		];
		$filters = $this->makeFilters( $store );

		$this->assertSame( 'post-value', $filters->resolveUserPostThenUser( 42, 'attachment_scope', 'default' ) );
	}

	public function test_resolve_falls_back_to_latest_global_value_for_a_different_post(): void {
		$store = [
			MediaFilters::postMapMetaKey( 'attachment_scope' ) => [
				'42' => [ 'value' => 'post-value', 'time' => 100 ],
			],
			MediaFilters::globalMetaKey( 'attachment_scope' ) => [ 'value' => 'global-value' ],
		];
		$filters = $this->makeFilters( $store );

		$this->assertSame( 'global-value', $filters->resolveUserPostThenUser( 7, 'attachment_scope', 'default' ) );
	}

	public function test_persist_stores_both_post_specific_and_latest_global_values(): void {
		$store = [];
		$filters = $this->makeFilters( $store );

		$filters->persistUserPostThenUser( 42, 'attachment_scope', 'new-value' );

		$this->assertSame( 'new-value', $store[ MediaFilters::globalMetaKey( 'attachment_scope' ) ]['value'] );
		$this->assertSame( 'new-value', $store[ MediaFilters::postMapMetaKey( 'attachment_scope' ) ]['42']['value'] );
	}

	public function test_persist_does_not_write_a_post_entry_without_a_post_id(): void {
		$store = [];
		$filters = $this->makeFilters( $store );

		$filters->persistUserPostThenUser( 0, 'attachment_scope', 'new-value' );

		$this->assertSame( 'new-value', $store[ MediaFilters::globalMetaKey( 'attachment_scope' ) ]['value'] );
		$this->assertArrayNotHasKey( MediaFilters::postMapMetaKey( 'attachment_scope' ), $store );
	}

	public function test_one_users_choice_does_not_alter_another_users_storage(): void {
		$storeA = [];
		$storeB = [];
		$filtersA = $this->makeFilters( $storeA );
		$filtersB = $this->makeFilters( $storeB );

		$filtersA->persistUserPostThenUser( 42, 'attachment_scope', 'a-value' );

		$this->assertSame( 'default', $filtersB->resolveUserPostThenUser( 42, 'attachment_scope', 'default' ) );
	}

	public function test_prune_map_keeps_only_the_most_recently_used_entries(): void {
		$map = [
			'1' => [ 'value' => 'old', 'time' => 10 ],
			'2' => [ 'value' => 'newer', 'time' => 20 ],
			'3' => [ 'value' => 'newest', 'time' => 30 ],
		];

		$pruned = MediaFilters::pruneMap( $map, 2 );

		// PHP casts numeric string array keys to integers.
		$this->assertSame( [ 2, 3 ], array_keys( $pruned ) );
	}

	public function test_prune_map_leaves_a_map_under_the_limit_untouched(): void {
		$map = [ '1' => [ 'value' => 'a', 'time' => 10 ] ];

		$this->assertSame( $map, MediaFilters::pruneMap( $map, 50 ) );
	}
}
