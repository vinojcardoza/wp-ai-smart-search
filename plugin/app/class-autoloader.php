<?php

declare(strict_types=1);

namespace WP_AI_Smart_Search;

/**
 * Autoloads the plugin's classes.
 *
 * Classes in the WP_AI_Smart_Search namespace are loaded from app/src/,
 * following the WordPress class-{name}.php file naming convention.
 *
 * @package WP_AI_Smart_Search
 */
final class Autoloader {

	/**
	 * Register the plugin autoloader with the SPL autoload stack.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Load the file for a class in the plugin namespace.
	 *
	 * Maps a class such as WP_AI_Smart_Search\Admin\Settings_Page to
	 * app/src/admin/class-settings-page.php. Classes outside the plugin
	 * namespace are ignored.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function autoload( $class_name ): void {
		$prefix = 'WP_AI_Smart_Search\\';
		$len    = strlen( $prefix );

		if ( strncmp( $prefix, $class_name, $len ) !== 0 ) {
			return;
		}

		// Get relative class name.
		$relative_class = substr( $class_name, $len );

		$base_dir = __DIR__ . '/src/';

		// Break the namespaced class path into parts.
		$path_parts = explode( '\\', $relative_class );

		// Take the actual class name (the last element).
		$class_file = array_pop( $path_parts );

		// Convert ClassName to class-name.php style.
		$file_name = 'class-' . strtolower( str_replace( '_', '-', $class_file ) ) . '.php';

		// Reconstruct the directory path, making subdirectories lowercase/hyphenated if desired.
		$subdirectory = '';
		if ( ! empty( $path_parts ) ) {
			$subdirectory = strtolower( implode( '/', $path_parts ) ) . '/';
		}

		$file = $base_dir . $subdirectory . $file_name;

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}
