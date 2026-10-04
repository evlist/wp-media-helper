<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\MediaSource\PathConfinement;

class PathConfinementTest extends TestCase {

	private string $base;
	private string $root;
	private string $outside;

	protected function setUp(): void {
		$this->base = realpath( sys_get_temp_dir() ) . '/wpmh_confinement_' . uniqid();
		$this->root = $this->base . '/root';
		$this->outside = $this->base . '/root-sibling';
		mkdir( $this->root . '/2026', 0755, true );
		mkdir( $this->outside, 0755, true );

		touch( $this->root . '/2026/photo.jpg' );
		touch( $this->outside . '/secret.txt' );
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

	public function test_accepts_a_file_below_the_root(): void {
		$this->assertSame(
			$this->root . '/2026/photo.jpg',
			PathConfinement::resolveFileWithinRoot( $this->root, $this->root . '/2026/photo.jpg' )
		);
	}

	public function test_rejects_a_file_outside_the_root(): void {
		$this->assertNull( PathConfinement::resolveFileWithinRoot( $this->root, $this->outside . '/secret.txt' ) );
		$this->assertNull( PathConfinement::resolveFileWithinRoot( $this->root, '/etc/passwd' ) );
	}

	public function test_rejects_dot_dot_traversal(): void {
		$this->assertNull( PathConfinement::resolveFileWithinRoot( $this->root, $this->root . '/2026/../../root-sibling/secret.txt' ) );
	}

	public function test_rejects_a_sibling_directory_sharing_the_root_prefix(): void {
		$this->assertNull( PathConfinement::resolveFileWithinRoot( $this->root, $this->base . '/root-sibling/secret.txt' ) );
	}

	public function test_rejects_a_symlink_pointing_outside_the_root(): void {
		symlink( $this->outside . '/secret.txt', $this->root . '/2026/link.jpg' );

		$this->assertNull( PathConfinement::resolveFileWithinRoot( $this->root, $this->root . '/2026/link.jpg' ) );
	}

	public function test_rejects_directories_missing_files_empty_values_and_null_bytes(): void {
		$this->assertNull( PathConfinement::resolveFileWithinRoot( $this->root, $this->root . '/2026' ) );
		$this->assertNull( PathConfinement::resolveFileWithinRoot( $this->root, $this->root . '/missing.jpg' ) );
		$this->assertNull( PathConfinement::resolveFileWithinRoot( $this->root, '' ) );
		$this->assertNull( PathConfinement::resolveFileWithinRoot( '', $this->root . '/2026/photo.jpg' ) );
		$this->assertNull( PathConfinement::resolveFileWithinRoot( $this->root, $this->root . "/2026/photo.jpg\0.txt" ) );
	}

	public function test_resolves_the_source_that_owns_the_path(): void {
		$sources = [
			[ 'id' => 'other', 'root' => $this->outside ],
			[ 'id' => 'main', 'root' => $this->root ],
		];

		$resolved = PathConfinement::resolveFileInSources( $sources, '', $this->root . '/2026/photo.jpg' );

		$this->assertSame( 'main', $resolved['source']['id'] );
		$this->assertSame( $this->root . '/2026/photo.jpg', $resolved['path'] );
	}

	public function test_the_owner_is_the_first_source_whose_tree_holds_the_file_whatever_the_client_claims(): void {
		mkdir( $this->root . '/photos', 0755, true );
		touch( $this->root . '/photos/a.jpg' );
		$sources = [
			[ 'id' => 'photos', 'root' => $this->root . '/photos', 'exclusions' => [] ],
			[ 'id' => 'uploads', 'root' => $this->root, 'exclusions' => [ $this->root . '/photos' ] ],
		];

		// Claiming 'uploads' for a file of 'photos' does not make 'uploads' its owner.
		$this->assertSame( 'photos', PathConfinement::resolveFileInSources( $sources, 'uploads', $this->root . '/photos/a.jpg' )['source']['id'] );
		$this->assertSame( 'uploads', PathConfinement::resolveFileInSources( $sources, 'photos', $this->root . '/2026/photo.jpg' )['source']['id'] );
		$this->assertNull( PathConfinement::resolveFileInSources( $sources, 'uploads', $this->outside . '/secret.txt' ) );
	}

	public function test_a_file_owned_by_a_source_that_is_not_active_is_refused(): void {
		// 'photos' is excluded or disabled upstream: only 'uploads' is listed, and it excludes that tree.
		mkdir( $this->root . '/photos', 0755, true );
		touch( $this->root . '/photos/a.jpg' );
		$sources = [ [ 'id' => 'uploads', 'root' => $this->root, 'exclusions' => [ $this->root . '/photos' ] ] ];

		$this->assertNull( PathConfinement::resolveFileInSources( $sources, 'uploads', $this->root . '/photos/a.jpg' ) );
	}
}
