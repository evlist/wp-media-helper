<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Geo;

use DateTimeImmutable;
use DateTimeZone;
use DOMElement;
use XMLReader;

/**
 * Reads where a file was taken (slice 052): the GPS of a photograph, the start and the end of a GPX track. Nothing here touches the
 * network or the database.
 */
final class GpsReader {

	/** A GPX file larger than this is not read. */
	public const MAX_GPX_BYTES = 200 * 1024 * 1024;

	/**
	 * A position as a decimal pair, or null when it is out of range or exactly 0, 0 (what a camera without a fix writes).
	 *
	 * @return array{lat:float, lon:float}|null
	 */
	public static function position( float $lat, float $lon ): ?array {
		if ( $lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0 || ( 0.0 === $lat && 0.0 === $lon ) ) {
			return null;
		}

		return [ 'lat' => round( $lat, 6 ), 'lon' => round( $lon, 6 ) ];
	}

	/**
	 * The coordinates written in the EXIF of a photograph: `GPSLatitude` and `GPSLongitude` as degrees, minutes and seconds in rationals
	 * (`"43/1"`), with `GPSLatitudeRef` N or S and `GPSLongitudeRef` E or W.
	 *
	 * @param array<string, mixed> $exif What `exif_read_data()` gives.
	 * @return array{lat:float, lon:float}|null
	 */
	public static function exifPosition( array $exif ): ?array {
		$lat = self::degrees( $exif['GPSLatitude'] ?? null );
		$lon = self::degrees( $exif['GPSLongitude'] ?? null );
		if ( null === $lat || null === $lon ) {
			return null;
		}

		$latRef = strtoupper( trim( (string) ( $exif['GPSLatitudeRef'] ?? 'N' ) ) );
		$lonRef = strtoupper( trim( (string) ( $exif['GPSLongitudeRef'] ?? 'E' ) ) );
		if ( 'S' === $latRef ) {
			$lat = -$lat;
		} elseif ( 'N' !== $latRef ) {
			return null;
		}
		if ( 'W' === $lonRef ) {
			$lon = -$lon;
		} elseif ( 'E' !== $lonRef ) {
			return null;
		}

		return self::position( $lat, $lon );
	}

	/**
	 * @param mixed $value Three rationals (degrees, minutes, seconds), or fewer.
	 */
	private static function degrees( $value ): ?float {
		if ( ! is_array( $value ) || [] === $value || count( $value ) > 3 ) {
			return null;
		}

		$total = 0.0;
		foreach ( array_values( $value ) as $index => $part ) {
			$number = self::rational( $part );
			if ( null === $number ) {
				return null;
			}
			$total += $number / ( 60 ** $index );
		}

		return $total;
	}

	/**
	 * @param mixed $part
	 */
	private static function rational( $part ): ?float {
		if ( is_int( $part ) || is_float( $part ) ) {
			return (float) $part;
		}
		if ( is_string( $part ) && 1 === preg_match( '#^\s*(-?\d+(?:\.\d+)?)\s*(?:/\s*(\d+(?:\.\d+)?))?\s*$#', $part, $found ) ) {
			$denominator = isset( $found[2] ) ? (float) $found[2] : 1.0;

			return 0.0 === $denominator ? null : (float) $found[1] / $denominator;
		}

		return null;
	}

	/**
	 * The position of a photograph, read from its header, or null (no GPS, not readable, the `exif` extension is missing).
	 *
	 * @return array{lat:float, lon:float}|null
	 */
	public static function fromImage( string $path ): ?array {
		if ( ! function_exists( 'exif_read_data' ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		$exif = @exif_read_data( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! is_array( $exif ) ) {
			return null;
		}

		return self::exifPosition( $exif );
	}

	/**
	 * The start and the end of a GPX track: the point of the smallest and the point of the largest `<time>`, whatever the order of the file
	 * (the file order is not always chronological, and the time of `<metadata>` is often that of the export). Without any time, the first
	 * and the last point of the file. The points of the tracks (`trkpt`) are used; the points of the routes (`rtept`) and the waypoints (`wpt`)
	 * only when there is no track point. Read as a stream, without entities nor network.
	 *
	 * @return array{start: array{lat:float, lon:float, at:?string}, end: array{lat:float, lon:float, at:?string}}|null `at` is UTC, written Y-m-d H:i:s.
	 */
	public static function gpxEndpoints( string $path ): ?array {
		if ( ! is_file( $path ) || ! is_readable( $path ) || (int) filesize( $path ) > self::MAX_GPX_BYTES ) {
			return null;
		}
		// A GPX file has no document type: one that declares entities is refused, whatever the parser does with them.
		$head = (string) file_get_contents( $path, false, null, 0, 4096 );
		if ( false !== stripos( $head, '<!ENTITY' ) || false !== stripos( $head, '<!DOCTYPE' ) ) {
			return null;
		}

		$reader = new XMLReader();
		if ( ! @$reader->open( $path, null, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return null;
		}

		$groups = [ 'trkpt' => [], 'rtept' => [], 'wpt' => [] ];
		try {
			$more = @$reader->read(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			while ( $more ) {
				if ( XMLReader::ELEMENT === $reader->nodeType && isset( $groups[ $reader->localName ] ) ) {
					$group = $reader->localName;
					$node  = @$reader->expand(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					if ( $node instanceof DOMElement ) {
						$point = self::gpxPoint( $node );
						if ( null !== $point ) {
							$groups[ $group ][] = $point;
						}
					}
					// The point is done: on to its next sibling, without going into it.
					$more = @$reader->next(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					continue;
				}
				$more = @$reader->read(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		} finally {
			$reader->close();
		}

		foreach ( [ 'trkpt', 'rtept', 'wpt' ] as $group ) {
			if ( [] !== $groups[ $group ] ) {
				return self::endpoints( $groups[ $group ] );
			}
		}

		return null;
	}

	/**
	 * @return array{lat:float, lon:float, time:?int}|null
	 */
	private static function gpxPoint( DOMElement $node ): ?array {
		if ( ! is_numeric( $node->getAttribute( 'lat' ) ) || ! is_numeric( $node->getAttribute( 'lon' ) ) ) {
			return null;
		}
		$position = self::position( (float) $node->getAttribute( 'lat' ), (float) $node->getAttribute( 'lon' ) );
		if ( null === $position ) {
			return null;
		}

		$time  = null;
		$times = $node->getElementsByTagNameNS( '*', 'time' );
		if ( $times->length > 0 ) {
			$time = self::utcTimestamp( trim( (string) $times->item( 0 )->textContent ) );
		}

		return [ 'lat' => $position['lat'], 'lon' => $position['lon'], 'time' => $time ];
	}

	private static function utcTimestamp( string $text ): ?int {
		if ( '' === $text ) {
			return null;
		}
		try {
			return ( new DateTimeImmutable( $text, new DateTimeZone( 'UTC' ) ) )->getTimestamp();
		} catch ( \Exception $exception ) {
			return null;
		}
	}

	/**
	 * @param array<int, array{lat:float, lon:float, time:?int}> $points In the order of the file.
	 * @return array{start: array{lat:float, lon:float, at:?string}, end: array{lat:float, lon:float, at:?string}}
	 */
	public static function endpoints( array $points ): array {
		$timed = array_values( array_filter( $points, static fn ( array $point ): bool => null !== $point['time'] ) );
		if ( [] === $timed ) {
			$start = $points[0];
			$end   = $points[ count( $points ) - 1 ];
		} else {
			$start = $timed[0];
			$end   = $timed[0];
			foreach ( $timed as $point ) {
				if ( $point['time'] < $start['time'] ) {
					$start = $point;
				}
				if ( $point['time'] >= $end['time'] ) {
					$end = $point;
				}
			}
		}

		$at = static fn ( ?int $time ): ?string => null === $time ? null : gmdate( 'Y-m-d H:i:s', $time );

		return [
			'start' => [ 'lat' => $start['lat'], 'lon' => $start['lon'], 'at' => $at( $start['time'] ) ],
			'end'   => [ 'lat' => $end['lat'], 'lon' => $end['lon'], 'at' => $at( $end['time'] ) ],
		];
	}
}
