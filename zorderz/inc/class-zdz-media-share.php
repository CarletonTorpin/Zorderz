<?php
/**
 * ZDZ_Media_Share — per-recipient media share grants.
 *
 * Lets one person share specific zdz_user_media items with another person on the team (shown as a
 * gallery card in a direct message). This is the ACCOUNT-BASED authorization layer that sits UNDER
 * the tokened media proxy: possession of a /?zdz_media=<id>&t=<token> link is never sufficient for a
 * private item — the viewer must be the owner, an admin, or hold a live grant recorded here. Grants
 * are revocable and fail closed.
 *
 * ── SECURITY CONTRACT ────────────────────────────────────────────────────────
 *  - create() authorizes the SHARER against every media id (owner or admin); it inserts nothing on
 *    any miss (fail closed) — no broken object-level authorization on the write path.
 *  - viewer_has_grant() is the single grant predicate; ZDZ_User_Media::serve() (thumb AND full), the
 *    "Shared With Me" scope and the message card all resolve through it, so a grant can't be honored
 *    on one surface and missed on another.
 *  - get_share() authorizes the VIEWER (sender / recipient / admin) before it returns anything — a
 *    share marker in a message body is untrusted, so a pasted marker renders nothing for a stranger.
 *  - revoke() blanks the grant; serve() re-checks on every request and a non-owner private response
 *    is sent no-store, so revocation is immediate.
 *  - The shared device can neither share nor receive.
 *  - Every share/revoke is written to the log and, when present, the platform audit rail.
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ZDZ_Media_Share {

	/** Table name (without prefix). */
	const TABLE = 'zdz_media_shares';

	/** Schema version — bump when the CREATE/ALTER shape changes. */
	const SCHEMA_VERSION = 1;

	/** Option key that gates the ensure_table() fast path. */
	const SCHEMA_OPT = 'zdz_media_shares_schema';

	/** Max stored length of the optional share note. */
	const NOTE_MAX = 500;

	/**
	 * Create the grants table on first use (mirrors ZDZ_User_Media::ensure_table:
	 * static + option fast path, idempotent CREATE, only stamps the option on a
	 * clean run so a half-applied migration retries).
	 */
	public static function ensure_table(): void {
		static $verified = false;
		if ( $verified ) { return; }
		if ( (int) get_option( self::SCHEMA_OPT, 0 ) >= self::SCHEMA_VERSION ) { $verified = true; return; }

		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is $wpdb->prefix plus the class constant TABLE
			"CREATE TABLE IF NOT EXISTS `{$table}` (
				`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				`share_group` varchar(64) NOT NULL DEFAULT '',
				`media_id` bigint(20) unsigned NOT NULL,
				`owner_id` bigint(20) unsigned NOT NULL DEFAULT 0,
				`shared_by` bigint(20) unsigned NOT NULL,
				`recipient_id` bigint(20) unsigned NOT NULL,
				`note` text,
				`conversation_id` bigint(20) unsigned NOT NULL DEFAULT 0,
				`created_at` datetime NOT NULL,
				`revoked_at` datetime NULL,
				`expires_at` datetime NULL,
				PRIMARY KEY (`id`),
				KEY `idx_recipient` (`recipient_id`,`revoked_at`),
				KEY `idx_grant` (`media_id`,`recipient_id`,`revoked_at`),
				KEY `idx_group` (`share_group`),
				KEY `idx_sender` (`shared_by`,`revoked_at`)
			) {$charset};" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$charset} is $wpdb->get_charset_collate()
		);
		if ( $wpdb->last_error ) {
			error_log( 'ZDZ_Media_Share::ensure_table failed, NOT stamping schema: ' . $wpdb->last_error );
			return;
		}
		update_option( self::SCHEMA_OPT, self::SCHEMA_VERSION, true );
		$verified = true;
	}

	/** A non-personal account: the shared device, or no account at all. */
	public static function is_kiosk( int $uid ): bool {
		if ( $uid <= 0 ) { return true; }
		$u = get_userdata( $uid );
		if ( ! $u || in_array( 'zdz_general', (array) $u->roles, true ) ) { return true; }
		if ( class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) && ZDZ_Hierarchy::is_kiosk( $uid ) ) { return true; }
		return class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid );
	}

	/** May $sender share this item? Its owner, or someone who may see others' media. Pure but for the ladder lookup. */
	public static function may_share_item( int $sender, array $row ): bool {
		if ( $sender <= 0 || empty( $row ) ) { return false; }
		return (int) ( $row['user_id'] ?? 0 ) === $sender || ZDZ_User_Media::viewer_can_view_others( $sender );
	}

	/** May $viewer open a share? Its sender, its recipient, or someone who may see others' media. */
	public static function may_view_share( int $viewer, int $sender, int $recipient ): bool {
		if ( $viewer <= 0 ) { return false; }
		return $viewer === $sender || $viewer === $recipient || ZDZ_User_Media::viewer_can_view_others( $viewer );
	}

	/** May $actor revoke a share? Its sender, or someone who may see others' media. */
	public static function may_revoke( int $actor, int $sender ): bool {
		return $actor > 0 && $sender > 0 && ( $actor === $sender || ZDZ_User_Media::viewer_can_view_others( $actor ) );
	}

	/** Is a stored grant live at $now (site-local 'Y-m-d H:i:s')? Not revoked, not expired. */
	public static function grant_live( array $grant, string $now ): bool {
		if ( ! empty( $grant['revoked_at'] ) ) { return false; }
		$exp = (string) ( $grant['expires_at'] ?? '' );
		return '' === $exp || $exp > $now;
	}

	/** May this user take part in sharing at all (real, non-kiosk account). */
	public static function is_shareable_actor( int $uid ): bool {
		return $uid > 0 && ! self::is_kiosk( $uid );
	}

	/**
	 * THE grant predicate. True iff $viewer holds a live (non-revoked, unexpired)
	 * grant for $media_id. Cheap (covered by idx_grant) and tolerant of a
	 * not-yet-created table.
	 */
	public static function viewer_has_grant( int $viewer_id, int $media_id ): bool {
		if ( $viewer_id <= 0 || $media_id <= 0 ) { return false; }
		self::ensure_table();
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$now   = current_time( 'mysql' );
		$hit   = $wpdb->get_var( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is $wpdb->prefix plus the class constant TABLE
			"SELECT 1 FROM {$table}
			  WHERE media_id = %d AND recipient_id = %d
			    AND revoked_at IS NULL
			    AND ( expires_at IS NULL OR expires_at > %s )
			  LIMIT 1",
			$media_id, $viewer_id, $now
		) );
		return (bool) $hit;
	}

	/**
	 * Create a share set. Authorizes the sender against EVERY media id (owner or
	 * admin) and inserts nothing on any miss (fail closed). Expands a shared
	 * video to its whole reel. Dedupes an identical active share to the same
	 * recipient. Returns ['share_group','media_ids','count','recipient_id'] or a
	 * WP_Error.
	 *
	 * @param int    $sender          Authenticated actor (resolved server-side by the caller).
	 * @param int    $recipient       Recipient user id.
	 * @param int[]  $media_ids       zdz_user_media ids to share.
	 * @param string $note            Optional note (stored, capped, sanitized).
	 * @param int    $conversation_id DM conversation id (for the render-time participant check).
	 * @return array|WP_Error
	 */
	public static function create( int $sender, int $recipient, array $media_ids, string $note = '', int $conversation_id = 0 ) {
		self::ensure_table();
		$sender    = (int) $sender;
		$recipient = (int) $recipient;

		if ( ! self::is_shareable_actor( $sender ) )    { return new WP_Error( 'zdz_ms_bad_sender',    __( 'You cannot share from this account.', 'zorderz' ) ); }
		if ( ! self::is_shareable_actor( $recipient ) ) { return new WP_Error( 'zdz_ms_bad_recipient', __( 'That recipient cannot receive shared media.', 'zorderz' ) ); }
		if ( $sender === $recipient )                   { return new WP_Error( 'zdz_ms_self',          __( 'You cannot share media with yourself.', 'zorderz' ) ); }

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $media_ids ) ) ) );
		if ( empty( $ids ) ) { return new WP_Error( 'zdz_ms_empty', __( 'No media selected.', 'zorderz' ) ); }

		// Authorize the sender against each item — the write-path BOLA gate.
		$rows = array();
		foreach ( $ids as $mid ) {
			$row = ZDZ_User_Media::get_by_id( $mid );
			if ( ! $row ) { return new WP_Error( 'zdz_ms_missing', __( 'One or more items no longer exist.', 'zorderz' ) ); }
			if ( ! self::may_share_item( $sender, $row ) ) { return new WP_Error( 'zdz_ms_forbidden', __( 'You can only share media you own.', 'zorderz' ) ); }
			$rows[ $mid ] = $row;
		}

		// A shared video brings its whole reel so playback stays coherent.
		$rows = self::expand_reels( $rows );

		// Reuse an identical, still-active share to the same recipient.
		$sig      = self::signature( array_keys( $rows ) );
		$existing = self::find_active_group( $sender, $recipient, $sig );
		if ( '' !== $existing ) {
			if ( $conversation_id > 0 ) { self::attach_conversation( $existing, $conversation_id ); }
			return array(
				'share_group'  => $existing,
				'media_ids'    => array_map( 'intval', array_keys( $rows ) ),
				'count'        => count( $rows ),
				'recipient_id' => $recipient,
				'deduped'      => true,
			);
		}

		$group = ( class_exists( 'ZDZ_Share_Link' ) && method_exists( 'ZDZ_Share_Link', 'mint_opaque' ) )
			? ZDZ_Share_Link::mint_opaque( 16 )
			: bin2hex( random_bytes( 16 ) );
		if ( '' === $group ) { return new WP_Error( 'zdz_ms_token', __( 'Could not create a secure share.', 'zorderz' ) ); }

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$now   = current_time( 'mysql' );
		$note  = mb_substr( sanitize_textarea_field( $note ), 0, self::NOTE_MAX );

		foreach ( $rows as $mid => $row ) {
			$wpdb->insert(
				$table,
				array(
					'share_group'     => $group,
					'media_id'        => (int) $mid,
					'owner_id'        => (int) $row['user_id'],
					'shared_by'       => $sender,
					'recipient_id'    => $recipient,
					'note'            => $note,
					'conversation_id' => (int) $conversation_id,
					'created_at'      => $now,
					'revoked_at'      => null,
					'expires_at'      => null,
				),
				array( '%s', '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s' )
			);
		}

		self::audit( 'media_share', $sender, array(
			'recipient' => $recipient,
			'group'     => $group,
			'media_ids' => array_map( 'intval', array_keys( $rows ) ),
			'count'     => count( $rows ),
		) );

		return array(
			'share_group'  => $group,
			'media_ids'    => array_map( 'intval', array_keys( $rows ) ),
			'count'        => count( $rows ),
			'recipient_id' => $recipient,
		);
	}

	/** Record the DM conversation on a group (used for the render-time participant check). */
	public static function attach_conversation( string $group, int $conversation_id ): void {
		if ( '' === $group || $conversation_id <= 0 ) { return; }
		self::ensure_table();
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$wpdb->update( $table, array( 'conversation_id' => $conversation_id ), array( 'share_group' => $group ), array( '%d' ), array( '%s' ) );
	}

	/** Revoke a whole share group. Actor must be the sender or an admin. */
	public static function revoke( int $actor, string $group ): bool {
		self::ensure_table();
		$actor = (int) $actor;
		if ( '' === $group || $actor <= 0 ) { return false; }
		global $wpdb;
		$table  = $wpdb->prefix . self::TABLE;
		$sender = (int) $wpdb->get_var( $wpdb->prepare( "SELECT shared_by FROM {$table} WHERE share_group = %s LIMIT 1", $group ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is $wpdb->prefix plus the class constant TABLE
		if ( ! $sender ) { return false; }
		if ( ! self::may_revoke( $actor, $sender ) ) { return false; }
		$now = current_time( 'mysql' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET revoked_at = %s WHERE share_group = %s AND revoked_at IS NULL", $now, $group ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is $wpdb->prefix plus the class constant TABLE
		self::audit( 'media_unshare', $actor, array( 'group' => $group ) );
		return true;
	}

	/**
	 * Fetch a share for a viewer. Authorizes viewer ∈ {sender, recipient, admin}
	 * BEFORE returning anything (the marker is untrusted). Deleted media drop out.
	 * Returns a normalized payload or a WP_Error.
	 */
	public static function get_share( string $group, int $viewer ) {
		self::ensure_table();
		$viewer = (int) $viewer;
		if ( '' === $group || $viewer <= 0 ) { return new WP_Error( 'zdz_ms_bad', __( 'Not found.', 'zorderz' ) ); }
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$now   = current_time( 'mysql' );
		$meta  = $wpdb->get_row( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is $wpdb->prefix plus the class constant TABLE
			"SELECT share_group, shared_by, recipient_id, note, conversation_id, created_at FROM {$table}
			  WHERE share_group = %s AND revoked_at IS NULL AND ( expires_at IS NULL OR expires_at > %s )
			  ORDER BY id ASC LIMIT 1",
			$group, $now
		), ARRAY_A );
		if ( ! $meta ) { return new WP_Error( 'zdz_ms_gone', __( 'This share is no longer available.', 'zorderz' ) ); }

		$sender    = (int) $meta['shared_by'];
		$recipient = (int) $meta['recipient_id'];
		$is_admin  = ZDZ_User_Media::viewer_can_view_others( $viewer );
		if ( ! self::may_view_share( $viewer, $sender, $recipient ) ) {
			return new WP_Error( 'zdz_ms_forbidden', __( 'You do not have access to this share.', 'zorderz' ) );
		}

		$mids  = $wpdb->get_col( $wpdb->prepare(
			"SELECT media_id FROM {$table} WHERE share_group = %s AND revoked_at IS NULL", $group // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is $wpdb->prefix plus the class constant TABLE
		) );
		$items = array();
		foreach ( (array) $mids as $mid ) {
			$row = ZDZ_User_Media::get_by_id( (int) $mid );
			if ( ! $row ) { continue; } // deleted media drop out
			$items[] = array(
				'id'            => (int) $row['id'],
				'title'         => (string) ( $row['title'] ?? '' ),
				'media_type'    => (string) ( $row['media_type'] ?? 'photo' ),
				'file_url'      => (string) ( $row['file_url'] ?? '' ),
				'thumbnail_url' => (string) ( $row['thumbnail_url'] ?? '' ),
			);
		}

		$su = get_userdata( $sender );
		return array(
			'share_group'     => $group,
			'shared_by'       => $sender,
			'shared_by_name'  => $su ? $su->display_name : __( 'A teammate', 'zorderz' ),
			'recipient_id'    => $recipient,
			'conversation_id' => (int) $meta['conversation_id'],
			'note'            => (string) $meta['note'],
			'created_at'      => (string) $meta['created_at'],
			'items'           => $items,
			'count'           => count( $items ),
			'can_revoke'      => ( $viewer === $sender || $is_admin ),
		);
	}

	/* ── internals ─────────────────────────────────────────────────────────── */

	/** Add every sibling clip of a shared video's reel (same owner, same reel_id). */
	private static function expand_reels( array $rows ): array {
		global $wpdb;
		$mtable = $wpdb->prefix . ZDZ_User_Media::TABLE;
		foreach ( array_values( $rows ) as $row ) {
			if ( 'video' !== (string) ( $row['media_type'] ?? '' ) ) { continue; }
			$meta = json_decode( (string) ( $row['meta_json'] ?? '' ), true );
			$reel = is_array( $meta ) ? (string) ( $meta['reel_id'] ?? '' ) : '';
			if ( '' === $reel ) { continue; }
			$needle = '%' . $wpdb->esc_like( '"reel_id":"' . $reel . '"' ) . '%';
			$sibs   = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$mtable} WHERE user_id = %d AND media_type = 'video' AND meta_json LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$mtable} is $wpdb->prefix plus ZDZ_User_Media::TABLE, a class constant
				(int) $row['user_id'], $needle
			), ARRAY_A );
			foreach ( (array) $sibs as $s ) {
				$sid = (int) $s['id'];
				if ( ! isset( $rows[ $sid ] ) ) { $rows[ $sid ] = $s; }
			}
		}
		return $rows;
	}

	/** Order-independent signature of a media-id set (for dedupe). */
	public static function signature( array $media_ids ): string {
		$ids = array_map( 'intval', $media_ids );
		sort( $ids );
		return md5( implode( ',', $ids ) );
	}

	/** Find an active share group from sender→recipient whose media set matches $sig. */
	private static function find_active_group( int $sender, int $recipient, string $sig ): string {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$now   = current_time( 'mysql' );
		$groups = $wpdb->get_col( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is $wpdb->prefix plus the class constant TABLE
			"SELECT DISTINCT share_group FROM {$table}
			  WHERE shared_by = %d AND recipient_id = %d AND revoked_at IS NULL
			    AND ( expires_at IS NULL OR expires_at > %s )",
			$sender, $recipient, $now
		) );
		foreach ( (array) $groups as $g ) {
			$mids = $wpdb->get_col( $wpdb->prepare(
				"SELECT media_id FROM {$table} WHERE share_group = %s AND revoked_at IS NULL", $g // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is $wpdb->prefix plus the class constant TABLE
			) );
			if ( self::signature( $mids ) === $sig ) { return (string) $g; }
		}
		return '';
	}

	/** Write a share/revoke to the backend log + the platform audit rail (guarded). */
	private static function audit( string $action, int $actor, array $ctx ): void {
		error_log( sprintf( 'ZDZ_Media_Share %s by user %d: %s', $action, $actor, wp_json_encode( $ctx ) ) );
		if ( class_exists( 'ZDZ_User_Log' ) && method_exists( 'ZDZ_User_Log', 'log' ) ) {
			$detail = isset( $ctx['group'] ) ? ( 'group ' . $ctx['group'] ) : '';
			if ( isset( $ctx['recipient'] ) ) { $detail .= ' -> user ' . (int) $ctx['recipient']; }
			if ( isset( $ctx['count'] ) )     { $detail .= ' (' . (int) $ctx['count'] . ' items)'; }
			ZDZ_User_Log::log( $actor, $action, trim( $detail ), 'zdz-media', $ctx );
		}
	}
}
