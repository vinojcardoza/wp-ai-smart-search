<?php
/**
 * Chat model configuration and prompt.
 *
 * @package WP_AI_Smart_Search
 */

declare(strict_types=1);

namespace WP_AI_Smart_Search;

/**
 * Settings and prompt for the chat model that writes an answer from the
 * final search results.
 *
 * The chat endpoint is configured separately from the embedding endpoint
 * (it is usually a different service). Expected format: an
 * OpenAI-compatible Chat Completions endpoint, e.g.
 * https://api.openai.com/v1/chat/completions or a local Ollama / LM Studio
 * server.
 *
 * The prompt is a template with two placeholders:
 * - {query}   the visitor's search text
 * - {results} the final ranked search results, numbered [1], [2], …
 *
 * @package WP_AI_Smart_Search
 */
final class Chat {

	/**
	 * Option that stores the chat endpoint URL.
	 *
	 * @var string
	 */
	public const ENDPOINT_OPTION = 'wpaismse_chat_endpoint';

	/**
	 * Option that stores the chat API key.
	 *
	 * @var string
	 */
	public const API_KEY_OPTION = 'wpaismse_chat_api_key';

	/**
	 * Option that stores the chat model name.
	 *
	 * @var string
	 */
	public const MODEL_OPTION = 'wpaismse_chat_model';

	/**
	 * Option that stores the prompt template. Empty means DEFAULT_PROMPT.
	 *
	 * @var string
	 */
	public const PROMPT_OPTION = 'wpaismse_chat_prompt';

	/**
	 * Prompt used until a custom one is saved.
	 *
	 * @var string
	 */
	public const DEFAULT_PROMPT = "You are the search assistant for this website. Answer the visitor's question using only the search results below. Cite the results you use by their number, like [1]. If the results don't contain the answer, say so briefly instead of guessing.\n\nQuestion: {query}\n\nSearch results:\n{results}";

	/**
	 * Whether an endpoint and model are set, so answers can be generated.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return self::option( self::ENDPOINT_OPTION ) !== '' && self::option( self::MODEL_OPTION ) !== '';
	}

	/**
	 * Get the prompt template: the saved one, or the default.
	 *
	 * @return string
	 */
	public static function prompt(): string {
		$prompt = self::option( self::PROMPT_OPTION );

		return $prompt !== '' ? $prompt : self::DEFAULT_PROMPT;
	}

	/**
	 * Fill the prompt template with a query and search results.
	 *
	 * @param string $query    Visitor's search text.
	 * @param array  $results  {
	 *     Final ranked results, best first.
	 *
	 *     @type array ...$0 {
	 *         @type string $title   Post title.
	 *         @type string $url     Permalink.
	 *         @type string $excerpt Matching text or excerpt.
	 *     }
	 * }
	 * @param string $template Template to fill. Defaults to prompt().
	 * @return string
	 */
	public static function render_prompt( string $query, array $results, ?string $template = null ): string {
		return strtr(
			$template ?? self::prompt(),
			array(
				'{query}'   => $query,
				'{results}' => self::format_results( $results ),
			)
		);
	}

	/**
	 * Format results as a numbered list for the prompt.
	 *
	 * Example:
	 *
	 *     [1] Post title
	 *     https://example.com/post/
	 *     Matching text…
	 *
	 * @param array $results Results as described in render_prompt().
	 * @return string
	 */
	public static function format_results( array $results ): string {
		$blocks = array();

		foreach ( array_values( $results ) as $index => $result ) {
			$lines = array_filter(
				array(
					sprintf( '[%d] %s', $index + 1, trim( (string) ( $result['title'] ?? '' ) ) ),
					trim( (string) ( $result['url'] ?? '' ) ),
					trim( (string) ( $result['excerpt'] ?? '' ) ),
				),
				fn( string $line ): bool => $line !== ''
			);

			$blocks[] = implode( "\n", $lines );
		}

		return implode( "\n\n", $blocks );
	}

	/**
	 * Read a string option, trimmed.
	 *
	 * @param string $name Option name.
	 * @return string
	 */
	private static function option( string $name ): string {
		return trim( (string) get_option( $name, '' ) );
	}
}
