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

	const allowedBase = ( window.wpMediaHelperSettings && window.wpMediaHelperSettings.allowedBase ) || '';

	const buildField = function ( index, key, slug, label, optional, description, prefix ) {
		const fieldId = 'wp-media-helper-source-' + slug + '-' + index;
		const optionalTag = optional
			? ' <span class="description">' + esc( __( '(optional)', 'wp-media-helper' ) ) + '</span>'
			: '';

		return `
			<tr>
				<th scope="row"><label for="${ fieldId }">${ esc( label ) }${ optionalTag }</label></th>
				<td>
					${ prefix ? '<code class="wp-media-helper-root-prefix">' + esc( prefix ) + '</code>' : '' }
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
				allowedBase
					? sprintf(
						/* translators: %s: example directory path relative to the base directory, wrapped in a code element. */
						esc( __( 'Directory of the external media, relative to the base directory shown on the left, for example %s.', 'wp-media-helper' ) ),
						'<code>nextcloud/photos</code>'
					)
					: sprintf(
						/* translators: %s: example directory path, wrapped in a code element. */
						esc( __( 'Absolute path to the external media root, for example %s.', 'wp-media-helper' ) ),
						'<code>/var/www/media</code>'
					),
				allowedBase ? allowedBase.replace( /[\\/]+$/, '' ) + '/' : ''
			),
			buildField(
				index,
				'path_pattern',
				'path',
				__( 'Path pattern', 'wp-media-helper' ),
				true,
				sprintf(
					/* translators: %s: example path pattern, wrapped in a code element. */
					esc( __( 'Subdirectory where the files of the requested date are likely to be, for example %s. It is only a hint, used to find new files quickly: a file is placed on a day by its date, wherever it is. Leave empty to rely on the periodic scan of the whole source.', 'wp-media-helper' ) ),
					'<code>{date:Y}/{date:m}/{date:d}</code>'
				)
			),
			buildField(
				index,
				'filter_pattern',
				'filter',
				__( 'Name date pattern', 'wp-media-helper' ),
				true,
				sprintf(
					/* translators: %s: example name date pattern, wrapped in a code element. */
					esc( __( 'How the date is written in file names, for example %s. Common forms such as 20261002_121549 or 2026-10-02 are recognised without a pattern. A name with a date alone is placed at 12:00.', 'wp-media-helper' ) ),
					'<code>{date:Ymd}</code>'
				)
			),
			`
			<tr>
				<th scope="row">${ esc( __( 'Files without a date in their name', 'wp-media-helper' ) ) }</th>
				<td>
					<label>
						<input type="hidden" name="sources[${ index }][mtime_fallback]" value="0" />
						<input type="checkbox" name="sources[${ index }][mtime_fallback]" value="1" checked />
						${ esc( __( 'Use the modification time of the file', 'wp-media-helper' ) ) }
					</label>
					<p class="description">${ esc( __( 'When unchecked, a file whose name has no date is not placed on any day.', 'wp-media-helper' ) ) }</p>
				</td>
			</tr>
			`,
			buildField(
				index,
				'thumbnail_cache',
				'cache',
				__( 'Thumbnail cache directory', 'wp-media-helper' ),
				true,
				allowedBase
					? sprintf(
						/* translators: %s: example directory path relative to the base directory, wrapped in a code element. */
						esc( __( 'Writable directory storing thumbnails, relative to the base directory shown on the left, for example %s. It must be separate from the root directory. Required only when the source directory is read-only.', 'wp-media-helper' ) ),
						'<code>nextcloud-cache</code>'
					)
					: sprintf(
						/* translators: %s: example directory path, wrapped in a code element. */
						esc( __( 'Writable directory storing thumbnails, for example %s. It must be separate from the root directory. Required only when the source directory is read-only.', 'wp-media-helper' ) ),
						'<code>/var/www/media-cache</code>'
					),
				allowedBase ? allowedBase.replace( /[\\/]+$/, '' ) + '/' : ''
			),
		].join( '' );

		return `
			<div class="wp-media-helper-source">
				<input type="hidden" name="sources[${ index }][id]" value="" />
				<div class="wp-media-helper-source-header">
					<strong>${ esc( __( 'New source', 'wp-media-helper' ) ) }</strong>
					<label class="wp-media-helper-toggle">
						<input type="hidden" name="sources[${ index }][enabled]" value="0" />
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
