/**
 * Settings screen: test the OpenRouter API key without leaving the page.
 */
( function () {
	'use strict';

	var cfg = window.PXAT_Settings || {};
	var __ = ( window.wp && wp.i18n ) ? wp.i18n.__ : function ( s ) {
		return s;
	};

	var btn = document.getElementById( 'pxat-test-key' );
	var input = document.getElementById( 'pxat-api-key' );
	var out = document.getElementById( 'pxat-key-result' );

	if ( ! btn || ! input || ! out ) {
		return;
	}

	btn.addEventListener( 'click', function () {
		out.textContent = __( 'Checking…', 'perxel-ai-translate' );
		out.className = 'pxat-test-result';

		var body = new URLSearchParams();
		body.set( 'action', 'pxat_test_api_key' );
		body.set( 'nonce', cfg.nonce );
		body.set( 'api_key', input.value );

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( r ) {
			return r.json();
		} ).then( function ( res ) {
			if ( res && res.success ) {
				out.textContent = res.data.message || __( 'API key is valid.', 'perxel-ai-translate' );
				out.className = 'pxat-test-result is-ok';
			} else {
				out.textContent = ( res && res.data && res.data.message ) || __( 'Could not validate the key.', 'perxel-ai-translate' );
				out.className = 'pxat-test-result is-bad';
			}
		} ).catch( function () {
			out.textContent = __( 'Request failed.', 'perxel-ai-translate' );
			out.className = 'pxat-test-result is-bad';
		} );
	} );
}() );
