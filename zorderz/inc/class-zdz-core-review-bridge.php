<?php
/**
 * Shared Review Bridge Client
 *
 * Calls the Zorderz Review Bridge REST API on the marketing site to check whether
 * a customer left a review (a testimonial published on the website). This is a
 * read-only client — it never writes to either site's database.
 *
 * The key travels in the X-Review-Bridge-Key header (KEY_HEADER). A website whose
 * bridge plugin expects another header name is answered with the
 * `zdz_review_bridge_header` filter (header_name()).
 *
 * Pattern: mirrors ZDZ_Core_FreshBooks / ZDZ_Core_Nutshell. Any plugin on
 * this site can call ZDZ_Core_ReviewBridge methods without knowing
 * anything about the remote API contract.
 *
 * Caching: individual lookups are cached for 15 minutes per email hash.
 * The published review count (count()) is cached for 1 hour.
 *
 * @since  2.14.5
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Core_ReviewBridge {

	/** @var string Remote bridge base URL (e.g. https://zorderz.org/wp-json/zdz-review-bridge/v1) */
	private string $bridge_url;

	/** The header the bridge key travels in, unless the zdz_review_bridge_header filter names another. */
	const KEY_HEADER = 'X-Review-Bridge-Key';

	/** @var string API key sent in the key header (header_name()) */
	private string $api_key;

	/** @var int Per-email cache TTL in seconds (15 minutes) */
	private int $cache_ttl = 900;

	/** Injected in tests ("now" as a unix time, for count()'s asked_at). */
	public static $now = null;

	/* ------------------------------------------------------------------ */
	/*  Constructor                                                        */
	/* ------------------------------------------------------------------ */

	public function __construct() {
		if ( class_exists( 'ZDZ_Core_Settings' ) ) {
			$this->bridge_url = rtrim( ZDZ_Core_Settings::get_review_bridge_url(), '/' );
			$this->api_key    = ZDZ_Core_Settings::get_review_bridge_key();
		} else {
			$this->bridge_url = '';
			$this->api_key    = '';
		}
	}

	/* ------------------------------------------------------------------ */
	/*  Public API                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Check whether a customer left a review.
	 *
	 * @param string $email Customer email (required).
	 * @param string $name  Customer name (optional fallback).
	 * @param bool   $bypass_cache Skip transient cache and hit the remote API.
	 * @return array|null  { found: bool, rating: int|null, date: string|null, snippet: string|null }
	 *                     Returns null on network error or misconfiguration.
	 */
	public function check_review( string $email, string $name = '', bool $bypass_cache = false ): ?array {
		if ( ! $this->is_configured() ) {
			return null;
		}

		$email = sanitize_email( $email );
		if ( empty( $email ) ) {
			return null;
		}

		// ── Cache check ──
		$cache_key = 'zdz_rb_' . md5( strtolower( $email ) );

		if ( ! $bypass_cache ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		// ── Remote API call ──
		$url = add_query_arg( [
			'email' => rawurlencode( $email ),
			'name'  => rawurlencode( sanitize_text_field( $name ) ),
		], $this->bridge_url . '/check' );

		$response = wp_remote_get( $url, [
			'headers' => $this->auth_headers(),
			'timeout' => 10,
		] );

		if ( is_wp_error( $response ) ) {
			error_log( 'Zorderz Review Bridge Error: ' . $response->get_error_message() );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$err  = $body['code'] ?? 'unknown';
			error_log( "Zorderz Review Bridge HTTP {$code}: {$err}" );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['found'] ) ) {
			error_log( 'Zorderz Review Bridge: unexpected response format' );
			return null;
		}

		// ── Cache the result ──
		set_transient( $cache_key, $data, $this->cache_ttl );

		return $data;
	}

	/**
	 * Batch-check multiple customers.
	 *
	 * Useful for Surveys plugin cron: check all "emailed" leads at once.
	 * Respects rate limits by sleeping between calls if needed.
	 *
	 * @param array $customers Array of [ 'email' => string, 'name' => string ] entries.
	 * @param bool  $bypass_cache Skip transient cache for all lookups.
	 * @return array Keyed by email => result array (or null on error).
	 */
	public function batch_check( array $customers, bool $bypass_cache = false ): array {
		$results = [];

		foreach ( $customers as $i => $customer ) {
			$email = $customer['email'] ?? '';
			$name  = $customer['name']  ?? '';

			if ( empty( $email ) ) {
				continue;
			}

			$results[ $email ] = $this->check_review( $email, $name, $bypass_cache );

			// Rate-limit safety: 60 req/min = ~1/sec. Sleep every 50 to
			// stay well under the bridge's limit.
			if ( ( $i + 1 ) % 50 === 0 ) {
				sleep( 2 );
			}
		}

		return $results;
	}

	/**
	 * The name of the header the bridge key travels in: KEY_HEADER, or the name the zdz_review_bridge_header filter
	 * gives, for a website whose bridge plugin checks another one. Only a plain header name (a letter, then letters,
	 * digits and dashes, at most 64) is taken; anything else falls back to KEY_HEADER, so a filter can never smuggle a
	 * second header or a line break into the request.
	 */
	public static function header_name(): string {
		$name = function_exists( 'apply_filters' ) ? apply_filters( 'zdz_review_bridge_header', self::KEY_HEADER ) : self::KEY_HEADER;
		return ( is_string( $name ) && 1 === preg_match( '/^[A-Za-z][A-Za-z0-9-]{0,63}$/D', $name ) ) ? $name : self::KEY_HEADER;
	}

	/** The bridge's key header, as header_name() names it. */
	private function auth_headers(): array {
		return array( self::header_name() => $this->api_key );
	}

	/**
	 * How many reviews the website has published (the bridge's GET /count, with the same key as /check). Kept an
	 * hour; a failure is kept five minutes, so the bridge's rate limit (shared with /check, per calling address) is
	 * never spent on retries. A failure is null, never 0.
	 *
	 * @return array|null { published:int, total:int|null, newest:string|null, asked_at:int (unix, when the site was asked) }
	 */
	public function count( bool $bypass_cache = false ): ?array {
		if ( ! $this->is_configured() ) {
			return null;
		}
		$key = 'zdz_rb_count_v1';
		if ( ! $bypass_cache ) {
			$hit = get_transient( $key );
			if ( is_array( $hit ) ) {
				return $hit;
			}
			if ( get_transient( $key . '_fail' ) ) {
				return null;
			}
		}
		$response = wp_remote_get( $this->bridge_url . '/count', array(
			'headers' => $this->auth_headers(),
			'timeout' => 4, // asked inside a person's home-screen request, at most once an hour
		) );
		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$data = 200 === $code ? json_decode( (string) wp_remote_retrieve_body( $response ), true ) : null;
		if ( ! is_array( $data ) || ! isset( $data['published'] ) || ! is_numeric( $data['published'] ) || (int) $data['published'] < 0 ) {
			// 404 = a bridge without /count; 401 = the key; 429 = the rate limit; 500 = its own failed read
			error_log( 'Zorderz Review Bridge /count: ' . ( is_wp_error( $response ) ? 'request failed' : 'HTTP ' . $code ) . '.' );
			set_transient( $key . '_fail', 1, 300 );
			return null;
		}
		$out = array(
			'published' => (int) $data['published'],
			'total'     => isset( $data['total'] ) && is_numeric( $data['total'] ) ? (int) $data['total'] : null,
			'newest'    => isset( $data['newest'] ) && is_string( $data['newest'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $data['newest'] ) ? $data['newest'] : null,
			'asked_at'  => null !== self::$now ? (int) self::$now : time(),
		);
		set_transient( $key, $out, HOUR_IN_SECONDS );
		delete_transient( $key . '_fail' );
		return $out;
	}

	/**
	 * Clear the cached result for a specific email.
	 *
	 * Useful after a Surveys plugin knows a review link was just sent —
	 * the next check should hit the live API.
	 *
	 * @param string $email
	 */
	public function clear_cache( string $email ): void {
		$cache_key = 'zdz_rb_' . md5( strtolower( sanitize_email( $email ) ) );
		delete_transient( $cache_key );
	}

	/**
	 * Check whether the bridge is properly configured.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return ! empty( $this->bridge_url ) && ! empty( $this->api_key );
	}

	/**
	 * Get the configured bridge URL (for diagnostic display).
	 *
	 * @return string
	 */
	public function get_bridge_url(): string {
		return $this->bridge_url;
	}
}
