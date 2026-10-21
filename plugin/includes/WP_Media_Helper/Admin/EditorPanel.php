<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace WP_Media_Helper\Admin;

use WP_Media_Helper\Attachment\Methods;
use WP_Media_Helper\MediaSource\FileTypes;

class EditorPanel {

	public function __construct() {
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueueAssets' ] );
	}

	public function enqueueAssets(): void {
		wp_enqueue_script(
			'wp-media-helper-editor-panel',
			plugins_url( 'assets/js/editor-media-panel.js', WP_MEDIA_HELPER_FILE ),
			[ 'wp-block-editor', 'wp-components', 'wp-data', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-hooks', 'wp-i18n', 'wp-plugins' ],
			wp_media_helper_asset_version( 'assets/js/editor-media-panel.js' ),
			true
		);

		$post   = get_post();
		$postId = $post instanceof \WP_Post ? (int) $post->ID : 0;
		$config = [
			'nonce' => wp_create_nonce( 'wp_media_helper_media_panel' ),
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'sourceId' => '',
			'canUpload' => current_user_can( 'upload_files' ),
			// The types that can be inserted in the content: a GPX track only when a site gives a block for it.
			'insertTypes' => has_filter( 'wp_media_helper_insert_gpx_block' ) ? [ 'image', 'video', 'subtitles', 'gps' ] : [ 'image', 'video', 'subtitles' ],
			'canTrash' => TrashedFiles::canTrash(),
			'canSeeTrash' => TrashedFiles::canSeeTrash(),
			'fileTypes' => self::fileTypes(),
			'date' => current_time( 'Y-m-d' ),
			// The day chosen for this post, as stored: the panel does not read it from the editor, whose saves could write an old one back.
			'postDate' => $postId > 0 ? PostDateMeta::clean( get_post_meta( $postId, PostDateMeta::META_KEY, true ) ) : '',
			'panelMode' => EditorMediaController::normalizePanelMode( get_user_meta( get_current_user_id(), EditorMediaController::panelModeMetaKey, true ) ),
			'attachmentMethod' => EditorMediaController::methodForPanel( Methods::active( $postId ), Methods::cohabitation( $postId ) ),
		];

		/**
		 * Filters the data given to the script of the panel (slice 042).
		 *
		 * @param array<string, mixed> $config The data.
		 * @param int                  $postId The post being edited, 0 when there is none yet.
		 */
		$filtered = apply_filters( 'wp_media_helper_panel_config', $config, $postId );

		wp_localize_script( 'wp-media-helper-editor-panel', 'wpMediaHelperEditorPanel', is_array( $filtered ) ? $filtered : $config );

		wp_set_script_translations(
			'wp-media-helper-editor-panel',
			'wp-media-helper',
			plugin_dir_path( WP_MEDIA_HELPER_FILE ) . 'languages'
		);
	}

	/**
	 * @return array<int, array{value:string, label:string, icon:string}>
	 */
	private static function fileTypes(): array {
		$labels = FileTypes::labels();
		$icons  = FileTypes::icons();
		$types  = [];
		foreach ( FileTypes::categories() as $category ) {
			$types[] = [
				'value' => $category,
				'label' => $labels[ $category ] ?? ucfirst( $category ),
				'icon'  => $icons[ $category ] ?? 'media-default',
			];
		}

		return $types;
	}
}
