<?php
/**
 * Confirm screen - nothing selected.
 *
 * @package Perxel_AI_Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


\Perxel_Ai_Translate\Admin::kit( \Perxel_UI::notice( 'info', esc_html__( 'No posts selected to translate.', 'perxel-ai-translate' ) ) );

\Perxel_Ai_Translate\Admin::kit(
	\Perxel_UI::rows(
		array(
			array(
				'title' => __( 'Pick posts to translate', 'perxel-ai-translate' ),
				'rows'  => array(
					array(
						'label' => __( 'From a post list', 'perxel-ai-translate' ),
						'sub'   => esc_html__( 'Open Posts, Pages or any translatable type, tick the rows you want, then choose "Perxel AI Translate…" from the Bulk actions menu.', 'perxel-ai-translate' ),
					),
					array(
						'label' => __( 'While editing a post', 'perxel-ai-translate' ),
						'sub'   => esc_html__( 'Use "Translate this page" in the top admin bar.', 'perxel-ai-translate' ),
					),
				),
			),
		)
	)
);
