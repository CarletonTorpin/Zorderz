<?php
/**
 * ZMI_Store — persistence for the mailbox identity + the service-mailbox registry.
 *
 * Identity lives in user-meta `zmi_mailbox_identity` = { upn, oid, tenant, aliases[] }
 * (meta, not a table, at one business's scale; promotable behind these signatures).
 * The service-mailbox registry (a shared/service box such as a support inbox, and any
 * future one) lives in the option `zmi_service_mailboxes`. Settings (internal-domain
 * fallback, service-mailbox reader roles, feature flags) are plain options, dark by default.
 *
 * Writes are validated + collision-guarded HERE so every entry point (profile save, seed,
 * Pull-from-Microsoft) shares one gate: an address may belong to at most one account, and
 * the same address can't be both a person's alias and a service mailbox.
 *
 * @package Zorderz\Mailbox_Identity
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZMI_Store {

	const META_IDENTITY   = 'zmi_mailbox_identity';
	const OPT_SERVICE      = 'zmi_service_mailboxes';
	const OPT_DOMAINS      = 'zmi_internal_domains';                // fallback only (Business Profile wins when this is blank)
	const OPT_READER_ROLES = 'zmi_service_mailbox_reader_roles';    // roles permitted to read a shared service mailbox
	const OPT_FLAGS        = 'zmi_flags';                           // feature flags, all dark by default

	/** Normalized identity for a user. Always the full shape, even when unset. */
	public static function identity( int $uid ): array {
		$raw = ( $uid > 0 ) ? get_user_meta( $uid, self::META_IDENTITY, true ) : '';
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'upn'     => ZDZ_Mailbox_Identity::normalize( (string) ( $raw['upn'] ?? '' ) ),
			'oid'     => (string) ( $raw['oid'] ?? '' ),
			'tenant'  => (string) ( $raw['tenant'] ?? '' ),
			'aliases' => ZDZ_Mailbox_Identity::clean_list( (array) ( $raw['aliases'] ?? array() ) ),
		);
	}

	/**
	 * Write a user's identity, validated + collision-guarded.
	 *
	 * @param int    $uid
	 * @param string $upn
	 * @param array  $aliases raw address list
	 * @param array  $extra   optional { oid, tenant }
	 * @return true|WP_Error  WP_Error names the colliding owner (never a silent overwrite).
	 */
	public static function save_identity( int $uid, string $upn, array $aliases, array $extra = array() ) {
		if ( $uid <= 0 ) {
			return new WP_Error( 'zmi_uid', 'Invalid user.' );
		}
		$upn = ZDZ_Mailbox_Identity::normalize( $upn );
		if ( '' !== $upn && ! is_email( $upn ) ) {
			return new WP_Error( 'zmi_upn', 'Primary mailbox is not a valid email address.' );
		}
		$aliases = ZDZ_Mailbox_Identity::clean_list( $aliases, $upn );

		// Every proposed address must not already belong to another account or a service box.
		foreach ( array_merge( '' !== $upn ? array( $upn ) : array(), $aliases ) as $addr ) {
			$guard = self::collision( $addr, $uid );
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}
		}

		$cur = self::identity( $uid );
		$val = array(
			'upn'     => $upn,
			'oid'     => (string) ( $extra['oid'] ?? $cur['oid'] ),
			'tenant'  => (string) ( $extra['tenant'] ?? $cur['tenant'] ),
			'aliases' => $aliases,
		);
		update_user_meta( $uid, self::META_IDENTITY, $val );
		self::write_through( $uid, $val );
		ZDZ_Mailbox_Identity::flush_index();
		return true;
	}

	/**
	 * Mirror the identity into the stores the Scheduler and theme-login read, so per-account
	 * calendar targeting (`zsch_mailbox`) and alias login (`zdz_login_aliases`) work with NO
	 * edits to those consumers once they adopt them. The resolver stays the source of truth;
	 * these are one-way mirrors refreshed on every write. (Zorderz note: the Scheduler already
	 * reads `zsch_mailbox`; login does not read `zdz_login_aliases` yet — the mirror write is
	 * harmless and ready for when it does. Retire once every consumer reads the resolver
	 * directly.)
	 */
	private static function write_through( int $uid, array $identity ): void {
		$upn = ZDZ_Mailbox_Identity::normalize( (string) ( $identity['upn'] ?? '' ) );
		if ( '' !== $upn && is_email( $upn ) ) {
			update_user_meta( $uid, 'zsch_mailbox', $upn );
		}
		update_user_meta( $uid, 'zdz_login_aliases', array_values( (array) ( $identity['aliases'] ?? array() ) ) );
	}

	/**
	 * Would $addr collide with anyone other than $self_uid (another person's identity/legacy,
	 * or a service mailbox)? Returns WP_Error describing the clash, else true.
	 */
	public static function collision( string $addr, int $self_uid ) {
		$addr = ZDZ_Mailbox_Identity::normalize( $addr );
		if ( '' === $addr ) {
			return true;
		}
		if ( ZDZ_Mailbox_Identity::is_service_address( $addr ) ) {
			return new WP_Error( 'zmi_collide_service', sprintf( '%s is registered as a shared/service mailbox — it cannot also be a personal alias.', $addr ) );
		}
		// Exact WP account email belonging to someone else.
		$owner = get_user_by( 'email', $addr );
		if ( $owner instanceof WP_User && (int) $owner->ID !== $self_uid ) {
			return new WP_Error( 'zmi_collide_user', sprintf( '%s is already the account email of %s.', $addr, $owner->display_name ) );
		}
		// Someone else's identity/legacy claim.
		$other = ZDZ_Mailbox_Identity::user_for_address( $addr );
		if ( $other > 0 && $other !== $self_uid ) {
			$u = get_userdata( $other );
			return new WP_Error( 'zmi_collide_alias', sprintf( '%s is already assigned to %s.', $addr, $u ? $u->display_name : ( 'user #' . $other ) ) );
		}
		return true;
	}

	/**
	 * One-time seed of a user's identity from the legacy stores (zsch_mailbox + zdz_login_aliases)
	 * when no identity meta exists yet. Idempotent; safe to call on every profile render.
	 */
	public static function seed_from_legacy( int $uid ): void {
		if ( $uid <= 0 ) {
			return;
		}
		$existing = get_user_meta( $uid, self::META_IDENTITY, true );
		if ( is_array( $existing ) && ! empty( $existing ) ) {
			return; // already established — never clobber
		}
		$upn     = ZDZ_Mailbox_Identity::mailbox_for_user( $uid ); // falls back to user_email
		$aliases = ZDZ_Mailbox_Identity::aliases_for_user( $uid ); // reads legacy zdz_login_aliases
		// Store without the collision gate (seed reflects state that already exists on disk).
		$val = array( 'upn' => $upn, 'oid' => '', 'tenant' => '', 'aliases' => $aliases );
		update_user_meta( $uid, self::META_IDENTITY, $val );
		self::write_through( $uid, $val );
		ZDZ_Mailbox_Identity::flush_index();
	}

	// ── service-mailbox registry ────────────────────────────────────

	/** @return array<int,array{address:string,kind:string,automated:int,index_mode:string,owner_service_user:int}> */
	public static function service_mailboxes(): array {
		$rows = get_option( self::OPT_SERVICE, array() );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$addr = ZDZ_Mailbox_Identity::normalize( (string) ( $r['address'] ?? '' ) );
			if ( '' === $addr || ! is_email( $addr ) ) {
				continue;
			}
			$out[] = array(
				'address'            => $addr,
				'kind'               => 'service',
				'automated'          => (int) ( $r['automated'] ?? 1 ),
				'index_mode'         => in_array( ( $r['index_mode'] ?? 'all' ), array( 'none', 'external', 'all' ), true ) ? (string) $r['index_mode'] : 'all',
				'owner_service_user' => (int) ( $r['owner_service_user'] ?? 0 ),
			);
		}
		return $out;
	}

	public static function set_service_mailboxes( array $rows ): void {
		$clean = array();
		foreach ( $rows as $r ) {
			$addr = ZDZ_Mailbox_Identity::normalize( (string) ( $r['address'] ?? '' ) );
			if ( '' === $addr || ! is_email( $addr ) ) {
				continue;
			}
			$clean[ $addr ] = array(
				'address'            => $addr,
				'automated'          => empty( $r['automated'] ) ? 0 : 1,
				'index_mode'         => in_array( ( $r['index_mode'] ?? 'all' ), array( 'none', 'external', 'all' ), true ) ? (string) $r['index_mode'] : 'all',
				'owner_service_user' => (int) ( $r['owner_service_user'] ?? 0 ),
			);
		}
		update_option( self::OPT_SERVICE, array_values( $clean ) );
		ZDZ_Mailbox_Identity::flush_index();
	}

	/** Pre-seed shown in the settings textarea until the registry is first saved. Ships EMPTY —
	 *  no service mailbox is pre-registered for any tenant; an admin adds each shared/service
	 *  box explicitly (e.g. a shared support inbox, indexed app-only — a later, separately
	 *  flagged step; here it is only registered). */
	public static function default_service_text(): string {
		return '';
	}

	// ── settings ────────────────────────────────────────────────────

	/**
	 * Configured internal domains: this app's own option first; if that is blank, the
	 * Business Profile's app domain when the theme provides one; else empty. Ships with
	 * no tenant domain baked in.
	 */
	public static function internal_domains_fallback(): array {
		$raw = (string) get_option( self::OPT_DOMAINS, '' );
		$out = array();
		foreach ( preg_split( '/[\s,]+/', strtolower( $raw ) ) as $d ) {
			$d = trim( $d );
			if ( '' !== $d ) {
				$out[] = $d;
			}
		}
		if ( ! empty( $out ) ) {
			return $out;
		}
		if ( class_exists( 'ZDZ_Business_Profile' ) && method_exists( 'ZDZ_Business_Profile', 'get' ) ) {
			$domain = strtolower( trim( (string) ZDZ_Business_Profile::get( 'web.app_domain', '' ) ) );
			if ( '' !== $domain ) {
				return array( $domain );
			}
		}
		return array(); // no configured domain and no Business Profile — empty, never a tenant default
	}

	/** Roles permitted to read a shared service mailbox's content. Default admins only. */
	public static function service_mailbox_reader_roles(): array {
		$roles = get_option( self::OPT_READER_ROLES, array( 'administrator' ) );
		return array_values( array_filter( array_map( 'strval', (array) $roles ) ) ) ?: array( 'administrator' );
	}

	public static function flag( string $key, bool $default = false ): bool {
		$flags = (array) get_option( self::OPT_FLAGS, array() );
		return isset( $flags[ $key ] ) ? ( 'yes' === $flags[ $key ] ) : $default;
	}

	public static function set_flag( string $key, bool $on ): void {
		$flags         = (array) get_option( self::OPT_FLAGS, array() );
		$flags[ $key ] = $on ? 'yes' : 'no';
		update_option( self::OPT_FLAGS, $flags );
	}
}
