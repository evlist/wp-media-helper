<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Thumbnails\ThumbnailService;

class ThumbnailServiceTest extends TestCase {

	private string $base;
	private string $cache;
	private int $calls = 0;

	protected function setUp(): void {
		$this->base = realpath( sys_get_temp_dir() ) . '/wpmh_thumbs_' . uniqid();
		$this->cache = $this->base . '/thumbnails';
		mkdir( $this->base . '/photos/2026', 0755, true );
		file_put_contents( $this->base . '/photos/2026/a.jpg', 'original' );
		$this->calls = 0;
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

	private function service( ?callable $resizer = null ): ThumbnailService {
		$resizer ??= function ( string $source, string $destination, int $width, int $height ): array {
			++$this->calls;
			file_put_contents( $destination, 'thumb ' . $width . 'x' . $height );

			return [ $width, $height ];
		};

		return new ThumbnailService( $this->base, 'https://e.test/uploads', $this->cache, $resizer );
	}

	public function test_a_size_is_created_in_the_cache_with_the_core_keys_and_no_path(): void {
		$entry = $this->service()->ensure( 'photos/2026/a.jpg', 300, 200, true, 'image/jpeg' );

		$this->assertSame( [ 'file', 'width', 'height', 'mime-type', 'filesize' ], array_keys( $entry ) );
		$this->assertSame( 'a-300x200.jpg', $entry['file'] );
		$this->assertSame( 300, $entry['width'] );
		$this->assertSame( 'image/jpeg', $entry['mime-type'] );
		$this->assertSame( 'thumb 300x200', file_get_contents( $this->cache . '/photos/2026/a-300x200.jpg' ) );
		$this->assertSame( 'original', file_get_contents( $this->base . '/photos/2026/a.jpg' ), 'The original is never touched.' );
		$this->assertFalse( file_exists( $this->base . '/photos/2026/a-300x200.jpg' ), 'Nothing is written next to the original.' );
		$this->assertSame( [], glob( $this->cache . '/photos/2026/.*-a-*' ) ?: [], 'No temporary file is left.' );
	}

	public function test_an_existing_file_is_reused_not_regenerated(): void {
		$service = $this->service();
		$service->ensure( 'photos/2026/a.jpg', 300, 200, true, 'image/jpeg' );
		$again = $service->ensure( 'photos/2026/a.jpg', 300, 200, true, 'image/jpeg' );

		$this->assertSame( 1, $this->calls );
		$this->assertSame( 'a-300x200.jpg', $again['file'] );
	}

	public function test_the_real_size_written_by_the_editor_is_recorded(): void {
		$service = $this->service( static function ( string $source, string $destination ): array {
			file_put_contents( $destination, 'x' );

			return [ 200, 300 ]; // Rotated by the EXIF orientation.
		} );

		$entry = $service->ensure( 'photos/2026/a.jpg', 300, 200, false, 'image/jpeg' );

		$this->assertSame( 200, $entry['width'] );
		$this->assertSame( 300, $entry['height'] );
	}

	public function test_a_failing_or_missing_original_gives_nothing_and_leaves_no_file(): void {
		$failing = $this->service( static fn (): ?array => null );
		$this->assertNull( $failing->ensure( 'photos/2026/a.jpg', 10, 10, false, 'image/jpeg' ) );
		$this->assertNull( $this->service()->ensure( 'photos/2026/missing.jpg', 10, 10, false, 'image/jpeg' ) );
		$this->assertSame( [], glob( $this->cache . '/photos/2026/*' ) ?: [] );
	}

	public function test_unsafe_relative_paths_are_refused(): void {
		touch( $this->base . '/secret.jpg' );

		$this->assertNull( $this->service()->ensure( '../secret.jpg', 10, 10, false, 'image/jpeg' ) );
		$this->assertNull( $this->service()->ensure( 'photos/../secret.jpg', 10, 10, false, 'image/jpeg' ) );
		$this->assertFalse( file_exists( $this->base . '/../secret-10x10.jpg' ) );
		$this->assertSame( 0, $this->calls );
	}

	public function test_a_link_in_the_cache_cannot_lead_the_files_out_of_it(): void {
		mkdir( $this->base . '/outside', 0755 );
		mkdir( $this->cache, 0755 );
		symlink( $this->base . '/outside', $this->cache . '/photos' );

		$this->assertNull( $this->service()->ensure( 'photos/2026/a.jpg', 10, 10, false, 'image/jpeg' ) );
		$this->assertSame( [], glob( $this->base . '/outside/*' ) ?: [] );
		$this->assertSame( 0, $this->calls );
	}

	public function test_a_link_as_original_is_refused(): void {
		symlink( $this->base . '/photos/2026/a.jpg', $this->base . '/photos/link.jpg' );

		$this->assertNull( $this->service()->ensure( 'photos/link.jpg', 10, 10, false, 'image/jpeg' ) );
	}

	public function test_existing_finds_files_with_or_without_a_path_and_urls_point_to_the_cache(): void {
		$service = $this->service();
		$entry = $service->ensure( 'photos/2026/a.jpg', 300, 200, true, 'image/jpeg' );

		$path = $service->existing( 'photos/2026/a.jpg', $entry );
		$this->assertSame( $this->cache . '/photos/2026/a-300x200.jpg', $path );
		$this->assertSame( 'https://e.test/uploads/thumbnails/photos/2026/a-300x200.jpg', $service->url( $path ) );

		$legacy = [ 'file' => 'a-300x200.jpg', 'path' => $this->cache . '/photos/2026/a-300x200.jpg' ];
		$this->assertSame( $path, $service->existing( 'photos/2026/a.jpg', $legacy ) );
		$this->assertNull( $service->existing( 'photos/2026/a.jpg', [ 'file' => 'a-1x1.jpg' ] ) );
	}

	public function test_purge_deletes_the_cached_files_only(): void {
		$service = $this->service();
		$metadata = [ 'sizes' => [
			'medium' => $service->ensure( 'photos/2026/a.jpg', 300, 200, true, 'image/jpeg' ),
			'large' => $service->ensure( 'photos/2026/a.jpg', 1024, 683, true, 'image/jpeg' ),
			// An entry whose file is next to the original (made by WordPress): not in the cache, not ours.
			'old' => [ 'file' => 'a.jpg', 'path' => $this->base . '/photos/2026/a.jpg' ],
		] ];

		$this->assertSame( 2, $service->purge( 'photos/2026/a.jpg', $metadata ) );
		$this->assertFalse( file_exists( $this->cache . '/photos/2026/a-300x200.jpg' ) );
		$this->assertTrue( file_exists( $this->base . '/photos/2026/a.jpg' ), 'The original is never deleted.' );
	}

	public function test_purge_for_deletes_every_size_of_a_file_and_only_those(): void {
		$service = $this->service();
		$service->ensure( 'photos/2026/a.jpg', 150, 150, true, 'image/jpeg' );
		$service->ensure( 'photos/2026/a.jpg', 1024, 683, false, 'image/jpeg' );
		file_put_contents( $this->base . '/photos/2026/ab.jpg', 'x' );
		$service->ensure( 'photos/2026/ab.jpg', 150, 150, true, 'image/jpeg' );
		file_put_contents( $this->base . '/photos/2026/b.jpg', 'x' );
		$service->ensure( 'photos/2026/b.jpg', 150, 150, true, 'image/jpeg' );
		file_put_contents( $this->cache . '/photos/2026/a-notasize.jpg', 'keep' );
		file_put_contents( $this->cache . '/photos/2026/a-10x10.png', 'keep' );

		$this->assertSame( 2, $service->purgeFor( 'photos/2026/a.jpg' ) );

		$this->assertSame( [ 'a-10x10.png', 'a-notasize.jpg', 'ab-150x150.jpg', 'b-150x150.jpg' ], array_map( 'basename', glob( $this->cache . '/photos/2026/*' ) ) );
		$this->assertTrue( is_file( $this->base . '/photos/2026/a.jpg' ), 'The original is kept.' );
		$this->assertSame( 0, $service->purgeFor( 'photos/2026/a.jpg' ), 'Nothing left to delete.' );
		$this->assertSame( 0, $service->purgeFor( '../a.jpg' ) );
		$this->assertSame( 0, $service->purgeFor( 'nowhere/a.jpg' ) );
	}

	public function test_purge_for_does_not_follow_a_link_in_the_cache(): void {
		mkdir( $this->base . '/outside', 0755 );
		file_put_contents( $this->base . '/outside/a-150x150.jpg', 'keep' );
		mkdir( $this->cache, 0755 );
		symlink( $this->base . '/outside', $this->cache . '/photos' );

		$this->assertSame( 0, $this->service()->purgeFor( 'photos/a.jpg' ) );
		$this->assertTrue( is_file( $this->base . '/outside/a-150x150.jpg' ) );
	}

	public function test_a_relative_address_is_resolved_to_the_cache_or_the_uploads(): void {
		$service = $this->service();
		$service->ensure( 'photos/2026/a.jpg', 480, 270, true, 'image/jpeg' );

		$this->assertSame( 'https://e.test/uploads/thumbnails/photos/2026/a-480x270.jpg', $service->urlForRelative( 'photos/2026/a-480x270.jpg' ) );
		$this->assertSame( 'https://e.test/uploads/photos/2026/a.jpg', $service->urlForRelative( 'photos/2026/a.jpg' ) );
		$this->assertNull( $service->urlForRelative( 'photos/2026/missing-1x1.jpg' ) );
		$this->assertNull( $service->urlForRelative( '../etc/passwd' ) );
		$this->assertNull( $service->urlForRelative( 'photos/../../x' ) );
	}
}
