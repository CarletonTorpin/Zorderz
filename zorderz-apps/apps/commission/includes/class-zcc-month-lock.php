<?php
/**
 * ZCC_Month_Lock — the person who pays commission locks a month after paying it.
 *
 * A pay admin (ZCC_Visibility::is_pay_admin: the commission permissions to run a figure and
 * see other people's; View As, Privacy View and the shared device demote) looking at one
 * person's WHOLE, ENDED calendar month sees "Lock <Month> for <Name>". Pressing it:
 *
 *   1. works the month out afresh on the server — never from figures the page sends — which
 *      writes or refreshes its draft rows;
 *   2. locks exactly that figure's invoices for that person (ZCC_Ledger::finalize_invoices); a
 *      stray draft another date range filed under the same month stays live;
 *   3. remembers who locked it, when, and the commission and invoice count it was locked at
 *      (ZCC_Ledger::record_month_lock).
 *
 * An invoice already locked in ANOTHER month (a partly paid one can show in two) is left out of
 * this month's lock and figure, so it is paid once. The month is called locked only once every
 * invoice reads back locked; otherwise nothing is recorded and the payer is asked to press
 * again. A second press with nothing new changes nothing. An invoice paid after the lock shows
 * as not locked yet and can be locked with another press. Nothing here unlocks: a lock
 * certifies a payment, and a mistaken one is a database fix, not a button.
 *
 * @package Zorderz\Commission
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZCC_Month_Lock {

	/** Tests: callable( int $target, string $start, string $end, string $month ) => calc result, or throws. */
	public static $compute = null;
	/** Tests: the current month ("YYYY-MM"), so "ended" can be tested. */
	public static $now_month = null;

	/** "YYYY-MM" when the range is exactly one whole calendar month, else ''. */
	public static function full_month( string $start, string $end ): string {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-01$/', $start, $m ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) ) {
			return '';
		}
		$y  = (int) $m[1];
		$mo = (int) $m[2];
		if ( $mo < 1 || $mo > 12 ) {
			return '';
		}
		$last = sprintf( '%04d-%02d-%02d', $y, $mo, (int) gmdate( 't', gmmktime( 0, 0, 0, $mo, 1, $y ) ) );
		return $end === $last ? sprintf( '%04d-%02d', $y, $mo ) : '';
	}

	public static function now_month(): string {
		if ( is_string( self::$now_month ) && '' !== self::$now_month ) {
			return self::$now_month;
		}
		return function_exists( 'current_time' ) ? (string) current_time( 'Y-m' ) : gmdate( 'Y-m' );
	}

	/** A month that has ended (before this one, in the site's time zone). */
	public static function has_ended( string $month ): bool {
		return (bool) preg_match( '/^\d{4}-\d{2}$/', $month ) && strcmp( $month, self::now_month() ) < 0;
	}

	public static function month_name( string $month ): string {
		if ( ! preg_match( '/^(\d{4})-(\d{2})$/', $month, $m ) ) {
			return $month;
		}
		return gmdate( 'F Y', gmmktime( 0, 0, 0, (int) $m[2], 1, (int) $m[1] ) );
	}

	/**
	 * Lock one person's ended month.
	 *
	 * @return array { ok, message, month?, locked_now?, commission?, invoices?, left_out? }
	 */
	public static function answer( int $uid, int $target, string $month ): array {
		if ( $uid <= 0 || ! ZCC_Visibility::is_pay_admin( $uid ) ) {
			return [ 'ok' => false, 'message' => __( 'Only the person who pays commission can lock a month.', 'zorderz' ) ];
		}
		if ( $target <= 0 ) {
			return [ 'ok' => false, 'message' => __( 'Pick whose month to lock.', 'zorderz' ) ];
		}
		if ( ! preg_match( '/^(\d{4})-(\d{2})$/', $month, $m ) || (int) $m[2] < 1 || (int) $m[2] > 12 ) {
			return [ 'ok' => false, 'message' => __( 'That isn’t a month.', 'zorderz' ) ];
		}
		$name = self::month_name( $month );
		if ( ! self::has_ended( $month ) ) {
			/* translators: %s: a month and year. */
			return [ 'ok' => false, 'message' => sprintf( __( '%s hasn’t ended yet. Lock a month after you’ve paid it.', 'zorderz' ), $name ) ];
		}
		$start = $month . '-01';
		$end   = $month . '-' . gmdate( 't', gmmktime( 0, 0, 0, (int) $m[2], 1, (int) $m[1] ) );
		try {
			$result = is_callable( self::$compute ) ? call_user_func( self::$compute, $target, $start, $end, $month )
				: self::compute( $target, $start, $end, $month );
		} catch ( \Throwable $e ) {
			error_log( 'ZCC month lock: compute_failed' );
			return [ 'ok' => false, 'message' => __( 'The month couldn’t be worked out just now (the billing system didn’t answer). Nothing was locked.', 'zorderz' ) ];
		}
		if ( ! is_array( $result ) || ! empty( $result['error'] ) ) {
			return [ 'ok' => false, 'message' => (string) ( $result['error'] ?? __( 'Nothing was locked.', 'zorderz' ) ) ];
		}

		$all = [];
		foreach ( (array) ( $result['invoices'] ?? [] ) as $inv ) {
			if ( (int) ( $inv['invoice_id'] ?? 0 ) > 0 ) {
				$all[] = $inv;
			}
		}
		if ( ! $all ) {
			/* translators: %s: a month and year. */
			return [ 'ok' => false, 'message' => sprintf( __( 'There’s no commission in %s to lock.', 'zorderz' ), $name ) ];
		}
		$before     = ZCC_Ledger::rows_for_invoices( $target, array_column( $all, 'invoice_id' ) );
		$mine       = [];
		$fresh      = [];
		$elsewhere  = 0;
		$commission = 0.0;
		foreach ( $all as $inv ) {
			$id  = (int) $inv['invoice_id'];
			$row = $before[ $id ] ?? null;
			if ( $row && 'finalized' === $row['status'] && $row['period_key'] !== $month ) {
				$elsewhere++;
				continue;
			}
			$mine[]      = $id;
			$commission += (float) ( $inv['commission_amount'] ?? 0 );
			if ( ! $row || 'finalized' !== $row['status'] ) {
				$fresh[] = $id;
			}
		}
		$commission = round( $commission, 2 );
		if ( ! $mine ) {
			/* translators: %s: a month and year. */
			return [ 'ok' => false, 'message' => sprintf( __( 'Every invoice in %s is already locked in another month. Nothing was locked.', 'zorderz' ), $name ) ];
		}
		$prev = ZCC_Ledger::get_month_lock( $target, $month );
		if ( $prev && ! $fresh ) {
			return [
				'ok'         => true,
				'month'      => $month,
				'locked_now' => 0,
				'commission' => (float) ( $prev['commission'] ?? 0 ),
				'invoices'   => (int) ( $prev['invoices'] ?? 0 ),
				/* translators: %s: a month and year. */
				'message'    => sprintf( __( '%s was already locked. Nothing new to lock.', 'zorderz' ), $name ),
			];
		}
		$locked = ZCC_Ledger::finalize_invoices( $target, $fresh, $uid );
		$after  = $fresh ? ZCC_Ledger::rows_for_invoices( $target, $fresh ) : [];
		$left   = 0;
		foreach ( $fresh as $id ) {
			if ( 'finalized' !== ( $after[ $id ]['status'] ?? '' ) ) {
				$left++;
			}
		}
		if ( $left > 0 ) {
			error_log( sprintf( 'ZCC month lock: %s for user %d incomplete, %d of %d not locked', $month, $target, $left, count( $fresh ) ) );
			/* translators: 1: invoices not locked, 2: invoices to lock, 3: a month and year. */
			return [ 'ok' => false, 'message' => sprintf( __( '%1$d of %2$d invoices in %3$s couldn’t be locked just now. Press Lock again. The month isn’t marked locked until they all are.', 'zorderz' ), $left, count( $fresh ), $name ) ];
		}
		$u   = function_exists( 'get_userdata' ) ? get_userdata( $uid ) : null;
		$rec = [
			'by'         => $uid,
			'by_name'    => ( $u && ! empty( $u->display_name ) ) ? (string) $u->display_name : '',
			'at'         => time(),
			'commission' => $commission,
			'invoices'   => count( $mine ),
			'locked_now' => $locked,
			'left_out'   => $elsewhere,
		];
		ZCC_Ledger::record_month_lock( $target, $month, $rec );
		if ( class_exists( 'ZCC_Audit' ) ) {
			ZCC_Audit::log( 'month_locked', [ 'month' => $month, 'party_id' => $target, 'by' => $uid, 'rows' => $locked, 'left_out' => $elsewhere ] );
		}
		$n   = count( $mine );
		$msg = sprintf(
			/* translators: 1: a month and year, 2: an amount, 3: an invoice count. */
			_n( '%1$s is locked: %2$s on %3$d invoice.', '%1$s is locked: %2$s on %3$d invoices.', $n, 'zorderz' ),
			$name,
			'$' . number_format( $commission, 2 ),
			$n
		);
		if ( $elsewhere ) {
			/* translators: %d: invoices left out. */
			$msg .= ' ' . sprintf( _n( '%d invoice was already locked in another month and left out.', '%d invoices were already locked in another month and left out.', $elsewhere, 'zorderz' ), $elsewhere );
		}
		return [ 'ok' => true, 'month' => $month, 'locked_now' => $locked, 'commission' => $commission, 'invoices' => $n, 'left_out' => $elsewhere, 'message' => $msg ];
	}

	/** The month worked out the way the figure is: paid and partly paid, this person's invoices only. */
	private static function compute( int $target, string $start, string $end, string $month ): array {
		if ( ! ZCC_FreshBooks::is_connected() ) {
			return [ 'error' => __( 'The billing system isn’t connected, so nothing was locked.', 'zorderz' ) ];
		}
		$invoices = ZCC_FreshBooks::get_invoices( $start, $end, [ 'paid', 'partial' ] );
		if ( ! $invoices && '' !== ZCC_FreshBooks::last_error() ) {
			throw new \RuntimeException( 'fetch_failed' );
		}
		$split = ZCC_Attribution::partition( $invoices, $target );
		return ZCC_Calc_Engine::calculate( $split['matched'], $target, $month );
	}

	/**
	 * What the figure shows a pay admin about locking: the lock record, the button, or nothing
	 * (a part month, a month not over, a status filter other than paid-and-partly-paid, or
	 * someone who does not pay commission). Data only; the page draws it.
	 *
	 * @return array|null
	 */
	public static function block( int $target, string $who, string $start, string $end, string $status, array $ledger_stats, array $summary, bool $is_pay_admin ): ?array {
		if ( ! $is_pay_admin || $target <= 0 ) {
			return null;
		}
		$month = self::full_month( $start, $end );
		if ( '' === $month || ! self::has_ended( $month ) ) {
			return null;
		}
		$st    = str_replace( ' ', '', strtolower( $status ) );
		$paidp = in_array( $st, [ '', 'paid,partial', 'partial,paid' ], true );
		$rec   = ZCC_Ledger::get_month_lock( $target, $month );
		$open  = (int) ( ( $ledger_stats['inserted'] ?? 0 ) + ( $ledger_stats['updated'] ?? 0 ) );
		$name  = self::month_name( $month );
		$who   = '' !== trim( $who ) ? $who : __( 'this person', 'zorderz' );
		return [
			'month'      => $month,
			'month_name' => $name,
			'user_id'    => $target,
			'locked'     => $rec ? [
				'at'         => (int) ( $rec['at'] ?? 0 ),
				'by_name'    => (string) ( $rec['by_name'] ?? '' ),
				'commission' => (float) ( $rec['commission'] ?? 0 ),
				'invoices'   => (int) ( $rec['invoices'] ?? 0 ),
			] : null,
			'open'       => $paidp ? $open : 0,
			'can_lock'   => $paidp && ( ! $rec || $open > 0 ),
			'needs_paid' => ! $paidp,
			/* translators: 1: a month and year, 2: a person's name, 3: an amount. */
			'ask'        => sprintf( __( 'Lock %1$s for %2$s at %3$s? Only do this after the commission is paid. The app can’t unlock it.', 'zorderz' ), $name, $who, '$' . number_format( (float) ( $summary['total_commission'] ?? 0 ), 2 ) ),
			/* translators: 1: a month and year, 2: a person's name. */
			'label'      => $rec ? __( 'Lock again', 'zorderz' ) : sprintf( __( 'Lock %1$s for %2$s', 'zorderz' ), $name, $who ),
		];
	}
}
