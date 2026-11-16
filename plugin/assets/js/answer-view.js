/**
 * AI Answer — front end (plain script, no dependencies).
 *
 * For each answer box rendered by Answer_Box::render(), POSTs the visitor's
 * search to /wpaismse/v1/answer and fills the box. The initial hidden states
 * are set in PHP. The answer arrives as HTML already sanitised on the server
 * (Answer_Formatter + wp_kses), so it can be inserted as-is.
 */
( function () {
	'use strict';

	/**
	 * Load and show the answer for one box.
	 *
	 * @param {HTMLElement} box The .wpaismse-answer element.
	 */
	function load( box ) {
		if ( box.dataset.started ) {
			return;
		}
		box.dataset.started = '1';

		const part = ( name ) => box.querySelector( '.wpaismse-answer__' + name );
		const loading = part( 'loading' );
		const body = part( 'body' );
		const message = part( 'message' );
		const sources = part( 'sources' );

		const done = () => {
			loading.hidden = true;
			box.removeAttribute( 'aria-busy' );
		};

		const showMessage = ( text ) => {
			message.textContent = text;
			message.hidden = false;
			done();
		};

		fetch( box.dataset.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { q: box.dataset.query } ),
		} )
			.then( ( response ) =>
				response.json().then( ( data ) => ( { ok: response.ok, status: response.status, data } ) )
			)
			.then( ( { ok, status, data } ) => {
				if ( ! ok ) {
					showMessage( status === 429 ? box.dataset.rateLimited : box.dataset.error );
					return;
				}

				if ( ! data.answer ) {
					// Nothing matched: hide the box instead of showing an empty answer.
					box.hidden = true;
					done();
					return;
				}

				body.innerHTML = data.answer_html;
				body.hidden = false;

				const list = sources.querySelector( 'ol' );
				( data.sources || [] ).forEach( ( source ) => {
					const link = document.createElement( 'a' );
					link.textContent = source.title;
					if ( /^https?:\/\//i.test( source.url ) ) {
						link.href = source.url;
					}
					const item = document.createElement( 'li' );
					item.appendChild( link );
					list.appendChild( item );
				} );
				sources.hidden = list.children.length === 0;

				done();
			} )
			.catch( () => showMessage( box.dataset.error ) );
	}

	function init() {
		document.querySelectorAll( '.wpaismse-answer[data-endpoint]' ).forEach( load );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
