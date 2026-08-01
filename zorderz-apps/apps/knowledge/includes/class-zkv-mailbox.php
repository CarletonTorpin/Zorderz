<?php
/**
 * ZKV_Mailbox — Microsoft Graph (Microsoft 365) mail client + poller.
 *
 * THE "FORWARD AN EMAIL TO THE VAULT" TRANSPORT (v1.4.0). Staff forward any
 * email to a dedicated mailbox the admin configures (e.g. documents@yourdomain).
 * Every few minutes a WP-cron tick authenticates as the application (client-
 * credentials grant — the same pattern as the Scheduler's Graph client), reads the
 * unread Inbox messages, and hands each one to ZKV_Email_Ingest. Nothing
 * inbound is ever exposed: no webhook, no REST route, no nopriv AJAX — the
 * site only ever calls OUT to Microsoft.
 *
 * Azure requirement: the app registration needs the *application* permission
 * `Mail.Read` (admin-consented). `Mail.Send` is NOT required — confirmation
 * replies go out through wp_mail (WP Mail SMTP), same as receipts/surveys.
 *
 * CREDENTIALS. The vault has its own settings (options below). If they are
 * left empty and the scheduler's Microsoft connection (TSSCH_Settings) is
 * configured, we transparently reuse those credentials — one Azure app for
 * the whole platform; CT only has to add the Mail.Read permission to it.
 * Secrets follow the platform posture: isolated option, non-autoloaded,
 * server-side only, never localized to JS, never returned by AJAX/REST.
 *
 * GRACEFUL DEGRADATION: unconfigured or disabled → every entry point is a
 * safe no-op. A Poe/Graph outage marks nothing lost — unread mail simply
 * waits for the next tick.
 *
 * NB: All HTTP goes through wp_remote_* (WordPress HTTP API) — never raw cURL.
 *
 * @since 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ZKV_Mailbox {

	const TOKEN_URL_TMPL = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';
	const GRAPH_BASE     = 'https://graph.microsoft.com/v1.0';
	const SCOPE          = 'https://graph.microsoft.com/.default';

	const OPT_CONFIG = 'zkv_mail_config'; // mailbox, tenant_id, client_id, enabled
	const OPT_SECRET = 'zkv_mail_secret'; // client secret (isolated, non-autoloaded)
	const OPT_TOKEN  = 'zkv_mail_token';  // cached app-only bearer + expiry + cred fingerprint
	const OPT_STATE  = 'zkv_mail_state';  // folder ids, processed message ids, last-poll summary

	const CRON_HOOK     = 'zkv_mail_poll_event';
	const CRON_SCHEDULE = 'zkv_five_min';
	const LOCK_KEY      = 'zkv_mail_poll_lock';
	const BATCH_SIZE    = 10;   // messages per tick — keeps a tick bounded.
	const MAX_SEEN_IDS  = 500;  // internetMessageId dedupe ring buffer.

	// Mailbox folders we file messages into after handling.
	const FOLDER_PROCESSED = 'Vault Processed';
	const FOLDER_REJECTED  = 'Vault Rejected';
	const FOLDER_FAILED    = 'Vault Failed';

	// ── Boot / cron wiring ─────────────────────────────────────────

	public static function boot() {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_schedule' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'poll' ) );
	}

	public static function register_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::CRON_SCHEDULE ] ) ) {
			$schedules[ self::CRON_SCHEDULE ] = array(
				'interval' => 300,
				// Plain string, NOT __(): cron_schedules can fire before textdomains load
				// (same lesson as the scheduler's five-minute schedule).
				'display'  => 'Every 5 Minutes (TS Knowledge Vault mail)',
			);
		}
		return $schedules;
	}

	/**
	 * Keep the recurring poll aligned with the enabled flag.
	 * Runs on init — cheap (two option reads + wp_next_scheduled).
	 */
	public static function maybe_schedule() {
		$active    = self::is_active();
		$scheduled = wp_next_scheduled( self::CRON_HOOK );

		if ( $active && ! $scheduled ) {
			wp_schedule_event( time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK );
		} elseif ( ! $active && $scheduled ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	// ── Settings ───────────────────────────────────────────────────

	/**
	 * The vault's own mail config (secret excluded).
	 *
	 * @return array{mailbox:string,tenant_id:string,client_id:string,enabled:bool}
	 */
	public static function get_config() {
		$cfg = get_option( self::OPT_CONFIG, array() );
		if ( ! is_array( $cfg ) ) { $cfg = array(); }
		return wp_parse_args( $cfg, array(
			'mailbox'   => '',
			'tenant_id' => '',
			'client_id' => '',
			'enabled'   => false,
		) );
	}

	public static function update_config( array $patch ) {
		$cfg = self::get_config();
		foreach ( array( 'mailbox', 'tenant_id', 'client_id', 'enabled' ) as $k ) {
			if ( array_key_exists( $k, $patch ) ) {
				if ( 'enabled' === $k ) {
					$cfg[ $k ] = (bool) $patch[ $k ];
				} elseif ( 'mailbox' === $k ) {
					$cfg[ $k ] = sanitize_email( (string) $patch[ $k ] );
				} else {
					$cfg[ $k ] = sanitize_text_field( (string) $patch[ $k ] );
				}
			}
		}
		update_option( self::OPT_CONFIG, $cfg );
	}

	public static function set_secret( $secret ) {
		if ( '' === (string) $secret ) {
			delete_option( self::OPT_SECRET );
			return;
		}
		update_option( self::OPT_SECRET, (string) $secret, false );
	}

	public static function get_secret() {
		return (string) get_option( self::OPT_SECRET, '' );
	}

	public static function has_secret() {
		return '' !== self::get_secret();
	}

	/**
	 * Effective Graph credentials — the vault's own, else the scheduler's
	 * Microsoft connection (TSSCH_Settings) as a transparent fallback so the
	 * platform keeps ONE Azure app registration.
	 *
	 * @return array{tenant_id:string,client_id:string,secret:string,source:string}
	 */
	public static function effective_credentials() {
		$cfg = self::get_config();
		if ( ! empty( $cfg['tenant_id'] ) && ! empty( $cfg['client_id'] ) && self::has_secret() ) {
			return array(
				'tenant_id' => $cfg['tenant_id'],
				'client_id' => $cfg['client_id'],
				'secret'    => self::get_secret(),
				'source'    => 'vault',
			);
		}
		// Fallback: scheduler's app-only connection (same tenant, same app).
		if ( class_exists( 'TSSCH_Settings' ) ) {
			$s_cfg    = TSSCH_Settings::get_config();
			$s_secret = TSSCH_Settings::get_secret();
			if ( ! empty( $s_cfg['tenant_id'] ) && ! empty( $s_cfg['client_id'] ) && '' !== $s_secret ) {
				return array(
					'tenant_id' => $s_cfg['tenant_id'],
					'client_id' => $s_cfg['client_id'],
					'secret'    => $s_secret,
					'source'    => 'scheduler',
				);
			}
		}
		return array( 'tenant_id' => '', 'client_id' => '', 'secret' => '', 'source' => 'none' );
	}

	/** Configured = mailbox address + usable credentials (own or scheduler). */
	public static function is_configured() {
		$cfg   = self::get_config();
		$creds = self::effective_credentials();
		return ! empty( $cfg['mailbox'] ) && 'none' !== $creds['source'];
	}

	/** Active = configured AND the enable switch is on. */
	public static function is_active() {
		$cfg = self::get_config();
		return ! empty( $cfg['enabled'] ) && self::is_configured();
	}

	// ── State (folder ids, dedupe ring, last-poll summary) ────────

	public static function get_state() {
		$s = get_option( self::OPT_STATE, array() );
		if ( ! is_array( $s ) ) { $s = array(); }
		return wp_parse_args( $s, array(
			'folders'   => array(),   // display name → Graph folder id
			'seen_ids'  => array(),   // internetMessageId ring buffer
			'last_poll' => '',
			'last_result' => '',
		) );
	}

	private static function update_state( array $patch ) {
		$s = array_merge( self::get_state(), $patch );
		update_option( self::OPT_STATE, $s, false );
	}

	// ── Token (client credentials, cached, fingerprinted) ─────────

	/**
	 * Acquire (or reuse) the app-only bearer token. The cache carries a
	 * credential fingerprint so switching from scheduler-fallback creds to
	 * the vault's own (or rotating the secret) invalidates it immediately.
	 *
	 * @return string|WP_Error
	 */
	public static function get_token() {
		$creds = self::effective_credentials();
		if ( 'none' === $creds['source'] ) {
			return new WP_Error( 'zkv_mail_unconfigured', 'Email-in is not configured (missing tenant/client/secret).' );
		}
		$fingerprint = md5( $creds['tenant_id'] . '|' . $creds['client_id'] . '|' . md5( $creds['secret'] ) );

		$cached = get_option( self::OPT_TOKEN, null );
		if ( is_array( $cached )
			&& ! empty( $cached['access_token'] )
			&& ! empty( $cached['expires_at'] )
			&& $cached['expires_at'] > time()
			&& ( $cached['fingerprint'] ?? '' ) === $fingerprint ) {
			return $cached['access_token'];
		}

		$url  = sprintf( self::TOKEN_URL_TMPL, rawurlencode( $creds['tenant_id'] ) );
		$resp = wp_remote_post( $url, array(
			'timeout' => 20,
			'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			'body'    => array(
				'client_id'     => $creds['client_id'],
				'client_secret' => $creds['secret'],
				'grant_type'    => 'client_credentials',
				'scope'         => self::SCOPE,
			),
		) );

		if ( is_wp_error( $resp ) ) { return $resp; }
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 || empty( $json['access_token'] ) ) {
			$desc = is_array( $json ) ? ( $json['error_description'] ?? $json['error'] ?? 'unknown' ) : 'unknown';
			self::log( 'token error HTTP ' . $code . ': ' . ( is_string( $desc ) ? $desc : wp_json_encode( $desc ) ) );
			return new WP_Error( 'zkv_mail_token', 'Could not authenticate with Microsoft 365 (HTTP ' . $code . ').' );
		}

		update_option( self::OPT_TOKEN, array(
			'access_token' => (string) $json['access_token'],
			// Refresh 5 minutes early to avoid edge expiry mid-poll.
			'expires_at'   => time() + max( 60, (int) ( $json['expires_in'] ?? 3600 ) - 300 ),
			'fingerprint'  => $fingerprint,
		), false );

		return (string) $json['access_token'];
	}

	// ── Low-level Graph request helper ─────────────────────────────

	/**
	 * Perform a Graph call against the configured mailbox's user node.
	 *
	 * @param string      $method  GET|POST|PATCH
	 * @param string      $path    Path under /users/{mailbox}, starting with '/'.
	 * @param array|null  $body    JSON body for POST/PATCH.
	 * @param bool        $raw     true → return raw body string instead of decoded JSON.
	 * @return array{ok:bool,code:int,data:mixed,error:string}
	 */
	private static function request( $method, $path, $body = null, $raw = false ) {
		$cfg   = self::get_config();
		$token = self::get_token();
		if ( is_wp_error( $token ) ) {
			return array( 'ok' => false, 'code' => 0, 'data' => null, 'error' => $token->get_error_message() );
		}

		$url  = self::GRAPH_BASE . '/users/' . rawurlencode( $cfg['mailbox'] ) . $path;
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode( $body );
		}

		$resp = wp_remote_request( $url, $args );
		if ( is_wp_error( $resp ) ) {
			return array( 'ok' => false, 'code' => 0, 'data' => null, 'error' => $resp->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body_raw = wp_remote_retrieve_body( $resp );

		// One-shot recovery from a token revoked/expired server-side.
		if ( 401 === $code ) {
			delete_option( self::OPT_TOKEN );
			$token = self::get_token();
			if ( ! is_wp_error( $token ) ) {
				$args['headers']['Authorization'] = 'Bearer ' . $token;
				$resp = wp_remote_request( $url, $args );
				if ( ! is_wp_error( $resp ) ) {
					$code     = (int) wp_remote_retrieve_response_code( $resp );
					$body_raw = wp_remote_retrieve_body( $resp );
				}
			}
		}

		if ( $code < 200 || $code >= 300 ) {
			$json = json_decode( $body_raw, true );
			$msg  = is_array( $json ) && isset( $json['error']['message'] ) ? $json['error']['message'] : ( 'HTTP ' . $code );
			return array( 'ok' => false, 'code' => $code, 'data' => $json, 'error' => $msg );
		}

		return array(
			'ok'    => true,
			'code'  => $code,
			'data'  => $raw ? $body_raw : json_decode( $body_raw, true ),
			'error' => '',
		);
	}

	// ── Mailbox operations ─────────────────────────────────────────

	/** List unread Inbox messages, oldest first, bounded batch. */
	public static function list_unread() {
		// Graph rule: properties in $orderby must ALSO lead the $filter, in the
		// same order — hence the always-true receivedDateTime clause up front.
		$path = '/mailFolders/inbox/messages?' . http_build_query( array(
			'$filter'  => 'receivedDateTime ge 1970-01-01T00:00:00Z and isRead eq false',
			'$top'     => self::BATCH_SIZE,
			'$orderby' => 'receivedDateTime asc',
			// toRecipients/ccRecipients (v1.4.1) let a consumer see the delivery
			// address — needed for plus-addressed routing (e.g. the messaging
			// plugin's app+dm-<token>@ DM-reply bridge).
			'$select'  => 'id,subject,from,sender,toRecipients,ccRecipients,receivedDateTime,internetMessageId,hasAttachments,body',
		) );
		$r = self::request( 'GET', $path );
		if ( ! $r['ok'] ) { return $r; }
		$r['messages'] = ( is_array( $r['data'] ) && ! empty( $r['data']['value'] ) ) ? $r['data']['value'] : array();
		return $r;
	}

	/**
	 * List a message's attachments (metadata only — bytes fetched separately).
	 * Only real file attachments are returned; inline images (signature logos
	 * etc.) and attached Outlook items are filtered out here.
	 */
	public static function list_attachments( $message_id ) {
		$path = '/messages/' . rawurlencode( $message_id ) . '/attachments?' . http_build_query( array(
			'$select' => 'id,name,contentType,size,isInline',
		) );
		$r = self::request( 'GET', $path );
		if ( ! $r['ok'] || empty( $r['data']['value'] ) || ! is_array( $r['data']['value'] ) ) {
			return array();
		}
		$out = array();
		foreach ( $r['data']['value'] as $att ) {
			$type = $att['@odata.type'] ?? '';
			if ( '#microsoft.graph.fileAttachment' !== $type ) { continue; }
			if ( ! empty( $att['isInline'] ) ) { continue; }
			$out[] = array(
				'id'          => (string) ( $att['id'] ?? '' ),
				'name'        => (string) ( $att['name'] ?? 'attachment' ),
				'contentType' => (string) ( $att['contentType'] ?? '' ),
				'size'        => (int) ( $att['size'] ?? 0 ),
			);
		}
		return $out;
	}

	/** Fetch one attachment's raw bytes via /$value (works at any size we allow). */
	public static function attachment_bytes( $message_id, $attachment_id ) {
		$path = '/messages/' . rawurlencode( $message_id ) . '/attachments/' . rawurlencode( $attachment_id ) . '/$value';
		$r    = self::request( 'GET', $path, null, true );
		return $r['ok'] ? (string) $r['data'] : '';
	}

	/** Mark a message read (so a re-poll never double-sees it mid-move). */
	public static function mark_read( $message_id ) {
		return self::request( 'PATCH', '/messages/' . rawurlencode( $message_id ), array( 'isRead' => true ) );
	}

	/**
	 * Move a message to one of our outcome folders (created on demand).
	 * Non-fatal on failure — the read flag already prevents re-processing.
	 */
	public static function file_message( $message_id, $folder_name ) {
		$folder_id = self::ensure_folder( $folder_name );
		if ( '' === $folder_id ) { return false; }
		$r = self::request( 'POST', '/messages/' . rawurlencode( $message_id ) . '/move', array( 'destinationId' => $folder_id ) );
		if ( ! $r['ok'] && 404 === $r['code'] ) {
			// Folder was deleted since we cached its id — re-ensure once and retry.
			self::forget_folder( $folder_name );
			$folder_id = self::ensure_folder( $folder_name );
			if ( '' !== $folder_id ) {
				$r = self::request( 'POST', '/messages/' . rawurlencode( $message_id ) . '/move', array( 'destinationId' => $folder_id ) );
			}
		}
		return ! empty( $r['ok'] );
	}

	/** Find-or-create a top-level mail folder by display name; cache its id. */
	private static function ensure_folder( $name ) {
		$state = self::get_state();
		if ( ! empty( $state['folders'][ $name ] ) ) {
			return (string) $state['folders'][ $name ];
		}

		// Look it up (displayName filter needs escaped single quotes).
		$safe = str_replace( "'", "''", $name );
		$r = self::request( 'GET', '/mailFolders?' . http_build_query( array(
			'$filter' => "displayName eq '" . $safe . "'",
			'$select' => 'id,displayName',
		) ) );
		$id = '';
		if ( $r['ok'] && ! empty( $r['data']['value'][0]['id'] ) ) {
			$id = (string) $r['data']['value'][0]['id'];
		} else {
			$c = self::request( 'POST', '/mailFolders', array( 'displayName' => $name ) );
			if ( $c['ok'] && ! empty( $c['data']['id'] ) ) {
				$id = (string) $c['data']['id'];
			}
		}

		if ( '' !== $id ) {
			$state = self::get_state();
			$state['folders'][ $name ] = $id;
			self::update_state( array( 'folders' => $state['folders'] ) );
		}
		return $id;
	}

	private static function forget_folder( $name ) {
		$state = self::get_state();
		unset( $state['folders'][ $name ] );
		self::update_state( array( 'folders' => $state['folders'] ) );
	}

	// ── The poll tick ──────────────────────────────────────────────

	/**
	 * Cron entry — read unread messages and hand each to the ingester.
	 *
	 * @param bool $force  true (admin "Check now") polls even while disabled,
	 *                     as long as the connection is configured.
	 * @return array Summary { ran, stored, duplicates, rejected, failed, errors[] }
	 */
	public static function poll( $force = false ) {
		$summary = array( 'ran' => false, 'stored' => 0, 'duplicates' => 0, 'rejected' => 0, 'failed' => 0, 'messaging' => 0, 'messaging_failed' => 0, 'errors' => array() );

		if ( $force !== true ) { $force = false; } // cron passes no args; guard against WP passing hook args.
		if ( ( ! $force && ! self::is_active() ) || ( $force && ! self::is_configured() ) ) {
			$summary['errors'][] = 'Email-in is not ' . ( $force ? 'configured' : 'enabled' ) . '.';
			return $summary;
		}
		if ( ! class_exists( 'ZKV_Email_Ingest' ) ) {
			$summary['errors'][] = 'Ingest class missing.';
			return $summary;
		}

		// Overlap lock — a slow tick (big attachments) must not stack.
		if ( get_transient( self::LOCK_KEY ) ) {
			$summary['errors'][] = 'Another poll is already running.';
			return $summary;
		}
		set_transient( self::LOCK_KEY, 1, 4 * MINUTE_IN_SECONDS );

		try {
			$list = self::list_unread();
			if ( empty( $list['ok'] ) ) {
				$summary['errors'][] = 'Mailbox read failed: ' . $list['error'];
				self::update_state( array(
					'last_poll'   => current_time( 'mysql' ),
					'last_result' => 'ERROR: ' . $list['error'],
				) );
				return $summary;
			}

			$summary['ran'] = true;
			$state    = self::get_state();
			$seen_ids = is_array( $state['seen_ids'] ) ? $state['seen_ids'] : array();

			foreach ( $list['messages'] as $msg ) {
				$mid  = (string) ( $msg['id'] ?? '' );
				$imid = (string) ( $msg['internetMessageId'] ?? '' );
				if ( '' === $mid ) { continue; }

				try {
					// Ring-buffer dedupe: a message we already handled (e.g. a move
					// failed after ingest) must never create a second document.
					if ( '' !== $imid && in_array( $imid, $seen_ids, true ) ) {
						self::mark_read( $mid );
						self::file_message( $mid, self::FOLDER_PROCESSED );
						continue;
					}

					$result = ZKV_Email_Ingest::ingest( $msg );

					// Mark read FIRST so a crash between steps can't re-process.
					self::mark_read( $mid );

					switch ( $result['status'] ) {
						case 'stored':
							$summary['stored']++;
							self::file_message( $mid, self::FOLDER_PROCESSED );
							break;
						case 'duplicate':
							$summary['duplicates']++;
							self::file_message( $mid, self::FOLDER_PROCESSED );
							break;
						case 'messaging':
							// v1.4.1 — handed off to the Internal Messaging DM-reply
							// bridge and posted into the DM. NOT a vault document.
							$summary['messaging'] = ( $summary['messaging'] ?? 0 ) + 1;
							self::file_message( $mid, self::FOLDER_PROCESSED );
							break;
						case 'messaging_failed':
							// Claimed as a DM reply but unroutable (bad token, wrong
							// sender, empty). File under the messaging plugin's own
							// folder; no vault document, no backscatter.
							$summary['messaging_failed'] = ( $summary['messaging_failed'] ?? 0 ) + 1;
							$summary['errors'][] = $result['reason'] ?? 'DM reply unroutable';
							self::file_message( $mid, ( '' !== ( $result['folder'] ?? '' ) ) ? $result['folder'] : self::FOLDER_FAILED );
							break;
						case 'rejected':
							$summary['rejected']++;
							self::file_message( $mid, self::FOLDER_REJECTED );
							break;
						default: // 'failed'
							$summary['failed']++;
							$summary['errors'][] = $result['reason'] ?? 'unknown error';
							self::file_message( $mid, self::FOLDER_FAILED );
							break;
					}

					if ( '' !== $imid ) {
						$seen_ids[] = $imid;
						if ( count( $seen_ids ) > self::MAX_SEEN_IDS ) {
							$seen_ids = array_slice( $seen_ids, -self::MAX_SEEN_IDS );
						}
					}

					self::log( 'message ' . substr( $imid ?: $mid, 0, 40 ) . ' → ' . $result['status']
						. ( ! empty( $result['reason'] ) ? ' (' . $result['reason'] . ')' : '' ) );

				} catch ( \Throwable $e ) {
					// One bad message must not wedge the queue: mark it read,
					// file it under Failed, carry on.
					$summary['failed']++;
					$summary['errors'][] = $e->getMessage();
					self::log( 'ingest exception: ' . $e->getMessage() );
					self::mark_read( $mid );
					self::file_message( $mid, self::FOLDER_FAILED );
				}
			}

			$parts = array();
			foreach ( array( 'stored', 'duplicates', 'rejected', 'failed', 'messaging', 'messaging_failed' ) as $k ) {
				if ( ! empty( $summary[ $k ] ) ) { $parts[] = $summary[ $k ] . ' ' . str_replace( '_', ' ', $k ); }
			}
			self::update_state( array(
				'seen_ids'    => $seen_ids,
				'last_poll'   => current_time( 'mysql' ),
				'last_result' => empty( $list['messages'] ) ? 'No new mail.' : implode( ', ', $parts ),
			) );

			// New documents were scheduled for AI indexing — nudge cron now.
			if ( $summary['stored'] > 0 && function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		} finally {
			delete_transient( self::LOCK_KEY );
		}

		return $summary;
	}

	// ── Admin helpers ──────────────────────────────────────────────

	/** Token + Inbox reachability check for the settings screen. */
	public static function test_connection() {
		if ( ! self::is_configured() ) {
			return array( 'ok' => false, 'message' => 'Not configured — enter the mailbox address plus tenant ID, client ID and secret (or configure the Scheduler\'s Microsoft connection).' );
		}
		$r = self::request( 'GET', '/mailFolders/inbox?' . http_build_query( array( '$select' => 'displayName,totalItemCount,unreadItemCount' ) ) );
		if ( ! $r['ok'] ) {
			$hint = '';
			if ( in_array( $r['code'], array( 403, 404 ), true ) ) {
				$hint = ' — check that the Azure app has the APPLICATION permission Mail.Read with admin consent, and that the mailbox address is right.';
			}
			return array( 'ok' => false, 'message' => 'Connection failed: ' . $r['error'] . $hint );
		}
		$creds = self::effective_credentials();
		return array(
			'ok'      => true,
			'message' => 'Connected to ' . self::get_config()['mailbox'] . ' — Inbox has '
				. (int) ( $r['data']['unreadItemCount'] ?? 0 ) . ' unread of '
				. (int) ( $r['data']['totalItemCount'] ?? 0 ) . ' messages'
				. ( 'scheduler' === $creds['source'] ? ' (using the Scheduler\'s Microsoft credentials)' : '' ) . '.',
		);
	}

	/** Settings-screen status line data. */
	public static function status() {
		$cfg   = self::get_config();
		$creds = self::effective_credentials();
		$state = self::get_state();
		$next  = wp_next_scheduled( self::CRON_HOOK );
		return array(
			'configured'  => self::is_configured(),
			'enabled'     => ! empty( $cfg['enabled'] ),
			'cred_source' => $creds['source'],
			'mailbox'     => $cfg['mailbox'],
			'last_poll'   => $state['last_poll'],
			'last_result' => $state['last_result'],
			'next_poll'   => $next ? gmdate( 'H:i:s', $next + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) : '',
		);
	}

	private static function log( $msg ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'ZKV_Mailbox: ' . $msg );
		}
	}
}

ZKV_Mailbox::boot();
