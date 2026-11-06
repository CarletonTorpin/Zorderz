/**
 * Commission widget — hydrates the dashboard skeleton from the REST routes.
 * The server is the single source of truth: every money figure is formatted from
 * what it returns, never recomputed here. Text is always set with textContent.
 *
 *   - "My commission" / "My pay" for the signed-in person; "Open in Commission"
 *     (the zdz_app_launch event with user_id, date_start, date_end) opens on that
 *     person and window when the server allows it, and never swaps in another figure.
 *   - An invoice number is a button: the invoice opens under its row, read live,
 *     with "Copy customer link" for whoever may see the customer.
 *   - A pay admin looking at a whole, ended month sees "Lock <Month> for <Name>".
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
		if ( text !== undefined && text !== null ) { e.textContent = String( text ); }
		return e;
	}

	function request( path, params, method ) {
		var url = cfg.restUrl + path;
		var opts = { headers: { 'X-WP-Nonce': cfg.nonce || '' }, credentials: 'same-origin', method: method || 'GET' };
		var qs = [];
		Object.keys( params || {} ).forEach( function ( k ) {
			qs.push( encodeURIComponent( k ) + '=' + encodeURIComponent( params[ k ] ) );
		} );
		if ( opts.method === 'GET' ) {
			if ( qs.length ) { url += '?' + qs.join( '&' ); }
		} else {
			opts.headers[ 'Content-Type' ] = 'application/x-www-form-urlencoded';
			opts.body = qs.join( '&' );
		}
		return fetch( url, opts ).then( function ( r ) { return r.json(); } );
	}

	function copyText( text, input ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			return navigator.clipboard.writeText( text ).then( function () { return true; }, function () { return false; } );
		}
		input.value = text;
		input.select();
		try { return Promise.resolve( document.execCommand( 'copy' ) ); } catch ( e ) { return Promise.resolve( false ); }
	}

	/* ── one invoice, drawn under its row ── */
	function openInvoice( holder, number, where, target ) {
		if ( holder.getAttribute( 'data-open' ) === '1' ) {
			holder.innerHTML = '';
			holder.setAttribute( 'data-open', '0' );
			return;
		}
		holder.setAttribute( 'data-open', '1' );
		holder.textContent = 'Loading…';
		request( 'invoice', { number: number, where: where, target: target || 0 } ).then( function ( d ) {
			holder.innerHTML = '';
			if ( ! d || ! d.ok ) {
				holder.appendChild( el( 'p', 'zcc-note', ( d && d.message ) || 'The invoice couldn’t be loaded right now.' ) );
				return;
			}
			var doc = d.doc || {};
			var sheet = el( 'div', 'zcc-sheet' );
			sheet.appendChild( el( 'div', 'zcc-sheet-num', 'Invoice ' + ( doc.number || '' ) + ( doc.issued ? ' · ' + doc.issued : '' ) ) );
			( doc.billed_to || [] ).forEach( function ( line ) { sheet.appendChild( el( 'div', 'zcc-sheet-billed', line ) ); } );
			var table = el( 'table', 'zcc-sheet-lines' );
			( doc.lines || [] ).forEach( function ( ln ) {
				var tr = el( 'tr' );
				tr.appendChild( el( 'td', '', ln.name + ( ln.description ? ' — ' + ln.description : '' ) ) );
				tr.appendChild( el( 'td', 'zcc-num', ln.qty === null ? '' : ln.qty ) );
				tr.appendChild( el( 'td', 'zcc-num', ln.amount === null ? '' : money( ln.amount ) ) );
				table.appendChild( tr );
			} );
			sheet.appendChild( table );
			if ( doc.totals ) {
				sheet.appendChild( el( 'div', 'zcc-sheet-total', 'Total ' + money( doc.totals.total ) + ' · Paid ' + money( doc.totals.paid ) + ' · Due ' + money( doc.totals.due ) ) );
			}
			if ( doc.notes ) { sheet.appendChild( el( 'div', 'zcc-sheet-notes', doc.notes ) ); }
			if ( d.can_share ) {
				var btn = el( 'button', 'button zcc-copy-link', 'Copy customer link' );
				var box = el( 'input', 'zcc-link-box' );
				box.readOnly = true;
				box.hidden = true;
				btn.type = 'button';
				btn.addEventListener( 'click', function () {
					btn.disabled = true;
					request( 'invoice-link', { number: number, where: where, target: target || 0 }, 'POST' ).then( function ( r ) {
						btn.disabled = false;
						if ( ! r || ! r.ok || ! r.link ) {
							btn.textContent = ( r && r.message ) || 'No link just now.';
							return;
						}
						box.value = r.link;
						copyText( r.link, box ).then( function ( ok ) {
							btn.textContent = ok ? 'Copied' : 'Copy the link below';
							box.hidden = ok;
						} );
					} ).catch( function () { btn.disabled = false; } );
				} );
				sheet.appendChild( btn );
				sheet.appendChild( box );
			}
			holder.appendChild( sheet );
		} ).catch( function () { holder.textContent = 'The invoice couldn’t be loaded right now.'; } );
	}

	function renderLock( box, lock, rerun ) {
		if ( ! lock ) { return; }
		var wrap = el( 'div', 'zcc-lock' );
		if ( lock.locked ) {
			var when = lock.locked.at ? new Date( lock.locked.at * 1000 ).toLocaleDateString() : '';
			wrap.appendChild( el( 'div', 'zcc-lock-done', lock.month_name + ' is locked. Locked ' + when + ( lock.locked.by_name ? ' by ' + lock.locked.by_name : '' ) +
				': ' + money( lock.locked.commission ) + ' on ' + lock.locked.invoices + ' invoice(s). These numbers no longer change.' ) );
			if ( lock.open > 0 ) {
				wrap.appendChild( el( 'div', 'zcc-lock-note', lock.open + ' invoice(s) paid since then not locked yet. Lock again after you pay them.' ) );
			}
		} else if ( lock.needs_paid ) {
			wrap.appendChild( el( 'div', 'zcc-lock-note', 'To lock ' + lock.month_name + ', show paid and partly paid invoices.' ) );
		} else {
			wrap.appendChild( el( 'div', 'zcc-lock-note', 'After you’ve paid this commission, lock the month so later edits to these invoices can’t change these numbers.' ) );
		}
		if ( lock.can_lock ) {
			var b = el( 'button', 'button zcc-lock-btn', lock.label );
			b.type = 'button';
			b.addEventListener( 'click', function () {
				if ( ! window.confirm( lock.ask ) ) { return; }
				b.disabled = true;
				request( 'lock-month', { user_id: lock.user_id, month: lock.month }, 'POST' ).then( function ( r ) {
					wrap.appendChild( el( 'div', 'zcc-lock-note', ( r && r.message ) || 'Nothing was locked.' ) );
					if ( r && r.ok && rerun ) { rerun(); } else { b.disabled = false; }
				} ).catch( function () { b.disabled = false; } );
			} );
			wrap.appendChild( b );
		}
		box.appendChild( wrap );
	}

	function renderCommission( box, data, rerun ) {
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
		if ( data.subject ) {
			box.appendChild( el( 'div', 'zcc-who', 'For ' + data.subject + ( data.date_start ? ' · ' + data.date_start + ' – ' + data.date_end : '' ) ) );
		}
		box.appendChild( el( 'div', 'zcc-figure', money( data.amount ) ) );
		if ( data.basis ) { box.appendChild( el( 'div', 'zcc-basis', data.basis ) ); }
		if ( data.review_count > 0 ) {
			box.appendChild( el( 'div', 'zcc-warn', data.review_count + ' invoice(s) in this window are waiting for a person to say whose they are; they are not in anyone’s figure yet.' ) );
		}
		var rows = ( data.breakdown && data.breakdown.invoices ) || [];
		if ( rows.length ) {
			var list = el( 'div', 'zcc-rows' );
			rows.forEach( function ( r ) {
				var row = el( 'div', 'zcc-row' );
				var num = el( 'button', 'zcc-inv-num', r.invoice_number );
				num.type = 'button';
				var sheet = el( 'div', 'zcc-inv-sheet' );
				num.addEventListener( 'click', function () { openInvoice( sheet, r.invoice_number, 'commission', data.subject_uid ); } );
				row.appendChild( num );
				row.appendChild( el( 'span', 'zcc-row-date', r.date || '' ) );
				row.appendChild( el( 'span', 'zcc-row-amt', money( r.commission_amount ) ) );
				if ( r.ledger_status === 'finalized' ) { row.appendChild( el( 'span', 'zcc-row-lock', 'Locked' ) ); }
				list.appendChild( row );
				list.appendChild( sheet );
			} );
			box.appendChild( list );
		}
		renderLock( box, data.lock, rerun );
	}

	function renderPay( box, data ) {
		box.innerHTML = '';
		var pc = data && data.paycheck;
		if ( ! pc ) {
			box.appendChild( el( 'p', 'zcc-note', ( data && data.message ) || 'No pay data.' ) );
			return;
		}
		box.appendChild( el( 'div', 'zcc-figure', money( pc.total_pay ) ) );
		box.appendChild( el( 'div', 'zcc-basis', ( pc.units_total || 0 ) + ' unit(s)' ) );
		// LOUD: never hide an unresolved (counted-but-unrated) line behind a $0.
		if ( pc.resolved === false && Array.isArray( pc.missing_rates ) && pc.missing_rates.length ) {
			box.appendChild( el( 'div', 'zcc-warn', 'Unresolved — no rate configured for: ' + pc.missing_rates.join( ', ' ) + '. This paycheck is not final.' ) );
		}
		var held = ( data.review || [] ).length || data.review_count || 0;
		if ( held ) {
			box.appendChild( el( 'div', 'zcc-warn', held + ' job(s) or item(s) are waiting for a person to confirm who did them; they are not in this paycheck yet.' ) );
		}
		if ( data.team_roles_note ) {
			box.appendChild( el( 'p', 'zcc-note', String( data.team_roles_note ) ) );
		}
	}

	function wire( widget ) {
		var btn = widget.querySelector( '.zcc-run' );
		var box = widget.querySelector( '.zcc-result' );
		var sel = widget.querySelector( '.zcc-period' );
		if ( ! btn || ! box ) { return; }
		var isPiece = box.getAttribute( 'data-piece' ) === '1';

		function run( extra ) {
			var params = extra || { subject: 'me', period: sel ? sel.value : 'this_month' };
			box.textContent = 'Loading…';
			if ( isPiece && ! extra ) {
				request( 'pay', { period: params.period } ).then( function ( d ) { renderPay( box, d ); } ).catch( function () { box.textContent = 'Could not load.'; } );
				return;
			}
			request( 'calculate', params ).then( function ( d ) {
				renderCommission( box, d, function () { run( params ); } );
			} ).catch( function () { box.textContent = 'Could not load.'; } );
		}
		btn.addEventListener( 'click', function () { run(); } );
		widget.zccRun = run;
	}

	function onLaunch( ev ) {
		var d = ev && ev.detail;
		if ( ! d || d.appId !== cfg.appId ) { return; }
		var o = d.options || {};
		var widget = document.querySelector( '.zcc-widget' );
		if ( ! widget || typeof widget.zccRun !== 'function' ) { return; }
		// The server decides whether this person may see that figure; a refusal is shown as
		// it is and nobody else's figure is put in its place.
		if ( o.user_id && o.date_start && o.date_end ) {
			widget.zccRun( { subject: String( o.user_id ), date_start: o.date_start, date_end: o.date_end } );
		}
	}

	function init() {
		var widgets = document.querySelectorAll( '.zcc-widget' );
		Array.prototype.forEach.call( widgets, wire );
		document.addEventListener( 'zdz_app_launch', onLaunch );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
