<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\MediaSource;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Reads the date, and the time when there is one, written in a file name.
 *
 * A pattern is written with the same placeholders as the path patterns:
 * `{date:Ymd}` is a date and `{date:His}` a time (letters `Y m d H i s v`),
 * `[ ... ]` is an optional part, `*` any run of characters and everything else is
 * literal text. A pattern is searched for anywhere in the name, and a run of digits next to a date
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
		'v' => '(\d{3})',
	];

	private const MAX_LENGTH = 200;

	/**
	 * Wildcards in a pattern: each one makes a failed match cost more, so the number is kept low.
	 */
	private const MAX_STARS = 3;

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
	 * Returns null when the pattern is not valid.
	 *
	 * Syntax: `{date:Ymd}` is a date or a time, with the letters `Y m d H i s v` (year,
	 * month, day, hour, minute, second, milliseconds) and any separator between them;
	 * `[ ... ]` is an optional part (not nested, and without the year, month and day);
	 * `*` is any run of characters; `\` makes the next character literal; anything else
	 * is literal text. The year, month and day must appear once each, a time needs the
	 * hour and the minute, the seconds need the hour, the milliseconds need the seconds.
	 * The pattern is searched anywhere in the name, and a run of digits next to a date
	 * field must not continue the field.
	 */
	public static function compile( string $pattern ): ?self {
		$pattern = trim( $pattern );
		if ( '' === $pattern || strlen( $pattern ) > self::MAX_LENGTH ) {
			return null;
		}

		$tokens = self::tokenize( $pattern );
		if ( null === $tokens || count( array_filter( $tokens, static fn ( array $token ): bool => 'star' === $token[0] ) ) > self::MAX_STARS ) {
			return null;
		}

		$groups    = [];
		$count     = 0;
		$regex     = '';
		$endsField = false;   // The regex so far can end with a date field.
		$boundary  = true;    // Start of the pattern, or just after `*`.
		$optional  = false;
		$before    = false;

		foreach ( $tokens as $token ) {
			switch ( $token[0] ) {
				case 'open':
					$optional = true;
					$before   = $endsField;
					$regex   .= '(?:';
					break;
				case 'close':
					$optional  = false;
					$endsField = $endsField || $before;
					$regex    .= ')?';
					break;
				case 'star':
					$regex    .= ( $endsField ? '(?!\d)' : '' ) . '.*?';
					$endsField = false;
					$boundary  = true;
					break;
				case 'text':
					$regex    .= preg_quote( $token[1], '/' );
					$endsField = false;
					$boundary  = false;
					break;
				case 'field':
					$letter = $token[1];
					if ( isset( $groups[ $letter ] ) || ( $optional && in_array( $letter, [ 'Y', 'm', 'd' ], true ) ) ) {
						return null;
					}
					$groups[ $letter ] = ++$count;
					$regex            .= ( $boundary ? '(?<!\d)' : '' ) . self::FIELDS[ $letter ];
					$endsField         = true;
					$boundary          = false;
					break;
			}
		}

		if ( $optional || ! isset( $groups['Y'], $groups['m'], $groups['d'] )
			|| isset( $groups['H'] ) !== isset( $groups['i'] )
			|| ( isset( $groups['s'] ) && ! isset( $groups['H'] ) )
			|| ( isset( $groups['v'] ) && ! isset( $groups['s'] ) ) ) {
			return null;
		}

		return new self( '/' . $regex . ( $endsField ? '(?!\d)' : '' ) . '/', $groups );
	}

	/**
	 * @return array<int, array{0:string, 1?:string}>|null
	 */
	private static function tokenize( string $pattern ): ?array {
		$tokens = [];
		$open   = false;
		$length = strlen( $pattern );

		for ( $i = 0; $i < $length; ++$i ) {
			$character = $pattern[ $i ];

			if ( '\\' === $character ) {
				if ( $i + 1 >= $length ) {
					return null;
				}
				$tokens[] = [ 'text', $pattern[ ++$i ] ];
			} elseif ( '{' === $character ) {
				$end = strpos( $pattern, '}', $i );
				if ( 0 !== strncmp( substr( $pattern, $i, 6 ), '{date:', 6 ) || false === $end || $end === $i + 6 ) {
					return null;
				}
				foreach ( str_split( substr( $pattern, $i + 6, $end - $i - 6 ) ) as $letter ) {
					if ( isset( self::FIELDS[ $letter ] ) ) {
						$tokens[] = [ 'field', $letter ];
					} elseif ( ctype_alpha( $letter ) || '{' === $letter ) {
						return null;
					} else {
						$tokens[] = [ 'text', $letter ];
					}
				}
				$i = $end;
			} elseif ( '}' === $character ) {
				return null;
			} elseif ( '[' === $character ) {
				if ( $open ) {
					return null;
				}
				$open     = true;
				$tokens[] = [ 'open' ];
			} elseif ( ']' === $character ) {
				if ( ! $open ) {
					return null;
				}
				$open     = false;
				$tokens[] = [ 'close' ];
			} elseif ( '*' === $character ) {
				$tokens[] = [ 'star' ];
			} else {
				$tokens[] = [ 'text', $character ];
			}
		}

		return $open ? null : $tokens;
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
		$hasSeconds = $hasTime && isset( $this->groups['s'] ) && '' !== ( $found[ $this->groups['s'] ] ?? '' );
		$time    = self::MEDIAN_TIME;
		if ( $hasTime ) {
			$time = sprintf(
				'%02d:%02d:%02d',
				(int) $found[ $this->groups['H'] ],
				(int) ( $found[ $this->groups['i'] ] ?? 0 ),
				$hasSeconds ? (int) $found[ $this->groups['s'] ] : 0
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
