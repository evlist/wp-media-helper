<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Admin\PostDateMeta;

class PostDateMetaTest extends TestCase {

	public function test_a_calendar_day_is_kept(): void {
		$this->assertSame( '2026-10-02', PostDateMeta::clean( '2026-10-02' ) );
	}

	public function test_anything_else_is_nothing(): void {
		foreach ( [ '', '2026-13-02', '2026-02-30', '02/10/2026', '2026-10-02 10:00', null, 20261002, [ '2026-10-02' ] ] as $value ) {
			$this->assertSame( '', PostDateMeta::clean( $value ) );
		}
	}
}
