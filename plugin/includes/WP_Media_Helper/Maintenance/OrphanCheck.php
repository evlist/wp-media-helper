<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Maintenance;

use WP_Media_Helper\Index\Pause;
use WP_Media_Helper\MediaSource\UploadsPath;
use WP_Media_Helper\Settings\ExternalSourceSettings;

/**
 * Looks, in the background, for the attachments whose file is no longer there, and keeps the result in the `OrphanRegistry`.
 *
 * The library is checked one group of paths at a time (the root of each source, then everything else), within a time
 * budget; what is left is done by the next run. A group that cannot be trusted (directory not mounted, empty, nothing
 * found) is reported as unavailable and gives no orphan. The check does not run while the indexing is paused: a move of the
 * files is probably under way.
 */
final class OrphanCheck {

	public const HOOK          = 'wp_media_helper_orphans_check';
	public const BUDGET_SECONDS = 20.0;
	/** The group for everything that is under no source. */
	private const REST = '';

	public static function register(): void {
		add_action( self::HOOK, [ self::class, 'run' ] );
		add_action( 'init', [ self::class, 'ensureScheduled' ] );
		add_action( 'wp_media_helper_files_went_missing', [ self::class, 'requestSoon' ] );
	}

	public static function ensureScheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 900, 'daily', self::HOOK );
		}
	}

	/**
	 * Asks for a check in a few minutes, after a scan found files gone (the files may be coming back: a sync in progress).
	 */
	public static function requestSoon(): void {
		if ( false === wp_next_scheduled( self::HOOK, [ 'soon' ] ) ) {
			wp_schedule_single_event( time() + 600, self::HOOK, [ 'soon' ] );
		}
	}

	/**
	 * The cron action. `$why` is only there to tell the single events apart.
	 */
	public static function run( string $why = '' ): void {
		if ( Pause::isPaused() ) {
			return;
		}

		if ( self::runWithin( self::BUDGET_SECONDS, 'now' === $why ) && false === wp_next_scheduled( self::HOOK, [ 'continue' ] ) ) {
			wp_schedule_single_event( time() + 10, self::HOOK, [ 'continue' ] );
		}
	}

	/**
	 * Works for at most `$seconds`; true when more remains.
	 *
	 * @param bool $restart Start a new check even if the last one is not finished.
	 */
	public static function runWithin( float $seconds, bool $restart = false ): bool {
		$uploads = wp_upload_dir( null, false );
		$baseDir = is_array( $uploads ) ? realpath( (string) ( $uploads['basedir'] ?? '' ) ) : false;
		if ( false === $baseDir ) {
			return false;
		}

		$registry = OrphanRegistry::forWordPress();
		if ( $restart ) {
			$state              = $registry->get();
			$state['queue']     = [];
			$state['started_at'] = 0;
			$registry->save( $state );
		}

		return self::step( $registry, new AttachmentCleaner( $baseDir ), self::sourcePrefixes( $baseDir ), $seconds, time(), 'microtime' );
	}

	/**
	 * One step of the check: takes the groups of the queue until the time is used.
	 *
	 * @param string[]          $prefixes The roots of the sources, relative to uploads.
	 * @param callable(): float $clock    Seconds, with decimals.
	 * @return bool Whether groups remain.
	 */
	public static function step( OrphanRegistry $registry, AttachmentCleaner $cleaner, array $prefixes, float $seconds, int $now, callable $clock ): bool {
		$state = $registry->get();
		if ( 0 === $state['started_at'] ) {
			$state['started_at'] = $now;
			$state['queue']      = array_merge( $prefixes, [ self::REST ] );
			$state['prefixes']   = $prefixes;
			$state['pending']    = [ 'ids' => [], 'unavailable' => [], 'scanned' => 0 ];
		}

		$deadline = $clock() + $seconds;
		while ( [] !== $state['queue'] ) {
			$group = (string) array_shift( $state['queue'] );
			if ( self::REST === $group ) {
				$found = $cleaner->findMissing( [], $state['prefixes'] );
			} else {
				// The groups inside this one are checked on their own.
				$nested = array_values( array_filter( $state['prefixes'], static fn ( string $other ): bool => str_starts_with( $other, $group . '/' ) ) );
				$found  = $cleaner->findMissing( [ $group ], $nested );
			}
			foreach ( $found['items'] as $item ) {
				$state['pending']['ids'][ $item['id'] ] = self::REST === $group ? '.' : $group;
			}
			$state['pending']['unavailable'] = array_merge( $state['pending']['unavailable'], $found['unavailable'] );
			$state['pending']['scanned']    += $found['scanned'];
			$registry->save( $state );

			if ( [] !== $state['queue'] && $clock() >= $deadline ) {
				return true;
			}
		}

		// Finished: what is found replaces the last result; an orphan seen before keeps the date it was first seen.
		$ids   = [];
		$where = [];
		foreach ( $state['pending']['ids'] as $id => $group ) {
			$ids[ $id ]   = $state['ids'][ $id ] ?? $now;
			$where[ $id ] = $group;
		}
		// A group that could not be checked keeps what was known of it: its orphans are not forgotten.
		foreach ( $state['ids'] as $id => $since ) {
			$group = $state['where'][ $id ] ?? null;
			if ( null !== $group && isset( $state['pending']['unavailable'][ $group ] ) && ! isset( $ids[ $id ] ) ) {
				$ids[ $id ]   = $since;
				$where[ $id ] = $group;
			}
		}
		$state['ids']         = $ids;
		$state['where']       = $where;
		$state['ignored']     = array_values( array_intersect( array_map( 'intval', $state['ignored'] ), array_keys( $ids ) ) );
		$state['unavailable'] = $state['pending']['unavailable'];
		$state['scanned']     = $state['pending']['scanned'];
		$state['checked_at']  = $now;
		$state['started_at']  = 0;
		$state['pending']     = [ 'ids' => [], 'unavailable' => [], 'scanned' => 0 ];
		$registry->save( $state );

		return false;
	}

	/**
	 * The roots of the configured sources below the uploads directory, relative to it ("." and anything outside are left out).
	 *
	 * @return string[]
	 */
	public static function sourcePrefixes( string $baseDir ): array {
		$sources  = get_option( ExternalSourceSettings::optionKey(), [] );
		$prefixes = [];
		foreach ( is_array( $sources ) ? $sources : [] as $source ) {
			$root = is_array( $source ) ? (string) ( $source['root'] ?? '' ) : '';
			$real = '' === $root ? false : realpath( $root );
			$key  = false === $real ? null : UploadsPath::relativeKey( $real, $baseDir );
			if ( null !== $key && '' !== $key && '.' !== $key ) {
				$prefixes[] = $key;
			}
		}

		return array_values( array_unique( $prefixes ) );
	}
}
