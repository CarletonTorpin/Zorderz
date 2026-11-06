<?php
/**
 * ZANA_Ledger_Figures — the sums every ledger answer, home tile and quick answer is made of.
 *
 * One rule per figure, so the chat, the ask bar and the home screen agree to the cent:
 *
 *   sales       invoices dated in the period at their full amount, paid or not; drafts, $0 and deleted invoices left
 *               out, archived ones included. A brand or product-line filter counts only the lines that name it.
 *   estimates   estimates dated in the period, drafts and deleted left out; accepted, invoiced, declined, still open.
 *   owed        what is still outstanding on open invoices (any date, or issued in / due in the period), by days past
 *               the due date; archived invoices with a balance are named apart.
 *   paid        invoices paid in full in the period (by the day the last of it was paid), and the days each took.
 *   customers   customers billed in the period: new (their first invoice ever is in it), returning, repeat (2+ jobs).
 *   received    payments dated in the period, on any invoice (money by the day it arrived); credits apart.
 *   leads       CRM leads created in a window, won and lost by the day they closed, open now.
 *
 * Filters: salesperson codes, town, postal code, lead-source group, brand, product line. Groupings: month, year,
 * salesperson, town, product line, brand, lead source, customer.
 *
 * Reads only the ledger's tables. A failed query throws (an answer is never a zero that hides a failure).
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Ledger_Figures {

	/** Injected in tests: callable( $kind, $period, $date_col ) => rows (then filtered here as the database would). */
	public static $rows = null;

	/** The billing system's payment type for a credit applied as a payment. */
	const CREDIT_TYPE = 'credit';

	/** Every filter, empty. */
	public static function filters( array $f = array() ): array {
		return array_merge( array(
			'family'   => 'sales',
			'reps'     => array(),
			'city'     => '',
			'zip'      => '',
			'source'   => '',
			'brand'    => '',
			'category' => '',
			'group'    => '',
			'top'      => 0,
			'dates'    => true,
			'ask'      => array(),
		), $f );
	}

	/* ─────────────────────────── reading ─────────────────────────── */

	/**
	 * Rows of one kind dated in the period ($p null: every dated row). $date_col: '' (the document date), 'due' (the due
	 * date, else the issue date), 'paid' (the day paid in full).
	 */
	public static function rows( string $kind, $p, string $date_col = '' ): array {
		if ( is_callable( self::$rows ) ) {
			$rows = (array) call_user_func( self::$rows, $kind, $p, $date_col );
		} else {
			$rows = self::db_rows( $kind, $p, $date_col );
		}
		$out = array();
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) || 1 === (int) ( $r['vis_state'] ?? 0 ) ) {
				continue;
			}
			if ( 'invoices' === $kind && 0 === (int) ( $r['status'] ?? 1 ) ) {
				continue; // a draft is never a sale
			}
			$d = self::date_of( $r, $date_col );
			if ( '' === $d || ( $p && ( $d < $p[0] || $d > $p[1] ) ) ) {
				continue;
			}
			$out[] = $r;
		}
		return $out;
	}

	/** The date a row is placed by. */
	public static function date_of( array $r, string $date_col ): string {
		if ( 'due' === $date_col ) {
			$d = (string) ( $r['due_date'] ?? '' );
			return '' !== $d ? substr( $d, 0, 10 ) : substr( (string) ( $r['d'] ?? '' ), 0, 10 );
		}
		if ( 'paid' === $date_col ) {
			return substr( (string) ( $r['paid_date'] ?? '' ), 0, 10 );
		}
		return substr( (string) ( $r['d'] ?? '' ), 0, 10 );
	}

	protected static function db_rows( string $kind, $p, string $date_col ): array {
		global $wpdb;
		switch ( $kind ) {
			case 'estimates':
				$cols = "estimate_id AS id, estimate_number, customer_id, customer_name, city, '' AS zip, est_date AS d, amount, accepted, invoiced, status_label, vis_state, lead_source, rep_code, mix, brands";
				$dc   = 'est_date';
				break;
			case 'payments':
				$cols = 'payment_id AS id, invoice_id, pay_date AS d, amount, pay_type, vis_state';
				$dc   = 'pay_date';
				break;
			default:
				$kind = 'invoices';
				$cols = 'invoice_id AS id, invoice_number, customer_id, customer_name, city, zip, inv_date AS d, due_date, paid_date, amount, paid, outstanding, vis_state, status, lead_source, rep_code, mix, brands';
				$dc   = 'due' === $date_col ? 'COALESCE(due_date, inv_date)' : ( 'paid' === $date_col ? 'paid_date' : 'inv_date' );
		}
		$t   = ZANA_Ledger_Store::table( $kind );
		$sql = "SELECT {$cols} FROM {$t} WHERE vis_state <> 1";
		$sql = $p ? $wpdb->prepare( $sql . " AND {$dc} >= %s AND {$dc} <= %s", $p[0], $p[1] ) : $sql . " AND {$dc} IS NOT NULL"; // phpcs:ignore WordPress.DB.PreparedSQL -- fixed names.
		$out = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- prepared above.
		self::db_ok();
		return is_array( $out ) ? $out : array();
	}

	/** A query that failed is an error, never zeros. */
	public static function db_ok(): void {
		global $wpdb;
		if ( is_object( $wpdb ) && '' !== (string) ( $wpdb->last_error ?? '' ) ) {
			throw new RuntimeException( 'db' );
		}
	}

	/* ─────────────────────────── filters ─────────────────────────── */

	/** A town as one label: the cleaned label, a trailing region code or postal code taken off, words capitalised. */
	public static function city_label( $raw ): string {
		$l = ZANA_Ledger_Enrich::label( (string) $raw, true );
		$l = trim( (string) preg_replace( '/[\s,]+[A-Za-z]{2}\.?(?:[\s,]+\d{3,10}(?:-\d{4})?)?\s*$|[\s,]+\d{3,10}(?:-\d{4})?\s*$/', '', $l ) );
		$l = trim( (string) preg_replace( '/\s+/', ' ', $l ), ' ,.' );
		return '' === $l ? '' : ucwords( strtolower( $l ) );
	}

	/** Does a row pass the filters (salesperson, town, postal code, lead-source group)? */
	public static function passes( array $f, array $r ): bool {
		if ( $f['reps'] && ! ZANA_Ledger_Access::rep_matches( (string) ( $r['rep_code'] ?? '' ), (array) $f['reps'] ) ) {
			return false;
		}
		if ( '' !== $f['city'] && strtolower( self::city_label( $r['city'] ?? '' ) ) !== strtolower( $f['city'] ) ) {
			return false;
		}
		if ( '' !== $f['zip'] && 0 !== strpos( (string) ( $r['zip'] ?? '' ), (string) $f['zip'] ) ) {
			return false;
		}
		if ( '' !== $f['source'] ) {
			$g = ZANA_Lead_Sources::group( $f['source'] );
			if ( ! $g || ! $g['billing'] || ! ZANA_Ledger_Rules::matches( (string) ( $r['lead_source'] ?? '' ), $g['billing'], $g['except'] ) ) {
				return false;
			}
		}
		return true;
	}

	public static function json( $s ): array {
		$a = json_decode( (string) $s, true );
		return is_array( $a ) ? $a : array();
	}

	/** The row's figure: the whole document, or only the lines of the brand / product line asked about. */
	public static function value( array $f, array $r ): float {
		if ( '' !== $f['brand'] ) {
			return (float) ( self::json( $r['brands'] ?? '' )[ $f['brand'] ] ?? 0 );
		}
		if ( '' !== $f['category'] ) {
			return (float) ( self::json( $r['mix'] ?? '' )[ $f['category'] ] ?? 0 );
		}
		return (float) ( $r['amount'] ?? 0 );
	}

	/** The row's group label(s) => its share of the value (a product-line or brand grouping splits one document). */
	public static function keys( array $f, array $r, float $v ): array {
		switch ( $f['group'] ) {
			case 'month':
				return array( substr( (string) $r['d'], 0, 7 ) => $v );
			case 'year':
				return array( substr( (string) $r['d'], 0, 4 ) => $v );
			case 'rep':
				$codes = ZANA_Ledger_Access::clean_codes( array( (string) ( $r['rep_code'] ?? '' ) ) );
				if ( ! $codes ) {
					return array( __( '(no salesperson)', 'zorderz' ) => $v );
				}
				$map = ZANA_Ledger_Access::rep_map();
				$out = array();
				foreach ( $codes as $c ) {
					$out[ $map[ $c ] ?? $c ] = $v / count( $codes ); // a shared job is split between its people
				}
				return $out;
			case 'city':
				$c = self::city_label( $r['city'] ?? '' );
				return array( '' !== $c ? $c : __( '(no town)', 'zorderz' ) => $v );
			case 'customer':
				$n = trim( (string) ( $r['customer_name'] ?? '' ) );
				return array( (int) ( $r['customer_id'] ?? 0 ) . '|' . ( '' !== $n ? $n : __( 'Unnamed customer', 'zorderz' ) ) => $v );
			case 'source':
				return array( ZANA_Lead_Sources::billing_label( (string) ( $r['lead_source'] ?? '' ) ) => $v );
			case 'category':
			case 'brand':
				$out = array();
				foreach ( self::json( 'brand' === $f['group'] ? ( $r['brands'] ?? '' ) : ( $r['mix'] ?? '' ) ) as $k => $a ) {
					if ( (float) $a > 0 ) {
						$out[ (string) $k ] = (float) $a;
					}
				}
				return $out;
		}
		return array( '' => $v );
	}

	protected static function add_group( array &$groups, string $k, float $v, array $extra = array() ): void {
		if ( ! isset( $groups[ $k ] ) ) {
			$groups[ $k ] = array( 'value' => 0.0, 'jobs' => 0 ) + array_map( function ( $x ) { return is_array( $x ) ? array() : 0; }, $extra );
		}
		$groups[ $k ]['value'] += $v;
		$groups[ $k ]['jobs']++;
		foreach ( $extra as $ek => $ev ) {
			if ( is_array( $ev ) ) {
				$groups[ $k ][ $ek ] = array_merge( $groups[ $k ][ $ek ], $ev );
			} else {
				$groups[ $k ][ $ek ] += $ev;
			}
		}
	}

	/* ─────────────────────────── the figures ─────────────────────────── */

	/** Sales: { value, jobs, docs_total, paid, owed, customers (count), groups label => {value, jobs} }. */
	public static function sales( array $f, $p ): array {
		$f    = self::filters( $f );
		$out  = array( 'value' => 0.0, 'jobs' => 0, 'docs_total' => 0.0, 'paid' => 0.0, 'owed' => 0.0, 'groups' => array(), 'customers' => 0 );
		$seen = array();
		foreach ( self::rows( 'invoices', $p ) as $r ) {
			if ( (float) $r['amount'] <= 0 || ! self::passes( $f, $r ) ) {
				continue;
			}
			$v = self::value( $f, $r );
			if ( $v <= 0.004 ) {
				continue;
			}
			$out['value'] += $v;
			$out['jobs']++;
			if ( (int) ( $r['customer_id'] ?? 0 ) > 0 ) {
				$seen[ (int) $r['customer_id'] ] = true;
			}
			$out['docs_total'] += (float) $r['amount'];
			$out['paid']       += (float) ( $r['paid'] ?? 0 );
			$out['owed']       += (float) ( $r['outstanding'] ?? 0 );
			foreach ( self::keys( $f, $r, $v ) as $k => $kv ) {
				self::add_group( $out['groups'], (string) $k, $kv );
			}
		}
		$out['customers'] = count( $seen );
		foreach ( array( 'value', 'docs_total', 'paid', 'owed' ) as $k ) {
			$out[ $k ] = round( $out[ $k ], 2 );
		}
		return $out;
	}

	/** Estimates: { n, value, accepted, accepted_value, invoiced, invoiced_value, either, declined, open, open_value, drafts, groups }. */
	public static function estimates( array $f, $p ): array {
		$f   = self::filters( $f );
		$out = array( 'n' => 0, 'value' => 0.0, 'accepted' => 0, 'accepted_value' => 0.0, 'invoiced' => 0, 'invoiced_value' => 0.0,
			'either' => 0, 'declined' => 0, 'open' => 0, 'open_value' => 0.0, 'drafts' => 0, 'groups' => array() );
		foreach ( self::rows( 'estimates', $p ) as $r ) {
			if ( ! self::passes( $f, $r ) ) {
				continue;
			}
			$label = strtolower( (string) ( $r['status_label'] ?? '' ) );
			if ( 'draft' === $label ) {
				$out['drafts']++;
				continue;
			}
			$v = self::value( $f, $r );
			if ( ( '' !== $f['brand'] || '' !== $f['category'] ) && $v <= 0.004 ) {
				continue;
			}
			$out['n']++;
			$out['value'] += $v;
			$acc = ! empty( $r['accepted'] );
			$inv = ! empty( $r['invoiced'] );
			if ( $acc ) {
				$out['accepted']++;
				$out['accepted_value'] += $v;
			}
			if ( $inv ) {
				$out['invoiced']++;
				$out['invoiced_value'] += $v;
			}
			if ( $acc || $inv ) {
				$out['either']++;
			} elseif ( false !== strpos( $label, 'declin' ) ) {
				$out['declined']++;
			} else {
				$out['open']++;
				$out['open_value'] += $v;
			}
			foreach ( self::keys( $f, $r, $v ) as $k => $kv ) {
				self::add_group( $out['groups'], (string) $k, $kv, array( 'won' => $inv ? 1 : 0 ) );
			}
		}
		return $out;
	}

	/**
	 * Money still owed: { n, owed, face, arch_n, arch_owed, ages (5 buckets {n, owed}), over_n, over_owed, oldest, groups,
	 * by_due, undated_due }. With due dates (the default) the buckets are days past due: not yet due, 1-30, 31-60, 61-90,
	 * over 90; an open invoice with no due date is due on its issue date (due on receipt). Without them, days since
	 * issue: 0-30, 31-60, 61-90, 91-180, over 180. $due_period: the period is of due dates ("due this week").
	 */
	public static function owed( array $f, $p, bool $due_period = false ): array {
		$f     = self::filters( $f );
		$today = ZANA_Ledger_Periods::today();
		$dates = ! empty( $f['dates'] );
		$out   = array( 'n' => 0, 'owed' => 0.0, 'face' => 0.0, 'arch_n' => 0, 'arch_owed' => 0.0,
			'ages' => array_fill( 0, 5, array( 'n' => 0, 'owed' => 0.0 ) ), 'groups' => array(), 'oldest' => '',
			'by_due' => $dates, 'over_n' => 0, 'over_owed' => 0.0, 'undated_due' => 0 );
		foreach ( self::rows( 'invoices', $p, $dates && $due_period ? 'due' : '' ) as $r ) {
			$o = (float) ( $r['outstanding'] ?? 0 );
			if ( $o <= 0.004 || ! self::passes( $f, $r ) ) {
				continue;
			}
			if ( 2 === (int) ( $r['vis_state'] ?? 0 ) ) {
				$out['arch_n']++;
				$out['arch_owed'] += $o;
				continue;
			}
			$d   = substr( (string) ( $r['d'] ?? '' ), 0, 10 );
			$due = $dates ? substr( (string) ( $r['due_date'] ?? '' ), 0, 10 ) : '';
			if ( $dates && '' === $due && '' !== $d ) {
				$due = $d;
				$out['undated_due']++;
			}
			if ( '' !== $due && $due < $today ) {
				$out['over_n']++;
				$out['over_owed'] += $o;
			}
			$out['n']++;
			$out['owed'] += $o;
			$out['face'] += (float) $r['amount'];
			$from = $dates ? $due : $d;
			$days = '' !== $from ? (int) round( ( strtotime( $today . ' 12:00:00 UTC' ) - strtotime( $from . ' 12:00:00 UTC' ) ) / 86400 ) : 9999;
			$b    = $dates
				? ( $days <= 0 ? 0 : ( $days <= 30 ? 1 : ( $days <= 60 ? 2 : ( $days <= 90 ? 3 : 4 ) ) ) )
				: ( $days <= 30 ? 0 : ( $days <= 60 ? 1 : ( $days <= 90 ? 2 : ( $days <= 180 ? 3 : 4 ) ) ) );
			$out['ages'][ $b ]['n']++;
			$out['ages'][ $b ]['owed'] += $o;
			if ( '' !== $d && ( '' === $out['oldest'] || $d < $out['oldest'] ) ) {
				$out['oldest'] = $d;
			}
			foreach ( self::keys( $f, $r, $o ) as $k => $kv ) {
				self::add_group( $out['groups'], (string) $k, $kv );
			}
		}
		return $out;
	}

	/** Paid in full in the period: { n, value, days[], avg, median, groups, undated }. */
	public static function paid( array $f, $p ): array {
		$f   = self::filters( $f );
		$out = array( 'n' => 0, 'value' => 0.0, 'days' => array(), 'avg' => null, 'median' => null, 'groups' => array(), 'undated' => 0 );
		foreach ( self::rows( 'invoices', $p, 'paid' ) as $r ) {
			if ( (float) $r['amount'] <= 0 || (float) ( $r['outstanding'] ?? 0 ) > 0.004 || ! self::passes( $f, $r ) ) {
				continue;
			}
			$v    = (float) $r['amount'];
			$d    = substr( (string) ( $r['d'] ?? '' ), 0, 10 );
			$days = '' !== $d ? max( 0, (int) round( ( strtotime( $r['paid_date'] . ' 12:00:00 UTC' ) - strtotime( $d . ' 12:00:00 UTC' ) ) / 86400 ) ) : null;
			$out['n']++;
			$out['value'] += $v;
			if ( null !== $days ) {
				$out['days'][] = $days;
			}
			$r['d'] = $r['paid_date']; // by month / year = the month it was paid
			foreach ( self::keys( $f, $r, $v ) as $k => $kv ) {
				self::add_group( $out['groups'], (string) $k, $kv, array( 'days' => null !== $days ? array( $days ) : array() ) );
			}
		}
		// paid in full with no payment date (a credit, say): counted apart, never guessed
		foreach ( self::rows( 'invoices', $p ) as $r ) {
			if ( (float) $r['amount'] > 0 && (float) ( $r['outstanding'] ?? 0 ) <= 0.004 && '' === (string) ( $r['paid_date'] ?? '' ) && self::passes( $f, $r ) ) {
				$out['undated']++;
			}
		}
		$out['avg']    = self::avg( $out['days'] );
		$out['median'] = self::median( $out['days'] );
		return $out;
	}

	public static function avg( array $a ) {
		return $a ? array_sum( $a ) / count( $a ) : null;
	}

	public static function median( array $a ) {
		if ( ! $a ) {
			return null;
		}
		sort( $a );
		$n = count( $a );
		return 1 === $n % 2 ? (float) $a[ intdiv( $n, 2 ) ] : ( $a[ $n / 2 - 1 ] + $a[ $n / 2 ] ) / 2;
	}

	/** Customers billed in the period: { customers, new, returning, repeat, value }; new = their first invoice ever is in it. */
	public static function customers( array $f, $p ): array {
		$f     = self::filters( $f );
		$seen  = array();
		$jobs  = array();
		$value = 0.0;
		foreach ( self::rows( 'invoices', $p ) as $r ) {
			$cid = (int) ( $r['customer_id'] ?? 0 );
			if ( $cid <= 0 || (float) $r['amount'] <= 0 || ! self::passes( $f, $r ) ) {
				continue;
			}
			$v = self::value( $f, $r );
			if ( $v <= 0.004 ) {
				continue;
			}
			$seen[ $cid ] = true;
			$jobs[ $cid ] = ( $jobs[ $cid ] ?? 0 ) + 1;
			$value       += $v;
		}
		$repeat = count( array_filter( $jobs, function ( $n ) { return $n >= 2; } ) );
		$new    = 0;
		if ( $seen && null !== $p ) {
			$first = array();
			foreach ( self::rows( 'invoices', null ) as $r ) {
				$cid = (int) ( $r['customer_id'] ?? 0 );
				$d   = substr( (string) ( $r['d'] ?? '' ), 0, 10 );
				if ( $cid > 0 && (float) $r['amount'] > 0 && '' !== $d && ( ! isset( $first[ $cid ] ) || $d < $first[ $cid ] ) ) {
					$first[ $cid ] = $d;
				}
			}
			foreach ( array_keys( $seen ) as $c ) {
				$fd = $first[ $c ] ?? '';
				if ( '' !== $fd && $fd >= $p[0] && $fd <= $p[1] ) {
					$new++;
				}
			}
		}
		return array(
			'customers' => count( $seen ),
			'new'       => null === $p ? count( $seen ) : $new,
			'returning' => null === $p ? 0 : count( $seen ) - $new,
			'repeat'    => $repeat,
			'value'     => round( $value, 2 ),
		);
	}

	/** Payments dated from $from to $to (Y-m-d), on any invoice: { value, payments, credits {count, amount} }. */
	public static function received( string $from, string $to ): array {
		$v  = 0.0;
		$n  = 0;
		$cn = 0;
		$ca = 0.0;
		foreach ( self::rows( 'payments', array( $from, $to, '' ) ) as $r ) {
			$a  = (float) ( $r['amount'] ?? 0 );
			$v += $a;
			$n++;
			if ( self::CREDIT_TYPE === strtolower( trim( (string) ( $r['pay_type'] ?? '' ) ) ) ) {
				$cn++;
				$ca += $a;
			}
		}
		return array( 'value' => round( $v, 2 ), 'payments' => $n, 'credits' => array( 'count' => $cn, 'amount' => round( $ca, 2 ) ) );
	}

	/* ─────────────────────────── CRM leads ─────────────────────────── */

	/** Injected in tests: callable() => lead rows { id, created_at, closed_at, status, source }. */
	public static $lead_rows = null;

	/** Lead rows (UTC times), all or created in [from_utc, to_utc). */
	public static function lead_rows( string $from_utc = '', string $to_utc = '' ): array {
		if ( is_callable( self::$lead_rows ) ) {
			$rows = (array) call_user_func( self::$lead_rows );
		} else {
			global $wpdb;
			$rows = $wpdb->get_results( 'SELECT lead_id AS id, created_at, closed_at, status, source FROM ' . ZANA_Ledger_Store::table( 'leads' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- fixed table name.
			self::db_ok();
			$rows = is_array( $rows ) ? $rows : array();
		}
		if ( '' === $from_utc ) {
			return $rows;
		}
		return array_values( array_filter( $rows, function ( $r ) use ( $from_utc, $to_utc ) {
			$c = (string) ( $r['created_at'] ?? '' );
			return '' !== $c && $c >= $from_utc && $c < $to_utc;
		} ) );
	}

	/**
	 * Leads in a window (UTC edges, end exclusive): { leads_new, leads_won (closed won in it, whenever created),
	 * leads_lost, win_rate (won / (won + lost), null when none closed), leads_open (open or pending now) }.
	 * The CRM's statuses: 0 open, 1 pending, 10 won, 11 lost, 12 cancelled.
	 */
	public static function leads( string $from_utc, string $to_utc ): array {
		$new  = 0;
		$won  = 0;
		$lost = 0;
		$open = 0;
		foreach ( self::lead_rows() as $r ) {
			$st = (int) ( $r['status'] ?? 0 );
			$c  = (string) ( $r['created_at'] ?? '' );
			$x  = (string) ( $r['closed_at'] ?? '' );
			if ( '' !== $c && $c >= $from_utc && $c < $to_utc ) {
				$new++;
			}
			if ( '' !== $x && $x >= $from_utc && $x < $to_utc ) {
				$won  += 10 === $st ? 1 : 0;
				$lost += 11 === $st ? 1 : 0;
			}
			if ( 0 === $st || 1 === $st ) {
				$open++;
			}
		}
		return array(
			'leads_new'  => $new,
			'leads_won'  => $won,
			'leads_lost' => $lost,
			'win_rate'   => ( $won + $lost ) > 0 ? round( $won / ( $won + $lost ), 4 ) : null,
			'leads_open' => $open,
		);
	}
}
