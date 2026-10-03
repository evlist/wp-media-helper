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

	public function test_contains_accepts_only_strict_sub_directories(): void {
		$this->assertTrue( AllowedBase::contains( $this->base . '/uploads', $this->inside ) );
		$this->assertFalse( AllowedBase::contains( $this->base . '/uploads', $this->base . '/uploads' ) );
		$this->assertFalse( AllowedBase::contains( $this->base . '/uploads', $this->outside ) );
		$this->assertFalse( AllowedBase::contains( $this->base . '/uploads', $this->base . '/uploads/missing' ) );
	}

	public function test_contains_rejects_dot_dot_and_symlink_escapes(): void {
		$this->assertFalse( AllowedBase::contains( $this->base . '/uploads', $this->inside . '/../../uploads-other' ) );

		symlink( $this->outside, $this->base . '/uploads/escape' );
		$this->assertFalse( AllowedBase::contains( $this->base . '/uploads', $this->base . '/uploads/escape' ) );
	}

	public function test_resolve_returns_null_outside_wordpress(): void {
		$this->assertNull( AllowedBase::resolve() );
	}

	public function test_a_root_outside_the_base_is_rejected_by_validation(): void {
		$errors = $this->settings( $this->base . '/uploads' )->validateSources( [
			[ 'name' => 'Inside', 'root' => $this->inside, 'thumbnail_cache' => $this->inside ],
			[ 'name' => 'Outside', 'root' => $this->outside, 'thumbnail_cache' => $this->outside ],
		] );

		$this->assertArrayNotHasKey( 0, $errors );
		$this->assertArrayHasKey( 'root', $errors[1] );
		$this->assertStringContainsString( $this->base . '/uploads', $errors[1]['root'] );
	}

	public function test_validation_can_skip_the_base_check_for_stored_sources(): void {
		$errors = $this->settings( $this->base . '/uploads' )->validateSources(
			[ [ 'name' => 'Outside', 'root' => $this->outside, 'thumbnail_cache' => $this->outside ] ],
			false
		);

		$this->assertSame( [], $errors );
	}

	public function test_saving_a_root_outside_the_base_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->settings( $this->base . '/uploads' )->saveAll( [
			[ 'name' => 'Outside', 'root' => $this->outside, 'thumbnail_cache' => $this->outside ],
		] );
	}

	public function test_is_root_allowed_reports_per_root_and_is_open_without_a_base(): void {
		$restricted = $this->settings( $this->base . '/uploads' );

		$this->assertTrue( $restricted->isRootAllowed( $this->inside ) );
		$this->assertFalse( $restricted->isRootAllowed( $this->outside ) );
		$this->assertTrue( $this->settings( null )->isRootAllowed( $this->outside ) );
	}
}
