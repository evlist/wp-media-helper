<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Geo\FileGeo;
use WP_Media_Helper\Index\WpdbIndexStore;

class FileGeoTest extends TestCase {

	private array $roots = [ 'basedir' => '/var/www/wp-content/uploads', 'baseurl' => 'https://e.test/wp-content/uploads', 'contentdir' => '/var/www/wp-content', 'contenturl' => 'https://e.test/wp-content', 'hosts' => [ 'https://www.e.test', 'https://cdn.e.test/x' ] ];

	public function test_an_address_stands_for_a_key_of_the_index_whatever_its_form(): void {
		$this->assertSame( [ 'cumulus/eric/a.jpg' ], FileGeo::candidateKeys( 'cumulus/eric/a.jpg', $this->roots ) );
		$this->assertSame( [ 'cumulus/eric/a.jpg' ], FileGeo::candidateKeys( '/var/www/wp-content/uploads/cumulus/eric/a.jpg', $this->roots ) );
		$this->assertSame( [ 'cumulus/eric/a.jpg' ], FileGeo::candidateKeys( 'https://e.test/wp-content/uploads/cumulus/eric/a.jpg?ver=2#x', $this->roots ) );
		$this->assertSame( [ 'cumulus/eric/a b.jpg' ], FileGeo::candidateKeys( 'https://e.test/wp-content/uploads/cumulus/eric/a%20b.jpg', $this->roots ) );
	}

	public function test_a_thumbnail_of_the_cache_is_mapped_back_to_its_original_after_itself(): void {
		$this->assertSame(
			[ 'thumbnails/cumulus/eric/20261016_161623-1024x577.jpg', 'cumulus/eric/20261016_161623.jpg' ],
			FileGeo::candidateKeys( 'https://e.test/wp-content/uploads/thumbnails/cumulus/eric/20261016_161623-1024x577.jpg', $this->roots )
		);
	}

	public function test_a_file_outside_the_uploads_is_kept_by_its_absolute_path(): void {
		$this->assertSame( [ '/var/www/wp-content/gallery/trip/a.jpg' ], FileGeo::candidateKeys( 'https://e.test/wp-content/gallery/trip/a.jpg', $this->roots ) );
		$this->assertSame( [ '/var/www/wp-content/gallery/trip/a.jpg' ], FileGeo::candidateKeys( '/var/www/wp-content/gallery/trip/a.jpg', $this->roots ) );
	}

	public function test_what_cannot_be_a_key_gives_none(): void {
		$this->assertSame( [], FileGeo::candidateKeys( '', $this->roots ) );
		$this->assertSame( [], FileGeo::candidateKeys( 'https://other.test/a.jpg', $this->roots ) );
		$this->assertSame( [], FileGeo::candidateKeys( 'cumulus/../../etc/passwd', $this->roots ) );
	}

	public function test_a_photo_is_described_with_its_position_its_date_and_where_the_date_comes_from(): void {
		$described = FileGeo::describe( [ 'kind' => 'image', 'geo_state' => 1, 'gps_lat' => 43.5, 'gps_lon' => 3.1, 'effective_date' => '2026-10-08 10:05:12', 'date_source' => 'name', 'name_date_precision' => 'second' ] );

		$this->assertSame( [ 'type' => 'file', 'state' => 'positioned', 'lat' => 43.5, 'lon' => 3.1, 'taken_at' => '2026-10-08 10:05:12', 'taken_source' => 'name', 'taken_precision' => 'second', 'place' => null ], $described );
	}

	public function test_a_photo_not_read_or_without_a_position_has_none_and_says_which(): void {
		$unread = FileGeo::describe( [ 'kind' => 'image', 'geo_state' => 0, 'effective_date' => '2026-10-08 10:05:12' ] );
		$none   = FileGeo::describe( [ 'kind' => 'image', 'geo_state' => 2, 'gps_lat' => 1.0, 'gps_lon' => 2.0, 'effective_date' => '2026-10-08 10:05:12' ] );

		$this->assertSame( 'unread', $unread['state'] );
		$this->assertSame( 'none', $none['state'] );
		$this->assertNull( $none['lat'], 'No position is given for a file without one, even if a stale value was left.' );
		$this->assertSame( '2026-10-08 10:05:12', $none['taken_at'], 'The time is still given.' );
	}

	public function test_a_track_is_described_by_its_two_ends_and_the_place_resolver_is_asked_for_each(): void {
		$asked = [];
		$described = FileGeo::describe(
			[ 'kind' => 'gps', 'geo_state' => 1, 'gps_lat' => 43.4, 'gps_lon' => 3.0, 'gps_end_lat' => 43.6, 'gps_end_lon' => 3.2, 'track_start_at' => '2026-10-08 09:00:00', 'track_end_at' => '2026-10-08 12:30:00' ],
			static function ( float $lat, float $lon ) use ( &$asked ): array {
				$asked[] = [ $lat, $lon ];

				return [ 'town' => 'T' . $lat ];
			}
		);

		$this->assertSame( 'track', $described['type'] );
		$this->assertSame( [ 'lat' => 43.4, 'lon' => 3.0, 'at' => '2026-10-08 09:00:00', 'place' => [ 'town' => 'T43.4' ] ], $described['start'] );
		$this->assertSame( [ 'lat' => 43.6, 'lon' => 3.2, 'at' => '2026-10-08 12:30:00', 'place' => [ 'town' => 'T43.6' ] ], $described['end'] );
		$this->assertSame( [ [ 43.4, 3.0 ], [ 43.6, 3.2 ] ], $asked );
	}

	public function test_the_scheme_www_and_the_other_hosts_of_the_site_do_not_matter_but_another_site_is_unknown(): void {
		$key = [ 'cumulus/a.jpg' ];
		foreach ( [ 'http://e.test/wp-content/uploads/cumulus/a.jpg', 'https://www.e.test/wp-content/uploads/cumulus/a.jpg', '//e.test/wp-content/uploads/cumulus/a.jpg', 'https://cdn.e.test/wp-content/uploads/cumulus/a.jpg', 'HTTPS://E.TEST/wp-content/uploads/cumulus/a.jpg' ] as $address ) {
			$this->assertSame( $key, FileGeo::candidateKeys( $address, $this->roots ), $address );
		}
		foreach ( [ 'https://other.test/wp-content/uploads/cumulus/a.jpg', 'https://e.test.evil.test/wp-content/uploads/cumulus/a.jpg', 'https://e.test/elsewhere/cumulus/a.jpg' ] as $address ) {
			$this->assertSame( [], FileGeo::candidateKeys( $address, $this->roots ), $address );
		}
	}

	public function test_nextgen_addresses_with_a_query_and_names_with_a_percent_are_mapped(): void {
		$this->assertSame( [ '/var/www/wp-content/gallery/trip/thumbs/thumbs_a.jpg' ], FileGeo::candidateKeys( 'https://e.test/wp-content/gallery/trip/thumbs/thumbs_a.jpg?t=1699999999', $this->roots ) );
		$this->assertSame( [ 'cumulus/100% fun.jpg' ], FileGeo::candidateKeys( 'https://e.test/wp-content/uploads/cumulus/100%25%20fun.jpg', $this->roots ) );
	}

	public function test_a_size_made_by_wordpress_stands_for_the_original_after_the_file_itself(): void {
		$this->assertSame( [ 'cumulus/a-300x200.jpg', 'cumulus/a.jpg' ], FileGeo::candidateKeys( 'https://e.test/wp-content/uploads/cumulus/a-300x200.jpg', $this->roots ) );
		$this->assertSame( [ 'cumulus/a-scaled.jpg', 'cumulus/a.jpg' ], FileGeo::candidateKeys( 'cumulus/a-scaled.jpg', $this->roots ) );
	}

	public function test_several_addresses_are_answered_with_one_query_each_keeping_its_own_answer(): void {
		$GLOBALS['wpdb'] = new FakeWpdb();
		$store           = new WpdbIndexStore();
		$dir             = $store->saveDirectory( 's', 'cumulus', 0, 1 );
		$store->insertFiles( 's', $dir, [ [ 'key' => 'cumulus/a.jpg', 'name' => 'a.jpg', 'ext' => 'jpg', 'kind' => 'image', 'size' => 1, 'mtime' => 1, 'effective_date' => '2026-10-08 10:00:00', 'effective_day' => '2026-10-08', 'date_source' => 'name' ] ], 1 );
		$store->saveGeo( (int) $store->filesForDay( [ 's' ], '2026-10-08' )[0]['id'], [ 'geo_state' => 1, 'gps_lat' => 43.5, 'gps_lon' => 3.1 ] );

		$a = 'https://e.test/wp-content/uploads/thumbnails/cumulus/a-1024x577.jpg';
		$b = 'https://e.test/wp-content/uploads/cumulus/a.jpg?ver=1';
		$c = 'https://other.test/a.jpg';
		$answers = FileGeo::lookupMany( $store, [ $a, $b, $c ], $this->roots );

		$this->assertSame( [ $a, $b, $c ], array_keys( $answers ) );
		$this->assertSame( 43.5, $answers[ $a ]['lat'] );
		$this->assertSame( 43.5, $answers[ $b ]['lat'] );
		$this->assertNull( $answers[ $c ] );
		$this->assertSame( [], FileGeo::lookupMany( $store, [], $this->roots ) );
	}

	public function test_a_lookup_finds_the_original_from_the_address_of_its_thumbnail(): void {
		$GLOBALS['wpdb'] = $db = new FakeWpdb();
		$store           = new WpdbIndexStore();
		$dir             = $store->saveDirectory( 's', 'cumulus/eric', 0, 1 );
		$store->insertFiles( 's', $dir, [ [ 'key' => 'cumulus/eric/a.jpg', 'name' => 'a.jpg', 'ext' => 'jpg', 'kind' => 'image', 'size' => 1, 'mtime' => 1, 'effective_date' => '2026-10-08 10:00:00', 'effective_day' => '2026-10-08', 'date_source' => 'name' ] ], 1 );
		$row = $store->filesForDay( [ 's' ], '2026-10-08' )[0];
		$store->saveGeo( (int) $row['id'], [ 'geo_state' => 1, 'gps_lat' => 43.5, 'gps_lon' => 3.1 ] );

		$found = FileGeo::lookup( $store, 'https://e.test/wp-content/uploads/thumbnails/cumulus/eric/a-1024x577.jpg', $this->roots );

		$this->assertSame( [ 43.5, 3.1, 'positioned' ], [ $found['lat'], $found['lon'], $found['state'] ] );
		$this->assertNull( FileGeo::lookup( $store, 'cumulus/eric/unknown.jpg', $this->roots ) );
	}
}
