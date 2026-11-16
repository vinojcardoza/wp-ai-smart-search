<?php
/**
 * REST API.
 *
 * @package WP_AI_Smart_Search
 */

declare(strict_types=1);

namespace WP_AI_Smart_Search\Hooks;

use Throwable;
use WP_AI_Smart_Search\Answer_Formatter;
use WP_AI_Smart_Search\Chat;
use WP_AI_Smart_Search\Chat_Client;
use WP_AI_Smart_Search\Rate_Limiter;
use WP_AI_Smart_Search\Search_Service;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Public search and AI answer endpoints.
 *
 * - GET  /wp-json/wpaismse/v1/search?q=…&limit=10  Hybrid search results.
 * - POST /wp-json/wpaismse/v1/answer   {"q": "…"}  AI answer (text and safe HTML) from the top results.
 *
 * They are separate so a front end can show results immediately and fetch
 * the (slower, paid) answer afterwards. Both are rate limited per IP and
 * cached for Search_Service::CACHE_TTL seconds.
 *
 * @package WP_AI_Smart_Search
 */
class Rest_Api {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	public const NAMESPACE = 'wpaismse/v1';

	/**
	 * Results sent to the chat model.
	 *
	 * @var int
	 */
	public const ANSWER_RESULTS = 5;

	/**
	 * Search requests allowed per IP per minute.
	 *
	 * @var int
	 */
	public const SEARCH_PER_MINUTE = 30;

	/**
	 * Answer requests allowed per IP per minute.
	 *
	 * @var int
	 */
	public const ANSWER_PER_MINUTE = 10;

	/**
	 * Register the hooks for this class.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$query_arg = array(
			'description'       => __( 'Search text.', 'wpaismse' ),
			'type'              => 'string',
			'required'          => true,
			'sanitize_callback' => array( Search_Service::class, 'normalize_query' ),
			'validate_callback' => array( $this, 'validate_query' ),
		);

		register_rest_route(
			self::NAMESPACE,
			'/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'q'     => $query_arg,
					'limit' => array(
						'description' => __( 'Maximum number of results.', 'wpaismse' ),
						'type'        => 'integer',
						'default'     => 10,
						'minimum'     => 1,
						'maximum'     => 20,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/answer',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'answer' ),
				'permission_callback' => '__return_true',
				'args'                => array( 'q' => $query_arg ),
			)
		);
	}

	/**
	 * Validate the query length.
	 *
	 * @param mixed $value Raw query.
	 * @return true|WP_Error
	 */
	public function validate_query( $value ) {
		if ( ! Search_Service::is_valid_query( Search_Service::normalize_query( $value ) ) ) {
			return new WP_Error(
				'wpaismse_query_too_short',
				/* translators: %d: minimum number of characters. */
				sprintf( __( 'Enter at least %d characters.', 'wpaismse' ), Search_Service::MIN_QUERY ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * GET /search: hybrid search results.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function search( WP_REST_Request $request ) {
		if ( ! ( new Rate_Limiter() )->hit( 'search', self::SEARCH_PER_MINUTE, MINUTE_IN_SECONDS ) ) {
			return $this->too_many_requests();
		}

		$query  = (string) $request['q'];
		$search = Search_Service::search( $query, (int) $request['limit'] );

		return new WP_REST_Response(
			array(
				'query'        => $query,
				'results'      => array_map( array( $this, 'public_result' ), $search['results'] ),
				'keyword_only' => $search['keyword_only'],
				'cached'       => $search['cached'],
			)
		);
	}

	/**
	 * POST /answer: AI answer from the top search results.
	 *
	 * Returns answer = null with reason "no_results" when nothing matches, so
	 * the chat model is never asked to answer without sources.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function answer( WP_REST_Request $request ) {
		if ( ! Chat::is_configured() ) {
			return new WP_Error( 'wpaismse_chat_not_configured', __( 'AI answers are not available.', 'wpaismse' ), array( 'status' => 503 ) );
		}

		if ( ! ( new Rate_Limiter() )->hit( 'answer', self::ANSWER_PER_MINUTE, MINUTE_IN_SECONDS ) ) {
			return $this->too_many_requests();
		}

		$query   = (string) $request['q'];
		$results = Search_Service::search( $query, self::ANSWER_RESULTS )['results'];
		$sources = array();

		foreach ( $results as $index => $result ) {
			$sources[] = array(
				'n'     => $index + 1,
				'id'    => $result['id'],
				'title' => $result['title'],
				'url'   => $result['url'],
			);
		}

		if ( $results === array() ) {
			return new WP_REST_Response(
				array(
					'query'       => $query,
					'answer'      => null,
					'answer_html' => null,
					'reason'      => 'no_results',
					'sources'     => array(),
					'cached'      => false,
				)
			);
		}

		$client = new Chat_Client();
		$key    = 'wpaismse_a_' . md5( (string) wp_json_encode( array( mb_strtolower( $query ), wp_list_pluck( $results, 'id' ), Chat::prompt(), $client->model() ) ) );
		$answer = get_transient( $key );
		$cached = is_string( $answer );

		if ( ! $cached ) {
			// The chat model gets the full matching chunk, not the shortened excerpt.
			$context = array_map(
				fn( array $result ): array => array(
					'title'   => $result['title'],
					'url'     => $result['url'],
					'excerpt' => $result['content'],
				),
				$results
			);

			try {
				$answer = $client->answer( $query, $context );
			} catch ( Throwable $e ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'WP AI Smart Search: answer failed: ' . $e->getMessage() );

				// Visitors get a generic message; details stay in the log.
				return new WP_Error( 'wpaismse_answer_failed', __( 'The AI answer could not be generated. Please try again later.', 'wpaismse' ), array( 'status' => 502 ) );
			}

			set_transient( $key, $answer, Search_Service::CACHE_TTL );
		}

		return new WP_REST_Response(
			array(
				'query'       => $query,
				'answer'      => $answer,
				'answer_html' => Answer_Formatter::to_html( $answer, $sources ),
				'reason'      => null,
				'sources'     => $sources,
				'cached'      => $cached,
			)
		);
	}

	/**
	 * Remove internal fields from a result before returning it.
	 *
	 * @param array $result Searcher result.
	 * @return array
	 */
	private function public_result( array $result ): array {
		unset( $result['content'] );

		return $result;
	}

	/**
	 * Build a 429 response.
	 *
	 * @return WP_Error
	 */
	private function too_many_requests(): WP_Error {
		return new WP_Error( 'wpaismse_rate_limited', __( 'Too many requests. Please wait a moment and try again.', 'wpaismse' ), array( 'status' => 429 ) );
	}
}
