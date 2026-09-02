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
		// v1.1.4 (Vimeo chapter embed): distill a TRUSTED-ORIGIN Vimeo player embed to a
		// plain-text [zdz-video] token BEFORE sanitization. The seeking markup no longer
		// depends on <iframe>/<script> surviving kses (they don't) — the token carries
		// only the numeric video id + chapter list and passes wp_kses_post() untouched.
		// Any non-Vimeo / arbitrary iframe is NOT recognized and falls through to the kses
		// call below, which strips it. This runs AHEAD of kses and does not relax it.
		$body            = self::extract_video_embeds( $body );
		// v1.0.27 (security): server-side sanitize at the single write chokepoint so the
		// AJAX path matches the REST /post path (which already wp_kses_post's the body).
		// Client-side DOMPurify (loaded from a CDN) is no longer the ONLY XSS guard —
		// a stored body can never carry <script>/on*-handlers even if that CDN fails.
		// Markdown chars (* _ # ` etc.) and safe formatting tags survive kses untouched.
		$body            = wp_kses_post( $body );

		// Membership check is the caller's job (AJAX gate).

		// v1.0.24 — Read-only roles (the shared kiosk `zdz_general`) can never
		// post. This is the single model-layer chokepoint every write funnels
		// through (AJAX zim_post, the REST /post route used by the assistant's
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
		// v1.1.4: the edit path is a write chokepoint too. Distil a trusted Vimeo embed
		// to a token, then server-side sanitize — mirroring post(). This also closes a
		// pre-existing gap: edited bodies were previously stored WITHOUT a server-side
		// kses pass (client DOMPurify was the only guard on the edit path). Markdown and
		// safe formatting survive kses; injected <script>/on*-handlers do not.
		$new_body = self::extract_video_embeds( $new_body );
		$new_body = wp_kses_post( $new_body );

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

	/* ═════════════════════════════════════════════════════════════════════
	 * Vimeo chapter embed (v1.1.4) — trusted-origin distillation.
	 *
	 * A first-party training-video embed (a Vimeo player + a clickable chapter
	 * list, as produced by the `text-to-vid` workflow) cannot survive the write
	 * chokepoint: wp_kses_post() strips <iframe>/<script>/<style> and the client
	 * DOMPurify re-strips on render, so the seeking script never runs and the raw
	 * chapter links fall through as blocked popups. Rather than WEAKEN either
	 * sanitizer, we recognize ONLY a trusted-origin Vimeo player embed and distil
	 * it — BEFORE kses — to a plain-text token:
	 *
	 *     [zdz-video]<hex>[/zdz-video]
	 *
	 * where <hex> is bin2hex(JSON) carrying ONLY the numeric video id, an optional
	 * alphanumeric privacy hash, and the chapter list (seconds + label). The token
	 * is plain text: it passes wp_kses_post() untouched and is rebuilt on the
	 * client by wireVideoEmbeds() with the iframe origin FIXED to player.vimeo.com.
	 *
	 * SECURITY POSTURE (preserved, not relaxed):
	 *   - Runs strictly AHEAD of wp_kses_post(); the kses call is unchanged.
	 *   - An iframe whose origin is NOT on the trusted allow-list is left in place
	 *     and stripped by kses. Never an arbitrary iframe src.
	 *   - The video id is validated numeric; the hash alphanumeric. No customer,
	 *     employee, price or place value is ever read — mechanism only.
	 *   - The trusted-origin allow-list is a Core-safe constant (Vimeo) exposed via
	 *     the `zim_video_embed_hosts` filter so a business on a different first-party
	 *     video host can add one WITHOUT hardcoding an origin (default: Vimeo only).
	 * ═════════════════════════════════════════════════════════════════════ */

	/**
	 * Distil trusted-origin Vimeo embeds in a raw body to [zdz-video] tokens.
	 *
	 * @param string $body  raw message body (pre-sanitization)
	 * @return string       body with trusted Vimeo embeds replaced by tokens
	 */
	public static function extract_video_embeds( $body ) {
		$body = (string) $body;
		// Fast path: no iframe means nothing to distil; the body is unchanged and
		// kses handles it exactly as before (posture preserved for normal messages).
		if ( '' === $body || false === stripos( $body, '<iframe' ) ) {
			return $body;
		}
		$hosts = self::trusted_video_hosts();
		if ( empty( $hosts ) ) {
			return $body;
		}

		$tokenized = false;
		// Bounded loop: each successful pass removes one embed, so it converges.
		for ( $i = 0; $i < 8; $i++ ) {
			list( $body, $did ) = self::tokenize_one_video( $body, $hosts );
			if ( ! $did ) {
				break;
			}
			$tokenized = true;
		}

		if ( $tokenized ) {
			// A chat message that pasted a video embed carries no legitimate
			// <script>/<style>; drop them (content included) so the distilled
			// message is clean. kses would neutralize the tags regardless — this
			// just avoids leaving the embed's inert script/style source as text.
			$body = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $body );
			$body = preg_replace( '#<script\b[^>]*/\s*>#i', '', $body );
			$body = preg_replace( '#<style\b[^>]*>.*?</style>#is', '', $body );
			// Strip any stray producer scaffolding not absorbed with a container.
			$body = preg_replace( '#<a\b[^>]*\bclass\s*=\s*("|\')[^"\']*\bttv-ch\b[^"\']*\1[^>]*>.*?</a>#is', '', $body );
			$body = preg_replace( '#<p\b[^>]*\bclass\s*=\s*("|\')[^"\']*\bttv-heading\b[^"\']*\1[^>]*>.*?</p>#is', '', $body );
		}

		return $body;
	}

	/**
	 * Trusted first-party video embed origins. Core default: Vimeo only.
	 *
	 * A business on a different first-party host adds it via the filter (mechanism,
	 * no origin buried in logic). The client rebuild is Vimeo-origin-fixed, so
	 * adding a host also needs a client player adapter — the default stays safe.
	 *
	 * @return string[] lowercase hostnames
	 */
	private static function trusted_video_hosts() {
		$hosts = apply_filters( 'zim_video_embed_hosts', array( 'player.vimeo.com' ) );
		$out   = array();
		foreach ( (array) $hosts as $h ) {
			$h = strtolower( trim( (string) $h ) );
			if ( '' !== $h ) {
				$out[] = $h;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Tokenize the first trusted Vimeo embed found. Prefers a full ttv-embed
	 * container (player + chapters); otherwise a bare trusted iframe.
	 *
	 * @return array{0:string,1:bool}  [ new body, whether an embed was tokenized ]
	 */
	private static function tokenize_one_video( $body, array $hosts ) {
		// 1) A full ttv-embed container (player + chapter list together).
		if ( preg_match(
			'/<div\b[^>]*\bclass\s*=\s*("|\')[^"\']*\bttv-embed\b[^"\']*\1[^>]*>/i',
			$body, $m, PREG_OFFSET_CAPTURE
		) ) {
			$open_start = (int) $m[0][1];
			$end        = self::match_div_container_end( $body, $open_start );
			if ( false !== $end ) {
				$container = substr( $body, $open_start, $end - $open_start );
				$token     = self::build_token_from_html( $container, $hosts );
				if ( null !== $token ) {
					// Absorb trailing whitespace + the producer's <style>/<script>
					// blocks (and HTML comments) that follow the container.
					$tail = substr( $body, $end );
					if ( preg_match(
						'/^(?:\s*(?:<style\b[^>]*>.*?<\/style>|<script\b[^>]*>.*?<\/script>|<script\b[^>]*\/\s*>|<!--.*?-->))*\s*/is',
						$tail, $tm
					) ) {
						$end += strlen( $tm[0] );
					}
					$body = substr( $body, 0, $open_start ) . $token . substr( $body, $end );
					return array( $body, true );
				}
			}
		}

		// 2) A bare trusted iframe (e.g. Vimeo's own share embed, no chapters).
		if ( preg_match_all( '/<iframe\b[^>]*>(?:.*?<\/iframe>)?/is', $body, $mm, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $mm[0] as $frame ) {
				$frame_html = $frame[0];
				$frame_off  = (int) $frame[1];
				$parsed     = self::parse_video_iframe( $frame_html, $hosts );
				if ( null !== $parsed ) {
					$token = self::build_token( $parsed['id'], $parsed['hash'], self::collect_chapters( $body ) );
					$body  = substr( $body, 0, $frame_off ) . $token . substr( $body, $frame_off + strlen( $frame_html ) );
					return array( $body, true );
				}
			}
		}

		return array( $body, false );
	}

	/**
	 * Walk <div>/</div> tags from an opening <div> offset and return the byte
	 * offset just past its matching </div>. Regex cannot balance nesting on its
	 * own; this small depth counter does. Returns false if unbalanced.
	 */
	private static function match_div_container_end( $html, $open_start ) {
		if ( ! preg_match_all( '/<(\/?)div\b[^>]*>/i', $html, $tags, PREG_OFFSET_CAPTURE, $open_start ) ) {
			return false;
		}
		$depth = 0;
		foreach ( $tags[0] as $idx => $tag ) {
			$is_close = ( '/' === $tags[1][ $idx ][0] );
			$depth   += $is_close ? -1 : 1;
			if ( 0 === $depth ) {
				return (int) $tag[1] + strlen( $tag[0] );
			}
		}
		return false;
	}

	/**
	 * Build a token from a chunk of HTML that contains a trusted iframe and,
	 * optionally, ttv-ch chapter anchors. Returns null when no trusted iframe.
	 */
	private static function build_token_from_html( $html, array $hosts ) {
		if ( ! preg_match( '/<iframe\b[^>]*>/i', $html, $fm ) ) {
			return null;
		}
		$parsed = self::parse_video_iframe( $fm[0], $hosts );
		if ( null === $parsed ) {
			return null;
		}
		return self::build_token( $parsed['id'], $parsed['hash'], self::collect_chapters( $html ) );
	}

	/**
	 * Validate an <iframe> tag's origin against the trusted allow-list and pull
	 * the numeric video id + optional alphanumeric privacy hash. The origin check
	 * is host-equality on a parsed URL — immune to `player.vimeo.com.evil.com` and
	 * `evil.com/player.vimeo.com/...`. Returns null for anything untrusted.
	 *
	 * @return array{id:string,hash:string}|null
	 */
	private static function parse_video_iframe( $iframe_tag, array $hosts ) {
		if ( ! preg_match( '/\bsrc\s*=\s*("|\')(.*?)\1/i', $iframe_tag, $sm ) ) {
			return null;
		}
		$src   = html_entity_decode( $sm[2], ENT_QUOTES );
		$parts = wp_parse_url( $src );
		if ( ! is_array( $parts ) ) {
			return null;
		}
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		$host   = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		$path   = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		// Trusted origin only — never an arbitrary iframe src.
		if ( 'https' !== $scheme || ! in_array( $host, $hosts, true ) ) {
			return null;
		}
		if ( ! preg_match( '#^/video/(\d+)#', $path, $pm ) ) {
			return null;
		}
		$hash = '';
		if ( ! empty( $parts['query'] ) ) {
			$q = array();
			parse_str( (string) $parts['query'], $q );
			if ( isset( $q['h'] ) && preg_match( '/^[0-9A-Za-z]+$/', (string) $q['h'] ) ) {
				$hash = (string) $q['h'];
			}
		}
		return array( 'id' => $pm[1], 'hash' => $hash );
	}

	/**
	 * Collect chapter markers from ttv-ch anchors: floored seconds + plain-text
	 * label. Labels are tag-stripped here and re-inserted via textContent on the
	 * client, so a hostile label can carry no markup through either layer.
	 *
	 * @return array<int,array{0:int,1:string}>
	 */
	private static function collect_chapters( $html ) {
		$chapters = array();
		if ( preg_match_all(
			'/<a\b[^>]*\bclass\s*=\s*("|\')[^"\']*\bttv-ch\b[^"\']*\1[^>]*>(.*?)<\/a>/is',
			$html, $am, PREG_SET_ORDER
		) ) {
			foreach ( $am as $a ) {
				$tag   = $a[0];
				$inner = $a[2];
				if ( ! preg_match( '/\bdata-t\s*=\s*("|\')\s*(\d+(?:\.\d+)?)\s*\1/i', $tag, $dm ) ) {
					continue;
				}
				$sec = (int) floor( (float) $dm[2] );
				if ( $sec < 0 ) {
					$sec = 0;
				}
				if ( preg_match( '/<span\b[^>]*\bttv-title\b[^>]*>(.*?)<\/span>/is', $inner, $ttl ) ) {
					$label = $ttl[1];
				} else {
					$label = $inner;
				}
				$label = trim( html_entity_decode( wp_strip_all_tags( $label ), ENT_QUOTES ) );
				if ( '' === $label ) {
					$label = self::clock_label( $sec );
				}
				$chapters[] = array( $sec, $label );
			}
		}
		return $chapters;
	}

	/**
	 * Assemble the [zdz-video] token. Payload keys are terse (v/h/c) to keep the
	 * hex small; the client mirrors them.
	 */
	private static function build_token( $id, $hash, array $chapters ) {
		$payload = array(
			'v' => (string) $id,
			'h' => (string) $hash,
			'c' => array_values( $chapters ),
		);
		$json = wp_json_encode( $payload );
		if ( ! is_string( $json ) ) {
			return '';
		}
		// Hex is the safest inert carrier: [0-9a-f] survives kses, markdown,
		// DOMPurify and every regex untouched.
		return '[zdz-video]' . bin2hex( $json ) . '[/zdz-video]';
	}

	/** m:ss / h:mm:ss label for a chapter with no title text. */
	private static function clock_label( $sec ) {
		$sec = max( 0, (int) $sec );
		$h   = intdiv( $sec, 3600 );
		$m   = intdiv( $sec % 3600, 60 );
		$s   = $sec % 60;
		return $h > 0
			? sprintf( '%d:%02d:%02d', $h, $m, $s )
			: sprintf( '%d:%02d', $m, $s );
	}
}
