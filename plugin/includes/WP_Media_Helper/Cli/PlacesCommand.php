<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Cli;

use WP_Media_Helper\Geo\Places\PlacesCron;
use WP_Media_Helper\Geo\Places\PlaceStore;
use WP_Media_Helper\Index\WpdbIndexStore;

/**
 * The places of the photos and of the GPX tracks (slice 052, phase B): reverse geocoding, with a cache, within the usage policy of the service.
 */
class PlacesCommand {

	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'media-helper geo places', self::class );
		}
	}

	/**
	 * Says where the looking for places stands.
	 *
	 * The service and its pace (nothing is asked while no service is set in the settings), the files with a position by state of their place, the
	 * cells kept in the cache, and the back-off if the service refused. No coordinate is shown.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table (default), json, csv, yaml.
	 *
	 * @when after_wp_load
	 */
	public function status( array $args, array $assoc ): void {
		$settings = PlacesCron::settings();
		$files    = ( new WpdbIndexStore() )->placeCounts();
		$cells    = ( new PlaceStore() )->counts();
		$limiter  = PlacesCron::limiter();

		$rows = [
			[ 'what' => 'service', 'value' => $settings->enabled() ? $settings->host() . ( $settings->isPublicService() ? ' (public: at most ' . $settings->requestsPerMinute() . ' requests a minute, one at a time)' : ' (' . $settings->requestsPerMinute() . ' requests a minute)' ) : 'none (off)' ],
			[ 'what' => 'language', 'value' => $settings->language ],
			[ 'what' => 'files with a position: place to look for', 'value' => (string) $files[0] ],
			[ 'what' => 'files with a position: place found', 'value' => (string) $files[1] ],
			[ 'what' => 'files with a position: given up', 'value' => (string) $files[2] ],
			[ 'what' => 'files with a position: waiting for a new try', 'value' => (string) $files[3] ],
			[ 'what' => 'cells in the cache: found', 'value' => (string) $cells[1] ],
			[ 'what' => 'cells in the cache: nothing there', 'value' => (string) $cells[2] ],
			[ 'what' => 'cells in the cache: failed, to try again', 'value' => (string) $cells[3] ],
			[ 'what' => 'cells in the cache: given up', 'value' => (string) $cells[4] ],
			[ 'what' => 'back-off of the service', 'value' => $limiter->blockedFor() > 0 ? $limiter->blockedFor() . ' s left' : 'none' ],
		];
		\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $rows, [ 'what', 'value' ] );
	}

	/**
	 * Looks for the places now, in the foreground.
	 *
	 * It keeps to the pace of the service (the public one: at most 4 requests a minute, so a first pass over a large library is a matter of days;
	 * prefer an instance of your own for that) and stops at the first refusal of the service, which starts a back-off that the background job
	 * respects too. Newest files first; the places already in the cache are not asked for again. It can be run again at any time.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Stop after this many requests.
	 *
	 * [--seconds=<n>]
	 * : Stop after about this many seconds.
	 *
	 * @when after_wp_load
	 */
	public function run( array $args, array $assoc ): void {
		$settings = PlacesCron::settings();
		if ( ! $settings->enabled() ) {
			\WP_CLI::error( 'No geocoding service is set (settings of the plugin, Places of the photos). Nothing is asked.' );
		}

		$limit    = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 0;
		$stopAt   = isset( $assoc['seconds'] ) ? microtime( true ) + max( 1, (int) $assoc['seconds'] ) : 0.0;
		$job      = PlacesCron::job( $settings );
		$totals   = [ 'requests' => 0, 'found' => 0, 'nothing' => 0, 'failed' => 0, 'files_done' => 0 ];
		$blocked  = 0;
		do {
			$slice  = 0.0 === $stopAt ? 30.0 : min( 30.0, max( 1.0, $stopAt - microtime( true ) ) );
			$report = $job->run( $slice, 0 === $limit ? 0 : $limit - $totals['requests'] );
			foreach ( $totals as $key => $value ) {
				$totals[ $key ] = $value + $report[ $key ];
			}
			$blocked = $report['blocked_for'];
			fwrite( STDERR, sprintf( "%d requests, %d places found, %d files done...\n", $totals['requests'], $totals['found'], $totals['files_done'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} while ( $report['left'] && 0 === $blocked && ( 0 === $limit || $totals['requests'] < $limit ) && ( 0.0 === $stopAt || microtime( true ) < $stopAt ) );

		if ( $blocked > 0 ) {
			\WP_CLI::warning( sprintf( 'The service refused or did not answer: nothing more is asked for %d seconds (the background job waits too).', $blocked ) );
		}
		\WP_CLI::success( sprintf( '%d requests: %d places found, %d with nothing there, %d failed; %d files done.', $totals['requests'], $totals['found'], $totals['nothing'], $totals['failed'], $totals['files_done'] ) );
	}

	/**
	 * Forgets cells so that they are asked for again.
	 *
	 * By default only the cells that failed or were given up, and the files that waited for them. With --all every cell of the cache (they will be
	 * asked for again: that is as many requests as there are cells).
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Forget every cell, not only the ones that failed.
	 *
	 * @when after_wp_load
	 */
	public function reset( array $args, array $assoc ): void {
		$all   = isset( $assoc['all'] );
		$cells = ( new PlaceStore() )->reset( ! $all );
		$files = ( new WpdbIndexStore() )->resetPlaces( ! $all );

		\WP_CLI::success( sprintf( '%d cells forgotten, %d files will have their place looked for again.', $cells, $files ) );
	}
}
