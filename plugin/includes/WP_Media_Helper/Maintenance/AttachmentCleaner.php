<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Maintenance;

use RuntimeException;
use WP_Media_Helper\Index\Schema;
use WP_Media_Helper\MediaSource\RecordRemover;
use WP_Media_Helper\MediaSource\UploadsPath;

/**
 * Finds, and removes, the attachments of a library that no longer match its files: the duplicates (the same path
 * and the same parent: the lowest ID stays) and the attachments whose file is not at the registered path. Only posts
 * of the type `attachment` are looked at; the other posts that carry a `_wp_attached_file` are counted, by type, and left alone.
 *
 * Removal is of the records only, never of a file (see `RecordRemover`).
 */
final class AttachmentCleaner {

	private const BATCH = 1000;
	private const CHUNK = 100;
	private const MAX_ELSEWHERE = 5;

	private string $baseDir;

	/**
	 * @var callable(int[]): int[]
	 */
	private $remover;

	/**
	 * @var callable|null
	 */
	private $progress = null;

	/**
	 * @param ?callable(int[]): int[] $remover Removes attachments (records only) and returns the IDs removed.
	 */
	public function __construct( string $baseDir, ?callable $remover = null ) {
		$this->baseDir = rtrim( UploadsPath::normalize( $baseDir ), '/' );
		$this->remover = $remover ?? [ RecordRemover::class, 'remove' ];
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
	 * What would be removed.
	 *
	 * @param string[] $prefixes Paths below uploads; an empty list is the whole uploads directory.
	 * @return array{
	 *   prefixes:string[],
	 *   ignored:array<string, int>,
	 *   scanned:int,
	 *   duplicates:array{groups:array<int, array{path:string, keep:int, remove:int[], parent:int}>, parents_differ:array<int, array{path:string, ids:int[], parents:int[]}>, items:array<int, array<string, mixed>>},
	 *   missing:array{items:array<int, array<string, mixed>>}
	 * }
	 * @throws RuntimeException When the directories of the scope cannot be trusted to be mounted.
	 */
	public function plan( array $prefixes, bool $duplicates, bool $missing ): array {
		if ( $missing ) {
			$this->assertReadable( $prefixes );
		}

		$rows = $this->attachments( $prefixes );
		$plan = [
			'prefixes'   => $prefixes,
			'ignored'    => $this->ignored( $prefixes ),
			'scanned'    => count( $rows ),
			'duplicates' => [ 'groups' => [], 'parents_differ' => [], 'items' => [] ],
			'missing'    => [ 'items' => [] ],
		];

		$byPath = [];
		foreach ( $rows as $row ) {
			$byPath[ $row['path'] ][] = $row;
		}

		$removedAsDuplicate = [];
		if ( $duplicates ) {
			foreach ( $byPath as $path => $group ) {
				if ( count( $group ) < 2 ) {
					continue;
				}
				$parents = array_values( array_unique( array_map( static fn ( array $row ): int => $row['parent'], $group ) ) );
				$ids     = array_map( static fn ( array $row ): int => $row['id'], $group );
				sort( $ids );
				if ( count( $parents ) > 1 ) {
					$plan['duplicates']['parents_differ'][] = [ 'path' => (string) $path, 'ids' => $ids, 'parents' => $parents ];
					continue;
				}
				$keep   = $ids[0];
				$remove = array_slice( $ids, 1 );
				$plan['duplicates']['groups'][] = [ 'path' => (string) $path, 'keep' => $keep, 'remove' => $remove, 'parent' => $parents[0] ];
				foreach ( $group as $row ) {
					if ( $row['id'] !== $keep ) {
						$removedAsDuplicate[ $row['id'] ] = $keep;
						$plan['duplicates']['items'][]    = $row + [ 'duplicate_of' => $keep ];
					}
				}
			}
		}

		if ( $missing ) {
			$perPrefix = [];
			foreach ( $rows as $row ) {
				if ( isset( $removedAsDuplicate[ $row['id'] ] ) ) {
					continue;
				}
				$prefix = $this->prefixOf( $row['path'], $prefixes );
				$perPrefix[ $prefix ]['all'] = ( $perPrefix[ $prefix ]['all'] ?? 0 ) + 1;
				if ( ! is_file( $this->baseDir . '/' . $row['path'] ) ) {
					$perPrefix[ $prefix ]['missing'] = ( $perPrefix[ $prefix ]['missing'] ?? 0 ) + 1;
					$plan['missing']['items'][]      = $row;
				}
			}
			// Every attachment of a directory missing is more likely a directory that is not mounted than a clean-up.
			foreach ( $perPrefix as $prefix => $count ) {
				if ( ( $count['missing'] ?? 0 ) === $count['all'] && $count['all'] > 1 ) {
					throw new RuntimeException( sprintf( 'All %d attachments under "%s" have no file: is the directory mounted? Nothing was done.', $count['all'], $prefix ) );
				}
			}
		}

		$this->describe( $plan );

		return $plan;
	}

	/**
	 * The attachments of a group of paths whose file is not there, without refusing: a group that cannot be trusted
	 * (the directory is absent, unreadable or empty, or not one attachment has its file) is reported as unavailable
	 * and gives no orphan, because an unmounted directory would make everything look orphan.
	 *
	 * @param string[] $prefixes Paths below uploads; an empty list is the whole uploads directory.
	 * @param string[] $exclude  Paths below uploads whose attachments are left out (they belong to another group).
	 * @return array{items:array<int, array{id:int, path:string, parent:int, mime:string, date:string, title:string, author:int}>, unavailable:array<string, string>, scanned:int}
	 */
	public function findMissing( array $prefixes, array $exclude = [] ): array {
		$label = [] === $prefixes ? '.' : implode( ',', $prefixes );
		try {
			$this->assertReadable( $prefixes );
		} catch ( RuntimeException $exception ) {
			return [ 'items' => [], 'unavailable' => [ $label => 'not readable or empty' ], 'scanned' => 0 ];
		}

		$rows = array_values( array_filter(
			$this->attachments( $prefixes ),
			static function ( array $row ) use ( $exclude ): bool {
				foreach ( $exclude as $other ) {
					if ( str_starts_with( $row['path'], $other . '/' ) ) {
						return false;
					}
				}

				return true;
			}
		) );

		$missing = [];
		foreach ( $rows as $row ) {
			if ( ! is_file( $this->baseDir . '/' . $row['path'] ) ) {
				$missing[] = $row;
			}
		}
		if ( count( $missing ) === count( $rows ) && count( $rows ) > 1 ) {
			return [ 'items' => [], 'unavailable' => [ $label => 'no attachment has its file' ], 'scanned' => count( $rows ) ];
		}

		return [ 'items' => $missing, 'unavailable' => [], 'scanned' => count( $rows ) ];
	}

	/**
	 * Removes what a plan lists: the featured images that pointed to a duplicate point to the twin that stays, then
	 * the records are removed.
	 *
	 * @param array<string, mixed> $plan
	 * @return int[] The IDs removed.
	 */
	public function apply( array $plan ): array {
		global $wpdb;

		foreach ( $plan['duplicates']['groups'] as $group ) {
			foreach ( $group['remove'] as $id ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_key = '_thumbnail_id' AND meta_value = %s", (string) $group['keep'], (string) $id ) ); // phpcs:ignore WordPress.DB
			}
		}

		$ids = [];
		foreach ( [ $plan['duplicates']['items'], $plan['missing']['items'] ] as $items ) {
			foreach ( $items as $item ) {
				$ids[] = (int) $item['id'];
			}
		}
		$removed = [];
		foreach ( array_chunk( $ids, self::CHUNK ) as $chunk ) {
			$removed = array_merge( $removed, ( $this->remover )( $chunk ) );
			$this->tell( sprintf( 'removed: %d of %d', count( $removed ), count( $ids ) ) );
		}

		return $removed;
	}

	/**
	 * @param string[] $prefixes
	 * @throws RuntimeException
	 */
	private function assertReadable( array $prefixes ): void {
		foreach ( [] === $prefixes ? [ '' ] : $prefixes as $prefix ) {
			$directory = '' === $prefix ? $this->baseDir : $this->baseDir . '/' . $prefix;
			$entries   = is_dir( $directory ) && is_readable( $directory ) ? scandir( $directory ) : false;
			if ( false === $entries ) {
				throw new RuntimeException( sprintf( 'The directory "%s" is not readable: nothing was done (an unmounted directory would make every attachment look orphan).', '' === $prefix ? '.' : $prefix ) );
			}
			if ( [] === array_diff( $entries, [ '.', '..' ] ) ) {
				throw new RuntimeException( sprintf( 'The directory "%s" is empty: nothing was done (an unmounted directory would make every attachment look orphan).', '' === $prefix ? '.' : $prefix ) );
			}
		}
	}

	/**
	 * @param string[] $prefixes
	 */
	private function prefixOf( string $path, array $prefixes ): string {
		foreach ( $prefixes as $prefix ) {
			if ( str_starts_with( $path, $prefix . '/' ) ) {
				return $prefix;
			}
		}

		return '.';
	}

	/**
	 * The SQL condition on `pm.meta_value` for a scope, and its arguments.
	 *
	 * @param string[] $prefixes
	 * @return array{0:string, 1:array<int, string>}
	 */
	private static function scope( array $prefixes ): array {
		if ( [] === $prefixes ) {
			return [ '1 = 1', [] ];
		}

		$conditions = [];
		$args       = [];
		foreach ( $prefixes as $prefix ) {
			$conditions[] = '( pm.meta_value >= %s AND pm.meta_value < %s )';
			$args[]       = $prefix . '/';
			$args[]       = $prefix . '0';
		}

		return [ '( ' . implode( ' OR ', $conditions ) . ' )', $args ];
	}

	/**
	 * @param string[] $prefixes
	 * @return array<int, array{id:int, path:string, parent:int, mime:string, date:string, title:string, author:int}>
	 */
	private function attachments( array $prefixes ): array {
		global $wpdb;

		[ $scope, $scopeArgs ] = self::scope( $prefixes );
		$rows = [];
		$last = 0;
		while ( true ) {
			$batch = $wpdb->get_results( $wpdb->prepare(
				"SELECT pm.meta_id, p.ID AS id, pm.meta_value AS path, p.post_parent AS parent, p.post_mime_type AS mime, p.post_date AS date, p.post_title AS title, p.post_author AS author
				FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'attachment'
				WHERE pm.meta_key = '_wp_attached_file' AND pm.meta_id > %d AND {$scope} ORDER BY pm.meta_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( [ $last ], $scopeArgs, [ self::BATCH ] )
			), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! is_array( $batch ) || [] === $batch ) {
				break;
			}
			foreach ( $batch as $row ) {
				$last = (int) $row['meta_id'];
				$path = (string) $row['path'];
				if ( [] !== $prefixes && '.' === $this->prefixOf( $path, $prefixes ) ) {
					continue;
				}
				$rows[] = [
					'id'     => (int) $row['id'],
					'path'   => $path,
					'parent' => (int) $row['parent'],
					'mime'   => (string) $row['mime'],
					'date'   => (string) $row['date'],
					'title'  => (string) $row['title'],
					'author' => (int) $row['author'],
				];
			}
			$this->tell( sprintf( 'attachments: %d read', count( $rows ) ) );
		}

		return $rows;
	}

	/**
	 * @param string[] $prefixes
	 * @return array<string, int>
	 */
	private function ignored( array $prefixes ): array {
		global $wpdb;

		[ $scope, $scopeArgs ] = self::scope( $prefixes );
		$found = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.post_type AS type, COUNT(*) AS n FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = '_wp_attached_file' AND p.post_type <> 'attachment' AND {$scope} GROUP BY p.post_type", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$scopeArgs
		), ARRAY_A ); // phpcs:ignore WordPress.DB
		$ignored = [];
		foreach ( is_array( $found ) ? $found : [] as $row ) {
			$ignored[ (string) $row['type'] ] = (int) $row['n'];
		}
		ksort( $ignored );

		return $ignored;
	}

	/**
	 * Adds to each item its media type and where it is used; to the missing ones, the same file name elsewhere in the index.
	 *
	 * @param array<string, mixed> $plan
	 */
	private function describe( array &$plan ): void {
		$ids = [];
		foreach ( [ 'duplicates', 'missing' ] as $category ) {
			foreach ( $plan[ $category ]['items'] as $item ) {
				$ids[] = $item['id'];
			}
		}
		$used = $this->usedBy( $ids );

		foreach ( [ 'duplicates', 'missing' ] as $category ) {
			foreach ( $plan[ $category ]['items'] as $index => $item ) {
				$plan[ $category ]['items'][ $index ]['media'] = '' === $item['mime'] ? 'unknown' : (string) strtok( $item['mime'], '/' );
				$plan[ $category ]['items'][ $index ]['used']  = $used[ $item['id'] ] ?? [ 'featured' => [], 'content' => [] ];
			}
		}

		$names = [];
		foreach ( $plan['missing']['items'] as $item ) {
			$names[ basename( $item['path'] ) ] = true;
		}
		$elsewhere = $this->sameNameElsewhere( array_keys( $names ) );
		foreach ( $plan['missing']['items'] as $index => $item ) {
			$plan['missing']['items'][ $index ]['found_elsewhere'] = array_values( array_diff( $elsewhere[ basename( $item['path'] ) ] ?? [], [ $item['path'] ] ) );
		}
	}

	/**
	 * Where attachments are used: as the featured image of posts, and as `wp-image-<id>` in the content of posts.
	 *
	 * @param int[] $ids
	 * @return array<int, array{featured:int[], content:int[]}>
	 */
	public function usedBy( array $ids ): array {
		global $wpdb;

		$used = [];
		foreach ( array_chunk( array_values( array_unique( $ids ) ), self::CHUNK ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
			$featured     = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value IN ({$placeholders})", array_map( 'strval', $chunk ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
			foreach ( is_array( $featured ) ? $featured : [] as $row ) {
				$used[ (int) $row['meta_value'] ]['featured'][] = (int) $row['post_id'];
			}

			$likes = [];
			foreach ( $chunk as $id ) {
				$likes[] = 'post_content LIKE %s';
			}
			$posts = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type <> 'attachment' AND ( " . implode( ' OR ', $likes ) . ' )', array_map( static fn ( int $id ): string => '%wp-image-' . $id . '%', $chunk ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
			foreach ( is_array( $posts ) ? $posts : [] as $post ) {
				if ( 0 < preg_match_all( '/wp-image-(\d+)(?!\d)/', (string) $post['post_content'], $found ) ) {
					foreach ( array_unique( array_map( 'intval', $found[1] ) ) as $id ) {
						if ( in_array( $id, $chunk, true ) ) {
							$used[ $id ]['content'][] = (int) $post['ID'];
						}
					}
				}
			}
		}

		foreach ( $used as $id => $where ) {
			$used[ $id ] = [ 'featured' => array_values( array_unique( $where['featured'] ?? [] ) ), 'content' => array_values( array_unique( $where['content'] ?? [] ) ) ];
		}

		return $used;
	}

	/**
	 * The paths, in the index, of files that have these names.
	 *
	 * @param string[] $names
	 * @return array<string, string[]>
	 */
	public function sameNameElsewhere( array $names ): array {
		global $wpdb;

		$table = Schema::filesTable();
		$found = [];
		foreach ( array_chunk( $names, self::CHUNK ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
			$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT name, path FROM {$table} WHERE missing_since IS NULL AND name IN ({$placeholders}) ORDER BY path ASC", $chunk ), ARRAY_A ); // phpcs:ignore WordPress.DB
			foreach ( is_array( $rows ) ? $rows : [] as $row ) {
				if ( count( $found[ (string) $row['name'] ] ?? [] ) < self::MAX_ELSEWHERE ) {
					$found[ (string) $row['name'] ][] = (string) $row['path'];
				}
			}
		}

		return $found;
	}
}
