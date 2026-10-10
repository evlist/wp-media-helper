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
	const defaultPanelMode = 'advanced' === wpMediaHelperEditorPanel.panelMode ? 'advanced' : 'simple';
	const dateMetaKey = 'wp_media_helper_date';

	// Background poll interval; the manual Refresh button always forces an immediate check.
	const AUTO_REFRESH_INTERVAL_MS = 30000;
	const BULK_ACTIONS = [
		{ value: 'import', label: __( 'Import', 'wp-media-helper' ) },
		{ value: 'remove', label: __( 'Remove', 'wp-media-helper' ) },
		{ value: 'attach', label: __( 'Attach to post', 'wp-media-helper' ) },
		{ value: 'detach', label: __( 'Detach from post', 'wp-media-helper' ) },
		{ value: 'trash', label: __( 'Move to trash', 'wp-media-helper' ) },
		{ value: 'restore', label: __( 'Restore from trash', 'wp-media-helper' ) },
	];
	const PANEL_MODES = [
		{ value: 'simple', label: __( 'Simple', 'wp-media-helper' ) },
		{ value: 'advanced', label: __( 'Advanced', 'wp-media-helper' ) },
	];
	const DEFAULT_ATTACHMENT_SCOPE = [ 'unattached', 'current' ];
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
			return { id: item.id, path: item.path, source_id: item.source_id };
		} ) ) );

		return window.fetch( endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData,
		} ).then( function ( response ) {
			return response.json();
		} );
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
			summary = selectedLabels.join( ', ' );
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
	const DENSITY_KEY = 'wpMediaHelperDensity';
	const GAP = 2;
	const LONG_PRESS_MS = 500;

	// Images per row, remembered in this browser.
	const readDensity = function () {
		try {
			const value = Number( window.localStorage.getItem( DENSITY_KEY ) );

			return DENSITIES.includes( value ) ? value : 2;
		} catch ( error ) {
			return 2;
		}
	};

	const writeDensity = function ( value ) {
		try {
			window.localStorage.setItem( DENSITY_KEY, String( value ) );
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

	const stateLabel = function ( item ) {
		if ( item.is_attached_to_other_post ) {
			return __( 'Attached to another post', 'wp-media-helper' );
		}

		return item.is_attached_to_current_post ? __( 'Attached to this post', 'wp-media-helper' ) : __( 'Not attached', 'wp-media-helper' );
	};

	// A paperclip says how the file relates to the post: green, attached here; red with a lock,
	// attached to another post; crossed out, not attached. The shape differs as well as the colour.
	const AttachmentBadge = function ( { item } ) {
		const other = item.is_attached_to_other_post;
		const here = item.is_attached_to_current_post;
		const colour = other ? '#b32d2e' : ( here ? '#0a7d45' : '#646970' );
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
				! other && ! here
					? wp.element.createElement( 'path', { d: 'M3 3l18 18', stroke: colour } )
					: null
			)
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

	const MediaPanel = function () {
		const [ filters, setFiltersState ] = useState( function () { return { date: getStoredDate() }; } );
		const [ availableSources, setAvailableSources ] = useState( [] );
		const [ status, setStatus ] = useState( 'fresh' );
		const [ reason, setReason ] = useState( null );
		const [ files, setFiles ] = useState( [] );
		const [ pagination, setPagination ] = useState( { page: 1, per_page: 0, total: 0, total_pages: 1 } );
		const [ loading, setLoading ] = useState( false );
		const [ selectedIds, setSelectedIds ] = useState( [] );
		const [ selectionMode, setSelectionMode ] = useState( false );
		const [ bulkAction, setBulkAction ] = useState( 'none' );
		const [ panelMode, setPanelMode ] = useState( defaultPanelMode );
		const [ density, setDensity ] = useState( readDensity );
		const [ galleryWidth, setGalleryWidth ] = useState( 0 );
		const [ hasNews, setHasNews ] = useState( false );
		// The filters with nothing checked, as the server reports them: the list is empty because of them.
		const [ emptyFilters, setEmptyFilters ] = useState( [] );
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
		}, [ filters.date, filters.filename || '', ( filters.attachment_scope ? filters.attachment_scope.join( ',' ) : '(default)' ), ( filters.source ? filters.source.join( ',' ) : '(default)' ), ( filters.media_type ? filters.media_type.join( ',' ) : '(default)' ), !! filters.show_trash ] );

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
			writeDensity( value );
		};

		const changeFilterList = function ( key, values ) {
			setFiltersState( function ( currentFilters ) {
				return Object.assign( {}, currentFilters, { [ key ]: values } );
			} );
			saveFilter( key, values, getCurrentPostId() );
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
			if ( failures.length > 0 ) {
				setOperationNotice( __( 'Some selected items could not be processed.', 'wp-media-helper' ) );
			}
		};

		const handleBulkAction = function () {
			if ( 'none' === bulkAction || 0 === selectedIds.length ) {
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
			if ( 'simple' === panelMode && 'remove' === bulkAction ) {
				selectedItems = selectedItems.filter( function ( item ) { return item.is_attached_to_current_post; } );
			}
			if ( 0 === selectedItems.length ) {
				return;
			}
			const postId = getCurrentPostId();
			if ( [ 'attach', 'detach', 'remove' ].includes( bulkAction ) && 0 === postId ) {
				setOperationNotice( __( 'Save the post before changing media attachments.', 'wp-media-helper' ) );
				return;
			}

			if ( 'trash' === bulkAction ) {
				setTrashRequest( { items: selectedItems, bulk: true } );
				return;
			}

			runBulk( bulkAction, selectedItems, postId );
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
			if ( 'unfeature' === action ) {
				setFeatured( 0 );
				return;
			}
			const postId = getCurrentPostId();
			if ( [ 'attach', 'detach', 'remove' ].includes( action ) && 0 === postId ) {
				setOperationNotice( __( 'Save the post before changing media attachments.', 'wp-media-helper' ) );
				return;
			}
			if ( 'trash' === action ) {
				setTrashRequest( { items: [ item ], bulk: false } );
				return;
			}

			setLoading( true );
			setOperationNotice( null );
			bulkMediaItems( action, [ item ], postId )
				.then( function ( payload ) {
					if ( payload && payload.success ) {
						applyBulkResults( payload );
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
			// A file WordPress does not accept cannot be imported or attached; the sheet says why.
			const importable = item.can_import || item.is_imported;
			// One image is the featured image: no bulk action, and only a picture can be.
			if ( featuredSupported && canUpload && importable && 'image' === item.media_type ) {
				actions.push( isFeatured( item )
					? { action: 'unfeature', label: __( 'Remove featured image', 'wp-media-helper' ) }
					: { action: 'feature', label: __( 'Set as featured image', 'wp-media-helper' ) } );
			}
			if ( ! item.is_attached_to_other_post && importable ) {
				if ( 'simple' === panelMode ) {
					actions.push( item.is_attached_to_current_post
						? { action: 'remove', label: __( 'Remove', 'wp-media-helper' ) }
						: { action: 'attach', label: __( 'Attach to post', 'wp-media-helper' ) } );
				} else {
					actions.push( item.is_imported
						? { action: 'remove', label: __( 'Remove from library', 'wp-media-helper' ) }
						: { action: 'import', label: __( 'Import', 'wp-media-helper' ) } );
					actions.push( item.is_attached_to_current_post
						? { action: 'detach', label: __( 'Detach from post', 'wp-media-helper' ) }
						: { action: 'attach', label: __( 'Attach to post', 'wp-media-helper' ) } );
				}
			}
			if ( canTrash ) {
				actions.push( { action: 'trash', label: __( 'Move to trash', 'wp-media-helper' ), destructive: true } );
			}

			return actions;
		};

		const cancelLongPress = function () {
			if ( longPress.current.timer ) {
				window.clearTimeout( longPress.current.timer );
				longPress.current.timer = null;
			}
		};

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

		const renderTile = function ( item, row, position ) {
			const key = itemKey( item );
			const selected = selectedIds.includes( key );

			return wp.element.createElement( 'div', {
				key: key,
				role: 'listitem',
				className: 'wpmh-tile',
				style: { position: 'relative', flex: '0 0 auto', width: row.widths[ position ] + 'px', height: row.height + 'px' }
			},
				wp.element.createElement( 'button', {
					type: 'button',
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
					: null,
				elsewhere.length > 0
					? wp.element.createElement( 'p', { style: danger },
						__( 'Attached to other posts: ', 'wp-media-helper' ) + elsewhere.map( function ( item ) { return item.other_post_title || ( '#' + item.other_post_id ); } ).join( ', ' )
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
			].filter( function ( entry ) { return entry[ 1 ]; } );
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
					wp.element.createElement( 'p', { style: { fontWeight: 600, color: item.is_attached_to_other_post ? '#b32d2e' : ( item.is_attached_to_current_post ? '#0a7d45' : '#50575e' ) } },
						item.is_attached_to_other_post && item.other_post_title ? sprintf( __( 'Attached to another post: %s', 'wp-media-helper' ), item.other_post_title ) : stateLabel( item ),
						item.is_attached_to_other_post && item.other_post_edit_url
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
		const currentAttachmentScope = filters.attachment_scope || DEFAULT_ATTACHMENT_SCOPE;
		const currentMediaType = filters.media_type || DEFAULT_MEDIA_TYPE;
		const currentSourceFilter = filters.source || DEFAULT_SOURCE_FILTER;
		const checkedSourceIds = currentSourceFilter.includes( 'all' )
			? availableSources.map( function ( source ) { return source.id; } )
			: currentSourceFilter;
		const visibleBulkActions = BULK_ACTIONS.filter( function ( action ) {
			if ( 'trash' === action.value ) {
				return canTrash;
			}
			if ( 'restore' === action.value ) {
				return canTrash && !! filters.show_trash;
			}

			return 'advanced' === panelMode || [ 'attach', 'remove' ].includes( action.value );
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

		return wp.element.createElement(
			PluginSidebar,
			{
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
						selectionMode
							? wp.element.createElement( 'div', { style: { display: 'flex', gap: '0.5rem', alignItems: 'center', marginBottom: '0.75rem', flexWrap: 'wrap' } },
								wp.element.createElement( Dropdown, {
									className: 'wp-media-helper-bulk-actions',
									disabled: loading,
									renderToggle: function ( { isOpen, onToggle } ) {
										const selectedAction = BULK_ACTIONS.find( function ( action ) {
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
								wp.element.createElement( 'div', { role: 'group', 'aria-label': __( 'Images per row', 'wp-media-helper' ), style: { display: 'inline-flex', border: '1px solid #949494', borderRadius: '3px', overflow: 'hidden' } },
									DENSITIES.map( function ( value, index ) {
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
								),
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
						renderTrashDialog()
					)
				)
			)
		);
	};

	registerPlugin( 'wp-media-helper', {
		render: MediaPanel,
		icon: 'format-image'
	} );
} )( window.wp );
