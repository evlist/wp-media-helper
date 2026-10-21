<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		return 1 === $number ? $single : $plural;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		foreach ( $GLOBALS['wpmh_test_filters'][ $hook ] ?? [] as $callback ) {
			$value = $callback( $value, ...$args );
		}

		return $value;
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( string $hook ) {
		return ! empty( $GLOBALS['wpmh_test_filters'][ $hook ] );
	}
}

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

require_once __DIR__ . '/support/FakeWpdb.php';
require_once __DIR__ . '/support/FakeMethod.php';
