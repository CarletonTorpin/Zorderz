<?php
/**
 * ZSCH_Availability — the free/busy model (wp_zsch_availability).
 *
 * The lightweight "who's around" layer, distinct from appointments. A user
 * paints blocks of time they are OPEN (available for a job) or BUSY (blocked).
 * Powers the team availability grid and the dictation flow ("mark me open
 * Mon–Wed"). Availability is intentionally NOT pushed to Outlook by default
 * (it's a coordination signal, not a calendar event) — though a future option
 * could create matching free/busy events.
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_Availability {

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'zsch_availability';
	}

	/**
	 * Get availability blocks for a set of users in a UTC window.
	 *
	 * @param int[]  $user_ids  empty = the whole team (everyone with access).
	 * @param string $start_utc 'Y-m-d H:i:s'
	 * @param string $end_utc
	 * @return array[] normalised blocks
	 */
	public static function query( array $user_ids, $start_utc, $end_utc ) {
		global $wpdb;
		$t = self::table();

		$where  = array( 'deleted_at IS NULL', 'start_utc < %s AND end_utc > %s' );
		$params = array( $end_utc, $start_utc );

		$user_ids = array_values( array_filter( array_map( 'intval', $user_ids ) ) );
		if ( $user_ids ) {
			$ph = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
			$where[] = "owner_user_id IN ($ph)";
			$params  = array_merge( $params, $user_ids );
		}

		$sql = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY start_utc ASC LIMIT 2000';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore
		return array_map( array( __CLASS__, 'shape_row' ), $rows ?: array() );
	}

	/**
	 * The team availability grid: for each team member, their open/busy blocks
	 * in the window. Returns a structure the UI renders as rows-per-person.
	 *
	 * @param string $start_utc
	 * @param string $end_utc
	 * @return array { members:[ {user_id, name, blocks:[…]} ] }
	 */
	public static function team_grid( $start_utc, $end_utc ) {
		$members = self::team_members();
		$ids     = wp_list_pluck( $members, 'user_id' );
		$blocks  = self::query( $ids, $start_utc, $end_utc );

		$by_user = array();
		foreach ( $blocks as $b ) {
			$by_user[ $b['owner_user_id'] ][] = $b;
		}

		// v1.7.0 (Connected Calendars Phase 1) — fold each member's EXTERNAL
		// conflict-calendar busy (Google/Microsoft) into their blocks as
		// read-only, busy-only entries (kind:'busy', source:'external'), so the
		// grid reflects outside commitments. These are personal data: excluded
		// entirely for the shared kiosk (a read-only viewer).
		if ( class_exists( 'ZSCH_Sync' ) && ! zsch_user_is_read_only() ) {
			$external = ZSCH_Sync::read_busy( $ids, $start_utc, $end_utc );
			foreach ( $external as $uid => $ext_blocks ) {
				foreach ( $ext_blocks as $eb ) {
					$eb['owner_user_id']  = (int) $uid;
					$by_user[ (int) $uid ][] = $eb;
				}
			}
		}

		$out = array();
		foreach ( $members as $m ) {
			$out[] = array(
				'user_id' => $m['user_id'],
				'name'    => $m['name'],
				'blocks'  => $by_user[ $m['user_id'] ] ?? array(),
			);
		}
		return array( 'members' => $out );
	}

	/**
	 * Create an availability block.
	 *
	 * @param int   $actor_id
	 * @param array $data { kind:'open'|'busy', start_local, end_local, time_zone,
	 *                      is_all_day, note, owner_id?, source? }
	 * @return array
	 */
	public static function create( $actor_id, array $data ) {
		global $wpdb;
		$actor_id = (int) $actor_id;
		if ( ! zsch_user_can_write( $actor_id ) ) {
			return array( 'success' => false, 'error' => 'Read-only account cannot set availability.' );
		}

		// You set your own availability; admins may set it for others.
		$owner_id = $actor_id;
		if ( ! empty( $data['owner_id'] ) && ZSCH_Appointments::viewer_is_admin( $actor_id ) ) {
			$owner_id = (int) $data['owner_id'];
		}

		$tz = self::sanitize_tz( $data['time_zone'] ?? ZSCH_Settings::default_tz() );
		$all_day = array_key_exists( 'is_all_day', $data ) ? ! empty( $data['is_all_day'] ) : true;

		$start_utc = self::local_to_utc( $data['start_local'] ?? '', $tz, $all_day, false );
		$end_utc   = self::local_to_utc( $data['end_local'] ?? '', $tz, $all_day, true );
		if ( '' === $start_utc || '' === $end_utc || strtotime( $end_utc ) <= strtotime( $start_utc ) ) {
			return array( 'success' => false, 'error' => 'Invalid date range.' );
		}

		// Resolve 'source' to a concrete value FIRST, then validate. (Earlier the
		// default lived only inside the in_array() check, so a request with no
		// 'source' param passed the raw NULL through to the NOT NULL column —
		// the "Column 'source' cannot be null" insert failure. Coalesce up front.)
		$source = $data['source'] ?? 'manual';
		if ( ! in_array( $source, array( 'manual', 'voice' ), true ) ) {
			$source = 'manual';
		}

		$row = array(
			'owner_user_id' => $owner_id,
			'kind'          => ( ( $data['kind'] ?? 'open' ) === 'busy' ) ? 'busy' : 'open',
			'start_utc'     => $start_utc,
			'end_utc'       => $end_utc,
			'is_all_day'    => $all_day ? 1 : 0,
			'time_zone'     => $tz,
			'note'          => sanitize_text_field( $data['note'] ?? '' ),
			'source'        => $source,
			'created_at'    => current_time( 'mysql', true ),
			'updated_at'    => current_time( 'mysql', true ),
		);

		if ( ! $wpdb->insert( self::table(), $row ) ) { // phpcs:ignore
			// Self-heal: a missing table is the usual cause on a file-overwrite
			// update. Create the schema once and retry before giving up.
			if ( function_exists( 'zsch_tables_exist' ) && ! zsch_tables_exist() ) {
				require_once ZSCH_PLUGIN_DIR . 'db/migrate-1.0.0.php';
				ZSCH_Migrate_1_0_0::run();
				if ( $wpdb->insert( self::table(), $row ) ) { // phpcs:ignore
					$id = (int) $wpdb->insert_id;
					return array( 'success' => true, 'id' => $id, 'block' => self::shape_row( self::get_raw( $id ) ) );
				}
			}
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'ZSCH_Availability insert failed: ' . $wpdb->last_error );
			}
			return array( 'success' => false, 'error' => 'Could not save availability. ' . ( $wpdb->last_error ? $wpdb->last_error : 'Please try again.' ) );
		}
		$id = (int) $wpdb->insert_id;
		return array( 'success' => true, 'id' => $id, 'block' => self::shape_row( self::get_raw( $id ) ) );
	}

	/**
	 * Delete an availability block (hard delete via soft flag).
	 */
	public static function delete( $actor_id, $id ) {
		global $wpdb;
		$id = (int) $id;
		$row = self::get_raw( $id );
		if ( ! $row ) {
			return array( 'success' => false, 'error' => 'Not found.' );
		}
		$is_admin = ZSCH_Appointments::viewer_is_admin( (int) $actor_id );
		if ( ! zsch_user_can_write( (int) $actor_id ) || ( ! $is_admin && (int) $row['owner_user_id'] !== (int) $actor_id ) ) {
			return array( 'success' => false, 'error' => 'Not allowed.' );
		}
		$wpdb->update( self::table(), array( 'deleted_at' => current_time( 'mysql', true ) ), array( 'id' => $id ) ); // phpcs:ignore
		return array( 'success' => true, 'id' => $id );
	}

	/**
	 * Bulk-set availability from parsed natural language (the dictation path).
	 * Accepts an array of {kind, start_local, end_local} segments already parsed
	 * by the caller (REST endpoint or the bot bridge).
	 *
	 * @param int   $actor_id
	 * @param array $segments
	 * @param array $common  shared fields (time_zone, note, source, owner_id?)
	 * @return array { success, created:int, blocks:[…] }
	 */
	public static function bulk_set( $actor_id, array $segments, array $common = array() ) {
		$created = array();
		foreach ( $segments as $seg ) {
			$res = self::create( $actor_id, array_merge( $common, $seg ) );
			if ( ! empty( $res['success'] ) ) {
				$created[] = $res['block'];
			}
		}
		return array( 'success' => true, 'created' => count( $created ), 'blocks' => $created );
	}

	// ── helpers ────────────────────────────────────────────────────

	/**
	 * Team members eligible for availability (have the app, not the kiosk).
	 *
	 * @return array[] { user_id, name }
	 */
	public static function team_members() {
		$users = get_users( array( 'fields' => array( 'ID', 'display_name' ), 'number' => -1, 'orderby' => 'display_name' ) );
		$out = array();
		foreach ( $users as $u ) {
			$uid = (int) $u->ID;
			if ( zsch_user_has_access( $uid ) && ! zsch_user_is_read_only( $uid ) ) {
				$out[] = array( 'user_id' => $uid, 'name' => $u->display_name );
			}
		}
		return $out;
	}

	public static function get_raw( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore
		return $row ?: null;
	}

	public static function shape_row( $row ) {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$owner = get_userdata( (int) $row['owner_user_id'] );
		return array(
			'id'            => (int) $row['id'],
			'owner_user_id' => (int) $row['owner_user_id'],
			'owner_name'    => $owner ? $owner->display_name : '',
			'kind'          => $row['kind'],
			'start_utc'     => str_replace( ' ', 'T', $row['start_utc'] ) . 'Z',
			'end_utc'       => str_replace( ' ', 'T', $row['end_utc'] ) . 'Z',
			'is_all_day'    => (bool) $row['is_all_day'],
			'time_zone'     => $row['time_zone'],
			'note'          => $row['note'],
			'source'        => $row['source'],
		);
	}

	private static function local_to_utc( $local, $tz, $all_day, $is_end ) {
		if ( '' === $local ) {
			return '';
		}
		try {
			$local = str_replace( 'T', ' ', $local );
			// All-day: normalise to day bounds (start 00:00, end 23:59:59).
			if ( $all_day && false === strpos( $local, ':' ) ) {
				$local .= $is_end ? ' 23:59:59' : ' 00:00:00';
			}
			$dt = new DateTime( $local, new DateTimeZone( $tz ) );
			$dt->setTimezone( new DateTimeZone( 'UTC' ) );
			return $dt->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return '';
		}
	}

	private static function sanitize_tz( $tz ) {
		$tz = (string) $tz;
		try {
			new DateTimeZone( $tz );
			return $tz;
		} catch ( Exception $e ) {
			return ZSCH_Settings::default_tz();
		}
	}
}
