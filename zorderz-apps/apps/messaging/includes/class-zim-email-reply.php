<?php
/**
 * ZIM_Email_Reply — the two-way email ↔ DM bridge (v1.1.0).
 *
 * TWO HALVES:
 *
 *   OUTBOUND (see ZIM_Notifications::send_dm_email):
 *     When a DM is delivered as an email alert, the email carries the ACTUAL
 *     message text and a Reply-To of  app+dm-<token>@<mail-domain> . The same
 *     opaque token is also embedded invisibly at the bottom of the body, so a
 *     reply survives even a mail client that drops Reply-To.
 *
 *   INBOUND (this class):
 *     The recipient replies to that email. The Knowledge Vault's Microsoft-Graph
 *     poller (TSKV_Mailbox::poll → TSKV_Email_Ingest::ingest) is the SINGLE reader
 *     of the shared App@ mailbox. Before it turns a message into a vault document
 *     it asks us — message_is_dm_reply($msg) — and if we claim it, hands the raw
 *     Graph message to handle_graph_message($msg). We verify the token, confirm
 *     the sender IS the person the DM was addressed to, strip the quoted history,
 *     and post the clean reply back into the DM through ZIM_Messages::post() as
 *     that user. The message never becomes a vault document.
 *
 * WHY THE VAULT READS AND WE DON'T (usually):
 *     Two independent pollers marking the same inbox read would race. So the
 *     vault's poll is authoritative and hands DM replies off to us. If the vault
 *     plugin is INACTIVE, self_poll() takes over on the same 5-minute cadence but
 *     claims ONLY app+dm-* messages, leaving everything else untouched. The two
 *     never run at once (self_poll no-ops whenever TSKV_Mailbox is active).
 *
 * TOKEN (INV-Token — opaque, signed, constant-time, single-purpose):
 *     base64url(payload) . '.' . base64url( HMAC-SHA256(payload, secret) )
 *     payload = "v1:{conversation_id}:{recipient_user_id}:{message_id}"
 *     secret  = wp_options 'zim_email_reply_secret' (64 hex, auto-generated,
 *               NON-autoloaded, server-side only, never localized / returned).
 *     The token proves routing; it is NOT a capability — the sender must still
 *     match the intended recipient, and ZIM_Messages::post() re-checks write
 *     permission (kiosk refused) server-side (INV-1 / INV-10).
 *
 * HONEST OUTPUT (INV-12): a reply that cannot be routed is dropped and logged;
 *     we never fabricate a delivery and never send backscatter to strangers.
 *
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIM_Email_Reply {

	/** wp_options key holding the per-install HMAC secret (non-autoloaded). */
	const OPT_SECRET = 'zim_email_reply_secret';

	/** wp_options key holding the bridge config (enabled, mailbox_base, mail_domain). */
	const OPT_CONFIG = 'zim_email_reply_config';

	/** Plus-tag prefix on the reply address local-part: app+dm-<token>@domain. */
	const PLUS_TAG = 'dm-';

	/** Invisible in-body token marker (belt-and-suspenders if Reply-To is dropped). */
	const BODY_MARKER_OPEN  = '[[zim-dm:';
	const BODY_MARKER_CLOSE = ']]';

	/** Self-poll cron (only used when the Knowledge Vault mailbox is NOT active). */
	const CRON_HOOK     = 'zim_email_reply_self_poll';
	const CRON_SCHEDULE = 'zim_every_minute'; // registered by the bootstrap already
	const SELF_POLL_EVERY = 300;               // seconds; guarded to run ~5-minutely
	const SELF_POLL_TS_OPT = 'zim_email_reply_last_self_poll';
	const SELF_SEEN_OPT    = 'zim_email_reply_self_seen'; // dedupe ring for self-poll
	const MAX_SEEN_IDS     = 500;

	/** Mailbox folder the vault files an unroutable DM reply into. */
	const FOLDER_FAILED = 'Messaging Failed';

	// ──────────────────────────────────────────────────────────────
	//  Boot
	// ──────────────────────────────────────────────────────────────

	/**
	 * Wire the (guarded) self-poll fallback. Safe to call once from the
	 * bootstrap's zim_load_includes(). The primary inbound path is the vault
	 * handoff and needs no wiring here.
	 */
	public static function boot() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'self_poll' ) );

		// Keep a recurring self-poll scheduled; self_poll() no-ops while the
		// vault mailbox is active, so this is cheap insurance, not a second reader.
		add_action( 'init', array( __CLASS__, 'maybe_schedule_self_poll' ) );
	}

	public static function maybe_schedule_self_poll() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Reuse the plugin's existing one-minute schedule; self_poll() throttles
			// itself to ~5 minutes and bails entirely when the vault is active.
			wp_schedule_event( time() + 120, self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	public static function unschedule_self_poll() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	// ──────────────────────────────────────────────────────────────
	//  Config
	// ──────────────────────────────────────────────────────────────

	/**
	 * Bridge config. Defaults derive from the Knowledge Vault mailbox config so
	 * the mailbox is configured in ONE place (the vault Email-In screen):
	 *   mailbox_base = local part of the vault mailbox (e.g. "app")
	 *   mail_domain  = domain of the vault mailbox (e.g. "the marketing site")
	 *
	 * @return array{enabled:bool,mailbox_base:string,mail_domain:string}
	 */
	public static function get_config() {
		$saved = get_option( self::OPT_CONFIG, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$base   = '';
		$domain = '';
		if ( class_exists( 'TSKV_Mailbox' ) ) {
			$mb = TSKV_Mailbox::get_config();
			$addr = is_array( $mb ) ? (string) ( $mb['mailbox'] ?? '' ) : '';
			if ( '' !== $addr && false !== strpos( $addr, '@' ) ) {
				list( $base, $domain ) = explode( '@', $addr, 2 );
			}
		}

		return wp_parse_args( $saved, array(
			'enabled'      => true,
			'mailbox_base' => $base,
			'mail_domain'  => $domain,
		) );
	}

	/** True when we know enough to MINT a reply address (base + domain + enabled). */
	public static function outbound_ready() {
		$c = self::get_config();
		return ! empty( $c['enabled'] ) && '' !== $c['mailbox_base'] && '' !== $c['mail_domain'];
	}

	/**
	 * The reply address for a token:  {base}+{PLUS_TAG}{token}@{domain}
	 * Returns '' when not configured (caller then omits Reply-To but still
	 * embeds the in-body token).
	 */
	public static function reply_address_for_token( $token ) {
		$c = self::get_config();
		if ( '' === $c['mailbox_base'] || '' === $c['mail_domain'] ) {
			return '';
		}
		return $c['mailbox_base'] . '+' . self::PLUS_TAG . $token . '@' . $c['mail_domain'];
	}

	// ──────────────────────────────────────────────────────────────
	//  Token (opaque, signed, constant-time)
	// ──────────────────────────────────────────────────────────────

	private static function secret() {
		$s = get_option( self::OPT_SECRET, '' );
		if ( ! is_string( $s ) || strlen( $s ) < 32 ) {
			$s = bin2hex( random_bytes( 32 ) ); // 64 hex chars
			update_option( self::OPT_SECRET, $s, false ); // NON-autoloaded, server-side only
		}
		return $s;
	}

	private static function b64url_encode( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	private static function b64url_decode( $txt ) {
		$txt = strtr( (string) $txt, '-_', '+/' );
		$pad = strlen( $txt ) % 4;
		if ( $pad ) {
			$txt .= str_repeat( '=', 4 - $pad );
		}
		return base64_decode( $txt, true );
	}

	/**
	 * Mint a routing token for a specific delivered DM.
	 *
	 * @param int $conversation_id
	 * @param int $recipient_user_id  the user the email is being SENT to
	 * @param int $message_id         the message that triggered this email
	 * @return string  opaque url/email-safe token
	 */
	public static function mint( $conversation_id, $recipient_user_id, $message_id ) {
		$payload = 'v1:' . (int) $conversation_id . ':' . (int) $recipient_user_id . ':' . (int) $message_id;
		$sig     = hash_hmac( 'sha256', $payload, self::secret(), true );
		return self::b64url_encode( $payload ) . '.' . self::b64url_encode( $sig );
	}

	/**
	 * Verify + decode a token.
	 *
	 * @return array{conversation_id:int,recipient_user_id:int,message_id:int}|false
	 */
	public static function verify( $token ) {
		$token = trim( (string) $token );
		if ( '' === $token || false === strpos( $token, '.' ) ) {
			return false;
		}
		list( $p_b64, $s_b64 ) = explode( '.', $token, 2 );
		$payload = self::b64url_decode( $p_b64 );
		$sig     = self::b64url_decode( $s_b64 );
		if ( false === $payload || false === $sig ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $payload, self::secret(), true );
		if ( ! hash_equals( $expected, $sig ) ) { // constant-time
			return false;
		}
		// payload = v1:conv:uid:mid
		$parts = explode( ':', $payload );
		if ( count( $parts ) !== 4 || 'v1' !== $parts[0] ) {
			return false;
		}
		return array(
			'conversation_id'   => (int) $parts[1],
			'recipient_user_id' => (int) $parts[2],
			'message_id'        => (int) $parts[3],
		);
	}

	/** The invisible in-body marker string carrying the token. */
	public static function body_marker( $token ) {
		return self::BODY_MARKER_OPEN . $token . self::BODY_MARKER_CLOSE;
	}

	// ──────────────────────────────────────────────────────────────
	//  Inbound — detection + handling (called by the vault handoff)
	// ──────────────────────────────────────────────────────────────

	/**
	 * Does this Graph message look like a reply to a DM alert email?
	 * True if any recipient address is  {base}+{PLUS_TAG}...  OR the body
	 * carries a well-formed in-body token marker.
	 *
	 * Detection is deliberately loose (either signal); AUTHORITY comes later
	 * from token verification + sender match in handle_graph_message().
	 *
	 * @param array $msg Graph message resource.
	 */
	public static function message_is_dm_reply( $msg ) {
		if ( ! is_array( $msg ) ) {
			return false;
		}
		// 1) Plus-tagged recipient address.
		if ( '' !== self::extract_token_from_recipients( $msg ) ) {
			return true;
		}
		// 2) In-body token marker (survives Reply-To stripping).
		$body = self::raw_body_text( $msg );
		if ( '' !== self::extract_token_from_body( $body ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Handle a claimed DM-reply Graph message. Returns a small status array the
	 * vault ingester maps onto its own outcome (handled → filed under
	 * FOLDER_FAILED on failure, or Processed on success).
	 *
	 * @return array{ok:bool,reason:string,message_id:int}
	 */
	public static function handle_graph_message( $msg ) {
		if ( ! is_array( $msg ) ) {
			return self::fail( 'not a message' );
		}

		// ── 1. Token (recipient plus-tag first, then in-body marker) ──
		$token = self::extract_token_from_recipients( $msg );
		if ( '' === $token ) {
			$token = self::extract_token_from_body( self::raw_body_text( $msg ) );
		}
		if ( '' === $token ) {
			return self::fail( 'no token on message' );
		}

		$claim = self::verify( $token );
		if ( ! $claim ) {
			return self::fail( 'token failed verification' );
		}

		$conversation_id   = (int) $claim['conversation_id'];
		$recipient_user_id = (int) $claim['recipient_user_id'];

		// ── 2. Sender must BE the person the DM was addressed to ──
		$from_email = self::from_email( $msg );
		if ( '' === $from_email ) {
			return self::fail( 'no sender address' );
		}
		$recipient = get_userdata( $recipient_user_id );
		if ( ! $recipient || empty( $recipient->user_email ) ) {
			return self::fail( 'token recipient is not a WP user' );
		}
		if ( strtolower( $from_email ) !== strtolower( (string) $recipient->user_email ) ) {
			// Someone other than the intended recipient replied (or a forward).
			// Never post as them; drop it.
			return self::fail( 'reply sender ' . $from_email . ' != intended recipient' );
		}

		// ── 3. The recipient must still be a member of that DM ──
		if ( ! class_exists( 'ZIM_Membership' )
		     || ! ZIM_Membership::is_member( $recipient_user_id, $conversation_id ) ) {
			return self::fail( 'sender no longer a member of conversation ' . $conversation_id );
		}

		// ── 4. Extract the human reply (strip quoted history + signature + marker) ──
		$reply = self::extract_reply_text( self::raw_body_text( $msg ), self::body_type( $msg ) );
		$reply = trim( $reply );
		if ( '' === $reply ) {
			return self::fail( 'empty reply after quote-strip' );
		}
		// Guard against pathological length.
		if ( strlen( $reply ) > 20000 ) {
			$reply = substr( $reply, 0, 20000 );
		}

		// ── 5. Post into the DM as the recipient (post() re-checks write perms) ──
		if ( ! class_exists( 'ZIM_Messages' ) ) {
			return self::fail( 'messaging model unavailable' );
		}
		$res = ZIM_Messages::post( $conversation_id, $recipient_user_id, $reply );
		if ( is_wp_error( $res ) ) {
			return self::fail( 'post rejected: ' . $res->get_error_code() );
		}

		self::log( sprintf(
			'posted email reply from %s into conversation %d (message %d)',
			$from_email,
			$conversation_id,
			is_array( $res ) ? (int) ( $res['message_id'] ?? 0 ) : 0
		) );

		return array(
			'ok'         => true,
			'reason'     => '',
			'message_id' => is_array( $res ) ? (int) ( $res['message_id'] ?? 0 ) : 0,
		);
	}

	// ──────────────────────────────────────────────────────────────
	//  Self-poll fallback (only when the vault mailbox is NOT active)
	// ──────────────────────────────────────────────────────────────

	/**
	 * Cron tick. Runs ONLY when the Knowledge Vault mailbox is inactive, so the
	 * two never read the same inbox at once. Claims ONLY app+dm-* messages,
	 * leaving all other unread mail untouched (never marks it read, never files).
	 */
	public static function self_poll() {
		// Primary path owns the inbox whenever it's active — stand down.
		if ( class_exists( 'TSKV_Mailbox' ) && TSKV_Mailbox::is_active() ) {
			return;
		}
		// Need the Graph transport (it lives in the vault plugin). If the vault
		// plugin is absent entirely, we have no transport → nothing to do.
		if ( ! class_exists( 'TSKV_Mailbox' ) ) {
			return;
		}
		if ( ! self::outbound_ready() ) {
			return; // mailbox not configured yet
		}

		// Throttle to ~5 minutes even though the schedule is 1-minute.
		$last = (int) get_option( self::SELF_POLL_TS_OPT, 0 );
		if ( $last && ( time() - $last ) < ( self::SELF_POLL_EVERY - 15 ) ) {
			return;
		}
		update_option( self::SELF_POLL_TS_OPT, time(), false );

		$list = TSKV_Mailbox::list_unread();
		if ( empty( $list['ok'] ) || empty( $list['messages'] ) ) {
			return;
		}

		$seen = get_option( self::SELF_SEEN_OPT, array() );
		if ( ! is_array( $seen ) ) {
			$seen = array();
		}

		foreach ( $list['messages'] as $msg ) {
			$mid  = (string) ( $msg['id'] ?? '' );
			$imid = (string) ( $msg['internetMessageId'] ?? '' );
			if ( '' === $mid ) {
				continue;
			}
			// ONLY claim DM replies. Leave everything else UNREAD for the vault
			// (or a human) — do not touch it.
			if ( ! self::message_is_dm_reply( $msg ) ) {
				continue;
			}
			if ( '' !== $imid && in_array( $imid, $seen, true ) ) {
				TSKV_Mailbox::mark_read( $mid );
				continue;
			}

			$r = self::handle_graph_message( $msg );
			TSKV_Mailbox::mark_read( $mid ); // claim it either way (it WAS a DM reply)
			if ( empty( $r['ok'] ) ) {
				TSKV_Mailbox::file_message( $mid, self::FOLDER_FAILED );
			}

			if ( '' !== $imid ) {
				$seen[] = $imid;
				if ( count( $seen ) > self::MAX_SEEN_IDS ) {
					$seen = array_slice( $seen, -self::MAX_SEEN_IDS );
				}
			}
		}

		update_option( self::SELF_SEEN_OPT, $seen, false );
	}

	// ──────────────────────────────────────────────────────────────
	//  Graph message field helpers
	// ──────────────────────────────────────────────────────────────

	private static function from_email( $msg ) {
		foreach ( array( 'from', 'sender' ) as $k ) {
			if ( ! empty( $msg[ $k ]['emailAddress']['address'] ) ) {
				return sanitize_email( $msg[ $k ]['emailAddress']['address'] );
			}
		}
		return '';
	}

	private static function body_type( $msg ) {
		return strtolower( (string) ( $msg['body']['contentType'] ?? 'text' ) );
	}

	private static function raw_body_text( $msg ) {
		return (string) ( $msg['body']['content'] ?? '' );
	}

	/**
	 * Pull the token out of the recipient addresses (To + Cc). Looks for
	 * local-part of the form  {base}+{PLUS_TAG}{token} . Base is matched
	 * loosely (any local part) so a mail system that rewrites the base still
	 * routes as long as the +dm-<token> tag survives.
	 */
	public static function extract_token_from_recipients( $msg ) {
		$addresses = array();
		foreach ( array( 'toRecipients', 'ccRecipients' ) as $bucket ) {
			if ( ! empty( $msg[ $bucket ] ) && is_array( $msg[ $bucket ] ) ) {
				foreach ( $msg[ $bucket ] as $r ) {
					// Keep ORIGINAL case — the token after "+dm-" is base64url and
					// case-sensitive. Only the tag match itself is case-insensitive.
					$a = (string) ( $r['emailAddress']['address'] ?? '' );
					if ( '' !== $a ) {
						$addresses[] = $a;
					}
				}
			}
		}

		$tag = '+' . self::PLUS_TAG; // "+dm-"
		foreach ( $addresses as $addr ) {
			$at    = strpos( $addr, '@' );
			$local = ( false === $at ) ? $addr : substr( $addr, 0, $at );
			// Case-insensitive search for the tag, case-preserving token extract.
			$pos = stripos( $local, $tag );
			if ( false !== $pos ) {
				$token = substr( $local, $pos + strlen( $tag ) );
				$token = self::sanitize_token( $token );
				if ( '' !== $token ) {
					return $token;
				}
			}
		}
		return '';
	}

	/** Pull the token out of an in-body marker [[zim-dm:TOKEN]] (html or text). */
	public static function extract_token_from_body( $body ) {
		if ( '' === (string) $body ) {
			return '';
		}
		$open  = preg_quote( self::BODY_MARKER_OPEN, '/' );
		$close = preg_quote( self::BODY_MARKER_CLOSE, '/' );
		// Body may be HTML with entities/tags between marker chars; decode first.
		$decoded = html_entity_decode( wp_strip_all_tags( (string) $body ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( preg_match( '/' . $open . '([A-Za-z0-9._\-]+)' . $close . '/', $decoded, $m ) ) {
			return self::sanitize_token( $m[1] );
		}
		// Fall back to scanning the raw body too (marker might sit inside a tag-free run).
		if ( preg_match( '/' . $open . '([A-Za-z0-9._\-]+)' . $close . '/', (string) $body, $m ) ) {
			return self::sanitize_token( $m[1] );
		}
		return '';
	}

	private static function sanitize_token( $token ) {
		$token = trim( (string) $token );
		// token charset = base64url + a single '.' separator.
		if ( ! preg_match( '/^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+$/', $token ) ) {
			return '';
		}
		return $token;
	}

	// ──────────────────────────────────────────────────────────────
	//  Quote / signature stripping
	// ──────────────────────────────────────────────────────────────

	/**
	 * Reduce a reply email body to just the human's new text: drop quoted
	 * history, the invisible token marker, and common signature/footers.
	 *
	 * @param string $body  raw body (html or text)
	 * @param string $type  'html' | 'text'
	 */
	public static function extract_reply_text( $body, $type ) {
		$text = ( 'html' === $type )
			? self::html_to_text( (string) $body )
			: (string) $body;

		// Remove the invisible token marker wherever it sits.
		$text = preg_replace(
			'/' . preg_quote( self::BODY_MARKER_OPEN, '/' ) . '[A-Za-z0-9._\-]+' . preg_quote( self::BODY_MARKER_CLOSE, '/' ) . '/',
			'',
			$text
		);

		$lines = preg_split( '/\r\n|\r|\n/', $text );
		$kept  = array();

		// Delimiters that mark the START of quoted history — stop at the first.
		$stop_patterns = array(
			'/^\s*-{2,}\s*Original Message\s*-{2,}/i',
			'/^\s*_{5,}\s*$/',                       // Outlook underscore rule
			'/^\s*On .+ wrote:\s*$/i',               // "On <date>, <name> wrote:"
			'/^\s*On .+ <[^>]+>\s* wrote:\s*$/i',
			'/^\s*From:\s.+$/i',                     // Outlook block header
			'/^\s*Sent from my /i',                  // mobile signatures (also a soft sig)
			'/^\s*Get Outlook for /i',
		);
		// A line that is the Zorderz alert header we generated (defensive).
		$our_header = '/^\s*(You have a new message|New message in|—{3,}|Open in app:|Reply to this email)/i';

		foreach ( $lines as $line ) {
			$stop = false;
			foreach ( $stop_patterns as $p ) {
				if ( preg_match( $p, $line ) ) {
					$stop = true;
					break;
				}
			}
			if ( $stop ) {
				break;
			}
			// Quoted lines (">" prefixed) => start of history; stop.
			if ( preg_match( '/^\s*>/', $line ) ) {
				break;
			}
			// Skip our own alert header lines if they got echoed at the very top.
			if ( empty( $kept ) && preg_match( $our_header, $line ) ) {
				continue;
			}
			$kept[] = $line;
		}

		$out = implode( "\n", $kept );

		// Trim a trailing "--" signature block if present.
		$out = preg_split( '/^\s*--\s*$/m', $out )[0];

		// Collapse excessive blank lines / whitespace.
		$out = preg_replace( "/[ \t]+\n/", "\n", $out );
		$out = preg_replace( "/\n{3,}/", "\n\n", $out );

		return trim( $out );
	}

	/**
	 * Minimal HTML→text for reply bodies (mirrors the vault's approach, kept
	 * local so this class has no hard dependency on TSKV_Email_Ingest).
	 */
	public static function html_to_text( $html ) {
		$text = (string) $html;
		$text = preg_replace( '/<(style|script|head|title)\b[^>]*>.*?<\/\1>/is', ' ', $text );
		$text = preg_replace( '/<!--.*?-->/s', ' ', $text );
		// Preserve the token marker if it lived inside an attribute/hidden span:
		// (strip_all_tags below keeps text nodes; markers are text, so fine.)
		$text = preg_replace( '/<br\s*\/?>/i', "\n", $text );
		$text = preg_replace( '/<\/(p|div|tr|li|h[1-6]|blockquote|table)>/i', "\n", $text );
		$text = preg_replace( '/<blockquote\b[^>]*>/i', "\n> ", $text ); // mark quotes so the stripper sees them
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( array( "\xC2\xA0", "\xE2\x80\x8B" ), array( ' ', '' ), $text );
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		return trim( $text );
	}

	// ──────────────────────────────────────────────────────────────
	//  Small helpers
	// ──────────────────────────────────────────────────────────────

	private static function fail( $reason ) {
		self::log( 'DM reply dropped: ' . $reason );
		return array( 'ok' => false, 'reason' => $reason, 'message_id' => 0 );
	}

	private static function log( $msg ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'ZIM_Email_Reply: ' . $msg );
		}
	}
}
