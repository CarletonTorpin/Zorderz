<?php
/**
 * ZPREP_Billing_Chain — the newest billing document decides a card's billing signals.
 *
 * A queue card can have an estimate and any number of invoices made from it. They are read
 * as one chain:
 *
 *   - the install-date GUESS comes from the newest document that writes a scheduled date
 *     (an invoice wins a tie with its estimate); when two documents name different days the
 *     guess carries `spread`, so the card can say there is more than one date;
 *   - the card is DONE (dropped from the queue) when any document in the chain carries the
 *     installation-receipt link;
 *   - the card is PAID when every invoice status in the chain is paid.
 *
 * The guess is never the install date's authority: ZPREP_Install_Date::precedence() keeps a
 * date the jobs app knows and lets this guess fill only a card that has no known date.
 * Pure: every input is passed in, nothing here calls the billing provider.
 *
 * @package Zorderz\Prep
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZPREP_Billing_Chain {

	/** Invoice statuses that mean money received in full. */
	const PAID_STATUSES = array( 'paid', 'autopaid' );

	/**
	 * Decide one chain.
	 *
	 * @param array $docs [ kind 'estimate'|'invoice', number, ts (int, newest issue/update),
	 *                      sched (?array envelope from ZPREP_Install_Fallback::from_text()),
	 *                      installed (bool) ]
	 * @return array{env:?array,doc:string,installed:bool,newest:string}
	 */
	public static function decide( array $docs ): array {
		$out  = array( 'env' => null, 'doc' => '', 'installed' => false, 'newest' => '' );
		$docs = array_values( array_filter( $docs, 'is_array' ) );
		if ( ! $docs ) {
			return $out;
		}
		foreach ( $docs as $d ) {
			if ( ! empty( $d['installed'] ) ) {
				$out['installed'] = true;
			}
		}
		usort( $docs, array( __CLASS__, 'invoices_then_newest' ) );
		$out['newest'] = self::label( $docs[0] );

		$dated = array();
		foreach ( $docs as $d ) {
			$sc = ( isset( $d['sched'] ) && is_array( $d['sched'] ) ) ? $d['sched'] : null;
			if ( $sc && '' !== (string) ( $sc['start_utc'] ?? '' ) ) {
				$dated[] = $d;
			}
		}
		if ( $dated ) {
			usort( $dated, array( __CLASS__, 'newest_then_invoice' ) );
			$sc        = $dated[0]['sched'];
			$sc['doc'] = self::label( $dated[0] );
			$days      = array_unique( array_map( static function ( $d ) { return substr( (string) $d['sched']['start_utc'], 0, 10 ); }, $dated ) );
			if ( count( $days ) > 1 ) {
				$sc['spread'] = true;
			}
			$out['env'] = $sc;
			$out['doc'] = $sc['doc'];
		}
		return $out;
	}

	/**
	 * Apply the chains to the queue cards.
	 *
	 * @param array $jobs     queue cards: 'estimate_number'; invoice-arm cards also carry
	 *                        'billing_source' => 'invoice' and 'billing_estimateid'.
	 * @param array $approved approved estimates: estimate_number, estimate_id, updated,
	 *                        created_at, sched, invoice_statuses (string[]).
	 * @param array $invoices recent invoices: invoice_number, estimateid, updated, created_at,
	 *                        scheduled, installed, v3_status.
	 * @return array{jobs:array,trace:string[]} cards in their order; a card whose chain is
	 *         done is removed, every other card gains 'billing_install' (?array), 'fb_paid'
	 *         (bool) and 'billing_doc' (label of the document the guess came from).
	 */
	public static function apply( array $jobs, array $approved, array $invoices ): array {
		$est_by_num = array();
		$est_by_id  = array();
		foreach ( $approved as $e ) {
			if ( ! is_array( $e ) ) {
				continue;
			}
			$n = (string) ( $e['estimate_number'] ?? '' );
			$i = (string) ( $e['estimate_id'] ?? '' );
			if ( '' !== $n ) { $est_by_num[ $n ] = $e; }
			if ( '' !== $i ) { $est_by_id[ $i ] = $e; }
		}
		$inv_by_est = array();
		$inv_by_num = array();
		foreach ( $invoices as $v ) {
			if ( ! is_array( $v ) ) {
				continue;
			}
			$eid = (string) ( $v['estimateid'] ?? '' );
			if ( '' !== $eid && '0' !== $eid ) { $inv_by_est[ $eid ][] = $v; }
			$n = (string) ( $v['invoice_number'] ?? '' );
			if ( '' !== $n ) { $inv_by_num[ $n ] = $v; }
		}

		$out   = array();
		$trace = array();
		foreach ( $jobs as $job ) {
			if ( ! is_array( $job ) ) {
				continue;
			}
			$num  = (string) ( $job['estimate_number'] ?? '' );
			$est  = null;
			$invs = array();
			if ( 'invoice' === (string) ( $job['billing_source'] ?? '' ) ) {
				$eid = (string) ( $job['billing_estimateid'] ?? '' );
				if ( '' !== $eid && isset( $est_by_id[ $eid ] ) ) { $est = $est_by_id[ $eid ]; }
				$invs = ( '' !== $eid && isset( $inv_by_est[ $eid ] ) ) ? $inv_by_est[ $eid ] : array();
				if ( ! $invs && isset( $inv_by_num[ $num ] ) ) { $invs = array( $inv_by_num[ $num ] ); }
			} elseif ( '' !== $num && isset( $est_by_num[ $num ] ) ) {
				$est  = $est_by_num[ $num ];
				$eid  = (string) ( $est['estimate_id'] ?? '' );
				$invs = ( '' !== $eid && isset( $inv_by_est[ $eid ] ) ) ? $inv_by_est[ $eid ] : array();
			}
			if ( ! $est && ! $invs ) {
				$out[] = $job;
				continue;
			}

			$statuses = array();
			if ( $est ) {
				foreach ( (array) ( $est['invoice_statuses'] ?? array() ) as $st ) { $statuses[] = (string) $st; }
			}
			foreach ( $invs as $v ) { $statuses[] = (string) ( $v['v3_status'] ?? '' ); }
			$job['fb_paid'] = self::paid_in_full( $statuses );

			$docs = array();
			if ( $est ) {
				$docs[] = array(
					'kind'      => 'estimate',
					'number'    => (string) ( $est['estimate_number'] ?? '' ),
					'ts'        => self::ts( $est ),
					'sched'     => $est['sched'] ?? null,
					'installed' => false,
				);
			}
			foreach ( $invs as $v ) {
				$docs[] = array(
					'kind'      => 'invoice',
					'number'    => (string) ( $v['invoice_number'] ?? '' ),
					'ts'        => self::ts( $v ),
					'sched'     => $v['scheduled'] ?? null,
					'installed' => ! empty( $v['installed'] ),
				);
			}
			$d   = self::decide( $docs );
			$tag = ( '' !== $num ? '#' . $num : '?' ) . ' <- ' . implode( ', ', array_map( array( __CLASS__, 'label' ), $docs ) );
			if ( $d['installed'] ) {
				$trace[] = $tag . ': installation receipt on file, dropped';
				continue;
			}
			$job['billing_install'] = $d['env'];
			$job['billing_doc']     = $d['doc'];
			if ( $d['env'] && ! empty( $job['fb_possible'] ) ) {
				// A dated document means the job is booked, not merely possible.
				$job['fb_possible'] = false;
				$job['fb_note']     = __( 'Invoiced · not yet cut', 'zorderz' );
			}
			$trace[] = $tag . ': ' . ( $d['env'] ? 'date guess from ' . $d['doc'] : 'no date on these' );
			$out[]   = $job;
		}
		return array( 'jobs' => $out, 'trace' => $trace );
	}

	/** Paid in full: at least one invoice status, and every one of them paid. */
	public static function paid_in_full( array $statuses ): bool {
		$seen = 0;
		foreach ( $statuses as $st ) {
			$st = strtolower( trim( (string) $st ) );
			if ( '' === $st ) {
				continue;
			}
			if ( ! in_array( $st, self::PAID_STATUSES, true ) ) {
				return false;
			}
			$seen++;
		}
		return $seen > 0;
	}

	/** Newest first; an invoice wins a tie with its estimate. */
	public static function newest_then_invoice( array $a, array $b ): int {
		$c = (int) ( $b['ts'] ?? 0 ) <=> (int) ( $a['ts'] ?? 0 );
		if ( 0 !== $c ) {
			return $c;
		}
		return ( 'invoice' === ( $b['kind'] ?? '' ) ? 1 : 0 ) <=> ( 'invoice' === ( $a['kind'] ?? '' ) ? 1 : 0 );
	}

	/** Invoices before estimates, each newest first. */
	public static function invoices_then_newest( array $a, array $b ): int {
		$ka = ( 'invoice' === ( $a['kind'] ?? '' ) ) ? 1 : 0;
		$kb = ( 'invoice' === ( $b['kind'] ?? '' ) ) ? 1 : 0;
		if ( $ka !== $kb ) {
			return $kb <=> $ka;
		}
		return (int) ( $b['ts'] ?? 0 ) <=> (int) ( $a['ts'] ?? 0 );
	}

	/** "invoice #N" / "estimate #N" (the document a date was read from). */
	public static function label( array $d ): string {
		$n = (string) ( $d['number'] ?? '' );
		if ( '' === $n ) {
			return '';
		}
		/* translators: %s: billing document number. */
		return sprintf( 'invoice' === ( $d['kind'] ?? '' ) ? __( 'invoice #%s', 'zorderz' ) : __( 'estimate #%s', 'zorderz' ), $n );
	}

	/** The later of a document's update and issue dates, as a timestamp (0 when unknown). */
	public static function ts( array $row ): int {
		$best = 0;
		foreach ( array( 'updated', 'created_at' ) as $k ) {
			$v = (string) ( $row[ $k ] ?? '' );
			if ( '' !== $v ) {
				$t = strtotime( $v );
				if ( $t && $t > $best ) {
					$best = (int) $t;
				}
			}
		}
		return $best;
	}
}
