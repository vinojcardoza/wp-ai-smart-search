<?php
/**
 * Settings page.
 *
 * @package WP_AI_Smart_Search
 */

declare(strict_types=1);

namespace WP_AI_Smart_Search\Admin;

use WP_AI_Smart_Search\Embeddings;

/**
 * AI Smart Search → Settings: the embedding endpoint, API key and model.
 *
 * Saving uses the WordPress Settings API (options.php), which handles the
 * nonce, capability check and sanitizing.
 *
 * @package WP_AI_Smart_Search
 */
class Settings_Page {

	/**
	 * Settings group name.
	 *
	 * @var string
	 */
	public const GROUP = 'wpaismse_settings';

	/**
	 * Register the options and how they are sanitized. Runs on admin_init.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			Embeddings::ENDPOINT_OPTION,
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( $this, 'sanitize_endpoint' ),
			)
		);

		register_setting(
			self::GROUP,
			Embeddings::API_KEY_OPTION,
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		register_setting(
			self::GROUP,
			Embeddings::MODEL_OPTION,
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);
	}

	/**
	 * Sanitize the endpoint URL. Only http and https URLs are accepted.
	 *
	 * An invalid URL shows an error and keeps the saved value.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_endpoint( $value ): string {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		$url = esc_url_raw( $value, array( 'http', 'https' ) );

		if ( '' === $url ) {
			function_exists( 'add_settings_error' ) && add_settings_error(
				Embeddings::ENDPOINT_OPTION,
				'invalid_endpoint',
				__( 'The endpoint must be a valid http or https URL.', 'wpaismse' )
			);

			return (string) get_option( Embeddings::ENDPOINT_OPTION, '' );
		}

		return $url;
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		Layout::open( Layout::SETTINGS_PAGE, __( 'Settings', 'wpaismse' ) );

		$this->notices();
		?>
		<div class="wpaismse-grid">
			<form class="wpaismse-col" action="options.php" method="post">
				<?php settings_fields( self::GROUP ); ?>

				<section class="wpaismse-card">
					<div class="wpaismse-card__head">
						<span class="wpaismse-icon"><?php Layout::icon( 'plug' ); ?></span>
						<div>
							<h2 class="wpaismse-card__title"><?php esc_html_e( 'Embedding service', 'wpaismse' ); ?></h2>
							<p class="wpaismse-card__sub"><?php esc_html_e( 'Where post content is sent to be turned into vectors.', 'wpaismse' ); ?></p>
						</div>
					</div>
					<div class="wpaismse-card__body">
						<?php
						$this->row(
							Embeddings::ENDPOINT_OPTION,
							'url',
							__( 'Endpoint URL', 'wpaismse' ),
							__( 'Required. An http or https URL that accepts a batch of texts and returns one vector per text.', 'wpaismse' ),
							'https://example.com/embed'
						);

						$this->row(
							Embeddings::API_KEY_OPTION,
							'password',
							__( 'API key', 'wpaismse' ),
							__( 'Optional. Sent as a Bearer token only when set. Leave empty for services without authentication.', 'wpaismse' ),
							''
						);

						$this->row(
							Embeddings::MODEL_OPTION,
							'text',
							__( 'Model', 'wpaismse' ),
							sprintf(
								/* translators: %d: number of dimensions. */
								__( 'Must produce %d-dimension vectors. Changing it re-embeds each post on its next index run.', 'wpaismse' ),
								Embeddings::DIMENSIONS
							),
							'all-MiniLM-L6-v2'
						);
						?>
					</div>
				</section>

				<div class="wpaismse-savebar">
					<span><?php esc_html_e( 'Changes apply to the next indexing run.', 'wpaismse' ); ?></span>
					<div class="wpaismse-actions">
						<button type="reset" class="wpaismse-btn wpaismse-btn--ghost"><?php esc_html_e( 'Discard', 'wpaismse' ); ?></button>
						<button type="submit" class="wpaismse-btn wpaismse-btn--primary"><?php esc_html_e( 'Save changes', 'wpaismse' ); ?></button>
					</div>
				</div>
			</form>

			<aside class="wpaismse-col">
				<section class="wpaismse-card wpaismse-card--aside">
					<h2 class="wpaismse-card__title"><?php esc_html_e( 'Request format', 'wpaismse' ); ?></h2>
					<pre class="wpaismse-code">POST {"texts": ["…", "…"]}
→ {"embeddings": [[…], […]]}</pre>
					<p class="wpaismse-note"><?php esc_html_e( 'Up to 16 texts per request, one vector per text in the same order.', 'wpaismse' ); ?></p>
				</section>
			</aside>
		</div>
		<?php
		Layout::close();
	}

	/**
	 * Show "Settings saved." and validation errors after saving.
	 *
	 * WordPress only does this automatically for pages under the Settings
	 * menu (options-head.php), so a top-level page has to do it itself.
	 *
	 * @return void
	 */
	private function notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag set by options.php's redirect.
		if ( isset( $_GET['settings-updated'] ) && ! get_settings_errors() ) {
			add_settings_error( 'general', 'settings_updated', __( 'Settings saved.', 'wpaismse' ), 'success' );
		}

		settings_errors();
	}

	/**
	 * Render one setting row: label and description on the left, input on the right.
	 *
	 * @param string $option      Option name, used as the input's id and name.
	 * @param string $type        Input type: text, url or password.
	 * @param string $label       Field label.
	 * @param string $description Help text.
	 * @param string $placeholder Placeholder text.
	 * @return void
	 */
	private function row( string $option, string $type, string $label, string $description, string $placeholder ): void {
		?>
		<div class="wpaismse-row">
			<div class="wpaismse-row__text">
				<label class="wpaismse-row__label" for="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $label ); ?></label>
				<p class="wpaismse-row__desc" id="<?php echo esc_attr( $option ); ?>-desc"><?php echo esc_html( $description ); ?></p>
			</div>
			<input
				class="wpaismse-input"
				type="<?php echo esc_attr( $type ); ?>"
				id="<?php echo esc_attr( $option ); ?>"
				name="<?php echo esc_attr( $option ); ?>"
				value="<?php echo esc_attr( (string) get_option( $option, '' ) ); ?>"
				placeholder="<?php echo esc_attr( $placeholder ); ?>"
				aria-describedby="<?php echo esc_attr( $option ); ?>-desc"
				autocomplete="off"
				spellcheck="false"
			/>
		</div>
		<?php
	}
}
