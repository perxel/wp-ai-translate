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

\Perxel_Ai_Translate\Admin::kit( \Perxel_UI::notice( $type, $text ) );
