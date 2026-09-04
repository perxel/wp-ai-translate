<?php

namespace Perxel\AITranslate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Translate this page" admin-bar node, shown while editing a single post of a
 * translatable type. It links to the Confirm screen for that one post - exactly
 * where the list-table bulk action lands - so every route into a run goes
 * through the same screen and the same "Translate and apply" button.
 */
class AdminBar {

	public function register() {
		add_action( 'admin_bar_menu', array( $this, 'add_node' ), 100 );
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

		$wp_admin_bar->add_node(
			array(
				'id'    => 'pxat-translate',
				'title' => '<span class="ab-icon dashicons dashicons-translation" aria-hidden="true" style="font-family:dashicons;top:2px;"></span><span class="ab-label">' . esc_html__( 'Translate this page', 'perxel-ai-translate' ) . '</span>',
				'href'  => add_query_arg(
					array(
						'page'      => Admin::PAGE_CONFIRM,
						'ids'       => $post_id,
						'post_type' => $post->post_type,
					),
					admin_url( 'admin.php' )
				),
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}
