<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Admin\MediaPanelState;
use WP_Media_Helper\Index\DayIndex;
use WP_Media_Helper\Index\IndexManager;
use WP_Media_Helper\Index\IndexScanner;
use WP_Media_Helper\Index\KeyMapper;
use WP_Media_Helper\Index\ScanBudget;
use WP_Media_Helper\Index\ScanState;
use WP_Media_Helper\Index\WpdbIndexStore;

class MediaPanelStateTest extends TestCase {

	private string $root;
	private DayIndex $dayIndex;
	private IndexManager $manager;
	/** @var array<string, mixed> */
	private array $option = [];

	protected function setUp(): void {
		$this->root = realpath( sys_get_temp_dir() ) . '/wpmh_media_panel_' . uniqid();
		mkdir( $this->root . '/2026/08', 0755, true );

		touch( $this->root . '/2026/08/20260810-rando-belledonne.jpg' );
		touch( $this->root . '/2026/08/20260810-rando-belledonne.gpx' );
		touch( $this->root . '/2026/08/20260811-autre-rando.jpg', time() - 1000 );
		touch( $this->root . '/2026/08', time() - 1000 );

		$GLOBALS['wpdb'] = new FakeWpdb();
		$store = new WpdbIndexStore();
		$keys = new KeyMapper( dirname( $this->root ) );
		$scanner = new IndexScanner( $store, $keys, new DateTimeZone( 'UTC' ) );
		$state = new ScanState(
			fn() => $this->option,
			function ( array $value ): void {
				$this->option = $value;
			}
		);
		$this->manager = new IndexManager( $store, $scanner, $state, static function ( int $delay ): void {} );
		$this->dayIndex = new DayIndex( $store, $this->manager, $keys );
	}

	protected function tearDown(): void {
		foreach ( new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		) as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->root );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function source(): array {
		return [
			'root' => $this->root,
			'path_pattern' => '{date:Y}/{date:m}',
			'filter_pattern' => '{date:Ymd}',
			'id' => 'belledonne',
		];
	}

	public function test_resolve_returns_a_user_facing_panel_state_for_a_selected_date(): void {
		$panel = new MediaPanelState( $this->dayIndex );

		$state = $panel->resolve( $this->source(), new DateTimeImmutable( '2026-08-10' ), 'belledonne' );

		$this->assertSame( '2026-08-10', $state['date'] );
		$this->assertCount( 2, $state['files'] );
		$this->assertSame( 'belledonne', $state['source_id'] );
		$this->assertSame( 'belledonne', $state['files'][0]['source_id'] );
		$this->assertSame( '', $state['directory'], 'No server directory is exposed.' );
		$this->assertSame( [ '20260810-rando-belledonne.gpx', '20260810-rando-belledonne.jpg' ], array_column( $state['files'], 'name' ) );
	}

	public function test_the_state_is_stale_until_the_first_pass_has_finished(): void {
		$panel = new MediaPanelState( $this->dayIndex );
		$first = $panel->resolve( $this->source(), new DateTimeImmutable( '2026-08-10' ), 'belledonne' );

		$this->assertSame( 'stale', $first['status'] );
		$this->assertTrue( $first['refresh_required'] );
		$this->assertSame( DayIndex::REASON_INCOMPLETE, $first['reason'] );

		$this->manager->runBackground( [ $this->source() ], new ScanBudget( 30 ) );
		$second = $panel->resolve( $this->source(), new DateTimeImmutable( '2026-08-10' ), 'belledonne' );

		$this->assertSame( 'fresh', $second['status'] );
		$this->assertFalse( $second['refresh_required'] );
		$this->assertNull( $second['reason'] );
	}

	public function test_request_refresh_returns_the_files_with_a_refresh_reason(): void {
		$panel = new MediaPanelState( $this->dayIndex );
		$panel->resolve( $this->source(), new DateTimeImmutable( '2026-08-10' ), 'belledonne' );
		$this->manager->runBackground( [ $this->source() ], new ScanBudget( 30 ) );

		$state = $panel->requestRefresh( $this->source(), new DateTimeImmutable( '2026-08-10' ), 'belledonne' );

		$this->assertSame( 'fresh', $state['status'] );
		$this->assertFalse( $state['refresh_required'] );
		$this->assertSame( 'forced-refresh', $state['reason'] );
		$this->assertNotEmpty( $state['files'] );
	}

	public function test_resolve_accepts_date_ranges_and_exposes_them_in_state(): void {
		$panel = new MediaPanelState( $this->dayIndex );

		$state = $panel->resolve( $this->source(), new DateTimeImmutable( '2026-08-09' ), 'belledonne', new DateTimeImmutable( '2026-08-11' ) );

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
		$this->assertSame( 'image', $entries[0]['media_type'] );
		$this->assertFalse( $entries[0]['is_imported'] );
		$this->assertFalse( $entries[0]['is_attached_to_current_post'] );
		$this->assertSame( 'report.pdf', $entries[1]['name'] );
		$this->assertSame( 'pdf', $entries[1]['type'] );
		$this->assertSame( 'other', $entries[1]['media_type'] );
		$this->assertFalse( $entries[1]['is_imported'] );
		$this->assertSame( 'archive.tar.gz', $entries[2]['name'] );
		$this->assertSame( 'gz', $entries[2]['type'] );
		$this->assertSame( 'other', $entries[2]['media_type'] );
		$this->assertFalse( $entries[2]['is_imported'] );
	}

	public function test_enrich_files_handles_an_empty_array(): void {
		$this->assertSame( [], MediaPanelState::enrichFiles( [] ) );
	}

	public function test_resolve_media_type_groups_common_image_video_and_other_extensions(): void {
		$this->assertSame( 'image', MediaPanelState::resolveMediaType( 'photo.JPEG' ) );
		$this->assertSame( 'video', MediaPanelState::resolveMediaType( 'clip.webm' ) );
		$this->assertSame( 'other', MediaPanelState::resolveMediaType( 'route.gpx' ) );
		$this->assertSame( 'other', MediaPanelState::resolveMediaType( 'unknown' ) );
	}

	public function test_filter_by_media_type_keeps_only_selected_categories(): void {
		$items = [
			[ 'id' => 'image', 'media_type' => 'image' ],
			[ 'id' => 'video', 'media_type' => 'video' ],
			[ 'id' => 'other', 'media_type' => 'other' ],
		];

		$filtered = MediaPanelState::filterByMediaType( $items, [ 'image', 'other' ] );

		$this->assertSame( [ 'image', 'other' ], array_column( $filtered, 'id' ) );
	}

	public function test_filter_by_filename_matches_case_insensitive_basename_only(): void {
		$items = [
			[ 'id' => 'match', 'name' => '20260810-Summit.png', 'path' => '/private/route/20260810-Summit.png' ],
			[ 'id' => 'path-only', 'name' => 'photo.jpg', 'path' => '/private/summit/photo.jpg' ],
			[ 'id' => 'other', 'name' => 'route.gpx' ],
		];

		$filtered = MediaPanelState::filterByFilename( $items, 'sUmMiT' );

		$this->assertSame( [ 'match' ], array_column( $filtered, 'id' ) );
	}

	public function test_filter_by_empty_filename_returns_all_items(): void {
		$items = [ [ 'id' => 'one' ], [ 'id' => 'two' ] ];

		$this->assertSame( $items, MediaPanelState::filterByFilename( $items, '' ) );
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

	public function test_set_import_state_matches_exact_paths_only(): void {
		$items = MediaPanelState::setImportState( [
			[ 'id' => 'a', 'path' => '/uploads/photos/2026/morning.png', 'name' => 'morning.png' ],
			[ 'id' => 'b', 'path' => '/uploads/photos/2027/morning.png', 'name' => 'morning.png' ],
			[ 'id' => 'c', 'path' => '/uploads/photos/2026/morning_1.png', 'name' => 'morning_1.png' ],
		], [ '/uploads/photos/2026/morning.png' ] );

		$this->assertTrue( $items[0]['is_imported'] );
		$this->assertFalse( $items[1]['is_imported'], 'Same file name in another directory is another file.' );
		$this->assertFalse( $items[2]['is_imported'], 'A numeric suffix is not a renamed copy.' );
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

	public function test_normalize_bulk_items_preserves_source_id_per_item(): void {
		$items = \WP_Media_Helper\Admin\EditorMediaController::normalizeBulkItems( [
			[ 'id' => 'a', 'path' => '/tmp/north/a.jpg', 'source_id' => 'north' ],
		] );

		$this->assertSame( [
			[ 'id' => 'a', 'path' => '/tmp/north/a.jpg', 'source_id' => 'north' ],
		], $items );
	}

	public function test_normalize_panel_mode_defaults_to_simple(): void {
		$this->assertSame( 'simple', \WP_Media_Helper\Admin\EditorMediaController::normalizePanelMode( null ) );
		$this->assertSame( 'simple', \WP_Media_Helper\Admin\EditorMediaController::normalizePanelMode( 'unexpected' ) );
		$this->assertSame( 'advanced', \WP_Media_Helper\Admin\EditorMediaController::normalizePanelMode( 'advanced' ) );
	}

	public function test_normalize_filters_prefers_the_structured_payload_over_legacy_parameters(): void {
		$filters = \WP_Media_Helper\Admin\EditorMediaController::normalizeFilters(
			json_encode( [ 'date' => '2026-09-20', 'source' => 'belledonne', 'attachment_scope' => [ 'other' ], 'media_type' => [ 'image' ], 'filename' => 'summit' ] ),
			'2026-01-01',
			'legacy-source',
			[ 'belledonne', 'legacy-source' ]
		);

		$this->assertSame( [ 'date' => '2026-09-20', 'source' => [ 'belledonne' ], 'attachment_scope' => [ 'other' ], 'media_type' => [ 'image' ], 'filename' => 'summit', 'show_hidden' => false ], $filters );
	}

	public function test_normalize_filters_falls_back_to_legacy_parameters_when_payload_is_absent(): void {
		$filters = \WP_Media_Helper\Admin\EditorMediaController::normalizeFilters( '', '2026-01-01', 'legacy-source', [ 'legacy-source', 'other-source' ] );

		$this->assertSame( [ 'date' => '2026-01-01', 'source' => [ 'legacy-source' ], 'attachment_scope' => [ 'unattached', 'current' ], 'media_type' => [ 'image', 'video', 'other' ], 'filename' => '', 'show_hidden' => false ], $filters );
	}

	public function test_normalize_filters_ignores_invalid_payloads(): void {
		$filters = \WP_Media_Helper\Admin\EditorMediaController::normalizeFilters( 'not-json', '2026-01-01', 'legacy-source', [ 'legacy-source', 'other-source' ] );

		$this->assertSame( [ 'date' => '2026-01-01', 'source' => [ 'legacy-source' ], 'attachment_scope' => [ 'unattached', 'current' ], 'media_type' => [ 'image', 'video', 'other' ], 'filename' => '', 'show_hidden' => false ], $filters );
	}

	public function test_normalize_filters_resolves_attachment_scope_from_storage_when_absent_from_payload(): void {
		$filters = \WP_Media_Helper\Admin\EditorMediaController::normalizeFilters(
			json_encode( [ 'date' => '2026-09-20' ] ),
			'2026-01-01',
			'legacy-source',
			[ 'legacy-source', 'other-source' ],
			static fn () => [ 'other' ]
		);

		$this->assertSame( [ 'other' ], $filters['attachment_scope'] );
	}

	public function test_normalize_filters_resolves_source_from_storage_when_absent_from_payload(): void {
		$filters = \WP_Media_Helper\Admin\EditorMediaController::normalizeFilters(
			json_encode( [ 'date' => '2026-09-20' ] ),
			'2026-01-01',
			'',
			[ 'north', 'south' ],
			null,
			static fn () => [ 'south' ]
		);

		$this->assertSame( [ 'south' ], $filters['source'] );
	}

	public function test_normalize_filters_resolves_filename_from_storage_when_absent_from_payload(): void {
		$filters = \WP_Media_Helper\Admin\EditorMediaController::normalizeFilters(
			json_encode( [ 'date' => '2026-09-20' ] ),
			'2026-01-01',
			'',
			[ 'north' ],
			null,
			null,
			null,
			static fn () => '  stored   summit  '
		);

		$this->assertSame( 'stored summit', $filters['filename'] );
	}

	public function test_normalize_filters_honors_an_explicit_empty_filename(): void {
		$filters = \WP_Media_Helper\Admin\EditorMediaController::normalizeFilters(
			json_encode( [ 'filename' => '' ] ),
			'2026-01-01',
			'',
			[ 'north' ],
			null,
			null,
			null,
			static fn () => 'stored summit'
		);

		$this->assertSame( '', $filters['filename'] );
	}

	public function test_normalize_filters_falls_back_to_all_when_stored_sources_are_stale(): void {
		$filters = \WP_Media_Helper\Admin\EditorMediaController::normalizeFilters(
			json_encode( [ 'date' => '2026-09-20' ] ),
			'2026-01-01',
			'',
			[ 'north', 'south' ],
			null,
			static fn () => [ 'removed-source' ]
		);

		$this->assertSame( [ 'all' ], $filters['source'] );
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

	public function test_normalize_media_type_filter_defaults_to_all_categories(): void {
		$this->assertSame( [ 'image', 'video', 'other' ], \WP_Media_Helper\Admin\EditorMediaController::normalizeMediaTypeFilter( null ) );
		$this->assertSame( [ 'image', 'video', 'other' ], \WP_Media_Helper\Admin\EditorMediaController::normalizeMediaTypeFilter( [] ) );
		$this->assertSame( [ 'image', 'video', 'other' ], \WP_Media_Helper\Admin\EditorMediaController::normalizeMediaTypeFilter( [ 'unknown' ] ) );
	}

	public function test_normalize_media_type_filter_keeps_only_supported_categories(): void {
		$this->assertSame( [ 'video', 'other' ], \WP_Media_Helper\Admin\EditorMediaController::normalizeMediaTypeFilter( [ 'video', 'bogus', 'other' ] ) );
	}

	public function test_normalize_filename_filter_trims_collapses_and_strips_separators(): void {
		$this->assertSame( 'Rando Summit', \WP_Media_Helper\Admin\EditorMediaController::normalizeFilenameFilter( " --  Rando\t  Summit_- " ) );
		$this->assertSame( '', \WP_Media_Helper\Admin\EditorMediaController::normalizeFilenameFilter( "  ._-/\\  " ) );
	}

	public function test_normalize_filename_filter_rejects_invalid_or_oversized_values(): void {
		$this->assertSame( '', \WP_Media_Helper\Admin\EditorMediaController::normalizeFilenameFilter( [ 'summit' ] ) );
		$this->assertSame( '', \WP_Media_Helper\Admin\EditorMediaController::normalizeFilenameFilter( "summit\xFF" ) );
		$this->assertSame( '', \WP_Media_Helper\Admin\EditorMediaController::normalizeFilenameFilter( "summit\x01" ) );
		$this->assertSame( '', \WP_Media_Helper\Admin\EditorMediaController::normalizeFilenameFilter( str_repeat( 'a', 256 ) ) );
	}

	public function test_normalize_source_filter_uses_all_for_multiple_sources_by_default(): void {
		$this->assertSame( [ 'all' ], \WP_Media_Helper\Admin\EditorMediaController::normalizeSourceFilter( null, [ 'north', 'south' ] ) );
	}

	public function test_normalize_source_filter_uses_the_only_source_implicitly(): void {
		$this->assertSame( [ 'north' ], \WP_Media_Helper\Admin\EditorMediaController::normalizeSourceFilter( null, [ 'north' ] ) );
		$this->assertSame( [ 'north' ], \WP_Media_Helper\Admin\EditorMediaController::normalizeSourceFilter( [ 'all' ], [ 'north' ] ) );
	}

	public function test_normalize_source_filter_returns_empty_when_no_sources_are_active(): void {
		$this->assertSame( [], \WP_Media_Helper\Admin\EditorMediaController::normalizeSourceFilter( [ 'all' ], [] ) );
	}

	public function test_normalize_source_filter_discards_stale_ids_and_falls_back_to_all(): void {
		$this->assertSame( [ 'north' ], \WP_Media_Helper\Admin\EditorMediaController::normalizeSourceFilter( [ 'north', 'removed' ], [ 'north', 'south' ] ) );
		$this->assertSame( [ 'all' ], \WP_Media_Helper\Admin\EditorMediaController::normalizeSourceFilter( [ 'removed' ], [ 'north', 'south' ] ) );
	}

	public function test_normalize_source_filter_keeps_a_selected_subset(): void {
		$this->assertSame( [ 'north', 'south' ], \WP_Media_Helper\Admin\EditorMediaController::normalizeSourceFilter( [ 'south', 'north', 'south' ], [ 'north', 'south' ] ) );
	}


	public function test_resolve_sources_for_filter_selects_by_internal_id_in_configuration_order(): void {
		$sources = [
			[ 'id' => 'north', 'name' => 'North' ],
			[ 'id' => 'south', 'name' => 'South' ],
			[ 'id' => 'east', 'name' => 'East' ],
		];

		$this->assertSame( [ $sources[0], $sources[2] ], \WP_Media_Helper\Admin\EditorMediaController::resolveSourcesForFilter( $sources, [ 'east', 'north' ] ) );
		$this->assertSame( $sources, \WP_Media_Helper\Admin\EditorMediaController::resolveSourcesForFilter( $sources, [ 'all' ] ) );
	}

	public function test_resolve_sources_for_filter_uses_configured_source_order(): void {
		$sources = [
			[ 'id' => 'north', 'name' => 'North' ],
			[ 'id' => 'south', 'name' => 'South' ],
			[ 'id' => 'east', 'name' => 'East' ],
		];

		$this->assertSame( [ $sources[0], $sources[2] ], \WP_Media_Helper\Admin\EditorMediaController::resolveSourcesForFilter( $sources, [ 'east', 'north' ] ) );
		$this->assertSame( $sources, \WP_Media_Helper\Admin\EditorMediaController::resolveSourcesForFilter( $sources, [ 'all' ] ) );
	}

	public function test_paginate_slices_items_and_reports_totals(): void {
		$page = MediaPanelState::paginate( range( 1, 25 ), 2, 10 );

		$this->assertSame( range( 11, 20 ), $page['items'] );
		$this->assertSame( 2, $page['page'] );
		$this->assertSame( 10, $page['per_page'] );
		$this->assertSame( 25, $page['total'] );
		$this->assertSame( 3, $page['total_pages'] );
		$this->assertSame( range( 21, 25 ), MediaPanelState::paginate( range( 1, 25 ), 3, 10 )['items'] );
	}

	public function test_paginate_clamps_the_requested_page(): void {
		$this->assertSame( 3, MediaPanelState::paginate( range( 1, 25 ), 99, 10 )['page'] );
		$this->assertSame( 1, MediaPanelState::paginate( range( 1, 25 ), 0, 10 )['page'] );
	}

	public function test_paginate_handles_empty_lists_and_invalid_page_sizes(): void {
		$empty = MediaPanelState::paginate( [], 4, 10 );
		$this->assertSame( [], $empty['items'] );
		$this->assertSame( 1, $empty['page'] );
		$this->assertSame( 1, $empty['total_pages'] );
		$this->assertSame( 1, MediaPanelState::paginate( [ 'a', 'b' ], 1, 0 )['per_page'] );
	}

	public function test_normalize_date_accepts_only_real_calendar_dates(): void {
		$this->assertSame( '2026-08-10', MediaPanelState::normalizeDate( ' 2026-08-10 ', '2000-01-01' ) );
		foreach ( [ '', 'tomorrow', '2026-13-01', '2026-02-30', '26-08-10', '2026-08-10 12:00', '../../etc' ] as $invalid ) {
			$this->assertSame( '2000-01-01', MediaPanelState::normalizeDate( $invalid, '2000-01-01' ) );
		}
	}

	public function test_find_other_post_attachment_ignores_the_current_post_and_unattached_files(): void {
		$rows = [
			[ 'id' => 1, 'parent' => 0, 'owned' => false ],
			[ 'id' => 2, 'parent' => 7, 'owned' => true ],
		];

		$this->assertSame( 0, \WP_Media_Helper\Admin\EditorMediaController::findOtherPostAttachment( $rows, 7 ) );
		$this->assertSame( 7, \WP_Media_Helper\Admin\EditorMediaController::findOtherPostAttachment( $rows, 8 ) );
		$this->assertSame( 0, \WP_Media_Helper\Admin\EditorMediaController::findOtherPostAttachment( [], 8 ) );
	}

	public function test_hidden_files_are_marked_when_the_panel_asks_for_them(): void {
		$items = MediaPanelState::enrichFiles( [ '/u/a.jpg', '/u/b.jpg' ], 's', '2026-10-02' );

		$marked = MediaPanelState::markHidden( $items, [ '/u/a.jpg' ] );
		$this->assertTrue( $marked[0]['is_hidden'] );
		$this->assertArrayNotHasKey( 'is_hidden', $marked[1] );
		$this->assertSame( $items, MediaPanelState::markHidden( $items, [] ) );
	}

	public function test_images_get_their_dimensions(): void {
		$items = MediaPanelState::enrichFiles( [ '/u/a.jpg', '/u/b.mp4' ], 's', '2026-10-02' );

		$marked = MediaPanelState::markDimensions( $items, [ '/u/a.jpg' => [ 4000, 3000 ] ] );
		$this->assertSame( [ 4000, 3000 ], [ $marked[0]['width'], $marked[0]['height'] ] );
		$this->assertArrayNotHasKey( 'width', $marked[1] );
	}

	public function test_items_get_the_details_shown_in_the_sheet(): void {
		$items = MediaPanelState::enrichFiles( [ '/u/a.jpg', '/u/b.mp4' ], 's', '2026-10-02' );

		$marked = MediaPanelState::markDetails( $items, [ '/u/a.jpg' => [ 'size' => 1234, 'date' => '2026-10-02 12:15:49', 'date_source' => 'embedded' ] ] );
		$this->assertSame( [ 1234, '2026-10-02 12:15:49', 'embedded' ], [ $marked[0]['file_size'], $marked[0]['effective_date'], $marked[0]['date_source'] ] );
		$this->assertArrayNotHasKey( 'file_size', $marked[1] );
	}
}
