<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Attachment;

/**
 * The fields that a method declares for the data of a link between a post and a media item
 * (slice 042). Media Helper draws the controls from the declaration and coerces what comes back
 * to the declared types; the method still validates what it stores.
 */
final class Fields {

	public const TYPES = [ 'multiselect', 'select', 'boolean', 'integer', 'text' ];

	/**
	 * The value of the filter of a field for the links that have no value in it ("No mode").
	 */
	public const NONE = '_none';

	/**
	 * The value of the filter for the links made the WordPress way (`post_parent` only), when both mechanisms are in use.
	 */
	public const NATIVE = '_native';

	private const MAX_TEXT = 255;

	/**
	 * Keeps the valid declarations: a `key` (lower case letters, digits, `_` and `-`), a `label`, one of
	 * the types, for the selects a list of `options` (`value`, `label`, and a pictogram: `icon`, a Dashicon name, or `icon_svg`,
	 * the data of an SVG path), and a `default` of the right kind. `filter` makes a select a filter of the panel, with
	 * `filter_default` the values checked at first (every option and `_none`, the links with no value, when not said).
	 *
	 * @param mixed $declared
	 * @return array<int, array{key:string, label:string, type:string, options:array<int, array{value:string, label:string}>, default:mixed}>
	 */
	public static function normalize( $declared ): array {
		$fields = [];
		foreach ( is_array( $declared ) ? $declared : [] as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$key  = isset( $field['key'] ) && is_string( $field['key'] ) ? strtolower( $field['key'] ) : '';
			$type = isset( $field['type'] ) && is_string( $field['type'] ) ? $field['type'] : '';
			if ( 1 !== preg_match( '/^[a-z0-9_-]{1,40}$/', $key ) || ! in_array( $type, self::TYPES, true ) || isset( $fields[ $key ] ) ) {
				continue;
			}

			$options = self::options( $field['options'] ?? [] );
			if ( in_array( $type, [ 'select', 'multiselect' ], true ) && [] === $options ) {
				continue;
			}

			$fields[ $key ] = [
				'key'            => $key,
				'label'          => isset( $field['label'] ) && is_scalar( $field['label'] ) ? (string) $field['label'] : $key,
				'type'           => $type,
				'options'        => $options,
				'default'        => null,
				'filter'         => false,
				'filter_default' => [],
			];
			$fields[ $key ]['default'] = self::coerce( $fields[ $key ], $field['default'] ?? null );

			// A select or multiselect may be a filter of the panel (slice 042): which values are checked at first
			// (every option when not said).
			if ( ! empty( $field['filter'] ) && in_array( $type, [ 'select', 'multiselect' ], true ) ) {
				$all                              = array_merge( array_column( $options, 'value' ), [ self::NONE ] );
				$asked                            = isset( $field['filter_default'] ) && is_array( $field['filter_default'] ) ? array_map( 'strval', array_filter( $field['filter_default'], 'is_scalar' ) ) : $all;
				$fields[ $key ]['filter']         = true;
				$fields[ $key ]['filter_default'] = array_values( array_filter( $all, static fn ( string $value ): bool => in_array( $value, $asked, true ) ) );
			}
		}

		return array_values( $fields );
	}

	/**
	 * The data of a link made of what the browser sent: only the declared keys, each coerced to its type.
	 * With `$useDefaults`, a declared field that was not sent takes its default.
	 *
	 * @param array<int, array<string, mixed>> $fields As returned by `normalize()`.
	 * @param mixed                            $data
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $fields, $data, bool $useDefaults = false ): array {
		$data   = is_array( $data ) ? $data : [];
		$result = [];
		foreach ( $fields as $field ) {
			$key = (string) $field['key'];
			if ( array_key_exists( $key, $data ) ) {
				$result[ $key ] = self::coerce( $field, $data[ $key ] );
			} elseif ( $useDefaults ) {
				$result[ $key ] = $field['default'];
			}
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $field
	 * @param mixed                $value
	 * @return mixed
	 */
	private static function coerce( array $field, $value ) {
		switch ( $field['type'] ) {
			case 'multiselect':
				$asked = is_array( $value ) ? array_map( 'strval', array_filter( $value, 'is_scalar' ) ) : [];

				return array_values( array_filter(
					array_column( $field['options'], 'value' ),
					static fn ( string $option ): bool => in_array( $option, $asked, true )
				) );
			case 'select':
				$asked = is_scalar( $value ) ? (string) $value : '';

				return in_array( $asked, array_column( $field['options'], 'value' ), true ) ? $asked : '';
			case 'boolean':
				return in_array( $value, [ true, 1, '1', 'true', 'on' ], true );
			case 'integer':
				return is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^\s*-?[0-9]{1,9}\s*$/', $value ) ) ? (int) $value : null;
			default:
				$text = is_scalar( $value ) ? trim( preg_replace( '/[\x00-\x1F\x7F]/', ' ', strip_tags( (string) $value ) ) ?? '' ) : '';

				return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_TEXT ) : substr( $text, 0, self::MAX_TEXT );
		}
	}

	/**
	 * A pictogram given by the name of a Dashicon (`admin-site`, `media-document`...): lower case letters, digits and dashes.
	 *
	 * @param mixed $icon
	 */
	private static function icon( $icon ): string {
		return is_string( $icon ) && 1 === preg_match( '/^[a-z0-9-]{1,40}$/', $icon ) ? $icon : '';
	}

	/**
	 * A pictogram given as the `d` attribute of an SVG path drawn in a 24 x 24 box, so the plugin can draw it without
	 * accepting any markup: only the characters of path data.
	 *
	 * @param mixed $path
	 */
	private static function svgPath( $path ): string {
		return is_string( $path ) && 1 === preg_match( '/^[MmLlHhVvCcSsQqTtAaZz0-9\s,.+eE-]{1,1500}$/', $path ) ? trim( $path ) : '';
	}

	/**
	 * @param mixed $options
	 * @return array<int, array{value:string, label:string, icon:string, icon_svg:string}>
	 */
	private static function options( $options ): array {
		$clean = [];
		foreach ( is_array( $options ) ? $options : [] as $option ) {
			if ( is_array( $option ) && isset( $option['value'] ) && is_scalar( $option['value'] ) && '' !== (string) $option['value'] ) {
				$clean[ (string) $option['value'] ] = [
					'value'    => (string) $option['value'],
					'label'    => isset( $option['label'] ) && is_scalar( $option['label'] ) ? (string) $option['label'] : (string) $option['value'],
					'icon'     => self::icon( $option['icon'] ?? '' ),
					'icon_svg' => self::svgPath( $option['icon_svg'] ?? '' ),
				];
			}
		}

		return array_values( $clean );
	}
}
