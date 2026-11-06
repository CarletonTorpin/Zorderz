<?php
/**
 * Estimates: finding the customer in the billing system's client list.
 *
 *   - the shared name matcher finds a nickname, a typo in the surname and a surname that sounds the same; a stranger is
 *     not offered; archived clients never are; five at most, best first;
 *   - a name alone only suggests: filling the customer in by itself needs the same email or phone;
 *   - below the admin tier a suggestion keeps its email, phone and street only when the search carried that email or
 *     phone, or the person owns an estimate for that client; otherwise name and town only, and only when the name matches;
 *   - after a parse, a look-alike name is dropped when the note's own email or phone says the customer is someone else
 *     (a household with the same surname, and a matching contact, are kept).
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/estimate-stubs.php';

final class EstimateClientMatchTest extends TestCase {

	private static function c( int $id, string $f, string $l, string $email = '', string $phone = '', int $vis = 0 ): array {
		return array( 'id' => $id, 'fname' => $f, 'lname' => $l, 'email' => $email, 'mob_phone' => $phone, 'home_phone' => '', 'bus_phone' => '',
			'p_street' => $id . ' Example Way', 'p_street2' => '', 'p_city' => 'Exampleton', 'p_province' => 'ST', 'p_code' => '00000', 'vis_state' => $vis );
	}

	private static function clients(): array {
		return array(
			self::c( 1, 'Robert', 'Example', 'robert@example.com', '555-555-0101' ),
			self::c( 2, 'Morgan', 'Exampel', '', '' ),
			self::c( 3, 'Morgan', 'Reade', 'morgan.r@example.com', '' ),
			self::c( 4, 'Casey', 'Other', 'casey@example.com', '555-555-0104' ),
			self::c( 5, 'Bob', 'Example', '', '', 1 ),
			self::c( 6, 'Blake', 'Example', 'blake@example.com', '' ),
		);
	}

	private static function ids( array $res ): array {
		return array_map( static function ( $r ) { return (int) $r['id']; }, $res );
	}

	public function test_a_nickname_a_typo_and_a_soundalike_are_found_and_a_stranger_is_not(): void {
		$res = ZEST_Client_Index::rank( self::clients(), array( 'fname' => 'Bob', 'lname' => 'Example' ) );
		$this->assertSame( 1, (int) $res[0]['id'], 'the nickname with the surname first' );
		$this->assertContains( 6, self::ids( $res ), 'the same surname is still a suggestion' );
		$this->assertNotContains( 4, self::ids( $res ) );
		$this->assertNotContains( 5, self::ids( $res ), 'an archived client is never offered' );

		$this->assertContains( 2, self::ids( ZEST_Client_Index::rank( self::clients(), array( 'fname' => 'Morgan', 'lname' => 'Example' ) ) ), 'a surname typo' );
		$this->assertContains( 3, self::ids( ZEST_Client_Index::rank( self::clients(), array( 'fname' => 'Morgan', 'lname' => 'Reid' ) ) ), 'a surname that sounds the same' );
		$this->assertSame( array(), ZEST_Client_Index::rank( self::clients(), array( 'fname' => 'Quinn', 'lname' => 'Nobody' ) ) );
	}

	public function test_a_name_alone_only_suggests_and_a_contact_fills_in(): void {
		foreach ( ZEST_Client_Index::rank( self::clients(), array( 'fname' => 'Robert', 'lname' => 'Example' ) ) as $r ) {
			$this->assertFalse( $r['auto_fill'], 'name only: ' . $r['id'] );
		}
		$by_email = ZEST_Client_Index::rank( self::clients(), array( 'email' => 'Casey@Example.com' ) );
		$this->assertSame( 4, (int) $by_email[0]['id'] );
		$this->assertTrue( $by_email[0]['auto_fill'] );
		$by_phone = ZEST_Client_Index::rank( self::clients(), array( 'phone' => '(555) 555-0101' ) );
		$this->assertSame( 1, (int) $by_phone[0]['id'] );
		$this->assertTrue( $by_phone[0]['auto_fill'] );
	}

	public function test_five_at_most_best_first(): void {
		$many = array();
		for ( $i = 1; $i <= 9; $i++ ) {
			$many[] = self::c( $i, 'Sam', 'Example' );
		}
		$many[] = self::c( 99, 'Sam', 'Example', 'sam@example.com' );
		$res = ZEST_Client_Index::rank( $many, array( 'fname' => 'Sam', 'lname' => 'Example', 'email' => 'sam@example.com' ) );
		$this->assertCount( 5, $res );
		$this->assertSame( 99, (int) $res[0]['id'] );
	}

	public function test_below_the_admin_tier_contact_details_are_withheld_unless_the_search_carried_them(): void {
		$ranked = ZEST_Client_Index::rank( self::clients(), array( 'fname' => 'Bob', 'lname' => 'Example' ) );
		$this->assertSame( $ranked, ZEST_Client_Index::withhold( $ranked, array( 'fname' => 'Bob', 'lname' => 'Example' ), true ) );

		$out = ZEST_Client_Index::withhold( $ranked, array( 'fname' => 'Bob', 'lname' => 'Example' ), false );
		foreach ( $out as $r ) {
			$this->assertTrue( $r['withheld'] );
			$this->assertSame( '', $r['email'] );
			$this->assertSame( '', $r['phone'] );
			$this->assertSame( '', $r['street'] );
			$this->assertSame( 'Exampleton', $r['city'] );
			$this->assertFalse( $r['auto_fill'] );
		}

		$mine = ZEST_Client_Index::withhold( $ranked, array( 'fname' => 'Bob', 'lname' => 'Example' ), false, static function ( string $cid ) { return '1' === $cid; } );
		$this->assertSame( 'robert@example.com', $mine[0]['email'], 'the person owns an estimate for this client' );

		$by_email = ZEST_Client_Index::rank( self::clients(), array( 'email' => 'casey@example.com' ) );
		$this->assertSame( 'casey@example.com', ZEST_Client_Index::withhold( $by_email, array( 'email' => 'casey@example.com' ), false )[0]['email'] );

		// a contact search below the admin tier never lists strangers by name
		$wide = ZEST_Client_Index::rank( self::clients(), array( 'email' => 'casey@example.com', 'lname' => 'Example' ) );
		foreach ( ZEST_Client_Index::withhold( $wide, array( 'email' => 'casey@example.com' ), false ) as $r ) {
			$this->assertSame( 4, (int) $r['id'] );
		}
	}

	public function test_after_a_parse_a_lookalike_is_dropped_when_the_notes_contact_says_someone_else(): void {
		$stranger = array( 'id' => '7', 'fname' => 'Devin', 'lname' => 'Examples', 'email' => 'devin@example.com', 'phone' => '' );
		$this->assertTrue( ZEST_Client_Index::weak_name_only( $stranger, 'Robin', 'Example', 'robin@example.com', '' ) );
		$this->assertFalse( ZEST_Client_Index::weak_name_only( $stranger, 'Robin', 'Example', '', '' ), 'nothing in the note to tell them apart' );
		$household = array( 'id' => '8', 'fname' => 'Riley', 'lname' => 'Example', 'email' => 'riley@example.com', 'phone' => '' );
		$this->assertFalse( ZEST_Client_Index::weak_name_only( $household, 'Robin', 'Example', 'robin@example.com', '' ), 'the same surname' );
		$same = array( 'id' => '9', 'fname' => 'Rob', 'lname' => 'Exampel', 'email' => 'other@example.com', 'phone' => '' );
		$this->assertFalse( ZEST_Client_Index::weak_name_only( $same, 'Robert', 'Example', 'robert@example.com', '' ), 'the same first name' );
		$match = array( 'id' => '10', 'fname' => 'Devin', 'lname' => 'Examples', 'email' => 'robin@example.com', 'phone' => '' );
		$this->assertFalse( ZEST_Client_Index::weak_name_only( $match, 'Robin', 'Example', 'robin@example.com', '' ), 'the contact matches' );
		$hidden = $stranger + array( 'withheld' => true );
		$this->assertFalse( ZEST_Client_Index::weak_name_only( $hidden, 'Robin', 'Example', 'robin@example.com', '' ) );
	}

	public function test_phones_are_read_in_any_common_form(): void {
		$this->assertSame( '5555550101', ZEST_Client_Index::phone_key( '+1 (555) 555-0101' ) );
		$this->assertSame( '', ZEST_Client_Index::phone_key( '0101' ) );
		$c = self::c( 1, 'Robin', 'Example', '', '' );
		$c['p_street2'] = '555-555-0199';
		$this->assertSame( array( '5555550199' ), ZEST_Client_Index::phones_of( $c ), 'a second street line that is only a phone' );
	}
}
