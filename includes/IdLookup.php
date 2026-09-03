<?php

namespace Perxel\AITranslate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ID lookup tool: paste post/product IDs in one language, get their WPML
 * counterparts in another, same order. Pure read-only lookup - no run, no
 * writes - so it runs synchronously in a self-submitting GET form. Post type is
 * auto-detected per ID.
 */
class IdLookup {

	/**
	 * @return array View variables.
	 */
	public static function data() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation params, each sanitised on use.
		$languages = Wpml::get_active_languages();

		if ( count( $languages ) < 2 ) {
			return array(
				'error'     => __( 'WPML needs at least two active languages.', 'perxel-ai-translate' ),
				'languages' => $languages,
			);
		}

		$source_lang = Wpml::get_default_language();
		$other_langs = array_values( array_diff( array_keys( $languages ), array( $source_lang ) ) );

		$submitted = isset( $_GET['pxat_lookup'] );

		$dest_lang = $submitted && isset( $_GET['dest_lang'] ) ? sanitize_text_field( wp_unslash( $_GET['dest_lang'] ) ) : reset( $other_langs );
		if ( ! isset( $languages[ $dest_lang ] ) || $dest_lang === $source_lang ) {
			$dest_lang = reset( $other_langs );
		}

		$ids_raw     = $submitted && isset( $_GET['ids'] ) ? sanitize_textarea_field( wp_unslash( $_GET['ids'] ) ) : '';
		$input_count = 0;
		$output_ids  = array();

		if ( $submitted && '' !== trim( $ids_raw ) ) {
			foreach ( preg_split( '/[\s,]+/', trim( $ids_raw ), -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
				if ( ! ctype_digit( $token ) ) {
					continue;
				}
				++$input_count;

				$id   = (int) $token;
				$type = get_post_type( $id );
				if ( ! $type || ! Wpml::is_post_type_translated( $type ) ) {
					continue;
				}

				$dest_id = Wpml::get_object_id( $id, $type, $dest_lang, false );
				if ( $dest_id ) {
					$output_ids[] = $dest_id;
				}
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return array(
			'error'       => '',
			'languages'   => $languages,
			'source_lang' => $source_lang,
			'dest_lang'   => $dest_lang,
			'submitted'   => $submitted,
			'ids_raw'     => $ids_raw,
			'input_count' => $input_count,
			'output_ids'  => $output_ids,
			'output_text' => implode( ', ', $output_ids ),
		);
	}
}
