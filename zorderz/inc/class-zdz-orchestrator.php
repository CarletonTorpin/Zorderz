<?php
/**
 * TS Orchestrator — deterministic, Poe-free intent classifier for the dashboard
 * "ask" field.
 *
 * The dashboard sends a free-text query here; this classifies it and, for the
 * read verbs we can answer in place, returns structured data the dashboard
 * renders as an inline card. Everything else returns route:'chat' so the
 * dashboard hands the query to the full Brain Bot chat (where open-ended
 * questions belong).
 *
 * WHY THIS EXISTS: the analytics app's own planner classification is LLM-based (it costs a Poe
 * call), so routing every keystroke through it would be slow and expensive. The
 * read-verb data, however, is available Poe-free through the capability bridges
 * (ZDZ_Contact_Bridge, TSEC_TSA_Bridge). So intent detection is done here with
 * deterministic, typo/punctuation-tolerant PHP, and the answer is assembled from
 * the bridges — no model in the loop. The bridges still enforce tier/kiosk/scope.
 *
 * Classification is intentionally CONSERVATIVE: when unsure, it returns
 * route:'chat' rather than guess — the bot handles anything we don't catch.
 *
 * @package ZorderzTheme
 * @version 1.1.0 (v2.28.11 — closest-match contact guard + secondary-intent chip)
 * @since   theme v2.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Orchestrator {

	/**
	 * Classify a dashboard query and (for read verbs) resolve its data.
	 *
	 * @param string $query              The user's raw text.
	 * @param int    $requesting_user_id Acting user (defaults to current).
	 * @return array {
	 *     @type string $verb    'contact' | 'lookup' | 'chat'
	 *     @type string $route   'inline' (render a card) | 'chat' (hand off)
	 *     @type string $render  card | list | clarify | denied | message | error (when inline)
	 *     @type array  $result  bridge result (when inline)
	 *     @type string $query   the extracted subject/name (when applicable)
	 * }
	 */
	public static function classify( string $query, int $requesting_user_id = 0 ): array {
		$raw = trim( $query );
		$uid = $requesting_user_id > 0 ? $requesting_user_id : get_current_user_id();

		$fallback = array( 'verb' => 'chat', 'route' => 'chat' );
		if ( $raw === '' ) {
			return $fallback;
		}

		$lo = strtolower( $raw );

		// ── 0. COMMISSION for a NAMED salesperson (inline card) ──
		// Checked BEFORE the aggregate guard: "commission for Alex, May 2026" /
		// "Alex's commission for May" should render the inline commission card,
		// not fall through to chat. We require a PERSON name so true aggregates
		// ("total commissions this month", "commission by rep") still go to chat.
		// The bridge is tier-gated and HARD-FORBIDDEN on kiosk — we only call it.
		$comm = self::detect_commission( $raw );
		if ( $comm !== null ) {
			if ( class_exists( 'TSCC_TSA_Bridge' )
				&& method_exists( 'TSCC_TSA_Bridge', 'commission_calc_for_tsa' )
				&& TSCC_TSA_Bridge::is_available() ) {
				$res = TSCC_TSA_Bridge::commission_calc_for_tsa( array(
					'subject'            => $comm['subject'],
					'period'             => $comm['period'],
					'tier'               => '',
					'is_kiosk'           => false,
					'requesting_user_id' => $uid,
				) );
				return array(
					'verb'      => 'commission',
					'route'     => 'inline',
					'render'    => self::commission_render_hint( $res ),
					'query'     => $comm['subject'],
					'focus'     => $comm['focus'] ?? '',
					'result'    => $res,
					'secondary' => self::detect_secondary( $raw, 'commission' ),
				);
			}
			return $fallback;
		}

		// ── Analytics / aggregate framing ALWAYS goes to chat (never a card). ──
		if ( preg_match( '/\b(how many|how much|count|number of|list all|list the|total|revenue|sales|average|avg|compare|trend|ranking|conversion|pipeline|by (salesperson|rep|city|zip|source|product|type|month)|this (month|quarter|year|week)|last (month|quarter|year|week)|ytd|mtd|qtd|breakdown|top \d)\b/i', $lo ) ) {
			return $fallback;
		}

		// ── Email COMPOSE goes to chat (checked before contact: "email <Name>
		// about X" is compose, not a request to look up <Name>'s email). The
		// editable house-voice draft card lives in the full chat. ──
		if ( preg_match( '/\b(?:write|draft|compose|send|shoot|fire off)\s+(?:an?\s+)?(?:e-?mail|note|message)\b/i', $lo )
			|| preg_match( '/\bemail\s+[A-Za-z]/i', $lo ) && preg_match( '/\b(about|regarding|re:|confirm|tell|saying|letting|that|to let)\b/i', $lo ) ) {
			return $fallback;
		}

		// ── 1. CONTACT lookup ── (phone/email/address/"info"/"who is")
		$contact_name = self::detect_contact( $raw );
		if ( $contact_name !== '' ) {
			if ( class_exists( 'ZDZ_Contact_Bridge' ) && ZDZ_Contact_Bridge::is_available() ) {
				// v2.28.8 FAST CACHE — contact lookups are a live FreshBooks hit (~2-3s)
				// and employees look up the same customers repeatedly. Cache the bridge
				// result for 10 min, keyed on the normalized name + requesting user
				// (so per-user disclosure scope is never shared). A hit returns instantly.
				$c_key = 'zdz_contact_' . md5( strtolower( $contact_name ) . '|' . $uid );
				$res   = get_transient( $c_key );
				if ( ! is_array( $res ) || empty( $res['success'] ) ) {
					$res = ZDZ_Contact_Bridge::lookup_for_tsa( array(
						'query'              => $contact_name,
						'tier'               => '',
						'is_kiosk'           => false,
						'requesting_user_id' => $uid,
					) );
					if ( is_array( $res ) && ! empty( $res['success'] ) ) {
						set_transient( $c_key, $res, 10 * MINUTE_IN_SECONDS );
					}
				}
				return array(
					'verb'      => 'contact',
					'route'     => 'inline',
					'render'    => self::contact_render_hint( $res ),
					'query'     => $contact_name,
					'result'    => $res,
					'secondary' => self::detect_secondary( $raw, 'contact' ),
				);
			}
			// Bridge unavailable → let the bot handle it.
			return $fallback;
		}

		// ── 2. DOCUMENT lookup ── (estimate/invoice/quote for a named customer)
		$doc_name = self::detect_doc_lookup( $raw );
		if ( $doc_name !== '' ) {
			if ( class_exists( 'TSEC_TSA_Bridge' )
				&& method_exists( 'TSEC_TSA_Bridge', 'lookup_for_tsa' )
				&& TSEC_TSA_Bridge::is_available() ) {
				$res = TSEC_TSA_Bridge::lookup_for_tsa( array(
					'customer'    => $doc_name,
					'window_days' => 90,
					'tier'        => '',
					'is_kiosk'    => false,
				) );
				// v2.28.7 — honour an explicit doc-type in the query. If the user asked
				// for "estimate(s)" or "invoice(s)" (but not both), filter the returned
				// documents to that doc_type so "estimate for X" doesn't dump every doc.
				$want_est = (bool) preg_match( '/\b(estimate|estimates|quote|quotes|bid|bids|proposal)\b/i', $raw );
				$want_inv = (bool) preg_match( '/\b(invoice|invoices|bill|bills)\b/i', $raw );
				if ( $want_est xor $want_inv ) {
					$want_type = $want_est ? 'estimate' : 'invoice';
					if ( ! empty( $res['documents'] ) && is_array( $res['documents'] ) ) {
						$filtered = array_values( array_filter( $res['documents'], function ( $d ) use ( $want_type ) {
							return isset( $d['doc_type'] ) && strtolower( (string) $d['doc_type'] ) === $want_type;
						} ) );
						// Only apply the filter if it leaves something; otherwise keep all
						// (the customer may only have the other type — better to show those
						// than an empty card).
						if ( count( $filtered ) > 0 ) {
							$res['documents']    = $filtered;
							$res['filtered_type'] = $want_type;
						}
					}
				}
				return array(
					'verb'   => 'lookup',
					'route'  => 'inline',
					'render' => self::doc_render_hint( $res ),
					'query'  => $doc_name,
					'result' => $res,
				);
			}
			return $fallback;
		}

		// ── Everything else → the bot. ──
		return $fallback;
	}

	// ───────────────────────── intent detection ─────────────────────────

	/**
	 * Contact-intent detector — typo/punctuation tolerant (mirrors the dashboard
	 * JS detector, kept here as the server-authoritative copy). Returns the
	 * extracted customer name, or '' if this isn't a contact lookup.
	 *
	 * @param string $s
	 * @return string
	 */
	public static function detect_contact( string $s ): string {
		$raw = $s;
		$info_term = '(?:contact|info|information|details?|number|phone|e-?mail|cell|address|card|reach)';
		$name_re   = '([A-Za-z][\w.\-]*(?:\s+[A-Za-z][\w.\-]*){0,3})';

		// Pass 1 — clean structured patterns (precise name capture).
		if ( preg_match( '/\b' . $info_term . '(?:\s+(?:info|information|details?|number|card))?\s+(?:for|on|of|about)\s+' . $name_re . '/i', $raw, $m ) ) {
			return self::clean_name( $m[1] );
		}
		if ( preg_match( '/\b' . $name_re . '(?:\'s|’s|s\')\s+' . $info_term . '\b/i', $raw, $m ) ) {
			return self::clean_name( $m[1] );
		}
		if ( preg_match( '/\bhow (?:do|can) i (?:reach|contact|get a hold of|call|email)\s+' . $name_re . '/i', $raw, $m ) ) {
			return self::clean_name( $m[1] );
		}
		if ( preg_match( '/^(?:who(?:\'s| is)|look up|lookup|pull up)\s+' . $name_re . '\??$/i', $raw, $m )
			&& ! preg_match( '/^(my|our|all|the|a|an|everyone|anyone)\b/i', $m[1] ) ) {
			return self::clean_name( $m[1] );
		}

		// Pass 2 — punctuation-tolerant fallback for messy typing
		// ("what';s devin riveras info/", "devin rivera info").
		$norm = strtolower( $raw );
		$norm = preg_replace( '/[\'’;:\/\\\\?!.,]+/', ' ', $norm );
		$norm = preg_replace( '/\s+/', ' ', $norm );
		$norm = trim( $norm );

		if ( ! preg_match( '/\b' . $info_term . '\b/i', $norm ) ) {
			return '';
		}
		// Strip the info word + filler/question words; the remainder should be a name.
		$leftover = preg_replace( '/\b' . $info_term . '\b/i', ' ', $norm );
		$leftover = preg_replace( '/\b(what|whats|what s|who|whos|who s|is|are|the|a|an|me|my|our|give|get|find|show|pull|up|look|lookup|please|can|you|i|for|on|of|about|to|s)\b/i', ' ', $leftover );
		$leftover = trim( preg_replace( '/\s+/', ' ', $leftover ) );

		if ( $leftover !== '' && preg_match( '/^[a-z][a-z .\-]{1,40}$/i', $leftover ) ) {
			$toks = array_values( array_filter( explode( ' ', $leftover ) ) );
			if ( count( $toks ) >= 1 && count( $toks ) <= 4
				&& ! preg_match( '/^(everyone|anyone|someone|customer|customers|client|clients|them|people)$/i', $leftover ) ) {
				return self::clean_name( $leftover );
			}
		}
		return '';
	}

	/**
	 * Document-lookup detector (estimate/invoice/quote for a named customer).
	 * Mirrors the dashboard's detectLookupIntent, minus the contact terms.
	 *
	 * @param string $s
	 * @return string
	 */
	public static function detect_doc_lookup( string $s ): string {
		$lo = strtolower( $s );
		// Aggregate framing is never a single-doc lookup.
		if ( preg_match( '/\b(how many|how much|total|average|avg|count|number of|by (salesperson|rep|city|source|product|type|month)|breakdown|compare|trend|ranking|top \d)\b/i', $lo ) ) {
			return '';
		}
		$nouns   = '(?:estimate|estimates|quote|quotes|bid|bids|invoice|invoices|bill|bills|job|jobs|work|paperwork|account)';
		$name_re = '([A-Za-z][\w.\-]*(?:\s+[A-Za-z][\w.\-]*){0,3})';

		// "<noun> for <Name>"
		if ( preg_match( '/\b' . $nouns . '\s+for\s+' . $name_re . '$/i', $s, $m ) ) {
			return self::clean_name( $m[1] );
		}
		// "pull up / show / find / open / look up <Name>'s <noun>"
		if ( preg_match( '/\b(?:pull up|pull|show me|show|find|open|look up|lookup|get)\s+' . $name_re . '(?:\'s|s\')\s+' . $nouns . '/i', $s, $m ) ) {
			return self::clean_name( $m[1] );
		}
		// "<Name>'s <noun>"
		if ( preg_match( '/^' . $name_re . '(?:\'s|s\')\s+' . $nouns . '\b/i', $s, $m ) ) {
			return self::clean_name( $m[1] );
		}
		// "show me / pull up / find the <Name> <noun>"
		if ( preg_match( '/\b(?:pull up|show me|show|find|open|look up|get)\s+(?:the\s+)?' . $name_re . '\s+' . $nouns . '\b/i', $s, $m )
			&& ! preg_match( '/^(my|our|all|any|recent|open|last|this)$/i', $m[1] ) ) {
			return self::clean_name( $m[1] );
		}
		return '';
	}

	/**
	 * Normalize an extracted name: strip leading interrogatives/fillers and stray
	 * possessive fragments, drop trailing connectors.
	 *
	 * @param string $s
	 * @return string
	 */
	public static function clean_name( string $s ): string {
		$name = trim( preg_replace( '/\s+/', ' ', $s ) );
		// Strip a leading possessive fragment ("s Sam Rivera" → "Sam Rivera").
		$name = preg_replace( '/^s\s+/i', '', $name );
		// Strip leading filler verbs/pronouns/determiners repeatedly.
		do {
			$before = $name;
			$name = preg_replace( '/^(?:get|give|grab|find|pull|look|show|me|us|the|a|an|my|our|up|to|for|of|please|can|you|i)\b\s*/i', '', $name );
		} while ( $name !== $before );
		// Drop trailing connector words swept in.
		$name = preg_replace( '/\s+\b(about|re|regarding|confirming|to confirm|and|for|that|when|tomorrow|today|asap)\b.*$/i', '', $name );
		if ( preg_match( '/^(what|who|when|where|why|how|is|are|was|were|do|does|did|could)\b/i', $name ) ) {
			$toks = explode( ' ', $name );
			$name = implode( ' ', array_slice( $toks, max( 0, count( $toks ) - 2 ) ) );
			$name = preg_replace( '/^(on|for|to|of|about|re)\s+/i', '', $name );
		}
		return trim( $name );
	}

	/**
	 * Commission-intent detector. Returns ['subject'=>name, 'period'=>phrase] when
	 * the query is asking for a NAMED person's commission, else null. Requires the
	 * word "commission(s)" AND a person name (or "my"/"me"), so genuine aggregates
	 * ("total commissions this month", "commission by rep", "how much commission
	 * did we pay") are NOT captured — they fall through to chat. The bridge does
	 * the real tier/kiosk gating; this only routes.
	 *
	 * @since theme v2.28.0
	 * @param string $s
	 * @return array|null { @type string $subject; @type string $period }
	 */
	public static function detect_commission( string $s ) {
		$lo = strtolower( trim( $s ) );
		$has_comm = ( strpos( $lo, 'commission' ) !== false );
		// EARNINGS SHORTHAND (v2.28.6) — mirror of client earnFrame. On this sales
		// dashboard an unambiguous personal-earnings question with NO other app
		// keyword means commission even without the word: "how much did Jordan earn",
		// "what did Sam make", "how much does Alex take home". Require a
		// did/does/do <Name> <earn-verb> OR <Name> earned/made/took-home frame.
		$earn_frame = (bool) preg_match( '/\b(?:did|does|do)\s+[A-Za-z][\w.\-]*(?:\s+[A-Za-z][\w.\-]*){0,2}?\s+(?:earn|earns|earned|make|makes|made|get|gets|got|bring in|brings in|brought in|pull|pulls|pulled|take home|takes home|took home)\b/i', $s )
			|| (bool) preg_match( '/\b[A-Za-z][\w.\-]*(?:\s+[A-Za-z][\w.\-]*){0,2}?\s+(?:earned|made|brought in|pulled in|took home)\b/i', $s );
		if ( ! $has_comm && ! $earn_frame ) {
			return null;
		}
		// PERSON-SPECIFIC override (v2.28.6): a possessive-name query like
		// "Jordan's commission rate" / "what's Sam's May commission" is about ONE
		// person and must NOT be swept into the company-wide rollup skip below.
		$person_specific = (bool) preg_match( '/\b[A-Za-z][\w.\-]*(?:\'s|’s)\s+(?:(?:this|last|next)\s+(?:month|quarter|year|week)\s+|(?:january|february|march|april|may|june|july|august|september|october|november|december|q[1-4]|mtd|ytd|\d{4})\s+)?commissions?\b/i', $s );
		// COMPANY-WIDE / cross-person phrasings → chat. ("by product"/"by type" is
		// NOT here — for a named person it's a valid in-card drill-down.)
		if ( ! $person_specific && preg_match( '/\b(by (?:salesperson|rep|person|month)|everyone|all (?:reps|salespeople|staff)|the (?:whole )?team|company-?wide|did we pay|do we pay|we paid|our (?:total |average )?commission|average commission|commission report|how do commissions?|how does commission|commission rates?|commission structure|commission plan|total commissions?)\b/i', $lo ) ) {
			return null;
		}

		// Mirror of the client zdzDetectCommissionInline (v2.28.0). The server is
		// authoritative, so it must recognize the SAME phrasings the client routes
		// to inline — including "<Name> commission" (name before the word) — or the
		// client shows a card but the server re-classifies to chat (the period/
		// subject mismatch). STOP = words that are never a salesperson (periods,
		// launch verbs, filler) used to reject a bad capture.
		$stop_re = '/^(i|have|has|had|commission|commissions|this|last|next|for|of|the|a|an|report|summary|by|total|owed|due|rate|rates|check|breakdown|history|my|me|mine|our|us|we|today|tomorrow|yesterday|month|year|quarter|week|q[1-4]|january|february|march|april|may|june|july|august|september|october|november|december|jan|feb|mar|apr|jun|jul|aug|sep|sept|oct|nov|dec|mtd|ytd|qtd|open|launch|start|pull|show|go|goto|check|view|bring|jump|fire|new|create|add|begin|see|calculate|calc|make|makes|made|making|earn|earns|earned|earning|get|gets|got|pay|paid|paying|average|avg|much|did|do|does|done|is|are|was|were|how|what|whats)\b/i';
		$name_re = '([A-Za-z][\w.\-]*(?:\s+[A-Za-z][\w.\-]*)?)';
		$subject = '';

		// "my commission" / "commission for me" → self (empty subject = self in bridge).
		// v2.28.7 — self also matches first-person EARNINGS phrasing (mirror of client).
		$is_self = (bool) preg_match( '/\b(my|me|mine)\b/i', $lo )
			|| (bool) preg_match( '/\b(?:did|do|have|has|how much (?:have|did))?\s*i\s+(?:have\s+)?(?:earn|earned|make|made|get|got|take home|took home|bring in|brought in|pull|pulled)\b/i', $s )
			|| (bool) preg_match( '/\bmy\s+(?:total\s+)?(?:pay|earnings|take)\b/i', $lo );

		$take = function ( $cand ) use ( $stop_re ) {
			$n = self::clean_name( (string) $cand );
			// Strip an orphaned leading "s " left when "what's"/"that's" backtracks the
			// possessive regex onto the bare "s" (v2.28.6) — no real name is a lone "s".
			$n = preg_replace( '/^s\s+/i', '', $n );
			// v2.28.7 — first-person/auxiliary fragments are self-references, not names.
			if ( preg_match( '/^(?:i|have i|has i|had i|did i|do i|i have|i did)$/i', trim( $n ) ) ) { return ''; }
			// Strip leading prepositions/verbs/fillers the regex may sweep in
			// ("on Alex", "is Alex", "did Alex"). Repeat until none remain —
			// mirrors the client takeName() LEAD list so client/server agree.
			$lead = '/^(?:on|in|into|at|for|of|to|the|a|an|my|our|up|and|or|vs|versus|commission|commissions|toward|towards|count|counts|counted|counting|rate|basis|math|much|how|what|whats|is|are|was|were|do|does|did|could|give|show|get|grab|find|pull|look|tell|me|us|please|can|you|i|see|make|makes|made|earn|earns|earned|pay|paid|break|breakdown|down|drill|detail|details)\s+/i';
			while ( preg_match( $lead, $n ) ) { $n = preg_replace( $lead, '', $n ); }
			$n = trim( $n );
			$n = trim( preg_replace( '/\s+\b(for|in|during|this|last|next|on)\b.*$/i', '', $n ) );
			$n = trim( preg_replace( '/\s+\b(january|february|march|april|may|june|july|august|september|october|november|december|today|tomorrow|yesterday|month|year|quarter|week|mtd|ytd|q[1-4]|\d{4})\b.*$/i', '', $n ) );
			$toks = array_values( array_filter( explode( ' ', $n ) ) );
			if ( count( $toks ) > 2 ) { $toks = array_slice( $toks, -2 ); }
			$last = end( $toks );
			if ( $last && preg_match( '/^[A-Za-z][\w.\-]*[a-rt-z]s$/i', $last ) && strlen( $last ) > 2 ) {
				$toks[ count( $toks ) - 1 ] = substr( $last, 0, -1 );
			}
			$n = implode( ' ', $toks );
			if ( $n === '' || preg_match( $stop_re, $n ) || strlen( $n ) < 2 ) { return ''; }
			return $n;
		};

		// Optional period word that can sit BETWEEN the name and "commission"
		// ("Alex's MAY commission", "Alex's LAST MONTH commission").
		$periodw = '(?:(?:this|last|next)\s+(?:month|quarter|year|week)\s+|(?:january|february|march|april|may|june|july|august|september|october|november|december|q[1-4]|mtd|ytd|\d{4})\s+)?';
		$pname   = '([A-Za-z][\w.\-]*(?:\s+[A-Za-z][\w.\-]*)?)';

		// "<Name>'s [period] commission" (period word optional).
		if ( preg_match( '/\b' . $pname . '(?:\'s|’s)\s+' . $periodw . 'commissions?\b/i', $s, $m ) ) {
			$subject = $take( $m[1] );
		}
		// v2.28.7 — MULTI-PERSON: take the FIRST valid name (the authoritative
		// path returns one subject; the client notes the others in the label).
		if ( $subject === '' ) {
			if ( preg_match( '/\b' . $pname . '\s+(?:and|&|,|or|vs|versus)\s+' . $pname . '(?:\s+[a-z]+)?\s+commissions?\b/i', $s, $mm ) ) {
				$subject = $take( $mm[1] );
			} elseif ( preg_match( '/\bcommissions?\s+(?:for|of)\s+' . $pname . '\s+(?:and|&|,)\s+' . $pname . '/i', $s, $mm ) ) {
				$subject = $take( $mm[1] );
			}
		}
		// "commission(s) for <Name>".
		if ( $subject === '' && preg_match( '/\bcommissions?\s+(?:for|of|owed to|earned by|due to)\s+' . $name_re . '/i', $s, $m ) ) {
			$subject = $take( $m[1] );
		}
		// "rate/basis on <Name>'s commission".
		if ( $subject === '' && preg_match( '/\b(?:rate|basis|math|breakdown)\s+(?:on|for|of)\s+' . $pname . '(?:\'s|’s)?\s+' . $periodw . 'commissions?/i', $s, $m ) ) {
			$subject = $take( $m[1] );
		}
		// "did/does <Name> earn/make/get …".
		if ( $subject === '' && preg_match( '/\b(?:did|does|do)\s+' . $name_re . '\s+(?:earn|earns|earned|make|makes|made|get|gets|got|bring in|brings in|brought in|pull|pulls|pulled|take home|takes home|took home)/i', $s, $m ) ) {
			$subject = $take( $m[1] );
		}
		// "<Name> earned/made/took home …" (the "how much" variant; verb FOLLOWS name).
		if ( $subject === '' && preg_match( '/\b' . $name_re . '\s+(?:earned|made|brought in|pulled in|took home)\b/i', $s, $m ) ) {
			$subject = $take( $m[1] );
		}
		// "... commission ... for <Name>".
		if ( $subject === '' && preg_match( '/\bcommissions?\b.*?\bfor\s+' . $name_re . '/i', $s, $m ) ) {
			$subject = $take( $m[1] );
		}
		// "make up/in/on <Name>'s [period] commission".
		if ( $subject === '' && preg_match( '/\b(?:up|in|on|of|toward|towards|count toward|count towards|counts toward|counted toward)\s+' . $pname . '(?:\'s|’s)?\s+' . $periodw . 'commissions?/i', $s, $m ) ) {
			$subject = $take( $m[1] );
		}
		// "<Name> [period] commission" (loosest; $take rejects non-names).
		if ( $subject === '' && preg_match( '/\b' . $pname . '\s+' . $periodw . 'commissions?\b/i', $s, $m ) ) {
			$subject = $take( $m[1] );
		}

		if ( $subject === '' && ! $is_self ) {
			return null; // commission word but no resolvable person → chat
		}

		// Period phrase (optional). The bridge resolves the concrete window;
		// passing the natural phrase is enough ("May 2026", "last month", "May").
		$period = '';
		if ( preg_match( '/\b((?:january|february|march|april|may|june|july|august|september|october|november|december)(?:\s+\d{4})?|(?:first|second|third|fourth|1st|2nd|3rd|4th)\s+quarter(?:\s+\d{4})?|q[1-4](?:\s+\d{4})?|this (?:month|quarter|year)|last (?:month|quarter|year)|quarter to date|month to date|year to date|year-to-date|qtd|mtd|ytd|\d{4}-\d{2}|\d{1,2}\/\d{4}|\d{4})\b/i', $s, $pm ) ) {
			$period = trim( $pm[1] );
		}

		// SMART FOCUS — which detail section the card should auto-open ('' = none).
		// Mirrors the client; forwarded so the authoritative path agrees.
		$focus = '';
		if ( preg_match( '/\b(by product|by category|product (?:line|split|breakdown|mix)|which products?|what products?)\b/i', $lo ) ) {
			$focus = 'products';
		} elseif ( preg_match( '/\b(which jobs?|what jobs?|jobs? (?:counted|list|breakdown|make up|are in)|per job|by job|list (?:the )?jobs?|what invoices?|which invoices?)\b/i', $lo ) ) {
			$focus = 'jobs';
		} elseif ( preg_match( '/\b(how (?:is|was|are) .*calculated|how .*calculate|what(?:\'s| is) the (?:rate|basis)|the rate on|commission rate|what rate|breakdown of (?:the )?(?:rate|math)|how much .*net|net commissionable)\b/i', $lo ) ) {
			$focus = 'basis';
		}

		return array( 'subject' => $subject, 'period' => $period, 'focus' => $focus );
	}

	// ───────────────────────── render hints ─────────────────────────

	/**
	 * Decide how the dashboard should render a contact result.
	 *
	 * @param array $res ZDZ_Contact_Bridge result.
	 * @return string
	 */
	private static function contact_render_hint( array $res ): string {
		$contact = is_array( $res['contact'] ?? null ) ? $res['contact'] : array();
		$has_detail = ! empty( $contact['phone'] ) || ! empty( $contact['email'] ) || ! empty( $contact['address'] );
		if ( ! empty( $res['error'] ) ) {
			return 'error';
		}
		if ( ! empty( $res['needs_clarify'] ) ) {
			return 'clarify';
		}
		if ( ! empty( $res['denied'] ) ) {
			return 'denied';
		}
		if ( $has_detail ) {
			return 'card';
		}
		return 'message';
	}

	/**
	 * Decide how the dashboard should render a document-lookup result.
	 *
	 * @param array $res TSEC_TSA_Bridge::lookup_for_tsa result.
	 * @return string
	 */
	private static function doc_render_hint( array $res ): string {
		if ( ! empty( $res['error'] ) ) {
			return 'error';
		}
		if ( ! empty( $res['needs_clarify'] ) ) {
			return 'clarify';
		}
		$docs = $res['documents'] ?? array();
		if ( empty( $docs ) ) {
			return 'message';
		}
		return count( $docs ) === 1 ? 'card' : 'list';
	}

	/**
	 * Decide how the dashboard renders a commission result.
	 *   error   — config/hard error
	 *   denied  — tier/kiosk refusal (the bridge sets a friendly message)
	 *   clarify — couldn't match the salesperson (message offers a retry)
	 *   card    — a real figure to show (with the expandable breakdown)
	 *   message — fallback text
	 *
	 * @since theme v2.28.0
	 * @param array $res TSCC_TSA_Bridge::commission_calc_for_tsa result.
	 * @return string
	 */
	private static function commission_render_hint( array $res ): string {
		if ( ! empty( $res['error'] ) ) {
			return 'error';
		}
		if ( ! empty( $res['denied'] ) ) {
			return 'denied';
		}
		if ( empty( $res['success'] ) ) {
			return 'clarify'; // no subject matched / nothing to show
		}
		return 'card';
	}

	/**
	 * v2.28.11: Detect a SECOND read-intent in a compound ask so the dashboard
	 * can surface it instead of silently dropping it. classify() resolves only
	 * the FIRST matching intent; a query like "Devin's phone and Jordan's
	 * commission" rendered the commission card and lost the contact half. This
	 * splits the raw text on a coordinating 'and'/'&'/';' and, for each segment
	 * that is NOT the primary verb, runs the cheap (no-bridge, no-network)
	 * detectors to see if another card-able intent is present. Returns a compact
	 * hint { verb, query } the front-end turns into a '+1 more' chip, or null.
	 * Detection only — it never calls a bridge here (the chip re-runs orchestrate
	 * for the chosen segment), so it adds no latency to the primary turn.
	 *
	 * @param string $raw          The full user query.
	 * @param string $primary_verb The verb already resolved ('commission'|'contact'|'lookup').
	 * @return array|null { @type string $verb; @type string $query } or null.
	 */
	private static function detect_secondary( string $raw, string $primary_verb ) {
		// Only bother when a coordinator is present — keeps single-intent asks free.
		if ( ! preg_match( '/(?:\s+(?:and|&|plus|;)\s+|\s*,\s+)/i', $raw ) ) {
			return null;
		}
		$segments = preg_split( '/(?:\s+(?:and|&|plus|;)\s+|\s*,\s+)/i', $raw, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $segments ) || count( $segments ) < 2 ) {
			return null;
		}
		foreach ( $segments as $seg ) {
			$seg = trim( $seg );
			if ( $seg === '' ) { continue; }
			// Commission segment (not if commission is already the primary).
			if ( $primary_verb !== 'commission' ) {
				$c = self::detect_commission( $seg );
				if ( $c !== null ) {
					return array( 'verb' => 'commission', 'query' => $seg );
				}
			}
			// Contact segment (not if contact is already the primary).
			if ( $primary_verb !== 'contact' ) {
				$cn = self::detect_contact( $seg );
				if ( $cn !== '' ) {
					return array( 'verb' => 'contact', 'query' => $seg );
				}
			}
			// Document segment (not if document is already the primary).
			if ( $primary_verb !== 'lookup' ) {
				$dn = self::detect_doc_lookup( $seg );
				if ( $dn !== '' ) {
					return array( 'verb' => 'lookup', 'query' => $seg );
				}
			}
		}
		return null;
	}
}
