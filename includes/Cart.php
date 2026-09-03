<?php

namespace Perxel\AITranslate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The translation cart: a per-user, persistent list of source posts waiting to
 * be translated. Entry points (the post-list bulk action, the single-post
 * "Translate this page" bar item, "re-translate") append to it; the Confirm
 * screen reads it, and creating a run empties it.
 *
 * Stored in user meta so it survives a logout - you can keep filling it over
 * several sessions. One post type at a time: adding a different type is refused
 * (the Confirm screen shows why, with a "Clear cart" button).
 *
 * Shape: { post_ids: int[], post_type: string }.
 */
class Cart {

	const META_KEY = 'pxat_cart';

	/**
	 * @param int|null $user_id Defaults to the current user.
	 * @return array { post_ids: int[], post_type: string } - post_ids empty when the cart is empty.
	 */
	public static function get( $user_id = null ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		$data    = $user_id ? get_user_meta( $user_id, self::META_KEY, true ) : '';

		$post_ids  = ( is_array( $data ) && isset( $data['post_ids'] ) ) ? array_map( 'intval', (array) $data['post_ids'] ) : array();
		$post_type = ( is_array( $data ) && isset( $data['post_type'] ) ) ? (string) $data['post_type'] : '';

		return array(
			'post_ids'  => array_values( array_unique( array_filter( $post_ids ) ) ),
			'post_type' => $post_type,
		);
	}

	/**
	 * @return int[] Post ids currently in the cart.
	 */
	public static function ids() {
		return self::get()['post_ids'];
	}

	/**
	 * @return string The cart's post type, or '' when empty.
	 */
	public static function post_type() {
		return self::get()['post_type'];
	}

	/**
	 * @return int Number of posts in the cart.
	 */
	public static function count() {
		return count( self::ids() );
	}

	/**
	 * Append posts to the cart.
	 *
	 * @param int[]  $post_ids  Source post ids.
	 * @param string $post_type Their (single) post type.
	 * @return array {
	 *     @type int    $added         Newly added.
	 *     @type int    $skipped       Already in the cart.
	 *     @type bool   $type_conflict True when the cart already holds a different post type (nothing added).
	 *     @type string $cart_type     The cart's existing post type when there was a conflict.
	 * }
	 */
	public static function add( array $post_ids, $post_type ) {
		$user_id  = get_current_user_id();
		$incoming = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
		$cart     = self::get( $user_id );

		if ( $cart['post_type'] && $post_type && $cart['post_type'] !== $post_type && ! empty( $cart['post_ids'] ) ) {
			set_transient(
				'pxat_cart_conflict_' . $user_id,
				array(
					'tried'     => count( $incoming ),
					'cart_type' => $cart['post_type'],
					'new_type'  => (string) $post_type,
				),
				MINUTE_IN_SECONDS
			);
			return array(
				'added'         => 0,
				'skipped'       => 0,
				'type_conflict' => true,
				'cart_type'     => $cart['post_type'],
			);
		}

		$before  = $cart['post_ids'];
		$merged  = array_values( array_unique( array_merge( $before, $incoming ) ) );
		$added   = count( $merged ) - count( $before );
		$skipped = count( $incoming ) - $added;

		self::save( $user_id, $merged, $cart['post_type'] ? $cart['post_type'] : (string) $post_type );

		return array(
			'added'         => $added,
			'skipped'       => $skipped,
			'type_conflict' => false,
			'cart_type'     => '',
		);
	}

	/**
	 * Drop posts from the cart.
	 *
	 * @param int[] $post_ids Ids to remove.
	 */
	public static function remove( array $post_ids ) {
		$user_id = get_current_user_id();
		$cart    = self::get( $user_id );
		$drop    = array_map( 'intval', $post_ids );
		$kept    = array_values( array_diff( $cart['post_ids'], $drop ) );

		if ( empty( $kept ) ) {
			self::clear();
			return;
		}

		self::save( $user_id, $kept, $cart['post_type'] );
	}

	/**
	 * Empty the cart.
	 *
	 * @param int|null $user_id Defaults to the current user.
	 */
	public static function clear( $user_id = null ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( $user_id ) {
			delete_user_meta( $user_id, self::META_KEY );
		}
	}

	/**
	 * The Confirm (cart) screen URL.
	 *
	 * @param array $extra_query Extra query args (e.g. dest_lang).
	 * @return string
	 */
	public static function url( array $extra_query = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => Admin::PAGE_CONFIRM ), $extra_query ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * @param int    $user_id   User id.
	 * @param int[]  $post_ids  Post ids.
	 * @param string $post_type Post type.
	 */
	protected static function save( $user_id, array $post_ids, $post_type ) {
		if ( ! $user_id ) {
			return;
		}
		update_user_meta(
			$user_id,
			self::META_KEY,
			array(
				'post_ids'  => array_values( array_unique( array_map( 'intval', $post_ids ) ) ),
				'post_type' => (string) $post_type,
			)
		);
	}
}
