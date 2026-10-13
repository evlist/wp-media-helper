// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later
( function ( wp ) {
	'use strict';

	const { registerBlockType } = wp.blocks;
	const { useBlockProps } = wp.blockEditor;
	const { __ } = wp.i18n;
	const { createElement } = wp.element;

	registerBlockType( 'wp-media-helper/gallery', {
		edit: function () {
			return createElement( 'p', useBlockProps(),
				__( 'Gallery of the images attached to the post (the standard WordPress gallery).', 'wp-media-helper' )
			);
		},
		save: function () {
			return null;
		},
	} );
}( window.wp ) );
