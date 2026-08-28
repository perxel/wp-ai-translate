<?php
/**
 * Batch progress / review screen.
 *
 * @package Perxel_AI_Translate
 *
 * @var array  $jobs
 * @var string $batch_id
 * @var array  $languages   WPML active languages.
 * @var string $source_lang
 * @var string $dest_lang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$counts  = PXAT_Batch::get_counts( $batch_id );
$meta    = PXAT_Batch::get_meta( $batch_id );
$is_done = $counts['total'] > 0 && 0 === $counts['pending'] && 0 === $counts['processing'];

$log_lines = array();
foreach ( $jobs as $job ) {
	if ( empty( $job['log'] ) ) {
		continue;
	}
	foreach ( $job['log'] as $entry ) {
		$log_lines[] = array(
			'at'   => $entry['at'],
			'text' => '[' . $entry['at'] . '] ' . $entry['message'],
		);
	}
}
usort(
	$log_lines,
	function ( $a, $b ) {
		return strcmp( $a['at'], $b['at'] );
	}
);

$batch_model = PXAT_OpenRouter::get_model( $meta['model'] );

$translated_pct = $counts['total'] > 0 ? round( $counts['success'] / $counts['total'] * 100 ) : 0;
$applied_pct    = $counts['total'] > 0 ? round( $counts['applied'] / $counts['total'] * 100 ) : 0;
$elapsed_seconds = PXAT_Batch::get_duration_seconds( $batch_id );

$source_lang_label = isset( $languages[ $source_lang ] ) ? ( isset( $languages[ $source_lang ]['translated_name'] ) ? $languages[ $source_lang ]['translated_name'] : $languages[ $source_lang ]['native_name'] ) : $source_lang;
$dest_lang_label   = isset( $languages[ $dest_lang ] ) ? ( isset( $languages[ $dest_lang ]['translated_name'] ) ? $languages[ $dest_lang ]['translated_name'] : $languages[ $dest_lang ]['native_name'] ) : $dest_lang;
?>
<div class="wrap pxat-wrap">
	<h1><?php echo esc_html( PXAT_NAME ); ?></h1>
	<p>
		<?php
		printf(
			/* translators: %s: batch identifier. */
			esc_html__( 'Batch: %s', 'perxel-ai-translate' ),
			esc_html( $batch_id )
		);
		?>
		<?php if ( $meta['created_at'] ) : ?>
			, <?php echo esc_html( PXAT_Format::time_ago( $meta['created_at'] ) ); ?>
			<?php if ( $meta['created_by'] ) : ?>
				<?php
				printf(
					/* translators: %s: user display name. */
					esc_html__( 'by %s', 'perxel-ai-translate' ),
					esc_html( $meta['created_by'] )
				);
				?>
			<?php endif; ?>
		<?php endif; ?>
	</p>

	<p>
		<span class="pxat-badge pxat-badge--applied"><?php echo esc_html( PXAT_Batch::mode_label( $meta ) ); ?></span>
		<?php if ( 'custom' === $meta['data_mode'] ) : ?>
			<span class="description"><?php esc_html_e( 'Selected data only, on posts that already have a translation. Any type with untranslated data in WPML reports an error for that type instead of applying partially.', 'perxel-ai-translate' ); ?></span>
		<?php endif; ?>
		<?php if ( ! empty( $meta['auto_apply'] ) ) : ?>
			<span class="description"><?php esc_html_e( 'Written straight into WordPress as each post finishes, no preview step.', 'perxel-ai-translate' ); ?></span>
		<?php endif; ?>
	</p>

	<?php if ( $is_done ) : ?>
	<p>
		<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'pxat_rerun_batch', 'batch_id' => $batch_id ), admin_url( 'admin-post.php' ) ), 'pxat_rerun_batch_' . $batch_id ) ); ?>"
			class="button"><?php esc_html_e( 'Re-translate this list of posts', 'perxel-ai-translate' ); ?></a>
	</p>
	<?php else : ?>
	<p>
		<button type="button" class="button button-primary" id="pxat-start-btn"><?php esc_html_e( 'Start translating', 'perxel-ai-translate' ); ?></button>
		<button type="button" class="button" id="pxat-stop-btn" style="display:none;"><?php esc_html_e( 'Stop', 'perxel-ai-translate' ); ?></button>
	</p>
	<?php endif; ?>

	<div id="pxat-stats" class="pxat-stats">
		<div class="pxat-stat-card">
			<div class="pxat-stat-label"><?php esc_html_e( 'Translated', 'perxel-ai-translate' ); ?></div>
			<div class="pxat-stat-value"><span id="pxat-stat-translated-pct"><?php echo esc_html( $translated_pct ); ?></span>%</div>
			<div class="pxat-stat-sub"><span id="pxat-stat-translated-frac"><?php echo esc_html( $counts['success'] . ' / ' . $counts['total'] ); ?></span></div>
			<div class="pxat-stat-bar"><div class="pxat-stat-bar-fill" id="pxat-stat-translated-bar" style="width:<?php echo esc_attr( $translated_pct ); ?>%"></div></div>
			<div class="pxat-stat-note" id="pxat-current">
				<span class="spinner" id="pxat-current-spinner"></span>
				<span id="pxat-current-text"></span>
			</div>
		</div>
		<div class="pxat-stat-card">
			<div class="pxat-stat-label"><?php esc_html_e( 'Applied', 'perxel-ai-translate' ); ?></div>
			<div class="pxat-stat-value"><span id="pxat-stat-applied-pct"><?php echo esc_html( $applied_pct ); ?></span>%</div>
			<div class="pxat-stat-sub"><span id="pxat-stat-applied-frac"><?php echo esc_html( $counts['applied'] . ' / ' . $counts['total'] ); ?></span></div>
			<div class="pxat-stat-bar"><div class="pxat-stat-bar-fill" id="pxat-stat-applied-bar" style="width:<?php echo esc_attr( $applied_pct ); ?>%"></div></div>
		</div>
		<div class="pxat-stat-card">
			<div class="pxat-stat-label"><?php esc_html_e( 'Cost', 'perxel-ai-translate' ); ?></div>
			<div class="pxat-stat-value" id="pxat-stat-cost"><?php echo esc_html( PXAT_Format::cost( $counts['cost_usd'] ) ); ?></div>
			<div class="pxat-stat-sub"><code><?php echo esc_html( $batch_model['label'] ); ?></code></div>
		</div>
		<div class="pxat-stat-card">
			<div class="pxat-stat-label"><?php esc_html_e( 'Volume', 'perxel-ai-translate' ); ?></div>
			<div class="pxat-stat-value" id="pxat-stat-tokens"><?php echo esc_html( PXAT_Format::unit_label( $counts['prompt_tokens'] + $counts['completion_tokens'] ) ); ?></div>
		</div>
		<div class="pxat-stat-card">
			<div class="pxat-stat-label"><?php esc_html_e( 'Time', 'perxel-ai-translate' ); ?></div>
			<div class="pxat-stat-value" id="pxat-stat-elapsed"><?php echo esc_html( PXAT_Format::duration( $elapsed_seconds ) ); ?></div>
		</div>
	</div>
	<p id="pxat-stats-footnote" class="description">
		<span id="pxat-stat-error"><?php echo esc_html( $counts['error'] ); ?></span> <?php esc_html_e( 'errors', 'perxel-ai-translate' ); ?>
		·
		<span id="pxat-stat-skipped"><?php echo esc_html( $counts['skipped'] ); ?></span> <?php esc_html_e( 'skipped', 'perxel-ai-translate' ); ?>
		<span id="pxat-stat-warnings-wrap" style="<?php echo $counts['warnings'] > 0 ? '' : 'display:none;'; ?>">
			· <span id="pxat-stat-warnings" class="pxat-inline-warning"><?php echo esc_html( $counts['warnings'] ); ?> <?php esc_html_e( 'posts with warnings (data did not copy completely — see the Action column)', 'perxel-ai-translate' ); ?></span>
		</span>
		<span id="pxat-stat-apply-errors-wrap" style="<?php echo $counts['apply_errors'] > 0 ? '' : 'display:none;'; ?>">
			· <span id="pxat-stat-apply-errors" class="pxat-inline-error"><?php echo esc_html( $counts['apply_errors'] ); ?> <?php esc_html_e( 'posts failed to apply (see the Action column)', 'perxel-ai-translate' ); ?></span>
		</span>
	</p>
	<?php if ( empty( $meta['auto_apply'] ) ) : ?>
	<p>
		<button type="button" class="button button-primary" id="pxat-apply-all-top"><?php esc_html_e( 'Apply all', 'perxel-ai-translate' ); ?></button>
		<span class="spinner" id="pxat-apply-all-top-spinner"></span>
	</p>
	<?php endif; ?>

	<table class="widefat striped" id="pxat-jobs-table">
		<thead>
			<tr>
				<th><?php echo esc_html( sprintf( '%s (%s)', __( 'Source post', 'perxel-ai-translate' ), $source_lang_label ) ); ?></th>
				<th><?php echo esc_html( sprintf( '%s (%s)', __( 'Destination post', 'perxel-ai-translate' ), $dest_lang_label ) ); ?></th>
				<th><?php esc_html_e( 'Status', 'perxel-ai-translate' ); ?></th>
				<th><?php esc_html_e( 'Note', 'perxel-ai-translate' ); ?></th>
				<th><?php esc_html_e( 'Action', 'perxel-ai-translate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $jobs as $job ) : ?>
				<tr data-job-id="<?php echo esc_attr( $job['id'] ); ?>" data-log-count="<?php echo esc_attr( count( isset( $job['log'] ) ? $job['log'] : array() ) ); ?>">
					<td class="pxat-source-post"><?php echo PXAT_Progress_Page::render_post_cell( $job['source_post_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup assembled with esc_* helpers inside render_post_cell(). ?></td>
					<td class="pxat-dest-post"><?php echo PXAT_Progress_Page::render_post_cell( $job['dest_post_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup assembled with esc_* helpers inside render_post_cell(). ?></td>
					<td class="pxat-status">
						<span class="pxat-badge pxat-badge--<?php echo esc_attr( $job['status'] ); ?>">
							<?php if ( 'processing' === $job['status'] ) : ?>
								<span class="spinner is-active"></span>
							<?php endif; ?>
							<?php echo esc_html( PXAT_Progress_Page::status_label( $job['status'] ) ); ?>
						</span>
					</td>
					<td class="pxat-message"><?php echo esc_html( $job['error_message'] ? $job['error_message'] : '' ); ?></td>
					<td class="pxat-action"><?php echo PXAT_Progress_Page::render_action_cell( $job ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup assembled with esc_* helpers inside render_action_cell(). ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( empty( $meta['auto_apply'] ) ) : ?>
	<p>
		<button type="button" class="button button-primary" id="pxat-apply-all-bottom"><?php esc_html_e( 'Apply all', 'perxel-ai-translate' ); ?></button>
		<span class="spinner" id="pxat-apply-all-bottom-spinner"></span>
	</p>
	<?php endif; ?>

	<dialog id="pxat-preview-dialog" class="pxat-dialog">
		<h2 id="pxat-preview-title"></h2>
		<div id="pxat-preview-body"></div>
		<p class="pxat-dialog-actions">
			<button type="button" class="button" data-dialog-close="pxat-preview-dialog"><?php esc_html_e( 'Close', 'perxel-ai-translate' ); ?></button>
		</p>
	</dialog>

	<dialog id="pxat-apply-dialog" class="pxat-dialog">
		<h2><?php esc_html_e( 'Confirm apply', 'perxel-ai-translate' ); ?></h2>
		<p id="pxat-apply-summary"></p>
		<p class="pxat-dialog-actions">
			<button type="button" class="button button-primary" id="pxat-apply-confirm"><?php esc_html_e( 'Apply', 'perxel-ai-translate' ); ?></button>
			<button type="button" class="button" data-dialog-close="pxat-apply-dialog"><?php esc_html_e( 'Cancel', 'perxel-ai-translate' ); ?></button>
		</p>
	</dialog>

	<h2><?php esc_html_e( 'Logs', 'perxel-ai-translate' ); ?></h2>
	<pre id="pxat-log" class="pxat-log"><?php
	foreach ( $log_lines as $line ) {
		echo esc_html( $line['text'] ) . "\n";
	}
	?></pre>

	<?php
	$footer_exclude = '';
	include PXAT_DIR . '/views/footer.php';
	?>
</div>
