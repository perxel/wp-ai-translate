<?php
/**
 * ID lookup tool.
 *
 * @package Perxel_AI_Translate
 *
 * @var string $error
 * @var array  $languages
 * @var string $source_lang
 * @var string $dest_lang
 * @var bool   $submitted
 * @var string $ids_raw
 * @var int    $input_count
 * @var array  $output_ids
 * @var string $output_text
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Perxel\AITranslate\Admin;
use Perxel\AITranslate\Wpml;

if ( '' !== $error ) {
	echo \Perxel_UI::notice( 'error', esc_html( $error ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Perxel_UI escapes structure.
	return;
}
?>
<p class="pxat-muted"><?php esc_html_e( 'Paste a list of IDs (commas, spaces or new lines) to get their matching translation IDs, in the same order. Post type is detected per ID. Read-only - nothing is written.', 'perxel-ai-translate' ); ?></p>

<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="pxat-lookup">
	<input type="hidden" name="page" value="<?php echo esc_attr( Admin::PAGE_ID_LOOKUP ); ?>" />
	<input type="hidden" name="pxat_lookup" value="1" />

	<?php
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Perxel_UI escapes structure; values escaped inline.
	$lang_select = '<select name="dest_lang">';
	foreach ( $languages as $code => $lang ) {
		if ( $code === $source_lang ) {
			continue;
		}
		$lang_select .= '<option value="' . esc_attr( $code ) . '"' . selected( $dest_lang, $code, false ) . '>' . esc_html( Wpml::language_label( $languages, $code ) ) . '</option>';
	}
	$lang_select .= '</select>';

	echo \Perxel_UI::rows(
		array(
			array(
				'rows' => array(
					array(
						'label'   => esc_html__( 'Languages', 'perxel-ai-translate' ),
						'sub'     => esc_html__( 'Source is WPML’s site default and cannot be changed here.', 'perxel-ai-translate' ),
						'content' => esc_html( Wpml::language_label( $languages, $source_lang ) ) . ' &rarr; ' . $lang_select,
					),
				),
			),
		)
	);
	// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	?>

	<div class="pxat-lookup__cols">
		<div>
			<label for="pxat-ids"><strong><?php esc_html_e( 'Source IDs', 'perxel-ai-translate' ); ?></strong></label>
			<textarea id="pxat-ids" name="ids" rows="16" placeholder="144777, 142887, 141732, …"><?php echo esc_textarea( $ids_raw ); ?></textarea>
		</div>
		<div>
			<strong><?php esc_html_e( 'Result', 'perxel-ai-translate' ); ?></strong>
			<button type="button" class="button button-small" id="pxat-copy-output"><?php esc_html_e( 'Copy', 'perxel-ai-translate' ); ?></button>
			<span id="pxat-copy-output-result" class="pxat-test-result"></span>
			<textarea id="pxat-output" readonly rows="16" onclick="this.select();" placeholder="<?php esc_attr_e( 'Results appear here after a lookup.', 'perxel-ai-translate' ); ?>"><?php echo esc_textarea( $output_text ); ?></textarea>
			<?php if ( $submitted ) : ?>
				<p class="pxat-muted">
					<?php
					printf(
						/* translators: 1: resolved count, 2: submitted count. */
						esc_html__( 'Found %1$d / %2$d IDs. IDs with no translation are omitted.', 'perxel-ai-translate' ),
						count( $output_ids ),
						(int) $input_count
					);
					?>
				</p>
			<?php endif; ?>
		</div>
	</div>

	<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Look up IDs', 'perxel-ai-translate' ); ?></button></p>
</form>
