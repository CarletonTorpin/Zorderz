<?php
/**
 * ZJOB_Installed_Counts — "how many of the jobs I sold were finished this month?", published as the read verb
 * `jobs.installed_counts` for the salesperson's home tile.
 *
 * WHAT COUNTS
 *   YOURS     the Project's confirmed salesperson is you (Projects' People, role 'seller', not ended; the newest such
 *             row). With no confirmed salesperson: you made the Project, or one of its jobs carries one of your
 *             salesperson codes. A Project whose confirmed salesperson is someone else is theirs.
 *   FINISHED  every job on the Project is finished (at least one done; none open, in progress or waiting to close;
 *             cancelled ones count neither way) and the last one finished in the asked month, in the business time
 *             zone. A done job's time is the later of the worker's "done" and the job's close; with neither (set to
 *             done directly), its last change.
 *   no mark   Projects carry no "installed by hand" mark, so the answer sends no `marked` count (the tile then shows
 *             every count as finished work, never "marked 0").
 *   NEVER     a Project whose jobs are all cleared test jobs. A Project counts once, in the month of its last job.
 *
 * WHO ASKS. Staff tier and up (salespeople, the office, admins): their own sold Projects. A tech, the shared device and
 * nobody signed in are refused. Counts only: never a customer, an address, a Project number or money. A failed read is
 * an error (success false), never 0.
 *
 * @package Zorderz\Jobs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZJOB_Installed_Counts {

	const VERB            = 'jobs.installed_counts';
	const MAX_MONTHS_BACK = 36;
	const LIVE            = array( 'open', 'in_progress', 'pending_close' );

	/** Injected in tests: "now" (unix) and the asker's salesperson codes. */
	public static $now   = null;
	public static $codes = null; // callable( $uid ) => codes

	public static function init(): void {
		add_filter( 'zdz_register_capabilities', array( __CLASS__, 'register' ) );
	}

	public static function register( $caps ) {
		$caps               = is_array( $caps ) ? $caps : array();
		$caps[ self::VERB ] = self::descriptor();
		return $caps;
	}

	public static function descriptor(): array {
		return array(
			'verb'          => self::VERB,
			'provider'      => 'zjob',
			'app'           => defined( 'ZJOB_APP_ID' ) ? ZJOB_APP_ID : 'jobs',
			'tier'          => 'staff',   // salespeople, the office, admins; never a tech or the shared device
			'needs_app'     => false,     // read for the home screen, not through the app
			'callback'      => array( __CLASS__, 'from_capability' ),
			'kiosk'         => false,
			'kiosk_bounded' => false,
			'side_effect'   => false,
			'summary'       => 'How many Projects you sold had all their work finished in a month (business time zone). Counts only.',
		);
	}

	private static function now(): int {
		return null !== self::$now ? (int) self::$now : time();
	}

	/**
	 * The asked month as days and UTC bounds in the business time zone. '' = this month. PURE apart from the zone.
	 *
	 * @return array{month:string,from:string,to:string,from_utc:string,to_utc:string,tz:string}|array{error:string}
	 */
	public static function month_window( string $month, ?DateTimeZone $tz = null, ?int $now = null ): array {
		$tz  = $tz ?? ZJOB_My_Counts::tz();
		$now = $now ?? self::now();
		$cur = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $tz );
		$month = trim( $month );
		if ( '' === $month ) {
			$month = $cur->format( 'Y-m' );
		}
		if ( ! preg_match( '/^(\d{4})-(\d{2})$/', $month, $m ) || (int) $m[2] < 1 || (int) $m[2] > 12 ) {
			return array( 'error' => 'The month must be written YYYY-MM.' );
		}
		$first = new DateTimeImmutable( $month . '-01 00:00:00', $tz );
		$back  = ( (int) $cur->format( 'Y' ) - (int) $m[1] ) * 12 + ( (int) $cur->format( 'n' ) - (int) $m[2] );
		if ( $back < 0 ) {
			return array( 'error' => 'That month has not started yet.' );
		}
		if ( $back > self::MAX_MONTHS_BACK ) {
			return array( 'error' => 'That month is too far back.' );
		}
		$next = $first->modify( '+1 month' );
		$utc  = new DateTimeZone( 'UTC' );
		return array(
			'month'    => $month,
			'from'     => $first->format( 'Y-m-d' ),
			'to'       => $next->modify( '-1 day' )->format( 'Y-m-d' ),
			'from_utc' => $first->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'to_utc'   => $next->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'tz'       => $tz->getName(),
		);
	}

	/** A stored UTC 'Y-m-d H:i:s', or '' for none (NULL, empty, a zero date). PURE. */
	public static function when( $v ): string {
		$v = substr( trim( (string) $v ), 0, 19 );
		return ( preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $v ) && 0 !== strpos( $v, '0000-00-00' ) ) ? $v : '';
	}

	/**
	 * When a Project's work finished: the last done job's time, or '' when it is not finished (a live job, or none
	 * done). Cleared test jobs are left out. PURE.
	 */
	public static function finished_at( array $jobs, array $cleared = array() ): string {
		$live = 0;
		$done = 0;
		$last = '';
		foreach ( $jobs as $j ) {
			if ( in_array( (int) ( $j['id'] ?? 0 ), $cleared, true ) ) {
				continue;
			}
			$st = (string) ( $j['status'] ?? '' );
			if ( in_array( $st, self::LIVE, true ) ) {
				$live++;
			} elseif ( 'done' === $st ) {
				$done++;
				$t = max( self::when( $j['worker_done_at'] ?? '' ), self::when( $j['closed_at'] ?? '' ) );
				if ( '' === $t ) {
					$t = self::when( $j['updated_at'] ?? '' );
				}
				if ( $t > $last ) {
					$last = $t;
				}
			}
		}
		return ( $live > 0 || $done < 1 ) ? '' : $last;
	}

	/**
	 * Is this Project the asker's? PURE.
	 *
	 * @param int      $uid
	 * @param int      $seller  the newest live confirmed salesperson (0: none)
	 * @param int      $creator who made the Project (0: unknown)
	 * @param string[] $job_codes the salesperson codes on its jobs
	 * @param string[] $my_codes  the asker's codes
	 */
	public static function is_yours( int $uid, int $seller, int $creator, array $job_codes, array $my_codes ): bool {
		if ( $seller > 0 ) {
			return $seller === $uid;
		}
		if ( $creator > 0 && $creator === $uid ) {
			return true;
		}
		$mine = array_map( 'strtoupper', $my_codes );
		foreach ( $job_codes as $c ) {
			if ( '' !== (string) $c && in_array( strtoupper( trim( (string) $c ) ), $mine, true ) ) {
				return true;
			}
		}
		return false;
	}

	/** The verb. $args: month (optional 'Y-m', default this month). Identity is the registry's stamp only. */
	public static function from_capability( array $args ): array {
		$uid   = (int) ( $args['user_id'] ?? 0 );
		$kiosk = ! empty( $args['is_kiosk'] ) || 'kiosk' === (string) ( $args['tier'] ?? '' )
			|| ( $uid > 0 && class_exists( 'ZJOB_My_Next' ) && ! ZJOB_My_Next::is_person( $uid ) );
		if ( $uid <= 0 ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'signed_out', 'verb' => self::VERB );
		}
		if ( $kiosk ) {
			return array( 'success' => false, 'denied' => true, 'reason' => 'kiosk', 'verb' => self::VERB );
		}
		$win = self::month_window( is_string( $args['month'] ?? null ) ? (string) $args['month'] : '' );
		if ( isset( $win['error'] ) ) {
			return array( 'success' => false, 'error' => $win['error'], 'verb' => self::VERB );
		}
		$codes = is_callable( self::$codes ) ? (array) call_user_func( self::$codes, $uid )
			: ( class_exists( 'ZJOB_Scope' ) ? ZJOB_Scope::sp_codes_for( $uid ) : array() );
		try {
			$c = self::count_mine( $uid, $codes, $win );
		} catch ( \Throwable $e ) {
			error_log( 'Zorderz Jobs ' . self::VERB . ': the Projects could not be read.' );
			return array( 'success' => false, 'error' => 'Projects could not be read just now.', 'verb' => self::VERB );
		}
		return array(
			'success'     => true,
			'verb'        => self::VERB,
			'month'       => $win['month'],
			'window'      => array( 'from' => $win['from'], 'to' => $win['to'], 'tz' => $win['tz'] ),
			'mine'        => $c,
			'read_at'     => self::now(),
			'definitions' => self::definitions(),
		);
	}

	public static function definitions(): array {
		return array(
			'installed' => 'Projects you sold whose work all finished this month (business time zone): every job on it done and the last one done this month. Yours: you are its confirmed salesperson, or none is confirmed and you made it or it carries your salesperson code. Cleared test jobs are left out.',
			'by_jobs'   => 'Of those, counted by their jobs: every job finished, the last one this month.',
		);
	}

	/**
	 * The counts. Throws on a failed query (never zeros).
	 *
	 * @return array{installed:int,by_jobs:int}
	 */
	public static function count_mine( int $uid, array $codes, array $win ): array {
		global $wpdb;
		$out = array( 'installed' => 0, 'by_jobs' => 0 );
		if ( ! isset( $wpdb ) || ! class_exists( 'Zdz_Flow_DB' ) || ! class_exists( 'ZJOB_DB' ) ) {
			throw new RuntimeException( 'unavailable' );
		}
		$codes = array_values( array_unique( array_filter( array_map( static function ( $c ) {
			$c = strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', (string) $c ) );
			return strlen( $c ) >= 2 && strlen( $c ) <= 4 ? $c : '';
		}, $codes ) ) ) );

		// 1. Candidates: confirmed as seller, made by the asker, or a job carrying one of the asker's codes.
		$cand   = array();
		$sellers = array();
		$people = class_exists( 'ZJOB_Project_People' ) && ZJOB_Project_People::ready() ? ZJOB_Project_People::table() : '';
		if ( '' !== $people ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, project_id, user_id FROM {$people} WHERE role = 'seller' AND ended_at IS NULL" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table names ($wpdb->prefix plus a fixed name); the user id is a placeholder.
				. " AND project_id IN ( SELECT project_id FROM {$people} WHERE role = 'seller' AND ended_at IS NULL AND user_id = %d )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table names ($wpdb->prefix plus a fixed name); the user id is a placeholder.
				$uid
			), ARRAY_A );
			self::ok( $rows );
			foreach ( (array) $rows as $r ) {
				$cand[ (string) $r['project_id'] ] = true;
			}
		}
		$tr   = Zdz_Flow_DB::transitions();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT work_item_id FROM {$tr} WHERE sequence = 1 AND actor_kind = 'user' AND actor_id = %s LIMIT 2000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a fixed name; the actor id is a placeholder.
			(string) $uid
		), ARRAY_A );
		self::ok( $rows );
		foreach ( (array) $rows as $r ) {
			$cand[ (string) $r['work_item_id'] ] = true;
		}
		$refs = Zdz_Flow_DB::refs();
		$jt   = ZJOB_DB::table();
		if ( $codes ) {
			$in   = implode( ',', array_fill( 0, count( $codes ), '%s' ) );
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT DISTINCT r.work_item_id FROM {$refs} r JOIN {$jt} j ON ( ( r.`system` = 'job' AND r.entity = 'handoff' AND j.id = CAST(r.external_id AS UNSIGNED) )" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table names ($wpdb->prefix plus a fixed name); every value is a placeholder.
				. " OR ( r.`system` = 'estimate' AND r.entity = 'document' AND j.estimate_id = CAST(r.external_id AS UNSIGNED) ) )"
				. " WHERE UPPER(TRIM(j.sp_code)) IN ({$in}) LIMIT 2000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- every IN-list item is a placeholder.
				$codes
			), ARRAY_A );
			self::ok( $rows );
			foreach ( (array) $rows as $r ) {
				$cand[ (string) $r['work_item_id'] ] = true;
			}
		}
		$pids = array_keys( $cand );
		if ( ! $pids ) {
			return $out;
		}
		$pin = implode( ',', array_fill( 0, count( $pids ), '%s' ) );

		// 2. Each candidate's newest live seller, its maker, and its jobs.
		if ( '' !== $people ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, project_id, user_id FROM {$people} WHERE role = 'seller' AND ended_at IS NULL AND project_id IN ({$pin}) ORDER BY id ASC", $pids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a fixed name; every IN-list item is a placeholder.
			self::ok( $rows );
			foreach ( (array) $rows as $r ) {
				$sellers[ (string) $r['project_id'] ] = (int) $r['user_id']; // the newest wins
			}
		}
		$creators = array();
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT work_item_id, actor_id FROM {$tr} WHERE sequence = 1 AND actor_kind = 'user' AND work_item_id IN ({$pin})", $pids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix plus a fixed name; every IN-list item is a placeholder.
		self::ok( $rows );
		foreach ( (array) $rows as $r ) {
			$creators[ (string) $r['work_item_id'] ] = (int) $r['actor_id'];
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT r.work_item_id AS pid, j.id, j.status, j.sp_code, j.worker_done_at, j.closed_at, j.updated_at FROM {$refs} r JOIN {$jt} j ON ( ( r.`system` = 'job' AND r.entity = 'handoff' AND j.id = CAST(r.external_id AS UNSIGNED) )" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table names ($wpdb->prefix plus a fixed name); every value is a placeholder.
			. " OR ( r.`system` = 'estimate' AND r.entity = 'document' AND j.estimate_id = CAST(r.external_id AS UNSIGNED) ) ) WHERE r.work_item_id IN ({$pin})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- every IN-list item is a placeholder.
			$pids
		), ARRAY_A );
		self::ok( $rows );
		$jobs = array();
		foreach ( (array) $rows as $r ) {
			$jobs[ (string) $r['pid'] ][ (int) $r['id'] ] = $r; // a job reached by both refs counts once
		}
		$cleared = class_exists( 'ZJOB_Handoff' ) ? array_map( 'intval', (array) ZJOB_Handoff::cleared_ids() ) : array();

		// 3. Yours, and finished in the month.
		foreach ( $pids as $pid ) {
			$list = array_values( $jobs[ $pid ] ?? array() );
			if ( ! self::is_yours( $uid, (int) ( $sellers[ $pid ] ?? 0 ), (int) ( $creators[ $pid ] ?? 0 ), array_column( $list, 'sp_code' ), $codes ) ) {
				continue;
			}
			$at = self::finished_at( $list, $cleared );
			if ( '' !== $at && $at >= $win['from_utc'] && $at < $win['to_utc'] ) {
				$out['by_jobs']++;
			}
		}
		$out['installed'] = $out['by_jobs'];
		return $out;
	}

	/** A query that failed (wpdb's last_error, or no result) is an error, never zeros. */
	private static function ok( $rows ): void {
		global $wpdb;
		if ( null === $rows || ( is_object( $wpdb ) && '' !== (string) ( $wpdb->last_error ?? '' ) ) ) {
			throw new RuntimeException( 'db' );
		}
	}
}
