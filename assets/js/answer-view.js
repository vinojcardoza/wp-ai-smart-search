/**
 * AI Answer — front end (Interactivity API store).
 *
 * On load, POSTs the visitor's search to /wpaismse/v1/answer and fills the
 * block's context. The answer arrives as HTML already sanitised on the
 * server (Answer_Formatter + wp_kses), so it can be inserted as-is.
 */
import { store, getContext, getElement } from '@wordpress/interactivity';

store( 'wpaismse/answer', {
	callbacks: {
		*load() {
			const context = getContext();

			if ( context.started ) {
				return;
			}
			context.started = true;

			try {
				const response = yield fetch( context.endpoint, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { q: context.query } ),
				} );
				const data = yield response.json();

				if ( ! response.ok ) {
					context.message = 429 === response.status ? context.messages.rateLimited : context.messages.error;
				} else if ( ! data.answer ) {
					// Nothing matched: hide the box instead of showing an empty answer.
					context.dismissed = true;
				} else {
					context.html = data.answer_html;
					context.sources = data.sources;
					context.hasAnswer = true;
					context.hasSources = data.sources.length > 0;
				}
			} catch ( error ) {
				context.message = context.messages.error;
			}

			context.loading = false;
		},

		renderAnswer() {
			const context = getContext();
			const { ref } = getElement();

			if ( ref && context.hasAnswer ) {
				ref.innerHTML = context.html;
			}
		},
	},
} );
