/**
 * Confirm screen: keep the "choose specific fields" pills enabled only when the
 * Custom radio is picked, and re-run the preview automatically when the config
 * changes (the "Update preview" button is the no-JS fallback).
 */
( function () {
	'use strict';

	var form = document.getElementById( 'pxat-config-form' );
	if ( ! form ) {
		return;
	}

	var customBox = document.getElementById( 'pxat-custom-types' );

	function syncCustomState() {
		if ( ! customBox ) {
			return;
		}
		var custom = form.querySelector( 'input[name="data_mode"][value="custom"]' );
		var on = custom && custom.checked;
		customBox.style.opacity = on ? '1' : '0.45';
		customBox.querySelectorAll( 'input' ).forEach( function ( i ) {
			i.disabled = ! on;
		} );
	}

	var submitTimer = null;
	function scheduleSubmit() {
		clearTimeout( submitTimer );
		submitTimer = setTimeout( function () {
			form.submit();
		}, 400 );
	}

	form.addEventListener( 'change', function ( ev ) {
		syncCustomState();
		if ( ev.target && ev.target.id !== 'pxat-config-update' ) {
			scheduleSubmit();
		}
	} );

	syncCustomState();
}() );
