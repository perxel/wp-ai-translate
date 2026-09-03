<?php

namespace Perxel\AITranslate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard: the landing screen. Shows setup state, how to start a translation,
 * a scoped post picker, any unfinished run, and all-time totals.
 */
class Dashboard {

	/**
	 * @return array View variables.
	 */
	public static function data() {
		$languages    = Wpml::get_active_languages();
		$has_api_key  = Settings::has_api_key();
		$enough_langs = count( $languages ) >= 2;

		return array(
			'state'         => ( $has_api_key && $enough_langs ) ? 'ready' : 'needs_setup',
			'has_api_key'   => $has_api_key,
			'enough_langs'  => $enough_langs,
			'settings_url'  => admin_url( 'admin.php?page=' . Admin::PAGE_SETTINGS ),
			'post_types'    => PostTypes::labelled(),
			'active_run_id' => Runs::active_run_id(),
			'totals'        => Runs::totals(),
			'recent'        => Runs::list_runs( 5 ),
			'languages'     => $languages,
			'default_lang'  => Wpml::get_default_language(),
			'nonce'         => wp_create_nonce( Admin::NONCE ),
		);
	}

	/**
	 * Turn the picker / paste-IDs form into a Selection and go to Confirm.
	 */
	public static function handle_select() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'perxel-ai-translate' ) );
		}
		check_admin_referer( 'pxat_dashboard_select' );

		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		if ( ! in_array( $post_type, PostTypes::get_translatable_post_types(), true ) ) {
			wp_die( esc_html__( 'Pick a post type first.', 'perxel-ai-translate' ) );
		}

		$ids = array();

		if ( isset( $_POST['post_ids'] ) ) {
			$ids = array_map( 'absint', (array) wp_unslash( $_POST['post_ids'] ) );
		}

		if ( isset( $_POST['paste_ids'] ) ) {
			$raw = sanitize_textarea_field( wp_unslash( $_POST['paste_ids'] ) );
			foreach ( preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
				if ( ctype_digit( $token ) ) {
					$ids[] = (int) $token;
				}
			}
		}

		$ids = array_values(
			array_unique(
				array_filter(
					$ids,
					static function ( $id ) use ( $post_type ) {
						return $id && get_post_type( $id ) === $post_type;
					}
				)
			)
		);

		if ( empty( $ids ) ) {
			wp_die( esc_html__( 'No matching posts of that type were found in the selection.', 'perxel-ai-translate' ) );
		}

		$token = Selection::store( $ids, $post_type );
		wp_safe_redirect( Selection::confirm_url( $token ) );
		exit;
	}

	/**
	 * AJAX: search posts of one translatable type for the picker.
	 */
	public static function ajax_post_search() {
		check_ajax_referer( Admin::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		$search    = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

		if ( ! in_array( $post_type, PostTypes::get_translatable_post_types(), true ) ) {
			wp_send_json_error( array( 'message' => 'Unknown post type' ) );
		}

		$posts = get_posts(
			array(
				'post_type'        => $post_type,
				's'                => $search,
				'posts_per_page'   => 20,
				'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'orderby'          => 'relevance',
				'suppress_filters' => false,
			)
		);

		$results = array();
		foreach ( $posts as $post ) {
			$results[] = array(
				'id'     => $post->ID,
				'title'  => '' !== $post->post_title ? $post->post_title : sprintf( '(#%d, no title)', $post->ID ),
				'status' => $post->post_status,
			);
		}

		wp_send_json_success( array( 'results' => $results ) );
	}
}
