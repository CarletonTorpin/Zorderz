<?php
/**
 * Who is who, for Territories. The shared device is never a person here: it holds no territory, is never
 * given work, and is refused by every route and verb.
 *
 * @package Zorderz\Territories
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The staff roles that can hold a territory or be given work. Never the shared-device role. */
function zter_team_roles() {
	return array( 'administrator', 'zdz_owner', 'zdz_admin', 'zdz_sales', 'zdz_operator', 'zdz_mfg', 'zdz_tech' );
}

function zter_is_kiosk( $uid ) {
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

function zter_is_team_member( $uid ) {
	$uid = (int) $uid;
	if ( $uid <= 0 || zter_is_kiosk( $uid ) ) {
		return false;
	}
	$u = get_userdata( $uid );
	return $u && (bool) array_intersect( (array) $u->roles, zter_team_roles() );
}

/** The admin tier edits territories, zones and rules and sees every decision. */
function zter_is_admin( $uid ) {
	$uid = (int) $uid;
	if ( $uid <= 0 || zter_is_kiosk( $uid ) ) {
		return false;
	}
	if ( class_exists( 'ZDZ_Capabilities' ) && method_exists( 'ZDZ_Capabilities', 'tier_of' ) ) {
		return 'admin' === ZDZ_Capabilities::tier_of( $uid );
	}
	return function_exists( 'user_can' ) && user_can( $uid, 'manage_options' );
}

/** Staff or above as the registry ranks it (sales, the operator, the shop, the admin tier); not a tech, not the shared device. */
function zter_is_staff( $uid ) {
	$uid = (int) $uid;
	if ( $uid <= 0 || zter_is_kiosk( $uid ) ) {
		return false;
	}
	if ( class_exists( 'ZDZ_Capabilities' ) && method_exists( 'ZDZ_Capabilities', 'tier_of' ) ) {
		return in_array( ZDZ_Capabilities::tier_of( $uid ), array( 'admin', 'staff' ), true );
	}
	return zter_is_admin( $uid );
}

/**
 * Reviewers see every decision and why, as the admin tier does, without editing anything. An admin names
 * them on the page (Settings). Never the shared device.
 */
function zter_can_review( $uid ) {
	$uid = (int) $uid;
	if ( $uid <= 0 || zter_is_kiosk( $uid ) ) {
		return false;
	}
	if ( zter_is_admin( $uid ) ) {
		return true;
	}
	$s = ZTER_DB::settings();
	return zter_is_team_member( $uid ) && in_array( $uid, $s['reviewers'], true );
}

/** May this person open the app? Never the shared device; the platform's app access. */
function zter_user_can_use( $uid ) {
	$uid = (int) $uid;
	if ( $uid <= 0 || zter_is_kiosk( $uid ) ) {
		return false;
	}
	if ( class_exists( 'ZDZ_Plugin_API' ) && method_exists( 'ZDZ_Plugin_API', 'user_can_access_app' ) ) {
		return (bool) ZDZ_Plugin_API::user_can_access_app( $uid, ZTER_APP_ID );
	}
	return zter_is_admin( $uid ) || zter_is_team_member( $uid );
}

/** The user meta a person's initials live in (the platform's short code). */
function zter_initials_meta() {
	return class_exists( 'ZDZ_Person_Color' ) && defined( 'ZDZ_Person_Color::META_INITIALS' ) ? ZDZ_Person_Color::META_INITIALS : 'zdz_user_initials';
}

/**
 * The initials a person is routed by: the platform's short code (the same field the estimate app writes and
 * reads). '' when unset or not 2–3 letters: such a person cannot be given work.
 */
function zter_initials( $uid ) {
	$ini = strtoupper( trim( (string) get_user_meta( (int) $uid, zter_initials_meta(), true ) ) );
	return preg_match( '/^[A-Z]{2,3}$/', $ini ) ? $ini : '';
}

/** One person who may be given work: on the team, not the shared device, with routing initials. */
function zter_assignable( $uid ) {
	return zter_is_team_member( $uid ) && '' !== zter_initials( $uid );
}

/** The team member with these initials (case-insensitive). 0 when none, or more than one (ambiguous). */
function zter_user_by_initials( $ini ) {
	$ini = strtoupper( trim( (string) $ini ) );
	if ( ! preg_match( '/^[A-Z0-9]{2,10}$/', $ini ) ) {
		return 0;
	}
	$found = array();
	foreach ( zter_team() as $p ) {
		if ( $p['initials'] === $ini ) {
			$found[] = $p['id'];
		}
	}
	return 1 === count( $found ) ? (int) $found[0] : 0;
}

/**
 * Initials two or more accounts hold. The estimate app writes a live answer only when exactly one account on
 * the whole site holds the initials (a former employee included), so a duplicate makes it keep its own pick.
 * Returns [ { initials, people: [ { id, name, on_team, assignable } ], blocks } ]; `blocks` is true when one of
 * them could be picked by a rule.
 */
function zter_duplicate_initials() {
	$by = array();
	$m  = zter_initials_meta();
	foreach ( (array) get_users( array( 'meta_key' => $m, 'meta_compare' => 'EXISTS', 'fields' => array( 'ID', 'display_name' ), 'number' => 1000 ) ) as $u ) {
		$uid = (int) $u->ID;
		$ini = strtoupper( trim( (string) get_user_meta( $uid, $m, true ) ) );
		if ( '' !== $ini ) {
			$by[ $ini ][] = array( 'id' => $uid, 'name' => (string) $u->display_name, 'on_team' => zter_is_team_member( $uid ), 'assignable' => zter_assignable( $uid ) );
		}
	}
	$out = array();
	foreach ( $by as $ini => $people ) {
		if ( count( $people ) > 1 ) {
			$out[] = array( 'initials' => (string) $ini, 'people' => $people, 'blocks' => (bool) array_filter( $people, function ( $p ) { return $p['assignable']; } ) );
		}
	}
	usort( $out, function ( $a, $b ) { return strcmp( $a['initials'], $b['initials'] ); } );
	return $out;
}

/** Where initials are set: the platform's User Management page (admin tier). */
function zter_user_admin_url() {
	return function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=zdz-user-management' ) : '';
}

/** The whole team: id, display name, routing initials ('' when unset). Once per request. */
function zter_team( $flush = false ) {
	static $memo = null;
	if ( $flush ) {
		$memo = null;
	}
	if ( null !== $memo ) {
		return $memo;
	}
	$out = array();
	foreach ( (array) get_users( array( 'role__in' => zter_team_roles(), 'fields' => array( 'ID', 'display_name' ), 'orderby' => 'display_name', 'number' => 500 ) ) as $u ) {
		$uid = (int) $u->ID;
		if ( ! zter_is_team_member( $uid ) ) {
			continue; // the shared device (even holding a staff role) and anyone without a staff role is not on the team
		}
		$out[] = array( 'id' => $uid, 'name' => (string) $u->display_name, 'initials' => zter_initials( $uid ) );
	}
	$memo = $out;
	return $out;
}

/** The person's tone from ZDZ_Person_Color, or ''. */
function zter_color( $uid ) {
	if ( class_exists( 'ZDZ_Person_Color' ) && method_exists( 'ZDZ_Person_Color', 'for_user' ) ) {
		$p = ZDZ_Person_Color::for_user( (int) $uid );
		if ( is_array( $p ) && ! empty( $p['color'] ) ) {
			return (string) $p['color'];
		}
	}
	return '';
}

/** The ink (text colour) the platform pairs with the person's tone, or ''. */
function zter_ink( $uid ) {
	if ( class_exists( 'ZDZ_Person_Color' ) && method_exists( 'ZDZ_Person_Color', 'for_user' ) ) {
		$p = ZDZ_Person_Color::for_user( (int) $uid );
		if ( is_array( $p ) && ! empty( $p['ink'] ) ) {
			return (string) $p['ink'];
		}
	}
	return '';
}

function zter_first_name( $name ) {
	$parts = preg_split( '/\s+/', trim( (string) $name ) );
	return (string) ( $parts[0] ?? '' );
}

/**
 * May $viewer see Team Roles' notes (the `why`) for $target? Team Roles' own rule (zro_can_view) when it is
 * active: the person, their crew lead, or the admin tier. Without Team Roles there is no `why` to show.
 */
function zter_can_see_roles_row( $viewer, $target ) {
	if ( function_exists( 'zro_can_view' ) ) {
		return (bool) zro_can_view( (int) $viewer, (int) $target );
	}
	return false;
}
