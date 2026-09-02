<?php
/**
 * ZIB_Connections — account model for per-user mailbox connections (Zorderz Inbox).
 *
 * Owner-scoping is enforced HERE, at the data layer (INV-Ownership): every
 * read/mutation takes the acting user id and filters `owner_user_id` in SQL, so
 * a leaked/guessed numeric id returns nothing. Token columns are NEVER selected
 * into any array that leaves this class.
 *
 * One mailbox per user (UNIQUE owner_user_id). Reconnecting REPLACES the grant
 * in place — same row id — rather than inserting a sibling.
 *
 * @since 0.1.0 (P0 — Connect)
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIB_Connections {

	const MODES = array( 'none', 'internal', 'external', 'all' );

	private static function t(): string {
		global $wpdb;
		return $wpdb->prefix . 'zib_accounts';
	}

	/**
	 * Create-or-replace this user's mailbox grant.
	 *
	 * @param int    $owner_user_id
	 * @param array  $who    {external_id (oid), tenant_id, upn, email}
	 * @param string $scopes Space-separated granted scopes.
	 * @param array  $tokens {access_token, refresh_token, expires_in}
	 * @return int|WP_Error Account id.
	 */
	public static function upsert_account( int $owner_user_id, array $who, string $scopes, array $tokens ) {
		global $wpdb;
		$external_id = (string) ( $who['external_id'] ?? '' );
		if ( $owner_user_id <= 0 || '' === $external_id ) {
			return new WP_Error( 'zib_conn_input', 'Invalid account identity.' );
		}

		$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::t() . ' WHERE owner_user_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$owner_user_id
		) );

		$identity = array(
			'provider'     => 'microsoft',
			'ms_tenant_id' => sanitize_text_field( (string) ( $who['tenant_id'] ?? '' ) ),
			'ms_object_id' => sanitize_text_field( $external_id ),
			'upn'          => sanitize_text_field( (string) ( $who['upn'] ?? '' ) ),
			'email_label'  => sanitize_text_field( (string) ( $who['email'] ?? '' ) ),
			'scopes'       => sanitize_text_field( $scopes ),
			'status'       => 'ok',
			'last_error'   => '',
			'updated_at'   => gmdate( 'Y-m-d H:i:s' ),
		);

		if ( $existing_id <= 0 ) {
			$insert = array_merge( $identity, array(
				'owner_user_id'   => $owner_user_id,
				'index_mode'      => 'none', // D10 — nothing indexes until the owner chooses.
				'window_start'    => gmdate( 'Y-m-d H:i:s', strtotime( '-1 year' ) ), // R4 — 12-month floor.
				'backfill_status' => 'pending',
				'connected_at'    => gmdate( 'Y-m-d H:i:s' ),
			) );
			$ok = $wpdb->insert( self::t(), $insert );
			if ( false === $ok ) {
				return new WP_Error( 'zib_conn_db', 'Could not save the mailbox account.' );
			}
			$existing_id = (int) $wpdb->insert_id;
		} else {
			// Reconnect. If the oid changed (a DIFFERENT mailbox), P1 must purge
			// the old Ballast + re-backfill; for P0 we just refresh identity.
			$wpdb->update( self::t(), $identity, array( 'id' => $existing_id ), null, array( '%d' ) );
		}

		$stored = ZIB_Vault::store_tokens(
			$existing_id,
			(string) ( $tokens['access_token'] ?? '' ),
			(string) ( $tokens['refresh_token'] ?? '' ),
			(int) ( $tokens['expires_in'] ?? 3600 )
		);
		if ( ! $stored ) {
			return new WP_Error( 'zib_conn_vault', 'Could not store the mailbox tokens.' );
		}
		return $existing_id;
	}

	/** One account row, ONLY if owned by $user_id. Token columns stripped. */
	public static function get_owned_account( int $user_id, int $account_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT id, owner_user_id, provider, email_label, upn, status, index_mode, admin_search_enabled, backfill_status, last_synced_at, last_error, connected_at, updated_at FROM ' . self::t() . ' WHERE id = %d AND owner_user_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$account_id, $user_id
		) );
	}

	/**
	 * This user's mailbox (status/settings only, no tokens) for the card.
	 *
	 * @return array|null
	 */
	public static function get_for_user( int $user_id ): ?array {
		global $wpdb;
		$a = $wpdb->get_row( $wpdb->prepare(
			'SELECT id, provider, email_label, upn, status, index_mode, admin_search_enabled, backfill_status, last_synced_at, connected_at FROM ' . self::t() . ' WHERE owner_user_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$user_id
		) );
		if ( ! $a ) {
			return null;
		}
		return array(
			'id'                   => (int) $a->id,
			'provider'             => (string) $a->provider,
			'email_label'          => (string) $a->email_label,
			'upn'                  => (string) $a->upn,
			'status'               => (string) $a->status,
			'index_mode'           => (string) $a->index_mode,
			'admin_search_enabled' => (bool) $a->admin_search_enabled,
			'backfill_status'      => (string) $a->backfill_status,
			'last_synced_at'       => $a->last_synced_at ? (string) $a->last_synced_at : null,
			'connected_at'         => (string) $a->connected_at,
		);
	}

	/** Set the index mode (owner-scoped). Narrowing purges out-of-scope mail in P1. */
	public static function set_index_mode( int $user_id, int $account_id, string $mode ) {
		global $wpdb;
		if ( ! in_array( $mode, self::MODES, true ) ) {
			return new WP_Error( 'zib_conn_mode', 'Unknown index mode.' );
		}
		$row = self::get_owned_account( $user_id, $account_id );
		if ( ! $row ) {
			return new WP_Error( 'zib_conn_denied', 'Unknown account.', array( 'status' => 404 ) );
		}
		$changed = ( (string) $row->index_mode !== $mode );

		if ( $changed ) {
			// A mode change re-scopes what's indexed. Purge the Ballast and reset
			// the backfill so the account re-indexes cleanly under the new rule —
			// the safe, simple choice (correct for both widening and narrowing).
			if ( class_exists( 'ZIB_Ingest' ) ) {
				ZIB_Ingest::purge_account( $account_id );
			}
			$wpdb->update(
				self::t(),
				array(
					'index_mode'         => $mode,
					'backfill_status'    => 'pending',
					'backfill_cursor'    => '',
					'delta_inbox_cursor' => '',
					'delta_sent_cursor'  => '',
					'updated_at'         => gmdate( 'Y-m-d H:i:s' ),
				),
				array( 'id' => $account_id ),
				array( '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		}
		return true;
	}

	/** Owner opt-in that lets admins search THIS mailbox (default off, R6). */
	public static function set_admin_search_enabled( int $user_id, int $account_id, bool $on ) {
		global $wpdb;
		if ( ! self::get_owned_account( $user_id, $account_id ) ) {
			return new WP_Error( 'zib_conn_denied', 'Unknown account.', array( 'status' => 404 ) );
		}
		$wpdb->update( self::t(), array( 'admin_search_enabled' => $on ? 1 : 0, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $account_id ), array( '%d', '%s' ), array( '%d' ) );
		return true;
	}

	/**
	 * Delete an owned account. In P1 this also purges the Ballast
	 * (messages/participants/seen) for the account; P0 has no content tables yet.
	 */
	public static function delete_account( int $user_id, int $account_id ): bool {
		global $wpdb;
		if ( ! self::get_owned_account( $user_id, $account_id ) ) {
			return false; // Not yours (or gone) — indistinguishable, on purpose.
		}
		// Disconnect purges the Ballast for this account (messages/participants/seen).
		if ( class_exists( 'ZIB_Ingest' ) ) {
			ZIB_Ingest::purge_account( $account_id );
		}
		$wpdb->delete( self::t(), array( 'id' => $account_id ), array( '%d' ) );
		return true;
	}

	/**
	 * Admin roster — STATUS/metadata only (who connected, their mode, whether
	 * they opted into admin search). NEVER tokens, NEVER message content.
	 *
	 * @return array
	 */
	public static function roster(): array {
		global $wpdb;
		$msgs = $wpdb->prefix . 'zib_messages';
		$rows = $wpdb->get_results(
			'SELECT a.owner_user_id, a.email_label, a.status, a.index_mode, a.admin_search_enabled, a.backfill_status, a.last_synced_at, a.connected_at,
			        (SELECT COUNT(*) FROM ' . $msgs . ' m WHERE m.account_id = a.id) AS indexed_count
			 FROM ' . self::t() . ' a ORDER BY a.owner_user_id' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$u     = get_userdata( (int) $r->owner_user_id );
			$out[] = array(
				'user_id'              => (int) $r->owner_user_id,
				'user'                 => $u ? $u->display_name : ( 'user #' . (int) $r->owner_user_id ),
				'email_label'          => (string) $r->email_label,
				'status'               => (string) $r->status,
				'index_mode'           => (string) $r->index_mode,
				'admin_search_enabled' => (bool) $r->admin_search_enabled,
				'backfill_status'      => (string) $r->backfill_status,
				'indexed_count'        => (int) $r->indexed_count,
				'last_synced_at'       => $r->last_synced_at ? (string) $r->last_synced_at : null,
				'connected_at'         => (string) $r->connected_at,
			);
		}
		return $out;
	}
}
