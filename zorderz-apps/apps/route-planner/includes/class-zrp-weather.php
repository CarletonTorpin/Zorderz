<?php
/**
 * ZRP_Weather -- the forecast for a planned walk.
 *
 * The planner's OWN call to Open-Meteo (free, no key), as Doorhangers' enrich call is Doorhangers' own: an outside
 * source, not another app's data. It asks for what Doorhangers' call does not: the chance of rain and the
 * amount, and the wind (sustained and gusts), hour by hour for the next 16 days.
 *
 * The limits (Zorderz -> Route Planner; starting values, the business sets its own): no hanging when, in the walking
 * window, the chance of rain is over its limit, or more rain than its limit is forecast that day, or the steady wind
 * is over its limit. The wind is two things, each labelled. The STEADY wind (the forecast's sustained wind) over its
 * limit flags the day. GUSTS over their own limit do not flag the day: the day is GUSTY, keeps status ok, carries the
 * warning "Gusty: hangers may blow off doors", and plan.js scores it lower. Gusts over GUST_FLAG x their limit flag
 * the day on their own. A day over a limit is FLAGGED and the nearest good day in the forecast is suggested (a calm
 * one before a gusty one). A date past the forecast's last day says "no forecast yet" -- never a guess, never a
 * climate average.
 * A forecast that did not answer says so; planning goes on.
 *
 * Nothing is stored but a 30-minute cache of the answer per ~2 km square (a transient).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZRP_Weather {

	const URL      = 'https://api.open-meteo.com/v1/forecast';
	const DAYS     = 16;      // Open-Meteo's forecast range
	const CACHE_S  = 1800;
	const SUGGEST_DAYS = 7;   // look this far either side for a good day
	const TIMEOUT_S = 6;
	const DOWN_S    = 120;   // after a failed call, the forecast is "did not answer" for this long
	const GUST_FLAG = 1.5;   // gusts over this many times their limit flag the day on their own (filter zrp_wx_gust_flag)
	const GUSTY     = 'Gusty: hangers may blow off doors';

	/** The weather limits from the settings. */
	public static function limits() {
		$s = zrp_get_settings();
		return array( 'rain_pct' => (int) $s['wx_rain_pct'], 'rain_in' => (float) $s['wx_rain_in'], 'wind_mph' => (int) $s['wx_wind_mph'], 'gust_mph' => (int) $s['wx_gust_mph'], 'gust_flag' => self::gust_flag() );
	}

	/** how many times the gust limit flags a day on its own. */
	public static function gust_flag() {
		$f = function_exists( 'apply_filters' ) ? apply_filters( 'zrp_wx_gust_flag', self::GUST_FLAG ) : self::GUST_FLAG;
		return is_numeric( $f ) ? max( 1.0, min( 5.0, (float) $f ) ) : self::GUST_FLAG;
	}

	/** The cache cell for a point: ~2 km squares, so neighbours share one call. */
	public static function cell( $lat, $lng ) {
		return sprintf( '%.2f,%.2f', round( (float) $lat * 50 ) / 50, round( (float) $lng * 50 ) / 50 );
	}

	/**
	 * Open-Meteo's answer for a point (16 days, hourly and daily, in the place's own time zone), or null with $err.
	 * Cached 30 minutes per cell.
	 */
	public static function fetch( $lat, $lng, &$err = '' ) {
		$err = '';
		$lat = (float) $lat;
		$lng = (float) $lng;
		if ( abs( $lat ) > 90 || abs( $lng ) > 180 || ( 0.0 === $lat && 0.0 === $lng ) ) {
			$err = 'no place';
			return null;
		}
		$key = 'zrp_wx_' . md5( self::cell( $lat, $lng ) );
		$hit = get_transient( $key );
		if ( is_array( $hit ) && isset( $hit['hourly'], $hit['daily'] ) ) {
			return $hit;
		}
		// Open-Meteo hanging (not refusing) cost 12 s per cell and up to 4 cells per plan -- past a web
		// server's time limit. Once it fails, it is not asked again for DOWN_S; every cell is told the same at once.
		$down = get_transient( 'zrp_wx_down' );
		if ( $down ) {
			$err = (string) $down;
			return null;
		}
		list( $clat, $clng ) = array_map( 'floatval', explode( ',', self::cell( $lat, $lng ) ) );
		$url = self::URL . '?' . http_build_query( array(
			'latitude'           => $clat,
			'longitude'          => $clng,
			'hourly'             => 'precipitation_probability,precipitation,wind_speed_10m,wind_gusts_10m',
			'daily'              => 'precipitation_sum,precipitation_probability_max,wind_speed_10m_max,wind_gusts_10m_max',
			'precipitation_unit' => 'inch',
			'wind_speed_unit'    => 'mph',
			'timezone'           => 'auto',
			'forecast_days'      => self::DAYS,
		) );
		$r = wp_remote_get( $url, array( 'timeout' => self::TIMEOUT_S, 'headers' => array( 'Accept' => 'application/json' ) ) );
		if ( is_wp_error( $r ) ) {
			$err = 'unreachable';
			set_transient( 'zrp_wx_down', $err, self::DOWN_S );
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		$j    = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		if ( 200 !== $code || ! is_array( $j ) || empty( $j['hourly']['time'] ) || empty( $j['daily']['time'] ) ) {
			$err = 'bad answer (HTTP ' . $code . ')';
			set_transient( 'zrp_wx_down', $err, self::DOWN_S );
			return null;
		}
		$keep = array(
			'hourly' => array_intersect_key( (array) $j['hourly'], array_flip( array( 'time', 'precipitation_probability', 'precipitation', 'wind_speed_10m', 'wind_gusts_10m' ) ) ),
			'daily'  => array_intersect_key( (array) $j['daily'], array_flip( array( 'time', 'precipitation_sum', 'precipitation_probability_max', 'wind_speed_10m_max', 'wind_gusts_10m_max' ) ) ),
			'tz'     => (string) ( $j['timezone'] ?? '' ),
		);
		set_transient( $key, $keep, self::CACHE_S );
		return $keep;
	}

	/** "10-12" for a walk starting at 10 that lasts up to two hours (Doorhangers' hours format, 24-hour). */
	public static function window( $start, $hours ) {
		$a = max( 0, min( 22, (int) $start ) );
		$b = max( $a + 1, min( 23, (int) ceil( $a + max( 0.5, (float) $hours ) ) ) );
		return $a . '-' . $b;
	}

	/** 20 -> "20", 22.5 -> "22.5", 1.5 -> "1.5". */
	private static function mph( $v ) {
		return rtrim( rtrim( number_format( (float) $v, 1, '.', '' ), '0' ), '.' );
	}

	private static function num( $v ) {
		return is_numeric( $v ) ? (float) $v : null;
	}

	/**
	 * One day's walking window against the limits. $om: fetch()'s answer. Returns
	 *   {date, status ok | flagged | none, window, rain_pct, rain_in, wind_mph, gust_mph, gusty, reasons [], warnings [],
	 *    range_end}
	 * status 'none' when the date is not in the forecast (past its last day): no figures at all. wind_mph is the steady
	 * wind, gust_mph the gusts. gusty: the gusts are over $lim['gust_mph'] (a warning, not a flag); over
	 * $lim['gust_flag'] x that, a reason (a flag). With no gust limit in $lim (an older caller), gusts are shown, not judged.
	 */
	public static function day( array $om, $date, $start, $hours, array $lim ) {
		$days  = array_values( (array) ( $om['daily']['time'] ?? array() ) );
		$last  = $days ? (string) end( $days ) : '';
		$win   = self::window( $start, $hours );
		$out   = array( 'date' => (string) $date, 'status' => 'none', 'window' => $win, 'rain_pct' => null, 'rain_in' => null, 'wind_mph' => null, 'gust_mph' => null, 'gusty' => false, 'reasons' => array(), 'warnings' => array(), 'range_end' => $last );
		$di    = array_search( (string) $date, $days, true );
		if ( false === $di ) {
			return $out;
		}
		list( $a, $b ) = array_map( 'intval', explode( '-', $win ) );
		$times = (array) ( $om['hourly']['time'] ?? array() );
		$got = array( 'precipitation_probability' => array(), 'precipitation' => array(), 'wind_speed_10m' => array(), 'wind_gusts_10m' => array() );
		foreach ( $times as $i => $t ) {
			if ( 0 !== strpos( (string) $t, (string) $date . 'T' ) ) {
				continue;
			}
			$h = (int) substr( (string) $t, 11, 2 );
			if ( $h < $a || $h > $b ) {
				continue;
			}
			foreach ( array_keys( $got ) as $k ) {
				$v = self::num( $om['hourly'][ $k ][ $i ] ?? null );
				if ( null !== $v ) {
					$got[ $k ][] = $v;
				}
			}
		}
		$pp = $got['precipitation_probability'];
		$pr = $got['precipitation'];
		$ws = $got['wind_speed_10m'];
		$wg = $got['wind_gusts_10m'];
		$day_sum = self::num( $om['daily']['precipitation_sum'][ $di ] ?? null );
		if ( ! $pp || ! $ws || ( null === $day_sum && ! $pr ) ) {
			// a verdict needs all three -- the rain chance and the wind in the window, and an amount.
			// Part of a forecast (an hour range the model does not reach yet) is not "good for hanging".
			$out['partial'] = (bool) ( $pp || $ws || null !== $day_sum );
			return $out;
		}
		$out['status']   = 'ok';
		$out['rain_pct'] = $pp ? (int) round( max( $pp ) ) : null;
		$out['rain_in']  = null !== $day_sum ? round( max( $day_sum, $pr ? array_sum( $pr ) : 0 ), 2 ) : ( $pr ? round( array_sum( $pr ), 2 ) : null );
		$out['wind_mph'] = $ws ? (int) round( max( $ws ) ) : null;
		$out['gust_mph'] = $wg ? (int) round( max( $wg ) ) : null;
		if ( null !== $out['rain_pct'] && $out['rain_pct'] > $lim['rain_pct'] ) {
			$out['reasons'][] = 'Rain chance ' . $out['rain_pct'] . '% (over ' . $lim['rain_pct'] . '%)';
		}
		if ( null !== $out['rain_in'] && $out['rain_in'] > $lim['rain_in'] ) {
			$out['reasons'][] = rtrim( rtrim( number_format( $out['rain_in'], 2 ), '0' ), '.' ) . ' in of rain that day (over ' . rtrim( rtrim( number_format( $lim['rain_in'], 2 ), '0' ), '.' ) . ' in)';
		}
		if ( null !== $out['wind_mph'] && $out['wind_mph'] > $lim['wind_mph'] ) {
			$out['reasons'][] = 'Steady wind ' . $out['wind_mph'] . ' mph (over ' . $lim['wind_mph'] . ' mph)';
		}
		// gusts, against their own limit. Over it: a warning and a lower score (plan.js), the day still good.
		// Over gust_flag x it: the day is flagged by the gusts alone.
		$gl = isset( $lim['gust_mph'] ) && is_numeric( $lim['gust_mph'] ) && (float) $lim['gust_mph'] > 0 ? (float) $lim['gust_mph'] : null;
		if ( null !== $gl && null !== $out['gust_mph'] && $out['gust_mph'] > $gl ) {
			$gf  = isset( $lim['gust_flag'] ) && is_numeric( $lim['gust_flag'] ) ? max( 1.0, (float) $lim['gust_flag'] ) : self::GUST_FLAG;
			$top = $gl * $gf;
			if ( $out['gust_mph'] > $top ) {
				$out['reasons'][] = 'Gusts to ' . $out['gust_mph'] . ' mph (over ' . self::mph( $top ) . ' mph, ' . self::mph( $gf ) . ' x the gust limit)';
			} else {
				$out['gusty']      = true;
				$out['warnings'][] = self::GUSTY . ' (gusts to ' . $out['gust_mph'] . ' mph, over ' . self::mph( $gl ) . ' mph)';
			}
		}
		if ( $out['reasons'] ) {
			$out['status'] = 'flagged';
		}
		return $out;
	}

	/**
	 * The day, and when it is flagged the nearest good day in the forecast (the next day before the day before, and so
	 * on, never before the forecast's first day = today): {..day(), suggest: day() | null}.
	 */
	public static function judge( array $om, $date, $start, $hours, array $lim, $today = '', $hour_now = null ) {
		$d = self::day( $om, $date, $start, $hours, $lim );
		$d['suggest'] = null;
		if ( 'flagged' !== $d['status'] ) {
			return $d;
		}
		$t = strtotime( $date . ' 12:00:00 UTC' );
		$gusty = null; // the nearest good day that is gusty, used only when no calm one is within the week
		for ( $i = 1; $i <= self::SUGGEST_DAYS; $i++ ) {
			foreach ( array( $i, -$i ) as $off ) {
				$cand = gmdate( 'Y-m-d', $t + $off * 86400 );
				// never a day gone by, nor today once its walking window has begun
				if ( '' !== $today && ( $cand < $today || ( $cand === $today && null !== $hour_now && (int) $hour_now >= (int) $start ) ) ) {
					continue;
				}
				$c    = self::day( $om, $cand, $start, $hours, $lim );
				if ( 'ok' === $c['status'] ) {
					unset( $c['range_end'] );
					if ( empty( $c['gusty'] ) ) {
						$d['suggest'] = $c;
						return $d;
					}
					if ( null === $gusty ) {
						$gusty = $c;
					}
				}
			}
		}
		$d['suggest'] = $gusty;
		return $d;
	}

	/** The forecast for a point and walk, or {status: 'error', message} when Open-Meteo did not answer. */
	public static function for_walk( $lat, $lng, $date, $start, $hours ) {
		$err = '';
		$om  = self::fetch( $lat, $lng, $err );
		if ( null === $om ) {
			return array( 'date' => (string) $date, 'status' => 'error', 'window' => self::window( $start, $hours ), 'reasons' => array(), 'suggest' => null,
				'message' => 'The forecast did not answer (' . $err . '). Check the weather before you go.' );
		}
		$today = function_exists( 'current_time' ) ? (string) current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		$hour  = function_exists( 'current_time' ) ? (int) current_time( 'G' ) : (int) gmdate( 'G' );
		$j = self::judge( $om, $date, $start, $hours, self::limits(), $today, $hour );
		if ( 'none' === $j['status'] ) {
			$j['message'] = ! empty( $j['partial'] )
				? 'The forecast for ' . $date . ' does not cover the whole walking window yet (no rain chance or wind for those hours). Check again closer to the day.'
				: 'No forecast yet for ' . $date . ': forecasts reach ' . ( $j['range_end'] ?: 'about two weeks ahead' ) . '. Check again closer to the day.';
		}
		return $j;
	}
}
