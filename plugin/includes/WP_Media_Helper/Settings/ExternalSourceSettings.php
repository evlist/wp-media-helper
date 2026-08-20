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
			throw new InvalidArgumentException( 'External source configuration must be stored as an array.' );
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
	 * Ensures there is always at least one blank source record for the UI.
	 *
	 * @param array<int, array<string, mixed>> $sources
	 * @return array<int, array<string, mixed>>
	 */
	public function ensureAtLeastOneSource( array $sources ): array {
		if ( [] !== $sources ) {
			return $sources;
		}

		return [[
			'id' => '',
			'name' => '',
			'enabled' => true,
			'root' => '',
			'path_pattern' => '',
			'filter_pattern' => '',
			'thumbnail_cache' => '',
		]];
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
				throw new InvalidArgumentException( 'Each external source must be an array.' );
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
			throw new InvalidArgumentException( sprintf( 'External source #%d must define a valid name.', $index + 1 ) );
		}

		if ( '' === $root ) {
			throw new InvalidArgumentException( sprintf( 'External source "%s" must define a root path.', $name ) );
		}

		if ( '' === $path ) {
			throw new InvalidArgumentException( sprintf( 'External source "%s" must define a path pattern.', $name ) );
		}

		$id = strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $name ) ?? $name );
		$id = trim( (string) $id, '-' );

		if ( '' === $id ) {
			throw new InvalidArgumentException( sprintf( 'External source "%s" could not generate a valid identifier.', $name ) );
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
