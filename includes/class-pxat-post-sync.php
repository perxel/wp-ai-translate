<?php
/**
 * Non-LLM destination-post sync (thumbnail, ACF, taxonomy).
 *
 * @package Perxel_AI_Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything that isn't translated by the LLM: finding/creating the
 * destination post, and the three granular "data type" sync functions
 * (thumbnail, ACF, taxonomy) that PXAT_Job_Processor::apply() composes per
 * job based on which types were selected on the confirm page. Each
 * granular function returns {success, message} — message is the failure
 * reason when success is false, or an optional non-blocking warning note
 * when success is true.
 */
class PXAT_Post_Sync {

	/**
	 * Finds the destination post, or — only when $allow_create is true —
	 * creates it. Does NOT copy any data of its own; that's entirely the
	 * job of sync_thumbnail()/sync_acf()/sync_taxonomies() and
	 * PXAT_Job_Processor's text-field apply step, called separately by the
	 * caller for whichever data types are actually selected.
	 *
	 * @param bool $allow_create Full mode may create a new destination post;
	 *                           Custom mode never does — a partial field set
	 *                           can't form a valid new post on its own (see
	 *                           PXAT_Confirm_Page's eligibility rules). Custom
	 *                           mode jobs are only ever created against posts
	 *                           already confirmed to have a destination, so
	 *                           this is a defensive-only guard for that case.
	 * @return array{post_id:?int, error:?string}
	 */
	public static function get_or_create_dest_post( $source_post_id, $post_type, $dest_lang, $source_lang, $allow_create ) {
		$existing = PXAT_WPML::get_object_id( $source_post_id, $post_type, $dest_lang, false );

		// Hard safety net: WPML's wpml_object_id filter is asked for the
		// $dest_lang translation of $source_post_id with
		// return_original_if_missing=false, so it should never hand back
		// $source_post_id itself. If it ever does (misconfigured trid, wrong
		// post_type, WPML quirk), treating that as a valid destination would
		// overwrite the source post's own content — refuse instead of risking
		// that.
		if ( $existing && (int) $existing === (int) $source_post_id ) {
			return array(
				'post_id' => null,
				'error'   => sprintf(
					/* translators: 1: post ID, 2: destination language code. */
					__( 'WPML returned a destination post (#%1$d, language %2$s) that is the same as the source post — skipped to avoid overwriting the original. Check the translation link (trid) for post #%1$d in WPML.', 'perxel-ai-translate' ),
					$source_post_id,
					$dest_lang
				),
			);
		}

		if ( $existing ) {
			// WPML flags a linked translation as a "duplicate" of the source
			// (postmeta _icl_lang_duplicate_of), which lets WPML auto-sync
			// (and override) it from the source — the opposite of what an
			// independent AI translation needs. Detach every time apply()
			// runs, same as clicking WPML's own "Translate independently".
			delete_post_meta( $existing, '_icl_lang_duplicate_of' );
			return array( 'post_id' => $existing, 'error' => null );
		}

		if ( ! $allow_create ) {
			return array( 'post_id' => null, 'error' => null ); // Custom mode: no destination to touch, nothing to do — not an error.
		}

		$source_post = get_post( $source_post_id );
		if ( ! $source_post ) {
			return array( 'post_id' => null, 'error' => __( 'Source post not found.', 'perxel-ai-translate' ) );
		}

		$dest_post_id = wp_insert_post(
			array(
				'post_type'   => $post_type,
				// Stays a draft until PXAT_Job_Processor::maybe_publish_dest_post()
				// flips it once every job targeting this post has finished cleanly.
				'post_status' => 'draft',
				'post_author' => $source_post->post_author,
				'post_title'  => $source_post->post_title, // placeholder until the title type (if selected) writes the real one.
				'post_name'   => $source_post->post_name,  // placeholder until the title type derives one from the translated title.
			),
			true
		);

		if ( is_wp_error( $dest_post_id ) ) {
			return array( 'post_id' => null, 'error' => $dest_post_id->get_error_message() );
		}

		$trid = PXAT_WPML::get_element_trid( $source_post_id, $post_type );
		PXAT_WPML::set_element_language_details( $dest_post_id, $post_type, $trid, $dest_lang, $source_lang );
		delete_post_meta( $dest_post_id, '_icl_lang_duplicate_of' );

		return array( 'post_id' => $dest_post_id, 'error' => null );
	}

	/**
	 * 'thumbnail' data type: copies the featured image from source to dest.
	 * Nothing on the source to copy is success (trivially done, not a
	 * failure) — only a genuine write failure (set_post_thumbnail()
	 * returning false when there WAS a thumbnail to set) counts against it.
	 *
	 * @return array{success:bool, message:?string}
	 */
	public static function sync_thumbnail( $source_post_id, $dest_post_id, $strict ) {
		$thumbnail_id = get_post_thumbnail_id( $source_post_id );

		if ( ! $thumbnail_id ) {
			return array( 'success' => true, 'message' => null );
		}

		if ( set_post_thumbnail( $dest_post_id, $thumbnail_id ) ) {
			return array( 'success' => true, 'message' => null );
		}

		$message = __( 'Could not set the featured image on the destination post.', 'perxel-ai-translate' );

		return array( 'success' => ! $strict, 'message' => $message );
	}

	/**
	 * 'acf' data type: copies every non-text ACF field (images, repeaters,
	 * groups, files, ...) from source to dest, blanking any translatable
	 * text nested inside a copied container back to '' so the separate
	 * text-field apply step (see PXAT_Job_Processor) writes the translated
	 * value into it instead of leaving the verbatim-copied source text.
	 * Covers all of ACF as one unit — translatable and non-translatable
	 * sub-fields together — matching how "ACF" is offered as a single
	 * checkbox on the confirm page, not split into two concerns.
	 *
	 * Can't pre-validate before writing the way sync_taxonomies() does (a
	 * write failure here is only detectable via readback after the write
	 * already happened), so under $strict this still writes everything —
	 * it just reports the type as failed if any field didn't verify,
	 * instead of silently treating a partial copy as success.
	 *
	 * @return array{success:bool, message:?string}
	 */
	public static function sync_acf( $source_post_id, $dest_post_id, $strict ) {
		// Group/Repeater/Flexible Content fields are copied whole below
		// (needed for their non-text sub-fields), which also copies any
		// translatable text nested inside them verbatim. Group those nested
		// defs by their top-level field key so that text can be blanked back
		// out right after its container is copied — otherwise the later
		// text-field apply step would see a non-empty destination value.
		$nested_translate_defs_by_top_key = array();
		foreach ( PXAT_Fields::get_acf_field_defs( $source_post_id ) as $def ) {
			if ( 'acf_nested' === $def['source'] ) {
				$nested_translate_defs_by_top_key[ $def['top_key'] ][] = $def;
			}
		}

		$mismatches = array();

		foreach ( PXAT_Fields::get_acf_copy_fields( $source_post_id ) as $field ) {
			// Field key, not name: update_field() by name has to re-resolve which
			// field group applies to $dest_post_id (location rules: status, template,
			// ...), which can silently no-op on a freshly-created draft even though
			// the same field reads fine by name on the published source post.
			$value = get_field( $field['field_key'], $source_post_id, false );

			if ( '' === $value || null === $value || false === $value || array() === $value ) {
				continue; // nothing on the source to copy.
			}

			update_field( $field['field_key'], $value, $dest_post_id );

			// Read straight back rather than trusting update_field()'s return value:
			// verifies the write actually stuck instead of assuming it did.
			$readback = get_field( $field['field_key'], $dest_post_id, false );
			if ( wp_json_encode( $readback ) !== wp_json_encode( $value ) ) {
				$mismatches[] = $field['name'];
			}

			foreach ( $nested_translate_defs_by_top_key[ $field['field_key'] ] ?? array() as $nested_def ) {
				PXAT_Fields::set_value( $dest_post_id, $nested_def, '' );
			}
		}

		if ( ! $mismatches ) {
			return array( 'success' => true, 'message' => null );
		}

		/* translators: %s: comma-separated field names. */
		$message = sprintf( __( 'ACF fields did not copy correctly: %s.', 'perxel-ai-translate' ), implode( ', ', $mismatches ) );

		return array( 'success' => ! $strict, 'message' => $message );
	}

	/**
	 * 'taxonomy' data type: remaps every taxonomy term on the source post to
	 * its destination-language counterpart via WPML, and assigns those to
	 * the destination post. The mapping for every source term across every
	 * taxonomy is resolved first, before anything is written. $strict
	 * decides what happens when some of them don't resolve: Custom mode
	 * writes nothing at all (the type fails outright — taxonomy was
	 * specifically targeted, so an incomplete result isn't good enough);
	 * Full mode writes whatever DID resolve and reports the rest as a
	 * warning, since taxonomy is only one of several data types there and
	 * a gap in it shouldn't block the others.
	 *
	 * @return array{success:bool, message:?string}
	 */
	public static function sync_taxonomies( $source_post_id, $dest_post_id, $post_type, $dest_lang, $strict ) {
		$taxonomies = get_object_taxonomies( $post_type, 'names' );
		$plan       = array(); // taxonomy => dest term ids that DID resolve.
		$missing    = array();

		foreach ( $taxonomies as $taxonomy ) {
			// suppress_filters: wp_get_object_terms() runs through WP_Term_Query
			// same as get_terms(), so without this WPML's language filter scopes
			// it to whatever language context is currently active (e.g. the
			// admin's language selector) instead of the actual terms assigned to
			// $source_post_id — silently returning empty and skipping the
			// taxonomy for posts that do have terms assigned.
			$term_ids = wp_get_object_terms( $source_post_id, $taxonomy, array(
				'fields'           => 'ids',
				'suppress_filters' => true,
			) );
			if ( is_wp_error( $term_ids ) || empty( $term_ids ) ) {
				continue;
			}

			$dest_term_ids = array();
			foreach ( $term_ids as $term_id ) {
				$dest_term_id = PXAT_WPML::get_object_id( $term_id, $taxonomy, $dest_lang, false );
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
			// Custom mode: taxonomy was specifically targeted — an
			// incomplete mapping means nothing gets written at all, rather
			// than leaving the post with some taxonomies synced and others
			// silently still wrong.
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: 1: language code, 2: comma-separated term references. */
					__( 'No %1$s translation for: %2$s — no taxonomy was written.', 'perxel-ai-translate' ),
					$dest_lang,
					implode( ', ', $missing )
				),
			);
		}

		// Full mode (or nothing missing at all): write whatever DID resolve,
		// note the rest as a warning — taxonomy is one of several data types
		// in Full mode, so a gap here shouldn't block the others.
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
