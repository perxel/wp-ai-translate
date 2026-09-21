<?php
/**
 * History screen.
 *
 * @package Perxel_AI_Translate
 *
 * @var \Perxel_Ai_Translate\RunsListTable $table
 * @var string|null                       $notice
 * @var bool                              $has_rows
 * @var array                             $totals
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( $notice ) {
	\Perxel_Ai_Translate\Admin::kit( \Perxel_UI::notice( 'success', esc_html( $notice ), array( 'dismissible' => true ) ) );
}

if ( ! $has_rows ) {
	\Perxel_Ai_Translate\Admin::kit( \Perxel_UI::notice( 'info', esc_html__( 'No translation runs yet. Start one from the Dashboard.', 'perxel-ai-translate' ) ) );
	return;
}
?>
<form method="get">
	<input type="hidden" name="page" value="<?php echo esc_attr( \Perxel_Ai_Translate\Admin::PAGE_HISTORY ); ?>" />
	<?php wp_nonce_field( 'bulk-runs' ); ?>
	<?php $table->display(); ?>
</form>
