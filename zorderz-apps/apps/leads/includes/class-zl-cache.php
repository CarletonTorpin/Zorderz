<?php
/**
 * Zorderz Leads — Transient Cache Helper
 *
 * Version-counter-invalidated transient cache. Pattern:
 *
 *   $key = ZL_Cache::versioned_key( 'dicts_sources', 'sources_v' );
 *   $data = ZL_Cache::get_or_compute( $key, 15 * MINUTE_IN_SECONDS, function () use ( $ns ) {
 *       return $ns->get_sources();
 *   } );
 *
 * When any write happens that would change the computed result, bump the
 * version counter with ZL_Cache::bump( 'sources_v' ). All old cache keys
 * are orphaned (not deleted — they expire naturally).
 *
 * ──────────────────────────────────────────────────────────────────────────
 * TRAP 4 — EVERY KEY HAS A DOCUMENTED INVALIDATION SOURCE
 * ──────────────────────────────────────────────────────────────────────────
 *
 * Version-counter registry (callers document what bumps each):
 *
 *   sources_v        — Nutshell "Source" picklist. Bump on manual re-sync.
 *                       Tiny list (<50); 24h TTL fine.
 *   salespeople_v    — Nutshell "Salesperson" picklist. Bump on re-sync.
 *                       ~10 entries; 24h TTL.
 *   company_facts_v  — Mirror of the analytics module's facts version. Bump via theme
 *                       or cross-plugin hook when TSA edits facts.
 *   existing_tags_v  — Cached list of all ZL-applied tags on Nutshell.
 *                       Bump whenever ZL writes a new tag.
 *   dicts_v          — Umbrella bump for ALL dict caches. Use sparingly —
 *                       nukes everything by version.
 *
 * All get_or_compute calls use 15-min TTLs for "session" caches, 24h TTLs
 * for "dict" caches that change rarely.
 *
 * @package Zorderz\Leads
 * @since   1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZL_Cache {

	/** Short TTL — for per-batch operational data (dedup lookups, etc.). */
	const TTL_SHORT = 15 * MINUTE_IN_SECONDS;

	/** Medium TTL — for dict caches (sources, salespeople). */
	const TTL_MEDIUM = 24 * HOUR_IN_SECONDS;

	/** Option prefix for version counters. */
	const VERSION_OPT_PREFIX = 'zlg_ver_';

	/** Transient prefix — keeps our keys namespaced. */
	const TRANSIENT_PREFIX = 'zlg_';

	/**
	 * Read or compute a cached value. Version-bumped upstream invalidation
	 * is folded into the transient key so there's no explicit delete path.
	 *
	 * @param string   $base_key   Stable identifier ("dicts_sources").
	 * @param int      $ttl        Seconds.
	 * @param callable $compute_fn Zero-arg compute callback.
	 * @param string   $version_key Name of the version counter (see class doc).
	 *                              Pass '' to disable version folding.
	 * @return mixed
	 */
	public static function get_or_compute( $base_key, $ttl, $compute_fn, $version_key = '' ) {
		$cache_key = self::build_key( $base_key, $version_key );
		$hit = get_transient( $cache_key );
		if ( $hit !== false ) {
			return $hit;
		}
		$val = call_user_func( $compute_fn );
		// Only cache non-falsy results — a false/null/empty array is usually
		// the failure-path, and we don't want to pin failures for 15 min.
		if ( $val !== false && $val !== null ) {
			set_transient( $cache_key, $val, (int) $ttl );
		}
		return $val;
	}

	/**
	 * Bump the version counter for a given label. Next read gets a fresh
	 * key and the compute_fn runs.
	 *
	 * @param string $version_key  Counter label (without prefix).
	 * @return int The new version number.
	 */
	public static function bump( $version_key ) {
		$opt = self::VERSION_OPT_PREFIX . sanitize_key( $version_key );
		$v   = (int) get_option( $opt, 0 ) + 1;
		update_option( $opt, $v, false );
		return $v;
	}

	/**
	 * Read the current version counter (for debug / audit).
	 *
	 * @param string $version_key
	 * @return int
	 */
	public static function version( $version_key ) {
		return (int) get_option( self::VERSION_OPT_PREFIX . sanitize_key( $version_key ), 0 );
	}

	/**
	 * Manually delete a cache entry (escape hatch; prefer bump()).
	 *
	 * @param string $base_key
	 * @param string $version_key
	 * @return void
	 */
	public static function forget( $base_key, $version_key = '' ) {
		delete_transient( self::build_key( $base_key, $version_key ) );
	}

	/**
	 * Build the actual transient key including the current version.
	 *
	 * @param string $base_key
	 * @param string $version_key
	 * @return string
	 */
	private static function build_key( $base_key, $version_key ) {
		$base = self::TRANSIENT_PREFIX . sanitize_key( $base_key );
		if ( $version_key === '' ) {
			return $base;
		}
		$v = self::version( $version_key );
		return $base . '_v' . $v;
	}
}
