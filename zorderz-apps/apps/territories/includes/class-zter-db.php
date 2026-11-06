<?php
/**
 * ZTER_DB — this app's own records. Nothing here reads another app's tables.
 *
 *   {prefix}zter_zips       one row per person × ZIP they cover (overlaps allowed; rules resolve them)
 *   {prefix}zter_decisions  one row per "who gets this?" answer: the stage, the rule and version that
 *                           matched, the ZIP, the work, each candidate's Team Roles LEVEL (never its
 *                           `why` or notes), what the asker's own pick was, what the rules chose, and
 *                           what was answered. No customer text is stored: the lines and the
 *                           customer stay with the asking app.
 *   {prefix}zter_history    every change to territories, rules, zones, words and settings; never edited
 *
 * Options (autoload off): zter_generation (raised by every territory, rule, zone or setting edit,
 * so a view may cache an answer briefly), zter_settings, zter_rr (round-robin turns).
 *
 * A request-local memory store can stand in for the three tables (memory()): the unit tests use it.
 *
 * @package Zorderz\Territories
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZTER_DB {

	const MAX_ZIPS_PER_PERSON = 400;
	const REFER_DAYS          = 120;

	/** @var array|null In-memory tables: zips [uid => [zip]], decisions [id => row], history [rows]. */
	private static $mem = null;

	/** Switch to (true) or away from (false) the in-memory store. Starting it empties it. */
	public static function memory( $on = true ) {
		self::$mem = $on ? array( 'zips' => array(), 'decisions' => array(), 'history' => array(), 'seq' => 0, 'hseq' => 0 ) : null;
		self::$sim = null;
	}

	public static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'zter_' . $name;
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$cs = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::t( 'zips' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			zip char(5) NOT NULL,
			added_by bigint(20) unsigned NOT NULL DEFAULT 0,
			added_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_zip (user_id,zip),
			KEY zip (zip)
		) {$cs};" );
		dbDelta( 'CREATE TABLE ' . self::t( 'decisions' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			asker_id bigint(20) unsigned NOT NULL DEFAULT 0,
			stage varchar(10) NOT NULL DEFAULT 'stub',
			mode varchar(10) NOT NULL DEFAULT 'shadow',
			is_test tinyint(1) NOT NULL DEFAULT 0,
			label varchar(80) NOT NULL DEFAULT '',
			zip char(5) NOT NULL DEFAULT '',
			zip_from varchar(12) NOT NULL DEFAULT '',
			kinds varchar(255) NOT NULL DEFAULT '',
			kind_state varchar(10) NOT NULL DEFAULT '',
			kind_notes text NULL,
			rule_id varchar(32) NOT NULL DEFAULT '',
			rule_version int(10) unsigned NOT NULL DEFAULT 0,
			default_initials varchar(8) NOT NULL DEFAULT '',
			chosen_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			chosen_initials varchar(16) NOT NULL DEFAULT '',
			chosen_people varchar(64) NOT NULL DEFAULT '',
			seller_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			live_rule_id varchar(64) NOT NULL DEFAULT '',
			live_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			live_people varchar(64) NOT NULL DEFAULT '',
			answered_initials varchar(16) NOT NULL DEFAULT '',
			confirmed_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			confirmed_people varchar(64) NOT NULL DEFAULT '',
			candidates text NULL,
			team_roles varchar(8) NOT NULL DEFAULT '',
			trail text NULL,
			dedupe_key char(32) NOT NULL DEFAULT '',
			applied_at datetime NULL,
			applied_doc varchar(40) NOT NULL DEFAULT '',
			notice varchar(40) NOT NULL DEFAULT '',
			note_by bigint(20) unsigned NOT NULL DEFAULT 0,
			note_to bigint(20) unsigned NOT NULL DEFAULT 0,
			note_reason varchar(300) NOT NULL DEFAULT '',
			note_at datetime NULL,
			refer tinyint(1) NOT NULL DEFAULT 0,
			review tinyint(1) NOT NULL DEFAULT 0,
			stub_key varchar(64) NOT NULL DEFAULT '',
			reference varchar(120) NOT NULL DEFAULT '',
			estimate_number varchar(20) NOT NULL DEFAULT '',
			refer_closed_at datetime NULL,
			refer_closed_by bigint(20) unsigned NOT NULL DEFAULT 0,
			refer_closed_how varchar(20) NOT NULL DEFAULT '',
			written_initials varchar(16) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY created (created_at),
			KEY refer (refer,created_at),
			KEY stub_key (stage,stub_key),
			KEY stage_created (stage,created_at),
			KEY dedupe (dedupe_key)
		) {$cs};" );
		dbDelta( 'CREATE TABLE ' . self::t( 'history' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			changed_at datetime NOT NULL,
			changed_by bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(20) NOT NULL,
			detail longtext NULL,
			PRIMARY KEY  (id),
			KEY changed (changed_at),
			KEY action (action),
			KEY user_id (user_id)
		) {$cs};" );
	}

	public static function now() {
		return function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' ); // UTC
	}

	private static function json( $v ) {
		return function_exists( 'wp_json_encode' ) ? wp_json_encode( $v ) : json_encode( $v );
	}

	/* ── ZIPs ─────────────────────────────────────────────────────────────────────────────────── */

	/** Five digits, or ''. A ZIP+4 keeps its first five. */
	public static function clean_zip( $z ) {
		$z = trim( (string) $z );
		if ( preg_match( '/^(\d{5})(?:-\d{4})?$/', $z, $m ) ) {
			return '00000' === $m[1] ? '' : $m[1];
		}
		return '';
	}

	/**
	 * A list of ZIPs from text or an array: commas, spaces and new lines all separate. Returns
	 * [ ok[], bad[] ]: ok is unique and sorted; bad holds what was not a ZIP, so the page can say so.
	 */
	public static function parse_zips( $raw ) {
		$items = is_array( $raw ) ? $raw : preg_split( '/[\s,;]+/', (string) $raw );
		$ok    = array();
		$bad   = array();
		foreach ( (array) $items as $it ) {
			$it = trim( is_scalar( $it ) ? (string) $it : '' );
			if ( '' === $it ) {
				continue;
			}
			$z = self::clean_zip( $it );
			if ( '' === $z ) {
				$bad[] = substr( $it, 0, 20 );
			} else {
				$ok[ $z ] = true;
			}
		}
		$ok = array_map( 'strval', array_keys( $ok ) ); // numeric keys come back as integers
		sort( $ok, SORT_STRING );
		return array( $ok, array_values( array_unique( $bad ) ) );
	}

	/** One person's ZIPs, sorted. */
	public static function zips_of( $uid ) {
		$uid = (int) $uid;
		if ( $uid <= 0 ) {
			return array();
		}
		if ( null !== self::$mem ) {
			$z = self::$mem['zips'][ $uid ] ?? array();
			sort( $z, SORT_STRING );
			return $z;
		}
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT zip FROM ' . self::t( 'zips' ) . ' WHERE user_id = %d ORDER BY zip', $uid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (self::t() with a literal); every value is a placeholder
		return is_array( $rows ) ? array_map( 'strval', $rows ) : array();
	}

	/** Who covers this ZIP: user ids, ascending. Null when the read fails (never "nobody"). */
	public static function people_for_zip( $zip ) {
		$zip = self::clean_zip( $zip );
		if ( '' === $zip ) {
			return array();
		}
		if ( null !== self::$mem ) {
			$out = array();
			foreach ( self::$mem['zips'] as $uid => $zips ) {
				if ( in_array( $zip, $zips, true ) ) {
					$out[] = (int) $uid;
				}
			}
			sort( $out );
			return $out;
		}
		global $wpdb;
		$wpdb->last_error = '';
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . self::t( 'zips' ) . ' WHERE zip = %s ORDER BY user_id', $zip ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (self::t() with a literal); every value is a placeholder
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			return null;
		}
		return array_map( 'intval', $rows );
	}

	/** Everyone's ZIPs: user_id => [zips]. Null when the read fails. */
	public static function all_zips() {
		if ( null !== self::$mem ) {
			$out = array();
			foreach ( self::$mem['zips'] as $uid => $zips ) {
				if ( $zips ) {
					sort( $zips, SORT_STRING );
					$out[ (int) $uid ] = $zips;
				}
			}
			ksort( $out );
			return $out;
		}
		global $wpdb;
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( 'SELECT user_id, zip FROM ' . self::t( 'zips' ) . ' ORDER BY user_id, zip', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (self::t() with a literal); the query takes no values
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			return null;
		}
		$out = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['user_id'] ][] = (string) $r['zip'];
		}
		return $out;
	}

	/**
	 * Replace one person's ZIP list. Each ZIP added or removed is in a history row. Raises the
	 * generation when anything changed. Returns [ added[], removed[] ].
	 */
	public static function set_zips( $uid, array $zips, $by, $source = 'page' ) {
		$uid  = (int) $uid;
		$now  = self::now();
		$have = self::zips_of( $uid );
		$want = array_slice( array_values( array_unique( $zips ) ), 0, self::MAX_ZIPS_PER_PERSON );
		$add  = array_values( array_diff( $want, $have ) );
		$del  = array_values( array_diff( $have, $want ) );
		if ( null !== self::$mem ) {
			self::$mem['zips'][ $uid ] = array_values( array_merge( array_diff( $have, $del ), $add ) );
		} else {
			global $wpdb;
			foreach ( $add as $z ) {
				$wpdb->insert( self::t( 'zips' ), array( 'user_id' => $uid, 'zip' => $z, 'added_by' => (int) $by, 'added_at' => $now ) );
			}
			if ( $del ) {
				$in = implode( ',', array_fill( 0, count( $del ), '%s' ) );
				$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t( 'zips' ) . " WHERE user_id = %d AND zip IN ({$in})", array_merge( array( $uid ), $del ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix and a fixed name; $in is one %s placeholder per ZIP
			}
		}
		if ( $add || $del ) {
			self::log( $by, $uid, 'zips', array( 'added' => $add, 'removed' => $del, 'source' => $source ) );
			self::bump();
		}
		return array( $add, $del );
	}

	/* ── Locks ──────────────────────────────────────────────────────────────────────────────── */

	/** A named database lock (waits up to 5 seconds). True when this request holds it. */
	public static function lock( $name ) {
		if ( null !== self::$mem ) {
			return true;
		}
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', (string) $name ) );
	}

	public static function unlock( $name ) {
		if ( null !== self::$mem ) {
			return;
		}
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', (string) $name ) );
	}

	/* ── The generation number ────────────────────────────────────────────────────────────────── */

	public static function generation() {
		return (int) get_option( 'zter_generation', 1 );
	}

	public static function bump() {
		update_option( 'zter_generation', self::generation() + 1, false );
	}

	/* ── Settings ─────────────────────────────────────────────────────────────────────────────── */

	/**
	 * peers_visible: may a salesperson see the others' territories (verbs and the app)? Default no.
	 * reviewers: people who see every decision and why without editing (the person who hands work out).
	 */
	public static function settings() {
		$s = get_option( 'zter_settings', array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'peers_visible' => ! empty( $s['peers_visible'] ),
			'reviewers'     => array_values( array_unique( array_filter( array_map( 'intval', (array) ( $s['reviewers'] ?? array() ) ) ) ) ),
		);
	}

	public static function save_settings( array $s, $by ) {
		$old = self::settings();
		$new = array(
			'peers_visible' => ! empty( $s['peers_visible'] ),
			'reviewers'     => array_values( array_unique( array_filter( array_map( 'intval', (array) ( $s['reviewers'] ?? array() ) ), function ( $u ) {
				return zter_is_team_member( $u );
			} ) ) ),
		);
		update_option( 'zter_settings', $new, false );
		if ( $new !== $old ) {
			self::log( $by, 0, 'settings', array( 'from' => $old, 'to' => $new ) );
			self::bump();
		}
		return $new;
	}

	/* ── Taking turns ─────────────────────────────────────────────────────────────────────────── */

	/**
	 * Least recently picked wins: each person's last turn is a sequence number per rule and per
	 * book, so overlapping territories share fairly. Three separate books: 'shadow' (what the rules
	 * would do), 'live' (real assignments, taken only when the asking app writes one) and 'test'
	 * (test stubs; they never move the other two).
	 */
	public static function next_in_turn( $book, $rule_id, array $uids ) {
		$uids = array_values( array_unique( array_map( 'intval', $uids ) ) );
		sort( $uids );
		if ( ! $uids ) {
			return 0;
		}
		$rr   = null !== self::$sim ? self::$sim : get_option( 'zter_rr', array() );
		$seen = is_array( $rr ) ? (array) ( $rr[ self::book( $book ) ][ $rule_id ] ?? array() ) : array();
		$pick = $uids[0];
		$best = PHP_INT_MAX;
		foreach ( $uids as $u ) {
			$n = (int) ( $seen[ $u ] ?? 0 );
			if ( $n < $best ) {
				$best = $n;
				$pick = $u;
			}
		}
		return $pick;
	}

	/** Record that $uid took a turn under a rule, in one book. */
	public static function take_turn( $book, $rule_id, $uid ) {
		$uid = (int) $uid;
		if ( $uid <= 0 || '' === (string) $rule_id ) {
			return;
		}
		$rr        = null !== self::$sim ? self::$sim : get_option( 'zter_rr', array() );
		$rr        = is_array( $rr ) ? $rr : array();
		$rr['seq'] = (int) ( $rr['seq'] ?? 0 ) + 1;
		$rr[ self::book( $book ) ][ (string) $rule_id ][ $uid ] = $rr['seq'];
		if ( null !== self::$sim ) {
			self::$sim = $rr; // a preview: the turn is taken in memory only, never stored
			return;
		}
		update_option( 'zter_rr', $rr, false );
	}

	/**
	 * A batch preview takes its turns in memory, so ten stubs in one ZIP shared by two people are
	 * proposed alternately, as they would really be given, and nothing is stored. Always end it.
	 */
	private static $sim = null;

	public static function sim_begin() {
		$rr        = get_option( 'zter_rr', array() );
		self::$sim = is_array( $rr ) ? $rr : array();
	}

	public static function sim_end() {
		self::$sim = null;
	}

	public static function simulating() {
		return null !== self::$sim;
	}

	private static function book( $b ) {
		return in_array( $b, array( 'shadow', 'live', 'test' ), true ) ? $b : 'shadow';
	}

	/* ── Decisions ────────────────────────────────────────────────────────────────────────────── */

	public static function add_decision( array $d ) {
		$ppl = function ( $v ) {
			return substr( implode( ',', array_map( 'intval', array_values( (array) $v ) ) ), 0, 64 );
		};
		$row = array(
			'created_at'        => self::now(),
			'stage'             => 'install' === ( $d['stage'] ?? '' ) ? 'install' : 'stub',
			'asker_id'          => (int) ( $d['asker_id'] ?? 0 ),
			'mode'              => 'live' === ( $d['mode'] ?? '' ) ? 'live' : 'shadow',
			'is_test'           => empty( $d['is_test'] ) ? 0 : 1,
			'label'             => substr( (string) ( $d['label'] ?? '' ), 0, 80 ),
			'zip'               => (string) ( $d['zip'] ?? '' ),
			'zip_from'          => substr( (string) ( $d['zip_from'] ?? '' ), 0, 12 ),
			'kinds'             => self::fit_list( (array) ( $d['kinds'] ?? array() ), 255 ),
			'kind_state'        => substr( (string) ( $d['kind_state'] ?? '' ), 0, 10 ),
			'kind_notes'        => self::json( array_values( (array) ( $d['kind_notes'] ?? array() ) ) ),
			'rule_id'           => substr( (string) ( $d['rule_id'] ?? '' ), 0, 32 ),
			'rule_version'      => (int) ( $d['rule_version'] ?? 0 ),
			'default_initials'  => substr( (string) ( $d['default_initials'] ?? '' ), 0, 8 ),
			'chosen_user_id'    => (int) ( $d['chosen_user_id'] ?? 0 ),
			'chosen_initials'   => substr( (string) ( $d['chosen_initials'] ?? '' ), 0, 16 ),
			'chosen_people'     => $ppl( $d['chosen_people'] ?? array() ),
			'seller_user_id'    => (int) ( $d['seller_user_id'] ?? 0 ),
			'live_rule_id'      => substr( (string) ( $d['live_rule_id'] ?? '' ), 0, 64 ), // a pair keeps both ids, "a+b"
			'live_user_id'      => (int) ( $d['live_user_id'] ?? 0 ),
			'live_people'       => $ppl( $d['live_people'] ?? array() ),
			'answered_initials' => substr( (string) ( $d['answered_initials'] ?? '' ), 0, 16 ),
			'candidates'        => self::json( array_values( (array) ( $d['candidates'] ?? array() ) ) ),
			'team_roles'        => substr( (string) ( $d['team_roles'] ?? '' ), 0, 8 ),
			'trail'             => self::json( array_values( (array) ( $d['trail'] ?? array() ) ) ),
			'dedupe_key'        => (string) ( $d['dedupe_key'] ?? '' ),
			'refer'             => max( 0, min( 2, (int) ( $d['refer'] ?? 0 ) ) ),
			'review'            => empty( $d['review'] ) ? 0 : 1,
			'stub_key'          => substr( (string) ( $d['stub_key'] ?? '' ), 0, 64 ),
			'reference'         => substr( (string) ( $d['reference'] ?? '' ), 0, 120 ),
		);
		if ( null !== self::$mem ) {
			$id                             = ++self::$mem['seq'];
			self::$mem['decisions'][ $id ] = $row + array( 'id' => $id, 'confirmed_user_id' => 0, 'confirmed_people' => '', 'applied_at' => null, 'applied_doc' => '', 'notice' => '',
				'note_by' => 0, 'note_to' => 0, 'note_reason' => '', 'note_at' => null, 'estimate_number' => '', 'refer_closed_at' => null, 'refer_closed_by' => 0, 'refer_closed_how' => '', 'written_initials' => '' );
			return $id;
		}
		global $wpdb;
		$ok = $wpdb->insert( self::t( 'decisions' ), $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/** A list joined with commas, as many whole items as fit in $max characters. */
	private static function fit_list( array $items, $max ) {
		$out = '';
		foreach ( $items as $it ) {
			$next = '' === $out ? (string) $it : $out . ',' . $it;
			if ( strlen( $next ) > $max ) {
				break;
			}
			$out = $next;
		}
		return $out;
	}

	public static function decision( $id ) {
		if ( null !== self::$mem ) {
			$r = self::$mem['decisions'][ (int) $id ] ?? null;
			return $r ? self::shape_decision( $r ) : null;
		}
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'decisions' ) . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (self::t() with a literal); every value is a placeholder
		return $r ? self::shape_decision( $r ) : null;
	}

	/** A recent decision with the same key (the same job asked again), so the turn is not taken twice. 0 minutes: no window. */
	public static function recent_by_key( $key, $minutes = 10 ) {
		if ( '' === $key ) {
			return null;
		}
		$since = (int) $minutes > 0 ? gmdate( 'Y-m-d H:i:s', time() - 60 * (int) $minutes ) : '1970-01-01 00:00:00';
		if ( null !== self::$mem ) {
			foreach ( array_reverse( self::$mem['decisions'], true ) as $r ) {
				if ( $r['dedupe_key'] === $key && $r['created_at'] >= $since ) {
					return self::shape_decision( $r );
				}
			}
			return null;
		}
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'decisions' ) . ' WHERE dedupe_key = %s AND created_at >= %s ORDER BY id DESC LIMIT 1', $key, $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (self::t() with a literal); every value is a placeholder
		return $r ? self::shape_decision( $r ) : null;
	}

	public static function decisions( $days = 7, $tests = false, $limit = 300, $stage = 'stub' ) {
		$since = gmdate( 'Y-m-d H:i:s', time() - 86400 * max( 1, min( 90, (int) $days ) ) );
		$stage = 'install' === $stage ? 'install' : 'stub';
		$limit = max( 1, min( 1000, (int) $limit ) );
		if ( null !== self::$mem ) {
			$out = array();
			foreach ( array_reverse( self::$mem['decisions'] ) as $r ) {
				if ( $r['created_at'] >= $since && $r['stage'] === $stage && ( $tests || ! $r['is_test'] ) && count( $out ) < $limit ) {
					$out[] = self::shape_decision( $r );
				}
			}
			return $out;
		}
		global $wpdb;
		$sql  = 'SELECT * FROM ' . self::t( 'decisions' ) . ' WHERE created_at >= %s AND stage = %s' . ( $tests ? '' : ' AND is_test = 0' ) . ' ORDER BY id DESC LIMIT %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $since, $stage, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql holds the table name ($wpdb->prefix and a fixed name), fixed conditions and placeholders
		return array_map( array( __CLASS__, 'shape_decision' ), is_array( $rows ) ? $rows : array() );
	}

	public static function shape_decision( array $r ) {
		foreach ( array( 'candidates', 'trail', 'kind_notes' ) as $k ) {
			$v       = is_array( $r[ $k ] ?? null ) ? $r[ $k ] : json_decode( (string) ( $r[ $k ] ?? '' ), true );
			$r[ $k ] = is_array( $v ) ? $v : array();
		}
		foreach ( array( 'id', 'asker_id', 'is_test', 'rule_version', 'chosen_user_id', 'live_user_id', 'note_by', 'note_to', 'seller_user_id', 'confirmed_user_id', 'refer', 'review', 'refer_closed_by' ) as $k ) {
			$r[ $k ] = (int) ( $r[ $k ] ?? 0 );
		}
		foreach ( array( 'chosen_people', 'live_people', 'confirmed_people' ) as $k ) {
			$v       = $r[ $k ] ?? '';
			$r[ $k ] = is_array( $v ) ? array_map( 'intval', $v ) : ( '' === (string) $v ? array() : array_map( 'intval', explode( ',', (string) $v ) ) );
		}
		foreach ( array( 'note_reason', 'applied_doc', 'notice', 'estimate_number', 'written_initials', 'stub_key', 'reference', 'live_rule_id', 'answered_initials', 'chosen_initials', 'default_initials', 'label', 'zip', 'zip_from', 'kind_state', 'rule_id', 'team_roles', 'mode', 'dedupe_key', 'refer_closed_how' ) as $k ) {
			$r[ $k ] = (string) ( $r[ $k ] ?? '' );
		}
		$r['stage'] = 'install' === ( $r['stage'] ?? '' ) ? 'install' : 'stub';
		$r['kinds'] = is_array( $r['kinds'] ?? null ) ? $r['kinds'] : ( '' === (string) ( $r['kinds'] ?? '' ) ? array() : explode( ',', (string) $r['kinds'] ) );
		return $r;
	}

	public static function update_decision( $id, array $fields ) {
		if ( null !== self::$mem ) {
			if ( ! isset( self::$mem['decisions'][ (int) $id ] ) ) {
				return false;
			}
			self::$mem['decisions'][ (int) $id ] = array_merge( self::$mem['decisions'][ (int) $id ], $fields );
			return true;
		}
		global $wpdb;
		return false !== $wpdb->update( self::t( 'decisions' ), $fields, array( 'id' => (int) $id ) );
	}

	/* ── Referral cases (outside every service area) ──────────────────────────────────────────── */

	/** The decision rows a referral list reads: refer > 0 since a date, newest first. Null when the read fails. */
	private static function refer_rows( $since ) {
		if ( null !== self::$mem ) {
			$out = array();
			foreach ( array_reverse( self::$mem['decisions'] ) as $r ) {
				if ( (int) $r['refer'] > 0 && $r['created_at'] >= $since ) {
					$out[] = $r;
				}
			}
			return $out;
		}
		global $wpdb;
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, created_at, stage, is_test, zip, kinds, refer, stub_key, reference, estimate_number, default_initials, written_initials, seller_user_id, refer_closed_at, refer_closed_by, refer_closed_how FROM ' . self::t( 'decisions' ) . ' WHERE refer > 0 AND created_at >= %s ORDER BY id DESC LIMIT 2000', $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (self::t() with a literal); every value is a placeholder
		return ( is_array( $rows ) && '' === $wpdb->last_error ) ? $rows : null;
	}

	/** The newest decision for each (stage, key): "stage|key" => {stage, stub_key, refer, created_at}. Null on a failed read. */
	private static function newest_for( array $keys ) {
		$newest = array();
		if ( null !== self::$mem ) {
			foreach ( self::$mem['decisions'] as $r ) {
				$g = $r['stage'] . '|' . $r['stub_key'];
				if ( isset( $keys[ $g ] ) ) {
					$newest[ $g ] = $r; // ids ascend: the last one seen is the newest
				}
			}
			return $newest;
		}
		global $wpdb;
		foreach ( array_chunk( array_values( $keys ), 200 ) as $chunk ) {
			$where = implode( ' OR ', array_fill( 0, count( $chunk ), '(stage = %s AND stub_key = %s)' ) );
			$args  = array();
			foreach ( $chunk as $k ) {
				$args[] = $k[0];
				$args[] = $k[1];
			}
			$got = $wpdb->get_results( $wpdb->prepare( 'SELECT d.stage, d.stub_key, d.refer, d.created_at FROM ' . self::t( 'decisions' ) . ' d JOIN (SELECT stage, stub_key, MAX(id) AS id FROM ' . self::t( 'decisions' ) . " WHERE {$where} GROUP BY stage, stub_key) n ON n.id = d.id", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix and a fixed name; $where is one pair of placeholders per key
			if ( ! is_array( $got ) || '' !== $wpdb->last_error ) {
				return null;
			}
			foreach ( $got as $g ) {
				$newest[ $g['stage'] . '|' . $g['stub_key'] ] = $g;
			}
		}
		return $newest;
	}

	/**
	 * The referral cases: decisions marked refer, newest first, one per job (the same caller key at
	 * the same stage is one case; its id is the first decision's). The newest decision for the job
	 * decides: when it is no longer a referral (its ZIP corrected, the zones widened), the case is
	 * superseded. Null when the read fails.
	 */
	public static function referral_cases( $open_only = true, $days = self::REFER_DAYS ) {
		$since = gmdate( 'Y-m-d H:i:s', time() - 86400 * max( 1, min( 3650, (int) $days ) ) );
		$rows  = self::refer_rows( $since );
		if ( null === $rows ) {
			return null;
		}
		$rows = array_reverse( $rows );
		$keys = array();
		foreach ( $rows as $r ) {
			if ( '' !== (string) $r['stub_key'] ) {
				$keys[ $r['stage'] . '|' . $r['stub_key'] ] = array( (string) $r['stage'], (string) $r['stub_key'] );
			}
		}
		$newest = $keys ? self::newest_for( $keys ) : array();
		if ( null === $newest ) {
			return null;
		}
		$cases = array();
		foreach ( $rows as $r ) {
			$g = '' !== (string) $r['stub_key'] ? $r['stage'] . '|' . $r['stub_key'] : 'd' . $r['id'];
			if ( ! isset( $cases[ $g ] ) ) {
				$cases[ $g ] = array( 'id' => (int) $r['id'], 'ids' => array(), 'stage' => 'install' === $r['stage'] ? 'install' : 'stub', 'stub_key' => (string) $r['stub_key'],
					'estimate_number' => '', 'written_initials' => '', 'closed_at' => '', 'closed_by' => 0, 'closed_how' => '' );
			}
			$c                     = &$cases[ $g ];
			$c['ids'][]            = (int) $r['id'];
			$c['refer']            = (int) $r['refer'];
			$c['is_test']          = (int) $r['is_test'];
			$c['zip']              = (string) $r['zip'];
			$c['kinds']            = is_array( $r['kinds'] ) ? $r['kinds'] : ( '' === (string) $r['kinds'] ? array() : explode( ',', (string) $r['kinds'] ) );
			$c['created_at']       = (string) $r['created_at'];
			$c['reference']        = (string) $r['reference'];
			$c['default_initials'] = (string) $r['default_initials'];
			$c['seller_user_id']   = (int) $r['seller_user_id'];
			foreach ( array( 'estimate_number', 'written_initials' ) as $k ) {
				if ( '' !== (string) ( $r[ $k ] ?? '' ) ) {
					$c[ $k ] = (string) $r[ $k ];
				}
			}
			if ( ! empty( $r['refer_closed_at'] ) ) {
				$c['closed_at']  = (string) $r['refer_closed_at'];
				$c['closed_by']  = (int) $r['refer_closed_by'];
				$c['closed_how'] = (string) $r['refer_closed_how'];
			}
			unset( $c );
		}
		foreach ( $cases as $g => &$c ) {
			$n = $newest[ $g ] ?? null;
			if ( $n && 0 === (int) $n['refer'] && '' === $c['closed_at'] ) {
				$c['closed_at']  = (string) $n['created_at'];
				$c['closed_how'] = 'superseded';
			}
			// a stub the asking app has not named yet may be a parse nobody saved: shown, never emailed
			$c['saved'] = 'install' === $c['stage'] || ! empty( $c['is_test'] ) || '' !== $c['estimate_number'];
		}
		unset( $c );
		$out = array_values( $cases );
		if ( $open_only ) {
			$out = array_values( array_filter( $out, function ( $c ) { return '' === $c['closed_at']; } ) );
		}
		usort( $out, function ( $a, $b ) { return $b['id'] - $a['id']; } );
		return $out;
	}

	/** One case by its id (the first decision's), open or closed, or null. */
	public static function referral_case( $id ) {
		foreach ( (array) self::referral_cases( false, 3650 ) as $c ) {
			if ( (int) $id === $c['id'] ) {
				return $c;
			}
		}
		return null;
	}

	/** Close a case: every decision of it. $how: 'emailed' (opened from the referral list) or 'closed' (by an admin). */
	public static function close_referral( array $case, $by, $how ) {
		$ids = array_map( 'intval', (array) $case['ids'] );
		if ( ! $ids ) {
			return false;
		}
		$how = 'emailed' === $how ? 'emailed' : 'closed';
		if ( null !== self::$mem ) {
			foreach ( $ids as $id ) {
				if ( isset( self::$mem['decisions'][ $id ] ) && empty( self::$mem['decisions'][ $id ]['refer_closed_at'] ) ) {
					self::$mem['decisions'][ $id ] = array_merge( self::$mem['decisions'][ $id ], array( 'refer_closed_at' => self::now(), 'refer_closed_by' => (int) $by, 'refer_closed_how' => $how ) );
				}
			}
			return true;
		}
		global $wpdb;
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$n  = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'decisions' ) . " SET refer_closed_at = %s, refer_closed_by = %d, refer_closed_how = %s WHERE refer_closed_at IS NULL AND id IN ({$in})", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix and a fixed name; $in is one %d placeholder per id
			array_merge( array( self::now(), (int) $by, $how ), $ids ) ) );
		return false !== $n;
	}

	public static function clear_tests( $by, $stage = null ) {
		if ( null !== self::$mem ) {
			$n = 0;
			foreach ( self::$mem['decisions'] as $id => $r ) {
				if ( $r['is_test'] && ( null === $stage || $r['stage'] === $stage ) ) {
					unset( self::$mem['decisions'][ $id ] );
					$n++;
				}
			}
		} else {
			global $wpdb;
			$n = (int) ( null === $stage
				? $wpdb->query( 'DELETE FROM ' . self::t( 'decisions' ) . ' WHERE is_test = 1' ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (self::t() with a literal); the query takes no values
				: $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t( 'decisions' ) . ' WHERE is_test = 1 AND stage = %s', 'install' === $stage ? 'install' : 'stub' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (self::t() with a literal); every value is a placeholder
		}
		self::log( $by, 0, 'tests', array( 'cleared' => $n ) );
		return $n;
	}

	/* ── History ──────────────────────────────────────────────────────────────────────────────── */

	public static function log( $by, $uid, $action, $detail ) {
		$row = array(
			'changed_at' => self::now(),
			'changed_by' => (int) $by,
			'user_id'    => (int) $uid,
			'action'     => substr( (string) $action, 0, 20 ),
			'detail'     => self::json( $detail ),
		);
		if ( null !== self::$mem ) {
			$row['id']               = ++self::$mem['hseq'];
			self::$mem['history'][] = $row;
			return;
		}
		global $wpdb;
		$wpdb->insert( self::t( 'history' ), $row );
	}

	/** History rows, newest first: { rows, more }. $action: only this kind of row ('' = all). */
	public static function history( $limit = 50, $before = 0, $action = '' ) {
		$limit = max( 1, min( 500, (int) $limit ) );
		if ( null !== self::$mem ) {
			$rows = array_values( array_filter( array_reverse( self::$mem['history'] ), function ( $r ) use ( $before, $action ) {
				return ( $before <= 0 || $r['id'] < $before ) && ( '' === $action || $r['action'] === $action );
			} ) );
			$rows = array_slice( $rows, 0, $limit + 1 );
		} else {
			global $wpdb;
			$where = array();
			if ( $before > 0 ) {
				$where[] = $wpdb->prepare( 'id < %d', (int) $before );
			}
			if ( '' !== $action ) {
				$where[] = $wpdb->prepare( 'action = %s', (string) $action );
			}
			$sql  = 'SELECT * FROM ' . self::t( 'history' ) . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' ) . ' ORDER BY id DESC LIMIT ' . ( $limit + 1 );
			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql holds the table name ($wpdb->prefix and a fixed name), conditions prepared above and an integer limit
			$rows = is_array( $rows ) ? $rows : array();
		}
		$more = count( $rows ) > $limit;
		$rows = array_slice( $rows, 0, $limit );
		foreach ( $rows as &$r ) {
			$d               = json_decode( (string) $r['detail'], true );
			$r['detail']     = is_array( $d ) ? $d : array();
			$r['id']         = (int) $r['id'];
			$r['changed_by'] = (int) $r['changed_by'];
			$r['user_id']    = (int) $r['user_id'];
		}
		unset( $r );
		return array( 'rows' => $rows, 'more' => $more );
	}

	public static function stats() {
		if ( null !== self::$mem ) {
			$z = 0;
			foreach ( self::$mem['zips'] as $l ) {
				$z += count( $l );
			}
			return array( 'zips' => $z, 'people' => count( array_filter( self::$mem['zips'] ) ), 'decisions' => count( self::$mem['decisions'] ), 'tests' => 0, 'install' => 0 );
		}
		global $wpdb;
		return array(
			'zips'      => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t( 'zips' ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (self::t() with a literal); the query takes no values
			'people'    => (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT user_id) FROM ' . self::t( 'zips' ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (self::t() with a literal); the query takes no values
			'decisions' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t( 'decisions' ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (self::t() with a literal); the query takes no values
			'tests'     => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t( 'decisions' ) . ' WHERE is_test = 1' ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (self::t() with a literal); the query takes no values
			'install'   => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t( 'decisions' ) . " WHERE stage = 'install'" ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix and a fixed name (self::t() with a literal); the query takes no values
		);
	}
}
