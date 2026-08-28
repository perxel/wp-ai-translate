<?php
/**
 * Two-phase job execution: translate then apply.
 *
 * @package Perxel_AI_Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two-phase job execution, called from the Progress page's AJAX loop:
 *
 * - process(): translates (one OpenRouter request, for whichever selected
 *   data types actually need the LLM) and stores the result in the job's
 *   `preview` — no WordPress content is written yet.
 * - apply(): writes a previously-translated job's `preview` into WordPress,
 *   one data type at a time (see apply_type()) — creating/relinking the
 *   destination post on first apply (Full mode only; Custom mode never
 *   creates one, see PXAT_Post_Sync::get_or_create_dest_post()), only once
 *   explicitly requested from the Review/Apply step. Safe to call again on
 *   an already-applied job.
 *
 * Every job carries 'data_mode' ('full' | 'custom') and, for custom,
 * 'custom_types' (a subset of PXAT_Fields::DATA_TYPES) — resolve_types()
 * turns that into the concrete list this job processes. Full mode's
 * per-type failures are warnings (best-effort: the destination post still
 * gets everything that could be written); Custom mode's are hard errors
 * (the job never counts as fully applied) — see apply()'s $strict flag.
 */
class PXAT_Job_Processor {

	// "Auto (batched)" mode only: how many parallel processNext() loops
	// progress.js runs against the same batch (see PXAT_Progress_Page's
	// 'workerCount' localization and progress.js's startWorkers()).
	// PXAT_Batch::claim_pending_group() is what makes running more than one
	// of these safe — each worker's claim is exclusive, so raising this
	// isn't just a JS-side change, it's also more simultaneous long-running
	// requests held open against the host's PHP-FPM worker pool.
	const BATCH_WORKER_COUNT = 2;

	protected static function resolve_types( array $job ) {
		return 'full' === $job['data_mode'] ? PXAT_Fields::DATA_TYPES : $job['custom_types'];
	}

	/**
	 * Subset of $types that actually need an OpenRouter call — everything
	 * except 'taxonomy', which is structural only (WPML term mapping, no
	 * text to translate).
	 */
	protected static function llm_types( array $types ) {
		return array_values( array_diff( $types, array( 'taxonomy', 'thumbnail' ) ) );
	}

	public static function type_label( $type ) {
		$labels = array(
			'title'     => __( 'Title / Slug', 'perxel-ai-translate' ),
			'content'   => __( 'Content', 'perxel-ai-translate' ),
			'acf'       => 'ACF',
			'rankmath'  => 'Rank Math',
			'taxonomy'  => 'Taxonomy',
			'thumbnail' => __( 'Featured image', 'perxel-ai-translate' ),
		);
		return isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
	}

	public static function process( $batch_id, array $job ) {
		$post_label = 'Post #' . $job['source_post_id'];

		PXAT_Batch::update_job( $batch_id, $job['id'], array( 'status' => 'processing' ) );

		$text_types = self::llm_types( self::resolve_types( $job ) );

		// No text-bearing type selected at all (e.g. Custom = Taxonomy
		// only) — no OpenRouter call needed by design, not because there
		// was nothing to translate. Marked 'success' with an empty preview
		// rather than 'skipped', specifically so apply() still runs —
		// 'skipped' jobs never reach ::apply(), and ::apply() is what
		// actually does the structural work (taxonomy/ACF/thumbnail).
		if ( empty( $text_types ) ) {
			PXAT_Batch::append_job_log( $batch_id, $job['id'], $post_label . ': no text field to translate, skipping the LLM call' );
			return PXAT_Batch::update_job(
				$batch_id,
				$job['id'],
				array(
					'status'        => 'success',
					'error_message' => null,
					'usage'         => array(
						'prompt_tokens'     => 0,
						'completion_tokens' => 0,
						'total_tokens'      => 0,
					),
					'cost_usd'      => 0.0,
					'preview'       => array(),
				)
			);
		}

		PXAT_Batch::append_job_log( $batch_id, $job['id'], $post_label . ': collecting fields from the source post' );

		$fields = isset( $job['fields'] ) ? $job['fields'] : array();

		if ( empty( $fields ) ) {
			// Text type(s) were selected, but the source post had nothing
			// in any of them (e.g. Content selected, post has no excerpt
			// or content) — genuinely nothing to translate, distinct from
			// the "no text type selected" case above.
			PXAT_Batch::append_job_log( $batch_id, $job['id'], $post_label . ': no field to translate, skipping' );
			return PXAT_Batch::update_job(
				$batch_id,
				$job['id'],
				array(
					'status'        => 'skipped',
					'error_message' => __( 'Nothing to translate.', 'perxel-ai-translate' ),
				)
			);
		}

		$payload = self::build_payload( $job );

		PXAT_Batch::append_job_log(
			$batch_id,
			$job['id'],
			sprintf( '%s: sending %d field(s) to the model (%s to %s)...', $post_label, count( $payload ), $job['source_lang'], $job['dest_lang'] )
		);

		$model_id = isset( $job['model'] ) ? $job['model'] : '';

		$result = PXAT_OpenRouter::translate(
			$payload,
			$job['source_lang'],
			$job['dest_lang'],
			$model_id,
			function ( $message ) use ( $batch_id, $job, $post_label ) {
				PXAT_Batch::append_job_log( $batch_id, $job['id'], $post_label . ': ' . $message );
			}
		);

		if ( is_wp_error( $result ) ) {
			PXAT_Batch::append_job_log( $batch_id, $job['id'], $post_label . ': model error: ' . $result->get_error_message() );
			return PXAT_Batch::update_job(
				$batch_id,
				$job['id'],
				array(
					'status'        => 'error',
					'error_message' => $result->get_error_message(),
				)
			);
		}

		$translations = $result['fields'];
		$usage        = $result['usage'];
		$cost_usd     = PXAT_OpenRouter::estimate_cost( $usage['prompt_tokens'], $usage['completion_tokens'], $model_id );

		PXAT_Batch::append_job_log( $batch_id, $job['id'], $post_label . ': response received, checking translated fields' );

		$missing_keys = array();
		foreach ( $fields as $field ) {
			if ( ! array_key_exists( $field['key'], $translations ) ) {
				$missing_keys[] = $field['key'];
			}
		}
		if ( $missing_keys ) {
			PXAT_Batch::append_job_log( $batch_id, $job['id'], $post_label . ': warning, missing keys in the response: ' . implode( ', ', $missing_keys ) );
		}

		PXAT_Batch::append_job_log(
			$batch_id,
			$job['id'],
			sprintf( '%s: translated (%s, %s), ready to preview and apply', $post_label, PXAT_Format::unit_label( $usage['total_tokens'] ), PXAT_Format::cost( $cost_usd ) )
		);

		return PXAT_Batch::update_job(
			$batch_id,
			$job['id'],
			array(
				'status'        => 'success',
				'error_message' => null,
				'usage'         => $usage,
				'cost_usd'      => $cost_usd,
				'preview'       => $translations,
			)
		);
	}

	/**
	 * Writes a translated job's `preview` into WordPress, one selected data
	 * type at a time (see apply_type()). Each type is applied and reported
	 * independently — one type failing never blocks or discards another
	 * type's successful write. `results` holds every type's own outcome;
	 * the job only counts as `applied` once every type in it succeeded —
	 * Full mode's per-type failures still get written as far as they can
	 * (best-effort, `strict` = false), so `applied` staying false there
	 * just means "at least one type had a warning", not "nothing was
	 * written" — check `results` for what actually happened per type.
	 */
	public static function apply( $batch_id, array $job ) {
		$post_label = 'Post #' . $job['source_post_id'];

		if ( ! empty( $job['applied'] ) ) {
			return $job; // already fully applied, nothing to do.
		}

		if ( 'success' !== $job['status'] ) {
			return $job; // not translated (yet), or errored/skipped — nothing to apply.
		}

		// Everything below talks to WPML (creating/registering the dest post,
		// resolving taxonomy term translations) and must never run
		// concurrently with another apply() call — see
		// PXAT_Batch::with_apply_lock() for why. Cheap even under
		// contention: apply() makes no network calls, translation already
		// happened before this is ever invoked.
		return PXAT_Batch::with_apply_lock(
			function () use ( $batch_id, $job, $post_label ) {
				$types        = self::resolve_types( $job );
				$strict       = 'custom' === $job['data_mode'];
				$allow_create = 'full' === $job['data_mode'];

				$sync = PXAT_Post_Sync::get_or_create_dest_post( $job['source_post_id'], $job['post_type'], $job['dest_lang'], $job['source_lang'], $allow_create );

				if ( $sync['error'] ) {
					PXAT_Batch::append_job_log( $batch_id, $job['id'], $post_label . ': apply error: ' . $sync['error'] );
					return PXAT_Batch::update_job( $batch_id, $job['id'], array( 'apply_error' => $sync['error'] ) );
				}

				if ( ! $sync['post_id'] ) {
					// Custom mode, no existing destination — PXAT_Confirm_Page
					// already excludes these posts at eligibility time, so this
					// is defensive only (e.g. the destination was deleted
					// between confirm and apply).
					PXAT_Batch::append_job_log( $batch_id, $job['id'], $post_label . ': no destination post to apply to, skipping' );
					return $job;
				}

				$dest_post_id = $sync['post_id'];

				$translations = isset( $job['preview'] ) ? $job['preview'] : array();
				$results      = array();

				foreach ( $types as $type ) {
					$result             = self::apply_type( $type, $job, $dest_post_id, $translations, $strict );
					$results[ $type ]   = $result;

					if ( ! $result['success'] ) {
						PXAT_Batch::append_job_log( $batch_id, $job['id'], sprintf( '%s: %s error — %s', $post_label, self::type_label( $type ), $result['message'] ) );
					} elseif ( $result['message'] ) {
						PXAT_Batch::append_job_log( $batch_id, $job['id'], sprintf( '%s: %s — %s', $post_label, self::type_label( $type ), $result['message'] ) );
					}
				}

				$all_success = ! array_filter(
					$results,
					function ( $result ) {
						return ! $result['success'];
					}
				);

				PXAT_Batch::append_job_log(
					$batch_id,
					$job['id'],
					$all_success
						? sprintf( '%s: applied, written to destination post #%d', $post_label, $dest_post_id )
						: sprintf( '%s: partially applied to destination post #%d — see per-type detail above', $post_label, $dest_post_id )
				);

				$updated_job = PXAT_Batch::update_job(
					$batch_id,
					$job['id'],
					array(
						'dest_post_id' => $dest_post_id,
						'results'      => $results,
						'applied'      => $all_success,
						'applied_at'   => $all_success ? current_time( 'mysql' ) : null,
						'apply_error'  => null,
					)
				);

				if ( $all_success ) {
					self::maybe_publish_dest_post( $batch_id, $updated_job );
				}

				return $updated_job;
			}
		);
	}

	/**
	 * Dispatches one data type to its own apply logic — text types
	 * (title/content/rankmath) via apply_text_field_group(), 'acf' as a
	 * combination of its own text fields plus PXAT_Post_Sync::sync_acf()'s
	 * structural copy (both count toward the one 'acf' result, since "ACF"
	 * is offered as a single checkbox), 'taxonomy' via
	 * PXAT_Post_Sync::sync_taxonomies(), 'thumbnail' via
	 * PXAT_Post_Sync::sync_thumbnail().
	 *
	 * @return array{success:bool, message:?string}
	 */
	protected static function apply_type( $type, array $job, $dest_post_id, array $translations, $strict ) {
		$fields = isset( $job['fields'] ) ? $job['fields'] : array();

		switch ( $type ) {
			case 'title':
				$result = self::apply_text_field_group( self::field_defs_for_type( $fields, 'title' ), $dest_post_id, $translations, $strict );
				// Slug isn't a translated field in its own right — it's derived
				// from the translated title, same as WordPress deriving a slug
				// from a title on any normal post save. Only derived when the
				// title itself actually wrote successfully.
				if ( $result['success'] && isset( $translations['post_title'] ) && '' !== $translations['post_title'] ) {
					$slug = sanitize_title( $translations['post_title'] );
					if ( $slug ) {
						wp_update_post( array( 'ID' => $dest_post_id, 'post_name' => $slug ) );
					}
				}
				return $result;

			case 'content':
				return self::apply_text_field_group( self::field_defs_for_type( $fields, 'content' ), $dest_post_id, $translations, $strict );

			case 'rankmath':
				return self::apply_text_field_group( self::field_defs_for_type( $fields, 'rankmath' ), $dest_post_id, $translations, $strict );

			case 'acf':
				$text_result       = self::apply_text_field_group( self::field_defs_for_type( $fields, 'acf' ), $dest_post_id, $translations, $strict );
				$structural_result = PXAT_Post_Sync::sync_acf( $job['source_post_id'], $dest_post_id, $strict );
				return self::combine_results( array( $text_result, $structural_result ) );

			case 'taxonomy':
				return PXAT_Post_Sync::sync_taxonomies( $job['source_post_id'], $dest_post_id, $job['post_type'], $job['dest_lang'], $strict );

			case 'thumbnail':
				return PXAT_Post_Sync::sync_thumbnail( $job['source_post_id'], $dest_post_id, $strict );
		}

		return array( 'success' => true, 'message' => null );
	}

	/**
	 * Partitions $fields (the job's already-computed, stable LLM field-def
	 * list from job creation) by which data type each entry belongs to —
	 * rather than re-deriving field defs from the source post at apply
	 * time, which could drift from what was actually translated if the
	 * source changed in between.
	 */
	protected static function field_defs_for_type( array $fields, $type ) {
		switch ( $type ) {
			case 'title':
				return array_values(
					array_filter(
						$fields,
						function ( $field ) {
							return 'core' === $field['source'] && 'post_title' === $field['key'];
						}
					)
				);
			case 'content':
				return array_values(
					array_filter(
						$fields,
						function ( $field ) {
							return 'core' === $field['source'] && in_array( $field['key'], array( 'post_excerpt', 'post_content' ), true );
						}
					)
				);
			case 'acf':
				return array_values(
					array_filter(
						$fields,
						function ( $field ) {
							return in_array( $field['source'], array( 'acf', 'acf_nested' ), true );
						}
					)
				);
			case 'rankmath':
				return array_values(
					array_filter(
						$fields,
						function ( $field ) {
							return 'rankmath' === $field['source'];
						}
					)
				);
		}
		return array();
	}

	/**
	 * Writes a group of translated text fields (e.g. just post_title for
	 * 'title', or excerpt+content for 'content') onto $dest_post_id.
	 * $strict mirrors PXAT_Post_Sync's granular functions: computes what's
	 * missing from $translations FIRST, and under $strict writes nothing at
	 * all if anything in the group is missing — never a partial write
	 * reported as success. Under non-strict (Full mode), writes whatever IS
	 * present and reports the rest as a warning.
	 *
	 * @return array{success:bool, message:?string}
	 */
	protected static function apply_text_field_group( array $field_defs, $dest_post_id, array $translations, $strict ) {
		if ( empty( $field_defs ) ) {
			return array( 'success' => true, 'message' => null );
		}

		$missing = array();
		foreach ( $field_defs as $field ) {
			if ( ! array_key_exists( $field['key'], $translations ) || '' === $translations[ $field['key'] ] ) {
				$missing[] = $field['key'];
			}
		}

		if ( $missing && $strict ) {
			return array(
				'success' => false,
				/* translators: %s: comma-separated field keys. */
				'message' => sprintf( __( 'Missing translation for: %s — nothing in this group was written.', 'perxel-ai-translate' ), implode( ', ', $missing ) ),
			);
		}

		foreach ( $field_defs as $field ) {
			if ( array_key_exists( $field['key'], $translations ) && '' !== $translations[ $field['key'] ] ) {
				PXAT_Fields::set_value( $dest_post_id, $field, $translations[ $field['key'] ] );
			}
		}

		return array(
			'success' => true,
			'message' => $missing ? sprintf( __( 'Missing translation for: %s.', 'perxel-ai-translate' ), implode( ', ', $missing ) ) : null,
		);
	}

	/**
	 * Combines the two sub-results that make up the 'acf' type (translated
	 * text fields + structural copy of non-text fields) into one.
	 */
	protected static function combine_results( array $results ) {
		$failed = array_filter(
			$results,
			function ( $result ) {
				return ! $result['success'];
			}
		);
		$messages = array_filter(
			array_map(
				function ( $result ) {
					return $result['message'];
				},
				$results
			)
		);

		return array(
			'success' => empty( $failed ),
			'message' => $messages ? implode( ' ', $messages ) : null,
		);
	}

	/**
	 * Once every job in this batch targeting the same destination post has
	 * been applied (or errored), syncs that post's status to match the
	 * source post's own status, never a hardcoded 'publish'. A draft
	 * source stays a draft translation; a published source goes live. Only
	 * runs when none of the jobs errored, so a partially-applied post
	 * never has its status touched (silently going live, or losing an
	 * already-correct draft status, either way).
	 */
	protected static function maybe_publish_dest_post( $batch_id, array $job ) {
		$dest_post_id = isset( $job['dest_post_id'] ) ? $job['dest_post_id'] : null;
		if ( ! $dest_post_id ) {
			return;
		}

		$post_label = 'Post #' . $job['source_post_id'];

		$jobs = PXAT_Batch::read( $batch_id );
		if ( ! $jobs ) {
			return;
		}

		$sibling_jobs = array_filter(
			$jobs,
			function ( $sibling ) use ( $dest_post_id ) {
				return isset( $sibling['dest_post_id'] ) && (int) $sibling['dest_post_id'] === (int) $dest_post_id;
			}
		);

		$has_error = false;
		foreach ( $sibling_jobs as $sibling ) {
			if ( in_array( $sibling['status'], array( 'pending', 'processing' ), true ) ) {
				return; // still work in flight for this post, nothing to do yet.
			}
			if ( 'error' === $sibling['status'] ) {
				$has_error = true;
				continue;
			}
			if ( 'success' === $sibling['status'] && empty( $sibling['applied'] ) ) {
				return; // translated but not yet (fully) applied, nothing to do yet.
			}
		}

		if ( $has_error ) {
			PXAT_Batch::append_job_log( $batch_id, $job['id'], $post_label . ': one or more jobs errored, leaving destination post #' . $dest_post_id . ' status untouched for review' );
			return;
		}

		$source_status = get_post_status( $job['source_post_id'] );
		$dest_status   = get_post_status( $dest_post_id );

		if ( ! $source_status || $source_status === $dest_status ) {
			return; // already matches the source (e.g. source is draft, dest is already draft), nothing to do.
		}

		wp_update_post(
			array(
				'ID'          => $dest_post_id,
				'post_status' => $source_status,
			)
		);
		PXAT_Batch::append_job_log( $batch_id, $job['id'], $post_label . ": all jobs finished, setting destination post #{$dest_post_id} status to match the source ({$source_status})" );
	}

	/**
	 * field_key => source text for one job — shared by process() (one job,
	 * one OpenRouter request) and the batch flow below (several jobs' payloads
	 * nested under their own job_id into one OpenRouter request).
	 */
	protected static function build_payload( array $job ) {
		$payload = array();
		foreach ( $job['fields'] as $field ) {
			$payload[ $field['key'] ] = PXAT_Fields::get_value( $job['source_post_id'], $field );
		}
		return $payload;
	}

	/**
	 * "Auto (batched)" mode: picks jobs off the front of $pending_jobs to
	 * process together in the next AJAX round-trip. Jobs with no LLM field
	 * at all ('fields' empty — no text-bearing type selected, or nothing on
	 * the source to translate) are free to include unconditionally: they
	 * never touch OpenRouter, process_batch() resolves them directly. Jobs
	 * that do need translating are bounded by whichever of two caps bites
	 * first — PXAT_OpenRouter::MAX_BATCH_JOBS (a flat count, so a
	 * failed/malformed batch response never takes down more than that many
	 * posts at once), or the model's own completion-token budget.
	 *
	 * @param array  $pending_jobs Jobs still 'pending', in the order they should be considered (see PXAT_Batch::get_pending_jobs()).
	 * @param string $model_id
	 * @return array Subset of $pending_jobs to process together next.
	 */
	public static function select_batch( array $pending_jobs, $model_id ) {
		if ( empty( $pending_jobs ) ) {
			return array();
		}

		$free_jobs      = array();
		$llm_candidates = array();

		foreach ( $pending_jobs as $job ) {
			if ( empty( $job['fields'] ) ) {
				$free_jobs[] = $job;
			} else {
				$llm_candidates[] = $job;
			}
		}

		if ( empty( $llm_candidates ) ) {
			return $free_jobs;
		}

		$first                = $llm_candidates[0];
		$settings             = PXAT_Settings::get_settings();
		$system_prompt_chars  = mb_strlen( PXAT_OpenRouter::build_batch_system_prompt( $settings['prompt'], $first['source_lang'], $first['dest_lang'] ) );
		$token_budget         = PXAT_OpenRouter::get_batch_output_budget( $model_id );

		$selected      = array();
		$running_total = 0;

		foreach ( $llm_candidates as $job ) {
			if ( count( $selected ) >= PXAT_OpenRouter::MAX_BATCH_JOBS ) {
				break;
			}

			$estimate = PXAT_OpenRouter::estimate_job_tokens( self::build_payload( $job ), $system_prompt_chars );

			if ( $selected && $token_budget > 0 && ( $running_total + $estimate['completion_tokens'] ) > $token_budget ) {
				break;
			}

			$selected[]     = $job;
			$running_total += $estimate['completion_tokens'];
		}

		return array_merge( $free_jobs, $selected );
	}

	/**
	 * "Auto (batched)" mode: resolves a claimed group of jobs (see
	 * PXAT_Progress_Page::ajax_process_job_batch()) — jobs needing no LLM
	 * call at all are marked directly (no_text_type_jobs: no text-bearing
	 * type selected; skipped_jobs: text type(s) selected but nothing on the
	 * source to translate — same distinction process() makes for a single
	 * job), and the rest are translated together in one OpenRouter request
	 * via process_llm_batch(). Every resolved job that's auto_apply then
	 * gets applied immediately, same as the single-job "Auto" flow.
	 *
	 * @return array Updated job records for every job in $jobs.
	 */
	public static function process_batch( $batch_id, array $jobs ) {
		if ( empty( $jobs ) ) {
			return array();
		}

		$no_text_type_jobs = array();
		$skipped_jobs      = array();
		$llm_jobs          = array();

		foreach ( $jobs as $job ) {
			$text_types = self::llm_types( self::resolve_types( $job ) );

			if ( empty( $text_types ) ) {
				$no_text_type_jobs[] = $job;
			} elseif ( empty( $job['fields'] ) ) {
				$skipped_jobs[] = $job;
			} else {
				$llm_jobs[] = $job;
			}
		}

		$updated = array();

		if ( $no_text_type_jobs ) {
			$ids = wp_list_pluck( $no_text_type_jobs, 'id' );
			PXAT_Batch::append_job_log_bulk( $batch_id, $ids, 'no text field to translate, skipping the LLM call' );
			$updated = array_merge(
				$updated,
				PXAT_Batch::update_jobs(
					$batch_id,
					array_fill_keys(
						$ids,
						array(
							'status'        => 'success',
							'error_message' => null,
							'usage'         => array(
								'prompt_tokens'     => 0,
								'completion_tokens' => 0,
								'total_tokens'      => 0,
							),
							'cost_usd'      => 0.0,
							'preview'       => array(),
						)
					)
				)
			);
		}

		if ( $skipped_jobs ) {
			$ids = wp_list_pluck( $skipped_jobs, 'id' );
			PXAT_Batch::append_job_log_bulk( $batch_id, $ids, 'no field to translate, skipping' );
			$updated = array_merge(
				$updated,
				PXAT_Batch::update_jobs(
					$batch_id,
					array_fill_keys(
						$ids,
						array(
							'status'        => 'skipped',
							'error_message' => __( 'Nothing to translate.', 'perxel-ai-translate' ),
						)
					)
				)
			);
		}

		if ( $llm_jobs ) {
			$updated = array_merge( $updated, self::process_llm_batch( $batch_id, $llm_jobs ) );
		}

		// Batch mode always carries auto_apply=true (see
		// PXAT_Confirm_Page::handle_submit()) — write each successfully
		// translated job straight to WordPress, same as the "Auto" mode's
		// single-job flow does right after each job's own translate call.
		foreach ( $updated as &$job ) {
			if ( 'success' === $job['status'] && ! empty( $job['auto_apply'] ) ) {
				$job = self::apply( $batch_id, $job );
			}
		}
		unset( $job );

		return $updated;
	}

	/**
	 * The actual OpenRouter batch call — $jobs here are always ones with a
	 * non-empty 'fields' list (see process_batch()'s split above).
	 *
	 * On a request-level failure (network error, non-200, unparseable JSON),
	 * every job in the group is marked 'error' with the same message — no
	 * retry-as-smaller-batches, so one bad batch never silently melts away a
	 * chunk of the queue without a visible error on every affected row.
	 *
	 * @return array Updated job records for every job in $jobs.
	 */
	protected static function process_llm_batch( $batch_id, array $jobs ) {
		$job_ids = wp_list_pluck( $jobs, 'id' );

		PXAT_Batch::append_job_log_bulk( $batch_id, $job_ids, sprintf( 'Sending a batch (%d posts) to the model...', count( $jobs ) ) );

		$payload      = array();
		$job_payloads = array();
		foreach ( $jobs as $job ) {
			$job_payloads[ $job['id'] ] = self::build_payload( $job );
			$payload[ $job['id'] ]      = $job_payloads[ $job['id'] ];
		}

		$first    = $jobs[0];
		$model_id = isset( $first['model'] ) ? $first['model'] : '';

		$result = PXAT_OpenRouter::translate_batch(
			$payload,
			$first['source_lang'],
			$first['dest_lang'],
			$model_id,
			function ( $message ) use ( $batch_id, $job_ids ) {
				PXAT_Batch::append_job_log_bulk( $batch_id, $job_ids, $message );
			}
		);

		if ( is_wp_error( $result ) ) {
			PXAT_Batch::append_job_log_bulk( $batch_id, $job_ids, 'model error: ' . $result->get_error_message() );
			return PXAT_Batch::update_jobs(
				$batch_id,
				array_fill_keys(
					$job_ids,
					array(
						'status'        => 'error',
						'error_message' => $result->get_error_message(),
					)
				)
			);
		}

		$results = $result['results'];
		$usage   = $result['usage'];

		// OpenRouter returns one aggregate `usage` for the whole batch request,
		// not per-post — split it across jobs proportional to each job's own
		// estimated size, so the sum across the batch (what the progress
		// page's running cost/token counters read) stays exact even though
		// any single job's own usage/cost is only an estimate.
		$settings            = PXAT_Settings::get_settings();
		$system_prompt_chars = mb_strlen( PXAT_OpenRouter::build_batch_system_prompt( $settings['prompt'], $first['source_lang'], $first['dest_lang'] ) );

		$weights    = array();
		$weight_sum = 0;
		foreach ( $jobs as $job ) {
			$estimate               = PXAT_OpenRouter::estimate_job_tokens( $job_payloads[ $job['id'] ], $system_prompt_chars );
			$weight                 = max( 1, $estimate['completion_tokens'] );
			$weights[ $job['id'] ]  = $weight;
			$weight_sum            += $weight;
		}

		$changes = array();

		foreach ( $jobs as $job ) {
			$job_id     = $job['id'];
			$post_label = 'Post #' . $job['source_post_id'];

			if ( ! array_key_exists( $job_id, $results ) || ! is_array( $results[ $job_id ] ) ) {
				PXAT_Batch::append_job_log( $batch_id, $job_id, $post_label . ': not present in the batch response' );
				$changes[ $job_id ] = array(
					'status'        => 'error',
					'error_message' => __( 'Missing from the model\'s batch response.', 'perxel-ai-translate' ),
				);
				continue;
			}

			$translations = $results[ $job_id ];

			$missing_keys = array();
			foreach ( $job['fields'] as $field ) {
				if ( ! array_key_exists( $field['key'], $translations ) ) {
					$missing_keys[] = $field['key'];
				}
			}
			if ( $missing_keys ) {
				PXAT_Batch::append_job_log( $batch_id, $job_id, $post_label . ': warning, missing keys in the response: ' . implode( ', ', $missing_keys ) );
			}

			$share     = $weight_sum > 0 ? $weights[ $job_id ] / $weight_sum : 0;
			$job_usage = array(
				'prompt_tokens'     => (int) round( $usage['prompt_tokens'] * $share ),
				'completion_tokens' => (int) round( $usage['completion_tokens'] * $share ),
				'total_tokens'      => (int) round( $usage['total_tokens'] * $share ),
			);
			$cost_usd  = PXAT_OpenRouter::estimate_cost( $job_usage['prompt_tokens'], $job_usage['completion_tokens'], $model_id );

			PXAT_Batch::append_job_log( $batch_id, $job_id, sprintf( '%s: translated (batched, %s, %s)', $post_label, PXAT_Format::unit_label( $job_usage['total_tokens'] ), PXAT_Format::cost( $cost_usd ) ) );

			$changes[ $job_id ] = array(
				'status'        => 'success',
				'error_message' => null,
				'usage'         => $job_usage,
				'cost_usd'      => $cost_usd,
				'preview'       => $translations,
			);
		}

		return PXAT_Batch::update_jobs( $batch_id, $changes );
	}
}
