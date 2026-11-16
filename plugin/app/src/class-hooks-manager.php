<?php
/**
 * Hooks manager.
 *
 * @package WP_AI_Smart_Search
 */

declare(strict_types=1);

namespace WP_AI_Smart_Search;

/**
 * Registers all of the plugin's WordPress hooks.
 *
 * Each class listed in $hooks lives in app/src/hooks/ and exposes a
 * register() method that adds its actions and filters.
 *
 * @package WP_AI_Smart_Search
 */
final class Hooks_Manager {

	/**
	 * Hook classes to register.
	 *
	 * @var string[]
	 */
	private array $hooks = array(
		Hooks\Initializer::class,
		Hooks\I18n::class,
		Hooks\Indexing::class,
		Hooks\Admin_Menu::class,
		Hooks\Reindexing::class,
		Hooks\Post_Exclusion::class,
		Hooks\Rest_Api::class,
		Hooks\Search_Integration::class,
		Hooks\Answer_Box::class,
	);

	/**
	 * Instantiate each hook class and register its hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( $this->hooks as $hook_class ) {
			( new $hook_class() )->register();
		}
	}
}
