<?php
/**
 * ZANA_Mail_Followup — a question about the STATE of the person's own mail threads: "who am I waiting to hear back
 * from?", "who needs a reply from me?", "what did I send this week?". A pure detector.
 *
 * When it fires, the chat answers from the inbox app's thread state (ZIB_ZANA_Bridge::followups: body-free, the
 * person's own mail only), never from the billing or CRM records.
 *
 * WHO HOLDS THE BALL is the whole classification, and the tricky part:
 *   • needs_reply    — the PERSON owes the reply: "waiting on me", "needs a reply FROM me", "I owe / I haven't
 *                      replied / did I reply", "unanswered".
 *   • awaiting_reply — the OTHER party owes: "owes ME a reply", "waiting to hear back", "haven't heard back",
 *                      "who hasn't replied to me", "no reply yet".
 *     "waiting ON me" (they wait for MY action: I owe) is the opposite of "owe ME a reply" (they owe me: I wait),
 *     though both name "me" — so needs is tested BEFORE awaiting and claims "waiting on me" first.
 *   • sent_log       — a period question about what the PERSON SENT.
 *
 * SAFETY / SCOPE: only a genuine mailbox thread-state question fires. A billing pipeline question ("who hasn't paid",
 * "open estimates", "waiting on payment") is vetoed unless the ask names the mail / reply medium. A message that leads
 * with a draft / send verb, or holds a write instruction anywhere (ZANA_Mail_Compose), is a compose and declines.
 * The shared device is refused by the caller.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Mail_Followup {

	/**
	 * Is this a mailbox thread-state / sent-log question, and of which kind?
	 *
	 * @param string $user_message the person's words for this turn.
	 * @return array { fire:bool, kind:string, period:string, reason:string }
	 *               kind ∈ {'','awaiting_reply','needs_reply','sent_log'}
	 */
	public static function detect( string $user_message ): array {
		$no  = array( 'fire' => false, 'kind' => '', 'period' => '', 'reason' => '' );
		$raw = trim( $user_message );
		$m   = strtolower( $raw );
		if ( '' === $m ) {
			return $no;
		}

		// A message that LEADS with a draft/send verb is a WRITE, not a status question —
		// it keeps its own compose path (the email draft). "who do I owe a reply" (a
		// status question) passes; "reply to everyone waiting" (a compose) is declined.
		if ( preg_match( '/^(?:can\s+you\s+|could\s+you\s+|please\s+|pls\s+|hey\s+|now\s+|go\s+ahead\s+and\s+|go\s+)*(?:draft|compose|repl(?:y|ies)|respond|forward|write\s+(?:back|a|an|the)\b|send|e-?mail)\b/i', $m ) ) {
			return $no;
		}
		// …and a write instruction ANYWHERE in the turn (the shared guard). "I haven't heard back
		// from you yet" inside a pasted customer message reads as awaiting_reply; the user's "Reply to her
		// saying we can come Tuesday" after it is the actual request — a compose, never a status digest.
		if ( class_exists( 'ZANA_Mail_Compose' ) && ZANA_Mail_Compose::is_compose( $raw ) ) {
			return $no;
		}

		$mm = ' ' . $m . ' ';

		// Billing / CRM pipeline VETO — these belong to the billing records, not the
		// mailbox, UNLESS the ask also names the mail/reply medium ("who owes me a REPLY").
		$reply_medium = (bool) preg_match( '/\b(repl(?:y|ies|ied)|respond(?:ed)?|response|answer(?:ed)?|e-?mail(?:ed|s)?|mail|inbox|mailbox|message[ds]?|wrote|written|hear(?:d)?\s+back|get(?:ting)?\s+back|got\s+back)\b/i', $mm );
		$billing      = (bool) preg_match( '/\b(invoice[ds]?|estimate[ds]?|quote[ds]?|paid|unpaid|overdue|balance|owe[sd]?\s+(?:me\s+)?money|payment[s]?|pipeline|open\s+estimate|deposit|collect(?:ed|ion)?)\b/i', $mm );
		if ( $billing && ! $reply_medium ) {
			return $no;
		}

		// ── sent_log — a period question about what the PERSON SENT ──
		if ( preg_match( '/\bwhat\b[^?.!]*\bi\b[^?.!]*\bsen[dt]\b/', $mm )
			|| preg_match( '/\b(?:e-?mails?|messages?)\s+i\s+sen[dt]\b/', $mm )
			|| preg_match( '/\bhow\s+many\b[^?.!]*\bi\b[^?.!]*\b(?:send|sent)\b/', $mm )
			|| preg_match( '/\bi\s+(?:sent|send)\b[^?.!]*\b(?:this|last|today|week|month|year|since|so\s+far|past)\b/', $mm )
			|| preg_match( '/\bmy\s+sent\s+(?:mail|e-?mails?|messages?)\b/', $mm ) ) {
			return array( 'fire' => true, 'kind' => 'sent_log', 'period' => self::period_of( $mm ), 'reason' => 'sent_log' );
		}

		// ── needs_reply — the PERSON owes the reply (tested BEFORE awaiting) ──
		if ( preg_match( '/\bwaiting\s+on\s+me\b/', $mm )
			|| preg_match( '/\bawaiting\b[^?.!]*\bmy\s+(?:reply|response|answer)\b/', $mm )
			|| preg_match( '/\bwaiting\s+(?:for|on)\s+my\s+(?:reply|response|answer)\b/', $mm ) // "my reply" = I owe; a bare "a reply" is directionless → falls to awaiting below
			|| preg_match( '/\b(?:who|what|which|customers?|clients?|any(?:one|body)?)\b[^?.!]*\bneeds?\s+(?:a\s+)?(?:reply|response|answer)\b/', $mm )
			|| preg_match( '/\bneeds?\s+(?:a\s+)?(?:reply|response|answer)\s+from\s+me\b/', $mm )
			|| preg_match( '/\bi\s+(?:still\s+)?(?:owe\b|need\s+to\s+(?:reply|respond|answer|get\s+back)\b)/', $mm )
			|| preg_match( '/\bi\s+(?:still\s+|really\s+|never\s+)?have\s*n.?t[\'’]?\s+(?:replied|answered|responded)\b/u', $mm ) // tolerate a stray apostrophe / adverb: "havent' responded", "I still haven't replied"
			|| preg_match( '/\bi\s+(?:still\s+)?have\s*n.?t[\'’]?\s+gotten\s+back\b/u', $mm )
			|| preg_match( '/\bhaven.?t[\'’]?\s+(?:replied|answered|responded)\s+to\b/u', $mm )      // "…email I haven't responded to"
			// INVERTED auxiliary — "who haven't I answered", "who have I not replied to", "have I answered
			// everyone". "who" is the OBJECT ("I" the subject), so the "I haven't" test above
			// would never match, and the turn would fall through to the estimates. These claim it for needs_reply.
			|| preg_match( '/\bhave\s*n.?t[\'’]?\s+i\s+(?:repl(?:y|ied)|answer(?:ed)?|respond(?:ed)?|gotten\s+back|get(?:ting)?\s+back)\b/u', $mm )
			|| preg_match( '/\bhave\s+i\s+(?:not\s+|yet\s+to\s+|already\s+|ever\s+)?(?:replied|answered|responded|gotten\s+back)\b/u', $mm )
			|| preg_match( '/\bdid\s+i\s+(?:reply|respond|answer|get\s+back)\b/', $mm )
			|| preg_match( '/\bunanswered\b/', $mm ) ) {
			return array( 'fire' => true, 'kind' => 'needs_reply', 'period' => '', 'reason' => 'needs_reply' );
		}

		// ── awaiting_reply — the OTHER party owes; the person is waiting to hear back ──
		if ( preg_match( '/\bowe[sd]?\s+me\s+(?:a\s+)?(?:reply|response|answer|call\s*back|e-?mail)\b/', $mm )
			|| preg_match( '/\bwaiting\s+(?:on|for)\b/', $mm )
			|| preg_match( '/\bwaiting\s+to\s+hear\b/', $mm )
			|| preg_match( '/\bheard?\s+back\s+from\b/', $mm )
			|| preg_match( '/\bhave\s*n.?t\s+heard\s+back\b/', $mm )
			|| preg_match( '/\bhavent\s+heard\s+back\b/', $mm )
			|| preg_match( '/\bno\s+(?:reply|response)\s+(?:yet|from|back)\b/', $mm )
			|| preg_match( '/\bwho\s+has\s*n.?t\s+(?:replied|responded|answered|gotten\s+back|written\s+back)\b/', $mm )
			|| preg_match( '/\bawaiting\b[^?.!]*\b(?:reply|response|them|him|her|customer)\b/', $mm )
			|| preg_match( '/\boutstanding\s+(?:threads?|replies|repl(?:y|ies)|e-?mails?|conversations?|follow[\s-]?ups?)\b/', $mm )
			|| preg_match( '/\bfollow[\s-]?ups?\b[^?.!]*\b(?:owed|outstanding|pending|waiting|do\s+i\s+have)\b/', $mm ) ) {
			return array( 'fire' => true, 'kind' => 'awaiting_reply', 'period' => '', 'reason' => 'awaiting_reply' );
		}

		return $no;
	}

	/** Extract a coarse period token from the (space-padded, lowercased) message. Pure. */
	public static function period_of( string $mm ): string {
		if ( preg_match( '/\btoday\b/', $mm ) ) {
			return 'today';
		}
		if ( preg_match( '/\byesterday\b/', $mm ) ) {
			return 'yesterday';
		}
		if ( preg_match( '/\blast\s+week\b/', $mm ) ) {
			return 'last_week';
		}
		if ( preg_match( '/\blast\s+month\b/', $mm ) ) {
			return 'last_month';
		}
		if ( preg_match( '/\bthis\s+(?:past\s+)?week\b/', $mm ) || preg_match( '/\bpast\s+week\b/', $mm ) || preg_match( '/\bthis\s+wk\b/', $mm ) ) {
			return 'this_week';
		}
		if ( preg_match( '/\bthis\s+month\b/', $mm ) ) {
			return 'this_month';
		}
		if ( preg_match( '/\bthis\s+year\b/', $mm ) || preg_match( '/\bytd\b/', $mm ) || preg_match( '/\byear\s+to\s+date\b/', $mm ) ) {
			return 'this_year';
		}
		if ( preg_match( '/\bweek\b/', $mm ) ) {
			return 'this_week';
		}
		if ( preg_match( '/\bmonth\b/', $mm ) ) {
			return 'this_month';
		}
		return 'recent'; // a bounded default window so "what have I sent" is never unbounded
	}

	/**
	 * Resolve a period token to a [since, until] pair of YYYY-MM-DD (or ['','' ] for no
	 * filter). $today (YYYY-MM-DD) is passed in so this stays PURE and testable — the
	 * chat passes the site's date. "this week" is a ROLLING 7 days (more useful for
	 * "how many did I send this week" than a Monday-anchored week that reads near-empty).
	 */
	public static function range_for_period( string $period, string $today ): array {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $today ) ) {
			return array( '', '' );
		}
		try {
			$d = new DateTimeImmutable( $today . ' 00:00:00', new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return array( '', '' );
		}
		$fmt = static function ( DateTimeImmutable $x ): string {
			return $x->format( 'Y-m-d' );
		};
		switch ( $period ) {
			case 'today':
				return array( $fmt( $d ), $fmt( $d ) );
			case 'yesterday':
				$y = $d->modify( '-1 day' );
				return array( $fmt( $y ), $fmt( $y ) );
			case 'this_week':
				return array( $fmt( $d->modify( '-6 days' ) ), $fmt( $d ) );
			case 'last_week':
				return array( $fmt( $d->modify( '-13 days' ) ), $fmt( $d->modify( '-7 days' ) ) );
			case 'this_month':
				return array( $d->format( 'Y-m-01' ), $fmt( $d ) );
			case 'last_month':
				$first = $d->modify( 'first day of last month' );
				$last  = $d->modify( 'last day of last month' );
				return array( $first->format( 'Y-m-d' ), $last->format( 'Y-m-d' ) );
			case 'this_year':
				return array( $d->format( 'Y-01-01' ), $fmt( $d ) );
			case 'recent':
				return array( $fmt( $d->modify( '-13 days' ) ), $fmt( $d ) );
			default:
				return array( '', '' );
		}
	}
}
