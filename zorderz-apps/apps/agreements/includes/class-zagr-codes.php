<?php
/**
 * ZAGR_Codes - the emailed 6-digit code. [CORE]
 *
 *   6 digits from the OS CSPRNG; valid 10 minutes; 5 tries, counted in the database with a conditional UPDATE (two
 *   requests can't both take the 5th); a new code no sooner than 60 seconds after the last one for this session. A new
 *   code ends the earlier ones.
 *   Bound to: the agreement, the session that asked (the cookie's row), the purpose (sign | change | manage) and the
 *   exact document version, so a code sent for one version never signs another.
 *   Kept only as a MAC under the agreements key (the codes subkey); the code itself exists only in the email.
 *
 * Budgets, so that a stranger with the link can never pause the customer:
 *   a source        one visitor: their address (ip_tag, an HMAC of the IP), or their session when there is none
 *   per session     3 codes an hour
 *   per address     10 codes an hour and 20 a day, whatever the agreement
 *   wrong answers   only an answer compared against a live code this session was sent counts ("wrong", or the 5th
 *                   "tries"); a check with no code, an expired code, or a used-up code counts for nothing. 20 from one
 *                   source pause that source, and only that source, for that agreement and purpose, for 24 hours or
 *                   until an administrator turns codes back on (staff are told; the record says codes_locked).
 *   per agreement   25 codes an hour and 60 a day, only against floods of email to the customer: two addresses at
 *                   their own limits can never reach it, and a source a code was used from for this agreement (in the
 *                   last 30 days: the customer, by every sign) is never held by it. Past it, only a source that already
 *                   got 2 codes for this agreement that day is held, so a new place (the customer's phone) still gets
 *                   its first codes; past 120 in a day, every source but a proven one is held. When it is reached the
 *                   customer and the cancellations address are emailed (once a day), the customer with how to cancel
 *                   by email or letter.
 *   wrong answers   also a ceiling per agreement and purpose: 20 wrong answers a day from places no code was used from;
 *   per agreement   past it, those places get no code and no comparison that day ('paused'), while a proven place is
 *                   never held. The customer and staff are emailed, as for the cap.
 *                   An administrator's "Turn codes back on" starts every count again from that moment.
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAGR_Codes {

	const TTL              = 600;
	const TRIES            = 5;
	const RESEND           = 60;
	const SESSION_PER_HOUR = 3;
	const IP_PER_HOUR      = 10;
	const IP_PER_DAY       = 20;
	const PER_HOUR         = 25;
	const PER_DAY          = 60;
	const NEW_PER_DAY      = 2;     // codes a day a new place still gets past PER_DAY / PER_HOUR
	const HARD_PER_DAY     = 120;   // past this, only a place a code was used from
	const WRONG_PER_DAY    = 20;    // wrong answers a day per purpose from places no code was used from
	const LOCK_AFTER       = 20;
	const LOCK_FOR         = 86400;
	const PROVEN_DAYS      = 30;
	const RESET_OPT        = 'zagr_codes_reset_';
	const PURPOSES         = array( 'sign', 'change', 'manage' );

	private static function mac( int $agreement_id, int $session_id, string $purpose, string $bound_for, string $code ): ?string {
		return ZAGR_Key::mac( 'code|' . $agreement_id . '|' . $session_id . '|' . $purpose . '|' . $bound_for . '|' . $code );
	}

	/** This request's source: the visitor's ip_tag, or its session when there is no address. */
	public static function source( int $session_id ): string {
		$tag = ZAGR_People::ip_tag();
		return '' !== $tag ? $tag : 's' . $session_id;
	}

	private static function count( string $where, array $args ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ZAGR_Install::t( 'codes' ) . ' WHERE ' . $where, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name; $where is a fixed fragment from this class, with a placeholder for every value
	}

	/** When the agreement-wide counts start: the later of $since and an administrator's last reset. */
	private static function since( int $agreement_id, int $since ): string {
		$r = (int) get_option( self::RESET_OPT . $agreement_id, 0 );
		return ZAGR_Clock::utc( max( $since, $r ) );
	}

	/** Has a code been used from this address for this agreement lately (the customer's own connection, by every sign)? */
	private static function proven( int $agreement_id, string $tag ): bool {
		return '' !== $tag && self::count( 'agreement_id = %d AND ip_tag = %s AND used_at IS NOT NULL AND used_at > %s', array( $agreement_id, $tag, ZAGR_Clock::utc( ZAGR_Clock::now() - self::PROVEN_DAYS * DAY_IN_SECONDS ) ) ) > 0;
	}

	/**
	 * the wrong answers in the last day (since a reset) to $purpose's codes from places no code was used
	 * from. A code's tries are its wrong answers until it is used; a used code's place is proven, so it isn't counted.
	 */
	public static function wrong_today( int $agreement_id, string $purpose ): int {
		global $wpdb;
		$t = ZAGR_Install::t( 'codes' );
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(tries), 0) FROM $t WHERE agreement_id = %d AND purpose = %s AND used_at IS NULL AND created_at > %s" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
			. " AND ( ip_tag = '' OR ip_tag NOT IN ( SELECT ip_tag FROM $t WHERE agreement_id = %d AND ip_tag <> '' AND used_at IS NOT NULL AND used_at > %s ) )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
			$agreement_id, $purpose, self::since( $agreement_id, ZAGR_Clock::now() - DAY_IN_SECONDS ),
			$agreement_id, ZAGR_Clock::utc( ZAGR_Clock::now() - self::PROVEN_DAYS * DAY_IN_SECONDS )
		) );
	}

	/** is $purpose paused today for every place no code was used from? */
	public static function ceiling( int $agreement_id, string $purpose ): bool {
		return self::wrong_today( $agreement_id, $purpose ) >= self::WRONG_PER_DAY;
	}

	/**
	 * Make a code for this session. { ok, why (sent | wait | limit | locked | paused | no_key | error), plain (the code,
	 * for the email only; never stored, never logged) }.
	 */
	public static function issue( int $agreement_id, int $session_id, string $purpose, string $bound_for ): array {
		global $wpdb;
		if ( ! in_array( $purpose, self::PURPOSES, true ) ) {
			return array( 'ok' => false, 'why' => 'error', 'plain' => '' );
		}
		if ( ! ZAGR_Key::usable() ) {
			return array( 'ok' => false, 'why' => 'no_key', 'plain' => '' );
		}
		$source = self::source( $session_id );
		if ( self::is_locked( $agreement_id, $purpose, $source ) ) {
			return array( 'ok' => false, 'why' => 'locked', 'plain' => '' );
		}
		$tag    = ZAGR_People::ip_tag();
		$proven = self::proven( $agreement_id, $tag );
		if ( ! $proven && self::ceiling( $agreement_id, $purpose ) ) {
			self::alert( $agreement_id, $purpose, 'ceiling' );
			return array( 'ok' => false, 'why' => 'paused', 'plain' => '' );
		}
		$t    = ZAGR_Install::t( 'codes' );
		$now  = ZAGR_Clock::now();
		$hour = ZAGR_Clock::utc( $now - HOUR_IN_SECONDS );
		$day  = ZAGR_Clock::utc( $now - DAY_IN_SECONDS );
		$last = $wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM $t WHERE session_id = %d AND purpose = %s ORDER BY id DESC LIMIT 1", $session_id, $purpose ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
		if ( $last && ZAGR_Clock::ts( (string) $last ) > $now - self::RESEND ) {
			return array( 'ok' => false, 'why' => 'wait', 'plain' => '' );
		}
		// this session's own budget
		if ( self::count( 'session_id = %d AND created_at > %s', array( $session_id, $hour ) ) >= self::SESSION_PER_HOUR ) {
			return array( 'ok' => false, 'why' => 'limit', 'plain' => '' );
		}
		// this address's budget, whatever the agreement
		if ( '' !== $tag && ( self::count( 'ip_tag = %s AND created_at > %s', array( $tag, $hour ) ) >= self::IP_PER_HOUR
			|| self::count( 'ip_tag = %s AND created_at > %s', array( $tag, $day ) ) >= self::IP_PER_DAY ) ) {
			return array( 'ok' => false, 'why' => 'limit', 'plain' => '' );
		}
		// the agreement's own cap: only against a flood of email to the customer from many places at once; never for
		// the customer's own connection, and only for a place that already got its codes today
		$today = self::count( 'agreement_id = %d AND created_at > %s', array( $agreement_id, self::since( $agreement_id, $now - DAY_IN_SECONDS ) ) );
		if ( ! $proven && ( $today >= self::PER_DAY || self::count( 'agreement_id = %d AND created_at > %s', array( $agreement_id, self::since( $agreement_id, $now - HOUR_IN_SECONDS ) ) ) >= self::PER_HOUR ) ) {
			$mine = '' !== $tag
				? self::count( 'agreement_id = %d AND ip_tag = %s AND created_at > %s', array( $agreement_id, $tag, $day ) )
				: self::count( 'agreement_id = %d AND session_id = %d AND created_at > %s', array( $agreement_id, $session_id, $day ) );
			if ( $mine >= self::NEW_PER_DAY || $today >= self::HARD_PER_DAY ) {
				self::alert( $agreement_id, $purpose, 'limited' );
				return array( 'ok' => false, 'why' => 'limit', 'plain' => '' );
			}
		}
		$code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$mac  = self::mac( $agreement_id, $session_id, $purpose, $bound_for, $code );
		if ( null === $mac ) {
			return array( 'ok' => false, 'why' => 'no_key', 'plain' => '' );
		}
		// a new code ends the earlier ones of this session and purpose
		$wpdb->query( $wpdb->prepare( "UPDATE $t SET expires_at = %s WHERE session_id = %d AND purpose = %s AND used_at IS NULL AND expires_at > %s", ZAGR_Clock::utc( $now - 1 ), $session_id, $purpose, ZAGR_Clock::utc( $now - 1 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
		$ok = $wpdb->insert( $t, array(
			'agreement_id' => $agreement_id,
			'session_id'   => $session_id,
			'purpose'      => $purpose,
			'bound_for'    => substr( $bound_for, 0, 100 ),
			'code_mac'     => $mac,
			'created_at'   => ZAGR_Clock::utc( $now ),
			'expires_at'   => ZAGR_Clock::utc( $now + self::TTL ),
			'tries'        => 0,
			'ip_tag'       => $tag,
		) );
		return $ok ? array( 'ok' => true, 'why' => 'sent', 'plain' => $code ) : array( 'ok' => false, 'why' => 'error', 'plain' => '' );
	}

	/**
	 * Check a typed code: ok | wrong | expired | tries | none | locked | paused. A right code is used up. Only a wrong
	 * answer compared against a live code this session was sent counts toward its source's pause and,
	 * from a place no code was used from, toward the agreement's ceiling; the one that reaches
	 * LOCK_AFTER answers "locked".
	 */
	public static function check( int $agreement_id, int $session_id, string $purpose, string $bound_for, string $typed ): string {
		$source = self::source( $session_id );
		if ( self::is_locked( $agreement_id, $purpose, $source ) ) {
			return 'locked'; // no comparison while this source is paused
		}
		$proven = self::proven( $agreement_id, ZAGR_People::ip_tag() );
		if ( ! $proven && self::ceiling( $agreement_id, $purpose ) ) {
			return 'paused'; // no comparison past the agreement's ceiling, except from a proven place
		}
		$r = self::compare( $agreement_id, $session_id, $purpose, $bound_for, $typed );
		if ( 'wrong' !== $r && 'tries' !== $r ) {
			return 'spent' === $r ? 'tries' : $r;   // nothing compared: nothing counted
		}
		$locked = self::count_wrong( $agreement_id, $purpose, $source );
		if ( ! $proven && self::ceiling( $agreement_id, $purpose ) ) {
			self::alert( $agreement_id, $purpose, 'ceiling' );
		}
		return $locked ? 'locked' : $r;
	}

	/** ok | wrong | tries (the 5th wrong, compared) | spent (no try left: not compared) | expired | none. */
	private static function compare( int $agreement_id, int $session_id, string $purpose, string $bound_for, string $typed ): string {
		global $wpdb;
		$t   = ZAGR_Install::t( 'codes' );
		$now = ZAGR_Clock::now();
		// only the newest code of this session and purpose counts: an earlier one, or one already used, is no code
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE session_id = %d AND agreement_id = %d AND purpose = %s ORDER BY id DESC LIMIT 1", $session_id, $agreement_id, $purpose ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
		if ( ! $row || null !== $row['used_at'] ) {
			return 'none';
		}
		if ( ZAGR_Clock::ts( (string) $row['expires_at'] ) < $now ) {
			return 'expired';
		}
		// one try spent, atomically; the 6th and later are refused before any comparison
		$n = $wpdb->query( $wpdb->prepare( "UPDATE $t SET tries = tries + 1 WHERE id = %d AND tries < %d AND used_at IS NULL", (int) $row['id'], self::TRIES ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
		if ( 1 !== (int) $n ) {
			return 'spent';
		}
		$digits = (string) preg_replace( '/\D/', '', $typed );
		$mac    = 6 === strlen( $digits ) ? self::mac( $agreement_id, $session_id, $purpose, $bound_for, $digits ) : null;
		if ( null === $mac || ! hash_equals( (string) $row['code_mac'], $mac ) ) {
			return (int) $row['tries'] + 1 >= self::TRIES ? 'tries' : 'wrong';
		}
		$u = $wpdb->query( $wpdb->prepare( "UPDATE $t SET used_at = %s WHERE id = %d AND used_at IS NULL", ZAGR_Clock::utc( $now ), (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
		return 1 === (int) $u ? 'ok' : 'none';
	}

	/* ---------------------------------------------------------------- the pause */

	/** Are this agreement's codes for $purpose paused for $source (this request's when null)? */
	public static function is_locked( int $agreement_id, string $purpose, ?string $source = null, int $session_id = 0 ): bool {
		global $wpdb;
		$source = $source ?? self::source( $session_id );
		$until  = $wpdb->get_var( $wpdb->prepare( 'SELECT locked_until FROM ' . ZAGR_Install::t( 'locks' ) . ' WHERE agreement_id = %d AND purpose = %s AND source = %s', $agreement_id, $purpose, $source ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
		return null !== $until && '' !== (string) $until && ZAGR_Clock::ts( (string) $until ) > ZAGR_Clock::now();
	}

	/** One more wrong answer from $source. True when this one paused it (only one request ever does). */
	private static function count_wrong( int $agreement_id, string $purpose, string $source ): bool {
		global $wpdb;
		$t   = ZAGR_Install::t( 'locks' );
		$now = ZAGR_Clock::utc();
		$sup = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
		$wpdb->insert( $t, array( 'agreement_id' => $agreement_id, 'purpose' => $purpose, 'source' => $source, 'wrong' => 0, 'updated_at' => $now ) ); // once (the unique key)
		if ( null !== $sup ) {
			$wpdb->suppress_errors( $sup );
		}
		$w = $wpdb->prepare( 'agreement_id = %d AND purpose = %s AND source = %s', $agreement_id, $purpose, $source );
		// a pause that has run its 24 hours starts the count again
		$wpdb->query( $wpdb->prepare( "UPDATE $t SET wrong = 0, locked_until = NULL, updated_at = %s WHERE $w AND locked_until IS NOT NULL AND locked_until <= %s", $now, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix and a fixed name; $w was prepared above; every other value is a placeholder
		$wpdb->query( $wpdb->prepare( "UPDATE $t SET wrong = wrong + 1, updated_at = %s WHERE $w AND locked_until IS NULL", $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix and a fixed name; $w was prepared above; every other value is a placeholder
		$until = ZAGR_Clock::utc( ZAGR_Clock::now() + self::LOCK_FOR );
		$n     = (int) $wpdb->query( $wpdb->prepare( "UPDATE $t SET locked_until = %s, updated_at = %s WHERE $w AND locked_until IS NULL AND wrong >= %d", $until, $now, self::LOCK_AFTER ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix and a fixed name; $w was prepared above; every other value is a placeholder
		if ( 1 !== $n ) {
			return false;
		}
		ZAGR_Events::append( $agreement_id, 'codes_locked', array( 'purpose' => $purpose, 'wrong' => self::LOCK_AFTER, 'until' => $until, 'one_source' => true ), 'system' );
		$k = 'zagr_locked_mail_' . $agreement_id;
		if ( ! get_transient( $k ) ) {   // staff hear about pauses once a day per agreement
			set_transient( $k, 1, DAY_IN_SECONDS );
			$row = ZAGR_Store::by_id( $agreement_id );
			if ( $row && ! ZAGR_Mailer::codes_staff( 'locked', ZAGR_Flow::facts( $row ), ZAGR_Flow::number( $row ), (int) $row['client_id'], $purpose, ZAGR_Clock::local_long( $until ), ! empty( $row['is_test'] ) ) ) {
				ZAGR_Events::append( $agreement_id, 'email_failed', array( 'what' => 'codes_locked_staff' ), 'system' );
			}
		}
		return true;
	}

	/**
	 * The agreement-wide cap ($kind 'limited') or the wrong-answer ceiling was reached: the
	 * cancellations address is told once a day for each, and the customer (with how to cancel by email or letter) once
	 * a day in all.
	 */
	private static function alert( int $agreement_id, string $purpose, string $kind ): void {
		$k = 'zagr_' . $kind . '_' . $agreement_id;
		if ( get_transient( $k ) ) {
			return;
		}
		set_transient( $k, 1, DAY_IN_SECONDS );
		ZAGR_Events::append( $agreement_id, 'ceiling' === $kind ? 'codes_ceiling' : 'codes_limited', 'ceiling' === $kind
			? array( 'purpose' => $purpose, 'wrong_per_day' => self::WRONG_PER_DAY )
			: array( 'purpose' => $purpose, 'per_hour' => self::PER_HOUR, 'per_day' => self::PER_DAY ), 'system' );
		$row = ZAGR_Store::by_id( $agreement_id );
		if ( ! $row ) {
			return;
		}
		$facts = ZAGR_Flow::facts( $row );
		if ( ! ZAGR_Mailer::codes_staff( $kind, $facts, ZAGR_Flow::number( $row ), (int) $row['client_id'], $purpose, '', ! empty( $row['is_test'] ) ) ) {
			ZAGR_Events::append( $agreement_id, 'email_failed', array( 'what' => 'codes_' . $kind . '_staff' ), 'system' );
		}
		$ck = 'zagr_paused_mail_' . $agreement_id;
		if ( get_transient( $ck ) ) {
			return;
		}
		set_transient( $ck, 1, DAY_IN_SECONDS );
		$to = ZAGR_Store::email( $row );
		$by = substr( (string) $row['cancel_by'], 0, 10 );
		if ( null === $to || ! ZAGR_Mailer::codes_paused( $to, $facts, ! empty( $row['signed_at'] ) && 'cancelled' !== $row['state'], '' !== $by ? ZAGR_Calendar::long_date( $by ) : '' ) ) {
			ZAGR_Events::append( $agreement_id, 'email_failed', array( 'what' => 'codes_paused_buyer' ), 'system' );
		}
	}

	/**
	 * An administrator turns an agreement's codes back on: every source's pause ends, and the agreement-wide counts start
	 * again from now. { ok, message }
	 */
	public static function reset_locks( int $agreement_id, int $uid ): array {
		global $wpdb;
		if ( ! ZAGR_People::is_admin( $uid ) ) {
			return array( 'ok' => false, 'message' => 'Only an administrator can turn codes back on.' );
		}
		if ( ! ZAGR_Store::by_id( $agreement_id ) ) {
			return array( 'ok' => false, 'message' => 'No such agreement.' );
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . ZAGR_Install::t( 'locks' ) . ' SET wrong = 0, locked_until = NULL, updated_at = %s WHERE agreement_id = %d', ZAGR_Clock::utc(), $agreement_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
		update_option( self::RESET_OPT . $agreement_id, ZAGR_Clock::now(), false );
		foreach ( array( 'zagr_limited_', 'zagr_ceiling_', 'zagr_paused_mail_' ) as $k ) {
			delete_transient( $k . $agreement_id );
		}
		ZAGR_Events::append( $agreement_id, 'codes_reset', array(), 'staff:' . $uid );
		return array( 'ok' => true, 'message' => 'Codes are on again for this agreement.' );
	}

	/** Is any source paused, or the agreement's cap or wrong-answer ceiling reached, on this agreement? (the settings screen) */
	public static function any_locked( int $agreement_id ): bool {
		global $wpdb;
		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ZAGR_Install::t( 'locks' ) . ' WHERE agreement_id = %d AND locked_until > %s', $agreement_id, ZAGR_Clock::utc() ) ) > 0 // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (ZAGR_Install::tables()); every value is a placeholder
			|| false !== get_transient( 'zagr_limited_' . $agreement_id ) ) {
			return true;
		}
		foreach ( self::PURPOSES as $p ) {
			if ( self::ceiling( $agreement_id, $p ) ) {
				return true;
			}
		}
		return false;
	}
}
