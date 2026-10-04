<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Thumbnails\ThumbnailLayout;

class ThumbnailLayoutTest extends TestCase {

	public function test_the_file_name_and_the_location_follow_the_thumbnails_folder_layout(): void {
		$this->assertSame( '20261002_121549-1024x577.jpg', ThumbnailLayout::fileName( '20261002_121549.jpg', 1024, 577 ) );
		$this->assertSame( 'a.b-10x10.png', ThumbnailLayout::fileName( 'a.b.png', 10, 10 ) );
		$this->assertSame(
			'/u/thumbnails/photos/2026/eric/10/02/20261002_121549-1024x577.jpg',
			ThumbnailLayout::pathForFile( '/u/thumbnails', 'photos/2026/eric/10/02/20261002_121549.jpg', '20261002_121549-1024x577.jpg' )
		);
		$this->assertSame( '/u/thumbnails/a-10x10.jpg', ThumbnailLayout::pathForFile( '/u/thumbnails/', 'a.jpg', 'a-10x10.jpg' ) );
	}

	public function test_an_unsafe_relative_path_gives_no_location(): void {
		foreach ( [ '../a.jpg', 'x/../a.jpg', '/a.jpg', 'x//a.jpg', './a.jpg', '' ] as $relative ) {
			$this->assertNull( ThumbnailLayout::pathForFile( '/u/thumbnails', $relative, 'a-1x1.jpg' ), $relative );
		}
	}

	public function test_an_entry_without_a_path_is_resolved_from_the_layout(): void {
		$this->assertSame(
			'/u/thumbnails/p/a-10x10.jpg',
			ThumbnailLayout::pathForEntry( '/u/thumbnails', 'p/a.jpg', [ 'file' => 'a-10x10.jpg' ] )
		);
		$this->assertNull( ThumbnailLayout::pathForEntry( '/u/thumbnails', 'p/a.jpg', [ 'file' => '../a-10x10.jpg' ] ) );
		$this->assertNull( ThumbnailLayout::pathForEntry( '/u/thumbnails', 'p/a.jpg', [ 'file' => '.hidden.jpg' ] ) );
		$this->assertNull( ThumbnailLayout::pathForEntry( '/u/thumbnails', 'p/a.jpg', [] ) );
	}

	public function test_a_path_written_by_thumbnails_folder_is_honored_only_inside_the_cache(): void {
		$entry = [ 'file' => 'a-10x10.jpg', 'path' => '/u/thumbnails/other/place/a-10x10.jpg' ];
		$this->assertSame( '/u/thumbnails/other/place/a-10x10.jpg', ThumbnailLayout::pathForEntry( '/u/thumbnails', 'p/a.jpg', $entry ) );

		// A path outside the cache, or with .. in it, is ignored: the layout decides.
		foreach ( [ '/etc/passwd', '/u/p/a-10x10.jpg', '/u/thumbnails/../p/a-10x10.jpg' ] as $path ) {
			$this->assertSame(
				'/u/thumbnails/p/a-10x10.jpg',
				ThumbnailLayout::pathForEntry( '/u/thumbnails', 'p/a.jpg', [ 'file' => 'a-10x10.jpg', 'path' => $path ] ),
				$path
			);
		}
	}

	public function test_urls_are_encoded_and_only_given_inside_the_uploads_directory(): void {
		$this->assertSame(
			'https://e.test/wp-content/uploads/thumbnails/p/my%20photo-10x10.jpg',
			ThumbnailLayout::url( '/var/u', 'https://e.test/wp-content/uploads', '/var/u/thumbnails/p/my photo-10x10.jpg' )
		);
		$this->assertNull( ThumbnailLayout::url( '/var/u', 'https://e.test/u', '/var/elsewhere/a.jpg' ) );
	}
}
