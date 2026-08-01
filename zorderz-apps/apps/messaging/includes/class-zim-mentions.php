<?php
/**
 * ZIM_Mentions
 *
 * @mention parsing, resolution, reconciliation.
 *
 * KEY INVARIANT (Trap 3):
 *   When a message is edited, we re-parse mentions but ONLY newly-added
 *   mentions fire a push. Existing mentions get nothing (no re-notification).
 *   Removed mentions are audit-stamped but NEVER notified ("you were
 *   un-mentioned" is user-hostile noise).
 *
 * RECONCILE FLOW:
 *   1. parse($body)  → list of @lognames found in the text
 *   2. resolve_for_conversation($lognames, $convo_id)
 *                    → user_ids filtered to ones with zdz_access_app AND
 *                      who are members of the conversation
 *   3. reconcile($message_id, $new_user_ids)
 *                    → diff against existing mention rows → { added, removed }
 *                      added gets an INSERT; removed gets an UPDATE of
 *                      removed_at; neither physically deletes.
 *
 * SOFT-DELETE INTERACTION (Trap 4):
 *   When a parent message is soft-deleted, mention rows are preserved. A
 *   user's "your mentions" history still contains those entries; clicking
 *   the notification lands on the [deleted by user] placeholder.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIM_Mentions {

	/**
	 * Extract candidate @lognames from a message body.
	 *
	 * Pattern: @login where login is [a-z0-9._-]+, preceded by whitespace
	 * or start-of-string. (Prevents e.g. email addresses from matching.)
	 */
	public static function parse( $body ) {
		$out = array();
		if ( ! is_string( $body ) || '' === $body ) {
			return $out;
		}
		if ( preg_match_all( '/(?:^|\s)@([a-z0-9._-]+)/i', $body, $m ) ) {
			foreach ( $m[1] as $login ) {
				$out[] = strtolower( $login );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Resolve login strings → user_ids, constrained to members of the given
	 * conversation. Non-members in @-mentions are silently skipped — we
	 * don't notify someone who can't even see the channel.
	 */
	public static function resolve_for_conversation( array $logins, $conversation_id ) {
		$out = array();
		foreach ( $logins as $login ) {
			$u = get_user_by( 'login', $login );
			if ( ! $u ) {
				continue;
			}
			if ( ! zim_user_has_access( $u->ID ) ) {
				continue;
			}
			if ( ! ZIM_Membership::is_member( $u->ID, $conversation_id ) ) {
				continue;
			}
			$out[] = (int) $u->ID;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Reconcile the mention set for a message.
	 *
	 * @param int   $message_id
	 * @param int[] $new_user_ids
	 * @return array [ 'added' => int[], 'removed' => int[] ]
	 */
	public static function reconcile( $message_id, array $new_user_ids ) {
		global $wpdb;
		$message_id  = (int) $message_id;
		$tbl         = $wpdb->prefix . 'zim_mentions';

		// Existing set — only rows not already flagged removed_at (those were
		// un-mentioned on a previous edit and are kept for audit).
		$existing = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT mentioned_user_id FROM {$tbl}
			  WHERE message_id = %d AND removed_at IS NULL",
			$message_id
		) ) );

		$new_user_ids = array_map( 'intval', $new_user_ids );
		$added        = array_values( array_diff( $new_user_ids, $existing ) );
		$removed      = array_values( array_diff( $existing, $new_user_ids ) );

		$now = current_time( 'mysql', true );

		foreach ( $added as $uid ) {
			// May collide with a previously-removed row for this (msg, user) pair
			// (user was mentioned, un-mentioned, then re-mentioned). Re-enable it.
			$existing_row = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$tbl} WHERE message_id = %d AND mentioned_user_id = %d LIMIT 1",
				$message_id,
				$uid
			) );
			if ( $existing_row ) {
				$wpdb->update(
					$tbl,
					array( 'removed_at' => null ),
					array( 'id' => (int) $existing_row ),
					array( '%s' ),
					array( '%d' )
				);
			} else {
				$wpdb->insert(
					$tbl,
					array(
						'message_id'        => $message_id,
						'mentioned_user_id' => $uid,
						'created_at'        => $now,
					),
					array( '%d', '%d', '%s' )
				);
			}
		}

		foreach ( $removed as $uid ) {
			$wpdb->update(
				$tbl,
				array( 'removed_at' => $now ),
				array(
					'message_id'        => $message_id,
					'mentioned_user_id' => $uid,
				),
				array( '%s' ),
				array( '%d', '%d' )
			);
		}

		return array( 'added' => $added, 'removed' => $removed );
	}

	/**
	 * Load active mentions for many messages in one query.
	 * Returns map: message_id → [ { user_id, login, name } ].
	 *
	 * Includes removed rows? No — removed rows are audit-only, not UI.
	 */
	public static function for_messages( array $message_ids ) {
		global $wpdb;
		$message_ids = array_values( array_unique( array_map( 'intval', $message_ids ) ) );
		if ( empty( $message_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $message_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT m.message_id, m.mentioned_user_id, u.user_login, u.display_name
			   FROM {$wpdb->prefix}zim_mentions m
			   LEFT JOIN {$wpdb->users} u ON u.ID = m.mentioned_user_id
			  WHERE m.message_id IN ({$placeholders}) AND m.removed_at IS NULL",
			...$message_ids
		), ARRAY_A );

		$out = array();
		foreach ( $rows as $r ) {
			$mid = (int) $r['message_id'];
			if ( ! isset( $out[ $mid ] ) ) {
				$out[ $mid ] = array();
			}
			$out[ $mid ][] = array(
				'user_id' => (int) $r['mentioned_user_id'],
				'login'   => (string) $r['user_login'],
				'name'    => (string) $r['display_name'],
			);
		}
		return $out;
	}

	/**
	 * Candidate list for the @-autocomplete popup — users the author can
	 * legitimately @mention in this conversation (members only).
	 *
	 * Returns up to $limit matches, sorted by display_name.
	 */
	public static function autocomplete_candidates( $conversation_id, $query = '', $limit = 10 ) {
		global $wpdb;

		$conversation_id = (int) $conversation_id;
		$limit           = max( 1, min( 25, (int) $limit ) );
		$q_like          = '%' . $wpdb->esc_like( trim( $query ) ) . '%';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT u.ID, u.user_login, u.display_name
			   FROM {$wpdb->prefix}zim_members m
			   JOIN {$wpdb->users} u ON u.ID = m.user_id
			  WHERE m.conversation_id = %d
			    AND ( u.user_login LIKE %s OR u.display_name LIKE %s )
			  ORDER BY u.display_name ASC
			  LIMIT %d",
			$conversation_id,
			$q_like,
			$q_like,
			$limit
		), ARRAY_A );

		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'user_id' => (int) $r['ID'],
				'login'   => (string) $r['user_login'],
				'name'    => (string) $r['display_name'],
			);
		}
		return $out;
	}
}
