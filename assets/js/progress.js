/**
 * Translation-run screen.
 *
 * A run is only ever started by the "Translate and apply" button on the Confirm
 * screen, which lands here with ?pxat_autostart=1. That is the single trigger -
 * this script consumes the flag (stripping it from the URL) and starts the loop
 * once. Every later load of this URL - a reload, a bookmark, the toolbar link -
 * has no flag and is view-only: it shows the run's state and a manual Resume /
 * Retry, and never spends anything on its own.
 *
 * The loop asks the server to translate + write the next post(s) until done. Run
 * state lives in the database; the server resolves a single `phase` (running |
 * blocked | complete | idle) and this script is a thin driver for it. It never
 * reloads the page except once on genuine completion (a terminal state).
 */
( function () {
	'use strict';

	var cfg = window.PXAT_Progress || {};

	var phase = cfg.phase || 'idle'; // last phase the server reported
	var running = false; // the pump loop is active
	var stopping = false; // user pressed Stop; workers wind down after the call in flight
	var activeWorkers = 0;
	var stalled = false; // last request failed
	var sessionExpired = false;
	var lostContact = 0;
	var pollTimer = null;

	function el( sel, ctx ) {
		return ( ctx || document ).querySelector( sel );
	}

	// wp.i18n.__, or a passthrough until it loads. Pass the text domain at every
	// call site so `wp i18n make-pot` can find the strings.
	var __ = ( window.wp && window.wp.i18n )
		? window.wp.i18n.__
		: function ( s ) {
			return s;
		};

	/* A one-line status next to the Start/Stop buttons so every state change
	   produces visible feedback. */
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
			// A 401/403 is almost always an expired nonce - the tab has been
			// open past the nonce lifetime.
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

	/* --- Rendering ----------------------------------------------- */

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
		var atBottom = pre.scrollHeight - pre.scrollTop - pre.clientHeight < 40;
		pre.textContent = text;
		if ( atBottom ) {
			pre.scrollTop = pre.scrollHeight;
		}
	}

	function render( d ) {
		if ( ! d ) {
			return;
		}
		renderCounts( d );
		renderItems( d.items );
		renderLog( d.log );
		if ( typeof d.phase === 'string' ) {
			phase = d.phase;
			if ( ! running ) {
				paintIdleUi();
			}
		}
	}

	/* --- Buttons ------------------------------------------------- */

	function show( node, visible ) {
		if ( node ) {
			node.hidden = ! visible;
		}
	}

	function paintRunningUi() {
		var start = el( '#pxat-start' );
		var stop = el( '#pxat-stop' );
		show( start, false );
		if ( stop ) {
			stop.hidden = false;
			stop.disabled = false;
			stop.textContent = __( 'Stop', 'perxel-ai-translate' );
		}
	}

	/* The loop is not running: show the right affordance for the current phase
	   and a one-line reason. */
	function paintIdleUi() {
		var start = el( '#pxat-start' );
		var stop = el( '#pxat-stop' );
		show( stop, false );

		if ( 'complete' === phase ) {
			show( start, false );
			return;
		}

		if ( start ) {
			start.hidden = false;
			start.textContent = hasProgress()
				? __( 'Resume translating', 'perxel-ai-translate' )
				: __( 'Start translating', 'perxel-ai-translate' );
		}

		if ( sessionExpired ) {
			setRunMsg( __( 'Your session expired. Reload the page to keep translating.', 'perxel-ai-translate' ) );
		} else if ( stalled ) {
			setRunMsg( __( 'The last request failed. Press Resume to try again.', 'perxel-ai-translate' ) );
		} else if ( 'blocked' === phase ) {
			setRunMsg( __( 'A post was interrupted. Press Resume to pick it back up.', 'perxel-ai-translate' ) );
		} else if ( stopping ) {
			setRunMsg( __( 'Stopped. Press Resume to translate the remaining posts.', 'perxel-ai-translate' ) );
		} else if ( 'running' === phase ) {
			setRunMsg( __( 'This run has posts left. Press Resume to continue.', 'perxel-ai-translate' ) );
		}
	}

	function hasProgress() {
		var done = el( '#pxat-stat-done' );
		return !! ( done && parseInt( done.textContent, 10 ) > 0 );
	}

	/* --- The loop ---------------------------------------------------- */

	/* The Resume button. Clears the failure flags, then goes through doResume()
	   so any stuck row is requeued before the loop picks back up. */
	function beginOrResume() {
		stalled = false;
		sessionExpired = false;
		if ( 'complete' === phase ) {
			return;
		}
		doResume();
	}

	function startLoop() {
		if ( running ) {
			return;
		}
		running = true;
		stopping = false;
		stalled = false;
		sessionExpired = false;
		setRunMsg( '' );
		paintRunningUi();
		startPoll();

		var n = Math.max( 1, cfg.batched ? ( cfg.workerCount || 1 ) : 1 );
		for ( var i = 0; i < n; i++ ) {
			activeWorkers++;
			pump();
		}
	}

	function pump() {
		if ( stopping ) {
			retire();
			return;
		}
		post( 'pxat_process', {} ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				stalled = true;
				retire();
				return;
			}
			lostContact = 0;
			stalled = false;
			render( res.data );

			var didWork = !! ( res.data.items && res.data.items.length );

			// This worker keeps pumping only while it is actually getting work
			// and the run is still going. Otherwise it retires - the other
			// workers carry on, and settle() decides (finish / recover / pause)
			// once they have all wound down. It is never this loop's job to
			// hammer an idle endpoint.
			if ( didWork && 'running' === res.data.phase && ! stopping ) {
				pump();
				return;
			}
			retire();
		} ).catch( function ( err ) {
			sessionExpired = isExpired( err );
			stalled = ! sessionExpired;
			retire();
		} );
	}

	function retire() {
		activeWorkers = Math.max( 0, activeWorkers - 1 );
		if ( activeWorkers > 0 ) {
			return;
		}
		running = false;
		settle();
	}

	/* Every worker has wound down. Either the run is complete, or it stopped for
	   a reason (Stop, a failed request, a stuck row, another tab holding the
	   work) and now waits for a manual Resume. Nothing auto-restarts here. */
	function settle() {
		stopPoll();
		if ( 'complete' === phase ) {
			finishToDone();
			return;
		}
		paintIdleUi();
		refreshOnce();
	}

	/* The Resume button: ask the server to requeue an interrupted post (safe now
	   that every worker has wound down), then pick the loop back up. */
	function doResume() {
		startPoll();
		setRunMsg( __( 'Recovering an interrupted post…', 'perxel-ai-translate' ) );
		post( 'pxat_resume', {} ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				paintIdleUi();
				return;
			}
			render( res.data );
			if ( 'complete' === res.data.phase ) {
				finishToDone();
				return;
			}
			if ( res.data.claimable > 0 ) {
				startLoop();
				return;
			}
			// Nothing to pick up yet - a request is still clearing. Leave the
			// Resume button so the user can try again in a moment.
			stopPoll();
			paintIdleUi();
			setRunMsg( __( 'The interrupted post is still clearing. Press Resume again in a moment.', 'perxel-ai-translate' ) );
		} ).catch( function ( err ) {
			sessionExpired = isExpired( err );
			stalled = ! sessionExpired;
			paintIdleUi();
		} );
	}

	/* Genuine completion is terminal. Reload once so the server renders the
	   proper done screen; after that cfg.phase is 'complete' and nothing here
	   starts again, so it cannot loop. */
	function finishToDone() {
		stopPoll();
		if ( 'complete' !== cfg.phase ) {
			window.location.reload();
		}
	}

	function refreshOnce() {
		post( 'pxat_status', {} ).then( function ( res ) {
			if ( res && res.success ) {
				render( res.data );
				if ( 'complete' === res.data.phase ) {
					finishToDone();
				}
			}
		} ).catch( function ( err ) {
			if ( isExpired( err ) ) {
				sessionExpired = true;
				paintIdleUi();
			}
		} );
	}

	function stop() {
		if ( ! running ) {
			return;
		}
		stopping = true;
		running = false;
		var btn = el( '#pxat-stop' );
		if ( btn ) {
			btn.disabled = true;
			btn.textContent = __( 'Stopping…', 'perxel-ai-translate' );
		}
		setRunMsg( __( 'Finishing the current post, then stopping…', 'perxel-ai-translate' ) );
	}

	/* --- Polling ------------------------------------------------- */

	function startPoll() {
		if ( pollTimer ) {
			return;
		}
		pollTimer = setInterval( function () {
			post( 'pxat_status', {} ).then( function ( res ) {
				if ( ! res || ! res.success ) {
					return;
				}
				if ( lostContact ) {
					lostContact = 0;
					setRunMsg( '' );
				}
				render( res.data );
				if ( 'complete' === res.data.phase ) {
					finishToDone();
				}
			} ).catch( function ( err ) {
				lostContact++;
				if ( isExpired( err ) ) {
					sessionExpired = true;
					stopPoll();
					if ( ! running ) {
						paintIdleUi();
					}
				} else if ( lostContact >= 3 ) {
					setRunMsg( __( 'Lost contact with the server - retrying. Check your connection.', 'perxel-ai-translate' ) );
				}
			} );
		}, 3000 );
	}

	function stopPoll() {
		if ( pollTimer ) {
			clearInterval( pollTimer );
			pollTimer = null;
		}
	}

	/* --- Retry + View ------------------------------------------- */

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
			btn.disabled = false;
			btn.textContent = label;
			if ( note && message ) {
				note.textContent = message;
			}
		}

		post( 'pxat_retry', { item_id: btn.getAttribute( 'data-item-id' ) } ).then( function ( res ) {
			if ( res && res.success ) {
				render( res.data );
				if ( 'complete' === res.data.phase ) {
					finishToDone();
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
			beginOrResume();
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

	/* Strip pxat_autostart from the address bar so a later reload of this URL is
	   view-only. */
	function consumeAutostartFlag() {
		if ( ! window.history || ! window.history.replaceState ) {
			return;
		}
		var q = window.location.search
			.replace( /^\?/, '' )
			.split( '&' )
			.filter( function ( p ) {
				return p && 'pxat_autostart' !== p.split( '=' )[ 0 ];
			} )
			.join( '&' );
		try {
			window.history.replaceState( {}, document.title, window.location.pathname + ( q ? '?' + q : '' ) );
		} catch ( e ) {}
	}

	/* On landing: always show the run's current state (a leading status refresh,
	   so the screen is not stuck on the server snapshot). Start the loop only
	   when this load carried the one-shot flag from the Confirm screen's
	   "Translate and apply" - never on a plain reload. */
	if ( cfg.runId && 'complete' !== cfg.phase ) {
		post( 'pxat_status', {} ).then( function ( res ) {
			var d = res && res.success ? res.data : null;
			if ( d ) {
				render( d );
			}
			if ( cfg.autostart ) {
				consumeAutostartFlag();
				beginRun( d ? d.phase : cfg.phase );
			} else {
				// View-only: keep the screen current (another session may be
				// running this run) without touching it.
				startPoll();
			}
		} ).catch( function () {
			if ( cfg.autostart ) {
				consumeAutostartFlag();
				beginRun( cfg.phase );
			}
		} );
	}

	function beginRun( p ) {
		if ( ! el( '#pxat-start' ) ) {
			return;
		}
		if ( 'blocked' === p ) {
			doResume();
		} else if ( 'complete' !== p ) {
			startLoop();
		}
	}
}() );
