<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Index;

use WP_Media_Helper\MediaSource\FileDates;

/**
 * Decides when each source is scanned, and runs the passes.
 *
 * A source has at most one pass in progress. A pass starts when the source was never
 * indexed, when a scan is requested (the *Refresh* button, a change of the settings
 * the dates depend on), when the last pass is older than the incremental interval, or
 * when the last full pass is older than the full interval.
 */
class IndexManager {

	public const INCREMENTAL_INTERVAL = 1800;
	public const FULL_INTERVAL        = 604800;
	public const RETENTION            = 2592000;

	public const PASS_FULL        = 'full';
	public const PASS_INCREMENTAL = 'incremental';

	private IndexStore $store;
	private IndexScanner $scanner;
	private ScanState $state;

	/**
	 * Asks for a background run soon.
	 *
	 * @var callable
	 */
	private $schedule;

	/**
	 * @var callable
	 */
	private $clock;

	public function __construct( IndexStore $store, IndexScanner $scanner, ScanState $state, callable $schedule, ?callable $clock = null ) {
		$this->store    = $store;
		$this->scanner  = $scanner;
		$this->state    = $state;
		$this->schedule = $schedule;
		$this->clock    = $clock ?? 'time';
	}

	/**
	 * Notes the settings the stored dates depend on, resets the index of a source
	 * whose root changed, and asks for the first pass of a source never indexed.
	 *
	 * @param array<string, mixed> $source
	 * @return array{run:int, in_progress:bool, full:bool, pending:string, started_at:int, finished_at:int, last_full_at:int, config_hash:string, root:string}
	 */
	public function prepare( array $source ): array {
		$id    = (string) ( $source['id'] ?? '' );
		$state = $this->state->get( $id );
		$hash  = FileDates::configHash( $source );
		$root  = (string) ( $source['root'] ?? '' );

		$changed = false;
		if ( '' !== $state['root'] && $state['root'] !== $root ) {
			$this->store->deleteSource( $id );
			$state   = array_merge( $state, [ 'in_progress' => false, 'finished_at' => 0, 'last_full_at' => 0, 'pending' => self::PASS_FULL ] );
			$changed = true;
		} elseif ( '' !== $state['config_hash'] && $state['config_hash'] !== $hash ) {
			// The dates stored in the index were computed with other settings.
			$state   = array_merge( $state, [ 'in_progress' => false, 'pending' => self::PASS_FULL ] );
			$changed = true;
		}

		if ( $state['root'] !== $root || $state['config_hash'] !== $hash ) {
			$state['root']        = $root;
			$state['config_hash'] = $hash;
			$changed              = true;
		}

		if ( 0 === $state['finished_at'] && ! $state['in_progress'] && '' === $state['pending'] ) {
			$state['pending'] = self::PASS_FULL;
			$changed          = true;
		}

		if ( $changed ) {
			$this->state->save( $id, $state );
		}

		// Work is waiting: make sure a background run is scheduled (cheap when it is).
		if ( '' !== $state['pending'] || $state['in_progress'] ) {
			( $this->schedule )( 0 );
		}

		return $state;
	}

	/**
	 * Whether the index of a source has been completed once.
	 *
	 * @param array<string, mixed> $source
	 */
	public function isIndexed( array $source ): bool {
		return $this->state->get( (string) ( $source['id'] ?? '' ) )['finished_at'] > 0;
	}

	/**
	 * Asks for a pass over a source, run in the background soon.
	 *
	 * @param array<string, mixed> $source
	 */
	public function requestPass( array $source, bool $full = false ): void {
		$id    = (string) ( $source['id'] ?? '' );
		$state = $this->state->get( $id );

		if ( self::PASS_FULL !== $state['pending'] ) {
			$state['pending'] = $full ? self::PASS_FULL : self::PASS_INCREMENTAL;
		}
		$this->state->save( $id, $state );
		( $this->schedule )( 0 );
	}

	/**
	 * Scans directories likely to hold what is asked for, right away and briefly.
	 *
	 * @param array<string, mixed> $source
	 * @param string[]             $directories Canonical directories below the root.
	 */
	public function scanHints( array $source, array $directories, ScanBudget $budget, bool $full = false ): ScanResult {
		// A distinct run for each call: hints must not mix with the run of a pass.
		$run = (int) ( microtime( true ) * 1000 );

		return $this->scanner->scan( $source, $run, $budget, $full, $directories );
	}

	/**
	 * Works on the sources that need it within the budget.
	 *
	 * @param array<int, array<string, mixed>> $sources
	 * @return bool Whether more work remains.
	 */
	public function runBackground( array $sources, ScanBudget $budget ): bool {
		$more = false;

		foreach ( $sources as $source ) {
			if ( $budget->exhausted() ) {
				return true;
			}

			$id    = (string) ( $source['id'] ?? '' );
			$state = $this->prepare( $source );
			$now   = $this->now();

			if ( ! $state['in_progress'] ) {
				$kind = $this->nextPassKind( $state, $now );
				if ( null === $kind ) {
					continue;
				}
				$state = array_merge( $state, [
					'run'         => $state['run'] + 1,
					'in_progress' => true,
					'full'        => self::PASS_FULL === $kind,
					'pending'     => '',
					'started_at'  => $now,
				] );
				$this->state->save( $id, $state );
			}

			$result = $this->scanner->scan( $source, $state['run'], $budget, $state['full'] );

			if ( $result->complete ) {
				$state = array_merge( $state, [ 'in_progress' => false, 'finished_at' => $this->now() ] );
				if ( $state['full'] ) {
					$state['last_full_at'] = $state['finished_at'];
				}
				$this->store->purgeMissing( $state['finished_at'] - self::RETENTION );
			} else {
				$more = true;
			}
			$this->state->save( $id, $state );
		}

		return $more;
	}

	/**
	 * Forgets the index of sources that are no longer configured.
	 *
	 * @param string[] $configuredIds
	 */
	public function forgetUnknownSources( array $configuredIds ): void {
		foreach ( $this->state->sourceIds() as $id ) {
			if ( ! in_array( $id, $configuredIds, true ) ) {
				$this->store->deleteSource( $id );
				$this->state->forget( $id );
			}
		}
	}

	/**
	 * Asks for an incremental pass over the sources that are due, without waiting for
	 * a visit to the panel.
	 *
	 * @param array<int, array<string, mixed>> $sources
	 */
	public function requestDuePasses( array $sources ): void {
		$now = $this->now();
		foreach ( $sources as $source ) {
			$state = $this->prepare( $source );
			if ( ! $state['in_progress'] && null !== $this->nextPassKind( $state, $now ) ) {
				( $this->schedule )( 0 );

				return;
			}
		}
	}

	/**
	 * @param array<string, mixed> $source
	 * @return array{indexed:bool, in_progress:bool, full:bool, finished_at:int, last_full_at:int, pending:string, files:int, directories:int}
	 */
	public function status( array $source ): array {
		$id    = (string) ( $source['id'] ?? '' );
		$state = $this->state->get( $id );

		return [
			'indexed'      => $state['finished_at'] > 0,
			'in_progress'  => $state['in_progress'],
			'full'         => $state['full'],
			'finished_at'  => $state['finished_at'],
			'last_full_at' => $state['last_full_at'],
			'pending'      => $state['pending'],
			'files'        => $this->store->countFiles( $id ),
			'directories'  => $this->store->countDirectories( $id ),
		];
	}

	/**
	 * @param array{run:int, in_progress:bool, full:bool, pending:string, started_at:int, finished_at:int, last_full_at:int, config_hash:string, root:string} $state
	 */
	private function nextPassKind( array $state, int $now ): ?string {
		if ( self::PASS_FULL === $state['pending'] || 0 === $state['finished_at'] || $now - $state['last_full_at'] >= self::FULL_INTERVAL ) {
			return self::PASS_FULL;
		}

		if ( self::PASS_INCREMENTAL === $state['pending'] || $now - $state['finished_at'] >= self::INCREMENTAL_INTERVAL ) {
			return self::PASS_INCREMENTAL;
		}

		return null;
	}

	private function now(): int {
		return (int) ( $this->clock )();
	}
}
