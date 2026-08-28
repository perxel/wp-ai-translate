<?php
/**
 * Translation ID lookup tool.
 *
 * @package Perxel_AI_Translate
 *
 * @var array  $languages    WPML active languages.
 * @var string $source_lang  Locked to WPML's site default — read-only.
 * @var string $dest_lang
 * @var bool   $submitted
 * @var string $ids_raw
 * @var int    $input_count  Numeric tokens found in $ids_raw (only meaningful when $submitted).
 * @var array  $output_ids   Resolved dest-lang IDs, in input order, missing ones dropped.
 * @var string $output_text  Comma-joined $output_ids.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap pxat-wrap">
	<h1><?php echo esc_html( sprintf( '%s - %s', PXAT_NAME, __( 'Translation ID lookup', 'perxel-ai-translate' ) ) ); ?></h1>
	<p class="description"><?php esc_html_e( 'Paste a list of IDs (separated by commas, spaces or new lines) to get their matching translation IDs, in the same order.', 'perxel-ai-translate' ); ?></p>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<input type="hidden" name="page" value="<?php echo esc_attr( PXAT_ID_Lookup_Page::PAGE_SLUG ); ?>" />
		<input type="hidden" name="pxat_lookup_submit" value="1" />

		<p style="display:flex; align-items:center; gap:20px;">
			<strong><?php esc_html_e( 'Languages', 'perxel-ai-translate' ); ?>:</strong>
			<span>
				<?php echo esc_html( isset( $languages[ $source_lang ]['translated_name'] ) ? $languages[ $source_lang ]['translated_name'] : ( isset( $languages[ $source_lang ]['native_name'] ) ? $languages[ $source_lang ]['native_name'] : $source_lang ) ); ?>
				<span class="description">(<?php esc_html_e( 'site default language, cannot be changed', 'perxel-ai-translate' ); ?>)</span>
			</span>
			&rarr;
			<select style="width:130px;" name="dest_lang">
				<?php
				foreach ( $languages as $code => $lang ) :
					if ( $code === $source_lang ) {
						continue;
					}
					?>
					<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $dest_lang, $code ); ?>><?php echo esc_html( isset( $lang['translated_name'] ) ? $lang['translated_name'] : $lang['native_name'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>

		<div style="display:flex; gap:24px; max-width:1100px;">
			<div style="flex:1; min-width:0;">
				<label for="pxat_ids"><strong><?php esc_html_e( 'ID list (source)', 'perxel-ai-translate' ); ?></strong></label>
				<textarea id="pxat_ids" name="ids" rows="16" style="width:100%; font-family:monospace; margin-top:6px;" placeholder="144777, 142887, 141732, ..."><?php echo esc_textarea( $ids_raw ); ?></textarea>
			</div>
			<div style="flex:1; min-width:0;">
				<strong><?php esc_html_e( 'Result (destination)', 'perxel-ai-translate' ); ?></strong>
				<button type="button" class="button button-small" id="pxat-copy-output" style="margin-left:8px;"><?php esc_html_e( 'Copy', 'perxel-ai-translate' ); ?></button>
				<span id="pxat-copy-output-result" class="pxat-test-result"></span>
				<textarea id="pxat_output" readonly rows="16" style="width:100%; font-family:monospace; margin-top:6px; background:#fff; color:#2c3338;" onclick="this.select();" placeholder="<?php esc_attr_e( 'Results appear here after a lookup.', 'perxel-ai-translate' ); ?>"><?php echo esc_textarea( $output_text ); ?></textarea>
				<?php if ( $submitted ) : ?>
					<p class="description" style="margin:6px 0 0;">
						<?php
						printf(
							/* translators: 1: number of IDs resolved, 2: number of IDs submitted. */
							esc_html__( 'Found %1$d / %2$d IDs. IDs with no translation are omitted from the result.', 'perxel-ai-translate' ),
							count( $output_ids ),
							$input_count
						);
						?>
					</p>
				<?php endif; ?>
			</div>
		</div>

		<?php submit_button( __( 'Look up IDs', 'perxel-ai-translate' ) ); ?>
	</form>

	<?php
	$footer_exclude = PXAT_ID_Lookup_Page::PAGE_SLUG;
	include PXAT_DIR . '/views/footer.php';
	?>
</div>
