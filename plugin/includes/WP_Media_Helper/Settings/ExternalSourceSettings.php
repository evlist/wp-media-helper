<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Settings;

use Closure;
use InvalidArgumentException;

class ExternalSourceSettings {

	private const OPTION_KEY = 'wp_media_helper_external_sources';

	/**
	 * @var Closure(): mixed
	 */
	private Closure $loader;

	/**
	 * @var Closure(array<mixed>): void
	 */
	private Closure $saver;

	/**
	 * @param Closure(): mixed $loader
	 * @param Closure(array<mixed>): void $saver
	 */
	public function __construct( Closure $loader, Closure $saver ) {
		$this->loader = $loader;
		$this->saver  = $saver;
	}

	/**
	 * Returns all configured external sources.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getAll(): array {
		$value = ( $this->loader )();

		if ( false === $value || null === $value || '' === $value ) {
			return [];
		}

		if ( ! is_array( $value ) ) {
			throw new InvalidArgumentException( __( 'External source configuration must be stored as an array.', 'wp-media-helper' ) );
		}

		return array_values( $value );
	}

	/**
	 * Saves a normalized collection of external sources.
	 *
	 * @param array<int, array<string, mixed>> $sources
	 * @return void
	 */
	public function saveAll( array $sources ): void {
		( $this->saver )( $this->normalizeSources( $sources ) );
	}

	/**
	 * Validates a source collection and returns field-level error messages.
	 *
	 * @param array<int, array<string, mixed>> $sources
	 * @return array<int, array<string, string>>
	 */
	public function validateSources( array $sources ): array {
		$errors = [];

		foreach ( $sources as $index => $source ) {
			if ( ! is_array( $source ) ) {
				$errors[ $index ] = [ 'source' => __( 'Invalid source entry.', 'wp-media-helper' ) ];
				continue;
			}

			$entryErrors = [];
			$name = trim( (string) ( $source['name'] ?? '' ) );
			$root = trim( (string) ( $source['root'] ?? '' ) );
			$path = trim( (string) ( $source['path_pattern'] ?? '' ) );

			if ( '' === $name ) {
				$entryErrors['name'] = __( 'Name is required.', 'wp-media-helper' );
			}

			if ( '' === $root ) {
				$entryErrors['root'] = __( 'Root directory is required.', 'wp-media-helper' );
			}

			if ( [] !== $entryErrors ) {
				$errors[ $index ] = $entryErrors;
			}
		}

		return $errors;
	}

	/**
	 * Validates and normalizes a collection of sources without persisting them.
	 *
	 * @param array<int, array<string, mixed>> $sources
	 * @return array<int, array<string, mixed>>
	 */
	public function normalizeSources( array $sources ): array {
		$normalized = [];

		foreach ( $sources as $index => $source ) {
			if ( ! is_array( $source ) ) {
				throw new InvalidArgumentException( __( 'Each external source must be an array.', 'wp-media-helper' ) );
			}

			$normalized[] = $this->normalizeSource( $source, $index );
		}

		return $normalized;
	}

	/**
	 * Returns the option key used for persistence.
	 */
	public static function optionKey(): string {
		return self::OPTION_KEY;
	}

	/**
	 * @param array<string, mixed> $source
	 * @param int                  $index
	 * @return array<string, mixed>
	 */
	private function normalizeSource( array $source, int $index ): array {
		$name = trim( (string) ( $source['name'] ?? '' ) );
		$root = trim( (string) ( $source['root'] ?? '' ) );
		$path = trim( (string) ( $source['path_pattern'] ?? '' ) );

		if ( '' === $name ) {
			throw new InvalidArgumentException(
				sprintf(
					/* translators: %d: position of the source in the settings form. */
					__( 'External source #%d must define a valid name.', 'wp-media-helper' ),
					$index + 1
				)
			);
		}

		if ( '' === $root ) {
			throw new InvalidArgumentException(
				sprintf(
					/* translators: %s: source name. */
					__( 'External source "%s" must define a root path.', 'wp-media-helper' ),
					$name
				)
			);
		}


		$id = strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $name ) ?? $name );
		$id = trim( (string) $id, '-' );

		if ( '' === $id ) {
			throw new InvalidArgumentException(
				sprintf(
					/* translators: %s: source name. */
					__( 'External source "%s" could not generate a valid identifier.', 'wp-media-helper' ),
					$name
				)
			);
		}

		return [
			'id' => $id,
			'name' => $name,
			'enabled' => filter_var( $source['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN ),
			'root' => $root,
			'path_pattern' => $path,
			'filter_pattern' => trim( (string) ( $source['filter_pattern'] ?? '' ) ),
			'thumbnail_cache' => trim( (string) ( $source['thumbnail_cache'] ?? '' ) ),
		];
	}
}
