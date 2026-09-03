/**
 * Dashboard post picker: search one translatable post type, click results to
 * add them to the selection, then Continue to the Confirm screen.
 */
( function () {
	'use strict';

	var cfg = window.PXAT_Dashboard || {};
	var __ = ( window.wp && wp.i18n ) ? wp.i18n.__ : function ( s ) {
		return s;
	};

	var form = document.getElementById( 'pxat-picker' );
	if ( ! form ) {
		return;
	}

	var typeSel = document.getElementById( 'pxat-picker-type' );
	var search = document.getElementById( 'pxat-picker-search' );
	var results = document.getElementById( 'pxat-picker-results' );
	var selectedLabel = document.getElementById( 'pxat-picker-selected' );
	var selected = {};
	var timer = null;

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s == null ? '' : String( s );
		return d.innerHTML;
	}

	function renderSelected() {
		form.querySelectorAll( 'input[name="post_ids[]"]' ).forEach( function ( n ) {
			n.remove();
		} );
		var ids = Object.keys( selected );
		ids.forEach( function ( id ) {
			var i = document.createElement( 'input' );
			i.type = 'hidden';
			i.name = 'post_ids[]';
			i.value = id;
			form.appendChild( i );
		} );
		if ( selectedLabel ) {
			selectedLabel.innerHTML = ids.length
				? ids.map( function ( id ) {
					return '<button type="button" class="pxat-chip" data-remove="' + esc( id ) + '">' + esc( selected[ id ] ) + ' &times;</button>';
				} ).join( ' ' )
				: '';
		}
	}

	function runSearch() {
		var q = search.value.trim();
		if ( q.length < 2 ) {
			results.innerHTML = '';
			return;
		}
		var body = new URLSearchParams();
		body.set( 'action', 'pxat_post_search' );
		body.set( 'nonce', cfg.nonce );
		body.set( 'post_type', typeSel.value );
		body.set( 'search', q );

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( r ) {
			return r.json();
		} ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				results.innerHTML = '';
				return;
			}
			var list = res.data.results || [];
			results.innerHTML = list.length
				? list.map( function ( p ) {
					return '<button type="button" class="pxat-chip" data-add="' + esc( p.id ) + '" data-title="' + esc( p.title ) + '">'
						+ esc( p.title ) + ' <span class="pxat-muted">#' + esc( p.id ) + '</span></button>';
				} ).join( ' ' )
				: '<span class="pxat-muted">' + esc( __( 'No matches.', 'perxel-ai-translate' ) ) + '</span>';
		} );
	}

	search.addEventListener( 'input', function () {
		clearTimeout( timer );
		timer = setTimeout( runSearch, 300 );
	} );

	typeSel.addEventListener( 'change', function () {
		selected = {};
		results.innerHTML = '';
		renderSelected();
	} );

	document.addEventListener( 'click', function ( ev ) {
		var t = ev.target.closest( '[data-add], [data-remove]' );
		if ( ! t ) {
			return;
		}
		ev.preventDefault();
		if ( t.hasAttribute( 'data-add' ) ) {
			selected[ t.getAttribute( 'data-add' ) ] = t.getAttribute( 'data-title' );
		} else {
			delete selected[ t.getAttribute( 'data-remove' ) ];
		}
		renderSelected();
	} );
}() );
