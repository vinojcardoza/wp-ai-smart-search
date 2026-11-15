<?php
/**
 * Plugin activation routine.
 *
 * @package WP_AI_Smart_Search
 */

declare(strict_types=1);

namespace WP_AI_Smart_Search\Hooks;

use WP_AI_Smart_Search\Index_Repository;

/**
 * Creates the plugin's custom database tables on activation.
 *
 * Two tables are created:
 *
 * - {prefix}ai_documents: one row per indexed post, with a hash of its
 *   content so unchanged posts can be skipped on re-index.
 * - {prefix}ai_chunks: one row per text chunk of a post, holding the
 *   chunk text (FULLTEXT indexed) and its INT8 quantized embedding.
 *
 * Tables are created through dbDelta(), so activation is safe to run
 * repeatedly and applies schema changes to existing tables. The tables are
 * dropped in uninstall.php when the plugin is deleted.
 *
 * @package WP_AI_Smart_Search
 */
final class Initializer {

	/**
	 * Current database schema version. Bump when the schema changes.
	 *
	 * @var string
	 */
	public const DB_VERSION = '1.0.0';

	/**
	 * Option name that stores the installed schema version.
	 *
	 * @var string
	 */
	public const DB_VERSION_OPTION = 'wp_ai_smart_search_db_version';

	/**
	 * Register the hooks for this class.
	 *
	 * The activation hook is tied to the main plugin file, so this must run
	 * while that file is loading (Hooks_Manager::register() does).
	 *
	 * @return void
	 */
	public function register(): void {
		register_activation_hook( WP_AI_SMART_SEARCH_PLUGIN_FILE, array( $this, 'activate' ) );
	}

	/**
	 * Run on plugin activation.
	 *
	 * @return void
	 */
	public function activate(): void {
		self::create_tables();
	}

	/**
	 * Create or update the plugin's custom tables.
	 *
	 * Note: dbDelta() is strict about formatting. Each column must be on its
	 * own line and PRIMARY KEY must be followed by two spaces.
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		global $wpdb;

		$tables          = Index_Repository::table_names();
		$charset_collate = $wpdb->get_charset_collate();

		$documents_sql = "CREATE TABLE {$tables['documents']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                post_id bigint(20) unsigned NOT NULL,
                content_hash char(64) NOT NULL,
                indexed_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY post_id (post_id)
            ) ENGINE=InnoDB $charset_collate;";

		$chunks_sql = "CREATE TABLE {$tables['chunks']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                post_id bigint(20) unsigned NOT NULL,
                chunk_index int(10) unsigned NOT NULL,
                chunk_text mediumtext NOT NULL,
                embedding blob NOT NULL,
                embedding_scale float NOT NULL,
                model_name varchar(100) NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY post_chunk (post_id,chunk_index),
                KEY post_id (post_id),
                KEY model_name (model_name),
                FULLTEXT KEY ft_chunk (chunk_text)
            ) ENGINE=InnoDB $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( array( $documents_sql, $chunks_sql ) );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}
}
