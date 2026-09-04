<?php
/**
 * Progress / run screen.
 *
 * @package Perxel_AI_Translate
 *
 * @var array  $run
 * @var array  $counts
 * @var bool   $is_done
 * @var string $phase       Run phase from Runs::state(): running|blocked|complete|idle.
 * @var array  $items       Items with ['html'] cell strings + post snapshots.
 * @var string $log_text    Activity log as one "[time] message" block.
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

$src_label  = Wpml::language_label( $languages, $run['source_lang'] );
$dest_label = Wpml::language_label( $languages, $run['dest_lang'] );

$scope = 'custom' === $run['data_mode']
	? sprintf(
		/* translators: %s: comma-separated type labels. */
		__( 'Custom (%s)', 'perxel-ai-translate' ),
		implode( ', ', array_map( array( 'Perxel\AITranslate\Translator', 'type_label' ), $run['custom_types'] ) )
	)
	: __( 'Everything', 'perxel-ai-translate' );

/*
 * The run figures, as one grouped list. Run identity is the group's chrome
 * (title + badge + note), not extra rows; progress is a meter in the first
 * row's value slot, not a standalone bar; the activity log is the last row.
 */
$progress_row = array(
	'label'   => __( 'Progress', 'perxel-ai-translate' ),
	'content' => '<span id="pxat-stat-done">' . esc_html( number_format_i18n( $counts['done'] ) ) . '</span> / '
		. esc_html( number_format_i18n( $counts['total'] ) ) . ' &middot; '
		. \Perxel_UI::meter( $done_pct, array( 'id' => 'pxat-progress-bar' ) ),
);

if ( $is_done && $counts['error'] > 0 ) {
	$progress_row['icon']  = 'bad';
	$progress_row['label'] = __( 'Finished with errors', 'perxel-ai-translate' );
	$progress_row['sub']   = esc_html(
		sprintf(
			/* translators: 1: posts written, 2: number that failed. */
			__( '%1$s written, %2$s failed - retry them in the table below.', 'perxel-ai-translate' ),
			$posts_n( $counts['done'] ),
			number_format_i18n( $counts['error'] )
		)
	);
} elseif ( $is_done ) {
	$progress_row['icon']  = 'good';
	$progress_row['label'] = __( 'Complete', 'perxel-ai-translate' );
	$progress_row['sub']   = esc_html(
		sprintf(
			/* translators: %s: post count. */
			__( '%s written into WordPress - open each post to review.', 'perxel-ai-translate' ),
			$posts_n( $counts['done'] )
		)
	);
}

echo \Perxel_UI::rows(
	array(
		array(
			'title'        => sprintf(
				/* translators: %d: run id. */
				__( 'Run #%d', 'perxel-ai-translate' ),
				$run['id']
			),
			'title_action' => '<span class="pxat-badge pxat-badge--mode">'
				. esc_html( $scope . ( $run['batched'] ? ' · ' . __( 'batched', 'perxel-ai-translate' ) : '' ) )
				. '</span>',
			'note'         => esc_html(
				sprintf(
					/* translators: 1: source language, 2: target language, 3: relative start time. */
					__( '%1$s → %2$s · started %3$s', 'perxel-ai-translate' ),
					$src_label,
					$dest_label,
					Format::time_ago( $run['created_at'] )
				)
			),
			'rows'         => array(
				$progress_row,
				array(
					'label'   => __( 'Errors', 'perxel-ai-translate' ),
					'sub'     => '<span id="pxat-stat-skipped">' . esc_html( number_format_i18n( $counts['skipped'] ) ) . '</span> ' . esc_html__( 'skipped', 'perxel-ai-translate' ),
					'content' => '<span id="pxat-stat-error">' . esc_html( number_format_i18n( $counts['error'] ) ) . '</span>',
					'tone'    => $counts['error'] > 0 ? 'bad' : null,
				),
				array(
					'label'   => __( 'Cost', 'perxel-ai-translate' ),
					'sub'     => esc_html( $model_label ) . ' &middot; <span id="pxat-stat-tokens">' . esc_html( Format::unit_label( $counts['prompt_tokens'] + $counts['completion_tokens'] ) ) . '</span>',
					'content' => '<span id="pxat-stat-cost">' . esc_html( Format::cost( $counts['cost_usd'] ) ) . '</span>',
				),
				array(
					'label'   => __( 'Time', 'perxel-ai-translate' ),
					'content' => '<span id="pxat-stat-time">' . esc_html( Format::duration( $elapsed ) ) . '</span>',
				),
				array(
					'summary' => __( 'Activity log', 'perxel-ai-translate' ),
					'details' => \Perxel_UI::code( $log_text, array( 'id' => 'pxat-log' ) ),
				),
			),
		),
	)
);

if ( $counts['warnings'] > 0 ) {
	echo \Perxel_UI::notice(
		'warning',
		esc_html(
			sprintf(
				/* translators: %s: post count. */
				__( '%s finished with a warning - some data did not copy completely. See the Note column.', 'perxel-ai-translate' ),
				$posts_n( $counts['warnings'] )
			)
		),
		array( 'inline' => true )
	);
}
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
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
