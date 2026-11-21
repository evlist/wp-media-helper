<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Geo\Places;

use WP_Media_Helper\Index\IndexStore;

/**
 * Looks for the place of the files that have a position (slice 052, phase B), newest first, within the usage policy of the service: the cache
 * first (a cell is asked for once, whatever the number of files in it), one request at a time, the pace and the back-off of RateLimiter, no
 * request in a loop on the same cell.
 *
 * `place_state` of a file: 0 to do, 1 done, 2 given up, 3 waiting for a new try. A track has two positions (its start and its end): both cells
 * are needed.
 */
final class PlacesJob {

	private IndexStore $files;
	private PlaceStore $places;
	private NominatimClient $client;
	private RateLimiter $limiter;
	private PlacesSettings $settings;
	/** @var callable(): float */
	private $clock;
	/** @var callable(float): void */
	private $sleeper;

	/**
	 * @param callable(): float|null     $clock   Seconds, with fractions.
	 * @param callable(float): void|null $sleeper Waits for that many seconds.
	 */
	public function __construct( IndexStore $files, PlaceStore $places, NominatimClient $client, RateLimiter $limiter, PlacesSettings $settings, ?callable $clock = null, ?callable $sleeper = null ) {
		$this->files    = $files;
		$this->places   = $places;
		$this->client   = $client;
		$this->limiter  = $limiter;
		$this->settings = $settings;
		$this->clock    = $clock ?? static fn (): float => microtime( true );
		$this->sleeper  = $sleeper ?? static function ( float $seconds ): void {
			usleep( (int) round( $seconds * 1000000 ) );
		};
	}

	/**
	 * @param float $seconds The time this run may take, waiting between two requests included.
	 * @param int   $limit   At most this many requests (0: no limit).
	 * @return array{requests:int, found:int, nothing:int, failed:int, files_done:int, left:bool, blocked_for:int, next_in:float}
	 *         `left`: files remain; `blocked_for`: seconds of a back-off; `next_in`: seconds before the next request may be sent.
	 */
	public function run( float $seconds, int $limit = 0 ): array {
		$report   = [ 'requests' => 0, 'found' => 0, 'nothing' => 0, 'failed' => 0, 'files_done' => 0, 'left' => false, 'blocked_for' => 0, 'next_in' => 0.0 ];
		$deadline = ( $this->clock )() + max( 0.0, $seconds );

		foreach ( [ false, true ] as $retry ) {
			do {
				$rows     = $this->files->filesNeedingPlace( 20, $retry );
				$progress = false;
				foreach ( $rows as $row ) {
					$outcome = $this->file( $row, $deadline, $limit, $report );
					if ( 'stop' === $outcome ) {
						$report['left']        = true;
						$report['blocked_for'] = $this->limiter->blockedFor();
						$report['next_in']     = $this->limiter->waitSeconds();

						return $report;
					}
					$progress = $progress || 'later' !== $outcome;
				}
			} while ( $progress && [] !== $rows );
		}

		return $report;
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, mixed> $report
	 * @return string `done`, `later` (a cell waits for a new try) or `stop` (the budget, the limit or a back-off ends the run).
	 */
	private function file( array $row, float $deadline, int $limit, array &$report ): string {
		$cells = [ [ (float) $row['gps_lat'], (float) $row['gps_lon'] ] ];
		if ( 'gps' === ( $row['kind'] ?? '' ) && isset( $row['gps_end_lat'], $row['gps_end_lon'] ) ) {
			$cells[] = [ (float) $row['gps_end_lat'], (float) $row['gps_end_lon'] ];
		}

		$later   = false;
		$gaveUp  = false;
		foreach ( $cells as [ $lat, $lon ] ) {
			$status = $this->cell( $lat, $lon, $deadline, $limit, $report );
			if ( 'stop' === $status ) {
				return 'stop';
			}
			$later  = $later || 'wait' === $status;
			$gaveUp = $gaveUp || 'gave_up' === $status;
		}

		if ( $later ) {
			$this->files->setPlaceState( (int) $row['id'], 3 );

			return 'later';
		}
		$this->files->setPlaceState( (int) $row['id'], $gaveUp ? 2 : 1 );
		++$report['files_done'];

		return 'done';
	}

	/**
	 * @param array<string, mixed> $report
	 * @return string `ok` (the cell has an answer, even "nothing"), `gave_up`, `wait` (failed, a new try later) or `stop`.
	 */
	private function cell( float $lat, float $lon, float $deadline, int $limit, array &$report ): string {
		$language = $this->settings->language;
		$known    = $this->places->find( PlaceStore::key( $lat, $lon, $language ) );
		$now      = (int) floor( ( $this->clock )() );

		if ( null !== $known ) {
			$state = (int) $known['state'];
			if ( in_array( $state, [ PlaceStore::STATE_FOUND, PlaceStore::STATE_NOTHING ], true ) ) {
				return 'ok';
			}
			if ( PlaceStore::STATE_GAVE_UP === $state ) {
				return 'gave_up';
			}
			if ( $known['next_try'] > $now ) {
				return 'wait';
			}
		}

		// A request is needed: within the budget, the limit, the back-off and the pace.
		if ( $this->limiter->blockedFor() > 0 || ( 0 !== $limit && $report['requests'] >= $limit ) ) {
			return 'stop';
		}
		$wait = $this->limiter->waitSeconds();
		if ( ( $this->clock )() + $wait >= $deadline ) {
			return 'stop';
		}
		if ( $wait > 0.0 ) {
			( $this->sleeper )( $wait );
		}

		$cell   = PlaceStore::cell( $lat, $lon );
		$answer = $this->client->reverse( $cell['lat'], $cell['lon'], (int) floor( ( $this->clock )() ) );
		$this->limiter->recordRequest( $this->settings->interval() );
		++$report['requests'];
		$fetchedAt = (int) floor( ( $this->clock )() );

		switch ( $answer['result'] ) {
			case 'ok':
				$body = (array) $answer['body'];
				$this->places->save( $lat, $lon, $language, [
					'state'        => PlaceStore::STATE_FOUND,
					'attempts'     => 0,
					'next_try'     => 0,
					'osm_type'     => isset( $body['osm_type'] ) ? substr( (string) $body['osm_type'], 0, 10 ) : null,
					'osm_id'       => isset( $body['osm_id'] ) ? (int) $body['osm_id'] : null,
					'place_id'     => isset( $body['place_id'] ) ? (int) $body['place_id'] : null,
					'display_name' => isset( $body['display_name'] ) ? (string) $body['display_name'] : null,
					'response'     => (string) wp_json_encode_compat( $body ),
					'service'      => $this->settings->host(),
					'fetched_at'   => $fetchedAt,
				] );
				$this->limiter->success();
				++$report['found'];

				return 'ok';

			case 'nothing':
				$this->places->save( $lat, $lon, $language, [ 'state' => PlaceStore::STATE_NOTHING, 'attempts' => 0, 'next_try' => 0, 'response' => (string) wp_json_encode_compat( $answer['body'] ), 'service' => $this->settings->host(), 'fetched_at' => $fetchedAt ] );
				$this->limiter->success();
				++$report['nothing'];

				return 'ok';

			case 'retry':
				// The service as a whole is refused or down: nothing more is sent for a while, and this cell is not counted as failed.
				$this->limiter->backoff( $answer['retry_after'] );

				return 'stop';

			default:
				$attempts = (int) ( $known['attempts'] ?? 0 ) + 1;
				++$report['failed'];
				if ( $attempts >= PlacesSettings::MAX_ATTEMPTS ) {
					$this->places->save( $lat, $lon, $language, [ 'state' => PlaceStore::STATE_GAVE_UP, 'attempts' => $attempts, 'next_try' => 0, 'service' => $this->settings->host(), 'fetched_at' => $fetchedAt ] );

					return 'gave_up';
				}
				// One hour, six, a day, three days: not again at the next run.
				$delays = [ 3600, 21600, 86400, 259200 ];
				$this->places->save( $lat, $lon, $language, [ 'state' => PlaceStore::STATE_FAILED, 'attempts' => $attempts, 'next_try' => $fetchedAt + $delays[ min( $attempts - 1, 3 ) ], 'service' => $this->settings->host(), 'fetched_at' => $fetchedAt ] );

				return 'wait';
		}
	}
}

/**
 * `wp_json_encode()` when WordPress is there, else `json_encode()` (the tests).
 *
 * @param mixed $value
 * @return string|false
 */
function wp_json_encode_compat( $value ) {
	return function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) : json_encode( $value, JSON_UNESCAPED_UNICODE );
}
