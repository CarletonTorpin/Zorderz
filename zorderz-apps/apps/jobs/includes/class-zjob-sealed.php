<?php
/**
 * ZJOB_Sealed — the jobs app on the platform's one vault for gate, lockbox, alarm, door and keypad codes (ZDZ_Sealed).
 *
 * An access code typed into a job is never kept or sent in plain text. Who may see one: the admin tier, and whoever is
 * on that work, on its day.
 *
 *   On the way in   a job's access notes and notes (ZJOB_Jobs::create) and a job note: every code is sealed into the
 *                   vault under the job (['job', 12]) or the project (['job', 'p<project id>']) and the words keep
 *                   "[gate code sealed]".
 *   On the way out  notices, the Scheduler's pre-filled bundle, the notes another app may harvest, and every jobs verb
 *                   answer (the chat reads the apps through those) are cut: the words keep only the label.
 *   Show code       for_scope() gives a screen what it shows beside "[gate code sealed]": id, kind, may_open. The button
 *                   posts to the platform's /sealed/<id>/open (ZDZ_Sealed::open), never here.
 *   Who may open    zdz_sealed_may_open: a job's code for the person it is assigned to, or anyone on its Scheduler
 *                   appointment, on its scheduled day; a project's code for whoever has one of its live jobs or its
 *                   appointment that day. "The day" is the business's own day (its time zone from the Business Profile,
 *                   else the site's). Admins, the shared device and Demo Mode are decided by the vault first.
 *   Estimates       zdz_sealed_estimate_visit: has this person a job or an appointment today on the project that holds
 *                   this estimate? The estimate app decides what that means.
 *
 * Without the vault every call here leaves the words as they were, and nothing is stored.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_Sealed {

	/** Job statuses whose code may still be needed on site. */
	const LIVE = array( 'open', 'in_progress' );

	/** Words worth scanning: every rule of the vault's reader needs one of these (or a number, see quick()). */
	const WORDS = '/code|gate|lock|key|combo|buzz|call\s*-?\s*box|alarm|door|garage|pool|community|entry|access|pin/i';

	/** Zero-width marks and the like, read away before the quick word check. */
	const INVISIBLE = '/[\x{00AD}\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u';

	/** A digit, a full-width digit or a spoken digit: without one there is no code to find. */
	const DIGITS = '/\d|\xEF\xBC[\x90-\x99]|\b(?:zero|oh|o|one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen|eighteen|nineteen|twenty|thirty|forty|fifty|sixty|seventy|eighty|ninety)\b/i';

	private static $memo = array();

	public static function boot(): void {
		add_filter( 'zdz_sealed_may_open', array( __CLASS__, 'may_open' ), 10, 3 );
		add_filter( 'zdz_sealed_estimate_visit', array( __CLASS__, 'estimate_visit' ), 10, 3 );
		add_filter( 'zdz_register_capabilities', array( __CLASS__, 'guard_verbs' ), 1000 );
		add_filter( 'zjob_notes_for_harvest', array( __CLASS__, 'deep' ), 1000 );
		add_filter( 'zdz_compose_context', array( __CLASS__, 'deep' ), 1000 );
	}

	/* ------------------------------------------------------------------ the vault, or nothing */

	public static function on(): bool {
		return class_exists( 'ZDZ_Sealed' ) && method_exists( 'ZDZ_Sealed', 'cut_ids' ) && ZDZ_Sealed::ready();
	}

	private static function worth( $text ): bool {
		return is_string( $text ) && '' !== $text && self::quick( $text );
	}

	/** The word check, with zero-width marks read through. */
	private static function words( string $text ): bool {
		$plain = preg_replace( self::INVISIBLE, '', $text );
		return (bool) preg_match( self::WORDS, null === $plain ? $text : $plain );
	}

	/**
	 * Could $text hold a code? The word list, OR the vault's own quick check for text with a number in it. Either is
	 * enough; the vault's find() still decides what is a code.
	 */
	private static function quick( string $text ): bool {
		if ( self::words( $text ) ) {
			return true;
		}
		$plain = preg_replace( self::INVISIBLE, '', $text );
		if ( ! preg_match( self::DIGITS, null === $plain ? $text : $plain ) ) {
			return false;
		}
		return class_exists( 'ZDZ_Sealed' ) && method_exists( 'ZDZ_Sealed', 'might_have_code' ) && (bool) ZDZ_Sealed::might_have_code( $text );
	}

	/**
	 * The codes the vault's reader finds in $text, less door sizes: "screen door #3080" is a door's size on a priced
	 * line, not a code. A "door" match counts only when its words say code, pin, combo or key. [[start, length, kind,
	 * words]]
	 */
	public static function spans( $text ): array {
		if ( ! self::worth( $text ) || ! self::on() ) {
			return array();
		}
		$out = array();
		foreach ( ZDZ_Sealed::find( $text ) as $h ) {
			$w = substr( $text, (int) $h[0], (int) $h[1] );
			if ( 'door' === $h[2] && ! preg_match( '/code|pin|combo|key/i', $w ) ) {
				continue;
			}
			$out[] = array( (int) $h[0], (int) $h[1], (string) $h[2], $w );
		}
		return $out;
	}

	private static function relabel( string $text, array $spans ): string {
		foreach ( array_reverse( $spans ) as $sp ) {
			$text = substr( $text, 0, $sp[0] ) . ZDZ_Sealed::label( $sp[2] ) . substr( $text, $sp[0] + $sp[1] );
		}
		return $text;
	}

	/** One code into the vault under $scope: a bare code by itself, else the words around it. 1 kept, 0 not. */
	private static function keep( array $sp, array $scope ): int {
		if ( (int) ZDZ_Sealed::seal( $sp[2], $sp[3], $scope ) > 0 ) {
			return 1;
		}
		$r = ZDZ_Sealed::cut_ids( $sp[3], $scope );
		return (int) $r['kept'] > 0 ? 1 : 0;
	}

	/** Words with every code cut out (nothing stored). For anything that leaves: a calendar body, a notice, a verb. */
	public static function cut( $text ) {
		$sp = self::spans( $text );
		return $sp ? self::relabel( (string) $text, $sp ) : $text;
	}

	/**
	 * Words with every code sealed under $scope first: what a record keeps. $seen (one array per record) files a code
	 * that appears in several of its fields once. A code that could not be kept is still cut (never left in the
	 * words), and the loss is logged without the code.
	 */
	public static function seal( $text, array $scope, ?array &$seen = null ) {
		$sp = self::spans( $text );
		if ( ! $sp ) {
			return $text;
		}
		if ( null === $seen ) {
			$seen = array();
		}
		$lost = 0;
		foreach ( $sp as $one ) {
			$k = $one[2] . '|' . strtolower( (string) preg_replace( '/\s+/', ' ', $one[3] ) );
			if ( isset( $seen[ $k ] ) ) {
				continue;
			}
			$seen[ $k ] = true;
			$lost      += 1 - self::keep( $one, $scope );
		}
		if ( $lost ) {
			error_log( 'Zorderz Jobs: ' . $lost . ' code(s) for ' . $scope[0] . ' ' . $scope[1] . ' could not be kept in the vault; they were cut from the words.' );
		}
		return self::relabel( (string) $text, $sp );
	}

	public static function has_code( $text ): bool {
		return (bool) self::spans( $text );
	}

	/** The codes in several words of one record into the vault, each distinct one once: [kept, lost]. */
	public static function keep_all( array $texts, array $scope ): array {
		$seen = array();
		$kept = 0;
		$lost = 0;
		foreach ( $texts as $t ) {
			foreach ( self::spans( $t ) as $one ) {
				$k = $one[2] . '|' . strtolower( (string) preg_replace( '/\s+/', ' ', $one[3] ) );
				if ( isset( $seen[ $k ] ) ) {
					continue;
				}
				$seen[ $k ] = true;
				self::keep( $one, $scope ) ? $kept++ : $lost++;
			}
		}
		return array( $kept, $lost );
	}

	public static function count_codes( $text ): int {
		return count( self::spans( $text ) );
	}

	/** Every string in an answer (any depth) with its codes cut. Keys are left alone. */
	public static function deep( $v ) {
		if ( is_string( $v ) ) {
			return self::cut( $v );
		}
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $x ) {
				if ( is_string( $x ) || is_array( $x ) ) {
					$v[ $k ] = self::deep( $x );
				}
			}
		}
		return $v;
	}

	/* ------------------------------------------------------------------ "Show code" */

	/**
	 * The codes kept for one record, newest first, as a screen shows them beside "[gate code sealed]": id, kind, label,
	 * added, may_open. Never the code. Empty for a signed-out person, the shared device, or without the vault.
	 *
	 * @param string $type 'job' (a job's id, or 'p<project id>') | 'estimate' | 'street' | 'address'
	 */
	public static function for_scope( string $type, string $id, int $uid ): array {
		if ( $uid <= 0 || '' === $id || ! self::on() || ! method_exists( 'ZDZ_Sealed', 't_sealed' ) || self::is_kiosk( $uid ) ) {
			return array();
		}
		global $wpdb;
		$t   = ZDZ_Sealed::t_sealed();
		$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$t}` WHERE scope_type = %s AND scope_id = %s ORDER BY id DESC LIMIT 20", $type, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a fixed name; values are placeholders.
		$out = array();
		foreach ( $ids as $sid ) {
			$i = ZDZ_Sealed::info( (int) $sid, $uid );
			if ( is_array( $i ) ) {
				$out[] = array(
					'id'       => (int) ( $i['id'] ?? $sid ),
					'kind'     => (string) ( $i['kind'] ?? '' ),
					'label'    => (string) ( $i['label'] ?? '' ),
					'added'    => (string) ( $i['added'] ?? '' ),
					'may_open' => ! empty( $i['may_open'] ),
				);
			}
		}
		return $out;
	}

	/** Which of these record ids have codes kept, in one read: [id => true]. For lists. */
	public static function ids_with_codes( string $type, array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'strval', $ids ), 'strlen' ) ) );
		if ( ! $ids || ! self::on() || ! method_exists( 'ZDZ_Sealed', 't_sealed' ) ) {
			return array();
		}
		global $wpdb;
		$t   = ZDZ_Sealed::t_sealed();
		$in  = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		$out = array();
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT scope_id FROM `{$t}` WHERE scope_type = %s AND scope_id IN ({$in})", array_merge( array( $type ), $ids ) ) ) as $sid ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a fixed name; every IN-list item is a placeholder.
			$out[ (string) $sid ] = true;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ "today", in the business's time zone */

	/** The business's time zone: the Business Profile's, else the site's, else UTC. */
	public static function tz(): DateTimeZone {
		$name = '';
		if ( class_exists( 'ZDZ_Business_Profile' ) && method_exists( 'ZDZ_Business_Profile', 'get' ) ) {
			$name = (string) ZDZ_Business_Profile::get( 'locale.timezone', '' );
		}
		if ( '' === $name && function_exists( 'wp_timezone_string' ) ) {
			$name = (string) wp_timezone_string();
		}
		try {
			return new DateTimeZone( '' !== $name ? $name : 'UTC' );
		} catch ( \Throwable $e ) {
			return new DateTimeZone( 'UTC' );
		}
	}

	/** Today's date in the business's time zone, Y-m-d. */
	public static function today(): string {
		$now = function_exists( 'apply_filters' ) ? (int) apply_filters( 'zdz_sealed_now', time() ) : time();
		return ( new DateTime( '@' . $now ) )->setTimezone( self::tz() )->format( 'Y-m-d' );
	}

	/** A UTC 'Y-m-d H:i:s' as its local date; '' when empty. */
	public static function day_of( $utc ): string {
		$utc = trim( (string) $utc );
		if ( '' === $utc || 0 === strpos( $utc, '0000' ) ) {
			return '';
		}
		try {
			return ( new DateTime( $utc, new DateTimeZone( 'UTC' ) ) )->setTimezone( self::tz() )->format( 'Y-m-d' );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/** Does [start, end] (UTC) touch today? An end before the start counts as the start's day only. */
	public static function spans_today( $start_utc, $end_utc = '' ): bool {
		$a = self::day_of( $start_utc );
		if ( '' === $a ) {
			return false;
		}
		$b = self::day_of( $end_utc );
		if ( '' === $b || $b < $a ) {
			$b = $a;
		}
		// An event that ends exactly at midnight belongs to the day before.
		if ( '' !== trim( (string) $end_utc ) && $b > $a ) {
			try {
				$e = ( new DateTime( (string) $end_utc, new DateTimeZone( 'UTC' ) ) )->setTimezone( self::tz() );
				if ( '00:00' === $e->format( 'H:i' ) ) {
					$b = $e->modify( '-1 day' )->format( 'Y-m-d' );
				}
			} catch ( \Throwable $x ) {
				unset( $x );
			}
		}
		$t = self::today();
		return $a <= $t && $t <= $b;
	}

	/* ------------------------------------------------------------------ who may open */

	private static function is_kiosk( int $uid ): bool {
		if ( class_exists( 'ZDZ_Capabilities' ) && method_exists( 'ZDZ_Capabilities', 'tier_of' ) && 'kiosk' === ZDZ_Capabilities::tier_of( $uid ) ) {
			return true;
		}
		return class_exists( 'ZDZ_Hierarchy' ) && ZDZ_Hierarchy::is_kiosk( $uid );
	}

	/** zdz_sealed_may_open: a job's or a project's code, for the person on it today. Another app's yes is kept. */
	public static function may_open( $ok, $row, $uid ) {
		if ( true === $ok || ( is_string( $ok ) && '' !== trim( $ok ) ) ) {
			return $ok;
		}
		$uid = (int) $uid;
		if ( $uid <= 0 || ! is_array( $row ) || 'job' !== (string) ( $row['scope_type'] ?? '' ) || self::is_kiosk( $uid ) ) {
			return $ok;
		}
		$sid = (string) ( $row['scope_id'] ?? '' );
		if ( preg_match( '/^\d+$/', $sid ) ) {
			$b = self::job_today( (int) $sid, $uid );
			return '' !== $b ? $b : $ok;
		}
		if ( preg_match( '/^p([A-Za-z0-9]{1,64})$/', $sid, $m ) ) {
			$b = self::project_today( (string) $m[1], $uid );
			return '' !== $b ? $b : $ok;
		}
		return $ok;
	}

	/** This person's live Scheduler appointments that touch today: owner or participant. [appt id => row] */
	public static function appointments_today( int $uid ): array {
		$k = 'appts|' . $uid . '|' . self::today();
		if ( isset( self::$memo[ $k ] ) ) {
			return self::$memo[ $k ];
		}
		global $wpdb;
		$out = array();
		if ( $uid > 0 && isset( $wpdb ) && class_exists( 'ZSCH_Appointments' ) ) {
			$t = $wpdb->prefix . 'zsch_appointments';   // the Scheduler's own table
			// A window wide enough for any time zone; spans_today() decides.
			$lo  = gmdate( 'Y-m-d H:i:s', time() - 9 * 86400 );
			$hi  = gmdate( 'Y-m-d H:i:s', time() + 2 * 86400 );
			$ids = array();
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$t}` WHERE owner_user_id = %d AND deleted_at IS NULL AND status <> 'cancelled' AND start_utc < %s AND end_utc >= %s", $uid, $hi, $lo ) ) as $a ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a fixed name; values are placeholders.
				$ids[ (int) $a ] = true;
			}
			if ( class_exists( 'ZSCH_Participants' ) && method_exists( 'ZSCH_Participants', 'appointment_ids_for_participant' ) ) {
				foreach ( array_slice( array_map( 'intval', (array) ZSCH_Participants::appointment_ids_for_participant( $uid ) ), 0, 2000 ) as $a ) {
					$ids[ $a ] = true;
				}
			}
			foreach ( array_keys( $ids ) as $a ) {
				$r = is_callable( array( 'ZSCH_Appointments', 'get_raw' ) ) ? ZSCH_Appointments::get_raw( (int) $a ) : null;
				if ( ! is_array( $r ) || ! empty( $r['deleted_at'] ) || 'cancelled' === strtolower( (string) ( $r['status'] ?? '' ) ) ) {
					continue;
				}
				if ( self::spans_today( $r['start_utc'] ?? '', $r['end_utc'] ?? '' ) ) {
					$out[ (int) $a ] = $r;
				}
			}
		}
		return self::$memo[ $k ] = $out;
	}

	/** "job #12 today" when $uid is on job $job_id today, else ''. */
	public static function job_today( int $job_id, int $uid ): string {
		if ( $job_id <= 0 || $uid <= 0 || ! class_exists( 'ZJOB_Jobs' ) ) {
			return '';
		}
		$mk = 'job|' . $job_id . '|' . $uid . '|' . self::today();
		if ( isset( self::$memo[ $mk ] ) ) {
			return self::$memo[ $mk ];
		}
		return self::$memo[ $mk ] = self::job_today_for_row( ZJOB_Jobs::get( $job_id ), $uid );
	}

	/**
	 * The rule for one job row, without a read (tests and lists): a live job's own person on its scheduled day, or
	 * anyone on its appointment today. ''when neither.
	 */
	public static function job_today_for_row( ?array $row, int $uid, ?array $mine = null ): string {
		if ( ! $row || $uid <= 0 || ! in_array( (string) ( $row['status'] ?? '' ), self::LIVE, true ) ) {
			return '';
		}
		$job_id = (int) ( $row['id'] ?? 0 );
		$appt   = (int) ( $row['scheduled_appt_id'] ?? 0 );
		$mine   = null === $mine ? ( $appt > 0 ? self::appointments_today( $uid ) : array() ) : $mine;
		if ( $appt > 0 && isset( $mine[ $appt ] ) ) {
			return 'job #' . $job_id . ' today';
		}
		if ( (int) ( $row['assigned_user_id'] ?? 0 ) === $uid && self::spans_today( $row['scheduled_start_utc'] ?? '', $row['scheduled_end_utc'] ?? '' ) ) {
			return 'job #' . $job_id . ' today';
		}
		return '';
	}

	/** "project <id> today" when $uid has a live job of the project today, or its appointment; else ''. */
	public static function project_today( string $pid, int $uid ): string {
		if ( '' === $pid || $uid <= 0 || ! class_exists( 'Zjob_Project' ) ) {
			return '';
		}
		$mk = 'project|' . $pid . '|' . $uid . '|' . self::today();
		if ( isset( self::$memo[ $mk ] ) ) {
			return self::$memo[ $mk ];
		}
		$out = '';
		foreach ( array_slice( (array) Zjob_Project::jobs_for( $pid ), 0, 50 ) as $j ) {
			if ( '' !== self::job_today_for_row( is_array( $j ) ? $j : null, $uid ) ) {
				$out = 'project ' . $pid . ' today';
				break;
			}
		}
		if ( '' === $out && class_exists( 'Zjob_Appointment_Link' ) ) {
			$appt = (int) Zjob_Appointment_Link::appointment_for( $pid );
			if ( $appt > 0 && isset( self::appointments_today( $uid )[ $appt ] ) ) {
				$out = 'project ' . $pid . ' today';
			}
		}
		return self::$memo[ $mk ] = $out;
	}

	/**
	 * zdz_sealed_estimate_visit( '', $ref, $uid ): whether $uid is on the work of this estimate today.
	 *   ['kind' => 'job',   'basis' => 'job #12 today']           assigned a live job of it scheduled today
	 *   ['kind' => 'visit', 'basis' => 'visit, project <id> today'] on the project's appointment today
	 * $ref carries 'local_id' (the estimate app's own id). The estimate app decides what each means.
	 */
	public static function estimate_visit( $prev, $ref, $uid ) {
		if ( is_array( $prev ) && ! empty( $prev['kind'] ) ) {
			return $prev;
		}
		$uid   = (int) $uid;
		$local = is_array( $ref ) ? (int) ( $ref['local_id'] ?? 0 ) : 0;
		if ( $uid <= 0 || $local <= 0 || ! class_exists( 'ZJOB_Jobs' ) ) {
			return $prev;
		}
		foreach ( array_slice( (array) ZJOB_Jobs::jobs_for_estimate( $local ), 0, 50 ) as $j ) {
			$b = self::job_today_for_row( is_array( $j ) ? $j : null, $uid );
			if ( '' !== $b ) {
				return array( 'kind' => 'job', 'basis' => $b );
			}
		}
		$pid = class_exists( 'ZJOB_Project_Refs' ) ? ZJOB_Project_Refs::project_for( 'estimate', (string) $local ) : '';
		if ( '' !== $pid ) {
			$b = self::project_today( $pid, $uid );
			if ( '' !== $b ) {
				return array( 'kind' => 'visit', 'basis' => 'visit, ' . $b );
			}
		}
		return $prev;
	}

	/* ------------------------------------------------------------------ verbs: what the chat and the ask bar read */

	/** Every jobs verb answers with codes cut (registered last, so it wraps what the jobs app registered). */
	public static function guard_verbs( $caps ) {
		if ( ! is_array( $caps ) ) {
			return $caps;
		}
		foreach ( $caps as $verb => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			foreach ( array( 'callable', 'callback' ) as $k ) {
				$cb = $row[ $k ] ?? null;
				if ( is_array( $cb ) && isset( $cb[0] ) && is_string( $cb[0] ) && 0 === stripos( $cb[0], 'ZJOB_' ) && is_callable( $cb ) ) {
					$caps[ $verb ][ $k ] = function ( $args = array() ) use ( $cb ) {
						return ZJOB_Sealed::deep( call_user_func( $cb, $args ) );
					};
				}
			}
		}
		return $caps;
	}

	/** Tests: forget what this request worked out. */
	public static function flush_memo(): void {
		self::$memo = array();
	}
}
