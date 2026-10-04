<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\MediaSource;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Reads the date, and the time when there is one, written in a file name.
 *
 * A pattern is written with the same placeholders as the path and filter
 * patterns: `{date:Ymd}` is a date and `{date:His}` a time, with the format
 * letters `Y m d H i s`. Everything outside placeholders is literal text. A
 * pattern is searched for anywhere in the name, and a run of digits next to a date
 * field must not continue the field, so `20261002121549` is never read as a longer
 * number.
 *
 * A date alone is given the median time of the day, so that it keeps its day
 * whatever the time zone.
 */
class NamePattern {

	public const MEDIAN_TIME      = '12:00:00';
	public const PRECISION_DAY    = 'day';
	public const PRECISION_SECOND = 'second';

	private const FIELDS = [
		'Y' => '(\d{4})',
		'm' => '(0[1-9]|1[0-2])',
		'd' => '(0[1-9]|[12]\d|3[01])',
		'H' => '([01]\d|2[0-3])',
		'i' => '([0-5]\d)',
		's' => '([0-5]\d)',
	];

	private const MAX_LENGTH = 200;

	private string $regex;

	/**
	 * @var array<string, int> Capture group of each field.
	 */
	private array $groups;

	/**
	 * @param array<string, int> $groups
	 */
	private function __construct( string $regex, array $groups ) {
		$this->regex  = $regex;
		$this->groups = $groups;
	}

	/**
	 * The recogniser used when a source has no pattern, or none matches: a date
	 * such as `20261002_121549`, `2026-10-02` or `2026-10-02 12.15.49` anywhere in
	 * the name.
	 */
	public static function generic(): self {
		static $generic = null;
		if ( null === $generic ) {
			$generic = new self(
				'/(?<!\d)((?:19|20)\d{2})[-_.]?(0[1-9]|1[0-2])[-_.]?(0[1-9]|[12]\d|3[01])'
				. '(?:[T_\-\s.]?([01]\d|2[0-3])[-_.:]?([0-5]\d)[-_.:]?([0-5]\d))?(?!\d)/',
				[ 'Y' => 1, 'm' => 2, 'd' => 3, 'H' => 4, 'i' => 5, 's' => 6 ]
			);
		}

		return $generic;
	}

	/**
	 * The former filter pattern of a source, such as `{date:Ymd}`, read as a name
	 * pattern: the name contains a date written that way.
	 */
	public static function fromLegacyFilter( string $filter ): ?self {
		return self::compile( $filter );
	}

	/**
	 * Returns null when the pattern is not valid: it must contain the year, the
	 * month and the day, a time needs the hour and the minute, a field appears once,
	 * and only the letters `Y m d H i s` may be used inside a placeholder.
	 */
	public static function compile( string $pattern ): ?self {
		$pattern = trim( $pattern );
		if ( '' === $pattern || strlen( $pattern ) > self::MAX_LENGTH ) {
			return null;
		}

		$parts  = preg_split( '/(\{date:[^{}]*\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		$tokens = [];
		$groups = [];
		$count  = 0;

		foreach ( (array) $parts as $part ) {
			if ( 1 === preg_match( '/^\{date:([^{}]*)\}$/', $part, $found ) ) {
				if ( '' === $found[1] ) {
					return null;
				}
				foreach ( str_split( $found[1] ) as $character ) {
					if ( isset( self::FIELDS[ $character ] ) ) {
						if ( isset( $groups[ $character ] ) ) {
							return null;
						}
						$groups[ $character ] = ++$count;
						$tokens[]             = [ 'field', self::FIELDS[ $character ] ];
					} elseif ( ctype_alpha( $character ) ) {
						return null;
					} else {
						$tokens[] = [ 'text', preg_quote( $character, '/' ) ];
					}
				}
				continue;
			}

			if ( str_contains( $part, '{' ) || str_contains( $part, '}' ) ) {
				return null;
			}
			$tokens[] = [ 'text', preg_quote( $part, '/' ) ];
		}

		if ( ! isset( $groups['Y'], $groups['m'], $groups['d'] ) || isset( $groups['H'] ) !== isset( $groups['i'] ) || ( isset( $groups['s'] ) && ! isset( $groups['H'] ) ) ) {
			return null;
		}

		$regex = '';
		foreach ( $tokens as $token ) {
			$regex .= $token[1];
		}
		$start = 'field' === $tokens[0][0] ? '(?<!\d)' : '';
		$end   = 'field' === $tokens[ count( $tokens ) - 1 ][0] ? '(?!\d)' : '';

		return new self( '/' . $start . $regex . $end . '/', $groups );
	}

	/**
	 * @return array{date:DateTimeImmutable, precision:string}|null
	 */
	public function match( string $name, DateTimeZone $timezone, int $now ): ?array {
		if ( 1 !== preg_match( $this->regex, $name, $found ) ) {
			return null;
		}

		$year  = (int) $found[ $this->groups['Y'] ];
		$month = (int) $found[ $this->groups['m'] ];
		$day   = (int) $found[ $this->groups['d'] ];
		if ( $year < 1990 || $year > (int) gmdate( 'Y', $now ) + 1 || ! checkdate( $month, $day, $year ) ) {
			return null;
		}

		$hasTime = isset( $this->groups['H'] ) && '' !== ( $found[ $this->groups['H'] ] ?? '' );
		$time    = self::MEDIAN_TIME;
		if ( $hasTime ) {
			$time = sprintf(
				'%02d:%02d:%02d',
				(int) $found[ $this->groups['H'] ],
				(int) ( $found[ $this->groups['i'] ?? 0 ] ?? 0 ),
				isset( $this->groups['s'] ) ? (int) ( $found[ $this->groups['s'] ] ?? 0 ) : 0
			);
		}

		$date = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', sprintf( '%04d-%02d-%02d %s', $year, $month, $day, $time ), $timezone );
		if ( false === $date ) {
			return null;
		}

		return [
			'date'      => $date,
			'precision' => $hasTime ? self::PRECISION_SECOND : self::PRECISION_DAY,
		];
	}
}
