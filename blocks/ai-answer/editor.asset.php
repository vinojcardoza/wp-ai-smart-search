<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase -- register_block_type() looks for this exact name.
/**
 * Dependencies of the AI Answer block's editor script (read by register_block_type()).
 *
 * @package WP_AI_Smart_Search
 */

return array(
	'dependencies' => array( 'wp-block-editor', 'wp-blocks', 'wp-element', 'wp-i18n' ),
	'version'      => (string) filemtime( __DIR__ . '/editor.js' ),
);
