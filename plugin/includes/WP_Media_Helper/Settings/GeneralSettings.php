<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Settings;

use Closure;
use WP_Media_Helper\Geo\Places\PlacesSettings;
use WP_Media_Helper\MediaSource\FileTypes;

/**
 * Plugin-wide settings that are not tied to a single external source.
 */
class GeneralSettings {

	private const OPTION_KEY = 'wp_media_helper_general_settings';

	public const DEFAULT_MAX_ENTRIES = 100;
	public const MIN_MAX_ENTRIES     = 1;
	public const MAX_MAX_ENTRIES     = 500;

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

	public static function optionKey(): string {
		return self::OPTION_KEY;
	}

	/**
	 * Maximum number of media entries returned per page, and accepted per bulk
	 * request. Falls back to the default when nothing valid is stored.
	 */
	public function getMaxEntries(): int {
		$stored = ( $this->loader )();
		$value  = is_array( $stored ) ? ( $stored['max_entries'] ?? null ) : null;

		if ( null === $value || null !== self::validateMaxEntries( $value ) ) {
			return self::DEFAULT_MAX_ENTRIES;
		}

		return (int) $value;
	}

	/**
	 * The file types added to those WordPress accepts: extension => MIME type. The dangerous ones
	 * are dropped even if they were stored.
	 *
	 * @return array<string, string>
	 */
	public function getAdditionalTypes(): array {
		return FileTypes::parseAdditionalTypes( $this->additionalTypesText() )['types'];
	}

	/**
	 * The "extra types" setting as it was written, for the settings form.
	 */
	public function additionalTypesText(): string {
		$stored = ( $this->loader )();
		$value  = is_array( $stored ) ? ( $stored['additional_types'] ?? '' ) : '';

		return is_string( $value ) ? $value : '';
	}

	/**
	 * The templates of the blocks inserted for some file types, as they were written (one line per extension), for the settings form.
	 */
	public function insertHandlersText(): string {
		return InsertHandlers::textOf( ( $this->loader )() );
	}

	/**
	 * A setting of the reverse geocoding (slice 052), as it was written: `places_url` (empty: off), `places_contact`, `places_language`, `places_rate`.
	 */
	public function placesValue( string $key ): string {
		$stored = ( $this->loader )();
		$value  = is_array( $stored ) ? ( $stored[ $key ] ?? '' ) : '';

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Extensions that are not listed, or the default list when nothing was saved.
	 *
	 * @return string[]
	 */
	public function getIgnoredExtensions(): array {
		return FileTypes::parseExtensions( $this->ignoredExtensionsText() );
	}

	public function ignoredExtensionsText(): string {
		$stored = ( $this->loader )();
		$value  = is_array( $stored ) ? ( $stored['ignored_extensions'] ?? null ) : null;

		return is_string( $value ) ? $value : implode( ' ', FileTypes::DEFAULT_IGNORED );
	}

	/**
	 * Returns an error message, or null when the value is an integer within bounds.
	 */
	public static function validateMaxEntries( mixed $value ): ?string {
		$isInteger = is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^\s*[0-9]+\s*$/', $value ) );
		if ( ! $isInteger ) {
			return __( 'The maximum number of entries must be a whole number.', 'wp-media-helper' );
		}

		$number = (int) $value;
		if ( $number < self::MIN_MAX_ENTRIES || $number > self::MAX_MAX_ENTRIES ) {
			return sprintf(
				/* translators: 1: minimum value, 2: maximum value. */
				__( 'The maximum number of entries must be between %1$d and %2$d.', 'wp-media-helper' ),
				self::MIN_MAX_ENTRIES,
				self::MAX_MAX_ENTRIES
			);
		}

		return null;
	}

	/**
	 * Persists the settings; throws when a value is invalid.
	 *
	 * @param array<string, mixed> $values
	 * @throws \InvalidArgumentException
	 */
	public function save( array $values ): void {
		$error = self::validateMaxEntries( $values['max_entries'] ?? self::DEFAULT_MAX_ENTRIES );
		if ( null !== $error ) {
			throw new \InvalidArgumentException( $error );
		}

		$additional = array_key_exists( 'additional_types', $values ) ? (string) $values['additional_types'] : $this->additionalTypesText();
		$parsed     = FileTypes::parseAdditionalTypes( $additional );
		if ( [] !== $parsed['errors'] ) {
			throw new \InvalidArgumentException( implode( ' ', $parsed['errors'] ) );
		}
		$handlersText = array_key_exists( 'insert_handlers', $values ) ? (string) $values['insert_handlers'] : $this->insertHandlersText();
		$handlers     = InsertHandlers::parse( $handlersText );
		if ( [] !== $handlers['errors'] ) {
			throw new \InvalidArgumentException( implode( ' ', $handlers['errors'] ) );
		}
		$places = [];
		foreach ( [ 'places_url', 'places_contact', 'places_language', 'places_rate' ] as $key ) {
			$places[ $key ] = trim( array_key_exists( $key, $values ) ? (string) $values[ $key ] : $this->placesValue( $key ) );
		}
		if ( '' !== $places['places_url'] && '' === PlacesSettings::cleanUrl( $places['places_url'] ) ) {
			throw new \InvalidArgumentException( __( 'The address of the geocoding service must be an https address (http for a service on your own network); the public service of OpenStreetMap only over https.', 'wp-media-helper' ) );
		}
		if ( '' !== $places['places_rate'] && ( 1 !== preg_match( '/^\d+$/', $places['places_rate'] ) || (int) $places['places_rate'] < 1 || (int) $places['places_rate'] > PlacesSettings::MAX_PER_MINUTE ) ) {
			throw new \InvalidArgumentException( sprintf( /* translators: %d: maximum. */ __( 'The requests a minute must be a whole number between 1 and %d.', 'wp-media-helper' ), PlacesSettings::MAX_PER_MINUTE ) );
		}
		if ( '' !== $places['places_language'] && PlacesSettings::cleanLanguage( $places['places_language'] ) !== str_replace( '_', '-', $places['places_language'] ) ) {
			throw new \InvalidArgumentException( __( 'The language of the places must be a language tag such as fr, en or pt-BR.', 'wp-media-helper' ) );
		}
		$ignored = array_key_exists( 'ignored_extensions', $values ) ? (string) $values['ignored_extensions'] : $this->ignoredExtensionsText();

		( $this->saver )(
			[
				'max_entries'        => (int) ( $values['max_entries'] ?? self::DEFAULT_MAX_ENTRIES ),
				'additional_types'   => $additional,
				'insert_handlers'    => $handlersText,
			] + $places + [
				'ignored_extensions' => implode( ' ', FileTypes::parseExtensions( $ignored ) ),
			]
		);
	}
}
