<?php
/**
 * ZDZ_Ask_Phrases — the ask bar's phrase rules, kept in ONE place.
 *
 * The ask bar on the home screen reads what a person types (or says) and offers a row: open an app, show a
 * commission card, look up stock. The rules for that live here as DATA:
 *
 *   - launch rows: which words open which app, whether a launch verb is required, what context to carry
 *     (a "for <name>" or a period) and under which launch option key;
 *   - the commission rule: an ordered list of subject patterns, the name clean-up, the self / company-wide / period /
 *     focus patterns;
 *   - the stock rule: the frames that make a question about one item's stock.
 *
 * The page receives them as zdzData.askPhrases; the page and the server each run the SAME rules through a small
 * interpreter (match_commission() / match_stock() here), so the two sides cannot drift apart.
 *
 * Patterns are written in the subset PCRE and JavaScript share: no lookbehind, no possessive quantifiers, no named
 * groups, no \A or \z. They carry no delimiters. Each is applied case-insensitively. Apps may add launch rows with the
 * filter `zdz_ask_launch_rows`.
 *
 * The words of the business's own trade (the things it makes, fits or sells: "gate", "awning", "valve") are not
 * Core's to know. trade_words() reads them from the option `zdz_ask_trade_words` (a catalog pack writes it) and the
 * filter of the same name; Core ships none. They are used where a rule must tell a thing from a person.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Ask_Phrases {

	/** Bump when the shape sent to the page changes. */
	const V = 1;

	/** The option a catalog pack writes: the trade's own words for things (a list of lower-case words or phrases). */
	const TRADE_OPT = 'zdz_ask_trade_words';

	/** A launch verb anywhere in the ask. */
	const LAUNCH_VERB = '\b(open|launch|start|go to|goto|bring up|pull up|show|show me|take me to|jump to|fire up|new|create|add|begin|check|view|play)\b';

	/** Single-word verbs that a one-letter slip still counts as ("opne", "luanch"). */
	const FUZZY_VERBS = array( 'open', 'launch', 'start', 'show', 'create', 'begin', 'check', 'view' );

	/** Aggregate commission questions never launch the Commission app; they go to chat. */
	const COMM_AGGREGATE = '\b(by (?:salesperson|rep|person|product|type|month)|report|the (?:whole )?team|everyone|company-?wide|did we pay|do we pay|we paid|our (?:total |average )?commission|average commission|total commissions?|how do commissions?|how does commission|commission rates?|commission structure)\b';

	/**
	 * The trade's words for things, each a lower-case word or short phrase of letters, digits, spaces, hyphens and
	 * apostrophes (anything else is dropped, so a word can never change a pattern). Core: none.
	 *
	 * @return string[]
	 */
	public static function trade_words(): array {
		$v = function_exists( 'get_option' ) ? get_option( self::TRADE_OPT, array() ) : array();
		$v = is_array( $v ) ? $v : array();
		if ( function_exists( 'apply_filters' ) ) {
			$f = apply_filters( self::TRADE_OPT, $v );
			$v = is_array( $f ) ? $f : $v;
		}
		$out = array();
		foreach ( $v as $w ) {
			$w = strtolower( trim( preg_replace( '/\s+/', ' ', (string) $w ) ) );
			if ( preg_match( '/^[a-z][a-z0-9 \'\-]{0,30}$/', $w ) ) {
				$out[ $w ] = $w;
			}
		}
		return array_values( $out );
	}

	/**
	 * The trade's words as pattern alternatives ("gate(?:s|es)?|awning(?:s|es)?"), '' when there are none. A hyphen is
	 * escaped; nothing else in a word is special.
	 */
	public static function trade_alt(): string {
		$alts = array();
		foreach ( self::trade_words() as $w ) {
			$p = str_replace( '-', '\-', $w );
			$alts[] = preg_match( '/s$/', $w ) ? $p : $p . '(?:s|es)?';
		}
		return implode( '|', $alts );
	}

	/** `a|b|c` joined with the trade's words, if any. */
	public static function with_trade( string $alts ): string {
		$t = self::trade_alt();
		return '' === $t ? $alts : $alts . '|' . $t;
	}

	/**
	 * The launch table. Each row:
	 *   ids          app ids, tried in order; the first one the person can see wins
	 *   re           the app's words (tested on the lower-cased ask)
	 *   verb         the row label's verb
	 *   require_verb true: launches only with a launch verb; false: also when the ask starts with the app word
	 *   ctx          '' | 'for' (a "for <name>") | 'period' (a date or period word)
	 *   key          the launch option the context goes into
	 *   icon, cc     fallbacks; the app's own icon and colour win
	 */
	public static function launch_rows(): array {
		$rows = array(
			array( 'ids' => array( 'scheduler' ), 're' => '\b(schedule|scheduler|calendar|appointments?|availability)\b', 'verb' => 'Open', 'require_verb' => false, 'ctx' => 'period', 'key' => 'focus', 'icon' => 'calendar', 'cc' => '#0EA5E9' ),
			array( 'ids' => array( 'estimate-creator' ), 're' => '\b(estimate|estimates|quote|quotes|bid|bids|proposal)\b', 'verb' => 'New', 'require_verb' => true, 'ctx' => 'for', 'key' => 'customer', 'icon' => 'file-text', 'cc' => '#F59E0B' ),
			array( 'ids' => array( 'zdz-sketch-pad' ), 're' => '\b(sketch|sketchpad|draw(?:ing)?|doodle|diagram)\b', 'verb' => 'Start', 'require_verb' => false, 'ctx' => '', 'key' => '', 'icon' => 'pencil', 'cc' => '#F59E0B' ),
			array( 'ids' => array( 'stock' ), 're' => '\b(stock|inventory|in stock|on hand)\b', 'verb' => 'Check', 'require_verb' => true, 'ctx' => 'for', 'key' => 'query', 'icon' => 'package', 'cc' => '#3B82F6' ),
			array( 'ids' => array( 'commission-calculator' ), 're' => '\b(commission|commissions|payroll|installer pay|sales ledger|my pay)\b', 'verb' => 'Open', 'require_verb' => false, 'ctx' => 'period', 'key' => 'period', 'icon' => 'calculator', 'cc' => '#10B981' ),
			array( 'ids' => array( 'leads' ), 're' => '\b(leads?|lead generator|prospects?)\b', 'verb' => 'Open', 'require_verb' => true, 'ctx' => '', 'key' => '', 'icon' => 'target', 'cc' => '#22C55E' ),
			array( 'ids' => array( 'surveys' ), 're' => '\b(surveys?|satisfaction|csat)\b', 'verb' => 'Open', 'require_verb' => true, 'ctx' => '', 'key' => '', 'icon' => 'clipboard-check', 'cc' => '#3B82F6' ),
			array( 'ids' => array( 'prep' ), 're' => '\b(prep|prep queue|shop queue|work queue|fabricat\w*)\b', 'verb' => 'Open', 'require_verb' => false, 'ctx' => '', 'key' => '', 'icon' => 'scissors', 'cc' => '#F59E0B' ),
			array( 'ids' => array( 'receipts' ), 're' => '\b(receipts?)\b', 'verb' => 'Open', 'require_verb' => true, 'ctx' => '', 'key' => '', 'icon' => 'receipt', 'cc' => '#0EA5E9' ),
			array( 'ids' => array( 'knowledge-vault' ), 're' => '\b(knowledge|knowledge vault|knowledge base)\b', 'verb' => 'Open', 'require_verb' => false, 'ctx' => '', 'key' => '', 'icon' => 'book-open', 'cc' => '#22C55E' ),
			array( 'ids' => array( 'internal-messaging' ), 're' => '\b(messages?|messaging|team chat|inbox)\b', 'verb' => 'Open', 'require_verb' => true, 'ctx' => '', 'key' => '', 'icon' => 'message-square', 'cc' => '#3B82F6' ),
			array( 'ids' => array( 'zdz-media', 'zdz-media-all' ), 're' => '\b(media|photos? library|gallery|image library)\b', 'verb' => 'Open', 'require_verb' => false, 'ctx' => '', 'key' => '', 'icon' => 'image', 'cc' => '#64748B' ),
			array( 'ids' => array( 'game' ), 're' => '\b(game|play a game)\b', 'verb' => 'Open', 'require_verb' => false, 'ctx' => '', 'key' => '', 'icon' => 'gamepad-2', 'cc' => '#84CC16' ),
		);
		if ( function_exists( 'apply_filters' ) ) {
			$rows = apply_filters( 'zdz_ask_launch_rows', $rows );
		}
		$out = array();
		foreach ( (array) $rows as $r ) {
			if ( ! is_array( $r ) || empty( $r['ids'] ) || empty( $r['re'] ) || ! self::valid( (string) $r['re'] ) ) {
				continue;   // a row an app got wrong is dropped, not fatal
			}
			$out[] = array(
				'ids'          => array_values( array_map( 'strval', (array) $r['ids'] ) ),
				're'           => (string) $r['re'],
				'verb'         => (string) ( $r['verb'] ?? 'Open' ),
				'require_verb' => ! empty( $r['require_verb'] ),
				'ctx'          => in_array( $r['ctx'] ?? '', array( 'for', 'period' ), true ) ? (string) $r['ctx'] : '',
				'key'          => preg_replace( '/[^A-Za-z_]/', '', (string) ( $r['key'] ?? '' ) ),
				'icon'         => (string) ( $r['icon'] ?? 'grid' ),
				'cc'           => preg_match( '/^#[0-9A-Fa-f]{6}$/', (string) ( $r['cc'] ?? '' ) ) ? (string) $r['cc'] : '#64748B',
			);
		}
		return $out;
	}

	/** Context patterns for launch rows. */
	public static function launch_ctx(): array {
		return array(
			'for'      => '\bfor\s+([A-Za-z][\w\'’.\-]*(?:\s+[A-Za-z][\w\'’.\-]*){0,3})',
			'for_trim' => '\s+\b(job|estimate|quote|account|today|tomorrow|this week|please)\b.*$',
			'for_lead' => '^(?:the|a|an|my|our|their|his|her)\s+',
			'period'   => '\b(today|tomorrow|yesterday|this (?:week|month|quarter|year)|last (?:week|month|quarter|year)|next (?:week|month)|month to date|year to date|mtd|ytd|q[1-4]|january|february|march|april|may|june|july|august|september|october|november|december|mon(?:day)?|tue(?:sday)?|wed(?:nesday)?|thu(?:rsday)?|fri(?:day)?|sat(?:urday)?|sun(?:day)?)\b',
		);
	}

	/**
	 * The commission rule. A NAMED person's commission (or "my commission") is a bounded answer and shows as a card;
	 * aggregates go to chat. `subject` patterns are tried in order; group `g` of the first that matches, cleaned by
	 * `take`, is the person (a pattern with `multi` also names the others for the row label).
	 */
	public static function commission(): array {
		$periodw = '(?:(?:this|last|next)\s+(?:month|quarter|year|week)\s+|(?:january|february|march|april|may|june|july|august|september|october|november|december|q[1-4]|mtd|ytd|\d{4})\s+)?';
		$pname   = '([A-Za-z][\w.\-]*(?:\s+[A-Za-z][\w.\-]*)?)';
		$name    = '([A-Za-z][\w.\-]*(?:\s+[A-Za-z][\w.\-]*){0,2}?)';
		$earn    = '(?:earn|earns|earned|make|makes|made|get|gets|got|bring in|brings in|brought in|pull|pulls|pulled|take home|takes home|took home)';
		return array(
			'word'            => 'commission',
			'earn'            => array(
				'\b(?:did|does|do)\s+[A-Za-z][\w.\-]*(?:\s+[A-Za-z][\w.\-]*){0,2}?\s+' . $earn . '\b',
				'\b[A-Za-z][\w.\-]*(?:\s+[A-Za-z][\w.\-]*){0,2}?\s+(?:earned|made|brought in|pulled in|took home)\b',
			),
			'person_specific' => '\b[A-Za-z][\w.\-]*(?:\'s|’s)\s+' . $periodw . 'commissions?\b',
			'company_wide'    => '\b(by (?:salesperson|rep|person|month)|everyone|all (?:reps|salespeople|staff)|the (?:whole )?team|company-?wide|total commissions?|did we pay|do we pay|we paid|our (?:total |average )?commission|average commission|commission report|how do commissions?|how does commission|commission rates?|commission structure|commission plan)\b',
			'self'            => array(
				'\b(my|me|mine)\b',
				'\b(?:did|do|have|has|how much (?:have|did))?\s*i\s+(?:have\s+)?(?:earn|earned|make|made|get|got|take home|took home|bring in|brought in|pull|pulled)\b',
				'\bmy\s+(?:total\s+)?(?:pay|earnings|take)\b',
			),
			'subject'         => array(
				array( 're' => '\b' . $pname . '(?:\'s|’s)\s+' . $periodw . 'commissions?\b', 'g' => 1 ),
				array( 're' => '\b' . $pname . '\s+(?:and|&|,|or|vs|versus)\s+' . $pname . '(?:\s+(?:and|&|,)\s+' . $pname . ')?\s+(?:[a-z]+\s+)?commissions?\b', 'g' => 1, 'multi' => array( 2, 3 ) ),
				array( 're' => '\bcommissions?\s+(?:for|of)\s+' . $pname . '\s+(?:and|&|,)\s+' . $pname, 'g' => 1, 'multi' => array( 2 ) ),
				array( 're' => '\bcommissions?\s+(?:for|of|owed to|earned by|due to)\s+' . $name, 'g' => 1 ),
				array( 're' => '\b(?:rate|basis|math|breakdown)\s+(?:on|for|of)\s+' . $pname . '(?:\'s|’s)?\s+' . $periodw . 'commissions?', 'g' => 1 ),
				array( 're' => '\b(?:did|does|do)\s+' . $name . '\s+' . $earn, 'g' => 1 ),
				array( 're' => '\b' . $name . '\s+(?:earned|made|brought in|pulled in|took home)\b', 'g' => 1 ),
				array( 're' => '\bcommissions?\b.*?\bfor\s+' . $name, 'g' => 1 ),
				array( 're' => '\b(?:up|in|on|of|toward|towards|count toward|count towards|counts toward|counted toward)\s+' . $pname . '(?:\'s|’s)?\s+' . $periodw . 'commissions?', 'g' => 1 ),
				array( 're' => '\b' . $pname . '\s+' . $periodw . 'commissions?\b', 'g' => 1, 'plural' => true ),
			),
			'take'            => array(
				'self_frag'    => '^(?:i|have i|has i|had i|did i|do i|i have|i did)$',
				'lead'         => '^(?:give|show|get|grab|find|pull|look|tell|me|us|the|a|an|my|our|up|and|or|vs|versus|commission|commissions|to|for|of|on|in|into|at|toward|towards|count|counts|counted|counting|please|can|you|i|what|whats|what\'s|is|are|was|were|do|does|did|could|how|much|see|make|makes|made|earn|earns|earned|pay|paid|rate|basis|math|break|breakdown|down|drill|detail|details)\b\s*',
				'trail_conn'   => '\s+\b(for|in|during|this|last|next|on)\b.*$',
				'trail_period' => '\s+\b(january|february|march|april|may|june|july|august|september|october|november|december|today|tomorrow|yesterday|month|year|quarter|week|mtd|ytd|q[1-4]|\d{4})\b.*$',
				'plural_s'     => '^[A-Za-z][\w.\-]*[a-rt-z]s$',
				// A name whose last word is a thing, not a person ("how much did the Example job make").
				'last_not'     => '\b(?:' . self::with_trade( 'job|jobs|project|projects|install|installs|estimate|estimates|invoice|invoices|sale|house|home|account|order|company|team' ) . ')$',
				// Words that are never a salesperson (with the fuzzy launch verbs, so "luanch commission" is not a person).
				'stop'         => '^(i|have|has|had|commission|commissions|this|last|next|for|of|the|a|an|report|summary|by|total|owed|due|rate|rates|check|breakdown|history|my|me|mine|our|us|we|today|tomorrow|yesterday|month|year|quarter|week|q[1-4]|january|february|march|april|may|june|july|august|september|october|november|december|jan|feb|mar|apr|jun|jul|aug|sep|sept|oct|nov|dec|mtd|ytd|qtd|open|launch|start|pull|show|go|goto|check|view|bring|jump|fire|new|create|add|begin|see|calculate|calc|make|makes|made|making|earn|earns|earned|earning|get|gets|got|pay|paid|paying|average|avg|much|did|do|does|done|is|are|was|were|how|what|whats|job|jobs|sale|sales|money|opne|oepn|luanch|lanuch|strat|shwo|chekc|veiw)\b',
			),
			'period'          => '\b((?:january|february|march|april|may|june|july|august|september|october|november|december)(?:\s+\d{4})?|(?:first|second|third|fourth|1st|2nd|3rd|4th)\s+quarter(?:\s+\d{4})?|q[1-4](?:\s+\d{4})?|this (?:month|quarter|year)|last (?:month|quarter|year)|quarter to date|month to date|year to date|year-to-date|qtd|mtd|ytd|\d{4}-\d{2}|\d{1,2}\/\d{4}|\d{4})\b',
			'focus'           => array(
				array( 'products', '\b(by product|by category|product (?:line|split|breakdown|mix)|which products?|what products?)\b' ),
				array( 'jobs', '\b(which jobs?|what jobs?|jobs? (?:counted|list|breakdown|make up|are in)|per job|by job|list (?:the )?jobs?|what invoices?|which invoices?)\b' ),
				array( 'basis', '\b(how (?:is|was|are) .*calculated|how .*calculate|what(?:\'s| is) the (?:rate|basis)|the rate on|commission rate|what rate|breakdown of (?:the )?(?:rate|math)|how much .*net|net commissionable)\b' ),
			),
		);
	}

	/**
	 * A stock question about an item ("how much tape do we have", "any hinges in stock"). `frames` are tried in order on
	 * the lower-cased ask; group 1 is the item. `not` refuses a capture that is a person, a time or another app's thing
	 * ("what's available tomorrow", "how many leads do we have"); those go on as before.
	 */
	public static function stock(): array {
		return array(
			// Anchored at both ends: only a short tail may follow ("right now", "today"), so "how many are left on the
			// Example job" is a question about a job, not about stock.
			'frames' => array(
				'^how (?:much|many)\s+(.+?)\s+(?:do we have|have we got|do we got|is there|are there|(?:are|is)\s+(?:left|available|in stock|on hand)|left|in stock|on hand|available)(?:\s+(?:right now|today|now|in stock|on hand|in the shop))?\s*\??$',
				'^(?:do we have|have we got|got any)\s+(?:any\s+)?(.+?)\s+(?:in stock|on hand|available|left)(?:\s+(?:right now|today|now))?\s*\??$',
				'^(?:are there |is there )?(?:any )?(.+?)\s+(?:(?:are|is)\s+)?(?:available|in stock|on hand|left)(?:\s+(?:right now|today|now))?\s*\??$',
			),
			'not'    => '^(?:what|whats|what\'s|who|whos|when|where|why|which|is|are|it|that|this|there|anything|everything|nothing|anyone|anybody)\b|\b(?:leads?|estimates?|quotes?|jobs?|appointments?|slots?|commissions?|surveys?|customers?|invoices?|projects?|messages?|photos?|sales|money|time|times|days?|hours?|weeks?|tomorrow|today|people|installers?|installs?|crews?|spots?|openings?|me|my|i|revenue|cash|profit|reviews?|budget|tickets?|trucks?|vans?|vehicles?|staff|techs?|reps?|salespeople|room|space|capacity|orders?|deals?|calls?|e-?mails?|tasks?|work|anyone|someone|help)\b',
			'trim'   => '^(?:the|any|some|our)\s+',
		);
	}

	/** The item asked about, or ''. The server's twin of the page's stock rule. */
	public static function match_stock( string $q ): string {
		$st = self::stock();
		$lo = strtolower( trim( $q ) );
		foreach ( $st['frames'] as $f ) {
			if ( preg_match( self::re( $f ), $lo, $m ) ) {
				$item = trim( (string) preg_replace( self::re( $st['trim'] ), '', trim( $m[1] ) ) );
				if ( '' === $item || strlen( $item ) > 60 || preg_match( self::re( $st['not'] ), $item ) ) {
					return '';
				}
				return $item;
			}
		}
		return '';
	}

	/**
	 * Everything the page needs, for zdzData.askPhrases. The stock rule only for someone the registry lets use
	 * stock.lookup (no stock row on the shared device, or with the Stock app missing).
	 */
	public static function for_js( int $uid = 0 ): array {
		return array(
			'v'             => self::V,
			'launchVerb'    => self::LAUNCH_VERB,
			'fuzzyVerbs'    => self::FUZZY_VERBS,
			'commAggregate' => self::COMM_AGGREGATE,
			'launch'        => self::launch_rows(),
			'ctx'           => self::launch_ctx(),
			'commission'    => self::commission(),
			'stock'         => ( class_exists( 'ZDZ_Capabilities' ) && $uid > 0 && ZDZ_Capabilities::can( 'stock.lookup', $uid ) ) ? self::stock() : null,
			// The work people do, said or typed, opens the app that does it (ZDZ_Ask_Actions).
			'actions'       => class_exists( 'ZDZ_Ask_Actions' ) ? ZDZ_Ask_Actions::for_js() : null,
		);
	}

	/* ── The interpreter (the server's twin of the page's) ─────────────────────────────────────────────── */

	/** Compile a shared pattern for PCRE. */
	public static function re( string $p ): string {
		return '/' . str_replace( '/', '\/', str_replace( '\/', '/', $p ) ) . '/i';
	}

	/** A pattern both sides can run: PCRE compiles it, and it uses nothing JavaScript lacks. */
	public static function valid( string $p ): bool {
		if ( preg_match( '/\(\?<[=!]|\(\?[a-zA-Z]|\(\?>|\(\?P|\(\?<\w|[+*?}]\+|\\\\[AzZGQEKRhpPX]/', $p ) ) {
			return false;   // lookbehind, inline flags, atomic or named groups, possessive quantifiers, PCRE-only escapes
		}
		return false !== @preg_match( self::re( $p ), '' );   // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/** Clean a captured name; '' when it is not a person. */
	public static function take( string $s ): string {
		$t = self::commission()['take'];
		$n = trim( preg_replace( '/\s+/', ' ', $s ) );
		if ( '' === $n || preg_match( self::re( $t['self_frag'] ), $n ) ) {
			return '';
		}
		$guard = 0;
		while ( preg_match( self::re( $t['lead'] ), $n ) && $guard++ < 20 ) {
			$next = (string) preg_replace( self::re( $t['lead'] ), '', $n, 1 );
			if ( $next === $n ) {
				break;
			}
			$n = $next;
		}
		$n    = (string) preg_replace( '/^s\s+/i', '', $n );
		$n    = trim( (string) preg_replace( self::re( $t['trail_conn'] ), '', $n ) );
		$n    = trim( (string) preg_replace( self::re( $t['trail_period'] ), '', $n ) );
		$toks = array_values( array_filter( explode( ' ', $n ), 'strlen' ) );
		if ( count( $toks ) > 2 ) {
			$toks = array_slice( $toks, -2 );
		}
		$last = end( $toks );
		if ( $last && strlen( $last ) > 2 && preg_match( self::re( $t['plural_s'] ), $last ) ) {
			$toks[ count( $toks ) - 1 ] = substr( $last, 0, -1 );
		}
		$n = implode( ' ', $toks );
		if ( '' === $n || strlen( $n ) < 2 || preg_match( self::re( $t['stop'] ), $n ) || preg_match( self::re( $t['last_not'] ), $n ) ) {
			return '';
		}
		return $n;
	}

	/**
	 * A named person's commission, or null. Returns {subject ('' = self), period, focus, others[]}.
	 * The same steps, in the same order, as the page's rule.
	 */
	public static function match_commission( string $q ): ?array {
		$c    = self::commission();
		$lo   = strtolower( trim( $q ) );
		$has  = false !== strpos( $lo, $c['word'] );
		$earn = false;
		foreach ( $c['earn'] as $p ) {
			if ( preg_match( self::re( $p ), $q ) ) {
				$earn = true;
				break;
			}
		}
		if ( ! $has && ! $earn ) {
			return null;
		}
		if ( ! preg_match( self::re( $c['person_specific'] ), $q ) && preg_match( self::re( $c['company_wide'] ), $lo ) ) {
			return null;
		}
		$self = false;
		foreach ( $c['self'] as $p ) {
			if ( preg_match( self::re( $p ), $q ) ) {
				$self = true;
				break;
			}
		}

		$subject = '';
		$others  = array();
		foreach ( $c['subject'] as $row ) {
			if ( ! preg_match( self::re( $row['re'] ), $q, $m ) ) {
				continue;
			}
			$cand = (string) ( $m[ $row['g'] ] ?? '' );
			if ( ! empty( $row['plural'] ) ) {
				$cand = (string) preg_replace( '/^([A-Za-z][\w.\-]*[a-rt-z])s$/i', '$1', $cand );
			}
			$subject = self::take( $cand );
			if ( '' !== $subject ) {
				foreach ( (array) ( $row['multi'] ?? array() ) as $g ) {
					$o = self::take( (string) ( $m[ $g ] ?? '' ) );
					if ( '' !== $o ) {
						$others[] = $o;
					}
				}
				break;
			}
		}
		if ( '' === $subject && ! $self ) {
			return null;
		}
		$period = preg_match( self::re( $c['period'] ), $q, $pm ) ? trim( $pm[1] ) : '';
		$focus  = '';
		foreach ( $c['focus'] as $f ) {
			if ( preg_match( self::re( $f[1] ), $lo ) ) {
				$focus = $f[0];
				break;
			}
		}
		return array( 'subject' => $subject, 'period' => $period, 'focus' => $focus, 'others' => $others );
	}

	/**
	 * Which launch row an ask opens, or null: { ids, verb, key, value }. $apps: the app ids this person can open (null =
	 * any). The server's twin of the page's launch detector, step for step: a row needs a launch verb (a one-letter slip
	 * in a single-word verb counts: "opne"), or (when it allows) its app word within the ask's first few words; a
	 * commission aggregate never opens anything, it goes to the chat.
	 */
	public static function match_launch( string $q, ?array $apps = null ): ?array {
		$lo = strtolower( trim( $q ) );
		if ( '' === $lo ) {
			return null;
		}
		$verb      = (bool) preg_match( self::re( self::LAUNCH_VERB ), $lo ) || self::fuzzy_verb( $lo );
		$ctx       = self::launch_ctx();
		$aggregate = (bool) preg_match( '/\bcommissions?\b/', $lo ) && (bool) preg_match( self::re( self::COMM_AGGREGATE ), $lo );
		foreach ( self::launch_rows() as $r ) {
			if ( ! preg_match( self::re( $r['re'] ), $lo ) ) {
				continue;
			}
			if ( $aggregate && in_array( 'commission-calculator', $r['ids'], true ) ) {
				return null;
			}
			$starts = ! $r['require_verb'] && preg_match( self::re( $r['re'] ), substr( $lo, 0, 14 ) );
			if ( ! $verb && ! $starts ) {
				continue;
			}
			$id = '';
			foreach ( $r['ids'] as $a ) {
				if ( null === $apps || in_array( $a, $apps, true ) ) {
					$id = $a;
					break;
				}
			}
			if ( '' === $id ) {
				continue;
			}
			$value = '';
			if ( 'for' === $r['ctx'] && preg_match( self::re( $ctx['for'] ), $q, $fm ) ) {
				$value = trim( (string) preg_replace( self::re( $ctx['for_trim'] ), '', $fm[1] ) );
				$value = trim( (string) preg_replace( self::re( $ctx['for_lead'] ), '', $value ) );
			} elseif ( 'period' === $r['ctx'] && preg_match( self::re( $ctx['period'] ), $q, $pm ) ) {
				$value = $pm[1];
			}
			return array( 'id' => $id, 'verb' => $r['verb'], 'key' => $r['key'], 'value' => $value );
		}
		return null;
	}

	/** A word of four letters or more one slip away (a swap, a missing, extra or wrong letter) from a single-word verb. */
	public static function fuzzy_verb( string $lo ): bool {
		foreach ( preg_split( '/[^a-z]+/', $lo ) as $wd ) {
			if ( strlen( $wd ) < 4 ) {
				continue;
			}
			foreach ( self::FUZZY_VERBS as $vb ) {
				if ( abs( strlen( $wd ) - strlen( $vb ) ) <= 1 && $wd !== $vb && self::one_slip( $wd, $vb ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Damerau-Levenshtein distance of at most one (the page's dl1). */
	private static function one_slip( string $a, string $b ): bool {
		$la = strlen( $a );
		$lb = strlen( $b );
		$prev2 = array();
		$prev  = range( 0, $lb );
		for ( $i = 1; $i <= $la; $i++ ) {
			$cur = array( $i );
			for ( $k = 1; $k <= $lb; $k++ ) {
				$cost = $a[ $i - 1 ] === $b[ $k - 1 ] ? 0 : 1;
				$val  = min( $prev[ $k ] + 1, $cur[ $k - 1 ] + 1, $prev[ $k - 1 ] + $cost );
				if ( $i > 1 && $k > 1 && $a[ $i - 1 ] === $b[ $k - 2 ] && $a[ $i - 2 ] === $b[ $k - 1 ] ) {
					$val = min( $val, $prev2[ $k - 2 ] + 1 );
				}
				$cur[ $k ] = $val;
			}
			$prev2 = $prev;
			$prev  = $cur;
		}
		return $prev[ $lb ] <= 1;
	}
}
