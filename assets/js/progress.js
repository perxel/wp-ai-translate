( function () {
	'use strict';

	var i18n = window.wp && window.wp.i18n ? window.wp.i18n : null;
	function __( text ) {
		return i18n ? i18n.__( text, 'perxel-ai-translate' ) : text;
	}
	function sprintf() {
		var args = Array.prototype.slice.call( arguments );
		if ( i18n ) {
			return i18n.sprintf.apply( i18n, args );
		}
		var i = 1;
		return args[ 0 ].replace( /%[sd]/g, function () {
			return args[ i++ ];
		} );
	}

	var currentTextEl = document.getElementById( 'pxat-current-text' );
	var currentSpinnerEl = document.getElementById( 'pxat-current-spinner' );
	var logEl = document.getElementById( 'pxat-log' );
	var jobsTable = document.getElementById( 'pxat-jobs-table' );
	var applyAllBtns = [ document.getElementById( 'pxat-apply-all-top' ), document.getElementById( 'pxat-apply-all-bottom' ) ].filter( Boolean );
	var applyAllSpinners = [ document.getElementById( 'pxat-apply-all-top-spinner' ), document.getElementById( 'pxat-apply-all-bottom-spinner' ) ].filter( Boolean );
	var startBtn = document.getElementById( 'pxat-start-btn' );
	var stopBtn = document.getElementById( 'pxat-stop-btn' );
	var running = false;

	// The processing AJAX request blocks for the full duration of one
	// OpenRouter call (up to 90s), so a separate, fast poll runs alongside
	// it purely to show which job is running right now, otherwise the page
	// looks frozen for the whole time.
	var pollTimer = null;
	var tickTimer = null;
	var activeJob = null;
	var pendingApplyIds = [];

	// "Auto (batched)" mode runs PXAT_Progress.workerCount parallel
	// processNext() loops against the same batch (see startWorkers() below);
	// every other mode stays at exactly 1. activeWorkers is how many of those
	// loops are still going — the run is only truly over once every worker
	// independently found nothing left to claim (see workerFinished()).
	var activeWorkers = 0;

	// Full job records (fields, preview, before, action, ...), seeded from
	// the server-rendered snapshot and kept current as poll()/processNext()/
	// applyJob() responses arrive — the Preview dialog reads straight out of
	// this, no separate AJAX call needed.
	var jobsById = {};
	( PXAT_Progress.jobs || [] ).forEach( function ( job ) {
		jobsById[ job.id ] = job;
	} );

	var statusLabels = {
		pending: __( 'Pending' ),
		processing: __( 'Processing' ),
		success: __( 'Translated' ),
		error: __( 'Error' ),
		skipped: __( 'Skipped' )
	};

	// Mirrors PXAT_Job_Processor::type_label().
	var TYPE_LABELS = {
		title: __( 'Title / Slug' ),
		content: __( 'Content' ),
		acf: 'ACF',
		rankmath: 'Rank Math',
		taxonomy: 'Taxonomy',
		thumbnail: __( 'Featured image' )
	};

	var seenLogCounts = {};
	document.querySelectorAll( '[data-job-id]' ).forEach( function ( row ) {
		seenLogCounts[ row.getAttribute( 'data-job-id' ) ] = parseInt( row.getAttribute( 'data-log-count' ) || '0', 10 );
	} );

	function jobLabel( job ) {
		return 'Post #' + job.source_post_id;
	}

	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = str || '';
		return div.innerHTML;
	}

	// Mirrors PXAT_Progress_Page::render_post_cell().
	function renderPostCell( post ) {
		if ( ! post ) {
			return '&mdash;';
		}

		var title = post.title ? post.title : sprintf( __( '(#%d, no title)' ), post.id );
		var html = post.edit_url
			? '<a href="' + escapeHtml( post.edit_url ) + '" target="_blank" rel="noopener">' + escapeHtml( title ) + '</a>'
			: escapeHtml( title );

		return html + ' <span class="description">(' + escapeHtml( post.status ) + ')</span>';
	}

	// Mirrors PXAT_Progress_Page::render_type_results() — keep both in sync.
	function renderTypeResults( results ) {
		var items = [];
		Object.keys( results ).forEach( function ( type ) {
			var result = results[ type ];
			var label = escapeHtml( TYPE_LABELS[ type ] || type );
			if ( ! result.success ) {
				items.push( '<span class="pxat-inline-error" title="' + escapeHtml( result.message || '' ) + '">' + label + ' ✗</span>' );
			} else if ( result.message ) {
				items.push( '<span class="pxat-inline-warning" title="' + escapeHtml( result.message ) + '">' + label + ' ⚠</span>' );
			} else {
				items.push( '<span class="pxat-inline-ok">' + label + ' ✓</span>' );
			}
		} );
		return items.join( ' ' );
	}

	// Mirrors PXAT_Progress_Page::render_action_cell() — keep both in sync.
	function renderActionCell( job ) {
		if ( 'error' === job.status ) {
			return '<button type="button" class="button button-small pxat-retry-btn" data-job-id="' + escapeHtml( job.id ) + '">' + escapeHtml( __( 'Retry' ) ) + '</button>';
		}

		if ( 'success' !== job.status ) {
			return '&mdash;';
		}

		var html = '';

		if ( job.apply_error ) {
			html += '<span class="pxat-inline-error">' + escapeHtml( job.apply_error ) + '</span> ';
		}

		if ( job.results && Object.keys( job.results ).length ) {
			html += '<div class="pxat-type-results">' + renderTypeResults( job.results ) + '</div>';
		}

		html += '<button type="button" class="button button-small pxat-preview-btn" data-job-id="' + escapeHtml( job.id ) + '">' + escapeHtml( __( 'Preview' ) ) + '</button> ';

		if ( job.applied ) {
			html += '<span class="pxat-badge pxat-badge--applied">' + escapeHtml( __( 'Applied' ) ) + '</span>';
		} else {
			html += '<button type="button" class="button button-primary button-small pxat-apply-btn" data-job-id="' + escapeHtml( job.id ) + '">' + escapeHtml( __( 'Apply' ) ) + '</button>';
		}

		return html;
	}

	function previewTitle( job ) {
		var actionLabel = 'update' === job.action ? __( 'will overwrite the existing translation' ) : __( 'will create a new post' );
		return jobLabel( job ) + ' — ' + actionLabel;
	}

	function renderPreviewBody( job ) {
		if ( ! job || ! Array.isArray( job.fields ) || ! job.fields.length ) {
			return '<p>' + escapeHtml( __( 'No fields.' ) ) + '</p>';
		}

		var preview = job.preview || {};
		var before = job.before || {};
		var beforeLabel = 'update' === job.action ? __( 'Current' ) : __( 'Original' );
		var html = '';

		job.fields.forEach( function ( field ) {
			var after = undefined !== preview[ field.key ] ? preview[ field.key ] : '';
			html += '<div class="pxat-preview-field">';
			html += '<h4><code>' + escapeHtml( field.key ) + '</code></h4>';
			html += '<div class="pxat-preview-before"><strong>' + escapeHtml( beforeLabel ) + '</strong><pre>' + escapeHtml( before[ field.key ] || '' ) + '</pre></div>';
			html += '<div class="pxat-preview-after"><strong>' + escapeHtml( __( 'New translation' ) ) + '</strong><pre>' + escapeHtml( after ) + '</pre></div>';
			html += '</div>';
		} );

		return html;
	}

	function openPreviewDialog( jobId ) {
		var job = jobsById[ jobId ];
		if ( ! job ) {
			return;
		}

		var titleEl = document.getElementById( 'pxat-preview-title' );
		var bodyEl = document.getElementById( 'pxat-preview-body' );
		if ( titleEl ) {
			titleEl.textContent = previewTitle( job );
		}
		if ( bodyEl ) {
			bodyEl.innerHTML = renderPreviewBody( job );
		}

		var dialog = document.getElementById( 'pxat-preview-dialog' );
		if ( dialog && dialog.showModal ) {
			dialog.showModal();
		}
	}

	function summarizeActions( ids ) {
		var create = 0;
		var update = 0;

		ids.forEach( function ( id ) {
			var job = jobsById[ id ];
			if ( ! job ) {
				return;
			}
			if ( 'update' === job.action ) {
				update++;
			} else {
				create++;
			}
		} );

		var parts = [];
		if ( create ) {
			parts.push( sprintf( __( '%d new post(s)' ), create ) );
		}
		if ( update ) {
			parts.push( sprintf( __( '%d previously translated post(s) (overwrite)' ), update ) );
		}

		return parts.length ? sprintf( __( 'Will apply: %s.' ), parts.join( ', ' ) ) : '';
	}

	function eligibleJobIds() {
		return Object.keys( jobsById ).filter( function ( id ) {
			var job = jobsById[ id ];
			return job && 'success' === job.status && ! job.applied;
		} );
	}

	function openApplyDialog( ids ) {
		ids = ids.filter( function ( id ) {
			var job = jobsById[ id ];
			return job && 'success' === job.status && ! job.applied;
		} );

		if ( ! ids.length ) {
			return;
		}

		pendingApplyIds = ids;

		var summaryEl = document.getElementById( 'pxat-apply-summary' );
		if ( summaryEl ) {
			summaryEl.textContent = summarizeActions( ids );
		}

		var dialog = document.getElementById( 'pxat-apply-dialog' );
		if ( dialog && dialog.showModal ) {
			dialog.showModal();
		}
	}

	function appendLogEntries( job ) {
		if ( ! job || ! Array.isArray( job.log ) ) {
			return;
		}

		var seen = seenLogCounts[ job.id ] || 0;
		if ( job.log.length <= seen ) {
			return;
		}

		for ( var i = seen; i < job.log.length; i++ ) {
			var entry = job.log[ i ];
			var line = '[' + entry.at + '] ' + entry.message;
			if ( logEl ) {
				logEl.textContent += line + '\n';
				logEl.scrollTop = logEl.scrollHeight;
			}
		}

		seenLogCounts[ job.id ] = job.log.length;
	}

	function setCurrent( text, busy ) {
		if ( currentTextEl ) {
			currentTextEl.textContent = text || '';
		}
		if ( currentSpinnerEl ) {
			currentSpinnerEl.classList.toggle( 'is-active', !! busy );
		}
	}

	function setApplyAllBusy( busy ) {
		applyAllBtns.forEach( function ( btn ) {
			btn.disabled = busy;
		} );
		applyAllSpinners.forEach( function ( spinner ) {
			spinner.classList.toggle( 'is-active', busy );
		} );
	}

	function setRowBusy( jobId, label ) {
		var row = document.querySelector( '[data-job-id="' + jobId + '"]' );
		var actionCell = row ? row.querySelector( '.pxat-action' ) : null;
		if ( actionCell ) {
			actionCell.innerHTML = '<span class="spinner is-active"></span> ' + escapeHtml( label || __( 'Applying…' ) );
		}
	}

	function setDurationSeconds( seconds ) {
		if ( 'number' === typeof seconds ) {
			setText( 'pxat-stat-elapsed', PXATFormat.duration( seconds ) );
		}
	}

	function renderCurrent() {
		if ( ! activeJob ) {
			setCurrent( '', false );
			return;
		}
		var secs = Math.floor( ( Date.now() - activeJob.since ) / 1000 );
		// Batch mode: several jobs are "processing" simultaneously — one
		// shared OpenRouter request covers all of them — so the label names
		// the group instead of one post.
		var label = activeJob.ids.length > 1
			? sprintf( __( '%d posts' ), activeJob.ids.length )
			: ( jobsById[ activeJob.ids[ 0 ] ] ? jobLabel( jobsById[ activeJob.ids[ 0 ] ] ) : '' );
		setCurrent( sprintf( __( 'Processing: %1$s (%2$ds)' ), label, secs ), true );
	}

	function poll() {
		var body = new URLSearchParams();
		body.set( 'action', 'pxat_get_status' );
		body.set( 'nonce', PXAT_Progress.nonce );
		body.set( 'batch_id', PXAT_Progress.batchId );

		fetch( PXAT_Progress.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( res ) {
				if ( ! res.success ) {
					return;
				}

				res.data.jobs.forEach( function ( job ) {
					updateRow( job );
					appendLogEntries( job );
				} );
				updateCounts( res.data.counts );
				setDurationSeconds( res.data.durationSeconds );

				var stillActiveIds = res.data.jobs.filter( function ( job ) {
					return 'processing' === job.status;
				} ).map( function ( job ) {
					return job.id;
				} );

				if ( stillActiveIds.length ) {
					activeJob = { ids: stillActiveIds, since: activeJob ? activeJob.since : Date.now() };
				} else {
					activeJob = null;
				}

				renderCurrent();
			} )
			.catch( function () {} );
	}

	function startPolling() {
		poll();
		pollTimer = setInterval( poll, 1500 );
		tickTimer = setInterval( renderCurrent, 1000 );
	}

	function stopPolling() {
		clearInterval( pollTimer );
		clearInterval( tickTimer );
		pollTimer = null;
		tickTimer = null;
		activeJob = null;
	}

	function updateRow( job ) {
		jobsById[ job.id ] = job;

		var row = document.querySelector( '[data-job-id="' + job.id + '"]' );
		if ( ! row ) {
			return;
		}

		var sourceCell = row.querySelector( '.pxat-source-post' );
		if ( sourceCell && job.source_post ) {
			sourceCell.innerHTML = renderPostCell( job.source_post );
		}

		var destCell = row.querySelector( '.pxat-dest-post' );
		if ( destCell && job.dest_post ) {
			destCell.innerHTML = renderPostCell( job.dest_post );
		}

		var statusCell = row.querySelector( '.pxat-status' );
		var statusSpinner = 'processing' === job.status ? '<span class="spinner is-active"></span>' : '';
		statusCell.innerHTML = '<span class="pxat-badge pxat-badge--' + job.status + '">' + statusSpinner + escapeHtml( statusLabels[ job.status ] || job.status ) + '</span>';

		var msgCell = row.querySelector( '.pxat-message' );
		msgCell.textContent = job.error_message || '';

		var actionCell = row.querySelector( '.pxat-action' );
		if ( actionCell ) {
			actionCell.innerHTML = renderActionCell( job );
		}
	}

	function setText( id, value ) {
		var el = document.getElementById( id );
		if ( el ) {
			el.textContent = value;
		}
	}

	function setBarWidth( id, pct ) {
		var el = document.getElementById( id );
		if ( el ) {
			el.style.width = pct + '%';
		}
	}

	function updateCounts( counts ) {
		var translatedPct = counts.total > 0 ? Math.round( ( counts.success / counts.total ) * 100 ) : 0;
		var appliedPct = counts.total > 0 ? Math.round( ( counts.applied / counts.total ) * 100 ) : 0;

		setText( 'pxat-stat-translated-pct', translatedPct );
		setText( 'pxat-stat-translated-frac', counts.success + ' / ' + counts.total );
		setBarWidth( 'pxat-stat-translated-bar', translatedPct );

		setText( 'pxat-stat-applied-pct', appliedPct );
		setText( 'pxat-stat-applied-frac', counts.applied + ' / ' + counts.total );
		setBarWidth( 'pxat-stat-applied-bar', appliedPct );

		setText( 'pxat-stat-error', counts.error );
		setText( 'pxat-stat-skipped', counts.skipped );

		var warningsWrapEl = document.getElementById( 'pxat-stat-warnings-wrap' );
		if ( warningsWrapEl ) {
			warningsWrapEl.style.display = counts.warnings > 0 ? '' : 'none';
		}
		setText( 'pxat-stat-warnings', sprintf( __( '%d posts with warnings (data did not copy completely — see the Action column)' ), counts.warnings ) );

		var applyErrorsWrapEl = document.getElementById( 'pxat-stat-apply-errors-wrap' );
		if ( applyErrorsWrapEl ) {
			applyErrorsWrapEl.style.display = counts.apply_errors > 0 ? '' : 'none';
		}
		setText( 'pxat-stat-apply-errors', sprintf( __( '%d posts failed to apply (see the Action column)' ), counts.apply_errors ) );

		var tokens = ( counts.prompt_tokens || 0 ) + ( counts.completion_tokens || 0 );
		setText( 'pxat-stat-tokens', PXATFormat.unitLabel( tokens, PXAT_Progress.displayUnit ) );
		setText( 'pxat-stat-cost', PXATFormat.cost( counts.cost_usd || 0 ) );
	}

	function processNext() {
		if ( ! running ) {
			workerStopped();
			return;
		}

		var body = new URLSearchParams();
		body.set( 'action', 'pxat_process_job' );
		body.set( 'nonce', PXAT_Progress.nonce );
		body.set( 'batch_id', PXAT_Progress.batchId );

		fetch( PXAT_Progress.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( res ) {
				if ( ! res.success ) {
					running = false;
					stopPolling();
					showRunningUi( false );
					setCurrent( __( 'Translation error, see the browser console.' ), false );
					return;
				}

				if ( res.data.counts ) {
					updateCounts( res.data.counts );
				}
				setDurationSeconds( res.data.durationSeconds );

				if ( res.data.done ) {
					workerFinished();
					return;
				}

				if ( res.data.job ) {
					updateRow( res.data.job );
					appendLogEntries( res.data.job );
				}

				if ( Array.isArray( res.data.jobs ) ) {
					res.data.jobs.forEach( function ( job ) {
						updateRow( job );
						appendLogEntries( job );
					} );
				}

				processNext();
			} )
			.catch( function () {
				running = false;
				stopPolling();
				showRunningUi( false );
				setCurrent( __( 'Connection error, try reloading the page.' ), false );
			} );
	}

	function showRunningUi( isRunning ) {
		if ( startBtn ) {
			startBtn.style.display = isRunning ? 'none' : '';
		}
		if ( stopBtn ) {
			stopBtn.style.display = isRunning ? '' : 'none';
			stopBtn.disabled = false;
		}
	}

	function hideRunningUi() {
		if ( startBtn ) {
			startBtn.style.display = 'none';
		}
		if ( stopBtn ) {
			stopBtn.style.display = 'none';
		}
	}

	function workerStopped() {
		activeWorkers = Math.max( 0, activeWorkers - 1 );
		if ( activeWorkers > 0 ) {
			return;
		}
		stopPolling();
		showRunningUi( false );
		setCurrent( __( 'Stopped — click "Start translating" to continue.' ), false );
	}

	function startWorkers( count ) {
		running = true;
		activeWorkers = count;
		startPolling();
		for ( var i = 0; i < count; i++ ) {
			processNext();
		}
	}

	function workerFinished() {
		activeWorkers = Math.max( 0, activeWorkers - 1 );
		if ( activeWorkers > 0 ) {
			return;
		}
		running = false;
		stopPolling();
		hideRunningUi();
		setCurrent(
			PXAT_Progress.autoApply
				? __( 'Done — everything translated and applied.' )
				: __( 'Translation finished — preview and apply below.' ),
			false
		);
	}

	function applyJob( jobId ) {
		setRowBusy( jobId, __( 'Applying…' ) );

		var body = new URLSearchParams();
		body.set( 'action', 'pxat_apply_job' );
		body.set( 'nonce', PXAT_Progress.nonce );
		body.set( 'batch_id', PXAT_Progress.batchId );
		body.set( 'job_id', jobId );

		return fetch( PXAT_Progress.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( res ) {
				if ( ! res.success ) {
					if ( jobsById[ jobId ] ) {
						updateRow( jobsById[ jobId ] );
					}
					return;
				}
				if ( res.data.job ) {
					updateRow( res.data.job );
				}
				if ( res.data.counts ) {
					updateCounts( res.data.counts );
				}
				setDurationSeconds( res.data.durationSeconds );
			} )
			.catch( function () {
				if ( jobsById[ jobId ] ) {
					updateRow( jobsById[ jobId ] );
				}
			} );
	}

	function retryJob( jobId ) {
		setRowBusy( jobId, __( 'Retrying…' ) );

		var body = new URLSearchParams();
		body.set( 'action', 'pxat_retry_job' );
		body.set( 'nonce', PXAT_Progress.nonce );
		body.set( 'batch_id', PXAT_Progress.batchId );
		body.set( 'job_id', jobId );

		return fetch( PXAT_Progress.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( res ) {
				if ( ! res.success ) {
					if ( jobsById[ jobId ] ) {
						updateRow( jobsById[ jobId ] );
					}
					return;
				}
				if ( res.data.job ) {
					updateRow( res.data.job );
					appendLogEntries( res.data.job );
				}
				if ( res.data.counts ) {
					updateCounts( res.data.counts );
				}
				setDurationSeconds( res.data.durationSeconds );
			} )
			.catch( function () {
				if ( jobsById[ jobId ] ) {
					updateRow( jobsById[ jobId ] );
				}
			} );
	}

	function applyQueue( ids ) {
		if ( ! ids.length ) {
			return;
		}
		setApplyAllBusy( true );
		runApplyQueue( ids );
	}

	function runApplyQueue( ids ) {
		if ( ! ids.length ) {
			setApplyAllBusy( false );
			setCurrent( __( 'Done applying.' ), false );
			return;
		}
		var id = ids.shift();
		setCurrent( sprintf( __( 'Applying: %s' ), jobsById[ id ] ? jobLabel( jobsById[ id ] ) : id ), true );
		applyJob( id ).then( function () {
			runApplyQueue( ids );
		} );
	}

	if ( jobsTable ) {
		jobsTable.addEventListener( 'click', function ( e ) {
			var previewBtn = e.target.closest( '.pxat-preview-btn' );
			if ( previewBtn ) {
				openPreviewDialog( previewBtn.getAttribute( 'data-job-id' ) );
				return;
			}
			var applyBtn = e.target.closest( '.pxat-apply-btn' );
			if ( applyBtn ) {
				openApplyDialog( [ applyBtn.getAttribute( 'data-job-id' ) ] );
				return;
			}
			var retryBtn = e.target.closest( '.pxat-retry-btn' );
			if ( retryBtn ) {
				retryJob( retryBtn.getAttribute( 'data-job-id' ) );
			}
		} );
	}

	[ 'pxat-apply-all-top', 'pxat-apply-all-bottom' ].forEach( function ( id ) {
		var btn = document.getElementById( id );
		if ( btn ) {
			btn.addEventListener( 'click', function () {
				openApplyDialog( eligibleJobIds() );
			} );
		}
	} );

	var applyConfirmBtn = document.getElementById( 'pxat-apply-confirm' );
	if ( applyConfirmBtn ) {
		applyConfirmBtn.addEventListener( 'click', function () {
			var dialog = document.getElementById( 'pxat-apply-dialog' );
			if ( dialog ) {
				dialog.close();
			}
			var ids = pendingApplyIds.slice();
			pendingApplyIds = [];
			applyQueue( ids );
		} );
	}

	document.querySelectorAll( '[data-dialog-close]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var dialog = document.getElementById( btn.getAttribute( 'data-dialog-close' ) );
			if ( dialog ) {
				dialog.close();
			}
		} );
	} );

	// The loop is never auto-started — a reload always lands paused, and
	// only a Start click (first run or after Stop) resumes it against
	// whatever's still pending in the batch file.
	if ( startBtn ) {
		startBtn.addEventListener( 'click', function () {
			var workerCount = PXAT_Progress.batchMode ? ( PXAT_Progress.workerCount || 1 ) : 1;
			showRunningUi( true );
			startWorkers( workerCount );
		} );
	}

	if ( stopBtn ) {
		stopBtn.addEventListener( 'click', function () {
			running = false;
			stopBtn.disabled = true;
			setCurrent( __( 'Stopping… (waiting for the current job to finish)' ), true );
		} );
	}
} )();
