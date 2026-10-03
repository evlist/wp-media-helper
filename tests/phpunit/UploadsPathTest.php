<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\MediaSource\UploadsPath;

class UploadsPathTest extends TestCase {

	private string $base;

	protected function setUp(): void {
		$this->base = realpath( sys_get_temp_dir() ) . '/wpmh_uploads_' . uniqid();
		mkdir( $this->base . '/photos/2026', 0755, true );
		touch( $this->base . '/photos/2026/a.jpg' );
	}

	protected function tearDown(): void {
		foreach ( [ $this->base . '/photos/2026/a.jpg', $this->base . '/photos/2026', $this->base . '/photos', $this->base . '-link', $this->base ] as $path ) {
			if ( is_link( $path ) ) {
				unlink( $path );
			} elseif ( is_file( $path ) ) {
				unlink( $path );
			} elseif ( is_dir( $path ) ) {
				rmdir( $path );
			}
		}
	}

	public function test_relative_key_is_the_path_below_the_base(): void {
		$this->assertSame( 'photos/2026/a.jpg', UploadsPath::relativeKey( $this->base . '/photos/2026/a.jpg', $this->base ) );
		$this->assertSame( 'photos/2026/a.jpg', UploadsPath::relativeKey( $this->base . '/photos/2026/a.jpg', $this->base . '/' ) );
		$this->assertSame( 'a b/c.jpg', UploadsPath::relativeKey( '/srv/uploads/a b/c.jpg', '/srv/uploads' ) );
	}

	public function test_relative_key_rejects_files_outside_the_base_and_dot_segments(): void {
		$this->assertNull( UploadsPath::relativeKey( '/etc/passwd', '/srv/uploads' ) );
		$this->assertNull( UploadsPath::relativeKey( '/srv/uploads-other/a.jpg', '/srv/uploads' ) );
		$this->assertNull( UploadsPath::relativeKey( '/srv/uploads/../etc/a.jpg', '/srv/uploads' ) );
		$this->assertNull( UploadsPath::relativeKey( '/srv/uploads/a/./b.jpg', '/srv/uploads' ) );
		$this->assertNull( UploadsPath::relativeKey( '/srv/uploads', '/srv/uploads' ) );
		$this->assertNull( UploadsPath::relativeKey( '', '/srv/uploads' ) );
		$this->assertNull( UploadsPath::relativeKey( "/srv/uploads/a\0.jpg", '/srv/uploads' ) );
	}

	public function test_relative_key_follows_a_symbolic_link_in_the_base(): void {
		symlink( $this->base, $this->base . '-link' );

		$this->assertSame( 'photos/2026/a.jpg', UploadsPath::relativeKey( $this->base . '/photos/2026/a.jpg', $this->base . '-link' ) );
		$this->assertSame( 'photos/2026/a.jpg', UploadsPath::relativeKey( $this->base . '-link/photos/2026/a.jpg', $this->base ) );
	}

	public function test_relative_key_normalizes_windows_separators(): void {
		if ( '\\' !== DIRECTORY_SEPARATOR ) {
			$this->markTestSkipped( 'Backslashes are separators on Windows only.' );
		}

		$this->assertSame( 'a/b.jpg', UploadsPath::relativeKey( 'C:\\www\\uploads\\a\\b.jpg', 'C:/www/uploads' ) );
	}

	public function test_lookup_keys_include_the_relative_and_the_legacy_absolute_forms(): void {
		$path = $this->base . '/photos/2026/a.jpg';

		$keys = UploadsPath::lookupKeys( $path, $this->base );

		$this->assertSame( [ 'photos/2026/a.jpg', $path ], $keys );
	}

	public function test_encode_path_encodes_each_segment_once(): void {
		$this->assertSame( 'photos/a%20b/c%23d%3F.jpg', UploadsPath::encodePath( 'photos/a b/c#d?.jpg' ) );
		$this->assertSame( 'a%2520b.jpg', UploadsPath::encodePath( 'a%20b.jpg' ) );
		$this->assertSame( 'caf%C3%A9/%C3%A9t%C3%A9.jpg', UploadsPath::encodePath( 'café/été.jpg' ) );
		$this->assertSame( 'photos/2026/a-1_b.~jpg', UploadsPath::encodePath( 'photos/2026/a-1_b.~jpg' ) );
	}

	public function test_needs_encoding_and_url(): void {
		$this->assertFalse( UploadsPath::needsEncoding( 'photos/2026/a.jpg' ) );
		$this->assertTrue( UploadsPath::needsEncoding( 'photos/a b.jpg' ) );
		$this->assertSame( 'https://e.example/wp-content/uploads/photos/a%20b.jpg', UploadsPath::url( 'https://e.example/wp-content/uploads/', 'photos/a b.jpg' ) );
	}

	public function test_a_backslash_is_a_legal_file_name_character_on_unix(): void {
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$this->markTestSkipped( 'Unix only.' );
		}

		$this->assertSame( 'a\\b.jpg', UploadsPath::relativeKey( '/srv/uploads/a\\b.jpg', '/srv/uploads' ) );
	}
}
