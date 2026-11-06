<?php
/**
 * ZDZ_Core_Google_Business — the business's own Google reviews, read from the Google Business Profile API.
 *
 * WHAT IT DOES
 * ------------
 * Lists the reviews on the one Business Profile location an administrator picked, with Google's own total and
 * average star rating. Once the site is connected, the home screen's Google reviews tile shows that total; until
 * then it shows the number typed on Zorderz → Settings.
 *
 * Google approves access to the Business Profile API per Google Cloud project, by hand. Until it has approved the
 * business's project, the project's quota is zero requests a minute: every call here then answers "not approved
 * yet", the Credentials screen says so, the tile keeps showing the typed number, and the site asks again at most
 * once an hour.
 *
 * Read-only. It never replies to a review and never changes anything on Google. Google offers no narrower OAuth
 * scope than business.manage for these reads, so that one scope is asked for, and the API is only ever sent GET
 * requests.
 *
 * WHAT IT KEEPS (none of it is ever printed, logged or sent to a page)
 * ---------------------------------------------------------------------
 *   Zorderz → Credentials → Google Business Profile: the OAuth client ID and secret (ZDZ_Core_Credentials, service
 *   'google_business', registered on zdz_core_credentials_services), typed by an administrator.
 *   zdz_core_gbp_token     [ refresh (sealed), at, by ]   written only by the sign-in callback
 *   zdz_core_gbp_location  [ account, location, title ]   the one location this site reads, picked by an administrator
 *   zdz_core_gbp_status    [ state, at, why ]             '' (never asked) | ok | reconnect | not_approved | error
 *   transient zdz_core_gbp_access        [ t (sealed), exp ]           the short-lived access token
 *   transient zdz_core_gbp_summary       [ total, average, asked_at ]  an hour
 *   transient zdz_core_gbp_summary_fail  [ at, why ]   five minutes after a failure, an hour after "not approved"
 *
 * Sealed means ZDZ_Core_Crypto's fixed-IV format, keyed off wp_salt('auth'), the same as Core's own credential rows.
 * A token that cannot be sealed is not stored at all. Changing the salts makes the sign-in unreadable: press Connect
 * again (ZDZ_Salt_Watch names it).
 *
 * THE SIGN-IN (OAuth 2.0, web server flow)
 * ----------------------------------------
 * Only an administrator (manage_options), from Zorderz → Credentials. Offline access with consent every time, so a
 * refresh token always comes back. The `state` is signed (an HMAC under the site's auth salt, bound to the person
 * and to an expiry ten minutes out) and single-use (its hash is kept for that person and spent by the first answer,
 * right or wrong). A sign-in that did not start on this screen, an old one, or a replayed one is refused.
 *
 * Google's refresh token does not rotate, so a refresh needs no single-flight lock (ZDZ_Token_Service exists for
 * providers whose refresh tokens are single-use); the access token is kept until a minute before it expires.
 *
 * A COPY OF THE SITE
 * ------------------
 * When ZDZ_Staging_Guard says this is a staging or test copy, nothing here calls Google: no sign-in, no token
 * refresh, no review read, no revoke (a revoke from a copy would end the live site's sign-in). The tile shows the
 * typed number there.
 *
 * WHO CALLS IT
 * ------------
 *   ZDZ_KPI_Metrics::google_tile(): is_ready(), summary( true ), fallback_reason(). A home screen asks Google
 *   itself only when nothing fresh is cached, and the whole chain (refresh, call, a 401, the retry) shares four
 *   seconds.
 *   reviews() is public for an app that records reviews (newest first, one page at a time).
 *
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Core_Google_Business {

	const SVC           = 'google_business';
	const SCOPE         = 'https://www.googleapis.com/auth/business.manage';
	const AUTH_URL      = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL     = 'https://oauth2.googleapis.com/token';
	const REVOKE_URL    = 'https://oauth2.googleapis.com/revoke';
	const ACCOUNTS_URL  = 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts';
	const LOCATIONS_FMT = 'https://mybusinessbusinessinformation.googleapis.com/v1/%s/locations';
	const REVIEWS_FMT   = 'https://mybusiness.googleapis.com/v4/%s/%s/reviews';
	const CALLBACK_SLUG = 'zdz-google-business-callback';
	const ANCHOR        = 'zdz-gbp';
	const LOG           = '[zdz-google-business] ';

	const OPT_TOKEN     = 'zdz_core_gbp_token';
	const OPT_LOCATION  = 'zdz_core_gbp_location';
	const OPT_STATUS    = 'zdz_core_gbp_status';
	const T_ACCESS      = 'zdz_core_gbp_access';
	const T_STATE       = 'zdz_core_gbp_state_';    // + user id
	const T_FOUND       = 'zdz_core_gbp_found_';    // + user id
	const T_NOTICE      = 'zdz_core_gbp_notice_';   // + user id
	const T_SUMMARY     = 'zdz_core_gbp_summary';
	const T_SUMMARY_BAD = 'zdz_core_gbp_summary_fail';

	const STATE_TTL        = 600;   // a sign-in must come back within ten minutes
	const SUMMARY_TTL      = 3600;  // the total and average are asked for at most once an hour
	const FAIL_TTL         = 300;   // after a failure, five minutes before a home screen asks again
	const NOT_APPROVED_TTL = 3600;  // while Google has not approved the project, once an hour
	const TILE_BUDGET      = 4;     // seconds a home screen may wait on Google, the whole chain
	const MAX_ACCOUNTS     = 5;     // accounts looked through when finding locations
	const TOKEN_MAX        = 4096;  // a token longer than this is not a token

	const STARS = array( 'ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5 );

	/** Tests set "now" (unix). */
	public static $now = null;

	/**
	 * Tests hand a callable ( string $method, string $url, array $args ): array{0:int,1:string} that answers
	 * [ HTTP status (0: no answer), body ] in place of the network. Null sends through WordPress.
	 *
	 * @var callable|null
	 */
	public static $transport = null;

	/** An absolute microtime by which every Google call in this request must have ended (null: none). */
	private static $deadline = null;

	/** The timeout a call may use now: min( $want, time left ), 0 when the deadline has passed. */
	private static function budget( int $want ): int {
		if ( null === self::$deadline ) {
			return $want;
		}
		$left = (int) floor( self::$deadline - microtime( true ) );
		return $left < 1 ? 0 : min( $want, $left );
	}

	private static function now(): int {
		return null !== self::$now ? (int) self::$now : time();
	}

	private static function log( string $msg ): void {
		error_log( self::LOG . $msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions -- operator diagnostics; never a token, code or secret
	}

	/* =====================================================================
	 * BOOT
	 * ===================================================================== */

	public static function init(): void {
		add_filter( 'zdz_core_credentials_services', array( __CLASS__, 'add_service' ) );
		add_action( 'zdz_core_credentials_service_after', array( __CLASS__, 'render_for_service' ), 10, 1 );
		add_action( 'admin_menu', array( __CLASS__, 'add_callback_page' ), 30 );
		add_action( 'admin_init', array( __CLASS__, 'handle_callback' ) );
		foreach ( array( 'connect', 'find', 'pick', 'disconnect', 'test' ) as $a ) {
			add_action( 'admin_post_zdz_gbp_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
		add_filter( 'zdz_salt_watch_breaks', array( __CLASS__, 'salt_breaks' ), 10, 2 );
		add_filter( 'zdz_export_secret_option_names', array( __CLASS__, 'export_secret_names' ) );
		add_filter( 'zdz_export_non_portable_option_patterns', array( __CLASS__, 'export_non_portable' ) );
	}

	/**
	 * The Credentials entry: the OAuth client, one pair, written only on that screen and read only by server code.
	 * Both rows are sealed. The sign-in (Connect) and the location are drawn under the entry by render_block().
	 */
	public static function service_def(): array {
		$F = ZDZ_Core_Crypto::FIXED;
		return array(
			'kind'          => 'app',
			'label'         => __( 'Google Business Profile (reviews)', 'zorderz' ),
			'group'         => 'Google',
			'note'          => __( 'The OAuth client (type: Web application) from the business\'s own Google Cloud project. The site uses it only to read the business\'s own Google reviews: their total and average star rating for the home screen. Google must approve that project for the Business Profile API before anything can be read. Type the values here yourself: never paste them into a chat or a document.', 'zorderz' ),
			'fields'        => array(
				'client_id'     => array( __( 'OAuth client ID', 'zorderz' ), false ),
				'client_secret' => array( __( 'OAuth client secret', 'zorderz' ), true ),
			),
			'required'      => array( 'client_id', 'client_secret' ),
			'canonical'     => array( 'client_id' => 'zdz_core_gbp_client_id', 'client_secret' => 'zdz_core_gbp_client_secret' ),
			'create_copies' => false,
			'copies'        => array(
				'client_id'     => array( 'zdz_core_gbp_client_id' => array( $F, __( 'Google reviews (server only)', 'zorderz' ) ) ),
				'client_secret' => array( 'zdz_core_gbp_client_secret' => array( $F, __( 'Google reviews (server only)', 'zorderz' ) ) ),
			),
		);
	}

	/** zdz_core_credentials_services: adds the entry; never replaces one already there. */
	public static function add_service( $services ) {
		$services = is_array( $services ) ? $services : array();
		if ( ! isset( $services[ self::SVC ] ) && class_exists( 'ZDZ_Core_Crypto' ) ) {
			$services[ self::SVC ] = self::service_def();
		}
		return $services;
	}

	/** Google sends the browser back to admin.php?page=zdz-google-business-callback (a hidden page). */
	public static function add_callback_page(): void {
		add_submenu_page( '', __( 'Google Business Profile', 'zorderz' ), __( 'Google Business Profile', 'zorderz' ), 'manage_options', self::CALLBACK_SLUG, '__return_false' );
	}

	/* =====================================================================
	 * STATE
	 * ===================================================================== */

	/** Is this a staging or test copy of the live site? Then nothing here calls Google. */
	public static function on_copy(): bool {
		return class_exists( 'ZDZ_Staging_Guard' ) && ZDZ_Staging_Guard::is_active();
	}

	/** [ id, secret ] from Zorderz → Credentials ('' each when not set). Server code only. */
	public static function client(): array {
		if ( ! class_exists( 'ZDZ_Core_Credentials' ) || ! method_exists( 'ZDZ_Core_Credentials', 'get' ) ) {
			return array( '', '' );
		}
		return array(
			trim( (string) ZDZ_Core_Credentials::get( self::SVC, 'client_id' ) ),
			trim( (string) ZDZ_Core_Credentials::get( self::SVC, 'client_secret' ) ),
		);
	}

	public static function has_client(): bool {
		list( $id, $secret ) = self::client();
		return '' !== $id && '' !== $secret;
	}

	public static function redirect_uri(): string {
		return admin_url( 'admin.php?page=' . self::CALLBACK_SLUG );
	}

	/** $plain sealed for storage, or '' when it cannot be sealed (then nothing is stored). */
	private static function seal( string $plain ): string {
		if ( '' === $plain || ! class_exists( 'ZDZ_Core_Crypto' ) ) {
			return '';
		}
		$s = ZDZ_Core_Crypto::encode( $plain, ZDZ_Core_Crypto::FIXED );
		return ( '' !== $s && $s !== $plain && ZDZ_Core_Crypto::FIXED === ZDZ_Core_Crypto::decode( $s )[0] ) ? $s : '';
	}

	/** A stored token's usable value; '' when it was never sealed or cannot be read under this site's salts. */
	private static function unseal( string $stored ): string {
		if ( '' === $stored || ! class_exists( 'ZDZ_Core_Crypto' ) ) {
			return '';
		}
		list( $format, $plain ) = ZDZ_Core_Crypto::decode( $stored );
		// Only a sealed value is a stored token: a plain string in the row is not trusted as one.
		return ( ZDZ_Core_Crypto::FIXED === $format || ZDZ_Core_Crypto::PAIR === $format ) ? (string) $plain : '';
	}

	/** One printable token, as Google issues them. */
	private static function is_token( $v ): bool {
		return is_string( $v ) && '' !== $v && strlen( $v ) <= self::TOKEN_MAX && 1 === preg_match( '/^[\x21-\x7E]+$/', $v );
	}

	private static function refresh_token(): string {
		$t = get_option( self::OPT_TOKEN, array() );
		return is_array( $t ) && ! empty( $t['refresh'] ) ? self::unseal( (string) $t['refresh'] ) : '';
	}

	public static function is_connected(): bool {
		return '' !== self::refresh_token();
	}

	/** An account resource name exactly as Google gives it: accounts/<id>. */
	public static function valid_account( string $s ): bool {
		return 1 === preg_match( '#^accounts/[A-Za-z0-9_-]{1,64}$#', $s );
	}

	/** A location resource name exactly as Google gives it: locations/<id>. */
	public static function valid_location( string $s ): bool {
		return 1 === preg_match( '#^locations/[A-Za-z0-9_-]{1,64}$#', $s );
	}

	/** [ account, location, title ] the administrator picked, or [] (also when a stored name is not one of Google's). */
	public static function location(): array {
		$l = get_option( self::OPT_LOCATION, array() );
		if ( ! is_array( $l ) || ! self::valid_account( (string) ( $l['account'] ?? '' ) ) || ! self::valid_location( (string) ( $l['location'] ?? '' ) ) ) {
			return array();
		}
		return array( 'account' => (string) $l['account'], 'location' => (string) $l['location'], 'title' => (string) ( $l['title'] ?? '' ) );
	}

	/** Client, sign-in and location all present. */
	public static function is_ready(): bool {
		return self::has_client() && self::is_connected() && array() !== self::location();
	}

	/** [ state, at, why ]. state: '' (never asked) | ok | reconnect | not_approved | error. */
	public static function status(): array {
		$s = get_option( self::OPT_STATUS, array() );
		return array(
			'state' => is_array( $s ) ? (string) ( $s['state'] ?? '' ) : '',
			'at'    => is_array( $s ) ? (int) ( $s['at'] ?? 0 ) : 0,
			'why'   => is_array( $s ) ? (string) ( $s['why'] ?? '' ) : '',
		);
	}

	private static function set_status( string $state, string $why = '' ): void {
		$cur = self::status();
		if ( $cur['state'] === $state && $cur['why'] === $why && self::now() - $cur['at'] < 300 ) {
			return; // unchanged inside five minutes: no write
		}
		update_option( self::OPT_STATUS, array( 'state' => $state, 'at' => self::now(), 'why' => substr( $why, 0, 160 ) ), false );
	}

	/**
	 * Why the tile shows the typed number although the site is set up: 'staging' on a copy of the site,
	 * 'not_approved' while Google has not approved the business's project, else '' (no reason: a failure is an error).
	 */
	public static function fallback_reason(): string {
		if ( self::on_copy() ) {
			return 'staging';
		}
		return 'not_approved' === self::status()['state'] ? 'not_approved' : '';
	}

	/* =====================================================================
	 * THE SIGN-IN STATE: signed, bound to one person, ten minutes, single use
	 * ===================================================================== */

	private static function sign_state( int $uid, string $nonce, int $exp ): string {
		return substr( hash_hmac( 'sha256', 'zdz-gbp-state|' . $uid . '|' . $nonce . '|' . $exp, (string) wp_salt( 'auth' ) ), 0, 32 );
	}

	/** A fresh state for $uid's sign-in: "<nonce>.<expiry>.<signature>". Replaces any earlier one of theirs. */
	public static function issue_state( int $uid ): string {
		$nonce = bin2hex( random_bytes( 16 ) );
		$exp   = self::now() + self::STATE_TTL;
		set_transient( self::T_STATE . $uid, array( 'h' => hash( 'sha256', $nonce ), 'exp' => $exp ), self::STATE_TTL );
		return $nonce . '.' . $exp . '.' . self::sign_state( $uid, $nonce, $exp );
	}

	/**
	 * Does $state answer $uid's own pending sign-in? '' when it does, else why not:
	 * malformed | forged | expired | unknown (none pending, already used, or replaced by a newer Connect).
	 * Any answer spends the pending state: it can be used once.
	 */
	public static function verify_state( int $uid, string $state ): string {
		$want = get_transient( self::T_STATE . $uid );
		delete_transient( self::T_STATE . $uid );
		if ( $uid <= 0 || 1 !== preg_match( '/^([a-f0-9]{32})\.([0-9]{1,12})\.([a-f0-9]{32})$/', $state, $m ) ) {
			return 'malformed';
		}
		if ( ! hash_equals( self::sign_state( $uid, $m[1], (int) $m[2] ), $m[3] ) ) {
			return 'forged';
		}
		if ( (int) $m[2] < self::now() ) {
			return 'expired';
		}
		if ( ! is_array( $want ) || ! is_string( $want['h'] ?? null ) || ! hash_equals( $want['h'], hash( 'sha256', $m[1] ) ) || (int) ( $want['exp'] ?? 0 ) < self::now() ) {
			return 'unknown';
		}
		return '';
	}

	/** The Google sign-in address for $uid, with a fresh state. '' without a client, or on a copy of the site. */
	public static function connect_url( int $uid ): string {
		list( $id ) = self::client();
		if ( ! self::has_client() || self::on_copy() || $uid <= 0 ) {
			return '';
		}
		return self::AUTH_URL . '?' . http_build_query( array(
			'client_id'              => $id,
			'redirect_uri'           => self::redirect_uri(),
			'response_type'          => 'code',
			'scope'                  => self::SCOPE,
			'access_type'            => 'offline',
			'prompt'                 => 'consent',
			'include_granted_scopes' => 'false',
			'state'                  => self::issue_state( $uid ),
		), '', '&', PHP_QUERY_RFC3986 );
	}

	/* =====================================================================
	 * THE NETWORK
	 * ===================================================================== */

	/** [ HTTP status (0: no answer), body ]. Never on a copy of the site. */
	private static function send( string $method, string $url, array $args ): array {
		if ( self::on_copy() ) {
			return array( 0, '' );
		}
		if ( null !== self::$transport ) {
			$r = call_user_func( self::$transport, $method, $url, $args );
			return array( (int) ( $r[0] ?? 0 ), (string) ( $r[1] ?? '' ) );
		}
		$res = 'POST' === $method ? wp_remote_post( $url, $args ) : wp_remote_get( $url, $args );
		if ( is_wp_error( $res ) ) {
			return array( 0, '' );
		}
		return array( (int) wp_remote_retrieve_response_code( $res ), (string) wp_remote_retrieve_body( $res ) );
	}

	/**
	 * Exchange the sign-in code for a lasting sign-in, and keep only the refresh token, sealed.
	 * '' on success, else why: staging | no_client | cannot_seal | unreachable | http_<n> | Google's error word.
	 */
	public static function exchange_code( string $code, int $uid ): string {
		if ( self::on_copy() ) {
			return 'staging';
		}
		list( $id, $secret ) = self::client();
		if ( '' === $id || '' === $secret ) {
			return 'no_client';
		}
		list( $http, $raw ) = self::send( 'POST', self::TOKEN_URL, array(
			'timeout' => 15,
			'body'    => array(
				'code'          => $code,
				'client_id'     => $id,
				'client_secret' => $secret,
				'redirect_uri'  => self::redirect_uri(),
				'grant_type'    => 'authorization_code',
			),
		) );
		$body = json_decode( $raw, true );
		if ( 200 !== $http || ! is_array( $body ) || ! self::is_token( $body['refresh_token'] ?? null ) ) {
			$why = is_array( $body ) && is_string( $body['error'] ?? null ) && '' !== sanitize_key( $body['error'] )
				? sanitize_key( $body['error'] )
				: ( $http ? ( 200 === $http ? 'no_refresh_token' : 'http_' . $http ) : 'unreachable' );
			self::log( 'the sign-in code was not exchanged (' . $why . ').' );
			return $why;
		}
		$sealed = self::seal( (string) $body['refresh_token'] );
		if ( '' === $sealed ) {
			self::log( 'the sign-in could not be sealed (is OpenSSL available?); nothing was stored.' );
			return 'cannot_seal';
		}
		update_option( self::OPT_TOKEN, array( 'refresh' => $sealed, 'at' => self::now(), 'by' => $uid ), false );
		delete_transient( self::T_ACCESS );
		delete_transient( self::T_SUMMARY );
		delete_transient( self::T_SUMMARY_BAD );
		if ( self::is_token( $body['access_token'] ?? null ) ) {
			self::keep_access( (string) $body['access_token'], (int) ( $body['expires_in'] ?? 3600 ) );
		}
		self::set_status( 'ok', 'connected' );
		self::log( 'connected by user #' . $uid . '.' );
		return '';
	}

	/** Keep the access token, sealed, until a minute before it expires (not at all when it cannot be sealed). */
	private static function keep_access( string $token, int $expires_in ): void {
		$sealed = self::seal( $token );
		if ( '' === $sealed ) {
			return;
		}
		$ttl = max( 60, $expires_in - 60 );
		set_transient( self::T_ACCESS, array( 't' => $sealed, 'exp' => self::now() + $ttl ), $ttl );
	}

	/** A current access token, refreshed when needed; '' when none can be had (status says why). */
	public static function access_token( int $timeout = 10 ): string {
		if ( self::on_copy() ) {
			return '';
		}
		$c = get_transient( self::T_ACCESS );
		if ( is_array( $c ) && ! empty( $c['t'] ) && (int) ( $c['exp'] ?? 0 ) > self::now() ) {
			$p = self::unseal( (string) $c['t'] );
			if ( '' !== $p ) {
				return $p;
			}
		}
		$refresh             = self::refresh_token();
		list( $id, $secret ) = self::client();
		if ( '' === $refresh || '' === $id || '' === $secret ) {
			return '';
		}
		$timeout = self::budget( $timeout );
		if ( 0 === $timeout ) {
			return '';
		}
		list( $http, $raw ) = self::send( 'POST', self::TOKEN_URL, array(
			'timeout' => $timeout,
			'body'    => array( 'client_id' => $id, 'client_secret' => $secret, 'refresh_token' => $refresh, 'grant_type' => 'refresh_token' ),
		) );
		$body = json_decode( $raw, true );
		if ( 200 === $http && is_array( $body ) && self::is_token( $body['access_token'] ?? null ) ) {
			self::keep_access( (string) $body['access_token'], (int) ( $body['expires_in'] ?? 3600 ) );
			return (string) $body['access_token'];
		}
		$err = is_array( $body ) && is_string( $body['error'] ?? null ) ? sanitize_key( $body['error'] ) : '';
		if ( 'invalid_grant' === $err || 'unauthorized_client' === $err ) {
			// The sign-in was withdrawn or expired (an OAuth app left in "Testing" expires it after 7 days), or the
			// client changed.
			self::set_status( 'reconnect', $err );
			self::log( 'the Google sign-in no longer works (' . $err . '); press Connect again on Zorderz → Credentials.' );
		} else {
			self::set_status( 'error', $http ? 'token http_' . $http : 'token: no answer' );
		}
		return '';
	}

	/**
	 * What a Google API answer means: '' (200) | not_approved | denied | http_<n>. PURE.
	 *
	 * Before Google approves the project its quota is 0 requests a minute: 429 / RESOURCE_EXHAUSTED, or a 403 saying
	 * the API "has not been used" in the project or "is disabled" (SERVICE_DISABLED) while it is not enabled yet.
	 */
	public static function classify( int $http, $json ): string {
		if ( 200 === $http ) {
			return '';
		}
		$err = is_array( $json ) && is_array( $json['error'] ?? null ) ? $json['error'] : array();
		$msg = strtolower( ( is_string( $err['status'] ?? null ) ? $err['status'] : '' ) . ' ' . ( is_string( $err['message'] ?? null ) ? $err['message'] : '' ) );
		foreach ( (array) ( $err['details'] ?? array() ) as $d ) {
			if ( is_array( $d ) && is_string( $d['reason'] ?? null ) ) {
				$msg .= ' ' . strtolower( $d['reason'] );
			}
		}
		if ( 429 === $http || false !== strpos( $msg, 'resource_exhausted' ) || false !== strpos( $msg, 'has not been used' )
			|| false !== strpos( $msg, 'is disabled' ) || false !== strpos( $msg, 'service_disabled' ) ) {
			return 'not_approved';
		}
		if ( 403 === $http ) {
			return 'denied';
		}
		return 'http_' . $http;
	}

	/**
	 * GET a Google API with the access token: [ http, json|null, why ]. why: '' | staging | no_token | not_approved |
	 * denied | http_<n> | unreachable. A 401 once drops the kept access token and tries again with a fresh one.
	 */
	public static function api_get( string $url, int $timeout = 10 ): array {
		if ( self::on_copy() ) {
			return array( 0, null, 'staging' );
		}
		for ( $try = 0; $try < 2; $try++ ) {
			$token = self::access_token( $timeout );
			if ( '' === $token ) {
				return array( 0, null, 'no_token' );
			}
			$t = self::budget( $timeout );
			if ( 0 === $t ) {
				return array( 0, null, 'unreachable' ); // out of this request's time: said as "could not be asked"
			}
			list( $http, $raw ) = self::send( 'GET', $url, array(
				'timeout' => $t,
				'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ),
			) );
			if ( 0 === $http ) {
				self::set_status( 'error', 'unreachable' );
				return array( 0, null, 'unreachable' );
			}
			$json = json_decode( $raw, true );
			if ( 401 === $http && 0 === $try ) {
				delete_transient( self::T_ACCESS );
				continue;
			}
			$why = self::classify( $http, $json );
			if ( '' === $why ) {
				return array( 200, is_array( $json ) ? $json : array(), '' );
			}
			if ( 'not_approved' === $why ) {
				self::set_status( 'not_approved', 'quota 0 or API not enabled' );
			} elseif ( 'denied' === $why ) {
				self::set_status( 'error', 'denied (is the signed-in account an owner or manager of the location?)' );
			} else {
				self::set_status( 'error', $why );
			}
			return array( $http, is_array( $json ) ? $json : null, $why );
		}
		return array( 401, null, 'no_token' );
	}

	/* =====================================================================
	 * READS
	 * ===================================================================== */

	/**
	 * Every location the signed-in account manages: [ [ account, location, title, address ] ], from at most five
	 * accounts. [] when none or on failure (the second value says why: '' | staging | no_token | not_approved | …).
	 *
	 * @return array{0:array,1:string}
	 */
	public static function find_locations(): array {
		list( $http, $json, $why ) = self::api_get( self::ACCOUNTS_URL );
		if ( 200 !== $http ) {
			return array( array(), $why );
		}
		$out  = array();
		$last = '';
		foreach ( array_slice( (array) ( $json['accounts'] ?? array() ), 0, self::MAX_ACCOUNTS ) as $acc ) {
			$an = is_array( $acc ) ? (string) ( $acc['name'] ?? '' ) : '';
			if ( ! self::valid_account( $an ) ) {
				continue;
			}
			$url                  = sprintf( self::LOCATIONS_FMT, $an ) . '?' . http_build_query( array( 'readMask' => 'name,title,storefrontAddress', 'pageSize' => 100 ), '', '&', PHP_QUERY_RFC3986 );
			list( $h2, $j2, $w2 ) = self::api_get( $url );
			if ( 200 !== $h2 ) {
				$last = $w2;
				continue;
			}
			foreach ( (array) ( $j2['locations'] ?? array() ) as $loc ) {
				$ln = is_array( $loc ) ? (string) ( $loc['name'] ?? '' ) : '';
				if ( ! self::valid_location( $ln ) ) {
					continue;
				}
				$addr  = is_array( $loc['storefrontAddress'] ?? null ) ? $loc['storefrontAddress'] : array();
				$lines = array_merge( array_filter( (array) ( $addr['addressLines'] ?? array() ), 'is_string' ), array( is_string( $addr['locality'] ?? null ) ? $addr['locality'] : '' ) );
				$out[] = array(
					'account'  => $an,
					'location' => $ln,
					'title'    => is_string( $loc['title'] ?? null ) ? $loc['title'] : '',
					'address'  => trim( implode( ', ', array_filter( $lines ) ) ),
				);
			}
		}
		return array( $out, $out ? '' : $last );
	}

	/**
	 * One page of the picked location's reviews, newest first.
	 *
	 * @return array [ ok, reviews: [ [ id, reviewer, anonymous, stars, comment, create, update ] ], next, total, average, why ]
	 */
	public static function reviews( string $page_token = '', int $page_size = 50, int $timeout = 10 ): array {
		$fail = array( 'ok' => false, 'reviews' => array(), 'next' => '', 'total' => null, 'average' => null, 'why' => '' );
		if ( self::on_copy() ) {
			return array( 'why' => 'staging' ) + $fail;
		}
		$loc = self::location();
		if ( array() === $loc ) {
			return array( 'why' => 'no_location' ) + $fail;
		}
		$q = array( 'pageSize' => max( 1, min( 50, $page_size ) ), 'orderBy' => 'updateTime desc' );
		if ( '' !== $page_token ) {
			$q['pageToken'] = $page_token;
		}
		$url                       = sprintf( self::REVIEWS_FMT, $loc['account'], $loc['location'] ) . '?' . http_build_query( $q, '', '&', PHP_QUERY_RFC3986 );
		list( $http, $json, $why ) = self::api_get( $url, $timeout );
		if ( 200 !== $http ) {
			return array( 'why' => $why ) + $fail;
		}
		$list = array();
		foreach ( (array) ( $json['reviews'] ?? array() ) as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$who    = is_array( $r['reviewer'] ?? null ) ? $r['reviewer'] : array();
			$list[] = array(
				'id'        => (string) ( $r['reviewId'] ?? '' ),
				'reviewer'  => (string) ( $who['displayName'] ?? '' ),
				'anonymous' => ! empty( $who['isAnonymous'] ),
				'stars'     => (int) ( self::STARS[ (string) ( $r['starRating'] ?? '' ) ] ?? 0 ),
				'comment'   => (string) ( $r['comment'] ?? '' ),
				'create'    => (string) ( $r['createTime'] ?? '' ),
				'update'    => (string) ( $r['updateTime'] ?? '' ),
			);
		}
		// Google leaves totalReviewCount out for a location with no reviews: a 200 then means 0, not a failure.
		$total   = isset( $json['totalReviewCount'] ) ? (int) $json['totalReviewCount'] : ( array() === $list ? 0 : null );
		$average = isset( $json['averageRating'] ) ? round( (float) $json['averageRating'], 1 ) : null;
		if ( null !== $total ) {
			set_transient( self::T_SUMMARY, array( 'total' => $total, 'average' => $average, 'asked_at' => self::now() ), self::SUMMARY_TTL );
			delete_transient( self::T_SUMMARY_BAD );
		}
		self::set_status( 'ok' );
		return array( 'ok' => true, 'reviews' => $list, 'next' => (string) ( $json['nextPageToken'] ?? '' ), 'total' => $total, 'average' => $average, 'why' => '' );
	}

	/**
	 * Total and average for the home tile: [ total, average, asked_at ] or null. A figure younger than an hour is
	 * used as it is. Otherwise, when $may_ask, one call for a one-review page, the whole chain within four seconds.
	 * After a failure no one asks again for five minutes; after "not approved", for an hour. Never on a copy.
	 */
	public static function summary( bool $may_ask = true ): ?array {
		$hit = get_transient( self::T_SUMMARY );
		if ( is_array( $hit ) && isset( $hit['total'] ) && self::now() - (int) ( $hit['asked_at'] ?? 0 ) < self::SUMMARY_TTL ) {
			return array( 'total' => (int) $hit['total'], 'average' => null === ( $hit['average'] ?? null ) ? null : (float) $hit['average'], 'asked_at' => (int) $hit['asked_at'] );
		}
		if ( ! $may_ask || self::on_copy() || ! self::is_ready() ) {
			return null;
		}
		$bad = get_transient( self::T_SUMMARY_BAD );
		if ( is_array( $bad ) ) {
			$wait = 'not_approved' === ( $bad['why'] ?? '' ) ? self::NOT_APPROVED_TTL : self::FAIL_TTL;
			if ( self::now() - (int) ( $bad['at'] ?? 0 ) < $wait ) {
				return null;
			}
		}
		self::$deadline = microtime( true ) + self::TILE_BUDGET;
		try {
			$r = self::reviews( '', 1, self::TILE_BUDGET );
		} finally {
			self::$deadline = null;
		}
		if ( ! $r['ok'] || null === $r['total'] ) {
			$why = '' !== (string) $r['why'] ? (string) $r['why'] : 'error';
			set_transient( self::T_SUMMARY_BAD, array( 'at' => self::now(), 'why' => $why ), 'not_approved' === $why ? self::NOT_APPROVED_TTL : self::FAIL_TTL );
			return null;
		}
		return array( 'total' => (int) $r['total'], 'average' => $r['average'], 'asked_at' => self::now() );
	}

	/** Google's review time (RFC 3339, UTC) as a unix time; 0 when unreadable. */
	public static function to_unix( string $rfc3339 ): int {
		if ( '' === $rfc3339 ) {
			return 0;
		}
		try {
			return ( new DateTimeImmutable( $rfc3339 ) )->getTimestamp();
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/** What a failure means, in words for the administrator. Never carries a token, code or secret. */
	public static function words( string $why ): string {
		$w = array(
			'staging'          => __( 'This is a copy of the live site: it does not sign in to Google or call it.', 'zorderz' ),
			'no_client'        => __( 'Enter the Google Business Profile client ID and secret above first.', 'zorderz' ),
			'no_location'      => __( 'No location picked yet.', 'zorderz' ),
			'no_token'         => __( 'The Google sign-in is missing or no longer works. Press Connect.', 'zorderz' ),
			'not_approved'     => __( 'Not approved yet: Google has not approved this business\'s Google Cloud project for the Business Profile API (its quota is 0), or the API is not enabled in that project. Until then the home screen shows the number typed on Zorderz → Settings.', 'zorderz' ),
			'denied'           => __( 'Google refused: is the signed-in account an owner or manager of this location?', 'zorderz' ),
			'unreachable'      => __( 'Google could not be reached just now.', 'zorderz' ),
			'cannot_seal'      => __( 'The sign-in could not be stored sealed on this server (OpenSSL is needed), so nothing was kept.', 'zorderz' ),
			'no_refresh_token' => __( 'Google did not hand over a lasting sign-in. Press Connect again.', 'zorderz' ),
		);
		if ( isset( $w[ $why ] ) ) {
			return $w[ $why ];
		}
		/* translators: %s: a short error word from Google, such as invalid_client or http_500 */
		return sprintf( __( 'Google answered %s.', 'zorderz' ), sanitize_key( $why ) );
	}

	/* =====================================================================
	 * THE SCREEN (Zorderz → Credentials → Google Business Profile)
	 * ===================================================================== */

	private static function gate( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'zorderz' ), 403 );
		}
		check_admin_referer( $action );
	}

	/** The result of an action, kept server-side for the next view of the screen (no free text in the URL). */
	private static function back( string $msg, bool $ok ): void {
		set_transient( self::T_NOTICE . get_current_user_id(), array( 'msg' => $msg, 'ok' => $ok ), 120 );
		$page = class_exists( 'ZDZ_Core_Credentials' ) ? ZDZ_Core_Credentials::page_url() : admin_url();
		wp_safe_redirect( $page . '#' . self::ANCHOR );
		exit;
	}

	public static function handle_connect(): void {
		self::gate( 'zdz_gbp_connect' );
		if ( self::on_copy() ) {
			self::back( self::words( 'staging' ), false );
		}
		$url = self::connect_url( (int) get_current_user_id() );
		if ( '' === $url ) {
			self::back( self::words( 'no_client' ), false );
		}
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- Google's own sign-in host, a constant
		exit;
	}

	/** admin_init: Google's answer to the sign-in. Checks the state, exchanges the code, keeps only the refresh token. */
	public static function handle_callback(): void {
		$page = is_string( $_GET['page'] ?? null ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- the signed state below is the check
		if ( self::CALLBACK_SLUG !== $page ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'zorderz' ), 403 );
		}
		$uid   = (int) get_current_user_id();
		$state = is_string( $_GET['state'] ?? null ) ? (string) wp_unslash( $_GET['state'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- matched against a strict pattern
		if ( '' !== self::verify_state( $uid, $state ) ) {
			self::log( 'a sign-in answer was refused: its state did not match a Connect press by user #' . $uid . '.' );
			self::back( __( 'That Google sign-in could not be matched to a Connect press here (it may have taken over ten minutes, or been used already). Press Connect again.', 'zorderz' ), false );
		}
		if ( self::on_copy() ) {
			self::back( self::words( 'staging' ), false );
		}
		if ( ! empty( $_GET['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			/* translators: %s: Google's short error word, such as access_denied */
			self::back( sprintf( __( 'Google did not connect: %s. Nothing was stored.', 'zorderz' ), sanitize_key( (string) wp_unslash( $_GET['error'] ) ) ), false ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		$code = is_string( $_GET['code'] ?? null ) ? (string) wp_unslash( $_GET['code'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- sent only to Google's token endpoint
		if ( ! self::is_token( $code ) ) {
			self::back( __( 'Google sent no sign-in code. Press Connect again.', 'zorderz' ), false );
		}
		$why = self::exchange_code( $code, $uid );
		if ( '' !== $why ) {
			self::back(
				in_array( $why, array( 'staging', 'no_client', 'cannot_seal', 'unreachable', 'no_refresh_token' ), true )
					? self::words( $why )
					/* translators: %s: Google's short error word or HTTP status */
					: sprintf( __( 'Google did not hand over a lasting sign-in (%s). Check the client ID, the secret and the redirect address in Google Cloud, then press Connect again.', 'zorderz' ), $why ),
				false
			);
		}
		self::back( __( 'Connected to Google.', 'zorderz' ) . ( array() === self::location() ? ' ' . __( 'Next: press "Find my location".', 'zorderz' ) : '' ), true );
	}

	public static function handle_find(): void {
		self::gate( 'zdz_gbp_find' );
		if ( self::on_copy() ) {
			self::back( self::words( 'staging' ), false );
		}
		list( $found, $why ) = self::find_locations();
		if ( ! $found ) {
			self::back( '' !== $why ? self::words( $why ) : __( 'No location was found for the signed-in Google account. Is it an owner or manager of the Business Profile?', 'zorderz' ), false );
		}
		if ( 1 === count( $found ) ) {
			self::keep_location( $found[0] );
			/* translators: %s: the location's name on Google */
			self::back( sprintf( __( 'Location set: %s.', 'zorderz' ), $found[0]['title'] ), true );
		}
		set_transient( self::T_FOUND . get_current_user_id(), $found, 1800 );
		/* translators: %d: number of locations */
		self::back( sprintf( __( '%d locations found: pick the business\'s own below.', 'zorderz' ), count( $found ) ), true );
	}

	public static function handle_pick(): void {
		self::gate( 'zdz_gbp_pick' );
		$found = get_transient( self::T_FOUND . get_current_user_id() );
		$i     = isset( $_POST['i'] ) ? (int) $_POST['i'] : -1; // phpcs:ignore WordPress.Security.NonceVerification -- gate() checked it
		if ( ! is_array( $found ) || ! isset( $found[ $i ] ) || ! is_array( $found[ $i ] ) ) {
			self::back( __( 'That list has expired. Press "Find my location" again.', 'zorderz' ), false );
		}
		self::keep_location( $found[ $i ] );
		delete_transient( self::T_FOUND . get_current_user_id() );
		/* translators: %s: the location's name on Google */
		self::back( sprintf( __( 'Location set: %s.', 'zorderz' ), (string) $found[ $i ]['title'] ), true );
	}

	/** Keep the picked location (only names exactly as Google gives them); the cached figures were for another. */
	private static function keep_location( array $f ): void {
		$a = (string) ( $f['account'] ?? '' );
		$l = (string) ( $f['location'] ?? '' );
		if ( ! self::valid_account( $a ) || ! self::valid_location( $l ) ) {
			self::back( __( 'That location could not be read. Press "Find my location" again.', 'zorderz' ), false );
		}
		update_option( self::OPT_LOCATION, array( 'account' => $a, 'location' => $l, 'title' => substr( (string) ( $f['title'] ?? '' ), 0, 200 ) ), false );
		delete_transient( self::T_SUMMARY );
		delete_transient( self::T_SUMMARY_BAD );
	}

	public static function handle_disconnect(): void {
		self::gate( 'zdz_gbp_disconnect' );
		$copy = self::on_copy();
		if ( ! $copy ) {
			$refresh = self::refresh_token();
			if ( '' !== $refresh ) {
				self::send( 'POST', self::REVOKE_URL, array( 'timeout' => 5, 'body' => array( 'token' => $refresh ) ) ); // best effort
			}
		}
		delete_option( self::OPT_TOKEN );
		delete_transient( self::T_ACCESS );
		delete_transient( self::T_SUMMARY );
		delete_transient( self::T_SUMMARY_BAD );
		self::set_status( '', 'disconnected' );
		self::log( 'disconnected by user #' . get_current_user_id() . ( $copy ? ' on a copy of the site (Google was not told).' : '.' ) );
		self::back(
			$copy
				? __( 'Disconnected on this copy only. Google was not told: that would end the live site\'s sign-in.', 'zorderz' )
				: __( 'Disconnected from Google. The location choice is kept for when you connect again.', 'zorderz' ),
			true
		);
	}

	public static function handle_test(): void {
		self::gate( 'zdz_gbp_test' );
		$r = self::reviews( '', 1, 10 );
		if ( $r['ok'] ) {
			self::back(
				null === $r['total'] || 0 === $r['total']
					? __( 'Google answered: no reviews yet.', 'zorderz' )
					/* translators: 1: number of reviews, 2: average star rating */
					: sprintf( __( 'Google answered: %1$d reviews, average %2$s.', 'zorderz' ), (int) $r['total'], null === $r['average'] ? '—' : number_format( (float) $r['average'], 1 ) ),
				true
			);
		}
		self::back( self::words( (string) $r['why'] ), false );
	}

	/** zdz_core_credentials_service_after: draws the sign-in block under this service's entry. */
	public static function render_for_service( $svc ): void {
		if ( self::SVC === $svc ) {
			self::render_block();
		}
	}

	/** Connect, location and test, under the Credentials entry. No secret, token, code or client ID is printed. */
	public static function render_block(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$uid = (int) get_current_user_id();
		echo '<div id="' . esc_attr( self::ANCHOR ) . '" style="margin:12px 0;padding:12px 14px;border:1px solid #dcdcde;border-radius:6px;max-width:980px">';
		$n = get_transient( self::T_NOTICE . $uid );
		if ( is_array( $n ) ) {
			delete_transient( self::T_NOTICE . $uid );
			echo '<div class="notice ' . ( ! empty( $n['ok'] ) ? 'notice-success' : 'notice-error' ) . ' inline"><p>' . esc_html( (string) ( $n['msg'] ?? '' ) ) . '</p></div>';
		}
		$post = admin_url( 'admin-post.php' );
		$btn  = static function ( string $action, string $label, bool $primary = false ) use ( $post ): string {
			return '<form method="post" action="' . esc_url( $post ) . '" style="display:inline-block;margin:4px 6px 4px 0">'
				. wp_nonce_field( $action, '_wpnonce', true, false )
				. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><button class="button' . ( $primary ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form>';
		};
		$s    = self::status();
		$loc  = self::location();
		$copy = self::on_copy();
		if ( $copy ) {
			echo '<p style="color:#996800"><strong>' . esc_html( self::words( 'staging' ) ) . '</strong></p>';
		}
		echo '<p><strong>' . esc_html__( 'Redirect address for Google Cloud', 'zorderz' ) . '</strong> ' . esc_html__( '(paste it exactly):', 'zorderz' ) . ' <code>' . esc_html( self::redirect_uri() ) . '</code></p>';
		if ( ! self::has_client() ) {
			echo '<p>' . esc_html__( 'Step 1: enter the client ID and secret above (from Google Cloud → APIs & Services → Credentials → OAuth client).', 'zorderz' ) . '</p></div>';
			return;
		}
		if ( ! self::is_connected() ) {
			echo '<p>' . esc_html__( 'Step 2: connect the Google account that owns or manages the Business Profile.', 'zorderz' ) . '</p>';
			echo ( $copy ? '' : $btn( 'zdz_gbp_connect', __( 'Connect Google', 'zorderz' ), true ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
			return;
		}
		$t  = get_option( self::OPT_TOKEN, array() );
		$at = is_array( $t ) ? (int) ( $t['at'] ?? 0 ) : 0;
		echo '<p><span class="dashicons dashicons-yes-alt" style="color:#008a20"></span> ' . esc_html__( 'Connected to Google', 'zorderz' )
			/* translators: %s: date and time */
			. ( $at ? ' ' . esc_html( sprintf( __( 'on %s', 'zorderz' ), function_exists( 'wp_date' ) ? wp_date( 'j M Y, H:i', $at ) : gmdate( 'j M Y H:i', $at ) ) ) : '' ) . '.</p>';
		if ( 'reconnect' === $s['state'] ) {
			echo '<p style="color:#b32d2e"><strong>' . esc_html__( 'The Google sign-in no longer works.', 'zorderz' ) . '</strong> '
				. esc_html__( 'Press Reconnect. If the OAuth app in Google Cloud is in "Testing", set it to "In production" first, or this repeats every 7 days.', 'zorderz' ) . '</p>';
		} elseif ( 'not_approved' === $s['state'] ) {
			echo '<p style="color:#996800"><strong>' . esc_html__( 'Waiting for Google:', 'zorderz' ) . '</strong> ' . esc_html( self::words( 'not_approved' ) ) . '</p>';
		} elseif ( 'error' === $s['state'] ) {
			/* translators: %s: a short reason */
			echo '<p style="color:#b32d2e">' . esc_html( sprintf( __( 'The last call to Google failed: %s.', 'zorderz' ), $s['why'] ) ) . '</p>';
		}
		if ( array() === $loc ) {
			echo '<p>' . esc_html__( 'Step 3: find the Business Profile location this site reads.', 'zorderz' ) . '</p>';
			echo $copy ? '' : $btn( 'zdz_gbp_find', __( 'Find my location', 'zorderz' ), true ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
			$found = get_transient( self::T_FOUND . $uid );
			if ( is_array( $found ) && $found ) {
				echo '<form method="post" action="' . esc_url( $post ) . '">' . wp_nonce_field( 'zdz_gbp_pick', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="zdz_gbp_pick">'; // phpcs:ignore WordPress.Security.EscapeOutput -- nonce field markup
				foreach ( array_values( $found ) as $i => $f ) {
					if ( ! is_array( $f ) ) {
						continue;
					}
					$addr = (string) ( $f['address'] ?? '' );
					echo '<label style="display:block;margin:4px 0"><input type="radio" name="i" value="' . (int) $i . '"' . ( 0 === $i ? ' checked' : '' ) . '> '
						. esc_html( (string) ( $f['title'] ?? '' ) ) . ( '' !== $addr ? ' — ' . esc_html( $addr ) : '' ) . '</label>';
				}
				echo '<button class="button button-primary">' . esc_html__( 'Use this location', 'zorderz' ) . '</button></form>';
			}
		} else {
			$sum = self::summary( false );
			echo '<p>' . esc_html__( 'Location:', 'zorderz' ) . ' <strong>' . esc_html( $loc['title'] ) . '</strong>'
				/* translators: 1: number of reviews, 2: average star rating */
				. ( is_array( $sum ) ? ' · ' . esc_html( sprintf( __( '%1$d reviews, average %2$s', 'zorderz' ), (int) $sum['total'], null === $sum['average'] ? '—' : number_format( (float) $sum['average'], 1 ) ) ) : '' ) . '</p>';
			if ( ! $copy ) {
				echo $btn( 'zdz_gbp_test', __( 'Test: ask Google now', 'zorderz' ) ) . $btn( 'zdz_gbp_find', __( 'Choose another location', 'zorderz' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
			}
		}
		echo ( $copy ? '' : $btn( 'zdz_gbp_connect', __( 'Reconnect', 'zorderz' ) ) ) . $btn( 'zdz_gbp_disconnect', __( 'Disconnect', 'zorderz' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
		echo '<p class="description">' . esc_html__( 'Read-only: the site reads this location\'s reviews, and their total and average for the home screen (at most once an hour). It never replies to a review or changes anything on Google.', 'zorderz' ) . '</p>';
		echo '</div>';
	}

	/* =====================================================================
	 * HOUSEKEEPING
	 * ===================================================================== */

	/** zdz_salt_watch_breaks: the sign-in is sealed under the auth salt. */
	public static function salt_breaks( $lines, $changed = array() ) {
		$lines = is_array( $lines ) ? $lines : array();
		$t     = get_option( self::OPT_TOKEN, array() );
		if ( in_array( 'auth', (array) $changed, true ) && is_array( $t ) && ! empty( $t['refresh'] ) ) {
			$lines[] = __( 'The Google Business Profile sign-in can no longer be read: press Connect again under Zorderz → Credentials → Google Business Profile.', 'zorderz' );
		}
		return $lines;
	}

	/** A data export never carries the sign-in. */
	public static function export_secret_names( $names ) {
		$names   = is_array( $names ) ? $names : array();
		$names[] = self::OPT_TOKEN;
		return $names;
	}

	/** The sign-in and its last status belong to one install. */
	public static function export_non_portable( $patterns ) {
		$patterns   = is_array( $patterns ) ? $patterns : array();
		$patterns[] = '/^zdz_core_gbp_(token|status)$/';
		return $patterns;
	}
}
