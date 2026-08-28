<?php
/**
 * Uninstall cleanup.
 *
 * Removes the plugin's option and its batch log directory. Runs only when the
 * user deletes the plugin from the Plugins screen. Translations already written
 * into posts are left untouched.
 *
 * @package Perxel_AI_Translate
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'pxat_settings' );

// Selection transients are short-lived; clear any that are still around.
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_pxat\_sel\_%' OR option_name LIKE '\_transient\_timeout\_pxat\_sel\_%'"
);

/**
 * Delete the batch log directory and everything in it.
 *
 * @param string $dir Absolute path.
 */
function pxat_uninstall_rmdir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $items as $path ) {
		if ( $path->isDir() ) {
			@rmdir( $path->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilentErrors, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		} else {
			@unlink( $path->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilentErrors, WordPress.WP.AlternativeFunctions.file_system_operations_unlink
		}
	}

	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilentErrors, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

$uploads = wp_upload_dir( null, false );
if ( ! empty( $uploads['basedir'] ) ) {
	pxat_uninstall_rmdir( trailingslashit( $uploads['basedir'] ) . 'perxel-ai-translate' );
}
pxat_uninstall_rmdir( WP_CONTENT_DIR . '/uploads/perxel-ai-translate' );
