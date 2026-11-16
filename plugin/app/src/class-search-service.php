<?php
/**
 * Cached search service.
 *
 * @package WP_AI_Smart_Search
 */

declare(strict_types=1);

namespace WP_AI_Smart_Search;

/**
 * Shared entry point for searching: query normalisation, caching and the
 * front-end search options. Used by the REST API, the native search
 * integration and the AI answer box.
 *
 * @package WP_AI_Smart_Search
 */
final class Search_Service {

	/**
	 * Seconds search results (and answers) are cached.
	 *
	 * @var int
	 */
	public const CACHE_TTL = 300;

	/**
	 * Shortest accepted query, in characters.
	 *
	 * @var int
	 */
	public const MIN_QUERY = 2;

	/**
	 * Longest accepted query, in characters.
	 *
	 * @var int
	 */
	public const MAX_QUERY = 200;

	/**
	 * Option: use AI Smart Search for the site's built-in search (default on).
	 *
	 * @var string
	 */
	public const REPLACE_SEARCH_OPTION = 'wpaismse_replace_search';

	/**
	 * Option: show an AI answer above search results automatically (default off).
	 *
	 * @var string
	 */
	public const AUTO_ANSWER_OPTION = 'wpaismse_auto_answer';

	/**
	 * Trim, collapse whitespace and cap the length of a query.
	 *
	 * @param mixed $value Raw query.
	 * @return string
	 */
	public static function normalize_query( $value ): string {
		$query = sanitize_text_field( (string) $value );
		// Collapse every run of whitespace (/u: Unicode-aware) to one space.
		$query = trim( (string) preg_replace( '/\s+/u', ' ', $query ) );

		return mb_substr( $query, 0, self::MAX_QUERY );
	}

	/**
	 * Whether a normalized query is long enough to search.
	 *
	 * @param string $query Normalized query.
	 * @return bool
	 */
	public static function is_valid_query( string $query ): bool {
		return mb_strlen( $query ) >= self::MIN_QUERY;
	}

	/**
	 * Run a search, cached for CACHE_TTL seconds.
	 *
	 * Keyword-only fallback results (embedding service down) are not cached,
	 * so full results return as soon as the service is back.
	 *
	 * @param string $query Normalized query.
	 * @param int    $limit Maximum number of results.
	 * @return array{results: array, keyword_only: bool, cached: bool} See Searcher::search().
	 */
	public static function search( string $query, int $limit ): array {
		$key    = 'wpaismse_s_' . md5( (string) wp_json_encode( array( mb_strtolower( $query ), $limit, ( new Embeddings() )->model(), Indexer::post_types() ) ) );
		$search = get_transient( $key );

		if ( is_array( $search ) ) {
			return $search + array( 'cached' => true );
		}

		$search = ( new Searcher() )->search( $query, $limit );

		if ( ! $search['keyword_only'] ) {
			set_transient( $key, $search, self::CACHE_TTL );
		}

		return $search + array( 'cached' => false );
	}

	/**
	 * Whether AI Smart Search replaces the site's built-in search.
	 *
	 * @return bool
	 */
	public static function replaces_search(): bool {
		return (bool) get_option( self::REPLACE_SEARCH_OPTION, true );
	}

	/**
	 * Whether an AI answer is inserted above search results automatically.
	 *
	 * @return bool
	 */
	public static function auto_answer(): bool {
		return (bool) get_option( self::AUTO_ANSWER_OPTION, false );
	}
}
