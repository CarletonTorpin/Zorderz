/**
 * Zorderz Surveys — dashboard widget.
 *
 * Reads stats + recent follow-ups, and drives the batch / sync / send / check-reviews actions.
 * All business decisions (status guard, review routing, safety floor) are server-side; this
 * file is presentation + transport only.
 *
 * It runs on the theme's kit of parts when the theme has it (window.ZDZ.ui): requests go
 * through ZDZ.ui.ajax, so a lost signal, a timeout or a server problem shows as a plain
 * sentence in the progress slot, and the buttons that send use ZDZ.ui.busy (one press at a
 * time). Without the kit it falls back to a plain fetch and its own busy state.
 *
 * Review e-mails go ONE customer per request, in order, with "Sending 2 of 6": a slow CRM on
 * one customer never stops the rest. One summary of the press is sent back for the log.
 */
( function () {
	'use strict';

	var cfg = window.zsvWidget || {};
	var root = document.getElementById( 'zsv-widget' );
	if ( ! root || ! cfg.ajaxurl ) {
		return;
	}
	var kit = ( window.ZDZ && window.ZDZ.ui && typeof window.ZDZ.ui.ajax === 'function' ) ? window.ZDZ.ui : null;

	/** Statuses whose customers may get the review e-mail (the server checks again). */
	var SENDABLE = { satisfied: 1, no_primary_account: 1 };

	function encode( data ) {
		var body = new URLSearchParams();
		Object.keys( data || {} ).forEach( function ( k ) {
			var v = data[ k ];
			if ( Array.isArray( v ) ) {
				v.forEach( function ( item ) { body.append( k + '[]', item ); } );
			} else {
				body.append( k, v );
			}
		} );
		return body;
	}

	/** One request; resolves with the `data` of {success:true,data}, rejects with a plain sentence. */
	function request( action, data ) {
		data = Object.assign( { nonce: cfg.nonce || '' }, data || {} );
		if ( kit ) {
			return kit.ajax( action, encode( data ), { timeout: 70000 } );
		}
		var body = encode( data );
		body.append( 'action', action );
		return fetch( cfg.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( r ) {
			return r.json().catch( function () { throw new Error( 'The server did not answer properly. Try again in a minute.' ); } );
		} ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				throw new Error( ( res && typeof res.data === 'string' ) ? res.data : 'That did not work.' );
			}
			return res.data;
		} );
	}

	function busy( btn, work ) {
		if ( kit && typeof kit.busy === 'function' ) {
			return kit.busy( btn, work );
		}
		if ( ! btn ) { return Promise.resolve( work() ); }
		if ( btn.disabled ) { return Promise.resolve(); }
		btn.disabled = true;
		btn.setAttribute( 'aria-busy', 'true' );
		var done = function () { btn.disabled = false; btn.removeAttribute( 'aria-busy' ); };
		return Promise.resolve().then( work ).then( function ( v ) { done(); return v; }, function ( e ) { done(); throw e; } );
	}

	var progress = document.getElementById( 'zsv-progress' );
	function say( text ) {
		if ( progress ) { progress.textContent = text || ''; }
	}
	function sayError( e ) {
		say( ( e && e.message ) ? e.message : 'That did not work. Try again in a minute.' );
	}

	function setText( id, val ) {
		var el = document.getElementById( id );
		if ( el ) { el.textContent = String( val ); }
	}

	function loadStats() {
		return request( 'zsv_stats', {} ).then( function ( d ) {
			d = d || {};
			setText( 'zsv-st-batches', d.batches != null ? d.batches : 0 );
			setText( 'zsv-st-leads', d.leads != null ? d.leads : 0 );
			setText( 'zsv-st-invited', d.invited != null ? d.invited : 0 );
			setText( 'zsv-st-reviews', d.reviews != null ? d.reviews : 0 );
		} ).catch( function () {} );
	}

	function statusChip( status ) {
		var label = status ? status.replace( /_/g, ' ' ) : 'not contacted';
		return '<span class="zsv-chip zsv-chip-' + escapeHtml( status || 'none' ) + '">' + escapeHtml( label ) + '</span>';
	}

	function escapeHtml( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	var recent = [];

	function sendable() {
		return recent.filter( function ( r ) { return ! r.invited && ! r.reviewed && SENDABLE[ r.status ]; } );
	}

	function refreshSendButton() {
		var btn = document.getElementById( 'zsv-send' );
		if ( ! btn ) { return; }
		var n = sendable().length;
		btn.hidden = n === 0;
		btn.textContent = n === 1 ? 'Send 1 review e-mail' : 'Send ' + n + ' review e-mails';
	}

	function loadRecent() {
		var list = document.getElementById( 'zsv-list' );
		if ( ! list ) { return Promise.resolve(); }
		return request( 'zsv_recent', {} ).then( function ( rows ) {
			recent = Array.isArray( rows ) ? rows : [];
			if ( ! recent.length ) {
				list.innerHTML = '<div class="zsv-empty">' + escapeHtml( 'No follow-ups yet.' ) + '</div>';
			} else {
				list.innerHTML = recent.map( function ( r ) {
					var meta = [ r.city, r.reviewed ? 'reviewed' : ( r.invited ? 'invited' : '' ) ].filter( Boolean ).join( ' · ' );
					return '<div class="zsv-row" data-id="' + ( parseInt( r.id, 10 ) || 0 ) + '">' +
						'<div class="zsv-row-main"><span class="zsv-row-name">' + escapeHtml( r.name ) + '</span>' + statusChip( r.status ) + '</div>' +
						'<div class="zsv-row-meta">' + escapeHtml( meta ) + '</div></div>';
				} ).join( '' );
			}
			refreshSendButton();
		} ).catch( function ( e ) {
			list.innerHTML = '<div class="zsv-empty">' + escapeHtml( ( e && e.message ) || 'Could not load follow-ups.' ) + '</div>';
		} );
	}

	function wire( id, action, doneText ) {
		var btn = document.getElementById( id );
		if ( ! btn ) { return; }
		btn.addEventListener( 'click', function () {
			say( '' );
			busy( btn, function () {
				return request( action, {} ).then( function () {
					say( doneText );
					return Promise.all( [ loadStats(), loadRecent() ] );
				} ).catch( sayError );
			} );
		} );
	}

	wire( 'zsv-run-batch', 'zsv_run_batch', 'Batch done.' );
	wire( 'zsv-sync', 'zsv_sync', 'Call notes synced.' );
	wire( 'zsv-check-reviews', 'zsv_check_reviews', 'Reviews checked.' );

	/** One customer per request, in order; one summary of the press for the log. */
	var sendBtn = document.getElementById( 'zsv-send' );
	if ( sendBtn ) {
		sendBtn.addEventListener( 'click', function () {
			var ids = sendable().map( function ( r ) { return parseInt( r.id, 10 ) || 0; } ).filter( Boolean );
			if ( ! ids.length ) { return; }
			var results = [];
			busy( sendBtn, function () {
				var chain = Promise.resolve();
				ids.forEach( function ( id, i ) {
					chain = chain.then( function () {
						say( 'Sending ' + ( i + 1 ) + ' of ' + ids.length + '…' );
						return request( 'zsv_send_invite', { lead_id: id } ).then( function ( r ) {
							results.push( r && r.result ? r : { id: id, result: 'failed', reason: 'no_answer' } );
						}, function () {
							results.push( { id: id, result: 'failed', reason: 'request_failed' } );
						} );
					} );
				} );
				return chain.then( function () {
					var sent = results.filter( function ( r ) { return r.result === 'sent'; } ).length;
					say( 'Sent ' + sent + ' of ' + ids.length + '.' + ( sent < ids.length ? ' The others were held back or could not be sent; see the list.' : '' ) );
					request( 'zsv_send_log', { results: JSON.stringify( results ) } ).catch( function () {} );
					return Promise.all( [ loadStats(), loadRecent() ] );
				} );
			} );
		} );
	}

	loadStats();
	loadRecent();
} )();
