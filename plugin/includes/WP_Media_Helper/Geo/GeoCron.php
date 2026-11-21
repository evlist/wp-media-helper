<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Geo;

use WP_Media_Helper\Index\Cron;
use WP_Media_Helper\Index\KeyMapper;
use WP_Media_Helper\Index\Pause;
use WP_Media_Helper\Index\WpdbIndexStore;

/**
 * Background reading of the positions of the files (slice 052): one event, its own lock and time budget, scheduled again while files remain.
 * It is asked for by the pass over the sources (new files have arrived) and by the hourly maintenance. It stops while the index is paused,
 * and the filter `wp_media_helper_read_gps` (default true) turns it off.
 */
final class GeoCron {

	public const RUN_HOOK       = 'wp_media_helper_geo_run';
	public const LOCK_OPTION    = 'wp_media_helper_geo_lock';
	public const BUDGET_SECONDS = 15.0;
	public const LOCK_SECONDS   = 120;

	public static function register(): void {
		add_action( self::RUN_HOOK, [ self::class, 'run' ] );
	}

	public static function enabled(): bool {
		return (bool) apply_filters( 'wp_media_helper_read_gps', true );
	}

	/**
	 * Asks for a run soon, unless one is already scheduled.
	 */
	public static function schedule( int $delay = 5 ): void {
		if ( ! self::enabled() || Pause::isPaused() || wp_next_scheduled( self::RUN_HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time() + max( 0, $delay ), self::RUN_HOOK );
	}

	public static function pass(): GeoPass {
		$uploads = wp_upload_dir( null, false );

		return new GeoPass( new WpdbIndexStore(), new KeyMapper( is_array( $uploads ) && ! empty( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '' ) );
	}

	public static function run(): void {
		if ( ! self::enabled() || Pause::isPaused() || ! self::lock() ) {
			return;
		}

		try {
			$report = self::pass()->run( self::BUDGET_SECONDS );
		} finally {
			delete_option( self::LOCK_OPTION );
		}

		if ( $report['left'] ) {
			self::schedule( 5 );
		}
		// Positions just read: their places are looked for (when a service is set).
		Places\PlacesCron::schedule( 10 );
	}

	public static function clear(): void {
		wp_clear_scheduled_hook( self::RUN_HOOK );
		Places\PlacesCron::clear();
	}

	private static function lock(): bool {
		if ( add_option( self::LOCK_OPTION, time(), '', 'no' ) ) {
			return true;
		}
		if ( time() - (int) get_option( self::LOCK_OPTION, 0 ) > self::LOCK_SECONDS ) {
			update_option( self::LOCK_OPTION, time(), false );

			return true;
		}

		return false;
	}
}
