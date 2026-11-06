<?php
/**
 * The website's half of an invoice card: who the customer is.
 *
 * The Charts hub keeps no names (its store is de-identified), so a card's name, town, ZIP and
 * payment state come from the site's own billing ledger: the invoice and estimate tables the
 * analytics app keeps in step with the billing system. Read only, one row by number; nothing
 * is written or cached here, and nothing from it is sent to the hub.
 *
 * The ledger's rep code is also the first lock for a salesperson's card (the server decides):
 * a job the ledger says is someone else's is refused before the hub is called. The hub checks
 * again against its own data.
 *
 * With no ledger on the site every lookup answers null: the card then shows no name, and the
 * hub's own check is the only lock (it fails closed on its side).
 *
 * @package Zorderz\Charts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCH_Ledger {

	/** Injected in tests: kind => array(number => row). */
	public static $rows = null;

	/** The ledger's table names (without the prefix); filter `zch_ledger_tables`. */
	public static function table_names() {
		$t = array( 'invoice' => 'zana_ledger_invoices', 'estimate' => 'zana_ledger_estimates', 'lead' => 'zana_ledger_leads' );
		$f = function_exists( 'apply_filters' ) ? apply_filters( 'zch_ledger_tables', $t ) : $t;
		return is_array( $f ) ? array_merge( $t, array_filter( $f, 'is_string' ) ) : $t;
	}

	/** The full table name for a kind, or null when the site has no such table. */
	public static function table( $kind ) {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return null;
		}
		$names = self::table_names();
		$name  = $names[ $kind ] ?? '';
		if ( ! preg_match( '/^[a-z0-9_]{1,60}$/', $name ) ) {
			return null;
		}
		$t     = $wpdb->prefix . $name;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
		return $found === $t ? $t : null;
	}

	/** "0001234" and "1234" are the same invoice. */
	public static function norm( $no ) {
		$no = (string) $no;
		if ( ! preg_match( '/^[A-Za-z0-9-]{1,20}$/D', $no ) ) {
			return null;
		}
		$n = ltrim( $no, '0' );
		return '' === $n ? '0' : $n;
	}

	/** The ledger row for one invoice or estimate, or null (no ledger, no row, two rows). */
	public static function row( $kind, $no ) {
		$n = self::norm( $no );
		if ( null === $n ) {
			return null;
		}
		if ( is_array( self::$rows ) ) {
			return self::$rows[ $kind ][ $n ] ?? null;
		}
		global $wpdb;
		$t = self::table( 'estimate' === $kind ? 'estimate' : 'invoice' );
		if ( ! $t ) {
			return null;
		}
		$col = 'estimate' === $kind ? 'estimate_number' : 'invoice_number';
		// Drafts and deleted documents never stand in for the job (vis_state 1 = deleted).
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$t} WHERE TRIM(LEADING '0' FROM {$col}) = %s AND vis_state <> 1 LIMIT 2", $n ), // phpcs:ignore WordPress.DB.PreparedSQL -- table and column are fixed names.
			ARRAY_A
		);
		return ( is_array( $rows ) && 1 === count( $rows ) ) ? $rows[0] : null;
	}

	/** The rep code the ledger gives the job ('' when it has none). */
	public static function rep( $kind, $no ) {
		$r = self::row( $kind, $no );
		return $r ? strtoupper( trim( (string) ( $r['rep_code'] ?? '' ) ) ) : '';
	}

	/**
	 * What the card shows about the customer and the payment, for a person already allowed to
	 * open it. Dollar figures only with the money right.
	 */
	public static function identity( $kind, $no, $money ) {
		$r = self::row( $kind, $no );
		if ( ! $r ) {
			return null;
		}
		$out = array(
			'customer_name' => trim( (string) ( $r['customer_name'] ?? '' ) ),
			'city'          => trim( (string) ( $r['city'] ?? '' ) ),
			'zip'           => trim( (string) ( $r['zip'] ?? '' ) ),
			'rep_code'      => strtoupper( trim( (string) ( $r['rep_code'] ?? '' ) ) ),
			'lead_source'   => trim( (string) ( $r['lead_source'] ?? '' ) ),
		);
		if ( 'estimate' === $kind ) {
			$out['status']   = (string) ( $r['status_label'] ?? '' );
			$out['accepted'] = ! empty( $r['accepted'] );
			$out['invoiced'] = ! empty( $r['invoiced'] );
			$out['amount']   = $money ? (float) ( $r['amount'] ?? 0 ) : null;
		} else {
			$out['pay_status']  = (string) ( $r['pay_status'] ?? '' );
			$out['amount']      = $money ? (float) ( $r['amount'] ?? 0 ) : null;
			$out['paid']        = $money ? (float) ( $r['paid'] ?? 0 ) : null;
			$out['outstanding'] = $money ? (float) ( $r['outstanding'] ?? 0 ) : null;
		}
		return $out;
	}
}
