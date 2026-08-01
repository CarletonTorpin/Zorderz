<?php
/**
 * Zorderz — Token Service (Connections credential authority)
 *
 * The single authoritative, lock-protected OAuth token refresher for the
 * platform. It guarantees AT MOST ONE network refresh per token rotation,
 * across every concurrent worker, for every registered provider.
 *
 * WHY IT EXISTS
 * Many OAuth providers issue SINGLE-USE refresh tokens: a successful refresh
 * mints a new refresh token and immediately revokes the old one, with no grace
 * window. When several independent refreshers run near-simultaneously they
 * revoke each other's tokens, producing a cascade of 401s that clears only with
 * a manual reconnect. This service serialises refreshes behind a single-flight
 * lock so that never happens, and converts contended on-demand refreshes into
 * lone scheduled work.
 *
 * PROVIDER-AGNOSTIC BY DESIGN
 * The core knows nothing about any specific provider. A provider registers
 * itself — its token endpoint, a credential resolver, its OAuth redirect URIs,
 * and the legacy option families to keep in sync — through the
 * `zdz_token_providers` filter. A refresh() call with no explicit provider
 * resolves the default provider. The bundled FreshBooks registration at the
 * foot of this file is the reference example and may be replaced or extended by
 * any tenant. This is the first shape of the Connections core service; the
 * eventual Connections layer replaces back-compat projection with one encrypted
 * row per connection instance, at which point providers keep this same
 * registration contract and only their credential store changes.
 *
 * PUBLIC CONTRACT (stable; existing consumers need no changes)
 *   ZDZ_Token_Service::refresh( array $args ): string
 *       $args: client_id, client_secret (ALREADY-DECRYPTED), refresh_token,
 *              redirect_uris (array, optional), force (bool, optional),
 *              provider (string id, optional — defaults to the default provider)
 *       Returns a valid ACCESS TOKEN, or '' on failure (callers fall back to
 *       their own path).
 *   ZDZ_Token_Service::get_refresh_token( $provider = null ): string
 *   ZDZ_Token_Service::get_access_token( $provider = null ): string
 *   ZDZ_Token_Service::get_account_id( $provider = null ): string
 *   ZDZ_Token_Service::needs_reauth( $provider = null ): bool
 *
 * CANONICAL STORE (wp_options, non-autoloaded), per provider store key:
 *   zdz_tok_<key>_access_token · _refresh_token · _account_id · _version
 *   (monotonic rotation counter) · _expires_at · _reauth_needed ·
 *   _cooldown_until · _last_refresh
 *
 * MECHANISM (preserved verbatim from the proven single-flight refresher)
 *  1. LOCK — MySQL advisory lock GET_LOCK (session-scoped, auto-released if the
 *     worker dies). Fallback when GET_LOCK is unavailable: an atomic options-row
 *     lock (INSERT IGNORE on the UNIQUE option_name index; stale-break). Never a
 *     transient / object-cache lock (non-atomic; evictable).
 *  2. LEADER — acquires the lock, DOUBLE-CHECKS the rotation counter and token
 *     freshness (adopt-only when another worker already rotated), performs
 *     exactly one network refresh (one grace-retry on transient errors only),
 *     then PERSISTS the canonical store + version++ BEFORE releasing the lock.
 *  3. FOLLOWER — lock busy → never refreshes; polls the rotation counter and
 *     adopts the leader's result with zero network calls; times out to ''.
 *  4. CIRCUIT BREAKER — a refresh that fails as a DEAD token (auth-class error
 *     on every redirect URI) sets a re-auth flag + a short cooldown during which
 *     all attempts short-circuit to '', killing request storms. Stored tokens
 *     are NEVER blanked; the flag clears on the next successful store.
 *  5. PROACTIVE CRON — every 15 minutes, refresh ahead of expiry (uncontended).
 *  6. BACK-COMPAT PROJECTION — after a successful refresh the new pair is
 *     mirrored into each of the provider's registered legacy option families
 *     that ALREADY exist on this install, so un-migrated readers keep working.
 *     Families are provider-registered, not hard-coded in the core.
 *
 * ROLLBACK: no destructive migration. Legacy option families stay populated; a
 * consumer that stops calling this service reverts to its own refresher.
 *
 * @package Zorderz
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ZDZ_Token_Service' ) ) :

class ZDZ_Token_Service {

	const VER                 = '1.1.0';
	const LOCK_STALE_SEC      = 60;   // stale-break for the options-row lock
	const FOLLOW_WAIT_MS      = 500;  // follower poll interval
	const FOLLOW_MAX_S        = 20;   // follower max wait
	const COOLDOWN_SEC        = 300;  // dead-token circuit-breaker cooldown
	const REFRESH_MARGIN_SEC  = 300;  // "still fresh" margin for leader adopt-fresh
	const CRON_REFRESH_WINDOW = 600;  // proactively refresh when expiry is within this

	/** Canonical store suffixes, per provider store key. */
	private static $store_suffixes = array(
		'access_token', 'refresh_token', 'account_id', 'version',
		'expires_at', 'reauth_needed', 'cooldown_until', 'last_refresh',
	);

	/** Active lock descriptor for this request: [ mode, name, row ]. */
	private static $lock = array( 'mode' => '', 'name' => '', 'row' => '' );

	// ─────────────────────────────────────────────────────────────────
	// PROVIDER REGISTRY (the registration API replaces all hard-coding)
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Registered providers, keyed by id.
	 *
	 * A provider descriptor may declare:
	 *   id, label, default (bool), store_key, token_url, grant,
	 *   body_format ('json'|'form'), request_headers[], body_extra[],
	 *   default_ttl, plaintext_secret_max, option_families[], legacy_options[],
	 *   redirect_uris (array|callable), credentials (array|callable resolver
	 *   returning client_id/client_secret/refresh_token).
	 *
	 * NOT memoized: a provider registered late (e.g. on `init`) must still be
	 * seen by refresh()/cron.
	 *
	 * @return array<string,array>
	 */
	private static function providers() {
		$raw = apply_filters( 'zdz_token_providers', array() );
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( $raw as $key => $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$id = isset( $p['id'] ) ? (string) $p['id'] : (string) $key;
			if ( '' === $id ) {
				continue;
			}
			$store_key = isset( $p['store_key'] ) && '' !== $p['store_key'] ? (string) $p['store_key'] : $id;
			$store_key = preg_replace( '/[^a-z0-9_]/', '', strtolower( $store_key ) );
			if ( '' === $store_key ) {
				continue;
			}

			$p['id']                   = $id;
			$p['store_key']            = $store_key;
			$p['label']                = isset( $p['label'] ) ? (string) $p['label'] : $id;
			$p['default']              = ! empty( $p['default'] );
			$p['token_url']            = isset( $p['token_url'] ) ? (string) $p['token_url'] : '';
			$p['grant']                = isset( $p['grant'] ) ? (string) $p['grant'] : 'refresh_token';
			$p['body_format']          = ( isset( $p['body_format'] ) && 'form' === $p['body_format'] ) ? 'form' : 'json';
			$p['request_headers']      = ( isset( $p['request_headers'] ) && is_array( $p['request_headers'] ) ) ? $p['request_headers'] : array();
			$p['body_extra']           = ( isset( $p['body_extra'] ) && is_array( $p['body_extra'] ) ) ? $p['body_extra'] : array();
			$p['default_ttl']          = isset( $p['default_ttl'] ) ? max( 60, (int) $p['default_ttl'] ) : 3600;
			$p['plaintext_secret_max'] = isset( $p['plaintext_secret_max'] ) ? (int) $p['plaintext_secret_max'] : 0;
			$p['option_families']      = ( isset( $p['option_families'] ) && is_array( $p['option_families'] ) )
				? array_values( array_unique( array_map( 'strval', $p['option_families'] ) ) ) : array();
			$p['legacy_options']       = ( isset( $p['legacy_options'] ) && is_array( $p['legacy_options'] ) )
				? array_values( array_unique( array_map( 'strval', $p['legacy_options'] ) ) ) : array();
			$p['legacy_cron']          = ( isset( $p['legacy_cron'] ) && is_array( $p['legacy_cron'] ) )
				? array_values( array_unique( array_map( 'strval', $p['legacy_cron'] ) ) ) : array();

			$out[ $id ] = $p;
		}
		return $out;
	}

	/**
	 * Resolve a provider by id, or the default provider when none is given.
	 *
	 * Default = the descriptor flagged `default`, else the `zdz_token_default_provider`
	 * filter value, else the first registered provider.
	 *
	 * @param string|null $provider Provider id, or null for the default.
	 * @return array|null
	 */
	private static function resolve_provider( $provider = null ) {
		$providers = self::providers();
		if ( empty( $providers ) ) {
			return null;
		}
		if ( is_string( $provider ) && '' !== $provider && isset( $providers[ $provider ] ) ) {
			return $providers[ $provider ];
		}
		$default_id = '';
		foreach ( $providers as $id => $p ) {
			if ( $p['default'] ) {
				$default_id = $id;
				break;
			}
		}
		if ( '' === $default_id ) {
			$default_id = (string) apply_filters( 'zdz_token_default_provider', '' );
		}
		if ( '' === $default_id || ! isset( $providers[ $default_id ] ) ) {
			$ids        = array_keys( $providers );
			$default_id = $ids[0];
		}
		return $providers[ $default_id ];
	}

	/** Canonical option name for a provider store field. */
	private static function opt( array $provider, $suffix ) {
		return 'zdz_tok_' . $provider['store_key'] . '_' . $suffix;
	}

	// ─────────────────────────────────────────────────────────────────
	// PUBLIC API
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Get a fresh access token, refreshing (single-flight) if needed.
	 *
	 * @param array $args See the file docblock. `provider` selects the instance;
	 *                    omitted → the default provider (contract-compatible with
	 *                    the pre-generalization single-provider service).
	 * @return string New/valid access token, or '' on failure (caller falls back).
	 */
	public static function refresh( array $args = array() ) {
		$provider = self::resolve_provider( isset( $args['provider'] ) ? (string) $args['provider'] : null );
		if ( ! $provider ) {
			self::log( 'refresh ABORT — no token provider registered/resolved. Register one via the zdz_token_providers filter.' );
			return '';
		}
		if ( '' === $provider['token_url'] ) {
			self::log( '[' . $provider['id'] . '] refresh ABORT — provider has no token_url endpoint configured' );
			return '';
		}

		self::seed_if_empty( $provider );

		// ── Circuit breaker: dead-token cooldown (storm killer) ──
		$cooldown = (int) get_option( self::opt( $provider, 'cooldown_until' ), 0 );
		if ( $cooldown > time() && empty( $args['force'] ) ) {
			self::log( '[' . $provider['id'] . '] refresh REFUSED — re-auth needed, cooldown active ' . ( $cooldown - time() ) . 's (origin=' . self::origin() . ')' );
			return '';
		}

		$entry_version = (int) get_option( self::opt( $provider, 'version' ), 0 );

		if ( self::acquire_lock( $provider ) ) {
			// ══ LEADER ══
			try {
				// Double-check #1 — rotation counter: another worker may have
				// rotated between our call start and lock acquisition.
				$now_version = (int) get_option( self::opt( $provider, 'version' ), 0 );
				if ( $now_version > $entry_version ) {
					$adopted = (string) get_option( self::opt( $provider, 'access_token' ), '' );
					self::log( '[' . $provider['id'] . '] leader adopt — rotation v' . $now_version . ' already published while acquiring (race avoided, origin=' . self::origin() . ')' );
					return $adopted;
				}
				// Double-check #2 — FRESHNESS: if the canonical store already holds
				// a token with real life left (a rotation the caller simply hasn't
				// read yet), adopt it rather than burning another single-use
				// rotation.
				if ( empty( $args['force'] ) ) {
					$stored_access = (string) get_option( self::opt( $provider, 'access_token' ), '' );
					$expires_at    = (int) get_option( self::opt( $provider, 'expires_at' ), 0 );
					if ( '' !== $stored_access && ( $expires_at - time() ) > self::REFRESH_MARGIN_SEC ) {
						self::log( '[' . $provider['id'] . '] leader adopt-fresh — stored access token still valid '
							. round( ( $expires_at - time() ) / 60 ) . 'm; skipping network refresh (origin=' . self::origin() . ')' );
						return $stored_access;
					}
				}
				return self::network_refresh_locked( $provider, $args, $entry_version );
			} finally {
				self::release_lock();
			}
		}

		// ══ FOLLOWER ══ — never refresh in parallel; wait and adopt.
		$deadline = microtime( true ) + self::FOLLOW_MAX_S;
		while ( microtime( true ) < $deadline ) {
			usleep( self::FOLLOW_WAIT_MS * 1000 );
			wp_cache_delete( self::opt( $provider, 'version' ), 'options' ); // bust alloptions staleness
			$v = (int) get_option( self::opt( $provider, 'version' ), 0 );
			if ( $v > $entry_version ) {
				$adopted = (string) get_option( self::opt( $provider, 'access_token' ), '' );
				self::log( '[' . $provider['id'] . '] follower adopt — rotation v' . $v . ' published by leader (race avoided, origin=' . self::origin() . ')' );
				return $adopted;
			}
			// If the leader failed terminally, its cooldown flag lets us exit early.
			if ( (int) get_option( self::opt( $provider, 'cooldown_until' ), 0 ) > time() ) {
				self::log( '[' . $provider['id'] . '] follower exit — leader reported dead refresh token (origin=' . self::origin() . ')' );
				return '';
			}
		}
		self::log( '[' . $provider['id'] . '] follower TIMEOUT after ' . self::FOLLOW_MAX_S . 's — no rotation published; returning empty (caller falls back; origin=' . self::origin() . ')' );
		return '';
	}

	/** Canonical refresh token for a provider (default provider when null). */
	public static function get_refresh_token( $provider = null ) {
		$p = self::resolve_provider( is_string( $provider ) ? $provider : null );
		if ( ! $p ) {
			return '';
		}
		self::seed_if_empty( $p );
		return (string) get_option( self::opt( $p, 'refresh_token' ), '' );
	}

	/** Canonical access token for a provider (default provider when null). */
	public static function get_access_token( $provider = null ) {
		$p = self::resolve_provider( is_string( $provider ) ? $provider : null );
		if ( ! $p ) {
			return '';
		}
		self::seed_if_empty( $p );
		return (string) get_option( self::opt( $p, 'access_token' ), '' );
	}

	/** Canonical account id for a provider (default provider when null). */
	public static function get_account_id( $provider = null ) {
		$p = self::resolve_provider( is_string( $provider ) ? $provider : null );
		if ( ! $p ) {
			return '';
		}
		self::seed_if_empty( $p );
		return (string) get_option( self::opt( $p, 'account_id' ), '' );
	}

	/** Whether a provider is in the "needs manual reconnect" state. */
	public static function needs_reauth( $provider = null ) {
		$p = self::resolve_provider( is_string( $provider ) ? $provider : null );
		if ( ! $p ) {
			return false;
		}
		return 'yes' === get_option( self::opt( $p, 'reauth_needed' ), '' );
	}

	// ─────────────────────────────────────────────────────────────────
	// LEADER: the one network refresh (runs INSIDE the lock)
	// ─────────────────────────────────────────────────────────────────

	private static function network_refresh_locked( array $provider, array $args, $entry_version ) {
		$creds = self::resolve_credentials( $provider, $args );
		if ( '' === $creds['client_id'] || '' === $creds['client_secret'] || '' === $creds['refresh_token'] ) {
			self::log( '[' . $provider['id'] . '] refresh ABORT — missing credentials (id:' . ( $creds['client_id'] ? 'ok' : 'MISSING' )
				. ' secret:' . ( $creds['client_secret'] ? 'ok' : 'MISSING' )
				. ' rt:' . ( $creds['refresh_token'] ? 'ok' : 'MISSING' ) . ')' );
			return '';
		}

		// Guard: never transmit a secret that still looks ENCRYPTED (a stored
		// blob passed where the provider expects the plaintext OAuth secret).
		$max = (int) $provider['plaintext_secret_max'];
		if ( $max > 0 && strlen( $creds['client_secret'] ) > $max ) {
			self::log( '[' . $provider['id'] . '] refresh ABORT — client_secret looks ENCRYPTED (len=' . strlen( $creds['client_secret'] ) . ' > ' . $max . '); refusing to transmit' );
			return '';
		}

		$redirect_uris = self::redirect_uris( $provider, $args );
		if ( empty( $redirect_uris ) ) {
			$redirect_uris = array( '' ); // some providers do not require redirect_uri on refresh
		}

		$headers   = array_merge( self::default_headers( $provider ), $provider['request_headers'] );
		$auth_dead = true; // assume dead unless a transient/protocol reason says otherwise
		$last_code = 0;
		$last_body = '';

		foreach ( $redirect_uris as $ru ) {
			for ( $attempt = 1; $attempt <= 2; $attempt++ ) { // grace retry (transient errors only)
				$body = array_merge(
					array(
						'grant_type'    => $provider['grant'],
						'client_id'     => $creds['client_id'],
						'client_secret' => $creds['client_secret'],
						'refresh_token' => $creds['refresh_token'],
					),
					$provider['body_extra']
				);
				if ( '' !== $ru ) {
					$body['redirect_uri'] = $ru;
				}

				$resp = wp_remote_post( $provider['token_url'], array(
					'timeout' => 20,
					'headers' => $headers,
					'body'    => ( 'form' === $provider['body_format'] ) ? $body : wp_json_encode( $body ),
				) );

				if ( is_wp_error( $resp ) ) {
					$auth_dead = false; // network problem, not a dead token
					self::log( '[' . $provider['id'] . '] refresh attempt failed (network: ' . $resp->get_error_message() . ') attempt=' . $attempt );
					if ( 1 === $attempt ) {
						usleep( 1500000 );
						continue;
					}
					break; // next redirect_uri
				}

				$code      = (int) wp_remote_retrieve_response_code( $resp );
				$body_str  = (string) wp_remote_retrieve_body( $resp );
				$last_code = $code;
				$last_body = substr( $body_str, 0, 300 );

				if ( 200 === $code ) {
					$tok = json_decode( $body_str, true );
					if ( is_array( $tok ) && ! empty( $tok['access_token'] ) ) {
						self::store_and_project( $provider, $tok, $entry_version );
						return (string) $tok['access_token'];
					}
					self::log( '[' . $provider['id'] . '] refresh 200 but unparseable body — treating as transient' );
					$auth_dead = false;
					break;
				}

				if ( $code >= 500 ) {
					$auth_dead = false; // upstream hiccup
					self::log( '[' . $provider['id'] . '] refresh attempt got HTTP ' . $code . ' (transient) attempt=' . $attempt );
					if ( 1 === $attempt ) {
						usleep( 1500000 );
						continue;
					}
					break;
				}

				// 400/401/403: try the next redirect_uri (a provider can 401 a
				// refresh whose redirect_uri does not match the grant). Only if
				// EVERY uri fails auth-class do we call the token dead.
				self::log( '[' . $provider['id'] . '] refresh attempt got HTTP ' . $code . ' (trying next redirect_uri)' );
				break; // no grace retry on auth-class responses
			}
		}

		if ( $auth_dead ) {
			// Dead refresh token: the only recovery is a manual reconnect.
			// NEVER blank the stored tokens; flag + cooldown + admin notice.
			update_option( self::opt( $provider, 'reauth_needed' ), 'yes', false );
			update_option( self::opt( $provider, 'cooldown_until' ), time() + self::COOLDOWN_SEC, false );
			self::log( '[' . $provider['id'] . '] refresh DEAD-TOKEN — all redirect URIs exhausted (last HTTP ' . $last_code . ' body: ' . $last_body . '). Re-auth flag set, ' . self::COOLDOWN_SEC . 's cooldown engaged. Stored tokens untouched; admin must reconnect; field users are NOT prompted.' );
		} else {
			self::log( '[' . $provider['id'] . '] refresh FAILED (transient; last HTTP ' . $last_code . '). Stored tokens untouched; no cooldown.' );
		}
		return '';
	}

	/**
	 * Persist the rotated pair — canonical store first, then projection — all
	 * BEFORE the lock is released by the caller. A lost write here is a lost
	 * connection, so this is the critical section.
	 */
	private static function store_and_project( array $provider, array $tok, $entry_version ) {
		$access  = (string) $tok['access_token'];
		$refresh = (string) ( $tok['refresh_token'] ?? '' );
		$expires = time() + (int) ( $tok['expires_in'] ?? $provider['default_ttl'] );

		update_option( self::opt( $provider, 'access_token' ), $access, false );
		if ( '' !== $refresh ) {
			update_option( self::opt( $provider, 'refresh_token' ), $refresh, false );
		}
		update_option( self::opt( $provider, 'expires_at' ), $expires, false );
		update_option( self::opt( $provider, 'last_refresh' ), time(), false );
		update_option( self::opt( $provider, 'version' ), $entry_version + 1, false );

		// Success clears the breaker.
		delete_option( self::opt( $provider, 'reauth_needed' ) );
		delete_option( self::opt( $provider, 'cooldown_until' ) );

		// Back-compat projection — only families already in use on this install.
		$projected = array();
		foreach ( $provider['option_families'] as $fam ) {
			if ( false !== get_option( $fam . 'access_token', false ) || false !== get_option( $fam . 'refresh_token', false ) ) {
				update_option( $fam . 'access_token', $access, false );
				if ( '' !== $refresh ) {
					update_option( $fam . 'refresh_token', $refresh, false );
				}
				$projected[] = rtrim( $fam, '_' );
			}
		}

		self::log( '[' . $provider['id'] . '] refresh SUCCESS — single network refresh; version now ' . ( $entry_version + 1 )
			. '; expires in ' . round( ( $expires - time() ) / 3600, 1 ) . 'h'
			. '; projected → [' . implode( ', ', $projected ) . '] (origin=' . self::origin() . ')' );
	}

	// ─────────────────────────────────────────────────────────────────
	// CREDENTIALS, REDIRECT URIS, SEEDING
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Resolve credentials: caller-supplied first (already-decrypted), then the
	 * provider's registered resolver. Matched-pair only — a caller id is never
	 * mixed with a resolver secret.
	 *
	 * This replaces the old behaviour of reaching into named plugin admin
	 * classes: the kernel now resolves credentials from a provider-supplied
	 * resolver that reads the platform's own credential store.
	 */
	private static function resolve_credentials( array $provider, array $args ) {
		$id  = trim( (string) ( $args['client_id'] ?? '' ) );
		$sec = trim( (string) ( $args['client_secret'] ?? '' ) );
		$rt  = trim( (string) ( $args['refresh_token'] ?? '' ) );

		if ( '' === $rt ) {
			$rt = (string) get_option( self::opt( $provider, 'refresh_token' ), '' );
		}

		// A caller-supplied secret that looks ENCRYPTED is discarded (with its
		// id, to honour the matched-pair rule) and re-resolved below.
		$max = (int) $provider['plaintext_secret_max'];
		if ( '' !== $sec && $max > 0 && strlen( $sec ) > $max ) {
			self::log( '[' . $provider['id'] . '] caller-supplied client_secret looks ENCRYPTED (len=' . strlen( $sec ) . ') — discarding; resolving via provider resolver instead' );
			$sec = '';
			$id  = '';
		}

		if ( ( '' === $id || '' === $sec ) && isset( $provider['credentials'] ) ) {
			$resolved = self::invoke_resolver( $provider['credentials'], $provider );
			if ( is_array( $resolved ) ) {
				$r_id  = trim( (string) ( $resolved['client_id'] ?? '' ) );
				$r_sec = trim( (string) ( $resolved['client_secret'] ?? '' ) );
				if ( '' !== $r_id && '' !== $r_sec ) {
					$id  = $r_id;
					$sec = $r_sec;
					self::log( '[' . $provider['id'] . '] credentials resolved via provider resolver (matched pair)' );
				}
				if ( '' === $rt && ! empty( $resolved['refresh_token'] ) ) {
					$rt = trim( (string) $resolved['refresh_token'] );
				}
			}
		}

		return array( 'client_id' => $id, 'client_secret' => $sec, 'refresh_token' => $rt );
	}

	private static function invoke_resolver( $resolver, array $provider ) {
		if ( is_callable( $resolver ) ) {
			return call_user_func( $resolver, $provider );
		}
		if ( is_array( $resolver ) ) {
			return $resolver;
		}
		return null;
	}

	/**
	 * Redirect URIs to try on refresh: caller-supplied + provider-declared, then
	 * the `zdz_token_redirect_uris` filter (the app that owns an OAuth callback
	 * contributes its URI here rather than the core naming plugin pages).
	 *
	 * @return string[]
	 */
	private static function redirect_uris( array $provider, array $args ) {
		$uris = array();
		if ( ! empty( $args['redirect_uris'] ) && is_array( $args['redirect_uris'] ) ) {
			$uris = array_map( 'strval', $args['redirect_uris'] );
		}
		$declared = $provider['redirect_uris'] ?? array();
		if ( is_callable( $declared ) ) {
			$declared = call_user_func( $declared, $provider );
		}
		if ( is_array( $declared ) ) {
			$uris = array_merge( $uris, array_map( 'strval', $declared ) );
		}

		/**
		 * Filter the OAuth redirect URIs attempted for a provider.
		 *
		 * @param string[] $uris        Candidate redirect URIs.
		 * @param string   $provider_id The provider id.
		 */
		$uris = apply_filters( 'zdz_token_redirect_uris', $uris, $provider['id'] );

		if ( ! is_array( $uris ) ) {
			return array();
		}
		$uris = array_filter( $uris, static function ( $u ) {
			return '' !== $u;
		} );
		return array_values( array_unique( $uris ) );
	}

	private static function default_headers( array $provider ) {
		return ( 'form' === $provider['body_format'] )
			? array( 'Content-Type' => 'application/x-www-form-urlencoded' )
			: array( 'Content-Type' => 'application/json' );
	}

	/**
	 * First-call self-seed of a provider's canonical store from whichever of its
	 * legacy families already holds tokens (an in-place upgrade adoption; a
	 * no-op on a fresh install, where no legacy family exists).
	 */
	private static function seed_if_empty( array $provider ) {
		if ( '' !== (string) get_option( self::opt( $provider, 'refresh_token' ), '' ) ) {
			return;
		}
		foreach ( $provider['option_families'] as $fam ) {
			$rt = (string) get_option( $fam . 'refresh_token', '' );
			if ( '' !== $rt ) {
				update_option( self::opt( $provider, 'refresh_token' ), $rt, false );

				$at = (string) get_option( $fam . 'access_token', '' );
				if ( '' !== $at && '' === (string) get_option( self::opt( $provider, 'access_token' ), '' ) ) {
					update_option( self::opt( $provider, 'access_token' ), $at, false );
				}
				$acct = (string) get_option( $fam . 'account_id', '' );
				if ( '' !== $acct && '' === (string) get_option( self::opt( $provider, 'account_id' ), '' ) ) {
					update_option( self::opt( $provider, 'account_id' ), $acct, false );
				}
				if ( false === get_option( self::opt( $provider, 'version' ), false ) ) {
					update_option( self::opt( $provider, 'version' ), 0, false );
				}
				self::log( '[' . $provider['id'] . '] seeded canonical store from "' . rtrim( $fam, '_' ) . '" family' );
				return;
			}
		}
	}

	// ─────────────────────────────────────────────────────────────────
	// LOCKING — GET_LOCK primary, atomic options-row fallback (per provider)
	// ─────────────────────────────────────────────────────────────────

	private static function acquire_lock( array $provider ) {
		global $wpdb;

		$lock_name = 'zdz_tok_' . $provider['store_key'] . '_refresh.' . DB_NAME;
		$lock_row  = 'zdz_tok_' . $provider['store_key'] . '_lock_row';

		// Primary: MySQL advisory lock (session-scoped; auto-release on crash).
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );
		if ( '1' === (string) $got ) {
			self::$lock = array( 'mode' => 'mysql', 'name' => $lock_name, 'row' => $lock_row );
			return true;
		}
		if ( '0' === (string) $got ) {
			return false; // held by another worker — follower path
		}

		// GET_LOCK unavailable (NULL/err) → atomic options-row lock.
		self::log( '[' . $provider['id'] . '] GET_LOCK unavailable — using options-row lock fallback' );
		$now      = time();
		$inserted = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
			$lock_row, (string) $now
		) );
		if ( 1 === (int) $inserted ) {
			self::$lock = array( 'mode' => 'row', 'name' => $lock_name, 'row' => $lock_row );
			return true;
		}
		// Row exists — stale-break if older than LOCK_STALE_SEC.
		$val = $wpdb->get_var( $wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $lock_row
		) );
		if ( null !== $val && ( $now - (int) $val ) > self::LOCK_STALE_SEC ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $lock_row ) );
			$re = $wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$lock_row, (string) $now
			) );
			if ( 1 === (int) $re ) {
				self::log( '[' . $provider['id'] . '] options-row lock: broke stale lock (> ' . self::LOCK_STALE_SEC . 's) and re-acquired' );
				self::$lock = array( 'mode' => 'row', 'name' => $lock_name, 'row' => $lock_row );
				return true;
			}
		}
		return false;
	}

	private static function release_lock() {
		global $wpdb;
		if ( 'mysql' === self::$lock['mode'] ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::$lock['name'] ) );
		} elseif ( 'row' === self::$lock['mode'] ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::$lock['row'] ) );
		}
		self::$lock = array( 'mode' => '', 'name' => '', 'row' => '' );
	}

	// ─────────────────────────────────────────────────────────────────
	// BOOT: cron + admin notice + rename map + boot beacon
	// ─────────────────────────────────────────────────────────────────

	public static function boot() {
		// Boot beacon (once daily, admin only) — confirms the service is present.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! wp_doing_ajax() && ! wp_doing_cron() && is_admin() ) {
			self::log_once_daily( 'boot v' . self::VER . ' (theme core service present)' );
		}

		add_filter( 'cron_schedules', static function ( $s ) {
			if ( ! isset( $s['zdz_tok_15min'] ) ) {
				$s['zdz_tok_15min'] = array( 'interval' => 900, 'display' => __( 'Every 15 minutes (Zorderz Token Service)', 'zorderz' ) );
			}
			return $s;
		} );

		add_action( 'init', static function () {
			// Retire any predecessor cron hooks a provider declares (e.g. a
			// pre-Zorderz must-use plugin's hook), then ensure our single
			// 15-minute maintenance tick is scheduled.
			foreach ( self::providers() as $provider ) {
				foreach ( $provider['legacy_cron'] as $legacy_hook ) {
					if ( '' !== $legacy_hook && wp_next_scheduled( $legacy_hook ) ) {
						wp_clear_scheduled_hook( $legacy_hook );
					}
				}
			}
			if ( ! wp_next_scheduled( 'zdz_tok_cron' ) ) {
				wp_schedule_event( time() + 120, 'zdz_tok_15min', 'zdz_tok_cron' );
			}
		} );
		add_action( 'zdz_tok_cron', array( __CLASS__, 'cron_tick' ) );

		// Deprecated-alias migration: publish each provider's old canonical option
		// keys → the zdz_tok_<key>_* store, applied (copy-not-move) by the theme's
		// rename migration so a pre-Zorderz install upgrades in place.
		add_filter( 'zdz_rename_map', array( __CLASS__, 'rename_map' ) );

		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	/** Pre-expiry refresh for every registered provider: runs uncontended. */
	public static function cron_tick() {
		foreach ( self::providers() as $provider ) {
			if ( '' === $provider['token_url'] ) {
				continue;
			}
			self::seed_if_empty( $provider );
			if ( 'yes' === get_option( self::opt( $provider, 'reauth_needed' ), '' ) ) {
				continue; // needs a human; don't churn
			}
			$expires = (int) get_option( self::opt( $provider, 'expires_at' ), 0 );
			$access  = (string) get_option( self::opt( $provider, 'access_token' ), '' );
			if ( '' === $access || 0 === $expires ) {
				continue; // nothing maintained yet through the service
			}
			if ( ( $expires - time() ) > self::CRON_REFRESH_WINDOW ) {
				continue; // still fresh
			}
			self::log( '[' . $provider['id'] . '] cron: access token expires in ' . max( 0, $expires - time() ) . 's — proactive refresh' );
			self::refresh( array( 'provider' => $provider['id'] ) );
		}
	}

	/** Admin-only reconnect notice, per provider in the re-auth state. */
	public static function admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		foreach ( self::providers() as $provider ) {
			if ( 'yes' !== get_option( self::opt( $provider, 'reauth_needed' ), '' ) ) {
				continue;
			}
			echo '<div class="notice notice-error"><p><strong>'
				. esc_html( sprintf( __( '%s needs to be reconnected.', 'zorderz' ), $provider['label'] ) )
				. '</strong> '
				. esc_html__( 'The shared refresh token is no longer valid (Zorderz Token Service). Reconnect from the connected app\'s settings. Field users are not being prompted.', 'zorderz' )
				. '</p></div>';
		}
	}

	/**
	 * Contribute each provider's canonical-store option renames to the theme's
	 * rename map.
	 *
	 * @param array $map The rename map.
	 * @return array
	 */
	public static function rename_map( $map ) {
		if ( ! is_array( $map ) ) {
			return $map;
		}
		if ( ! isset( $map['options'] ) || ! is_array( $map['options'] ) ) {
			$map['options'] = array();
		}
		foreach ( self::providers() as $provider ) {
			foreach ( $provider['legacy_options'] as $legacy_prefix ) {
				$legacy_prefix = (string) $legacy_prefix;
				if ( '' === $legacy_prefix ) {
					continue;
				}
				foreach ( self::$store_suffixes as $suffix ) {
					$old = $legacy_prefix . $suffix;
					$new = self::opt( $provider, $suffix );
					if ( $old !== $new ) {
						$map['options'][ $old ] = $new;
					}
				}
			}
		}
		return $map;
	}

	// ─────────────────────────────────────────────────────────────────
	// TELEMETRY
	// ─────────────────────────────────────────────────────────────────

	private static function origin() {
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return 'cron';
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return 'ajax';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}
		return 'request';
	}

	private static function log( $msg ) {
		error_log( '[ZDZ_Token_Service] ' . $msg );
	}

	private static function log_once_daily( $msg ) {
		$k = 'zdz_tok_boot_' . gmdate( 'Ymd' );
		if ( ! get_transient( $k ) ) {
			set_transient( $k, 1, DAY_IN_SECONDS );
			self::log( $msg );
		}
	}
}

ZDZ_Token_Service::boot();

/*
 * Deprecated alias for a pre-Zorderz install upgrading in place. Any legacy
 * consumer still calling the old class name resolves to this service (the public
 * contract is identical: refresh()/get_refresh_token()/get_access_token()). Only
 * added when the legacy class is absent, so it never collides with a still-
 * present legacy must-use plugin.
 */
if ( ! class_exists( 'TS_Token_Service', false ) ) {
	class_alias( 'ZDZ_Token_Service', 'TS_Token_Service' );
}

/*
 * ── Bundled provider: FreshBooks ──────────────────────────────────────────
 * The reference registration. FreshBooks is ONE registered provider, not a core
 * assumption — everything specific to it lives in this descriptor. Any tenant
 * can replace or extend it through the same public `zdz_token_providers` filter.
 * (api.freshbooks.com is a third-party API host, not the platform's own domain.)
 */
if ( ! function_exists( 'zdz_register_freshbooks_token_provider' ) ) {
	function zdz_register_freshbooks_token_provider( $providers ) {
		if ( ! is_array( $providers ) ) {
			$providers = array();
		}
		$providers['freshbooks'] = array(
			'id'         => 'freshbooks',
			'label'      => 'FreshBooks',
			'default'    => true,                 // refresh() with no provider → here
			'store_key'  => 'fb',                 // canonical store → zdz_tok_fb_*
			'token_url'  => 'https://api.freshbooks.com/auth/oauth/token',
			'body_format'          => 'json',
			'request_headers'      => array( 'Api-Version' => 'alpha' ),
			'default_ttl'          => 43200,      // access tokens ~12h; used only if the response omits expires_in
			'plaintext_secret_max' => 100,        // a stored blob longer than this is encrypted, not the plaintext secret
			// Legacy families kept in sync so un-migrated readers keep working
			// until the Connections layer replaces projection with one encrypted
			// row. Projection only writes to families ALREADY present on the
			// install, so listing extras never seeds data.
			'option_families' => array(
				'zdz_core_fb_', 'zdz_surveys_fb_',
				'tsa_fb_', 'tsec_fb_', 'tscc_fb_', 'tsl_fb_', 'tsic_fb_',
				'ts_core_fb_', 'ts_surveys_fb_', 'tsemc_fb_', 'tser_fb_',
			),
			// Old canonical store prefix → migrated to zdz_tok_fb_* via zdz_rename_map.
			'legacy_options'  => array( 'ts_tok_fb_' ),
			// Predecessor maintenance cron hook to retire on upgrade.
			'legacy_cron'     => array( 'ts_tok_fb_cron' ),
			// The kernel resolves credentials from the platform's OWN store, never
			// by reaching into a plugin's admin class.
			'credentials'     => 'zdz_freshbooks_token_credentials',
			// A neutral base redirect. The app that owns the OAuth callback adds
			// its URI via the zdz_token_redirect_uris filter.
			'redirect_uris'   => array( admin_url( 'admin.php' ) ),
		);
		return $providers;
	}
}
add_filter( 'zdz_token_providers', 'zdz_register_freshbooks_token_provider' );

if ( ! function_exists( 'zdz_freshbooks_token_credentials' ) ) {
	/**
	 * FreshBooks credential resolver — reads the theme's own credential store.
	 *
	 * @return array{client_id:string,client_secret:string,refresh_token:string}
	 */
	function zdz_freshbooks_token_credentials( $provider ) {
		if ( ! class_exists( 'ZDZ_Core_Settings' ) ) {
			return array();
		}
		return array(
			'client_id'     => ZDZ_Core_Settings::get_fb_client_id(),
			'client_secret' => ZDZ_Core_Settings::get_fb_client_secret(),
			'refresh_token' => ZDZ_Core_Settings::get_fb_refresh_token(),
		);
	}
}

endif;
