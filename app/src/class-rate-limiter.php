<?php
/**
 * Request rate limiter.
 *
 * @package WP_AI_Smart_Search
 */

declare(strict_types=1);

namespace WP_AI_Smart_Search;

/**
 * Fixed-window rate limit per client IP, stored in transients.
 *
 * Uses REMOTE_ADDR only: forwarded-for headers can be set by anyone. Sites
 * behind a proxy or CDN can supply the real client IP with the
 * wpaismse_client_ip filter. The IP is hashed before being used as a key.
 *
 * @package WP_AI_Smart_Search
 */
class Rate_Limiter {

	/**
	 * Transient prefix.
	 *
	 * @var string
	 */
	private const PREFIX = 'wpaismse_rl_';

	/**
	 * Record a hit and report whether the client is within the limit.
	 *
	 * @param string $bucket  Name of the limit, e.g. "search" or "answer".
	 * @param int    $max     Requests allowed per window.
	 * @param int    $seconds Window length in seconds.
	 * @return bool True if allowed, false if the limit is exceeded.
	 */
	public function hit( string $bucket, int $max, int $seconds ): bool {
		$window = (int) floor( time() / $seconds );
		$key    = self::PREFIX . $bucket . '_' . substr( md5( self::client_ip() . '|' . $window ), 0, 16 );
		$count  = (int) get_transient( $key );

		if ( $count >= $max ) {
			return false;
		}

		set_transient( $key, $count + 1, $seconds );

		return true;
	}

	/**
	 * Get the client IP used for rate limiting.
	 *
	 * @return string
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the client IP used for rate limiting (e.g. to trust a proxy header).
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		return (string) apply_filters( 'wpaismse_client_ip', $ip );
	}
}
