<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Geo;

use WP_Media_Helper\Index\IndexStore;
use WP_Media_Helper\Index\KeyMapper;

/**
 * Reads the position of the files of the index that were not read yet (slice 052): the GPS of the photographs, the start and the end of the
 * GPX tracks. A pass of its own, so that the scan of the directories stays fast: the scan only records a file, this pass opens it afterwards,
 * once, and again only when the file changed (the scan sets the state back to 0).
 *
 * `geo_state`: 0 not read, 1 read with a position, 2 read without (no GPS, not readable, a type that has none).
 */
final class GeoPass {

	private const BATCH = 50;

	/** Extensions of the photographs whose header PHP can read (`exif_read_data`). */
	public const IMAGE_EXTENSIONS = [ 'jpg', 'jpeg', 'tif', 'tiff' ];

	private IndexStore $store;
	private KeyMapper $keys;
	/** @var callable(string): ?array{lat:float, lon:float} */
	private $imageReader;
	/** @var callable(string): ?array<string, mixed> */
	private $gpxReader;

	public function __construct( IndexStore $store, KeyMapper $keys, ?callable $imageReader = null, ?callable $gpxReader = null ) {
		$this->store       = $store;
		$this->keys        = $keys;
		$this->imageReader = $imageReader ?? [ GpsReader::class, 'fromImage' ];
		$this->gpxReader   = $gpxReader ?? [ GpsReader::class, 'gpxEndpoints' ];
	}

	/**
	 * @param float $seconds The time this run may take.
	 * @param int   $limit   At most this many files (0: no limit).
	 * @return array{read:int, with_position:int, without:int, left:bool} `left`: files remain to be read.
	 */
	public function run( float $seconds, int $limit = 0 ): array {
		$report = $this->work( $seconds, $limit );
		if ( $report['with_position'] > 0 && function_exists( 'do_action' ) ) {
			/**
			 * Positions were just read: the code that asked for the files of a page may ask again (slice 052).
			 *
			 * @param int    $count  How many cells or positions are new.
			 * @param string $source `positions` here, `places` after a pass of the places.
			 */
			do_action( 'wp_media_helper_places_updated', $report['with_position'], 'positions' );
		}

		return $report;
	}

	private function work( float $seconds, int $limit ): array {
		$deadline = microtime( true ) + max( 0.0, $seconds );
		$report   = [ 'read' => 0, 'with_position' => 0, 'without' => 0, 'left' => false ];

		while ( microtime( true ) < $deadline && ( 0 === $limit || $report['read'] < $limit ) ) {
			$rows = $this->store->filesNeedingGeo( 0 === $limit ? self::BATCH : min( self::BATCH, $limit - $report['read'] ) );
			if ( [] === $rows ) {
				return $report;
			}
			foreach ( $rows as $row ) {
				if ( microtime( true ) >= $deadline || ( 0 !== $limit && $report['read'] >= $limit ) ) {
					$report['left'] = true;

					return $report;
				}
				$geo = $this->read( $row );
				$this->store->saveGeo( (int) $row['id'], $geo );
				++$report['read'];
				1 === $geo['geo_state'] ? ++$report['with_position'] : ++$report['without'];
			}
		}

		$report['left'] = [] !== $this->store->filesNeedingGeo( 1 );

		return $report;
	}

	/**
	 * What to record for a file.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function read( array $row ): array {
		$path      = $this->keys->absolute( (string) $row['path'] );
		$extension = strtolower( (string) ( $row['ext'] ?? '' ) );
		$none      = [ 'geo_state' => 2 ];

		if ( 'image' === ( $row['kind'] ?? '' ) && in_array( $extension, self::IMAGE_EXTENSIONS, true ) ) {
			$position = ( $this->imageReader )( $path );

			return null === $position ? $none : [ 'geo_state' => 1, 'gps_lat' => $position['lat'], 'gps_lon' => $position['lon'] ];
		}

		if ( 'gps' === ( $row['kind'] ?? '' ) && 'gpx' === $extension ) {
			$track = ( $this->gpxReader )( $path );
			if ( ! is_array( $track ) ) {
				return $none;
			}

			return [
				'geo_state'      => 1,
				'gps_lat'        => $track['start']['lat'],
				'gps_lon'        => $track['start']['lon'],
				'gps_end_lat'    => $track['end']['lat'],
				'gps_end_lon'    => $track['end']['lon'],
				'track_start_at' => $track['start']['at'],
				'track_end_at'   => $track['end']['at'],
			];
		}

		return $none;
	}
}
