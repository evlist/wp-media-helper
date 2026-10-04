<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Index;

/**
 * Tables of the file index, created with dbDelta() and versioned by an option.
 */
class Schema {

	public const VERSION        = 1;
	public const VERSION_OPTION = 'wp_media_helper_index_db_version';

	public static function directoriesTable(): string {
		global $wpdb;

		return $wpdb->prefix . 'media_helper_dirs';
	}

	public static function filesTable(): string {
		global $wpdb;

		return $wpdb->prefix . 'media_helper_files';
	}

	/**
	 * The CREATE TABLE statements, in the format dbDelta() expects.
	 *
	 * @return array{directories:string, files:string}
	 */
	public static function statements( string $directoriesTable, string $filesTable, string $charsetCollate ): array {
		return [
			'directories' => "CREATE TABLE {$directoriesTable} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_id varchar(100) NOT NULL,
  parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
  path_hash char(40) NOT NULL,
  path text NOT NULL,
  mtime bigint(20) unsigned DEFAULT NULL,
  scanned_run bigint(20) unsigned NOT NULL DEFAULT 0,
  queued_run bigint(20) unsigned NOT NULL DEFAULT 0,
  last_scanned bigint(20) unsigned NOT NULL DEFAULT 0,
  missing_since bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY source_path (source_id,path_hash),
  KEY parent_id (parent_id),
  KEY queue (source_id,queued_run)
) {$charsetCollate};",
			'files'       => "CREATE TABLE {$filesTable} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_id varchar(100) NOT NULL,
  dir_id bigint(20) unsigned NOT NULL,
  path_hash char(40) NOT NULL,
  path text NOT NULL,
  name varchar(255) NOT NULL,
  ext varchar(20) NOT NULL DEFAULT '',
  kind varchar(10) NOT NULL DEFAULT 'other',
  size bigint(20) unsigned NOT NULL DEFAULT 0,
  mtime bigint(20) unsigned NOT NULL DEFAULT 0,
  name_date datetime DEFAULT NULL,
  name_date_precision varchar(10) DEFAULT NULL,
  embedded_date datetime DEFAULT NULL,
  embedded_state tinyint(3) unsigned NOT NULL DEFAULT 0,
  date_override datetime DEFAULT NULL,
  effective_date datetime DEFAULT NULL,
  effective_day date DEFAULT NULL,
  date_source varchar(10) NOT NULL DEFAULT 'none',
  hidden tinyint(1) unsigned NOT NULL DEFAULT 0,
  hidden_by bigint(20) unsigned DEFAULT NULL,
  hidden_at bigint(20) unsigned DEFAULT NULL,
  first_seen bigint(20) unsigned NOT NULL DEFAULT 0,
  last_seen bigint(20) unsigned NOT NULL DEFAULT 0,
  missing_since bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY source_path (source_id,path_hash),
  KEY day (effective_day,hidden),
  KEY source_day (source_id,effective_day),
  KEY dir_id (dir_id)
) {$charsetCollate};",
		];
	}

	/**
	 * Creates or updates the tables when the stored version is older, and removes the
	 * JSON index files of earlier versions.
	 */
	public static function maybeUpgrade(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::VERSION ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$statements = self::statements( self::directoriesTable(), self::filesTable(), $wpdb->get_charset_collate() );
		dbDelta( $statements['directories'] );
		dbDelta( $statements['files'] );

		self::removeLegacyIndexFiles();
		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Drops the tables (uninstall).
	 */
	public static function drop(): void {
		global $wpdb;

		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::filesTable() ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::directoriesTable() ); // phpcs:ignore WordPress.DB
		delete_option( self::VERSION_OPTION );
	}

	/**
	 * Deletes the JSON files of the previous index and their folder. Only the files
	 * that index wrote are removed.
	 */
	public static function removeLegacyIndexFiles(): void {
		$folders = [ rtrim( sys_get_temp_dir(), '/\\' ) . '/wp-media-helper-index' ];

		$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir( null, false ) : null;
		if ( is_array( $uploads ) && ! empty( $uploads['basedir'] ) ) {
			$folders[] = rtrim( (string) $uploads['basedir'], '/\\' ) . '/wp-media-helper-index';
		}

		foreach ( glob( rtrim( sys_get_temp_dir(), '/\\' ) . '/wp-media-helper-index-*', GLOB_ONLYDIR ) ?: [] as $folder ) {
			$folders[] = $folder;
		}

		foreach ( $folders as $folder ) {
			if ( ! is_dir( $folder ) || is_link( $folder ) ) {
				continue;
			}

			foreach ( array_merge( glob( $folder . '/*.json' ) ?: [], [ $folder . '/index.php', $folder . '/.htaccess' ] ) as $file ) {
				if ( is_file( $file ) && ! is_link( $file ) ) {
					@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
				}
			}
			@rmdir( $folder ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
	}
}
