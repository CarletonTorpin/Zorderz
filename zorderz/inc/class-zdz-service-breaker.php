<?php
/**
 * Zdz_Service_Breaker — a general per-service circuit breaker.
 *
 * A sibling to the token-refresh cooldown already in class-zdz-token-service.php
 * (which is refresh-specific, keyed on zdz_tok_*_cooldown_until). This one is the
 * GENERAL per-service breaker: it short-circuits a sick upstream so a background
 * loop stops hammering it, and — critically — it distinguishes a sick upstream
 * from OUR OWN misconfiguration:
 *
 *   - N consecutive hard failures (default 3) → a timed pause (default 15 min).
 *     One success resets the counter AND clears the pause.
 *   - The AUTH WALL: N consecutive 401s (default 3) return an abort signal but
 *     DELIBERATELY DO NOT trip the breaker. A 401 is our config (a bad/absent
 *     credential), not a sick upstream — pausing the service would just hide a
 *     fixable misconfiguration behind a 15-minute wait.
 *
 * State is runtime options only (zdz_breaker_<service>_*); no table, no schema,
 * no seeded data. The <service> string is always the caller's — a provider name
 * resolved from the connections pack at the call site — never hardcoded here, so
 * no vendor literal lives in this file. [CORE] mechanism throughout.
 *
 * @since   1.7.0
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Zdz_Service_Breaker', false ) ) :

class Zdz_Service_Breaker {

	/** Sentinel returned by guard() when the breaker is open (call not made). */
	const OPEN_SENTINEL = 'breaker_open';

	private static function key( $service, $suffix ) {
		return 'zdz_breaker_' . sanitize_key( (string) $service ) . '_' . $suffix;
	}

	/** Is the breaker currently paused for this service? */
	public static function is_open( $service ) {
		return (int) get_option( self::key( $service, 'until' ), 0 ) > time();
	}

	/** Seconds until the pause lifts (0 if not open). */
	public static function retry_after( $service ) {
		$until = (int) get_option( self::key( $service, 'until' ), 0 );
		return max( 0, $until - time() );
	}

	/**
	 * Record a hard failure. At the consecutive-failure threshold, open the
	 * breaker for the pause window and log a disposition.
	 */
	public static function record_failure( $service ) {
		$fails = (int) get_option( self::key( $service, 'fails' ), 0 ) + 1;
		update_option( self::key( $service, 'fails' ), $fails, false );

		$threshold = (int) apply_filters( 'zdz_breaker_fail_threshold', 3 );
		if ( $fails >= $threshold ) {
			$pause = (int) apply_filters( 'zdz_breaker_pause_seconds', 900 );
			update_option( self::key( $service, 'until' ), time() + $pause, false );
			self::log_disposition( $service, 'breaker_open', sprintf(
				'%d consecutive failures — pausing %ds',
				$fails, $pause
			) );
		}
	}

	/**
	 * Record a success: reset the failure counter AND the auth-wall counter, and
	 * clear any active pause. One success closes the breaker.
	 */
	public static function record_success( $service ) {
		update_option( self::key( $service, 'fails' ), 0, false );
		update_option( self::key( $service, 'auth401' ), 0, false );
		if ( (int) get_option( self::key( $service, 'until' ), 0 ) > 0 ) {
			delete_option( self::key( $service, 'until' ) );
		}
	}

	/**
	 * The AUTH WALL. Record a 401 (auth/config failure). At the consecutive-401
	 * threshold, return an abort signal — but DO NOT trip the breaker (a 401 is
	 * our config, not a sick upstream). A non-401 success elsewhere resets it via
	 * record_success().
	 *
	 * @return bool true when the caller should ABORT (config wall hit).
	 */
	public static function record_auth_failure( $service ) {
		$count = (int) get_option( self::key( $service, 'auth401' ), 0 ) + 1;
		update_option( self::key( $service, 'auth401' ), $count, false );

		$threshold = (int) apply_filters( 'zdz_breaker_auth_threshold', 3 );
		if ( $count >= $threshold ) {
			self::log_disposition( $service, 'auth_wall', sprintf(
				'%d consecutive 401s — aborting WITHOUT tripping the breaker (this is config, not a sick upstream)',
				$count
			) );
			return true;
		}
		return false;
	}

	/**
	 * Convenience runner: if the breaker is open, return OPEN_SENTINEL without
	 * calling $fn; otherwise run $fn and route the outcome to
	 * record_success/record_failure. $fn should return false / throw on failure.
	 *
	 * @return mixed  $fn's return value, or OPEN_SENTINEL when open.
	 */
	public static function guard( $service, callable $fn ) {
		if ( self::is_open( $service ) ) {
			return self::OPEN_SENTINEL;
		}
		try {
			$result = $fn();
		} catch ( \Throwable $e ) {
			self::record_failure( $service );
			throw $e;
		}
		if ( false === $result || null === $result ) {
			self::record_failure( $service );
		} else {
			self::record_success( $service );
		}
		return $result;
	}

	private static function log_disposition( $service, $kind, $detail ) {
		$msg = sprintf( '[Zdz_Service_Breaker] service=%s %s: %s', (string) $service, $kind, $detail );
		if ( function_exists( 'do_action' ) ) {
			do_action( 'zdz_flow_disposition', 'service_breaker', $kind, array(
				'service' => (string) $service,
				'detail'  => $detail,
			) );
		}
		error_log( $msg );
	}
}

endif;
