<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\MediaSource\SourceIndexer;

class SourceIndexerTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/wpmh_indexer_' . uniqid();
		mkdir( $this->root . '/2026/08', 0755, true );
		mkdir( $this->root . '/2026/07', 0755, true );

		touch( $this->root . '/2026/08/20260810-rando-belledonne.jpg' );
		touch( $this->root . '/2026/08/20260810-rando-belledonne.gpx' );
		touch( $this->root . '/2026/08/20260811-autre-rando.jpg' );
		touch( $this->root . '/2026/07/20260715-ancienne-rando.jpg' );
	}

	protected function tearDown(): void {
		foreach ( new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		) as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->root );
	}

	public function test_indexes_files_for_a_resolved_date_directory(): void {
		$indexer = new SourceIndexer();
		$date     = new DateTimeImmutable( '2026-08-10' );

		$results = $indexer->findForDate(
			[
				'root' => $this->root,
				'path_pattern' => '{date:Y}/{date:m}',
				'filter_pattern' => '{date:Ymd}',
				'id' => 'belledonne',
			],
			$date,
			'belledonne'
		);

		$this->assertSame(
			[
				$this->root . '/2026/08/20260810-rando-belledonne.gpx',
				$this->root . '/2026/08/20260810-rando-belledonne.jpg',
			],
			$results
		);
	}

	public function test_uses_source_root_when_path_pattern_is_empty(): void {
		$indexer = new SourceIndexer();
		$date     = new DateTimeImmutable( '2026-08-10' );

		$results = $indexer->findForDate(
			[
				'root' => $this->root,
				'path_pattern' => '',
				'filter_pattern' => '',
			],
			$date,
			'belledonne'
		);

		$this->assertCount( 4, $results );
		foreach ( $results as $path ) {
			$this->assertStringStartsWith( $this->root . '/', $path );
		}
	}

	public function test_rejects_invalid_pattern_in_source_configuration(): void {
		$indexer = new SourceIndexer();
		$date     = new DateTimeImmutable( '2026-08-10' );

		$this->expectException( InvalidArgumentException::class );
		$indexer->findForDate(
			[
				'root' => $this->root,
				'path_pattern' => '{date:Y/{date:m}',
			],
			$date
		);
	}
}
