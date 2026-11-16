<?php
/**
 * Shared admin page layout.
 *
 * @package WP_AI_Smart_Search
 */

declare(strict_types=1);

namespace WP_AI_Smart_Search\Admin;

use WP_AI_Smart_Search\Embeddings;

/**
 * Top bar, tab navigation and helpers shared by the plugin's admin pages.
 *
 * Usage in a page's render method:
 *
 *     Layout::open( Layout::SETTINGS_PAGE, __( 'Settings', 'wpaismse' ) );
 *     // … page content …
 *     Layout::close();
 *
 * @package WP_AI_Smart_Search
 */
final class Layout {

	/**
	 * Slug of the Settings page.
	 *
	 * @var string
	 */
	public const SETTINGS_PAGE = 'wpaismse-settings';

	/**
	 * Slug of the top-level menu and the Search page.
	 *
	 * @var string
	 */
	public const SEARCH_PAGE = 'wpaismse-search';

	/**
	 * Slug of the Chat page.
	 *
	 * @var string
	 */
	public const CHAT_PAGE = 'wpaismse-chat';

	/**
	 * Slug of the Post Types page.
	 *
	 * @var string
	 */
	public const POST_TYPES_PAGE = 'wpaismse-post-types';

	/**
	 * Slug of the Re-Index page.
	 *
	 * @var string
	 */
	public const REINDEX_PAGE = 'wpaismse-reindex';

	/**
	 * Get the tabs shown in the top bar, in order.
	 *
	 * @return array<string, string> Page slug => label.
	 */
	public static function tabs(): array {
		return array(
			self::SEARCH_PAGE     => __( 'Search', 'wpaismse' ),
			self::SETTINGS_PAGE   => __( 'Settings', 'wpaismse' ),
			self::CHAT_PAGE       => __( 'Chat', 'wpaismse' ),
			self::POST_TYPES_PAGE => __( 'Post Types', 'wpaismse' ),
			self::REINDEX_PAGE    => __( 'Re-Index', 'wpaismse' ),
		);
	}

	/**
	 * Get the admin URL of a plugin page.
	 *
	 * @param string $page Page slug.
	 * @param array  $args Extra query arguments.
	 * @return string
	 */
	public static function url( string $page, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Print the opening markup: top bar with tabs, then the page title.
	 *
	 * @param string $active Slug of the current page (highlighted tab).
	 * @param string $title  Page title.
	 * @return void
	 */
	public static function open( string $active, string $title ): void {
		$endpoint = (string) get_option( Embeddings::ENDPOINT_OPTION, '' );
		$version  = get_file_data( WP_AI_SMART_SEARCH_PLUGIN_FILE, array( 'Version' => 'Version' ) )['Version'];
		?>
		<div class="wpaismse">
			<header class="wpaismse-bar">
				<div class="wpaismse-brand">
					<span class="wpaismse-logo"><?php self::icon( 'search' ); ?></span>
					<?php esc_html_e( 'AI Smart Search', 'wpaismse' ); ?>
				</div>
				<nav class="wpaismse-tabs" aria-label="<?php esc_attr_e( 'AI Smart Search', 'wpaismse' ); ?>">
					<?php foreach ( self::tabs() as $slug => $label ) : ?>
						<a
							class="wpaismse-tab<?php echo $slug === $active ? ' is-active' : ''; ?>"
							href="<?php echo esc_url( self::url( $slug ) ); ?>"
							<?php echo $slug === $active ? 'aria-current="page"' : ''; ?>
						><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</nav>
				<div class="wpaismse-bar__end">
					<?php if ( $endpoint !== '' ) : ?>
						<span class="wpaismse-status wpaismse-status--ok"><?php esc_html_e( 'Embedding service configured', 'wpaismse' ); ?></span>
					<?php else : ?>
						<span class="wpaismse-status"><?php esc_html_e( 'Embedding endpoint not set', 'wpaismse' ); ?></span>
					<?php endif; ?>
					<?php if ( $version !== '' ) : ?>
						<span class="wpaismse-tag">v<?php echo esc_html( $version ); ?></span>
					<?php endif; ?>
				</div>
			</header>

			<div class="wpaismse-main">
				<hr class="wp-header-end">
				<h1 class="wpaismse-title"><?php echo esc_html( $title ); ?></h1>
		<?php
	}

	/**
	 * Print the closing markup for open().
	 *
	 * @return void
	 */
	public static function close(): void {
		?>
			</div>
		</div>
		<?php
	}

	/**
	 * Print an inline SVG icon (stroke uses currentColor).
	 *
	 * @param string $name Icon name: search, plug, refresh, layers, chat or text.
	 * @return void
	 */
	public static function icon( string $name ): void {
		$paths = array(
			'search'  => '<circle cx="7" cy="7" r="4.5"/><path d="m10.5 10.5 3.5 3.5"/>',
			'plug'    => '<path d="M6 1.5v3M10 1.5v3M4 4.5h8v3a4 4 0 0 1-8 0zM8 11.5v3"/>',
			'refresh' => '<path d="M13.5 8A5.5 5.5 0 1 1 11.9 4.1M13.5 2v3.5H10"/>',
			'chat'    => '<path d="M2.5 3.5h11v7.5H7l-3 2.5v-2.5H2.5z"/>',
			'text'    => '<path d="M3 4h10M3 8h10M3 12h6"/>',
			'layers'  => '<path d="m8 1.5 6.5 3.5L8 8.5 1.5 5z"/><path d="m1.5 8 6.5 3.5L14.5 8M1.5 11l6.5 3.5 6.5-3.5"/>',
		);

		if ( ! isset( $paths[ $name ] ) ) {
			return;
		}

		printf(
			'<svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%s</svg>',
			$paths[ $name ] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup from the array above.
		);
	}

	/**
	 * Format a GMT MySQL datetime as "5 mins ago".
	 *
	 * @param string|null $gmt_datetime GMT datetime, or null.
	 * @return string "—" when null.
	 */
	public static function time_ago( ?string $gmt_datetime ): string {
		if ( $gmt_datetime === null ) {
			return '—';
		}

		$timestamp = strtotime( $gmt_datetime . ' UTC' );

		if ( $timestamp === false ) {
			return '—';
		}

		/* translators: %s: human-readable time difference, e.g. "5 mins". */
		return sprintf( __( '%s ago', 'wpaismse' ), human_time_diff( $timestamp ) );
	}
}
