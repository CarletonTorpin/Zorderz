<?php
/**
 * ZANA_Email_Turn — "is this turn about WRITING AN EMAIL, and is it a follow-up to one?" Pure decisions.
 *
 * Three things go wrong without it. A pasted customer question with a reply request at the end ("…Please advise,
 * thank you. And write the email to riley@example.com") reads like a multi-part research question; "check my past sent
 * emails to figure out how I write these" reads like a mailbox recall (wrong job: it answers "here are your emails"
 * and drafts nothing); and "try again" / "write it differently" carry no email word, so the drafting rules and the
 * business's voice are never loaded and the redo comes back as plain, unsigned text. This class is the one place
 * those decisions are made. ZANA_Mail_Compose is used when present.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Email_Turn {

	/** The user's own words: a pasted message (one that ends in a sign-off) is set aside. */
	public static function own_words( string $msg ): string {
		$msg = (string) preg_replace( '/^\[CONTINUATION\].*?My new message[^:]*:\s*/si', '', $msg );
		if ( class_exists( 'ZANA_Mail_Compose' ) && method_exists( 'ZANA_Mail_Compose', 'own_text' ) ) {
			$own = (string) ZANA_Mail_Compose::own_text( $msg );
			if ( '' !== trim( $own ) ) {
				return $own;
			}
		}
		return $msg;
	}

	/**
	 * Is the user asking for an email (or a reply) to be WRITTEN this turn?
	 * "write the email to x@y", "draft a reply", "email her back", "respond to this customer".
	 * A question ABOUT email ("did she email me?", "show me Robin's emails") is not.
	 */
	public static function is_email_task( string $msg ): bool {
		$own = strtolower( trim( self::own_words( $msg ) ) );
		if ( '' === $own ) {
			return false;
		}
		// An imperative WRITE clause anywhere — at the start or after "and" / "then" / "if so" / a sentence
		// end — makes it a write, even after a lookup or a question ("Find Alex's email and write him a
		// thank-you email", "Did Robin email? If so draft a reply").
		$clause = '(?:^|[.?!;:]\s*|\b(?:and|then|so)\s+|\bif\s+so,?\s+)(?:please\s+|(?:can|could|would)\s+you\s+)?';
		if ( preg_match( '/' . $clause . '(?:write|draft|compose|prepare|craft)\b[^.?!\n]{0,60}\b(?:e-?mails?|reply|replies|response|responses|letter|note)\b/', $own )
			|| preg_match( '/' . $clause . '(?:reply|respond|write\s+back)\s+(?:to|back)\b/', $own )
			|| preg_match( '/' . $clause . 'e-?mail\s+(?:her|him|them|back)\b/', $own ) ) {
			return true;
		}
		// Otherwise a question about mail, or a request to SEE it, is not a request to write one:
		// "Did I send a reply to Robin?", "read me Robin's reply", "what did I write in my reply to Robin".
		if ( self::is_question_or_read( $own ) ) {
			return false;
		}
		if ( preg_match( '/\b(write|draft|compose|prepare|craft|send|shoot)\b[^.?!\n]{0,60}\b(e-?mails?|reply|replies|response|responses|letter|note)\b/', $own ) ) {
			return true;
		}
		if ( preg_match( '/\b(reply|respond|write\s+back)\s+(to|back)\b/', $own )
			|| preg_match( '/\be-?mail\s+(her|him|them|this\s+(?:person|customer|lady|guy|individual)|back)\b/', $own ) ) {
			return true;
		}
		if ( class_exists( 'ZANA_Mail_Compose' ) ) {
			$d = ZANA_Mail_Compose::detect( $msg );
			if ( ! empty( $d['compose'] ) && ! empty( $d['email'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The email draft the newest bot turn produced, if any: an email-draft card ([ZDZ_EMAIL_DRAFT]), or a plain-text
	 * draft ("To: x@y" + "Subject:" lines). Returns array( 'to' => string, 'subject' => string ) or array().
	 */
	public static function prior_draft( array $history ): array {
		for ( $i = count( $history ) - 1; $i >= 0; $i-- ) {
			if ( ( $history[ $i ]['role'] ?? '' ) !== 'assistant' ) {
				continue;
			}
			$t = (string) ( $history[ $i ]['content'] ?? '' );
			$card = false !== stripos( $t, '[ZDZ_EMAIL_DRAFT' );
			$to   = '';
			$subj = '';
			if ( preg_match( '/^[ \t>*_]*to:[ \t*_]*([^\s<>*]+@[^\s<>*]+\.[A-Za-z]{2,})/mi', $t, $m ) ) {
				$to = $m[1];
			}
			if ( preg_match( '/^[ \t>*_]*subject:[ \t*_]*(.+)$/mi', $t, $m ) ) {
				$subj = trim( $m[1], " \t*_" );
			}
			if ( $card || ( '' !== $to && '' !== $subj ) ) {
				return array( 'to' => $to, 'subject' => $subj );
			}
			return array(); // only the immediately preceding bot turn counts
		}
		return array();
	}

	/** Did one of the user's last three turns ask for an email to be written? */
	public static function recent_email_request( array $history ): bool {
		$seen = 0;
		for ( $i = count( $history ) - 1; $i >= 0 && $seen < 3; $i-- ) {
			if ( ( $history[ $i ]['role'] ?? '' ) !== 'user' ) {
				continue;
			}
			$seen++;
			if ( self::is_email_task( (string) ( $history[ $i ]['content'] ?? '' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The addresses the USER typed in this conversation (newest first, at most 5). Never the bot's, never
	 * one inside a pasted message (own_words() sets a signed paste aside), and only from the
	 * user's last five turns, the part of the conversation the model is shown.
	 */
	public static function typed_addresses( array $history, string $msg = '' ): array {
		$out   = array();
		$texts = array( self::own_words( $msg ) );
		for ( $i = count( $history ) - 1, $seen = 0; $i >= 0 && $seen < 5; $i-- ) {
			if ( ( $history[ $i ]['role'] ?? '' ) === 'user' ) {
				$texts[] = self::own_words( (string) ( $history[ $i ]['content'] ?? '' ) );
				$seen++;
			}
		}
		foreach ( $texts as $t ) {
			$found = class_exists( 'ZANA_Mail_Compose' )
				? ZANA_Mail_Compose::addresses_in( $t )
				: ( preg_match_all( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $t, $m ) ? array_map( 'strtolower', $m[0] ) : array() );
			foreach ( $found as $a ) {
				if ( ! in_array( $a, $out, true ) ) {
					$out[] = $a;
				}
			}
			if ( count( $out ) >= 5 ) {
				break;
			}
		}
		return array_slice( $out, 0, 5 );
	}

	/** A data / report question that merely follows a draft — it goes to the data path, not the draft. */
	public static function is_data_question( string $msg ): bool {
		$q = strtolower( $msg );
		return (bool) preg_match( '/\b(revenue|profit|margin|how\s+many|how\s+much\s+(?:did|have|revenue|money)|number\s+of|totals?\s+(?:for|of|up)|average|avg|breakdown|per\s+month|this\s+month|last\s+month|this\s+year|last\s+year|ytd|quarter|commissions?|invoice\s*#?\d|estimate\s*#?\d)\b/', $q );
	}

	/** Another app's action — those own their fast paths. */
	public static function is_other_action( string $msg ): bool {
		return (bool) preg_match( '/\b(schedul\w*|book\s+(?:an?|the|her|him|them)|appointments?|reschedul\w*|calendar|estimates?|invoices?|leads?|nutshell|crm|jobs?|projects?|commissions?|surveys?|exclude|assign|working|available|availability|free\s+(?:on\s+)?(?:mon|tue|wed|thu|fri|sat|sun|today|tomorrow|this|next)\w*)\b|\bdm\s+@|\bpost\s+(?:this\s+)?to\s+#/i', $msg );
	}

	/** A question (not a request), or a request to SEE / FIND something — those keep their own routes. */
	public static function is_question_or_read( string $q ): bool {
		return self::is_question( $q ) || self::is_read( $q );
	}

	/** Opens like a question: who / what / did / is … (a request like "can you make it shorter" is not one). */
	public static function is_question( string $q ): bool {
		$q = self::lead_trim( $q );
		return (bool) preg_match( '/^(?:who|what|when|where|which|whose|why|did|does|do|is|are|was|were|has|have|had)\b/', $q );
	}

	private static function lead_trim( string $q ): string {
		$q = ltrim( strtolower( $q ), " \t\n\"'(" );
		return (string) preg_replace( '/^(?:ok(?:ay)?|so|and|also|now|hey|please|pls)[,\s]+/', '', $q );
	}

	/** A request to SEE or FIND something: show / search / list / pull up / open / read / check / find <thing>. */
	public static function is_read( string $q ): bool {
		$q = self::lead_trim( $q );
		if ( preg_match( '/^(?:(?:can|could|would|will)\s+you\s+)?(?:show|search|list|pull\s+up|bring\s+up|look\s+up|open|read|check|go\s+to|navigate)\b/', $q ) ) {
			return true;
		}
		// "find Alex's address" is a lookup; "find a better one" / "find another company" edits the email.
		return (bool) preg_match( '/^(?:(?:can|could|would|will)\s+you\s+)?find\s+(?!(?:a|an|another|someone|somebody|one|a\s+(?:better|different))\b)/', $q );
	}

	/** "thanks", "ok", "perfect", "looks good" — an acknowledgement, not an edit. */
	public static function is_ack( string $q ): bool {
		return (bool) preg_match( '/^\s*(?:(?:ok(?:ay)?|k|thanks?|thank\s+you|thx|ty|perfect|great|good|awesome|cool|nice|excellent|got\s+it|sounds\s+good|looks\s+good|love\s+it|yes|yep|yeah|no|nope|done|that\s+works|all\s+set)[\s!.,:)]*)+$/i', $q );
	}

	/** "send it", "go ahead and send", "send to her" — the card's Send button / confirm path owns these. */
	public static function is_send( string $q ): bool {
		return (bool) preg_match( '/^\s*(?:please\s+|ok(?:ay)?[,\s]+|go\s+ahead\s+(?:and\s+)?)?send\b|\bsend\s+(?:it|this|that|the\s+(?:e-?mail|draft|message)|now|her|him|them)\b/i', $q );
	}

	/**
	 * Does THIS turn redo or edit the email the conversation is working on?
	 * Needs: a draft in the newest bot turn, or an email request in the last three user turns; and this
	 * turn reads as an edit / retry / style instruction ("try again", "write it differently", "max four
	 * sentences", "not a franchise, find a better one", "sign it Sam", "Hi Riley,").
	 * Never: an acknowledgement or a send; a question or a request to see / find
	 * something; a data question; another app's action (estimate, lead, schedule, job…); an email that
	 * needs customer history; a NEW email request (it takes the normal path). False negatives take the
	 * ordinary path; false positives would hijack the turn, so every doubt says no.
	 */
	public static function is_draft_followup( string $msg, array $history ): bool {
		if ( empty( $history ) ) {
			return false;
		}
		$has_draft = ! empty( self::prior_draft( $history ) );
		if ( ! $has_draft && ! self::recent_email_request( $history ) ) {
			return false;
		}
		$q = strtolower( trim( self::own_words( $msg ) ) );
		if ( '' === $q || self::is_ack( $q ) || self::is_send( $q ) || self::is_data_question( $q )
			|| self::is_other_action( $q ) || self::wants_customer_data( $q ) ) {
			return false;
		}
		$strong = (bool) preg_match( '/\b(try\s+again|again|redo|re-?do|retry|one\s+more\s+time|another\s+(?:version|go|try)|different(?:ly)?|rewrite|re-?word|rephrase|revise|shorter|shorten|longer|tighten|simpler|simplify|casual|formal|friendl(?:y|ier)|warmer|tone|voice|style|wording|sentences?|max(?:imum)?|at\s+most|no\s+more\s+than|sign\s+it|sign[\s-]?off|signature|greeting|subject\s+line|start\s+(?:it\s+)?with|begin\s+(?:it\s+)?with|end\s+(?:it\s+)?with|better\s+one|instead|too\s+(?:long|short|wordy|formal|casual|stiff|pushy))\b/', $q );
		if ( self::is_read( $q ) || ( self::is_question( $q ) && ! $strong ) ) {
			return false; // "check again" / "show me…" keep their routes; "what if it were shorter?" is an edit
		}
		if ( self::is_email_task( $q ) && ! $strong ) {
			return false; // a new email ("write a thank-you email to Alex…") takes the normal path
		}
		if ( $strong ) {
			return true;
		}
		$edit = (bool) preg_match( '/\b(edit|tweak|adjust|fix|change|swap|replace|remove|delete|drop|take\s+out|leave\s+out|add|include|mention|link|make\s+it|not\s+(?:a|an|the|that|this|so)|find\s+(?:a\s+)?(?:better|different|another)|use\s+(?:my|our|her|his|their)|translate|spanish|english|cc|bcc|another(?:\s+one)?|one\s+more|say|tell\s+(?:her|him|them)|too\s+(?:long|short|formal|casual)|like\s+(?:i|we)\s+(?:usually|normally|always)|how\s+(?:i|we)\s+(?:usually\s+)?(?:write|do|word|say))\b/', $q );
		if ( $edit ) {
			return true;
		}
		// A short greeting / sign-off line for the draft ("Hi Riley," / "H Riley," / "from Sam").
		return $has_draft && strlen( $q ) <= 60
			&& (bool) preg_match( '/^(?:hi|hey|hello|dear|h)\b|^(?:from|regards|cheers|best|thanks|thank\s+you|sincerely)\b.{0,40}$/', $q ); // a bare "Jordan," is not a greeting
	}

	/**
	 * "Check my past sent emails to see how I write these" / "use my style" / "we have a style guide".
	 * A request to WRITE LIKE the past mail — not to look something up in it. It must never be routed
	 * to a mailbox recall (that answers "here are your emails" and drafts nothing).
	 */
	public static function is_style_reference( string $msg ): bool {
		$q = strtolower( self::own_words( $msg ) );
		if ( preg_match( '/\b(my|our)\s+(?:own\s+)?(?:writing\s+|email\s+|e-mail\s+)?(style|voice|tone)\b/', $q )
			|| preg_match( '/\bstyle\s+guide\b/', $q )
			|| preg_match( '/\bhow\s+(?:i|we)\s+(?:usually\s+|normally\s+|always\s+)?(?:write|word|phrase|reply|respond|answer|do\s+(?:it|these|this|them))\b/', $q )
			|| preg_match( '/\b(?:like|the\s+way)\s+(?:i|we)\s+(?:usually|normally|always)\s+(?:do|write|say|reply|respond)\b/', $q )
			|| preg_match( '/\b(?:we|i)(?:\'ve|\s+have)\s+(?:done|written|sent)\s+(?:these|this|them|those|ones?\s+like\s+this)\s+before\b/', $q ) ) {
			return true;
		}
		// "check / look at / read my (past|sent|old) emails … (how|style|figure out|learn|copy)".
		return (bool) preg_match( '/\b(check|look\s+(?:at|through)|read|go\s+through|study|use|see|review|learn\s+from)\s+(?:my|our)\s+(?:\w+\s+){0,2}(?:sent\s+)?(?:e-?mails?|messages|replies|mail)\b[^.?!\n]{0,80}\b(how|style|voice|tone|wording|figure\s+out|learn|copy|match|same\s+way)\b/', $q );
	}

	/**
	 * Does the user's own wording ask for customer analysis an email would be built on ("email our top
	 * 10 customers", "write to everyone who bought from us last spring")? Only then does an email turn need the customer records.
	 */
	public static function wants_customer_data( string $msg ): bool {
		$q = strtolower( self::own_words( $msg ) );
		return (bool) preg_match( '/\b(top|best|biggest|largest|repeat|loyal|past|previous|former|old|recent|all\s+(?:of\s+)?(?:our|my|the)|every|each)\s+(?:\d+\s+)?(?:customers?|clients?|buyers?|accounts?)\b|\b(?:customers?|clients?|people|everyone|anyone)\s+who\b|\bwho\s+(?:bought|ordered|purchased|paid|owes?)\b|\b(?:unpaid|overdue|open)\s+(?:invoices?|estimates?)\b|\binvoice\s*#?\d|\bestimate\s*#?\d|\b(?:their|his|her)\s+(?:invoice|estimate|order|job|history)\b/', $q );
	}

	/**
	 * Remove the business's own name before keyword tests: a business name that contains an ordinary word ("Best",
	 * "Count", "Sum") is a NAME, not a request to total something up or count — a pasted email signed "<name> / <business>"
	 * must not read as a count request. The names come from the Business Profile (trading, legal and short names);
	 * only a Capitalised or ALL-CAPS spelling counts, as a name is written, and never right before a product or sales
	 * word ("what were <name> door sales" stays a count). With no profile, nothing is removed.
	 */
	public static function without_company_name( string $msg ): string {
		$re = self::company_name_re();
		if ( '' === $re ) {
			return $msg;
		}
		$out = preg_replace( $re, ' ', $msg );
		return is_string( $out ) ? $out : $msg;
	}

	/** Tests hand in the names: array of strings. */
	public static $company_names = null;

	/** The pattern for the business's own names ('' when it has none). */
	public static function company_name_re(): string {
		$names = is_array( self::$company_names ) ? self::$company_names : array();
		if ( null === self::$company_names && class_exists( 'ZDZ_Business_Profile' ) ) {
			foreach ( array( 'identity.trading_name', 'identity.legal_name', 'identity.short_name' ) as $path ) {
				$v = ZDZ_Business_Profile::get( $path, '' );
				if ( is_string( $v ) && '' !== trim( $v ) ) {
					$names[] = $v;
				}
			}
		}
		$alts = array();
		foreach ( $names as $n ) {
			$words = preg_split( '/\s+/', trim( (string) preg_replace( '/[^A-Za-z0-9&\' ]+/', ' ', (string) $n ) ) );
			$words = array_values( array_filter( (array) $words, 'strlen' ) );
			if ( ! $words || strlen( implode( ' ', $words ) ) < 3 || count( $words ) > 6 ) {
				continue;
			}
			$parts = array();
			foreach ( $words as $w ) {
				$cap     = ucfirst( strtolower( $w ) );
				$parts[] = '(?:' . preg_quote( $cap, '/' ) . '|' . preg_quote( strtoupper( $w ), '/' ) . '|' . preg_quote( $w, '/' ) . ')';
			}
			$alts[ implode( ' ', $words ) ] = implode( '\s+', $parts );
		}
		if ( ! $alts ) {
			return '';
		}
		uksort( $alts, function ( $a, $b ) { return strlen( (string) $b ) - strlen( (string) $a ); } );
		return '/\b(?:' . implode( '|', $alts ) . ')(?:\s+(?:Company|COMPANY|company|Co|CO)\.?)?\b(?!s)(?!\s+(?:doors?|repairs?|sales?|jobs?|installs?|orders?|count|counts))/';
	}

	/**
	 * Words for the sent-mail style search: the request's own words without addresses, links, numbers or
	 * the company name. Includes the first email request of this conversation, so a "try again" turn
	 * still searches for the right kind of email. At most 300 characters.
	 */
	public static function style_topic( string $msg, array $history ): string {
		$parts = array( self::own_words( $msg ) );
		for ( $i = 0, $n = count( $history ); $i < $n; $i++ ) {
			if ( ( $history[ $i ]['role'] ?? '' ) === 'user' && self::is_email_task( (string) ( $history[ $i ]['content'] ?? '' ) ) ) {
				$parts[] = (string) $history[ $i ]['content'];
				break;
			}
		}
		$t = self::without_company_name( implode( ' ', $parts ) );
		$t = (string) preg_replace( '#https?://\S+#i', ' ', $t );
		$t = (string) preg_replace( '/\S+@\S+/', ' ', $t );
		$t = strtolower( (string) preg_replace( '/[^\p{L}\s]+/u', ' ', $t ) );
		// Content words only: 4+ letters, no stopwords or request verbs, at most 10 terms.
		static $stop = null;
		if ( null === $stop ) {
			$stop = array_flip( explode( ' ', 'about again also advise after before being both could differently does done email emails even every find from have here into just know like make maybe more most much need only other over please really reply rewrite same should some still such than thank thanks that their them then there these they thing this those through very want were what when where which while with would write your yours draft better shorter longer sentence sentences maximum style guide past sent check figure person people live this that will shall them okay sure great hello dear mail message note' ) );
		}
		$words = array();
		foreach ( preg_split( '/\s+/', $t ) as $w ) {
			if ( strlen( $w ) >= 4 && ! isset( $stop[ $w ] ) && ! in_array( $w, $words, true ) ) {
				$words[] = $w;
			}
			if ( count( $words ) >= 10 ) {
				break;
			}
		}
		return implode( ' ', $words );
	}
}
