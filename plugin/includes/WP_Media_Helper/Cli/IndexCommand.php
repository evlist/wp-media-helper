<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Cli;

use WP_Media_Helper\Index\WpdbIndexStore;

/**
 * Reads the index.
 */
class IndexCommand {

	private const FIELDS = [ 'attachment', 'path', 'source_id', 'effective_date', 'date_source', 'name_date', 'embedded_date', 'date_override', 'hidden', 'found' ];

	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'media-helper index', self::class );
		}
	}

	/**
	 * Shows the dates the index holds for some files.
	 *
	 * Give paths relative to the uploads directory, or attachment IDs (the path of an attachment follows it when its files
	 * are moved, so IDs allow comparing the dates before and after a remap), or a source to take its first files from.
	 *
	 * ## OPTIONS
	 *
	 * [<path>...]
	 * : Paths relative to the uploads directory.
	 *
	 * [--attachment=<ids>]
	 * : Attachment IDs, separated by commas.
	 *
	 * [--source=<id>]
	 * : Take the first files of this source (in the order of the index).
	 *
	 * [--limit=<n>]
	 * : How many files with --source. Default: 20.
	 *
	 * [--format=<format>]
	 * : table, json or csv. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-helper index dates --attachment=49103,49104 --format=json
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc
	 */
	public function dates( array $args, array $assoc ): void {
		$store = new WpdbIndexStore();
		$rows  = [];

		$keys = [];
		foreach ( $args as $path ) {
			$keys[ trim( (string) $path, '/' ) ] = '';
		}
		if ( isset( $assoc['attachment'] ) ) {
			foreach ( array_filter( array_map( 'intval', explode( ',', (string) $assoc['attachment'] ) ) ) as $id ) {
				$path = (string) get_post_meta( $id, '_wp_attached_file', true );
				if ( '' === $path ) {
					$rows[] = self::row( (string) $id, '', null );
					continue;
				}
				$keys[ $path ] = (string) $id;
			}
		}

		$found = [];
		foreach ( $store->rowsByKeys( array_keys( $keys ) ) as $row ) {
			$found[ (string) $row['path'] ][] = $row;
		}
		foreach ( $keys as $path => $attachment ) {
			foreach ( $found[ (string) $path ] ?? [ null ] as $row ) {
				$rows[] = self::row( (string) $attachment, (string) $path, $row );
			}
		}

		if ( isset( $assoc['source'] ) ) {
			foreach ( $store->sampleRows( (string) $assoc['source'], (int) ( $assoc['limit'] ?? 20 ) ) as $row ) {
				$rows[] = self::row( '', (string) $row['path'], $row );
			}
		}

		if ( [] === $rows ) {
			\WP_CLI::error( 'Give paths, --attachment or --source.' );
		}

		$format = (string) ( $assoc['format'] ?? 'table' );
		if ( 'json' === $format ) {
			\WP_CLI::line( (string) wp_json_encode( $rows ) );

			return;
		}
		\WP_CLI\Utils\format_items( $format, $rows, self::FIELDS );
	}

	/**
	 * @param array<string, mixed>|null $row
	 * @return array<string, string>
	 */
	private static function row( string $attachment, string $path, ?array $row ): array {
		return [
			'attachment'     => $attachment,
			'path'           => $path,
			'source_id'      => (string) ( $row['source_id'] ?? '' ),
			'effective_date' => (string) ( $row['effective_date'] ?? '' ),
			'date_source'    => (string) ( $row['date_source'] ?? '' ),
			'name_date'      => (string) ( $row['name_date'] ?? '' ),
			'embedded_date'  => (string) ( $row['embedded_date'] ?? '' ),
			'date_override'  => (string) ( $row['date_override'] ?? '' ),
			'hidden'         => null === $row ? '' : ( empty( $row['hidden'] ) ? '0' : '1' ),
			'found'          => null === $row ? 'no' : 'yes',
		];
	}
}
