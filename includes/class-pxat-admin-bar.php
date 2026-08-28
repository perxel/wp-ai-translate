<?php
/**
 * Admin-bar "Translate this page" entry point.
 *
 * @package Perxel_AI_Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a "Translate this page" node to the admin bar while editing a single
 * post of a translatable post type. Clicking it creates the same kind of
 * selection transient PXAT_Bulk_Action uses (scoped to just this one post)
 * and lands on the same confirm page.
 */
class PXAT_Admin_Bar {

	const ACTION = 'pxat_translate_single';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_bar_menu', array( __CLASS__, 'add_node' ), 100 );
		add_action( 'admin_action_' . self::ACTION, array( __CLASS__, 'handle_translate_single' ) );
	}

	/**
	 * @param WP_Admin_Bar $wp_admin_bar The admin bar instance.
	 */
	public static function add_node( $wp_admin_bar ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation params, each sanitized on use.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || ! isset( $_GET['post'] ) ) {
			return;
		}

		$post_id = absint( wp_unslash( $_GET['post'] ) );
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return;
		}

		if ( ! in_array( $post->post_type, PXAT_Post_Types::get_translatable_post_types(), true ) ) {
			return;
		}

		if ( count( PXAT_WPML::get_active_languages() ) < 2 ) {
			return;
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'post'   => $post_id,
				),
				admin_url( 'admin.php' )
			),
			self::ACTION . '_' . $post_id
		);

		$wp_admin_bar->add_node(
			array(
				'id'    => 'pxat-translate',
				'title' => __( 'Translate this page', 'perxel-ai-translate' ),
				'href'  => $url,
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Handle the admin-bar click: stash the selection and redirect to Confirm.
	 */
	public static function handle_translate_single() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation params, each sanitized on use.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'perxel-ai-translate' ) );
		}

		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		check_admin_referer( self::ACTION . '_' . $post_id );

		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || ! in_array( $post->post_type, PXAT_Post_Types::get_translatable_post_types(), true ) ) {
			wp_die( esc_html__( 'Invalid post, or its type cannot be translated.', 'perxel-ai-translate' ) );
		}

		$token = wp_generate_password( 12, false );

		set_transient(
			'pxat_sel_' . $token,
			array(
				'post_ids'  => array( $post_id ),
				'post_type' => $post->post_type,
			),
			HOUR_IN_SECONDS
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => PXAT_Confirm_Page::PAGE_SLUG,
					'sel'  => $token,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}
