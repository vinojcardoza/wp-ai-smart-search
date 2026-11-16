<?php
/**
 * Hybrid search.
 *
 * @package WP_AI_Smart_Search
 */

declare(strict_types=1);

namespace WP_AI_Smart_Search;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are logged, never printed as HTML.

use Throwable;

/**
 * Hybrid keyword + semantic search over the index.
 *
 * 1. Keyword: MySQL FULLTEXT on chunk text → best chunk per post, top KEYWORD_LIMIT.
 * 2. Semantic: embed the query, scan stored INT8 vectors in batches of
 *    SCAN_BATCH, cosine similarity → best chunk per post, top SEMANTIC_LIMIT.
 *    Matches below SEMANTIC_RELATIVE_CUTOFF × the best similarity are dropped,
 *    so on small sites the semantic list isn't simply every post.
 * 3. Weighted Reciprocal Rank Fusion (k = RRF_K) combines the two rankings;
 *    keyword ranks count KEYWORD_WEIGHT, so a weak keyword-only hit doesn't
 *    tie with the best semantic match.
 *
 * If the embedding service fails, results fall back to keyword-only and
 * the result says so (keyword_only = true).
 *
 * @package WP_AI_Smart_Search
 */
class Searcher {

	/**
	 * Keyword candidates.
	 *
	 * @var int
	 */
	public const KEYWORD_LIMIT = 100;

	/**
	 * Semantic candidates.
	 *
	 * @var int
	 */
	public const SEMANTIC_LIMIT = 100;

	/**
	 * Semantic matches must score at least this fraction of the best match.
	 *
	 * Relative rather than absolute, because typical similarity values differ
	 * between embedding models. Matches at or below 0 are always dropped.
	 *
	 * @var float
	 */
	public const SEMANTIC_RELATIVE_CUTOFF = 0.5;

	/**
	 * Weight of keyword ranks in the fusion (semantic ranks weigh 1).
	 *
	 * @var float
	 */
	public const KEYWORD_WEIGHT = 0.8;

	/**
	 * Vectors read from the database per batch during the scan.
	 *
	 * @var int
	 */
	public const SCAN_BATCH = 500;

	/**
	 * Reciprocal Rank Fusion constant: higher values flatten the advantage of top ranks.
	 *
	 * @var int
	 */
	public const RRF_K = 60;

	/**
	 * Words kept in each result's excerpt.
	 *
	 * @var int
	 */
	public const EXCERPT_WORDS = 40;

	/**
	 * Embedding service client.
	 *
	 * @var Embeddings
	 */
	private Embeddings $embeddings;

	/**
	 * Index repository.
	 *
	 * @var Index_Repository
	 */
	private Index_Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param Embeddings|null       $embeddings Embedding client. Defaults to a new Embeddings.
	 * @param Index_Repository|null $repository Index repository. Defaults to a new Index_Repository.
	 */
	public function __construct( ?Embeddings $embeddings = null, ?Index_Repository $repository = null ) {
		$this->embeddings = $embeddings ?? new Embeddings();
		$this->repository = $repository ?? new Index_Repository();
	}

	/**
	 * Search the index.
	 *
	 * @param string $query Search text.
	 * @param int    $limit Maximum number of results.
	 * @return array{
	 *     results: array,
	 *     keyword_only: bool
	 * } results is a list of array{
	 *     id: int, title: string, url: string, type: string,
	 *     excerpt: string, content: string, score: float
	 * }, best first. excerpt is the best chunk trimmed to EXCERPT_WORDS words;
	 * content is the full chunk (for the chat prompt).
	 */
	public function search( string $query, int $limit = 10 ): array {
		$query = trim( $query );

		if ( $query === '' || $limit < 1 ) {
			return array(
				'results'      => array(),
				'keyword_only' => false,
			);
		}

		$keyword      = $this->repository->keyword_search( $query, self::KEYWORD_LIMIT );
		$keyword_only = false;

		try {
			$semantic = $this->semantic_search( $query, self::SEMANTIC_LIMIT );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'WP AI Smart Search: semantic search failed, using keyword results only: ' . $e->getMessage() );
			$semantic     = array();
			$keyword_only = true;
		}

		$ranked = array_slice(
			self::fuse(
				array(
					array( array_keys( $keyword ), self::KEYWORD_WEIGHT ),
					array( array_keys( $semantic ), 1.0 ),
				)
			),
			0,
			$limit,
			true
		);

		return array(
			'results'      => $this->hydrate( $ranked, $keyword, $semantic ),
			'keyword_only' => $keyword_only,
		);
	}

	/**
	 * Combine rankings with weighted Reciprocal Rank Fusion.
	 *
	 * Each list adds weight / (RRF_K + rank) to a post's score, rank starting at 1.
	 *
	 * @param array $rankings List of array{0: int[], 1: float}: post IDs best first, and the list's weight.
	 * @return array<int, float> Fused scores keyed by post ID, best first.
	 */
	public static function fuse( array $rankings ): array {
		$scores = array();

		foreach ( $rankings as [ $ranking, $weight ] ) {
			foreach ( array_values( $ranking ) as $index => $post_id ) {
				$scores[ $post_id ] = ( $scores[ $post_id ] ?? 0.0 ) + $weight / ( self::RRF_K + $index + 1 );
			}
		}

		arsort( $scores );

		return $scores;
	}

	/**
	 * Semantic search: cosine similarity between the query and every stored vector.
	 *
	 * Uses the raw INT8 values: cosine similarity ignores a vector's scale,
	 * so the stored scale is not needed here.
	 *
	 * @param string $query Search text.
	 * @param int    $limit Maximum number of posts.
	 * @return array<int, array{chunk_id: int, score: float}> Keyed by post ID, best first.
	 * @throws \Exception If the query cannot be embedded.
	 */
	private function semantic_search( string $query, int $limit ): array {
		$vector = $this->embeddings->generate_embeddings( array( $query ) )[0];

		if ( count( $vector ) !== Embeddings::DIMENSIONS ) {
			throw new \Exception( sprintf( 'Query embedding has %d dimensions, expected %d.', count( $vector ), Embeddings::DIMENSIONS ) );
		}

		$norm = sqrt( array_sum( array_map( fn( $value ): float => (float) $value * (float) $value, $vector ) ) );

		if ( $norm <= 0.0 ) {
			return array();
		}

		$query_vector = array_map( fn( $value ): float => (float) $value / $norm, array_values( $vector ) );
		$model        = $this->embeddings->model();
		$best         = array();
		$after_id     = 0;

		do {
			$rows    = $this->repository->embedding_batch( $after_id, self::SCAN_BATCH, $model );
			$fetched = count( $rows );

			foreach ( $rows as $row ) {
				$after_id = $row['id'];
				$values   = unpack( 'c*', $row['embedding'] );

				if ( $values === false || count( $values ) !== Embeddings::DIMENSIONS ) {
					continue;
				}

				$dot    = 0.0;
				$length = 0;

				// unpack() keys start at 1.
				foreach ( $query_vector as $i => $q ) {
					$v       = $values[ $i + 1 ];
					$dot    += $q * $v;
					$length += $v * $v;
				}

				if ( $length === 0 ) {
					continue;
				}

				$score = $dot / sqrt( $length );

				if ( ! isset( $best[ $row['post_id'] ] ) || $score > $best[ $row['post_id'] ]['score'] ) {
					$best[ $row['post_id'] ] = array(
						'chunk_id' => $row['id'],
						'score'    => $score,
					);
				}
			}
		} while ( self::SCAN_BATCH === $fetched );

		uasort( $best, fn( array $a, array $b ): int => $b['score'] <=> $a['score'] );

		$top    = (float) ( reset( $best )['score'] ?? 0.0 );
		$cutoff = max( 0.0, $top * self::SEMANTIC_RELATIVE_CUTOFF );
		$best   = array_filter( $best, fn( array $candidate ): bool => $candidate['score'] > $cutoff );

		return array_slice( $best, 0, $limit, true );
	}

	/**
	 * Turn ranked post IDs into result arrays.
	 *
	 * The excerpt comes from the post's best semantic chunk, or its best
	 * keyword chunk when it was only found by keyword.
	 *
	 * @param array<int, float>                              $ranked   Fused scores keyed by post ID.
	 * @param array<int, array{chunk_id: int, score: float}> $keyword  Keyword matches.
	 * @param array<int, array{chunk_id: int, score: float}> $semantic Semantic matches.
	 * @return array
	 */
	private function hydrate( array $ranked, array $keyword, array $semantic ): array {
		$chunk_ids = array();

		foreach ( array_keys( $ranked ) as $post_id ) {
			$chunk_ids[ $post_id ] = $semantic[ $post_id ]['chunk_id'] ?? $keyword[ $post_id ]['chunk_id'];
		}

		$texts   = $this->repository->chunk_texts( array_values( $chunk_ids ) );
		$results = array();

		foreach ( $ranked as $post_id => $score ) {
			$post = get_post( $post_id );

			if ( ! $post || $post->post_status !== 'publish' ) {
				continue;
			}

			$content = $texts[ $chunk_ids[ $post_id ] ] ?? '';

			$results[] = array(
				'id'      => (int) $post_id,
				'title'   => html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'url'     => (string) get_permalink( $post ),
				'type'    => $post->post_type,
				'excerpt' => wp_trim_words( $content, self::EXCERPT_WORDS ),
				'content' => $content,
				'score'   => round( $score, 6 ),
			);
		}

		return $results;
	}
}
