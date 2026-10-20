<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Thumbnails;

/**
 * Which named sizes of an attachment have to be made (slice 023): the ones it does not have (no entry, or no file), and, on request, the
 * ones it has with other dimensions than the current definition of the size gives (the definition changed: another page format of a book,
 * a theme update). WordPress itself never compares them: an entry of the metadata is trusted.
 */
final class ThumbnailPlan {

	/**
	 * The dimensions a size would have for an original, or null when the size is not smaller than the original (nothing is made for it).
	 *
	 * @param array{width:int|string, height:int|string, crop:mixed} $definition
	 * @param callable|null $dimensions `image_resize_dimensions` (replaced in tests).
	 * @return array{width:int, height:int}|null
	 */
	public static function expected( int $width, int $height, array $definition, ?callable $dimensions = null ): ?array {
		if ( $width < 1 || $height < 1 ) {
			return null;
		}
		$dimensions ??= 'image_resize_dimensions';
		$result = $dimensions( $width, $height, (int) $definition['width'], (int) $definition['height'], $definition['crop'] );

		return is_array( $result ) ? [ 'width' => (int) $result[4], 'height' => (int) $result[5] ] : null;
	}

	/**
	 * @param array<string, mixed>                                      $metadata    The metadata of the attachment (`width`, `height`, `sizes`).
	 * @param array<string, array{width:int, height:int, crop:mixed}>   $definitions The registered sizes.
	 * @param string[]|null                                             $names       Only these sizes (null: all of them).
	 * @param callable                                                  $fileExists  `( string $name, array $entry ): bool`, whether the file of an entry is there.
	 * @param callable|null                                             $dimensions  `image_resize_dimensions` (replaced in tests).
	 * @return array{missing: string[], stale: string[]}
	 */
	public static function classify( array $metadata, array $definitions, ?array $names, callable $fileExists, ?callable $dimensions = null ): array {
		$missing = [];
		$stale   = [];
		$width   = (int) ( $metadata['width'] ?? 0 );
		$height  = (int) ( $metadata['height'] ?? 0 );

		foreach ( $definitions as $name => $definition ) {
			$name = (string) $name;
			if ( null !== $names && ! in_array( $name, $names, true ) ) {
				continue;
			}
			$expected = self::expected( $width, $height, $definition, $dimensions );
			if ( null === $expected ) {
				continue;
			}

			$entry = $metadata['sizes'][ $name ] ?? null;
			if ( ! is_array( $entry ) || ! $fileExists( $name, $entry ) ) {
				$missing[] = $name;
			} elseif ( (int) ( $entry['width'] ?? 0 ) !== $expected['width'] || (int) ( $entry['height'] ?? 0 ) !== $expected['height'] ) {
				$stale[] = $name;
			}
		}

		return [ 'missing' => $missing, 'stale' => $stale ];
	}

	/**
	 * A fingerprint of the definitions of the sizes (name, width, height, crop): it changes when a size is added, removed or redefined.
	 *
	 * @param array<string, array{width:int, height:int, crop:mixed}> $definitions
	 */
	public static function fingerprint( array $definitions ): string {
		$normalized = [];
		foreach ( $definitions as $name => $definition ) {
			$normalized[ (string) $name ] = [ (int) $definition['width'], (int) $definition['height'], $definition['crop'] ];
		}
		ksort( $normalized );

		return md5( (string) json_encode( $normalized ) );
	}
}
