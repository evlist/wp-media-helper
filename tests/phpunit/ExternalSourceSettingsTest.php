<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Settings\ExternalSourceSettings;

class ExternalSourceSettingsTest extends TestCase {

	private string $tmpRoot;

	protected function setUp(): void {
		$this->tmpRoot = sys_get_temp_dir() . '/wpmh_settings_' . uniqid();
		mkdir( $this->tmpRoot, 0755, true );
	}

	protected function tearDown(): void {
		if ( is_dir( $this->tmpRoot ) ) {
			rmdir( $this->tmpRoot );
		}
	}

	public function test_loads_empty_array_when_option_is_missing(): void {
		$settings = new ExternalSourceSettings(
			static fn(): mixed => false,
			static function ( array $value ): void {}
		);

		$this->assertSame( [], $settings->getAll() );
	}

	public function test_saves_normalized_sources(): void {
		$saved = null;
		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ) use ( &$saved ): void {
				$saved = $value;
			}
		);

		$settings->saveAll( [
			[
				'name' => '  Nextcloud Main  ',
				'enabled' => '1',
				'root' => $this->tmpRoot,
				'path_pattern' => '{date:Y}/{date:m}/{date:d}',
				'filter_pattern' => '  {date:Ymd}  ',
				'thumbnail_cache' => '/tmp/wp-media-helper-cache',
			],
		] );

		$this->assertIsArray( $saved );
		$this->assertSame( 'nextcloud-main', $saved[0]['id'] );
		$this->assertSame( 'Nextcloud Main', $saved[0]['name'] );
		$this->assertSame( 'active', $saved[0]['state'] );
		$this->assertSame( $this->tmpRoot, $saved[0]['root'] );
		$this->assertSame( '{date:Y}/{date:m}/{date:d}', $saved[0]['path_pattern'] );
		$this->assertSame( '{date:Ymd}', $saved[0]['filter_pattern'] );
		$this->assertSame( '/tmp/wp-media-helper-cache', $saved[0]['thumbnail_cache'] );
	}

	public function test_saves_an_empty_collection_when_all_sources_are_removed(): void {
		$saved = null;
		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ) use ( &$saved ): void {
				$saved = $value;
			}
		);

		$settings->saveAll( [] );

		$this->assertSame( [], $saved );
	}

	public function test_rejects_invalid_source_shape(): void {
		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ): void {}
		);

		$this->expectException( InvalidArgumentException::class );
		$settings->saveAll( [
			[
				'name' => '',
				'root' => '',
				'path_pattern' => '',
			],
		] );
	}

	public function test_reports_per_field_validation_errors(): void {
		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ): void {}
		);

		$errors = $settings->validateSources( [
			[
				'name' => '',
				'root' => '',
				'path_pattern' => '',
			],
		] );

		$this->assertArrayHasKey( 0, $errors );
		$this->assertArrayHasKey( 'name', $errors[0] );
		$this->assertArrayHasKey( 'root', $errors[0] );
		$this->assertFalse( array_key_exists( 'path_pattern', $errors[0] ) );
		$this->assertStringContainsString( 'required', $errors[0]['name'] );
		$this->assertStringContainsString( 'required', $errors[0]['root'] );
	}

	public function test_rejects_duplicate_source_names(): void {
		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ): void {}
		);

		$errors = $settings->validateSources( [
			[
				'name' => 'Nextcloud Main',
				'root' => $this->tmpRoot,
			],
			[
				'name' => '  nextcloud main  ',
				'root' => $this->tmpRoot,
			],
		] );

		$this->assertArrayHasKey( 'name', $errors[0] );
		$this->assertArrayHasKey( 'name', $errors[1] );
		$this->assertStringContainsString( 'already used', $errors[0]['name'] );
	}

	public function test_rejects_duplicate_names_when_saving(): void {
		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ): void {}
		);

		$this->expectException( InvalidArgumentException::class );
		$settings->saveAll( [
			[
				'name' => 'Nextcloud Main',
				'root' => $this->tmpRoot,
			],
			[
				'name' => 'Nextcloud Main',
				'root' => $this->tmpRoot,
			],
		] );
	}

	public function test_suffixes_names_that_produce_duplicate_source_ids(): void {
		$saved = null;
		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ) use ( &$saved ): void {
				$saved = $value;
			}
		);

		mkdir( $this->tmpRoot . '/sub', 0755, true );
		$settings->saveAll( [
			[ 'name' => 'Nextcloud Main', 'root' => $this->tmpRoot ],
			[ 'name' => 'Nextcloud-Main', 'root' => $this->tmpRoot . '/sub' ],
		] );

		$this->assertSame( 'nextcloud-main', $saved[0]['id'] );
		$this->assertSame( 'nextcloud-main-2', $saved[1]['id'] );
	}

	public function test_allows_static_sources_without_path_pattern(): void {
		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ): void {}
		);

		$errors = $settings->validateSources( [
			[
				'name' => 'Static Media',
				'root' => $this->tmpRoot,
				'path_pattern' => '',
			],
		] );

		$this->assertSame( [], $errors );
	}

	public function test_rejects_unknown_placeholder_in_path_pattern(): void {
		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ): void {}
		);

		$errors = $settings->validateSources( [
			[
				'name' => 'Bad pattern',
				'root' => $this->tmpRoot,
				'path_pattern' => '{unknown}/{date:Y}',
			],
		] );

		$this->assertArrayHasKey( 'path_pattern', $errors[0] );
		$this->assertStringContainsString( 'unknown', $errors[0]['path_pattern'] );
	}

	public function test_rejects_empty_date_format_in_path_pattern(): void {
		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ): void {}
		);

		$errors = $settings->validateSources( [
			[
				'name' => 'Bad pattern',
				'root' => $this->tmpRoot,
				'path_pattern' => '{date:}',
			],
		] );

		$this->assertArrayHasKey( 'path_pattern', $errors[0] );
	}

	public function test_rejects_unmatched_braces_in_path_pattern(): void {
		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ): void {}
		);

		$errors = $settings->validateSources( [
			[
				'name' => 'Bad pattern',
				'root' => $this->tmpRoot,
				'path_pattern' => '{date:Y}/{date:m',
			],
		] );

		$this->assertArrayHasKey( 'path_pattern', $errors[0] );
	}

	public function test_rejects_unknown_placeholder_in_filter_pattern(): void {
		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ): void {}
		);

		$errors = $settings->validateSources( [
			[
				'name' => 'Bad filter',
				'root' => $this->tmpRoot,
				'filter_pattern' => '{foo}',
			],
		] );

		$this->assertArrayHasKey( 'filter_pattern', $errors[0] );
	}

	public function test_accepts_valid_path_and_filter_patterns(): void {
		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ): void {}
		);

		$errors = $settings->validateSources( [
			[
				'name' => 'Good pattern',
				'root' => $this->tmpRoot,
				'path_pattern' => '{date:Y}/{date:m}/{date:d}',
				'filter_pattern' => '{date:Ymd}',
			],
		] );

		$this->assertSame( [], $errors );
	}

	public function test_rejects_relative_root_directory(): void {
		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ): void {}
		);

		$errors = $settings->validateSources( [
			[
				'name' => 'Relative',
				'root' => 'relative/path',
			],
		] );

		$this->assertArrayHasKey( 'root', $errors[0] );
		$this->assertStringContainsString( 'absolute', $errors[0]['root'] );
	}

	public function test_rejects_missing_root_directory(): void {
		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ): void {}
		);

		$errors = $settings->validateSources( [
			[
				'name' => 'Missing',
				'root' => $this->tmpRoot . '/does-not-exist',
			],
		] );

		$this->assertArrayHasKey( 'root', $errors[0] );
		$this->assertStringContainsString( 'exist', $errors[0]['root'] );
	}

	public function test_rejects_root_directory_that_is_a_file(): void {
		$filePath = $this->tmpRoot . '/not-a-directory';
		touch( $filePath );

		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ): void {}
		);

		$errors = $settings->validateSources( [
			[
				'name' => 'Not a directory',
				'root' => $filePath,
			],
		] );

		unlink( $filePath );

		$this->assertArrayHasKey( 'root', $errors[0] );
		$this->assertStringContainsString( 'directory', $errors[0]['root'] );
	}

	public function test_rejects_unreadable_root_directory(): void {
		chmod( $this->tmpRoot, 0000 );

		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ): void {}
		);

		$errors = $settings->validateSources( [
			[
				'name' => 'Unreadable',
				'root' => $this->tmpRoot,
			],
		] );

		chmod( $this->tmpRoot, 0755 );

		if ( 0 === posix_getuid() ) {
			$this->markTestSkipped( 'Root user bypasses filesystem permissions.' );
		}

		$this->assertArrayHasKey( 'root', $errors[0] );
		$this->assertStringContainsString( 'readable', $errors[0]['root'] );
	}

	public function test_requires_thumbnail_cache_when_root_is_read_only(): void {
		chmod( $this->tmpRoot, 0555 );

		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ): void {}
		);

		$errors = $settings->validateSources( [
			[
				'name' => 'Read-only source',
				'root' => $this->tmpRoot,
			],
		] );

		chmod( $this->tmpRoot, 0755 );

		if ( 0 === posix_getuid() ) {
			$this->markTestSkipped( 'Root user bypasses filesystem permissions.' );
		}

		$this->assertArrayHasKey( 'thumbnail_cache', $errors[0] );
		$this->assertStringContainsString( 'read-only', $errors[0]['thumbnail_cache'] );
	}

	public function test_allows_read_only_root_when_thumbnail_cache_is_provided(): void {
		$cache = sys_get_temp_dir() . '/wpmh_cache_' . uniqid();
		mkdir( $cache );
		chmod( $this->tmpRoot, 0555 );

		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ): void {}
		);

		$errors = $settings->validateSources( [
			[
				'name' => 'Read-only source',
				'root' => $this->tmpRoot,
				'thumbnail_cache' => $cache,
			],
		] );

		chmod( $this->tmpRoot, 0755 );
		rmdir( $cache );

		if ( 0 === posix_getuid() ) {
			$this->markTestSkipped( 'Root user bypasses filesystem permissions.' );
		}

		$this->assertSame( [], $errors );
	}

	public function test_accepts_existing_writable_thumbnail_cache(): void {
		$cache = $this->tmpRoot . '-cache';
		mkdir( $cache );

		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ): void {}
		);

		$errors = $settings->validateSources( [
			[
				'name' => 'Cached',
				'root' => $this->tmpRoot,
				'thumbnail_cache' => $cache,
			],
		] );

		rmdir( $cache );

		$this->assertSame( [], $errors );
	}

	public function test_accepts_thumbnail_cache_that_can_be_created(): void {
		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ): void {}
		);

		$errors = $settings->validateSources( [
			[
				'name' => 'Cached',
				'root' => $this->tmpRoot,
				'thumbnail_cache' => $this->tmpRoot . '-not-yet-created',
			],
		] );

		$this->assertSame( [], $errors );
	}

	public function test_rejects_thumbnail_cache_whose_parent_does_not_exist(): void {
		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ): void {}
		);

		$errors = $settings->validateSources( [
			[
				'name' => 'Cached',
				'root' => $this->tmpRoot,
				'thumbnail_cache' => $this->tmpRoot . '/missing-parent/cache',
			],
		] );

		$this->assertArrayHasKey( 'thumbnail_cache', $errors[0] );
		$this->assertStringContainsString( 'writable or creatable', $errors[0]['thumbnail_cache'] );
	}

	public function test_rejects_thumbnail_cache_that_is_a_file(): void {
		$cache = $this->tmpRoot . '/cache-file';
		touch( $cache );

		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ): void {}
		);

		$errors = $settings->validateSources( [
			[
				'name' => 'Cached',
				'root' => $this->tmpRoot,
				'thumbnail_cache' => $cache,
			],
		] );

		unlink( $cache );

		$this->assertArrayHasKey( 'thumbnail_cache', $errors[0] );
		$this->assertStringContainsString( 'directory', $errors[0]['thumbnail_cache'] );
	}

	public function test_rejects_unwritable_existing_thumbnail_cache(): void {
		$cache = $this->tmpRoot . '/readonly-cache';
		mkdir( $cache );
		chmod( $cache, 0555 );

		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ): void {}
		);

		$errors = $settings->validateSources( [
			[
				'name' => 'Cached',
				'root' => $this->tmpRoot,
				'thumbnail_cache' => $cache,
			],
		] );

		chmod( $cache, 0755 );
		rmdir( $cache );

		if ( 0 === posix_getuid() ) {
			$this->markTestSkipped( 'Root user bypasses filesystem permissions.' );
		}

		$this->assertArrayHasKey( 'thumbnail_cache', $errors[0] );
		$this->assertStringContainsString( 'writable', $errors[0]['thumbnail_cache'] );
	}

	public function test_page_collects_per_field_errors_for_invalid_sources(): void {
		$reflection = new ReflectionClass( \WP_Media_Helper\Admin\ExternalSourceSettingsPage::class );
		$page = $reflection->newInstanceWithoutConstructor();
		$errors = $page->getValidationErrors( [
			[
				'name' => '',
				'root' => '',
				'path_pattern' => '',
			],
		] );

		$this->assertArrayHasKey( 0, $errors );
		$this->assertArrayHasKey( 'name', $errors[0] );
		$this->assertArrayHasKey( 'root', $errors[0] );
		$this->assertFalse( array_key_exists( 'path_pattern', $errors[0] ) );
	}

	public function test_page_lists_every_error_with_its_source_position(): void {
		$reflection = new ReflectionClass( \WP_Media_Helper\Admin\ExternalSourceSettingsPage::class );
		$page = $reflection->newInstanceWithoutConstructor();

		$notices = $page->buildErrorNotices( [
			[
				'name' => 'Nextcloud',
				'root' => $this->tmpRoot,
			],
			[
				'name' => '',
				'root' => '',
			],
		] );

		$this->assertSame(
			[
				'Source #2: Name is required.',
				'Source #2: Root directory is required.',
			],
			$notices
		);
	}

	public function test_page_reports_no_notice_for_valid_sources(): void {
		$reflection = new ReflectionClass( \WP_Media_Helper\Admin\ExternalSourceSettingsPage::class );
		$page = $reflection->newInstanceWithoutConstructor();

		$notices = $page->buildErrorNotices( [
			[
				'name' => 'Nextcloud',
				'root' => $this->tmpRoot,
			],
		] );

		$this->assertSame( [], $notices );
	}

	public function test_rejects_thumbnail_cache_inside_or_equal_to_or_containing_the_root(): void {
		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ): void {}
		);

		// A cache inside a root is fine (it is excluded from the listing); a cache that is
		// or contains a root is not.
		$this->assertSame( [], $settings->validateSources( [
			[ 'name' => 'Cached', 'root' => $this->tmpRoot, 'thumbnail_cache' => $this->tmpRoot . '/cache' ],
		] ) );

		foreach ( [ $this->tmpRoot, dirname( $this->tmpRoot ) ] as $cache ) {
			$errors = $settings->validateSources( [
				[ 'name' => 'Cached', 'root' => $this->tmpRoot, 'thumbnail_cache' => $cache ],
			] );

			$this->assertArrayHasKey( 'thumbnail_cache', $errors[0] );
			$this->assertStringContainsString( 'cannot be, or contain', $errors[0]['thumbnail_cache'] );
		}
	}

	public function test_two_sources_cannot_share_a_root_but_roots_can_be_nested(): void {
		mkdir( $this->tmpRoot . '/sub', 0755, true );
		$settings = new ExternalSourceSettings( static fn(): mixed => [], static function ( array $value ): void {} );

		$errors = $settings->validateSources( [
			[ 'name' => 'A', 'root' => $this->tmpRoot, 'thumbnail_cache' => '/tmp/wp-media-helper-cache' ],
			[ 'name' => 'B', 'root' => $this->tmpRoot, 'thumbnail_cache' => '/tmp/wp-media-helper-cache' ],
		] );
		$this->assertArrayNotHasKey( 0, $errors );
		$this->assertArrayHasKey( 'root', $errors[1] );

		$this->assertSame( [], $settings->validateSources( [
			[ 'name' => 'A', 'root' => $this->tmpRoot . '/sub', 'thumbnail_cache' => '/tmp/wp-media-helper-cache' ],
			[ 'name' => 'B', 'root' => $this->tmpRoot, 'thumbnail_cache' => '/tmp/wp-media-helper-cache' ],
		] ) );
	}

	public function test_rejects_relative_thumbnail_cache_paths(): void {
		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ): void {}
		);

		$errors = $settings->validateSources( [
			[ 'name' => 'Cached', 'root' => $this->tmpRoot, 'thumbnail_cache' => 'cache' ],
		] );

		$this->assertStringContainsString( 'absolute', $errors[0]['thumbnail_cache'] );
	}

	private function normalize( array $source ): array {
		$saved = null;
		$settings = new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ) use ( &$saved ): void {
				$saved = $value;
			}
		);
		$settings->saveAll( [ array_merge( [ 'name' => 'Photos', 'root' => $this->tmpRoot, 'thumbnail_cache' => '/tmp/wp-media-helper-cache' ], $source ) ] );

		return $saved[0];
	}

	public function test_the_modification_time_fallback_is_on_unless_turned_off(): void {
		$this->assertTrue( $this->normalize( [] )['mtime_fallback'] );
		$this->assertTrue( $this->normalize( [ 'mtime_fallback' => '1' ] )['mtime_fallback'] );
		$this->assertFalse( $this->normalize( [ 'mtime_fallback' => '0' ] )['mtime_fallback'] );
	}

	public function test_a_source_unchecked_in_the_form_is_disabled(): void {
		// The form sends 0 for the hidden field, then 1 when the box is checked.
		$this->assertSame( 'disabled', $this->normalize( [ 'enabled' => '0' ] )['state'] );
		$this->assertSame( 'active', $this->normalize( [ 'enabled' => '1' ] )['state'] );
	}

	public function test_the_state_is_kept_and_a_source_saved_before_the_states_existed_is_migrated(): void {
		$this->assertSame( 'excluded', $this->normalize( [ 'state' => 'excluded' ] )['state'] );
		$this->assertSame( 'disabled', $this->normalize( [ 'state' => 'disabled' ] )['state'] );
		$this->assertSame( 'active', $this->normalize( [ 'state' => 'bogus' ] )['state'] );
		$this->assertSame( 'disabled', \WP_Media_Helper\Settings\SourceState::of( [ 'enabled' => false ] ) );
		$this->assertSame( 'active', \WP_Media_Helper\Settings\SourceState::of( [ 'enabled' => true ] ) );
	}

	public function test_a_read_only_source_needs_a_cache_only_when_it_lists_files(): void {
		$settings = new ExternalSourceSettings( static fn(): mixed => [], static function ( array $value ): void {} );
		$readOnly = static fn ( string $state ): array => [ 'name' => 'Ro', 'root' => '/', 'state' => $state ];

		if ( ! is_writable( '/' ) ) {
			$this->assertArrayHasKey( 'thumbnail_cache', $settings->validateSources( [ $readOnly( 'active' ) ] )[0] ?? [] );
		}
		$this->assertArrayNotHasKey( 'thumbnail_cache', $settings->validateSources( [ $readOnly( 'excluded' ) ] )[0] ?? [] );
	}

	public function test_a_name_date_pattern_must_say_where_the_year_month_and_day_are(): void {
		$settings = new ExternalSourceSettings( static fn(): mixed => [], static function ( array $value ): void {} );
		$check = fn( string $pattern ): array => $settings->validateSources( [ [ 'name' => 'A', 'root' => $this->tmpRoot, 'filter_pattern' => $pattern, 'thumbnail_cache' => '/tmp/wp-media-helper-cache' ] ] );

		$this->assertSame( [], $check( '{date:Ymd}' ) );
		$this->assertSame( [], $check( 'IMG_{date:Y-m-d}_{date:His}' ) );
		$this->assertArrayHasKey( 'filter_pattern', $check( '{date:Ym}' )[0] );
		$this->assertArrayHasKey( 'filter_pattern', $check( '{date:y-m-d}' )[0] );
		$this->assertArrayHasKey( 'filter_pattern', $check( 'no date here' )[0] );
	}
}
