<?php
/**
 * Shared Review Bridge Client
 *
 * Calls the TS Review Bridge REST API on the marketing site to check whether
 * a customer left a Thrive Ovation testimonial. This is a read-only client —
 * it never writes to either site's database.
 *
 * Pattern: mirrors ZDZ_Core_FreshBooks / ZDZ_Core_Nutshell. Any plugin on
 * this site can call ZDZ_Core_ReviewBridge methods without knowing
 * anything about the remote API contract.
 *
 * Caching: individual lookups are cached for 15 minutes per email hash.
 * The overall Ovation testimonial count is cached for 1 hour.
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

	/** @var string API key sent in X-TS-Bridge-Key header */
	private string $api_key;

	/** @var int Per-email cache TTL in seconds (15 minutes) */
	private int $cache_ttl = 900;

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
			'headers' => [
				'X-TS-Bridge-Key' => $this->api_key,
			],
			'timeout' => 10,
		] );

		if ( is_wp_error( $response ) ) {
			error_log( 'TS Review Bridge Error: ' . $response->get_error_message() );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$err  = $body['code'] ?? 'unknown';
			error_log( "TS Review Bridge HTTP {$code}: {$err}" );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['found'] ) ) {
			error_log( 'TS Review Bridge: unexpected response format' );
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
