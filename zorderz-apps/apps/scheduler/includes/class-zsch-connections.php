<?php
/**
 * ZSCH_Connections — account + feed models for Connected Calendars.
 *
 * Owner-scoping is enforced HERE, at the data layer (INV-Ownership): every
 * read/mutation takes the acting user id and filters `owner_user_id` in SQL —
 * a leaked/guessed numeric id returns nothing. Admins get roster STATUS only
 * (provider, label, status, counts) — never tokens, never other users' feed
 * details beyond names.
 *
 * @since 1.6.0 (Connected Calendars Phase 0)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_Connections {

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

	// ── accounts ───────────────────────────────────────────────────

	/**
	 * Create-or-replace the grant for (owner, provider, external account).
	 * Reconnecting an existing account REPLACES its tokens in place (same row
	 * id, feeds preserved) — never a sibling row (Google refresh-token cap).
	 *
	 * @param int    $owner_user_id
	 * @param string $provider     'google' | 'microsoft'
	 * @param string $external_id  Immutable provider key (sub / tid:oid).
	 * @param string $email_label  Display only.
	 * @param string $scopes       Space-separated granted scopes.
	 * @param array  $tokens       {access_token, refresh_token, expires_in}
	 * @return int|WP_Error Account id.
	 */
	public static function upsert_account( int $owner_user_id, string $provider, string $external_id, string $email_label, string $scopes, array $tokens ) {
		global $wpdb;
		if ( $owner_user_id <= 0 || '' === $external_id || ! in_array( $provider, array( 'google', 'microsoft' ), true ) ) {
			return new WP_Error( 'zsch_conn_input', 'Invalid account identity.' );
		}

		$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::t_accounts() . ' WHERE owner_user_id = %d AND provider = %s AND external_id = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$owner_user_id, $provider, $external_id
		) );

		if ( $existing_id <= 0 ) {
			$ok = $wpdb->insert( self::t_accounts(), array(
				'owner_user_id' => $owner_user_id,
				'provider'      => $provider,
				'external_id'   => $external_id,
				'email_label'   => sanitize_text_field( $email_label ),
				'scopes'        => sanitize_text_field( $scopes ),
				'status'        => 'ok',
				'created_at'    => gmdate( 'Y-m-d H:i:s' ),
				'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
			), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
			if ( false === $ok ) {
				return new WP_Error( 'zsch_conn_db', 'Could not save the calendar account.' );
			}
			$existing_id = (int) $wpdb->insert_id;
		} else {
			$wpdb->update( self::t_accounts(), array(
				'email_label' => sanitize_text_field( $email_label ),
				'scopes'      => sanitize_text_field( $scopes ),
				'status'      => 'ok',
				'last_error'  => '',
				'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
			), array( 'id' => $existing_id ), array( '%s', '%s', '%s', '%s', '%s' ), array( '%d' ) );
		}

		$stored = ZSCH_Vault::store_tokens(
			$existing_id,
			(string) ( $tokens['access_token'] ?? '' ),
			(string) ( $tokens['refresh_token'] ?? '' ),
			(int) ( $tokens['expires_in'] ?? 3600 )
		);
		if ( ! $stored ) {
			return new WP_Error( 'zsch_conn_vault', 'Could not store the calendar tokens.' );
		}
		return $existing_id;
	}

	/**
	 * One account row, ONLY if owned by $user_id. Token columns stripped.
	 *
	 * @return object|null
	 */
	public static function get_owned_account( int $user_id, int $account_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT id, owner_user_id, provider, external_id, email_label, scopes, status, last_error, token_expires_at, updated_at FROM ' . self::t_accounts() . ' WHERE id = %d AND owner_user_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$account_id, $user_id
		) );
	}

	/**
	 * All of a user's accounts, each with its feeds. Serialized for the
	 * widget card (no token material, ever).
	 *
	 * @return array
	 */
	public static function list_for_user( int $user_id ): array {
		global $wpdb;
		$accounts = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, provider, email_label, status, last_error, updated_at FROM ' . self::t_accounts() . ' WHERE owner_user_id = %d ORDER BY provider, email_label', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$user_id
		) );
		$out = array();
		foreach ( (array) $accounts as $a ) {
			$feeds = $wpdb->get_results( $wpdb->prepare(
				'SELECT id, external_cal_id, name, color, mode, privacy, last_synced_at, sync_status FROM ' . self::t_feeds() . ' WHERE account_id = %d ORDER BY name', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				(int) $a->id
			) );
			$out[] = array(
				'id'          => (int) $a->id,
				'provider'    => (string) $a->provider,
				'email_label' => (string) $a->email_label,
				'status'      => (string) $a->status,
				'feeds'       => array_map( static function ( $f ) {
					return array(
						'id'              => (int) $f->id,
						'external_cal_id' => (string) $f->external_cal_id,
						'name'            => (string) $f->name,
						'color'           => (string) $f->color,
						'mode'            => (string) $f->mode,
						'privacy'         => (string) $f->privacy,
						'last_synced_at'  => $f->last_synced_at ? (string) $f->last_synced_at : null,
						'sync_status'     => (string) $f->sync_status,
					);
				}, (array) $feeds ),
			);
		}
		return $out;
	}

	/**
	 * Delete an owned account: feeds + mirrored events + the row. Best-effort
	 * provider revoke happens in ZSCH_OAuth::disconnect() BEFORE this (it
	 * needs the tokens, which die here).
	 *
	 * @return bool
	 */
	public static function delete_account( int $user_id, int $account_id ): bool {
		global $wpdb;
		$owned = self::get_owned_account( $user_id, $account_id );
		if ( ! $owned ) {
			return false; // Not yours (or gone) — indistinguishable, on purpose.
		}
		$feed_ids = $wpdb->get_col( $wpdb->prepare(
			'SELECT id FROM ' . self::t_feeds() . ' WHERE account_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$account_id
		) );
		if ( ! empty( $feed_ids ) ) {
			$in = implode( ',', array_map( 'intval', $feed_ids ) );
			$wpdb->query( 'DELETE FROM ' . self::t_events() . " WHERE feed_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( 'DELETE FROM ' . self::t_feeds() . " WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$wpdb->delete( self::t_accounts(), array( 'id' => $account_id ), array( '%d' ) );
		return true;
	}

	// ── feeds (conflict calendars) ─────────────────────────────────

	/**
	 * Turn a calendar ON as a conflict feed (idempotent) — owner-checked via
	 * the account join.
	 *
	 * @return int|WP_Error Feed id.
	 */
	public static function enable_feed( int $user_id, int $account_id, string $external_cal_id, string $name, string $color ) {
		global $wpdb;
		if ( ! self::get_owned_account( $user_id, $account_id ) ) {
			return new WP_Error( 'zsch_conn_denied', 'Unknown account.', array( 'status' => 404 ) );
		}
		if ( '' === $external_cal_id ) {
			return new WP_Error( 'zsch_conn_input', 'Missing calendar id.' );
		}
		$existing = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::t_feeds() . ' WHERE account_id = %d AND external_cal_id = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$account_id, $external_cal_id
		) );
		if ( $existing > 0 ) {
			return $existing;
		}
		$ok = $wpdb->insert( self::t_feeds(), array(
			'account_id'      => $account_id,
			'external_cal_id' => sanitize_text_field( $external_cal_id ),
			'name'            => sanitize_text_field( $name ),
			'color'           => sanitize_text_field( $color ),
			'mode'            => 'conflict',
			'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
		), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		if ( false === $ok ) {
			return new WP_Error( 'zsch_conn_db', 'Could not save the conflict calendar.' );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Turn a calendar OFF: delete the feed row + its mirrored events.
	 * Owner-checked through the account join.
	 *
	 * @return bool
	 */
	public static function disable_feed( int $user_id, int $feed_id ): bool {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT f.id FROM ' . self::t_feeds() . ' f INNER JOIN ' . self::t_accounts() . ' a ON a.id = f.account_id WHERE f.id = %d AND a.owner_user_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$feed_id, $user_id
		) );
		if ( ! $row ) {
			return false;
		}
		$wpdb->delete( self::t_events(), array( 'feed_id' => $feed_id ), array( '%d' ) );
		$wpdb->delete( self::t_feeds(), array( 'id' => $feed_id ), array( '%d' ) );
		return true;
	}

	// ── admin roster (status only) ─────────────────────────────────

	/**
	 * Roster for Settings → TS Scheduler: who has what connected. Status
	 * only — no tokens, no calendar contents.
	 *
	 * @return array
	 */
	public static function roster(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT a.owner_user_id, a.provider, a.email_label, a.status, a.updated_at,
				(SELECT COUNT(*) FROM ' . self::t_feeds() . ' f WHERE f.account_id = a.id) AS feed_count
			 FROM ' . self::t_accounts() . ' a ORDER BY a.owner_user_id, a.provider' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$u     = get_userdata( (int) $r->owner_user_id );
			$out[] = array(
				'user'        => $u ? $u->display_name : ( 'user #' . (int) $r->owner_user_id ),
				'provider'    => (string) $r->provider,
				'email_label' => (string) $r->email_label,
				'status'      => (string) $r->status,
				'feeds'       => (int) $r->feed_count,
				'updated_at'  => (string) $r->updated_at,
			);
		}
		return $out;
	}
}
