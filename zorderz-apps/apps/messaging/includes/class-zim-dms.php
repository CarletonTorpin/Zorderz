<?php
/**
 * ZIM_DMs
 *
 * One-to-one direct messages. No group DMs in MEP.
 *
 * DETERMINISTIC CONVERSATION ID (acceptance #12):
 *   Opening a DM from user 12 ↔ 47 always produces the same conversation_id,
 *   no matter who initiates. We normalize (user_a, user_b) = (MIN, MAX) and
 *   UNIQUE-key on the pair in wp_zim_conversations.
 *
 * On first open we create the conversation row + two member rows.
 * Subsequent opens just return the existing id.
 *
 * MEP LIMIT: no group DMs. If three people need to talk, they use a channel.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIM_DMs {

	/**
	 * Get-or-create a DM conversation between two users.
	 *
	 * v1.0.23: Self-DMs ($user_a === $user_b) are now allowed.
	 * Use case: "Notes to Self" and Brain Bot admin testing.
	 * The deterministic pair normalization still works for self-DMs:
	 * min(12,12) = 12, max(12,12) = 12, and the UNIQUE KEY
	 * idx_dm_pair(user_a, user_b) is satisfied.
	 *
	 * @param int $user_a  caller (for audit trail)
	 * @param int $user_b  counterpart (may equal $user_a for self-DM)
	 * @return int         conversation_id, or 0 on invalid input
	 */
	public static function get_or_create_conversation( $user_a, $user_b ) {
		$user_a = (int) $user_a;
		$user_b = (int) $user_b;
		if ( $user_a <= 0 || $user_b <= 0 ) {
			return 0;
		}

		// v1.0.24 — The initiator ($user_a, per the contract above) must have
		// write access. The shared kiosk (`zdz_general`) is read-only: it cannot
		// start a DM (or a self-DM "note") because doing so is a write. This
		// also closes the DM half of the Brain-Bot draft path for the kiosk —
		// even if a [ZIM_DM_DRAFT] somehow reached confirmation, the
		// conversation can't be created on this account.
		if ( function_exists( 'zim_user_can_write' ) && ! zim_user_can_write( $user_a ) ) {
			return 0;
		}

		// Both parties must be real users with access.
		// For self-DMs ($user_a === $user_b), check once.
		$uids_to_check = array_unique( array( $user_a, $user_b ) );
		foreach ( $uids_to_check as $uid ) {
			$u = get_userdata( $uid );
			if ( ! $u || ! zim_user_has_access( $uid ) ) {
				return 0;
			}
		}

		// Normalize (user_a, user_b) deterministically.
		$lo = min( $user_a, $user_b );
		$hi = max( $user_a, $user_b );

		global $wpdb;
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}zim_conversations
			  WHERE kind = 'dm' AND user_a = %d AND user_b = %d LIMIT 1",
			$lo,
			$hi
		) );
		if ( $existing ) {
			return (int) $existing;
		}

		// Create the conversation row.
		$ok = $wpdb->insert(
			$wpdb->prefix . 'zim_conversations',
			array(
				'kind'       => 'dm',
				'user_a'     => $lo,
				'user_b'     => $hi,
				'created_by' => $user_a,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%d', '%s' )
		);
		if ( false === $ok ) {
			return 0;
		}
		$conversation_id = (int) $wpdb->insert_id;

		// Member rows so membership checks treat DMs identically to channels.
		// For self-DMs ($lo === $hi), insert only one row to avoid hitting
		// the UNIQUE KEY idx_conv_user(conversation_id, user_id).
		ZIM_Channels::add_member( $conversation_id, $lo, 'member' );
		if ( $lo !== $hi ) {
			ZIM_Channels::add_member( $conversation_id, $hi, 'member' );
		}

		return $conversation_id;
	}

	/**
	 * List DM conversations for a user, joined with the counterpart's name.
	 *
	 * v1.0.23: Self-DMs (user_a === user_b === $user_id) are labelled
	 * "Notes to Self" and include an `is_self` flag so the frontend
	 * can style them differently.
	 *
	 * @return array [ [ 'id', 'other_user_id', 'other_name', 'is_self', 'unread', 'last_message_at' ], ... ]
	 */
	public static function list_for_user( $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.id, c.user_a, c.user_b, c.last_message_at,
			        m.last_read_message_id
			   FROM {$wpdb->prefix}zim_conversations c
			   JOIN {$wpdb->prefix}zim_members m ON m.conversation_id = c.id
			  WHERE c.kind = 'dm' AND m.user_id = %d
			  ORDER BY c.last_message_at DESC",
			$user_id
		), ARRAY_A );

		if ( ! $rows ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $r ) {
			$other_id = ( (int) $r['user_a'] === $user_id ) ? (int) $r['user_b'] : (int) $r['user_a'];
			$is_self  = ( $other_id === $user_id );
			$other    = get_userdata( $other_id );
			if ( ! $other ) {
				continue; // user deleted — skip, don't show orphan
			}
			$out[] = array(
				'id'              => (int) $r['id'],
				'other_user_id'   => $other_id,
				'other_name'      => $is_self ? 'Notes to Self' : $other->display_name,
				'other_login'     => $other->user_login,
				'is_self'         => $is_self,
				'last_message_at' => $r['last_message_at'],
				'unread'          => ZIM_Channels::unread_count(
					(int) $r['id'],
					(int) $r['last_read_message_id']
				),
			);
		}
		return $out;
	}

	/**
	 * Return the counterpart user_id in a DM, given the caller.
	 * Returns 0 if the caller isn't a participant.
	 *
	 * v1.0.23: For self-DMs (user_a === user_b), this correctly returns
	 * the user's own ID — the first branch matches and returns user_b
	 * which equals user_a. No special-casing needed.
	 */
	public static function other_party( $conversation_id, $user_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT user_a, user_b FROM {$wpdb->prefix}zim_conversations
			  WHERE id = %d AND kind = 'dm' LIMIT 1",
			(int) $conversation_id
		) );
		if ( ! $row ) {
			return 0;
		}
		$user_id = (int) $user_id;
		if ( (int) $row->user_a === $user_id ) {
			return (int) $row->user_b;
		}
		if ( (int) $row->user_b === $user_id ) {
			return (int) $row->user_a;
		}
		return 0;
	}
}
