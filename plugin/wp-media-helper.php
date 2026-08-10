<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Plugin Name: WP Media Helper
 * Plugin URI:  https://github.com/evlist/wp-media-helper
 * Description: Streamlines media selection and attachment from the post editor, with optional support for externally-hosted media directories.
 * Version:     0.1.0
 * Author:      Eric van der Vlist
 * Author URI:  https://dyomedea.com
 * License:     GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: wp-media-helper
 * Requires at least: 6.0
 * Requires PHP: 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register( function ( string $class ): void {
	$prefix = 'WP_Media_Helper\\';
	$base   = __DIR__ . '/includes/WP_Media_Helper/';

	if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
		return;
	}

	$relative = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) );
	$file     = $base . $relative . '.php';

	if ( is_readable( $file ) ) {
		require $file;
	}
} );
