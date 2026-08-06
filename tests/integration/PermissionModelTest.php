<?php
/**
 * The permission model must FAIL CLOSED: a user whose role is not a recognized Zorderz role
 * resolves to all-deny. Every downstream gate (KPI revenue, chat egress, kiosk) trusts this.
 * A disguised "restore access for role X during migration" fix that changes the unknown-role
 * branch to a permissive default would silently grant near-anonymous accounts cross-employee
 * data access. This test pins the invariant.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

final class PermissionModelTest extends WP_UnitTestCase {

	/** A built-in low-privilege role (subscriber) is not a Zorderz role -> all deny. */
	public function test_subscriber_resolves_to_all_deny(): void {
		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$resolved = ZDZ_Data_Permissions::resolve( $uid );

		$this->assertNotEmpty( $resolved, 'resolve() should return the permission map, not empty.' );
		foreach ( $resolved as $perm => $decision ) {
			$this->assertSame( 'deny', $decision, "Subscriber must be denied '{$perm}'." );
		}
		$this->assertFalse(
			ZDZ_Data_Permissions::can( $uid, 'view_others_data' ),
			'A subscriber must not be able to view other people\'s data.'
		);
		$this->assertFalse(
			ZDZ_Data_Permissions::can( $uid, 'view_company_revenue' ),
			'A subscriber must not be able to view company revenue.'
		);
	}

	/** An entirely unknown role string must also fail closed. */
	public function test_unknown_role_resolves_to_all_deny(): void {
		$uid = self::factory()->user->create();
		$u   = new WP_User( $uid );
		$u->set_role( 'some_unregistered_role' );

		$resolved = ZDZ_Data_Permissions::resolve( $uid );
		foreach ( $resolved as $perm => $decision ) {
			$this->assertSame( 'deny', $decision, "Unknown role must be denied '{$perm}'." );
		}
	}

	/** Sanity check the harness: an administrator is NOT all-deny (so the test can tell them apart). */
	public function test_administrator_is_not_all_deny(): void {
		$uid      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$resolved = ZDZ_Data_Permissions::resolve( $uid );
		$granted  = array_filter( $resolved, static function ( $d ) { return 'deny' !== $d; } );
		$this->assertNotEmpty( $granted, 'An administrator should have at least one non-deny permission.' );
	}
}
