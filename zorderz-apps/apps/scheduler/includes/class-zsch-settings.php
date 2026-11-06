<?php
/**
 * ZSCH_Settings
 *
 * Thin option-backed config store for the scheduler. Holds the Microsoft 365
 * (Azure AD) connection details and a couple of sync cursors. Kept tiny and
 * static so any class can read config without bootstrapping an object.
 *
 * SECURITY: the client secret is the one genuinely sensitive value. It is
 * stored in a dedicated option and only ever read server-side by ZSCH_Graph.
 * It is NEVER localized to JS, never returned by REST, and the admin screen
 * shows only whether it is set (not the value).
 *
 * v1.6.0 adds the Connected Calendars (per-user OAuth) config block: Google +
 * Microsoft delegated app credentials. Same posture — each secret lives in
 * its own isolated option (`zsch_google_secret`, `zsch_ms_delegated_secret`,
 * exactly like `zsch_graph_secret`), write-only from the admin screen,
 * never sent to the browser, never logged.
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZSCH_Settings {

	const OPT_CONFIG = 'zsch_graph_config'; // tenant_id, client_id, default_tz, sync_enabled
	const OPT_SECRET = 'zsch_graph_secret'; // client secret (isolated)
	const OPT_TOKEN  = 'zsch_graph_token';  // cached app-only bearer + expiry

	// v1.6.0 — Connected Calendars (per-user OAuth) config.
	const OPT_CONNCAL_CONFIG    = 'zsch_conncal_config';      // google_client_id, ms_client_id, conflict_policy
	const OPT_GOOGLE_SECRET     = 'zsch_google_secret';       // Google OAuth client secret (isolated)
	const OPT_MS_DELEG_SECRET   = 'zsch_ms_delegated_secret'; // Microsoft delegated-app secret (isolated)

	// Which connected accounts count as WORK calendars (comma/space list of domains).
	const OPT_WORK_DOMAINS      = 'zsch_work_domains';

	/* ── App credentials managed centrally ─────────────────────────────────
	 * Three services, judged independently:
	 *   ms_calendar_sync  zsch_graph_config[tenant_id|client_id], zsch_graph_secret
	 *   google_calendar   zsch_conncal_config[google_client_id], zsch_google_secret
	 *   ms_calendars      zsch_conncal_config[ms_client_id], zsch_ms_delegated_secret
	 * While a service is engaged on the platform's Credentials screen, this app's
	 * settings form shows one line in place of its inputs and never writes them.
	 * Without that screen (an older theme, or none) nothing changes. */

	/** Is $svc managed on the platform's Credentials screen? */
	public static function cred_managed( string $svc ): bool {
		return class_exists( 'ZDZ_Core_Credentials' )
			&& is_callable( array( 'ZDZ_Core_Credentials', 'is_app' ) )
			&& is_callable( array( 'ZDZ_Core_Credentials', 'engaged' ) )
			&& ZDZ_Core_Credentials::engaged( $svc );
	}

	/** A form that hid $svc's fields says so; its submit must not write them (a stale form). */
	public static function managed_posted( string $svc ): bool {
		return isset( $_POST['zdz_cred_managed'] ) && is_array( $_POST['zdz_cred_managed'] ) // phpcs:ignore WordPress.Security.NonceVerification -- read inside the caller's nonce-checked save
			&& ! empty( $_POST['zdz_cred_managed'][ $svc ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read only by the admin saves, after check_admin_referer(); it can only stop a write.
	}

	/** Engaged now OR the submitted form had hidden this service's fields: do not write them. */
	public static function cred_locked( string $svc ): bool {
		return self::cred_managed( $svc ) || self::managed_posted( $svc );
	}

	/** Admin URL of the platform's Credentials screen. */
	public static function credentials_url(): string {
		$page = ( class_exists( 'ZDZ_Core_Credentials' ) && defined( 'ZDZ_Core_Credentials::PAGE' ) )
			? (string) constant( 'ZDZ_Core_Credentials::PAGE' )
			: 'zdz-core-credentials';
		return admin_url( 'admin.php?page=' . rawurlencode( $page ) );
	}

	/**
	 * Get the full config array (secret excluded).
	 *
	 * @return array{tenant_id:string,client_id:string,default_tz:string,sync_enabled:bool}
	 */
	public static function get_config() {
		$cfg = get_option( self::OPT_CONFIG, array() );
		if ( ! is_array( $cfg ) ) {
			$cfg = array();
		}
		return wp_parse_args( $cfg, array(
			'tenant_id'    => '',
			'client_id'    => '',
			// Ships EMPTY — the effective tenant time zone is resolved by
			// default_tz() from the Business Profile / site config, never a
			// hardcoded region. An admin may still pin one here explicitly.
			'default_tz'   => '',
			'sync_enabled' => false,
		) );
	}

	/**
	 * Save config (merges; never touches the secret here).
	 *
	 * @param array $patch
	 */
	public static function update_config( array $patch ) {
		$cfg = self::get_config();
		// Only allow known keys through.
		foreach ( array( 'tenant_id', 'client_id', 'default_tz', 'sync_enabled' ) as $k ) {
			if ( array_key_exists( $k, $patch ) ) {
				$cfg[ $k ] = ( 'sync_enabled' === $k ) ? (bool) $patch[ $k ] : sanitize_text_field( (string) $patch[ $k ] );
			}
		}
		update_option( self::OPT_CONFIG, $cfg );
	}

	/**
	 * Store the client secret (write-only from the admin's perspective).
	 *
	 * @param string $secret  Pass '' to leave unchanged is handled by caller.
	 */
	public static function set_secret( $secret ) {
		update_option( self::OPT_SECRET, (string) $secret );
	}

	/**
	 * Read the client secret. Server-side callers only (ZSCH_Graph).
	 *
	 * @return string
	 */
	public static function get_secret() {
		return (string) get_option( self::OPT_SECRET, '' );
	}

	/**
	 * Is a client secret on file? (For the admin "configured?" indicator —
	 * does not reveal the value.)
	 *
	 * @return bool
	 */
	public static function has_secret() {
		return '' !== self::get_secret();
	}

	/**
	 * Is two-way Graph sync fully configured AND switched on?
	 *
	 * @return bool
	 */
	public static function sync_active() {
		$cfg = self::get_config();
		return ! empty( $cfg['sync_enabled'] )
			&& ! empty( $cfg['tenant_id'] )
			&& ! empty( $cfg['client_id'] )
			&& self::has_secret();
	}

	/**
	 * Default tenant time zone (IANA), used when a user's mailbox tz is unknown.
	 *
	 * @return string
	 */
	public static function default_tz() {
		// 1) A future shared Core setting, if the theme ships one (so the plugin
		//    and any shared ZDZ_Core_Graph client agree on the tenant tz).
		if ( class_exists( 'ZDZ_Core_Settings' ) && method_exists( 'ZDZ_Core_Settings', 'get_graph_default_tz' ) ) {
			$tz = (string) ZDZ_Core_Settings::get_graph_default_tz();
			if ( '' !== $tz ) {
				return $tz;
			}
		}
		// 2) An explicit admin override on this app's settings screen.
		$cfg = self::get_config();
		if ( ! empty( $cfg['default_tz'] ) ) {
			return $cfg['default_tz'];
		}
		// 3) The tenant's Business Profile time zone (Identity).
		if ( class_exists( 'ZDZ_Business_Profile' ) ) {
			$tz = (string) ZDZ_Business_Profile::get( 'locale.timezone', '' );
			if ( '' !== $tz ) {
				return $tz;
			}
		}
		// 4) The WordPress site's configured time zone — never a hardcoded region.
		$tz = function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : '';
		return '' !== $tz ? $tz : 'UTC';
	}

	// ── cached app-only token ──────────────────────────────────────

	/**
	 * @return array{access_token:string,expires_at:int}|null
	 */
	public static function get_cached_token() {
		$t = get_option( self::OPT_TOKEN, null );
		if ( is_array( $t ) && ! empty( $t['access_token'] ) && ! empty( $t['expires_at'] ) ) {
			return $t;
		}
		return null;
	}

	/**
	 * @param string $access_token
	 * @param int    $expires_in  seconds
	 */
	public static function set_cached_token( $access_token, $expires_in ) {
		update_option( self::OPT_TOKEN, array(
			'access_token' => (string) $access_token,
			// Refresh 5 minutes early to avoid edge expiry mid-request.
			'expires_at'   => time() + max( 60, (int) $expires_in - 300 ),
		), false );
	}

	public static function clear_cached_token() {
		delete_option( self::OPT_TOKEN );
	}

	// ── Connected Calendars (v1.6.0, per-user OAuth) ───────────────

	/**
	 * Connected Calendars config (secrets excluded).
	 *
	 * @return array{google_client_id:string,ms_client_id:string,conflict_policy:string}
	 */
	public static function conncal_config() {
		$cfg = get_option( self::OPT_CONNCAL_CONFIG, array() );
		if ( ! is_array( $cfg ) ) {
			$cfg = array();
		}
		return wp_parse_args( $cfg, array(
			'google_client_id' => '',
			'ms_client_id'     => '',
			'conflict_policy'  => 'warn', // warn | block (enforced by the busy overlay)
		) );
	}

	/**
	 * Save Connected Calendars config (merges; secrets handled separately).
	 *
	 * @param array $patch
	 */
	public static function update_conncal_config( array $patch ) {
		$cfg = self::conncal_config();
		foreach ( array( 'google_client_id', 'ms_client_id', 'conflict_policy' ) as $k ) {
			if ( array_key_exists( $k, $patch ) ) {
				$val = sanitize_text_field( (string) $patch[ $k ] );
				if ( 'conflict_policy' === $k && ! in_array( $val, array( 'warn', 'block' ), true ) ) {
					$val = 'warn';
				}
				$cfg[ $k ] = $val;
			}
		}
		update_option( self::OPT_CONNCAL_CONFIG, $cfg );
	}

	/** Google OAuth client secret — isolated option, non-autoloaded, server-side only. */
	public static function set_google_secret( $secret ) {
		if ( '' === (string) $secret ) {
			delete_option( self::OPT_GOOGLE_SECRET );
			return;
		}
		update_option( self::OPT_GOOGLE_SECRET, (string) $secret, false );
	}

	public static function google_secret() {
		return (string) get_option( self::OPT_GOOGLE_SECRET, '' );
	}

	public static function has_google_secret() {
		return '' !== self::google_secret();
	}

	/** Microsoft delegated-app client secret — isolated, non-autoloaded, server-side only. */
	public static function set_ms_delegated_secret( $secret ) {
		if ( '' === (string) $secret ) {
			delete_option( self::OPT_MS_DELEG_SECRET );
			return;
		}
		update_option( self::OPT_MS_DELEG_SECRET, (string) $secret, false );
	}

	public static function ms_delegated_secret() {
		return (string) get_option( self::OPT_MS_DELEG_SECRET, '' );
	}

	public static function has_ms_delegated_secret() {
		return '' !== self::ms_delegated_secret();
	}

	// ── Which connected calendars are WORK calendars ───────────────

	/**
	 * The e-mail domains whose connected calendars count as WORK calendars.
	 *
	 * The MECHANISM (match an account's domain) is Core; the VALUE is the
	 * business's own, so it lives in an option. Core default: none, which
	 * discloses no event titles at all. Same shape as the inbox app's internal
	 * domains — two apps asking "is this one of ours?" should answer it the
	 * same way.
	 *
	 * Accepts a comma/space/semicolon separated list. Each entry is lowercased,
	 * stripped of a trailing root dot, and REQUIRED TO CONTAIN A DOT, so a stray
	 * token ('com', a typo, an empty field left by a save) cannot become a
	 * matchable domain.
	 *
	 * @return string[] lowercased bare domains; empty array = disclose nothing.
	 */
	public static function work_domains() {
		$raw = (string) get_option( self::OPT_WORK_DOMAINS, '' );
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', strtolower( $raw ) ) as $d ) {
			$d = trim( $d, " \t\n\r\0\x0B." );
			if ( '' !== $d && false !== strpos( $d, '.' ) ) {
				$out[] = $d;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Is this connected account a WORK account?
	 *
	 * The one question that decides whether a colleague may see WHAT somebody is
	 * busy with, or only THAT they are busy. It is asked of the ACCOUNT's address
	 * (`wp_zsch_calendar_accounts.email_label`), never of the event.
	 *
	 * FAIL CLOSED, because the two errors are not symmetric. A work calendar
	 * mistaken for a personal one costs a tooltip; a personal calendar mistaken
	 * for a work one publishes somebody's private life to everyone they work
	 * with, and it cannot be un-seen. So every uncertain case answers false:
	 * no '@', nothing after the '@', a non-string, an empty domain list.
	 *
	 * Matching is EXACT equality against a configured domain, after lowercasing
	 * and dropping a trailing root dot. Not a substring test, which would accept
	 * `x@notexample.com` for `example.com`; not a prefix or suffix test, which
	 * would accept `x@example.com.attacker.net`. Subdomains do NOT match either —
	 * `x@sub.example.com` is a different mail domain and this function is not
	 * the place to guess that it is ours.
	 *
	 * The domain is taken from the LAST '@', which is what RFC 5321 means by the
	 * domain part: a quoted local part may legally contain one.
	 *
	 * @param string $email Account address as stored on the account row.
	 * @return bool
	 */
	public static function is_work_email( $email ) {
		if ( ! is_string( $email ) ) {
			return false;
		}
		$email = strtolower( trim( $email ) );
		$at    = strrpos( $email, '@' );
		if ( false === $at ) {
			return false;
		}
		$domain = trim( rtrim( substr( $email, $at + 1 ), '.' ) );
		if ( '' === $domain ) {
			return false;
		}
		$domains = self::work_domains();
		if ( empty( $domains ) ) {
			return false;
		}
		return in_array( $domain, $domains, true );
	}
}
