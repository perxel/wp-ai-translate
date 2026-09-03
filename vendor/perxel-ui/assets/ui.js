/**
 * Perxel shared admin UI - minimal behaviour.
 *
 * WordPress core already wires dismiss buttons onto `.notice.is-dismissible`,
 * so the kit adds only two guards:
 *
 * 1. Destructive-action confirm. Any element carrying `data-pxui-confirm` is
 *    click-blocked unless the native confirm is accepted:
 *
 *        <button class="button" data-pxui-confirm="Delete everything?">Delete</button>
 *
 * 2. Unsaved-changes guard. A `<form data-pxui-dirty-guard>` is snapshotted on
 *    load; once a field differs, leaving the page (menu link, back button,
 *    closing the tab) trips the browser's native "Leave site?" prompt. The
 *    guard clears itself when that form submits.
 *
 *      - Fields that are `[disabled]`, `[readonly]`, `[type=hidden]`, buttons,
 *        or carry `data-pxui-dirty-ignore` are left out of the snapshot.
 *      - Programmatic `.value =` writes fire no event and so never mark the
 *        form dirty; a script that changes state the user should be warned
 *        about calls `pxui.dirtyGuard.mark( form )`. `clear( form )` and
 *        `resnapshot( form )` are the counterparts.
 *      - The native prompt text cannot be customised; the attribute takes no
 *        value.
 */
( function () {
	'use strict';

	/* --- 1. Destructive-action confirm -------------------------------- */

	document.addEventListener( 'click', function ( ev ) {
		var t = ev.target;
		if ( ! t || ! t.closest ) {
			return;
		}

		var el = t.closest( '[data-pxui-confirm]' );
		if ( ! el ) {
			return;
		}

		var message = el.getAttribute( 'data-pxui-confirm' ) || 'Are you sure?';
		if ( ! window.confirm( message ) ) {
			ev.preventDefault();
			ev.stopImmediatePropagation();
		}
	}, true );

	/* --- 2. Unsaved-changes guard ----------------------------------- */

	var SKIP_TYPES = { hidden: 1, submit: 1, button: 1, reset: 1, image: 1, file: 1 };
	var FORCED = 'PXUI_FORCED_DIRTY';

	function counted( field ) {
		if ( ! field.name || field.disabled || field.readOnly ) {
			return false;
		}
		if ( SKIP_TYPES[ field.type ] ) {
			return false;
		}
		if ( field.closest && field.closest( '[data-pxui-dirty-ignore]' ) ) {
			return false;
		}
		return true;
	}

	function snapshot( form ) {
		var parts = [];
		var fields = form.elements;
		for ( var i = 0; i < fields.length; i++ ) {
			var field = fields[ i ];
			if ( ! counted( field ) ) {
				continue;
			}
			var value;
			if ( 'checkbox' === field.type || 'radio' === field.type ) {
				value = field.checked ? '1' : '0';
			} else if ( field.multiple && field.options ) {
				var picked = [];
				for ( var o = 0; o < field.options.length; o++ ) {
					if ( field.options[ o ].selected ) {
						picked.push( field.options[ o ].value );
					}
				}
				value = picked.join( ',' );
			} else {
				value = field.value;
			}
			// A snapshot line always starts with a digit, so the FORCED
			// sentinel can never compare equal to a real snapshot.
			parts.push( i + ':' + field.name + '=' + value );
		}
		return parts.join( '\n' );
	}

	var guarded = [];

	function entryFor( form ) {
		for ( var i = 0; i < guarded.length; i++ ) {
			if ( guarded[ i ].form === form ) {
				return guarded[ i ];
			}
		}
		return null;
	}

	function anyDirty() {
		for ( var i = 0; i < guarded.length; i++ ) {
			var e = guarded[ i ];
			if ( e.clean === FORCED || e.clean !== snapshot( e.form ) ) {
				return true;
			}
		}
		return false;
	}

	function init() {
		var forms = document.querySelectorAll( 'form[data-pxui-dirty-guard]' );
		if ( ! forms.length ) {
			return;
		}

		Array.prototype.forEach.call( forms, function ( form ) {
			var entry = { form: form, clean: snapshot( form ) };
			guarded.push( entry );

			form.addEventListener( 'submit', function () {
				entry.clean = snapshot( form );
			} );
		} );

		window.addEventListener( 'beforeunload', function ( ev ) {
			if ( anyDirty() ) {
				ev.preventDefault();
				ev.returnValue = '';
			}
		} );

		window.pxui.dirtyGuard = {
			// Force the form dirty - state changed without a field event.
			mark: function ( form ) {
				var e = entryFor( form );
				if ( e ) {
					e.clean = FORCED;
				}
			},
			// Treat the form's current values as the saved baseline.
			clear: function ( form ) {
				var e = entryFor( form );
				if ( e ) {
					e.clean = snapshot( form );
				}
			},
			resnapshot: function ( form ) {
				this.clear( form );
			}
		};
	}

	window.pxui = window.pxui || {};

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
