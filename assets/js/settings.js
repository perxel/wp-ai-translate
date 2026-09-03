/**
 * Settings screen: test the OpenRouter API key and the model without leaving
 * the page, and keep the "verified" hidden inputs honest.
 */
( function () {
	'use strict';

	var cfg = window.PXAT_Settings || {};
	var __ = ( window.wp && wp.i18n ) ? wp.i18n.__ : function ( s ) {
		return s;
	};

	function $( id ) {
		return document.getElementById( id );
	}

	function ajax( action, data ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', cfg.nonce );
		Object.keys( data ).forEach( function ( k ) {
			body.set( k, data[ k ] );
		} );
		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	function setResult( node, text, ok ) {
		if ( ! node ) {
			return;
		}
		node.textContent = text;
		node.className = 'pxat-test-result' + ( ok === true ? ' is-ok' : ok === false ? ' is-bad' : '' );
	}

	/* --- API key ------------------------------------------------- */

	var keyInput = $( 'pxat-api-key' );
	var keyResult = $( 'pxat-key-result' );
	var keyVerified = $( 'pxat-api-key-verified' );
	var keyBtn = $( 'pxat-test-key' );

	if ( keyBtn && keyInput ) {
		keyBtn.addEventListener( 'click', function () {
			setResult( keyResult, __( 'Checking…', 'perxel-ai-translate' ), null );
			ajax( 'pxat_test_api_key', { api_key: keyInput.value } ).then( function ( res ) {
				if ( res && res.success ) {
					setResult( keyResult, res.data.message || __( 'API key is valid.', 'perxel-ai-translate' ), true );
					if ( keyVerified ) {
						keyVerified.value = '1';
					}
				} else {
					setResult( keyResult, ( res && res.data && res.data.message ) || __( 'Could not validate the key.', 'perxel-ai-translate' ), false );
					if ( keyVerified ) {
						keyVerified.value = '';
					}
				}
			} ).catch( function () {
				setResult( keyResult, __( 'Request failed.', 'perxel-ai-translate' ), false );
			} );
		} );

		keyInput.addEventListener( 'input', function () {
			if ( keyVerified ) {
				keyVerified.value = '';
			}
			setResult( keyResult, '', null );
		} );
	}

	/* --- Model ------------------------------------------------- */

	var modelInput = $( 'pxat-model-id' );
	var modelResult = $( 'pxat-model-result' );
	var modelDetail = $( 'pxat-model-detail' );
	var modelBtn = $( 'pxat-test-model' );

	var hidden = {
		verified: $( 'pxat-model-verified' ),
		label: $( 'pxat-model-label' ),
		input: $( 'pxat-model-input' ),
		output: $( 'pxat-model-output' ),
		context: $( 'pxat-model-context' ),
		maxOutput: $( 'pxat-model-max-output' )
	};

	function clearModelVerification() {
		if ( hidden.verified ) {
			hidden.verified.value = '';
		}
	}

	if ( modelBtn && modelInput ) {
		modelBtn.addEventListener( 'click', function () {
			setResult( modelResult, __( 'Checking…', 'perxel-ai-translate' ), null );
			ajax( 'pxat_test_model', { model_id: modelInput.value } ).then( function ( res ) {
				if ( res && res.success ) {
					var d = res.data;
					setResult( modelResult, __( 'Model found.', 'perxel-ai-translate' ), true );
					if ( modelDetail ) {
						modelDetail.textContent = d.summary || '';
					}
					if ( hidden.verified ) { hidden.verified.value = '1'; }
					if ( hidden.label ) { hidden.label.value = d.label || ''; }
					if ( hidden.input ) { hidden.input.value = d.input || 0; }
					if ( hidden.output ) { hidden.output.value = d.output || 0; }
					if ( hidden.context ) { hidden.context.value = d.context || 0; }
					if ( hidden.maxOutput ) { hidden.maxOutput.value = d.max_output || 0; }
				} else {
					setResult( modelResult, ( res && res.data && res.data.message ) || __( 'Model not found.', 'perxel-ai-translate' ), false );
					clearModelVerification();
				}
			} ).catch( function () {
				setResult( modelResult, __( 'Request failed.', 'perxel-ai-translate' ), false );
			} );
		} );

		modelInput.addEventListener( 'input', function () {
			clearModelVerification();
			setResult( modelResult, '', null );
		} );
	}
}() );
