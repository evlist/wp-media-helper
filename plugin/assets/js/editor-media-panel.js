( function ( wp ) {
	'use strict';

	const { __, sprintf, _n } = wp.i18n;
	const { registerPlugin } = wp.plugins;
	const { PluginSidebar } = wp.editPost;
	const { PanelBody, PanelRow, Button, TextControl, Notice, Dropdown, MenuItem } = wp.components;
	const chevronDown = wp.icons && wp.icons.chevronDown ? wp.icons.chevronDown : null;
	const bulkActionIcon = chevronDown || wp.element.createElement( 'span', { 'aria-hidden': true, style: { fontSize: '12px', lineHeight: 1 } }, '\u25BE' );
	const { useState, useEffect } = wp.element;

	const endpoint = wpMediaHelperEditorPanel.ajaxUrl;
	const nonce = wpMediaHelperEditorPanel.nonce;
	const defaultDate = wpMediaHelperEditorPanel.date;
	const dateMetaKey = 'wp_media_helper_date';

	// Background poll interval; the manual Refresh button always forces an immediate check.
	const AUTO_REFRESH_INTERVAL_MS = 30000;
	const BULK_ACTIONS = [
		{ value: 'import', label: __( 'Import', 'wp-media-helper' ) },
		{ value: 'remove', label: __( 'Remove', 'wp-media-helper' ) },
		{ value: 'attach', label: __( 'Attach to post', 'wp-media-helper' ) },
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

	const getCurrentPostId = function () {
		return Number( wp.data.select( 'core/editor' ).getCurrentPostId() || 0 );
	};

	const fetchState = function ( dateValue, forceRefresh ) {
		const formData = new window.FormData();
		formData.append( 'action', 'wp_media_helper_media_panel_state' );
		formData.append( 'nonce', nonce );
		formData.append( 'date', dateValue );
		formData.append( 'force_refresh', forceRefresh ? '1' : '0' );
		formData.append( 'source_id', wpMediaHelperEditorPanel.sourceId || '' );
		formData.append( 'post_id', String( getCurrentPostId() ) );

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
			is_imported: !! ( item && item.is_imported ),
			is_attached_to_current_post: !! ( item && item.is_attached_to_current_post ),
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
			return { id: item.id, path: item.path };
		} ) ) );

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

	const MediaPanel = function () {
		const [ date, setDateState ] = useState( getStoredDate );
		const [ status, setStatus ] = useState( 'fresh' );
		const [ reason, setReason ] = useState( null );
		const [ files, setFiles ] = useState( [] );
		const [ loading, setLoading ] = useState( false );
		const [ selectedIds, setSelectedIds ] = useState( [] );
		const [ bulkAction, setBulkAction ] = useState( 'none' );
		const [ operationNotice, setOperationNotice ] = useState( null );
		const [ lastRefreshedAt, setLastRefreshedAt ] = useState( null );
		const [ , setTick ] = useState( 0 );

		const setDate = function ( value ) {
			setDateState( value );

			const metaUpdate = {};
			metaUpdate[ dateMetaKey ] = value;
			wp.data.dispatch( 'core/editor' ).editPost( { meta: metaUpdate } );
		};

		const applyPayload = function ( payload ) {
			setStatus( payload.data.status || 'fresh' );
			setReason( payload.data.reason || null );
			setFiles( payload.data.files || [] );
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

		const runFetch = function ( forceRefresh ) {
			setLoading( true );
			return fetchState( date, forceRefresh )
				.then( function ( payload ) {
					if ( payload && payload.success ) {
						applyPayload( payload );
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
		}, [ date ] );

		const handleRefresh = function () {
			runFetch( true );
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

			const selectedItems = files.map( function ( file ) {
				return normalizeMediaItem( file );
			} ).filter( function ( item ) {
				return selectedIds.includes( itemKey( item ) );
			} );
			const postId = getCurrentPostId();
			if ( 'attach' === bulkAction && 0 === postId ) {
				setOperationNotice( __( 'Save the post before attaching media.', 'wp-media-helper' ) );
				return;
			}

			setLoading( true );
			setOperationNotice( null );
			bulkMediaItems( bulkAction, selectedItems, postId )
				.then( function ( payload ) {
					if ( payload && payload.success ) {
						applyBulkResults( payload );
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

			setLoading( true );
			setOperationNotice( null );
			bulkMediaItems( action, [ item ], getCurrentPostId() )
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

		const visibleItems = files.map( function ( file ) {
			return normalizeMediaItem( file );
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
					wp.element.createElement( TextControl, {
						label: __( 'Date', 'wp-media-helper' ),
						value: date,
						onChange: setDate,
						type: 'date'
					} )
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
							reason ? __( 'Refresh required: ', 'wp-media-helper' ) + reason : __( 'Refresh required.', 'wp-media-helper' ) )
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
						wp.element.createElement( 'div', { style: { display: 'flex', gap: '0.5rem', alignItems: 'flex-end', marginBottom: '0.75rem' } },
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
									return BULK_ACTIONS.map( function ( action ) {
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
						wp.element.createElement( 'div', { style: { overflowX: 'auto' } },
							wp.element.createElement( 'table', { style: { width: '100%', borderCollapse: 'collapse', fontSize: '12px' } },
								wp.element.createElement( 'thead', null,
									wp.element.createElement( 'tr', null,
										wp.element.createElement( 'th', { scope: 'col', style: { padding: '0.35rem', textAlign: 'left', width: '2rem' } },
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
										wp.element.createElement( 'th', { scope: 'col', style: { padding: '0.35rem', textAlign: 'left' } }, __( 'Name', 'wp-media-helper' ) ),
										wp.element.createElement( 'th', { scope: 'col', style: { padding: '0.35rem', textAlign: 'left' } }, __( 'Type', 'wp-media-helper' ) ),
										wp.element.createElement( 'th', { scope: 'col', style: { padding: '0.35rem', textAlign: 'left' } }, __( 'Status', 'wp-media-helper' ) ),
										wp.element.createElement( 'th', { scope: 'col', style: { padding: '0.35rem', textAlign: 'left' } }, __( 'Current post', 'wp-media-helper' ) ),
										wp.element.createElement( 'th', { scope: 'col', style: { padding: '0.35rem', textAlign: 'right' } }, __( 'Actions', 'wp-media-helper' ) )
									)
								),
								wp.element.createElement( 'tbody', null,
									visibleItems.map( function ( item ) {
										const key = itemKey( item );
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
											wp.element.createElement( 'td', { style: { padding: '0.4rem', overflowWrap: 'anywhere' } }, item.name ),
											wp.element.createElement( 'td', { style: { padding: '0.4rem', textTransform: 'uppercase', color: '#50575e' } }, item.type ),
											wp.element.createElement( 'td', { style: { padding: '0.4rem', color: item.is_imported ? '#0a7d45' : '#50575e' } },
												item.is_imported ? __( 'In WP media library', 'wp-media-helper' ) : __( 'Not in WP media library', 'wp-media-helper' )
											),
											wp.element.createElement( 'td', { style: { padding: '0.4rem', color: item.is_attached_to_current_post ? '#0a7d45' : '#50575e' } },
												item.is_attached_to_current_post ? __( 'Attached to current post', 'wp-media-helper' ) : __( 'Not attached to current post', 'wp-media-helper' )
											),
											wp.element.createElement( 'td', { style: { padding: '0.4rem', textAlign: 'right' } },
												wp.element.createElement( 'div', { style: { display: 'flex', justifyContent: 'flex-end', gap: '0.5rem' } },
													wp.element.createElement( Button, {
														isLink: true,
														disabled: loading,
														onClick: function () { handleItemAction( item.is_imported ? 'remove' : 'import', item ); },
														text: item.is_imported ? __( 'Remove', 'wp-media-helper' ) : __( 'Import', 'wp-media-helper' )
													} ),
													! item.is_attached_to_current_post
														? wp.element.createElement( Button, {
															isLink: true,
															disabled: loading,
															onClick: function () { handleItemAction( 'attach', item ); },
															text: __( 'Attach', 'wp-media-helper' )
														} )
														: null
												)
											)
										);
									} )
								)
							)
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
