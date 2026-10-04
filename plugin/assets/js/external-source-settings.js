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
	const settings = window.wpMediaHelperSettings || {};
	let nextIndex = container.querySelectorAll( '.wp-media-helper-source' ).length;

	const cards = function () {
		return Array.from( container.querySelectorAll( ':scope > .wp-media-helper-source' ) );
	};

	const field = function ( card, suffix ) {
		return card.querySelector( 'input[name$="[' + suffix + ']"], select[name$="[' + suffix + ']"]' );
	};

	// The root of a source as typed, without slashes at the ends. `.` is the base directory itself.
	const normalizedRoot = function ( card ) {
		const input = field( card, 'root' );
		const value = input ? input.value.trim().replace( /^\.\/+/, '' ).replace( /\/+$/, '' ) : '';

		return '.' === value ? '' : value;
	};

	// A source whose directory is inside the directory of an earlier source, or equal to it, is owned by
	// that source and never lists a file. Only roots written the same way (all relative, or all absolute)
	// can be compared here; the server checks the real paths when saving.
	const updateShadowing = function () {
		const owners = [];

		cards().forEach( function ( card ) {
			const note = card.querySelector( ':scope > .wp-media-helper-shadow-note' );
			const state = field( card, 'state' ) ? field( card, 'state' ).value : 'active';
			const root = normalizedRoot( card );
			const nameInput = field( card, 'name' );
			let ownerName = '';

			if ( 'disabled' !== state && ( root !== '' || ( field( card, 'root' ) && field( card, 'root' ).value.trim() !== '' ) ) ) {
				const absolute = root.startsWith( '/' );
				owners.some( function ( owner ) {
					if ( owner.absolute !== absolute ) {
						return false;
					}
					if ( owner.root === '' || root === owner.root || root.startsWith( owner.root + '/' ) ) {
						ownerName = owner.name;

						return true;
					}

					return false;
				} );
				owners.push( { root: root, absolute: absolute, name: nameInput && nameInput.value.trim() ? nameInput.value.trim() : __( 'a previous source', 'wp-media-helper' ) } );
			}

			if ( note ) {
				note.hidden = '' === ownerName;
				note.textContent = ownerName
					? sprintf(
						/* translators: %s: name of the earlier source that owns this directory. */
						__( 'Inside the directory of %s, which comes first: this source will never list a file. Move it before that source.', 'wp-media-helper' ),
						ownerName
					)
					: '';
			}
		} );
	};

	// The order of the cards is the priority order, and the form is submitted in that order.
	const refresh = function () {
		const list = cards();

		if ( emptyState ) {
			emptyState.hidden = list.length > 0;
		}
		list.forEach( function ( card, position ) {
			const number = card.querySelector( '.wp-media-helper-priority-number' );
			if ( number ) {
				number.textContent = String( position + 1 );
			}
		} );
		updateShadowing();
	};

	const setExpanded = function ( card, expanded ) {
		const toggle = card.querySelector( '.wp-media-helper-source-toggle' );
		const body = card.querySelector( ':scope > .wp-media-helper-source-body' );
		if ( toggle && body ) {
			toggle.setAttribute( 'aria-expanded', expanded ? 'true' : 'false' );
			body.hidden = ! expanded;
		}
	};

	// The markup of a new source comes from the same PHP code as the stored ones. It is inserted
	// before or after a card, or last, and opened with the focus on its name.
	const addSource = function ( where, reference ) {
		if ( ! template ) {
			return;
		}

		const holder = document.createElement( 'div' );
		holder.innerHTML = template.innerHTML.split( '__INDEX__' ).join( String( nextIndex++ ) );
		const card = holder.firstElementChild;

		if ( reference && 'before' === where ) {
			container.insertBefore( card, reference );
		} else if ( reference && 'after' === where ) {
			container.insertBefore( card, reference.nextElementSibling );
		} else {
			container.appendChild( card );
		}

		setExpanded( card, true );
		refresh();
		const name = field( card, 'name' );
		if ( name ) {
			name.focus();
		}
	};

	addButton.addEventListener( 'click', function () { addSource( 'last', null ); } );
	document.addEventListener( 'click', function ( event ) {
		if ( event.target.closest && event.target.closest( '.wp-media-helper-add-first' ) ) {
			addSource( 'last', null );
		}
	} );

	// A preset adds its patterns to the list of the source, without repeating one.
	container.addEventListener( 'change', function ( event ) {
		if ( event.target.classList.contains( 'wp-media-helper-state' ) ) {
			const card = event.target.closest( '.wp-media-helper-source' );
			if ( card ) {
				card.setAttribute( 'data-state', event.target.value );
			}
			updateShadowing();

			return;
		}

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

	// The header of a card follows what is typed.
	container.addEventListener( 'input', function ( event ) {
		const card = event.target.closest ? event.target.closest( '.wp-media-helper-source' ) : null;
		if ( ! card ) {
			return;
		}

		if ( event.target.name && event.target.name.endsWith( '[name]' ) ) {
			const title = card.querySelector( '.wp-media-helper-source-title' );
			if ( title ) {
				title.textContent = event.target.value.trim() || __( 'New source', 'wp-media-helper' );
			}
			updateShadowing();
		} else if ( event.target.name && event.target.name.endsWith( '[root]' ) ) {
			const summary = card.querySelector( '.wp-media-helper-source-summary' );
			if ( summary ) {
				summary.textContent = event.target.value.trim();
			}
			updateShadowing();
		}
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
		const target = event.target.closest ? event.target.closest( 'button' ) : null;
		const card = event.target.closest ? event.target.closest( '.wp-media-helper-source' ) : null;
		if ( ! target || ! card ) {
			return;
		}

		if ( target.classList.contains( 'wp-media-helper-source-toggle' ) ) {
			setExpanded( card, 'true' !== target.getAttribute( 'aria-expanded' ) );

			return;
		}

		if ( target.classList.contains( 'wp-media-helper-add-around' ) ) {
			addSource( target.dataset.where, card );

			return;
		}

		if ( target.classList.contains( 'wp-media-helper-move-source' ) ) {
			if ( '-1' === target.dataset.direction ) {
				if ( card.previousElementSibling ) {
					container.insertBefore( card, card.previousElementSibling );
				}
			} else if ( card.nextElementSibling ) {
				container.insertBefore( card.nextElementSibling, card );
			}
			refresh();
			target.focus();

			return;
		}

		if ( ! target.classList.contains( 'wp-media-helper-remove-source' ) ) {
			return;
		}

		const nameInput = field( card, 'name' );
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
			refresh();
		}
	} );

	// Drag and drop by the handle (the arrows do the same for the keyboard and for touch screens).
	let dragged = null;

	const clearDropMarks = function () {
		cards().forEach( function ( card ) { card.classList.remove( 'drop-before', 'drop-after' ); } );
	};

	container.addEventListener( 'mousedown', function ( event ) {
		const handle = event.target.closest ? event.target.closest( '.wp-media-helper-drag-handle' ) : null;
		const card = handle ? handle.closest( '.wp-media-helper-source' ) : null;
		cards().forEach( function ( other ) { other.draggable = false; } );
		if ( card ) {
			card.draggable = true;
		}
	} );

	container.addEventListener( 'dragstart', function ( event ) {
		const card = event.target.closest ? event.target.closest( '.wp-media-helper-source' ) : null;
		if ( ! card || ! card.draggable ) {
			return;
		}

		dragged = card;
		card.classList.add( 'is-dragging' );
		event.dataTransfer.effectAllowed = 'move';
		event.dataTransfer.setData( 'text/plain', 'wp-media-helper-source' );
	} );

	container.addEventListener( 'dragover', function ( event ) {
		const over = event.target.closest ? event.target.closest( '.wp-media-helper-source' ) : null;
		if ( ! dragged || ! over || over === dragged ) {
			return;
		}

		event.preventDefault();
		const box = over.getBoundingClientRect();
		clearDropMarks();
		over.classList.add( event.clientY < box.top + box.height / 2 ? 'drop-before' : 'drop-after' );
	} );

	container.addEventListener( 'drop', function ( event ) {
		const over = event.target.closest ? event.target.closest( '.wp-media-helper-source' ) : null;
		if ( ! dragged || ! over || over === dragged ) {
			return;
		}

		event.preventDefault();
		const box = over.getBoundingClientRect();
		if ( event.clientY < box.top + box.height / 2 ) {
			container.insertBefore( dragged, over );
		} else {
			container.insertBefore( dragged, over.nextElementSibling );
		}
		refresh();
	} );

	container.addEventListener( 'dragend', function () {
		if ( dragged ) {
			dragged.classList.remove( 'is-dragging' );
			dragged.draggable = false;
		}
		dragged = null;
		clearDropMarks();
	} );

	refresh();
} )( window.wp );
