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

	public function test_a_client_cannot_claim_a_path_belongs_to_another_source(): void {
		$sources = [
			[ 'id' => 'other', 'root' => $this->outside ],
			[ 'id' => 'main', 'root' => $this->root ],
		];

		$this->assertNull( PathConfinement::resolveFileInSources( $sources, 'other', $this->root . '/2026/photo.jpg' ) );
		$this->assertNull( PathConfinement::resolveFileInSources( $sources, 'unknown', $this->root . '/2026/photo.jpg' ) );
	}
}
