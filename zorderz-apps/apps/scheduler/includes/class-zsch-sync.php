<?php
/**
 * ZSCH_Sync — Connected Calendars Phase 1 sync engine + busy-overlay reads.
 *
 * Phase 0 connected accounts and stored encrypted tokens; Phase 1 (this class)
 * is the missing "last mile": it PULLS each enabled conflict feed's busy times
 * into the wp_zsch_external_events mirror on the existing 5-minute cron, and
 * exposes a single read helper the availability grid, the chat availability
 * verb, and the booking-conflict check all share.
 *
 * DATA FLOW
 *   zsch_cron_sync (every 5m) ─► cron_all()
 *      └─ per feed: ZSCH_{Graph_Delegated|Google}::fetch_events() over the
 *         rolling window (today−1d … +60d) ─► upsert into external_events
 *         (idempotent on UNIQUE(feed_id, external_event_id)) ─► generational
 *         prune (in-window rows this run did NOT touch = gone upstream) ─►
 *         feed bookkeeping (last_synced_at / sync_status / cursor).
 *
 * TOKENS: obtained ONLY through ZSCH_Vault::get_access_token() (single-flight
 * refresh; invalid_grant → the account is flipped to reauth_needed there, and
 * this engine simply skips it next tick until the user reconnects).
 *
 * PRIVACY (read side): external calendars are PERSONAL. read_busy() returns
 * BUSY-ONLY blocks (no titles) for team / cross-user views, and callers MUST
 * NOT invoke it for the shared kiosk. Titles live in the mirror only for a
 * future owner-eyes-only surface; they are never emitted by read_busy().
 *
 * SAFE NO-OP: every entry point is gated by ZSCH_OAuth::feature_enabled(); with
 * the flag down (or no feeds) nothing runs and no rows are written or read.
 *
 * @since 1.7.0 (Connected Calendars Phase 1)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_Sync {

	/** Rolling mirror window. */
	const WINDOW_BACK_DAYS = 1;
	const WINDOW_FWD_DAYS  = 60;

	/** Skip a feed re-synced this recently (manual /sync + cron overlap guard). */
	const MIN_RESYNC_SECS = 60;

	// ── table names ────────────────────────────────────────────────

	private static function t_accounts(): string {
		global $wpdb;
		return $wpdb->prefix . 'zsch_calendar_accounts';
	}

	private static function t_feeds(): string {
		global $wpdb;
		return $wpdb->prefix . 'zsch_calendar_feeds';
	}

	private static function t_events(): string {
		global $wpdb;
		return $wpdb->prefix . 'zsch_external_events';
	}

	/** Rolling UTC window [start,end] as 'Y-m-d H:i:s'. */
	public static function window(): array {
		$now = time();
		return array(
			gmdate( 'Y-m-d H:i:s', $now - ( self::WINDOW_BACK_DAYS * DAY_IN_SECONDS ) ),
			gmdate( 'Y-m-d H:i:s', $now + ( self::WINDOW_FWD_DAYS * DAY_IN_SECONDS ) ),
		);
	}

	// ── sync (write side) ──────────────────────────────────────────

	/**
	 * Cron entry — sync every OK account's feeds, then purge out-of-window rows.
	 * Feature-gated, self-locking (a manual /sync can't overlap it), and wrapped
	 * so one bad feed or a provider hiccup never breaks the tick (or the shared
	 * zsch_cron_sync that the app-level Graph sync also rides).
	 */
	public static function cron_all(): void {
		if ( ! class_exists( 'ZSCH_OAuth' ) || ! ZSCH_OAuth::feature_enabled() ) {
			return;
		}
		if ( get_transient( 'zsch_sync_running' ) ) {
			return;
		}
		set_transient( 'zsch_sync_running', 1, 3 * MINUTE_IN_SECONDS );
		try {
			foreach ( self::ok_feed_rows() as $f ) {
				self::sync_feed_row( $f );
			}
			self::purge_window();
		} catch ( \Throwable $e ) {
			self::log( 'cron_all fatal: ' . $e->getMessage() );
		} finally {
			delete_transient( 'zsch_sync_running' );
		}
	}

	/**
	 * Manual — sync just this user's feeds now (POST /connections/sync).
	 *
	 * @return array { success, feeds_synced, feeds_failed, events, reauth }
	 */
	public static function sync_user( int $user_id ): array {
		if ( ! class_exists( 'ZSCH_OAuth' ) || ! ZSCH_OAuth::feature_enabled() ) {
			return array( 'success' => false, 'error' => 'Connected Calendars is not enabled.' );
		}
		$ok = 0;
		$err = 0;
		$events = 0;
		$reauth = false;
		foreach ( self::ok_feed_rows( $user_id ) as $f ) {
			$r = self::sync_feed_row( $f, true );
			$reauth = $reauth || $r['reauth'];
			if ( $r['ok'] ) {
				$ok++;
				$events += $r['count'];
			} else {
				$err++;
			}
		}
		return array(
			'success'      => true,
			'feeds_synced' => $ok,
			'feeds_failed' => $err,
			'events'       => $events,
			'reauth'       => $reauth,
		);
	}

	/**
	 * Feed rows joined with their account, only for accounts in 'ok' status
	 * (reauth_needed / disabled are skipped until reconnected). Optionally scoped
	 * to one owner.
	 *
	 * @return array<int,object>
	 */
	private static function ok_feed_rows( int $owner_user_id = 0 ): array {
		global $wpdb;
		$sql = 'SELECT f.id, f.account_id, f.external_cal_id, f.privacy, f.last_synced_at,
				a.owner_user_id, a.provider
			FROM ' . self::t_feeds() . ' f
			INNER JOIN ' . self::t_accounts() . " a ON a.id = f.account_id
			WHERE a.status = 'ok'";
		if ( $owner_user_id > 0 ) {
			$sql = $wpdb->prepare( $sql . ' AND a.owner_user_id = %d', $owner_user_id ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return (array) $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Sync one feed (row joined with account fields: provider, owner_user_id).
	 *
	 * @return array { ok:bool, count:int, reauth:bool }
	 */
	private static function sync_feed_row( $f, bool $force = false ): array {
		global $wpdb;
		$out = array( 'ok' => false, 'count' => 0, 'reauth' => false );

		if ( ! $force
			&& ! empty( $f->last_synced_at )
			&& ( time() - strtotime( $f->last_synced_at . ' UTC' ) ) < self::MIN_RESYNC_SECS ) {
			$out['ok'] = true; // synced moments ago — leave it
			return $out;
		}

		list( $start, $end ) = self::window();
		$provider = (string) $f->provider;

		$res = ( 'microsoft' === $provider )
			? ZSCH_Graph_Delegated::fetch_events( (int) $f->account_id, (string) $f->external_cal_id, $start, $end )
			: ZSCH_Google::fetch_events( (int) $f->account_id, (string) $f->external_cal_id, $start, $end );

		if ( is_wp_error( $res ) ) {
			$code   = $res->get_error_code();
			$reauth = ( 'zsch_reauth' === $code );
			$wpdb->update(
				self::t_feeds(),
				array(
					'sync_status' => $reauth ? 'reauth' : 'error',
					'last_error'  => substr( sanitize_text_field( $res->get_error_message() ), 0, 250 ),
					'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
				),
				array( 'id' => (int) $f->id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
			$out['reauth'] = $reauth;
			self::log( "feed {$f->id} ({$provider}) sync error: {$code}" );
			return $out;
		}

		$run_ts     = gmdate( 'Y-m-d H:i:s' );
		$busy_only  = ( 'busy_only' === (string) $f->privacy );
		$count      = 0;
		foreach ( (array) $res['events'] as $ev ) {
			if ( '' === (string) ( $ev['external_event_id'] ?? '' ) ) {
				continue;
			}
			$title = $busy_only ? '' : (string) ( $ev['title'] ?? '' );
			self::upsert_event( (int) $f->id, $ev, $title, $run_ts );
			$count++;
		}

		// Generational prune: in-window rows for THIS feed that this run did not
		// re-touch have vanished upstream (cancelled / moved out) → drop them.
		$wpdb->query( $wpdb->prepare(
			'DELETE FROM ' . self::t_events() . ' WHERE feed_id = %d AND start_utc < %s AND end_utc > %s AND updated_at < %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			(int) $f->id, $end, $start, $run_ts
		) );

		$wpdb->update(
			self::t_feeds(),
			array(
				'last_synced_at' => $run_ts,
				'sync_status'    => 'ok',
				'last_error'     => '',
				'sync_cursor'    => (string) ( $res['cursor'] ?? '' ),
				'updated_at'     => $run_ts,
			),
			array( 'id' => (int) $f->id ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		$out['ok']    = true;
		$out['count'] = $count;
		return $out;
	}

	/** Idempotent upsert of one normalized busy event into the mirror. */
	private static function upsert_event( int $feed_id, array $ev, string $title, string $run_ts ): void {
		global $wpdb;
		$sql = 'INSERT INTO ' . self::t_events() . '
			(feed_id, external_event_id, start_utc, end_utc, time_zone, is_all_day, busy_status, title, updated_at)
			VALUES (%d, %s, %s, %s, %s, %d, %s, %s, %s)
			ON DUPLICATE KEY UPDATE
			start_utc = VALUES(start_utc), end_utc = VALUES(end_utc), time_zone = VALUES(time_zone),
			is_all_day = VALUES(is_all_day), busy_status = VALUES(busy_status), title = VALUES(title),
			updated_at = VALUES(updated_at)';
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql,
			$feed_id,
			(string) $ev['external_event_id'],
			(string) $ev['start_utc'],
			(string) $ev['end_utc'],
			'UTC',
			(int) ( $ev['is_all_day'] ?? 0 ),
			(string) ( $ev['busy_status'] ?? 'busy' ),
			$title,
			$run_ts
		) );
	}

	/** Drop mirror rows entirely outside the rolling window. */
	public static function purge_window(): void {
		global $wpdb;
		list( $start, $end ) = self::window();
		$wpdb->query( $wpdb->prepare(
			'DELETE FROM ' . self::t_events() . ' WHERE end_utc < %s OR start_utc > %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$start, $end
		) );
	}

	// ── reads (busy overlay) ───────────────────────────────────────

	/**
	 * External busy blocks per user in a UTC window. BUSY-ONLY (no titles) — this
	 * is coordination data for team/cross-user views. NEVER call for a kiosk
	 * viewer: external calendars are personal.
	 *
	 * @param int[]  $user_ids
	 * @param string $start_utc 'Y-m-d H:i:s'
	 * @param string $end_utc   'Y-m-d H:i:s'
	 * @return array<int,array<int,array>> owner_user_id => [ {kind:'busy',start_utc,end_utc,is_all_day,busy_status,source:'external',note:''} ]
	 */
	public static function read_busy( array $user_ids, string $start_utc, string $end_utc ): array {
		global $wpdb;
		if ( ! class_exists( 'ZSCH_OAuth' ) || ! ZSCH_OAuth::feature_enabled() ) {
			return array();
		}
		$ids = array_values( array_filter( array_map( 'intval', $user_ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql = 'SELECT a.owner_user_id, e.start_utc, e.end_utc, e.is_all_day, e.busy_status
			FROM ' . self::t_events() . ' e
			INNER JOIN ' . self::t_feeds() . ' f ON f.id = e.feed_id
			INNER JOIN ' . self::t_accounts() . " a ON a.id = f.account_id
			WHERE a.status <> 'disabled'
			  AND e.busy_status <> 'free'
			  AND e.start_utc < %s AND e.end_utc > %s
			  AND a.owner_user_id IN ($ph)
			ORDER BY e.start_utc ASC
			LIMIT 4000";
		$params = array_merge( array( $end_utc, $start_utc ), $ids );
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		$out = array();
		foreach ( (array) $rows as $r ) {
			$uid = (int) $r['owner_user_id'];
			$out[ $uid ][] = array(
				'kind'        => 'busy',
				'start_utc'   => str_replace( ' ', 'T', $r['start_utc'] ) . 'Z',
				'end_utc'     => str_replace( ' ', 'T', $r['end_utc'] ) . 'Z',
				'is_all_day'  => (bool) $r['is_all_day'],
				'busy_status' => (string) $r['busy_status'],
				'source'      => 'external',
				'note'        => '',
			);
		}
		return $out;
	}

	/**
	 * External busy overlaps for ONE user in a window — the booking-conflict
	 * check. Empty when the feature is off, the user has no feeds, or nothing
	 * overlaps.
	 *
	 * @return array<int,array> [ {kind,start_utc,end_utc,is_all_day,busy_status,source,note} ]
	 */
	public static function conflicts_for( int $owner_id, string $start_utc, string $end_utc ): array {
		if ( $owner_id <= 0 ) {
			return array();
		}
		$map = self::read_busy( array( $owner_id ), $start_utc, $end_utc );
		return $map[ $owner_id ] ?? array();
	}

	private static function log( string $msg ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'ZSCH Sync: ' . $msg );
		}
	}
}
