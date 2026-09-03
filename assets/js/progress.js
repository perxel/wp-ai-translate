/**
 * Translation-run screen: a browser-driven loop that asks the server to
 * translate + write the next post(s), until the run is done. State lives in the
 * database, so closing the tab just pauses; reopening resumes.
 */
( function () {
	'use strict';

	var cfg = window.PXAT_Progress || {};
	var running = false;
	var finished = false;
	var stalled = false;
	var sessionExpired = false;
	var pollFails = 0;
	var activeWorkers = 0;
	var pollTimer = null;

	function el( sel, ctx ) {
		return ( ctx || document ).querySelector( sel );
	}

	// wp.i18n.__, or a passthrough until it loads. Named `__` (not `t`) because
	// `toggleButtons()` and the click handler each keep a local `var t`. Pass the
	// text domain at every call site so `wp i18n make-pot` can find the strings.
	var __ = ( window.wp && window.wp.i18n )
		? window.wp.i18n.__
		: function ( s ) {
			return s;
		};

	/* A one-line status next to the Start/Stop buttons so a click always
	   produces visible feedback (stopping, stopped, failed). */
	function setRunMsg( text ) {
		var box = el( '.pxui-main__actions' );
		if ( ! box ) {
			return;
		}
		var msg = el( '#pxat-run-msg' );
		if ( ! text ) {
			if ( msg ) {
				msg.parentNode.removeChild( msg );
			}
			return;
		}
		if ( ! msg ) {
			msg = document.createElement( 'span' );
			msg.id = 'pxat-run-msg';
			msg.className = 'pxat-run-msg';
			box.appendChild( msg );
		}
		msg.textContent = text;
	}

	function post( action, data ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', cfg.nonce );
		body.set( 'run_id', cfg.runId );
		Object.keys( data || {} ).forEach( function ( k ) {
			body.set( k, data[ k ] );
		} );
		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( r ) {
			// A 401/403 here is almost always an expired nonce - the tab has
			// been open past the nonce lifetime. Flag it so callers can tell
			// the user to reload rather than "try again".
			if ( 401 === r.status || 403 === r.status ) {
				var expiredErr = new Error( 'session expired' );
				expiredErr.expired = true;
				throw expiredErr;
			}
			if ( ! r.ok ) {
				throw new Error( 'HTTP ' + r.status );
			}
			return r.json();
		} );
	}

	function isExpired( err ) {
		return !! ( err && err.expired );
	}

	function setText( id, value ) {
		var node = el( '#' + id );
		if ( node ) {
			node.textContent = value;
		}
	}

	function renderCounts( d ) {
		if ( ! d || ! d.counts ) {
			return;
		}
		var c = d.counts;
		var total = Math.max( 1, c.total );
		var pct = Math.round( ( c.done + c.error + c.skipped ) / total * 100 );
		var meter = el( '#pxat-progress-bar' );
		if ( meter ) {
			var fill = meter.querySelector( '.pxui-meter__fill' );
			if ( fill ) {
				fill.style.width = pct + '%';
			}
			var meterText = meter.querySelector( '.pxui-meter__text' );
			if ( meterText ) {
				meterText.textContent = pct + '%';
			}
			meter.setAttribute( 'aria-valuenow', String( pct ) );
		}
		setText( 'pxat-stat-done', new Intl.NumberFormat().format( c.done ) );
		setText( 'pxat-stat-error', new Intl.NumberFormat().format( c.error ) );
		setText( 'pxat-stat-skipped', new Intl.NumberFormat().format( c.skipped ) );
		setText( 'pxat-stat-cost', window.PXAT_Format.cost( c.cost_usd ) );
		setText( 'pxat-stat-tokens', window.PXAT_Format.unitLabel( c.prompt_tokens + c.completion_tokens ) );
		if ( typeof d.durationSeconds !== 'undefined' ) {
			setText( 'pxat-stat-time', window.PXAT_Format.duration( d.durationSeconds ) );
		}
	}

	function renderItems( items ) {
		( items || [] ).forEach( function ( item ) {
			var row = el( 'tr[data-item-id="' + item.id + '"]' );
			if ( ! row ) {
				return;
			}
			var map = { source: 'pxat-cell-source', dest: 'pxat-cell-dest', status: 'pxat-cell-status', note: 'pxat-cell-note', action: 'pxat-cell-action' };
			Object.keys( map ).forEach( function ( key ) {
				var cell = row.querySelector( '.' + map[ key ] );
				if ( cell && item.html && typeof item.html[ key ] !== 'undefined' ) {
					cell.innerHTML = item.html[ key ];
				}
			} );
			row.setAttribute( 'data-preview', JSON.stringify( { before: item.before, preview: item.preview, action: item.action } ) );
		} );
	}

	function renderLog( text ) {
		if ( typeof text !== 'string' ) {
			return;
		}
		var pre = el( '#pxat-log' );
		if ( ! pre || pre.textContent === text ) {
			return;
		}
		// Keep the view pinned to the newest line unless the user has
		// scrolled up to read something.
		var atBottom = pre.scrollHeight - pre.scrollTop - pre.clientHeight < 40;
		pre.textContent = text;
		if ( atBottom ) {
			pre.scrollTop = pre.scrollHeight;
		}
	}

	function render( d ) {
		renderCounts( d );
		renderItems( d.items );
		renderLog( d.log );
	}

	/* The run has no work left: the server-rendered heading ("Finished with
	   errors" / the auto-start) is now stale, so reload into the done screen. */
	function reloadIfComplete( d ) {
		var c = d && d.counts;
		if ( c && c.total > 0 && ! c.pending && ! c.translating ) {
			window.location.reload();
			return true;
		}
		return false;
	}

	/* --- The loop -------------------------------------------------- */

	function retire( reload ) {
		activeWorkers--;
		if ( activeWorkers > 0 ) {
			return;
		}
		if ( reload ) {
			window.location.reload();
			return;
		}
		onIdle();
	}

	/* Every worker has wound down without finishing the run: it is paused.
	   Restore the buttons and say so. */
	function onIdle() {
		if ( pollTimer ) {
			clearInterval( pollTimer );
			pollTimer = null;
		}
		var stopBtn = el( '#pxat-stop' );
		if ( stopBtn ) {
			stopBtn.disabled = false;
			stopBtn.textContent = __( 'Stop', 'perxel-ai-translate' );
		}
		var startBtn = el( '#pxat-start' );
		if ( startBtn ) {
			startBtn.textContent = __( 'Resume translating', 'perxel-ai-translate' );
		}
		toggleButtons( false );

		var failed = stalled;
		var expired = sessionExpired;
		stalled = false;
		sessionExpired = false;
		if ( expired ) {
			setRunMsg( __( 'Your session expired. Reload the page to keep translating.', 'perxel-ai-translate' ) );
		} else if ( failed ) {
			setRunMsg( __( 'The last request failed. Press Resume to try again.', 'perxel-ai-translate' ) );
		} else {
			setRunMsg( __( 'Stopped. Press Resume to translate the remaining posts.', 'perxel-ai-translate' ) );
		}

		// One last refresh so the figures match the final written post - and if
		// the run turns out to have no work left, reload into the done screen.
		post( 'pxat_status', {} ).then( function ( res ) {
			if ( res && res.success ) {
				render( res.data );
				reloadIfComplete( res.data );
			}
		} ).catch( function ( err ) {
			if ( isExpired( err ) ) {
				setRunMsg( __( 'Your session expired. Reload the page to keep translating.', 'perxel-ai-translate' ) );
			}
		} );
	}

	function worker() {
		if ( ! running ) {
			retire( finished );
			return;
		}
		post( 'pxat_process', {} ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				running = false;
				stalled = true;
				retire( false );
				return;
			}
			pollFails = 0;
			render( res.data );
			if ( res.data.done ) {
				running = false;
				finished = true;
				retire( true );
				return;
			}
			worker();
		} ).catch( function ( err ) {
			running = false;
			stalled = true;
			sessionExpired = isExpired( err );
			retire( false );
		} );
	}

	function start() {
		if ( running ) {
			return;
		}
		running = true;
		finished = false;
		stalled = false;
		sessionExpired = false;
		pollFails = 0;
		setRunMsg( '' );
		toggleButtons( true );
		var n = Math.max( 1, cfg.batched ? cfg.workerCount : 1 );
		for ( var i = 0; i < n; i++ ) {
			activeWorkers++;
			worker();
		}
		startPoll();
	}

	function stop() {
		if ( ! running ) {
			return;
		}
		running = false;
		var btn = el( '#pxat-stop' );
		if ( btn ) {
			btn.disabled = true;
			btn.textContent = __( 'Stopping…', 'perxel-ai-translate' );
		}
		// A translate request is likely in flight; it has to finish before the
		// worker checks `running` and winds down (then onIdle() takes over).
		setRunMsg( __( 'Finishing the current post, then stopping…', 'perxel-ai-translate' ) );
	}

	function toggleButtons( isRunning ) {
		var s = el( '#pxat-start' );
		var t = el( '#pxat-stop' );
		if ( s ) {
			s.hidden = isRunning;
		}
		if ( t ) {
			t.hidden = ! isRunning;
		}
	}

	function startPoll() {
		if ( pollTimer ) {
			return;
		}
		pollTimer = setInterval( function () {
			if ( ! running ) {
				clearInterval( pollTimer );
				pollTimer = null;
				return;
			}
			post( 'pxat_status', {} ).then( function ( res ) {
				if ( res && res.success ) {
					if ( pollFails ) {
						pollFails = 0;
						setRunMsg( '' );
					}
					render( res.data );
				}
			} ).catch( function ( err ) {
				pollFails++;
				if ( isExpired( err ) ) {
					setRunMsg( __( 'Your session expired. Reload the page to keep translating.', 'perxel-ai-translate' ) );
				} else if ( pollFails >= 3 ) {
					setRunMsg( __( 'Lost contact with the server - retrying. Check your connection.', 'perxel-ai-translate' ) );
				}
			} );
		}, 3000 );
	}

	/* --- Retry + View -------------------------------------------- */

	function onRetry( btn ) {
		var label = btn.textContent;
		var row = btn.closest( 'tr[data-item-id]' );
		var note = row ? row.querySelector( '.pxat-cell-note' ) : null;

		btn.disabled = true;
		btn.textContent = __( 'Retrying…', 'perxel-ai-translate' );
		if ( note ) {
			note.textContent = __( 'Retrying this post…', 'perxel-ai-translate' );
		}

		function restore( message ) {
			// The row was not re-rendered, so put the button and note back.
			btn.disabled = false;
			btn.textContent = label;
			if ( note && message ) {
				note.textContent = message;
			}
		}

		post( 'pxat_retry', { item_id: btn.getAttribute( 'data-item-id' ) } ).then( function ( res ) {
			if ( res && res.success ) {
				// render() replaces this row's cells - a fresh (enabled) Retry
				// button appears if it failed again, or none if it is done now.
				render( res.data );
				// If that cleared the last error, the "Finished with errors"
				// heading is now wrong - reload into the "Complete" screen.
				var c = res.data && res.data.counts;
				if ( c && ! c.error && ! c.pending && ! c.translating ) {
					window.location.reload();
				}
				return;
			}
			restore( ( res && res.data && res.data.message ) || __( 'The retry did not go through. Try again.', 'perxel-ai-translate' ) );
		} ).catch( function ( err ) {
			restore( isExpired( err )
				? __( 'Your session expired. Reload the page and try again.', 'perxel-ai-translate' )
				: __( 'The retry request failed. Check your connection and try again.', 'perxel-ai-translate' ) );
		} );
	}

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s == null ? '' : String( s );
		return d.innerHTML;
	}

	function openView( row ) {
		var data;
		try {
			data = JSON.parse( row.getAttribute( 'data-preview' ) || '{}' );
		} catch ( e ) {
			data = {};
		}
		var before = data.before || {};
		var preview = data.preview || {};
		var keys = Object.keys( Object.assign( {}, before, preview ) );
		var html = '';
		if ( ! keys.length ) {
			html = '<p>' + esc( ( window.wp && wp.i18n ) ? wp.i18n.__( 'Nothing to preview.', 'perxel-ai-translate' ) : 'Nothing to preview.' ) + '</p>';
		} else {
			html = '<table class="widefat striped"><thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead><tbody>';
			keys.forEach( function ( k ) {
				html += '<tr><td><code>' + esc( k ) + '</code></td><td>' + esc( before[ k ] || '' ) + '</td><td>' + esc( preview[ k ] || '' ) + '</td></tr>';
			} );
			html += '</tbody></table>';
		}
		var body = el( '#pxat-view-body' );
		var dlg = el( '#pxat-view-dialog' );
		if ( ! body || ! dlg ) {
			return;
		}
		body.innerHTML = html;
		if ( dlg.open ) {
			return;
		}
		if ( dlg.showModal ) {
			try {
				dlg.showModal();
			} catch ( e ) {
				dlg.setAttribute( 'open', '' );
			}
		} else {
			dlg.setAttribute( 'open', '' );
		}
	}

	document.addEventListener( 'click', function ( ev ) {
		var t = ev.target;
		if ( ! t.closest ) {
			return;
		}
		if ( t.id === 'pxat-start' ) {
			start();
		} else if ( t.id === 'pxat-stop' ) {
			stop();
		} else if ( t.id === 'pxat-view-close' ) {
			var dlg = el( '#pxat-view-dialog' );
			if ( dlg.close ) {
				dlg.close();
			} else {
				dlg.removeAttribute( 'open' );
			}
		} else if ( t.classList.contains( 'pxat-retry' ) ) {
			onRetry( t );
		} else if ( t.classList.contains( 'pxat-view' ) ) {
			var row = t.closest( 'tr[data-item-id]' );
			if ( row ) {
				openView( row );
			}
		}
	} );

	/* Auto-begin on landing: the cart's "Start" is the only click. The run
	   state lives in the DB, so a reopened unfinished run resumes the same way.
	   #pxat-start is only rendered while the run still has work to do. */
	if ( cfg.runId && el( '#pxat-start' ) ) {
		start();
	}
}() );
