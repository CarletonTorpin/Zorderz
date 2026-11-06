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
 *   - RESOLVE ONCE, REMEMBER FOREVER. Buildings do not move. Results are
 *     memoized per-request and kept in the permanent wp_zdz_places table keyed
 *     off address_cache_key() (a transient when the table is not there yet), so
 *     every call site hits one entry. A FAILED networked lookup is remembered
 *     too, with an attempt counter and a backoff (1 h, 6 h, 24 h, then never),
 *     so an address the provider cannot place is not re-sent on every pass.
 *   - NEVER ON A PAGE A PERSON IS WAITING FOR, unless asked: lookup() is
 *     cache-only; resolve() / resolve_address() may go to the provider; a
 *     background pass uses resolve_batch() with a cap on calls.
 *   - GEOMETRY. distance_m() and same_property() give one answer, platform-wide,
 *     to "was this photo taken at this address": within SAME_PROPERTY_M (120 m,
 *     the `zdz_geocoder_same_property_m` filter) is a match, within three times
 *     that is nearby, and a phone fix vaguer than USABLE_ACCURACY_M is
 *     too_vague rather than a confident wrong answer.
 *
 * WHAT A BUSINESS CONFIGURES (never Core): which provider (endpoint template,
 * `secret://` key reference, the parse filter or one of the common response
 * shapes parse_common() understands), the provider's label, and any address
 * abbreviations its region writes two ways ("Street"/"St") on the
 * `zdz_geocoder_address_abbreviations` filter. Core ships none of them, so the
 * shared cache key stays byte-for-byte what every caller already computes.
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

	/** The permanent cache table, without the prefix. */
	const TABLE = 'zdz_places';

	/** Bumped when the table shape changes. Read by db/migrate-places.php. */
	const SCHEMA_VERSION = 1;

	/** Option holding the applied schema version. */
	const SCHEMA_OPTION = 'zdz_places_schema';

	/** How close two points must be to be the same property, in metres (filterable). */
	const SAME_PROPERTY_M = 120.0;

	/** A phone GPS fix worse than this, in metres, is too vague to place a photo on a house. */
	const USABLE_ACCURACY_M = 75.0;

	/** Network timeout for one provider call, in seconds. */
	const HTTP_TIMEOUT = 6;

	/** Give up on an address after this many failed provider calls. */
	const MAX_ATTEMPTS = 4;

	/** Wait after the Nth failure before asking the provider again, in seconds. */
	const BACKOFF = array( 1 => 3600, 2 => 21600, 3 => 86400 );

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

		$force = ! empty( $opts['force'] ) && empty( $opts['cache_only'] );

		// RESOLVE ONCE — memo, then the permanent table, then a transient.
		$cached = $force ? null : self::cache_get( $key );
		if ( is_array( $cached ) ) {
			self::touch( $key );
			return $cached;
		}

		// Cache-only callers (e.g. a budgeted backfill draining the cheap majority
		// before spending any network budget) opt out of the resolve path entirely:
		// a miss is a plain null, no filter and no networked provider is consulted.
		if ( ! empty( $opts['cache_only'] ) ) {
			return null;
		}

		// A networked lookup that failed before waits out its backoff (or was given up).
		if ( ! $force && ! self::may_retry( $key ) ) {
			return null;
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
		$asked = false;
		if ( null === $result && self::networked_enabled() ) {
			$result = self::resolve_networked( $key, $opts, $asked );
		}

		if ( null === $result ) {
			// Only a provider that was actually asked counts as a failure: an
			// address nobody could look up yet must not be marked unresolvable
			// before a provider is configured.
			if ( $asked ) {
				self::record_failure( $key, is_array( $address ) ? $key : (string) $address );
			}
			return null;
		}

		self::cache_set( $key, $result, is_array( $address ) ? $key : (string) $address );
		return $result;
	}

	/**
	 * Cache-only: the stored point, or null. NEVER touches the network. The one
	 * to call from anything a person is waiting on.
	 *
	 * @param string|array $address As for resolve_address().
	 */
	public static function lookup( $address ): ?array {
		return self::resolve_address( $address, array( 'cache_only' => true ) );
	}

	/**
	 * Cache, then the provider. For a background pass, a cron, or an explicit
	 * "resolve this now" action — not a render path. Null means "no coordinates
	 * available", never "not a real address".
	 *
	 * @param string|array $address As for resolve_address().
	 * @param bool         $force   Ask again even with a cached answer or a backoff.
	 */
	public static function resolve( $address, bool $force = false ): ?array {
		return self::resolve_address( $address, array( 'force' => $force ) );
	}

	/**
	 * Resolve a batch, politely: cached ones are counted, at most $limit provider
	 * calls are made, a quarter-second apart. For a cron or an admin "fill the
	 * cache" action, not a page load.
	 *
	 * @param array $addresses Strings or canonical field arrays.
	 * @param int   $limit     Hard cap on provider calls this pass.
	 * @param array $opts      Passed to resolve_address() (a test transport, say).
	 * @return array{resolved:int,cached:int,failed:int,skipped:int}
	 */
	public static function resolve_batch( array $addresses, int $limit = 25, array $opts = array() ): array {
		$out   = array( 'resolved' => 0, 'cached' => 0, 'failed' => 0, 'skipped' => 0 );
		$calls = 0;
		foreach ( $addresses as $addr ) {
			$key = is_array( $addr ) ? self::address_cache_key( $addr ) : self::normalize_string( (string) $addr );
			if ( '' === $key ) {
				$out['skipped']++;
				continue;
			}
			if ( self::lookup( $addr ) ) {
				$out['cached']++;
				continue;
			}
			if ( $calls >= $limit ) {
				$out['skipped']++;
				continue;
			}
			$calls++;
			if ( $calls > 1 && empty( $opts['transport'] ) ) {
				usleep( 250000 ); // a good citizen, even where the provider publishes no limit
			}
			if ( self::resolve_address( $addr, $opts ) ) {
				$out['resolved']++;
			} else {
				$out['failed']++;
			}
		}
		return $out;
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
		$s = trim( (string) $s, " \t\n\r\0\x0B," );
		$abbr = self::abbreviations();
		if ( $abbr && '' !== $s ) {
			// Whole words only: " street " -> " st ", so "Streeter" is never touched.
			$s = trim( preg_replace( '/\s+/', ' ', strtr( ' ' . str_replace( ',', ' , ', $s ) . ' ', $abbr ) ) );
			$s = str_replace( ' ,', ',', $s );
		}
		return $s;
	}

	/**
	 * Address words a region writes two ways, folded to one form so both hash to
	 * one key: [ ' street ' => ' st ', ... ] built from the
	 * `zdz_geocoder_address_abbreviations` filter ( [ 'street' => 'st' ] ). Core
	 * ships none, which keeps every existing key byte-identical; a business that
	 * turns this on changes its keys once, for every caller at the same time.
	 *
	 * @return array<string,string>
	 */
	private static function abbreviations(): array {
		$raw = self::filter( 'zdz_geocoder_address_abbreviations', array() );
		$out = array();
		foreach ( (array) $raw as $long => $short ) {
			$long  = is_string( $long ) ? strtolower( trim( $long ) ) : '';
			$short = is_string( $short ) ? strtolower( trim( $short ) ) : '';
			if ( '' !== $long && '' !== $short && $long !== $short && preg_match( '/^[\p{L}\p{N}][\p{L}\p{N} .\'-]*$/u', $long . $short ) ) {
				$out[ ' ' . $long . ' ' ] = ' ' . $short . ' ';
			}
		}
		return $out;
	}

	/** The permanent table's key for an address: sha1 of its cache key. */
	public static function hash( $address ): string {
		$key = is_array( $address ) ? self::address_cache_key( $address ) : self::normalize_string( (string) $address );
		return sha1( $key );
	}

	/** Fully-qualified table name. */
	public static function table(): string {
		global $wpdb;
		return ( isset( $wpdb ) && is_object( $wpdb ) ? (string) $wpdb->prefix : '' ) . self::TABLE;
	}

	/* ───────────────────────── Geometry ───────────────────────── */

	/** Great-circle distance in metres (haversine; far finer than a phone's fix at this scale). */
	public static function distance_m( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
		$r  = 6371000.0;
		$p1 = deg2rad( $lat1 );
		$p2 = deg2rad( $lat2 );
		$dp = deg2rad( $lat2 - $lat1 );
		$dl = deg2rad( $lng2 - $lng1 );
		$a  = sin( $dp / 2 ) * sin( $dp / 2 ) + cos( $p1 ) * cos( $p2 ) * sin( $dl / 2 ) * sin( $dl / 2 );
		return $r * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
	}

	/** The narrowest and widest same-property radius a setting may choose, in metres. */
	const SAME_PROPERTY_MIN_M = 10.0;
	const SAME_PROPERTY_MAX_M = 1000.0;

	/** The same-property radius in metres: SAME_PROPERTY_M, or the `zdz_geocoder_same_property_m` filter (10 to 1000). */
	public static function same_property_m(): float {
		$m = (float) self::filter( 'zdz_geocoder_same_property_m', self::SAME_PROPERTY_M );
		return ( $m >= self::SAME_PROPERTY_MIN_M && $m <= self::SAME_PROPERTY_MAX_M ) ? $m : self::SAME_PROPERTY_M;
	}

	/**
	 * Is this photo plausibly at this property? The distance and a verdict, not a
	 * bare bool: a caller usually shows the distance, and a near miss is worth
	 * offering as a suggestion. A phone fix vaguer than USABLE_ACCURACY_M is
	 * too_vague, never a match.
	 *
	 * @return array{same:bool,distance_m:float,verdict:string} match | nearby | elsewhere | too_vague
	 */
	public static function same_property( float $plat, float $plng, float $jlat, float $jlng, ?float $accuracy_m = null ): array {
		$d = self::distance_m( $plat, $plng, $jlat, $jlng );
		$r = self::same_property_m();
		if ( null !== $accuracy_m && $accuracy_m > self::USABLE_ACCURACY_M ) {
			return array( 'same' => false, 'distance_m' => $d, 'verdict' => 'too_vague' );
		}
		if ( $d <= $r ) {
			return array( 'same' => true, 'distance_m' => $d, 'verdict' => 'match' );
		}
		if ( $d <= $r * 3 ) {
			return array( 'same' => false, 'distance_m' => $d, 'verdict' => 'nearby' );
		}
		return array( 'same' => false, 'distance_m' => $d, 'verdict' => 'elsewhere' );
	}

	/**
	 * May an address that failed before be asked again now? Pure: the stored
	 * status, attempts and last try (UTC unix time) against $now.
	 */
	public static function retry_allowed( string $status, int $attempts, int $last_try, int $now ): bool {
		if ( 'unresolvable' === $status || $attempts >= self::MAX_ATTEMPTS ) {
			return false;
		}
		if ( $attempts <= 0 ) {
			return true;
		}
		$wait = self::BACKOFF[ $attempts ] ?? 86400;
		return ( $now - $last_try ) > $wait;
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
	private static function resolve_networked( string $key, array $opts, bool &$asked = false ): ?array {
		$endpoint = (string) self::filter( 'zdz_media_geocode_endpoint', '' );
		if ( '' === $endpoint ) {
			return null; // no endpoint configured -> never call out.
		}
		$asked = true;

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
			$ver  = defined( 'ZDZ_THEME_VER_FLOOR' ) ? ZDZ_THEME_VER_FLOOR : '1';
			$args = array(
				'timeout'    => self::HTTP_TIMEOUT,
				'user-agent' => 'Zorderz/' . $ver . ( function_exists( 'home_url' ) ? ' (+' . home_url( '/' ) . ')' : '' ),
				'headers'    => array( 'Accept' => 'application/json' ),
			);
			// The configured endpoint through the safe call: no private address, at most two redirects, each checked.
			$resp = class_exists( 'ZDZ_Outbound' )
				? ZDZ_Outbound::send( 'GET', $url, $args, false, 2 )
				: wp_safe_remote_get( $url, $args + array( 'redirection' => 2 ) );
			if ( function_exists( 'is_wp_error' ) && is_wp_error( $resp ) ) {
				return null;
			}
			if ( function_exists( 'wp_remote_retrieve_response_code' ) && 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
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
		if ( null === $parsed ) {
			$parsed = self::parse_common( $raw );
		}
		$label = (string) self::filter( 'zdz_media_geocode_provider_label', 'networked' );
		return self::shape_result( $parsed, '' !== $label ? substr( $label, 0, 48 ) : 'networked' );
	}

	/**
	 * The response shapes common geocoders answer with, so a business can point
	 * at one with configuration alone (no parse filter). Null for anything else.
	 *
	 *   { "lat": .., "lng"|"lon": .. }                                  a single point
	 *   { "result": { "addressMatches": [ { "coordinates": { "x": lng, "y": lat }, "matchedAddress": ".." } ] } }
	 *                                                                    an address-range match (street-interpolated)
	 *   [ { "lat": "..", "lon": "..", "display_name": ".." }, ... ]       a search list (first hit)
	 *   { "features": [ { "geometry": { "coordinates": [ lng, lat ] } } ] }  GeoJSON (first feature)
	 *
	 * @param mixed $raw A JSON string or an already-decoded array.
	 */
	public static function parse_common( $raw ): ?array {
		$d = is_array( $raw ) ? $raw : ( is_string( $raw ) ? json_decode( $raw, true ) : null );
		if ( ! is_array( $d ) ) {
			return null;
		}
		$m = $d['result']['addressMatches'][0] ?? null;
		if ( is_array( $m ) && isset( $m['coordinates']['x'], $m['coordinates']['y'] ) && is_numeric( $m['coordinates']['x'] ) && is_numeric( $m['coordinates']['y'] ) ) {
			return array( 'lat' => round( (float) $m['coordinates']['y'], 7 ), 'lng' => round( (float) $m['coordinates']['x'], 7 ),
				'precision' => 'interpolated', 'matched' => (string) ( $m['matchedAddress'] ?? '' ) );
		}
		$f = $d['features'][0]['geometry']['coordinates'] ?? null;
		if ( is_array( $f ) && isset( $f[0], $f[1] ) && is_numeric( $f[0] ) && is_numeric( $f[1] ) ) {
			return array( 'lat' => (float) $f[1], 'lng' => (float) $f[0] );
		}
		if ( isset( $d[0] ) && is_array( $d[0] ) ) {
			$d = $d[0];
		}
		if ( null !== self::num( $d, array( 'lat', 'latitude' ) ) && null !== self::num( $d, array( 'lng', 'lon', 'long', 'longitude' ) ) ) {
			$out = array( 'lat' => self::num( $d, array( 'lat', 'latitude' ) ), 'lng' => self::num( $d, array( 'lng', 'lon', 'long', 'longitude' ) ) );
			if ( isset( $d['display_name'] ) && is_string( $d['display_name'] ) ) {
				$out['matched'] = $d['display_name'];
			}
			if ( isset( $d['precision'] ) && is_string( $d['precision'] ) ) {
				$out['precision'] = $d['precision'];
			}
			return $out;
		}
		return null;
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
			'matched'     => isset( $raw['matched'] ) && is_scalar( $raw['matched'] ) ? (string) $raw['matched'] : '',
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

	/** Is the permanent table there (its migration stamped)? Never runs DDL. */
	private static function table_ready(): bool {
		global $wpdb;
		return isset( $wpdb ) && is_object( $wpdb ) && function_exists( 'get_option' )
			&& (int) get_option( self::SCHEMA_OPTION, 0 ) >= self::SCHEMA_VERSION;
	}

	private static function cache_get( string $key ) {
		if ( isset( self::$mem[ $key ] ) ) {
			return self::$mem[ $key ];
		}
		if ( self::table_ready() ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . self::table() . '` WHERE addr_hash = %s', sha1( $key ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::table() is $wpdb->prefix plus the class constant TABLE
			if ( is_array( $row ) && 'ok' === (string) $row['status'] && null !== $row['lat'] ) {
				$v = array(
					'lat'         => (float) $row['lat'],
					'lng'         => (float) $row['lng'],
					'provider'    => (string) $row['provider'],
					'resolved_at' => (string) $row['updated_at'],
					'precision'   => (string) $row['precision_level'],
					'matched'     => (string) $row['matched_address'],
				);
				self::$mem[ $key ] = $v;
				return $v;
			}
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

	private static function cache_set( string $key, array $result, string $raw = '' ): void {
		self::$mem[ $key ] = $result;
		if ( self::table_ready() ) {
			global $wpdb;
			$now  = gmdate( 'Y-m-d H:i:s' );
			$cut  = function ( $v, $n ) { return function_exists( 'mb_substr' ) ? mb_substr( (string) $v, 0, $n ) : substr( (string) $v, 0, $n ); };
			$data = array(
				'addr_hash'       => sha1( $key ),
				'address_raw'     => $cut( '' !== $raw ? $raw : $key, 255 ),
				'address_norm'    => $cut( $key, 255 ),
				'matched_address' => $cut( $result['matched'] ?? '', 255 ),
				'lat'             => round( (float) $result['lat'], 7 ),
				'lng'             => round( (float) $result['lng'], 7 ),
				'precision_level' => $cut( $result['precision'] ?? 'unknown', 24 ),
				'provider'        => $cut( $result['provider'] ?? 'unknown', 48 ),
				'status'          => 'ok',
				'attempts'        => 0,
				'last_attempt_at' => $now,
				'updated_at'      => $now,
			);
			$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM `' . self::table() . '` WHERE addr_hash = %s', $data['addr_hash'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::table() is $wpdb->prefix plus the class constant TABLE
			if ( $id > 0 ) {
				$wpdb->update( self::table(), $data, array( 'id' => $id ) );
			} else {
				$data['created_at'] = $now;
				$data['hit_count']  = 1;
				$wpdb->insert( self::table(), $data );
			}
			if ( '' === (string) $wpdb->last_error ) {
				return;
			}
			error_log( 'ZDZ_Geocoder: the places table refused a point (' . $wpdb->last_error . '); kept as a transient.' );
		}
		if ( function_exists( 'set_transient' ) ) {
			$ttl = (int) self::filter( 'zdz_media_geocode_cache_ttl', defined( 'WEEK_IN_SECONDS' ) ? WEEK_IN_SECONDS : 604800 );
			set_transient( self::cache_option_name( $key ), $result, $ttl );
		}
	}

	/** Remember that the provider could not place an address: retry later, give up after MAX_ATTEMPTS. */
	private static function record_failure( string $key, string $raw ): void {
		if ( ! self::table_ready() ) {
			return;
		}
		global $wpdb;
		$now  = gmdate( 'Y-m-d H:i:s' );
		$hash = sha1( $key );
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT id, attempts FROM `' . self::table() . '` WHERE addr_hash = %s', $hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::table() is $wpdb->prefix plus the class constant TABLE
		if ( is_array( $row ) ) {
			$n = (int) $row['attempts'] + 1;
			$wpdb->update( self::table(), array(
				'attempts'        => $n,
				'status'          => $n >= self::MAX_ATTEMPTS ? 'unresolvable' : 'retry',
				'last_attempt_at' => $now,
				'updated_at'      => $now,
			), array( 'id' => (int) $row['id'] ) );
			return;
		}
		$cut = function ( $v ) { return function_exists( 'mb_substr' ) ? mb_substr( (string) $v, 0, 255 ) : substr( (string) $v, 0, 255 ); };
		$wpdb->insert( self::table(), array(
			'addr_hash'       => $hash,
			'address_raw'     => $cut( '' !== $raw ? $raw : $key ),
			'address_norm'    => $cut( $key ),
			'status'          => 'retry',
			'attempts'        => 1,
			'hit_count'       => 0,
			'last_attempt_at' => $now,
			'created_at'      => $now,
			'updated_at'      => $now,
		) );
	}

	/** Has an address that failed before waited out its backoff? True when it never failed. */
	private static function may_retry( string $key ): bool {
		if ( ! self::table_ready() ) {
			return true;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT status, attempts, last_attempt_at FROM `' . self::table() . '` WHERE addr_hash = %s', sha1( $key ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::table() is $wpdb->prefix plus the class constant TABLE
		if ( ! is_array( $row ) || 'ok' === (string) $row['status'] ) {
			return true;
		}
		return self::retry_allowed( (string) $row['status'], (int) $row['attempts'], (int) strtotime( (string) $row['last_attempt_at'] . ' UTC' ), time() );
	}

	/** Count a cache hit. Cheap, and it makes an unused cache obvious. */
	private static function touch( string $key ): void {
		if ( ! self::table_ready() ) {
			return;
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE `' . self::table() . '` SET hit_count = hit_count + 1 WHERE addr_hash = %s', sha1( $key ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::table() is $wpdb->prefix plus the class constant TABLE
	}

	/** @return array{total:int,ok:int,retry:int,unresolvable:int,providers:array} */
	public static function stats(): array {
		$empty = array( 'total' => 0, 'ok' => 0, 'retry' => 0, 'unresolvable' => 0, 'providers' => array() );
		if ( ! self::table_ready() ) {
			return $empty;
		}
		global $wpdb;
		$t = self::table();
		return array(
			'total'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$t}`" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$t} is self::table(): $wpdb->prefix plus the class constant TABLE
			'ok'           => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$t}` WHERE status = 'ok'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$t} is self::table(): $wpdb->prefix plus the class constant TABLE
			'retry'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$t}` WHERE status = 'retry'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$t} is self::table(): $wpdb->prefix plus the class constant TABLE
			'unresolvable' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$t}` WHERE status = 'unresolvable'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$t} is self::table(): $wpdb->prefix plus the class constant TABLE
			'providers'    => (array) $wpdb->get_results( "SELECT provider, COUNT(*) n FROM `{$t}` WHERE status = 'ok' GROUP BY provider", ARRAY_A ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$t} is self::table(): $wpdb->prefix plus the class constant TABLE
		);
	}

	/** Forget the in-request memo (tests; a long pass that re-resolved something). */
	public static function flush(): void {
		self::$mem = array();
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
