<?php
/**
 * AI Search uninstall routine.
 *
 * Runs automatically when the plugin is deleted from the WordPress admin
 * (Plugins → Delete). Removes all data created by the plugin: the index
 * tables, the schema version option and any pending indexing cron events.
 *
 * Note: this file is NOT executed on deactivation, only on deletion.
 *
 * @package AI_Search
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$tables = array(
	$wpdb->prefix . 'ai_chunks',
	$wpdb->prefix . 'ai_documents',
);

foreach ( $tables as $table_name ) {
	$wpdb->query( "DROP TABLE IF EXISTS $table_name" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
}

delete_option( 'wp_ai_smart_search_db_version' );
delete_option( 'wpaismse_embedding_endpoint' );
delete_option( 'wpaismse_embedding_api_key' );
delete_option( 'wpaismse_embedding_model' );
delete_option( 'wpaismse_post_types' );
delete_option( 'wpaismse_replace_search' );
delete_option( 'wpaismse_auto_answer' );
delete_option( 'wpaismse_chat_endpoint' );
delete_option( 'wpaismse_chat_api_key' );
delete_option( 'wpaismse_chat_model' );
delete_option( 'wpaismse_chat_prompt' );

delete_option( 'wpaismse_reindex' );
delete_option( 'wpaismse_reindex_lock' );

delete_post_meta_by_key( '_wpaismse_exclude' );

// Cached search results, answers and rate-limit counters.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_wpaismse\_%' OR option_name LIKE '\_transient\_timeout\_wpaismse\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

wp_unschedule_hook( 'wpaismse_index_post' );
wp_unschedule_hook( 'wpaismse_reindex_batch' );
