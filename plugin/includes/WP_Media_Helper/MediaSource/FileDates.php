<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\MediaSource;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The rule that gives a file its date, shared by the index (the day a file is
 * listed under) and by registration (the date of the attachment), so that what the
 * panel shows for a day is what the Media Library sorts by.
 *
 * Order: a date written in the name, then, when the source allows it, the
 * modification time. (Dates embedded in files and dates set by the user come in
 * a later slice and go before the modification time and before the name.)
 */
class FileDates {

	public const SOURCE_NAME  = 'name';
	public const SOURCE_MTIME = 'mtime';
	public const SOURCE_NONE  = 'none';

	/**
	 * Earliest timestamp accepted as a real date (1990-01-01).
	 */
	public const MIN_TIMESTAMP = 631152000;

	private const FORMAT = 'Y-m-d H:i:s';

	/**
	 * Name patterns of a source: its configured pattern, then the generic recogniser.
	 *
	 * @param array<string, mixed> $source
	 * @return NamePattern[]
	 */
	public static function patterns( array $source ): array {
		$patterns = [];
		$filter   = trim( (string) ( $source['filter_pattern'] ?? '' ) );
		if ( '' !== $filter ) {
			$configured = NamePattern::fromLegacyFilter( $filter );
			if ( null !== $configured ) {
				$patterns[] = $configured;
			}
		}
		$patterns[] = NamePattern::generic();

		return $patterns;
	}

	/**
	 * Whether a source falls back to the modification time when the name has no
	 * date. On unless the source says otherwise.
	 *
	 * @param array<string, mixed> $source
	 */
	public static function usesMtimeFallback( array $source ): bool {
		return ! array_key_exists( 'mtime_fallback', $source ) || filter_var( $source['mtime_fallback'], FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Changes when what the rule depends on changes in a source, so that stored
	 * dates can be recomputed.
	 *
	 * @param array<string, mixed> $source
	 */
	public static function configHash( array $source ): string {
		return md5( trim( (string) ( $source['filter_pattern'] ?? '' ) ) . '|' . ( self::usesMtimeFallback( $source ) ? '1' : '0' ) );
	}

	public static function isPlausible( ?int $timestamp, int $now ): bool {
		return null !== $timestamp && $timestamp >= self::MIN_TIMESTAMP && $timestamp <= $now + 366 * 86400;
	}

	/**
	 * @param NamePattern[] $patterns
	 * @return array{date:DateTimeImmutable, precision:string}|null
	 */
	public static function nameDate( string $name, array $patterns, DateTimeZone $timezone, int $now ): ?array {
		foreach ( $patterns as $pattern ) {
			$found = $pattern->match( $name, $timezone, $now );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * @param NamePattern[] $patterns
	 * @return array{name_date:string|null, precision:string|null, local:string|null, gmt:string|null, day:string|null, source:string}
	 */
	public static function effective( string $name, ?int $mtime, array $patterns, bool $mtimeFallback, DateTimeZone $timezone, int $now ): array {
		$found = self::nameDate( $name, $patterns, $timezone, $now );
		if ( null !== $found ) {
			$formatted = self::format( $found['date'], $timezone );

			return [
				'name_date' => $formatted['local'],
				'precision' => $found['precision'],
				'local'     => $formatted['local'],
				'gmt'       => $formatted['gmt'],
				'day'       => $formatted['day'],
				'source'    => self::SOURCE_NAME,
			];
		}

		if ( $mtimeFallback && self::isPlausible( $mtime, $now ) ) {
			$formatted = self::format( new DateTimeImmutable( '@' . (int) $mtime ), $timezone );

			return [
				'name_date' => null,
				'precision' => NamePattern::PRECISION_SECOND,
				'local'     => $formatted['local'],
				'gmt'       => $formatted['gmt'],
				'day'       => $formatted['day'],
				'source'    => self::SOURCE_MTIME,
			];
		}

		return [
			'name_date' => null,
			'precision' => null,
			'local'     => null,
			'gmt'       => null,
			'day'       => null,
			'source'    => self::SOURCE_NONE,
		];
	}

	/**
	 * @return array{local:string, gmt:string, day:string}
	 */
	public static function format( DateTimeImmutable $date, DateTimeZone $timezone ): array {
		$local = $date->setTimezone( $timezone );

		return [
			'local' => $local->format( self::FORMAT ),
			'gmt'   => $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( self::FORMAT ),
			'day'   => $local->format( 'Y-m-d' ),
		];
	}
}
