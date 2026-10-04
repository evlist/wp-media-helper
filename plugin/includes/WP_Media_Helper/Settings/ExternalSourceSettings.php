<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Settings;

use Closure;
use InvalidArgumentException;
use WP_Media_Helper\MediaSource\NamePattern;
use WP_Media_Helper\MediaSource\PathConfinement;

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
	 * @var (Closure(): ?string)|null
	 */
	private ?Closure $allowedBase;

	/**
	 * @var (Closure(): ?string)|null
	 */
	private ?Closure $cacheDirectory;

	/**
	 * @param Closure(): mixed $loader
	 * @param Closure(array<mixed>): void $saver
	 * @param (Closure(): ?string)|null $allowedBase Returns the directory every root must be under, or null for no restriction.
	 * @param (Closure(): ?string)|null $cacheDirectory Returns the site-wide thumbnail cache directory, which no root may be inside or equal to.
	 */
	public function __construct( Closure $loader, Closure $saver, ?Closure $allowedBase = null, ?Closure $cacheDirectory = null ) {
		$this->loader         = $loader;
		$this->saver          = $saver;
		$this->allowedBase    = $allowedBase;
		$this->cacheDirectory = $cacheDirectory;
	}

	/**
	 * Whether a source may be used: its root is inside the allowed base directory.
	 *
	 * @param array<string, mixed> $source
	 */
	public function isSourceAllowed( array $source ): bool {
		return $this->isRootAllowed( (string) ( $source['root'] ?? '' ) );
	}

	/**
	 * Whether a source root is inside the allowed base directory (always true
	 * when no base is configured).
	 */
	public function isRootAllowed( string $root ): bool {
		$base = null === $this->allowedBase ? null : ( $this->allowedBase )();

		return null === $base || AllowedBase::contains( $base, $root );
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
	public function validateSources( array $sources, bool $enforceAllowedBase = true ): array {
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
			} else {
				$rootError = $this->validateRootDirectory( $root, $enforceAllowedBase );
				if ( null !== $rootError ) {
					$entryErrors['root'] = $rootError;
				}
			}

			if ( '' !== $path ) {
				$pathError = $this->validatePatternSyntax( $path );
				if ( null !== $pathError ) {
					$entryErrors['path_pattern'] = $pathError;
				}
			}

			$filter = trim( (string) ( $source['filter_pattern'] ?? '' ) );
			if ( '' !== $filter ) {
				$filterError = $this->validatePatternSyntax( $filter ) ?? $this->validateNamePattern( $filter );
				if ( null !== $filterError ) {
					$entryErrors['filter_pattern'] = $filterError;
				}
			}

			if ( [] !== $entryErrors ) {
				$errors[ $index ] = $entryErrors;
			}
		}

		foreach ( $this->findDuplicateNameIndexes( $sources ) as $index ) {
			$errors[ $index ]['name'] = __( 'This name is already used by another source.', 'wp-media-helper' );
		}

		foreach ( $this->findOverlapErrors( $sources ) as $index => $fieldErrors ) {
			foreach ( $fieldErrors as $field => $message ) {
				$errors[ $index ][ $field ] = $errors[ $index ][ $field ] ?? $message;
			}
		}

		return $errors;
	}

	/**
	 * Roots may be nested, but two sources cannot share a root, and no root may be
	 * inside or equal to the thumbnail cache (the cache itself may lie inside a root:
	 * ownership keeps it out of every listing).
	 *
	 * @param array<int, mixed> $sources
	 * @return array<int, array<string, string>>
	 */
	private function findOverlapErrors( array $sources ): array {
		$cache = null === $this->cacheDirectory ? null : ( $this->cacheDirectory )();
		$cache = null === $cache ? null : AllowedBase::resolveDirectory( $cache );

		$errors = [];
		$seen   = [];
		foreach ( $sources as $index => $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}
			$root = realpath( trim( (string) ( $source['root'] ?? '' ) ) );
			if ( false === $root || ! is_dir( $root ) ) {
				continue;
			}

			if ( isset( $seen[ $root ] ) ) {
				$errors[ $index ]['root'] = __( 'This root directory is already used by another source.', 'wp-media-helper' );
			}
			$seen[ $root ] = true;

			if ( null !== $cache && ( $root === $cache || PathConfinement::isWithin( $cache, $root ) ) ) {
				$errors[ $index ]['root'] = __( 'Root directory cannot be, or be inside, the thumbnail cache directory.', 'wp-media-helper' );
			}
		}

		return $errors;
	}

	/**
	 * Returns the indexes of sources whose normalized name collides with another source.
	 *
	 * @param array<int, array<string, mixed>> $sources
	 * @return int[]
	 */
	private function findDuplicateNameIndexes( array $sources ): array {
		$seen = [];
		$duplicates = [];

		foreach ( $sources as $index => $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}

			$name = strtolower( trim( (string) ( $source['name'] ?? '' ) ) );
			if ( '' === $name ) {
				continue;
			}

			if ( isset( $seen[ $name ] ) ) {
				$duplicates[] = $seen[ $name ][0];
				$duplicates[] = $index;
			}

			$seen[ $name ][] = $index;
		}

		return array_unique( $duplicates );
	}

	/**
	 * Validates and normalizes a collection of sources without persisting them.
	 *
	 * @param array<int, array<string, mixed>> $sources
	 * @return array<int, array<string, mixed>>
	 */
	public function normalizeSources( array $sources ): array {
		$normalized = [];

		if ( [] !== $this->findDuplicateNameIndexes( $sources ) ) {
			throw new InvalidArgumentException( __( 'Source names must be unique.', 'wp-media-helper' ) );
		}

		foreach ( $this->findOverlapErrors( $sources ) as $fieldErrors ) {
			throw new InvalidArgumentException( (string) reset( $fieldErrors ) );
		}

		$usedIds = [];
		foreach ( $sources as $index => $source ) {
			if ( ! is_array( $source ) ) {
				throw new InvalidArgumentException( __( 'Each external source must be an array.', 'wp-media-helper' ) );
			}

			$normalized[] = $this->normalizeSource( $source, $index, $usedIds );
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
	 * @param array<string, bool>  $usedIds
	 * @return array<string, mixed>
	 */
	private function normalizeSource( array $source, int $index, array &$usedIds ): array {
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

		$rootError = $this->validateRootDirectory( $root );
		if ( null !== $rootError ) {
			throw new InvalidArgumentException(
				sprintf(
					/* translators: 1: source name, 2: validation message. */
					__( 'External source "%1$s": %2$s', 'wp-media-helper' ),
					$name,
					$rootError
				)
			);
		}

		$filter = trim( (string) ( $source['filter_pattern'] ?? '' ) );

		foreach ( [ 'path_pattern' => $path, 'filter_pattern' => $filter ] as $field => $value ) {
			if ( '' === $value ) {
				continue;
			}

			$patternError = $this->validatePatternSyntax( $value );
			if ( null === $patternError && 'filter_pattern' === $field ) {
				$patternError = $this->validateNamePattern( $value );
			}
			if ( null !== $patternError ) {
				throw new InvalidArgumentException(
					sprintf(
						/* translators: 1: source name, 2: validation message. */
						__( 'External source "%1$s": %2$s', 'wp-media-helper' ),
						$name,
						$patternError
					)
				);
			}
		}


		$id = trim( (string) ( $source['id'] ?? '' ) );
		if ( '' === $id ) {
			$id = $this->sourceIdFromName( $name );
		}

		$baseId = $id;
		$suffix = 2;
		while ( isset( $usedIds[ $id ] ) ) {
			$id = $baseId . '-' . $suffix;
			++$suffix;
		}
		$usedIds[ $id ] = true;

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
			'state' => SourceState::of( $source ),
			'root' => $root,
			'path_pattern' => $path,
			'filter_pattern' => trim( (string) ( $source['filter_pattern'] ?? '' ) ),
			'mtime_fallback' => filter_var( $source['mtime_fallback'] ?? true, FILTER_VALIDATE_BOOLEAN ),
		];
	}

	private function sourceIdFromName( string $name ): string {
		$id = function_exists( 'sanitize_title' ) ? sanitize_title( $name ) : preg_replace( '/[^a-z0-9]+/i', '-', $name );

		return strtolower( trim( (string) $id, '-' ) );
	}

	/**
	 * Checks that a root directory is an absolute, existing, readable directory.
	 */
	private function validateRootDirectory( string $root, bool $enforceAllowedBase = true ): ?string {
		if ( ! str_starts_with( $root, '/' ) ) {
			return __( 'Root directory must be an absolute path.', 'wp-media-helper' );
		}

		if ( ! file_exists( $root ) ) {
			return __( 'Root directory does not exist.', 'wp-media-helper' );
		}

		if ( ! is_dir( $root ) ) {
			return __( 'Root directory is not a directory.', 'wp-media-helper' );
		}

		if ( ! is_readable( $root ) ) {
			return __( 'Root directory is not readable.', 'wp-media-helper' );
		}

		if ( $enforceAllowedBase && ! $this->isRootAllowed( $root ) ) {
			$base = null === $this->allowedBase ? '' : (string) ( $this->allowedBase )();

			return sprintf(
				/* translators: %s: allowed base directory path. */
				__( 'Root directory must be a sub-directory of %s.', 'wp-media-helper' ),
				$base
			);
		}

		return null;
	}

	/**
	 * Checks that a name date pattern says where the year, the month and the day are.
	 */
	private function validateNamePattern( string $pattern ): ?string {
		if ( null !== NamePattern::fromLegacyFilter( $pattern ) ) {
			return null;
		}

		return __( 'The name date pattern must contain the year, the month and the day, each once, with the letters Y, m and d (and H, i, s for a time), for example {date:Ymd}.', 'wp-media-helper' );
	}

	/**
	 * Checks that a path or filter pattern only uses supported, well-formed placeholders.
	 */
	private function validatePatternSyntax( string $pattern ): ?string {
		if ( substr_count( $pattern, '{' ) !== substr_count( $pattern, '}' ) ) {
			return __( 'Pattern contains unmatched braces.', 'wp-media-helper' );
		}

		$stripped = preg_replace( '/\{[^{}]*\}/', '', $pattern );
		if ( null !== $stripped && ( str_contains( $stripped, '{' ) || str_contains( $stripped, '}' ) ) ) {
			return __( 'Pattern contains unmatched braces.', 'wp-media-helper' );
		}

		preg_match_all( '/\{([^{}]*)\}/', $pattern, $matches );

		foreach ( $matches[1] as $token ) {
			if ( '' === $token ) {
				return __( 'Pattern contains an empty placeholder.', 'wp-media-helper' );
			}

			if ( ! str_starts_with( $token, 'date:' ) ) {
				return sprintf(
					/* translators: %s: unsupported placeholder name. */
					__( 'Unknown placeholder: {%s}', 'wp-media-helper' ),
					$token
				);
			}

			if ( '' === substr( $token, 5 ) ) {
				return __( 'Date placeholder requires a format, for example {date:Ymd}.', 'wp-media-helper' );
			}
		}

		return null;
	}
}
