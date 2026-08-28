( function () {
	'use strict';

	var i18n = window.wp && window.wp.i18n ? window.wp.i18n : null;
	function __( text ) {
		return i18n ? i18n.__( text, 'perxel-ai-translate' ) : text;
	}

	var copyBtn = document.getElementById( 'pxat-copy-output' );
	var output = document.getElementById( 'pxat_output' );
	var resultEl = document.getElementById( 'pxat-copy-output-result' );

	if ( ! copyBtn || ! output || ! resultEl ) {
		return;
	}

	copyBtn.addEventListener( 'click', function () {
		navigator.clipboard.writeText( output.value ).then(
			function () {
				resultEl.textContent = __( 'Copied.' );
				resultEl.className = 'pxat-test-result pxat-test-result--ok';
			},
			function () {
				resultEl.textContent = __( 'Could not copy automatically, please select and copy manually.' );
				resultEl.className = 'pxat-test-result pxat-test-result--fail';
			}
		);
	} );
} )();
