<?php
/**
 * A person's profile: "Backup sign-in addresses" (ZDZ_Admin_UI's own form and its own save).
 *
 *   - Before anyone manages sign-in routes an administrator saves a person's addresses; a person never saves their
 *     own, nor does an account in Privacy View.
 *   - Once someone manages, only the manager does, and never under View As.
 *   - An entry that is refused is not stored and is named in a notice, once.
 *   - The shared device and an account in Privacy View are given none.
 *   - The field says what it is for, is greyed out for everyone who may not change it, and says where it is changed.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/signin-test-doubles.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-admin-ui.php';   // ends with "new ZDZ_Admin_UI();"

final class SigninProfileTest extends TestCase {

	private $ui;

	protected function setUp(): void {
		zdz_test_signin_reset();
		zdz_test_signin_people();
		// As WordPress: whoever may edit users may edit each of them, and everyone may edit their own profile.
		foreach ( array_keys( $GLOBALS['zdz_test_user_caps'] ) as $id ) {
			$GLOBALS['zdz_test_user_caps'][ $id ][] = 'edit_user';
		}
		if ( ! zdz_test_signin_has( 'wp_verify_nonce' ) || ! ( get_user_by( 'email', 'ALEX@example.com' ) instanceof WP_User ) ) {
			$this->markTestSkipped( 'a stand-in loaded earlier (nonce check, people) does not answer as these tests need' );
		}
		$this->ui = new ZDZ_Admin_UI();
	}

	protected function tearDown(): void {
		zdz_test_signin_teardown();
	}

	/** The profile form is saved by $who for $target, with this in the addresses box. */
	private function save( int $who, int $target, string $addresses, bool $with_nonce = true ): void {
		$GLOBALS['zdz_test_current_user'] = $who;
		$_POST                            = array( 'zdz_login_aliases' => $addresses );
		if ( $with_nonce ) {
			$_POST['zdz_app_permissions_nonce'] = zdz_test_signin_nonce( 'zdz_save_app_permissions' );
		}
		$this->ui->save_app_permissions( $target );
		$_POST = array();
	}

	private static function stored( int $uid ): array {
		$a = get_user_meta( $uid, 'zdz_login_aliases', true );
		return is_array( $a ) ? $a : array();
	}

	/** The addresses row of $target's profile as $who sees it ('' when the section is not drawn for them). */
	private function field( int $who, int $target ): string {
		$GLOBALS['zdz_test_current_user'] = $who;
		ob_start();
		$this->ui->render_app_permissions( get_user_by( 'id', $target ) );
		$html = (string) ob_get_clean();
		return preg_match( '~<tr>\s*<th><label for="zdz_login_aliases">.*?</tr>~s', $html, $m ) ? $m[0] : '';
	}

	private static function notice(): string {
		ob_start();
		ZDZ_Signin_Routes::refused_notice();
		return (string) ob_get_clean();
	}

	public function test_before_anyone_manages_an_administrator_saves_and_a_person_never_saves_their_own(): void {
		$this->save( 2, 11, 'alex.home@example.net' );
		$this->assertSame( array( 'alex.home@example.net' ), self::stored( 11 ), "an administrator saves a person's address" );
		$this->save( 12, 12, 'jordan.mine@example.net' );
		$this->assertSame( array(), self::stored( 12 ), 'a person posting to their own profile saves nothing' );
		$this->save( 3, 3, 'devin.mine@example.net' );
		$this->assertSame( array(), self::stored( 3 ), 'nor the account in Privacy View, administrator role or not' );
		$this->save( 2, 12, 'jordan.home@example.net', false );
		$this->assertSame( array(), self::stored( 12 ), 'a post without the form\'s nonce saves nothing' );
		$this->save( 9, 12, 'jordan.home@example.net' );
		$this->assertSame( array(), self::stored( 12 ), 'nor one from the shared device' );
	}

	public function test_once_someone_manages_only_the_manager_saves(): void {
		$this->save( 2, 11, 'alex.home@example.net' );
		zdz_test_signin_as( 1 );
		ZDZ_Signin_Routes::claim_management( 1 );

		$this->save( 2, 11, 'alex.changed@example.net' );
		$this->assertSame( array( 'alex.home@example.net' ), self::stored( 11 ), 'another administrator can no longer change it' );
		$this->save( 11, 11, 'alex.mine@example.net' );
		$this->assertSame( array( 'alex.home@example.net' ), self::stored( 11 ), 'nor the person' );

		$this->save( 1, 12, 'JORDAN.Home@example.net, alex@example.com, nope' );
		$this->assertSame( array( 'jordan.home@example.net' ), self::stored( 12 ), 'the manager saves; kept lower-cased' );
		$note = self::notice();
		$this->assertStringContainsString( '&quot;alex@example.com&quot; is another account&#039;s sign-in address', $note, 'the two refused entries are named, each with its reason' );
		$this->assertStringContainsString( '&quot;nope&quot; is not an email address', $note );
		$this->assertSame( '', self::notice(), 'once' );

		$this->save( 1, 9, 'device.second@example.net' );
		$this->save( 1, 3, 'devin.second@example.net' );
		$this->assertSame( array(), self::stored( 9 ), 'the shared device is given none, even by the manager' );
		$this->assertSame( array(), self::stored( 3 ), 'nor the account in Privacy View' );

		zdz_test_signin_view_as( 'zdz_sales' );
		$this->save( 1, 12, '' );
		zdz_test_signin_as( 1 );
		$this->assertSame( array( 'jordan.home@example.net' ), self::stored( 12 ), 'the manager under View As changes nothing' );
		$this->save( 1, 12, '' );
		$this->assertSame( array(), self::stored( 12 ), 'as themselves they clear it' );
	}

	public function test_the_field_says_what_it_is_for_and_who_changes_it(): void {
		zdz_test_signin_as( 1 );
		ZDZ_Signin_Routes::claim_management( 1 );
		ZDZ_Signin_Routes::save_addresses( 1, 12, 'jordan.home@example.net' );

		$f = $this->field( 1, 12 );
		$this->assertStringContainsString( '>Backup sign-in addresses<', $f );
		$this->assertStringNotContainsString( 'Login Email Aliases', $f );
		$this->assertStringContainsString( 'value="jordan.home@example.net"', $f );
		$this->assertStringNotContainsString( 'disabled', $f, 'the manager can type in it' );
		$this->assertStringContainsString( 'named after you save', $f, '... and is told a refused entry will be named' );
		$this->assertStringContainsString( 'when the work mailbox is down', $f );
		$this->assertDoesNotMatchRegularExpression( '/placeholder="[^"]*@/', $f, 'the example is words, never an address' );

		$f = $this->field( 2, 12 );
		$this->assertStringContainsString( 'disabled', $f, 'another administrator sees it greyed out' );
		$this->assertStringContainsString( 'Zorderz → Sign-in routes', $f, '... with where it is changed' );
		$this->assertStringContainsString( 'value="jordan.home@example.net"', $f );

		foreach ( array( 9 => 'the shared device', 3 => 'the account in Privacy View' ) as $target => $whose ) {
			$f = $this->field( 1, $target );
			$this->assertStringContainsString( 'disabled', $f, "on $whose's profile it is greyed out for everyone" );
			$this->assertStringContainsString( 'no backup sign-in', $f, '... and says why' );
		}
		$this->assertSame( '', $this->field( 12, 12 ), 'a person is not shown the section on their own profile at all' );

		zdz_test_signin_as( 1 );
		zdz_test_signin_view_as( 'zdz_sales' );
		$this->assertStringContainsString( 'disabled', $this->field( 1, 12 ), 'under View As it is greyed out for the manager too' );
	}

	public function test_before_anyone_manages_the_field_is_open_to_an_administrator(): void {
		$f = $this->field( 2, 12 );
		$this->assertStringContainsString( '>Backup sign-in addresses<', $f );
		$this->assertStringNotContainsString( 'disabled', $f );
		$this->assertStringContainsString( 'disabled', $this->field( 2, 9 ) );
	}

	public function test_the_older_collision_guard_is_gone_with_its_only_caller(): void {
		$this->assertFalse( method_exists( 'ZDZ_Admin_UI', 'alias_taken_by_other' ), 'the rules live in ZDZ_Signin_Routes::clean_addresses() alone' );
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-admin-ui.php' );
		$this->assertSame( 0, substr_count( $src, "update_user_meta( \$user_id, 'zdz_login_aliases'" ), 'and the profile writes addresses only through it' );
		$this->assertSame( 1, substr_count( $src, '= ZDZ_Signin_Routes::save_addresses(' ) );
	}
}
