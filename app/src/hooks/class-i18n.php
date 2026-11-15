<?php
/**
 * Internationalization hooks.
 *
 * @package WP_AI_Smart_Search
 */

declare(strict_types=1);

namespace WP_AI_Smart_Search\Hooks;

/**
 * Loads the plugin's translations.
 *
 * @package WP_AI_Smart_Search
 */
class I18n {

	/**
	 * Register the hooks for this class.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Load the plugin's translations from the /languages directory.
	 *
	 * Translation files must be named wpaismse-{locale}.mo (e.g. wpaismse-fr_FR.mo).
	 * Translations installed in wp-content/languages/plugins/ take precedence.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'wpaismse', false, dirname( WP_AI_SMART_SEARCH_PLUGIN_BASENAME ) . '/languages' );
	}
}
