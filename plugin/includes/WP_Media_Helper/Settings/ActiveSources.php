<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Settings;

use WP_Media_Helper\Thumbnails\ThumbnailCache;

/**
 * The configured sources, read from the settings.
 */
class ActiveSources {

	private static function settings(): ExternalSourceSettings {
		return new ExternalSourceSettings(
			static fn(): mixed => get_option( ExternalSourceSettings::optionKey(), [] ),
			static function ( array $value ): void {},
			static fn(): ?string => AllowedBase::resolve(),
			static fn(): ?string => ThumbnailCache::directory()
		);
	}

	/**
	 * The sources that list files, in priority order, each with the directories it
	 * must not enter (see SourceOwnership). A source outside the allowed base
	 * directory is treated as disabled.
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

		return SourceOwnership::listing( $sources, static fn ( array $source ): bool => $settings->isSourceAllowed( $source ), ThumbnailCache::directory() );
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
