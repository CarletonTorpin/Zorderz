<?php
/**
 * ZDZ_Geocoder — privacy-first FORWARD geocoder (address -> coordinates) and the
 * single, shared address CACHE-KEY normalization contract.
 *
 * Companion to the reverse-only ZDZ_Media_Geocoder (GPS -> place name). This class
 * is the address -> anchor step that the media-set location classifier
 * (ZDZ_Media_Location) needs, and it OWNS the cache-key contract that the jobs
 * Project path and the estimate finalize path reuse so an address is resolved at
 * most once across the whole platform.
 *
 * ── PRIVACY / GEO-PII POSTURE (load-bearing) ─────────────────────────────────
 *   - The COORDINATES this class returns are SERVER-SIDE ONLY. They feed
 *     ZDZ_Media_Location::classify() as an anchor and must NEVER be serialized to
 *     a client, a public receipt page, or any AJAX payload. Only a *categorical*
 *     location status may cross that boundary (see ZDZ_Media_Location).
 *   - OFFLINE / OFF BY DEFAULT. Forward-geocoding a typed address generally needs
 *     a network call — a privacy cost the platform deliberately avoids by default.
 *     Core ships NO API key and makes NO network call. With nothing configured,
 *     resolve_address() returns null.
 *   - IDENTITY-CONFIGURABLE. A tenant opts in to networked resolution and names a
 *     provider ENDPOINT + a `secret://` credential reference through the
 *     `connections` Identity pack (resolved via filters below). No provider name
 *     is compiled into Core.
 *   - RESOLVE ONCE. Results are memoized per-request and (when WP is present)
 *     cached as a transient keyed off address_cache_key(), so all three call
 *     sites hit one entry.
 *
 * Returned shape (or null if nothing could be resolved):
 *   [ 'lat' => float, 'lng' => float, 'provider' => string,
 *     'resolved_at' => 'Y-m-d H:i:s' (UTC), 'precision' => string ]
 *
 * @since   1.7.0
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Geocoder {

	/** Cache option/transient prefix. Storage name = PREFIX . md5( cache-key ). */
	const CACHE_PREFIX = 'zdz_geo_addr_';

	/**
	 * Canonical address field names for address_cache_key(). Consumers should
	 * pass these keys; a small set of common aliases is also accepted so a call
	 * site that already has 'postcode' or 'line1' still hashes to the same entry.
	 *
	 * @var array<string,array<int,string>>
	 */
	private static $field_aliases = array(
		'street'  => array( 'street', 'street1', 'address', 'address1', 'addr', 'line1' ),
		'city'    => array( 'city', 'town', 'locality' ),
		'state'   => array( 'state', 'region', 'admin1', 'province' ),
		'zip'     => array( 'zip', 'zipcode', 'postal', 'postcode', 'postal_code' ),
		'country' => array( 'country' ),
	);

	/** @var array<string,array> In-process "resolve once" cache, keyed by cache-key. */
	private static $mem = array();

	/**
	 * Resolve an address to coordinates. SERVER-SIDE ONLY — never hand the return
	 * value to a client (see the class docblock).
	 *
	 * @param string|array $address A canonical field array (see $field_aliases) or
	 *                              a free-form address string.
	 * @param array        $opts    Optional. Adapter/context. `transport` may carry
	 *                              a callable( string $url, string $key, array $opts )
	 *                              for the networked path (adapter/test seam).
	 * @return array|null The result shape, or null when nothing resolved.
	 */
	public static function resolve_address( $address, array $opts = array() ): ?array {
		$key = is_array( $address )
			? self::address_cache_key( $address )
			: self::normalize_string( (string) $address );

		if ( '' === $key ) {
			return null;
		}

		// RESOLVE ONCE — memo + transient.
		$cached = self::cache_get( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		/**
		 * Filter: zdz_media_forward_geocode
		 *
		 * Return a result array (see class docblock; at minimum numeric lat+lng)
		 * to supply coordinates for the normalized address. This is where an
		 * operator wires a resolver they trust (self-hosted, offline, or a keyed
		 * commercial API they have accepted the privacy cost of). Return
		 * null/false to fall through. Parallel to the reverse seam
		 * `zdz_media_reverse_geocode` in ZDZ_Media_Geocoder.
		 *
		 * @param null|array $pre  Short-circuit value (null by default).
		 * @param string     $key  The normalized cache-key address string.
		 * @param array      $opts Caller context.
		 */
		$pre    = self::filter( 'zdz_media_forward_geocode', null, $key, $opts );
		$result = self::shape_result( $pre, 'filter' );

		// Core networked provider — OFF by default; provider + secret are Identity
		// config. Core ships the gate false AND no endpoint, so this stays dead.
		if ( null === $result && self::networked_enabled() ) {
			$result = self::resolve_networked( $key, $opts );
		}

		if ( null === $result ) {
			return null;
		}

		self::cache_set( $key, $result );
		return $result;
	}

	/**
	 * THE shared cache-key normalization contract. Compose "street, city, state
	 * zip" and normalize (lower-cased, trimmed, single-spaced, comma-tidied) so
	 * every call site that describes the same address produces a byte-identical
	 * key — and therefore one cache entry. This is the single source; the jobs
	 * Project path and the estimate finalize path call it rather than re-deriving
	 * the string.
	 *
	 * @param array $addr Canonical field array (see $field_aliases).
	 * @return string The normalized key ('' when no usable field was given).
	 */
	public static function address_cache_key( array $addr ): string {
		$street  = self::field( $addr, 'street' );
		$city    = self::field( $addr, 'city' );
		$state   = self::field( $addr, 'state' );
		$zip     = self::field( $addr, 'zip' );
		$country = self::field( $addr, 'country' );

		$primary = array();
		if ( '' !== $street ) {
			$primary[] = $street;
		}
		if ( '' !== $city ) {
			$primary[] = $city;
		}
		$line = implode( ', ', $primary );

		$tail = trim( $state . ( '' !== $zip ? ' ' . $zip : '' ) );

		if ( '' !== $line && '' !== $tail ) {
			$composed = $line . ', ' . $tail;
		} else {
			$composed = '' !== $line ? $line : $tail;
		}

		// Country is appended only when a caller explicitly supplies it, so the
		// common "street, city, state zip" form stays stable across call sites.
		if ( '' !== $country ) {
			$composed = '' !== $composed ? $composed . ', ' . $country : $country;
		}

		return self::normalize_string( $composed );
	}

	/**
	 * The storage name for a cache-key. Consumers rarely need this; resolve_address
	 * uses it internally. Exposed so a maintenance tool can target an entry.
	 */
	public static function cache_option_name( string $key ): string {
		return self::CACHE_PREFIX . md5( $key );
	}

	/**
	 * Normalize any address string to the canonical key form: lower-cased,
	 * whitespace collapsed to single spaces, trimmed, and comma separators tidied
	 * to ", ". Free-form "123 X St, Y, ZZ 00000" and a composed field array both
	 * converge here.
	 */
	public static function normalize_string( string $s ): string {
		$s = trim( $s );
		if ( '' === $s ) {
			return '';
		}
		if ( function_exists( 'mb_strtolower' ) ) {
			$s = mb_strtolower( $s, 'UTF-8' );
		} else {
			$s = strtolower( $s );
		}
		$s = preg_replace( '/\s+/', ' ', $s );        // collapse whitespace runs
		$s = preg_replace( '/\s*,\s*/', ', ', $s );   // tidy comma separators
		return trim( (string) $s, " \t\n\r\0\x0B," );
	}

	/* ───────────────────────── Networked provider ───────────────────────── */

	/**
	 * Whether networked forward geocoding is permitted at all. Core default false.
	 */
	private static function networked_enabled(): bool {
		return (bool) self::filter( 'zdz_media_networked_geocode_enabled', false );
	}

	/**
	 * Provider-agnostic networked resolve. Reads a provider ENDPOINT template and a
	 * `secret://` credential reference from Identity config (via filters an
	 * Identity `connections` pack drives). Core ships BOTH empty, so this returns
	 * null and makes NO network call. No provider name is compiled in.
	 */
	private static function resolve_networked( string $key, array $opts ): ?array {
		$endpoint = (string) self::filter( 'zdz_media_geocode_endpoint', '' );
		if ( '' === $endpoint ) {
			return null; // no endpoint configured -> never call out.
		}

		$secret_ref = (string) self::filter( 'zdz_media_geocode_secret_ref', '' );
		$secret     = ( '' !== $secret_ref )
			? (string) self::filter( 'zdz_connection_secret', '', $secret_ref )
			: '';

		$url = self::build_endpoint_url( $endpoint, $key, $secret );

		// Adapter/test seam: an explicit transport overrides WP HTTP.
		$transport = $opts['transport'] ?? null;
		if ( is_callable( $transport ) ) {
			$raw = $transport( $url, $key, $opts );
		} elseif ( function_exists( 'wp_remote_get' ) && function_exists( 'wp_remote_retrieve_body' ) ) {
			$resp = wp_remote_get( $url, array( 'timeout' => 8 ) );
			if ( function_exists( 'is_wp_error' ) && is_wp_error( $resp ) ) {
				return null;
			}
			$raw = wp_remote_retrieve_body( $resp );
		} else {
			return null; // no transport available.
		}

		/**
		 * Filter: zdz_media_geocode_parse_response
		 * Map a provider's raw response body to the result shape (lat/lng at
		 * minimum). Identity `mappings`/`connections` supplies this per provider.
		 */
		$parsed = self::filter( 'zdz_media_geocode_parse_response', null, $raw, $key );
		return self::shape_result( $parsed, 'networked' );
	}

	/**
	 * Fill an endpoint template. Supports {query}/{key}/{secret} placeholders;
	 * with no placeholder, appends ?q=<key>. All values are rawurlencoded.
	 */
	private static function build_endpoint_url( string $endpoint, string $key, string $secret ): string {
		if ( false !== strpos( $endpoint, '{query}' ) || false !== strpos( $endpoint, '{key}' ) || false !== strpos( $endpoint, '{secret}' ) ) {
			return strtr(
				$endpoint,
				array(
					'{query}'  => rawurlencode( $key ),
					'{key}'    => rawurlencode( $key ),
					'{secret}' => rawurlencode( $secret ),
				)
			);
		}
		$sep = ( false === strpos( $endpoint, '?' ) ) ? '?' : '&';
		return $endpoint . $sep . 'q=' . rawurlencode( $key );
	}

	/* ───────────────────────── Shaping / validation ───────────────────────── */

	/**
	 * Coerce a resolver's raw result into the canonical shape, or null if it does
	 * not carry a valid coordinate.
	 */
	private static function shape_result( $raw, string $default_provider ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$lat = self::num( $raw, array( 'lat', 'latitude' ) );
		$lng = self::num( $raw, array( 'lng', 'lon', 'long', 'longitude' ) );
		if ( null === $lat || null === $lng || ! self::valid_coord( $lat, $lng ) ) {
			return null;
		}
		return array(
			'lat'         => $lat,
			'lng'         => $lng,
			'provider'    => ! empty( $raw['provider'] ) ? (string) $raw['provider'] : $default_provider,
			'resolved_at' => ! empty( $raw['resolved_at'] ) ? (string) $raw['resolved_at'] : gmdate( 'Y-m-d H:i:s' ),
			'precision'   => ! empty( $raw['precision'] ) ? (string) $raw['precision'] : 'unknown',
		);
	}

	private static function valid_coord( float $lat, float $lng ): bool {
		if ( $lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0 ) {
			return false;
		}
		// Null island (0,0) is almost always a parse bug, never a real address.
		if ( 0.0 === $lat && 0.0 === $lng ) {
			return false;
		}
		return true;
	}

	private static function num( array $arr, array $keys ): ?float {
		foreach ( $keys as $k ) {
			if ( isset( $arr[ $k ] ) && is_numeric( $arr[ $k ] ) ) {
				return (float) $arr[ $k ];
			}
		}
		return null;
	}

	/** Resolve one canonical field from an array honoring the alias list. */
	private static function field( array $addr, string $canonical ): string {
		$aliases = self::$field_aliases[ $canonical ] ?? array( $canonical );
		foreach ( $aliases as $k ) {
			if ( isset( $addr[ $k ] ) && is_scalar( $addr[ $k ] ) && '' !== trim( (string) $addr[ $k ] ) ) {
				return trim( (string) $addr[ $k ] );
			}
		}
		return '';
	}

	/* ───────────────────────── Cache ───────────────────────── */

	private static function cache_get( string $key ) {
		if ( isset( self::$mem[ $key ] ) ) {
			return self::$mem[ $key ];
		}
		if ( function_exists( 'get_transient' ) ) {
			$v = get_transient( self::cache_option_name( $key ) );
			if ( is_array( $v ) ) {
				self::$mem[ $key ] = $v;
				return $v;
			}
		}
		return null;
	}

	private static function cache_set( string $key, array $result ): void {
		self::$mem[ $key ] = $result;
		if ( function_exists( 'set_transient' ) ) {
			$ttl = (int) self::filter( 'zdz_media_geocode_cache_ttl', defined( 'WEEK_IN_SECONDS' ) ? WEEK_IN_SECONDS : 604800 );
			set_transient( self::cache_option_name( $key ), $result, $ttl );
		}
	}

	/* ───────────────────────── Helpers ───────────────────────── */

	/** apply_filters wrapper that degrades to the default when WP is absent. */
	private static function filter( string $tag, $default, ...$args ) {
		if ( function_exists( 'apply_filters' ) ) {
			return apply_filters( $tag, $default, ...$args );
		}
		return $default;
	}
}
