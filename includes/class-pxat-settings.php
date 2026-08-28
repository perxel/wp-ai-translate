<?php
/**
 * Settings screen (Settings → Perxel AI Translate).
 *
 * @package Perxel_AI_Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin's options page: API key, extra prompt, and display preferences.
 */
class PXAT_Settings {

	const PAGE_SLUG = 'pxat-settings';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_pxat_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_pxat_test_api_key', array( __CLASS__, 'ajax_test_api_key' ) );
	}

	/**
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		unset( $hook );

		// admin.css also styles this plugin's menu item, which is visible on
		// every admin screen, so it always loads; the settings-page script
		// below still only runs on the settings page itself.
		wp_enqueue_style( 'pxat-admin', PXAT_URL . '/assets/css/admin.css', array(), PXAT_VERSION );

		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen switch.
			return;
		}

		wp_enqueue_script( 'pxat-settings', PXAT_URL . '/assets/js/settings.js', array( 'wp-i18n' ), PXAT_VERSION, true );
		wp_set_script_translations( 'pxat-settings', 'perxel-ai-translate', PXAT_DIR . '/languages' );
		wp_localize_script(
			'pxat-settings',
			'PXAT_Settings',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'pxat_test_api_key' ),
			)
		);
	}

	/**
	 * AJAX: validate the entered API key against OpenRouter.
	 */
	public static function ajax_test_api_key() {
		check_ajax_referer( 'pxat_test_api_key', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'perxel-ai-translate' ) ), 403 );
		}

		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		$result  = PXAT_OpenRouter::test_api_key( $api_key );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'API key is valid.', 'perxel-ai-translate' ),
				'label'   => isset( $result['label'] ) ? $result['label'] : '',
				'usage'   => isset( $result['usage'] ) ? $result['usage'] : null,
				'limit'   => isset( $result['limit'] ) ? $result['limit'] : null,
			)
		);
	}

	/**
	 * Register the options page.
	 */
	public static function register_menu() {
		add_options_page(
			PXAT_NAME,
			PXAT_NAME,
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * @return array Saved settings merged over defaults.
	 */
	public static function get_settings() {
		$defaults = array(
			'api_key'      => '',
			'prompt'       => '',
			'display_unit' => 'tokens',
		);

		$saved = get_option( PXAT_OPTION_KEY, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	/**
	 * Persist the submitted settings.
	 */
	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'perxel-ai-translate' ) );
		}
		check_admin_referer( 'pxat_save_settings' );

		$settings = array(
			'api_key'      => isset( $_POST['pxat_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['pxat_api_key'] ) ) : '',
			'prompt'       => isset( $_POST['pxat_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['pxat_prompt'] ) ) : '',
			'display_unit' => isset( $_POST['pxat_display_unit'] ) && 'words' === $_POST['pxat_display_unit'] ? 'words' : 'tokens',
		);
		update_option( PXAT_OPTION_KEY, $settings );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'updated' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Render the options page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = self::get_settings();
		$updated  = isset( $_GET['updated'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash flag set by our own redirect.
		?>
		<div class="wrap pxat-wrap" style="max-width:900px">
			<h1><?php echo esc_html( PXAT_NAME ); ?></h1>

			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'perxel-ai-translate' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pxat_save_settings" />
				<?php wp_nonce_field( 'pxat_save_settings' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pxat_api_key"><?php esc_html_e( 'OpenRouter API key', 'perxel-ai-translate' ); ?></label></th>
						<td>
							<input type="password" id="pxat_api_key" name="pxat_api_key" value="<?php echo esc_attr( $settings['api_key'] ); ?>" class="regular-text" autocomplete="off" />
							<button type="button" class="button" id="pxat-test-api-key"><?php esc_html_e( 'Test', 'perxel-ai-translate' ); ?></button>
							<p><span id="pxat-test-api-key-result" class="pxat-test-result"></span></p>
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to openrouter.ai. */
									esc_html__( 'Create an account and an API key at %s.', 'perxel-ai-translate' ),
									'<a target="_blank" rel="noopener noreferrer" href="https://openrouter.ai/">openrouter.ai</a>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'System prompt', 'perxel-ai-translate' ); ?></th>
						<td>
							<div class="pxat-chat-message">
								<div class="pxat-chat-message__header">
									<span class="pxat-chat-message__role"><?php esc_html_e( 'System', 'perxel-ai-translate' ); ?></span>
									<button type="button" class="button button-small" id="pxat-copy-system-prompt"><?php esc_html_e( 'Copy', 'perxel-ai-translate' ); ?></button>
								</div>
								<pre class="pxat-chat-message__body" id="pxat_system_prompt_preview"><?php echo esc_html( PXAT_OpenRouter::build_system_prompt( $settings['prompt'], '{source_lang}', '{dest_lang}' ) ); ?></pre>
							</div>
							<p><span id="pxat-copy-system-prompt-result" class="pxat-test-result"></span></p>
							<p class="description"><?php esc_html_e( 'This is the system prompt the plugin sends to the AI. You can copy it to translate manually with any AI chat tool if your API key stops working. Replace {source_lang} and {dest_lang} with the real language codes (for example en, fr) before using it.', 'perxel-ai-translate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pxat_prompt"><?php esc_html_e( 'Extra instructions', 'perxel-ai-translate' ); ?></label></th>
						<td>
							<textarea id="pxat_prompt" name="pxat_prompt" rows="3" class="large-text code"><?php echo esc_textarea( $settings['prompt'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Optional guidance appended to every request: glossary, tone of voice, terminology rules, and so on.', 'perxel-ai-translate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Model', 'perxel-ai-translate' ); ?></th>
						<td>
							<?php foreach ( PXAT_OpenRouter::get_models() as $model ) : ?>
								<p style="margin:0 0 4px;">
									<code><?php echo esc_html( $model['label'] ); ?></code>
									&mdash;
									<?php
									printf(
										/* translators: 1: input price, 2: output price, both USD per 1M tokens. */
										esc_html__( '$%1$s input / $%2$s output per 1M tokens', 'perxel-ai-translate' ),
										esc_html( $model['input'] ),
										esc_html( $model['output'] )
									);
									?>
								</p>
							<?php endforeach; ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to openrouter.ai/models. */
									esc_html__( 'Models are configured in code and can be changed with the %s filter.', 'perxel-ai-translate' ),
									'<code>pxat_openrouter_models</code>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pxat_display_unit"><?php esc_html_e( 'Display unit', 'perxel-ai-translate' ); ?></label></th>
						<td>
							<select id="pxat_display_unit" name="pxat_display_unit">
								<option value="tokens" <?php selected( $settings['display_unit'], 'tokens' ); ?>><?php esc_html_e( 'Tokens', 'perxel-ai-translate' ); ?></option>
								<option value="words" <?php selected( $settings['display_unit'], 'words' ); ?>><?php esc_html_e( 'Words (estimated)', 'perxel-ai-translate' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'How translation volume is shown on the Confirm and Progress screens: by real token count, or by an estimated word count (~0.75 words per token).', 'perxel-ai-translate' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'perxel-ai-translate' ) ); ?>
			</form>

			<?php
			$footer_exclude = self::PAGE_SLUG;
			include PXAT_DIR . '/views/footer.php';
			?>
		</div>
		<?php
	}
}
