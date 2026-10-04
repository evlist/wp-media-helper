<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Index;

use WP_Media_Helper\Settings\ActiveSources;

/**
 * Background work of the index, run by WordPress cron.
 *
 * - `wp_media_helper_index_run` works on the sources that need a pass, within a time
 *   budget, and schedules itself again while work remains.
 * - `wp_media_helper_index_maintenance` runs hourly: it forgets removed sources and
 *   asks for the passes that are due. Cron depends on visits to the site unless a
 *   system cron calls `wp-cron.php`.
 */
class Cron {

	public const RUN_HOOK         = 'wp_media_helper_index_run';
	public const MAINTENANCE_HOOK = 'wp_media_helper_index_maintenance';
	public const LOCK_OPTION      = 'wp_media_helper_index_lock';
	public const BUDGET_SECONDS   = 15.0;
	public const LOCK_SECONDS     = 120;

	public static function register(): void {
		add_action( self::RUN_HOOK, [ self::class, 'run' ] );
		add_action( self::MAINTENANCE_HOOK, [ self::class, 'maintain' ] );
		add_action( 'init', [ self::class, 'ensureMaintenanceScheduled' ] );
	}

	public static function ensureMaintenanceScheduled(): void {
		if ( ! wp_next_scheduled( self::MAINTENANCE_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::MAINTENANCE_HOOK );
		}
	}

	/**
	 * Asks for a background run soon, unless one is already scheduled.
	 */
	public static function schedule( int $delay = 0 ): void {
		if ( wp_next_scheduled( self::RUN_HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time() + max( 0, $delay ), self::RUN_HOOK );

		if ( 0 === $delay && ! ( defined( 'DOING_CRON' ) && DOING_CRON ) && function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	public static function run(): void {
		if ( ! self::lock() ) {
			return;
		}

		try {
			$more = DayIndex::forWordPress()->manager()->runBackground( ActiveSources::all(), new ScanBudget( self::BUDGET_SECONDS ) );
		} finally {
			delete_option( self::LOCK_OPTION );
		}

		if ( $more ) {
			self::schedule( 5 );
		}
	}

	public static function maintain(): void {
		$manager = DayIndex::forWordPress()->manager();
		$manager->forgetUnknownSources( ActiveSources::configuredIds() );
		$manager->requestDuePasses( ActiveSources::all() );
	}

	public static function clear(): void {
		wp_clear_scheduled_hook( self::RUN_HOOK );
		wp_clear_scheduled_hook( self::MAINTENANCE_HOOK );
	}

	/**
	 * Only one run at a time. A lock older than the limit is taken over: a run that
	 * died must not block the index.
	 */
	private static function lock(): bool {
		if ( add_option( self::LOCK_OPTION, time(), '', 'no' ) ) {
			return true;
		}

		$since = (int) get_option( self::LOCK_OPTION, 0 );
		if ( time() - $since > self::LOCK_SECONDS ) {
			update_option( self::LOCK_OPTION, time(), false );

			return true;
		}

		return false;
	}
}
