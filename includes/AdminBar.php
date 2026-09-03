<?php

namespace Perxel\AITranslate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Translate this page" admin-bar node, shown while editing a single post of a
 * translatable type. Stashes a one-post selection and lands on the Confirm
 * screen, same as the bulk action.
 */
class AdminBar {

	const ACTION = 'pxat_translate_single';

	public function register() {
		add_action( 'admin_bar_menu', array( $this, 'add_node' ), 100 );
		add_action( 'admin_action_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * @param \WP_Admin_Bar $wp_admin_bar The admin bar instance.
	 */
	public function add_node( $wp_admin_bar ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation params.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || ! isset( $_GET['post'] ) ) {
			return;
		}

		$post_id = absint( wp_unslash( $_GET['post'] ) );
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || ! in_array( $post->post_type, PostTypes::get_translatable_post_types(), true ) ) {
			return;
		}

		if ( count( Wpml::get_active_languages() ) < 2 ) {
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

	public function handle() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce checked below.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'perxel-ai-translate' ) );
		}

		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		check_admin_referer( self::ACTION . '_' . $post_id );

		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || ! in_array( $post->post_type, PostTypes::get_translatable_post_types(), true ) ) {
			wp_die( esc_html__( 'Invalid post, or its type cannot be translated.', 'perxel-ai-translate' ) );
		}

		$token = Selection::store( array( $post_id ), $post->post_type );
		wp_safe_redirect( Selection::confirm_url( $token ) );
		exit;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}
