( function ( wp ) {
	'use strict';

	const { __, sprintf, _n } = wp.i18n;
	const { registerPlugin } = wp.plugins;
	const { PluginSidebar } = wp.editPost;
	const { PanelBody, PanelRow, Button, TextControl, Notice, Dropdown, MenuItem, CheckboxControl } = wp.components;
	const chevronDown = wp.icons && wp.icons.chevronDown ? wp.icons.chevronDown : null;
	const bulkActionIcon = chevronDown || wp.element.createElement( 'span', { 'aria-hidden': true, style: { fontSize: '12px', lineHeight: 1 } }, '\u25BE' );
	const { useState, useEffect } = wp.element;

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

	const MediaPanel = function () {
		const [ filters, setFiltersState ] = useState( function () { return { date: getStoredDate() }; } );
		const [ availableSources, setAvailableSources ] = useState( [] );
		const [ status, setStatus ] = useState( 'fresh' );
		const [ reason, setReason ] = useState( null );
		const [ files, setFiles ] = useState( [] );
		const [ page, setPage ] = useState( 1 );
		const [ pagination, setPagination ] = useState( { page: 1, per_page: 0, total: 0, total_pages: 1 } );
		const [ loading, setLoading ] = useState( false );
		const [ selectedIds, setSelectedIds ] = useState( [] );
		const [ bulkAction, setBulkAction ] = useState( 'none' );
		const [ panelMode, setPanelMode ] = useState( defaultPanelMode );
		const [ operationNotice, setOperationNotice ] = useState( null );
		const [ lastRefreshedAt, setLastRefreshedAt ] = useState( null );
		const [ , setTick ] = useState( 0 );

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
			setPage( 1 );
			setFiltersState( function ( currentFilters ) {
				return Object.assign( {}, currentFilters, { date: value } );
			} );

			const metaUpdate = {};
			metaUpdate[ dateMetaKey ] = value;
			wp.data.dispatch( 'core/editor' ).editPost( { meta: metaUpdate } );
		};

		const applyPayload = function ( payload, requestedFilters ) {
			setStatus( payload.data.status || 'fresh' );
			setReason( payload.data.reason || null );
			setFiles( payload.data.files || [] );
			setAvailableSources( payload.data.available_sources || [] );
			if ( payload.data.pagination ) {
				setPagination( payload.data.pagination );
				// The server clamps the page when fewer results remain.
				setPage( payload.data.pagination.page );
			}
			if ( payload.data.filters && Array.isArray( payload.data.filters.source ) ) {
				setFiltersState( function ( currentFilters ) {
					const nextSource = payload.data.filters.source;
					const currentSource = currentFilters.source || [];
					const isSameSource = nextSource.length === currentSource.length
						&& nextSource.every( function ( value, index ) { return value === currentSource[ index ]; } );

					return isSameSource ? currentFilters : Object.assign( {}, currentFilters, { source: nextSource } );
				} );
			}
			if ( payload.data.filters && Array.isArray( payload.data.filters.attachment_scope ) ) {
				setFiltersState( function ( currentFilters ) {
					const nextScope = payload.data.filters.attachment_scope;
					const currentScope = currentFilters.attachment_scope || [];
					// Bail out on an identical scope so the effect below does not re-fire on every response.
					const isSameScope = nextScope.length === currentScope.length
						&& nextScope.every( function ( value, index ) { return value === currentScope[ index ]; } );

					return isSameScope ? currentFilters : Object.assign( {}, currentFilters, { attachment_scope: nextScope } );
				} );
			}
			if ( payload.data.filters && Array.isArray( payload.data.filters.media_type ) ) {
				setFiltersState( function ( currentFilters ) {
					const nextTypes = payload.data.filters.media_type;
					const currentTypes = currentFilters.media_type || [];
					const isSameTypes = nextTypes.length === currentTypes.length
						&& nextTypes.every( function ( value, index ) { return value === currentTypes[ index ]; } );

					return isSameTypes ? currentFilters : Object.assign( {}, currentFilters, { media_type: nextTypes } );
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
			const nextIds = new window.Set( ( payload.data.files || [] ).map( function ( file ) {
				return itemKey( normalizeMediaItem( file ) );
			} ) );
			setSelectedIds( function ( currentIds ) {
				return currentIds.filter( function ( id ) {
					return nextIds.has( id );
				} );
			} );
			setLastRefreshedAt( Date.now() );
		};

		// Re-render every second so the "refreshed Xs ago" label stays current.
		useEffect( function () {
			const timer = window.setInterval( function () {
				setTick( function ( value ) {
					return value + 1;
				} );
			}, 1000 );

			return function () {
				window.clearInterval( timer );
			};
		}, [] );

		const runFetch = function ( forceRefresh, overrideFilters ) {
			const requestedFilters = overrideFilters || filters;
			setLoading( true );
			return fetchState( requestedFilters, forceRefresh, page )
				.then( function ( payload ) {
					if ( payload && payload.success ) {
						applyPayload( payload, requestedFilters );
					} else if ( ! forceRefresh ) {
						setStatus( 'fresh' );
						setReason( null );
						setFiles( [] );
					}
				} )
				.finally( function () {
					setLoading( false );
				} );
		};

		useEffect( function () {
			runFetch( false );

			// Periodically re-check freshness in the background, like a live status feed.
			const poller = window.setInterval( function () {
				runFetch( false );
			}, AUTO_REFRESH_INTERVAL_MS );

			// Also re-check whenever the browser tab regains visibility.
			const handleVisibilityChange = function () {
				if ( 'visible' === document.visibilityState ) {
					runFetch( false );
				}
			};
			document.addEventListener( 'visibilitychange', handleVisibilityChange );

			return function () {
				window.clearInterval( poller );
				document.removeEventListener( 'visibilitychange', handleVisibilityChange );
			};
			// eslint-disable-next-line react-hooks/exhaustive-deps
		}, [ filters.date, filters.filename || '', ( filters.attachment_scope || [] ).join( ',' ), ( filters.source || [] ).join( ',' ), ( filters.media_type || [] ).join( ',' ), !! filters.show_hidden, page ] );

		const handleRefresh = function () {
			runFetch( true );
		};

		const handlePanelMode = function ( mode ) {
			setPanelMode( mode );
			setBulkAction( 'none' );
			savePanelMode( mode );
		};

		const toggleAttachmentScope = function ( state ) {
			const current = filters.attachment_scope || DEFAULT_ATTACHMENT_SCOPE;
			const next = current.includes( state )
				? current.filter( function ( value ) { return value !== state; } )
				: current.concat( [ state ] );

			if ( 0 === next.length ) {
				return;
			}

			setPage( 1 );
			const nextFilters = Object.assign( {}, filters, { attachment_scope: next } );
			setFiltersState( nextFilters );
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

			setPage( 1 );
			setFiltersState( function ( currentFilters ) {
				return Object.assign( {}, currentFilters, { media_type: next } );
			} );
			saveFilter( 'media_type', next, getCurrentPostId() );
		};

		// Not stored: hidden files are listed only while the box is checked.
		const toggleShowHidden = function ( checked ) {
			setPage( 1 );
			setSelectedIds( [] );
			setFiltersState( function ( currentFilters ) {
				return Object.assign( {}, currentFilters, { show_hidden: !! checked } );
			} );
		};

		const setFilename = function ( value ) {
			const next = value.slice( 0, 255 );
			setPage( 1 );
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

			setPage( 1 );
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

		const toggleAll = function () {
			const visibleIds = files.map( function ( file ) {
				return itemKey( normalizeMediaItem( file ) );
			} );
			const allVisibleSelected = visibleIds.length > 0 && visibleIds.every( function ( id ) {
				return selectedIds.includes( id );
			} );

			setSelectedIds( function ( currentIds ) {
				if ( allVisibleSelected ) {
					return currentIds.filter( function ( id ) { return ! visibleIds.includes( id ); } );
				}

				return Array.from( new window.Set( currentIds.concat( visibleIds ) ) );
			} );
		};

		const applyBulkResults = function ( payload ) {
			const results = payload && payload.data && payload.data.results ? payload.data.results : [];
			const resultByKey = {};
			results.forEach( function ( result ) {
				resultByKey[ result.id || result.path ] = result;
			} );

			setFiles( function ( currentFiles ) {
				return currentFiles.map( function ( currentFile ) {
					const item = normalizeMediaItem( currentFile );
					const result = resultByKey[ itemKey( item ) ] || resultByKey[ item.path ];
					if ( ! result || ! result.success ) {
						return currentFile;
					}

					const nextItem = Object.assign( {}, item, { is_imported: !! result.is_imported } );
					if ( Object.prototype.hasOwnProperty.call( result, 'is_attached_to_current_post' ) ) {
						nextItem.is_attached_to_current_post = !! result.is_attached_to_current_post;
					}

					return nextItem;
				} );
			} );

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
			const performedAction = bulkAction;
			bulkMediaItems( bulkAction, selectedItems, postId )
				.then( function ( payload ) {
					if ( payload && payload.success ) {
						applyBulkResults( payload );
						if ( [ 'hide', 'show' ].includes( performedAction ) ) {
							setSelectedIds( [] );
							runFetch( false );
						}
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
						if ( [ 'hide', 'show' ].includes( action ) ) {
							runFetch( false );
						}
					} else {
						setOperationNotice( payload && payload.data && payload.data.message ? payload.data.message : __( 'The action failed.', 'wp-media-helper' ) );
					}
				} )
				.finally( function () {
					setLoading( false );
				} );
		};

		const visibleItems = files.map( function ( file ) {
			return normalizeMediaItem( file );
		} );
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
		const visibleIds = visibleItems.map( itemKey );
		const selectedVisibleCount = visibleIds.filter( function ( id ) {
			return selectedIds.includes( id );
		} ).length;
		const allVisibleSelected = visibleItems.length > 0 && selectedVisibleCount === visibleItems.length;
		const someVisibleSelected = selectedVisibleCount > 0 && ! allVisibleSelected;

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
					wp.element.createElement( 'span', { style: { color: '#757575', fontSize: '12px' } }, formatElapsed( lastRefreshedAt ) )
				),
				wp.element.createElement(
					PanelRow,
					null,
					wp.element.createElement( 'div', { style: { width: '100%' } },
						wp.element.createElement( 'div', { style: { display: 'flex', gap: '0.5rem', alignItems: 'flex-end', marginBottom: '0.75rem', flexWrap: 'wrap' } },
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
							} )
						),
						operationNotice
							? wp.element.createElement( Notice, { status: 'warning', isDismissible: true, onRemove: function () { setOperationNotice( null ); } }, operationNotice )
							: null,
						wp.element.createElement( 'div', null,
							wp.element.createElement( 'table', { style: { width: '100%', tableLayout: 'fixed', borderCollapse: 'collapse', fontSize: '12px' } },
								wp.element.createElement( 'thead', null,
									wp.element.createElement( 'tr', null,
										wp.element.createElement( 'th', { scope: 'col', style: { padding: '0.35rem', textAlign: 'left', width: '1.75rem' } },
											wp.element.createElement( 'input', {
												type: 'checkbox',
												checked: allVisibleSelected,
												disabled: loading || 0 === visibleItems.length,
												 onChange: toggleAll,
												ref: function ( element ) {
													if ( element ) {
														element.indeterminate = someVisibleSelected;
													}
												},
												'aria-label': __( 'Select all media', 'wp-media-helper' )
											} )
										),
										wp.element.createElement( 'th', { scope: 'col', style: { padding: '0.35rem', textAlign: 'left', width: '40%' } }, __( 'Media', 'wp-media-helper' ) ),
										wp.element.createElement( 'th', { scope: 'col', style: { padding: '0.35rem', textAlign: 'left', width: '35%' } }, __( 'Status', 'wp-media-helper' ) ),
										wp.element.createElement( 'th', { scope: 'col', style: { padding: '0.35rem', textAlign: 'right', width: '4.75rem' } }, __( 'Actions', 'wp-media-helper' ) )
									)
								),
								wp.element.createElement( 'tbody', null,
									visibleItems.map( function ( item ) {
										const key = itemKey( item );
										const primaryAction = item.is_attached_to_other_post
											? null
											: ( 'simple' === panelMode
												? ( item.is_attached_to_current_post ? 'remove' : 'attach' )
												: ( item.is_imported ? 'remove' : 'import' ) );
										const primaryLabel = {
											import: __( 'Import', 'wp-media-helper' ),
											remove: __( 'Remove', 'wp-media-helper' ),
											attach: __( 'Attach', 'wp-media-helper' ),
										}[ primaryAction ];
										return wp.element.createElement( 'tr', { key: key, style: { borderTop: '1px solid #dcdcde' } },
											wp.element.createElement( 'td', { style: { padding: '0.4rem' } },
												wp.element.createElement( 'input', {
													type: 'checkbox',
													checked: selectedIds.includes( key ),
													disabled: loading,
													onChange: function () { toggleSelected( item ); },
													'aria-label': sprintf( __( 'Select %s', 'wp-media-helper' ), item.name )
												} )
											),
											wp.element.createElement( 'td', { style: { padding: '0.4rem', overflowWrap: 'anywhere', verticalAlign: 'top' } },
												wp.element.createElement( 'div', { style: { display: 'flex', gap: '0.5rem', alignItems: 'flex-start' } },
													item.thumbnail_url
														? wp.element.createElement( 'img', {
															src: item.thumbnail_url,
															alt: '',
															loading: 'lazy',
															width: 48,
															height: 48,
															style: { width: '48px', height: '48px', objectFit: 'cover', borderRadius: '2px', flex: '0 0 auto', background: '#f0f0f1' },
															// A preview that cannot be made leaves the name alone.
															onError: function ( event ) { event.target.style.display = 'none'; }
														} )
														: null,
													wp.element.createElement( 'div', { style: { minWidth: 0 } },
														// The full path of the file, to tell apart files that share a name.
														wp.element.createElement( 'strong', { style: { fontSize: '12px' }, title: item.path }, item.name ),
														wp.element.createElement( 'div', { style: { marginTop: '0.15rem', textTransform: 'uppercase', color: '#50575e', fontSize: '10px' } }, item.type )
													)
												)
											),
											wp.element.createElement( 'td', { style: { padding: '0.4rem', verticalAlign: 'top', overflowWrap: 'anywhere' } },
												item.is_hidden
													? wp.element.createElement( 'div', { style: { color: '#8a2424', fontWeight: 600, marginBottom: '0.25rem' } }, __( 'Hidden', 'wp-media-helper' ) )
													: null,
												item.is_attached_to_other_post
													? wp.element.createElement( 'div', { style: { color: '#b32d2e' } },
														item.other_post_edit_url
															? wp.element.createElement( 'a', { href: item.other_post_edit_url, target: '_blank', rel: 'noreferrer' },
																item.other_post_title
																	? sprintf( __( 'Attached to another post: %s', 'wp-media-helper' ), item.other_post_title )
																	: __( 'Attached to another post', 'wp-media-helper' )
															)
															: ( item.other_post_title
																? sprintf( __( 'Attached to another post: %s', 'wp-media-helper' ), item.other_post_title )
																: __( 'Attached to another post', 'wp-media-helper' ) )
													)
													: ( 'simple' === panelMode
														? wp.element.createElement( 'div', { style: { color: item.is_attached_to_current_post ? '#0a7d45' : '#50575e' } },
															item.is_attached_to_current_post ? __( 'Attached to current post', 'wp-media-helper' ) : __( 'Not attached to current post', 'wp-media-helper' )
														)
														: [
															wp.element.createElement( 'div', { key: 'library', style: { color: item.is_imported ? '#0a7d45' : '#50575e' } },
																item.is_imported ? __( 'In WP media library', 'wp-media-helper' ) : __( 'Not in WP media library', 'wp-media-helper' )
															),
															wp.element.createElement( 'div', { key: 'post', style: { marginTop: '0.25rem', color: item.is_attached_to_current_post ? '#0a7d45' : '#50575e' } },
																item.is_attached_to_current_post ? __( 'Attached to current post', 'wp-media-helper' ) : __( 'Not attached to current post', 'wp-media-helper' )
															),
														] )
											),
											wp.element.createElement( 'td', { style: { padding: '0.4rem', textAlign: 'right' } },
												item.is_hidden
													? ( canHide
														? wp.element.createElement( Button, {
															isLink: true,
															disabled: loading,
															onClick: function () { handleItemAction( 'show', item ); },
															text: __( 'Show again', 'wp-media-helper' )
														} )
														: null )
													: ( item.is_attached_to_other_post
													? null
													: wp.element.createElement( 'div', { style: { display: 'flex', flexDirection: 'column', alignItems: 'flex-end', gap: '0.25rem' } },
														wp.element.createElement( Button, {
															isLink: true,
															disabled: loading,
															onClick: function () { handleItemAction( primaryAction, item ); },
															text: primaryLabel
														} ),
														'advanced' === panelMode && item.is_attached_to_current_post
															? wp.element.createElement( Button, {
																isLink: true,
																disabled: loading,
																onClick: function () { handleItemAction( 'detach', item ); },
																text: __( 'Detach', 'wp-media-helper' )
															} )
															: 'advanced' === panelMode
																? wp.element.createElement( Button, {
																isLink: true,
																disabled: loading,
																onClick: function () { handleItemAction( 'attach', item ); },
																text: __( 'Attach', 'wp-media-helper' )
															} )
																: null,
														canHide
															? wp.element.createElement( Button, {
																isLink: true,
																disabled: loading,
																onClick: function () { handleItemAction( 'hide', item ); },
																text: __( 'Hide', 'wp-media-helper' )
															} )
															: null
													) )
											)
										);
									} )
								)
							),
							pagination.total_pages > 1
								? wp.element.createElement( 'div', { role: 'navigation', 'aria-label': __( 'Pagination', 'wp-media-helper' ), style: { display: 'flex', gap: '0.5rem', alignItems: 'center', justifyContent: 'space-between', marginTop: '0.75rem', fontSize: '12px' } },
									wp.element.createElement( Button, {
										isSecondary: true,
										disabled: loading || pagination.page <= 1,
										onClick: function () { setPage( pagination.page - 1 ); },
										text: __( 'Previous', 'wp-media-helper' )
									} ),
									wp.element.createElement( 'span', null,
										sprintf(
											/* translators: 1: current page number, 2: total number of pages, 3: total number of items. */
											__( 'Page %1$d of %2$d (%3$d items)', 'wp-media-helper' ),
											pagination.page,
											pagination.total_pages,
											pagination.total
										)
									),
									wp.element.createElement( Button, {
										isSecondary: true,
										disabled: loading || pagination.page >= pagination.total_pages,
										onClick: function () { setPage( pagination.page + 1 ); },
										text: __( 'Next', 'wp-media-helper' )
									} )
								)
								: null
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
