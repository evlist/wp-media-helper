<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Settings;

/**
 * The one-click source on the uploads directory.
 */
final class UploadsSource {

	public const ID   = 'uploads';
	public const NAME = 'Uploads';

	/**
	 * Whether a source other than a disabled one already has the uploads directory as root.
	 *
	 * @param array<int, mixed> $sources Configured sources.
	 */
	public static function isConfigured( array $sources, string $base ): bool {
		$realBase = realpath( $base );
		if ( false === $realBase ) {
			return false;
		}

		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) || SourceState::DISABLED === SourceState::of( $source ) ) {
				continue;
			}
			if ( realpath( trim( (string) ( $source['root'] ?? '' ) ) ) === $realBase ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The sources with the uploads source added last, so the narrower sources before it
	 * keep their files. Its name and identifier are made unique.
	 *
	 * @param array<int, array<string, mixed>> $sources
	 * @return array<int, array<string, mixed>>
	 */
	public static function append( array $sources, string $base, string $name = self::NAME ): array {
		$names = array_map( static fn ( $source ): string => is_array( $source ) ? strtolower( (string) ( $source['name'] ?? '' ) ) : '', $sources );
		$ids   = array_map( static fn ( $source ): string => is_array( $source ) ? (string) ( $source['id'] ?? '' ) : '', $sources );

		$candidate = $name;
		$id        = self::ID;
		for ( $suffix = 2; in_array( strtolower( $candidate ), $names, true ) || in_array( $id, $ids, true ); ++$suffix ) {
			$candidate = $name . ' ' . $suffix;
			$id        = self::ID . '-' . $suffix;
		}

		$sources[] = [
			'id'             => $id,
			'name'           => $candidate,
			'state'          => SourceState::ACTIVE,
			'root'           => rtrim( $base, '/\\' ),
			'path_pattern'   => '',
			'name_patterns' => [],
			'mtime_fallback' => true,
		];

		return $sources;
	}
}
