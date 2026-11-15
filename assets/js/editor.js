/**
 * AI Smart Search — block editor panel.
 *
 * Adds an "AI Smart Search" panel to the post sidebar with a switch that sets
 * the _wpaismse_exclude post meta. The value is saved with the post over REST.
 * Plain JS (no build step), using WordPress's bundled packages.
 */
( function ( wp ) {
	'use strict';

	const el = wp.element.createElement;
	const __ = wp.i18n.__;
	const { useSelect, useDispatch } = wp.data;
	const { ToggleControl } = wp.components;
	// wp.editor in WordPress 6.6+; wp.editPost before that.
	const PluginDocumentSettingPanel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	const META_KEY = '_wpaismse_exclude';

	if ( ! PluginDocumentSettingPanel ) {
		return;
	}

	function ExcludePanel() {
		const meta = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'meta' );
		}, [] );
		const { editPost } = useDispatch( 'core/editor' );

		// Meta is missing when the post type doesn't expose it over REST.
		if ( ! meta || ! Object.prototype.hasOwnProperty.call( meta, META_KEY ) ) {
			return null;
		}

		const excluded = !! meta[ META_KEY ];

		return el(
			PluginDocumentSettingPanel,
			{
				name: 'wpaismse-exclude',
				title: __( 'AI Smart Search', 'wpaismse' ),
			},
			el( ToggleControl, {
				label: __( 'Exclude from AI search', 'wpaismse' ),
				help: excluded
					? __( 'This post will not be indexed or returned in AI search results.', 'wpaismse' )
					: __( 'This post is indexed when published.', 'wpaismse' ),
				checked: excluded,
				onChange: function ( value ) {
					editPost( { meta: { [ META_KEY ]: value } } );
				},
				__nextHasNoMarginBottom: true,
			} )
		);
	}

	wp.plugins.registerPlugin( 'wpaismse-exclude', {
		render: ExcludePanel,
		icon: 'search',
	} );
}( window.wp ) );
