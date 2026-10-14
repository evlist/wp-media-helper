<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

/**
 * The controller of the panel needs WordPress to run, so most of it is never executed by the tests. A method that is called
 * but was deleted by mistake (it happened: `convertLink()`, `updateLink()` and `switchToNative()` vanished in a refactoring) would
 * only show on a real site. This test reads the sources and checks that every `$this->method()` and `self::method()` call of the
 * classes of the plugin names a method that exists.
 */
class ControllerCallsTest extends TestCase {

	public function test_every_method_called_on_its_own_class_exists(): void {
		$checked = 0;
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( __DIR__ . '/../../plugin/includes/WP_Media_Helper', FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			$source = (string) file_get_contents( $file->getPathname() );
			if ( 1 !== preg_match( '/^namespace\s+([^;]+);.*?^(?:final\s+|abstract\s+)?class\s+(\w+)/ms', $source, $found ) ) {
				continue;
			}

			$class = $found[1] . '\\' . $found[2];
			if ( ! class_exists( $class ) ) {
				continue;
			}

			// Read with the tokenizer, so that a word in a string or a comment is not taken for a call.
			$tokens = array_values( array_filter( token_get_all( $source ), static fn ( $token ): bool => ! is_array( $token ) || ! in_array( $token[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) );
			$names  = [];
			foreach ( $tokens as $index => $token ) {
				$text = is_array( $token ) ? $token[1] : $token;
				$next = $tokens[ $index + 1 ] ?? null;
				$after = $tokens[ $index + 2 ] ?? null;
				$third = $tokens[ $index + 3 ] ?? null;
				$isThis = '$this' === $text;
				if ( ( $isThis && '->' === ( is_array( $next ) ? $next[1] : $next ) ) || ( in_array( strtolower( $text ), [ 'self', 'static' ], true ) && '::' === ( is_array( $next ) ? $next[1] : $next ) ) ) {
					if ( is_array( $after ) && T_STRING === $after[0] && '(' === ( is_array( $third ) ? $third[1] : $third ) ) {
						$names[ $after[1] ] = true;
					}
				}
			}
			foreach ( array_keys( $names ) as $name ) {
				$this->assertTrue( method_exists( $class, $name ), $class . ' calls ' . $name . '(), which it does not have.' );
				++$checked;
			}
		}

		$this->assertTrue( $checked > 50, 'The test looked at the calls of the classes of the plugin.' );
	}
}
