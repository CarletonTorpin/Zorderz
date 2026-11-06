<?php
/**
 * ZANA_Lead_Sources — "how many leads from <source> in <period>", answered by code from the ledger's CRM leads copy.
 *
 * The groups are the business's (ZANA_Ledger_Rules `source_groups`): each names the words a person types for it, which
 * CRM source names belong to it, which billing-document labels belong to it (empty: the billing system has no such
 * tag), and optionally source names shown beside it on their own line without being counted in it (`aside`). Core adds
 * one group of its own, "No source set" (an empty CRM source), which names nothing.
 *
 * Every count is for ONE period: leads created in it, each lead's status now (won, lost, cancelled, open). "How many did
 * we win last month" also gets the leads closed won in that month, whenever they came in. For a group the billing
 * system also tags, the same dates' estimates and invoices with that label are given beside the CRM's count (dollars
 * only with the revenue right).
 *
 * Recognised by rule with an allow-list of words: a word this reader does not know ("leads in <town>", "leads for
 * <person>", a source nobody listed) sends the question to the chat's planner, never to a count that ignores it.
 *
 * Who: staff and admin tiers (counts are not money); never the shared device, never Demo Mode. Only while the leads
 * copy is complete and synced in the last two hours.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Lead_Sources {

	/** The first words of every answer (a follow-up in the chat is recognised by them). */
	const MARK = 'Leads by source, from';

	/** Rows a table shows. */
	const TABLE_ROWS = 12;

	/** The leads copy is fresh for this long. */
	const FRESH_SECONDS = 7200;

	/** Injected in tests: bool, the leads copy is complete and fresh. */
	public static $ready = null;

	/* ─────────────────────────── the groups ─────────────────────────── */

	/** key => group (the business's, then Core's "none"). */
	public static function groups(): array {
		$out = array();
		foreach ( (array) ZANA_Ledger_Rules::get()['source_groups'] as $g ) {
			$out[ $g['key'] ] = $g;
		}
		$out['none'] = array(
			'key'         => 'none',
			'label'       => __( 'No source set', 'zorderz' ),
			'words'       => array( 'no source', 'without a source', 'unknown source', 'source not set', 'source missing', 'source blank' ),
			'crm'         => array( '=' ),
			'billing'     => array(),
			'except'      => array(),
			'aside'       => array(),
			'aside_label' => '',
		);
		return $out;
	}

	public static function group( string $key ): ?array {
		$g = self::groups();
		return $g[ $key ] ?? null;
	}

	/** The group a CRM source name belongs to, or ''. */
	public static function group_of( string $source ): string {
		foreach ( self::groups() as $k => $g ) {
			if ( ZANA_Ledger_Rules::matches( $source, $g['crm'], $g['except'] ) ) {
				return $k;
			}
		}
		return '';
	}

	/** A billing document's lead source as its group label, else the label itself ('(no source given)' when blank). */
	public static function billing_label( string $src ): string {
		foreach ( self::groups() as $g ) {
			if ( $g['billing'] && ZANA_Ledger_Rules::matches( $src, $g['billing'], $g['except'] ) ) {
				return $g['label'];
			}
		}
		$c = trim( $src );
		return '' !== $c ? $c : __( '(no source given)', 'zorderz' );
	}

	/** Groups the question names, in order. */
	public static function groups_in( string $m ): array {
		$out = array();
		foreach ( self::groups() as $k => $g ) {
			foreach ( $g['words'] as $w ) {
				if ( ZANA_Ledger_Rules::has_phrase( $m, $w ) ) {
					$out[] = $k;
					break;
				}
			}
		}
		return $out;
	}

	/** Every word of every group's phrases (a question's words this reader knows). */
	protected static function group_words(): array {
		$w = array();
		foreach ( self::groups() as $g ) {
			foreach ( $g['words'] as $p ) {
				foreach ( preg_split( '/[^\p{L}\p{N}\']+/u', (string) $p, -1, PREG_SPLIT_NO_EMPTY ) as $x ) {
					$w[ $x ] = true;
				}
			}
		}
		return array_keys( $w );
	}

	/* ─────────────────────────── recognising the question (pure but for the groups) ─────────────────────────── */

	public static function norm( string $message ): string {
		return ZANA_Ledger_Rules::fold( $message );
	}

	/** A question this report must leave alone. */
	protected static function excluded_ask( string $m ): bool {
		if ( preg_match( '/\b(?:list|show me (?:the|all|each|every)|names?|who (?:are|were|is|was)|which leads|what leads|tell me about (?:the|each)|details?|contacts?|phone|emails?|address(?:es)?)\b/', $m ) ) {
			return true;
		}
		if ( preg_match( '/\blead\s*(?:#|no\.?|number|–|-)?\s*\d{2,}|#\s*\d{2,}/', $m ) ) {
			return true;
		}
		if ( preg_match( '/\b(?:my|mine|assigned|assignee|owner|rep|reps|salesperson|salespeople|sales people|per person|by person|each person|for each rep)\b|\b[a-z]+\'s leads\b/', $m ) ) {
			return true;
		}
		// a person: "how many leads does <name> have"
		$skip = array_merge( array( 'we', 'you', 'i', 'it', 'our', 'the', 'they', 'that', 'this', 'crm' ), self::group_words() );
		$skip = implode( '|', array_map( function ( $w ) { return preg_quote( $w, '/' ) . '\b'; }, $skip ) );
		if ( preg_match( '/\b(?:does|did|has|have)\s+(?!' . $skip . ')[a-z]+\s+(?:have|had|got|get|gotten|bring|brought|generate|generated|close|closed|win|won)\b/', $m ) ) {
			return true;
		}
		if ( preg_match( '/\b(?:worth|values?|valued|pipeline|revenue|sales|sold|money|dollars?|\$|income|profit|spend|spent|cost|costs|cpl|roi|budget|cpa|price)\b/', $m ) ) {
			return true;
		}
		if ( preg_match( '/\b(?:vs\.?|versus|compared?|comparison|than|difference|trend|growth|grow|change|up or down|better|worse)\b/', $m ) ) {
			return true;
		}
		if ( preg_match( '/\b(?:add|create|enter|log|update|assign|move|close (?:the|a|this|that|out)|delete|email|e-mail|text|call|follow up|follow-up|write|draft|send|chart|graph|plot)\b/', $m ) ) {
			return true;
		}
		return (bool) preg_match( '/\b(?:surveys?|reviews?|invoices?|estimates?|quotes?|jobs?|customers?|clients?|website visits?|traffic|clicks?|impressions?|calls? from)\b/', $m );
	}

	/** A source the question names that no group knows ("leads from the home show"). */
	protected static function unknown_source( string $m ): bool {
		if ( ! preg_match_all( '/\b(?:from|via|through|by way of|off|out of)\s+(?:the\s+|our\s+|a\s+)?([a-z][a-z\'-]*(?:\s+[a-z][a-z\'-]*){0,2})/', $m, $r ) ) {
			return false;
		}
		$ok = '/^(?:in|during|for|over|within|to|and|or|so far|the past|past|last|this|since|our|each|every|all|no source|each source|every source|all sources|which source|what source|source|sources|nutshell|the crm|crm|this year|last year|this month|last month|today|yesterday|jan\w*|feb\w*|mar\w*|apr\w*|may|jun\w*|jul\w*|aug\w*|sep\w*|oct\w*|nov\w*|dec\w*|20\d\d|q[1-4])\b/';
		foreach ( $r[1] as $phrase ) {
			$phrase = trim( $phrase );
			if ( preg_match( $ok, $phrase ) ) {
				continue;
			}
			$known = false;
			foreach ( self::groups() as $g ) {
				foreach ( $g['words'] as $w ) {
					if ( 0 === strpos( $phrase, $w ) ) {
						$known = true;
						break 2;
					}
				}
			}
			if ( ! $known ) {
				return true;
			}
		}
		return false;
	}

	/** The words left that this report does not read. */
	public static function leftover( string $m ): array {
		$mrx   = implode( '|', array_keys( ZANA_Ledger_Periods::MONTHS ) );
		$nums  = '\d{1,3}|one|two|three|four|five|six|seven|eight|nine|ten|twelve|eighteen|thirty|ninety';
		$strip = array(
			'/\b20\d\d\s*(?:-|–|to|through|thru)\s*20\d\d\b/',
			'/\b(?:since|from|after)\s+20\d\d(?:\s+(?:on|onwards?|forward|to (?:now|today|date)))?\b/',
			'/\ball time\b|\bever\b/',
			'/\b(?:in |over |during |for |within )?(?:the )?(?:last|past|previous)\s+(?:' . $nums . ')\s+(?:days?|weeks?|months?|years?)\b/',
			'/\b(?:in |over |during |for )?(?:the |this )?past (?:week|month|year)\b/',
			'/\b(?:q[1-4]|(?:the )?(?:first|second|third|fourth) quarter)(?:\s+(?:of\s+)?20\d\d)?\b/',
			'/\b(?:since\s+)?(?:' . $mrx . ')\b(?:\s+(?:of\s+)?20\d\d)?/',
			'/\b(?:so far )?(?:this year|ytd|year to date)(?: so far)?\b/',
			'/\b(?:last|previous) (?:year|month|week)\b|\bthis (?:month|week)\b|\bmonth to date\b|\bmtd\b|\btoday\b|\byesterday\b|\bso far\b/',
			'/\b20\d\d\b/',
			'/\bby month\b|\bper month\b|\bmonthly\b|\beach month\b|\bmonth by month\b/',
			'/\bturned into (?:jobs|sales)\b|\bclosed won\b|\bin (?:the )?pipeline\b|\bnot closed\b/',
		);
		$rest = $m;
		foreach ( $strip as $rx ) {
			$rest = (string) preg_replace( $rx, ' ', $rest );
		}
		foreach ( self::groups() as $g ) {
			foreach ( $g['words'] as $w ) {
				$rest = (string) preg_replace( '/(?<![\p{L}\p{N}])' . str_replace( ' ', '\s+', preg_quote( $w, '/' ) ) . '(?![\p{L}\p{N}])/u', ' ', $rest );
			}
		}
		$ok   = '/^(?:how|many|number|numbers|of|count|counts|total|totals|tally|percent|percentage|share|lead|leads|new|incoming|inbound|'
			. 'did|do|does|we|us|i|you|have|has|had|get|got|gotten|getting|receive|received|come|came|coming|bring|brought|generate|generated|'
			. 'in|from|for|during|on|via|through|with|the|our|all|a|an|were|was|are|is|there|been|so|far|what|what\'s|whats|where|which|'
			. 'by|per|each|every|source|sources|channel|channels|breakdown|break|down|it|them|best|top|most|and|or|to|only|just|'
			. 'win|wins|won|close|closing|closed|conversion|conversions|convert|converted|rate|rates|lost|cancelled|canceled|open|still|active|pending|'
			. 'can|tell|me|give|show|please|know|nutshell|crm|now|currently|altogether|overall)$/';
		$left = array();
		foreach ( preg_split( '/[^a-z0-9\']+/', $rest, -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
			if ( ! preg_match( $ok, $w ) ) {
				$left[] = $w;
			}
		}
		return $left;
	}

	/** The status asked about: won | lost | cancelled | open | ''. A negation is never the positive status. */
	public static function status_in( string $m ): string {
		$m = (string) preg_replace( '/\bnot closed\b/', 'open', $m );
		if ( preg_match( '/\bnot\b|n\'t\b|\bwithout\b|\bnever\b/', $m ) ) {
			return '';
		}
		if ( preg_match( '/\b(?:won|wins|closed won|converted|turned into (?:jobs|sales))\b/', $m ) ) {
			return 'won';
		}
		if ( preg_match( '/\blost\b/', $m ) ) {
			return 'lost';
		}
		if ( preg_match( '/\bcancell?ed\b/', $m ) ) {
			return 'cancelled';
		}
		if ( preg_match( '/\b(?:open|still open|active|in (?:the )?pipeline|pending|not closed)\b/', $m ) ) {
			return 'open';
		}
		return '';
	}

	/**
	 * Is this a lead-count question? The filters, or null.
	 * @return array|null { groups[], period, status, by_month, breakdown }
	 */
	public static function detect( string $message ): ?array {
		$m = self::norm( $message );
		if ( '' === $m || strlen( $m ) > 400 ) {
			return null;
		}
		$rate   = (bool) preg_match( '/\b(?:win rate|close rate|closing rate|conversion rate|convert|converted|conversion)\b/', $m );
		$groups = self::groups_in( $m );
		if ( ! preg_match( '/\bleads?\b/', $m ) && ! ( $rate && $groups ) ) {
			return null;
		}
		$count     = (bool) preg_match( '/\b(?:how many|number of|count|total|tally|percent|percentage|share)\b/', $m );
		$breakdown = (bool) preg_match( '/\bby source\b|\bper source\b|\beach source\b|\bevery source\b|\blead sources?\b|\bsources? of (?:our |the )?leads\b|\bwhere (?:do|did|are|were|have) (?:our |the |all |these |those )?leads?\b.*\bcom(?:e|ing|ing from)\b|\bwhich (?:source|sources|channel|channels)\b|\bwhat (?:source|sources|channel|channels)\b|\bbreakdown\b|\bbreak (?:it |them )?down\b|\bbest source\b|\btop sources?\b/', $m );
		$bare      = ! $count && ! $breakdown && ! $rate && $groups && count( preg_split( '/\s+/', $m ) ) <= 6;
		if ( ! $count && ! $breakdown && ! $rate && ! $bare ) {
			return null;
		}
		if ( self::excluded_ask( $m ) || self::unknown_source( $m ) ) {
			return null;
		}
		$period = ZANA_Ledger_Periods::period_in( $m );
		if ( false === $period || self::leftover( $m ) ) {
			return null;
		}
		return array(
			'groups'    => $groups,
			'period'    => $period,
			'status'    => self::status_in( $m ),
			'by_month'  => (bool) preg_match( '/\bby month\b|\bper month\b|\bmonthly\b|\beach month\b|\bmonth by month\b/', $m ),
			'breakdown' => $breakdown || ! $groups,
		);
	}

	/** Is the leads copy complete and synced in the last two hours? */
	public static function ready(): bool {
		if ( null !== self::$ready ) {
			return (bool) self::$ready;
		}
		try {
			$c = ZANA_Ledger::coverage()['leads'] ?? array();
		} catch ( \Throwable $e ) {
			return false;
		}
		$t = strtotime( (string) ( $c['last_ok'] ?? '' ) . ' UTC' );
		return ! empty( $c['complete'] ) && (int) ( $c['rows'] ?? 0 ) > 0 && $t && $t >= time() - self::FRESH_SECONDS;
	}

	/* ─────────────────────────── counting ─────────────────────────── */

	/** Leads created in the period, by CRM source and status: source => { n, won, lost, cancelled, open, reread, other, months }. */
	public static function counts( $period ): array {
		$rows = $period
			? ZANA_Ledger_Figures::lead_rows( ZANA_Ledger_Periods::utc_edge( $period[0] ), ZANA_Ledger_Periods::utc_edge( gmdate( 'Y-m-d', strtotime( $period[1] . ' 12:00:00 UTC +1 day' ) ) ) )
			: array_filter( ZANA_Ledger_Figures::lead_rows(), function ( $r ) { return '' !== (string) ( $r['created_at'] ?? '' ); } );
		$by   = array();
		$tz   = ZANA_Ledger_Periods::tz();
		foreach ( $rows as $r ) {
			$src = trim( (string) ( $r['source'] ?? '' ) );
			if ( ! isset( $by[ $src ] ) ) {
				$by[ $src ] = array( 'n' => 0, 'won' => 0, 'lost' => 0, 'cancelled' => 0, 'open' => 0, 'reread' => 0, 'other' => 0, 'months' => array() );
			}
			$st = (int) ( $r['status'] ?? 0 );
			$by[ $src ]['n']++;
			$k = 10 === $st ? 'won' : ( 11 === $st ? 'lost' : ( 12 === $st ? 'cancelled' : ( in_array( $st, array( 0, 1 ), true ) ? 'open' : ( -1 === $st ? 'reread' : 'other' ) ) ) );
			$by[ $src ][ $k ]++;
			$local = new DateTime( (string) $r['created_at'], new DateTimeZone( 'UTC' ) );
			$local->setTimezone( $tz );
			$mk = $local->format( 'Y-m' );
			$by[ $src ]['months'][ $mk ] = ( $by[ $src ]['months'][ $mk ] ?? 0 ) + 1;
		}
		return $by;
	}

	/** Leads closed with a status in the period (by the CRM's close date, whenever created): source => n. */
	public static function closed_counts( array $period, string $status ): array {
		$code = array( 'won' => 10, 'lost' => 11, 'cancelled' => 12 )[ $status ] ?? 0;
		$from = ZANA_Ledger_Periods::utc_edge( $period[0] );
		$to   = ZANA_Ledger_Periods::utc_edge( $period[1], true );
		$out  = array();
		foreach ( ZANA_Ledger_Figures::lead_rows() as $r ) {
			$x = (string) ( $r['closed_at'] ?? '' );
			if ( $code && $code === (int) ( $r['status'] ?? 0 ) && '' !== $x && $x >= $from && $x <= $to ) {
				$k         = trim( (string) ( $r['source'] ?? '' ) );
				$out[ $k ] = ( $out[ $k ] ?? 0 ) + 1;
			}
		}
		return $out;
	}

	/** Billing documents with a group's label in the period: { invoices, customers, amount, estimates, accepted, est_customers }. */
	public static function billing( string $key, $period ): ?array {
		$g = self::group( $key );
		if ( ! $g || ! $g['billing'] ) {
			return null;
		}
		$out   = array( 'invoices' => 0, 'customers' => 0, 'amount' => 0.0, 'estimates' => 0, 'accepted' => 0, 'est_customers' => 0 );
		$cust  = array();
		$ecust = array();
		foreach ( ZANA_Ledger_Figures::rows( 'invoices', $period ) as $r ) {
			if ( (float) $r['amount'] > 0 && ZANA_Ledger_Rules::matches( (string) $r['lead_source'], $g['billing'], $g['except'] ) ) {
				$out['invoices']++;
				$out['amount'] += (float) $r['amount'];
				if ( (int) $r['customer_id'] > 0 ) {
					$cust[ (int) $r['customer_id'] ] = true;
				}
			}
		}
		foreach ( ZANA_Ledger_Figures::rows( 'estimates', $period ) as $r ) {
			if ( ZANA_Ledger_Rules::matches( (string) $r['lead_source'], $g['billing'], $g['except'] ) ) {
				$out['estimates']++;
				$out['accepted'] += ! empty( $r['accepted'] ) ? 1 : 0;
				if ( (int) $r['customer_id'] > 0 ) {
					$ecust[ (int) $r['customer_id'] ] = true;
				}
			}
		}
		$out['customers']     = count( $cust );
		$out['est_customers'] = count( $ecust );
		$out['amount']        = round( $out['amount'], 2 );
		return $out;
	}

	/* ─────────────────────────── answering ─────────────────────────── */

	protected static function n( $v ): string {
		return number_format( (int) $v );
	}

	protected static function plural( $n, string $one, string $many ): string {
		return self::n( $n ) . ' ' . ( 1 === (int) $n ? $one : $many );
	}

	protected static function pct( $a, $b ): string {
		return $b > 0 ? round( 100 * $a / $b ) . '%' : 'n/a';
	}

	protected static function cell( $s ): string {
		return str_replace( array( '|', "\n" ), array( '/', ' ' ), (string) $s );
	}

	protected static function sum( array $rows ): array {
		$t = array( 'n' => 0, 'won' => 0, 'lost' => 0, 'cancelled' => 0, 'open' => 0, 'reread' => 0, 'other' => 0, 'months' => array() );
		foreach ( $rows as $c ) {
			foreach ( array( 'n', 'won', 'lost', 'cancelled', 'open', 'reread', 'other' ) as $k ) {
				$t[ $k ] += (int) ( $c[ $k ] ?? 0 );
			}
			foreach ( (array) ( $c['months'] ?? array() ) as $p => $v ) {
				$t['months'][ $p ] = ( $t['months'][ $p ] ?? 0 ) + $v;
			}
		}
		return $t;
	}

	/** "4 won, 1 lost, 0 cancelled, 6 still open" (+ the win rate when percentages are allowed). */
	protected static function outcome( array $c, bool $percent ): string {
		$bits = array( self::n( $c['won'] ) . ' won', self::n( $c['lost'] ) . ' lost', self::n( $c['cancelled'] ) . ' cancelled', self::n( $c['open'] ) . ' still open' );
		if ( ! empty( $c['reread'] ) ) {
			$bits[] = self::n( $c['reread'] ) . ' closed with the outcome still being read';
		}
		if ( ! empty( $c['other'] ) ) {
			$bits[] = self::n( $c['other'] ) . ' with another status';
		}
		$s = implode( ', ', $bits );
		if ( $percent && ( $c['won'] + $c['lost'] ) > 0 ) {
			$s .= ' (win rate ' . self::pct( $c['won'], $c['won'] + $c['lost'] ) . ' of those decided)';
		}
		return $s;
	}

	/** The rows of one group: source => counts, and the aside rows apart. */
	protected static function split( array $g, array $by ): array {
		$in    = array();
		$aside = array();
		foreach ( $by as $src => $c ) {
			if ( $g['aside'] && ZANA_Ledger_Rules::matches( (string) $src, $g['aside'] ) ) {
				$aside[ $src ] = $c;
			} elseif ( ZANA_Ledger_Rules::matches( (string) $src, $g['crm'], $g['except'] ) ) {
				$in[ $src ] = $c;
			}
		}
		return array( $in, $aside );
	}

	/**
	 * The answer, whole (markdown). $ctx: ZANA_Ledger_Access context (dollars with company rights, percentages likewise).
	 */
	public static function answer( array $f, array $ctx ): string {
		$f       = array_merge( array( 'groups' => array(), 'period' => null, 'status' => '', 'by_month' => false, 'breakdown' => true ), $f );
		$money   = ZANA_Ledger_Access::may( $ctx, 'company' );
		$by      = self::counts( $f['period'] );
		$when    = $f['period'] ? ZANA_Ledger_Periods::label( $f['period'] ) : __( 'all time', 'zorderz' );
		$all     = self::sum( $by );
		$out     = array( '**' . self::MARK . ' the CRM copy · ' . $when . '**', '' );
		$closed  = ( in_array( $f['status'], array( 'won', 'lost', 'cancelled' ), true ) && $f['period'] ) ? self::closed_counts( $f['period'], $f['status'] ) : null;
		$groups  = self::groups();
		if ( $f['groups'] ) {
			foreach ( $f['groups'] as $gk ) {
				$g                = $groups[ $gk ];
				list( $in, $aside ) = self::split( $g, $by );
				$t                = self::sum( $in );
				$out[]            = '**' . $g['label'] . ': ' . self::plural( $t['n'], 'lead', 'leads' ) . '**' . ( $money && $all['n'] ? ' (' . self::pct( $t['n'], $all['n'] ) . ' of all ' . self::n( $all['n'] ) . ')' : '' );
				$out[]            = '- ' . self::outcome( $t, $money );
				if ( is_array( $closed ) ) {
					$n = 0;
					foreach ( $closed as $src => $c ) {
						if ( ZANA_Ledger_Rules::matches( (string) $src, $g['crm'], $g['except'] ) ) {
							$n += $c;
						}
					}
					$out[] = '- **' . self::plural( $n, 'lead', 'leads' ) . ' ' . $f['status'] . '** in ' . $f['period'][2] . ', by the day the CRM closed ' . ( 1 === $n ? 'it' : 'them' ) . ', whenever the lead came in.';
				}
				if ( count( $in ) > 1 ) {
					arsort( $in );
					foreach ( array_slice( $in, 0, self::TABLE_ROWS, true ) as $src => $c ) {
						$out[] = '  - ' . self::cell( '' === $src ? __( '(no source given)', 'zorderz' ) : $src ) . ': ' . self::n( $c['n'] );
					}
				}
				if ( $aside ) {
					$a     = self::sum( $aside );
					$out[] = '- ' . ( '' !== $g['aside_label'] ? $g['aside_label'] : __( 'Listed apart, not counted above', 'zorderz' ) ) . ': ' . self::plural( $a['n'], 'lead', 'leads' );
				}
				$b = self::billing( $gk, $f['period'] );
				if ( is_array( $b ) ) {
					$out[] = '- ' . sprintf(
						/* translators: 1: customers quoted, 2: estimates, 3: customers billed, 4: invoices */
						__( 'In the billing system, with this source: %1$s customers quoted (%2$s estimates), %3$s billed (%4$s invoices)', 'zorderz' ),
						self::n( $b['est_customers'] ), self::n( $b['estimates'] ), self::n( $b['customers'] ), self::n( $b['invoices'] )
					) . ( $money ? ', $' . number_format( $b['amount'], 2 ) : '' ) . '.';
				}
				$out[] = '';
				if ( $f['by_month'] && $t['months'] ) {
					$out = array_merge( $out, self::month_table( $t['months'] ) );
				}
			}
		} else {
			$rows = array();
			foreach ( $by as $src => $c ) {
				$k     = self::group_of( (string) $src );
				$label = '' !== $k ? $groups[ $k ]['label'] : ( '' === $src ? __( '(no source given)', 'zorderz' ) : (string) $src );
				$rows[ $label ] = self::sum( array( $rows[ $label ] ?? array(), $c ) );
			}
			uasort( $rows, function ( $a, $b ) use ( $f ) {
				$k = '' !== $f['status'] ? $f['status'] : 'n';
				return $b[ $k ] <=> $a[ $k ];
			} );
			$out[] = '**' . self::plural( $all['n'], 'lead', 'leads' ) . '** — ' . self::outcome( $all, $money );
			$out[] = '';
			$out[] = '| Source | Leads | Won |';
			$out[] = '|---|---:|---:|';
			$more  = array_slice( $rows, self::TABLE_ROWS - 1, null, true );
			foreach ( array_slice( $rows, 0, count( $rows ) > self::TABLE_ROWS ? self::TABLE_ROWS - 1 : self::TABLE_ROWS, true ) as $label => $c ) {
				$out[] = '| ' . self::cell( $label ) . ' | ' . self::n( $c['n'] ) . ' | ' . self::n( $c['won'] ) . ' |';
			}
			if ( count( $rows ) > self::TABLE_ROWS ) {
				$m     = self::sum( $more );
				$out[] = '| Everything else (' . self::n( count( $more ) ) . ') | ' . self::n( $m['n'] ) . ' | ' . self::n( $m['won'] ) . ' |';
			}
			$out[] = '';
			if ( $f['by_month'] && $all['months'] ) {
				$out = array_merge( $out, self::month_table( $all['months'] ) );
			}
		}
		$out[] = '_' . __( 'Leads are counted by the day they came in; won, lost and open is each lead\'s status now.', 'zorderz' ) . self::synced() . '_';
		return trim( implode( "\n", $out ) );
	}

	protected static function month_table( array $months ): array {
		ksort( $months );
		$rows = array( '| Month | Leads |', '|---|---:|' );
		foreach ( array_slice( $months, -self::TABLE_ROWS, null, true ) as $mk => $n ) {
			$rows[] = '| ' . gmdate( 'M Y', strtotime( $mk . '-01 00:00:00 UTC' ) ) . ' | ' . self::n( $n ) . ' |';
		}
		$rows[] = '';
		return $rows;
	}

	protected static function synced(): string {
		try {
			$t = strtotime( (string) ( ZANA_Ledger::coverage()['leads']['last_ok'] ?? '' ) . ' UTC' );
		} catch ( \Throwable $e ) {
			$t = false;
		}
		if ( ! $t ) {
			return '';
		}
		$min = (int) floor( ( time() - $t ) / 60 );
		return ' ' . ( $min < 1 ? __( 'Synced just now.', 'zorderz' ) : sprintf( /* translators: %d: minutes */ __( 'Synced %d min ago.', 'zorderz' ), $min ) );
	}

	/** The ask bar's short answer: what it is, then the count asked for (the chat has the rest). */
	public static function quick( array $f, array $ctx ): string {
		$f      = array_merge( array( 'groups' => array(), 'period' => null, 'status' => '', 'by_month' => false, 'breakdown' => true ), $f );
		$money  = ZANA_Ledger_Access::may( $ctx, 'company' );
		$by     = self::counts( $f['period'] );
		$when   = ZANA_Ledger_Periods::short( $f['period'] );
		$groups = self::groups();
		if ( ! $f['groups'] ) {
			$rows = array();
			foreach ( $by as $src => $c ) {
				$k     = self::group_of( (string) $src );
				$label = '' !== $k ? $groups[ $k ]['label'] : ( '' === $src ? __( '(no source given)', 'zorderz' ) : (string) $src );
				$rows[ $label ] = ( $rows[ $label ] ?? 0 ) + ( '' !== $f['status'] ? (int) $c[ $f['status'] ] : (int) $c['n'] );
			}
			arsort( $rows );
			$out  = array( '**' . ucfirst( trim( $f['status'] . ' leads by source' ) ) . ' · ' . $when . '**', '', '**' . self::plural( array_sum( $rows ), 'lead', 'leads' ) . '**', '', '| Source | Leads |', '|---|---:|' );
			$more = array_slice( $rows, 7, null, true );
			foreach ( array_slice( $rows, 0, count( $rows ) > 8 ? 7 : 8, true ) as $label => $n ) {
				$out[] = '| ' . self::cell( $label ) . ' | ' . self::n( $n ) . ' |';
			}
			if ( count( $rows ) > 8 ) {
				$out[] = '| Everything else (' . self::n( count( $more ) ) . ') | ' . self::n( array_sum( $more ) ) . ' |';
			}
			return implode( "\n", $out );
		}
		$titles = array();
		$figs   = array();
		foreach ( $f['groups'] as $gk ) {
			$g          = $groups[ $gk ];
			list( $in ) = self::split( $g, $by );
			$t          = self::sum( $in );
			$titles[]   = $g['label'];
			$fig        = '' !== $f['status'] ? '**' . self::n( $t[ $f['status'] ] ) . ' ' . $f['status'] . '** of ' . self::plural( $t['n'], 'lead', 'leads' ) : '**' . self::plural( $t['n'], 'lead', 'leads' ) . '**';
			$b          = self::billing( $gk, $f['period'] );
			if ( is_array( $b ) ) {
				$fig .= ' · ' . self::n( $b['est_customers'] ) . ' customers quoted, ' . self::n( $b['customers'] ) . ' billed' . ( $money ? ' ($' . number_format( round( $b['amount'] ) ) . ')' : '' );
			}
			$figs[] = ( count( $f['groups'] ) > 1 ? $g['label'] . ': ' : '' ) . $fig;
		}
		return '**' . implode( ' & ', $titles ) . ' leads · ' . $when . '**' . "\n\n" . implode( ' · ', $figs );
	}
}
