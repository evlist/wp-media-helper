<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Cli;

use InvalidArgumentException;
use RuntimeException;
use WP_Media_Helper\Maintenance\AttachmentCleaner;
use WP_Media_Helper\Maintenance\PathMap;
use WP_Media_Helper\Settings\AllowedBase;
use WP_Media_Helper\Settings\ExternalSourceSettings;
use WP_Media_Helper\Settings\SourceCatalog;
use WP_Media_Helper\Thumbnails\ThumbnailCache;

/**
 * Cleans the attachments of the media library.
 */
class AttachmentsCommand {

	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'media-helper attachments', self::class );
		}
	}

	/**
	 * Removes the attachments that no longer match their files: duplicates, and attachments whose file is missing.
	 *
	 * Only the records are removed, never a file (not even that of the twin that stays), and nothing is written
	 * without --yes. Only posts of type attachment are looked at: other posts that carry a `_wp_attached_file`
	 * (for example the folder records of a media library plugin) are counted by type and left alone.
	 *
	 * - Duplicates: attachments with the same `_wp_attached_file` and the same parent post. The lowest ID stays; the featured
	 *   images that pointed to the others point to it. When the parents differ nothing is removed and the group is reported.
	 * - Missing: attachments whose file is not at the registered path (the duplicates removed by this very run are not
	 *   counted again). Refused, and named, when a directory of the scope is not readable or is empty, or when no attachment
	 *   of a directory has its file: an unmounted directory would make everything look orphan.
	 *
	 * Removal asks every attachment method (`Method::may_remove()` with the context `trash`: the file is gone, the entries of the
	 * attachment disappear from every post) and lists the refusals under `refused` (a refusal counts as a problem). The methods are
	 * told with the action `wp_media_helper_attachments_removed`. Some methods look at the current user: use the WP-CLI global --user.
	 *
	 * The report says, for each attachment, whether it is used (featured image, `wp-image-<id>` in a content) and, for the
	 * missing ones, in which paths of the index a file of the same name is found.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<sources>]
	 * : Ids, roots or names of sources, separated by commas. Their root is the scope.
	 *
	 * [--prefix=<prefixes>]
	 * : Paths below the uploads directory, separated by commas ("." is the whole library). One of --source and --prefix is required.
	 *
	 * [--duplicates]
	 * : Look for duplicates.
	 *
	 * [--missing]
	 * : Look for attachments whose file is missing.
	 *
	 * [--expect-duplicates=<n>]
	 * : Refuse, and change nothing, unless exactly n attachments are to be removed as duplicates.
	 *
	 * [--expect-missing=<n>]
	 * : Refuse, and change nothing, unless exactly n attachments are to be removed as missing.
	 *
	 * [--save=<file>]
	 * : Before removing, write the list (id, path, mime, parent, date, title, author, where used) to this new file, which must be outside the uploads directory. Required with --yes.
	 *
	 * [--format=<format>]
	 * : text or json (one object, the only output; the progress is on the error output). Default: text.

	 *
	 * [--yes]
	 * : Remove. Without it the command only reports.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-helper attachments clean --prefix=photos/eric,photos/gisele --duplicates --missing --format=json
	 *     wp media-helper attachments clean --source=eric --missing --expect-missing=62 --save=/root/removed.json --yes
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc
	 */
	public function clean( array $args, array $assoc ): void {
		$duplicates = (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'duplicates', false );
		$missing    = (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'missing', false );
		$apply      = (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'yes', false );
		$json       = 'json' === ( $assoc['format'] ?? 'text' );
		if ( ! $duplicates && ! $missing ) {
			\WP_CLI::error( 'Say what to look for: --duplicates, --missing, or both.' );
		}
		foreach ( [ 'expect-duplicates' => $duplicates, 'expect-missing' => $missing ] as $option => $wanted ) {
			if ( isset( $assoc[ $option ] ) && ! $wanted ) {
				\WP_CLI::error( sprintf( '--%1$s needs --%2$s.', $option, substr( $option, 7 ) ) );
			}
			if ( isset( $assoc[ $option ] ) && ! ctype_digit( (string) $assoc[ $option ] ) ) {
				\WP_CLI::error( sprintf( '--%s must be a number.', $option ) );
			}
		}
		if ( $apply && ! isset( $assoc['save'] ) ) {
			\WP_CLI::error( 'Give --save=<file> with --yes: the list of what is removed must be kept.' );
		}

		$uploads = wp_upload_dir( null, false );
		$baseDir = is_array( $uploads ) ? (string) realpath( (string) ( $uploads['basedir'] ?? '' ) ) : '';
		if ( '' === $baseDir ) {
			\WP_CLI::error( 'The uploads directory is not available.' );
		}

		$saveTo = isset( $assoc['save'] ) ? $this->checkedSavePath( (string) $assoc['save'], $baseDir ) : null;
		$prefixes = $this->prefixes( $assoc );

		$cleaner = new AttachmentCleaner( $baseDir );
		$cleaner->onProgress( static function ( string $message ): void {
			// On the error output, so that the answer (text or json) stays alone on the standard output.
			fwrite( STDERR, $message . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} );
		try {
			$plan = $cleaner->plan( $prefixes, $duplicates, $missing );
		} catch ( RuntimeException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}

		$counts   = [ 'duplicates' => count( $plan['duplicates']['items'] ), 'missing' => count( $plan['missing']['items'] ) ];
		$expected = [];
		$problems = 0;
		foreach ( [ 'duplicates', 'missing' ] as $category ) {
			if ( isset( $assoc[ 'expect-' . $category ] ) ) {
				$expected[ $category ] = (int) $assoc[ 'expect-' . $category ];
				if ( $expected[ $category ] !== $counts[ $category ] ) {
					++$problems;
				}
			}
		}

		$run     = gmdate( 'YmdHis' ) . '-' . substr( md5( uniqid( '', true ) ), 0, 4 );
		$removed = [];
		$refused = [];
		$saved   = null;
		$applied = false;
		if ( $apply && 0 === $problems ) {
			$applied = true;
			if ( $counts['duplicates'] + $counts['missing'] > 0 ) {
				$this->save( $saveTo, $run, $plan );
				$saved   = $saveTo;
				$removed = $cleaner->apply( $plan );
				if ( count( $removed ) !== $counts['duplicates'] + $counts['missing'] ) {
					++$problems;
				}
				$refused = $cleaner->refused();
			}
		}

		$report = [
			'run'        => $run,
			'applied'    => $applied,
			'saved'      => $saved,
			'prefixes'   => $prefixes,
			'scanned'    => $plan['scanned'],
			'ignored'    => (object) $plan['ignored'],
			'duplicates' => [ 'count' => $counts['duplicates'], 'groups' => $plan['duplicates']['groups'], 'parents_differ' => $plan['duplicates']['parents_differ'], 'items' => $plan['duplicates']['items'] ],
			'missing'    => [ 'count' => $counts['missing'], 'items' => $plan['missing']['items'] ],
			'expected'   => (object) $expected,
			'removed'    => $removed,
			'refused'    => (object) $refused,
			'problems'   => $problems,
		];

		if ( $json ) {
			\WP_CLI::line( (string) wp_json_encode( $report ) );
		} else {
			$this->print( $report, $counts, $expected );
		}
		if ( $problems > 0 ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * @param array<string, mixed> $assoc
	 * @return string[]
	 */
	private function prefixes( array $assoc ): array {
		if ( ! isset( $assoc['source'] ) && ! isset( $assoc['prefix'] ) ) {
			\WP_CLI::error( 'Give --source or --prefix: the whole library is never a default (--prefix=. for all of it).' );
		}

		$prefixes = [];
		try {
			foreach ( array_filter( array_map( 'trim', explode( ',', (string) ( $assoc['prefix'] ?? '' ) ) ), 'strlen' ) as $prefix ) {
				if ( '.' === $prefix ) {
					return [];
				}
				$prefixes[] = PathMap::clean( $prefix );
			}

			if ( isset( $assoc['source'] ) ) {
				$catalog = new SourceCatalog(
					new ExternalSourceSettings(
						static fn(): mixed => get_option( ExternalSourceSettings::optionKey(), [] ),
						static function ( array $value ): void {},
						static fn(): ?string => AllowedBase::resolve(),
						static fn(): ?string => ThumbnailCache::directory()
					),
					AllowedBase::resolve()
				);
				$sources = $catalog->sources();
				foreach ( array_filter( array_map( 'trim', explode( ',', (string) $assoc['source'] ) ), 'strlen' ) as $reference ) {
					$root = AllowedBase::toRelative( AllowedBase::resolve(), (string) ( $sources[ $catalog->find( $sources, $reference ) ]['root'] ?? '' ) );
					if ( '.' === $root ) {
						return [];
					}
					$prefixes[] = PathMap::clean( $root );
				}
			}
		} catch ( InvalidArgumentException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}

		return array_values( array_unique( $prefixes ) );
	}

	/**
	 * A new file, in a directory that exists and is writable, outside the uploads directory (which is public).
	 */
	private function checkedSavePath( string $file, string $baseDir ): string {
		$directory = realpath( dirname( $file ) );
		if ( false === $directory || ! is_dir( $directory ) || ! is_writable( $directory ) ) {
			\WP_CLI::error( 'The directory of --save does not exist or is not writable.' );
		}
		if ( $directory === $baseDir || str_starts_with( $directory . '/', $baseDir . '/' ) ) {
			\WP_CLI::error( 'Do not save the list inside the uploads directory: it is public.' );
		}
		if ( file_exists( $file ) ) {
			\WP_CLI::error( 'The --save file already exists: it is not overwritten.' );
		}

		return $directory . '/' . basename( $file );
	}

	/**
	 * @param array<string, mixed> $plan
	 */
	private function save( ?string $file, string $run, array $plan ): void {
		$items = [];
		foreach ( [ 'duplicates', 'missing' ] as $category ) {
			foreach ( $plan[ $category ]['items'] as $item ) {
				$items[] = array_merge( [ 'category' => $category ], $item );
			}
		}
		$data = [ 'run' => $run, 'time' => gmdate( 'c' ), 'site' => home_url(), 'prefixes' => $plan['prefixes'], 'items' => $items ];
		if ( null === $file || false === file_put_contents( $file, (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			\WP_CLI::error( 'The list could not be saved: nothing was removed.' );
		}
		chmod( $file, 0600 );
	}

	/**
	 * @param array<string, mixed> $report
	 * @param array<string, int>   $counts
	 * @param array<string, int>   $expected
	 */
	private function print( array $report, array $counts, array $expected ): void {
		$apply = (bool) $report['applied'];
		foreach ( (array) $report['ignored'] as $type => $count ) {
			\WP_CLI::line( sprintf( 'Ignored, not attachments: %d posts of type %s.', $count, $type ) );
		}
		\WP_CLI::line( sprintf( 'Attachments looked at: %d', $report['scanned'] ) );
		\WP_CLI::line( sprintf( 'Duplicates %s: %d (in %d groups)', $apply ? 'removed' : 'to remove', $counts['duplicates'], count( $report['duplicates']['groups'] ) ) );
		foreach ( $report['duplicates']['parents_differ'] as $group ) {
			\WP_CLI::warning( sprintf( 'Same path, different parents, not removed: %s (ids %s)', $group['path'], implode( ', ', $group['ids'] ) ) );
		}
		\WP_CLI::line( sprintf( 'Missing files %s: %d', $apply ? 'removed' : 'to remove', $counts['missing'] ) );
		$used = 0;
		foreach ( $report['missing']['items'] as $item ) {
			$used += [] !== $item['used']['featured'] || [] !== $item['used']['content'] ? 1 : 0;
		}
		if ( $used > 0 ) {
			\WP_CLI::line( sprintf( '  of which used (featured image or in a content): %d', $used ) );
		}
		foreach ( $expected as $category => $number ) {
			if ( $number !== $counts[ $category ] ) {
				\WP_CLI::warning( sprintf( 'Expected %d %s, found %d: nothing was changed.', $number, $category, $counts[ $category ] ) );
			}
		}
		foreach ( (array) $report['refused'] as $id => $reason ) {
			\WP_CLI::warning( sprintf( 'Not removed, an attachment method refused: #%d (%s)', $id, $reason ) );
		}
		if ( null !== $report['saved'] ) {
			\WP_CLI::line( sprintf( 'List saved in %s (run %s)', $report['saved'], $report['run'] ) );
		}
		if ( ! $apply && 0 === $report['problems'] ) {
			\WP_CLI::warning( 'Nothing was changed. Add --yes (and --save=<file>) to remove.' );
		}
	}
}
