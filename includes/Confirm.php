<?php

namespace Perxel\AITranslate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Confirm screen: pick the destination language, source-status filter and data
 * scope, see a per-post plan with a cost estimate, then start the run. Step 1 is
 * a GET self-submit form (press "Update" to recompute); "Start" is a POST that
 * creates the run and redirects to Progress.
 *
 * There is no run-mode choice any more — every run translates and writes. The
 * only speed knob is "batched" (several posts per model request).
 */
class Confirm {

	const TYPE_LABELS = array(
		'title'     => 'Title / Slug',
		'content'   => 'Excerpt & Content',
		'acf'       => 'ACF',
		'rankmath'  => 'Rank Math SEO',
		'taxonomy'  => 'Taxonomy',
		'thumbnail' => 'Featured image',
	);

	/*
	---------------------------------------------------------------------
	 * Render
	 * ------------------------------------------------------------------- */

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$admin = Plugin::instance()->admin();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation params, each sanitised on use.
		$token     = isset( $_GET['sel'] ) ? sanitize_text_field( wp_unslash( $_GET['sel'] ) ) : '';
		$selection = Selection::get( $token );

		if ( ! $selection ) {
			$admin->screen(
				Admin::PAGE_DASHBOARD,
				__( 'Confirm translation', 'perxel-ai-translate' ),
				'notice',
				array(
					'type' => 'warning',
					'text' => __( 'That selection has expired. Pick the posts again from the list.', 'perxel-ai-translate' ),
				)
			);
			return;
		}

		$languages = Wpml::get_active_languages();
		if ( count( $languages ) < 2 ) {
			$admin->screen(
				Admin::PAGE_DASHBOARD,
				__( 'Confirm translation', 'perxel-ai-translate' ),
				'notice',
				array(
					'type' => 'error',
					'text' => __( 'WPML needs at least two active languages.', 'perxel-ai-translate' ),
				)
			);
			return;
		}

		$post_ids  = $selection['post_ids'];
		$post_type = $selection['post_type'];

		$available_statuses = self::available_statuses( $post_ids );
		$config             = self::read_config( $languages, $available_statuses );

		$plan = self::build_plan( $post_ids, $post_type, $config );

		$vars = array_merge(
			$config,
			array(
				'token'              => $token,
				'post_type'          => $post_type,
				'languages'          => $languages,
				'available_statuses' => $available_statuses,
				'models'             => OpenRouter::get_models(),
				'rows'               => $plan['rows'],
				'total_tokens'       => $plan['total_tokens'],
				'total_cost_usd'     => $plan['total_cost_usd'],
				'eligible_count'     => $plan['eligible_count'],
				'type_labels'        => self::type_labels(),
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$actions = '';
		if ( $plan['eligible_count'] > 0 ) {
			$posts_phrase = sprintf(
				/* translators: %s: number of posts. */
				_n( '%s post', '%s posts', $plan['eligible_count'], 'perxel-ai-translate' ),
				number_format_i18n( $plan['eligible_count'] )
			);
			$actions = '<button type="submit" form="pxat-start-form" class="button button-primary">'
				. esc_html(
					sprintf(
						/* translators: 1: post count, 2: estimated cost. */
						__( 'Start — %1$s (%2$s)', 'perxel-ai-translate' ),
						$posts_phrase,
						Format::cost( $plan['total_cost_usd'] )
					)
				)
				. '</button>';
		}

		$admin->screen(
			Admin::PAGE_DASHBOARD,
			__( 'Confirm translation', 'perxel-ai-translate' ),
			'confirm',
			$vars,
			array( 'actions' => $actions )
		);
	}

	/**
	 * @return array label map for the data types (translated).
	 */
	public static function type_labels() {
		return array(
			'title'     => __( 'Title / Slug', 'perxel-ai-translate' ),
			'content'   => __( 'Excerpt & Content', 'perxel-ai-translate' ),
			'acf'       => __( 'ACF', 'perxel-ai-translate' ),
			'rankmath'  => __( 'Rank Math SEO', 'perxel-ai-translate' ),
			'taxonomy'  => __( 'Taxonomy', 'perxel-ai-translate' ),
			'thumbnail' => __( 'Featured image', 'perxel-ai-translate' ),
		);
	}

	/*
	---------------------------------------------------------------------
	 * Config
	 * ------------------------------------------------------------------- */

	/**
	 * @param array $languages          WPML active languages.
	 * @param array $available_statuses status slug => label among the selection.
	 * @return array
	 */
	protected static function read_config( array $languages, array $available_statuses ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation params, each sanitised on use.
		$saved = isset( $_GET['pxat_save_config'] );

		// Locked to WPML's own site default: a free-choice source here risks
		// resolving the "destination" to a real, different post and overwriting it.
		$source_lang = Wpml::get_default_language();
		$other_langs = array_values( array_diff( array_keys( $languages ), array( $source_lang ) ) );

		$dest_lang = $saved && isset( $_GET['dest_lang'] ) ? sanitize_text_field( wp_unslash( $_GET['dest_lang'] ) ) : reset( $other_langs );
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
			$custom_types = array_values( array_intersect( array_map( 'sanitize_text_field', (array) wp_unslash( $_GET['custom_types'] ) ), Fields::DATA_TYPES ) );
		}

		$batched = $saved ? ! empty( $_GET['batched'] ) : false;

		$model_id = $saved && isset( $_GET['model'] ) ? sanitize_text_field( wp_unslash( $_GET['model'] ) ) : '';
		$model_id = OpenRouter::get_model( $model_id )['id'];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return array(
			'source_lang'   => $source_lang,
			'dest_lang'     => $dest_lang,
			'source_status' => $source_status,
			'data_mode'     => $data_mode,
			'custom_types'  => $custom_types,
			'batched'       => $batched,
			'model_id'      => $model_id,
		);
	}

	/**
	 * Distinct post_status values among the selected posts.
	 *
	 * @param int[] $post_ids Post ids.
	 * @return array status slug => label.
	 */
	protected static function available_statuses( array $post_ids ) {
		$statuses = array();
		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || isset( $statuses[ $post->post_status ] ) ) {
				continue;
			}
			$object                         = get_post_status_object( $post->post_status );
			$statuses[ $post->post_status ] = $object ? $object->label : $post->post_status;
		}
		return $statuses;
	}

	/*
	---------------------------------------------------------------------
	 * Plan / preview
	 * ------------------------------------------------------------------- */

	/**
	 * Resolve each selected post to its source-language counterpart via WPML.
	 * Deduped. Posts with no source-language sibling are reported separately.
	 *
	 * @param int[]  $post_ids    Selected ids.
	 * @param string $post_type   Post type.
	 * @param string $source_lang Source language.
	 * @return array{resolved:int[], unresolved:int[]}
	 */
	protected static function resolve_source_ids( array $post_ids, $post_type, $source_lang ) {
		$resolved   = array();
		$unresolved = array();
		$seen       = array();

		foreach ( $post_ids as $selected_id ) {
			$resolved_id = Wpml::get_object_id( $selected_id, $post_type, $source_lang, false );

			if ( $resolved_id ) {
				$lang = Wpml::get_post_language( $resolved_id );
				if ( $lang && $lang !== $source_lang ) {
					$resolved_id = null;
				}
			}

			if ( ! $resolved_id ) {
				$unresolved[] = $selected_id;
				continue;
			}
			if ( isset( $seen[ $resolved_id ] ) ) {
				continue;
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
	 * Build the per-post preview rows and the run-wide token / cost estimate.
	 *
	 * @param int[]  $post_ids  Selected ids.
	 * @param string $post_type Post type.
	 * @param array  $config    read_config() result.
	 * @return array
	 */
	protected static function build_plan( array $post_ids, $post_type, array $config ) {
		$selected_types = 'full' === $config['data_mode'] ? Fields::DATA_TYPES : $config['custom_types'];

		$system_prompt_chars = mb_strlen( OpenRouter::build_system_prompt( Settings::get( 'prompt' ), $config['source_lang'], $config['dest_lang'] ) );

		$resolution     = self::resolve_source_ids( $post_ids, $post_type, $config['source_lang'] );
		$rows           = array();
		$total_prompt   = 0;
		$total_output   = 0;
		$total_cost     = 0.0;
		$eligible_count = 0;

		foreach ( $resolution['unresolved'] as $selected_id ) {
			$post   = get_post( $selected_id );
			$rows[] = array(
				'id'          => $selected_id,
				'title'       => $post ? $post->post_title : sprintf( '#%d', $selected_id ),
				'source_url'  => $post ? get_permalink( $selected_id ) : '',
				'status'      => $post ? $post->post_status : '',
				'dest_exists' => false,
				'dest_title'  => '',
				'dest_url'    => '',
				'state'       => 'unresolved',
				'tokens'      => 0,
				'cost_usd'    => 0.0,
			);
		}

		foreach ( $resolution['resolved'] as $source_id ) {
			$post = get_post( $source_id );
			if ( ! $post ) {
				continue;
			}
			if ( 'any' !== $config['source_status'] && $post->post_status !== $config['source_status'] ) {
				continue;
			}

			$dest_id   = Wpml::get_object_id( $source_id, $post_type, $config['dest_lang'], false );
			$dest_post = $dest_id ? get_post( $dest_id ) : null;

			// Custom mode never creates a destination post.
			$eligible = ! empty( $selected_types ) && ( 'full' === $config['data_mode'] || (bool) $dest_id );

			$fields          = $eligible ? Fields::compute_field_plan( $source_id, $selected_types ) : array();
			$has_structural  = (bool) array_intersect( array( 'acf', 'taxonomy', 'thumbnail' ), $selected_types );
			$structural_only = false;

			if ( $eligible && empty( $fields ) && ! $has_structural ) {
				$eligible = false;
			} elseif ( $eligible && empty( $fields ) ) {
				$structural_only = true;
			}

			$row_tokens = 0;
			$row_cost   = 0.0;

			if ( ! empty( $fields ) ) {
				$payload = array();
				foreach ( $fields as $field ) {
					$payload[ $field['key'] ] = Fields::get_value( $source_id, $field );
				}
				$estimate      = OpenRouter::estimate_job_tokens( $payload, $system_prompt_chars );
				$row_tokens    = $estimate['prompt_tokens'] + $estimate['completion_tokens'];
				$row_cost      = OpenRouter::estimate_cost( $estimate['prompt_tokens'], $estimate['completion_tokens'], $config['model_id'] );
				$total_prompt += $estimate['prompt_tokens'];
				$total_output += $estimate['completion_tokens'];
				$total_cost   += $row_cost;
			}

			if ( $eligible ) {
				++$eligible_count;
			}

			$rows[] = array(
				'id'          => $source_id,
				'title'       => $post->post_title,
				'source_url'  => get_permalink( $source_id ),
				'status'      => $post->post_status,
				'dest_exists' => (bool) $dest_id,
				'dest_title'  => $dest_post ? $dest_post->post_title : '',
				'dest_url'    => $dest_id ? get_permalink( $dest_id ) : '',
				'state'       => $eligible ? ( $structural_only ? 'structural' : 'translate' ) : 'skip',
				'skip_reason' => $eligible ? '' : self::skip_reason( $config['data_mode'], (bool) $dest_id ),
				'tokens'      => $row_tokens,
				'cost_usd'    => $row_cost,
			);
		}

		return array(
			'rows'           => $rows,
			'total_tokens'   => $total_prompt + $total_output,
			'total_cost_usd' => $total_cost,
			'eligible_count' => $eligible_count,
		);
	}

	protected static function skip_reason( $data_mode, $dest_exists ) {
		if ( 'custom' === $data_mode && ! $dest_exists ) {
			return __( 'No existing translation — Custom mode does not create posts.', 'perxel-ai-translate' );
		}
		return __( 'Nothing left to process.', 'perxel-ai-translate' );
	}

	/*
	---------------------------------------------------------------------
	 * Submit
	 * ------------------------------------------------------------------- */

	public static function handle_submit() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'perxel-ai-translate' ) );
		}
		check_admin_referer( 'pxat_create_run' );

		$token     = isset( $_POST['sel'] ) ? sanitize_text_field( wp_unslash( $_POST['sel'] ) ) : '';
		$selection = Selection::get( $token );
		if ( ! $selection ) {
			wp_die( esc_html__( 'That selection has expired. Pick the posts again.', 'perxel-ai-translate' ) );
		}

		$post_ids  = $selection['post_ids'];
		$post_type = $selection['post_type'];

		$source_lang   = Wpml::get_default_language();
		$dest_lang     = isset( $_POST['dest_lang'] ) ? sanitize_text_field( wp_unslash( $_POST['dest_lang'] ) ) : '';
		$source_status = isset( $_POST['source_status'] ) ? sanitize_text_field( wp_unslash( $_POST['source_status'] ) ) : 'publish';
		$model_id      = OpenRouter::get_model( isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '' )['id'];
		$batched       = ! empty( $_POST['batched'] );

		$data_mode = isset( $_POST['data_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['data_mode'] ) ) : 'full';
		if ( ! in_array( $data_mode, array( 'full', 'custom' ), true ) ) {
			$data_mode = 'full';
		}

		$custom_types = array();
		if ( isset( $_POST['custom_types'] ) ) {
			$custom_types = array_values( array_intersect( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['custom_types'] ) ), Fields::DATA_TYPES ) );
		}

		$selected_types = 'full' === $data_mode ? Fields::DATA_TYPES : $custom_types;
		if ( empty( $selected_types ) ) {
			wp_die( esc_html__( 'No data selected to process.', 'perxel-ai-translate' ) );
		}

		$resolution = self::resolve_source_ids( $post_ids, $post_type, $source_lang );
		$items      = array();

		foreach ( $resolution['unresolved'] as $selected_id ) {
			$items[] = array(
				'source_post_id' => $selected_id,
				'post_type'      => $post_type,
				'action'         => 'create',
				'status'         => 'error',
				'error_message'  => sprintf(
					/* translators: 1: post ID, 2: source language code. */
					__( 'Post #%1$d has no %2$s version to use as the source.', 'perxel-ai-translate' ),
					$selected_id,
					$source_lang
				),
				'fields'         => array(),
				'before'         => array(),
			);
		}

		foreach ( $resolution['resolved'] as $source_id ) {
			if ( 'any' !== $source_status && get_post_status( $source_id ) !== $source_status ) {
				continue;
			}

			$existing = Wpml::get_object_id( $source_id, $post_type, $dest_lang, false );

			if ( $existing && (int) $existing === (int) $source_id ) {
				$items[] = array(
					'source_post_id' => $source_id,
					'post_type'      => $post_type,
					'action'         => 'create',
					'status'         => 'error',
					'error_message'  => sprintf(
						/* translators: 1: post ID, 2: destination language code. */
						__( 'WPML returned the source post itself as its %2$s translation (post #%1$d) — skipped to avoid overwriting the original.', 'perxel-ai-translate' ),
						$source_id,
						$dest_lang
					),
					'fields'         => array(),
					'before'         => array(),
				);
				continue;
			}

			if ( 'custom' === $data_mode && ! $existing ) {
				continue;
			}

			$fields         = Fields::compute_field_plan( $source_id, $selected_types );
			$has_structural = (bool) array_intersect( array( 'acf', 'taxonomy', 'thumbnail' ), $selected_types );
			if ( empty( $fields ) && ! $has_structural ) {
				continue;
			}

			$action      = $existing ? 'update' : 'create';
			$before_post = $existing ? $existing : $source_id;
			$before      = array();
			foreach ( $fields as $field ) {
				$before[ $field['key'] ] = Fields::get_value( $before_post, $field );
			}

			$items[] = array(
				'source_post_id' => $source_id,
				'dest_post_id'   => $existing ? (int) $existing : 0,
				'post_type'      => $post_type,
				'action'         => $action,
				'status'         => 'pending',
				'fields'         => $fields,
				'before'         => $before,
			);
		}

		$has_pending = (bool) array_filter(
			$items,
			static function ( $i ) {
				return 'pending' === $i['status'];
			}
		);

		if ( ! $has_pending ) {
			wp_die( esc_html__( 'Nothing to process. None of the selected posts match the chosen data (already fully translated, no destination post to sync into, or no remaining fields).', 'perxel-ai-translate' ) );
		}

		$run_id = Runs::create(
			array(
				'model'        => $model_id,
				'source_lang'  => $source_lang,
				'dest_lang'    => $dest_lang,
				'data_mode'    => $data_mode,
				'custom_types' => $custom_types,
				'batched'      => $batched,
			)
		);
		Runs::add_items( $run_id, $items );
		Selection::forget( $token );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => Admin::PAGE_PROGRESS,
					'run_id' => $run_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
