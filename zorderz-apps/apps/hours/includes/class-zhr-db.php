<?php
/**
 * ZHR_DB -- Hours' three tables.
 *
 *   wp_zhr_entries   one row per stretch of work: who, the day it counts for, start and end to the minute (site time),
 *                     where the source of it was (timer, text, photo, exact times, door hanging, an admin), where the
 *                     phone was at clock-in and clock-out when the person allowed it, the words or photo reading it
 *                     came from, and every change (history_json: who, when, what it was before). A deleted entry is
 *                     kept (deleted_at, deleted_by) so the accountant can see it went; nothing reads it as hours.
 *                     end_at NULL = the clock is running. At most one running entry per person (clock_in takes a lock).
 *                     end_review 'asked' = an open shift whose finish the person asked the office to set ("I
 *                     forgot"); it is no longer the running clock, and it counts nothing until a person sets it.
 *
 *   wp_zhr_meals     a person's answer about a day's meal period, when the business has meal rules (voluntary |
 *                     not_provided), one row per day and meal, with every change kept.
 *
 *   wp_zhr_activity  what the app itself saw a person do, copied once from where it happened (the platform's usage
 *                     log, photos, Jobs steps, door-hanger walks, Hours' own clock and pings), to the minute, with a place
 *                     when the source had one. `ref` names the source row, so a copy is made once. Never a customer's name.
 *
 * @package Zorderz\Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZHR_DB {

	const SOURCES = array( 'timer', 'text', 'photo', 'manual', 'doorhangers', 'admin' );

	public static function t_entries() {
		global $wpdb;
		return $wpdb->prefix . 'zhr_entries';
	}

	public static function t_meals() {
		global $wpdb;
		return $wpdb->prefix . 'zhr_meals';
	}

	public static function t_activity() {
		global $wpdb;
		return $wpdb->prefix . 'zhr_activity';
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$e       = self::t_entries();
		$a       = self::t_activity();
		dbDelta( "CREATE TABLE $e (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			work_date date NOT NULL,
			start_at datetime NOT NULL,
			end_at datetime NULL,
			minutes int NOT NULL DEFAULT 0,
			source varchar(16) NOT NULL DEFAULT 'manual',
			source_ref varchar(64) NOT NULL DEFAULT '',
			note varchar(1500) NOT NULL DEFAULT '',
			start_lat decimal(10,7) NULL,
			start_lng decimal(10,7) NULL,
			start_acc int NULL,
			end_lat decimal(10,7) NULL,
			end_lng decimal(10,7) NULL,
			end_acc int NULL,
			raw_input text NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_at datetime NULL,
			deleted_at datetime NULL,
			deleted_by bigint(20) unsigned NOT NULL DEFAULT 0,
			end_review varchar(16) NOT NULL DEFAULT '',
			history_json longtext NULL,
			PRIMARY KEY  (id),
			KEY user_date (user_id,work_date),
			KEY user_end (user_id,end_at),
			KEY work_date (work_date),
			KEY source_ref (source_ref)
		) $charset;" );
		// a person's answer about a day's meal period: voluntary | not_provided; one row per day and meal
		$m = self::t_meals();
		dbDelta( "CREATE TABLE $m (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			work_date date NOT NULL,
			meal_no tinyint unsigned NOT NULL DEFAULT 1,
			answer varchar(16) NOT NULL DEFAULT '',
			about varchar(40) NOT NULL DEFAULT '',
			answered_by bigint(20) unsigned NOT NULL DEFAULT 0,
			answered_at datetime NOT NULL,
			history_json longtext NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_day_meal (user_id,work_date,meal_no),
			KEY work_date (work_date)
		) $charset;" );
		dbDelta( "CREATE TABLE $a (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			at datetime NOT NULL,
			kind varchar(16) NOT NULL DEFAULT '',
			app_id varchar(64) NOT NULL DEFAULT '',
			lat decimal(10,7) NULL,
			lng decimal(10,7) NULL,
			acc int NULL,
			until datetime NULL,
			detail varchar(120) NOT NULL DEFAULT '',
			ref varchar(80) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ref (ref),
			KEY user_at (user_id,at)
		) $charset;" );
	}

	/* ------------------------------------------------------------------ entries */

	private static function shape( $r ) {
		if ( ! is_array( $r ) ) {
			return null;
		}
		foreach ( array( 'id', 'user_id', 'minutes', 'created_by', 'updated_by', 'deleted_by' ) as $k ) {
			$r[ $k ] = (int) ( $r[ $k ] ?? 0 );
		}
		foreach ( array( 'start_lat', 'start_lng', 'end_lat', 'end_lng' ) as $k ) {
			$r[ $k ] = ( isset( $r[ $k ] ) && null !== $r[ $k ] && '' !== $r[ $k ] ) ? (float) $r[ $k ] : null;
		}
		foreach ( array( 'start_acc', 'end_acc' ) as $k ) {
			$r[ $k ] = ( isset( $r[ $k ] ) && null !== $r[ $k ] && '' !== $r[ $k ] ) ? (int) $r[ $k ] : null;
		}
		if ( empty( $r['end_at'] ) ) {
			$r['end_at'] = null;
		}
		$r['end_review'] = (string) ( $r['end_review'] ?? '' );
		return $r;
	}

	public static function get( $id ) {
		global $wpdb;
		return self::shape( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t_entries() . ' WHERE id = %d', (int) $id ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (self::t_entries())
	}

	/**
	 * A person's live entries whose work day is in [$from, $to], plus any entry still running (whatever its day),
	 * oldest first. $uid 0 = everyone.
	 */
	public static function entries( $uid, $from, $to, $with_deleted = false ) {
		global $wpdb;
		$where  = array( '( ( work_date BETWEEN %s AND %s ) OR ( end_at IS NULL AND work_date <= %s ) )' );
		$params = array( $from, $to, $to );
		if ( $uid > 0 ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $uid;
		}
		if ( ! $with_deleted ) {
			$where[] = 'deleted_at IS NULL';
		}
		$sql  = 'SELECT * FROM ' . self::t_entries() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY user_id, start_at ASC LIMIT 20000';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_values( array_filter( array_map( array( __CLASS__, 'shape' ), (array) $rows ) ) );
	}

	/**
	 * This person's live entries that touch [$from_dt, $to_dt] (for the overlap check), except $except_id. A shift left
	 * with the office has no known end: only its clock-in minute is taken, so the person can work on meanwhile.
	 */
	public static function touching( $uid, $from_dt, $to_dt, $except_id = 0 ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, start_at, end_at, end_review FROM ' . self::t_entries() . " WHERE user_id = %d AND deleted_at IS NULL AND id <> %d AND start_at < %s AND ( ( end_at IS NULL AND end_review = '' ) OR end_at > %s OR ( end_at IS NULL AND end_review <> '' AND start_at >= %s ) ) ORDER BY start_at LIMIT 50", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (self::t_entries())
			(int) $uid, (int) $except_id, $to_dt, $from_dt, $from_dt
		), ARRAY_A );
		return (array) $rows;
	}

	/** The running clock: an open entry not left with the office. Live, or past 16 hours (a forgotten clock-out). */
	public static function running( $uid ) {
		global $wpdb;
		return self::shape( $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::t_entries() . " WHERE user_id = %d AND end_at IS NULL AND deleted_at IS NULL AND end_review = '' ORDER BY start_at DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (self::t_entries())
			(int) $uid
		), ARRAY_A ) );
	}

	/** The running entry a door-hanger walk started (source_ref 'zdh:<drop>'), whoever's it is. */
	public static function running_by_ref( $ref ) {
		global $wpdb;
		return self::shape( $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::t_entries() . " WHERE source_ref = %s AND end_at IS NULL AND deleted_at IS NULL AND end_review = '' ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (self::t_entries())
			(string) $ref
		), ARRAY_A ) );
	}

	/** Everyone whose clock is running now: user_id => entry. */
	public static function running_all() {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . self::t_entries() . " WHERE end_at IS NULL AND deleted_at IS NULL AND end_review = '' ORDER BY start_at ASC", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (self::t_entries())
			$r = self::shape( $r );
			if ( $r ) {
				$out[ $r['user_id'] ] = $r; // the newest one wins, as running() picks it
			}
		}
		return $out;
	}

	/**
	 * Every open entry (end_at NULL), left with the office or not, oldest first; $uid 0 = everyone. The office's
	 * list of shifts waiting for a finish, and a person's own.
	 */
	public static function open_entries( $uid = 0 ) {
		global $wpdb;
		$sql  = 'SELECT * FROM ' . self::t_entries() . ' WHERE end_at IS NULL AND deleted_at IS NULL' . ( $uid > 0 ? ' AND user_id = %d' : '' ) . ' ORDER BY start_at ASC LIMIT 500';
		$rows = $wpdb->get_results( $uid > 0 ? $wpdb->prepare( $sql, (int) $uid ) : $sql, ARRAY_A ); // phpcs:ignore
		return array_values( array_filter( array_map( array( __CLASS__, 'shape' ), (array) $rows ) ) );
	}

	/** Insert a checked entry. Returns the new id or 0. */
	public static function insert( array $row ) {
		global $wpdb;
		$now  = current_time( 'mysql' );
		$data = array(
			'user_id'    => (int) $row['user_id'],
			'work_date'  => (string) $row['work_date'],
			'start_at'   => (string) $row['start_at'],
			'end_at'     => empty( $row['end_at'] ) ? null : (string) $row['end_at'],
			'minutes'    => (int) ( $row['minutes'] ?? 0 ),
			'source'     => in_array( $row['source'] ?? '', self::SOURCES, true ) ? $row['source'] : 'manual',
			'source_ref' => substr( (string) ( $row['source_ref'] ?? '' ), 0, 64 ),
			'note'       => ZHR_Time::cut( (string) ( $row['note'] ?? '' ), 1500 ), // already cleaned and encoded (ZHR_Private::store_note)
			'raw_input'  => isset( $row['raw_input'] ) ? ZHR_Time::cut( (string) $row['raw_input'], 2000 ) : null,
			'created_by' => (int) ( $row['created_by'] ?? get_current_user_id() ),
			'created_at' => $now,
		);
		foreach ( array( 'start_lat', 'start_lng', 'start_acc', 'end_lat', 'end_lng', 'end_acc' ) as $k ) {
			if ( isset( $row[ $k ] ) && null !== $row[ $k ] ) {
				$data[ $k ] = $row[ $k ];
			}
		}
		$ok = $wpdb->insert( self::t_entries(), $data );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Change an entry, keeping what it was. $fields: any of work_date, start_at, end_at, minutes, note, end_lat, end_lng,
	 * end_acc, source_ref, end_review. $why: a short word for the history (edit, clock_out, route_finished,
	 * set_end and office_set_end -- a finish a person set later -- ask_office ...).
	 * The UPDATE carries the values it read, so two changes at once cannot both win: 0 rows = someone else changed it.
	 */
	public static function update( $id, array $fields, $actor, $why ) {
		global $wpdb;
		$cur = self::get( $id );
		if ( ! $cur || $cur['deleted_at'] ) {
			return false;
		}
		$allowed = array( 'work_date', 'start_at', 'end_at', 'minutes', 'note', 'end_lat', 'end_lng', 'end_acc', 'source_ref', 'end_review' );
		$set     = array();
		foreach ( $fields as $k => $v ) {
			if ( in_array( $k, $allowed, true ) ) {
				$set[ $k ] = 'note' === $k ? ZHR_Time::cut( (string) $v, 1500 ) : $v; // notes arrive encoded
			}
		}
		if ( ! $set ) {
			return true;
		}
		$hist   = json_decode( (string) $cur['history_json'], true );
		$hist   = is_array( $hist ) ? $hist : array();
		$before = array();
		foreach ( array( 'work_date', 'start_at', 'end_at', 'minutes', 'note', 'end_review' ) as $k ) {
			if ( array_key_exists( $k, $set ) && (string) $set[ $k ] !== (string) $cur[ $k ] ) {
				$before[ $k ] = $cur[ $k ];
			}
		}
		// a clock-out is the entry finishing, not a change of mind: it is recorded, but not as an edit
		if ( $before && ! in_array( $why, array( 'clock_out', 'route_finished', 'meal_break' ), true ) ) {
			$hist[] = array( 'at' => current_time( 'mysql' ), 'by' => (int) $actor, 'why' => substr( (string) $why, 0, 24 ), 'before' => $before );
		}
		$set['history_json'] = $hist ? wp_json_encode( $hist ) : null;
		$set['updated_by']   = (int) $actor;
		$set['updated_at']   = current_time( 'mysql' );
		// the row as it was read: start, end and the last change -- a second change made meanwhile makes this one miss
		$where_sql = 'id = %d AND deleted_at IS NULL AND start_at = %s AND ' . ( null === $cur['end_at'] ? 'end_at IS NULL' : 'end_at = %s' )
			. ' AND ' . ( empty( $cur['updated_at'] ) ? 'updated_at IS NULL' : 'updated_at = %s' );
		$cols      = array();
		$vals      = array();
		foreach ( $set as $k => $v ) {
			if ( null === $v ) {
				$cols[] = "`$k` = NULL";
			} else {
				$cols[] = "`$k` = %s";
				$vals[] = (string) $v;
			}
		}
		$vals[] = (int) $id;
		$vals[] = $cur['start_at'];
		if ( null !== $cur['end_at'] ) {
			$vals[] = $cur['end_at'];
		}
		if ( ! empty( $cur['updated_at'] ) ) {
			$vals[] = $cur['updated_at'];
		}
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t_entries() . ' SET ' . implode( ', ', $cols ) . ' WHERE ' . $where_sql, $vals ) ); // phpcs:ignore
		return 1 === (int) $n;
	}

	public static function soft_delete( $id, $actor ) {
		global $wpdb;
		$cur = self::get( $id );
		if ( ! $cur || $cur['deleted_at'] ) {
			return false;
		}
		$hist   = json_decode( (string) $cur['history_json'], true );
		$hist   = is_array( $hist ) ? $hist : array();
		$hist[] = array( 'at' => current_time( 'mysql' ), 'by' => (int) $actor, 'why' => 'deleted', 'before' => array( 'start_at' => $cur['start_at'], 'end_at' => $cur['end_at'], 'minutes' => $cur['minutes'] ) );
		$n      = $wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::t_entries() . ' SET deleted_at = %s, deleted_by = %d, history_json = %s WHERE id = %d AND deleted_at IS NULL', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (self::t_entries())
			current_time( 'mysql' ), (int) $actor, wp_json_encode( $hist ), (int) $id
		) );
		return 1 === (int) $n;
	}

	/** A named MySQL lock per person around a clock change (two taps on two phones never make two running clocks). */
	public static function lock( $uid ) {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', 'zhr_clock_' . (int) $uid ) );
	}

	public static function unlock( $uid ) {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'zhr_clock_' . (int) $uid ) );
	}

	/* ------------------------------------------------------------------ meal answers */

	/**
	 * user_id => date => [meal_no => ['answer' => …, 'about' => 'status|start_ts']] for [$from, $to]; $uid 0 = everyone.
	 * 'about' is the meal the answer was given for: if the day's hours change afterwards, the answer no longer counts.
	 */
	public static function meal_answers( $uid, $from, $to ) {
		global $wpdb;
		$sql    = 'SELECT user_id, work_date, meal_no, answer, about FROM ' . self::t_meals() . ' WHERE work_date BETWEEN %s AND %s' . ( $uid > 0 ? ' AND user_id = %d' : '' );
		$params = $uid > 0 ? array( $from, $to, (int) $uid ) : array( $from, $to );
		$out    = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) as $r ) { // phpcs:ignore
			if ( in_array( $r['answer'], array( 'voluntary', 'not_provided' ), true ) ) {
				$out[ (int) $r['user_id'] ][ $r['work_date'] ][ (int) $r['meal_no'] ] = array( 'answer' => $r['answer'], 'about' => (string) $r['about'] );
			}
		}
		return $out;
	}

	/** Record (or change) an answer, keeping what it was. */
	public static function set_meal_answer( $uid, $date, $no, $answer, $actor, $about = '' ) {
		global $wpdb;
		$t   = self::t_meals();
		$cur = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id = %d AND work_date = %s AND meal_no = %d", (int) $uid, $date, (int) $no ), ARRAY_A ); // phpcs:ignore
		$now = current_time( 'mysql' );
		if ( $cur ) {
			if ( $cur['answer'] === $answer && (string) $cur['about'] === (string) $about ) {
				return true;
			}
			$h   = json_decode( (string) $cur['history_json'], true );
			$h   = is_array( $h ) ? $h : array();
			$h[] = array( 'at' => $now, 'by' => (int) $actor, 'before' => $cur['answer'], 'about_before' => (string) $cur['about'], 'by_before' => (int) $cur['answered_by'], 'at_before' => $cur['answered_at'] );
			return false !== $wpdb->update( $t, array( 'answer' => $answer, 'about' => (string) $about, 'answered_by' => (int) $actor, 'answered_at' => $now, 'history_json' => wp_json_encode( $h ) ), array( 'id' => (int) $cur['id'] ) );
		}
		return (bool) $wpdb->insert( $t, array( 'user_id' => (int) $uid, 'work_date' => $date, 'meal_no' => (int) $no, 'answer' => $answer, 'about' => (string) $about, 'answered_by' => (int) $actor, 'answered_at' => $now ) );
	}

	/* ------------------------------------------------------------------ activity */

	/** Add activity rows; a row whose ref is already there is skipped. Returns how many were new. */
	public static function add_activity( array $rows ) {
		global $wpdb;
		$n = 0;
		foreach ( array_chunk( $rows, 200 ) as $chunk ) {
			$vals = array();
			$ph   = array();
			foreach ( $chunk as $r ) {
				if ( empty( $r['user_id'] ) || empty( $r['at'] ) || empty( $r['ref'] ) ) {
					continue;
				}
				$ph[]   = '(%d, %s, %s, %s, ' . ( isset( $r['lat'] ) && null !== $r['lat'] ? '%f' : 'NULL' ) . ', ' . ( isset( $r['lng'] ) && null !== $r['lng'] ? '%f' : 'NULL' ) . ', ' . ( isset( $r['acc'] ) && null !== $r['acc'] ? '%d' : 'NULL' ) . ', ' . ( ! empty( $r['until'] ) ? '%s' : 'NULL' ) . ', %s, %s)';
				$vals[] = (int) $r['user_id'];
				$vals[] = (string) $r['at'];
				$vals[] = substr( (string) ( $r['kind'] ?? '' ), 0, 16 );
				$vals[] = substr( (string) ( $r['app_id'] ?? '' ), 0, 64 );
				if ( isset( $r['lat'] ) && null !== $r['lat'] ) {
					$vals[] = (float) $r['lat'];
				}
				if ( isset( $r['lng'] ) && null !== $r['lng'] ) {
					$vals[] = (float) $r['lng'];
				}
				if ( isset( $r['acc'] ) && null !== $r['acc'] ) {
					$vals[] = (int) $r['acc'];
				}
				if ( ! empty( $r['until'] ) ) {
					$vals[] = (string) $r['until'];
				}
				$vals[] = substr( (string) ( $r['detail'] ?? '' ), 0, 120 );
				$vals[] = substr( (string) $r['ref'], 0, 80 );
			}
			if ( ! $ph ) {
				continue;
			}
			$sql = 'INSERT IGNORE INTO ' . self::t_activity() . ' (user_id, at, kind, app_id, lat, lng, acc, until, detail, ref) VALUES ' . implode( ', ', $ph );
			$n  += (int) $wpdb->query( $wpdb->prepare( $sql, $vals ) ); // phpcs:ignore
		}
		return $n;
	}

	/**
	 * Before a walk is re-copied: remove its copied rows (its spans, driven stretches and points: refs
	 * zdh:span:<id>[:…], zdh:drv:<id>:…, zdh:pt:<id>:…) that the re-copy $rows does not make exactly as they are --
	 * a ref it no longer makes, or the same ref for another person or with other times (the walker changed; the finish
	 * was corrected). Those are then written again by the insert. The walk timer's own rows (zdh:start:, zdh:finish:)
	 * are never touched, nor any other walk's (zdh:pt:12: is not zdh:pt:120:).
	 */
	public static function prune_walk_activity( $drop_id, array $rows ) {
		global $wpdb;
		$drop_id = (int) $drop_id;
		if ( $drop_id <= 0 ) {
			return 0;
		}
		$sig  = function ( $uid, $at, $until ) {
			return (int) $uid . '|' . substr( (string) $at, 0, 19 ) . '|' . ( null === $until || '' === (string) $until ? '' : substr( (string) $until, 0, 19 ) );
		};
		$want = array();
		foreach ( $rows as $r ) {
			$want[ (string) $r['ref'] ] = $sig( $r['user_id'] ?? 0, $r['at'] ?? '', $r['until'] ?? null );
		}
		$like = function ( $p ) use ( $wpdb ) { return $wpdb->esc_like( $p ) . '%'; };
		$sql  = 'SELECT id, ref, user_id, at, until FROM ' . self::t_activity() . ' WHERE ( ref = %s OR ref LIKE %s OR ref LIKE %s OR ref LIKE %s )';
		$args = array( 'zdh:span:' . $drop_id, $like( 'zdh:span:' . $drop_id . ':' ), $like( 'zdh:drv:' . $drop_id . ':' ), $like( 'zdh:pt:' . $drop_id . ':' ) );
		$gone = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) as $have ) { // phpcs:ignore
			$ref = (string) $have['ref'];
			if ( ! isset( $want[ $ref ] ) || $want[ $ref ] !== $sig( $have['user_id'], $have['at'], $have['until'] ) ) {
				$gone[] = (int) $have['id'];
			}
		}
		if ( ! $gone ) {
			return 0;
		}
		return (int) $wpdb->query( 'DELETE FROM ' . self::t_activity() . ' WHERE id IN (' . implode( ',', array_map( 'intval', $gone ) ) . ')' ); // phpcs:ignore
	}

	/** A person's activity in [$from_dt, $to_dt] (site time), oldest first. A walk span that began earlier and runs into the window counts. */
	public static function activity( $uid, $from_dt, $to_dt, $limit = 5000 ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			// a walk span that began before the window counts; walks are short, so a day before is far enough back (and
			// keeps the index range to the window instead of everything this person ever did)
			'SELECT at, kind, app_id, lat, lng, acc, until, detail FROM ' . self::t_activity() . ' WHERE user_id = %d AND at >= %s AND at <= %s AND ( at >= %s OR ( until IS NOT NULL AND until > %s ) ) ORDER BY at ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (self::t_activity())
			(int) $uid, ZHR_Time::local( (int) ZHR_Time::ts( substr( $from_dt, 0, 19 ) ) - DAY_IN_SECONDS ), $to_dt, $from_dt, $from_dt, (int) $limit
		), ARRAY_A );
		return (array) $rows;
	}

	public static function table_exists( $table ) {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === (string) $table;
	}

	public static function stats() {
		global $wpdb;
		return array(
			'entries'  => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t_entries() . ' WHERE deleted_at IS NULL' ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (self::t_entries())
			'running'  => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t_entries() . " WHERE deleted_at IS NULL AND end_at IS NULL AND end_review = ''" ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (self::t_entries())
			'activity' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t_activity() ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a fixed name (self::t_activity())
		);
	}
}
