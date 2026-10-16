<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Attachment\AutoDrafts;

class AutoDraftsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wpdb'] = new FakeWpdb();
		foreach ( [ [ 1, 'publish' ], [ 2, 'auto-draft' ], [ 3, 'draft' ], [ 4, 'auto-draft' ] ] as $row ) {
			$GLOBALS['wpdb']->insert( 'wp_posts', [ 'ID' => $row[0], 'post_status' => $row[1] ] );
		}
	}

	public function test_the_auto_drafts_are_found_among_posts(): void {
		$this->assertSame( [ 2, 4 ], AutoDrafts::among( [ 1, 2, 3, 4, 99 ] ) );
		$this->assertSame( [], AutoDrafts::among( [ 1, 3 ] ) );
		$this->assertSame( [], AutoDrafts::among( [] ) );
	}

	public function test_without_drops_only_the_auto_drafts(): void {
		$this->assertSame( [ 1, 3, 99 ], AutoDrafts::without( [ 1, 2, 3, 4, 99 ] ) );
	}
}
