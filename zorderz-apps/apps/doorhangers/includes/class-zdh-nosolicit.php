<?php
/**
 * ZDH_NoSolicit -- houses with a "No Soliciting" sign.
 *
 * A walker who finds the sign presses "No-Solicit House" (pocket mode, or the walk view) and the app records where they
 * stood. The server works out which house that was -- the planned house they are nearest to, else the address source's
 * nearest address point -- and keeps it in its own table, by the same match_key the drops use (number + street
 * [+ unit]). From then on:
 *   - that house is NOT delivered on this walk, whatever the GPS auto-tick thought (the address row is set
 *     delivered = 0, and a finish that "marks the rest delivered" leaves it alone);
 *   - it is never stored on a route again: a planned route, a drawn route, a Quick Start walk and a route sent from
 *     the Route Planner all leave it out (without(), applied in ZDH_DB::insert_addresses and ZDH_Plan);
 *   - where a map draws the area's houses it carries no_solicit = 1, so it is drawn red and nothing ticks it;
 *   - Lookup says "No Soliciting sign noted <date>".
 * This is a safety floor: a business may add houses to the list (filter `zdh_nosolicit_keys`), never take one off it
 * in code. The record is the house, never the person: who pressed is kept for the log line only.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDH_NoSolicit {

	const SNAP_M = 40; // the house the walker is at: the nearest within this many metres of the fix

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'zdh_nosolicit';
	}

	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE $t (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			drop_id bigint(20) unsigned NOT NULL DEFAULT 0,
			address_id bigint(20) unsigned NOT NULL DEFAULT 0,
			match_key varchar(191) NOT NULL DEFAULT '',
			full_address varchar(255) NOT NULL DEFAULT '',
			zip varchar(10) NOT NULL DEFAULT '',
			apn varchar(20) NOT NULL DEFAULT '',
			lat decimal(10,7) NULL,
			lng decimal(10,7) NULL,
			fix_lat decimal(10,7) NULL,
			fix_lng decimal(10,7) NULL,
			fix_acc int NULL,
			resolved varchar(12) NOT NULL DEFAULT 'none',
			PRIMARY KEY  (id),
			KEY match_key (match_key),
			KEY drop_id (drop_id)
		) " . $wpdb->get_charset_collate() . ';' );
	}

	/**
	 * Record a sign where the walker stands. $args: lat, lng, acc, drop_id, address_id, uid.
	 * Returns ['id','full_address','resolved','match_key','address_id','already'] or WP_Error.
	 */
	public static function add( $args ) {
		global $wpdb;
		$lat = isset( $args['lat'] ) && is_numeric( $args['lat'] ) ? (float) $args['lat'] : null;
		$lng = isset( $args['lng'] ) && is_numeric( $args['lng'] ) ? (float) $args['lng'] : null;
		$acc = isset( $args['acc'] ) && is_numeric( $args['acc'] ) ? (int) $args['acc'] : null;
		$did = isset( $args['drop_id'] ) ? (int) $args['drop_id'] : 0;
		$aid = isset( $args['address_id'] ) ? (int) $args['address_id'] : 0;
		$uid = isset( $args['uid'] ) ? (int) $args['uid'] : 0;
		if ( ( null === $lat || null === $lng ) && ! $aid ) {
			return new WP_Error( 'no_fix', 'No GPS position and no house to mark.', array( 'status' => 400 ) );
		}
		$house = null; $resolved = 'none';
		// 1. the house the walker says (a row of this drop)
		if ( $aid ) {
			$house = $wpdb->get_row( $wpdb->prepare( 'SELECT id, drop_id, house_number, unit, street, city, zip, full_address, match_key, lat, lng, apn FROM ' . ZDH_DB::t_addr() . ' WHERE id = %d' . ( $did ? ' AND drop_id = %d' : '' ), $did ? array( $aid, $did ) : array( $aid ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from ZDH_DB::t_addr() ($wpdb->prefix and a fixed name); the optional drop clause adds a placeholder and both ids are bound
			if ( $house ) { $resolved = 'house'; }
		}
		// 2. the nearest planned house of this drop
		if ( ! $house && $did && null !== $lat ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, drop_id, house_number, unit, street, city, zip, full_address, match_key, lat, lng, apn FROM ' . ZDH_DB::t_addr() . ' WHERE drop_id = %d AND lat IS NOT NULL', $did ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from ZDH_DB::t_addr() ($wpdb->prefix and a fixed name); values are placeholders
			$best = null; $bd = self::SNAP_M + 1;
			foreach ( (array) $rows as $r ) {
				$d = self::dist_m( $lat, $lng, (float) $r['lat'], (float) $r['lng'] );
				if ( $d < $bd ) { $bd = $d; $best = $r; }
			}
			if ( $best ) { $house = $best; $resolved = 'nearest'; }
		}
		// 3. the address source's nearest address point (a Quick Start walk has no planned houses)
		if ( ! $house && null !== $lat && class_exists( 'ZDH_Geo' ) ) {
			$pt  = array( round( $lat, 7 ), round( $lng, 7 ) );
			$res = ZDH_Geo::find_in_area( array( array( 'type' => 'path', 'points' => array( $pt, array( $pt[0] + 0.00001, $pt[1] ) ), 'buffer_m' => self::SNAP_M ) ) );
			if ( ! is_wp_error( $res ) && ! empty( $res['addresses'] ) ) {
				$best = null; $bd = self::SNAP_M + 1;
				foreach ( $res['addresses'] as $a ) {
					if ( ! isset( $a['lat'], $a['lng'] ) || null === $a['lat'] ) { continue; }
					$d = self::dist_m( $lat, $lng, (float) $a['lat'], (float) $a['lng'] );
					if ( $d < $bd ) { $bd = $d; $best = $a; }
				}
				if ( $best ) {
					$house = array(
						'id' => 0, 'drop_id' => 0, 'house_number' => $best['number'], 'unit' => $best['unit'] ?? '', 'street' => $best['street'],
						'city' => $best['city'] ?? '', 'zip' => $best['zip'] ?? '', 'apn' => $best['apn'] ?? '', 'lat' => $best['lat'], 'lng' => $best['lng'],
						'match_key' => ZDH_DB::match_key( $best['number'], $best['street'], $best['unit'] ?? '' ),
						'full_address' => trim( $best['number'] . ( ! empty( $best['unit'] ) ? ' #' . $best['unit'] : '' ) . ' ' . $best['street'] . ( ! empty( $best['city'] ) ? ', ' . $best['city'] : '' ) ),
					);
					$resolved = 'map';
				}
			}
		}
		$key = $house ? (string) $house['match_key'] : '';
		if ( $house && $key === '' ) { $key = ZDH_DB::match_key( $house['house_number'], $house['street'], $house['unit'] ); }
		$already = $key !== '' ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE match_key = %s LIMIT 1', $key ) ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::table() ($wpdb->prefix and a fixed name); values are placeholders
		$id = $already;
		if ( ! $already ) {
			$wpdb->insert( self::table(), array(
				'created_at'   => current_time( 'mysql' ),
				'created_by'   => $uid,
				'drop_id'      => $house ? (int) $house['drop_id'] : $did,
				'address_id'   => $house ? (int) $house['id'] : 0,
				'match_key'    => $key,
				'full_address' => $house ? (string) $house['full_address'] : '',
				'zip'          => $house ? (string) $house['zip'] : '',
				'apn'          => $house ? (string) $house['apn'] : '',
				'lat'          => $house && null !== $house['lat'] ? (float) $house['lat'] : $lat,
				'lng'          => $house && null !== $house['lng'] ? (float) $house['lng'] : $lng,
				'fix_lat'      => $lat,
				'fix_lng'      => $lng,
				'fix_acc'      => $acc,
				'resolved'     => $resolved,
			) );
			$id = (int) $wpdb->insert_id;
			self::$cache = null; // the per-request copy of the keys is stale now
		}
		// the house is not delivered on this walk, whatever the auto-tick did
		if ( $house && ! empty( $house['id'] ) ) {
			$wpdb->update( ZDH_DB::t_addr(), array( 'delivered' => 0, 'delivered_at' => null ), array( 'id' => (int) $house['id'] ) );
		} elseif ( $did && $key !== '' ) {
			$wpdb->update( ZDH_DB::t_addr(), array( 'delivered' => 0, 'delivered_at' => null ), array( 'drop_id' => $did, 'match_key' => $key ) );
		}
		return array(
			'id'           => $id,
			'already'      => (bool) $already,
			'resolved'     => $resolved,
			'match_key'    => $key,
			'address_id'   => $house ? (int) $house['id'] : 0,
			'full_address' => $house ? (string) $house['full_address'] : '',
		);
	}

	/**
	 * Every recorded key => row (the table is small: one row per sign ever seen), plus any key a site adds through
	 * `zdh_nosolicit_keys` (a list it keeps elsewhere). A filter can only add: a recorded sign is never dropped here.
	 */
	private static $cache = null;
	public static function keys() {
		global $wpdb;
		if ( null !== self::$cache ) { return self::$cache; }
		$cache = array();
		$rows  = $wpdb->get_results( 'SELECT match_key, full_address, created_at, lat, lng FROM ' . self::table(), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from self::table() ($wpdb->prefix and a fixed name); the query takes no values
		foreach ( (array) $rows as $r ) {
			if ( $r['match_key'] !== '' ) { $cache[ $r['match_key'] ] = $r; }
		}
		self::$cache = self::widen( $cache, function_exists( 'apply_filters' ) ? apply_filters( 'zdh_nosolicit_keys', array() ) : array() );
		return self::$cache;
	}

	/** Recorded keys plus added ones (match keys, or rows with a match_key). Pure; never removes a recorded key. */
	public static function widen( array $recorded, $added ) {
		$out = $recorded;
		foreach ( (array) $added as $k => $v ) {
			$key = is_array( $v ) ? (string) ( $v['match_key'] ?? '' ) : ( is_string( $v ) ? $v : ( is_string( $k ) ? $k : '' ) );
			$key = strtolower( trim( $key ) );
			if ( '' !== $key && ! isset( $out[ $key ] ) ) {
				$out[ $key ] = array( 'match_key' => $key, 'full_address' => is_array( $v ) ? (string) ( $v['full_address'] ?? '' ) : '', 'created_at' => is_array( $v ) ? (string) ( $v['created_at'] ?? '' ) : '', 'lat' => null, 'lng' => null );
			}
		}
		return $out;
	}

	/** The match key of a house in any of the app's shapes (a stored row, an address-source row, a planner house). */
	public static function key_of( array $a ) {
		if ( isset( $a['match_key'] ) && '' !== (string) $a['match_key'] ) {
			return (string) $a['match_key'];
		}
		return ZDH_DB::match_key( $a['house_number'] ?? ( $a['number'] ?? '' ), $a['street'] ?? '', $a['unit'] ?? '' );
	}

	/**
	 * The safety floor, pure: $list less every house whose key is in $keys (key => anything). Returns
	 * [ kept list (re-indexed), how many were left out ].
	 */
	public static function without( array $list, array $keys ) {
		$kept = array();
		$out  = 0;
		foreach ( $list as $a ) {
			if ( is_array( $a ) && isset( $keys[ self::key_of( $a ) ] ) ) {
				$out++;
				continue;
			}
			$kept[] = $a;
		}
		return array( $kept, $out );
	}

	/**
	 * Tag a list of houses: each gets no_solicit = 1|0. A house is matched by its match_key, or by (number, street, unit)
	 * when the list is the address source's (a route being planned, a Quick Start walk). $sel_field: a field to clear on
	 * a tagged house ('selected'), so a route never starts with it ticked.
	 */
	public static function tag( array $list, $sel_field = '' ) {
		$keys = self::keys();
		foreach ( $list as &$a ) {
			$a['no_solicit'] = isset( $keys[ self::key_of( $a ) ] ) ? 1 : 0;
			if ( $a['no_solicit'] && $sel_field !== '' ) { $a[ $sel_field ] = false; }
		}
		unset( $a );
		return $list;
	}

	/** After a "mark the rest delivered": a no-solicit house of this drop goes back to not delivered. */
	public static function undeliver_in_drop( $drop_id ) {
		global $wpdb;
		$keys = array_keys( self::keys() );
		if ( ! $keys ) { return 0; }
		$in = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		return (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . ZDH_DB::t_addr() . " SET delivered = 0, delivered_at = NULL WHERE drop_id = %d AND delivered = 1 AND match_key IN ($in)", array_merge( array( (int) $drop_id ), $keys ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from ZDH_DB::t_addr() ($wpdb->prefix and a fixed name); $in is one %s placeholder per key and every key is bound
	}

	/** For Lookup: the sign on this house, if any. */
	public static function for_key( $key ) {
		$keys = self::keys();
		return isset( $keys[ $key ] ) ? array( 'noted' => substr( (string) $keys[ $key ]['created_at'], 0, 10 ), 'address' => (string) $keys[ $key ]['full_address'] ) : null;
	}

	public static function dist_m( $lat1, $lng1, $lat2, $lng2 ) {
		$r = 6371000; $p1 = deg2rad( $lat1 ); $p2 = deg2rad( $lat2 ); $dp = deg2rad( $lat2 - $lat1 ); $dl = deg2rad( $lng2 - $lng1 );
		$a = sin( $dp / 2 ) ** 2 + cos( $p1 ) * cos( $p2 ) * sin( $dl / 2 ) ** 2;
		return 2 * $r * atan2( sqrt( $a ), sqrt( 1 - $a ) );
	}
}
