<?php
/**
 * ZDZ_Mailbox_Identity — the ONE authoritative "which addresses ARE this person?" resolver.
 *
 * Sibling to ZDZ_Party (BID-2, "which person?"); this is the BID-7 Connection concretion,
 * "which mailbox / addresses?". Every connector that used to do its own
 * get_user_by('email', $addr) — the Scheduler's mailbox targeting + intake matcher, the
 * Inbox's classifier + ingest attribution, the magic-link login bridge — READS this
 * instead, so an alias, a shared root inbox, or a second-domain address all resolve to the
 * one account (INV-Ownership), and a shared/service box (e.g. a support inbox) is a
 * first-class, never-a-person address.
 *
 * PUBLIC API (stable — consumers depend on these signatures; a clean platform-level name
 * on purpose, so other apps can call it directly, and the class can later move verbatim
 * into the theme's Core services with no caller change):
 *   ::mailbox_for_user( int $uid ): string            primary Exchange mailbox / UPN ('' if none)
 *   ::aliases_for_user( int $uid ): string[]           the person's other addresses (never the primary)
 *   ::addresses_for_user( int $uid ): string[]         mailbox ∪ aliases ∪ user_email
 *   ::user_for_address( string $addr ): int            owning WP user id, EXACT + ONE match only, else 0
 *   ::is_internal_address( string $addr ): bool        a person of ours, an internal domain, or a service box
 *   ::is_service_address( string $addr ): bool         a registered shared/service mailbox
 *
 * FAIL-SAFE: user_for_address() is exact-match, one-match-only. An address that maps to two
 * accounts (a mis-provisioned duplicate) returns 0 — never a coin flip — exactly as
 * Zsch_Intake already refuses an ambiguous mailbox meta. A miss loses attribution
 * (recoverable); a wrong match is a privacy breach (not), so the bias is hard toward 0.
 *
 * The parse/match core (normalize, domain_of, is_domain_internal, pick) is PURE — no WP —
 * so the whole rule is unit-testable without WordPress. The WP seams (identity meta, user
 * roster, memoized reverse index) are thin wrappers.
 *
 * @package Zorderz\Mailbox_Identity
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZDZ_Mailbox_Identity {

	/** Per-request memo of the reverse address→uid index (built once; a few dozen rows). */
	private static $index = null;

	// ════════════════════════════════════════════════════════════════
	//  PURE core (no WP / DB — unit-tested directly)
	// ════════════════════════════════════════════════════════════════

	/** Lowercased, trimmed address. PURE. */
	public static function normalize( string $addr ): string {
		return strtolower( trim( $addr ) );
	}

	/** Bare domain of an address, lowercased; '' if none. PURE. */
	public static function domain_of( string $addr ): string {
		$addr = self::normalize( $addr );
		$at   = strrpos( $addr, '@' );
		return ( false === $at ) ? '' : substr( $addr, $at + 1 );
	}

	/**
	 * Is a domain one of the internal domains (exact or a real dot-boundary subdomain)?
	 * A lookalike (notexample.com) must NOT match example.com. PURE.
	 * (Same rule as ZIB_Classifier::is_internal, kept here so the resolver is the one home.)
	 *
	 * @param string   $domain           lowercase bare domain
	 * @param string[] $internal_domains lowercase bare domains
	 */
	public static function is_domain_internal( string $domain, array $internal_domains ): bool {
		$domain = self::normalize( $domain );
		if ( '' === $domain ) {
			return false;
		}
		foreach ( $internal_domains as $id ) {
			$id = self::normalize( (string) $id );
			if ( '' === $id ) {
				continue;
			}
			if ( $domain === $id ) {
				return true;
			}
			$suffix = '.' . $id;
			if ( strlen( $domain ) > strlen( $suffix ) && substr( $domain, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve an address against a prebuilt index map. EXACT + ONE only: an address whose
	 * index entry is the ambiguity sentinel (0) returns 0. PURE.
	 *
	 * @param string             $addr
	 * @param array<string,int>  $index  address(normalized) => uid, or => 0 when ambiguous
	 */
	public static function pick( string $addr, array $index ): int {
		$addr = self::normalize( $addr );
		return ( '' !== $addr && isset( $index[ $addr ] ) ) ? (int) $index[ $addr ] : 0;
	}

	/** Dedupe + normalize a list of addresses, dropping empties and (optionally) one primary. PURE. */
	public static function clean_list( array $addrs, string $exclude = '' ): array {
		$exclude = self::normalize( $exclude );
		$out     = array();
		foreach ( $addrs as $a ) {
			$a = self::normalize( (string) $a );
			if ( '' === $a || $a === $exclude || isset( $out[ $a ] ) ) {
				continue;
			}
			$out[ $a ] = true;
		}
		return array_keys( $out );
	}

	// ════════════════════════════════════════════════════════════════
	//  WP seams
	// ════════════════════════════════════════════════════════════════

	/** The person's primary Exchange mailbox / UPN. Identity meta → legacy zsch_mailbox → user_email. */
	public static function mailbox_for_user( int $uid ): string {
		if ( $uid <= 0 ) {
			return '';
		}
		$id = ZMI_Store::identity( $uid );
		if ( '' !== $id['upn'] && is_email( $id['upn'] ) ) {
			return $id['upn'];
		}
		$legacy = (string) get_user_meta( $uid, 'zsch_mailbox', true ); // read-through until retired
		if ( is_email( $legacy ) ) {
			return self::normalize( $legacy );
		}
		$u = get_userdata( $uid );
		return ( $u && is_email( $u->user_email ) ) ? self::normalize( $u->user_email ) : '';
	}

	/** The person's aliases (never the primary): identity meta ∪ legacy zdz_login_aliases. */
	public static function aliases_for_user( int $uid ): array {
		if ( $uid <= 0 ) {
			return array();
		}
		$id      = ZMI_Store::identity( $uid );
		$legacy  = get_user_meta( $uid, 'zdz_login_aliases', true ); // read-through until retired
		$merged  = array_merge( (array) $id['aliases'], is_array( $legacy ) ? $legacy : array() );
		return self::clean_list( $merged, self::mailbox_for_user( $uid ) );
	}

	/** Every address that IS this person: primary ∪ aliases ∪ WP account email. */
	public static function addresses_for_user( int $uid ): array {
		$primary = self::mailbox_for_user( $uid );
		$u       = get_userdata( $uid );
		$acct    = ( $u && is_email( $u->user_email ) ) ? $u->user_email : '';
		$all     = array_merge( array( $primary, $acct ), self::aliases_for_user( $uid ) );
		return self::clean_list( $all );
	}

	/**
	 * The WP user that owns an address. Exact + one-match-only. 0 if unknown or ambiguous.
	 * A cheap get_user_by('email') fast-path, then the memoized reverse index (identity +
	 * legacy metas). Never returns a guess.
	 */
	public static function user_for_address( string $addr ): int {
		$addr = self::normalize( $addr );
		if ( '' === $addr || false === strpos( $addr, '@' ) ) {
			return 0;
		}
		$u = get_user_by( 'email', $addr );
		if ( $u instanceof WP_User ) {
			return (int) $u->ID;
		}
		return self::pick( $addr, self::index() );
	}

	/**
	 * Internal = one of our people (active, non-kiosk), OR an internal domain, OR a
	 * registered service mailbox. The single internal test a classifier adopts.
	 */
	public static function is_internal_address( string $addr ): bool {
		$addr = self::normalize( $addr );
		if ( '' === $addr ) {
			return false;
		}
		if ( self::is_service_address( $addr ) ) {
			return true; // a registered service box is company-owned
		}
		$uid = self::user_for_address( $addr );
		if ( $uid > 0 && self::user_is_person( $uid ) ) {
			return true;
		}
		return self::is_domain_internal( self::domain_of( $addr ), self::internal_domains() );
	}

	/** Is this a registered shared/service mailbox (managed on the settings screen)? */
	public static function is_service_address( string $addr ): bool {
		$addr = self::normalize( $addr );
		foreach ( ZMI_Store::service_mailboxes() as $svc ) {
			if ( self::normalize( (string) ( $svc['address'] ?? '' ) ) === $addr ) {
				return true;
			}
		}
		return false;
	}

	/** The configured internal domains — this app's option, then the Business Profile's app domain, else empty. */
	public static function internal_domains(): array {
		return ZMI_Store::internal_domains_fallback();
	}

	// ── helpers ─────────────────────────────────────────────────────

	/** An active, non-kiosk WP user is a "person" (former staff still count; the kiosk never does). */
	private static function user_is_person( int $uid ): bool {
		if ( function_exists( 'zib_user_is_read_only' ) && zib_user_is_read_only( $uid ) ) {
			return false;
		}
		if ( function_exists( 'zsch_user_is_read_only' ) && zsch_user_is_read_only( $uid ) ) {
			return false;
		}
		$u = get_userdata( $uid );
		if ( ! $u ) {
			return false;
		}
		return ! in_array( 'zdz_general', (array) $u->roles, true );
	}

	/**
	 * Build (once per request) the reverse index address→uid from every user's identity meta
	 * and legacy metas. An address claimed by two distinct users collapses to the ambiguity
	 * sentinel 0. user_email is intentionally NOT folded in here — user_for_address()
	 * already fast-paths it via get_user_by('email'), which is authoritative and unique.
	 *
	 * @return array<string,int>
	 */
	private static function index(): array {
		if ( null !== self::$index ) {
			return self::$index;
		}
		$map = array();
		$claim = function ( string $addr, int $uid ) use ( &$map ) {
			$addr = self::normalize( $addr );
			if ( '' === $addr || false === strpos( $addr, '@' ) ) {
				return;
			}
			if ( isset( $map[ $addr ] ) && (int) $map[ $addr ] !== $uid ) {
				$map[ $addr ] = 0; // two owners → ambiguous → never matched
				return;
			}
			$map[ $addr ] = $uid;
		};

		$users = get_users( array( 'fields' => array( 'ID' ) ) );
		foreach ( $users as $row ) {
			$uid = (int) $row->ID;
			$id  = ZMI_Store::identity( $uid );
			if ( '' !== $id['upn'] ) {
				$claim( $id['upn'], $uid );
			}
			foreach ( (array) $id['aliases'] as $a ) {
				$claim( (string) $a, $uid );
			}
			// Legacy read-through (retired once consumers read the resolver directly).
			$leg_mbx = (string) get_user_meta( $uid, 'zsch_mailbox', true );
			if ( '' !== $leg_mbx ) {
				$claim( $leg_mbx, $uid );
			}
			$leg_al = get_user_meta( $uid, 'zdz_login_aliases', true );
			if ( is_array( $leg_al ) ) {
				foreach ( $leg_al as $a ) {
					$claim( (string) $a, $uid );
				}
			}
		}

		self::$index = $map;
		return self::$index;
	}

	/** Test/seam hook: drop the memo (used by tests and after a bulk write). */
	public static function flush_index(): void {
		self::$index = null;
	}
}
