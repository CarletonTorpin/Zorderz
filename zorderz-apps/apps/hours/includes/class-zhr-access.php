<?php
/**
 * ZHR_Access -- who may see or change whose hours. Deny by default: nobody sees another person's hours unless a rung of
 * the ladder below lets them, and the server decides it on every request (what the screen hides is never the boundary).
 *
 *   signed out, the shared device, demo mode      -> nothing, not even their own
 *   not holding the Hours app                     -> nothing
 *   their own hours                               -> see, and change within the edit window
 *   the admin tier (ZDZ_Capabilities::tier_of)    -> see and change anyone's hours (never the shared device's), any
 *                                                    date (the full view)
 *   a crew lead, when the business turned it on   -> see their own crew's hours as the person sees them; never change
 *                                                    them, never the office's view (ZDZ_Hierarchy decides the crew)
 *   anyone else                                   -> nothing ("not found", never whose it is)
 *
 * decide() is pure: the facts are gathered by the caller (app.php's zhr_* helpers), so the ladder is tested on its own.
 *
 * @package Zorderz\Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZHR_Access {

	/**
	 * $f: viewer (int), target (int), action ('view' | 'change' | 'export'), viewer_shared (bool: the shared device or
	 * demo mode), viewer_holds (bool: may open Hours), viewer_admin (bool), target_ok (bool: a real person, not the shared
	 * device; someone who no longer holds Hours still has past hours the office reads), leads_target (bool: the viewer is the target's crew lead), crew_leads (bool: the
	 * business lets crew leads read their crew's hours).
	 * Returns ['allow' => bool, 'full' => bool (the office's view: evidence, places, history), 'why' => '' | code].
	 */
	public static function decide( array $f ) {
		$viewer = (int) ( $f['viewer'] ?? 0 );
		$target = (int) ( $f['target'] ?? 0 );
		$action = in_array( (string) ( $f['action'] ?? 'view' ), array( 'view', 'change', 'export' ), true ) ? (string) $f['action'] : 'change';
		$no     = function ( $why ) { return array( 'allow' => false, 'full' => false, 'why' => $why ); };
		if ( $viewer <= 0 ) {
			return $no( 'signed_out' );
		}
		if ( ! empty( $f['viewer_shared'] ) ) {
			return $no( 'shared_device' );
		}
		if ( empty( $f['viewer_holds'] ) ) {
			return $no( 'no_app' );
		}
		if ( $target <= 0 ) {
			return $no( 'no_person' );
		}
		$admin = ! empty( $f['viewer_admin'] );
		if ( $viewer === $target ) {
			return array( 'allow' => true, 'full' => $admin, 'why' => '' );
		}
		if ( empty( $f['target_ok'] ) ) {
			return $no( 'no_person' );
		}
		if ( $admin ) {
			return array( 'allow' => true, 'full' => true, 'why' => '' );
		}
		if ( 'view' === $action && ! empty( $f['crew_leads'] ) && ! empty( $f['leads_target'] ) ) {
			return array( 'allow' => true, 'full' => false, 'why' => '' );
		}
		return $no( 'not_yours' );
	}
}
