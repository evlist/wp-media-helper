<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Index;

/**
 * Limits what one scan run may do: a time and a number of directories.
 */
final class ScanBudget {

	private float $deadline;
	private int $directories;

	public function __construct( float $seconds, int $maxDirectories = PHP_INT_MAX ) {
		$this->deadline    = microtime( true ) + max( 0.0, $seconds );
		$this->directories = max( 0, $maxDirectories );
	}

	public function exhausted(): bool {
		return $this->directories <= 0 || microtime( true ) >= $this->deadline;
	}

	public function consumeDirectory(): void {
		--$this->directories;
	}
}
