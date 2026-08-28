( function () {
	'use strict';

	var i18n = window.wp && window.wp.i18n ? window.wp.i18n : null;
	function __( text ) {
		return i18n ? i18n.__( text, 'perxel-ai-translate' ) : text;
	}

	var btn = document.getElementById( 'pxat-test-api-key' );
	var resultEl = document.getElementById( 'pxat-test-api-key-result' );
	var input = document.getElementById( 'pxat_api_key' );

	if ( btn && resultEl && input ) {
		btn.addEventListener( 'click', function () {
			var apiKey = input.value.trim();

			resultEl.textContent = __( 'Checking…' );
			resultEl.className = 'pxat-test-result';

			var body = new URLSearchParams();
			body.set( 'action', 'pxat_test_api_key' );
			body.set( 'nonce', PXAT_Settings.nonce );
			body.set( 'api_key', apiKey );

			fetch( PXAT_Settings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			} )
				.then( function ( res ) {
					return res.json();
				} )
				.then( function ( res ) {
					if ( res.success ) {
						var msg = res.data.message;
						if ( res.data.limit !== null && typeof res.data.limit !== 'undefined' ) {
							msg += ' (' + __( 'used' ) + ': $' + res.data.usage + ' / ' + __( 'limit' ) + ': $' + res.data.limit + ')';
						}
						resultEl.textContent = msg;
						resultEl.className = 'pxat-test-result pxat-test-result--ok';
					} else {
						resultEl.textContent = ( res.data && res.data.message ) ? res.data.message : __( 'Invalid key.' );
						resultEl.className = 'pxat-test-result pxat-test-result--fail';
					}
				} )
				.catch( function ( err ) {
					resultEl.textContent = __( 'Request failed: ' ) + err.message;
					resultEl.className = 'pxat-test-result pxat-test-result--fail';
				} );
		} );
	}

	var copyBtn = document.getElementById( 'pxat-copy-system-prompt' );
	var copySource = document.getElementById( 'pxat_system_prompt_preview' );
	var copyResultEl = document.getElementById( 'pxat-copy-system-prompt-result' );

	if ( copyBtn && copySource && copyResultEl ) {
		copyBtn.addEventListener( 'click', function () {
			navigator.clipboard.writeText( copySource.textContent ).then(
				function () {
					copyResultEl.textContent = __( 'Copied.' );
					copyResultEl.className = 'pxat-test-result pxat-test-result--ok';
				},
				function () {
					copyResultEl.textContent = __( 'Could not copy automatically, please select and copy manually.' );
					copyResultEl.className = 'pxat-test-result pxat-test-result--fail';
				}
			);
		} );
	}
} )();
