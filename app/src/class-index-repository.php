<?php
/**
 * Search index repository.
 *
 * @package WP_AI_Smart_Search
 */

declare(strict_types=1);

namespace WP_AI_Smart_Search;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are logged, never printed as HTML.

use RuntimeException;
use Throwable;

/**
 * Reads and writes the plugin's index tables.
 *
 * This is the only class that runs SQL against {prefix}ai_documents and
 * {prefix}ai_chunks, and the single owner of their names.
 *
 * @package WP_AI_Smart_Search
 */
class Index_Repository {

	/**
	 * How many chunks keyword_search() reads per wanted post, since one post
	 * can have several matching chunks.
	 *
	 * @var int
	 */
	private const KEYWORD_OVERFETCH = 5;

	/**
	 * Get the plugin's table names, keyed by short name.
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return array{documents: string, chunks: string}
	 */
	public static function table_names(): array {
		global $wpdb;

		return array(
			'documents' => $wpdb->prefix . 'ai_documents',
			'chunks'    => $wpdb->prefix . 'ai_chunks',
		);
	}

	/**
	 * Get the stored content hash of an indexed post.
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null The hash, or null if the post is not indexed.
	 */
	public function get_content_hash( int $post_id ): ?string {
		global $wpdb;

		$tables = self::table_names();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$hash = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT content_hash FROM %i WHERE post_id = %d',
				$tables['documents'],
				$post_id
			)
		);

		return null === $hash ? null : (string) $hash;
	}

	/**
	 * Replace a post's index with new chunks in one transaction.
	 *
	 * Deletes the post's old chunks, upserts its document row and inserts the
	 * new chunks. If anything fails, the whole change is rolled back and the
	 * old index stays in place.
	 *
	 * Chunk positions (chunk_index) are taken from the order of $chunks, so
	 * callers never have to number them.
	 *
	 * Call this only after all embeddings are ready, so the transaction does
	 * not stay open while waiting on the embedding service.
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $hash    Content hash to store for the post.
	 * @param string $model   Embedding model name stored with each chunk.
	 * @param array  $chunks  {
	 *     Chunks in order. May be empty (the post is recorded as indexed with no chunks).
	 *
	 *     @type array ...$0 {
	 *         @type string $text  Chunk text.
	 *         @type string $blob  INT8 embedding from Quantizer::quantize().
	 *         @type float  $scale Scale from Quantizer::quantize().
	 *     }
	 * }
	 * @return void
	 * @throws Throwable RuntimeException if a query fails, or any other error during the
	 *                   writes. The transaction is rolled back before it is rethrown.
	 */
	public function replace_post( int $post_id, string $hash, string $model, array $chunks ): void {
		global $wpdb;

		$tables = self::table_names();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'START TRANSACTION' );

		try {
			$this->check( $wpdb->delete( $tables['chunks'], array( 'post_id' => $post_id ), array( '%d' ) ) );

			$this->check(
				$wpdb->replace(
					$tables['documents'],
					array(
						'post_id'      => $post_id,
						'content_hash' => $hash,
						'indexed_at'   => current_time( 'mysql', true ),
					),
					array( '%d', '%s', '%s' )
				)
			);

			foreach ( array_values( $chunks ) as $chunk_index => $chunk ) {
				$this->check(
					$wpdb->insert(
						$tables['chunks'],
						array(
							'post_id'         => $post_id,
							'chunk_index'     => $chunk_index,
							'chunk_text'      => $chunk['text'],
							'embedding'       => $chunk['blob'],
							// '%s' with full precision: $wpdb rounds '%f' to 6 decimals.
							'embedding_scale' => Quantizer::format_scale( $chunk['scale'] ),
							'model_name'      => $model,
						),
						array( '%d', '%d', '%s', '%s', '%s', '%s' )
					)
				);
			}

			$wpdb->query( 'COMMIT' );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Remove a post from the index.
	 *
	 * Safe to call for posts that were never indexed.
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 * @throws RuntimeException If a query fails.
	 */
	public function delete_post( int $post_id ): void {
		global $wpdb;

		$tables = self::table_names();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$this->check( $wpdb->delete( $tables['chunks'], array( 'post_id' => $post_id ), array( '%d' ) ) );
		$this->check( $wpdb->delete( $tables['documents'], array( 'post_id' => $post_id ), array( '%d' ) ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Remove every indexed post of the given post types.
	 *
	 * Used when post types are switched off, so their content leaves search
	 * right away instead of waiting for a re-index.
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param string[] $post_types Post type names.
	 * @return int Number of posts removed from the index.
	 * @throws RuntimeException If a query fails.
	 */
	public function delete_post_types( array $post_types ): int {
		global $wpdb;

		$post_types = array_values( array_filter( array_map( 'strval', $post_types ) ) );

		if ( array() === $post_types ) {
			return 0;
		}

		$tables       = self::table_names();
		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is only %s placeholders; values are spread.
		$this->check(
			$wpdb->query(
				$wpdb->prepare(
					"DELETE c FROM %i c INNER JOIN {$wpdb->posts} p ON p.ID = c.post_id WHERE p.post_type IN ($placeholders)",
					$tables['chunks'],
					...$post_types
				)
			)
		);

		$removed = $wpdb->query(
			$wpdb->prepare(
				"DELETE d FROM %i d INNER JOIN {$wpdb->posts} p ON p.ID = d.post_id WHERE p.post_type IN ($placeholders)",
				$tables['documents'],
				...$post_types
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$this->check( $removed );

		return (int) $removed;
	}

	/**
	 * Count indexed posts and chunks, and get the last indexing time.
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return array{posts: int, chunks: int, last_indexed: string|null} last_indexed is a GMT
	 *                                                                  MySQL datetime, or null if nothing is indexed.
	 */
	public function stats(): array {
		global $wpdb;

		$tables = self::table_names();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$last  = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(indexed_at) FROM %i', $tables['documents'] ) );
		$stats = array(
			'posts'        => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $tables['documents'] ) ),
			'chunks'       => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $tables['chunks'] ) ),
			'last_indexed' => null === $last ? null : (string) $last,
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		return $stats;
	}

	/**
	 * Keyword search: the best-matching chunk per post, by MySQL FULLTEXT relevance.
	 *
	 * Only chunks of currently published, non-password-protected posts are
	 * returned, so a post that changed status since it was indexed never
	 * shows up.
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param string $query Search text.
	 * @param int    $limit Maximum number of posts.
	 * @return array<int, array{chunk_id: int, score: float}> Keyed by post ID, best first.
	 */
	public function keyword_search( string $query, int $limit ): array {
		global $wpdb;

		$tables = self::table_names();

		// Top-scoring chunks; several can belong to one post, so read extra and keep
		// each post's best below. Window functions would need MySQL 8+, so this stays portable.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from table_names() / $wpdb.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.post_id, c.id AS chunk_id, MATCH(c.chunk_text) AGAINST (%s IN NATURAL LANGUAGE MODE) AS score
				FROM %i c
				INNER JOIN {$wpdb->posts} p ON p.ID = c.post_id
				WHERE MATCH(c.chunk_text) AGAINST (%s IN NATURAL LANGUAGE MODE)
					AND p.post_status = 'publish'
					AND p.post_password = ''
				ORDER BY score DESC, c.id ASC
				LIMIT %d",
				$query,
				$tables['chunks'],
				$query,
				$limit * self::KEYWORD_OVERFETCH
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$results = array();

		foreach ( (array) $rows as $row ) {
			$post_id = (int) $row['post_id'];

			if ( isset( $results[ $post_id ] ) ) {
				continue;
			}

			$results[ $post_id ] = array(
				'chunk_id' => (int) $row['chunk_id'],
				'score'    => (float) $row['score'],
			);

			if ( count( $results ) >= $limit ) {
				break;
			}
		}

		return $results;
	}

	/**
	 * Read a batch of embeddings for the vector scan, in chunk ID order.
	 *
	 * Only chunks of the given model and of published, non-password-protected
	 * posts are returned. Chunk text is not read here, to keep the scan light.
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param int    $after_id Only return chunks with an ID greater than this.
	 * @param int    $limit    Maximum number of rows.
	 * @param string $model    Embedding model name.
	 * @return array List of array{id: int, post_id: int, embedding: string}.
	 */
	public function embedding_batch( int $after_id, int $limit, string $model ): array {
		global $wpdb;

		$tables = self::table_names();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from table_names() / $wpdb.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id, c.post_id, c.embedding
				FROM %i c
				INNER JOIN {$wpdb->posts} p ON p.ID = c.post_id
				WHERE c.id > %d
					AND c.model_name = %s
					AND p.post_status = 'publish'
					AND p.post_password = ''
				ORDER BY c.id ASC
				LIMIT %d",
				$tables['chunks'],
				$after_id,
				$model,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map(
			fn( array $row ): array => array(
				'id'        => (int) $row['id'],
				'post_id'   => (int) $row['post_id'],
				'embedding' => (string) $row['embedding'],
			),
			(array) $rows
		);
	}

	/**
	 * Get the text of specific chunks.
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param int[] $chunk_ids Chunk IDs.
	 * @return array<int, string> Chunk text keyed by chunk ID.
	 */
	public function chunk_texts( array $chunk_ids ): array {
		global $wpdb;

		$chunk_ids = array_values( array_unique( array_filter( array_map( 'intval', $chunk_ids ) ) ) );

		if ( array() === $chunk_ids ) {
			return array();
		}

		$tables       = self::table_names();
		$placeholders = implode( ', ', array_fill( 0, count( $chunk_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is only %d placeholders; values are spread.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, chunk_text FROM %i WHERE id IN ($placeholders)", $tables['chunks'], ...$chunk_ids ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$texts = array();

		foreach ( (array) $rows as $row ) {
			$texts[ (int) $row['id'] ] = (string) $row['chunk_text'];
		}

		return $texts;
	}

	/**
	 * Throw if a $wpdb write failed.
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param int|bool $result Return value of a $wpdb write method (false on error).
	 * @return void
	 * @throws RuntimeException If the write failed.
	 */
	private function check( int|bool $result ): void {
		global $wpdb;

		if ( false === $result ) {
			throw new RuntimeException( 'Index database error: ' . $wpdb->last_error );
		}
	}
}
