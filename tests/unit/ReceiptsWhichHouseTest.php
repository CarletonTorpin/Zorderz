<?php
/**
 * "Which house?" (ZRCPT_Houses), with neutral addresses and points:
 *
 *   - place(): only this house in reach is sure; this house and a neighbour is "Which house?"; another house only is
 *     not this one; nothing near is off site (allowed only with "Include anyway"); a camera job of this Project is
 *     sure and another job's never appears; a person's filing decides; no location is allowed unless a neighbour had
 *     work that day; a list with no house of its own asks when two houses compete;
 *   - set_status() and gate_reason() (unknown fails closed);
 *   - matching addresses: the same house by number, street, unit and postcode, never by distance; a duplex's other
 *     unit is a neighbour; address_relation(); merge() folds a duplicate into this house without adopting its keys;
 *   - observed_point(); the picker's houses carry no coordinates.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/receipts/includes/class-zrcpt-houses.php';

class ReceiptsWhichHouseTest extends TestCase {

	/* A point and metres east of it (at this latitude one degree of longitude is ~ 86 km). */
	const LAT = 37.0;
	const LNG = -100.0;

	private function east( float $m ): float {
		return self::LNG + $m / ( 111320.0 * cos( deg2rad( self::LAT ) ) );
	}

	private function target( array $over = array() ): array {
		return ZRCPT_Houses::house( array_merge( array(
			'key' => 'inv:1001', 'keys' => array( 'inv:1001', 'prj:P1', 'job:7' ), 'address' => '12 Example St, Springfield, 11111',
			'lat' => self::LAT, 'lng' => self::LNG, 'precision' => 'rooftop', 'project_ids' => array( 'P1' ), 'is_target' => true,
		), $over ) );
	}

	private function neighbour( float $m, array $over = array() ): array {
		return ZRCPT_Houses::house( array_merge( array(
			'key' => 'job:9', 'address' => '14 Example St, Springfield, 11111', 'lat' => self::LAT, 'lng' => $this->east( $m ), 'precision' => 'rooftop',
		), $over ) );
	}

	private function photo( float $m, ?float $acc = 5.0, array $over = array() ): array {
		return array_merge( array( 'media_id' => 1, 'gps_lat' => self::LAT, 'gps_lng' => $this->east( $m ), 'gps_accuracy' => $acc ), $over );
	}

	public function test_only_this_house_in_reach_is_sure() {
		$pl = ZRCPT_Houses::place( $this->photo( 2 ), $this->target(), array( $this->neighbour( 200 ) ) );
		$this->assertSame( 'near_this', $pl['status'] );
		$this->assertSame( 2, $pl['dist_m'] );
	}

	public function test_two_neighbours_in_reach_is_which_house() {
		$pl = ZRCPT_Houses::place( $this->photo( 8, 10 ), $this->target(), array( $this->neighbour( 15 ) ) );
		$this->assertSame( 'which_house', $pl['status'] );
		$this->assertSame( array( 'job:9' ), $pl['competitors'] );
		// A rough (street-level) point for this house widens its circle, and the neighbour still competes.
		$pl = ZRCPT_Houses::place( $this->photo( 40, 10 ), $this->target( array( 'precision' => 'interpolated' ) ), array( $this->neighbour( 45 ) ) );
		$this->assertSame( 'which_house', $pl['status'] );
	}

	public function test_another_house_only_is_not_this_one_and_nothing_near_is_off_site() {
		$pl = ZRCPT_Houses::place( $this->photo( 100, 5 ), $this->target(), array( $this->neighbour( 100 ) ) );
		$this->assertSame( 'near_other', $pl['status'] );
		$this->assertSame( 'other_house', ZRCPT_Houses::gate_reason( $pl, true ) );
		$far = ZRCPT_Houses::place( $this->photo( 900, 5 ), $this->target(), array() );
		$this->assertSame( 'off_site', $far['status'] );
		$this->assertSame( 'off_site', ZRCPT_Houses::gate_reason( $far, false ) );
		$this->assertSame( '', ZRCPT_Houses::gate_reason( $far, true ) );
		// Away from this house with nobody else near, within the wide on-site circle: still this house.
		$this->assertSame( 'near_this', ZRCPT_Houses::place( $this->photo( 150, 5 ), $this->target(), array() )['status'] );
		// Away from this house and another house nearby: a person decides.
		$this->assertSame( 'which_house', ZRCPT_Houses::place( $this->photo( 150, 5 ), $this->target(), array( $this->neighbour( 250 ) ) )['status'] );
	}

	public function test_the_camera_job_and_a_persons_filing_decide_first() {
		$t = $this->target();
		$this->assertSame( 'job_this', ZRCPT_Houses::place( $this->photo( 15, 5, array( 'job_id' => 7 ) ), $t, array( $this->neighbour( 15 ) ), array( 7 => 'this' ) )['status'] );
		$this->assertSame( 'job_other', ZRCPT_Houses::place( $this->photo( 0, 5, array( 'job_id' => 9 ) ), $t, array(), array( 9 => 'other' ) )['status'] );
		$this->assertSame( 'job_unverified', ZRCPT_Houses::place( $this->photo( 0, 5, array( 'job_id' => 8 ) ), $t, array(), array( 8 => 'unverified' ) )['status'] );
		$filed = ZRCPT_Houses::place( $this->photo( 15, 5 ), $t, array( $this->neighbour( 15 ) ), array(), array( 'house_key' => 'inv:1001' ) );
		$this->assertSame( 'filed_this', $filed['status'] );
		$other = ZRCPT_Houses::place( $this->photo( 0, 5 ), $t, array(), array(), array( 'house_key' => 'job:9' ) );
		$this->assertSame( 'filed_other', $other['status'] );
		// "Neither", an unbound key, or the bare self key is never anyone's house.
		$this->assertSame( 'filed_other', ZRCPT_Houses::place( $this->photo( 0, 5 ), $t, array(), array(), array( 'house_key' => 'self:house' ) )['status'] );
		$this->assertSame( 'filed_this', ZRCPT_Houses::place( $this->photo( 0, 5 ), $t, array(), array(), array( 'house_key' => 'x', 'house_address' => '12 example street, Springfield, 11111' ) )['status'] );
	}

	public function test_no_location_is_allowed_unless_a_neighbour_had_work_that_day() {
		$p = array( 'media_id' => 1 );
		$this->assertSame( 'unlocated', ZRCPT_Houses::place( $p, $this->target(), array( $this->neighbour( 900 ) ) )['status'] );
		$this->assertSame( 'which_house', ZRCPT_Houses::place( $p, $this->target(), array( $this->neighbour( 30 ) ) )['status'] );
	}

	public function test_a_list_with_no_house_of_its_own_asks_when_two_compete() {
		$this->assertSame( 'which_house', ZRCPT_Houses::place( $this->photo( 10, 10 ), null, array( $this->neighbour( 0, array( 'key' => 'job:1', 'address' => '1 A St' ) ), $this->neighbour( 15, array( 'key' => 'job:2', 'address' => '3 A St' ) ) ) )['status'] );
		$this->assertSame( 'near_other', ZRCPT_Houses::place( $this->photo( 0, 5 ), null, array( $this->neighbour( 0 ) ) )['status'] );
		$this->assertSame( 'no_anchor', ZRCPT_Houses::place( $this->photo( 0, 5 ), null, array() )['status'] );
	}

	public function test_set_status_and_the_gate_fail_closed() {
		$s = function ( ...$st ) { return array_map( function ( $x ) { return array( 'status' => $x ); }, $st ); };
		$this->assertSame( 'this_house', ZRCPT_Houses::set_status( $s( 'near_this', 'job_this', 'filed_this' ) ) );
		$this->assertSame( 'which_house', ZRCPT_Houses::set_status( $s( 'near_this', 'which_house' ) ) );
		$this->assertSame( 'off_site', ZRCPT_Houses::set_status( $s( 'near_this', 'off_site' ) ) );
		$this->assertSame( 'filed_other', ZRCPT_Houses::set_status( $s( 'filed_other', 'job_other' ) ) );
		$this->assertSame( 'unlocated', ZRCPT_Houses::set_status( $s( 'unlocated', 'near_this' ) ) );
		$this->assertSame( 'unlocated', ZRCPT_Houses::set_status( array() ) );
		$this->assertSame( 'which_house', ZRCPT_Houses::gate_reason( array( 'status' => 'something new' ), true ) );
		$this->assertSame( 'other_job', ZRCPT_Houses::gate_reason( array( 'status' => 'job_other' ), true ) );
		$this->assertSame( '', ZRCPT_Houses::gate_reason( array( 'status' => 'unlocated' ), false ) );
		$msg = ZRCPT_Houses::refusal_message( array( 'which_house' => 2, 'off_site' => 1 ) );
		$this->assertStringContainsString( '2 photo(s) still need', $msg );
		$this->assertStringContainsString( 'Include anyway', $msg );
	}

	public function test_the_same_house_by_address_never_by_distance() {
		$this->assertTrue( ZRCPT_Houses::same_address( '12 Example Street, Springfield, 11111', '12 example st., Springfield 11111' ) );
		$this->assertFalse( ZRCPT_Houses::same_address( '12 Example St, Springfield', '14 Example St, Springfield' ) );
		$this->assertFalse( ZRCPT_Houses::same_address( '12 Example St Apt A, Springfield', '12 Example St Apt B, Springfield' ) );   // a duplex
		$this->assertFalse( ZRCPT_Houses::same_address( '12 Example St, Springfield, 11111', '12 Example St, Riverton, 22222' ) );
		$this->assertFalse( ZRCPT_Houses::same_address( '12 Example Ct, Springfield', '12 Example Dr, Springfield' ) );
		$this->assertTrue( ZRCPT_Houses::same_address( '12 Example St, #B, Springfield', '12 Example St Unit B, Springfield' ) );
		$this->assertSame( 'conflict', ZRCPT_Houses::address_relation( '12 Example St', '14 Example St' ) );
		$this->assertSame( 'unknown', ZRCPT_Houses::address_relation( '12 Example Trl, Springfield', '12 Example St, Springfield' ) );
		$this->assertSame( 'same', ZRCPT_Houses::address_relation( '12 Example St', '12 EXAMPLE STREET' ) );
		$this->assertNull( ZRCPT_Houses::street_key( 'Springfield' ) );
		$k = ZRCPT_Houses::street_key( '12 Example St, Springfield AB 1A2 B3C' );
		$this->assertSame( '12', $k['num'] );
		$this->assertSame( 'springfield', $k['city'] );
	}

	public function test_merge_folds_a_duplicate_into_this_house_without_its_keys() {
		$t   = $this->target( array( 'precision' => 'interpolated' ) );
		$dup = ZRCPT_Houses::house( array( 'key' => 'receipt:5', 'address' => '12 Example Street, Springfield, 11111', 'lat' => self::LAT, 'lng' => $this->east( 3 ), 'precision' => 'observed' ) );
		$nb  = $this->neighbour( 15 );
		$m   = ZRCPT_Houses::merge( array( $dup, $nb, ZRCPT_Houses::house( array( 'key' => 'receipt:6', 'keys' => array( 'job:9' ), 'lat' => self::LAT, 'lng' => $this->east( 16 ) ) ) ), $t );
		$this->assertSame( 'observed', $m['target']['precision'] );          // the better point won
		$this->assertNotContains( 'receipt:5', $m['target']['keys'] );       // but not the other source's key
		$this->assertCount( 1, $m['others'] );                               // two sources of one neighbour merge on a shared key
		$this->assertContains( 'receipt:6', $m['others'][0]['keys'] );
	}

	public function test_observed_point_and_the_public_house() {
		$o = ZRCPT_Houses::observed_point( array( $this->photo( 0, 5 ), $this->photo( 10, 5 ), $this->photo( 2, 80 ) ) );
		$this->assertSame( 2, $o['n'] );
		$this->assertSame( 'observed', $o['precision'] );
		$this->assertNull( ZRCPT_Houses::observed_point( array( $this->photo( 0, 5 ) ) ) );
		$pub = ZRCPT_Houses::house_public( $this->neighbour( 15 ), 'B' );
		$this->assertArrayNotHasKey( 'lat', $pub );
		$this->assertArrayNotHasKey( 'lng', $pub );
		$this->assertSame( '14 Example St', $pub['address'] );
		$houses = ZRCPT_Houses::houses_for_set(
			array( array( 'day' => '2026-03-02', 'competitors' => array( 'job:9' ), 'reach' => array() ) ),
			array( '2026-03-02' => array( $this->neighbour( 15 ), $this->neighbour( 900, array( 'key' => 'job:4' ) ) ) ),
			$this->target()
		);
		$this->assertSame( array( 'A', 'B' ), array_column( $houses, 'letter' ) );
		$this->assertTrue( $houses[0]['is_target'] );
		$this->assertSame( 'job:9', $houses[1]['key'] );
	}
}
