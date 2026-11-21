/**
 * Zorderz Surveys — dashboard widget.
 *
 * Reads stats + recent follow-ups, and drives the batch / sync / send / check-reviews actions and
 * the Google reviews panel. All business decisions (status guard, review routing, safety floor,
 * one e-mail per customer, which customer a Google review may be recorded on) are server-side;
 * this file is presentation + transport only, and what it decides about the screen comes from
 * widget-logic.js.
 *
 * It runs on the theme's kit of parts when the theme has it (window.ZDZ.ui): requests go
 * through ZDZ.ui.ajax, so a lost signal, a timeout or a server problem shows as a plain
 * sentence in the progress slot, and the buttons that send use ZDZ.ui.busy (one press at a
 * time). Without the kit it falls back to a plain fetch and its own busy state.
 *
 * Review e-mails go ONE customer per request, in order, with "Sending 2 of 6": a slow CRM on
 * one customer never stops the rest. One summary of the press is sent back for the log. This
 * page never asks the site to e-mail a customer again: only the settings screen can, after a
 * person has answered a question.
 *
 * A customer nobody has handled is listed with the call notes showing; a tap opens the rest.
 * One press of "Check reviews" keeps checking until nothing is left, and its last line is a
 * plain sentence. Everything shown is text: names, notes and reviews are never treated as markup.
 */
( function () {
	'use strict';

	var cfg = window.zsvWidget || {};
	var root = document.getElementById( 'zsv-widget' );
	var L = window.ZSVLogic;
	if ( ! root || ! cfg.ajaxurl || ! L ) {
		return;
	}
	var kit = ( window.ZDZ && window.ZDZ.ui && typeof window.ZDZ.ui.ajax === 'function' ) ? window.ZDZ.ui : null;

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
	var opened = {};   // customers whose panel a person opened: kept open when the list is drawn again

	function refreshSendButton() {
		var btn = document.getElementById( 'zsv-send' );
		if ( ! btn ) { return; }
		var n = L.sendable( recent ).length;
		btn.hidden = n === 0;
		btn.textContent = n === 1 ? 'Send 1 review e-mail' : 'Send ' + n + ' review e-mails';
	}

	/**
	 * One customer. A customer nobody has handled yet is drawn with the call notes showing (the
	 * panel carries zsv-notes-first: laid out, notes only); the rest of the panel waits for the tap.
	 */
	function rowHtml( r ) {
		var id = parseInt( r.id, 10 ) || 0;
		var meta = [ r.city, r.reviewed ? 'reviewed' : ( r.invited ? 'invited' : '' ) ].filter( Boolean ).join( ' \u00B7 ' );
		var first = L.notesFirst( r );
		var open = !! opened[ id ];
		var hint = L.hintLabel( r, open );
		var details = [
			r.work ? 'Work: ' + r.work : '',
			r.date ? 'Added ' + String( r.date ).substring( 0, 10 ) : '',
			r.emailed_at ? 'E-mailed ' + String( r.emailed_at ).substring( 0, 16 ) : ''
		].filter( Boolean ).join( ' \u00B7 ' );
		var html = '<div class="zsv-row" data-id="' + id + '">' +
			'<div class="zsv-row-main"><span class="zsv-row-name">' + escapeHtml( r.name ) + '</span>' + statusChip( r.status ) + '</div>' +
			'<div class="zsv-row-meta">' + escapeHtml( meta ) + '</div>';
		if ( hint ) {
			html += '<button type="button" class="zsv-row-hint" aria-expanded="' + ( open ? 'true' : 'false' ) + '" aria-controls="zsv-panel-' + id + '">' + escapeHtml( hint ) + '</button>' +
				'<div class="zsv-row-panel' + ( first ? ' zsv-notes-first' : '' ) + ( open ? ' zsv-expanded' : '' ) + '" id="zsv-panel-' + id + '">' +
				( details ? '<div class="zsv-row-details">' + escapeHtml( details ) + '</div>' : '' ) +
				( r.notes ? '<div class="zsv-row-notes">' + escapeHtml( r.notes ) + '</div>' : '' ) +
				'</div>';
		}
		return html + '</div>';
	}

	function loadRecent() {
		var list = document.getElementById( 'zsv-list' );
		if ( ! list ) { return Promise.resolve(); }
		return request( 'zsv_recent', {} ).then( function ( rows ) {
			recent = Array.isArray( rows ) ? rows : [];
			if ( ! recent.length ) {
				list.innerHTML = '<div class="zsv-empty">' + escapeHtml( 'No follow-ups yet.' ) + '</div>';
			} else {
				list.innerHTML = recent.map( rowHtml ).join( '' );
			}
			refreshSendButton();
		} ).catch( function ( e ) {
			list.innerHTML = '<div class="zsv-empty">' + escapeHtml( ( e && e.message ) || 'Could not load follow-ups.' ) + '</div>';
		} );
	}

	// A tap on the hint opens the whole panel; a second tap folds it back (to its notes, where they were showing).
	var listEl = document.getElementById( 'zsv-list' );
	if ( listEl ) {
		listEl.addEventListener( 'click', function ( e ) {
			var hint = e.target && e.target.closest ? e.target.closest( '.zsv-row-hint' ) : null;
			if ( ! hint ) { return; }
			var row = hint.closest( '.zsv-row' );
			var panel = row ? row.querySelector( '.zsv-row-panel' ) : null;
			if ( ! panel ) { return; }
			var id = parseInt( row.getAttribute( 'data-id' ), 10 ) || 0;
			var open = ! panel.classList.contains( 'zsv-expanded' );
			panel.classList.toggle( 'zsv-expanded', open );
			hint.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			if ( open ) { opened[ id ] = true; } else { delete opened[ id ]; }
			var data = recent.filter( function ( r ) { return ( parseInt( r.id, 10 ) || 0 ) === id; } )[ 0 ];
			hint.textContent = L.hintLabel( data || {}, open );
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

	/*
	 * Check reviews: one press checks until nothing is left (L.shouldCheckAgain), every pass its
	 * own request, never repeated by the kit. The last line stays under the buttons until the
	 * next check. Nothing here sends an e-mail.
	 */
	var checkBtn = document.getElementById( 'zsv-check-reviews' );
	var checkStatus = document.getElementById( 'zsv-review-status' );
	if ( checkBtn ) {
		checkBtn.addEventListener( 'click', function () {
			var others = [ 'zsv-run-batch', 'zsv-sync' ].map( function ( id ) { return document.getElementById( id ); } ).filter( Boolean );
			var held = others.filter( function ( b ) { return ! b.disabled; } );
			var tot = L.newTotals();
			function show( text ) {
				say( '' );
				if ( checkStatus ) {
					checkStatus.textContent = text;
					checkStatus.hidden = false;
				} else {
					say( text );
				}
			}
			function pass() {
				return request( 'zsv_check_reviews', {} ).then( function ( d ) {
					d = d || {};
					L.addPass( tot, d );
					// A later pass that cannot run at all: what the earlier passes did is still said.
					if ( d.error && tot.passes > 1 ) { throw new Error( String( d.error ) ); }
					if ( L.shouldCheckAgain( tot, d ) ) {
						say( 'Checking for new reviews\u2026 ' + L.countOf( tot.checked, 'customer', 'customers' ) + ' checked so far' );
						return pass();
					}
					show( L.reviewResultText( tot, d ) );
				} );
			}
			say( 'Checking for new reviews\u2026' );
			held.forEach( function ( b ) { b.disabled = true; } );
			busy( checkBtn, function () {
				return pass().catch( function ( e ) {
					show( L.reviewErrorText( tot, ( e && e.message ) || '' ) );
				} ).then( function () {
					held.forEach( function ( b ) { b.disabled = false; } );
					return tot.found > 0 ? Promise.all( [ loadStats(), loadRecent() ] ) : null;
				} );
			} );
		} );
	}

	/** One customer per request, in order; one summary of the press for the log. */
	var sendBtn = document.getElementById( 'zsv-send' );
	if ( sendBtn ) {
		sendBtn.addEventListener( 'click', function () {
			var ids = L.sendable( recent ).map( function ( r ) { return parseInt( r.id, 10 ) || 0; } ).filter( Boolean );
			if ( ! ids.length ) { return; }
			var results = [];
			busy( sendBtn, function () {
				var chain = Promise.resolve();
				ids.forEach( function ( id, i ) {
					chain = chain.then( function () {
						say( 'Sending ' + ( i + 1 ) + ' of ' + ids.length + '\u2026' );
						// No send_again here, ever: a customer the site says was e-mailed stays e-mailed once.
						return request( 'zsv_send_invite', { lead_id: id } ).then( function ( r ) {
							results.push( r && r.result ? r : { id: id, result: 'failed', reason: 'no_answer' } );
						}, function () {
							results.push( { id: id, result: 'failed', reason: 'request_failed' } );
						} );
					} );
				} );
				return chain.then( function () {
					say( L.sendSummary( results, ids.length ) );
					request( 'zsv_send_log', { results: JSON.stringify( results ) } ).catch( function () {} );
					return Promise.all( [ loadStats(), loadRecent() ] );
				} );
			} );
		} );
	}

	/*
	 * Google reviews: the business's own reviews, read hourly by the site and matched there to a
	 * surveyed customer by the reviewer's public name. Watch-only until an administrator turns
	 * automatic recording on. A person can Confirm one of the customers the site offered, or say
	 * "Not them". Hidden until the site is connected to Google.
	 */
	var grEl = document.getElementById( 'zsv-greviews' );

	function grRender( d ) {
		if ( ! grEl ) { return; }
		if ( ! d || ! d.ready ) { grEl.hidden = true; grEl.innerHTML = ''; return; }
		var last = L.grLastText( d.last );
		var html = '<div class="zsv-gr-head"><strong>Google reviews</strong>' +
			'<span class="zsv-gr-mode">' + escapeHtml( L.grModeText( !! d.automatic ) ) + '</span>' +
			( last ? '<span class="zsv-gr-last">' + escapeHtml( last ) + '</span>' : '' ) + '</div>';
		var rows = '';
		( d.items || [] ).slice( 0, 10 ).forEach( function ( it ) {
			var st = L.grStatus( it );
			var what;
			if ( st.kind === 'ask' ) {
				what = '<span class="zsv-gr-q">' + escapeHtml( st.text ) + '</span> ' + st.offer.map( function ( c ) {
					var who = L.grOfferLabel( c, st.offer );
					return c.reviewed
						? '<span class="zsv-gr-none">' + escapeHtml( who + ' (already has a review)' ) + '</span>'
						: '<button type="button" class="zsv-btn zsv-btn-secondary zsv-btn-sm zsv-gr-confirm" data-review="' + escapeHtml( it.id ) + '" data-lead="' + ( parseInt( c.id, 10 ) || 0 ) + '" data-name="' + escapeHtml( who ) + '">' + escapeHtml( 'Confirm: ' + who ) + '</button>';
				} ).join( ' ' ) +
				' <button type="button" class="zsv-btn zsv-btn-secondary zsv-btn-sm zsv-gr-dismiss" data-review="' + escapeHtml( it.id ) + '">Not them</button>';
			} else {
				what = '<span class="zsv-gr-' + ( st.kind === 'done' ? 'done' : 'none' ) + '">' + escapeHtml( st.text ) + '</span>';
			}
			var n = parseInt( it.stars, 10 ) || 0;
			rows += '<div class="zsv-gr-row"><div class="zsv-gr-top"><span class="zsv-gr-stars" role="img" aria-label="' + n + ' stars">' + L.stars( n ) + '</span> ' +
				'<strong>' + escapeHtml( it.reviewer || 'A Google user' ) + '</strong> <span class="zsv-gr-date">' + escapeHtml( it.date ) + '</span></div>' +
				( it.snippet ? '<div class="zsv-gr-snip">\u201C' + escapeHtml( it.snippet ) + '\u201D</div>' : '' ) +
				'<div class="zsv-gr-what">' + what + '</div></div>';
		} );
		if ( ! rows ) {
			rows = '<p class="zsv-gr-none">' + escapeHtml( 'No new Google reviews in the last ' + ( parseInt( d.lookback, 10 ) || 60 ) + ' days yet.' ) + '</p>';
		}
		var admin = d.can_admin
			? '<div class="zsv-gr-admin"><button type="button" class="zsv-btn zsv-btn-secondary zsv-btn-sm" id="zsv-gr-now">Check Google now</button> ' +
				'<button type="button" class="zsv-btn zsv-btn-secondary zsv-btn-sm" id="zsv-gr-mode" data-on="' + ( d.automatic ? '0' : '1' ) + '">' +
				( d.automatic ? 'Turn off automatic recording' : 'Turn on automatic recording' ) + '</button></div>'
			: '';
		grEl.innerHTML = html + rows + admin;
		grEl.hidden = false;
	}

	function grLoad() {
		if ( ! grEl ) { return; }
		request( 'zsv_greviews_list', {} ).then( grRender, function () { /* the panel stays as it was */ } );
	}

	if ( grEl ) {
		grEl.addEventListener( 'click', function ( e ) {
			var b = e.target && e.target.closest ? e.target.closest( 'button' ) : null;
			if ( ! b || b.disabled ) { return; }
			var act = null, data = {};
			if ( b.classList.contains( 'zsv-gr-confirm' ) ) {
				if ( ! window.confirm( 'Record this Google review on ' + ( b.getAttribute( 'data-name' ) || 'this customer' ) + '?\n\nTheir survey lead will also be closed as Won in the CRM.' ) ) { return; }
				act = 'zsv_greviews_confirm';
				data = { review_id: b.getAttribute( 'data-review' ), lead_id: b.getAttribute( 'data-lead' ) };
			} else if ( b.classList.contains( 'zsv-gr-dismiss' ) ) {
				act = 'zsv_greviews_dismiss';
				data = { review_id: b.getAttribute( 'data-review' ) };
			} else if ( b.id === 'zsv-gr-now' ) {
				act = 'zsv_greviews_check_now';
			} else if ( b.id === 'zsv-gr-mode' ) {
				var on = b.getAttribute( 'data-on' ) === '1';
				if ( on && ! window.confirm( 'Record sure matches automatically from now on?\n\nA sure match is a full name (or first name and last initial) that fits exactly one surveyed customer. The review is recorded on that customer; their lead is closed as Won only where the auto-close policy allows it, and is otherwise left for a person.\n\nWhat is listed now is not recorded: it stays for a person to confirm.' ) ) { return; }
				act = 'zsv_greviews_mode';
				data = { automatic: on ? '1' : '0' };
			}
			if ( ! act ) { return; }
			busy( b, function () {
				return request( act, data ).then( function ( d ) {
					grRender( d );
					if ( act === 'zsv_greviews_confirm' ) { return Promise.all( [ loadStats(), loadRecent() ] ); }
				} ).catch( sayError );
			} );
		} );
	}

	loadStats();
	loadRecent();
	grLoad();
} )();
