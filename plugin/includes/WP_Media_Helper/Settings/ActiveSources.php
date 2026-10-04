<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Settings;

/**
 * The configured sources, read from the settings.
 */
class ActiveSources {

	private static function settings(): ExternalSourceSettings {
		return new ExternalSourceSettings(
			static fn(): mixed => get_option( ExternalSourceSettings::optionKey(), [] ),
			static function ( array $value ): void {},
			static fn(): ?string => AllowedBase::resolve()
		);
	}

	/**
	 * Enabled sources whose root and thumbnail cache are inside the allowed base
	 * directory. A source outside it is treated as disabled.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$settings = self::settings();

		try {
			$sources = $settings->getAll();
		} catch ( \InvalidArgumentException $exception ) {
			return [];
		}

		return array_values( array_filter( $sources, static function ( $source ) use ( $settings ): bool {
			return is_array( $source )
				&& ! empty( $source['id'] )
				&& ! empty( $source['name'] )
				&& ! empty( $source['root'] )
				&& filter_var( $source['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN )
				&& $settings->isSourceAllowed( $source );
		} ) );
	}

	/**
	 * Identifiers of every configured source, enabled or not.
	 *
	 * @return string[]
	 */
	public static function configuredIds(): array {
		try {
			$sources = self::settings()->getAll();
		} catch ( \InvalidArgumentException $exception ) {
			return [];
		}

		return array_values( array_filter( array_map( static fn ( $source ): string => is_array( $source ) ? (string) ( $source['id'] ?? '' ) : '', $sources ) ) );
	}
}
