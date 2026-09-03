/**
 * Settings screen: one "Test" button (on the OpenRouter group title) checks the
 * API key, then the model, and updates each row's status dot + sub line.
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

	var keyInput = $( 'pxat-api-key' );
	var modelInput = $( 'pxat-model-id' );
	var testBtn = $( 'pxat-test' );

	if ( ! keyInput || ! modelInput || ! testBtn ) {
		return;
	}

	var hidden = {
		keyVerified: $( 'pxat-api-key-verified' ),
		modelVerified: $( 'pxat-model-verified' ),
		label: $( 'pxat-model-label' ),
		input: $( 'pxat-model-input' ),
		output: $( 'pxat-model-output' ),
		context: $( 'pxat-model-context' ),
		maxOutput: $( 'pxat-model-max-output' )
	};

	function setDot( input, state ) {
		var row = input.closest( '.pxui-row' );
		if ( ! row ) {
			return;
		}
		var icon = row.querySelector( '.pxui-row__icon' );
		if ( icon ) {
			icon.className = 'pxui-row__icon pxui-row__icon--' + state;
		}
	}

	function setSub( id, text ) {
		var node = $( id );
		if ( node ) {
			node.textContent = text;
		}
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

	function testKey() {
		setDot( keyInput, 'muted' );
		setSub( 'pxat-key-sub', __( 'Checking…', 'perxel-ai-translate' ) );
		return ajax( 'pxat_test_api_key', { api_key: keyInput.value } ).then( function ( res ) {
			if ( res && res.success ) {
				setDot( keyInput, 'good' );
				setSub( 'pxat-key-sub', res.data.message || __( 'Verified', 'perxel-ai-translate' ) );
				if ( hidden.keyVerified ) {
					hidden.keyVerified.value = '1';
				}
				return true;
			}
			setDot( keyInput, 'bad' );
			setSub( 'pxat-key-sub', ( res && res.data && res.data.message ) || __( 'Could not validate the key.', 'perxel-ai-translate' ) );
			if ( hidden.keyVerified ) {
				hidden.keyVerified.value = '';
			}
			return false;
		} );
	}

	function testModel() {
		setDot( modelInput, 'muted' );
		setSub( 'pxat-model-detail', __( 'Checking…', 'perxel-ai-translate' ) );
		return ajax( 'pxat_test_model', { model_id: modelInput.value } ).then( function ( res ) {
			if ( res && res.success ) {
				var d = res.data;
				setDot( modelInput, 'good' );
				setSub( 'pxat-model-detail', d.summary || '' );
				if ( hidden.modelVerified ) { hidden.modelVerified.value = '1'; }
				if ( hidden.label ) { hidden.label.value = d.label || ''; }
				if ( hidden.input ) { hidden.input.value = d.input || 0; }
				if ( hidden.output ) { hidden.output.value = d.output || 0; }
				if ( hidden.context ) { hidden.context.value = d.context || 0; }
				if ( hidden.maxOutput ) { hidden.maxOutput.value = d.max_output || 0; }
				return true;
			}
			setDot( modelInput, 'bad' );
			setSub( 'pxat-model-detail', ( res && res.data && res.data.message ) || __( 'Model not found.', 'perxel-ai-translate' ) );
			if ( hidden.modelVerified ) { hidden.modelVerified.value = ''; }
			return false;
		} );
	}

	testBtn.addEventListener( 'click', function () {
		testBtn.disabled = true;
		var original = testBtn.textContent;
		testBtn.textContent = __( 'Testing…', 'perxel-ai-translate' );

		function done() {
			testBtn.disabled = false;
			testBtn.textContent = original;
		}

		testKey().then( testModel ).then( done, function () {
			setSub( 'pxat-key-sub', __( 'Request failed.', 'perxel-ai-translate' ) );
			done();
		} );
	} );

	// Editing a field makes its verified state stale.
	keyInput.addEventListener( 'input', function () {
		if ( hidden.keyVerified ) {
			hidden.keyVerified.value = '';
		}
		setDot( keyInput, 'muted' );
		setSub( 'pxat-key-sub', __( 'not checked', 'perxel-ai-translate' ) );
	} );

	modelInput.addEventListener( 'input', function () {
		if ( hidden.modelVerified ) {
			hidden.modelVerified.value = '';
		}
		setDot( modelInput, 'muted' );
		setSub( 'pxat-model-detail', __( 'not checked', 'perxel-ai-translate' ) );
	} );
}() );
