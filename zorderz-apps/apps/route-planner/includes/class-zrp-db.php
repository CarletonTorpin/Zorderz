<?php
/**
 * ZRP_DB -- the survey's own tables.
 *
 *   wp_zrp_drives     one survey drive: who, when, how far, and a thinned track (one point per ~25 m, at most
 *                      MAX_TRACK) so the map can show which streets were driven. The person's own; the admin tier's.
 *   wp_zrp_notes      one spoken (or typed) note: where and when it was said, the words as kept (ZRP_Vocab::scrub),
 *                      the scope said, and the street segment it was snapped to on the server (ZRP_Snap). Status
 *                      pending -> confirmed | discarded. A house number only when the scope is "this house".
 *                      source live | typed | audio (read from a recording by the transcription service); status
 *                      'retracted' -- words a "scratch that" took back, kept 30 days so the person can Undo (never
 *                      counted); why (what took them back); retract_of (the note they were cut from).
 *   wp_zrp_note_tags  the tags a PERSON confirmed for a note -- the only thing other apps count, through
 *                      routeplanner.street_tags. Suggested tags live on the note until then and are never counted.
 *
 * Nothing here holds a name, a plate or a face: notes describe homes, never people.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZRP_DB {

	const MAX_TRACK = 1500;

	public static function t_drives() { global $wpdb; return $wpdb->prefix . 'zrp_drives'; }
	public static function t_notes() { global $wpdb; return $wpdb->prefix . 'zrp_notes'; }
	public static function t_tags() { global $wpdb; return $wpdb->prefix . 'zrp_note_tags'; }

	public static function create_tables() {
		global $wpdb;
		$c = $wpdb->get_charset_collate();
		if ( ! function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( 'CREATE TABLE ' . self::t_drives() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			started_at datetime NOT NULL,
			ended_at datetime NULL,
			status varchar(10) NOT NULL DEFAULT 'running',
			distance_m int NOT NULL DEFAULT 0,
			note_count int NOT NULL DEFAULT 0,
			track_json longtext NULL,
			PRIMARY KEY  (id),
			KEY user_started (user_id, started_at)
		) $c;" );
		dbDelta( 'CREATE TABLE ' . self::t_notes() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			drive_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			client_id varchar(40) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			said_at datetime NULL,
			lat decimal(10,7) NULL,
			lng decimal(10,7) NULL,
			acc_m int NULL,
			speed_mps decimal(6,2) NULL,
			heading int NULL,
			said varchar(500) NOT NULL DEFAULT '',
			scope varchar(14) NOT NULL DEFAULT 'here',
			side varchar(1) NOT NULL DEFAULT '',
			street varchar(120) NOT NULL DEFAULT '',
			street_key varchar(120) NOT NULL DEFAULT '',
			zip varchar(5) NOT NULL DEFAULT '',
			city varchar(60) NOT NULL DEFAULT '',
			seg_id bigint(20) NULL,
			house_number varchar(20) NOT NULL DEFAULT '',
			snapped tinyint(1) NOT NULL DEFAULT 0,
			suggested varchar(400) NOT NULL DEFAULT '',
			status varchar(10) NOT NULL DEFAULT 'pending',
			confirmed_by bigint(20) unsigned NULL,
			confirmed_at datetime NULL,
			source varchar(6) NOT NULL DEFAULT 'live',
			audio_id bigint(20) unsigned NULL,
			retract_of bigint(20) unsigned NULL,
			why varchar(120) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY user_status (user_id, status),
			KEY audio (audio_id),
			KEY street (street_key, zip),
			KEY seg (seg_id),
			KEY drive (drive_id),
			UNIQUE KEY user_client (user_id, client_id)
		) $c;" );
		dbDelta( 'CREATE TABLE ' . self::t_tags() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			note_id bigint(20) unsigned NOT NULL DEFAULT 0,
			tag varchar(40) NOT NULL DEFAULT '',
			product varchar(40) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY note (note_id),
			KEY tag (tag)
		) $c;" );
		ZRP_Audio::create_tables();
		ZRP_Codes::create_tables();
		ZRP_Plan::create_tables(); // who sent which planned route to Doorhangers
	}

	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/* ------------------------------------------------------------------ drives */

	public static function start_drive( $uid ) {
		global $wpdb;
		$ok = $wpdb->insert( self::t_drives(), array( 'user_id' => (int) $uid, 'started_at' => self::now(), 'status' => 'running', 'track_json' => '[]' ) );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function get_drive( $id ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t_drives() . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_drives() ($wpdb->prefix and a fixed name); values are placeholders
		return is_array( $r ) ? $r : null;
	}

	/** Thin a track to one point per $step metres, keeping the first and last; [lat, lng, unix seconds]. */
	public static function thin( array $pts, $step = 25.0 ) {
		$out  = array();
		$last = null;
		$n    = count( $pts );
		foreach ( $pts as $i => $p ) {
			if ( ! is_array( $p ) || count( $p ) < 2 || ! is_numeric( $p[0] ) || ! is_numeric( $p[1] ) ) {
				continue;
			}
			$q = array( round( (float) $p[0], 6 ), round( (float) $p[1], 6 ), isset( $p[2] ) && is_numeric( $p[2] ) ? (int) $p[2] : 0 );
			if ( abs( $q[0] ) > 90 || abs( $q[1] ) > 180 ) {
				continue;
			}
			if ( null === $last || $i === $n - 1 || self::dist_m( $last[0], $last[1], $q[0], $q[1] ) >= $step ) {
				$out[] = $q;
				$last  = $q;
			}
		}
		return $out;
	}

	/** Add points to a running drive (the phone sends them in batches); distance grows by what was added. */
	public static function add_track( $id, array $pts ) {
		global $wpdb;
		$d = self::get_drive( $id );
		if ( ! $d ) {
			return false;
		}
		$have = json_decode( (string) $d['track_json'], true );
		$have = is_array( $have ) ? $have : array();
		$new  = self::thin( $pts );
		if ( ! $new ) {
			return true;
		}
		$add = 0.0;
		$prev = $have ? end( $have ) : null;
		foreach ( $new as $q ) {
			if ( $prev ) {
				$step = self::dist_m( $prev[0], $prev[1], $q[0], $q[1] );
				if ( $step < 2000 ) { $add += $step; } // a GPS jump is not distance driven
			}
			$prev = $q;
		}
		$all = array_merge( $have, $new );
		if ( count( $all ) > self::MAX_TRACK ) {
			$all = self::thin( $all, 60.0 );
			$all = array_slice( $all, -self::MAX_TRACK );
		}
		return false !== $wpdb->update( self::t_drives(), array( 'track_json' => wp_json_encode( $all ), 'distance_m' => (int) $d['distance_m'] + (int) round( $add ) ), array( 'id' => (int) $id ) );
	}

	public static function end_drive( $id ) {
		global $wpdb;
		$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t_notes() . " WHERE drive_id = %d AND status NOT IN ('discarded','retracted')", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_notes() ($wpdb->prefix and a fixed name); values are placeholders
		return false !== $wpdb->update( self::t_drives(), array( 'status' => 'done', 'ended_at' => self::now(), 'note_count' => $n ), array( 'id' => (int) $id ) );
	}

	/** a drive's note count again, after a recording's notes arrive (an ended drive stays ended). */
	public static function end_drive_count( $id ) {
		global $wpdb;
		$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t_notes() . " WHERE drive_id = %d AND status NOT IN ('discarded','retracted')", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_notes() ($wpdb->prefix and a fixed name); values are placeholders
		return false !== $wpdb->update( self::t_drives(), array( 'note_count' => $n ), array( 'id' => (int) $id ) );
	}

	/** A person's drives, newest first (the admin tier: everyone's). Tracks included only when asked for one drive. */
	public static function drives( $uid, $all = false, $limit = 30 ) {
		global $wpdb;
		$sql = 'SELECT id, user_id, started_at, ended_at, status, distance_m, note_count FROM ' . self::t_drives();
		$sql .= $all ? '' : $wpdb->prepare( ' WHERE user_id = %d', (int) $uid );
		$sql .= ' ORDER BY id DESC LIMIT ' . max( 1, min( 200, (int) $limit ) );
		return (array) $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is fixed SQL on self::t_drives(), a prepare()d user_id clause and an int-cast limit
	}

	/* ------------------------------------------------------------------ notes */

	/** Insert a note; the same (user, client_id) twice returns the first one's id (the phone re-sends after no signal). */
	public static function add_note( array $n ) {
		global $wpdb;
		$cid = (string) ( $n['client_id'] ?? '' );
		if ( '' !== $cid ) {
			$have = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t_notes() . ' WHERE user_id = %d AND client_id = %s', (int) $n['user_id'], $cid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_notes() ($wpdb->prefix and a fixed name); values are placeholders
			if ( $have ) {
				return array( $have, false );
			}
		}
		if ( '' === $cid ) {
			$cid = 's' . substr( md5( uniqid( '', true ) . wp_rand() ), 0, 20 ); // every note has one: the UNIQUE key holds
		}
		$row = array_merge( array( 'created_at' => self::now(), 'status' => 'pending' ), $n, array( 'client_id' => $cid ) );
		$ok  = $wpdb->insert( self::t_notes(), $row );
		if ( ! $ok ) {
			// two copies of one note at once: the UNIQUE (user, client_id) key refused the second; hand back the first
			$have = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t_notes() . ' WHERE user_id = %d AND client_id = %s', (int) $n['user_id'], $cid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_notes() ($wpdb->prefix and a fixed name); values are placeholders
			return $have ? array( $have, false ) : array( 0, false );
		}
		return array( (int) $wpdb->insert_id, true );
	}

	public static function get_note( $id ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t_notes() . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_notes() ($wpdb->prefix and a fixed name); values are placeholders
		return is_array( $r ) ? $r : null;
	}

	public static function tags_of( $note_id ) {
		global $wpdb;
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT tag FROM ' . self::t_tags() . ' WHERE note_id = %d ORDER BY tag', (int) $note_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_tags() ($wpdb->prefix and a fixed name); values are placeholders
	}

	/** Confirm with these tags (already checked against the vocabulary), or discard. */
	public static function set_status( $id, $status, $by, array $tags = array(), array $vocab = array(), $fields = array() ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t_tags() . ' WHERE note_id = %d', (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_tags() ($wpdb->prefix and a fixed name); values are placeholders
		if ( 'confirmed' === $status ) {
			foreach ( $tags as $t ) {
				$wpdb->insert( self::t_tags(), array( 'note_id' => (int) $id, 'tag' => $t, 'product' => (string) ( $vocab[ $t ]['product'] ?? '' ) ) );
			}
		}
		$upd = array_merge( $fields, array( 'status' => $status, 'confirmed_by' => (int) $by, 'confirmed_at' => self::now() ) );
		return false !== $wpdb->update( self::t_notes(), $upd, array( 'id' => (int) $id ) );
	}

	/** Notes for the Review list and the map. $where: user (int|null), status (string[]), box [xmin,ymin,xmax,ymax], drive. */
	public static function notes( array $where, $limit = 300 ) {
		global $wpdb;
		$w = array( '1=1' );
		if ( isset( $where['user'] ) ) {
			$w[] = $wpdb->prepare( 'user_id = %d', (int) $where['user'] );
		}
		if ( ! empty( $where['drive'] ) ) {
			$w[] = $wpdb->prepare( 'drive_id = %d', (int) $where['drive'] );
		}
		if ( ! empty( $where['since'] ) ) {
			$w[] = $wpdb->prepare( 'created_at >= %s', (string) $where['since'] );
		}
		if ( ! empty( $where['status'] ) ) {
			$st = array_values( array_intersect( (array) $where['status'], array( 'pending', 'confirmed', 'discarded', 'retracted' ) ) );
			$w[] = $st ? "status IN ('" . implode( "','", $st ) . "')" : '1=0';
		}
		if ( ! empty( $where['box'] ) && 4 === count( $where['box'] ) ) {
			list( $x0, $y0, $x1, $y1 ) = array_map( 'floatval', $where['box'] );
			$w[] = $wpdb->prepare( 'lat BETWEEN %f AND %f AND lng BETWEEN %f AND %f', $y0, $y1, $x0, $x1 );
		}
		$rows = (array) $wpdb->get_results( 'SELECT * FROM ' . self::t_notes() . ' WHERE ' . implode( ' AND ', $w ) . ' ORDER BY id DESC LIMIT ' . max( 1, min( 2000, (int) $limit ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_notes() ($wpdb->prefix and a fixed name); every condition in $w is prepare()d or an allow-listed status; the limit is cast to int
		if ( $rows ) {
			$ids  = array_map( 'intval', array_column( $rows, 'id' ) );
			$tags = (array) $wpdb->get_results( 'SELECT note_id, tag FROM ' . self::t_tags() . ' WHERE note_id IN (' . implode( ',', $ids ) . ')', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_tags() ($wpdb->prefix and a fixed name); $ids is a list of ints (array_map intval)
			$by   = array();
			foreach ( $tags as $t ) { $by[ (int) $t['note_id'] ][] = (string) $t['tag']; }
			foreach ( $rows as &$r ) { $r['tags'] = $by[ (int) $r['id'] ] ?? array(); }
			unset( $r );
		}
		return $rows;
	}

	/**
	 * Confirmed notes with their tags on these streets ([street_key, zip] pairs) or inside a box:
	 * {notes: [{id, street, street_key, zip, city, seg_id, scope, side, said, created_at, tags[]}], truncated}, or null on a
	 * failed read. The limit counts NOTES.
	 */
	public static function confirmed_on( array $streets = array(), $box = null, $limit = 2000 ) {
		global $wpdb;
		$w = array( "n.status = 'confirmed'", 'EXISTS (SELECT 1 FROM ' . self::t_tags() . ' t0 WHERE t0.note_id = n.id)' );
		if ( $streets ) {
			$or = array();
			foreach ( $streets as $s ) {
				$or[] = '' !== $s[1] ? $wpdb->prepare( '(n.street_key = %s AND n.zip = %s)', $s[0], $s[1] ) : $wpdb->prepare( '(n.street_key = %s)', $s[0] );
			}
			$w[] = '(' . implode( ' OR ', $or ) . ')';
		}
		if ( is_array( $box ) && 4 === count( $box ) ) {
			list( $x0, $y0, $x1, $y1 ) = array_map( 'floatval', $box );
			$w[] = $wpdb->prepare( 'n.lat BETWEEN %f AND %f AND n.lng BETWEEN %f AND %f', $y0, $y1, $x0, $x1 );
		}
		$limit = max( 1, min( 5000, (int) $limit ) );
		if ( property_exists( $wpdb, 'last_error' ) ) {
			$wpdb->last_error = '';
		}
		$ids = $wpdb->get_col( 'SELECT n.id FROM ' . self::t_notes() . ' n WHERE ' . implode( ' AND ', $w ) . ' ORDER BY n.id DESC LIMIT ' . ( $limit + 1 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::t_notes() ($wpdb->prefix and a fixed name); every condition in $w is fixed or prepare()d; the limit is cast to int
		// WordPress answers a failed query with an empty list and sets last_error: a failure, never "nothing noted"
		if ( ! is_array( $ids ) || ! empty( $wpdb->last_error ) ) {
			return null;
		}
		$truncated = count( $ids ) > $limit;
		$ids       = array_map( 'intval', array_slice( $ids, 0, $limit ) );
		if ( ! $ids ) {
			return array( 'notes' => array(), 'truncated' => false );
		}
		$rows = $wpdb->get_results( 'SELECT n.id, n.street, n.street_key, n.zip, n.city, n.seg_id, n.scope, n.side, n.said, n.created_at, n.lat, n.lng, t.tag FROM ' . self::t_notes() . ' n INNER JOIN ' . self::t_tags() . ' t ON t.note_id = n.id WHERE n.id IN (' . implode( ',', $ids ) . ') ORDER BY n.id DESC, t.tag', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names from self::t_notes() and self::t_tags() (each $wpdb->prefix and a fixed name); $ids is a list of ints (array_map intval)
		if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) {
			return null;
		}
		$by = array();
		foreach ( $rows as $r ) {
			$id = (int) $r['id'];
			if ( ! isset( $by[ $id ] ) ) {
				$by[ $id ] = $r + array( 'tags' => array() );
				unset( $by[ $id ]['tag'] );
			}
			$by[ $id ]['tags'][] = (string) $r['tag'];
		}
		return array( 'notes' => array_values( $by ), 'truncated' => $truncated );
	}

	public static function dist_m( $lat1, $lng1, $lat2, $lng2 ) {
		$x = ( $lng2 - $lng1 ) * cos( deg2rad( ( $lat1 + $lat2 ) / 2 ) ) * 111320;
		$y = ( $lat2 - $lat1 ) * 110540;
		return sqrt( $x * $x + $y * $y );
	}
}
