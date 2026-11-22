<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\TestCase;
use WP_Media_Helper\Geo\Places\NominatimClient;
use WP_Media_Helper\Geo\Places\PlaceAnswer;
use WP_Media_Helper\Geo\Places\PlaceQuality;
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

	/** An answer in the `geocodejson` format, as the service gives it. */
	private function found( int $id ): array {
		$body = [
			'type'      => 'FeatureCollection',
			'geocoding' => [ 'version' => '0.1.0', 'licence' => 'ODbL' ],
			'features'  => [ [
				'type'       => 'Feature',
				'properties' => [ 'geocoding' => [
					'place_id' => $id, 'osm_type' => 'node', 'osm_id' => 900 + $id, 'osm_key' => 'place', 'osm_value' => 'hamlet', 'label' => "Place $id",
					'name' => "H$id", 'locality' => "H$id", 'city' => "V$id", 'county' => "C$id", 'state' => 'S', 'country' => 'France',
					'admin' => [ 'level2' => 'France', 'level4' => 'S', 'level6' => "C$id", 'level7' => "A$id", 'level8' => "V$id" ],
					'extra' => [ 'wikidata' => "Q$id" ],
				] ],
				'geometry'   => [ 'type' => 'Point', 'coordinates' => [ 3.1, 43.5 ] ],
			] ],
		];

		return [ 'status' => 200, 'body' => json_encode( $body ), 'headers' => [], 'error' => null ];
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
		foreach ( [ 'format=geocodejson', 'zoom=18', 'addressdetails=1', 'extratags=1', 'accept-language=fr-FR' ] as $part ) {
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
		$this->assertSame( 'Q1', $whole['features'][0]['properties']['geocoding']['extra']['wikidata'] );
		$this->assertSame( 'V1', $whole['features'][0]['properties']['geocoding']['admin']['level8'] );

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
		$this->assertSame( 'V1', PlaceAnswer::levels( json_decode( $this->places->find( PlaceStore::key( 43.4, 3.0, 'fr-FR' ) )['response'], true ) )['settlement'] );
		$this->assertSame( 'V2', PlaceAnswer::levels( json_decode( $this->places->find( PlaceStore::key( 43.6, 3.2, 'fr-FR' ) )['response'], true ) )['settlement'] );
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

	public function test_a_finer_precision_gives_finer_cells_with_keys_of_their_own_and_the_public_service_stays_at_100_m(): void {
		$own = $this->settings( 'http://nominatim.lan/reverse', [ 'places_precision' => '4' ] );
		$this->assertSame( 4, $own->precision );
		$this->assertSame( 3, $this->settings( 'https://nominatim.openstreetmap.org/reverse', [ 'places_precision' => '5' ] )->precision, 'Never finer than 100 m for the public service.' );
		$this->assertSame( 5, $this->settings( 'http://nominatim.lan/reverse', [ 'places_precision' => '9' ] )->precision, 'Out of range is brought back.' );

		$this->assertSame( [ 'lat' => 43.5123, 'lon' => 3.1235 ], PlaceStore::cell( 43.51234, 3.12349, 4 ) );
		$this->assertSame( [ 'lat' => 43.512, 'lon' => 3.123 ], PlaceStore::cell( 43.51234, 3.12349 ) );
		$this->assertNotSame( PlaceStore::key( 43.5123, 3.1235, 'fr', 3 ), PlaceStore::key( 43.5123, 3.1235, 'fr', 4 ) );
		$this->assertSame( PlaceStore::key( 43.5123, 3.1235, 'fr', 4 ), PlaceStore::key( 43.51231, 3.12352, 'fr', 4 ) );

		$this->places = new PlaceStore( 4 );
		$this->addPhotos( [ [ 43.51231, 3.12349 ], [ 43.51233, 3.12351 ], [ 43.51301, 3.12349 ] ] );
		$report = $this->job( $own )->run( 60.0 );

		$this->assertSame( 2, $report['requests'], 'Two cells of 10 m: the first two photos share one.' );
		$this->assertStringContainsString( 'lat=43.5130&lon=3.1235', $this->requests[0]['url'], 'The newest photo is asked for first, with its cell of 4 decimals.' );
	}

	public function test_retry_after_is_read_as_seconds_or_as_a_date(): void {
		$this->assertSame( 120, RateLimiter::retryAfter( '120', 0 ) );
		$this->assertSame( 60, RateLimiter::retryAfter( gmdate( 'D, d M Y H:i:s', 1000060 ) . ' GMT', 1000000 ) );
		$this->assertNull( RateLimiter::retryAfter( '', 0 ) );
		$this->assertNull( RateLimiter::retryAfter( 'soon', 0 ) );
	}

	public function test_the_levels_have_the_same_names_whatever_the_format_and_the_country(): void {
		$france = json_decode( $this->found( 5 )['body'], true );
		$levels = PlaceAnswer::levels( $france );

		$this->assertSame( [ 'H5', 'V5', 'C5', 'S', 'France', 'place hamlet', 'H5' ], [ $levels['name'], $levels['settlement'], $levels['county'], $levels['state'], $levels['country'], $levels['kind'], $levels['site'] ] );
		$this->assertSame( [ 'level2' => 'France', 'level4' => 'S', 'level6' => 'C5', 'level7' => 'A5', 'level8' => 'V5' ], $levels['admin'] );

		// When the flat fields are missing the numbered levels fill in.
		unset( $france['features'][0]['properties']['geocoding']['city'], $france['features'][0]['properties']['geocoding']['county'] );
		$this->assertSame( [ 'V5', 'C5' ], [ PlaceAnswer::levels( $france )['settlement'], PlaceAnswer::levels( $france )['county'] ] );

		// An answer of the older format kept in a cache: the keys of the address, with the village before the town before the city.
		$legacy = PlaceAnswer::levels( [ 'place_id' => 1, 'name' => 'Col', 'category' => 'natural', 'type' => 'saddle', 'address' => [ 'hamlet' => 'Le Tronchet', 'village' => 'Bourg-Saint-Pierre', 'municipality' => 'Arrondissement', 'county' => 'Entremont', 'state' => 'Valais', 'country' => 'Suisse' ] ] );
		$this->assertSame( [ 'Le Tronchet', 'Bourg-Saint-Pierre', 'Entremont', 'Valais', 'Suisse', 'natural saddle' ], [ $legacy['locality'], $legacy['settlement'], $legacy['county'], $legacy['state'], $legacy['country'], $legacy['kind'] ] );
	}

	public function test_the_site_is_the_place_name_below_the_commune(): void {
		$site = static fn ( array $geocoding ): string => PlaceAnswer::levels( [ 'features' => [ [ 'properties' => [ 'geocoding' => $geocoding ] ] ] ] )['site'];

		// A place found: its name (a place-name of the countryside).
		$this->assertSame( 'Jarriges', $site( [ 'osm_key' => 'place', 'osm_value' => 'locality', 'name' => 'Jarriges', 'district' => 'Le Foirail', 'city' => 'Salers' ] ) );
		// A land use or a building is not a place name: the locality, else the district.
		$this->assertSame( "L'Abbaye", $site( [ 'osm_key' => 'landuse', 'osm_value' => 'residential', 'name' => 'Résidence ATC', 'locality' => "L'Abbaye", 'district' => "Quartier d'Orbigny", 'city' => 'Châtelaillon-Plage' ] ) );
		$this->assertSame( 'Les Arberons', $site( [ 'osm_key' => 'landuse', 'osm_value' => 'residential', 'name' => 'Résidence ATC', 'district' => 'Les Arberons', 'city' => 'Chamonix' ] ) );
		// The name of the commune is not a finer level: the next candidate is taken, or none.
		$this->assertSame( 'Masdamont', $site( [ 'osm_key' => 'place', 'osm_value' => 'village', 'name' => 'Saint-Bonnet', 'district' => 'Masdamont', 'city' => 'saint-bonnet' ] ) );
		$this->assertSame( '', $site( [ 'osm_key' => 'boundary', 'osm_value' => 'administrative', 'name' => 'Beaufort', 'district' => 'Beaufort', 'city' => 'Beaufort' ] ) );
		$this->assertSame( '', $site( [ 'osm_key' => 'landuse', 'osm_value' => 'farmyard', 'name' => 'Ferme', 'city' => 'Lattes' ] ), 'A farm name alone is not a place name.' );
		// The older format derives the same.
		$this->assertSame( 'Le Tronchet', PlaceAnswer::levels( [ 'place_id' => 1, 'name' => 'Col', 'category' => 'natural', 'type' => 'saddle', 'address' => [ 'hamlet' => 'Le Tronchet', 'village' => 'Bourg-Saint-Pierre' ] ] )['site'] );
	}

	public function test_a_state_that_is_only_the_level_3_group_is_not_a_state_and_a_place_outside_any_commune_keeps_its_site(): void {
		$answer = [ 'features' => [ [ 'properties' => [ 'geocoding' => [
			'osm_key' => 'place', 'osm_value' => 'locality', 'name' => 'La Jamble', 'locality' => 'Les Boucholeurs', 'state' => 'France métropolitaine', 'country' => 'France',
			'admin' => [ 'level3' => 'France métropolitaine' ],
		] ] ] ] ];

		$levels = PlaceAnswer::levels( $answer );

		$this->assertSame( [ '', '', 'La Jamble', 'France' ], [ $levels['state'], $levels['settlement'], $levels['site'], $levels['country'] ] );

		// With a level 4 the state is the region, as before.
		$answer['features'][0]['properties']['geocoding']['state']          = 'Nouvelle-Aquitaine';
		$answer['features'][0]['properties']['geocoding']['admin']['level4'] = 'Nouvelle-Aquitaine';
		$this->assertSame( 'Nouvelle-Aquitaine', PlaceAnswer::levels( $answer )['state'] );
	}

	public function test_a_geocodejson_answer_without_feature_is_nothing_there_and_anything_else_is_not_a_place(): void {
		$this->assertTrue( PlaceAnswer::hasPlace( json_decode( $this->found( 1 )['body'], true ) ) );
		$this->assertFalse( PlaceAnswer::hasPlace( [ 'error' => 'Unable to geocode' ] ) );
		$this->assertFalse( PlaceAnswer::hasPlace( [ 'type' => 'FeatureCollection', 'features' => [] ] ) );
		$this->addPhotos( [ [ 40.0, -30.0 ] ] );
		$this->answers = [ [ 'status' => 200, 'body' => '{"type":"FeatureCollection","features":[]}', 'headers' => [], 'error' => null ] ];

		$report = $this->job( $this->settings() )->run( 60.0 );

		$this->assertSame( 1, $report['nothing'] );
	}

	public function test_the_quality_counts_the_levels_the_numbered_levels_and_the_places_without_a_name(): void {
		$one = static fn ( array $geocoding ): array => [ 'features' => [ [ 'properties' => [ 'geocoding' => $geocoding ] ] ] ];
		$tally = PlaceQuality::tally( [
			$one( [ 'osm_key' => 'place', 'osm_value' => 'village', 'name' => 'V', 'city' => 'V', 'county' => 'C', 'country' => 'F', 'admin' => [ 'level2' => 'F', 'level6' => 'C', 'level8' => 'V' ] ] ),
			$one( [ 'osm_key' => 'place', 'osm_value' => 'hamlet', 'name' => 'H', 'locality' => 'H', 'city' => 'T', 'county' => 'C', 'country' => 'F', 'admin' => [ 'level2' => 'F', 'level8' => 'T' ] ] ),
			$one( [ 'osm_key' => 'natural', 'osm_value' => 'peak', 'name' => 'P', 'county' => 'C', 'country' => 'F', 'admin' => [ 'level2' => 'F' ] ] ),
		] );

		$this->assertSame( 3, $tally['total'] );
		$this->assertSame( [ 3, 1, 1, 2, 3, 3 ], [ $tally['levels']['name'], $tally['levels']['site'], $tally['levels']['locality'], $tally['levels']['settlement'], $tally['levels']['county'], $tally['levels']['country'] ] );
		$this->assertSame( [ 'level2' => 3, 'level6' => 1, 'level8' => 2 ], $tally['admin'] );
		$this->assertSame( 1, $tally['nowhere_named'], 'Only the peak has neither a settlement nor a locality.' );
		$this->assertSame( [ 'place village' => 1, 'place hamlet' => 1, 'natural peak' => 1 ], $tally['kinds'] );
	}

	public function test_the_cells_found_are_listed_in_chunks(): void {
		$this->addPhotos( [ [ 43.1, 3.1 ], [ 43.2, 3.2 ], [ 43.3, 3.3 ] ] );
		$this->answers = [ $this->found( 1 ), $this->found( 2 ), $this->found( 3 ) ];
		$this->job( $this->settings() )->run( 120.0 );

		$names = array_map( static fn ( array $answer ): string => PlaceAnswer::summary( $answer )['display_name'], iterator_to_array( $this->places->foundAnswers( 2 ), false ) );

		$this->assertCount( 3, $names );
	}
}
