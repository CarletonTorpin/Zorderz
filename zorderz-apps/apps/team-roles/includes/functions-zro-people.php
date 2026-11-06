<?php
/**
 * Who is who, for Team Roles. The platform's tier ladder decides the admin tier; the shared
 * device is never a person here: it holds no row, sees nothing and is never assigned work.
 *
 * @package Zorderz\TeamRoles
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The staff roles that make someone part of the team (a row in the grid). Never the shared-device role. */
function zro_team_roles() {
	return array( 'administrator', 'zdz_owner', 'zdz_admin', 'zdz_sales', 'zdz_operator', 'zdz_mfg', 'zdz_tech' );
}

/** Is this account the shared device? The platform's trait test first, then its role slug. */
function zro_is_kiosk( $uid ) {
	$uid = (int) $uid;
	if ( $uid <= 0 ) {
		return false;
	}
	if ( class_exists( 'ZDZ_User_Roles' ) && method_exists( 'ZDZ_User_Roles', 'is_shared_device' ) && ZDZ_User_Roles::is_shared_device( $uid ) ) {
		return true;
	}
	if ( class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'is_kiosk' ) ) {
		return (bool) ZDZ_Hierarchy::is_kiosk( $uid );
	}
	$u = get_userdata( $uid );
	return $u ? in_array( 'zdz_general', (array) $u->roles, true ) : false;
}

/** On the team: a real user with a staff role, and not the shared device. */
function zro_is_team_member( $uid ) {
	$uid = (int) $uid;
	if ( $uid <= 0 || zro_is_kiosk( $uid ) ) {
		return false;
	}
	$u = get_userdata( $uid );
	return $u && (bool) array_intersect( (array) $u->roles, zro_team_roles() );
}

/** May this person open the app? The platform's app access; never the shared device. */
function zro_user_can_use( $uid ) {
	$uid = (int) $uid;
	if ( $uid <= 0 || zro_is_kiosk( $uid ) ) {
		return false;
	}
	if ( class_exists( 'ZDZ_Plugin_API' ) && method_exists( 'ZDZ_Plugin_API', 'user_can_access_app' ) ) {
		return (bool) ZDZ_Plugin_API::user_can_access_app( $uid, ZRO_APP_ID );
	}
	return zro_is_team_member( $uid );
}

/** The admin tier edits the grid and the list of abilities. */
function zro_user_is_admin( $uid ) {
	$uid = (int) $uid;
	if ( $uid <= 0 || zro_is_kiosk( $uid ) ) {
		return false;
	}
	if ( class_exists( 'ZDZ_Capabilities' ) && method_exists( 'ZDZ_Capabilities', 'tier_of' ) ) {
		return 'admin' === ZDZ_Capabilities::tier_of( $uid );
	}
	return function_exists( 'user_can' ) && user_can( $uid, 'manage_options' );
}

/**
 * May $viewer see $target's row (notes and dates included)? Yourself; the admin tier, everyone;
 * a crew lead, their crew (ZDZ_Hierarchy::can_oversee, the platform's one oversight gate). The
 * shared device, never.
 */
function zro_can_view( $viewer, $target ) {
	$viewer = (int) $viewer;
	$target = (int) $target;
	if ( $viewer <= 0 || $target <= 0 || zro_is_kiosk( $viewer ) ) {
		return false;
	}
	if ( $viewer === $target ) {
		return true;
	}
	if ( zro_user_is_admin( $viewer ) ) {
		return true;
	}
	if ( class_exists( 'ZDZ_Hierarchy' ) && method_exists( 'ZDZ_Hierarchy', 'can_oversee' ) ) {
		return (bool) ZDZ_Hierarchy::can_oversee( $viewer, $target );
	}
	return false;
}

/** The whole team: id, display name, initials, tone (never an e-mail or a role). Once per request. */
function zro_team( $flush = false ) {
	static $memo = null;
	if ( $flush ) {
		$memo = null;
	}
	if ( null !== $memo ) {
		return $memo;
	}
	$out   = array();
	$users = function_exists( 'get_users' )
		? get_users( array( 'role__in' => zro_team_roles(), 'fields' => array( 'ID', 'display_name' ), 'orderby' => 'display_name', 'number' => 500 ) )
		: array();
	foreach ( (array) $users as $u ) {
		$uid = (int) $u->ID;
		if ( ! zro_is_team_member( $uid ) ) {
			continue; // the shared device (even holding a staff role) and anyone without a staff role is not on the team
		}
		$tone  = zro_tone( $uid );
		$out[] = array(
			'id'       => $uid,
			'name'     => (string) $u->display_name,
			'initials' => '' !== $tone['initials'] ? $tone['initials'] : zro_initials_from_name( (string) $u->display_name ),
			'color'    => $tone['color'],
			'ink'      => $tone['ink'],
		);
	}
	$memo = $out;
	return $out;
}

/** The person's initials and tone from ZDZ_Person_Color, or empty values. */
function zro_tone( $uid ) {
	$empty = array( 'initials' => '', 'color' => '', 'ink' => '' );
	if ( class_exists( 'ZDZ_Person_Color' ) && method_exists( 'ZDZ_Person_Color', 'for_user' ) ) {
		$p = ZDZ_Person_Color::for_user( (int) $uid );
		if ( is_array( $p ) ) {
			return array( 'initials' => (string) ( $p['initials'] ?? '' ), 'color' => (string) ( $p['color'] ?? '' ), 'ink' => (string) ( $p['ink'] ?? '' ) );
		}
	}
	return $empty;
}

/** Two letters from a display name, for a person with no initials set. */
function zro_initials_from_name( $name ) {
	$parts = array_slice( preg_split( '/\s+/', trim( (string) $name ) ), 0, 2 );
	return strtoupper( implode( '', array_map( function ( $w ) { return function_exists( 'mb_substr' ) ? mb_substr( $w, 0, 1 ) : substr( $w, 0, 1 ); }, $parts ) ) );
}

/** The team rows $viewer may see: everyone (admin tier), self plus crew (crew lead), or self. */
function zro_visible_team( $viewer ) {
	$viewer = (int) $viewer;
	$out    = array();
	foreach ( zro_team() as $p ) {
		if ( zro_can_view( $viewer, $p['id'] ) ) {
			$out[] = $p;
		}
	}
	return $out;
}

if ( ! function_exists( 'zdz_person_can' ) ) {
	/**
	 * Can this person do this? The one question other apps ask.
	 *
	 * @param int    $user_id A WordPress user id.
	 * @param string $key     An ability key, or a cell key "<item-id>__sell" | "__install" | "__repair".
	 * @param string $role    Optional: the role apart ("sell", "install", "repair"), with $key the item id.
	 * @return array{can:bool, level:string, why:string} Always these three keys; can is true only for Can.
	 */
	function zdz_person_can( $user_id, $key, $role = '' ) {
		if ( '' !== (string) $role ) {
			$key = ZRO_Abilities::cell_key( (string) $key, (string) $role );
		}
		return ZRO_Roles::person_can( $user_id, $key );
	}
}
