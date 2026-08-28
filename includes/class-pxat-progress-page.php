<?php
/**
 * Batch progress screen and its AJAX loop.
 *
 * @package Perxel_AI_Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Progress page: browser-driven AJAX loop, one job (one OpenRouter request)
 * per request. Nothing is lost if the tab closes, state lives in the batch
 * JSON file, reopening the page resumes against whatever's still pending.
 */
class PXAT_Progress_Page {

	const PAGE_SLUG = 'pxat-progress';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'wp_ajax_pxat_process_job', array( __CLASS__, 'ajax_process_job' ) );
		add_action( 'wp_ajax_pxat_apply_job', array( __CLASS__, 'ajax_apply_job' ) );
		add_action( 'wp_ajax_pxat_retry_job', array( __CLASS__, 'ajax_retry_job' ) );
		add_action( 'wp_ajax_pxat_get_status', array( __CLASS__, 'ajax_get_status' ) );
		add_action( 'admin_post_pxat_rerun_batch', array( __CLASS__, 'handle_rerun' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function register_menu() {
		add_submenu_page( null, sprintf( '%s - %s', PXAT_NAME, __( 'Progress', 'perxel-ai-translate' ) ), __( 'Progress', 'perxel-ai-translate' ), 'manage_options', self::PAGE_SLUG, array( __CLASS__, 'render_page' ) );
	}

	/**
	 * Title + status snapshot for a post, read fresh each call so it reflects
	 * whatever a job has translated so far. Shared by the initial page render
	 * and the AJAX responses JS uses to update rows live, so both draw from
	 * the same source of truth instead of duplicating post lookups.
	 */
	public static function post_snapshot( $post_id ) {
		if ( ! $post_id ) {
			return null;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}

		return array(
			'id'       => $post_id,
			'title'    => $post->post_title,
			'status'   => $post->post_status,
			'edit_url' => get_edit_post_link( $post_id, 'raw' ),
		);
	}

	/**
	 * Renders a post_snapshot() as "Title (status)" linked to the edit
	 * screen, matching how confirm-page.php shows its source/dest columns.
	 */
	public static function render_post_cell( $post_id ) {
		$snapshot = self::post_snapshot( $post_id );
		if ( ! $snapshot ) {
			return '-';
		}

		$title = '' !== $snapshot['title'] ? $snapshot['title'] : sprintf( '(#%d, no title)', $snapshot['id'] );

		$html = $snapshot['edit_url']
			? '<a href="' . esc_url( $snapshot['edit_url'] ) . '" target="_blank" rel="noopener">' . esc_html( $title ) . '</a>'
			: esc_html( $title );

		$html .= ' <span class="description">(' . esc_html( $snapshot['status'] ) . ')</span>';

		return $html;
	}

	/**
	 * Attaches source/dest post snapshots to a job so JS can re-render both
	 * cells after a job finishes (translation may have changed the dest
	 * post's title) without a separate lookup request.
	 */
	protected static function with_post_snapshots( array $job ) {
		$job['source_post'] = self::post_snapshot( $job['source_post_id'] );
		$job['dest_post']   = self::post_snapshot( $job['dest_post_id'] );
		return $job;
	}

	/**
	 * Renders the Action column cell for a job: nothing while it's still
	 * pending/processing/errored/skipped, Preview + Apply once translated,
	 * or an "applied" badge (still with a Preview link) once written.
	 * Mirrored in JS (renderActionCell()) so rows update live without a
	 * page reload — keep both in sync when changing this.
	 */
	public static function render_action_cell( array $job ) {
		// Retry re-runs process() (and apply(), if auto_apply) against this
		// exact job_id — see ajax_retry_job(). Never "next pending"; only
		// this one row is touched.
		if ( 'error' === $job['status'] ) {
			return '<button type="button" class="button button-small pxat-retry-btn" data-job-id="' . esc_attr( $job['id'] ) . '">' . esc_html__( 'Retry', 'perxel-ai-translate' ) . '</button>';
		}

		if ( 'success' !== $job['status'] ) {
			return '&mdash;';
		}

		$html = '';

		// Job-level failure — apply() couldn't even find/create the
		// destination post, before any data type was even attempted.
		if ( ! empty( $job['apply_error'] ) ) {
			$html .= '<span class="pxat-inline-error">' . esc_html( $job['apply_error'] ) . '</span> ';
		}

		// Per-type breakdown — each selected data type's own outcome, once
		// apply() has run. Full mode's failures are warnings (that type
		// still wrote as much as it could); Custom mode's are hard errors
		// (nothing in that type was written) — see PXAT_Job_Processor::apply().
		if ( ! empty( $job['results'] ) ) {
			$html .= '<div class="pxat-type-results">' . self::render_type_results( $job['results'] ) . '</div>';
		}

		$html .= '<button type="button" class="button button-small pxat-preview-btn" data-job-id="' . esc_attr( $job['id'] ) . '">' . esc_html__( 'Preview', 'perxel-ai-translate' ) . '</button> ';

		if ( ! empty( $job['applied'] ) ) {
			$html .= '<span class="pxat-badge pxat-badge--applied">' . esc_html__( 'Applied', 'perxel-ai-translate' ) . '</span>';
		} else {
			$html .= '<button type="button" class="button button-primary button-small pxat-apply-btn" data-job-id="' . esc_attr( $job['id'] ) . '">' . esc_html__( 'Apply', 'perxel-ai-translate' ) . '</button>';
		}

		return $html;
	}

	/**
	 * One pill per selected data type, mirrored in JS (renderTypeResults())
	 * so rows update live without a page reload — keep both in sync.
	 */
	public static function render_type_results( array $results ) {
		$items = array();

		foreach ( $results as $type => $result ) {
			$label = esc_html( PXAT_Job_Processor::type_label( $type ) );

			if ( empty( $result['success'] ) ) {
				$items[] = '<span class="pxat-inline-error" title="' . esc_attr( $result['message'] ) . '">' . $label . ' ✗</span>';
			} elseif ( ! empty( $result['message'] ) ) {
				$items[] = '<span class="pxat-inline-warning" title="' . esc_attr( $result['message'] ) . '">' . $label . ' ⚠</span>';
			} else {
				$items[] = '<span class="pxat-inline-ok">' . $label . ' ✓</span>';
			}
		}

		return implode( ' ', $items );
	}

	public static function status_label( $status ) {
		$labels = array(
			'pending'    => __( 'Pending', 'perxel-ai-translate' ),
			'processing' => __( 'Processing', 'perxel-ai-translate' ),
			'success'    => __( 'Translated', 'perxel-ai-translate' ),
			'error'      => __( 'Error', 'perxel-ai-translate' ),
			'skipped'    => __( 'Skipped', 'perxel-ai-translate' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	public static function enqueue_assets( $hook ) {
		unset( $hook );

		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen switch.
			return;
		}

		wp_enqueue_style( 'pxat-admin', PXAT_URL . '/assets/css/admin.css', array(), PXAT_VERSION );
		wp_enqueue_script( 'pxat-format', PXAT_URL . '/assets/js/pxat-format.js', array( 'wp-i18n' ), PXAT_VERSION, true );
		wp_enqueue_script( 'pxat-progress', PXAT_URL . '/assets/js/progress.js', array( 'pxat-format', 'wp-i18n' ), PXAT_VERSION, true );
		wp_set_script_translations( 'pxat-progress', 'perxel-ai-translate', PXAT_DIR . '/languages' );
		wp_set_script_translations( 'pxat-format', 'perxel-ai-translate', PXAT_DIR . '/languages' );

		$batch_id = isset( $_GET['batch_id'] ) ? sanitize_text_field( wp_unslash( $_GET['batch_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$jobs     = $batch_id ? PXAT_Batch::read( $batch_id ) : null;
		$meta     = $batch_id ? PXAT_Batch::get_meta( $batch_id ) : array( 'created_at' => '' );

		wp_localize_script(
			'pxat-progress',
			'PXAT_Progress',
			array(
				'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
				'nonce'                => wp_create_nonce( 'pxat_progress' ),
				'batchId'              => $batch_id,
				'displayUnit'          => PXAT_Format::display_unit(),
				// The translate loop is never auto-started — a reload always
				// lands paused (see views/progress-page.php's Start button in
				// progress.js). The Preview dialog still reads straight out of
				// this initial snapshot (preview/before/action, in addition to
				// the fields already used for rendering), so it needs no
				// separate AJAX call.
				'jobs'                 => $jobs ? array_map( array( __CLASS__, 'with_post_snapshots' ), $jobs ) : array(),
				// Auto / Auto (batched): every job auto-applies the instant it
				// translates, so once the run finishes there's no separate
				// "preview and apply" step left — the completion message needs
				// to say something different than in Manual mode. See
				// workerFinished() in progress.js.
				'autoApply'            => ! empty( $meta['auto_apply'] ),
				// "Auto (batched)": runs several parallel processNext() loops
				// (see progress.js's startWorkers()) instead of one, since
				// PXAT_Batch::claim_pending_group() makes claiming a group
				// exclusive across concurrent AJAX requests. Manual/Auto
				// stay at exactly one worker, unchanged.
				'batchMode'            => ! empty( $meta['batch_mode'] ),
				'workerCount'          => PXAT_Job_Processor::BATCH_WORKER_COUNT,
			)
		);
	}

	public static function render_page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation params, each sanitized on use.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$batch_id = isset( $_GET['batch_id'] ) ? sanitize_text_field( wp_unslash( $_GET['batch_id'] ) ) : '';

		if ( ! $batch_id ) {
			echo '<div class="wrap"><p>'
				. sprintf(
					/* translators: %s: link to the history page */
					esc_html__( 'No batch selected. See %s.', 'perxel-ai-translate' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=' . PXAT_History_Page::PAGE_SLUG ) ) . '">' . esc_html__( 'Translation history', 'perxel-ai-translate' ) . '</a>'
				)
				. '</p></div>';
			return;
		}

		$jobs = PXAT_Batch::read( $batch_id );
		if ( null === $jobs ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Batch not found.', 'perxel-ai-translate' ) . '</p></div>';
			return;
		}

		$languages   = PXAT_WPML::get_active_languages();
		$source_lang = isset( $jobs[0]['source_lang'] ) ? $jobs[0]['source_lang'] : '';
		$dest_lang   = isset( $jobs[0]['dest_lang'] ) ? $jobs[0]['dest_lang'] : '';

		include PXAT_DIR . '/views/progress-page.php';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Re-runs a batch's source post list through the confirm page, the same
	 * way a fresh bulk-action selection would (see PXAT_Bulk_Action) — the
	 * post picker just already happened. Goes through confirm, not straight
	 * to a new batch, since dest posts/fields may have changed since the
	 * original run and still need re-checking. Always produces a new
	 * batch_id, the old batch/log is left untouched.
	 */
	public static function handle_rerun() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation params, each sanitized on use.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'perxel-ai-translate' ) );
		}

		$batch_id = isset( $_GET['batch_id'] ) ? sanitize_text_field( wp_unslash( $_GET['batch_id'] ) ) : '';
		if ( ! $batch_id ) {
			wp_die( esc_html__( 'Missing batch_id.', 'perxel-ai-translate' ) );
		}

		check_admin_referer( 'pxat_rerun_batch_' . $batch_id );

		$jobs = PXAT_Batch::read( $batch_id );
		if ( null === $jobs ) {
			wp_die( esc_html__( 'Batch not found.', 'perxel-ai-translate' ) );
		}

		$post_ids  = array();
		$post_type = '';
		$dest_lang = '';

		foreach ( $jobs as $job ) {
			if ( empty( $job['source_post_id'] ) || in_array( $job['source_post_id'], $post_ids, true ) ) {
				continue;
			}
			$post_ids[]  = $job['source_post_id'];
			$post_type   = $job['post_type'];
			$dest_lang   = $job['dest_lang'];
		}

		if ( empty( $post_ids ) ) {
			wp_die( esc_html__( 'No posts to re-translate.', 'perxel-ai-translate' ) );
		}

		$token = wp_generate_password( 12, false );
		set_transient(
			'pxat_sel_' . $token,
			array(
				'post_ids'  => $post_ids,
				'post_type' => $post_type,
			),
			HOUR_IN_SECONDS
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => PXAT_Confirm_Page::PAGE_SLUG,
					'sel'       => $token,
					'dest_lang' => $dest_lang,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	public static function ajax_process_job() {
		check_ajax_referer( 'pxat_progress', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$batch_id = isset( $_POST['batch_id'] ) ? sanitize_text_field( wp_unslash( $_POST['batch_id'] ) ) : '';
		if ( ! $batch_id ) {
			wp_send_json_error( array( 'message' => 'Missing batch_id' ) );
		}

		$meta = PXAT_Batch::get_meta( $batch_id );

		if ( ! empty( $meta['batch_mode'] ) ) {
			self::ajax_process_job_batch( $batch_id, $meta );
			return;
		}

		$job = PXAT_Batch::get_next_pending_job( $batch_id );

		if ( ! $job ) {
			wp_send_json_success(
				array(
					'done'   => true,
					'counts' => PXAT_Batch::get_counts( $batch_id ),
				)
			);
		}

		// Timed so PXAT_Batch::get_duration_seconds() reflects actual work
		// done, not wall-clock time since the batch was created — see
		// add_active_seconds()'s docblock.
		$started_at  = microtime( true );
		$updated_job = PXAT_Job_Processor::process( $batch_id, $job );

		// Auto-apply mode (see the "auto_apply" checkbox on the confirm
		// page): write the translation straight to WordPress the moment it
		// succeeds, no manual Preview/Apply step. Opt-in per batch, off by
		// default — reuses the exact same apply() the Apply button calls.
		if ( 'success' === $updated_job['status'] && ! empty( $updated_job['auto_apply'] ) ) {
			$updated_job = PXAT_Job_Processor::apply( $batch_id, $updated_job );
		}
		PXAT_Batch::add_active_seconds( $batch_id, microtime( true ) - $started_at );

		wp_send_json_success(
			array(
				'done'            => false,
				'job'             => self::with_post_snapshots( $updated_job ),
				'counts'          => PXAT_Batch::get_counts( $batch_id ),
				'durationSeconds' => PXAT_Batch::get_duration_seconds( $batch_id ),
			)
		);
	}

	/**
	 * "Auto (batched)" counterpart to ajax_process_job()'s single-job body:
	 * same polling contract (one AJAX round-trip processes "the next unit of
	 * work" and reports back), except that unit is a group of jobs sent to
	 * OpenRouter in one request. Responds with a `jobs` array instead of a
	 * single `job` — see progress.js's processNext() for the matching branch.
	 *
	 * This mode runs PXAT_Progress.workerCount of these AJAX loops in
	 * parallel from the browser (see progress.js's startWorkers()), so
	 * "claim" has to be exclusive: PXAT_Batch::claim_pending_group() picks
	 * this call's group AND marks it 'processing' under one file lock, so a
	 * second worker's claim landing at the same moment can never see (and
	 * re-send) the same jobs this one just took.
	 *
	 * `done: true` here means only "nothing left for THIS worker to claim
	 * right now" — not that the whole batch is finished, since other
	 * workers may still be mid-request. progress.js tracks that distinction
	 * itself (see workerFinished()); the page-wide "done" state the user
	 * sees is still just PXAT_Batch::get_counts() showing 0 pending/processing.
	 */
	protected static function ajax_process_job_batch( $batch_id, array $meta ) {
		$model_id = $meta['model'];

		$group = PXAT_Batch::claim_pending_group(
			$batch_id,
			function ( array $pending ) use ( $model_id ) {
				return PXAT_Job_Processor::select_batch( $pending, $model_id );
			}
		);

		if ( ! $group ) {
			wp_send_json_success(
				array(
					'done'   => true,
					'counts' => PXAT_Batch::get_counts( $batch_id ),
				)
			);
		}

		$started_at   = microtime( true );
		$updated_jobs = PXAT_Job_Processor::process_batch( $batch_id, $group );
		PXAT_Batch::add_active_seconds( $batch_id, microtime( true ) - $started_at );

		wp_send_json_success(
			array(
				'done'            => false,
				'jobs'            => array_map( array( __CLASS__, 'with_post_snapshots' ), $updated_jobs ),
				'counts'          => PXAT_Batch::get_counts( $batch_id ),
				'durationSeconds' => PXAT_Batch::get_duration_seconds( $batch_id ),
			)
		);
	}

	/**
	 * Writes one previously-translated job into WordPress. Unlike
	 * ajax_process_job(), which always grabs "the next pending job" (a
	 * strict queue), this targets a specific job_id — Apply is always
	 * initiated against a row (or rows) the user picked on the Review step,
	 * whether one row's Apply button or the client-side loop behind "Apply
	 * All".
	 */
	public static function ajax_apply_job() {
		check_ajax_referer( 'pxat_progress', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$batch_id = isset( $_POST['batch_id'] ) ? sanitize_text_field( wp_unslash( $_POST['batch_id'] ) ) : '';
		$job_id   = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';

		if ( ! $batch_id || ! $job_id ) {
			wp_send_json_error( array( 'message' => 'Missing batch_id or job_id' ) );
		}

		$jobs = PXAT_Batch::read( $batch_id );
		$job  = null;
		foreach ( (array) $jobs as $candidate ) {
			if ( (string) $candidate['id'] === $job_id ) {
				$job = $candidate;
				break;
			}
		}

		if ( ! $job ) {
			wp_send_json_error( array( 'message' => 'Job not found' ) );
		}

		$started_at  = microtime( true );
		$updated_job = PXAT_Job_Processor::apply( $batch_id, $job );
		PXAT_Batch::add_active_seconds( $batch_id, microtime( true ) - $started_at );

		wp_send_json_success(
			array(
				'job'             => self::with_post_snapshots( $updated_job ),
				'counts'          => PXAT_Batch::get_counts( $batch_id ),
				'durationSeconds' => PXAT_Batch::get_duration_seconds( $batch_id ),
			)
		);
	}

	/**
	 * Re-runs process() (and apply(), if auto_apply) against exactly one
	 * job_id — the row's own Retry button. Same targeted-lookup pattern as
	 * ajax_apply_job(), deliberately not PXAT_Batch::get_next_pending_job():
	 * retrying one failed row must never touch any other job in the batch.
	 */
	public static function ajax_retry_job() {
		check_ajax_referer( 'pxat_progress', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$batch_id = isset( $_POST['batch_id'] ) ? sanitize_text_field( wp_unslash( $_POST['batch_id'] ) ) : '';
		$job_id   = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';

		if ( ! $batch_id || ! $job_id ) {
			wp_send_json_error( array( 'message' => 'Missing batch_id or job_id' ) );
		}

		$jobs = PXAT_Batch::read( $batch_id );
		$job  = null;
		foreach ( (array) $jobs as $candidate ) {
			if ( (string) $candidate['id'] === $job_id ) {
				$job = $candidate;
				break;
			}
		}

		if ( ! $job ) {
			wp_send_json_error( array( 'message' => 'Job not found' ) );
		}

		$started_at  = microtime( true );
		$updated_job = PXAT_Job_Processor::process( $batch_id, $job );

		if ( 'success' === $updated_job['status'] && ! empty( $updated_job['auto_apply'] ) ) {
			$updated_job = PXAT_Job_Processor::apply( $batch_id, $updated_job );
		}
		PXAT_Batch::add_active_seconds( $batch_id, microtime( true ) - $started_at );

		wp_send_json_success(
			array(
				'job'             => self::with_post_snapshots( $updated_job ),
				'counts'          => PXAT_Batch::get_counts( $batch_id ),
				'durationSeconds' => PXAT_Batch::get_duration_seconds( $batch_id ),
			)
		);
	}

	/**
	 * Read-only snapshot of a batch. ajax_process_job() blocks for the full
	 * duration of one OpenRouter call (up to 90s), so the browser polls this
	 * on a separate, fast request to show which job is currently running
	 * instead of leaving the page looking frozen.
	 */
	public static function ajax_get_status() {
		check_ajax_referer( 'pxat_progress', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$batch_id = isset( $_POST['batch_id'] ) ? sanitize_text_field( wp_unslash( $_POST['batch_id'] ) ) : '';
		if ( ! $batch_id ) {
			wp_send_json_error( array( 'message' => 'Missing batch_id' ) );
		}

		$jobs = PXAT_Batch::read( $batch_id );
		if ( null === $jobs ) {
			wp_send_json_error( array( 'message' => 'Batch not found' ) );
		}

		wp_send_json_success(
			array(
				'jobs'            => array_map( array( __CLASS__, 'with_post_snapshots' ), $jobs ),
				'counts'          => PXAT_Batch::get_counts( $batch_id ),
				'durationSeconds' => PXAT_Batch::get_duration_seconds( $batch_id ),
			)
		);
	}
}
