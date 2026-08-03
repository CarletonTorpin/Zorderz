/*
 * Zorderz Estimates — manual PDF import (milestone #54).
 *
 * Flow: operator uploads an existing business's estimate/invoice PDF → pdf.js extracts the
 * text in the browser (worker is the VENDORED file, never a CDN) → the text is parsed into
 * the canonical document model by the server (async parse queue, mode:'import', with a
 * sync REST fallback) → the operator reviews and edits an editable draft → on Confirm it is
 * POSTed to the EXISTING /estimate/import or /invoice/import endpoint with the wp_rest nonce.
 *
 * Nothing is imported until the human confirms. All money/line values are preserved verbatim
 * (no repricing). A totals mismatch against the document's printed total is flagged, never
 * silently corrected. If a PDF yields too little text (scanned images), a paste/manual entry
 * fallback is shown. All dynamic text is inserted as textContent / escaped.
 */
( function () {
	'use strict';

	var cfg = window.zestImport || {};
	var MAX_BYTES = 15 * 1024 * 1024; // 15 MB cap on the uploaded PDF
	var MIN_CHARS_PER_PAGE = 40;      // below this we assume an image-only PDF

	var fileEl      = document.getElementById( 'imp-file' );
	var kindEl      = document.getElementById( 'imp-kind' );
	var fnameEl     = document.getElementById( 'imp-fname' );
	var statusEl    = document.getElementById( 'imp-status' );
	var manualBox   = document.getElementById( 'imp-manual' );
	var textEl      = document.getElementById( 'imp-text' );
	var parseTextBtn = document.getElementById( 'imp-parse-text' );
	var reviewEl    = document.getElementById( 'imp-review' );
	if ( ! fileEl && ! parseTextBtn ) { return; } // card not on this page

	// Point pdf.js at the vendored worker (pdf.min.js loaded via a prior <script> tag).
	if ( window.pdfjsLib && cfg.pdfWorker ) {
		try { window.pdfjsLib.GlobalWorkerOptions.workerSrc = cfg.pdfWorker; } catch ( e ) {}
	}

	/* ───────────────────────────── helpers ───────────────────────────── */

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = ( s == null ) ? '' : String( s );
		return d.innerHTML;
	}
	function attr( s ) { return esc( s ).replace( /"/g, '&quot;' ); }
	function num( n ) { var v = parseFloat( n ); return isNaN( v ) ? 0 : v; }
	function money( n ) {
		var v = num( n );
		var s = Math.abs( v ).toLocaleString( undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
		return ( v < 0 ? '−$' : '$' ) + s;
	}

	function setStatus( msg, cls ) {
		if ( ! statusEl ) { return; }
		if ( ! msg ) { statusEl.hidden = true; statusEl.textContent = ''; statusEl.className = 'impstatus'; return; }
		statusEl.hidden = false;
		statusEl.textContent = msg;
		statusEl.className = 'impstatus' + ( cls ? ( ' ' + cls ) : '' );
	}

	function kindVal() { return ( kindEl && kindEl.value ) || ''; }

	function ajax( action, data ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( k ) { body.append( k, data[ k ] ); } );
		return fetch( cfg.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.catch( function () { return { success: false }; } );
	}

	function rest( path, body ) {
		return fetch( cfg.rest + path, {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.restNonce },
			body: JSON.stringify( body || {} )
		} ).then( function ( r ) {
			return r.json().then( function ( j ) { return { http: r.status, j: j }; }, function () { return { http: r.status, j: null }; } );
		} ).catch( function () { return { http: 0, j: null }; } );
	}

	/* ─────────────────────── pdf.js text extraction ──────────────────── */

	function extractText( file ) {
		return file.arrayBuffer().then( function ( buf ) {
			if ( ! window.pdfjsLib ) { throw new Error( 'PDF library failed to load' ); }
			return window.pdfjsLib.getDocument( { data: buf } ).promise;
		} ).then( function ( pdf ) {
			var total = pdf.numPages;
			var pages = [];
			function page( n ) {
				if ( n > total ) { return Promise.resolve( { text: pages.join( '\n\n' ), pages: total } ); }
				if ( n === 1 || n % 5 === 0 ) { setStatus( 'Extracting page ' + n + ' of ' + total + '…' ); }
				return pdf.getPage( n ).then( function ( p ) { return p.getTextContent(); } ).then( function ( tc ) {
					pages.push( tc.items.map( function ( i ) { return i.str; } ).join( ' ' ) );
					return page( n + 1 );
				} );
			}
			return page( 1 );
		} );
	}

	/* ───────────────────────── file / paste inputs ───────────────────── */

	if ( fileEl ) {
		fileEl.addEventListener( 'change', function () {
			if ( reviewEl ) { reviewEl.hidden = true; reviewEl.innerHTML = ''; }
			if ( manualBox ) { manualBox.hidden = true; }
			var f = fileEl.files && fileEl.files[ 0 ];
			if ( ! f ) { return; }
			if ( fnameEl ) { fnameEl.textContent = f.name; }

			var isPdf = ( f.type === 'application/pdf' ) || /\.pdf$/i.test( f.name );
			if ( ! isPdf ) { setStatus( 'Please choose a PDF file.', 'err' ); return; }
			if ( f.size > MAX_BYTES ) { setStatus( 'That PDF is too large (max 15 MB).', 'err' ); return; }

			setStatus( 'Reading PDF…' );
			extractText( f ).then( function ( r ) {
				var text = ( r.text || '' ).trim();
				var minChars = MIN_CHARS_PER_PAGE * Math.max( 1, r.pages || 1 );
				if ( text.length < minChars ) {
					setStatus( 'Not much text found — this PDF may be scanned images. Paste or type the text below, then parse.', 'warn' );
					if ( manualBox ) { manualBox.hidden = false; }
					if ( textEl && ! textEl.value ) { textEl.value = text; }
					return;
				}
				startParse( text );
			} ).catch( function ( e ) {
				setStatus( 'Could not read that PDF: ' + ( ( e && e.message ) || e ) + '. Paste the text below instead.', 'err' );
				if ( manualBox ) { manualBox.hidden = false; }
			} );
		} );
	}

	if ( parseTextBtn ) {
		parseTextBtn.addEventListener( 'click', function () {
			var text = ( textEl && textEl.value || '' ).trim();
			if ( ! text ) { setStatus( 'Paste some text first.', 'err' ); return; }
			startParse( text );
		} );
	}

	/* ─── parse via async queue (mode:'import'); sync REST fallback ───── */

	function startParse( text ) {
		setStatus( 'Parsing document…' );
		if ( reviewEl ) { reviewEl.hidden = true; reviewEl.innerHTML = ''; }
		ajax( 'zest_enqueue_parse', { text: text, mode: 'import', kind: kindVal() } ).then( function ( res ) {
			if ( ! res || ! res.success || ! res.data || ! res.data.job ) {
				return syncParse( text ); // enqueue unavailable — fall back to the sync endpoint
			}
			pollJob( res.data.job, text, 0, false );
		} );
	}

	function pollJob( job, text, tries, sawRunning ) {
		ajax( 'zest_job_status', { job: job } ).then( function ( res ) {
			if ( ! res || ! res.success || ! res.data ) {
				if ( tries >= 3 ) { return syncParse( text ); }
				return setTimeout( function () { pollJob( job, text, tries + 1, sawRunning ); }, 1500 );
			}
			var d = res.data;
			if ( d.status === 'done' && d.result ) { return handleResult( d.result, text ); }
			if ( d.status === 'error' ) { setStatus( d.error || 'Parse failed.', 'err' ); return; }
			if ( d.status === 'running' ) { sawRunning = true; }
			setStatus( ( d.progress && d.progress.stage ) ? ( 'Working… (' + d.progress.stage + ')' ) : 'Working…' );
			// Loopback likely disabled: still queued after ~9s and never ran → sync fallback.
			if ( d.status === 'queued' && ! sawRunning && tries >= 6 ) { return syncParse( text ); }
			if ( tries >= 40 ) { setStatus( 'Timed out. Please try again.', 'err' ); return; }
			setTimeout( function () { pollJob( job, text, tries + 1, sawRunning ); }, 1500 );
		} );
	}

	function syncParse( text ) {
		setStatus( 'Parsing document…' );
		rest( 'estimate/parse', { text: text, kind: kindVal() } ).then( function ( r ) {
			if ( r.j && r.j.ok ) { handleResult( r.j, text ); }
			else { setStatus( ( r.j && r.j.error ) || ( 'Parse failed (HTTP ' + r.http + ').' ), 'err' ); }
		} );
	}

	function handleResult( result, text ) {
		if ( ! result || ! result.ok || ! result.doc ) {
			setStatus( ( result && result.error ) || 'Parse failed.', 'err' );
			return;
		}
		setStatus( '' );
		var doc = result.doc;
		if ( ! doc.source_text ) { doc.source_text = text; }
		if ( ! Array.isArray( doc.items ) ) { doc.items = []; }
		if ( ! doc.customer || typeof doc.customer !== 'object' ) { doc.customer = {}; }
		renderReview( doc, result.warnings || [] );
	}

	/* ─────────────────────── editable review panel ───────────────────── */

	var KINDS = [ 'item', 'context', 'discount', 'fee', 'note' ];

	function fldInput( label, id, val, wide ) {
		return '<div class="fld' + ( wide ? ' wide' : '' ) + '"><label for="' + id + '">' + esc( label ) + '</label>'
			+ '<input id="' + id + '" type="text" value="' + attr( val == null ? '' : val ) + '"></div>';
	}
	function fldTextarea( label, id, val ) {
		return '<div class="fld wide"><label for="' + id + '">' + esc( label ) + '</label>'
			+ '<textarea id="' + id + '" rows="2">' + esc( val == null ? '' : val ) + '</textarea></div>';
	}
	function kindOptions( val ) {
		return KINDS.map( function ( k ) {
			return '<option value="' + k + '"' + ( String( val ) === k ? ' selected' : '' ) + '>' + k + '</option>';
		} ).join( '' );
	}
	function lineRow( li, idx ) {
		return '<tr data-idx="' + idx + '">'
			+ '<td><select data-f="kind">' + kindOptions( li.kind || 'item' ) + '</select></td>'
			+ '<td><input data-f="description" type="text" value="' + attr( li.description || '' ) + '"></td>'
			+ '<td><input data-f="sub_description" type="text" value="' + attr( li.sub_description || '' ) + '"></td>'
			+ '<td class="r"><input class="r liqty" data-f="quantity" type="number" step="any" value="' + attr( num( li.quantity ) ) + '"></td>'
			+ '<td class="r"><input class="r lirate" data-f="unit_price" type="number" step="0.01" value="' + attr( num( li.unit_price ) ) + '"></td>'
			+ '<td class="r"><input class="r lilt" data-f="line_total" type="number" step="0.01" value="' + attr( num( li.line_total ) ) + '"></td>'
			+ '<td class="c"><input data-f="is_lot" type="checkbox"' + ( li.is_lot ? ' checked' : '' ) + '></td>'
			+ '<td><button type="button" class="lidel" data-del="' + idx + '">Remove</button></td>'
			+ '</tr>';
	}
	function selOpt( cur, val, label ) { return '<option value="' + val + '"' + ( cur === val ? ' selected' : '' ) + '>' + esc( label ) + '</option>'; }

	function renderReview( doc, warnings ) {
		var isInv = doc.kind === 'invoice';
		var c = doc.customer || {};

		var warnHtml = ( warnings && warnings.length )
			? '<div class="warns">' + warnings.map( function ( w ) { return '<div class="warn">' + esc( w ) + '</div>'; } ).join( '' ) + '</div>'
			: '';

		var hdr = '<div class="fld"><label for="f-kind">Type</label><select id="f-kind">'
			+ selOpt( doc.kind, 'estimate', 'Estimate' ) + selOpt( doc.kind, 'invoice', 'Invoice' ) + '</select></div>'
			+ fldInput( 'Number', 'f-number', doc.number )
			+ fldInput( 'Date', 'f-date', doc.date )
			+ ( isInv ? fldInput( 'Due date', 'f-due', doc.due_date ) : '' )
			+ fldInput( 'Reference', 'f-ref', doc.reference )
			+ fldInput( 'Salesperson', 'f-sales', doc.salesperson );

		var cust = fldInput( 'Customer name', 'f-cname', c.name )
			+ fldInput( 'Company', 'f-corg', c.org )
			+ fldInput( 'Email', 'f-cemail', c.email )
			+ fldInput( 'Phone', 'f-cphone', c.phone )
			+ fldInput( 'Street', 'f-cstreet', c.street )
			+ fldInput( 'City', 'f-ccity', c.city )
			+ fldInput( 'State', 'f-cstate', c.state )
			+ fldInput( 'ZIP', 'f-czip', c.zip );

		var rows = doc.items.map( function ( li, i ) { return lineRow( li, i ); } ).join( '' );

		var adj = '<div class="adj">'
			+ '<label>Discount <select id="f-dtype">'
			+ selOpt( doc.discount_type || 'none', 'none', 'None' )
			+ selOpt( doc.discount_type || 'none', 'percent', 'Percent %' )
			+ selOpt( doc.discount_type || 'none', 'amount', 'Amount $' )
			+ '</select> <input id="f-dval" type="number" step="0.01" value="' + attr( num( doc.discount_value ) ) + '"></label>'
			+ '<label>Tax <input id="f-tax" type="number" step="0.01" value="' + attr( num( doc.tax ) ) + '"></label>'
			+ '<label>Shipping <input id="f-ship" type="number" step="0.01" value="' + attr( num( doc.shipping ) ) + '"></label>'
			+ ( isInv ? '<label>Amount paid <input id="f-paid" type="number" step="0.01" value="' + attr( num( doc.amount_paid ) ) + '"></label>' : '' )
			+ '</div>';

		var notes = fldTextarea( 'Notes', 'f-notes', doc.notes ) + fldTextarea( 'Terms', 'f-terms', doc.terms );

		reviewEl.innerHTML = '<h3>Review &amp; edit before import</h3>'
			+ warnHtml
			+ '<div class="grid">' + hdr + '</div>'
			+ '<div class="grid">' + cust + '</div>'
			+ '<table class="litable"><thead><tr><th>Kind</th><th>Description</th><th>Sub-description</th>'
			+ '<th class="r">Qty</th><th class="r">Unit</th><th class="r">Line total</th><th class="c">Lot</th><th></th></tr></thead>'
			+ '<tbody id="li-body">' + rows + '</tbody></table>'
			+ '<div><button type="button" class="btn" id="li-add">+ Add line</button></div>'
			+ adj
			+ '<div class="grid">' + notes + '</div>'
			+ '<div class="totbar" id="totbar"></div>'
			+ '<div class="prevwrap"><div class="prevhd"><span>Printable preview</span>'
			+ '<button type="button" class="btn ghost" id="imp-refresh">Refresh preview</button></div>'
			+ '<iframe id="imp-frame" class="prevframe" title="Document preview" sandbox="allow-same-origin"></iframe></div>'
			+ '<div class="confirmbar"><button type="button" class="btn primary" id="imp-confirm">Confirm import</button>'
			+ '<button type="button" class="btn" id="imp-cancel">Cancel</button></div>';

		reviewEl.hidden = false;
		bindReview( doc );
		recompute( doc );
		refreshPreview( doc );
	}

	function bindReview( doc ) {
		function on( id, ev, fn ) { var el = document.getElementById( id ); if ( el ) { el.addEventListener( ev, fn ); } }
		var c = doc.customer;

		on( 'f-kind', 'change', function () { doc.kind = this.value; renderReview( doc, [] ); } );
		on( 'f-number', 'input', function () { doc.number = this.value; } );
		on( 'f-date', 'input', function () { doc.date = this.value; } );
		on( 'f-due', 'input', function () { doc.due_date = this.value; } );
		on( 'f-ref', 'input', function () { doc.reference = this.value; } );
		on( 'f-sales', 'input', function () { doc.salesperson = this.value; } );
		on( 'f-cname', 'input', function () { c.name = this.value; } );
		on( 'f-corg', 'input', function () { c.org = this.value; } );
		on( 'f-cemail', 'input', function () { c.email = this.value; } );
		on( 'f-cphone', 'input', function () { c.phone = this.value; } );
		on( 'f-cstreet', 'input', function () { c.street = this.value; } );
		on( 'f-ccity', 'input', function () { c.city = this.value; } );
		on( 'f-cstate', 'input', function () { c.state = this.value; } );
		on( 'f-czip', 'input', function () { c.zip = this.value; } );

		on( 'f-dtype', 'change', function () { doc.discount_type = this.value; recompute( doc ); } );
		on( 'f-dval', 'input', function () { doc.discount_value = num( this.value ); recompute( doc ); } );
		on( 'f-tax', 'input', function () { doc.tax = num( this.value ); recompute( doc ); } );
		on( 'f-ship', 'input', function () { doc.shipping = num( this.value ); recompute( doc ); } );
		on( 'f-paid', 'input', function () { doc.amount_paid = num( this.value ); recompute( doc ); } );
		on( 'f-notes', 'input', function () { doc.notes = this.value; } );
		on( 'f-terms', 'input', function () { doc.terms = this.value; } );

		var body = document.getElementById( 'li-body' );
		if ( body ) {
			// Delegated edits. line_total is preserved verbatim — it is NOT auto-derived from
			// qty × unit; the operator edits it directly when the source total needs a fix.
			var handler = function ( e ) {
				var t = e.target;
				var f = t.getAttribute && t.getAttribute( 'data-f' );
				if ( ! f ) { return; }
				var tr = t.closest( 'tr' );
				var idx = tr ? parseInt( tr.getAttribute( 'data-idx' ), 10 ) : -1;
				if ( idx < 0 || ! doc.items[ idx ] ) { return; }
				if ( f === 'is_lot' ) { doc.items[ idx ].is_lot = !! t.checked; return; }
				if ( f === 'quantity' || f === 'unit_price' || f === 'line_total' ) { doc.items[ idx ][ f ] = num( t.value ); }
				else { doc.items[ idx ][ f ] = t.value; }
				if ( f === 'line_total' ) { recompute( doc ); }
			};
			body.addEventListener( 'input', handler );
			body.addEventListener( 'change', handler );
			body.addEventListener( 'click', function ( e ) {
				var del = e.target.getAttribute && e.target.getAttribute( 'data-del' );
				if ( del == null ) { return; }
				doc.items.splice( parseInt( del, 10 ), 1 );
				redrawLines( doc );
			} );
		}

		on( 'li-add', 'click', function () {
			doc.items.push( { kind: 'item', description: '', sub_description: '', quantity: 1, unit_price: 0, line_total: 0, is_lot: false, attribution: '' } );
			redrawLines( doc );
		} );
		on( 'imp-refresh', 'click', function () { refreshPreview( doc ); } );
		on( 'imp-cancel', 'click', function () { reviewEl.hidden = true; reviewEl.innerHTML = ''; setStatus( '' ); if ( fileEl ) { fileEl.value = ''; } if ( fnameEl ) { fnameEl.textContent = ''; } } );
		on( 'imp-confirm', 'click', function () { confirmImport( doc, this ); } );
	}

	// Re-render only the line-item tbody after add/remove (indexes shift).
	function redrawLines( doc ) {
		var body = document.getElementById( 'li-body' );
		if ( ! body ) { return; }
		body.innerHTML = doc.items.map( function ( li, i ) { return lineRow( li, i ); } ).join( '' );
		recompute( doc );
	}

	function computeTotals( doc ) {
		var sub = 0;
		( doc.items || [] ).forEach( function ( li ) { sub += num( li.line_total ); } );
		var disc = 0;
		if ( doc.discount_type === 'percent' ) { disc = sub * num( doc.discount_value ) / 100; }
		else if ( doc.discount_type === 'amount' ) { disc = num( doc.discount_value ); }
		var total = sub - disc + num( doc.tax ) + num( doc.shipping );
		return { subtotal: sub, discount: disc, total: total };
	}

	function recompute( doc ) {
		var el = document.getElementById( 'totbar' );
		if ( ! el ) { return; }
		var t = computeTotals( doc );
		var html = '<div class="trow"><span>Subtotal</span><span>' + money( t.subtotal ) + '</span></div>';
		if ( t.discount ) { html += '<div class="trow"><span>Discount</span><span>' + money( -Math.abs( t.discount ) ) + '</span></div>'; }
		if ( num( doc.shipping ) ) { html += '<div class="trow"><span>Shipping</span><span>' + money( doc.shipping ) + '</span></div>'; }
		html += '<div class="trow"><span>Tax</span><span>' + money( doc.tax ) + '</span></div>';
		html += '<div class="trow grand"><span>' + ( doc.kind === 'invoice' ? 'Total' : 'Estimate Total' ) + '</span><span>' + money( t.total ) + '</span></div>';
		if ( doc.kind === 'invoice' && num( doc.amount_paid ) ) {
			html += '<div class="trow"><span>Amount paid</span><span>' + money( doc.amount_paid ) + '</span></div>';
			html += '<div class="trow grand"><span>Amount due</span><span>' + money( t.total - num( doc.amount_paid ) ) + '</span></div>';
		}
		// Reconcile against the total printed on the source; flag, never auto-correct.
		if ( doc.stated_total != null && doc.stated_total !== '' ) {
			var delta = Math.round( ( t.total - num( doc.stated_total ) ) * 100 ) / 100;
			if ( Math.abs( delta ) > 0.01 ) {
				html += '<div class="flag">Totals mismatch: computed ' + money( t.total ) + ' vs printed '
					+ money( doc.stated_total ) + ' (off by ' + money( delta ) + '). Check the line items.</div>';
			}
		}
		el.innerHTML = html;
	}

	function refreshPreview( doc ) {
		var frame = document.getElementById( 'imp-frame' );
		if ( ! frame ) { return; }
		rest( 'estimate/preview', doc ).then( function ( r ) {
			if ( r.j && r.j.ok && typeof r.j.html === 'string' ) { frame.srcdoc = r.j.html; }
			else { frame.srcdoc = '<p style="font:13px sans-serif;color:#b3261e;padding:12px">Preview unavailable (HTTP ' + r.http + ').</p>'; }
		} );
	}

	function confirmImport( doc, btn ) {
		if ( ! doc.items.length ) { setStatus( 'Add at least one line item before importing.', 'err' ); return; }
		var path = ( doc.kind === 'invoice' ) ? 'invoice/import' : 'estimate/import';
		if ( btn ) { btn.disabled = true; btn.textContent = 'Importing…'; }
		rest( path, doc ).then( function ( r ) {
			if ( r.j && r.j.ok && r.j.id ) {
				setStatus( 'Imported ' + doc.kind + ' #' + r.j.id + '. Reloading…', 'ok' );
				if ( r.j.view ) { window.open( r.j.view, '_blank', 'noopener' ); }
				setTimeout( function () { location.reload(); }, 1200 );
			} else {
				if ( btn ) { btn.disabled = false; btn.textContent = 'Confirm import'; }
				setStatus( ( r.j && ( r.j.message || r.j.error ) ) || ( 'Import failed (HTTP ' + r.http + ').' ), 'err' );
			}
		} );
	}
}() );
