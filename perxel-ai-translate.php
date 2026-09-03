<?php
/**
 * Plugin Name:       Perxel AI Translate
 * Plugin URI:        https://github.com/phucbm/perxel-ai-translate
 * Description:        Bulk-translate posts, pages and custom post types across WPML languages with an AI model of your choice via OpenRouter.
 * Version:           0.0.2
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Perxel
 * Author URI:        https://perxel.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       perxel-ai-translate
 * Domain Path:       /languages
 *
 * @package Perxel_AI_Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PXAT_VERSION', '0.0.2' );
define( 'PXAT_FILE', __FILE__ );
define( 'PXAT_DIR', __DIR__ );
define( 'PXAT_URL', untrailingslashit( plugin_dir_url( __FILE__ ) ) );
define( 'PXAT_OPTION_KEY', 'pxat_settings' );

/**
 * Human-readable product name. A brand name, deliberately not translated.
 */
define( 'PXAT_NAME', 'Perxel AI Translate' );

/**
 * Chat-completion models offered to the user, in dropdown order. Prices are USD
 * per 1M tokens, matching OpenRouter's own unit. Filterable via
 * `pxat_openrouter_models`, or define this constant to replace the list.
 */
if ( ! defined( 'PXAT_OPENROUTER_MODELS' ) ) {
	define(
		'PXAT_OPENROUTER_MODELS',
		array(
			array(
				'id'                => 'google/gemini-2.0-flash-001',
				'label'             => 'Gemini 2.0 Flash',
				'input'             => 0.10,
				'output'            => 0.40,
				'max_output_tokens' => 8192,
			),
		)
	);
}

/**
 * PSR-4-ish autoloader for Perxel\AITranslate\* -> includes/*.php.
 */
spl_autoload_register(
	static function ( $class_name ) {
		if ( strpos( $class_name, 'Perxel\\AITranslate\\' ) !== 0 ) {
			return;
		}

		$relative = substr( $class_name, strlen( 'Perxel\\AITranslate\\' ) );
		$path     = PXAT_DIR . '/includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

/**
 * Shared Perxel admin-UI kit. Standalone, versioned independently of this
 * plugin (github.com/perxel/wp-plugin-ui); vendored into vendor/perxel-ui/ via
 * bin/update-ui.sh. Overwriting it can never change plugin behaviour — the
 * loader keeps the highest registered version across active plugins and a
 * second copy is inert. We host the kit's component showcase as a hidden
 * maintainer-only screen, so suppress its own Tools page.
 */
define( 'PERXEL_UI_SHOWCASE_HOSTED', true );

if ( is_readable( PXAT_DIR . '/vendor/perxel-ui/loader.php' ) ) {
	require_once PXAT_DIR . '/vendor/perxel-ui/loader.php';
	Perxel_UI_Loader::register( '0.16.0', PXAT_DIR . '/vendor/perxel-ui', PXAT_URL . '/vendor/perxel-ui' );
}

register_activation_hook( __FILE__, array( 'Perxel\AITranslate\Plugin', 'activate' ) );

/**
 * WPML is a regular plugin, so it has not loaded yet on plugins_loaded's early
 * priority. The active check runs after WPML has had its chance to load.
 */
add_action( 'plugins_loaded', 'pxat_maybe_init' );

/**
 * Boot the plugin, or show a notice explaining why it stayed inactive.
 */
function pxat_maybe_init() {
	load_plugin_textdomain( 'perxel-ai-translate', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
		add_action( 'admin_notices', 'pxat_wpml_missing_notice' );
		return;
	}

	Perxel\AITranslate\Plugin::instance()->boot();
}

/**
 * Admin notice shown when WPML is not active.
 */
function pxat_wpml_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %s: plugin name. */
				__( '%s is inactive because WPML is not installed or activated.', 'perxel-ai-translate' ),
				PXAT_NAME
			)
		)
	);
}
