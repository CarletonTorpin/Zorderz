/**
 * Zorderz Dot Plot widget. Dependency-free. All security decisions are server-side: this only
 * renders what /dotplot/plot returns and shows the server's refusal message verbatim when a spec
 * is rejected (unknown source / un-allow-listed entity / unentitled money source / bad window).
 */
( function () {
	'use strict';

	var cfg = window.zdpWidget || {};
	var root = document.getElementById( 'zdp-widget' );
	if ( ! root || ! cfg.rest ) { return; }

	var elSource = document.getElementById( 'zdp-source' );
	var elEntity = document.getElementById( 'zdp-entity' );
	var elFrom   = document.getElementById( 'zdp-from' );
	var elTo     = document.getElementById( 'zdp-to' );
	var elMsg    = document.getElementById( 'zdp-msg' );
	var elGrid   = document.getElementById( 'zdp-grid' );
	var sources  = {};

	function api( path, method, body ) {
		return fetch( cfg.rest + path, {
			method: method,
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
			credentials: 'same-origin',
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( r ) { return r.json().then( function ( j ) { return { status: r.status, json: j }; } ); } );
	}

	function say( text, kind ) {
		elMsg.textContent = text || '';
		elMsg.className = 'zdp-msg' + ( kind ? ' zdp-msg-' + kind : '' );
	}

	function defaultWindow() {
		var to = new Date();
		var from = new Date();
		from.setDate( from.getDate() - 30 );
		if ( ! elTo.value ) { elTo.value = to.toISOString().slice( 0, 10 ); }
		if ( ! elFrom.value ) { elFrom.value = from.toISOString().slice( 0, 10 ); }
	}

	function loadSources() {
		api( '/sources', 'GET' ).then( function ( res ) {
			var list = ( res.json && res.json.sources ) || [];
			sources = {};
			elSource.innerHTML = '';
			if ( ! list.length ) {
				say( 'No report sources are registered yet.', 'muted' );
			}
			list.forEach( function ( s ) {
				sources[ s.key ] = s;
				var o = document.createElement( 'option' );
				o.value = s.key;
				o.textContent = s.label + ( s.money_class ? ' ($)' : '' );
				elSource.appendChild( o );
			} );
			syncEntities();
		} );
	}

	function syncEntities() {
		var s = sources[ elSource.value ];
		elEntity.innerHTML = '';
		if ( ! s ) { return; }
		( s.entities || [] ).forEach( function ( e ) {
			var o = document.createElement( 'option' );
			o.value = e;
			o.textContent = e;
			elEntity.appendChild( o );
		} );
	}

	function currentSpec() {
		return {
			source: elSource.value,
			entity: elEntity.value,
			axis: 'count',
			window: { from: elFrom.value, to: elTo.value }
		};
	}

	function plot() {
		defaultWindow();
		var spec = currentSpec();
		if ( ! spec.source || ! spec.entity ) { say( 'Choose a source and a grouping.', 'muted' ); return; }
		say( 'Plotting…' );
		api( '/plot', 'POST', spec ).then( function ( res ) {
			var d = res.json || {};
			if ( ! d.ok ) {
				say( d.message || 'That plot was refused.', 'error' );
				elGrid.innerHTML = '';
				return;
			}
			say( d.events_plotted + ' event(s), ' + d.rows.length + ' row(s).', 'ok' );
			renderGrid( d );
		} ).catch( function () { say( 'Network error.', 'error' ); } );
	}

	function dictate() {
		var u = ( document.getElementById( 'zdp-utterance' ).value || '' ).trim();
		if ( ! u ) { return; }
		say( 'Thinking…' );
		api( '/dictate', 'POST', { utterance: u } ).then( function ( res ) {
			var d = res.json || {};
			if ( ! d.ok || ! d.spec ) { say( 'Could not turn that into a plot. Pick the fields manually.', 'muted' ); return; }
			if ( sources[ d.spec.source ] ) { elSource.value = d.spec.source; syncEntities(); }
			if ( d.spec.entity ) { elEntity.value = d.spec.entity; }
			if ( d.spec.window ) {
				if ( d.spec.window.from ) { elFrom.value = d.spec.window.from; }
				if ( d.spec.window.to ) { elTo.value = d.spec.window.to; }
			}
			plot(); // the server re-validates the proposed spec.
		} ).catch( function () { say( 'Network error.', 'error' ); } );
	}

	function renderGrid( d ) {
		var days = d.days || [];
		var rows = d.rows || [];
		var cells = d.cells || {};
		if ( ! rows.length || ! days.length ) {
			elGrid.innerHTML = '<div class="zdp-empty">No events in this window.</div>';
			return;
		}
		// Peak value for dot scaling.
		var peak = 1;
		rows.forEach( function ( r ) {
			days.forEach( function ( day ) {
				var v = ( cells[ r.id ] && cells[ r.id ][ day ] ) || 0;
				if ( v > peak ) { peak = v; }
			} );
		} );

		var html = '<table class="zdp-grid"><thead><tr><th class="zdp-rowhead"></th>';
		days.forEach( function ( day ) { html += '<th class="zdp-dayhead"><span>' + esc( day.slice( 5 ) ) + '</span></th>'; } );
		html += '</tr></thead><tbody>';
		rows.forEach( function ( r ) {
			html += '<tr><th class="zdp-rowhead" title="' + esc( r.label ) + '">' + esc( r.label ) + '</th>';
			days.forEach( function ( day ) {
				var v = ( cells[ r.id ] && cells[ r.id ][ day ] ) || 0;
				if ( v > 0 ) {
					var scale = 0.35 + 0.65 * ( v / peak );
					html += '<td class="zdp-cell"><span class="zdp-dot" style="transform:scale(' + scale.toFixed( 2 ) + ')" title="' + esc( r.label + ' · ' + day + ' · ' + v ) + '"></span></td>';
				} else {
					html += '<td class="zdp-cell"></td>';
				}
			} );
			html += '</tr>';
		} );
		html += '</tbody></table>';
		elGrid.innerHTML = html;
	}

	function esc( s ) {
		return String( s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	elSource.addEventListener( 'change', syncEntities );
	document.getElementById( 'zdp-plot' ).addEventListener( 'click', plot );
	document.getElementById( 'zdp-dictate' ).addEventListener( 'click', dictate );

	defaultWindow();
	loadSources();
}() );
