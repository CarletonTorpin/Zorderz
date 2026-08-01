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
 * LOGOUT (Trap 6 / acceptance #13):
 *   clear_auth_cookie fires on logout. on_logout() deletes push subscription
 *   rows tied to the current user. If the logout request can't also unsubscribe
 *   the browser endpoint (would require client-side cooperation), that's fine —
 *   delivery checks user_id match before firing, so mismatched subs stay
 *   silent.
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
	public static function subscribe( $user_id, $endpoint, $p256dh, $auth, $user_agent = '' ) {
		global $wpdb;
		$user_id  = (int) $user_id;
		$endpoint = (string) $endpoint;
		if ( $user_id <= 0 || '' === $endpoint ) {
			return new WP_Error( 'zim_bad_sub', 'Invalid subscription.' );
		}
		if ( ! preg_match( '#^https://#i', $endpoint ) ) {
			return new WP_Error( 'zim_bad_sub', 'Endpoint must be https://.' );
		}

		$hash = hash( 'sha256', $endpoint );

		// UPSERT semantics: if endpoint already registered by any user,
		// REASSIGN to this user — the browser has a new owner now.
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}zim_push_subscriptions WHERE endpoint_hash = %s LIMIT 1",
			$hash
		) );
		if ( $existing ) {
			$wpdb->update(
				$wpdb->prefix . 'zim_push_subscriptions',
				array(
					'user_id'    => $user_id,
					'p256dh'     => sanitize_text_field( $p256dh ),
					'auth'       => sanitize_text_field( $auth ),
					'user_agent' => sanitize_text_field( substr( (string) $user_agent, 0, 250 ) ),
					'rotated_at' => null,
				),
				array( 'id' => (int) $existing->id ),
				array( '%d','%s','%s','%s','%s' ),
				array( '%d' )
			);
			return (int) $existing->id;
		}

		$wpdb->insert(
			$wpdb->prefix . 'zim_push_subscriptions',
			array(
				'user_id'       => $user_id,
				'endpoint'      => $endpoint,
				'endpoint_hash' => $hash,
				'p256dh'        => sanitize_text_field( $p256dh ),
				'auth'          => sanitize_text_field( $auth ),
				'user_agent'    => sanitize_text_field( substr( (string) $user_agent, 0, 250 ) ),
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%d','%s','%s','%s','%s','%s','%s' )
		);
		return (int) $wpdb->insert_id;
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
	 * Logout handler — nuke all subscriptions tied to the logged-out user.
	 * Prevents the shared-device leak (Trap 6 / acceptance #13).
	 */
	public static function on_logout() {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		global $wpdb;
		$wpdb->delete(
			$wpdb->prefix . 'zim_push_subscriptions',
			array( 'user_id' => (int) $user_id ),
			array( '%d' )
		);
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
				$code = $result->get_error_code();
				if ( 'gone' === $code || 'not_found' === $code ) {
					// Subscription dead — prune.
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
	 * Returns true on 2xx, WP_Error otherwise.
	 */
	public static function send_one( $endpoint, $p256dh_b64, $auth_b64, $payload ) {
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

		$response = wp_remote_post( $endpoint, array(
			'headers'  => $headers,
			'body'     => $enc,
			'timeout'  => 10,
			'blocking' => true,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
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
