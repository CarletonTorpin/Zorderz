/*
 * Zorderz Estimates - the estimate screens: one estimate opened from a list, the billing documents view, and the
 * admin tools.
 *
 *   ZESTDash.openEstimate(id)        one of this app's estimates: its lines (codes cut), the deposit check with its
 *                                    fix, Show code, Edit lines (wording, quantities, prices, press-and-hold choices),
 *                                    Approve (with the questions an agreement asks once), Send (asked twice), the sale
 *                                    form, Signed on paper, Add measurements; "Ask an admin" when it is not yours
 *   ZESTDash.openDocument(kind, ref)  a billing estimate or invoice that is yours (or any, for the admin tier), opened as
 *                                    the billing system has it, with the same editor
 *   the Documents tab                your documents, and "Open #"
 *   the Tools tab (admin tier)       re-assigning stubs (preview, apply, finish, past batches, undo) and the deposit list
 *   the New tab                      a customer lookup as you type
 *
 * Every decision is the server's: this file shows what the server sends and sends back what the person chose. Nothing
 * changes on a tap: a change starts with a deliberate press (or a press and hold on a word), and nothing is written
 * until the person reviews the list of changes and saves. Text from the server is always written as text, never as
 * markup.
 *
 * Needs window.zestWidget { ajaxurl, nonce, restBase, restNonce, isAdminTier }.
 */
( function () {
	'use strict';

	var HOLD_MS = 450;
	var MOVE_PX = 8;
	var MAP_KEY = 'zest_line_options_v1';

	function cfg() { return window.zestWidget || {}; }

	function h( tag, cls, text ) {
		var e = document.createElement( tag );
		if ( cls ) { e.className = cls; }
		if ( text !== undefined && text !== null ) { e.textContent = String( text ); }
		return e;
	}

	function btn( label, cls, onClick ) {
		var b = h( 'button', 'zest-btn ' + ( cls || 'zest-btn-secondary' ), label );
		b.type = 'button';
		if ( onClick ) { b.addEventListener( 'click', onClick ); }
		return b;
	}

	function money( v ) {
		if ( v === null || v === undefined || v === '' ) { return ''; }
		var n = Number( v ) || 0;
		return ( n < 0 ? '-$' : '$' ) + Math.abs( n ).toLocaleString( 'en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
	}

	function rand16() {
		var a = new Uint8Array( 12 );
		( window.crypto || window.msCrypto ).getRandomValues( a );
		return Array.prototype.map.call( a, function ( b ) { return ( '0' + b.toString( 16 ) ).slice( -2 ); } ).join( '' );
	}

	/** admin-ajax POST. Arrays go as key[] (or key[name] for an object); `json` names fields sent as one JSON string. */
	function post( action, data, json ) {
		var fd = new FormData();
		fd.append( 'action', action );
		fd.append( 'nonce', cfg().nonce || '' );
		Object.keys( data || {} ).forEach( function ( k ) {
			var v = data[ k ];
			if ( json && json.indexOf( k ) !== -1 ) {
				fd.append( k, JSON.stringify( v ) );
			} else if ( Array.isArray( v ) ) {
				v.forEach( function ( x ) { fd.append( k + '[]', x ); } );
			} else if ( v && typeof v === 'object' ) {
				Object.keys( v ).forEach( function ( kk ) { fd.append( k + '[' + kk + ']', v[ kk ] ); } );
			} else if ( v !== undefined && v !== null ) {
				fd.append( k, v );
			}
		} );
		return fetch( cfg().ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd } )
			.then( function ( r ) {
				return r.text().then( function ( t ) {
					try { return JSON.parse( t ); } catch ( e ) { return { success: false, data: { message: 'The server answered in a way the app could not read. Try again.' } }; }
				} );
			} )
			.catch( function () { return { success: false, data: { message: 'Request failed. Please try again.' } }; } );
	}

	function errText( res, fallback ) {
		var d = res && res.data;
		if ( typeof d === 'string' ) { return d; }
		return ( d && d.message ) || fallback || 'That did not work.';
	}

	/* ------------------------------------------------------------ the sheet */

	var sheet = null;

	function openSheet( title, onClose ) {
		closeSheet();
		var wrap = h( 'div', 'zest-sheet-wrap' );
		var box = h( 'div', 'zest-sheet' );
		box.setAttribute( 'role', 'dialog' );
		box.setAttribute( 'aria-modal', 'true' );
		var head = h( 'div', 'zest-sheet-head' );
		var t = h( 'div', 'zest-sheet-title', title );
		var x = btn( 'Close', 'zest-btn-secondary zest-sheet-close', function () { closeSheet(); } );
		head.appendChild( t );
		head.appendChild( x );
		var body = h( 'div', 'zest-sheet-body' );
		box.appendChild( head );
		box.appendChild( body );
		wrap.appendChild( box );
		wrap.addEventListener( 'click', function ( e ) { if ( e.target === wrap ) { closeSheet(); } } );
		document.body.appendChild( wrap );
		sheet = { wrap: wrap, body: body, title: t, onClose: onClose };
		return sheet;
	}

	function closeSheet() {
		if ( ! sheet ) { return; }
		var s = sheet;
		sheet = null;
		if ( s.onClose ) { try { s.onClose(); } catch ( e ) { /* closing never fails */ } }
		if ( s.wrap.parentNode ) { s.wrap.parentNode.removeChild( s.wrap ); }
	}

	function note( parent, text, cls ) {
		var n = h( 'div', 'zest-note ' + ( cls || '' ), text );
		parent.appendChild( n );
		return n;
	}

	function confirmBox( parent, question, yesLabel, onYes ) {
		var box = h( 'div', 'zest-confirm' );
		box.appendChild( h( 'div', 'zest-confirm-q', question ) );
		var row = h( 'div', 'zest-action-row' );
		var no = btn( 'Cancel', 'zest-btn-secondary', function () { parent.removeChild( box ); } );
		var yes = btn( yesLabel, 'zest-btn-primary', function () { parent.removeChild( box ); onYes(); } );
		row.appendChild( no );
		row.appendChild( yes );
		box.appendChild( row );
		parent.appendChild( box );
		no.focus();
	}

	function yesNo( parent, question, onAnswer ) {
		var box = h( 'div', 'zest-confirm' );
		box.appendChild( h( 'div', 'zest-confirm-q', question ) );
		var row = h( 'div', 'zest-action-row' );
		row.appendChild( btn( 'No', 'zest-btn-secondary', function () { parent.removeChild( box ); onAnswer( 'no' ); } ) );
		row.appendChild( btn( 'Yes', 'zest-btn-primary', function () { parent.removeChild( box ); onAnswer( 'yes' ); } ) );
		box.appendChild( row );
		parent.appendChild( box );
	}

	/* ------------------------------------------------------------ the option map (press-and-hold menus) */

	var mapCache = null;

	function haveMap() {
		if ( ! mapCache ) {
			try { mapCache = JSON.parse( sessionStorage.getItem( MAP_KEY ) || 'null' ); } catch ( e ) { mapCache = null; }
		}
		return mapCache && mapCache.fp ? mapCache.fp : '';
	}

	function takeMap( d ) {
		if ( d && d.map && d.map_fp ) {
			mapCache = { fp: d.map_fp, map: d.map };
			try { sessionStorage.setItem( MAP_KEY, JSON.stringify( mapCache ) ); } catch ( e ) { /* memory only */ }
		}
		if ( d && ! d.map_fp ) { mapCache = null; }
		return mapCache && d && mapCache.fp === d.map_fp ? mapCache.map : null;
	}

	function norm( s ) {
		return String( s ).toLowerCase().replace( /[“”″]/g, '"' ).replace( /’/g, "'" ).replace( /[–—]/g, '-' );
	}

	function isWordChar( c ) { return !! c && /[a-z0-9]/i.test( c ); }

	function familyOf( text, map ) {
		var t = ' ' + norm( text ) + ' ';
		var fams = ( map && map.families ) || [];
		for ( var i = 0; i < fams.length; i++ ) {
			var words = fams[ i ].match || [];
			for ( var j = 0; j < words.length; j++ ) {
				var w = norm( words[ j ] );
				if ( ! w ) { continue; }
				var at = t.indexOf( w );
				while ( at !== -1 ) {
					var b = at > 0 ? t.charAt( at - 1 ) : '';
					var e = t.charAt( at + w.length );
					if ( ! isWordChar( b ) && ! ( isWordChar( w.slice( -1 ) ) && isWordChar( e ) ) ) { return fams[ i ]; }
					at = t.indexOf( w, at + 1 );
				}
			}
		}
		return null;
	}

	function slotOptions( map, slot ) {
		var s = map.slots && map.slots[ slot ];
		if ( ! s ) { return []; }
		if ( s.kind === 'color' ) {
			return ( map.colors || [] ).map( function ( c ) { return { id: c.id, label: c.label, write: String( c.label ).toLowerCase(), say: c.say || [] }; } );
		}
		return ( s.options || [] ).map( function ( o ) { return { id: o.id, label: o.label, write: o.write, say: o.say || [] }; } );
	}

	function applyCase( original, write ) {
		if ( original.length > 1 && original.toUpperCase() === original && original.toLowerCase() !== original ) { return write.toUpperCase(); }
		var f = original.charAt( 0 );
		if ( f && f.toLowerCase() !== f ) { return write.charAt( 0 ).toUpperCase() + write.slice( 1 ); }
		return write;
	}

	/** The swappable words of a text: [ { start, end, from, slot, option } ] in code points, longest saying first. */
	function tokens( text, map, fam ) {
		if ( ! map || ! fam ) { return []; }
		var chars = Array.from( String( text ) );
		var low = chars.map( function ( c ) { return norm( c ); } );
		var out = [];
		var taken = [];
		( fam.slots || [] ).forEach( function ( slot ) {
			var says = [];
			slotOptions( map, slot ).forEach( function ( o ) { ( o.say || [] ).forEach( function ( s ) { says.push( { s: Array.from( norm( s ) ), id: o.id } ); } ); } );
			says.sort( function ( a, b ) { return b.s.length - a.s.length; } );
			says.forEach( function ( say ) {
				for ( var i = 0; i + say.s.length <= low.length; i++ ) {
					var hit = true;
					for ( var k = 0; k < say.s.length; k++ ) { if ( low[ i + k ] !== say.s[ k ] ) { hit = false; break; } }
					if ( ! hit ) { continue; }
					var end = i + say.s.length;
					if ( isWordChar( chars[ i - 1 ] || '' ) || ( isWordChar( say.s[ say.s.length - 1 ] ) && isWordChar( chars[ end ] || '' ) ) ) { continue; }
					var clash = taken.some( function ( r ) { return i < r[ 1 ] && end > r[ 0 ]; } );
					if ( clash ) { continue; }
					taken.push( [ i, end ] );
					out.push( { start: i, end: end, from: chars.slice( i, end ).join( '' ), slot: slot, option: say.id } );
				}
			} );
		} );
		return out.sort( function ( a, b ) { return a.start - b.start; } );
	}

	/** Press and hold (still) opens; a tap only says so; any movement cancels. */
	function onHold( el, fn ) {
		var timer = null, x = 0, y = 0;
		function cancel() { if ( timer ) { clearTimeout( timer ); timer = null; } }
		el.addEventListener( 'pointerdown', function ( e ) {
			x = e.clientX; y = e.clientY;
			cancel();
			timer = setTimeout( function () { timer = null; fn(); }, HOLD_MS );
		} );
		el.addEventListener( 'pointermove', function ( e ) { if ( Math.abs( e.clientX - x ) > MOVE_PX || Math.abs( e.clientY - y ) > MOVE_PX ) { cancel(); } } );
		[ 'pointerup', 'pointerleave', 'pointercancel' ].forEach( function ( ev ) { el.addEventListener( ev, cancel ); } );
		el.addEventListener( 'contextmenu', function ( e ) { e.preventDefault(); } );
	}

	/* ------------------------------------------------------------ the line editor */

	/**
	 * Draws the lines with their rights and collects changes. adapter: { save(changes, reason) -> Promise<res>, onSaved(res) }.
	 * State: changes by key; the base text is never edited in place, only shown struck through with the new words.
	 */
	function lineEditor( parent, doc, adapter ) {
		var map = takeMap( doc );
		var rights = doc.rights || {};
		var changes = {};   // key -> change
		var swaps = {};     // "i|field" -> [ swap ]
		var editing = false;
		var box = h( 'div', 'zest-doc-lines' );
		parent.appendChild( box );
		if ( rights.note ) { note( parent, rights.note ); }
		var bar = h( 'div', 'zest-action-row' );
		parent.appendChild( bar );

		function count() { return Object.keys( changes ).length + Object.keys( swaps ).reduce( function ( n, k ) { return n + swaps[ k ].length; }, 0 ); }

		function wordsEl( text, i, field, fam ) {
			var span = h( 'span', 'zest-words' );
			var list = editing && rights.options ? tokens( text, map, fam ) : [];
			var chars = Array.from( String( text ) );
			var at = 0;
			var mine = swaps[ i + '|' + field ] || [];
			list.forEach( function ( t ) {
				if ( t.start > at ) { span.appendChild( document.createTextNode( chars.slice( at, t.start ).join( '' ) ) ); }
				var done = mine.filter( function ( s ) { return s.start === t.start; } )[ 0 ];
				var w = h( 'span', 'zest-token' + ( done ? ' is-changed' : '' ) );
				if ( done ) {
					w.appendChild( h( 's', '', t.from ) );
					w.appendChild( document.createTextNode( ' ' + done.to ) );
				} else {
					w.textContent = t.from;
				}
				w.title = 'Press and hold to change';
				onHold( w, function () { optionMenu( w, t, i, field ); } );
				w.addEventListener( 'click', function () { if ( ! w.classList.contains( 'is-open' ) ) { w.classList.add( 'is-hint' ); setTimeout( function () { w.classList.remove( 'is-hint' ); }, 900 ); } } );
				span.appendChild( w );
				at = t.end;
			} );
			if ( at < chars.length ) { span.appendChild( document.createTextNode( chars.slice( at ).join( '' ) ) ); }
			return span;
		}

		function optionMenu( anchor, t, i, field ) {
			var menu = h( 'div', 'zest-menu' );
			var label = ( map.slots[ t.slot ] && map.slots[ t.slot ].label ) || 'Choice';
			menu.appendChild( h( 'div', 'zest-menu-title', label ) );
			slotOptions( map, t.slot ).forEach( function ( o ) {
				if ( o.id === t.option ) { return; }
				menu.appendChild( btn( o.label, 'zest-btn-secondary', function () {
					var key = i + '|' + field;
					swaps[ key ] = ( swaps[ key ] || [] ).filter( function ( s ) { return s.start !== t.start; } );
					swaps[ key ].push( { type: 'option', line: i, field: field, start: t.start, end: t.end, from: t.from, to: applyCase( t.from, o.write ), slot: t.slot, option: o.id } );
					menu.parentNode.removeChild( menu );
					draw();
				} ) );
			} );
			menu.appendChild( btn( 'Cancel', 'zest-btn-secondary', function () { menu.parentNode.removeChild( menu ); } ) );
			anchor.parentNode.insertBefore( menu, anchor.nextSibling );
		}

		function draw() {
			box.innerHTML = '';
			( doc.lines || [] ).forEach( function ( l, i ) {
				var row = h( 'div', 'zest-doc-line' + ( changes[ 'r' + i ] ? ' is-removed' : '' ) );
				var fam = map ? familyOf( l.name + ' - ' + l.description, map ) : null;
				var left = h( 'div', 'zest-doc-line-words' );
				var nm = h( 'div', 'zest-row-name' );
				nm.appendChild( changes[ 't' + i + 'name' ] ? document.createTextNode( changes[ 't' + i + 'name' ].to ) : wordsEl( l.name, i, 'name', fam ) );
				left.appendChild( nm );
				var ds = h( 'div', 'zest-line-meta' );
				ds.appendChild( changes[ 't' + i + 'description' ] ? document.createTextNode( changes[ 't' + i + 'description' ].to ) : wordsEl( l.description, i, 'description', fam ) );
				left.appendChild( ds );
				row.appendChild( left );
				var right = h( 'div', 'zest-doc-line-nums' );
				var q = changes[ 'q' + i ] ? changes[ 'q' + i ].to : l.qty;
				var r = changes[ 'p' + i ] ? changes[ 'p' + i ].to : l.rate;
				right.appendChild( h( 'div', '', 'x ' + q ) );
				if ( l.rate !== null && l.rate !== undefined ) { right.appendChild( h( 'div', 'zest-line-price', money( r ) ) ); }
				row.appendChild( right );
				if ( editing ) {
					var tools = h( 'div', 'zest-action-row zest-line-tools' );
					if ( rights.text ) {
						tools.appendChild( btn( 'Reword', 'zest-btn-secondary', function () { reword( i, l ); } ) );
					}
					if ( rights.qty ) {
						tools.appendChild( btn( 'Quantity', 'zest-btn-secondary', function () { number( i, 'qty', l.qty ); } ) );
					}
					if ( rights.price && l.rate !== null ) {
						tools.appendChild( btn( 'Price', 'zest-btn-secondary', function () { number( i, 'rate', l.rate ); } ) );
					}
					if ( rights.add_remove ) {
						tools.appendChild( btn( changes[ 'r' + i ] ? 'Keep this line' : 'Remove this line', 'zest-btn-secondary', function () {
							if ( changes[ 'r' + i ] ) { delete changes[ 'r' + i ]; } else { changes[ 'r' + i ] = { type: 'remove', line: i }; }
							draw();
						} ) );
					}
					row.appendChild( tools );
				}
				box.appendChild( row );
			} );
			drawBar();
		}

		function reword( i, l ) {
			var f = h( 'div', 'zest-confirm' );
			var n = h( 'input', 'zest-input' ); n.value = l.name;
			var d = h( 'textarea', 'zest-textarea' ); d.value = l.description; d.rows = 3;
			f.appendChild( h( 'div', 'zest-confirm-q', 'Line ' + ( i + 1 ) + ': type the new wording' ) );
			f.appendChild( n ); f.appendChild( d );
			var row = h( 'div', 'zest-action-row' );
			row.appendChild( btn( 'Cancel', 'zest-btn-secondary', function () { box.removeChild( f ); } ) );
			row.appendChild( btn( 'Use this wording', 'zest-btn-primary', function () {
				if ( n.value !== l.name ) { changes[ 't' + i + 'name' ] = { type: 'text', line: i, field: 'name', to: n.value }; delete swaps[ i + '|name' ]; }
				if ( d.value !== l.description ) { changes[ 't' + i + 'description' ] = { type: 'text', line: i, field: 'description', to: d.value }; delete swaps[ i + '|description' ]; }
				draw();
			} ) );
			f.appendChild( row );
			box.insertBefore( f, box.children[ i + 1 ] || null );
			n.focus();
		}

		function number( i, kind, was ) {
			var f = h( 'div', 'zest-confirm' );
			var inp = h( 'input', 'zest-input' ); inp.inputMode = 'decimal'; inp.value = String( was );
			f.appendChild( h( 'div', 'zest-confirm-q', 'Line ' + ( i + 1 ) + ': ' + ( kind === 'qty' ? 'the new quantity' : 'the new price' ) ) );
			f.appendChild( inp );
			var row = h( 'div', 'zest-action-row' );
			row.appendChild( btn( 'Cancel', 'zest-btn-secondary', function () { box.removeChild( f ); } ) );
			row.appendChild( btn( 'Use it', 'zest-btn-primary', function () {
				var v = inp.value.replace( /[$,\s]/g, '' );
				if ( ! /^-?\d+(\.\d{1,2})?$/.test( v ) ) { inp.focus(); return; }
				changes[ ( kind === 'qty' ? 'q' : 'p' ) + i ] = { type: kind, line: i, to: v };
				draw();
			} ) );
			f.appendChild( row );
			box.insertBefore( f, box.children[ i + 1 ] || null );
			inp.focus();
		}

		function addLine() {
			var f = h( 'div', 'zest-confirm' );
			var n = h( 'input', 'zest-input' ); n.placeholder = 'Name';
			var d = h( 'textarea', 'zest-textarea' ); d.placeholder = 'Description'; d.rows = 2;
			var q = h( 'input', 'zest-input' ); q.placeholder = 'Quantity'; q.value = '1'; q.inputMode = 'decimal';
			var r = h( 'input', 'zest-input' ); r.placeholder = 'Price'; r.value = '0'; r.inputMode = 'decimal';
			[ n, d, q, r ].forEach( function ( x ) { f.appendChild( x ); } );
			var row = h( 'div', 'zest-action-row' );
			row.appendChild( btn( 'Cancel', 'zest-btn-secondary', function () { box.removeChild( f ); } ) );
			row.appendChild( btn( 'Add it', 'zest-btn-primary', function () {
				if ( ! n.value.trim() ) { n.focus(); return; }
				changes[ 'a' + rand16() ] = { type: 'add', name: n.value, description: d.value, qty: q.value, rate: r.value };
				draw();
			} ) );
			f.appendChild( row );
			box.appendChild( f );
			n.focus();
		}

		function all() {
			var list = Object.keys( changes ).map( function ( k ) { return changes[ k ]; } );
			Object.keys( swaps ).forEach( function ( k ) { list = list.concat( swaps[ k ] ); } );
			return list;
		}

		function review() {
			var f = h( 'div', 'zest-confirm' );
			f.appendChild( h( 'div', 'zest-confirm-q', count() + ' change(s). Nothing is saved until you press Save.' ) );
			var ul = h( 'ul', 'zest-list' );
			all().forEach( function ( c ) {
				var t = c.type === 'option' ? 'Line ' + ( c.line + 1 ) + ': "' + c.from + '" to "' + c.to + '"'
					: c.type === 'text' ? 'Line ' + ( c.line + 1 ) + ': ' + c.field + ' reworded'
					: c.type === 'qty' ? 'Line ' + ( c.line + 1 ) + ': quantity ' + c.to
					: c.type === 'rate' ? 'Line ' + ( c.line + 1 ) + ': price ' + money( c.to )
					: c.type === 'remove' ? 'Line ' + ( c.line + 1 ) + ' removed'
					: 'New line: ' + c.name;
				ul.appendChild( h( 'li', '', t ) );
			} );
			f.appendChild( ul );
			var why = h( 'input', 'zest-input' ); why.placeholder = 'Why (optional)'; why.maxLength = 300;
			f.appendChild( why );
			var row = h( 'div', 'zest-action-row' );
			var save = btn( 'Save', 'zest-btn-primary', function () {
				save.disabled = true;
				adapter.save( all(), why.value ).then( function ( res ) {
					if ( res && res.success ) {
						changes = {}; swaps = {}; editing = false;
						adapter.onSaved( res.data || {} );
					} else {
						save.disabled = false;
						note( f, errText( res, 'Nothing was saved.' ), 'is-error' );
						if ( res && res.data && res.data.doc ) { adapter.onSaved( { doc: res.data.doc, moved: true, summary: [] } ); }
					}
				} );
			} );
			save.disabled = true;   // wakes after a beat, so a double tap never saves by accident
			setTimeout( function () { save.disabled = false; }, 700 );
			row.appendChild( btn( 'Back', 'zest-btn-secondary', function () { parent.removeChild( f ); } ) );
			row.appendChild( save );
			f.appendChild( row );
			parent.appendChild( f );
		}

		function drawBar() {
			bar.innerHTML = '';
			var can = rights.text || rights.qty || rights.price || rights.add_remove;
			if ( ! can ) { return; }
			if ( ! editing ) {
				bar.appendChild( btn( 'Edit lines', 'zest-btn-secondary', function () { editing = true; draw(); } ) );
				return;
			}
			if ( rights.add_remove ) { bar.appendChild( btn( 'Add a line', 'zest-btn-secondary', addLine ) ); }
			bar.appendChild( btn( 'Stop editing', 'zest-btn-secondary', function () { editing = false; changes = {}; swaps = {}; draw(); } ) );
			if ( count() ) { bar.appendChild( btn( 'Review & save (' + count() + ')', 'zest-btn-primary', review ) ); }
			if ( map && rights.options ) { note( bar, 'Press and hold a highlighted word to pick another choice.' ); }
		}

		draw();
	}

	/* ------------------------------------------------------------ one of this app's estimates */

	function openEstimate( id, number ) {
		var token = '';
		var s = openSheet( number ? 'Estimate #' + number : 'Estimate', function () {
			if ( token ) { post( 'zest_drop_edit_token', { token: token } ); }
		} );
		var body = s.body;
		body.appendChild( h( 'div', 'zest-empty zest-loading', 'Loading...' ) );
		post( 'zest_line_edits_load', { id: id, have_map: haveMap() } ).then( function ( res ) {
			body.innerHTML = '';
			if ( ! res || ! res.success ) {
				note( body, errText( res, 'This estimate could not be opened.' ), 'is-error' );
				if ( res && res.data && res.data.code === 'not_yours' ) { askAccess( body, id ); }
				return;
			}
			var d = res.data;
			token = d.token || '';
			drawEstimate( body, id, number, d );
		} );
	}

	function askAccess( body, id ) {
		var t = h( 'textarea', 'zest-textarea' ); t.rows = 2; t.placeholder = 'Why do you need it? (optional)';
		body.appendChild( t );
		body.appendChild( btn( 'Ask an admin', 'zest-btn-primary', function ( e ) {
			e.target.disabled = true;
			post( 'zest_request_access', { id: id, note: t.value } ).then( function ( res ) {
				note( body, res && res.success ? ( ( res.data && res.data.message ) || 'Asked. You will be told the answer.' ) : errText( res ), res && res.success ? '' : 'is-error' );
			} );
		} ) );
	}

	function drawEstimate( body, id, number, d ) {
		if ( d.from === 'local' ) { note( body, 'This is the app\'s own copy: the billing system could not be read just now, or it has none.' ); }
		var dep = h( 'div', 'zest-dep' );
		body.appendChild( dep );
		depositCheck( dep, id );
		var lines = h( 'div', '' );
		body.appendChild( lines );
		lineEditor( lines, { lines: d.lines, rights: d.rights, map: d.map, map_fp: d.map_fp }, {
			save: function ( changes, reason ) {
				return post( 'zest_line_edits_save', { id: id, token: d.token, base: d.base, changes: changes, reason: reason }, [ 'changes' ] );
			},
			onSaved: function ( r ) {
				if ( r.moved ) { return; }
				openEstimate( id, number );
				setTimeout( function () { if ( sheet ) { note( sheet.body, 'Saved. ' + ( r.summary || [] ).join( '; ' ) ); } }, 400 );
			}
		} );
		( d.codes || [] ).forEach( function ( c ) { showCode( body, c ); } );
		var acts = h( 'div', 'zest-action-row zest-est-actions' );
		var out = h( 'div', 'zest-est-out' );
		acts.appendChild( btn( 'Approve', 'zest-btn-primary', function () { approve( out, id, {} ); } ) );
		acts.appendChild( btn( 'Send', 'zest-btn-secondary', function () {
			confirmBox( out, 'Send estimate' + ( number ? ' #' + number : '' ) + ' to the customer now?', 'Yes, send it', function () {
				post( 'zest_send', { id: id } ).then( function ( res ) { note( out, res && res.success ? res.data.message : errText( res ), res && res.success ? '' : 'is-error' ); } );
			} );
		} ) );
		acts.appendChild( btn( 'Sale form', 'zest-btn-secondary', function () { saleForm( out, id ); } ) );
		acts.appendChild( btn( 'Signed on paper', 'zest-btn-secondary', function () { paper( out, id ); } ) );
		acts.appendChild( btn( 'Add measurements', 'zest-btn-secondary', function () { measurements( out, id ); } ) );
		body.appendChild( acts );
		body.appendChild( out );
	}

	function showCode( body, c ) {
		var row = h( 'div', 'zest-code-row' );
		row.appendChild( h( 'span', '', ( c.label || 'Code' ) + ' (sealed)' ) );
		if ( c.may_open ) {
			row.appendChild( btn( 'Show code', 'zest-btn-secondary', function ( e ) {
				var b = e.target; b.disabled = true;
				fetch( String( cfg().restBase || '' ).replace( /\/$/, '' ) + '/sealed/' + encodeURIComponent( c.id ) + '/open', { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg().restNonce || '' } } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( j ) {
						var out = h( 'strong', 'zest-code', j && j.code ? j.code : ( ( j && j.message ) || 'Not available.' ) );
						row.replaceChild( out, b );
						setTimeout( function () { if ( out.parentNode ) { out.textContent = '(hidden again)'; } }, 30000 );
					} )
					.catch( function () { b.disabled = false; } );
			} ) );
		}
		body.appendChild( row );
	}

	function depositCheck( box, id ) {
		post( 'zest_dep_check', { id: id } ).then( function ( res ) {
			if ( ! res || ! res.success ) { return; }
			var d = res.data;
			if ( ! d.findings || ! d.findings.length ) { return; }
			box.className = 'zest-dep ' + ( d.sure ? 'is-sure' : 'is-possible' );
			box.appendChild( h( 'div', 'zest-row-name', d.sure ? 'This asks a deposit over the limit' : 'This may ask a deposit over the limit' ) );
			if ( d.limit ) { box.appendChild( h( 'div', 'zest-line-meta', d.limit ) ); }
			d.findings.forEach( function ( f ) { box.appendChild( h( 'div', 'zest-hint', f.where + ': "' + f.words + '" (' + f.why + ')' ) ); } );
			if ( d.may_fix ) {
				box.appendChild( btn( 'Fix the deposit wording', 'zest-btn-secondary', function ( e ) {
					e.target.disabled = true;
					post( 'zest_dep_fix', { id: id, step: 'preview' } ).then( function ( p ) {
						if ( ! p || ! p.success ) { note( box, errText( p ), 'is-error' ); return; }
						var f = p.data;
						var c = h( 'div', 'zest-confirm' );
						c.appendChild( h( 'div', 'zest-confirm-q', 'These words come out:' ) );
						( f.removed || [] ).forEach( function ( r ) { c.appendChild( h( 'div', 'zest-hint', r ) ); } );
						if ( f.wrote ) { c.appendChild( h( 'div', 'zest-confirm-q', 'And this goes in: ' + f.wrote ) ); }
						var row = h( 'div', 'zest-action-row' );
						row.appendChild( btn( 'Cancel', 'zest-btn-secondary', function () { box.removeChild( c ); } ) );
						row.appendChild( btn( 'Make these changes', 'zest-btn-primary', function () {
							post( 'zest_dep_fix', { id: id, step: 'apply', token: f.token } ).then( function ( a ) {
								note( box, a && a.success ? a.data.message : errText( a ), a && a.success ? '' : 'is-error' );
							} );
						} ) );
						c.appendChild( row );
						box.appendChild( c );
					} );
				} ) );
			}
		} );
	}

	function approve( out, id, answers ) {
		post( 'zest_approve', Object.assign( { id: id }, answers.language_ok ? { language_ok: answers.language_ok } : {}, { answers: answers.more || {} } ) ).then( function ( res ) {
			if ( res && res.success ) { note( out, res.data.message ); return; }
			var d = res && res.data;
			if ( d && d.code === 'ask' && d.ask ) {
				yesNo( out, d.message, function ( a ) {
					var next = { language_ok: answers.language_ok, more: Object.assign( {}, answers.more || {} ) };
					if ( d.ask === 'language' ) { next.language_ok = a; } else { next.more[ d.ask ] = a; }
					approve( out, id, next );
				} );
				return;
			}
			note( out, errText( res, 'Not approved.' ), 'is-error' );
		} );
	}

	function saleForm( out, id ) {
		post( 'zest_sale_form', { id: id, step: 'check' } ).then( function ( res ) {
			if ( ! res || ! res.success ) { note( out, errText( res ), 'is-error' ); return; }
			var d = res.data;
			var f = h( 'div', 'zest-confirm' );
			f.appendChild( h( 'div', 'zest-confirm-q', 'Sale form: contract price ' + d.contract + ( d.cap ? '; the down payment may be at most ' + d.cap : '' ) ) );
			var day = h( 'input', 'zest-input' ); day.type = 'date'; day.value = d.today || '';
			var down = h( 'input', 'zest-input' ); down.inputMode = 'decimal'; down.value = d.fill || ''; down.disabled = !! d.locked; down.placeholder = 'Down payment';
			f.appendChild( h( 'label', 'zest-label', 'Signing day' ) ); f.appendChild( day );
			f.appendChild( h( 'label', 'zest-label', 'Down payment' ) ); f.appendChild( down );
			( d.words || [] ).forEach( function ( w ) { f.appendChild( h( 'div', 'zest-hint', w.where + ': ' + w.text ) ); } );
			var older = '';
			if ( d.asks_age ) {
				var ob = h( 'div', 'zest-action-row' );
				[ 'yes', 'no' ].forEach( function ( v ) {
					ob.appendChild( btn( v === 'yes' ? 'A buyer is 65 or older' : 'No buyer is 65 or older', 'zest-btn-secondary', function ( e ) {
						older = v;
						Array.prototype.forEach.call( ob.children, function ( c ) { c.classList.remove( 'is-active' ); } );
						e.target.classList.add( 'is-active' );
					} ) );
				} );
				f.appendChild( ob );
			}
			f.appendChild( btn( 'Print the sale form', 'zest-btn-primary', function () {
				post( 'zest_sale_form', { id: id, signing: day.value, down: d.locked ? '' : down.value, seen: d.seen || '', older: older } ).then( function ( r ) {
					if ( ! r || ! r.success ) { note( f, errText( r ), 'is-error' ); return; }
					printFacts( r.data );
				} );
			} ) );
			out.appendChild( f );
		} );
	}

	function printFacts( d ) {
		var w = window.open( '', '_blank' );
		if ( ! w ) { return; }
		var doc = w.document;
		doc.title = 'Sale form';
		var facts = d.facts || {};
		var root = doc.createElement( 'div' );
		root.style.font = '14px sans-serif';
		var add = function ( k, v ) {
			var p = doc.createElement( 'p' );
			var b = doc.createElement( 'strong' ); b.textContent = k + ': ';
			p.appendChild( b ); p.appendChild( doc.createTextNode( v === null || v === undefined ? '' : String( v ) ) );
			root.appendChild( p );
		};
		Object.keys( facts ).forEach( function ( k ) {
			if ( k === 'items' ) {
				( facts.items || [] ).forEach( function ( it, i ) { add( 'Item ' + ( i + 1 ), [ it.name, it.description, it.qty, it.price ].filter( Boolean ).join( ' - ' ) ); } );
			} else if ( typeof facts[ k ] !== 'object' ) {
				add( k, facts[ k ] );
			}
		} );
		if ( d.form && d.form.fields ) {
			var hdr = doc.createElement( 'h3' ); hdr.textContent = 'Form fields'; root.appendChild( hdr );
			Object.keys( d.form.fields ).forEach( function ( k ) { add( k, d.form.fields[ k ] ); } );
		}
		doc.body.appendChild( root );
		w.focus();
		w.print();
	}

	function paper( out, id ) {
		post( 'zest_paper_view', { id: id } ).then( function ( res ) {
			if ( ! res || ! res.success ) { note( out, errText( res ), 'is-error' ); return; }
			var p = res.data.paper;
			if ( ! p ) { note( out, 'Signed on paper needs the agreements app, and an agreement for this estimate.' ); return; }
			if ( p.paper && p.line ) { note( out, p.line ); return; }
			if ( ! p.can ) { note( out, 'A paper signing can\'t be recorded on this estimate now.' ); return; }
			var f = h( 'div', 'zest-confirm' );
			var day = h( 'input', 'zest-input' ); day.type = 'date'; day.value = p.today; day.min = p.first; day.max = p.today;
			f.appendChild( h( 'label', 'zest-label', 'Signed on' ) ); f.appendChild( day );
			var older = p.buyer_older ? 'yes' : '';
			var lang = p.language_ok || '';
			var picked = {};
			var thumbs = h( 'div', 'zest-thumbs' );
			if ( p.asks_age ) {
				yesNo( f, 'Is any buyer 65 or older?', function ( a ) { older = a; } );
			}
			if ( ! lang ) {
				yesNo( f, 'Was the sale made in the agreement\'s language?', function ( a ) { lang = a; } );
			}
			f.appendChild( btn( 'Choose from today\'s photos', 'zest-btn-secondary', function () {
				post( 'zest_paper_photos', { id: id, today: 1 } ).then( function ( r ) {
					thumbs.innerHTML = '';
					( ( r && r.success && r.data.photos ) || [] ).forEach( function ( ph ) {
						var im = h( 'img', 'zest-thumb' ); im.src = ph.thumb; im.alt = 'Photo ' + ( ph.when || '' );
						im.addEventListener( 'click', function () {
							if ( picked[ ph.id ] ) { delete picked[ ph.id ]; im.classList.remove( 'is-picked' ); } else { picked[ ph.id ] = true; im.classList.add( 'is-picked' ); }
						} );
						thumbs.appendChild( im );
					} );
					if ( ! thumbs.children.length ) { note( thumbs, 'No photos of yours from today. Take one with the camera app first.' ); }
				} );
			} ) );
			f.appendChild( thumbs );
			f.appendChild( btn( 'Record the paper signing', 'zest-btn-primary', function () {
				var ids = Object.keys( picked );
				if ( ! ids.length ) { note( f, 'Pick at least one photo of the signature page.', 'is-error' ); return; }
				post( 'zest_paper_record', { id: id, date: day.value, older: older, language_ok: lang, photos: ids } ).then( function ( r ) {
					note( f, r && r.success ? r.data.message : errText( r ), r && r.success ? '' : 'is-error' );
				} );
			} ) );
			out.appendChild( f );
		} );
	}

	function measurements( out, id ) {
		var f = h( 'div', 'zest-confirm' );
		var t = h( 'textarea', 'zest-textarea' ); t.rows = 4; t.placeholder = 'Type or say the measurements';
		f.appendChild( t );
		var pv = h( 'pre', 'zest-pre' );
		f.appendChild( btn( 'Preview', 'zest-btn-secondary', function () {
			post( 'zest_add_measurements', { id: id, text: t.value, step: 'preview' } ).then( function ( r ) {
				if ( ! r || ! r.success ) { note( f, errText( r ), 'is-error' ); return; }
				pv.textContent = r.data.note;
				var add = btn( 'Add to the lead', 'zest-btn-primary', function () {
					add.disabled = true;
					post( 'zest_add_measurements', { id: id, text: t.value, step: 'apply', token: r.data.token } ).then( function ( a ) {
						note( f, a && a.success ? a.data.message : errText( a ), a && a.success ? '' : 'is-error' );
					} );
				} );
				f.appendChild( add );
			} );
		} ) );
		f.appendChild( pv );
		out.appendChild( f );
	}

	/* ------------------------------------------------------------ billing documents */

	function openDocument( kind, ref ) {
		var s = openSheet( ( kind === 'invoice' ? 'Invoice' : 'Estimate' ) + ( ref.number ? ' #' + ref.number : '' ) );
		var body = s.body;
		body.appendChild( h( 'div', 'zest-empty zest-loading', 'Loading...' ) );
		post( 'zest_bv_load', { kind: kind, id: ref.id || '', number: ref.number || '', have_map: haveMap() } ).then( function ( res ) {
			body.innerHTML = '';
			if ( ! res || ! res.success ) { note( body, errText( res, 'It could not be opened.' ), 'is-error' ); return; }
			drawDocument( body, res.data );
		} );
	}

	function drawDocument( body, d ) {
		body.innerHTML = '';
		var c = d.customer || {};
		body.appendChild( h( 'div', 'zest-row-name', c.org || c.name || '' ) );
		body.appendChild( h( 'div', 'zest-line-meta', [ c.street, c.city, c.state, c.zip ].filter( Boolean ).join( ', ' ) ) );
		if ( d.date ) { body.appendChild( h( 'div', 'zest-line-meta', 'Date of issue ' + d.date + ( d.reference ? ' - Reference ' + d.reference : '' ) ) ); }
		lineEditor( body, d, {
			save: function ( changes, reason ) {
				return post( 'zest_bv_save', { kind: d.kind, id: d.id, base: d.base, changes: changes, reason: reason, batch: rand16() + rand16() }, [ 'changes' ] );
			},
			onSaved: function ( r ) {
				if ( r.doc ) { drawDocument( body, r.doc ); }
				if ( ! r.moved ) { note( body, 'Saved. ' + ( r.summary || [] ).join( '; ' ) + ( r.verify_note ? ' ' + r.verify_note : '' ) ); }
				else { note( body, 'It changed in the billing system since you opened it. Here it is now: make your change again.', 'is-error' ); }
			}
		} );
		if ( d.totals ) {
			var t = h( 'div', 'zest-totals' );
			t.appendChild( h( 'div', '', 'Subtotal ' + money( d.totals.subtotal ) ) );
			if ( d.totals.tax ) { t.appendChild( h( 'div', '', 'Tax ' + money( d.totals.tax ) ) ); }
			t.appendChild( h( 'div', 'zest-row-name', 'Total ' + money( d.totals.total ) ) );
			if ( d.kind === 'invoice' ) { t.appendChild( h( 'div', '', 'Paid ' + money( d.totals.paid ) + ' - Due ' + money( d.totals.outstanding ) ) ); }
			body.appendChild( t );
		}
		if ( d.notes ) { var n = h( 'pre', 'zest-pre', d.notes ); body.appendChild( n ); }
		if ( d.row_id ) { body.appendChild( btn( 'Open in Estimates', 'zest-btn-secondary', function () { openEstimate( d.row_id, d.number ); } ) ); }
		if ( d.history && d.history.length ) {
			body.appendChild( h( 'div', 'zest-row-name', 'Changes' ) );
			d.history.forEach( function ( x ) { body.appendChild( h( 'div', 'zest-hint', x.when + ' - ' + x.who + ' - ' + x.what + ( x.reason ? ' (' + x.reason + ')' : '' ) ) ); } );
		}
	}

	function documentsPanel( panel ) {
		panel.innerHTML = '';
		var row = h( 'div', 'zest-action-row' );
		var kind = h( 'select', 'zest-input' );
		[ [ 'estimate', 'Estimate' ], [ 'invoice', 'Invoice' ] ].forEach( function ( o ) { var op = h( 'option', '', o[ 1 ] ); op.value = o[ 0 ]; kind.appendChild( op ); } );
		var num = h( 'input', 'zest-input' ); num.placeholder = 'Number'; num.inputMode = 'numeric';
		row.appendChild( kind ); row.appendChild( num );
		row.appendChild( btn( 'Open', 'zest-btn-primary', function () { if ( num.value.trim() ) { openDocument( kind.value, { number: num.value.trim() } ); } } ) );
		panel.appendChild( row );
		var list = h( 'div', 'zest-w-list' );
		list.appendChild( h( 'div', 'zest-empty zest-loading', 'Loading...' ) );
		panel.appendChild( list );
		post( 'zest_bv_list', {} ).then( function ( res ) {
			list.innerHTML = '';
			var rows = ( res && res.success && res.data.rows ) || [];
			if ( ! rows.length ) { list.appendChild( h( 'div', 'zest-empty', res && res.success ? 'No documents yet.' : errText( res ) ) ); return; }
			rows.forEach( function ( r ) {
				var el = h( 'div', 'zest-row' );
				var l = h( 'div', '' );
				l.appendChild( h( 'div', 'zest-row-name', r.customer || '' ) );
				l.appendChild( h( 'div', 'zest-row-sub', ( r.kind === 'invoice' ? 'Invoice' : 'Estimate' ) + ' #' + r.number + ( r.date ? ' - ' + r.date : '' ) ) );
				el.appendChild( l );
				el.appendChild( h( 'span', 'zest-badge', r.status || '' ) );
				el.addEventListener( 'click', function () { openDocument( r.kind, { id: r.id, number: r.number } ); } );
				list.appendChild( el );
			} );
		} );
	}

	/* ------------------------------------------------------------ admin tools */

	function toolsPanel( panel ) {
		panel.innerHTML = '';
		panel.appendChild( h( 'div', 'zest-row-name', 'Re-assign stubs' ) );
		note( panel, 'Open stubs still waiting for a salesperson, given to the person Territories\' live rules pick. You see each one before anything is written, and a batch can be undone.' );
		var row = h( 'div', 'zest-action-row' );
		var scope = h( 'select', 'zest-input' );
		[ [ 'test', 'Test customers' ], [ 'real', 'Real customers' ] ].forEach( function ( o ) { var op = h( 'option', '', o[ 1 ] ); op.value = o[ 0 ]; scope.appendChild( op ); } );
		var days = h( 'input', 'zest-input' ); days.value = '90'; days.inputMode = 'numeric'; days.title = 'Days back';
		row.appendChild( scope ); row.appendChild( days );
		var out = h( 'div', 'zest-rsg' );
		row.appendChild( btn( 'Preview', 'zest-btn-primary', function ( e ) { e.target.disabled = true; rsgStart( out, scope.value, days.value, function () { e.target.disabled = false; } ); } ) );
		row.appendChild( btn( 'Past batches', 'zest-btn-secondary', function () { rsgBatches( out ); } ) );
		panel.appendChild( row );
		panel.appendChild( out );

		panel.appendChild( h( 'div', 'zest-row-name', 'Deposits over the limit' ) );
		var dep = h( 'div', 'zest-w-list' );
		panel.appendChild( btn( 'List them', 'zest-btn-secondary', function () { depScan( dep, 0 ); } ) );
		panel.appendChild( dep );
	}

	function depScan( list, page ) {
		if ( page === 0 ) { list.innerHTML = ''; }
		post( 'zest_dep_scan', { page: page } ).then( function ( res ) {
			if ( ! res || ! res.success ) { note( list, errText( res ), 'is-error' ); return; }
			res.data.rows.forEach( function ( r ) {
				var el = h( 'div', 'zest-row' );
				var l = h( 'div', '' );
				l.appendChild( h( 'div', 'zest-row-name', r.customer + ' #' + r.number ) );
				l.appendChild( h( 'div', 'zest-row-sub', r.first ) );
				el.appendChild( l );
				el.appendChild( h( 'span', 'zest-badge', r.sure ? 'over' : 'maybe' ) );
				el.addEventListener( 'click', function () { openEstimate( r.id, r.number ); } );
				list.appendChild( el );
			} );
			if ( res.data.more ) { list.appendChild( btn( 'More', 'zest-btn-secondary', function ( e ) { e.target.parentNode.removeChild( e.target ); depScan( list, page + 1 ); } ) ); }
			else if ( ! list.children.length ) { list.appendChild( h( 'div', 'zest-empty', 'None ask a deposit over the limit.' ) ); }
		} );
	}

	function rsgStart( out, scope, days, done ) {
		out.innerHTML = '';
		var st = note( out, 'Looking...' );
		post( 'zest_rsg_start', { scope: scope, days: days } ).then( function ( res ) {
			if ( ! res || ! res.success ) { st.textContent = errText( res ); done(); return; }
			if ( ! res.data.batch ) { st.textContent = res.data.message; done(); return; }
			var batch = res.data.batch;
			( function read() {
				post( 'zest_rsg_read', { batch: batch } ).then( function ( r ) {
					if ( ! r || ! r.success ) { st.textContent = errText( r ); done(); return; }
					st.textContent = 'Read ' + r.data.read + ' of ' + r.data.of + '...';
					if ( ! r.data.done ) { read(); return; }
					post( 'zest_rsg_propose', { batch: batch } ).then( function ( p ) {
						done();
						if ( ! p || ! p.success ) { st.textContent = errText( p ); return; }
						rsgPreview( out, p.data );
					} );
				} );
			}() );
		} );
	}

	function rsgPreview( out, v ) {
		out.innerHTML = '';
		if ( v.summary ) { note( out, v.summary ); }
		var ticks = {};
		var table = h( 'div', 'zest-w-list' );
		v.rows.forEach( function ( r ) {
			var el = h( 'div', 'zest-row' );
			var l = h( 'div', '' );
			l.appendChild( h( 'div', 'zest-row-name', '#' + r.num + ( r.zip ? ' - ' + r.zip : '' ) + ( r.kinds && r.kinds.length ? ' - ' + r.kinds.join( ', ' ) : '' ) ) );
			l.appendChild( h( 'div', 'zest-row-sub', r.state === 'proposed' ? r.from + ' to ' + r.to + ( r.to_name ? ' (' + r.to_name + ')' : '' ) + ( r.rule && r.rule.no ? ' - rule ' + r.rule.no : '' ) : 'keeps ' + r.from + ( r.why ? ' - ' + r.why : '' ) ) );
			el.appendChild( l );
			if ( r.state === 'proposed' ) {
				var cb = h( 'input', '' ); cb.type = 'checkbox';
				cb.addEventListener( 'change', function () { if ( cb.checked ) { ticks[ r.key ] = true; } else { delete ticks[ r.key ]; } } );
				el.appendChild( cb );
			} else {
				el.appendChild( h( 'span', 'zest-badge', r.state ) );
			}
			table.appendChild( el );
		} );
		out.appendChild( table );
		if ( v.skipped && v.skipped.length ) {
			var sk = h( 'details', '' );
			sk.appendChild( h( 'summary', '', v.skipped.length + ' not eligible' ) );
			v.skipped.forEach( function ( s ) { sk.appendChild( h( 'div', 'zest-hint', '#' + s.num + ': ' + s.why ) ); } );
			out.appendChild( sk );
		}
		var res = h( 'div', '' );
		out.appendChild( btn( 'Apply the ticked ones', 'zest-btn-primary', function ( e ) {
			var keys = Object.keys( ticks );
			if ( ! keys.length ) { note( res, 'Nothing is ticked.' ); return; }
			e.target.disabled = true;
			( function next() {
				var chunk = keys.splice( 0, 4 );
				if ( ! chunk.length ) {
					post( 'zest_rsg_finish', { batch: v.batch } ).then( function ( f ) { rsgResult( res, f && f.success ? f.data : null, errText( f ) ); } );
					return;
				}
				post( 'zest_rsg_apply', { batch: v.batch, picks: chunk }, [ 'picks' ] ).then( function ( a ) {
					if ( ! a || ! a.success ) { note( res, errText( a ), 'is-error' ); post( 'zest_rsg_finish', { batch: v.batch } ); return; }
					a.data.results.forEach( function ( x ) { note( res, '#' + ( x.num || x.key ) + ': ' + ( x.ok ? 'now ' + x.to : x.why ) ); } );
					next();
				} );
			}() );
		} ) );
		out.appendChild( res );
	}

	function rsgResult( el, r, err ) {
		if ( ! r ) { note( el, err, 'is-error' ); return; }
		var c = r.counts || {};
		note( el, 'Batch ' + r.batch + ': ' + ( c.applied || 0 ) + ' re-assigned' + ( c.changed ? ', ' + c.changed + ' changed since' : '' ) + ( c.failed ? ', ' + c.failed + ' failed' : '' ) + '. Each person was told once.' );
	}

	function rsgBatches( out ) {
		out.innerHTML = '';
		post( 'zest_rsg_batches', {} ).then( function ( res ) {
			if ( ! res || ! res.success ) { note( out, errText( res ), 'is-error' ); return; }
			if ( ! res.data.batches.length ) { note( out, 'No batches yet.' ); return; }
			res.data.batches.forEach( function ( b ) {
				var el = h( 'div', 'zest-row' );
				el.appendChild( h( 'div', '', 'Batch ' + b.batch + ' - ' + b.state + ' - ' + b.created + ( b.by ? ' - ' + b.by : '' ) + ' - ' + ( ( b.counts && b.counts.applied ) || 0 ) + ' applied' ) );
				if ( b.state === 'applied' || b.state === 'partly_undone' ) {
					el.appendChild( btn( 'Undo', 'zest-btn-secondary', function ( e ) {
						var bt = e.target;
						confirmBox( out, 'Put batch ' + b.batch + '\'s estimates back as they were?', 'Yes, undo it', function () {
							bt.disabled = true;
							var run = rand16();
							( function again() {
								post( 'zest_rsg_undo', { batch: b.batch, run: run } ).then( function ( u ) {
									if ( ! u || ! u.success ) { note( out, errText( u ), 'is-error' ); return; }
									if ( ! u.data.done ) { again(); return; }
									note( out, 'Batch ' + b.batch + ': ' + u.data.state + '.' );
								} );
							}() );
						} );
					} ) );
				}
				if ( b.stuck ) {
					el.appendChild( btn( 'Finish it', 'zest-btn-secondary', function () { post( 'zest_rsg_finish', { batch: b.batch } ).then( function ( f ) { rsgResult( out, f && f.success ? f.data : null, errText( f ) ); } ); } ) );
				}
				out.appendChild( el );
			} );
		} );
	}

	/* ------------------------------------------------------------ the customer lookup in New */

	function clientSearch( panel ) {
		var input = document.getElementById( 'zest-input' );
		if ( ! panel || ! input || panel.querySelector( '.zest-client-search' ) ) { return; }
		var box = h( 'div', 'zest-client-search' );
		var q = h( 'input', 'zest-input' ); q.placeholder = 'Find a customer (name, email or phone)'; q.setAttribute( 'aria-label', 'Find a customer' );
		var list = h( 'div', 'zest-w-list' );
		list.setAttribute( 'aria-live', 'polite' );
		box.appendChild( q ); box.appendChild( list );
		panel.insertBefore( box, panel.firstChild );
		var timer = null, seq = 0;
		q.addEventListener( 'input', function () {
			clearTimeout( timer );
			var v = q.value.trim();
			if ( v.length < 2 ) { list.innerHTML = ''; return; }
			timer = setTimeout( function () {
				var my = ++seq;
				var p = { quick: 1 };
				if ( v.indexOf( '@' ) !== -1 ) { p.email = v; }
				else if ( /^[\d\s()+.\-]{5,}$/.test( v ) ) { p.phone = v; }
				else { var parts = v.split( /\s+/ ); p.fname = parts.length > 1 ? parts[ 0 ] : ''; p.lname = parts.length > 1 ? parts.slice( 1 ).join( ' ' ) : parts[ 0 ]; }
				post( 'zest_search_clients', p ).then( function ( res ) {
					if ( my !== seq ) { return; }
					list.innerHTML = '';
					( ( res && res.success && res.data.results ) || [] ).slice( 0, 6 ).forEach( function ( c ) {
						var name = ( ( c.fname || '' ) + ' ' + ( c.lname || '' ) ).trim();
						var el = h( 'div', 'zest-row' );
						el.appendChild( h( 'div', 'zest-row-name', name || c.organization || '' ) );
						el.appendChild( h( 'div', 'zest-row-sub', [ c.city, c.state ].filter( Boolean ).join( ', ' ) + ( c.withheld ? ' (details kept for the salesperson)' : '' ) ) );
						el.addEventListener( 'click', function () {
							var line = [ name, c.email, c.phone, c.street, c.city, c.state, c.zip ].filter( Boolean ).join( ', ' );
							input.value = line + ( input.value ? '\n' + input.value : '' );
							list.innerHTML = '';
							q.value = '';
							input.focus();
						} );
						list.appendChild( el );
					} );
				} );
			}, 300 );
		} );
	}

	/* ------------------------------------------------------------ boot */

	function boot() {
		var root = document.getElementById( 'zest-widget' );
		if ( ! root || root.getAttribute( 'data-zest-dash' ) === '1' ) { return !! root; }
		root.setAttribute( 'data-zest-dash', '1' );
		clientSearch( root.querySelector( '[data-panel="new"]' ) );
		root.addEventListener( 'click', function ( e ) {
			var tab = e.target.closest ? e.target.closest( '.zest-w-tab' ) : null;
			if ( tab ) {
				var name = tab.getAttribute( 'data-tab' );
				if ( name === 'docs' ) { documentsPanel( root.querySelector( '[data-panel="docs"]' ) ); }
				if ( name === 'tools' ) { toolsPanel( root.querySelector( '[data-panel="tools"]' ) ); }
				return;
			}
			var row = e.target.closest ? e.target.closest( '#zest-open-list .zest-row, #zest-history-list .zest-row' ) : null;
			if ( row && row.getAttribute( 'data-id' ) ) {
				openEstimate( row.getAttribute( 'data-id' ), row.getAttribute( 'data-number' ) || '' );
			}
		} );
		root.addEventListener( 'keydown', function ( e ) {
			if ( e.key !== 'Enter' && e.key !== ' ' ) { return; }
			var row = e.target.closest ? e.target.closest( '#zest-open-list .zest-row, #zest-history-list .zest-row' ) : null;
			if ( row && row.getAttribute( 'data-id' ) ) {
				e.preventDefault();
				openEstimate( row.getAttribute( 'data-id' ), row.getAttribute( 'data-number' ) || '' );
			}
		} );
		return true;
	}

	window.ZESTDash = { openEstimate: openEstimate, openDocument: openDocument };
	if ( ! boot() ) {
		var tries = 0;
		document.addEventListener( 'zdz_widgets_rendered', boot );
		var iv = setInterval( function () { if ( boot() || ++tries > 40 ) { clearInterval( iv ); } }, 250 );
	}
	document.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Escape' ) { closeSheet(); } } );
}() );
