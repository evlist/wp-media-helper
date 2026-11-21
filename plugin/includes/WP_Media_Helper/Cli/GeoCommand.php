<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Cli;

use WP_Media_Helper\Geo\GeoCron;
use WP_Media_Helper\Index\WpdbIndexStore;

/**
 * The positions of the files (slice 052): where the photographs were taken, the start and the end of the GPX tracks.
 */
class GeoCommand {

	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'media-helper geo', self::class );
		}
	}

	/**
	 * Says how many files have been read for their position.
	 *
	 * Counts the files of the index (not the missing ones) by state: not read yet, read with a position, read without one (no GPS in the
	 * file, a type that has none, not readable). The reading is done in the background by WP-Cron (the pass `wp_media_helper_geo_run`); it
	 * waits while the index is paused. No coordinate is shown here.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table (default), json, csv, yaml.
	 *
	 * @when after_wp_load
	 */
	public function status( array $args, array $assoc ): void {
		$counts = ( new WpdbIndexStore() )->geoCounts();
		$rows   = [
			[ 'state' => 'not read yet', 'files' => $counts[0] ],
			[ 'state' => 'with a position', 'files' => $counts[1] ],
			[ 'state' => 'without a position', 'files' => $counts[2] ],
		];
		\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $rows, [ 'state', 'files' ] );
		\WP_CLI::log( GeoCron::enabled() ? 'The background reading is on.' : 'The background reading is off (filter wp_media_helper_read_gps).' );
	}

	/**
	 * Reads the position of the files not read yet, now.
	 *
	 * Newest files first. It can be run again at any time; the files already read are only read again when they changed, or with --reset.
	 * It goes on until nothing is left to read, or the limit is reached.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Stop after this many files.
	 *
	 * [--reset]
	 * : Forget what was read first (all the files, or those of --source), so that everything is read again.
	 *
	 * [--source=<id>]
	 * : With --reset: only the files of this source.
	 *
	 * @when after_wp_load
	 */
	public function run( array $args, array $assoc ): void {
		$store = new WpdbIndexStore();
		if ( isset( $assoc['reset'] ) ) {
			\WP_CLI::log( sprintf( '%d files will be read again.', $store->resetGeo( isset( $assoc['source'] ) ? (string) $assoc['source'] : null ) ) );
		}

		$limit  = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 0;
		$pass   = GeoCron::pass();
		$totals = [ 'read' => 0, 'with_position' => 0, 'without' => 0 ];
		do {
			$report = $pass->run( 20.0, 0 === $limit ? 0 : $limit - $totals['read'] );
			foreach ( $totals as $key => $value ) {
				$totals[ $key ] = $value + $report[ $key ];
			}
			if ( $report['read'] > 0 ) {
				fwrite( STDERR, sprintf( "%d files read, %d with a position...\n", $totals['read'], $totals['with_position'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		} while ( $report['left'] && ( 0 === $limit || $totals['read'] < $limit ) );

		\WP_CLI::success( sprintf( '%d files read: %d with a position, %d without.', $totals['read'], $totals['with_position'], $totals['without'] ) );
	}
}
