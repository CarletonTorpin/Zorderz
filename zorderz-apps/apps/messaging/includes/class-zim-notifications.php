<?php
/**
 * ZIM_Notifications
 *
 * Notification queue and dispatch.
 *
 * TRAP 7 — QUIET HOURS MUST DEFER, NOT DROP:
 *   If a push would fire during a user's quiet hours, we store it in
 *   wp_zim_notification_queue with release_at = end-of-quiet-hours. The
 *   per-minute cron picks it up at that time. Multiple queued items collapse
 *   into a single digest push at release time (acceptance #6).
 *
 * TRAP 6 — FIRE POLICY:
 *   Pushes fire ONLY on:
 *     (a) @mentions, OR
 *     (b) the first unread message in an otherwise-read conversation.
 *   Not on every incoming message. Queue-level dedup keeps it quiet.
 *
 * EMAIL FALLBACK (acceptance #5):
 *   Users who declined Web Push (zero subscription rows) receive an email
 *   digest instead. Throttled to once per 30 minutes per (user, conversation).
 *
 * SOFT-DELETE CANCELLATION (Trap 4):
 *   When a message is soft-deleted, its queued-but-not-yet-fired notifications
 *   are cancelled (cancelled_at set). Pushes we already fired can't be
 *   recalled — that's fine; the user clicks through and sees the placeholder.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIM_Notifications {

	const OPT_QUIET_HOURS_PREFIX = 'zim_quiet_hours_';
	const META_LAST_EMAIL_PREFIX = 'zim_last_email_convo_';

	/**
	 * Queue a push for an @mention.
	 */
	public static function queue_mention( $user_id, $conversation_id, $message_id, $author_user_id ) {
		$payload = array(
			'kind'           => 'mention',
			'author_user_id' => (int) $author_user_id,
		);
		self::queue( (int) $user_id, (int) $conversation_id, (int) $message_id, 'mention', $payload );
	}

	/**
	 * Queue a "first unread in this conversation" push for every non-author
	 * member who was caught up (last_read_message_id == previous head).
	 */
	public static function maybe_queue_first_unread( $conversation_id, $new_message_id, $author_user_id ) {
		global $wpdb;
		$conversation_id = (int) $conversation_id;
		$author_user_id  = (int) $author_user_id;
		$new_message_id  = (int) $new_message_id;

		// Find members who were caught up — their last_read_message_id is the
		// most recent message before this one (or 0 and no messages existed).
		$prev_max = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(MAX(id), 0) FROM {$wpdb->prefix}zim_messages
			  WHERE conversation_id = %d AND id < %d",
			$conversation_id,
			$new_message_id
		) );

		$caught_up = $wpdb->get_col( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->prefix}zim_members
			  WHERE conversation_id = %d
			    AND user_id <> %d
			    AND last_read_message_id >= %d",
			$conversation_id,
			$author_user_id,
			$prev_max
		) );

		foreach ( $caught_up as $uid ) {
			self::queue( (int) $uid, $conversation_id, $new_message_id, 'first_unread', array(
				'kind'           => 'first_unread',
				'author_user_id' => $author_user_id,
			) );
		}
	}

	/**
	 * Queue a notification. Computes release_at based on the recipient's
	 * quiet hours.
	 */
	public static function queue( $user_id, $conversation_id, $message_id, $kind, array $payload ) {
		global $wpdb;
		$release_at = self::compute_release_at( $user_id );

		$wpdb->insert(
			$wpdb->prefix . 'zim_notification_queue',
			array(
				'user_id'         => $user_id,
				'conversation_id' => $conversation_id,
				'message_id'      => $message_id,
				'kind'            => $kind,
				'release_at'      => $release_at,
				'created_at'      => current_time( 'mysql', true ),
				'payload_json'    => wp_json_encode( $payload ),
			),
			array( '%d','%d','%d','%s','%s','%s','%s' )
		);
	}

	/**
	 * Cancel pending (not-yet-fired) notifications for a message. Used when
	 * the message is soft-deleted before its push left the queue.
	 */
	public static function cancel_for_message( $message_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}zim_notification_queue
			    SET cancelled_at = NOW()
			  WHERE message_id = %d AND fired_at IS NULL AND cancelled_at IS NULL",
			(int) $message_id
		) );
	}

	/**
	 * Cron tick (every minute): send all due, un-fired, un-cancelled jobs.
	 * Merges jobs for same (user, conversation) into single digest pushes.
	 */
	public static function dispatch_due() {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT id, user_id, conversation_id, message_id, kind, payload_json
			   FROM {$wpdb->prefix}zim_notification_queue
			  WHERE release_at <= UTC_TIMESTAMP()
			    AND fired_at IS NULL
			    AND cancelled_at IS NULL
			  ORDER BY user_id, conversation_id, id
			  LIMIT 500",
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			return;
		}

		// Group by (user, conversation).
		$groups = array();
		foreach ( $rows as $r ) {
			$key = $r['user_id'] . ':' . $r['conversation_id'];
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array();
			}
			$groups[ $key ][] = $r;
		}

		foreach ( $groups as $group ) {
			self::fire_group( $group );
		}
	}

	/**
	 * Send one group of queued notifications for a single (user,conversation)
	 * as either a single push or a digest, depending on count.
	 */
	private static function fire_group( array $group ) {
		global $wpdb;
		$user_id         = (int) $group[0]['user_id'];
		$conversation_id = (int) $group[0]['conversation_id'];

		// Verify the recipient still has access and is still a member.
		// (Guards race: user removed from channel while their push was queued.)
		if ( ! zim_user_has_access( $user_id )
		     || ! ZIM_Membership::is_member( $user_id, $conversation_id ) ) {
			self::mark_fired( wp_list_pluck( $group, 'id' ) );
			return;
		}

		$conv  = ZIM_Channels::get( $conversation_id );
		$label = self::conversation_label( $conv, $user_id );

		$mention_count = 0; $unread_count = 0;
		foreach ( $group as $r ) {
			if ( 'mention' === $r['kind'] ) { $mention_count++; }
			if ( 'first_unread' === $r['kind'] ) { $unread_count++; }
		}

		if ( count( $group ) === 1 ) {
			$r   = $group[0];
			$p   = json_decode( (string) $r['payload_json'], true ) ?: array();
			$author = get_userdata( (int) ( $p['author_user_id'] ?? 0 ) );
			$author_name = $author ? $author->display_name : 'Someone';

			$push_payload = array(
				'title' => 'mention' === $r['kind']
					? $author_name . ' mentioned you in ' . $label
					: 'New message in ' . $label,
				'body'  => 'Tap to open.',
				'url'   => self::deep_link_url( $conversation_id ),
				'tag'   => 'zim-' . $conversation_id,
				'conversation_id' => $conversation_id,
			);
		} else {
			// Digest.
			$parts = array();
			if ( $mention_count > 0 ) {
				$parts[] = $mention_count . ' ' . _n( 'mention', 'mentions', $mention_count, 'zdz-internal-messaging' );
			}
			if ( $unread_count > 0 ) {
				$parts[] = $unread_count . ' ' . _n( 'message', 'messages', $unread_count, 'zdz-internal-messaging' );
			}
			$push_payload = array(
				'title' => 'New activity in ' . $label,
				'body'  => implode( ' · ', $parts ),
				'url'   => self::deep_link_url( $conversation_id ),
				'tag'   => 'zim-' . $conversation_id,
				'conversation_id' => $conversation_id,
			);
		}

		$result = ZIM_Push::send_to_user( $user_id, $push_payload );

		// ── Email delivery ─────────────────────────────────────────
		// v1.1.0 — DMs email the recipient the ACTUAL message text (plus a
		// reply address that routes a reply back into the DM) REGARDLESS of
		// push-subscription status, because the whole point is reply-by-email.
		// Channels keep the original behaviour: an email digest ONLY as a
		// fallback for users with no active push subscription.
		$is_dm = ( $conv && isset( $conv->kind ) && 'dm' === $conv->kind );

		self::dbg( sprintf(
			'fire_group: user=%d conv=%d kind=%s group_size=%d',
			$user_id, $conversation_id, ( $conv && isset( $conv->kind ) ? $conv->kind : '??' ), count( $group )
		) );

		if ( $is_dm ) {
			// One rich DM email covering this (user, conversation) group. Quiet
			// hours are already honoured — these jobs only fire once release_at
			// has passed. Per-conversation 30-min cooldown still applies so a
			// burst of DMs doesn't fan out into a pile of emails.
			self::maybe_send_dm_email( $user_id, $conversation_id, $group, $label );
		} else {
			// Channel fallback (unchanged): email only when there is no push.
			$has_push = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}zim_push_subscriptions WHERE user_id = %d",
				$user_id
			) );
			if ( 0 === $has_push ) {
				self::maybe_send_email( $user_id, $conversation_id, $push_payload );
			}
		}

		self::mark_fired( wp_list_pluck( $group, 'id' ) );
	}

	/**
	 * Mark queue rows as fired.
	 */
	private static function mark_fired( array $ids ) {
		global $wpdb;
		$ids = array_map( 'intval', $ids );
		if ( empty( $ids ) ) {
			return;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}zim_notification_queue
			    SET fired_at = NOW()
			  WHERE id IN ({$placeholders})",
			...$ids
		) );
	}

	/**
	 * Email digest — only once per 30 minutes per (user, conversation).
	 */
	private static function maybe_send_email( $user_id, $conversation_id, $payload ) {
		$meta_key = self::META_LAST_EMAIL_PREFIX . $conversation_id;
		$last     = (int) get_user_meta( $user_id, $meta_key, true );
		if ( $last && ( time() - $last ) < self::email_cooldown_seconds() ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			return;
		}
		$subject = '[Zorderz] ' . $payload['title'];
		$body    = $payload['title'] . "\n\n"
			. ( $payload['body'] ?? '' ) . "\n\n"
			. 'Open: ' . $payload['url'];

		wp_mail( $user->user_email, $subject, $body );
		update_user_meta( $user_id, $meta_key, time() );
	}

	/**
	 * DM email (v1.1.0) — sends the recipient the ACTUAL message text plus a
	 * reply address (app+dm-<token>@…) that routes their email reply straight
	 * back into the DM. Sent for every DM regardless of push status; still
	 * throttled to once per 30 minutes per (user, conversation) so a rapid
	 * back-and-forth doesn't flood the inbox.
	 *
	 * Quiet hours are already applied upstream (jobs only fire after release_at).
	 *
	 * @param int    $user_id          the recipient
	 * @param int    $conversation_id
	 * @param array  $group            queued rows for this (user, conversation)
	 * @param string $label            display label of the conversation (sender name)
	 */
	private static function maybe_send_dm_email( $user_id, $conversation_id, array $group, $label ) {
		$meta_key = self::META_LAST_EMAIL_PREFIX . $conversation_id;
		$last     = (int) get_user_meta( $user_id, $meta_key, true );
		$cooldown = self::email_cooldown_seconds();
		if ( $cooldown > 0 && $last && ( time() - $last ) < $cooldown ) {
			self::dbg( sprintf(
				'DM email SUPPRESSED by cooldown: user=%d conv=%d (last sent %ds ago, cooldown=%ds)',
				$user_id, $conversation_id, ( time() - $last ), $cooldown
			) );
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			self::dbg( sprintf(
				'DM email SKIPPED: user=%d conv=%d has no email on file',
				$user_id, $conversation_id
			) );
			return;
		}

		// The message that anchors this email = the most recent one in the group.
		$anchor_message_id = 0;
		$author_user_id    = 0;
		foreach ( $group as $r ) {
			$mid = (int) $r['message_id'];
			if ( $mid > $anchor_message_id ) {
				$anchor_message_id = $mid;
			}
			$p = json_decode( (string) $r['payload_json'], true ) ?: array();
			if ( ! empty( $p['author_user_id'] ) ) {
				$author_user_id = (int) $p['author_user_id'];
			}
		}

		$author      = $author_user_id ? get_userdata( $author_user_id ) : null;
		$author_name = $author ? $author->display_name : ( $label ?: 'A teammate' );

		// Pull the ACTUAL message body(ies) for this conversation in the group.
		$message_lines = self::dm_email_message_body( $conversation_id, $group );
		if ( '' === trim( $message_lines ) ) {
			// Nothing readable to include (e.g. deleted / attachment-only) — fall
			// back to a minimal notice rather than emailing an empty message.
			$message_lines = '(' . $author_name . ' sent you a message. Open the app to view it.)';
		}

		$deep_link = self::deep_link_url( $conversation_id );

		// Mint a reply-routing token for THIS recipient + conversation + message.
		$reply_to   = '';
		$body_token = '';
		if ( class_exists( 'ZIM_Email_Reply' ) ) {
			$token      = ZIM_Email_Reply::mint( $conversation_id, $user_id, $anchor_message_id );
			$reply_to   = ZIM_Email_Reply::reply_address_for_token( $token );
			$body_token = ZIM_Email_Reply::body_marker( $token );
		}

		$can_reply = ( '' !== $reply_to );

		$subject = '[Zorderz] New message from ' . $author_name;

		$body  = 'You have a new message from ' . $author_name . " on Zorderz:\n\n";
		$body .= "————————————————————\n";
		$body .= $message_lines . "\n";
		$body .= "————————————————————\n\n";
		if ( $can_reply ) {
			$body .= "Reply to this email and your response will be sent back to " . $author_name . " as a direct message.\n\n";
		}
		$body .= 'Open in app: ' . $deep_link . "\n";

		// Invisible token line — lets a reply route even if the mail client
		// drops the Reply-To address. Kept on its own line so quote-stripping
		// on the way back removes it cleanly.
		if ( '' !== $body_token ) {
			$body .= "\n" . $body_token . "\n";
		}

		$headers = array();
		if ( $can_reply ) {
			$headers[] = 'Reply-To: ' . $author_name . ' via Zorderz <' . $reply_to . '>';
		}

		$sent = wp_mail( $user->user_email, $subject, $body, $headers );
		self::dbg( sprintf(
			'DM email %s: to=%s conv=%d anchor_msg=%d can_reply=%s reply_to=%s',
			$sent ? 'SENT (wp_mail=true)' : 'FAILED (wp_mail=false)',
			$user->user_email, $conversation_id, $anchor_message_id,
			$can_reply ? 'yes' : 'no',
			$reply_to !== '' ? $reply_to : '(none)'
		) );
		update_user_meta( $user_id, $meta_key, time() );
	}

	/**
	 * v1.1.2 — targeted debug logging for the DM-email outbound path. Only emits
	 * when WP_DEBUG is on, and prefixed so it's greppable in debug.log. This exists
	 * because a successful wp_mail() is otherwise silent, making it impossible to
	 * tell "sent" from "suppressed/skipped" when a DM email doesn't arrive.
	 */
	private static function dbg( $msg ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'ZIM_DM_EMAIL: ' . $msg );
		}
	}

	/**
	 * v1.1.3 — the per-(user, conversation) email cooldown, in seconds.
	 *
	 * Defaults to ZIM_EMAIL_DIGEST_COOLDOWN_SECONDS (30 min) but is now routed
	 * through the `zim_email_cooldown_seconds` filter so the throttle can be
	 * shortened or disabled — e.g. to 0 during a live round-trip test — without a
	 * redeploy. Returning 0 disables the cooldown entirely (every DM emails).
	 *
	 * @return int seconds; 0 disables the cooldown
	 */
	private static function email_cooldown_seconds() {
		$default = defined( 'ZIM_EMAIL_DIGEST_COOLDOWN_SECONDS' )
			? (int) ZIM_EMAIL_DIGEST_COOLDOWN_SECONDS
			: 30 * MINUTE_IN_SECONDS;

		return max( 0, (int) apply_filters( 'zim_email_cooldown_seconds', $default ) );
	}

	/**
	 * Build the human-readable message text for the DM email from the actual
	 * stored message rows referenced by this notification group. Newest last.
	 * Deleted messages and pure-attachment messages are represented sensibly.
	 *
	 * @return string plain text (may be multiple messages separated by blank lines)
	 */
	private static function dm_email_message_body( $conversation_id, array $group ) {
		global $wpdb;

		$ids = array();
		foreach ( $group as $r ) {
			$mid = (int) $r['message_id'];
			if ( $mid > 0 ) {
				$ids[ $mid ] = true;
			}
		}
		if ( empty( $ids ) ) {
			return '';
		}
		$ids = array_keys( $ids );
		sort( $ids ); // chronological (ids are monotonic)

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, body, deleted_at FROM {$wpdb->prefix}zim_messages
			  WHERE conversation_id = %d AND id IN ({$placeholders})
			  ORDER BY id ASC",
			$conversation_id,
			...$ids
		), ARRAY_A );

		if ( empty( $rows ) ) {
			return '';
		}

		$out = array();
		foreach ( $rows as $row ) {
			if ( ! empty( $row['deleted_at'] ) ) {
				continue; // don't email deleted content
			}
			$text = trim( (string) $row['body'] );
			// Body is stored kses-sanitised; for a plain-text email, flatten any
			// residual markup and decode entities.
			$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$text = trim( $text );
			if ( '' !== $text ) {
				$out[] = $text;
			}
		}

		return implode( "\n\n", $out );
	}

	// ─────────────────────────────────────────────────────────────
	// Quiet hours
	// ─────────────────────────────────────────────────────────────

	/**
	 * Compute release_at in UTC MySQL datetime format, applying the user's
	 * quiet hours window. Returns the current UTC time if not in quiet hours.
	 *
	 * Quiet hours default: 21:00–07:00 local time. User-overridable via
	 * set_quiet_hours(). Crossing-midnight windows are handled by folding:
	 * if end < start, the window spans midnight.
	 */
	public static function compute_release_at( $user_id ) {
		$window = self::get_quiet_hours( $user_id );
		$tz     = wp_timezone();
		$now_local = new DateTime( 'now', $tz );

		$start = self::parse_hm( $window['start'] );
		$end   = self::parse_hm( $window['end'] );

		// Boundaries today.
		$start_dt = ( clone $now_local )->setTime( $start[0], $start[1], 0 );
		$end_dt   = ( clone $now_local )->setTime( $end[0],   $end[1],   0 );

		$in_window = false;
		if ( $start_dt <= $end_dt ) {
			// Same-day window (e.g., 13:00–14:00).
			if ( $now_local >= $start_dt && $now_local < $end_dt ) {
				$in_window = true;
				$release_local = $end_dt;
			}
		} else {
			// Spans midnight (e.g., 21:00–07:00).
			if ( $now_local >= $start_dt ) {
				// After start, before midnight — release tomorrow's end time.
				$in_window = true;
				$release_local = ( clone $end_dt )->modify( '+1 day' );
			} elseif ( $now_local < $end_dt ) {
				// Past midnight, before end — release today's end time.
				$in_window = true;
				$release_local = $end_dt;
			}
		}

		if ( ! $in_window ) {
			return gmdate( 'Y-m-d H:i:s' );
		}

		$release_utc = ( clone $release_local )->setTimezone( new DateTimeZone( 'UTC' ) );
		return $release_utc->format( 'Y-m-d H:i:s' );
	}

	public static function get_quiet_hours( $user_id ) {
		$v = get_user_meta( (int) $user_id, self::OPT_QUIET_HOURS_PREFIX, true );
		if ( is_array( $v ) && ! empty( $v['start'] ) && ! empty( $v['end'] ) ) {
			return $v;
		}
		return array(
			'start' => ZIM_DEFAULT_QUIET_START,
			'end'   => ZIM_DEFAULT_QUIET_END,
		);
	}

	public static function set_quiet_hours( $user_id, $start_hm, $end_hm ) {
		if ( ! preg_match( '/^\d{2}:\d{2}$/', $start_hm )
		     || ! preg_match( '/^\d{2}:\d{2}$/', $end_hm ) ) {
			return new WP_Error( 'zim_bad_hm', 'Times must be HH:MM.' );
		}
		update_user_meta( (int) $user_id, self::OPT_QUIET_HOURS_PREFIX, array(
			'start' => $start_hm,
			'end'   => $end_hm,
		) );
		return true;
	}

	private static function parse_hm( $hm ) {
		$parts = explode( ':', $hm );
		return array( (int) ( $parts[0] ?? 0 ), (int) ( $parts[1] ?? 0 ) );
	}

	private static function conversation_label( $conv, $viewer_user_id ) {
		if ( ! $conv ) {
			return 'a conversation';
		}
		if ( 'channel' === $conv->kind ) {
			return $conv->name ?: ( '#' . $conv->slug );
		}
		// DM — label by the other party's display name.
		$other_id = ( (int) $conv->user_a === (int) $viewer_user_id ) ? (int) $conv->user_b : (int) $conv->user_a;
		$other    = get_userdata( $other_id );
		return $other ? $other->display_name : 'DM';
	}

	private static function deep_link_url( $conversation_id ) {
		return add_query_arg( array(
			'zim_page' => 1,
			'c'         => (int) $conversation_id,
		), home_url( '/' ) );
	}
}
