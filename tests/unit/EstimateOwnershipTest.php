<?php
/**
 * Estimates: whose estimate it is, and asking an admin for one that is not yours.
 *
 *   - the creator owns it; a stub Territories routed to someone names them (salesperson column, provenance line) and is
 *     theirs; the operator who typed it in is never named as its salesperson;
 *   - a code names a person only when exactly one account holds it, never the shared device or an operator only;
 *   - the agreements app's "who may record a paper signing" is answered from this app's row;
 *   - access requests: deny by default; only an administrator answers; an approval grants that person that estimate; a
 *     removal wins over everything (even being its creator) until an administrator approves again; the shared device
 *     can neither ask nor be granted.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/estimate-stubs.php';

final class EstimateOwnershipTest extends TestCase {

	const ALEX   = 61; // sales, AA
	const JORDAN = 62; // sales, JJ
	const SAM    = 63; // operator only, SS
	const RILEY  = 64; // the shared device, RR
	const CASEY  = 65; // owner (admin tier), CC
	const QUINN  = 66; // sales, shares the code QQ with Robin
	const ROBIN  = 67; // sales, QQ

	protected function setUp(): void {
		$roles = array(
			self::ALEX => array( 'zdz_sales' ), self::JORDAN => array( 'zdz_sales' ), self::SAM => array( 'zdz_operator' ),
			self::RILEY => array( 'zdz_general' ), self::CASEY => array( 'zdz_owner' ), self::QUINN => array( 'zdz_sales' ), self::ROBIN => array( 'zdz_sales' ),
		);
		$codes = array( self::ALEX => 'AA', self::JORDAN => 'JJ', self::SAM => 'SS', self::RILEY => 'RR', self::CASEY => 'CC', self::QUINN => 'QQ', self::ROBIN => 'QQ' );
		$GLOBALS['zdz_test_user_roles'] = $roles;
		$GLOBALS['zdz_test_user_meta']  = array();
		$GLOBALS['zdz_test_users']      = array();
		foreach ( $roles as $id => $r ) {
			$GLOBALS['zdz_test_user_meta'][ $id ] = array( 'zdz_user_initials' => $codes[ $id ] );
			$GLOBALS['zdz_test_users'][]          = (object) array( 'ID' => $id, 'roles' => $r, 'display_name' => 'Person ' . $id );
		}
		$GLOBALS['zdz_test_options']      = array();
		$GLOBALS['zdz_test_filters']      = array();
		$GLOBALS['zdz_test_current_user'] = self::ALEX;
		$GLOBALS['wpdb']                  = new ZDZ_Test_Est_DB();
		ZEST_Salesperson::flush();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_user_roles'], $GLOBALS['zdz_test_user_meta'], $GLOBALS['zdz_test_users'], $GLOBALS['zdz_test_options'],
			$GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_current_user'], $GLOBALS['wpdb'] );
		ZEST_Salesperson::flush();
	}

	private static function row( array $over = array() ): array {
		return $over + array(
			'id' => 7, 'created_by' => self::ALEX, 'salesperson' => 'AA', 'notes' => '', 'billing_doc_num' => '1001', 'billing_doc_id' => '',
			'items_json' => json_encode( array( array( 'description' => 'Widget', 'sub_description' => '', 'unit_price' => 100, 'quantity' => 1 ) ) ),
		);
	}

	public function test_the_creator_owns_it_and_another_salesperson_does_not(): void {
		$r = self::row();
		$this->assertTrue( ZEST_Salesperson::owned_by( $r, self::ALEX ) );
		$this->assertFalse( ZEST_Salesperson::owned_by( $r, self::JORDAN ) );
		$this->assertTrue( ZEST_Salesperson::owned_by( $r, self::CASEY ), 'the admin tier' );
		$this->assertFalse( ZEST_Salesperson::owned_by( $r, self::RILEY ), 'never the shared device' );
	}

	public function test_a_routed_stub_belongs_to_the_routed_person(): void {
		// an operator typed it in; Territories routed it to Jordan: the column and the location line name Jordan
		$r = self::row( array(
			'created_by'  => self::SAM,
			'salesperson' => 'JJ',
			'items_json'  => json_encode( array( array( 'description' => 'Location', 'sub_description' => 'Exampleton - (JJ)', 'unit_price' => 0, 'quantity' => 1 ) ) ),
		) );
		$this->assertTrue( ZEST_Salesperson::owned_by( $r, self::JORDAN ) );
		$this->assertSame( array( self::JORDAN ), ZEST_Salesperson::for_row( $r )['salesperson_uids'] );
		$this->assertTrue( ZEST_Salesperson::owned_by( $r, self::SAM ), 'the operator still owns what they typed' );
		$this->assertFalse( ZEST_Salesperson::owned_by( $r, self::ALEX ) );
	}

	public function test_the_operator_who_typed_it_is_never_its_salesperson(): void {
		$r = self::row( array( 'created_by' => self::SAM, 'salesperson' => 'SS' ) );
		$this->assertSame( array(), ZEST_Salesperson::for_row( $r )['salesperson_uids'] );
		$this->assertSame( 0, ZEST_Salesperson::person_for_code( 'SS' ), 'an operator only is never named' );
	}

	public function test_a_shared_code_names_nobody(): void {
		$this->assertSame( 0, ZEST_Salesperson::person_for_code( 'QQ' ) );
		$r = self::row( array( 'created_by' => self::SAM, 'salesperson' => 'QQ' ) );
		$this->assertFalse( ZEST_Salesperson::owned_by( $r, self::QUINN ) );
		$this->assertSame( 0, ZEST_Salesperson::person_for_code( 'RR' ), 'never the shared device' );
	}

	public function test_the_agreements_app_is_told_the_rows_salespeople(): void {
		$GLOBALS['wpdb']->rows[7] = self::row( array( 'created_by' => self::SAM, 'salesperson' => 'JJ' ) );
		$this->assertSame( array( self::JORDAN ), ZEST_Salesperson::agreement_salespeople( array(), 7 ) );
		$GLOBALS['wpdb']->rows[8] = self::row( array( 'id' => 8 ) );
		$this->assertSame( array( self::ALEX ), ZEST_Salesperson::agreement_salespeople( array(), 8 ) );
		$this->assertSame( array(), ZEST_Salesperson::agreement_salespeople( array(), 99 ), 'no row, nobody' );
	}

	public function test_the_contact_verb_answers_the_owner_only(): void {
		$GLOBALS['wpdb']->rows[7] = self::row( array( 'customer_name' => 'Morgan Example', 'customer_email' => 'morgan@example.com' ) );
		$mine = ZEST_Salesperson::contact_for( array( 'user_id' => self::ALEX, 'ref' => 'estimate:7' ) );
		$this->assertSame( 'Morgan', $mine['first_name'] );
		$other = ZEST_Salesperson::contact_for( array( 'user_id' => self::JORDAN, 'ref' => 'estimate:7' ) );
		$this->assertTrue( $other['denied'] );
		$this->assertArrayNotHasKey( 'email', $other );
		$this->assertTrue( ZEST_Salesperson::contact_for( array( 'user_id' => self::RILEY, 'is_kiosk' => true, 'ref' => 'estimate:7' ) )['denied'] );
	}

	/* ── asking an admin ─────────────────────────────────────────────── */

	public function test_access_is_denied_until_an_administrator_approves(): void {
		$r = self::row( array( 'created_by' => self::JORDAN, 'salesperson' => 'JJ' ) );
		$this->assertFalse( ZEST_Salesperson::owned_by( $r, self::ALEX ) );
		$this->assertFalse( ZEST_Access_Requests::has_grant( self::ALEX, 7 ) );
		// nobody can be told: the request is not kept and grants nothing. No administrator is on the list to ask, so
		// this holds whichever delivery classes (messaging, the alert router) other test files have loaded.
		$GLOBALS['zdz_test_filters']['zest_access_request_recipients'] = function () { return array(); };
		$res = ZEST_Access_Requests::create( self::ALEX, 7, '1001', array( 'JJ' ), 'Measured it for them' );
		$this->assertFalse( $res['ok'] );
		$this->assertFalse( ZEST_Access_Requests::has_grant( self::ALEX, 7 ) );
	}

	public function test_an_administrator_approves_then_removes_access(): void {
		$GLOBALS['zdz_test_options'][ ZEST_Access_Requests::OPT ] = array(
			'abc123' => array( 'uid' => self::ALEX, 'row' => 7, 'num' => '1001', 'initials' => array( 'JJ' ), 'note' => '', 'at' => time(), 'status' => 'pending', 'asked' => array( self::CASEY ) ),
		);
		$this->assertFalse( ZEST_Access_Requests::decide( 'abc123', self::JORDAN, 'approve' )['ok'], 'only an administrator answers' );
		$this->assertFalse( ZEST_Access_Requests::decide( 'abc123', self::RILEY, 'approve' )['ok'] );
		$this->assertTrue( ZEST_Access_Requests::decide( 'abc123', self::CASEY, 'approve' )['ok'] );
		$this->assertTrue( ZEST_Access_Requests::has_grant( self::ALEX, 7 ) );
		$r = self::row( array( 'created_by' => self::JORDAN, 'salesperson' => 'JJ' ) );
		$this->assertTrue( ZEST_Salesperson::owned_by( $r, self::ALEX ) );
		$this->assertSame( array( 7 ), ZEST_Access_Requests::granted_rows( self::ALEX ) );
		$this->assertFalse( ZEST_Access_Requests::decide( 'abc123', self::CASEY, 'approve' )['ok'], 'answered once' );

		$this->assertTrue( ZEST_Access_Requests::decide( 'abc123', self::CASEY, 'revoke' )['ok'] );
		$this->assertFalse( ZEST_Access_Requests::has_grant( self::ALEX, 7 ) );
		$this->assertTrue( ZEST_Access_Requests::is_revoked( self::ALEX, 7 ) );
		$mine = self::row( array( 'created_by' => self::ALEX ) );
		$this->assertFalse( ZEST_Salesperson::owned_by( $mine, self::ALEX ), 'a removal wins even over being its creator' );
	}

	public function test_a_declined_request_grants_nothing_and_the_shared_device_can_neither_ask_nor_hold_a_grant(): void {
		$GLOBALS['zdz_test_options'][ ZEST_Access_Requests::OPT ] = array(
			'def456' => array( 'uid' => self::ALEX, 'row' => 7, 'num' => '1001', 'initials' => array(), 'note' => '', 'at' => time(), 'status' => 'pending', 'asked' => array() ),
		);
		$this->assertTrue( ZEST_Access_Requests::decide( 'def456', self::CASEY, 'decline' )['ok'] );
		$this->assertFalse( ZEST_Access_Requests::has_grant( self::ALEX, 7 ) );
		$this->assertFalse( ZEST_Access_Requests::create( self::RILEY, 7, '1001', array(), '' )['ok'] );
		$GLOBALS['zdz_test_user_meta'][ self::RILEY ][ ZEST_Access_Requests::META ] = array( 'row:7' => array( 'row' => 7 ) );
		$this->assertFalse( ZEST_Access_Requests::has_grant( self::RILEY, 7 ) );
	}

	public function test_only_the_admin_tier_is_asked_never_the_asker(): void {
		$who = ZEST_Access_Requests::recipients( self::CASEY );
		$this->assertNotContains( self::CASEY, $who );
		foreach ( $who as $id ) {
			$this->assertTrue( ZEST_Access_Requests::is_admin_tier( $id ) );
		}
		$this->assertContains( self::CASEY, ZEST_Access_Requests::recipients( self::ALEX ) );
	}
}
