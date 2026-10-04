<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\MediaSource;

use WP_Media_Helper\Settings\GeneralSettings;

/**
 * Adds the file types chosen by an administrator to those WordPress accepts, for every upload of the
 * site and for the files imported by the plugin. Types that can run code are never added.
 */
final class AdditionalTypes {

	public static function register(): void {
		add_filter( 'upload_mimes', [ self::class, 'allow' ], 20 );
		add_filter( 'wp_check_filetype_and_ext', [ self::class, 'trustExtension' ], 20, 5 );
	}

	/**
	 * @param array<string, string> $mimes
	 * @return array<string, string>
	 */
	public static function allow( $mimes ) {
		if ( ! is_array( $mimes ) ) {
			return $mimes;
		}

		foreach ( self::types() as $extension => $mime ) {
			$mimes[ $extension ] = $mime;
		}

		return FileTypes::withoutDangerous( $mimes );
	}

	/**
	 * WordPress compares the type PHP detects in the content with the extension; for a text format such
	 * as GPX or WebVTT it detects `text/plain` or `application/xml` and refuses. For the types added here,
	 * and only for them, the extension is taken as written.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public static function trustExtension( $data, $file = '', $filename = '', $mimes = null, $realMime = '' ) {
		if ( ! is_array( $data ) || ! empty( $data['ext'] ) ) {
			return $data;
		}

		$extension = FileTypes::extension( (string) $filename );
		$types     = self::types();
		if ( isset( $types[ $extension ] ) && ! FileTypes::isDangerous( $extension ) ) {
			$data['ext']  = $extension;
			$data['type'] = $types[ $extension ];
		}

		return $data;
	}

	/**
	 * @return array<string, string>
	 */
	private static function types(): array {
		return ( new GeneralSettings(
			static fn (): mixed => get_option( GeneralSettings::optionKey(), [] ),
			static function ( array $value ): void {}
		) )->getAdditionalTypes();
	}
}
