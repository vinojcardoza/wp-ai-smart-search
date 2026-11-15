<?php
/**
 * AI Answer block and shortcode.
 *
 * @package WP_AI_Smart_Search
 */

declare(strict_types=1);

namespace WP_AI_Smart_Search\Hooks;

use WP_AI_Smart_Search\Chat;
use WP_AI_Smart_Search\Search_Service;

/**
 * Registers the "AI Answer" block (wpaismse/ai-answer) and the
 * [ai_search_answer] shortcode, and renders their shared markup.
 *
 * Both show an AI-written answer for the current search (?s=…), fetched
 * from POST /wpaismse/v1/answer by assets/js/answer-view.js (Interactivity
 * API) after the page loads, so results are never delayed by the model.
 * Nothing is rendered when there is no search or the chat model isn't
 * configured.
 *
 * @package WP_AI_Smart_Search
 */
class Answer_Block {

	/**
	 * Script module ID of the front-end store.
	 *
	 * @var string
	 */
	public const MODULE = 'wpaismse-answer';

	/**
	 * Style handle.
	 *
	 * @var string
	 */
	public const STYLE = 'wpaismse-answer';

	/**
	 * Register the hooks for this class.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
		add_shortcode( 'ai_search_answer', array( $this, 'shortcode' ) );
	}

	/**
	 * Register the block, its front-end module and its stylesheet.
	 *
	 * @return void
	 */
	public function register_block(): void {
		$css = 'assets/css/answer.css';
		$js  = 'assets/js/answer-view.js';

		wp_register_style( self::STYLE, WP_AI_SMART_SEARCH_PLUGIN_URL . $css, array(), (string) filemtime( WP_AI_SMART_SEARCH_PLUGIN_PATH . $css ) );

		wp_register_script_module(
			self::MODULE,
			WP_AI_SMART_SEARCH_PLUGIN_URL . $js,
			array( '@wordpress/interactivity' ),
			(string) filemtime( WP_AI_SMART_SEARCH_PLUGIN_PATH . $js )
		);

		register_block_type(
			WP_AI_SMART_SEARCH_PLUGIN_PATH . 'blocks/ai-answer',
			array(
				'render_callback' => array( $this, 'render_block' ),
				'style_handles'   => array( self::STYLE ),
			)
		);
	}

	/**
	 * Block render callback.
	 *
	 * Core processes the Interactivity directives of blocks that support
	 * interactivity, so the markup is returned unprocessed.
	 *
	 * The block has no attributes; wrapper attributes (spacing etc.) come from
	 * get_block_wrapper_attributes(), which reads the block being rendered.
	 *
	 * @return string
	 */
	public function render_block(): string {
		return self::render( get_block_wrapper_attributes( array( 'class' => 'wpaismse-answer' ) ), false );
	}

	/**
	 * Shortcode: [ai_search_answer].
	 *
	 * @return string
	 */
	public function shortcode(): string {
		return self::render( 'class="wpaismse-answer"', true );
	}

	/**
	 * Render the answer box for the current search.
	 *
	 * @param string $wrapper_attributes Attributes for the wrapper element (already escaped).
	 * @param bool   $process            Run the Interactivity API's server-side directive
	 *                                   processing (needed outside blocks).
	 * @return string HTML, or an empty string when there is nothing to answer.
	 */
	public static function render( string $wrapper_attributes, bool $process ): string {
		$query = Search_Service::normalize_query( get_search_query( false ) );

		if ( ! Search_Service::is_valid_query( $query ) || ! Chat::is_configured() ) {
			return '';
		}

		wp_enqueue_script_module( self::MODULE );
		wp_enqueue_style( self::STYLE );

		$context = array(
			'endpoint'   => rest_url( Rest_Api::NAMESPACE . '/answer' ),
			'query'      => $query,
			'started'    => false,
			'loading'    => true,
			'dismissed'  => false,
			'hasAnswer'  => false,
			'hasSources' => false,
			'html'       => '',
			'message'    => '',
			'sources'    => array(),
			'messages'   => array(
				'error'       => __( 'The AI answer is not available right now.', 'wpaismse' ),
				'rateLimited' => __( 'Too many requests. Please wait a moment and search again.', 'wpaismse' ),
			),
		);

		ob_start();
		?>
		<div
			<?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes() or a literal. ?>
			data-wp-interactive="wpaismse/answer"
			<?php echo wp_interactivity_data_wp_context( $context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by core. ?>
			data-wp-init="callbacks.load"
			data-wp-bind--hidden="context.dismissed"
			data-wp-bind--aria-busy="context.loading"
			aria-live="polite"
		>
			<p class="wpaismse-answer__label">
				<?php esc_html_e( 'AI answer', 'wpaismse' ); ?>
				<span>· <?php esc_html_e( 'AI-generated, check the sources', 'wpaismse' ); ?></span>
			</p>

			<div class="wpaismse-answer__loading" data-wp-bind--hidden="!context.loading">
				<?php esc_html_e( 'Writing an answer…', 'wpaismse' ); ?>
			</div>

			<div class="wpaismse-answer__body" data-wp-bind--hidden="!context.hasAnswer" data-wp-watch="callbacks.renderAnswer"></div>

			<p class="wpaismse-answer__message" data-wp-bind--hidden="!context.message" data-wp-text="context.message"></p>

			<div data-wp-bind--hidden="!context.hasSources">
				<p class="wpaismse-answer__sources-title"><?php esc_html_e( 'Sources', 'wpaismse' ); ?></p>
				<ol class="wpaismse-answer__sources">
					<template data-wp-each="context.sources">
						<li><a data-wp-bind--href="context.item.url" data-wp-text="context.item.title"></a></li>
					</template>
				</ol>
			</div>
		</div>
		<?php
		$html = (string) ob_get_clean();

		return $process ? wp_interactivity_process_directives( $html ) : $html;
	}
}
