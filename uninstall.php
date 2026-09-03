<?php
/**
 * Uninstall cleanup. Runs only when the plugin is deleted from the Plugins
 * screen. Removes the settings option, the custom tables, per-user translation
 * carts and any leftover cart transients. Translations already written into
 * posts are left alone.
 *
 * @package Perxel_AI_Translate
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'pxat_settings' );
delete_option( 'pxat_db_version' );

require_once __DIR__ . '/includes/Db.php';
Perxel\AITranslate\Db::uninstall();

global $wpdb;

// Per-user translation carts.
delete_metadata( 'user', 0, 'pxat_cart', '', true );

// Short-lived cart transients.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_pxat\_cart\_conflict\_%' OR option_name LIKE '\_transient\_timeout\_pxat\_cart\_conflict\_%'"
);
