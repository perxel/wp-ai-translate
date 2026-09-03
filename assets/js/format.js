/**
 * Mirror of PHP Perxel\AITranslate\Format for the progress screen's live
 * counters. Kept tiny and dependency-free.
 */
( function () {
	'use strict';

	var WORDS_PER_TOKEN = 0.75;

	function displayUnit() {
		return ( window.PXAT_Progress && window.PXAT_Progress.displayUnit ) || 'tokens';
	}

	function numberFormat( n ) {
		return String( Math.round( n ) ).replace( /\B(?=(\d{3})+(?!\d))/g, ',' );
	}

	window.PXAT_Format = {
		unitLabel: function ( tokens ) {
			tokens = tokens || 0;
			if ( 'words' === displayUnit() ) {
				return '~' + numberFormat( tokens * WORDS_PER_TOKEN ) + ' words';
			}
			return numberFormat( tokens ) + ' tokens';
		},
		cost: function ( usd ) {
			usd = parseFloat( usd ) || 0;
			if ( usd > 0 && usd < 0.01 ) {
				return '~$' + usd.toFixed( 4 );
			}
			return '~$' + usd.toFixed( 2 );
		},
		duration: function ( seconds ) {
			seconds = Math.max( 0, Math.floor( seconds || 0 ) );
			var h = Math.floor( seconds / 3600 );
			var m = Math.floor( ( seconds % 3600 ) / 60 );
			var s = seconds % 60;
			function pad( x ) {
				return x < 10 ? '0' + x : String( x );
			}
			return h > 0 ? h + ':' + pad( m ) + ':' + pad( s ) : pad( m ) + ':' + pad( s );
		}
	};
}() );
