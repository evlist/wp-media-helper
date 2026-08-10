<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\MediaSource;

class FilesystemScanner {

	/**
	 * Returns absolute paths of files under $root whose basename contains $filter.
	 *
	 * @param string $root   Absolute path to the directory tree to scan.
	 * @param string $filter Substring that must appear in the filename.
	 * @return string[]
	 */
	public function find( string $root, string $filter ): array {
		if ( ! is_dir( $root ) ) {
			return [];
		}

		$results  = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && str_contains( $file->getFilename(), $filter ) ) {
				$results[] = $file->getPathname();
			}
		}

		return $results;
	}
}
