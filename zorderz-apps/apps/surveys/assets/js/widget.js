/**
 * Zorderz Surveys — dashboard widget.
 *
 * Reads stats + recent follow-ups, and drives the sync / check-reviews actions. All
 * business decisions (status guard, review routing, safety floor) are server-side;
 * this file is presentation + transport only.
 */
( function () {
	'use strict';

	var cfg = window.zsvWidget || {};
	var root = document.getElementById( 'zsv-widget' );
	if ( ! root || ! cfg.ajaxurl ) {
		return;
	}

	function post( action, data ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce || '' );
		Object.keys( data || {} ).forEach( function ( k ) {
			var v = data[ k ];
			if ( Array.isArray( v ) ) {
				v.forEach( function ( item ) { body.append( k + '[]', item ); } );
			} else {
				body.append( k, v );
			}
		} );
		return fetch( cfg.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( r ) { return r.json(); } );
	}

	function setText( id, val ) {
		var el = document.getElementById( id );
		if ( el ) { el.textContent = String( val ); }
	}

	function loadStats() {
		post( 'zsv_stats', {} ).then( function ( res ) {
			if ( ! res || ! res.success ) { return; }
			var d = res.data || {};
			setText( 'zsv-st-batches', d.batches != null ? d.batches : 0 );
			setText( 'zsv-st-leads', d.leads != null ? d.leads : 0 );
			setText( 'zsv-st-invited', d.invited != null ? d.invited : 0 );
			setText( 'zsv-st-reviews', d.reviews != null ? d.reviews : 0 );
		} ).catch( function () {} );
	}

	function statusChip( status ) {
		var label = status ? status.replace( /_/g, ' ' ) : 'not contacted';
		return '<span class="zsv-chip zsv-chip-' + ( status || 'none' ) + '">' + escapeHtml( label ) + '</span>';
	}

	function escapeHtml( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function loadRecent() {
		var list = document.getElementById( 'zsv-list' );
		if ( ! list ) { return; }
		post( 'zsv_recent', {} ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				list.innerHTML = '<div class="zsv-empty">' + escapeHtml( 'Could not load follow-ups.' ) + '</div>';
				return;
			}
			var rows = res.data || [];
			if ( ! rows.length ) {
				list.innerHTML = '<div class="zsv-empty">' + escapeHtml( 'No follow-ups yet.' ) + '</div>';
				return;
			}
			list.innerHTML = rows.map( function ( r ) {
				var meta = [ r.city, r.reviewed ? 'reviewed' : ( r.invited ? 'invited' : '' ) ].filter( Boolean ).join( ' · ' );
				return '<div class="zsv-row" data-id="' + r.id + '">' +
					'<div class="zsv-row-main"><span class="zsv-row-name">' + escapeHtml( r.name ) + '</span>' + statusChip( r.status ) + '</div>' +
					'<div class="zsv-row-meta">' + escapeHtml( meta ) + '</div></div>';
			} ).join( '' );
		} ).catch( function () {
			list.innerHTML = '<div class="zsv-empty">' + escapeHtml( 'Could not load follow-ups.' ) + '</div>';
		} );
	}

	function busy( btn, on ) {
		if ( ! btn ) { return; }
		btn.disabled = !! on;
		btn.dataset.label = btn.dataset.label || btn.textContent;
		btn.textContent = on ? 'Working…' : btn.dataset.label;
	}

	var batchBtn = document.getElementById( 'zsv-run-batch' );
	if ( batchBtn ) {
		batchBtn.addEventListener( 'click', function () {
			busy( batchBtn, true );
			post( 'zsv_run_batch', {} ).then( function () {
				busy( batchBtn, false );
				loadStats();
				loadRecent();
			} ).catch( function () { busy( batchBtn, false ); } );
		} );
	}

	var syncBtn = document.getElementById( 'zsv-sync' );
	if ( syncBtn ) {
		syncBtn.addEventListener( 'click', function () {
			busy( syncBtn, true );
			post( 'zsv_sync', {} ).then( function () {
				busy( syncBtn, false );
				loadRecent();
				loadStats();
			} ).catch( function () { busy( syncBtn, false ); } );
		} );
	}

	var revBtn = document.getElementById( 'zsv-check-reviews' );
	if ( revBtn ) {
		revBtn.addEventListener( 'click', function () {
			busy( revBtn, true );
			post( 'zsv_check_reviews', {} ).then( function () {
				busy( revBtn, false );
				loadStats();
				loadRecent();
			} ).catch( function () { busy( revBtn, false ); } );
		} );
	}

	loadStats();
	loadRecent();
} )();
