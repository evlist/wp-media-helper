<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\MediaSource;

class FilesystemScanner {

	/**
	 * Returns absolute paths of files under $root whose basename contains $filter.
	 *
	 * Symbolic links are ignored, and every result is verified to resolve below
	 * $root, so a link cannot expose files located elsewhere on the server.
	 *
	 * @param string $root   Absolute path to the directory tree to scan.
	 * @param string $filter Substring that must appear in the filename.
	 * @return string[]
	 */
	public function find( string $root, string $filter ): array {
		if ( ! is_dir( $root ) ) {
			return [];
		}

		$realRoot = realpath( $root );
		if ( false === $realRoot ) {
			return [];
		}

		$results  = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isLink() || ! $file->isFile() || ! str_contains( $file->getFilename(), $filter ) ) {
				continue;
			}

			$realFile = realpath( $file->getPathname() );
			if ( false === $realFile || ! PathConfinement::isWithin( $realRoot, $realFile ) ) {
				continue;
			}

			$results[] = $file->getPathname();
		}

		return $results;
	}
}
