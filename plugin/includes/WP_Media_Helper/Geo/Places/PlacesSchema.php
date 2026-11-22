<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Geo\Places;

/**
 * The cache of the places (slice 052, phase B). A table of its own, kept when the index is reset: an answer of the service is not asked for again.
 */
final class PlacesSchema {

	public const VERSION        = 2;
	public const VERSION_OPTION = 'wp_media_helper_places_db_version';

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'media_helper_places';
	}

	/**
	 * `state`: 1 found, 2 nothing there (the service answered, there is no place: not asked again), 3 failed (tried again after `next_try`), 4 given up.
	 * `response` is the whole answer of the service as it came, `lat` and `lon` the cell (rounded to 3 decimals) it was asked for.
	 */
	public static function statement( string $table, string $charsetCollate ): string {
		return "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  cell_key char(40) NOT NULL,
  lat decimal(9,6) NOT NULL,
  lon decimal(10,6) NOT NULL,
  lang varchar(20) NOT NULL DEFAULT '',
  state tinyint(3) unsigned NOT NULL DEFAULT 0,
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  next_try bigint(20) unsigned NOT NULL DEFAULT 0,
  osm_type varchar(10) DEFAULT NULL,
  osm_id bigint(20) unsigned DEFAULT NULL,
  place_id bigint(20) unsigned DEFAULT NULL,
  display_name text DEFAULT NULL,
  response longtext DEFAULT NULL,
  service varchar(190) NOT NULL DEFAULT '',
  fetched_at bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY cell_key (cell_key),
  KEY state (state,next_try)
) {$charsetCollate};";
	}

	public static function maybeUpgrade(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::VERSION ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( self::statement( self::table(), $wpdb->get_charset_collate() ) );
		update_option( self::VERSION_OPTION, self::VERSION, false );
	}
}
