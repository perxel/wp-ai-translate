<?php
/**
 * Translation ID lookup tool.
 *
 * @package Perxel_AI_Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Standalone tool: paste a list of post/product IDs in one language, get
 * back their WPML counterparts in another language, same order. Pure
 * read-only lookup via PXAT_WPML::get_object_id() — no batch, no jobs, no
 * writes, so it runs synchronously in a single self-submitting GET form.
 *
 * Post type is auto-detected per ID via get_post_type() rather than asked
 * for up front — IDs are unique across all post types in wp_posts, so this
 * is exact, not a guess, and it means a pasted list can mix post types
 * (products, pages, ...) and each ID still resolves against its own real
 * type instead of one type picked for the whole batch.
 */
class PXAT_ID_Lookup_Page {

	const PAGE_SLUG = 'pxat-id-lookup';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function register_menu() {
		add_submenu_page( null, sprintf( '%s - %s', PXAT_NAME, __( 'Translation ID lookup', 'perxel-ai-translate' ) ), __( 'Translation ID lookup', 'perxel-ai-translate' ), 'manage_options', self::PAGE_SLUG, array( __CLASS__, 'render_page' ) );
	}

	public static function enqueue_assets( $hook ) {
		unset( $hook );

		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen switch.
			return;
		}

		wp_enqueue_style( 'pxat-admin', PXAT_URL . '/assets/css/admin.css', array(), PXAT_VERSION );
		wp_enqueue_script( 'pxat-id-lookup', PXAT_URL . '/assets/js/id-lookup.js', array( 'wp-i18n' ), PXAT_VERSION, true );
		wp_set_script_translations( 'pxat-id-lookup', 'perxel-ai-translate', PXAT_DIR . '/languages' );
	}

	public static function render_page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation params, each sanitized on use.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$languages = PXAT_WPML::get_active_languages();
		if ( count( $languages ) < 2 ) {
			echo '<div class="wrap"><p>' . esc_html__( 'WPML needs at least two active languages.', 'perxel-ai-translate' ) . '</p></div>';
			return;
		}

		$source_lang = PXAT_WPML::get_default_language();
		$other_langs = array_diff( array_keys( $languages ), array( $source_lang ) );

		$submitted = isset( $_GET['pxat_lookup_submit'] );

		$dest_lang = $submitted && isset( $_GET['dest_lang'] ) ? sanitize_text_field( wp_unslash( $_GET['dest_lang'] ) ) : reset( $other_langs );
		if ( ! isset( $languages[ $dest_lang ] ) || $dest_lang === $source_lang ) {
			$dest_lang = reset( $other_langs );
		}

		$ids_raw     = $submitted && isset( $_GET['ids'] ) ? sanitize_textarea_field( wp_unslash( $_GET['ids'] ) ) : '';
		$input_count = 0;
		$output_ids  = array();

		if ( $submitted && '' !== trim( $ids_raw ) ) {
			$tokens = preg_split( '/[\s,]+/', trim( $ids_raw ), -1, PREG_SPLIT_NO_EMPTY );
			foreach ( $tokens as $token ) {
				if ( ! ctype_digit( $token ) ) {
					continue;
				}
				++$input_count;

				$id   = (int) $token;
				$type = get_post_type( $id );
				if ( ! $type || ! PXAT_WPML::is_post_type_translated( $type ) ) {
					continue; // post doesn't exist, or its type isn't WPML-translated.
				}

				$dest_id = PXAT_WPML::get_object_id( $id, $type, $dest_lang, false );
				if ( $dest_id ) {
					$output_ids[] = $dest_id;
				}
			}
		}

		$output_text = implode( ', ', $output_ids );

		include PXAT_DIR . '/views/id-lookup-page.php';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}
