<?php
/**
 * Native WordPress search integration.
 *
 * @package WP_AI_Smart_Search
 */

declare(strict_types=1);

namespace WP_AI_Smart_Search\Hooks;

use Throwable;
use WP_AI_Smart_Search\Indexer;
use WP_AI_Smart_Search\Search_Service;
use WP_Post;
use WP_Query;

/**
 * Makes the site's own search (?s=…) use AI Smart Search, and optionally
 * puts the AI answer above the results.
 *
 * - Results: the main search query is restricted to the hybrid search's top
 *   NATIVE_LIMIT posts in ranked order (post__in + orderby post__in), so any
 *   theme's search.php, pagination and search form keep working. If hybrid
 *   search finds nothing, WordPress's own search runs instead. Searches
 *   restricted to a post type (e.g. a shop's product search) are left alone.
 * - Excerpts: on search results, each post's excerpt is its best-matching
 *   passage.
 * - Auto-insert: when enabled, the AI Answer box is added above the results
 *   (loop_start in classic themes; before the main Query Loop block in
 *   block themes).
 *
 * @package WP_AI_Smart_Search
 */
class Search_Integration {

	/**
	 * Maximum number of ranked results the native search pages through.
	 *
	 * @var int
	 */
	public const NATIVE_LIMIT = 50;

	/**
	 * Query var marking a query handled by AI Smart Search.
	 *
	 * @var string
	 */
	private const FLAG = 'wpaismse_search';

	/**
	 * Best-matching passage per post ID for the current search.
	 *
	 * @var array<int, string>
	 */
	private array $excerpts = array();

	/**
	 * Whether the answer box was already inserted on this page.
	 *
	 * @var bool
	 */
	private bool $inserted = false;

	/**
	 * Register the hooks for this class.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'pre_get_posts', array( $this, 'pre_get_posts' ) );
		add_filter( 'posts_search', array( $this, 'posts_search' ), 10, 2 );
		add_filter( 'get_the_excerpt', array( $this, 'excerpt' ), 20, 2 );
		add_action( 'loop_start', array( $this, 'insert_answer_classic' ) );
		add_filter( 'render_block_core/query', array( $this, 'insert_answer_block_theme' ), 10, 2 );
	}

	/**
	 * Swap the main search query's results for hybrid search results.
	 *
	 * @param WP_Query $query Query being prepared.
	 * @return void
	 */
	public function pre_get_posts( WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() || $query->is_feed() || wp_doing_ajax() ) {
			return;
		}

		if ( ! Search_Service::replaces_search() ) {
			return;
		}

		// Leave post-type-specific searches (e.g. ?post_type=product) to their owners.
		$post_type = $query->get( 'post_type' );
		if ( ! empty( $post_type ) && $post_type !== 'any' ) {
			return;
		}

		$text = Search_Service::normalize_query( $query->get( 's' ) );

		if ( ! Search_Service::is_valid_query( $text ) || Indexer::post_types() === array() ) {
			return;
		}

		try {
			$results = Search_Service::search( $text, self::NATIVE_LIMIT )['results'];
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'WP AI Smart Search: native search failed, using WordPress search: ' . $e->getMessage() );
			return;
		}

		// Nothing found: let WordPress's own search have a go.
		if ( $results === array() ) {
			return;
		}

		foreach ( $results as $result ) {
			$this->excerpts[ $result['id'] ] = $result['excerpt'];
		}

		$query->set( 'post__in', wp_list_pluck( $results, 'id' ) );
		$query->set( 'orderby', 'post__in' );
		$query->set( 'post_type', Indexer::post_types() );
		$query->set( 'ignore_sticky_posts', true );
		$query->set( self::FLAG, true );
	}

	/**
	 * Drop WordPress's LIKE search clause from queries we handle.
	 *
	 * The query keeps its search term, so is_search(), get_search_query() and
	 * the search form still work; only the SQL filter is replaced by post__in.
	 *
	 * @param string   $search SQL search clause.
	 * @param WP_Query $query  Query.
	 * @return string
	 */
	public function posts_search( string $search, WP_Query $query ): string {
		return $query->get( self::FLAG ) ? '' : $search;
	}

	/**
	 * Show the best-matching passage as the excerpt on search results.
	 *
	 * @param string       $excerpt Excerpt.
	 * @param WP_Post|null $post    Post.
	 * @return string
	 */
	public function excerpt( string $excerpt, $post = null ): string {
		if ( ! $post instanceof WP_Post || ! is_search() || empty( $this->excerpts[ $post->ID ] ) ) {
			return $excerpt;
		}

		return $this->excerpts[ $post->ID ];
	}

	/**
	 * Classic themes: print the answer box before the main search loop.
	 *
	 * @param WP_Query $query Query whose loop is starting.
	 * @return void
	 */
	public function insert_answer_classic( WP_Query $query ): void {
		// Block themes render templates to a string first; output here would land outside the page.
		if ( wp_is_block_theme() || ! $this->should_insert( $query ) ) {
			return;
		}

		$this->inserted = true;

		echo Answer_Box::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped in Answer_Box::render().
	}

	/**
	 * Block themes: put the answer box before the Query Loop that shows the search results.
	 *
	 * @param string $content Rendered block.
	 * @param array  $block   Parsed block.
	 * @return string
	 */
	public function insert_answer_block_theme( string $content, array $block ): string {
		global $wp_query;

		if ( empty( $block['attrs']['query']['inherit'] ) || ! $wp_query instanceof WP_Query || ! $this->should_insert( $wp_query ) ) {
			return $content;
		}

		$this->inserted = true;

		return Answer_Box::render() . $content;
	}

	/**
	 * Whether the answer box should be inserted for this query.
	 *
	 * @param WP_Query $query Query.
	 * @return bool
	 */
	private function should_insert( WP_Query $query ): bool {
		return ! $this->inserted
			&& ! is_admin()
			&& $query->is_main_query()
			&& $query->is_search()
			&& Search_Service::auto_answer();
	}
}
