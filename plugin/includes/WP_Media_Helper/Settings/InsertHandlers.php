<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Settings;

/**
 * What is inserted in the content for a file type that has no block of its own (slice 051): a template of a block, by extension, written by
 * the administrator in the settings (one line per extension: `gpx {"block": "core/shortcode", "attributes": {"text": "[sgpx gpx=\"{url}\"]"}}`)
 * and changeable in code with the filter `wp_media_helper_insert_handlers`. Photos, videos and subtitles have their own handling and are not
 * affected.
 *
 * In the strings of a template: `{id}` the attachment ID (a number when it is the whole string), `{url}` the address of the file, `{name}` its
 * file name, `{title}` its title, `{mime}` its MIME type.
 */
final class InsertHandlers {

	/**
	 * @return array{handlers: array<string, array<string, mixed>>, errors: string[]}
	 */
	public static function parse( string $text ): array {
		$handlers = [];
		$errors   = [];
		foreach ( preg_split( '/\R/', $text ) ?: [] as $number => $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( 1 !== preg_match( '/^\.?([A-Za-z0-9]+)\s+(\{.*\})$/s', $line, $found ) ) {
				/* translators: %d: line number. */
				$errors[] = sprintf( __( 'Insertion, line %d: write an extension, a space and the template as a JSON object.', 'wp-media-helper' ), $number + 1 );
				continue;
			}
			$template = json_decode( $found[2], true );
			if ( ! is_array( $template ) || ! isset( $template['block'] ) || ! is_string( $template['block'] ) || 1 !== preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $template['block'] ) ) {
				/* translators: %d: line number. */
				$errors[] = sprintf( __( 'Insertion, line %d: the template must be a JSON object with a "block" such as "core/shortcode".', 'wp-media-helper' ), $number + 1 );
				continue;
			}
			if ( isset( $template['attributes'] ) && ! is_array( $template['attributes'] ) ) {
				/* translators: %d: line number. */
				$errors[] = sprintf( __( 'Insertion, line %d: "attributes" must be a JSON object.', 'wp-media-helper' ), $number + 1 );
				continue;
			}
			$handlers[ strtolower( $found[1] ) ] = $template;
		}

		return [ 'handlers' => $handlers, 'errors' => $errors ];
	}

	/**
	 * The text of the settings: what was saved, and, as long as nothing was ever saved, a template for the GPX tracks when the plugin WP GPX
	 * Maps (shortcode `sgpx`) is active, which is the one that shows them.
	 *
	 * @param mixed $stored The general settings, as stored.
	 */
	public static function textOf( $stored ): string {
		if ( is_array( $stored ) && isset( $stored['insert_handlers'] ) && is_string( $stored['insert_handlers'] ) ) {
			return $stored['insert_handlers'];
		}

		return function_exists( 'shortcode_exists' ) && shortcode_exists( 'sgpx' )
			? 'gpx {"block": "core/shortcode", "attributes": {"text": "[sgpx gpx=\\"{url}\\"]"}}'
			: '';
	}

	/**
	 * The handlers in use: those of the settings, then the filter.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		$handlers = self::parse( self::textOf( get_option( GeneralSettings::optionKey(), [] ) ) )['handlers'];

		/**
		 * The templates of the blocks inserted for the files of a type, by extension.
		 *
		 * @param array<string, array<string, mixed>> $handlers `block`, `attributes`, `innerBlocks` (with the placeholders `{id}`, `{url}`, `{name}`, `{title}`, `{mime}`).
		 */
		$filtered = apply_filters( 'wp_media_helper_insert_handlers', $handlers );

		return is_array( $filtered ) ? $filtered : $handlers;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function forExtension( string $extension ): ?array {
		return self::all()[ strtolower( $extension ) ] ?? null;
	}

	/**
	 * The description of the block for a file: the template with its placeholders filled.
	 *
	 * @param array<string, mixed>                                              $template
	 * @param array{id:int, url:string, name:string, title?:string, mime?:string} $file
	 * @return array{block:string, attributes:array<string, mixed>, innerBlocks:array<int, array<string, mixed>>}
	 */
	public static function block( array $template, array $file ): array {
		return [
			'block'       => (string) $template['block'],
			'attributes'  => (array) self::fill( (array) ( $template['attributes'] ?? [] ), $file ),
			'innerBlocks' => array_values( array_map(
				static fn ( $inner ): array => is_array( $inner ) && isset( $inner['block'] ) ? self::block( $inner, $file ) : [ 'block' => 'core/paragraph', 'attributes' => [], 'innerBlocks' => [] ],
				(array) ( $template['innerBlocks'] ?? [] )
			) ),
		];
	}

	/**
	 * @param mixed                $value
	 * @param array<string, mixed> $file
	 * @return mixed
	 */
	private static function fill( $value, array $file ) {
		if ( is_array( $value ) ) {
			return array_map( static fn ( $item ) => self::fill( $item, $file ), $value );
		}
		if ( ! is_string( $value ) ) {
			return $value;
		}
		if ( '{id}' === $value ) {
			return (int) $file['id'];
		}

		return strtr( $value, [
			'{id}'    => (string) $file['id'],
			'{url}'   => (string) $file['url'],
			'{name}'  => (string) $file['name'],
			'{title}' => (string) ( $file['title'] ?? $file['name'] ),
			'{mime}'  => (string) ( $file['mime'] ?? '' ),
		] );
	}
}
