<?php
// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Server-side rendering of the block `wp-media-helper/gallery`.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo \WP_Media_Helper\Blocks\GalleryBlock::render( is_array( $attributes ) ? $attributes : [], isset( $block->context['postId'] ) ? (int) $block->context['postId'] : 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the markup of the gallery of WordPress and of the wrapper of the block.
