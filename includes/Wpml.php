<?php

namespace Perxel\AITranslate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrappers around WPML's filter/action API. source_lang / dest_lang are
 * never hardcoded anywhere in this plugin, always resolved via these.
 */
class Wpml {

	public static function is_post_type_translated( $post_type ) {
		return (bool) apply_filters( 'wpml_is_translated_post_type', null, $post_type );
	}

	public static function get_active_languages() {
		$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		return is_array( $languages ) ? $languages : array();
	}

	public static function get_default_language() {
		return apply_filters( 'wpml_default_language', null );
	}

	public static function get_element_trid( $post_id, $post_type ) {
		return apply_filters( 'wpml_element_trid', null, $post_id, 'post_' . $post_type );
	}

	/**
	 * The WPML language code a post is actually tagged with right now -
	 * independent of whatever "source_lang" the UI has selected. Callers must
	 * verify a post's real language matches the chosen source_lang before
	 * treating it as a translation source.
	 *
	 * @return string|null Language code, or null if WPML has no record for this post.
	 */
	public static function get_post_language( $post_id ) {
		$details = apply_filters( 'wpml_post_language_details', null, $post_id );
		return is_array( $details ) && ! empty( $details['language_code'] ) ? $details['language_code'] : null;
	}

	/**
	 * @param int    $object_id    Post or term ID in the source language.
	 * @param string $type         Post type slug, or taxonomy slug (unprefixed, WPML's own convention).
	 * @param string $language_code Target language.
	 * @param bool   $return_original_if_missing Whether to fall back to the original.
	 * @return int|null
	 */
	public static function get_object_id( $object_id, $type, $language_code, $return_original_if_missing = false ) {
		$result = apply_filters( 'wpml_object_id', $object_id, $type, $return_original_if_missing, $language_code );
		return $result ? (int) $result : null;
	}

	public static function set_element_language_details( $dest_post_id, $post_type, $trid, $language_code, $source_language_code ) {
		do_action(
			'wpml_set_element_language_details',
			array(
				'element_id'           => $dest_post_id,
				'element_type'         => 'post_' . $post_type,
				'trid'                 => $trid,
				'language_code'        => $language_code,
				'source_language_code' => $source_language_code,
			)
		);
	}

	/**
	 * Best display name for a language code from get_active_languages()'s map.
	 *
	 * @param array  $languages get_active_languages() result.
	 * @param string $code      Language code.
	 * @return string
	 */
	public static function language_label( array $languages, $code ) {
		if ( isset( $languages[ $code ]['translated_name'] ) && '' !== $languages[ $code ]['translated_name'] ) {
			return $languages[ $code ]['translated_name'];
		}
		if ( isset( $languages[ $code ]['native_name'] ) && '' !== $languages[ $code ]['native_name'] ) {
			return $languages[ $code ]['native_name'];
		}
		return $code;
	}
}
