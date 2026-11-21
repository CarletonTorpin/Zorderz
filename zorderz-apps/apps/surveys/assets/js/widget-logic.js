/**
 * Zorderz Surveys — the widget's decisions and sentences, with no page in them.
 *
 * Everything here is a plain function of what the site answered: which customer's notes are shown
 * without a tap, whether a review check should ask again by itself, and what each last line says.
 * The page (widget.js) draws and sends; nothing here touches the page or the network, so the same
 * file runs in a test. What may be SENT is never decided here: the site decides, every time.
 */
( function ( root, factory ) {
	var api = factory();
	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	} else {
		root.ZSVLogic = api;
	}
} )( typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	/** Call outcomes whose customers may get the review e-mail (the site checks again). */
	var SENDABLE = { satisfied: 1, no_primary_account: 1 };

	function countOf( n, one, many ) {
		return n + ' ' + ( n === 1 ? one : many );
	}

	/* ── which customer's notes are showing ─────────────────────────────────────────── */

	/**
	 * Has anybody dealt with this customer yet? Handled = the review e-mail went, or a review is
	 * recorded, or the lead is closed in the CRM (any outcome). Display only: it decides what is
	 * open on the screen, never what may be sent.
	 */
	function isHandled( row ) {
		return !! ( row && ( row.invited || row.reviewed || row.closed ) );
	}

	function notesOf( row ) {
		return row && row.notes ? String( row.notes ).trim() : '';
	}

	/**
	 * A customer nobody has handled, with call notes, is listed with those notes showing: reading
	 * them is what the list is opened for. A stored message from a failed sync ("Error: …") is not
	 * the operator's note, so it is not drawn open as one.
	 */
	function notesFirst( row ) {
		var notes = notesOf( row );
		if ( ! notes || notes.indexOf( 'Error: ' ) === 0 ) {
			return false;
		}
		return ! isHandled( row );
	}

	function hasDetails( row ) {
		return !! ( row && ( row.work || row.date || row.emailed_at ) );
	}

	/** The line under a customer that opens and closes the panel; '' when there is nothing to open. */
	function hintLabel( row, open ) {
		if ( open ) { return 'Tap to close'; }
		if ( notesFirst( row ) ) { return 'Call notes \u00B7 Tap for job details'; }
		if ( notesOf( row ) ) { return 'Tap for call notes'; }
		return hasDetails( row ) ? 'Tap for job details' : '';
	}

	/** The customers the Send button offers: never one already e-mailed or reviewed. */
	function sendable( rows ) {
		return ( rows || [] ).filter( function ( r ) {
			return r && ! r.invited && ! r.reviewed && SENDABLE[ r.status ];
		} );
	}

	/* ── the send line ──────────────────────────────────────────────────────────────── */

	var SKIP_WORDS = {
		lock_unavailable: 'Could not make sure a customer is e-mailed only once just now: not sent. Try again in a minute.',
		row_unreadable: 'Could not read a customer\'s record just now: not sent.',
		crm_closed: 'A lead closed as lost or cancelled in the CRM was not e-mailed.',
		no_email: 'A customer has no usable e-mail address.',
		no_review_destination: 'No review destination is set up yet.',
		not_found: 'A customer is no longer in the list. Reload.'
	};

	/**
	 * The last line of a press of Send. When the site refused a second e-mail it says so (the page
	 * was loaded before another device sent, or a send to that customer was running). This button
	 * never asks to send again.
	 */
	function sendSummary( results, total ) {
		var sent = 0, failed = 0, already = 0, running = 0, held = 0, why = '';
		( results || [] ).forEach( function ( r ) {
			if ( ! r ) { return; }
			if ( r.result === 'sent' ) { sent++; return; }
			if ( r.result === 'failed' ) { failed++; return; }
			if ( r.already_emailed ) { already++; return; }
			if ( r.in_progress ) { running++; return; }
			if ( r.reason && SKIP_WORDS[ r.reason ] ) {
				if ( ! why ) { why = SKIP_WORDS[ r.reason ]; }   // the first such reason is said; one sentence is enough
				return;
			}
			held++;
		} );
		var msg = 'Sent ' + sent + ' of ' + total + '.';
		if ( already ) { msg += ' ' + ( already === 1 ? '1 was already e-mailed and was not sent again.' : already + ' were already e-mailed and were not sent again.' ); }
		if ( running ) { msg += ' A send to ' + running + ' of them was already running: not sent again.'; }
		if ( failed ) { msg += ' ' + failed + ' could not be sent: check ' + ( failed === 1 ? 'that one' : 'those' ) + ' before sending again.'; }
		if ( why ) { msg += ' ' + why; }
		if ( held ) { msg += ' ' + ( held === 1 ? '1 was held back; see the list.' : held + ' were held back; see the list.' ); }
		return msg;
	}

	/* ── one press of Check reviews, to the end ─────────────────────────────────────── */

	/*
	 * The site checks for a limited time on each request and reports who it had no time
	 * for (`deferred`). The page asks again by itself for as long as the last pass got somewhere and
	 * time was the only thing in the way. It stops, and says what is left, when:
	 *   - the review site gave no answer for somebody (`errors`: a rate limit or an outage; asking
	 *     again at once is only refused again);
	 *   - a pass checked nobody;
	 *   - the check could not run at all; or
	 *   - REVIEW_MAX_PASSES passes have run, so a press always ends.
	 * A checked customer is stamped by the site and does not come back in the same press, so those
	 * are added up. Customers left waiting DO come back, so they are read from the LAST pass only.
	 */
	var REVIEW_MAX_PASSES = 8;

	function newTotals() {
		return { checked: 0, found: 0, passes: 0 };
	}

	function addPass( tot, d ) {
		d = d || {};
		tot.passes++;
		tot.checked += d.checked || 0;
		tot.found += d.found || 0;
		return tot;
	}

	/** After a pass was added to the totals: should the page ask again by itself? */
	function shouldCheckAgain( tot, d ) {
		d = d || {};
		return ! d.error && ! ( d.errors > 0 ) && ( d.deferred || 0 ) > 0 && ( d.checked || 0 ) > 0 && tot.passes < REVIEW_MAX_PASSES;
	}

	/** The last line of a review check: what was checked, what was found, what is left and why. */
	function reviewResultText( tot, last ) {
		var d = last || {};
		if ( d.error ) {
			return 'Review check unavailable: ' + d.error;
		}
		var errors = d.errors || 0;
		var left = d.deferred || 0;
		if ( tot.checked === 0 && errors === 0 && left === 0 ) {
			return 'No customers to check right now.';
		}
		var msg = 'Checked ' + countOf( tot.checked, 'customer', 'customers' ) + '.' +
			( tot.found > 0 ? ' ' + countOf( tot.found, 'new review', 'new reviews' ) + '.' : ' No new reviews.' );
		if ( errors > 0 ) {
			msg += ' ' + errors + ' could not be checked just now (no answer from the review site) and ' +
				( errors === 1 ? 'is' : 'are' ) + ' checked again automatically.';
		}
		if ( left > 0 ) {
			msg += ' ' + left + ( left === 1 ? ' was' : ' were' ) + ' not checked this time' +
				( left === 1 ? ' and is' : ' and are' ) + ' checked again automatically.';
		}
		return msg;
	}

	/** A later pass failed or could not run: what the earlier passes did is still said. */
	function reviewStoppedText( tot, why ) {
		return 'Checked ' + countOf( tot.checked, 'customer', 'customers' ) + ', ' +
			( tot.found > 0 ? countOf( tot.found, 'new review', 'new reviews' ) : 'no new reviews' ) +
			', then the check stopped: ' + String( why || 'no answer' ).replace( /[.\s]+$/, '' ) + '. Press Check reviews again.';
	}

	/** A first pass that failed, in a sentence. The site answers 'Unauthorized' to anyone who is not an administrator. */
	function reviewFailedText( why ) {
		if ( why === 'Unauthorized' ) {
			return 'Only an administrator can check reviews.';
		}
		return 'Review check failed: ' + ( why || 'no answer' );
	}

	/** A pass that failed outright: after earlier passes did their work, that is said first. */
	function reviewErrorText( tot, why ) {
		return tot && tot.passes > 0 ? reviewStoppedText( tot, why ) : reviewFailedText( why );
	}

	/* ── the Google reviews panel ───────────────────────────────────────────────────── */

	var GR_WHY = {
		not_ready: 'not connected',
		not_approved: 'waiting for Google to approve access',
		no_token: 'the Google sign-in needs reconnecting',
		no_location: 'no location picked yet',
		denied: 'Google refused',
		unreachable: 'Google could not be reached',
		staging: 'not asked on a copy of the site',
		busy: 'another check was running',
		more_next_run: 'more to read next hour'
	};

	/** Why the last check did not run, in words. An unknown word from the site is shown as letters only. */
	function grWhy( code ) {
		var c = String( code || '' );
		return GR_WHY[ c ] || c.replace( /[^a-z0-9_]/gi, '' ).replace( /_/g, ' ' );
	}

	function grModeText( automatic ) {
		return automatic ? 'Automatic: a sure match is recorded' : 'Watch-only: nothing is recorded until confirmed';
	}

	/** The "checked …" line, or '' before the first check. */
	function grLastText( last ) {
		if ( ! last || ! last.at ) { return ''; }
		return 'checked ' + last.at + ( last.why && ! last.ran ? ' \u00B7 ' + grWhy( last.why ) : '' );
	}

	/**
	 * What one listed review shows: { kind, text, offer }.
	 *   kind  done | none | ask
	 *   text  the words
	 *   offer for kind 'ask': the customers offered, each { id, name, reviewed } (a customer who
	 *         already has a review is named, never offered as a button)
	 */
	function grStatus( item ) {
		var it = item || {};
		if ( ( it.status === 'recorded' || it.status === 'confirmed' ) && it.lead ) {
			return { kind: 'done', text: 'Recorded on ' + it.lead.name + ( it.status === 'recorded' ? ' (automatic)' : '' ), offer: [] };
		}
		if ( it.status === 'dismissed' ) {
			return { kind: 'none', text: 'Not a surveyed customer', offer: [] };
		}
		if ( it.status === 'would' || it.status === 'possible' ) {
			var offer = ( it.cands || [] ).filter( function ( c ) { return c && c.id; } );
			if ( offer.length ) {
				return { kind: 'ask', text: it.status === 'would' ? 'Would record on:' : 'Could be:', offer: offer };
			}
		}
		return { kind: 'none', text: 'No surveyed customer matched', offer: [] };
	}

	/**
	 * How one offered customer is named on a button: the name and the day their follow-up was
	 * opened, as the list shows it. Two customers of one name opened the same day also get their
	 * number, so no two buttons of one review ever read the same.
	 */
	function grOfferLabel( c, cands ) {
		var base = function ( x ) { return String( ( x && x.name ) || '' ) + ( x && x.added ? ' \u00B7 added ' + x.added : '' ); };
		var label = base( c );
		var twins = ( cands || [] ).filter( function ( x ) { return x && base( x ) === label; } ).length;
		return twins > 1 ? label + ' \u00B7 #' + ( parseInt( c.id, 10 ) || 0 ) : label;
	}

	function stars( n ) {
		n = Math.max( 0, Math.min( 5, parseInt( n, 10 ) || 0 ) );
		var s = '';
		for ( var i = 0; i < 5; i++ ) { s += i < n ? '\u2605' : '\u2606'; }
		return s;
	}

	return {
		SENDABLE: SENDABLE,
		REVIEW_MAX_PASSES: REVIEW_MAX_PASSES,
		countOf: countOf,
		isHandled: isHandled,
		notesFirst: notesFirst,
		hasDetails: hasDetails,
		hintLabel: hintLabel,
		sendable: sendable,
		sendSummary: sendSummary,
		newTotals: newTotals,
		addPass: addPass,
		shouldCheckAgain: shouldCheckAgain,
		reviewResultText: reviewResultText,
		reviewStoppedText: reviewStoppedText,
		reviewFailedText: reviewFailedText,
		reviewErrorText: reviewErrorText,
		grWhy: grWhy,
		grModeText: grModeText,
		grLastText: grLastText,
		grStatus: grStatus,
		grOfferLabel: grOfferLabel,
		stars: stars
	};
} );
