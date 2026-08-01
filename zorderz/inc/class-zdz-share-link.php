<?php
/**
 * ZDZ_Share_Link — reusable "secret share-link" primitives (theme pass, item #3).
 *
 * A small, dependency-free toolkit for serving a private artifact (a file, a CPT,
 * a record) behind an UNGUESSABLE, capability-style link instead of an enumerable
 * public URL. It is the extracted, shared core of the same pattern already shipped
 * in three places, each of which stays as-is:
 *
 *   - zdz-receipts           — human-typeable 4-word token (printed on receipts)
 *   - zdz-internal-messaging — 128-bit opaque token in postmeta (chat attachments)
 *   - zdz-theme-2 ZDZ_User_Media — 128-bit opaque token in a share_token column (media)
 *
 * This class exists so the NEXT artifact that needs a gated link doesn't hand-roll
 * the mint / constant-time verify / 404-on-miss / no-index headers / rate-limit
 * again. It deliberately provides only the primitives; the caller owns storage
 * (which column / meta key the token lives in) and routing (its own rewrite rule).
 *
 * ── DOMAIN SEPARATION (required) ─────────────────────────────────────────────
 * Every method that derives or checks a token takes a $namespace string (e.g.
 * 'receipt', 'chat-attach', 'user-media'). Namespaces NEVER share secret material:
 *
 *   - Random tokens (mint_opaque / mint_words) are independent CSPRNG draws — a
 *     leak of one record's token tells you nothing about another, in any namespace.
 *   - The OPTIONAL stateless-HMAC helpers (sign / verify_signed) derive a per-
 *     namespace key from the site's auth salt + the namespace, so a signed link
 *     minted for 'receipt' can never validate as 'user-media' even for the same id.
 *
 * So a break in one application's links never extends to another — matching CT's
 * standing instruction ("some slight difference, so if one got cracked in the
 * receipt app it wouldn't automatically extend to this other set of places").
 *
 * ── THREAT MODEL ─────────────────────────────────────────────────────────────
 * The link is matched to the ONLINE (rate-limitable HTTP) threat, not offline
 * crypto. 128-bit opaque tokens are far past any online brute force; the 4-word
 * option (~41 bits) is human-usable and, WITH the built-in per-IP rate-limit,
 * takes ~years to hit a specific link. Callers that use the low-entropy word
 * option on a sensitive artifact SHOULD gate the route with rate_ok().
 *
 * @package Zorderz
 * @since   v2.35.0 (theme pass item #3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Share_Link {

	/** Bytes of entropy for an opaque token (16 = 128-bit). */
	const OPAQUE_BYTES = 16;

	/** Default per-IP attempt budget for a token route (per window). */
	const RATE_LIMIT = 30;

	/** Rate-limit window, seconds. */
	const RATE_WINDOW = 60;

	/**
	 * Mint an opaque, unguessable token (default shape: 128-bit hex).
	 *
	 * Independent CSPRNG draw — no namespace secret is involved, so the token is
	 * safe to store and compare across any namespace without cross-leak. Store it
	 * on the record and compare later with is_valid_opaque().
	 *
	 * @param int $bytes Entropy in bytes (min 16). 16 → 32 hex chars / 128 bit.
	 * @return string Lowercase hex, or '' if the platform CSPRNG is unavailable.
	 */
	public static function mint_opaque( int $bytes = self::OPAQUE_BYTES ): string {
		$bytes = max( self::OPAQUE_BYTES, $bytes );
		try {
			return bin2hex( random_bytes( $bytes ) );
		} catch ( \Exception $e ) {
			return ''; // No CSPRNG → refuse rather than mint a weak token.
		}
	}

	/**
	 * Constant-time compare of a provided opaque token against the stored one.
	 *
	 * @param string $stored   The token persisted on the record ('' = unminted).
	 * @param string $provided The token from the request.
	 * @return bool True only if both are non-empty and equal (timing-safe).
	 */
	public static function is_valid_opaque( string $stored, string $provided ): bool {
		if ( '' === $stored || '' === $provided ) {
			return false;
		}
		return hash_equals( $stored, $provided );
	}

	/**
	 * Mint a human-typeable multi-word token from a caller-supplied word list.
	 *
	 * For artifacts a person must read or say (printed receipts, phoned-out links).
	 * The caller owns the word list AND its quality — pass a cleaned list of short,
	 * unambiguous words. Entropy ≈ words * log2(count(list)); with a ~1288-word
	 * list, 4 words ≈ 41 bits. Refuses (returns '') below the entropy floor so a
	 * corrupt/short list can't silently produce a weak token.
	 *
	 * @param array $wordlist   Cleaned candidate words (deduped, lowercase a-z).
	 * @param int   $words      How many words (min 4).
	 * @param int   $min_list   Entropy floor: refuse if the list is smaller (default 1000).
	 * @return string Hyphen-joined token (e.g. "maple-otter-canyon-drift"), or ''.
	 */
	public static function mint_words( array $wordlist, int $words = 4, int $min_list = 1000 ): string {
		$wordlist = array_values( array_unique( array_filter(
			$wordlist,
			static function ( $w ) { return is_string( $w ) && preg_match( '/^[a-z]{3,8}$/', $w ); }
		) ) );
		$n = count( $wordlist );
		if ( $n < $min_list ) {
			return ''; // Entropy floor not met → caller must not publish.
		}
		$words = max( 4, $words );
		$picked = array();
		try {
			for ( $i = 0; $i < $words; $i++ ) {
				$picked[] = $wordlist[ random_int( 0, $n - 1 ) ];
			}
		} catch ( \Exception $e ) {
			return '';
		}
		return implode( '-', $picked );
	}

	/**
	 * Normalize a word/opaque token from a URL to its canonical stored form.
	 *
	 * Lowercase, trim, collapse whitespace/underscores to single hyphens, drop
	 * anything outside [a-z0-9-], and squeeze repeated hyphens. Apply this to BOTH
	 * the incoming request token and (once, at mint) the stored token so the
	 * constant-time compare is apples-to-apples.
	 *
	 * @param string $token Raw token from the request.
	 * @return string Canonical token.
	 */
	public static function normalize( string $token ): string {
		$token = strtolower( trim( $token ) );
		$token = preg_replace( '/[\s_]+/', '-', $token );
		$token = preg_replace( '/[^a-z0-9-]/', '', (string) $token );
		$token = preg_replace( '/-+/', '-', (string) $token );
		return trim( (string) $token, '-' );
	}

	/**
	 * OPTIONAL stateless signature: derive a per-namespace token for an id without
	 * storing anything. Use when you'd rather not add a column/meta — the link is
	 * self-verifying. NOT revocable without rotating the namespace (see below).
	 *
	 * key = HMAC-SHA256( "zdz-share:{namespace}", wp_salt('auth') )   [per namespace]
	 * sig = substr( HMAC-SHA256( "{namespace}:{id}", key ), 0, 32 )  [128-bit, hex]
	 *
	 * Because the key is bound to the namespace, a signature minted for one
	 * namespace can never validate under another — domain separation holds even
	 * though no random token is stored.
	 *
	 * @param string $namespace App/scope id, e.g. 'receipt'.
	 * @param int    $id        The record id being linked.
	 * @param string $salt_key  Which wp_salt() bucket to bind to (default 'auth').
	 * @return string 32-char hex signature (128-bit).
	 */
	public static function sign( string $namespace, int $id, string $salt_key = 'auth' ): string {
		$key = hash_hmac( 'sha256', 'zdz-share:' . $namespace, wp_salt( $salt_key ) );
		return substr( hash_hmac( 'sha256', $namespace . ':' . (int) $id, $key ), 0, 32 );
	}

	/**
	 * Verify a stateless signature (constant-time) for an id in a namespace.
	 *
	 * @param string $namespace App/scope id — MUST match what sign() used.
	 * @param int    $id        The record id.
	 * @param string $provided  The signature from the request.
	 * @param string $salt_key  Salt bucket used at sign time.
	 * @return bool
	 */
	public static function verify_signed( string $namespace, int $id, string $provided, string $salt_key = 'auth' ): bool {
		$provided = self::normalize( $provided );
		if ( '' === $provided ) {
			return false;
		}
		return hash_equals( self::sign( $namespace, $id, $salt_key ), $provided );
	}

	/**
	 * Per-IP rate-limit for a token route. Fail-OPEN (never lock out real users
	 * because the object cache is unavailable) but throttle brute force.
	 *
	 * Call once at the top of a token-serving handler; if it returns false, emit
	 * a 404 (not 429 — don't confirm the route exists) via not_found().
	 *
	 * @param string $namespace App/scope id (keys the counter, so apps don't share a budget).
	 * @param int    $limit     Attempts allowed per window (default 30; filterable).
	 * @param int    $window    Window seconds (default 60).
	 * @return bool True if under the limit (allowed).
	 */
	public static function rate_ok( string $namespace, int $limit = self::RATE_LIMIT, int $window = self::RATE_WINDOW ): bool {
		$limit = (int) apply_filters( 'zdz_share_link_rate_limit', $limit, $namespace );
		if ( $limit <= 0 ) {
			return true; // Disabled by filter.
		}
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^0-9a-f:.]/i', '', (string) $_SERVER['REMOTE_ADDR'] ) : 'na';
		$key = 'zdz_share_rl_' . md5( $namespace . '|' . $ip );
		$hits = (int) get_transient( $key );
		if ( $hits >= $limit ) {
			return false;
		}
		set_transient( $key, $hits + 1, $window );
		return true;
	}

	/**
	 * Emit the private, search-engine-invisible response headers for a served
	 * artifact. Layered no-index (header here + the caller should also add a
	 * <meta robots> when it renders HTML), no referrer leak, and no shared-cache
	 * storage. Call right before streaming/echoing the private content.
	 *
	 * @return void
	 */
	public static function send_private_headers(): void {
		if ( headers_sent() ) {
			return;
		}
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Referrer-Policy: no-referrer', true );
		header( 'X-Content-Type-Options: nosniff', true );
		header( 'Cache-Control: private, no-store, max-age=0', true );
	}

	/**
	 * Terminal 404 for a token miss. Returns a real "not found" (never 403) so a
	 * probe can't distinguish "wrong token" from "no such link" — non-enumerable.
	 * Sets the query 404 flag so a normal theme 404 renders, tags it noindex, exits.
	 *
	 * @param bool $render_theme_404 Load the theme's 404 template (default true).
	 * @return void  Does not return (exits).
	 */
	public static function not_found( bool $render_theme_404 = true ): void {
		if ( ! headers_sent() ) {
			status_header( 404 );
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
		if ( $render_theme_404 && ! empty( $GLOBALS['wp_query'] ) && is_object( $GLOBALS['wp_query'] ) ) {
			$GLOBALS['wp_query']->set_404();
			$tpl = get_query_template( '404' );
			if ( $tpl ) {
				include $tpl;
			}
		}
		exit;
	}
}
