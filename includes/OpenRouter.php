<?php

namespace Perxel\AITranslate;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenRouter chat-completions client. Every call is wrapped uniformly: non-200
 * and unparseable responses both come back as a WP_Error with the raw text,
 * never a thrown exception; the caller logs it and moves on.
 */
class OpenRouter {

	const API_URL     = 'https://openrouter.ai/api/v1/chat/completions';
	const MAX_RETRIES = 2;

	// Rough rule of thumb (fine for Latin-script text); real usage always comes
	// back from the API's own `usage` field once a job runs.
	const CHARS_PER_TOKEN = 4;

	// A batched request carries several posts' worth of content, so it gets
	// more time than a single-job request's 90s.
	const BATCH_TIMEOUT = 180;

	// Hard ceiling on how many posts get grouped into one batch request.
	const MAX_BATCH_JOBS = 20;

	// A batch is filled against this fraction of the model's max_output_tokens.
	const BATCH_OUTPUT_SAFETY_FACTOR = 0.5;

	/**
	 * @param array    $payload     field_key => source text.
	 * @param string   $source_lang WPML language code.
	 * @param string   $dest_lang   WPML language code.
	 * @param string   $model_id    OpenRouter model id. Falls back to the first configured model.
	 * @param callable $log         Optional status callback.
	 * @return array|WP_Error {fields: field_key => translated text, usage: {...}} on success.
	 */
	public static function translate( array $payload, $source_lang, $dest_lang, $model_id, $log = null ) {
		$messages = array(
			array(
				'role'    => 'system',
				'content' => self::build_system_prompt( Settings::get( 'prompt' ), $source_lang, $dest_lang ),
			),
			array(
				'role'    => 'user',
				'content' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
			),
		);

		$result = self::send_request( $messages, $model_id, 90, $log );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'fields' => $result['data'],
			'usage'  => $result['usage'],
		);
	}

	/**
	 * Batched translate: $payload nests several posts' field maps under their
	 * own job_id key in one request.
	 *
	 * @param array    $payload     job_id => {field_key => source text}.
	 * @param string   $source_lang Source WPML language code.
	 * @param string   $dest_lang   Destination WPML language code.
	 * @param string   $model_id    OpenRouter model id.
	 * @param callable $log         Optional status callback.
	 * @return array|WP_Error {results: job_id => {field_key => translated text}, usage: {...}} on success.
	 */
	public static function translate_batch( array $payload, $source_lang, $dest_lang, $model_id, $log = null ) {
		$messages = array(
			array(
				'role'    => 'system',
				'content' => self::build_batch_system_prompt( Settings::get( 'prompt' ), $source_lang, $dest_lang ),
			),
			array(
				'role'    => 'user',
				'content' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
			),
		);

		$result = self::send_request( $messages, $model_id, self::BATCH_TIMEOUT, $log );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'results' => $result['data'],
			'usage'   => $result['usage'],
		);
	}

	/**
	 * Shared HTTP/retry/parsing core behind translate() and translate_batch().
	 *
	 * @param array    $messages Chat messages.
	 * @param string   $model_id OpenRouter model id.
	 * @param int      $timeout  Request timeout in seconds.
	 * @param callable $log      Optional status callback.
	 * @return array|WP_Error {data: decoded JSON object, usage: {...}} on success.
	 */
	protected static function send_request( array $messages, $model_id, $timeout, $log = null ) {
		if ( ! is_callable( $log ) ) {
			$log = static function ( $message ) {};
		}

		$api_key = Settings::get( 'api_key' );
		$model   = self::get_model( $model_id );

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'pxat_no_api_key',
				sprintf(
					/* translators: %s: plugin name. */
					__( 'No OpenRouter API key configured. Add one on the %s settings screen.', 'perxel-ai-translate' ),
					PXAT_NAME
				)
			);
		}

		$body = array(
			'model'           => $model['id'],
			'response_format' => array( 'type' => 'json_object' ),
			'messages'        => $messages,
		);

		$attempt = 0;

		while ( true ) {
			$log( 0 === $attempt ? 'request sent to OpenRouter' : sprintf( 'retry %d to OpenRouter', $attempt ) );

			$response = wp_remote_post(
				self::API_URL,
				array(
					'timeout' => $timeout,
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode( $body ),
				)
			);

			if ( is_wp_error( $response ) ) {
				$log( 'request failed: ' . $response->get_error_message() );
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );

			if ( 429 === $code && $attempt < self::MAX_RETRIES ) {
				++$attempt;
				$wait = $attempt * 2;
				$log( sprintf( 'rate limited (HTTP 429), waiting %ds before retry %d/%d', $wait, $attempt, self::MAX_RETRIES ) );
				sleep( $wait ); // phpcs:ignore WordPress.PHP.NoSilentErrors, WordPressVIPMinimum.Performance.SleepFunction -- deliberate short back-off between retries of an admin-triggered request.
				continue;
			}

			if ( $code < 200 || $code >= 300 ) {
				$raw = wp_remote_retrieve_body( $response );
				$log( sprintf( 'OpenRouter returned HTTP %d', $code ) );
				return new WP_Error(
					'pxat_api_error',
					sprintf(
						/* translators: 1: HTTP status code, 2: raw error body. */
						__( 'OpenRouter API error (HTTP %1$d): %2$s', 'perxel-ai-translate' ),
						$code,
						substr( $raw, 0, 500 )
					)
				);
			}

			$log( 'response received (HTTP ' . $code . '), parsing' );

			$raw     = wp_remote_retrieve_body( $response );
			$json    = json_decode( $raw, true );
			$content = isset( $json['choices'][0]['message']['content'] ) ? $json['choices'][0]['message']['content'] : null;

			if ( null === $content ) {
				$log( 'unexpected response shape from OpenRouter' );
				return new WP_Error(
					'pxat_bad_response',
					__( 'Unexpected response from OpenRouter: ', 'perxel-ai-translate' ) . substr( $raw, 0, 500 )
				);
			}

			$parsed = json_decode( $content, true );
			if ( null === $parsed || ! is_array( $parsed ) ) {
				$log( 'could not parse translated JSON from the model reply' );
				return new WP_Error(
					'pxat_parse_error',
					__( 'Could not parse the translated JSON: ', 'perxel-ai-translate' ) . substr( $content, 0, 500 )
				);
			}

			$usage = isset( $json['usage'] ) && is_array( $json['usage'] ) ? $json['usage'] : array();

			return array(
				'data'  => $parsed,
				'usage' => array(
					'prompt_tokens'     => isset( $usage['prompt_tokens'] ) ? (int) $usage['prompt_tokens'] : 0,
					'completion_tokens' => isset( $usage['completion_tokens'] ) ? (int) $usage['completion_tokens'] : 0,
					'total_tokens'      => isset( $usage['total_tokens'] ) ? (int) $usage['total_tokens'] : 0,
				),
			);
		}
	}

	/**
	 * @param string $model_id OpenRouter model id.
	 * @return int Token budget one batch request should stay under, or 0 if the model has none configured.
	 */
	public static function get_batch_output_budget( $model_id ) {
		$model      = self::get_model( $model_id );
		$max_output = isset( $model['max_output_tokens'] ) ? (int) $model['max_output_tokens'] : 0;

		return $max_output > 0 ? (int) floor( $max_output * self::BATCH_OUTPUT_SAFETY_FACTOR ) : 0;
	}

	/**
	 * @return array[] All configured models: {id, label, input, output, max_output_tokens?}.
	 */
	public static function get_models() {
		/**
		 * Filters the list of OpenRouter models offered to the user.
		 *
		 * @param array[] $models Each entry: {id, label, input, output, max_output_tokens?}.
		 */
		$models = apply_filters( 'pxat_openrouter_models', PXAT_OPENROUTER_MODELS );

		return ( is_array( $models ) && $models ) ? array_values( $models ) : PXAT_OPENROUTER_MODELS;
	}

	/**
	 * @param string $model_id OpenRouter model id.
	 * @return array Matching model def, or the first configured model if $model_id is empty/unknown.
	 */
	public static function get_model( $model_id ) {
		foreach ( self::get_models() as $model ) {
			if ( $model['id'] === $model_id ) {
				return $model;
			}
		}
		return self::get_models()[0];
	}

	/**
	 * Pre-flight token estimate for one job, mirroring what translate() sends.
	 *
	 * @param array $payload             field_key => source text.
	 * @param int   $system_prompt_chars mb_strlen() of the exact system prompt.
	 * @return array {prompt_tokens, completion_tokens}
	 */
	public static function estimate_job_tokens( array $payload, $system_prompt_chars ) {
		$user_chars = mb_strlen( wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ) );

		return array(
			'prompt_tokens'     => (int) ceil( ( $system_prompt_chars + $user_chars ) / self::CHARS_PER_TOKEN ),
			'completion_tokens' => (int) ceil( $user_chars / self::CHARS_PER_TOKEN ),
		);
	}

	/**
	 * @param int    $prompt_tokens     Prompt token count.
	 * @param int    $completion_tokens Completion token count.
	 * @param string $model_id          OpenRouter model id.
	 * @return float USD cost.
	 */
	public static function estimate_cost( $prompt_tokens, $completion_tokens, $model_id ) {
		$model = self::get_model( $model_id );
		return ( $prompt_tokens / 1000000 ) * $model['input'] + ( $completion_tokens / 1000000 ) * $model['output'];
	}

	/**
	 * Validates a key against OpenRouter's own key-info endpoint (no completion
	 * call, so it costs nothing).
	 *
	 * @param string $api_key The key to check.
	 * @return array|WP_Error Key info array on success.
	 */
	public static function test_api_key( $api_key ) {
		if ( empty( $api_key ) ) {
			return new WP_Error( 'pxat_no_api_key', __( 'Enter an API key first.', 'perxel-ai-translate' ) );
		}

		$response = wp_remote_get(
			'https://openrouter.ai/api/v1/auth/key',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $json['error']['message'] ) ? $json['error']['message'] : substr( $raw, 0, 300 );
			/* translators: 1: HTTP status code, 2: error message. */
			return new WP_Error( 'pxat_invalid_key', sprintf( __( 'HTTP %1$d: %2$s', 'perxel-ai-translate' ), $code, $message ) );
		}

		return isset( $json['data'] ) && is_array( $json['data'] ) ? $json['data'] : array();
	}

	/**
	 * @param string $user_prompt Extra instructions appended to the system prompt.
	 * @param string $source_lang Source language code.
	 * @param string $dest_lang   Destination language code.
	 * @return string
	 */
	public static function build_system_prompt( $user_prompt, $source_lang, $dest_lang ) {
		$system = 'You are a professional translator. Translate the values of the JSON object provided by the '
			. "user from \"{$source_lang}\" to \"{$dest_lang}\". Keep the exact same JSON keys. Some values contain "
			. 'HTML tags and page-builder shortcodes (e.g. [vc_row], [vc_column][/vc_column]); preserve '
			. 'all tags, shortcodes and their attributes exactly as-is, only translate the human-readable text '
			. 'content between/inside them. Return ONLY a single JSON object with the same keys as the input and '
			. 'translated string values, no extra commentary, no markdown code fences.';

		if ( ! empty( $user_prompt ) ) {
			$system .= "\n\nAdditional instructions:\n" . $user_prompt;
		}

		return $system;
	}

	/**
	 * Same contract as build_system_prompt(), one level deeper: the user message
	 * is job_id => {field_key: text} for several posts at once.
	 *
	 * @param string $user_prompt Extra instructions appended to the system prompt.
	 * @param string $source_lang Source language code.
	 * @param string $dest_lang   Destination language code.
	 * @return string
	 */
	public static function build_batch_system_prompt( $user_prompt, $source_lang, $dest_lang ) {
		$system = 'You are a professional translator. You will receive a JSON object mapping several item IDs to '
			. "field objects. For every item, translate the values of its field object from \"{$source_lang}\" to "
			. '"' . $dest_lang . '". Keep the exact same item IDs, and the exact same field keys within each item. '
			. 'Some values contain HTML tags and page-builder shortcodes (e.g. [vc_row], '
			. '[vc_column][/vc_column]); preserve all tags, shortcodes and their attributes exactly as-is, only '
			. 'translate the human-readable text content between/inside them. Return ONLY a single JSON object '
			. 'with the same item IDs, each mapped to an object with the same field keys and translated string '
			. 'values, no extra commentary, no markdown code fences.';

		if ( ! empty( $user_prompt ) ) {
			$system .= "\n\nAdditional instructions:\n" . $user_prompt;
		}

		return $system;
	}
}
