<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Index;

use Closure;

/**
 * Where each source stands: the current pass, when the last one finished, and the
 * settings the stored dates were computed with. Kept in one option (not autoloaded).
 */
class ScanState {

	public const OPTION = 'wp_media_helper_index_state';

	private const DEFAULTS = [
		'run'          => 0,
		'in_progress'  => false,
		'full'         => false,
		'pending'      => '',
		'started_at'   => 0,
		'finished_at'  => 0,
		'last_full_at' => 0,
		'config_hash'  => '',
		'root'         => '',
	];

	/**
	 * @var Closure(): mixed
	 */
	private Closure $loader;

	/**
	 * @var Closure(array<string, mixed>): void
	 */
	private Closure $saver;

	/**
	 * @param Closure(): mixed                         $loader
	 * @param Closure(array<string, mixed>): void $saver
	 */
	public function __construct( Closure $loader, Closure $saver ) {
		$this->loader = $loader;
		$this->saver  = $saver;
	}

	/**
	 * @return array{run:int, in_progress:bool, full:bool, pending:string, started_at:int, finished_at:int, last_full_at:int, config_hash:string, root:string}
	 */
	public function get( string $sourceId ): array {
		$all = $this->all();

		return array_merge( self::DEFAULTS, is_array( $all[ $sourceId ] ?? null ) ? $all[ $sourceId ] : [] );
	}

	/**
	 * @param array<string, mixed> $state
	 */
	public function save( string $sourceId, array $state ): void {
		$all              = $this->all();
		$all[ $sourceId ] = array_merge( self::DEFAULTS, $state );
		( $this->saver )( $all );
	}

	public function forget( string $sourceId ): void {
		$all = $this->all();
		unset( $all[ $sourceId ] );
		( $this->saver )( $all );
	}

	/**
	 * @return string[]
	 */
	public function sourceIds(): array {
		return array_map( 'strval', array_keys( $this->all() ) );
	}

	/**
	 * Always read from the option: several objects (a request and a cron run) share it.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function all(): array {
		$stored = ( $this->loader )();

		return is_array( $stored ) ? $stored : [];
	}
}
