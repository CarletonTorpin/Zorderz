<?php
/**
 * ZDZ_Signin_Routes, the people: who manages sign-in routes, the one-time code a person gives, the second issuer
 * and backup addresses.
 *
 *   - Nobody manages at first, and until someone does nobody can give a code. An administrator, signed in as
 *     themselves, names themselves once. Never the shared device, an account in Privacy View, View As or Demo Mode.
 *     A manager who can no longer manage does not hand the page to the next administrator.
 *   - A given code: eight digits, for another person, never the shared device or an account in Privacy View; one
 *     live per account, replaced only when asked; taken back by whoever may.
 *   - The second issuer gives codes within its reach and changes nothing else.
 *   - Backup addresses are saved only by the manager (before anyone is named: an administrator); an entry that is
 *     refused is named with its reason.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/signin-test-doubles.php';
// The Identity Pack, for what a pack may set here (it looks for packs in these two places; neither exists).
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/zdz-pb-no-content' );
}
if ( ! function_exists( 'get_template_directory' ) ) {
	function get_template_directory() { return sys_get_temp_dir() . '/zdz-pb-no-theme'; }
}
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-business-profile.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-identity-pack.php';

final class SigninRoutesTest extends TestCase {

	protected function setUp(): void {
		zdz_test_signin_reset();
		zdz_test_signin_people();
		if ( ! ( get_user_by( 'email', 'ALEX@example.com' ) instanceof WP_User ) || ! ( get_user_by( 'id', 12 ) instanceof WP_User ) ) {
			$this->markTestSkipped( 'a get_user_by() stand-in loaded earlier does not know these accounts' );
		}
	}

	protected function tearDown(): void {
		zdz_test_signin_teardown();
	}

	private static function who( $email, $code ): int {
		$c = ZDZ_Signin_Routes::claim( $email, $code );
		return $c ? (int) $c['user']->ID : 0;
	}

	/** Morgan presses "I manage sign-in routes". */
	private static function morgan_manages(): void {
		zdz_test_signin_as( 1 );
		ZDZ_Signin_Routes::claim_management( 1 );
		$GLOBALS['wpdb']->written = array();
	}

	private static function told( int $uid, string $in ): array {
		return array_values( array_filter( $GLOBALS['wpdb']->notices(), function ( $n ) use ( $uid, $in ) {
			return $uid === $n['to'] && ( false !== strpos( $n['title'], $in ) || false !== strpos( $n['message'], $in ) );
		} ) );
	}

	private static function page(): string {
		ob_start();
		ZDZ_Signin_Routes::render();
		return (string) ob_get_clean();
	}

	/* ── who manages sign-in routes ────────────────────────────────────────── */

	public function test_nobody_manages_at_first_and_nobody_can_give_a_code(): void {
		$r = 'ZDZ_Signin_Routes';
		zdz_test_signin_as( 1 );
		$this->assertSame( array(), $r::managers() );
		$this->assertFalse( $r::may_manage( 1 ) );
		$this->assertFalse( $r::ever_named() );
		$this->assertTrue( is_wp_error( $r::issue( 1, 11 ) ), 'before anyone manages, nobody can give a code' );
		$this->assertFalse( $r::set_issuer( 1, 11 ) );
		$this->assertNull( $r::issuer() );
		$this->assertSame( array(), get_user_meta( 11, 'zdz_signin_codes', true ) ?: array() );
	}

	public function test_only_an_administrator_signed_in_as_themselves_can_take_it(): void {
		$r = 'ZDZ_Signin_Routes';
		zdz_test_signin_as( 3 );
		$this->assertFalse( $r::may_claim( 3 ), 'an account in Privacy View with an administrator role cannot' );
		$this->assertFalse( $r::claim_management( 3 ) );
		$this->assertFalse( $r::may_open( 3 ) );
		zdz_test_signin_as( 9 );
		$this->assertFalse( $r::may_claim( 9 ), 'the shared device cannot' );
		$this->assertFalse( $r::may_open( 9 ) );
		zdz_test_signin_as( 11 );
		$this->assertFalse( $r::may_claim( 11 ), 'a salesperson cannot' );
		$this->assertFalse( $r::may_open( 11 ) );
		zdz_test_signin_as( 1 );
		zdz_test_signin_view_as( 'zdz_sales' );
		$this->assertFalse( $r::may_claim( 1 ), 'an administrator under View As cannot' );
		zdz_test_signin_as( 1 );
		if ( zdz_test_signin_demo( true ) ) {
			$this->assertFalse( $r::may_claim( 1 ), 'an administrator in Demo Mode cannot' );
		}
		zdz_test_signin_as( 2 );
		$this->assertFalse( $r::claim_management( 1 ), 'nobody can take it for someone else' );
		$this->assertSame( array(), $r::managers() );

		zdz_test_signin_as( 1 );
		$this->assertTrue( $r::may_claim( 1 ) );
		$this->assertTrue( $r::claim_management( 1 ), 'an administrator, as themselves, takes it' );
		$this->assertSame( array( 1 ), $r::managers() );
		$this->assertTrue( $r::may_manage( 1 ) );

		$told = self::told( 2, 'Morgan Example now manages sign-in routes' );
		$this->assertCount( 1, $told, 'the other administrator was told who took it' );
		foreach ( array( 3, 9, 11, 12 ) as $other ) {
			$this->assertSame( array(), self::told( $other, '' ), 'the account in Privacy View, the shared device and everyone else were not' );
		}

		zdz_test_signin_as( 2 );
		$this->assertFalse( $r::may_claim( 2 ), 'once taken, another administrator cannot take it' );
		$this->assertFalse( $r::claim_management( 2 ) );
		$this->assertFalse( $r::may_open( 2 ), '... or open the page' );
		$this->assertSame( array( 1 ), $r::managers() );
	}

	public function test_an_account_that_does_not_exist_gets_no_route(): void {
		$this->assertFalse( ZDZ_Signin_Routes::gets_routes( 0 ) );
		$this->assertFalse( ZDZ_Signin_Routes::gets_routes( 4040 ) );
		$this->assertFalse( ZDZ_Signin_Routes::eligible_manager( 4040 ) );
		$this->assertTrue( ZDZ_Signin_Routes::gets_routes( 12 ) );
		$this->assertFalse( ZDZ_Signin_Routes::eligible_manager( 12 ), 'a manager is an administrator' );
		$this->assertTrue( ZDZ_Signin_Routes::eligible_manager( 2 ) );
	}

	public function test_a_manager_adds_and_removes_administrators_and_manages_only_as_themselves(): void {
		$r = 'ZDZ_Signin_Routes';
		self::morgan_manages();
		$this->assertFalse( $r::remove_manager( 1, 1 ), 'the last manager cannot be removed' );
		$this->assertFalse( $r::add_manager( 1, 3 ), 'not an account in Privacy View' );
		$this->assertFalse( $r::add_manager( 1, 9 ), 'not the shared device' );
		$this->assertFalse( $r::add_manager( 1, 11 ), 'not a salesperson' );
		$this->assertTrue( $r::add_manager( 1, 2 ) );
		$this->assertSame( array( 1, 2 ), $r::managers() );
		$this->assertTrue( $r::remove_manager( 1, 2 ) );
		$this->assertSame( array( 1 ), $r::managers() );
		$this->assertCount( 1, self::told( 2, 'no longer manage' ), 'a manager who is removed is told' );

		zdz_test_signin_view_as( 'zdz_tech' );
		$this->assertFalse( $r::may_manage( 1 ), 'a manager under View As manages nothing' );
		$this->assertTrue( is_wp_error( $r::issue( 1, 11 ) ) );
		zdz_test_signin_as( 1 );
		zdz_test_signin_view_as( null, 12 );
		$this->assertFalse( $r::may_manage( 1 ), 'nor viewing as one person, with no role chosen' );
		$this->assertTrue( is_wp_error( $r::issue( 1, 11 ) ) );
		zdz_test_signin_as( 1 );
		if ( zdz_test_signin_demo( true ) ) {
			$this->assertFalse( $r::may_manage( 1 ), 'nor in Demo Mode' );
		}
		zdz_test_signin_as( 11 );
		$this->assertFalse( $r::may_manage( 1 ), "a request that is not the manager's own can't act as the manager" );
		$this->assertTrue( is_wp_error( $r::issue( 1, 12 ) ) );
		zdz_test_signin_as( 1 );
		$this->assertTrue( $r::may_manage( 1 ) );
	}

	public function test_a_manager_who_can_no_longer_manage_does_not_reopen_the_button(): void {
		$r = 'ZDZ_Signin_Routes';
		self::morgan_manages();
		$GLOBALS['zdz_test_user_meta'][1]['zdz_privacy_view'] = '1';   // put in Privacy View; the same for deleted or demoted
		zdz_test_signin_as( 2 );
		$this->assertSame( array(), $r::managers() );
		$this->assertTrue( $r::ever_named() );
		$this->assertFalse( $r::may_claim( 2 ), 'the button does NOT re-open for the next administrator' );
		$this->assertFalse( $r::claim_management( 2 ) );
		$this->assertFalse( $r::may_open( 2 ) );
		$this->assertFalse( $r::may_edit_addresses( 2 ), 'and addresses do not fall back to any administrator' );
		$this->assertNull( $r::save_addresses( 2, 12, 'jordan.takeover@example.net' ) );

		$html = self::page();
		$this->assertStringContainsString( 'ZDZ_SIGNIN_MANAGER', $html, 'they are told the way back is a line in wp-config.php' );
		$this->assertTrue( false !== strpos( $html, "define( 'ZDZ_SIGNIN_MANAGER', 2 );" ) || false !== strpos( $html, 'define( &#039;ZDZ_SIGNIN_MANAGER&#039;, 2 );' ), 'with their own user ID in it' );
		$this->assertStringNotContainsString( '<form', $html );

		zdz_test_signin_as( 11 );
		$html = self::page();
		$this->assertStringNotContainsString( 'ZDZ_SIGNIN_MANAGER', $html, 'someone who could not be a manager is not handed the line' );
		$this->assertStringContainsString( 'Only the person who manages sign-in routes', $html );
	}

	/* ── the one-time code the manager gives ───────────────────────────────── */

	public function test_the_manager_gives_a_code_to_another_person_only(): void {
		$r = 'ZDZ_Signin_Routes';
		self::morgan_manages();
		$c = $r::issue( 1, 11 );
		$this->assertMatchesRegularExpression( '/^[1-9][0-9]{7}$/', (string) $c, 'eight digits, for Alex' );
		$this->assertStringNotContainsString( $c, serialize( $GLOBALS['zdz_test_user_meta'] ), 'the stored record does not hold the code' );
		$this->assertStringNotContainsString( strrev( $c ), serialize( $GLOBALS['zdz_test_user_meta'] ) );
		$this->assertTrue( is_wp_error( $r::issue( 1, 1 ) ), 'not for themselves' );
		$this->assertTrue( is_wp_error( $r::issue( 1, 9 ) ), 'not the shared device' );
		$this->assertTrue( is_wp_error( $r::issue( 1, 3 ) ), 'not an account in Privacy View' );
		$this->assertTrue( is_wp_error( $r::issue( 1, 4040 ) ) );
		$this->assertFalse( $r::may_issue_for( 1, 9 ), 'may_issue_for itself says no (not only put_code)' );
		$this->assertFalse( $r::may_issue_for( 1, 3 ) );

		$this->assertSame( 0, self::who( 'jordan@example.com', $c ), "it does not work with someone else's email" );
		$this->assertSame( 11, self::who( 'alex@example.com', $c ), 'it works with their email' );
		$this->assertSame( 0, self::who( 'alex@example.com', $c ), 'once' );
	}

	public function test_one_code_is_live_per_account_and_a_second_is_given_only_to_replace_it(): void {
		$r = 'ZDZ_Signin_Routes';
		self::morgan_manages();
		$first = $r::issue( 1, 11 );
		$again = $r::issue( 1, 11 );
		$this->assertTrue( is_wp_error( $again ), 'a refreshed page changes nothing' );
		$this->assertSame( 'zdz_signin_live', $again->get_error_code() );
		$this->assertStringContainsString( '10 more min', $again->get_error_message() );
		$this->assertSame( 11, self::who( 'alex@example.com', $first ), '... and the first still works' );

		$first  = $r::issue( 1, 11 );
		$second = $r::issue( 1, 11, true );
		$this->assertNotSame( $first, $second );
		$this->assertSame( 0, self::who( 'alex@example.com', $first ), 'with "replace", the new code cancels the one before' );
		$this->assertSame( 11, self::who( 'alex@example.com', $second ) );
	}

	public function test_a_code_is_taken_back_by_whoever_may(): void {
		$r = 'ZDZ_Signin_Routes';
		self::morgan_manages();
		$c = $r::issue( 1, 11 );
		zdz_test_signin_as( 12 );
		$this->assertFalse( $r::cancel_issued( 12, 11 ), 'a person who gives no codes cannot take one back' );
		$this->assertFalse( $r::cancel_issued( 1, 11 ), "nor in the manager's name" );
		$this->assertGreaterThan( 0, $r::issued_left( 11 ) );
		zdz_test_signin_as( 1 );
		$this->assertSame( 1, $r::issued_by( 11 ) );
		$this->assertTrue( $r::cancel_issued( 1, 11 ) );
		$this->assertSame( 0, self::who( 'alex@example.com', $c ) );
		$this->assertFalse( $r::cancel_issued( 1, 11 ), 'there is nothing left to take back' );
		$this->assertSame( 0, $r::issued_by( 11 ) );
	}

	/* ── the second issuer ─────────────────────────────────────────────────── */

	public function test_the_manager_names_one_second_issuer(): void {
		$r = 'ZDZ_Signin_Routes';
		self::morgan_manages();
		zdz_test_signin_as( 11 );
		$this->assertFalse( $r::set_issuer( 11, 12 ), 'a salesperson cannot name one' );
		zdz_test_signin_as( 1 );
		$this->assertFalse( $r::set_issuer( 1, 9 ), 'not the shared device' );
		$this->assertFalse( $r::set_issuer( 1, 3 ), 'not an account in Privacy View' );
		$this->assertFalse( $r::set_issuer( 1, 1 ), 'not a manager' );
		$this->assertNull( $r::issuer() );

		$this->assertTrue( $r::set_issuer( 1, 11 ) );
		$this->assertSame( array( 'uid' => 11, 'scope' => 'non_admin' ), $r::issuer(), 'the default is everyone except administrators' );
		$this->assertTrue( $r::set_issuer( 1, 11, 'everything' ) );
		$this->assertSame( 'non_admin', $r::issuer()['scope'], 'an unknown reach is the narrow one' );
		$this->assertGreaterThanOrEqual( 1, count( self::told( 11, 'page=zdz-signin-routes' ) ), 'Alex is told where the page is' );

		$GLOBALS['zdz_test_options']['zdz_signin_issuer'] = array( 'uid' => 12 );
		$this->assertSame( array( 'uid' => 12, 'scope' => 'non_admin' ), $r::issuer(), 'a stored issuer with no reach written down has the narrow one' );
		$r::set_issuer( 1, 2 );
		$r::add_manager( 1, 2 );
		$this->assertNull( $r::issuer(), 'an issuer who becomes a manager is no longer "the second issuer"' );
		$r::remove_manager( 1, 2 );
		$this->assertTrue( $r::set_issuer( 1, 0 ) );
		$this->assertNull( $r::issuer() );
		$this->assertFalse( get_option( 'zdz_signin_issuer' ) );
	}

	public function test_the_second_issuer_gives_codes_within_its_reach_and_changes_nothing_else(): void {
		$r = 'ZDZ_Signin_Routes';
		self::morgan_manages();
		$r::set_issuer( 1, 11 );
		$GLOBALS['wpdb']->written = array();
		zdz_test_signin_as( 11 );
		$this->assertTrue( $r::may_open( 11 ), 'Alex can open the page' );
		$this->assertFalse( $r::may_manage( 11 ) );

		$c = $r::issue( 11, 12 );
		$this->assertSame( 12, self::who( 'jordan@example.com', (string) $c ), 'Alex gives Jordan a code, and it works' );
		$this->assertCount( 1, self::told( 1, 'Alex Example gave Jordan Sample' ), 'the manager is told each time' );
		$this->assertStringNotContainsString( (string) $c, serialize( $GLOBALS['wpdb']->written ), 'no notice holds the code' );

		$this->assertTrue( is_wp_error( $r::issue( 11, 1 ) ), 'not for an administrator: the manager' );
		$this->assertTrue( is_wp_error( $r::issue( 11, 2 ) ), '... or the office administrator' );
		$this->assertTrue( is_wp_error( $r::issue( 11, 11 ) ), 'not for themselves' );
		$this->assertTrue( is_wp_error( $r::issue( 11, 9 ) ), 'not the shared device' );
		$this->assertTrue( is_wp_error( $r::issue( 11, 3 ) ), 'not an account in Privacy View' );
		$this->assertFalse( $r::may_issue_for( 11, 9 ) );
		$this->assertFalse( $r::may_issue_for( 11, 3 ) );

		$this->assertNull( $r::save_addresses( 11, 12, 'jordan.home@example.net' ), 'it cannot change addresses' );
		$this->assertFalse( $r::add_manager( 11, 2 ), '... managers' );
		$this->assertFalse( $r::set_issuer( 11, 12 ), '... or the issuer' );
		$this->assertFalse( $r::set_issuer( 11, 0 ) );

		zdz_test_signin_as( 12 );
		$this->assertTrue( is_wp_error( $r::issue( 11, 12 ) ), "Jordan's own request cannot act as Alex" );
		$this->assertFalse( $r::may_open( 12 ) );
	}

	public function test_a_second_issuer_who_is_an_administrator_gives_nothing_under_view_as(): void {
		$r = 'ZDZ_Signin_Routes';
		self::morgan_manages();
		$r::set_issuer( 1, 2 );   // Robin, the office administrator, who does not manage
		zdz_test_signin_as( 2 );
		$this->assertTrue( $r::is_issuer( 2 ) );
		$this->assertFalse( $r::may_manage( 2 ) );
		$this->assertTrue( $r::may_issue_for( 2, 12 ) );
		$this->assertFalse( $r::may_issue_for( 2, 1 ), 'its reach is still everyone except administrators' );
		zdz_test_signin_view_as( 'zdz_tech' );
		$this->assertTrue( is_wp_error( $r::issue( 2, 12 ) ), 'under View As it gives nothing' );
		$this->assertFalse( $r::may_open( 2 ) );
		zdz_test_signin_as( 2 );
		if ( zdz_test_signin_demo( true ) ) {
			$this->assertTrue( is_wp_error( $r::issue( 2, 12 ) ), 'nor in Demo Mode' );
		}
		zdz_test_signin_as( 2 );
		$this->assertTrue( is_string( $r::issue( 2, 12 ) ), 'as themselves, it does' );
	}

	public function test_the_second_issuer_takes_back_or_replaces_only_a_code_it_gave(): void {
		$r = 'ZDZ_Signin_Routes';
		self::morgan_manages();
		$r::set_issuer( 1, 11 );
		$r::issue( 1, 2 );
		zdz_test_signin_as( 11 );
		$this->assertFalse( $r::cancel_issued( 11, 2 ), 'not a code given to someone outside its reach' );
		$this->assertGreaterThan( 0, $r::issued_left( 2 ) );

		zdz_test_signin_as( 1 );
		$theirs = $r::issue( 1, 12 );
		zdz_test_signin_as( 11 );
		$this->assertFalse( $r::cancel_issued( 11, 12 ), 'not a code the MANAGER gave someone in its reach' );
		$this->assertTrue( is_wp_error( $r::issue( 11, 12, true ) ) );
		$this->assertSame( 12, self::who( 'jordan@example.com', $theirs ) );

		$mine  = $r::issue( 11, 12 );
		$mine2 = $r::issue( 11, 12, true );
		$this->assertSame( 0, self::who( 'jordan@example.com', $mine ), 'it can replace its own' );
		$this->assertTrue( $r::cancel_issued( 11, 12 ), '... and take it back' );
		$this->assertSame( 0, self::who( 'jordan@example.com', $mine2 ) );

		$r::issue( 11, 12 );
		zdz_test_signin_as( 1 );
		$this->assertTrue( is_string( $r::issue( 1, 12, true ) ), "the manager can replace the issuer's" );
		$this->assertTrue( $r::cancel_issued( 1, 12 ), '... or take it back' );
	}

	public function test_with_anyone_the_second_issuer_can_get_the_manager_back_in(): void {
		$r = 'ZDZ_Signin_Routes';
		self::morgan_manages();
		$r::set_issuer( 1, 11, 'anyone' );
		zdz_test_signin_as( 11 );
		$c = $r::issue( 11, 1 );
		$this->assertSame( 1, self::who( 'morgan@example.com', (string) $c ) );
		$this->assertTrue( is_wp_error( $r::issue( 11, 9 ) ), 'still never the shared device' );
		$this->assertTrue( is_wp_error( $r::issue( 11, 3 ) ), '... or an account in Privacy View' );

		zdz_test_signin_as( 1 );
		$r::set_issuer( 1, 0 );
		zdz_test_signin_as( 11 );
		$this->assertTrue( is_wp_error( $r::issue( 11, 12 ) ), 'once removed, Alex can give nothing' );
		$this->assertFalse( $r::may_open( 11 ), '... and open nothing' );
	}

	/* ── backup addresses ──────────────────────────────────────────────────── */

	public function test_a_typed_list_is_cleaned_and_every_refused_entry_is_named_with_its_reason(): void {
		$r = 'ZDZ_Signin_Routes';
		update_user_meta( 12, 'zdz_login_aliases', array( 'jordan.home@example.net' ) );
		list( $keep, $no ) = $r::clean_addresses( 11, 'ALEX.Home@Example.net, not-an-address; alex@example.com jordan@example.com, jordan.home@example.net, alex.home@example.net' );
		$this->assertSame( array( 'alex.home@example.net' ), $keep, 'kept: the one good address, lower-cased, once' );
		$why = array_column( $no, 1, 0 );
		$this->assertSame( 'is not an email address', $why['not-an-address'] );
		$this->assertStringContainsString( 'own address', $why['alex@example.com'] );
		$this->assertStringContainsString( "another account's sign-in address", $why['jordan@example.com'] );
		$this->assertStringContainsString( "another person's backup address", $why['jordan.home@example.net'] );
		$this->assertCount( 4, $no );
		$this->assertSame( array( array(), array() ), $r::clean_addresses( 11, " ,; \n" ) );
	}

	public function test_only_the_manager_saves_backup_addresses(): void {
		$r = 'ZDZ_Signin_Routes';
		self::morgan_manages();
		update_user_meta( 12, 'zdz_login_aliases', array( 'jordan.home@example.net' ) );
		$refused = $r::save_addresses( 1, 11, 'alex.home@example.net, not-an-address alex@example.com jordan@example.com jordan.home@example.net' );
		$this->assertCount( 4, $refused, 'the manager saves; the refused ones come back' );
		$this->assertSame( array( 'alex.home@example.net' ), $r::addresses( 11 ) );

		zdz_test_signin_as( 11 );
		$this->assertNull( $r::save_addresses( 11, 11, 'alex.mine@example.net' ), 'a person cannot save their own' );
		zdz_test_signin_as( 2 );
		$this->assertNull( $r::save_addresses( 2, 11, '' ), 'another administrator cannot, once someone manages' );
		$this->assertFalse( $r::may_edit_addresses( 2 ) );
		zdz_test_signin_as( 3 );
		$this->assertNull( $r::save_addresses( 3, 3, 'devin.home@example.net' ), 'an account in Privacy View cannot, on its own profile' );
		$this->assertNull( $r::save_addresses( 3, 11, '' ), "... or anyone's" );
		$this->assertSame( array( 'alex.home@example.net' ), $r::addresses( 11 ) );

		zdz_test_signin_as( 1 );
		$this->assertNull( $r::save_addresses( 1, 9, 'device.second@example.net' ), 'the shared device is given no address' );
		$this->assertNull( $r::save_addresses( 1, 3, 'devin.second@example.net' ), 'nor an account in Privacy View' );
		$this->assertNull( $r::save_addresses( 1, 4040, 'nobody.home@example.net' ) );
		update_user_meta( 9, 'zdz_login_aliases', array( 'device.alias@example.com' ) );
		$this->assertNull( $r::save_addresses( 1, 9, 'device.other@example.net' ), 'an address the shared device already has can be removed, not replaced' );
		$this->assertSame( array(), $r::save_addresses( 1, 9, '' ) );
		$this->assertSame( array(), $r::addresses( 9 ) );
	}

	public function test_before_anyone_manages_an_administrator_saves_them_and_nobody_else(): void {
		$r = 'ZDZ_Signin_Routes';
		zdz_test_signin_as( 2 );
		$this->assertTrue( $r::may_edit_addresses( 2 ) );
		$this->assertSame( array(), $r::save_addresses( 2, 12, 'jordan.home@example.net' ) );
		zdz_test_signin_as( 12 );
		$this->assertNull( $r::save_addresses( 12, 12, 'jordan.mine@example.net' ), 'a person still cannot save their own' );
		zdz_test_signin_as( 2 );
		zdz_test_signin_view_as( 'zdz_sales' );
		$this->assertNull( $r::save_addresses( 2, 12, '' ), 'nor an administrator under View As' );
		zdz_test_signin_as( 2 );
		if ( zdz_test_signin_demo( true ) ) {
			$this->assertNull( $r::save_addresses( 2, 12, '' ), '... or in Demo Mode' );
		}
		zdz_test_signin_as( 9 );
		$this->assertNull( $r::save_addresses( 9, 12, '' ) );
		$this->assertSame( array( 'jordan.home@example.net' ), $r::addresses( 12 ) );
	}

	public function test_a_removed_address_takes_its_code_and_its_tested_mark_with_it(): void {
		$r = 'ZDZ_Signin_Routes';
		self::morgan_manages();
		$r::save_addresses( 1, 11, 'alex.home@example.net' );
		$r::put_code( 11, '246810', 'mail', 300, array( 'to' => 'alex.home@example.net' ) );
		$r::after_sign_in( get_user_by( 'id', 11 ), $r::claim( 'alex.home@example.net', '246810' )['rec'] );
		$this->assertSame( 'tested', $r::cover( 11 ) );

		$r::put_code( 11, '864200', 'mail', 300, array( 'to' => 'alex.home@example.net' ) );
		$r::put_code( 11, '864201', 'mail', 300, array( 'to' => 'alex@example.com' ) );
		$r::save_addresses( 1, 11, 'alex.other@example.net' );
		$this->assertSame( 0, self::who( 'alex.home@example.net', '864200' ), 'a code asked for with an address that is then removed dies with it' );
		$this->assertCount( 1, get_user_meta( 11, 'zdz_signin_codes', true ) );
		$this->assertSame( 11, self::who( 'alex@example.com', '864201' ), 'the one mailed to the work address does not' );
		$this->assertSame( 'untested', $r::cover( 11 ), 'a changed address starts untested' );
		$this->assertSame( 0, $r::tested_at( 11, 'alex.home@example.net' ) );

		$changes = array_values( array_filter( (array) get_option( 'zdz_signin_log' ), function ( $e ) { return 'addresses_changed' === $e['what']; } ) );
		$this->assertCount( 2, $changes );
		$this->assertSame( '1', $changes[1]['n'], 'the log keeps how many there are now, never an address' );
		$r::save_addresses( 1, 11, 'alex.other@example.net' );
		$this->assertCount( 2, array_filter( (array) get_option( 'zdz_signin_log' ), function ( $e ) { return 'addresses_changed' === $e['what']; } ), 'saving the same list again is no change' );
	}

	/* ── what an Identity Pack may set ─────────────────────────────────────── */

	public function test_an_identity_pack_lists_the_mail_services_public_domains(): void {
		$all = ZDZ_Signin_Routes::pack_consumer( array( 'other' => array() ) );
		$this->assertSame( array( 'other', 'signin-routes' ), array_keys( $all ) );
		$this->assertSame( array( 'profile' ), $all['signin-routes']['files'] );
		$this->assertSame( array( 'profile' => array( 'email.shared_outage_domains' ) ), $all['signin-routes']['paths'] );

		$plan = ZDZ_Signin_Routes::plan_shared_outage_domains( array( 'email.shared_outage_domains' => array( 'Example.ORG', 'mail.example.net', 'not a domain', 'example.org' ) ) );
		$this->assertSame( 'zdz_signin_shared_outage_domains', $plan['writes'][0]['option'] );
		$this->assertSame( array( 'example.org', 'mail.example.net' ), $plan['writes'][0]['value'] );
		$this->assertCount( 1, $plan['refused'] );
		$this->assertStringContainsString( 'not a domain', $plan['refused'][0]['why'] );

		$this->assertSame( array(), ZDZ_Signin_Routes::plan_shared_outage_domains( array() )['writes'], 'a pack that says nothing changes nothing' );
		$this->assertSame( array(), ZDZ_Signin_Routes::plan_shared_outage_domains( array( 'email.shared_outage_domains' => array( 'nope' ) ) )['writes'], 'nothing is written when no entry is a domain' );
		$one = ZDZ_Signin_Routes::plan_shared_outage_domains( array( 'email.shared_outage_domains' => 'example.org, example.net' ) );
		$this->assertSame( array( 'example.org', 'example.net' ), $one['writes'][0]['value'], 'one comma-separated line reads the same' );

		update_option( 'zdz_signin_shared_outage_domains', array( 'example.org', 'example.net' ) );
		$this->assertSame( array(), ZDZ_Signin_Routes::plan_shared_outage_domains( array( 'email.shared_outage_domains' => array( 'example.org', 'example.net' ) ) )['writes'], 'what the site already holds is no change' );
		$this->assertSame( 'shared', ZDZ_Signin_Routes::address_flag( 'alex@example.com', 'alex@example.net' ) );
	}

	public function test_the_shipped_example_pack_carries_the_key_and_a_preview_writes_nothing(): void {
		// The example's own profile.yml, through this consumer alone (the registry's other readers are not this test's).
		$profile = ZDZ_Identity_Pack::read_file( dirname( __DIR__, 2 ) . '/zorderz/identity-packs/example-business', 'profile' );
		$this->assertIsArray( $profile );
		$this->assertSame( array( 'example.org' ), $profile['email']['shared_outage_domains'] ?? null, 'the example names one public mail domain' );
		$mine = ZDZ_Signin_Routes::pack_consumer( array() )['signin-routes'];
		$rows = call_user_func( $mine['preview'], array( 'profile' => $profile ) );
		$this->assertCount( 1, $rows, 'the preview lists it as one change, and refuses nothing' );
		$this->assertSame( 'email.shared_outage_domains', $rows[0]['path'] );
		$this->assertArrayNotHasKey( 'kind', $rows[0] );
		$this->assertSame( array(), ZDZ_Signin_Routes::shared_outage_domains(), 'a preview writes nothing' );
		$this->assertSame( '', ZDZ_Signin_Routes::address_flag( 'alex@example.com', 'alex@example.org' ) );

		call_user_func( $mine['apply'], array( 'profile' => $profile ), 'example-business' );
		$this->assertSame( array( 'example.org' ), ZDZ_Signin_Routes::shared_outage_domains(), 'applying the pack sets them' );
		$this->assertSame( 'shared', ZDZ_Signin_Routes::address_flag( 'alex@example.com', 'alex@example.org' ) );
		$this->assertSame( array(), call_user_func( $mine['preview'], array( 'profile' => $profile ) ), 'and applying it again would change nothing' );
	}

	/* ── the notice type ───────────────────────────────────────────────────── */

	public function test_its_notices_go_to_the_bell_and_push_not_email_by_default(): void {
		$router = ZDZ_Alert_Router::get_instance();
		$types  = new ReflectionProperty( 'ZDZ_Alert_Router', 'types' );
		$types->setAccessible( true );
		$before = $types->getValue( $router );
		ZDZ_Signin_Routes::register_alert_type( $router );
		$mine = $router->get_types()['signin_route'];
		$types->setValue( $router, $before );
		$this->assertSame( array( 'in_app', 'push' ), $mine['default_channels'], 'these are for when email is down' );
		$this->assertSame( 'system', $mine['category'] );
		ZDZ_Signin_Routes::register_alert_type( null );
		ZDZ_Signin_Routes::register_alert_type( new stdClass() );
		$this->assertArrayNotHasKey( 'signin_route', $router->get_types(), 'and the router is left as it was found' );
	}
}
