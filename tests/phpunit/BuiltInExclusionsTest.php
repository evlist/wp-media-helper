<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Settings\BuiltInExclusions;
use WP_Media_Helper\Settings\SourceOwnership;
use WP_Media_Helper\Settings\UploadsSource;

class BuiltInExclusionsTest extends TestCase {

	private string $base;

	protected function setUp(): void {
		$this->base = realpath( sys_get_temp_dir() ) . '/wpmh_builtin_' . uniqid();
		foreach ( [ 'woocommerce_uploads/x', 'backup-2026', 'backups', 'cache', '2026/10', 'photos', 'elementorish' ] as $directory ) {
			mkdir( $this->base . '/' . $directory, 0755, true );
		}
		file_put_contents( $this->base . '/woocommerce_uploads/x/invoice.pdf', 'x' );
		file_put_contents( $this->base . '/backup.zip', 'x' );
	}

	protected function tearDown(): void {
		foreach ( new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->base, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		) as $file ) {
			$file->isDir() && ! $file->isLink() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->base );
	}

	/**
	 * @param string[] $directories
	 * @return string[]
	 */
	private function relative( array $directories ): array {
		return array_map( fn ( string $d ): string => substr( $d, strlen( $this->base ) + 1 ), $directories );
	}

	public function test_the_defaults_cover_private_and_technical_directories_and_match_wildcards(): void {
		$found = BuiltInExclusions::directories( $this->base, BuiltInExclusions::names() );

		$this->assertSame( [ 'backup-2026', 'backups', 'cache', 'woocommerce_uploads' ], $this->relative( $found ) );
		$this->assertNotContains( 'elementorish', $this->relative( $found ), 'A wildcard-free name matches only itself.' );
	}

	public function test_unsafe_names_are_dropped(): void {
		$this->assertSame( [ 'a', 'b/c' ], BuiltInExclusions::clean( [ 'a', '/b/c/', '../x', 'a/../b', '', '.', 'x//y', 42, 'a' ] ) );
		$this->assertSame( [], BuiltInExclusions::directories( $this->base, BuiltInExclusions::clean( [ '../' . basename( $this->base ) ] ) ) );
	}

	public function test_a_source_on_uploads_does_not_list_them_but_a_deliberate_root_is_kept(): void {
		$builtIn = BuiltInExclusions::directories( $this->base, BuiltInExclusions::names() );
		$uploads = [ 'id' => 'uploads', 'name' => 'Uploads', 'root' => $this->base, 'state' => 'active' ];
		$chosen  = [ 'id' => 'backups', 'name' => 'Backups', 'root' => $this->base . '/backups', 'state' => 'active' ];

		$listing = SourceOwnership::listing( [ $uploads ], null, null, $builtIn );
		$this->assertSame( $builtIn, $listing[0]['exclusions'] );

		// A source the administrator rooted inside a listed directory is left alone.
		$listing = SourceOwnership::listing( [ $chosen ], null, null, $builtIn );
		$this->assertSame( [], $listing[0]['exclusions'] );
	}

	public function test_the_uploads_source_is_added_last_with_a_unique_name_and_id(): void {
		$photos = [ 'id' => 'photos', 'name' => 'Photos', 'root' => $this->base . '/photos', 'state' => 'active' ];

		$sources = UploadsSource::append( [ $photos ], $this->base . '/' );
		$this->assertSame( [ 'photos', 'uploads' ], array_column( $sources, 'id' ) );
		$this->assertSame( $this->base, $sources[1]['root'] );
		$this->assertSame( 'active', $sources[1]['state'] );
		$this->assertTrue( $sources[1]['mtime_fallback'] );

		$taken = [ [ 'id' => 'uploads', 'name' => 'uploads', 'root' => $this->base . '/photos' ] ];
		$again = UploadsSource::append( $taken, $this->base );
		$this->assertSame( 'uploads-2', $again[1]['id'] );
		$this->assertSame( 'Uploads 2', $again[1]['name'] );
	}

	public function test_the_button_is_offered_unless_a_source_not_disabled_has_the_uploads_directory_as_root(): void {
		$this->assertFalse( UploadsSource::isConfigured( [], $this->base ) );
		$this->assertFalse( UploadsSource::isConfigured( [ [ 'root' => $this->base . '/photos' ] ], $this->base ) );
		$this->assertTrue( UploadsSource::isConfigured( [ [ 'root' => $this->base . '/' ] ], $this->base ) );
		$this->assertTrue( UploadsSource::isConfigured( [ [ 'root' => $this->base, 'state' => 'excluded' ] ], $this->base ) );
		$this->assertFalse( UploadsSource::isConfigured( [ [ 'root' => $this->base, 'state' => 'disabled' ] ], $this->base ) );
		$this->assertFalse( UploadsSource::isConfigured( [ [ 'root' => $this->base ] ], $this->base . '/missing' ) );
	}
}
