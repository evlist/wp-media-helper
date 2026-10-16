<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Maintenance;

use Closure;

/**
 * What the last checks found: the attachments whose file is missing (orphans), those the administrator chose to ignore,
 * the groups of paths that could not be checked, and the state of a check that is running in several steps.
 * Kept in one option; the IDs only, the rest is read from the posts when shown.
 */
final class OrphanRegistry {

	public const OPTION = 'wp_media_helper_orphans';

	private const DEFAULTS = [
		'checked_at'  => 0,
		'started_at'  => 0,
		'queue'       => [],
		'prefixes'    => [],
		'pending'     => [ 'ids' => [], 'unavailable' => [], 'scanned' => 0 ],
		'ids'         => [],
		'where'       => [],
		'ignored'     => [],
		'unavailable' => [],
		'scanned'     => 0,
	];

	/** @var Closure(): mixed */
	private Closure $loader;

	/** @var Closure(array<string, mixed>): void */
	private Closure $saver;

	/**
	 * @param Closure(): mixed                         $loader
	 * @param Closure(array<string, mixed>): void $saver
	 */
	public function __construct( Closure $loader, Closure $saver ) {
		$this->loader = $loader;
		$this->saver  = $saver;
	}

	public static function forWordPress(): self {
		return new self(
			static fn(): mixed => get_option( self::OPTION, [] ),
			static function ( array $value ): void {
				update_option( self::OPTION, $value, false );
			}
		);
	}

	/**
	 * @return array{checked_at:int, started_at:int, queue:string[], prefixes:string[], pending:array{ids:array<int, int>, unavailable:array<string, string>, scanned:int}, ids:array<int, int>, where:array<int, string>, ignored:int[], unavailable:array<string, string>, scanned:int}
	 */
	public function get(): array {
		$value = ( $this->loader )();

		return array_merge( self::DEFAULTS, is_array( $value ) ? $value : [] );
	}

	/**
	 * @param array<string, mixed> $state
	 */
	public function save( array $state ): void {
		( $this->saver )( $state );
	}

	/**
	 * Orphans that are not ignored.
	 *
	 * @return int[]
	 */
	public function open(): array {
		$state = $this->get();
		$ids   = array_diff( array_map( 'intval', array_keys( $state['ids'] ) ), array_map( 'intval', $state['ignored'] ) );
		sort( $ids );

		return array_values( $ids );
	}

	/**
	 * @return int[]
	 */
	public function ignored(): array {
		$state = $this->get();
		$ids   = array_intersect( array_map( 'intval', array_keys( $state['ids'] ) ), array_map( 'intval', $state['ignored'] ) );
		sort( $ids );

		return array_values( $ids );
	}

	/**
	 * @param int[] $ids
	 */
	public function ignore( array $ids ): void {
		$state            = $this->get();
		$state['ignored'] = array_values( array_unique( array_merge( array_map( 'intval', $state['ignored'] ), array_map( 'intval', $ids ) ) ) );
		$this->save( $state );
	}

	/**
	 * @param int[] $ids
	 */
	public function unignore( array $ids ): void {
		$state            = $this->get();
		$state['ignored'] = array_values( array_diff( array_map( 'intval', $state['ignored'] ), array_map( 'intval', $ids ) ) );
		$this->save( $state );
	}

	/**
	 * Forgets attachments that were removed.
	 *
	 * @param int[] $ids
	 */
	public function forget( array $ids ): void {
		$state = $this->get();
		foreach ( $ids as $id ) {
			unset( $state['ids'][ (int) $id ], $state['where'][ (int) $id ] );
		}
		$state['ignored'] = array_values( array_diff( array_map( 'intval', $state['ignored'] ), array_map( 'intval', $ids ) ) );
		$this->save( $state );
	}
}
