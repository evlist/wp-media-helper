<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Geo\Places\NominatimClient;
use WP_Media_Helper\Geo\Places\PlacesJob;
use WP_Media_Helper\Geo\Places\PlacesSettings;
use WP_Media_Helper\Geo\Places\PlaceStore;
use WP_Media_Helper\Geo\Places\RateLimiter;
use WP_Media_Helper\Index\WpdbIndexStore;

class PlacesTest extends TestCase {

	private float $now = 1000000.0;
	private array $state = [];
	/** @var array<int, array{url:string, headers:array<string, string>}> */
	private array $requests = [];
	/** @var array<int, array<string, mixed>> The answers the fake service gives, in order (the last one repeats). */
	private array $answers = [];
	private FakeWpdb $db;
	private WpdbIndexStore $files;
	private PlaceStore $places;

	protected function setUp(): void {
		$this->db        = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->db;
		$this->files     = new WpdbIndexStore();
		$this->places    = new PlaceStore();
		$this->requests  = [];
		$this->state     = [];
		$this->answers   = [ $this->found( 1 ) ];
	}

	private function found( int $id ): array {
		return [ 'status' => 200, 'body' => json_encode( [ 'place_id' => $id, 'osm_type' => 'node', 'osm_id' => 900 + $id, 'display_name' => "Place $id", 'address' => [ 'village' => "V$id", 'country' => 'France' ], 'extratags' => [ 'wikidata' => "Q$id" ] ] ), 'headers' => [], 'error' => null ];
	}

	private function settings( string $url = 'https://nominatim.openstreetmap.org/reverse', array $more = [] ): PlacesSettings {
		return new PlacesSettings( array_merge( [ 'places_url' => $url, 'places_language' => 'fr_FR' ], $more ), 'en' );
	}

	private function job( PlacesSettings $settings ): PlacesJob {
		$transport = function ( string $url, array $headers ): array {
			$this->requests[] = [ 'url' => $url, 'headers' => $headers ];
			$answer           = count( $this->answers ) > 1 ? array_shift( $this->answers ) : $this->answers[0];

			return $answer;
		};
		$limiter = new RateLimiter( fn (): array => $this->state, function ( array $state ): void {
			$this->state = $state;
		}, fn (): float => $this->now );

		return new PlacesJob(
			$this->files,
			$this->places,
			new NominatimClient( $settings->url, $settings->language, 'WP-Media-Helper/test (https://e.test)', 'https://e.test/', $transport ),
			$limiter,
			$settings,
			fn (): float => $this->now,
			function ( float $seconds ): void {
				$this->now += $seconds;
			}
		);
	}

	/** Files with a position, newest first by date. */
	private function addPhotos( array $positions ): void {
		$dir   = $this->files->saveDirectory( 's', 'p', 0, 1 );
		$files = [];
		foreach ( $positions as $index => [ $lat, $lon ] ) {
			$files[] = [ 'key' => "p/$index.jpg", 'name' => "$index.jpg", 'ext' => 'jpg', 'kind' => 'image', 'size' => 1, 'mtime' => 1, 'effective_date' => sprintf( '2026-10-08 10:%02d:00', $index ), 'effective_day' => '2026-10-08', 'date_source' => 'name' ];
		}
		$this->files->insertFiles( 's', $dir, $files, 1 );
		foreach ( $this->files->filesForDay( [ 's' ], '2026-10-08' ) as $row ) {
			$index = (int) $row['name'];
			$this->files->saveGeo( (int) $row['id'], [ 'geo_state' => 1, 'gps_lat' => $positions[ $index ][0], 'gps_lon' => $positions[ $index ][1] ] );
		}
	}

	public function test_nothing_is_asked_without_a_service_and_the_public_one_is_held_to_four_a_minute(): void {
		$this->assertFalse( ( new PlacesSettings( [] ) )->enabled() );
		$this->assertFalse( ( new PlacesSettings( [ 'places_url' => 'ftp://x/' ] ) )->enabled() );
		$this->assertFalse( ( new PlacesSettings( [ 'places_url' => 'http://nominatim.openstreetmap.org/reverse' ] ) )->enabled(), 'The public service only over https.' );

		$public = $this->settings( 'https://nominatim.openstreetmap.org/reverse', [ 'places_rate' => 600 ] );
		$this->assertTrue( $public->isPublicService() );
		$this->assertSame( 4, $public->requestsPerMinute(), 'Whatever the setting says.' );
		$this->assertSame( 15.0, $public->interval() );

		$own = $this->settings( 'http://nominatim.lan/reverse', [ 'places_rate' => 120 ] );
		$this->assertFalse( $own->isPublicService() );
		$this->assertSame( 0.5, $own->interval() );
		$this->assertSame( 1.0, $this->settings( 'http://nominatim.lan/reverse' )->interval(), 'One a second by default.' );
		$this->assertSame( 'fr-FR', $public->language );
		$this->assertSame( 'en', ( new PlacesSettings( [ 'places_language' => 'x' ], 'en' ) )->language );
	}

	public function test_the_request_carries_the_rounded_coordinates_the_language_and_an_identifying_user_agent(): void {
		$this->addPhotos( [ [ 43.512345, 3.123456 ] ] );

		$this->job( $this->settings() )->run( 60.0 );

		$this->assertCount( 1, $this->requests );
		$this->assertStringContainsString( 'lat=43.512&lon=3.123', $this->requests[0]['url'] );
		$this->assertStringNotContainsString( '43.5123', $this->requests[0]['url'] );
		foreach ( [ 'format=jsonv2', 'zoom=18', 'addressdetails=1', 'extratags=1', 'accept-language=fr-FR' ] as $part ) {
			$this->assertStringContainsString( $part, $this->requests[0]['url'] );
		}
		$this->assertSame( 'WP-Media-Helper/test (https://e.test)', $this->requests[0]['headers']['User-Agent'] );
	}

	public function test_many_files_in_one_cell_cost_one_request_and_the_whole_answer_is_kept(): void {
		$this->addPhotos( [ [ 43.5121, 3.1231 ], [ 43.5119, 3.1229 ], [ 43.5124, 3.1234 ] ] );

		$report = $this->job( $this->settings() )->run( 60.0 );

		$this->assertSame( 1, $report['requests'] );
		$this->assertSame( 3, $report['files_done'] );
		$this->assertSame( [ 0 => 0, 1 => 3, 2 => 0, 3 => 0 ], $this->files->placeCounts() );

		$cell = $this->places->find( PlaceStore::key( 43.512, 3.123, 'fr-FR' ) );
		$this->assertSame( [ 1, 'node', 901, 'Place 1', 'nominatim.openstreetmap.org' ], [ $cell['state'], $cell['osm_type'], $cell['osm_id'], $cell['display_name'], $cell['service'] ] );
		$whole = json_decode( $cell['response'], true );
		$this->assertSame( 'Q1', $whole['extratags']['wikidata'] );
		$this->assertSame( 'V1', $whole['address']['village'] );

		// Run again: nothing to do, nothing asked.
		$this->requests = [];
		$this->assertSame( 0, $this->job( $this->settings() )->run( 60.0 )['requests'] );
		$this->assertSame( [], $this->requests );
	}

	public function test_requests_are_spaced_by_the_pace_of_the_service_and_the_run_stops_when_the_time_is_up(): void {
		$this->addPhotos( [ [ 43.1, 3.1 ], [ 43.2, 3.2 ], [ 43.3, 3.3 ], [ 43.4, 3.4 ] ] );
		$start = $this->now;

		// 40 seconds: the first at once, the second after 15 s, the third after 30 s, a fourth would be at 45 s.
		$report = $this->job( $this->settings() )->run( 40.0 );

		$this->assertSame( 3, $report['requests'] );
		$this->assertTrue( $report['left'] );
		$this->assertSame( 30.0, $this->now - $start, 'It waited between the requests, and not for the one that does not fit.' );
		$this->assertSame( 1, $this->files->placeCounts()[0], 'One file is left for the next run.' );
	}

	public function test_a_refusal_stops_everything_with_a_growing_backoff_that_honours_retry_after(): void {
		$this->addPhotos( [ [ 43.1, 3.1 ], [ 43.2, 3.2 ] ] );
		$this->answers = [ [ 'status' => 429, 'body' => '', 'headers' => [ 'Retry-After' => '600' ], 'error' => null ] ];
		$job = $this->job( $this->settings() );

		$report = $job->run( 60.0 );

		$this->assertSame( 1, $report['requests'], 'No other request after a 429.' );
		$this->assertTrue( $report['left'] );
		$this->assertSame( 600, $report['blocked_for'] );
		$this->assertSame( 0, $this->places->counts()[3], 'A refusal of the service is not a failure of the cell.' );

		// While it lasts, a new run sends nothing.
		$this->requests = [];
		$this->assertSame( 0, $job->run( 60.0 )['requests'] );
		$this->assertSame( [], $this->requests );

		// After it, one more failure doubles the delay (120 s, then 240 s), and a long Retry-After wins.
		$this->now += 601;
		$this->answers = [ [ 'status' => 503, 'body' => '', 'headers' => [], 'error' => null ] ];
		$this->assertSame( 240, $job->run( 60.0 )['blocked_for'] );
	}

	public function test_a_service_that_does_not_answer_is_a_backoff_too(): void {
		$this->addPhotos( [ [ 43.1, 3.1 ] ] );
		$this->answers = [ [ 'status' => 0, 'body' => '', 'headers' => [], 'error' => 'timeout' ] ];

		$report = $this->job( $this->settings() )->run( 60.0 );

		$this->assertSame( 120, $report['blocked_for'] );
		$this->assertSame( 1, $this->files->placeCounts()[0] );
	}

	public function test_a_cell_that_fails_is_tried_again_later_not_at_the_next_run_and_is_given_up_after_five_tries(): void {
		$this->addPhotos( [ [ 43.1, 3.1 ], [ 43.2, 3.2 ] ] );
		$this->answers = [ [ 'status' => 400, 'body' => '', 'headers' => [], 'error' => null ], $this->found( 2 ) ];
		$job = $this->job( $this->settings() );

		$first = $job->run( 120.0 );

		$this->assertSame( 1, $first['failed'] );
		$this->assertSame( 1, $first['found'], 'The other file went on.' );
		$this->assertSame( [ 0 => 0, 1 => 1, 2 => 0, 3 => 1 ], $this->files->placeCounts(), 'The file of the failed cell waits.' );

		// The next run, soon after: the cell is not asked for again.
		$this->requests = [];
		$job->run( 120.0 );
		$this->assertSame( [], $this->requests );

		// An hour later it is tried again, and again and again, until it is given up.
		$this->answers = [ [ 'status' => 400, 'body' => '', 'headers' => [], 'error' => null ] ];
		foreach ( [ 3601, 21601, 86401, 259201 ] as $skip ) {
			$this->now += $skip;
			$job->run( 120.0 );
		}
		// The newest file (43.2, 3.2) was asked for first and failed; the other one found its place.
		$cell = $this->places->find( PlaceStore::key( 43.2, 3.2, 'fr-FR' ) );
		$this->assertSame( [ PlaceStore::STATE_GAVE_UP, 5 ], [ $cell['state'], $cell['attempts'] ] );
		$this->assertSame( 1, $this->files->placeCounts()[2], 'The file is given up.' );
	}

	public function test_a_place_that_is_nowhere_is_cached_as_such_and_not_asked_again(): void {
		$this->addPhotos( [ [ 40.0, -30.0 ] ] );
		$this->answers = [ [ 'status' => 200, 'body' => '{"error":"Unable to geocode"}', 'headers' => [], 'error' => null ] ];

		$report = $this->job( $this->settings() )->run( 60.0 );

		$this->assertSame( [ 1, 1 ], [ $report['nothing'], $report['files_done'] ] );
		$this->assertSame( PlaceStore::STATE_NOTHING, $this->places->find( PlaceStore::key( 40.0, -30.0, 'fr-FR' ) )['state'] );
	}

	public function test_a_track_needs_the_places_of_its_start_and_of_its_end(): void {
		$dir = $this->files->saveDirectory( 's', 'g', 0, 1 );
		$this->files->insertFiles( 's', $dir, [ [ 'key' => 'g/t.gpx', 'name' => 't.gpx', 'ext' => 'gpx', 'kind' => 'gps', 'size' => 1, 'mtime' => 1, 'effective_date' => '2026-10-08 09:00:00', 'effective_day' => '2026-10-08', 'date_source' => 'name' ] ], 1 );
		$id = (int) $this->files->filesForDay( [ 's' ], '2026-10-08' )[0]['id'];
		$this->files->saveGeo( $id, [ 'geo_state' => 1, 'gps_lat' => 43.4, 'gps_lon' => 3.0, 'gps_end_lat' => 43.6, 'gps_end_lon' => 3.2 ] );
		$this->answers = [ $this->found( 1 ), $this->found( 2 ) ];

		$report = $this->job( $this->settings() )->run( 60.0 );

		$this->assertSame( [ 2, 1 ], [ $report['requests'], $report['files_done'] ] );
		$this->assertSame( 'V1', json_decode( $this->places->find( PlaceStore::key( 43.4, 3.0, 'fr-FR' ) )['response'], true )['address']['village'] );
		$this->assertSame( 'V2', json_decode( $this->places->find( PlaceStore::key( 43.6, 3.2, 'fr-FR' ) )['response'], true )['address']['village'] );
	}

	public function test_a_position_read_again_gets_its_place_looked_for_again(): void {
		$this->addPhotos( [ [ 43.1, 3.1 ] ] );
		$this->job( $this->settings() )->run( 60.0 );
		$this->assertSame( 1, $this->files->placeCounts()[1] );

		$id = (int) $this->files->filesForDay( [ 's' ], '2026-10-08' )[0]['id'];
		$this->files->saveGeo( $id, [ 'geo_state' => 1, 'gps_lat' => 43.2, 'gps_lon' => 3.2 ] );

		$this->assertSame( [ 0 => 1, 1 => 0, 2 => 0, 3 => 0 ], $this->files->placeCounts() );
	}

	public function test_the_cache_keys_are_the_rounded_cell_and_the_language(): void {
		$this->assertSame( PlaceStore::key( 43.5121, 3.1229, 'fr' ), PlaceStore::key( 43.5124, 3.1231, 'FR' ) );
		$this->assertNotSame( PlaceStore::key( 43.512, 3.123, 'fr' ), PlaceStore::key( 43.512, 3.123, 'en' ) );
		$this->assertNotSame( PlaceStore::key( 43.512, 3.123, 'fr' ), PlaceStore::key( 43.513, 3.123, 'fr' ) );
		$this->assertSame( [ 'lat' => 0.0, 'lon' => 0.0 ], PlaceStore::cell( -0.0004, 0.0003 ) );
	}

	public function test_retry_after_is_read_as_seconds_or_as_a_date(): void {
		$this->assertSame( 120, RateLimiter::retryAfter( '120', 0 ) );
		$this->assertSame( 60, RateLimiter::retryAfter( gmdate( 'D, d M Y H:i:s', 1000060 ) . ' GMT', 1000000 ) );
		$this->assertNull( RateLimiter::retryAfter( '', 0 ) );
		$this->assertNull( RateLimiter::retryAfter( 'soon', 0 ) );
	}
}
