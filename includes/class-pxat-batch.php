<?php
/**
 * File-based batch job log/queue.
 *
 * @package Perxel_AI_Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * File-based job log/queue. One JSON array per batch:
 * wp-content/uploads/perxel-ai-translate/logs/batch-{batch_id}.json
 *
 * Serves as both the live queue the AJAX loop reads/writes during
 * processing, and the permanent log afterward. No custom DB table.
 */
class PXAT_Batch {

	public static function file_path( $batch_id ) {
		// Whitelist charset: batch_id can arrive from a GET/POST param, and this builds a filesystem path.
		$safe = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $batch_id );
		return trailingslashit( PXAT_LOG_DIR ) . 'batch-' . $safe . '.json';
	}

	public static function generate_id() {
		return gmdate( 'Ymd-His' ) . '-' . substr( wp_generate_password( 8, false, false ), 0, 8 );
	}

	public static function save( $batch_id, array $jobs ) {
		$path = self::file_path( $batch_id );
		file_put_contents( $path, wp_json_encode( $jobs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ), LOCK_EX );
	}

	public static function read( $batch_id ) {
		$path = self::file_path( $batch_id );
		if ( ! file_exists( $path ) ) {
			return null;
		}

		$raw  = file_get_contents( $path );
		$jobs = json_decode( $raw, true );

		return is_array( $jobs ) ? $jobs : null;
	}

	/**
	 * Runs $mutator against this batch's current jobs under one exclusive
	 * file lock held for the whole read -> mutate -> write cycle. Every
	 * write to a batch file goes through here — not just save() — because
	 * "Auto (batched)" mode runs several parallel AJAX workers against the
	 * same batch (see PXAT_Progress.workerCount / claim_pending_group()
	 * below): save()'s own LOCK_EX only serializes the write step, so two
	 * workers that both read() before either writes would silently lose one
	 * of their changes (last write wins, but on the whole file, so it wins
	 * over the OTHER worker's change too, not just its own prior state).
	 * Holding the lock across the read is what actually prevents that.
	 *
	 * @param callable $mutator array $jobs -> [ array $jobs_to_write, mixed $result ].
	 * @return mixed $result from $mutator, or null if the batch file is missing/unreadable.
	 */
	protected static function with_lock( $batch_id, callable $mutator ) {
		$path = self::file_path( $batch_id );
		if ( ! file_exists( $path ) ) {
			return null;
		}

		$handle = fopen( $path, 'r+' );
		if ( ! $handle ) {
			return null;
		}

		if ( ! flock( $handle, LOCK_EX ) ) {
			fclose( $handle );
			return null;
		}

		$raw  = stream_get_contents( $handle );
		$jobs = json_decode( $raw, true );

		if ( ! is_array( $jobs ) ) {
			flock( $handle, LOCK_UN );
			fclose( $handle );
			return null;
		}

		list( $jobs, $result ) = call_user_func( $mutator, $jobs );

		ftruncate( $handle, 0 );
		rewind( $handle );
		fwrite( $handle, wp_json_encode( $jobs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		fflush( $handle );

		flock( $handle, LOCK_UN );
		fclose( $handle );

		return $result;
	}

	// Not batch-specific — a fixed file, since PXAT_Job_Processor::apply()
	// needs to serialize against every OTHER concurrent apply() call
	// regardless of which batch(es) they belong to, not just within one
	// batch's own two "Auto (batched)" workers.
	const APPLY_LOCK_FILE = 'apply.lock';

	/**
	 * Runs $fn with an exclusive lock held for its entire duration, so two
	 * processes can never be inside it at the same time. Used to wrap
	 * PXAT_Job_Processor::apply(): "Auto (batched)" mode runs 2 parallel
	 * AJAX workers, each of which can call apply() — which creates the
	 * destination post, registers it with WPML (wpml_set_element_language_details),
	 * and resolves/assigns taxonomy terms via WPML's own translation lookups —
	 * for a *different* job at the exact same instant. Two processes doing
	 * that concurrently is what was silently dropping taxonomy terms (and
	 * anything else PXAT_Post_Sync's granular sync functions do) under parallel workers,
	 * even though every term was already correctly translated in WPML: this
	 * removes the concurrent access itself rather than retrying around it.
	 * apply() does no network calls (translation already happened before
	 * it's ever invoked), so this costs negligible wait time even with both
	 * workers finishing translation at the same moment.
	 */
	public static function with_apply_lock( callable $fn ) {
		$path   = trailingslashit( PXAT_LOG_DIR ) . self::APPLY_LOCK_FILE;
		$handle = fopen( $path, 'c' ); // 'c': create if missing, never truncate — this file carries no content of its own, only ever used as a lock handle.

		if ( ! $handle ) {
			return call_user_func( $fn );
		}

		flock( $handle, LOCK_EX );

		try {
			return call_user_func( $fn );
		} finally {
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}
	}

	public static function update_job( $batch_id, $job_id, array $changes ) {
		return self::with_lock(
			$batch_id,
			function ( $jobs ) use ( $job_id, $changes ) {
				$now         = current_time( 'mysql' );
				$updated_job = null;

				foreach ( $jobs as &$job ) {
					if ( (string) $job['id'] === (string) $job_id ) {
						$job         = array_merge( $job, $changes, array( 'updated_at' => $now ) );
						$updated_job = $job;
						break;
					}
				}
				unset( $job );

				return array( $jobs, $updated_job );
			}
		);
	}

	/**
	 * Same as update_job(), for several jobs in one locked read/write — used
	 * by the "Auto (batched)" batch flow (PXAT_Job_Processor::process_batch())
	 * so writing back a whole group's results is one file write instead of N.
	 *
	 * @param array $changes_by_job_id job_id => changes array (same shape as update_job()'s $changes).
	 * @return array Updated job records, in the batch's own stored order.
	 */
	public static function update_jobs( $batch_id, array $changes_by_job_id ) {
		$result = self::with_lock(
			$batch_id,
			function ( $jobs ) use ( $changes_by_job_id ) {
				$now     = current_time( 'mysql' );
				$updated = array();

				foreach ( $jobs as &$job ) {
					if ( ! array_key_exists( $job['id'], $changes_by_job_id ) ) {
						continue;
					}
					$job       = array_merge( $job, $changes_by_job_id[ $job['id'] ], array( 'updated_at' => $now ) );
					$updated[] = $job;
				}
				unset( $job );

				return array( $jobs, $updated );
			}
		);

		return null === $result ? array() : $result;
	}

	/**
	 * Appends one line to a job's step log and writes it to disk immediately,
	 * which is what lets the browser poll (PXAT_Progress_Page::ajax_get_status)
	 * see granular progress ("collecting fields", "sending to LLM", ...) while
	 * the single long-running process request is still in flight. Also doubles
	 * as the permanent audit trail once the batch is done.
	 */
	public static function append_job_log( $batch_id, $job_id, $message ) {
		self::with_lock(
			$batch_id,
			function ( $jobs ) use ( $job_id, $message ) {
				$now = current_time( 'mysql' );
				$by  = self::current_user_label();

				foreach ( $jobs as &$job ) {
					if ( (string) $job['id'] === (string) $job_id ) {
						if ( empty( $job['log'] ) || ! is_array( $job['log'] ) ) {
							$job['log'] = array();
						}
						$job['log'][] = array(
							'at'      => $now,
							'by'      => $by,
							'message' => $message,
						);
						break;
					}
				}
				unset( $job );

				return array( $jobs, null );
			}
		);
	}

	/**
	 * Same as append_job_log(), for several jobs sharing one log line at once
	 * — a batch request's "sent to LLM" / "received response" steps apply to
	 * every job in that group simultaneously, since they're one HTTP call.
	 */
	public static function append_job_log_bulk( $batch_id, array $job_ids, $message ) {
		self::with_lock(
			$batch_id,
			function ( $jobs ) use ( $job_ids, $message ) {
				$now    = current_time( 'mysql' );
				$by     = self::current_user_label();
				$id_set = array_flip( $job_ids );

				foreach ( $jobs as &$job ) {
					if ( ! isset( $id_set[ $job['id'] ] ) ) {
						continue;
					}
					if ( empty( $job['log'] ) || ! is_array( $job['log'] ) ) {
						$job['log'] = array();
					}
					$job['log'][] = array(
						'at'      => $now,
						'by'      => $by,
						'message' => $message,
					);
				}
				unset( $job );

				return array( $jobs, null );
			}
		);
	}

	/**
	 * Atomically claims a subset of this batch's still-pending jobs: holds
	 * the with_lock() lock across the whole read -> select -> mark
	 * 'processing' -> write sequence, so two parallel "Auto (batched)"
	 * workers claiming at the same moment can never both pick the same job
	 * — whichever one's lock wins sees the other's claim already reflected
	 * once it gets its turn.
	 *
	 * @param callable $selector array $pending_jobs (already stale-reclaimed) -> array subset to claim.
	 * @return array The claimed jobs, already marked 'processing' — empty if nothing was pending or $selector chose nothing.
	 */
	public static function claim_pending_group( $batch_id, callable $selector ) {
		$result = self::with_lock(
			$batch_id,
			function ( $jobs ) use ( $selector ) {
				list( $jobs, $unused_changed ) = self::apply_stale_reclaim( $jobs );
				unset( $unused_changed ); // written back below regardless, since the lock is already held.

				$pending = array_values(
					array_filter(
						$jobs,
						function ( $job ) {
							return 'pending' === $job['status'];
						}
					)
				);

				$claimed = array();

				if ( $pending ) {
					$selected    = call_user_func( $selector, $pending );
					$claimed_ids = wp_list_pluck( $selected, 'id' );

					if ( $claimed_ids ) {
						$id_set = array_flip( $claimed_ids );
						$now    = current_time( 'mysql' );

						foreach ( $jobs as &$job ) {
							if ( ! isset( $id_set[ $job['id'] ] ) ) {
								continue;
							}
							$job['status']     = 'processing';
							$job['updated_at'] = $now;
							$claimed[]         = $job;
						}
						unset( $job );
					}
				}

				return array( $jobs, $claimed );
			}
		);

		return null === $result ? array() : $result;
	}

	/**
	 * Display name of whoever's browser triggered the current request (the
	 * AJAX loop always runs as the admin who has the Progress page open),
	 * so each log line can show who ran it, not just when.
	 */
	protected static function current_user_label() {
		$user = wp_get_current_user();
		return $user && $user->exists() ? $user->display_name : '';
	}

	// A single OpenRouter attempt is allowed 90s (see PXAT_OpenRouter::translate).
	// This loop only ever has one job in flight at a time, so a 'processing'
	// job still there past one attempt's worth of time is almost always a
	// crashed/killed request (PHP execution-time limit is the usual culprit,
	// and hosts commonly cap that well under 90s), safe to requeue. Rare
	// case: the original request is genuinely mid-429-retry and still alive;
	// reclaiming it early just costs one duplicate OpenRouter call, not data
	// loss, since the JSON write is last-write-wins.
	const STALE_PROCESSING_SECONDS = 90;

	// Same idea, sized for a "Auto (batched)" batch request (up to
	// MAX_BATCH_JOBS posts in one call, allowed PXAT_OpenRouter::BATCH_TIMEOUT
	// per attempt) — a flat, longer window rather than one scaled to the
	// actual group size, since that size isn't persisted anywhere a later
	// reclaim pass could read it back from.
	const STALE_PROCESSING_SECONDS_BATCH = 300;

	public static function get_next_pending_job( $batch_id ) {
		$pending = self::get_pending_jobs( $batch_id );
		return $pending ? $pending[0] : null;
	}

	/**
	 * All jobs still 'pending' in this batch, in stored order — the pool
	 * PXAT_Job_Processor::select_batch() groups into one "Auto (batched)"
	 * request, or (for the manual/auto single-job flow) the same list
	 * get_next_pending_job() already picked its first element from.
	 */
	public static function get_pending_jobs( $batch_id ) {
		self::reclaim_stale_jobs( $batch_id );

		$jobs = self::read( $batch_id );
		if ( ! $jobs ) {
			return array();
		}

		return array_values(
			array_filter(
				$jobs,
				function ( $job ) {
					return 'pending' === $job['status'];
				}
			)
		);
	}

	/**
	 * Requeues jobs left in 'processing' by a request that never finished
	 * (PHP execution-time limit, fatal error, dropped connection). Without
	 * this, such a job stays 'processing' forever, since get_next_pending_job()
	 * only ever matches 'pending', so it would never be retried again.
	 */
	protected static function reclaim_stale_jobs( $batch_id ) {
		self::with_lock(
			$batch_id,
			function ( $jobs ) {
				return self::apply_stale_reclaim( $jobs );
			}
		);
	}

	/**
	 * Pure transform behind reclaim_stale_jobs(): flips any 'processing' job
	 * stale past its window back to 'pending'. No I/O of its own — shared by
	 * reclaim_stale_jobs() (locks, reclaims, writes back on its own) and
	 * claim_pending_group() (already holding the lock for its own claim, so
	 * it folds reclaim into that same read -> write cycle instead of nesting
	 * a second lock acquisition on the same file).
	 *
	 * @return array [ array $jobs, bool $changed ]
	 */
	protected static function apply_stale_reclaim( array $jobs ) {
		$changed = false;
		$now     = current_time( 'timestamp' );

		foreach ( $jobs as &$job ) {
			if ( 'processing' !== $job['status'] ) {
				continue;
			}

			$stale_seconds = ! empty( $job['batch_mode'] ) ? self::STALE_PROCESSING_SECONDS_BATCH : self::STALE_PROCESSING_SECONDS;
			$updated_at    = isset( $job['updated_at'] ) ? strtotime( $job['updated_at'] ) : 0;
			if ( $updated_at && $updated_at > ( $now - $stale_seconds ) ) {
				continue;
			}

			$job['status']        = 'pending';
			$job['error_message'] = 'Requeued after an interrupted request.';
			$job['updated_at']    = current_time( 'mysql' );
			$changed               = true;
		}
		unset( $job );

		return array( $jobs, $changed );
	}

	/**
	 * Creation timestamp/author/model, read from the first job (all jobs in
	 * a batch are created in the same request, so they share the same
	 * values). Single source of truth for this batch-level metadata, so the
	 * batch list and a batch's own progress page both read it from here
	 * instead of each pulling $jobs[0] apart themselves.
	 */
	public static function get_meta( $batch_id ) {
		$jobs = self::read( $batch_id );

		return array(
			'created_at'    => $jobs && isset( $jobs[0]['created_at'] ) ? $jobs[0]['created_at'] : '',
			'created_by'    => $jobs && isset( $jobs[0]['created_by'] ) ? $jobs[0]['created_by'] : '',
			'model'         => $jobs && isset( $jobs[0]['model'] ) ? $jobs[0]['model'] : '',
			'auto_apply'    => $jobs && ! empty( $jobs[0]['auto_apply'] ),
			// "Auto (batched)" mode: implies auto_apply too (see
			// PXAT_Confirm_Page::handle_submit()), but additionally routes
			// this batch's jobs through PXAT_Job_Processor::process_batch()
			// instead of one-OpenRouter-call-per-post.
			'batch_mode'    => $jobs && ! empty( $jobs[0]['batch_mode'] ),
			// 'full' processes every data type; 'custom' processes only
			// 'custom_types' — see PXAT_Job_Processor::resolve_types() and
			// PXAT_Confirm_Page's data-axis. Single source of truth for
			// display (PXAT_Batches_List_Table, views/progress-page.php).
			'data_mode'     => $jobs && isset( $jobs[0]['data_mode'] ) ? $jobs[0]['data_mode'] : 'full',
			'custom_types'  => $jobs && isset( $jobs[0]['custom_types'] ) ? $jobs[0]['custom_types'] : array(),
			// Cumulative wall-clock time actually spent working this batch —
			// see add_active_seconds() / get_duration_seconds().
			'active_seconds' => $jobs && isset( $jobs[0]['active_seconds'] ) ? (float) $jobs[0]['active_seconds'] : 0.0,
		);
	}

	/**
	 * Adds to this batch's cumulative "actually processing" time (see
	 * get_duration_seconds()). Called once per ajax_process_job()/
	 * ajax_process_job_batch()/ajax_apply_job()/ajax_retry_job() request,
	 * with that request's own measured wall-clock duration — so the total
	 * only ever grows while a request is genuinely doing work, never while
	 * the batch sits paused (Stop clicked, tab closed, nobody has the page
	 * open). Stored on jobs[0], same convention as created_at/auto_apply/etc.
	 * (see get_meta()).
	 */
	public static function add_active_seconds( $batch_id, $seconds ) {
		if ( $seconds <= 0 ) {
			return;
		}

		self::with_lock(
			$batch_id,
			function ( $jobs ) use ( $seconds ) {
				if ( isset( $jobs[0] ) ) {
					$existing                  = isset( $jobs[0]['active_seconds'] ) ? (float) $jobs[0]['active_seconds'] : 0.0;
					$jobs[0]['active_seconds'] = $existing + $seconds;
				}
				return array( $jobs, null );
			}
		);
	}

	/**
	 * "Full" or "Custom (Title/Slug, Taxonomy)" — the data axis — plus the
	 * run mode, together in one label since both are decided together in
	 * Step 1. Shared by PXAT_Batches_List_Table and views/progress-page.php.
	 *
	 * @param array $meta get_meta()'s result.
	 */
	public static function mode_label( array $meta ) {
		if ( 'custom' === $meta['data_mode'] ) {
			$type_labels = array_map( array( 'PXAT_Job_Processor', 'type_label' ), $meta['custom_types'] );
			/* translators: %s: comma-separated list of data type labels. */
			$data_label = sprintf( __( 'Custom (%s)', 'perxel-ai-translate' ), implode( ', ', $type_labels ) );
		} else {
			$data_label = __( 'Full', 'perxel-ai-translate' );
		}

		if ( $meta['batch_mode'] ) {
			$run_label = __( 'Auto (batched)', 'perxel-ai-translate' );
		} elseif ( $meta['auto_apply'] ) {
			$run_label = __( 'Auto', 'perxel-ai-translate' );
		} else {
			$run_label = __( 'Manual', 'perxel-ai-translate' );
		}

		return $data_label . ' · ' . $run_label;
	}

	public static function get_counts( $batch_id ) {
		$jobs   = self::read( $batch_id );
		$counts = array(
			'total'             => 0,
			'pending'           => 0,
			'processing'        => 0,
			'success'           => 0,
			'error'             => 0,
			'skipped'           => 0,
			'applied'           => 0,
			// Full mode: jobs where at least one selected data type wrote
			// with a non-blocking note (e.g. a taxonomy term with no
			// destination-language translation, so it was skipped while the
			// rest of that type still wrote) — the job still counts as
			// 'applied'. Counted separately from 'error' since these jobs
			// still show a clean status otherwise: without this, such an
			// issue was only ever visible by reading a job's log text.
			'warnings'          => 0,
			// Custom mode (specifically-targeted data failed outright, so
			// nothing in that type was written — see
			// PXAT_Job_Processor::apply()'s $strict path), or a job-level
			// failure to even find/create the destination post
			// ('apply_error'). Either way the job never counts as 'applied'.
			'apply_errors'      => 0,
			'cost_usd'          => 0.0,
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
		);

		if ( ! $jobs ) {
			return $counts;
		}

		$counts['total'] = count( $jobs );

		foreach ( $jobs as $job ) {
			if ( isset( $counts[ $job['status'] ] ) ) {
				$counts[ $job['status'] ]++;
			}
			if ( ! empty( $job['applied'] ) ) {
				$counts['applied']++;
			}

			$results        = isset( $job['results'] ) && is_array( $job['results'] ) ? $job['results'] : array();
			$has_type_error = (bool) array_filter(
				$results,
				function ( $result ) {
					return empty( $result['success'] );
				}
			);
			$has_type_warning = (bool) array_filter(
				$results,
				function ( $result ) {
					return ! empty( $result['success'] ) && ! empty( $result['message'] );
				}
			);

			if ( ! empty( $job['apply_error'] ) || $has_type_error ) {
				$counts['apply_errors']++;
			} elseif ( $has_type_warning ) {
				$counts['warnings']++;
			}

			if ( ! empty( $job['cost_usd'] ) ) {
				$counts['cost_usd'] += (float) $job['cost_usd'];
			}
			if ( ! empty( $job['usage'] ) && is_array( $job['usage'] ) ) {
				$counts['prompt_tokens']     += isset( $job['usage']['prompt_tokens'] ) ? (int) $job['usage']['prompt_tokens'] : 0;
				$counts['completion_tokens'] += isset( $job['usage']['completion_tokens'] ) ? (int) $job['usage']['completion_tokens'] : 0;
			}
		}

		return $counts;
	}

	/**
	 * How long this batch has actually spent processing, in seconds — the
	 * sum of add_active_seconds() calls, i.e. real time inside
	 * ajax_process_job()/ajax_process_job_batch()/ajax_apply_job()/
	 * ajax_retry_job() requests, not wall-clock time since the batch was
	 * created. Deliberately excludes any time the batch sat paused/idle
	 * (Stop clicked, tab closed, nobody has the page open) — see
	 * PXAT_Progress_Page's Start/Stop buttons. Naturally "frozen" whenever
	 * nothing is running, no separate is_done branching needed: the number
	 * simply stops growing the moment the last request finishes. Single
	 * source of truth so the batch history table and a batch's own progress
	 * page ("Time" stat) agree.
	 */
	public static function get_duration_seconds( $batch_id ) {
		return self::get_meta( $batch_id )['active_seconds'];
	}

	public static function delete( $batch_id ) {
		$path = self::file_path( $batch_id );
		if ( ! file_exists( $path ) ) {
			return false;
		}

		return unlink( $path );
	}

	public static function list_batches() {
		$files   = glob( trailingslashit( PXAT_LOG_DIR ) . 'batch-*.json' );
		$batches = array();

		if ( $files ) {
			rsort( $files );
			foreach ( $files as $file ) {
				$batches[] = preg_replace( '/^batch-(.+)\.json$/', '$1', basename( $file ) );
			}
		}

		return $batches;
	}
}
