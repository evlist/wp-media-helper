<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Maintenance;

/**
 * What the registry of orphans says, as plain text for the Site Health test and for the notice of the admin pages
 * (the callers escape it).
 */
final class OrphanReport {

	/**
	 * @param array<string, mixed> $state The state of the `OrphanRegistry`.
	 * @return array{status:string, label:string, paragraphs:string[], problem:bool}
	 */
	public static function health( array $state, int $now ): array {
		$open        = self::open( $state );
		$unavailable = (array) ( $state['unavailable'] ?? [] );
		$paragraphs  = [];

		if ( [] !== $unavailable ) {
			$paragraphs[] = sprintf(
				/* translators: %s: list of directories. */
				__( 'These directories of the media library could not be checked, because they are not readable, empty, or none of their attachments has its file (a directory that is not mounted?): %s. Their attachments are not reported as missing.', 'wp-media-helper' ),
				implode( ', ', array_keys( $unavailable ) )
			);
		}
		if ( $open > 0 ) {
			$paragraphs[] = sprintf(
				/* translators: %d: number of attachments. */
				_n( '%d attachment of the media library has no file: its image is broken wherever it is used.', '%d attachments of the media library have no file: their images are broken wherever they are used.', $open, 'wp-media-helper' ),
				$open
			);
		}

		if ( $open > 0 ) {
			$label = sprintf(
				/* translators: %d: number of attachments. */
				_n( '%d attachment of the media library has lost its file', '%d attachments of the media library have lost their file', $open, 'wp-media-helper' ),
				$open
			);
		} elseif ( [] !== $unavailable ) {
			$label = __( 'Some media directories could not be checked', 'wp-media-helper' );
		} else {
			$label        = __( 'Every attachment of the media library has its file', 'wp-media-helper' );
			$paragraphs[] = (int) ( $state['checked_at'] ?? 0 ) > 0
				? sprintf(
					/* translators: %d: number of attachments checked. */
					__( 'Checked: %d attachments.', 'wp-media-helper' ),
					(int) ( $state['scanned'] ?? 0 )
				)
				: __( 'The check has not run yet: it runs every day.', 'wp-media-helper' );
		}

		return [
			'status'     => $open > 0 || [] !== $unavailable ? 'recommended' : 'good',
			'label'      => $label,
			'paragraphs' => $paragraphs,
			'problem'    => $open > 0 || [] !== $unavailable,
		];
	}

	/**
	 * @param array<string, mixed> $state
	 * @return string[] The paragraphs of the notice; none when there is nothing to say.
	 */
	public static function notice( array $state ): array {
		$report = self::health( $state, 0 );

		return $report['problem'] ? $report['paragraphs'] : [];
	}

	/**
	 * Orphans that are not ignored.
	 *
	 * @param array<string, mixed> $state
	 */
	public static function open( array $state ): int {
		return count( array_diff( array_map( 'intval', array_keys( (array) ( $state['ids'] ?? [] ) ) ), array_map( 'intval', (array) ( $state['ignored'] ?? [] ) ) ) );
	}
}
