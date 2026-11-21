<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Geo\Places;

/**
 * The settings of the reverse geocoding (slice 052, phase B). **Off until a service is given**: no coordinate leaves the site by default.
 *
 * The public service of OpenStreetMap (`nominatim.openstreetmap.org`) is held to its usage policy whatever the settings say: one request at
 * a time and never more than 4 a minute (the job runs at regular intervals, which the policy counts as bulk use), results cached, an identifying
 * User-Agent. Another service (an instance of one's own, a provider) gets the rate of the setting.
 */
final class PlacesSettings {

	public const PUBLIC_HOST          = 'nominatim.openstreetmap.org';
	public const PUBLIC_PER_MINUTE    = 4;
	public const DEFAULT_PER_MINUTE   = 60;
	public const MAX_PER_MINUTE       = 600;
	public const MAX_ATTEMPTS         = 5;

	public string $url;
	public string $contact;
	public string $language;
	public int $perMinute;

	/**
	 * @param array<string, mixed> $stored The general settings, as stored.
	 */
	public function __construct( array $stored, string $siteLanguage = 'en' ) {
		$this->url      = self::cleanUrl( isset( $stored['places_url'] ) && is_string( $stored['places_url'] ) ? $stored['places_url'] : '' );
		$this->contact  = isset( $stored['places_contact'] ) && is_string( $stored['places_contact'] ) ? trim( $stored['places_contact'] ) : '';
		$language       = isset( $stored['places_language'] ) && is_string( $stored['places_language'] ) ? trim( $stored['places_language'] ) : '';
		$this->language = self::cleanLanguage( '' !== $language ? $language : $siteLanguage );
		$perMinute      = isset( $stored['places_rate'] ) && is_numeric( $stored['places_rate'] ) ? (int) $stored['places_rate'] : self::DEFAULT_PER_MINUTE;
		$this->perMinute = max( 1, min( self::MAX_PER_MINUTE, $perMinute ) );
	}

	public function enabled(): bool {
		return '' !== $this->url;
	}

	public function host(): string {
		return strtolower( (string) parse_url( $this->url, PHP_URL_HOST ) );
	}

	public function isPublicService(): bool {
		$host = $this->host();

		return self::PUBLIC_HOST === $host;
	}

	/**
	 * The requests a minute that are allowed: 4 at the most for the public service, the setting for any other.
	 */
	public function requestsPerMinute(): int {
		return $this->isPublicService() ? self::PUBLIC_PER_MINUTE : $this->perMinute;
	}

	/**
	 * The shortest time between two requests, in seconds.
	 */
	public function interval(): float {
		return 60.0 / $this->requestsPerMinute();
	}

	/**
	 * An https (or http, for an instance on a private network) address with a host, or an empty string.
	 */
	public static function cleanUrl( string $url ): string {
		$url   = trim( $url );
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( $parts['scheme'] ?? '', [ 'https', 'http' ], true ) ) {
			return '';
		}
		// The public service is only reached over https, whatever was typed.
		if ( self::PUBLIC_HOST === strtolower( (string) $parts['host'] ) && 'https' !== $parts['scheme'] ) {
			return '';
		}

		return $url;
	}

	/**
	 * A language tag for `accept-language`: `fr`, `fr-FR`, `pt-BR` (a locale such as `fr_FR` is turned into it).
	 */
	public static function cleanLanguage( string $language ): string {
		$language = str_replace( '_', '-', trim( $language ) );

		return 1 === preg_match( '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $language ) ? $language : 'en';
	}
}
