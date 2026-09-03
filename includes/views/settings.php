<?php
/**
 * Settings screen.
 *
 * @package Perxel_AI_Translate
 *
 * @var array      $settings      Settings::all().
 * @var array      $model         Settings::model().
 * @var array      $environment   Admin::environment().
 * @var array[]    $compatibility Admin::compatibility().
 * @var array|null $benchmark     Admin::homepage_benchmark().
 * @var bool       $updated       Whether the form just saved.
 * @var bool       $was_reset     Whether settings were just reset.
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
	: esc_html__( 'not checked', 'perxel-ai-translate' );

$key_icon = \Perxel\AITranslate\Settings::api_key_tone();
if ( $settings['api_key_verified'] && (float) $settings['api_key_limit'] > 0 ) {
	$key_sub = sprintf(
		/* translators: 1: USD credit left, 2: USD credit limit, e.g. "$37.66 left of $50.00". */
		esc_html__( 'Verified · %1$s left of %2$s', 'perxel-ai-translate' ),
		esc_html( \Perxel\AITranslate\Format::money_usd( (float) $settings['api_key_remaining'] ) ),
		esc_html( \Perxel\AITranslate\Format::money_usd( (float) $settings['api_key_limit'] ) )
	);
} else {
	$key_sub = $settings['api_key_verified'] ? esc_html__( 'Verified', 'perxel-ai-translate' ) : esc_html__( 'not checked', 'perxel-ai-translate' );
}
$model_icon = $settings['model_verified'] ? 'good' : 'muted';

$test_button = '<button type="button" class="button" id="pxat-test">' . esc_html__( 'Test', 'perxel-ai-translate' ) . '</button>';

$openrouter_rows = array(
	array(
		'label'   => __( 'API key', 'perxel-ai-translate' ),
		'icon'    => $key_icon,
		'sub'     => '<span id="pxat-key-sub">' . $key_sub . '</span>',
		'content' => '<input type="password" id="pxat-api-key" name="api_key" autocomplete="off" value="' . esc_attr( $settings['api_key'] ) . '" />',
	),
	array(
		'label'   => __( 'Model id', 'perxel-ai-translate' ),
		'icon'    => $model_icon,
		'sub'     => '<span id="pxat-model-detail">' . $model_detail . '</span>',
		'content' => '<input type="text" class="pxui-mono" id="pxat-model-id" name="model_id" value="' . esc_attr( $settings['model_id'] ) . '" placeholder="' . esc_attr( PXAT_DEFAULT_MODEL ) . '" />',
	),
);

if ( $benchmark ) {
	$bench_volume = sprintf(
		/* translators: %s: approximate word count. */
		esc_html__( '~%s words', 'perxel-ai-translate' ),
		number_format_i18n( $benchmark['words'] )
	);

	$openrouter_rows[] = array(
		'label'   => __( 'Homepage benchmark', 'perxel-ai-translate' ),
		'icon'    => 'muted',
		'sub'     => sprintf(
			/* translators: %s: word or token volume of the front page. */
			esc_html__( 'Your front page (%s) translated once at this model\'s rates. A fixed sample for comparing models.', 'perxel-ai-translate' ),
			$bench_volume
		),
		'content' => sprintf(
			/* translators: %s: estimated USD cost, e.g. "~$0.0021". */
			esc_html__( '%s per language', 'perxel-ai-translate' ),
			esc_html( \Perxel\AITranslate\Format::cost( $benchmark['cost_per_lang'] ) )
		),
	);
}
?>
<form id="pxat-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-pxui-dirty-guard>
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
				'title'        => __( 'OpenRouter', 'perxel-ai-translate' ),
				'title_action' => $test_button,
				'note'         => sprintf(
					/* translators: 1: link to openrouter.ai, 2: link to the model list. */
					esc_html__( 'Get an API key at %1$s and browse model ids at %2$s. You pay OpenRouter directly for usage.', 'perxel-ai-translate' ),
					'<a href="https://openrouter.ai/" target="_blank" rel="noopener noreferrer">openrouter.ai</a>',
					'<a href="https://openrouter.ai/models" target="_blank" rel="noopener noreferrer">openrouter.ai/models</a>'
				),
				'rows'         => $openrouter_rows,
			),
			array(
				'title' => __( 'Translation', 'perxel-ai-translate' ),
				'rows'  => array(
					array(
						'label'   => __( 'Faster batched requests', 'perxel-ai-translate' ),
						'sub'     => esc_html__( 'Send several posts per model request. Faster for many short posts; one bad response affects a group.', 'perxel-ai-translate' ),
						'content' => \Perxel_UI::toggle(
							array(
								'name'    => 'batched',
								'checked' => (bool) $settings['batched'],
								'label'   => __( 'Faster batched requests', 'perxel-ai-translate' ),
							)
						),
					),
					array(
						'summary' => __( 'Extra instructions', 'perxel-ai-translate' ),
						'sub'     => esc_html__( 'Optional guidance appended to every request: glossary, tone of voice, terminology rules.', 'perxel-ai-translate' ),
						'open'    => '' !== trim( (string) $settings['prompt'] ),
						'details' => '<textarea name="prompt" rows="4">' . esc_textarea( $settings['prompt'] ) . '</textarea>',
					),
					array(
						'summary' => __( 'System prompt sent to the model', 'perxel-ai-translate' ),
						'sub'     => esc_html__( 'Read-only. Copy it to translate manually with any AI chat tool if your key stops working. Replace {source_lang} / {dest_lang} with real codes.', 'perxel-ai-translate' ),
						'details' => '<textarea class="pxui-mono" rows="8" readonly onclick="this.select()">' . esc_textarea( $system_prompt ) . '</textarea>',
					),
				),
			),
		)
	);
	?>
</form>

<?php
/* --- Compatibility --------------------------------------------------- */

$compat_rows = array();
foreach ( $compatibility as $plugin ) {
	if ( $plugin['active'] ) {
		$state = $plugin['version']
			/* translators: %s: plugin version active on this site. */
			? sprintf( __( 'active %s', 'perxel-ai-translate' ), $plugin['version'] )
			: __( 'active', 'perxel-ai-translate' );
	} else {
		$state = __( 'not detected', 'perxel-ai-translate' );
	}

	$compat_rows[] = array(
		'label'   => $plugin['name'],
		'icon'    => $plugin['active'] ? 'good' : 'muted',
		'sub'     => esc_html( $plugin['note'] ),
		/* translators: 1: version the plugin was tested against, 2: state on this site (e.g. "active 1.0.0" or "not detected"). */
		'content' => esc_html( sprintf( __( 'tested %1$s  ·  %2$s', 'perxel-ai-translate' ), $plugin['tested'], $state ) ),
	);
}

echo \Perxel_UI::rows(
	array(
		array(
			'title' => __( 'Compatibility', 'perxel-ai-translate' ),
			'note'  => esc_html__( 'The plugin is built and tested against these versions. Each is listed whether or not it is installed; a check means it is active on this site.', 'perxel-ai-translate' ),
			'rows'  => $compat_rows,
		),
	)
);

/* --- Environment ------------------------------------------------------ */

$env    = $environment;
$env_ok = $env['wpml_active'] && $env['lang_count'] >= 2 && $env['api_key_ok'] && $env['model_ok'];

$lines = array(
	sprintf(
		'WPML                 %s',
		$env['wpml_active']
			? 'active ' . $env['wpml_version'] . ', tested ' . $env['wpml_tested']
			: 'NOT ACTIVE'
	),
	sprintf( 'Active languages     %d', $env['lang_count'] ),
	sprintf( 'Default language     %s', $env['default_lang'] ? $env['default_lang'] : '-' ),
	sprintf( 'API key              %s', $env['api_key_set'] ? ( $env['api_key_ok'] ? 'set, verified' : 'set, not verified' ) : 'NOT SET' ),
	sprintf( 'Model                %s', $env['model_id'] . ( $env['model_ok'] ? ' (verified)' : ' (not verified)' ) ),
	sprintf( 'PHP                  %s', $env['php_version'] ),
	sprintf( 'Max execution time   %ds', $env['max_execution'] ),
);

echo \Perxel_UI::rows(
	array(
		array(
			'title' => __( 'Environment', 'perxel-ai-translate' ),
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
					'sub'     => esc_html__( 'Clears the API key, model and extra instructions. Translation runs and their history are kept.', 'perxel-ai-translate' ),
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
