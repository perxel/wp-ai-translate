<?php
/**
 * Confirm / cost-estimate screen for a bulk translation.
 *
 * @package Perxel_AI_Translate
 *
 * @var array  $rows                    Row data for every selected post.
 * @var array  $languages               WPML active languages.
 * @var string $source_lang             Locked to WPML's site default — read-only.
 * @var string $dest_lang
 * @var string $source_status           Selected source-post-status filter ('any' or a post_status slug).
 * @var array  $available_statuses      Distinct post_status => label found among the selected posts.
 * @var string $data_mode               'full' | 'custom'.
 * @var array  $custom_types            Subset of PXAT_Fields::DATA_TYPES, meaningful only when $data_mode === 'custom'.
 * @var array  $selected_types          Effective type list.
 * @var bool   $show_taxonomy_summary   Custom mode with Taxonomy selected.
 * @var array  $taxonomy_names          Taxonomies registered for this post type.
 * @var string $run_mode                'manual' | 'auto' | 'batch_auto'.
 * @var string $model_id
 * @var string $token
 * @var string $post_type
 * @var array  $models                  PXAT_OpenRouter::get_models().
 * @var int    $total_prompt_tokens
 * @var int    $total_completion_tokens
 * @var int    $total_tokens
 * @var float  $total_cost_usd
 * @var bool   $nothing_to_do
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$type_labels = array(
	'title'     => __( 'Title / Slug', 'perxel-ai-translate' ),
	'content'   => __( 'Excerpt & Content', 'perxel-ai-translate' ),
	'acf'       => __( 'ACF', 'perxel-ai-translate' ),
	'rankmath'  => __( 'Rank Math SEO', 'perxel-ai-translate' ),
	'taxonomy'  => __( 'Taxonomy', 'perxel-ai-translate' ),
	'thumbnail' => __( 'Featured image', 'perxel-ai-translate' ),
);
?>
<div class="wrap pxat-wrap">
	<h1><?php echo esc_html( sprintf( '%s - %s', PXAT_NAME, __( 'Confirm bulk translation', 'perxel-ai-translate' ) ) ); ?></h1>
	<?php if ( 1 === count( $models ) ) : ?>
		<p class="description">
			<?php
			printf(
				/* translators: %s: model label. */
				esc_html__( 'Model: %s', 'perxel-ai-translate' ),
				'<code>' . esc_html( $models[0]['label'] ) . '</code>'
			);
			?>
		</p>
	<?php endif; ?>

	<h2 class="pxat-step-title"><?php esc_html_e( 'Step 1: Configuration', 'perxel-ai-translate' ); ?></h2>
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" id="pxat-config-form">
		<input type="hidden" name="page" value="<?php echo esc_attr( PXAT_Confirm_Page::PAGE_SLUG ); ?>" />
		<input type="hidden" name="sel" value="<?php echo esc_attr( $token ); ?>" />
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Languages', 'perxel-ai-translate' ); ?></th>
				<td style="display:flex; align-items:center; gap:20px;">
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
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Source post status', 'perxel-ai-translate' ); ?></th>
				<td>
					<select style="width:280px;" name="source_status">
						<option value="any" <?php selected( $source_status, 'any' ); ?>><?php esc_html_e( 'Any status', 'perxel-ai-translate' ); ?></option>
						<?php foreach ( $available_statuses as $status => $label ) : ?>
							<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $source_status, $status ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="description"><?php esc_html_e( 'Only translate selected source posts with this status.', 'perxel-ai-translate' ); ?></span>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Data to process', 'perxel-ai-translate' ); ?></th>
				<td>
					<label>
						<input type="radio" name="data_mode" value="full" <?php checked( $data_mode, 'full' ); ?> id="pxat-data-mode-full" />
						<strong><?php esc_html_e( 'Full', 'perxel-ai-translate' ); ?></strong>
					</label>
					<span class="description"><?php esc_html_e( 'Title/Slug, Excerpt & Content, ACF, Rank Math, Taxonomy, Featured image. Untranslated posts are created.', 'perxel-ai-translate' ); ?></span>
					<br />
					<label>
						<input type="radio" name="data_mode" value="custom" <?php checked( $data_mode, 'custom' ); ?> id="pxat-data-mode-custom" />
						<strong><?php esc_html_e( 'Custom', 'perxel-ai-translate' ); ?></strong>
					</label>
					<span class="description"><?php esc_html_e( 'Pick individual data types below. Applies only to posts that already have a translation — no new posts are created.', 'perxel-ai-translate' ); ?></span>

					<div id="pxat-custom-types" style="margin-top:8px; padding-left:24px;">
						<?php foreach ( PXAT_Fields::DATA_TYPES as $type ) : ?>
							<label style="display:inline-block; margin-right:16px;">
								<input type="checkbox" name="custom_types[]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, $custom_types, true ) ); ?> />
								<?php echo esc_html( $type_labels[ $type ] ); ?>
							</label>
						<?php endforeach; ?>
					</div>

					<span class="description">
						<?php
						if ( $taxonomy_names ) {
							printf(
								/* translators: %s: comma-separated list of taxonomy slugs. */
								esc_html__( 'Taxonomies detected for this post type: %s', 'perxel-ai-translate' ),
								esc_html( implode( ', ', $taxonomy_names ) )
							);
						} else {
							esc_html_e( 'No taxonomies are registered for this post type.', 'perxel-ai-translate' );
						}
						?>
					</span>
					<br />
					<span class="description"><?php esc_html_e( 'Always overwrites: every selected data type is re-translated or re-copied from the source post, even if the destination already has content.', 'perxel-ai-translate' ); ?></span>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Run mode', 'perxel-ai-translate' ); ?></th>
				<td>
					<label>
						<input type="radio" name="run_mode" value="manual" <?php checked( $run_mode, 'manual' ); ?> />
						<strong><?php esc_html_e( 'Manual', 'perxel-ai-translate' ); ?></strong><br />
						<span class="description"><?php esc_html_e( 'Translate first, then review and apply — preview each translation and choose which posts to write into WordPress.', 'perxel-ai-translate' ); ?></span>
					</label>
					<br /><br />
					<label>
						<input type="radio" name="run_mode" value="auto" <?php checked( $run_mode, 'auto' ); ?> />
						<strong><?php esc_html_e( 'Auto', 'perxel-ai-translate' ); ?></strong><br />
						<span class="description"><?php esc_html_e( 'Translate and apply immediately — written straight into WordPress, no preview step.', 'perxel-ai-translate' ); ?></span>
					</label>
					<br /><br />
					<label>
						<input type="radio" name="run_mode" value="batch_auto" <?php checked( $run_mode, 'batch_auto' ); ?> />
						<strong><?php esc_html_e( 'Auto (batched)', 'perxel-ai-translate' ); ?></strong><br />
						<span class="description"><?php esc_html_e( 'Group several posts into each request to the model for faster throughput, then write straight into WordPress — no preview step. Best when you have many posts with short content each.', 'perxel-ai-translate' ); ?></span>
					</label>
				</td>
			</tr>
			<?php if ( count( $models ) > 1 ) : ?>
				<tr>
					<th scope="row"><label for="pxat-model-select"><?php esc_html_e( 'Model', 'perxel-ai-translate' ); ?></label></th>
					<td>
						<select name="model" id="pxat-model-select">
							<?php foreach ( $models as $model ) : ?>
								<option value="<?php echo esc_attr( $model['id'] ); ?>" <?php selected( $model_id, $model['id'] ); ?>>
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: model label, 2: input price, 3: output price. */
											__( '%1$s ($%2$s / $%3$s per 1M tokens)', 'perxel-ai-translate' ),
											$model['label'],
											$model['input'],
											$model['output']
										)
									);
									?>
								</option>
							<?php endforeach; ?>
						</select>
						<span class="description"><a href="https://openrouter.ai/models" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Browse models', 'perxel-ai-translate' ); ?></a></span>
					</td>
				</tr>
			<?php endif; ?>
		</table>
		<?php if ( 1 === count( $models ) ) : ?>
			<input type="hidden" name="model" value="<?php echo esc_attr( $models[0]['id'] ); ?>" />
		<?php endif; ?>
		<?php submit_button( __( 'Update', 'perxel-ai-translate' ), 'secondary', 'pxat_save_config', false ); ?>
	</form>

	<script>
	( function () {
		var customBox = document.getElementById( 'pxat-custom-types' );
		var customRadio = document.getElementById( 'pxat-data-mode-custom' );
		var fullRadio = document.getElementById( 'pxat-data-mode-full' );
		function syncCustomBoxState() {
			if ( customBox ) {
				customBox.style.opacity = customRadio && customRadio.checked ? '1' : '0.4';
			}
		}
		if ( fullRadio ) { fullRadio.addEventListener( 'change', syncCustomBoxState ); }
		if ( customRadio ) { customRadio.addEventListener( 'change', syncCustomBoxState ); }
		syncCustomBoxState();
	} )();
	</script>

	<h2 class="pxat-step-title"><?php esc_html_e( 'Step 2: Posts to process', 'perxel-ai-translate' ); ?></h2>
	<p>
		<strong>
		<?php
		printf(
			/* translators: %d: number of selected posts. */
			/* translators: %s: number of posts. */
			esc_html( _n( '%s post selected', '%s posts selected', count( $rows ), 'perxel-ai-translate' ) ),
			esc_html( number_format_i18n( count( $rows ) ) )
		);
		?>
		</strong>
	</p>

	<table class="widefat striped">
		<thead>
			<tr>
				<?php
				$source_lang_label = isset( $languages[ $source_lang ] ) ? ( isset( $languages[ $source_lang ]['translated_name'] ) ? $languages[ $source_lang ]['translated_name'] : $languages[ $source_lang ]['native_name'] ) : $source_lang;
				$dest_lang_label   = isset( $languages[ $dest_lang ] ) ? ( isset( $languages[ $dest_lang ]['translated_name'] ) ? $languages[ $dest_lang ]['translated_name'] : $languages[ $dest_lang ]['native_name'] ) : $dest_lang;
				?>
				<th><?php esc_html_e( 'ID', 'perxel-ai-translate' ); ?></th>
				<th><?php echo esc_html( sprintf( '%s (%s)', __( 'Source post', 'perxel-ai-translate' ), $source_lang_label ) ); ?></th>
				<th><?php echo esc_html( sprintf( '%s (%s)', __( 'Translation', 'perxel-ai-translate' ), $dest_lang_label ) ); ?></th>
				<th><?php esc_html_e( 'Status', 'perxel-ai-translate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row['source_post_id'] ); ?></td>
					<td>
						<?php if ( $row['source_url'] ) : ?>
							<a href="<?php echo esc_url( $row['source_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row['title'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $row['title'] ); ?>
						<?php endif; ?>
						<span class="description">(<?php echo esc_html( $row['source_status'] ); ?>)</span>
						<?php if ( ! empty( $row['taxonomy_summary']['source'] ) ) : ?>
							<div class="pxat-tax-summary">
								<?php foreach ( $row['taxonomy_summary']['source'] as $label => $names ) : ?>
									<div><?php echo esc_html( $label . ': ' . $names ); ?></div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $row['dest_exists'] ) : ?>
							<?php $dest_label = '' !== $row['dest_title'] ? $row['dest_title'] : sprintf( '(#%d, no title)', $row['dest_post_id'] ); ?>
							<?php if ( $row['dest_url'] ) : ?>
								<a href="<?php echo esc_url( $row['dest_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $dest_label ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $dest_label ); ?>
							<?php endif; ?>
							<span class="description">(<?php echo esc_html( $row['dest_status'] ); ?>)</span>
							<?php if ( ! empty( $row['taxonomy_summary']['dest'] ) ) : ?>
								<div class="pxat-tax-summary">
									<?php foreach ( $row['taxonomy_summary']['dest'] as $label => $names ) : ?>
										<div<?php echo null === $names ? ' style="color:#b32d2e; font-weight:600;"' : ''; ?>><?php echo esc_html( $label . ': ' . ( null === $names ? __( '— none yet —', 'perxel-ai-translate' ) : $names ) ); ?></div>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						<?php else : ?>
							<?php esc_html_e( 'not translated', 'perxel-ai-translate' ); ?>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( ! empty( $row['unresolved'] ) ) : ?>
							<span class="description" style="color:#b32d2e;">
								<?php
								printf(
									/* translators: %s: WPML source language code. */
									esc_html__( 'Skipped: no "%s" version of this post to use as the source', 'perxel-ai-translate' ),
									esc_html( $source_lang )
								);
								?>
							</span>
						<?php elseif ( ! $row['will_translate'] && 'custom' === $data_mode && ! $row['dest_exists'] ) : ?>
							<span class="description"><?php esc_html_e( 'Skipped (no translation to apply to — Custom mode does not create posts)', 'perxel-ai-translate' ); ?></span>
						<?php elseif ( ! $row['will_translate'] ) : ?>
							<span class="description"><?php esc_html_e( 'Skipped (nothing left to process)', 'perxel-ai-translate' ); ?></span>
						<?php elseif ( $row['structural_only'] ) : ?>
							<span class="description"><?php esc_html_e( 'Will apply (free, no model call)', 'perxel-ai-translate' ); ?></span>
						<?php elseif ( $row['tokens'] > 0 ) : ?>
							<?php echo esc_html( PXAT_Format::cost( $row['cost_usd'] ) ); ?>
							<span class="description">(<?php echo esc_html( PXAT_Format::unit_label( $row['tokens'] ) ); ?>)</span>
						<?php else : ?>
							<span class="description">&mdash;</span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<details style="margin-top:15px;">
		<summary><?php esc_html_e( 'Data that will be processed', 'perxel-ai-translate' ); ?></summary>

		<h3><?php esc_html_e( 'Translated / synced (always overwrites)', 'perxel-ai-translate' ); ?></h3>
		<?php if ( $selected_types ) : ?>
			<ul class="pxat-list">
				<?php foreach ( $selected_types as $type ) : ?>
					<li><?php echo esc_html( $type_labels[ $type ] ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p><?php esc_html_e( 'No data selected.', 'perxel-ai-translate' ); ?></p>
		<?php endif; ?>
	</details>

	<p class="description" id="pxat-cost-estimate">
		<?php
		if ( $nothing_to_do ) {
			esc_html_e( 'Nothing to process with the current selection.', 'perxel-ai-translate' );
		} elseif ( 0 === $total_tokens ) {
			esc_html_e( 'Will apply to the posts above — no model call, no cost.', 'perxel-ai-translate' );
		} else {
			$model = PXAT_OpenRouter::get_model( $model_id );
			printf(
				/* translators: 1: estimated cost, 2: token/word volume, 3: model label. */
				esc_html__( 'Estimated cost for this run is about %1$s (%2$s) with %3$s (based on the length of the source posts and the selected model\'s price).', 'perxel-ai-translate' ),
				esc_html( PXAT_Format::cost( $total_cost_usd ) ),
				esc_html( PXAT_Format::unit_label( $total_tokens ) ),
				esc_html( $model['label'] )
			);
		}
		?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="pxat_create_batch" />
		<input type="hidden" name="sel" value="<?php echo esc_attr( $token ); ?>" />
		<input type="hidden" name="dest_lang" value="<?php echo esc_attr( $dest_lang ); ?>" />
		<input type="hidden" name="source_status" value="<?php echo esc_attr( $source_status ); ?>" />
		<input type="hidden" name="data_mode" value="<?php echo esc_attr( $data_mode ); ?>" />
		<?php foreach ( $custom_types as $type ) : ?>
			<input type="hidden" name="custom_types[]" value="<?php echo esc_attr( $type ); ?>" />
		<?php endforeach; ?>
		<input type="hidden" name="run_mode" value="<?php echo esc_attr( $run_mode ); ?>" />
		<input type="hidden" name="model" value="<?php echo esc_attr( $model_id ); ?>" />
		<?php wp_nonce_field( 'pxat_create_batch' ); ?>

		<?php
		submit_button(
			sprintf(
				/* translators: %d: number of posts. */
				_n( 'Start (%s post)', 'Start (%s posts)', count( $rows ), 'perxel-ai-translate' ),
				number_format_i18n( count( $rows ) )
			),
			'primary',
			'submit',
			true,
			array_merge( array( 'id' => 'pxat-submit-btn' ), $nothing_to_do ? array( 'disabled' => 'disabled' ) : array() )
		);
		?>
		<span class="spinner" id="pxat-submit-spinner"></span>
		<a href="<?php echo esc_url( wp_get_referer() ? wp_get_referer() : admin_url() ); ?>" class="button"><?php esc_html_e( 'Cancel', 'perxel-ai-translate' ); ?></a>
	</form>

	<script>
	( function () {
		var btn = document.getElementById( 'pxat-submit-btn' );
		var spinner = document.getElementById( 'pxat-submit-spinner' );
		var form = btn ? btn.closest( 'form' ) : null;
		if ( form && spinner ) {
			form.addEventListener( 'submit', function () {
				spinner.classList.add( 'is-active' );
				btn.disabled = true;
			} );
		}
	} )();
	</script>

	<?php
	$footer_exclude = '';
	include PXAT_DIR . '/views/footer.php';
	?>
</div>
