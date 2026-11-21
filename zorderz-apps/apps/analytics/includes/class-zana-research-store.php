<?php
/**
 * ZANA_Research_Store — where a deep research run is kept: one row a run, in its own table.
 *
 * A run's id is the number people see ("deep research #12"). The row holds whose it is, the chat it belongs to, its
 * question, where it is (phase), what it may still use (time, cost, steps, calls), what it has gathered (plan, notes,
 * the record of every search and answer, sources), its activity log, and its answer.
 *
 *   phase   planning → awaiting (the plan is shown; nothing more happens until its person presses Start)
 *           → researching ⇄ condensing → vetting → writing → done | stopped | failed
 *
 * WHAT THIS CLASS GUARANTEES
 *   - a run that has ended stays ended: phase changes are one UPDATE that only matches a live run, and end() tells
 *     the one caller that ended it (so the answer is written once);
 *   - the wrap-up pass runs once: latch_wrapup() is one UPDATE only one caller wins;
 *   - cost and calls are added by one UPDATE, so two writers never lose a count;
 *   - "Keep going" is used once: claim_continue() is one UPDATE.
 *
 * Money is kept in thousandths of a US dollar. Times are UTC.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Research_Store {

	const DB_VERSION = '1';
	const DB_OPTION  = 'zana_research_db';
	const KEEP_DAYS  = 365;

	const LIVE  = array( 'planning', 'awaiting', 'researching', 'condensing', 'vetting', 'writing' );
	const ENDED = array( 'done', 'stopped', 'failed' );

	/** Columns kept as JSON. */
	const JSON = array( 'plan', 'sources', 'state', 'activity', 'counts', 'trail' );

	/** Tests: callable(): int — the clock. */
	public static $now = null;

	public static function now(): int {
		return is_callable( self::$now ) ? (int) call_user_func( self::$now ) : time();
	}

	public static function at( int $ts ): string {
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	public static function ts( $stored ): int {
		$stored = is_string( $stored ) ? trim( $stored ) : '';
		if ( '' === $stored || '0000-00-00 00:00:00' === $stored ) {
			return 0;
		}
		$t = strtotime( $stored . ' UTC' );
		return false === $t ? 0 : (int) $t;
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zana_research';
	}

	/** The table, made once per schema version. False when it could not be made (then no run starts). */
	public static function maybe_install(): bool {
		if ( self::DB_VERSION === (string) get_option( self::DB_OPTION, '' ) ) {
			return true;
		}
		global $wpdb;
		$t   = self::table();
		$cs  = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
		$sql = "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL DEFAULT 0,
			session_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			root_id bigint(20) unsigned NOT NULL DEFAULT 0,
			phase varchar(16) NOT NULL DEFAULT 'planning',
			approved tinyint(1) NOT NULL DEFAULT 0,
			web tinyint(1) NOT NULL DEFAULT 0,
			continued tinyint(1) NOT NULL DEFAULT 0,
			step int(11) NOT NULL DEFAULT 0,
			max_steps int(11) NOT NULL DEFAULT 0,
			calls int(11) NOT NULL DEFAULT 0,
			cost_milli bigint(20) NOT NULL DEFAULT 0,
			budget_milli bigint(20) NOT NULL DEFAULT 0,
			round_min int(11) NOT NULL DEFAULT 0,
			chain_min int(11) NOT NULL DEFAULT 0,
			chain_milli bigint(20) NOT NULL DEFAULT 0,
			chain_steps int(11) NOT NULL DEFAULT 0,
			started_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			deadline_at datetime NULL DEFAULT NULL,
			wrapup_at datetime NULL DEFAULT NULL,
			ended_on varchar(24) NOT NULL DEFAULT '',
			ended_in varchar(16) NOT NULL DEFAULT '',
			question longtext NULL,
			plan longtext NULL,
			notes longtext NULL,
			record longtext NULL,
			sources longtext NULL,
			final longtext NULL,
			state longtext NULL,
			activity longtext NULL,
			counts text NULL,
			trail longtext NULL,
			error text NULL,
			PRIMARY KEY  (id),
			KEY user_started (user_id, started_at),
			KEY started (started_at),
			KEY phase (phase)
		) {$cs};";
		if ( ! function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		if ( function_exists( 'dbDelta' ) ) {
			dbDelta( $sql );
		} else {
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- a fixed CREATE TABLE statement; its only variable part is this class's own table name
		}
		if ( '' !== (string) $wpdb->last_error ) {
			return false;
		}
		update_option( self::DB_OPTION, self::DB_VERSION, false );
		return true;
	}

	public static function installed(): bool {
		return self::DB_VERSION === (string) get_option( self::DB_OPTION, '' );
	}

	/* ================================================================== a run */

	/** A new run. Returns its id, or 0. */
	public static function create( array $row ): int {
		if ( ! self::maybe_install() ) {
			return 0;
		}
		global $wpdb;
		$now  = self::at( self::now() );
		$base = array(
			'job_id'       => 0,
			'session_id'   => 0,
			'user_id'      => 0,
			'parent_id'    => 0,
			'root_id'      => 0,
			'phase'        => 'planning',
			'approved'     => 0,
			'web'          => 0,
			'continued'    => 0,
			'step'         => 0,
			'max_steps'    => 0,
			'calls'        => 0,
			'cost_milli'   => 0,
			'budget_milli' => 0,
			'round_min'    => 0,
			'chain_min'    => 0,
			'chain_milli'  => 0,
			'chain_steps'  => 0,
			'started_at'   => $now,
			'updated_at'   => $now,
			'deadline_at'  => null,
			'wrapup_at'    => null,
			'ended_on'     => '',
			'ended_in'     => '',
			'question'     => '',
			'plan'         => array(),
			'notes'        => '',
			'record'       => '',
			'sources'      => array(),
			'final'        => '',
			'state'        => array(),
			'activity'     => array(),
			'counts'       => array(),
			'trail'        => array(),
			'error'        => '',
		);
		$row  = array_intersect_key( $row, $base ) + $base;
		foreach ( self::JSON as $k ) {
			$row[ $k ] = (string) wp_json_encode( is_array( $row[ $k ] ) ? $row[ $k ] : array() );
		}
		return $wpdb->insert( self::table(), $row ) ? (int) $wpdb->insert_id : 0;
	}

	/** A run's row with its JSON columns read back as arrays; null when there is none. */
	public static function get( int $id ): ?array {
		if ( $id <= 0 || ! self::installed() ) {
			return null;
		}
		global $wpdb;
		$t   = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is this class's own table name with the site's prefix
		if ( ! is_array( $row ) ) {
			return null;
		}
		foreach ( self::JSON as $k ) {
			$v         = json_decode( (string) ( $row[ $k ] ?? '' ), true );
			$row[ $k ] = is_array( $v ) ? $v : array();
		}
		foreach ( array( 'id', 'job_id', 'session_id', 'user_id', 'parent_id', 'root_id', 'approved', 'web', 'continued', 'step', 'max_steps', 'calls', 'cost_milli', 'budget_milli', 'round_min', 'chain_min', 'chain_milli', 'chain_steps' ) as $k ) {
			$row[ $k ] = (int) ( $row[ $k ] ?? 0 );
		}
		foreach ( array( 'question', 'notes', 'record', 'final', 'error', 'phase', 'ended_on', 'ended_in' ) as $k ) {
			$row[ $k ] = (string) ( $row[ $k ] ?? '' );
		}
		return $row;
	}

	public static function is_live( $run ): bool {
		return is_array( $run ) && in_array( (string) ( $run['phase'] ?? '' ), self::LIVE, true );
	}

	/**
	 * Change a run's fields (never its phase: phase() and end() do that). JSON columns are given as arrays. Always
	 * moves "last heard".
	 */
	public static function set( int $id, array $fields ): void {
		unset( $fields['phase'], $fields['id'], $fields['calls'], $fields['cost_milli'] );
		foreach ( self::JSON as $k ) {
			if ( array_key_exists( $k, $fields ) ) {
				$fields[ $k ] = (string) wp_json_encode( is_array( $fields[ $k ] ) ? $fields[ $k ] : array() );
			}
		}
		$fields['updated_at'] = self::at( self::now() );
		global $wpdb;
		$wpdb->update( self::table(), $fields, array( 'id' => $id ) );
	}

	/** Move a LIVE run to another live phase. False when the run has ended (it stays as it is). */
	public static function phase( int $id, string $to ): bool {
		if ( ! in_array( $to, self::LIVE, true ) ) {
			return false;
		}
		global $wpdb;
		$t = self::table();
		$n = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET phase = %s, updated_at = %s WHERE id = %d AND phase NOT IN ('done','stopped','failed')", $to, self::at( self::now() ), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is this class's own table name with the site's prefix
		return false !== $n && (int) $n > 0;
	}

	/** Move a run from one named phase to another: true for the one caller whose UPDATE did it (Start, pressed twice). */
	public static function phase_from( int $id, string $from, string $to ): bool {
		global $wpdb;
		$t = self::table();
		$n = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET phase = %s, updated_at = %s WHERE id = %d AND phase = %s", $to, self::at( self::now() ), $id, $from ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is this class's own table name with the site's prefix
		return 1 === (int) $n;
	}

	/** End a live run as done, stopped or failed. True for the one caller that ended it. */
	public static function end( int $id, string $as ): bool {
		$as = in_array( $as, self::ENDED, true ) ? $as : 'failed';
		global $wpdb;
		$t = self::table();
		$n = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET phase = %s, updated_at = %s WHERE id = %d AND phase NOT IN ('done','stopped','failed')", $as, self::at( self::now() ), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is this class's own table name with the site's prefix
		return false !== $n && (int) $n > 0;
	}

	/** The one wrap-up pass: true for the caller that takes it, false when it was already taken. */
	public static function latch_wrapup( int $id ): bool {
		global $wpdb;
		$t = self::table();
		$n = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET wrapup_at = %s WHERE id = %d AND wrapup_at IS NULL", self::at( self::now() ), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is this class's own table name with the site's prefix
		return 1 === (int) $n;
	}

	/** "Keep going" on a run, once: true for the press that takes it. release_continue() gives it back when no round started. */
	public static function claim_continue( int $id ): bool {
		global $wpdb;
		$t = self::table();
		$n = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET continued = 1 WHERE id = %d AND continued = 0", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is this class's own table name with the site's prefix
		return 1 === (int) $n;
	}

	public static function release_continue( int $id ): void {
		global $wpdb;
		$wpdb->update( self::table(), array( 'continued' => 0 ), array( 'id' => $id ) );
	}

	/** Add what a call cost (thousandths of a dollar) and how many calls it was, in one statement. */
	public static function charge( int $id, int $milli, int $calls = 1 ): void {
		global $wpdb;
		$t = self::table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET cost_milli = cost_milli + %d, calls = calls + %d, updated_at = %s WHERE id = %d", max( 0, $milli ), max( 0, $calls ), self::at( self::now() ), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is this class's own table name with the site's prefix
	}

	/* ================================================================== counts across runs */

	/** The start of the current UTC day, as stored. */
	public static function day_start(): string {
		return gmdate( 'Y-m-d 00:00:00', self::now() );
	}

	/** When the day's counts start again (unix time). */
	public static function day_resets_at(): int {
		return (int) ( floor( self::now() / 86400 ) + 1 ) * 86400;
	}

	/** Ai calls every run made today, the whole site. */
	public static function day_calls(): int {
		if ( ! self::installed() ) {
			return 0;
		}
		global $wpdb;
		$t = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(calls),0) FROM {$t} WHERE started_at >= %s", self::day_start() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is this class's own table name with the site's prefix
	}

	/** What one person's research used today: [ runs they started, thousandths of a dollar ]. */
	public static function person_day( int $uid ): array {
		if ( $uid <= 0 || ! self::installed() ) {
			return array( 0, 0 );
		}
		global $wpdb;
		$t    = self::table();
		$runs = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE user_id = %d AND approved = 1 AND started_at >= %s", $uid, self::day_start() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is this class's own table name with the site's prefix
		$cost = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(cost_milli),0) FROM {$t} WHERE user_id = %d AND started_at >= %s", $uid, self::day_start() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is this class's own table name with the site's prefix
		return array( $runs, $cost );
	}

	/** The id of a run this person has in progress, or 0. */
	public static function live_for( int $uid ): int {
		if ( $uid <= 0 || ! self::installed() ) {
			return 0;
		}
		global $wpdb;
		$t = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE user_id = %d AND phase IN ('planning','awaiting','researching','condensing','vetting','writing') ORDER BY id DESC LIMIT 1", $uid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is this class's own table name with the site's prefix
	}

	/** Runs that ended more than KEEP_DAYS ago are removed (their answers stay in the chat; the trail under them goes). */
	public static function prune(): void {
		if ( ! self::installed() ) {
			return;
		}
		global $wpdb;
		$t = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE phase IN ('done','stopped','failed') AND updated_at < %s", self::at( self::now() - self::KEEP_DAYS * 86400 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $t is this class's own table name with the site's prefix
	}
}
