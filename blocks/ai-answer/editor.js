/**
 * AI Answer block — editor placeholder.
 *
 * The block is rendered on the server (Hooks\Answer_Block); in the editor it
 * shows a description, since there is no visitor search to answer.
 * Plain JS (no build step), using WordPress's bundled packages.
 */
( function ( wp ) {
	'use strict';

	const el = wp.element.createElement;
	const __ = wp.i18n.__;

	wp.blocks.registerBlockType( 'wpaismse/ai-answer', {
		edit: function () {
			const props = wp.blockEditor.useBlockProps( { className: 'wpaismse-answer wpaismse-answer--placeholder' } );

			return el(
				'div',
				props,
				el( 'p', { className: 'wpaismse-answer__label' }, __( 'AI answer', 'wpaismse' ) ),
				el( 'p', null, __( 'On search results pages, an AI-written answer to the visitor\'s search appears here, with links to its sources.', 'wpaismse' ) )
			);
		},
		save: function () {
			return null;
		},
	} );
}( window.wp ) );
