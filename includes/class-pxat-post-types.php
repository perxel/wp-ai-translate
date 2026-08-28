<?php
/**
 * Translatable post-type detection.
 *
 * @package Perxel_AI_Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which post types get the bulk action, detected dynamically via WPML's own
 * setting, never hardcoded to product/project. Makes the plugin portable.
 */
class PXAT_Post_Types {

	public static function get_translatable_post_types() {
		$post_types = get_post_types( array( 'public' => true, 'show_ui' => true ), 'names' );
		$eligible   = array();

		foreach ( $post_types as $post_type ) {
			if ( 'attachment' === $post_type ) {
				continue;
			}
			if ( PXAT_WPML::is_post_type_translated( $post_type ) ) {
				$eligible[] = $post_type;
			}
		}

		return $eligible;
	}
}
