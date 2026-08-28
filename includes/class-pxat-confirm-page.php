<?php
/**
 * Confirm / cost-estimate screen controller.
 *
 * @package Perxel_AI_Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PXAT_Confirm_Page {

	const PAGE_SLUG = 'pxat-confirm';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_pxat_create_batch', array( __CLASS__, 'handle_submit' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function enqueue_assets( $hook ) {
		unset( $hook );

		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen switch.
			return;
		}

		wp_enqueue_style( 'pxat-admin', PXAT_URL . '/assets/css/admin.css', array(), PXAT_VERSION );
	}

	public static function register_menu() {
		// Parent null = headless page: reachable at admin.php?page=... without a nav item (WP 5.3+).
		add_submenu_page( null, __( 'Confirm translation', 'perxel-ai-translate' ), __( 'Confirm translation', 'perxel-ai-translate' ), 'manage_options', self::PAGE_SLUG, array( __CLASS__, 'render_page' ) );
	}

	protected static function get_selection( $token ) {
		if ( ! $token ) {
			return null;
		}
		return get_transient( 'pxat_sel_' . $token );
	}

	/**
	 * Reads Step 1's config from $_GET, falling back to defaults on first
	 * load (no "pxat_save_config" in the querystring yet, i.e. the "Update"
	 * button hasn't been clicked). Once saved, every field is taken
	 * at face value, including an empty custom_types (browsers omit
	 * unchecked checkboxes from the querystring entirely).
	 */
	protected static function read_config( array $languages, array $available_statuses ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation params, each sanitized on use.
		$saved = isset( $_GET['pxat_save_config'] );

		// Never user-choosable — locked to WPML's own site default language.
		// A free-choice dropdown here would let a swapped/misconfigured pair
		// resolve the "destination" to what's actually the original post
		// (PXAT_Post_Sync::get_or_create_dest_post()'s same-post guard only
		// catches WPML resolving back to literally the same post, not a
		// different, real post reached via a wrong source_lang). Locking
		// this out removes the free-choice input entirely, not just adds a
		// smarter check.
		$source_lang = PXAT_WPML::get_default_language();

		$other_langs = array_diff( array_keys( $languages ), array( $source_lang ) );
		$dest_lang   = $saved && isset( $_GET['dest_lang'] ) ? sanitize_text_field( wp_unslash( $_GET['dest_lang'] ) ) : reset( $other_langs );
		if ( ! isset( $languages[ $dest_lang ] ) || $dest_lang === $source_lang ) {
			$dest_lang = reset( $other_langs );
		}

		$source_status = $saved && isset( $_GET['source_status'] ) ? sanitize_text_field( wp_unslash( $_GET['source_status'] ) ) : 'publish';
		if ( 'any' !== $source_status && ! isset( $available_statuses[ $source_status ] ) ) {
			$source_status = 'publish';
		}

		$data_mode = $saved && isset( $_GET['data_mode'] ) ? sanitize_text_field( wp_unslash( $_GET['data_mode'] ) ) : 'full';
		if ( ! in_array( $data_mode, array( 'full', 'custom' ), true ) ) {
			$data_mode = 'full';
		}

		$custom_types = array();
		if ( $saved && isset( $_GET['custom_types'] ) ) {
			$custom_types = array_values( array_intersect( array_map( 'sanitize_text_field', (array) wp_unslash( $_GET['custom_types'] ) ), PXAT_Fields::DATA_TYPES ) );
		}

		$run_mode = $saved && isset( $_GET['run_mode'] ) ? sanitize_text_field( wp_unslash( $_GET['run_mode'] ) ) : 'manual';
		if ( ! in_array( $run_mode, array( 'manual', 'auto', 'batch_auto' ), true ) ) {
			$run_mode = 'manual';
		}

		$model_id = $saved && isset( $_GET['model'] ) ? sanitize_text_field( wp_unslash( $_GET['model'] ) ) : '';
		$model_id = PXAT_OpenRouter::get_model( $model_id )['id']; // Normalizes to a known model id, falling back to the first configured one.

		return array(
			'source_lang'   => $source_lang,
			'dest_lang'     => $dest_lang,
			'source_status' => $source_status,
			'data_mode'     => $data_mode,
			'custom_types'  => $custom_types,
			'run_mode'      => $run_mode,
			'model_id'      => $model_id,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	public static function render_page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation params, each sanitized on use.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$token     = isset( $_GET['sel'] ) ? sanitize_text_field( wp_unslash( $_GET['sel'] ) ) : '';
		$selection = self::get_selection( $token );

		if ( ! $selection || empty( $selection['post_ids'] ) ) {
			echo '<div class="wrap"><p>' . esc_html__( 'That selection has expired. Please pick the posts again from the list.', 'perxel-ai-translate' ) . '</p></div>';
			return;
		}

		$post_ids  = $selection['post_ids'];
		$post_type = $selection['post_type'];

		$languages = PXAT_WPML::get_active_languages();
		if ( count( $languages ) < 2 ) {
			echo '<div class="wrap"><p>' . esc_html__( 'WPML needs at least two active languages.', 'perxel-ai-translate' ) . '</p></div>';
			return;
		}

		$available_statuses = self::get_available_statuses( $post_ids );
		$config              = self::read_config( $languages, $available_statuses );

		$source_lang   = $config['source_lang'];
		$dest_lang     = $config['dest_lang'];
		$source_status = $config['source_status'];
		$data_mode     = $config['data_mode'];
		$custom_types  = $config['custom_types'];
		$run_mode      = $config['run_mode'];
		$model_id      = $config['model_id'];
		$models        = PXAT_OpenRouter::get_models();

		$selected_types = 'full' === $data_mode ? PXAT_Fields::DATA_TYPES : $custom_types;

		// What "Taxonomy" will actually consider for this post type — printed
		// on the confirm page so it's provable, not just asserted, that a
		// given custom taxonomy is detected. Anything not registered against
		// $post_type (register_taxonomy()'s object_type) never reaches
		// PXAT_Post_Sync::sync_taxonomies()'s loop at all.
		$taxonomy_names = get_object_taxonomies( $post_type, 'names' );

		// Source/dest taxonomy comparison per row — only meaningful (and
		// only shown) for a Custom selection that includes Taxonomy, where
		// catching a gap before Start is the whole point.
		$show_taxonomy_summary = 'custom' === $data_mode && in_array( 'taxonomy', $selected_types, true );

		$settings            = PXAT_Settings::get_settings();
		$system_prompt_chars = mb_strlen( PXAT_OpenRouter::build_system_prompt( $settings['prompt'], $source_lang, $dest_lang ) );

		$rows                    = array();
		$total_prompt_tokens     = 0;
		$total_completion_tokens = 0;
		$total_cost_usd          = 0.0;

		// Selecting either language variant of the same product works the
		// same way: whatever was clicked in the post list gets resolved to
		// its $source_lang counterpart here, before anything else runs — see
		// resolve_source_ids(). $post_ids from here on is always genuinely
		// in $source_lang, so no separate "language_mismatch" branch is
		// needed below any more.
		$resolution = self::resolve_source_ids( $post_ids, $post_type, $source_lang );

		foreach ( $resolution['unresolved'] as $selected_id ) {
			$selected_post = get_post( $selected_id );
			$rows[]        = array(
				'source_post_id'   => $selected_id,
				'title'            => $selected_post ? $selected_post->post_title : sprintf( '#%d', $selected_id ),
				'source_url'       => $selected_post ? get_permalink( $selected_id ) : '',
				'source_status'    => $selected_post ? $selected_post->post_status : '',
				'dest_exists'      => false,
				'dest_post_id'     => null,
				'dest_title'       => '',
				'dest_url'         => '',
				'dest_status'      => null,
				'will_translate'   => false,
				'structural_only'  => false,
				'unresolved'       => true,
				'tokens'           => 0,
				'cost_usd'         => 0.0,
				'taxonomy_summary' => null,
			);
		}

		foreach ( $resolution['resolved'] as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			if ( 'any' !== $source_status && $post->post_status !== $source_status ) {
				continue;
			}

			$dest_post_id = PXAT_WPML::get_object_id( $post_id, $post_type, $dest_lang, false );

			// Full mode can create a destination post from scratch (it has
			// enough — title at minimum — to form a valid one) and always
			// overwrites whichever fields are selected, translated or not.
			// Custom mode never creates one — a partial field set can't form
			// a valid new post on its own — so it's only ever eligible
			// against a post that already has a destination.
			$eligible = ! empty( $selected_types ) && ( 'full' === $data_mode || (bool) $dest_post_id );

			$fields          = array();
			$structural_only = false;

			if ( $eligible ) {
				$fields = PXAT_Fields::compute_field_plan( $post_id, $selected_types );

				// 'acf', 'taxonomy', and 'thumbnail' can have real (free,
				// no-LLM) work to do even with zero translatable fields — a
				// post with only non-text ACF fields to copy, only taxonomy
				// terms to sync, or just a featured image to copy. Only
				// truly nothing-to-do posts get excluded.
				$has_structural_type = (bool) array_intersect( array( 'acf', 'taxonomy', 'thumbnail' ), $selected_types );
				if ( empty( $fields ) && ! $has_structural_type ) {
					$eligible = false;
				} elseif ( empty( $fields ) ) {
					$structural_only = true;
				}
			}

			$row_tokens   = array(
				'prompt_tokens'     => 0,
				'completion_tokens' => 0,
			);
			$row_cost_usd = 0.0;

			if ( ! empty( $fields ) ) {
				$payload = array();
				foreach ( $fields as $field ) {
					$payload[ $field['key'] ] = PXAT_Fields::get_value( $post_id, $field );
				}
				$row_tokens   = PXAT_OpenRouter::estimate_job_tokens( $payload, $system_prompt_chars );
				$row_cost_usd = PXAT_OpenRouter::estimate_cost( $row_tokens['prompt_tokens'], $row_tokens['completion_tokens'], $model_id );
			}

			$total_prompt_tokens     += $row_tokens['prompt_tokens'];
			$total_completion_tokens += $row_tokens['completion_tokens'];
			$total_cost_usd          += $row_cost_usd;

			$dest_post = $dest_post_id ? get_post( $dest_post_id ) : null;

			$taxonomy_summary = null;
			if ( $show_taxonomy_summary ) {
				$source_summary = self::get_taxonomy_summary( $post_id, $taxonomy_names );
				$dest_summary   = $dest_post_id ? self::get_taxonomy_summary( $dest_post_id, $taxonomy_names ) : array();

				$dest_aligned = array();
				foreach ( $source_summary as $label => $names ) {
					// null (not '') specifically marks "this taxonomy has
					// terms on the source but nothing on the dest" — a real
					// gap, rendered distinctly from a taxonomy that's simply
					// empty everywhere.
					$dest_aligned[ $label ] = isset( $dest_summary[ $label ] ) ? $dest_summary[ $label ] : null;
				}

				$taxonomy_summary = array(
					'source' => $source_summary,
					'dest'   => $dest_aligned,
				);
			}

			$rows[] = array(
				'source_post_id'    => $post_id,
				'title'             => $post->post_title,
				'source_url'        => get_permalink( $post_id ),
				'source_status'     => $post->post_status,
				'dest_exists'       => (bool) $dest_post_id,
				'dest_post_id'      => $dest_post_id,
				'dest_title'        => $dest_post ? $dest_post->post_title : '',
				'dest_url'          => $dest_post_id ? get_permalink( $dest_post_id ) : '',
				// A new post starts as draft and is switched to match the source
				// post's status once every job for it succeeds (see
				// PXAT_Job_Processor::maybe_publish_dest_post()), so the source's
				// current status is what it will end up as, not what it starts as.
				'dest_status'       => $dest_post_id ? get_post_status( $dest_post_id ) : null,
				'will_translate'    => $eligible,
				'structural_only'   => $structural_only,
				'unresolved'        => false,
				'tokens'            => $row_tokens['prompt_tokens'] + $row_tokens['completion_tokens'],
				'cost_usd'          => $row_cost_usd,
				'taxonomy_summary'  => $taxonomy_summary,
			);
		}

		$total_tokens = $total_prompt_tokens + $total_completion_tokens;

		$has_eligible_rows = (bool) array_filter(
			$rows,
			function ( $row ) {
				return $row['will_translate'];
			}
		);
		$nothing_to_do = ! $has_eligible_rows;

		include PXAT_DIR . '/views/confirm-page.php';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Resolves each selected post to its $source_lang counterpart via WPML,
	 * regardless of which language variant was actually clicked in the post
	 * picker — selecting either language's post for the same product works
	 * the same way, rather than requiring the exact source-language post to
	 * be the one selected. Two selected posts resolving to the same source
	 * are only processed once. A post with no $source_lang sibling at all
	 * (no translation group, or WPML's own language tag on what it resolved
	 * to doesn't actually match $source_lang — a data-integrity red flag,
	 * never silently trusted) can't be resolved and is reported back
	 * separately.
	 *
	 * This is purely a selection-time convenience: it changes which post_id
	 * gets treated as the source, never how the destination is found — that
	 * stays exactly PXAT_WPML::get_object_id( $resolved_source, ..., $dest_lang )
	 * as it always has been, so write-safety is untouched by this.
	 *
	 * @return array{resolved:int[], unresolved:int[]} 'resolved': deduped source_lang post IDs. 'unresolved': originally-selected IDs that couldn't be resolved.
	 */
	protected static function resolve_source_ids( array $post_ids, $post_type, $source_lang ) {
		$resolved   = array();
		$unresolved = array();
		$seen       = array();

		foreach ( $post_ids as $selected_id ) {
			$resolved_id = PXAT_WPML::get_object_id( $selected_id, $post_type, $source_lang, false );

			if ( $resolved_id ) {
				$resolved_lang = PXAT_WPML::get_post_language( $resolved_id );
				if ( $resolved_lang && $resolved_lang !== $source_lang ) {
					$resolved_id = null; // WPML data inconsistency — don't trust it.
				}
			}

			if ( ! $resolved_id ) {
				$unresolved[] = $selected_id;
				continue;
			}

			if ( isset( $seen[ $resolved_id ] ) ) {
				continue; // another selected post already resolved to this same source.
			}

			$seen[ $resolved_id ] = true;
			$resolved[]           = $resolved_id;
		}

		return array(
			'resolved'   => $resolved,
			'unresolved' => $unresolved,
		);
	}

	/**
	 * taxonomy label => "term name, term name" for every taxonomy in
	 * $taxonomies that actually has terms assigned to $post_id — the
	 * Custom+Taxonomy confirm-page preview's source/dest comparison.
	 * Taxonomies with nothing assigned are simply omitted, same as
	 * PXAT_Post_Sync's own taxonomy loop skips them.
	 */
	protected static function get_taxonomy_summary( $post_id, array $taxonomies ) {
		$summary = array();

		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy );
			if ( ! $terms || is_wp_error( $terms ) ) {
				continue;
			}

			$tax_obj = get_taxonomy( $taxonomy );
			$label   = $tax_obj ? $tax_obj->labels->singular_name : $taxonomy;

			$summary[ $label ] = implode( ', ', wp_list_pluck( $terms, 'name' ) );
		}

		return $summary;
	}

	/**
	 * Distinct post_status values found among the selected posts, keyed by
	 * status slug with the core-translated label as the value — the option
	 * list for the "source status" filter dropdown.
	 */
	protected static function get_available_statuses( array $post_ids ) {
		$statuses = array();
		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || isset( $statuses[ $post->post_status ] ) ) {
				continue;
			}
			$status_object                 = get_post_status_object( $post->post_status );
			$statuses[ $post->post_status ] = $status_object ? $status_object->label : $post->post_status;
		}
		return $statuses;
	}

	public static function handle_submit() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'perxel-ai-translate' ) );
		}
		check_admin_referer( 'pxat_create_batch' );

		$token     = isset( $_POST['sel'] ) ? sanitize_text_field( wp_unslash( $_POST['sel'] ) ) : '';
		$selection = self::get_selection( $token );

		if ( ! $selection || empty( $selection['post_ids'] ) ) {
			wp_die( esc_html__( 'That selection has expired. Please pick the posts again.', 'perxel-ai-translate' ) );
		}

		$post_ids      = $selection['post_ids'];
		$post_type     = $selection['post_type'];
		// Always re-derived, never trusted from the submitted form — see the
		// matching comment in read_config().
		$source_lang   = PXAT_WPML::get_default_language();
		$dest_lang     = isset( $_POST['dest_lang'] ) ? sanitize_text_field( wp_unslash( $_POST['dest_lang'] ) ) : '';
		$source_status = isset( $_POST['source_status'] ) ? sanitize_text_field( wp_unslash( $_POST['source_status'] ) ) : 'publish';
		$model_id      = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';
		$model_id      = PXAT_OpenRouter::get_model( $model_id )['id']; // Normalizes to a known model id, falling back to the first configured one.

		$data_mode = isset( $_POST['data_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['data_mode'] ) ) : 'full';
		if ( ! in_array( $data_mode, array( 'full', 'custom' ), true ) ) {
			$data_mode = 'full';
		}

		$custom_types = array();
		if ( isset( $_POST['custom_types'] ) ) {
			$custom_types = array_values( array_intersect( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['custom_types'] ) ), PXAT_Fields::DATA_TYPES ) );
		}

		$selected_types = 'full' === $data_mode ? PXAT_Fields::DATA_TYPES : $custom_types;

		$run_mode   = isset( $_POST['run_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['run_mode'] ) ) : 'manual';
		$auto_apply = in_array( $run_mode, array( 'auto', 'batch_auto' ), true );
		$batch_mode = 'batch_auto' === $run_mode;

		if ( empty( $selected_types ) ) {
			wp_die( esc_html__( 'No data selected to process.', 'perxel-ai-translate' ) );
		}

		$jobs = array();

		// Selecting either language variant of the same product works the
		// same way: whatever was clicked in the post list gets resolved to
		// its $source_lang counterpart here — see resolve_source_ids() and
		// the matching comment in render_page(). Checked again here, not
		// just in the preview, since this is the request that actually
		// creates jobs.
		$resolution = self::resolve_source_ids( $post_ids, $post_type, $source_lang );

		foreach ( $resolution['unresolved'] as $selected_id ) {
			$jobs[] = self::make_job(
				$selected_id, null, $post_type, $source_lang, $dest_lang, array(), $model_id,
				'error',
				sprintf(
					/* translators: 1: post ID, 2: source language code. */
					__( 'Post #%1$d has no %2$s version to use as the source (no %2$s translation found in this post\'s translation group).', 'perxel-ai-translate' ),
					$selected_id,
					$source_lang
				),
				'create',
				array(),
				$auto_apply,
				$batch_mode,
				$data_mode,
				$custom_types
			);
		}

		foreach ( $resolution['resolved'] as $source_post_id ) {
			if ( 'any' !== $source_status && get_post_status( $source_post_id ) !== $source_status ) {
				continue;
			}

			// Read-only lookup only — no destination post is created or
			// touched here. Creation/re-copying is deferred to
			// PXAT_Job_Processor::apply(), so a dry run makes zero WordPress
			// writes. Mirrors the safety check and skip decision that
			// PXAT_Post_Sync::get_or_create_dest_post() otherwise makes.
			$existing = PXAT_WPML::get_object_id( $source_post_id, $post_type, $dest_lang, false );

			if ( $existing && (int) $existing === (int) $source_post_id ) {
				$jobs[] = self::make_job(
					$source_post_id, null, $post_type, $source_lang, $dest_lang, array(), $model_id,
					'error',
					sprintf(
						/* translators: 1: post ID, 2: destination language code. */
						__( 'WPML returned a destination post (#%1$d, language %2$s) that is the same as the source post — skipped to avoid overwriting the original. Check the translation link (trid) for post #%1$d in WPML.', 'perxel-ai-translate' ),
						$source_post_id,
						$dest_lang
					),
					'create',
					array(),
					$auto_apply,
					$batch_mode,
					$data_mode,
					$custom_types
				);
				continue;
			}

			// Custom mode never creates a destination post — a partial field
			// set can't form a valid new post on its own — so it's only ever
			// eligible against a post that already has one.
			if ( 'custom' === $data_mode && ! $existing ) {
				continue;
			}

			$fields = PXAT_Fields::compute_field_plan( $source_post_id, $selected_types );

			// 'acf' and 'taxonomy' can have real (free, no-LLM) work to do
			// even with zero translatable fields — see the matching comment
			// in render_page().
			$has_structural_type = (bool) array_intersect( array( 'acf', 'taxonomy', 'thumbnail' ), $selected_types );
			if ( empty( $fields ) && ! $has_structural_type ) {
				continue; // nothing translatable, and no structural-only type selected either.
			}

			// 'before' is always populated so the Preview dialog can show a
			// before/after pair either way: for 'update' it's the existing
			// (already-translated) dest content being overwritten; for
			// 'create' there's no dest content yet, so it's the original
			// source-language text instead — still useful to judge the
			// translation against.
			$action      = $existing ? 'update' : 'create';
			$before_post = $existing ? $existing : $source_post_id;
			$before      = array();
			foreach ( $fields as $field ) {
				$before[ $field['key'] ] = PXAT_Fields::get_value( $before_post, $field );
			}

			$jobs[] = self::make_job(
				$source_post_id, $existing ? $existing : null, $post_type, $source_lang, $dest_lang, $fields, $model_id,
				'pending', null, $action, $before,
				$auto_apply, $batch_mode, $data_mode, $custom_types
			);
		}

		if ( empty( $jobs ) ) {
			wp_die( esc_html__( 'Nothing to process. None of the selected posts match the chosen data (already fully translated, no destination post to sync into, or no remaining fields).', 'perxel-ai-translate' ) );
		}

		$batch_id = PXAT_Batch::generate_id();
		PXAT_Batch::save( $batch_id, $jobs );
		delete_transient( 'pxat_sel_' . $token );

		wp_safe_redirect( add_query_arg( array( 'page' => PXAT_Progress_Page::PAGE_SLUG, 'batch_id' => $batch_id ), admin_url( 'admin.php' ) ) );
		exit;
	}

	protected static function make_job( $source_post_id, $dest_post_id, $post_type, $source_lang, $dest_lang, array $fields, $model_id, $status, $error_message, $action, array $before, $auto_apply, $batch_mode, $data_mode, array $custom_types ) {
		$now = current_time( 'mysql' );

		return array(
			'id'             => wp_generate_uuid4(),
			'source_post_id' => $source_post_id,
			'dest_post_id'   => $dest_post_id,
			'post_type'      => $post_type,
			'fields'         => $fields,
			'source_lang'    => $source_lang,
			'dest_lang'      => $dest_lang,
			'model'          => $model_id,
			'status'         => $status,
			'error_message'  => $error_message,
			// 'update' when a destination post already exists and is being
			// overwritten, 'create' when this job will create a brand new
			// one. 'before' is captured read-only for the Preview dialog's
			// before/after pair: the destination's current values for
			// 'update' jobs, or the original source-language text for
			// 'create' jobs (no destination content exists yet). 'preview'
			// is filled in by PXAT_Job_Processor::process();
			// 'results'/'applied'/'applied_at'/'apply_error' are set by ::apply().
			'action'         => $action,
			'before'         => $before,
			// 'full' processes every PXAT_Fields::DATA_TYPES entry; 'custom'
			// processes only $custom_types — see
			// PXAT_Job_Processor::resolve_types(). Always overwrites
			// whichever fields end up selected, no separate "retranslate"
			// permission — see the confirm-page data-axis design.
			'data_mode'      => $data_mode,
			'custom_types'   => $custom_types,
			// Opt-in per batch (see the run_mode radios): when true,
			// ajax_process_job() chains PXAT_Job_Processor::apply() right
			// after a successful translate, skipping the manual
			// Preview/Apply review step for this job.
			'auto_apply'     => $auto_apply,
			// "Auto (batched)": tells PXAT_Progress_Page::ajax_process_job() to
			// group this batch's pending jobs through
			// PXAT_Job_Processor::process_batch() (several posts per OpenRouter
			// request) instead of one job = one request. See PXAT_Batch::get_meta().
			'batch_mode'     => $batch_mode,
			'preview'        => null,
			// Per data-type outcome once apply() runs — see
			// PXAT_Job_Processor::apply_type(). Empty until then.
			'results'        => array(),
			'applied'        => false,
			'applied_at'     => null,
			// Set only when apply() couldn't even find/create the
			// destination post — a job-level failure distinct from a
			// per-type entry in 'results'.
			'apply_error'    => null,
			'created_at'     => $now,
			'created_by'     => wp_get_current_user()->display_name,
			'updated_at'     => $now,
		);
	}
}
