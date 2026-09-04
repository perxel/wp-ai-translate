<?php

namespace Perxel\AITranslate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs one item of a translation run end to end: translate the text through
 * OpenRouter, then write everything (text fields, ACF, taxonomy, featured
 * image) into the WPML destination post. There is no separate "apply" step -
 * a run always writes; review the result in the editor.
 *
 * Full mode's per-type failures are non-blocking warnings (the destination
 * still gets everything that could be written); Custom mode's are hard errors
 * (the item ends 'error', retryable). See $strict.
 */
class Translator {

	// Parallel browser workers for a batched run. The DB claim (Runs::claim_ids)
	// makes running more than one safe. Filterable, kept low: each worker holds a
	// long request open against the host's PHP-FPM pool.
	const BATCH_WORKER_COUNT = 2;

	public static function worker_count( $run ) {
		if ( empty( $run['batched'] ) ) {
			return 1;
		}
		/**
		 * Filters the number of parallel browser workers for a batched run.
		 *
		 * @param int $count Default BATCH_WORKER_COUNT.
		 */
		return max( 1, (int) apply_filters( 'pxat_batch_worker_count', self::BATCH_WORKER_COUNT ) );
	}

	public static function type_label( $type ) {
		$labels = array(
			'title'     => __( 'Title / Slug', 'perxel-ai-translate' ),
			'content'   => __( 'Content', 'perxel-ai-translate' ),
			'acf'       => 'ACF',
			'rankmath'  => 'Rank Math',
			'taxonomy'  => __( 'Taxonomy', 'perxel-ai-translate' ),
			'thumbnail' => __( 'Featured image', 'perxel-ai-translate' ),
		);
		return $labels[ $type ] ?? $type;
	}

	protected static function resolve_types( array $run ) {
		return 'full' === $run['data_mode'] ? Fields::DATA_TYPES : $run['custom_types'];
	}

	/**
	 * "Post title (#id)" for the activity log - far easier to scan than a bare
	 * id. Title trimmed so one line stays readable.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	protected static function post_label( $post_id ) {
		$post  = get_post( (int) $post_id );
		$title = $post && '' !== trim( (string) $post->post_title ) ? trim( $post->post_title ) : '(no title)';
		if ( mb_strlen( $title ) > 50 ) {
			$title = rtrim( mb_substr( $title, 0, 50 ) ) . '…';
		}
		return sprintf( '%s (#%d)', $title, (int) $post_id );
	}

	/**
	 * Cost for a log line: always the exact USD, plus the dong equivalent on a
	 * Vietnamese-default site (OpenRouter bills in USD, but that is what the
	 * user thinks in).
	 *
	 * @param float $usd Cost in USD.
	 * @return string
	 */
	protected static function cost_label( $usd ) {
		$out = Format::money_usd( $usd );
		if ( 'VND' === Format::currency() ) {
			$out .= ' (' . Format::cost( $usd ) . ')';
		}
		return $out;
	}

	/**
	 * Subset of $types that need an OpenRouter call - everything except the
	 * structural-only 'taxonomy' and 'thumbnail'.
	 *
	 * @param array $types Type list.
	 * @return array
	 */
	protected static function llm_types( array $types ) {
		return array_values( array_diff( $types, array( 'taxonomy', 'thumbnail' ) ) );
	}

	protected static function build_payload( array $item ) {
		$payload = array();
		foreach ( (array) $item['fields'] as $field ) {
			$payload[ $field['key'] ] = Fields::get_value( $item['source_post_id'], $field );
		}
		return $payload;
	}

	/*
	---------------------------------------------------------------------
	 * Single-item flow
	 * ------------------------------------------------------------------- */

	/**
	 * @param array $run  Run row.
	 * @param array $item Claimed item (status 'translating').
	 * @return array Updated item.
	 */
	public static function process_item( array $run, array $item ) {
		$item = self::translate_step( $run, $item );

		if ( in_array( $item['status'], array( 'error', 'skipped' ), true ) ) {
			return $item;
		}

		return self::write_step( $run, $item );
	}

	/**
	 * Translate phase only. On return the item either carries a stored preview
	 * (status still 'translating') or is 'error' / 'skipped'.
	 *
	 * @param array $run  Run row.
	 * @param array $item Item.
	 * @return array Updated item.
	 */
	protected static function translate_step( array $run, array $item ) {
		$label      = self::post_label( $item['source_post_id'] );
		$text_types = self::llm_types( self::resolve_types( $run ) );

		if ( empty( $text_types ) ) {
			Runs::log( $run['id'], $item['id'], $label . ': no text field selected, nothing to send to the model' );
			return Runs::update_item(
				$item['id'],
				array(
					'preview'           => array(),
					'prompt_tokens'     => 0,
					'completion_tokens' => 0,
					'cost_usd'          => 0.0,
				)
			);
		}

		if ( empty( $item['fields'] ) ) {
			Runs::log( $run['id'], $item['id'], $label . ': nothing to translate, skipping' );
			return Runs::update_item(
				$item['id'],
				array(
					'status'        => 'skipped',
					'error_message' => __( 'Nothing to translate.', 'perxel-ai-translate' ),
				)
			);
		}

		// An earlier request translated this post but died before (or during)
		// the write - the stored preview is still good, so skip the model and
		// go straight to writing it. Keeps a retried / reclaimed row from being
		// charged twice.
		if ( ! empty( $item['preview'] ) ) {
			Runs::log( $run['id'], $item['id'], $label . ': already translated by an earlier request, writing the stored result' );
			return $item;
		}

		$payload = self::build_payload( $item );

		$type_names = implode( ', ', array_map( array( __CLASS__, 'type_label' ), $text_types ) );
		Runs::log(
			$run['id'],
			$item['id'],
			sprintf( '%s: translating %s - %d text field(s), %s to %s', $label, $type_names, count( $payload ), $run['source_lang'], $run['dest_lang'] )
		);

		$result = OpenRouter::translate(
			$payload,
			$run['source_lang'],
			$run['dest_lang'],
			$run['model'],
			static function ( $message ) use ( $run, $item, $label ) {
				Runs::log( $run['id'], $item['id'], $label . ': ' . $message );
			}
		);

		if ( is_wp_error( $result ) ) {
			Runs::log( $run['id'], $item['id'], $label . ': model error: ' . $result->get_error_message() );
			return Runs::update_item(
				$item['id'],
				array(
					'status'        => 'error',
					'error_message' => $result->get_error_message(),
				)
			);
		}

		$usage = $result['usage'];
		$cost  = OpenRouter::estimate_cost( $usage['prompt_tokens'], $usage['completion_tokens'], $run['input_rate'], $run['output_rate'] );

		self::warn_missing_keys( $run, $item, $result['fields'] );

		Runs::log(
			$run['id'],
			$item['id'],
			sprintf( '%s: translated - %s', $label, self::cost_label( $cost ) )
		);

		return Runs::update_item(
			$item['id'],
			array(
				'error_message'     => null,
				'preview'           => $result['fields'],
				'prompt_tokens'     => $usage['prompt_tokens'],
				'completion_tokens' => $usage['completion_tokens'],
				'cost_usd'          => $cost,
			)
		);
	}

	protected static function warn_missing_keys( array $run, array $item, array $translations ) {
		$missing = array();
		foreach ( (array) $item['fields'] as $field ) {
			if ( ! array_key_exists( $field['key'], $translations ) ) {
				$missing[] = $field['key'];
			}
		}
		if ( $missing ) {
			Runs::log( $run['id'], $item['id'], self::post_label( $item['source_post_id'] ) . ': fields missing from the model reply - ' . implode( ', ', $missing ) );
		}
	}

	/**
	 * Write phase: create/find the destination post and write every selected
	 * data type into it. Serialised across workers by the write lock.
	 *
	 * @param array $run  Run row.
	 * @param array $item Item carrying a stored preview.
	 * @return array Updated item.
	 */
	protected static function write_step( array $run, array $item ) {
		return Runs::with_write_lock(
			static function () use ( $run, $item ) {
				$label        = self::post_label( $item['source_post_id'] );
				$types        = self::resolve_types( $run );
				$strict       = 'custom' === $run['data_mode'];
				$allow_create = 'full' === $run['data_mode'];

				$sync = PostSync::get_or_create_dest_post(
					$item['source_post_id'],
					$item['post_type'],
					$run['dest_lang'],
					$run['source_lang'],
					$allow_create
				);

				if ( $sync['error'] ) {
					Runs::log( $run['id'], $item['id'], $label . ': ' . $sync['error'] );
					return Runs::update_item(
						$item['id'],
						array(
							'status'          => 'error',
							'error_message'   => $sync['error'],
							'has_apply_error' => 1,
						)
					);
				}

				if ( ! $sync['post_id'] ) {
					Runs::log( $run['id'], $item['id'], $label . ': no destination post to write to, skipping' );
					return Runs::update_item(
						$item['id'],
						array(
							'status'        => 'skipped',
							'error_message' => __( 'No existing translation to write into.', 'perxel-ai-translate' ),
						)
					);
				}

				$dest_post_id = (int) $sync['post_id'];
				$translations = (array) $item['preview'];
				$results      = array();

				Runs::log(
					$run['id'],
					$item['id'],
					sprintf(
						'%s: writing into %s post #%d (%s)',
						$label,
						$run['dest_lang'],
						$dest_post_id,
						'update' === $item['action'] ? 'existing translation' : 'created'
					)
				);

				foreach ( $types as $type ) {
					$results[ $type ] = self::apply_type( $type, $run, $item, $dest_post_id, $translations, $strict );
				}

				$hard_fail = (bool) array_filter(
					$results,
					static function ( $r ) {
						return ! $r['success'];
					}
				);
				$soft_warn = (bool) array_filter(
					$results,
					static function ( $r ) {
						return $r['success'] && $r['message'];
					}
				);

				$failed_types = array();
				$summary      = array();
				foreach ( $results as $type => $r ) {
					$tl = self::type_label( $type );
					if ( ! $r['success'] ) {
						$failed_types[] = $tl;
						$summary[]      = $tl . ' failed' . ( '' !== (string) $r['message'] ? ' (' . $r['message'] . ')' : '' );
					} elseif ( '' !== (string) $r['message'] ) {
						$summary[] = $tl . ' (' . $r['message'] . ')';
					} else {
						$summary[] = $tl . ' ok';
					}
				}

				Runs::log( $run['id'], $item['id'], $label . ': ' . implode( ' · ', $summary ) );

				$updated = Runs::update_item(
					$item['id'],
					array(
						'status'          => $hard_fail ? 'error' : 'done',
						'dest_post_id'    => $dest_post_id,
						'results'         => $results,
						'has_warning'     => $soft_warn ? 1 : 0,
						'has_apply_error' => $hard_fail ? 1 : 0,
						'error_message'   => $hard_fail
							/* translators: %s: comma-separated data type labels. */
							? sprintf( __( 'Could not write: %s', 'perxel-ai-translate' ), implode( ', ', $failed_types ) )
							: null,
					)
				);

				if ( ! $hard_fail ) {
					self::maybe_sync_dest_status( $run, $updated );
				}

				return $updated;
			}
		);
	}

	/*
	---------------------------------------------------------------------
	 * Batched flow
	 * ------------------------------------------------------------------- */

	/**
	 * Picks item ids off the front of $pending to translate together, bounded by
	 * MAX_BATCH_JOBS and the model's completion-token budget.
	 *
	 * @param array $pending Pending items, oldest first.
	 * @param array $run     Run row.
	 * @return int[] Item ids to claim.
	 */
	public static function select_batch_ids( array $pending, array $run ) {
		if ( empty( $pending ) ) {
			return array();
		}

		$free = array();
		$llm  = array();

		foreach ( $pending as $item ) {
			if ( empty( $item['fields'] ) ) {
				$free[] = $item;
			} else {
				$llm[] = $item;
			}
		}

		if ( empty( $llm ) ) {
			return wp_list_pluck( $free, 'id' );
		}

		$system_prompt_chars = mb_strlen( OpenRouter::build_batch_system_prompt( Settings::get( 'prompt' ), $run['source_lang'], $run['dest_lang'] ) );
		$token_budget        = OpenRouter::get_batch_output_budget( $run['max_output'] );

		$selected = array();
		$total    = 0;

		foreach ( $llm as $item ) {
			if ( count( $selected ) >= OpenRouter::MAX_BATCH_JOBS ) {
				break;
			}
			$estimate = OpenRouter::estimate_job_tokens( self::build_payload( $item ), $system_prompt_chars );
			if ( $selected && $token_budget > 0 && ( $total + $estimate['completion_tokens'] ) > $token_budget ) {
				break;
			}
			$selected[] = $item;
			$total     += $estimate['completion_tokens'];
		}

		return array_merge( wp_list_pluck( $free, 'id' ), wp_list_pluck( $selected, 'id' ) );
	}

	/**
	 * Translate + write a claimed group of items. Items needing no LLM call are
	 * resolved directly; the rest go in one OpenRouter batch request; then every
	 * item's write phase runs.
	 *
	 * @param array $run   Run row.
	 * @param array $items Claimed items.
	 * @return array Updated items.
	 */
	public static function process_items( array $run, array $items ) {
		if ( empty( $items ) ) {
			return array();
		}

		$no_text = array();
		$skipped = array();
		$llm     = array();

		foreach ( $items as $item ) {
			$text_types = self::llm_types( self::resolve_types( $run ) );
			if ( empty( $text_types ) ) {
				$no_text[] = $item;
			} elseif ( empty( $item['fields'] ) ) {
				$skipped[] = $item;
			} else {
				$llm[] = $item;
			}
		}

		$to_write = array();

		foreach ( $no_text as $item ) {
			$to_write[] = Runs::update_item(
				$item['id'],
				array(
					'preview'           => array(),
					'prompt_tokens'     => 0,
					'completion_tokens' => 0,
					'cost_usd'          => 0.0,
					'error_message'     => null,
				)
			);
		}

		foreach ( $skipped as $item ) {
			Runs::log( $run['id'], $item['id'], self::post_label( $item['source_post_id'] ) . ': nothing to translate, skipping' );
			Runs::update_item(
				$item['id'],
				array(
					'status'        => 'skipped',
					'error_message' => __( 'Nothing to translate.', 'perxel-ai-translate' ),
				)
			);
		}

		if ( $llm ) {
			$translated = self::translate_batch_step( $run, $llm );
			foreach ( $translated as $item ) {
				if ( 'translating' === $item['status'] ) {
					$to_write[] = $item;
				}
			}
		}

		$out = array();
		foreach ( $to_write as $item ) {
			$out[] = self::write_step( $run, $item );
		}

		// Skipped items still need reporting back to the browser.
		foreach ( $skipped as $item ) {
			$fresh = Runs::item( $item['id'] );
			if ( $fresh ) {
				$out[] = $fresh;
			}
		}

		return $out;
	}

	/**
	 * Translate a claimed group. Items an earlier request already translated are
	 * kept as-is (their stored preview stands); the rest go to the model. A
	 * whole-group failure is split once and retried in halves so one bad post -
	 * or an over-large group - does not sink the batch.
	 *
	 * @param array $run   Run row.
	 * @param array $items Items with non-empty fields.
	 * @return array Updated items (status 'translating' on success, 'error' on failure).
	 */
	protected static function translate_batch_step( array $run, array $items ) {
		$fresh   = array();
		$already = array();
		foreach ( $items as $item ) {
			if ( ! empty( $item['preview'] ) ) {
				$already[] = $item;
			} else {
				$fresh[] = $item;
			}
		}

		if ( $already ) {
			Runs::log( $run['id'], 0, sprintf( 'Already translated by an earlier request (#%s); writing the stored result', implode( ', #', wp_list_pluck( $already, 'id' ) ) ) );
		}

		if ( empty( $fresh ) ) {
			return $already;
		}

		$changes = self::run_batch_call( $run, $fresh );
		$errored = array_filter(
			$changes,
			static function ( $c ) {
				return isset( $c['status'] ) && 'error' === $c['status'];
			}
		);

		// Every item errored with no partial results back: a request-level
		// failure, not a per-post one. Split once and retry each half.
		if ( count( $errored ) === count( $fresh ) && count( $fresh ) > 1 ) {
			$halves  = array_chunk( $fresh, (int) ceil( count( $fresh ) / 2 ) );
			$changes = array();
			foreach ( $halves as $half ) {
				Runs::log( $run['id'], 0, sprintf( 'Retrying a smaller group of %d (#%s) after a batch failure', count( $half ), implode( ', #', wp_list_pluck( $half, 'id' ) ) ) );
				$changes += self::run_batch_call( $run, $half );
			}
		}

		return array_merge( Runs::update_items( $changes ), $already );
	}

	/**
	 * One OpenRouter batch call for $items, returning a raw changes map
	 * (item id => update array); the caller persists. On a request-level failure
	 * every item in the group gets a 'error' change with the same message.
	 *
	 * @param array $run   Run row.
	 * @param array $items Items with non-empty fields and no stored preview.
	 * @return array<int,array> item id => changes.
	 */
	protected static function run_batch_call( array $run, array $items ) {
		$ids = wp_list_pluck( $items, 'id' );

		$payload      = array();
		$job_payloads = array();
		$field_count  = 0;
		foreach ( $items as $item ) {
			$job_payloads[ $item['id'] ]     = self::build_payload( $item );
			$payload[ (string) $item['id'] ] = $job_payloads[ $item['id'] ];
			$field_count                    += count( $job_payloads[ $item['id'] ] );
		}

		$type_names = implode( ', ', array_map( array( __CLASS__, 'type_label' ), self::llm_types( self::resolve_types( $run ) ) ) );
		Runs::log(
			$run['id'],
			0,
			sprintf(
				'Batch of %d posts (#%s): %s - %d text fields, %s to %s',
				count( $items ),
				implode( ', #', $ids ),
				$type_names,
				$field_count,
				$run['source_lang'],
				$run['dest_lang']
			)
		);

		$result = OpenRouter::translate_batch(
			$payload,
			$run['source_lang'],
			$run['dest_lang'],
			$run['model'],
			static function ( $message ) use ( $run ) {
				Runs::log( $run['id'], 0, $message );
			}
		);

		if ( is_wp_error( $result ) ) {
			Runs::log( $run['id'], 0, 'batch model error: ' . $result->get_error_message() );
			$changes = array();
			foreach ( $ids as $id ) {
				$changes[ $id ] = array(
					'status'        => 'error',
					'error_message' => $result->get_error_message(),
				);
			}
			return $changes;
		}

		$results = $result['results'];
		$usage   = $result['usage'];

		// OpenRouter returns one aggregate usage for the batch - split it across
		// items proportional to each item's estimated size so the run total
		// stays exact.
		$system_prompt_chars = mb_strlen( OpenRouter::build_batch_system_prompt( Settings::get( 'prompt' ), $run['source_lang'], $run['dest_lang'] ) );

		$weights = array();
		$sum     = 0;
		foreach ( $items as $item ) {
			$estimate               = OpenRouter::estimate_job_tokens( $job_payloads[ $item['id'] ], $system_prompt_chars );
			$weights[ $item['id'] ] = max( 1, $estimate['completion_tokens'] );
			$sum                   += $weights[ $item['id'] ];
		}

		$changes = array();

		foreach ( $items as $item ) {
			$id    = $item['id'];
			$label = self::post_label( $item['source_post_id'] );
			$key   = (string) $id;

			if ( ! array_key_exists( $key, $results ) || ! is_array( $results[ $key ] ) ) {
				Runs::log( $run['id'], $id, $label . ': missing from the batch response' );
				$changes[ $id ] = array(
					'status'        => 'error',
					'error_message' => __( 'Missing from the model\'s batch response.', 'perxel-ai-translate' ),
				);
				continue;
			}

			$translations = $results[ $key ];
			self::warn_missing_keys( $run, $item, $translations );

			$share     = $sum > 0 ? $weights[ $id ] / $sum : 0;
			$job_usage = array(
				'prompt_tokens'     => (int) round( $usage['prompt_tokens'] * $share ),
				'completion_tokens' => (int) round( $usage['completion_tokens'] * $share ),
			);
			$cost      = OpenRouter::estimate_cost( $job_usage['prompt_tokens'], $job_usage['completion_tokens'], $run['input_rate'], $run['output_rate'] );

			Runs::log( $run['id'], $id, sprintf( '%s: translated - batch share %s', $label, self::cost_label( $cost ) ) );

			$changes[ $id ] = array(
				'error_message'     => null,
				'preview'           => $translations,
				'prompt_tokens'     => $job_usage['prompt_tokens'],
				'completion_tokens' => $job_usage['completion_tokens'],
				'cost_usd'          => $cost,
			);
		}

		return $changes;
	}

	/*
	---------------------------------------------------------------------
	 * Per-type write dispatch (unchanged logic from the two-phase engine)
	 * ------------------------------------------------------------------- */

	/**
	 * @param string $type         Data type.
	 * @param array  $run          Run row.
	 * @param array  $item         Item.
	 * @param int    $dest_post_id Destination post id.
	 * @param array  $translations Preview map.
	 * @param bool   $strict       Custom mode.
	 * @return array{success:bool, message:?string}
	 */
	protected static function apply_type( $type, array $run, array $item, $dest_post_id, array $translations, $strict ) {
		$fields = (array) $item['fields'];

		switch ( $type ) {
			case 'title':
				$result = self::apply_text_field_group( self::field_defs_for_type( $fields, 'title' ), $dest_post_id, $translations, $strict );
				if ( $result['success'] && isset( $translations['post_title'] ) && '' !== $translations['post_title'] ) {
					$slug = sanitize_title( $translations['post_title'] );
					if ( $slug ) {
						wp_update_post(
							array(
								'ID'        => $dest_post_id,
								'post_name' => $slug,
							)
						);
					}
				}
				return $result;

			case 'content':
				return self::apply_text_field_group( self::field_defs_for_type( $fields, 'content' ), $dest_post_id, $translations, $strict );

			case 'rankmath':
				return self::apply_text_field_group( self::field_defs_for_type( $fields, 'rankmath' ), $dest_post_id, $translations, $strict );

			case 'acf':
				$text_result       = self::apply_text_field_group( self::field_defs_for_type( $fields, 'acf' ), $dest_post_id, $translations, $strict );
				$structural_result = PostSync::sync_acf( $item['source_post_id'], $dest_post_id, $strict );
				return self::combine_results( array( $text_result, $structural_result ) );

			case 'taxonomy':
				return PostSync::sync_taxonomies( $item['source_post_id'], $dest_post_id, $item['post_type'], $run['dest_lang'], $strict );

			case 'thumbnail':
				return PostSync::sync_thumbnail( $item['source_post_id'], $dest_post_id, $strict );
		}

		return array(
			'success' => true,
			'message' => null,
		);
	}

	protected static function field_defs_for_type( array $fields, $type ) {
		switch ( $type ) {
			case 'title':
				return array_values(
					array_filter(
						$fields,
						static function ( $field ) {
							return 'core' === $field['source'] && 'post_title' === $field['key'];
						}
					)
				);
			case 'content':
				return array_values(
					array_filter(
						$fields,
						static function ( $field ) {
							return 'core' === $field['source'] && in_array( $field['key'], array( 'post_excerpt', 'post_content' ), true );
						}
					)
				);
			case 'acf':
				return array_values(
					array_filter(
						$fields,
						static function ( $field ) {
							return in_array( $field['source'], array( 'acf', 'acf_nested' ), true );
						}
					)
				);
			case 'rankmath':
				return array_values(
					array_filter(
						$fields,
						static function ( $field ) {
							return 'rankmath' === $field['source'];
						}
					)
				);
		}
		return array();
	}

	/**
	 * @param array $field_defs   Field defs for one type.
	 * @param int   $dest_post_id Destination post id.
	 * @param array $translations Preview map.
	 * @param bool  $strict       Custom mode.
	 * @return array{success:bool, message:?string}
	 */
	protected static function apply_text_field_group( array $field_defs, $dest_post_id, array $translations, $strict ) {
		if ( empty( $field_defs ) ) {
			return array(
				'success' => true,
				'message' => null,
			);
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
				'message' => sprintf( __( 'Missing translation for: %s - nothing in this group was written.', 'perxel-ai-translate' ), implode( ', ', $missing ) ),
			);
		}

		foreach ( $field_defs as $field ) {
			if ( array_key_exists( $field['key'], $translations ) && '' !== $translations[ $field['key'] ] ) {
				Fields::set_value( $dest_post_id, $field, $translations[ $field['key'] ] );
			}
		}

		return array(
			'success' => true,
			/* translators: %s: comma-separated field keys. */
			'message' => $missing ? sprintf( __( 'Missing translation for: %s.', 'perxel-ai-translate' ), implode( ', ', $missing ) ) : null,
		);
	}

	protected static function combine_results( array $results ) {
		$failed   = array_filter(
			$results,
			static function ( $r ) {
				return ! $r['success'];
			}
		);
		$messages = array_filter(
			array_map(
				static function ( $r ) {
					return $r['message'];
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
	 * Sync the destination post's status to the source's, once every item in
	 * this run targeting the same destination has finished cleanly.
	 *
	 * @param array $run  Run row.
	 * @param array $item Just-written item.
	 */
	protected static function maybe_sync_dest_status( array $run, array $item ) {
		$dest_post_id = (int) $item['dest_post_id'];
		if ( ! $dest_post_id ) {
			return;
		}

		foreach ( Runs::items( $run['id'] ) as $sibling ) {
			if ( (int) $sibling['dest_post_id'] !== $dest_post_id ) {
				continue;
			}
			if ( in_array( $sibling['status'], array( 'pending', 'translating' ), true ) ) {
				return;
			}
			if ( 'error' === $sibling['status'] ) {
				Runs::log( $run['id'], $item['id'], self::post_label( $item['source_post_id'] ) . ': another item for post #' . $dest_post_id . ' errored - leaving its status untouched for review' );
				return;
			}
		}

		$source_status = get_post_status( $item['source_post_id'] );
		$dest_status   = get_post_status( $dest_post_id );

		if ( ! $source_status || $source_status === $dest_status ) {
			return;
		}

		wp_update_post(
			array(
				'ID'          => $dest_post_id,
				'post_status' => $source_status,
			)
		);
		Runs::log( $run['id'], $item['id'], sprintf( '%s: matched destination post #%d status to source (%s)', self::post_label( $item['source_post_id'] ), $dest_post_id, $source_status ) );
	}
}
