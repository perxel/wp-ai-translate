<?php
/**
 * Number, cost and duration formatting.
 *
 * @package Perxel_AI_Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for how token/word counts and USD cost are displayed.
 * Every PHP call site renders these numbers through here instead of formatting
 * inline, and assets/js/pxat-format.js mirrors the same rules for progress.js's
 * AJAX-polled counts, since PHP and JS can't share one implementation directly.
 */
class PXAT_Format {

	// OpenRouter/OpenAI's own rule of thumb: ~750 words per 1000 tokens
	// (English-ish text). Good enough for a rough display estimate.
	const WORDS_PER_TOKEN = 0.75;

	/**
	 * 'tokens' or 'words', per the "Display unit" setting.
	 *
	 * @return string
	 */
	public static function display_unit() {
		$settings = PXAT_Settings::get_settings();
		return isset( $settings['display_unit'] ) && 'words' === $settings['display_unit'] ? 'words' : 'tokens';
	}

	/**
	 * @param int $tokens Token count.
	 * @return int Approximate word count.
	 */
	public static function tokens_to_words( $tokens ) {
		return (int) round( $tokens * self::WORDS_PER_TOKEN );
	}

	/**
	 * "1,234 tokens" or "~926 words", depending on the configured display unit.
	 *
	 * @param int $tokens Token count.
	 * @return string
	 */
	public static function unit_label( $tokens ) {
		if ( 'words' === self::display_unit() ) {
			/* translators: %s: approximate word count. */
			return sprintf( __( '~%s words', 'perxel-ai-translate' ), number_format_i18n( self::tokens_to_words( $tokens ) ) );
		}
		/* translators: %s: token count. */
		return sprintf( __( '%s tokens', 'perxel-ai-translate' ), number_format_i18n( $tokens ) );
	}

	/**
	 * "~$0.0123" — a rough USD cost estimate. OpenRouter bills in USD, so this
	 * is the plugin's native cost unit.
	 *
	 * @param float $cost_usd Cost in US dollars.
	 * @return string
	 */
	public static function cost( $cost_usd ) {
		$cost_usd = (float) $cost_usd;

		if ( $cost_usd > 0 && $cost_usd < 0.01 ) {
			return '~$' . number_format( $cost_usd, 4 );
		}

		return '~$' . number_format( $cost_usd, 2 );
	}

	/**
	 * "02:15" or "1:02:15" — elapsed seconds as a clock-style duration, for
	 * the batch's running-time stat card.
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
	 * "5 mins ago", "2 hours ago" — relative time for a MySQL datetime (as
	 * stored in $job['created_at'], site-local via current_time('mysql')).
	 * Single source of truth so the batch list and a batch's own progress
	 * page render this the same way instead of each formatting it inline.
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
