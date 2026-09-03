<?php

namespace Perxel\AITranslate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Progress screen: the browser-driven translate loop and its review surface.
 * A run always writes into WordPress as it goes; this screen just shows what
 * happened and lets you retry a failed post or open the editor.
 */
class Progress {

	const NONCE = Admin::NONCE;

	/*
	---------------------------------------------------------------------
	 * Render
	 * ------------------------------------------------------------------- */

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$admin = Plugin::instance()->admin();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param.
		$run_id = isset( $_GET['run_id'] ) ? absint( wp_unslash( $_GET['run_id'] ) ) : 0;
		$run    = $run_id ? Runs::get( $run_id ) : null;

		if ( ! $run ) {
			$admin->screen(
				Admin::PAGE_DASHBOARD,
				__( 'Translation run', 'perxel-ai-translate' ),
				'notice',
				array(
					'type' => 'warning',
					'text' => sprintf(
						/* translators: %s: link to History. */
						__( 'That run was not found. See %s.', 'perxel-ai-translate' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=' . Admin::PAGE_HISTORY ) ) . '">' . esc_html__( 'History', 'perxel-ai-translate' ) . '</a>'
					),
				)
			);
			return;
		}

		$counts    = Runs::counts( $run_id );
		$is_done   = $counts['total'] > 0 && 0 === $counts['pending'] && 0 === $counts['translating'];
		$languages = Wpml::get_active_languages();

		$actions = '';
		if ( ! $is_done ) {
			$actions  = '<button type="button" class="button button-primary" id="pxat-start">' . esc_html__( 'Start translating', 'perxel-ai-translate' ) . '</button> ';
			$actions .= '<button type="button" class="button" id="pxat-stop" hidden>' . esc_html__( 'Stop', 'perxel-ai-translate' ) . '</button>';
		} else {
			$actions = '<a class="button" href="' . esc_url(
				wp_nonce_url(
					add_query_arg(
						array(
							'action' => 'pxat_rerun',
							'run_id' => $run_id,
						),
						admin_url( 'admin-post.php' )
					),
					'pxat_rerun_' . $run_id
				)
			) . '">' . esc_html__( 'Re-translate these posts', 'perxel-ai-translate' ) . '</a>';
		}

		$admin->screen(
			Admin::PAGE_DASHBOARD,
			__( 'Translation run', 'perxel-ai-translate' ),
			'progress',
			array(
				'run'         => $run,
				'counts'      => $counts,
				'is_done'     => $is_done,
				'items'       => array_map( array( __CLASS__, 'with_snapshots' ), Runs::items( $run_id ) ),
				'log_lines'   => Runs::log_lines( $run_id ),
				'languages'   => $languages,
				'model_label' => OpenRouter::get_model( $run['model'] )['label'],
				'elapsed'     => Runs::duration_seconds( $run_id ),
			),
			array( 'actions' => $actions )
		);
	}

	public static function localize() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav param.
		$run_id = isset( $_GET['run_id'] ) ? absint( wp_unslash( $_GET['run_id'] ) ) : 0;
		$run    = $run_id ? Runs::get( $run_id ) : null;

		wp_localize_script(
			'pxat-progress',
			'PXAT_Progress',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( self::NONCE ),
				'runId'       => $run_id,
				'batched'     => $run ? (bool) $run['batched'] : false,
				'workerCount' => $run ? Translator::worker_count( $run ) : 1,
				'displayUnit' => Format::display_unit(),
			)
		);
	}

	/*
	---------------------------------------------------------------------
	 * Item rendering (server-side; JS swaps the returned HTML in place)
	 * ------------------------------------------------------------------- */

	public static function post_snapshot( $post_id ) {
		$post_id = (int) $post_id;
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

	public static function render_post_cell( $post_id ) {
		$snap = self::post_snapshot( $post_id );
		if ( ! $snap ) {
			return '&mdash;';
		}
		$title = '' !== $snap['title'] ? $snap['title'] : sprintf( '(#%d, no title)', $snap['id'] );
		$html  = $snap['edit_url']
			? '<a href="' . esc_url( $snap['edit_url'] ) . '" target="_blank" rel="noopener">' . esc_html( $title ) . '</a>'
			: esc_html( $title );
		return $html . ' <span class="pxat-muted">(' . esc_html( $snap['status'] ) . ')</span>';
	}

	public static function status_label( $status ) {
		$labels = array(
			'pending'     => __( 'Pending', 'perxel-ai-translate' ),
			'translating' => __( 'Translating', 'perxel-ai-translate' ),
			'done'        => __( 'Done', 'perxel-ai-translate' ),
			'error'       => __( 'Error', 'perxel-ai-translate' ),
			'skipped'     => __( 'Skipped', 'perxel-ai-translate' ),
		);
		return $labels[ $status ] ?? $status;
	}

	public static function render_status_cell( array $item ) {
		$spin = 'translating' === $item['status'] ? '<span class="pxat-spin"></span> ' : '';
		return '<span class="pxat-badge pxat-badge--' . esc_attr( $item['status'] ) . '">' . $spin . esc_html( self::status_label( $item['status'] ) ) . '</span>';
	}

	public static function render_note_cell( array $item ) {
		if ( 'error' === $item['status'] && $item['error_message'] ) {
			return '<span class="pxat-inline-error">' . esc_html( $item['error_message'] ) . '</span>';
		}

		$notes = array();
		foreach ( (array) $item['results'] as $type => $result ) {
			if ( empty( $result['message'] ) ) {
				continue;
			}
			$class   = empty( $result['success'] ) ? 'pxat-inline-error' : 'pxat-inline-warning';
			$notes[] = '<span class="' . $class . '">' . esc_html( Translator::type_label( $type ) . ': ' . $result['message'] ) . '</span>';
		}
		return implode( '<br>', $notes );
	}

	public static function render_action_cell( array $item ) {
		$out = '';

		if ( 'error' === $item['status'] ) {
			$out .= '<button type="button" class="button button-small pxat-retry" data-item-id="' . esc_attr( $item['id'] ) . '">' . esc_html__( 'Retry', 'perxel-ai-translate' ) . '</button> ';
		}

		if ( ! empty( $item['preview'] ) || ! empty( $item['before'] ) ) {
			$out .= '<button type="button" class="button button-small pxat-view" data-item-id="' . esc_attr( $item['id'] ) . '">' . esc_html__( 'View', 'perxel-ai-translate' ) . '</button> ';
		}

		$dest = self::post_snapshot( $item['dest_post_id'] );
		if ( $dest && $dest['edit_url'] ) {
			$out .= '<a class="button button-small" href="' . esc_url( $dest['edit_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Edit', 'perxel-ai-translate' ) . '</a>';
		}

		return '' !== $out ? $out : '&mdash;';
	}

	/**
	 * Attach post snapshots and pre-rendered cell HTML so the browser can swap
	 * a row in place with no client-side templating.
	 *
	 * @param array $item Hydrated item.
	 * @return array
	 */
	public static function with_snapshots( array $item ) {
		$item['source_post'] = self::post_snapshot( $item['source_post_id'] );
		$item['dest_post']   = self::post_snapshot( $item['dest_post_id'] );
		$item['html']        = array(
			'source' => self::render_post_cell( $item['source_post_id'] ),
			'dest'   => self::render_post_cell( $item['dest_post_id'] ),
			'status' => self::render_status_cell( $item ),
			'note'   => self::render_note_cell( $item ),
			'action' => self::render_action_cell( $item ),
		);
		return $item;
	}

	/*
	---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------- */

	protected static function guard() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}
	}

	protected static function run_from_request() {
		$run_id = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran.
		$run    = $run_id ? Runs::get( $run_id ) : null;
		if ( ! $run ) {
			wp_send_json_error( array( 'message' => 'Run not found' ) );
		}
		return $run;
	}

	protected static function payload( $run_id, $items = null ) {
		$out = array(
			'counts'          => Runs::counts( $run_id ),
			'durationSeconds' => Runs::duration_seconds( $run_id ),
		);
		if ( null !== $items ) {
			$out['items'] = array_map( array( __CLASS__, 'with_snapshots' ), $items );
		}
		return $out;
	}

	public static function ajax_process() {
		self::guard();
		$run    = self::run_from_request();
		$run_id = $run['id'];
		$worker = wp_generate_uuid4();

		if ( $run['batched'] ) {
			$pending_ids   = Runs::peek_pending_ids( $run_id, OpenRouter::MAX_BATCH_JOBS );
			$pending_items = array_filter( array_map( array( 'Perxel\AITranslate\Runs', 'item' ), $pending_ids ) );
			$group_ids     = Translator::select_batch_ids( $pending_items, $run );
			$claimed       = Runs::claim_ids( $run_id, $worker, $group_ids );
		} else {
			$pending_ids = Runs::peek_pending_ids( $run_id, 1 );
			$claimed     = Runs::claim_ids( $run_id, $worker, $pending_ids );
		}

		if ( empty( $claimed ) ) {
			Runs::maybe_finish( $run_id );
			wp_send_json_success( array_merge( array( 'done' => true ), self::payload( $run_id ) ) );
		}

		$t0      = microtime( true );
		$updated = $run['batched']
			? Translator::process_items( $run, $claimed )
			: array( Translator::process_item( $run, $claimed[0] ) );
		Runs::add_active_seconds( $run_id, microtime( true ) - $t0 );

		Runs::maybe_finish( $run_id );

		wp_send_json_success( array_merge( array( 'done' => false ), self::payload( $run_id, $updated ) ) );
	}

	public static function ajax_retry() {
		self::guard();
		$run     = self::run_from_request();
		$item_id = isset( $_POST['item_id'] ) ? absint( wp_unslash( $_POST['item_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran.

		$item = $item_id ? Runs::item( $item_id ) : null;
		if ( ! $item || (int) $item['run_id'] !== (int) $run['id'] ) {
			wp_send_json_error( array( 'message' => 'Item not found' ) );
		}

		$item = Runs::update_item(
			$item_id,
			array(
				'status'          => 'translating',
				'error_message'   => null,
				'has_warning'     => 0,
				'has_apply_error' => 0,
				'results'         => array(),
			)
		);

		$t0      = microtime( true );
		$updated = Translator::process_item( $run, $item );
		Runs::add_active_seconds( $run['id'], microtime( true ) - $t0 );
		Runs::maybe_finish( $run['id'] );

		wp_send_json_success( self::payload( $run['id'], array( $updated ) ) );
	}

	public static function ajax_status() {
		self::guard();
		$run = self::run_from_request();
		wp_send_json_success( self::payload( $run['id'], Runs::items( $run['id'] ) ) );
	}

	/*
	---------------------------------------------------------------------
	 * Re-run
	 * ------------------------------------------------------------------- */

	public static function handle_rerun() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'perxel-ai-translate' ) );
		}

		$run_id = isset( $_GET['run_id'] ) ? absint( wp_unslash( $_GET['run_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked below.
		check_admin_referer( 'pxat_rerun_' . $run_id );

		$run = $run_id ? Runs::get( $run_id ) : null;
		if ( ! $run ) {
			wp_die( esc_html__( 'Run not found.', 'perxel-ai-translate' ) );
		}

		$post_ids  = array();
		$post_type = '';
		foreach ( Runs::items( $run_id ) as $item ) {
			if ( $item['source_post_id'] && ! in_array( $item['source_post_id'], $post_ids, true ) ) {
				$post_ids[] = $item['source_post_id'];
				$post_type  = $item['post_type'];
			}
		}

		if ( empty( $post_ids ) ) {
			wp_die( esc_html__( 'No posts to re-translate.', 'perxel-ai-translate' ) );
		}

		$token = Selection::store( $post_ids, $post_type );
		wp_safe_redirect( Selection::confirm_url( $token, array( 'dest_lang' => $run['dest_lang'] ) ) );
		exit;
	}
}
