<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Settings\DirectoryBrowser;

class DirectoryBrowserTest extends TestCase {

	private string $root;
	private string $base;

	protected function setUp(): void {
		$this->root = realpath( sys_get_temp_dir() ) . '/wpmh_browse_' . uniqid();
		$this->base = $this->root . '/uploads';
		foreach ( [ 'uploads/photos/2026/10', 'uploads/photos/2025', 'uploads/Gpx', 'uploads/.hidden', 'uploads/thumbnails', 'uploads/img10', 'uploads/img2', 'outside/secret' ] as $directory ) {
			mkdir( $this->root . '/' . $directory, 0755, true );
		}
		file_put_contents( $this->base . '/file.txt', 'x' );
		symlink( $this->root . '/outside', $this->base . '/linked' );
	}

	protected function tearDown(): void {
		foreach ( new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		) as $file ) {
			$file->isDir() && ! $file->isLink() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->root );
	}

	/**
	 * @param array{directories:array<int, array{name:string}>}|null $listing
	 * @return string[]
	 */
	private function names( ?array $listing ): array {
		return array_column( $listing['directories'] ?? [], 'name' );
	}

	public function test_the_base_lists_its_directories_in_natural_order_without_files_links_or_dot_directories(): void {
		$listing = DirectoryBrowser::browse( $this->base, '' );

		$this->assertSame( [ 'Gpx', 'img2', 'img10', 'photos', 'thumbnails' ], $this->names( $listing ) );
		$this->assertSame( '', $listing['path'] );
		$this->assertNull( $listing['parent'] );
		$this->assertFalse( $listing['truncated'] );
		$this->assertSame( 'photos', $listing['directories'][3]['path'] );
	}

	public function test_a_sub_directory_gives_relative_paths_and_its_parent(): void {
		$listing = DirectoryBrowser::browse( $this->base, './photos/' );
		$this->assertSame( [ '2025', '2026' ], $this->names( $listing ) );
		$this->assertSame( 'photos', $listing['path'] );
		$this->assertSame( '', $listing['parent'] );
		$this->assertSame( 'photos/2026', $listing['directories'][1]['path'] );

		$deeper = DirectoryBrowser::browse( $this->base, 'photos/2026' );
		$this->assertSame( 'photos', $deeper['parent'] );
		$this->assertSame( [ '10' ], $this->names( $deeper ) );
	}

	public function test_a_dot_means_the_base_itself(): void {
		$this->assertSame( $this->names( DirectoryBrowser::browse( $this->base, '' ) ), $this->names( DirectoryBrowser::browse( $this->base, '.' ) ) );
	}

	public function test_nothing_outside_the_base_can_be_listed(): void {
		$this->assertNull( DirectoryBrowser::browse( $this->base, '..' ) );
		$this->assertNull( DirectoryBrowser::browse( $this->base, '../outside' ) );
		$this->assertNull( DirectoryBrowser::browse( $this->base, 'photos/../../outside' ) );
		$this->assertNull( DirectoryBrowser::browse( $this->base, 'linked' ), 'A link to a directory outside leads out of the base.' );
		$this->assertNull( DirectoryBrowser::browse( $this->base, 'photos/missing' ) );
		$this->assertNull( DirectoryBrowser::browse( $this->base, "photos\0" ) );
		$this->assertNull( DirectoryBrowser::browse( $this->base . '/nowhere', '' ) );
		$this->assertNull( DirectoryBrowser::browse( $this->base, 'file.txt' ), 'A file is not a directory.' );
	}

	public function test_reserved_directories_are_marked(): void {
		$listing = DirectoryBrowser::browse( $this->base, '', [ $this->base . '/thumbnails', $this->base . '/photos/2025' ] );
		$reserved = array_column( $listing['directories'], 'reserved', 'name' );

		$this->assertTrue( $reserved['thumbnails'] );
		$this->assertFalse( $reserved['photos'] );
		$this->assertTrue( array_column( DirectoryBrowser::browse( $this->base, 'photos', [ $this->base . '/photos/2025' ] )['directories'], 'reserved', 'name' )['2025'] );
	}

	public function test_the_number_of_entries_is_limited(): void {
		for ( $i = 0; $i < DirectoryBrowser::LIMIT + 5; ++$i ) {
			mkdir( $this->base . '/many/d' . $i, 0755, true );
		}

		$listing = DirectoryBrowser::browse( $this->base, 'many' );

		$this->assertCount( DirectoryBrowser::LIMIT, $listing['directories'] );
		$this->assertTrue( $listing['truncated'] );
	}

	public function test_paths_are_cleaned(): void {
		$this->assertSame( 'a/b', DirectoryBrowser::clean( '/./a//b/.' ) );
		$this->assertSame( '', DirectoryBrowser::clean( '.' ) );
		$this->assertSame( 'a/b', DirectoryBrowser::clean( 'a\\b' ) );
		$this->assertNull( DirectoryBrowser::clean( 'a/../b' ) );
	}
}
