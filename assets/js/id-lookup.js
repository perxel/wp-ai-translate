/**
 * ID lookup: copy the result list to the clipboard.
 */
( function () {
	'use strict';

	var __ = ( window.wp && wp.i18n ) ? wp.i18n.__ : function ( s ) {
		return s;
	};

	var btn = document.getElementById( 'pxat-copy-output' );
	var out = document.getElementById( 'pxat-output' );
	var result = document.getElementById( 'pxat-copy-output-result' );

	if ( ! btn || ! out ) {
		return;
	}

	btn.addEventListener( 'click', function () {
		out.select();
		var ok = false;
		try {
			ok = document.execCommand( 'copy' );
		} catch ( e ) {
			ok = false;
		}
		if ( navigator.clipboard && ! ok ) {
			navigator.clipboard.writeText( out.value );
			ok = true;
		}
		if ( result ) {
			result.textContent = ok ? __( 'Copied.', 'perxel-ai-translate' ) : __( 'Press Ctrl/Cmd+C to copy.', 'perxel-ai-translate' );
		}
	} );
}() );
