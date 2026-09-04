<?php
/**
 * Uninstall cleanup. Runs only when the plugin is deleted from the Plugins
 * screen. Removes the settings option and the custom tables. Translations
 * already written into posts are left alone.
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
