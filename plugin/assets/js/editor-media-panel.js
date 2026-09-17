( function ( wp ) {
	'use strict';

	const { __, sprintf, _n } = wp.i18n;
	const { registerPlugin } = wp.plugins;
	const { PluginSidebar } = wp.editPost;
	const { PanelBody, PanelRow, Button, TextControl, Notice } = wp.components;
	const { useState, useEffect } = wp.element;

	const endpoint = wpMediaHelperEditorPanel.ajaxUrl;
	const nonce = wpMediaHelperEditorPanel.nonce;
	const defaultDate = wpMediaHelperEditorPanel.date;
	const dateMetaKey = 'wp_media_helper_date';

	// Background poll interval; the manual Refresh button always forces an immediate check.
	const AUTO_REFRESH_INTERVAL_MS = 30000;

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

	const fetchState = function ( dateValue, forceRefresh ) {
		const formData = new window.FormData();
		formData.append( 'action', 'wp_media_helper_media_panel_state' );
		formData.append( 'nonce', nonce );
		formData.append( 'date', dateValue );
		formData.append( 'force_refresh', forceRefresh ? '1' : '0' );
		formData.append( 'source_id', wpMediaHelperEditorPanel.sourceId || '' );

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

	const MediaPanel = function () {
		const [ date, setDateState ] = useState( getStoredDate );
		const [ status, setStatus ] = useState( 'fresh' );
		const [ reason, setReason ] = useState( null );
		const [ files, setFiles ] = useState( [] );
		const [ loading, setLoading ] = useState( false );
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
					wp.element.createElement( 'ul', { style: { listStyle: 'disc', paddingLeft: '1.25rem', marginTop: 0 } },
						files.map( function ( file ) {
							return wp.element.createElement( 'li', { key: file }, file );
						} )
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
