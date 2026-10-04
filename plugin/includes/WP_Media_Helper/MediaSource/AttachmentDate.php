<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\MediaSource;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Chooses the date of a registered attachment.
 *
 * Provisional default order (see slice 022, "Attachment date"): the capture
 * date of an image, the creation date of a video or audio file, a date written
 * in the file name, the modification time of the file, then the current time.
 */
class AttachmentDate {

	public const SOURCE_CAPTURE  = 'capture';
	public const SOURCE_MEDIA    = 'media';
	public const SOURCE_FILENAME = 'filename';
	public const SOURCE_MTIME    = 'mtime';
	public const SOURCE_NOW      = 'now';

	private const FORMAT = 'Y-m-d H:i:s';

	/**
	 * Time given to a date found without a time: the middle of the day.
	 */
	private const MEDIAN_TIME = '12:00:00';

	/**
	 * Earliest timestamp accepted as a real date (1990-01-01).
	 */
	private const MIN_TIMESTAMP = 631152000;

	/**
	 * @param int|null $captureWallClock Image `created_timestamp` from WordPress. EXIF has no time zone and core
	 *                                   stores the local wall-clock time as if it were UTC, so it is re-read as
	 *                                   site-local time.
	 * @param int|null $mediaInstant     Video or audio `created_timestamp`: a real instant.
	 * @param int|null $mtime            Modification time of the file: a real instant.
	 * @return array{local:string, gmt:string, source:string}
	 */
	public static function resolve( ?int $captureWallClock, ?int $mediaInstant, string $basename, ?int $mtime, DateTimeZone $timezone, int $now ): array {
		$utc = new DateTimeZone( 'UTC' );

		if ( self::isPlausible( $captureWallClock, $now ) ) {
			$date = DateTimeImmutable::createFromFormat( self::FORMAT, gmdate( self::FORMAT, (int) $captureWallClock ), $timezone );
			if ( false !== $date ) {
				return self::format( $date, $timezone, $utc, self::SOURCE_CAPTURE );
			}
		}

		if ( self::isPlausible( $mediaInstant, $now ) ) {
			return self::format( new DateTimeImmutable( '@' . (int) $mediaInstant ), $timezone, $utc, self::SOURCE_MEDIA );
		}

		$fromName = self::fromFilename( $basename, $timezone, $now );
		if ( null !== $fromName ) {
			return self::format( $fromName, $timezone, $utc, self::SOURCE_FILENAME );
		}

		if ( self::isPlausible( $mtime, $now ) ) {
			return self::format( new DateTimeImmutable( '@' . (int) $mtime ), $timezone, $utc, self::SOURCE_MTIME );
		}

		return self::format( new DateTimeImmutable( '@' . $now ), $timezone, $utc, self::SOURCE_NOW );
	}

	/**
	 * Finds a date such as `20261002_121549`, `2026-10-02` or `2026-10-02 12.15.49`
	 * in a file name. The time is read when the name has one. A date alone is
	 * taken at the median time of the day, 12:00:00 site time, so that a change of
	 * time zone or daylight saving time cannot move it to the previous or the next
	 * day, and so that it sorts in the middle of the day.
	 */
	public static function fromFilename( string $basename, DateTimeZone $timezone, int $now ): ?DateTimeImmutable {
		$pattern = '/(?<!\d)((?:19|20)\d{2})[-_.]?(0[1-9]|1[0-2])[-_.]?(0[1-9]|[12]\d|3[01])'
			. '(?:[T_\-\s.]?([01]\d|2[0-3])[-_.:]?([0-5]\d)[-_.:]?([0-5]\d))?(?!\d)/';
		if ( 1 !== preg_match( $pattern, $basename, $m ) ) {
			return null;
		}

		$year = (int) $m[1];
		if ( ! checkdate( (int) $m[2], (int) $m[3], $year ) || $year > (int) gmdate( 'Y', $now ) + 1 ) {
			return null;
		}

		$time = isset( $m[4] ) ? sprintf( '%s:%s:%s', $m[4], $m[5], $m[6] ) : self::MEDIAN_TIME;
		$date = DateTimeImmutable::createFromFormat( self::FORMAT, sprintf( '%s-%s-%s %s', $m[1], $m[2], $m[3], $time ), $timezone );

		return false === $date ? null : $date;
	}

	private static function isPlausible( ?int $timestamp, int $now ): bool {
		return null !== $timestamp && $timestamp >= self::MIN_TIMESTAMP && $timestamp <= $now + 366 * 86400;
	}

	/**
	 * @return array{local:string, gmt:string, source:string}
	 */
	private static function format( DateTimeImmutable $date, DateTimeZone $timezone, DateTimeZone $utc, string $source ): array {
		return [
			'local'  => $date->setTimezone( $timezone )->format( self::FORMAT ),
			'gmt'    => $date->setTimezone( $utc )->format( self::FORMAT ),
			'source' => $source,
		];
	}
}
