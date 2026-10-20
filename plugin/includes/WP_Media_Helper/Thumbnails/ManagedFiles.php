<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Thumbnails;

/**
 * Which files the thumbnails are managed for (slice 023): the files of the attachments registered by this plugin, and any file under the
 * directory of an active source, whichever tool registered its attachment. This is about the sizes only (the file made in the cache, the
 * entry of the metadata): being *managed* gives no right to remove anything, which stays with the attachments *owned* by the plugin.
 */
final class ManagedFiles {

	/**
	 * @param string[] $roots Absolute directories of the active sources.
	 */
	public static function isUnderRoots( string $file, array $roots ): bool {
		$file = self::normalize( $file );
		if ( '' === $file || in_array( '..', explode( '/', $file ), true ) ) {
			return false;
		}
		foreach ( $roots as $root ) {
			$root = rtrim( self::normalize( $root ), '/' );
			if ( '' !== $root && 0 === strpos( $file, $root . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	private static function normalize( string $path ): string {
		return preg_replace( '#/+#', '/', str_replace( '\\', '/', $path ) ) ?? '';
	}
}
