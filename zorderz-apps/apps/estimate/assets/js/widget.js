/*
 * Zorderz Estimates — widget behaviour.
 *
 * Drives the tab shell, the parse → preview → confirm flow, and the open/history lists.
 * All money/scope/convention decisions happen SERVER-SIDE; the widget only collects input,
 * shows the server's preview, and confirms. Photos enqueue a background job and poll.
 * No product, price, customer or place literal lives here — everything is data from the
 * server, which reads it from the Item Engine, Party and Doc Conventions.
 *
 * Each estimate on a list is one box: its row and, under it, its follow-up (followup.js draws
 * that part: ZESTFollowup.decorate). Estimates a customer declined fold into "Declined estimates"
 * at the foot of Open.
 *
 * For an account in Privacy View (zestWidget.privacyView) the widget is look-only: it sends
 * nothing but the two list reads, and a row opens the look-only sheet (privacy-view.js).
 */
( function () {
	'use strict';

	var cfg = window.zestWidget || {};
	var root = document.getElementById( 'zest-widget' );
	// A setting arrives as the server's page sends it: true, 1 or "1".
	var pv = cfg.privacyView === true || cfg.privacyView === 1 || cfg.privacyView === '1';
	var LOOK = [ 'zest_list_open', 'zest_history' ];

	// Idempotency guard: a fallback boot may inject a second copy of this file.
	// If the widget is already mounted, do nothing (no double listeners/renders).
	if ( root && root.getAttribute( 'data-zest-mounted' ) === '1' ) { return; }

	// The widget HTML is injected by the theme's renderWidgets() and can appear
	// AFTER this footer script evaluates. Bailing on a null root here was the
	// 1.3.1 defect: the Open list stayed on "Loading..." and the tabs never bound.
	// Instead, wait for the theme's "zdz_widgets_rendered" event (plus
	// DOMContentLoaded and a short poll) and boot once the markup is present.
	if ( ! root ) {
		var booted = false;
		var tryBoot = function () {
			if ( booted ) { return; }
			root = document.getElementById( 'zest-widget' );
			if ( root ) { booted = true; start(); }
		};
		document.addEventListener( 'zdz_widgets_rendered', tryBoot );
		document.addEventListener( 'DOMContentLoaded', tryBoot );
		var polls = 0;
		var iv = setInterval( function () {
			tryBoot();
			if ( booted || ++polls > 40 ) { clearInterval( iv ); } // ~10s max
		}, 250 );
		return;
	}

	start();

	// Everything below is wrapped so it runs either immediately (widget HTML
	// already in the DOM) or deferred (the race above).
	function start() {
		// Mark mounted so a second (fallback-injected) copy no-ops at the guard.
		if ( root.getAttribute( 'data-zest-mounted' ) === '1' ) { return; }
		root.setAttribute( 'data-zest-mounted', '1' );

	function post( action, data ) {
		// Look only: nothing but the two list reads ever leaves this file for an account in Privacy View.
		if ( pv && LOOK.indexOf( action ) === -1 ) {
			return Promise.resolve( { success: false, data: { message: 'Not available in Privacy View.' } } );
		}
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( k ) {
			var v = data[ k ];
			body.append( k, typeof v === 'object' ? JSON.stringify( v ) : v );
		} );
		return fetch( cfg.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.catch( function () { return { success: false, data: { message: 'Request failed. Please try again.' } }; } );
	}

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s == null ? '' : String( s );
		return d.innerHTML;
	}

	function money( n ) {
		var v = parseFloat( n || 0 );
		return isNaN( v ) ? '' : v.toLocaleString( undefined, { style: 'currency', currency: 'USD' } );
	}

	/* ---- tabs ---- */
	root.querySelectorAll( '.zest-w-tab' ).forEach( function ( tab ) {
		tab.addEventListener( 'click', function () {
			var name = tab.getAttribute( 'data-tab' );
			root.querySelectorAll( '.zest-w-tab' ).forEach( function ( t ) { t.classList.toggle( 'is-active', t === tab ); } );
			root.querySelectorAll( '.zest-w-panel' ).forEach( function ( p ) {
				p.classList.toggle( 'is-active', p.getAttribute( 'data-panel' ) === name );
			} );
			if ( name === 'open' ) { loadList( 'zest_list_open', 'zest-open-list' ); }
			if ( name === 'history' ) { loadList( 'zest_history', 'zest-history-list' ); }
		} );
	} );

	/* ---- lists ---- */
	var declinedOpen = false;   // "Declined estimates" stays as the person left it when the list is drawn again

	function rowHtml( r ) {
		return '<div class="zest-row" data-id="' + esc( r.id ) + '" data-number="' + esc( r.number ) + '" role="button" tabindex="0">'
			+ '<div><div class="zest-row-name">' + esc( r.customer || 'Estimate' ) + ( r.test && pv ? ' <span class="zest-w-pv-test">test</span>' : '' ) + '</div>'
			+ '<div class="zest-row-sub">' + esc( r.number ? '#' + r.number : 'stub' ) + ' &middot; ' + esc( r.items ) + ' items</div></div>'
			+ '<span class="zest-badge">' + esc( r.status ) + '</span></div>';
	}

	/** One estimate, one box: the row, and under it the strip its follow-up is drawn in. */
	function cardHtml( r ) {
		return '<div class="zest-fu-card">' + rowHtml( r ) + '<div class="zest-fu-strip" data-fu-id="' + esc( r.id ) + '"></div></div>';
	}

	/** The list's markup. With follow-ups: a box per estimate, and on Open the declined ones folded at the foot. */
	function listHtml( rows, withFollowups, isOpenList ) {
		if ( ! withFollowups ) { return rows.map( rowHtml ).join( '' ); }
		var live = [], declined = [];
		rows.forEach( function ( r ) { ( isOpenList && r.declined ? declined : live ).push( r ); } );
		var h = live.map( cardHtml ).join( '' );
		if ( declined.length ) {
			h += '<details class="zest-fu-declined"' + ( declinedOpen ? ' open' : '' ) + '><summary>Declined estimates (' + declined.length + ')</summary>'
				+ declined.map( cardHtml ).join( '' ) + '</details>';
		}
		return h;
	}

	function loadList( action, elId, again ) {
		var el = document.getElementById( elId );
		if ( ! el ) { return; }
		// Drawn again after a follow-up was recorded: what is there stays until the new list arrives.
		if ( ! again ) { el.innerHTML = '<div class="zest-empty zest-loading">Loading&hellip;</div>'; }
		post( action, {} ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				el.innerHTML = '<div class="zest-empty">Could not load.</div>';
				return;
			}
			var data = res.data || {};
			var rows = data.rows || [];
			if ( ! rows.length ) {
				el.innerHTML = '<div class="zest-empty">Nothing here yet.</div>';
				return;
			}
			var fu = !! data.followups && !! window.ZESTFollowup;
			el.innerHTML = listHtml( rows, fu, action === 'zest_list_open' );
			var fold = el.querySelector( '.zest-fu-declined' );
			if ( fold ) { fold.addEventListener( 'toggle', function () { declinedOpen = fold.open; } ); }
			if ( fu ) { window.ZESTFollowup.decorate( el, data, function () { loadList( action, elId, true ); } ); }
		} );
	}

	// Look only: a row opens the look-only sheet. (The editor's script, which opens a row otherwise, is not loaded.)
	if ( pv ) {
		var look = function ( e ) {
			var row = e.target.closest ? e.target.closest( '#zest-open-list .zest-row, #zest-history-list .zest-row' ) : null;
			if ( ! row || ! row.getAttribute( 'data-id' ) || ! window.ZESTPrivacyView ) { return; }
			if ( e.type === 'keydown' ) {
				if ( e.key !== 'Enter' && e.key !== ' ' ) { return; }
				e.preventDefault();
			}
			window.ZESTPrivacyView.open( row.getAttribute( 'data-id' ) );
		};
		root.addEventListener( 'click', look );
		root.addEventListener( 'keydown', look );
	}

	/* ---- parse → preview → confirm ---- */
	var input = document.getElementById( 'zest-input' );
	var fileEl = document.getElementById( 'zest-file' );
	var parseBtn = document.getElementById( 'zest-parse' );
	var statusEl = document.getElementById( 'zest-status' );
	var statusText = document.getElementById( 'zest-status-text' );
	var preview = document.getElementById( 'zest-preview' );
	var lastEstimate = null;

	function setStatus( msg ) {
		if ( ! statusEl ) { return; }
		if ( msg ) { statusEl.hidden = false; statusText.textContent = msg; }
		else { statusEl.hidden = true; }
	}

	function renderPreview( est, warnings ) {
		lastEstimate = est;
		if ( ! preview ) { return; }
		var lines = ( est.line_items || [] ).map( function ( li ) {
			var price = parseFloat( li.unit_price || 0 );
			return '<div class="zest-line"><div>' + esc( li.description || '' )
				+ ( li.sub_description ? '<div class="zest-line-meta">' + esc( li.sub_description ) + '</div>' : '' )
				+ ( li.door && li.door.text ? '<div class="zest-team-only">Team only: ' + esc( li.door.text ) + '</div>' : '' )
				+ '</div><div class="zest-line-price">' + ( price > 0 ? money( price ) : '&ndash;' ) + '</div></div>';
		} ).join( '' );
		var warn = ( warnings || [] ).map( function ( w ) { return '<div class="zest-hint">' + esc( w ) + '</div>'; } ).join( '' );
		preview.hidden = false;
		preview.innerHTML = '<div class="zest-row-name">' + esc( est.customer_name || 'Customer' ) + '</div>'
			+ lines + warn
			+ '<div class="zest-action-row"><button class="zest-btn zest-btn-primary" id="zest-create">Send to billing</button>'
			+ '<button class="zest-btn zest-btn-secondary" id="zest-reset">Start over</button></div>';
		var createBtn = document.getElementById( 'zest-create' );
		if ( createBtn ) { createBtn.addEventListener( 'click', doCreate ); }
		var resetBtn = document.getElementById( 'zest-reset' );
		if ( resetBtn ) { resetBtn.addEventListener( 'click', function () { preview.hidden = true; lastEstimate = null; } ); }
	}

	function doParse() {
		if ( fileEl && fileEl.files && fileEl.files.length ) {
			enqueuePhotos();
			return;
		}
		var text = input ? input.value.trim() : '';
		if ( ! text ) { return; }
		setStatus( 'Reading…' );
		// Text parse runs SYNCHRONOUSLY. A text estimate parse completes in a few seconds —
		// well under a managed host's gateway timeout — so it does not need the background
		// job path. 1.3.4 routed it through zest_enqueue_parse, but on WP Engine that job
		// consistently returned an empty Ai response ("No response content.") for the
		// catalog-augmented text prompt, while the SAME input parsed correctly on the
		// synchronous endpoint (identical model + key). Photos and PDF import stay async
		// below (they are genuinely slow and their background jobs work). See 1.3.5 notes.
		parseSync( text );
	}

	function parseSync( text ) {
		post( 'zest_parse', { text: text } ).then( function ( res ) {
			setStatus( '' );
			if ( ! res || ! res.success ) {
				setStatus( ( res && res.data && res.data.message ) || 'Parse failed.' );
				return;
			}
			renderPreview( res.data.estimate, res.data.warnings );
		} );
	}

	function enqueuePhotos() {
		var urls = [];
		// The theme media store handles upload; here we assume already-hosted URLs are
		// provided by the shell. Fall back to text-only if none.
		setStatus( 'Uploading…' );
		post( 'zest_enqueue_parse', { text: input ? input.value.trim() : '', images: urls } ).then( function ( res ) {
			if ( ! res || ! res.success ) { setStatus( 'Upload failed.' ); return; }
			pollJob( res.data.job );
		} );
	}

	function pollJob( job ) {
		post( 'zest_job_status', { job: job } ).then( function ( res ) {
			if ( ! res || ! res.success ) { setStatus( 'Job failed.' ); return; }
			var d = res.data;
			setStatus( ( d.progress && d.progress.stage ) || 'Working…' );
			if ( d.status === 'done' && d.result ) {
				setStatus( '' );
				renderPreview( d.result.estimate, d.result.warnings );
			} else if ( d.status === 'error' ) {
				setStatus( d.error || 'Parse failed.' );
			} else {
				setTimeout( function () { pollJob( job ); }, 1500 );
			}
		} );
	}

	function doCreate() {
		if ( ! lastEstimate ) { return; }
		setStatus( 'Creating…' );
		post( 'zest_create', { estimate: lastEstimate } ).then( function ( res ) {
			setStatus( '' );
			if ( ! res || ! res.success ) {
				setStatus( ( res && res.data && res.data.message ) || 'Create failed.' );
				return;
			}
			preview.innerHTML = '<div class="zest-empty">Created estimate #' + esc( res.data.number ) + '.</div>';
			if ( input ) { input.value = ''; }
		} );
	}

	if ( parseBtn ) { parseBtn.addEventListener( 'click', doParse ); }

	/* initial load */
	loadList( 'zest_list_open', 'zest-open-list' );
	}
}() );
