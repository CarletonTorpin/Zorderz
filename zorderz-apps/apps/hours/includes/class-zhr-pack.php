<?php
/**
 * ZHR_Pack -- Hours' part of an Identity Pack: the business's working-time rules (rules.yml) and how hourly pay is
 * counted (compensation.yml). Written into the option zhr_settings, checked exactly as the app's own Settings check it.
 *
 *   rules.yml
 *     hours:
 *       week_start: monday               # a weekday name, or 1 (Monday) to 7 (Sunday)
 *       standard_hours:                  # every weekday not listed (or listed as off) is a day off
 *         monday: { start: "09:00", end: "17:00" }
 *       edit_weeks: 1                    # weeks a person may change their own hours (1 = this week only)
 *       remind_at: ""                    # a clock still running at this time gets one notice; "" = none
 *       everyone_on_staff: false         # every staff role holds Hours (a per-person deny still wins)
 *       crew_leads_see_crew: false       # a crew lead reads their crew's hours (never changes them)
 *       location: { ask_on_clock: false, note_while_on_clock: false }
 *       meal_periods:                    # at most two; none = no meal is required or asked about
 *         - { after_minutes: 0, length_minutes: 0, waivable_up_to_minutes: null }
 *         - { after_minutes: 0, length_minutes: 0, waivable_up_to_minutes: null, waivable_only_if_previous_not_waived: false }
 *   compensation.yml
 *     hourly:
 *       meal_premium_minutes: 0          # owed for a day with a required meal missed, late or short, not by choice
 *       overtime: { daily_after_minutes: null, weekly_after_minutes: null }
 *       pay_period: { kind: "", anchor: "" }   # weekly | biweekly (anchor: the first day of any one) | semimonthly | monthly
 *
 * A key the pack leaves out is left as it is. A value that cannot be read is refused with the reason and changes
 * nothing; a key Hours does not know is reported as unused. Revert puts the settings back as they were.
 *
 * Registered on the Identity Pack's consumer registry (`zdz_identity_pack_consumers`).
 *
 * @package Zorderz\Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZHR_Pack {

	const DAYS = array( 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7 );

	const RULES_KEYS = array( 'week_start', 'standard_hours', 'edit_weeks', 'remind_at', 'everyone_on_staff', 'crew_leads_see_crew', 'location', 'meal_periods' );
	const PAY_KEYS   = array( 'meal_premium_minutes', 'overtime', 'pay_period' );

	public static function register( $consumers ) {
		$consumers          = is_array( $consumers ) ? $consumers : array();
		$consumers['hours'] = array(
			'label'    => __( 'Hours', 'zorderz' ),
			'files'    => array( 'rules', 'compensation' ),
			'paths'    => array( 'rules' => array( 'hours' ), 'compensation' => array( 'hourly' ) ),
			'preview'  => array( __CLASS__, 'preview' ),
			'apply'    => array( __CLASS__, 'apply' ),
			'snapshot' => array( __CLASS__, 'snapshot' ),
			'restore'  => array( __CLASS__, 'restore' ),
		);
		return $consumers;
	}

	private static function flag( $v, &$ok ) {
		if ( is_bool( $v ) ) {
			$ok = true;
			return $v;
		}
		$s  = strtolower( trim( (string) $v ) );
		$ok = in_array( $s, array( 'true', 'false', 'yes', 'no', 'on', 'off', '1', '0' ), true );
		return in_array( $s, array( 'true', 'yes', 'on', '1' ), true );
	}

	private static function minutes_or_null( $v, $max, &$ok ) {
		if ( null === $v || '' === $v || 'null' === $v ) {
			$ok = true;
			return null;
		}
		$ok = is_numeric( $v ) && (int) round( (float) $v ) >= 1 && (int) round( (float) $v ) <= $max;
		return $ok ? (int) round( (float) $v ) : null;
	}

	/**
	 * What a pack's files say for Hours, checked (pure): ['patch' => settings keys => values (the zhr_settings shape),
	 * 'refused' => [ ['path','why'] ], 'unconsumed' => [ path ]], or null when the pack has neither block.
	 */
	public static function plan( array $files ) {
		$rules = $files['rules']['hours'] ?? null;
		$pay   = $files['compensation']['hourly'] ?? null;
		if ( ! is_array( $rules ) && ! is_array( $pay ) ) {
			return null;
		}
		$out   = array( 'patch' => array(), 'refused' => array(), 'unconsumed' => array() );
		$no    = function ( $path, $why ) use ( &$out ) { $out['refused'][] = array( 'path' => $path, 'why' => $why ); };
		$rules = is_array( $rules ) ? $rules : array();
		$pay   = is_array( $pay ) ? $pay : array();
		foreach ( array_diff( array_map( 'strval', array_keys( $rules ) ), self::RULES_KEYS ) as $k ) {
			$out['unconsumed'][] = "hours.$k";
		}
		foreach ( array_diff( array_map( 'strval', array_keys( $pay ) ), self::PAY_KEYS ) as $k ) {
			$out['unconsumed'][] = "hourly.$k";
		}
		if ( array_key_exists( 'week_start', $rules ) ) {
			$w = $rules['week_start'];
			$n = is_numeric( $w ) ? (int) $w : ( self::DAYS[ strtolower( trim( (string) $w ) ) ] ?? 0 );
			if ( $n >= 1 && $n <= 7 ) {
				$out['patch']['week_start'] = $n;
			} else {
				$no( 'hours.week_start', __( 'A weekday name, or 1 (Monday) to 7 (Sunday).', 'zorderz' ) );
			}
		}
		if ( array_key_exists( 'standard_hours', $rules ) ) {
			$sh   = is_array( $rules['standard_hours'] ) ? $rules['standard_hours'] : array();
			$days = array();
			$bad  = false;
			for ( $d = 1; $d <= 7; $d++ ) {
				$days[ $d ] = array( 'on' => false, 'start' => '', 'end' => '' );
			}
			foreach ( $sh as $name => $row ) {
				$d = self::DAYS[ strtolower( trim( (string) $name ) ) ] ?? 0;
				if ( ! $d ) {
					$out['unconsumed'][] = "hours.standard_hours.$name";
					continue;
				}
				if ( null === $row || ( is_array( $row ) && ! empty( $row['off'] ) ) ) {
					continue; // a day off
				}
				$s = is_array( $row ) ? ZHR_Time::hm_to_min( $row['start'] ?? '' ) : null;
				$e = is_array( $row ) ? ZHR_Time::hm_to_min( $row['end'] ?? '' ) : null;
				if ( null === $s || null === $e || $e <= $s ) {
					$no( "hours.standard_hours.$name", __( 'A day needs a start and an end, 24-hour HH:MM, the end after the start.', 'zorderz' ) );
					$bad = true;
					continue;
				}
				$days[ $d ] = array( 'on' => true, 'start' => ZHR_Time::min_to_hm( $s ), 'end' => ZHR_Time::min_to_hm( $e ) );
			}
			if ( ! $bad ) {
				$out['patch']['days'] = $days; // all seven together, or none: half a week is never applied
			}
		}
		if ( array_key_exists( 'edit_weeks', $rules ) ) {
			$v = $rules['edit_weeks'];
			if ( is_numeric( $v ) && (int) $v >= 1 && (int) $v <= 8 ) {
				$out['patch']['edit_weeks'] = (int) $v;
			} else {
				$no( 'hours.edit_weeks', __( 'Whole weeks, 1 to 8.', 'zorderz' ) );
			}
		}
		if ( array_key_exists( 'remind_at', $rules ) ) {
			$r = trim( (string) $rules['remind_at'] );
			if ( '' === $r || null !== ZHR_Time::hm_to_min( $r ) ) {
				$out['patch']['remind_at'] = $r;
			} else {
				$no( 'hours.remind_at', __( 'A time, 24-hour HH:MM, or "" for no reminder.', 'zorderz' ) );
			}
		}
		foreach ( array( 'everyone_on_staff' => 'everyone', 'crew_leads_see_crew' => 'crew_leads' ) as $k => $to ) {
			if ( array_key_exists( $k, $rules ) ) {
				$v = self::flag( $rules[ $k ], $ok );
				if ( $ok ) {
					$out['patch'][ $to ] = $v;
				} else {
					$no( "hours.$k", __( 'true or false.', 'zorderz' ) );
				}
			}
		}
		if ( array_key_exists( 'location', $rules ) ) {
			$loc = is_array( $rules['location'] ) ? $rules['location'] : array();
			foreach ( array( 'ask_on_clock' => 'ask_location', 'note_while_on_clock' => 'note_location' ) as $k => $to ) {
				if ( array_key_exists( $k, $loc ) ) {
					$v = self::flag( $loc[ $k ], $ok );
					if ( $ok ) {
						$out['patch'][ $to ] = $v;
					} else {
						$no( "hours.location.$k", __( 'true or false.', 'zorderz' ) );
					}
				}
			}
			foreach ( array_diff( array_map( 'strval', array_keys( $loc ) ), array( 'ask_on_clock', 'note_while_on_clock' ) ) as $k ) {
				$out['unconsumed'][] = "hours.location.$k";
			}
		}
		$meals = null;
		if ( array_key_exists( 'meal_periods', $rules ) ) {
			$list = is_array( $rules['meal_periods'] ) ? array_values( $rules['meal_periods'] ) : array();
			if ( count( $list ) > ZHR_Rules::MAX_MEALS ) {
				$no( 'hours.meal_periods', __( 'At most two meal periods a day.', 'zorderz' ) );
			} else {
				$periods = array();
				foreach ( $list as $i => $p ) {
					$p     = is_array( $p ) ? $p : array();
					$after = self::minutes_or_null( $p['after_minutes'] ?? null, 1440, $ok1 );
					$len   = self::minutes_or_null( $p['length_minutes'] ?? null, 240, $ok2 );
					$waive = self::minutes_or_null( $p['waivable_up_to_minutes'] ?? null, 1440, $ok3 );
					if ( ! $ok1 || ! $ok2 || ! $ok3 || null === $after || null === $len || ( null !== $waive && $waive <= $after ) ) {
						$no( 'hours.meal_periods.' . ( $i + 1 ), __( 'A meal period needs after_minutes (1-1440) and length_minutes (1-240); a waiver limit, when given, is longer than after_minutes.', 'zorderz' ) );
						$periods = null;
						break;
					}
					$periods[] = array( 'after' => $after, 'length' => $len, 'waive_up_to' => $waive, 'waive_if_previous_not_waived' => self::flag( $p['waivable_only_if_previous_not_waived'] ?? false, $okf ) && $okf );
				}
				if ( null !== $periods ) {
					$meals = array( 'periods' => $periods );
				}
			}
		}
		if ( array_key_exists( 'meal_premium_minutes', $pay ) ) {
			$v = $pay['meal_premium_minutes'];
			if ( is_numeric( $v ) && (int) $v >= 0 && (int) $v <= 480 ) {
				$meals = ( $meals ?? array() ) + array( 'premium_minutes' => (int) $v );
			} else {
				$no( 'hourly.meal_premium_minutes', __( 'Whole minutes, 0 to 480.', 'zorderz' ) );
			}
		}
		if ( null !== $meals ) {
			$out['patch']['meals'] = $meals; // completed against the current rules when applied
		}
		if ( array_key_exists( 'overtime', $pay ) ) {
			$o  = is_array( $pay['overtime'] ) ? $pay['overtime'] : array();
			$d  = self::minutes_or_null( $o['daily_after_minutes'] ?? null, 1440, $okd );
			$w  = self::minutes_or_null( $o['weekly_after_minutes'] ?? null, 10080, $okw );
			if ( $okd && $okw ) {
				$out['patch']['overtime'] = array( 'daily_after' => $d, 'weekly_after' => $w );
			} else {
				$no( 'hourly.overtime', __( 'daily_after_minutes (1-1440) and weekly_after_minutes (1-10080), or null for no line.', 'zorderz' ) );
			}
		}
		if ( array_key_exists( 'pay_period', $pay ) ) {
			$pp   = is_array( $pay['pay_period'] ) ? $pay['pay_period'] : array();
			$kind = strtolower( trim( (string) ( $pp['kind'] ?? '' ) ) );
			$c    = ZHR_Rules::clean_pay_period( array( 'kind' => $kind, 'anchor' => (string) ( $pp['anchor'] ?? '' ) ) );
			if ( $c['kind'] === $kind ) {
				$out['patch']['pay_period'] = $c;
			} else {
				$no( 'hourly.pay_period', __( 'kind is weekly, biweekly (with anchor: the first day of any one, YYYY-MM-DD), semimonthly, monthly, or "" for none.', 'zorderz' ) );
			}
		}
		return $out;
	}

	/** The settings in force with the pack's patch on top, cleaned as the app's Settings clean them. */
	private static function after( array $patch ) {
		$cur = zhr_get_settings();
		if ( isset( $patch['meals'] ) ) {
			$patch['meals'] = $patch['meals'] + $cur['meals']; // a premium alone keeps the periods in force, and the other way round
		}
		return ZHR_Time::clean_settings( $patch, $cur );
	}

	private static function file_of( $key ) {
		return in_array( $key, array( 'overtime', 'pay_period' ), true ) ? 'compensation' : 'rules';
	}

	public static function preview( array $files ) {
		$p = self::plan( $files );
		if ( null === $p ) {
			return array();
		}
		$rows = array();
		$cur  = zhr_get_settings();
		$new  = self::after( $p['patch'] );
		foreach ( array_keys( $p['patch'] ) as $k ) {
			if ( $cur[ $k ] !== $new[ $k ] ) {
				$rows[] = array( 'file' => self::file_of( $k ), 'path' => 'hours.' . $k, 'from' => wp_json_encode( $cur[ $k ] ), 'to' => wp_json_encode( $new[ $k ] ) );
			}
		}
		foreach ( $p['refused'] as $r ) {
			$rows[] = array( 'file' => 0 === strpos( $r['path'], 'hourly.' ) ? 'compensation' : 'rules', 'path' => $r['path'], 'from' => '', 'to' => '', 'kind' => 'refused', 'why' => $r['why'] );
		}
		foreach ( $p['unconsumed'] as $u ) {
			$rows[] = array( 'file' => 0 === strpos( $u, 'hourly.' ) ? 'compensation' : 'rules', 'path' => $u, 'from' => '', 'to' => '', 'kind' => 'unconsumed' );
		}
		return $rows;
	}

	public static function apply( array $files, $slug = '' ) {
		$p = self::plan( $files );
		if ( null === $p ) {
			return array( __( 'Hours: the pack has no hours rules; left as they were.', 'zorderz' ) );
		}
		$msgs = array();
		if ( $p['patch'] ) {
			update_option( 'zhr_settings', self::after( $p['patch'] ), false );
			/* translators: %d: number of settings */
			$msgs[] = sprintf( _n( 'Hours: %d setting applied.', 'Hours: %d settings applied.', count( $p['patch'] ), 'zorderz' ), count( $p['patch'] ) );
		}
		foreach ( $p['refused'] as $r ) {
			/* translators: 1: a pack path, 2: why */
			$msgs[] = sprintf( __( 'Hours: %1$s left as it was: %2$s', 'zorderz' ), $r['path'], $r['why'] );
		}
		return $msgs;
	}

	/** What to keep before a pack writes: the option as it was, or that there was none. */
	public static function snapshot( ...$unused ) {
		$v = get_option( 'zhr_settings', null );
		return array( 'had' => is_array( $v ), 'value' => is_array( $v ) ? $v : null );
	}

	public static function restore( $kept ) {
		if ( is_array( $kept ) && ! empty( $kept['had'] ) && is_array( $kept['value'] ) ) {
			update_option( 'zhr_settings', $kept['value'], false );
		} elseif ( is_array( $kept ) ) {
			delete_option( 'zhr_settings' );
		}
		return array();
	}
}
