<?php
/**
 * Confirm screen.
 *
 * @package Perxel_AI_Translate
 *
 * @var string $source_lang
 * @var string $dest_lang
 * @var string $data_mode
 * @var array  $custom_types
 * @var array  $model          Settings::model() - { id, label, input, output, … }
 * @var bool   $model_verified
 * @var string $settings_url
 * @var string $ids_csv
 * @var string $post_type
 * @var string $post_type_label
 * @var int    $selected_count
 * @var array  $languages
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

echo '<p class="pxat-lead">' . esc_html(
	sprintf(
		/* translators: 1: number of posts, 2: post type name. */
		__( '%1$s %2$s selected.', 'perxel-ai-translate' ),
		number_format_i18n( $selected_count ),
		$post_type_label
	)
) . '</p>';

/* --- Configuration (GET self-submit, auto-applied) ---------------- */

$status_label = static function ( $slug ) {
	if ( '' === $slug ) {
		return '';
	}
	$object = get_post_status_object( $slug );
	return $object ? $object->label : $slug;
};

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

$data_radios =
	'<label class="pxat-radio"><input type="radio" name="data_mode" value="full" ' . checked( $data_mode, 'full', false ) . ' /> '
		. esc_html__( 'Everything', 'perxel-ai-translate' ) . '</label>'
	. '<label class="pxat-radio"><input type="radio" name="data_mode" value="custom" ' . checked( $data_mode, 'custom', false ) . ' /> '
		. esc_html__( 'Specific fields', 'perxel-ai-translate' ) . '</label>';

$data_sub = 'custom' === $data_mode
	? esc_html__( 'Only updates posts that already have a translation. Nothing new is created.', 'perxel-ai-translate' )
	: esc_html__( 'Title, content, ACF, Rank Math, taxonomy, featured image. Missing translations are created.', 'perxel-ai-translate' );

$model_note = $model['input'] > 0
	? esc_html( sprintf( /* translators: 1: input price, 2: output price. */ __( '$%1$s in / $%2$s out per 1M tokens', 'perxel-ai-translate' ), $model['input'], $model['output'] ) )
	: esc_html__( 'pricing not checked yet', 'perxel-ai-translate' );

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
		'label'   => __( 'Model', 'perxel-ai-translate' ),
		'sub'     => $model_note . ' · <a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'change in Settings', 'perxel-ai-translate' ) . '</a>',
		'tone'    => $model_verified ? null : 'warn',
		'content' => esc_html( $model['label'] ),
	),
	array(
		'label'   => __( 'What to translate', 'perxel-ai-translate' ),
		'sub'     => $data_sub,
		'content' => $data_radios,
	),
	array(
		'label'   => __( 'Fields', 'perxel-ai-translate' ),
		'sub'     => esc_html__( 'Pick the data types to translate.', 'perxel-ai-translate' ),
		'content' => '<div id="pxat-fields">' . $type_pills . '</div>',
	),
);
?>
<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" id="pxat-config-form">
	<input type="hidden" name="page" value="<?php echo esc_attr( Admin::PAGE_CONFIRM ); ?>" />
	<input type="hidden" name="pxat_save_config" value="1" />
	<input type="hidden" name="ids" value="<?php echo esc_attr( $ids_csv ); ?>" />
	<input type="hidden" name="post_type" value="<?php echo esc_attr( $post_type ); ?>" />
	<?php echo \Perxel_UI::rows( $config_rows ); ?>
	<p class="pxat-config-status" id="pxat-config-status" hidden>
		<span class="pxat-spin" aria-hidden="true"></span>
		<?php esc_html_e( 'Updating the plan…', 'perxel-ai-translate' ); ?>
	</p>
	<noscript>
		<p><button type="submit" class="button"><?php esc_html_e( 'Update plan', 'perxel-ai-translate' ); ?></button></p>
	</noscript>
</form>

<?php
/* --- Review & start ---------------------------------------------- */

if ( 0 === $eligible_count ) {
	echo \Perxel_UI::notice( 'warning', esc_html__( 'Nothing to translate with the current selection.', 'perxel-ai-translate' ) );
} elseif ( 0 === $total_tokens ) {
	echo \Perxel_UI::notice( 'info', esc_html__( 'Structural copy only - no model call, no cost.', 'perxel-ai-translate' ) );
}

$source_label = Wpml::language_label( $languages, $source_lang );
$dest_label   = Wpml::language_label( $languages, $dest_lang );
?>
<div class="pxat-table-wrap">
<table class="widefat striped">
	<thead>
		<tr>
			<th class="pxat-col-num">#</th>
			<th><?php echo esc_html( sprintf( '%s (%s)', __( 'Source post', 'perxel-ai-translate' ), $source_label ) ); ?></th>
			<th><?php echo esc_html( sprintf( '%s (%s)', __( 'Translation', 'perxel-ai-translate' ), $dest_label ) ); ?></th>
			<th><?php esc_html_e( 'Plan', 'perxel-ai-translate' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php $num = 0; ?>
		<?php foreach ( $rows as $row ) : ?>
			<?php ++$num; ?>
			<tr>
				<td class="pxat-col-num"><?php echo (int) $num; ?></td>
				<td>
					<?php if ( $row['source_url'] ) : ?>
						<a href="<?php echo esc_url( $row['source_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row['title'] ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $row['title'] ); ?>
					<?php endif; ?>
					<span class="pxat-muted">(<?php echo esc_html( $status_label( $row['status'] ) ); ?>)</span>
				</td>
				<td>
					<?php
					if ( 'unresolved' === $row['state'] ) {
						echo '<span class="pxat-muted">' . esc_html__( 'No translation', 'perxel-ai-translate' ) . '</span>';
					} elseif ( $row['dest_exists'] ) {
						$dl = '' !== $row['dest_title'] ? $row['dest_title'] : __( '(no title)', 'perxel-ai-translate' );
						if ( $row['dest_url'] ) {
							echo '<a href="' . esc_url( $row['dest_url'] ) . '" target="_blank" rel="noopener">' . esc_html( $dl ) . '</a>';
						} else {
							echo esc_html( $dl );
						}

						if ( 'skip' === $row['state'] ) {
							$line = __( 'No change', 'perxel-ai-translate' );
						} elseif ( $row['dest_status'] !== $row['status'] ) {
							$line = sprintf(
								/* translators: 1: current translation status, 2: status it will take after the run. */
								__( 'Overwrite, %1$s → %2$s', 'perxel-ai-translate' ),
								esc_html( $status_label( $row['dest_status'] ) ),
								esc_html( $status_label( $row['status'] ) )
							);
						} else {
							$line = sprintf(
								/* translators: %s: translation status. */
								__( 'Overwrite (%s)', 'perxel-ai-translate' ),
								esc_html( $status_label( $row['dest_status'] ) )
							);
						}
						echo '<br /><span class="pxat-muted">' . $line;
						if ( '' !== $row['dest_modified'] ) {
							echo ' · ' . esc_html(
								sprintf(
									/* translators: %s: how long ago the translation was last edited, e.g. "3 days ago". */
									__( 'edited %s', 'perxel-ai-translate' ),
									Format::time_ago( $row['dest_modified'] )
								)
							);
						}
						echo '</span>';
					} elseif ( 'skip' === $row['state'] ) {
						echo '<span class="pxat-muted">' . esc_html__( 'Not created', 'perxel-ai-translate' ) . '</span>';
					} else {
						echo '<span class="pxat-muted">' . esc_html__( 'New translation', 'perxel-ai-translate' ) . '</span>';
						echo '<br /><span class="pxat-muted">' . esc_html(
							sprintf(
								/* translators: %s: status the new translation will be created with. */
								__( 'will be created (%s)', 'perxel-ai-translate' ),
								$status_label( $row['status'] )
							)
						) . '</span>';
					}
					?>
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
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
</div>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="pxat-start-form">
	<input type="hidden" name="action" value="pxat_create_run" />
	<input type="hidden" name="ids" value="<?php echo esc_attr( $ids_csv ); ?>" />
	<input type="hidden" name="post_type" value="<?php echo esc_attr( $post_type ); ?>" />
	<input type="hidden" name="dest_lang" value="<?php echo esc_attr( $dest_lang ); ?>" />
	<input type="hidden" name="data_mode" value="<?php echo esc_attr( $data_mode ); ?>" />
	<?php foreach ( ( 'full' === $data_mode ? array() : $custom_types ) as $t ) : ?>
		<input type="hidden" name="custom_types[]" value="<?php echo esc_attr( $t ); ?>" />
	<?php endforeach; ?>
	<?php wp_nonce_field( 'pxat_create_run' ); ?>
	<p>
		<?php if ( $eligible_count > 0 ) : ?>
			<button type="submit" class="button button-primary button-hero">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: post count, 2: cost. */
						__( 'Translate and apply - %1$s (%2$s)', 'perxel-ai-translate' ),
						$posts_phrase,
						Format::cost( $total_cost_usd )
					)
				);
				?>
			</button>
		<?php endif; ?>
		<a class="button" href="<?php echo esc_url( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . Admin::PAGE_DASHBOARD ) ); ?>"><?php esc_html_e( 'Cancel', 'perxel-ai-translate' ); ?></a>
	</p>
	<p class="pxat-muted"><?php esc_html_e( 'Review each result in the editor afterwards.', 'perxel-ai-translate' ); ?></p>
</form>

<?php
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
