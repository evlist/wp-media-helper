<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;

/**
 * WP-CLI is not loaded in these tests, so its parsing of the options is checked on the source: WP-CLI reads
 * `--no-xxx` as the negation of a flag `xxx` (`$assoc['xxx'] = false`), so a command must never declare or read an
 * option called `no-xxx`, and must only read options that its docblock declares (otherwise WP-CLI refuses the
 * option as unknown before the command runs).
 */
class CliOptionsTest extends TestCase {

	/**
	 * @return string[]
	 */
	private function files(): array {
		return glob( __DIR__ . '/../../plugin/includes/WP_Media_Helper/Cli/*.php' ) ?: [];
	}

	public function test_there_are_commands_to_check(): void {
		$this->assertTrue( count( $this->files() ) >= 4 );
	}

	public function test_no_option_is_declared_or_read_with_a_no_prefix(): void {
		foreach ( $this->files() as $file ) {
			$code = (string) file_get_contents( $file );
			$this->assertSame( 0, preg_match( '/\[--no-[a-z]/', $code ), basename( $file ) . ' declares a --no-xxx option: declare xxx and read it with get_flag_value( ..., true ).' );
			$this->assertSame( 0, preg_match( "/assoc\\[\\s*'no-/", $code ), basename( $file ) . ' reads a no-xxx option.' );
		}
	}

	public function test_every_option_read_is_declared_in_the_docblocks_of_the_file(): void {
		foreach ( $this->files() as $file ) {
			$code = (string) file_get_contents( $file );
			preg_match_all( '/\[?--([a-z][a-z-]*)[=\]\s]/', $code, $declared );
			preg_match_all( "/(?:assoc|assoc_args)\\[\\s*'([a-z-]+)'\\s*\\]/", $code, $read );
			preg_match_all( "/get_flag_value\\(\\s*\\\$assoc(?:_args)?,\\s*'([a-z-]+)'/", $code, $flags );
			foreach ( array_unique( array_merge( $read[1], $flags[1] ) ) as $option ) {
				$this->assertTrue( in_array( $option, $declared[1], true ), basename( $file ) . ' reads --' . $option . ' without declaring it.' );
			}
		}
	}
}
