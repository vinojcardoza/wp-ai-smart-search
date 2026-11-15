<?php
/**
 * Re-index hooks.
 *
 * @package WP_AI_Smart_Search
 */

declare(strict_types=1);

namespace WP_AI_Smart_Search\Hooks;

use WP_AI_Smart_Search\Admin\Layout;
use WP_AI_Smart_Search\Admin\Reindex_Page;
use WP_AI_Smart_Search\Embeddings;
use WP_AI_Smart_Search\Index_Repository;
use WP_AI_Smart_Search\Reindexer;

/**
 * Starts and advances the "Re-index all posts" job.
 *
 * - admin-post.php?action=wpaismse_reindex: starts a job (button on the Re-Index page).
 * - admin-ajax.php?action=wpaismse_reindex_step: runs one batch and returns progress
 *   (polled by the Re-Index page while it is open).
 * - WP-Cron wpaismse_reindex_batch: runs batches in the background until done.
 *
 * @package WP_AI_Smart_Search
 */
class Reindexing {

	/**
	 * Nonce action shared by the start form and the AJAX step.
	 *
	 * @var string
	 */
	public const NONCE = 'wpaismse_reindex';

	/**
	 * Register the hooks for this class.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_wpaismse_reindex', array( $this, 'start' ) );
		add_action( 'wp_ajax_wpaismse_reindex_step', array( $this, 'step' ) );
		add_action( Reindexer::CRON_HOOK, array( $this, 'cron_batch' ) );
	}

	/**
	 * Start a re-index and return to the Re-Index page.
	 *
	 * @return void
	 */
	public function start(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wpaismse' ), 403 );
		}

		check_admin_referer( self::NONCE );

		$result = 'started';

		if ( trim( (string) get_option( Embeddings::ENDPOINT_OPTION, '' ) ) === '' ) {
			$result = 'no-endpoint';
		} else {
			( new Reindexer() )->start();
		}

		wp_safe_redirect( Layout::url( Layout::REINDEX_PAGE, array( 'wpaismse' => $result ) ) );
		exit;
	}

	/**
	 * AJAX: run one batch and return the progress markup and index stats.
	 *
	 * @return void
	 */
	public function step(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}

		$state = ( new Reindexer() )->run_batch();
		$stats = ( new Index_Repository() )->stats();

		wp_send_json_success(
			array(
				'status' => $state['status'],
				'html'   => Reindex_Page::status_html( $state ),
				'stats'  => array(
					'posts'        => number_format_i18n( $stats['posts'] ),
					'chunks'       => number_format_i18n( $stats['chunks'] ),
					'last_indexed' => Layout::time_ago( $stats['last_indexed'] ),
				),
			)
		);
	}

	/**
	 * WP-Cron: run one batch and schedule the next until the job is done.
	 *
	 * @return void
	 */
	public function cron_batch(): void {
		$reindexer = new Reindexer();
		$state     = $reindexer->run_batch();

		if ( $state['status'] === 'running' ) {
			$reindexer->schedule_next();
		}
	}
}
