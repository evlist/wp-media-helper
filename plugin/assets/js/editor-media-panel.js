( function ( wp ) {
	'use strict';

	const { __ } = wp.i18n;
	const { registerPlugin } = wp.plugins;
	const { PluginSidebar } = wp.editPost;
	const { PanelBody, PanelRow, Button, TextControl, Notice } = wp.components;
	const { useState, useEffect } = wp.element;

	const endpoint = wpMediaHelperEditorPanel.ajaxUrl;
	const nonce = wpMediaHelperEditorPanel.nonce;
	const defaultDate = wpMediaHelperEditorPanel.date;

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

	const MediaPanel = function () {
		const [ date, setDate ] = useState( defaultDate );
		const [ status, setStatus ] = useState( 'fresh' );
		const [ reason, setReason ] = useState( null );
		const [ files, setFiles ] = useState( [] );
		const [ loading, setLoading ] = useState( false );

		useEffect( function () {
			setLoading( true );
			fetchState( date, false )
				.then( function ( payload ) {
					if ( payload && payload.success ) {
						setStatus( payload.data.status || 'fresh' );
						setReason( payload.data.reason || null );
						setFiles( payload.data.files || [] );
					} else {
						setStatus( 'fresh' );
						setReason( null );
						setFiles( [] );
					}
				})
				.finally( function () {
					setLoading( false );
				});
		}, [ date ] );

		const handleRefresh = function () {
			setLoading( true );
			fetchState( date, true )
				.then( function ( payload ) {
					if ( payload && payload.success ) {
						setStatus( payload.data.status || 'fresh' );
						setReason( payload.data.reason || null );
						setFiles( payload.data.files || [] );
					}
				})
				.finally( function () {
					setLoading( false );
				});
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
						: wp.element.createElement( Notice, { status: 'success', isDismissible: false }, __( 'Media is up to date.', 'wp-media-helper' ) )
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
