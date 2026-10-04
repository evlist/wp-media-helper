<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Settings\SourceOwnership;

class SourceOwnershipTest extends TestCase {

	private string $base;

	protected function setUp(): void {
		$this->base = realpath( sys_get_temp_dir() ) . '/wpmh_owner_' . uniqid();
		mkdir( $this->base . '/uploads/photos', 0755, true );
		mkdir( $this->base . '/uploads/thumbnails', 0755, true );
		mkdir( $this->base . '/uploads/private', 0755, true );
		mkdir( $this->base . '/elsewhere', 0755, true );
	}

	protected function tearDown(): void {
		foreach ( new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->base, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		) as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->base );
	}

	/**
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	private function source( string $id, string $root, array $extra = [] ): array {
		return array_merge( [ 'id' => $id, 'name' => ucfirst( $id ), 'root' => $this->base . '/' . $root, 'state' => 'active' ], $extra );
	}

	/**
	 * @param array<int, array<string, mixed>> $listing
	 * @return array<string, string[]>
	 */
	private function exclusions( array $listing ): array {
		$result = [];
		foreach ( $listing as $source ) {
			$result[ $source['id'] ] = array_map( fn ( string $d ): string => substr( $d, strlen( $this->base ) + 1 ), $source['exclusions'] );
		}

		return $result;
	}

	public function test_a_broad_source_after_a_narrow_one_does_not_list_its_tree_nor_the_cache(): void {
		$listing = SourceOwnership::listing( [
			$this->source( 'photos', 'uploads/photos' ),
			$this->source( 'uploads', 'uploads', [ 'thumbnail_cache' => $this->base . '/uploads/thumbnails' ] ),
		] );

		$this->assertSame( [ 'photos' => [], 'uploads' => [ 'uploads/photos', 'uploads/thumbnails' ] ], $this->exclusions( $listing ) );
	}

	public function test_the_order_decides_who_owns_a_tree(): void {
		$listing = SourceOwnership::listing( [
			$this->source( 'uploads', 'uploads' ),
			$this->source( 'photos', 'uploads/photos' ),
		] );

		$this->assertSame( [ 'uploads' => [], 'photos' => [ 'uploads/photos' ] ], $this->exclusions( $listing ), 'The second source is entirely owned by the first.' );
	}

	public function test_a_disabled_source_is_ignored_and_an_excluded_one_reserves_its_tree(): void {
		$disabled = SourceOwnership::listing( [
			$this->source( 'photos', 'uploads/photos', [ 'state' => 'disabled' ] ),
			$this->source( 'uploads', 'uploads' ),
		] );
		$this->assertSame( [ 'uploads' => [] ], $this->exclusions( $disabled ), 'The files of a disabled source fall to the next source.' );

		$excluded = SourceOwnership::listing( [
			$this->source( 'private', 'uploads/private', [ 'state' => 'excluded' ] ),
			$this->source( 'uploads', 'uploads' ),
		] );
		$this->assertSame( [ 'uploads' => [ 'uploads/private' ] ], $this->exclusions( $excluded ), 'An excluded source lists nothing, and nobody lists its tree.' );
	}

	public function test_a_source_not_allowed_is_ignored_and_a_legacy_enabled_flag_still_counts(): void {
		$listing = SourceOwnership::listing(
			[
				$this->source( 'elsewhere', 'elsewhere' ),
				[ 'id' => 'old', 'name' => 'Old', 'root' => $this->base . '/uploads/photos', 'enabled' => false ],
				[ 'id' => 'legacy', 'name' => 'Legacy', 'root' => $this->base . '/uploads/private', 'enabled' => true ],
			],
			fn ( array $source ): bool => str_contains( (string) $source['root'], '/uploads' )
		);

		$this->assertSame( [ 'legacy' ], array_column( $listing, 'id' ) );
	}

	public function test_a_cache_around_a_source_removes_all_of_it(): void {
		$listing = SourceOwnership::listing( [
			$this->source( 'photos', 'uploads/photos', [ 'thumbnail_cache' => $this->base . '/uploads' ] ),
		] );

		$this->assertSame( [ 'photos' => [ 'uploads/photos' ] ], $this->exclusions( $listing ) );
	}

	public function test_shadowed_sources_are_reported_with_their_owner(): void {
		$shadowed = SourceOwnership::shadowed( [
			$this->source( 'uploads', 'uploads' ),
			$this->source( 'photos', 'uploads/photos' ),
			$this->source( 'other', 'elsewhere' ),
		] );

		$this->assertSame( [ 1 => 'Uploads' ], $shadowed );
		$this->assertSame( [], SourceOwnership::shadowed( [ $this->source( 'photos', 'uploads/photos' ), $this->source( 'uploads', 'uploads' ) ] ) );
	}

	public function test_the_fingerprint_changes_with_the_exclusions_only(): void {
		$a = SourceOwnership::listing( [ $this->source( 'uploads', 'uploads' ) ] )[0];
		$b = SourceOwnership::listing( [ $this->source( 'photos', 'uploads/photos' ), $this->source( 'uploads', 'uploads' ) ] )[1];

		$this->assertSame( '', SourceOwnership::fingerprint( $a ) );
		$this->assertNotSame( SourceOwnership::fingerprint( $a ), SourceOwnership::fingerprint( $b ) );
	}
}
