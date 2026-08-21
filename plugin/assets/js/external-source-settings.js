// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

( function ( wp ) {
	'use strict';

	const { __, sprintf } = wp.i18n;

	const container = document.getElementById( 'wp-media-helper-sources' );
	const addButton = document.getElementById( 'wp-media-helper-add-source' );

	if ( ! container || ! addButton ) {
		return;
	}

	const emptyState = document.getElementById( 'wp-media-helper-no-source' );
	let nextIndex = container.querySelectorAll( '.wp-media-helper-source' ).length;

	// Translations are interpolated into markup, so they are escaped like any other value.
	const esc = function ( value ) {
		const holder = document.createElement( 'span' );
		holder.textContent = value;

		return holder.innerHTML;
	};

	const refreshEmptyState = function () {
		if ( emptyState ) {
			emptyState.hidden = container.querySelectorAll( '.wp-media-helper-source' ).length > 0;
		}
	};

	const buildField = function ( index, key, slug, label, optional, description ) {
		const fieldId = 'wp-media-helper-source-' + slug + '-' + index;
		const optionalTag = optional
			? ' <span class="description">' + esc( __( '(optional)', 'wp-media-helper' ) ) + '</span>'
			: '';

		return `
			<tr>
				<th scope="row"><label for="${ fieldId }">${ esc( label ) }${ optionalTag }</label></th>
				<td>
					<input id="${ fieldId }" type="text" class="regular-text" name="sources[${ index }][${ key }]" value="" aria-describedby="${ fieldId }-description" />
					<p class="description" id="${ fieldId }-description">${ description }</p>
				</td>
			</tr>
		`;
	};

	const buildSourceMarkup = function ( index ) {
		const fields = [
			buildField(
				index,
				'name',
				'name',
				__( 'Name', 'wp-media-helper' ),
				false,
				sprintf(
					/* translators: %s: example source name, wrapped in a code element. */
					esc( __( 'Label used to identify this source in the admin, for example %s.', 'wp-media-helper' ) ),
					'<code>' + esc( __( 'Nextcloud Main', 'wp-media-helper' ) ) + '</code>'
				)
			),
			buildField(
				index,
				'root',
				'root',
				__( 'Root directory', 'wp-media-helper' ),
				false,
				sprintf(
					/* translators: %s: example directory path, wrapped in a code element. */
					esc( __( 'Absolute path to the external media root, for example %s.', 'wp-media-helper' ) ),
					'<code>/var/www/media</code>'
				)
			),
			buildField(
				index,
				'path_pattern',
				'path',
				__( 'Path pattern', 'wp-media-helper' ),
				true,
				sprintf(
					/* translators: %s: example path pattern, wrapped in a code element. */
					esc( __( 'Subdirectory resolved for the requested date, for example %s. Leave empty to use the source root directly.', 'wp-media-helper' ) ),
					'<code>{date:Y}/{date:m}/{date:d}</code>'
				)
			),
			buildField(
				index,
				'filter_pattern',
				'filter',
				__( 'Filter pattern', 'wp-media-helper' ),
				true,
				sprintf(
					/* translators: %s: example filename filter, wrapped in a code element. */
					esc( __( 'Filename filter applied once the directory is resolved, for example %s. Leave empty to keep every file in the resolved directory.', 'wp-media-helper' ) ),
					'<code>{date:Ymd}</code>'
				)
			),
			buildField(
				index,
				'thumbnail_cache',
				'cache',
				__( 'Thumbnail cache directory', 'wp-media-helper' ),
				true,
				sprintf(
					/* translators: %s: example directory path, wrapped in a code element. */
					esc( __( 'Writable directory storing thumbnails, for example %s. Required only when the source directory is read-only.', 'wp-media-helper' ) ),
					'<code>/var/www/media-cache</code>'
				)
			),
		].join( '' );

		return `
			<div class="wp-media-helper-source">
				<input type="hidden" name="sources[${ index }][id]" value="" />
				<div class="wp-media-helper-source-header">
					<strong>${ esc( __( 'New source', 'wp-media-helper' ) ) }</strong>
					<label class="wp-media-helper-toggle">
						<input type="checkbox" name="sources[${ index }][enabled]" value="1" checked />
						${ esc( __( 'Enabled', 'wp-media-helper' ) ) }
					</label>
					<button type="button" class="button-link-delete wp-media-helper-remove-source">${ esc( __( 'Remove', 'wp-media-helper' ) ) }</button>
				</div>
				<table class="form-table" role="presentation">
					<tbody>${ fields }</tbody>
				</table>
			</div>
		`;
	};

	addButton.addEventListener( 'click', function () {
		const fragment = document.createElement( 'div' );
		fragment.innerHTML = buildSourceMarkup( nextIndex++ );
		container.appendChild( fragment.firstElementChild );
		refreshEmptyState();
	} );

	container.addEventListener( 'click', function ( event ) {
		if ( ! event.target.classList.contains( 'wp-media-helper-remove-source' ) ) {
			return;
		}

		const card = event.target.closest( '.wp-media-helper-source' );

		if ( ! card ) {
			return;
		}

		const nameInput = card.querySelector( 'input[name$="[name]"]' );
		const sourceName = nameInput && nameInput.value.trim()
			? nameInput.value.trim()
			: __( 'this source', 'wp-media-helper' );

		const message = sprintf(
			/* translators: %s: name of the source being removed. */
			__( 'Remove %s? This change will be saved when you click Save changes.', 'wp-media-helper' ),
			sourceName
		);

		if ( window.confirm( message ) ) {
			card.remove();
			refreshEmptyState();
		}
	} );
} )( window.wp );
