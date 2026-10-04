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
