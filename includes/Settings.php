<?php

namespace Perxel\AITranslate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin settings storage. One option (PXAT_OPTION_KEY) holding an array.
 * Read through the typed accessors, never get_option() directly.
 *
 * The translation model is a setting, not code - `model_id` plus the pricing /
 * limits the "Test model" button fetched from OpenRouter for it.
 */
class Settings {

	public static function defaults() {
		return array(
			'api_key'          => '',
			'api_key_verified' => false,
			'prompt'           => '',
			'batched'          => false,
			'display_unit'     => 'tokens',
			'model_id'         => PXAT_DEFAULT_MODEL,
			'model_verified'   => false,
			'model_label'      => '',
			'model_input'      => 0.0,  // USD per 1M prompt tokens.
			'model_output'     => 0.0,  // USD per 1M completion tokens.
			'model_context'    => 0,    // Context length, tokens.
			'model_max_output' => 0,    // Max completion tokens, 0 = unknown.
		);
	}

	/**
	 * @return array Saved settings merged over defaults.
	 */
	public static function all() {
		$saved = get_option( PXAT_OPTION_KEY, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * @param string $key One of the defaults keys.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function api_key() {
		return (string) self::get( 'api_key' );
	}

	public static function has_api_key() {
		return '' !== trim( self::api_key() );
	}

	/**
	 * Whether runs send several posts per model request (faster for many short
	 * posts; one bad response affects a group). A global preference, snapshotted
	 * into each run.
	 *
	 * @return bool
	 */
	public static function batched() {
		return (bool) self::get( 'batched' );
	}

	/**
	 * The configured model, shaped like the old per-model array so call sites
	 * change little.
	 *
	 * @return array { id, label, input, output, context, max_output }
	 */
	public static function model() {
		$all = self::all();
		$id  = '' !== trim( (string) $all['model_id'] ) ? trim( (string) $all['model_id'] ) : PXAT_DEFAULT_MODEL;
		$max = (int) $all['model_max_output'];

		return array(
			'id'         => $id,
			'label'      => '' !== $all['model_label'] ? (string) $all['model_label'] : $id,
			'input'      => (float) $all['model_input'],
			'output'     => (float) $all['model_output'],
			'context'    => (int) $all['model_context'],
			'max_output' => $max > 0 ? $max : PXAT_DEFAULT_MAX_OUTPUT,
		);
	}

	public static function model_verified() {
		return (bool) self::get( 'model_verified' ) && '' !== trim( (string) self::get( 'model_label' ) );
	}

	/**
	 * @param array $values Partial or full settings.
	 */
	public static function update( array $values ) {
		update_option( PXAT_OPTION_KEY, wp_parse_args( $values, self::all() ) );
	}

	public static function reset() {
		update_option( PXAT_OPTION_KEY, self::defaults() );
	}

	/**
	 * Sanitise a raw settings-form submission into a storable array. The
	 * verified flags are trusted from hidden inputs the Test buttons set, and
	 * cleared here whenever the key or model id differs from what was verified.
	 *
	 * @param array $raw $_POST-shaped input (already unslashed).
	 * @return array
	 */
	public static function sanitize( array $raw ) {
		$current  = self::all();
		$model_id = isset( $raw['model_id'] ) ? trim( sanitize_text_field( $raw['model_id'] ) ) : '';

		// The Test buttons set these hidden inputs to 1 on success and the
		// Settings JS clears them the moment the key / model field is edited.
		$key_verified   = ! empty( $raw['api_key_verified'] );
		$model_verified = ! empty( $raw['model_verified'] ) && '' !== trim( (string) ( $raw['model_label'] ?? '' ) );

		return array(
			'api_key'          => isset( $raw['api_key'] ) ? sanitize_text_field( $raw['api_key'] ) : '',
			'api_key_verified' => $key_verified,
			'prompt'           => isset( $raw['prompt'] ) ? sanitize_textarea_field( $raw['prompt'] ) : '',
			'batched'          => ! empty( $raw['batched'] ),
			'display_unit'     => isset( $raw['display_unit'] ) && 'words' === $raw['display_unit'] ? 'words' : 'tokens',
			'model_id'         => '' !== $model_id ? $model_id : PXAT_DEFAULT_MODEL,
			'model_verified'   => $model_verified,
			'model_label'      => isset( $raw['model_label'] ) ? sanitize_text_field( $raw['model_label'] ) : '',
			'model_input'      => isset( $raw['model_input'] ) ? (float) $raw['model_input'] : (float) $current['model_input'],
			'model_output'     => isset( $raw['model_output'] ) ? (float) $raw['model_output'] : (float) $current['model_output'],
			'model_context'    => isset( $raw['model_context'] ) ? absint( $raw['model_context'] ) : (int) $current['model_context'],
			'model_max_output' => isset( $raw['model_max_output'] ) ? absint( $raw['model_max_output'] ) : (int) $current['model_max_output'],
		);
	}
}
