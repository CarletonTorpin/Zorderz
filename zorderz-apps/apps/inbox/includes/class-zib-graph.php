<?php
/**
 * ZIB_Graph — delegated Microsoft Graph client for per-user mailboxes (Zorderz Inbox).
 *
 * Modeled on ZSCH_Graph_Delegated, whose own header notes it was built so
 * "staff can connect their own M365 mailboxes." This is that, for mail:
 * authorization-code flow against a SINGLE-TENANT app registration —
 * single-tenant by configuration: only the configured tenant's mailboxes
 * complete the flow — read-only.
 *
 * SCOPES: 'openid profile email offline_access User.Read Mail.Read' — there is
 * deliberately NO Mail.ReadWrite / Mail.Send. The connector can read a mailbox
 * and nothing else; it can never send or alter mail. That is a security
 * boundary of the whole feature, not a limitation to be lifted later.
 *
 * Message-fetch / delta methods (list_messages, delta, fetch_body) arrive in
 * P1 (Ballast + ingest). P0 needs only: authorize URL, code exchange, token
 * refresh, and identity from the id_token.
 *
 * @since 0.1.0 (P0 — Connect)
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIB_Graph {

	const GRAPH  = 'https://graph.microsoft.com/v1.0';
	const SCOPES = 'openid profile email offline_access User.Read Mail.Read';

	/** Single-tenant authority root (…/{tenant}/oauth2/v2.0). */
	private static function authority(): string {
		$tenant = ZIB_Settings::tenant_id();
		return 'https://login.microsoftonline.com/' . rawurlencode( $tenant ) . '/oauth2/v2.0';
	}

	/**
	 * Build the Microsoft sign-in URL.
	 *
	 * @param string $state Signed state blob.
	 * @return string|WP_Error
	 */
	public static function auth_url( string $state ) {
		$client_id = ZIB_Settings::client_id();
		$tenant    = ZIB_Settings::tenant_id();
		if ( '' === $client_id || '' === $tenant ) {
			return new WP_Error( 'zib_unconfigured', 'Zorderz Inbox is not configured.' );
		}
		return self::authority() . '/authorize?' . http_build_query( array(
			'client_id'     => $client_id,
			'response_type' => 'code',
			'redirect_uri'  => ZIB_OAuth::redirect_uri(),
			'response_mode' => 'query',
			'scope'         => self::SCOPES,
			'prompt'        => 'select_account',
			'state'         => $state,
		) );
	}

	/**
	 * Exchange an authorization code for tokens.
	 *
	 * @return array|WP_Error {access_token, refresh_token, expires_in, id_token, scope}
	 */
	public static function exchange_code( string $code ) {
		return self::token_request( array(
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'client_id'     => ZIB_Settings::client_id(),
			'client_secret' => ZIB_Settings::secret(),
			'redirect_uri'  => ZIB_OAuth::redirect_uri(),
			'scope'         => self::SCOPES,
		) );
	}

	/**
	 * Refresh an access token. Microsoft ROTATES the refresh token on every
	 * use; ZIB_Vault persists the returned pair atomically.
	 *
	 * @return array|WP_Error
	 */
	public static function refresh_token( string $refresh_token ) {
		return self::token_request( array(
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refresh_token,
			'client_id'     => ZIB_Settings::client_id(),
			'client_secret' => ZIB_Settings::secret(),
			'scope'         => self::SCOPES,
		) );
	}

	/**
	 * Shared token-endpoint POST with the invalid_grant / invalid_client split.
	 *
	 *   invalid_grant  = the credentials were ACCEPTED; the user's grant is
	 *                    gone (revoked / expired / password change) → per-user
	 *                    reauth. ZIB_Vault flips that ONE account.
	 *   invalid_client = the app SECRET is wrong (AADSTS7000215) → a platform
	 *                    misconfig; NEVER flip a user's account for it. Returned
	 *                    as a transient error so the vault keeps the token.
	 *
	 * @param array $params
	 * @return array|WP_Error
	 */
	private static function token_request( array $params ) {
		$resp = wp_remote_post( self::authority() . '/token', array(
			'timeout' => 20,
			'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			'body'    => $params,
		) );
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'zib_net', $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$json = json_decode( (string) wp_remote_retrieve_body( $resp ), true );

		if ( $code >= 200 && $code < 300 && ! empty( $json['access_token'] ) ) {
			return array(
				'access_token'  => (string) $json['access_token'],
				'refresh_token' => (string) ( $json['refresh_token'] ?? '' ),
				'expires_in'    => (int) ( $json['expires_in'] ?? 3600 ),
				'id_token'      => (string) ( $json['id_token'] ?? '' ),
				'scope'         => (string) ( $json['scope'] ?? '' ),
			);
		}

		$err = is_array( $json ) ? (string) ( $json['error'] ?? '' ) : '';
		if ( 'invalid_grant' === $err ) {
			return new WP_Error( 'invalid_grant', 'Microsoft authorization is no longer valid.' );
		}
		if ( 'invalid_client' === $err ) {
			// Platform misconfig (bad/expired app secret). Do not blame the user.
			return new WP_Error( 'invalid_client', 'Zorderz Inbox app credentials are misconfigured.' );
		}
		return new WP_Error( 'zib_token', 'Token request failed (' . ( '' !== $err ? $err : $code ) . ').' );
	}

	/**
	 * Identity from the id_token JWT. Keys off the IMMUTABLE `oid` (an email can
	 * change); `upn` / email are display labels only.
	 *
	 * @return array{external_id:string,tenant_id:string,upn:string,email:string}
	 */
	public static function identity_from_id_token( string $jwt ): array {
		$out   = array( 'external_id' => '', 'tenant_id' => '', 'upn' => '', 'email' => '' );
		$parts = explode( '.', $jwt );
		if ( count( $parts ) < 2 ) {
			return $out;
		}
		$payload = base64_decode( strtr( $parts[1], '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $parts[1] ) % 4 ) % 4 ), true );
		$claims  = is_string( $payload ) ? json_decode( $payload, true ) : null;
		if ( ! is_array( $claims ) ) {
			return $out;
		}
		$upn = (string) ( $claims['preferred_username'] ?? ( $claims['upn'] ?? ( $claims['email'] ?? '' ) ) );
		return array(
			'external_id' => (string) ( $claims['oid'] ?? '' ),
			'tenant_id'   => (string) ( $claims['tid'] ?? '' ),
			'upn'         => $upn,
			'email'       => (string) ( $claims['email'] ?? $upn ),
		);
	}

	/**
	 * Best-effort revoke on disconnect.
	 *
	 * Microsoft does NOT offer a programmatic per-grant revoke for a delegated
	 * refresh token (unlike Google). Real revocation is the user removing the
	 * app under myaccount.microsoft.com, or an admin in Entra. Disconnect's
	 * security value here is that we DELETE our stored tokens + purge the
	 * Ballast; this method exists for parity and is intentionally a no-op.
	 *
	 * @return bool
	 */
	public static function revoke( string $refresh_token ): bool {
		unset( $refresh_token ); // no MS delegated revoke endpoint; see docblock.
		return true;
	}

	// ── Mail read surface (P1 ingest) ──────────────────────────────
	//
	// Two-stage by design: list/delta return METADATA only ($select below, no
	// body) so the classifier can decide internal/external from participants;
	// the full body is fetched ONLY for in-scope messages. An out-of-scope
	// customer email's body is therefore never pulled into the system.

	const MSG_SELECT = 'id,internetMessageId,conversationId,receivedDateTime,sentDateTime,from,toRecipients,ccRecipients,subject,bodyPreview,hasAttachments,importance';

	/** Well-known folder id for a logical folder name. */
	private static function folder_id( string $folder ): string {
		return ( 'sent' === $folder ) ? 'sentitems' : 'inbox';
	}

	/** The date field a folder is ordered/filtered on. */
	private static function date_field( string $folder ): string {
		return ( 'sent' === $folder ) ? 'sentDateTime' : 'receivedDateTime';
	}

	/**
	 * Authenticated Graph GET. Tokens come ONLY through the single-flight vault.
	 *
	 * @param int    $account_id
	 * @param string $url Absolute Graph URL (or a nextLink/deltaLink).
	 * @return array|WP_Error Decoded JSON.
	 */
	public static function graph_get( int $account_id, string $url, array $extra_headers = array() ) {
		self::$last_retry_after = 0;
		$token = ZIB_Vault::get_access_token( $account_id );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		// $extra_headers lets a caller opt in to e.g. Prefer: IdType="ImmutableId" (attachment /
		// folder discovery) without changing message-ingest calls. Default empty = behaviour unchanged.
		$headers = array_merge(
			array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
			$extra_headers
		);
		$resp = wp_remote_get( $url, array(
			'timeout' => 25,
			'headers' => $headers,
		) );
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'zib_net', $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$json = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( $code >= 200 && $code < 300 ) {
			return is_array( $json ) ? $json : array();
		}
		// Delta token no longer valid — Graph signals this as HTTP 410 (or an error code
		// such as resyncRequired). Surface a distinct code the delta loop recovers from with
		// a full re-sync, instead of a dead cursor retried forever (v0.9.2 stall fix).
		$gerr = ( is_array( $json ) && isset( $json['error']['code'] ) ) ? (string) $json['error']['code'] : '';
		if ( 410 === $code || in_array( $gerr, array( 'resyncRequired', 'SyncStateNotFound', 'SyncStateInvalid' ), true ) ) {
			return new WP_Error( 'zib_delta_invalid', 'Delta token expired — full resync required (' . ( '' !== $gerr ? $gerr : $code ) . ').' );
		}
		if ( 401 === $code ) {
			return new WP_Error( 'zib_graph_401', 'Graph rejected the token.' );
		}
		if ( 429 === $code || $code >= 500 ) {
			return new WP_Error( 'zib_graph_transient', 'Graph is throttling or unavailable (' . $code . ').' );
		}
		return new WP_Error( 'zib_graph_' . $code, 'Graph error ' . $code . '.' );
	}

	/**
	 * One backfill page for a folder within the window (metadata only).
	 *
	 * @param int         $account_id
	 * @param string      $folder    'inbox' | 'sent'
	 * @param string      $since_iso ISO-8601 UTC lower bound.
	 * @param string      $next_link A prior @odata.nextLink, or '' for the first page.
	 * @return array|WP_Error { messages: array[], next: string }
	 */
	public static function backfill_page( int $account_id, string $folder, string $since_iso, string $next_link = '' ) {
		if ( '' !== $next_link ) {
			$url = $next_link;
		} else {
			$field = self::date_field( $folder );
			$url   = self::GRAPH . '/me/mailFolders/' . self::folder_id( $folder ) . '/messages?'
				. '$select=' . rawurlencode( self::MSG_SELECT )
				. '&$filter=' . rawurlencode( $field . ' ge ' . $since_iso )
				. '&$orderby=' . rawurlencode( $field . ' desc' )
				. '&$top=25';
		}
		$json = self::graph_get( $account_id, $url );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		return array(
			'messages' => isset( $json['value'] ) && is_array( $json['value'] ) ? $json['value'] : array(),
			'next'     => (string) ( $json['@odata.nextLink'] ?? '' ),
		);
	}

	/**
	 * Initialize a forward-sync cursor at "now" (no history enumerated).
	 * Backfill covers history; delta covers everything after this point.
	 *
	 * @return string|WP_Error deltaLink.
	 */
	public static function delta_init( int $account_id, string $folder ) {
		$url  = self::GRAPH . '/me/mailFolders/' . self::folder_id( $folder ) . '/messages/delta?$deltatoken=latest'
			. '&$select=' . rawurlencode( self::MSG_SELECT );
		$json = self::graph_get( $account_id, $url );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		// $deltatoken=latest is MEANT to return a deltaLink and no items (anchor at "now"; history
		// is the backfill's job). Some mailboxes return a nextLink ENUMERATION instead — too large to
		// drain in one tick (the v0.9.3 chase timed out on WP Engine's ~3-min limit). So return the
		// first link as-is: a deltaLink means we are anchored; a skiptoken means ZIB_Ingest::prime_all()
		// drains it to the deltaLink in bounded, metadata-only, self-rescheduled bursts (v0.9.4).
		$link = (string) ( $json['@odata.deltaLink'] ?? ( $json['@odata.nextLink'] ?? '' ) );
		if ( '' === $link ) {
			return new WP_Error( 'zib_delta_init', 'No delta link returned.' );
		}
		return $link;
	}

	/**
	 * Follow a delta/next link. Returns changed messages plus the next cursor.
	 *
	 * @return array|WP_Error { items: array[], removed: string[], next: string, delta: string }
	 */
	public static function delta_page( int $account_id, string $link ) {
		$json = self::graph_get( $account_id, $link );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$items   = array();
		$removed = array();
		foreach ( (array) ( $json['value'] ?? array() ) as $row ) {
			if ( isset( $row['@removed'] ) ) {
				if ( ! empty( $row['id'] ) ) {
					$removed[] = (string) $row['id'];
				}
			} else {
				$items[] = $row;
			}
		}
		return array(
			'items'   => $items,
			'removed' => $removed,
			'next'    => (string) ( $json['@odata.nextLink'] ?? '' ),
			'delta'   => (string) ( $json['@odata.deltaLink'] ?? '' ),
		);
	}

	/**
	 * Stage two: fetch ONE message's body (only ever called for in-scope mail).
	 *
	 * @return array|WP_Error { content: string, format: 'text'|'html' }
	 */
	public static function fetch_body( int $account_id, string $ms_message_id ) {
		$url  = self::GRAPH . '/me/messages/' . rawurlencode( $ms_message_id ) . '?$select=body';
		$json = self::graph_get( $account_id, $url );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$body = isset( $json['body'] ) && is_array( $json['body'] ) ? $json['body'] : array();
		$fmt  = ( 'html' === strtolower( (string) ( $body['contentType'] ?? 'text' ) ) ) ? 'html' : 'text';
		return array(
			'content' => (string) ( $body['content'] ?? '' ),
			'format'  => $fmt,
		);
	}

	/** @var int Seconds Graph asked us to wait after the last throttle (429/5xx), for the caller's backoff. */
	private static $last_retry_after = 0;

	/** Seconds Graph asked us to wait after the last write throttle (0 = none). */
	public static function retry_after(): int {
		return (int) self::$last_retry_after;
	}

	/**
	 * Authenticated Graph POST (JSON). Write twin of graph_get. Used by the send path.
	 *
	 * @return array|WP_Error Decoded JSON, or array() for an empty 2xx (e.g. 202 from sendMail).
	 */
	public static function graph_post( int $account_id, string $url, array $body, array $extra_headers = array() ) {
		return self::graph_write( 'POST', $account_id, $url, $body, $extra_headers );
	}

	/**
	 * Authenticated Graph PATCH (JSON). Used by the triage path — e.g. { isRead } — and by the send
	 * path to set a draft's body/recipients before sending.
	 *
	 * @return array|WP_Error Decoded JSON, or array() for an empty 2xx.
	 */
	public static function graph_patch( int $account_id, string $url, array $body, array $extra_headers = array() ) {
		return self::graph_write( 'PATCH', $account_id, $url, $body, $extra_headers );
	}

	/** Shared JSON-write core for graph_post/graph_patch — one token + error ladder. */
	private static function graph_write( string $method, int $account_id, string $url, array $body, array $extra_headers = array() ) {
		self::$last_retry_after = 0;
		$token = ZIB_Vault::get_access_token( $account_id );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$headers = array_merge(
			array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			),
			$extra_headers
		);
		$resp = wp_remote_request( $url, array(
			'method'  => $method,
			'timeout' => 25,
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
		) );
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'zib_net', $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$json = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( $code >= 200 && $code < 300 ) {
			return is_array( $json ) ? $json : array(); // 200/201/202 / empty body = success
		}
		if ( 401 === $code ) {
			return new WP_Error( 'zib_graph_401', 'Graph rejected the token.' );
		}
		if ( 403 === $code ) {
			// Missing Mail.Send / Mail.ReadWrite consent lands here — the caller maps it to "reconnect".
			return new WP_Error( 'zib_graph_403', 'Graph denied the write (permission not granted — reconnect to enable it).' );
		}
		if ( 429 === $code || $code >= 500 ) {
			$ra = wp_remote_retrieve_header( $resp, 'retry-after' );
			if ( '' !== (string) $ra ) {
				self::$last_retry_after = is_numeric( $ra ) ? max( 0, (int) $ra ) : max( 0, (int) ( strtotime( (string) $ra ) - time() ) );
			}
			return new WP_Error( 'zib_graph_transient', 'Graph is throttling or unavailable (' . $code . ').' );
		}
		$gmsg = ( is_array( $json ) && isset( $json['error']['message'] ) ) ? (string) $json['error']['message'] : '';
		return new WP_Error( 'zib_graph_' . $code, 'Graph error ' . $code . ( '' !== $gmsg ? ': ' . $gmsg : '.' ) );
	}

	/** Send a BRAND-NEW message. POST /me/sendMail (202, empty body). No message id → no Prefer header. */
	public static function send_new( int $account_id, array $message, bool $save_to_sent = true ) {
		return self::graph_post( $account_id, self::GRAPH . '/me/sendMail', array(
			'message'         => $message,
			'saveToSentItems' => (bool) $save_to_sent,
		) );
	}

	/**
	 * REPLY / REPLY-ALL preserving the owner's spacing. Graph's /reply action collapses a comment's blank
	 * lines, so instead: createReply(All) → the draft carries the quoted original as HTML → PREPEND the
	 * owner's spacing-preserving HTML above the quote → send the draft. Threading (In-Reply-To /
	 * References) is set by createReply and survives the body PATCH.
	 */
	public static function send_reply_html( int $account_id, string $ms_message_id, string $comment_html, bool $all = false, bool $immutable = false ) {
		$hdr   = $immutable ? self::immutable_headers() : array();
		$verb  = $all ? 'createReplyAll' : 'createReply';
		$draft = self::graph_post( $account_id, self::GRAPH . '/me/messages/' . rawurlencode( $ms_message_id ) . '/' . $verb, array(), $hdr );
		if ( is_wp_error( $draft ) ) { return $draft; }
		$draft_id = (string) ( $draft['id'] ?? '' );
		if ( '' === $draft_id ) { return new WP_Error( 'zib_graph_draft', 'Reply draft was not created.' ); }
		$body  = self::prepend_into_body( $comment_html, (string) ( $draft['body']['content'] ?? '' ) );
		$patch = self::graph_patch( $account_id, self::GRAPH . '/me/messages/' . rawurlencode( $draft_id ), array( 'body' => array( 'contentType' => 'HTML', 'content' => $body ) ), $hdr );
		if ( is_wp_error( $patch ) ) { return $patch; }
		return self::graph_post( $account_id, self::GRAPH . '/me/messages/' . rawurlencode( $draft_id ) . '/send', array(), $hdr );
	}

	/**
	 * FORWARD preserving the owner's spacing. Same shape as send_reply_html: createForward → prepend the
	 * owner's HTML above the quoted original AND set toRecipients on the draft → send.
	 */
	public static function send_forward_html( int $account_id, string $ms_message_id, string $comment_html, array $to_recipients, bool $immutable = false ) {
		$hdr   = $immutable ? self::immutable_headers() : array();
		$draft = self::graph_post( $account_id, self::GRAPH . '/me/messages/' . rawurlencode( $ms_message_id ) . '/createForward', array(), $hdr );
		if ( is_wp_error( $draft ) ) { return $draft; }
		$draft_id = (string) ( $draft['id'] ?? '' );
		if ( '' === $draft_id ) { return new WP_Error( 'zib_graph_draft', 'Forward draft was not created.' ); }
		$body  = self::prepend_into_body( $comment_html, (string) ( $draft['body']['content'] ?? '' ) );
		$patch = self::graph_patch( $account_id, self::GRAPH . '/me/messages/' . rawurlencode( $draft_id ), array( 'body' => array( 'contentType' => 'HTML', 'content' => $body ), 'toRecipients' => $to_recipients ), $hdr );
		if ( is_wp_error( $patch ) ) { return $patch; }
		return self::graph_post( $account_id, self::GRAPH . '/me/messages/' . rawurlencode( $draft_id ) . '/send', array(), $hdr );
	}

	/** Insert the owner's HTML comment at the top of the reply/forward's visible body (just after the
	 *  <body> tag, or prepended if there is none). The quoted original stays beneath it. */
	private static function prepend_into_body( string $comment_html, string $original_html ): string {
		if ( '' === $original_html ) { return $comment_html; }
		if ( preg_match( '/<body[^>]*>/i', $original_html, $m, PREG_OFFSET_CAPTURE ) ) {
			$pos = (int) $m[0][1] + strlen( (string) $m[0][0] );
			return substr( $original_html, 0, $pos ) . $comment_html . substr( $original_html, $pos );
		}
		return $comment_html . $original_html;
	}

	/** Set an indexed message's read state. PATCH /me/messages/{id} { isRead }. */
	public static function set_read( int $account_id, string $ms_message_id, bool $is_read, bool $immutable = false ) {
		$url = self::GRAPH . '/me/messages/' . rawurlencode( $ms_message_id );
		return self::graph_patch( $account_id, $url, array( 'isRead' => (bool) $is_read ), $immutable ? self::immutable_headers() : array() );
	}

	/**
	 * MOVE an indexed message to another folder. POST /me/messages/{id}/move { destinationId }.
	 * $destination is a well-known name ('deleteditems' | 'archive' | 'junkemail' | 'inbox' | …) or a
	 * concrete mailFolder id. Graph returns the moved message (new id) on 201; we only need success.
	 */
	public static function move_message( int $account_id, string $ms_message_id, string $destination, bool $immutable = false ) {
		$url = self::GRAPH . '/me/messages/' . rawurlencode( $ms_message_id ) . '/move';
		return self::graph_post( $account_id, $url, array( 'destinationId' => $destination ), $immutable ? self::immutable_headers() : array() );
	}

	/**
	 * Attachment metadata list for a message. $select lists only BASE attachment properties —
	 * contentId lives on the fileAttachment subtype, not the base type, so selecting it 400s the
	 * whole polymorphic list; the cid is read from the full object (get_attachment) instead.
	 *
	 * @return array|WP_Error Graph attachment metadata objects (the value[] array), or WP_Error.
	 */
	public static function list_attachments( int $account_id, string $ms_message_id, bool $immutable = false ) {
		$url  = self::GRAPH . '/me/messages/' . rawurlencode( $ms_message_id )
			. '/attachments?$select=' . rawurlencode( 'id,name,contentType,size,isInline' );
		$json = self::graph_get( $account_id, $url, $immutable ? self::immutable_headers() : array() );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		return ( isset( $json['value'] ) && is_array( $json['value'] ) ) ? $json['value'] : array();
	}

	/**
	 * Fetch ONE attachment in full — a fileAttachment carries contentBytes (base64). The caller
	 * re-classifies the full object (type / isInline / contentType / size) before serving any bytes;
	 * this method is a thin authenticated GET, nothing more.
	 *
	 * @return array|WP_Error The Graph attachment object, or WP_Error.
	 */
	public static function get_attachment( int $account_id, string $ms_message_id, string $att_id, bool $immutable = false ) {
		$url  = self::GRAPH . '/me/messages/' . rawurlencode( $ms_message_id )
			. '/attachments/' . rawurlencode( $att_id );
		$json = self::graph_get( $account_id, $url, $immutable ? self::immutable_headers() : array() );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		return is_array( $json ) ? $json : array();
	}

	/** The per-request Prefer header asking Graph for immutable ids (stable across folder moves). */
	private static function immutable_headers(): array {
		return array( 'Prefer' => 'IdType="ImmutableId"' );
	}
}
