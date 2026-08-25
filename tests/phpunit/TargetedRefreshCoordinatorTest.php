<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\MediaSource\ExternalMediaIndex;
use WP_Media_Helper\MediaSource\TargetedRefreshCoordinator;

class TargetedRefreshCoordinatorTest extends TestCase {

	private string $root;
	private string $storage;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/wpmh_refresh_coord_' . uniqid();
		$this->storage = sys_get_temp_dir() . '/wpmh_refresh_store_' . uniqid();
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

	public function test_returns_fresh_state_for_unchanged_directory(): void {
		$index = new ExternalMediaIndex( $this->storage );
		$coordinator = new TargetedRefreshCoordinator( $index );
		$date = new DateTimeImmutable( '2026-08-10' );
		$source = [
			'root' => $this->root,
			'path_pattern' => '{date:Y}/{date:m}',
			'filter_pattern' => '{date:Ymd}',
			'id' => 'belledonne',
		];

		$index->getForSource( $source, $date, 'belledonne' );
		$result = $coordinator->resolve( $source, $date, 'belledonne' );

		$this->assertFalse( $result['refresh_required'] );
		$this->assertFalse( $result['stale'] );
		$this->assertSame( $this->root . '/2026/08', $result['directory'] );
		$this->assertCount( 2, $result['files'] );
	}

	public function test_marks_result_as_stale_and_refreshes_when_directory_changes(): void {
		$index = new ExternalMediaIndex( $this->storage );
		$coordinator = new TargetedRefreshCoordinator( $index );
		$date = new DateTimeImmutable( '2026-08-10' );
		$source = [
			'root' => $this->root,
			'path_pattern' => '{date:Y}/{date:m}',
			'filter_pattern' => '{date:Ymd}',
			'id' => 'belledonne',
		];

		$index->getForSource( $source, $date, 'belledonne' );
		touch( $this->root . '/2026/08/20260811-autre-rando.jpg' );
		touch( $this->root . '/2026/08', time() + 2 );
		clearstatcache( true, $this->root . '/2026/08' );

		$result = $coordinator->resolve( $source, $date, 'belledonne' );

		$this->assertTrue( $result['refresh_required'] );
		$this->assertTrue( $result['stale'] );
		$this->assertSame( 'stale-directory', $result['reason'] );
	}

	public function test_force_refresh_bypasses_cache(): void {
		$index = new ExternalMediaIndex( $this->storage );
		$coordinator = new TargetedRefreshCoordinator( $index );
		$date = new DateTimeImmutable( '2026-08-10' );
		$source = [
			'root' => $this->root,
			'path_pattern' => '{date:Y}/{date:m}',
			'filter_pattern' => '{date:Ymd}',
			'id' => 'belledonne',
		];

		$index->getForSource( $source, $date, 'belledonne' );
		$result = $coordinator->resolve( $source, $date, 'belledonne', true );

		$this->assertTrue( $result['refresh_required'] );
		$this->assertTrue( $result['stale'] );
		$this->assertSame( 'forced-refresh', $result['reason'] );
	}
}
