<?php
/**
 * ZIB_Settings — option-backed config for per-user M365 mail connect (Zorderz Inbox).
 *
 * SECURITY: the delegated-app client secret is the one genuinely sensitive
 * value. It lives in its own isolated, NON-autoloaded option, is read only
 * server-side by ZIB_Graph, is NEVER localized to JS, never returned by REST,
 * and the admin screen shows only whether it is set (not the value) — exactly
 * the posture ZSCH_Settings uses for `zsch_ms_delegated_secret`.
 *
 * Single-tenant by configuration: only the configured tenant's mailboxes
 * complete the flow. `tenant_id` is [IDENTITY] / BID-7 Connection config — it
 * lives in an option, never in code.
 *
 * Feature flag `zib_enabled` is default 'no': with it down every surface
 * (routes, card, REST, cron) no-ops. Nothing reads a mailbox until an admin
 * turns this on AND a user connects.
 *
 * @since 0.1.0 (P0 — Connect)
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIB_Settings {

	const OPT_CONFIG  = 'zib_config';           // tenant_id, client_id
	const OPT_SECRET  = 'zib_ms_secret';        // delegated-app client secret (isolated, non-autoload)
	const OPT_FLAG    = 'zib_enabled';          // 'yes' | 'no' (default no) — connect feature
	const OPT_INGEST  = 'zib_ingest_enabled';   // 'yes' | 'no' (default no) — the dark ingestion switch
	const OPT_ENRICH  = 'zib_enrich_enabled';   // 'yes' | 'no' (default no) — the dark enrichment switch (P6a)
	const OPT_DOMAINS = 'zib_internal_domains'; // comma/space list; [IDENTITY]/BID-6

	/**
	 * Config array (secret excluded).
	 *
	 * @return array{tenant_id:string,client_id:string}
	 */
	public static function config(): array {
		$cfg = get_option( self::OPT_CONFIG, array() );
		if ( ! is_array( $cfg ) ) {
			$cfg = array();
		}
		return wp_parse_args( $cfg, array(
			'tenant_id' => '',
			'client_id' => '',
		) );
	}

	/**
	 * Save config (merges known keys only; never touches the secret here).
	 *
	 * @param array $patch
	 */
	public static function update_config( array $patch ): void {
		$cfg = self::config();
		foreach ( array( 'tenant_id', 'client_id' ) as $k ) {
			if ( array_key_exists( $k, $patch ) ) {
				$cfg[ $k ] = sanitize_text_field( (string) $patch[ $k ] );
			}
		}
		update_option( self::OPT_CONFIG, $cfg );
	}

	/** Store the client secret. Non-autoloaded; '' deletes it. */
	public static function set_secret( string $secret ): void {
		if ( '' === $secret ) {
			delete_option( self::OPT_SECRET );
			return;
		}
		update_option( self::OPT_SECRET, $secret, false );
	}

	/** Read the client secret. Server-side callers only (ZIB_Graph). */
	public static function secret(): string {
		return (string) get_option( self::OPT_SECRET, '' );
	}

	/** Is a client secret on file? (For the admin "configured?" indicator.) */
	public static function has_secret(): bool {
		return '' !== self::secret();
	}

	/** The Azure tenant id (single-tenant authority). */
	public static function tenant_id(): string {
		return (string) self::config()['tenant_id'];
	}

	/** The delegated-app client id. */
	public static function client_id(): string {
		return (string) self::config()['client_id'];
	}

	/**
	 * The internal ("employee") email domains — [IDENTITY] / BID-6. Read from
	 * an option, never hardcoded. Ships EMPTY: when the option is unset, this
	 * falls through to Identity config / the Business Profile (the tenant's
	 * primary email domain, else its app domain) instead of any built-in
	 * default.
	 *
	 * @return string[] lowercase bare domains
	 */
	public static function internal_domains(): array {
		$raw = (string) get_option( self::OPT_DOMAINS, '' );
		$out = array();
		foreach ( preg_split( '/[\s,]+/', strtolower( $raw ) ) as $d ) {
			$d = trim( $d );
			if ( '' !== $d ) {
				$out[] = $d;
			}
		}
		if ( ! empty( $out ) ) {
			return $out;
		}
		if ( class_exists( 'ZDZ_Business_Profile' ) ) {
			$domain = (string) ZDZ_Business_Profile::get( 'email.primary_domain' );
			if ( '' === $domain ) {
				$domain = (string) ZDZ_Business_Profile::get( 'domains.app' );
			}
			$domain = strtolower( trim( $domain ) );
			if ( '' !== $domain ) {
				return array( $domain );
			}
		}
		return array();
	}

	public static function set_internal_domains( string $csv ): void {
		update_option( self::OPT_DOMAINS, sanitize_text_field( $csv ) );
	}

	/**
	 * Feature master switch: flag ON *and* fully configured. Default OFF.
	 * Mirrors ZSCH_OAuth::feature_enabled()'s "flag AND provider configured".
	 */
	public static function feature_enabled(): bool {
		if ( 'yes' !== get_option( self::OPT_FLAG, 'no' ) ) {
			return false;
		}
		$cfg = self::config();
		return '' !== $cfg['tenant_id'] && '' !== $cfg['client_id'] && self::has_secret();
	}

	/**
	 * The DARK ingestion switch (P1). Default OFF. Even with the feature live and
	 * mailboxes connected, NO mail is read/indexed until this is turned on. Lets
	 * the owner deploy + verify P1 before any real inbox is touched.
	 */
	public static function ingest_enabled(): bool {
		return self::feature_enabled() && 'yes' === get_option( self::OPT_INGEST, 'no' );
	}

	/**
	 * The DARK enrichment switch (P6a). Default OFF. With it off, no index card / extract /
	 * tag is derived or stored — deploying P6a changes nothing until the owner turns it on.
	 * Independent of ingest_enabled so already-indexed mail can be enriched even with
	 * forward ingestion paused.
	 */
	public static function enrich_enabled(): bool {
		return self::feature_enabled() && 'yes' === get_option( self::OPT_ENRICH, 'no' );
	}
}
