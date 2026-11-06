<?php
/**
 * ZSCH_RRule — parse and VALIDATE an RFC 5545 recurrence rule.
 *
 * Separate from the expander on purpose. Expansion is arithmetic; this is
 * judgement about whether the input means anything at all, and every rule that
 * must be REFUSED is refused here. Keeping them apart means the
 * expander can assume it was handed something coherent, and means "did we accept
 * a rule we should have rejected?" is answerable without generating a single
 * instance.
 *
 * PURE. No WordPress, no database, no clock.
 *
 * ── A BOUNDED SUBSET, REFUSED LOUDLY ──────────────────────────────────────────
 *
 * RRULE is a large spec and most of it a work calendar never uses. Supporting
 * all of it means shipping a lot of surface that no real data exercises and no
 * test pins — which is how an engine ends up confidently wrong about BYSETPOS
 * two years after anybody remembers writing it.
 *
 * So: FREQ (DAILY/WEEKLY/MONTHLY/YEARLY), INTERVAL, COUNT, UNTIL, BYDAY,
 * BYMONTHDAY, BYMONTH, WKST. Everything else is REFUSED WITH A NAMED REASON.
 *
 * ── THE FOUR THINGS THE SPEC ITSELF SAYS MUST NOT HAPPEN ──────────────────────
 *
 *   1. COUNT and UNTIL together. Accepting both means silently
 *      choosing one and producing a series nobody asked for.
 *   2. A numeric prefix on BYDAY when FREQ is not MONTHLY or YEARLY.
 *      `FREQ=WEEKLY;BYDAY=-1SU` is meaningless, not a hint.
 *   3. UNTIL that is not UTC when DTSTART carries a zone. The RFC's
 *      own published example got this wrong and needed Errata 3883 to fix it —
 *      if the spec authors slipped, an implementation will. A local UNTIL
 *      truncates the series up to a day early or late.
 *   4. Out-of-range values: BYMONTHDAY 0 or |n| > 31, BYMONTH outside 1..12,
 *      INTERVAL or COUNT below 1.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_RRule {

	/** Frequencies this engine expands. */
	const FREQS = array( 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY' );

	/** Real RRULE frequencies we deliberately do not implement. */
	const FREQS_REFUSED = array( 'SECONDLY', 'MINUTELY', 'HOURLY' );

	/** Rule parts this engine understands. */
	const PARTS = array( 'FREQ', 'INTERVAL', 'COUNT', 'UNTIL', 'BYDAY', 'BYMONTHDAY', 'BYMONTH', 'WKST' );

	/** Real rule parts we deliberately do not implement. */
	const PARTS_REFUSED = array( 'BYSETPOS', 'BYYEARDAY', 'BYWEEKNO', 'BYHOUR', 'BYMINUTE', 'BYSECOND' );

	/** RFC weekday tokens, in RFC order (SU first) — index is PHP's `w`. */
	const DAYS = array( 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' );

	/** Longest rule string we will even look at. */
	const MAX_LEN = 1024;

	/**
	 * Parse and validate.
	 *
	 * @param mixed  $rule    e.g. "FREQ=WEEKLY;BYDAY=MO,WE;INTERVAL=2"
	 * @param bool   $zoned   true when DTSTART carries a time zone, which makes a
	 *                        non-UTC UNTIL invalid. Always true in this
	 *                        system; the parameter exists so the rule is explicit
	 *                        rather than assumed.
	 * @return array {ok:bool, error?:string, code?:string, ...parts}
	 */
	public static function parse( $rule, $zoned = true ) {
		if ( ! is_string( $rule ) ) {
			return self::err( 'not_a_string', 'A recurrence rule must be a string.' );
		}
		$rule = trim( $rule );
		if ( '' === $rule ) {
			return self::err( 'empty', 'The recurrence rule is empty.' );
		}
		if ( strlen( $rule ) > self::MAX_LEN ) {
			return self::err( 'too_long', 'The recurrence rule is implausibly long.' );
		}

		/* Tolerate the "RRULE:" prefix — it is how the value arrives from an
		 * iCalendar file, and refusing it would be pedantry rather than safety. */
		if ( 0 === stripos( $rule, 'RRULE:' ) ) {
			$rule = substr( $rule, 6 );
		}

		$out = array(
			'ok'         => true,
			'freq'       => '',
			'interval'   => 1,
			'count'      => 0,      // 0 = unset
			'until_utc'  => '',     // '' = unset, else 'Y-m-d H:i:s'
			'byday'      => array(), // list of array('ord'=>int|null,'day'=>'MO')
			'bymonthday' => array(), // ints, may be negative
			'bymonth'    => array(), // ints 1..12
			'wkst'       => 'MO',
		);

		$seen = array();
		foreach ( explode( ';', $rule ) as $chunk ) {
			$chunk = trim( $chunk );
			if ( '' === $chunk ) {
				continue;
			}
			if ( false === strpos( $chunk, '=' ) ) {
				return self::err( 'malformed_part', sprintf( 'Rule part "%s" is not NAME=VALUE.', $chunk ) );
			}
			list( $name, $value ) = explode( '=', $chunk, 2 );
			$name  = strtoupper( trim( $name ) );
			$value = trim( $value );

			if ( in_array( $name, self::PARTS_REFUSED, true ) ) {
				return self::err(
					'part_not_supported',
					sprintf(
						'%s is a real RRULE part that this scheduler does not implement, so the rule is refused rather than expanded without it. Expressing it a different way is safer than getting dates that look right and are not.',
						$name
					)
				);
			}
			if ( ! in_array( $name, self::PARTS, true ) ) {
				return self::err( 'unknown_part', sprintf( '"%s" is not a rule part this scheduler recognises.', $name ) );
			}
			/* A repeated part is ambiguous — which one wins? Neither, honestly. */
			if ( isset( $seen[ $name ] ) ) {
				return self::err( 'duplicate_part', sprintf( '%s appears more than once.', $name ) );
			}
			$seen[ $name ] = true;

			$res = self::part( $name, $value, $out );
			if ( isset( $res['ok'] ) && false === $res['ok'] ) {
				return $res;
			}
			$out = $res;
		}

		// ── the cross-part rules ────────────────────────────────────────
		if ( '' === $out['freq'] ) {
			return self::err( 'freq_required', 'FREQ is required (RFC 5545 section 3.3.10).' );
		}

		/*
		 * occur in the same recur." */
		if ( $out['count'] > 0 && '' !== $out['until_utc'] ) {
			return self::err(
				'count_and_until',
				'COUNT and UNTIL cannot both be set (RFC 5545 section 3.3.10) — a rule carrying both does not say where the series ends.'
			);
		}

		/*
		 * YEARLY. "The second Tuesday of every week" is not a thing. */
		if ( ! in_array( $out['freq'], array( 'MONTHLY', 'YEARLY' ), true ) ) {
			foreach ( $out['byday'] as $bd ) {
				if ( null !== $bd['ord'] ) {
					return self::err(
						'byday_prefix_freq',
						sprintf(
							'BYDAY carries a numeric prefix (%s%s) but FREQ is %s. A prefix is only meaningful with MONTHLY or YEARLY (RFC 5545 section 3.3.10).',
							$bd['ord'], $bd['day'], $out['freq']
						)
					);
				}
			}
		}

		/*
		 * at parse time in self::part(); this re-states the dependency so the
		 * reason survives a later refactor that moves the check. */
		if ( $zoned && isset( $out['_until_was_local'] ) ) {
			return self::err( 'until_not_utc', 'UNTIL must be given in UTC when the event has a time zone (RFC 5545 section 3.3.10, Errata 3883).' );
		}
		unset( $out['_until_was_local'] );

		return $out;
	}

	/**
	 * Validate and absorb one NAME=VALUE part.
	 *
	 * @param string $name
	 * @param string $value
	 * @param array  $out
	 * @return array the updated $out, or an error array.
	 */
	private static function part( $name, $value, array $out ) {
		switch ( $name ) {

			case 'FREQ':
				$v = strtoupper( $value );
				if ( in_array( $v, self::FREQS_REFUSED, true ) ) {
					return self::err(
						'freq_not_supported',
						sprintf( 'FREQ=%s is a real frequency this scheduler does not implement: repeats more often than daily are not supported.', $v )
					);
				}
				if ( ! in_array( $v, self::FREQS, true ) ) {
					return self::err( 'freq_invalid', sprintf( '"%s" is not a valid FREQ.', $value ) );
				}
				$out['freq'] = $v;
				return $out;

			case 'INTERVAL':
				if ( ! preg_match( '/^\d+$/', $value ) ) {
					return self::err( 'interval_invalid', 'INTERVAL must be a positive whole number.' );
				}
				$n = (int) $value;
				if ( $n < 1 ) {
					return self::err( 'interval_invalid', 'INTERVAL must be 1 or greater (RFC 5545 section 3.3.10).' );
				}
				if ( $n > 1000 ) {
					return self::err( 'interval_implausible', 'INTERVAL above 1000 is refused as a typo guard.' );
				}
				$out['interval'] = $n;
				return $out;

			case 'COUNT':
				if ( ! preg_match( '/^\d+$/', $value ) ) {
					return self::err( 'count_invalid', 'COUNT must be a positive whole number.' );
				}
				$n = (int) $value;
				if ( $n < 1 ) {
					return self::err( 'count_invalid', 'COUNT must be 1 or greater (RFC 5545 section 3.3.10).' );
				}
				$out['count'] = $n;
				return $out;

			case 'UNTIL':
				/* Accept ONLY the UTC DATE-TIME form. A floating or zoned UNTIL is
				 * refused rather than coerced — coercion is what silently moves the
				 * end of somebody's series by a day. */
				if ( preg_match( '/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z$/', $value, $m ) ) {
					if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
						return self::err( 'until_invalid', sprintf( 'UNTIL "%s" is not a real date.', $value ) );
					}
					if ( (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 60 ) {
						return self::err( 'until_invalid', sprintf( 'UNTIL "%s" is not a real time.', $value ) );
					}
					$out['until_utc'] = sprintf( '%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6] );
					return $out;
				}
				/* A local/floating DATE-TIME, or a bare DATE. Both are legal RRULE in
				 * other circumstances, which is exactly why they must be named rather
				 * than lumped in with garbage. */
				if ( preg_match( '/^\d{8}(T\d{6})?$/', $value ) ) {
					$out['_until_was_local'] = true;
					return $out;
				}
				return self::err( 'until_invalid', sprintf( 'UNTIL "%s" is not a valid date-time.', $value ) );

			case 'BYDAY':
				$days = array();
				foreach ( explode( ',', $value ) as $tok ) {
					$tok = strtoupper( trim( $tok ) );
					if ( ! preg_match( '/^([+-]?\d{1,2})?(SU|MO|TU|WE|TH|FR|SA)$/', $tok, $m ) ) {
						return self::err( 'byday_invalid', sprintf( '"%s" is not a valid BYDAY value.', $tok ) );
					}
					$ord = ( isset( $m[1] ) && '' !== $m[1] ) ? (int) $m[1] : null;
					if ( null !== $ord && ( 0 === $ord || $ord > 53 || $ord < -53 ) ) {
						return self::err( 'byday_ordinal_invalid', sprintf( 'BYDAY ordinal "%s" is out of range.', $m[1] ) );
					}
					$days[] = array( 'ord' => $ord, 'day' => $m[2] );
				}
				if ( empty( $days ) ) {
					return self::err( 'byday_empty', 'BYDAY is present but empty.' );
				}
				$out['byday'] = $days;
				return $out;

			case 'BYMONTHDAY':
				$list = array();
				foreach ( explode( ',', $value ) as $tok ) {
					$tok = trim( $tok );
					if ( ! preg_match( '/^[+-]?\d{1,2}$/', $tok ) ) {
						return self::err( 'bymonthday_invalid', sprintf( '"%s" is not a valid BYMONTHDAY value.', $tok ) );
					}
					$n = (int) $tok;
					/* 0 is not a day of the month in either direction. */
					if ( 0 === $n || $n > 31 || $n < -31 ) {
						return self::err( 'bymonthday_range', sprintf( 'BYMONTHDAY "%s" is out of range (1..31 or -1..-31).', $tok ) );
					}
					$list[] = $n;
				}
				$out['bymonthday'] = $list;
				return $out;

			case 'BYMONTH':
				$list = array();
				foreach ( explode( ',', $value ) as $tok ) {
					$tok = trim( $tok );
					if ( ! preg_match( '/^\d{1,2}$/', $tok ) ) {
						return self::err( 'bymonth_invalid', sprintf( '"%s" is not a valid BYMONTH value.', $tok ) );
					}
					$n = (int) $tok;
					if ( $n < 1 || $n > 12 ) {
						return self::err( 'bymonth_range', sprintf( 'BYMONTH "%s" is out of range (1..12).', $tok ) );
					}
					$list[] = $n;
				}
				$out['bymonth'] = $list;
				return $out;

			case 'WKST':
				$v = strtoupper( $value );
				if ( ! in_array( $v, self::DAYS, true ) ) {
					return self::err( 'wkst_invalid', sprintf( '"%s" is not a valid WKST.', $value ) );
				}
				$out['wkst'] = $v;
				return $out;
		}
		return $out;   // unreachable; PARTS is closed
	}

	/**
	 * Re-emit a parsed rule as a canonical RRULE string.
	 *
	 * Used by the "this and following" split, which has to hand back a truncated
	 * copy of a rule it was given. Re-emitting from the PARSED form rather than
	 * string-editing the original is deliberate: a textual edit of a rule is how
	 * you end up with `FREQ=DAILY;COUNT=5;UNTIL=…`, which this very class refuses.
	 *
	 * @param array $p a successful parse() result.
	 * @return string
	 */
	public static function to_string( array $p ) {
		if ( empty( $p['ok'] ) ) {
			return '';
		}
		$parts = array( 'FREQ=' . $p['freq'] );
		if ( (int) $p['interval'] > 1 ) {
			$parts[] = 'INTERVAL=' . (int) $p['interval'];
		}
		if ( ! empty( $p['bymonth'] ) ) {
			$parts[] = 'BYMONTH=' . implode( ',', $p['bymonth'] );
		}
		if ( ! empty( $p['bymonthday'] ) ) {
			$parts[] = 'BYMONTHDAY=' . implode( ',', $p['bymonthday'] );
		}
		if ( ! empty( $p['byday'] ) ) {
			$tok = array();
			foreach ( $p['byday'] as $bd ) {
				$tok[] = ( null === $bd['ord'] ? '' : (string) $bd['ord'] ) . $bd['day'];
			}
			$parts[] = 'BYDAY=' . implode( ',', $tok );
		}
		/* COUNT and UNTIL are mutually exclusive, so at most one is emitted — the
		 * canonical form cannot express the thing parse() refuses. */
		if ( (int) $p['count'] > 0 ) {
			$parts[] = 'COUNT=' . (int) $p['count'];
		} elseif ( '' !== $p['until_utc'] ) {
			$parts[] = 'UNTIL=' . str_replace( array( '-', ' ', ':' ), array( '', 'T', '' ), $p['until_utc'] ) . 'Z';
		}
		if ( 'MO' !== $p['wkst'] ) {
			$parts[] = 'WKST=' . $p['wkst'];
		}
		return implode( ';', $parts );
	}

	/**
	 * @param string $code
	 * @param string $message
	 * @return array
	 */
	private static function err( $code, $message ) {
		return array( 'ok' => false, 'code' => $code, 'error' => $message );
	}
}
