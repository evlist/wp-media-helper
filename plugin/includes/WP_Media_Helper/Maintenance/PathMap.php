<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Maintenance;

use InvalidArgumentException;

/**
 * The mapping from old path prefixes to new ones, all relative to the uploads directory.
 *
 * An entry says that the files below `from` are now below `to`, that they belong to the source
 * `source`, and that the root directory of that source is `root` (`to` itself, or a directory
 * above it when several old sources are joined into one). The longest `from` wins.
 */
final class PathMap {

	/**
	 * @var array<int, array{from:string, to:string, source:string, from_source:string, root:string}>
	 */
	private array $entries = [];

	/**
	 * @param array<int, array<string, string>> $entries
	 * @throws InvalidArgumentException When an entry is not usable.
	 */
	public function __construct( array $entries ) {
		foreach ( $entries as $entry ) {
			$from = self::clean( (string) ( $entry['from'] ?? '' ) );
			$to   = self::clean( (string) ( $entry['to'] ?? '' ) );
			$root = '' === (string) ( $entry['root'] ?? '' ) ? $to : self::clean( (string) $entry['root'] );

			if ( $from === $to ) {
				throw new InvalidArgumentException( sprintf( 'The prefix "%s" is mapped onto itself.', $from ) );
			}
			if ( $root !== $to && ! str_starts_with( $to, $root . '/' ) ) {
				throw new InvalidArgumentException( sprintf( 'The root "%1$s" of the source is not "%2$s" or a directory above it.', $root, $to ) );
			}
			foreach ( $this->entries as $other ) {
				if ( $other['from'] === $from ) {
					throw new InvalidArgumentException( sprintf( 'The prefix "%s" is mapped twice.', $from ) );
				}
			}

			$this->entries[] = [
				'from'        => $from,
				'to'          => $to,
				'source'      => (string) ( $entry['source'] ?? '' ),
				'from_source' => (string) ( $entry['from_source'] ?? '' ),
				'root'        => $root,
			];
		}

		usort( $this->entries, static fn ( array $a, array $b ): int => strlen( $b['from'] ) <=> strlen( $a['from'] ) );
	}

	/**
	 * Parses lines of `old new source [old_source]`, `#` starting a comment.
	 *
	 * @throws InvalidArgumentException When a line is not usable.
	 */
	public static function parse( string $text ): self {
		$entries = [];
		foreach ( preg_split( '/\R/', $text ) ?: [] as $number => $line ) {
			$line = trim( (string) preg_replace( '/#.*$/', '', $line ) );
			if ( '' === $line ) {
				continue;
			}
			$fields = preg_split( '/\s+/', $line ) ?: [];
			if ( count( $fields ) < 3 || count( $fields ) > 4 ) {
				throw new InvalidArgumentException( sprintf( 'Line %d: expected "old new source [old_source]".', $number + 1 ) );
			}
			$entries[] = [ 'from' => $fields[0], 'to' => $fields[1], 'source' => $fields[2], 'from_source' => $fields[3] ?? '' ];
		}

		return new self( $entries );
	}

	/**
	 * @return array<int, array{from:string, to:string, source:string, from_source:string, root:string}>
	 */
	public function entries(): array {
		return $this->entries;
	}

	/**
	 * @return array{path:string, entry:array{from:string, to:string, source:string, from_source:string, root:string}}|null
	 */
	public function map( string $path ): ?array {
		foreach ( $this->entries as $entry ) {
			if ( $path === $entry['from'] ) {
				return [ 'path' => $entry['to'], 'entry' => $entry ];
			}
			if ( str_starts_with( $path, $entry['from'] . '/' ) ) {
				return [ 'path' => $entry['to'] . substr( $path, strlen( $entry['from'] ) ), 'entry' => $entry ];
			}
		}

		return null;
	}

	/**
	 * The mapping that puts everything back. The source of the old paths becomes the target; it is
	 * empty when it was not known, and the source of the rows is then left as it is.
	 */
	public function inverse(): self {
		$entries = [];
		foreach ( $this->entries as $entry ) {
			$entries[] = [ 'from' => $entry['to'], 'to' => $entry['from'], 'source' => $entry['from_source'], 'from_source' => $entry['source'], 'root' => $entry['from'] ];
		}

		return new self( $entries );
	}

	/**
	 * @throws InvalidArgumentException
	 */
	public static function clean( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		if ( str_starts_with( $path, '/' ) ) {
			throw new InvalidArgumentException( sprintf( 'The path "%s" is not a path relative to the uploads directory.', $path ) );
		}
		$path = trim( $path, '/' );
		if ( '' === $path || str_contains( $path, "\0" ) || 1 === preg_match( '#(^|/)\.\.?(/|$)|//#', $path ) || 1 === preg_match( '#^[A-Za-z]:#', $path ) ) {
			throw new InvalidArgumentException( sprintf( 'The path "%s" is not a path relative to the uploads directory.', $path ) );
		}

		return $path;
	}
}
