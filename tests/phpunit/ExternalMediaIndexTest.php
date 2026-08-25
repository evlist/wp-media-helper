<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\MediaSource\ExternalMediaIndex;

class ExternalMediaIndexTest extends TestCase {

	private string $root;
	private string $storage;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/wpmh_media_index_' . uniqid();
		$this->storage = sys_get_temp_dir() . '/wpmh_media_index_store_' . uniqid();
		mkdir( $this->root . '/2026/08', 0755, true );
		mkdir( $this->storage, 0755, true );

		touch( $this->root . '/2026/08/20260810-rando-belledonne.jpg' );
		touch( $this->root . '/2026/08/20260810-rando-belledonne.gpx' );
	}

	protected function tearDown(): void {
		foreach ( new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		) as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->root );

		if ( is_dir( $this->storage ) ) {
			foreach ( new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $this->storage, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			) as $file ) {
				$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
			}
			rmdir( $this->storage );
		}
	}

	public function test_reuses_cache_when_directory_has_not_changed(): void {
		$index = new ExternalMediaIndex( $this->storage );
		$date = new DateTimeImmutable( '2026-08-10' );
		$source = [
			'root' => $this->root,
			'path_pattern' => '{date:Y}/{date:m}',
			'filter_pattern' => '{date:Ymd}',
			'id' => 'belledonne',
		];

		$first = $index->getForSource( $source, $date, 'belledonne' );
		$second = $index->getForSource( $source, $date, 'belledonne' );

		$this->assertSame( $first, $second );
		$this->assertCount( 2, $first );
	}

	public function test_refreshes_cache_when_directory_mtime_has_changed(): void {
		$index = new ExternalMediaIndex( $this->storage );
		$date = new DateTimeImmutable( '2026-08-10' );
		$source = [
			'root' => $this->root,
			'path_pattern' => '{date:Y}/{date:m}',
			'filter_pattern' => '{date:Ymd}',
			'id' => 'belledonne',
		];

		$first = $index->getForSource( $source, $date, 'belledonne' );
		touch( $this->root . '/2026/08/20260811-autre-rando.jpg' );
		$second = $index->getForSource( $source, $date, 'belledonne' );

		$this->assertSame( $first, $second );
		$this->assertCount( 2, $second );
	}

	public function test_ignores_invalid_cached_payloads(): void {
		$index = new ExternalMediaIndex( $this->storage );
		$date = new DateTimeImmutable( '2026-08-10' );
		$source = [
			'root' => $this->root,
			'path_pattern' => '{date:Y}/{date:m}',
			'filter_pattern' => '{date:Ymd}',
			'id' => 'belledonne',
		];

		$cache = $index->pathForSource( $source, 'belledonne' );
		file_put_contents( $cache, '{invalid-json', LOCK_EX );

		$results = $index->getForSource( $source, $date, 'belledonne' );

		$this->assertCount( 2, $results );
		$this->assertSame( 'belledonne', $source['id'] );
	}
}
