( function ( wp ) {
	'use strict';

	const { __, sprintf, _n } = wp.i18n;
	const { registerPlugin } = wp.plugins;
	const { PluginSidebar } = wp.editPost;
	const { PanelBody, PanelRow, Button, TextControl, Notice, Dropdown, MenuItem, CheckboxControl } = wp.components;
	// An icon font may be missing in some versions: the tile then shows its text only.
	const Dashicon = wp.components.Dashicon || function () { return null; };
	const chevronDown = wp.icons && wp.icons.chevronDown ? wp.icons.chevronDown : null;
	const bulkActionIcon = chevronDown || wp.element.createElement( 'span', { 'aria-hidden': true, style: { fontSize: '12px', lineHeight: 1 } }, '\u25BE' );
	const { useState, useEffect, useRef } = wp.element;

	const endpoint = wpMediaHelperEditorPanel.ajaxUrl;
	const nonce = wpMediaHelperEditorPanel.nonce;
	const defaultDate = wpMediaHelperEditorPanel.date;
	const canHide = !! wpMediaHelperEditorPanel.canHide;
	const canSeeHidden = !! wpMediaHelperEditorPanel.canSeeHidden;
	const defaultPanelMode = 'advanced' === wpMediaHelperEditorPanel.panelMode ? 'advanced' : 'simple';
	const dateMetaKey = 'wp_media_helper_date';

	// Background poll interval; the manual Refresh button always forces an immediate check.
	const AUTO_REFRESH_INTERVAL_MS = 30000;
	const BULK_ACTIONS = [
		{ value: 'import', label: __( 'Import', 'wp-media-helper' ) },
		{ value: 'remove', label: __( 'Remove', 'wp-media-helper' ) },
		{ value: 'attach', label: __( 'Attach to post', 'wp-media-helper' ) },
		{ value: 'detach', label: __( 'Detach from post', 'wp-media-helper' ) },
		{ value: 'hide', label: __( 'Hide', 'wp-media-helper' ) },
		{ value: 'show', label: __( 'Show again', 'wp-media-helper' ) },
	];
	const PANEL_MODES = [
		{ value: 'simple', label: __( 'Simple', 'wp-media-helper' ) },
		{ value: 'advanced', label: __( 'Advanced', 'wp-media-helper' ) },
	];
	const DEFAULT_ATTACHMENT_SCOPE = [ 'unattached', 'current' ];
	const DEFAULT_MEDIA_TYPE = [ 'image', 'video', 'other' ];
	const DEFAULT_SOURCE_FILTER = [ 'all' ];
	const ATTACHMENT_SCOPE_STATES = [
		{ value: 'unattached', label: __( 'Unattached', 'wp-media-helper' ) },
		{ value: 'current', label: __( 'Attached to this post', 'wp-media-helper' ) },
		{ value: 'other', label: __( 'Attached to another post', 'wp-media-helper' ) },
	];
	const MEDIA_TYPE_OPTIONS = [
		{ value: 'image', label: __( 'Images', 'wp-media-helper' ) },
		{ value: 'video', label: __( 'Videos', 'wp-media-helper' ) },
		{ value: 'other', label: __( 'Other', 'wp-media-helper' ) },
	];

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

	const getStoredDate = function () {
		const meta = wp.data.select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};

		return meta[ dateMetaKey ] || defaultDate;
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
			is_hidden: !! ( item && item.is_hidden ),
			thumbnail_url: item && item.thumbnail_url ? item.thumbnail_url : '',
			thumbnail_large_url: item && item.thumbnail_large_url ? item.thumbnail_large_url : '',
			width: item && item.width ? item.width : 0,
			height: item && item.height ? item.height : 0,
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

	const FilterCheckboxGroup = function ( { label, options, selectedValues, disabled, minSelected, onToggle } ) {
		const selectedLabels = options.filter( function ( option ) {
			return selectedValues.includes( option.value );
		} ).map( function ( option ) {
			return option.label;
		} );
		const selectionSummary = selectedLabels.length === options.length
			? __( 'All', 'wp-media-helper' )
			: selectedLabels.length > 0 ? selectedLabels.join( ', ' ) : __( 'None', 'wp-media-helper' );

		return wp.element.createElement( 'details', { style: { borderTop: '1px solid #ddd' } },
			wp.element.createElement( 'summary', { style: { cursor: 'pointer', padding: '7px 0' } },
				wp.element.createElement( 'span', { style: { display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', gap: '0.5rem' } },
					wp.element.createElement( 'strong', { style: { fontSize: '12px', fontWeight: 600, flexShrink: 0 } }, label ),
					wp.element.createElement( 'span', { style: { color: '#757575', fontSize: '11px', minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', textAlign: 'right' } }, selectionSummary )
				)
			),
			wp.element.createElement( 'div', { role: 'group', 'aria-label': label, style: { display: 'flex', flexDirection: 'column', gap: '0.1rem', padding: '0 0 8px' } },
				options.map( function ( option ) {
					const checked = selectedValues.includes( option.value );
					return wp.element.createElement( 'label', { key: option.value, style: { display: 'flex', alignItems: 'center', gap: '0.3rem', fontSize: '12px' } },
						wp.element.createElement( 'input', {
							type: 'checkbox',
							checked: checked,
							disabled: disabled || ( checked && selectedValues.length <= minSelected ),
							onChange: function () { onToggle( option.value ); }
						} ),
						option.label
					);
				} )
			)
		);
	};


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

	// Rows of `perRow` thumbnails that fill the width, each keeping its proportions: the height of
	// a row is what makes the widths of its images add up to the available width.
	const layoutRows = function ( items, width, perRow ) {
		const rows = [];
		const usable = Math.max( 1, width );

		for ( let index = 0; index < items.length; index += perRow ) {
			const group = items.slice( index, index + perRow );
			const total = group.reduce( function ( sum, item ) { return sum + aspectOf( item ); }, 0 );
			let height = Math.max( 1, usable - GAP * ( group.length - 1 ) ) / total;

			if ( group.length < perRow ) {
				// A short last row is not stretched to the full width.
				height = Math.min( height, ( usable - GAP * ( perRow - 1 ) ) / ( perRow * 1.5 ) );
			}

			rows.push( {
				items: group,
				height: Math.round( Math.max( 48, Math.min( height, usable * 1.2 ) ) ),
			} );
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

		const icon = 'video' === item.media_type ? 'video-alt3' : ( 'image' === item.media_type ? 'format-image' : 'media-default' );

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
		const [ galleryWidth, setGalleryWidth ] = useState( 260 );
		const [ hasNews, setHasNews ] = useState( false );
		const [ operationNotice, setOperationNotice ] = useState( null );
		const [ lastRefreshedAt, setLastRefreshedAt ] = useState( null );
		const [ , setTick ] = useState( 0 );

		// What the long-lived callbacks (timers, observers) need to see at the time they run.
		const latest = useRef( {} );
		const sequence = useRef( 0 );
		const loadingMore = useRef( false );
		const sentinel = useRef( null );
		const gallery = useRef( null );
		const longPress = useRef( { timer: null, fired: false } );

		// The editor loads the post asynchronously: on a page reload the stored meta
		// can arrive after this component first rendered with the default date.
		const storedDate = wp.data.useSelect( function ( select ) {
			const meta = select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};

			return meta[ dateMetaKey ] || '';
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
		}, [ filters.date, filters.filename || '', ( filters.attachment_scope || [] ).join( ',' ), ( filters.source || [] ).join( ',' ), ( filters.media_type || [] ).join( ',' ), !! filters.show_hidden ] );

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

		// The width available to the gallery decides the height of its rows.
		useEffect( function () {
			const element = gallery.current;
			if ( ! element ) {
				return undefined;
			}

			setGalleryWidth( element.clientWidth || 260 );
			if ( 'function' !== typeof window.ResizeObserver ) {
				return undefined;
			}

			const observer = new window.ResizeObserver( function () {
				setGalleryWidth( element.clientWidth || 260 );
			} );
			observer.observe( element );

			return function () { observer.disconnect(); };
		}, [] );

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

		const toggleAttachmentScope = function ( state ) {
			const current = filters.attachment_scope || DEFAULT_ATTACHMENT_SCOPE;
			const next = current.includes( state )
				? current.filter( function ( value ) { return value !== state; } )
				: current.concat( [ state ] );

			if ( 0 === next.length ) {
				return;
			}

			setFiltersState( Object.assign( {}, filters, { attachment_scope: next } ) );
			saveFilter( 'attachment_scope', next, getCurrentPostId() );
		};

		const toggleMediaType = function ( mediaType ) {
			const current = filters.media_type || DEFAULT_MEDIA_TYPE;
			const next = current.includes( mediaType )
				? current.filter( function ( value ) { return value !== mediaType; } )
				: MEDIA_TYPE_OPTIONS.map( function ( option ) { return option.value; } ).filter( function ( value ) {
					return current.includes( value ) || value === mediaType;
				} );

			if ( 0 === next.length ) {
				return;
			}

			setFiltersState( function ( currentFilters ) {
				return Object.assign( {}, currentFilters, { media_type: next } );
			} );
			saveFilter( 'media_type', next, getCurrentPostId() );
		};

		// Not stored: hidden files are listed only while the box is checked.
		const toggleShowHidden = function ( checked ) {
			setSelectedIds( [] );
			setFiltersState( function ( currentFilters ) {
				return Object.assign( {}, currentFilters, { show_hidden: !! checked } );
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

		const toggleSource = function ( sourceId ) {
			const current = filters.source || DEFAULT_SOURCE_FILTER;
			let next;

			if ( 'all' === sourceId ) {
				next = DEFAULT_SOURCE_FILTER;
			} else if ( current.includes( 'all' ) ) {
				next = availableSources
					.map( function ( source ) { return source.id; } )
					.filter( function ( id ) { return id !== sourceId; } );
			} else {
				next = current.includes( sourceId )
					? current.filter( function ( value ) { return value !== sourceId; } )
					: current.concat( [ sourceId ] );

				if ( 0 === next.length ) {
					next = DEFAULT_SOURCE_FILTER;
				} else if ( availableSources.length === next.length ) {
					next = DEFAULT_SOURCE_FILTER;
				}
			}

			setFiltersState( function ( currentFilters ) {
				return Object.assign( {}, currentFilters, { source: next } );
			} );
			saveFilter( 'source', next, getCurrentPostId() );
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

		// Hiding or showing changes the list: the item is updated or removed where it is, without
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

					if ( 'hidden' === result.operation && ! latest.current.filters.show_hidden ) {
						return;
					}

					const nextItem = Object.assign( {}, item, { is_imported: !! result.is_imported } );
					if ( Object.prototype.hasOwnProperty.call( result, 'is_attached_to_current_post' ) ) {
						nextItem.is_attached_to_current_post = !! result.is_attached_to_current_post;
					}
					if ( Object.prototype.hasOwnProperty.call( result, 'is_hidden' ) ) {
						nextItem.is_hidden = !! result.is_hidden;
						// Hiding deletes the previews; showing again brings new addresses. Until then
						// the tile keeps its shape (the dimensions stay) and shows its icon.
						nextItem.thumbnail_url = result.thumbnail_url || '';
						nextItem.thumbnail_large_url = result.thumbnail_large_url || '';
					}
					next.push( nextItem );
				} );

				return next;
			} );

			const removedKeys = results.filter( function ( result ) {
				return result.success && 'hidden' === result.operation && ! latest.current.filters.show_hidden;
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

			setLoading( true );
			setOperationNotice( null );
			bulkMediaItems( bulkAction, selectedItems, postId )
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
			const postId = getCurrentPostId();
			if ( [ 'attach', 'detach', 'remove' ].includes( action ) && 0 === postId ) {
				setOperationNotice( __( 'Save the post before changing media attachments.', 'wp-media-helper' ) );
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

		// What can be done with an item, depending on the mode and on where it is attached.
		const actionsFor = function ( item ) {
			if ( item.is_hidden ) {
				return canHide ? [ { action: 'show', label: __( 'Show again', 'wp-media-helper' ) } ] : [];
			}

			const actions = [];
			if ( ! item.is_attached_to_other_post ) {
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
				if ( canHide ) {
					actions.push( { action: 'hide', label: __( 'Hide', 'wp-media-helper' ) } );
				}
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

		// A click that ends a long press, or comes with Ctrl/Shift, is about the selection, not the menu.
		const handleTileClick = function ( event, item, toggleMenu ) {
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
				toggleMenu();
			}
		};

		const renderTile = function ( item, row, onClick ) {
			const key = itemKey( item );
			const selected = selectedIds.includes( key );
			const ratio = aspectOf( item );

			return wp.element.createElement( 'button', {
				key: key,
				type: 'button',
				onClick: onClick,
				onPointerDown: function () { pressStart( item ); },
				onPointerUp: cancelLongPress,
				onPointerLeave: cancelLongPress,
				onPointerCancel: cancelLongPress,
				'aria-label': item.name + ' – ' + stateLabel( item ),
				'aria-pressed': selectionMode ? selected : undefined,
				style: {
					position: 'relative', flex: '0 0 auto', width: Math.floor( ratio * row.height ) + 'px', height: row.height + 'px', padding: 0, margin: 0, border: 0,
					background: '#f0f0f1', cursor: 'pointer', overflow: 'hidden', borderRadius: '2px', opacity: item.is_hidden ? 0.55 : 1,
					outline: selected ? '3px solid #2271b1' : 'none', outlineOffset: '-3px', userSelect: 'none', WebkitTouchCallout: 'none', touchAction: 'manipulation'
				}
			},
				wp.element.createElement( TileContent, { item: item, perRow: density } ),
				wp.element.createElement( AttachmentBadge, { item: item } ),
				item.is_imported
					? wp.element.createElement( CornerBadge, { icon: 'admin-media', label: __( 'In the WordPress media library', 'wp-media-helper' ), position: { left: '3px' }, colour: '#2271b1' } )
					: null,
				item.is_hidden
					? wp.element.createElement( CornerBadge, { icon: 'hidden', label: __( 'Hidden', 'wp-media-helper' ), position: { right: '3px' }, colour: '#8a2424' } )
					: null,
				selectionMode
					? wp.element.createElement( 'span', {
						'aria-hidden': true,
						style: { position: 'absolute', top: '3px', right: '3px', width: '20px', height: '20px', borderRadius: '50%', border: '2px solid #fff', boxShadow: '0 0 2px rgba(0,0,0,0.6)', background: selected ? '#2271b1' : 'rgba(0,0,0,0.25)', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: '13px', lineHeight: 1 }
					}, selected ? '✓' : '' )
					: null
			);
		};

		// Without selection mode a tile opens the menu of the item: its information and its actions.
		const renderMenu = function ( item, onClose ) {
			const actions = actionsFor( item );

			return wp.element.createElement( 'div', { style: { minWidth: '220px', maxWidth: '280px', padding: '8px 0' } },
				wp.element.createElement( 'div', { style: { padding: '0 12px 8px', overflowWrap: 'anywhere' } },
					wp.element.createElement( 'strong', { style: { fontSize: '12px' }, title: item.path }, item.name ),
					wp.element.createElement( 'div', { style: { color: '#50575e', fontSize: '11px', marginTop: '2px' } },
						[ String( item.type || '' ).toUpperCase(), item.width > 0 ? item.width + '×' + item.height : '' ].filter( Boolean ).join( ' · ' ) ),
					item.is_hidden ? wp.element.createElement( 'div', { style: { color: '#8a2424', fontSize: '11px' } }, __( 'Hidden', 'wp-media-helper' ) ) : null,
					wp.element.createElement( 'div', { style: { fontSize: '11px', marginTop: '2px', color: item.is_attached_to_other_post ? '#b32d2e' : ( item.is_attached_to_current_post ? '#0a7d45' : '#50575e' ) } },
						item.is_attached_to_other_post && item.other_post_title
							? sprintf( __( 'Attached to another post: %s', 'wp-media-helper' ), item.other_post_title )
							: stateLabel( item ) ),
					item.is_attached_to_other_post && item.other_post_edit_url
						? wp.element.createElement( 'a', { href: item.other_post_edit_url, target: '_blank', rel: 'noreferrer', style: { fontSize: '11px' } }, __( 'Open that post', 'wp-media-helper' ) )
						: null,
					item.is_imported ? wp.element.createElement( 'div', { style: { fontSize: '11px', color: '#2271b1' } }, __( 'In the WordPress media library', 'wp-media-helper' ) ) : null
				),
				actions.map( function ( entry ) {
					return wp.element.createElement( MenuItem, {
						key: entry.action,
						disabled: loading,
						onClick: function () {
							onClose();
							handleItemAction( entry.action, item );
						}
					}, entry.label );
				} ),
				wp.element.createElement( MenuItem, { key: 'select', onClick: function () { onClose(); startSelection( item ); } }, __( 'Select', 'wp-media-helper' ) )
			);
		};

		const visibleItems = files.map( function ( file ) {
			return normalizeMediaItem( file );
		} );
		const rows = layoutRows( visibleItems, galleryWidth, density );
		const currentAttachmentScope = filters.attachment_scope || DEFAULT_ATTACHMENT_SCOPE;
		const currentMediaType = filters.media_type || DEFAULT_MEDIA_TYPE;
		const currentSourceFilter = filters.source || DEFAULT_SOURCE_FILTER;
		const checkedSourceIds = currentSourceFilter.includes( 'all' )
			? availableSources.map( function ( source ) { return source.id; } )
			: currentSourceFilter;
		const visibleBulkActions = BULK_ACTIONS.filter( function ( action ) {
			if ( 'hide' === action.value ) {
				return canHide;
			}
			if ( 'show' === action.value ) {
				return canHide && !! filters.show_hidden;
			}

			return 'advanced' === panelMode || [ 'attach', 'remove' ].includes( action.value );
		} );
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
								minSelected: 1,
								onToggle: toggleSource
							} )
							: null,
						wp.element.createElement( FilterCheckboxGroup, {
							label: __( 'Attachment', 'wp-media-helper' ),
							options: ATTACHMENT_SCOPE_STATES,
							selectedValues: currentAttachmentScope,
							disabled: loading,
							minSelected: 1,
							onToggle: toggleAttachmentScope
						} ),
						wp.element.createElement( FilterCheckboxGroup, {
							label: __( 'Media type', 'wp-media-helper' ),
							options: MEDIA_TYPE_OPTIONS,
							selectedValues: currentMediaType,
							disabled: loading,
							minSelected: 1,
							onToggle: toggleMediaType
						} ),
						canSeeHidden
							? wp.element.createElement( 'div', { style: { marginTop: '12px', paddingTop: '10px', borderTop: '1px solid #ddd' } },
								wp.element.createElement( CheckboxControl, {
									label: __( 'Show hidden files', 'wp-media-helper' ),
									checked: !! filters.show_hidden,
									disabled: loading,
									onChange: toggleShowHidden
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
						wp.element.createElement( 'div', { ref: gallery, role: 'list', 'aria-label': __( 'Media files', 'wp-media-helper' ), style: { display: 'flex', flexDirection: 'column', gap: GAP + 'px', width: '100%' } },
							rows.map( function ( row, rowIndex ) {
								return wp.element.createElement( 'div', { key: rowIndex, role: 'presentation', style: { display: 'flex', gap: GAP + 'px' } },
									row.items.map( function ( item ) {
										if ( selectionMode ) {
											return renderTile( item, row, function ( event ) { handleTileClick( event, item, function () {} ); } );
										}

										return wp.element.createElement( Dropdown, {
											key: itemKey( item ),
											popoverProps: { placement: 'bottom-start' },
											renderToggle: function ( { onToggle } ) {
												return renderTile( item, row, function ( event ) { handleTileClick( event, item, onToggle ); } );
											},
											renderContent: function ( { onClose } ) {
												return renderMenu( item, onClose );
											}
										} );
									} )
								);
							} )
						),
						0 === visibleItems.length && ! loading
							? wp.element.createElement( 'p', { style: { color: '#757575', fontSize: '12px' } }, __( 'No file for these filters.', 'wp-media-helper' ) )
							: null,
						// Reaching this marker loads the next lot.
						wp.element.createElement( 'div', { ref: sentinel, style: { minHeight: '1px', margin: '0.5rem 0', textAlign: 'center', color: '#757575', fontSize: '12px' } },
							hasMore && loading ? __( 'Loading…', 'wp-media-helper' ) : ''
						)
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
