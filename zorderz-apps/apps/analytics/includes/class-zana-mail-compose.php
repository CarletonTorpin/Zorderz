<?php
/**
 * ZANA_Mail_Compose — the one "is the person asking me to WRITE?" guard, shared by every mailbox READ route:
 * ZANA_Mail_CrossUser, ZANA_Mail_Recall, ZANA_Mail_Relevance, ZANA_Mail_Followup.
 *
 * Why one guard. A person often pastes a customer's message — name, phone, address, "Her email: …", "Please let me
 * know what time we can expect you … Thanks, <name>" — and puts their own instruction LAST: "Write an email to this
 * individual asking if we can come today". A guard that only asks "does the message START with draft / send /
 * write?" misses that instruction, and the pasted words ("what time", "let me know", "did you get my email?") look
 * like a mailbox question. Every read route asks this class instead.
 *
 * THE MODEL — three rules, all conservative:
 *   1. PASTED TEXT IS NOT THE PERSON'S REQUEST. A pasted message ends with a sign-off — "Thanks, Robin",
 *      "Thanks for your help,\nRobin", "Best,\nRobin E.", "-Robin", "— Robin", "--\nRobin", a name alone under the
 *      message, "Sent from my iPhone", then any phone / address / website lines of a signature — and starts
 *      with a greeting ("Hi Alex,"), after the person's own instruction ("Help me respond to this: …", "Please
 *      help me reply - …"), on the line after one that ends in a colon, or at the start of the turn — never
 *      before the person's own opening line or sentence when that is an instruction or their own mailbox
 *      question ("Did I already reply to this?\n<paste>"). Everything from that start to its sign-off is set
 *      aside (a thread of several pasted messages included, working back from the last sign-off); only the
 *      person's own sentences are read. A "thanks" inside the person's own sentence, a list of names or a lone
 *      "Thanks" line is not a sign-off; "> " quote markers are dropped. With no sign-off there is no paste.
 *      (When the person's own words then hold no instruction and ask nothing, the whole turn is read instead.)
 *   2. A CUSTOMER'S QUESTION IS NOT THE PERSON'S. "Did you get my email?", "have you had a chance to look at
 *      our quote?", "can you send me the estimate?", "when will I hear back?" are asked OF the business, so
 *      they are never a mailbox READ — even when no sign-off marks them as pasted.
 *   3. A MAILBOX QUESTION OUTRANKS A WRITE. Each of the person's sentences is READ (a question about mail,
 *      or a request to see it — "did Jordan reply?", "show me Robin's emails"), WRITE (an instruction to
 *      write to someone — "write an email to her", "reply to this", "email her back", "tell her…", "help
 *      me reply") or neutral. If ANY sentence is a READ the turn is not a compose; otherwise any WRITE makes it
 *      one. So "Did Jordan get my email? Respond with yes or no." and "Show me Robin's emails. Then draft a
 *      reply." stay reads — the draft can follow in the next turn — and a mailbox question is never lost unless
 *      it sat inside a pasted message or was a customer's question.
 * An instruction can start a sentence, follow a request word ("please", "can you quickly", "is it possible
 * to", "any chance you could", "mind if we"), open it after "I need to" / "let's" / "gotta", follow a greeting
 * ("Hi Alex, reply…"), a comma ("For this lady, write back…"), a double space, a pasted phone number, address
 * or web address, or be Capitalized mid-sentence — the ways the person's own words begin after a paste with no
 * punctuation. A question framed as a request ("Is it possible to write an email to this individual…?", "hey
 * quick favor, can you reply to her?") is a WRITE.
 * A turn that STARTS with draft / compose / reply / respond / forward / write back|a|an|the / send is a write
 * ('leading'). A bare "email" is not (ZANA_Mail_Recall's "Email" follow-up means "look in my mail"). Quotations
 * ("She wrote: …"), reported speech ("she asked me to send her an email") and a carried [CONTINUATION] answer
 * never count.
 *
 * Also: addresses_in() — the addresses in the person's own words this turn (a pasted message included: an address
 * the person pasted is theirs to use); the email-drafting rules read it. own_text() — the person's words with the
 * paste removed (a pure helper for tests and diagnostics).
 *
 * Pure and dependency-free, so every rule is unit-tested directly.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Mail_Compose {

	/** A turn that starts with a write verb (a bare "email" deliberately excluded — see above). */
	const LEADING = '/^(?:can\s+you\s+|could\s+you\s+|please\s+|pls\s+|hey\s+|now\s+|go\s+ahead\s+and\s+|go\s+|just\s+)*((?:draft|compose|repl(?:y|ies)|respond|rspnd|respnd|rspond|repsond|rply|forward|write\s+(?:back|a|an|the)|send)\b)/';

	/** Only the tail of a very long turn is read (the instruction is at the end or the start). */
	const TAIL_BYTES = 8000;

	/** Compose verbs that take an email-shaped object anywhere in a sentence ("write an email", "send her a note"). */
	const VERB_STRONG = '(?:write|rite|draft|compose|send|shoot|craft|prepare|answer|word|create|generate|make|whip\s+up|fire\s+off|put\s+together|type\s+(?:up|out)|knock\s+out)';

	/** Verbs that are also reads ("give me the emails from Robin") — they count only with ONE new message: "give me AN email…". */
	const VERB_SINGULAR = '(?:give\s+me|gimme|get\s+me|need|get)';

	/** An indirect-object word: never me/us (a paste's "send ME an email" is the customer asking), a preposition or a determiner. */
	const IO_WORD = '(?!(?:an?|the|this|that|these|those|my|our|your|his|their|its|some|any|one|another|in|on|at|to|for|of|about|with|from|into|onto|by|as|up|out|off|back|together|me|us|it|and|or|if|so|all|every|each)\b)[a-z][a-z\'\-]*\.?';

	/** A modifier between the determiner and the noun: a content word ("a quick FOLLOW-UP email") — never "a list OF the emails". */
	const MOD_WORD = '(?!(?:of|from|about|in|on|with|to|for|by|at|and|or|the|a|an|my|our|your|his|her|their|all|any|every|each|some|these|those|this|that|i|we|you|he|she|they|it|me|us|them|last|latest|recent)\b)[a-z][a-z\'\-]*';

	/** Email-shaped nouns (plus a few common misspellings and "text", which is a write all the same). */
	const NOUN = '(?:e[\s\-]?mails?|mails?|emial|emal|eamil|emaill|messages?|msgs?|repl(?:y|ies)|responses?|letters?|notes?|texts?|txts?|follow[\s\-]?ups?)';

	/** The same, singular only ("give me AN email I can send her"). */
	const NOUN_ONE = '(?:e[\s\-]?mail|mail|emial|emal|eamil|message|msg|reply|response|letter|note|text|txt|follow[\s\-]?up)';

	/** Adjectives allowed with no determiner ("write QUICK email", "draft REPLY email"). */
	const ADJ = '(?:quick|short|brief|friendly|polite|professional|nice|new|follow[\s\-]?up|followup|check[\s\-]?in|thank[\s\-]?you|formal|simple|personal|little|courteous|warm|separate|reply|response|confirmation|scheduling|intro(?:ductory)?)';

	/** Words that open a request right before an imperative, anywhere in a sentence ("please", "can you", "and"). */
	const FRAME = '(?:please(?:\s+to)?|pls|plz|kindly|(?:can|could|would|will)\s+(?:you|u)(?:\s+(?:please|pls|quickly|quick|real\s+quick(?:ly)?|possibly|maybe|perhaps|actually|just|also|kindly))*|possible\s+(?:for\s+(?:you|u)\s+)?to|mind\s+if\s+(?:we|i)|(?:way|chance)\s+(?:to|(?:we|you|u)\s+(?:could|can))|(?:you|u)\s+able\s+to|chance\s+(?:you|u)\s+(?:could|can)|think\s+(?:you|u)\s+(?:could|can)|don\'?t\s+(?:you|u)|how\s+about\s+(?:you|u)|(?:can|could|should|shall)\s+(?:we|i)|go\s+ahead\s+and|i\s+need\s+you\s+to|i\s+want\s+you\s+to|i\'?d\s+like\s+you\s+to|and|then|also|just|ok|okay|so|hey|next|now)';

	/** Words that open a request only when they START the sentence ("I need to reply…" — but not "emails I need to reply to"). */
	const FRAME_START = '(?:(?:ok|okay|so|hey|and|also)[\s,]+)*(?:(?:i\s+|we\s+)?(?:gotta|got\s+to|must|(?:had\s+)?better)|(?:i\'?ve|we\'?ve|ive|weve)\s+got\s+to|time\s+to|i\s+need\s+to|we\s+need\s+to|need\s+to|i\s+want\s+to|i\'?d\s+like\s+to|i\s+would\s+like\s+to|i\'?m\s+going\s+to|im\s+going\s+to|we\s+should|we\s+have\s+to|i\s+should|i\s+have\s+to|can\s+i\s+get|could\s+i\s+get|can\s+i\s+have|let\'?s|lets|help\s+me(?:\s+to)?|(?:would\s+you\s+)?mind)';

	/**
	 * A request frame before the write verb, anywhere in the sentence's first words: "is it possible to write…?",
	 * "hey quick favor, can you reply to her?", "any chance you could email her?", "why don't you reply?" — a
	 * WRITE though it is a question. The words BEFORE the verb must end in the frame (plus "quickly",
	 * "possibly"…), so "did she ask if you could reply by Friday?" is not one.
	 */
	const REQUEST_FRAME = '/^(?:\S+[\s,:!]+){0,6}?(?:(?:can|could|would|will)\s+(?:you|u)|is\s+it\s+possible\s+(?:for\s+(?:you|u)\s+)?to|would\s+it\s+be\s+possible\s+(?:for\s+(?:you|u)\s+)?to|(?:are|r)\s+(?:you|u)\s+able\s+to|any\s+chance\s+(?:you|u)\s+(?:could|can)|do\s+(?:you|u)\s+think\s+(?:you|u)\s+(?:could|can)|why\s+don\'?t\s+(?:you|u)|how\s+about\s+(?:you|u)|(?:would\s+you\s+)?mind\s+if\s+(?:we|i)|is\s+there\s+(?:any\s+)?(?:way|chance)\s+(?:to|(?:we|you|u)\s+(?:could|can)))(?:\s+(?:please|pls|quickly|quick|real\s+quick(?:ly)?|possibly|maybe|perhaps|actually|just|also|kindly|go\s+ahead\s+and))*\s*$/';

	/** A sentence that asks about mail must mention it — mail, a reply, sending, writing, telling. */
	const MAIL_SIGNAL = '/\b(?:e-?mails?|e-?mailed|mail|inbox|mailbox|messages?|messaged|msgs?|repl(?:y|ies|ied)|respond(?:ed)?|response|answer(?:ed)?|wr(?:ote|itten|ite)|sent|send|told|tell|said|say|heard|hear|get\s+back|got\s+back|note|text(?:ed)?)\b/';

	/** Question openers (after an optional "so," / "ok," / "quick question:"). */
	const QUESTION_OPENER = '/^(?:(?:so|ok|okay|hey|also|and|but|btw|quick\s+question|one\s+more\s+thing)\b[\s,:]*)*(?:who|whom|whose|who\'?s|whos|what|what\'?s|whats|which|when|where|why|how|whether|did|didn\'?t|didnt|do|don\'?t|dont|does|doesn\'?t|doesnt|have|haven\'?t|havent|has|hasn\'?t|hasnt|had|hadn\'?t|is|isn\'?t|isnt|are|aren\'?t|arent|was|wasn\'?t|wasnt|were|weren\'?t|werent|am|any|anything|anyone|got)\b/';

	/** Read-verb openers: the user asks to SEE mail ("search my email…", "show me the reply I wrote…"). */
	const READ_OPENER = '/^(?:(?:so|ok|okay|hey|also|and|but|btw|please|pls|can\s+you|could\s+you)\b[\s,:]*)*(?:search|find|show|pull(?:\s+up)?|look\s+(?:up|for|in|at|through)|check|list|open|read|bring\s+up|dig\s+up|see\s+if|see\s+whether|tell\s+me|remind\s+me|get\s+me\s+(?:the|my|all)|give\s+me\s+(?:the|my|all|a\s+(?:count|list|summary|rundown))|gimme\s+(?:the|my|all)|scan|review)\b/';

	/** Asking for help writing one — a write, though it looks like a question ("what should I write back"). */
	const ADVICE = '/\b(?:help\s+me(?:\s+to)?\s+(?:write|reply|respond|answer|draft|word)|how\s+(?:should|would|do|can)\s+(?:i|you|we)\s+(?:reply|respond|answer|word|write|say)|what\s+(?:should|do|would|can)\s+(?:i|we|you)\s+(?:write|say|reply|respond|tell)|thoughts\s+on\s+(?:a|my|the|an)\s+(?:reply|response|email)|what\'?s\s+a\s+good\s+(?:reply|response|answer|email))\b/';

	/** A short follow-up about an action ("did I?", "did I ever do that?", "has he?") — a READ even with no mail word. */
	const SHORT_RECALL = '/^(?:did|have|has|had)\s+(?:i|we|he|she|they)\b[^?]{0,40}\?\s*$/';

	/** A read verb followed, within a few words, by mail. Before a write in one sentence = read-then-write. */
	const READ_THEN = '/\b(?:search|find|show|pull\s+up|look\s+(?:up|for|in|at|through)|check|open|read|see\s+if|see\s+whether|dig\s+up|bring\s+up)\b(?:\s+[a-z0-9\'\-]+){0,8}?\s+(?:e-?mails?|inbox|mailbox|mail|messages?|thread|repl(?:y|ies)|response)\b/';

	/** Reported speech: "she asked me to send her an email", "I promised Robin I'd send her one" — a description, not an instruction. */
	const REPORTED = '/\b(?:asked|asks|told|tells|wanted|wants|promised|promises|said|says|agreed|planned|meant|forgot|remembered|expects?|expected|reminded|begged|requested)\b(?:\s+[a-z\'\-]+){0,4}?\s+(?:to|i\'?d|i\'?ll|i\s+will|i\s+would|he\'?d|she\'?d|they\'?d|we\'?d|he\s+would|she\s+would)\s*$/';

	/** A quotation introducer: what follows it is someone else's words ("She wrote: …"). */
	const QUOTE_INTRO = '/\b(?:said|says|wrote|writes|asked|asks|texted|replied|responded|mentioned|messaged|emailed)\s*$/';

	/** A word that starts an instruction — never part of a sign-off name ("Thanks, Robin WRITE to her"). */
	const INSTRUCTION_WORD = '/^(?:(?:write|rite|draft|compose|send|shoot|email|e-mail|reply|respond|rspnd|respnd|rspond|repsond|rply|answer|get|follow|reach|circle|tell|ask|let|lets|can|could|would|will|should|shall|must|gotta|please|pls|plz|kindly|need|give|gimme|make|create|generate|prepare|put|whip|fire|type|help|what|how|thoughts|an|a|the|i|im|ok|okay|now|so|and|then|also|just|but|btw|hey|hi|hello|yo|did|do|does|is|are|was|were|who|any|have|has|search|find|show|check|pull|look|remind|text|hit|mind|we|response|msg)(?:\'s)?|i\'?(?:d|m|ll|ve))$/i';

	/** Words that are never a sign-off's name, whatever their case ("Thanks! If not…", "thanks, and draft…"). */
	const NOT_NAME = '/^(?:she|he|they|it|you|u|we|me|us|them|him|her|for|to|so|much|again|a|an|the|and|in|on|at|of|all|very|everyone|anyone|both|guys|lot|alot|tons|anyway|though|btw|but|or|if|as|now|then|when|once|also|just|please|pls|otherwise|because|since|unless|until|while|here|there|this|that|these|those|no|yes|ok|okay|advance|thanks|thank|thx|ty|cheers|regards|sincerely|appreciated)$/i';

	/** A phone number or an email address — a signature's contact line. */
	const CONTACT = '(?:(?:\+?1[\s.\-]?)?\(?\d{3}\)?[\s.\-]?\d{3}[\s.\-]\d{4}(?:[ \t]*(?:x|ext\.?)[ \t]*\d{1,5})?|[A-Za-z0-9][A-Za-z0-9._%+\-]*@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,24})';

	/**
	 * A customer's question to the company, as it appears in a pasted message: "did you get my email?", "have you
	 * had a chance to look at our quote?", "can you send me the quote?", "when will I hear back?". Nobody asks the
	 * assistant these about their own mailbox, so they never make a turn a mailbox READ. "Did you get / see…"
	 * counts only about the asker's OWN item ("my email", "the photos I sent") — "have you seen Robin's email?"
	 * is the user's question and stays a READ.
	 */
	const CUSTOMER_VOICE = '/^(?:(?:hi|hello|hey|dear)\s+[a-z][a-z\'\-]*[\s,!:]+)?(?:(?:hi|hello|hey|so|ok|okay|also|and|but|btw|just\s+wondering|quick\s+question)\b[\s,!:]*)*(?:(?:did|have|has|had|do|does)\s+(?:you|u|y\'?all|you\s+guys|your\s+(?:team|office|company|crew))\s+(?:(?:ever|already|even|guys|all)\s+)?(?:get|got|gotten|receive|received|see|seen|saw|have\s+a\s+chance|had\s+a\s+chance|get\s+a\s+chance)\b(?=.*?(?:\b(?:my|our|mine|ours)\b|\b(?:i|we)\s+(?:sent|emailed|e-mailed|texted|mailed|left|forwarded|submitted|attached|called|wrote)\b))|(?:can|could|would|will)\s+(?:you|u|y\'?all|you\s+guys)\s+(?:please\s+|pls\s+)?(?:send|email|e-mail|text|mail|forward|call|reply|respond|get\s+back)\s+(?:to\s+)?(?:me|us)\b|did\s+(?:you|u|y\'?all|you\s+guys)\s+(?:ever\s+|already\s+)?send\s+(?:(?:me|us)\s+)?(?:the|a|my|our|that)\s+(?:quote|estimate|invoice|proposal|contract|info|information|details|pictures|photos|pics|price|pricing|bid)\b|(?:were|was)\s+(?:you|u|anyone|someone)\s+able\s+to\s+(?:see|get|read|open|look\s+at)\s+(?:my|our)\b|(?:has|have)\s+(?:anyone|anybody|someone|somebody)\s+(?:had\s+a\s+chance\s+to\s+|gotten\s+to\s+|been\s+able\s+to\s+)?(?:look(?:ed)?\s+at|read|seen|see|get|got|gotten|review(?:ed)?|check(?:ed)?)\s+(?:my|our)\b|(?:when|how\s+soon)\s+(?:will|can|should|do|would)\s+(?:i|we)\s+(?:hear|expect|get\s+(?:a|an|the|my|our)\s+(?:response|reply|answer|quote|estimate|email)))/';

	/** Where one sentence ends and the next begins: . ! ? then space (not after Mr./Dr./St.…), ": ", a spaced dash, an em dash. */
	const SPLIT = '/(?<=[.!?])(?<!\bmr\.|\bmrs\.|\bms\.|\bdr\.|\bst\.|\bjr\.|\bsr\.|\bvs\.|\betc\.|\bno\.|\bave\.|\brd\.|\bblvd\.|\bln\.|\bct\.|\bapt\.|\bste\.|\bapprox\.)\s+|(?<=:)\s+|\s[\-\x{2013}\x{2014}]+\s|\x{2014}/iu';

	/** @var array{0:string,1:array}|null memo of the last detect() — four routers ask about the same turn. */
	private static $memo = null;

	/**
	 * Is this turn an instruction to write?
	 *
	 * @param string $user_message the user's words for this turn (a leading [CONTINUATION] block is dropped).
	 * @return array { compose:bool, reason:string, phrase:string, email:bool }
	 *               reason ∈ {'', 'leading', 'phrase', 'imperative', 'advice', 'request'}; email = the write
	 *               names email ("an email", "email her") rather than a reply / message / text in general.
	 */
	public static function detect( string $user_message ): array {
		if ( null !== self::$memo && self::$memo[0] === $user_message ) {
			return self::$memo[1];
		}
		$result     = self::analyse( $user_message );
		self::$memo = array( $user_message, $result );
		return $result;
	}

	/** Convenience: detect()['compose']. */
	public static function is_compose( string $user_message ): bool {
		$d = self::detect( $user_message );
		return ! empty( $d['compose'] );
	}

	/**
	 * The email addresses in the user's words, lower-cased and de-duplicated, in the order typed. Pure.
	 * A trailing sentence dot is not part of an address, a host with no TLD ("user@localhost") is not one,
	 * and a TLD is all-lower or ALL-UPPER — so a paste glued to the next sentence ("…@example.com.Thanks",
	 * "…@example.comPlease") still yields "…@example.com".
	 *
	 * @param string $text the user's words for this turn (a leading [CONTINUATION] block is dropped).
	 * @return string[]
	 */
	public static function addresses_in( string $text ): array {
		$text = self::own_words( $text );
		if ( '' === $text || false === strpos( $text, '@' ) ) {
			return array();
		}
		if ( ! preg_match_all( '/[A-Za-z0-9][A-Za-z0-9._%+\-]*@(?:[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?\.[A-Z][a-z]{1,23}(?![A-Za-z.])|[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?)*\.(?:[a-z]{2,24}|[A-Z]{2,24})(?![a-z]))/', $text, $m ) ) {
			return array();
		}
		$out = array();
		foreach ( $m[0] as $a ) {
			$a = strtolower( rtrim( (string) $a, '.' ) );
			if ( false !== filter_var( $a, FILTER_VALIDATE_EMAIL ) ) {
				$out[ $a ] = true;
			}
		}
		return array_keys( $out );
	}

	// ─────────────────────────────────────────────────────────────────────────────────────────────────

	/** The user's own words: drop a carried "[CONTINUATION] … My new message (…):" wrapper if present. */
	private static function own_words( string $msg ): string {
		if ( false !== strpos( $msg, '>' ) ) {
			$msg = (string) preg_replace( '/^[ \t]*(?:>[ \t]?)+/m', '', $msg ); // a quoted reply's "> " / ">> " line markers
		}
		if ( 0 === strpos( ltrim( $msg ), '[CONTINUATION]' ) ) {
			$at = strrpos( $msg, 'My new message' );
			if ( false !== $at ) {
				$colon = strpos( $msg, ':', $at );
				if ( false !== $colon ) {
					$msg = substr( $msg, $colon + 1 );
				}
			}
		}
		return trim( $msg );
	}

	private static function analyse( string $user_message ): array {
		$no   = array( 'compose' => false, 'reason' => '', 'phrase' => '', 'email' => false );
		$orig = self::own_words( $user_message );
		if ( '' === $orig ) {
			return $no;
		}

		// leading — a turn that opens with a write verb.
		if ( preg_match( self::LEADING, strtolower( substr( $orig, 0, 400 ) ), $m ) ) {
			return array( 'compose' => true, 'reason' => 'leading', 'phrase' => $m[1], 'email' => (bool) preg_match( '/\be-?mail/', strtolower( substr( $orig, 0, 400 ) ) ) );
		}

		if ( strlen( $orig ) > self::TAIL_BYTES ) {
			$orig = substr( $orig, -self::TAIL_BYTES );
			$orig = (string) preg_replace( '/^[\x80-\xBF]+/', '', $orig ); // never start mid-character
			if ( preg_match( '/^[^\n]{0,2000}\n|^[^.!?\n]{0,500}[.!?]+\s+/', $orig, $cut ) ) {
				$orig = (string) substr( $orig, strlen( $cut[0] ) ); // …nor mid-sentence: the cut-off fragment is nobody's words
			}
		}

		$own = self::user_text( $orig );
		$c   = self::decide( $own );
		if ( null === $c && $own !== $orig && ! self::asks_anything( $own ) ) {
			// The user's own words hold no instruction and no question — theirs sits inside or around the pasted
			// message ("Hi, did you get my email? Write her back… Thanks, Robin", "…estimate.\nReply saying 3
			// works\nThanks, Robin"), or a sign-off was misread. Read the whole turn the same way. (A question of
			// their own with no mail word — "when did this come in?" — stays theirs: no fallback.)
			$c = self::decide( $orig );
		}
		if ( null === $c || 'write' !== $c['kind'] ) {
			return $no;
		}
		$write = $c;
		return array(
			'compose' => true,
			'reason'  => $write['reason'],
			'phrase'  => $write['phrase'],
			'email'   => (bool) preg_match( '/\be[\s\-]?mail|\bemial\b|\bemal\b|\beamil\b/', strtolower( $write['phrase'] ) ),
		);
	}

	/**
	 * The turn's verdict from its sentences: a READ if any sentence is one (a mailbox question outranks a write
	 * in the same turn), else the last WRITE, else null when no sentence is an instruction either way.
	 *
	 * @return array{kind:string, reason:string, phrase:string}|null
	 */
	private static function decide( string $text ) {
		$write = null;
		foreach ( self::sentences( $text ) as $s ) {
			if ( $s['quoted'] ) {
				continue;
			}
			$c = self::classify( $s['text'] );
			if ( 'read' === $c['kind'] ) {
				return $c;
			}
			if ( 'write' === $c['kind'] ) {
				$write = $c;
			}
		}
		return $write;
	}

	/** Does any sentence of $text ask something (a question opener, a read opener, or a "?")? */
	private static function asks_anything( string $text ): bool {
		foreach ( self::sentences( $text ) as $s ) {
			$low = strtolower( ltrim( $s['text'], " \t-*>~" ) );
			if ( false !== strpos( $low, '?' ) || preg_match( self::QUESTION_OPENER, $low ) || preg_match( self::READ_OPENER, $low ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The user's own words this turn: a carried [CONTINUATION] wrapper dropped and a pasted message (from its
	 * start to its last sign-off — see user_text()) removed. With no sign-off it is the whole turn. Pure.
	 *
	 * @param string $user_message the user's words for this turn.
	 * @return string
	 */
	public static function own_text( string $user_message ): string {
		return trim( self::user_text( self::own_words( $user_message ) ) );
	}

	/**
	 * Remove the pasted message(s): each from its start (a greeting, or the line after one that ends in a colon,
	 * or the start of what is left) to the end of its sign-off, working back from the LAST sign-off — so a
	 * thread of two pasted messages ("Hi Alex, did Jordan get my email? Thanks, Robin\nHi Alex, just following
	 * up. Thanks, Robin\nreply to her") is set aside whole. With no sign-off, nothing is removed.
	 */
	private static function user_text( string $text ): string {
		$ends = self::signoff_ends( $text );
		if ( empty( $ends ) ) {
			return $text; // no sign-off, no paste
		}
		// Where a paste can start: the start of the turn, a greeting, the line after a line ending in ":".
		$starts = array( 0 );
		if ( preg_match_all( '/(?:^|[\n.!?:]\s*)((?:hi|hello|hey|dear|good\s+(?:morning|afternoon|evening))\b[^\n]{0,40}?[,!\n])/i', $text, $g, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $g[1] as $x ) {
				$starts[] = (int) $x[1];
			}
		}
		if ( preg_match_all( '/:[ \t]*\n/', $text, $c, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $c[0] as $x ) {
				if ( ! self::quote_intro_before( $text, (int) $x[1] ) ) {
					$starts[] = (int) $x[1] + strlen( $x[0] );
				}
			}
		}
		// The user's own opening is never part of the paste after it: a first LINE that is an instruction or a
		// mailbox question ("Did Robin reply to my email?\nThanks\ndraft a follow-up if not", "Help me write back
		// to this lady\nWhen can I expect a response? Thanks, Robin"), or a first SENTENCE that is an instruction
		// or a question about the user's own mail or about THIS ("Did I already reply to this? can you come out
		// Tuesday? Thanks, Robin\nIf not, draft a reply"). A greeting opens a paste instead.
		if ( ! preg_match( '/^\s*(?:hi|hello|hey|dear|good\s+(?:morning|afternoon|evening))\b/i', $text ) ) {
			$nl = strpos( $text, "\n" );
			if ( false !== $nl && $nl > 0 ) {
				$c = self::decide( trim( (string) substr( $text, 0, $nl ) ) );
				if ( null !== $c ) {
					$starts[] = $nl + 1;
				}
			}
			$line = false === $nl ? $text : (string) substr( $text, 0, $nl );
			$segs = preg_split( self::SPLIT, $line, 2, PREG_SPLIT_OFFSET_CAPTURE );
			if ( is_array( $segs ) && 2 === count( $segs ) ) {
				$c = self::classify( trim( (string) $segs[0][0] ) );
				if ( 'write' === $c['kind'] || ( 'read' === $c['kind'] && preg_match( '/\b(?:i|we|this|these)\b/', strtolower( (string) $segs[0][0] ) ) ) ) {
					$starts[] = (int) $segs[1][1];
				}
			}
		}
		// "Help me respond to this: did Jordan get my email… Thanks, Robin" — a write instruction, ": " (or " - "), the paste.
		if ( preg_match_all( '/(?::[ \t]+|(?<![ \t])[ \t]+[\-\x{2013}\x{2014}][ \t]+)(?=\S)/u', $text, $cc, PREG_OFFSET_CAPTURE ) ) {
			foreach ( array_slice( $cc[0], 0, 20 ) as $x ) {
				$pos  = (int) $x[1];
				$line = (string) substr( $text, 0, $pos );
				$line = (string) substr( $line, (int) strrpos( "\n" . $line, "\n" ) );
				$segs = preg_split( '/(?<=[.!?])\s+/', $line );
				$last = trim( (string) end( $segs ) );
				$c    = '' === $last ? null : self::classify( $last );
				if ( null !== $c && 'write' === $c['kind'] ) {
					$starts[] = $pos + strlen( $x[0] );
				}
			}
		}
		sort( $starts );
		// From the LAST sign-off back: each paste runs from the last start before its sign-off to the sign-off.
		$keep   = array();
		$cursor = strlen( $text );
		$i      = count( $ends ) - 1;
		for ( $n = 0; $i >= 0 && $n < 10; $n++ ) {
			$end = $ends[ $i ];
			$beg = 0;
			foreach ( $starts as $st ) {
				if ( $st >= $end ) {
					break;
				}
				$beg = $st;
			}
			$keep[] = trim( (string) substr( $text, $end, $cursor - $end ) );
			$cursor = $beg;
			while ( $i >= 0 && $ends[ $i ] > $beg ) {
				$i--; // a sign-off inside this paste ("Thanks! Can you come Tuesday?…") is part of it
			}
		}
		$keep[] = trim( (string) substr( $text, 0, $cursor ) );
		// A blank line between the kept pieces: "Morgan wrote:" before a removed paste must not make the user's
		// own instruction after it read as part of the quotation.
		return implode( "\n\n", array_reverse( $keep ) );
	}

	/**
	 * Where pasted messages end: the end of every sign-off, ascending (empty when there is none). The sign-offs:
	 *   "Thanks, Robin" / "Best,\nRobin Example" / "Thanks so much, Robin E." — a closing, then the name: one
	 *       word, plus a Capitalised second word on the same line; a word that starts an instruction is never
	 *       part of the name ("Thanks, reply to her" has no name, "Thanks, Robin\nShould I…" a one-word one);
	 *   "Thanks!" / "Thanks." standing alone;
	 *   "-Robin" / "— Robin E." / "~Robin" — at a line's start or after a sentence's end (an en or em dash after
	 *       any space), with nothing after the name on its line but the user's own instruction ("-Robin reply
	 *       to this");
	 *   a name alone on the line under the message ("…same model.\nRobin E.") and "Robin E." straight after a
	 *       sentence's end;
	 *   "Sent from my iPhone", "Sent from Yahoo Mail for Android", "Get Outlook for iOS" — the device words
	 *       that follow are Capitalised or numbers ("iPhone 15 Pro"), never an instruction ("…iPhone  reply");
	 * and, below the last of them, a signature's phone numbers and addresses.
	 */
	private static function signoff_ends( string $text ): array {
		$ends = array();

		// A closing and a name.
		if ( preg_match_all( '/\b(?:(?:thanks|thank\s+you)(?:\s+(?:so|very)\s+much|\s+again|\s+a\s+lot|\s+in\s+advance)?(?:\s+for\s+(?:your|the|all\s+(?:your|the))\s+[a-z]+(?:\s+[a-z]+)?)?|many\s+thanks|thx|ty|regards|best\s+regards|kind\s+regards|warm\s+regards|best|sincerely|cheers|warmly|respectfully|take\s+care|talk\s+soon|appreciate\s+it)[,!.]*(?:[ \t]*[^\sA-Za-z0-9]{1,8})?\s+([A-Za-z][A-Za-z\'\-]+)((?-i)[ \t]+[A-Z][A-Za-z\'\-]*\.?)?/i', $text, $all, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			foreach ( $all as $m ) {
				$stop = self::name_end( $m );
				if ( $stop < 0 ) {
					continue; // "Thanks, reply to her" / "thanks, and draft…" / "Thanks! If not…" — no name, no sign-off
				}
				if ( ctype_lower( substr( $m[1][0], 0, 1 ) ) && ! preg_match( '/^[ \t]*[.,!]?[ \t]*(?:\r?\n|$)/', (string) substr( $text, $stop, 40 ) ) ) {
					continue; // a lower-case "name" ("thanks robin") must end its line — "…? thanks bud, draft…" is the user's
				}
				$ends[] = $stop;
			}
		}
		// "Thanks!" standing alone at the end of its line ("…let me know. Thanks!\nreply to her"). With more words
		// after it on the same line it is part of the user's own sentence ("…? thanks! then draft a follow-up").
		if ( preg_match_all( '/\b(?:thanks|thank\s+you|thx|cheers)[!.]+(?=[ \t]*\r?\n)/i', $text, $all, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $all[0] as $m ) {
				$ends[] = (int) $m[1] + strlen( $m[0] );
			}
		}
		// "-Robin", "— Robin E.", "~Robin".
		if ( preg_match_all( '/(?:(?:^|\n|(?<=[.!?)]))[ \t]*+[\-\x{2013}\x{2014}~]|(?<=[ \t])[\x{2013}\x{2014}])[ \t]*+([A-Z][A-Za-z\'\-]+)([ \t]++[A-Z][A-Za-z\'\-]*\.?)?/u', $text, $all, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			foreach ( $all as $m ) {
				$stop = self::name_end( $m );
				if ( $stop >= 0 && self::ends_line_or_instruction( $text, $stop ) && ! self::in_list( $text, (int) $m[1][1] ) ) {
					$ends[] = $stop;
				}
			}
		}
		// "-- \nRobin" — the standard signature separator line, then the name.
		if ( preg_match_all( '/(?:^|\n)--[ \t]*\r?\n[ \t]*([A-Z][A-Za-z\'\-]+)([ \t]+[A-Z][A-Za-z\'\-]*\.?)?/', $text, $all, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			foreach ( $all as $m ) {
				$stop = self::name_end( $m );
				if ( $stop >= 0 ) {
					$ends[] = $stop;
				}
			}
		}
		// A name alone on the line under the message.
		if ( preg_match_all( '/[.!?)"][ \t]*\r?\n[ \t]*([A-Z][a-z\'\-]+)([ \t]+[A-Z](?:[a-z\'\-]+\.?|\.))?[ \t]*(?=\r?\n|$)/', $text, $all, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			foreach ( $all as $m ) {
				$stop = self::name_end( $m );
				if ( $stop >= 0 && ! self::in_list( $text, (int) $m[1][1] ) ) {
					$ends[] = $stop;
				}
			}
		}
		// "Robin E." straight after a sentence's end.
		if ( preg_match_all( '/(?<=[.!?])[ \t]+([A-Z][a-z\'\-]+)([ \t]+[A-Z]\.)/', $text, $all, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			foreach ( $all as $m ) {
				$stop = self::name_end( $m );
				if ( $stop >= 0 && self::ends_line_or_instruction( $text, $stop ) ) {
					$ends[] = $stop;
				}
			}
		}
		// "Sent from my iPhone" and its kin.
		if ( preg_match_all( '/(?:\bSent|(?:^|\n)[ \t]*sent)\s+from\s+(?:my\s+)?[A-Za-z][A-Za-z0-9]*|\bGet\s+Outlook\s+for\s+[A-Za-z]+/', $text, $all, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $all[0] as $m ) {
				$stop = (int) $m[1] + strlen( $m[0] );
				while ( preg_match( '/^[ \t,]+([A-Z0-9][A-Za-z0-9\-]*|for|on|smartphone|phone|device|tablet)(?![A-Za-z0-9\-])/', substr( $text, $stop, 40 ), $dm ) && ! preg_match( self::INSTRUCTION_WORD, $dm[1] ) ) {
					$stop += strlen( $dm[0] );
				}
				$ends[] = $stop;
			}
		}
		// The signature's phone numbers and addresses.
		foreach ( $ends as $k => $end ) {
			while ( preg_match( '/^[ \t]*(?:\r?\n[ \t]*)?(?:[|\x{2022}][ \t]*)?(?:(?:cell|mobile|phone|tel|office|c|m|p|o)[ \t]*[:.][ \t]*)?(?:' . self::CONTACT . '|www\.[A-Za-z0-9.\-\/]+|https?:\/\/[^\s]+)/iu', substr( $text, $end, 120 ), $cm ) ) {
				$end += strlen( $cm[0] );
			}
			$ends[ $k ] = $end;
		}
		$ends = array_values( array_unique( $ends ) );
		sort( $ends );
		return $ends;
	}

	/**
	 * Where the name in a sign-off match ends ($m: [whole, first word, optional second word], with offsets), or
	 * -1 when there is no name — a first word that starts an instruction ("Thanks, reply…") or is never a name
	 * ("thanks, and…", "Thanks! If…") means there is no sign-off at all.
	 */
	private static function name_end( array $m ): int {
		if ( preg_match( self::INSTRUCTION_WORD, $m[1][0] ) || preg_match( self::NOT_NAME, $m[1][0] ) ) {
			return -1;
		}
		$stop = (int) $m[1][1] + strlen( $m[1][0] );
		if ( isset( $m[2] ) && '' !== $m[2][0] && -1 !== (int) $m[2][1] && ! preg_match( self::INSTRUCTION_WORD, rtrim( trim( $m[2][0] ), '.' ) ) ) {
			$stop = (int) $m[2][1] + strlen( $m[2][0] );
		}
		return $stop;
	}

	/** Does the sign-off that stops at $pos end its line, or run straight into the user's instruction ("-Robin reply to this")? */
	private static function ends_line_or_instruction( string $text, int $pos ): bool {
		$rest = (string) substr( $text, $pos, 60 );
		if ( preg_match( '/^[ \t]*[.,!]?[ \t]*(?:\r?\n|$)/', $rest ) ) {
			return true;
		}
		return preg_match( '/^[ \t]*[.,!]?[ \t]+([A-Za-z][A-Za-z\'\-]*)/', $rest, $w ) && preg_match( self::INSTRUCTION_WORD, $w[1] );
	}

	/**
	 * Is the line holding $pos one line of a list — a neighbouring line that is also a bullet ("- Robin Example\n
	 * - Sam Example") or also just a name ("Robin Example\nSam Example")? A list of names is the user's, not a sign-off.
	 */
	private static function in_list( string $text, int $pos ): bool {
		$nl   = strrpos( substr( $text, 0, $pos ), "\n" );
		$ls   = false === $nl ? 0 : $nl + 1;
		$le   = strpos( $text, "\n", $pos );
		$next = false === $le ? '' : (string) substr( $text, $le + 1, 200 );
		$prev = 0 === $ls ? '' : (string) substr( $text, max( 0, $ls - 201 ), min( 200, $ls - 1 ) );
		$prev = (string) substr( $prev, (int) strrpos( "\n" . $prev, "\n" ) );
		$item = '/^[ \t]*(?:[\-\x{2013}\x{2014}~*\x{2022}]|\d+[.)])|^[ \t]*[A-Z][a-z\'\-]+(?:[ \t]+[A-Z][A-Za-z\'\-]*\.?)?[ \t]*(?:\r?\n|$)/u';
		return ( '' !== $next && preg_match( $item, $next ) ) || ( '' !== $prev && preg_match( $item, $prev . "\n" ) );
	}

	/** Is the colon at $pos introducing a quotation ("She wrote:")? Those are handled as quotations instead. */
	private static function quote_intro_before( string $text, int $pos ): bool {
		$from = max( 0, $pos - 40 );
		return (bool) preg_match( self::QUOTE_INTRO, strtolower( rtrim( substr( $text, $from, $pos - $from ) ) ) );
	}

	/**
	 * Cut the user's text into sentences: at . ! ? followed by space (not after Mr./Dr./St.…), at line
	 * breaks, at ": ", at a spaced dash or an em dash. A segment after "…she wrote:" is marked quoted, to
	 * the end of its block (a blank line ends a block).
	 *
	 * @return array<int, array{text:string, quoted:bool}>
	 */
	private static function sentences( string $text ): array {
		$out   = array();
		$split = self::SPLIT;
		foreach ( (array) preg_split( '/\n\s*\n/', $text ) as $block ) {
			$quote = false;
			foreach ( (array) preg_split( '/\n+/', (string) $block ) as $line ) {
				$segs = preg_split( $split, (string) $line, -1, PREG_SPLIT_NO_EMPTY );
				if ( false === $segs ) {
					$segs = preg_split( '/(?<=[.!?:])\s+|\s-+\s/', (string) $line, -1, PREG_SPLIT_NO_EMPTY ); // invalid UTF-8: split without /u
				}
				foreach ( (array) $segs as $seg ) {
					$seg = trim( (string) $seg );
					if ( '' === $seg ) {
						continue;
					}
					$out[] = array( 'text' => $seg, 'quoted' => $quote );
					if ( ':' === substr( $seg, -1 ) && preg_match( self::QUOTE_INTRO, strtolower( rtrim( substr( $seg, 0, -1 ) ) ) ) ) {
						$quote = true; // the rest of this block is someone else's words
					}
				}
			}
		}
		return $out;
	}

	/**
	 * READ, WRITE or other — see the class doc.
	 *
	 * @return array{kind:string, reason:string, phrase:string}
	 */
	private static function classify( string $s ): array {
		$s     = ltrim( $s, " \t-*>~\xE2\x80\x93\x94\xA2" ); // "-- did I ever answer this?", "• reply to her"
		$low   = strtolower( $s );
		$other = array( 'kind' => 'other', 'reason' => '', 'phrase' => '' );
		$read  = array( 'kind' => 'read', 'reason' => '', 'phrase' => '' );

		// "Did I tell you what she said?" is about this conversation, not the mailbox.
		if ( preg_match( '/^(?:did|have|had)\s+i\s+(?:ever\s+)?(?:tell|told|mention|mentioned|say|said|show|showed|shown)\s+(?:(?:it\s+)?to\s+)?you\b/', $low ) ) {
			return $other;
		}
		// "did I?", "did I ever do that?" — a follow-up about an action the turn just described.
		if ( preg_match( self::SHORT_RECALL, $low ) ) {
			return $read;
		}
		// Asking for help writing one ("what should I write back", "thoughts on a reply?") — a WRITE.
		if ( preg_match( self::ADVICE, $low, $m ) ) {
			return array( 'kind' => 'write', 'reason' => 'advice', 'phrase' => $m[0] );
		}

		$w = self::write_match( $s, $low );

		// Read-then-write in one sentence ("find Robin's email and reply to it") — the read can be answered now.
		if ( null !== $w && preg_match( self::READ_THEN, $low, $rm, PREG_OFFSET_CAPTURE ) && (int) $rm[0][1] < (int) $w[2] ) {
			return $read;
		}
		// A sentence that OPENS with a write verb is an instruction even with a "?" ("Reply to her?").
		if ( null !== $w && 'imperative' === $w[0] && 0 === (int) $w[2] ) {
			return array( 'kind' => 'write', 'reason' => $w[0], 'phrase' => $w[1] );
		}
		// A polite request to write ("can you write…?", "could we email her back…?", "mind replying?").
		if ( null !== $w && ( preg_match( '/^(?:(?:ok|okay|so|hey|and|also)\b[\s,]*)*(?:(?:can|could|would|will|should|shall)\s+(?:you|u|we|i)\b|please\b|pls\b|kindly\b|(?:can|could)\s+i\s+(?:get|have)\b|(?:would\s+you\s+)?mind\b)/', $low )
			|| preg_match( self::REQUEST_FRAME, (string) substr( $low, 0, (int) $w[2] ) ) ) ) {
			return array( 'kind' => 'write', 'reason' => 'request', 'phrase' => $w[1] );
		}

		// A question about mail — or a request to SEE mail — is a READ.
		$questiony = preg_match( self::QUESTION_OPENER, $low ) || preg_match( self::READ_OPENER, $low ) || false !== strpos( $low, '?' );
		if ( $questiony && preg_match( self::MAIL_SIGNAL, (string) preg_replace( '/\btell\s+(?:me|us)\b/', ' ', $low ) ) ) {
			// "did you get my email?" is a pasted customer's — but "did you see my email FROM the supplier?" / "when
			// will I hear back from the county?" is the user asking about mail they received.
			$from_sender = preg_match( '/\bfrom\s+(?!(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday|mon|tue|tues|wed|thu|thur|thurs|fri|sat|sun|last|yesterday|today|tonight|this|earlier|before|the\s+other\s+day|a\s+(?:few|couple)|\d|you|your|u|my|our|me|us)\b)[a-z]/', $low );
			return ( ! $from_sender && preg_match( self::CUSTOMER_VOICE, $low ) ) ? $other : $read;
		}

		if ( null !== $w ) {
			return array( 'kind' => 'write', 'reason' => $w[0], 'phrase' => $w[1] );
		}
		return $other;
	}

	/**
	 * The first write instruction in one sentence, or null.
	 *
	 * @return array{0:string,1:string,2:int}|null [reason, phrase, byte offset in the sentence]
	 */
	private static function write_match( string $orig, string $low ) {
		$io  = '(?:(?:this|that|the)\s+[a-z][a-z\'\-]*\s+|' . self::IO_WORD . '\s+(?:' . self::IO_WORD . '\s+)?)?';
		$obj = '(?:(?:up|out|off|together|back)\s+)?(?:(?:an?|the|this|that|one|another|my|our|some|her|his|their)\s+(?:' . self::MOD_WORD . '\s+){0,3}|(?:' . self::ADJ . '\s+){0,3})' . self::NOUN . '\b';
		$one = '(?:an?|one)\s+(?:' . self::MOD_WORD . '\s+){0,2}' . self::NOUN_ONE . '\b';

		// phrase — a compose verb + an email-shaped object, anywhere in the sentence (never in reported speech).
		foreach ( array(
			'/\b' . self::VERB_STRONG . '\s+' . $io . $obj . '/',
			'/\b' . self::VERB_SINGULAR . '\s+(?:me\s+)?(?:' . $one . '(?:\s+(?:out\s+)?(?:to|for|i\s+can)\b|\s*[.!?]*\s*$)|' . self::NOUN_ONE . '\s+(?:out\s+)?(?:to|for)\b)/',
		) as $re ) {
			if ( ! preg_match_all( $re, $low, $all, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $all[0] as $hit ) {
				$pos  = (int) $hit[1];
				$from = max( 0, $pos - 80 );
				if ( ! preg_match( self::REPORTED, substr( $low, $from, $pos - $from ) ) ) {
					return array( 'phrase', substr( $orig, $pos, strlen( $hit[0] ) ), $pos );
				}
			}
		}
		// A noun-phrase instruction: "An email to this individual…", "email response please", "Response please".
		if ( preg_match( '/^(?:(?:an?|one)\s+(?:quick\s+|short\s+)?' . self::NOUN_ONE . '\s+(?:to|for)\b|e-?mail\s+(?:response|reply)\b|(?:a\s+)?(?:response|reply)\s+please\b)/', $low, $m ) ) {
			return array( 'imperative', $m[0], 0 );
		}

		// imperative — a communication verb where an instruction can start.
		$imperatives = array(
			'/\b(?:repl(?:y|ies)|respond|rspnd|respnd|rspond|repsond|rply|answer)\b(?!\s+(?:from|by|yet|is|was|are|were|will|would|should|has|had)\b)/',
			'/\bwrite\s+(?:(?:her|him|them|this\s+\w+|that\s+\w+|the\s+\w+|[a-z][a-z\'\-]*)\s+)?back\b/',
			'/\bwrite\s+(?:to\b(?!\s+(?:me|us)\b)|(?:her|him|them)\b|(?:this|that|the)\s+(?:person|individual|customer|client|homeowner|lady|guy|gentleman|woman|man|lead|prospect|family|couple|contact)\b|(?:up\s+|out\s+)?something\b)/',
			'/\b(?:draft|compose)\b/',
			'/\b(?:get\s+back|follow\s+up|circle\s+back|reach\s+out)\s+(?:(?:to|with)\s+|w\/\s*)(?!(?:me|us)\b)/',
			'/\b(?:tell|ask|text)\s+(?!(?:me|us|you)\b)(?:her|him|them|(?:this|that|the)\s+\w+|[a-z][a-z\'\-]*)\b/',
			'/\blet\s+(?!(?:me|us)\b)(?:her|him|them|(?:this|that|the)\s+\w+|[a-z][a-z\'\-]*)\s+know\b/',
			'/\bhit\s+(?:her|him|them|(?:this|that|the)\s+\w+|[a-z][a-z\'\-]*)\s+back\b/',
			'/\bmind\s+(?:replying|responding|emailing|writing|answering|texting)\b/',
			'/\brevert\s+(?:back\s+)?to\s+(?:her|him|them|(?:the|this|that)\s+(?:customer|client|lady|guy|gentleman|homeowner|lead|person|individual|party))\b/',
			'/\be-?mail\s+to\s+(?!(?:me|us|myself)\b)(?:her|him|them|(?:this|that|the)\s+[a-z][a-z\'\-]*)\b/',
			'/\bget\s+(?:her|him|them)\s+(?:an?\s+)?(?:quick\s+)?(?:e-?mail|reply|response|note|message|text)\b/',
			'/\b(?:message|msg|mail)\s+(?!(?:me|us|it|you|from|to|for|about|in|on|at|of|with|by|address(?:es)?|account|inbox|thread|chain|list|was|is|has|had|i|we|and|or|the|a|an|my|your)\b)(?:her|him|them|(?:this|that)\s+[a-z][a-z\'\-]*|[a-z][a-z\'\-]*)(?=\s+(?:and|to|asking|ask|telling|letting|saying|about|regarding|re|that|back|with|if|whether|for|today|tomorrow|now|asap|please|pls)\b|\s*[.!?,;:]|\s*$)/',
			'/\be-?mail\s+(?!(?:me|us|it|from|to|for|about|in|on|at|of|with|by|address(?:es)?|account|inbox|thread|chain|list|was|is|has|had|i|we|and|or)\b)(?:(?:this|that|the)\s+[a-z][a-z\'\-]*|back|[a-z][a-z\'\-]*\.?(?:\s+(?!(?:sent|wrote|replied|asked|said|forwarded|emailed|got|received|mentioned)\b)[a-z][a-z\'\-]*)?)(?=\s+(?:and|to|asking|ask|telling|letting|saying|about|regarding|re|that|back|with|if|whether|for|today|tomorrow|now|asap|please|pls)\b|\s*[.!?,;:]|\s*$)/',
		);
		foreach ( $imperatives as $re ) {
			if ( ! preg_match_all( $re, $low, $all, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $all[0] as $hit ) {
				$pos = (int) $hit[1];
				if ( self::at_instruction_start( $orig, $low, $pos ) ) {
					return array( 'imperative', substr( $orig, $pos, strlen( $hit[0] ) ), $pos );
				}
			}
		}
		return null;
	}

	/**
	 * Can an instruction start at $pos in this sentence? At its start, right after a request frame
	 * ("please", "can you", "and"), after a sentence-opening one ("I need to…", "let's…"), after a greeting
	 * ("Hi Alex, reply…"), or when the verb is Capitalized mid-sentence — which is how the user's own
	 * sentence begins after a paste with no punctuation. Only the few words before $pos are read.
	 */
	private static function at_instruction_start( string $orig, string $low, int $pos ): bool {
		$from   = max( 0, $pos - 60 );
		$before = rtrim( substr( $low, $from, $pos - $from ) );
		if ( '' === $before ) {
			return true; // the sentence starts here
		}
		if ( preg_match( '/(?:^|[\s,.;:!?(\-])' . self::FRAME . '[\s,]*$/', $before . ' ' ) ) {
			return true; // "please reply…", "can you email her…", "ok, reply…"
		}
		if ( ',' === substr( $before, -1 ) ) {
			return true; // a clause, then the instruction: "For this lady, write back…", "If she hasn't, email her"
		}
		if ( preg_match( '/[ \t]{2,}$/', substr( $low, max( 0, $pos - 4 ), min( 4, $pos ) ) ) ) {
			return true; // after a double space — where a paste with no punctuation ends and the user's words start
		}
		if ( 0 === $from && preg_match( '/^' . self::FRAME_START . '[\s,]*$/', $before ) ) {
			return true; // "I need to reply…", "let's email her…" — only when the sentence opens with it
		}
		if ( 0 === $from && preg_match( '/^(?:hi|hey|hello|yo)\s+[a-z][a-z\'\-]*[\s,]*$/', $before ) ) {
			return true; // "Hi Alex, reply to her…"
		}
		if ( preg_match( '/(?:\d{3}[\s.\-]?\d{4}|@[a-z0-9\-]+(?:\.[a-z0-9\-]+)*\.[a-z]{2,24}|https?:\/\/\S+|\bwww\.\S+|\b[a-z0-9\-]+(?:\.[a-z0-9\-]+)*\.(?:com|net|org|us|co|biz|info|io|app))[.,;:]?$/', $before ) ) {
			return true; // straight after a pasted phone number, address or link: "…(555) 555-0199 reply to this"
		}
		return (bool) preg_match( '/^[A-Z]/', substr( $orig, $pos, 1 ) ); // a Capitalized verb mid-sentence
	}
}
