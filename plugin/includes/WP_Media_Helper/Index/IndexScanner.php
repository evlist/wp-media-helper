<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Index;

use DateTimeZone;
use WP_Media_Helper\Admin\MediaPanelState;
use WP_Media_Helper\MediaSource\FileDates;
use WP_Media_Helper\MediaSource\PathConfinement;
use WP_Media_Helper\Settings\SourceOwnership;

/**
 * Walks the tree of a source and keeps the index up to date.
 *
 * The walk is incremental: a directory whose modification time has not changed is
 * not read, its sub-directories are taken from the index and checked in turn, so
 * a run with no change costs about one `stat` per directory. A directory time is
 * only a hint (some file systems do not update it, and an edit in place does not
 * change it), so a full run re-reads every directory.
 *
 * A run works through a queue of directories kept in the index, within a budget, so
 * a first scan of a large tree is split in resumable runs. Symbolic links are
 * ignored, nothing outside the source root is entered, and files whose name starts
 * with a dot are skipped. Directories owned by another source are skipped, not
 * filtered afterwards, so a broad source never walks the tree of a narrower one.
 */
class IndexScanner {

	private const QUEUE_BATCH = 25;

	/**
	 * A directory modified less than this many seconds before it is read is stored
	 * without a time, so the next run reads it again: a file added in the same second
	 * would otherwise be missed.
	 */
	private const HOT_SECONDS = 2;

	private const IGNORED_NAMES = [ 'Thumbs.db', 'desktop.ini' ];

	private IndexStore $store;
	private KeyMapper $keys;
	private DateTimeZone $timezone;

	/**
	 * @var callable|null
	 */
	private $clock;

	public function __construct( IndexStore $store, KeyMapper $keys, DateTimeZone $timezone, ?callable $clock = null ) {
		$this->store    = $store;
		$this->keys     = $keys;
		$this->timezone = $timezone;
		$this->clock    = $clock;
	}

	/**
	 * Runs one pass over a source until its queue is empty or the budget is used up.
	 *
	 * @param array<string, mixed> $source           The source: `id`, `root`, `filter_pattern`, `mtime_fallback`, and `exclusions` (canonical directories it must not enter).
	 * @param int                  $run              Identifies the pass; a pass that did not finish is resumed with the same value.
	 * @param bool                 $full             Re-read every directory, and recompute every date.
	 * @param string[]|null        $startDirectories Canonical directories to start from instead of the root (hints).
	 */
	public function scan( array $source, int $run, ScanBudget $budget, bool $full = false, ?array $startDirectories = null ): ScanResult {
		$sourceId = (string) ( $source['id'] ?? '' );
		$root     = realpath( (string) ( $source['root'] ?? '' ) );
		if ( '' === $sourceId || false === $root || ! is_dir( $root ) ) {
			return new ScanResult( true, 0 );
		}

		$exclusions = [];
		foreach ( (array) ( $source['exclusions'] ?? [] ) as $excluded ) {
			$exclusions[] = rtrim( (string) $excluded, '/\\' );
		}

		$now    = $this->now();
		$rootId = $this->store->saveDirectory( $sourceId, $this->keys->key( $root ), 0, $now );

		if ( null === $startDirectories ) {
			$this->store->queueDirectory( $rootId, $run );
		} else {
			foreach ( $startDirectories as $directory ) {
				$this->queueChain( $sourceId, $root, $rootId, $directory, $run, $now, $exclusions );
			}
		}

		$context = [
			'source_id' => $sourceId,
			'root'      => $root,
			'run'       => $run,
			'full'      => $full,
			'exclusions' => $exclusions,
			'patterns'  => FileDates::patterns( $source ),
			'fallback'  => FileDates::usesMtimeFallback( $source ),
			'stats'     => [ 'read' => 0, 'unchanged' => 0, 'added' => 0, 'updated' => 0, 'missing' => 0 ],
		];

		while ( ! $budget->exhausted() ) {
			$batch = $this->store->nextQueued( $sourceId, $run, self::QUEUE_BATCH );
			if ( [] === $batch ) {
				break;
			}

			foreach ( $batch as $directory ) {
				if ( $budget->exhausted() ) {
					break 2;
				}
				$this->processDirectory( $directory, $context );
				$budget->consumeDirectory();
			}
		}

		$pending = $this->store->countPending( $sourceId, $run );
		$stats   = $context['stats'];

		return new ScanResult( 0 === $pending, $pending, $stats['read'], $stats['unchanged'], $stats['added'], $stats['updated'], $stats['missing'] );
	}

	/**
	 * Queues a directory below the root, creating the rows of the directories above it.
	 * The directory must be canonical: a path with `..` or a link in it is refused,
	 * and so is one inside an excluded directory.
	 *
	 * @param string[] $exclusions
	 */
	private function queueChain( string $sourceId, string $root, int $rootId, string $directory, int $run, int $now, array $exclusions ): void {
		$directory = rtrim( $directory, '/\\' );
		$real      = realpath( $directory );
		if ( false === $real || $real !== $directory || ! is_dir( $real ) || ! $this->isInsideRoot( $real, $root ) || SourceOwnership::isExcluded( $real, $exclusions ) ) {
			return;
		}

		if ( $real === $root ) {
			$this->store->queueDirectory( $rootId, $run );

			return;
		}

		$parentId   = $rootId;
		$cumulative = $root;
		foreach ( explode( '/', substr( $real, strlen( $root ) + 1 ) ) as $segment ) {
			$cumulative .= '/' . $segment;
			$parentId    = $this->store->saveDirectory( $sourceId, $this->keys->key( $cumulative ), $parentId, $now );
		}
		$this->store->queueDirectory( $parentId, $run );
	}

	/**
	 * @param array<string, mixed> $directory A directory row.
	 * @param array<string, mixed> $context
	 */
	private function processDirectory( array $directory, array &$context ): void {
		$now      = $this->now();
		$run      = (int) $context['run'];
		$id       = (int) $directory['id'];
		$absolute = $this->keys->absolute( (string) $directory['path'] );

		if ( is_link( $absolute ) || ! is_dir( $absolute ) || ! $this->isInsideRoot( $absolute, (string) $context['root'] ) || SourceOwnership::isExcluded( $absolute, $context['exclusions'] ) ) {
			$this->markSubtreeMissing( $directory, $now );
			$this->store->markScanned( $id, $run, null, $now );

			return;
		}

		clearstatcache( true, $absolute );
		$mtime = filemtime( $absolute );
		if ( false === $mtime ) {
			$this->store->markScanned( $id, $run, null, $now );

			return;
		}

		$unchanged = ! $context['full'] && null !== $directory['mtime'] && (int) $directory['mtime'] === $mtime && (int) $directory['scanned_run'] > 0;
		if ( $unchanged ) {
			$this->store->queueChildDirectories( $id, $run );
			$this->store->markScanned( $id, $run, $mtime, $now );
			++$context['stats']['unchanged'];

			return;
		}

		$entries = @scandir( $absolute ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $entries ) {
			$this->store->markScanned( $id, $run, null, $now );

			return;
		}

		$subdirectories = [];
		$files          = [];
		foreach ( $entries as $name ) {
			if ( '.' === $name[0] || in_array( $name, self::IGNORED_NAMES, true ) ) {
				continue;
			}
			$path = $absolute . '/' . $name;
			if ( is_link( $path ) ) {
				continue;
			}
			if ( is_dir( $path ) ) {
				// Trees owned by another source, and the thumbnail caches, are not entered.
				if ( ! SourceOwnership::isExcluded( $path, $context['exclusions'] ) ) {
					$subdirectories[ $name ] = $path;
				}
			} elseif ( is_file( $path ) ) {
				$stat = @stat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( false !== $stat ) {
					$files[ $name ] = [ 'size' => (int) $stat['size'], 'mtime' => (int) $stat['mtime'], 'path' => $path ];
				}
			}
		}

		$this->syncDirectories( $directory, $subdirectories, $context, $now );
		$this->syncFiles( $directory, $files, $context, $now );

		// A directory changed a moment ago may still change within the same second.
		$this->store->markScanned( $id, $run, $now - $mtime < self::HOT_SECONDS ? null : $mtime, $now );
		++$context['stats']['read'];
	}

	/**
	 * @param array<string, mixed>  $directory
	 * @param array<string, string> $subdirectories Name => absolute path.
	 * @param array<string, mixed>  $context
	 */
	private function syncDirectories( array $directory, array $subdirectories, array &$context, int $now ): void {
		$existing = [];
		foreach ( $this->store->childDirectories( (int) $directory['id'] ) as $child ) {
			$existing[ basename( (string) $child['path'] ) ] = $child;
		}

		foreach ( $subdirectories as $name => $path ) {
			$childId = $this->store->saveDirectory( (string) $context['source_id'], $this->keys->key( $path ), (int) $directory['id'], $now );
			$this->store->queueDirectory( $childId, (int) $context['run'] );
		}

		foreach ( $existing as $name => $child ) {
			if ( ! isset( $subdirectories[ $name ] ) && null === $child['missing_since'] ) {
				$this->markSubtreeMissing( $child, $now );
			}
		}
	}

	/**
	 * @param array<string, mixed>                $directory
	 * @param array<string, array<string, mixed>> $files Name => size, mtime, path.
	 * @param array<string, mixed>                $context
	 */
	private function syncFiles( array $directory, array $files, array &$context, int $now ): void {
		$existing = $this->store->filesInDirectory( (int) $directory['id'] );
		$new      = [];

		foreach ( $files as $name => $file ) {
			$dates = FileDates::effective( (string) $name, $file['mtime'], $context['patterns'], (bool) $context['fallback'], $this->timezone, $now );

			if ( ! isset( $existing[ $name ] ) ) {
				$extension = strtolower( pathinfo( (string) $name, PATHINFO_EXTENSION ) );
				$new[]     = [
					'key'                 => $this->keys->key( $file['path'] ),
					'name'                => (string) $name,
					'ext'                 => substr( $extension, 0, 20 ),
					'kind'                => MediaPanelState::resolveMediaType( (string) $name ),
					'size'                => $file['size'],
					'mtime'               => $file['mtime'],
					'name_date'           => $dates['name_date'],
					'name_date_precision' => $dates['precision'],
					'effective_date'      => $dates['local'],
					'effective_day'       => $dates['day'],
					'date_source'         => $dates['source'],
				];
				continue;
			}

			$row    = $existing[ $name ];
			$fields = [
				'size'                => $file['size'],
				'mtime'               => $file['mtime'],
				'name_date'           => $dates['name_date'],
				'name_date_precision' => $dates['precision'],
				'effective_date'      => $dates['local'],
				'effective_day'       => $dates['day'],
				'date_source'         => $dates['source'],
				'missing_since'       => null,
			];
			if ( $this->differs( $row, $fields ) ) {
				$this->store->updateFile( (int) $row['id'], $fields, $now );
				++$context['stats']['updated'];
			}
		}

		if ( [] !== $new ) {
			$this->store->insertFiles( (string) $context['source_id'], (int) $directory['id'], $new, $now );
			$context['stats']['added'] += count( $new );
		}

		$gone = [];
		foreach ( $existing as $name => $row ) {
			if ( ! isset( $files[ $name ] ) && null === $row['missing_since'] ) {
				$gone[] = (int) $row['id'];
			}
		}
		if ( [] !== $gone ) {
			$this->store->markFilesMissing( $gone, $now );
			$context['stats']['missing'] += count( $gone );
		}
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, mixed> $fields
	 */
	private function differs( array $row, array $fields ): bool {
		foreach ( $fields as $column => $value ) {
			$current = $row[ $column ] ?? null;
			if ( null === $value || null === $current ? $value !== $current : (string) $value !== (string) $current ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Marks a directory, its files and everything below it as missing.
	 *
	 * @param array<string, mixed> $directory
	 */
	private function markSubtreeMissing( array $directory, int $now ): void {
		$stack = [ $directory ];
		while ( [] !== $stack ) {
			$current = array_pop( $stack );
			$id      = (int) $current['id'];

			$this->store->markDirectoryMissing( $id, $now );

			$files = [];
			foreach ( $this->store->filesInDirectory( $id ) as $file ) {
				if ( null === $file['missing_since'] ) {
					$files[] = (int) $file['id'];
				}
			}
			$this->store->markFilesMissing( $files, $now );

			foreach ( $this->store->childDirectories( $id ) as $child ) {
				if ( null === $child['missing_since'] ) {
					$stack[] = $child;
				}
			}
		}
	}

	private function isInsideRoot( string $path, string $root ): bool {
		$real = realpath( $path );

		return false !== $real && ( $real === $root || PathConfinement::isWithin( $root, $real ) );
	}

	private function now(): int {
		return null === $this->clock ? time() : (int) ( $this->clock )();
	}
}
