<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\MediaSource;

use DateTimeInterface;
use InvalidArgumentException;

class DatePatternResolver {

	/**
	 * Resolves a fully qualified source path.
	 *
	 * When no path pattern is configured, the source root is used directly.
	 *
	 * @param string            $root    Absolute root path of the source.
	 * @param string            $pattern Optional pattern to resolve under the root.
	 * @param DateTimeInterface $date    Date used to resolve placeholders.
	 * @param string|null       $source  Optional source identifier used for {source}.
	 * @return string
	 */
	public function resolvePath( string $root, string $pattern, DateTimeInterface $date, ?string $source = null ): string {
		$trimmedRoot = rtrim( $root, '/\\' );
		if ( '' === $pattern || null === $pattern ) {
			return $trimmedRoot;
		}

		$resolved = $this->resolve( $pattern, $date, $source );
		return rtrim( $trimmedRoot, '/\\' ) . '/' . ltrim( $resolved, '/\\' );
	}

	/**
	 * Resolves a filter pattern independently from the base directory.
	 *
	 * @param string            $pattern Pattern containing placeholders.
	 * @param DateTimeInterface $date    Date used to resolve placeholders.
	 * @param string|null       $source  Optional source identifier used for {source}.
	 * @return string
	 */
	public function resolveFilter( string $pattern, DateTimeInterface $date, ?string $source = null ): string {
		return $this->resolve( $pattern, $date, $source );
	}

	/**
	 * Resolves date and source placeholders using a small supported token set.
	 *
	 * @param string            $pattern Pattern containing {date:...} or {source} placeholders.
	 * @param DateTimeInterface $date    Date used to resolve placeholders.
	 * @param string|null       $source  Optional source identifier used for {source}.
	 * @return string
	 */
	public function resolve( string $pattern, DateTimeInterface $date, ?string $source = null ): string {
		$this->assertValidPlaceholderSyntax( $pattern );

		$result = preg_replace_callback(
			'/\{([^{}]+)\}/',
			function ( array $matches ) use ( $date, $source ): string {
				$placeholder = $matches[1];

				if ( 'source' === $placeholder ) {
					return (string) ( $source ?? '' );
				}

				if ( ! str_starts_with( $placeholder, 'date:' ) ) {
					throw new InvalidArgumentException(
						'Unknown date pattern placeholder: {' . $placeholder . '}'
					);
				}

				$format = substr( $placeholder, 5 );
				if ( '' === $format ) {
					throw new InvalidArgumentException( 'Date pattern format cannot be empty.' );
				}

				return $date->format( $format );
			},
			$pattern
		);

		if ( null === $result ) {
			throw new InvalidArgumentException( 'Invalid date pattern.' );
		}

		return $result;
	}

	/**
	 * Ensures the pattern contains only a single, non-nested token layer.
	 */
	private function assertValidPlaceholderSyntax( string $pattern ): void {
		$depth = 0;
		$length = strlen( $pattern );

		for ( $index = 0; $index < $length; $index++ ) {
			$character = $pattern[ $index ];

			if ( '{' === $character ) {
				if ( $depth > 0 ) {
					throw new InvalidArgumentException( 'Pattern contains unmatched braces.' );
				}
				$depth++;
				continue;
			}

			if ( '}' === $character ) {
				if ( 0 === $depth ) {
					throw new InvalidArgumentException( 'Pattern contains unmatched braces.' );
				}
				$depth--;
			}
		}

		if ( 0 !== $depth ) {
			throw new InvalidArgumentException( 'Pattern contains unmatched braces.' );
		}
	}
}
