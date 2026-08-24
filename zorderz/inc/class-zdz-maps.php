<?php
/**
 * ZDZ_Maps — the map-provider seam for the shared client map-URL helper.
 *
 * Wave D / D-01. The platform builds map deep-links from an address in exactly
 * one place on the client: window.zdzMapsUrl() (theme assets/js/app.js), reused
 * by the analytics chat address linkifier and the jobs dossier. This class is
 * the server-side half: it owns the PROVIDER base-URLs as an Identity-configurable
 * value with a sensible Core default, and hands them to the client.
 *
 * GENERALIZATION: would another business's copy differ? The maps service MIGHT
 * (a business outside the Apple/Google duopoly, or one standardising on a single
 * provider) — so the base-URLs are [IDENTITY], routed through the Core filter
 *   apply_filters( 'zdz_maps_providers', <core defaults> )
 * with the generic default living here (and mirrored in the JS so the helper
 * still works if this config never loads). No company/person/place name appears
 * in any base-URL — they are generic vendor endpoints, a vendor fact not a tenant
 * one; the ADDRESS that gets appended is runtime contact data, never shipped.
 *
 * SECURITY: every base-URL is a hardcoded https:// endpoint ending at its query
 * parameter; the client appends only an encodeURIComponent()-escaped address.
 * There is no scheme derived from data, so a crafted address cannot become a
 * javascript:/data: link.
 *
 * @since   1.6.2
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Maps {

	/**
	 * Core default provider base-URLs, keyed by device family + intent.
	 * Each value is a complete https:// prefix; the client concatenates the
	 * encodeURIComponent()-escaped address onto it. Keys mirror the branches in
	 * window.zdzMapsUrl() exactly.
	 */
	const CORE_DEFAULTS = array(
		'appleSearch'      => 'https://maps.apple.com/?q=',
		'appleDirections'  => 'https://maps.apple.com/?daddr=',
		'googleSearch'     => 'https://www.google.com/maps/search/?api=1&query=',
		'googleDirections' => 'https://www.google.com/maps/dir/?api=1&destination=',
	);

	/**
	 * Resolve the provider base-URLs: the Core defaults, run through the
	 * `zdz_maps_providers` filter so an Identity Pack / a tenant plugin can swap
	 * the maps service. Any missing key falls back to its Core default, so a
	 * partial override can never produce an empty base.
	 *
	 * Pure aside from the one filter — safe to call anywhere, and callable under
	 * a no-WP harness (returns the Core defaults when apply_filters is absent).
	 *
	 * @return array<string,string> the four base-URLs, keyed as CORE_DEFAULTS.
	 */
	public static function providers(): array {
		$defaults = self::CORE_DEFAULTS;
		$providers = function_exists( 'apply_filters' )
			? apply_filters( 'zdz_maps_providers', $defaults )
			: $defaults;
		if ( ! is_array( $providers ) ) {
			$providers = $defaults;
		}
		// Merge over defaults so every key is always present and non-empty.
		$out = $defaults;
		foreach ( $defaults as $k => $_ ) {
			if ( isset( $providers[ $k ] ) && is_string( $providers[ $k ] ) && '' !== $providers[ $k ] ) {
				$out[ $k ] = $providers[ $k ];
			}
		}
		return $out;
	}

	/**
	 * Locale street-grammar EXTENSION for the chat address linkifier, keyed
	 * `strong` / `weak` / `spanish` (each an array of street-type tokens). Ships
	 * EMPTY from Core — the linkifier already carries a US + common-Spanish default
	 * baked into the JS, and merges per-key — so a `territories` Identity Pack can
	 * ADD a locale's grammar via the `zdz_street_grammar` filter without Core naming
	 * any place. Empty by default => the JS defaults stand unchanged.
	 *
	 * @return array<string,array<int,string>> grammar overrides, or [] for none.
	 */
	public static function street_grammar(): array {
		$grammar = function_exists( 'apply_filters' )
			? apply_filters( 'zdz_street_grammar', array() )
			: array();
		return is_array( $grammar ) ? $grammar : array();
	}

	/** Register the client-config emitter. Self-boots when WordPress is present. */
	public static function boot(): void {
		if ( function_exists( 'add_action' ) ) {
			// Priority 20 so it runs AFTER the theme has enqueued zdz-app-js.
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'emit_config' ), 20 );
		}
	}

	/**
	 * Attach window.zdzMapsProviders just before the theme app script runs, so
	 * window.zdzMapsUrl() (and every surface reusing it) sees the tenant's
	 * provider choice. A no-op on any page where zdz-app-js is not enqueued.
	 */
	public static function emit_config(): void {
		if ( ! function_exists( 'wp_add_inline_script' ) || ! function_exists( 'wp_json_encode' ) ) {
			return;
		}
		$json = wp_json_encode( self::providers() );
		if ( ! is_string( $json ) || '' === $json ) {
			return;
		}
		$script = 'window.zdzMapsProviders = ' . $json . ';';
		// Only attach a street-grammar override when a pack actually supplies one.
		$grammar = self::street_grammar();
		if ( ! empty( $grammar ) ) {
			$gjson = wp_json_encode( $grammar );
			if ( is_string( $gjson ) && '' !== $gjson ) {
				$script .= ' window.zdzStreetGrammar = ' . $gjson . ';';
			}
		}
		wp_add_inline_script( 'zdz-app-js', $script, 'before' );
	}
}

// Self-boot (schema/behaviour only; no data). Guarded for the no-WP harness.
if ( function_exists( 'add_action' ) ) {
	ZDZ_Maps::boot();
}
