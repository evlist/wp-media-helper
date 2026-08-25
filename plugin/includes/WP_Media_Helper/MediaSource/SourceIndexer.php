<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\MediaSource;

use DateTimeInterface;
use InvalidArgumentException;

class SourceIndexer {

	/**
	 * Resolves a target directory for a source and date, then returns the matching files.
	 *
	 * @param array<string, mixed> $source
	 * @return string[]
	 */
	public function findForDate( array $source, DateTimeInterface $date, ?string $context = null ): array {
		$root = trim( (string) ( $source['root'] ?? '' ) );
		if ( '' === $root ) {
			throw new InvalidArgumentException( 'Source root is required.' );
		}

		$pathPattern = trim( (string) ( $source['path_pattern'] ?? '' ) );
		$filterPattern = trim( (string) ( $source['filter_pattern'] ?? '' ) );
		$sourceId = (string) ( $context ?? $source['id'] ?? '' );

		$resolver = new DatePatternResolver();
		$directory = $resolver->resolvePath( $root, $pathPattern, $date, $sourceId );
		$filter = '' === $filterPattern ? '' : $resolver->resolveFilter( $filterPattern, $date, $sourceId );

		$files = ( new FilesystemScanner() )->find( $directory, $filter );
		sort( $files, SORT_STRING );

		return $files;
	}
}
