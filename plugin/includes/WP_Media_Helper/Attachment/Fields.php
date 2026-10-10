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

	private const MAX_TEXT = 255;

	/**
	 * Keeps the valid declarations: a `key` (lower case letters, digits, `_` and `-`), a `label`, one of
	 * the types, for the selects a list of `options` (`value`, `label`), and a `default` of the right kind.
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
				'key'     => $key,
				'label'   => isset( $field['label'] ) && is_scalar( $field['label'] ) ? (string) $field['label'] : $key,
				'type'    => $type,
				'options' => $options,
				'default' => null,
			];
			$fields[ $key ]['default'] = self::coerce( $fields[ $key ], $field['default'] ?? null );
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
	 * @param mixed $options
	 * @return array<int, array{value:string, label:string}>
	 */
	private static function options( $options ): array {
		$clean = [];
		foreach ( is_array( $options ) ? $options : [] as $option ) {
			if ( is_array( $option ) && isset( $option['value'] ) && is_scalar( $option['value'] ) && '' !== (string) $option['value'] ) {
				$clean[ (string) $option['value'] ] = [
					'value' => (string) $option['value'],
					'label' => isset( $option['label'] ) && is_scalar( $option['label'] ) ? (string) $option['label'] : (string) $option['value'],
				];
			}
		}

		return array_values( $clean );
	}
}
