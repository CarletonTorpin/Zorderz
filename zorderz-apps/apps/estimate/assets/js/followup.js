/*
 * Zorderz Estimates - follow-ups on the lists: re-send an estimate to its salesperson, their answer, and taking one
 * back.
 *
 * On the theme's kit of parts (ZDZ.ui: ajax, sheet, busy, toast, loadingHtml). The server decides everything: who may
 * re-send, who may answer, and what is written to the billing system (one line of fixed phrases, never what is typed
 * here). This file asks, draws and escapes.
 *
 * An estimate and its follow-up are ONE box: widget.js draws each list row inside a .zest-fu-card with an empty
 * <div class="zest-fu-strip" data-fu-id="..."> under it, and calls ZESTFollowup.decorate( listEl, payload, reload )
 * after every draw. `payload` is the list's answer: { rows: [ { id, number, customer, followup, followup_history,
 * can_follow } ], followups: { can_send, outcomes, channels, max_note, read_only } }.
 *
 * Every sheet names the estimate and its customer at the top and on its save button, so nothing is recorded on the
 * wrong one; "Recorded by mistake?" and "Sent by mistake?" take the last thing back; the strip says what happened to
 * the billing line (written, or left as it is and why), not only when it failed.
 */
( function () {
	'use strict';

	var S = { meta: {}, byId: {}, reload: function () {} };

	function cfg() { return window.zestWidget || {}; }
	function kit() { return ( window.ZDZ && window.ZDZ.ui ) ? window.ZDZ.ui : null; }

	/** One of this app's follow-up requests. A look-only list only ever asks for the history. */
	function post( action, body ) {
		var k = kit();
		if ( ! k ) { return Promise.reject( new Error( 'Follow-ups need a newer Zorderz theme. Reload the app.' ) ); }
		if ( S.meta.read_only && action !== 'zest_fu_history' ) { return Promise.reject( new Error( 'Not available in Privacy View.' ) ); }
		body = body || {};
		body.nonce = cfg().nonce || '';
		return k.ajax( action, body, { timeout: 60000 } );
	}

	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ]; } );
	}
	function say( t, kind ) { var k = kit(); if ( k ) { k.toast( t, kind ? { kind: kind, duration: 7000 } : {} ); } }
	function loading( label ) { var k = kit(); return k && k.loadingHtml ? k.loadingHtml( label ) : '<p>' + esc( label ) + '</p>'; }
	function qs( sel, el ) { return ( el || document ).querySelector( sel ); }
	function qsa( sel, el ) { return Array.prototype.slice.call( ( el || document ).querySelectorAll( sel ) ); }
	function bare( e ) { return String( ( e && e.number ) || '' ).replace( /^#/, '' ); }
	/** The estimate as words: "#1234", or "this estimate" when it has no number yet. */
	function numText( e ) { return bare( e ) ? '#' + bare( e ) : 'this estimate'; }
	function num( e ) { return esc( numText( e ) ); }
	/** Which estimate a sheet is about, said first: the number and the customer as the list shows them. */
	function estHead( e ) {
		var c = String( ( e && e.customer ) || '' ).trim();
		return '<p class="zest-fu-est">Estimate ' + ( bare( e ) ? num( e ) : '' ) + ( c ? ' <span>' + ( bare( e ) ? '&middot; ' : '' ) + esc( c ) + '</span>' : '' ) + '</p>';
	}
	function when( utc ) {
		if ( ! utc ) { return ''; }
		var d = new Date( String( utc ).replace( ' ', 'T' ) + 'Z' );
		return isNaN( d ) ? '' : d.toLocaleString( undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' } );
	}
	/** What this screen shows of the estimate's follow-up, sent back with every write ("0" = none). */
	function tokenOf( e ) { return ( e && e.followup && e.followup.token ) ? e.followup.token : '0'; }
	/** The server said the follow-up changed under this screen: draw the list again. */
	function changed( err ) {
		var b = err && err.body;
		var code = b && b.data && typeof b.data === 'object' ? b.data.code : '';
		if ( code === 'changed' ) { S.reload(); return true; }
		return false;
	}
	function bell( channels ) {
		return ( channels || [] ).map( function ( c ) { return c === 'in_app' ? 'the bell' : c; } ).join( ', ' );
	}

	/* ------------------------------------------------------------------ the strip under a row */

	/** What happened to the billing line, as a sentence (or nothing). */
	function lineState( f, withRetry ) {
		if ( f.doc_state === 'failed' ) {
			return '<p class="zest-fu-warn" role="status">The line on the billing estimate was not written' + ( f.doc_why ? ' (' + esc( f.doc_why ) + ')' : '' ) + '.'
				+ ( withRetry ? ' <button type="button" class="zest-fu-link" data-fu-act="retry">Try again</button>' : '' ) + '</p>';
		}
		if ( f.state === 'done' && f.doc_state === 'skipped' ) {
			return '<p class="zest-fu-muted" role="status">The billing system was left as it is' + ( f.doc_why ? ': ' + esc( f.doc_why ) : '' ) + '.</p>';
		}
		if ( f.state === 'done' && f.doc_state === 'written' ) {
			return '<p class="zest-fu-muted">The billing estimate has the short line.</p>';
		}
		return '';
	}

	function stripHtml( e ) {
		var f = e.followup, can = !! S.meta.can_send, h = '';
		var history = ( f || e.followup_history ) ? '<button type="button" class="zest-fu-link" data-fu-act="history">History</button>' : '';
		// Look only (an account in Privacy View): what happened, in words, and History. No button that re-sends,
		// answers, reopens, tries the billing system again or takes anything back.
		if ( S.meta.read_only ) {
			if ( f && f.words ) {
				var atRo = when( f.state === 'open' ? f.asked : ( f.when || f.asked ) );
				h += '<p class="zest-fu-words">' + ( f.declined ? '<span class="zest-fu-tag">Declined</span> ' : '' )
					+ esc( f.words ) + ( atRo ? ' <span class="zest-fu-muted">&middot; ' + esc( atRo ) + '</span>' : '' ) + '</p>' + lineState( f, false );
			}
			return h + ( history ? '<div class="zest-fu-acts">' + history + '</div>' : '' );
		}
		if ( f && f.words ) {
			var at = when( f.state === 'open' ? f.asked : ( f.when || f.asked ) );
			h += '<p class="zest-fu-words' + ( f.mine ? ' zest-fu-words--mine' : '' ) + '">'
				+ ( f.mine ? '<span class="zest-fu-tag">Yours to do</span> ' : '' )
				+ ( f.declined ? '<span class="zest-fu-tag">Declined</span> ' : '' )
				+ esc( f.words ) + ( at ? ' <span class="zest-fu-muted">&middot; ' + esc( at ) + '</span>' : '' ) + '</p>' + lineState( f, true );
		}
		var b = [];
		if ( e.can_follow ) {
			if ( f && f.mine ) {
				// Asked of this person: the three answers, one tap away.
				b.push( '<button type="button" class="zdz-btn zdz-btn--primary zdz-btn--sm" data-fu-act="answer" data-fu-answer="reached">Reached them</button>' );
				b.push( '<button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-fu-act="answer" data-fu-answer="tried">Tried, no answer</button>' );
				b.push( '<button type="button" class="zdz-btn zdz-btn--secondary zdz-btn--sm" data-fu-act="answer" data-fu-answer="handled">Already handled</button>' );
			} else if ( f && f.declined ) {
				b.push( '<button type="button" class="zest-fu-link" data-fu-act="reopen">Reopen</button>' );
			} else {
				if ( can ) { b.push( '<button type="button" class="zest-fu-link" data-fu-act="send">' + ( f ? 'Send again' : 'Re-send to salesperson' ) + '</button>' ); }
				b.push( '<button type="button" class="zest-fu-link" data-fu-act="answer">' + ( f ? 'Record an answer' : 'Record a follow-up' ) + '</button>' );
			}
		} else if ( f && f.declined ) {
			b.push( '<button type="button" class="zest-fu-link" data-fu-act="reopen">Reopen</button>' );
		}
		// Take back the last thing recorded here. An answer or a try: whoever sees it (the server checks). The ask
		// itself: only whoever may re-send, never the person asked.
		if ( f && ( f.state === 'done' || f.state === 'tried' ) ) { b.push( '<button type="button" class="zest-fu-link" data-fu-act="undo">Recorded by mistake?</button>' ); }
		else if ( f && f.state === 'open' && ( can || f.by_me ) && ! f.mine ) { b.push( '<button type="button" class="zest-fu-link" data-fu-act="undo">Sent by mistake?</button>' ); }
		// History whenever the estimate has any follow-up row: also after its only one was taken back, where what was
		// typed is kept for whoever typed it.
		if ( history ) { b.push( history ); }
		return h + ( b.length ? '<div class="zest-fu-acts">' + b.join( '' ) + '</div>' : '' );
	}

	function decorate( listEl, payload, reload ) {
		if ( ! listEl || ! payload || ! payload.followups ) { return; } // a server without follow-ups: no strips
		S.meta = payload.followups || {};
		S.reload = typeof reload === 'function' ? reload : function () {};
		S.byId = {};
		( payload.rows || [] ).forEach( function ( e ) { if ( e && e.id && ! S.byId[ e.id ] ) { S.byId[ e.id ] = e; } } );
		qsa( '.zest-fu-strip', listEl ).forEach( function ( el ) {
			var e = S.byId[ el.getAttribute( 'data-fu-id' ) ];
			if ( ! e ) { return; }
			el.innerHTML = stripHtml( e );
			// Named for a screen reader as its estimate's own, and each control says which estimate it acts on.
			el.setAttribute( 'role', 'group' );
			el.setAttribute( 'aria-label', 'Follow-up for estimate ' + numText( e ) );
			qsa( '[data-fu-act]', el ).forEach( function ( b ) { b.setAttribute( 'aria-label', b.textContent + ', estimate ' + numText( e ) ); } );
			// Something asked of this person must not sit unseen inside a folded group.
			if ( e.followup && e.followup.mine ) {
				var fold = el.closest( 'details' );
				if ( fold && ! fold.classList.contains( 'zest-fu-declined' ) ) { fold.open = true; }
			}
		} );
		if ( ! listEl._zestFuBound ) {   // one listener for the list, however often it is drawn again
			listEl._zestFuBound = true;
			listEl.addEventListener( 'click', function ( ev ) {
				var btn = ev.target && ev.target.closest ? ev.target.closest( '[data-fu-act]' ) : null;
				var strip = btn && btn.closest( '.zest-fu-strip' );
				if ( ! btn || ! strip ) { return; }
				ev.preventDefault();
				ev.stopPropagation();
				var e = S.byId[ strip.getAttribute( 'data-fu-id' ) ];
				if ( ! e ) { return; }
				var act = btn.getAttribute( 'data-fu-act' );
				if ( S.meta.read_only && act !== 'history' ) { return; }
				if ( ! kit() ) { strip.insertAdjacentHTML( 'beforeend', '<p class="zest-fu-warn" role="alert">Follow-ups need a newer Zorderz theme. Reload the app.</p>' ); return; }
				if ( act === 'send' ) { openSend( e ); }
				else if ( act === 'answer' ) { openAnswer( e, btn.getAttribute( 'data-fu-answer' ) || '' ); }
				else if ( act === 'history' ) { openHistory( e ); }
				else if ( act === 'reopen' ) { openReopen( e ); }
				else if ( act === 'retry' ) { retry( e, btn ); }
				else if ( act === 'undo' ) { openUndo( e ); }
			} );
			// A key pressed on one of the strip's controls is that control's, never the row's ("open this estimate").
			listEl.addEventListener( 'keydown', function ( ev ) {
				if ( ev.target && ev.target.closest && ev.target.closest( '.zest-fu-strip' ) ) { ev.stopPropagation(); }
			} );
		}
	}

	/* ------------------------------------------------------------------ 1. re-send to the salesperson */

	function openSend( e ) {
		var k = kit();
		var sh = k.sheet( {
			title: 'Re-send ' + numText( e ) + ' to salesperson',
			body: '<div class="zest-fu">' + estHead( e ) + '<div data-fu-body>' + loading( 'Looking up who is on estimate ' + numText( e ) ) + '</div></div>',
			actions: [
				{ label: 'Re-send ' + numText( e ), kind: 'primary', onClick: function ( sheet ) { return send( e, sheet ); } },
				{ label: 'Cancel' }
			]
		} );
		function off() {
			var go = qs( '.zdz-sheet-actions .zdz-btn--primary', sh.el );
			if ( go ) { go.disabled = true; }
		}
		post( 'zest_fu_people', { id: e.id } ).then( function ( r ) {
			var b = qs( '[data-fu-body]', sh.el );
			if ( ! b ) { return; }
			var people = ( r.people || [] ).filter( function ( p ) { return +p.id !== +r.me; } );
			if ( ! people.length ) {
				var codes = ( r.codes || [] ).join( ' & ' );
				b.innerHTML = '<p>' + ( ( r.people || [] ).length
					? 'You are the salesperson on estimate ' + num( e ) + '. Record the follow-up yourself.'
					: 'Estimate ' + num( e ) + ' names ' + ( codes ? '<strong>' + esc( codes ) + '</strong>, which is not exactly one person on the team' : 'no salesperson' )
						+ ', so there is nobody to send it to. Set the salesperson on the estimate first.' ) + '</p>';
				off();
				return;
			}
			b.innerHTML = '<p>They get a notice (the bell, and a push if theirs is on) asking them to follow up with the customer on estimate ' + num( e ) + ' and say how it went. Nothing is sent to the customer.</p>'
				+ '<fieldset class="zest-fu-set"><legend>Send it to</legend>'
				+ people.map( function ( p, i ) {
					return '<label class="zest-fu-check"><input type="radio" name="fu-to" value="' + esc( p.id ) + '"' + ( i === 0 ? ' checked' : '' ) + '> '
						+ '<span><strong>' + esc( p.initials || p.name ) + '</strong>' + ( p.initials && p.name ? ' <span class="zest-fu-muted">' + esc( p.name ) + '</span>' : '' ) + '</span></label>';
				} ).join( '' ) + '</fieldset>'
				+ '<label class="zest-fu-label" for="fu-ask-note">A note for them (optional)</label>'
				+ '<textarea id="fu-ask-note" class="zest-fu-text" rows="3" maxlength="' + ( +S.meta.max_note || 1000 ) + '"></textarea>'
				+ '<p class="zest-fu-muted">The note stays in the app. It is not put on the billing estimate.</p>';
		} ).catch( function ( err ) {
			var b = qs( '[data-fu-body]', sh.el );
			if ( b ) { b.innerHTML = '<p class="zest-fu-err" role="alert">' + esc( ( err && err.message ) || 'That did not work. Try again.' ) + '</p>'; }
			off();
		} );
	}

	function send( e, sheet ) {
		var to = qs( 'input[name="fu-to"]:checked', sheet.el );
		if ( ! to ) { return Promise.reject( new Error( 'Pick who to send it to.' ) ); }
		var note = ( qs( '#fu-ask-note', sheet.el ) || {} ).value || '';
		return post( 'zest_fu_send', { id: e.id, to: to.value, note: note, expect: tokenOf( e ) } ).then( function ( r ) {
			var name = ( r.to && ( r.to.initials || r.to.name ) ) || 'them';
			var told = r.told || [];
			if ( r.again ) { say( 'Already sent to ' + name + ' a moment ago.' ); }
			else if ( told.length ) { say( 'Sent to ' + name + ' (' + bell( told ) + ').', 'success' ); }
			else { say( 'Recorded for ' + name + ', but no notice went: their notices for this are off. They will see it on their open estimates.', 'error' ); }
			S.reload();
		}, function ( err ) {
			if ( changed( err ) ) { sheet.close( 'changed' ); say( ( err && err.message ) || 'This follow-up changed. Look again.', 'error' ); return false; }
			throw err;
		} );
	}

	/* ------------------------------------------------------------------ 2. the answer */

	function openAnswer( e, preset ) {
		var k = kit();
		var f = e.followup;
		var waiting = !! ( f && ( f.state === 'open' || f.state === 'tried' ) );
		var outs = S.meta.outcomes || [], chans = S.meta.channels || [];
		var pick = preset || 'reached';
		var body = '<div class="zest-fu" data-fu-body>' + estHead( e )
			+ ( waiting && f.by ? '<p class="zest-fu-muted">' + esc( f.by ) + ' asked' + ( f.to ? ' ' + esc( f.to ) : '' ) + ' to follow up on it.</p>' : '' )
			+ '<fieldset class="zest-fu-set"><legend>How did it go?</legend>'
			+ [ [ 'reached', 'Reached them' ], [ 'tried', 'Tried, no answer' ], [ 'handled', 'Already handled it earlier' ] ].map( function ( a ) {
				return '<label class="zest-fu-check"><input type="radio" name="fu-answer" value="' + a[ 0 ] + '"' + ( a[ 0 ] === pick ? ' checked' : '' ) + '> ' + a[ 1 ] + '</label>';
			} ).join( '' ) + '</fieldset>'
			+ '<div data-fu-said><label class="zest-fu-label" for="fu-outcome">What did the customer say?</label>'
			+ '<select id="fu-outcome" class="zest-fu-select"><option value="">Pick one</option>'
			+ outs.map( function ( o ) { return '<option value="' + esc( o.code ) + '" data-declined="' + ( o.declined ? 1 : 0 ) + '">' + esc( o.label ) + '</option>'; } ).join( '' ) + '</select>'
			+ '<p class="zest-fu-banner" data-fu-declines hidden>This moves the estimate to Declined estimates. It can be reopened.</p></div>'
			+ '<label class="zest-fu-label" for="fu-channel">How</label><select id="fu-channel" class="zest-fu-select">'
			+ chans.map( function ( c ) { return '<option value="' + esc( c.code ) + '">' + esc( c.label ) + '</option>'; } ).join( '' ) + '</select>'
			+ '<label class="zest-fu-label" for="fu-note">What they said, in your words</label>'
			+ '<textarea id="fu-note" class="zest-fu-text" rows="4" maxlength="' + ( +S.meta.max_note || 1000 ) + '"></textarea>'
			+ '<p class="zest-fu-muted">Your words stay in the app and, when a CRM is connected, on the estimate\'s lead there. The billing estimate gets only one short fixed line, never what you type.</p>'
			+ '</div>';
		var sh = k.sheet( { title: 'Follow-up on ' + numText( e ), body: body, actions: [
			{ label: 'Save for ' + numText( e ), kind: 'primary', onClick: function ( sheet ) { return saveAnswer( e, sheet ); } },
			{ label: 'Cancel' }
		] } );
		var b = qs( '[data-fu-body]', sh.el );
		function sync() {
			var a = ( qs( 'input[name="fu-answer"]:checked', b ) || {} ).value || 'reached';
			qs( '[data-fu-said]', b ).hidden = a === 'tried';
			// A voicemail is a try; meeting in person is a reach (the contact log's rule, and the server's).
			qsa( '#fu-channel option', b ).forEach( function ( o ) {
				var no = ( o.value === 'voicemail' && a !== 'tried' ) || ( o.value === 'in_person' && a === 'tried' );
				o.disabled = no; o.hidden = no;
			} );
			var ch = qs( '#fu-channel', b );
			if ( ch.selectedOptions[ 0 ] && ch.selectedOptions[ 0 ].disabled ) { ch.value = 'call'; }
			var o = qs( '#fu-outcome', b ).selectedOptions[ 0 ];
			qs( '[data-fu-declines]', b ).hidden = ! ( a !== 'tried' && o && o.getAttribute( 'data-declined' ) === '1' );
		}
		qsa( 'input[name="fu-answer"]', b ).forEach( function ( r ) { r.addEventListener( 'change', sync ); } );
		qs( '#fu-outcome', b ).addEventListener( 'change', sync );
		sync();
	}

	function saveAnswer( e, sheet ) {
		var b = sheet.el;
		var answer = ( qs( 'input[name="fu-answer"]:checked', b ) || {} ).value || '';
		var outcome = answer === 'tried' ? '' : qs( '#fu-outcome', b ).value;
		var note = qs( '#fu-note', b ).value || '';
		if ( answer !== 'tried' && ! outcome ) { return Promise.reject( new Error( 'Pick what the customer said.' ) ); }
		if ( outcome === 'other' && ! note.trim() ) { return Promise.reject( new Error( 'For "Something else", say what they said in the note.' ) ); }
		return post( 'zest_fu_answer', {
			id: e.id, answer: answer, outcome: outcome, channel: qs( '#fu-channel', b ).value, note: note,
			expect: tokenOf( e )
		} ).then( function ( r ) {
			var doc = r.doc || {};
			// Who asked, and whether their notice went (said only when someone asked).
			var asker = r.asker ? ( r.asker.told && r.asker.told.length ? ' ' + ( r.asker.who || 'Whoever asked' ) + ' was told.' : ' ' + ( r.asker.who || 'Whoever asked' ) + ' was not told (their notices for this are off).' ) : '';
			var again = doc.approval === 'void' ? ' It had been approved: approve it again before sending it.' : '';
			if ( doc.state === 'failed' ) { say( 'Saved. The line on the billing estimate was not written' + ( doc.why ? ' (' + doc.why + ')' : '' ) + '. Use "Try again" on the list.' + asker + again, 'error' ); }
			else if ( doc.state === 'skipped' ) { say( 'Saved. The billing system was left as it is: ' + ( doc.why || 'the estimate could not be checked' ) + '.' + asker ); }
			else if ( doc.state === 'written' ) { say( 'Saved. The billing estimate has the short line' + ( doc.why ? ' (' + doc.why + ')' : '' ) + '.' + asker + again, again ? 'error' : 'success' ); }
			else { say( 'Saved.' + asker, 'success' ); }
			S.reload();
		}, function ( err ) {
			if ( changed( err ) ) { sheet.close( 'changed' ); say( ( err && err.message ) || 'This follow-up changed. Look again.', 'error' ); return false; }
			throw err;
		} );
	}

	/* ------------------------------------------------------------------ 3. history, reopen, retry */

	function historyHtml( rows ) {
		if ( ! rows.length ) { return '<p>Nothing recorded for this estimate.</p>'; }
		return '<ol class="zest-fu-hist">' + rows.map( function ( f ) {
			var h = '<li><p class="zest-fu-when">' + esc( when( f.asked ) ) + ( f.state === 'superseded' ? ' <span class="zest-fu-tag">replaced by a newer ask</span>' : '' )
				+ ( f.state === 'withdrawn' ? ' <span class="zest-fu-tag">taken back</span>' : '' ) + '</p>';
			if ( f.by ) { h += '<p>' + esc( f.by ) + ' asked ' + esc( f.to || 'the salesperson' ) + ' to follow up.</p>'; }
			if ( f.ask_note ) { h += '<p class="zest-fu-quote">' + esc( f.ask_note ) + '</p>'; }
			( f.tried || [] ).forEach( function ( t ) {
				h += '<p>' + esc( t.who || 'Someone' ) + ' tried' + ( t.channel ? ' <span class="zest-fu-muted">(' + esc( String( t.channel ).toLowerCase() ) + ')</span>' : '' )
					+ ( t.when ? ' <span class="zest-fu-muted">&middot; ' + esc( when( t.when ) ) + '</span>' : '' ) + '</p>';
				if ( t.note ) { h += '<p class="zest-fu-quote">' + esc( t.note ) + '</p>'; }
			} );
			if ( f.answer && f.answer !== 'tried' ) {
				h += '<p><strong>' + esc( f.words ) + '</strong>' + ( f.channel ? ' <span class="zest-fu-muted">(' + esc( String( f.channel ).toLowerCase() ) + ')</span>' : '' )
					+ ( f.when ? ' <span class="zest-fu-muted">&middot; ' + esc( when( f.when ) ) + '</span>' : '' ) + '</p>';
				if ( f.note ) { h += '<p class="zest-fu-quote">' + esc( f.note ) + '</p>'; }
				if ( f.doc_line ) { h += '<p class="zest-fu-muted">On the billing estimate: "' + esc( f.doc_line ) + '"</p>'; }
				else if ( f.doc_state === 'failed' || f.doc_state === 'skipped' ) { h += '<p class="zest-fu-muted">The billing system: ' + ( f.doc_state === 'failed' ? 'not written' : 'left as it is' ) + ( f.doc_why ? ' (' + esc( f.doc_why ) + ')' : '' ) + '.</p>'; }
			}
			if ( f.answer ) {
				if ( f.crm === 'posted' ) { h += '<p class="zest-fu-muted">Noted on the lead in the CRM.</p>'; }
				else if ( f.crm === 'queued' || f.crm === 'posting' ) { h += '<p class="zest-fu-muted">The CRM note is on its way.</p>'; }
				else if ( f.crm === 'none' ) { h += '<p class="zest-fu-muted">No CRM lead was found for it, so the words are kept here only.</p>'; }
				else if ( f.crm === 'failed' ) { h += '<p class="zest-fu-muted">The CRM note did not go. The words are kept here.</p>'; }
			} else if ( f.state === 'open' ) { h += '<p class="zest-fu-muted">No answer yet.</p>'; }
			// What was taken back from it, oldest first. Its typed words only for those the server sends them to.
			( f.undone || [] ).forEach( function ( u ) {
				h += '<p class="zest-fu-undone">' + esc( u.by || 'Someone' ) + ' took back "' + esc( u.words ) + '" (' + ( u.kind === 'ask' ? 'sent' : 'recorded' ) + ' by mistake)'
					+ ( u.when ? ' &middot; ' + esc( when( u.when ) ) : '' ) + '</p>';
				if ( u.note ) { h += '<p class="zest-fu-quote zest-fu-undone">It had said: ' + esc( u.note ) + '</p>'; }
			} );
			return h + '</li>';
		} ).join( '' ) + '</ol>';
	}

	function openHistory( e ) {
		var k = kit();
		var sh = k.sheet( { title: 'Follow-ups on ' + numText( e ), body: '<div class="zest-fu">' + estHead( e ) + '<div data-fu-body>' + loading( 'Loading' ) + '</div></div>', actions: [ { label: 'Close' } ] } );
		post( 'zest_fu_history', { id: e.id } ).then( function ( r ) {
			var b = qs( '[data-fu-body]', sh.el );
			if ( b ) { b.innerHTML = historyHtml( r.rows || [] ); }
		} ).catch( function ( err ) {
			var b = qs( '[data-fu-body]', sh.el );
			if ( b ) { b.innerHTML = '<p class="zest-fu-err" role="alert">' + esc( ( err && err.message ) || 'That did not work. Try again.' ) + '</p>'; }
		} );
	}

	function openReopen( e ) {
		var k = kit();
		k.sheet( {
			title: 'Reopen ' + numText( e ) + '?',
			body: '<div class="zest-fu">' + estHead( e ) + '<p>It goes back among the open estimates. If the estimate has not been accepted, the line on the billing estimate will say it was reopened.</p></div>',
			actions: [
				{ label: 'Reopen ' + numText( e ), kind: 'primary', onClick: function ( sheet ) {
					return post( 'zest_fu_reopen', { id: e.id, expect: tokenOf( e ) } ).then( function ( r ) {
						var doc = r.doc || {};
						// Said as it is: the line changed, or it did not and why (the old line may still be there).
						if ( doc.state === 'written' ) { say( 'Reopened. The billing estimate now says so.' + ( doc.approval === 'void' ? ' It had been approved: approve it again before sending it.' : '' ), 'success' ); }
						else if ( doc.state === 'failed' ) { say( 'Reopened here. The line on the billing estimate was not changed' + ( doc.why ? ' (' + doc.why + ')' : '' ) + ', so it still shows the earlier line. Use "Try again" on the list.', 'error' ); }
						else { say( 'Reopened here. The billing system was left as it is' + ( doc.why ? ': ' + doc.why : '' ) + '.', doc.why ? 'error' : 'success' ); }
						S.reload();
					}, function ( err ) {
						if ( changed( err ) ) { sheet.close( 'changed' ); say( ( err && err.message ) || 'It changed. Look again.', 'error' ); return false; }
						throw err;
					} );
				} },
				{ label: 'Cancel' }
			]
		} );
	}

	function retry( e, btn ) {
		var k = kit(), f = e.followup;
		if ( ! f ) { return; }
		k.busy( btn, function () { return post( 'zest_fu_line_retry', { fu: f.id } ); } ).then( function ( r ) {
			var doc = r.doc || {};
			say( doc.state === 'written' ? 'The billing estimate has the short line now.' : 'Still not written' + ( doc.why ? ' (' + doc.why + ')' : '' ) + '.', doc.state === 'written' ? 'success' : 'error' );
			S.reload();
		}, function ( err ) { say( ( err && err.message ) || 'That did not work. Try again.', 'error' ); } );
	}

	/* ------------------------------------------------------------------ 4. taken back */

	function openUndo( e ) {
		var k = kit(), f = e.followup;
		if ( ! f ) { return; }
		var ask = f.state === 'open', tried = f.state === 'tried';
		var at = when( ask ? f.asked : ( f.when || f.asked ) );
		var what = '<p class="zest-fu-quote">' + esc( f.words ) + ( at ? ' <span class="zest-fu-muted">&middot; ' + esc( at ) + '</span>' : '' ) + '</p>';
		var crmLine = '<li>Its note for the lead in the CRM is dropped if it has not gone yet. If it has, a short correction is added there and the first note stays.</li><li>What was typed stays under History, for you.</li>';
		var say1 = ask
			? '<p>This takes back the request' + ( f.to ? ' sent to ' + esc( f.to ) : '' ) + ', as sent by mistake.' + ( f.to ? ' ' + esc( f.to ) + ' is told.' : '' )
				+ ' If it had replaced an earlier request that was still waiting, that one waits again. Nothing was written to the billing system or the CRM for it.</p>'
			: tried
				? '<p>This takes back the last "tried, no answer", as recorded by mistake.</p><ul class="zest-fu-list">' + crmLine + '</ul>'
				: '<p>This takes the answer back, as recorded by mistake.</p>'
					+ '<ul class="zest-fu-list"><li>Its line comes off the billing estimate' + ( f.doc_state === 'written' || f.doc_state === 'failed' ? ' (an earlier follow-up line that it replaced goes back).' : ': nothing was written there, so the billing system is not touched.' ) + '</li>'
					+ crmLine
					+ ( f.by ? '<li>The estimate waits on ' + esc( f.to || 'the salesperson' ) + ' again' + ( f.by_me ? '.' : ', and ' + esc( f.by ) + ' is told.' ) + '</li>' : '' ) + '</ul>';
		k.sheet( {
			title: ( ask ? 'Take back the re-send of ' : 'Take back this follow-up on ' ) + numText( e ) + '?',
			body: '<div class="zest-fu">' + estHead( e ) + what + say1 + '</div>',
			actions: [
				{ label: 'Take back from ' + numText( e ), kind: 'primary', onClick: function ( sheet ) {
					return post( 'zest_fu_undo', { id: e.id, expect: tokenOf( e ) } ).then( function ( r ) {
						var doc = r.doc || {};
						var told = ( r.told || [] ).map( function ( t ) { return ( t.who || 'They' ) + ( t.told && t.told.length ? ' was told.' : ' was not told (their notices for this are off).' ); } ).join( ' ' );
						var again = doc.approval === 'void' ? ' The estimate had been approved: approve it again before sending it.' : '';
						var head = 'Taken back from ' + numText( e ) + '.';
						// Said as it went: the billing system, then the CRM, then who was told.
						var crm = r.crm === 'dropped' ? ' Its CRM note had not gone yet and was dropped.'
							: ( r.crm === 'corrected' ? ' A correction is on its way to the lead in the CRM; the first note there stays.' : '' );
						var back = r.back ? ' The earlier request stands again (' + r.back + ').' : '';
						var tail = crm + back + ( told ? ' ' + told : '' ) + again;
						if ( doc.state === 'removed' ) { say( head + ' Its line is off the billing estimate.' + tail, again ? 'error' : 'success' ); }
						else if ( doc.state === 'restored' ) { say( head + ' The billing estimate shows the earlier follow-up line again.' + tail, again ? 'error' : 'success' ); }
						else if ( doc.state === 'kept' ) { say( head + ' The line on the billing estimate stays: it is the earlier follow-up\'s, word for word.' + tail, 'success' ); }
						else if ( doc.state === 'clean' ) { say( head + ' The billing estimate did not have the line.' + tail, 'success' ); }
						else if ( doc.state === 'skipped' ) { say( head + ' The billing system was left as it is: ' + ( doc.why || 'the estimate could not be checked' ) + '. Its line may still be on the estimate.' + tail, 'error' ); }
						else { say( head + ( r.kind === 'answer' ? ' The billing system was not touched: nothing had been written there.' : '' ) + tail, 'success' ); }
						S.reload();
					}, function ( err ) {
						if ( changed( err ) ) { sheet.close( 'changed' ); say( ( err && err.message ) || 'It changed. Look again.', 'error' ); return false; }
						throw err;
					} );
				} },
				{ label: 'Cancel' }
			]
		} );
	}

	window.ZESTFollowup = { decorate: decorate, _strip: stripHtml, _history: historyHtml, _state: S };
}() );
