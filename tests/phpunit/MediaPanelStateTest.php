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

	public function test_request_refresh_returns_fresh_state_with_refresh_reason(): void {
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

		$this->assertSame( 'fresh', $state['status'] );
		$this->assertFalse( $state['refresh_required'] );
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

	public function test_enrich_files_creates_a_user_facing_metadata_contract(): void {
		$entries = MediaPanelState::enrichFiles( [
			'/tmp/source/2026/08/10/photo-1.jpg',
			'/tmp/source/2026/08/10/report.pdf',
			'/tmp/source/2026/08/10/archive.tar.gz',
		] );

		$this->assertCount( 3, $entries );
		$this->assertSame( 'photo-1.jpg', $entries[0]['name'] );
		$this->assertSame( 'jpg', $entries[0]['type'] );
		$this->assertFalse( $entries[0]['is_imported'] );
		$this->assertFalse( $entries[0]['is_attached_to_current_post'] );
		$this->assertSame( 'report.pdf', $entries[1]['name'] );
		$this->assertSame( 'pdf', $entries[1]['type'] );
		$this->assertFalse( $entries[1]['is_imported'] );
		$this->assertSame( 'archive.tar.gz', $entries[2]['name'] );
		$this->assertSame( 'gz', $entries[2]['type'] );
		$this->assertFalse( $entries[2]['is_imported'] );
	}

	public function test_enrich_files_handles_an_empty_array(): void {
		$this->assertSame( [], MediaPanelState::enrichFiles( [] ) );
	}

	public function test_merge_files_keeps_all_distinct_entries_when_items_are_enriched(): void {
		$current = [
			[ 'id' => 'a', 'path' => '/tmp/20260810-morning.png', 'name' => '20260810-morning.png', 'type' => 'png', 'is_imported' => false ],
			[ 'id' => 'b', 'path' => '/tmp/20260810-route.gpx', 'name' => '20260810-route.gpx', 'type' => 'gpx', 'is_imported' => false ],
		];
		$incoming = [
			[ 'id' => 'c', 'path' => '/tmp/20260810-summit.png', 'name' => '20260810-summit.png', 'type' => 'png', 'is_imported' => false ],
			[ 'id' => 'd', 'path' => '/tmp/20260810-not-an-image.txt', 'name' => '20260810-not-an-image.txt', 'type' => 'txt', 'is_imported' => false ],
		];

		$merged = MediaPanelState::mergeFiles( $current, $incoming );

		$this->assertCount( 4, $merged );
		$this->assertSame( '20260810-morning.png', $merged[0]['name'] );
		$this->assertSame( '20260810-not-an-image.txt', $merged[3]['name'] );
	}

	public function test_set_import_state_marks_matching_files_as_imported(): void {
		$items = MediaPanelState::setImportState( [
			[ 'id' => 'a', 'path' => '/tmp/20260810-morning.png', 'name' => '20260810-morning.png', 'type' => 'png', 'is_imported' => false ],
			[ 'id' => 'b', 'path' => '/tmp/20260810-route.gpx', 'name' => '20260810-route.gpx', 'type' => 'gpx', 'is_imported' => false ],
		], [ '/tmp/20260810-morning.png' ] );

		$this->assertTrue( $items[0]['is_imported'] );
		$this->assertFalse( $items[1]['is_imported'] );
	}

	public function test_set_import_state_matches_wordpress_renamed_uploads(): void {
		$items = MediaPanelState::setImportState( [
			[ 'id' => 'a', 'path' => '/tmp/source/20260810-morning.png', 'name' => '20260810-morning.png', 'type' => 'png', 'is_imported' => false ],
		], [ '/wp-content/uploads/2026/08/20260810-morning_1.png' ] );

		$this->assertTrue( $items[0]['is_imported'] );
	}

	public function test_set_attachment_state_marks_items_attached_to_the_current_post(): void {
		$items = MediaPanelState::setAttachmentState( [
			[ 'id' => 'a', 'path' => '/tmp/source/morning.png', 'is_attached_to_current_post' => false ],
			[ 'id' => 'b', 'path' => '/tmp/source/route.gpx', 'is_attached_to_current_post' => false ],
		], [ '/tmp/source/morning.png' ] );

		$this->assertTrue( $items[0]['is_attached_to_current_post'] );
		$this->assertFalse( $items[1]['is_attached_to_current_post'] );
	}

	public function test_set_other_post_state_marks_items_attached_elsewhere(): void {
		$items = MediaPanelState::setOtherPostState( [
			[ 'id' => 'a', 'path' => '/tmp/source/morning.png' ],
			[ 'id' => 'b', 'path' => '/tmp/source/route.gpx' ],
		], [ '/tmp/source/morning.png' => [ 'post_id' => 9 ] ] );

		$this->assertTrue( $items[0]['is_attached_to_other_post'] );
		$this->assertSame( 9, $items[0]['other_post_id'] );
		$this->assertFalse( $items[1]['is_attached_to_other_post'] );
		$this->assertSame( 0, $items[1]['other_post_id'] );
	}

	public function test_resolve_attachment_scope_state_prioritizes_current_over_other(): void {
		$this->assertSame( 'current', MediaPanelState::resolveAttachmentScopeState( [ 'is_attached_to_current_post' => true, 'is_attached_to_other_post' => true ] ) );
		$this->assertSame( 'other', MediaPanelState::resolveAttachmentScopeState( [ 'is_attached_to_current_post' => false, 'is_attached_to_other_post' => true ] ) );
		$this->assertSame( 'unattached', MediaPanelState::resolveAttachmentScopeState( [ 'is_attached_to_current_post' => false, 'is_attached_to_other_post' => false ] ) );
	}

	public function test_filter_by_attachment_scope_keeps_only_selected_states(): void {
		$items = [
			[ 'id' => 'a', 'is_attached_to_current_post' => true, 'is_attached_to_other_post' => false ],
			[ 'id' => 'b', 'is_attached_to_current_post' => false, 'is_attached_to_other_post' => true ],
			[ 'id' => 'c', 'is_attached_to_current_post' => false, 'is_attached_to_other_post' => false ],
		];

		$filtered = MediaPanelState::filterByAttachmentScope( $items, [ 'unattached', 'current' ] );

		$this->assertSame( [ 'a', 'c' ], array_column( $filtered, 'id' ) );
	}

	public function test_path_matches_handles_wordpress_renamed_uploads(): void {
		$this->assertTrue( MediaPanelState::pathMatches( '/tmp/source/20260810-morning.png', '/wp-content/uploads/2026/08/20260810-morning_1.png' ) );
		$this->assertFalse( MediaPanelState::pathMatches( '/tmp/source/20260810-morning.png', '/tmp/source/20260811-morning.png' ) );
	}

	public function test_normalize_bulk_items_discards_invalid_items_and_duplicates(): void {
		$items = \WP_Media_Helper\Admin\EditorMediaController::normalizeBulkItems( [
			[ 'id' => 'a', 'path' => '/tmp/source/a.jpg' ],
			[ 'id' => 'a', 'path' => '/tmp/source/a.jpg' ],
			[ 'path' => '/tmp/source/b.gpx' ],
			'',
			[ 'id' => 'invalid', 'path' => '  ' ],
			new stdClass(),
		] );

		$this->assertSame( [
			[ 'id' => 'a', 'path' => '/tmp/source/a.jpg' ],
			[ 'id' => '/tmp/source/b.gpx', 'path' => '/tmp/source/b.gpx' ],
		], $items );
	}

	public function test_normalize_panel_mode_defaults_to_simple(): void {
		$this->assertSame( 'simple', \WP_Media_Helper\Admin\EditorMediaController::normalizePanelMode( null ) );
		$this->assertSame( 'simple', \WP_Media_Helper\Admin\EditorMediaController::normalizePanelMode( 'unexpected' ) );
		$this->assertSame( 'advanced', \WP_Media_Helper\Admin\EditorMediaController::normalizePanelMode( 'advanced' ) );
	}

	public function test_normalize_filters_prefers_the_structured_payload_over_legacy_parameters(): void {
		$filters = \WP_Media_Helper\Admin\EditorMediaController::normalizeFilters(
			json_encode( [ 'date' => '2026-09-20', 'source' => 'belledonne', 'attachment_scope' => [ 'other' ] ] ),
			'2026-01-01',
			'legacy-source'
		);

		$this->assertSame( [ 'date' => '2026-09-20', 'source' => 'belledonne', 'attachment_scope' => [ 'other' ] ], $filters );
	}

	public function test_normalize_filters_falls_back_to_legacy_parameters_when_payload_is_absent(): void {
		$filters = \WP_Media_Helper\Admin\EditorMediaController::normalizeFilters( '', '2026-01-01', 'legacy-source' );

		$this->assertSame( [ 'date' => '2026-01-01', 'source' => 'legacy-source', 'attachment_scope' => [ 'unattached', 'current' ] ], $filters );
	}

	public function test_normalize_filters_ignores_invalid_payloads(): void {
		$filters = \WP_Media_Helper\Admin\EditorMediaController::normalizeFilters( 'not-json', '2026-01-01', 'legacy-source' );

		$this->assertSame( [ 'date' => '2026-01-01', 'source' => 'legacy-source', 'attachment_scope' => [ 'unattached', 'current' ] ], $filters );
	}

	public function test_normalize_filters_resolves_attachment_scope_from_storage_when_absent_from_payload(): void {
		$filters = \WP_Media_Helper\Admin\EditorMediaController::normalizeFilters(
			json_encode( [ 'date' => '2026-09-20' ] ),
			'2026-01-01',
			'legacy-source',
			static fn () => [ 'other' ]
		);

		$this->assertSame( [ 'other' ], $filters['attachment_scope'] );
	}

	public function test_normalize_attachment_scope_defaults_when_empty_or_invalid(): void {
		$this->assertSame( [ 'unattached', 'current' ], \WP_Media_Helper\Admin\EditorMediaController::normalizeAttachmentScope( null ) );
		$this->assertSame( [ 'unattached', 'current' ], \WP_Media_Helper\Admin\EditorMediaController::normalizeAttachmentScope( [] ) );
		$this->assertSame( [ 'unattached', 'current' ], \WP_Media_Helper\Admin\EditorMediaController::normalizeAttachmentScope( [ 'bogus' ] ) );
	}

	public function test_normalize_attachment_scope_keeps_only_known_states(): void {
		$this->assertSame(
			[ 'current', 'other' ],
			\WP_Media_Helper\Admin\EditorMediaController::normalizeAttachmentScope( [ 'current', 'other', 'bogus' ] )
		);
	}
}
