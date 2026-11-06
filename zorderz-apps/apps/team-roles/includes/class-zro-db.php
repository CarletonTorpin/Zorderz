<?php
/**
 * ZRO_DB — two tables.
 *
 *   {prefix}zro_answers   one row per person per ability (or item cell) that someone has marked:
 *                         level (can | learning | cannot), an optional note and "since" date, who
 *                         last changed it and when. No row = not marked, which answers Can't.
 *   {prefix}zro_history   every change, never edited or deleted: who, when, from -> to (level,
 *                         note, since), and how (grid | copy | migrate | ability). Changes to the
 *                         list of abilities are kept here too, with user_id 0.
 *
 * A request-local memory store can stand in for both tables (memory()): the unit tests use it,
 * and so can a dry run that must never write.
 *
 * @package Zorderz\TeamRoles
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZRO_DB {

	const LEVELS   = array( 'can', 'learning', 'cannot' );
	const MAX_NOTE = 300;

	/** @var array|null In-memory tables: [ 'answers' => [uid => [key => row]], 'history' => [rows], 'options' => [] ]. */
	private static $mem = null;

	/** Switch to (true) or away from (false) the in-memory store. Starting it empties it. */
	public static function memory( $on = true ) {
		self::$mem = $on ? array( 'answers' => array(), 'history' => array(), 'claims' => array(), 'seq' => 0 ) : null;
	}

	public static function in_memory() {
		return null !== self::$mem;
	}

	public static function t_answers() {
		global $wpdb;
		return $wpdb->prefix . 'zro_answers';
	}

	public static function t_history() {
		global $wpdb;
		return $wpdb->prefix . 'zro_history';
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$cs = $wpdb->get_charset_collate();
		$a  = self::t_answers();
		$h  = self::t_history();
		dbDelta( "CREATE TABLE $a (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			ability varchar(128) NOT NULL,
			level varchar(10) NOT NULL DEFAULT 'cannot',
			note varchar(300) NOT NULL DEFAULT '',
			since date NULL DEFAULT NULL,
			updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_ability (user_id,ability),
			KEY ability_level (ability,level)
		) $cs;" );
		dbDelta( "CREATE TABLE $h (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ability varchar(128) NOT NULL DEFAULT '',
			from_level varchar(10) NOT NULL DEFAULT '',
			to_level varchar(10) NOT NULL DEFAULT '',
			from_note varchar(300) NOT NULL DEFAULT '',
			to_note varchar(300) NOT NULL DEFAULT '',
			from_since date NULL DEFAULT NULL,
			to_since date NULL DEFAULT NULL,
			changed_by bigint(20) unsigned NOT NULL DEFAULT 0,
			changed_at datetime NOT NULL,
			source varchar(20) NOT NULL DEFAULT 'grid',
			detail varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY user_at (user_id,changed_at),
			KEY ability_at (ability,changed_at)
		) $cs;" );
	}

	/**
	 * Plain text: control characters out, whitespace collapsed, cut to length. Tags are NOT
	 * stripped (strip_tags reads "height <3m only" as a tag); every reader escapes on output, and
	 * callers of the person-can answer treat `why` as plain text.
	 */
	public static function plain( $s, $max ) {
		$s = (string) $s;
		if ( function_exists( 'wp_check_invalid_utf8' ) ) {
			$s = wp_check_invalid_utf8( $s, true );
		}
		$s = str_replace( array( "\r", "\n", "\t" ), ' ', $s );
		$s = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $s );
		$s = trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	public static function clean_note( $s ) {
		return self::plain( $s, self::MAX_NOTE );
	}

	/** A real calendar date (Y-m-d), or null. */
	public static function clean_date( $s ) {
		$s = trim( (string) $s );
		if ( '' === $s || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}
		return $s;
	}

	private static function now() {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}

	/** One person's answer for one key, or null when not marked. */
	public static function answer( $uid, $ability ) {
		if ( null !== self::$mem ) {
			$r = self::$mem['answers'][ (int) $uid ][ (string) $ability ] ?? null;
			return $r ? self::shape( $r ) : null;
		}
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t_answers() . ' WHERE user_id = %d AND ability = %s', (int) $uid, (string) $ability ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (t_answers()); every value is a placeholder
		return $r ? self::shape( $r ) : null;
	}

	/** Answers for many people: [uid => [key => row]]. */
	public static function answers_for( array $uids ) {
		$uids = array_values( array_filter( array_map( 'intval', $uids ) ) );
		$out  = array();
		if ( ! $uids ) {
			return $out;
		}
		if ( null !== self::$mem ) {
			foreach ( $uids as $u ) {
				foreach ( self::$mem['answers'][ $u ] ?? array() as $k => $r ) {
					$out[ $u ][ $k ] = self::shape( $r );
				}
			}
			return $out;
		}
		global $wpdb;
		$in = implode( ',', $uids ); // integers only
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . self::t_answers() . " WHERE user_id IN ($in)", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix and a fixed name; $in holds only integers (intval above)
			$out[ (int) $r['user_id'] ][ $r['ability'] ] = self::shape( $r );
		}
		return $out;
	}

	/** Everyone marked for one key at the given levels: [uid => row]. */
	public static function by_ability( $ability, array $levels ) {
		$levels = array_values( array_intersect( $levels, self::LEVELS ) );
		if ( ! $levels ) {
			return array();
		}
		$out = array();
		if ( null !== self::$mem ) {
			foreach ( self::$mem['answers'] as $u => $rows ) {
				$r = $rows[ (string) $ability ] ?? null;
				if ( $r && in_array( $r['level'], $levels, true ) ) {
					$out[ (int) $u ] = self::shape( $r );
				}
			}
			return $out;
		}
		global $wpdb;
		$ph   = implode( ',', array_fill( 0, count( $levels ), '%s' ) );
		$args = array_merge( array( (string) $ability ), $levels );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t_answers() . " WHERE ability = %s AND level IN ($ph)", $args ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix and a fixed name; $ph is one %s placeholder per level
			$out[ (int) $r['user_id'] ] = self::shape( $r );
		}
		return $out;
	}

	/** Does anyone hold any mark at all (any ability, any level)? Team Roles is "in use" then. */
	public static function has_marks() {
		if ( null !== self::$mem ) {
			foreach ( self::$mem['answers'] as $rows ) {
				if ( ! empty( $rows ) ) {
					return true;
				}
			}
			return false;
		}
		global $wpdb;
		return null !== $wpdb->get_var( 'SELECT 1 FROM ' . self::t_answers() . ' LIMIT 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (t_answers()); the query takes no values
	}

	/** How many marks one key holds (anyone, any level). */
	public static function count_for( array $keys ) {
		$keys = array_values( array_filter( array_map( 'strval', $keys ) ) );
		if ( ! $keys ) {
			return 0;
		}
		if ( null !== self::$mem ) {
			$n = 0;
			foreach ( self::$mem['answers'] as $rows ) {
				$n += count( array_intersect_key( $rows, array_flip( $keys ) ) );
			}
			return $n;
		}
		global $wpdb;
		$ph = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t_answers() . " WHERE ability IN ($ph)", $keys ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix and a fixed name; $ph is one %s placeholder per key
	}

	private static function shape( array $r ) {
		return array(
			'level'      => in_array( $r['level'] ?? '', self::LEVELS, true ) ? $r['level'] : 'cannot',
			'note'       => (string) ( $r['note'] ?? '' ),
			'since'      => ! empty( $r['since'] ) && '0000-00-00' !== $r['since'] ? (string) $r['since'] : '',
			'updated_by' => (int) ( $r['updated_by'] ?? 0 ),
			'updated_at' => (string) ( $r['updated_at'] ?? '' ),
		);
	}

	/**
	 * Set one cell. Writes the answer and a history row, only when something changed. Returns
	 * ['changed' => bool, 'answer' => row] or WP_Error. The caller has already decided the actor may.
	 */
	public static function set( $uid, $ability, $level, $note, $since, $by, $source = 'grid', $detail = '' ) {
		$uid = (int) $uid;
		if ( ! in_array( $level, self::LEVELS, true ) ) {
			return new WP_Error( 'bad_level', __( 'Choose Can, Learning or Can\'t.', 'zorderz' ), array( 'status' => 400 ) );
		}
		$note  = self::clean_note( $note );
		$since = self::clean_date( $since );
		$old   = self::answer( $uid, $ability );
		$from  = $old ? $old : array( 'level' => '', 'note' => '', 'since' => '' );
		if ( $old && $old['level'] === $level && $old['note'] === $note && $old['since'] === (string) $since ) {
			return array( 'changed' => false, 'answer' => $old );
		}
		if ( ! $old && 'cannot' === $level && '' === $note && null === $since ) {
			return array( 'changed' => false, 'answer' => null ); // not marked already reads Can't: nothing to keep
		}
		$now = self::now();
		if ( null !== self::$mem ) {
			self::$mem['answers'][ $uid ][ (string) $ability ] = array( 'level' => $level, 'note' => $note, 'since' => (string) $since, 'updated_by' => (int) $by, 'updated_at' => $now );
		} else {
			global $wpdb;
			$vals = array( $uid, (string) $ability, $level, $note );
			if ( null !== $since ) {
				$vals[] = $since;
			}
			$vals[] = (int) $by;
			$vals[] = $now;
			$ok     = $wpdb->query( $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name; since is the literal NULL or a %s placeholder; every value is a placeholder
				'INSERT INTO ' . self::t_answers() . ' (user_id, ability, level, note, since, updated_by, updated_at) VALUES (%d, %s, %s, %s, ' . ( null === $since ? 'NULL' : '%s' ) . ', %d, %s)
				 ON DUPLICATE KEY UPDATE level = VALUES(level), note = VALUES(note), since = VALUES(since), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
				$vals
			) );
			if ( false === $ok ) {
				return new WP_Error( 'db', __( 'That could not be saved just now. Try again.', 'zorderz' ), array( 'status' => 500 ) );
			}
		}
		self::log( array(
			'user_id'    => $uid,
			'ability'    => (string) $ability,
			'from_level' => $from['level'],
			'to_level'   => $level,
			'from_note'  => $from['note'],
			'to_note'    => $note,
			'from_since' => $from['since'],
			'to_since'   => (string) $since,
			'changed_by' => (int) $by,
			'source'     => $source,
			'detail'     => $detail,
		) );
		return array( 'changed' => true, 'answer' => self::answer( $uid, $ability ) );
	}

	/** Clear one cell back to not marked. True when there was something to clear (and a history row says so). */
	public static function clear( $uid, $ability, $by ) {
		$old = self::answer( $uid, $ability );
		if ( ! $old ) {
			return false;
		}
		if ( null !== self::$mem ) {
			unset( self::$mem['answers'][ (int) $uid ][ (string) $ability ] );
		} else {
			global $wpdb;
			if ( false === $wpdb->delete( self::t_answers(), array( 'user_id' => (int) $uid, 'ability' => (string) $ability ), array( '%d', '%s' ) ) ) {
				return new WP_Error( 'db', __( 'That could not be saved just now. Try again.', 'zorderz' ), array( 'status' => 500 ) );
			}
		}
		self::log( array( 'user_id' => (int) $uid, 'ability' => (string) $ability, 'from_level' => $old['level'], 'to_level' => '', 'from_note' => $old['note'],
			'from_since' => $old['since'], 'changed_by' => (int) $by, 'source' => 'grid', 'detail' => 'Back to not marked' ) );
		return true;
	}

	/** Add one history row. Never updated or deleted. */
	public static function log( array $h ) {
		$row = array(
			'user_id'    => (int) ( $h['user_id'] ?? 0 ),
			'ability'    => substr( (string) ( $h['ability'] ?? '' ), 0, 128 ),
			'from_level' => (string) ( $h['from_level'] ?? '' ),
			'to_level'   => (string) ( $h['to_level'] ?? '' ),
			'from_note'  => self::clean_note( $h['from_note'] ?? '' ),
			'to_note'    => self::clean_note( $h['to_note'] ?? '' ),
			'from_since' => self::clean_date( $h['from_since'] ?? '' ),
			'to_since'   => self::clean_date( $h['to_since'] ?? '' ),
			'changed_by' => (int) ( $h['changed_by'] ?? 0 ),
			'changed_at' => self::now(),
			'source'     => substr( (string) ( $h['source'] ?? 'grid' ), 0, 20 ),
			'detail'     => substr( (string) ( $h['detail'] ?? '' ), 0, 255 ),
		);
		if ( null !== self::$mem ) {
			$row['id']                 = ++self::$mem['seq'];
			self::$mem['history'][]    = $row;
			return;
		}
		global $wpdb;
		$wpdb->insert( self::t_history(), $row, array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ) );
	}

	/**
	 * History, newest first. $uids: only these people (null = everyone, including ability-list
	 * changes with user_id 0). $ability: only this key ('' = all).
	 */
	public static function history( $uids, $ability = '', $limit = 100, $before_id = 0 ) {
		$limit = max( 1, min( 501, (int) $limit ) );
		if ( is_array( $uids ) ) {
			$uids = array_values( array_filter( array_map( 'intval', $uids ) ) );
			if ( ! $uids ) {
				return array();
			}
		}
		if ( null !== self::$mem ) {
			$rows = array_reverse( self::$mem['history'] );
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $uids, $ability, $before_id ) {
				return ( ! is_array( $uids ) || in_array( (int) $r['user_id'], $uids, true ) )
					&& ( '' === (string) $ability || $r['ability'] === (string) $ability )
					&& ( $before_id <= 0 || $r['id'] < $before_id );
			} ) );
			return array_slice( $rows, 0, $limit );
		}
		global $wpdb;
		$where = array( '1=1' );
		$args  = array();
		if ( is_array( $uids ) ) {
			$where[] = 'user_id IN (' . implode( ',', $uids ) . ')';
		}
		if ( '' !== (string) $ability ) {
			$where[] = 'ability = %s';
			$args[]  = (string) $ability;
		}
		if ( $before_id > 0 ) {
			$where[] = 'id < %d';
			$args[]  = (int) $before_id;
		}
		$sql = 'SELECT * FROM ' . self::t_history() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT ' . $limit;
		return (array) $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql holds the table name ($wpdb->prefix and a fixed name), fixed conditions, integer user ids (intval above), an integer limit and placeholders
	}

	/**
	 * Claim an option row atomically (one INSERT IGNORE): true for exactly one caller. add_option()
	 * is not a lock: two requests that both look before either writes would both get true.
	 * $stale_after > 0 lets a caller take over a claim older than that many seconds (a request that
	 * died), also atomically.
	 */
	public static function claim_option( $name, $stale_after = 0 ) {
		if ( null !== self::$mem ) {
			if ( isset( self::$mem['claims'][ $name ] ) ) {
				return false;
			}
			self::$mem['claims'][ $name ] = time();
			return true;
		}
		global $wpdb;
		$now = time();
		$n   = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) $now ) );
		$got = 1 === (int) $n;
		if ( ! $got && $stale_after > 0 ) {
			$n   = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d", (string) $now, $name, $now - (int) $stale_after ) );
			$got = 1 === (int) $n;
		}
		self::forget_option( $name );
		return $got;
	}

	/** Let a claim go. */
	public static function release_option( $name ) {
		if ( null !== self::$mem ) {
			unset( self::$mem['claims'][ $name ] );
			return;
		}
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => $name ), array( '%s' ) );
		self::forget_option( $name );
	}

	/** An option as stored right now, past every cache (null when absent). */
	public static function fresh_option( $name ) {
		if ( null !== self::$mem ) {
			$v = get_option( $name, null );
			return $v;
		}
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		return null === $v ? null : maybe_unserialize( $v );
	}

	private static function forget_option( $name ) {
		wp_cache_delete( $name, 'options' );
		$no = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $no ) && isset( $no[ $name ] ) ) {
			unset( $no[ $name ] );
			wp_cache_set( 'notoptions', $no, 'options' );
		}
	}

	public static function stats() {
		if ( null !== self::$mem ) {
			$n = 0;
			foreach ( self::$mem['answers'] as $rows ) {
				$n += count( $rows );
			}
			return array( 'answers' => $n, 'people' => count( array_filter( self::$mem['answers'] ) ), 'history' => count( self::$mem['history'] ) );
		}
		global $wpdb;
		return array(
			'answers' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t_answers() ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (t_answers()); the query takes no values
			'people'  => (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT user_id) FROM ' . self::t_answers() ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (t_answers()); the query takes no values
			'history' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t_history() ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (t_history()); the query takes no values
		);
	}
}
