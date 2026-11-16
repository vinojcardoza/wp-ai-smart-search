<?php
/**
 * Bulk re-indexer.
 *
 * @package WP_AI_Smart_Search
 */

declare(strict_types=1);

namespace WP_AI_Smart_Search;

use Throwable;

/**
 * Re-indexes every indexable post in small batches.
 *
 * The job's progress is kept in the wpaismse_reindex option, so it survives
 * between requests. Batches are run by whoever gets there first: the
 * settings page (AJAX, while it is open) or WP-Cron (in the background).
 * A short-lived lock stops two requests from processing the same batch.
 *
 * Posts are walked in ID order using the last processed ID as a cursor, so
 * posts published during a run are still picked up and none are repeated.
 *
 * @package WP_AI_Smart_Search
 */
class Reindexer {

	/**
	 * Option that stores the job state.
	 *
	 * @var string
	 */
	public const STATE_OPTION = 'wpaismse_reindex';

	/**
	 * Option used as a lock while a batch runs. Its value is the lock's expiry time.
	 *
	 * @var string
	 */
	public const LOCK_OPTION = 'wpaismse_reindex_lock';

	/**
	 * WP-Cron hook that runs one batch in the background.
	 *
	 * @var string
	 */
	public const CRON_HOOK = 'wpaismse_reindex_batch';

	/**
	 * Posts processed per batch. Each post can make one or more embedding requests.
	 *
	 * @var int
	 */
	public const BATCH_SIZE = 5;

	/**
	 * Seconds after which a lock is considered stale (e.g. the request died).
	 *
	 * @var int
	 */
	private const LOCK_TTL = 120;

	/**
	 * Post indexer.
	 *
	 * @var Indexer
	 */
	private Indexer $indexer;

	/**
	 * Constructor.
	 *
	 * @param Indexer|null $indexer Indexer. Defaults to a new Indexer.
	 */
	public function __construct( ?Indexer $indexer = null ) {
		$this->indexer = $indexer ?? new Indexer();
	}

	/**
	 * Get the job state.
	 *
	 * @return array{
	 *     status: string,
	 *     total: int,
	 *     processed: int,
	 *     indexed: int,
	 *     unchanged: int,
	 *     removed: int,
	 *     failed: int,
	 *     last_id: int,
	 *     last_error: string,
	 *     started_at: int,
	 *     finished_at: int
	 * } status is 'idle' (never run), 'running' or 'done'. Times are Unix timestamps (0 = not set).
	 */
	public function status(): array {
		$defaults = array(
			'status'      => 'idle',
			'total'       => 0,
			'processed'   => 0,
			'indexed'     => 0,
			'unchanged'   => 0,
			'removed'     => 0,
			'failed'      => 0,
			'last_id'     => 0,
			'last_error'  => '',
			'started_at'  => 0,
			'finished_at' => 0,
		);

		$state = get_option( self::STATE_OPTION, array() );

		return array_merge( $defaults, is_array( $state ) ? array_intersect_key( $state, $defaults ) : array() );
	}

	/**
	 * Whether a job is in progress.
	 *
	 * @return bool
	 */
	public function is_running(): bool {
		return $this->status()['status'] === 'running';
	}

	/**
	 * Start a new re-index of all indexable posts and schedule its first batch.
	 *
	 * Does nothing if a job is already running.
	 *
	 * @return array The job state (see status()).
	 */
	public function start(): array {
		if ( $this->is_running() ) {
			return $this->status();
		}

		$state = array(
			'status'      => 'running',
			'total'       => $this->count_posts(),
			'processed'   => 0,
			'indexed'     => 0,
			'unchanged'   => 0,
			'removed'     => 0,
			'failed'      => 0,
			'last_id'     => 0,
			'last_error'  => '',
			'started_at'  => time(),
			'finished_at' => 0,
		);

		update_option( self::STATE_OPTION, $state, false );

		$this->schedule_next();

		return $state;
	}

	/**
	 * Process the next batch of posts.
	 *
	 * Safe to call at any time: returns the current state unchanged if no job
	 * is running or another request holds the lock. Errors on single posts
	 * are counted and the job moves on.
	 *
	 * @return array The job state after the batch (see status()).
	 */
	public function run_batch(): array {
		if ( ! $this->is_running() || ! $this->lock() ) {
			return $this->status();
		}

		try {
			// Re-read from the database after taking the lock: another request may have just
			// finished a batch, and get_option() would otherwise return this request's cached copy.
			wp_cache_delete( self::STATE_OPTION, 'options' );
			$state = $this->status();

			if ( $state['status'] !== 'running' ) {
				return $state;
			}

			$ids = $this->next_ids( $state['last_id'], self::BATCH_SIZE );

			foreach ( $ids as $post_id ) {
				try {
					$result = $this->indexer->index_post( $post_id, true );
					++$state[ $result ];
				} catch ( Throwable $e ) {
					++$state['failed'];
					$state['last_error'] = sprintf( 'Post %d: %s', $post_id, $e->getMessage() );
				}

				++$state['processed'];
				$state['last_id'] = $post_id;
			}

			if ( count( $ids ) < self::BATCH_SIZE ) {
				$state['status']      = 'done';
				$state['finished_at'] = time();
				// Posts published during the run make processed > the initial total.
				$state['total'] = max( $state['total'], $state['processed'] );
			}

			update_option( self::STATE_OPTION, $state, false );

			return $state;
		} finally {
			$this->unlock();
		}
	}

	/**
	 * Schedule the next background batch, unless one is already scheduled.
	 *
	 * @return void
	 */
	public function schedule_next(): void {
		if ( wp_next_scheduled( self::CRON_HOOK ) === false ) {
			wp_schedule_single_event( time(), self::CRON_HOOK );
		}
	}

	/**
	 * Count the posts a re-index will process.
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return int
	 */
	private function count_posts(): int {
		global $wpdb;

		[ $types_sql, $types ] = $this->post_type_placeholders();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $types_sql is only %s placeholders; values are spread.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_password = '' AND post_type IN ($types_sql) AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = {$wpdb->posts}.ID AND pm.meta_key = %s AND pm.meta_value = '1' )", ...array_merge( $types, array( Indexer::EXCLUDE_META ) ) ) );
	}

	/**
	 * Get the next batch of indexable post IDs after a cursor.
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param int $after_id Only return IDs greater than this.
	 * @param int $limit    Maximum number of IDs.
	 * @return int[]
	 */
	private function next_ids( int $after_id, int $limit ): array {
		global $wpdb;

		[ $types_sql, $types ] = $this->post_type_placeholders();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $types_sql is only %s placeholders; values are spread.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_password = '' AND post_type IN ($types_sql) AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = {$wpdb->posts}.ID AND pm.meta_key = %s AND pm.meta_value = '1' ) AND ID > %d ORDER BY ID ASC LIMIT %d", ...array_merge( $types, array( Indexer::EXCLUDE_META, $after_id, $limit ) ) ) );

		return array_map( 'intval', $ids );
	}

	/**
	 * Build "%s, %s, …" placeholders for the indexable post types.
	 *
	 * @return array{0: string, 1: string[]} Placeholder SQL and the post type values.
	 */
	private function post_type_placeholders(): array {
		$types = array_values( array_map( 'strval', Indexer::post_types() ) );

		if ( $types === array() ) {
			// No indexable types: match nothing.
			$types = array( '' );
		}

		return array( implode( ', ', array_fill( 0, count( $types ), '%s' ) ), $types );
	}

	/**
	 * Take the batch lock.
	 *
	 * Uses add_option(), which fails if the option exists, so taking it is atomic.
	 * A lock older than LOCK_TTL is treated as abandoned and replaced.
	 *
	 * @return bool Whether the lock was taken.
	 */
	private function lock(): bool {
		if ( add_option( self::LOCK_OPTION, time() + self::LOCK_TTL, '', false ) ) {
			return true;
		}

		wp_cache_delete( self::LOCK_OPTION, 'options' );

		if ( (int) get_option( self::LOCK_OPTION, 0 ) < time() ) {
			delete_option( self::LOCK_OPTION );
			return add_option( self::LOCK_OPTION, time() + self::LOCK_TTL, '', false );
		}

		return false;
	}

	/**
	 * Release the batch lock.
	 *
	 * @return void
	 */
	private function unlock(): void {
		delete_option( self::LOCK_OPTION );
	}
}
