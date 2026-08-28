<?php
/**
 * Plugin bootstrap.
 *
 * @package Perxel_AI_Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires up every admin-side component and owns the log-directory lifecycle.
 */
class PXAT_Plugin {

	/**
	 * Runs on plugins_loaded, once WPML is confirmed active.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'ensure_log_dir' ) );

		PXAT_Settings::init();
		PXAT_Bulk_Action::init();
		PXAT_Admin_Bar::init();
		PXAT_Confirm_Page::init();
		PXAT_Progress_Page::init();
		PXAT_History_Page::init();
		PXAT_ID_Lookup_Page::init();
	}

	/**
	 * Activation hook: create the log directory up front so the first run
	 * never races to make it.
	 */
	public static function activate() {
		self::ensure_log_dir();
	}

	/**
	 * Create the batch log directory and drop in guard files that keep its
	 * contents from being listed or served directly.
	 */
	public static function ensure_log_dir() {
		if ( ! file_exists( PXAT_LOG_DIR ) ) {
			wp_mkdir_p( PXAT_LOG_DIR );
		}

		$index = PXAT_LOG_DIR . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- one-time guard file in the uploads dir.
		}

		$htaccess = PXAT_LOG_DIR . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- one-time guard file in the uploads dir.
		}
	}
}
