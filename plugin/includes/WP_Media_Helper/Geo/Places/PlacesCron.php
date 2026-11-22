<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Geo\Places;

use WP_Media_Helper\Index\Pause;
use WP_Media_Helper\Index\WpdbIndexStore;
use WP_Media_Helper\Settings\GeneralSettings;

/**
 * Background looking for the places (slice 052, phase B): one event, its own lock and time budget, which waits between the requests the time the
 * service asks for and schedules itself again at the time the next request may be sent. It does nothing without a service in the settings.
 */
final class PlacesCron {

	public const RUN_HOOK       = 'wp_media_helper_places_run';
	public const LOCK_OPTION    = 'wp_media_helper_places_lock';
	public const PACE_OPTION    = 'wp_media_helper_places_pace';
	public const BUDGET_SECONDS = 50.0;
	public const LOCK_SECONDS   = 180;

	public static function register(): void {
		add_action( self::RUN_HOOK, [ self::class, 'run' ] );
	}

	public static function settings(): PlacesSettings {
		$stored = get_option( GeneralSettings::optionKey(), [] );

		return new PlacesSettings( is_array( $stored ) ? $stored : [], self::siteLanguage() );
	}

	private static function siteLanguage(): string {
		return function_exists( 'determine_locale' ) ? (string) determine_locale() : 'en';
	}

	public static function enabled(): bool {
		return self::settings()->enabled() && (bool) apply_filters( 'wp_media_helper_geocoding', true );
	}

	/**
	 * Asks for a run, unless one is already scheduled or the service is off.
	 */
	public static function schedule( int $delay = 5 ): void {
		if ( ! self::enabled() || Pause::isPaused() || wp_next_scheduled( self::RUN_HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time() + max( 0, $delay ), self::RUN_HOOK );
	}

	public static function limiter(): RateLimiter {
		return new RateLimiter(
			static fn (): array => (array) get_option( self::PACE_OPTION, [] ),
			static function ( array $state ): void {
				update_option( self::PACE_OPTION, $state, false );
			}
		);
	}

	/**
	 * The User-Agent that names the application, the site and, when given, a contact: the stock one of an HTTP library is not accepted by the
	 * public service.
	 */
	public static function userAgent( PlacesSettings $settings ): string {
		$version = defined( 'WP_MEDIA_HELPER_VERSION' ) ? (string) WP_MEDIA_HELPER_VERSION : '0';
		$site    = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';

		return sprintf( 'WP-Media-Helper/%s (%s%s)', $version, $site, '' === $settings->contact ? '' : '; ' . $settings->contact );
	}

	public static function job( PlacesSettings $settings ): PlacesJob {
		return new PlacesJob(
			new WpdbIndexStore(),
			new PlaceStore( $settings->precision ),
			new NominatimClient( $settings->url, $settings->language, self::userAgent( $settings ), function_exists( 'home_url' ) ? (string) home_url( '/' ) : '', NominatimClient::wordpressTransport(), $settings->precision ),
			self::limiter(),
			$settings
		);
	}

	public static function run(): void {
		if ( ! self::enabled() || Pause::isPaused() || ! self::lock() ) {
			return;
		}

		try {
			$report = self::job( self::settings() )->run( self::BUDGET_SECONDS );
		} finally {
			delete_option( self::LOCK_OPTION );
		}

		if ( $report['left'] ) {
			self::schedule( max( 5, $report['blocked_for'], (int) ceil( $report['next_in'] ) ) );
		}
	}

	public static function clear(): void {
		wp_clear_scheduled_hook( self::RUN_HOOK );
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
