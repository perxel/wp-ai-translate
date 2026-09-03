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

	var originalLabel = testBtn.textContent;

	function runChecks( which ) {
		testBtn.disabled = true;
		testBtn.textContent = __( 'Testing…', 'perxel-ai-translate' );

		function done() {
			testBtn.disabled = false;
			testBtn.textContent = originalLabel;
		}

		var chain = Promise.resolve();
		if ( which.key ) {
			chain = chain.then( testKey );
		}
		if ( which.model ) {
			chain = chain.then( testModel );
		}
		chain.then( done, function () {
			setSub( 'pxat-key-sub', __( 'Request failed.', 'perxel-ai-translate' ) );
			done();
		} );
	}

	testBtn.addEventListener( 'click', function () {
		runChecks( { key: true, model: true } );
	} );

	// On load, verify anything set but not yet verified - so the status dots
	// reflect reality, not just the last time someone pressed Test. An already
	// verified value is trusted until it is edited or the key changes on save.
	var pending = {
		key: keyInput.value.trim() !== '' && ! ( hidden.keyVerified && hidden.keyVerified.value ),
		model: modelInput.value.trim() !== '' && ! ( hidden.modelVerified && hidden.modelVerified.value )
	};
	if ( pending.key || pending.model ) {
		runChecks( pending );
	}

	// Editing a field makes its verified state stale.
	keyInput.addEventListener( 'input', function () {
		if ( hidden.keyVerified ) {
			hidden.keyVerified.value = '';
		}
		setDot( keyInput, 'muted' );
		setSub( 'pxat-key-sub', __( 'not checked', 'perxel-ai-translate' ) );
	} );

	function setHidden( node, value ) {
		if ( node ) {
			node.value = value;
		}
	}

	modelInput.addEventListener( 'input', function () {
		setHidden( hidden.modelVerified, '' );
		setHidden( hidden.label, '' );
		setHidden( hidden.input, '0' );
		setHidden( hidden.output, '0' );
		setHidden( hidden.context, '0' );
		setHidden( hidden.maxOutput, '0' );
		setDot( modelInput, 'muted' );
		setSub( 'pxat-model-detail', __( 'not checked', 'perxel-ai-translate' ) );
	} );
}() );
