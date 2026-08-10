<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

spl_autoload_register( function ( string $class ): void {
	$prefix = 'WP_Media_Helper\\';
	$base   = __DIR__ . '/../../plugin/includes/WP_Media_Helper/';

	if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
		return;
	}

	$relative = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) );
	$file     = $base . $relative . '.php';

	if ( is_readable( $file ) ) {
		require $file;
	}
} );
