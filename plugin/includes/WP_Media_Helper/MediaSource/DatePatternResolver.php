<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\MediaSource;

use DateTimeInterface;
use InvalidArgumentException;

class DatePatternResolver {

	/**
	 * Resolves date placeholders using PHP date format characters.
	 *
	 * @param string             $pattern Pattern containing {date:...} placeholders.
	 * @param DateTimeInterface  $date    Date used to resolve placeholders.
	 * @return string
	 */
	public function resolve( string $pattern, DateTimeInterface $date ): string {
		$result = preg_replace_callback(
			'/\{([^{}]+)\}/',
			static function ( array $matches ) use ( $date ): string {
				$placeholder = $matches[1];

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
}
