/**
 * Confirm screen: reveal the "Fields" row only when "Specific fields" is picked,
 * and re-run the plan automatically when the config changes - a spinner shows by
 * the controls while the reload is pending and the start buttons are held
 * disabled so a run can't start against a stale plan. The <noscript> "Update
 * plan" button is the fallback when this script does not run.
 */
( function () {
	'use strict';

	var form = document.getElementById( 'pxat-config-form' );
	if ( ! form ) {
		return;
	}

	var fieldsBox = document.getElementById( 'pxat-fields' );
	var fieldsRow = fieldsBox ? fieldsBox.closest( '.pxui-row' ) : null;
	var status    = document.getElementById( 'pxat-config-status' );
	var startBtns = document.querySelectorAll( '#pxat-start-form button[type="submit"], button[type="submit"][form="pxat-start-form"]' );

	function specificChosen() {
		var custom = form.querySelector( 'input[name="data_mode"][value="custom"]' );
		return !! ( custom && custom.checked );
	}

	function syncFields() {
		var on = specificChosen();
		if ( fieldsRow ) {
			fieldsRow.hidden = ! on;
		}
		if ( fieldsBox ) {
			fieldsBox.querySelectorAll( 'input' ).forEach( function ( i ) {
				i.disabled = ! on;
			} );
		}
	}

	var timer = null;
	function scheduleSubmit() {
		clearTimeout( timer );
		if ( status ) {
			status.hidden = false;
		}
		startBtns.forEach( function ( b ) {
			b.disabled = true;
		} );
		timer = setTimeout( function () {
			form.submit();
		}, 350 );
	}

	form.addEventListener( 'change', function () {
		syncFields();
		scheduleSubmit();
	} );

	syncFields();
}() );
