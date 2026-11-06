<?php
/**
 * Prep remakes and per-job assignment: who may assign what to whom, which queue cards a
 * person sees, who may revoke, and how a remake request is validated. Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

final class PrepRemakesAssignmentsTest extends TestCase {

	public static function setUpBeforeClass(): void {
		$dir = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/prep/includes/';
		require_once $dir . 'class-zprep-assignments.php';
		require_once $dir . 'class-zprep-remakes.php';
	}

	public function test_assign_decisions(): void {
		$d = array( 'ZPREP_Assignments', 'decide_create' );
		$this->assertSame( 'forbidden', $d( false, 'est:1', true, false, true, 0, 5 ) );
		$this->assertSame( 'no_job', $d( true, ' ', true, false, true, 0, 5 ) );
		$this->assertSame( 'no_user', $d( true, 'est:1', false, false, true, 0, 5 ) );
		$this->assertSame( 'no_user', $d( true, 'est:1', true, false, true, 0, 0 ) );
		$this->assertSame( 'shared_device', $d( true, 'est:1', true, true, true, 0, 5 ), 'the shared device is never given work' );
		$this->assertSame( 'not_able', $d( true, 'est:1', true, false, false, 0, 5 ) );
		$this->assertSame( 'unchanged', $d( true, 'est:1', true, false, true, 5, 5 ) );
		$this->assertSame( 'reassign', $d( true, 'est:1', true, false, true, 6, 5 ) );
		$this->assertSame( 'assign', $d( true, 'est:1', true, false, true, 0, 5 ) );
	}

	public function test_card_visibility_by_mode(): void {
		$v = array( 'ZPREP_Assignments', 'card_visible' );
		$this->assertTrue( $v( 'manager', 1, 0 ) );
		$this->assertTrue( $v( 'manager', 1, 9 ), 'managers see every card' );
		$this->assertTrue( $v( 'team', 2, 0 ) );
		$this->assertTrue( $v( 'team', 2, 2 ) );
		$this->assertFalse( $v( 'team', 2, 9 ), 'a job assigned to someone else is hidden' );
		$this->assertTrue( $v( 'assignee', 3, 3 ) );
		$this->assertFalse( $v( 'assignee', 3, 0 ), 'an assignee sees only their own jobs' );
		$this->assertFalse( $v( 'assignee', 3, 9 ) );
		$this->assertFalse( $v( 'none', 4, 0 ) );
		$this->assertFalse( $v( 'something-new', 4, 0 ), 'an unknown mode sees nothing' );
	}

	public function test_revoke_rules(): void {
		$r = array( 'ZPREP_Assignments', 'revoke_allowed' );
		$this->assertTrue( $r( 5, 5, false, false ), 'the person who assigned it' );
		$this->assertTrue( $r( 6, 5, true, false ), 'any manager' );
		$this->assertFalse( $r( 6, 5, false, false ) );
		$this->assertFalse( $r( 5, 5, true, true ), 'never twice' );
		$this->assertFalse( $r( 0, 0, true, false ) );
	}

	public function test_job_keys(): void {
		$this->assertSame( array( 'est:1001', 'lead:7' ), ZPREP_Assignments::card_keys( array( 'estimate_number' => '1001', 'lead_id' => 7 ) ) );
		$this->assertSame( array( 'remake:4' ), ZPREP_Assignments::card_keys( array( 'job_key' => 'remake:4' ) ) );
		$this->assertSame( 'est:1001', ZPREP_Assignments::job_key_for( 7, '1001' ) );
		$this->assertSame( 'lead:7', ZPREP_Assignments::job_key_for( 7, '' ) );
		$this->assertSame( 'remake:4', ZPREP_Assignments::job_key_for( 0, 'remake:4' ) );
	}

	public function test_remake_request_is_validated(): void {
		$colors  = array( 'dark', 'light' );
		$widths  = array( 30, 48 );
		$reasons = array( 'material_change', 'miscut', 'damage', 'other' );
		$this->assertSame( 'no_customer', ZPREP_Remakes::normalize( array( 'pieces' => array( array( 'qty' => 1 ) ) ), $colors, $widths, $reasons )['error'] );
		$this->assertSame( 'no_pieces', ZPREP_Remakes::normalize( array( 'customer_label' => 'Example Co', 'pieces' => array( array( 'qty' => 0 ) ) ), $colors, $widths, $reasons )['error'] );
		$n = ZPREP_Remakes::normalize(
			array(
				'customer_label' => 'Example Co', 'reason_code' => 'invented', 'color' => 'purple', 'force_roll' => 99,
				'parent_job_key' => 'est:1001"><b>', 'pieces' => array(
					array( 'qty' => 2, 'kind' => 'Panel-A!', 'width' => '12.345', 'height' => 'x', 'color' => 'LIGHT' ),
					array( 'qty' => -1 ),
				),
			),
			$colors,
			$widths,
			$reasons
		)['row'];
		$this->assertSame( 'other', $n['reason_code'], 'an unknown reason becomes other' );
		$this->assertSame( '', $n['color'], 'only a configured colour' );
		$this->assertSame( 0, $n['force_roll'], 'only a configured width, else automatic' );
		$this->assertSame( '', $n['parent_job_key'], 'a malformed parent key is dropped' );
		$this->assertCount( 1, $n['pieces'] );
		$this->assertSame( array( 'panela', 2, 12.35, '', 'light' ), array( $n['pieces'][0]['kind'], $n['pieces'][0]['qty'], $n['pieces'][0]['width'], $n['pieces'][0]['height'], $n['pieces'][0]['color'] ) );
		$ok = ZPREP_Remakes::normalize( array( 'customer_label' => 'Example Co', 'force_roll' => 48, 'color' => 'dark', 'parent_job_key' => 'lead:77', 'pieces' => array( array( 'qty' => 1 ) ) ), $colors, $widths, $reasons )['row'];
		$this->assertSame( array( 48, 'dark', 'lead:77' ), array( $ok['force_roll'], $ok['color'], $ok['parent_job_key'] ) );
	}

	public function test_remake_rows_become_engine_measurements(): void {
		$row = array( 'color' => 'light', 'measurements' => zdz_test_prep_json( array(
			array( 'kind' => 'custom', 'qty' => 3, 'width' => 10, 'height' => '', 'shape' => 'rectangle', 'color' => '' ),
			array( 'kind' => 'custom', 'qty' => 0, 'width' => 5, 'height' => 5, 'shape' => 'circle', 'color' => 'dark' ),
		) ) );
		$m = ZPREP_Remakes::measurements( $row, 'dark' );
		$this->assertSame( array( 3, 10.0, null, 'light' ), array( $m[0]['qty'], $m[0]['width_in'], $m[0]['height_in'], $m[0]['color'] ), 'a row without a colour takes the remake colour' );
		$this->assertSame( array( 1, 'dark', 'circle' ), array( $m[1]['qty'], $m[1]['color'], $m[1]['shape'] ) );
		$this->assertSame( 'dark', ZPREP_Remakes::measurements( array( 'color' => '', 'measurements' => '[{"qty":1}]' ), 'dark' )[0]['color'], 'else the default colour' );
	}

	public function test_remake_job_keys_round_trip(): void {
		$this->assertSame( 'remake:12', ZPREP_Remakes::job_key( 12 ) );
		$this->assertSame( 12, ZPREP_Remakes::id_from_job_key( 'remake:12' ) );
		$this->assertSame( 0, ZPREP_Remakes::id_from_job_key( 'est:12' ) );
	}
}

if ( ! function_exists( 'zdz_test_prep_json' ) ) {
	function zdz_test_prep_json( $v ) { return json_encode( $v ); }
}
