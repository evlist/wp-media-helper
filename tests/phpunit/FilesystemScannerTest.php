<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\MediaSource\FilesystemScanner;

class FilesystemScannerTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/wpmh_test_' . uniqid();
		mkdir( $this->root . '/2026/08', 0755, true );
		mkdir( $this->root . '/2026/07', 0755, true );

		touch( $this->root . '/2026/08/20260810-rando-belledonne.jpg' );
		touch( $this->root . '/2026/08/20260810-rando-belledonne.gpx' );
		touch( $this->root . '/2026/08/20260811-autre-rando.jpg' );
		touch( $this->root . '/2026/07/20260715-ancienne-rando.jpg' );
	}

	protected function tearDown(): void {
		$this->removeDir( $this->root );
	}

	public function test_finds_files_matching_filter(): void {
		$scanner = new FilesystemScanner();
		$results = $scanner->find( $this->root, '20260810' );

		$names = array_map( 'basename', $results );
		sort( $names );

		$this->assertSame(
			[ '20260810-rando-belledonne.gpx', '20260810-rando-belledonne.jpg' ],
			$names
		);
	}

	public function test_returns_empty_array_when_no_match(): void {
		$scanner = new FilesystemScanner();
		$results = $scanner->find( $this->root, '99991231' );

		$this->assertSame( [], $results );
	}

	public function test_searches_recursively(): void {
		$scanner = new FilesystemScanner();
		$results = $scanner->find( $this->root, '20260715' );

		$this->assertCount( 1, $results );
		$this->assertStringContainsString( '20260715', basename( $results[0] ) );
	}

	public function test_returns_absolute_paths(): void {
		$scanner = new FilesystemScanner();
		$results = $scanner->find( $this->root, '20260810' );

		foreach ( $results as $path ) {
			$this->assertStringStartsWith( '/', $path );
		}
	}

	private function removeDir( string $dir ): void {
		foreach ( new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		) as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $dir );
	}
}
