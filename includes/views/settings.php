<?php
/**
 * Settings screen.
 *
 * @package Perxel_AI_Translate
 *
 * @var array $settings  Settings::all().
 * @var bool  $updated   Whether the form just saved.
 * @var bool  $was_reset Whether settings were just reset.
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

$models = OpenRouter::get_models();

$model_lines = array();
foreach ( $models as $model ) {
	$model_lines[] = sprintf(
		'%s  —  $%s in / $%s out per 1M tokens',
		$model['label'],
		$model['input'],
		$model['output']
	);
}

$system_prompt = OpenRouter::build_system_prompt( $settings['prompt'], '{source_lang}', '{dest_lang}' );
?>
<form id="pxat-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="pxat_save_settings" />
	<?php wp_nonce_field( 'pxat_save_settings' ); ?>

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
						'sub'     => '<span id="pxat-key-result" class="pxat-test-result"></span>',
						'content' => '<input type="password" id="pxat-api-key" name="api_key" autocomplete="off" class="regular-text" value="'
							. esc_attr( $settings['api_key'] ) . '" /> '
							. '<button type="button" class="button" id="pxat-test-key">' . esc_html__( 'Test', 'perxel-ai-translate' ) . '</button>',
					),
				),
			),
			array(
				'title' => __( 'Translation', 'perxel-ai-translate' ),
				'rows'  => array(
					array(
						'label'   => __( 'Extra instructions', 'perxel-ai-translate' ),
						'sub'     => esc_html__( 'Optional guidance appended to every request: glossary, tone of voice, terminology rules.', 'perxel-ai-translate' ),
						'content' => '<textarea name="prompt" rows="3" class="large-text code">' . esc_textarea( $settings['prompt'] ) . '</textarea>',
					),
					array(
						'summary' => __( 'System prompt sent to the model', 'perxel-ai-translate' ),
						'sub'     => esc_html__( 'Copy this to translate manually with any AI chat tool if your key stops working. Replace {source_lang} / {dest_lang} with real codes.', 'perxel-ai-translate' ),
						'details' => \Perxel_UI::code( $system_prompt ),
					),
					array(
						'summary' => __( 'Available models', 'perxel-ai-translate' ),
						'sub'     => sprintf(
							/* translators: %s: filter name. */
							esc_html__( 'Configured in code. Change or extend with the %s filter.', 'perxel-ai-translate' ),
							'<code>pxat_openrouter_models</code>'
						),
						'details' => \Perxel_UI::code( implode( "\n", $model_lines ) ),
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
echo \Perxel_UI::rows(
	array(
		array(
			'title'  => __( 'Danger zone', 'perxel-ai-translate' ),
			'danger' => true,
			'rows'   => array(
				array(
					'label'   => __( 'Reset settings to defaults', 'perxel-ai-translate' ),
					'sub'     => esc_html__( 'Clears the API key, extra instructions and display unit. Translation runs and their history are kept.', 'perxel-ai-translate' ),
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
