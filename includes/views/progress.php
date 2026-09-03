<?php
/**
 * Progress / run screen.
 *
 * @package Perxel_AI_Translate
 *
 * @var array  $run
 * @var array  $counts
 * @var bool   $is_done
 * @var array  $items       Items with ['html'] cell strings + post snapshots.
 * @var array  $log_lines   Rows: logged_at, message.
 * @var array  $languages
 * @var string $model_label
 * @var float  $elapsed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Perxel\AITranslate\Format;
use Perxel\AITranslate\Translator;
use Perxel\AITranslate\Wpml;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Perxel_UI escapes structure; dynamic values escaped inline.

$posts_n = static function ( $n ) {
	return sprintf(
		/* translators: %s: number of posts. */
		_n( '%s post', '%s posts', $n, 'perxel-ai-translate' ),
		number_format_i18n( $n )
	);
};

$total    = max( 1, (int) $counts['total'] );
$done_pct = (int) round( ( $counts['done'] + $counts['error'] + $counts['skipped'] ) / $total * 100 );

$scope = 'custom' === $run['data_mode']
	? sprintf(
		/* translators: %s: comma-separated type labels. */
		__( 'Custom (%s)', 'perxel-ai-translate' ),
		implode( ', ', array_map( array( 'Perxel\AITranslate\Translator', 'type_label' ), $run['custom_types'] ) )
	)
	: __( 'Everything', 'perxel-ai-translate' );

echo '<p class="pxat-step">'
	. esc_html(
		sprintf(
			/* translators: 1: run id, 2: source lang, 3: dest lang, 4: relative time. */
			__( 'Run #%1$d · %2$s → %3$s · started %4$s', 'perxel-ai-translate' ),
			$run['id'],
			Wpml::language_label( $languages, $run['source_lang'] ),
			Wpml::language_label( $languages, $run['dest_lang'] ),
			Format::time_ago( $run['created_at'] )
		)
	)
	. ' <span class="pxat-badge pxat-badge--mode">' . esc_html( $scope . ( $run['batched'] ? ' · ' . __( 'batched', 'perxel-ai-translate' ) : '' ) ) . '</span></p>';

if ( $is_done ) {
	echo \Perxel_UI::notice(
		'success',
		esc_html(
			sprintf(
				/* translators: %s: post count. */
				__( 'Run finished. %s written into WordPress — open each post to review.', 'perxel-ai-translate' ),
				$posts_n( $counts['done'] )
			)
		)
	);
}

echo \Perxel_UI::progress_bar( $done_pct, array( 'id' => 'pxat-progress-bar' ) );

echo \Perxel_UI::stat_grid(
	array(
		array(
			'label' => __( 'Done', 'perxel-ai-translate' ),
			'value' => '<span id="pxat-stat-done">' . esc_html( number_format_i18n( $counts['done'] ) ) . '</span> / ' . esc_html( number_format_i18n( $counts['total'] ) ),
			'tone'  => 'good',
		),
		array(
			'label' => __( 'Errors', 'perxel-ai-translate' ),
			'value' => '<span id="pxat-stat-error">' . esc_html( number_format_i18n( $counts['error'] ) ) . '</span>',
			'sub'   => '<span id="pxat-stat-skipped">' . esc_html( number_format_i18n( $counts['skipped'] ) ) . '</span> ' . esc_html__( 'skipped', 'perxel-ai-translate' ),
			'tone'  => $counts['error'] > 0 ? 'bad' : null,
		),
		array(
			'label' => __( 'Cost', 'perxel-ai-translate' ),
			'value' => '<span id="pxat-stat-cost">' . esc_html( Format::cost( $counts['cost_usd'] ) ) . '</span>',
			'sub'   => esc_html( $model_label ),
		),
		array(
			'label' => __( 'Volume', 'perxel-ai-translate' ),
			'value' => '<span id="pxat-stat-tokens">' . esc_html( Format::unit_label( $counts['prompt_tokens'] + $counts['completion_tokens'] ) ) . '</span>',
		),
		array(
			'label' => __( 'Time', 'perxel-ai-translate' ),
			'value' => '<span id="pxat-stat-time">' . esc_html( Format::duration( $elapsed ) ) . '</span>',
		),
	)
);

if ( $counts['warnings'] > 0 ) {
	echo \Perxel_UI::notice(
		'warning',
		esc_html(
			sprintf(
				/* translators: %s: post count. */
				__( '%s finished with a warning — some data did not copy completely. See the Note column.', 'perxel-ai-translate' ),
				$posts_n( $counts['warnings'] )
			)
		),
		array( 'inline' => true )
	);
}

$src_label  = Wpml::language_label( $languages, $run['source_lang'] );
$dest_label = Wpml::language_label( $languages, $run['dest_lang'] );
?>
<div class="pxat-table-wrap">
<table class="widefat striped" id="pxat-items">
	<thead>
		<tr>
			<th><?php echo esc_html( sprintf( '%s (%s)', __( 'Source', 'perxel-ai-translate' ), $src_label ) ); ?></th>
			<th><?php echo esc_html( sprintf( '%s (%s)', __( 'Translation', 'perxel-ai-translate' ), $dest_label ) ); ?></th>
			<th><?php esc_html_e( 'Status', 'perxel-ai-translate' ); ?></th>
			<th><?php esc_html_e( 'Note', 'perxel-ai-translate' ); ?></th>
			<th><?php esc_html_e( 'Action', 'perxel-ai-translate' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php
		foreach ( $items as $item ) :
			$preview_json = wp_json_encode(
				array(
					'before'  => $item['before'],
					'preview' => $item['preview'],
					'action'  => $item['action'],
				)
			);
			?>
			<tr data-item-id="<?php echo (int) $item['id']; ?>" data-preview="<?php echo esc_attr( $preview_json ); ?>">
				<td class="pxat-cell-source"><?php echo $item['html']['source']; ?></td>
				<td class="pxat-cell-dest"><?php echo $item['html']['dest']; ?></td>
				<td class="pxat-cell-status"><?php echo $item['html']['status']; ?></td>
				<td class="pxat-cell-note"><?php echo $item['html']['note']; ?></td>
				<td class="pxat-cell-action"><?php echo $item['html']['action']; ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
</div>

<dialog id="pxat-view-dialog" class="pxat-dialog">
	<h2><?php esc_html_e( 'Translation preview', 'perxel-ai-translate' ); ?></h2>
	<div id="pxat-view-body"></div>
	<p><button type="button" class="button" id="pxat-view-close"><?php esc_html_e( 'Close', 'perxel-ai-translate' ); ?></button></p>
</dialog>

<?php
$log_text = '';
foreach ( $log_lines as $line ) {
	$log_text .= '[' . $line['logged_at'] . '] ' . $line['message'] . "\n";
}
echo \Perxel_UI::rows(
	array(
		array(
			'rows' => array(
				array(
					'summary' => __( 'Activity log', 'perxel-ai-translate' ),
					'details' => \Perxel_UI::code( $log_text, array( 'id' => 'pxat-log' ) ),
				),
			),
		),
	)
);

// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
