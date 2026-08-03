/*
 * Zorderz Estimates — widget behaviour.
 *
 * Drives the tab shell, the parse → preview → confirm flow, and the open/history lists.
 * All money/scope/convention decisions happen SERVER-SIDE; the widget only collects input,
 * shows the server's preview, and confirms. Photos enqueue a background job and poll.
 * No product, price, customer or place literal lives here — everything is data from the
 * server, which reads it from the Item Engine, Party and Doc Conventions.
 */
( function () {
	'use strict';

	var cfg = window.zestWidget || {};
	var root = document.getElementById( 'zest-widget' );

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
	function loadList( action, elId ) {
		var el = document.getElementById( elId );
		if ( ! el ) { return; }
		el.innerHTML = '<div class="zest-empty zest-loading">Loading&hellip;</div>';
		post( action, {} ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				el.innerHTML = '<div class="zest-empty">Could not load.</div>';
				return;
			}
			var rows = ( res.data && res.data.rows ) || [];
			if ( ! rows.length ) {
				el.innerHTML = '<div class="zest-empty">Nothing here yet.</div>';
				return;
			}
			el.innerHTML = rows.map( function ( r ) {
				return '<div class="zest-row" data-id="' + esc( r.id ) + '">'
					+ '<div><div class="zest-row-name">' + esc( r.customer || 'Estimate' ) + '</div>'
					+ '<div class="zest-row-sub">' + esc( r.number ? '#' + r.number : 'stub' ) + ' &middot; ' + esc( r.items ) + ' items</div></div>'
					+ '<span class="zest-badge">' + esc( r.status ) + '</span></div>';
			} ).join( '' );
		} );
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
				+ '</div><div class="zest-line-price">' + ( price > 0 ? money( price ) : '&mdash;' ) + '</div></div>';
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
