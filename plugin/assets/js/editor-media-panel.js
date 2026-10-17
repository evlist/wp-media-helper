( function ( wp ) {
	'use strict';

	const { __, sprintf, _n } = wp.i18n;
	const { registerPlugin } = wp.plugins;
	const { PluginSidebar } = wp.editPost;
	const { PanelBody, PanelRow, Button, TextControl, Notice, Dropdown, MenuItem, CheckboxControl, Modal } = wp.components;
	// An icon font may be missing in some versions: the tile then shows its text only.
	const Dashicon = wp.components.Dashicon || function () { return null; };
	const chevronDown = wp.icons && wp.icons.chevronDown ? wp.icons.chevronDown : null;
	const bulkActionIcon = chevronDown || wp.element.createElement( 'span', { 'aria-hidden': true, style: { fontSize: '12px', lineHeight: 1 } }, '\u25BE' );
	const { useState, useEffect, useRef } = wp.element;

	const endpoint = wpMediaHelperEditorPanel.ajaxUrl;
	const nonce = wpMediaHelperEditorPanel.nonce;
	const defaultDate = wpMediaHelperEditorPanel.date;
	const canTrash = !! wpMediaHelperEditorPanel.canTrash;
	const canUpload = !! wpMediaHelperEditorPanel.canUpload;
	const canSeeTrash = !! wpMediaHelperEditorPanel.canSeeTrash;
	// How media items are attached to posts (slice 042): the method in use, as the server describes it.
	const normalizeMethod = function ( method ) {
		return {
			id: method && method.id ? String( method.id ) : 'native',
			label: method && method.label ? String( method.label ) : '',
			cohabitation: !! ( method && method.cohabitation ),
			multiple_posts: !! ( method && method.multiple_posts ),
			fields: method && Array.isArray( method.fields ) ? method.fields : [],
		};
	};
	let attachmentMethod = normalizeMethod( wpMediaHelperEditorPanel.attachmentMethod );
	const defaultPanelMode = 'advanced' === wpMediaHelperEditorPanel.panelMode ? 'advanced' : 'simple';
	const dateMetaKey = 'wp_media_helper_date';

	// What the data of a link is called: the name of the first field the method declares ("Modes"), else "link".
	const editLabel = function () {
		const first = attachmentMethod.fields.length > 0 ? attachmentMethod.fields[ 0 ] : null;

		return first && first.label
			? sprintf( __( 'Edit %s…', 'wp-media-helper' ), String( first.label ).toLowerCase() )
			: __( 'Edit link…', 'wp-media-helper' );
	};

	// Background poll interval; the manual Refresh button always forces an immediate check.
	const AUTO_REFRESH_INTERVAL_MS = 30000;
	const BULK_ACTIONS = [
		{ value: 'import', label: __( 'Import', 'wp-media-helper' ) },
		{ value: 'remove', label: __( 'Remove from library', 'wp-media-helper' ) },
		{ value: 'attach', label: __( 'Attach to post', 'wp-media-helper' ) },
		{ value: 'detach', label: __( 'Detach from post', 'wp-media-helper' ) },
		// With both mechanisms in use (cohabitation): the labels are completed with the name of the method.
		{ value: 'attach_native', label: __( 'Attach (WordPress)', 'wp-media-helper' ) },
		{ value: 'attach_method', label: __( 'Attach with the method…', 'wp-media-helper' ) },
		{ value: 'to_method', label: __( 'Switch to the method…', 'wp-media-helper' ) },
		{ value: 'to_native', label: __( 'Switch to WordPress', 'wp-media-helper' ) },
		{ value: 'insert', label: __( 'Insert in the content', 'wp-media-helper' ) },
		{ value: 'trash', label: __( 'Move to trash', 'wp-media-helper' ) },
		{ value: 'restore', label: __( 'Restore from trash', 'wp-media-helper' ) },
	];
	const PANEL_MODES = [
		{ value: 'simple', label: __( 'Simple', 'wp-media-helper' ) },
		{ value: 'advanced', label: __( 'Advanced', 'wp-media-helper' ) },
	];
	// What is shown when nothing was chosen: all the states with a method that links a file to several posts.
	const defaultAttachmentScope = function () {
		return attachmentMethod.multiple_posts ? [ 'unattached', 'current', 'other' ] : [ 'unattached', 'current' ];
	};
	// The categories of files (images, videos, audio, documents, ...) come from the server.
	const FILE_TYPES = Array.isArray( wpMediaHelperEditorPanel.fileTypes ) && wpMediaHelperEditorPanel.fileTypes.length
		? wpMediaHelperEditorPanel.fileTypes
		: [ { value: 'image', label: __( 'Images', 'wp-media-helper' ), icon: 'format-image' }, { value: 'video', label: __( 'Videos', 'wp-media-helper' ), icon: 'video-alt3' }, { value: 'other', label: __( 'Other', 'wp-media-helper' ), icon: 'media-default' } ];
	const DEFAULT_MEDIA_TYPE = FILE_TYPES.map( function ( type ) { return type.value; } );
	const DEFAULT_SOURCE_FILTER = [ 'all' ];
	const ATTACHMENT_SCOPE_STATES = [
		{ value: 'unattached', label: __( 'Unattached', 'wp-media-helper' ) },
		{ value: 'current', label: __( 'Attached to this post', 'wp-media-helper' ) },
		{ value: 'other', label: __( 'Attached to another post', 'wp-media-helper' ) },
	];
	const MEDIA_TYPE_OPTIONS = FILE_TYPES.map( function ( type ) { return { value: type.value, label: type.label }; } );

	const formatElapsed = function ( lastRefreshedAt ) {
		if ( ! lastRefreshedAt ) {
			return __( 'never refreshed', 'wp-media-helper' );
		}

		const seconds = Math.max( 0, Math.round( ( Date.now() - lastRefreshedAt ) / 1000 ) );

		if ( seconds < 5 ) {
			return __( 'refreshed just now', 'wp-media-helper' );
		}

		if ( seconds < 60 ) {
			return sprintf(
				/* translators: %d: number of seconds. */
				_n( 'refreshed %d second ago', 'refreshed %d seconds ago', seconds, 'wp-media-helper' ),
				seconds
			);
		}

		const minutes = Math.round( seconds / 60 );

		return sprintf(
			/* translators: %d: number of minutes. */
			_n( 'refreshed %d minute ago', 'refreshed %d minutes ago', minutes, 'wp-media-helper' ),
			minutes
		);
	};

	// Reasons sent by the server are codes: show them as sentences.
	const describeReason = function ( reason ) {
		if ( 'index-incomplete' === reason ) {
			return __( 'The files are still being indexed: the list may be incomplete.', 'wp-media-helper' );
		}

		if ( 'index-paused' === reason ) {
			return __( 'The indexing is paused: the list shows the index as it is.', 'wp-media-helper' );
		}

		return reason ? __( 'Refresh required: ', 'wp-media-helper' ) + reason : __( 'Refresh required.', 'wp-media-helper' );
	};

	const getCurrentPostId = function () {
		return Number( wp.data.select( 'core/editor' ).getCurrentPostId() || 0 );
	};

	const fetchState = function ( filters, forceRefresh, page ) {
		const formData = new window.FormData();
		formData.append( 'action', 'wp_media_helper_media_panel_state' );
		formData.append( 'nonce', nonce );
		formData.append( 'force_refresh', forceRefresh ? '1' : '0' );
		formData.append( 'source_id', wpMediaHelperEditorPanel.sourceId || '' );
		formData.append( 'post_id', String( getCurrentPostId() ) );
		formData.append( 'filters', JSON.stringify( filters ) );
		formData.append( 'page', String( page || 1 ) );

		return window.fetch( endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData,
		} ).then( function ( response ) {
			return response.json();
		} );
	};

	// The day to start from when no date was chosen for the post: the day of publication for a post
	// that is published, scheduled or private (the posts one comes back to), today for a draft.
	const PUBLISHED_STATUSES = [ 'publish', 'future', 'private' ];

	const publicationDay = function ( status, date ) {
		if ( ! PUBLISHED_STATUSES.includes( status ) || 'string' !== typeof date ) {
			return '';
		}

		return /^\d{4}-\d{2}-\d{2}/.test( date ) ? date.slice( 0, 10 ) : '';
	};

	const getStoredDate = function () {
		const editor = wp.data.select( 'core/editor' );
		const meta = editor.getEditedPostAttribute( 'meta' ) || {};

		return meta[ dateMetaKey ] || publicationDay( editor.getEditedPostAttribute( 'status' ), editor.getEditedPostAttribute( 'date' ) ) || defaultDate;
	};

	const normalizeMediaItem = function ( item ) {
		if ( 'string' === typeof item ) {
			return {
				id: item,
				name: item.split( '/' ).pop() || item,
				path: item,
				type: 'other',
				is_imported: false,
			};
		}

		return {
			id: item && item.id ? item.id : ( item && item.path ? item.path : item && item.name ? item.name : '' ),
			name: item && item.name ? item.name : ( item && item.path ? item.path.split( '/' ).pop() : __( 'Media item', 'wp-media-helper' ) ),
			path: item && item.path ? item.path : ( item && item.name ? item.name : '' ),
			type: item && item.type ? item.type : 'other',
			source_id: item && item.source_id ? item.source_id : '',
			is_imported: !! ( item && item.is_imported ),
			is_attached_to_current_post: !! ( item && item.is_attached_to_current_post ),
			is_attached_to_other_post: !! ( item && item.is_attached_to_other_post ),
			other_post_id: item && item.other_post_id ? item.other_post_id : 0,
			other_post_title: item && item.other_post_title ? item.other_post_title : '',
			other_posts: item && Array.isArray( item.other_posts ) ? item.other_posts : [],
			other_posts_count: item && item.other_posts_count ? item.other_posts_count : 0,
			attachment_data: item && item.attachment_data && 'object' === typeof item.attachment_data ? item.attachment_data : null,
			attached_natively_only: !! ( item && item.attached_natively_only ),
			other_post_edit_url: item && item.other_post_edit_url ? item.other_post_edit_url : '',
			media_type: item && item.media_type ? item.media_type : 'other',
			can_import: ! ( item && false === item.can_import ),
			import_blocker: item && item.import_blocker ? item.import_blocker : '',
			is_trashed: !! ( item && item.is_trashed ),
			thumbnail_url: item && item.thumbnail_url ? item.thumbnail_url : '',
			thumbnail_large_url: item && item.thumbnail_large_url ? item.thumbnail_large_url : '',
			width: item && item.width ? item.width : 0,
			height: item && item.height ? item.height : 0,
			attachment_ids: item && Array.isArray( item.attachment_ids ) ? item.attachment_ids : [],
			file_size: item && item.file_size ? item.file_size : 0,
			effective_date: item && item.effective_date ? item.effective_date : '',
			date_source: item && item.date_source ? item.date_source : '',
		};
	};

	const bulkMediaItems = function ( action, items, postId ) {
		const formData = new window.FormData();
		formData.append( 'action', 'wp_media_helper_bulk_media' );
		formData.append( 'nonce', nonce );
		formData.append( 'source_id', wpMediaHelperEditorPanel.sourceId || '' );
		formData.append( 'bulk_action', action );
		formData.append( 'post_id', String( postId || 0 ) );
		formData.append( 'items', JSON.stringify( items.map( function ( item ) {
			const entry = { id: item.id, path: item.path, source_id: item.source_id };
			if ( item.data ) {
				entry.data = item.data;
			}

			return entry;
		} ) ) );

		return window.fetch( endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData,
		} ).then( function ( response ) {
			return response.json();
		} );
	};

	// Inserting a file in the content (slice 051).
	const DRAG_TYPE = 'application/x-wp-media-helper';

	// What the server says to insert for the files (paths and sources only are sent: it decides the rest).
	const fetchInsertData = function ( items, postId ) {
		const formData = new window.FormData();
		formData.append( 'action', 'wp_media_helper_insert_data' );
		formData.append( 'nonce', nonce );
		formData.append( 'source_id', wpMediaHelperEditorPanel.sourceId || '' );
		formData.append( 'post_id', String( postId || 0 ) );
		formData.append( 'items', JSON.stringify( items.map( function ( item ) {
			return { id: item.id, path: item.path, source_id: item.source_id };
		} ) ) );

		return window.fetch( endpoint, { method: 'POST', credentials: 'same-origin', body: formData } ).then( function ( response ) {
			return response.json();
		} );
	};

	const blockFromDescription = function ( description ) {
		return wp.blocks.createBlock(
			description.block,
			description.attributes || {},
			( description.innerBlocks || [] ).map( blockFromDescription )
		);
	};

	const blockEditor = function () {
		return wp.data.select( 'core/block-editor' );
	};

	// Where the blocks go, as `{ root, index }`: after the selected block, else at the end of the content.
	const pointAfterSelection = function () {
		const editor = blockEditor();
		const selected = editor.getSelectedBlockClientId ? editor.getSelectedBlockClientId() : null;
		if ( selected && 'core/gallery' === editor.getBlockName( selected ) ) {
			// The gallery itself is selected: the photos join it, at the end.
			return { root: selected, index: editor.getBlockCount( selected ) };
		}

		if ( selected ) {
			return { root: editor.getBlockRootClientId( selected ) || '', index: editor.getBlockIndex( selected ) + 1 };
		}

		return { root: '', index: editor.getBlockCount() };
	};

	// Inserts the blocks described by the server. Into a gallery, photos are image blocks of that gallery, not galleries in a gallery.
	const placeBlocks = function ( descriptions, point ) {
		const editor = blockEditor();
		const inGallery = point.root && 'core/gallery' === editor.getBlockName( point.root );
		let list = descriptions;
		if ( inGallery ) {
			list = [];
			descriptions.forEach( function ( description ) {
				if ( 'core/gallery' === description.block ) {
					description.innerBlocks.forEach( function ( inner ) { list.push( inner ); } );
				} else {
					list.push( description );
				}
			} );
		}
		wp.data.dispatch( 'core/block-editor' ).insertBlocks( list.map( blockFromDescription ), point.index, point.root || undefined, true );

		return list.length;
	};

	// Can this item be inserted now? Only photos for the moment (the server says so too).
	const canInsert = function ( item ) {
		return !! wpMediaHelperEditorPanel.canUpload && 'image' === item.media_type && ! item.is_trashed && ( item.can_import || item.is_imported );
	};

	// The block under a point of the content, and where a drop there would insert, as `{ root, index, box, vertical, after }`.
	const dropPoint = function ( event ) {
		const editor = blockEditor();
		const element = event.target && event.target.closest ? event.target.closest( '[data-block]' ) : null;
		if ( ! element ) {
			return { root: '', index: editor.getBlockCount(), box: null };
		}

		const clientId = element.getAttribute( 'data-block' );
		const box = element.getBoundingClientRect();
		if ( 'core/gallery' === editor.getBlockName( clientId ) ) {
			// Over the gallery itself, not over one of its images: the end of the gallery.
			return { root: clientId, index: editor.getBlockCount( clientId ), box: box, vertical: true, after: true, inside: true };
		}

		const root = editor.getBlockRootClientId( clientId ) || '';
		const inGallery = root && 'core/gallery' === editor.getBlockName( root );
		const after = inGallery ? event.clientX > box.left + box.width / 2 : event.clientY > box.top + box.height / 2;

		return { root: root, index: editor.getBlockIndex( clientId ) + ( after ? 1 : 0 ), box: box, vertical: !! inGallery, after: after };
	};

	const savePanelMode = function ( mode ) {
		const formData = new window.FormData();
		formData.append( 'action', 'wp_media_helper_panel_mode' );
		formData.append( 'nonce', nonce );
		formData.append( 'mode', mode );

		return window.fetch( endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData,
		} ).then( function ( response ) {
			return response.json();
		} );
	};

	const saveFilter = function ( key, value, postId ) {
		const formData = new window.FormData();
		formData.append( 'action', 'wp_media_helper_save_filter' );
		formData.append( 'nonce', nonce );
		formData.append( 'key', key );
		formData.append( 'post_id', String( postId || 0 ) );
		formData.append( 'value', JSON.stringify( value ) );

		return window.fetch( endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData,
		} ).then( function ( response ) {
			return response.json();
		} );
	};

	const itemKey = function ( item ) {
		return item.id || item.path || item.name;
	};

	const normalizeFilenameQuery = function ( query ) {
		return query.replace( /\s+/g, ' ' ).trim().replace( /^[._/\\-]+|[._/\\-]+$/g, '' ).trim();
	};

	// The pictogram of an option of a filter: the path of an SVG (24 x 24) or the name of a Dashicon.
	const OptionIcon = function ( { option } ) {
		if ( option.icon_svg ) {
			return wp.element.createElement( 'svg', { width: 14, height: 14, viewBox: '0 0 24 24', 'aria-hidden': true, focusable: 'false', style: { flexShrink: 0, verticalAlign: 'text-bottom' }, fill: 'currentColor' },
				wp.element.createElement( 'path', { d: option.icon_svg } )
			);
		}
		if ( option.icon ) {
			return wp.element.createElement( Dashicon, { icon: option.icon, size: 14 } );
		}

		return null;
	};

	// A group of checkboxes with a checkbox "All" that, like the one of the tables of WordPress, checks everything
	// when it is not checked and unchecks everything when it is. Nothing checked is allowed.
	const FilterCheckboxGroup = function ( { label, options, selectedValues, disabled, onChange } ) {
		const allValues = options.map( function ( option ) { return option.value; } );
		const checkedValues = allValues.filter( function ( value ) { return selectedValues.includes( value ); } );
		const all = allValues.length > 0 && checkedValues.length === allValues.length;
		const none = 0 === checkedValues.length;
		const selectedLabels = options.filter( function ( option ) {
			return selectedValues.includes( option.value );
		} ).map( function ( option ) {
			return option.label;
		} );
		let summary;
		if ( all ) {
			summary = wp.element.createElement( 'span', { style: { color: '#007017', fontWeight: 600 } }, __( 'All', 'wp-media-helper' ) );
		} else if ( none ) {
			summary = wp.element.createElement( 'span', { style: { color: '#b32d2e', fontWeight: 600 } }, __( 'None', 'wp-media-helper' ) );
		} else {
			summary = options.filter( function ( option ) { return selectedValues.includes( option.value ); } ).map( function ( option, index ) {
				return wp.element.createElement( 'span', { key: option.value, style: { display: 'inline-flex', alignItems: 'center', gap: '2px', marginLeft: index > 0 ? '6px' : 0 } },
					wp.element.createElement( OptionIcon, { option: option } ),
					option.label
				);
			} );
		}

		return wp.element.createElement( 'details', { style: { borderTop: '1px solid #ddd' } },
			wp.element.createElement( 'summary', { style: { cursor: 'pointer', padding: '7px 0' } },
				wp.element.createElement( 'span', { style: { display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', gap: '0.5rem' } },
					wp.element.createElement( 'strong', { style: { fontSize: '12px', fontWeight: 600, flexShrink: 0 } }, label ),
					wp.element.createElement( 'span', { style: { color: '#757575', fontSize: '11px', minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', textAlign: 'right' } }, summary )
				)
			),
			wp.element.createElement( 'div', { role: 'group', 'aria-label': label, style: { display: 'flex', flexDirection: 'column', gap: '0.1rem', padding: '0 0 8px' } },
				wp.element.createElement( 'label', { style: { display: 'flex', alignItems: 'center', gap: '0.3rem', fontSize: '12px', fontWeight: 600, paddingBottom: '3px', marginBottom: '3px', borderBottom: '1px solid #eee' } },
					wp.element.createElement( 'input', {
						type: 'checkbox',
						checked: all,
						disabled: disabled,
						// The dash of a partial selection.
						ref: function ( element ) { if ( element ) { element.indeterminate = ! all && ! none; } },
						onChange: function () { onChange( all ? [] : allValues ); }
					} ),
					__( 'All', 'wp-media-helper' )
				),
				options.map( function ( option ) {
					const checked = selectedValues.includes( option.value );
					return wp.element.createElement( 'label', { key: option.value, style: { display: 'flex', alignItems: 'center', gap: '0.3rem', fontSize: '12px' } },
						wp.element.createElement( 'input', {
							type: 'checkbox',
							checked: checked,
							disabled: disabled,
							onChange: function () {
								onChange( allValues.filter( function ( value ) {
									return value === option.value ? ! checked : selectedValues.includes( value );
								} ) );
							}
						} ),
						wp.element.createElement( OptionIcon, { option: option } ),
						option.label
					);
				} )
			)
		);
	};


	const formatBytes = function ( bytes ) {
		if ( ! bytes ) {
			return '';
		}

		const units = [ 'B', 'KB', 'MB', 'GB' ];
		let value = bytes;
		let unit = 0;
		while ( value >= 1024 && unit < units.length - 1 ) {
			value /= 1024;
			unit += 1;
		}

		return ( unit > 0 ? value.toFixed( 1 ) : String( value ) ) + ' ' + units[ unit ];
	};

	// Where the date of a file comes from, as the index recorded it.
	const dateSourceLabel = function ( source ) {
		return {
			name: __( 'from the file name', 'wp-media-helper' ),
			embedded: __( 'capture date in the file', 'wp-media-helper' ),
			mtime: __( 'file modification time', 'wp-media-helper' ),
		}[ source ] || '';
	};

	// The "more" button of a tile shows on hover and focus, and always on a touch screen.
	const GALLERY_CSS = '.wpmh-tile .wpmh-more{opacity:0;transition:opacity .1s}'
		+ '.wpmh-tile:hover .wpmh-more,.wpmh-tile:focus-within .wpmh-more,.wpmh-tile .wpmh-more:focus{opacity:1}'
		+ '@media (hover:none){.wpmh-tile .wpmh-more{opacity:1}}'
		+ '.wpmh-tile>button:focus-visible{outline:3px solid #2271b1;outline-offset:-3px}';

	// ---- Gallery -------------------------------------------------------------------------

	const DENSITIES = [ 1, 2, 3 ];
	// The modal of the gallery block follows the width of the screen: 1 or 2 per row on a phone held upright, 1 to 3 on a phone on its
	// side or in a small window, more in a wide window. The choice remembered is the last one made; the value used is the closest
	// one the screen offers.
	const PICKER_BUCKETS = {
		compact: { choices: [ 1, 2 ], fallback: 2 },
		medium: { choices: [ 1, 2, 3 ], fallback: 2 },
		wide: { choices: [ 3, 4, 6, 8 ], fallback: 4 }
	};
	const PICKER_DENSITY_KEY = 'wpMediaHelperPickerDensity';
	const pickerBucket = function ( width ) {
		return width < 600 ? 'compact' : ( width < 1000 ? 'medium' : 'wide' );
	};
	const closestChoice = function ( wanted, choices ) {
		return choices.reduce( function ( best, choice ) {
			return Math.abs( choice - wanted ) < Math.abs( best - wanted ) ? choice : best;
		}, choices[ 0 ] );
	};
	const DENSITY_KEY = 'wpMediaHelperDensity';
	const GAP = 2;
	const LONG_PRESS_MS = 1000;
	// Drag-and-drop insertion is parked (slice 051): it fought with the long press on a touch screen. The code stays, the tiles are not draggable.
	const DRAG_ENABLED = false;

	// Images per row, remembered in this browser (0 when nothing is remembered; the modal has its own memory).
	const readDensity = function ( picker ) {
		try {
			const value = Number( window.localStorage.getItem( picker ? PICKER_DENSITY_KEY : DENSITY_KEY ) );

			return picker ? ( value > 0 ? value : 0 ) : ( DENSITIES.includes( value ) ? value : 2 );
		} catch ( error ) {
			return picker ? 0 : 2;
		}
	};

	const writeDensity = function ( value, picker ) {
		try {
			window.localStorage.setItem( picker ? PICKER_DENSITY_KEY : DENSITY_KEY, String( value ) );
		} catch ( error ) {
			// The choice just is not remembered.
		}
	};

	// Width over height. Unknown sizes (files that are not images, images not read yet) get a usual shape.
	const aspectOf = function ( item ) {
		if ( item.width > 0 && item.height > 0 ) {
			return item.width / item.height;
		}

		return 'image' === item.media_type ? 1.5 : 1.25;
	};

	// The shape a thumbnail is given in the layout. A very wide or very tall picture is cropped
	// (the image covers its tile) so that no row is stretched or squeezed to an extreme.
	const MIN_ASPECT = 0.5;
	const MAX_ASPECT = 3;

	const tileAspect = function ( item ) {
		return Math.min( MAX_ASPECT, Math.max( MIN_ASPECT, aspectOf( item ) ) );
	};

	// Rows of `perRow` thumbnails that fill the width, each keeping its proportions: the height of
	// a row is what makes the widths of its images add up to the available width, so a row never
	// overflows nor leaves a gap, whatever the width of the screen.
	const layoutRows = function ( items, width, perRow ) {
		const rows = [];
		const usable = Math.max( 1, Math.floor( width ) );

		for ( let index = 0; index < items.length; index += perRow ) {
			const group = items.slice( index, index + perRow );
			const total = group.reduce( function ( sum, item ) { return sum + tileAspect( item ); }, 0 );
			let height = Math.max( 1, usable - GAP * ( group.length - 1 ) ) / total;

			if ( group.length < perRow ) {
				// A short last row is not stretched to the full width.
				height = Math.min( height, ( usable - GAP * ( perRow - 1 ) ) / ( perRow * 1.5 ) );
			}

			height = Math.max( 1, Math.floor( height ) );

			// Whole pixels: what the rounding leaves goes to the last image of a full row, so that
			// the row ends exactly at the edge (the picture covers its tile, a pixel or two of crop is invisible).
			const widths = group.map( function ( item ) { return Math.max( 1, Math.floor( tileAspect( item ) * height ) ); } );
			if ( group.length === perRow ) {
				const spare = usable - GAP * ( group.length - 1 ) - widths.reduce( function ( sum, value ) { return sum + value; }, 0 );
				if ( spare > 0 && spare < group.length * 2 + 2 ) {
					widths[ widths.length - 1 ] += spare;
				}
			}

			rows.push( { items: group, widths: widths, height: height } );
		}

		return rows;
	};

	// With one post per media item, being attached to another post blocks the item (red lock); when
	// a media item may belong to several posts it is only information.
	const blockedElsewhere = function ( item ) {
		return item.is_attached_to_other_post && ! attachmentMethod.multiple_posts;
	};

	const stateLabel = function ( item ) {
		if ( item.attached_natively_only ) {
			return attachmentMethod.cohabitation ? __( 'Attached the WordPress way', 'wp-media-helper' ) : __( 'Attached the WordPress way only (not counted: attach it to use the method)', 'wp-media-helper' );
		}

		if ( attachmentMethod.multiple_posts ) {
			if ( item.is_attached_to_current_post ) {
				return item.is_attached_to_other_post ? __( 'Attached to this post and to others', 'wp-media-helper' ) : __( 'Attached to this post', 'wp-media-helper' );
			}
			if ( item.is_attached_to_other_post ) {
				return __( 'Attached to other posts', 'wp-media-helper' );
			}

			return __( 'Not attached', 'wp-media-helper' );
		}

		if ( item.is_attached_to_other_post ) {
			return __( 'Attached to another post', 'wp-media-helper' );
		}

		return item.is_attached_to_current_post ? __( 'Attached to this post', 'wp-media-helper' ) : __( 'Not attached', 'wp-media-helper' );
	};

	// A paperclip says how the file relates to the post: green, attached here; red with a lock,
	// attached to another post; crossed out, not attached. The shape differs as well as the colour.
	const AttachmentBadge = function ( { item } ) {
		const other = blockedElsewhere( item );
		const here = item.is_attached_to_current_post;
		const elsewhereOnly = attachmentMethod.multiple_posts && item.is_attached_to_other_post && ! here;
		const colour = other ? '#b32d2e' : ( here ? '#0a7d45' : ( elsewhereOnly ? '#996800' : '#646970' ) );
		const label = stateLabel( item );

		return wp.element.createElement( 'span', {
			role: 'img',
			'aria-label': label,
			title: label,
			style: { position: 'absolute', top: '3px', left: '3px', width: '22px', height: '22px', borderRadius: '50%', background: 'rgba(255,255,255,0.9)', boxShadow: '0 0 2px rgba(0,0,0,0.4)', display: 'flex', alignItems: 'center', justifyContent: 'center' }
		},
			wp.element.createElement( 'svg', { width: 16, height: 16, viewBox: '0 0 24 24', 'aria-hidden': true, focusable: 'false', fill: 'none', stroke: colour, strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round' },
				wp.element.createElement( 'path', { d: 'M8 12.5 14.2 6.3a2.6 2.6 0 0 1 3.7 3.7L10 18a4 4 0 0 1-5.7-5.7L12 4.6' } ),
				other
					? wp.element.createElement( 'g', { stroke: colour, fill: colour, strokeWidth: 1.5 },
						wp.element.createElement( 'rect', { x: 14, y: 15, width: 8, height: 6, rx: 1 } ),
						wp.element.createElement( 'path', { d: 'M16 15v-1.5a2 2 0 0 1 4 0V15', fill: 'none' } )
					)
					: null,
				! other && ! here && ! elsewhereOnly
					? wp.element.createElement( 'path', { d: 'M3 3l18 18', stroke: colour } )
					: null
			)
		);
	};

	// The pictograms of the values of the link (the modes) of a file attached to the post: the options of the fields that are filters,
	// drawn with their icon (a letter when an option has none), at the bottom right of the tile.
	const LinkValueBadges = function ( { item } ) {
		if ( ! item.is_attached_to_current_post || item.attached_natively_only || ! item.attachment_data ) {
			return null;
		}

		const chips = [];
		attachmentMethod.fields.filter( function ( field ) { return field.filter; } ).forEach( function ( field ) {
			const raw = item.attachment_data[ field.key ];
			const values = Array.isArray( raw ) ? raw : ( raw ? [ raw ] : [] );
			values.forEach( function ( value ) {
				const option = field.options.filter( function ( candidate ) { return candidate.value === value; } )[ 0 ];
				if ( option ) {
					chips.push( { key: field.key + ':' + value, option: option, field: field } );
				}
			} );
		} );
		if ( 0 === chips.length ) {
			return null;
		}

		return wp.element.createElement( 'span', { style: { position: 'absolute', right: '3px', bottom: '3px', display: 'flex', gap: '2px' } },
			chips.map( function ( chip ) {
				const label = chip.field.label + ': ' + chip.option.label;

				return wp.element.createElement( 'span', {
					key: chip.key,
					role: 'img',
					'aria-label': label,
					title: label,
					style: { minWidth: '18px', height: '18px', padding: '0 2px', boxSizing: 'border-box', borderRadius: '9px', background: 'rgba(255,255,255,0.92)', boxShadow: '0 0 2px rgba(0,0,0,0.4)', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#1d2327', fontSize: '10px', fontWeight: 600 }
				},
					chip.option.icon || chip.option.icon_svg ? wp.element.createElement( OptionIcon, { option: chip.option } ) : chip.option.label.slice( 0, 2 )
				);
			} )
		);
	};

	const CornerBadge = function ( { icon, label, position, colour } ) {
		return wp.element.createElement( 'span', {
			role: 'img',
			'aria-label': label,
			title: label,
			style: Object.assign( { position: 'absolute', bottom: '3px', width: '18px', height: '18px', borderRadius: '50%', background: 'rgba(255,255,255,0.9)', boxShadow: '0 0 2px rgba(0,0,0,0.4)', display: 'flex', alignItems: 'center', justifyContent: 'center', color: colour }, position )
		}, wp.element.createElement( Dashicon, { icon: icon, size: 14 } ) );
	};

	// The picture of a thumbnail, or an icon for files that have none.
	const TileContent = function ( { item, perRow } ) {
		const useLarge = 1 === perRow || ( 2 === perRow && ( window.devicePixelRatio || 1 ) > 1.5 );
		const source = useLarge && item.thumbnail_large_url ? item.thumbnail_large_url : item.thumbnail_url;
		const [ failed, setFailed ] = useState( false );

		if ( source && ! failed ) {
			return wp.element.createElement( 'img', {
				src: source,
				alt: item.name,
				loading: 'lazy',
				draggable: false,
				onError: function () { setFailed( true ); },
				style: { display: 'block', width: '100%', height: '100%', objectFit: 'cover' }
			} );
		}

		const known = FILE_TYPES.filter( function ( type ) { return type.value === item.media_type; } )[ 0 ];
		const icon = known && known.icon ? known.icon : 'media-default';

		return wp.element.createElement( 'span', { style: { display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', width: '100%', height: '100%', gap: '2px', color: '#50575e', padding: '4px', boxSizing: 'border-box', overflow: 'hidden' } },
			wp.element.createElement( Dashicon, { icon: icon, size: 24 } ),
			wp.element.createElement( 'span', { style: { fontSize: '10px', textTransform: 'uppercase' } }, item.type ),
			wp.element.createElement( 'span', { style: { fontSize: '10px', maxWidth: '100%', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }, item.name )
		);
	};

	// The data of a link, as the method declares it: how to show a value and a control to change it.
	const formatFieldValue = function ( field, value ) {
		const labelOf = function ( optionValue ) {
			const option = ( field.options || [] ).filter( function ( candidate ) { return candidate.value === optionValue; } )[ 0 ];

			return option ? option.label : optionValue;
		};
		if ( 'multiselect' === field.type ) {
			return Array.isArray( value ) && value.length > 0 ? value.map( labelOf ).join( ', ' ) : __( 'none', 'wp-media-helper' );
		}
		if ( 'select' === field.type ) {
			return value ? labelOf( value ) : '';
		}
		if ( 'boolean' === field.type ) {
			return value ? __( 'Yes', 'wp-media-helper' ) : __( 'No', 'wp-media-helper' );
		}

		return null === value || undefined === value ? '' : String( value );
	};

	const FieldControl = function ( { field, value, onChange, disabled } ) {
		if ( 'multiselect' === field.type ) {
			const current = Array.isArray( value ) ? value : [];

			return wp.element.createElement( 'fieldset', { style: { border: 0, margin: '0 0 8px', padding: 0 } },
				wp.element.createElement( 'legend', { style: { fontWeight: 600, fontSize: '12px', padding: 0 } }, field.label ),
				field.options.map( function ( option ) {
					return wp.element.createElement( CheckboxControl, {
						key: option.value,
						label: option.label,
						checked: current.includes( option.value ),
						disabled: disabled,
						onChange: function ( checked ) {
							onChange( field.options.map( function ( candidate ) { return candidate.value; } ).filter( function ( candidate ) {
								return candidate === option.value ? checked : current.includes( candidate );
							} ) );
						}
					} );
				} )
			);
		}
		if ( 'boolean' === field.type ) {
			return wp.element.createElement( CheckboxControl, { label: field.label, checked: !! value, disabled: disabled, onChange: onChange } );
		}
		if ( 'select' === field.type ) {
			return wp.element.createElement( 'label', { style: { display: 'block', margin: '0 0 8px', fontSize: '12px', fontWeight: 600 } },
				field.label,
				wp.element.createElement( 'select', {
					value: value || '',
					disabled: disabled,
					onChange: function ( event ) { onChange( event.target.value ); },
					style: { display: 'block', width: '100%', marginTop: '2px' }
				},
					wp.element.createElement( 'option', { value: '' }, '—' ),
					field.options.map( function ( option ) { return wp.element.createElement( 'option', { key: option.value, value: option.value }, option.label ); } )
				)
			);
		}

		return wp.element.createElement( TextControl, {
			label: field.label,
			type: 'integer' === field.type ? 'number' : 'text',
			value: null === value || undefined === value ? '' : String( value ),
			disabled: disabled,
			onChange: function ( typed ) {
				if ( 'integer' === field.type ) {
					const number = parseInt( typed, 10 );
					onChange( '' === typed || isNaN( number ) ? null : number );
				} else {
					onChange( typed );
				}
			}
		} );
	};

	// With `picker` ({ galleryClientId, onClose }) the panel is shown in a modal opened from a gallery block: the photos chosen
	// are added to that gallery.
	const MediaPanel = function ( props ) {
		const picker = props && props.picker ? props.picker : null;
		const [ filters, setFiltersState ] = useState( function () { return { date: getStoredDate() }; } );
		const [ availableSources, setAvailableSources ] = useState( [] );
		const [ status, setStatus ] = useState( 'fresh' );
		const [ reason, setReason ] = useState( null );
		const [ files, setFiles ] = useState( [] );
		const [ pagination, setPagination ] = useState( { page: 1, per_page: 0, total: 0, total_pages: 1 } );
		const [ loading, setLoading ] = useState( false );
		const [ selectedIds, setSelectedIds ] = useState( [] );
		const [ selectionMode, setSelectionMode ] = useState( !! picker );
		const [ bulkAction, setBulkAction ] = useState( 'none' );
		const [ panelMode, setPanelMode ] = useState( defaultPanelMode );
		const [ viewportWidth, setViewportWidth ] = useState( window.innerWidth );
		const bucket = picker ? pickerBucket( viewportWidth ) : null;
		// What was chosen, and what the screen shows of it.
		const [ chosenDensity, setDensity ] = useState( function () { return readDensity( !! picker ); } );
		const densityChoices = picker ? PICKER_BUCKETS[ bucket ].choices : DENSITIES;
		const density = picker ? closestChoice( chosenDensity || PICKER_BUCKETS[ bucket ].fallback, densityChoices ) : chosenDensity;
		// Turning the phone changes the choices.
		useEffect( function () {
			if ( ! picker ) {
				return undefined;
			}
			const onResize = function () { setViewportWidth( window.innerWidth ); };
			window.addEventListener( 'resize', onResize );

			return function () { window.removeEventListener( 'resize', onResize ); };
		}, [] );
		const [ galleryWidth, setGalleryWidth ] = useState( 0 );
		const [ hasNews, setHasNews ] = useState( false );
		// The filters with nothing checked, as the server reports them: the list is empty because of them.
		const [ emptyFilters, setEmptyFilters ] = useState( [] );
		// The link being edited: { item, values }.
		const [ linkEdit, setLinkEdit ] = useState( null );
		const [ , setMethodState ] = useState( attachmentMethod );
		// The featured image of the post being edited (undefined when its type has none).
		const featuredMedia = wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'featured_media' );
		}, [] );
		const featuredSupported = 'undefined' !== typeof featuredMedia;
		const isFeatured = function ( item ) {
			return featuredSupported && featuredMedia > 0 && item.attachment_ids.includes( featuredMedia );
		};

		// The files waiting for the confirmation of their move to the trash.
		const [ trashRequest, setTrashRequest ] = useState( null );
		const [ removeRequest, setRemoveRequest ] = useState( null );
		const [ menu, setMenu ] = useState( null );
		const [ sheetKey, setSheetKey ] = useState( null );
		const [ operationNotice, setOperationNotice ] = useState( null );
		const [ lastRefreshedAt, setLastRefreshedAt ] = useState( null );
		const [ , setTick ] = useState( 0 );

		// What the long-lived callbacks (timers, observers) need to see at the time they run.
		const latest = useRef( {} );
		const sequence = useRef( 0 );
		const loadingMore = useRef( false );
		const sentinel = useRef( null );
		const [ galleryElement, setGalleryElement ] = useState( null );
		const longPress = useRef( { timer: null, fired: false } );

		// The content of the editor accepts what is dragged from the panel: a line shows where it goes, the drop inserts it.
		// The canvas is an iframe in the current editor and is rebuilt now and then: the documents are looked at again regularly.
		useEffect( function () {
			if ( picker ) {
				return undefined;
			}
			let line = null;
			// What is dragged from the panel, and over the content (in the iframe of the canvas, or in the content area of a page
			// without iframe): the sidebar, the toolbar and the rest of the page do not take it.
			const mine = function ( event ) {
				if ( ! event.dataTransfer || Array.prototype.indexOf.call( event.dataTransfer.types || [], DRAG_TYPE ) < 0 ) {
					return false;
				}

				return event.target.ownerDocument !== document || !! ( event.target.closest && event.target.closest( '.editor-styles-wrapper, .block-editor-block-list__layout' ) );
			};
			const hideLine = function () {
				if ( line && line.parentNode ) {
					line.parentNode.removeChild( line );
				}
				line = null;
			};
			const showLine = function ( doc, point ) {
				if ( ! point.box ) {
					hideLine();
					return;
				}
				if ( ! line || line.ownerDocument !== doc ) {
					hideLine();
					line = doc.createElement( 'div' );
					line.setAttribute( 'data-wpmh-drop-line', '1' );
					doc.body.appendChild( line );
				}
				const thickness = 3;
				const style = point.inside
					? { left: point.box.left + 'px', top: point.box.top + 'px', width: point.box.width + 'px', height: point.box.height + 'px', border: '2px dashed #2271b1', background: 'transparent' }
					: ( point.vertical
						? { left: ( ( point.after ? point.box.right : point.box.left ) - 1 ) + 'px', top: point.box.top + 'px', width: thickness + 'px', height: point.box.height + 'px', background: '#2271b1' }
						: { left: point.box.left + 'px', top: ( ( point.after ? point.box.bottom : point.box.top ) - 1 ) + 'px', width: point.box.width + 'px', height: thickness + 'px', background: '#2271b1' } );
				Object.assign( line.style, { position: 'fixed', zIndex: 100000, pointerEvents: 'none', boxSizing: 'border-box' }, style );
			};
			const onOver = function ( event ) {
				if ( ! mine( event ) ) {
					return;
				}
				event.preventDefault();
				event.dataTransfer.dropEffect = 'copy';
				showLine( event.target.ownerDocument, dropPoint( event ) );
			};
			const onLeave = function ( event ) {
				if ( mine( event ) && ! event.relatedTarget ) {
					hideLine();
				}
			};
			const onDrop = function ( event ) {
				if ( ! mine( event ) ) {
					return;
				}
				// The editor has its own drop zone: this drop is ours.
				event.preventDefault();
				event.stopPropagation();
				hideLine();
				let dragged = [];
				try {
					dragged = JSON.parse( event.dataTransfer.getData( DRAG_TYPE ) );
				} catch ( error ) {
					dragged = [];
				}
				const point = dropPoint( event );
				if ( Array.isArray( dragged ) && dragged.length > 0 && latest.current.insert ) {
					latest.current.insert( dragged.map( function ( entry ) { return Object.assign( { media_type: 'image', can_import: true, is_imported: true }, entry ); } ), point );
				}
			};
			const bind = function ( doc ) {
				if ( ! doc || doc.__wpmhDropBound ) {
					return;
				}
				doc.__wpmhDropBound = true;
				doc.addEventListener( 'dragover', onOver, true );
				doc.addEventListener( 'dragleave', onLeave, true );
				doc.addEventListener( 'drop', onDrop, true );
				doc.addEventListener( 'dragend', hideLine, true );
			};
			const bindAll = function () {
				bind( document );
				const frames = document.querySelectorAll( 'iframe[name="editor-canvas"]' );
				Array.prototype.forEach.call( frames, function ( frame ) {
					try {
						bind( frame.contentDocument );
					} catch ( error ) {
						// A frame of another origin is not the canvas.
					}
				} );
			};
			bindAll();
			const timer = window.setInterval( bindAll, 1500 );

			return function () {
				window.clearInterval( timer );
				hideLine();
			};
		}, [] );


		// The editor loads the post asynchronously: on a page reload the stored meta
		// can arrive after this component first rendered with the default date.
		const storedDate = wp.data.useSelect( function ( select ) {
			const editor = select( 'core/editor' );
			const meta = editor.getEditedPostAttribute( 'meta' ) || {};

			// The date chosen for the post, else the day it was published (which is not saved as a choice).
			return meta[ dateMetaKey ] || publicationDay( editor.getEditedPostAttribute( 'status' ), editor.getEditedPostAttribute( 'date' ) );
		}, [] );

		useEffect( function () {
			if ( storedDate ) {
				setFiltersState( function ( currentFilters ) {
					return currentFilters.date === storedDate ? currentFilters : Object.assign( {}, currentFilters, { date: storedDate } );
				} );
			}
		}, [ storedDate ] );

		const setDate = function ( value ) {
			setFiltersState( function ( currentFilters ) {
				return Object.assign( {}, currentFilters, { date: value } );
			} );

			const metaUpdate = {};
			metaUpdate[ dateMetaKey ] = value;
			wp.data.dispatch( 'core/editor' ).editPost( { meta: metaUpdate } );
		};

		// Keeps a filter from the server's answer when it differs from ours, without re-rendering when it does not.
		const adoptList = function ( key, nextValue ) {
			setFiltersState( function ( currentFilters ) {
				const currentValue = currentFilters[ key ] || [];
				const same = nextValue.length === currentValue.length
					&& nextValue.every( function ( value, index ) { return value === currentValue[ index ]; } );

				return same ? currentFilters : Object.assign( {}, currentFilters, { [ key ]: nextValue } );
			} );
		};

		const applyPayload = function ( payload, requestedFilters, replace ) {
			const incoming = payload.data.files || [];

			setStatus( payload.data.status || 'fresh' );
			setReason( payload.data.reason || null );
			setAvailableSources( payload.data.available_sources || [] );
			setEmptyFilters( Array.isArray( payload.data.empty_filters ) ? payload.data.empty_filters : [] );
			if ( payload.data.attachment_method ) {
				attachmentMethod = normalizeMethod( payload.data.attachment_method );
				setMethodState( attachmentMethod );
			}
			if ( payload.data.pagination ) {
				setPagination( payload.data.pagination );
			}
			if ( payload.data.filters && Array.isArray( payload.data.filters.source ) ) {
				adoptList( 'source', payload.data.filters.source );
			}
			if ( payload.data.filters && Array.isArray( payload.data.filters.attachment_scope ) ) {
				// Bail out on an identical scope so the effect below does not re-fire on every response.
				adoptList( 'attachment_scope', payload.data.filters.attachment_scope );
			}
			if ( payload.data.filters && Array.isArray( payload.data.filters.media_type ) ) {
				adoptList( 'media_type', payload.data.filters.media_type );
			}
			if ( payload.data.filters && payload.data.filters.link && 'object' === typeof payload.data.filters.link && ! Array.isArray( payload.data.filters.link ) ) {
				setFiltersState( function ( currentFilters ) {
					return JSON.stringify( currentFilters.link || null ) === JSON.stringify( payload.data.filters.link ) ? currentFilters : Object.assign( {}, currentFilters, { link: payload.data.filters.link } );
				} );
			}
			if ( payload.data.filters && 'string' === typeof payload.data.filters.filename ) {
				setFiltersState( function ( currentFilters ) {
					if ( Object.prototype.hasOwnProperty.call( requestedFilters, 'filename' ) || Object.prototype.hasOwnProperty.call( currentFilters, 'filename' ) ) {
						return currentFilters;
					}

					return Object.assign( {}, currentFilters, { filename: payload.data.filters.filename } );
				} );
			}

			setFiles( function ( currentFiles ) {
				if ( replace ) {
					return incoming;
				}

				const known = new window.Set( currentFiles.map( function ( file ) { return itemKey( normalizeMediaItem( file ) ); } ) );

				return currentFiles.concat( incoming.filter( function ( file ) { return ! known.has( itemKey( normalizeMediaItem( file ) ) ); } ) );
			} );

			if ( replace ) {
				const nextIds = new window.Set( incoming.map( function ( file ) { return itemKey( normalizeMediaItem( file ) ); } ) );
				setSelectedIds( function ( currentIds ) {
					return currentIds.filter( function ( id ) { return nextIds.has( id ); } );
				} );
			}
			setLastRefreshedAt( Date.now() );
		};

		// Re-render every second so the "refreshed Xs ago" label stays current.
		useEffect( function () {
			const timer = window.setInterval( function () {
				setTick( function ( value ) { return value + 1; } );
			}, 1000 );

			return function () { window.clearInterval( timer ); };
		}, [] );

		// Page 1 replaces the list (and drops answers still on their way); the next pages are appended.
		const loadPage = function ( pageNumber, options ) {
			const settings = options || {};
			const requestedFilters = latest.current.filters;
			const replace = 1 === pageNumber;
			const mine = replace ? ++sequence.current : sequence.current;

			if ( replace ) {
				loadingMore.current = false;
			}
			setLoading( true );

			return fetchState( requestedFilters, !! settings.force, pageNumber )
				.then( function ( payload ) {
					if ( mine !== sequence.current ) {
						return;
					}
					if ( payload && payload.success ) {
						applyPayload( payload, requestedFilters, replace );
						setHasNews( false );
					} else if ( replace && ! settings.force ) {
						setStatus( 'fresh' );
						setReason( null );
						setFiles( [] );
					}
				} )
				.finally( function () {
					if ( mine === sequence.current ) {
						setLoading( false );
						loadingMore.current = false;
					}
				} );
		};

		const loadMore = function () {
			const current = latest.current;
			if ( loadingMore.current || current.loading || current.pagination.page >= current.pagination.total_pages ) {
				return;
			}

			loadingMore.current = true;
			loadPage( current.pagination.page + 1 );
		};

		latest.current = { filters: filters, pagination: pagination, loading: loading, loadMore: loadMore };

		// A change of filter starts the list again from its first lot.
		useEffect( function () {
			loadPage( 1 );
			// eslint-disable-next-line react-hooks/exhaustive-deps
		}, [ filters.date, filters.filename || '', ( filters.attachment_scope ? filters.attachment_scope.join( ',' ) : '(default)' ), ( filters.source ? filters.source.join( ',' ) : '(default)' ), ( filters.media_type ? filters.media_type.join( ',' ) : '(default)' ), JSON.stringify( filters.link || null ), !! filters.show_trash ] );

		// The list is not replaced behind the user's back, which would lose the scroll position and
		// the selection: a check for news (every 30 s, and when the tab is shown again) only offers a refresh.
		useEffect( function () {
			const checkForNews = function () {
				if ( latest.current.loading ) {
					return;
				}

				fetchState( latest.current.filters, false, 1 ).then( function ( payload ) {
					if ( ! payload || ! payload.success || latest.current.loading ) {
						return;
					}

					setStatus( payload.data.status || 'fresh' );
					setReason( payload.data.reason || null );
					setAvailableSources( payload.data.available_sources || [] );
					if ( payload.data.pagination && payload.data.pagination.total !== latest.current.pagination.total ) {
						setHasNews( true );
					}
				} );
			};

			const poller = window.setInterval( checkForNews, AUTO_REFRESH_INTERVAL_MS );
			const handleVisibilityChange = function () {
				if ( 'visible' === document.visibilityState ) {
					checkForNews();
				}
			};
			document.addEventListener( 'visibilitychange', handleVisibilityChange );

			return function () {
				window.clearInterval( poller );
				document.removeEventListener( 'visibilitychange', handleVisibilityChange );
			};
		}, [] );

		// Infinite scroll: the next lot is loaded when the end of the list comes near.
		useEffect( function () {
			const element = sentinel.current;
			if ( ! element || 'function' !== typeof window.IntersectionObserver ) {
				return undefined;
			}

			const observer = new window.IntersectionObserver( function ( entries ) {
				if ( entries.some( function ( entry ) { return entry.isIntersecting; } ) ) {
					latest.current.loadMore();
				}
			}, { rootMargin: '300px' } );
			observer.observe( element );

			return function () { observer.disconnect(); };
		}, [ files.length, pagination.page, pagination.total_pages, loading ] );

		// The width available to the gallery decides the height of its rows. The gallery may be
		// attached to the page after this component (the sidebar renders its content when it is
		// opened), so it is observed from the moment it exists, not from the first render.
		useEffect( function () {
			if ( ! galleryElement ) {
				return undefined;
			}

			const measure = function () {
				const width = Math.floor( galleryElement.getBoundingClientRect().width );
				if ( width > 0 ) {
					setGalleryWidth( width );
				}
			};

			measure();
			if ( 'function' !== typeof window.ResizeObserver ) {
				window.addEventListener( 'resize', measure );

				return function () { window.removeEventListener( 'resize', measure ); };
			}

			const observer = new window.ResizeObserver( measure );
			observer.observe( galleryElement );

			return function () { observer.disconnect(); };
		}, [ galleryElement ] );

		// The style of the "more" buttons, added once.
		useEffect( function () {
			if ( document.getElementById( 'wpmh-gallery-css' ) ) {
				return;
			}

			const style = document.createElement( 'style' );
			style.id = 'wpmh-gallery-css';
			style.textContent = GALLERY_CSS;
			document.head.appendChild( style );
		}, [] );

		// The context menu closes with Escape, a click elsewhere, or a scroll.
		useEffect( function () {
			if ( ! menu ) {
				return undefined;
			}

			const close = function () { setMenu( null ); };
			// A scroll still on its way when the menu opens (the browser reports it a frame late) must not close it.
			const openedAt = Date.now();
			const closeOnScroll = function () {
				if ( Date.now() - openedAt > 250 ) {
					close();
				}
			};
			const onKey = function ( event ) {
				if ( 'Escape' === event.key ) {
					close();
				}
			};
			const onDown = function ( event ) {
				if ( ! event.target.closest || ! event.target.closest( '[role=menu]' ) ) {
					close();
				}
			};
			document.addEventListener( 'keydown', onKey );
			document.addEventListener( 'mousedown', onDown );
			document.addEventListener( 'scroll', closeOnScroll, true );

			return function () {
				document.removeEventListener( 'keydown', onKey );
				document.removeEventListener( 'mousedown', onDown );
				document.removeEventListener( 'scroll', closeOnScroll, true );
			};
		}, [ menu ] );

		const handleRefresh = function () {
			loadPage( 1, { force: true } );
		};

		const handlePanelMode = function ( mode ) {
			setPanelMode( mode );
			setBulkAction( 'none' );
			savePanelMode( mode );
		};

		const chooseDensity = function ( value ) {
			setDensity( value );
			writeDensity( value, !! picker );
		};

		const changeFilterList = function ( key, values ) {
			setFiltersState( function ( currentFilters ) {
				return Object.assign( {}, currentFilters, { [ key ]: values } );
			} );
			saveFilter( key, values, getCurrentPostId() );
		};

		const changeLinkFilter = function ( key, values ) {
			setFiltersState( function ( currentFilters ) {
				return Object.assign( {}, currentFilters, { link: Object.assign( {}, currentFilters.link || {}, { [ key ]: values } ) } );
			} );
			saveFilter( 'link_' + key, values, getCurrentPostId() );
		};

		// The fields of the link that are filters (modes...): the panel's choice of what to attach for. When exactly one value
		// is selected in such a filter, attaching uses it instead of the default of the field.
		const linkContext = function () {
			const data = {};
			const labels = [];
			attachmentMethod.fields.forEach( function ( field ) {
				const selected = filters.link && Array.isArray( filters.link[ field.key ] ) ? filters.link[ field.key ] : null;
				// The values of the field itself: `_native` and `_none` are not values to attach for.
				const real = selected ? selected.filter( function ( value ) { return '_native' !== value && '_none' !== value; } ) : [];
				if ( ! field.filter || 1 !== real.length ) {
					return;
				}
				data[ field.key ] = 'multiselect' === field.type ? [ real[ 0 ] ] : real[ 0 ];
				const option = field.options.filter( function ( candidate ) { return candidate.value === real[ 0 ]; } )[ 0 ];
				labels.push( option ? option.label : real[ 0 ] );
			} );

			return { data: data, labels: labels };
		};

		const withLinkContext = function ( items ) {
			const context = linkContext();

			return 0 === Object.keys( context.data ).length ? items : items.map( function ( item ) { return Object.assign( {}, item, { data: context.data } ); } );
		};

		const changeAttachmentScope = function ( values ) {
			changeFilterList( 'attachment_scope', values );
		};

		const changeMediaType = function ( values ) {
			changeFilterList( 'media_type', values );
		};

		// Every source checked is stored as "all", which also covers the sources added later.
		const changeSource = function ( values ) {
			changeFilterList( 'source', values.length === availableSources.length ? DEFAULT_SOURCE_FILTER : values );
		};

		// Not stored: the trash is listed only while the box is checked.
		const toggleShowTrash = function ( checked ) {
			setSelectedIds( [] );
			setFiltersState( function ( currentFilters ) {
				return Object.assign( {}, currentFilters, { show_trash: !! checked } );
			} );
		};

		const setFilename = function ( value ) {
			const next = value.slice( 0, 255 );
			setFiltersState( function ( currentFilters ) {
				return Object.assign( {}, currentFilters, { filename: next } );
			} );
			saveFilter( 'filename', next, getCurrentPostId() );
		};

		const normalizeFilename = function () {
			const current = filters.filename || '';
			const normalized = normalizeFilenameQuery( current );
			if ( normalized !== current ) {
				setFilename( normalized );
			}
		};


		const toggleSelected = function ( item ) {
			const key = itemKey( item );
			if ( picker && ! canInsert( item ) ) {
				setOperationNotice( __( 'Only photos can be added to a gallery.', 'wp-media-helper' ) );
				return;
			}
			setSelectedIds( function ( currentIds ) {
				return currentIds.includes( key )
					? currentIds.filter( function ( id ) { return id !== key; } )
					: currentIds.concat( [ key ] );
			} );
		};

		const startSelection = function ( item ) {
			setSelectionMode( true );
			if ( item ) {
				setSelectedIds( function ( currentIds ) {
					const key = itemKey( item );

					return currentIds.includes( key ) ? currentIds : currentIds.concat( [ key ] );
				} );
			}
		};

		const endSelection = function () {
			setSelectionMode( false );
			setSelectedIds( [] );
			setBulkAction( 'none' );
		};

		// The picker: the chosen photos go to the end of the gallery, then the modal closes.
		const addToGallery = function () {
			const chosen = files.map( normalizeMediaItem ).filter( function ( item ) { return selectedIds.includes( itemKey( item ) ); } );
			runInsert( chosen, { root: picker.galleryClientId, index: blockEditor().getBlockCount( picker.galleryClientId ) }, picker.onClose );
		};

		const selectAllLoaded = function () {
			setSelectedIds( files.map( function ( file ) { return itemKey( normalizeMediaItem( file ) ); } ) );
		};

		// Trashing or restoring changes the list: the item is updated or removed where it is, without
		// loading the list again (which would lose the scroll position).
		const applyBulkResults = function ( payload ) {
			const results = payload && payload.data && payload.data.results ? payload.data.results : [];
			const resultByKey = {};
			results.forEach( function ( result ) {
				resultByKey[ result.id || result.path ] = result;
			} );

			setFiles( function ( currentFiles ) {
				const next = [];
				currentFiles.forEach( function ( currentFile ) {
					const item = normalizeMediaItem( currentFile );
					const result = resultByKey[ itemKey( item ) ] || resultByKey[ item.path ];
					if ( ! result || ! result.success ) {
						next.push( currentFile );
						return;
					}

					if ( 'trashed' === result.operation && ! latest.current.filters.show_trash ) {
						return;
					}

					const nextItem = Object.assign( {}, item, { is_imported: !! result.is_imported } );
					if ( Object.prototype.hasOwnProperty.call( result, 'is_attached_to_current_post' ) ) {
						nextItem.is_attached_to_current_post = !! result.is_attached_to_current_post;
					}
					if ( result.attachment_id ) {
						nextItem.attachment_ids = [ result.attachment_id ];
					}
					if ( Object.prototype.hasOwnProperty.call( result, 'attached_natively_only' ) ) {
						nextItem.attached_natively_only = !! result.attached_natively_only;
					}
					if ( result.attachment_data && 'object' === typeof result.attachment_data ) {
						nextItem.attachment_data = result.attachment_data;
					} else if ( null === result.attachment_data || ( Object.prototype.hasOwnProperty.call( result, 'is_attached_to_current_post' ) && ! result.is_attached_to_current_post ) ) {
						nextItem.attachment_data = null;
					}
					if ( Object.prototype.hasOwnProperty.call( result, 'is_attached_to_current_post' ) && ! result.is_attached_to_current_post ) {
						nextItem.attached_natively_only = false;
					}
					if ( Object.prototype.hasOwnProperty.call( result, 'is_trashed' ) ) {
						nextItem.is_trashed = !! result.is_trashed;
						if ( result.is_trashed ) {
							nextItem.attachment_ids = [];
							nextItem.is_attached_to_other_post = false;
						}
						// Trashing deletes the previews; restoring brings new addresses. Until then
						// the tile keeps its shape (the dimensions stay) and shows its icon.
						nextItem.thumbnail_url = result.thumbnail_url || '';
						nextItem.thumbnail_large_url = result.thumbnail_large_url || '';
					}
					next.push( nextItem );
				} );

				return next;
			} );

			const removedIds = [];
			results.forEach( function ( result ) {
				( result.removed_ids || [] ).forEach( function ( id ) { removedIds.push( id ); } );
			} );
			if ( featuredSupported && featuredMedia > 0 && removedIds.includes( featuredMedia ) ) {
				setFeatured( 0 );
			}

			const removedKeys = results.filter( function ( result ) {
				return result.success && 'trashed' === result.operation && ! latest.current.filters.show_trash;
			} ).length;
			if ( removedKeys > 0 ) {
				setPagination( function ( current ) {
					return Object.assign( {}, current, { total: Math.max( 0, current.total - removedKeys ) } );
				} );
				setSelectedIds( [] );
			}
			const failures = results.filter( function ( result ) { return ! result.success; } );
			// What a refusal says (a method that protects the media, a missing right) is shown, not only that something failed.
			const reasons = results.map( function ( result ) { return result.message; } ).filter( function ( message, index, all ) { return message && all.indexOf( message ) === index; } );
			if ( failures.length > 0 || reasons.length > 0 ) {
				setOperationNotice( ( failures.length > 0 ? __( 'Some selected items could not be processed.', 'wp-media-helper' ) : '' ) + ( reasons.length > 0 ? ' ' + reasons.slice( 0, 3 ).join( ' ' ) : '' ) );
			}
		};

		const handleBulkAction = function () {
			if ( 'none' === bulkAction || 0 === selectedIds.length ) {
				return;
			}
			// An action chosen in the advanced mode and no longer offered (the mode was switched) is not run.
			if ( 'simple' === panelMode && ! [ 'attach', 'detach', 'insert', 'trash', 'restore', 'attach_native', 'attach_method', 'to_native', 'to_method' ].includes( bulkAction ) ) {
				return;
			}

			let selectedItems = files.map( function ( file ) {
				return normalizeMediaItem( file );
			} ).filter( function ( item ) {
				return selectedIds.includes( itemKey( item ) );
			} );
			if ( 'simple' === panelMode && 'attach' === bulkAction ) {
				selectedItems = selectedItems.filter( function ( item ) { return ! item.is_attached_to_current_post; } );
			}
			// Simple mode never removes a file from the library (it would lose its metadata): it detaches.
			if ( 'simple' === panelMode && 'detach' === bulkAction ) {
				selectedItems = selectedItems.filter( function ( item ) { return item.is_attached_to_current_post; } );
			}
			if ( 0 === selectedItems.length ) {
				return;
			}
			const postId = getCurrentPostId();
			if ( [ 'attach', 'detach', 'remove', 'attach_native', 'attach_method', 'to_native', 'to_method' ].includes( bulkAction ) && 0 === postId ) {
				setOperationNotice( __( 'Save the post before changing media attachments.', 'wp-media-helper' ) );
				return;
			}

			// With both mechanisms in use, each action concerns the files that are in the right state.
			const cohabFilters = {
				attach_native: function ( item ) { return ! item.is_attached_to_current_post; },
				attach_method: function ( item ) { return ! item.is_attached_to_current_post; },
				to_method: function ( item ) { return item.is_attached_to_current_post && item.attached_natively_only; },
				to_native: function ( item ) { return item.is_attached_to_current_post && ! item.attached_natively_only; },
			};
			if ( cohabFilters[ bulkAction ] ) {
				selectedItems = selectedItems.filter( cohabFilters[ bulkAction ] );
				if ( 0 === selectedItems.length ) {
					setOperationNotice( __( 'None of the selected files is in the right state for this action.', 'wp-media-helper' ) );
					return;
				}
			}
			if ( [ 'attach', 'attach_method', 'to_method' ].includes( bulkAction ) && attachmentMethod.fields.length > 0 ) {
				askLinkValues( bulkAction, selectedItems );
				return;
			}

			if ( 'trash' === bulkAction ) {
				setTrashRequest( { items: selectedItems, bulk: true } );
				return;
			}

			if ( 'remove' === bulkAction ) {
				setRemoveRequest( { items: selectedItems } );
				return;
			}

			if ( 'insert' === bulkAction ) {
				runInsert( selectedItems );
				setSelectedIds( [] );
				setBulkAction( 'none' );
				return;
			}

			runBulk( bulkAction, [ 'attach', 'attach_method', 'to_method' ].includes( bulkAction ) ? withLinkContext( selectedItems ) : selectedItems, postId );
		};

		const runBulk = function ( action, selectedItems, postId ) {
			setLoading( true );
			setOperationNotice( null );
			bulkMediaItems( action, selectedItems, postId )
				.then( function ( payload ) {
					if ( payload && payload.success ) {
						applyBulkResults( payload );
						setSelectedIds( [] );
					} else {
						setOperationNotice( payload && payload.data && payload.data.message ? payload.data.message : __( 'The bulk operation failed.', 'wp-media-helper' ) );
					}
				} )
				.finally( function () {
					setLoading( false );
					setBulkAction( 'none' );
				} );
		};

		const handleItemAction = function ( action, item ) {
			if ( ! item || ! item.path ) {
				return;
			}
			if ( 'feature' === action ) {
				handleFeature( item );
				return;
			}
			if ( 'edit' === action || [ 'attach', 'attach_method', 'to_method' ].includes( action ) && attachmentMethod.fields.length > 0 ) {
				askLinkValues( action, [ item ] );
				return;
			}
			if ( 'unfeature' === action ) {
				setFeatured( 0 );
				return;
			}
			if ( 'insert' === action ) {
				runInsert( [ item ] );
				return;
			}
			const postId = getCurrentPostId();
			if ( [ 'attach', 'detach', 'remove', 'update', 'attach_native', 'attach_method', 'to_native', 'to_method' ].includes( action ) && 0 === postId ) {
				setOperationNotice( __( 'Save the post before changing media attachments.', 'wp-media-helper' ) );
				return;
			}
			if ( 'trash' === action ) {
				setTrashRequest( { items: [ item ], bulk: false } );
				return;
			}
			if ( 'remove' === action ) {
				setRemoveRequest( { items: [ item ] } );
				return;
			}

			setLoading( true );
			setOperationNotice( null );
			bulkMediaItems( action, [ 'attach', 'attach_method', 'to_method' ].includes( action ) ? withLinkContext( [ item ] ) : [ item ], postId )
				.then( function ( payload ) {
					if ( payload && payload.success ) {
						applyBulkResults( payload );
						if ( [ 'attach', 'attach_method', 'to_method' ].includes( action ) && linkContext().labels.length > 0 ) {
							announce( sprintf( __( 'Attached for: %s', 'wp-media-helper' ), linkContext().labels.join( ', ' ) ) );
						}
					} else {
						setOperationNotice( payload && payload.data && payload.data.message ? payload.data.message : __( 'The action failed.', 'wp-media-helper' ) );
					}
				} )
				.finally( function () {
					setLoading( false );
				} );
		};

		const announce = function ( message ) {
			try {
				wp.data.dispatch( 'core/notices' ).createNotice( 'success', message, { type: 'snackbar', isDismissible: true } );
			} catch ( error ) {
				// The notice is a courtesy: the change is in the editor either way.
			}
		};

		// Asks the server what to insert, then inserts it at the point (after the selected block when none is given).
		const runInsert = function ( items, point, done ) {
			const candidates = items.filter( function ( item ) { return canInsert( item ); } );
			if ( 0 === candidates.length ) {
				setOperationNotice( __( 'Only photos can be inserted in the content for now.', 'wp-media-helper' ) );
				return;
			}

			setLoading( true );
			setOperationNotice( null );
			fetchInsertData( candidates, getCurrentPostId() )
				.then( function ( payload ) {
					if ( ! payload || ! payload.success ) {
						setOperationNotice( payload && payload.data && payload.data.message ? payload.data.message : __( 'The files could not be inserted.', 'wp-media-helper' ) );
						return;
					}

					const results = payload.data.results || [];
					const placed = payload.data.blocks.length > 0 ? placeBlocks( payload.data.blocks, point || pointAfterSelection() ) : 0;
					const reasons = results.filter( function ( result ) { return ! result.success && result.message; } ).map( function ( result ) { return result.message; } );
					if ( reasons.length > 0 ) {
						setOperationNotice( reasons.slice( 0, 3 ).join( ' ' ) );
					}
					if ( placed > 0 && done ) {
						done();
					}
					if ( placed > 0 ) {
						announce( sprintf( _n( '%d photo inserted in the content.', '%d photos inserted in the content.', placed, 'wp-media-helper' ), placed ) );
						// A file that had no attachment has one now: the tile shows it.
						setFiles( function ( current ) {
							return current.map( function ( file ) {
								const done = results.find( function ( result ) { return result.success && result.path === file.path; } );
								return done ? Object.assign( {}, file, { is_imported: true, attachment_ids: file.attachment_ids && file.attachment_ids.length ? file.attachment_ids : [ done.attachment_id ] } ) : file;
							} );
						} );
					}
				} )
				.finally( function () {
					setLoading( false );
				} );
		};

		// The drop handlers read this one: they are set once and the panel changes.
		if ( ! picker ) {
			latest.current.insert = runInsert;
		}

		const setFeatured = function ( attachmentId ) {
			wp.data.dispatch( 'core/editor' ).editPost( { featured_media: attachmentId } );
			announce( attachmentId > 0
				? __( 'Featured image set. Update the post to save it.', 'wp-media-helper' )
				: __( 'Featured image removed. Update the post to save it.', 'wp-media-helper' ) );
		};

		// A file that is not in the Media Library yet is imported first: a featured image is an attachment.
		const handleFeature = function ( item ) {
			if ( item.attachment_ids.length > 0 ) {
				setFeatured( item.attachment_ids[ 0 ] );
				return;
			}

			setLoading( true );
			setOperationNotice( null );
			bulkMediaItems( 'import', [ item ], getCurrentPostId() )
				.then( function ( payload ) {
					const result = payload && payload.success && payload.data.results ? payload.data.results[ 0 ] : null;
					if ( result && result.success && result.attachment_id ) {
						applyBulkResults( payload );
						setFeatured( result.attachment_id );
					} else {
						setOperationNotice( result && result.message ? result.message : __( 'The image could not be imported.', 'wp-media-helper' ) );
					}
				} )
				.finally( function () { setLoading( false ); } );
		};

		// What can be done with an item, depending on the mode and on where it is attached.
		const actionsFor = function ( item ) {
			if ( item.is_trashed ) {
				return canTrash ? [ { action: 'restore', label: __( 'Restore from trash', 'wp-media-helper' ) } ] : [];
			}

			const actions = [];
			// *Remove from library* comes last, after the move to the trash: both lose something.
			let removal = null;
			// A file WordPress does not accept cannot be imported or attached; the sheet says why.
			const importable = item.can_import || item.is_imported;
			// One image is the featured image: no bulk action, and only a picture can be.
			if ( featuredSupported && canUpload && importable && 'image' === item.media_type ) {
				actions.push( isFeatured( item )
					? { action: 'unfeature', label: __( 'Remove featured image', 'wp-media-helper' ) }
					: { action: 'feature', label: __( 'Set as featured image', 'wp-media-helper' ) } );
			}
			if ( canInsert( item ) ) {
				actions.push( { action: 'insert', label: __( 'Insert in the content', 'wp-media-helper' ) } );
			}
			if ( attachmentMethod.cohabitation ) {
				// Both mechanisms are in use: a file is attached the WordPress way or through the method, and can be switched.
				if ( importable ) {
					const methodName = attachmentMethod.label || __( 'the method', 'wp-media-helper' );
					if ( ! item.is_attached_to_current_post ) {
						actions.push( { action: 'attach_native', label: __( 'Attach (WordPress)', 'wp-media-helper' ) } );
						actions.push( { action: 'attach_method', label: sprintf( __( 'Attach with %s…', 'wp-media-helper' ), methodName ) } );
					} else if ( item.attached_natively_only ) {
						actions.push( { action: 'to_method', label: sprintf( __( 'Switch to %s…', 'wp-media-helper' ), methodName ) } );
					} else {
						actions.push( { action: 'to_native', label: __( 'Switch to WordPress', 'wp-media-helper' ) } );
					}
					if ( item.is_attached_to_current_post ) {
						actions.push( { action: 'detach', label: __( 'Detach from post', 'wp-media-helper' ) } );
					}
				}
				if ( importable && item.is_imported && ! item.is_attached_to_current_post && 'simple' !== panelMode ) {
					removal = { action: 'remove', label: __( 'Remove from library', 'wp-media-helper' ), destructive: true };
				}
			} else if ( ( ! blockedElsewhere( item ) ) && importable ) {
				if ( 'simple' === panelMode ) {
					// Detaching keeps the record in the library, and its metadata; removing it is for the advanced mode.
					actions.push( item.is_attached_to_current_post
						? { action: 'detach', label: __( 'Detach from post', 'wp-media-helper' ) }
						: { action: 'attach', label: __( 'Attach to post', 'wp-media-helper' ) } );
				} else {
					if ( item.is_imported ) {
						removal = { action: 'remove', label: __( 'Remove from library', 'wp-media-helper' ), destructive: true };
					} else {
						actions.push( { action: 'import', label: __( 'Import', 'wp-media-helper' ) } );
					}
					actions.push( item.is_attached_to_current_post
						? { action: 'detach', label: __( 'Detach from post', 'wp-media-helper' ) }
						: { action: 'attach', label: __( 'Attach to post', 'wp-media-helper' ) } );
				}
			}
			// The data of the link (modes, position...) that the method declares.
			if ( attachmentMethod.fields.length > 0 && item.is_attached_to_current_post && ! item.attached_natively_only && importable ) {
				actions.push( { action: 'edit', label: editLabel() } );
			}
			if ( canTrash ) {
				actions.push( { action: 'trash', label: __( 'Move to trash', 'wp-media-helper' ), destructive: true } );
			}
			if ( removal ) {
				actions.push( removal );
			}

			return actions;
		};

		const cancelLongPress = function () {
			if ( longPress.current.timer ) {
				window.clearTimeout( longPress.current.timer );
				longPress.current.timer = null;
			}
		};

		// Same behaviour with a finger and a mouse: moving the pressed tile within the delay is a drag (which cancels the timer),
		// holding it still for the whole delay enters selection mode.
		const pressStart = function ( item ) {
			cancelLongPress();
			longPress.current.fired = false;
			if ( selectionMode ) {
				return;
			}

			longPress.current.timer = window.setTimeout( function () {
				longPress.current.fired = true;
				longPress.current.timer = null;
				startSelection( item );
			}, LONG_PRESS_MS );
		};

		const closeMenu = function () {
			setMenu( null );
		};

		// A context menu opened with the keyboard (the menu key, Shift+F10) has no pointer position:
		// it opens under the tile.
		const openMenu = function ( event, item ) {
			event.preventDefault();
			const box = event.currentTarget.getBoundingClientRect();
			const fromKeyboard = 0 === event.clientX && 0 === event.clientY;

			setMenu( { key: itemKey( item ), x: fromKeyboard ? box.left : event.clientX, y: fromKeyboard ? box.bottom : event.clientY } );
		};

		const openMenuFromButton = function ( event, item ) {
			event.stopPropagation();
			const box = event.currentTarget.getBoundingClientRect();
			setMenu( { key: itemKey( item ), x: box.left, y: box.bottom } );
		};

		// A click is about the selection in selection mode (and with Ctrl/Shift), and opens the detail sheet otherwise.
		const handleTileClick = function ( event, item ) {
			cancelLongPress();
			if ( longPress.current.fired ) {
				longPress.current.fired = false;
				event.preventDefault();
				return;
			}

			if ( selectionMode ) {
				toggleSelected( item );
			} else if ( event.ctrlKey || event.metaKey || event.shiftKey ) {
				startSelection( item );
			} else {
				setSheetKey( itemKey( item ) );
			}
		};

		// Dragging a tile (or all the selected ones when it is one of them) carries the paths and the sources, never addresses.
		const startDrag = function ( event, item ) {
			cancelLongPress();
			if ( ! DRAG_ENABLED || ! canInsert( item ) || ! event.dataTransfer ) {
				event.preventDefault();
				return;
			}

			const key = itemKey( item );
			const dragged = selectionMode && selectedIds.includes( key )
				? files.map( normalizeMediaItem ).filter( function ( candidate ) { return selectedIds.includes( itemKey( candidate ) ) && canInsert( candidate ); } )
				: [ item ];
			event.dataTransfer.clearData();
			event.dataTransfer.setData( DRAG_TYPE, JSON.stringify( dragged.map( function ( candidate ) { return { id: candidate.id, path: candidate.path, source_id: candidate.source_id }; } ) ) );
			event.dataTransfer.setData( 'text/plain', dragged.map( function ( candidate ) { return candidate.name; } ).join( '\n' ) );
			event.dataTransfer.effectAllowed = 'copy';
		};

		const renderTile = function ( item, row, position ) {
			const key = itemKey( item );
			const selected = selectedIds.includes( key );

			return wp.element.createElement( 'div', {
				key: key,
				role: 'listitem',
				className: 'wpmh-tile',
				style: { position: 'relative', flex: '0 0 auto', width: row.widths[ position ] + 'px', height: row.height + 'px' }
			},
				// Not a button: Firefox does not start a drag from a button.
				wp.element.createElement( 'div', {
					role: 'button',
					tabIndex: 0,
					draggable: DRAG_ENABLED && canInsert( item ),
					onDragStart: function ( event ) { startDrag( event, item ); },
					onKeyDown: function ( event ) {
						if ( 'Enter' === event.key || ' ' === event.key ) {
							event.preventDefault();
							handleTileClick( event, item );
						}
					},
					onClick: function ( event ) { handleTileClick( event, item ); },
					onContextMenu: function ( event ) { cancelLongPress(); openMenu( event, item ); },
					onPointerDown: function () { pressStart( item ); },
					onPointerUp: cancelLongPress,
					onPointerLeave: cancelLongPress,
					onPointerCancel: cancelLongPress,
					'aria-label': item.name + ' – ' + stateLabel( item ),
					'aria-pressed': selectionMode ? selected : undefined,
					'aria-haspopup': 'menu',
					style: {
						position: 'absolute', inset: 0, width: '100%', height: '100%', padding: 0, margin: 0, border: 0,
						background: '#f0f0f1', cursor: 'pointer', overflow: 'hidden', borderRadius: '2px', opacity: item.is_trashed ? 0.55 : 1,
						outline: selected ? '3px solid #2271b1' : 'none', outlineOffset: '-3px', userSelect: 'none', WebkitTouchCallout: 'none', touchAction: 'manipulation'
					}
				},
					wp.element.createElement( TileContent, { item: item, perRow: density } ),
					wp.element.createElement( AttachmentBadge, { item: item } ),
					item.is_imported
						? wp.element.createElement( CornerBadge, { icon: 'admin-media', label: __( 'In the WordPress media library', 'wp-media-helper' ), position: { left: '3px' }, colour: '#2271b1' } )
						: null,
					isFeatured( item )
						? wp.element.createElement( CornerBadge, { icon: 'star-filled', label: __( 'Featured image', 'wp-media-helper' ), position: { left: '24px' }, colour: '#b8860b' } )
						: null,
					wp.element.createElement( LinkValueBadges, { item: item } ),
					item.attached_natively_only
						? wp.element.createElement( CornerBadge, { icon: attachmentMethod.cohabitation ? 'wordpress' : 'update', label: stateLabel( item ), position: { bottom: '3px', right: '3px' }, colour: attachmentMethod.cohabitation ? '#2271b1' : '#996800' } )
						: null,
					! item.can_import && ! item.is_imported
						? wp.element.createElement( CornerBadge, { icon: 'warning', label: item.import_blocker || __( 'This file cannot be imported.', 'wp-media-helper' ), position: { bottom: '3px', left: '3px' }, colour: '#996800' } )
						: null,
					item.is_trashed
						? wp.element.createElement( CornerBadge, { icon: 'trash', label: __( 'In the trash', 'wp-media-helper' ), position: { right: '3px' }, colour: '#8a2424' } )
						: null
				),
				selectionMode
					? wp.element.createElement( 'span', {
						'aria-hidden': true,
						style: { position: 'absolute', top: '3px', right: '3px', width: '20px', height: '20px', borderRadius: '50%', border: '2px solid #fff', boxShadow: '0 0 2px rgba(0,0,0,0.6)', background: selected ? '#2271b1' : 'rgba(0,0,0,0.25)', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: '13px', lineHeight: 1, pointerEvents: 'none' }
					}, selected ? '✓' : '' )
					: wp.element.createElement( 'button', {
						type: 'button',
						className: 'wpmh-more',
						onClick: function ( event ) { openMenuFromButton( event, item ); },
						'aria-label': sprintf( __( 'Actions for %s', 'wp-media-helper' ), item.name ),
						'aria-haspopup': 'menu',
						style: { position: 'absolute', top: '3px', right: '3px', width: '22px', height: '22px', padding: 0, border: 0, borderRadius: '50%', background: 'rgba(255,255,255,0.92)', boxShadow: '0 0 2px rgba(0,0,0,0.5)', cursor: 'pointer', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: '15px', lineHeight: 1, color: '#1d2327' }
					}, '⋮' )
			);
		};

		// The actions of an item as a menu, opened by a right click, the menu key or the "more" button.
		const renderContextMenu = function () {
			const item = menu ? visibleItems.find( function ( candidate ) { return itemKey( candidate ) === menu.key; } ) : null;
			if ( ! menu || ! item ) {
				return null;
			}

			const actions = actionsFor( item );
			const left = Math.max( 4, Math.min( menu.x, window.innerWidth - 240 ) );
			const top = Math.max( 4, Math.min( menu.y, window.innerHeight - ( 60 + 34 * ( actions.length + 2 ) ) ) );
			const choose = function ( callback ) {
				return function () {
					closeMenu();
					callback();
				};
			};

			return wp.element.createElement( 'div', {
				ref: function ( element ) { if ( element && ! element.contains( document.activeElement ) ) { const first = element.querySelector( 'button' ); if ( first ) { first.focus( { preventScroll: true } ); } } },
				role: 'menu',
				'aria-label': sprintf( __( 'Actions for %s', 'wp-media-helper' ), item.name ),
				onKeyDown: function ( event ) {
					const buttons = Array.from( event.currentTarget.querySelectorAll( 'button' ) );
					const at = buttons.indexOf( document.activeElement );
					if ( 'ArrowDown' === event.key ) {
						event.preventDefault();
						buttons[ ( at + 1 ) % buttons.length ].focus();
					} else if ( 'ArrowUp' === event.key ) {
						event.preventDefault();
						buttons[ ( at - 1 + buttons.length ) % buttons.length ].focus();
					}
				},
				style: { position: 'fixed', left: left + 'px', top: top + 'px', zIndex: 1000000, minWidth: '200px', maxWidth: '240px', background: '#fff', border: '1px solid #949494', borderRadius: '2px', boxShadow: '0 3px 10px rgba(0,0,0,0.25)', padding: '6px 0' }
			},
				wp.element.createElement( 'div', { style: { padding: '2px 12px 6px', fontSize: '12px', fontWeight: 600, overflowWrap: 'anywhere', borderBottom: '1px solid #ddd', marginBottom: '4px' } }, item.name ),
				wp.element.createElement( MenuItem, { key: 'details', onClick: choose( function () { setSheetKey( itemKey( item ) ); } ) }, __( 'Details', 'wp-media-helper' ) ),
				actions.map( function ( entry ) {
					return wp.element.createElement( MenuItem, {
						key: entry.action,
						disabled: loading,
						isDestructive: !! entry.destructive,
						style: entry.destructive ? { color: '#b32d2e' } : undefined,
						onClick: choose( function () { handleItemAction( entry.action, item ); } )
					}, entry.label );
				} ),
				wp.element.createElement( MenuItem, { key: 'select', onClick: choose( function () { startSelection( item ); } ) }, __( 'Select', 'wp-media-helper' ) )
			);
		};

		// The detail sheet: a large preview, what is known about the file, and its actions.
		// The move to the trash cannot be undone for the media library entries, so it is asked for.
		const renderTrashDialog = function () {
			if ( ! trashRequest ) {
				return null;
			}

			const items = trashRequest.items;
			const imported = items.filter( function ( item ) { return item.is_imported; } ).length;
			const elsewhere = items.filter( function ( item ) { return item.is_attached_to_other_post; } );
			const featured = items.filter( isFeatured ).length;
			const close = function () { setTrashRequest( null ); };
			const danger = { color: '#b32d2e', fontWeight: 600 };
			const run = function () {
				setTrashRequest( null );
				runBulk( 'trash', items, getCurrentPostId() );
			};

			return wp.element.createElement( Modal, {
				title: 1 === items.length ? sprintf( __( 'Move %s to the trash?', 'wp-media-helper' ), items[ 0 ].name ) : sprintf( _n( 'Move %d file to the trash?', 'Move %d files to the trash?', items.length, 'wp-media-helper' ), items.length ),
				onRequestClose: close,
				className: 'wpmh-trash-dialog'
			},
				wp.element.createElement( 'p', null, _n( 'The file stays on the disk, untouched, and can be restored to the gallery from the trash.', 'The files stay on the disk, untouched, and can be restored to the gallery from the trash.', items.length, 'wp-media-helper' ) ),
				imported > 0
					? wp.element.createElement( 'p', { style: danger, role: 'alert' },
						1 === items.length
							? __( 'It loses its title, caption and description, leaves every post it is attached to and is no longer a featured image. Restoring the file brings none of this back.', 'wp-media-helper' )
							: ( imported < items.length
								? sprintf( _n( '%d of these files is in the media library: it loses its title, caption and description, leaves every post it is attached to and is no longer a featured image. Restoring the file brings none of this back.', '%d of these files are in the media library: they lose their titles, captions and descriptions, leave every post they are attached to and are no longer featured images. Restoring the files brings none of this back.', imported, 'wp-media-helper' ), imported )
								: __( 'They lose their titles, captions and descriptions, leave every post they are attached to and are no longer featured images. Restoring the files brings none of this back.', 'wp-media-helper' ) )
					)
					: wp.element.createElement( 'p', { style: { color: '#1d2327' } },
						_n( 'It is not in the WordPress media library: nothing else is lost.', 'None of them is in the WordPress media library: nothing else is lost.', items.length, 'wp-media-helper' )
					),
				elsewhere.length > 0
					? wp.element.createElement( 'p', { style: danger },
						__( 'Attached to other posts: ', 'wp-media-helper' ) + elsewhere.map( function ( item ) {
							return item.other_posts.length > 0 ? item.other_posts.map( function ( post ) { return post.title || ( '#' + post.id ); } ).join( ', ' ) : ( item.other_post_title || ( '#' + item.other_post_id ) );
						} ).join( ', ' )
					)
					: null,
				featured > 0
					? wp.element.createElement( 'p', { style: danger }, __( 'It is the featured image of this post: the featured image will be removed.', 'wp-media-helper' ) )
					: null,
				wp.element.createElement( 'p', { style: { color: '#50575e', fontSize: '12px' } }, __( 'An image inserted in the content of a post as a block keeps showing, since the file is still there.', 'wp-media-helper' ) ),
				wp.element.createElement( 'div', { style: { display: 'flex', gap: '8px', justifyContent: 'flex-end' } },
					wp.element.createElement( Button, { isSecondary: true, onClick: close, text: __( 'Cancel', 'wp-media-helper' ) } ),
					wp.element.createElement( Button, { isDestructive: true, isPrimary: true, onClick: run, text: __( 'Move to trash', 'wp-media-helper' ) } )
				)
			);
		};

		// Removing the record from the library loses what it holds: it is asked for, like the trash.
		const renderRemoveDialog = function () {
			if ( ! removeRequest ) {
				return null;
			}

			const items = removeRequest.items;
			const elsewhere = items.filter( function ( item ) { return item.is_attached_to_other_post; } );
			const featured = items.filter( isFeatured ).length;
			const close = function () { setRemoveRequest( null ); };
			const danger = { color: '#b32d2e', fontWeight: 600 };
			const run = function () {
				setRemoveRequest( null );
				runBulk( 'remove', items, getCurrentPostId() );
			};

			return wp.element.createElement( Modal, {
				title: 1 === items.length ? sprintf( __( 'Remove %s from the media library?', 'wp-media-helper' ), items[ 0 ].name ) : sprintf( _n( 'Remove %d file from the media library?', 'Remove %d files from the media library?', items.length, 'wp-media-helper' ), items.length ),
				onRequestClose: close,
				className: 'wpmh-remove-dialog'
			},
				wp.element.createElement( 'p', null, _n( 'The file stays on the disk, untouched, and stays in the gallery of this panel.', 'The files stay on the disk, untouched, and stay in the gallery of this panel.', items.length, 'wp-media-helper' ) ),
				wp.element.createElement( 'p', { style: danger, role: 'alert' },
					_n( 'Its record in the WordPress media library is deleted with what it holds: its title, caption, description and alternative text, and its links to posts (the modes, the positions). It leaves every post it is attached to and is no longer a featured image. Importing the file again makes a new record without any of this.', 'Their records in the WordPress media library are deleted with what they hold: titles, captions, descriptions and alternative texts, and their links to posts (the modes, the positions). They leave every post they are attached to and are no longer featured images. Importing the files again makes new records without any of this.', items.length, 'wp-media-helper' )
				),
				elsewhere.length > 0
					? wp.element.createElement( 'p', { style: danger },
						__( 'Attached to other posts: ', 'wp-media-helper' ) + elsewhere.map( function ( item ) {
							return item.other_posts.length > 0 ? item.other_posts.map( function ( post ) { return post.title || ( '#' + post.id ); } ).join( ', ' ) : ( item.other_post_title || ( '#' + item.other_post_id ) );
						} ).join( ', ' )
					)
					: null,
				featured > 0
					? wp.element.createElement( 'p', { style: danger }, __( 'It is the featured image of this post: the featured image will be removed.', 'wp-media-helper' ) )
					: null,
				wp.element.createElement( 'p', { style: { color: '#50575e', fontSize: '12px' } }, __( 'To keep the record, only detach the file from the post.', 'wp-media-helper' ) ),
				wp.element.createElement( 'div', { style: { display: 'flex', gap: '8px', justifyContent: 'flex-end' } },
					wp.element.createElement( Button, { isSecondary: true, onClick: close, text: __( 'Cancel', 'wp-media-helper' ) } ),
					wp.element.createElement( Button, { isDestructive: true, isPrimary: true, onClick: run, text: __( 'Remove from library', 'wp-media-helper' ) } )
				)
			);
		};

		// The values of the fields of a link to ask for: those of the link when it is edited, else the selection of the field
		// filters when it holds one value, else the defaults.
		const askLinkValues = function ( action, items ) {
			const context = linkContext().data;
			const values = {};
			attachmentMethod.fields.forEach( function ( field ) {
				const first = items[ 0 ];
				if ( 'edit' === action && 1 === items.length && first.attachment_data && Object.prototype.hasOwnProperty.call( first.attachment_data, field.key ) ) {
					values[ field.key ] = first.attachment_data[ field.key ];
				} else if ( Object.prototype.hasOwnProperty.call( context, field.key ) ) {
					values[ field.key ] = context[ field.key ];
				} else {
					values[ field.key ] = field.default;
				}
			} );
			setLinkEdit( { action: action, items: items, values: values } );
		};

		const renderLinkDialog = function () {
			if ( ! linkEdit ) {
				return null;
			}

			const close = function () { setLinkEdit( null ); };
			const methodName = attachmentMethod.label || __( 'the method', 'wp-media-helper' );
			const single = 1 === linkEdit.items.length ? linkEdit.items[ 0 ].name : '';
			const titles = {
				edit: attachmentMethod.fields.length > 0 && attachmentMethod.fields[ 0 ].label
					? sprintf( __( '%1$s of %2$s', 'wp-media-helper' ), attachmentMethod.fields[ 0 ].label, single )
					: sprintf( __( 'Link of %s with this post', 'wp-media-helper' ), single ),
				attach: single ? sprintf( __( 'Attach %1$s with %2$s', 'wp-media-helper' ), single, methodName ) : sprintf( _n( 'Attach %1$d file with %2$s', 'Attach %1$d files with %2$s', linkEdit.items.length, 'wp-media-helper' ), linkEdit.items.length, methodName ),
				attach_method: single ? sprintf( __( 'Attach %1$s with %2$s', 'wp-media-helper' ), single, methodName ) : sprintf( _n( 'Attach %1$d file with %2$s', 'Attach %1$d files with %2$s', linkEdit.items.length, 'wp-media-helper' ), linkEdit.items.length, methodName ),
				to_method: single ? sprintf( __( 'Switch %1$s to %2$s', 'wp-media-helper' ), single, methodName ) : sprintf( _n( 'Switch %1$d file to %2$s', 'Switch %1$d files to %2$s', linkEdit.items.length, 'wp-media-helper' ), linkEdit.items.length, methodName ),
			};
			const save = function () {
				const request = linkEdit;
				const items = request.items.map( function ( item ) { return Object.assign( {}, item, { data: request.values } ); } );
				setLinkEdit( null );
				if ( 'edit' !== request.action ) {
					runBulk( request.action, items, getCurrentPostId() );
					return;
				}

				setLoading( true );
				setOperationNotice( null );
				bulkMediaItems( 'update', items, getCurrentPostId() )
					.then( function ( payload ) {
						const result = payload && payload.success && payload.data.results ? payload.data.results[ 0 ] : null;
						if ( result && result.success ) {
							applyBulkResults( payload );
						} else {
							setOperationNotice( result && result.message ? result.message : __( 'The link could not be changed.', 'wp-media-helper' ) );
						}
					} )
					.finally( function () { setLoading( false ); } );
			};

			return wp.element.createElement( Modal, {
				title: titles[ linkEdit.action ] || titles.edit,
				onRequestClose: close,
				className: 'wpmh-link-dialog'
			},
				'edit' === linkEdit.action ? null : wp.element.createElement( 'p', { style: { fontSize: '12px', color: '#50575e' } }, __( 'Choose the values of the link.', 'wp-media-helper' ) ),
				attachmentMethod.fields.map( function ( field ) {
					return wp.element.createElement( FieldControl, {
						key: field.key,
						field: field,
						value: linkEdit.values[ field.key ],
						onChange: function ( next ) {
							setLinkEdit( function ( current ) {
								return Object.assign( {}, current, { values: Object.assign( {}, current.values, { [ field.key ]: next } ) } );
							} );
						}
					} );
				} ),
				wp.element.createElement( 'div', { style: { display: 'flex', gap: '8px', justifyContent: 'flex-end' } },
					wp.element.createElement( Button, { isSecondary: true, onClick: close, text: __( 'Cancel', 'wp-media-helper' ) } ),
					wp.element.createElement( Button, { isPrimary: true, onClick: save, text: 'edit' === linkEdit.action ? __( 'Save', 'wp-media-helper' ) : __( 'Apply', 'wp-media-helper' ) } )
				)
			);
		};

		const renderSheet = function () {
			const index = sheetKey ? visibleItems.findIndex( function ( candidate ) { return itemKey( candidate ) === sheetKey; } ) : -1;
			if ( index < 0 ) {
				return null;
			}

			const item = visibleItems[ index ];
			const actions = actionsFor( item );
			const source = availableSources.find( function ( candidate ) { return candidate.id === item.source_id; } );
			const picture = item.thumbnail_large_url || item.thumbnail_url;
			const rowsOfInfo = [
				[ __( 'Type', 'wp-media-helper' ), String( item.type || '' ).toUpperCase() ],
				[ __( 'Dimensions', 'wp-media-helper' ), item.width > 0 ? item.width + ' × ' + item.height + ' px' : '' ],
				[ __( 'Size', 'wp-media-helper' ), formatBytes( item.file_size ) ],
				[ __( 'Date', 'wp-media-helper' ), item.effective_date ? item.effective_date + ( dateSourceLabel( item.date_source ) ? ' (' + dateSourceLabel( item.date_source ) + ')' : '' ) : '' ],
				[ __( 'Source', 'wp-media-helper' ), source ? source.name : '' ],
				[ __( 'File', 'wp-media-helper' ), item.path ],
			].concat( attachmentMethod.fields.length > 0 && item.attachment_data
				? attachmentMethod.fields.map( function ( field ) {
					return [ field.label, formatFieldValue( field, item.attachment_data[ field.key ] ) ];
				} )
				: [] ).concat( attachmentMethod.multiple_posts && item.other_posts_count > 0
				? [ [ __( 'Also in', 'wp-media-helper' ), item.other_posts.map( function ( post ) { return post.title || ( '#' + post.id ); } ).join( ', ' ) + ( item.other_posts_count > item.other_posts.length ? ' …' : '' ) ] ]
				: [] ).filter( function ( entry ) { return entry[ 1 ]; } );
			const go = function ( offset ) {
				const next = visibleItems[ index + offset ];
				if ( next ) {
					setSheetKey( itemKey( next ) );
				}
			};

			return wp.element.createElement( Modal, {
				title: item.name,
				onRequestClose: function () { setSheetKey( null ); },
				className: 'wpmh-sheet'
			},
				wp.element.createElement( 'div', {
					onKeyDown: function ( event ) {
						if ( 'INPUT' === event.target.tagName || 'TEXTAREA' === event.target.tagName ) {
							return;
						}
						if ( 'ArrowLeft' === event.key ) {
							go( -1 );
						} else if ( 'ArrowRight' === event.key ) {
							go( 1 );
						}
					},
					style: { maxWidth: '560px' }
				},
					picture
						? wp.element.createElement( 'img', { src: picture, alt: item.name, draggable: false, style: { display: 'block', maxWidth: '100%', maxHeight: '55vh', margin: '0 auto 12px', objectFit: 'contain', background: '#f0f0f1' } } )
						: null,
					wp.element.createElement( 'p', { style: { fontWeight: 600, color: blockedElsewhere( item ) ? '#b32d2e' : ( item.is_attached_to_current_post ? '#0a7d45' : '#50575e' ) } },
						blockedElsewhere( item ) && item.other_post_title ? sprintf( __( 'Attached to another post: %s', 'wp-media-helper' ), item.other_post_title ) : stateLabel( item ),
						blockedElsewhere( item ) && item.other_post_edit_url
							? [ ' ', wp.element.createElement( 'a', { key: 'edit', href: item.other_post_edit_url, target: '_blank', rel: 'noreferrer', style: { fontWeight: 400 } }, __( 'Open that post', 'wp-media-helper' ) ) ]
							: null,
						item.is_imported ? wp.element.createElement( 'span', { style: { color: '#2271b1', fontWeight: 400 } }, ' · ' + __( 'In the WordPress media library', 'wp-media-helper' ) ) : null,
						isFeatured( item ) ? wp.element.createElement( 'span', { style: { color: '#b8860b', fontWeight: 400 } }, ' · ' + __( 'Featured image', 'wp-media-helper' ) ) : null,
						item.is_trashed ? wp.element.createElement( 'span', { style: { color: '#8a2424', fontWeight: 400 } }, ' · ' + __( 'In the trash', 'wp-media-helper' ) ) : null
					),
					! item.can_import && ! item.is_imported
						? wp.element.createElement( 'p', { style: { color: '#996800', margin: '0 0 8px' } }, item.import_blocker || __( 'This file cannot be imported.', 'wp-media-helper' ) )
						: null,
					wp.element.createElement( 'dl', { style: { display: 'grid', gridTemplateColumns: 'max-content 1fr', gap: '2px 12px', margin: '0 0 12px', fontSize: '12px' } },
						rowsOfInfo.map( function ( entry ) {
							return [
								wp.element.createElement( 'dt', { key: entry[ 0 ] + 't', style: { color: '#50575e' } }, entry[ 0 ] ),
								wp.element.createElement( 'dd', { key: entry[ 0 ] + 'd', style: { margin: 0, overflowWrap: 'anywhere' } }, entry[ 1 ] ),
							];
						} )
					),
					wp.element.createElement( 'div', { style: { display: 'flex', gap: '8px', flexWrap: 'wrap', alignItems: 'center' } },
						actions.map( function ( entry ) {
							return wp.element.createElement( Button, {
								key: entry.action,
								isPrimary: entry === actions[ 0 ],
								isSecondary: entry !== actions[ 0 ] && ! entry.destructive,
								isDestructive: !! entry.destructive,
								disabled: loading,
								onClick: function () { handleItemAction( entry.action, item ); },
								text: entry.label
							} );
						} ),
						wp.element.createElement( 'span', { style: { marginLeft: 'auto', display: 'flex', gap: '4px' } },
							wp.element.createElement( Button, { isSecondary: true, disabled: index <= 0, onClick: function () { go( -1 ); }, 'aria-label': __( 'Previous file', 'wp-media-helper' ), text: '‹' } ),
							wp.element.createElement( Button, { isSecondary: true, disabled: index >= visibleItems.length - 1, onClick: function () { go( 1 ); }, 'aria-label': __( 'Next file', 'wp-media-helper' ), text: '›' } )
						)
					)
				)
			);
		};

		const visibleItems = files.map( function ( file ) {
			return normalizeMediaItem( file );
		} );
		const rows = galleryWidth > 0 ? layoutRows( visibleItems, galleryWidth, density ) : [];
		const currentAttachmentScope = filters.attachment_scope || defaultAttachmentScope();
		const currentMediaType = filters.media_type || DEFAULT_MEDIA_TYPE;
		const currentSourceFilter = filters.source || DEFAULT_SOURCE_FILTER;
		const checkedSourceIds = currentSourceFilter.includes( 'all' )
			? availableSources.map( function ( source ) { return source.id; } )
			: currentSourceFilter;
		const methodName = attachmentMethod.label || __( 'the method', 'wp-media-helper' );
		const visibleBulkActions = BULK_ACTIONS.filter( function ( action ) {
			const cohabitationOnly = [ 'attach_native', 'attach_method', 'to_method', 'to_native' ].includes( action.value );
			if ( cohabitationOnly ) {
				return !! attachmentMethod.cohabitation;
			}
			if ( attachmentMethod.cohabitation && [ 'attach', 'import' ].includes( action.value ) ) {
				return false;
			}
			if ( 'insert' === action.value ) {
				return !! wpMediaHelperEditorPanel.canUpload;
			}
			if ( 'trash' === action.value ) {
				return canTrash;
			}
			if ( 'restore' === action.value ) {
				return canTrash && !! filters.show_trash;
			}

			return 'advanced' === panelMode || [ 'attach', 'detach', 'insert' ].includes( action.value );
		} ).map( function ( action ) {
			if ( 'attach_method' === action.value ) {
				return Object.assign( {}, action, { label: sprintf( __( 'Attach with %s…', 'wp-media-helper' ), methodName ) } );
			}
			if ( 'to_method' === action.value ) {
				return Object.assign( {}, action, { label: sprintf( __( 'Switch to %s…', 'wp-media-helper' ), methodName ) } );
			}

			return action;
		} );
		const emptyFilterLabels = {
			source: __( 'Sources', 'wp-media-helper' ),
			attachment_scope: __( 'Attachment', 'wp-media-helper' ),
			media_type: __( 'Media type', 'wp-media-helper' ),
		};
		// Puts back "all" in each filter that has nothing checked.
		const checkEmptyFilters = function () {
			emptyFilters.forEach( function ( key ) {
				if ( 'source' === key ) {
					changeFilterList( 'source', DEFAULT_SOURCE_FILTER );
				} else if ( 'attachment_scope' === key ) {
					changeFilterList( 'attachment_scope', ATTACHMENT_SCOPE_STATES.map( function ( state ) { return state.value; } ) );
				} else if ( 'media_type' === key ) {
					changeFilterList( 'media_type', DEFAULT_MEDIA_TYPE );
				}
			} );
		};
		const hasMore = pagination.page < pagination.total_pages;
		const densityControl = wp.element.createElement( 'div', { role: 'group', 'aria-label': __( 'Images per row', 'wp-media-helper' ), style: { display: 'inline-flex', border: '1px solid #949494', borderRadius: '3px', overflow: 'hidden' } },
			densityChoices.map( function ( value, index ) {
				return wp.element.createElement( Button, {
					key: value,
					isPrimary: density === value,
					isSecondary: density !== value,
					onClick: function () { chooseDensity( value ); },
					'aria-pressed': density === value,
					'aria-label': sprintf( _n( '%d image per row', '%d images per row', value, 'wp-media-helper' ), value ),
					style: { border: 0, borderLeft: 0 === index ? 0 : '1px solid #949494', borderRadius: 0, minWidth: '2rem', justifyContent: 'center' },
					text: String( value )
				} );
			} )
		);

		return wp.element.createElement(
			picker ? 'div' : PluginSidebar,
			picker
				? { className: 'wpmh-picker' }
				: {
					name: 'wp-media-helper-sidebar',
					title: __( 'Media Helper', 'wp-media-helper' )
				},
			wp.element.createElement(
				PanelBody,
				{ title: __( 'External media', 'wp-media-helper' ), initialOpen: true },
				wp.element.createElement(
					PanelRow,
					null,
					wp.element.createElement( 'div', { role: 'group', 'aria-label': __( 'Panel mode', 'wp-media-helper' ), style: { display: 'inline-flex', border: '1px solid #949494', borderRadius: '3px', overflow: 'hidden' } },
						PANEL_MODES.map( function ( mode, index ) {
							return wp.element.createElement( Button, {
								key: mode.value,
								isSecondary: panelMode !== mode.value,
								isPrimary: panelMode === mode.value,
								disabled: loading,
								onClick: function () { handlePanelMode( mode.value ); },
								'aria-pressed': panelMode === mode.value,
								style: { border: 0, borderLeft: 0 === index ? 0 : '1px solid #949494', borderRadius: 0, minWidth: '5.25rem' },
								text: mode.label
							} );
						} )
					)
				),
				wp.element.createElement(
					PanelRow,
					null,
					wp.element.createElement( 'div', { style: { width: '100%', borderTop: '1px solid #ddd', borderBottom: '1px solid #ddd', padding: '10px 0' } },
						wp.element.createElement( 'h3', { style: { margin: '0 0 8px', color: '#50575e', fontSize: '11px', fontWeight: 600, textTransform: 'uppercase' } }, __( 'Filters', 'wp-media-helper' ) ),
						wp.element.createElement( TextControl, {
							label: __( 'Date', 'wp-media-helper' ),
							value: filters.date,
							onChange: setDate,
							type: 'date'
						} ),
						wp.element.createElement( TextControl, {
							label: __( 'Filename', 'wp-media-helper' ),
							value: filters.filename || '',
							onChange: setFilename,
							onBlur: normalizeFilename,
							maxLength: 255,
							placeholder: __( 'Search filenames', 'wp-media-helper' ),
							type: 'search'
						} ),
						availableSources.length > 1
							? wp.element.createElement( FilterCheckboxGroup, {
								label: __( 'Sources', 'wp-media-helper' ),
								options: availableSources.map( function ( source ) {
									return { value: source.id, label: source.name };
								} ),
								selectedValues: checkedSourceIds,
								disabled: loading,
								onChange: changeSource
							} )
							: null,
						wp.element.createElement( FilterCheckboxGroup, {
							label: __( 'Attachment', 'wp-media-helper' ),
							options: ATTACHMENT_SCOPE_STATES,
							selectedValues: currentAttachmentScope,
							disabled: loading,
							onChange: changeAttachmentScope
						} ),
						wp.element.createElement( FilterCheckboxGroup, {
							label: __( 'Media type', 'wp-media-helper' ),
							options: MEDIA_TYPE_OPTIONS,
							selectedValues: currentMediaType,
							disabled: loading,
							onChange: changeMediaType
						} ),
						attachmentMethod.fields.filter( function ( field ) { return field.filter; } ).map( function ( field, index ) {
							// With both mechanisms in use the first filter says which is shown: the WordPress way, and each value of the method.
							const withNative = attachmentMethod.cohabitation && 0 === index;
							const selected = filters.link && Array.isArray( filters.link[ field.key ] ) ? filters.link[ field.key ] : field.filter_default.concat( withNative ? [ '_native' ] : [] );

							return wp.element.createElement( FilterCheckboxGroup, {
								key: 'link-' + field.key,
								label: withNative ? __( 'Attached through', 'wp-media-helper' ) : field.label,
								options: ( withNative ? [ { value: '_native', label: __( 'WordPress', 'wp-media-helper' ), icon: 'wordpress' } ] : [] ).concat( field.options ).concat( [ { value: '_none', label: __( 'None or not attached', 'wp-media-helper' ) } ] ),
								selectedValues: selected,
								disabled: loading,
								onChange: function ( values ) { changeLinkFilter( field.key, values ); }
							} );
						} ),
						canSeeTrash
							? wp.element.createElement( 'div', { style: { marginTop: '12px', paddingTop: '10px', borderTop: '1px solid #ddd' } },
								wp.element.createElement( CheckboxControl, {
									label: __( 'Show the trash', 'wp-media-helper' ),
									checked: !! filters.show_trash,
									disabled: loading,
									onChange: toggleShowTrash
								} )
							)
							: null
					)
				),
				wp.element.createElement(
					PanelRow,
					null,
					wp.element.createElement( Button, {
						isPrimary: true,
						onClick: handleRefresh,
						disabled: loading,
						text: loading ? __( 'Refreshing…', 'wp-media-helper' ) : __( 'Refresh', 'wp-media-helper' )
					} )
				),
				wp.element.createElement(
					PanelRow,
					null,
					status === 'stale'
						? wp.element.createElement( Notice, { status: 'warning', isDismissible: false },
							describeReason( reason ) )
						: null
				),
				wp.element.createElement(
					PanelRow,
					null,
					hasNews
						? wp.element.createElement( Notice, { status: 'info', isDismissible: false, actions: [ { label: __( 'Refresh the list', 'wp-media-helper' ), onClick: handleRefresh } ] },
							__( 'The list has changed.', 'wp-media-helper' ) )
						: wp.element.createElement( 'span', { style: { color: '#757575', fontSize: '12px' } }, formatElapsed( lastRefreshedAt ) )
				),
				wp.element.createElement(
					PanelRow,
					null,
					wp.element.createElement( 'div', { style: { width: '100%' } },
						operationNotice
							? wp.element.createElement( Notice, { status: 'warning', isDismissible: true, onRemove: function () { setOperationNotice( null ); } }, operationNotice )
							: null,
						// How many files the filters match (all pages), how many are on screen, how many are selected.
						wp.element.createElement( 'div', { role: 'status', style: { margin: '0 0 0.5rem', color: '#50575e', fontSize: '12px' } },
							sprintf( _n( '%d file', '%d files', pagination.total, 'wp-media-helper' ), pagination.total ),
							selectedIds.length > 0
								? ' · ' + sprintf( _n( '%d selected', '%d selected', selectedIds.length, 'wp-media-helper' ), selectedIds.length )
								: ''
						),
						picker
							? wp.element.createElement( 'div', { style: { display: 'flex', gap: '0.5rem', alignItems: 'center', marginBottom: '0.75rem', flexWrap: 'wrap', position: 'sticky', top: 0, background: '#fff', padding: '4px 0', zIndex: 2 } },
								wp.element.createElement( Button, {
									isPrimary: true,
									disabled: loading || 0 === selectedIds.length,
									onClick: addToGallery,
									text: 0 === selectedIds.length
										? __( 'Add to the gallery', 'wp-media-helper' )
										: sprintf( _n( 'Add %d photo to the gallery', 'Add %d photos to the gallery', selectedIds.length, 'wp-media-helper' ), selectedIds.length )
								} ),
								densityControl,
								wp.element.createElement( Button, { isLink: true, disabled: loading || 0 === visibleItems.length, onClick: selectAllLoaded, text: __( 'Select all', 'wp-media-helper' ) } ),
								wp.element.createElement( Button, { isLink: true, onClick: picker.onClose, text: __( 'Cancel', 'wp-media-helper' ) } )
							)
							: selectionMode
							? wp.element.createElement( 'div', { style: { display: 'flex', gap: '0.5rem', alignItems: 'center', marginBottom: '0.75rem', flexWrap: 'wrap' } },
								wp.element.createElement( Dropdown, {
									className: 'wp-media-helper-bulk-actions',
									disabled: loading,
									renderToggle: function ( { isOpen, onToggle } ) {
										const selectedAction = visibleBulkActions.find( function ( action ) {
											return action.value === bulkAction;
										} );

										return wp.element.createElement( Button, {
											isSecondary: true,
											icon: bulkActionIcon,
											onClick: onToggle,
											'aria-haspopup': 'menu',
											'aria-expanded': isOpen,
											text: selectedAction ? selectedAction.label : __( 'Bulk action', 'wp-media-helper' )
										} );
									},
									renderContent: function ( { onClose } ) {
										return visibleBulkActions.map( function ( action ) {
											return wp.element.createElement( MenuItem, {
												key: action.value,
												isDestructive: [ 'remove', 'trash' ].includes( action.value ),
												style: [ 'remove', 'trash' ].includes( action.value ) ? { color: '#b32d2e' } : undefined,
												onClick: function () {
													setBulkAction( action.value );
													onClose();
												}
											}, action.label );
										} );
									}
								} ),
								wp.element.createElement( Button, {
									isSecondary: true,
									disabled: loading || 0 === selectedIds.length,
									onClick: handleBulkAction,
									text: __( 'Apply', 'wp-media-helper' )
								} ),
								wp.element.createElement( Button, { isLink: true, disabled: loading || 0 === visibleItems.length, onClick: selectAllLoaded, text: __( 'Select all', 'wp-media-helper' ) } ),
								wp.element.createElement( Button, { isLink: true, onClick: endSelection, text: __( 'Cancel', 'wp-media-helper' ) } )
							)
							: wp.element.createElement( 'div', { style: { display: 'flex', gap: '0.5rem', alignItems: 'center', justifyContent: 'space-between', marginBottom: '0.75rem' } },
								densityControl,
								wp.element.createElement( Button, { isSecondary: true, disabled: loading || 0 === visibleItems.length, onClick: function () { startSelection( null ); }, text: __( 'Select', 'wp-media-helper' ) } )
							),
						wp.element.createElement( 'div', { ref: setGalleryElement, role: 'list', 'aria-label': __( 'Media files', 'wp-media-helper' ), style: { display: 'flex', flexDirection: 'column', gap: GAP + 'px', width: '100%' } },
							rows.map( function ( row, rowIndex ) {
								return wp.element.createElement( 'div', { key: rowIndex, role: 'presentation', style: { display: 'flex', gap: GAP + 'px' } },
									row.items.map( function ( item, position ) {
										return renderTile( item, row, position );
									} )
								);
							} )
						),
						0 === visibleItems.length && ! loading && emptyFilters.length > 0
							? wp.element.createElement( 'div', { role: 'status', style: { fontSize: '12px' } },
								wp.element.createElement( 'p', { style: { color: '#b32d2e', fontWeight: 600 } },
									sprintf( __( 'Nothing is selected in: %s.', 'wp-media-helper' ), emptyFilters.map( function ( key ) { return emptyFilterLabels[ key ] || key; } ).join( ', ' ) )
								),
								wp.element.createElement( Button, { isSecondary: true, onClick: checkEmptyFilters, text: __( 'Select all', 'wp-media-helper' ) } )
							)
							: null,
						0 === visibleItems.length && ! loading && 0 === emptyFilters.length
							? wp.element.createElement( 'p', { style: { color: '#757575', fontSize: '12px' } }, __( 'No file for these filters.', 'wp-media-helper' ) )
							: null,
						// Reaching this marker loads the next lot.
						wp.element.createElement( 'div', { ref: sentinel, style: { minHeight: '1px', margin: '0.5rem 0', textAlign: 'center', color: '#757575', fontSize: '12px' } },
							hasMore && loading ? __( 'Loading…', 'wp-media-helper' ) : ''
						),
						renderContextMenu(),
						renderSheet(),
						renderTrashDialog(),
						renderRemoveDialog(),
						renderLinkDialog()
					)
				)
			)
		);
	};

	// A button in the toolbar of the gallery block opens the panel in a modal; the photos chosen are added to that gallery.
	if ( canUpload && wp.hooks && wp.blockEditor && wp.blockEditor.BlockControls && wp.components.ToolbarButton ) {
		wp.hooks.addFilter( 'editor.BlockEdit', 'wp-media-helper/gallery-picker', function ( BlockEdit ) {
			return function ( props ) {
				const [ open, setOpen ] = useState( false );
				const show = 'core/gallery' === props.name && props.isSelected;
				const close = function () { setOpen( false ); };

				return wp.element.createElement( wp.element.Fragment, null,
					wp.element.createElement( BlockEdit, props ),
					show
						? wp.element.createElement( wp.blockEditor.BlockControls, { group: 'other' },
							wp.element.createElement( wp.components.ToolbarButton, {
								icon: 'images-alt2',
								label: __( 'Add from the Media Helper panel', 'wp-media-helper' ),
								onClick: function () { setOpen( true ); }
							} )
						)
						: null,
					show && open
						? wp.element.createElement( Modal, { title: __( 'Add to the gallery from the Media Helper panel', 'wp-media-helper' ), onRequestClose: close, isFullScreen: true },
							wp.element.createElement( MediaPanel, { picker: { galleryClientId: props.clientId, onClose: close } } )
						)
						: null
				);
			};
		} );
	}

	registerPlugin( 'wp-media-helper', {
		render: MediaPanel,
		icon: 'format-image'
	} );
} )( window.wp );
