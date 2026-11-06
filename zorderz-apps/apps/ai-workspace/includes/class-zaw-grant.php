<?php
/**
 * ZAW_Grant -- who may use the Ai Workspace.
 *
 *   The grant   option zaw_grants = { user id: { at: time } }. An OWNER-LEVEL account (the zdz_owner or WordPress
 *               administrator role, as the platform's other owner-only pages; an office admin's zdz_admin alone is
 *               not) turns the workspace on FOR THEMSELVES, while fewer than max_grants people hold it (Core default
 *               one; ZAW_Settings). Whoever holds it can turn it off for themselves.
 *   Never       the shared device (its role, the hierarchy's word for it, or the kiosk tier), an account in Privacy
 *               View (it shows coded data to someone who must not see the real words), anyone signed out, anyone not
 *               granted. Checked on every route, and again when a tool runs and when the hub pulls a turn.
 *
 * @package Zorderz\AiWorkspace
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZAW_Grant {

	const OPTION = 'zaw_grants';

	public static function all() {
		$g   = get_option( self::OPTION, array() );
		$out = array();
		foreach ( is_array( $g ) ? $g : array() as $uid => $row ) {
			if ( (int) $uid > 0 && is_array( $row ) ) {
				$out[ (int) $uid ] = array( 'at' => (int) ( $row['at'] ?? 0 ) );
			}
		}
		return $out;
	}

	/** The shared device, by its role, its capability tier, or the hierarchy's word for it. */
	public static function is_shared_device( $uid ) {
		$uid = (int) $uid;
		if ( $uid <= 0 ) {
			return false;
		}
		if ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid ) ) {
			return true;
		}
		if ( class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) && ZDZ_Hierarchy::is_kiosk( $uid ) ) {
			return true;
		}
		if ( class_exists( 'ZDZ_Capabilities' ) && method_exists( 'ZDZ_Capabilities', 'tier_of' ) && 'kiosk' === ZDZ_Capabilities::tier_of( $uid ) ) {
			return true;
		}
		$u = get_userdata( $uid );
		return $u ? in_array( 'zdz_general', (array) $u->roles, true ) : false;
	}

	/** An account in Privacy View sees coded data only: it never talks to the assistant. */
	public static function in_privacy_view( $uid ) {
		return class_exists( 'ZDZ_Privacy_View' ) && method_exists( 'ZDZ_Privacy_View', 'is_on' ) && ZDZ_Privacy_View::is_on( (int) $uid );
	}

	/** '' when this account may be considered at all, else why not: signed_out | shared_device | privacy_view. */
	public static function refused( $uid ) {
		$uid = (int) $uid;
		if ( $uid <= 0 ) {
			return 'signed_out';
		}
		if ( self::is_shared_device( $uid ) ) {
			return 'shared_device';
		}
		if ( self::in_privacy_view( $uid ) ) {
			return 'privacy_view';
		}
		return '';
	}

	/** May this person send a turn? Granted, signed in, never the shared device, never in Privacy View. */
	public static function may_use( $uid ) {
		return '' === self::refused( $uid ) && isset( self::all()[ (int) $uid ] );
	}

	/** How many people may hold it at once: the business's setting; a filter may only narrow it. */
	public static function max() {
		$set = class_exists( 'ZAW_Settings' ) ? (int) ZAW_Settings::get( 'max_grants' ) : 1;
		$set = max( 1, $set );
		$f   = (int) apply_filters( 'zaw_max_grants', $set );
		return max( 1, min( $set, $f > 0 ? $f : $set ) );
	}

	/** An owner-level account: the zdz_owner or WordPress administrator role (an office admin's zdz_admin is not). */
	public static function owner_level( $uid ) {
		$u = get_userdata( (int) $uid );
		return $u && (bool) array_intersect( array( 'zdz_owner', 'administrator' ), (array) $u->roles );
	}

	/** An owner-level account turns it on for themselves. -> '' or why not (a sentence a person reads). */
	public static function grant_self( $uid ) {
		$uid = (int) $uid;
		if ( '' !== self::refused( $uid ) || ! user_can( $uid, 'manage_options' ) || ! self::owner_level( $uid ) ) {
			return __( 'Only an owner-level account can turn the Ai Workspace on, for itself, and never the shared device or an account in Privacy View.', 'zorderz' );
		}
		$g = self::all();
		if ( isset( $g[ $uid ] ) ) {
			return '';
		}
		if ( count( $g ) >= self::max() ) {
			return __( 'The Ai Workspace is already on for as many people as the settings allow. Someone who holds it turns it off first, or an administrator raises the number in the settings.', 'zorderz' );
		}
		$g[ $uid ] = array( 'at' => time() );
		update_option( self::OPTION, $g, false );
		return '';
	}

	public static function revoke_self( $uid ) {
		$g = self::all();
		unset( $g[ (int) $uid ] );
		update_option( self::OPTION, $g, false );
	}
}
