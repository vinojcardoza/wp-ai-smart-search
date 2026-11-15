<?php
/**
 * Plugin Name:       WP AI Smart Search
 * Plugin URI:        https://vinojcardoza.com/
 * Description:       Adds AI-powered semantic search to WordPress by storing post content embeddings.
 * Version:           1.0.0
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Author:            Vinoj Cardoza
 * Author URI:        https://vinojcardoza.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpaismse
 * Domain Path:       /languages
 *
 * @package AI_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_AI_SMART_SEARCH_PLUGIN_FILE', __FILE__ );
define( 'WP_AI_SMART_SEARCH_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'WP_AI_SMART_SEARCH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_AI_SMART_SEARCH_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once WP_AI_SMART_SEARCH_PLUGIN_PATH . 'app/class-autoloader.php';
WP_AI_Smart_Search\Autoloader::register();

// Load all the hooks.
( new WP_AI_Smart_Search\Hooks_Manager() )->register();
