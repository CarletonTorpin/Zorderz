/*!
 * Zorderz Theme — Dashboard Personalization (v2.13.1)
 *
 * Narrow scope for this release:
 *   (1) Drag-to-reorder KPI cards — order persists via /user-prefs
 *   (2) Personal Records strip — injected below the grid, data from
 *       /user-records
 *
 * Deliberately NOT in this release:
 *   - Scope toggles (Me/Team/Company) — the /kpi-metrics endpoint
 *     doesn't filter by scope yet, so toggling them would be
 *     visually broken.
 *   - Goal progress bars — same reason (need per-user metrics).
 *
 * Defensive architecture:
 *   - NO setInterval, NO MutationObserver (previous versions trapped
 *     in feedback loops).
 *   - Fixed schedule of setTimeouts at 0s, 2s, 5s, 10s; then we stop.
 *   - Every fetch has an 8s AbortController timeout.
 *   - Every entry point is wrapped in try/catch via safe() — any
 *     thrown error flips a sessionStorage kill switch so the next
 *     pageload is a no-op.
 *
 * Manual kill switch (open devtools console, paste, reload):
 *     sessionStorage.setItem('zdz_disable_personalization', '1'); location.reload();
 * Re-enable:
 *     sessionStorage.removeItem('zdz_disable_personalization'); location.reload();
 */
( function () {
	'use strict';

	try {
		if ( window.sessionStorage && sessionStorage.getItem( 'zdz_disable_personalization' ) === '1' ) {
			console.info( '[ts] personalization disabled by kill switch' );
			return;
		}
	} catch ( _ ) {}

	var T;
	try { T = window.zdzData; } catch ( _ ) { T = null; }
	if ( ! T || ! T.apiUrl || ! T.nonce ) { return; }

	var API   = String( T.apiUrl ).replace( /\/$/, '' );
	var NONCE = T.nonce;
	var FETCH_TIMEOUT_MS = 8000;

	var state = {
		cardOrder:   [],
		records:     {},
		prefsLoaded: false
	};

	function tripKillSwitch( err ) {
		try {
			console.warn( '[ts] personalization tripped — disabling for this session', err );
			sessionStorage.setItem( 'zdz_disable_personalization', '1' );
		} catch ( _ ) {}
	}

	function safe( fn ) {
		return function () {
			try { return fn.apply( this, arguments ); }
			catch ( err ) { tripKillSwitch( err ); }
		};
	}

	function restFetch( path, opts ) {
		opts = opts || {};
		opts.headers = opts.headers || {};
		opts.headers[ 'X-WP-Nonce' ] = NONCE;
		opts.credentials = 'same-origin';

		var ctrl, timer = null;
		try { ctrl = new AbortController(); opts.signal = ctrl.signal; } catch ( _ ) { ctrl = null; }
		if ( ctrl ) {
			timer = setTimeout( function () { try { ctrl.abort(); } catch ( _ ) {} }, FETCH_TIMEOUT_MS );
		}

		return fetch( API + path, opts )
			.then( function ( r ) {
				if ( timer ) { clearTimeout( timer ); timer = null; }
				if ( ! r.ok ) { throw new Error( 'HTTP ' + r.status ); }
				return r.json();
			} )
			.catch( function () {
				if ( timer ) { clearTimeout( timer ); timer = null; }
				return null;
			} );
	}

	/* ── Prefs + records fetch ────────────────────────────────── */

	function loadPrefs() {
		return restFetch( '/user-prefs' ).then( function ( res ) {
			if ( res && res.success && res.data ) {
				if ( Array.isArray( res.data.card_order ) ) {
					state.cardOrder = res.data.card_order.slice();
				}
			}
			state.prefsLoaded = true;
		} );
	}

	function savePrefs() {
		// Fire-and-forget; never block UI.
		restFetch( '/user-prefs', {
			method:  'POST',
			headers: { 'Content-Type': 'application/json' },
			body:    JSON.stringify( { card_order: state.cardOrder } )
		} );
	}

	function loadRecords() {
		return restFetch( '/user-records' ).then( function ( res ) {
			if ( res && res.success && res.data && res.data.records ) {
				state.records = res.data.records;
			}
		} );
	}

	/* ── DOM helpers ──────────────────────────────────────────── */

	function findGrid() {
		try { return document.querySelector( '.kpi-grid' ); } catch ( _ ) { return null; }
	}
	function cardId( card ) {
		var slot = card && card.querySelector ? card.querySelector( '[data-zdz-kpi]' ) : null;
		return slot ? slot.getAttribute( 'data-zdz-kpi' ) : null;
	}

	/* ── Drag handle per card ─────────────────────────────────── */

	function augmentCard( card ) {
		if ( card.__tsAugmented ) { return; }
		var cid = cardId( card );
		if ( ! cid ) { return; }
		card.__tsAugmented = true;
		card.setAttribute( 'data-zdz-card-id', cid );

		var handle = document.createElement( 'button' );
		handle.className = 'zdz-drag-handle';
		handle.type = 'button';
		handle.setAttribute( 'aria-label', 'Drag to reorder card' );
		handle.innerHTML = '<span aria-hidden="true">⋮⋮</span>';
		// Handle clicks shouldn't bubble into the card's click-to-analytics.
		handle.addEventListener( 'click', function ( e ) { e.stopPropagation(); } );
		card.insertBefore( handle, card.firstChild );
	}

	function applyOrder( grid ) {
		if ( ! state.cardOrder.length ) { return; }
		var cards   = grid.querySelectorAll( '.kpi-card' );
		var current = [];
		for ( var i = 0; i < cards.length; i++ ) {
			current.push( cards[ i ].getAttribute( 'data-zdz-card-id' ) || cardId( cards[ i ] ) );
		}
		var needs = false;
		for ( var j = 0; j < state.cardOrder.length; j++ ) {
			if ( current[ j ] !== state.cardOrder[ j ] ) { needs = true; break; }
		}
		if ( ! needs ) { return; }
		for ( var k = 0; k < state.cardOrder.length; k++ ) {
			var card = grid.querySelector( '[data-zdz-card-id="' + state.cardOrder[ k ] + '"]' );
			if ( card ) { grid.appendChild( card ); }
		}
	}

	function bindDrag( grid ) {
		if ( grid.__tsDragBound ) { return; }
		grid.__tsDragBound = true;

		var dragging = null;
		var placeholder = null;

		grid.addEventListener( 'pointerdown', safe( function ( ev ) {
			var handle = ev.target && ev.target.closest ? ev.target.closest( '.zdz-drag-handle' ) : null;
			if ( ! handle ) { return; }
			var card = handle.closest( '.kpi-card' );
			if ( ! card ) { return; }
			ev.preventDefault();
			ev.stopPropagation();
			try { handle.setPointerCapture( ev.pointerId ); } catch ( _ ) {}
			dragging = card;
			placeholder = document.createElement( 'div' );
			placeholder.className = 'zdz-card-placeholder';
			placeholder.style.height = card.offsetHeight + 'px';
			card.classList.add( 'is-dragging' );
			card.parentNode.insertBefore( placeholder, card.nextSibling );
		} ) );

		grid.addEventListener( 'pointermove', safe( function ( ev ) {
			if ( ! dragging ) { return; }
			var after = getDragAfterElement( grid, ev.clientY );
			if ( after == null ) {
				grid.appendChild( placeholder );
				grid.appendChild( dragging );
			} else {
				grid.insertBefore( placeholder, after );
				grid.insertBefore( dragging, after );
			}
		} ) );

		function finish() {
			try {
				if ( ! dragging ) { return; }
				dragging.classList.remove( 'is-dragging' );
				if ( placeholder && placeholder.parentNode ) {
					placeholder.parentNode.removeChild( placeholder );
				}
				dragging = null;
				placeholder = null;
				var cards = grid.querySelectorAll( '.kpi-card' );
				var order = [];
				for ( var i = 0; i < cards.length; i++ ) {
					var cid = cards[ i ].getAttribute( 'data-zdz-card-id' ) || cardId( cards[ i ] );
					if ( cid ) { order.push( cid ); }
				}
				state.cardOrder = order;
				savePrefs();
			} catch ( err ) { tripKillSwitch( err ); }
		}

		grid.addEventListener( 'pointerup',     finish );
		grid.addEventListener( 'pointercancel', finish );
	}

	function getDragAfterElement( grid, y ) {
		var cards = grid.querySelectorAll( '.kpi-card:not(.is-dragging)' );
		var closestEl  = null;
		var closestOff = -Infinity;
		for ( var i = 0; i < cards.length; i++ ) {
			var box = cards[ i ].getBoundingClientRect();
			var offset = y - box.top - box.height / 2;
			if ( offset < 0 && offset > closestOff ) {
				closestOff = offset;
				closestEl  = cards[ i ];
			}
		}
		return closestEl;
	}

	/* ── Personal Records strip ───────────────────────────────── */

	function injectRecordsStrip( grid ) {
		if ( document.querySelector( '.zdz-records-strip' ) ) { return; }
		var strip = document.createElement( 'section' );
		strip.className = 'zdz-records-strip is-empty';
		strip.setAttribute( 'aria-label', 'Personal records' );
		strip.innerHTML =
			'<h4 class="zdz-records-title">Personal Records</h4>' +
			'<div class="zdz-records-grid" data-zdz-personal-records></div>';
		grid.parentNode.insertBefore( strip, grid.nextSibling );
		renderRecords();
	}

	function renderRecords() {
		var el = document.querySelector( '[data-zdz-personal-records]' );
		if ( ! el ) { return; }
		// v2.20.0: Work-achievement focused records (not financial)
		var order = [
			[ 'most_jobs_in_week',          'Most Jobs/Week',       function ( v ) { return Math.round( v ); } ],
			[ 'most_estimates_in_month',    'Most Estimates/Mo',    function ( v ) { return Math.round( v ); } ],
			[ 'most_new_clients_in_month',  'New Clients/Mo',       function ( v ) { return Math.round( v ); } ],
			[ 'longest_activity_streak',    'Longest Streak',       function ( v ) { return v + ' days'; } ],
			[ 'most_surveys_in_month',      'Surveys/Mo',           function ( v ) { return Math.round( v ); } ]
		];
		var any  = false;
		var html = '';
		for ( var i = 0; i < order.length; i++ ) {
			var p   = order[ i ];
			var rec = state.records[ p[0] ];
			if ( rec ) { any = true; }
			html += '<div class="zdz-record-tile">' +
				'<div class="zdz-record-label">' + p[1] + '</div>' +
				'<div class="zdz-record-value">' + ( rec ? p[2]( Number( rec.record_value ) ) : '—' ) + '</div>' +
				( rec && rec.achieved_at ? '<div class="zdz-record-date">' + String( rec.achieved_at ).split( ' ' )[0] + '</div>' : '' ) +
			'</div>';
		}
		el.innerHTML = html;
		var strip = document.querySelector( '.zdz-records-strip' );
		if ( strip ) {
			strip.classList.toggle( 'is-empty', ! any );
			// v2.20.0: Show friendly message when no records exist yet
			if ( ! any ) {
				el.innerHTML = '<div class="personal-records-empty">' +
					'<div class="personal-records-empty-icon">🏆</div>' +
					'Your personal bests will appear here as you use Zorderz. ' +
					'Complete jobs, create estimates, and build your streak!' +
				'</div>';
			}
		}
	}

	function fmtMoney( n ) {
		try { return new Intl.NumberFormat( 'en-US', { maximumFractionDigits: 0 } ).format( n ); }
		catch ( _ ) { return String( Math.round( n ) ); }
	}

	/* ── Orchestration ────────────────────────────────────────── */

	function personalizeGrid( grid ) {
		if ( grid.__tsPersonalized ) { return; }
		grid.__tsPersonalized = true;

		var cards = grid.querySelectorAll( '.kpi-card' );
		for ( var i = 0; i < cards.length; i++ ) { augmentCard( cards[ i ] ); }
		applyOrder( grid );
		bindDrag( grid );
		injectRecordsStrip( grid );
	}

	// Fixed, bounded attempt schedule. No intervals. After the last
	// attempt we are done forever.
	var attemptTimes = [ 0, 2000, 5000, 10000 ];

	function tryAugment() {
		try {
			if ( ! state.prefsLoaded ) { return; }
			var grid = findGrid();
			if ( grid ) { personalizeGrid( grid ); }
		} catch ( err ) {
			tripKillSwitch( err );
		}
	}

	function init() {
		try {
			Promise.all( [ loadPrefs(), loadRecords() ] ).then( safe( function () {
				attemptTimes.forEach( function ( ms ) { setTimeout( tryAugment, ms ); } );
			} ) );
		} catch ( err ) { tripKillSwitch( err ); }
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	// Debugging surface only.
	window.zdzDashboardPersonalization = {
		getState: function () { return state; },
		disable:  function () { try { sessionStorage.setItem( 'zdz_disable_personalization', '1' ); } catch ( _ ) {} },
		enable:   function () { try { sessionStorage.removeItem( 'zdz_disable_personalization' ); } catch ( _ ) {} },
		reloadRecords: function () { return loadRecords().then( renderRecords ); }
	};
} )();
