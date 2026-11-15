<?php
/**
 * Post indexer.
 *
 * @package WP_AI_Smart_Search
 */

declare(strict_types=1);

namespace WP_AI_Smart_Search;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are logged, never printed as HTML.

use RuntimeException;
use WP_Post;

/**
 * Builds the search index for a single post.
 *
 * Pipeline: check the post is indexable → build plain text → skip if its hash
 * is unchanged → split into chunks → embed in batches → quantize → replace the
 * post's rows in one transaction.
 *
 * All embedding requests finish before the database transaction starts, so
 * a slow or failing embedding service never holds the transaction open and
 * the old index stays searchable until the new one is ready.
 *
 * @package WP_AI_Smart_Search
 */
class Indexer {

	/**
	 * Option that stores the enabled post types (array of names).
	 *
	 * @var string
	 */
	public const POST_TYPES_OPTION = 'wpaismse_post_types';

	/**
	 * Post types enabled when the option has never been saved.
	 *
	 * @var string[]
	 */
	public const DEFAULT_POST_TYPES = array( 'post', 'page' );

	/**
	 * Post meta key that excludes a single post from indexing when true.
	 *
	 * Set from the post editor (Hooks\Post_Exclusion).
	 *
	 * @var string
	 */
	public const EXCLUDE_META = '_wpaismse_exclude';

	/**
	 * Number of chunks sent to the embedding service per request.
	 *
	 * @var int
	 */
	public const BATCH_SIZE = 16;

	/**
	 * Embedding service client.
	 *
	 * @var Embeddings
	 */
	private Embeddings $embeddings;

	/**
	 * Index tables repository.
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
	 * Index a post, or remove it from the index if it should not be searchable.
	 *
	 * Posts that are not published, are password protected, or are not an
	 * indexable post type are removed from the index, so unpublishing a post
	 * also removes it from search.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $force   Re-embed even if the content hash is unchanged.
	 * @return string What happened: 'indexed', 'unchanged' or 'removed'.
	 * @throws RuntimeException If the embedding service or database fails. The old index is kept.
	 */
	public function index_post( int $post_id, bool $force = false ): string {

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! self::is_indexable( $post ) ) {
			$this->repository->delete_post( $post_id );
			return 'removed';
		}

		$text  = self::build_text( $post );
		$model = $this->embeddings->model();

		// Including the model means switching models re-embeds every post.
		$hash = hash( 'sha256', $model . "\n" . $text );

		if ( ! $force && $this->repository->get_content_hash( $post_id ) === $hash ) {
			return 'unchanged';
		}

		$chunks = $this->embed_chunks( Chunker::split( $text ) );

		$this->repository->replace_post( $post_id, $hash, $model, $chunks );

		return 'indexed';
	}

	/**
	 * Whether a post belongs in the search index.
	 *
	 * @param WP_Post $post Post object.
	 * @return bool
	 */
	public static function is_indexable( WP_Post $post ): bool {
		return $post->post_status === 'publish'
			&& $post->post_password === ''
			&& in_array( $post->post_type, self::post_types(), true )
			&& ! self::is_excluded( $post->ID );
	}

	/**
	 * Whether a post was excluded from indexing in the editor.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_excluded( int $post_id ): bool {
		return (bool) get_post_meta( $post_id, self::EXCLUDE_META, true );
	}

	/**
	 * Post types that are indexed: the ones enabled on the Post Types page.
	 *
	 * Defaults to post and page until that page is saved. An empty array
	 * (every type switched off) means nothing is indexed.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		$types = get_option( self::POST_TYPES_OPTION, self::DEFAULT_POST_TYPES );
		$types = is_array( $types ) ? array_values( array_map( 'strval', $types ) ) : self::DEFAULT_POST_TYPES;

		/**
		 * Filters the post types indexed for AI search.
		 *
		 * @param string[] $post_types Post type names enabled on the Post Types page.
		 */
		return (array) apply_filters( 'wpaismse_indexable_post_types', $types );
	}

	/**
	 * Build the plain text that gets chunked and embedded.
	 *
	 * The title comes first, so it is part of the first chunk and matched by
	 * keyword search. Shortcodes, HTML tags and comments (including block
	 * markup) are removed and HTML entities decoded.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	public static function build_text( WP_Post $post ): string {
		$text = $post->post_title . "\n" . strip_shortcodes( $post->post_content );
		$text = wp_strip_all_tags( $text );

		return trim( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Embed and quantize chunks, BATCH_SIZE chunks per request.
	 *
	 * @param string[] $texts Chunk texts in order.
	 * @return array List of array{text: string, blob: string, scale: float}, in the same order.
	 * @throws RuntimeException If the embedding service fails or returns an invalid vector.
	 */
	private function embed_chunks( array $texts ): array {

		$chunks = array();

		foreach ( array_chunk( $texts, self::BATCH_SIZE ) as $batch ) {
			try {
				$vectors = $this->embeddings->generate_embeddings( $batch );
			} catch ( \Exception $e ) {
				throw new RuntimeException( 'Embedding failed: ' . $e->getMessage(), 0, $e );
			}

			// $index is the position within this request; Index_Repository numbers chunks across the post.
			foreach ( $batch as $index => $text ) {
				try {
					$quantized = Quantizer::quantize( $vectors[ $index ], Embeddings::DIMENSIONS );
				} catch ( \InvalidArgumentException $e ) {
					throw new RuntimeException( 'Invalid embedding: ' . $e->getMessage(), 0, $e );
				}

				$chunks[] = array(
					'text'  => $text,
					'blob'  => $quantized['blob'],
					'scale' => $quantized['scale'],
				);
			}
		}

		return $chunks;
	}
}
