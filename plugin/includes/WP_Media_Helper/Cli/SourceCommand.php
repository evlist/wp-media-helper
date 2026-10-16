<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Cli;

use InvalidArgumentException;
use WP_Media_Helper\MediaSource\AttachmentRegistry;
use WP_Media_Helper\Settings\AllowedBase;
use WP_Media_Helper\Settings\ExternalSourceSettings;
use WP_Media_Helper\Settings\SourceCatalog;
use WP_Media_Helper\Settings\SourceState;
use WP_Media_Helper\Thumbnails\ThumbnailCache;

/**
 * Manages the external media sources, like the settings page does (same checks).
 */
class SourceCommand {

	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'media-helper source', self::class );
		}
	}

	/**
	 * Lists the sources in their order of priority.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-helper source list --format=json
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc
	 */
	public function list( array $args, array $assoc ): void {
		$rows = $this->catalog()->rows( $this->attachmentCounts() );
		$format = (string) ( $assoc['format'] ?? 'table' );
		if ( 'json' === $format ) {
			\WP_CLI::line( (string) wp_json_encode( $rows ) );

			return;
		}

		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['name_patterns'] = implode( ' | ', $row['name_patterns'] );
			$rows[ $index ]['mtime_fallback'] = $row['mtime_fallback'] ? 'yes' : 'no';
		}
		\WP_CLI\Utils\format_items( $format, $rows, [ 'rank', 'id', 'name', 'root', 'state', 'path_pattern', 'attachments' ] );
	}

	/**
	 * Adds a source. When a source already has this root nothing is changed.
	 *
	 * ## OPTIONS
	 *
	 * --root=<root>
	 * : Directory of the source, relative to the allowed base directory (the uploads directory).
	 *
	 * [--name=<name>]
	 * : Name. Default: the last segment of the root.
	 *
	 * [--before=<source>]
	 * : Id, root or name of the source it is placed before. Default: at the end. The sources are tried in order: put the specific ones before "uploads".
	 *
	 * [--path-pattern=<pattern>]
	 * : For example {date:Y}/{date:m}/{date:d}.
	 *
	 * [--name-patterns=<patterns>]
	 * : Patterns of dates in file names, separated by |.
	 *
	 * [--mtime-fallback]
	 * : A file whose name has no date is placed on the day of its modification time. On by default; --no-mtime-fallback leaves such a file out of every day.
	 *
	 * [--dry-run]
	 * : Say what would be done and change nothing.
	 *
	 * [--porcelain]
	 * : Only print the id of the source (added or already there).
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-helper source add --root=cumulus/eric --name=eric --before=uploads --path-pattern='{date:Y}/{date:m}/{date:d}'
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc
	 */
	public function add( array $args, array $assoc ): void {
		$catalog = $this->catalog();
		try {
			$result = $catalog->add(
				[
					'root'           => (string) ( $assoc['root'] ?? '' ),
					'name'           => (string) ( $assoc['name'] ?? '' ),
					'path_pattern'   => (string) ( $assoc['path-pattern'] ?? '' ),
					'name_patterns'  => isset( $assoc['name-patterns'] ) ? array_values( array_filter( array_map( 'trim', explode( '|', (string) $assoc['name-patterns'] ) ), 'strlen' ) ) : [],
					'mtime_fallback' => (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'mtime-fallback', true ),
				],
				isset( $assoc['before'] ) ? (string) $assoc['before'] : null
			);
		} catch ( InvalidArgumentException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}

		if ( 'added' === $result['status'] && ! isset( $assoc['dry-run'] ) ) {
			$this->save( $catalog, $result['sources'] );
		}

		if ( isset( $assoc['porcelain'] ) ) {
			\WP_CLI::line( $result['id'] );

			return;
		}
		if ( 'exists' === $result['status'] ) {
			\WP_CLI::success( sprintf( 'A source already has this root: "%s" (rank %d). Nothing changed.', $result['id'], $result['rank'] ) );

			return;
		}
		\WP_CLI::success( sprintf( isset( $assoc['dry-run'] ) ? 'Would add the source "%s" at rank %d.' : 'Added the source "%s" at rank %d.', $result['id'], $result['rank'] ) );
	}

	/**
	 * Excludes a source: it lists nothing, but no other source lists its files either.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Id, root or name.
	 *
	 * [--dry-run]
	 * : Change nothing.
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc
	 */
	public function exclude( array $args, array $assoc ): void {
		$this->setState( (string) ( $args[0] ?? '' ), SourceState::EXCLUDED, $assoc );
	}

	/**
	 * Makes a source active again.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Id, root or name.
	 *
	 * [--dry-run]
	 * : Change nothing.
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc
	 */
	public function include( array $args, array $assoc ): void {
		$this->setState( (string) ( $args[0] ?? '' ), SourceState::ACTIVE, $assoc );
	}

	/**
	 * Removes a source. Refused while attachments are still registered through it.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Id, root or name.
	 *
	 * [--dry-run]
	 * : Change nothing.
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc
	 */
	public function remove( array $args, array $assoc ): void {
		$catalog = $this->catalog();
		try {
			$result = $catalog->without( (string) ( $args[0] ?? '' ), $this->attachmentCounts() );
		} catch ( InvalidArgumentException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}

		if ( ! isset( $assoc['dry-run'] ) ) {
			$this->save( $catalog, $result['sources'] );
		}
		\WP_CLI::success( sprintf( isset( $assoc['dry-run'] ) ? 'Would remove the source "%s".' : 'Removed the source "%s".', $result['id'] ) );
	}

	/**
	 * @param array<string, mixed> $assoc
	 */
	private function setState( string $reference, string $state, array $assoc ): void {
		$catalog = $this->catalog();
		try {
			$result = $catalog->withState( $reference, $state );
		} catch ( InvalidArgumentException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}

		if ( ! $result['changed'] ) {
			\WP_CLI::success( sprintf( 'The source "%s" is already %s. Nothing changed.', $result['id'], $state ) );

			return;
		}
		if ( ! isset( $assoc['dry-run'] ) ) {
			$this->save( $catalog, $result['sources'] );
		}
		\WP_CLI::success( sprintf( isset( $assoc['dry-run'] ) ? 'Would make the source "%s" %s.' : 'The source "%s" is now %s.', $result['id'], $state ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $sources
	 */
	private function save( SourceCatalog $catalog, array $sources ): void {
		try {
			$catalog->save( $sources );
		} catch ( InvalidArgumentException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}
	}

	private function catalog(): SourceCatalog {
		return new SourceCatalog(
			new ExternalSourceSettings(
				static fn(): mixed => get_option( ExternalSourceSettings::optionKey(), [] ),
				static function ( array $value ): void {
					update_option( ExternalSourceSettings::optionKey(), $value );
				},
				static fn(): ?string => AllowedBase::resolve(),
				static fn(): ?string => ThumbnailCache::directory()
			),
			AllowedBase::resolve()
		);
	}

	/**
	 * @return array<string, int> Attachments registered through each source, by source id.
	 */
	private function attachmentCounts(): array {
		global $wpdb;

		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT meta_value AS source, COUNT(*) AS n FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY meta_value", AttachmentRegistry::SOURCE_ID_META ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$counts = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$counts[ (string) $row['source'] ] = (int) $row['n'];
		}

		return $counts;
	}
}
