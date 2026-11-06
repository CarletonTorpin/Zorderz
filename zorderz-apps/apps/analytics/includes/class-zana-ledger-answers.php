<?php
/**
 * ZANA_Ledger_Answers — sales, jobs, estimates, money owed, invoices paid and customers, answered by code from the
 * stored-data ledger in milliseconds: no model, no live billing call.
 *
 * One family = one route:
 *   sales      revenue, jobs (= invoices) and the average job for a period; compared with the year before, the period
 *              before or another year; or by month, year, salesperson, town, product line, brand, lead source or
 *              customer (top N)
 *   estimates  how many, worth how much, accepted, turned into invoices (conversion), still open
 *   owed       money still owed on open invoices, by days past due; what is overdue; what falls due in a period;
 *              "which invoices are overdue" as a list
 *   paid       invoices paid in full in a period, and how many days customers take to pay
 *   customers  how many billed, how many new (first job ever) and returning
 * Each takes any of: salespeople (a name or a code of a person who holds one, or "my"), a brand (the rules' brand
 * words), a product line (the catalog's), a town and a postal code the ledger holds, a lead-source group.
 *
 * Recognised by rule (detect()) with an allow-list of words: a word this reader does not know sends the question to the
 * chat's planner, never to a figure that ignores the word that mattered. Answered in the chat's fast lane and under the
 * home screen's ask bar (ZANA_Quick_Answers).
 *
 * Who: company rights (revenue and others' data; ZANA_Ledger_Access), never the shared device or Demo Mode; a money-owed
 * LIST names customers, so never a Privacy View account. Only while the billing copy is complete and synced in the last
 * three hours. A brand, product-line or date question waits while the stored labels are being re-derived.
 *
 * Contract: detect( $message ), resolve( $message, $uid ), answer( $f, $uid ), quick( $f, $uid ).
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Ledger_Answers {

	/** In every full answer's footer: a follow-up in the chat is recognised by it. */
	const MARK = 'From the sales ledger';

	/** Rows a table shows. */
	const TABLE_ROWS = 12;

	/** Rows a list shows. */
	const LIST_ROWS = 25;

	/** Hours since the last good sync before the chat's own path answers instead. */
	const FRESH_HOURS = 3;

	/** Injected in tests. */
	public static $ready = null;       // bool: the billing copy is complete and fresh
	public static $cities = null;      // town labels the ledger holds
	public static $zips = null;        // postal codes the ledger holds
	public static $categories = null;  // phrase => product line
	public static $relabelling = null; // bool: the stored labels are being re-derived

	/** What each grouping is called in a question (tried in this order). */
	const GROUP_WORDS = array(
		'month'    => '/\bby month\b|\bper month\b|\bmonthly\b|\beach month\b|\bmonth by month\b|\bmonth over month\b|\btrend(?:ed|ing|s)?\b/',
		'year'     => '/\bby year\b|\bper year\b|\byearly\b|\beach year\b|\byear by year\b|\bannual(?:ly)?\b/',
		'rep'      => '/\bby (?:sales\s*)?(?:rep|reps|salesperson|salespeople|sales people|seller|sellers|person)\b|\bper (?:sales\s*)?(?:rep|salesperson|person)\b|\beach (?:sales\s*)?(?:rep|salesperson)\b|\b(?:which|what) (?:sales\s*)?(?:rep|salesperson)\b|\btop (?:sales\s*)?(?:reps|salespeople|sales people|sellers)\b|\bwho (?:sold|billed|brought in|made|has|had|did) (?:the )?most\b/',
		'city'     => '/\bby (?:town|city|area)\b|\b(?:per|each) (?:town|city)\b|\b(?:top|best|which|what) (?:\d{1,2} )?(?:towns|cities)\b|\bwhere (?:did|do|have) we (?:sell|sold|bill|billed) (?:the )?most\b/',
		'category' => '/\bby (?:product lines?|product types?|products?|categor(?:y|ies)|lines?)\b|\b(?:per|each|every) (?:product lines?|products?|categor(?:y|ies))\b|\bproduct mix\b|\b(?:top|best|which|what) (?:\d{1,2} )?(?:product lines|products|categories)\b/',
		'brand'    => '/\bby brand\b|\bper brand\b|\b(?:top|best|which|what) brands?\b/',
		'source'   => '/\bby (?:lead )?source\b|\bper (?:lead )?source\b|\b(?:which|what|top|best) (?:lead )?sources?\b/',
		'customer' => '/\b(?:top|best|biggest|largest) (?:\d{1,2} )?(?:customers|clients|accounts)\b|\bby customer\b|\bwhich customers\b|\bwho owes (?:us )?(?:the )?most\b/',
	);

	const PAY_DAYS = '/\bhow (?:long|many days|fast|quickly|soon)\b(?:\s+[a-z\'&]+){0,5}?\s+(?:(?:to|before|until|till)\s+(?:pay|paying|pay up|be paid|get paid|getting paid|payment)|(?:get|got|getting|are|is|were|be)\s+paid|pay|paying|pay up)\b|\b(?:average|avg|mean|median|typical) (?:number of )?days\b(?:\s+[a-z\'&]+){0,4}?\s+(?:to|before|until|till)\s+(?:pay|payment|get paid|be paid)\b|\bdays?[- ]to[- ](?:pay|payment|get paid)\b|\btime to (?:pay|payment|get paid)\b/';
	const PAY_FULL = '/\b(?:invoices?|jobs?|bills?)(?:\s+(?:that|which|were|was|got|have|has|had|been|we|they|did|get|gotten))*\s+paid(?:\s+in\s+full|\s+off|\s+up)?\b|\bpaid(?:[- ]in[- ]full|[- ]off|[- ]up)?\s+(?:invoices?|jobs?|bills?)\b|\bpaid\s+in\s+full\b|\bpaid\s+off\b/';
	/** We are the payer ("how fast do we pay"): not customers paying us. */
	const WE_PAY = '/\b(?:we|i|us)\b(?:\s+[a-z\']+){0,3}?\s+pay(?:ing)?\b(?!\s+(?:us|me)\b)/';
	/** Money received for work ("jobs we got paid for"): payments, the planner's. */
	const GOT_PAID_FOR = '/\b(?:get|got|gotten|getting)\s+paid\s+(?:for|on)\b|\b(?:we|us)\b(?:\s+[a-z\']+){0,3}?\s+paid\s+(?:for|on)\b/';
	const PAY_CUTS = array(
		'/\bhow (?:long|many days|fast|quickly|soon)\b/',
		'/\b(?:average|avg|mean|median|typical) (?:number of )?days\b/',
		'/\bdays?[- ]to[- ](?:pay|payment|get paid)\b|\btime to (?:pay|payment|get paid)\b/',
		'/\b(?:to|before|until|till)\s+(?:pay up|pay|paying|be paid|get paid|getting paid|payment)\b/',
		'/\b(?:get|got|getting|gotten|are|is|were|was|be|been)\s+paid(?:\s+in\s+full|\s+off|\s+up)?\b/',
		'/\bpaid(?:[- ]in[- ]full|[- ]off|[- ]up)?\b/',
		'/\bpay(?:ing)?(?: up)?\b/',
	);

	/* ═══════════════════════════ the vocabulary (from the business's own data) ═══════════════════════════ */

	/** Name phrases for salespeople, longest first: phrase => codes. A first name two people share is left out. */
	public static function rep_phrases(): array {
		$by_name = array();
		foreach ( ZANA_Ledger_Access::rep_map() as $code => $name ) {
			$n = strtolower( trim( (string) $name ) );
			if ( '' !== $n && $n !== strtolower( (string) $code ) ) {
				$by_name[ $n ][] = strtoupper( (string) $code );
			}
		}
		$out   = array();
		$first = array();
		foreach ( $by_name as $name => $codes ) {
			$parts = preg_split( '/\s+/', $name );
			if ( count( $parts ) >= 2 && strlen( (string) end( $parts ) ) >= 2 ) {
				$out[ $name ] = $codes;
			}
			if ( strlen( $parts[0] ) >= 3 ) {
				$first[ $parts[0] ][ $name ] = $codes;
			}
		}
		foreach ( $first as $fn => $people ) {
			if ( 1 === count( $people ) ) {
				$out[ $fn ] = reset( $people );
			}
		}
		uksort( $out, function ( $a, $b ) { return strlen( $b ) <=> strlen( $a ); } );
		return $out;
	}

	/** Town labels the ledger has invoices in, longest first: key => label. */
	public static function cities(): array {
		$raw = is_array( self::$cities ) ? self::$cities : self::distinct( 'city' );
		$out = array();
		foreach ( $raw as $c ) {
			$l = ZANA_Ledger_Figures::city_label( (string) $c );
			if ( strlen( $l ) >= 3 && preg_match( "/^[\\p{L}][\\p{L} .'-]*$/u", $l ) && ! isset( $out[ strtolower( $l ) ] ) ) {
				$out[ strtolower( $l ) ] = $l;
			}
		}
		uksort( $out, function ( $a, $b ) { return strlen( $b ) <=> strlen( $a ); } );
		return $out;
	}

	/** Postal codes the ledger holds. */
	public static function zips(): array {
		return is_array( self::$zips ) ? self::$zips : self::distinct( 'zip' );
	}

	/** Distinct non-empty values of an invoice column (kept an hour). */
	protected static function distinct( string $col ): array {
		$key    = 'zana_ledger_distinct_' . $col;
		$cached = function_exists( 'get_transient' ) ? get_transient( $key ) : false;
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$out = array();
		if ( is_object( $wpdb ) && in_array( $col, array( 'city', 'zip' ), true ) ) {
			$out = array_values( array_filter( array_map( 'strval', (array) $wpdb->get_col( 'SELECT DISTINCT ' . $col . ' FROM ' . ZANA_Ledger_Store::table( 'invoices' ) . " WHERE {$col} <> '' AND vis_state <> 1 LIMIT 3000" ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- fixed names.
		}
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $key, $out, HOUR_IN_SECONDS );
		}
		return $out;
	}

	/** Product-line phrases (the catalog's item names and aliases) => the product line the ledger's mix files them under. */
	public static function categories(): array {
		if ( is_array( self::$categories ) ) {
			return self::$categories;
		}
		$out = array();
		if ( class_exists( 'ZDZ_Item_Engine' ) && ! ZDZ_Item_Engine::is_empty() ) {
			foreach ( (array) ZDZ_Item_Engine::all() as $item ) {
				$cat = ZANA_Ledger_Enrich::category( (string) ( $item['name'] ?? '' ) );
				$n   = ZANA_Ledger_Rules::phrase( $item['name'] ?? '' );
				if ( '' !== $cat && '' !== $n && strlen( $n ) >= 3 ) {
					$out[ $n ] = $cat;
				}
			}
			foreach ( (array) ZDZ_Item_Engine::aliases() as $alias => $id ) {
				$item = ZDZ_Item_Engine::get( $id );
				$cat  = is_array( $item ) ? ZANA_Ledger_Enrich::category( (string) ( $item['name'] ?? '' ) ) : '';
				$a    = ZANA_Ledger_Rules::phrase( $alias );
				if ( '' !== $cat && '' !== $a && strlen( $a ) >= 3 && ! isset( $out[ $a ] ) ) {
					$out[ $a ] = $cat;
				}
			}
		}
		uksort( $out, function ( $a, $b ) { return strlen( $b ) <=> strlen( $a ); } );
		self::$categories = $out;
		return $out;
	}

	/** Words that name things counted in units ("how many <things>"): the trade's words and a few generic ones. */
	protected static function unit_words(): string {
		$w = array( 'units?', 'pieces?', 'items?', 'panels?', 'rolls?', 'parts?' );
		if ( class_exists( 'ZDZ_Ask_Phrases' ) && method_exists( 'ZDZ_Ask_Phrases', 'trade_words' ) ) {
			foreach ( ZDZ_Ask_Phrases::trade_words() as $t ) {
				$w[] = preg_quote( (string) $t, '/' ) . '(?:s|es)?';
			}
		}
		return implode( '|', $w );
	}

	/* ═══════════════════════════ recognising the question ═══════════════════════════ */

	public static function norm( string $message ): string {
		$m = ZANA_Ledger_Rules::fold( $message );
		$m = (string) preg_replace( '/[?!]+/', ' ', $m );
		$m = (string) preg_replace( '/\b(year|month|quarter|week)-(to|over)-(date|year|month|quarter|week)\b/', '$1 $2 $3', $m );
		$m = (string) preg_replace( '/\b(estimate|quote)-to-(invoice|close|job)\b/', '$1 to $2', $m );
		$m = (string) preg_replace( '/\baccounts?-receivables?\b/', 'accounts receivable', $m );
		$m = (string) preg_replace( '/\(?\s*(?:current|0-30)\s*\/\s*31-60\s*\/\s*61-90\s*\/\s*(?:90\+|91\+|over 90)\s*\)?/', ' by age ', $m );
		$m = (string) preg_replace( '/\b(this|last|next|previous|prior|current) (quarter|month|week|year)\'s\b/', '$1 $2', $m );
		return trim( (string) preg_replace( '/\s+/', ' ', $m ) );
	}

	protected static function cut( string &$m, string $rx ): int {
		$n = 0;
		$m = (string) preg_replace( $rx, ' ', $m, -1, $n );
		return (int) $n;
	}

	protected static function paid_cut( string $m ): string {
		foreach ( self::PAY_CUTS as $rx ) {
			$m = (string) preg_replace( $rx, ' ', $m );
		}
		return $m;
	}

	/** "this quarter" / "last quarter" / "qtd" as the quarter they name. */
	protected static function quarter_words( string $rest ): string {
		$today = ZANA_Ledger_Periods::today();
		$y     = (int) substr( $today, 0, 4 );
		$q     = (int) ceil( (int) substr( $today, 5, 2 ) / 3 );
		$pq    = $q > 1 ? $q - 1 : 4;
		$py    = $q > 1 ? $y : $y - 1;
		$rest  = (string) preg_replace( '/\b(?:this|the current|current) quarter\b|\bquarter to date\b|\bqtd\b/', ' q' . $q . ' ' . $y . ' ', $rest );
		return (string) preg_replace( '/\b(?:last|previous|prior) quarter\b/', ' q' . $pq . ' ' . $py . ' ', $rest );
	}

	/** The family the question is about: sales | estimates | owed | paid | customers | '' | 'none'. */
	public static function family_of( string $m ): string {
		$owed = (bool) preg_match( '/\b(?:owed|owing|outstanding|receivables?|unpaid|a\/r|balances? due|money (?:still )?due|(?:open|pending|unpaid) invoices?|overdue|past[- ]due)\b|\bowes? us\b|\bstill owes?\b|\binvoices? (?:that are |are |is |still )*(?:open|unpaid|outstanding|pending|due)\b|\b(?:what|how much)(?:\'s| is)? (?:coming )?due\b/', $m );
		if ( preg_match( self::PAY_DAYS, $m ) || preg_match( self::PAY_FULL, $m ) ) {
			if ( preg_match( self::WE_PAY, $m ) || preg_match( self::GOT_PAID_FOR, $m ) ) {
				return 'none';
			}
			if ( preg_match( '/\b(?:estimates?|quotes?|bids?|proposals?)\b/', $m )
				|| ( ! preg_match( self::PAY_DAYS, $m ) && preg_match( '/\b(?:how many|number of|count(?: of)?)\s+(?:(?!days?\b)[a-z\']+\s+){0,2}?(?:customers?|clients?|people|accounts?|homeowners?|households?)\b(?!\')/', $m ) ) ) {
				return 'none';
			}
			return $owed ? 'none' : 'paid';
		}
		if ( $owed ) {
			return 'owed';
		}
		if ( preg_match( '/\b(?:estimates?|quotes?|bids?|proposals?)\b/', $m ) ) {
			return 'estimates';
		}
		if ( preg_match( '/\b(?:customers?|clients?)\b/', $m ) && ! preg_match( self::GROUP_WORDS['customer'], $m )
			&& preg_match( '/\b(?:how many|number of|count|new|repeat|returning|unique|distinct|different|first[- ]time)\b/', $m ) ) {
			if ( preg_match( '/\b(?:sales|sold|revenue|revenues|billed|income|spent|spend|money|dollars|worth|value)\b/', $m ) ) {
				return 'none'; // the revenue of new customers is not this answer
			}
			return 'customers';
		}
		if ( preg_match( '/\b(?:sales|sold|sell|selling|revenue|revenues|billed|billing|invoiced|invoices?|jobs?|income|gross|ticket|tickets|bought|spent)\b/', $m )
			|| preg_match( '/\bhow much (?:did|have|has|do) (?:we|i|he|she|they|[a-z]+) (?:make|made|bring in|brought in|bill|billed|do|done)\b/', $m )
			|| preg_match( self::GROUP_WORDS['customer'], $m ) ) {
			return 'sales';
		}
		return '';
	}

	/** A question this reader must leave alone (another meaning, a figure the ledger does not hold, an action). */
	protected static function excluded( string $m ): bool {
		if ( preg_match( '/\b(?:biggest|largest|highest|lowest|smallest|best|worst|top(?: \d{1,2})?)\s+(?:\w+\s+)?(?:jobs?|invoices?|sales?|estimates?|quotes?|tickets?|deals?|orders?)\b/', $m ) ) {
			return true; // one job, not a sum
		}
		if ( preg_match( '/\bhow many\b/', $m ) && preg_match( '/\b(?:' . self::unit_words() . ')\b(?![\s-]*(?:jobs?|invoices?|sales|orders?)\b)/', $m ) ) {
			return true; // units, not invoices
		}
		if ( preg_match( '/\b(?:up|down|higher|lower|more|less|better|worse|grow(?:ing|n)?|shrink(?:ing)?|declin(?:e|ed|ing)|chang(?:e|ed|ing))\s*(?:this|last|from|than|or|compared|vs|versus|since|over|$)|\b(?:go|went|gone|going) (?:up|down)\b/', (string) preg_replace( '/\bbreak(?:ing)?\b.{0,30}?\bdown\b|\btied up\b|\bmore than\b/', ' ', $m ) ) ) {
			return true;
		}
		if ( preg_match( '/\b(?:average|avg|typical(?:ly)?)\b.*\bper (?:day|week|month|year)\b|\b(?:day|days) of the week\b|\bweekdays?\b|\b(?:best|worst|busiest|slowest|biggest|top) (?:day|week|month|year|season)s?\b/', $m ) ) {
			return true;
		}
		return (bool) preg_match( '/\b(?:leads?|commissions?|profit|profits|margins?|costs?|cogs|markup|expenses?|labor|hours|paid|pay|payments?|collect(?:ed|ions?)?|deposits?|refunds?|credits?|tax|taxes|budget|goals?|targets?|quotas?|forecast|predict|projected|projection|pace|run[- ]?rate|on track|why|should|could|would|chart|graph|plot|map|email|e-mail|draft|drafts|text|call|create|add|update|delete|remove|assign|schedule|scheduled|tomorrow|install(?:ed|s|ation)?|crew|weather|rain|names?|details?|contact|phone|address|line items?|not|without|except|excluding|exclude|other than|besides)\b|\b(?:over|under|above|below|more than|less than|at least|at most|greater than|bigger than|smaller than)\s+\d|[<>$%#]|\b\d{1,3}(?:,\d{3})+\b|\b\d+k\b/', $m );
	}

	protected static function compare_cut( string &$m ): ?array {
		if ( self::cut( $m, '/\b(?:vs\.?|versus|compared?(?: to| with)?|against)\s+(?:the\s+)?(?:same\s+(?:period|time|point|months?|month|quarter|week|dates?|stretch)\s+(?:in\s+|of\s+)?)?(?:last year|a year (?:ago|before|earlier)|the year before|previous year|prior year|year before)\b|\byear over year\b|\byoy\b|\bsame (?:period|time|point) (?:as )?last year\b/' ) ) {
			return array( 'kind' => 'year' );
		}
		if ( preg_match( '/\b(?:vs\.?|versus|compared?(?: to| with)?|against)\s+(?:the\s+)?(?:same (?:days|point) (?:of|in)\s+)?(?:last|previous|prior|the) (month|quarter|week)(?: before)?\b/', $m, $u ) ) {
			self::cut( $m, '/\b(?:vs\.?|versus|compared?(?: to| with)?|against)\s+(?:the\s+)?(?:same (?:days|point) (?:of|in)\s+)?(?:last|previous|prior|the) (?:month|quarter|week)(?: before)?\b/' );
			return array( 'kind' => $u[1] );
		}
		if ( self::cut( $m, '/\b(?:vs\.?|versus|compared?(?: to| with)?|against)\s+(?:the\s+)?(?:previous|prior|preceding|last)\s+(?:period|stretch|one)\b|\b(?:vs\.?|versus|compared?(?: to| with)?|against)\s+the\s+(?:period|stretch|one)\s+before\b/' ) ) {
			return array( 'kind' => 'period' );
		}
		return null;
	}

	protected static function two_years_cut( string &$m ): ?array {
		if ( preg_match( '/\b(20\d\d)\s*(?:vs\.?|versus|compared?(?: to| with)?|against|and|or)\s*(20\d\d)\b/', $m, $r ) && $r[1] !== $r[2] ) {
			if ( preg_match( '/\b(?:from|between)\s+' . $r[1] . '\s*(?:to|and|-)\s*' . $r[2] . '\b/', $m ) ) {
				return null; // a range
			}
			$m = str_replace( $r[0], ' ', $m );
			return array( (int) $r[1], (int) $r[2] );
		}
		return null;
	}

	/** "due next week / next month / in the next N days": a period ahead (only money owed by due date uses one). */
	protected static function next_period_cut( string &$rest ): ?array {
		$today = ZANA_Ledger_Periods::today();
		$t     = strtotime( $today . ' 12:00:00 UTC' );
		if ( self::cut( $rest, '/\b(?:for |in |during )?(?:the )?next week\b/' ) ) {
			$mon = $t + ( 8 - (int) gmdate( 'N', $t ) ) * 86400;
			return array( gmdate( 'Y-m-d', $mon ), gmdate( 'Y-m-d', $mon + 6 * 86400 ), 'next week' );
		}
		if ( self::cut( $rest, '/\b(?:for |in |during )?(?:the )?next month\b/' ) ) {
			$first = ZANA_Ledger_Periods::shift_months( substr( $today, 0, 8 ) . '01', 1 );
			return array( $first, ZANA_Ledger_Periods::month_end( $first ), ZANA_Ledger_Periods::month_name( $first ) );
		}
		$words = array( 'seven' => 7, 'ten' => 10, 'fourteen' => 14, 'thirty' => 30, 'sixty' => 60, 'ninety' => 90, 'two' => 2, 'three' => 3, 'five' => 5 );
		if ( preg_match( '/\b(?:in |over |within |during )?(?:the )?next (\d{1,3}|seven|ten|fourteen|thirty|sixty|ninety|two|three|five) (days?|weeks?)\b|\b(?:in |over |within )?(?:the )?next (two weeks)\b/', $rest, $mm ) ) {
			if ( ! empty( $mm[3] ) ) {
				$days = 14;
			} else {
				$n    = is_numeric( $mm[1] ) ? (int) $mm[1] : ( $words[ $mm[1] ] ?? 0 );
				$days = 0 === strpos( $mm[2], 'week' ) ? 7 * $n : $n;
			}
			if ( $days < 1 || $days > 366 ) {
				return null;
			}
			$rest = str_replace( $mm[0], ' ', $rest );
			return array( $today, gmdate( 'Y-m-d', $t + ( $days - 1 ) * 86400 ), 'the next ' . $days . ' days' );
		}
		return null;
	}

	/** What is asked within the family. */
	public static function ask_of( string $m, string $family ): array {
		$ask      = array();
		$how_many = (bool) preg_match( '/\bhow many\b|\bnumber of\b|\bcount\b/', $m );
		switch ( $family ) {
			case 'estimates':
				if ( preg_match( '/\bconver(?:sion|sions|t|ted|ting)\b|\bturned into\b|\bclose rate\b|\bclosing rate\b|\bwin rate\b|\bclosed\b|\bsigned\b|\bto invoice\b|\bto close\b/', $m ) ) {
					$ask[] = 'conversion';
				}
				if ( preg_match( '/\bopen\b|\bunsold\b|\bpending\b|\bawaiting\b|\bunconverted\b/', $m ) ) {
					$ask[] = 'open';
				}
				if ( preg_match( '/\baccepted\b/', $m ) ) {
					$ask[] = 'accepted';
				}
				if ( preg_match( '/\b(?:value|worth|dollars?|how much|amount|average|avg)\b/', $m ) ) {
					$ask[] = preg_match( '/\b(?:average|avg)\b/', $m ) ? 'average' : 'value';
				}
				if ( $how_many || preg_match( '/\b(?:sent|send|wrote|written|quoted|issued)\b/', $m ) ) {
					array_unshift( $ask, 'count' );
				}
				break;
			case 'owed':
				if ( preg_match( '/\boverdue\b|\bpast[- ]due\b|\blate\b/', $m ) ) {
					$ask[] = 'overdue';
				}
				if ( preg_match( '/\b(?:age|ages|aged|aging|old|older|days|breakdown|bucket|buckets)\b/', (string) preg_replace( '/\bnext \S+ (?:days?|weeks?)\b/', ' ', $m ) ) ) {
					$ask[] = 'ages';
				}
				if ( $how_many ) {
					$ask[] = 'count';
				}
				break;
			case 'paid':
				if ( preg_match( self::PAY_DAYS, $m ) ) {
					$ask[] = 'days';
				} elseif ( $how_many && ! preg_match( '/\bhow much\b|\b(?:dollars?|money|worth|value|amount)\b/', $m ) ) {
					$ask[] = 'count';
				} elseif ( $how_many ) {
					$ask = array( 'amount', 'count' );
				}
				break;
			case 'customers':
				if ( preg_match( '/\bnew\b|\bfirst[- ]time\b/', $m ) ) {
					$ask[] = 'new';
				}
				if ( preg_match( '/\brepeat\b|\breturning\b|\bcome back\b|\bagain\b/', $m ) ) {
					$ask[] = 'repeat';
				}
				break;
			default:
				if ( preg_match( '/\b(?:share|percent|percentage|portion|what part|split)\b/', $m ) ) {
					$ask[] = 'share';
				} elseif ( preg_match( '/\bper customer\b|\ba customer\b/', $m ) && preg_match( '/\b(?:average|avg|per)\b/', $m ) ) {
					$ask[] = 'per_customer';
				} elseif ( preg_match( '/\b(?:average|avg|ticket|typical|deal size|job size)\b/', $m ) ) {
					$ask[] = 'average';
				} elseif ( $how_many && ! preg_match( '/\bhow much\b|\b(?:sales|revenue|dollars?|money|billed)\b/', $m ) ) {
					$ask[] = 'count';
				} elseif ( $how_many ) {
					$ask = array( 'amount', 'count' );
				}
		}
		return array_values( array_unique( $ask ) );
	}

	/** "which invoices are overdue", "list unpaid invoices", "who owes us money": the list of open invoices, or null. */
	public static function detect_list( string $raw ): ?array {
		$m = self::norm( $raw );
		if ( '' === $m || strlen( $m ) > 200 ) {
			return null;
		}
		if ( ! preg_match( '/\b(?:which|what|list|show(?: me)?|who)\b/', $m ) || ! preg_match( '/\b(?:invoices?|owes?|owing)\b/', $m ) ) {
			return null;
		}
		$over = (bool) preg_match( '/\boverdue\b|\bpast[- ]due\b|\blate\b/', $m );
		if ( ! $over && ! preg_match( '/\b(?:unpaid|outstanding|open|owed|owing|owes?|still due)\b/', $m ) ) {
			return null;
		}
		$rest = ' ' . $m . ' ';
		self::cut( $rest, '/\b(?:which|what|list|show(?: me)?|who|all|the|of|our|are|is|still|invoices?|overdue|past[- ]due|late|unpaid|outstanding|open|owed|owing|owes?|us|money|due|me|please|currently|right now|now|longest|oldest|biggest|largest|first|by|days|amount)\b/' );
		$top = 0;
		if ( preg_match( '/\btop (\d{1,2})\b/', $m, $tn ) ) {
			$top = max( 1, min( self::LIST_ROWS, (int) $tn[1] ) );
			self::cut( $rest, '/\btop \d{1,2}\b/' );
		}
		if ( '' !== trim( $rest ) ) {
			return null; // a name, a period, a filter: the planner's
		}
		return ZANA_Ledger_Figures::filters( array(
			'family' => 'owed',
			'list'   => array( 'overdue' => $over, 'sort' => preg_match( '/\b(?:longest|oldest|days)\b/', $m ) ? 'days' : 'amount' ),
			'top'    => $top,
			'period' => null, 'compare' => null, 'years' => null, 'mine' => false, 'all' => false,
		) );
	}

	/**
	 * Is this a question this reader answers? The filters, or null.
	 * @return array|null { family, period, compare, years, reps, mine, brand, category, city, zip, source, group, top, ask, ... }
	 */
	public static function detect( string $raw ): ?array {
		if ( '' === trim( $raw ) || strlen( $raw ) > 400 ) {
			return null;
		}
		$list = self::detect_list( $raw );
		if ( null !== $list ) {
			return $list;
		}
		$m = self::norm( $raw );
		if ( '' === $m || strlen( $m ) > 300 ) {
			return null;
		}
		$family = self::family_of( $m ); // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.famRemoved -- family_of() is this class's own method, not the removed fam extension
		if ( '' === $family || 'none' === $family ) {
			return null;
		}
		if ( self::excluded( 'paid' === $family ? self::paid_cut( $m ) : $m ) ) {
			return null;
		}
		$f = ZANA_Ledger_Figures::filters( array(
			'family'   => $family,
			'period'   => null,
			'compare'  => null,
			'years'    => null,
			'mine'     => false,
			'all'      => false,
			'ask'      => self::ask_of( $m, $family ),
			'open_ask' => (bool) preg_match( '/\b(?:open|unsold|pending|awaiting|right now|currently|still)\b/', $m ),
			'now_ask'  => (bool) preg_match( '/\b(?:so far|right now|now|currently|at the moment)\b/', $m ),
			'due_ask'  => (bool) preg_match( '/(?<!past )(?<!past-)\bdue\b/', $m ),
			'due_next' => null,
			'due_ext'  => false,
			'jobs_ask' => (bool) preg_match( '/\bjobs?\b/', $m ) && ! preg_match( '/\b(?:sales|sold|revenue|billed|invoiced|invoices?|worth|value|dollars|money)\b/', $m ),
		) );
		$rest = ' ' . (string) preg_replace( '/\bbreak(?:ing)?\b(.{0,30}?)\bdown\b/', ' breakdown $1 ', $m ) . ' ';

		foreach ( self::GROUP_WORDS as $g => $rx ) {
			if ( preg_match( $rx, $rest ) ) {
				if ( '' !== $f['group'] ) {
					return null; // two groupings
				}
				$f['group'] = $g;
				self::cut( $rest, $rx );
			}
		}
		if ( preg_match( '/\btop (\d{1,2})\b/', $m, $tn ) ) {
			$f['top'] = max( 1, min( self::TABLE_ROWS, (int) $tn[1] ) );
		}
		self::cut( $rest, '/\btop \d{1,2}\b/' );

		// salespeople: names, then codes typed in capitals, then "my"
		foreach ( self::rep_phrases() as $phrase => $codes ) {
			if ( self::cut( $rest, '/\b' . preg_quote( $phrase, '/' ) . '(?:\'s|s\')?(?=\s|$)/' ) ) {
				$f['reps'] = array_values( array_unique( array_merge( $f['reps'], $codes ) ) );
			}
		}
		$letters = preg_replace( '/[^a-z]/i', '', $raw );
		$caps    = strlen( $letters ) > 0 && strlen( preg_replace( '/[^A-Z]/', '', $raw ) ) / strlen( $letters ) > 0.5;
		if ( ! $caps ) {
			foreach ( array_keys( ZANA_Ledger_Access::rep_map() ) as $code ) {
				$code = strtoupper( (string) $code );
				if ( strlen( $code ) >= 2 && preg_match( '/(?<![A-Za-z])' . preg_quote( $code, '/' ) . '(?:\'s)?(?![A-Za-z])/', $raw ) ) {
					$f['reps'][] = $code;
					self::cut( $rest, '/\b' . preg_quote( strtolower( $code ), '/' ) . '(?:\'s)?(?=\s|$)/' );
				}
			}
			$f['reps'] = array_values( array_unique( $f['reps'] ) );
		}
		$i_sell = '(?:sold|sell|closed|close|billed|bill|made|make|brought|bring|done|do|invoiced|invoice|written|write|wrote|sent|send|quoted|quote|got|get)';
		if ( self::cut( $rest, '/\bmy\b|\bmine\b/' ) | self::cut( $rest, '/\b(?:did|have|has|had|do)\s+i\s+' . $i_sell . '\b|\bi(?:\'ve|\s+have)?\s+' . $i_sell . '\b/' ) ) {
			$f['mine'] = true;
		}

		// towns before brands and periods
		foreach ( self::cities() as $key => $label ) {
			$rx = '/\b(?:in |for |from |around )?' . preg_quote( $key, '/' ) . '(?:\'s)?(?=\s|$)/';
			if ( preg_match( $rx, $rest ) ) {
				if ( '' !== $f['city'] ) {
					return null; // two towns
				}
				$f['city'] = $label;
				self::cut( $rest, $rx );
			}
		}
		// a postal code the ledger holds
		if ( preg_match_all( '/\b(\d{5,10})\b/', $rest, $zr ) ) {
			$zips = array_intersect( array_unique( $zr[1] ), self::zips() );
			if ( count( $zips ) > 1 ) {
				return null;
			}
			if ( $zips ) {
				$f['zip'] = (string) reset( $zips );
				self::cut( $rest, '/\b(?:in |for )?(?:zip(?: code)? |postcode |postal code )?' . preg_quote( $f['zip'], '/' ) . '\b/' );
			}
		}

		// a brand, else a product line
		foreach ( (array) ZANA_Ledger_Rules::get()['brands'] as $label => $words ) {
			foreach ( $words as $w ) {
				if ( ZANA_Ledger_Rules::has_phrase( $rest, $w ) ) {
					if ( '' !== $f['brand'] && $f['brand'] !== $label ) {
						return null; // two brands
					}
					$f['brand'] = $label;
					$rest       = (string) preg_replace( '/(?<![\p{L}\p{N}])' . preg_quote( $w, '/' ) . '(?:\'s)?(?![\p{L}\p{N}])/u', ' ', $rest );
				}
			}
		}
		foreach ( self::categories() as $phrase => $label ) {
			if ( ZANA_Ledger_Rules::has_phrase( $rest, $phrase ) ) {
				if ( ( '' !== $f['category'] && $f['category'] !== $label ) || '' !== $f['brand'] ) {
					return null; // two lines, or a brand and a line
				}
				$f['category'] = $label;
				$rest          = (string) preg_replace( '/(?<![\p{L}\p{N}])' . preg_quote( $phrase, '/' ) . '(?:s|es)?(?![\p{L}\p{N}])/u', ' ', $rest );
			}
		}

		// a lead-source group the billing system tags
		$groups = ZANA_Lead_Sources::groups_in( $rest );
		if ( count( $groups ) > 1 ) {
			return null;
		}
		if ( $groups ) {
			$g = ZANA_Lead_Sources::group( $groups[0] );
			if ( ! $g || ! $g['billing'] ) {
				return null; // a source the billing system does not tag
			}
			$f['source'] = $groups[0];
			foreach ( $g['words'] as $w ) {
				$rest = (string) preg_replace( '/(?<![\p{L}\p{N}])' . preg_quote( $w, '/' ) . '(?![\p{L}\p{N}])/u', ' ', $rest );
			}
			self::cut( $rest, '/\b(?:jobs|customers|sales|revenue) (?:from|via|through)\b|\b(?:lead )?source\b/' );
		}

		if ( 'paid' === $family ) {
			$rest = self::paid_cut( $rest );
		}
		if ( 'owed' === $family ) {
			$f['due_next'] = self::next_period_cut( $rest );
		}

		$f['compare'] = self::compare_cut( $rest );
		$f['years']   = self::two_years_cut( $rest );
		if ( $f['years'] && $f['compare'] ) {
			return null;
		}
		if ( self::cut( $rest, '/\ball[- ]time\b|\bever\b|\bin total\b|\bsince (?:we )?(?:started|the beginning)\b/' ) ) {
			$f['all'] = true;
		}
		$rest   = self::quarter_words( $rest );
		$period = ZANA_Ledger_Periods::period_in( trim( $rest ) );
		if ( false === $period ) {
			return null;
		}
		if ( $f['years'] && null !== $period ) {
			return null;
		}
		if ( $f['due_next'] ) {
			if ( null !== $period || $f['years'] || $f['compare'] || $f['all'] ) {
				return null;
			}
			$period = $f['due_next'];
		}
		$f['period'] = $period;
		$rest        = self::strip_period_words( $rest );
		if ( self::leftover( $rest, $family, $f['group'] ) ) {
			return null;
		}
		$asks = (bool) preg_match( '/\b(?:how much|how many|what|what\'s|whats|total|totals|number of|count|show|tell|give|breakdown|break down|compare|average|avg|top|which|who|where|did|do|does|have|has)\b/', $m );
		if ( ! $asks && str_word_count( $m ) > 10 ) {
			return null;
		}
		return self::settle( $f );
	}

	/** Checks across the filters; null when the combination is not one this reader answers. */
	protected static function settle( array $f ): ?array {
		if ( $f['due_next'] && ! ( 'owed' === $f['family'] && $f['due_ask'] ) ) {
			return null;
		}
		// "due this week / this month": the WHOLE week or month; a period already over is not a due-date question
		if ( 'owed' === $f['family'] && $f['due_ask'] && is_array( $f['period'] ) && ! $f['due_next'] ) {
			$p   = $f['period'];
			$lab = (string) $p[2];
			if ( 'today' === $lab ) {
				$p[2] = 'today';
			} elseif ( 0 === strpos( $lab, 'this week' ) ) {
				$p = array( $p[0], gmdate( 'Y-m-d', strtotime( $p[0] . ' 12:00:00 UTC' ) + 6 * 86400 ), 'this week' );
			} elseif ( preg_match( '/^([A-Z][a-z]+ 20\d\d) so far$/', $lab, $mm ) ) {
				$p = array( $p[0], ZANA_Ledger_Periods::month_end( $p[0] ), $mm[1] );
			} elseif ( preg_match( '/^Q([1-4]) (20\d\d) so far$/', $lab, $mm ) ) {
				$p = array( $p[0], ZANA_Ledger_Periods::month_end( sprintf( '%s-%02d-01', $mm[2], 3 * (int) $mm[1] ) ), 'Q' . $mm[1] . ' ' . $mm[2] );
			} elseif ( preg_match( '/^(20\d\d) so far$/', $lab, $mm ) ) {
				$p = array( $p[0], $mm[1] . '-12-31', $mm[1] );
			} else {
				return null;
			}
			$f['period']  = $p;
			$f['due_ext'] = true;
		}
		if ( 'estimates' === $f['family'] && '' !== $f['zip'] ) {
			return null; // estimates carry no postal code
		}
		if ( 'owed' !== $f['family'] && $f['now_ask'] && null === $f['period'] && ! $f['years'] && ! $f['compare'] ) {
			return null;
		}
		if ( $f['due_ask'] && 'owed' !== $f['family'] ) {
			return null;
		}
		if ( 'sales' === $f['family'] && $f['jobs_ask'] && is_array( $f['period'] ) && preg_match( '/^(?:today|yesterday|this week|last week)/', (string) $f['period'][2] ) ) {
			return null; // "how many jobs today" is the schedule
		}
		if ( $f['compare'] && is_array( $f['period'] ) ) {
			$p     = $f['period'];
			$days  = (int) round( ( strtotime( $p[1] . ' 12:00:00 UTC' ) - strtotime( $p[0] . ' 12:00:00 UTC' ) ) / 86400 ) + 1;
			$unit  = $f['compare']['kind'];
			$today = ZANA_Ledger_Periods::today();
			if ( 'month' === $unit && ! ( '01' === substr( $p[0], 8, 2 ) && substr( $p[0], 0, 7 ) === substr( $p[1], 0, 7 )
				&& ( $p[1] === ZANA_Ledger_Periods::month_end( $p[0] ) || $p[1] === $today ) ) ) {
				return null;
			}
			if ( 'quarter' === $unit ) {
				$qn = (int) ceil( (int) substr( $p[0], 5, 2 ) / 3 );
				$qs = sprintf( '%s-%02d-01', substr( $p[0], 0, 4 ), 3 * $qn - 2 );
				$qe = ZANA_Ledger_Periods::month_end( sprintf( '%s-%02d-01', substr( $p[0], 0, 4 ), 3 * $qn ) );
				if ( $p[0] !== $qs || ! ( $p[1] === $qe || ( $p[1] === $today && $today <= $qe ) ) ) {
					return null;
				}
			}
			if ( 'week' === $unit && ! ( 1 === (int) gmdate( 'N', strtotime( $p[0] . ' 12:00:00 UTC' ) ) && ( 7 === $days || ( $p[1] === $today && $days <= 7 ) ) ) ) {
				return null;
			}
			if ( 'period' === $unit && $days > 400 ) {
				return null;
			}
			if ( 'year' === $unit && substr( $p[0], 0, 4 ) < substr( $today, 0, 4 ) ) {
				return null; // "2024 vs last year": which year is "last" is unclear
			}
		}
		if ( 'month' === $f['group'] && ( $f['compare'] || $f['years'] ) && is_array( $f['period'] ) ) {
			$span = 12 * ( (int) substr( $f['period'][1], 0, 4 ) - (int) substr( $f['period'][0], 0, 4 ) ) + (int) substr( $f['period'][1], 5, 2 ) - (int) substr( $f['period'][0], 5, 2 ) + 1;
			if ( $span > self::TABLE_ROWS ) {
				return null;
			}
		}
		if ( $f['all'] && ( $f['compare'] || $f['years'] ) ) {
			return null;
		}
		if ( 'owed' === $f['family'] ) {
			if ( null !== $f['period'] && in_array( 'overdue', $f['ask'], true ) ) {
				if ( is_array( $f['period'] ) && 'today' === (string) $f['period'][2] && ! $f['due_ask'] && ! $f['due_next'] && ! $f['compare'] && ! $f['years'] ) {
					$f['period'] = null; // overdue today = overdue
				} else {
					return null;
				}
			}
			if ( $f['compare'] || $f['years'] || in_array( $f['group'], array( 'month', 'year', 'category', 'brand' ), true ) || '' !== $f['category'] || '' !== $f['brand'] ) {
				return null;
			}
		}
		if ( 'customers' === $f['family'] && ( '' !== $f['group'] || $f['compare'] || $f['years'] ) ) {
			return null;
		}
		if ( 'paid' === $f['family'] && ( '' !== $f['brand'] || '' !== $f['category'] || in_array( $f['group'], array( 'category', 'brand', 'customer' ), true ) ) ) {
			return null;
		}
		if ( 'paid' === $f['family'] && in_array( 'days', $f['ask'], true ) && $f['top'] ) {
			return null;
		}
		if ( 'estimates' === $f['family'] && 'customer' === $f['group'] ) {
			return null;
		}
		if ( 'estimates' === $f['family'] && null === $f['period'] && ! $f['years'] && ! empty( $f['open_ask'] ) ) {
			return null; // years-old quotes would count as open
		}
		if ( ( '' !== $f['brand'] || '' !== $f['category'] ) && in_array( $f['group'], array( 'category', 'brand' ), true ) ) {
			return null;
		}
		if ( ( $f['compare'] || $f['years'] ) && ! in_array( $f['group'], array( '', 'month' ), true ) ) {
			return null;
		}
		if ( $f['top'] && '' === $f['group'] ) {
			return null;
		}
		if ( count( $f['reps'] ) > 1 && '' === $f['group'] ) {
			$f['group'] = 'rep';
		}
		if ( $f['mine'] && $f['reps'] ) {
			return null;
		}
		return $f;
	}

	protected static function strip_period_words( string $rest ): string {
		$mrx   = implode( '|', array_keys( ZANA_Ledger_Periods::MONTHS ) );
		$nums  = '\d{1,3}|one|two|three|four|five|six|seven|eight|nine|ten|twelve|eighteen|thirty|ninety';
		$strip = array(
			'/\b20\d\d\s*(?:-|–|to|through|thru)\s*20\d\d\b/',
			'/\b(?:since|from|after)\s+20\d\d(?:\s+(?:on|onwards?|forward|to (?:now|today|date)))?\b/',
			'/\b(?:in |over |during |for |within )?(?:the )?(?:last|past|previous)\s+(?:' . $nums . ')\s+(?:days?|weeks?|months?|years?)\b/',
			'/\b(?:in |over |during |for )?(?:the |this )?past (?:week|month|year)\b/',
			'/\b(?:q[1-4]|(?:the )?(?:first|second|third|fourth) quarter)(?:\s+(?:of\s+)?20\d\d)?\b/',
			'/\b(?:since\s+)?(?:' . $mrx . ')\b(?:\s+(?:of\s+)?20\d\d)?/',
			'/\b(?:so far )?(?:this year|ytd|year to date)(?: so far)?\b/',
			'/\b(?:last|previous) (?:year|month|week)\b|\bthis (?:month|week)\b|\bmonth to date\b|\bmtd\b|\btoday\b|\byesterday\b|\bso far\b/',
			'/\b20\d\d\b/',
		);
		foreach ( $strip as $rx ) {
			$rest = (string) preg_replace( $rx, ' ', $rest );
		}
		return $rest;
	}

	/** The words left after every reading is taken out that this reader does not know. */
	public static function leftover( string $rest, string $family = '', string $group = '' ): array {
		$ok  = '/^(?:how|much|many|number|numbers|of|count|counts|total|totals|tally|sum|what|what\'s|whats|was|were|is|are|be|been|our|we|us|you|did|do|does|done|have|has|had|the|a|an|in|for|from|on|at|to|by|with|and|or|all|overall|altogether|so|far|sales|sale|sold|sell|selling|revenue|revenues|billed|billing|bill|invoiced|invoice|invoices|job|jobs|average|avg|ticket|tickets|size|sizes|value|worth|gross|income|money|dollars|make|made|bring|brought|get|got|give|show|tell|can|please|it|there|this|that|those|these|estimate|estimates|quote|quotes|bid|bids|proposal|proposals|customer|customers|client|clients|account|accounts|each|every|per|breakdown|break|period|ago|across|me|i|know|need|want|see|look|looking|like|about|just|only|also|then|again|instead|same|ones|one|any|some|business|company|happened|during|over|within|throughout|through|we\'ve|weve|amount|figure|figures|report|summary|stats|out|invoicing|brand|brands|whole|entire|bought|buy|buying|spent|spend|spending|share|percent|percentage|portion|split|came|come|comes|coming|generated|generate|issue|dollar|deal|deals|trend|trends|trended|trending|together|combined|how\'s|hows|doing|ok|okay|but|now|hey|well|alright)$/';
		$fam = array(
			'owed'      => 'up|cash|tied|aging|aged|age|ages|late|overall|accounts?|open|pending|still|right|now|currently|due|owe|owed|owing|owes|outstanding|receivables?|unpaid|balances?|overdue|past|coming|falls?|fall|anything|any',
			'paid'      => 'take|takes|took|taking|usually|typically|generally|normally|days|day|long|fast|quickly|soon|full|off|up|average|avg|median|mean|typical|customers?|clients?|people|they|them|us',
			'estimates' => 'open|pending|still|right|now|currently|accepted|won|converted|conversions?|convert|rates?|close|closed|sent|send|written|wrote|quoted|unsold|unconverted|signed|turned|into',
			'customers' => 'unique|distinct|different|first|time|first-time|new|repeat|returning',
			'sales'     => 'closed|close|issued|sent',
		);
		$x = $fam[ $family ] ?? '';
		if ( '' !== $group ) {
			$x .= ( '' !== $x ? '|' : '' ) . 'best|biggest|largest|most|which|who|where|top|highest';
		}
		$extra = '' !== $x ? '/^(?:' . $x . ')$/' : '/^$/';
		$left  = array();
		foreach ( preg_split( '/[^\p{L}\p{N}\'-]+/u', $rest, -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
			$w = trim( $w, "'-" );
			if ( '' !== $w && ! preg_match( $ok, $w ) && ! preg_match( $extra, $w ) ) {
				$left[] = $w;
			}
		}
		return $left;
	}

	/* ═══════════════════════════ who, and whether the ledger can ═══════════════════════════ */

	/** Is the billing copy complete and synced recently (invoices and estimates)? */
	public static function ready(): bool {
		if ( null !== self::$ready ) {
			return (bool) self::$ready;
		}
		try {
			$cov = ZANA_Ledger::coverage();
		} catch ( \Throwable $e ) {
			return false;
		}
		foreach ( array( 'invoices', 'estimates' ) as $k ) {
			$t = strtotime( (string) ( $cov[ $k ]['last_ok'] ?? '' ) . ' UTC' );
			if ( empty( $cov[ $k ]['complete'] ) || (int) ( $cov[ $k ]['rows'] ?? 0 ) <= 0 || ! $t || $t < time() - self::FRESH_HOURS * 3600 ) {
				return false;
			}
		}
		return true;
	}

	/** Are the stored labels being re-derived (a brand, product-line or date answer waits)? */
	public static function relabelling(): bool {
		if ( null !== self::$relabelling ) {
			return (bool) self::$relabelling;
		}
		try {
			return ZANA_Ledger::relabel_pending( ZANA_Ledger::state() );
		} catch ( \Throwable $e ) {
			return true;
		}
	}

	/**
	 * Everything that makes a question answerable for this person now, or null (the chat's planner answers): who may,
	 * the ledger's state, "my" resolved to the asker's codes.
	 */
	public static function resolve( string $message, int $uid ): ?array {
		$ctx = ZANA_Ledger_Access::context( $uid );
		if ( ! ZANA_Ledger_Access::may( $ctx, 'company' ) ) {
			return null;
		}
		$f = self::detect( $message );
		if ( null === $f || ! self::ready() ) {
			return null;
		}
		if ( ! empty( $f['list'] ) && ! ZANA_Ledger_Access::may( $ctx, 'list' ) ) {
			return null; // a list names customers
		}
		if ( 'customer' === $f['group'] && ! ZANA_Ledger_Access::may( $ctx, 'list' ) ) {
			return null;
		}
		if ( $f['mine'] ) {
			if ( ! $ctx['codes'] ) {
				return null; // no salesperson code on this account
			}
			$f['reps'] = $ctx['codes'];
		}
		$relabel    = self::relabelling();
		$f['dates'] = ! $relabel;
		if ( $relabel && ( '' !== $f['brand'] || '' !== $f['category'] || in_array( $f['group'], array( 'brand', 'category', 'source' ), true ) || '' !== $f['source'] ) ) {
			return null; // the stored labels are being re-derived
		}
		if ( $relabel && ( 'paid' === $f['family'] || ( 'owed' === $f['family'] && ( in_array( 'overdue', $f['ask'], true ) || $f['due_ask'] || ! empty( $f['list'] ) ) ) ) ) {
			return null; // due and paid dates may be missing until it is done
		}
		return $f;
	}

	/* ═══════════════════════════ the answer ═══════════════════════════ */

	protected static function money( $v ): string {
		return '$' . number_format( (float) $v, 2 );
	}

	protected static function round_money( $v ): string {
		$v = (float) $v;
		return ( $v < 0 ? '−' : '' ) . '$' . number_format( abs( round( $v ) ) );
	}

	protected static function n( $v ): string {
		return number_format( (int) $v );
	}

	protected static function plural( $n, string $one, string $many ): string {
		return self::n( $n ) . ' ' . ( 1 === (int) $n ? $one : $many );
	}

	protected static function pct( $a, $b ): string {
		return $b > 0 ? round( 100 * $a / $b ) . '%' : 'n/a';
	}

	protected static function change( $a, $b ): string {
		if ( $b <= 0 ) {
			return $a > 0 ? 'new' : 'no change';
		}
		$d = round( 100 * ( $a - $b ) / $b );
		return ( $d > 0 ? '+' : ( $d < 0 ? '−' : '±' ) ) . abs( $d ) . '%';
	}

	protected static function cell( $s ): string {
		$t = (string) preg_replace( '/[<>\[\]*_`~\\\\|]/', '', (string) $s );
		$t = (string) preg_replace( '#\b(?:https?|ftp)://#i', '', $t );
		return trim( (string) preg_replace( '/\s+/', ' ', $t ) );
	}

	/** The periods an answer is about: one, or two for a comparison (the first asked about). null = all time. */
	public static function periods( array $f ): array {
		$today = ZANA_Ledger_Periods::today();
		if ( ! empty( $f['years'] ) ) {
			$out = array();
			foreach ( $f['years'] as $y ) {
				$out[] = array( $y . '-01-01', $y . '-12-31', (string) $y );
			}
			$cy = (int) substr( $today, 0, 4 );
			if ( in_array( $cy, $f['years'], true ) ) {
				foreach ( $out as $i => $p ) {
					$y         = (int) substr( $p[0], 0, 4 );
					$to        = ZANA_Ledger_Periods::shift_year( $today, $y - $cy );
					$out[ $i ] = array( $p[0], $to, $y . ' to ' . gmdate( 'M j', strtotime( $to . ' 12:00:00 UTC' ) ) );
				}
			}
			return $out;
		}
		$p = $f['period'] ?? null;
		if ( null === $p && 'paid' === ( $f['family'] ?? '' ) && in_array( 'days', (array) ( $f['ask'] ?? array() ), true ) && empty( $f['all'] ) && empty( $f['compare'] ) ) {
			$p = array( ZANA_Ledger_Periods::back( $today, 12 ), $today, 'the last 12 months' );
		}
		if ( ! empty( $f['compare'] ) ) {
			$kind = $f['compare']['kind'];
			if ( null === $p ) {
				$y = (int) substr( $today, 0, 4 );
				if ( 'month' === $kind ) {
					$p = array( substr( $today, 0, 8 ) . '01', $today, ZANA_Ledger_Periods::month_name( $today ) . ' so far' );
				} elseif ( 'quarter' === $kind ) {
					$q = (int) ceil( (int) substr( $today, 5, 2 ) / 3 );
					$p = array( sprintf( '%04d-%02d-01', $y, 3 * $q - 2 ), $today, 'Q' . $q . ' ' . $y . ' so far' );
				} elseif ( 'week' === $kind ) {
					$dow = (int) gmdate( 'N', strtotime( $today . ' 12:00:00 UTC' ) );
					$p   = array( gmdate( 'Y-m-d', strtotime( $today . ' 12:00:00 UTC -' . ( $dow - 1 ) . ' days' ) ), $today, 'this week (from Monday)' );
				} else {
					$p = array( $y . '-01-01', $today, $y . ' so far' );
				}
			}
			if ( 'year' === $kind ) {
				$b = array( ZANA_Ledger_Periods::shift_year( $p[0], -1 ), ZANA_Ledger_Periods::shift_year( $p[1], -1 ), 'the same dates a year before' );
			} elseif ( in_array( $kind, array( 'month', 'quarter' ), true ) ) {
				$n     = 'month' === $kind ? 1 : 3;
				$from  = ZANA_Ledger_Periods::shift_months( $p[0], -$n );
				$whole = $p[1] === ZANA_Ledger_Periods::month_end( $p[1] ) && ( 'month' === $kind || 0 === (int) substr( $p[1], 5, 2 ) % 3 );
				$prev  = ZANA_Ledger_Periods::month_end( ZANA_Ledger_Periods::shift_months( substr( $p[1], 0, 8 ) . '01', -$n ) );
				$to    = $whole ? $prev : min( ZANA_Ledger_Periods::shift_months( $p[1], -$n ), $prev );
				$b     = array( $from, $to, 'the ' . $kind . ' before, same days' );
			} elseif ( 'week' === $kind ) {
				$b = array( gmdate( 'Y-m-d', strtotime( $p[0] . ' 12:00:00 UTC -7 days' ) ), gmdate( 'Y-m-d', strtotime( $p[1] . ' 12:00:00 UTC -7 days' ) ), 'the week before' );
			} else {
				$len = (int) round( ( strtotime( $p[1] . ' 12:00:00 UTC' ) - strtotime( $p[0] . ' 12:00:00 UTC' ) ) / 86400 ) + 1;
				$b   = array( gmdate( 'Y-m-d', strtotime( $p[0] . ' 12:00:00 UTC -' . $len . ' days' ) ), gmdate( 'Y-m-d', strtotime( $p[0] . ' 12:00:00 UTC -1 day' ) ), 'the ' . $len . ' days before' );
			}
			return array( $p, $b );
		}
		return array( $p );
	}

	/** What the answer is about: "Example brand sales · Alex (AB) · Riverton". */
	protected static function subject( array $f, string $noun ): string {
		$what = '' !== $f['brand'] ? $f['brand'] . ' ' . $noun : ( '' !== $f['category'] ? $f['category'] . ' ' . $noun : ucfirst( $noun ) );
		$bits = array( $what );
		if ( $f['reps'] && 'rep' !== $f['group'] ) {
			$bits[] = implode( ', ', array_map( array( 'ZANA_Ledger_Access', 'rep_label' ), $f['reps'] ) ) . ( ! empty( $f['mine'] ) ? ' (you)' : '' );
		}
		if ( '' !== $f['city'] ) {
			$bits[] = $f['city'];
		}
		if ( '' !== $f['zip'] ) {
			$bits[] = $f['zip'];
		}
		if ( '' !== $f['source'] ) {
			$g      = ZANA_Lead_Sources::group( $f['source'] );
			$bits[] = sprintf( /* translators: %s: a lead-source group */ __( 'lead source: %s', 'zorderz' ), $g ? $g['label'] : $f['source'] );
		}
		return implode( ' · ', $bits );
	}

	protected static function when( $p ): string {
		return null === $p ? __( 'all time', 'zorderz' ) : ZANA_Ledger_Periods::short( $p );
	}

	protected static function synced_text(): string {
		try {
			$t = strtotime( (string) ( ZANA_Ledger::coverage()['invoices']['last_ok'] ?? '' ) . ' UTC' );
		} catch ( \Throwable $e ) {
			$t = false;
		}
		if ( ! $t ) {
			return '';
		}
		$min = (int) floor( ( time() - $t ) / 60 );
		return $min < 1 ? 'synced just now' : ( $min < 90 ? 'synced ' . $min . ' min ago' : 'synced ' . round( $min / 60 ) . ' hours ago' );
	}

	protected static function footer( array $f ): string {
		$s    = self::synced_text();
		$bits = array( '_' . self::MARK . ( '' !== $s ? ' (' . $s . ')' : '' ) . '.' );
		if ( '' !== $f['brand'] ) {
			$bits[] = '"' . $f['brand'] . '" = the lines that name it.';
		} elseif ( '' !== $f['category'] ) {
			$bits[] = '"' . $f['category'] . '" = the lines filed under it.';
		}
		if ( 'paid' === $f['family'] ) {
			$bits[] = 'An invoice counts on the day it was paid in full, at its whole amount; drafts and deleted invoices left out.';
		} elseif ( in_array( $f['family'], array( 'sales', 'customers' ), true ) ) {
			$bits[] = 'A job = an invoice; drafts and deleted invoices left out, archived ones included.';
		} elseif ( 'estimates' === $f['family'] ) {
			$bits[] = 'Drafts and deleted estimates left out.';
		}
		return implode( ' ', $bits ) . '_';
	}

	/** A table of groups (months and years in order, else largest first). $cols: header => fn( $g, $total ) => cell. */
	protected static function table( array $f, array $groups, array $cols, int $max ): array {
		if ( ! $groups || '' === (string) $f['group'] ) {
			return array();
		}
		$heads = array( 'month' => 'Month', 'year' => 'Year', 'rep' => 'Salesperson', 'city' => 'Town', 'category' => 'Product line', 'brand' => 'Brand', 'source' => 'Lead source', 'customer' => 'Customer' );
		$total = 0.0;
		foreach ( $groups as $g ) {
			$total += (float) $g['value'];
		}
		$more = array();
		if ( in_array( $f['group'], array( 'month', 'year' ), true ) ) {
			ksort( $groups );
			$groups = array_slice( $groups, -$max, null, true );
		} else {
			uasort( $groups, function ( $a, $b ) { return $b['value'] <=> $a['value']; } );
			$limit  = $f['top'] ? (int) $f['top'] : ( count( $groups ) > $max ? $max - 1 : $max );
			$more   = array_slice( $groups, $limit, null, true );
			$groups = array_slice( $groups, 0, $limit, true );
		}
		$rows = array( '', '| ' . $heads[ $f['group'] ] . ' | ' . implode( ' | ', array_keys( $cols ) ) . ' |', '|---' . str_repeat( '|---:', count( $cols ) ) . '|' );
		foreach ( $groups as $k => $g ) {
			$label = (string) $k;
			if ( 'month' === $f['group'] ) {
				$label = gmdate( 'M Y', strtotime( $label . '-01 00:00:00 UTC' ) );
			} elseif ( 'customer' === $f['group'] ) {
				$label = substr( $label, strpos( $label, '|' ) + 1 );
			}
			$cells = array();
			foreach ( $cols as $fn ) {
				$cells[] = $fn( $g, $total );
			}
			$rows[] = '| ' . self::cell( $label ) . ' | ' . implode( ' | ', $cells ) . ' |';
		}
		if ( $more && ! $f['top'] ) {
			$sum = array( 'value' => 0.0, 'jobs' => 0, 'won' => 0, 'days' => array() );
			foreach ( $more as $g ) {
				$sum['value'] += $g['value'];
				$sum['jobs']  += $g['jobs'];
				$sum['won']   += (int) ( $g['won'] ?? 0 );
				$sum['days']   = array_merge( $sum['days'], (array) ( $g['days'] ?? array() ) );
			}
			$cells = array();
			foreach ( $cols as $fn ) {
				$cells[] = $fn( $sum, $total );
			}
			$rows[] = '| Everything else (' . self::n( count( $more ) ) . ') | ' . implode( ' | ', $cells ) . ' |';
		}
		$rows[] = '';
		if ( 'brand' === $f['group'] ) {
			$rows[] = '_A line naming two brands counts under both._';
		}
		return $rows;
	}

	/** The answer, whole (markdown), for the chat. */
	public static function answer( array $f, int $uid = 0 ): string {
		return self::render( $f, false );
	}

	/** The ask bar's answer: what it is, then the figure asked for; a table only when a breakdown was asked for. */
	public static function quick( array $f, int $uid = 0 ): string {
		return self::render( $f, true );
	}

	protected static function render( array $f, bool $quick ): string {
		$f = ZANA_Ledger_Figures::filters( array_merge( array( 'period' => null, 'compare' => null, 'years' => null, 'mine' => false, 'all' => false ), $f ) );
		switch ( $f['family'] ) {
			case 'estimates':
				$out = self::render_estimates( $f, $quick );
				break;
			case 'owed':
				$out = ! empty( $f['list'] ) ? self::render_owed_list( $f ) : self::render_owed( $f, $quick );
				break;
			case 'paid':
				$out = self::render_paid( $f, $quick );
				break;
			case 'customers':
				$out = self::render_customers( $f, $quick );
				break;
			default:
				$out = self::render_sales( $f, $quick );
		}
		if ( ! $quick ) {
			$out[] = '';
			$out[] = self::footer( $f );
		}
		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", implode( "\n", $out ) ) );
	}

	protected static function render_sales( array $f, bool $quick ): array {
		$ps   = self::periods( $f );
		$ask  = $f['ask'] ? $f['ask'][0] : 'amount';
		$max  = $quick ? 8 : self::TABLE_ROWS;
		$head = '**' . self::subject( $f, 'sales' ) . ' · ' . self::when( $ps[0] ) . ( count( $ps ) > 1 ? ' vs ' . self::when( $ps[1] ) : '' ) . '**';
		$out  = array( $head, '' );
		$a    = ZANA_Ledger_Figures::sales( $f, $ps[0] );
		if ( count( $ps ) > 1 ) {
			$b = ZANA_Ledger_Figures::sales( $f, $ps[1] );
			if ( 'count' === $ask ) {
				$out[] = '**' . self::n( $a['jobs'] ) . '** vs **' . self::n( $b['jobs'] ) . '** jobs (' . self::change( $a['jobs'], $b['jobs'] ) . ')';
			} elseif ( 'average' === $ask ) {
				$av    = $a['jobs'] ? $a['value'] / $a['jobs'] : 0;
				$bv    = $b['jobs'] ? $b['value'] / $b['jobs'] : 0;
				$out[] = 'Average job **' . self::round_money( $av ) . '** vs **' . self::round_money( $bv ) . '** (' . self::change( $av, $bv ) . ')';
			} else {
				$out[] = '**' . self::round_money( $a['value'] ) . '** vs **' . self::round_money( $b['value'] ) . '** (' . self::change( $a['value'], $b['value'] ) . ') · ' . self::n( $a['jobs'] ) . ' vs ' . self::n( $b['jobs'] ) . ' jobs';
			}
			if ( 'month' === $f['group'] ) {
				$out = array_merge( $out, self::table( $f, $a['groups'], array( 'Sales' => function ( $g ) { return self::round_money( $g['value'] ); }, 'Jobs' => function ( $g ) { return self::n( $g['jobs'] ); } ), $max ) );
			}
			return $out;
		}
		if ( 'count' === $ask ) {
			$out[] = '**' . self::plural( $a['jobs'], 'job', 'jobs' ) . '**' . ( $quick ? '' : ' · ' . self::money( $a['value'] ) );
		} elseif ( 'average' === $ask ) {
			$out[] = 'Average job **' . ( $a['jobs'] ? self::money( $a['value'] / $a['jobs'] ) : '$0.00' ) . '** over ' . self::plural( $a['jobs'], 'job', 'jobs' );
		} elseif ( 'per_customer' === $ask ) {
			$out[] = 'Per customer **' . ( $a['customers'] ? self::money( $a['value'] / $a['customers'] ) : '$0.00' ) . '** (' . self::plural( $a['customers'], 'customer', 'customers' ) . ')';
		} elseif ( 'share' === $ask && ( '' !== $f['brand'] || '' !== $f['category'] || $f['reps'] || '' !== $f['city'] || '' !== $f['source'] ) ) {
			$all   = ZANA_Ledger_Figures::sales( ZANA_Ledger_Figures::filters(), $ps[0] );
			$out[] = '**' . self::pct( $a['value'], $all['value'] ) . '** of all sales (' . self::money( $a['value'] ) . ' of ' . self::money( $all['value'] ) . ')';
		} else {
			$out[] = '**' . self::money( $a['value'] ) . '** from ' . self::plural( $a['jobs'], 'job', 'jobs' );
		}
		if ( ! $quick && '' === $f['group'] ) {
			$out[] = '- ' . sprintf( 'Of those invoices, %s collected and %s still owed.', self::money( $a['paid'] ), self::money( $a['owed'] ) );
			if ( $a['jobs'] ) {
				$out[] = '- Average job ' . self::money( $a['value'] / $a['jobs'] ) . '; ' . self::plural( $a['customers'], 'customer', 'customers' ) . '.';
			}
		}
		$share = ! in_array( $f['group'], array( 'month', 'year', 'brand' ), true );
		return array_merge( $out, self::table( $f, $a['groups'], array(
			'Sales' => function ( $g, $t ) use ( $share ) { return self::round_money( $g['value'] ) . ( $share && $t > 0 ? ' (' . self::pct( $g['value'], $t ) . ')' : '' ); },
			'Jobs'  => function ( $g ) { return self::n( $g['jobs'] ); },
		), $max ) );
	}

	protected static function render_estimates( array $f, bool $quick ): array {
		$ps  = self::periods( $f );
		$max = $quick ? 8 : self::TABLE_ROWS;
		$out = array( '**' . self::subject( $f, 'estimates' ) . ' · ' . self::when( $ps[0] ) . ( count( $ps ) > 1 ? ' vs ' . self::when( $ps[1] ) : '' ) . '**', '' );
		$a   = ZANA_Ledger_Figures::estimates( $f, $ps[0] );
		$ask = $f['ask'] ? $f['ask'] : array( 'count' );
		if ( count( $ps ) > 1 ) {
			$b     = ZANA_Ledger_Figures::estimates( $f, $ps[1] );
			$out[] = '**' . self::n( $a['n'] ) . '** vs **' . self::n( $b['n'] ) . '** estimates (' . self::change( $a['n'], $b['n'] ) . ') · ' . self::round_money( $a['value'] ) . ' vs ' . self::round_money( $b['value'] )
				. ' · turned into invoices ' . self::pct( $a['invoiced'], $a['n'] ) . ' vs ' . self::pct( $b['invoiced'], $b['n'] );
			return $out;
		}
		if ( in_array( 'conversion', $ask, true ) ) {
			$out[] = '**' . self::pct( $a['invoiced'], $a['n'] ) . '** turned into invoices (' . self::n( $a['invoiced'] ) . ' of ' . self::n( $a['n'] ) . '); ' . self::n( $a['accepted'] ) . ' accepted.';
		} elseif ( in_array( 'open', $ask, true ) ) {
			$out[] = '**' . self::plural( $a['open'], 'estimate', 'estimates' ) . ' still open** · ' . self::money( $a['open_value'] );
		} elseif ( in_array( 'accepted', $ask, true ) ) {
			$out[] = '**' . self::n( $a['accepted'] ) . ' accepted** of ' . self::plural( $a['n'], 'estimate', 'estimates' ) . ' · ' . self::money( $a['accepted_value'] );
		} elseif ( in_array( 'average', $ask, true ) ) {
			$out[] = 'Average estimate **' . ( $a['n'] ? self::money( $a['value'] / $a['n'] ) : '$0.00' ) . '** over ' . self::plural( $a['n'], 'estimate', 'estimates' );
		} else {
			$out[] = '**' . self::plural( $a['n'], 'estimate', 'estimates' ) . '** · ' . self::money( $a['value'] );
		}
		if ( ! $quick && '' === $f['group'] ) {
			$out[] = '- ' . self::n( $a['accepted'] ) . ' accepted, ' . self::n( $a['invoiced'] ) . ' turned into invoices (' . self::pct( $a['invoiced'], $a['n'] ) . '), '
				. self::n( $a['declined'] ) . ' declined, ' . self::n( $a['open'] ) . ' still open (' . self::money( $a['open_value'] ) . ').';
			if ( $a['drafts'] ) {
				$out[] = '- ' . self::plural( $a['drafts'], 'draft', 'drafts' ) . ' not counted.';
			}
		}
		return array_merge( $out, self::table( $f, $a['groups'], array(
			'Estimates' => function ( $g ) { return self::n( $g['jobs'] ) . ' (' . self::round_money( $g['value'] ) . ')'; },
			'Invoiced'  => function ( $g ) { return self::pct( (int) ( $g['won'] ?? 0 ), $g['jobs'] ); },
		), $max ) );
	}

	protected static function render_owed( array $f, bool $quick ): array {
		$due = ! empty( $f['due_ask'] ) && null !== $f['period'];
		$o   = ZANA_Ledger_Figures::owed( $f, $f['period'], $due );
		$out = array( '**' . self::subject( $f, $due ? 'invoices due' : 'money owed' ) . ' · ' . ( $due ? self::when( $f['period'] ) : ( null === $f['period'] ? 'any year' : 'issued ' . self::when( $f['period'] ) ) ) . '**', '' );
		if ( in_array( 'overdue', $f['ask'], true ) ) {
			$out[] = '**' . self::money( $o['over_owed'] ) . ' overdue** on ' . self::plural( $o['over_n'], 'invoice', 'invoices' ) . ( $quick ? '' : ' (of ' . self::money( $o['owed'] ) . ' still owed)' );
		} elseif ( in_array( 'count', $f['ask'], true ) ) {
			$out[] = '**' . self::plural( $o['n'], 'open invoice', 'open invoices' ) . '** · ' . self::money( $o['owed'] );
		} else {
			$out[] = '**' . self::money( $o['owed'] ) . ' still owed** on ' . self::plural( $o['n'], 'invoice', 'invoices' ) . ( $o['over_n'] ? ', ' . self::money( $o['over_owed'] ) . ' of it overdue' : '' );
		}
		if ( ( ! $quick || in_array( 'ages', $f['ask'], true ) ) && '' === $f['group'] && $o['n'] ) {
			$labels = $o['by_due']
				? array( 'Not yet due', '1–30 days late', '31–60 days late', '61–90 days late', 'Over 90 days late' )
				: array( '0–30 days', '31–60 days', '61–90 days', '91–180 days', 'Over 180 days' );
			$out[]  = '';
			$out[]  = '| ' . ( $o['by_due'] ? 'Past due' : 'Since issued' ) . ' | Owed | Invoices |';
			$out[]  = '|---|---:|---:|';
			foreach ( $o['ages'] as $i => $b ) {
				$out[] = '| ' . $labels[ $i ] . ' | ' . self::money( $b['owed'] ) . ' | ' . self::n( $b['n'] ) . ' |';
			}
			$out[] = '';
		}
		if ( ! $quick ) {
			if ( $o['arch_n'] ) {
				$out[] = '- Not counted: ' . self::plural( $o['arch_n'], 'archived invoice', 'archived invoices' ) . ' still showing ' . self::money( $o['arch_owed'] ) . ' (usually written off or settled outside the billing system).';
			}
			if ( $o['undated_due'] ) {
				$out[] = '- ' . self::plural( $o['undated_due'], 'invoice has', 'invoices have' ) . ' no due date in the ledger: counted as due on the invoice date.';
			}
			if ( '' !== $o['oldest'] ) {
				$out[] = '- The oldest open invoice is from ' . gmdate( 'M j, Y', strtotime( $o['oldest'] . ' 12:00:00 UTC' ) ) . '.';
			}
		}
		return array_merge( $out, self::table( $f, $o['groups'], array(
			'Owed'     => function ( $g, $t ) { return self::money( $g['value'] ) . ( $t > 0 ? ' (' . self::pct( $g['value'], $t ) . ')' : '' ); },
			'Invoices' => function ( $g ) { return self::n( $g['jobs'] ); },
		), $quick ? 8 : self::TABLE_ROWS ) );
	}

	/** Which invoices are overdue (or still owed), ranked, with the due date and days late of each. Names customers. */
	protected static function render_owed_list( array $f ): array {
		$today = ZANA_Ledger_Periods::today();
		$over  = ! empty( $f['list']['overdue'] );
		$dates = ! empty( $f['dates'] );
		$rows  = array();
		$sum   = array( 'n' => 0, 'owed' => 0.0, 'over_n' => 0, 'over_owed' => 0.0, 'arch_n' => 0, 'arch_owed' => 0.0 );
		foreach ( ZANA_Ledger_Figures::rows( 'invoices', null ) as $r ) {
			$o = (float) ( $r['outstanding'] ?? 0 );
			if ( $o <= 0.004 || ! ZANA_Ledger_Figures::passes( $f, $r ) ) {
				continue;
			}
			if ( 2 === (int) ( $r['vis_state'] ?? 0 ) ) {
				$sum['arch_n']++;
				$sum['arch_owed'] += $o;
				continue;
			}
			$d    = substr( (string) $r['d'], 0, 10 );
			$due  = $dates ? substr( (string) ( $r['due_date'] ?? '' ), 0, 10 ) : '';
			$due  = $dates && '' === $due ? $d : $due;
			$late = '' !== $due && $due < $today;
			$from = $dates ? $due : $d;
			$days = '' !== $from ? (int) round( ( strtotime( $today . ' 12:00:00 UTC' ) - strtotime( $from . ' 12:00:00 UTC' ) ) / 86400 ) : null;
			if ( $late ) {
				$sum['over_n']++;
				$sum['over_owed'] += $o;
			}
			if ( $over && ! $late ) {
				continue;
			}
			$sum['n']++;
			$sum['owed'] += $o;
			$rows[]       = array( 'number' => (string) ( $r['invoice_number'] ?? '' ), 'customer' => (string) ( $r['customer_name'] ?? '' ), 'when' => $dates ? $due : $d, 'days' => $days, 'owed' => $o );
		}
		$by_days = 'days' === ( $f['list']['sort'] ?? '' );
		usort( $rows, function ( $a, $b ) use ( $by_days ) {
			return $by_days ? array( (int) $b['days'], $b['owed'] ) <=> array( (int) $a['days'], $a['owed'] ) : array( $b['owed'], (int) $b['days'] ) <=> array( $a['owed'], (int) $a['days'] );
		} );
		$limit = $f['top'] ? (int) $f['top'] : self::LIST_ROWS;
		$out   = array( '**' . ( $over ? 'Overdue invoices' : 'Invoices still owed' ) . ' · any year**', '' );
		if ( ! $rows ) {
			$out[] = $over ? 'Nothing is past its due date.' : 'No open invoice shows a balance.';
			return $out;
		}
		$out[] = $over
			? '**' . self::money( $sum['over_owed'] ) . ' overdue** on ' . self::plural( $sum['over_n'], 'invoice', 'invoices' ) . '.'
			: '**' . self::money( $sum['owed'] ) . ' still owed** on ' . self::plural( $sum['n'], 'invoice', 'invoices' ) . ( $sum['over_n'] ? ', ' . self::money( $sum['over_owed'] ) . ' of it overdue' : '' ) . '.';
		$out[] = '';
		$out[] = $dates ? '| Invoice | Customer | Owed | Due | Days late |' : '| Invoice | Customer | Owed | Issued | Days since issue |';
		$out[] = '|---|---|---:|---|---:|';
		foreach ( array_slice( $rows, 0, $limit ) as $r ) {
			$days  = null === $r['days'] ? '-' : ( $r['days'] > 0 ? self::n( $r['days'] ) : 'not yet due' );
			$num   = preg_match( '/^[A-Za-z0-9-]{1,20}$/D', $r['number'] ) ? '#' . $r['number'] : '-';
			$out[] = '| ' . $num . ' | ' . ( '' !== self::cell( $r['customer'] ) ? self::cell( $r['customer'] ) : '-' ) . ' | ' . self::money( $r['owed'] ) . ' | ' . ( '' !== $r['when'] ? gmdate( 'M j, Y', strtotime( $r['when'] . ' 12:00:00 UTC' ) ) : '-' ) . ' | ' . $days . ' |';
		}
		$more = array_slice( $rows, $limit );
		if ( $more ) {
			$out[] = '';
			$out[] = '- And ' . self::plural( count( $more ), 'more invoice', 'more invoices' ) . ', ' . self::money( array_sum( array_column( $more, 'owed' ) ) ) . '.';
		}
		if ( $sum['arch_n'] ) {
			$out[] = '- Not counted: ' . self::plural( $sum['arch_n'], 'archived invoice', 'archived invoices' ) . ' still showing ' . self::money( $sum['arch_owed'] ) . '.';
		}
		return $out;
	}

	protected static function render_paid( array $f, bool $quick ): array {
		$ps  = self::periods( $f );
		$p   = ZANA_Ledger_Figures::paid( $f, $ps[0] );
		$out = array( '**' . self::subject( $f, 'invoices paid in full' ) . ' · ' . self::when( $ps[0] ) . '**', '' );
		$day = function ( $v ) {
			$d = (int) round( (float) $v );
			return $d . ' ' . ( 1 === $d ? 'day' : 'days' );
		};
		if ( in_array( 'days', $f['ask'], true ) ) {
			$out[] = null === $p['avg'] ? 'No invoice was paid in full in that time.'
				: 'Customers took **' . $day( $p['avg'] ) . '** on average to pay in full (median ' . $day( $p['median'] ) . ') over ' . self::plural( count( $p['days'] ), 'invoice', 'invoices' ) . '.';
			$cols  = array( 'Average days' => function ( $g ) use ( $day ) { return $g['days'] ? $day( ZANA_Ledger_Figures::avg( $g['days'] ) ) : 'n/a'; }, 'Invoices' => function ( $g ) { return self::n( $g['jobs'] ); } );
		} else {
			$out[] = '**' . self::plural( $p['n'], 'invoice', 'invoices' ) . ' paid in full** · ' . self::money( $p['value'] );
			$cols  = array( 'Paid' => function ( $g ) { return self::round_money( $g['value'] ); }, 'Invoices' => function ( $g ) { return self::n( $g['jobs'] ); } );
		}
		if ( ! $quick && $p['undated'] ) {
			$out[] = '- ' . self::plural( $p['undated'], 'invoice was', 'invoices were' ) . ' settled with no payment date (a credit, say): not counted.';
		}
		return array_merge( $out, self::table( $f, $p['groups'], $cols, $quick ? 8 : self::TABLE_ROWS ) );
	}

	protected static function render_customers( array $f, bool $quick ): array {
		$ps  = self::periods( $f );
		$c   = ZANA_Ledger_Figures::customers( $f, $ps[0] );
		$out = array( '**' . self::subject( $f, 'customers' ) . ' · ' . self::when( $ps[0] ) . '**', '' );
		if ( in_array( 'new', $f['ask'], true ) && null !== $ps[0] ) {
			$out[] = '**' . self::plural( $c['new'], 'new customer', 'new customers' ) . '** (first job ever) of ' . self::n( $c['customers'] ) . ' billed.';
		} elseif ( in_array( 'repeat', $f['ask'], true ) ) {
			$out[] = '**' . self::plural( $c['repeat'], 'customer', 'customers' ) . ' with two or more jobs** of ' . self::n( $c['customers'] ) . ' billed' . ( null !== $ps[0] ? '; ' . self::n( $c['returning'] ) . ' had been customers before.' : '.' );
		} else {
			$out[] = '**' . self::plural( $c['customers'], 'customer', 'customers' ) . ' billed**' . ( null !== $ps[0] ? ' · ' . self::n( $c['new'] ) . ' new, ' . self::n( $c['returning'] ) . ' returning' : '' );
		}
		return $out;
	}
}
