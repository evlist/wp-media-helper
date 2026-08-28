<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Admin\MediaPanelState;
use WP_Media_Helper\MediaSource\ExternalMediaIndex;
use WP_Media_Helper\MediaSource\TargetedRefreshCoordinator;

class MediaPanelStateTest extends TestCase {

	private string $root;
	private string $storage;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/wpmh_media_panel_' . uniqid();
		$this->storage = sys_get_temp_dir() . '/wpmh_media_panel_store_' . uniqid();
		mkdir( $this->root . '/2026/08', 0755, true );
		mkdir( $this->storage, 0755, true );

		touch( $this->root . '/2026/08/20260810-rando-belledonne.jpg' );
		touch( $this->root . '/2026/08/20260810-rando-belledonne.gpx' );
	}

	protected function tearDown(): void {
		foreach ( new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		) as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->root );

		if ( is_dir( $this->storage ) ) {
			foreach ( new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $this->storage, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			) as $file ) {
				$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
			}
			rmdir( $this->storage );
		}
	}

	public function test_resolve_returns_a_user_facing_panel_state_for_a_selected_date(): void {
		$source = [
			'root' => $this->root,
			'path_pattern' => '{date:Y}/{date:m}',
			'filter_pattern' => '{date:Ymd}',
			'id' => 'belledonne',
		];
		$date = new DateTimeImmutable( '2026-08-10' );
		$index = new ExternalMediaIndex( $this->storage );
		$panel = new MediaPanelState( new TargetedRefreshCoordinator( $index ) );

		$index->getForSource( $source, $date, 'belledonne' );
		$state = $panel->resolve( $source, $date, 'belledonne' );

		$this->assertSame( 'fresh', $state['status'] );
		$this->assertSame( '2026-08-10', $state['date'] );
		$this->assertFalse( $state['refresh_required'] );
		$this->assertCount( 2, $state['files'] );
		$this->assertSame( 'belledonne', $state['source_id'] );
	}

	public function test_request_refresh_sets_an_explicit_stale_state_and_reason(): void {
		$source = [
			'root' => $this->root,
			'path_pattern' => '{date:Y}/{date:m}',
			'filter_pattern' => '{date:Ymd}',
			'id' => 'belledonne',
		];
		$date = new DateTimeImmutable( '2026-08-10' );
		$index = new ExternalMediaIndex( $this->storage );
		$panel = new MediaPanelState( new TargetedRefreshCoordinator( $index ) );

		$index->getForSource( $source, $date, 'belledonne' );
		$state = $panel->requestRefresh( $source, $date, 'belledonne' );

		$this->assertSame( 'stale', $state['status'] );
		$this->assertTrue( $state['refresh_required'] );
		$this->assertSame( 'forced-refresh', $state['reason'] );
		$this->assertNotEmpty( $state['files'] );
	}

	public function test_resolve_accepts_date_ranges_and_exposes_them_in_state(): void {
		$source = [
			'root' => $this->root,
			'path_pattern' => '{date:Y}/{date:m}',
			'filter_pattern' => '{date:Ymd}',
			'id' => 'belledonne',
		];
		$start = new DateTimeImmutable( '2026-08-09' );
		$end = new DateTimeImmutable( '2026-08-11' );
		$panel = new MediaPanelState( new TargetedRefreshCoordinator( new ExternalMediaIndex( $this->storage ) ) );

		$state = $panel->resolve( $source, $start, 'belledonne', $end );

		$this->assertSame( '2026-08-09', $state['date'] );
		$this->assertSame( [ 'start' => '2026-08-09', 'end' => '2026-08-11' ], $state['date_range'] );
		$this->assertArrayHasKey( 'files', $state );
	}

	public function test_default_selection_uses_all_configured_sources(): void {
		$configured = [
			[ 'id' => 'belledonne', 'name' => 'Belledonne' ],
			[ 'id' => 'alpes', 'name' => 'Alpes' ],
		];

		$this->assertSame( $configured, \WP_Media_Helper\Admin\EditorMediaController::resolveRequestedSources( $configured, '' ) );
		$this->assertSame( [ $configured[1] ], \WP_Media_Helper\Admin\EditorMediaController::resolveRequestedSources( $configured, 'alpes' ) );
	}
}
