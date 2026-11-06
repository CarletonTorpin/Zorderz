<?php
/**
 * ZANA_LLM_Client — the chat's one road to a model.
 *
 * Every model call this app makes goes through the platform's Ai router, ZDZ_Core_LLM::ask( $role, … ), as the caller
 * `sales-analytics` (a row of ZDZ_Core_LLM::CALLERS). There is no other road: no provider client, no model name, no
 * price in this app. Which model answers, what it costs and the monthly budget are the business's settings in the
 * router (Zorderz → Ai usage).
 *
 * WHAT THE ROUTER DOES FOR IT: codes customer details before anything leaves (ZDZ_Private; with no coder nothing is
 * sent), prices each call before sending, holds the month's budget, refuses the shared device, refuses anything outside
 * zero data retention, and says a failure as one.
 *
 * WHAT IT DOES ITSELF
 *   - the person's daily allowance (ZANA_Allowance): checked before a call, its cost added after;
 *   - roles: a call that asks for the most effort is `reason`; everything else `chat`;
 *   - the instructions in three parts: the part before SPLIT is the same for everyone and every call (sent as the
 *     router's system_prefix, cached for an hour, with CODES_NOTE at its end); the part before TIER_SPLIT is the same for
 *     everyone at one permission level (system_tier, its own cached block); the rest follows unmarked. The fixed parts
 *     are the business's own instructions (marked company text, so only the one-word name rules are off in them);
 *   - the web: a short, data-free question goes to the router's `web` role as it is; anything else gets a question-only
 *     web brief first, and the findings, or a "could not look it up" note, go in front of the call, which never carries
 *     the web;
 *   - pictures: the chat is not a registered picture sender, so a picture part is left out and the model is told;
 *   - the answer comes back coded; names_check() takes out a person's name the model wrote that it was never sent,
 *     then the codes are turned back into words for the person who asked (they were allowed to see what was coded).
 *
 * chat() returns { ok, text, error, kind } and never throws.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_LLM_Client {

	/** This app's row in ZDZ_Core_LLM::CALLERS (its app id). */
	const CALLER = 'sales-analytics';

	/** Ends the fixed text that is the same for everyone (cached). */
	const SPLIT = "\n<<<ZANA-CACHE-SPLIT>>>\n";

	/** Ends the text that is the same for everyone at one permission level (cached on its own). */
	const TIER_SPLIT = "\n<<<ZANA-TIER-SPLIT>>>\n";

	const DEFAULT_S   = 150;
	const BRIEF_MAX_S = 45;
	const MAX_OUT     = 16384;
	const CAP_MILLI   = 1000;   // the router's default per-call cap ($1), counted when a call ends in an exception

	/**
	 * The router sends every customer as a code, and the model must be told so, or it writes made-up names beside real
	 * figures. At the end of the fixed text (cached), in words only: the router turns any bracket written here into [ ].
	 */
	const CODES_NOTE = "CUSTOMER CODES (how customers reach you; this overrides any other instruction about writing names, before or after it).\n"
		. "To keep customers private, every customer's name, e-mail, phone and street in the data reaches you as a short code: the letter c, a colon and digits, or the letter e, a colon and letters and digits, inside special double square brackets. The app turns each code back into the real words before anyone reads your answer.\n"
		. "- Wherever you name a customer, copy that customer's code from the data exactly, brackets and every character included. The reader sees the real name.\n"
		. "- Never write a customer's name in words. Never guess, complete, shorten, translate or invent a name, and never describe a code (\"customer 123\").\n"
		. "- If you cannot find the code for a customer, write \"a customer\". A wrong name is far worse than no name.\n"
		. "- The same goes for an e-mail, phone or street: copy its code or leave it out.";

	const PICTURE_NOTE = '[A picture was attached here. The chat cannot read pictures yet: if the question needs it, say so plainly.]';

	/** Capitalised words that start labels and headings, never a person's name. */
	const NOT_NAMES = array( 'key', 'takeaway', 'takeaways', 'bottom', 'line', 'lines', 'year', 'years', 'over', 'month', 'months',
		'quarter', 'week', 'weeks', 'day', 'days', 'total', 'totals', 'grand', 'sub', 'subtotal', 'revenue', 'sales', 'growth', 'peaked',
		'peak', 'average', 'avg', 'top', 'biggest', 'largest', 'smallest', 'net', 'gross', 'change', 'trend', 'summary', 'note', 'notes',
		'what', 'why', 'how', 'where', 'who', 'which', 'when', 'this', 'that', 'these', 'those', 'the', 'and', 'for', 'from', 'with',
		'new', 'old', 'last', 'next', 'prior', 'previous', 'same', 'other', 'all', 'each', 'per', 'only', 'more', 'less', 'most', 'least',
		'up', 'down', 'still', 'open', 'paid', 'owed', 'due', 'overdue', 'invoice', 'invoices', 'estimate', 'estimates', 'job', 'jobs',
		'customer', 'customers', 'lead', 'leads', 'source', 'sources', 'product', 'products', 'service', 'services', 'region', 'area',
		'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december',
		'jan', 'feb', 'mar', 'apr', 'jun', 'jul', 'aug', 'sep', 'sept', 'oct', 'nov', 'dec', 'monday', 'tuesday', 'wednesday',
		'thursday', 'friday', 'saturday', 'sunday', 'spring', 'summer', 'fall', 'autumn', 'winter', 'season', 'chart', 'charts',
		'table', 'figure', 'step', 'option', 'plan', 'goal', 'risk', 'action', 'actions', 'next', 'steps', 'big', 'small', 'high', 'low' );

	const NAME_GONE = '[name not in our records]';

	/** @var int the person asking (0 for background work). */
	private $uid;
	/** @var string the last answer's finish: '', 'stop' or 'length'. */
	private $finish = '';
	/** @var array the last router answer's numbers (model, usd, tries), for the turn's record. */
	public $last_numbers = array();
	/** @var string|null what the answer call was sent, as words (null outside an answer call). */
	private $sent_plain = null;

	public function __construct( int $uid = 0 ) {
		$this->uid = $uid;
	}

	public function last_finish_reason(): string {
		return $this->finish;
	}

	/**
	 * The chat's one call.
	 *
	 * @param array $messages [ { role: system|user|assistant, content: string|parts } ]
	 * @param array $opts     budget_s (seconds the answer must arrive in), web (bool), reason (bool), max_tokens (int)
	 * @return array{ok:bool,text:string,error:string,kind:string}
	 */
	public function chat( array $messages, array $opts = array() ): array {
		$this->finish       = '';
		$this->last_numbers = array();
		if ( ! class_exists( 'ZDZ_Core_LLM' ) ) {
			return self::fail( __( 'The assistant isn\'t set up on this site yet (the Ai router is missing).', 'zorderz' ), 'setup' );
		}
		$no = ZANA_Allowance::refusal( $this->uid );
		if ( null !== $no ) {
			return self::fail( $no, 'allowance' );
		}
		$budget   = isset( $opts['budget_s'] ) ? max( 15, (int) $opts['budget_s'] ) : self::DEFAULT_S;
		$deadline = time() + $budget;
		$role     = ! empty( $opts['reason'] ) ? 'reason' : 'chat';
		$max_out  = isset( $opts['max_tokens'] ) && (int) $opts['max_tokens'] > 0 ? (int) $opts['max_tokens'] : self::MAX_OUT;

		$web = ! empty( $opts['web'] );
		list( $system, $msgs ) = self::to_router( $messages );
		$flat = '' !== trim( $system ) ? array_merge( array( array( 'role' => 'system', 'content' => self::without_marks( $system ) ) ), $msgs ) : $msgs;
		if ( $web && ZDZ_Core_LLM::web_safe( $flat ) ) {
			// a short question on its own: the web role takes it as it is
			return $this->ask( 'web', $system, $msgs, $max_out, $deadline );
		}
		if ( $web ) {
			$note       = $this->web_brief( $messages, min( self::BRIEF_MAX_S, (int) floor( $budget / 3 ) ) );
			// after the fixed text, so it joins the part after the split mark: the findings change every question and
			// must never ride in the cached, company-marked fixed text
			$messages[] = array( 'role' => 'system', 'content' => $note );
			list( $system, $msgs ) = self::to_router( $messages );
		}
		// a name the model writes that is nowhere in what it was sent is taken out (names_check works on the answer while
		// it is still coded: a real customer is a code there, so a name from the records is never touched)
		$this->sent_plain = $system . "\n" . self::plain_of( $msgs );
		try {
			return $this->ask( $role, $system, $msgs, $max_out, $deadline );
		} finally {
			$this->sent_plain = null;
		}
	}

	/* ---------------------------------------------------------------- the router */

	private function ask( string $role, string $system, array $msgs, int $max_out, int $deadline ): array {
		try {
			$r = ZDZ_Core_LLM::ask( $role, $this->request( $role, $system, $msgs, $max_out ), array(
				'caller'       => self::CALLER,
				'user_id'      => $this->uid,
				'deadline'     => $deadline,
				'primary_only' => self::primary_only(),
			) );
		} catch ( \Throwable $e ) {
			// the router books a call that threw at its whole hold; the day counts the per-call cap the same way
			ZANA_Allowance::add_milli( $this->uid, self::CAP_MILLI );
			error_log( 'ZANA_LLM_Client: the Ai router threw (' . get_class( $e ) . ').' );
			return self::fail( __( 'The Ai couldn\'t answer that just now. Try again in a minute.', 'zorderz' ), 'transport' );
		}
		return $this->answered( $r );
	}

	/** The router request: the fixed part first (cached), the level's part next (cached on its own), then the rest. */
	public function request( string $role, string $system, array $msgs, int $max_out ): array {
		$req = array(
			'system'     => $system,
			'messages'   => $msgs,
			'max_output' => $max_out,
		);
		if ( 'web' === $role ) {
			$req['system'] = trim( self::without_marks( $system ) );
			return $req;
		}
		list( $prefix, $rest ) = self::split_system( $system );
		list( $tier, $rest )   = self::split_tier( $rest );
		if ( '' !== $prefix ) {
			$req['system_prefix']         = $prefix . "\n\n" . self::CODES_NOTE;
			$req['system_prefix_company'] = true;   // the business's own instructions: no one-word name rules in them
			if ( '' !== $tier ) {
				$req['system_tier']         = $tier;
				$req['system_tier_company'] = true;
			}
			$req['system'] = $rest;
		} else {
			$req['system'] = self::without_marks( ( '' !== $tier ? $tier . "\n\n" : '' ) . $rest ) . "\n\n" . self::CODES_NOTE;
		}
		$req['cache_latest'] = false;
		return $req;
	}

	/**
	 * The shared device, read the way the Ai router reads it (ZDZ_Core_LLM::is_kiosk: its capability, its role, or the
	 * hierarchy's word for it), so the chat and the router never disagree about who it is.
	 */
	public static function is_shared_device( int $uid ): bool {
		if ( $uid <= 0 ) {
			return false;
		}
		if ( class_exists( 'ZDZ_Core_LLM' ) ) {
			return (bool) ZDZ_Core_LLM::is_kiosk( $uid );
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) && ZDZ_Hierarchy::is_kiosk( $uid ) ) {
			return true;
		}
		return class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid );
	}

	/** May a business pin the chat to its primary model (the router's rule 14)? Off unless it says so. */
	public static function primary_only(): bool {
		return function_exists( 'apply_filters' ) && (bool) apply_filters( 'zana_llm_primary_only', false );
	}

	private function answered( $r ): array {
		if ( is_wp_error( $r ) ) {
			$d = (array) $r->get_error_data();
			ZANA_Allowance::add_milli( $this->uid, (int) ( $d['usd_milli'] ?? 0 ) );
			return self::error_words( $r );
		}
		$r = is_array( $r ) ? $r : array();
		ZANA_Allowance::add_milli( $this->uid, (int) ceil( (float) ( $r['usd'] ?? 0 ) * 1000 ) );
		$this->last_numbers = array( 'model' => (string) ( $r['model'] ?? '' ), 'usd' => (float) ( $r['usd'] ?? 0 ), 'tries' => (int) ( $r['tries'] ?? 1 ) );
		$text = trim( (string) ( $r['text'] ?? '' ) );
		if ( '' === $text ) {
			return self::fail( __( 'The Ai gave back an empty answer.', 'zorderz' ), 'empty' );
		}
		$this->finish = 'max_tokens' === (string) ( $r['stop'] ?? '' ) ? 'length' : 'stop';
		if ( null !== $this->sent_plain ) {
			$text = self::names_check( $text, $this->sent_plain, self::team_names() );   // before decoding
		}
		return array( 'ok' => true, 'text' => self::decode( $text ), 'error' => '', 'kind' => '' );
	}

	/** A failure, in words the person can act on. */
	private static function fail( string $msg, string $kind ): array {
		return array( 'ok' => false, 'text' => '', 'error' => $msg, 'kind' => $kind );
	}

	/** The router's refusal or failure, in words a person can act on. */
	public static function error_words( $e ): array {
		$code = is_object( $e ) && method_exists( $e, 'get_error_code' ) ? (string) $e->get_error_code() : '';
		$msg  = is_object( $e ) && method_exists( $e, 'get_error_message' ) ? (string) $e->get_error_message() : '';
		switch ( $code ) {
			case 'zdz_llm_unreachable':
			case 'zdz_llm_time':
				return self::fail( __( 'The Ai is busy or didn\'t answer in time. Try again in a minute.', 'zorderz' ), 'transport' );
			case 'zdz_llm_no_key':
				return self::fail( __( 'The assistant\'s Ai key isn\'t set up. An admin checks Zorderz → Credentials.', 'zorderz' ), 'setup' );
			case 'zdz_llm_kiosk':
				return self::fail( __( 'The assistant is not available on the shared device.', 'zorderz' ), 'kiosk' );
			case 'zdz_llm_empty':
				return self::fail( __( 'The Ai gave back an empty answer.', 'zorderz' ), 'empty' );
			case 'zdz_llm_budget':
			case 'zdz_llm_month':
				return self::fail( '' !== $msg ? $msg : __( 'The site\'s monthly Ai budget does not allow this call. An admin checks Zorderz → Ai usage.', 'zorderz' ), 'budget' );
		}
		// The router's own words are written for a person already; a code alone is not.
		return self::fail( '' !== $msg ? $msg : __( 'The Ai couldn\'t answer that just now.', 'zorderz' ), 'other' );
	}

	/** Tests hand in the coder's decode: callable( $text ) => string. */
	public static $decoder = null;

	/** The router's codes back to the words, for the person who asked. */
	public static function decode( string $text ): string {
		if ( is_callable( self::$decoder ) ) {
			return (string) call_user_func( self::$decoder, $text );
		}
		if ( class_exists( 'ZDZ_Private' ) && method_exists( 'ZDZ_Private', 'for' ) ) {
			$c = ZDZ_Private::for( 'llm' );
			if ( $c && method_exists( $c, 'decode' ) ) {
				return (string) $c->decode( $text, true );
			}
		}
		return $text;
	}

	/* ---------------------------------------------------------------- the names check */

	/** The words of the router messages, as sent before coding. */
	public static function plain_of( array $msgs ): string {
		$out = array();
		foreach ( $msgs as $m ) {
			$c = $m['content'] ?? '';
			if ( is_string( $c ) ) {
				$out[] = $c;
			} elseif ( is_array( $c ) ) {
				foreach ( $c as $p ) {
					if ( is_array( $p ) && isset( $p['text'] ) && is_string( $p['text'] ) ) {
						$out[] = $p['text'];
					}
				}
			}
		}
		return implode( "\n", $out );
	}

	/** The people who work here (their names are never "made up"). */
	public static function team_names(): array {
		$out = array();
		if ( function_exists( 'get_users' ) ) {
			foreach ( (array) get_users( array( 'fields' => array( 'display_name' ), 'number' => 300 ) ) as $u ) {
				$out[] = (string) ( is_object( $u ) ? ( $u->display_name ?? '' ) : $u );
			}
		}
		return $out;
	}

	/**
	 * Every customer in the data reaches the model as a code, so in the answer as it comes back (still coded) a person's
	 * name written in words that appears nowhere in what the model was sent did not come from the records. On a line
	 * that carries a figure ($, "dollars", or a table row with a digit) -- where a made-up name passes for a real
	 * customer -- a run of two or three capitalised words is replaced with NAME_GONE when its last word was never sent.
	 * A run that holds a label word ("Key Takeaway", "August Revenue") or is made of one team member's name stays. The
	 * answer ends by saying how many were taken out. JSON is left alone.
	 */
	public static function names_check( string $answer, string $sent, array $team = array() ): string {
		$t = trim( $answer );
		if ( '' === $t || ( ( '{' === $t[0] || '[' === $t[0] ) && null !== json_decode( $t, true ) ) ) {
			return $answer;
		}
		$seen = array();
		if ( preg_match_all( '/[\p{L}][\p{L}\'’\-]*/u', $sent, $m ) ) {
			foreach ( $m[0] as $w ) {
				$seen[ mb_strtolower( (string) preg_replace( '/[\'’]s$/u', '', $w ) ) ] = true;
			}
		}
		$team_w = array();
		foreach ( $team as $n ) {
			$ws = preg_split( '/\s+/u', mb_strtolower( trim( (string) $n ) ), -1, PREG_SPLIT_NO_EMPTY );
			if ( $ws ) {
				$team_w[] = $ws;
			}
		}
		$not   = array_flip( self::NOT_NAMES );
		$gone  = array();
		$lines = preg_split( '/(\r\n|\n)/', $answer, -1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( $lines as $i => $line ) {
			if ( 1 === $i % 2 ) {
				continue;
			}
			$figure = preg_match( '/\$\s?\d|\d[\d,]*(?:\.\d+)?\s*dollars\b/i', $line ) || ( preg_match( '/^\s*\|/', $line ) && preg_match( '/\d/', $line ) );
			if ( ! $figure ) {
				continue;
			}
			$word = '\p{Lu}(?=[\p{L}\'’\-]*\p{Ll})[\p{L}\'’\-]+';
			$out  = preg_replace_callback(
				'/(?<![\p{L}\'’\-])' . $word . '(?:[ \t]+(?:\p{Lu}\.|' . $word . ')){1,2}(?![\p{L}])/u',
				function ( $mm ) use ( $seen, $team_w, $not, &$gone ) {
					$whole = $mm[0];
					$words = array();
					foreach ( preg_split( '/[ \t]+/', $whole ) as $w ) {
						$words[] = mb_strtolower( (string) preg_replace( '/[\'’]s$/u', '', rtrim( $w, '.' ) ) );
					}
					foreach ( $words as $w ) {
						if ( isset( $not[ $w ] ) ) {
							return $whole;   // a label word: "Key Takeaway", "August Revenue"
						}
					}
					foreach ( $team_w as $tw ) {
						if ( ! array_diff( array_filter( $words, function ( $w ) { return mb_strlen( $w ) > 2; } ), $tw ) ) {
							return $whole;   // every word is in one team member's name
						}
					}
					$last = end( $words );
					if ( mb_strlen( $last ) < 3 || isset( $seen[ $last ] ) ) {
						return $whole;
					}
					$gone[ $whole ] = true;
					return self::NAME_GONE;
				},
				$line
			);
			$lines[ $i ] = is_string( $out ) ? $out : $line;
		}
		if ( ! $gone ) {
			return $answer;
		}
		$n = count( $gone );
		return implode( '', $lines ) . "\n\n---\n_" . sprintf(
			/* translators: %d: how many names were taken out */
			_n( 'Names check: %d name the Ai wrote is not in the records, so it was taken out. The figures beside it come from the records.', 'Names check: %d names the Ai wrote are not in the records, so they were taken out. The figures beside them come from the records.', $n, 'zorderz' ),
			$n
		) . '_';
	}

	/* ---------------------------------------------------------------- shapes */

	/** The split marks, taken out of a text that is not split (a web question, an unsplit prompt). */
	public static function without_marks( string $text ): string {
		return str_replace( array( trim( self::SPLIT ), trim( self::TIER_SPLIT ) ), '', $text );
	}

	/**
	 * A joined system text cut into ( the fixed part, the rest ) at the split mark; ( '', all ) when there is none. Any
	 * other split mark is taken out of the rest (only the first one cuts).
	 */
	public static function split_system( string $system ): array {
		$mark = trim( self::SPLIT );
		$at   = strpos( $system, $mark );
		if ( false === $at ) {
			return array( '', $system );
		}
		return array( rtrim( substr( $system, 0, $at ) ), ltrim( str_replace( $mark, '', substr( $system, $at + strlen( $mark ) ) ) ) );
	}

	/** ( the part before the tier mark, the rest ), or ( '', all ) when there is no mark. */
	public static function split_tier( string $rest ): array {
		$mark = trim( self::TIER_SPLIT );
		$at   = strpos( $rest, $mark );
		if ( false === $at ) {
			return array( '', $rest );
		}
		return array( trim( substr( $rest, 0, $at ) ), ltrim( str_replace( $mark, '', substr( $rest, $at + strlen( $mark ) ) ) ) );
	}

	/**
	 * Chat messages to the router's: every system message joined into one system text, user and assistant turns in
	 * order, same-role neighbours joined, the first turn a user's, picture parts left out (with a note).
	 *
	 * @return array { 0: system string, 1: router messages }
	 */
	public static function to_router( array $messages ): array {
		$system = array();
		$turns  = array();
		foreach ( $messages as $m ) {
			if ( ! is_array( $m ) ) {
				continue;
			}
			$role = (string) ( $m['role'] ?? 'user' );
			$text = self::text_of( $m['content'] ?? '' );
			if ( 'system' === $role ) {
				if ( '' !== trim( $text ) ) {
					$system[] = $text;
				}
				continue;
			}
			$role = in_array( $role, array( 'assistant', 'bot' ), true ) ? 'assistant' : 'user';
			if ( '' === trim( $text ) ) {
				continue;
			}
			$n = count( $turns );
			if ( $n && $turns[ $n - 1 ]['role'] === $role ) {
				$turns[ $n - 1 ]['content'] .= "\n\n" . $text;
			} else {
				$turns[] = array( 'role' => $role, 'content' => $text );
			}
		}
		if ( $turns && 'assistant' === $turns[0]['role'] ) {
			array_unshift( $turns, array( 'role' => 'user', 'content' => '(The conversation so far.)' ) );
		}
		return array( implode( "\n\n", $system ), $turns );
	}

	/** A message's text: a string, or content parts (text kept, a picture replaced by a note). */
	public static function text_of( $content ): string {
		if ( is_string( $content ) ) {
			return $content;
		}
		$out = array();
		foreach ( (array) $content as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$t = (string) ( $p['type'] ?? '' );
			if ( 'text' === $t && isset( $p['text'] ) ) {
				$out[] = (string) $p['text'];
			} elseif ( in_array( $t, array( 'image_url', 'image', 'file', 'input_image', 'pdf' ), true ) ) {
				$out[] = self::PICTURE_NOTE;
			}
		}
		return implode( "\n", $out );
	}

	/* ---------------------------------------------------------------- the web brief */

	/** The question alone: everything from the first data block on is cut, and the "USER QUESTION:" label. */
	public static function brief_question( string $text ): string {
		$cuts = array( 'DATA AVAILABLE', '=== INVOICES' );
		foreach ( ZANA_Markers::fence_tokens() as $t ) {
			$cuts[] = $t;
		}
		foreach ( $cuts as $cut ) {
			$p = strpos( $text, $cut );
			if ( false !== $p ) {
				$text = substr( $text, 0, $p );
			}
		}
		$text = (string) preg_replace( '/^\s*USER QUESTION:\s*/i', '', $text );
		return trim( mb_substr( $text, 0, 1500 ) );
	}

	/** What the model is told when there are no web findings. */
	public static function no_web_note(): string {
		return 'Note: live web search is not available for this answer. If the question needs current information from the web, say plainly that you could not look it up, and do not invent it.';
	}

	/** Findings for the question alone, from the router's web role; the no-web note when there are none. */
	private function web_brief( array $messages, int $secs ): string {
		$q = '';
		for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
			if ( 'user' === ( $messages[ $i ]['role'] ?? '' ) ) {
				$q = self::text_of( $messages[ $i ]['content'] ?? '' );
				break;
			}
		}
		$q = self::brief_question( $q );
		if ( '' === $q || $secs < 15 || null !== ZANA_Allowance::refusal( $this->uid ) ) {
			return self::no_web_note();
		}
		try {
			$r = ZDZ_Core_LLM::ask( 'web', array(
				'system'   => 'You are the web-research step for another model. Search the web for what the question needs and return concise factual findings with concrete figures and dates, each followed by the exact URL it came from. Say when sources disagree or the web is inconclusive. Never invent a figure or a URL.',
				'messages' => array( array( 'role' => 'user', 'content' => $q ) ),
			), array(
				'caller'   => self::CALLER,
				'user_id'  => $this->uid,
				'deadline' => time() + $secs,
			) );
		} catch ( \Throwable $e ) {
			ZANA_Allowance::add_milli( $this->uid, self::CAP_MILLI );
			error_log( 'ZANA_LLM_Client: the web brief threw (' . get_class( $e ) . ').' );
			return self::no_web_note();
		}
		if ( is_wp_error( $r ) ) {
			ZANA_Allowance::add_milli( $this->uid, (int) ( ( (array) $r->get_error_data() )['usd_milli'] ?? 0 ) );
			return self::no_web_note();
		}
		ZANA_Allowance::add_milli( $this->uid, (int) ceil( (float) ( $r['usd'] ?? 0 ) * 1000 ) );
		$brief = trim( self::decode( (string) ( $r['text'] ?? '' ) ) );
		return '' === $brief
			? self::no_web_note()
			: "WEB FINDINGS (from a separate web search; first-pass, unverified -- cite them as such):\n" . $brief;
	}
}
