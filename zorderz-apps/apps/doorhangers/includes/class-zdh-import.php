<?php
/**
 * ZDH_Import -- a Doorhangers full backup (JSON, from Export -> Full backup) into the app's tables.
 *
 * The backup carries the three tables' rows (drops / addresses / streets, the same columns, the same street_key rule),
 * so an import is a column-by-column copy with the drop ids remapped. Rows are marked source_app = 'import:backup';
 * the deliverer stays the text the backup stored (no person on this site); attachment ids from the other site are
 * dropped (they point at media that is not here).
 *
 * The app's floors hold for imported rows too: codes in a drop's notes or description are sealed (or, without the
 * vault, cut) exactly as for a new route, and a house with a No Soliciting sign noted here is not imported onto a
 * route still to be walked.
 *
 * Idempotent: a drop that already came in with the same date, time, streets summary, house count, method and start
 * time is skipped, so the same file can be imported twice without doubling the log. `replace` empties the three
 * tables first (an administrator's deliberate choice; the admin page arms the button before it will send it).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDH_Import {

	const SOURCE = 'import:backup';

	/** @return array|WP_Error {drops, addresses, streets, skipped} */
	public static function from_json( $json, $replace = false ) {
		global $wpdb;
		if ( strlen( (string) $json ) > 64 * 1024 * 1024 ) {
			return new WP_Error( 'too_big', 'That backup is larger than 64 MB.' );
		}
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) || ! isset( $data['drops'] ) || ! is_array( $data['drops'] ) ) {
			return new WP_Error( 'bad_backup', __( 'That file is not a Doorhangers backup (no "drops" list in it).', 'zorderz' ) );
		}
		$drops     = $data['drops'];
		$addresses = isset( $data['addresses'] ) && is_array( $data['addresses'] ) ? $data['addresses'] : array();
		$streets   = isset( $data['streets'] ) && is_array( $data['streets'] ) ? $data['streets'] : array();

		$t_drops   = ZDH_DB::t_drops();
		$t_addr    = ZDH_DB::t_addr();
		$t_streets = ZDH_DB::t_streets();
		$cols      = array(
			'drops'     => self::columns( $t_drops ),
			'addresses' => self::columns( $t_addr ),
			'streets'   => self::columns( $t_streets ),
		);
		if ( ! $cols['drops'] ) {
			return new WP_Error( 'no_tables', __( 'The Doorhangers tables are missing. Deactivate and activate the Zorderz apps once.', 'zorderz' ) );
		}

		if ( $replace ) {
			$wpdb->query( "DELETE FROM `$t_streets`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM `$t_addr`" );    // phpcs:ignore
			$wpdb->query( "DELETE FROM `$t_drops`" );   // phpcs:ignore
			delete_metadata( 'post', 0, '_zdh_drop_id', '', true ); // no screenshot points at a drop that is gone
		}

		$by_drop_addr = array();
		foreach ( $addresses as $a ) {
			$by_drop_addr[ (int) ( $a['drop_id'] ?? 0 ) ][] = $a;
		}
		$by_drop_street = array();
		foreach ( $streets as $st ) {
			$by_drop_street[ (int) ( $st['drop_id'] ?? 0 ) ][] = $st;
		}

		$n_drops = 0; $n_addr = 0; $n_streets = 0; $skipped = 0;
		foreach ( $drops as $d ) {
			if ( ! is_array( $d ) || empty( $d['id'] ) ) {
				$skipped++;
				continue;
			}
			$old_id = (int) $d['id'];
			$row    = self::pick( $d, $cols['drops'], array( 'id', 'created_by', 'attachment_ids', 'delivered_by_uid', 'source_app' ) );
			$row['created_by']       = (int) get_current_user_id();
			$row['attachment_ids']   = '';
			$row['delivered_by_uid'] = 0;
			$row['source_app']       = self::SOURCE;
			if ( empty( $row['created_at'] ) ) {
				$row['created_at'] = current_time( 'mysql' );
			}
			$row['address_count']    = isset( $by_drop_addr[ $old_id ] ) ? count( $by_drop_addr[ $old_id ] ) : (int) ( $d['address_count'] ?? 0 );
			if ( self::already_in( $row ) ) {
				$skipped++;
				continue;
			}
			// the code floor: the words go in with every code cut, then the codes are sealed under the new id
			$words = array();
			if ( class_exists( 'ZDH_Sealed' ) ) {
				foreach ( array( 'notes', 'description' ) as $k ) {
					if ( isset( $row[ $k ] ) && is_string( $row[ $k ] ) && ZDH_Sealed::has_code( $row[ $k ] ) ) {
						$words[ $k ] = $row[ $k ];
						$row[ $k ]   = ZDH_Sealed::cut( $row[ $k ] );
					}
				}
			}
			if ( false === $wpdb->insert( $t_drops, $row ) ) {
				$skipped++;
				continue;
			}
			$new_id = (int) $wpdb->insert_id;
			$n_drops++;
			foreach ( $words as $w ) {
				ZDH_Sealed::seal( $w, $new_id );
			}
			$to_walk = in_array( (string) ( $row['status'] ?? '' ), array( 'planned', 'in_progress' ), true );
			$ns_keys = ( $to_walk && class_exists( 'ZDH_NoSolicit' ) ) ? ZDH_NoSolicit::keys() : array();
			foreach ( $by_drop_addr[ $old_id ] ?? array() as $a ) {
				if ( $ns_keys && isset( $ns_keys[ ZDH_NoSolicit::key_of( (array) $a ) ] ) ) {
					continue; // never onto a route still to be walked
				}
				$ar = self::pick( $a, $cols['addresses'], array( 'id', 'drop_id' ) );
				$ar['drop_id'] = $new_id;
				if ( false !== $wpdb->insert( $t_addr, $ar ) ) {
					$n_addr++;
				}
			}
			foreach ( $by_drop_street[ $old_id ] ?? array() as $st ) {
				$sr = self::pick( $st, $cols['streets'], array( 'id', 'drop_id' ) );
				$sr['drop_id'] = $new_id;
				if ( false !== $wpdb->insert( $t_streets, $sr ) ) {
					$n_streets++;
				}
			}
		}
		return array( 'drops' => $n_drops, 'addresses' => $n_addr, 'streets' => $n_streets, 'skipped' => $skipped, 'replaced' => (bool) $replace );
	}

	/** The columns a table has, so a backup from a newer or older version never breaks the insert. */
	private static function columns( $table ) {
		global $wpdb;
		$rows = $wpdb->get_col( "SHOW COLUMNS FROM `$table`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/** Only the columns the table has, minus the ones this import sets itself; strings cut to a sane length. */
	private static function pick( $src, $cols, $skip ) {
		$out = array();
		foreach ( $cols as $c ) {
			if ( in_array( $c, $skip, true ) || ! array_key_exists( $c, $src ) ) {
				continue;
			}
			$v = $src[ $c ];
			if ( is_array( $v ) ) {
				$v = wp_json_encode( $v );
			}
			$out[ $c ] = is_string( $v ) ? substr( $v, 0, 2000000 ) : $v;
		}
		return $out;
	}

	/** Already imported: the same date, time, summary, count, method and start. */
	private static function already_in( $row ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			'SELECT id FROM ' . ZDH_DB::t_drops() . ' WHERE source_app = %s AND COALESCE(drop_date, \'\') = %s AND COALESCE(drop_time, \'\') = %s AND streets_summary = %s AND address_count = %d AND method = %s AND COALESCE(started_at, \'\') = %s LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from ZDH_DB::t_drops() ($wpdb->prefix and a fixed name); values are placeholders bound below
			self::SOURCE,
			(string) ( $row['drop_date'] ?? '' ),
			(string) ( $row['drop_time'] ?? '' ),
			(string) ( $row['streets_summary'] ?? '' ),
			(int) ( $row['address_count'] ?? 0 ),
			(string) ( $row['method'] ?? '' ),
			(string) ( $row['started_at'] ?? '' )
		);
		return (bool) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the prepare()d statement above
	}
}
