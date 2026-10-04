<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Index;

use WP_Media_Helper\MediaSource\UploadsPath;

/**
 * Converts between file-system paths and the keys stored in the index.
 *
 * A key is the path relative to the uploads directory, the same value WordPress
 * keeps in `_wp_attached_file`, so the index holds no server path and stays valid
 * when the site moves. A path outside the uploads directory is kept absolute.
 */
class KeyMapper {

	private string $basedir;

	public function __construct( string $basedir ) {
		$this->basedir = rtrim( UploadsPath::normalize( $basedir ), '/' );
	}

	public function key( string $absolutePath ): string {
		$relative = '' === $this->basedir ? null : UploadsPath::relativeKey( $absolutePath, $this->basedir );

		return $relative ?? rtrim( UploadsPath::normalize( $absolutePath ), '/' );
	}

	public function absolute( string $key ): string {
		if ( str_starts_with( $key, '/' ) || 1 === preg_match( '#^[A-Za-z]:/#', $key ) ) {
			return $key;
		}

		return $this->basedir . '/' . $key;
	}
}
