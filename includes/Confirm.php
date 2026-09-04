<?php

namespace Perxel\AITranslate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Confirm screen. The selected post ids arrive in the URL (`ids`, comma
 * separated, plus `post_type`) from the bulk action, the "Translate this page"
 * bar item or a re-run - there is no stored selection. Pick the destination
 * language and data scope, see a per-post plan with a cost estimate, and start
 * the run. The config block is a GET self-submit form that carries the ids
 * through as hidden fields and re-runs the plan on change (JS auto-submits; the
 * "Update plan" button is the no-JS fallback); "Translate and apply" is a POST
 * that creates the run and redirects to Progress.
 *
 * There is no run-mode choice any more - every run translates and writes. Every
 * selected post is processed regardless of status. "Batched" (several posts per
 * model request) is a global Settings toggle, snapshotted into each run.
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
		list( $post_ids, $post_type ) = self::read_selection( $_GET );

		$languages = Wpml::get_active_languages();
		if ( count( $languages ) < 2 ) {
			$admin->screen(
				Admin::PAGE_DASHBOARD,
				__( 'Translation', 'perxel-ai-translate' ),
				'notice',
				array(
					'type' => 'error',
					'text' => __( 'WPML needs at least two active languages.', 'perxel-ai-translate' ),
				)
			);
			return;
		}

		if ( empty( $post_ids ) ) {
			$admin->screen(
				Admin::PAGE_DASHBOARD,
				__( 'Translation', 'perxel-ai-translate' ),
				'confirm-empty'
			);
			return;
		}

		$config = self::read_config( $languages );

		$plan = self::build_plan( $post_ids, $post_type, $config );

		// If the OpenRouter key reports a credit limit, warn when this run's
		// estimate would exceed what is left, and block Start once it hits zero.
		$key_budget    = OpenRouter::key_budget();
		$start_blocked = $key_budget && $key_budget['remaining'] <= 0 && $plan['eligible_count'] > 0;

		$vars = array_merge(
			$config,
			array(
				'key_budget'      => $key_budget,
				'start_blocked'   => $start_blocked,
				'ids_csv'         => implode( ',', $post_ids ),
				'post_type'       => $post_type,
				'post_type_label' => self::post_type_label( $post_type, count( $post_ids ) ),
				'selected_count'  => count( $post_ids ),
				'languages'       => $languages,
				'model'           => Settings::model(),
				'model_verified'  => Settings::model_verified(),
				'settings_url'    => admin_url( 'admin.php?page=' . Admin::PAGE_SETTINGS ),
				'rows'            => $plan['rows'],
				'total_tokens'    => $plan['total_tokens'],
				'total_cost_usd'  => $plan['total_cost_usd'],
				'eligible_count'  => $plan['eligible_count'],
				'type_labels'     => self::type_labels(),
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
			$actions = '<button type="submit" form="pxat-start-form" class="button button-primary"'
				. disabled( $start_blocked, true, false ) . '>'
				. esc_html(
					sprintf(
						/* translators: 1: post count, 2: estimated cost. */
						__( 'Translate and apply - %1$s (%2$s)', 'perxel-ai-translate' ),
						$posts_phrase,
						Format::cost( $plan['total_cost_usd'] )
					)
				)
				. '</button>';
		}

		$admin->screen(
			Admin::PAGE_DASHBOARD,
			__( 'Translation', 'perxel-ai-translate' ),
			'confirm',
			$vars,
			array( 'actions' => $actions )
		);
	}

	/**
	 * The selected posts for this screen: `ids` (comma-separated) and
	 * `post_type` from the request. The post type falls back to the first
	 * post's own type, and a non-translatable type voids the whole selection.
	 *
	 * @param array $source $_GET or $_POST (already slash-quoted by WordPress).
	 * @return array{0:int[], 1:string} [ post_ids, post_type ].
	 */
	protected static function read_selection( array $source ) {
		$raw      = isset( $source['ids'] ) ? wp_unslash( $source['ids'] ) : '';
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', (string) $raw ) ) ) ) );

		$post_type = isset( $source['post_type'] ) ? sanitize_key( wp_unslash( $source['post_type'] ) ) : '';
		if ( ! $post_type && $post_ids ) {
			$post_type = (string) get_post_type( $post_ids[0] );
		}

		if ( ! $post_type || ! in_array( $post_type, PostTypes::get_translatable_post_types(), true ) ) {
			return array( array(), '' );
		}

		return array( $post_ids, $post_type );
	}

	/**
	 * @param string $post_type Post type slug.
	 * @param int    $count     How many posts (picks singular vs plural label).
	 * @return string Human label, falling back to the slug.
	 */
	protected static function post_type_label( $post_type, $count ) {
		$object = $post_type ? get_post_type_object( $post_type ) : null;
		if ( ! $object ) {
			return (string) $post_type;
		}
		return 1 === $count ? $object->labels->singular_name : $object->labels->name;
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
	 * @param array $languages WPML active languages.
	 * @return array
	 */
	protected static function read_config( array $languages ) {
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

		$data_mode = $saved && isset( $_GET['data_mode'] ) ? sanitize_text_field( wp_unslash( $_GET['data_mode'] ) ) : 'full';
		if ( ! in_array( $data_mode, array( 'full', 'custom' ), true ) ) {
			$data_mode = 'full';
		}

		$custom_types = array();
		if ( $saved && isset( $_GET['custom_types'] ) ) {
			$custom_types = array_values( array_intersect( array_map( 'sanitize_text_field', (array) wp_unslash( $_GET['custom_types'] ) ), Fields::DATA_TYPES ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return array(
			'source_lang'  => $source_lang,
			'dest_lang'    => $dest_lang,
			'data_mode'    => $data_mode,
			'custom_types' => $custom_types,
		);
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
		$model          = Settings::model();

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
				'id'            => $selected_id,
				'title'         => $post ? $post->post_title : sprintf( '#%d', $selected_id ),
				'source_url'    => $post ? get_permalink( $selected_id ) : '',
				'status'        => $post ? $post->post_status : '',
				'dest_exists'   => false,
				'dest_title'    => '',
				'dest_url'      => '',
				'dest_status'   => '',
				'dest_modified' => '',
				'state'         => 'unresolved',
				'tokens'        => 0,
				'cost_usd'      => 0.0,
			);
		}

		foreach ( $resolution['resolved'] as $source_id ) {
			$post = get_post( $source_id );
			if ( ! $post ) {
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
				$row_cost      = OpenRouter::estimate_cost( $estimate['prompt_tokens'], $estimate['completion_tokens'], $model['input'], $model['output'] );
				$total_prompt += $estimate['prompt_tokens'];
				$total_output += $estimate['completion_tokens'];
				$total_cost   += $row_cost;
			}

			if ( $eligible ) {
				++$eligible_count;
			}

			$rows[] = array(
				'id'            => $source_id,
				'title'         => $post->post_title,
				'source_url'    => get_permalink( $source_id ),
				'status'        => $post->post_status,
				'dest_exists'   => (bool) $dest_id,
				'dest_title'    => $dest_post ? $dest_post->post_title : '',
				'dest_url'      => $dest_id ? get_permalink( $dest_id ) : '',
				'dest_status'   => $dest_post ? $dest_post->post_status : '',
				'dest_modified' => $dest_post ? $dest_post->post_modified : '',
				'state'         => $eligible ? ( $structural_only ? 'structural' : 'translate' ) : 'skip',
				'skip_reason'   => $eligible ? '' : self::skip_reason( $config['data_mode'], (bool) $dest_id ),
				'tokens'        => $row_tokens,
				'cost_usd'      => $row_cost,
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
			return __( 'No existing translation - Custom mode does not create posts.', 'perxel-ai-translate' );
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

		list( $post_ids, $post_type ) = self::read_selection( $_POST );
		if ( empty( $post_ids ) ) {
			wp_die( esc_html__( 'No posts selected to translate.', 'perxel-ai-translate' ) );
		}

		$data_mode = isset( $_POST['data_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['data_mode'] ) ) : 'full';
		if ( ! in_array( $data_mode, array( 'full', 'custom' ), true ) ) {
			$data_mode = 'full';
		}

		$custom_types = array();
		if ( isset( $_POST['custom_types'] ) ) {
			$custom_types = array_values( array_intersect( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['custom_types'] ) ), Fields::DATA_TYPES ) );
		}

		$run = self::create_run(
			$post_ids,
			$post_type,
			array(
				'source_lang'  => Wpml::get_default_language(),
				'dest_lang'    => isset( $_POST['dest_lang'] ) ? sanitize_text_field( wp_unslash( $_POST['dest_lang'] ) ) : '',
				'data_mode'    => $data_mode,
				'custom_types' => $custom_types,
				'batched'      => Settings::batched(),
			)
		);

		if ( is_wp_error( $run ) ) {
			wp_die( esc_html( $run->get_error_message() ) );
		}

		// pxat_autostart is the one-shot "go" flag progress.js looks for; it is
		// the only thing that starts a run's loop. A plain reload of the
		// Progress URL never carries it.
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => Admin::PAGE_PROGRESS,
					'run_id'         => $run,
					'pxat_autostart' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Build a run + its items from a selection and a resolved config. Shared by
	 * the Confirm form and the one-click entry points.
	 *
	 * @param int[]  $post_ids  Selected post ids.
	 * @param string $post_type Post type slug.
	 * @param array  $config    source_lang, dest_lang, data_mode, custom_types, batched.
	 * @return int|\WP_Error New run id, or an error describing why nothing was created.
	 */
	public static function create_run( array $post_ids, $post_type, array $config ) {
		if ( empty( $post_ids ) || ! in_array( $post_type, PostTypes::get_translatable_post_types(), true ) ) {
			return new \WP_Error( 'pxat_no_selection', __( 'No posts selected to translate.', 'perxel-ai-translate' ) );
		}

		$key_budget = OpenRouter::key_budget();
		if ( $key_budget && $key_budget['remaining'] <= 0 ) {
			return new \WP_Error( 'pxat_no_credit', __( 'The API key has reached its OpenRouter spending limit. Top up your account, then try again.', 'perxel-ai-translate' ) );
		}

		$source_lang  = (string) $config['source_lang'];
		$dest_lang    = (string) $config['dest_lang'];
		$data_mode    = 'custom' === ( $config['data_mode'] ?? 'full' ) ? 'custom' : 'full';
		$custom_types = array_values( array_intersect( (array) ( $config['custom_types'] ?? array() ), Fields::DATA_TYPES ) );
		$batched      = ! empty( $config['batched'] );
		$model        = Settings::model();

		$languages = Wpml::get_active_languages();
		if ( '' === $dest_lang || ! isset( $languages[ $dest_lang ] ) || $dest_lang === $source_lang ) {
			return new \WP_Error( 'pxat_bad_target', __( 'Pick a target language for this translation.', 'perxel-ai-translate' ) );
		}

		$selected_types = 'full' === $data_mode ? Fields::DATA_TYPES : $custom_types;
		if ( empty( $selected_types ) ) {
			return new \WP_Error( 'pxat_no_data', __( 'No data selected to process.', 'perxel-ai-translate' ) );
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
			$existing = Wpml::get_object_id( $source_id, $post_type, $dest_lang, false );

			if ( $existing && (int) $existing === (int) $source_id ) {
				$items[] = array(
					'source_post_id' => $source_id,
					'post_type'      => $post_type,
					'action'         => 'create',
					'status'         => 'error',
					'error_message'  => sprintf(
						/* translators: 1: post ID, 2: destination language code. */
						__( 'WPML returned the source post itself as its %2$s translation (post #%1$d) - skipped to avoid overwriting the original.', 'perxel-ai-translate' ),
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

		$pending_count = count(
			array_filter(
				$items,
				static function ( $i ) {
					return 'pending' === $i['status'];
				}
			)
		);

		if ( 0 === $pending_count ) {
			return new \WP_Error(
				'pxat_nothing_to_do',
				__( 'Nothing to process. None of the selected posts match the chosen data (already fully translated, no destination post to sync into, or no remaining fields).', 'perxel-ai-translate' )
			);
		}

		// Batching only pays off across several posts: it uses a heavier
		// JSON-envelope prompt and splits one usage figure across the group. A
		// one-post run always takes the plain path.
		$use_batch = $batched && $pending_count > 1;

		$run_id = Runs::create(
			array(
				'model'        => $model['id'],
				'model_label'  => $model['label'],
				'input_rate'   => $model['input'],
				'output_rate'  => $model['output'],
				'max_output'   => $model['max_output'],
				'source_lang'  => $source_lang,
				'dest_lang'    => $dest_lang,
				'data_mode'    => $data_mode,
				'custom_types' => $custom_types,
				'batched'      => $use_batch,
			)
		);
		Runs::add_items( $run_id, $items );

		$scope = 'custom' === $data_mode
			? implode( ', ', array_map( array( Translator::class, 'type_label' ), $custom_types ) )
			: 'everything';

		Runs::log(
			$run_id,
			0,
			sprintf(
				'Run #%d created - %s (%s) - %s to %s - %s - %d post%s%s - reasoning off, fastest-provider routing',
				$run_id,
				'' !== $model['label'] ? $model['label'] : $model['id'],
				$model['id'],
				$source_lang,
				$dest_lang,
				$scope,
				$pending_count,
				1 === $pending_count ? '' : 's',
				$use_batch ? sprintf( ' - batched, up to %d in parallel', Translator::worker_count( array( 'batched' => true ) ) ) : ''
			)
		);

		return (int) $run_id;
	}
}
