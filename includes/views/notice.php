<?php
/**
 * Standalone notice inside the shared layout.
 *
 * @package Perxel_AI_Translate
 *
 * @var string $type success|warning|error|info
 * @var string $text Trusted HTML message.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo \Perxel_UI::notice( $type, $text ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Perxel_UI escapes structure; caller-supplied message.
