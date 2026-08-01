<?php
/**
 * ZSCH_Appointments — the appointment model (wp_zsch_appointments).
 *
 * Local source of truth for events. Every write also pushes to Microsoft Graph
 * (when sync is active) and records the mapping in wp_zsch_graph_map. The
 * cron puller calls reconcile_from_graph() to fold Outlook-side changes back in.
 *
 * SCOPES:
 *   'personal' — belongs to one user; visible to that user + admins; synced to
 *                that user's Outlook calendar.
 *   'shared'   — the team calendar; visible to all who can see the scheduler;
 *                synced to the OWNER's Outlook (whoever created it) so it shows
 *                on a real mailbox, and colour-coded by owner in the UI.
 *
 * All times stored UTC. The caller hands wall-clock + tz; we convert.
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_Appointments {

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'zsch_appointments';
	}

	private static function map_table() {
		global $wpdb;
		return $wpdb->prefix . 'zsch_graph_map';
	}

	/**
	 * Fetch events visible to $viewer within a UTC window.
	 *
	 * Visibility:
	 *   - shared events: everyone.
	 *   - personal events: only the owner (admins may pass $all_personal=true via
	 *     a scope arg, but the default viewer call shows just their own).
	 *
	 * @param int    $viewer_id
	 * @param string $start_utc 'Y-m-d H:i:s'
	 * @param string $end_utc
	 * @param array  $args { scope: 'all'|'personal'|'shared', owner_id?:int }
	 * @return array[] normalised rows
	 */
	public static function query( $viewer_id, $start_utc, $end_utc, array $args = array() ) {
		global $wpdb;
		$viewer_id = (int) $viewer_id;
		$scope     = $args['scope'] ?? 'all';
		$t         = self::table();

		$where  = array( 'deleted_at IS NULL' );
		$params = array();

		// Window overlap: event starts before window end AND ends after window start.
		$where[]  = 'start_utc < %s AND end_utc > %s';
		$params[] = $end_utc;
		$params[] = $start_utc;

		$is_admin = self::viewer_is_admin( $viewer_id );

		if ( 'shared' === $scope ) {
			$where[] = "calendar_scope = 'shared'";
		} elseif ( 'personal' === $scope ) {
			$where[]  = "calendar_scope = 'personal' AND owner_user_id = %d";
			$params[] = $viewer_id;
		} else {
			// 'all' — shared (everyone) + personal (own, or anyone if admin).
			if ( $is_admin && ! empty( $args['owner_id'] ) ) {
				$where[]  = "( calendar_scope = 'shared' OR ( calendar_scope = 'personal' AND owner_user_id = %d ) )";
				$params[] = (int) $args['owner_id'];
			} elseif ( $is_admin ) {
				// admins see all personal + shared
				$where[] = "( calendar_scope = 'shared' OR calendar_scope = 'personal' )";
			} else {
				$where[]  = "( calendar_scope = 'shared' OR ( calendar_scope = 'personal' AND owner_user_id = %d ) )";
				$params[] = $viewer_id;
			}
		}

		$sql = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY start_utc ASC LIMIT 1000';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore

		return array_map( array( __CLASS__, 'shape_row' ), $rows ?: array() );
	}

	/**
	 * Create an appointment (and push to Graph if active).
	 *
	 * @param int   $actor_id  the user creating it.
	 * @param array $data      raw input: title, body, location, start_local,
	 *                         end_local, time_zone, is_all_day, calendar_scope,
	 *                         busy_status, owner_id?, attendees?[]
	 * @return array { success, id?, appointment?, error?, graph? }
	 */
	public static function create( $actor_id, array $data ) {
		global $wpdb;
		$actor_id = (int) $actor_id;

		if ( ! zsch_user_can_write( $actor_id ) ) {
			return array( 'success' => false, 'error' => 'Read-only account cannot create events.' );
		}

		$scope = ( ( $data['calendar_scope'] ?? 'personal' ) === 'shared' ) ? 'shared' : 'personal';
		// Owner: personal events belong to the actor; shared events can name an
		// owner (defaults to actor). Non-admins can only own their own.
		$owner_id = $actor_id;
		if ( ! empty( $data['owner_id'] ) && self::viewer_is_admin( $actor_id ) ) {
			$owner_id = (int) $data['owner_id'];
		}

		$tz       = self::sanitize_tz( $data['time_zone'] ?? ZSCH_Settings::default_tz() );
		$start_utc = self::local_to_utc( $data['start_local'] ?? '', $tz );
		$end_utc   = self::local_to_utc( $data['end_local'] ?? '', $tz );
		if ( '' === $start_utc || '' === $end_utc || strtotime( $end_utc ) <= strtotime( $start_utc ) ) {
			return array( 'success' => false, 'error' => 'Invalid start/end time.' );
		}

		// v1.7.0 (Connected Calendars Phase 1) — conflict check against the
		// owner's EXTERNAL calendars. Policy 'warn' (default): book anyway and
		// return warnings for the UI/bot to surface. Policy 'block': refuse.
		// No-op (empty) when the feature is off or the owner has no feeds.
		$conflicts = class_exists( 'ZSCH_Sync' ) ? ZSCH_Sync::conflicts_for( (int) $owner_id, $start_utc, $end_utc ) : array();
		if ( ! empty( $conflicts ) && 'block' === ZSCH_Settings::conncal_config()['conflict_policy'] ) {
			return array(
				'success'   => false,
				'error'     => 'That time conflicts with a connected calendar.',
				'conflicts' => self::shape_conflicts( $conflicts ),
			);
		}

		$row = array(
			'owner_user_id'  => $owner_id,
			'calendar_scope' => $scope,
			'title'          => sanitize_text_field( $data['title'] ?? '' ),
			'body'           => sanitize_textarea_field( $data['body'] ?? '' ),
			'location'       => sanitize_text_field( $data['location'] ?? '' ),
			'start_utc'      => $start_utc,
			'end_utc'        => $end_utc,
			'is_all_day'     => ! empty( $data['is_all_day'] ) ? 1 : 0,
			'time_zone'      => $tz,
			'status'         => 'confirmed',
			'busy_status'    => self::sanitize_busy( $data['busy_status'] ?? 'busy' ),
			'color'          => sanitize_text_field( $data['color'] ?? '' ),
			'attendees'      => self::sanitize_attendees( $data['attendees'] ?? array() ),
			'created_by'     => $actor_id,
			'created_at'     => current_time( 'mysql', true ),
			'updated_at'     => current_time( 'mysql', true ),
		);

		$ok = $wpdb->insert( self::table(), $row ); // phpcs:ignore
		if ( ! $ok ) {
			// Self-heal a missing table (file-overwrite update), then retry once.
			if ( function_exists( 'zsch_tables_exist' ) && ! zsch_tables_exist() ) {
				require_once ZSCH_PLUGIN_DIR . 'db/migrate-1.0.0.php';
				ZSCH_Migrate_1_0_0::run();
				$ok = $wpdb->insert( self::table(), $row ); // phpcs:ignore
			}
			if ( ! $ok ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'ZSCH_Appointments insert failed: ' . $wpdb->last_error );
				}
				return array( 'success' => false, 'error' => 'Could not save appointment. ' . ( $wpdb->last_error ? $wpdb->last_error : 'Please try again.' ) );
			}
		}
		$id = (int) $wpdb->insert_id;

		// Push to Graph (owner's mailbox). Non-fatal on failure — local row stands.
		$graph_result = self::push_create( $owner_id, array_merge( $row, array( 'id' => $id ) ) );

		return array(
			'success'     => true,
			'id'          => $id,
			'appointment' => self::shape_row( self::get_raw( $id ) ),
			'graph'       => $graph_result,
			// v1.7.0 — non-empty when the booked time overlaps the owner's
			// external calendars (policy 'warn'): booked anyway, surfaced to the UI.
			'warnings'    => ! empty( $conflicts ) ? self::shape_conflicts( $conflicts ) : array(),
		);
	}

	/**
	 * Update an appointment (and patch Graph).
	 *
	 * @param int   $actor_id
	 * @param int   $id
	 * @param array $data  same shape as create (partial allowed).
	 * @return array
	 */
	public static function update( $actor_id, $id, array $data ) {
		global $wpdb;
		$actor_id = (int) $actor_id;
		$id       = (int) $id;

		$existing = self::get_raw( $id );
		if ( ! $existing ) {
			return array( 'success' => false, 'error' => 'Not found.' );
		}
		if ( ! self::can_modify( $actor_id, $existing ) ) {
			return array( 'success' => false, 'error' => 'Not allowed.' );
		}

		$tz = self::sanitize_tz( $data['time_zone'] ?? $existing['time_zone'] );

		$update = array( 'updated_at' => current_time( 'mysql', true ) );
		if ( isset( $data['title'] ) )       { $update['title']    = sanitize_text_field( $data['title'] ); }
		if ( isset( $data['body'] ) )        { $update['body']     = sanitize_textarea_field( $data['body'] ); }
		if ( isset( $data['location'] ) )    { $update['location'] = sanitize_text_field( $data['location'] ); }
		if ( isset( $data['busy_status'] ) ) { $update['busy_status'] = self::sanitize_busy( $data['busy_status'] ); }
		if ( isset( $data['color'] ) )       { $update['color']    = sanitize_text_field( $data['color'] ); }
		if ( isset( $data['time_zone'] ) )   { $update['time_zone'] = $tz; }
		if ( array_key_exists( 'attendees', $data ) ) { $update['attendees'] = self::sanitize_attendees( $data['attendees'] ); }
		if ( isset( $data['is_all_day'] ) )  { $update['is_all_day'] = ! empty( $data['is_all_day'] ) ? 1 : 0; }

		if ( isset( $data['start_local'] ) ) {
			$s = self::local_to_utc( $data['start_local'], $tz );
			if ( '' === $s ) { return array( 'success' => false, 'error' => 'Invalid start time.' ); }
			$update['start_utc'] = $s;
		}
		if ( isset( $data['end_local'] ) ) {
			$e = self::local_to_utc( $data['end_local'], $tz );
			if ( '' === $e ) { return array( 'success' => false, 'error' => 'Invalid end time.' ); }
			$update['end_utc'] = $e;
		}

		$start_check = $update['start_utc'] ?? $existing['start_utc'];
		$end_check   = $update['end_utc'] ?? $existing['end_utc'];
		if ( strtotime( $end_check ) <= strtotime( $start_check ) ) {
			return array( 'success' => false, 'error' => 'End must be after start.' );
		}

		$wpdb->update( self::table(), $update, array( 'id' => $id ) ); // phpcs:ignore

		$merged = array_merge( $existing, $update );
		$graph_result = self::push_update( (int) $existing['owner_user_id'], $merged );

		return array(
			'success'     => true,
			'id'          => $id,
			'appointment' => self::shape_row( self::get_raw( $id ) ),
			'graph'       => $graph_result,
		);
	}

	/**
	 * Soft-delete an appointment (and delete from Graph).
	 *
	 * @param int $actor_id
	 * @param int $id
	 * @return array
	 */
	public static function delete( $actor_id, $id ) {
		global $wpdb;
		$id = (int) $id;
		$existing = self::get_raw( $id );
		if ( ! $existing ) {
			return array( 'success' => false, 'error' => 'Not found.' );
		}
		if ( ! self::can_modify( (int) $actor_id, $existing ) ) {
			return array( 'success' => false, 'error' => 'Not allowed.' );
		}

		$wpdb->update( self::table(), array( 'deleted_at' => current_time( 'mysql', true ) ), array( 'id' => $id ) ); // phpcs:ignore

		// Remove from Graph + drop the map row.
		$map = self::get_map( $id );
		if ( $map && '' !== $map['graph_event_id'] ) {
			$mailbox = ZSCH_Graph::mailbox_for_user( (int) $existing['owner_user_id'] );
			ZSCH_Graph::delete_event( $mailbox, $map['graph_event_id'] );
			$wpdb->delete( self::map_table(), array( 'appointment_id' => $id ) ); // phpcs:ignore
		}

		return array( 'success' => true, 'id' => $id );
	}

	// ── Graph push helpers ─────────────────────────────────────────

	private static function push_create( $owner_id, array $row ) {
		if ( ! ZSCH_Graph::is_available() ) {
			return array( 'skipped' => true );
		}
		$res = ZSCH_Graph::create_event( $owner_id, $row );
		if ( ! empty( $res['success'] ) && empty( $res['skipped'] ) && ! empty( $res['graph_event_id'] ) ) {
			self::upsert_map( (int) $row['id'], ZSCH_Graph::mailbox_for_user( $owner_id ), $res );
		}
		return $res;
	}

	private static function push_update( $owner_id, array $row ) {
		if ( ! ZSCH_Graph::is_available() ) {
			return array( 'skipped' => true );
		}
		$map = self::get_map( (int) $row['id'] );
		$mailbox = ZSCH_Graph::mailbox_for_user( $owner_id );
		if ( $map && '' !== $map['graph_event_id'] ) {
			$res = ZSCH_Graph::update_event( $mailbox, $map['graph_event_id'], $row, $map['etag'] );
			if ( ! empty( $res['success'] ) && empty( $res['skipped'] ) ) {
				self::upsert_map( (int) $row['id'], $mailbox, $res );
			}
			return $res;
		}
		// No map yet (created while sync was off) — create it now.
		return self::push_create( $owner_id, $row );
	}

	/**
	 * Reconcile a batch of Graph events for a user into our table.
	 * Called by ZSCH_Graph::cron_sync_all(). Match on graph_event_id (then
	 * iCalUId); insert new, update changed, soft-delete cancelled.
	 *
	 * @param int    $owner_id
	 * @param string $mailbox
	 * @param array  $events  raw Graph event objects.
	 */
	public static function reconcile_from_graph( $owner_id, $mailbox, array $events ) {
		global $wpdb;
		foreach ( $events as $ev ) {
			$graph_id = $ev['id'] ?? '';
			if ( '' === $graph_id ) {
				continue;
			}
			$start_utc = ZSCH_Graph::graph_to_utc( $ev['start'] ?? array() );
			$end_utc   = ZSCH_Graph::graph_to_utc( $ev['end'] ?? array() );
			if ( '' === $start_utc || '' === $end_utc ) {
				continue;
			}
			$cancelled = ! empty( $ev['isCancelled'] );

			$existing_id = self::appt_id_for_graph_event( $graph_id );

			if ( $cancelled ) {
				if ( $existing_id ) {
					$wpdb->update( self::table(), array( 'deleted_at' => current_time( 'mysql', true ) ), array( 'id' => $existing_id ) ); // phpcs:ignore
				}
				continue;
			}

			$fields = array(
				'owner_user_id'  => (int) $owner_id,
				'calendar_scope' => 'personal', // pulled-in Outlook events are personal
				'title'          => sanitize_text_field( $ev['subject'] ?? '(no subject)' ),
				'body'           => sanitize_textarea_field( $ev['bodyPreview'] ?? '' ),
				'location'       => sanitize_text_field( $ev['location']['displayName'] ?? '' ),
				'start_utc'      => $start_utc,
				'end_utc'        => $end_utc,
				'is_all_day'     => ! empty( $ev['isAllDay'] ) ? 1 : 0,
				'busy_status'    => self::busy_from_show_as( $ev['showAs'] ?? 'busy' ),
				'status'         => 'confirmed',
				'updated_at'     => current_time( 'mysql', true ),
			);

			if ( $existing_id ) {
				$wpdb->update( self::table(), $fields, array( 'id' => $existing_id ) ); // phpcs:ignore
				self::touch_map_synced( $existing_id, $ev['@odata.etag'] ?? '' );
			} else {
				$fields['created_by'] = 0; // system/pull
				$fields['created_at'] = current_time( 'mysql', true );
				$wpdb->insert( self::table(), $fields ); // phpcs:ignore
				$new_id = (int) $wpdb->insert_id;
				self::upsert_map( $new_id, $mailbox, array(
					'graph_event_id' => $graph_id,
					'ical_uid'       => $ev['iCalUId'] ?? '',
					'etag'           => $ev['@odata.etag'] ?? '',
				), 'pull' );
			}
		}
	}

	// ── map-table helpers ──────────────────────────────────────────

	private static function upsert_map( $appointment_id, $mailbox, array $res, $direction = 'push' ) {
		global $wpdb;
		$appointment_id = (int) $appointment_id;
		$existing = self::get_map( $appointment_id );
		$data = array(
			'appointment_id' => $appointment_id,
			'graph_user'     => (string) $mailbox,
			'graph_event_id' => (string) ( $res['graph_event_id'] ?? ( $existing['graph_event_id'] ?? '' ) ),
			'graph_ical_uid' => (string) ( $res['ical_uid'] ?? ( $existing['graph_ical_uid'] ?? '' ) ),
			'etag'           => (string) ( $res['etag'] ?? ( $existing['etag'] ?? '' ) ),
			'last_synced_at' => current_time( 'mysql', true ),
			'direction'      => $direction,
			'sync_error'     => '',
		);
		if ( $existing ) {
			$wpdb->update( self::map_table(), $data, array( 'appointment_id' => $appointment_id ) ); // phpcs:ignore
		} else {
			$wpdb->insert( self::map_table(), $data ); // phpcs:ignore
		}
	}

	private static function touch_map_synced( $appointment_id, $etag ) {
		global $wpdb;
		$wpdb->update( self::map_table(), array(
			'etag'           => (string) $etag,
			'last_synced_at' => current_time( 'mysql', true ),
			'direction'      => 'pull',
		), array( 'appointment_id' => (int) $appointment_id ) ); // phpcs:ignore
	}

	private static function get_map( $appointment_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::map_table() . ' WHERE appointment_id = %d', (int) $appointment_id ), ARRAY_A ); // phpcs:ignore
		return $row ?: null;
	}

	private static function appt_id_for_graph_event( $graph_event_id ) {
		global $wpdb;
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT appointment_id FROM ' . self::map_table() . ' WHERE graph_event_id = %s', $graph_event_id ) ); // phpcs:ignore
		return $id ? (int) $id : 0;
	}

	// ── small utilities ────────────────────────────────────────────

	public static function get_raw( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore
		return $row ?: null;
	}

	/**
	 * Compact external-conflict list for booking warnings (busy-only; no titles).
	 *
	 * @param array $conflicts rows from ZSCH_Sync::conflicts_for()
	 * @return array
	 */
	private static function shape_conflicts( array $conflicts ) {
		$out = array();
		foreach ( $conflicts as $c ) {
			$out[] = array(
				'start_utc'   => (string) ( $c['start_utc'] ?? '' ),
				'end_utc'     => (string) ( $c['end_utc'] ?? '' ),
				'is_all_day'  => ! empty( $c['is_all_day'] ),
				'busy_status' => (string) ( $c['busy_status'] ?? 'busy' ),
				'source'      => 'external',
			);
		}
		return $out;
	}

	private static function can_modify( $actor_id, array $row ) {
		if ( ! zsch_user_can_write( $actor_id ) ) {
			return false;
		}
		if ( self::viewer_is_admin( $actor_id ) ) {
			return true;
		}
		// Owner can modify their own; shared events can be edited by their creator.
		return (int) $row['owner_user_id'] === (int) $actor_id
			|| (int) $row['created_by'] === (int) $actor_id;
	}

	public static function viewer_is_admin( $user_id ) {
		return user_can( (int) $user_id, 'manage_options' )
			|| user_can( (int) $user_id, 'zdz_owner' )
			|| user_can( (int) $user_id, 'zdz_admin' );
	}

	/**
	 * Normalise a DB row into the shape the frontend + bridge consume.
	 */
	public static function shape_row( $row ) {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$owner = get_userdata( (int) $row['owner_user_id'] );
		return array(
			'id'             => (int) $row['id'],
			'owner_user_id'  => (int) $row['owner_user_id'],
			'owner_name'     => $owner ? $owner->display_name : '',
			'scope'          => $row['calendar_scope'],
			'title'          => $row['title'],
			'body'           => $row['body'],
			'location'       => $row['location'],
			'start_utc'      => self::iso( $row['start_utc'] ),
			'end_utc'        => self::iso( $row['end_utc'] ),
			'is_all_day'     => (bool) $row['is_all_day'],
			'time_zone'      => $row['time_zone'],
			'busy_status'    => $row['busy_status'],
			'status'         => $row['status'],
			'color'          => $row['color'],
			'attendees'      => json_decode( (string) ( $row['attendees'] ?? '' ), true ) ?: array(),
		);
	}

	private static function iso( $mysql_utc ) {
		if ( empty( $mysql_utc ) ) {
			return '';
		}
		return str_replace( ' ', 'T', $mysql_utc ) . 'Z';
	}

	private static function local_to_utc( $local, $tz ) {
		if ( '' === $local ) {
			return '';
		}
		try {
			// Accept "2026-06-14T14:00" or "2026-06-14 14:00[:00]".
			$local = str_replace( 'T', ' ', $local );
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

	private static function sanitize_busy( $b ) {
		$b = strtolower( (string) $b );
		return in_array( $b, array( 'busy', 'free', 'tentative', 'oof' ), true ) ? $b : 'busy';
	}

	private static function busy_from_show_as( $show ) {
		$show = strtolower( (string) $show );
		if ( 'free' === $show ) { return 'free'; }
		if ( 'tentative' === $show ) { return 'tentative'; }
		if ( 'oof' === $show || 'workingelsewhere' === $show ) { return 'oof'; }
		return 'busy';
	}

	private static function sanitize_attendees( $att ) {
		if ( is_string( $att ) ) {
			$att = json_decode( $att, true );
		}
		$out = array();
		if ( is_array( $att ) ) {
			foreach ( $att as $email ) {
				if ( is_email( $email ) ) {
					$out[] = sanitize_email( $email );
				}
			}
		}
		return wp_json_encode( array_values( array_unique( $out ) ) );
	}
}
