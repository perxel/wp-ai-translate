<?php
/**
 * Translation cart - empty state.
 *
 * @package Perxel_AI_Translate
 *
 * @var array|null $conflict { tried, cart_type, new_type } when an add was refused for a type mismatch.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Perxel_UI escapes structure; dynamic values escaped inline.

if ( $conflict ) {
	echo \Perxel_UI::notice(
		'warning',
		esc_html__( 'Those posts were a different post type from what the cart held, so nothing was added. The cart is now empty - add them again.', 'perxel-ai-translate' )
	);
}

echo \Perxel_UI::notice( 'info', esc_html__( 'Your translation cart is empty.', 'perxel-ai-translate' ) );

echo \Perxel_UI::rows(
	array(
		array(
			'title' => __( 'Add posts to the cart', 'perxel-ai-translate' ),
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
);

// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
