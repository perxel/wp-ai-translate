<?php

namespace Perxel\AITranslate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for how volume and estimated cost are displayed.
 * Every PHP call site renders these through here; assets/js/format.js mirrors
 * the same rules for the progress screen's AJAX-polled counters.
 *
 * Volume is always shown as an estimated word count - "token" is an
 * implementation detail the site owner never needs. Cost is our own estimate of
 * spend: shown in USD, or converted to VND on sites whose WPML default language
 * is Vietnamese. Model price sheets ($/1M tokens) are rendered elsewhere and
 * stay in USD to keep OpenRouter's exact rates intact.
 */
class Format {

	// OpenRouter/OpenAI's own rule of thumb: ~750 words per 1000 tokens. The
	// word count is English-centric and only ever a sense of scale, hence "~".
	const WORDS_PER_TOKEN = 0.75;

	// Fixed display rate, no setting. Approximate, set 2026-09. OpenRouter
	// always bills in USD; this only converts our own estimate for readability.
	const USD_TO_VND = 26000;

	/**
	 * @param int $tokens Token count.
	 * @return int Approximate word count.
	 */
	public static function tokens_to_words( $tokens ) {
		return (int) round( $tokens * self::WORDS_PER_TOKEN );
	}

	/**
	 * "~926 words" - an estimated word count for a token total.
	 *
	 * @param int $tokens Token count.
	 * @return string
	 */
	public static function unit_label( $tokens ) {
		/* translators: %s: approximate word count. */
		return sprintf( __( '~%s words', 'perxel-ai-translate' ), number_format_i18n( self::tokens_to_words( $tokens ) ) );
	}

	/**
	 * Currency our estimated costs are displayed in: 'VND' on a Vietnamese
	 * default-language site, 'USD' everywhere else. Resolved once per request.
	 *
	 * @return string
	 */
	public static function currency() {
		static $currency = null;
		if ( null === $currency ) {
			$currency = 'vi' === Wpml::get_default_language() ? 'VND' : 'USD';
		}
		return $currency;
	}

	/**
	 * "~$0.0123" or "~319,800₫" - a rough estimate of spend. OpenRouter bills
	 * in USD; the VND figure is a fixed-rate convenience conversion.
	 *
	 * @param float $cost_usd Cost in US dollars.
	 * @return string
	 */
	public static function cost( $cost_usd ) {
		$cost_usd = (float) $cost_usd;

		if ( 'VND' === self::currency() ) {
			/* translators: %s: approximate cost in Vietnamese dong. */
			return sprintf( __( '~%s₫', 'perxel-ai-translate' ), number_format_i18n( round( $cost_usd * self::USD_TO_VND ) ) );
		}

		if ( $cost_usd > 0 && $cost_usd < 0.01 ) {
			return '~$' . number_format( $cost_usd, 4 );
		}

		return '~$' . number_format( $cost_usd, 2 );
	}

	/**
	 * "02:15" or "1:02:15" - elapsed seconds as a clock-style duration.
	 *
	 * @param int $seconds Elapsed seconds.
	 * @return string
	 */
	public static function duration( $seconds ) {
		$seconds = max( 0, (int) $seconds );
		$hours   = (int) floor( $seconds / 3600 );
		$minutes = (int) floor( ( $seconds % 3600 ) / 60 );
		$secs    = $seconds % 60;

		if ( $hours > 0 ) {
			return sprintf( '%d:%02d:%02d', $hours, $minutes, $secs );
		}
		return sprintf( '%02d:%02d', $minutes, $secs );
	}

	/**
	 * "5 mins ago" - relative time for a MySQL datetime stored site-local via
	 * current_time( 'mysql' ).
	 *
	 * @param string $mysql_datetime A 'Y-m-d H:i:s' timestamp.
	 * @return string
	 */
	public static function time_ago( $mysql_datetime ) {
		if ( ! $mysql_datetime ) {
			return '';
		}

		return sprintf(
			/* translators: %s: human-readable time difference, e.g. "5 mins". */
			__( '%s ago', 'perxel-ai-translate' ),
			human_time_diff( strtotime( $mysql_datetime ), current_time( 'timestamp' ) ) // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- comparing against a site-local stored datetime.
		);
	}
}
