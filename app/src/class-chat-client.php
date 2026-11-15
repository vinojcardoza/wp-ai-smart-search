<?php
/**
 * Chat model client.
 *
 * @package WP_AI_Smart_Search
 */

declare(strict_types=1);

namespace WP_AI_Smart_Search;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are logged, never printed as HTML.

use Exception;

/**
 * Sends the answer prompt to an OpenAI-compatible Chat Completions endpoint.
 *
 * Request:  POST {"model": "…", "messages": [{"role": "user", "content": "<prompt>"}]}
 * Response: {"choices": [{"message": {"content": "…"}}]}
 *
 * Only model and messages are sent, so the request works across providers
 * (some reject parameters such as temperature for certain models).
 * Reasoning models that put "<think>…</think>" in the reply (e.g. Qwen3 via
 * Ollama) have that part removed.
 *
 * @package WP_AI_Smart_Search
 */
class Chat_Client {

	/**
	 * Request timeout in seconds. Local models can be slow.
	 *
	 * @var int
	 */
	public const TIMEOUT = 120;

	/**
	 * Maximum characters of an error response included in exception messages.
	 *
	 * @var int
	 */
	private const ERROR_LENGTH = 200;

	/**
	 * Endpoint URL.
	 *
	 * @var string
	 */
	private string $endpoint;

	/**
	 * API key. Empty means no Authorization header.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Model name.
	 *
	 * @var string
	 */
	private string $model;

	/**
	 * Constructor. Loads the settings from the database.
	 */
	public function __construct() {
		$this->endpoint = trim( (string) get_option( Chat::ENDPOINT_OPTION, '' ) );
		$this->api_key  = trim( (string) get_option( Chat::API_KEY_OPTION, '' ) );
		$this->model    = trim( (string) get_option( Chat::MODEL_OPTION, '' ) );
	}

	/**
	 * Get the model name (part of the answer cache key).
	 *
	 * @return string
	 */
	public function model(): string {
		return $this->model;
	}

	/**
	 * Ask the chat model to answer a query from search results.
	 *
	 * @param string $query   Visitor's search text.
	 * @param array  $results Search results, as passed to Chat::render_prompt().
	 * @return string The answer, plain text as returned by the model.
	 * @throws Exception If the chat model is not configured, the request fails,
	 *                   or the response has no answer.
	 */
	public function answer( string $query, array $results ): string {
		if ( $this->endpoint === '' || $this->model === '' ) {
			throw new Exception( 'Chat model is not configured (endpoint and model are required).' );
		}

		$headers = array( 'Content-Type' => 'application/json' );

		if ( $this->api_key !== '' ) {
			$headers['Authorization'] = 'Bearer ' . $this->api_key;
		}

		$response = wp_remote_post(
			$this->endpoint,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => $headers,
				'body'    => wp_json_encode(
					array(
						'model'    => $this->model,
						'messages' => array(
							array(
								'role'    => 'user',
								'content' => Chat::render_prompt( $query, $results ),
							),
						),
						'stream'   => false,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'Chat request failed: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			// OpenAI-style errors keep the text in error.message; some servers send a plain error string.
			$detail = is_array( $data ) ? ( $data['error']['message'] ?? $data['error'] ?? '' ) : '';
			$detail = is_string( $detail ) ? $detail : '';

			throw new Exception( trim( sprintf( 'Chat endpoint returned HTTP %d. %s', $code, mb_substr( $detail, 0, self::ERROR_LENGTH ) ) ) );
		}

		$content = is_array( $data ) ? ( $data['choices'][0]['message']['content'] ?? null ) : null;

		if ( ! is_string( $content ) ) {
			throw new Exception( 'Invalid chat response: no choices[0].message.content.' );
		}

		$answer = self::clean( $content );

		if ( $answer === '' ) {
			throw new Exception( 'The chat model returned an empty answer.' );
		}

		return $answer;
	}

	/**
	 * Remove reasoning blocks and surrounding whitespace from a reply.
	 *
	 * @param string $content Raw reply.
	 * @return string
	 */
	public static function clean( string $content ): string {
		// Complete <think>…</think> blocks, then an unclosed one at the start.
		$content = (string) preg_replace( '#<think>.*?</think>#si', '', $content );
		$content = (string) preg_replace( '#^\s*<think>.*#si', '', $content );

		return trim( $content );
	}
}
