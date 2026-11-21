<?php
/**
 * Zorderz → Sign-in routes, as it is drawn and as it answers a post (ZDZ_Signin_Routes::render()).
 *
 *   - A fresh install: one box that says what the page is for and one button; no list, no address, no code can be
 *     given until someone presses it.
 *   - The manager: who has no backup address and whose has never signed them in; each person's addresses; the
 *     one-time code, shown once; the second issuer; who manages; what happened.
 *   - One press, one code: a refreshed page gives no second code.
 *   - The second issuer: the people within its reach, and nothing else.
 *   - Everyone else, by the page's address: the refusal and nothing more. A post with no nonce goes no further.
 *   - The page has no script, and a name with markup in it is shown as text.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/signin-test-doubles.php';

/**
 * Each test runs in a process of its own: other test files define the WordPress nonce check and menu stand-ins
 * differently, and in a shared process whichever loaded first would win (these tests would then skip).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SigninPageTest extends TestCase {

	protected function setUp(): void {
		zdz_test_signin_reset();
		zdz_test_signin_people();
		if ( ! zdz_test_signin_has( 'check_admin_referer', 'add_submenu_page' ) || ! ( get_user_by( 'email', 'ALEX@example.com' ) instanceof WP_User ) ) {
			$this->markTestSkipped( 'a stand-in loaded earlier (nonce check, menu, people) does not answer as these tests need' );
		}
	}

	protected function tearDown(): void {
		zdz_test_signin_teardown();
	}

	/** The page as the signed-in person opens it. */
	private static function get(): string {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array();
		ob_start();
		ZDZ_Signin_Routes::render();
		return (string) ob_get_clean();
	}

	/** The page after one of its forms is posted, as a browser that had the page open would post it. */
	private static function post( array $fields, $nonce = null ): string {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = $fields;
		if ( false !== $nonce ) {
			$_POST['_wpnonce'] = null === $nonce ? zdz_test_signin_nonce( 'zdz_signin_routes' ) : $nonce;
		}
		if ( 'issue' === ( $fields['zdz_signin_do'] ?? '' ) && ! array_key_exists( 'zdz_signin_form', $fields ) ) {
			$_POST['zdz_signin_form'] = (string) get_user_meta( get_current_user_id(), 'zdz_signin_form', true );
		}
		ob_start();
		try {
			ZDZ_Signin_Routes::render();
		} finally {
			$html = (string) ob_get_clean();
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$_POST                     = array();
		}
		return $html;
	}

	private static function text( string $html ): string {
		$html = (string) preg_replace( '~<style.*?</style>~s', '', $html );
		return trim( (string) preg_replace( '/\s+/', ' ', html_entity_decode( strip_tags( $html ), ENT_QUOTES ) ) );
	}

	/** One person's row, as text ('' when they have none). */
	private static function row( string $html, int $uid ): string {
		return preg_match( '~<tr data-uid="' . $uid . '">(.*?)</tr>~s', $html, $m ) ? self::text( $m[1] ) : '';
	}

	private static function row_html( string $html, int $uid ): string {
		return preg_match( '~<tr data-uid="' . $uid . '">(.*?)</tr>~s', $html, $m ) ? $m[1] : '';
	}

	private static function box( string $html, string $id ): string {
		return preg_match( '~<div class="zdz-sr-box" id="' . $id . '">(.*?)</div>~s', $html, $m ) ? $m[1] : '';
	}

	private static function shown_code( string $html ): string {
		return preg_match( '~id="zdz-sr-code">(\d{4}) (\d{4})<~', $html, $m ) ? $m[1] . $m[2] : '';
	}

	private static function who( $email, $code ): int {
		$c = ZDZ_Signin_Routes::claim( $email, $code );
		return $c ? (int) $c['user']->ID : 0;
	}

	/**
	 * The page with something on it: Morgan manages; Alex's backup address has signed them in; Morgan's and Jordan's
	 * have not, and Jordan's is at the mail service that hosts the work mailboxes; Riley's is on the work domain;
	 * Robin and Casey have none; Alex is the second issuer; Jordan holds a live code; five wrong codes were tried
	 * for Casey; the shared device still has an address set earlier.
	 */
	private static function a_working_site(): void {
		$r = 'ZDZ_Signin_Routes';
		update_option( 'zdz_signin_shared_outage_domains', array( 'example.org' ) );
		update_user_meta( 9, 'zdz_login_aliases', array( 'device.alias@example.com' ) );
		zdz_test_signin_as( 1 );
		$r::claim_management( 1 );
		$r::save_addresses( 1, 1, 'morgan.home@example.net' );
		$r::save_addresses( 1, 11, 'alex.home@example.net' );
		$r::put_code( 11, '246810', 'mail', 300, array( 'to' => 'alex.home@example.net' ) );
		$r::after_sign_in( get_user_by( 'id', 11 ), $r::claim( 'alex.home@example.net', '246810' )['rec'] );
		$r::save_addresses( 1, 12, 'jordan@example.org' );
		$r::save_addresses( 1, 13, 'riley.second@example.com' );
		$r::set_issuer( 1, 11 );
		$r::issue( 1, 12 );
		$r::put_code( 14, '555111', 'mail', 300, array( 'to' => 'casey@example.com' ) );
		for ( $i = 0; $i < 5; $i++ ) {
			$r::claim( 'casey@example.com', '555000' );   // five wrong codes at the login screen
		}
	}

	/* ── a fresh install ───────────────────────────────────────────────────── */

	public function test_before_anyone_manages_the_page_explains_itself_and_offers_one_button(): void {
		zdz_test_signin_as( 1 );
		$html = self::get();
		$text = self::text( $html );
		$this->assertSame( 1, substr_count( $html, '<button' ), 'one button' );
		$this->assertStringContainsString( 'I manage sign-in routes', $html );
		$this->assertStringNotContainsString( '<table', $html, 'no list of people' );
		$this->assertStringNotContainsString( '@', $text, 'and no address yet' );
		$this->assertStringContainsString( 'Nobody manages sign-in routes yet.', $text );
		$this->assertStringContainsString( "a person's sign-in email can't reach them", $text, 'it says what the page is for' );
		$this->assertStringContainsString( 'nobody can give a code', $text, '... what holds until someone presses' );
		$this->assertStringContainsString( "can't be undone from this page", $text, '... that it is pressed once' );
		$this->assertStringContainsString( "define( 'ZDZ_SIGNIN_MANAGER', <user ID> );", $text, '... and the way to name someone else later' );
		$this->assertSame( array(), ZDZ_Signin_Routes::managers(), 'looking at the page names nobody' );

		$html = self::post( array( 'zdz_signin_do' => 'issue', 'zdz_signin_uid' => 11 ) );
		$this->assertSame( '', self::shown_code( $html ), 'a post for a code before anyone manages gives none' );

		$html = self::post( array( 'zdz_signin_do' => 'claim' ) );
		$this->assertSame( array( 1 ), ZDZ_Signin_Routes::managers() );
		$this->assertStringContainsString( 'You now manage sign-in routes.', $html );
		$this->assertStringContainsString( '<table', $html, 'and the people are listed' );
		$this->assertStringNotContainsString( 'I manage sign-in routes', $html );
	}

	/* ── who is offered the page ───────────────────────────────────────────── */

	public function test_the_page_is_on_the_menu_only_for_those_who_may_open_it(): void {
		self::a_working_site();
		$GLOBALS['zdz_test_signin_pages'] = array();
		zdz_test_signin_as( 1 );
		ZDZ_Signin_Routes::add_page();
		$this->assertSame( array( array( 'parent' => 'zdz-core-settings', 'cap' => 'read', 'slug' => 'zdz-signin-routes' ) ), $GLOBALS['zdz_test_signin_pages'], 'the manager: under the Zorderz menu' );

		foreach ( array( 2 => 'another administrator', 3 => 'the account in Privacy View', 9 => 'the shared device', 12 => 'a person who gives no codes', 0 => 'nobody signed in' ) as $id => $who ) {
			$GLOBALS['zdz_test_signin_pages'] = array();
			zdz_test_signin_as( $id );
			ZDZ_Signin_Routes::add_page();
			$this->assertSame( array(), $GLOBALS['zdz_test_signin_pages'], $who . ': no page' );
			$html = self::get();
			$this->assertStringNotContainsString( '<table', $html, $who . ': opened by its address, it shows nothing but the refusal' );
			$this->assertStringNotContainsString( '<form', $html );
			$this->assertStringNotContainsString( '@', $html );
			$this->assertStringContainsString( 'Only the person who manages sign-in routes', $html );
		}

		$GLOBALS['zdz_test_signin_pages'] = array();
		zdz_test_signin_as( 11 );
		ZDZ_Signin_Routes::add_page();
		$this->assertSame( '', $GLOBALS['zdz_test_signin_pages'][0]['parent'], 'the second issuer (not an administrator): a page with no menu parent, reached by its address' );
		$this->assertStringContainsString( 'page=zdz-signin-routes', ZDZ_Signin_Routes::page_url() );
	}

	public function test_when_nobody_can_manage_any_more_an_administrator_is_shown_the_way_back_and_nothing_else(): void {
		self::a_working_site();
		$GLOBALS['zdz_test_user_meta'][1]['zdz_privacy_view'] = '1';   // the manager is put in Privacy View; the same for deleted or demoted
		$r = 'ZDZ_Signin_Routes';

		$GLOBALS['zdz_test_signin_pages'] = array();
		zdz_test_signin_as( 2 );
		$this->assertFalse( $r::may_open( 2 ) );
		$this->assertTrue( $r::shows_way_back( 2 ) );
		$r::add_page();
		$this->assertSame( array( array( 'parent' => 'zdz-core-settings', 'cap' => 'read', 'slug' => 'zdz-signin-routes' ) ), $GLOBALS['zdz_test_signin_pages'], 'the page is on their menu, so the way back can be read' );
		$html = self::get();
		$this->assertStringContainsString( 'can no longer do so', self::text( $html ) );
		$this->assertStringContainsString( "define( 'ZDZ_SIGNIN_MANAGER', 2 );", self::text( $html ) );
		$this->assertStringNotContainsString( '<form', $html, 'no form' );
		$this->assertStringNotContainsString( '<table', $html, 'no list' );
		$this->assertStringNotContainsString( '@', $html, 'no address' );

		$was  = get_user_meta( 13, 'zdz_signin_codes', true );
		$html = self::post( array( 'zdz_signin_do' => 'issue', 'zdz_signin_uid' => 13, 'zdz_signin_form' => '' ) );
		$this->assertSame( $was, get_user_meta( 13, 'zdz_signin_codes', true ), 'and a post from it does nothing' );
		$html = self::post( array( 'zdz_signin_do' => 'claim' ) );
		$this->assertSame( array(), $r::managers(), '... the button included' );
		$this->assertStringNotContainsString( '<form', $html );

		foreach ( array( 13 => 'a salesperson', 9 => 'the shared device', 1 => 'the account now in Privacy View', 0 => 'nobody signed in' ) as $id => $who ) {
			$GLOBALS['zdz_test_signin_pages'] = array();
			zdz_test_signin_as( $id );
			$this->assertFalse( $r::shows_way_back( $id ), $who );
			$r::add_page();
			$this->assertSame( array(), $GLOBALS['zdz_test_signin_pages'], $who . ': no page' );
			$this->assertStringNotContainsString( 'ZDZ_SIGNIN_MANAGER', self::get(), $who . ': not handed the line' );
		}
		// The second issuer is for exactly this: it still gives codes within its reach, and is not handed the line either.
		$GLOBALS['zdz_test_signin_pages'] = array();
		zdz_test_signin_as( 11 );
		$this->assertTrue( $r::may_open( 11 ) );
		$this->assertFalse( $r::shows_way_back( 11 ) );
		$html = self::get();
		$this->assertStringNotContainsString( 'ZDZ_SIGNIN_MANAGER', $html );
		$this->assertSame( 3, substr_count( $html, '>Give a ' ), 'a button for each of the three people within its reach' );
		zdz_test_signin_as( 2 );
		zdz_test_signin_view_as( 'zdz_sales' );
		$this->assertFalse( $r::shows_way_back( 2 ), 'nor an administrator under View As' );

		// With a manager who can manage, the other administrators have no page (the page is the manager's).
		unset( $GLOBALS['zdz_test_user_meta'][1]['zdz_privacy_view'] );
		zdz_test_signin_as( 2 );
		$this->assertFalse( $r::shows_way_back( 2 ) );
	}

	/* ── the manager's page ────────────────────────────────────────────────── */

	public function test_the_manager_sees_who_is_covered_and_who_is_not(): void {
		self::a_working_site();
		$html  = self::get();
		$cover = self::text( self::box( $html, 'zdz-sr-cover' ) );
		$this->assertStringContainsString( 'No backup address (3): Robin Sample, Riley Example, Casey Sample', $cover, 'an address on the work domain counts as none' );
		$this->assertStringContainsString( 'Backup address not yet tested (2): Morgan Example, Jordan Sample.', $cover );
		$this->assertStringNotContainsString( 'Alex Example', $cover, 'someone whose backup address has signed them in is on neither list' );
		$this->assertStringNotContainsString( 'Shared Device', $cover, 'nor the shared device' );
		$this->assertStringNotContainsString( 'Devin Example', $cover, 'nor the account in Privacy View' );
		$this->assertStringContainsString( 'a way into WordPress that does not depend on this site\'s email', $cover );

		$this->assertStringContainsString( 'alex.home@example.net: tested 13 Nov 2026', self::row( $html, 11 ), 'tested, with the day' );
		$this->assertStringContainsString( 'backup address', self::row( $html, 11 ), 'and how they last signed in' );
		$this->assertStringContainsString( 'jordan@example.org: not yet tested · same mail service as the work mailbox', self::row( $html, 12 ) );
		$this->assertStringContainsString( 'riley.second@example.com: same mail domain as the work address', self::row( $html, 13 ) );
		$this->assertStringContainsString( 'no code sign-in recorded yet', self::row( $html, 14 ) );
		$this->assertStringContainsString( 'robin@example.com', self::row( $html, 2 ), "the manager sees each person's sign-in address" );
		$this->assertStringNotContainsString( 'Locked', $html, 'nobody is ever shown as locked: there is no lock' );
	}

	public function test_the_manager_sees_live_codes_and_gives_them_only_to_people_who_get_routes(): void {
		self::a_working_site();
		$html = self::get();
		$this->assertStringContainsString( 'A code is live for 10 more min. Take it back', self::row( $html, 12 ) );
		$this->assertStringContainsString( 'Give a new code', self::row( $html, 12 ) );
		$this->assertSame( 1, substr_count( self::row_html( $html, 12 ), 'name="zdz_signin_replace"' ), 'only "Give a new code" says "replace"' );
		$this->assertSame( 0, substr_count( self::row_html( $html, 11 ), 'name="zdz_signin_replace"' ) );
		$this->assertStringContainsString( 'Give a sign-in code', self::row( $html, 11 ), 'one for each person who gets routes' );

		$this->assertStringContainsString( 'The shared device: no backup routes. It also signs in with 1 other address set earlier. Remove', self::row( $html, 9 ) );
		$this->assertStringContainsString( 'An account in Privacy View: no backup routes.', self::row( $html, 3 ) );
		foreach ( array( 9, 3, 1 ) as $uid ) {
			$this->assertStringNotContainsString( 'Give a', self::row( $html, $uid ), 'no code for the shared device, the account in Privacy View or oneself' );
		}
		$this->assertStringContainsString( 'you', self::row( $html, 1 ) );

		$text = self::text( $html );
		$this->assertDoesNotMatchRegularExpression( '/\b\d{4} \d{4}\b|\b\d{3} \d{3}\b|\b\d{6,8}\b/', $text, 'no code is on the page when none was just given' );
		$this->assertStringNotContainsString( '<script', $html, 'no script, so no browser dialog can block the tab' );
		$this->assertDoesNotMatchRegularExpression( '/confirm\s*\(|alert\s*\(|onclick=/', $html );
	}

	public function test_the_manager_sees_the_second_issuer_the_managers_and_what_happened(): void {
		self::a_working_site();
		$html   = self::get();
		$issuer = self::box( $html, 'zdz-sr-issuer' );
		$this->assertStringContainsString( 'Now: Alex Example, for everyone except administrators.', self::text( $issuer ) );
		$this->assertMatchesRegularExpression( '/value="non_admin" checked/', $issuer, 'the choice starts at "everyone except administrators"' );
		$this->assertDoesNotMatchRegularExpression( '/value="anyone" checked/', $issuer );
		preg_match_all( '~<option value="\d+">([^<]*)</option>~', $issuer, $m );
		$this->assertSame( array( 'Choose an account…', 'Robin Sample', 'Alex Example', 'Jordan Sample', 'Riley Example', 'Casey Sample' ), $m[1], 'the list leaves out the shared device, the account in Privacy View and the manager' );

		$managers = self::box( $html, 'zdz-sr-managers' );
		$this->assertStringContainsString( '<li>Morgan Example</li>', $managers, 'the only manager has no "Remove"' );
		preg_match_all( '~<option value="\d+">([^<]*)</option>~', $managers, $m );
		$this->assertSame( array( 'Add an administrator…', 'Robin Sample' ), $m[1] );

		$text = self::text( $html );
		$this->assertStringContainsString( 'Alex Example may give codes to everyone except administrators (set by Morgan Example)', $text, 'the log reads as sentences' );
		$this->assertStringContainsString( 'Morgan Example gave Jordan Sample a one-time code', $text );
		$this->assertStringContainsString( 'Casey Sample: 5 wrong codes were tried; an emailed code was cancelled', $text, 'what happened at the login screen is there too' );
		$this->assertStringContainsString( 'Alex Example signed in with a backup address', $text );
		$this->assertStringContainsString( 'Morgan Example manages sign-in routes (set by Morgan Example)', $text );

		$this->assertSame( substr_count( $html, '<form' ), substr_count( $html, 'name="_wpnonce"' ), 'every form carries its nonce' );
		preg_match_all( '~name="zdz_signin_form" value="([^"]*)"~', $html, $t );
		$this->assertSame( substr_count( $html, '>Give a' ), count( $t[1] ), 'every "Give" form carries this showing\'s one-press token' );
		$this->assertCount( 1, array_unique( $t[1] ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{24}$/', $t[1][0] );
		$this->assertSame( $t[1][0], get_user_meta( 1, 'zdz_signin_form', true ) );
	}

	/* ── giving a code ─────────────────────────────────────────────────────── */

	public function test_a_code_is_shown_once_with_who_it_is_for_and_how_to_pass_it_on(): void {
		self::a_working_site();
		self::get();
		$html = self::post( array( 'zdz_signin_do' => 'issue', 'zdz_signin_uid' => 13 ) );
		$code = self::shown_code( $html );
		$this->assertMatchesRegularExpression( '/^[1-9]\d{7}$/', $code, 'eight digits, in two groups of four' );
		$note = preg_match( '~<div class="notice notice-success">(.*?)</div>~s', $html, $m ) ? self::text( $m[1] ) : '';
		$this->assertStringContainsString( 'Sign-in code for Riley Example:', $note );
		$this->assertStringContainsString( 'Eight digits. Good for 10 minutes, once, for that account only.', $note );
		$this->assertStringContainsString( 'by voice', $note );
		$this->assertStringContainsString( 'Email not arriving?', $note );
		$this->assertSame( 1, substr_count( self::text( $html ), substr( $code, 0, 4 ) . ' ' . substr( $code, 4 ) ), 'the code is on the page once' );
		$this->assertStringContainsString( 'A code is live for 10 more min.', self::row( $html, 13 ) );

		$again = self::post( array( 'zdz_signin_do' => 'issue', 'zdz_signin_uid' => 13, 'zdz_signin_replace' => '1', 'zdz_signin_form' => '' ) );
		$this->assertSame( '', self::shown_code( $again ), 'a press with no token gives nothing' );
		$this->assertSame( 13, self::who( 'riley@example.com', $code ), 'and the code that was shown signs Riley in' );
	}

	public function test_one_press_gives_one_code(): void {
		self::a_working_site();
		$first = self::get();
		preg_match( '~name="zdz_signin_form" value="([^"]*)"~', $first, $t );
		$press = array( 'zdz_signin_do' => 'issue', 'zdz_signin_uid' => 13, 'zdz_signin_form' => $t[1] );

		$html = self::post( $press );
		$code = self::shown_code( $html );
		$this->assertNotSame( '', $code );

		$html = self::post( $press );
		$this->assertSame( '', self::shown_code( $html ), 'the same post again (a refreshed page) gives no second code' );
		$this->assertStringContainsString( 'That press was not counted', $html );
		$this->assertStringContainsString( 'notice-error', $html );

		delete_user_meta( 1, 'zdz_signin_form' );
		$html = self::post( array( 'zdz_signin_do' => 'issue', 'zdz_signin_uid' => 13, 'zdz_signin_replace' => '1', 'zdz_signin_form' => '' ) );
		$this->assertSame( '', self::shown_code( $html ), 'with no token on record, a press with an empty one is refused' );
		$this->assertStringContainsString( 'not counted', $html );

		$html = self::post( array( 'zdz_signin_do' => 'issue', 'zdz_signin_uid' => 13 ) );
		$this->assertSame( '', self::shown_code( $html ), 'a fresh press while a code is live, without "replace": refused' );
		$this->assertStringContainsString( 'already live', $html, '... and it says why' );
		$this->assertSame( 13, self::who( 'riley@example.com', $code ), 'the first code still works' );

		$html = self::post( $press );
		$this->assertSame( '', self::shown_code( $html ), 'the first post yet again, now that the code is used up: still no new code' );
		$this->assertSame( 0, ZDZ_Signin_Routes::issued_left( 13 ) );

		$html = self::post( array( 'zdz_signin_do' => 'issue', 'zdz_signin_uid' => 12, 'zdz_signin_replace' => '1' ) );
		$this->assertNotSame( '', self::shown_code( $html ), '"Give a new code" replaces a live one' );
	}

	public function test_a_code_that_cannot_be_given_or_kept_is_not_shown(): void {
		self::a_working_site();
		self::get();
		$html = self::post( array( 'zdz_signin_do' => 'issue', 'zdz_signin_uid' => 9 ) );
		$this->assertSame( '', self::shown_code( $html ), 'asked for the shared device: refused' );
		$this->assertSame( 1, substr_count( $html, 'notice-error' ) );
		$html = self::post( array( 'zdz_signin_do' => 'issue', 'zdz_signin_uid' => 1 ) );
		$this->assertSame( '', self::shown_code( $html ), 'or for oneself' );

		$GLOBALS['wpdb']->held = array( zdz_test_signin_lock( 13 ) );
		$html                  = self::post( array( 'zdz_signin_do' => 'issue', 'zdz_signin_uid' => 13 ) );
		$GLOBALS['wpdb']->held = array();
		$this->assertSame( '', self::shown_code( $html ), 'a code that could not be kept is not shown' );
		$this->assertStringContainsString( 'could not be kept', $html );

		$html = self::post( array( 'zdz_signin_do' => 'cancel', 'zdz_signin_uid' => 12 ) );
		$this->assertStringContainsString( 'That code no longer works.', $html );
		$this->assertStringNotContainsString( 'A code is live', self::row( $html, 12 ) );
		$this->assertStringContainsString( 'There was no code to take back.', self::post( array( 'zdz_signin_do' => 'cancel', 'zdz_signin_uid' => 12 ) ) );
	}

	/* ── the other forms ───────────────────────────────────────────────────── */

	public function test_addresses_are_saved_from_the_page_and_a_refused_one_is_named(): void {
		self::a_working_site();
		$html = self::post( array( 'zdz_signin_do' => 'addresses', 'zdz_signin_uid' => 14, 'zdz_signin_addresses' => 'casey.home@example.net, alex@example.com, nope' ) );
		$warn = preg_match( '~<div class="notice notice-warning">(.*?)</div>~s', $html, $m ) ? self::text( $m[1] ) : '';
		$this->assertSame( 'Saved, except: "alex@example.com" is another account\'s sign-in address; "nope" is not an email address.', $warn );
		$this->assertStringContainsString( 'casey.home@example.net: not yet tested', self::row( $html, 14 ), 'and the good one is saved' );
		$this->assertStringContainsString( 'Saved.', self::post( array( 'zdz_signin_do' => 'addresses', 'zdz_signin_uid' => 14, 'zdz_signin_addresses' => 'casey.home@example.net' ) ) );

		$html = self::post( array( 'zdz_signin_do' => 'addresses', 'zdz_signin_uid' => 9, 'zdz_signin_addresses' => '' ) );
		$this->assertSame( array(), ZDZ_Signin_Routes::addresses( 9 ), "the shared device's earlier address is removed" );
		$this->assertStringNotContainsString( 'other address', self::row( $html, 9 ) );
		$this->assertStringContainsString( 'That could not be saved.', self::post( array( 'zdz_signin_do' => 'addresses', 'zdz_signin_uid' => 9, 'zdz_signin_addresses' => 'device.new@example.net' ) ) );
	}

	public function test_the_issuer_and_the_managers_are_changed_from_the_page(): void {
		self::a_working_site();
		$this->assertStringContainsString( 'Choose an account first.', self::post( array( 'zdz_signin_do' => 'issuer', 'zdz_signin_uid' => 0 ) ) );
		$this->assertStringContainsString( "That account can't be the second issuer.", html_entity_decode( self::post( array( 'zdz_signin_do' => 'issuer', 'zdz_signin_uid' => 9 ) ), ENT_QUOTES ) );
		$html = self::post( array( 'zdz_signin_do' => 'issuer', 'zdz_signin_uid' => 11 ) );
		$this->assertStringContainsString( 'Now: Alex Example, for everyone except administrators.', self::text( $html ) );
		$this->assertStringContainsString( 'Saved. They have been told where to find this page.', self::text( $html ), 'a role that gets notices is sent the address' );
		$this->assertStringNotContainsString( 'No notice reaches', self::text( $html ) );
		$html = self::post( array( 'zdz_signin_do' => 'issuer', 'zdz_signin_uid' => 12, 'zdz_signin_scope' => 'anyone' ) );
		$this->assertStringContainsString( 'Now: Jordan Sample, for anyone, administrators included.', self::text( $html ) );
		// Jordan's role gets no bell and no push here, and the page is on no menu of theirs: the manager is given the address to pass on.
		$this->assertStringContainsString( 'Saved. No notice reaches that account, so tell them where this page is: ' . ZDZ_Signin_Routes::page_url(), self::text( $html ) );
		$this->assertStringContainsString( 'admin.php?page=zdz-signin-routes', ZDZ_Signin_Routes::page_url() );
		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringNotContainsString( 'They have been told', self::text( $html ) );
		$html = self::post( array( 'zdz_signin_do' => 'issuer_remove' ) );
		$this->assertStringContainsString( 'There is no second issuer now.', $html );
		$this->assertNull( ZDZ_Signin_Routes::issuer() );

		$html = self::post( array( 'zdz_signin_do' => 'manager_add', 'zdz_signin_uid' => 2 ) );
		$this->assertSame( array( 1, 2 ), ZDZ_Signin_Routes::managers() );
		$this->assertSame( 2, substr_count( self::box( $html, 'zdz-sr-managers' ), '>Remove<' ), 'with two managers either can be removed' );
		$this->assertStringContainsString( "That account can't manage sign-in routes.", html_entity_decode( self::post( array( 'zdz_signin_do' => 'manager_add', 'zdz_signin_uid' => 11 ) ), ENT_QUOTES ) );
		self::post( array( 'zdz_signin_do' => 'manager_remove', 'zdz_signin_uid' => 2 ) );
		$this->assertSame( array( 1 ), ZDZ_Signin_Routes::managers() );
		$this->assertStringContainsString( "can't be removed here", html_entity_decode( self::post( array( 'zdz_signin_do' => 'manager_remove', 'zdz_signin_uid' => 1 ) ), ENT_QUOTES ) );
		$this->assertSame( array( 1 ), ZDZ_Signin_Routes::managers() );
		$this->assertStringContainsString( '<table', self::post( array( 'zdz_signin_do' => 'no-such-act' ) ), 'a post that names no act does nothing' );
	}

	public function test_a_post_with_no_nonce_or_another_forms_nonce_goes_no_further(): void {
		self::a_working_site();
		self::get();
		$token = (string) get_user_meta( 1, 'zdz_signin_form', true );
		$was   = get_user_meta( 13, 'zdz_signin_codes', true );
		foreach ( array( 'no nonce' => false, "another form's nonce" => zdz_test_signin_nonce( 'zdz_save_app_permissions' ) ) as $what => $nonce ) {
			$stopped = false;
			try {
				self::post( array( 'zdz_signin_do' => 'issue', 'zdz_signin_uid' => 13, 'zdz_signin_form' => $token ), $nonce );
			} catch ( RuntimeException $e ) {
				$stopped = true;
			}
			$this->assertTrue( $stopped, 'a post with ' . $what . ' is stopped' );
			$this->assertSame( $was, get_user_meta( 13, 'zdz_signin_codes', true ), '... before anything is done' );
			$this->assertSame( $token, get_user_meta( 1, 'zdz_signin_form', true ), '... or read' );
		}
	}

	/* ── the second issuer's page ──────────────────────────────────────────── */

	public function test_the_second_issuer_sees_the_people_within_its_reach_and_nothing_else(): void {
		self::a_working_site();
		zdz_test_signin_as( 11 );
		$html = self::get();
		$text = self::text( $html );
		foreach ( array( 'Jordan Sample', 'Riley Example', 'Casey Sample' ) as $name ) {
			$this->assertStringContainsString( $name, $text );
		}
		foreach ( array( 'Morgan Example', 'Robin Sample', 'Shared Device', 'Devin Example' ) as $name ) {
			$this->assertStringNotContainsString( $name, $text, 'no administrator, shared device or account in Privacy View' );
		}
		$this->assertStringNotContainsString( '@', $text, 'no address' );
		foreach ( array( 'Backup sign-in addresses', 'Second issuer', 'Managed by', 'What happened', 'Last signed in' ) as $part ) {
			$this->assertStringNotContainsString( $part, $text, 'no settings and no log' );
		}
		$this->assertSame( 3, substr_count( $html, '>Give a' ), 'three buttons' );
		$this->assertStringNotContainsString( 'Take it back', $html, 'not on a code the manager gave' );
		$this->assertStringNotContainsString( '<script', $html );

		$was  = ZDZ_Signin_Routes::addresses( 12 );
		$html = self::post( array( 'zdz_signin_do' => 'addresses', 'zdz_signin_uid' => 12, 'zdz_signin_addresses' => 'jordan.other@example.net' ) );
		$this->assertSame( $was, ZDZ_Signin_Routes::addresses( 12 ), 'a posted address change from its page changes nothing' );
		$this->assertStringContainsString( 'That could not be saved.', $html );
		foreach ( array( 'issuer' => 12, 'issuer_remove' => 0, 'manager_add' => 2, 'manager_remove' => 1, 'claim' => 0 ) as $do => $uid ) {
			self::post( array( 'zdz_signin_do' => $do, 'zdz_signin_uid' => $uid ) );
		}
		$this->assertSame( array( 'uid' => 11, 'scope' => 'non_admin' ), ZDZ_Signin_Routes::issuer(), 'nor a post of any other form' );
		$this->assertSame( array( 1 ), ZDZ_Signin_Routes::managers() );

		$html = self::post( array( 'zdz_signin_do' => 'issue', 'zdz_signin_uid' => 1 ) );
		$this->assertSame( '', self::shown_code( $html ), "its post for the manager's account is refused" );
		$this->assertStringContainsString( 'notice-error', $html );
		$html = self::post( array( 'zdz_signin_do' => 'issue', 'zdz_signin_uid' => 12, 'zdz_signin_replace' => '1' ) );
		$this->assertSame( '', self::shown_code( $html ), 'it cannot replace a code the manager gave' );

		$html = self::post( array( 'zdz_signin_do' => 'issue', 'zdz_signin_uid' => 13 ) );
		$code = self::shown_code( $html );
		$this->assertNotSame( '', $code, 'its "Give a sign-in code" shows the code once, with how to pass it on' );
		$this->assertStringContainsString( 'Email not arriving?', $html );
		$this->assertStringContainsString( 'Take it back', self::row( $html, 13 ), 'and it may take back its own' );
		$this->assertSame( 13, self::who( 'riley@example.com', $code ) );
	}

	/* ── an account in Privacy View, and markup in a name ──────────────────── */

	public function test_a_post_from_an_account_in_privacy_view_is_refused_before_it_is_read(): void {
		self::a_working_site();
		$GLOBALS['zdz_test_options']['zdz_signin_managers'] = array( 1, 3 );   // even were it written into the list of managers
		zdz_test_signin_as( 3 );
		update_user_meta( 3, 'zdz_signin_form', 'a-token' );
		$html = self::post( array( 'zdz_signin_do' => 'issue', 'zdz_signin_uid' => 13, 'zdz_signin_form' => 'a-token' ), false );
		$this->assertStringContainsString( 'Only the person who manages sign-in routes', $html, 'no nonce was asked for: the post was never read' );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertSame( 'a-token', get_user_meta( 3, 'zdz_signin_form', true ) );
		$this->assertSame( 0, ZDZ_Signin_Routes::issued_left( 13 ) );
		$this->assertSame( array( 1 ), ZDZ_Signin_Routes::managers(), 'and it does not count as a manager' );
	}

	public function test_a_name_with_markup_in_it_is_shown_as_text(): void {
		self::a_working_site();
		zdz_test_signin_user( 15, 'sam@example.com', 'Sam <img src=x onerror=alert(1)> "Odd"', array( 'zdz_tech' ) );
		update_user_meta( 15, 'zdz_login_aliases', array( 'sam.home@example.net' ) );
		zdz_test_signin_as( 1 );
		ZDZ_Signin_Routes::set_issuer( 1, 15 );
		ZDZ_Signin_Routes::set_issuer( 1, 11 );
		$html = self::get();
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertGreaterThanOrEqual( 3, substr_count( $html, '&lt;img src=x onerror=alert(1)&gt;' ), "everywhere on the manager's page: the row, the lists, the log" );

		$html = self::post( array( 'zdz_signin_do' => 'issue', 'zdz_signin_uid' => 15 ) );
		$this->assertNotSame( '', self::shown_code( $html ) );
		$this->assertStringNotContainsString( '<img src=x', $html, 'and in the box that shows a code' );
		$this->assertStringContainsString( 'Sign-in code for <strong>Sam &lt;img src=x onerror=alert(1)&gt;', $html );

		zdz_test_signin_as( 11 );
		$html = self::get();
		$this->assertStringNotContainsString( '<img src=x', $html, "and on the second issuer's page" );
		$this->assertStringContainsString( '&lt;img src=x', $html );
	}

	/* ── a notice after a profile save ─────────────────────────────────────── */

	public function test_entries_a_profile_save_left_out_are_named_once(): void {
		self::a_working_site();
		ZDZ_Signin_Routes::remember_refused( array() );
		$this->assertSame( array(), (array) $GLOBALS['zdz_test_transients'], 'nothing to say, nothing kept' );
		ZDZ_Signin_Routes::remember_refused( array( array( 'alex@example.com', "is another account's sign-in address" ), array( 'nope', 'is not an email address' ) ) );
		ob_start();
		ZDZ_Signin_Routes::refused_notice();
		$note = (string) ob_get_clean();
		$this->assertStringContainsString( '&quot;alex@example.com&quot; is another account&#039;s sign-in address; &quot;nope&quot; is not an email address', $note );
		$this->assertStringContainsString( 'Backup sign-in addresses:', $note );
		ob_start();
		ZDZ_Signin_Routes::refused_notice();
		$this->assertSame( '', (string) ob_get_clean(), 'once' );
		zdz_test_signin_as( 2 );
		ZDZ_Signin_Routes::remember_refused( array( array( 'x', 'y' ) ) );
		zdz_test_signin_as( 1 );
		ob_start();
		ZDZ_Signin_Routes::refused_notice();
		$this->assertSame( '', (string) ob_get_clean(), 'and only to the person who saved' );
	}
}
