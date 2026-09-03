<?php
/**
 * History screen.
 *
 * @package Perxel_AI_Translate
 *
 * @var \Perxel\AITranslate\RunsListTable $table
 * @var string|null                       $notice
 * @var bool                              $has_rows
 * @var array                             $totals
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( $notice ) {
	echo \Perxel_UI::notice( 'success', esc_html( $notice ), array( 'dismissible' => true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Perxel_UI escapes structure.
}

if ( ! $has_rows ) {
	echo \Perxel_UI::notice( 'info', esc_html__( 'No translation runs yet. Start one from the Dashboard.', 'perxel-ai-translate' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	return;
}
?>
<form method="get">
	<input type="hidden" name="page" value="<?php echo esc_attr( \Perxel\AITranslate\Admin::PAGE_HISTORY ); ?>" />
	<?php wp_nonce_field( 'bulk-runs' ); ?>
	<?php $table->display(); ?>
</form>
