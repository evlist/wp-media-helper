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

			$rootIsWritable = false;
			if ( '' === $root ) {
				$entryErrors['root'] = __( 'Root directory is required.', 'wp-media-helper' );
			} else {
				$rootError = $this->validateRootDirectory( $root );
				if ( null !== $rootError ) {
					$entryErrors['root'] = $rootError;
				} else {
					$rootIsWritable = is_writable( $root );
				}
			}

			$thumbnailCache = trim( (string) ( $source['thumbnail_cache'] ?? '' ) );
			if ( '' !== $thumbnailCache ) {
				$cacheError = $this->validateThumbnailCache( $thumbnailCache );
				if ( null !== $cacheError ) {
					$entryErrors['thumbnail_cache'] = $cacheError;
				}
			} elseif ( ! isset( $entryErrors['root'] ) && ! $rootIsWritable ) {
				$entryErrors['thumbnail_cache'] = __( 'A thumbnail cache directory is required because the root directory is read-only.', 'wp-media-helper' );
			}

			if ( '' !== $path ) {
				$pathError = $this->validatePatternSyntax( $path );
				if ( null === $pathError ) {
					$pathError = $this->validatePathEscape( $path );
				}
				if ( null !== $pathError ) {
					$entryErrors['path_pattern'] = $pathError;
				}
			}

			$filter = trim( (string) ( $source['filter_pattern'] ?? '' ) );
			if ( '' !== $filter ) {
				$filterError = $this->validatePatternSyntax( $filter );
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

		$thumbnailCache = trim( (string) ( $source['thumbnail_cache'] ?? '' ) );
		if ( '' !== $thumbnailCache ) {
			$cacheError = $this->validateThumbnailCache( $thumbnailCache );
			if ( null !== $cacheError ) {
				throw new InvalidArgumentException(
					sprintf(
						/* translators: 1: source name, 2: validation message. */
						__( 'External source "%1$s": %2$s', 'wp-media-helper' ),
						$name,
						$cacheError
					)
				);
			}
		} elseif ( ! is_writable( $root ) ) {
			throw new InvalidArgumentException(
				sprintf(
					/* translators: %s: source name. */
					__( 'External source "%s" is read-only and must define a thumbnail cache directory.', 'wp-media-helper' ),
					$name
				)
			);
		}

		$filter = trim( (string) ( $source['filter_pattern'] ?? '' ) );

		foreach ( [ 'path_pattern' => $path, 'filter_pattern' => $filter ] as $field => $value ) {
			if ( '' === $value ) {
				continue;
			}

			$patternError = $this->validatePatternSyntax( $value );
			if ( null === $patternError && 'path_pattern' === $field ) {
				$patternError = $this->validatePathEscape( $value );
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

	/**
	 * Checks that a root directory is an absolute, existing, readable directory.
	 */
	private function validateRootDirectory( string $root ): ?string {
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

		return null;
	}

	/**
	 * Checks that a thumbnail cache directory is writable, or can be created.
	 */
	private function validateThumbnailCache( string $cache ): ?string {
		if ( file_exists( $cache ) ) {
			if ( ! is_dir( $cache ) ) {
				return __( 'Thumbnail cache path is not a directory.', 'wp-media-helper' );
			}

			if ( ! is_writable( $cache ) ) {
				return __( 'Thumbnail cache directory is not writable.', 'wp-media-helper' );
			}

			return null;
		}

		if ( ! is_writable( dirname( $cache ) ) ) {
			return __( 'Thumbnail cache directory is not writable or creatable.', 'wp-media-helper' );
		}

		return null;
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

	/**
	 * Rejects path patterns that could resolve outside the configured root.
	 */
	private function validatePathEscape( string $pattern ): ?string {
		if ( str_starts_with( $pattern, '/' ) ) {
			return __( 'Path pattern must not be an absolute path; it would escape the configured source root.', 'wp-media-helper' );
		}

		$segments = explode( '/', str_replace( '\\', '/', $pattern ) );
		if ( in_array( '..', $segments, true ) ) {
			return __( 'Path pattern must not contain ".."; it would escape the configured source root.', 'wp-media-helper' );
		}

		return null;
	}
}
