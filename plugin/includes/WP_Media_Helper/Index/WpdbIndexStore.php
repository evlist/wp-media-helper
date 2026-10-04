<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Index;

/**
 * The file index in two database tables (see {@see Schema}).
 *
 * Only plain SQL is used (no MySQL-only syntax), values always go through
 * `$wpdb->prepare()`, and a scan only writes the columns it owns.
 */
class WpdbIndexStore implements IndexStore {

	private const INSERT_CHUNK = 100;

	private const INTEGER_COLUMNS = [ 'id', 'parent_id', 'dir_id', 'mtime', 'scanned_run', 'queued_run', 'last_scanned', 'missing_since', 'size', 'first_seen', 'last_seen', 'hidden', 'hidden_by', 'hidden_at', 'embedded_state' ];

	/**
	 * Columns a scan may update on a file.
	 */
	private const UPDATABLE_FILE_COLUMNS = [ 'size', 'mtime', 'name_date', 'name_date_precision', 'embedded_date', 'embedded_state', 'effective_date', 'effective_day', 'date_source', 'missing_since' ];

	public function findDirectory( string $sourceId, string $key ): ?array {
		global $wpdb;

		$table = Schema::directoriesTable();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_id = %s AND path_hash = %s", $sourceId, sha1( $key ) ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return is_array( $row ) ? self::typed( $row ) : null;
	}

	public function saveDirectory( string $sourceId, string $key, int $parentId, int $now ): int {
		global $wpdb;

		$table    = Schema::directoriesTable();
		$existing = $this->findDirectory( $sourceId, $key );
		if ( null !== $existing ) {
			$wpdb->update( $table, [ 'parent_id' => $parentId, 'missing_since' => null ], [ 'id' => $existing['id'] ] ); // phpcs:ignore WordPress.DB

			return (int) $existing['id'];
		}

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB
			$table,
			[
				'source_id' => $sourceId,
				'parent_id' => $parentId,
				'path_hash' => sha1( $key ),
				'path'      => $key,
			]
		);
		if ( false !== $inserted && (int) $wpdb->insert_id > 0 ) {
			return (int) $wpdb->insert_id;
		}

		// Another request inserted the same directory a moment ago.
		$concurrent = $this->findDirectory( $sourceId, $key );

		return null === $concurrent ? 0 : (int) $concurrent['id'];
	}

	public function childDirectories( int $directoryId ): array {
		global $wpdb;

		$table = Schema::directoriesTable();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE parent_id = %d ORDER BY id ASC", $directoryId ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return array_map( [ self::class, 'typed' ], is_array( $rows ) ? $rows : [] );
	}

	public function queueDirectory( int $directoryId, int $run ): void {
		global $wpdb;

		$wpdb->update( Schema::directoriesTable(), [ 'queued_run' => $run ], [ 'id' => $directoryId ] ); // phpcs:ignore WordPress.DB
	}

	public function queueChildDirectories( int $directoryId, int $run ): void {
		global $wpdb;

		$table = Schema::directoriesTable();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET queued_run = %d WHERE parent_id = %d AND missing_since IS NULL", $run, $directoryId ) ); // phpcs:ignore WordPress.DB
	}

	public function nextQueued( string $sourceId, int $run, int $limit ): array {
		global $wpdb;

		$table = Schema::directoriesTable();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE source_id = %s AND queued_run = %d AND scanned_run <> %d AND missing_since IS NULL ORDER BY id ASC LIMIT %d",
				$sourceId,
				$run,
				$run,
				max( 1, $limit )
			),
			ARRAY_A
		);

		return array_map( [ self::class, 'typed' ], is_array( $rows ) ? $rows : [] );
	}

	public function countPending( string $sourceId, int $run ): int {
		global $wpdb;

		$table = Schema::directoriesTable();

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE source_id = %s AND queued_run = %d AND scanned_run <> %d AND missing_since IS NULL",
				$sourceId,
				$run,
				$run
			)
		);
	}

	public function markScanned( int $directoryId, int $run, ?int $mtime, int $now ): void {
		global $wpdb;

		$wpdb->update( Schema::directoriesTable(), [ 'scanned_run' => $run, 'mtime' => $mtime, 'last_scanned' => $now ], [ 'id' => $directoryId ] ); // phpcs:ignore WordPress.DB
	}

	public function filesInDirectory( int $directoryId ): array {
		global $wpdb;

		$table = Schema::filesTable();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE dir_id = %d", $directoryId ), ARRAY_A ); // phpcs:ignore WordPress.DB

		$byName = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$row                    = self::typed( $row );
			$byName[ $row['name'] ] = $row;
		}

		return $byName;
	}

	public function insertFiles( string $sourceId, int $directoryId, array $files, int $now ): void {
		global $wpdb;

		$table   = Schema::filesTable();
		$columns = 'source_id, dir_id, path_hash, path, name, ext, kind, size, mtime, name_date, name_date_precision, embedded_date, embedded_state, effective_date, effective_day, date_source, first_seen, last_seen';

		foreach ( array_chunk( $files, self::INSERT_CHUNK ) as $chunk ) {
			$rows = [];
			$args = [];
			foreach ( $chunk as $file ) {
				$cells = [
					[ '%s', $sourceId ],
					[ '%d', $directoryId ],
					[ '%s', sha1( (string) $file['key'] ) ],
					[ '%s', (string) $file['key'] ],
					[ '%s', (string) $file['name'] ],
					[ '%s', (string) $file['ext'] ],
					[ '%s', (string) $file['kind'] ],
					[ '%d', (int) $file['size'] ],
					[ '%d', (int) $file['mtime'] ],
					[ '%s', $file['name_date'] ?? null ],
					[ '%s', $file['name_date_precision'] ?? null ],
					[ '%s', $file['embedded_date'] ?? null ],
					[ '%d', (int) ( $file['embedded_state'] ?? 0 ) ],
					[ '%s', $file['effective_date'] ?? null ],
					[ '%s', $file['effective_day'] ?? null ],
					[ '%s', (string) ( $file['date_source'] ?? 'none' ) ],
					[ '%d', $now ],
					[ '%d', $now ],
				];

				$placeholders = [];
				foreach ( $cells as $cell ) {
					if ( null === $cell[1] ) {
						$placeholders[] = 'NULL';
						continue;
					}
					$placeholders[] = $cell[0];
					$args[]         = $cell[1];
				}
				$rows[] = '(' . implode( ', ', $placeholders ) . ')';
			}

			$done = $wpdb->query( $wpdb->prepare( "INSERT INTO {$table} ({$columns}) VALUES " . implode( ', ', $rows ), $args ) ); // phpcs:ignore WordPress.DB
			if ( false === $done ) {
				// A concurrent request may have inserted some of these files: one by one,
				// so that the others are not lost with them.
				$this->insertOneByOne( $sourceId, $directoryId, $chunk, $now );
			}
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $files
	 */
	private function insertOneByOne( string $sourceId, int $directoryId, array $files, int $now ): void {
		global $wpdb;

		$table = Schema::filesTable();
		$known = $this->filesInDirectory( $directoryId );
		foreach ( $files as $file ) {
			if ( isset( $known[ (string) $file['name'] ] ) ) {
				continue;
			}

			$wpdb->insert( // phpcs:ignore WordPress.DB
				$table,
				[
					'source_id'           => $sourceId,
					'dir_id'              => $directoryId,
					'path_hash'           => sha1( (string) $file['key'] ),
					'path'                => (string) $file['key'],
					'name'                => (string) $file['name'],
					'ext'                 => (string) $file['ext'],
					'kind'                => (string) $file['kind'],
					'size'                => (int) $file['size'],
					'mtime'               => (int) $file['mtime'],
					'name_date'           => $file['name_date'] ?? null,
					'name_date_precision' => $file['name_date_precision'] ?? null,
					'embedded_date'       => $file['embedded_date'] ?? null,
					'embedded_state'      => (int) ( $file['embedded_state'] ?? 0 ),
					'effective_date'      => $file['effective_date'] ?? null,
					'effective_day'       => $file['effective_day'] ?? null,
					'date_source'         => (string) ( $file['date_source'] ?? 'none' ),
					'first_seen'          => $now,
					'last_seen'           => $now,
				]
			);
		}
	}

	public function updateFile( int $fileId, array $fields, int $now ): void {
		global $wpdb;

		$data = array_intersect_key( $fields, array_flip( self::UPDATABLE_FILE_COLUMNS ) );
		if ( [] === $data ) {
			return;
		}
		$data['last_seen'] = $now;

		$wpdb->update( Schema::filesTable(), $data, [ 'id' => $fileId ] ); // phpcs:ignore WordPress.DB
	}

	public function markFilesMissing( array $fileIds, int $now ): void {
		global $wpdb;

		$ids = array_values( array_unique( array_map( 'intval', $fileIds ) ) );
		if ( [] === $ids ) {
			return;
		}

		$table = Schema::filesTable();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$wpdb->query( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"UPDATE {$table} SET missing_since = %d WHERE missing_since IS NULL AND id IN (" . implode( ', ', array_fill( 0, count( $chunk ), '%d' ) ) . ')',
					array_merge( [ $now ], $chunk )
				)
			);
		}
	}

	public function markDirectoryMissing( int $directoryId, int $now ): void {
		global $wpdb;

		$table = Schema::directoriesTable();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET missing_since = %d WHERE id = %d AND missing_since IS NULL", $now, $directoryId ) ); // phpcs:ignore WordPress.DB
	}

	public function filesForDay( array $sourceIds, string $day ): array {
		global $wpdb;

		$sourceIds = array_values( array_unique( array_map( 'strval', $sourceIds ) ) );
		if ( [] === $sourceIds ) {
			return [];
		}

		$table = Schema::filesTable();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE effective_day = %s AND missing_since IS NULL AND hidden = 0 AND source_id IN (" . implode( ', ', array_fill( 0, count( $sourceIds ), '%s' ) ) . ') ORDER BY effective_date ASC, name ASC',
				array_merge( [ $day ], $sourceIds )
			),
			ARRAY_A
		);

		return array_map( [ self::class, 'typed' ], is_array( $rows ) ? $rows : [] );
	}

	public function countFiles( string $sourceId ): int {
		global $wpdb;

		$table = Schema::filesTable();

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source_id = %s AND missing_since IS NULL", $sourceId ) ); // phpcs:ignore WordPress.DB
	}

	public function countDirectories( string $sourceId ): int {
		global $wpdb;

		$table = Schema::directoriesTable();

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source_id = %s AND missing_since IS NULL", $sourceId ) ); // phpcs:ignore WordPress.DB
	}

	public function purgeMissing( int $before ): int {
		global $wpdb;

		$deleted = 0;
		foreach ( [ Schema::filesTable(), Schema::directoriesTable() ] as $table ) {
			$count = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE missing_since IS NOT NULL AND missing_since < %d", $before ) ); // phpcs:ignore WordPress.DB
			$deleted += is_int( $count ) ? $count : 0;
		}

		return $deleted;
	}

	public function deleteSource( string $sourceId ): void {
		global $wpdb;

		foreach ( [ Schema::filesTable(), Schema::directoriesTable() ] as $table ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE source_id = %s", $sourceId ) ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * Database drivers return strings: restore integers and nulls.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function typed( array $row ): array {
		foreach ( self::INTEGER_COLUMNS as $column ) {
			if ( array_key_exists( $column, $row ) && null !== $row[ $column ] ) {
				$row[ $column ] = (int) $row[ $column ];
			}
		}

		return $row;
	}
}
