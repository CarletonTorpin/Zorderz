<?php
/**
 * ZDZ_Ask_Actions -- the ask bar understands the work people do, said or typed, and opens the app that does it.
 *
 * Phrases open the app; they never do the action themselves. No model and no guessing: one table of rules, sent to the
 * page as zdzData.askPhrases.actions and run by the same small interpreter on both sides (the page's, and match()
 * here). Each ask goes through three steps:
 *
 *   1. normalize(): what dictation adds is taken off -- "um", "okay so", "hey <the business's name>", "can you",
 *      "I need to", "go ahead and", "please", "real quick" -- contractions are opened ("I'm" -> "i am", "gonna" ->
 *      "going to"), spoken digits are joined ("six five two three" -> "6523") and punctuation becomes spaces;
 *   2. a question is never an action: "how many estimates did I send", "did Jordan start the job" go on as before (to
 *      the quick answers and the chat);
 *   3. the rows, most specific first: a row matches when one of its whole phrases matches, or one of its verbs AND one
 *      of its objects are both in the ask; its `not` vetoes it. The first row that matches, for an app this person can
 *      open, wins. Its context -- a customer or job name ("for Example", "the Example project", "Example's install") or
 *      an estimate number -- goes on the row and into the app's launch options.
 *
 * The row in the ask bar says what will open ("Send an estimate -- Example ... Opens Estimates"); pressing it opens the
 * app, and the person does the work there. Nothing is sent, approved or recorded from the ask bar.
 *
 * Each row's id is the action key the app records when the work is done (ZDZ_Usage::record()), so the usage view can
 * tell what was asked for from what was done.
 *
 * What belongs to the business, not to Core:
 *   - its own name, as a spoken greeting ("hey <name>, ..."): wake_words(), from ZDZ_Business_Profile;
 *   - its trade's words for things ("gate", "awning"): ZDZ_Ask_Phrases::trade_words(), from a catalog pack. Core's rows
 *     use only words every trade shares (materials, parts, supplies, hardware, pieces, panels).
 *
 * Patterns use the subset PCRE and JavaScript share (no lookbehind, possessive quantifiers, named groups or \A / \z);
 * negative lookahead is fine. Apps may add or change rows with the filter `zdz_ask_actions`.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Ask_Actions {

	/** Bump when the rules change shape, so the page can tell. */
	const V = 1;

	/** '\b(?:a|b|c)\b' from a list of alternatives (each already a pattern fragment). */
	private static function w( array $alts ): string {
		return '\b(?:' . implode( '|', $alts ) . ')\b';
	}

	/**
	 * The business's names as a spoken greeting may carry them ("hey example co"), lower case, as pattern alternatives:
	 * the trading, legal and short names and the short code from the Business Profile, each reduced to letters, digits
	 * and single spaces (a space may also be left out: "exampleco"). '' when the profile has none.
	 */
	public static function wake_words(): string {
		$names = array();
		if ( class_exists( 'ZDZ_Business_Profile' ) ) {
			foreach ( array( 'identity.trading_name', 'identity.legal_name', 'identity.short_name', 'identity.short_code' ) as $path ) {
				$v = ZDZ_Business_Profile::get( $path, '' );
				if ( is_string( $v ) && '' !== trim( $v ) ) {
					$names[] = $v;
				}
			}
		}
		return self::wake_alt( $names );
	}

	/** Names as greeting alternatives, longest first: "Example Co" -> "example ?co". */
	public static function wake_alt( array $names ): string {
		$alts = array();
		foreach ( $names as $n ) {
			$n = strtolower( trim( (string) preg_replace( '/[^a-z0-9]+/i', ' ', (string) $n ) ) );
			if ( strlen( $n ) < 2 || strlen( $n ) > 40 ) {
				continue;
			}
			$alts[ $n ] = str_replace( ' ', ' ?', $n );
		}
		uksort( $alts, function ( $a, $b ) { return strlen( (string) $b ) - strlen( (string) $a ); } );
		return implode( '|', $alts );
	}

	/**
	 * Step 1: what speech and typing add that is not the request. Ordered [pattern, replacement] pairs, applied in order,
	 * each globally; the whole list runs twice (so "okay so can you please" all comes off).
	 */
	public static function normalize_rules(): array {
		$wake = self::wake_words();
		$lead = '^(?:(?:ok(?:ay)?|alright|all right|so|hey|yo|hi|hello|well|right|now|and|then|also|oh|actually|quick question|hey (?:assistant|there' . ( '' !== $wake ? '|' . $wake : '' ) . ')' . ( '' !== $wake ? '|' . $wake : '' ) . ')\s+)+';
		$ask  = '^(?:(?:can|could|would|will) (?:you|u|we|i) (?:please |pls )?|please |pls |i (?:need|want|have|got) to |i would like to |i would love to |i am (?:going|about|trying|fixing) to |i am gonna |i will |i should |let us |let me |go ahead and |time to |need to |got to |have to |help me |i want |i need |we need to |we have to |we got to |we should |make sure (?:to |we |i )?|remember to |do not forget to |dont forget to |i think i need to |i guess i should |just |quickly |real quick |lets )+';
		return array(
			array( '[^a-z0-9\'#/\-\s]+', ' ' ),
			array( '\bi\'m\b|\bim\b', 'i am' ),
			array( '\b(?:gonna)\b', 'going to' ),
			array( '\b(?:wanna)\b', 'want to' ),
			array( '\b(?:gotta)\b', 'got to' ),
			array( '\b(?:lemme)\b', 'let me' ),
			array( '\b(?:gimme)\b', 'give me' ),
			array( '\blet\'s\b|\blets\b', 'let us' ),
			array( '\bi\'ll\b', 'i will' ),
			array( '\bi\'ve\b', 'i have' ),
			array( '\bi\'d\b', 'i would' ),
			array( '\bwe\'re\b', 'we are' ),
			array( '\bwe\'ll\b', 'we will' ),
			array( '\bit\'s\b', 'it is' ),
			array( '\bthat\'s\b', 'that is' ),
			array( '\bwhat\'s\b|\bwhats\b', 'what is' ),
			array( '\bdon\'t\b', 'do not' ),
			array( '\bdidn\'t\b', 'did not' ),
			array( '\bcan\'t\b', 'can not' ),
			array( '\b(?:um+|uh+|uhm+|er+|erm+|hmm+|ah+|mm+)\b', ' ' ),
			array( '\b(?:you know|i mean|like i said|basically|kinda|sort of)\b', ' ' ),
			array( '\bzero\b', '0' ), array( '\bone\b(?= (?:zero|one|two|three|four|five|six|seven|eight|nine|[0-9])\b)', '1' ),
			array( '\btwo\b', '2' ), array( '\bthree\b', '3' ), array( '\bfour\b', '4' ), array( '\bfive\b', '5' ),
			array( '\bsix\b', '6' ), array( '\bseven\b', '7' ), array( '\beight\b', '8' ), array( '\bnine\b', '9' ),
			array( '\b(\d) (?=\d\b)', '$1' ),
			array( '\b(\d) (?=\d\b)', '$1' ),
			array( '\b(\d) (?=\d\b)', '$1' ),
			array( '\b(\d) (?=\d\b)', '$1' ),
			array( '\s+', ' ' ),
			array( '^ | $', '' ),
			array( $lead, '' ),
			array( $ask, '' ),
			array( '\s+(?:please|pls|for me|real quick|right now|asap|thanks|thank you|ok(?:ay)?|now|thx|today please)$', '' ),
			array( '\s+(?:please|pls|for me|real quick|right now|asap|thanks|thank you|ok(?:ay)?|now|thx)$', '' ),
		);
	}

	/**
	 * Step 0: accented letters to their plain letter, so a name with an accent is still a name (otherwise every non-ASCII
	 * letter would become a space). One table for both sides; a customer search ignores accents anyway.
	 */
	const FOLD = 'àa áa âa ãa äa åa āa ąa ăa çc ćc čc ďd đd èe ée êe ëe ēe ęe ěe ėe ìi íi îi ïi īi įi ıi İi ľl łl ĺl ñn ńn ňn òo óo ôo õo öo øo ōo őo ŕr řr śs šs şs ťt ţt ùu úu ûu üu ūu ůu űu ųu ýy ÿy źz žz żz ÀA ÁA ÂA ÃA ÄA ÅA ĀA ĄA ĂA ÇC ĆC ČC ĎD ĐD ÈE ÉE ÊE ËE ĒE ĘE ĚE ĖE ÌI ÍI ÎI ÏI ĪI ĮI ĽL ŁL ĹL ÑN ŃN ŇN ÒO ÓO ÔO ÕO ÖO ØO ŌO ŐO ŔR ŘR ŚS ŠS ŞS ŤT ŢT ÙU ÚU ÛU ÜU ŪU ŮU ŰU ŲU ÝY ŸY ŹZ ŽZ ŻZ';

	/** The fold table as [from, to] pairs (each from one letter, to one plain letter). */
	public static function fold_pairs(): array {
		$out = array();
		foreach ( explode( ' ', self::FOLD ) as $pair ) {
			if ( preg_match( '/^(.+)([a-zA-Z])$/su', $pair, $m ) ) {
				$out[] = array( $m[1], $m[2] );
			}
		}
		return $out;
	}

	/**
	 * Step 2b: a report about the work is not a request to do it ("estimates sent this month", "jobs completed in
	 * October", "average days to close a job" go to the quick answer). ANALYTIC words are never an action. REPORT words (a
	 * total, a rate, a past period, the things done in the plural) are a report only when the ask does not open with a
	 * verb of doing (DOING): "change the total on the Example estimate", "send the surveys from last week", "mark the jobs
	 * done" still act.
	 */
	const ANALYTIC = '\b(?:how many|how much|average|averages|avg|number of|compare|compared|comparison|versus|ratio|revenue|trend|trends|breakdown|stats|statistics|ytd|year to date)\b';
	const REPORT   = '\b(?:total|totals|count|counts|vs|rate|rates|percent|percentage|per (?:day|week|month|year)|this (?:month|year|quarter)|last (?:week|month|year|quarter)|past (?:week|month|year|quarter|\d+ days)|so far|in (?:january|february|march|april|june|july|august|september|october|november|december|q[1-4])|since (?:january|february|march|april|may|june|july|august|september|october|november|december|last))\b|\b(?:estimates|quotes|bids|proposals|jobs|leads|receipts|surveys|reviews|orders|projects|installs|customers|messages|cuts|channels) (?:(?!get |got |be |been |to )\w+ )?(?:sent|resent|approved|accepted|completed|closed|contacted|created|started|ordered|installed|forwarded|assigned|generated|done|finished|signed|lost|won|edited|printed|recorded|placed)\b';
	const DOING    = '^(?:send|re-?send|email|e-mail|mail|shoot|fire off|forward|text|message|msg|dm|ping|tell|let|share|post|reply|change|update|fix|edit|revise|lower|raise|adjust|add|remove|make|create|start|begin|set up|mark|get|close|finish|complete|wrap up|order|reorder|print|assign|hand off|give|pass|generate|pull|file|upload|sort|approve|accept|record|log|note|call|reach out|contact|follow up|ask|request|cut|compute|plan|save|book|schedule|put|place|submit|redo|resubmit|reissue|price|quote|bid)\b';

	/** Step 2: a question asks about the work; it does not ask to do it. */
	const QUESTION = '^(?:how|what|which|when|who|whom|whose|why|where|did|does|do|have|has|is|are|was|were|should|count|list|tell me (?:how|what|which|when|who|if|whether)|show me (?:how|what|which|who)|give me (?:a |the )?(?:count|number|list|total)|any idea)\b|\bhow (?:many|much|often|long)\b';

	/** The context patterns: a name (group 1) and an estimate number (group 1). */
	public static function ctx(): array {
		$trade = ZDZ_Ask_Phrases::trade_alt();
		$T     = '' !== $trade ? '|' . $trade : '';
		$thing = '(?:job|estimate|quote|bid|proposal|invoice|project|house|place|install|installation|order|receipt|home|property|account|photos|pics|pictures|measurements|lead|survey|review|family|families|household|residence|folks' . $T . ')';
		return array(
			'name'   => array(
				'\bthe ([a-z][a-z\'.\-]*?)(?:\'s)? ' . $thing . '\b',
				'\b([a-z][a-z\'.\-]*?)\'s ' . $thing . '\b',
				'\b(?:for|to|on|at|with|from|over at|out at|over to) (?:the |mr |mrs |ms |miss |dr )?([a-z][a-z\'.\-]*(?: [a-z][a-z\'.\-]*)?)',
				'\b(?:called|reached|rang|phoned|texted|contacted|talked to|spoke to|spoke with|visited|got a ?hold of|got ahold of|connected with|left a (?:message|voicemail|vm) (?:for|with)) (?:back )?(?:mr |mrs |ms |miss |dr |the )?([a-z][a-z\'.\-]*)',
				'\blet ([a-z][a-z\'.\-]*) know\b',
				'\b(?:ping|dm|message|msg|text|tell|ask) (?:the )?([a-z][a-z\'.\-]*)\b',
			),
			'trail'  => ' (?:' . substr( $thing, 3, -1 ) . '|today|tomorrow|now|again|too|also|asap|please|this|that|real|right|about|and|so|because|since|before|after|at|on|in|by|for|with|the|a|an|vs|versus|or|plus)\b.*$',
			// Words that are never a customer's name: errands, places, people in general, the work itself, times, and the
			// trade's own words.
			'stop'   => '^(?:lunch|breakfast|dinner|coffee|gas|break|pick|pick up|picking|grab|grabbing|get|getting|go|going|from|to|for|with|about|up|back|home|store|depot|supplier|warehouse|bank|yard|follow up|follow|followup|follow-up|callback|call back|task|tasks|revised|updated|corrected|fixed|final|latest|current|original|signed|approved|whole|entire|big|small|front|back|side|second|third|another|any|some|more|lead|prospect|prospects|me|him|her|them|us|it|its|this|that|these|those|there|here|today|tomorrow|tonight|now|team|the team|everyone|everybody|someone|somebody|anyone|customer|customers|client|clients|homeowner|homeowners|crew|crews|chat|channel|office|shop|printer|my|our|their|his|your|a|an|the|job|jobs|estimate|estimates|quote|quotes|project|projects|house|place|install|lead|leads|order|orders|receipt|receipts|survey|surveys|review|reviews|message|messages|photo|photos|pics|work|way|my way|the way|site|address|morning|afternoon|evening|week|monday|tuesday|wednesday|thursday|friday|saturday|sunday|google|facebook|yelp|again|later|soon|time|sale|deal|him and her|all|both|each|every|next|last|first|new|old|same|other|one|two|done|ready|it all' . $T . ')$',
			'number' => '\b(?:estimate|quote|invoice|job|bid|number|no|num|#)\s*(?:number\s*|no\s*|num\s*)?#?\s*(\d{3,7})\b|#(\d{3,7})\b',
		);
	}


	/**
	 * Step 3: the rows, most specific first. Each row:
	 *   id      the action (the same key the app records when the work is done)
	 *   apps    app ids, tried in order; the first one this person can open wins
	 *   label   the row's words ("Send an estimate")
	 *   verbs   one of these ...
	 *   objects ... and one of these, anywhere in the ask (either order);
	 *   phrases or one of these whole phrases alone
	 *   not     vetoes the row
	 *   ctx     'name' | 'number' | 'both' | '' : what context to read
	 *   key     the launch option a name goes into ('customer' by default); a number goes into 'estimate_number'
	 *   open    fixed launch options
	 */
	public static function rows(): array {
		$w     = function ( $a ) { return self::w( (array) $a ); };
		$trade = ZDZ_Ask_Phrases::trade_alt();
		$T     = '' !== $trade ? '|' . $trade : '';

		$EST   = array( 'estimates?', 'quotes?', 'bids?', 'proposals?', 'pricing', 'price sheets?' );
		$JOB   = array( 'jobs?', 'installs?', 'installations?', 'work orders?', 'service calls?', 'repairs?', 'jobsite', 'job site' );
		$CHAT  = '(?:the )?(?:chat|channel|team|messages|thread|group|crew|office|guys)';
		$SEND  = array( 'send', 'sends', 'sending', 'sent', 'e-?mail', 'e-?mails', 'e-?mailing', 'e-?mailed', 'shoot', 'shoot over', 'fire off', 'fire over', 'get (?:it |that |this |them )?out', 'mail', 'deliver', 'forward', 'submit', 'push (?:out|over|through)', 'kick (?:out|over)', 'zap', 'text' );
		$APPROVE = array( 'approve', 'approves', 'approving', 'approved', 'okay', 'ok', 'o k', 'sign off(?: on)?', 'green ?light', 'bless', 'greenlight', 'give (?:the |a )?(?:go ahead|green light|thumbs up|ok|okay) (?:on|to|for)' );
		$EDIT  = array( 'edit', 'edits', 'editing', 'change', 'changes', 'changing', 'update', 'updates', 'updating', 'fix', 'fixing', 'correct', 'correcting', 'revise', 'revising', 'adjust', 'adjusting', 'modify', 'tweak', 'redo', 'rework', 'add (?:a |an |another )?(?:line|item|line item' . $T . ')', 'remove (?:a |the |that )?(?:line|item|line item)', 'take off', 'knock off', 'drop the price', 'lower the price', 'raise the price', 'discount', 'mark up' );
		$MAKE  = array( 'create', 'creating', 'make', 'making', 'write(?: up)?', 'writing(?: up)?', 'draw up', 'put together', 'start', 'starting', 'build', 'building', 'draft', 'drafting', 'new', 'prepare', 'work up', 'whip up', 'do', 'run', 'generate', 'set up', 'begin' );
		$DONE  = array( 'finish', 'finished', 'finishing', 'complete', 'completed', 'completing', 'wrap(?:ped)? up', 'wrapping up', 'done with', 'knock(?:ed)? out', 'mark (?:it |the \w+ )?(?:as )?(?:done|complete|completed|finished)', 'check off', 'checked off', 'tick off' );

		$rows = array(
			// ── Messages first: "share the estimate in the chat" is a message, not a send to the customer ──
			array(
				'id' => 'document_shared', 'apps' => array( 'internal-messaging' ), 'label' => 'Share an estimate or invoice in Messages',
				'phrases' => array(
					'\b(?:share|post|drop|put|send|throw|stick|paste) (?:\w+ ){0,4}(?:estimates?|invoices?|quotes?|bids?) (?:\w+ ){0,3}(?:in|to|with|into|on) ' . $CHAT . '\b',
					'\bshare (?:the |this |that |an? |my |our )?(?:\w+ )?(?:estimate|invoice|quote|bid)\b',
					'\b(?:let|show) (?:the )?(?:team|crew|office|guys) (?:see|look at|have) (?:the |this |that )?(?:estimate|invoice|quote|bid)\b',
				),
				'ctx' => 'name', 'key' => 'customer',
			),
			// ── Estimates ──
			array(
				'id' => 'estimate_resent', 'apps' => array( 'estimate-creator' ), 'label' => 'Re-send an existing estimate',
				'verbs' => array( 're-?send', 're-?sends', 're-?sending', 're-?sent', 're-?e-?mail', 're-?e-?mailed', 'send (?:it |that |this |them )?(?:again|back|one more time|over again)', 'e-?mail (?:it |that |this )?again', 'forward (?:it |that )?again', 'send (?:a |the |an )?(?:new|revised|updated|corrected|fixed|second|another|redone) (?:version|copy)', 'send (?:a |the |an )?(?:new|revised|updated|corrected|fixed|redone)', 're-?issue', 're-?submit', '(?:new|revised|updated|corrected|second) version', 'another copy' ),
				'objects' => $EST,
				'phrases' => array( '\b(?:estimate|quote|bid|proposal) (?:out )?(?:again|one more time)\b', '\bsend (?:\w+ )?(?:another|a new) copy\b', '\b(?:revised|updated|corrected|new) (?:estimate|quote|bid|proposal) (?:needs to |has to |should )?go(?:es)? out\b' ),
				'ctx' => 'both', 'key' => 'customer',
			),
			array(
				'id' => 'estimate_sent', 'apps' => array( 'estimate-creator' ), 'label' => 'Send an estimate',
				'verbs' => $SEND, 'objects' => $EST,
				'phrases' => array( '\b(?:estimate|quote|bid|proposal)s?\b(?: \w+){0,6} (?:needs to |has to |should |can |is ready to |ready to |to )?go(?:es)? out\b', '\bget (?:the |that |this |a |an |their |his |her )?(?:\w+ )?(?:estimate|quote|bid|proposal) (?:out|over|sent|to)\b', '\bsend (?:\w+ ){1,3}(?:a|an|the|their|his|her|our) (?:estimate|quote|bid|proposal)\b' ),
				'not' => '\b(?:receipt|survey|invoice to the team|in the chat)\b',
				'ctx' => 'both', 'key' => 'customer',
			),
			array(
				'id' => 'estimate_approved', 'apps' => array( 'estimate-creator' ), 'label' => 'Approve an estimate',
				'verbs' => $APPROVE, 'objects' => $EST,
				'phrases' => array( '\b(?:estimate|quote|bid|proposal) (?:is |looks |seems )?(?:good to go|ready to send|ready to go|ok to send|okay to send|good to send|approved)\b' ),
				'not' => '\b(?:customer|client|homeowner|they|he|she) (?:approved|okayed|signed)\b',
				'ctx' => 'both', 'key' => 'customer',
			),
			array(
				'id' => 'estimate_accepted', 'apps' => array( 'estimate-creator' ), 'label' => 'Record that the customer accepted',
				'verbs' => array( 'mark', 'marking', 'record', 'recording', 'log', 'logging', 'note', 'set', 'flag', 'put' ),
				'objects' => array( '(?:as )?accepted', '(?:as )?sold', '(?:as )?signed', '(?:as )?won', 'a (?:sale|win)', 'acceptance' ),
				'phrases' => array(
					'\b(?:accepted|signed off on|said yes to|went (?:ahead )?with|took|agreed to|signed) (?:the |our |my |his |her |their |that |this )?(?:\w+ )?(?:estimate|quote|bid|proposal|deal|contract)\b',
					'\b(?:i|we) (?:just )?(?:sold|closed|won|landed|booked) (?:the |a |that |this |another )?(?:\w+ )?(?:job|deal|sale|estimate|quote|one|contract)\b',
					'\b(?:closed|won|landed) the (?:deal|sale|job)\b', '\b(?:sale|deal) (?:is )?(?:closed|done|signed)\b',
					'\b(?:customer|client|homeowner|they|he|she|[a-z]+) (?:accepted|said yes|signed|went for it|is going ahead|are going ahead|wants to go ahead|want to go ahead)\b',
				),
				'not' => '\b(?:did not|didnt|not|declined|rejected|said no|passed)\b',
				'ctx' => 'both', 'key' => 'customer',
			),
			array(
				'id' => 'estimate_edited', 'apps' => array( 'estimate-creator' ), 'label' => 'Edit an estimate',
				'verbs' => $EDIT, 'objects' => $EST,
				'not' => '\b(?:message|text|msg|receipt)\b',
				'ctx' => 'both', 'key' => 'customer',
			),
			// ── Messages: an edit of a message ──
			array(
				'id' => 'message_edited', 'apps' => array( 'internal-messaging' ), 'label' => 'Edit a message',
				'verbs' => array( 'edit', 'change', 'fix', 'correct', 'update', 'reword', 'rewrite', 'redo' ),
				'objects' => array( 'messages?', 'msgs?', 'texts?', 'dms?', 'what i (?:said|wrote|sent|typed)', 'my (?:last )?(?:post|reply|comment)' ),
				'phrases' => array( '\b(?:typo|mistake|misspelling|error) in (?:my|the|that) (?:last )?(?:message|text|msg|post|dm)\b' ),
			),
			// ── Receipts ──
			array(
				'id' => 'receipt_sent', 'apps' => array( 'receipts' ), 'label' => 'Send a receipt to the customer',
				'verbs' => $SEND, 'objects' => array( 'receipts?', 'completion (?:report|certificate|letter)', 'proof of install(?:ation)?', 'install(?:ation)? (?:report|certificate)' ),
				'phrases' => array( '\breceipt (?:needs to |has to |should |can )?go(?:es)? out\b' ),
				'ctx' => 'name', 'key' => 'customer',
			),
			array(
				'id' => 'receipt_approved', 'apps' => array( 'receipts' ), 'label' => 'Approve a receipt',
				'verbs' => $APPROVE, 'objects' => array( 'receipts?', 'completion (?:report|certificate)', 'proof of install(?:ation)?' ),
				'phrases' => array( '\breceipt (?:is |looks )?(?:good to go|ready to send|ready to go|ok|okay|good)\b' ),
				'ctx' => 'name', 'key' => 'customer',
			),
			array(
				'id' => 'receipt_generated', 'apps' => array( 'receipts' ), 'label' => 'Make an install receipt',
				'verbs' => $MAKE, 'objects' => array( '(?:install(?:ation)? |final )?receipts?', 'completion (?:report|certificate|letter)', 'proof of install(?:ation)?', 'install(?:ation)? (?:report|certificate)' ),
				'ctx' => 'name', 'key' => 'customer',
			),
			array(
				'id' => 'photos_filed', 'apps' => array( 'receipts' ), 'label' => 'File photos to a house',
				'verbs' => array( 'file', 'files', 'filing', 'sort', 'sorting', 'organi[sz]e', 'organi[sz]ing', 'move', 'assign', 'match', 'attach', 'tag', 'group', 'put away', 'clean up' ),
				'objects' => array( 'photos?', 'pics?', 'pix', 'pictures?', 'images?', 'shots?', 'snapshots?' ),
				'phrases' => array( '\b(?:which|what) (?:house|address|home|job) (?:are |do |does )?(?:these|those|the) (?:photos|pics|pictures) (?:go|belong) (?:to|with)\b', '\bphotos to sort\b' ),
				'ctx' => 'name', 'key' => 'customer',
			),
			// ── Surveys (staff work only; a customer's answer is never here) ──
			array(
				'id' => 'survey_emails_sent', 'apps' => array( 'surveys' ), 'label' => 'Send survey emails',
				'verbs' => array_merge( $SEND, array( 'blast', 'blast out', 'ask for' ) ),
				'objects' => array( 'surveys?', 'survey (?:e-?mails?|links?|requests?|invites?|invitations?)', 'review (?:requests?|e-?mails?|asks?|links?|invites?)', 'feedback (?:requests?|e-?mails?|forms?)', 'satisfaction (?:surveys?|e-?mails?)' ),
				'phrases' => array( '\bask (?:\w+ ){0,3}for (?:a |an |their |some )?(?:reviews?|feedback|google reviews?|ratings?)\b', '\b(?:get|send) (?:the |our )?surveys? out\b', '\brequest (?:a |some )?reviews?\b' ),
				'ctx' => 'name', 'key' => 'customer',
			),
			array(
				'id' => 'survey_review_recorded', 'apps' => array( 'surveys' ), 'label' => 'Record a customer review',
				'verbs' => array( 'mark', 'record', 'log', 'note', 'enter', 'add', 'put in', 'save', 'count' ),
				'objects' => array( '(?:as )?reviewed', '(?:a |the |their |his |her )?(?:google |five star |5 star |online |yelp )?reviews?', 'ratings?', 'testimonials?' ),
				'phrases' => array( '\b(?:left|gave|posted|wrote|dropped) (?:us |me )?(?:a |an )?(?:\w+ )?(?:review|google review|5 star|five star|rating|testimonial)\b', '\bgot (?:a |an |another |the |their )?(?:\w+ )?(?:review|5 star|five star|rating)\b', '\b(?:5|five) stars? (?:from|on|for)\b' ),
				'not' => '\b(?:send|ask for|request|email)\b',
				'ctx' => 'name', 'key' => 'customer',
			),
			array(
				'id' => 'survey_note_forwarded', 'apps' => array( 'surveys' ), 'label' => 'Forward a survey note to a teammate',
				'verbs' => array( 'forward', 'forwards', 'pass(?: along| on)?', 'send', 'share', 'flag', 'hand off', 'kick over', 'escalate' ),
				'objects' => array( '(?:survey |customer )?(?:notes?|comments?|feedback|complaints?|responses?|answers?|concerns?)', 'what (?:the customer|they|he|she) said' ),
				'not' => '\b(?:lead|leads|job note|project)\b',
				'ctx' => 'name', 'key' => 'customer',
			),
			array(
				'id' => 'survey_task_completed', 'apps' => array( 'surveys' ), 'label' => 'Complete a follow-up task',
				'verbs' => array_merge( $DONE, array( 'close', 'closed', 'clear', 'cleared' ) ),
				'objects' => array( 'follow(?:-| )?ups?(?: tasks?| calls?)?', 'call ?backs?', 'survey tasks?', 'review tasks?' ),
				'phrases' => array( '\b(?:followed up|called (?:\w+ )?back) (?:with|on)\b' ),
				'ctx' => 'name', 'key' => 'customer',
			),
			array(
				'id' => 'survey_batch_created', 'apps' => array( 'surveys' ), 'label' => 'Create a survey batch',
				'verbs' => $MAKE, 'objects' => array( 'surveys?(?: batch(?:es)?| list| round)?', 'batch(?:es)? of surveys', 'satisfaction surveys?', 'csat(?: batch)?', 'review batch' ),
			),
			// ── Projects (the office's side of a job) ──
			array(
				'id' => 'material_ordered', 'apps' => array( 'jobs' ), 'label' => 'Mark a material order placed',
				'verbs' => array( 'mark', 'marking', 'record', 'log', 'note', 'set', 'flag' ),
				'objects' => array( '(?:as )?ordered', '(?:as )?placed', '(?:as )?sent in', '(?:as )?submitted', '(?:as )?called in' ),
				'phrases' => array(
					'\b(?:the )?(?:order|po|materials?|parts|hardware|supplies' . $T . ') (?:is |was |has been |have been |got |were |are )?(?:placed|ordered|sent in|submitted|called in|put in)\b',
					'\b(?:(?:i|we) (?:just |already )?)?(?:placed|submitted|called in|put in|sent in|phoned in) (?:the |an |our |that |this |a )?(?:\w+ )?(?:order|po)\b',
					'\b(?:i|we) (?:just |already )?ordered (?:the |it|them|those|that|this|all|everything|materials|parts' . $T . ')\b',
				),
				'not' => '\b(?:need|needs|have to|got to|should|start|create|new|draft|work orders?)\b',
				'ctx' => 'name', 'key' => 'customer',
			),
			array(
				'id' => 'order_started', 'apps' => array( 'jobs' ), 'label' => 'Start a material order',
				'verbs' => $MAKE, 'objects' => array( '(?:(?:material|materials|parts|supply|supplies|hardware|stock' . $T . ') )?orders?', 'purchase orders?', 'pos?', 'order forms?', 'materials? lists?', 'parts lists?' ),
				'phrases' => array( '\b(?:order|need to order|have to order|got to order|should order|needs ordering|need ordering) (?:the |some |more |a |an |new )?(?:materials?|parts|supplies|stuff|hardware|kits?' . $T . ')\b', '\b(?:materials?|parts|supplies|hardware' . $T . ') (?:need|needs) (?:to be )?order(?:ed|ing)\b' ),
				'not' => '\b(?:work orders?|service calls?)\b',
				'ctx' => 'name', 'key' => 'customer',
			),
			array(
				'id' => 'project_marked_installed', 'apps' => array( 'jobs' ), 'label' => 'Mark a project installed',
				'verbs' => array( 'mark', 'marking', 'set', 'log', 'record', 'flag', 'note', 'show' ),
				'objects' => array( '(?:as )?installed', '(?:as )?install(?:ation)? (?:done|complete|completed)' ),
				'phrases' => array( '\bproject (?:is |was |got |has been )?installed\b', '\binstalled (?:the )?\w+ (?:project|job)\b', '\b(?:the )?\w+ (?:project|job) (?:is |was |got |has been )installed\b' ),
				'not' => '\b(?:not|undo|unmark|un-?install)\b',
				'ctx' => 'name', 'key' => 'customer',
			),
			array(
				'id' => 'project_closed_out', 'apps' => array( 'jobs' ), 'label' => 'Close out a project',
				'verbs' => array( 'close', 'closes', 'closing', 'closed', 'close out', 'finali[sz]e', 'wrap up', 'archive', 'complete', 'finish', 'shut down', 'put to bed' ),
				'objects' => array( 'projects?' ),
				'not' => '\b(?:reopen|re-open|open back up|un-?close)\b',
				'ctx' => 'name', 'key' => 'customer',
			),
			array(
				'id' => 'project_note_added', 'apps' => array( 'jobs' ), 'label' => "Write in a project's conversation",
				'verbs' => array( 'add', 'leave', 'write', 'put', 'post', 'jot(?: down)?', 'make', 'log', 'note', 'drop', 'say', 'update', 'comment', 'message' ),
				'objects' => array( 'project (?:notes?|conversation|thread|chat|comments?|updates?)', 'notes? (?:on|to|in|for) (?:the )?(?:\w+ )?project', '(?:comment|update|message) (?:on|to|in) (?:the )?(?:\w+ )?project' ),
				'ctx' => 'name', 'key' => 'customer',
			),
			// ── Prep (the shop) ──
			array(
				'id' => 'cut_completed', 'apps' => array( 'prep' ), 'label' => 'Mark a cut complete',
				'verbs' => array_merge( $DONE, array( 'mark' ) ),
				'objects' => array( 'cuts?', 'cutting', 'cut list', '(?:panels?|pieces?' . $T . ') (?:are |is )?cut' ),
				'phrases' => array( '\b(?:done|finished|through) cutting\b', '\b(?:cuts?|cutting|panels?|pieces?' . $T . ') (?:is |are |was |were |got )?(?:all )?(?:done|cut|finished|complete|completed)\b', '\bcut (?:is|are) (?:done|complete|finished)\b', '\b(?:i|we) (?:just )?cut (?:the |all |them|those|everything|it)\b' ),
				'not' => '\b(?:need|needs|have to|got to|should|plan|compute|print)\b',
				'ctx' => 'both', 'key' => 'customer',
			),
			array(
				'id' => 'cut_sheets_printed', 'apps' => array( 'prep' ), 'label' => 'Print cut sheets',
				'verbs' => array( 'print', 'prints', 'printing', 'printed', 'print out', 'run off', 'send to (?:the )?printer', 'make (?:a )?cop(?:y|ies) of' ),
				'objects' => array( 'cut ?sheets?', 'cut lists?', 'cutting sheets?', 'sheets?', 'tickets?', 'labels?', 'work sheets?', 'shop sheets?', 'prep sheets?' ),
				'ctx' => 'both', 'key' => 'customer',
			),
			array(
				'id' => 'section_saved', 'apps' => array( 'prep' ), 'label' => 'Save field measurements',
				'verbs' => array( 'save', 'saving', 'enter', 'entering', 'log', 'record', 'put in', 'add', 'type in', 'input', 'jot down', 'write down', 'take', 'took', 'punch in', 'plug in', 'key in' ),
				'objects' => array( 'measurements?', 'measures', 'sizes', 'dimensions', 'field measure(?:ment)?s?', 'sections?', 'panels?', 'openings?' ),
				'phrases' => array( '\b(?:(?:i|we) )?(?:just )?measured (?:the |a |an |their |his |her )?(?:\w+ )?(?:house|job|opening|openings|section|sections' . $T . ')\b', '\bmeasure(?:ments)? (?:are |is )?(?:done|in|taken)\b' ),
				'ctx' => 'both', 'key' => 'customer',
			),
			array(
				'id' => 'cut_plan_computed', 'apps' => array( 'prep' ), 'label' => 'Plan the cuts',
				'verbs' => array( 'plan', 'planning', 'compute', 'computing', 'calculate', 'calculating', 'figure out', 'work out', 'run', 'lay out', 'optimi[sz]e', 'nest', 'do', 'make' ),
				'objects' => array( 'cuts', 'cut plans?', 'cut list', 'cutting plans?', 'nesting', 'layouts?', 'yield', 'material (?:usage|needed|layout)' ),
				'phrases' => array( '\bplan (?:the |out )?(?:cut|cuts|cutting)\b', '\bhow to cut\b' ),
				'ctx' => 'both', 'key' => 'customer',
			),
			// ── Jobs (the crew's side) ──
			array(
				'id' => 'job_closed', 'apps' => array( 'jobs' ), 'label' => 'Close out a job',
				'verbs' => array( 'close', 'closes', 'closing', 'closed', 'close out', 'finali[sz]e', 'archive', 'sign off(?: on)?', 'shut down', 'put to bed' ),
				'objects' => $JOB,
				'phrases' => array( '\bclose (?:it|this|that) out\b' ),
				'not' => '\b(?:project|reopen|re-open|deal|sale)\b',
				'ctx' => 'name', 'key' => 'customer',
			),
			array(
				'id' => 'job_completed', 'apps' => array( 'jobs' ), 'label' => 'Finish a job (with photos)',
				'verbs' => $DONE, 'objects' => $JOB,
				'phrases' => array(
					'\b(?:job|install|installation|work|repair|service call) (?:is |was |are |has been )?(?:all )?(?:done|complete|completed|finished|wrapped up)\b',
					'\b(?:i|we) (?:am |are )?(?:all )?(?:done|finished|wrapped up|wrapping up|packing up|packed up) (?:here|over here|at|with|on|for the day)\b',
					'\b(?:upload|add|send|take) (?:the )?(?:completion|finish(?:ed)?|after|final|done) (?:photos|pics|pictures|shots)\b',
					'\ball (?:done|finished|wrapped up) (?:here|at|with|on)\b',
				),
				'not' => '\b(?:cut|cutting|project|estimate|quote|survey|follow ?up|task|message)\b|^(?:text|ping|dm|message|msg|tell|let)\b',
				'ctx' => 'name', 'key' => 'customer',
			),
			array(
				'id' => 'job_started', 'apps' => array( 'jobs' ), 'label' => 'Start a job (On my way)',
				'verbs' => array( 'start', 'starts', 'starting', 'started', 'begin', 'beginning', 'kick off', 'kicking off', 'get going on', 'get started (?:on|with)', 'getting started (?:on|with)', 'head(?:ing)? (?:out )?to', 'go(?:ing)? to', 'leave for', 'leaving for', 'drive to', 'driving to', 'roll(?:ing)? (?:out )?to' ),
				'objects' => $JOB,
				'phrases' => array(
					'\b(?:on my way|on the way|on our way|omw|en ?route|heading (?:out|over|there|that way|your way)|headed (?:out|over|there|that way)|leaving (?:now|the shop|the yard|for)|rolling (?:out|over)|driving (?:over|out)|pulling out|out the door|be there (?:in|soon|shortly|at)|almost there|(?:about|nearly) to (?:arrive|get there)|arriving (?:now|soon|in)|on site|onsite|here at the|just (?:got|arrived|pulled up) (?:here|to|at|on)|(?:pulled up|arrived|got here|got there|made it) (?:at|to|here|there|on site))\b',
				),
				'not' => '\b(?:late|running behind|estimate|quote|sketch|survey|cut|cutting|order|project|lunch|breakfast|dinner|coffee|gas|break|home|the store|bank|depot|supplier|warehouse|pick up|picking up|grab|grabbing|call me|text me|let me know|tell me|when (?:you|they|he|she|the crew|we))\b|^(?:text|ping|dm|message|msg|tell|let|email)\b',
				'ctx' => 'name', 'key' => 'customer',
			),
			array(
				'id' => 'job_note_added', 'apps' => array( 'jobs' ), 'label' => 'Add a job note',
				'verbs' => array( 'add', 'leave', 'write', 'put', 'post', 'jot(?: down)?', 'make', 'log', 'drop', 'note' ),
				'objects' => array( '(?:job |install |crew )?(?:notes?|comments?) (?:on|to|in|for|about) (?:the |this |that )?(?:\w+ )?(?:job|install|work order|service call)', 'job notes?', 'install notes?', 'crew notes?' ),
				'ctx' => 'name', 'key' => 'customer',
			),
			// ── Leads ──
			array(
				'id' => 'lead_forwarded', 'apps' => array( 'leads' ), 'label' => 'Forward a lead',
				'verbs' => array( 'forward', 'forwards', 'forwarding', 'pass(?: along| on| over)?', 'send', 'sending', 'hand off', 'hand over', 'share', 'refer', 'give', 'kick over', 'flip', 'toss', 'route' ),
				'objects' => array( 'leads?', 'prospects?' ),
				'not' => '\b(?:generate|new leads|more leads|pull|assign)\b|\b(?:give|send|get|pull|find|show) (?:me|us)\b|\bzip\b|\b\d{5}\b',
				'ctx' => 'name', 'key' => 'customer', 'open' => array( 'view' => 'my-leads' ),
			),
			array(
				'id' => 'forward_completed', 'apps' => array( 'leads' ), 'label' => 'Complete a forwarded lead task',
				'verbs' => array_merge( $DONE, array( 'close', 'clear' ) ),
				'objects' => array( 'forwarded (?:leads?|tasks?|notes?)', 'lead tasks?', 'lead follow(?:-| )?ups?', 'forwards?' ),
				'open' => array( 'view' => 'my-leads' ),
			),
			array(
				'id' => 'leads_assigned', 'apps' => array( 'leads' ), 'label' => 'Assign leads',
				'verbs' => array( 'assign', 'assigning', 'give out', 'hand out', 'distribute', 'split(?: up)?', 'divvy(?: up)?', 'allocate', 'dole out', 'spread' ),
				'objects' => array( 'leads?', 'lead lists?', 'prospects?' ),
				'ctx' => 'name', 'key' => 'assignee',
			),
			array(
				'id' => 'lead_contacted', 'apps' => array( 'leads' ), 'label' => 'Mark a lead contacted',
				'verbs' => array( 'mark', 'marking', 'log', 'record', 'note', 'set', 'flag' ),
				'objects' => array( '(?:as )?contacted', '(?:as )?called', '(?:as )?reached', 'as spoke(?:n)? (?:to|with)', '(?:as )?talked to', 'a call', 'the call' ),
				'phrases' => array(
					'\b(?:i|we) (?:just |already )?(?:called|reached|rang|phoned|texted|contacted|talked to|spoke (?:to|with)|got (?:a ?hold|ahold) of|connected with|knocked on|visited|left (?:a )?(?:message|voicemail|vm)(?: for| with)?)\b',
					'\b(?:called|reached|phoned|contacted|talked to|spoke (?:to|with)|got (?:a ?hold|ahold) of|left (?:a )?(?:message|voicemail|vm) (?:for|with)) (?:the )?(?:lead|prospect|[a-z]+)\b',
					'\bno answer (?:at|from|on)\b',
				),
				'not' => '\b(?:estimate|quote|survey|review|follow ?up|called in|po|purchase order|order|orders)\b',
				'ctx' => 'name', 'key' => 'customer', 'open' => array( 'view' => 'my-leads' ),
			),
			array(
				'id' => 'leads_generated', 'apps' => array( 'leads' ), 'label' => 'Generate leads',
				'verbs' => array( 'generate', 'generating', 'pull', 'pulling', 'get', 'find', 'make', 'build', 'run', 'create', 'give me', 'load', 'fetch', 'grab', 'dig up', 'come up with', 'spin up', 'need' ),
				'objects' => array( '(?:new |more |fresh |some )?leads?', 'lead (?:lists?|batch(?:es)?)', 'prospects?', 'people to call', 'call lists?', 'door ?knock(?:ing)? lists?', 'doors to knock' ),
				'phrases' => array( '\b(?:new|more|fresh) leads\b', '\bwho (?:should|can|do) i (?:call|knock|visit|hit) (?:next|today|tomorrow)?\b' ),
			),
			// ── Messages ──
			array(
				'id' => 'channel_created', 'apps' => array( 'internal-messaging' ), 'label' => 'Create a channel',
				'verbs' => array( 'create', 'make', 'start', 'set up', 'new', 'add', 'spin up' ),
				'objects' => array( 'channels?', 'group (?:chats?|messages?|threads?)', 'chat ?rooms?', 'team chats?', 'group' ),
			),
			// ── The general verbs come last ──
			array(
				'id' => 'job_handed_off', 'apps' => array( 'jobs' ), 'label' => 'Hand off a job',
				'verbs' => array( 'hand off', 'handoff', 'hand over', 'assign', 'give', 'pass', 'send', 'dispatch', 'create', 'make', 'new', 'put in', 'send out', 'send over', 'set up', 'line up', 'book a crew for' ),
				'objects' => $JOB,
				'phrases' => array( '\b(?:hand|pass|give|send|kick) (?:it|this|that|the job|this job|that job|the install) (?:off |over )?to\b', '\b(?:needs?|get) (?:a |an )?(?:crew|installer|tech|technician) (?:on|for|out to|out at)\b', '\b(?:new|create a|make a) (?:job|work order|install job|service call)\b' ),
				'not' => '\b(?:estimate|quote|start|started|on my way|close|closed|finish|finished|done)\b',
				'ctx' => 'name', 'key' => 'customer',
			),
			array(
				'id' => 'estimate_created', 'apps' => array( 'estimate-creator' ), 'label' => 'Create an estimate',
				'verbs' => $MAKE, 'objects' => $EST,
				'phrases' => array( '\b(?:new|fresh|another) (?:estimate|quote|bid|proposal)\b', '\b(?:need|needs|want|wants) (?:a |an )?(?:new )?(?:estimate|quote|bid|price|proposal)\b', '\b(?:price|bid|quote) (?:out |up )?(?:a |the |their |his |her )?(?:\w+ )?(?:job|house|place' . $T . ')\b' ),
				'ctx' => 'both', 'key' => 'customer',
			),
			array(
				'id' => 'message_sent', 'apps' => array( 'internal-messaging' ), 'label' => 'Send a message',
				'verbs' => array( 'send', 'shoot', 'write', 'post', 'drop', 'fire off' ),
				'objects' => array( 'messages?', 'msgs?', 'dms?', 'notes? to (?:the )?(?:team|crew|office|guys|\w+)', 'texts?', 'the (?:team|crew|office|group|guys|channel|chat)', '(?:in|to|on) (?:the )?(?:chat|channel|team chat|group)' ),
				'phrases' => array( '\blet (?:\w+ ){1,3}know\b', '^(?:ping|dm|message|msg|text) (?!me\b|us\b|from\b|back\b)\w+', '\btell (?!me\b|us\b|you\b)[a-z]+ \w+', '\b(?:reach out|reply|respond) to (?:\w+)\b', '\b(?:hit|holler at) (?:\w+ )up\b' ),
				'not' => '\b(?:estimate|quote|bid|receipt|survey|review|lead)\b',
				'ctx' => 'name', 'key' => 'to',
			),
		);
		$rows = apply_filters( 'zdz_ask_actions', $rows );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			if ( ! is_array( $r ) || empty( $r['id'] ) || empty( $r['apps'] ) || empty( $r['label'] ) ) {
				continue;
			}
			$row = array(
				'id'      => preg_replace( '/[^a-z0-9_]/', '', (string) $r['id'] ),
				'apps'    => array_values( array_map( 'strval', (array) $r['apps'] ) ),
				'label'   => (string) $r['label'],
				'verbs'   => empty( $r['verbs'] ) ? '' : $w( $r['verbs'] ),
				'objects' => empty( $r['objects'] ) ? '' : $w( $r['objects'] ),
				'phrases' => array_values( array_map( 'strval', (array) ( $r['phrases'] ?? array() ) ) ),
				'not'     => (string) ( $r['not'] ?? '' ),
				'ctx'     => in_array( $r['ctx'] ?? '', array( 'name', 'number', 'both' ), true ) ? (string) $r['ctx'] : '',
				'key'     => preg_replace( '/[^a-z_]/', '', (string) ( $r['key'] ?? 'customer' ) ),
				'open'    => is_array( $r['open'] ?? null ) ? $r['open'] : array(),
			);
			$all = array_merge( array( $row['verbs'], $row['objects'], $row['not'] ), $row['phrases'] );
			$bad = false;
			foreach ( $all as $p ) {
				if ( '' !== $p && ! self::valid( $p ) ) { $bad = true; break; }
			}
			if ( $bad || ( '' === $row['verbs'] xor '' === $row['objects'] ) || ( '' === $row['verbs'] && ! $row['phrases'] ) ) {
				continue;   // a row that cannot run on both sides is dropped, not fatal
			}
			$out[] = $row;
		}
		return $out;
	}

	/** Everything the page needs (zdzData.askPhrases.actions). */
	public static function for_js(): array {
		return array(
			'v'         => self::V,
			'normalize' => self::normalize_rules(),
			'question'  => self::QUESTION,
			'analytic'  => self::ANALYTIC,
			'report'    => self::REPORT,
			'doing'     => self::DOING,
			'fold'      => self::fold_pairs(),
			'ctx'       => self::ctx(),
			'rows'      => self::rows(),
		);
	}

	/* ── The interpreter: the PHP twin of app.js zdzAskAction (one corpus, both sides, in the test) ──────────── */

	private static function re( string $p ): string {
		return '/' . str_replace( '/', '\/', str_replace( '\/', '/', $p ) ) . '/i';
	}

	private static function valid( string $p ): bool {
		if ( preg_match( '/\(\?<[=!]|\(\?[a-zA-Z]|\(\?>|\(\?P|\(\?<\w|[+*?}]\+|\\\\[AzZGQEKRhpPX]/', $p ) ) {
			return false;   // lookbehind, inline flags, atomic or named groups, possessive quantifiers, PCRE-only escapes (\p is a letter class in PHP and a plain "p" in the page)
		}
		return false !== @preg_match( self::re( $p ), '' );   // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/** Step 1. */
	public static function normalize( string $q ): string {
		// Curly quotes and a backtick are a plain apostrophe (done here, not in a rule: the two regex dialects spell them
		// differently); then lower case.
		$s = str_replace( array( "\u{2018}", "\u{2019}", '`' ), "'", (string) $q );
		$s = strtr( $s, array_column( self::fold_pairs(), 1, 0 ) );
		// Then: A-Z only to lower case (the page's toLowerCase would also change a few non-ASCII letters into ASCII ones),
		// any other run of non-ASCII to one space, spaces to one, and at most 300 characters (a 40 KB paste is not an
		// ask; it also kept the long rules inside PCRE's limits, which the page has not got).
		$s = strtolower( $s );
		$s = (string) preg_replace( '/[^\x00-\x7f]+/', ' ', $s );
		$s = substr( trim( (string) preg_replace( '/\s+/', ' ', $s ) ), 0, 300 );
		$rules = self::normalize_rules();
		for ( $pass = 0; $pass < 2; $pass++ ) {
			foreach ( $rules as $r ) {
				$s = (string) preg_replace( self::re( $r[0] ), $r[1], $s );
			}
		}
		return trim( $s );
	}

	/** A name in the ask, or ''. */
	public static function name_in( string $s, bool $person = false ): string {
		$c = self::ctx();
		// A message goes to a person: "tell Jordan the Example job moved" names Jordan before the Example job.
		$pats = $person ? array_merge( array_slice( $c['name'], 4 ), array_slice( $c['name'], 0, 4 ) ) : $c['name'];
		foreach ( $pats as $p ) {
			// Every place the pattern fits, left to right: "done with the follow up for Alex" passes over "follow up".
			if ( ! preg_match_all( self::re( $p ), $s, $all, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $all as $m ) {
				$n = trim( (string) preg_replace( self::re( $c['trail'] ), '', ' ' . trim( (string) ( $m[1] ?? '' ) ) ) );
				$n = trim( (string) preg_replace( '/\'s$/', '', $n ) );
				if ( '' === $n || strlen( $n ) < 2 || preg_match( self::re( $c['stop'] ), $n ) || preg_match( self::re( $c['stop'] ), explode( ' ', $n )[0] ) || preg_match( '/\d/', $n ) ) {
					continue;
				}
				return ucwords( $n );
			}
		}
		return '';
	}

	/** An estimate number in the ask, or ''. */
	public static function number_in( string $s ): string {
		if ( preg_match( self::re( self::ctx()['number'] ), $s, $m ) ) {
			return (string) ( '' !== ( $m[1] ?? '' ) ? $m[1] : ( $m[2] ?? '' ) );
		}
		return '';
	}

	/**
	 * The action an ask asks to do, or null: { id, app, label, name, number, options }. $apps: the app ids this person
	 * can open (null = any).
	 */
	public static function match( string $q, ?array $apps = null ): ?array {
		$s = self::normalize( $q );
		if ( '' === $s || strlen( $s ) > 240 || preg_match( self::re( self::QUESTION ), $s ) || preg_match( self::re( self::ANALYTIC ), $s ) || ( preg_match( self::re( self::REPORT ), $s ) && ! preg_match( self::re( self::DOING ), $s ) ) || preg_match( '/\?\s*$/', $q ) ) {
			return null;
		}
		foreach ( self::rows() as $r ) {
			$hit = false;
			foreach ( $r['phrases'] as $p ) {
				if ( preg_match( self::re( $p ), $s ) ) { $hit = true; break; }
			}
			if ( ! $hit && '' !== $r['verbs'] && preg_match( self::re( $r['verbs'] ), $s ) && preg_match( self::re( $r['objects'] ), $s ) ) {
				$hit = true;
			}
			if ( ! $hit || ( '' !== $r['not'] && preg_match( self::re( $r['not'] ), $s ) ) ) {
				continue;
			}
			$app = '';
			foreach ( $r['apps'] as $a ) {
				if ( null === $apps || in_array( $a, $apps, true ) ) { $app = $a; break; }
			}
			if ( '' === $app ) {
				continue;   // an app this person cannot open: the next row may still fit
			}
			$name   = in_array( $r['ctx'], array( 'name', 'both' ), true ) ? self::name_in( $s, 'to' === ( $r['key'] ?? '' ) ) : '';
			$number = in_array( $r['ctx'], array( 'number', 'both' ), true ) ? self::number_in( $s ) : '';
			$opts   = array( 'source' => 'ask', 'action' => $r['id'] ) + $r['open'];
			if ( '' !== $name ) { $opts[ $r['key'] ] = $name; }
			if ( '' !== $number ) { $opts['estimate_number'] = $number; }
			return array( 'id' => $r['id'], 'app' => $app, 'label' => $r['label'], 'name' => $name, 'number' => $number, 'options' => $opts );
		}
		return null;
	}
}
