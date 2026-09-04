<?php

namespace Perxel\AITranslate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * History screen: every translation run, kept until deleted. All-time totals
 * live on the Dashboard; this screen is the list plus per-run delete.
 */
class History {

	/**
	 * @return array View variables.
	 */
	public static function data() {
		$table  = new RunsListTable();
		$notice = null;

		if ( 'delete' === $table->current_action() ) {
			$notice = self::handle_bulk_delete();
		}

		$table->prepare_items();

		return array(
			'table'    => $table,
			'notice'   => $notice,
			'has_rows' => ! empty( $table->items ),
			'totals'   => Runs::totals(),
		);
	}

	/**
	 * @return string|null Result message.
	 */
	protected static function handle_bulk_delete() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce checked below.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'perxel-ai-translate' ) );
		}

		check_admin_referer( 'bulk-runs' );

		$run_ids = isset( $_REQUEST['run_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['run_ids'] ) ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$run_ids = array_filter( $run_ids );
		foreach ( $run_ids as $run_id ) {
			Runs::delete( $run_id );
		}

		if ( ! $run_ids ) {
			return null;
		}

		return sprintf(
			/* translators: %s: number of deleted runs. */
			_n( 'Deleted %s run.', 'Deleted %s runs.', count( $run_ids ), 'perxel-ai-translate' ),
			number_format_i18n( count( $run_ids ) )
		);
	}
}
