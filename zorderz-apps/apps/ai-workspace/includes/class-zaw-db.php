<?php
/**
 * ZAW_DB -- the Ai Workspace's two tables.
 *
 *   {prefix}zaw_convs   one row per conversation: its key (16 hex, made here), its person, the hub's conversation id
 *                       (the conversation itself lives on the hub computer, sealed), and the codes this conversation
 *                       produced (customer ids and sealed codes): the only ones a model may hand back and have decoded.
 *   {prefix}zaw_turns   one row per turn: its id (32 hex), person, state, the CODED question, answer and progress lines
 *                       (kept 7 days, then blanked), the single-use fetch link's and the tool token's HASHES, the site's
 *                       Ai hold, and the turn's usage (numbers; only under the person's consent). `rev` counts its
 *                       changes, so a poller holding an older copy can't write it back.
 *
 * Never a key, a token in the clear, or an uncoded word from a person.
 *
 * @package Zorderz\AiWorkspace
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAW_DB {

	const OPTION = 'zaw_db_version';

	public static function convs() {
		global $wpdb;
		return $wpdb->prefix . 'zaw_convs';
	}

	public static function turns() {
		global $wpdb;
		return $wpdb->prefix . 'zaw_turns';
	}

	public static function maybe_install() {
		if ( ZAW_DB_VERSION !== (string) get_option( self::OPTION, '' ) ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;
		$cs  = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
		$c   = self::convs();
		$t   = self::turns();
		$sql = array(
			"CREATE TABLE {$c} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			conv_key varchar(16) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			engine_conv varchar(16) NOT NULL DEFAULT '',
			codes_c longtext NULL,
			codes_e longtext NULL,
			created datetime NOT NULL,
			updated datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY conv_key (conv_key),
			KEY user_id (user_id)
		) {$cs};",
			"CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			turn_id varchar(32) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			conv_key varchar(16) NOT NULL DEFAULT '',
			state varchar(16) NOT NULL DEFAULT '',
			question longtext NULL,
			answer longtext NULL,
			events longtext NULL,
			next_offset int(10) unsigned NOT NULL DEFAULT 0,
			fetch_hash varchar(64) NOT NULL DEFAULT '',
			fetch_exp datetime NULL,
			tool_hash varchar(64) NOT NULL DEFAULT '',
			site_tools text NULL,
			hold_id bigint(20) unsigned NOT NULL DEFAULT 0,
			hold_milli int(10) unsigned NOT NULL DEFAULT 0,
			usd_milli int(10) unsigned NOT NULL DEFAULT 0,
			est tinyint(1) NOT NULL DEFAULT 0,
			in_tokens int(10) unsigned NOT NULL DEFAULT 0,
			out_tokens int(10) unsigned NOT NULL DEFAULT 0,
			cache_read int(10) unsigned NOT NULL DEFAULT 0,
			cache_write int(10) unsigned NOT NULL DEFAULT 0,
			searches smallint(5) unsigned NOT NULL DEFAULT 0,
			calls smallint(5) unsigned NOT NULL DEFAULT 0,
			tool_calls smallint(5) unsigned NOT NULL DEFAULT 0,
			model varchar(32) NOT NULL DEFAULT '',
			fallback tinyint(1) NOT NULL DEFAULT 0,
			stop varchar(24) NOT NULL DEFAULT '',
			error varchar(64) NOT NULL DEFAULT '',
			ledger_id bigint(20) NOT NULL DEFAULT 0,
			rev int(10) unsigned NOT NULL DEFAULT 0,
			coded_count smallint(5) unsigned NOT NULL DEFAULT 0,
			caught_count smallint(5) unsigned NOT NULL DEFAULT 0,
			kept_usage tinyint(1) NOT NULL DEFAULT 1,
			created datetime NOT NULL,
			updated datetime NOT NULL,
			finished datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY turn_id (turn_id),
			KEY user_state (user_id, state),
			KEY conv_key (conv_key)
		) {$cs};",
		);
		if ( ! function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		foreach ( $sql as $one ) {
			if ( function_exists( 'dbDelta' ) ) {
				dbDelta( $one );
			} else {
				$wpdb->query( $one ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- our own DDL, no input
			}
		}
		update_option( self::OPTION, ZAW_DB_VERSION, false );
	}

	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/* ------------------------------------------------------------------ turns */

	public static function turn( $turn_id ) {
		global $wpdb;
		if ( ! is_string( $turn_id ) || ! preg_match( '/^[a-f0-9]{32}$/', $turn_id ) ) {
			return null;
		}
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::turns() . ' WHERE turn_id = %s', $turn_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is ours
		return is_array( $r ) ? $r : null;
	}

	/**
	 * Change a turn. Every change moves its `rev` on by one, so a writer that read the row earlier can tell it is no
	 * longer the row it read (cas_turn). -> rows changed (0 or 1), or false on a database error.
	 */
	private static function update_turn( $id, array $f, $rev = null ) {
		global $wpdb;
		$f['updated'] = self::now();
		$sets         = array();
		$vals         = array();
		foreach ( $f as $k => $v ) {
			if ( ! preg_match( '/^[a-z_]{1,32}$/', (string) $k ) || 'rev' === $k || 'id' === $k ) {
				continue;
			}
			$sets[] = $k . ' = ' . ( is_int( $v ) || is_bool( $v ) ? '%d' : '%s' );
			$vals[] = is_bool( $v ) ? (int) $v : ( null === $v ? '' : $v );
		}
		$sql    = 'UPDATE ' . self::turns() . ' SET ' . implode( ', ', $sets ) . ', rev = rev + 1 WHERE id = %d';
		$vals[] = (int) $id;
		if ( null !== $rev ) {
			$sql   .= ' AND rev = %d';
			$vals[] = (int) $rev;
		}
		$n = $wpdb->query( $wpdb->prepare( $sql, $vals ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- column names checked above
		return false === $n ? false : (int) $n;
	}

	public static function set_turn( $id, array $f ) {
		return false !== self::update_turn( $id, $f );
	}

	/** Change a turn only if nobody changed it since it was read at $rev: a slow poller never writes back an older page. */
	public static function cas_turn( $id, $rev, array $f ) {
		return 1 === self::update_turn( $id, $f, (int) $rev );
	}

	/* ------------------------------------------------------------------ conversations */

	public static function conv( $key ) {
		global $wpdb;
		if ( ! is_string( $key ) || ! preg_match( '/^[a-f0-9]{16}$/', $key ) ) {
			return null;
		}
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::convs() . ' WHERE conv_key = %s', $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is ours
		return is_array( $r ) ? $r : null;
	}

	public static function new_conv( $uid ) {
		global $wpdb;
		$key = bin2hex( random_bytes( 8 ) );
		$now = self::now();
		$ok  = $wpdb->insert( self::convs(), array( 'conv_key' => $key, 'user_id' => (int) $uid, 'engine_conv' => '', 'codes_c' => '[]', 'codes_e' => '[]', 'created' => $now, 'updated' => $now ) );
		return $ok ? self::conv( $key ) : null;
	}

	public static function set_conv( $key, array $f ) {
		global $wpdb;
		$f['updated'] = self::now();
		return false !== $wpdb->update( self::convs(), $f, array( 'conv_key' => (string) $key ) );
	}

	/** The codes a conversation produced: [ customer ids, sealed codes ]. */
	public static function codes( array $conv ) {
		$c = json_decode( (string) ( $conv['codes_c'] ?? '[]' ), true );
		$e = json_decode( (string) ( $conv['codes_e'] ?? '[]' ), true );
		return array( is_array( $c ) ? array_values( array_map( 'intval', $c ) ) : array(), is_array( $e ) ? array_values( array_map( 'strval', $e ) ) : array() );
	}

	/** Add the codes found in $text to the conversation's own (at most 2,000 of each: the oldest go first). */
	public static function add_codes( $key, $text ) {
		$conv = self::conv( $key );
		if ( ! $conv || ! class_exists( 'ZDZ_Private' ) ) {
			return;
		}
		list( $c, $e ) = self::codes( $conv );
		$nc = array_values( array_unique( array_merge( $c, ZDZ_Private::codes( $text ) ) ) );
		$ne = array_values( array_unique( array_merge( $e, ZDZ_Private::sealed_codes( $text ) ) ) );
		if ( $nc === $c && $ne === $e ) {
			return;
		}
		self::set_conv( $key, array( 'codes_c' => wp_json_encode( array_slice( $nc, -2000 ) ), 'codes_e' => wp_json_encode( array_slice( $ne, -2000 ) ) ) );
	}
}
