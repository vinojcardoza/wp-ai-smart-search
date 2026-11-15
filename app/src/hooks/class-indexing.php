<?php
/**
 * Indexing hooks.
 *
 * @package WP_AI_Smart_Search
 */

declare(strict_types=1);

namespace WP_AI_Smart_Search\Hooks;

use Throwable;
use WP_AI_Smart_Search\Index_Repository;
use WP_AI_Smart_Search\Indexer;
use WP_Post;

/**
 * Keeps the search index in sync with post changes.
 *
 * Saving a post schedules a one-off WP-Cron event instead of indexing
 * inline, because embedding requests can take up to 90 seconds each and
 * would make saving slow. Trashed and deleted posts are removed from the
 * index straight away (no network calls needed).
 *
 * @package WP_AI_Smart_Search
 */
class Indexing {

	/**
	 * WP-Cron hook that indexes one post. Its only argument is the post ID.
	 *
	 * @var string
	 */
	public const CRON_HOOK = 'wpaismse_index_post';

	/**
	 * Register the hooks for this class.
	 *
	 * @return void
	 */
	public function register(): void {
		// Fires after terms and meta are saved, including status changes like unpublish and trash.
		add_action( 'wp_after_insert_post', array( $this, 'schedule' ), 10, 2 );
		add_action( 'trashed_post', array( $this, 'remove' ) );
		add_action( 'deleted_post', array( $this, 'remove' ) );
		add_action( self::CRON_HOOK, array( $this, 'run' ) );

		// Post types switched off on the Post Types page leave the index immediately.
		add_action( 'add_option_' . Indexer::POST_TYPES_OPTION, array( $this, 'post_types_added' ), 10, 2 );
		add_action( 'update_option_' . Indexer::POST_TYPES_OPTION, array( $this, 'post_types_updated' ), 10, 2 );
	}

	/**
	 * First save of the post types option: compare against the defaults.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  New value.
	 * @return void
	 */
	public function post_types_added( string $option, $value ): void {
		$this->remove_disabled_types( Indexer::DEFAULT_POST_TYPES, $value );
	}

	/**
	 * Post types option changed: remove types that were switched off.
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $value     New value.
	 * @return void
	 */
	public function post_types_updated( $old_value, $value ): void {
		$this->remove_disabled_types( is_array( $old_value ) ? $old_value : Indexer::DEFAULT_POST_TYPES, $value );
	}

	/**
	 * Remove indexed posts of every type in $before that is not in $after.
	 *
	 * @param array $before Previously enabled post types.
	 * @param mixed $after  Newly enabled post types.
	 * @return void
	 */
	private function remove_disabled_types( array $before, $after ): void {
		$disabled = array_diff( $before, is_array( $after ) ? $after : array() );

		if ( array() === $disabled ) {
			return;
		}

		try {
			( new Index_Repository() )->delete_post_types( $disabled );
		} catch ( Throwable $e ) {
			$this->log( 0, $e );
		}
	}

	/**
	 * Schedule a post to be indexed (or removed) in the background.
	 *
	 * Runs for every save of an indexable post type, whatever its status:
	 * Indexer::index_post() decides whether to index or remove it.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function schedule( int $post_id, WP_Post $post ): void {

		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, Indexer::post_types(), true ) ) {
			return;
		}

		$args = array( $post_id );

		if ( false === wp_next_scheduled( self::CRON_HOOK, $args ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK, $args );
		}
	}

	/**
	 * Remove a trashed or deleted post from the index immediately.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function remove( int $post_id ): void {
		wp_clear_scheduled_hook( self::CRON_HOOK, array( $post_id ) );

		try {
			( new Index_Repository() )->delete_post( $post_id );
		} catch ( Throwable $e ) {
			$this->log( $post_id, $e );
		}
	}

	/**
	 * WP-Cron callback: index one post.
	 *
	 * Errors are logged rather than thrown, so one failing post does not
	 * stop other cron events. The old index is kept on failure.
	 *
	 * @param int|string $post_id Post ID (WP-Cron may pass it as a string).
	 * @return void
	 */
	public function run( int|string $post_id ): void {
		try {
			( new Indexer() )->index_post( (int) $post_id );
		} catch ( Throwable $e ) {
			$this->log( (int) $post_id, $e );
		}
	}

	/**
	 * Log an indexing error.
	 *
	 * @param int       $post_id Post ID.
	 * @param Throwable $e       Error.
	 * @return void
	 */
	private function log( int $post_id, Throwable $e ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf( 'WP AI Smart Search: indexing post %d failed: %s', $post_id, $e->getMessage() ) );
	}
}
