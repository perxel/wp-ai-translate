<?php
/**
 * Shared "hub" nav footer, included at the bottom of every top-level plugin
 * screen so the link set stays identical everywhere.
 *
 * @package Perxel_AI_Translate
 *
 * @var string $footer_exclude Page slug to omit from the link list (the page including this partial), '' if none.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$footer_links = array(
	PXAT_History_Page::PAGE_SLUG   => __( 'Translation history', 'perxel-ai-translate' ),
	PXAT_ID_Lookup_Page::PAGE_SLUG => __( 'Translation ID lookup', 'perxel-ai-translate' ),
	PXAT_Settings::PAGE_SLUG       => __( 'Settings', 'perxel-ai-translate' ),
);
?>
<p class="pxat-footer">
	<?php foreach ( $footer_links as $slug => $label ) : ?>
		<?php if ( $slug === $footer_exclude ) : ?>
			<?php continue; ?>
		<?php endif; ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"><?php echo esc_html( $label ); ?></a>
		|
	<?php endforeach; ?>
	<?php
	/* translators: %s: plugin version number. */
	printf( esc_html__( 'Version %s', 'perxel-ai-translate' ), esc_html( PXAT_VERSION ) );
	?>
</p>
