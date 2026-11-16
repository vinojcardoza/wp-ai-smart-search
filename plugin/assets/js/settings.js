/**
 * AI Smart Search — admin pages (Re-Index progress, Chat prompt preview).
 *
 * While a re-index is running, repeatedly asks the server to process the next
 * batch (admin-ajax.php?action=wpaismse_reindex_step) and updates the status
 * block and index stats with the response. WP-Cron keeps the job going when
 * the page is closed; the server lock stops the two from overlapping.
 */
( function () {
	'use strict';

	const card = document.querySelector( '[data-wpaismse-reindex]' );

	if ( ! card || card.dataset.status !== 'running' ) {
		return;
	}

	const statusEl = card.querySelector( '[data-wpaismse-reindex-status]' );
	const button   = card.querySelector( '[data-wpaismse-reindex-button]' );
	const RETRY_MS = 3000;
	const NEXT_MS  = 300;

	function updateStats( stats ) {
		Object.keys( stats ).forEach(
			function ( key ) {
				const el = document.querySelector( '[data-wpaismse-stat="' + key + '"]' );
				if ( el ) {
						el.textContent = stats[ key ];
				}
			}
		);
	}

	function finish() {
		if ( button ) {
			button.disabled    = false;
			button.textContent = button.dataset.idleLabel || button.textContent;
		}
		card.dataset.status = 'done';
	}

	function step() {
		const body = new URLSearchParams(
			{
				action: 'wpaismse_reindex_step',
				nonce: card.dataset.nonce,
			}
		);

		fetch(
			card.dataset.ajaxUrl,
			{
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			}
		)
			.then(
				function ( response ) {
					if ( ! response.ok ) {
							throw new Error( 'HTTP ' + response.status );
					}
					return response.json();
				}
			)
			.then(
				function ( result ) {
					if ( ! result || ! result.success ) {
							throw new Error( 'Request failed' );
					}

					statusEl.innerHTML = result.data.html;
					updateStats( result.data.stats );

					if ( result.data.status === 'running' ) {
						window.setTimeout( step, NEXT_MS );
					} else {
						finish();
					}
				}
			)
			.catch(
				function () {
					// Network hiccup or another request holding the lock: try again shortly.
					window.setTimeout( step, RETRY_MS );
				}
			);
	}

	step();
}() );

/**
 * Chat page: live prompt preview. Fills {query} and {results} in the prompt
 * box with the sample values from the preview's data attributes.
 */
( function () {
	'use strict';

	const textarea = document.querySelector( '[data-wpaismse-prompt]' );
	const preview  = document.querySelector( '[data-wpaismse-prompt-preview]' );

	if ( ! textarea || ! preview ) {
		return;
	}

	function render() {
		// An empty box means the default prompt is used.
		const template = textarea.value.trim() || textarea.dataset.default;

		preview.textContent = template
			.split( '{query}' ).join( preview.dataset.query )
			.split( '{results}' ).join( preview.dataset.results );
	}

	textarea.addEventListener( 'input', render );

	// "Discard" resets the form after the event fires, so re-render afterwards.
	if ( textarea.form ) {
		textarea.form.addEventListener(
			'reset',
			function () {
				window.setTimeout( render, 0 );
			}
		);
	}
}() );
