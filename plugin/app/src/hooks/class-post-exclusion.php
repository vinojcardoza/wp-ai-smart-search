<?php
/**
 * Per-post exclusion hooks.
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
 * Lets editors exclude a single post from AI search.
 *
 * - Block editor: an "AI Smart Search" panel in the post sidebar
 *   (assets/js/editor.js) that edits the _wpaismse_exclude meta over REST.
 * - Classic editor: a side meta box with the same checkbox. It is marked
 *   __back_compat_meta_box, so the block editor hides it.
 *
 * Ticking the option removes the post from the index immediately. Unticking
 * it re-indexes the post through the usual save hook (Hooks\Indexing).
 *
 * Only shown for post types enabled on the Post Types page.
 *
 * @package WP_AI_Smart_Search
 */
class Post_Exclusion {

	/**
	 * Nonce action and field name for the classic editor meta box.
	 *
	 * @var string
	 */
	private const NONCE = 'wpaismse_exclude';

	/**
	 * Register the hooks for this class.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_meta' ), 20 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ), 10, 2 );

		// Remove from the index as soon as the post is excluded, however the meta is set.
		add_action( 'added_post_meta', array( $this, 'meta_changed' ), 10, 4 );
		add_action( 'updated_post_meta', array( $this, 'meta_changed' ), 10, 4 );
	}

	/**
	 * Register the exclude meta for every enabled post type, editable over REST.
	 *
	 * The block editor only saves meta for post types that support
	 * custom-fields, so that support is added where missing.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		foreach ( Indexer::post_types() as $post_type ) {
			if ( ! post_type_exists( $post_type ) ) {
				continue;
			}

			if ( ! post_type_supports( $post_type, 'custom-fields' ) ) {
				add_post_type_support( $post_type, 'custom-fields' );
			}

			register_post_meta(
				$post_type,
				Indexer::EXCLUDE_META,
				array(
					'type'              => 'boolean',
					'description'       => __( 'Exclude this post from AI search.', 'wpaismse' ),
					'single'            => true,
					'default'           => false,
					'show_in_rest'      => true,
					'sanitize_callback' => 'rest_sanitize_boolean',
					'auth_callback'     => fn( $allowed, $meta_key, $post_id ): bool => current_user_can( 'edit_post', (int) $post_id ),
				)
			);
		}
	}

	/**
	 * Load the block editor panel for enabled post types.
	 *
	 * @return void
	 */
	public function enqueue_editor_assets(): void {
		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->post_type, Indexer::post_types(), true ) ) {
			return;
		}

		$file = 'assets/js/editor.js';

		wp_enqueue_script(
			'wpaismse-editor',
			WP_AI_SMART_SEARCH_PLUGIN_URL . $file,
			array( 'wp-components', 'wp-data', 'wp-editor', 'wp-element', 'wp-i18n', 'wp-plugins' ),
			(string) filemtime( WP_AI_SMART_SEARCH_PLUGIN_PATH . $file ),
			true
		);

		wp_set_script_translations( 'wpaismse-editor', 'wpaismse', WP_AI_SMART_SEARCH_PLUGIN_PATH . 'languages' );
	}

	/**
	 * Add the classic editor meta box (hidden in the block editor).
	 *
	 * @return void
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'wpaismse-exclude',
			__( 'AI Smart Search', 'wpaismse' ),
			array( $this, 'render_meta_box' ),
			Indexer::post_types(),
			'side',
			'default',
			array( '__back_compat_meta_box' => true )
		);
	}

	/**
	 * Render the classic editor meta box.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return void
	 */
	public function render_meta_box( WP_Post $post ): void {
		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( Indexer::EXCLUDE_META ); ?>" value="1" <?php checked( Indexer::is_excluded( $post->ID ) ); ?> />
			<?php esc_html_e( 'Exclude from AI search', 'wpaismse' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'This post will not be indexed or returned in AI search results.', 'wpaismse' ); ?></p>
		<?php
	}

	/**
	 * Save the classic editor meta box.
	 *
	 * Runs before wp_after_insert_post, so the indexing job scheduled there
	 * already sees the new value.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save_meta_box( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, Indexer::post_types(), true ) ) {
			return;
		}

		if ( isset( $_POST[ Indexer::EXCLUDE_META ] ) ) {
			update_post_meta( $post_id, Indexer::EXCLUDE_META, true );
		} else {
			delete_post_meta( $post_id, Indexer::EXCLUDE_META );
		}
	}

	/**
	 * Remove a post from the index right away when it is excluded.
	 *
	 * No network call is needed, so this does not wait for the cron job.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return void
	 */
	public function meta_changed( $meta_id, $post_id, $meta_key, $meta_value ): void {
		if ( Indexer::EXCLUDE_META !== $meta_key || ! rest_sanitize_boolean( $meta_value ) ) {
			return;
		}

		try {
			( new Index_Repository() )->delete_post( (int) $post_id );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( 'WP AI Smart Search: removing excluded post %d failed: %s', (int) $post_id, $e->getMessage() ) );
		}
	}
}
