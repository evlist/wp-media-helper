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
	 * @param string $cacheDir The thumbnail cache directory (inside the uploads directory), or '' for none.
	 */
	public function __construct( string $baseDir, string $baseUrl, string $cacheDir, ?WpdbIndexStore $store = null ) {
		$this->baseDir  = rtrim( UploadsPath::normalize( $baseDir ), '/' );
		$this->baseUrl  = rtrim( $baseUrl, '/' );
		$this->cacheDir = '' === $cacheDir ? '' : rtrim( UploadsPath::normalize( $cacheDir ), '/' );
		$this->store    = $store ?? new WpdbIndexStore();
	}

	/**
	 * @return array{
	 *   attachments:array{moved:int, not_ours:int, missing:int[], collisions:int[]},
	 *   index:array{directories:int, files:int, joined:int, collisions:int},
	 *   cache:array{moved:int},
	 *   urls:array{posts:int, meta:int, search_replace:string[]}
	 * }
	 */
	public function run( PathMap $map, bool $apply, bool $moveCache = true ): array {
		return [
			'attachments' => $this->attachments( $map, $apply ),
			'index'       => $this->index( $map, $apply ),
			'cache'       => [ 'moved' => $moveCache ? $this->cache( $map, $apply ) : 0 ],
			'urls'        => $this->urls( $map ),
		];
	}

	/**
	 * @return array{moved:int, not_ours:int, missing:int[], collisions:int[]}
	 */
	private function attachments( PathMap $map, bool $apply ): array {
		global $wpdb;

		$report = [ 'moved' => 0, 'not_ours' => 0, 'missing' => [], 'collisions' => [] ];
		foreach ( $map->entries() as $entry ) {
			$last = 0;
			while ( true ) {
				[ $low, $high ] = self::range( $entry['from'] );
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta}
					WHERE meta_key = '_wp_attached_file' AND meta_id > %d AND ( meta_value = %s OR ( meta_value >= %s AND meta_value < %s ) )
					ORDER BY meta_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
					$this->moveAttachment( (int) $row['post_id'], $old, $target['path'], $target['entry'], $apply, $report );
				}
			}
		}

		return $report;
	}

	/**
	 * @param array{from:string, to:string, source:string, from_source:string, root:string} $entry
	 * @param array{moved:int, not_ours:int, missing:int[], collisions:int[]}               $report
	 */
	private function moveAttachment( int $id, string $old, string $new, array $entry, bool $apply, array &$report ): void {
		global $wpdb;

		if ( ! is_file( $this->baseDir . '/' . $new ) ) {
			$report['missing'][] = $id;

			return;
		}
		$taken = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s AND post_id <> %d LIMIT 1", $new, $id ) ); // phpcs:ignore WordPress.DB
		if ( null !== $taken && false !== $taken && '' !== (string) $taken ) {
			$report['collisions'][] = $id;

			return;
		}

		$ours = '' !== (string) get_post_meta( $id, AttachmentRegistry::SOURCE_ID_META, true );
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
	 * @return array{directories:int, files:int, joined:int, collisions:int}
	 */
	private function index( PathMap $map, bool $apply ): array {
		$report = [ 'directories' => 0, 'files' => 0, 'joined' => 0, 'collisions' => 0 ];
		// Directories first, shortest path first: a joining row exists before the row below it.
		foreach ( $map->entries() as $entry ) {
			$this->rewriteRows( Schema::directoriesTable(), $entry, $map, $apply, $report, 'directories' );
		}
		foreach ( $map->entries() as $entry ) {
			$this->rewriteRows( Schema::filesTable(), $entry, $map, $apply, $report, 'files' );
		}

		return $report;
	}

	/**
	 * @param array{from:string, to:string, source:string, from_source:string, root:string} $entry
	 * @param array{directories:int, files:int, joined:int, collisions:int}                 $report
	 */
	private function rewriteRows( string $table, array $entry, PathMap $map, bool $apply, array &$report, string $kind ): void {
		global $wpdb;

		[ $low, $high ] = self::range( $entry['from'] );
		$last = 0;
		while ( true ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, source_id, path FROM {$table} WHERE id > %d AND ( path = %s OR ( path >= %s AND path < %s ) ) ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

				$sourceId = '' !== $entry['source'] ? $entry['source'] : (string) $row['source_id'];
				$taken    = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source_id = %s AND path_hash = %s AND id <> %d", $sourceId, sha1( $target['path'] ), $last ) ); // phpcs:ignore WordPress.DB
				if ( null !== $taken && false !== $taken && '' !== (string) $taken ) {
					++$report['collisions'];
					continue;
				}

				++$report[ $kind ];
				$data = [ 'source_id' => $sourceId, 'path_hash' => sha1( $target['path'] ), 'path' => $target['path'] ];
				if ( 'directories' === $kind && $old === $entry['from'] ) {
					$data['parent_id'] = $this->joinParent( $entry, $sourceId, $apply, $report );
				}
				if ( $apply ) {
					$wpdb->update( $table, $data, [ 'id' => $last ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
			}
		}
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
			++$report['joined'];
			if ( ! $apply ) {
				continue;
			}
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
	 * to run on purpose. The contents are not edited here.
	 *
	 * @return array{posts:int, meta:int, search_replace:string[]}
	 */
	private function urls( PathMap $map ): array {
		global $wpdb;

		$report = [ 'posts' => 0, 'meta' => 0, 'search_replace' => [] ];
		foreach ( $map->entries() as $entry ) {
			$like = '%/uploads/' . $wpdb->esc_like( $entry['from'] ) . '/%';
			$posts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type <> 'attachment' AND post_content LIKE %s", $like ) ); // phpcs:ignore WordPress.DB
			$meta  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key <> '_wp_attached_file' AND meta_value LIKE %s", $like ) ); // phpcs:ignore WordPress.DB
			$report['posts'] += $posts;
			$report['meta']  += $meta;
			$report['search_replace'][] = sprintf( "wp search-replace '/uploads/%s/' '/uploads/%s/' --all-tables --dry-run", $entry['from'], $entry['to'] );
		}

		return $report;
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
