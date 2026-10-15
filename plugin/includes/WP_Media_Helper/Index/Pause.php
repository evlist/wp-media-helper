<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Index;

/**
 * Holds the background indexing still, for example while the paths of the files are rewritten.
 *
 * Only the indexing stops (the cron passes, the re-scan buttons and the quick scan of the
 * panel). The panel, the imports, the trash and the gallery keep working on the index as it is.
 * The constant `WP_MEDIA_HELPER_PAUSED` forces the pause for sites controlled by code; the option
 * survives restarts and is not cleared by a reset.
 */
final class Pause {

	public const OPTION = 'wp_media_helper_index_paused';

	public static function isPaused(): bool {
		return self::isForcedByConstant() || self::isSetByOption();
	}

	public static function isForcedByConstant(): bool {
		return defined( 'WP_MEDIA_HELPER_PAUSED' ) && (bool) constant( 'WP_MEDIA_HELPER_PAUSED' );
	}

	public static function isSetByOption(): bool {
		return '1' === (string) get_option( self::OPTION, '' );
	}

	public static function pause(): void {
		update_option( self::OPTION, '1', false );
	}

	/**
	 * Clears the option and asks for the passes that are due. A constant stays in force.
	 */
	public static function resume(): void {
		delete_option( self::OPTION );
		if ( ! self::isPaused() ) {
			Cron::schedule();
		}
	}
}
