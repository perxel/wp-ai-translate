<?php
/**
 * Dashboard screen.
 *
 * @package Perxel_AI_Translate
 *
 * @var string $state         'needs_setup' | 'ready'
 * @var bool   $has_api_key
 * @var bool   $enough_langs
 * @var string $settings_url
 * @var array  $post_types    slug => plural label
 * @var int|null $active_run_id
 * @var array  $totals
 * @var array  $recent        Run rows.
 * @var array  $languages
 * @var string $default_lang
 * @var string $nonce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Perxel\AITranslate\Admin;
use Perxel\AITranslate\Format;
use Perxel\AITranslate\OpenRouter;
use Perxel\AITranslate\Runs;
use Perxel\AITranslate\Wpml;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Perxel_UI escapes structure; dynamic values escaped inline.

/* --- Setup gate --------------------------------------------------- */

if ( 'needs_setup' === $state ) {
	$rows = array();

	$rows[] = array(
		'icon'    => $has_api_key ? 'good' : 'bad',
		'label'   => __( 'Add your OpenRouter API key', 'perxel-ai-translate' ),
		'sub'     => esc_html__( 'Translations run through your own OpenRouter account.', 'perxel-ai-translate' ),
		'content' => $has_api_key ? esc_html__( 'Done', 'perxel-ai-translate' ) : '<a class="button button-primary" href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Open Settings', 'perxel-ai-translate' ) . '</a>',
	);

	$rows[] = array(
		'icon'    => $enough_langs ? 'good' : 'bad',
		'label'   => __( 'WPML with two or more active languages', 'perxel-ai-translate' ),
		'sub'     => esc_html__( 'The plugin reads languages and links translations through WPML.', 'perxel-ai-translate' ),
		'content' => $enough_langs ? esc_html__( 'Done', 'perxel-ai-translate' ) : esc_html__( 'Not ready', 'perxel-ai-translate' ),
	);

	echo \Perxel_UI::notice( 'info', esc_html__( 'Finish setup to start translating.', 'perxel-ai-translate' ) );
	echo \Perxel_UI::rows(
		array(
			array(
				'title' => __( 'Setup', 'perxel-ai-translate' ),
				'rows'  => $rows,
			),
		)
	);
	return;
}

/* --- Unfinished run --------------------------------------------------- */

if ( $active_run_id ) {
	$resume = '<a class="button button-primary" href="' . esc_url(
		add_query_arg(
			array(
				'page'   => Admin::PAGE_PROGRESS,
				'run_id' => $active_run_id,
			),
			admin_url( 'admin.php' )
		)
	) . '">' . esc_html__( 'Resume', 'perxel-ai-translate' ) . '</a>';

	echo \Perxel_UI::notice( 'warning', esc_html__( 'A translation run is unfinished.', 'perxel-ai-translate' ) . ' ' . $resume );
}

/* --- Start a translation --------------------------------------------- */

$pt_links = array();
foreach ( $post_types as $slug => $label ) {
	$pt_links[] = '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . $slug ) ) . '">' . esc_html( $label ) . '</a>';
}

echo \Perxel_UI::notice(
	'info',
	esc_html__( 'Translating a lot? Open a post list, tick the rows, and choose "Perxel AI Translate…" from Bulk actions.', 'perxel-ai-translate' )
	. ( $pt_links ? ' &nbsp;' . implode( ' · ', $pt_links ) : '' )
);

$pt_options = '';
foreach ( $post_types as $slug => $label ) {
	$pt_options .= '<option value="' . esc_attr( $slug ) . '">' . esc_html( $label ) . '</option>';
}
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="pxat-picker" data-nonce="<?php echo esc_attr( $nonce ); ?>">
	<input type="hidden" name="action" value="pxat_dashboard_select" />
	<?php wp_nonce_field( 'pxat_dashboard_select' ); ?>

	<?php
	echo \Perxel_UI::rows(
		array(
			array(
				'title' => __( 'Or pick a few posts', 'perxel-ai-translate' ),
				'rows'  => array(
					array(
						'label'   => __( 'Post type', 'perxel-ai-translate' ),
						'content' => '<select name="post_type" id="pxat-picker-type">' . $pt_options . '</select>',
					),
					array(
						'label'   => __( 'Search', 'perxel-ai-translate' ),
						'sub'     => '<span id="pxat-picker-selected" class="pxat-muted"></span>',
						'content' => '<input type="search" id="pxat-picker-search" class="regular-text" placeholder="' . esc_attr__( 'Type a title…', 'perxel-ai-translate' ) . '" autocomplete="off" />',
					),
					array(
						'summary' => __( 'Paste a list of IDs instead', 'perxel-ai-translate' ),
						'details' => '<textarea name="paste_ids" rows="3" class="large-text code" placeholder="144777, 142887, …"></textarea>',
					),
				),
				'note'  => '<span id="pxat-picker-results"></span>',
			),
		)
	);
	?>
	<p><button type="submit" class="button button-primary" id="pxat-picker-go"><?php esc_html_e( 'Continue', 'perxel-ai-translate' ); ?></button></p>
</form>

<?php
/* --- At a glance ------------------------------------------------------ */

$tiles = array(
	array(
		'label' => __( 'Runs', 'perxel-ai-translate' ),
		'value' => esc_html( number_format_i18n( $totals['runs'] ) ),
	),
	array(
		'label' => __( 'Posts translated', 'perxel-ai-translate' ),
		'value' => esc_html( number_format_i18n( $totals['posts_done'] ) ),
	),
	array(
		'label' => __( 'Spent', 'perxel-ai-translate' ),
		'value' => esc_html( Format::cost( $totals['cost_usd'] ) ),
	),
	array(
		'label' => __( 'Volume', 'perxel-ai-translate' ),
		'value' => esc_html( Format::unit_label( $totals['tokens'] ) ),
	),
);

if ( $totals['warnings'] > 0 || $totals['apply_errors'] > 0 ) {
	$tiles[] = array(
		'label' => __( 'Issues', 'perxel-ai-translate' ),
		'value' => esc_html( number_format_i18n( $totals['warnings'] + $totals['apply_errors'] ) ),
		'sub'   => esc_html__( 'warnings + errors across all runs', 'perxel-ai-translate' ),
		'tone'  => 'warn',
	);
}

echo '<h2 class="pxat-h2">' . esc_html__( 'At a glance', 'perxel-ai-translate' ) . '</h2>';
echo \Perxel_UI::stat_grid( $tiles );

/* --- Recent runs ---------------------------------------------------- */

if ( $recent ) {
	$rows = array();
	foreach ( $recent as $run ) {
		$counts = Runs::counts( $run['id'] );
		$rows[] = array(
			'summary' => sprintf(
				/* translators: 1: run id, 2: source lang, 3: dest lang. */
				__( 'Run #%1$d · %2$s → %3$s', 'perxel-ai-translate' ),
				$run['id'],
				Wpml::language_label( $languages, $run['source_lang'] ),
				Wpml::language_label( $languages, $run['dest_lang'] )
			),
			'sub'     => esc_html( Format::time_ago( $run['created_at'] ) . ' · ' . OpenRouter::get_model( $run['model'] )['label'] ),
			'content' => esc_html( number_format_i18n( $counts['done'] ) . ' / ' . number_format_i18n( $counts['total'] ) ),
			'details' => '<a class="button button-small" href="' . esc_url(
				add_query_arg(
					array(
						'page'   => Admin::PAGE_PROGRESS,
						'run_id' => $run['id'],
					),
					admin_url( 'admin.php' )
				)
			) . '">' . esc_html__( 'Open run', 'perxel-ai-translate' ) . '</a>',
		);
	}

	echo \Perxel_UI::rows(
		array(
			array(
				'title' => __( 'Recent runs', 'perxel-ai-translate' ),
				'rows'  => $rows,
				'note'  => '<a href="' . esc_url( admin_url( 'admin.php?page=' . Admin::PAGE_HISTORY ) ) . '">' . esc_html__( 'All runs', 'perxel-ai-translate' ) . ' &rarr;</a>',
			),
		)
	);
}

// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
