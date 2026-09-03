<?php
/**
 * Settings screen.
 *
 * @package Perxel_AI_Translate
 *
 * @var array $settings    Settings::all().
 * @var array $model       Settings::model().
 * @var array $environment Admin::environment().
 * @var bool  $updated     Whether the form just saved.
 * @var bool  $was_reset   Whether settings were just reset.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Perxel\AITranslate\OpenRouter;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Perxel_UI escapes structure; dynamic values escaped inline.

if ( $updated ) {
	echo \Perxel_UI::notice( 'success', esc_html__( 'Settings saved.', 'perxel-ai-translate' ), array( 'dismissible' => true ) );
} elseif ( $was_reset ) {
	echo \Perxel_UI::notice( 'success', esc_html__( 'Settings reset to defaults.', 'perxel-ai-translate' ), array( 'dismissible' => true ) );
}

$system_prompt = OpenRouter::build_system_prompt( $settings['prompt'], '{source_lang}', '{dest_lang}' );

$price = static function ( $n ) {
	return rtrim( rtrim( number_format( (float) $n, 4 ), '0' ), '.' );
};

$model_detail = $model['input'] > 0
	? esc_html(
		sprintf(
			/* translators: 1: input price, 2: output price. */
			__( '$%1$s in / $%2$s out per 1M tokens', 'perxel-ai-translate' ),
			$price( $model['input'] ),
			$price( $model['output'] )
		)
	)
	: esc_html__( 'Run Test to fetch pricing.', 'perxel-ai-translate' );
?>
<form id="pxat-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="pxat_save_settings" />
	<?php wp_nonce_field( 'pxat_save_settings' ); ?>

	<input type="hidden" name="api_key_verified" id="pxat-api-key-verified" value="<?php echo $settings['api_key_verified'] ? '1' : ''; ?>" />
	<input type="hidden" name="model_verified" id="pxat-model-verified" value="<?php echo $settings['model_verified'] ? '1' : ''; ?>" />
	<input type="hidden" name="model_label" id="pxat-model-label" value="<?php echo esc_attr( $settings['model_label'] ); ?>" />
	<input type="hidden" name="model_input" id="pxat-model-input" value="<?php echo esc_attr( $settings['model_input'] ); ?>" />
	<input type="hidden" name="model_output" id="pxat-model-output" value="<?php echo esc_attr( $settings['model_output'] ); ?>" />
	<input type="hidden" name="model_context" id="pxat-model-context" value="<?php echo esc_attr( $settings['model_context'] ); ?>" />
	<input type="hidden" name="model_max_output" id="pxat-model-max-output" value="<?php echo esc_attr( $settings['model_max_output'] ); ?>" />

	<?php
	echo \Perxel_UI::rows(
		array(
			array(
				'title' => __( 'Connection', 'perxel-ai-translate' ),
				'note'  => sprintf(
					/* translators: %s: link to openrouter.ai. */
					esc_html__( 'Create an account and an API key at %s. You pay OpenRouter directly for usage.', 'perxel-ai-translate' ),
					'<a href="https://openrouter.ai/" target="_blank" rel="noopener noreferrer">openrouter.ai</a>'
				),
				'rows'  => array(
					array(
						'label'   => __( 'OpenRouter API key', 'perxel-ai-translate' ),
						'stacked' => true,
						'sub'     => $settings['api_key_verified']
							? '<span class="pxat-inline-ok">' . esc_html__( 'Verified', 'perxel-ai-translate' ) . '</span>'
							: esc_html__( 'Not verified yet - press Test.', 'perxel-ai-translate' ),
						'content' => '<input type="password" id="pxat-api-key" name="api_key" autocomplete="off" value="'
							. esc_attr( $settings['api_key'] ) . '" /> '
							. '<button type="button" class="button" id="pxat-test-key">' . esc_html__( 'Test', 'perxel-ai-translate' ) . '</button>'
							. ' <span id="pxat-key-result" class="pxat-test-result"></span>',
					),
				),
			),
			array(
				'title' => __( 'Model', 'perxel-ai-translate' ),
				'note'  => '<a href="https://openrouter.ai/models" target="_blank" rel="noopener noreferrer">'
					. esc_html__( 'Browse models on openrouter.ai', 'perxel-ai-translate' ) . '</a> - '
					. esc_html__( 'paste any model id. Test checks it exists and fetches its pricing.', 'perxel-ai-translate' ),
				'rows'  => array(
					array(
						'label'   => __( 'Model id', 'perxel-ai-translate' ),
						'stacked' => true,
						'sub'     => '<span id="pxat-model-detail">' . $model_detail . '</span>',
						'content' => '<input type="text" class="pxui-mono" id="pxat-model-id" name="model_id" value="'
							. esc_attr( $settings['model_id'] ) . '" placeholder="' . esc_attr( PXAT_DEFAULT_MODEL ) . '" /> '
							. '<button type="button" class="button" id="pxat-test-model">' . esc_html__( 'Test model', 'perxel-ai-translate' ) . '</button>'
							. ' <span id="pxat-model-result" class="pxat-test-result"></span>',
					),
				),
			),
			array(
				'title' => __( 'Translation', 'perxel-ai-translate' ),
				'rows'  => array(
					array(
						'label'   => __( 'Extra instructions', 'perxel-ai-translate' ),
						'stacked' => true,
						'sub'     => esc_html__( 'Optional guidance appended to every request: glossary, tone of voice, terminology rules.', 'perxel-ai-translate' ),
						'content' => '<textarea name="prompt" rows="3">' . esc_textarea( $settings['prompt'] ) . '</textarea>',
					),
					array(
						'label'   => __( 'System prompt sent to the model', 'perxel-ai-translate' ),
						'stacked' => true,
						'sub'     => esc_html__( 'Read-only. Copy it to translate manually with any AI chat tool if your key stops working - replace {source_lang} / {dest_lang} with real codes.', 'perxel-ai-translate' ),
						'content' => '<textarea class="pxui-mono" rows="7" readonly onclick="this.select()">' . esc_textarea( $system_prompt ) . '</textarea>',
					),
				),
			),
			array(
				'title' => __( 'Display', 'perxel-ai-translate' ),
				'rows'  => array(
					array(
						'label'   => __( 'Volume unit', 'perxel-ai-translate' ),
						'sub'     => esc_html__( 'How translation volume is shown: real token count, or an estimated word count.', 'perxel-ai-translate' ),
						'content' => '<select name="display_unit">'
							. '<option value="tokens"' . selected( $settings['display_unit'], 'tokens', false ) . '>' . esc_html__( 'Tokens', 'perxel-ai-translate' ) . '</option>'
							. '<option value="words"' . selected( $settings['display_unit'], 'words', false ) . '>' . esc_html__( 'Words (estimated)', 'perxel-ai-translate' ) . '</option>'
							. '</select>',
					),
				),
			),
		)
	);
	?>
</form>

<?php
/* --- Environment ------------------------------------------------------ */

$env      = $environment;
$env_ok   = $env['wpml_active'] && $env['lang_count'] >= 2 && $env['api_key_set'];
$env_note = ( $env['api_key_ok'] && $env['model_ok'] )
	? __( 'API key and model are verified.', 'perxel-ai-translate' )
	: __( 'Run the Test buttons above so the key and model show as verified here.', 'perxel-ai-translate' );

$lines = array(
	sprintf( 'WPML                 %s', $env['wpml_active'] ? 'active ' . $env['wpml_version'] : 'NOT ACTIVE' ),
	sprintf( 'Active languages     %d', $env['lang_count'] ),
	sprintf( 'Default language     %s', $env['default_lang'] ? $env['default_lang'] : '-' ),
	sprintf( 'API key              %s', $env['api_key_set'] ? ( $env['api_key_ok'] ? 'set, verified' : 'set, not verified' ) : 'NOT SET' ),
	sprintf( 'Model                %s', $env['model_id'] . ( $env['model_ok'] ? ' (verified)' : ' (not verified)' ) ),
	sprintf( 'ACF                  %s', $env['acf'] ? 'active' : 'not detected' ),
	sprintf( 'Rank Math            %s', $env['rankmath'] ? 'active' : 'not detected' ),
	sprintf( 'PHP                  %s', $env['php_version'] ),
	sprintf( 'Max execution time   %ds', $env['max_execution'] ),
);

echo \Perxel_UI::rows(
	array(
		array(
			'title' => __( 'Environment', 'perxel-ai-translate' ),
			'note'  => esc_html( $env_note ),
			'rows'  => array(
				array(
					'summary' => $env_ok
						? __( 'Ready to translate', 'perxel-ai-translate' )
						: __( 'Setup is incomplete', 'perxel-ai-translate' ),
					'icon'    => $env_ok ? 'good' : 'warn',
					'details' => \Perxel_UI::code( implode( "\n", $lines ) ),
				),
			),
		),
	)
);

/* --- Danger zone ---------------------------------------------------- */

echo \Perxel_UI::rows(
	array(
		array(
			'title'  => __( 'Danger zone', 'perxel-ai-translate' ),
			'danger' => true,
			'rows'   => array(
				array(
					'label'   => __( 'Reset settings to defaults', 'perxel-ai-translate' ),
					'sub'     => esc_html__( 'Clears the API key, model, extra instructions and display unit. Translation runs and their history are kept.', 'perxel-ai-translate' ),
					'content' => '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
						. '<input type="hidden" name="action" value="pxat_reset_settings" />'
						. wp_nonce_field( 'pxat_reset_settings', '_wpnonce', true, false )
						. '<button type="submit" class="button" data-pxui-confirm="' . esc_attr__( 'Reset all settings to their defaults?', 'perxel-ai-translate' ) . '">'
						. esc_html__( 'Reset settings', 'perxel-ai-translate' ) . '</button>'
						. '</form>',
				),
			),
		),
	)
);

// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
