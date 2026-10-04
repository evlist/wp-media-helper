<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

/**
 * The part of `wpdb` used by the index, on top of an in-memory SQLite database,
 * so the SQL of the store can be tested without a MySQL server.
 */
class FakeWpdb {

	public string $prefix = 'wp_';
	public int $insert_id = 0;
	public int $queries = 0;

	private PDO $pdo;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->exec( 'CREATE TABLE wp_media_helper_dirs (
			id INTEGER PRIMARY KEY AUTOINCREMENT, source_id TEXT NOT NULL, parent_id INTEGER NOT NULL DEFAULT 0,
			path_hash TEXT NOT NULL, path TEXT NOT NULL, mtime INTEGER DEFAULT NULL, scanned_run INTEGER NOT NULL DEFAULT 0,
			queued_run INTEGER NOT NULL DEFAULT 0, last_scanned INTEGER NOT NULL DEFAULT 0, missing_since INTEGER DEFAULT NULL)' );
		$this->pdo->exec( 'CREATE UNIQUE INDEX dirs_source_path ON wp_media_helper_dirs (source_id, path_hash)' );
		$this->pdo->exec( "CREATE TABLE wp_media_helper_files (
			id INTEGER PRIMARY KEY AUTOINCREMENT, source_id TEXT NOT NULL, dir_id INTEGER NOT NULL, path_hash TEXT NOT NULL,
			path TEXT NOT NULL, name TEXT NOT NULL, ext TEXT NOT NULL DEFAULT '', kind TEXT NOT NULL DEFAULT 'other',
			size INTEGER NOT NULL DEFAULT 0, mtime INTEGER NOT NULL DEFAULT 0, name_date TEXT DEFAULT NULL,
			name_date_precision TEXT DEFAULT NULL, embedded_date TEXT DEFAULT NULL, embedded_state INTEGER NOT NULL DEFAULT 0,
			date_override TEXT DEFAULT NULL, effective_date TEXT DEFAULT NULL, effective_day TEXT DEFAULT NULL,
			date_source TEXT NOT NULL DEFAULT 'none', hidden INTEGER NOT NULL DEFAULT 0, hidden_by INTEGER DEFAULT NULL,
			hidden_at INTEGER DEFAULT NULL, first_seen INTEGER NOT NULL DEFAULT 0, last_seen INTEGER NOT NULL DEFAULT 0,
			missing_since INTEGER DEFAULT NULL)" );
		$this->pdo->exec( 'CREATE UNIQUE INDEX files_source_path ON wp_media_helper_files (source_id, path_hash)' );
		$this->pdo->exec( 'CREATE INDEX files_day ON wp_media_helper_files (effective_day, hidden)' );
		$this->pdo->exec( 'CREATE INDEX files_dir ON wp_media_helper_files (dir_id)' );
	}

	/**
	 * @param string $table
	 * @return string[] Column names of a table.
	 */
	public function columns( string $table ): array {
		return array_column( $this->pdo->query( "PRAGMA table_info({$table})" )->fetchAll( PDO::FETCH_ASSOC ), 'name' );
	}

	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$position = 0;

		return (string) preg_replace_callback(
			'/%[sdf%]/',
			static function ( array $found ) use ( &$args, &$position ): string {
				if ( '%%' === $found[0] ) {
					return '%';
				}
				$value = $args[ $position++ ] ?? null;
				if ( '%d' === $found[0] ) {
					return (string) (int) $value;
				}
				if ( '%f' === $found[0] ) {
					return (string) (float) $value;
				}

				return "'" . str_replace( "'", "''", (string) $value ) . "'";
			},
			$query
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_results( string $query, $output = ARRAY_A ): array {
		++$this->queries;

		return $this->pdo->query( $query )->fetchAll( PDO::FETCH_ASSOC );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_row( string $query, $output = ARRAY_A, int $offset = 0 ): ?array {
		$rows = $this->get_results( $query );

		return $rows[ $offset ] ?? null;
	}

	public function get_var( string $query ) {
		++$this->queries;
		$value = $this->pdo->query( $query )->fetchColumn();

		return false === $value ? null : $value;
	}

	/**
	 * @return array<int, mixed>
	 */
	public function get_col( string $query ): array {
		++$this->queries;

		return $this->pdo->query( $query )->fetchAll( PDO::FETCH_COLUMN );
	}

	/**
	 * @return int|false
	 */
	public function query( string $query ) {
		++$this->queries;

		try {
			return $this->pdo->exec( $query );
		} catch ( PDOException $exception ) {
			return false;
		}
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert( string $table, array $data ) {
		++$this->queries;
		$columns      = array_keys( $data );
		$placeholders = implode( ', ', array_fill( 0, count( $columns ), '?' ) );
		$statement    = $this->pdo->prepare( "INSERT INTO {$table} (" . implode( ', ', $columns ) . ") VALUES ({$placeholders})" );
		try {
			$statement->execute( array_values( $data ) );
		} catch ( PDOException $exception ) {
			$this->insert_id = 0;

			return false;
		}
		$this->insert_id = (int) $this->pdo->lastInsertId();

		return 1;
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 */
	public function update( string $table, array $data, array $where ) {
		++$this->queries;
		$set = [];
		$args = [];
		foreach ( $data as $column => $value ) {
			if ( null === $value ) {
				$set[] = "{$column} = NULL";
				continue;
			}
			$set[]  = "{$column} = ?";
			$args[] = $value;
		}
		$conditions = [];
		foreach ( $where as $column => $value ) {
			$conditions[] = "{$column} = ?";
			$args[]       = $value;
		}
		$statement = $this->pdo->prepare( "UPDATE {$table} SET " . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $conditions ) );
		$statement->execute( $args );

		return $statement->rowCount();
	}
}
