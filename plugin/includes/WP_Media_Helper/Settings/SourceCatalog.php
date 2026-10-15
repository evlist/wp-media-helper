<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Settings;

use InvalidArgumentException;

/**
 * Reads and changes the list of configured sources the way the settings page does, for scripts: the
 * same validation (root inside the allowed base, patterns, names, overlaps), the same normalization
 * and the same identifiers. Nothing is stored here: the caller saves the list it gets back.
 */
final class SourceCatalog {

	private ExternalSourceSettings $settings;
	private ?string $base;

	/**
	 * @param ?string $base The allowed base directory the roots are relative to (null: roots are absolute).
	 */
	public function __construct( ExternalSourceSettings $settings, ?string $base ) {
		$this->settings = $settings;
		$this->base     = $base;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function sources(): array {
		return $this->settings->getAll();
	}

	/**
	 * @param array<string, int> $attachments Attachments registered through each source, by source id.
	 * @return array<int, array{rank:int, id:string, name:string, root:string, state:string, path_pattern:string, name_patterns:array<int, string>, mtime_fallback:bool, attachments:int}>
	 */
	public function rows( array $attachments = [] ): array {
		$rows = [];
		foreach ( $this->sources() as $index => $source ) {
			$id     = (string) ( $source['id'] ?? '' );
			$rows[] = [
				'rank'           => $index + 1,
				'id'             => $id,
				'name'           => (string) ( $source['name'] ?? '' ),
				'root'           => AllowedBase::toRelative( $this->base, (string) ( $source['root'] ?? '' ) ),
				'state'          => SourceState::of( $source ),
				'path_pattern'   => (string) ( $source['path_pattern'] ?? '' ),
				'name_patterns'  => array_values( (array) ( $source['name_patterns'] ?? [] ) ),
				'mtime_fallback' => filter_var( $source['mtime_fallback'] ?? true, FILTER_VALIDATE_BOOLEAN ),
				'attachments'    => (int) ( $attachments[ $id ] ?? 0 ),
			];
		}

		return $rows;
	}

	/**
	 * The position of a source designated by its id, its root (relative to the base, or absolute) or its name.
	 *
	 * @param array<int, array<string, mixed>> $sources
	 * @throws InvalidArgumentException When nothing matches or several sources do.
	 */
	public function find( array $sources, string $reference ): int {
		$reference = trim( $reference );
		$absolute  = rtrim( AllowedBase::toAbsolute( $this->base, $reference ), '/\\' );

		$matchers = [
			static fn ( array $source ): bool => (string) ( $source['id'] ?? '' ) === $reference,
			static fn ( array $source ): bool => rtrim( (string) ( $source['root'] ?? '' ), '/\\' ) === $absolute,
			static fn ( array $source ): bool => 0 === strcasecmp( (string) ( $source['name'] ?? '' ), $reference ),
		];
		foreach ( $matchers as $matcher ) {
			$found = [];
			foreach ( $sources as $index => $source ) {
				if ( is_array( $source ) && $matcher( $source ) ) {
					$found[] = $index;
				}
			}
			if ( 1 === count( $found ) ) {
				return $found[0];
			}
			if ( count( $found ) > 1 ) {
				throw new InvalidArgumentException( sprintf( 'More than one source matches "%s": use its id.', $reference ) );
			}
		}

		throw new InvalidArgumentException( sprintf( 'No source matches "%s".', $reference ) );
	}

	/**
	 * Adds a source before another one (at the end by default). A source that already has this root is not
	 * added again.
	 *
	 * @param array<string, mixed> $fields `root` (relative to the base), and optionally `name`, `path_pattern`, `name_patterns`, `mtime_fallback`.
	 * @return array{status:string, id:string, rank:int, sources:array<int, array<string, mixed>>}
	 * @throws InvalidArgumentException When the settings would not accept the source.
	 */
	public function add( array $fields, ?string $before = null ): array {
		$sources = $this->sources();
		$root    = rtrim( AllowedBase::toAbsolute( $this->base, trim( (string) ( $fields['root'] ?? '' ) ) ), '/\\' );
		if ( '' === $root ) {
			throw new InvalidArgumentException( 'A root directory is required.' );
		}

		foreach ( $sources as $index => $source ) {
			if ( rtrim( (string) ( $source['root'] ?? '' ), '/\\' ) === $root ) {
				return [ 'status' => 'exists', 'id' => (string) ( $source['id'] ?? '' ), 'rank' => $index + 1, 'sources' => $sources ];
			}
		}

		$name = trim( (string) ( $fields['name'] ?? '' ) );
		$new  = [
			'name'           => '' === $name ? basename( $root ) : $name,
			'state'          => SourceState::ACTIVE,
			'root'           => $root,
			'path_pattern'   => trim( (string) ( $fields['path_pattern'] ?? '' ) ),
			'name_patterns'  => array_values( (array) ( $fields['name_patterns'] ?? [] ) ),
			'mtime_fallback' => filter_var( $fields['mtime_fallback'] ?? true, FILTER_VALIDATE_BOOLEAN ),
		];

		$position = null === $before ? count( $sources ) : $this->find( $sources, $before );
		array_splice( $sources, $position, 0, [ $new ] );
		$normalized = $this->settings->normalizeSources( $sources );

		return [ 'status' => 'added', 'id' => (string) $normalized[ $position ]['id'], 'rank' => $position + 1, 'sources' => $normalized ];
	}

	/**
	 * @return array{changed:bool, id:string, sources:array<int, array<string, mixed>>}
	 * @throws InvalidArgumentException
	 */
	public function withState( string $reference, string $state ): array {
		$sources = $this->sources();
		$index   = $this->find( $sources, $reference );
		$changed = SourceState::of( $sources[ $index ] ) !== $state;
		$sources[ $index ]['state'] = $state;
		unset( $sources[ $index ]['enabled'] );

		return [ 'changed' => $changed, 'id' => (string) ( $sources[ $index ]['id'] ?? '' ), 'sources' => $this->settings->normalizeSources( $sources ) ];
	}

	/**
	 * @param array<string, int> $attachments Attachments registered through each source, by source id.
	 * @return array{id:string, sources:array<int, array<string, mixed>>}
	 * @throws InvalidArgumentException When the source is not found or attachments are still attached to it.
	 */
	public function without( string $reference, array $attachments ): array {
		$sources = $this->sources();
		$index   = $this->find( $sources, $reference );
		$id      = (string) ( $sources[ $index ]['id'] ?? '' );
		if ( ( $attachments[ $id ] ?? 0 ) > 0 ) {
			throw new InvalidArgumentException( sprintf( '%d attachments are still registered through the source "%s": move them first (paths remap).', $attachments[ $id ], $id ) );
		}
		array_splice( $sources, $index, 1 );

		return [ 'id' => $id, 'sources' => $this->settings->normalizeSources( $sources ) ];
	}

	/**
	 * @param array<int, array<string, mixed>> $sources
	 */
	public function save( array $sources ): void {
		$this->settings->saveAll( $sources );
	}
}
