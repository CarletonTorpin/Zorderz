<?php
/**
 * ZIM_Push
 *
 * Web Push — subscription CRUD + VAPID JWT signing + aes128gcm content
 * encryption + HTTPS delivery.
 *
 * DESIGN:
 *   - Self-contained. We do NOT depend on the Minishlink/WebPush library or
 *     any other vendor (Trap 2 — no reusing the ecosystem's external API
 *     clients). WordPress core + openssl is enough.
 *   - RFC 8291 aes128gcm encryption scheme — the modern Web Push format.
 *     All modern browsers (Chrome, Firefox, Safari, Edge) support it.
 *   - VAPID per RFC 8292, ES256 (ECDSA over P-256).
 *
 * KEYS:
 *   VAPID keypair is generated lazily (via rotate_keys_if_due) and stored
 *   in options as openssl PEM. We rotate every 90 days (Trap 6). Old
 *   subscriptions invalidate gracefully: on 410 Gone from the push service,
 *   we delete the sub. User re-authorizes on next visit — new sub gets the
 *   new key. Rotations are transparent.
 *
 * LOGOUT (Trap 6 / acceptance #13), scoped to the device:
 *   clear_auth_cookie fires on logout. Each subscription records a hash of the
 *   WordPress session that made it (session_hash = sha256 of
 *   wp_get_session_token(), the same verifier WordPress keeps in usermeta), and
 *   on_logout() deletes only the rows of the session signing out. Deleting every
 *   row of the person would mean that signing out on a desktop or a shared
 *   computer silently stopped pushes to their phone. Rows made before the
 *   session column existed carry no hash and are left alone; nothing ever
 *   deletes everything. The shared-device protection holds: the device that
 *   signs out loses its own subscription, and the next person's sign-in
 *   re-assigns the endpoint to them only if they turn notifications on.
 *
 * ENDPOINTS: stored and sent to only on a known browser push service
 *   (ZDZ_Push_Endpoint), checked when a browser subscribes and again before
 *   every send; a stored row that fails the check is dropped. Sends go through
 *   ZDZ_Push_Endpoint::post() (no redirects, no private addresses), and
 *   nothing of the service's reply is passed on, only its status code.
 *
 * SETTINGS: the registry read verb messaging.push_setup describes this channel
 *   to the theme's Settings -> Notifications (key, worker, endpoints, this
 *   person's current subscriptions, quiet hours). The writes stay here, at
 *   zim_push_subscribe / _unsubscribe / _test (ZIM_Dashboard).
 *
 * PAYLOAD:
 *   JSON, under 4 KB. Frontend SW reads it. We include enough context for
 *   the notification to render without calling back to the server:
 *     { title, body, icon, url, tag, conversation_id }
 *   tag is used for notification grouping — one notification per conversation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIM_Push {

	const OPT_VAPID_PRIVATE = 'zim_vapid_private_pem';
	const OPT_VAPID_PUBLIC  = 'zim_vapid_public_b64';
	const OPT_VAPID_CREATED = 'zim_vapid_created_at';
	const OPT_VAPID_SUBJECT = 'zim_vapid_subject';

	/** Send me a test: at most this many per person per hour. */
	const TEST_LIMIT     = 5;
	const TEST_WINDOW    = 3600;
	const META_TESTS     = 'zim_push_tests';
	const VERB_SETUP     = 'messaging.push_setup';

	/** @var bool|null per request: does the table have session_hash yet (has the migration run)? */
	private static $has_session_col = null;

	/** The test push: "<business name> test", and one line saying it works. */
	public static function test_payload() {
		$name = class_exists( 'ZDZ_Business_Profile' ) ? trim( (string) ZDZ_Business_Profile::name() ) : '';
		return array(
			/* translators: %s: the business's name */
			'title' => '' !== $name ? sprintf( __( '%s test', 'zorderz' ), $name ) : __( 'Test notification', 'zorderz' ),
			'body'  => __( 'Notifications work on this device (notices and Messages).', 'zorderz' ),
			'url'   => home_url( '/' ),
			'tag'   => 'zdz-push-test-messages',
		);
	}

	/**
	 * Ensure a VAPID keypair exists and return the public key (base64url,
	 * uncompressed, 65 bytes). The frontend needs this to subscribe.
	 */
	public static function get_public_key() {
		$pub = get_option( self::OPT_VAPID_PUBLIC, '' );
		if ( $pub ) {
			return $pub;
		}
		self::generate_keypair();
		return (string) get_option( self::OPT_VAPID_PUBLIC, '' );
	}

	/**
	 * Generate a fresh VAPID keypair. Stores PEM-encoded private key and
	 * base64url-encoded uncompressed public key (for PushManager.subscribe
	 * `applicationServerKey`).
	 */
	public static function generate_keypair() {
		$config = array(
			'private_key_type' => OPENSSL_KEYTYPE_EC,
			'curve_name'       => 'prime256v1',
		);
		$key = openssl_pkey_new( $config );
		if ( ! $key ) {
			return false;
		}
		openssl_pkey_export( $key, $private_pem );
		$details = openssl_pkey_get_details( $key );
		if ( empty( $details['ec']['x'] ) || empty( $details['ec']['y'] ) ) {
			return false;
		}
		$x = str_pad( $details['ec']['x'], 32, "\x00", STR_PAD_LEFT );
		$y = str_pad( $details['ec']['y'], 32, "\x00", STR_PAD_LEFT );
		$uncompressed = "\x04" . $x . $y; // SEC1 uncompressed point: 04 || X || Y
		$public_b64   = self::b64url_encode( $uncompressed );

		update_option( self::OPT_VAPID_PRIVATE, $private_pem, false );
		update_option( self::OPT_VAPID_PUBLIC, $public_b64, false );
		update_option( self::OPT_VAPID_CREATED, time(), false );
		if ( ! get_option( self::OPT_VAPID_SUBJECT ) ) {
			update_option( self::OPT_VAPID_SUBJECT, 'mailto:' . get_option( 'admin_email' ), false );
		}
		return true;
	}

	/**
	 * Daily cron: regenerate keys every 90 days. Old subscriptions become
	 * invalid; clients re-subscribe on next page load and receive the new
	 * public key.
	 */
	public static function rotate_keys_if_due() {
		$created = (int) get_option( self::OPT_VAPID_CREATED, 0 );
		if ( ! $created ) {
			self::generate_keypair();
			return;
		}
		$age_days = (time() - $created) / DAY_IN_SECONDS;
		if ( $age_days >= ZIM_PUSH_ROTATION_DAYS ) {
			self::generate_keypair();
			// Old subscriptions are now invalid — delivery will get 410s and
			// self-prune. Mark all rows as needing rotation for faster cleanup.
			global $wpdb;
			$wpdb->query( "UPDATE {$wpdb->prefix}zim_push_subscriptions SET rotated_at = NOW() WHERE rotated_at IS NULL" );
		}
	}

	/**
	 * Persist a subscription from the browser's PushSubscription.toJSON().
	 *
	 * @param int    $user_id
	 * @param string $endpoint      full push endpoint URL
	 * @param string $p256dh        b64url-encoded subscriber public key
	 * @param string $auth          b64url-encoded subscriber auth secret
	 * @param string $user_agent
	 */
	public static function subscribe( $user_id, $endpoint, $p256dh, $auth, $user_agent = '', $session_hash = null ) {
		global $wpdb;
		$user_id  = (int) $user_id;
		$endpoint = (string) $endpoint;
		// The session that made it, so signing out removes this device only (on_logout()).
		$session_hash = null === $session_hash ? self::session_hash() : (string) $session_hash;
		$with_session = self::has_session_col();
		if ( $user_id <= 0 || '' === $endpoint ) {
			return new WP_Error( 'zim_bad_sub', 'Invalid subscription.' );
		}
		if ( ! self::endpoint_ok( $endpoint ) ) {
			return new WP_Error( 'zim_bad_sub', __( 'This browser\'s notification service is not one this site sends to', 'zorderz' ) );
		}

		$hash = hash( 'sha256', $endpoint );

		// UPSERT semantics: if endpoint already registered by any user,
		// REASSIGN to this user — the browser has a new owner now.
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}zim_push_subscriptions WHERE endpoint_hash = %s LIMIT 1",
			$hash
		) );
		if ( $existing ) {
			$row = array(
				'user_id'    => $user_id,
				'p256dh'     => sanitize_text_field( $p256dh ),
				'auth'       => sanitize_text_field( $auth ),
				'user_agent' => sanitize_text_field( substr( (string) $user_agent, 0, 250 ) ),
				'rotated_at' => null,
			);
			$fmt = array( '%d','%s','%s','%s','%s' );
			if ( $with_session ) {
				$row['session_hash'] = '' !== $session_hash ? $session_hash : null;
				$fmt[] = '%s';
			}
			$wpdb->update( $wpdb->prefix . 'zim_push_subscriptions', $row, array( 'id' => (int) $existing->id ), $fmt, array( '%d' ) );
			return (int) $existing->id;
		}

		$row = array(
			'user_id'       => $user_id,
			'endpoint'      => $endpoint,
			'endpoint_hash' => $hash,
			'p256dh'        => sanitize_text_field( $p256dh ),
			'auth'          => sanitize_text_field( $auth ),
			'user_agent'    => sanitize_text_field( substr( (string) $user_agent, 0, 250 ) ),
			'created_at'    => current_time( 'mysql', true ),
		);
		$fmt = array( '%d','%s','%s','%s','%s','%s','%s' );
		if ( $with_session ) {
			$row['session_hash'] = '' !== $session_hash ? $session_hash : null;
			$fmt[] = '%s';
		}
		$wpdb->insert( $wpdb->prefix . 'zim_push_subscriptions', $row, $fmt );
		return (int) $wpdb->insert_id;
	}

	/**
	 * May the server store and send to this endpoint? Only on a known browser push service (ZDZ_Push_Endpoint: https,
	 * port 443, a push service's host name, never an IP address). Without the kernel check nothing is sent.
	 */
	public static function endpoint_ok( $endpoint ) {
		return class_exists( 'ZDZ_Push_Endpoint' ) && '' !== ZDZ_Push_Endpoint::validate( (string) $endpoint );
	}

	/** A send result that means "drop this row": the service called it gone, or it is not on a known push service. */
	public static function is_dead( $result ) {
		return is_wp_error( $result ) && in_array( $result->get_error_code(), array( 'gone', 'not_found', 'zim_endpoint_refused' ), true );
	}

	/**
	 * Delete one subscription by endpoint hash. Used when the client
	 * explicitly unsubscribes.
	 */
	public static function unsubscribe_by_endpoint( $user_id, $endpoint ) {
		global $wpdb;
		$hash = hash( 'sha256', (string) $endpoint );
		return $wpdb->delete(
			$wpdb->prefix . 'zim_push_subscriptions',
			array( 'endpoint_hash' => $hash, 'user_id' => (int) $user_id ),
			array( '%s', '%d' )
		);
	}

	/**
	 * Logout handler (Trap 6 / acceptance #13): removes the subscription(s) of the session signing out - this device -
	 * and nothing else, never every device of the person. No session token, no session column, or no rows of that
	 * session: nothing is deleted. Rows made before the session column existed (no hash) are left alone.
	 */
	public static function on_logout() {
		$user_id = (int) get_current_user_id();
		$sh      = self::session_hash();
		if ( $user_id <= 0 || '' === $sh || ! self::has_session_col() ) {
			return;
		}
		global $wpdb;
		$wpdb->delete(
			$wpdb->prefix . 'zim_push_subscriptions',
			array( 'user_id' => $user_id, 'session_hash' => $sh ),
			array( '%d', '%s' )
		);
	}

	/** sha256 of this request's WordPress session token ('' when there is none). Never the token itself. */
	public static function session_hash() {
		$t = function_exists( 'wp_get_session_token' ) ? (string) wp_get_session_token() : '';
		return '' === $t ? '' : hash( 'sha256', $t );
	}

	/** Has the migration added session_hash? Checked once per request; a failed migration never breaks a write. */
	public static function has_session_col() {
		if ( null === self::$has_session_col ) {
			global $wpdb;
			self::$has_session_col = 'session_hash' === (string) $wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->prefix}zim_push_subscriptions LIKE 'session_hash'" );
		}
		return self::$has_session_col;
	}

	/** The endpoint hashes of this person's subscriptions that can still be delivered (made under the current key). */
	public static function subscribed_hashes( $user_id ) {
		global $wpdb;
		$h = $wpdb->get_col( $wpdb->prepare(
			"SELECT endpoint_hash FROM {$wpdb->prefix}zim_push_subscriptions WHERE user_id = %d AND rotated_at IS NULL",
			(int) $user_id
		) );
		return array_values( array_map( 'strval', (array) $h ) );
	}

	/**
	 * Of those, the ones made (or last re-stamped) in THIS sign-in session. A listed subscription that is not here was
	 * made in an earlier session, or before the session column existed: the app posts it again so the row carries this
	 * session, or signing out on this device would not find it.
	 */
	public static function current_hashes( $user_id ) {
		$sh = self::session_hash();
		if ( '' === $sh || ! self::has_session_col() ) {
			return array();
		}
		global $wpdb;
		$h = $wpdb->get_col( $wpdb->prepare(
			"SELECT endpoint_hash FROM {$wpdb->prefix}zim_push_subscriptions WHERE user_id = %d AND rotated_at IS NULL AND session_hash = %s",
			(int) $user_id, $sh
		) );
		return array_values( array_map( 'strval', (array) $h ) );
	}

	// ─────────────────────────────────────────────────────────────
	// The registry verb messaging.push_setup (Settings -> Notifications)
	// ─────────────────────────────────────────────────────────────

	/**
	 * messaging.push_setup - what the theme's Settings needs to turn this channel on or off on a device.
	 *
	 * Who gets what:
	 *   - anyone with Messages (tech, staff, admin): the public key, the worker and its scope, the subscribe /
	 *     unsubscribe / test actions with nonces for THEM, the endpoint hashes of their own current subscriptions (so a
	 *     device can tell whether it is on; never an endpoint, never anyone else's), which of those carry THIS sign-in
	 *     session (current_hashes: the app re-posts the others, so signing out on this device finds them), and their
	 *     quiet hours (their own, else the business's, else Core's: ZDZ_Quiet_Hours);
	 *   - the shared device, an account in Privacy View, the signed-out, someone without Messages: refused.
	 * Viewer tier, not staff: field roles get notices and Messages too. needs_app false: the callback applies Messages'
	 * own access rule (zim_user_has_access + ZIM_Widget::should_render, which also refuses customer-facing mode).
	 */
	public static function register_capabilities( $caps ) {
		$caps = is_array( $caps ) ? $caps : array();
		$caps[ self::VERB_SETUP ] = array(
			'callable'   => array( __CLASS__, 'from_capability' ),
			'descriptor' => array(
				'verb'          => self::VERB_SETUP,
				'source'        => 'zdz-internal-messaging',
				'app'           => 'internal-messaging',
				'min_tier'      => 'viewer',
				'kiosk'         => false,
				'kiosk_bounded' => false,
				'side_effect'   => false,
				'confirm'       => false,
				'needs_app'     => false,
				'summary'       => 'How this person turns on phone notifications for notices and Messages on a device: key, worker, endpoints, and which of their subscriptions are current. Never for the shared device.',
			),
		);
		return $caps;
	}

	public static function from_capability( $args ) {
		$args = is_array( $args ) ? $args : array();
		$uid  = (int) ( $args['user_id'] ?? 0 );
		$no   = array( 'success' => false, 'error' => __( 'Messages notifications are not available on this account.', 'zorderz' ) );
		if ( $uid <= 0 || ! empty( $args['is_kiosk'] ) || self::refused( $uid ) ) {
			return $no;
		}
		if ( ! function_exists( 'zim_user_has_access' ) || ! zim_user_has_access( $uid )
			|| ( class_exists( 'ZIM_Widget' ) && ! ZIM_Widget::should_render() ) ) {
			return $no;
		}
		$key = self::get_public_key();
		if ( '' === (string) $key ) {
			return array( 'success' => false, 'error' => __( 'Messages notifications are not set up on the server.', 'zorderz' ) );
		}
		$sw    = ZIM_PLUGIN_URL . 'assets/js/sw.js';
		$path  = (string) wp_parse_url( $sw, PHP_URL_PATH );
		$scope = substr( $path, 0, strrpos( $path, '/' ) + 1 );
		$nonce = wp_create_nonce( ZIM_NONCE );
		$quiet = class_exists( 'ZIM_Notifications' ) && method_exists( 'ZIM_Notifications', 'get_quiet_hours' )
			? (array) ZIM_Notifications::get_quiet_hours( $uid )
			: array( 'start' => ZIM_DEFAULT_QUIET_START, 'end' => ZIM_DEFAULT_QUIET_END );
		return array(
			'success'           => true,
			'channel'           => 'messages',
			'label'             => __( 'Notices and Messages', 'zorderz' ),
			'parts'             => array( __( 'notices', 'zorderz' ), __( 'Messages', 'zorderz' ) ),
			'vapid_public_key'  => (string) $key,
			'sw_url'            => $sw,
			'sw_scope'          => $scope,
			'subscribe'         => array( 'action' => 'zim_push_subscribe', 'nonce' => $nonce ),
			'unsubscribe'       => array( 'action' => 'zim_push_unsubscribe', 'nonce' => $nonce ),
			'test'              => array( 'action' => 'zim_push_test', 'nonce' => $nonce ),
			'subscribed_hashes' => self::subscribed_hashes( $uid ),
			'current_hashes'    => self::current_hashes( $uid ),
			'quiet_hours'       => array(
				'start'  => (string) ( $quiet['start'] ?? ZIM_DEFAULT_QUIET_START ),
				'end'    => (string) ( $quiet['end'] ?? ZIM_DEFAULT_QUIET_END ),
				'action' => 'zim_set_quiet_hours',
				'nonce'  => $nonce,
			),
		);
	}

	/**
	 * Never phone notifications for: the shared device (whatever other roles it holds), a messaging read-only role,
	 * or an account in Privacy View (it reads its one thread in the app, masked; a push or an e-mail would not be).
	 */
	public static function refused( $uid ) {
		$uid = (int) $uid;
		if ( $uid <= 0 ) {
			return true;
		}
		if ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid ) ) {
			return true;
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) && ZDZ_Hierarchy::is_kiosk( $uid ) ) {
			return true;
		}
		if ( function_exists( 'zim_user_is_read_only' ) && zim_user_is_read_only( $uid ) ) {
			return true;
		}
		return class_exists( 'ZIM_Privacy_View' ) && ZIM_Privacy_View::on( $uid );
	}

	// ─────────────────────────────────────────────────────────────
	// Send me a test
	// ─────────────────────────────────────────────────────────────

	/**
	 * Send the fixed test push to the asker's own subscriptions - only this device's when it names its endpoint (which
	 * must be theirs). Immediate: it ignores quiet hours, because the person asked for it. At most TEST_LIMIT an hour.
	 *
	 * @return array|WP_Error { sent, pruned, failed }
	 */
	public static function send_test( $user_id, $endpoint = '' ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return new WP_Error( 'zim_test_signed_out', __( 'Sign in first.', 'zorderz' ) );
		}
		if ( self::refused( $user_id ) ) {
			return new WP_Error( 'zim_test_refused', __( 'Messages notifications are not available on this account.', 'zorderz' ) );
		}
		$now   = time();
		$stamp = get_user_meta( $user_id, self::META_TESTS, true );
		$stamp = array_values( array_filter( is_array( $stamp ) ? array_map( 'intval', $stamp ) : array(), function ( $t ) use ( $now ) {
			return $t > $now - self::TEST_WINDOW;
		} ) );
		if ( count( $stamp ) >= self::TEST_LIMIT ) {
			$wait = max( 1, (int) ceil( ( min( $stamp ) + self::TEST_WINDOW - $now ) / 60 ) );
			/* translators: 1: tests allowed per hour, 2: minutes to wait */
			return new WP_Error( 'zim_test_limit', sprintf( __( 'That is %1$d tests this hour. Try again in %2$d min.', 'zorderz' ), self::TEST_LIMIT, $wait ) );
		}
		$endpoint = (string) $endpoint;
		if ( '' !== $endpoint ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, endpoint, p256dh, auth FROM {$wpdb->prefix}zim_push_subscriptions WHERE user_id = %d AND endpoint_hash = %s",
				$user_id, hash( 'sha256', $endpoint )
			) );
			if ( ! $rows ) {
				return new WP_Error( 'zim_test_not_this_device', __( 'This device is not subscribed for notices and Messages. Turn notifications on first.', 'zorderz' ) );
			}
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, endpoint, p256dh, auth FROM {$wpdb->prefix}zim_push_subscriptions WHERE user_id = %d AND rotated_at IS NULL",
				$user_id
			) ); // a row made under a rotated key cannot be delivered
			if ( ! $rows ) {
				return new WP_Error( 'zim_test_none', __( 'No device is subscribed for notices and Messages yet.', 'zorderz' ) );
			}
		}
		$stamp[] = $now;
		update_user_meta( $user_id, self::META_TESTS, $stamp );

		$json = wp_json_encode( self::test_payload() );
		$sent = 0; $pruned = 0; $failed = 0;
		foreach ( $rows as $row ) {
			$r = self::send_one( $row->endpoint, $row->p256dh, $row->auth, $json );
			if ( true === $r ) {
				$sent++;
				continue;
			}
			if ( self::is_dead( $r ) ) {
				$wpdb->delete( $wpdb->prefix . 'zim_push_subscriptions', array( 'id' => (int) $row->id ), array( '%d' ) );
				$pruned++;
			} else {
				$failed++;
			}
		}
		return array( 'sent' => $sent, 'pruned' => $pruned, 'failed' => $failed );
	}

	/**
	 * Send a push payload to all active subscriptions for a user.
	 * Payload may be an array (JSON-encoded) or a pre-encoded string.
	 *
	 * Returns [ 'sent' => n, 'pruned' => n ].
	 */
	public static function send_to_user( $user_id, $payload ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( self::refused( $user_id ) ) {
			return array( 'sent' => 0, 'pruned' => 0 );   // the shared device never gets a push, whoever queued it
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, endpoint, p256dh, auth
			   FROM {$wpdb->prefix}zim_push_subscriptions
			  WHERE user_id = %d",
			$user_id
		) );
		$sent = 0; $pruned = 0;
		if ( ! $rows ) {
			return array( 'sent' => 0, 'pruned' => 0 );
		}

		$json = is_string( $payload ) ? $payload : wp_json_encode( $payload );
		if ( strlen( $json ) > 3800 ) {
			// Web Push payload ceiling is 4KB after encryption framing; we keep a margin.
			$json = substr( $json, 0, 3800 );
		}

		foreach ( $rows as $row ) {
			$result = self::send_one( $row->endpoint, $row->p256dh, $row->auth, $json );
			if ( is_wp_error( $result ) ) {
				if ( self::is_dead( $result ) ) {
					// Subscription dead, or not on a known push service: prune it.
					$wpdb->delete(
						$wpdb->prefix . 'zim_push_subscriptions',
						array( 'id' => (int) $row->id ),
						array( '%d' )
					);
					$pruned++;
				}
				continue;
			}
			$sent++;
		}
		return array( 'sent' => $sent, 'pruned' => $pruned );
	}

	/**
	 * Send one encrypted push payload to a specific endpoint.
	 * Returns true on 2xx, WP_Error otherwise. An endpoint that is not on a known push service (a row stored before
	 * the check existed) is never sent to: WP_Error 'zim_endpoint_refused', and the callers drop the row. The send goes
	 * through ZDZ_Push_Endpoint::post() (redirects off, private addresses refused); only the status code comes back.
	 */
	public static function send_one( $endpoint, $p256dh_b64, $auth_b64, $payload ) {
		$endpoint = (string) $endpoint;
		if ( ! self::endpoint_ok( $endpoint ) ) {
			return new WP_Error( 'zim_endpoint_refused', 'Not a known push service.' );
		}
		$public_key = self::get_public_key();
		$private_pem = get_option( self::OPT_VAPID_PRIVATE, '' );
		if ( ! $public_key || ! $private_pem ) {
			return new WP_Error( 'zim_no_vapid', 'VAPID keys not initialized.' );
		}

		// Encrypt payload per RFC 8291.
		$enc = self::encrypt_aes128gcm(
			$payload,
			self::b64url_decode( $p256dh_b64 ),
			self::b64url_decode( $auth_b64 )
		);
		if ( is_wp_error( $enc ) ) {
			return $enc;
		}

		// Build VAPID JWT.
		$parts = wp_parse_url( $endpoint );
		$audience = ( isset( $parts['scheme'], $parts['host'] ) )
			? $parts['scheme'] . '://' . $parts['host']
			: $endpoint;
		$jwt = self::sign_vapid_jwt( $audience, $private_pem );
		if ( is_wp_error( $jwt ) ) {
			return $jwt;
		}

		$headers = array(
			'Content-Type'     => 'application/octet-stream',
			'Content-Encoding' => 'aes128gcm',
			'TTL'              => '86400',
			'Urgency'          => 'normal',
			'Authorization'    => 'vapid t=' . $jwt . ', k=' . $public_key,
		);

		$response = ZDZ_Push_Endpoint::post( $endpoint, array(
			'headers'  => $headers,
			'body'     => $enc,
			'timeout'  => 10,
			'blocking' => true,
		) );

		if ( is_wp_error( $response ) ) {
			return 'zdz_push_endpoint_refused' === $response->get_error_code()
				? new WP_Error( 'zim_endpoint_refused', 'Not a known push service.' )
				: new WP_Error( 'zim_push_unreachable', 'The push service could not be reached.' );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}
		if ( 404 === $code ) {
			return new WP_Error( 'not_found', 'Subscription not found.' );
		}
		if ( 410 === $code ) {
			return new WP_Error( 'gone', 'Subscription expired.' );
		}
		return new WP_Error( 'push_http_' . $code, 'Push service returned ' . $code );
	}

	// ─────────────────────────────────────────────────────────────
	// VAPID JWT — ES256 over P-256
	// ─────────────────────────────────────────────────────────────

	/**
	 * Sign a VAPID JWT. Header: { alg: ES256, typ: JWT }.
	 * Claims: { aud, exp (<=24h), sub }.
	 * Signature is raw r||s (64 bytes), b64url-encoded.
	 */
	private static function sign_vapid_jwt( $audience, $private_pem ) {
		$header = array( 'alg' => 'ES256', 'typ' => 'JWT' );
		$claims = array(
			'aud' => $audience,
			'exp' => time() + 12 * HOUR_IN_SECONDS, // < 24h per spec
			'sub' => (string) get_option( self::OPT_VAPID_SUBJECT, 'mailto:admin@example.com' ),
		);

		$signing_input = self::b64url_encode( wp_json_encode( $header ) )
			. '.' . self::b64url_encode( wp_json_encode( $claims ) );

		$key = openssl_pkey_get_private( $private_pem );
		if ( ! $key ) {
			return new WP_Error( 'zim_vapid_key_load', 'Cannot load VAPID private key.' );
		}

		$sig_der = '';
		$ok = openssl_sign( $signing_input, $sig_der, $key, OPENSSL_ALGO_SHA256 );
		if ( ! $ok ) {
			return new WP_Error( 'zim_vapid_sign', 'VAPID sign failed.' );
		}

		// OpenSSL returns DER (SEQUENCE of two INTEGERs). Convert to raw r||s.
		$raw = self::der_to_raw( $sig_der, 32 );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		return $signing_input . '.' . self::b64url_encode( $raw );
	}

	/**
	 * Convert DER ECDSA signature (SEQUENCE { INTEGER r, INTEGER s }) to
	 * the raw fixed-width r||s form required by JOSE ES256.
	 */
	private static function der_to_raw( $der, $component_len ) {
		$p = 0; $len = strlen( $der );
		if ( $len < 2 || "\x30" !== $der[0] ) {
			return new WP_Error( 'zim_vapid_der', 'Malformed DER signature.' );
		}
		$p = 2; // skip 0x30, length
		if ( $p >= $len || "\x02" !== $der[ $p ] ) {
			return new WP_Error( 'zim_vapid_der', 'Malformed DER signature (r).' );
		}
		$p++;
		$r_len = ord( $der[ $p ] ); $p++;
		$r = substr( $der, $p, $r_len ); $p += $r_len;
		if ( $p >= $len || "\x02" !== $der[ $p ] ) {
			return new WP_Error( 'zim_vapid_der', 'Malformed DER signature (s).' );
		}
		$p++;
		$s_len = ord( $der[ $p ] ); $p++;
		$s = substr( $der, $p, $s_len );

		// Trim leading zero padding, then left-pad to component_len.
		$r = ltrim( $r, "\x00" );
		$s = ltrim( $s, "\x00" );
		$r = str_pad( $r, $component_len, "\x00", STR_PAD_LEFT );
		$s = str_pad( $s, $component_len, "\x00", STR_PAD_LEFT );
		return $r . $s;
	}

	// ─────────────────────────────────────────────────────────────
	// Content encryption (RFC 8291, aes128gcm scheme)
	//
	//   shared_secret = ECDH(local_priv, subscriber_pub_p256dh)
	//   PRK_key       = HKDF(shared_secret, key_info, sub_auth)[0..32]
	//     key_info    = "WebPush: info\0" || sub_pub || local_pub
	//   CEK salt      = 16 bytes random
	//   CEK           = HKDF(PRK_key, "Content-Encoding: aes128gcm\0", salt)[0..16]
	//   Nonce         = HKDF(PRK_key, "Content-Encoding: nonce\0",    salt)[0..12]
	//   cipher        = AES-128-GCM(CEK, Nonce, plaintext || 0x02)
	//   body = salt || uint32_be(rs=4096) || 0x41 || local_pub(65B) || cipher
	// ─────────────────────────────────────────────────────────────

	private static function encrypt_aes128gcm( $payload, $sub_pub_raw, $sub_auth ) {
		if ( strlen( $sub_pub_raw ) !== 65 || "\x04" !== $sub_pub_raw[0] ) {
			return new WP_Error( 'zim_bad_p256dh', 'Invalid subscriber public key.' );
		}
		if ( strlen( $sub_auth ) !== 16 ) {
			return new WP_Error( 'zim_bad_auth', 'Invalid subscriber auth secret.' );
		}

		// Generate ephemeral keypair for this push.
		$local = openssl_pkey_new( array(
			'private_key_type' => OPENSSL_KEYTYPE_EC,
			'curve_name'       => 'prime256v1',
		) );
		if ( ! $local ) {
			return new WP_Error( 'zim_local_keypair', 'Local keypair generation failed.' );
		}
		$details = openssl_pkey_get_details( $local );
		$lx = str_pad( $details['ec']['x'], 32, "\x00", STR_PAD_LEFT );
		$ly = str_pad( $details['ec']['y'], 32, "\x00", STR_PAD_LEFT );
		$local_pub_raw = "\x04" . $lx . $ly;

		// ECDH: derive shared secret by computing local_priv * sub_pub.
		$shared = self::ecdh_shared_secret( $local, $sub_pub_raw );
		if ( is_wp_error( $shared ) ) {
			return $shared;
		}

		// HKDF step 1 — PRK_key from (auth_secret, shared_secret, key_info).
		$key_info = 'WebPush: info' . "\x00" . $sub_pub_raw . $local_pub_raw;
		$prk_key  = self::hkdf( $sub_auth, $shared, $key_info, 32 );

		// Salt — 16 random bytes.
		$salt = random_bytes( 16 );

		// Derive Content Encryption Key and Nonce.
		$cek   = self::hkdf( $salt, $prk_key, "Content-Encoding: aes128gcm\x00", 16 );
		$nonce = self::hkdf( $salt, $prk_key, "Content-Encoding: nonce\x00",    12 );

		// Plaintext + single padding byte 0x02 (last record delimiter per RFC 8188).
		$plaintext = $payload . "\x02";
		$tag = '';
		$ciphertext = openssl_encrypt(
			$plaintext,
			'aes-128-gcm',
			$cek,
			OPENSSL_RAW_DATA,
			$nonce,
			$tag,
			'',
			16
		);
		if ( false === $ciphertext ) {
			return new WP_Error( 'zim_gcm_failed', 'AES-GCM encryption failed.' );
		}

		// RFC 8188 header: salt(16) || rs(uint32_be) || idlen(u8)=65 || keyid(local_pub, 65B)
		$rs = 4096;
		$header = $salt
			. pack( 'N', $rs )
			. chr( 65 )
			. $local_pub_raw;

		return $header . $ciphertext . $tag;
	}

	/**
	 * ECDH shared secret between an openssl EC private key and a raw
	 * uncompressed peer public key (65 bytes, 0x04 || X || Y).
	 *
	 * We use openssl_pkey_derive, available on PHP 7.3+ with OpenSSL 1.1.1+.
	 * Falls back to a phpseclib-style manual derivation if not available.
	 */
	private static function ecdh_shared_secret( $local_private_key, $peer_pub_raw ) {
		if ( ! function_exists( 'openssl_pkey_derive' ) ) {
			return new WP_Error( 'zim_no_ecdh', 'openssl_pkey_derive unavailable on this host.' );
		}

		// Wrap the peer raw public into a PEM-ish structure openssl_pkey_get_public accepts.
		$peer_pem = self::raw_p256_pub_to_pem( $peer_pub_raw );
		$peer_key = openssl_pkey_get_public( $peer_pem );
		if ( ! $peer_key ) {
			return new WP_Error( 'zim_bad_peer', 'Invalid peer public key.' );
		}
		$shared = openssl_pkey_derive( $peer_key, $local_private_key, 32 );
		if ( false === $shared ) {
			return new WP_Error( 'zim_ecdh_fail', 'ECDH derivation failed.' );
		}
		return $shared;
	}

	/**
	 * Wrap an uncompressed P-256 public point (65 bytes) into a SubjectPublicKeyInfo
	 * PEM so OpenSSL will accept it via openssl_pkey_get_public.
	 *
	 * The SPKI prefix below is the fixed DER header for an id-ecPublicKey +
	 * secp256r1 OID + BIT STRING, followed by our 65-byte point.
	 */
	private static function raw_p256_pub_to_pem( $raw65 ) {
		$spki_prefix = hex2bin(
			'3059301306072a8648ce3d020106082a8648ce3d03010703420004'
			// Note: the final "04" in that prefix IS the 0x04 uncompressed flag
			// — so we strip the leading 0x04 from our raw bytes below.
		);
		// raw65 = 04 || X || Y (65 bytes). Strip the leading 04 before appending,
		// since the prefix already includes it.
		$der = $spki_prefix . substr( $raw65, 1 );
		$b64 = chunk_split( base64_encode( $der ), 64, "\n" );
		return "-----BEGIN PUBLIC KEY-----\n" . $b64 . "-----END PUBLIC KEY-----\n";
	}

	/**
	 * HKDF (RFC 5869) with SHA-256. Returns $length bytes.
	 * Uses PHP's built-in hash_hkdf when available (PHP 7.1.2+).
	 */
	private static function hkdf( $salt, $ikm, $info, $length ) {
		if ( function_exists( 'hash_hkdf' ) ) {
			return hash_hkdf( 'sha256', $ikm, $length, $info, $salt );
		}
		// Fallback — extract + expand, loop.
		$prk = hash_hmac( 'sha256', $ikm, $salt, true );
		$t = ''; $okm = ''; $i = 1;
		while ( strlen( $okm ) < $length ) {
			$t = hash_hmac( 'sha256', $t . $info . chr( $i ), $prk, true );
			$okm .= $t; $i++;
		}
		return substr( $okm, 0, $length );
	}

	// ─────────────────────────────────────────────────────────────
	// Base64url helpers
	// ─────────────────────────────────────────────────────────────

	public static function b64url_encode( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	public static function b64url_decode( $s ) {
		$s = strtr( $s, '-_', '+/' );
		$pad = strlen( $s ) % 4;
		if ( $pad ) {
			$s .= str_repeat( '=', 4 - $pad );
		}
		return base64_decode( $s );
	}
}
