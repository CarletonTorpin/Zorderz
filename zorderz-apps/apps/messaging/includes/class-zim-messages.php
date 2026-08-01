<?php
/**
 * ZIM_Messages
 *
 * Message CRUD — post, edit (5-min window), soft-delete, fetch.
 *
 * WRITE PATH: post() → inserts row → ZIM_Mentions::reconcile() → returns
 * the set of newly-mentioned users → ZIM_Notifications::queue() fires a
 * push per user (respecting quiet hours).
 *
 * EDIT WINDOW (Trap 3 / acceptance #3):
 *   - Authors can edit for 5 minutes after send.
 *   - Edit re-runs mention parser, but ZIM_Mentions::reconcile() only
 *     triggers pushes for NEWLY-added users. Existing mentioned users get
 *     nothing. Removed mentions are audit-stamped but never notify.
 *
 * SOFT-DELETE (Trap 4 / acceptance #7):
 *   - Sets deleted_at + deleted_by_user_id.
 *   - Body preserved in the row for audit / subpoena / admin undelete.
 *   - Rendered body is "[deleted by user at HH:MM]".
 *   - Mention rows preserved (never physically removed). Preserves notification
 *     history integrity.
 *   - Attachment files purged 30 days later by cron (see ZIM_Attachments).
 *
 * NO-CACHE CONTRACT (acceptance #14):
 *   Messages are returned directly from wp_zim_messages on every poll. We
 *   do NOT write message bodies to transients, wp_cache_set(), or any
 *   persistent cache. This is checked by the acceptance suite.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIM_Messages {

	/**
	 * Post a new message.
	 *
	 * @param int    $conversation_id
	 * @param int    $author_user_id
	 * @param string $body
	 * @param int[]  $attachment_ids  wp_zim_attachments.id list (already uploaded)
	 * @return array|WP_Error  on success: [ 'message_id', 'mentioned_user_ids' ]
	 */
	public static function post( $conversation_id, $author_user_id, $body, $attachment_ids = array() ) {
		global $wpdb;

		$conversation_id = (int) $conversation_id;
		$author_user_id  = (int) $author_user_id;
		$body            = wp_unslash( (string) $body ); // Gotcha #6: undo magic quotes.
		// v1.0.27 (security): server-side sanitize at the single write chokepoint so the
		// AJAX path matches the REST /post path (which already wp_kses_post's the body).
		// Client-side DOMPurify (loaded from a CDN) is no longer the ONLY XSS guard —
		// a stored body can never carry <script>/on*-handlers even if that CDN fails.
		// Markdown chars (* _ # ` etc.) and safe formatting tags survive kses untouched.
		$body            = wp_kses_post( $body );

		// Membership check is the caller's job (AJAX gate).

		// v1.0.24 — Read-only roles (the shared kiosk `zdz_general`) can never
		// post. This is the single model-layer chokepoint every write funnels
		// through (AJAX zim_post, the REST /post route used by Brain Bot's
		// "post to #channel", and any future caller), so a forgotten gate at a
		// higher layer cannot re-open a send path for the shared account. This
		// is the structural fix the platform learned it needed after the
		// Session 406 autonomous-posting incident: remove the capability, don't
		// merely discourage it.
		if ( function_exists( 'zim_user_can_write' ) && ! zim_user_can_write( $author_user_id ) ) {
			return new WP_Error(
				'zim_read_only',
				'This account has read-only messaging access and cannot send messages.'
			);
		}

		// Announcements channel: admins only.
		$conv = $wpdb->get_row( $wpdb->prepare(
			"SELECT kind, is_announcements FROM {$wpdb->prefix}zim_conversations WHERE id = %d",
			$conversation_id
		) );
		if ( ! $conv ) {
			return new WP_Error( 'zim_no_conversation', 'Conversation not found.' );
		}
		if ( ! empty( $conv->is_announcements )
		     && ! ZIM_Membership::is_channel_admin( $author_user_id, $conversation_id ) ) {
			return new WP_Error( 'zim_announcements_admin_only', 'Only admins can post in #announcements.' );
		}

		// Empty bodies allowed only if there's at least one attachment.
		$trimmed = trim( $body );
		if ( '' === $trimmed && empty( $attachment_ids ) ) {
			return new WP_Error( 'zim_empty_message', 'Message is empty.' );
		}

		$now = current_time( 'mysql', true );

		$ok = $wpdb->insert(
			$wpdb->prefix . 'zim_messages',
			array(
				'conversation_id' => $conversation_id,
				'author_user_id'  => $author_user_id,
				'body'            => $body,
				'created_at'      => $now,
			),
			array( '%d','%d','%s','%s' )
		);
		if ( false === $ok ) {
			return new WP_Error( 'zim_insert_failed', 'Failed to store message.' );
		}
		$message_id = (int) $wpdb->insert_id;

		// Bump conversation last_message_at — drives sidebar ordering.
		$wpdb->update(
			$wpdb->prefix . 'zim_conversations',
			array( 'last_message_at' => $now ),
			array( 'id' => $conversation_id ),
			array( '%s' ),
			array( '%d' )
		);

		// Bind attachments to the message. Verify the caller owns them.
		if ( ! empty( $attachment_ids ) ) {
			ZIM_Attachments::bind_to_message( $attachment_ids, $message_id, $author_user_id );
		}

		// Author's read cursor advances automatically — they've "seen" their own msg.
		ZIM_Channels::mark_read( $conversation_id, $author_user_id, $message_id );

		// Mention pipeline — parse, filter to conversation members, reconcile, queue pushes.
		$mentioned_logins = ZIM_Mentions::parse( $body );
		$mentioned_ids    = ZIM_Mentions::resolve_for_conversation( $mentioned_logins, $conversation_id );
		$reconcile        = ZIM_Mentions::reconcile( $message_id, $mentioned_ids );

		// Queue pushes only for newly-added mentions (none on first-post since
		// reconcile is called fresh). Also queue a first-unread push per
		// non-author member when policy permits.
		if ( ! empty( $reconcile['added'] ) ) {
			foreach ( $reconcile['added'] as $uid ) {
				ZIM_Notifications::queue_mention( (int) $uid, $conversation_id, $message_id, $author_user_id );
			}
		}

		// First-unread push — fires ONLY if this is the first message in an
		// otherwise-read conversation for that user. Not per message (Trap 7
		// intent: not spammy).
		ZIM_Notifications::maybe_queue_first_unread( $conversation_id, $message_id, $author_user_id );

		// v1.0.11 — Action-only audit log for traceability.
		// We log that a send happened (actor, conversation_id, message_id,
		// byte-length, attachment-count, mention-count), but NEVER the
		// message body itself. This gives compliance/HR-type traceability
		// without storing conversation content in the audit stream.
		if ( class_exists( 'ZDZ_Admin_Dashboard' )
		     && method_exists( 'ZDZ_Admin_Dashboard', 'log_action' ) ) {
			ZDZ_Admin_Dashboard::log_action(
				(int) $author_user_id,
				'zim_message_sent',
				sprintf( 'Sent message in conversation #%d', $conversation_id ),
				'zdz-internal-messaging',
				array(
					'conversation_id' => (int) $conversation_id,
					'message_id'      => (int) $message_id,
					'body_bytes'      => strlen( (string) $body ),
					'attachment_count'=> is_array( $attachment_ids ) ? count( $attachment_ids ) : 0,
					'mention_count'   => is_array( $mentioned_ids ) ? count( $mentioned_ids ) : 0,
				)
			);
		}

		return array(
			'message_id'         => $message_id,
			'mentioned_user_ids' => $mentioned_ids,
		);
	}

	/**
	 * Edit an existing message. Only the author, only within the edit window.
	 * Re-runs mention parser; reconcile() determines who (if anyone) gets a
	 * fresh push.
	 */
	public static function edit( $message_id, $author_user_id, $new_body ) {
		global $wpdb;

		$new_body = wp_unslash( (string) $new_body );

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, conversation_id, author_user_id, created_at, deleted_at
			   FROM {$wpdb->prefix}zim_messages WHERE id = %d",
			(int) $message_id
		) );
		if ( ! $row ) {
			return new WP_Error( 'zim_not_found', 'Message not found.' );
		}
		if ( (int) $row->author_user_id !== (int) $author_user_id ) {
			return new WP_Error( 'zim_not_author', 'Only the author can edit.' );
		}
		if ( ! empty( $row->deleted_at ) ) {
			return new WP_Error( 'zim_deleted', 'Cannot edit deleted message.' );
		}
		$age = time() - strtotime( $row->created_at . ' UTC' );
		if ( $age > ZIM_EDIT_WINDOW_SECONDS ) {
			return new WP_Error( 'zim_edit_window_closed', 'Edit window has closed.' );
		}
		if ( '' === trim( $new_body ) ) {
			return new WP_Error( 'zim_empty_message', 'Message is empty.' );
		}

		$wpdb->update(
			$wpdb->prefix . 'zim_messages',
			array(
				'body'      => $new_body,
				'edited_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $message_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		// Mention reconciliation (Trap 3): only `added` fires pushes.
		$mentioned_logins = ZIM_Mentions::parse( $new_body );
		$mentioned_ids    = ZIM_Mentions::resolve_for_conversation( $mentioned_logins, (int) $row->conversation_id );
		$reconcile        = ZIM_Mentions::reconcile( (int) $message_id, $mentioned_ids );

		if ( ! empty( $reconcile['added'] ) ) {
			foreach ( $reconcile['added'] as $uid ) {
				ZIM_Notifications::queue_mention(
					(int) $uid,
					(int) $row->conversation_id,
					(int) $message_id,
					(int) $author_user_id
				);
			}
		}

		return array( 'message_id' => (int) $message_id, 'reconcile' => $reconcile );
	}

	/**
	 * Soft-delete. Authors delete their own; admins can delete any.
	 * Cancels pending queued pushes for this message (see Notifications).
	 */
	public static function soft_delete( $message_id, $actor_user_id ) {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, conversation_id, author_user_id, deleted_at
			   FROM {$wpdb->prefix}zim_messages WHERE id = %d",
			(int) $message_id
		) );
		if ( ! $row ) {
			return new WP_Error( 'zim_not_found', 'Message not found.' );
		}
		if ( ! empty( $row->deleted_at ) ) {
			return true; // already deleted — idempotent
		}

		$is_author = ( (int) $row->author_user_id === (int) $actor_user_id );
		$is_admin  = ZIM_Membership::is_channel_admin( $actor_user_id, (int) $row->conversation_id );
		if ( ! $is_author && ! $is_admin ) {
			return new WP_Error( 'zim_not_permitted', 'Not permitted to delete this message.' );
		}

		$wpdb->update(
			$wpdb->prefix . 'zim_messages',
			array(
				'deleted_at'         => current_time( 'mysql', true ),
				'deleted_by_user_id' => (int) $actor_user_id,
			),
			array( 'id' => (int) $message_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);

		// Cancel any queued-but-not-yet-fired notifications tied to this message.
		ZIM_Notifications::cancel_for_message( (int) $message_id );

		// Audit admin force-deletes (not self-deletes — those are user action).
		// Theme contract: ZDZ_Admin_Dashboard::log_action( $user_id, $action_type, $detail, $app_id, $meta )
		// — global namespace, not \Zorderz\.
		if ( $is_admin && ! $is_author ) {
			if ( class_exists( 'ZDZ_Admin_Dashboard' )
			     && method_exists( 'ZDZ_Admin_Dashboard', 'log_action' ) ) {
				ZDZ_Admin_Dashboard::log_action(
					(int) $actor_user_id,
					'zim_admin_force_delete_message',
					sprintf(
						'Admin force-deleted message %d in conversation %d (original author %d)',
						(int) $message_id,
						(int) $row->conversation_id,
						(int) $row->author_user_id
					),
					'zdz-internal-messaging',
					array(
						'message_id'      => (int) $message_id,
						'conversation_id' => (int) $row->conversation_id,
						'original_author' => (int) $row->author_user_id,
					)
				);
			}
		}

		return true;
	}

	/**
	 * Fetch up to $limit messages with id > $since_id in the given conversation.
	 * The polling endpoint calls this on every tick.
	 *
	 * Returns messages in chronological order. Each row is hydrated with
	 * author display name, attachments, and mentions — one extra query per
	 * (attachments / mentions) batch, N+1 avoided.
	 */
	public static function fetch_since( $conversation_id, $since_id, $limit = 50 ) {
		global $wpdb;

		$conversation_id = (int) $conversation_id;
		$since_id        = (int) $since_id;
		$limit           = max( 1, min( 50, (int) $limit ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, conversation_id, author_user_id, body,
			        created_at, edited_at, deleted_at, deleted_by_user_id
			   FROM {$wpdb->prefix}zim_messages
			  WHERE conversation_id = %d AND id > %d
			  ORDER BY id ASC
			  LIMIT %d",
			$conversation_id,
			$since_id,
			$limit
		), ARRAY_A );

		return self::hydrate( $rows );
	}

	/**
	 * Fetch a page of historical messages (scroll-back). Returns messages
	 * older than $before_id, newest-first, most-recent-first in the returned
	 * array (frontend reverses before prepending).
	 */
	public static function fetch_before( $conversation_id, $before_id, $limit = 50 ) {
		global $wpdb;

		$conversation_id = (int) $conversation_id;
		$before_id       = (int) $before_id;
		$limit           = max( 1, min( 50, (int) $limit ) );

		$where_before = $before_id > 0 ? $wpdb->prepare( 'AND id < %d', $before_id ) : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, conversation_id, author_user_id, body,
			        created_at, edited_at, deleted_at, deleted_by_user_id
			   FROM {$wpdb->prefix}zim_messages
			  WHERE conversation_id = %d {$where_before}
			  ORDER BY id DESC
			  LIMIT %d",
			$conversation_id,
			$limit
		), ARRAY_A );

		// Return chronological for the UI.
		$rows = array_reverse( $rows );
		return self::hydrate( $rows );
	}

	/**
	 * Hydrate raw message rows with author name, attachments, and mentions.
	 * Also handles the [deleted by user at HH:MM] display-body rewrite.
	 */
	private static function hydrate( $rows ) {
		if ( empty( $rows ) ) {
			return array();
		}

		// Collect ids and authors.
		$message_ids = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$author_ids  = array_unique( array_map( 'intval', wp_list_pluck( $rows, 'author_user_id' ) ) );

		// Batch-load display names.
		$authors = array();
		foreach ( $author_ids as $uid ) {
			$u = get_userdata( $uid );
			$authors[ $uid ] = array(
				'id'    => $uid,
				'name'  => $u ? $u->display_name : 'Unknown',
				'login' => $u ? $u->user_login   : '',
			);
		}

		// Batch attachments + mentions.
		$attachments_by_message = ZIM_Attachments::for_messages( $message_ids );
		$mentions_by_message    = ZIM_Mentions::for_messages( $message_ids );

		$out = array();
		foreach ( $rows as $r ) {
			$mid        = (int) $r['id'];
			$is_deleted = ! empty( $r['deleted_at'] );
			$body_out   = $is_deleted
				? self::deleted_placeholder( $r['deleted_at'] )
				: (string) $r['body'];

			// On soft-delete, hide attachments from UI but keep metadata.
			$attachments = $is_deleted ? array() : ( $attachments_by_message[ $mid ] ?? array() );

			$out[] = array(
				'id'              => $mid,
				'conversation_id' => (int) $r['conversation_id'],
				'author'          => $authors[ (int) $r['author_user_id'] ] ?? array(
					'id' => (int) $r['author_user_id'], 'name' => 'Unknown', 'login' => '',
				),
				'body'            => $body_out,
				'body_raw'        => $is_deleted ? '' : (string) $r['body'],
				'created_at'      => self::iso( $r['created_at'] ),
				'edited_at'       => $r['edited_at'] ? self::iso( $r['edited_at'] ) : null,
				'deleted'         => $is_deleted,
				'attachments'     => $attachments,
				'mentions'        => $mentions_by_message[ $mid ] ?? array(),
				'can_edit_until'  => $is_deleted
					? null
					: self::iso_from_ts( strtotime( $r['created_at'] . ' UTC' ) + ZIM_EDIT_WINDOW_SECONDS ),
			);
		}
		return $out;
	}

	private static function deleted_placeholder( $deleted_at_utc ) {
		$ts = strtotime( $deleted_at_utc . ' UTC' );
		if ( ! $ts ) {
			return '*[deleted by user]*';
		}
		// Localize to site timezone for display.
		$local = wp_date( 'H:i', $ts );
		return sprintf( '*[deleted by user at %s]*', $local );
	}

	private static function iso( $mysql_dt ) {
		if ( empty( $mysql_dt ) ) {
			return null;
		}
		$ts = strtotime( $mysql_dt . ' UTC' );
		return $ts ? gmdate( 'c', $ts ) : null;
	}

	private static function iso_from_ts( $ts ) {
		return $ts ? gmdate( 'c', $ts ) : null;
	}
}
