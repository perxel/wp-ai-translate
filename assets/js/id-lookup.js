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

	function report( ok ) {
		if ( result ) {
			result.textContent = ok
				? __( 'Copied.', 'perxel-ai-translate' )
				: __( 'Press Ctrl/Cmd+C to copy.', 'perxel-ai-translate' );
		}
	}

	function legacyCopy() {
		out.select();
		try {
			return document.execCommand( 'copy' );
		} catch ( e ) {
			return false;
		}
	}

	btn.addEventListener( 'click', function () {
		// The async Clipboard API reports real success/failure - only claim
		// "Copied." once it actually resolves. Fall back to execCommand if it
		// is unavailable or the write is denied.
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( out.value ).then(
				function () {
					report( true );
				},
				function () {
					report( legacyCopy() );
				}
			);
			return;
		}
		report( legacyCopy() );
	} );
}() );
