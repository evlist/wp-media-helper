<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Cli;

use WP_Media_Helper\Index\Pause;

/**
 * Manages WP Media Helper.
 */
class MediaHelperCommand {

	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'media-helper', self::class );
		}
	}

	/**
	 * Pauses the background indexing.
	 *
	 * The cron passes, the Re-scan buttons and the quick scan of the panel stop. The panel, the imports, the
	 * trash and the gallery keep working on the index as it is. The pause survives restarts.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-helper pause
	 */
	public function pause(): void {
		Pause::pause();
		\WP_CLI::success( 'The indexing is paused.' );
	}

	/**
	 * Resumes the background indexing and asks for the passes that are due.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-helper resume
	 */
	public function resume(): void {
		Pause::resume();
		if ( Pause::isForcedByConstant() ) {
			\WP_CLI::warning( 'The option is cleared, but the constant WP_MEDIA_HELPER_PAUSED keeps the indexing paused.' );

			return;
		}
		\WP_CLI::success( 'The indexing is resumed.' );
	}

	/**
	 * Tells whether the indexing is paused: prints "paused" or "running".
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : text or json ({"state":"paused","by_constant":false}). Default: text.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-helper status
	 *     wp media-helper status --format=json
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc
	 */
	public function status( array $args = [], array $assoc = [] ): void {
		$state = Pause::isPaused() ? 'paused' : 'running';
		if ( 'json' === ( $assoc['format'] ?? 'text' ) ) {
			\WP_CLI::line( (string) wp_json_encode( [ 'state' => $state, 'by_constant' => Pause::isForcedByConstant() ] ) );

			return;
		}
		\WP_CLI::line( $state );
	}
}
