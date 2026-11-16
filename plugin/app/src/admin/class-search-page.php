<?php
/**
 * Search page.
 *
 * @package WP_AI_Smart_Search
 */

declare(strict_types=1);

namespace WP_AI_Smart_Search\Admin;

use WP_AI_Smart_Search\Chat;
use WP_AI_Smart_Search\Search_Service;

/**
 * AI Smart Search → Search: how visitors see AI Smart Search on the site.
 *
 * - Use AI Smart Search for the theme's own search (default on).
 * - Show the AI answer above search results automatically (default off).
 *
 * @package WP_AI_Smart_Search
 */
class Search_Page {

	/**
	 * Settings group name.
	 *
	 * @var string
	 */
	public const GROUP = 'wpaismse_search';

	/**
	 * Register the options and how they are sanitized. Runs on admin_init.
	 *
	 * An unticked switch isn't submitted, so null sanitizes to false.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			Search_Service::REPLACE_SEARCH_OPTION,
			array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);

		register_setting(
			self::GROUP,
			Search_Service::AUTO_ANSWER_OPTION,
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);
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

		Layout::open( Layout::SEARCH_PAGE, __( 'Search', 'wpaismse' ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag set by options.php's redirect.
		if ( isset( $_GET['settings-updated'] ) && ! get_settings_errors() ) {
			add_settings_error( 'general', 'settings_updated', __( 'Settings saved.', 'wpaismse' ), 'success' );
		}

		settings_errors();
		?>
		<div class="wpaismse-grid">
			<form class="wpaismse-col" action="options.php" method="post">
				<?php settings_fields( self::GROUP ); ?>

				<section class="wpaismse-card">
					<div class="wpaismse-card__head">
						<span class="wpaismse-icon"><?php Layout::icon( 'search' ); ?></span>
						<div>
							<h2 class="wpaismse-card__title"><?php esc_html_e( 'Search on your site', 'wpaismse' ); ?></h2>
							<p class="wpaismse-card__sub"><?php esc_html_e( 'How visitors see AI Smart Search.', 'wpaismse' ); ?></p>
						</div>
					</div>
					<div class="wpaismse-card__body">
						<?php
						$this->switch_row(
							Search_Service::REPLACE_SEARCH_OPTION,
							__( 'Use AI Smart Search for site search', 'wpaismse' ),
							__( 'Your theme\'s search box and results page use keyword + semantic ranking. If nothing matches, WordPress\'s own search is used.', 'wpaismse' ),
							Search_Service::replaces_search()
						);

						$this->switch_row(
							Search_Service::AUTO_ANSWER_OPTION,
							__( 'Show an AI answer above search results', 'wpaismse' ),
							Chat::is_configured()
								? __( 'Adds the AI Answer box automatically. Turn this off if you place the [ai_search_answer] shortcode yourself.', 'wpaismse' )
								: __( 'Needs a chat model: set it up on the Chat page first.', 'wpaismse' ),
							Search_Service::auto_answer()
						);
						?>
					</div>
				</section>

				<div class="wpaismse-savebar">
					<span><?php esc_html_e( 'Changes apply to the next search.', 'wpaismse' ); ?></span>
					<div class="wpaismse-actions">
						<button type="reset" class="wpaismse-btn wpaismse-btn--ghost"><?php esc_html_e( 'Discard', 'wpaismse' ); ?></button>
						<button type="submit" class="wpaismse-btn wpaismse-btn--primary"><?php esc_html_e( 'Save changes', 'wpaismse' ); ?></button>
					</div>
				</div>
			</form>

			<aside class="wpaismse-col">
				<section class="wpaismse-card wpaismse-card--aside">
					<h2 class="wpaismse-card__title"><?php esc_html_e( 'AI answers', 'wpaismse' ); ?></h2>
					<?php if ( Chat::is_configured() ) : ?>
						<p class="wpaismse-status wpaismse-status--ok"><?php esc_html_e( 'Chat model configured', 'wpaismse' ); ?></p>
					<?php else : ?>
						<p class="wpaismse-status"><?php esc_html_e( 'No chat model yet', 'wpaismse' ); ?></p>
						<p class="wpaismse-note">
							<a href="<?php echo esc_url( Layout::url( Layout::CHAT_PAGE ) ); ?>"><?php esc_html_e( 'Set up a chat model →', 'wpaismse' ); ?></a>
						</p>
					<?php endif; ?>
				</section>

				<section class="wpaismse-card wpaismse-card--aside">
					<h2 class="wpaismse-card__title"><?php esc_html_e( 'Place it yourself', 'wpaismse' ); ?></h2>
					<p class="wpaismse-note"><?php esc_html_e( 'Instead of the automatic answer box, add this shortcode where you want the answer to appear on search results:', 'wpaismse' ); ?></p>
					<pre class="wpaismse-code">[ai_search_answer]</pre>
					<p class="wpaismse-note"><?php esc_html_e( 'Works in classic theme templates and widgets, and in a Shortcode block in a block theme\'s Search Results template.', 'wpaismse' ); ?></p>
				</section>
			</aside>
		</div>
		<?php
		Layout::close();
	}

	/**
	 * Render a switch row: label and description on the left, toggle on the right.
	 *
	 * @param string $option      Option name, used as the checkbox id and name.
	 * @param string $label       Field label.
	 * @param string $description Help text.
	 * @param bool   $checked     Current value.
	 * @return void
	 */
	private function switch_row( string $option, string $label, string $description, bool $checked ): void {
		?>
		<div class="wpaismse-row">
			<div class="wpaismse-row__text">
				<label class="wpaismse-row__label" for="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $label ); ?></label>
				<p class="wpaismse-row__desc" id="<?php echo esc_attr( $option ); ?>-desc"><?php echo esc_html( $description ); ?></p>
			</div>
			<input
				class="wpaismse-switch"
				type="checkbox"
				role="switch"
				id="<?php echo esc_attr( $option ); ?>"
				name="<?php echo esc_attr( $option ); ?>"
				value="1"
				aria-describedby="<?php echo esc_attr( $option ); ?>-desc"
				<?php checked( $checked ); ?>
			/>
		</div>
		<?php
	}
}
