<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\MediaSource;

/**
 * What the plugin knows about file types, in one place: the category of a file (for the panel
 * filter and its icon), the extensions that are never listed, and the types that an
 * administrator may add to those WordPress accepts.
 */
final class FileTypes {

	public const OTHER = 'other';

	/**
	 * Category => extensions. The order is the order of the panel filter.
	 */
	private const CATEGORIES = [
		'image'     => [ 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'avif', 'heic', 'heif', 'tif', 'tiff', 'bmp', 'dng', 'raw', 'cr2', 'cr3', 'nef', 'arw', 'orf', 'rw2' ],
		'video'     => [ 'mp4', 'mov', 'webm', 'avi', 'm4v', 'mkv', 'mpg', 'mpeg', '3gp', 'wmv', 'ogv' ],
		'audio'     => [ 'mp3', 'm4a', 'wav', 'ogg', 'oga', 'flac', 'aac', 'opus', 'wma' ],
		'document'  => [ 'pdf', 'doc', 'docx', 'odt', 'rtf', 'txt', 'xls', 'xlsx', 'ods', 'csv', 'ppt', 'pptx', 'odp', 'epub' ],
		'subtitles' => [ 'vtt', 'srt', 'ass', 'ssa', 'sub' ],
		'gps'       => [ 'gpx', 'kml', 'kmz', 'fit', 'tcx' ],
		'archive'   => [ 'zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2' ],
	];

	/**
	 * Extensions that can run code, on the server or in a visitor's browser: never importable, whatever
	 * the settings or the `upload_mimes` filter say.
	 */
	private const DANGEROUS = [
		'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'inc',
		'js', 'mjs', 'html', 'htm', 'xhtml', 'shtml', 'svg', 'swf', 'xml', 'xsl', 'xslt',
		'exe', 'msi', 'bat', 'cmd', 'com', 'scr', 'dll', 'sh', 'bash', 'cgi', 'pl', 'py', 'rb', 'jsp', 'asp', 'aspx',
		'htaccess', 'htpasswd', 'ini', 'user',
	];

	/**
	 * Extensions not listed unless the settings say otherwise.
	 */
	public const DEFAULT_IGNORED = [ 'tmp', 'part', 'db', 'ini', 'lock', 'bak', 'crdownload' ];

	/**
	 * @return string[] Category identifiers, `other` last.
	 */
	public static function categories(): array {
		$categories = array_keys( self::map() );
		$categories[] = self::OTHER;

		return $categories;
	}

	/**
	 * @return array<string, string> Category => label.
	 */
	public static function labels(): array {
		return [
			'image'     => __( 'Images', 'wp-media-helper' ),
			'video'     => __( 'Videos', 'wp-media-helper' ),
			'audio'     => __( 'Audio', 'wp-media-helper' ),
			'document'  => __( 'Documents', 'wp-media-helper' ),
			'subtitles' => __( 'Subtitles', 'wp-media-helper' ),
			'gps'       => __( 'GPS tracks', 'wp-media-helper' ),
			'archive'   => __( 'Archives', 'wp-media-helper' ),
			self::OTHER => __( 'Other', 'wp-media-helper' ),
		];
	}

	/**
	 * @return array<string, string> Category => dashicon.
	 */
	public static function icons(): array {
		return [
			'image'     => 'format-image',
			'video'     => 'video-alt3',
			'audio'     => 'format-audio',
			'document'  => 'media-document',
			'subtitles' => 'media-text',
			'gps'       => 'location-alt',
			'archive'   => 'media-archive',
			self::OTHER => 'media-default',
		];
	}

	public static function category( string $filename ): string {
		$extension = self::extension( $filename );
		foreach ( self::map() as $category => $extensions ) {
			if ( in_array( $extension, $extensions, true ) ) {
				return $category;
			}
		}

		return self::OTHER;
	}

	public static function extension( string $filename ): string {
		return strtolower( pathinfo( basename( str_replace( '\\', '/', $filename ) ), PATHINFO_EXTENSION ) );
	}

	/**
	 * Whether the extension can run code: such a type is never added and never imported.
	 */
	public static function isDangerous( string $extension ): bool {
		return in_array( strtolower( ltrim( $extension, '.' ) ), self::DANGEROUS, true );
	}

	/**
	 * Parses the "extra types" setting: one `extension mime/type` per line. Returns the accepted types
	 * and one message per line that was refused.
	 *
	 * @return array{types:array<string, string>, errors:string[]}
	 */
	public static function parseAdditionalTypes( string $text ): array {
		$types  = [];
		$errors = [];
		foreach ( preg_split( '/\R/', $text ) ?: [] as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}

			$parts = preg_split( '/[\s=]+/', $line, -1, PREG_SPLIT_NO_EMPTY ) ?: [];
			if ( 2 !== count( $parts ) ) {
				/* translators: %s: the line that was written. */
				$errors[] = sprintf( __( '“%s”: write an extension and a MIME type, for example “gpx application/gpx+xml”.', 'wp-media-helper' ), $line );
				continue;
			}

			$extension = strtolower( ltrim( $parts[0], '.' ) );
			$mime      = strtolower( $parts[1] );
			if ( 1 !== preg_match( '/^[a-z0-9]{1,12}$/', $extension ) ) {
				/* translators: %s: the extension that was written. */
				$errors[] = sprintf( __( '“%s” is not a valid extension (letters and digits only).', 'wp-media-helper' ), $parts[0] );
			} elseif ( self::isDangerous( $extension ) ) {
				/* translators: %s: the extension that was written. */
				$errors[] = sprintf( __( '“%s” can run code and can never be imported.', 'wp-media-helper' ), $extension );
			} elseif ( 1 !== preg_match( '#^[a-z0-9][a-z0-9!\#$&^_.+-]*/[a-z0-9][a-z0-9!\#$&^_.+-]*$#', $mime ) || self::isDangerousMime( $mime ) ) {
				/* translators: %s: the MIME type that was written. */
				$errors[] = sprintf( __( '“%s” is not an accepted MIME type.', 'wp-media-helper' ), $parts[1] );
			} else {
				$types[ $extension ] = $mime;
			}
		}

		return [ 'types' => $types, 'errors' => $errors ];
	}

	/**
	 * Parses a list of extensions separated by spaces, commas or lines. Anything that is not a plain extension is dropped.
	 *
	 * @return string[]
	 */
	public static function parseExtensions( string $text ): array {
		$list = [];
		foreach ( preg_split( '/[\s,;]+/', strtolower( $text ), -1, PREG_SPLIT_NO_EMPTY ) ?: [] as $item ) {
			$item = ltrim( $item, '.*' );
			if ( 1 === preg_match( '/^[a-z0-9]{1,12}$/', $item ) ) {
				$list[ $item ] = true;
			}
		}

		return array_keys( $list );
	}

	/**
	 * Takes the dangerous types out of a `upload_mimes` list (the keys are `jpg|jpeg` style patterns).
	 *
	 * @param array<string, string> $mimes
	 * @return array<string, string>
	 */
	public static function withoutDangerous( array $mimes ): array {
		foreach ( array_keys( $mimes ) as $pattern ) {
			foreach ( explode( '|', (string) $pattern ) as $extension ) {
				if ( self::isDangerous( $extension ) ) {
					unset( $mimes[ $pattern ] );
					break;
				}
			}
		}

		return $mimes;
	}

	/**
	 * Why a file cannot be imported, or null when it can.
	 *
	 * @param array{ext:string|false, type:string|false} $check Result of wp_check_filetype().
	 */
	public static function importBlocker( string $filename, array $check ): ?string {
		if ( self::isDangerous( self::extension( $filename ) ) ) {
			return __( 'This type of file can run code and is never imported.', 'wp-media-helper' );
		}

		if ( empty( $check['type'] ) ) {
			return __( 'WordPress does not accept this file type. An administrator can add it in the plugin settings.', 'wp-media-helper' );
		}

		return null;
	}

	private static function isDangerousMime( string $mime ): bool {
		return 1 === preg_match( '#^(text/html|application/(x-)?(php|httpd-php|javascript|x-javascript|xhtml\+xml|x-sh|x-msdownload|x-executable)|text/(javascript|x-php))#', $mime );
	}

	/**
	 * @return array<string, string[]>
	 */
	private static function map(): array {
		$map = self::CATEGORIES;
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the file categories: category => list of extensions.
			 *
			 * @param array<string, string[]> $map
			 */
			$filtered = apply_filters( 'wp_media_helper_file_categories', $map );
			if ( is_array( $filtered ) ) {
				$map = array_filter( $filtered, static fn ( $extensions, $key ): bool => is_string( $key ) && self::OTHER !== $key && is_array( $extensions ), ARRAY_FILTER_USE_BOTH );
			}
		}

		return $map;
	}
}
