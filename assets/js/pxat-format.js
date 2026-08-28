( function ( global ) {
	'use strict';

	// Mirrors PXAT_Format on the PHP side. Kept in sync manually since PHP
	// and JS can't share one implementation.
	var WORDS_PER_TOKEN = 0.75;

	var i18n = global.wp && global.wp.i18n ? global.wp.i18n : null;

	function __( text ) {
		return i18n ? i18n.__( text, 'perxel-ai-translate' ) : text;
	}

	function sprintf( format, value ) {
		return i18n ? i18n.sprintf( format, value ) : format.replace( '%s', value );
	}

	function formatNumber( n ) {
		return Math.round( n ).toLocaleString( 'en-US' );
	}

	function tokensToWords( tokens ) {
		return Math.round( tokens * WORDS_PER_TOKEN );
	}

	// unit: 'tokens' | 'words'. Mirrors PXAT_Format::unit_label().
	function unitLabel( tokens, unit ) {
		if ( 'words' === unit ) {
			return sprintf( __( '~%s words' ), formatNumber( tokensToWords( tokens ) ) );
		}
		return sprintf( __( '%s tokens' ), formatNumber( tokens ) );
	}

	// Mirrors PXAT_Format::cost() — a rough USD estimate.
	function cost( costUsd ) {
		costUsd = Number( costUsd ) || 0;
		if ( costUsd > 0 && costUsd < 0.01 ) {
			return '~$' + costUsd.toFixed( 4 );
		}
		return '~$' + costUsd.toFixed( 2 );
	}

	function pad2( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}

	// Mirrors PXAT_Format::duration().
	function duration( seconds ) {
		seconds = Math.max( 0, Math.floor( seconds ) );
		var hours = Math.floor( seconds / 3600 );
		var minutes = Math.floor( ( seconds % 3600 ) / 60 );
		var secs = seconds % 60;

		if ( hours > 0 ) {
			return hours + ':' + pad2( minutes ) + ':' + pad2( secs );
		}
		return pad2( minutes ) + ':' + pad2( secs );
	}

	global.PXATFormat = {
		formatNumber: formatNumber,
		unitLabel: unitLabel,
		cost: cost,
		duration: duration
	};
} )( window );
