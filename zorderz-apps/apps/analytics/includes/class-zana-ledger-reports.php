<?php
/**
 * ZANA_Ledger_Reports — exact figures from the stored-data ledger, handed to the chat's model as a DATA block it may
 * quote but never has to compute (the chat's `zdz_analytics_data_context` seam; the block is fenced by the chat and every
 * customer detail in it is coded by the platform's Ai router before anything leaves the site).
 *
 *   customers    unique customers per year, new vs returning, kept from the year before, 2+ jobs, billed and
 *                collected per year; top customers in the last 12 months and how concentrated revenue is
 *   where        customers and revenue by town, lead source and salesperson, last 12 months
 *   products     revenue by product line per year
 *   estimates    estimates per year: count, value, accepted, invoiced, conversion
 *   leads        CRM leads per year: created, won, lost, open, win rate
 *   receivables  every open invoice, any year: owed, by days past due, archived balances apart
 *
 * Every block starts with its coverage (how far back each copy reaches, as of when), so a partial copy is said to be
 * partial, never presented as complete. Only for company rights (ZANA_Ledger_Access); a Privacy View account gets the
 * figures without the lists that name customers.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Ledger_Reports {

	const MAX_CHARS = 9000;

	public static function init(): void {
		add_filter( 'zdz_analytics_data_context', array( __CLASS__, 'data_context' ), 20, 4 );
	}

	/** The chat's seam: append the block when the question asks for one and this person may see it. */
	public static function data_context( $data, $message = '', $user_id = 0, $context = array() ) {
		$data = is_array( $data ) ? $data : array();
		try {
			$ctx = ZANA_Ledger_Access::context( (int) $user_id );
			if ( ! ZANA_Ledger_Access::may( $ctx, 'company' ) ) {
				return $data;
			}
			$block = self::block_for( (string) $message, ! ZANA_Ledger_Access::may( $ctx, 'list' ) );
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz Analytics: the ledger block failed (' . get_class( $e ) . ').' );
			return $data;
		}
		if ( '' !== $block ) {
			$data['text'] = trim( (string) ( $data['text'] ?? '' ) . "\n\n" . $block );
		}
		return $data;
	}

	/** Which report groups a question asks for (empty: not a ledger question). Pure but for the vocabulary. */
	public static function wants( string $question ): array {
		$q   = ZANA_Ledger_Rules::fold( $question );
		$g   = array();
		$agg = '/\b(how\s+many|how\s+much|count|number\s+of|per\s+(year|month)|by\s+(year|month|city|town|source|category|product)|each\s+(year|month)|annual|yearly|monthly|trend|report|compare|comparison|total|average|avg|most|least|biggest|largest|best|top|share|percent(age)?|breakdown|split|revenue|sales|sold|mix)\b/';
		$strong = preg_match( '/\b(unique|distinct|repeat\s+(customers?|clients?|business|buyers?)|returning|retention|retain(ed)?|churn|lifetime\s+value|loyal|concentration|top\s+\d*\s*(customers|clients|accounts)|new\s+(customers?|clients?)|customer\s+base|customer\s+count|how\s+many\s+(people|households|homes|customers|clients))\b/', $q );
		$plural = preg_match( '/\b(customers|clients)\b/', $q ) && preg_match( '/\b(how\s+many|count|number\s+of|per\s+(year|month)|by\s+(year|month|city)|each\s+year|annual|yearly|trend|report|compare|total|average|avg|most|biggest|largest|best)\b/', $q );
		if ( $strong || $plural ) {
			$g['customers'] = true;
		}
		$town = false;
		foreach ( array_keys( ZANA_Ledger_Answers::cities() ) as $c ) {
			if ( strlen( $c ) >= 4 && ZANA_Ledger_Rules::has_phrase( $q, $c ) && preg_match( $agg, $q ) ) {
				$town = true;
				break;
			}
		}
		if ( $town || preg_match( '/\b(city|cities|town|towns|area|areas|zip|postcode|neighbou?rhood|lead\s+source|sources?|channel|referral|salesperson|sales\s*rep|by\s+rep)\b/', $q ) ) {
			$g['where'] = true;
		}
		$product = false;
		foreach ( array_keys( ZANA_Ledger_Answers::categories() ) as $p ) {
			if ( ZANA_Ledger_Rules::has_phrase( $q, $p ) ) {
				$product = true;
				break;
			}
		}
		if ( preg_match( '/\b(product\s+mix|service\s+mix|sales\s+mix|by\s+(product|category)|product\s+(lines?|categor(y|ies))|categories)\b/', $q ) || ( ( $product || preg_match( '/\bproducts?\b/', $q ) ) && preg_match( $agg, $q ) ) ) {
			$g['products'] = true;
		}
		if ( preg_match( '/\b(estimates?|quotes?|conversion|convert|close\s+rate|closing|accepted|pipeline)\b/', $q ) ) {
			$g['estimates'] = true;
		}
		if ( preg_match( '/\b(leads?|crm|win\s+rate|won|lost|days\s+to\s+close|pipeline)\b/', $q ) ) {
			$g['leads'] = true;
		}
		$money = preg_match( '/(\$|\b(money|invoices?|balances?|amounts?|payments?|paid|pay|bills?|billing|cash|dollars?|revenue|accounts?|collection\s+rate|customers?\s+(still\s+)?owe)\b)/', $q );
		if ( preg_match( '/\b(a\/?r|receivables?|unpaid|overdue|past\s+due|late\s+(invoices?|payments?|payers?)|open\s+invoices?|not\s+(yet\s+)?paid|still\s+(due|owe)|owed|owing|collection\s+rate)\b/', $q )
			|| ( $money && preg_match( '/\b(outstanding|collections?|aging|ageing|owes?)\b/', $q ) ) ) {
			$g['receivables'] = true;
		}
		if ( ! array_diff_key( $g, array( 'receivables' => true ) ) && preg_match( '/\b(report|annual|yearly|per\s+year|by\s+year|year[\s-]+over[\s-]+year|yoy|trend|growth|compare|comparison|benchmark|how\s+(are|is)\s+(we|our|the\s+business)|performance|revenue\s+(trend|history))\b/', $q ) ) {
			$g['customers'] = true;
			$g['products']  = true;
			$g['estimates'] = true;
			$g['leads']     = true;
		}
		return array_keys( $g );
	}

	/** The data block for a question, or ''. $no_names: leave out the lists that name customers. */
	public static function block_for( string $question, bool $no_names = false ): string {
		$groups = self::wants( $question );
		return $groups ? self::block( $groups, $no_names ) : '';
	}

	public static function block( array $groups, bool $no_names = false ): string {
		$cov = ZANA_Ledger::coverage();
		if ( (int) ( $cov['invoices']['rows'] ?? 0 ) <= 0 && (int) ( $cov['leads']['rows'] ?? 0 ) <= 0 ) {
			return '';
		}
		$out = array( '=== DATA LEDGER (exact figures computed by the server from every synced document; quote them, do not re-add or estimate) ===', self::coverage_line( $cov ) );
		foreach ( array( 'customers', 'where', 'products', 'estimates', 'leads', 'receivables' ) as $g ) {
			if ( ! in_array( $g, $groups, true ) ) {
				continue;
			}
			try {
				$sec = call_user_func( array( __CLASS__, $g . '_section' ), $cov, $no_names );
				if ( '' !== $sec ) {
					$out[] = $sec;
				}
			} catch ( \Throwable $e ) {
				error_log( 'Zorderz Analytics: the ledger report "' . $g . '" failed.' );
			}
		}
		$out[] = '=== END DATA LEDGER ===';
		$text  = implode( "\n\n", $out );
		if ( strlen( $text ) > self::MAX_CHARS ) {
			$text = ( function_exists( 'mb_strcut' ) ? mb_strcut( $text, 0, self::MAX_CHARS - 60, 'UTF-8' ) : substr( $text, 0, self::MAX_CHARS - 60 ) )
				. "\n…(ledger block trimmed)\n=== END DATA LEDGER ===";
		}
		return $text;
	}

	public static function coverage_line( array $cov ): string {
		$bits = array();
		foreach ( array( 'invoices', 'estimates' ) as $k ) {
			$c = (array) ( $cov[ $k ] ?? array() );
			if ( (int) ( $c['rows'] ?? 0 ) <= 0 ) {
				$bits[] = $k . ': not loaded yet';
				continue;
			}
			$bits[] = ! empty( $c['complete'] )
				? $k . ': complete history from ' . (string) $c['loaded_from'] . ' (' . (int) $c['rows'] . ' documents)'
				: $k . ': PARTIAL, loaded back to ' . ( null !== ( $c['loaded_from'] ?? null ) ? (string) $c['loaded_from'] : 'the current year so far' ) . ' (' . (int) $c['rows'] . ' documents; older years still loading)';
		}
		$l      = (array) ( $cov['leads'] ?? array() );
		$bits[] = (int) ( $l['rows'] ?? 0 ) <= 0 ? 'CRM leads: not loaded yet' : ( ! empty( $l['complete'] ) ? 'CRM leads: all ' . (int) $l['rows'] : 'CRM leads: PARTIAL, ' . (int) $l['rows'] . ' loaded so far' );
		$last   = max( (string) ( $cov['invoices']['last_ok'] ?? '' ), (string) ( $cov['leads']['last_ok'] ?? '' ) );
		return 'COVERAGE: ' . implode( '; ', $bits ) . ( '' !== $last ? '. Synced ' . $last . ' UTC.' : '.' )
			. ' These are COMPANY-WIDE figures. If a year asked about is outside this coverage, say so; never present a partial year as complete.';
	}

	protected static function money( $v ): string {
		return '$' . number_format( (float) $v, 2 );
	}

	protected static function pct( $a, $b ): string {
		return $b > 0 ? round( 100 * $a / $b ) . '%' : 'n/a';
	}

	/** The first year a section may state whole (the oldest fully loaded), or null. */
	protected static function floor_year( array $cov, string $kind ): ?int {
		$f = $cov[ $kind ]['loaded_from'] ?? null;
		return null === $f ? null : (int) $f;
	}

	protected static function this_year(): int {
		return (int) substr( ZANA_Ledger_Periods::today(), 0, 4 );
	}

	/** The invoices the reports count (no drafts, no deleted, over $0), from the first loaded year. */
	protected static function invoices( array $cov ): array {
		$from = self::floor_year( $cov, 'invoices' );
		if ( null === $from ) {
			return array();
		}
		return array_values( array_filter( ZANA_Ledger_Figures::rows( 'invoices', array( $from . '-01-01', ZANA_Ledger_Periods::today(), '' ) ), function ( $r ) { return (float) $r['amount'] > 0; } ) );
	}

	protected static function customers_section( array $cov, bool $no_names ): string {
		$rows = self::invoices( $cov );
		$from = self::floor_year( $cov, 'invoices' );
		if ( ! $rows ) {
			return '';
		}
		$by    = array();
		$first = array();
		foreach ( $rows as $r ) {
			$c = (int) $r['customer_id'];
			if ( $c <= 0 ) {
				continue;
			}
			$y = (int) substr( (string) $r['d'], 0, 4 );
			$first[ $c ]  = isset( $first[ $c ] ) ? min( $first[ $c ], $y ) : $y;
			$by[ $y ][ $c ] = array(
				'n'         => ( $by[ $y ][ $c ]['n'] ?? 0 ) + 1,
				'billed'    => ( $by[ $y ][ $c ]['billed'] ?? 0 ) + (float) $r['amount'],
				'collected' => ( $by[ $y ][ $c ]['collected'] ?? 0 ) + (float) ( $r['paid'] ?? 0 ),
			);
		}
		ksort( $by );
		$lines = array( 'CUSTOMERS BY YEAR (a customer = a billing client with at least one invoice over $0 dated that year; "new" = first such invoice since ' . $from . '):',
			'Year | Unique customers | New | Returning | Kept from prior year | 2+ jobs | Invoices | Billed | Collected' );
		foreach ( $by as $y => $cs ) {
			$prev = $by[ $y - 1 ] ?? array();
			$o    = array( 'new' => 0, 'ret' => 0, 'kept' => 0, 'multi' => 0, 'n' => 0, 'billed' => 0.0, 'collected' => 0.0 );
			foreach ( $cs as $c => $x ) {
				$o['n']         += $x['n'];
				$o['billed']    += $x['billed'];
				$o['collected'] += $x['collected'];
				$o['multi']     += $x['n'] >= 2 ? 1 : 0;
				$first[ $c ] === $y ? $o['new']++ : $o['ret']++;
				$o['kept']      += isset( $prev[ $c ] ) ? 1 : 0;
			}
			$base    = $y <= $from;
			$lines[] = sprintf( '%s | %d | %s | %s | %s | %d | %d | %s | %s', $y . ( $y === self::this_year() ? ' (YTD)' : '' ), count( $cs ),
				$base ? 'n/a' : (string) $o['new'], $base ? 'n/a' : (string) $o['ret'], ( $base || ! $prev ) ? 'n/a' : $o['kept'] . ' of ' . count( $prev ) . ' (' . self::pct( $o['kept'], count( $prev ) ) . ')',
				$o['multi'], $o['n'], self::money( $o['billed'] ), self::money( $o['collected'] ) );
		}
		if ( ! $no_names ) {
			$since = ZANA_Ledger_Periods::shift_months( ZANA_Ledger_Periods::today(), -12 );
			$top   = array();
			$tot   = 0.0;
			foreach ( $rows as $r ) {
				if ( (string) $r['d'] < $since || (int) $r['customer_id'] <= 0 ) {
					continue;
				}
				$c          = (int) $r['customer_id'];
				$top[ $c ]  = array( 'name' => (string) $r['customer_name'], 'billed' => ( $top[ $c ]['billed'] ?? 0 ) + (float) $r['amount'], 'n' => ( $top[ $c ]['n'] ?? 0 ) + 1 );
				$tot       += (float) $r['amount'];
			}
			uasort( $top, function ( $a, $b ) { return $b['billed'] <=> $a['billed']; } );
			$top = array_slice( $top, 0, 10, true );
			if ( $top && $tot > 0 ) {
				$bits = array();
				$i    = 0;
				foreach ( $top as $c => $x ) {
					$bits[] = ( ++$i ) . ') ' . ( '' !== trim( $x['name'] ) ? $x['name'] : 'Client #' . $c ) . ' ' . self::money( $x['billed'] ) . ' (' . $x['n'] . ' inv)';
				}
				$lines[] = 'TOP 10 CUSTOMERS, LAST 12 MONTHS (top 10 = ' . self::pct( array_sum( array_column( $top, 'billed' ) ), $tot ) . ' of ' . self::money( $tot ) . '): ' . implode( '; ', $bits ) . '.';
			}
		}
		return implode( "\n", $lines );
	}

	protected static function where_section( array $cov, bool $no_names ): string {
		$since = ZANA_Ledger_Periods::shift_months( ZANA_Ledger_Periods::today(), -12 );
		$from  = self::floor_year( $cov, 'invoices' );
		if ( null === $from || $from . '-01-01' > $since ) {
			return '';
		}
		$lines = array();
		foreach ( array( 'city' => 'BY TOWN', 'source' => 'BY LEAD SOURCE', 'rep' => 'BY SALESPERSON' ) as $group => $label ) {
			$s = ZANA_Ledger_Figures::sales( ZANA_Ledger_Figures::filters( array( 'group' => $group ) ), array( $since, ZANA_Ledger_Periods::today(), '' ) );
			uasort( $s['groups'], function ( $a, $b ) { return $b['value'] <=> $a['value']; } );
			$bits = array();
			foreach ( array_slice( $s['groups'], 0, 12, true ) as $k => $g ) {
				$bits[] = $k . ': ' . (int) $g['jobs'] . ' inv, ' . self::money( $g['value'] );
			}
			if ( $bits ) {
				$lines[] = $label . ', LAST 12 MONTHS: ' . implode( '; ', $bits ) . '.';
			}
		}
		return implode( "\n", $lines );
	}

	protected static function products_section( array $cov, bool $no_names ): string {
		$by = array();
		foreach ( self::invoices( $cov ) as $r ) {
			$y = (int) substr( (string) $r['d'], 0, 4 );
			if ( $y < self::this_year() - 3 ) {
				continue;
			}
			foreach ( ZANA_Ledger_Figures::json( $r['mix'] ?? '' ) as $cat => $amt ) {
				$by[ $y ][ $cat ] = ( $by[ $y ][ $cat ] ?? 0 ) + (float) $amt;
			}
		}
		if ( ! $by ) {
			return '';
		}
		ksort( $by );
		$lines = array( 'REVENUE BY PRODUCT LINE (invoice line amounts, administrative lines left out):' );
		foreach ( $by as $y => $cats ) {
			arsort( $cats );
			$tot  = array_sum( $cats );
			$bits = array();
			foreach ( array_slice( $cats, 0, 8, true ) as $cat => $amt ) {
				$bits[] = $cat . ' ' . self::money( $amt ) . ' (' . self::pct( $amt, $tot ) . ')';
			}
			$lines[] = $y . ( $y === self::this_year() ? ' (year to date)' : '' ) . ': ' . implode( '; ', $bits ) . '.';
		}
		return implode( "\n", $lines );
	}

	protected static function estimates_section( array $cov, bool $no_names ): string {
		$from = self::floor_year( $cov, 'estimates' );
		if ( null === $from || (int) ( $cov['estimates']['rows'] ?? 0 ) <= 0 ) {
			return '';
		}
		$lines = array( 'ESTIMATES BY YEAR (drafts left out; conversion = estimates later invoiced):', 'Year | Estimates | Value | Accepted | Invoiced | Conversion (count) | Conversion (value)' );
		for ( $y = $from; $y <= self::this_year(); $y++ ) {
			$e = ZANA_Ledger_Figures::estimates( ZANA_Ledger_Figures::filters(), array( $y . '-01-01', $y . '-12-31', '' ) );
			if ( ! $e['n'] ) {
				continue;
			}
			$lines[] = sprintf( '%s | %d | %s | %d | %d | %s | %s', $y . ( $y === self::this_year() ? ' (YTD)' : '' ), $e['n'], self::money( $e['value'] ), $e['accepted'], $e['invoiced'],
				self::pct( $e['invoiced'], $e['n'] ), self::pct( $e['invoiced_value'], $e['value'] ) );
		}
		return count( $lines ) > 2 ? implode( "\n", $lines ) : '';
	}

	protected static function leads_section( array $cov, bool $no_names ): string {
		$l = (array) ( $cov['leads'] ?? array() );
		if ( (int) ( $l['rows'] ?? 0 ) <= 0 ) {
			return 'CRM LEADS: not loaded yet.';
		}
		if ( empty( $l['complete'] ) ) {
			return 'CRM LEADS: still loading (' . (int) $l['rows'] . ' leads in so far); per-year figures appear once every lead is in.';
		}
		$lines = array( 'CRM LEADS BY YEAR (created that year; won / lost by the day they closed):', 'Year | Created | Won | Lost | Open now (of those created) | Win rate' );
		$by    = array();
		foreach ( ZANA_Ledger_Figures::lead_rows() as $r ) {
			$c = substr( (string) ( $r['created_at'] ?? '' ), 0, 4 );
			$x = substr( (string) ( $r['closed_at'] ?? '' ), 0, 4 );
			$s = (int) ( $r['status'] ?? 0 );
			if ( '' !== $c ) {
				$by[ $c ]['created'] = ( $by[ $c ]['created'] ?? 0 ) + 1;
				$by[ $c ]['open']    = ( $by[ $c ]['open'] ?? 0 ) + ( in_array( $s, array( 0, 1 ), true ) ? 1 : 0 );
			}
			if ( '' !== $x ) {
				$by[ $x ]['won']  = ( $by[ $x ]['won'] ?? 0 ) + ( 10 === $s ? 1 : 0 );
				$by[ $x ]['lost'] = ( $by[ $x ]['lost'] ?? 0 ) + ( 11 === $s ? 1 : 0 );
			}
		}
		ksort( $by );
		foreach ( array_slice( $by, -6, null, true ) as $y => $o ) {
			$w       = (int) ( $o['won'] ?? 0 );
			$lo      = (int) ( $o['lost'] ?? 0 );
			$lines[] = sprintf( '%s | %d | %d | %d | %d | %s', $y, (int) ( $o['created'] ?? 0 ), $w, $lo, (int) ( $o['open'] ?? 0 ), self::pct( $w, $w + $lo ) );
		}
		return implode( "\n", $lines );
	}

	protected static function receivables_section( array $cov, bool $no_names ): string {
		if ( (int) ( $cov['invoices']['rows'] ?? 0 ) <= 0 ) {
			return '';
		}
		$dates = ! ZANA_Ledger::relabel_pending( ZANA_Ledger::state() );
		$o     = ZANA_Ledger_Figures::owed( ZANA_Ledger_Figures::filters( array( 'dates' => $dates ) ), null );
		$lines = array( 'MONEY STILL OWED, ALL OPEN INVOICES, ANY YEAR (drafts and deleted left out):' . ( empty( $cov['invoices']['complete'] ) ? ' the ledger is still loading older years, so this total may be LOW; say so.' : '' ) );
		if ( ! $o['n'] && ! $o['arch_n'] ) {
			$lines[] = '  No invoice in the ledger shows a balance.';
			return implode( "\n", $lines );
		}
		$lines[] = '  ' . $o['n'] . ' invoices, ' . self::money( $o['owed'] ) . ' still owed (face value ' . self::money( $o['face'] ) . ').'
			. ( $o['arch_n'] ? ' Apart: ' . $o['arch_n'] . ' ARCHIVED invoices still show ' . self::money( $o['arch_owed'] ) . ' (usually written off or settled outside the billing system; say so rather than counting them as collectable).' : '' );
		$labels  = $o['by_due'] ? array( 'not yet due', '1-30 days late', '31-60', '61-90', 'over 90' ) : array( '0-30 days since issue', '31-60', '61-90', '91-180', 'over 180' );
		$bits    = array();
		foreach ( $o['ages'] as $i => $b ) {
			$bits[] = $labels[ $i ] . ' ' . $b['n'] . ' / ' . self::money( $b['owed'] );
		}
		$lines[] = '  ' . ( $o['by_due'] ? 'By DUE DATE: ' : 'By age since ISSUE (due dates still loading): ' ) . implode( '; ', $bits ) . '.' . ( $o['by_due'] ? ' "Overdue" / "past due" / "late" means past the due date: ' . $o['over_n'] . ' invoices, ' . self::money( $o['over_owed'] ) . '.' : '' );
		$lines[] = '  RULE: for "how much is owed / receivables / outstanding" totals, quote THIS all-time figure.';
		return implode( "\n", $lines );
	}
}
