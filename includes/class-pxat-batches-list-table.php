<?php
/**
 * Batch history WP_List_Table.
 *
 * @package Perxel_AI_Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// WP_List_Table isn't autoloaded — only lazy-loaded by WP core when a screen
// actually needs it. This file is itself only required from
// PXAT_History_Page::render_page(), well after admin bootstrapping, so it's
// safe to pull the class in here if nothing else already has.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Batch history list, native WP admin table chrome: checkboxes, "Bulk
 * actions" dropdown (top and bottom), select-all — all handled by WP core's
 * own admin JS, nothing custom to maintain here.
 */
class PXAT_Batches_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'batch',
				'plural'   => 'batches',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'batch_id'   => __( 'Batch', 'perxel-ai-translate' ),
			'created_at' => __( 'Started', 'perxel-ai-translate' ),
			'duration'   => __( 'Run time', 'perxel-ai-translate' ),
			'model'      => __( 'Model', 'perxel-ai-translate' ),
			'total'      => __( 'Posts', 'perxel-ai-translate' ),
			'applied'    => __( 'Applied', 'perxel-ai-translate' ),
			// Full mode: a data type wrote with a non-blocking note (see
			// PXAT_Batch::get_counts()) — the job still counts as applied.
			// This used to be visible only by reading a job's log text;
			// putting it here means it can't go unnoticed across a batch.
			'warnings'   => __( 'Warnings', 'perxel-ai-translate' ),
			// Custom mode: a specifically-targeted data type failed outright
			// (or the destination post itself couldn't be found/created) —
			// the job never counts as applied.
			'errors'     => __( 'Apply errors', 'perxel-ai-translate' ),
			'mode'       => __( 'Mode', 'perxel-ai-translate' ),
			'tokens'     => __( 'Tokens / words', 'perxel-ai-translate' ),
			'cost'       => __( 'Cost', 'perxel-ai-translate' ),
			'created_by' => __( 'Created by', 'perxel-ai-translate' ),
		);
	}

	public function get_bulk_actions() {
		return array(
			'delete' => __( 'Delete', 'perxel-ai-translate' ),
		);
	}

	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="batch_ids[]" value="%s" />', esc_attr( $item['batch_id'] ) );
	}

	protected function column_batch_id( $item ) {
		$url = add_query_arg( array( 'page' => PXAT_Progress_Page::PAGE_SLUG, 'batch_id' => $item['batch_id'] ), admin_url( 'admin.php' ) );

		$delete_url = wp_nonce_url(
			add_query_arg( array( 'action' => 'pxat_delete_batch', 'batch_id' => $item['batch_id'] ), admin_url( 'admin-post.php' ) ),
			'pxat_delete_batch_' . $item['batch_id']
		);

		$actions = array(
			'delete' => '<a href="' . esc_url( $delete_url ) . '" class="pxat-delete-batch" onclick="return confirm(\'' . esc_js( __( 'Delete this batch? This cannot be undone.', 'perxel-ai-translate' ) ) . '\')">' . esc_html__( 'Delete', 'perxel-ai-translate' ) . '</a>',
		);

		return '<a href="' . esc_url( $url ) . '">' . esc_html( $item['batch_id'] ) . '</a>' . $this->row_actions( $actions );
	}

	protected function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? $item[ $column_name ] : '';
	}

	protected function column_warnings( $item ) {
		if ( ! $item['warnings'] ) {
			return '&mdash;';
		}

		return '<span class="pxat-inline-warning">'
			/* translators: %s: number of posts. */
			. esc_html( sprintf( _n( '%s post', '%s posts', $item['warnings'], 'perxel-ai-translate' ), number_format_i18n( $item['warnings'] ) ) )
			. '</span>';
	}

	protected function column_errors( $item ) {
		if ( ! $item['errors'] ) {
			return '&mdash;';
		}

		return '<span class="pxat-inline-error">'
			. esc_html( sprintf( _n( '%s post', '%s posts', $item['errors'], 'perxel-ai-translate' ), number_format_i18n( $item['errors'] ) ) )
			. '</span>';
	}

	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$items = array();

		foreach ( PXAT_Batch::list_batches() as $batch_id ) {
			$counts = PXAT_Batch::get_counts( $batch_id );
			$meta   = PXAT_Batch::get_meta( $batch_id );

			$items[] = array(
				'batch_id'   => $batch_id,
				'created_at' => $meta['created_at'] ? PXAT_Format::time_ago( $meta['created_at'] ) : '',
				'duration'   => PXAT_Format::duration( PXAT_Batch::get_duration_seconds( $batch_id ) ),
				'model'      => PXAT_OpenRouter::get_model( $meta['model'] )['label'],
				'total'      => $counts['total'],
				// applied/total, not applied/success — same metric the
				// progress page's own "Applied" stat uses (see
				// views/progress-page.php), so a batch with errored/skipped
				// jobs shows that in this ratio instead of reading as fully
				// applied just because every *successful* job got applied.
				'applied'    => $counts['applied'] . '/' . $counts['total'],
				'warnings'   => $counts['warnings'],
				'errors'     => $counts['apply_errors'],
				'mode'       => PXAT_Batch::mode_label( $meta ),
				'tokens'     => PXAT_Format::unit_label( $counts['prompt_tokens'] + $counts['completion_tokens'] ),
				'cost'       => PXAT_Format::cost( $counts['cost_usd'] ),
				'created_by' => $meta['created_by'] ? $meta['created_by'] : '',
			);
		}

		$this->items = $items;
	}
}
