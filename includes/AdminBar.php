<?php

namespace Perxel\AITranslate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Translate this page" admin-bar node, shown while editing a single post of a
 * translatable type. On a two-language site it creates the run and jumps
 * straight to Progress (everything, into the one other language, one request per
 * post); with three or more languages the target is ambiguous, so it lands on
 * the Confirm screen instead - same as the bulk action. While a run that
 * contains this post is still going, the node links back to that run.
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

		$icon = '<span class="ab-icon dashicons dashicons-translation" aria-hidden="true" style="font-family:dashicons;top:2px;"></span>';

		$active_run = Runs::active_run_id_for_source( $post_id );
		if ( $active_run ) {
			$wp_admin_bar->add_node(
				array(
					'id'    => 'pxat-translate',
					'title' => $icon . '<span class="ab-label">' . esc_html__( 'Translation running - view', 'perxel-ai-translate' ) . '</span>',
					'href'  => admin_url( 'admin.php?page=' . Admin::PAGE_PROGRESS . '&run_id=' . $active_run ),
				)
			);
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
				'title' => $icon . '<span class="ab-label">' . esc_html__( 'Translate this page', 'perxel-ai-translate' ) . '</span>',
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

		// One click: on a two-language site, skip Confirm and start the run now.
		$config = Confirm::default_config( Wpml::get_active_languages(), false );
		if ( $config ) {
			$run = Confirm::create_run( array( $post_id ), $post->post_type, $config );
			if ( ! is_wp_error( $run ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'   => Admin::PAGE_PROGRESS,
							'run_id' => $run,
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}
		}

		// Ambiguous target, or nothing to translate - let the Confirm screen
		// spell out the choice / the reason.
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => Admin::PAGE_CONFIRM,
					'ids'       => $post_id,
					'post_type' => $post->post_type,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}
