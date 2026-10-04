<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Settings;

use Closure;
use WP_Media_Helper\MediaSource\PathConfinement;

/**
 * Decides which source owns which files.
 *
 * Sources form an ordered list. A file is owned by the first active or excluded
 * source, in list order, whose root contains it, and only an active owner lists it.
 * Thumbnail cache directories are never listed by any source.
 *
 * The result is expressed as the directories each active source must not enter
 * (`exclusions`): the trees of earlier owners and the caches that lie inside its
 * root. Import, attach and the scanner all rely on that list, so a file has one
 * owner whatever the date or the path pattern.
 */
final class SourceOwnership {

	/**
	 * Returns the sources that list files, in order, each with its `exclusions`:
	 * canonical directories (possibly the root itself, when the source is entirely
	 * owned by something else) that it must not list.
	 *
	 * @param array<int, mixed>        $sources   Every configured source, in priority order.
	 * @param (Closure(array<string,mixed>): bool)|null $isAllowed Whether a source may be used at all (allowed base directory).
	 * @return array<int, array<string, mixed>>
	 */
	public static function listing( array $sources, ?Closure $isAllowed = null ): array {
		$caches = [];
		foreach ( $sources as $source ) {
			$cache = is_array( $source ) ? trim( (string) ( $source['thumbnail_cache'] ?? '' ) ) : '';
			$real  = '' === $cache ? null : AllowedBase::resolveDirectory( $cache );
			if ( null !== $real ) {
				$caches[] = $real;
			}
		}

		$owners = [];
		$result = [];
		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) || SourceState::DISABLED === SourceState::of( $source ) ) {
				continue;
			}
			if ( null !== $isAllowed && ! $isAllowed( $source ) ) {
				continue;
			}

			$root = realpath( (string) ( $source['root'] ?? '' ) );
			if ( false === $root || ! is_dir( $root ) ) {
				continue;
			}

			if ( SourceState::ACTIVE === SourceState::of( $source ) && ! empty( $source['id'] ) && ! empty( $source['name'] ) ) {
				$source['exclusions'] = self::exclusionsWithin( $root, array_merge( $owners, $caches ) );
				$result[]             = $source;
			}

			$owners[] = $root;
		}

		return $result;
	}

	/**
	 * The directories of $others that matter for a scan of $root.
	 *
	 * @param string[] $others Canonical directories.
	 * @return string[]
	 */
	private static function exclusionsWithin( string $root, array $others ): array {
		$exclusions = [];
		foreach ( $others as $other ) {
			if ( $other === $root || PathConfinement::isWithin( $other, $root ) ) {
				// The whole source is owned by something else.
				return [ $root ];
			}
			if ( PathConfinement::isWithin( $root, $other ) ) {
				$exclusions[] = $other;
			}
		}

		$exclusions = array_values( array_unique( $exclusions ) );
		sort( $exclusions );

		return $exclusions;
	}

	/**
	 * Whether a canonical path is inside one of the excluded directories.
	 *
	 * @param string[] $exclusions
	 */
	public static function isExcluded( string $realPath, array $exclusions ): bool {
		foreach ( $exclusions as $excluded ) {
			if ( $realPath === $excluded || PathConfinement::isWithin( $excluded, $realPath ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A short value that changes when the exclusions of a source change.
	 *
	 * @param array<string, mixed> $source
	 */
	public static function fingerprint( array $source ): string {
		$exclusions = is_array( $source['exclusions'] ?? null ) ? $source['exclusions'] : [];
		sort( $exclusions );

		return [] === $exclusions ? '' : md5( implode( "\n", $exclusions ) );
	}

	/**
	 * Active sources shadowed by earlier ones, by index in the configured list:
	 * their root is inside (or equal to) the root of an earlier active or excluded source.
	 *
	 * @param array<int, mixed> $sources
	 * @return array<int, string> Index => name of the source that owns the root.
	 */
	public static function shadowed( array $sources ): array {
		$owners  = [];
		$shadows = [];
		foreach ( $sources as $index => $source ) {
			if ( ! is_array( $source ) || SourceState::DISABLED === SourceState::of( $source ) ) {
				continue;
			}
			$root = realpath( trim( (string) ( $source['root'] ?? '' ) ) );
			if ( false === $root ) {
				continue;
			}
			foreach ( $owners as [ $ownerRoot, $ownerName ] ) {
				if ( $ownerRoot === $root || PathConfinement::isWithin( $ownerRoot, $root ) ) {
					$shadows[ $index ] = $ownerName;
					break;
				}
			}
			$owners[] = [ $root, (string) ( $source['name'] ?? '' ) ];
		}

		return $shadows;
	}
}
