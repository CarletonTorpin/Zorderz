/**
 * Commission widget — hydrates the dashboard skeleton from the REST routes.
 * Read-only. No pay path here; the server is the single source of truth. All
 * money figures are formatted from what the server returns, never recomputed.
 *
 * @package Zorderz\Commission
 */
( function () {
	'use strict';

	var cfg = window.zccCfg || {};

	function money( n ) {
		var v = Number( n || 0 );
		return '$' + v.toLocaleString( undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
	}

	function el( tag, cls, text ) {
		var e = document.createElement( tag );
		if ( cls ) { e.className = cls; }
		if ( text !== undefined ) { e.textContent = text; }
		return e;
	}

	function request( path, params ) {
		var url = cfg.restUrl + path;
		var qs = [];
		Object.keys( params || {} ).forEach( function ( k ) {
			qs.push( encodeURIComponent( k ) + '=' + encodeURIComponent( params[ k ] ) );
		} );
		if ( qs.length ) { url += '?' + qs.join( '&' ); }
		return fetch( url, {
			headers: { 'X-WP-Nonce': cfg.nonce || '' },
			credentials: 'same-origin'
		} ).then( function ( r ) { return r.json(); } );
	}

	function renderCommission( box, data ) {
		box.innerHTML = '';
		if ( data && data.denied ) {
			box.appendChild( el( 'p', 'zcc-note', data.message || 'Not available here.' ) );
			return;
		}
		if ( ! data || ! data.success || data.error ) {
			box.appendChild( el( 'p', 'zcc-note', ( data && ( data.error || data.message ) ) || 'Could not load.' ) );
			return;
		}
		if ( typeof data.amount === 'undefined' ) {
			box.appendChild( el( 'p', 'zcc-note', data.message || 'Figure not permitted for your role.' ) );
			return;
		}
		box.appendChild( el( 'div', 'zcc-figure', money( data.amount ) ) );
		if ( data.basis ) { box.appendChild( el( 'div', 'zcc-basis', data.basis ) ); }
	}

	function renderPay( box, data ) {
		box.innerHTML = '';
		var pc = data && data.paycheck;
		if ( ! pc ) {
			box.appendChild( el( 'p', 'zcc-note', 'No pay data.' ) );
			return;
		}
		box.appendChild( el( 'div', 'zcc-figure', money( pc.total_pay ) ) );
		box.appendChild( el( 'div', 'zcc-basis', ( pc.units_total || 0 ) + ' unit(s)' ) );
		// LOUD: never hide an unresolved (counted-but-unrated) line behind a $0.
		if ( pc.resolved === false && Array.isArray( pc.missing_rates ) && pc.missing_rates.length ) {
			var warn = el( 'div', 'zcc-warn', 'Unresolved — no rate configured for: ' + pc.missing_rates.join( ', ' ) + '. This paycheck is not final.' );
			box.appendChild( warn );
		}
	}

	function wire( widget ) {
		var btn = widget.querySelector( '.zcc-run' );
		var box = widget.querySelector( '.zcc-result' );
		var sel = widget.querySelector( '.zcc-period' );
		if ( ! btn || ! box ) { return; }
		btn.addEventListener( 'click', function () {
			var period = sel ? sel.value : 'this_month';
			box.textContent = 'Loading…';
			var isPiece = box.getAttribute( 'data-piece' ) === '1';
			if ( isPiece ) {
				request( 'pay', { period: period } ).then( function ( d ) { renderPay( box, d ); } ).catch( function () { box.textContent = 'Error.'; } );
			} else {
				request( 'calculate', { subject: 'me', period: period } ).then( function ( d ) { renderCommission( box, d ); } ).catch( function () { box.textContent = 'Error.'; } );
			}
		} );
	}

	function init() {
		var widgets = document.querySelectorAll( '.zcc-widget' );
		Array.prototype.forEach.call( widgets, wire );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
