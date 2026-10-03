<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

use WP_Media_Helper\MediaSource\UploadsPath;
use WP_Media_Helper\Settings\ExternalSourceSettings;

/**
 * Encodes the URL of attachments whose files live in a configured source.
 *
 * Files are never renamed, so a file name with a space, `#`, `?`, `%` or a
 * non-ASCII character reaches WordPress as is, and core appends it to the
 * uploads URL without encoding it.
 */
class AttachmentUrls {

	/**
	 * @var string[]|null
	 */
	private ?array $roots = null;

	public function __construct() {
		add_filter( 'wp_get_attachment_url', [ $this, 'encode' ], 20, 2 );
	}

	/**
	 * @param mixed $url
	 * @param int   $attachmentId
	 * @return mixed
	 */
	public function encode( $url, $attachmentId ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return $url;
		}

		$relative = get_post_meta( (int) $attachmentId, '_wp_attached_file', true );
		if ( ! is_string( $relative ) || '' === $relative || str_starts_with( $relative, '/' ) || ! UploadsPath::needsEncoding( $relative ) ) {
			return $url;
		}

		$uploads = wp_upload_dir( null, false );
		if ( ! is_array( $uploads ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return $url;
		}

		// A URL already changed by another filter (CDN, other host) is left alone.
		$raw = rtrim( (string) $uploads['baseurl'], '/' ) . '/' . $relative;
		if ( $url !== $raw && ! str_starts_with( $url, rtrim( (string) $uploads['baseurl'], '/' ) . '/' ) ) {
			return $url;
		}

		if ( ! $this->isInConfiguredSource( rtrim( (string) $uploads['basedir'], '/\\' ) . '/' . $relative ) ) {
			return $url;
		}

		return UploadsPath::url( (string) $uploads['baseurl'], $relative );
	}

	private function isInConfiguredSource( string $absolutePath ): bool {
		$path = UploadsPath::normalize( $absolutePath );
		foreach ( $this->roots() as $root ) {
			if ( str_starts_with( $path, $root . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return string[]
	 */
	private function roots(): array {
		if ( null === $this->roots ) {
			$this->roots = [];
			$settings = new ExternalSourceSettings(
				static fn(): mixed => get_option( ExternalSourceSettings::optionKey(), [] ),
				static function ( array $value ): void {}
			);
			try {
				$sources = $settings->getAll();
			} catch ( \InvalidArgumentException $exception ) {
				$sources = [];
			}
			foreach ( $sources as $source ) {
				$root = is_array( $source ) ? trim( (string) ( $source['root'] ?? '' ) ) : '';
				if ( '' !== $root ) {
					$this->roots[] = rtrim( UploadsPath::normalize( $root ), '/' );
					$real = realpath( $root );
					if ( false !== $real ) {
						$this->roots[] = rtrim( UploadsPath::normalize( $real ), '/' );
					}
				}
			}
			$this->roots = array_values( array_unique( $this->roots ) );
		}

		return $this->roots;
	}
}
