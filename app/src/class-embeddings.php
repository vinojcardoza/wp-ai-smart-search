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
	 * Uses the OpenAI-compatible embeddings format (OpenAI, OpenRouter, Ollama,
	 * LM Studio, ...): sends {"model": "...", "input": [...]} and reads
	 * {"data": [{"index": 0, "embedding": [...]}, ...]}, ordered by "index".
	 * A legacy {"embeddings": [[...], ...]} response is also accepted. The
	 * model is only sent when set, and the API key is sent as
	 * "Authorization: Bearer <key>" only when it is not empty.
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

		if ( $texts === array() ) {
			return array();
		}

		if ( $this->endpoint === '' ) {
			throw new Exception( 'Embedding endpoint is not set (option wpaismse_embedding_endpoint).' );
		}

		$headers = array( 'Content-Type' => 'application/json' );

		if ( $this->api_key !== '' ) {
			$headers['Authorization'] = 'Bearer ' . $this->api_key;
		}

		$body = array( 'input' => array_values( $texts ) );

		if ( $this->model !== '' ) {
			$body['model'] = $this->model;
		}

		$response = wp_remote_post(
			$this->endpoint,
			array(
				'timeout' => 90,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			throw new Exception( 'Embedding endpoint returned HTTP ' . $code . self::error_detail( $data ) );
		}

		$embeddings = self::extract_embeddings( $data );

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
			if ( ! is_array( $embedding ) || $embedding === array() ) {
				throw new Exception(
					sprintf( 'Invalid embedding response: embedding %d is not a non-empty array.', $index )
				);
			}
		}

		return $embeddings;
	}

	/**
	 * Pull the vectors out of a decoded embedding response.
	 *
	 * OpenAI-compatible responses ({"data": [{"index", "embedding"}]}) are
	 * sorted by "index" (falling back to array position); a legacy
	 * {"embeddings": [...]} list is returned as is.
	 *
	 * @param mixed $data Decoded JSON response body.
	 *
	 * @return array List of vectors in input order (not yet validated).
	 *
	 * @throws Exception If the response has neither format.
	 */
	private static function extract_embeddings( mixed $data ): array {

		if ( is_array( $data ) && isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$embeddings = array();

			foreach ( array_values( $data['data'] ) as $position => $item ) {
				if ( ! is_array( $item ) || ! isset( $item['embedding'] ) ) {
					throw new Exception(
						sprintf( 'Invalid embedding response: data item %d has no embedding.', $position )
					);
				}

				$index = isset( $item['index'] ) && is_int( $item['index'] ) ? $item['index'] : $position;

				if ( isset( $embeddings[ $index ] ) ) {
					throw new Exception(
						sprintf( 'Invalid embedding response: duplicate index %d.', $index )
					);
				}

				$embeddings[ $index ] = $item['embedding'];
			}

			ksort( $embeddings );

			return array_values( $embeddings );
		}

		if ( is_array( $data ) && isset( $data['embeddings'] ) && is_array( $data['embeddings'] ) ) {
			return array_values( $data['embeddings'] );
		}

		throw new Exception( 'Invalid embedding response: missing data array' . self::error_detail( $data ) );
	}

	/**
	 * Format the provider's error message, if the response carries one.
	 *
	 * Handles {"error": {"message": "..."}} (OpenAI, OpenRouter) and
	 * {"error": "..."}. Truncated to 200 characters.
	 *
	 * @param mixed $data Decoded JSON response body.
	 *
	 * @return string ": <message>", or an empty string.
	 */
	private static function error_detail( mixed $data ): string {

		if ( ! is_array( $data ) || ! isset( $data['error'] ) ) {
			return '';
		}

		$error   = $data['error'];
		$message = is_array( $error ) ? ( $error['message'] ?? '' ) : $error;

		if ( ! is_string( $message ) || $message === '' ) {
			return '';
		}

		return ': ' . mb_substr( $message, 0, 200 );
	}
}
