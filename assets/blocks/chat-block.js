/**
 * The StoreCrew chat block, registered against the editor's own globals.
 *
 * Hand-written rather than built. It needs three calls and a placeholder, and a
 * build pipeline for that would add `@wordpress/*` packages to a plugin that
 * deliberately has none — the admin application bundles its own React precisely
 * so it never inherits core's.
 *
 * `wp.element.createElement` rather than JSX, for the same reason: no transform
 * means no build.
 */
( function ( blocks, element, blockEditor, i18n ) {
	'use strict';

	if ( ! blocks || ! element || ! blockEditor ) {
		return;
	}

	var el = element.createElement;
	var __ = i18n && i18n.__ ? i18n.__ : function ( s ) { return s; };

	blocks.registerBlockType( 'storecrew/chat', {
		edit: function () {
			var props = blockEditor.useBlockProps( {
				style: {
					border: '1px dashed currentColor',
					borderRadius: '12px',
					padding: '2rem',
					textAlign: 'center',
					opacity: 0.7,
				},
			} );

			// A static preview. Booting the real widget in the editor would open
			// a conversation every time somebody opened the page for editing,
			// and bill the merchant for the answers.
			return el(
				'div',
				props,
				el( 'strong', null, __( 'StoreCrew chat', 'storecrew' ) ),
				el( 'br' ),
				el(
					'span',
					null,
					__( 'The chat panel appears here on the published page.', 'storecrew' )
				)
			);
		},

		// Server-rendered: `render_callback` prints the mount point, so there is
		// nothing to save into post content.
		save: function () {
			return null;
		},
	} );
} )( window.wp && window.wp.blocks, window.wp && window.wp.element, window.wp && window.wp.blockEditor, window.wp && window.wp.i18n );
