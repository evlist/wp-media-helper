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

	const template = document.getElementById( 'wp-media-helper-source-template' );
	const emptyState = document.getElementById( 'wp-media-helper-no-source' );
	let nextIndex = container.querySelectorAll( '.wp-media-helper-source' ).length;

	const refreshEmptyState = function () {
		if ( emptyState ) {
			emptyState.hidden = container.querySelectorAll( '.wp-media-helper-source' ).length > 0;
		}
	};

	// The order of the cards is the priority order, and the form is submitted in that order.
	const renumber = function () {
		container.querySelectorAll( '.wp-media-helper-source' ).forEach( function ( card, position ) {
			const number = card.querySelector( '.wp-media-helper-priority-number' );
			if ( number ) {
				number.textContent = String( position + 1 );
			}
		} );
	};

	// The markup of a new source comes from the same PHP code as the stored ones.
	addButton.addEventListener( 'click', function () {
		if ( ! template ) {
			return;
		}

		const holder = document.createElement( 'div' );
		holder.innerHTML = template.innerHTML.split( '__INDEX__' ).join( String( nextIndex++ ) );
		container.appendChild( holder.firstElementChild );
		refreshEmptyState();
		renumber();
	} );

	const settings = window.wpMediaHelperSettings || {};

	// A preset adds its patterns to the list of the source, without repeating one.
	container.addEventListener( 'change', function ( event ) {
		if ( ! event.target.classList.contains( 'wp-media-helper-preset' ) || ! event.target.value ) {
			return;
		}

		const option = event.target.options[ event.target.selectedIndex ];
		const cell = event.target.closest( '.wp-media-helper-name-patterns' );
		const area = cell ? cell.querySelector( 'textarea' ) : null;
		let added = [];
		try {
			added = JSON.parse( option.getAttribute( 'data-patterns' ) || '[]' );
		} catch ( error ) {
			added = [];
		}

		if ( area ) {
			const lines = area.value.split( /\r?\n/ ).map( function ( line ) { return line.trim(); } ).filter( Boolean );
			added.forEach( function ( pattern ) {
				if ( -1 === lines.indexOf( pattern ) ) {
					lines.push( pattern );
				}
			} );
			area.value = lines.join( '\n' );
		}
		event.target.value = '';
	} );

	// Asks the server which date a file name gives with the patterns as currently typed.
	container.addEventListener( 'click', function ( event ) {
		if ( ! event.target.classList.contains( 'wp-media-helper-test-pattern' ) ) {
			return;
		}

		const cell = event.target.closest( '.wp-media-helper-name-patterns' );
		const area = cell ? cell.querySelector( 'textarea' ) : null;
		const nameInput = cell ? cell.querySelector( '.wp-media-helper-test-name' ) : null;
		const result = cell ? cell.querySelector( '.wp-media-helper-test-result' ) : null;
		if ( ! area || ! nameInput || ! result || ! settings.ajaxUrl ) {
			return;
		}

		const body = new window.FormData();
		body.append( 'action', 'wp_media_helper_test_name_pattern' );
		body.append( 'nonce', settings.nonce || '' );
		body.append( 'patterns', area.value );
		body.append( 'name', nameInput.value );
		result.textContent = '…';

		window.fetch( settings.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) { return response.json(); } )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					result.textContent = payload && payload.data && payload.data.message ? payload.data.message : __( 'The test failed.', 'wp-media-helper' );
				} else if ( ! payload.data.date ) {
					result.textContent = __( 'No date found in this name.', 'wp-media-helper' );
				} else {
					result.textContent = payload.data.pattern > 0
						? sprintf( __( '%1$s (pattern %2$d)', 'wp-media-helper' ), payload.data.date, payload.data.pattern )
						: sprintf( __( '%s (common form recognised without a pattern)', 'wp-media-helper' ), payload.data.date );
				}
			} )
			.catch( function () { result.textContent = __( 'The test failed.', 'wp-media-helper' ); } );
	} );

	container.addEventListener( 'click', function ( event ) {
		if ( event.target.classList.contains( 'wp-media-helper-move-source' ) ) {
			const moving = event.target.closest( '.wp-media-helper-source' );
			if ( ! moving ) {
				return;
			}

			if ( '-1' === event.target.dataset.direction ) {
				if ( moving.previousElementSibling ) {
					container.insertBefore( moving, moving.previousElementSibling );
				}
			} else if ( moving.nextElementSibling ) {
				container.insertBefore( moving.nextElementSibling, moving );
			}
			renumber();

			return;
		}

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
			renumber();
		}
	} );
} )( window.wp );
