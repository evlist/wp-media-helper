<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Measures the file index on a synthetic tree (slice 025).
 *
 *   php tests/benchmark/index-benchmark.php [directories] [files] [directory]
 *
 * The defaults are the size of the target library: 5,000 directories and 110,000
 * files, organised as year/month/day. The index runs on in-memory SQLite, so the
 * times are only a lower bound for a MySQL server; the number of SQL queries is
 * the portable figure. The tree is created below the system temporary directory
 * (or the directory given) and removed at the end.
 */

require __DIR__ . '/../phpunit/bootstrap.php';

use WP_Media_Helper\Index\IndexScanner;
use WP_Media_Helper\Index\KeyMapper;
use WP_Media_Helper\Index\ScanBudget;
use WP_Media_Helper\Index\WpdbIndexStore;

$directoryCount = (int) ( $argv[1] ?? 5000 );
$fileCount      = (int) ( $argv[2] ?? 110000 );
$parent         = $argv[3] ?? sys_get_temp_dir();
$base           = realpath( $parent ) . '/wpmh_bench_' . uniqid();
$root           = $base . '/photos';

function line( string $label, string $value ): void {
	printf( "| %-58s | %-32s |\n", $label, $value );
}

function removeTree( string $path ): void {
	if ( is_link( $path ) || is_file( $path ) ) {
		unlink( $path );
	} elseif ( is_dir( $path ) ) {
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				removeTree( $path . '/' . $entry );
			}
		}
		rmdir( $path );
	}
}

echo "Building {$directoryCount} directories and {$fileCount} files in {$base}...\n";
$start = microtime( true );
$perDirectory = max( 1, (int) ceil( $fileCount / $directoryCount ) );
$created = 0;
$paths = [];
$day = new DateTimeImmutable( '2013-01-01' );
for ( $i = 0; $i < $directoryCount; $i++ ) {
	$directory = $root . '/' . $day->format( 'Y/m/d' );
	mkdir( $directory, 0755, true );
	$paths[] = $directory;
	for ( $n = 0; $n < $perDirectory && $created < $fileCount; $n++, $created++ ) {
		touch( sprintf( '%s/%s_%06d_%d.jpg', $directory, $day->format( 'Ymd' ), $n * 7, $n ) );
	}
	$day = $day->modify( '+1 day' );
}
// Old modification times, as after a while on a real disk.
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST ) as $item ) {
	if ( $item->isDir() ) {
		touch( $item->getPathname(), time() - 10000 );
	}
}
touch( $root, time() - 10000 );
printf( "Tree built in %.1f s.\n\n", microtime( true ) - $start );

$db = new FakeWpdb();
$GLOBALS['wpdb'] = $db;
$store = new WpdbIndexStore();
$scanner = new IndexScanner( $store, new KeyMapper( $base ), new DateTimeZone( 'Europe/Paris' ) );
$source = [ 'id' => 'bench', 'root' => $root, 'filter_pattern' => '', 'mtime_fallback' => true ];

echo "| Measure                                                    | Result                           |\n";
echo "|------------------------------------------------------------|----------------------------------|\n";

// 1. First scan, split in runs of 1,000 directories (a stand-in for a time budget).
$run = 1;
$runs = 0;
$queries = $db->queries;
$start = microtime( true );
do {
	$result = $scanner->scan( $source, $run, new ScanBudget( 600, 1000 ) );
	++$runs;
} while ( ! $result->complete );
$elapsed = microtime( true ) - $start;
line( 'First scan: total time', sprintf( '%.1f s in %d runs of 1,000 dirs', $elapsed, $runs ) );
line( 'First scan: SQL queries', (string) ( $db->queries - $queries ) );
line( 'Indexed', $store->countFiles( 'bench' ) . ' files, ' . $store->countDirectories( 'bench' ) . ' dirs' );

// 2. Pass with no change.
$queries = $db->queries;
$start = microtime( true );
$result = $scanner->scan( $source, ++$run, new ScanBudget( 600 ) );
line( 'No-change pass: time', sprintf( '%.2f s', microtime( true ) - $start ) );
line( 'No-change pass: directories read / unchanged', $result->directoriesRead . ' / ' . $result->directoriesUnchanged );
line( 'No-change pass: SQL queries', (string) ( $db->queries - $queries ) );

// 3. Files added in one deep directory.
$deep = $paths[ (int) ( $directoryCount / 2 ) ];
for ( $n = 0; $n < 5; $n++ ) {
	touch( $deep . '/added_' . $n . '.jpg' );
}
$queries = $db->queries;
$start = microtime( true );
$result = $scanner->scan( $source, ++$run, new ScanBudget( 600 ) );
line( 'After adding 5 files in a deep directory: time', sprintf( '%.2f s', microtime( true ) - $start ) );
line( '  directories read / files added', $result->directoriesRead . ' / ' . $result->filesAdded );
line( '  SQL queries', (string) ( $db->queries - $queries ) );

// 4. Hint scan of the directories of a day, once indexed.
$queries = $db->queries;
$start = microtime( true );
$hints = [ $paths[100], $paths[101], $paths[102] ];
$result = $scanner->scan( $source, (int) ( microtime( true ) * 1000 ), new ScanBudget( 1 ), false, $hints );
line( 'Hint scan of 3 directories: time', sprintf( '%.3f s', microtime( true ) - $start ) );
line( '  directories unchanged / SQL queries', $result->directoriesUnchanged . ' / ' . ( $db->queries - $queries ) );

// 5. The list of a day.
$day100 = ( new DateTimeImmutable( '2013-01-01' ) )->modify( '+100 day' )->format( 'Y-m-d' );
$queries = $db->queries;
$start = microtime( true );
$rows = $store->filesForDay( [ 'bench' ], $day100 );
line( 'List of one day: time', sprintf( '%.4f s', microtime( true ) - $start ) );
line( '  files / SQL queries', count( $rows ) . ' / ' . ( $db->queries - $queries ) );

// 6. A full pass (weekly): every directory read again.
$queries = $db->queries;
$start = microtime( true );
$result = $scanner->scan( $source, ++$run, new ScanBudget( 600 ), true );
line( 'Full pass: time', sprintf( '%.1f s', microtime( true ) - $start ) );
line( '  directories read / files updated / SQL queries', $result->directoriesRead . ' / ' . $result->filesUpdated . ' / ' . ( $db->queries - $queries ) );

printf( "\nPeak memory: %.0f MB\n", memory_get_peak_usage( true ) / 1048576 );
removeTree( $base );
