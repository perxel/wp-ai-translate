<?php
/**
 * Per-field translate/copy decisions.
 *
 * @package Perxel_AI_Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-field translate/copy decision, made by ACF field type, not by
 * hand-listing every field across the ~10 field groups on this site.
 */
class PXAT_Fields {

	const ACF_TEXT_TYPES      = array( 'text', 'textarea', 'wysiwyg' );
	const ACF_CONTAINER_TYPES = array( 'group', 'repeater', 'flexible_content' );

	// Safety net against runaway recursion (e.g. a misconfigured ACF clone
	// field), not a limit we expect real field groups to ever approach.
	const MAX_ACF_DEPTH = 10;

	const RANKMATH_KEYS = array(
		'rank_math_title',
		'rank_math_description',
		'rank_math_focus_keyword',
		'rank_math_facebook_title',
		'rank_math_facebook_description',
		'rank_math_twitter_title',
		'rank_math_twitter_description',
	);

	// The "Data to process" checklist on the confirm page, and the granular
	// per-type apply functions in PXAT_Job_Processor/PXAT_Post_Sync — every
	// other piece of the data-selection model (job['custom_types'], the
	// per-type results map, PXAT_Confirm_Page's eligibility/preview logic)
	// is keyed off these same six slugs. 'taxonomy' and 'thumbnail'
	// contribute no LLM field defs at all (see get_field_defs_for_type()) —
	// both are structural only, handled by PXAT_Post_Sync::sync_taxonomies()
	// / sync_thumbnail().
	const DATA_TYPES = array( 'title', 'content', 'acf', 'rankmath', 'taxonomy', 'thumbnail' );

	public static function get_title_field_defs() {
		return array( array( 'key' => 'post_title', 'source' => 'core' ) );
	}

	public static function get_content_field_defs() {
		return array(
			array( 'key' => 'post_excerpt', 'source' => 'core' ),
			array( 'key' => 'post_content', 'source' => 'core' ),
		);
	}

	/**
	 * Translatable ACF fields (text/textarea/wysiwyg), found by recursing
	 * into Group, Repeater, and Flexible Content fields at any depth. A
	 * field nested inside one of those containers is addressed by a path
	 * (sub-field key, or row index for repeater/flexible rows) relative to
	 * its top-level container, since that's the only way ACF lets you read
	 * or write a value nested inside a Repeater/Flexible Content row.
	 */
	public static function get_acf_field_defs( $post_id ) {
		$defs = array();

		if ( ! function_exists( 'get_field_objects' ) ) {
			return $defs;
		}

		// Modern ACF (5.x/6.x/Pro) takes positional args here: $format_value,
		// $load_value, not an options array (that was pre-ACF5 API). We only
		// need field name/type/key, not values, so both are false.
		$fields = get_field_objects( $post_id, false, false );
		if ( ! $fields ) {
			return $defs;
		}

		foreach ( $fields as $field ) {
			$defs = array_merge(
				$defs,
				self::collect_translate_defs( $field, $post_id, $field['key'], array(), 0 )
			);
		}

		return $defs;
	}

	/**
	 * @param array  $field   ACF field-object (schema) node.
	 * @param int    $post_id Source post, used to read repeater/flexible row counts.
	 * @param string $top_key Field key of the nearest top-level ACF field ancestor.
	 * @param array  $path    Path of $field's value within the top-level container's
	 *                        raw value (empty when $field is itself the top-level field).
	 * @param int    $depth   Recursion guard, see MAX_ACF_DEPTH.
	 */
	private static function collect_translate_defs( array $field, $post_id, $top_key, array $path, $depth ) {
		if ( in_array( $field['type'], self::ACF_TEXT_TYPES, true ) ) {
			return array(
				array(
					'source' => empty( $path ) ? 'acf' : 'acf_nested',
					// LLM payload/response key. Top-level: the field name, same as
					// before. Nested: prefixed with the container's field key, since
					// two different containers can have same-named sub-fields (or
					// same row index) and $path alone wouldn't be unique.
					'key'       => empty( $path ) ? $field['name'] : $top_key . ':' . implode( '.', $path ),
					'field_key' => $field['key'],
					'top_key'   => $top_key,
					'path'      => $path,
				),
			);
		}

		if ( $depth >= self::MAX_ACF_DEPTH || ! in_array( $field['type'], self::ACF_CONTAINER_TYPES, true ) ) {
			return array();
		}

		$defs = array();

		// Path segments below are each sub-field's own field KEY (e.g.
		// "field_5f2a..."), not its name: ACF's internal (unformatted) value
		// for Group/Repeater/Flexible Content is keyed by field key — only
		// formatted values (format_value=true, which we deliberately don't
		// use) get remapped to sub-field name.

		if ( 'group' === $field['type'] && ! empty( $field['sub_fields'] ) ) {
			foreach ( $field['sub_fields'] as $sub_field ) {
				$defs = array_merge(
					$defs,
					self::collect_translate_defs( $sub_field, $post_id, $top_key, array_merge( $path, array( $sub_field['key'] ) ), $depth + 1 )
				);
			}
			return $defs;
		}

		if ( 'repeater' === $field['type'] && ! empty( $field['sub_fields'] ) ) {
			$rows = self::read_path( $top_key, $path, $post_id );
			if ( ! is_array( $rows ) ) {
				return $defs;
			}
			foreach ( array_keys( $rows ) as $row_index ) {
				foreach ( $field['sub_fields'] as $sub_field ) {
					$defs = array_merge(
						$defs,
						self::collect_translate_defs( $sub_field, $post_id, $top_key, array_merge( $path, array( $row_index, $sub_field['key'] ) ), $depth + 1 )
					);
				}
			}
			return $defs;
		}

		if ( 'flexible_content' === $field['type'] && ! empty( $field['layouts'] ) ) {
			$rows = self::read_path( $top_key, $path, $post_id );
			if ( ! is_array( $rows ) ) {
				return $defs;
			}
			foreach ( $rows as $row_index => $row ) {
				// The layout row's own type tag, unlike its sub-fields, is
				// always stored under this literal string key, regardless of
				// format_value.
				$layout_name = is_array( $row ) && isset( $row['acf_fc_layout'] ) ? $row['acf_fc_layout'] : null;
				foreach ( $field['layouts'] as $layout ) {
					if ( empty( $layout['sub_fields'] ) || $layout['name'] !== $layout_name ) {
						continue;
					}
					foreach ( $layout['sub_fields'] as $sub_field ) {
						$defs = array_merge(
							$defs,
							self::collect_translate_defs( $sub_field, $post_id, $top_key, array_merge( $path, array( $row_index, $sub_field['key'] ) ), $depth + 1 )
						);
					}
					break;
				}
			}
			return $defs;
		}

		return $defs;
	}

	/**
	 * Reads the raw value of the top-level ACF field $top_key, then walks
	 * down $path (sub-field keys / repeater row indexes) to the value that
	 * lives there. Returns null if any segment along the way is missing.
	 */
	private static function read_path( $top_key, array $path, $post_id ) {
		$value = get_field( $top_key, $post_id, false );

		foreach ( $path as $segment ) {
			if ( is_array( $value ) && isset( $value[ $segment ] ) ) {
				$value = $value[ $segment ];
			} else {
				return null;
			}
		}

		return $value;
	}

	/**
	 * Sets $value at $path inside $container (by reference), creating
	 * intermediate arrays as needed. Every other key in $container is left
	 * untouched.
	 */
	private static function write_path( &$container, array $path, $value ) {
		$ref = &$container;
		$last = count( $path ) - 1;

		foreach ( $path as $i => $segment ) {
			if ( $i === $last ) {
				$ref[ $segment ] = $value;
			} else {
				if ( ! isset( $ref[ $segment ] ) || ! is_array( $ref[ $segment ] ) ) {
					$ref[ $segment ] = array();
				}
				$ref = &$ref[ $segment ];
			}
		}
	}

	/**
	 * Non text/textarea/wysiwyg ACF fields on the source post, copied as-is
	 * to the destination post, never sent to the translator. For a Group,
	 * Repeater, or Flexible Content field this copies the whole structure
	 * (needed for its non-text sub-fields, e.g. images); any translatable
	 * text nested inside is copied along with it and must be blanked
	 * afterward, see PXAT_Post_Sync::sync_acf().
	 *
	 * @return array[] List of ['name' => field name, 'field_key' => ACF field key].
	 */
	public static function get_acf_copy_fields( $post_id ) {
		$out = array();

		if ( ! function_exists( 'get_field_objects' ) ) {
			return $out;
		}

		// Modern ACF (5.x/6.x/Pro) takes positional args here: $format_value,
		// $load_value, not an options array (that was pre-ACF5 API). We only
		// need field name/type/key, not values, so both are false.
		$fields = get_field_objects( $post_id, false, false );
		if ( ! $fields ) {
			return $out;
		}

		foreach ( $fields as $field_name => $field ) {
			if ( ! in_array( $field['type'], self::ACF_TEXT_TYPES, true ) ) {
				$out[] = array(
					'name'      => $field_name,
					'field_key' => $field['key'],
				);
			}
		}

		return $out;
	}

	public static function get_rankmath_field_defs( $post_id ) {
		$defs = array();

		if ( ! defined( 'RANK_MATH_VERSION' ) ) {
			return $defs;
		}

		foreach ( self::RANKMATH_KEYS as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( '' === $value || false === $value ) {
				continue;
			}
			$defs[] = array( 'key' => $key, 'source' => 'rankmath' );
		}

		return $defs;
	}

	public static function get_translatable_field_defs( $source_post_id ) {
		return array_merge(
			self::get_title_field_defs(),
			self::get_content_field_defs(),
			self::get_acf_field_defs( $source_post_id ),
			self::get_rankmath_field_defs( $source_post_id )
		);
	}

	/**
	 * LLM field defs for one entry of DATA_TYPES — the per-type building
	 * block compute_field_plan() unions across whichever types are selected.
	 * 'taxonomy' and 'thumbnail' always return empty: both are structural
	 * (WPML term mapping / a featured-image copy), never sent to the LLM —
	 * see PXAT_Post_Sync::sync_taxonomies() / sync_thumbnail().
	 */
	public static function get_field_defs_for_type( $source_post_id, $type ) {
		switch ( $type ) {
			case 'title':
				return self::get_title_field_defs();
			case 'content':
				return self::get_content_field_defs();
			case 'acf':
				return self::get_acf_field_defs( $source_post_id );
			case 'rankmath':
				return self::get_rankmath_field_defs( $source_post_id );
			case 'taxonomy':
			case 'thumbnail':
				return array();
		}
		return array();
	}

	public static function get_value( $post_id, array $field ) {
		switch ( $field['source'] ) {
			case 'core':
				$post = get_post( $post_id );
				return $post ? (string) $post->{$field['key']} : '';

			case 'acf':
				if ( ! function_exists( 'get_field' ) ) {
					return '';
				}
				$selector = ! empty( $field['field_key'] ) ? $field['field_key'] : $field['key'];
				$value    = get_field( $selector, $post_id );
				return is_string( $value ) ? $value : ( $value ? (string) $value : '' );

			case 'acf_nested':
				if ( ! function_exists( 'get_field' ) ) {
					return '';
				}
				$value = self::read_path( $field['top_key'], $field['path'], $post_id );
				return is_string( $value ) ? $value : ( $value ? (string) $value : '' );

			case 'rankmath':
				return (string) get_post_meta( $post_id, $field['key'], true );
		}

		return '';
	}

	public static function set_value( $post_id, array $field, $value ) {
		switch ( $field['source'] ) {
			case 'core':
				wp_update_post(
					array(
						'ID'          => $post_id,
						$field['key'] => $value,
					)
				);
				break;

			case 'acf':
				if ( ! function_exists( 'update_field' ) ) {
					break;
				}
				$selector = ! empty( $field['field_key'] ) ? $field['field_key'] : $field['key'];
				update_field( $selector, $value, $post_id );
				break;

			case 'acf_nested':
				if ( ! function_exists( 'get_field' ) || ! function_exists( 'update_field' ) ) {
					break;
				}
				$container = get_field( $field['top_key'], $post_id, false );
				if ( ! is_array( $container ) ) {
					$container = array();
				}
				self::write_path( $container, $field['path'], $value );
				update_field( $field['top_key'], $container, $post_id );
				break;

			case 'rankmath':
				update_post_meta( $post_id, $field['key'], $value );
				break;
		}
	}

	/**
	 * LLM field defs with non-empty source content, unioned across whichever
	 * of DATA_TYPES are selected — the full set sent to the LLM whenever a
	 * post is (re)translated. Always overwrites whatever's selected; doesn't
	 * look at the destination post at all (no "only fill in empty fields"
	 * skip logic — see PXAT_Confirm_Page's data-axis redesign).
	 *
	 * @param array $types Subset of DATA_TYPES to include ('taxonomy' contributes nothing here — it's structural, not LLM).
	 */
	public static function compute_field_plan( $source_post_id, array $types ) {
		$defs = array();
		foreach ( $types as $type ) {
			$defs = array_merge( $defs, self::get_field_defs_for_type( $source_post_id, $type ) );
		}

		$to_translate = array();

		foreach ( $defs as $field ) {
			if ( '' !== self::get_value( $source_post_id, $field ) ) {
				$to_translate[] = $field;
			}
		}

		return $to_translate;
	}
}
