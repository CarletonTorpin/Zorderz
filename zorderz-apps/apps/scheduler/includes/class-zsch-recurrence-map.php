<?php
/**
 * ZSCH_Recurrence_Map — our rule, in Google's and Microsoft's vocabulary.
 *
 * PURE. No HTTP, no WordPress, no database. It converts a parsed RRULE into the
 * shape each provider's API expects, and nothing else — which is what makes it
 * testable without touching anybody's real calendar.
 *
 * ── NOT WIRED TO A PUSH YET ───────────────────────────────────────────────────
 *
 * Nothing sends a series to a provider today: an appointment that repeats is
 * pushed as its first occurrence only. This class is the tested mapping a
 * series push will use. Everything here can be proven at the data level; what
 * cannot be proven from a test bench is what a provider does with the result,
 * and a mapping bug there is a repeating event on somebody's real calendar that
 * is not ours to clean up. So the push lands separately, together with the
 * switch that turns it on.
 *
 * ── THE TWO SHAPES ────────────────────────────────────────────────────────────
 *
 * Google takes the RRULE almost verbatim: a `recurrence` array of RFC 5545
 * content lines. The work is mostly making sure we hand it a rule it accepts and
 * that EXDATEs carry a TZID, because a bare local EXDATE does not match an
 * instance and cancels nothing.
 *
 * Microsoft Graph does NOT take an RRULE. It takes a `patternedRecurrence`
 * object split into a `pattern` and a `range`, with its own vocabulary —
 * `absoluteMonthly` vs `relativeMonthly`, an `index` word instead of an ordinal,
 * and days spelled out in full. Several RRULEs have no Graph equivalent at all,
 * and those are REFUSED rather than approximated: a series that silently repeats
 * on the wrong days is worse than one that did not sync.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_Recurrence_Map {

	/** RFC weekday token => Graph's spelled-out name. */
	const GRAPH_DAYS = array(
		'SU' => 'sunday', 'MO' => 'monday', 'TU' => 'tuesday', 'WE' => 'wednesday',
		'TH' => 'thursday', 'FR' => 'friday', 'SA' => 'saturday',
	);

	/** Graph's ordinal words. Graph has no "4th"; it has first..fourth and last. */
	const GRAPH_INDEX = array( 1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', -1 => 'last' );

	/* ══ GOOGLE ════════════════════════════════════════════════════════ */

	/**
	 * Build Google's `recurrence` array.
	 *
	 * @param string $rule          canonical RRULE.
	 * @param string $exdate_list   newline/comma separated LOCAL wall clock.
	 * @param string $tz            IANA zone.
	 * @return array {ok:bool, recurrence?:string[], code?:string, error?:string}
	 */
	public static function to_google( $rule, $exdate_list = '', $tz = '' ) {
		$p = ZSCH_RRule::parse( (string) $rule, true );
		if ( empty( $p['ok'] ) ) {
			return array( 'ok' => false, 'code' => $p['code'], 'error' => $p['error'] );
		}
		$lines = array( 'RRULE:' . ZSCH_RRule::to_string( $p ) );

		/* EXDATE must carry the TZID. Google matches an exception against the
		 * instance's own value, so a bare floating local time cancels NOTHING and
		  */
		$ex = ZSCH_Series::split_list( $exdate_list );
		if ( ! empty( $ex ) && '' !== (string) $tz ) {
			$vals = array();
			foreach ( $ex as $e ) {
				$vals[] = str_replace( array( '-', ' ', ':' ), array( '', 'T', '' ), $e );
			}
			$lines[] = 'EXDATE;TZID=' . $tz . ':' . implode( ',', $vals );
		}
		return array( 'ok' => true, 'recurrence' => $lines );
	}

	/* ══ MICROSOFT GRAPH ═══════════════════════════════════════════════ */

	/**
	 * Build Graph's `patternedRecurrence`.
	 *
	 * @param string $rule
	 * @param string $dtstart_local 'Y-m-d H:i:s' — Graph's range needs a start DATE.
	 * @param string $tz
	 * @return array {ok:bool, patternedRecurrence?:array, code?:string, error?:string}
	 */
	public static function to_graph( $rule, $dtstart_local, $tz ) {
		$p = ZSCH_RRule::parse( (string) $rule, true );
		if ( empty( $p['ok'] ) ) {
			return array( 'ok' => false, 'code' => $p['code'], 'error' => $p['error'] );
		}
		$pattern = self::graph_pattern( $p );
		if ( empty( $pattern['ok'] ) ) {
			return $pattern;
		}
		$range = self::graph_range( $p, $dtstart_local, $tz );
		if ( empty( $range['ok'] ) ) {
			return $range;
		}
		return array(
			'ok'                  => true,
			'patternedRecurrence' => array(
				'pattern' => $pattern['pattern'],
				'range'   => $range['range'],
			),
		);
	}

	/**
	 * The `pattern` half.
	 *
	 * @param array $p parsed rule.
	 * @return array
	 */
	private static function graph_pattern( array $p ) {
		$interval = max( 1, (int) $p['interval'] );

		switch ( $p['freq'] ) {

			case 'DAILY':
				if ( ! empty( $p['byday'] ) || ! empty( $p['bymonthday'] ) || ! empty( $p['bymonth'] ) ) {
					/* Graph's `daily` pattern takes an interval and nothing else.
					 * A DAILY rule with BY* parts narrows the set in a way Graph
					 * cannot express, so it is refused rather than pushed with the
					 * narrowing silently dropped — which would repeat the job on
					 * days nobody scheduled it. */
					return self::err( 'graph_daily_with_by',
						'Microsoft cannot express a daily rule that also filters by day or month. The series stays local rather than syncing as something different.' );
				}
				return self::ok_pattern( array( 'type' => 'daily', 'interval' => $interval ) );

			case 'WEEKLY':
				$days = array();
				foreach ( $p['byday'] as $bd ) {
					$days[] = self::GRAPH_DAYS[ $bd['day'] ];
				}
				$pat = array(
					'type'       => 'weekly',
					'interval'   => $interval,
					'daysOfWeek' => $days,
					/* WKST matters the moment INTERVAL > 1 — the same rule with a
					 * different week start produces different dates. Graph calls it
					 * firstDayOfWeek and defaults to sunday, while RFC defaults to
					 * MO, so it is ALWAYS sent rather than left to a default that
					 * disagrees with ours. */
					'firstDayOfWeek' => self::GRAPH_DAYS[ $p['wkst'] ],
				);
				if ( empty( $days ) ) {
					unset( $pat['daysOfWeek'] );
				}
				return self::ok_pattern( $pat );

			case 'MONTHLY':
				if ( ! empty( $p['byday'] ) ) {
					$bd = $p['byday'][0];
					if ( count( $p['byday'] ) > 1 ) {
						return self::err( 'graph_monthly_multi_byday',
							'Microsoft can express only one weekday for a monthly "nth weekday" rule.' );
					}
					if ( null === $bd['ord'] || ! isset( self::GRAPH_INDEX[ $bd['ord'] ] ) ) {
						return self::err( 'graph_monthly_ordinal',
							'Microsoft supports only the first, second, third, fourth or last weekday of a month.' );
					}
					return self::ok_pattern( array(
						'type'       => 'relativeMonthly',
						'interval'   => $interval,
						'daysOfWeek' => array( self::GRAPH_DAYS[ $bd['day'] ] ),
						'index'      => self::GRAPH_INDEX[ $bd['ord'] ],
					) );
				}
				if ( ! empty( $p['bymonthday'] ) ) {
					$d = (int) $p['bymonthday'][0];
					if ( count( $p['bymonthday'] ) > 1 ) {
						return self::err( 'graph_monthly_multi_monthday',
							'Microsoft can express only one day-of-month for a monthly rule.' );
					}
					if ( $d < 1 ) {
						/* BYMONTHDAY=-1 means "last day of the month", which Graph's
						 * absoluteMonthly cannot say at all — dayOfMonth is a fixed
						 * number. Refused rather than pushed as the 28th. */
						return self::err( 'graph_negative_monthday',
							'Microsoft cannot express "the last day of the month" as a monthly rule, and sending a fixed day instead would move the appointment.' );
					}
					return self::ok_pattern( array( 'type' => 'absoluteMonthly', 'interval' => $interval, 'dayOfMonth' => $d ) );
				}
				return self::err( 'graph_monthly_needs_day',
					'A monthly rule needs a day-of-month or an nth-weekday for Microsoft.' );

			case 'YEARLY':
				$month = ! empty( $p['bymonth'] ) ? (int) $p['bymonth'][0] : 0;
				if ( 0 === $month ) {
					return self::err( 'graph_yearly_needs_month',
						'A yearly rule needs an explicit month for Microsoft.' );
				}
				if ( ! empty( $p['byday'] ) ) {
					$bd = $p['byday'][0];
					if ( null === $bd['ord'] || ! isset( self::GRAPH_INDEX[ $bd['ord'] ] ) ) {
						return self::err( 'graph_yearly_ordinal',
							'Microsoft supports only the first, second, third, fourth or last weekday for a yearly rule.' );
					}
					return self::ok_pattern( array(
						'type'       => 'relativeYearly',
						'interval'   => $interval,
						'month'      => $month,
						'daysOfWeek' => array( self::GRAPH_DAYS[ $bd['day'] ] ),
						'index'      => self::GRAPH_INDEX[ $bd['ord'] ],
					) );
				}
				$d = ! empty( $p['bymonthday'] ) ? (int) $p['bymonthday'][0] : 0;
				if ( $d < 1 ) {
					return self::err( 'graph_yearly_needs_day',
						'A yearly rule needs a day-of-month for Microsoft.' );
				}
				return self::ok_pattern( array(
					'type' => 'absoluteYearly', 'interval' => $interval, 'month' => $month, 'dayOfMonth' => $d,
				) );
		}
		return self::err( 'graph_freq', 'That frequency cannot be expressed for Microsoft.' );
	}

	/**
	 * The `range` half.
	 *
	 * @param array  $p
	 * @param string $dtstart_local
	 * @param string $tz
	 * @return array
	 */
	private static function graph_range( array $p, $dtstart_local, $tz ) {
		$start_date = substr( (string) $dtstart_local, 0, 10 );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) ) {
			return self::err( 'graph_range_start', 'The series start date is not usable.' );
		}
		$range = array( 'startDate' => $start_date, 'recurrenceTimeZone' => (string) $tz );

		if ( (int) $p['count'] > 0 ) {
			$range['type']                = 'numbered';
			$range['numberOfOccurrences'] = (int) $p['count'];
			return array( 'ok' => true, 'range' => $range );
		}
		if ( '' !== (string) $p['until_utc'] ) {
			/* Graph's endDate is a DATE in the recurrence time zone, while our UNTIL
			 * is a UTC instant. Converting rather than truncating the string matters
			 * across a zone offset: 2026-06-04 00:30Z is still 3 June locally, and
			 * using the UTC date would add a day to the series. */
			$local = ZSCH_Series::utc_to_local( (string) $p['until_utc'], (string) $tz );
			$range['type']    = 'endDate';
			$range['endDate'] = ( '' !== $local ) ? substr( $local, 0, 10 ) : substr( (string) $p['until_utc'], 0, 10 );
			return array( 'ok' => true, 'range' => $range );
		}
		$range['type'] = 'noEnd';
		return array( 'ok' => true, 'range' => $range );
	}

	/** @return array */
	private static function ok_pattern( array $pattern ) {
		return array( 'ok' => true, 'pattern' => $pattern );
	}

	/** @return array */
	private static function err( $code, $message ) {
		return array( 'ok' => false, 'code' => $code, 'error' => $message );
	}
}
