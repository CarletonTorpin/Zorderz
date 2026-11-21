/*
 * Zorderz Estimates - one estimate to look at, for an account in Privacy View.
 *
 * Loaded only for that account (ZEST_Privacy_View::render_widget()). On the theme's kit of parts (ZDZ.ui: ajax, sheet,
 * loadingHtml). It asks zest_pv_estimate { id } - this site's copy of the estimate, already made safe by the server
 * (the customer, the number and every team member are codes; no e-mail, phone, town, price, or anything a person
 * typed or dictated is sent: a line is its quantity, not its words) - and draws it. It sends nothing else and draws no
 * control that changes anything. Everything is escaped.
 */
( function () {
	'use strict';

	var STATUS = { created: 'Created', stub: 'Stub', open: 'Open', updated: 'Updated', sent: 'Sent', accepted: 'Accepted', imported: 'Imported', converted: 'Invoiced' };

	function kit() { return ( window.ZDZ && window.ZDZ.ui ) ? window.ZDZ.ui : null; }
	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ]; } );
	}
	/** A stored date and time, to the minute, as it is stored. */
	function at( s ) {
		var m = /^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2}))?/.exec( String( s || '' ) );
		return m ? m[ 1 ] + ( m[ 2 ] ? ' ' + m[ 2 ] : '' ) : '';
	}
	function fact( label, value ) {
		return value ? '<div class="zest-pv-fact"><dt>' + esc( label ) + '</dt><dd>' + value + '</dd></div>' : '';
	}
	function qtyText( q ) {
		var n = Number( q );
		return isFinite( n ) ? String( Math.round( n * 1000 ) / 1000 ) : '';
	}

	function bodyHtml( e ) {
		e = e || {};
		var h = '<div class="zest-pv">';
		h += '<p class="zest-pv-head">' + ( e.kind === 'invoice' ? 'Invoice' : 'Estimate' ) + ( e.number ? ' #' + esc( e.number ) : '' )
			+ ( e.customer ? ' <span>&middot; ' + esc( e.customer ) + '</span>' : '' )
			+ ( e.test ? ' <span class="zest-w-pv-test">test</span>' : '' ) + '</p>';
		var place = [ e.street, e.state ].filter( function ( x ) { return !! x; } ).map( esc ).join( ', ' );
		h += '<dl class="zest-pv-facts">'
			+ fact( 'Salesperson', esc( e.salesperson || '' ) )
			+ fact( 'Status', esc( STATUS[ e.status ] || '' ) )
			+ fact( 'Created', esc( at( e.created ) ) )
			+ fact( 'Sent', esc( at( e.sent_at ) ) )
			+ fact( 'Approved', esc( at( e.approved_at ) ) + ( e.approved_at && e.approved_by ? ' by ' + esc( e.approved_by ) : '' ) )
			+ fact( 'Accepted', esc( at( e.accepted_at ) ) )
			+ fact( 'Place', place )
			+ fact( 'E-mail on file', e.has_email ? 'Yes' : 'No' )
			+ fact( 'Phone on file', e.has_phone ? 'Yes' : 'No' )
			+ fact( 'Billing system', e.in_billing ? 'Has this estimate' : 'Does not have it' )
			+ fact( 'CRM', e.in_crm ? 'Has a lead for it' : 'No lead on record' )
			+ '</dl>';

		var lines = e.lines || [];
		if ( lines.length ) {
			h += '<div class="zest-pv-scroll"><table class="zest-pv-lines"><thead><tr><th scope="col">Line</th><th scope="col" class="zest-pv-num">Qty</th></tr></thead><tbody>';
			lines.forEach( function ( l, i ) {
				h += '<tr><td>' + ( i + 1 ) + ( l.words ? '' : ' <span class="zest-pv-muted">(no words)</span>' ) + '</td>'
					+ '<td class="zest-pv-num">' + esc( qtyText( l.qty ) ) + '</td></tr>';
			} );
			h += '</tbody></table></div>';
			h += '<p class="zest-pv-muted">What each line says, and its price, are not shown in Privacy View.</p>';
		} else {
			h += '<p class="zest-pv-muted">No lines on this site\'s copy of it.</p>';
		}
		if ( e.left_out ) { h += '<p class="zest-pv-muted">' + esc( e.left_out ) + ( e.left_out === 1 ? ' item was' : ' items were' ) + ' left off when it was read.</p>'; }

		h += '<h3 class="zest-pv-sub">Follow-up</h3>';
		var f = e.followup;
		if ( f && f.words ) {
			h += '<p>' + ( f.declined ? '<span class="zest-fu-tag">Declined</span> ' : '' ) + esc( f.words ) + '</p>';
			if ( f.doc_state === 'failed' ) { h += '<p class="zest-pv-muted">The line on the billing estimate was not written' + ( f.doc_why ? ' (' + esc( f.doc_why ) + ')' : '' ) + '.</p>'; }
			else if ( f.doc_state === 'skipped' ) { h += '<p class="zest-pv-muted">The billing system was left as it is' + ( f.doc_why ? ': ' + esc( f.doc_why ) : '' ) + '.</p>'; }
			else if ( f.doc_state === 'written' ) { h += '<p class="zest-pv-muted">The billing estimate has the short line.</p>'; }
		} else {
			h += '<p class="zest-pv-muted">None showing on the list.</p>';
		}
		var ours = e.followup_lines || [];
		if ( ours.length ) {
			h += '<p class="zest-pv-muted">In this site\'s copy of the notes:</p><ul class="zest-pv-ours">'
				+ ours.map( function ( l ) { return '<li>' + esc( l ) + '</li>'; } ).join( '' ) + '</ul>';
		}
		if ( e.notes_hidden ) {
			h += '<p class="zest-pv-muted">' + esc( e.notes_hidden ) + ( e.notes_hidden === 1 ? ' other line of notes is' : ' other lines of notes are' ) + ' not shown in Privacy View.</p>';
		}
		return h + '</div>';
	}

	function open( id ) {
		var k = kit();
		id = String( id == null ? '' : id );
		if ( ! k || ! /^[1-9]\d*$/.test( id ) ) { return; }
		var W = window.zestWidget || {};
		var sh = k.sheet( {
			title: 'Estimate, to look at',
			body: '<div data-pv-body>' + ( k.loadingHtml ? k.loadingHtml( 'Loading' ) : '<p>Loading</p>' ) + '</div>',
			actions: [ { label: 'Close' } ]
		} );
		k.ajax( 'zest_pv_estimate', { nonce: W.nonce || '', id: id }, { timeout: 30000 } ).then( function ( r ) {
			var b = sh && sh.el ? sh.el.querySelector( '[data-pv-body]' ) : null;
			if ( b ) { b.innerHTML = bodyHtml( ( r && r.estimate ) || {} ); }
		} ).catch( function ( err ) {
			var b = sh && sh.el ? sh.el.querySelector( '[data-pv-body]' ) : null;
			if ( b ) { b.innerHTML = '<p class="zest-fu-err" role="alert">' + esc( ( err && err.message ) || 'That did not open. Try again.' ) + '</p>'; }
		} );
	}

	window.ZESTPrivacyView = { open: open, _html: bodyHtml };
}() );
