<?php
/**
 * Re-Index page.
 *
 * @package WP_AI_Smart_Search
 */

declare(strict_types=1);

namespace WP_AI_Smart_Search\Admin;

use WP_AI_Smart_Search\Embeddings;
use WP_AI_Smart_Search\Hooks\Reindexing;
use WP_AI_Smart_Search\Index_Repository;
use WP_AI_Smart_Search\Reindexer;

/**
 * AI Smart Search → Re-Index: start a re-index of all posts, follow its
 * progress, and see index statistics.
 *
 * While a job runs, assets/js/settings.js polls the AJAX step in
 * Hooks\Reindexing and swaps in the markup from status_html().
 *
 * @package WP_AI_Smart_Search
 */
class Reindex_Page {

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$model   = (string) get_option( Embeddings::MODEL_OPTION, '' );
		$stats   = ( new Index_Repository() )->stats();
		$job     = ( new Reindexer() )->status();
		$running = $job['status'] === 'running';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag set by our own redirect.
		$notice = isset( $_GET['wpaismse'] ) ? sanitize_key( wp_unslash( $_GET['wpaismse'] ) ) : '';

		Layout::open( Layout::REINDEX_PAGE, __( 'Re-Index', 'wpaismse' ) );
		?>
		<?php if ( $notice === 'started' ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Re-index started. You can leave this page; it continues in the background.', 'wpaismse' ); ?></p></div>
		<?php elseif ( $notice === 'no-endpoint' ) : ?>
			<div class="notice notice-error is-dismissible">
				<p>
					<?php esc_html_e( 'Set the embedding endpoint URL before re-indexing.', 'wpaismse' ); ?>
					<a href="<?php echo esc_url( Layout::url( Layout::SETTINGS_PAGE ) ); ?>"><?php esc_html_e( 'Go to Settings', 'wpaismse' ); ?></a>
				</p>
			</div>
		<?php endif; ?>

		<div class="wpaismse-grid">
			<div class="wpaismse-col">
				<section
					class="wpaismse-card"
					data-wpaismse-reindex
					data-status="<?php echo esc_attr( $job['status'] ); ?>"
					data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( Reindexing::NONCE ) ); ?>"
				>
					<div class="wpaismse-card__head">
						<span class="wpaismse-icon"><?php Layout::icon( 'refresh' ); ?></span>
						<div>
							<h2 class="wpaismse-card__title"><?php esc_html_e( 'Indexing', 'wpaismse' ); ?></h2>
							<p class="wpaismse-card__sub"><?php esc_html_e( 'Re-embed every published post and page. Runs in the background in batches.', 'wpaismse' ); ?></p>
						</div>
					</div>
					<div class="wpaismse-card__body">
						<div class="wpaismse-row">
							<div class="wpaismse-row__text wpaismse-reindex" data-wpaismse-reindex-status aria-live="polite">
								<?php echo self::status_html( $job ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside status_html(). ?>
							</div>
							<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
								<input type="hidden" name="action" value="wpaismse_reindex" />
								<?php wp_nonce_field( Reindexing::NONCE ); ?>
								<button
									type="submit"
									class="wpaismse-btn wpaismse-btn--primary"
									data-wpaismse-reindex-button
									data-idle-label="<?php esc_attr_e( 'Re-index all posts', 'wpaismse' ); ?>"
									<?php disabled( $running ); ?>
								><?php echo esc_html( $running ? __( 'Re-indexing…', 'wpaismse' ) : __( 'Re-index all posts', 'wpaismse' ) ); ?></button>
							</form>
						</div>
					</div>
				</section>
			</div>

			<aside class="wpaismse-col">
				<section class="wpaismse-card wpaismse-card--aside">
					<h2 class="wpaismse-card__title"><?php esc_html_e( 'Index', 'wpaismse' ); ?></h2>
					<dl class="wpaismse-stats">
						<div>
							<dt><?php esc_html_e( 'Posts indexed', 'wpaismse' ); ?></dt>
							<dd data-wpaismse-stat="posts"><?php echo esc_html( number_format_i18n( $stats['posts'] ) ); ?></dd>
						</div>
						<div>
							<dt><?php esc_html_e( 'Chunks', 'wpaismse' ); ?></dt>
							<dd data-wpaismse-stat="chunks"><?php echo esc_html( number_format_i18n( $stats['chunks'] ) ); ?></dd>
						</div>
						<div>
							<dt><?php esc_html_e( 'Last indexed', 'wpaismse' ); ?></dt>
							<dd data-wpaismse-stat="last_indexed"><?php echo esc_html( Layout::time_ago( $stats['last_indexed'] ) ); ?></dd>
						</div>
						<div>
							<dt><?php esc_html_e( 'Model', 'wpaismse' ); ?></dt>
							<dd><?php echo esc_html( $model !== '' ? $model : '—' ); ?></dd>
						</div>
						<div>
							<dt><?php esc_html_e( 'Dimensions', 'wpaismse' ); ?></dt>
							<dd><?php echo esc_html( (string) Embeddings::DIMENSIONS ); ?></dd>
						</div>
					</dl>
				</section>
			</aside>
		</div>
		<?php
		Layout::close();
	}

	/**
	 * Build the re-index status block: badge, headline, progress bar and counts.
	 *
	 * Used for the first render and returned by the AJAX step, so the text
	 * and translations live in one place.
	 *
	 * @param array $state Job state from Reindexer::status().
	 * @return string Escaped HTML.
	 */
	public static function status_html( array $state ): string {
		$total     = max( 0, (int) $state['total'] );
		$processed = max( 0, (int) $state['processed'] );
		$percent   = $total > 0 ? (int) min( 100, floor( $processed * 100 / $total ) ) : 0;

		switch ( $state['status'] ) {
			case 'running':
				$badge    = __( 'Running', 'wpaismse' );
				$headline = sprintf(
					/* translators: 1: posts processed, 2: total posts. */
					__( '%1$s of %2$s posts processed', 'wpaismse' ),
					number_format_i18n( $processed ),
					number_format_i18n( $total )
				);
				break;

			case 'done':
				$badge    = $state['failed'] > 0 ? __( 'Finished with errors', 'wpaismse' ) : __( 'Finished', 'wpaismse' );
				$percent  = 100;
				$headline = sprintf(
					/* translators: 1: posts processed, 2: time difference, e.g. "5 mins". */
					__( '%1$s posts processed %2$s ago', 'wpaismse' ),
					number_format_i18n( $processed ),
					human_time_diff( (int) $state['finished_at'] )
				);
				break;

			default:
				$badge    = __( 'Not run yet', 'wpaismse' );
				$headline = __( 'Posts are indexed automatically when saved. Re-index to process all existing content.', 'wpaismse' );
		}

		$counts = array(
			__( 'Indexed', 'wpaismse' ) => (int) $state['indexed'],
			__( 'Removed', 'wpaismse' ) => (int) $state['removed'],
			__( 'Failed', 'wpaismse' )  => (int) $state['failed'],
		);

		$html  = '<span class="wpaismse-badge wpaismse-badge--' . esc_attr( (string) $state['status'] ) . ( $state['failed'] > 0 ? ' wpaismse-badge--errors' : '' ) . '">' . esc_html( $badge ) . '</span>';
		$html .= '<p class="wpaismse-reindex__headline">' . esc_html( $headline ) . '</p>';

		if ( $state['status'] !== 'idle' ) {
			$html .= '<div class="wpaismse-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( (string) $percent ) . '">'
				. '<span style="width:' . esc_attr( (string) $percent ) . '%"></span></div>';

			$items = array();
			foreach ( $counts as $label => $count ) {
				$items[] = '<li><span>' . esc_html( $label ) . '</span> ' . esc_html( number_format_i18n( $count ) ) . '</li>';
			}
			$html .= '<ul class="wpaismse-counts">' . implode( '', $items ) . '</ul>';
		}

		if ( $state['last_error'] !== '' ) {
			$html .= '<p class="wpaismse-reindex__error">' . esc_html( $state['last_error'] ) . '</p>';
		}

		return $html;
	}
}
