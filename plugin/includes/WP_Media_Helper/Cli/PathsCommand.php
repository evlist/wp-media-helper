<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Cli;

use InvalidArgumentException;
use WP_Media_Helper\Index\KeyMapper;
use WP_Media_Helper\Index\Pause;
use WP_Media_Helper\Index\Schema;
use WP_Media_Helper\Maintenance\PathMap;
use WP_Media_Helper\Maintenance\PathRemapper;
use WP_Media_Helper\Settings\ActiveSources;
use WP_Media_Helper\Thumbnails\ThumbnailCache;

/**
 * Moves the paths recorded for existing files to other directories, keeping the attachments.
 */
class PathsCommand {

	public const RUNS_OPTION = 'wp_media_helper_remap_runs';
	private const MAX_RUNS   = 10;

	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'media-helper paths', self::class );
		}
	}

	/**
	 * Rewrites the paths of attachments and of the index after the files were mounted elsewhere.
	 *
	 * The attachments keep their IDs: `_wp_attached_file`, the `file` of the metadata, the provenance metas and
	 * the guid change, the rows of the index are rewritten in place (the trash and the dates set by hand stay)
	 * and the thumbnails of the old directories are moved inside the cache. Contents (posts) are not edited: the
	 * report counts those that carry an old address and prints the `wp search-replace` lines to run on purpose.
	 *
	 * Nothing is written without --yes. The indexing must be paused (`wp media-helper pause`) before --yes.
	 * Each path is relative to the uploads directory; the new source must already be configured and its
	 * directory mounted next to the old one.
	 *
	 * ## OPTIONS
	 *
	 * [--from=<prefix>]
	 * : Old path prefix, for example photos/2026/eric.
	 *
	 * [--to=<prefix>]
	 * : New path prefix, for example cumulus/eric/2026.
	 *
	 * [--source=<id>]
	 * : Id of the source (already configured) that owns the new prefix.
	 *
	 * [--from-source=<id>]
	 * : Id of the old source, kept for the undo. Found from the index when there is only one.
	 *
	 * [--map=<file>]
	 * : A file of lines "old new source [old_source]" instead of --from, --to and --source.
	 *
	 * [--cache]
	 * : Move the thumbnails of the old directories inside the cache. On by default; --no-cache leaves them where they are (they are made again when needed).
	 *
	 * [--urls]
	 * : Look for old addresses in the contents (two scans of the posts and meta tables, slow on a large site). On by default; --no-urls skips it. The `wp search-replace` lines are printed anyway.
	 *
	 * [--yes]
	 * : Apply. Without it the command only reports what it would do.
	 *
	 * [--format=<format>]
	 * : text or json. With json the only output is one object: {"applied", "undo", "refused", "run", "ignored":{post type: count}, "attachments":{"moved","not_ours","missing","collisions"}, "index":{"directories","files","joined","collisions","merged","sources":{prefix: {source: rows}}}, "cache":{"moved"}, "urls":{"counted","posts","meta","search_replace"}, "problems"}. `refused` is true when --yes was given but the index has collisions: nothing was changed. Default: text.
	 *
	 * [--undo=<run>]
	 * : Put back the paths of an earlier run, given its identifier.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-helper paths remap --from=photos/2026/eric --to=cumulus/eric/2026 --source=cumulus-eric
	 *     wp media-helper paths remap --map=remap.txt --yes
	 *     wp media-helper paths remap --undo=20261020101500-ab12 --yes
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc
	 */
	public function remap( array $args, array $assoc ): void {
		$apply   = isset( $assoc['yes'] );
		$uploads = wp_upload_dir( null, false );
		$baseDir = is_array( $uploads ) ? (string) ( $uploads['basedir'] ?? '' ) : '';
		$baseUrl = is_array( $uploads ) ? (string) ( $uploads['baseurl'] ?? '' ) : '';
		if ( '' === $baseDir || false === realpath( $baseDir ) ) {
			\WP_CLI::error( 'The uploads directory is not available.' );
		}
		$baseDir = (string) realpath( $baseDir );

		if ( $apply && ! Pause::isPaused() ) {
			\WP_CLI::error( 'Pause the indexing first ("wp media-helper pause"), so no pass runs while the paths change.' );
		}

		try {
			$map = isset( $assoc['undo'] ) ? $this->undoMap( (string) $assoc['undo'] ) : $this->forwardMap( $assoc, $baseDir );
		} catch ( InvalidArgumentException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}

		$cache    = ThumbnailCache::directory();
		$remapper = new PathRemapper( $baseDir, $baseUrl, null === $cache ? '' : $cache );
		$remapper->onProgress( static function ( string $message ): void {
			// On the error output, so that the answer (text or json) stays alone on the standard output.
			fwrite( STDERR, $message . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} );
		$report   = $remapper->run( $map, $apply, (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'cache', true ), (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'urls', true ) );

		$json     = 'json' === ( $assoc['format'] ?? 'text' );
		$problems = count( $report['attachments']['missing'] ) + count( $report['attachments']['collisions'] ) + $report['index']['collisions'];
		$run      = null;
		if ( $apply && ! $report['refused'] && ! isset( $assoc['undo'] ) && $report['attachments']['moved'] + $report['index']['files'] > 0 ) {
			$run = $this->remember( $map );
		}

		if ( $json ) {
			\WP_CLI::line( (string) wp_json_encode( array_merge( [ 'applied' => $apply, 'undo' => isset( $assoc['undo'] ), 'run' => $run ], array_merge( $report, [ 'ignored' => (object) $report['ignored'] ] ), [ 'problems' => $problems ] ) ) );
		} else {
			$this->print( $report, $apply );
			if ( null !== $run ) {
				\WP_CLI::line( sprintf( 'Run %s. Undo with: wp media-helper paths remap --undo=%s --yes', $run, $run ) );
			}
			if ( ! $apply ) {
				\WP_CLI::warning( 'Nothing was changed. Add --yes to apply.' );
			}
		}
		if ( $problems > 0 ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * @param array<string, mixed> $assoc
	 * @throws InvalidArgumentException
	 */
	private function forwardMap( array $assoc, string $baseDir ): PathMap {
		if ( isset( $assoc['map'] ) ) {
			$text = is_readable( (string) $assoc['map'] ) ? (string) file_get_contents( (string) $assoc['map'] ) : '';
			if ( '' === $text ) {
				throw new InvalidArgumentException( 'The map file cannot be read or is empty.' );
			}
			$raw = PathMap::parse( $text )->entries();
		} elseif ( isset( $assoc['from'], $assoc['to'], $assoc['source'] ) ) {
			$raw = ( new PathMap( [ [ 'from' => (string) $assoc['from'], 'to' => (string) $assoc['to'], 'source' => (string) $assoc['source'], 'from_source' => (string) ( $assoc['from-source'] ?? '' ) ] ] ) )->entries();
		} else {
			throw new InvalidArgumentException( 'Give --from, --to and --source, or --map, or --undo.' );
		}

		$keys    = new KeyMapper( $baseDir );
		$sources = [];
		foreach ( ActiveSources::all() as $source ) {
			if ( is_array( $source ) && '' !== (string) ( $source['id'] ?? '' ) ) {
				$sources[ (string) $source['id'] ] = $source;
			}
		}

		$entries = [];
		foreach ( $raw as $entry ) {
			$source = $sources[ $entry['source'] ] ?? null;
			$root   = null === $source ? false : realpath( (string) ( $source['root'] ?? '' ) );
			if ( false === $root ) {
				throw new InvalidArgumentException( sprintf( 'The source "%s" is not configured and active, or its directory does not exist. Add it in the settings first.', $entry['source'] ) );
			}
			if ( ! is_dir( $baseDir . '/' . $entry['to'] ) ) {
				throw new InvalidArgumentException( sprintf( 'The directory "%s" does not exist: mount it before the remap.', $entry['to'] ) );
			}
			$entry['root']        = $keys->key( $root );
			$entry['from_source'] = '' !== $entry['from_source'] ? $entry['from_source'] : $this->soleSourceUnder( $entry['from'] );
			$entries[]            = $entry;
		}

		return new PathMap( $entries );
	}

	/**
	 * The source that holds most of the live rows of the index under a prefix: the old source of those rows, kept to put them back
	 * on undo. Other sources may hold stale rows there (a source reordered or removed); those are merged away by the remap.
	 */
	private function soleSourceUnder( string $from ): string {
		global $wpdb;

		$table = Schema::filesTable();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT source_id, SUM( CASE WHEN missing_since IS NULL THEN 1 ELSE 0 END ) AS live, COUNT(*) AS n FROM {$table} WHERE path >= %s AND path < %s GROUP BY source_id ORDER BY live DESC, n DESC LIMIT 1", $from . '/', $from . '0' ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return is_array( $row ) ? (string) $row['source_id'] : '';
	}

	/**
	 * @throws InvalidArgumentException
	 */
	private function undoMap( string $run ): PathMap {
		$runs = get_option( self::RUNS_OPTION, [] );
		if ( ! is_array( $runs ) || ! isset( $runs[ $run ]['entries'] ) || ! is_array( $runs[ $run ]['entries'] ) ) {
			throw new InvalidArgumentException( sprintf( 'No run "%s" is recorded.', $run ) );
		}

		return ( new PathMap( $runs[ $run ]['entries'] ) )->inverse();
	}

	private function remember( PathMap $map ): string {
		$runs = get_option( self::RUNS_OPTION, [] );
		$runs = is_array( $runs ) ? $runs : [];
		$id   = gmdate( 'YmdHis' ) . '-' . substr( md5( uniqid( '', true ) ), 0, 4 );

		$runs[ $id ] = [ 'time' => time(), 'entries' => $map->entries() ];
		$runs        = array_slice( $runs, -self::MAX_RUNS, null, true );
		update_option( self::RUNS_OPTION, $runs, false );

		return $id;
	}

	/**
	 * @param array<string, mixed> $report
	 */
	private function print( array $report, bool $apply ): void {
		$verb = $apply && ! $report['refused'] ? 'moved' : 'to move';
		foreach ( $report['ignored'] as $type => $count ) {
			\WP_CLI::line( sprintf( 'Ignored, not attachments: %d posts of type %s (they have a _wp_attached_file under an old prefix).', $count, $type ) );
		}
		$a    = $report['attachments'];
		$i    = $report['index'];

		\WP_CLI::line( sprintf( 'Attachments %s: %d (%d not registered by this plugin)', $verb, $a['moved'], $a['not_ours'] ) );
		\WP_CLI::line( sprintf( 'Index %s: %d directories, %d files; %d joining directories created; %d stale duplicate rows dropped', $verb, $i['directories'], $i['files'], $i['joined'], $i['merged'] ) );
		foreach ( $i['sources'] as $prefix => $sources ) {
			if ( count( $sources ) > 1 ) {
				$parts = [];
				foreach ( $sources as $id => $count ) {
					$parts[] = sprintf( '%s: %d', $id, $count );
				}
				\WP_CLI::line( sprintf( '  rows under %s are held by several sources (%s): the stale ones are merged', $prefix, implode( ', ', $parts ) ) );
			}
		}
		if ( $report['refused'] ) {
			\WP_CLI::warning( 'Nothing was changed: some rows of the index would collide (see below). Fix that and run again.' );
		}
		\WP_CLI::line( sprintf( 'Thumbnail directories %s: %d', $verb, $report['cache']['moved'] ) );
		if ( [] !== $a['missing'] ) {
			\WP_CLI::warning( sprintf( '%d attachments left alone, the file is not at the new path: %s', count( $a['missing'] ), implode( ', ', array_slice( $a['missing'], 0, 20 ) ) ) );
		}
		if ( [] !== $a['collisions'] ) {
			\WP_CLI::warning( sprintf( '%d attachments left alone, another attachment already has the new path: %s', count( $a['collisions'] ), implode( ', ', array_slice( $a['collisions'], 0, 20 ) ) ) );
		}
		if ( $i['collisions'] > 0 ) {
			\WP_CLI::warning( sprintf( '%d index rows would collide: two rows that both hold data of a person (trashed, a date set by hand) go to the same new path.', $i['collisions'] ) );
		}

		$u = $report['urls'];
		if ( ! $u['counted'] ) {
			\WP_CLI::line( 'Contents not examined (--no-urls). To replace the old addresses (check the dry run first):' );
		} elseif ( $u['posts'] + $u['meta'] > 0 ) {
			\WP_CLI::line( sprintf( 'Contents with an old address: %d posts, %d meta values. To replace them (check the dry run first):', $u['posts'], $u['meta'] ) );
		} else {
			\WP_CLI::line( 'No content carries an old address.' );

			return;
		}
		foreach ( $u['search_replace'] as $line ) {
			\WP_CLI::line( '  ' . $line );
		}
	}
}
