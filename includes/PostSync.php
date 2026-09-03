<?php

namespace Perxel\AITranslate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything that isn't translated by the LLM: finding/creating the destination
 * post, and the three granular "data type" sync functions (thumbnail, ACF,
 * taxonomy) that Translator composes per item based on which types were
 * selected. Each granular function returns {success, message}.
 */
class PostSync {

	/**
	 * Finds the destination post, or - only when $allow_create is true -
	 * creates it. Does NOT copy any data of its own.
	 *
	 * @param int    $source_post_id Source post ID.
	 * @param string $post_type      Post type.
	 * @param string $dest_lang      Destination language.
	 * @param string $source_lang    Source language.
	 * @param bool   $allow_create   Full mode may create; Custom mode never does.
	 * @return array{post_id:?int, error:?string}
	 */
	public static function get_or_create_dest_post( $source_post_id, $post_type, $dest_lang, $source_lang, $allow_create ) {
		$existing = Wpml::get_object_id( $source_post_id, $post_type, $dest_lang, false );

		// Hard safety net: WPML should never hand back the source post itself as
		// its own translation. If it does, refuse rather than overwrite the
		// source's own content.
		if ( $existing && (int) $existing === (int) $source_post_id ) {
			return array(
				'post_id' => null,
				'error'   => sprintf(
					/* translators: 1: post ID, 2: destination language code. */
					__( 'WPML returned a destination post (#%1$d, language %2$s) that is the same as the source post - skipped to avoid overwriting the original. Check the translation link (trid) for post #%1$d in WPML.', 'perxel-ai-translate' ),
					$source_post_id,
					$dest_lang
				),
			);
		}

		if ( $existing ) {
			// Detach WPML's "duplicate of" flag every time, same as clicking
			// WPML's own "Translate independently".
			delete_post_meta( $existing, '_icl_lang_duplicate_of' );
			return array(
				'post_id' => $existing,
				'error'   => null,
			);
		}

		if ( ! $allow_create ) {
			return array(
				'post_id' => null,
				'error'   => null,
			);
		}

		$source_post = get_post( $source_post_id );
		if ( ! $source_post ) {
			return array(
				'post_id' => null,
				'error'   => __( 'Source post not found.', 'perxel-ai-translate' ),
			);
		}

		$dest_post_id = wp_insert_post(
			array(
				'post_type'   => $post_type,
				'post_status' => 'draft',
				'post_author' => $source_post->post_author,
				'post_title'  => $source_post->post_title,
				'post_name'   => $source_post->post_name,
			),
			true
		);

		if ( is_wp_error( $dest_post_id ) ) {
			return array(
				'post_id' => null,
				'error'   => $dest_post_id->get_error_message(),
			);
		}

		$trid = Wpml::get_element_trid( $source_post_id, $post_type );
		Wpml::set_element_language_details( $dest_post_id, $post_type, $trid, $dest_lang, $source_lang );
		delete_post_meta( $dest_post_id, '_icl_lang_duplicate_of' );

		return array(
			'post_id' => $dest_post_id,
			'error'   => null,
		);
	}

	/**
	 * 'thumbnail' data type: copies the featured image from source to dest.
	 *
	 * @param int  $source_post_id Source post ID.
	 * @param int  $dest_post_id   Destination post ID.
	 * @param bool $strict         Whether a partial result fails the type.
	 * @return array{success:bool, message:?string}
	 */
	public static function sync_thumbnail( $source_post_id, $dest_post_id, $strict ) {
		$thumbnail_id = get_post_thumbnail_id( $source_post_id );

		if ( ! $thumbnail_id ) {
			return array(
				'success' => true,
				'message' => null,
			);
		}

		if ( set_post_thumbnail( $dest_post_id, $thumbnail_id ) ) {
			return array(
				'success' => true,
				'message' => null,
			);
		}

		return array(
			'success' => ! $strict,
			'message' => __( 'Could not set the featured image on the destination post.', 'perxel-ai-translate' ),
		);
	}

	/**
	 * 'acf' data type: copies every non-text ACF field from source to dest,
	 * blanking any translatable text nested inside a copied container.
	 *
	 * @param int  $source_post_id Source post ID.
	 * @param int  $dest_post_id   Destination post ID.
	 * @param bool $strict         Whether a partial result fails the type.
	 * @return array{success:bool, message:?string}
	 */
	public static function sync_acf( $source_post_id, $dest_post_id, $strict ) {
		$nested_translate_defs_by_top_key = array();
		foreach ( Fields::get_acf_field_defs( $source_post_id ) as $def ) {
			if ( 'acf_nested' === $def['source'] ) {
				$nested_translate_defs_by_top_key[ $def['top_key'] ][] = $def;
			}
		}

		$mismatches = array();

		foreach ( Fields::get_acf_copy_fields( $source_post_id ) as $field ) {
			$value = get_field( $field['field_key'], $source_post_id, false );

			if ( '' === $value || null === $value || false === $value || array() === $value ) {
				continue;
			}

			update_field( $field['field_key'], $value, $dest_post_id );

			$readback = get_field( $field['field_key'], $dest_post_id, false );
			if ( wp_json_encode( $readback ) !== wp_json_encode( $value ) ) {
				$mismatches[] = $field['name'];
			}

			foreach ( $nested_translate_defs_by_top_key[ $field['field_key'] ] ?? array() as $nested_def ) {
				Fields::set_value( $dest_post_id, $nested_def, '' );
			}
		}

		if ( ! $mismatches ) {
			return array(
				'success' => true,
				'message' => null,
			);
		}

		return array(
			'success' => ! $strict,
			/* translators: %s: comma-separated field names. */
			'message' => sprintf( __( 'ACF fields did not copy correctly: %s.', 'perxel-ai-translate' ), implode( ', ', $mismatches ) ),
		);
	}

	/**
	 * 'taxonomy' data type: remaps every taxonomy term on the source post to its
	 * destination-language counterpart via WPML.
	 *
	 * @param int    $source_post_id Source post ID.
	 * @param int    $dest_post_id   Destination post ID.
	 * @param string $post_type      Post type.
	 * @param string $dest_lang      Destination language.
	 * @param bool   $strict         Whether a partial mapping fails the type.
	 * @return array{success:bool, message:?string}
	 */
	public static function sync_taxonomies( $source_post_id, $dest_post_id, $post_type, $dest_lang, $strict ) {
		$taxonomies = get_object_taxonomies( $post_type, 'names' );
		$plan       = array();
		$missing    = array();

		foreach ( $taxonomies as $taxonomy ) {
			$term_ids = wp_get_object_terms(
				$source_post_id,
				$taxonomy,
				array(
					'fields'           => 'ids',
					'suppress_filters' => true,
				)
			);
			if ( is_wp_error( $term_ids ) || empty( $term_ids ) ) {
				continue;
			}

			$dest_term_ids = array();
			foreach ( $term_ids as $term_id ) {
				$dest_term_id = Wpml::get_object_id( $term_id, $taxonomy, $dest_lang, false );
				if ( $dest_term_id ) {
					$dest_term_ids[] = $dest_term_id;
				} else {
					$missing[] = sprintf( 'term %d ("%s")', $term_id, $taxonomy );
				}
			}

			if ( $dest_term_ids ) {
				$plan[ $taxonomy ] = $dest_term_ids;
			}
		}

		if ( $missing && $strict ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: 1: language code, 2: comma-separated term references. */
					__( 'No %1$s translation for: %2$s - no taxonomy was written.', 'perxel-ai-translate' ),
					$dest_lang,
					implode( ', ', $missing )
				),
			);
		}

		foreach ( $plan as $taxonomy => $dest_term_ids ) {
			wp_set_object_terms( $dest_post_id, $dest_term_ids, $taxonomy, false );
		}

		return array(
			'success' => true,
			'message' => $missing ? sprintf(
				/* translators: 1: language code, 2: comma-separated term references. */
				__( 'No %1$s translation for: %2$s.', 'perxel-ai-translate' ),
				$dest_lang,
				implode( ', ', $missing )
			) : null,
		);
	}
}
