<?php
/**
 * Admin menu hooks.
 *
 * @package WP_AI_Smart_Search
 */

declare(strict_types=1);

namespace WP_AI_Smart_Search\Hooks;

use WP_AI_Smart_Search\Admin\Chat_Page;
use WP_AI_Smart_Search\Admin\Layout;
use WP_AI_Smart_Search\Admin\Post_Types_Page;
use WP_AI_Smart_Search\Admin\Reindex_Page;
use WP_AI_Smart_Search\Admin\Search_Page;
use WP_AI_Smart_Search\Admin\Settings_Page;

/**
 * Adds the "AI Smart Search" admin menu with its Search, Settings, Chat,
 * Post Types and Re-Index submenu pages, loads their assets, and links Settings from the Plugins
 * screen.
 *
 * @package WP_AI_Smart_Search
 */
class Admin_Menu {

	/**
	 * Settings page.
	 *
	 * @var Settings_Page
	 */
	private Settings_Page $settings_page;

	/**
	 * Search page.
	 *
	 * @var Search_Page
	 */
	private Search_Page $search_page;

	/**
	 * Chat page.
	 *
	 * @var Chat_Page
	 */
	private Chat_Page $chat_page;

	/**
	 * Post Types page.
	 *
	 * @var Post_Types_Page
	 */
	private Post_Types_Page $post_types_page;

	/**
	 * Re-Index page.
	 *
	 * @var Reindex_Page
	 */
	private Reindex_Page $reindex_page;

	/**
	 * Hook suffixes of the plugin's pages, used to load assets only there.
	 *
	 * @var string[]
	 */
	private array $hook_suffixes = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->settings_page   = new Settings_Page();
		$this->search_page     = new Search_Page();
		$this->chat_page       = new Chat_Page();
		$this->post_types_page = new Post_Types_Page();
		$this->reindex_page    = new Reindex_Page();
	}

	/**
	 * Register the hooks for this class.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this->settings_page, 'register_settings' ) );
		add_action( 'admin_init', array( $this->search_page, 'register_settings' ) );
		add_action( 'admin_init', array( $this->chat_page, 'register_settings' ) );
		add_action( 'admin_init', array( $this->post_types_page, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . WP_AI_SMART_SEARCH_PLUGIN_BASENAME, array( $this, 'add_settings_link' ) );
	}

	/**
	 * Add the top-level menu and its submenu pages.
	 *
	 * The first submenu reuses the top-level slug so the menu's first item
	 * reads "Search" instead of repeating the plugin name.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		$this->hook_suffixes[] = (string) add_menu_page(
			__( 'AI Smart Search', 'wpaismse' ),
			__( 'AI Smart Search', 'wpaismse' ),
			'manage_options',
			Layout::SEARCH_PAGE,
			array( $this->search_page, 'render' ),
			'dashicons-search'
		);

		$this->hook_suffixes[] = (string) add_submenu_page(
			Layout::SEARCH_PAGE,
			__( 'Search', 'wpaismse' ),
			__( 'Search', 'wpaismse' ),
			'manage_options',
			Layout::SEARCH_PAGE,
			array( $this->search_page, 'render' )
		);

		$this->hook_suffixes[] = (string) add_submenu_page(
			Layout::SEARCH_PAGE,
			__( 'Settings', 'wpaismse' ),
			__( 'Settings', 'wpaismse' ),
			'manage_options',
			Layout::SETTINGS_PAGE,
			array( $this->settings_page, 'render' )
		);

		$this->hook_suffixes[] = (string) add_submenu_page(
			Layout::SEARCH_PAGE,
			__( 'Chat', 'wpaismse' ),
			__( 'Chat', 'wpaismse' ),
			'manage_options',
			Layout::CHAT_PAGE,
			array( $this->chat_page, 'render' )
		);

		$this->hook_suffixes[] = (string) add_submenu_page(
			Layout::SEARCH_PAGE,
			__( 'Post Types', 'wpaismse' ),
			__( 'Post Types', 'wpaismse' ),
			'manage_options',
			Layout::POST_TYPES_PAGE,
			array( $this->post_types_page, 'render' )
		);

		$this->hook_suffixes[] = (string) add_submenu_page(
			Layout::SEARCH_PAGE,
			__( 'Re-Index', 'wpaismse' ),
			__( 'Re-Index', 'wpaismse' ),
			'manage_options',
			Layout::REINDEX_PAGE,
			array( $this->reindex_page, 'render' )
		);

		$this->hook_suffixes = array_values( array_unique( array_filter( $this->hook_suffixes ) ) );
	}

	/**
	 * Load the stylesheet and script on the plugin's pages only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, $this->hook_suffixes, true ) ) {
			return;
		}

		$css = 'assets/css/settings.css';
		$js  = 'assets/js/settings.js';

		wp_enqueue_style(
			'wpaismse-admin',
			WP_AI_SMART_SEARCH_PLUGIN_URL . $css,
			array(),
			(string) filemtime( WP_AI_SMART_SEARCH_PLUGIN_PATH . $css )
		);

		wp_enqueue_script(
			'wpaismse-admin',
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
	 * Add a "Settings" link to the plugin's row on the Plugins screen.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function add_settings_link( array $links ): array {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( Layout::url( Layout::SETTINGS_PAGE ) ),
				esc_html__( 'Settings', 'wpaismse' )
			)
		);

		return $links;
	}
}
