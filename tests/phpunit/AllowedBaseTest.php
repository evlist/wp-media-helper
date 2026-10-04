<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Settings\AllowedBase;
use WP_Media_Helper\Settings\ExternalSourceSettings;

class AllowedBaseTest extends TestCase {

	private string $base;
	private string $inside;
	private string $outside;

	protected function setUp(): void {
		$this->base = realpath( sys_get_temp_dir() ) . '/wpmh_base_' . uniqid();
		$this->inside = $this->base . '/uploads/nextcloud';
		$this->outside = $this->base . '/uploads-other';
		mkdir( $this->inside, 0755, true );
		mkdir( $this->outside, 0755, true );
	}

	protected function tearDown(): void {
		foreach ( [ $this->inside, $this->base . '/uploads', $this->outside, $this->base ] as $directory ) {
			if ( is_link( $directory ) ) {
				unlink( $directory );
			} elseif ( is_dir( $directory ) ) {
				if ( is_link( $directory . '/escape' ) ) {
					unlink( $directory . '/escape' );
				}
				rmdir( $directory );
			}
		}
	}

	private function settings( ?string $base ): ExternalSourceSettings {
		return new ExternalSourceSettings(
			static fn(): mixed => [],
			static function ( array $value ): void {},
			static fn(): ?string => $base
		);
	}

	public function test_contains_accepts_the_base_itself_and_its_sub_directories(): void {
		$this->assertTrue( AllowedBase::contains( $this->base . '/uploads', $this->inside ) );
		$this->assertTrue( AllowedBase::contains( $this->base . '/uploads', $this->base . '/uploads' ) );
		$this->assertFalse( AllowedBase::contains( $this->base . '/uploads', $this->base ) );
		$this->assertFalse( AllowedBase::contains( $this->base . '/uploads', $this->outside ) );
		$this->assertFalse( AllowedBase::contains( $this->base . '/uploads', $this->base . '/uploads/missing' ) );
	}

	public function test_contains_rejects_dot_dot_and_symlink_escapes(): void {
		$this->assertFalse( AllowedBase::contains( $this->base . '/uploads', $this->inside . '/../../uploads-other' ) );

		symlink( $this->outside, $this->base . '/uploads/escape' );
		$this->assertFalse( AllowedBase::contains( $this->base . '/uploads', $this->base . '/uploads/escape' ) );
	}

	public function test_the_base_is_typed_as_a_dot_and_shown_as_one(): void {
		$this->assertSame( '/var/www/uploads', AllowedBase::toAbsolute( '/var/www/uploads', '.' ) );
		$this->assertSame( '.', AllowedBase::toRelative( '/var/www/uploads', '/var/www/uploads' ) );
	}

	public function test_resolve_returns_null_outside_wordpress(): void {
		$this->assertNull( AllowedBase::resolve() );
	}

	public function test_a_root_outside_the_base_is_rejected_by_validation(): void {
		$errors = $this->settings( $this->base . '/uploads' )->validateSources( [
			[ 'name' => 'Inside', 'root' => $this->inside, 'thumbnail_cache' => $this->base . '/uploads/cache' ],
			[ 'name' => 'Outside', 'root' => $this->outside, 'thumbnail_cache' => $this->base . '/uploads/cache2' ],
		] );

		$this->assertArrayNotHasKey( 0, $errors );
		$this->assertArrayHasKey( 'root', $errors[1] );
		$this->assertStringContainsString( $this->base . '/uploads', $errors[1]['root'] );
	}

	public function test_validation_can_skip_the_base_check_for_stored_sources(): void {
		$errors = $this->settings( $this->base . '/uploads' )->validateSources(
			[ [ 'name' => 'Outside', 'root' => $this->outside, 'thumbnail_cache' => $this->base . '/other-cache' ] ],
			false
		);

		$this->assertSame( [], $errors );
	}

	public function test_saving_a_root_outside_the_base_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->settings( $this->base . '/uploads' )->saveAll( [
			[ 'name' => 'Outside', 'root' => $this->outside, 'thumbnail_cache' => $this->base . '/uploads/cache' ],
		] );
	}

	public function test_is_root_allowed_reports_per_root_and_is_open_without_a_base(): void {
		$restricted = $this->settings( $this->base . '/uploads' );

		$this->assertTrue( $restricted->isRootAllowed( $this->inside ) );
		$this->assertFalse( $restricted->isRootAllowed( $this->outside ) );
		$this->assertTrue( $this->settings( null )->isRootAllowed( $this->outside ) );
	}

	public function test_to_absolute_prefixes_relative_values_with_the_base(): void {
		$this->assertSame( '/srv/uploads/nextcloud/photos', AllowedBase::toAbsolute( '/srv/uploads', 'nextcloud/photos' ) );
		$this->assertSame( '/srv/uploads/nextcloud', AllowedBase::toAbsolute( '/srv/uploads/', ' nextcloud/ ' ) );
		$this->assertSame( '/srv/uploads/../etc', AllowedBase::toAbsolute( '/srv/uploads', '../etc' ) );
		$this->assertSame( '', AllowedBase::toAbsolute( '/srv/uploads', '   ' ) );
	}

	public function test_to_absolute_leaves_absolute_values_for_validation(): void {
		$this->assertSame( '/srv/uploads/nextcloud', AllowedBase::toAbsolute( '/srv/uploads', '/srv/uploads/nextcloud' ) );
		$this->assertSame( '/etc', AllowedBase::toAbsolute( '/srv/uploads', '/etc' ) );
		$this->assertSame( 'photos', AllowedBase::toAbsolute( null, 'photos' ) );
	}

	public function test_a_typed_relative_path_validates_like_its_absolute_form(): void {
		$base = $this->base . '/uploads';
		$settings = $this->settings( $base );
		$root = AllowedBase::toAbsolute( $base, 'nextcloud' );

		$cache = AllowedBase::toAbsolute( $base, 'cache' );
		$this->assertSame( [], $settings->validateSources( [ [ 'name' => 'A', 'root' => $root, 'thumbnail_cache' => $cache ] ] ) );

		$escaping = AllowedBase::toAbsolute( $base, '../uploads-other' );
		$errors = $settings->validateSources( [ [ 'name' => 'B', 'root' => $escaping, 'thumbnail_cache' => $cache ] ] );
		$this->assertArrayHasKey( 'root', $errors[0] );
	}

	public function test_to_relative_strips_the_base_when_the_root_is_inside_it(): void {
		$this->assertSame( 'nextcloud/photos', AllowedBase::toRelative( '/srv/uploads', '/srv/uploads/nextcloud/photos' ) );
		$this->assertSame( '/var/www/media', AllowedBase::toRelative( '/srv/uploads', '/var/www/media' ) );
		$this->assertSame( '/srv/uploads-other/x', AllowedBase::toRelative( '/srv/uploads', '/srv/uploads-other/x' ) );
		$this->assertSame( '/var/www/media', AllowedBase::toRelative( null, '/var/www/media' ) );
	}

	public function test_a_thumbnail_cache_outside_the_base_is_rejected(): void {
		$settings = $this->settings( $this->base . '/uploads' );
		$cache = $this->base . '/uploads/new-cache';

		$errors = $settings->validateSources( [ [ 'name' => 'A', 'root' => $this->inside, 'thumbnail_cache' => $this->base . '/cache-outside' ] ] );
		$this->assertArrayHasKey( 'thumbnail_cache', $errors[0] );
		$this->assertStringContainsString( $this->base . '/uploads', $errors[0]['thumbnail_cache'] );

		$this->assertSame( [], $settings->validateSources( [ [ 'name' => 'A', 'root' => $this->inside, 'thumbnail_cache' => $cache ] ] ) );
	}

	public function test_a_not_yet_created_cache_cannot_escape_the_base_through_dot_dot(): void {
		$base = $this->base . '/uploads';

		$this->assertTrue( AllowedBase::containsDirectory( $base, $base . '/new' ) );
		$this->assertFalse( AllowedBase::containsDirectory( $base, $base . '/../new' ) );
		$this->assertFalse( AllowedBase::containsDirectory( $base, $base . '/new/..' ) );
		$this->assertFalse( AllowedBase::containsDirectory( $base, $base . '/missing/new' ) );
		$this->assertFalse( AllowedBase::containsDirectory( $base, $base ) );
	}

	public function test_is_source_allowed_checks_root_and_cache(): void {
		$settings = $this->settings( $this->base . '/uploads' );

		$this->assertTrue( $settings->isSourceAllowed( [ 'root' => $this->inside ] ) );
		$this->assertTrue( $settings->isSourceAllowed( [ 'root' => $this->inside, 'thumbnail_cache' => $this->base . '/uploads/cache' ] ) );
		$this->assertFalse( $settings->isSourceAllowed( [ 'root' => $this->inside, 'thumbnail_cache' => $this->base . '/cache-outside' ] ) );
		$this->assertFalse( $settings->isSourceAllowed( [ 'root' => $this->outside ] ) );
	}
}
