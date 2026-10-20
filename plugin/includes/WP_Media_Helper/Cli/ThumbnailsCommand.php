<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Cli;

use WP_Media_Helper\Thumbnails\PostImages;
use WP_Media_Helper\Thumbnails\Thumbnails;

/**
 * Makes the named sizes of the images registered by this plugin, in the thumbnail cache.
 */
class ThumbnailsCommand {

	private const BATCH = 200;

	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'media-helper thumbnails', self::class );
		}
	}

	/**
	 * Makes the registered image sizes that images do not have yet and, with --stale, those they have with other dimensions than the current
	 * definition gives (a size redefined: another page format, a theme update).
	 *
	 * It can be run again at any time: it only makes what is missing or stale, and reports what it made. Only images registered by
	 * this plugin are looked at. The files of a stale size that is made again stay in the cache until the attachment is deleted.
	 * Without --post-ids, --prefix, --from or --to, every such image is looked at.
	 *
	 * ## OPTIONS
	 *
	 * [--size=<names>]
	 * : Only these sizes, separated by commas (the names registered with `add_image_size()`, and the standard ones). Default: all.
	 *
	 * [--stale]
	 * : Also make again the sizes whose dimensions differ from their current definition.
	 *
	 * [--post-ids=<ids>]
	 * : Only the images used by these posts, separated by commas: those in their content (`wp-image-<id>`, image, gallery, video and cover blocks,
	 * `[gallery]`), their featured image and the attachments of which they are the parent.
	 *
	 * [--prefix=<prefixes>]
	 * : Only images whose file is below these paths of the uploads directory, separated by commas.
	 *
	 * [--from=<date>]
	 * : Only images of attachments dated this day or later (Y-m-d).
	 *
	 * [--to=<date>]
	 * : Only images of attachments dated this day or earlier (Y-m-d).
	 *
	 * [--limit=<n>]
	 * : Stop after this many images that needed something.
	 *
	 * [--dry-run]
	 * : Say what would be made, make nothing.
	 *
	 * [--format=<format>]
	 * : Format of the report by size: table (default), json, csv, yaml.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-helper thumbnails generate --dry-run
	 *     wp media-helper thumbnails generate --size=print,print-thumb --stale
	 *     wp media-helper thumbnails generate --size=print --post-ids=58741,58680
	 *
	 * @when after_wp_load
	 */
	public function generate( array $args, array $assoc ): void {
		$names = isset( $assoc['size'] ) ? array_values( array_filter( array_map( 'trim', explode( ',', (string) $assoc['size'] ) ) ) ) : null;
		if ( null !== $names ) {
			$unknown = array_values( array_diff( $names, array_keys( wp_get_registered_image_subsizes() ) ) );
			if ( [] !== $unknown ) {
				\WP_CLI::error( 'Unknown size: ' . implode( ', ', $unknown ) . '. Registered: ' . implode( ', ', array_keys( wp_get_registered_image_subsizes() ) ) . '.' );
			}
		}
		$stale  = isset( $assoc['stale'] );
		$dryRun = isset( $assoc['dry-run'] );
		$limit  = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 0;
		$filter = [
			'prefixes' => isset( $assoc['prefix'] ) ? array_values( array_filter( array_map( static fn ( string $prefix ): string => trim( trim( $prefix ), '/' ), explode( ',', (string) $assoc['prefix'] ) ) ) ) : [],
			'from'     => self::day( $assoc, 'from' ),
			'to'       => self::day( $assoc, 'to' ),
		];

		$thumbnails = new Thumbnails();
		$totals     = [];
		$looked     = 0;
		$needed     = 0;
		$failed     = 0;

		$visit = function ( int $id ) use ( $thumbnails, $names, $stale, $dryRun, $filter, &$totals, &$looked, &$needed, &$failed ): bool {
			if ( ! self::matches( $id, $filter ) ) {
				return false;
			}
			$plan = $thumbnails->sizesToMake( $id, $names, $stale );
			if ( null === $plan ) {
				return false;
			}
			++$looked;
			if ( [] === $plan['missing'] && [] === $plan['stale'] ) {
				return false;
			}
			++$needed;
			foreach ( $plan['missing'] as $name ) {
				$totals[ $name ]['missing'] = ( $totals[ $name ]['missing'] ?? 0 ) + 1;
			}
			foreach ( $plan['stale'] as $name ) {
				$totals[ $name ]['stale'] = ( $totals[ $name ]['stale'] ?? 0 ) + 1;
			}
			if ( $dryRun ) {
				return true;
			}

			$report = $thumbnails->makeSizes( $id, $plan['missing'], $plan['stale'] );
			foreach ( $report['made'] as $name ) {
				$totals[ $name ]['made'] = ( $totals[ $name ]['made'] ?? 0 ) + 1;
			}
			foreach ( $report['failed'] as $name ) {
				$totals[ $name ]['failed'] = ( $totals[ $name ]['failed'] ?? 0 ) + 1;
				++$failed;
			}

			return true;
		};

		$ids = isset( $assoc['post-ids'] ) ? self::imagesOfPosts( (string) $assoc['post-ids'] ) : null;
		if ( null !== $ids ) {
			foreach ( $ids as $position => $id ) {
				if ( $visit( $id ) && $limit > 0 && $needed >= $limit ) {
					break;
				}
				self::progress( $position + 1, count( $ids ), $needed );
			}
		} else {
			$last = 0;
			do {
				$batch = self::nextBatch( $last, $filter );
				foreach ( $batch as $id ) {
					$last = $id;
					if ( $visit( $id ) && $limit > 0 && $needed >= $limit ) {
						$batch = [];
						$last  = PHP_INT_MAX;
						break;
					}
				}
				self::progress( $looked, 0, $needed );
			} while ( [] !== $batch && PHP_INT_MAX !== $last );
		}

		$rows = [];
		ksort( $totals );
		foreach ( $totals as $name => $counts ) {
			$rows[] = [
				'size'    => $name,
				'missing' => (int) ( $counts['missing'] ?? 0 ),
				'stale'   => (int) ( $counts['stale'] ?? 0 ),
				'made'    => (int) ( $counts['made'] ?? 0 ),
				'failed'  => (int) ( $counts['failed'] ?? 0 ),
			];
		}
		if ( [] !== $rows ) {
			\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $rows, [ 'size', 'missing', 'stale', 'made', 'failed' ] );
		}

		\WP_CLI::log( sprintf( '%d images looked at, %d %s something.', $looked, $needed, $dryRun ? 'would need' : 'needed' ) );
		if ( $failed > 0 ) {
			\WP_CLI::warning( sprintf( '%d sizes could not be made (the image is too large for the memory limit, unreadable, or the cache is not writable).', $failed ) );
		}
		\WP_CLI::success( $dryRun ? 'Nothing was made (dry run).' : 'Done.' );
	}

	/**
	 * @param array<string, mixed> $assoc
	 */
	private static function day( array $assoc, string $key ): string {
		if ( ! isset( $assoc[ $key ] ) ) {
			return '';
		}
		$value = (string) $assoc[ $key ];
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			\WP_CLI::error( sprintf( '--%s must be a day written Y-m-d.', $key ) );
		}

		return $value;
	}

	/**
	 * @param array{prefixes: string[], from: string, to: string} $filter
	 */
	private static function matches( int $id, array $filter ): bool {
		if ( [] !== $filter['prefixes'] ) {
			$file = (string) get_post_meta( $id, '_wp_attached_file', true );
			$hit  = false;
			foreach ( $filter['prefixes'] as $prefix ) {
				if ( '.' === $prefix || '' === $prefix || str_starts_with( $file, $prefix . '/' ) ) {
					$hit = true;
					break;
				}
			}
			if ( ! $hit ) {
				return false;
			}
		}
		if ( '' !== $filter['from'] || '' !== $filter['to'] ) {
			$day = substr( (string) get_post_field( 'post_date', $id ), 0, 10 );
			if ( ( '' !== $filter['from'] && $day < $filter['from'] ) || ( '' !== $filter['to'] && $day > $filter['to'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The next image attachments after `$after`, by id.
	 *
	 * @param array{prefixes: string[], from: string, to: string} $filter
	 * @return int[]
	 */
	private static function nextBatch( int $after, array $filter ): array {
		global $wpdb;

		$where = [ "p.post_type = 'attachment'", "p.post_mime_type LIKE 'image/%'", $wpdb->prepare( 'p.ID > %d', $after ) ];
		if ( '' !== $filter['from'] ) {
			$where[] = $wpdb->prepare( 'p.post_date >= %s', $filter['from'] . ' 00:00:00' );
		}
		if ( '' !== $filter['to'] ) {
			$where[] = $wpdb->prepare( 'p.post_date <= %s', $filter['to'] . ' 23:59:59' );
		}

		return array_map( 'intval', (array) $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p WHERE " . implode( ' AND ', $where ) . ' ORDER BY p.ID ASC LIMIT ' . self::BATCH ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * The images that posts use: in their content, as featured image, and as attached children.
	 *
	 * @return int[]
	 */
	private static function imagesOfPosts( string $list ): array {
		global $wpdb;

		$ids = [];
		foreach ( array_filter( array_map( 'intval', explode( ',', $list ) ) ) as $postId ) {
			$post = get_post( $postId );
			if ( ! $post instanceof \WP_Post ) {
				\WP_CLI::warning( sprintf( 'Post %d not found.', $postId ) );
				continue;
			}
			$ids = array_merge( $ids, PostImages::ids( (string) $post->post_content ) );
			$featured = (int) get_post_thumbnail_id( $postId );
			if ( $featured > 0 ) {
				$ids[] = $featured;
			}
			$children = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_parent = %d", $postId ) ); // phpcs:ignore WordPress.DB
			$ids      = array_merge( $ids, array_map( 'intval', (array) $children ) );
		}
		$ids = array_values( array_unique( $ids ) );
		sort( $ids );

		return $ids;
	}

	private static function progress( int $done, int $total, int $needed ): void {
		if ( 0 === $done ) {
			return;
		}
		fwrite( STDERR, sprintf( "%d images looked at%s, %d with something to do...\n", $done, $total > 0 ? ' of ' . $total : '', $needed ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
