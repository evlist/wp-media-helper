<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Settings;

/**
 * Name date patterns for common cameras, phones and apps, offered when editing a source.
 *
 * A first list from usual conventions, to be completed from real file names. The generic
 * recogniser (a date such as `20261002_121549` or `2026-10-02` anywhere in the name) is
 * always tried last, so most of these only matter to pin down a convention.
 */
final class NamePatternPresets {

	/**
	 * @return array<string, array{label:string, patterns:string[]}>
	 */
	public static function all(): array {
		return [
			'android'     => [ 'label' => __( 'Android camera (IMG_/VID_)', 'wp-media-helper' ), 'patterns' => [ 'IMG_{date:Ymd}_{date:His}', 'VID_{date:Ymd}_{date:His}' ] ],
			'pixel'       => [ 'label' => __( 'Pixel (PXL_)', 'wp-media-helper' ), 'patterns' => [ 'PXL_{date:Ymd}_{date:His}{date:v}' ] ],
			'samsung'     => [ 'label' => __( 'Samsung and others (date_time)', 'wp-media-helper' ), 'patterns' => [ '{date:Ymd}_{date:His}' ] ],
			'screenshots' => [ 'label' => __( 'Screenshots', 'wp-media-helper' ), 'patterns' => [ 'Screenshot_{date:Ymd}-{date:His}', 'Screenshot {date:Y-m-d} at {date:H.i.s}' ] ],
			'whatsapp'    => [ 'label' => __( 'WhatsApp (date only)', 'wp-media-helper' ), 'patterns' => [ '{date:Ymd}-WA' ] ],
			'separators'  => [ 'label' => __( 'Dates with separators', 'wp-media-helper' ), 'patterns' => [ '{date:Y-m-d}_{date:H-i-s}', '{date:Y-m-d} {date:H.i.s}', '{date:Y-m-d}' ] ],
			'any'         => [ 'label' => __( 'Any date, optional time', 'wp-media-helper' ), 'patterns' => [ '{date:Ymd}[_{date:His}]' ] ],
		];
	}
}
