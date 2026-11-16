<?php
/**
 * AI answer box: [ai_search_answer] shortcode and shared render.
 *
 * @package WP_AI_Smart_Search
 */

declare(strict_types=1);

namespace WP_AI_Smart_Search\Hooks;

use WP_AI_Smart_Search\Chat;
use WP_AI_Smart_Search\Search_Service;

/**
 * Renders the AI answer box, used by the [ai_search_answer] shortcode and by
 * the search results auto-insert (Search_Integration).
 *
 * The box shows an AI-written answer for the current search (?s=…), fetched
 * from POST /wpaismse/v1/answer by assets/js/answer-view.js (plain script)
 * after the page loads, so results are never delayed by the model.
 * Nothing is rendered when there is no search or the chat model isn't
 * configured.
 *
 * @package WP_AI_Smart_Search
 */
class Answer_Box {

	/**
	 * Script handle.
	 *
	 * @var string
	 */
	public const SCRIPT = 'wpaismse-answer';

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
		add_action( 'init', array( $this, 'register_assets' ) );
		add_shortcode( 'ai_search_answer', array( $this, 'shortcode' ) );
	}

	/**
	 * Register the front-end script and stylesheet (enqueued by render()).
	 *
	 * @return void
	 */
	public function register_assets(): void {
		$css = 'assets/css/answer.css';
		$js  = 'assets/js/answer-view.js';

		wp_register_style( self::STYLE, WP_AI_SMART_SEARCH_PLUGIN_URL . $css, array(), (string) filemtime( WP_AI_SMART_SEARCH_PLUGIN_PATH . $css ) );

		wp_register_script(
			self::SCRIPT,
			WP_AI_SMART_SEARCH_PLUGIN_URL . $js,
			array(),
			(string) filemtime( WP_AI_SMART_SEARCH_PLUGIN_PATH . $js ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Shortcode: [ai_search_answer].
	 *
	 * @return string
	 */
	public function shortcode(): string {
		return self::render();
	}

	/**
	 * Render the answer box for the current search.
	 *
	 * Initial hidden states are set here, so the box looks right before the
	 * script runs; assets/js/answer-view.js reads the data-* attributes.
	 *
	 * @return string HTML, or an empty string when there is nothing to answer.
	 */
	public static function render(): string {
		$query = Search_Service::normalize_query( get_search_query( false ) );

		if ( ! Search_Service::is_valid_query( $query ) || ! Chat::is_configured() ) {
			return '';
		}

		wp_enqueue_script( self::SCRIPT );
		wp_enqueue_style( self::STYLE );

		ob_start();
		?>
		<div
			class="wpaismse-answer"
			data-endpoint="<?php echo esc_url( rest_url( Rest_Api::NAMESPACE . '/answer' ) ); ?>"
			data-query="<?php echo esc_attr( $query ); ?>"
			data-error="<?php esc_attr_e( 'The AI answer is not available right now.', 'wpaismse' ); ?>"
			data-rate-limited="<?php esc_attr_e( 'Too many requests. Please wait a moment and search again.', 'wpaismse' ); ?>"
			aria-busy="true"
			aria-live="polite"
		>
			<p class="wpaismse-answer__label">
				<?php esc_html_e( 'AI answer', 'wpaismse' ); ?>
				<span>· <?php esc_html_e( 'AI-generated, check the sources', 'wpaismse' ); ?></span>
			</p>

			<div class="wpaismse-answer__loading">
				<?php esc_html_e( 'Writing an answer…', 'wpaismse' ); ?>
			</div>

			<div class="wpaismse-answer__body" hidden></div>

			<p class="wpaismse-answer__message" hidden></p>

			<div class="wpaismse-answer__sources" hidden>
				<p class="wpaismse-answer__sources-title"><?php esc_html_e( 'Sources', 'wpaismse' ); ?></p>
				<ol></ol>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
