<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Maintenance\Reset;

class ResetTest extends TestCase {

	private string $base;

	protected function setUp(): void {
		$this->base = realpath( sys_get_temp_dir() ) . '/wpmh_reset_' . uniqid();
		mkdir( $this->base . '/uploads/thumbnails/photos/2026', 0755, true );
		mkdir( $this->base . '/outside', 0755 );
		file_put_contents( $this->base . '/uploads/thumbnails/photos/2026/a-150x150.jpg', 'x' );
		file_put_contents( $this->base . '/uploads/thumbnails/b-10x10.jpg', 'x' );
		file_put_contents( $this->base . '/uploads/original.jpg', 'keep' );
		file_put_contents( $this->base . '/outside/secret.txt', 'keep' );
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

	public function test_the_cache_is_deleted_with_its_directories_and_nothing_else(): void {
		$this->assertSame( 2, Reset::deleteTree( $this->base . '/uploads/thumbnails', $this->base . '/uploads' ) );

		$this->assertFalse( is_dir( $this->base . '/uploads/thumbnails' ) );
		$this->assertTrue( is_file( $this->base . '/uploads/original.jpg' ) );
	}

	public function test_a_link_in_the_cache_is_removed_but_never_followed(): void {
		symlink( $this->base . '/outside', $this->base . '/uploads/thumbnails/photos/link' );
		symlink( $this->base . '/outside/secret.txt', $this->base . '/uploads/thumbnails/filelink' );

		Reset::deleteTree( $this->base . '/uploads/thumbnails', $this->base . '/uploads' );

		$this->assertTrue( is_file( $this->base . '/outside/secret.txt' ), 'What a link points to is kept.' );
		$this->assertFalse( is_dir( $this->base . '/uploads/thumbnails' ) );
	}

	public function test_a_directory_that_is_not_strictly_inside_the_limit_is_refused(): void {
		$this->assertSame( 0, Reset::deleteTree( $this->base . '/uploads', $this->base . '/uploads' ) );
		$this->assertSame( 0, Reset::deleteTree( $this->base . '/outside', $this->base . '/uploads' ) );
		$this->assertSame( 0, Reset::deleteTree( $this->base . '/uploads/thumbnails/../../outside', $this->base . '/uploads' ) );
		$this->assertTrue( is_file( $this->base . '/outside/secret.txt' ) );

		symlink( $this->base . '/outside', $this->base . '/uploads/linked' );
		$this->assertSame( 0, Reset::deleteTree( $this->base . '/uploads/linked', $this->base . '/uploads' ) );
		$this->assertTrue( is_file( $this->base . '/outside/secret.txt' ) );
	}

	public function test_only_known_items_are_listed(): void {
		$this->assertSame( [ 'settings', 'index', 'post_dates', 'user_data', 'thumbnails', 'cache', 'attachments' ], Reset::items() );
	}

	public function test_a_filter_on_the_names_keeps_every_other_file(): void {
		file_put_contents( $this->base . '/uploads/thumbnails/photos/2026/original.jpg', 'keep' );
		file_put_contents( $this->base . '/uploads/thumbnails/photos/2026/.0123abcd-a-150x150.jpg', 'x' );
		file_put_contents( $this->base . '/uploads/thumbnails/notes.txt', 'keep' );
		$thumbnail = static fn ( string $name ): bool => 1 === preg_match( '/^.+-\d+x\d+\.[A-Za-z0-9]+$/', $name ) || 1 === preg_match( '/^\.[0-9a-f]{8}-.+/', $name );

		$deleted = Reset::deleteTree( $this->base . '/uploads/thumbnails', $this->base . '/uploads', $thumbnail );

		$this->assertSame( 3, $deleted, 'The two thumbnails and the temporary file.' );
		$this->assertTrue( is_file( $this->base . '/uploads/thumbnails/photos/2026/original.jpg' ), 'A file that is not named like a thumbnail stays.' );
		$this->assertTrue( is_file( $this->base . '/uploads/thumbnails/notes.txt' ) );
		$this->assertTrue( is_dir( $this->base . '/uploads/thumbnails/photos/2026' ), 'A directory that still holds a file stays.' );
	}
}
