<?php
/**
 * Confirm screen.
 *
 * @package Perxel_AI_Translate
 *
 * @var string $source_lang
 * @var string $dest_lang
 * @var string $source_status
 * @var string $data_mode
 * @var array  $custom_types
 * @var bool   $batched
 * @var array  $model          Settings::model() - { id, label, input, output, … }
 * @var bool   $model_verified
 * @var string $settings_url
 * @var string $post_type
 * @var string $post_type_label
 * @var int    $cart_count
 * @var array|null $conflict
 * @var string $clear_url
 * @var array  $languages
 * @var array  $available_statuses
 * @var array  $rows
 * @var int    $total_tokens
 * @var float  $total_cost_usd
 * @var int    $eligible_count
 * @var array  $type_labels
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Perxel\AITranslate\Admin;
use Perxel\AITranslate\Fields;
use Perxel\AITranslate\Format;
use Perxel\AITranslate\Wpml;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Perxel_UI escapes structure; dynamic values escaped inline.

$posts_phrase = sprintf(
	/* translators: %s: number of posts. */
	_n( '%s post', '%s posts', $eligible_count, 'perxel-ai-translate' ),
	number_format_i18n( $eligible_count )
);

if ( $conflict ) {
	echo \Perxel_UI::notice(
		'warning',
		esc_html__( 'Those posts were a different post type from the ones already in your cart, so nothing was added. Start the run or clear the cart, then add them.', 'perxel-ai-translate' )
	);
}

echo \Perxel_UI::notice(
	'info',
	esc_html(
		sprintf(
			/* translators: 1: number of posts, 2: post type name. */
			__( '%1$s %2$s in your translation cart.', 'perxel-ai-translate' ),
			number_format_i18n( $cart_count ),
			$post_type_label
		)
	)
	. ' <a href="' . esc_url( $clear_url ) . '">' . esc_html__( 'Clear cart', 'perxel-ai-translate' ) . '</a>'
);

echo '<p class="pxat-step">' . esc_html__( 'Step 1 of 2 - configure', 'perxel-ai-translate' ) . '</p>';

/* --- Step 1: configuration (GET self-submit) ------------------------ */

$status_select  = '<select name="source_status">';
$status_select .= '<option value="any"' . selected( $source_status, 'any', false ) . '>' . esc_html__( 'Any status', 'perxel-ai-translate' ) . '</option>';
foreach ( $available_statuses as $slug => $label ) {
	$status_select .= '<option value="' . esc_attr( $slug ) . '"' . selected( $source_status, $slug, false ) . '>' . esc_html( $label ) . '</option>';
}
$status_select .= '</select>';

$dest_select = '<select name="dest_lang">';
foreach ( $languages as $code => $lang ) {
	if ( $code === $source_lang ) {
		continue;
	}
	$dest_select .= '<option value="' . esc_attr( $code ) . '"' . selected( $dest_lang, $code, false ) . '>' . esc_html( Wpml::language_label( $languages, $code ) ) . '</option>';
}
$dest_select .= '</select>';

$type_options = array();
foreach ( Fields::DATA_TYPES as $type ) {
	$type_options[] = array(
		'value' => $type,
		'label' => $type_labels[ $type ],
	);
}
$type_pills = \Perxel_UI::checkbox_group(
	array(
		'name'     => 'custom_types',
		'form'     => 'pxat-config-form',
		'options'  => $type_options,
		'selected' => 'full' === $data_mode ? Fields::DATA_TYPES : $custom_types,
	)
);

$data_rows = array(
	array(
		'label'   => __( 'Everything', 'perxel-ai-translate' ),
		'sub'     => esc_html__( 'Title, content, ACF, Rank Math, taxonomy, featured image. Missing translations are created.', 'perxel-ai-translate' ),
		'content' => '<input type="radio" name="data_mode" value="full" class="pxui-checkbox" ' . checked( $data_mode, 'full', false ) . ' />',
	),
	array(
		'summary' => __( 'Choose specific fields', 'perxel-ai-translate' ),
		'sub'     => esc_html__( 'Only affects posts that already have a translation - nothing new is created.', 'perxel-ai-translate' ),
		'content' => '<input type="radio" name="data_mode" value="custom" class="pxui-checkbox" ' . checked( $data_mode, 'custom', false ) . ' />',
		'details' => '<div id="pxat-custom-types">' . $type_pills . '</div>',
		'open'    => 'custom' === $data_mode,
	),
);

$config_rows = array(
	array(
		'label'   => __( 'Translate into', 'perxel-ai-translate' ),
		'sub'     => sprintf(
			/* translators: %s: source language name. */
			esc_html__( 'From %s (WPML site default, locked).', 'perxel-ai-translate' ),
			esc_html( Wpml::language_label( $languages, $source_lang ) )
		),
		'content' => $dest_select,
	),
	array(
		'label'   => __( 'Only source posts with status', 'perxel-ai-translate' ),
		'content' => $status_select,
	),
);

$model_note = $model['input'] > 0
	? esc_html( sprintf( /* translators: 1: input price, 2: output price. */ __( '$%1$s in / $%2$s out per 1M tokens', 'perxel-ai-translate' ), $model['input'], $model['output'] ) )
	: esc_html__( 'pricing not checked yet', 'perxel-ai-translate' );

$config_rows[] = array(
	'label'   => __( 'Model', 'perxel-ai-translate' ),
	'sub'     => $model_note . ' · <a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'change in Settings', 'perxel-ai-translate' ) . '</a>',
	'tone'    => $model_verified ? null : 'warn',
	'content' => esc_html( $model['label'] ),
);

$config_rows[] = array(
	'label'   => __( 'Faster batched requests', 'perxel-ai-translate' ),
	'sub'     => esc_html__( 'Send several posts per model request. Faster for many short posts; one bad response affects a group.', 'perxel-ai-translate' ),
	'content' => \Perxel_UI::toggle(
		array(
			'name'    => 'batched',
			'form'    => 'pxat-config-form',
			'checked' => $batched,
			'label'   => __( 'Faster batched requests', 'perxel-ai-translate' ),
		)
	),
);
?>
<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" id="pxat-config-form">
	<input type="hidden" name="page" value="<?php echo esc_attr( Admin::PAGE_CONFIRM ); ?>" />
	<input type="hidden" name="pxat_save_config" value="1" />
	<?php
	echo \Perxel_UI::rows(
		array(
			array(
				'title' => __( 'Settings', 'perxel-ai-translate' ),
				'rows'  => $config_rows,
			),
		)
	);
	echo \Perxel_UI::rows(
		array(
			array(
				'title' => __( 'What to translate', 'perxel-ai-translate' ),
				'rows'  => $data_rows,
			),
		)
	);
	?>
	<p><button type="submit" class="button" id="pxat-config-update"><?php esc_html_e( 'Update preview', 'perxel-ai-translate' ); ?></button></p>
</form>

<?php
/* --- Step 2: posts ------------------------------------------------- */

echo '<p class="pxat-step">' . esc_html__( 'Step 2 of 2 - review & start', 'perxel-ai-translate' ) . '</p>';

if ( 0 === $eligible_count ) {
	echo \Perxel_UI::notice( 'warning', esc_html__( 'Nothing to translate with the current selection.', 'perxel-ai-translate' ) );
} elseif ( $total_tokens > 0 ) {
	echo \Perxel_UI::notice(
		'info',
		esc_html(
			sprintf(
				/* translators: 1: post count, 2: cost, 3: volume. */
				__( 'About to translate %1$s for roughly %2$s (%3$s). Review each result in the editor afterwards.', 'perxel-ai-translate' ),
				$posts_phrase,
				Format::cost( $total_cost_usd ),
				Format::unit_label( $total_tokens )
			)
		)
	);
} else {
	echo \Perxel_UI::notice( 'info', esc_html__( 'These posts will be updated with no model call - structural copy only, no cost.', 'perxel-ai-translate' ) );
}

$source_label = Wpml::language_label( $languages, $source_lang );
$dest_label   = Wpml::language_label( $languages, $dest_lang );
?>
<div class="pxat-table-wrap">
<table class="widefat striped">
	<thead>
		<tr>
			<th><?php esc_html_e( 'ID', 'perxel-ai-translate' ); ?></th>
			<th><?php echo esc_html( sprintf( '%s (%s)', __( 'Source post', 'perxel-ai-translate' ), $source_label ) ); ?></th>
			<th><?php echo esc_html( sprintf( '%s (%s)', __( 'Translation', 'perxel-ai-translate' ), $dest_label ) ); ?></th>
			<th><?php esc_html_e( 'Plan', 'perxel-ai-translate' ); ?></th>
			<th class="pxat-col-remove"><span class="screen-reader-text"><?php esc_html_e( 'Remove from cart', 'perxel-ai-translate' ); ?></span></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $rows as $row ) : ?>
			<tr>
				<td><?php echo (int) $row['id']; ?></td>
				<td>
					<?php if ( $row['source_url'] ) : ?>
						<a href="<?php echo esc_url( $row['source_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row['title'] ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $row['title'] ); ?>
					<?php endif; ?>
					<span class="pxat-muted">(<?php echo esc_html( $row['status'] ); ?>)</span>
				</td>
				<td>
					<?php if ( $row['dest_exists'] ) : ?>
						<?php $dl = '' !== $row['dest_title'] ? $row['dest_title'] : __( '(no title)', 'perxel-ai-translate' ); ?>
						<?php if ( $row['dest_url'] ) : ?>
							<a href="<?php echo esc_url( $row['dest_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $dl ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $dl ); ?>
						<?php endif; ?>
					<?php else : ?>
						<span class="pxat-muted"><?php esc_html_e( 'will be created', 'perxel-ai-translate' ); ?></span>
					<?php endif; ?>
				</td>
				<td>
					<?php
					switch ( $row['state'] ) {
						case 'unresolved':
							echo '<span class="pxat-inline-error">' . esc_html__( 'Skipped - no source-language version', 'perxel-ai-translate' ) . '</span>';
							break;
						case 'skip':
							echo '<span class="pxat-muted">' . esc_html( $row['skip_reason'] ) . '</span>';
							break;
						case 'structural':
							echo '<span class="pxat-muted">' . esc_html__( 'Copy only - no model call', 'perxel-ai-translate' ) . '</span>';
							break;
						default:
							echo esc_html( Format::cost( $row['cost_usd'] ) ) . ' <span class="pxat-muted">(' . esc_html( Format::unit_label( $row['tokens'] ) ) . ')</span>';
					}
					?>
				</td>
				<td class="pxat-col-remove">
					<a class="pxat-remove" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pxat_cart_remove&post_id=' . (int) $row['id'] ), 'pxat_cart_remove' ) ); ?>" aria-label="<?php esc_attr_e( 'Remove from cart', 'perxel-ai-translate' ); ?>"><?php esc_html_e( 'Remove', 'perxel-ai-translate' ); ?></a>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
</div>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="pxat-start-form">
	<input type="hidden" name="action" value="pxat_create_run" />
	<input type="hidden" name="dest_lang" value="<?php echo esc_attr( $dest_lang ); ?>" />
	<input type="hidden" name="source_status" value="<?php echo esc_attr( $source_status ); ?>" />
	<input type="hidden" name="data_mode" value="<?php echo esc_attr( $data_mode ); ?>" />
	<?php foreach ( ( 'full' === $data_mode ? array() : $custom_types ) as $t ) : ?>
		<input type="hidden" name="custom_types[]" value="<?php echo esc_attr( $t ); ?>" />
	<?php endforeach; ?>
	<input type="hidden" name="batched" value="<?php echo $batched ? '1' : '0'; ?>" />
	<?php wp_nonce_field( 'pxat_create_run' ); ?>
	<p>
		<?php if ( $eligible_count > 0 ) : ?>
			<button type="submit" class="button button-primary button-hero">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: post count, 2: cost. */
						__( 'Start - %1$s (%2$s)', 'perxel-ai-translate' ),
						$posts_phrase,
						Format::cost( $total_cost_usd )
					)
				);
				?>
			</button>
		<?php endif; ?>
		<a class="button" href="<?php echo esc_url( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . Admin::PAGE_DASHBOARD ) ); ?>"><?php esc_html_e( 'Cancel', 'perxel-ai-translate' ); ?></a>
	</p>
</form>

<?php
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
