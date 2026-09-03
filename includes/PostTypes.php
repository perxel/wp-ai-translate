<?php

namespace Perxel\AITranslate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which post types get the bulk action, detected dynamically via WPML's own
 * setting, never hardcoded. Makes the plugin portable.
 */
class PostTypes {

	public static function get_translatable_post_types() {
		$post_types = get_post_types(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'names'
		);
		$eligible   = array();

		foreach ( $post_types as $post_type ) {
			if ( 'attachment' === $post_type ) {
				continue;
			}
			if ( Wpml::is_post_type_translated( $post_type ) ) {
				$eligible[] = $post_type;
			}
		}

		return $eligible;
	}

	/**
	 * [ post_type slug => plural label ] for every translatable post type - the
	 * Dashboard's "open a list" links.
	 *
	 * @return array<string,string>
	 */
	public static function labelled() {
		$out = array();
		foreach ( self::get_translatable_post_types() as $post_type ) {
			$object = get_post_type_object( $post_type );
			if ( $object ) {
				$out[ $post_type ] = $object->labels->name;
			}
		}
		return $out;
	}
}
