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
			'conflict_policy'  => 'warn', // warn | block (enforced from Phase 1)
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
}
