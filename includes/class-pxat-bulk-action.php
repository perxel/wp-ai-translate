<?php
/**
 * Post-list bulk action entry point.
 *
 * @package Perxel_AI_Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the "Perxel AI Translate…" bulk action to the native post list-table
 * dropdown for every post type WPML is configured to translate. Selecting it
 * redirects to the confirmation page rather than acting immediately.
 */
class PXAT_Bulk_Action {

	const ACTION = 'pxat_bulk_translate';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_for_post_types' ) );
	}

	/**
	 * Wire the bulk action onto each translatable post type's list table.
	 */
	public static function register_for_post_types() {
		foreach ( PXAT_Post_Types::get_translatable_post_types() as $post_type ) {
			add_filter( "bulk_actions-edit-{$post_type}", array( __CLASS__, 'add_bulk_action' ) );
			add_filter( "handle_bulk_actions-edit-{$post_type}", array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
		}
	}

	/**
	 * @param array $actions Registered bulk actions.
	 * @return array
	 */
	public static function add_bulk_action( $actions ) {
		$actions[ self::ACTION ] = PXAT_NAME . '…';
		return $actions;
	}

	/**
	 * @param string $redirect_to Redirect URL.
	 * @param string $doaction    Selected action.
	 * @param int[]  $post_ids    Selected post IDs.
	 * @return string
	 */
	public static function handle_bulk_action( $redirect_to, $doaction, $post_ids ) {
		if ( self::ACTION !== $doaction ) {
			return $redirect_to;
		}

		if ( ! current_user_can( 'manage_options' ) || empty( $post_ids ) ) {
			return $redirect_to;
		}

		$post_ids  = array_map( 'intval', $post_ids );
		$post_type = get_post_type( $post_ids[0] );
		$token     = wp_generate_password( 12, false );

		set_transient(
			'pxat_sel_' . $token,
			array(
				'post_ids'  => $post_ids,
				'post_type' => $post_type,
			),
			HOUR_IN_SECONDS
		);

		return add_query_arg(
			array(
				'page' => PXAT_Confirm_Page::PAGE_SLUG,
				'sel'  => $token,
			),
			admin_url( 'admin.php' )
		);
	}
}
