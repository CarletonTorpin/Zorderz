<?php
/**
 * ZANA_Invoice_State — one rule for "where does this invoice's money stand?". Pure.
 *
 * A status filter that knew only the billing system's numeric statuses (draft / sent / viewed / paid) matched nothing for
 * "partial", "unpaid", "outstanding" or "overdue". The payment state reads the billing system's own strings (v3_status,
 * payment_status), the amounts (paid / outstanding) and the due date. The only numeric status it trusts is 4 = paid;
 * draft / sent / viewed keep their numeric meaning.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Invoice_State {

	/** Filter words that mean a payment state rather than a status code. */
	const PAYMENT_WORDS = array( 'partial', 'unpaid', 'outstanding', 'overdue' );

	/** The plain status words' numeric meaning. */
	const STATUS_CODES = array( 'draft' => 0, 'sent' => 1, 'viewed' => 2, 'paid' => 4 );

	public static function is_payment_filter( $filter ): bool {
		return in_array( strtolower( trim( (string) $filter ) ), self::PAYMENT_WORDS, true );
	}

	private static function money( $v ) {
		if ( is_array( $v ) ) {
			$v = $v['amount'] ?? null;
		}
		return ( null === $v || '' === $v ) ? null : (float) $v;
	}

	/** 'paid' | 'partial' | 'overdue' | 'outstanding' | 'draft'. $today: Y-m-d. */
	public static function payment_state( array $doc, string $today ): string {
		$status = (int) ( $doc['status'] ?? -1 );
		$ps     = strtolower( (string) ( $doc['payment_status'] ?? '' ) );
		$v3     = strtolower( (string) ( $doc['v3_status'] ?? '' ) );
		$paid   = self::money( $doc['paid'] ?? null );
		$out    = self::money( $doc['outstanding'] ?? null );
		$due    = (string) ( $doc['due_date'] ?? '' );
		$amt    = self::money( $doc['amount'] ?? null );
		if ( 'draft' === $v3 ) {
			return 'draft';
		}
		// nothing left to pay is paid: a paid-up invoice, one settled by a credit, a $0 invoice
		if ( 4 === $status || 'paid' === $ps || in_array( $v3, array( 'paid', 'autopaid', 'auto-paid' ), true )
			|| ! empty( $doc['date_paid'] ) || ( null !== $out && $out <= 0.004 )
			|| ( null === $out && null !== $paid && null !== $amt && $paid >= $amt - 0.004 && $amt > 0 ) ) {
			return 'paid';
		}
		$some_paid = null !== $paid && $paid > 0.004 && ( null !== $out ? $out > 0.004 : ( null === $amt || $paid < $amt - 0.004 ) );
		if ( 'partial' === $ps || 'partial' === $v3 || $some_paid ) {
			return 'partial';
		}
		if ( 'overdue' === $v3 || ( '' !== $due && $due < $today ) ) {
			return 'overdue';
		}
		return 'outstanding';
	}

	/** Does this invoice match a status filter word? Anything unknown is not a match. */
	public static function matches( array $doc, $filter, string $today ): bool {
		$f = strtolower( trim( (string) $filter ) );
		if ( '' === $f ) {
			return true;
		}
		if ( self::is_payment_filter( $f ) ) {
			$state = self::payment_state( $doc, $today );
			if ( 'partial' === $f || 'overdue' === $f ) {
				return $state === $f;
			}
			return in_array( $state, array( 'partial', 'overdue', 'outstanding' ), true );
		}
		if ( isset( self::STATUS_CODES[ $f ] ) ) {
			if ( 'paid' === $f && 'paid' === self::payment_state( $doc, $today ) ) {
				return true;
			}
			return (int) ( $doc['status'] ?? -1 ) === self::STATUS_CODES[ $f ];
		}
		return strtolower( (string) ( $doc['status'] ?? '' ) ) === $f;
	}
}
