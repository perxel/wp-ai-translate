<?php
/**
 * Plugin Name:       Perxel AI Translate
 * Plugin URI:        https://github.com/phucbm/perxel-ai-translate
 * Description:        Bulk-translate posts, pages and custom post types across WPML languages with an AI model of your choice via OpenRouter.
 * Version:           0.0.1
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

define( 'PXAT_VERSION', '0.0.1' );
define( 'PXAT_FILE', __FILE__ );
define( 'PXAT_DIR', __DIR__ );
define( 'PXAT_URL', untrailingslashit( plugin_dir_url( __FILE__ ) ) );
define( 'PXAT_OPTION_KEY', 'pxat_settings' );

/**
 * Where batch job/log files are written — a subdirectory of wp-content/uploads,
 * the only location a plugin can reliably write to across hosts. Override with
 * a define() before this file loads if your uploads directory is elsewhere.
 */
if ( ! defined( 'PXAT_LOG_DIR' ) ) {
	define( 'PXAT_LOG_DIR', WP_CONTENT_DIR . '/uploads/perxel-ai-translate/logs' );
}

/**
 * Human-readable product name. A brand name, deliberately not translated.
 */
define( 'PXAT_NAME', 'Perxel AI Translate' );

/**
 * Chat-completion models offered to the user, in the order they appear in the
 * model dropdown. Prices are USD per 1M tokens, matching OpenRouter's own unit.
 * Filterable so a site can add or replace models without editing the plugin.
 *
 * @see PXAT_OpenRouter::get_models()
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
				// Per OpenRouter's model listing. Caps how many posts can be
				// grouped into one "Auto (batched)" request, since the model's
				// completion length is the binding constraint, not its context
				// window. See PXAT_OpenRouter::get_batch_output_budget().
				'max_output_tokens' => 8192,
			),
		)
	);
}

require_once PXAT_DIR . '/includes/class-pxat-wpml.php';
require_once PXAT_DIR . '/includes/class-pxat-post-types.php';
require_once PXAT_DIR . '/includes/class-pxat-fields.php';
require_once PXAT_DIR . '/includes/class-pxat-batch.php';
require_once PXAT_DIR . '/includes/class-pxat-post-sync.php';
require_once PXAT_DIR . '/includes/class-pxat-openrouter.php';
require_once PXAT_DIR . '/includes/class-pxat-settings.php';
require_once PXAT_DIR . '/includes/class-pxat-format.php';
require_once PXAT_DIR . '/includes/class-pxat-job-processor.php';
require_once PXAT_DIR . '/includes/class-pxat-bulk-action.php';
require_once PXAT_DIR . '/includes/class-pxat-admin-bar.php';
require_once PXAT_DIR . '/includes/class-pxat-confirm-page.php';
require_once PXAT_DIR . '/includes/class-pxat-progress-page.php';
require_once PXAT_DIR . '/includes/class-pxat-history-page.php';
require_once PXAT_DIR . '/includes/class-pxat-id-lookup-page.php';
require_once PXAT_DIR . '/includes/class-pxat-plugin.php';

register_activation_hook( __FILE__, array( 'PXAT_Plugin', 'activate' ) );

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

	PXAT_Plugin::init();
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
