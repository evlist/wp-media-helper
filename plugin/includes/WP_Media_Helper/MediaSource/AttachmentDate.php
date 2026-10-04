<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\MediaSource;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Chooses the date of a registered attachment.
 *
 * Order: a date written in the file name (the user's own statement of the day, which
 * also covers files without metadata and videos assembled by a tool that writes
 * unrelated metadata), the capture date of an image, the creation date of a video
 * or audio file, the modification time when the source allows it, then the current
 * time. The name and the modification time follow the same rule as the file index
 * ({@see FileDates}).
 */
class AttachmentDate {

	public const SOURCE_CAPTURE  = 'capture';
	public const SOURCE_MEDIA    = 'media';
	public const SOURCE_FILENAME = 'filename';
	public const SOURCE_MTIME    = 'mtime';
	public const SOURCE_NOW      = 'now';

	private const FORMAT = 'Y-m-d H:i:s';

	/**
	 * @param int|null                  $captureWallClock Image `created_timestamp` from WordPress. EXIF has no time zone and
	 *                                                    core stores the local wall-clock time as if it were UTC, so it is
	 *                                                    re-read as site-local time.
	 * @param int|null                  $mediaInstant     Video or audio `created_timestamp`: a real instant.
	 * @param int|null                  $mtime            Modification time of the file: a real instant.
	 * @param array<string, mixed>|null $source           The source of the file: its name pattern and modification-time setting.
	 * @return array{local:string, gmt:string, source:string}
	 */
	public static function resolve( ?int $captureWallClock, ?int $mediaInstant, string $basename, ?int $mtime, DateTimeZone $timezone, int $now, ?array $source = null ): array {
		$source = $source ?? [];

		$name = FileDates::nameDate( $basename, FileDates::patterns( $source ), $timezone, $now );
		if ( null !== $name ) {
			return self::format( $name['date'], $timezone, self::SOURCE_FILENAME );
		}

		if ( FileDates::isPlausible( $captureWallClock, $now ) ) {
			$date = DateTimeImmutable::createFromFormat( self::FORMAT, gmdate( self::FORMAT, (int) $captureWallClock ), $timezone );
			if ( false !== $date ) {
				return self::format( $date, $timezone, self::SOURCE_CAPTURE );
			}
		}

		if ( FileDates::isPlausible( $mediaInstant, $now ) ) {
			return self::format( new DateTimeImmutable( '@' . (int) $mediaInstant ), $timezone, self::SOURCE_MEDIA );
		}

		if ( FileDates::usesMtimeFallback( $source ) && FileDates::isPlausible( $mtime, $now ) ) {
			return self::format( new DateTimeImmutable( '@' . (int) $mtime ), $timezone, self::SOURCE_MTIME );
		}

		return self::format( new DateTimeImmutable( '@' . $now ), $timezone, self::SOURCE_NOW );
	}

	/**
	 * Finds a date such as `20261002_121549`, `2026-10-02` or `2026-10-02 12.15.49`
	 * in a file name. The time is read when the name has one. A date alone is taken at
	 * the median time of the day, 12:00:00 site time. Its GMT date is then the same
	 * day for any site time zone within twelve hours of UTC (midnight would give the
	 * previous GMT day east of UTC), and it sorts in the middle of the day. The local
	 * date is always the one in the name.
	 */
	public static function fromFilename( string $basename, DateTimeZone $timezone, int $now ): ?DateTimeImmutable {
		$found = NamePattern::generic()->match( $basename, $timezone, $now );

		return null === $found ? null : $found['date'];
	}

	/**
	 * @return array{local:string, gmt:string, source:string}
	 */
	private static function format( DateTimeImmutable $date, DateTimeZone $timezone, string $source ): array {
		$formatted = FileDates::format( $date, $timezone );

		return [
			'local'  => $formatted['local'],
			'gmt'    => $formatted['gmt'],
			'source' => $source,
		];
	}
}
