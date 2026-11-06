<?php
/**
 * The people list (ZDZ_Party): a search matches names, never e-mail addresses, for anyone but an administrator, and
 * the REST list is refused to the shared device and to a device in Demo Mode. Before, the e-mail was searched even
 * though it was stripped from the answer, so any account could learn a colleague's address one character at a time.
 *
 * Neutral data only (example.com).
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/messaging-test-doubles.php';
if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public $ID           = 0;
		public $roles        = array();
		public $user_email   = '';
		public $display_name = '';
		public $user_login   = '';
	}
}
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-party.php';

final class PartyPeopleTest extends TestCase {

	protected function setUp(): void {
		zdz_test_msg_reset();
		zdz_test_msg_people( array( 5 => 'staff', 6 => 'shared', 8 => 'admin' ) );
		$GLOBALS['zdz_test_users'] = array(
			self::user( 5, 'Alex Example', 'alex@example.com', 'zdz_tech' ),
			self::user( 7, 'Jordan Sample', 'quinn.private@example.com', 'zdz_sales' ),
			self::user( 8, 'Sam Admin', 'sam@example.com', 'administrator' ),
			self::user( 6, 'Shared Device', 'device@example.com', 'zdz_general' ),
		);
		$this->flush();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_users'] );
		$this->flush();
		zdz_test_msg_teardown();
	}

	private function flush(): void {
		$r = new ReflectionProperty( 'ZDZ_Party', 'memo' );
		$r->setAccessible( true );
		$r->setValue( null, array() );
	}

	private static function user( int $id, string $name, string $email, string $role ): WP_User {
		$u               = new WP_User();
		$u->ID           = $id;
		$u->display_name = $name;
		$u->user_email   = $email;
		$u->roles        = array( $role );
		return $u;
	}

	private static function ids( array $rows ): array {
		return array_map( function ( $r ) { return (int) $r['id']; }, $rows );
	}

	public function test_a_search_matches_names_never_addresses(): void {
		$GLOBALS['zdz_test_current_user'] = 5;
		$this->assertSame( array(), ZDZ_Party::selectable_people( array( 'search' => 'quinn.priv', 'search_email' => true ) ), 'an address fragment finds no one' );
		$this->assertSame( array(), ZDZ_Party::selectable_people( array( 'search' => '@example' ) ) );
		$this->assertSame( array( 7 ), self::ids( ZDZ_Party::selectable_people( array( 'search' => 'jordan' ) ) ), 'a name does' );
		foreach ( ZDZ_Party::selectable_people() as $row ) {
			$this->assertArrayNotHasKey( '_email', $row, 'no address leaves the server' );
		}
	}

	public function test_an_administrator_may_still_search_by_address(): void {
		$GLOBALS['zdz_test_current_user'] = 8;
		$this->assertSame( array( 7 ), self::ids( ZDZ_Party::selectable_people( array( 'search' => 'quinn.priv', 'search_email' => true ) ) ) );
		$this->assertSame( array(), ZDZ_Party::selectable_people( array( 'search' => 'quinn.priv' ) ), 'only when asked for' );
	}

	public function test_the_shared_device_is_never_listed_and_may_not_list(): void {
		$GLOBALS['zdz_test_current_user'] = 5;
		$this->assertNotContains( 6, self::ids( ZDZ_Party::selectable_people() ) );
		$this->assertFalse( ZDZ_Party::may_list( 6 ), 'the shared device' );
		$this->assertFalse( ZDZ_Party::may_list( 0 ), 'signed out' );
		$this->assertTrue( ZDZ_Party::may_list( 5 ) );
		$this->assertTrue( ZDZ_Party::may_list( 8 ) );
	}

	public function test_the_match_rule(): void {
		$row = array( 'name' => 'Riley Example', '_email' => 'casey@example.com' );
		$this->assertTrue( ZDZ_Party::matches( $row, '' ) );
		$this->assertTrue( ZDZ_Party::matches( $row, 'riley' ) );
		$this->assertFalse( ZDZ_Party::matches( $row, 'casey' ) );
		$this->assertTrue( ZDZ_Party::matches( $row, 'casey', true ) );
	}
}
