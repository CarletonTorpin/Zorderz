<?php
/**
 * ZIB_Classifier — deterministic internal-vs-external classification.
 *
 * The single most safety-critical decision in the ingest path: getting it wrong
 * is how a CUSTOMER email could be mislabeled "internal" and captured under the
 * employee↔employee mode. So the rule is POSITIVE-CONFIRMATION and FAIL-SAFE:
 *
 *   class = 'internal'  ONLY IF every participant (from + to + cc) is CONFIRMED
 *                       internal.
 *   class = 'external'  otherwise — including any unknown, unresolvable, or
 *                       empty participant set.
 *
 * "Internal" = the address is in a configured internal domain (or a subdomain
 * of one) OR it resolves to an active, registered, non-kiosk WP user (catches
 * the rare internal person on an outside address). The domain list is
 * [IDENTITY] config (ZIB_Settings::internal_domains), never hardcoded.
 *
 * Mixed threads are classified PER MESSAGE, not per thread — a customer looped
 * into an internal chain flips only that one message to 'external'.
 *
 * The core methods are PURE (domains + an optional resolver are passed in), so
 * the whole rule is unit-testable without WordPress; classify_message() is the
 * thin WP-backed wrapper the ingest engine calls.
 *
 * @since 0.1.0 (P1 groundwork — built ahead, D4-independent)
 * @package Zorderz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZIB_Classifier {

	const CLASS_INTERNAL = 'internal';
	const CLASS_EXTERNAL = 'external';

	/** The bare domain of an address, lowercased. '' if none. */
	public static function domain_of( string $addr ): string {
		$addr = strtolower( trim( $addr ) );
		$at   = strrpos( $addr, '@' );
		return ( false === $at ) ? '' : substr( $addr, $at + 1 );
	}

	/**
	 * Is one address internal? Domain-match (exact or subdomain) OR a positive
	 * hit from the optional resolver. A lookalike domain (notexample.com)
	 * must NOT match example.com — the subdomain test requires a literal
	 * dot boundary.
	 *
	 * @param string        $addr
	 * @param string[]      $internal_domains  lowercase bare domains
	 * @param callable|null $is_registered_internal  fn(string $addr): bool
	 */
	public static function is_internal( string $addr, array $internal_domains, ?callable $is_registered_internal = null ): bool {
		$d = self::domain_of( $addr );
		if ( '' === $d ) {
			return false;
		}
		foreach ( $internal_domains as $id ) {
			$id = strtolower( trim( (string) $id ) );
			if ( '' === $id ) {
				continue;
			}
			if ( $d === $id ) {
				return true;
			}
			$suffix = '.' . $id;
			if ( strlen( $d ) > strlen( $suffix ) && substr( $d, -strlen( $suffix ) ) === $suffix ) {
				return true; // real subdomain (dot boundary), not a lookalike
			}
		}
		if ( $is_registered_internal && $is_registered_internal( $addr ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Classify a message from its participant addresses. PURE + FAIL-SAFE.
	 *
	 * @param string[]      $participant_addrs from + to + cc
	 * @param string[]      $internal_domains
	 * @param callable|null $is_registered_internal
	 * @return string 'internal' | 'external'
	 */
	public static function classify( array $participant_addrs, array $internal_domains, ?callable $is_registered_internal = null ): string {
		$addrs = array();
		foreach ( $participant_addrs as $a ) {
			$a = trim( (string) $a );
			if ( '' !== $a ) {
				$addrs[] = $a;
			}
		}
		if ( empty( $addrs ) ) {
			return self::CLASS_EXTERNAL; // unknown participants → treat as external
		}
		foreach ( $addrs as $a ) {
			if ( ! self::is_internal( $a, $internal_domains, $is_registered_internal ) ) {
				return self::CLASS_EXTERNAL; // any non-internal party → external
			}
		}
		return self::CLASS_INTERNAL;
	}

	/**
	 * Should a message of this class be stored under this account's index mode?
	 *
	 * @param string $class 'internal' | 'external'
	 * @param string $mode  'none' | 'internal' | 'external' | 'all'
	 */
	public static function keep_for_mode( string $class, string $mode ): bool {
		switch ( $mode ) {
			case 'all':
				return true;
			case 'internal':
				return self::CLASS_INTERNAL === $class;
			case 'external':
				return self::CLASS_EXTERNAL === $class;
			case 'none':
			default:
				return false;
		}
	}

	// ── WP-backed wrappers (used by the P1 ingest engine) ───────────

	/** Classify using the configured internal domains + the WP user roster. */
	public static function classify_message( array $participant_addrs ): string {
		return self::classify(
			$participant_addrs,
			ZIB_Settings::internal_domains(),
			array( __CLASS__, 'wp_is_registered_internal' )
		);
	}

	/**
	 * Does this address belong to a registered, active, NON-kiosk WP user?
	 * Former employees (ts_inactive) still count as internal — they were staff
	 * when the historical mail was sent. The shared kiosk is never a person.
	 */
	public static function wp_is_registered_internal( string $addr ): bool {
		// Prefer the unified mailbox-identity resolver (covers aliases); falls through to the
		// exact-email check when the resolver is absent or has no match.
		if ( class_exists( 'ZDZ_Mailbox_Identity' ) ) {
			$uid = (int) ZDZ_Mailbox_Identity::user_for_address( $addr );
			if ( $uid > 0 ) {
				return ! ( function_exists( 'zib_user_is_read_only' ) && zib_user_is_read_only( $uid ) );
			}
		}
		$u = get_user_by( 'email', trim( $addr ) );
		if ( ! $u ) {
			return false;
		}
		if ( function_exists( 'zib_user_is_read_only' ) && zib_user_is_read_only( (int) $u->ID ) ) {
			return false; // kiosk is a device, not a person
		}
		return true;
	}
}
