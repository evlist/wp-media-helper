<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Settings;

/**
 * The state of a configured source.
 *
 * - active:   its files are listed and can be imported, and it owns its tree;
 * - disabled: ignored as if it did not exist, its files fall to the following sources;
 * - excluded: lists nothing, but owns its tree so no other source lists its files.
 */
final class SourceState {

	public const ACTIVE   = 'active';
	public const DISABLED = 'disabled';
	public const EXCLUDED = 'excluded';

	/**
	 * @return string[]
	 */
	public static function all(): array {
		return [ self::ACTIVE, self::DISABLED, self::EXCLUDED ];
	}

	/**
	 * The state of a stored or submitted source. A source saved before the states
	 * existed has only an `enabled` flag: enabled is active, otherwise disabled.
	 *
	 * @param array<string, mixed> $source
	 */
	public static function of( array $source ): string {
		$state = $source['state'] ?? null;
		if ( is_string( $state ) && in_array( $state, self::all(), true ) ) {
			return $state;
		}

		return filter_var( $source['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN ) ? self::ACTIVE : self::DISABLED;
	}
}
