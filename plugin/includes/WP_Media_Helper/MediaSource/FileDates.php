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
 * Order: a date written in the name, then a date embedded in the file (the capture
 * date of a photo), then, when the source allows it, the modification time. (Dates
 * set by the user come in a later slice and go before the name.)
 */
class FileDates {

	public const SOURCE_NAME     = 'name';
	public const SOURCE_EMBEDDED = 'embedded';
	public const SOURCE_MTIME = 'mtime';
	public const SOURCE_NONE  = 'none';

	/**
	 * Earliest timestamp accepted as a real date (1990-01-01).
	 */
	public const MIN_TIMESTAMP = 631152000;

	/**
	 * Changes when what a scan reads from the files changes (embedded dates, image dimensions), so existing rows are read again by a full pass.
	 */
	private const EMBEDDED_VERSION = 'e3';

	private const FORMAT = 'Y-m-d H:i:s';

	/**
	 * The name patterns written for a source, in order: its `name_patterns` list, or the
	 * single `filter_pattern` of a source saved before there were several.
	 *
	 * @param array<string, mixed> $source
	 * @return string[]
	 */
	public static function patternStrings( array $source ): array {
		$list = $source['name_patterns'] ?? null;
		if ( is_string( $list ) ) {
			$list = preg_split( '/\R/', $list ) ?: [];
		}
		if ( ! is_array( $list ) ) {
			$list = [ (string) ( $source['filter_pattern'] ?? '' ) ];
		}

		$strings = [];
		foreach ( $list as $pattern ) {
			$pattern = is_string( $pattern ) ? trim( $pattern ) : '';
			if ( '' !== $pattern ) {
				$strings[] = $pattern;
			}
		}

		return $strings;
	}

	/**
	 * Name patterns of a source: the configured ones in order, then the generic recogniser.
	 *
	 * @param array<string, mixed> $source
	 * @return NamePattern[]
	 */
	public static function patterns( array $source ): array {
		$patterns = [];
		foreach ( self::patternStrings( $source ) as $string ) {
			$configured = NamePattern::compile( $string );
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
		return md5( implode( "\n", self::patternStrings( $source ) ) . '|' . ( self::usesMtimeFallback( $source ) ? '1' : '0' ) . '|' . self::EMBEDDED_VERSION );
	}

	public static function isPlausible( ?int $timestamp, int $now ): bool {
		return null !== $timestamp && $timestamp >= self::MIN_TIMESTAMP && $timestamp <= $now + 366 * 86400;
	}

	/**
	 * The date a name gives, with its time refined by the file when the name has a day only.
	 *
	 * A day alone is placed at the median time of the day. When the file says the same day
	 * (the capture date of a photo, the creation date of a video) its time is used instead,
	 * so a file named after its day sorts in the order it was taken. When the days differ, for
	 * example a video assembled later, the median time is kept: the name is the user's statement
	 * of the day.
	 *
	 * @param array{date:DateTimeImmutable, precision:string} $name
	 */
	public static function refineTime( array $name, ?DateTimeImmutable $embedded, DateTimeZone $timezone ): DateTimeImmutable {
		if ( NamePattern::PRECISION_DAY !== $name['precision'] || null === $embedded ) {
			return $name['date'];
		}

		$local = $embedded->setTimezone( $timezone );

		return $local->format( 'Y-m-d' ) === $name['date']->setTimezone( $timezone )->format( 'Y-m-d' ) ? $local : $name['date'];
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
	 * @param NamePattern[]          $patterns
	 * @param DateTimeImmutable|null $embedded The date read from the content of the file, in site time, when there is one.
	 * @return array{name_date:string|null, precision:string|null, local:string|null, gmt:string|null, day:string|null, source:string}
	 */
	public static function effective( string $name, ?int $mtime, array $patterns, bool $mtimeFallback, DateTimeZone $timezone, int $now, ?DateTimeImmutable $embedded = null ): array {
		$found = self::nameDate( $name, $patterns, $timezone, $now );
		if ( null !== $found ) {
			$named     = self::format( $found['date'], $timezone );
			$formatted = self::format( self::refineTime( $found, $embedded, $timezone ), $timezone );

			return [
				'name_date' => $named['local'],
				'precision' => $found['precision'],
				'local'     => $formatted['local'],
				'gmt'       => $formatted['gmt'],
				'day'       => $formatted['day'],
				'source'    => self::SOURCE_NAME,
			];
		}

		if ( null !== $embedded && self::isPlausible( $embedded->getTimestamp(), $now ) ) {
			$formatted = self::format( $embedded, $timezone );

			return [
				'name_date' => null,
				'precision' => NamePattern::PRECISION_SECOND,
				'local'     => $formatted['local'],
				'gmt'       => $formatted['gmt'],
				'day'       => $formatted['day'],
				'source'    => self::SOURCE_EMBEDDED,
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
