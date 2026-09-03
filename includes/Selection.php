<?php

namespace Perxel\AITranslate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A pending post selection, carried across the redirect from an entry point
 * (bulk action, admin-bar item, Dashboard picker, "re-translate") to the
 * Confirm screen. Stored as a short-lived transient keyed by a random token so
 * a long id list never rides in the URL.
 */
class Selection {

	const PREFIX = 'pxat_sel_';
	const TTL    = HOUR_IN_SECONDS;

	/**
	 * @param int[]  $post_ids  Selected post ids.
	 * @param string $post_type Their (single) post type.
	 * @return string Token.
	 */
	public static function store( array $post_ids, $post_type ) {
		$token = wp_generate_password( 16, false );

		set_transient(
			self::PREFIX . $token,
			array(
				'post_ids'  => array_values( array_unique( array_map( 'intval', $post_ids ) ) ),
				'post_type' => (string) $post_type,
			),
			self::TTL
		);

		return $token;
	}

	/**
	 * @param string $token Token.
	 * @return array|null { post_ids, post_type }
	 */
	public static function get( $token ) {
		if ( ! $token ) {
			return null;
		}
		$data = get_transient( self::PREFIX . sanitize_text_field( $token ) );
		return ( is_array( $data ) && ! empty( $data['post_ids'] ) ) ? $data : null;
	}

	public static function forget( $token ) {
		if ( $token ) {
			delete_transient( self::PREFIX . sanitize_text_field( $token ) );
		}
	}

	/**
	 * The Confirm screen URL for a freshly stored selection.
	 *
	 * @param string $token       Token.
	 * @param array  $extra_query Extra query args (e.g. dest_lang).
	 * @return string
	 */
	public static function confirm_url( $token, array $extra_query = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => Admin::PAGE_CONFIRM,
					'sel'  => $token,
				),
				$extra_query
			),
			admin_url( 'admin.php' )
		);
	}
}
