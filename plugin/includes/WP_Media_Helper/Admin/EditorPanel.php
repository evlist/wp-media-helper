<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

class EditorPanel {

	public function __construct() {
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueueAssets' ] );
	}

	public function enqueueAssets(): void {
		wp_enqueue_script(
			'wp-media-helper-editor-panel',
			plugins_url( 'assets/js/editor-media-panel.js', WP_MEDIA_HELPER_FILE ),
			[ 'wp-components', 'wp-data', 'wp-edit-post', 'wp-element', 'wp-i18n', 'wp-plugins' ],
			WP_MEDIA_HELPER_VERSION,
			true
		);

		wp_localize_script(
			'wp-media-helper-editor-panel',
			'wpMediaHelperEditorPanel',
			[
				'nonce' => wp_create_nonce( 'wp_media_helper_media_panel' ),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'sourceId' => '',
				'date' => current_time( 'Y-m-d' ),
			]
		);

		wp_set_script_translations(
			'wp-media-helper-editor-panel',
			'wp-media-helper',
			plugin_dir_path( WP_MEDIA_HELPER_FILE ) . 'languages'
		);
	}
}
