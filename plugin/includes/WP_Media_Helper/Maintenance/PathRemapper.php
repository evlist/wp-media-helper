<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Maintenance;

use WP_Media_Helper\Index\Schema;
use WP_Media_Helper\Index\WpdbIndexStore;
use WP_Media_Helper\MediaSource\AttachmentRegistry;
use WP_Media_Helper\MediaSource\UploadsPath;

/**
 * Moves the paths of existing files to other directories without losing the attachments: the
 * attachments keep their IDs and only the paths recorded for them change, in the posts tables
 * and in the index (rows rewritten in place, so the trash and the dates set by hand stay).
 *
 * The files themselves are moved by whoever mounts the directories. Nothing is written when
 * `$apply` is false: the report is the same.
 */
final class PathRemapper {

	private const BATCH = 500;

	private string $baseDir;
	private string $baseUrl;
	private string $cacheDir;
	private WpdbIndexStore $store;

	/**
	 * The rows that a pass has already sent to a path, by `source|hash`: the dry run and the real run both follow it, so that they
	 * find the same collisions and duplicates (a row moved earlier in the pass is not in the database in a dry run).
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $claimed = [];

	/** @var bool Whether the live row wins when two rows disagree on a date set by hand. */
	private bool $preferLive = false;

	/** @var array<string, bool> The joining directories already counted or created in this pass. */
	private array $joined = [];

	/**
	 * Told of the progress of the long steps (a line of text).
	 *
	 * @var callable|null
	 */
	private $progress = null;

	/**
	 * @param string $cacheDir The thumbnail cache directory (inside the uploads directory), or '' for none.
	 */
	public function __construct( string $baseDir, string $baseUrl, string $cacheDir, ?WpdbIndexStore $store = null ) {
		$this->baseDir  = rtrim( UploadsPath::normalize( $baseDir ), '/' );
		$this->baseUrl  = rtrim( $baseUrl, '/' );
		$this->cacheDir = '' === $cacheDir ? '' : rtrim( UploadsPath::normalize( $cacheDir ), '/' );
		$this->store    = $store ?? new WpdbIndexStore();
	}

	public function onProgress( callable $progress ): void {
		$this->progress = $progress;
	}

	private function tell( string $message ): void {
		if ( null !== $this->progress ) {
			( $this->progress )( $message );
		}
	}

	/**
	 * `ignored` counts, by post type, the posts that have a `_wp_attached_file` under an old prefix without being attachments
	 * (for example the folder records of a media library plugin): they are left alone.
	 *
	 * @return array{
	 *   refused:bool,
	 *   ignored:array<string, int>,
	 *   attachments:array{moved:int, not_ours:int, missing:int[], collisions:int[]},
	 *   index:array{directories:int, files:int, joined:int, collisions:int, merged:int, sources:array<string, array<string, int>>, collision_details:array<int, array<string, mixed>>},
	 *   cache:array{moved:int},
	 *   urls:array{counted:bool, posts:int, meta:int, search_replace:string[]}
	 * }
	 */
	public function run( PathMap $map, bool $apply, bool $moveCache = true, bool $countUrls = true, bool $preferLive = false ): array {
		$this->preferLive = $preferLive;
		$ignored = $this->ignored( $map );
		$urls    = $countUrls ? $this->urls( $map ) : [ 'counted' => false, 'posts' => 0, 'meta' => 0, 'search_replace' => $this->searchReplaceLines( $map ) ];

		// The whole index is planned first, without writing: the same pass as a dry run. A real run goes on only when no row of the
		// index would collide, so that it never leaves the attachments moved and the index half rewritten.
		$index   = $this->index( $map, false );
		$refused = $apply && $index['collisions'] > 0;
		if ( $apply && ! $refused ) {
			$attachments = $this->attachments( $map, true );
			$index       = $this->index( $map, true );
			$cache       = [ 'moved' => $moveCache ? $this->cache( $map, true ) : 0 ];
		} else {
			$attachments = $this->attachments( $map, false );
			$cache       = [ 'moved' => $moveCache ? $this->cache( $map, false ) : 0 ];
		}

		return [
			'refused'     => $refused,
			'ignored'     => $ignored,
			'attachments' => $attachments,
			'index'       => $index,
			'cache'       => $cache,
			'urls'        => $urls,
		];
	}

	/**
	 * @return array<string, int> Post type => number of posts, for the posts that are not attachments.
	 */
	private function ignored( PathMap $map ): array {
		global $wpdb;

		$ignored = [];
		foreach ( $map->entries() as $entry ) {
			[ $low, $high ] = self::range( $entry['from'] );
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT p.post_type AS type, COUNT(*) AS n FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = '_wp_attached_file' AND p.post_type <> 'attachment' AND ( pm.meta_value = %s OR ( pm.meta_value >= %s AND pm.meta_value < %s ) )
				GROUP BY p.post_type", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$entry['from'],
				$low,
				$high
			), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( is_array( $rows ) ? $rows : [] as $row ) {
				$ignored[ (string) $row['type'] ] = ( $ignored[ (string) $row['type'] ] ?? 0 ) + (int) $row['n'];
			}
		}
		ksort( $ignored );

		return $ignored;
	}

	/**
	 * @return array{moved:int, not_ours:int, missing:int[], collisions:int[]}
	 */
	private function attachments( PathMap $map, bool $apply ): array {
		global $wpdb;

		$report  = [ 'moved' => 0, 'not_ours' => 0, 'missing' => [], 'collisions' => [] ];
		$checked = 0;
		foreach ( $map->entries() as $entry ) {
			// The paths already taken below the new prefix, read once: a query by path would read every
			// `_wp_attached_file` of the site again for each attachment (the value has no index).
			$taken = $this->pathsUnder( $entry['to'] );
			$last  = 0;
			[ $low, $high ] = self::range( $entry['from'] );
			while ( true ) {
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT pm.meta_id, pm.post_id, pm.meta_value,
						EXISTS( SELECT 1 FROM {$wpdb->postmeta} o WHERE o.post_id = pm.post_id AND o.meta_key = %s ) AS owned
					FROM {$wpdb->postmeta} pm
					INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'attachment'
					WHERE pm.meta_key = '_wp_attached_file' AND pm.meta_id > %d AND ( pm.meta_value = %s OR ( pm.meta_value >= %s AND pm.meta_value < %s ) )
					ORDER BY pm.meta_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					AttachmentRegistry::SOURCE_ID_META,
					$last,
					$entry['from'],
					$low,
					$high,
					self::BATCH
				), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( ! is_array( $rows ) || [] === $rows ) {
					break;
				}

				foreach ( $rows as $row ) {
					$last   = (int) $row['meta_id'];
					$old    = (string) $row['meta_value'];
					$target = $map->map( $old );
					// Another, longer prefix may own it; and the database comparison ignores case.
					if ( null === $target || $target['entry']['from'] !== $entry['from'] ) {
						continue;
					}
					$this->moveAttachment( (int) $row['post_id'], $old, $target['path'], $target['entry'], '1' === (string) $row['owned'] || 1 === $row['owned'], $taken, $apply, $report );
					if ( 0 === ++$checked % 5000 ) {
						$this->tell( sprintf( 'attachments: %d checked', $checked ) );
					}
				}
			}
		}
		$this->tell( sprintf( 'attachments: %d checked, done', $checked ) );

		return $report;
	}

	/**
	 * The attachments whose `_wp_attached_file` is below a prefix, as path => post ID.
	 *
	 * @return array<string, int>
	 */
	private function pathsUnder( string $prefix ): array {
		global $wpdb;

		[ $low, $high ] = self::range( $prefix );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'attachment' WHERE pm.meta_key = '_wp_attached_file' AND pm.meta_value >= %s AND pm.meta_value < %s", $low, $high ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$paths = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$paths[ (string) $row['meta_value'] ] = (int) $row['post_id'];
		}

		return $paths;
	}

	/**
	 * @param array{from:string, to:string, source:string, from_source:string, root:string} $entry
	 * @param array<string, int>                                                            $taken
	 * @param array{moved:int, not_ours:int, missing:int[], collisions:int[]}               $report
	 */
	private function moveAttachment( int $id, string $old, string $new, array $entry, bool $ours, array &$taken, bool $apply, array &$report ): void {
		global $wpdb;

		if ( ! is_file( $this->baseDir . '/' . $new ) ) {
			$report['missing'][] = $id;

			return;
		}
		if ( isset( $taken[ $new ] ) && $taken[ $new ] !== $id ) {
			$report['collisions'][] = $id;

			return;
		}
		$taken[ $new ] = $id;

		if ( ! $ours ) {
			++$report['not_ours'];
		}
		++$report['moved'];
		if ( ! $apply ) {
			return;
		}

		update_post_meta( $id, '_wp_attached_file', wp_slash( $new ) );

		$metadata = wp_get_attachment_metadata( $id );
		if ( is_array( $metadata ) && isset( $metadata['file'] ) ) {
			$metadata['file'] = $new;
			wp_update_attachment_metadata( $id, $metadata );
		}

		if ( $ours ) {
			update_post_meta( $id, AttachmentRegistry::SOURCE_PATH_META, wp_slash( $new ) );
			if ( '' !== $entry['source'] ) {
				update_post_meta( $id, AttachmentRegistry::SOURCE_ID_META, wp_slash( $entry['source'] ) );
			}
		}

		// Only the address that this path gave: another guid was set by someone else.
		$oldUrl = UploadsPath::url( $this->baseUrl, $old );
		$guid   = $wpdb->get_var( $wpdb->prepare( "SELECT guid FROM {$wpdb->posts} WHERE ID = %d", $id ) ); // phpcs:ignore WordPress.DB
		if ( $guid === $oldUrl ) {
			$wpdb->update( $wpdb->posts, [ 'guid' => UploadsPath::url( $this->baseUrl, $new ) ], [ 'ID' => $id ] ); // phpcs:ignore WordPress.DB
		}
		clean_post_cache( $id );
	}

	/**
	 * @return array{directories:int, files:int, joined:int, collisions:int, merged:int, sources:array<string, array<string, int>>, collision_details:array<int, array<string, mixed>>}
	 */
	private function index( PathMap $map, bool $apply ): array {
		$report = [ 'directories' => 0, 'files' => 0, 'joined' => 0, 'collisions' => 0, 'merged' => 0, 'sources' => [], 'collision_details' => [] ];
		$this->claimed = [];
		$this->joined  = [];
		// Directories first, shortest path first: a joining row exists before the row below it.
		foreach ( $map->entries() as $entry ) {
			$this->rewriteRows( Schema::directoriesTable(), $entry, $map, $apply, $report, 'directories' );
		}
		foreach ( $map->entries() as $entry ) {
			$this->rewriteRows( Schema::filesTable(), $entry, $map, $apply, $report, 'files' );
		}
		$this->claimed = [];

		return $report;
	}

	/**
	 * Rewrites the rows of the index under an old prefix. Several rows can have the same path under different sources (a source that was
	 * reordered or removed leaves stale rows, marked missing, for thirty days): they all go to the same new path, where only one may be.
	 * The one with data a person set (trashed, a date by hand) stays, else the one that is not missing, else the first; the others
	 * are stale duplicates and are dropped (`merged`). When both rows carry data of a person the data is **merged** (a file is trashed if
	 * either is, a date set by hand is kept from whichever has one): the trash flag is set on every row of a path, so both rows
	 * usually hold the same. Only two different dates set by hand are a real collision (`collisions`, listed in `collision_details`),
	 * unless `$preferLive` says that the row not marked missing wins.
	 *
	 * @param array{from:string, to:string, source:string, from_source:string, root:string} $entry
	 * @param array<string, mixed>                                                          $report
	 */
	private function rewriteRows( string $table, array $entry, PathMap $map, bool $apply, array &$report, string $kind ): void {
		global $wpdb;

		$files = 'files' === $kind;
		[ $low, $high ] = self::range( $entry['from'] );
		$last = 0;
		while ( true ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, source_id, path, missing_since" . ( $files ? ', hidden, hidden_by, hidden_at, date_override' : '' ) . " FROM {$table} WHERE id > %d AND ( path = %s OR ( path >= %s AND path < %s ) ) ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$last,
				$entry['from'],
				$low,
				$high,
				self::BATCH
			), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! is_array( $rows ) || [] === $rows ) {
				return;
			}

			foreach ( $rows as $row ) {
				$last   = (int) $row['id'];
				$old    = (string) $row['path'];
				$target = $map->map( $old );
				if ( null === $target || $target['entry']['from'] !== $entry['from'] ) {
					continue;
				}

				$report['sources'][ $entry['from'] ][ (string) $row['source_id'] ] = ( $report['sources'][ $entry['from'] ][ (string) $row['source_id'] ] ?? 0 ) + 1;
				$sourceId = '' !== $entry['source'] ? $entry['source'] : (string) $row['source_id'];
				$hash     = sha1( $target['path'] );
				$key      = $sourceId . '|' . $hash;
				$mine     = $this->describeRow( $row, $files );

				$other = $this->claimed[ $key ] ?? $this->rowAt( $table, $files, $sourceId, $hash, $last );
				if ( null !== $other ) {
					$conflict = $mine['user'] && $other['user'] && null !== $mine['date_override'] && null !== $other['date_override'] && $mine['date_override'] !== $other['date_override'];
					if ( $conflict && ! $this->preferLive ) {
						++$report['collisions'];
						$report['collision_details'][] = [
							'path' => $target['path'],
							'was'  => $old,
							'rows' => [ $this->detail( $other, $sourceId ), $this->detail( $mine, (string) $row['source_id'] ) ],
						];
						continue;
					}
					++$report['merged'];
					// The live row wins over a stale one; then the one with data of a person; then the one already there.
					$mineWins = $mine['missing'] !== $other['missing'] ? ! $mine['missing'] : ( $mine['user'] && ! $other['user'] );
					$winner = $mineWins ? $mine : $other;
					$loser  = $mineWins ? $other : $mine;
					// Nothing a person set is lost: the winner takes what it lacks (a date set by hand, the trash) from the loser.
					$union = [];
					if ( $files ) {
						if ( ! $winner['hidden'] && $loser['hidden'] ) {
							$union = [ 'hidden' => 1, 'hidden_by' => $loser['hidden_by'], 'hidden_at' => $loser['hidden_at'] ];
							$winner['hidden'] = true;
						}
						if ( null === $winner['date_override'] && null !== $loser['date_override'] ) {
							$union['date_override'] = $loser['date_override'];
							$winner['date_override'] = $loser['date_override'];
						}
						$winner['user'] = $winner['hidden'] || null !== $winner['date_override'];
					}
					if ( $apply ) {
						$this->dropRow( $table, $files, $loser['id'], $winner['id'] );
						if ( [] !== $union ) {
							$wpdb->update( $table, $union, [ 'id' => $winner['id'] ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						}
					}
					$this->claimed[ $key ] = $winner;
					if ( $winner['id'] !== $last ) {
						// The row that was there stays where it is (it is already at, or on its way to, the new path).
						continue;
					}
					$mine = $winner;
				}

				$this->claimed[ $key ] = $mine;
				++$report[ $kind ];
				if ( 0 === $report[ $kind ] % 10000 ) {
					$this->tell( sprintf( 'index: %d %s checked', $report[ $kind ], $kind ) );
				}
				$data = [ 'source_id' => $sourceId, 'path_hash' => $hash, 'path' => $target['path'] ];
				if ( ! $files && $old === $entry['from'] ) {
					$data['parent_id'] = $this->joinParent( $entry, $sourceId, $apply, $report );
				}
				if ( $apply ) {
					$wpdb->update( $table, $data, [ 'id' => $last ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
			}
		}
	}

	/**
	 * What a row of the index holds that matters for a merge.
	 *
	 * @param array<string, mixed> $row
	 * @return array{id:int, user:bool, missing:bool, hidden:bool, hidden_by:mixed, hidden_at:mixed, date_override:?string}
	 */
	private function describeRow( array $row, bool $files ): array {
		$hidden = $files && ! empty( $row['hidden'] );
		$date   = $files && null !== ( $row['date_override'] ?? null ) ? (string) $row['date_override'] : null;

		return [
			'id'            => (int) $row['id'],
			'user'          => $hidden || null !== $date,
			'missing'       => null !== ( $row['missing_since'] ?? null ),
			'hidden'        => $hidden,
			'hidden_by'     => $row['hidden_by'] ?? null,
			'hidden_at'     => $row['hidden_at'] ?? null,
			'date_override' => $date,
		];
	}

	/**
	 * A row as the report shows it.
	 *
	 * @param array<string, mixed> $described
	 * @return array<string, mixed>
	 */
	private function detail( array $described, string $source ): array {
		return [ 'id' => $described['id'], 'source' => $source, 'missing' => $described['missing'], 'hidden' => $described['hidden'], 'date_override' => $described['date_override'] ];
	}

	/**
	 * The row of the database at a path of a source, other than `$exceptId`.
	 *
	 * @return array<string, mixed>|null
	 */
	private function rowAt( string $table, bool $files, string $sourceId, string $hash, int $exceptId ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, missing_since" . ( $files ? ', hidden, hidden_by, hidden_at, date_override' : '' ) . " FROM {$table} WHERE source_id = %s AND path_hash = %s AND id <> %d LIMIT 1", $sourceId, $hash, $exceptId ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! is_array( $row ) ) {
			return null;
		}

		return $this->describeRow( $row, $files );
	}

	/**
	 * Deletes a stale duplicate row. The rows below a directory that goes are given to the one that stays.
	 */
	private function dropRow( string $table, bool $files, int $id, int $keepId ): void {
		global $wpdb;

		if ( ! $files ) {
			$wpdb->update( Schema::filesTable(), [ 'dir_id' => $keepId ], [ 'dir_id' => $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( Schema::directoriesTable(), [ 'parent_id' => $keepId ], [ 'parent_id' => $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * The parent of the top directory of an entry: nothing when it is the root of its source, else
	 * the chain of directories from the root down to its parent, created when missing (with no
	 * stored time, so the next scan reads their listing and finds what is new).
	 *
	 * @param array{from:string, to:string, source:string, from_source:string, root:string} $entry
	 * @param array{directories:int, files:int, joined:int, collisions:int}                 $report
	 */
	private function joinParent( array $entry, string $sourceId, bool $apply, array &$report ): int {
		if ( $entry['root'] === $entry['to'] ) {
			return 0;
		}

		$chain = [ $entry['root'] ];
		foreach ( explode( '/', substr( dirname( $entry['to'] ), strlen( $entry['root'] ) + 1 ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			$chain[] = end( $chain ) . '/' . $segment;
		}

		$parentId = 0;
		foreach ( $chain as $key ) {
			$existing = $this->store->findDirectory( $sourceId, $key );
			if ( null !== $existing ) {
				$parentId = (int) $existing['id'];
				continue;
			}
			if ( ! $apply ) {
				// Not created in a dry run: counted once, however many entries share the root.
				if ( ! isset( $this->joined[ $sourceId . '|' . $key ] ) ) {
					$this->joined[ $sourceId . '|' . $key ] = true;
					++$report['joined'];
				}
				continue;
			}
			++$report['joined'];
			$parentId = $this->store->saveDirectory( $sourceId, $key, $parentId, time() );
		}

		return $parentId;
	}

	/**
	 * Moves the thumbnails of the old directories to the new ones inside the cache.
	 */
	private function cache( PathMap $map, bool $apply ): int {
		if ( '' === $this->cacheDir || ! is_dir( $this->cacheDir ) ) {
			return 0;
		}

		$moved = 0;
		foreach ( $map->entries() as $entry ) {
			$from = $this->cacheDir . '/' . $entry['from'];
			$to   = $this->cacheDir . '/' . $entry['to'];
			if ( is_link( $from ) || ! is_dir( $from ) ) {
				continue;
			}
			++$moved;
			if ( ! $apply ) {
				continue;
			}
			$this->moveDirectory( $from, $to );
		}

		return $moved;
	}

	private function moveDirectory( string $from, string $to ): void {
		if ( ! file_exists( $to ) ) {
			if ( ! is_dir( dirname( $to ) ) ) {
				mkdir( dirname( $to ), 0755, true );
			}
			rename( $from, $to );

			return;
		}
		if ( is_link( $to ) || ! is_dir( $to ) ) {
			return;
		}

		foreach ( scandir( $from ) ?: [] as $name ) {
			if ( '.' === $name || '..' === $name || is_link( $from . '/' . $name ) ) {
				continue;
			}
			if ( is_dir( $from . '/' . $name ) ) {
				$this->moveDirectory( $from . '/' . $name, $to . '/' . $name );
			} elseif ( ! file_exists( $to . '/' . $name ) ) {
				rename( $from . '/' . $name, $to . '/' . $name );
			}
		}
		@rmdir( $from ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
	}

	/**
	 * Counts the contents that still carry an old address, and gives the `wp search-replace` lines
	 * to run on purpose. The contents are not edited here. One pass over each table, whatever the
	 * number of prefixes (these tables can hold millions of rows and the match cannot use an index).
	 *
	 * @return array{counted:bool, posts:int, meta:int, search_replace:string[]}
	 */
	private function urls( PathMap $map ): array {
		global $wpdb;

		$likes = [];
		foreach ( $map->entries() as $entry ) {
			$likes[] = '%/uploads/' . $wpdb->esc_like( $entry['from'] ) . '/%';
		}
		$conditions = static fn ( string $column ): string => '( ' . implode( ' OR ', array_fill( 0, count( $likes ), $column . ' LIKE %s' ) ) . ' )';

		$this->tell( 'contents: looking for old addresses in the posts' );
		$posts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type <> 'attachment' AND " . $conditions( 'post_content' ), $likes ) ); // phpcs:ignore WordPress.DB
		$this->tell( 'contents: looking for old addresses in the meta values' );
		$meta = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key <> '_wp_attached_file' AND " . $conditions( 'meta_value' ), $likes ) ); // phpcs:ignore WordPress.DB

		return [ 'counted' => true, 'posts' => $posts, 'meta' => $meta, 'search_replace' => $this->searchReplaceLines( $map ) ];
	}

	/**
	 * @return string[]
	 */
	private function searchReplaceLines( PathMap $map ): array {
		$lines = [];
		foreach ( $map->entries() as $entry ) {
			$lines[] = sprintf( "wp search-replace '/uploads/%s/' '/uploads/%s/' --all-tables --dry-run", $entry['from'], $entry['to'] );
		}

		return $lines;
	}

	/**
	 * Bounds of the strings that start with `$prefix/`: every such string is >= `prefix/` and < `prefix0`
	 * (`0` follows `/`). The database may compare without regard to case, so each row is checked again.
	 *
	 * @return array{0:string, 1:string}
	 */
	private static function range( string $prefix ): array {
		return [ $prefix . '/', $prefix . '0' ];
	}
}
