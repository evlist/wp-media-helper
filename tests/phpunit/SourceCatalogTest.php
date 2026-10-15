<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Settings\ExternalSourceSettings;
use WP_Media_Helper\Settings\SourceCatalog;

class SourceCatalogTest extends TestCase {

	private string $base;
	/** @var array<int, array<string, mixed>> */
	private array $stored = [];

	protected function setUp(): void {
		$this->base = realpath( sys_get_temp_dir() ) . '/wpmh_catalog_' . uniqid();
		foreach ( [ 'cumulus/eric', 'cumulus/gisele', 'photos/eric' ] as $directory ) {
			mkdir( $this->base . '/' . $directory, 0755, true );
		}
		$this->stored = [
			[ 'id' => 'old-eric', 'name' => 'Eric 2024', 'state' => 'active', 'root' => $this->base . '/photos/eric', 'path_pattern' => '', 'name_patterns' => [], 'mtime_fallback' => true ],
			[ 'id' => 'uploads', 'name' => 'Uploads', 'state' => 'active', 'root' => $this->base, 'path_pattern' => '', 'name_patterns' => [], 'mtime_fallback' => true ],
		];
	}

	protected function tearDown(): void {
		foreach ( [ 'cumulus/eric', 'cumulus/gisele', 'cumulus', 'photos/eric', 'photos' ] as $directory ) {
			@rmdir( $this->base . '/' . $directory );
		}
		rmdir( $this->base );
	}

	private function catalog(): SourceCatalog {
		$base = $this->base;

		return new SourceCatalog(
			new ExternalSourceSettings(
				fn(): mixed => $this->stored,
				function ( array $value ): void {
					$this->stored = $value;
				},
				static fn(): ?string => $base
			),
			$base
		);
	}

	public function test_the_rows_show_the_roots_relative_to_the_base_with_their_rank_and_attachments(): void {
		$rows = $this->catalog()->rows( [ 'old-eric' => 12 ] );

		$this->assertSame( 'photos/eric', $rows[0]['root'] );
		$this->assertSame( 1, $rows[0]['rank'] );
		$this->assertSame( 12, $rows[0]['attachments'] );
		$this->assertSame( '.', $rows[1]['root'] );
		$this->assertSame( 'active', $rows[1]['state'] );
	}

	public function test_a_source_is_added_before_another_and_gets_an_id(): void {
		$result = $this->catalog()->add( [ 'root' => 'cumulus/eric', 'name' => 'eric', 'path_pattern' => '{date:Y}/{date:m}/{date:d}' ], 'uploads' );

		$this->assertSame( 'added', $result['status'] );
		$this->assertSame( 'eric', $result['id'] );
		$this->assertSame( 2, $result['rank'] );
		$this->assertSame( [ 'old-eric', 'eric', 'uploads' ], array_column( $result['sources'], 'id' ) );
		$this->assertSame( $this->base . '/cumulus/eric', $result['sources'][1]['root'] );
		$this->assertSame( '{date:Y}/{date:m}/{date:d}', $result['sources'][1]['path_pattern'] );
		$this->assertTrue( $result['sources'][1]['mtime_fallback'] );
	}

	public function test_the_name_defaults_to_the_last_segment_of_the_root_and_the_source_goes_last_by_default(): void {
		$result = $this->catalog()->add( [ 'root' => 'cumulus/gisele' ] );

		$this->assertSame( 'gisele', $result['sources'][2]['name'] );
		$this->assertSame( 3, $result['rank'] );
	}

	public function test_a_source_with_the_same_root_is_not_added_again(): void {
		$catalog = $this->catalog();
		$first   = $catalog->add( [ 'root' => 'cumulus/eric', 'name' => 'eric' ], 'uploads' );
		$catalog->save( $first['sources'] );

		$second = $catalog->add( [ 'root' => 'cumulus/eric/', 'name' => 'other' ], 'uploads' );

		$this->assertSame( 'exists', $second['status'] );
		$this->assertSame( 'eric', $second['id'] );
		$this->assertSame( 3, count( $second['sources'] ) );
	}

	public function test_a_root_that_does_not_exist_or_leaves_the_base_is_refused(): void {
		foreach ( [ 'cumulus/nowhere', '/tmp', '' ] as $root ) {
			$refused = false;
			try {
				$this->catalog()->add( [ 'root' => $root ] );
			} catch ( InvalidArgumentException $exception ) {
				$refused = true;
			}
			$this->assertTrue( $refused, $root );
		}
	}

	public function test_a_source_is_found_by_id_root_or_name_and_an_unknown_one_is_refused(): void {
		$catalog = $this->catalog();

		$this->assertSame( 0, $catalog->find( $this->stored, 'old-eric' ) );
		$this->assertSame( 0, $catalog->find( $this->stored, 'photos/eric' ) );
		$this->assertSame( 1, $catalog->find( $this->stored, 'uploads' ) );
		$this->assertSame( 0, $catalog->find( $this->stored, 'eric 2024' ) );

		$refused = false;
		try {
			$catalog->find( $this->stored, 'nothing' );
		} catch ( InvalidArgumentException $exception ) {
			$refused = true;
		}
		$this->assertTrue( $refused );
	}

	public function test_a_source_is_excluded_and_included_and_the_change_is_reported(): void {
		$catalog = $this->catalog();

		$excluded = $catalog->withState( 'old-eric', 'excluded' );
		$this->assertTrue( $excluded['changed'] );
		$this->assertSame( 'excluded', $excluded['sources'][0]['state'] );

		$again = $catalog->withState( 'old-eric', 'active' );
		$this->assertFalse( $again['changed'] );
	}

	public function test_a_source_with_attachments_is_not_removed(): void {
		$catalog = $this->catalog();

		$refused = false;
		try {
			$catalog->without( 'old-eric', [ 'old-eric' => 3 ] );
		} catch ( InvalidArgumentException $exception ) {
			$refused = true;
		}
		$this->assertTrue( $refused );

		$result = $catalog->without( 'old-eric', [] );
		$this->assertSame( [ 'uploads' ], array_column( $result['sources'], 'id' ) );
	}
}
