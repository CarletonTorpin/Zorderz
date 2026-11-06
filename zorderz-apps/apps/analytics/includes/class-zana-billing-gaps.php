<?php
/**
 * ZANA_Billing_Gaps — "which accepted estimates were never invoiced?", answered from the ledger's copy of the billing
 * system (its estimates carry the billing system's own "invoiced" mark and include archived ones).
 *
 * The answer is a join, so it is computed, never asked of a model. Each accepted, never-invoiced estimate in the window
 * is split by what happened to its customer since:
 *   - no invoice to the customer since (from a week before the estimate): the ones to read; the work may never have
 *     been billed, or the job was lost without the estimate being declined;
 *   - invoiced afterwards, but for less than these estimates add up to, once the estimates the billing system did link
 *     are taken out (short by more than $100 and 10%): read these next (company view only: a salesperson is never shown
 *     a customer's invoice totals);
 *   - invoiced afterwards, for at least as much: most likely billed on an invoice the billing system never linked.
 *
 * Who: the company view (every estimate), or a salesperson with the right to see their own figures (the estimates
 * under their own codes). Never a Privacy View account (the list names customers), the shared device or Demo Mode.
 * Claimed only while both billing copies are complete and synced in the last three hours.
 *
 * detect() also recognises "which jobs are done but not invoiced" and "invoices that were deleted"; those join the jobs
 * app's completed work and are the chat's to answer, so this class answers only 'accepted'.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Billing_Gaps {

	/** How far back the window reaches when the question names none. */
	const DEFAULT_MONTHS = 18;
	/** The billing copies must have synced within this many seconds. */
	const FRESH = 10800;
	/** Lines shown per section. */
	const SHOW = 25;
	/** Most accepted estimates one answer reads. */
	const MAX = 500;

	/** Injected in tests. */
	public static $now = null;
	public static $estimates = null; // callable( from, to, codes ) => accepted, never-invoiced rows
	public static $customer_docs = null; // callable( customer ids ) => { invoices: cid => [[date, amount]], linked: cid => [[date, amount]] }

	protected static function now(): int {
		return null !== self::$now ? (int) self::$now : time();
	}

	/**
	 * What the message asks: '' (not this), 'jobs' (done jobs not invoiced), 'deleted' (invoices deleted), 'accepted'
	 * (accepted estimates never invoiced). Pure.
	 */
	public static function detect( string $message ): string {
		$m = strtolower( str_replace( array( "\u{2019}", "\u{2018}", '`' ), "'", $message ) );
		$m = str_replace( array( '-', '_' ), ' ', $m );
		$m = trim( (string) preg_replace( '/\s+/', ' ', $m ) );
		if ( '' === $m || strlen( $m ) > 400 ) {
			return '';
		}
		// a request to MAKE or SEND an invoice is an action, not this report
		if ( preg_match( '/(?:^|[.!?;,:]\s*)(?:(?:please|pls|can you|could you|now|then|and|also)\s+)?(?:(?:create|make|send|draft|write|generate|build|prepare|email|convert)\b(?: [\w\']+){0,3}? (?:invoices?|bills?|it|them)\b|invoice (?:the|them|it|those|these|all|every)\b)/', $m )
			|| preg_match( '/\b(?:please|pls|can you|could you)\s+(?:\w+\s+){0,2}(?:create|make|send|draft|generate|prepare|convert)\b/', $m ) ) {
			return '';
		}
		$done_w = '(?:complete|completed|done|finished|closed|wrapped up)';
		if ( preg_match( "/(?:\\bnot|n't|\\bnever)\\s+(?:yet\\s+|been\\s+|fully\\s+|all\\s+)*{$done_w}\\b/", $m ) ) {
			return '';
		}
		$work     = (bool) preg_match( '/\b(?:jobs|installs|installations|projects|work|appointments|visits)\b/', $m )
			|| ( preg_match( '/\b(?:job|install|installation|project|appointment|visit)\b/', $m ) && preg_match( '/\b(?:which|any|list|show|all|every|how many|what)\b/', $m ) );
		$done     = (bool) preg_match( "/\\b{$done_w}\\b|\\b(?:installed|visited|serviced|happened)\\b|\\bdid (?:we |you |they |the crew )?(?:do|install|finish|complete|service|visit)\\b|\\bwent out\\b|\\bpast (?:appointments|visits|installs)\\b/", $m );
		$unbilled = (bool) preg_match( "/\\bun ?(?:invoiced|billed)\\b|(?:\\bnot|n't|\\bnever|\\bwithout|\\bno|\\bmissing|\\byet to)\\b(?: [\\w']+){0,3}? (?:invoic\\w*|bill(?:ed|ing)?)\\b|\\bneed(?:s|ing)? (?:to be )?(?:invoic\\w*|bill(?:ed|ing)?)\\b|\\b(?:awaiting|pending|waiting (?:on|for)) (?:an? )?(?:invoic\\w*|bill(?:ing)?)\\b/", $m );
		$est_w    = (bool) preg_match( '/\b(?:estimates?|quotes?|proposals?|bids?)\b/', $m );
		$accept_w = (bool) preg_match( '/\b(?:accepted|approved|signed|won|awarded)\b/', $m );
		$turned   = (bool) preg_match( "/\\b(?:never|not|n't|wasn't|weren't)\\b(?: [\\w']+){0,2}? (?:turned|converted|made|changed)(?: (?:in)?to| into)? (?:an? )?invoices?\\b/", $m );
		if ( ! ( $work && $done ) && $est_w && ( ( $accept_w && ( $unbilled || $turned ) ) || preg_match( '/\b(?:awaiting|pending|waiting (?:on|for)) (?:an? )?(?:invoic\w*|bill(?:ing)?)\b/', $m ) ) ) {
			return 'accepted';
		}
		if ( preg_match( '/\b(?:schedul\w*|quot(?:e|ed|es|ing)|sold|sell|selling|book(?:ed|ing)?|deposits?)\b/', $m )
			&& ! preg_match( "/\\b{$done_w}\\b|\\b(?:installed|visited|serviced)\\b/", $m ) ) {
			return '';
		}
		$asks_billed = (bool) preg_match( '/^(?:have|has|were)\b.*\b(?:all|every|each)\b.*\b(?:been )?(?:invoiced|billed)\b/', $m );
		if ( $work && $done && ( $unbilled || $asks_billed ) ) {
			return 'jobs';
		}
		$deleted = (bool) preg_match( '/\b(?:deleted|removed|voided)\b(?: [\w\']+){0,3}? invoices?\b|\binvoices?\b(?: [\w\']+){0,5}? (?:(?:was|were|got|been|is|are) )?(?:deleted|removed|no longer exists?|gone)\b/', $m );
		if ( $deleted && preg_match( '/\b(?:jobs?|estimates?|projects?|accepted|complete|completed|done)\b/', $m ) ) {
			return 'deleted';
		}
		return '';
	}

	/**
	 * The window a message names: array( since Y-m-d, until Y-m-d, words, read ). Read in UTC days; with nothing named,
	 * the last DEFAULT_MONTHS months (read = false).
	 */
	public static function window_from( string $message, $now = null ): array {
		$now   = null !== $now ? (int) $now : self::now();
		$m     = strtolower( $message );
		$today = gmdate( 'Y-m-d', $now );
		$yr    = (int) gmdate( 'Y', $now );
		$mons  = array( 'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12 );
		$mon_re = '(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?';
		$fmt   = function ( $d ) { return gmdate( 'M j, Y', strtotime( $d . ' 00:00:00 UTC' ) ); };
		if ( preg_match( '/\b(?:since|from|after) (?:the (?:start|beginning) of )?' . $mon_re . '(?:,? (20\d\d))?\b/', $m, $w ) ) {
			$mo    = $mons[ $w[1] ];
			$y     = isset( $w[2] ) && '' !== $w[2] ? (int) $w[2] : ( $mo <= (int) gmdate( 'n', $now ) ? $yr : $yr - 1 );
			$since = sprintf( '%04d-%02d-01', $y, $mo );
			return array( $since, $today, 'since ' . $fmt( $since ), true );
		}
		if ( preg_match( '/\b(?:since|from|after) (?:the (?:start|beginning) of )?(20\d\d)\b/', $m, $w ) ) {
			return array( $w[1] . '-01-01', $today, 'since ' . $fmt( $w[1] . '-01-01' ), true );
		}
		if ( preg_match( '/\b(?:in|during|for|of) (20\d\d)\b/', $m, $w ) ) {
			$y = (int) $w[1];
			return array( $y . '-01-01', min( $today, $y . '-12-31' ), 'in ' . $y, true );
		}
		if ( preg_match( '/\bthis month\b|\bmonth to date\b|\bmtd\b/', $m ) ) {
			return array( gmdate( 'Y-m-01', $now ), $today, 'this month', true );
		}
		if ( preg_match( '/\blast month\b/', $m ) ) {
			$first = gmdate( 'Y-m-01', strtotime( gmdate( 'Y-m-01', $now ) . ' -1 month' ) );
			return array( $first, gmdate( 'Y-m-t', strtotime( $first ) ), 'in ' . gmdate( 'F Y', strtotime( $first ) ), true );
		}
		if ( preg_match( '/\bthis year\b|\bytd\b|\byear to date\b/', $m ) ) {
			return array( $yr . '-01-01', $today, 'this year', true );
		}
		if ( preg_match( '/\blast year\b/', $m ) ) {
			return array( ( $yr - 1 ) . '-01-01', ( $yr - 1 ) . '-12-31', 'in ' . ( $yr - 1 ), true );
		}
		$words = array( 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'nine' => 9, 'twelve' => 12, 'eighteen' => 18, 'twenty four' => 24 );
		if ( preg_match( '/\b(?:last|past|previous) (\d{1,3}|one|two|three|four|five|six|nine|twelve|eighteen|twenty four) (days?|weeks?|months?|years?)\b/', $m, $w ) ) {
			$n    = ctype_digit( $w[1] ) ? (int) $w[1] : $words[ $w[1] ];
			$unit = rtrim( $w[2], 's' );
			return array( gmdate( 'Y-m-d', strtotime( '-' . max( 1, $n ) . ' ' . $unit . 's', $now ) ), $today, 'in the last ' . $n . ' ' . $unit . ( 1 === $n ? '' : 's' ), true );
		}
		if ( preg_match( '/\b(?:ever|all time|all[- ]time|any time|anytime|of all time)\b/', $m ) ) {
			return array( '1990-01-01', $today, 'at any time', true );
		}
		return array( gmdate( 'Y-m-d', strtotime( '-' . self::DEFAULT_MONTHS . ' months', $now ) ), $today, 'in the last ' . self::DEFAULT_MONTHS . ' months', false );
	}

	/** Both billing copies complete and synced within FRESH? '' when yes, else the sentence. */
	protected static function not_ready(): string {
		try {
			$cov = ZANA_Ledger::coverage();
		} catch ( \Throwable $e ) {
			return __( 'The billing copy is not set up on this site yet.', 'zorderz' );
		}
		foreach ( array( 'invoices', 'estimates' ) as $k ) {
			$t = strtotime( (string) ( $cov[ $k ]['last_ok'] ?? '' ) . ' UTC' );
			if ( empty( $cov[ $k ]['complete'] ) || ! $t || self::now() - $t > self::FRESH ) {
				return __( 'I can\'t list accepted estimates that were never invoiced right now: the billing copy isn\'t fully loaded or hasn\'t synced in the last few hours. Please ask again later.', 'zorderz' );
			}
		}
		return '';
	}

	/** Accepted, never-invoiced, not-deleted estimates dated in the window (newest first), for these codes ([] = all). */
	protected static function accepted_rows( string $since, string $until, array $codes ): array {
		if ( is_callable( self::$estimates ) ) {
			return (array) call_user_func( self::$estimates, $since, $until, $codes );
		}
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT estimate_id, estimate_number, customer_id, customer_name, est_date, amount, rep_code, vis_state FROM ' . ZANA_Ledger_Store::table( 'estimates' ) // phpcs:ignore WordPress.DB.PreparedSQL -- fixed table name.
			. " WHERE accepted = 1 AND invoiced = 0 AND vis_state IN (0, 2) AND LOWER(status_label) <> 'draft' AND est_date >= %s AND est_date <= %s ORDER BY est_date DESC, estimate_id DESC LIMIT %d",
			$since, $until, self::MAX * 4
		), ARRAY_A );
		ZANA_Ledger_Figures::db_ok();
		if ( $codes ) {
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $codes ) { return ZANA_Ledger_Access::rep_matches( (string) $r['rep_code'], $codes ); } ) );
		}
		return $rows;
	}

	/** Every issued invoice (date, amount) of these customers, and their accepted estimates the billing system did link. */
	protected static function customer_docs( array $cids ): array {
		if ( is_callable( self::$customer_docs ) ) {
			return (array) call_user_func( self::$customer_docs, $cids );
		}
		global $wpdb;
		$out = array( 'invoices' => array(), 'linked' => array() );
		foreach ( array_chunk( array_map( 'intval', $cids ), 500 ) as $chunk ) {
			$in = implode( ',', $chunk );
			foreach ( (array) $wpdb->get_results( 'SELECT customer_id, inv_date, amount FROM ' . ZANA_Ledger_Store::table( 'invoices' ) . " WHERE customer_id IN ({$in}) AND vis_state <> 1 AND status <> 0", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL -- integer ids, fixed table.
				$out['invoices'][ (int) $r['customer_id'] ][] = array( (string) $r['inv_date'], (float) $r['amount'] );
			}
			foreach ( (array) $wpdb->get_results( 'SELECT customer_id, est_date, amount FROM ' . ZANA_Ledger_Store::table( 'estimates' ) . " WHERE customer_id IN ({$in}) AND accepted = 1 AND invoiced = 1 AND vis_state <> 1", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL -- integer ids, fixed table.
				$out['linked'][ (int) $r['customer_id'] ][] = array( (string) $r['est_date'], (float) $r['amount'] );
			}
		}
		ZANA_Ledger_Figures::db_ok();
		return $out;
	}

	/**
	 * The split, pure: { open[], short[], later[], open_total, cut }. $company: the full view (with the "invoiced for
	 * less" check, which reads a customer's invoice totals).
	 */
	public static function split( array $rows, array $docs, bool $company ): array {
		$cut  = count( $rows ) > self::MAX;
		$rows = array_slice( $rows, 0, self::MAX );
		$inv  = (array) ( $docs['invoices'] ?? array() );
		$link = (array) ( $docs['linked'] ?? array() );
		$open = array();
		$later = array();
		$open_total = 0.0;
		foreach ( $rows as $r ) {
			$cid    = (int) $r['customer_id'];
			$dates  = array_column( $inv[ $cid ] ?? array(), 0 );
			$cutoff = gmdate( 'Y-m-d', strtotime( (string) $r['est_date'] . ' 00:00:00 UTC -7 days' ) );
			$after  = array_values( array_filter( $dates, function ( $d ) use ( $cutoff ) { return $d >= $cutoff; } ) );
			$before = array_values( array_filter( $dates, function ( $d ) use ( $cutoff ) { return $d < $cutoff; } ) );
			$row    = array(
				'customer'        => (string) $r['customer_name'],
				'customer_id'     => $cid,
				'estimate_number' => (string) $r['estimate_number'],
				'est_date'        => (string) $r['est_date'],
				'cutoff'          => $cutoff,
				'amount'          => (float) $r['amount'],
				'archived'        => 2 === (int) $r['vis_state'],
			);
			if ( $after ) {
				sort( $after );
				$row['first_after'] = $after[0];
				$row['count_after'] = count( $after );
				$later[]            = $row;
			} else {
				rsort( $before );
				$row['last_before'] = $before[0] ?? '';
				$open[]             = $row;
				$open_total        += (float) $r['amount'];
			}
		}
		$short = array();
		if ( $later && $company ) {
			$by = array();
			foreach ( $later as $o ) {
				$c = $o['customer_id'];
				if ( ! isset( $by[ $c ] ) ) {
					$by[ $c ] = array( 'customer' => $o['customer'], 'rows' => array(), 'u' => 0.0, 'from' => $o['cutoff'] );
				}
				$by[ $c ]['rows'][] = $o;
				$by[ $c ]['u']     += $o['amount'];
				$by[ $c ]['from']   = min( $by[ $c ]['from'], $o['cutoff'] );
			}
			foreach ( $by as $c => $g ) {
				$i = 0.0;
				foreach ( $inv[ $c ] ?? array() as $x ) {
					if ( $x[0] >= $g['from'] ) {
						$i += $x[1];
					}
				}
				$l     = 0.0;
				$lfrom = gmdate( 'Y-m-d', strtotime( $g['from'] . ' 00:00:00 UTC -90 days' ) );
				foreach ( $link[ $c ] ?? array() as $x ) {
					if ( $x[0] >= $lfrom ) {
						$l += $x[1];
					}
				}
				$gap = round( $g['u'] - max( 0.0, $i - $l ), 2 );
				if ( $gap > 100 && $gap > 0.1 * $g['u'] ) {
					$short[ $c ] = $g + array( 'i' => $i, 'l' => $l, 'gap' => $gap );
				}
			}
			uasort( $short, function ( $a, $b ) { return $b['gap'] <=> $a['gap']; } );
			$later = array_values( array_filter( $later, function ( $o ) use ( $short ) { return ! isset( $short[ $o['customer_id'] ] ); } ) );
		}
		return array( 'open' => $open, 'short' => array_values( $short ), 'later' => $later, 'open_total' => $open_total, 'cut' => $cut, 'n' => count( $rows ) );
	}

	protected static function clean( $s ): string {
		$t = (string) preg_replace( '/[<>\[\]*_`~\\\\|]/', '', (string) $s );
		return trim( (string) preg_replace( '/\s+/', ' ', $t ) );
	}

	protected static function day( string $ymd ): string {
		$t = strtotime( $ymd . ' 12:00:00 UTC' );
		return $t ? gmdate( 'M j, Y', $t ) : '-';
	}

	protected static function line( array $o, string $tail ): string {
		$name = self::clean( $o['customer'] );
		$enum = self::clean( $o['estimate_number'] );
		return '- **' . ( '' !== $name ? $name : __( 'Unnamed customer', 'zorderz' ) ) . '** · ' . ( '' !== $enum ? 'estimate #' . $enum : 'estimate (no number)' )
			. ' of ' . self::day( $o['est_date'] ) . ( ! empty( $o['archived'] ) ? ' (archived)' : '' )
			. ' · $' . number_format( (float) $o['amount'], 2 ) . ' · ' . $tail;
	}

	/** The answer (markdown) for this person, or a sentence saying why not. */
	public static function accepted_answer( int $uid, string $message ): string {
		$ctx = ZANA_Ledger_Access::context( $uid );
		if ( ZANA_Ledger_Access::may( $ctx, 'list' ) ) {
			$codes   = array();
			$company = true;
		} elseif ( ZANA_Ledger_Access::may( $ctx, 'list_own' ) ) {
			$codes   = $ctx['codes'];
			$company = false;
		} else {
			return __( 'Accepted estimates that were never invoiced are shown to the company view and to the salesperson on them.', 'zorderz' );
		}
		$why = self::not_ready();
		if ( '' !== $why ) {
			return $why;
		}
		list( $since, $until, $label, $read ) = self::window_from( $message );
		$rows = self::accepted_rows( $since, $until, $codes );
		$cids = array_values( array_unique( array_filter( array_map( function ( $r ) { return (int) $r['customer_id']; }, array_slice( $rows, 0, self::MAX ) ) ) ) );
		$s    = self::split( $rows, $cids ? self::customer_docs( $cids ) : array(), $company );

		$whose = $company ? '' : ' under your codes';
		$out   = array();
		if ( 0 === $s['n'] ) {
			$out[] = 'No accepted estimate' . $whose . ' ' . $label . ' is left un-invoiced in the billing system.';
		} else {
			$out[] = '**Accepted estimates' . $whose . ' never invoiced, ' . $label . ' — ' . number_format( $s['n'] ) . ( $s['cut'] ? '+' : '' ) . '**';
		}
		if ( $s['open'] ) {
			$out[] = '';
			$out[] = '**No invoice to the customer since — ' . count( $s['open'] ) . ( $s['open_total'] > 0 ? ' · $' . number_format( $s['open_total'], 2 ) : '' ) . '**';
			$out[] = '_These are the ones to read: the work may never have been billed, or the job was lost without the estimate being declined._';
			foreach ( array_slice( $s['open'], 0, self::SHOW ) as $o ) {
				$out[] = self::line( $o, '' !== $o['last_before'] ? 'last invoiced ' . self::day( $o['last_before'] ) : 'no earlier invoice on file' );
			}
			if ( count( $s['open'] ) > self::SHOW ) {
				$out[] = '- …and ' . ( count( $s['open'] ) - self::SHOW ) . ' more';
			}
		}
		if ( $s['short'] ) {
			$gap   = array_sum( array_column( $s['short'], 'gap' ) );
			$n     = array_sum( array_map( function ( $g ) { return count( $g['rows'] ); }, $s['short'] ) );
			$out[] = '';
			$out[] = '**Invoiced afterwards, but for less — ' . $n . ' · $' . number_format( $gap, 2 ) . ' not accounted for**';
			$out[] = '_The customer was invoiced after these estimates, but for less than they add up to once the estimates the billing system did link are taken out. Read these next._';
			foreach ( array_slice( $s['short'], 0, self::SHOW ) as $g ) {
				$name  = self::clean( $g['customer'] );
				$nums  = array_map( function ( $o ) { $e = self::clean( $o['estimate_number'] ); return '' !== $e ? '#' . $e : '(no number)'; }, $g['rows'] );
				$out[] = '- **' . ( '' !== $name ? $name : __( 'Unnamed customer', 'zorderz' ) ) . '** · ' . count( $g['rows'] ) . ' estimate' . ( 1 === count( $g['rows'] ) ? '' : 's' )
					. ' ($' . number_format( $g['u'], 2 ) . ': ' . implode( ', ', $nums ) . ') · invoiced $' . number_format( $g['i'], 2 ) . ' since ' . self::day( $g['from'] )
					. ( $g['l'] > 0 ? ', of which $' . number_format( $g['l'], 2 ) . ' for estimates the billing system linked' : '' )
					. ' · **$' . number_format( $g['gap'], 2 ) . ' not accounted for**';
			}
		}
		if ( $s['later'] ) {
			$out[] = '';
			$out[] = '**The customer was invoiced afterwards' . ( $company ? ', and for at least as much' : '' ) . ' — ' . count( $s['later'] ) . '**';
			$out[] = '_Most likely billed on an invoice the billing system never linked to the estimate._';
			foreach ( array_slice( $s['later'], 0, self::SHOW ) as $o ) {
				$out[] = self::line( $o, 'invoiced ' . self::day( $o['first_after'] ) . ( $o['count_after'] > 1 ? ' (+' . ( $o['count_after'] - 1 ) . ' more)' : '' ) );
			}
			if ( count( $s['later'] ) > self::SHOW ) {
				$out[] = '- …and ' . ( count( $s['later'] ) - self::SHOW ) . ' more';
			}
		}
		$foot = array( 'From the billing copy: the billing system\'s own "invoiced" mark on each accepted estimate, archived estimates included, deleted ones left out.' );
		if ( ! $read && preg_match( '/\b(?:since|before|after|between|during|last|past|this|20\d\d|january|february|march|april|june|july|august|september|october|november|december)\b/', strtolower( $message ) ) ) {
			$foot[] = 'I couldn\'t read the time period in the question, so this covers the last ' . self::DEFAULT_MONTHS . ' months.';
		}
		if ( $s['cut'] ) {
			$foot[] = 'Only the newest ' . number_format( self::MAX ) . ' are counted; name a shorter period to see the rest.';
		}
		$out[] = '';
		$out[] = '_' . implode( ' ', $foot ) . '_';
		return trim( implode( "\n", $out ) );
	}
}
