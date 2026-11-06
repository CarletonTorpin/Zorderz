<?php
/**
 * ZAGR_People - who is asking: the request's IP and device, staff and the shared device, a person's name. [CORE]
 *
 * Tiers come from the platform's ZDZ_Capabilities when it is there, behind class_exists; otherwise from the platform's
 * role slugs. The shared device and Demo Mode are never staff.
 *
 * The IP: the platform's ZDZ_Client_IP::get() (REMOTE_ADDR, what the server saw, which a visitor cannot set; or a
 * trusted proxy's value on zdz_client_ip). A forwarded-for header is recorded beside it in the sealed record, never used
 * for the rate limits (anyone can type one). The filter zagr_client_ip may still replace the address for this app. The caps never store the IP itself, nor an unkeyed hash of it (an MD5 of an
 * IPv4 address reverses in moments): they count by ip_tag(), an HMAC under the site's salt.
 *
 * @package Zorderz\Agreements
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAGR_People {

	/** The address the rate limits use. '' outside a web request. */
	public static function client_ip(): string {
		$ip = class_exists( 'ZDZ_Client_IP' )
			? ZDZ_Client_IP::get()
			: ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '' );
		$ip = (string) apply_filters( 'zagr_client_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/** The visitor's address as the caps count it: 16 hex characters of an HMAC under the site's salt ('' without an IP). */
	public static function ip_tag( string $ip = '' ): string {
		$ip = '' !== $ip ? $ip : self::client_ip();
		return '' === $ip ? '' : substr( hash_hmac( 'sha256', 'zagr-ip|' . $ip, (string) wp_salt( 'nonce' ) ), 0, 16 );
	}

	/** Which address: 16 hex characters of a MAC under the agreements key, never the address. '' without a key. */
	public static function email_fp( string $email ): string {
		$m = ZAGR_Key::mac( 'email|' . strtolower( trim( $email ) ) );
		return null === $m ? '' : substr( $m, 0, 16 );
	}

	/** What the record keeps (sealed): the client IP, and a forwarded-for chain's first entry when there is one. */
	public static function request_ip(): string {
		$ip = self::client_ip();
		if ( '' === $ip ) {
			return '';
		}
		if ( class_exists( 'ZDZ_Client_IP' ) ) {
			$chain = ZDZ_Client_IP::forwarded_chain();
			$fwd   = $chain ? $chain[0] : '';
		} else {
			$fwd = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? trim( explode( ',', sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) )[0] ) : '';
		}
		if ( '' !== $fwd && $fwd !== $ip && filter_var( $fwd, FILTER_VALIDATE_IP ) ) {
			return $ip . ' (forwarded for ' . $fwd . ')';
		}
		return $ip;
	}

	/** The device as the browser names itself (sealed in the record). */
	public static function request_ua(): string {
		if ( '' === self::client_ip() ) {
			return '';
		}
		// WordPress adds slashes to $_SERVER: they come off, so the record keeps the header's own words (as plain text)
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$ua = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $ua );
		return substr( trim( $ua ), 0, 400 );
	}

	/** The shared device or Demo Mode: the platform's answer, and the shared-device role itself either way (refuse, don't guess). */
	public static function is_kiosk( int $uid ): bool {
		if ( class_exists( 'ZDZ_Kiosk_Demo' ) && method_exists( 'ZDZ_Kiosk_Demo', 'is_active' ) && ZDZ_Kiosk_Demo::is_active() ) {
			return true; // Demo Mode: the device runs as the shared account
		}
		if ( $uid <= 0 ) {
			return false;
		}
		if ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid ) ) {
			return true;
		}
		if ( class_exists( 'ZDZ_Capabilities' ) && method_exists( 'ZDZ_Capabilities', 'tier_of' ) && 'kiosk' === ZDZ_Capabilities::tier_of( $uid ) ) {
			return true;
		}
		if ( class_exists( 'ZDZ_Alert_Router' ) && method_exists( 'ZDZ_Alert_Router', 'is_kiosk_user' ) && ZDZ_Alert_Router::is_kiosk_user( $uid ) ) {
			return true;
		}
		$u      = get_userdata( $uid );
		$shared = class_exists( 'ZDZ_User_Roles' ) && defined( 'ZDZ_User_Roles::SHARED_DEVICE_ROLE' ) ? ZDZ_User_Roles::SHARED_DEVICE_ROLE : 'zdz_general';
		return $u ? in_array( $shared, (array) $u->roles, true ) : false;
	}

	private static function tier( int $uid ): string {
		if ( $uid <= 0 ) {
			return '';
		}
		if ( self::is_kiosk( $uid ) ) {
			return 'kiosk';
		}
		if ( class_exists( 'ZDZ_Capabilities' ) && method_exists( 'ZDZ_Capabilities', 'tier_of' ) ) {
			return (string) ZDZ_Capabilities::tier_of( $uid );
		}
		// without the theme: ZDZ_Capabilities::tier_of()'s rule. A plain subscriber is no app user.
		$u     = get_userdata( $uid );
		$roles = $u ? (array) $u->roles : array();
		if ( array_intersect( $roles, array( 'administrator', 'zdz_owner', 'zdz_admin' ) ) || user_can( $uid, 'manage_options' ) ) {
			return 'admin';
		}
		if ( array_intersect( $roles, array( 'zdz_sales', 'zdz_operator', 'zdz_mfg' ) ) ) {
			return 'staff';
		}
		if ( in_array( 'zdz_tech', $roles, true ) || user_can( $uid, 'zdz_access_app' ) ) {
			return 'viewer';
		}
		return '';
	}

	/** An app user who may read where an agreement stands: viewer, staff or admin, never the shared device. */
	public static function is_viewer( int $uid ): bool {
		return in_array( self::tier( $uid ), array( 'viewer', 'staff', 'admin' ), true );
	}

	/** Staff who may sign for the business: the staff and admin tiers, never the shared device. */
	public static function is_staff( int $uid ): bool {
		return in_array( self::tier( $uid ), array( 'staff', 'admin' ), true );
	}

	/** An administrator (settings, the key, the record's staff actions): manage_options, never the shared device. */
	public static function is_admin( int $uid ): bool {
		return $uid > 0 && ! self::is_kiosk( $uid ) && user_can( $uid, 'manage_options' );
	}

	/**
	 * The admin tier as the platform reads it, or manage_options;
	 * never the shared device or Demo Mode. Who may record a paper signing from the app as an administrator.
	 */
	public static function is_admin_tier( int $uid ): bool {
		return $uid > 0 && ! self::is_kiosk( $uid ) && ( user_can( $uid, 'manage_options' ) || 'admin' === self::tier( $uid ) );
	}

	/** A person's name for the agreement ("Signed for the contractor by …"), resolved when it is drawn. */
	public static function name( int $uid ): string {
		$u = $uid > 0 ? get_userdata( $uid ) : null;
		if ( ! $u ) {
			return '';
		}
		$n = trim( (string) ( $u->display_name ?? '' ) );
		return '' !== $n ? $n : (string) ( $u->user_login ?? '' );
	}

	/** "z…@example.com": enough to recognize, not enough to read. */
	public static function mask_email( string $e ): string {
		$at = strrpos( $e, '@' );
		if ( false === $at || $at < 1 ) {
			return 'the address on your estimate';
		}
		return substr( $e, 0, 1 ) . '…' . substr( $e, $at );
	}
}
