<?php
/**
 * Embedding service client.
 *
 * @package WP_AI_Smart_Search
 */

declare(strict_types=1);

namespace WP_AI_Smart_Search;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are logged, never printed as HTML.

use Exception;

/**
 * Client for the external embedding service.
 *
 * The endpoint, API key and model name are read from the options
 * wpaismse_embedding_endpoint, wpaismse_embedding_api_key and
 * wpaismse_embedding_model.
 *
 * @package WP_AI_Smart_Search
 */
class Embeddings {

	/**
	 * Number of dimensions in each embedding vector.
	 *
	 * @var int
	 */
	public const DIMENSIONS = 384;

	/**
	 * Option that stores the endpoint URL.
	 *
	 * @var string
	 */
	public const ENDPOINT_OPTION = 'wpaismse_embedding_endpoint';

	/**
	 * Option that stores the API key.
	 *
	 * @var string
	 */
	public const API_KEY_OPTION = 'wpaismse_embedding_api_key';

	/**
	 * Option that stores the model name.
	 *
	 * @var string
	 */
	public const MODEL_OPTION = 'wpaismse_embedding_model';

	/**
	 * Embedding endpoint URL.
	 *
	 * @var string
	 */
	private string $endpoint;

	/**
	 * API key sent as a Bearer token. Empty means no Authorization header.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Name of the model served by the endpoint.
	 *
	 * @var string
	 */
	private string $model;

	/**
	 * Constructor. Loads the settings from the database.
	 */
	public function __construct() {
		$this->endpoint = trim( (string) get_option( self::ENDPOINT_OPTION, '' ) );
		$this->api_key  = trim( (string) get_option( self::API_KEY_OPTION, '' ) );
		$this->model    = trim( (string) get_option( self::MODEL_OPTION, '' ) );
	}

	/**
	 * Get the name of the embedding model.
	 *
	 * Stored with every chunk and part of each post's content hash, so
	 * changing it re-embeds every post on its next index run.
	 *
	 * @return string
	 */
	public function model(): string {
		return $this->model;
	}

	/**
	 * Generate vector embeddings for a batch of texts via the embedding API.
	 *
	 * Sends the texts as a JSON payload ({"texts": [...]}) to the endpoint.
	 * The API key is sent as "Authorization: Bearer <key>" only when it is
	 * not empty.
	 *
	 * @param string[] $texts List of texts to embed.
	 *
	 * @return array[] List of embedding vectors (arrays of floats), one per input
	 *                 text, in the same order as $texts.
	 *
	 * @throws Exception If the endpoint is not set, the request fails, the endpoint
	 *                   returns a non-2xx status code, or the response is not a list
	 *                   of one non-empty vector per input text.
	 */
	public function generate_embeddings( array $texts ): array {

		if ( array() === $texts ) {
			return array();
		}

		if ( '' === $this->endpoint ) {
			throw new Exception( 'Embedding endpoint is not set (option wpaismse_embedding_endpoint).' );
		}

		$headers = array( 'Content-Type' => 'application/json' );

		if ( '' !== $this->api_key ) {
			$headers['Authorization'] = 'Bearer ' . $this->api_key;
		}

		$response = wp_remote_post(
			$this->endpoint,
			array(
				'timeout' => 90,
				'headers' => $headers,
				'body'    => wp_json_encode( array( 'texts' => array_values( $texts ) ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			throw new Exception( 'Embedding endpoint returned HTTP ' . $code );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || ! isset( $data['embeddings'] ) || ! is_array( $data['embeddings'] ) ) {
			throw new Exception( 'Invalid embedding response: missing embeddings array.' );
		}

		$embeddings = array_values( $data['embeddings'] );

		if ( count( $embeddings ) !== count( $texts ) ) {
			throw new Exception(
				sprintf(
					'Invalid embedding response: expected %d embeddings, got %d.',
					count( $texts ),
					count( $embeddings )
				)
			);
		}

		foreach ( $embeddings as $index => $embedding ) {
			if ( ! is_array( $embedding ) || array() === $embedding ) {
				throw new Exception(
					sprintf( 'Invalid embedding response: embedding %d is not a non-empty array.', $index )
				);
			}
		}

		return $embeddings;
	}
}
