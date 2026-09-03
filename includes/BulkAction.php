<?php

namespace Perxel\AITranslate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the "Perxel AI Translate…" bulk action to every translatable post type's
 * list table. Selecting it redirects to the Confirm screen rather than acting
 * immediately.
 */
class BulkAction {

	const ACTION = 'pxat_bulk_translate';

	public function register() {
		add_action( 'admin_init', array( $this, 'register_for_post_types' ) );
	}

	public function register_for_post_types() {
		foreach ( PostTypes::get_translatable_post_types() as $post_type ) {
			add_filter( "bulk_actions-edit-{$post_type}", array( $this, 'add_bulk_action' ) );
			add_filter( "handle_bulk_actions-edit-{$post_type}", array( $this, 'handle_bulk_action' ), 10, 3 );
		}
	}

	/**
	 * @param array $actions Registered bulk actions.
	 * @return array
	 */
	public function add_bulk_action( $actions ) {
		$actions[ self::ACTION ] = PXAT_NAME . '…';
		return $actions;
	}

	/**
	 * @param string $redirect_to Redirect URL.
	 * @param string $doaction    Selected action.
	 * @param int[]  $post_ids    Selected post IDs.
	 * @return string
	 */
	public function handle_bulk_action( $redirect_to, $doaction, $post_ids ) {
		if ( self::ACTION !== $doaction ) {
			return $redirect_to;
		}

		if ( ! current_user_can( 'manage_options' ) || empty( $post_ids ) ) {
			return $redirect_to;
		}

		$post_ids  = array_map( 'intval', $post_ids );
		$post_type = get_post_type( $post_ids[0] );
		$token     = Selection::store( $post_ids, $post_type );

		return Selection::confirm_url( $token );
	}
}
