<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\MediaSource;

/**
 * Finds the attachments that already exist for files of the uploads directory,
 * whichever tool registered them.
 *
 * Recognition is by exact file identity: the path relative to the uploads
 * directory, as WordPress and tools such as Bulk Media Register store it in
 * `_wp_attached_file`. The absolute paths written by older versions of this
 * plugin are recognised too.
 */
class AttachmentRegistry {

	public const SOURCE_ID_META   = '_wp_media_helper_source_id';
	public const SOURCE_PATH_META = '_wp_media_helper_source_path';

	private const CHUNK_SIZE = 400;

	/**
	 * @return array{basedir:string, baseurl:string}|null
	 */
	public function uploads(): ?array {
		$uploads = wp_upload_dir( null, false );
		if ( ! is_array( $uploads ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return null;
		}

		return [
			'basedir' => (string) $uploads['basedir'],
			'baseurl' => (string) $uploads['baseurl'],
		];
	}

	/**
	 * Path of a file relative to the uploads directory, or null when it is
	 * outside it.
	 */
	public function relativeKey( string $path ): ?string {
		$uploads = $this->uploads();

		return null === $uploads ? null : UploadsPath::relativeKey( $path, $uploads['basedir'] );
	}

	/**
	 * Attachments of one file, oldest first.
	 *
	 * @return array<int, array{id:int, parent:int, owned:bool}>
	 */
	public function findByPath( string $path ): array {
		return $this->statesFor( [ $path ] )[ $path ] ?? [];
	}

	/**
	 * Looks up the attachments of many files with a few exact queries.
	 *
	 * `owned` is true for attachments created by this plugin: only those may be
	 * removed by it.
	 *
	 * @param string[] $paths Paths as listed by the scanner.
	 * @return array<string, array<int, array{id:int, parent:int, owned:bool}>> Keyed by listed path.
	 */
	public function statesFor( array $paths ): array {
		global $wpdb;

		$uploads = $this->uploads();
		$basedir = null === $uploads ? '' : $uploads['basedir'];

		$pathsByKey = [];
		foreach ( array_unique( array_filter( $paths, static fn ( $path ): bool => is_string( $path ) && '' !== trim( $path ) ) ) as $path ) {
			foreach ( UploadsPath::lookupKeys( $path, $basedir ) as $key ) {
				$pathsByKey[ $key ][] = $path;
			}
		}
		if ( [] === $pathsByKey ) {
			return [];
		}

		$states = [];
		foreach ( array_chunk( array_keys( $pathsByKey ), self::CHUNK_SIZE ) as $keys ) {
			$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$query = $wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value, p.post_parent,
					EXISTS( SELECT 1 FROM {$wpdb->postmeta} o WHERE o.post_id = pm.post_id AND o.meta_key = %s ) AS owned
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = '_wp_attached_file' AND p.post_type = 'attachment' AND pm.meta_value IN ( {$placeholders} )
				ORDER BY pm.post_id ASC",
				array_merge( [ self::SOURCE_ID_META ], $keys )
			);
			$rows = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! is_array( $rows ) ) {
				continue;
			}

			foreach ( $rows as $row ) {
				// The database comparison ignores case; the file system may not.
				foreach ( $pathsByKey[ (string) $row['meta_value'] ] ?? [] as $path ) {
					$states[ $path ][ (int) $row['post_id'] ] = [
						'id'     => (int) $row['post_id'],
						'parent' => (int) $row['post_parent'],
						'owned'  => '1' === (string) $row['owned'] || 1 === $row['owned'],
					];
				}
			}
		}

		return array_map( 'array_values', $states );
	}

	/**
	 * Rewrites an attachment registered by an earlier version of this plugin in
	 * the WordPress-native form: relative `_wp_attached_file`, URL guid, and no
	 * server path in the provenance meta.
	 */
	public function normalizeLegacy( int $attachmentId, string $relative ): void {
		$uploads = $this->uploads();
		if ( null === $uploads ) {
			return;
		}

		$stored = get_post_meta( $attachmentId, '_wp_attached_file', true );
		if ( is_string( $stored ) && $stored !== $relative ) {
			update_post_meta( $attachmentId, '_wp_attached_file', wp_slash( $relative ) );
		}

		update_post_meta( $attachmentId, self::SOURCE_PATH_META, wp_slash( $relative ) );

		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'guid' => UploadsPath::url( $uploads['baseurl'], $relative ) ], [ 'ID' => $attachmentId ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $attachmentId );
	}
}
