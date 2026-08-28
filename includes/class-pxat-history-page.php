<?php
/**
 * Batch history screen.
 *
 * @package Perxel_AI_Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Batch history: every dry run and its apply progress, permanently (batches
 * are only ever removed here, manually). Kept separate from
 * PXAT_Progress_Page, which owns a single batch's own translate/apply flow —
 * different concerns, so a different page/class.
 */
class PXAT_History_Page {

	const PAGE_SLUG = 'pxat-history';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_pxat_delete_batch', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function register_menu() {
		add_submenu_page( null, sprintf( '%s - %s', PXAT_NAME, __( 'Translation history', 'perxel-ai-translate' ) ), __( 'Translation history', 'perxel-ai-translate' ), 'manage_options', self::PAGE_SLUG, array( __CLASS__, 'render_page' ) );
	}

	public static function enqueue_assets( $hook ) {
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen switch.
			return;
		}

		wp_enqueue_style( 'pxat-admin', PXAT_URL . '/assets/css/admin.css', array(), PXAT_VERSION );
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! class_exists( 'PXAT_Batches_List_Table' ) ) {
			require_once PXAT_DIR . '/includes/class-pxat-batches-list-table.php';
		}

		$table  = new PXAT_Batches_List_Table();
		$notice = null;

		if ( 'delete' === $table->current_action() ) {
			$notice = self::handle_bulk_delete();
		}

		$table->prepare_items();

		echo '<div class="wrap pxat-wrap"><h1>' . esc_html( sprintf( '%s - %s', PXAT_NAME, __( 'Translation batches', 'perxel-ai-translate' ) ) ) . '</h1>';

		if ( $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
		}

		if ( empty( $table->items ) ) {
			echo '<p>' . esc_html__( 'No batches yet.', 'perxel-ai-translate' ) . '</p>';
			$footer_exclude = self::PAGE_SLUG;
			include PXAT_DIR . '/views/footer.php';
			echo '</div>';
			return;
		}

		self::render_stats();

		echo '<form method="post">';
		wp_nonce_field( 'bulk-batches' );
		$table->display();
		echo '</form>';

		$footer_exclude = self::PAGE_SLUG;
		include PXAT_DIR . '/views/footer.php';

		echo '</div>';
	}

	/**
	 * Totals across every batch in history — same PXAT_Batch::get_counts()
	 * each batch row already computes, just summed instead of shown per-row.
	 * Same .pxat-stats/.pxat-stat-card markup as the single-batch progress
	 * page, so it reads as the same dashboard, just at the "all time" scope.
	 */
	protected static function render_stats() {
		$totals = array(
			'success'           => 0,
			'warnings'          => 0,
			'apply_errors'      => 0,
			'cost_usd'          => 0.0,
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
		);

		foreach ( PXAT_Batch::list_batches() as $batch_id ) {
			$counts                       = PXAT_Batch::get_counts( $batch_id );
			$totals['success']           += $counts['success'];
			$totals['warnings']          += $counts['warnings'];
			$totals['apply_errors']      += $counts['apply_errors'];
			$totals['cost_usd']          += $counts['cost_usd'];
			$totals['prompt_tokens']     += $counts['prompt_tokens'];
			$totals['completion_tokens'] += $counts['completion_tokens'];
		}
		?>
		<div class="pxat-stats">
			<div class="pxat-stat-card">
				<div class="pxat-stat-label"><?php esc_html_e( 'Posts translated', 'perxel-ai-translate' ); ?></div>
				<div class="pxat-stat-value"><?php echo esc_html( $totals['success'] ); ?></div>
			</div>
			<?php if ( $totals['warnings'] > 0 ) : ?>
			<div class="pxat-stat-card">
				<div class="pxat-stat-label"><?php esc_html_e( 'Warnings', 'perxel-ai-translate' ); ?></div>
				<div class="pxat-stat-value"><span class="pxat-inline-warning"><?php echo esc_html( $totals['warnings'] ); ?></span></div>
				<div class="pxat-stat-sub"><?php esc_html_e( 'posts (Full mode) with data that did not copy completely', 'perxel-ai-translate' ); ?></div>
			</div>
			<?php endif; ?>
			<?php if ( $totals['apply_errors'] > 0 ) : ?>
			<div class="pxat-stat-card">
				<div class="pxat-stat-label"><?php esc_html_e( 'Apply errors', 'perxel-ai-translate' ); ?></div>
				<div class="pxat-stat-value"><span class="pxat-inline-error"><?php echo esc_html( $totals['apply_errors'] ); ?></span></div>
				<div class="pxat-stat-sub"><?php esc_html_e( 'posts (Custom mode) that failed to apply', 'perxel-ai-translate' ); ?></div>
			</div>
			<?php endif; ?>
			<div class="pxat-stat-card">
				<div class="pxat-stat-label"><?php esc_html_e( 'Cost', 'perxel-ai-translate' ); ?></div>
				<div class="pxat-stat-value"><?php echo esc_html( PXAT_Format::cost( $totals['cost_usd'] ) ); ?></div>
			</div>
			<div class="pxat-stat-card">
				<div class="pxat-stat-label"><?php esc_html_e( 'Volume', 'perxel-ai-translate' ); ?></div>
				<div class="pxat-stat-value"><?php echo esc_html( PXAT_Format::unit_label( $totals['prompt_tokens'] + $totals['completion_tokens'] ) ); ?></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Bulk "Delete" from the batch history table (checkboxes + Bulk actions
	 * dropdown, top or bottom — both wired up by WP_List_Table/WP core admin
	 * JS). Same underlying delete as the per-row "Delete" link, just applied to
	 * every checked batch_id in one request.
	 */
	protected static function handle_bulk_delete() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation params, each sanitized on use.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'perxel-ai-translate' ) );
		}

		check_admin_referer( 'bulk-batches' );

		$batch_ids = isset( $_POST['batch_ids'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['batch_ids'] ) ) : array();

		foreach ( $batch_ids as $batch_id ) {
			PXAT_Batch::delete( $batch_id );
		}

		if ( ! $batch_ids ) {
			return null;
		}

		return sprintf(
			/* translators: %d: number of deleted batches */
			/* translators: %s: number of deleted batches. */
			_n( 'Deleted %s batch.', 'Deleted %s batches.', count( $batch_ids ), 'perxel-ai-translate' ),
			number_format_i18n( count( $batch_ids ) )
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	public static function handle_delete() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation params, each sanitized on use.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'perxel-ai-translate' ) );
		}

		$batch_id = isset( $_GET['batch_id'] ) ? sanitize_text_field( wp_unslash( $_GET['batch_id'] ) ) : '';
		if ( ! $batch_id ) {
			wp_die( esc_html__( 'Missing batch_id.', 'perxel-ai-translate' ) );
		}

		check_admin_referer( 'pxat_delete_batch_' . $batch_id );

		PXAT_Batch::delete( $batch_id );

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG ), admin_url( 'admin.php' ) ) );
		exit;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}
