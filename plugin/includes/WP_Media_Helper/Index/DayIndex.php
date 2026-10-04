<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Index;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use WP_Media_Helper\MediaSource\DatePatternResolver;
use WP_Media_Helper\MediaSource\EmbeddedDates;
use WP_Media_Helper\MediaSource\ImageDimensions;

/**
 * The files of a source for one day, answered from the index.
 *
 * The path pattern of a source is a hint: the directories it points to for the
 * requested day and the days around it are checked right away and briefly, so that
 * files just added to today's folder show up at once. Everything else is found by the
 * passes run in the background.
 */
class DayIndex {

	public const REASON_INCOMPLETE = 'index-incomplete';
	public const REASON_FORCED     = 'forced-refresh';

	private const HINT_SECONDS = 1.0;

	private IndexStore $store;
	private IndexManager $manager;
	private KeyMapper $keys;

	public function __construct( IndexStore $store, IndexManager $manager, KeyMapper $keys ) {
		$this->store   = $store;
		$this->manager = $manager;
		$this->keys    = $keys;
	}

	/**
	 * The index of the current site, scanned by WordPress cron.
	 */
	public static function forWordPress(): self {
		$uploads  = wp_upload_dir( null, false );
		$basedir  = is_array( $uploads ) && ! empty( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
		$store    = new WpdbIndexStore();
		$keys     = new KeyMapper( $basedir );
		$scanner  = new IndexScanner( $store, $keys, wp_timezone(), null, EmbeddedDates::imageReader(), ImageDimensions::reader() );
		$state    = new ScanState(
			static fn(): mixed => get_option( ScanState::OPTION, [] ),
			static function ( array $value ): void {
				update_option( ScanState::OPTION, $value, false );
			}
		);
		$manager = new IndexManager( $store, $scanner, $state, [ Cron::class, 'schedule' ] );

		return new self( $store, $manager, $keys );
	}

	/**
	 * Hides or shows files (canonical paths of files of an active source). Returns how many
	 * index rows changed.
	 *
	 * @param string[] $paths
	 */
	public function setHidden( array $paths, bool $hidden, int $userId ): int {
		return $this->store->setHidden( array_map( [ $this->keys, 'key' ], $paths ), $hidden, $userId, time() );
	}

	/**
	 * The files, among those given, that are hidden.
	 *
	 * @param string[] $paths
	 * @return string[]
	 */
	public function hiddenAmong( array $paths ): array {
		$byKey = [];
		foreach ( $paths as $path ) {
			$byKey[ $this->keys->key( $path ) ] = $path;
		}

		$hidden = [];
		foreach ( $this->store->hiddenKeys( array_keys( $byKey ) ) as $key ) {
			if ( isset( $byKey[ $key ] ) ) {
				$hidden[] = $byKey[ $key ];
			}
		}

		return $hidden;
	}

	public function manager(): IndexManager {
		return $this->manager;
	}

	/**
	 * @param array<string, mixed> $source
	 * @return array{files:string[], hidden:string[], dimensions:array<string, array{0:int, 1:int}>, refresh_required:bool, stale:bool, reason:string|null}
	 */
	public function forDay( array $source, DateTimeInterface $date, bool $force = false, bool $includeHidden = false ): array {
		$id = (string) ( $source['id'] ?? '' );
		$this->manager->prepare( $source );
		$indexed = $this->manager->isIndexed( $source );

		$directories = $this->hintDirectories( $source, $date, $indexed );
		if ( [] !== $directories ) {
			$this->manager->scanHints( $source, $directories, new ScanBudget( self::HINT_SECONDS ), $force );
		}
		if ( $force ) {
			$this->manager->requestPass( $source, false );
		}

		$files      = [];
		$hidden     = [];
		$dimensions = [];
		foreach ( $this->store->filesForDay( [ $id ], $date->format( 'Y-m-d' ), $includeHidden ) as $row ) {
			$path    = $this->keys->absolute( (string) $row['path'] );
			$files[] = $path;
			if ( ! empty( $row['hidden'] ) ) {
				$hidden[] = $path;
			}
			if ( ! empty( $row['width'] ) && ! empty( $row['height'] ) ) {
				$dimensions[ $path ] = [ (int) $row['width'], (int) $row['height'] ];
			}
		}

		return [
			'files'            => $files,
			'hidden'           => $hidden,
			'dimensions'       => $dimensions,
			'refresh_required' => ! $indexed,
			'stale'            => false,
			'reason'           => $indexed ? ( $force ? self::REASON_FORCED : null ) : self::REASON_INCOMPLETE,
		];
	}

	/**
	 * Directories to check first for a day: those the path pattern resolves for the
	 * day before, the day and the day after (a time zone can put a photo in the
	 * neighbour's folder). Without a path pattern, only the root, and only until the
	 * source has been indexed once.
	 *
	 * @param array<string, mixed> $source
	 * @return string[]
	 */
	private function hintDirectories( array $source, DateTimeInterface $date, bool $indexed ): array {
		$configuredRoot = rtrim( (string) ( $source['root'] ?? '' ), '/\\' );
		$root           = realpath( $configuredRoot );
		if ( '' === $configuredRoot || false === $root ) {
			return [];
		}

		$pattern = trim( (string) ( $source['path_pattern'] ?? '' ) );
		if ( '' === $pattern ) {
			return $indexed ? [] : [ $root ];
		}

		$resolver    = new DatePatternResolver();
		// The panel date is a calendar day: rebuild it without any time zone conversion.
		$immutable   = new DateTimeImmutable( $date->format( 'Y-m-d' ), new DateTimeZone( 'UTC' ) );
		$directories = [];
		foreach ( [ 0, -1, 1 ] as $offset ) {
			try {
				$directory = $resolver->resolvePath( $configuredRoot, $pattern, $immutable->modify( sprintf( '%+d day', $offset ) ), (string) ( $source['id'] ?? '' ) );
			} catch ( InvalidArgumentException $exception ) {
				continue;
			}

			// Work on the canonical root, so a root reached through a link is accepted.
			if ( str_starts_with( $directory, $configuredRoot . '/' ) ) {
				$directory = $root . substr( $directory, strlen( $configuredRoot ) );
			}
			$directories[] = rtrim( $directory, '/\\' );
		}

		return array_values( array_unique( $directories ) );
	}
}
