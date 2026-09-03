/**
 * Translation-run screen: a browser-driven loop that asks the server to
 * translate + write the next post(s), until the run is done. State lives in the
 * database, so closing the tab just pauses; reopening resumes.
 */
( function () {
	'use strict';

	var cfg = window.PXAT_Progress || {};
	var running = false;
	var activeWorkers = 0;
	var pollTimer = null;

	function el( sel, ctx ) {
		return ( ctx || document ).querySelector( sel );
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
			return r.json();
		} );
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
		var fill = el( '#pxat-progress-bar .pxui-progress__fill' );
		if ( fill ) {
			fill.style.width = pct + '%';
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

	function render( d ) {
		renderCounts( d );
		renderItems( d.items );
	}

	/* --- The loop -------------------------------------------------- */

	function worker() {
		if ( ! running ) {
			activeWorkers--;
			return;
		}
		post( 'pxat_process', {} ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				running = false;
				activeWorkers--;
				return;
			}
			render( res.data );
			if ( res.data.done ) {
				running = false;
				activeWorkers--;
				if ( activeWorkers <= 0 ) {
					window.location.reload();
				}
				return;
			}
			worker();
		} ).catch( function () {
			running = false;
			activeWorkers--;
		} );
	}

	function start() {
		if ( running ) {
			return;
		}
		running = true;
		toggleButtons( true );
		var n = Math.max( 1, cfg.batched ? cfg.workerCount : 1 );
		for ( var i = 0; i < n; i++ ) {
			activeWorkers++;
			worker();
		}
		startPoll();
	}

	function stop() {
		running = false;
		toggleButtons( false );
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
					render( res.data );
				}
			} );
		}, 3000 );
	}

	/* --- Retry + View -------------------------------------------- */

	function onRetry( btn ) {
		btn.disabled = true;
		post( 'pxat_retry', { item_id: btn.getAttribute( 'data-item-id' ) } ).then( function ( res ) {
			if ( res && res.success ) {
				render( res.data );
			}
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
		el( '#pxat-view-body' ).innerHTML = html;
		var dlg = el( '#pxat-view-dialog' );
		if ( dlg.showModal ) {
			dlg.showModal();
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
}() );
