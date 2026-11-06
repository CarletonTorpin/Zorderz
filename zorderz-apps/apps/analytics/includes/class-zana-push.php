<?php
/**
 * ZANA_Push — the chat's own phone-notification channel ("Chat answers").
 *
 * A slow answer may finish after the person has left the chat; with notifications on for that device, the chat tells
 * them it is ready. Per the platform's push contract (ZDZ_Push_Setup) the channel is this app's own: its own key
 * (VAPID, P-256, made on first use and kept in options), its own worker (assets/js/sw.js, scoped to this app's assets
 * folder: it only shows notifications), its own subscriptions table (wp_zana_push_subscriptions) and its own
 * endpoints. Settings → Notifications finds it through the registry read verb `chat.push_setup` and turns it on or off
 * with the person's one switch:
 *
 *   chat.push_setup   viewer tier, the chat app: { channel 'chat', label, vapid_public_key, sw_url, sw_scope,
 *                     subscribe / unsubscribe / test { action, nonce }, subscribed_hashes, current_hashes }.
 *                     Refused for the shared device, an account in Privacy View, Demo Mode and the signed out.
 *   admin-ajax        zana_push_subscribe (endpoint, p256dh, auth), zana_push_unsubscribe (endpoint: the asker's own
 *                     only), zana_push_test (endpoint optional; five an hour) — all with the nonce `zana_nonce`.
 *
 * Each subscription records a hash of the sign-in session that made it, and signing out removes that session's rows
 * only (never everything). A push the push service calls gone (404 / 410) is removed. An endpoint is stored and sent
 * to only on a known browser push service (ZDZ_Push_Endpoint, checked on save and again on every send; a stored row
 * that fails the check is removed without a word), sends go through ZDZ_Push_Endpoint::post() (no redirects, no
 * private addresses), and a refusal reaches the person as the status code in words, never the service's own text.
 * Delivery is RFC 8291 aes128gcm with an RFC 8292 VAPID signature; the notification's words are this app's ("Your
 * answer is ready"), never an answer's text, and a click opens the chat on that conversation. Notices other than chat answers go through the platform's
 * alert router (ZDZ_Alert_Router → the messaging app), not through this channel.
 *
 * @package Zorderz\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZANA_Push {

	const JWT_TTL     = 43200;
	const TEST_LIMIT  = 5;
	const TEST_WINDOW = 3600;
	const META_TESTS  = 'zana_push_tests';
	const VERB_SETUP  = 'chat.push_setup';
	const OPT_PUBLIC  = 'zana_vapid_public_key';
	const OPT_PRIVATE = 'zana_vapid_private_pem';

	/** Tests hand in "now" and the subscription store. */
	public static $now = null;

	public static function boot(): void {
		add_action( 'clear_auth_cookie', array( __CLASS__, 'on_logout' ) );
		add_filter( 'zdz_register_capabilities', array( __CLASS__, 'register_capabilities' ), 20 );
		add_action( 'wp_ajax_zana_push_subscribe', array( __CLASS__, 'ajax_subscribe' ) );
		add_action( 'wp_ajax_zana_push_unsubscribe', array( __CLASS__, 'ajax_unsubscribe' ) );
		add_action( 'wp_ajax_zana_push_test', array( __CLASS__, 'ajax_test' ) );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zana_push_subscriptions';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE {$t} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			endpoint TEXT NOT NULL,
			endpoint_hash CHAR(64) NOT NULL DEFAULT '',
			p256dh VARCHAR(255) NOT NULL DEFAULT '',
			auth VARCHAR(255) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			session_hash CHAR(64) NULL,
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			last_used_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY endpoint_hash (endpoint_hash),
			KEY session_hash (session_hash)
		) " . $wpdb->get_charset_collate() . ';' );
	}

	/* ─────────────────────────── who ─────────────────────────── */

	/** Never for: the signed out, the shared device, Demo Mode, an account in Privacy View. */
	public static function refused( int $uid ): bool {
		if ( $uid <= 0 ) {
			return true;
		}
		if ( class_exists( 'ZDZ_Push_Setup' ) && ! ZDZ_Push_Setup::allowed( $uid ) ) {
			return true;
		}
		if ( class_exists( 'ZANA_LLM_Client' ) && ZANA_LLM_Client::is_shared_device( $uid ) ) {
			return true;
		}
		return class_exists( 'ZANA_Pseudonym_Route' ) && ZANA_Pseudonym_Route::is_on( $uid );
	}

	/* ─────────────────────────── keys ────────────────────────── */

	public static function get_public_key(): string {
		self::ensure_keys();
		return (string) get_option( self::OPT_PUBLIC, '' );
	}

	private static function get_private_pem(): string {
		self::ensure_keys();
		return (string) get_option( self::OPT_PRIVATE, '' );
	}

	/** Make the key pair on first use (a P-256 key; the public half as 65 raw bytes, base64url). */
	public static function ensure_keys(): void {
		if ( '' !== (string) get_option( self::OPT_PUBLIC, '' ) || ! function_exists( 'openssl_pkey_new' ) ) {
			return;
		}
		$key = openssl_pkey_new( array( 'curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC ) );
		if ( ! $key ) {
			return;
		}
		$d = openssl_pkey_get_details( $key );
		openssl_pkey_export( $key, $pem );
		$raw = "\x04" . str_pad( (string) $d['ec']['x'], 32, "\0", STR_PAD_LEFT ) . str_pad( (string) $d['ec']['y'], 32, "\0", STR_PAD_LEFT );
		update_option( self::OPT_PRIVATE, $pem, false );
		update_option( self::OPT_PUBLIC, self::base64url_encode( $raw ), false );
	}

	/** The VAPID contact: the business's alerts sender, else the site's admin address. */
	public static function subject(): string {
		$mail = '';
		if ( class_exists( 'ZDZ_Business_Profile' ) && method_exists( 'ZDZ_Business_Profile', 'sender' ) ) {
			$s    = ZDZ_Business_Profile::sender( 'alerts' );
			$mail = is_array( $s ) ? (string) ( $s['email'] ?? '' ) : '';
		}
		if ( ! is_email( $mail ) ) {
			$mail = (string) get_option( 'admin_email', '' );
		}
		return is_email( $mail ) ? 'mailto:' . $mail : home_url( '/' );
	}

	/* ─────────────────────────── subscriptions ───────────────── */

	/** sha256 of this request's sign-in session token ('' without one). Never the token. */
	public static function session_hash(): string {
		$t = function_exists( 'wp_get_session_token' ) ? (string) wp_get_session_token() : '';
		return '' === $t ? '' : hash( 'sha256', $t );
	}

	/** May the server send to this endpoint? Only on a known push service; no kernel check, no sending. */
	public static function endpoint_ok( string $endpoint ): bool {
		return class_exists( 'ZDZ_Push_Endpoint' ) && '' !== ZDZ_Push_Endpoint::validate( $endpoint );
	}

	/** Save (or re-own) a subscription for this person and session. False for an endpoint the server would not send to. */
	public static function save_subscription( int $uid, string $endpoint, string $p256dh, string $auth, string $ua = '' ): bool {
		if ( $uid <= 0 || ! self::endpoint_ok( $endpoint ) ) {
			return false;
		}
		global $wpdb;
		$h = hash( 'sha256', $endpoint );
		$wpdb->delete( self::table(), array( 'endpoint_hash' => $h ), array( '%s' ) );   // the browser may have a new owner
		$sh = self::session_hash();
		return (bool) $wpdb->insert( self::table(), array(
			'user_id'       => $uid,
			'endpoint'      => $endpoint,
			'endpoint_hash' => $h,
			'p256dh'        => substr( $p256dh, 0, 255 ),
			'auth'          => substr( $auth, 0, 255 ),
			'user_agent'    => substr( $ua, 0, 255 ),
			'session_hash'  => '' !== $sh ? $sh : null,
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
		), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
	}

	/** Remove one endpoint: with a person, only theirs; without (the push service said gone), anyone's. */
	public static function remove_subscription( string $endpoint, int $uid = 0 ): void {
		global $wpdb;
		$where = array( 'endpoint_hash' => hash( 'sha256', $endpoint ) );
		$fmt   = array( '%s' );
		if ( $uid > 0 ) {
			$where['user_id'] = $uid;
			$fmt[]            = '%d';
		}
		$wpdb->delete( self::table(), $where, $fmt );
	}

	/** Sign-out: this session's subscriptions go, nothing else. No session: nothing is deleted (never everything). */
	public static function on_logout(): void {
		$uid = (int) get_current_user_id();
		$sh  = self::session_hash();
		if ( $uid <= 0 || '' === $sh ) {
			return;
		}
		global $wpdb;
		$wpdb->delete( self::table(), array( 'user_id' => $uid, 'session_hash' => $sh ), array( '%d', '%s' ) );
	}

	public static function get_user_subscriptions( int $uid ): array {
		global $wpdb;
		$r = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY created_at DESC', $uid ), ARRAY_A );   // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $r ) ? $r : array();
	}

	/** sha256 of each of this person's endpoints (a device recognises itself; no endpoint leaves the server). */
	public static function subscribed_hashes( int $uid ): array {
		return array_values( array_unique( array_map( function ( $r ) { return (string) $r['endpoint_hash']; }, self::get_user_subscriptions( $uid ) ) ) );
	}

	/** Of those, the ones saved in THIS sign-in session. */
	public static function current_hashes( int $uid ): array {
		$sh = self::session_hash();
		if ( '' === $sh ) {
			return array();
		}
		return array_values( array_unique( array_map( function ( $r ) { return (string) $r['endpoint_hash']; }, array_filter( self::get_user_subscriptions( $uid ), function ( $r ) use ( $sh ) {
			return (string) ( $r['session_hash'] ?? '' ) === $sh;
		} ) ) ) );
	}

	/* ─────────────────────────── the registry verb ───────────── */

	public static function register_capabilities( $caps ) {
		$caps = is_array( $caps ) ? $caps : array();
		$caps[ self::VERB_SETUP ] = array(
			'callable'   => array( __CLASS__, 'from_capability' ),
			'descriptor' => array(
				'verb'          => self::VERB_SETUP,
				'source'        => 'sales-analytics',
				'app'           => ZANA_APP_ID,
				'min_tier'      => 'viewer',
				'kiosk'         => false,
				'kiosk_bounded' => false,
				'side_effect'   => false,
				'confirm'       => false,
				'needs_app'     => true,
				'outside'       => false,
				'summary'       => 'How this person turns on phone notifications for Chat answers on a device: key, worker, endpoints, and which of their subscriptions are current. Never for the shared device.',
			),
		);
		return $caps;
	}

	/** The channel for the signed-in person (the registry stamps who). */
	public static function from_capability( $args ): array {
		$args = is_array( $args ) ? $args : array();
		$uid  = (int) ( $args['user_id'] ?? 0 );
		$no   = array( 'success' => false, 'error' => __( 'Chat notifications are not available on this account.', 'zorderz' ) );
		if ( ! empty( $args['is_kiosk'] ) || self::refused( $uid ) ) {
			return $no;
		}
		$key = self::get_public_key();
		if ( '' === $key ) {
			return array( 'success' => false, 'error' => __( 'Chat notifications are not set up on the server.', 'zorderz' ) );
		}
		$sw    = ZANA_URL . 'assets/js/sw.js';
		$path  = (string) wp_parse_url( $sw, PHP_URL_PATH );
		$nonce = wp_create_nonce( ZANA_NONCE );
		return array(
			'success'           => true,
			'channel'           => 'chat',
			'label'             => __( 'Chat answers', 'zorderz' ),
			'parts'             => array( __( 'Chat answers', 'zorderz' ) ),
			'vapid_public_key'  => $key,
			'sw_url'            => $sw,
			'sw_scope'          => substr( $path, 0, (int) strrpos( $path, '/' ) + 1 ),
			'subscribe'         => array( 'action' => 'zana_push_subscribe', 'nonce' => $nonce ),
			'unsubscribe'       => array( 'action' => 'zana_push_unsubscribe', 'nonce' => $nonce ),
			'test'              => array( 'action' => 'zana_push_test', 'nonce' => $nonce ),
			'subscribed_hashes' => self::subscribed_hashes( $uid ),
			'current_hashes'    => self::current_hashes( $uid ),
		);
	}

	/* ─────────────────────────── admin-ajax ──────────────────── */

	private static function ajax_gate(): int {
		check_ajax_referer( ZANA_NONCE, 'nonce' );
		$uid = (int) get_current_user_id();
		if ( self::refused( $uid ) ) {
			wp_send_json_error( array( 'message' => __( 'Notifications are off on this account.', 'zorderz' ) ), 403 );
		}
		return $uid;
	}

	/**
	 * An endpoint as the browser gave it (https only; percent-encoded octets kept). '' when it is not one. Only a hash of
	 * it is used to find the asker's own row; storing and sending check it fully (endpoint_ok()).
	 */
	public static function clean_endpoint( $raw ): string {
		$e = trim( (string) $raw );
		return ( 0 === stripos( $e, 'https://' ) && strlen( $e ) <= 2000 && ! preg_match( '/[\s<>"\']/', $e ) ) ? $e : '';
	}

	public static function ajax_subscribe(): void {
		$uid      = self::ajax_gate();
		$endpoint = self::clean_endpoint( wp_unslash( $_POST['endpoint'] ?? '' ) );   // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ajax_gate() checked the nonce; clean_endpoint() admits only an https URL with no spaces, quotes or angle brackets
		$p256dh   = preg_replace( '/[^A-Za-z0-9_\-=]/', '', (string) wp_unslash( $_POST['p256dh'] ?? '' ) );   // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ajax_gate() checked the nonce; only base64url characters are kept
		$auth     = preg_replace( '/[^A-Za-z0-9_\-=]/', '', (string) wp_unslash( $_POST['auth'] ?? '' ) );   // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ajax_gate() checked the nonce; only base64url characters are kept
		if ( '' === $endpoint || '' === $p256dh || '' === $auth ) {
			wp_send_json_error( array( 'message' => __( 'Missing subscription data.', 'zorderz' ) ) );
		}
		if ( ! self::endpoint_ok( $endpoint ) ) {
			wp_send_json_error( array( 'message' => __( 'This browser\'s notification service is not one this site sends to', 'zorderz' ), 'code' => 'zana_push_endpoint' ), 400 );
		}
		if ( ! self::save_subscription( $uid, $endpoint, $p256dh, $auth, sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ) ) ) {
			wp_send_json_error( array( 'message' => __( 'The subscription could not be saved. Try again', 'zorderz' ) ), 500 );
		}
		wp_send_json_success( array( 'subscribed' => true, 'endpoint_hash' => hash( 'sha256', $endpoint ) ) );
	}

	public static function ajax_unsubscribe(): void {
		check_ajax_referer( ZANA_NONCE, 'nonce' );
		$uid      = (int) get_current_user_id();
		$endpoint = self::clean_endpoint( wp_unslash( $_POST['endpoint'] ?? '' ) );   // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- check_ajax_referer() above; clean_endpoint() admits only an https URL with no spaces, quotes or angle brackets
		if ( $uid <= 0 || '' === $endpoint ) {
			wp_send_json_error( array( 'message' => __( 'Missing endpoint.', 'zorderz' ) ) );
		}
		self::remove_subscription( $endpoint, $uid );   // the asker's own only
		wp_send_json_success( array( 'unsubscribed' => true ) );
	}

	public static function ajax_test(): void {
		$uid = self::ajax_gate();
		$r   = self::send_test( $uid, self::clean_endpoint( wp_unslash( $_POST['endpoint'] ?? '' ) ) );   // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ajax_gate() checked the nonce; clean_endpoint() admits only an https URL with no spaces, quotes or angle brackets
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message(), 'code' => $r->get_error_code() ), 'zana_test_limit' === $r->get_error_code() ? 429 : 400 );
		}
		if ( empty( $r['sent'] ) ) {
			wp_send_json_error( array( 'message' => '' !== (string) ( $r['reason'] ?? '' ) ? $r['reason'] : __( 'Nothing was sent.', 'zorderz' ), 'code' => 'zana_test_refused' ) + $r );
		}
		wp_send_json_success( $r );
	}

	/** Which test stamps of the last hour still count (pure). */
	public static function recent_stamps( $stamps, int $now ): array {
		return array_values( array_filter( is_array( $stamps ) ? array_map( 'intval', $stamps ) : array(), function ( $t ) use ( $now ) {
			return $t > $now - self::TEST_WINDOW;
		} ) );
	}

	/** Send me a test: the asker's own subscriptions (this device's when named, which must be theirs). Five an hour. */
	public static function send_test( int $uid, string $endpoint = '' ) {
		$now   = null !== self::$now ? (int) self::$now : time();
		$stamp = self::recent_stamps( get_user_meta( $uid, self::META_TESTS, true ), $now );
		if ( count( $stamp ) >= self::TEST_LIMIT ) {
			$wait = max( 1, (int) ceil( ( min( $stamp ) + self::TEST_WINDOW - $now ) / 60 ) );
			/* translators: 1: tests allowed per hour, 2: minutes to wait */
			return new WP_Error( 'zana_test_limit', sprintf( __( 'That is %1$d tests this hour. Try again in %2$d min.', 'zorderz' ), self::TEST_LIMIT, $wait ) );
		}
		$subs = self::get_user_subscriptions( $uid );
		if ( '' !== $endpoint ) {
			$h    = hash( 'sha256', $endpoint );
			$subs = array_values( array_filter( $subs, function ( $r ) use ( $h ) { return (string) $r['endpoint_hash'] === $h; } ) );
			if ( ! $subs ) {
				return new WP_Error( 'zana_test_not_this_device', __( 'This device is not subscribed for Chat answers. Turn notifications on first.', 'zorderz' ) );
			}
		} elseif ( ! $subs ) {
			return new WP_Error( 'zana_test_none', __( 'No device is subscribed for Chat answers yet.', 'zorderz' ) );
		}
		$stamp[] = $now;
		update_user_meta( $uid, self::META_TESTS, $stamp );
		$sent = 0; $pruned = 0; $failed = 0; $reason = '';
		$name = class_exists( 'ZDZ_Business_Profile' ) ? (string) ZDZ_Business_Profile::name() : '';
		foreach ( $subs as $sub ) {
			$r = self::send( $sub, '' !== $name ? $name : __( 'Test', 'zorderz' ), __( 'Notifications work on this device (Chat answers).', 'zorderz' ), home_url( '/' ), 'zana-push-test' );
			if ( ! empty( $r['success'] ) ) {
				$sent++;
			} elseif ( ! empty( $r['dropped'] ) || in_array( (int) ( $r['status'] ?? 0 ), array( 404, 410 ), true ) ) {
				$pruned++;
			} else {
				$failed++;
				$reason = '' === $reason ? self::refusal( $r, (string) $sub['endpoint'] ) : $reason;
			}
		}
		return array( 'sent' => $sent, 'pruned' => $pruned, 'failed' => $failed, 'reason' => $reason );
	}

	/**
	 * A push service's refusal in a person's words: the service's host (one of the known push services) and its status
	 * code, said plainly. Never any part of what the service sent back.
	 */
	public static function refusal( array $r, string $endpoint ): string {
		$host  = (string) wp_parse_url( $endpoint, PHP_URL_HOST );
		$code  = (int) ( $r['status'] ?? 0 );
		$words = class_exists( 'ZDZ_Push_Endpoint' ) ? ZDZ_Push_Endpoint::status_words( $code ) : __( 'it refused the message', 'zorderz' );
		/* translators: 1: a push service's host name, 2: what went wrong, in words */
		return sprintf( __( 'The push service (%1$s) did not deliver it: %2$s', 'zorderz' ), $host, $words ) . ( $code ? ' (' . $code . ')' : '' );
	}

	/* ─────────────────────────── sending ─────────────────────── */

	/** "Your answer is ready" to every device of this person (never the shared device). Returns { sent, failed }. */
	public static function notify_answer( int $uid, int $session_id, string $title = '' ): array {
		if ( self::refused( $uid ) ) {
			return array( 'sent' => 0, 'failed' => 0 );
		}
		$subs = self::get_user_subscriptions( $uid );
		if ( ! $subs ) {
			return array( 'sent' => 0, 'failed' => 0 );
		}
		$body = '' !== trim( $title )
			/* translators: %s: the conversation's title */
			? sprintf( __( 'Your answer for "%s" is ready.', 'zorderz' ), mb_substr( trim( $title ), 0, 60 ) )
			: __( 'Your answer is ready.', 'zorderz' );
		$sent = 0;
		$failed = 0;
		foreach ( $subs as $sub ) {
			$r = self::send( $sub, __( 'Chat', 'zorderz' ), $body, home_url( '/?zana_session=' . $session_id ), 'zana-session-' . $session_id );
			! empty( $r['success'] ) ? $sent++ : $failed++;
		}
		return array( 'sent' => $sent, 'failed' => $failed );
	}

	/**
	 * One push to one subscription. { success, status, error?, dropped? }. A stored endpoint that is not on a known push
	 * service is removed and never sent to (dropped). The error is this code's own words, never the service's reply.
	 */
	public static function send( array $subscription, string $title, string $body, string $url = '', string $tag = '' ): array {
		$endpoint = (string) ( $subscription['endpoint'] ?? '' );
		$p256dh   = (string) ( $subscription['p256dh'] ?? '' );
		$auth     = (string) ( $subscription['auth'] ?? '' );
		if ( '' === $endpoint || '' === $p256dh || '' === $auth ) {
			return array( 'success' => false, 'status' => 0, 'error' => 'Invalid subscription data.' );
		}
		if ( ! self::endpoint_ok( $endpoint ) ) {
			self::remove_subscription( $endpoint );
			return array( 'success' => false, 'status' => 0, 'error' => 'Not a known push service.', 'dropped' => true );
		}
		$payload = wp_json_encode( array(
			'title' => $title,
			'body'  => $body,
			'url'   => '' !== $url ? $url : home_url( '/' ),
			'tag'   => '' !== $tag ? $tag : 'zana-' . time(),
		) );
		try {
			$encrypted = self::encrypt_payload( (string) $payload, $p256dh, $auth );
			$vapid     = self::build_vapid_headers( $endpoint );
		} catch ( \Exception $e ) {
			return array( 'success' => false, 'status' => 0, 'error' => 'The push could not be prepared.' );
		}
		// No Topic header: one push service refuses it; a newer push still replaces an older one on screen by its tag.
		$response = ZDZ_Push_Endpoint::post( $endpoint, array(
			'headers' => array(
				'Content-Type'     => 'application/octet-stream',
				'Content-Encoding' => 'aes128gcm',
				'Content-Length'   => strlen( $encrypted ),
				'TTL'              => '86400',
				'Urgency'          => 'normal',
				'Authorization'    => $vapid['authorization'],
			),
			'body'    => $encrypted,
			'timeout' => 20,
		) );
		if ( is_wp_error( $response ) ) {
			if ( 'zdz_push_endpoint_refused' === $response->get_error_code() ) {
				self::remove_subscription( $endpoint );
				return array( 'success' => false, 'status' => 0, 'error' => 'Not a known push service.', 'dropped' => true );
			}
			return array( 'success' => false, 'status' => 0, 'error' => 'The push service could not be reached.' );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 410 === $status || 404 === $status ) {
			self::remove_subscription( $endpoint );
			return array( 'success' => false, 'status' => $status, 'error' => 'Subscription expired.' );
		}
		if ( $status >= 200 && $status < 300 ) {
			if ( ! empty( $subscription['id'] ) ) {
				global $wpdb;
				$wpdb->update( self::table(), array( 'last_used_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $subscription['id'] ), array( '%s' ), array( '%d' ) );
			}
			return array( 'success' => true, 'status' => $status );
		}
		return array( 'success' => false, 'status' => $status, 'error' => 'The push service refused it.' );
	}

	// ── VAPID JWT ─────────────────────────────────────────────────

	/**
	 * Build the VAPID Authorization header value.
	 */
	private static function build_vapid_headers( $endpoint ) {
		$pem = self::get_private_pem();
		$pub = self::get_public_key();

		if ( empty( $pem ) || empty( $pub ) ) {
			throw new \Exception( 'VAPID keys not configured.' );
		}

		$audience = self::get_origin( $endpoint );

		// JWT header
		$header = self::base64url_encode( wp_json_encode( array(
			'typ' => 'JWT',
			'alg' => 'ES256',
		) ) );

		// JWT payload
		$payload = self::base64url_encode( wp_json_encode( array(
			'aud' => $audience,
			'exp' => time() + self::JWT_TTL,
			'sub' => self::subject(),
		) ) );

		$signing_input = $header . '.' . $payload;

		// Sign with ECDSA P-256
		$key = openssl_pkey_get_private( $pem );
		if ( ! $key ) {
			throw new \Exception( 'Failed to load VAPID private key.' );
		}

		$sig = '';
		if ( ! openssl_sign( $signing_input, $sig, $key, OPENSSL_ALGO_SHA256 ) ) {
			throw new \Exception( 'VAPID signing failed.' );
		}

		// Convert DER signature to raw R||S (64 bytes)
		$raw_sig = self::der_to_raw( $sig );

		$jwt = $signing_input . '.' . self::base64url_encode( $raw_sig );

		return array(
			'authorization' => 'vapid t=' . $jwt . ', k=' . $pub,
		);
	}

	// ── Payload Encryption (RFC 8291 + RFC 8188) ──────────────────

	/**
	 * Encrypt a push payload using aes128gcm content encoding.
	 *
	 * @param string $payload  Plaintext payload (JSON string).
	 * @param string $p256dh   Subscriber's public key (URL-safe base64).
	 * @param string $auth     Subscriber's auth secret (URL-safe base64).
	 * @return string Encrypted binary payload.
	 */
	private static function encrypt_payload( $payload, $p256dh, $auth ) {
		// Decode subscriber keys
		$ua_public = self::base64url_decode( $p256dh );
		$auth_secret = self::base64url_decode( $auth );

		if ( strlen( $ua_public ) !== 65 ) {
			throw new \Exception( 'Invalid p256dh key length: ' . (int) strlen( $ua_public ) );
		}
		if ( strlen( $auth_secret ) !== 16 ) {
			throw new \Exception( 'Invalid auth secret length: ' . (int) strlen( $auth_secret ) );
		}

		// Generate ephemeral ECDH key pair
		$ephemeral_key = openssl_pkey_new( array(
			'curve_name'       => 'prime256v1',
			'private_key_type' => OPENSSL_KEYTYPE_EC,
		) );
		if ( ! $ephemeral_key ) {
			throw new \Exception( 'Failed to generate ephemeral EC key.' );
		}

		$ephemeral_details = openssl_pkey_get_details( $ephemeral_key );
		$ex = str_pad( $ephemeral_details['ec']['x'], 32, "\0", STR_PAD_LEFT );
		$ey = str_pad( $ephemeral_details['ec']['y'], 32, "\0", STR_PAD_LEFT );
		$as_public = "\x04" . $ex . $ey;

		// Perform ECDH to get shared secret
		// Convert subscriber's uncompressed public key to PEM for openssl
		$ua_pem = self::raw_public_to_pem( $ua_public );

		// Use openssl to compute shared secret
		$shared_secret = '';
		if ( ! openssl_pkey_export( $ephemeral_key, $ephemeral_pem ) ) {
			throw new \Exception( 'Failed to export ephemeral key.' );
		}

		$shared_secret = self::compute_ecdh( $ephemeral_pem, $ua_pem );

		// RFC 8291: IKM derivation
		// info = "WebPush: info\x00" + ua_public + as_public
		$ikm_info = "WebPush: info\x00" . $ua_public . $as_public;
		$ikm = self::hkdf( $auth_secret, $shared_secret, $ikm_info, 32 );

		// Generate random salt (16 bytes)
		$salt = random_bytes( 16 );

		// Derive content encryption key (CEK) and nonce
		$cek_info  = "Content-Encoding: aes128gcm\x00";
		$nonce_info = "Content-Encoding: nonce\x00";
		$cek   = self::hkdf( $salt, $ikm, $cek_info, 16 );
		$nonce = self::hkdf( $salt, $ikm, $nonce_info, 12 );

		// Encrypt with AES-128-GCM
		// Add padding delimiter \x02 (last record indicator)
		$padded = $payload . "\x02";

		$tag = '';
		$ciphertext = openssl_encrypt(
			$padded,
			'aes-128-gcm',
			$cek,
			OPENSSL_RAW_DATA,
			$nonce,
			$tag,
			'',  // AAD
			16   // tag length
		);

		if ( $ciphertext === false ) {
			throw new \Exception( 'AES-GCM encryption failed.' );
		}

		// aes128gcm header: salt(16) + rs(4, uint32) + idlen(1) + keyid(65)
		$rs = pack( 'N', 4096 ); // record size
		$idlen = chr( 65 );       // key ID length = 65 (uncompressed public key)

		return $salt . $rs . $idlen . $as_public . $ciphertext . $tag;
	}

	// ── ECDH via OpenSSL ──────────────────────────────────────────

	/**
	 * Compute ECDH shared secret using openssl_pkey_derive (PHP 7.3+).
	 * Falls back to a manual approach if needed.
	 */
	private static function compute_ecdh( $private_pem, $peer_pub_pem ) {
		$priv_key = openssl_pkey_get_private( $private_pem );
		$pub_key  = openssl_pkey_get_public( $peer_pub_pem );

		if ( ! $priv_key || ! $pub_key ) {
			throw new \Exception( 'Failed to load keys for ECDH.' );
		}

		if ( function_exists( 'openssl_pkey_derive' ) ) {
			$shared = openssl_pkey_derive( $pub_key, $priv_key, 32 );
			if ( $shared === false ) {
				throw new \Exception( 'openssl_pkey_derive failed.' );
			}
			return $shared;
		}

		throw new \Exception( 'openssl_pkey_derive not available (requires PHP 7.3+).' );
	}

	/**
	 * Convert a raw uncompressed EC public key (65 bytes, 04||x||y) to PEM.
	 */
	private static function raw_public_to_pem( $raw ) {
		// SubjectPublicKeyInfo ASN.1 wrapper for P-256
		// SEQUENCE { SEQUENCE { OID ecPublicKey, OID prime256v1 }, BIT STRING { raw } }
		$prefix = hex2bin(
			'3059301306072a8648ce3d020106082a8648ce3d030107034200'
		);
		$der = $prefix . $raw;
		$b64 = chunk_split( base64_encode( $der ), 64, "\n" );
		return "-----BEGIN PUBLIC KEY-----\n{$b64}-----END PUBLIC KEY-----\n";
	}

	// ── Crypto Helpers ────────────────────────────────────────────

	/**
	 * HKDF (RFC 5869) — Extract + Expand.
	 */
	private static function hkdf( $salt, $ikm, $info, $length ) {
		// Extract
		$prk = hash_hmac( 'sha256', $ikm, $salt, true );
		// Expand
		$output = '';
		$t = '';
		$counter = 1;
		while ( strlen( $output ) < $length ) {
			$t = hash_hmac( 'sha256', $t . $info . chr( $counter ), $prk, true );
			$output .= $t;
			$counter++;
		}
		return substr( $output, 0, $length );
	}

	/**
	 * Convert DER-encoded ECDSA signature to raw R||S format (64 bytes).
	 */
	private static function der_to_raw( $der ) {
		$pos = 0;
		if ( ord( $der[ $pos++ ] ) !== 0x30 ) {
			throw new \Exception( 'Invalid DER signature.' );
		}

		// Total length
		$len = ord( $der[ $pos++ ] );
		if ( $len & 0x80 ) {
			$pos += ( $len & 0x7f );
		}

		// R
		if ( ord( $der[ $pos++ ] ) !== 0x02 ) {
			throw new \Exception( 'Invalid DER: expected INTEGER for R.' );
		}
		$r_len = ord( $der[ $pos++ ] );
		$r = substr( $der, $pos, $r_len );
		$pos += $r_len;

		// S
		if ( ord( $der[ $pos++ ] ) !== 0x02 ) {
			throw new \Exception( 'Invalid DER: expected INTEGER for S.' );
		}
		$s_len = ord( $der[ $pos++ ] );
		$s = substr( $der, $pos, $s_len );

		// Pad/trim R and S to 32 bytes each
		$r = ltrim( $r, "\x00" );
		$s = ltrim( $s, "\x00" );
		$r = str_pad( $r, 32, "\x00", STR_PAD_LEFT );
		$s = str_pad( $s, 32, "\x00", STR_PAD_LEFT );

		return $r . $s;
	}

	/**
	 * URL-safe base64 encode.
	 */
	private static function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * URL-safe base64 decode.
	 */
	private static function base64url_decode( $data ) {
		$data = strtr( $data, '-_', '+/' );
		$pad  = strlen( $data ) % 4;
		if ( $pad ) {
			$data .= str_repeat( '=', 4 - $pad );
		}
		return base64_decode( $data );
	}

	/**
	 * Get origin from a URL (scheme + host).
	 */
	private static function get_origin( $url ) {
		$parts = wp_parse_url( $url );
		return $parts['scheme'] . '://' . $parts['host'];
	}
}
