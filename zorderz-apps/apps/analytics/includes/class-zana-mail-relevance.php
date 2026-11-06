<?php
/**
 * ZANA_Mail_Relevance — should this turn be answered from the person's own MAILBOX? A pure detector.
 *
 * ZANA_Mail_Recall only redirects a turn that already looked like a customer look-up and only for a recall of what was
 * said ("what did I tell Casey"). This detector can START a mail route on its own, on any of these signals:
 *   (A0) "search my sent emails for <X>" — the person's SENT mail, searched for a person or a topic;
 *   (A)  an explicit inbox reference — the person names their own mail as the source ("in my inbox", "check my email",
 *        "search my mail");
 *   (B)  a purchase from a supplier — a business buys materials by email, so "how many <supplies> have I ordered" or
 *        "how much have I spent with <vendor>" is a mailbox question, not a billing one (the billing system holds what
 *        the business SOLD). A buy verb AND a supply word AND no sales word;
 *   (C)  a business-admin topic that lives in the person's mail rather than the CRM — insurance, liability, a
 *        certificate of insurance, bonding, a licence renewal, workers' comp, payroll tax; topic-anchored, so "what's
 *        the status on our insurance renewal?" routes with no inbox word;
 *   (D)  a question about mail RECEIVED — a mail noun aimed at a sender ("how many emails do I have from Example
 *        Co"), a bare mailbox count ("check my emails"), "<someone> emailed me", or an inbound word right before
 *        "from" ("any word from the vendor", "have I heard back");
 *   (E)  mail SENT to a named person — "have I sent emails to Jordan about the invoice", "did I email Jordan" — a
 *        yes/no or a list, not a count (a count stays on ZANA_Mail_Followup).
 *
 * SAFETY BY CONSTRUCTION: a turn that leads with a draft / send verb, or holds a write instruction anywhere
 * (ZANA_Mail_Compose), never routes here; the person saying not to use their mail ("do not search the mailbox") never
 * routes here (declined()); the purchase arm needs a buy verb AND a supply word AND no sales word, so "how many did I
 * SELL" / "who hasn't PAID" stay on the billing path; the received-mail arm needs a mail noun with a sender or a
 * read frame (or the "emailed me" shape) AND no outbound-send signal, so a SENT count never lands here. The chat only
 * consults this on a turn no other mail or message route has claimed, and never for the shared device.
 *
 * The words a business adds: supplies it buys (`zana_mail_supply_words`, plus the catalog's trade words from
 * ZDZ_Ask_Phrases::trade_words()) and its own admin topics (`zana_mail_admin_words`: a regional licence board, a tax
 * form). Each is an option a pack writes and a filter of the same name; Core ships the generic words only. A word a
 * business adds only ever ADDS a mail route; it can never take one away.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Mail_Relevance {

	/** Option + filter: extra supply words (what this business buys by email). */
	const SUPPLY_OPT = 'zana_mail_supply_words';

	/** Option + filter: extra admin topics that live in the mailbox (a licence board's name, a regional tax form). */
	const ADMIN_OPT = 'zana_mail_admin_words';

	/** Supply words every business shares. */
	const CORE_SUPPLY = array( 'rolls?', 'spools?', 'pallets?', 'materials?', 'suppliers?', 'vendors?', 'bulk', 'inventory', 'restock', 'p\.?\s?o\.?', 'purchase\s+order', 'hardware', 'parts' );

	/** Admin topics every business shares (workers' comp anchored to "work…", so "compensation" alone never matches). */
	const CORE_ADMIN = array(
		'work(?:ers\'?|man\'?s)?[\s\-]?comp(?:ensation)?',
		'liability',
		'insurance',
		'coi',
		'certificate\s+of\s+insurance',
		'bond(?:ed|ing|s)?',
		'contractor\'?s?\s+licen[sc]e',
		'licen[sc]e\s+renewal',
		'payroll[\s\-]?tax',
	);

	/**
	 * Should this turn be answered from the person's own mailbox?
	 *
	 * @param string $user_message the person's words for this turn.
	 * @return array { fire:bool, reason:string } reason ∈ {'', 'sent_search', 'explicit_inbox', 'procurement', 'admin_insurance', 'sent_to', 'mail_lookup'}
	 */
	public static function detect( string $user_message ): array {
		$no = array( 'fire' => false, 'reason' => '' );
		$mq = strtolower( trim( $user_message ) );
		if ( '' === $mq ) {
			return $no;
		}

		// A message that LEADS with a draft / send verb is a WRITE, never a read.
		if ( preg_match( '/^(?:can\s+you\s+|could\s+you\s+|please\s+|pls\s+|hey\s+|now\s+|go\s+|just\s+)*(?:draft|compose|repl(?:y|ies)|respond|forward|write\s+(?:back|a|an|the)\b|send|email)\b/', $mq ) ) {
			return $no;
		}
		// …and a write instruction ANYWHERE in the turn: a pasted customer message carries read-shaped words ("as I
		// said in my email") that would otherwise make "…Write her an email confirming Tuesday" a mailbox search.
		if ( class_exists( 'ZANA_Mail_Compose' ) && ZANA_Mail_Compose::is_compose( $user_message ) ) {
			return $no;
		}
		// The person said not to use their mail.
		if ( self::declined( $user_message ) ) {
			return $no;
		}

		// (A0) "search my sent emails for <X>".
		if ( '' !== self::sent_search_term( $user_message ) ) {
			return array( 'fire' => true, 'reason' => 'sent_search' );
		}

		// (A) explicit inbox reference.
		if ( preg_match( '/\b(?:in|on|from|per|check|checking|search|searching|look|looking|its|it\'?s|they\'?re|theyre|there\'?s|theres)\b[^.?!]{0,14}\b(?:my\s+|the\s+)?(?:e-?mail|inbox|mailbox|mail)\b/', $mq )
			|| preg_match( '/\bmy\s+(?:e-?mail|inbox|mailbox)\b/', $mq ) ) {
			return array( 'fire' => true, 'reason' => 'explicit_inbox' );
		}

		// (B) a purchase from a supplier.
		$buy_verb = (bool) preg_match( '/\b(order(?:ed|s|ing)?|re-?order(?:ed)?|purchas(?:e|ed|es|ing)|bought|buy(?:ing)?|restock(?:ed)?|procure[ds]?|spen[dt]\s+(?:on|with)|paid\s+(?:for|to))\b/', $mq );
		$supply   = (bool) preg_match( self::supply_re(), $mq );
		$sales    = (bool) preg_match( '/\b(sold|sell|sales|revenue|invoiced|who\s+(?:hasn\'?t|has\s+not)\s+paid|receivable|commission|estimate[sd]?)\b/', $mq );
		if ( $buy_verb && $supply && ! $sales ) {
			return array( 'fire' => true, 'reason' => 'procurement' );
		}

		// (C) a business-admin topic that lives in the mailbox.
		if ( preg_match( self::admin_re(), $mq ) && ! $sales ) {
			return array( 'fire' => true, 'reason' => 'admin_insurance' );
		}

		// (D) mail RECEIVED, and (E) mail SENT to a person. An outbound / sent question ("how many emails did I send")
		// belongs to ZANA_Mail_Followup; a pure address look-up ("find Alex's email") carries neither a sender "from"
		// nor a read frame and stays a customer look-up. A sender "from" bypasses the sales guard, so "any emails from
		// Riley about her estimate" still routes to the mailbox.
		$mail_noun   = (bool) preg_match( '/\b(?:e-?mails?|inbox|mailbox|mail)\b/', $mq );
		$sender_from = (bool) preg_match( '/\bfrom\s+\S/', $mq );
		$read_frame  = (bool) preg_match( '/\b(?:how\s+many|how\s+much|any|do\s+i\s+have|have\s+i\s+(?:got|gotten|received)|is\s+there|are\s+there|show\s+me|check|list)\b/', $mq );
		$emailed_me  = (bool) preg_match( '/\be-?mail(?:ed)?\s+(?:me|us)\b/', $mq );
		$outbound    = (bool) preg_match( '/\b(?:my|our)\s+(?:sent\s+)?(?:e-?mails?|messages?|replies)\s+to\s+\S/', $mq )
			|| (bool) preg_match( '/\b(?:i|we)\s+(?:ever\s+|already\s+|actually\s+|just\s+|recently\s+)?(?:sent|send|e-?mail(?:ed)?|wrote|drafted|replied|responded)\b/', $mq )
			|| (bool) preg_match( '/\b(?:did|have|has|should|will|can|could|gonna)\s+(?:i|we)\s+(?:ever\s+|already\s+|actually\s+|just\s+|recently\s+)?(?:send|sent|e-?mail|e-?mailed|write|wrote|draft|drafted|reply|repl(?:y|ies)|respond)/', $mq )
			|| (bool) preg_match( '/\be-?mails?\s+(?:i|we)\s+(?:sent|send|wrote|drafted)\b/', $mq );
		$c_sales     = (bool) preg_match( '/\b(?:sold|sell|sales|revenue|invoiced|receivable|commission|estimate[sd]?)\b/', $mq );

		// An inbound word with no mail noun — "any word / update / reply from <sender>", "have I heard back", "did the
		// supplier get back to me". The inbound word must stand right before "from" (or be a noun with a determiner and
		// "from" later in the same clause): "Note that I added a second coat … we bought ours from the usual store" pairs "note"
		// and "from" a sentence apart and is not a mail question. Never on a project update ("any updates ON the job").
		$inbound_from = (bool) preg_match( '/\b(?:word|updates?|news|notes?|responses?|repl(?:y|ies))\s+(?:(?:back|yet|at\s+all)\s+){0,2}from\s+\S/', $mq )
			|| (bool) preg_match( '/\b(?:any|a|an|the|some|no|new|another|more|latest|last)\s+(?:(?:new|recent|further|more|other)\s+)?(?:word|updates?|news|notes?|responses?|repl(?:y|ies))\b(?!\s+of\b)[^.?!;]{0,40}?\bfrom\s+\S/', $mq )
			|| (bool) preg_match( '/(?:^|[.?!]\s+)(?:updates|replies|responses|news)\b[^.?!;]{0,40}?\bfrom\s+\S/', $mq );
		$got_back     = (bool) preg_match( '/\b(?:get|got|gotten|getting)\s+back\s+to\s+(?:me|us)\b/', $mq )
			|| (bool) preg_match( '/\bhear(?:d)?\s+back\b/', $mq )
			|| (bool) preg_match( '/\breach(?:ed)?\s+out\s+to\s+(?:me|us)\b/', $mq );
		$comms_from   = ( $inbound_from || $got_back ) && ! $c_sales;

		// (E) Not an estimate / invoice "was it sent?" question: a billing system sends those from its own mail server,
		// so the person's sent folder would answer a confident wrong "no" — unless they say "an email".
		$doc_object = (bool) preg_match( '/\b(?:estimates?|invoices?|quotes?|receipts?|proposals?|bills?)\b|#\s?\d{3,}/', $mq );
		$mail_said  = (bool) preg_match( '/\b(?:an?\s+e-?mail|e-?mails|messages)\b/', $mq );
		if ( $outbound && ! preg_match( '/\bhow\s+many\b/', $mq ) && ( ! $doc_object || $mail_said ) && '' !== self::extract_recipient( $user_message ) ) {
			return array( 'fire' => true, 'reason' => 'sent_to' );
		}

		if ( ! $outbound
			&& ( ( $mail_noun && $sender_from )
				|| ( $mail_noun && $read_frame && ! $c_sales )
				|| $emailed_me
				|| $comms_from ) ) {
			return array( 'fire' => true, 'reason' => 'mail_lookup' );
		}

		return $no;
	}

	/** The supply-word pattern: Core's words, the catalog's trade words, and the business's own (each word quoted). */
	public static function supply_re(): string {
		$words = self::CORE_SUPPLY;
		foreach ( self::extra_words( self::SUPPLY_OPT ) as $w ) {
			$words[] = $w;
		}
		if ( class_exists( 'ZDZ_Ask_Phrases' ) && method_exists( 'ZDZ_Ask_Phrases', 'trade_words' ) ) {
			foreach ( (array) ZDZ_Ask_Phrases::trade_words() as $w ) {
				$w = self::word_pattern( (string) $w );
				if ( '' !== $w ) {
					$words[] = $w;
				}
			}
		}
		return '/\b(?:' . implode( '|', array_unique( $words ) ) . ')\b/';
	}

	/** The admin-topic pattern: Core's topics and the business's own. */
	public static function admin_re(): string {
		return '/\b(?:' . implode( '|', array_unique( array_merge( self::CORE_ADMIN, self::extra_words( self::ADMIN_OPT ) ) ) ) . ')\b/i';
	}

	/** A business's extra words (option, then the filter of the same name), each made a safe literal pattern. */
	public static function extra_words( string $opt ): array {
		$v = function_exists( 'get_option' ) ? get_option( $opt, array() ) : array();
		if ( is_string( $v ) ) {
			$v = preg_split( '/[\r\n,]+/', $v );
		}
		$v = is_array( $v ) ? $v : array();
		if ( function_exists( 'apply_filters' ) ) {
			$f = apply_filters( $opt, $v );
			$v = is_array( $f ) ? $f : $v;
		}
		$out = array();
		foreach ( $v as $w ) {
			$p = self::word_pattern( is_string( $w ) ? $w : '' );
			if ( '' !== $p ) {
				$out[] = $p;
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, 80 );
	}

	/** "Example widget" → "example[\s\-]widgets?": a literal word (spaces or a hyphen between words, a plural s). */
	public static function word_pattern( string $w ): string {
		$w = strtolower( trim( preg_replace( '/\s+/', ' ', $w ) ) );
		if ( '' === $w || strlen( $w ) < 2 || strlen( $w ) > 40 || ! preg_match( '/^[a-z0-9][a-z0-9 \'\-]*$/', $w ) ) {
			return '';
		}
		$parts = preg_split( '/[ \-]+/', $w );
		$parts = array_map( function ( $p ) {
			return preg_quote( $p, '/' );
		}, $parts );
		$p = implode( '[\s\-]?', $parts );
		return 's' === substr( $w, -1 ) ? $p : $p . 's?';
	}

	/**
	 * Does the person tell the chat NOT to use their mail this turn? "Do not search the mailbox", "don't check my
	 * email", "without looking in my inbox", "skip / ignore the mailbox", "leave my email out of this", "no email
	 * search". Checked before every mail route, so such a turn goes on like any other question. Only read routes ask:
	 * "don't email her yet" is a compose question and keeps its own path. "don't forget to check my email" is NOT a
	 * decline (only a short list of filler words may stand between the "not" and the read verb). Pure.
	 */
	public static function declined( string $user_message ): bool {
		$mq = strtolower( (string) preg_replace( '/[\x{2018}\x{2019}]/u', "'", $user_message ) );
		if ( '' === trim( $mq ) ) {
			return false;
		}
		$mail = '(?:e-?mails?|inbox(?:es)?|mailbox(?:es)?|mail)';
		$own  = '(?:my\s+|the\s+|your\s+|our\s+)?';
		// Exact read verbs ("got", "used", "opened" are not reads); "never" is not a decline ("I never got the email
		// from the supplier, look in my inbox" asks for mail); the verb and the mail sit in ONE clause (no comma or
		// semicolon between: "don't use the CRM, check my mail" is a mail request).
		$verb = '(?:search(?:ing)?|check(?:ing)?|look(?:ing)?|use|using|pull(?:ing)?|scan(?:ning)?|query(?:ing)?'
			. '|dig(?:ging)?|touch(?:ing)?|consult(?:ing)?|go(?:ing)?\s+(?:through|into|in\s+to|in)|hit(?:ting)?)';
		$patterns = array(
			// "do not / don't / no need to / without / stop / quit / avoid / didn't ask you to" + read verb + mail
			'/\b(?:do\s+not|don\'?t|dont|no\s+need\s+to|without|stop|quit|avoid|did\s*n[o\']?t\s+ask\s+(?:you\s+)?to|'
				. 'do\s+not\s+need\s+to|don\'?t\s+need\s+to)\s+(?:(?:you|bother|even|ever|to|try|trying|please|go\s+and)\s+){0,3}'
				. $verb . '\b[^.?!,;]{0,24}?\b' . $mail . '\b/',
			// "skip / ignore / exclude / leave out the mailbox", "not from my email", "nothing to do with my email"
			'/\b(?:skip|ignore|exclude|leave\s+out|not\s+from|nothing\s+to\s+do\s+with|do\s+not\s+need|don\'?t\s+need)\s+' . $own . $mail . '\b/',
			// "leave my email out / alone"
			'/\bleave\s+' . $own . $mail . '\s+(?:out|alone)\b/',
			// "no email search", "no mailbox lookup"
			'/\bno\s+' . $mail . '\s+(?:search(?:es|ing)?|look-?ups?|check)\b/',
			// a sentence that IS the decline: "No email please.", "Not my email, use the CRM.", "Not from my inbox!"
			'/(?:^|[.!?;]\s*)(?:no|not)\s+' . $own . $mail . '(?:\s+(?:please|pls|thanks|this\s+time|for\s+this(?:\s+one)?))?\s*(?:[.!,;]|$)/',
		);
		foreach ( $patterns as $re ) {
			if ( ! preg_match( $re, $mq, $hit, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			// …unless the rest of the turn points the chat back AT the mail: "I don't use my inbox much, BUT did the
			// vendor email me", "no email search needed? JUST CHECK my inbox", "don't search my email for Alex, SEARCH
			// for the supplier". Then it is a narrower mail question, not a decline.
			$rest = (string) substr( $mq, (int) $hit[0][1] + strlen( (string) $hit[0][0] ) );
			if ( preg_match( '/\b(?:search|check|look|find|pull)\w*\b[^.?!]{0,30}?\b' . $mail . '\b|\be-?mail(?:ed)?\s+(?:me|us)\b/', $rest )
				|| preg_match( '/(?:\bbut\b|\binstead\b|\bjust\b|\brather\b|,)\s*(?:\w+\s+){0,2}(?:search|check|look|find)\b/', $rest ) ) {
				return false;
			}
			return true;
		}
		return false;
	}

	/**
	 * The PERSON the asker sent mail to — "have I sent emails to Jordan about X" → "jordan", "did I email Jordan
	 * Example" → "jordan example", "did I email jordan@example.com" → the full address. Skips a lead-in that is not a
	 * name ("emailed anything to Riley" → "riley"; "my client Riley" → "riley"). '' when none. Pure.
	 */
	public static function extract_recipient( string $user_message ): string {
		$mq = strtolower( trim( $user_message ) );
		if ( preg_match( '/\b(?:to|e-?mail(?:ed)?|messag(?:e|ed))\s+([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})\b/', $mq, $am ) ) {
			return $am[1];
		}
		if ( ! preg_match_all( '/\b(?:(?:e-?mails?|mail|messages?|replies|anything|something|it|this|that|them|one)\s+to|e-?mail(?:ed)?|messag(?:e|ed)|wr(?:ite|ote)(?:\s+to)?|repl(?:y|ied)\s+to|sen[dt]\s+(?:an?\s+)?(?:e-?mail\s+)?to)\s+((?:[a-z][a-z.\'\-]*\s*){1,4})/', $mq, $all, PREG_SET_ORDER ) ) {
			return '';
		}
		$stop = array( 'about', 'regarding', 'concerning', 'anything', 'something', 'everything', 'any', 'ever', 'on', 're', 'for', 'with', 'this', 'that', 'last', 'yet', 'already', 'today', 'yesterday', 'recently', 'and', 'or', 'the', 'a', 'an', 'it', 'him', 'her', 'them', 'me', 'us', 'you', 'back', 'again', 'to', 'since', 'week', 'month' );
		$lead = array( 'anything', 'something', 'it', 'one', 'to', 'my', 'our', 'the', 'client', 'customer', 'colleague', 'tech', 'installer', 'rep', 'salesperson', 'friend', 'boss', 'partner' );
		foreach ( $all as $m ) {
			$toks = preg_split( '/\s+/', trim( $m[1] ) );
			while ( $toks && in_array( $toks[0], $lead, true ) ) {
				array_shift( $toks );
			}
			$out = array();
			foreach ( $toks as $t ) {
				$t = trim( $t, ".,'\"" );
				if ( '' === $t || in_array( $t, $stop, true ) || count( $out ) >= 2 ) {
					break;
				}
				$out[] = $t;
			}
			$name = implode( ' ', $out );
			if ( '' !== $name && strlen( $name ) >= 2 ) {
				return $name;
			}
		}
		return '';
	}

	/**
	 * The TOPIC of a sent-mail ask — "have I sent emails to Jordan about Sam's commission?" → "sam's commission".
	 * Only "about / regarding / concerning" count (never "from", which names a sender). '' when there is no clean
	 * topic or it is a bare pronoun ("about it"). Pure.
	 */
	public static function extract_topic( string $user_message ): string {
		if ( ! preg_match( '/\b(?:about|regarding|concerning)\s+.+$/i', trim( $user_message ), $m ) ) {
			return '';
		}
		return self::extract_query( $m[0] );
	}

	/**
	 * "search my sent emails for jordan" / "check my sent mail for the Example Street job" → the term after "for /
	 * about / mentioning". It may be a person or a topic, so the inbox tries the addressee first and then the message
	 * text. '' when the turn isn't that shape, or what is left is only a period ("my sent emails for this week" is the
	 * sent list's job). Pure.
	 */
	public static function sent_search_term( string $user_message ): string {
		$mq = strtolower( trim( $user_message ) );
		if ( ! preg_match( '/\bmy\s+(?:sent|outgoing)\s+(?:e-?mails?|mail|messages?|folder|box)\s+(?:for|about|mentioning|regarding)\s+(.+)$/', $mq, $m ) ) {
			return '';
		}
		$parts = preg_split( '/[.,!?;:](?=\s|$)|\b(?:and|then)\s+(?:show|tell|list|draft|write|reply)\b/', (string) $m[1] );
		$t     = trim( (string) $parts[0], " \t\"'" );
		// Framing, not the search: "anything about the Example Street job", "emails I sent about X", "messages to…".
		$t = (string) preg_replace( '/^(?:(?:anything|something|stuff|e-?mails?|messages?|mail)\s+)?(?:(?:that\s+)?(?:i|we)\s+(?:sent|wrote|e-?mailed)\s+)?(?:about|regarding|mentioning|on)\s+/', '', $t );
		$t = (string) preg_replace( '/^(?:the|a|an|any)\s+/', '', $t );
		// A PERIOD is the sent list's job — cut it off, and if nothing else is left this is not a search.
		$t = trim( (string) preg_replace(
			'/(?:^|\s)(?:(?:from|since|during|in|over|for)\s+)?(?:'
			. '(?:(?:this|last|past|previous|the\s+past|the\s+last)\s+(?:\d+\s+|few\s+|couple\s+(?:of\s+)?)?(?:days?|weeks?|weekend|months?|years?|quarters?|monday|tuesday|wednesday|thursday|friday|saturday|sunday))'
			. '|today|yesterday|tonight'
			. '|(?:in|during|since|from|for)\s+(?:january|february|march|april|may|june|july|august|september|october|november|december|jan|feb|apr|jun|jul|aug|sept?|oct|nov|dec)'
			. ')\b.*$/',
			'',
			$t
		) );
		$t = trim( (string) preg_replace( '/\s+(?:please|pls|thanks?)$/', '', $t ) );
		if ( '' === $t || ! preg_match( '/[a-z]/', $t ) || str_word_count( $t ) > 6
			|| preg_match( '/^(?:anything|something|stuff|it|them|him|her|e-?mails?|messages?|mail|week|month|year|day)$/', $t )
			|| preg_match( '/^(?:january|february|march|april|may|june|july|august|september|october|november|december)(?:\s+\d{4})?$/', $t ) ) {
			return '';
		}
		return $t;
	}

	/**
	 * The server's own plan for a search of the person's SENT mail, shared by the mailbox route and the recall route
	 * so both search the same way. array() when the turn isn't a sent-mail search.
	 *   "have I sent emails to Jordan about Sam's commission?" → {query:"jordan", topic:"sam's commission", match:"recipient"}
	 *   "search my sent emails for jordan"                     → {query:"jordan", topic:"", match:"any"}
	 * 'any' = the term may be a name or a topic: the inbox tries the addressee first, then the text. A write anywhere in
	 * the turn is never a search. Pure.
	 *
	 * @return array{query?:string,topic?:string,match?:string}
	 */
	public static function sent_plan( string $user_message ): array {
		if ( class_exists( 'ZANA_Mail_Compose' ) && ZANA_Mail_Compose::is_compose( $user_message ) ) {
			return array();
		}
		// "…and draft a reply to him" / "…then write Jordan": a search plus a write is not a search-only turn (the
		// server-built search skips the model, which would drop the draft). "did I reply to…" is fine.
		if ( preg_match( '/(?:\b(?:and|then)|[,;])\s*(?:please\s+|also\s+)?(?:draft|write|compose|reply|respond|forward|send|e-?mail|tell|summari[sz]e|explain|compare|count)\b/i', $user_message ) ) {
			return array();
		}
		$d    = self::detect( $user_message );
		$term = self::sent_search_term( $user_message );
		$to   = ( 'sent_to' === ( $d['reason'] ?? '' ) || '' !== $term ) ? self::extract_recipient( $user_message ) : '';
		if ( '' !== $to ) {
			$topic = self::extract_topic( $user_message );
			// "emailed customers about the price change": a GROUP is not an addressee. With a topic, search the text of
			// sent mail for the topic; without one, leave it to the sent list.
			if ( preg_match( '/^(?:customers?|clients?|homeowners?|leads?|people|everyone|everybody|anyone|anybody|someone|somebody|vendors?|suppliers?|(?:the\s+)?team|staff|employees?)$/', $to ) ) {
				return '' !== $topic ? array( 'query' => $topic, 'topic' => '', 'match' => 'any' ) : array();
			}
			return array( 'query' => $to, 'topic' => $topic, 'match' => 'recipient' );
		}
		if ( '' !== $term ) {
			return array( 'query' => $term, 'topic' => '', 'match' => 'any' );
		}
		return array();
	}

	/**
	 * A clear SENDER or TOPIC to search for, from a turn already decided to be a mailbox read: "how many emails FROM
	 * Example Co" → "example co". '' when there is no clean phrase — the inbox then lists the newest mail (a bare
	 * "check my email"). Only "from <X>" or "about / regarding <X>" qualify, cut at the next framing word or clause
	 * punctuation (never at a dot inside a token, so "jordan@example.com" survives). Pure.
	 */
	public static function extract_query( string $user_message ): string {
		$raw = trim( $user_message );
		if ( '' === $raw ) {
			return '';
		}
		if ( ! preg_match( '/\b(?:from|about|regarding|concerning)\s+(.+)$/i', $raw, $m ) ) {
			return '';
		}
		$parts = preg_split( '/\b(?:about|regarding|concerning|on|in|for|re|since|last|latest|this|that|yesterday|today|recently|lately|yet)\b|[.,!?;:](?=\s|$)/i', (string) $m[1] );
		$tail  = trim( (string) $parts[0] );
		$tail  = (string) preg_replace( '/^(?:the|a|an|my|our|his|her|their|any)\s+/i', '', $tail );
		$tail  = trim( $tail, " \t\"'" );
		if ( '' === $tail || ! preg_match( '/[a-z]/i', $tail ) || str_word_count( $tail ) > 5 ) {
			return '';
		}
		if ( preg_match( '/^(?:me|us|them|him|her|it|you|anyone|someone|somebody|everyone|everybody)$/i', $tail ) ) {
			return '';
		}
		return $tail;
	}
}
