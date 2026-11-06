<?php
/**
 * Estimates: a new stub that names two salespeople.
 *
 *   - the writing: "(AB & CD)", "ab/cd", "AB and CD" read alike; three people, a repeat or a long code are no pair;
 *   - a live pair is used only when both people check out: each named person exactly one account and the account
 *     Territories named; an "own pick" person carrying this stub's default code; the rule's person never nobody;
 *   - this app tells only its own pick, once per decision, never the operator who made it, and nobody when what was
 *     written is not the pair that was answered.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/estimate-stubs.php';

final class EstimatePairsTest extends TestCase {

	const ALEX   = 61; // AB, sales
	const JORDAN = 62; // CD, sales
	const SAM    = 63; // OP, operator
	const ROBIN  = 64; // EF and a second EF holder below

	protected function setUp(): void {
		$GLOBALS['zdz_test_transients'] = array();
		$GLOBALS['zdz_test_user_roles'] = array( self::ALEX => array( 'zdz_sales' ), self::JORDAN => array( 'zdz_sales' ), self::SAM => array( 'zdz_operator' ), self::ROBIN => array( 'zdz_sales' ), 65 => array( 'zdz_sales' ) );
		$GLOBALS['zdz_test_user_meta']  = array(
			self::ALEX => array( 'zdz_user_initials' => 'AB' ), self::JORDAN => array( 'zdz_user_initials' => 'CD' ),
			self::SAM => array( 'zdz_user_initials' => 'OP' ), self::ROBIN => array( 'zdz_user_initials' => 'EF' ), 65 => array( 'zdz_user_initials' => 'EF' ),
		);
		$GLOBALS['zdz_test_users'] = array();
		foreach ( $GLOBALS['zdz_test_user_roles'] as $id => $r ) {
			$GLOBALS['zdz_test_users'][] = (object) array( 'ID' => $id, 'roles' => $r );
		}
		ZEST_Salesperson::flush();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_transients'], $GLOBALS['zdz_test_user_roles'], $GLOBALS['zdz_test_user_meta'], $GLOBALS['zdz_test_users'] );
		ZEST_Salesperson::flush();
	}

	public function test_the_writing(): void {
		$this->assertSame( array( 'AB', 'CD' ), ZEST_Pairs::split( '(ab & cd)' ) );
		$this->assertSame( array( 'AB', 'CD' ), ZEST_Pairs::split( 'AB/CD' ) );
		$this->assertSame( array( 'AB', 'CD' ), ZEST_Pairs::split( 'AB and CD' ) );
		$this->assertSame( array( 'QP' ), ZEST_Pairs::split( 'QP' ) );
		$this->assertSame( array(), ZEST_Pairs::split( 'AB & CD & EF' ) );
		$this->assertSame( array(), ZEST_Pairs::split( 'AB & AB' ) );
		$this->assertSame( array(), ZEST_Pairs::split( 'ABCD' ) );
		$this->assertSame( 'AB & CD', ZEST_Pairs::join( array( 'AB', 'CD' ) ) );
		$this->assertTrue( ZEST_Pairs::same( '(AB&CD)', 'ab & cd' ) );
		$this->assertFalse( ZEST_Pairs::same( 'CD & AB', 'AB & CD' ), 'the first is the rule\'s person' );
	}

	public function test_a_pair_is_used_only_when_both_people_check_out(): void {
		$both = ZEST_Pairs::resolve( array( 'initials' => 'AB & CD', 'people' => array( array( 'user_id' => self::ALEX, 'initials' => 'AB' ), array( 'user_id' => self::JORDAN, 'initials' => 'CD' ) ) ), 'OP' );
		$this->assertTrue( $both['ok'] );
		$this->assertFalse( $both['people'][1]['own_pick'] );

		$own = ZEST_Pairs::resolve( array( 'initials' => 'AB & OP', 'people' => array( array( 'user_id' => self::ALEX, 'initials' => 'AB' ), array( 'user_id' => 0, 'initials' => 'OP' ) ) ), 'OP' );
		$this->assertTrue( $own['ok'] );
		$this->assertTrue( $own['people'][1]['own_pick'] );
		$this->assertSame( self::SAM, $own['people'][1]['user_id'] );

		$this->assertFalse( ZEST_Pairs::resolve( array( 'initials' => 'AB & CD', 'people' => array( array( 'user_id' => self::ALEX ), array( 'user_id' => self::ALEX ) ) ), 'OP' )['ok'], 'the named account must hold the code' );
		$this->assertFalse( ZEST_Pairs::resolve( array( 'initials' => 'AB & EF', 'people' => array( array( 'user_id' => self::ALEX ), array( 'user_id' => self::ROBIN ) ) ), 'OP' )['ok'], 'a code two accounts share names nobody' );
		$this->assertFalse( ZEST_Pairs::resolve( array( 'initials' => 'AB & CD', 'people' => array( array( 'user_id' => self::ALEX ), array( 'user_id' => 0 ) ) ), 'OP' )['ok'], 'nobody found and not this app\'s pick' );
		$this->assertFalse( ZEST_Pairs::resolve( array( 'initials' => 'OP & AB', 'people' => array( array( 'user_id' => 0 ), array( 'user_id' => self::ALEX ) ) ), 'OP' )['ok'], 'the rule\'s person is somebody' );
		$this->assertFalse( ZEST_Pairs::resolve( array( 'initials' => 'AB', 'people' => array() ), 'OP' )['ok'] );
	}

	public function test_this_app_tells_only_its_own_pick_once(): void {
		$route = array(
			'mode' => 'live', 'decision_id' => 41, 'initials' => 'AB & CD',
			'people' => array( array( 'user_id' => self::ALEX, 'initials' => 'AB', 'own_pick' => false ), array( 'user_id' => self::JORDAN, 'initials' => 'CD', 'own_pick' => true ) ),
		);
		$this->assertSame( array(), ZEST_Pairs::notify_own_picks( $route, 'AB', '1042', '00000', self::SAM ), 'what was written is not the pair' );
		$told = ZEST_Pairs::notify_own_picks( $route, '(AB & CD)', '1042', '00000', self::SAM );
		$this->assertSame( array( self::JORDAN ), array_column( $told, 'to' ) );
		$this->assertSame( array(), ZEST_Pairs::notify_own_picks( $route, 'AB & CD', '1042', '00000', self::SAM ), 'once per decision' );

		$GLOBALS['zdz_test_transients'] = array();
		$this->assertSame( array(), ZEST_Pairs::notify_own_picks( $route, 'AB & CD', '1042', '00000', self::JORDAN ), 'never the person who made it' );
		$GLOBALS['zdz_test_transients'] = array();
		$this->assertSame( array(), ZEST_Pairs::notify_own_picks( array( 'mode' => 'shadow' ) + $route, 'AB & CD', '1042', '', self::SAM ), 'a live decision only' );
	}
}
