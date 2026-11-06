<?php
/**
 * ZANA_Mail_CrossUser — "search a NAMED COLLEAGUE's mailbox" ("did Jordan get any Example Co emails this week?").
 * A pure detector; ZANA_Mail_Relevance only ever routes to the asker's OWN mail.
 *
 * Reading someone else's mail is the inbox app's consented path: the colleague turns on "Allow admin search" on their
 * own mailbox connection, the asker must be a mailbox reader, and every read is logged by the inbox's gatekeeper.
 * This detector only lets the chat ROUTE such a turn there; the inbox resolves the NAME to a consenting colleague on
 * the server and enforces the gate. Nothing here reads mail or trusts a name — it only classifies intent.
 *
 * SAFETY BY CONSTRUCTION:
 *   - needs a MAIL NOUN (email / mail / inbox / message) AND a colleague-inbox SHAPE (get / receive · possessive
 *     "X's mail" · mail "to / for X");
 *   - a SELF / pronoun / generic subject (I, we, me, the supplier, the team…) never fires: those stay the asker's own;
 *   - a leading write verb, or a write instruction anywhere (ZANA_Mail_Compose), never routes to a read;
 *   - the chat fires this only for a mailbox READER (the inbox's ZIB_Gatekeeper::is_reader) and only for a name that
 *     is one of the team's people, so anyone else's turn goes on exactly as before.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Mail_CrossUser {

	/** Subjects that are the asker or a non-person — never a colleague. */
	const STOP = array(
		'i', 'we', 'me', 'us', 'my', 'our', 'you', 'your', 'they', 'them', 'he', 'she', 'it', 'him', 'her',
		'someone', 'anyone', 'somebody', 'everybody', 'everyone', 'nobody', 'no one', 'the', 'a', 'an', 'this', 'that',
		'the supplier', 'the customer', 'the client', 'the vendor', 'the office', 'the team', 'my team', 'the guys',
		'the shop', 'the company', 'the owner', 'the sender', 'the buyer', 'the seller', 'the rep', 'the crew',
	);

	/** A 2-word capture may pick up a leading verb/command ("check Jordan") — drop word 1 if it's one of these. */
	const LEAD_DROP = array(
		'check', 'search', 'see', 'show', 'list', 'read', 'open', 'find', 'look', 'whose', 'into', 'at', 'in', 'on',
		'is', 'was', 'get', 'got', 'any', 'the', 'a', 'an', 'to', 'for', 'about', 'of', 'did', 'does', 'do', 'has',
		'have', 'had', 'i', 'we', 'you', 'they', 'and', 'or', 'but', 'so', 'email', 'emails', 'mail',
		'me', 'up', // "get me Jordan's emails", "look up Jordan's emails" — never part of a name
	);

	/** …or a trailing framing/time word ("Jordan about", "Jordan from") — drop word 2 if it's one of these. */
	const TAIL_DROP = array(
		'about', 'from', 'regarding', 'concerning', 'on', 'in', 'for', 'this', 'that', 'last', 'latest', 'recently',
		'lately', 'today', 'yesterday', 'the', 'a', 'an', 'and', 'or', 'got', 'get', 'gets', 'has', 'have', 'had',
		'is', 'was', 'email', 'emails', 'mail', 'inbox',
		// a participle / connector after the name ("to Jordan asking about…", "for Jordan if…").
		'asking', 'saying', 'telling', 'letting', 'requesting', 'wanting', 'who', 'which', 'if', 'whether', 'with',
		're', 'tomorrow', 'tonight', 'morning', 'afternoon', 'evening', 'week', 'month', 'yet', 'already', 'ever', 'please',
	);

	/**
	 * A determiner / demonstrative / quantifier in FRONT of the capture makes it a noun phrase —
	 * "this individual", "the homeowner", "that customer" — never a colleague's name. Checked on the RAW
	 * capture, because LEAD_DROP strips a leading "the"/"a"/"any" and would hide it.
	 */
	const DETERMINERS = array(
		'the', 'a', 'an', 'my', 'our', 'your', 'his', 'her', 'their', 'its', 'this', 'that', 'these', 'those',
		'said', 'such', 'some', 'any', 'each', 'every', 'another', 'other', 'which', 'whose', 'no',
	);

	/**
	 * A generic role noun is never a colleague's name, with or without a determiner ("this individual",
	 * "customer Robin", "the homeowner"): "Write an email to this individual…" names no colleague.
	 */
	const ROLE_NOUNS = array(
		// Only words that are never a first name or a surname in practice — "Guy", "Friend", "Sellers" are
		// real names, and the chat's staff check (ZANA_Mail_Route::is_staff_name) already refuses anyone who is not
		// one of our people. This list only keeps the obvious role words out of the person slot.
		'individual', 'individuals', 'person', 'persons', 'people', 'customer', 'customers', 'client', 'clients',
		'homeowner', 'homeowners', 'lady', 'ladies', 'gentleman', 'gentlemen', 'family', 'couple',
		'prospect', 'prospects', 'contact', 'contacts', 'neighbor', 'neighbors', 'neighbour', 'neighbours',
		'tenant', 'tenants', 'landlord', 'recipient', 'recipients', 'sender', 'senders', 'vendor', 'vendors',
		'supplier', 'suppliers', 'folks', 'team', 'crew', 'office', 'company', 'caller', 'staff', 'husband',
		'wife', 'everyone', 'anyone', 'someone', 'somebody', 'anybody', 'everybody', 'nobody',
	);

	/**
	 * Detect a "search <colleague>'s mailbox" turn.
	 *
	 * @param string $user_message the caller's verbatim turn.
	 * @return array { fire:bool, person:string, query:string, reason:string }
	 */
	public static function detect( string $user_message ): array {
		$no  = array( 'fire' => false, 'person' => '', 'query' => '', 'reason' => '' );
		$raw = trim( $user_message );
		if ( '' === $raw ) {
			return $no;
		}
		$mq = strtolower( $raw );

		// A message that LEADS with a draft/send verb is a WRITE, not a read.
		if ( preg_match( '/^(?:can\s+you\s+|could\s+you\s+|please\s+|pls\s+|hey\s+|now\s+|go\s+|just\s+)*(?:draft|compose|repl(?:y|ies)|respond|forward|write\s+(?:back|a|an|the)\b|send|email)\b/', $mq ) ) {
			return $no;
		}
		// …and so is an instruction to write ANYWHERE in the turn: the guard above only sees the start, and a person
		// who pasted a customer's text and ended with "Write an email to this individual asking…" wants a draft.
		if ( class_exists( 'ZANA_Mail_Compose' ) && ZANA_Mail_Compose::is_compose( $raw ) ) {
			return $no;
		}
		// Must reference mail at all.
		if ( ! preg_match( '/\b(?:e-?mails?|mail|mailbox|inbox|messages?)\b/', $mq ) ) {
			return $no;
		}

		$person = '';
		$reason = '';
		$name   = "([a-z][a-z.'\\-]*(?:\\s+[a-z][a-z.'\\-]*)?)"; // 1–2 name tokens

		// Shape 1: "<Name> (get|got|receive|received|have|had) …" — the colleague's own inbox.
		if ( preg_match( '/\b(?:did|does|do|has|have|had|is|was|were|will|would|whether)\s+' . $name . '\s+(?:(?:ever|already|actually|really|even|just|recently|also|still|yet)\s+)*(?:get|got|gotten|getting|receiv\w+|have|had)\b/i', $raw, $m ) ) {
			$person = $m[1];
			$reason = 'colleague_received';
		} elseif ( preg_match( '/\b' . $name . "'?s\\s+(?:e-?mails?|mail|mailbox|inbox|messages?)\\b/i", $raw, $m ) ) {
			// Shape 2: possessive "<Name>'s email / inbox".
			$person = $m[1];
			$reason = 'colleague_possessive';
		} elseif ( preg_match( '/\b(?:e-?mails?|mail|messages?)\s+(?:to|for)\s+' . $name . '\b/i', $raw, $m ) ) {
			// Shape 3: "mail to / for <Name>".
			// The ASKER as the sender — "have I sent emails to Jordan…", "did I email Jordan…", "my emails to Jordan",
			// "what did we send Jordan" — is the asker's OWN sent mail, never Jordan's mailbox. (Only Shape 3: "did Jordan
			// get the email I sent him?" is still Jordan's inbox — Shape 1.)
			if ( preg_match( '/\b(?:have|has|did|had|do|does)\s+(?:i|we)\s+(?:(?:ever|already|actually|really|even|just|recently|also|still|yet)\s+)*(?:send|sent|e-?mail(?:ed)?|mail(?:ed)?|write|wrote|written|forward(?:ed)?|repl(?:y|ied)|messag(?:e|ed))\b/', $mq )
				|| preg_match( '/\b(?:i|we)\s+(?:(?:ever|already|just|recently|also|never)\s+)*(?:sent|e-?mailed|mailed|wrote|forwarded|replied|messaged)\b/', $mq )
				|| preg_match( '/\b(?:my|our)\s+(?:sent\s+)?(?:e-?mails?|mail|messages?|replies)\s+(?:to|for)\b/', $mq ) ) {
				return $no;
			}
			$person = $m[1];
			$reason = 'colleague_addressed';
		} else {
			return $no;
		}

		// "this individual" / "the homeowner" / "that customer" is a noun phrase, not a name. Test the
		// RAW capture's first word — the LEAD_DROP trim below would strip "the" and hide it.
		$raw_toks = preg_split( '/\s+/', strtolower( trim( $person ) ) );
		if ( is_array( $raw_toks ) && in_array( (string) $raw_toks[0], self::DETERMINERS, true ) ) {
			return $no;
		}

		// A 1–2 word capture can pick up a leading command ("check Jordan") or a trailing framing word
		// ("Jordan about") — trim those so the person is just the name ("Jordan", "Jordan Example").
		$ptoks = preg_split( '/\s+/', trim( $person ) );
		if ( is_array( $ptoks ) && 2 === count( $ptoks ) && in_array( strtolower( $ptoks[0] ), self::LEAD_DROP, true ) ) {
			array_shift( $ptoks );
		}
		if ( is_array( $ptoks ) && 2 === count( $ptoks ) && in_array( strtolower( $ptoks[1] ), self::TAIL_DROP, true ) ) {
			array_pop( $ptoks );
		}
		$person = implode( ' ', (array) $ptoks );

		$person = trim( $person, " \t\"'\xe2\x80\x99" ); // strip surrounding quotes / possessive apostrophe (keep internal, e.g. O'Brien)
		$pl     = strtolower( $person );
		if ( '' === $pl
			|| in_array( $pl, self::STOP, true )
			|| preg_match( '/^(?:the|a|an|my|our|your|his|her|their|its|this|that|these|those)\b/', $pl )
			|| preg_match( '/^(?:e-?mails?|mail|inbox|mailbox|messages?)$/', $pl )
			|| preg_match( '/\d/', $person )
			|| array_intersect( preg_split( '/\s+/', $pl ), self::ROLE_NOUNS ) ) { // a role, not a name
			return $no; // self / pronoun / generic / not a name → the asker's own mail, untouched
		}

		return array(
			'fire'   => true,
			'person' => $person,
			'query'  => self::extract_topic( $mq, $pl ),
			'reason' => $reason,
		);
	}

	/**
	 * The topic to search the colleague's mailbox for: an explicit "about/from <topic>", else the
	 * modifier right before the mail noun ("any Example Co emails" → "example co"). '' is a
	 * safe fallback — the bridge then lists their newest mail. PURE.
	 */
	public static function extract_topic( string $mq, string $person = '' ): string {
		// (a) explicit topic / sender framing.
		if ( preg_match( '/\b(?:about|regarding|concerning|from)\s+(.+)$/', $mq, $m ) ) {
			$t = self::clean_topic( $m[1], $person );
			if ( '' !== $t ) {
				return $t;
			}
		}
		// (b) the words before the FIRST mail noun (drop the person + framing/verb words).
		if ( preg_match( '/^(.*?)\b(?:e-?mails?|mail|mailbox|inbox|messages?)\b/', $mq, $m ) ) {
			$t = self::clean_topic( $m[1], $person );
			if ( '' !== $t ) {
				return $t;
			}
		}
		return '';
	}

	/** Reduce a candidate phrase to a clean topic, or '' if there isn't a usable one. */
	private static function clean_topic( string $s, string $person = '' ): string {
		$s = strtolower( trim( $s ) );
		if ( '' !== $person ) {
			$s = (string) preg_replace( '/\b' . preg_quote( $person, '/' ) . '\b/', ' ', $s );
		}
		// cut at a framing / time word or clause punctuation.
		$parts = preg_split( '/\b(?:about|regarding|concerning|from|on|in|for|since|last|latest|this|that|yesterday|today|recently|lately|yet|week|month|day|so\s+far)\b|[.,!?;:](?=\s|$)/', $s );
		$t     = trim( (string) $parts[0] );
		// strip leading question/aux/verb/quantifier words that precede the real topic.
		$t = (string) preg_replace( '/^(?:\s*(?:did|does|do|has|have|had|is|was|were|will|would|whether|any|some|the|a|an|get|got|gotten|getting|receiv\w+|ever|already|actually|really|even|just|new|show|list|check|search|see|me|of)\b\s*)+/', '', $t );
		$t = trim( $t, " \t\"'" );
		if ( strlen( $t ) < 2 || ! preg_match( '/[a-z]/', $t ) || str_word_count( $t ) > 5 ) {
			return '';
		}
		if ( preg_match( '/^(?:me|us|them|him|her|it|you|any(?:one|thing)?|someone|something)$/', $t ) ) {
			return '';
		}
		return $t;
	}
}
