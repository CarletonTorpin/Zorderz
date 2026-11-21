/**
 * Zorderz Surveys — "Send again" on the Surveys settings screen.
 *
 * A customer is sent the review request once; the site refuses a second one. This is the one
 * place a person can ask for it: one customer at a time, after a question that names the customer
 * and says when they were e-mailed. Only an OK sends the request, with `send_again` carrying that
 * customer's own number and nobody else's. The decision is the site's either way: it sends again
 * only for an administrator, and only the customer named in the same request.
 */
( function () {
	'use strict';

	var cfg = window.zsvAdmin || {};
	var table = document.getElementById( 'zsv-sent' );
	if ( ! table || ! cfg.ajaxurl ) {
		return;
	}
	var words = cfg.words || {};

	/** Put the customer, the time and the send number into a sentence, each as plain text. */
	function fill( text, name, at, n ) {
		var parts = { 1: name, 2: at, 3: n };
		return String( text || '' ).replace( /%([123])\$s/g, function ( m, k ) { return String( parts[ k ] ); } );
	}

	/** What one answer from the site means, in words. Pure. */
	function sayResult( r ) {
		if ( ! r || ! r.result ) { return words.error || ''; }
		if ( r.result === 'sent' ) {
			return r.unrecorded ? ( words.unrecorded || '' ) : fill( words.sent, '', '', String( r.send_no || 2 ) );
		}
		if ( r.result === 'failed' ) { return words.failed || ''; }
		if ( r.in_progress ) { return words.in_progress || ''; }
		if ( r.already_emailed ) { return words.refused || ''; }
		if ( r.reason === 'lock_unavailable' ) { return words.lock || ''; }
		if ( r.reason === 'row_unreadable' ) { return words.unreadable || ''; }
		if ( r.reason === 'crm_closed' ) { return words.crm_closed || ''; }
		return words.held || '';
	}

	function post( data ) {
		var body = new URLSearchParams();
		Object.keys( data ).forEach( function ( k ) { body.append( k, data[ k ] ); } );
		return fetch( cfg.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( res ) { return res.json(); } );
	}

	table.addEventListener( 'click', function ( e ) {
		var btn = e.target && e.target.closest ? e.target.closest( '.zsv-send-again' ) : null;
		if ( ! btn || btn.disabled ) { return; }
		var id = parseInt( btn.getAttribute( 'data-id' ), 10 ) || 0;
		if ( ! id ) { return; }
		var name = btn.getAttribute( 'data-name' ) || ( '#' + id );
		var at = btn.getAttribute( 'data-at' ) || '';
		// Nothing is sent unless a person answers OK to this question.
		if ( ! window.confirm( fill( words.ask, name, at, '' ) ) ) { return; }

		var row = btn.closest( 'tr' );
		var out = row ? row.querySelector( '.zsv-send-again-result' ) : null;
		var say = function ( t ) { if ( out ) { out.textContent = ' ' + t; } };
		btn.disabled = true;
		say( words.sending || '' );
		post( { action: 'zsv_send_invite', nonce: cfg.nonce || '', lead_id: id, send_again: id } ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				say( words.error || '' );
				return;
			}
			var r = res.data || {};
			say( sayResult( r ) );
			if ( r.result === 'sent' && row ) {
				var sends = row.querySelector( '.zsv-sends' );
				if ( sends && r.send_no ) { sends.textContent = String( r.send_no ); }
			}
			// A failed or refused request may be tried again; a request that went stays pressed.
			if ( r.result !== 'sent' ) { btn.disabled = false; }
		} ).catch( function () {
			// The request may have reached the site: the e-mail may have gone. Never repeated automatically.
			say( words.error || '' );
		} );
	} );
} )();
