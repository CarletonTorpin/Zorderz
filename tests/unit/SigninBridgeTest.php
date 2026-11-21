<?php
/**
 * The login bridge's code paths (ZDZ_Magic_Link_Bridge), through its own REST handlers: a sign-in code is claimed
 * with the email it is for, kept per account, and never logged.
 *
 *   - A claim is the email and the code; a code with no email, or with another account's, signs nobody in. Two
 *     people who draw the same digits each sign in as themselves.
 *   - Wrong tries are counted against the code, whatever connecting address they come from; the account is never
 *     locked; six-digit guesses never cancel a code a person gave.
 *   - The answer is the same for an unknown address and a wrong code.
 *   - A code asked for with a backup address is emailed to it and claimed only with it. A backup address of the shared
 *     device or of an account in Privacy View reaches nobody, and the log never names the address a code went to.
 *   - The login plugin's own form and the "You're logged in" page keep their codes the same way; a code that could
 *     not be kept is not sent or shown.
 *   - Limits a stranger cannot turn against the person: sends are capped per typed address; one connecting address
 *     has its own share; an office behind one address still gets its codes.
 *   - An account in Privacy View is refused every sign-in route.
 *
 * Neutral data only (documentation addresses 192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24).
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/signin-test-doubles.php';

final class SigninBridgeTest extends TestCase {

	const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 19_0 like Mac OS X)';
	const LINK   = 'https://app.example.com/?magic-login=1&token=example';

	private $bridge;
	private $ip      = 10;
	private $seen    = array();   // every code this test was given
	private $log_was = '';
	private $log     = '';

	protected function setUp(): void {
		zdz_test_signin_reset();
		zdz_test_signin_people();
		zdz_test_signin_user( 16, 'blake@example.com', 'Blake Example', array( 'zdz_sales' ) );
		$GLOBALS['zdz_test_user_meta'][11]['zdz_login_aliases'] = array( 'alex.home@example.net' );
		$GLOBALS['zdz_test_user_meta'][14]['zdz_login_aliases'] = array( 'casey.home@example.net' );
		$GLOBALS['zdz_test_options']['zdz_signin_managers']     = array( 1 );
		if ( ! zdz_test_signin_has( 'wp_mail' ) || ! ( get_user_by( 'email', 'ALEX@example.com' ) instanceof WP_User ) ) {
			$this->markTestSkipped( 'a wp_mail() or get_user_by() stand-in loaded earlier does not answer as these tests need' );
		}
		$this->bridge  = ZDZ_Magic_Link_Bridge::get_instance();
		$this->ip      = 10;
		$this->seen    = array();
		$this->log     = (string) tempnam( sys_get_temp_dir(), 'zdzsignin' );
		$this->log_was = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $this->log );
	}

	protected function tearDown(): void {
		ini_set( 'error_log', $this->log_was );
		if ( '' !== $this->log && is_file( $this->log ) ) {
			unlink( $this->log );
		}
		zdz_test_signin_teardown();
	}

	/** Each call comes from its own connecting address unless a test says otherwise, so the per-address limits never decide a test about the per-account ones. */
	private function from( ?string $ip = null ): void {
		$_SERVER['REMOTE_ADDR'] = null !== $ip ? $ip : '203.0.113.' . ( $this->ip++ );
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_REAL_IP'] );
	}

	private static function mails(): array {
		return (array) ( $GLOBALS['zdz_test_signin_mail'] ?? array() );
	}

	/** The six digits in a mail ('' when it carries none). */
	private function code_in( $mail ): string {
		$text = is_array( $mail ) ? (string) ( $mail['message'] ?? '' ) : '';
		$c    = preg_match( '/(\d{3}) (\d{3})/', $text, $m ) ? $m[1] . $m[2] : '';
		if ( '' !== $c ) {
			$this->seen[] = $c;
		}
		return $c;
	}

	/** "Send me a code": the code the site mailed for this ask ('' when it sent nothing). */
	private function ask( string $email, ?string $ip = null ): string {
		$this->from( $ip );
		$GLOBALS['zdz_test_current_user'] = 0;
		$before = count( self::mails() );
		$this->bridge->handle_send_code( new WP_REST_Request( array( 'email' => $email ) ) );
		$mails = self::mails();
		return count( $mails ) > $before ? $this->code_in( end( $mails ) ) : '';
	}

	/** A claim: who it signed in (0: nobody), its error code and the reply. */
	private function claim( $code, $email = null, ?string $ip = null ): array {
		$this->from( $ip );
		$GLOBALS['zdz_test_current_user'] = 0;
		$params = array( 'code' => $code );
		if ( null !== $email ) {
			$params['email'] = $email;
		}
		$r  = $this->bridge->handle_code_claim( new WP_REST_Request( $params ) );
		$in = ( $r instanceof WP_REST_Response && ! empty( $r->data['success'] ) ) ? (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ) : 0;
		$GLOBALS['zdz_test_current_user'] = 0;
		return array( 'in' => $in, 'err' => $r instanceof WP_Error ? (string) $r->get_error_code() : '', 'r' => $r );
	}

	private function give( int $target, bool $replace = false ) {
		zdz_test_signin_as( 1 );
		$c = ZDZ_Signin_Routes::issue( 1, $target, $replace );
		if ( is_string( $c ) ) {
			$this->seen[] = $c;
		}
		$GLOBALS['zdz_test_current_user'] = 0;
		return $c;
	}

	private function link_mail( string $to, array $more = array() ): array {
		return $this->bridge->inject_otp_into_magic_login_email( $more + array( 'to' => $to, 'subject' => 'Log in', 'message' => self::LINK, 'headers' => array(), 'attachments' => array() ) );
	}

	/** The "You're logged in" page's address for this person on an iPhone, and the code it carries. */
	private function logged_in_page( int $uid ): array {
		$_SERVER['HTTP_USER_AGENT'] = self::IPHONE;
		$_GET                       = array();
		$url                        = $this->bridge->filter_login_redirect( 'https://app.example.com/', 'https://app.example.com/', get_user_by( 'id', $uid ) );
		unset( $_SERVER['HTTP_USER_AGENT'] );
		parse_str( (string) parse_url( (string) $url, PHP_URL_QUERY ), $q );
		$code = (string) ( $q['bridge_code'] ?? '' );
		if ( '' !== $code ) {
			$this->seen[] = $code;
		}
		return array( $url, $code );
	}

	private static function told( int $uid, string $in ): array {
		return array_values( array_filter( $GLOBALS['wpdb']->notices(), function ( $n ) use ( $uid, $in ) {
			return $uid === $n['to'] && ( false !== strpos( $n['title'], $in ) || false !== strpos( $n['message'], $in ) );
		} ) );
	}

	private static function events( int $uid, string $what ): array {
		return array_values( array_filter( (array) get_option( 'zdz_signin_events', array() ), function ( $e ) use ( $uid, $what ) {
			return $uid === $e['u'] && $what === $e['what'];
		} ) );
	}

	/* ── a claim is the email and the code ─────────────────────────────────── */

	public function test_a_claim_is_the_email_and_the_code(): void {
		$c = $this->ask( 'alex@example.com' );
		$this->assertSame( 6, strlen( $c ) );
		$mails = self::mails();
		$this->assertSame( 'alex@example.com', end( $mails )['to'], 'a code is emailed to the address typed' );

		$r = $this->claim( $c, 'jordan@example.com' );
		$this->assertSame( 0, $r['in'], "Alex's code with Jordan's email signs nobody in" );
		$this->assertSame( 'invalid_code', $r['err'] );
		$r = $this->claim( $c );
		$this->assertSame( 0, $r['in'], 'a code with no email signs nobody in' );
		$this->assertSame( 'email_needed', $r['err'], 'a page loaded before the email went with the code sees a plain sentence' );
		$this->assertSame( 400, $r['r']->get_error_data()['status'] );
		$this->assertStringContainsString( 'reload the page', $r['r']->get_error_message() );

		$r = $this->claim( $c, 'alex@example.com' );
		$this->assertSame( 11, $r['in'], "Alex's code with Alex's email signs Alex in" );
		$this->assertTrue( $r['r']->data['success'] );
		$this->assertStringContainsString( 'https://app.example.com', (string) $r['r']->data['redirect'] );
		if ( zdz_test_signin_has( 'wp_set_auth_cookie' ) ) {
			$this->assertSame( 11, $GLOBALS['zdz_test_signin_cookie'] );
		}
		$this->assertSame( 0, $this->claim( $c, 'alex@example.com' )['in'], 'the same code a second time signs nobody in' );
	}

	public function test_two_people_who_draw_the_same_six_digits_each_sign_in_as_themselves(): void {
		$c = $this->ask( 'alex@example.com' );
		ZDZ_Signin_Routes::put_code( 12, $c, 'mail', 300, array( 'to' => 'jordan@example.com' ) );   // Jordan's ask drew the same digits
		$this->assertSame( 11, $this->claim( $c, 'alex@example.com' )['in'] );
		$this->assertSame( 12, $this->claim( $c, 'jordan@example.com' )['in'] );

		$c     = $this->ask( 'jordan@example.com' );
		$named = array_filter( array_keys( (array) $GLOBALS['zdz_test_transients'] ), function ( $k ) { return (bool) preg_match( '/_code_\d{6}$/', (string) $k ); } );
		$this->assertSame( array(), array_values( $named ), 'nothing is stored under a name made of the code' );
		$this->assertStringNotContainsString( $c, serialize( $GLOBALS['zdz_test_user_meta'] ) . serialize( $GLOBALS['zdz_test_transients'] ) . serialize( $GLOBALS['zdz_test_options'] ), 'no stored value holds the code itself' );
	}

	/* ── wrong tries ───────────────────────────────────────────────────────── */

	public function test_wrong_tries_are_counted_against_the_code_whatever_address_they_come_from(): void {
		$c   = $this->ask( 'jordan@example.com' );
		$bad = zdz_test_signin_wrong( $c );
		for ( $i = 1; $i <= 5; $i++ ) {
			$r = $this->claim( $bad, 'jordan@example.com' );   // five wrong tries, five connecting addresses
			$this->assertSame( 0, $r['in'] );
		}
		$this->assertSame( 0, $this->claim( $c, 'jordan@example.com' )['in'], 'then the RIGHT code, from a sixth address, is refused: that code is dead' );
		$this->assertCount( 1, self::told( 1, 'wrong sign-in codes were tried' ), 'the manager is told, once' );

		$c = $this->ask( 'jordan@example.com' );
		$this->assertSame( 12, $this->claim( $c, 'jordan@example.com' )['in'], 'the account is NOT locked: Jordan asks again and the new code works at once' );

		$c = $this->ask( 'jordan@example.com' );
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->claim( zdz_test_signin_wrong( $c ), 'jordan@example.com' );
		}
		$this->assertCount( 1, self::told( 1, 'wrong sign-in codes were tried' ), "a second round in the same 15 minutes adds no second notice (a stranger can't fill the bell)" );
		$this->assertCount( 1, self::events( 12, 'code_killed' ), '... and no second line on the page' );

		$this->assertSame( 11, $this->claim( $this->ask( 'alex@example.com' ), 'alex@example.com' )['in'], 'another account is not affected' );

		$c = $this->ask( 'jordan@example.com' );
		for ( $i = 1; $i <= 4; $i++ ) {
			$this->claim( zdz_test_signin_wrong( $c ), 'jordan@example.com' );
		}
		$this->assertSame( 12, $this->claim( $c, 'jordan@example.com' )['in'], 'four wrong tries, then the right code: signed in' );
	}

	public function test_a_strangers_guesses_do_not_cancel_a_code_a_person_gave(): void {
		$given = $this->give( 12 );
		$this->assertSame( 8, strlen( (string) $given ), 'the manager gives Jordan a code: eight digits' );
		for ( $i = 1; $i <= 30; $i++ ) {
			$this->claim( '111111', 'jordan@example.com' );   // a stranger who knows the address, six-digit guesses
		}
		$this->assertSame( 12, $this->claim( $given, 'jordan@example.com' )['in'], 'thirty wrong six-digit tries do not cancel it' );

		$given = $this->give( 12 );
		for ( $i = 1; $i <= 20; $i++ ) {
			$this->claim( '10000000', 'jordan@example.com' );
		}
		$this->assertSame( 12, $this->claim( $given, 'jordan@example.com' )['in'], 'nor do twenty wrong EIGHT-digit tries (it takes 300)' );

		$given = $this->give( 12 );
		for ( $i = 1; $i <= 300; $i++ ) {
			ZDZ_Signin_Routes::claim( 'jordan@example.com', '10000000' );
		}
		$this->assertSame( 0, $this->claim( $given, 'jordan@example.com' )['in'], '300 wrong eight-digit tries do cancel it' );
		$this->assertCount( 1, self::told( 1, 'cancelled' ), 'and the manager is told' );
		$kept = serialize( $GLOBALS['wpdb']->written ) . serialize( get_option( 'zdz_signin_log' ) ) . serialize( get_option( 'zdz_signin_events' ) );
		foreach ( $this->seen as $code ) {
			$this->assertStringNotContainsString( $code, $kept, 'no code that was given is in a notice or either log' );
		}
	}

	public function test_the_site_wide_backstop_counts_six_digit_misses_and_never_stops_a_given_code(): void {
		$GLOBALS['zdz_test_filters']['zdz_magic_link_global_miss_ceiling'] = function () { return 3; };
		$given = $this->give( 12 );
		$mine  = $this->ask( 'alex@example.com' );
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->assertSame( 'invalid_code', $this->claim( '111111', 'nobody@example.com' )['err'] );
		}
		$r = $this->claim( $mine, 'alex@example.com' );
		$this->assertSame( 'rate_limited', $r['err'], 'past the ceiling a six-digit claim is not weighed at all' );
		$this->assertSame( 429, $r['r']->get_error_data()['status'] );
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->assertSame( 'invalid_code', $this->claim( '10000000', 'jordan@example.com' )['err'], 'an eight-digit try is weighed against its own code' );
		}
		$this->assertSame( 12, $this->claim( $given, 'jordan@example.com' )['in'], 'a flood of six-digit guesses does not keep out someone holding a code a person gave' );
		$this->assertSame( 3, (int) get_transient( 'zdz_ml_bridge_code_miss_global' ), 'and eight-digit misses are not counted there' );

		unset( $GLOBALS['zdz_test_filters']['zdz_magic_link_global_miss_ceiling'] );
		$this->assertSame( 11, $this->claim( $mine, 'alex@example.com' )['in'], 'the default ceiling is far above a few misses' );
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-magic-link-bridge.php' );
		$this->assertStringContainsString( "apply_filters( 'zdz_magic_link_global_miss_ceiling', 1800 )", $src, 'thirty connecting addresses at their own ceiling of sixty' );
	}

	/* ── the same answer; odd input ────────────────────────────────────────── */

	public function test_an_unknown_address_and_a_wrong_code_get_the_same_answer(): void {
		$c  = $this->ask( 'alex@example.com' );
		$r1 = $this->claim( $c, 'nobody@example.com' );
		$r2 = $this->claim( zdz_test_signin_wrong( $c ), 'alex@example.com' );
		$this->assertInstanceOf( 'WP_Error', $r1['r'] );
		$this->assertInstanceOf( 'WP_Error', $r2['r'] );
		$this->assertSame( $r1['r']->get_error_code(), $r2['r']->get_error_code() );
		$this->assertSame( $r1['r']->get_error_message(), $r2['r']->get_error_message() );
		$this->assertSame( $r1['r']->get_error_data(), $r2['r']->get_error_data() );
		$this->assertSame( 401, $r2['r']->get_error_data()['status'] );
		$this->assertStringContainsString( 'same email you asked for the code with', $r2['r']->get_error_message() );
		$this->assertSame( 11, $this->claim( $c, 'alex@example.com' )['in'], "a wrong try or two does not spoil the real owner's code" );
	}

	public function test_odd_input_is_refused_not_a_fault(): void {
		$c = $this->ask( 'alex@example.com' );
		foreach ( array( array( 'alex@example.com' ), 42, true, 'alex', '' ) as $email ) {
			$r = $this->claim( $c, $email );
			$this->assertSame( 0, $r['in'] );
			$this->assertInstanceOf( 'WP_Error', $r['r'], 'answered, whatever was sent as the email' );
		}
		foreach ( array( array( $c ), null, '12345', '1234567', '123456789', 'abcdef' ) as $code ) {
			$r = $this->claim( $code, 'alex@example.com' );
			$this->assertSame( 'invalid_code', $r['err'], 'a code of another shape is a miss' );
		}
		$this->assertSame( 5, get_user_meta( 11, 'zdz_signin_codes', true )[0]['left'], 'and none of them cost the live code a try' );
		$this->assertSame( 11, $this->claim( substr( $c, 0, 3 ) . ' ' . substr( $c, 3 ), 'alex@example.com' )['in'], 'a space in the code is not part of it' );
	}

	public function test_the_claim_route_takes_six_or_eight_digits_and_an_optional_email_that_is_a_string(): void {
		if ( ! zdz_test_signin_has( 'register_rest_route' ) ) {
			$this->markTestSkipped( 'a register_rest_route() stand-in loaded earlier does not keep the routes' );
		}
		$this->bridge->register_routes();
		$routes = (array) $GLOBALS['zdz_test_signin_rest'];
		$this->assertSame( array( '/magic-link-init', '/magic-link-status', '/magic-link-claim', '/magic-link-code-claim', '/magic-link-send-code' ), array_keys( $routes ), 'no new route' );
		$args = $routes['/magic-link-code-claim']['args'];
		$this->assertTrue( $args['code']['required'] );
		foreach ( array( '123456' => true, '12345678' => true, '12345' => false, '1234567' => false, '123456789' => false, '12345a' => false, '123 456' => false ) as $code => $ok ) {
			$this->assertSame( $ok, $args['code']['validate_callback']( (string) $code ), (string) $code );
		}
		$this->assertFalse( $args['code']['validate_callback']( array( '123456' ) ) );
		$this->assertFalse( $args['email']['required'], 'a page loaded before the email went with the code gets the plain sentence' );
		$this->assertTrue( $args['email']['validate_callback']( 'alex@example.com' ) );
		$this->assertFalse( $args['email']['validate_callback']( array( 'alex@example.com' ) ), 'a list never reaches sanitize_email()' );
		$this->assertSame( 'sanitize_email', $args['email']['sanitize_callback'] );
	}

	/* ── the backup address ────────────────────────────────────────────────── */

	public function test_a_code_asked_for_with_the_backup_address_goes_to_it_and_is_claimed_only_with_it(): void {
		$c     = $this->ask( 'alex.home@example.net' );
		$mails = self::mails();
		$this->assertSame( 'alex.home@example.net', end( $mails )['to'] );
		$this->assertSame( 11, $this->claim( $c, 'alex.home@example.net' )['in'] );
		$this->assertGreaterThan( 0, ZDZ_Signin_Routes::tested_at( 11, 'alex.home@example.net' ), 'that address now counts as tested' );
		$this->assertSame( 'tested', ZDZ_Signin_Routes::cover( 11 ) );
		$this->assertSame( 'backup', get_user_meta( 11, 'zdz_signin_last', true )['route'] );

		$c = $this->ask( 'alex.home@example.net' );
		$this->assertSame( 0, $this->claim( $c, 'alex@example.com' )['in'], 'not with the work address' );
		$this->assertSame( 11, $this->claim( $c, 'ALEX.Home@example.net' )['in'] );
	}

	public function test_a_backup_address_given_before_an_account_got_no_routes_signs_nobody_in(): void {
		// both were given a backup address before one went into Privacy View and the other became the shared device
		$GLOBALS['zdz_test_user_meta'][3]['zdz_login_aliases'] = array( 'devin.home@example.net' );
		$GLOBALS['zdz_test_user_meta'][9]['zdz_login_aliases'] = array( 'device.home@example.net' );

		$this->assertNull( ZDZ_Magic_Link_Bridge::user_by_alias( 'devin.home@example.net' ) );
		$this->assertNull( ZDZ_Magic_Link_Bridge::user_by_alias( 'device.home@example.net' ) );
		$this->assertSame( '', $this->ask( 'devin.home@example.net' ), 'no code is mailed to it' );
		$this->assertSame( '', $this->ask( 'device.home@example.net' ) );
		$this->assertCount( 0, self::mails() );
		$this->assertSame( 11, ZDZ_Magic_Link_Bridge::user_by_alias( 'Alex.Home@example.net' )->ID, 'a backup address of a person with routes still works' );

		$this->assertSame( 3, $this->claim( $this->ask( 'devin@example.com' ), 'devin@example.com' )['in'], 'the account\'s own address is not a backup route' );
	}

	public function test_the_log_names_the_account_by_number_and_never_the_address_a_code_went_to(): void {
		$this->ask( 'alex.home@example.net' );
		$this->ask( 'alex@example.com' );
		$log = (string) file_get_contents( $this->log );
		$this->assertStringContainsString( 'sign-in code emailed for user #11 to a backup address', $log );
		$this->assertStringContainsString( 'sign-in code emailed for user #11 to the account address', $log );
		$this->assertStringNotContainsString( 'alex.home@example.net', $log );
		$this->assertStringNotContainsString( 'alex@example.com', $log );
	}

	/* ── the login plugin's own form, and the "You're logged in" page ──────── */

	public function test_the_login_plugins_link_email_becomes_a_code_email_claimed_with_the_email(): void {
		$args = $this->link_mail( 'jordan@example.com' );
		$c    = $this->code_in( $args );
		$this->assertSame( 6, strlen( $c ) );
		$this->assertStringNotContainsString( 'magic-login', $args['message'], 'no link is left in it' );
		$this->assertSame( 12, $this->claim( $c, 'jordan@example.com' )['in'] );

		$html = $this->link_mail( 'jordan@example.com', array( 'message' => '<a href="' . self::LINK . '">in</a>', 'headers' => array( 'Content-Type: text/html; charset=UTF-8' ), 'attachments' => array( '/example/logo.png' ) ) );
		$this->code_in( $html );
		$this->assertSame( array(), $html['attachments'], 'as plain text with nothing attached' );
		$this->assertStringNotContainsString( 'text/html', implode( ' ', (array) $html['headers'] ) );
		$this->assertStringContainsString( 'text/plain', implode( ' ', (array) $html['headers'] ) );

		$other = array( 'to' => 'jordan@example.com', 'subject' => 'Your receipt', 'message' => 'Thank you.', 'headers' => array(), 'attachments' => array() );
		$this->assertSame( $other, $this->bridge->inject_otp_into_magic_login_email( $other ), 'any other mail the site sends passes through untouched' );
	}

	public function test_that_form_is_held_to_the_same_six_and_past_it_the_email_carries_no_code_and_no_link(): void {
		$n    = 0;
		$last = array();
		for ( $i = 0; $i < 8; $i++ ) {
			$last = $this->link_mail( 'jordan@example.com' );
			$n   += '' !== $this->code_in( $last ) ? 1 : 0;
		}
		$this->assertSame( 6, $n );
		$this->assertDoesNotMatchRegularExpression( '/\d{3} \d{3}|magic-login/', $last['message'] . $last['subject'] );
		$this->assertStringContainsString( 'no login code this time', $last['subject'] );
		$this->assertSame( array(), $last['attachments'] );

		$GLOBALS['wpdb']->allow = 1;   // the ask is counted, then another request holds the account
		$m = $this->link_mail( 'casey@example.com' );
		$GLOBALS['wpdb']->allow = -1;
		$this->assertDoesNotMatchRegularExpression( '/\d{3} \d{3}|magic-login/', $m['message'] . $m['subject'], 'nor when the code could not be kept' );
	}

	public function test_that_form_with_a_backup_address_typed_keeps_the_code_under_what_was_typed(): void {
		$GLOBALS['zdz_test_filters']['zdz_has_magic_login'] = function () { return true; };
		$_POST = array( 'log' => 'CASEY.Home@example.net' );
		$this->bridge->premap_login_alias();
		$this->assertSame( 'casey@example.com', $_POST['log'], 'the login plugin is handed the account address' );
		$m = $this->link_mail( $_POST['log'] );
		$_POST = array();
		$c     = $this->code_in( $m );
		$this->assertSame( 'casey@example.com', $m['to'], 'its email goes to the account address' );
		$this->assertSame( 0, $this->claim( $c, 'casey@example.com' )['in'] );
		$this->assertSame( 14, $this->claim( $c, 'casey.home@example.net' )['in'], 'and the code is claimed with what was typed' );
		$this->assertSame( 0, ZDZ_Signin_Routes::tested_at( 14, 'casey.home@example.net' ), 'that sign-in does not mark the backup address tested: its mailbox received nothing' );
		$this->assertSame( 'untested', ZDZ_Signin_Routes::cover( 14 ) );
		$sends = get_user_meta( 14, 'zdz_signin_sends', true );
		$this->assertSame( array( md5( 'casey.home@example.net' ) ), array_keys( $sends ), 'and it is counted under the address that was typed' );

		// what was typed in ONE request's form is never used for another account's email in the same request
		$_POST = array( 'log' => 'casey.home@example.net' );
		$this->bridge->premap_login_alias();
		$_POST = array();
		$c     = $this->code_in( $this->link_mail( 'alex@example.com' ) );
		$this->assertSame( 0, $this->claim( $c, 'casey.home@example.net' )['in'] );
		$this->assertSame( 11, $this->claim( $c, 'alex@example.com' )['in'], "an email for Alex is kept under Alex's address" );
	}

	public function test_without_the_login_plugin_no_typed_address_is_read_from_a_post(): void {
		$_POST = array( 'log' => 'casey.home@example.net' );
		$this->bridge->premap_login_alias();
		$this->assertSame( 'casey.home@example.net', $_POST['log'], 'the post is left as it was' );
		$_POST = array();
		$c     = $this->code_in( $this->link_mail( 'casey@example.com' ) );
		$this->assertSame( 14, $this->claim( $c, 'casey@example.com' )['in'] );
	}

	public function test_the_logged_in_pages_code_needs_the_email_and_retires_its_token(): void {
		$before          = array_keys( (array) $GLOBALS['zdz_test_transients'] );
		list( $url, $c ) = $this->logged_in_page( 11 );
		$this->assertSame( 6, strlen( $c ), 'the page is given a code' );
		$this->assertStringContainsString( 'magic-login-bridge=1', $url );
		$tokens = array_values( array_filter( array_diff( array_keys( (array) $GLOBALS['zdz_test_transients'] ), $before ), function ( $k ) { return false !== strpos( (string) $k, '_token_' ); } ) );
		$this->assertCount( 1, $tokens );

		$this->assertSame( 0, $this->claim( $c )['in'], 'that code alone signs nobody in' );
		$this->assertSame( 0, $this->claim( $c, 'jordan@example.com' )['in'] );
		$this->assertSame( 11, $this->claim( $c, 'alex.home@example.net' )['in'], 'with any address of the account it signs them in' );
		$this->assertSame( 0, $this->claim( $c, 'alex@example.com' )['in'], 'once' );
		$this->assertFalse( get_transient( $tokens[0] ), 'the token made with that code is retired with it' );
		$this->assertSame( 'bridge', get_user_meta( 11, 'zdz_signin_last', true )['route'] );

		$GLOBALS['wpdb']->held = array( zdz_test_signin_lock( 14 ) );
		list( $url, $c )       = $this->logged_in_page( 14 );
		$GLOBALS['wpdb']->held = array();
		$this->assertSame( '', $c, 'the page is given no code when the code could not be kept' );
		$this->assertStringContainsString( 'magic-login-bridge=1', $url );

		$page = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz/page-login.php' );
		$this->assertStringContainsString( 'then enter your email and the code above', $page, 'the page says to enter the email with the code' );
	}

	/* ── limits a stranger can't turn against the person ───────────────────── */

	public function test_sends_are_capped_per_typed_address_and_no_ask_inside_the_cap_pushes_a_live_code_out(): void {
		$first = $this->ask( 'blake@example.com' );
		for ( $i = 0; $i < 7; $i++ ) {
			$this->ask( 'blake@example.com' );   // eight asks for one address from eight connecting addresses
		}
		$this->assertCount( 6, self::mails(), 'six emails' );
		$this->assertCount( 1, self::events( 16, 'send_refused' ), 'the refused asks are one line on Sign-in routes' );
		$this->assertSame( 16, $this->claim( $first, 'blake@example.com' )['in'], 'the first code is still live' );

		$mine = $this->ask( 'alex.home@example.net' );
		for ( $i = 0; $i < 8; $i++ ) {
			$this->ask( 'alex@example.com' );   // a stranger, with the work address
		}
		$theirs = $this->code_in( self::mails()[ count( self::mails() ) - 1 ] );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->claim( zdz_test_signin_wrong( $theirs ), 'alex@example.com' );   // ... and five wrong codes typed with it
		}
		for ( $i = 0; $i < 4; $i++ ) {
			$this->logged_in_page( 11 );
		}
		$this->assertSame( 11, $this->claim( $mine, 'alex.home@example.net' )['in'], "asks and wrong codes at the work address, and the logged-in page's codes, leave the backup address's code alone" );
		$this->assertNotSame( '', $this->ask( 'alex.home@example.net' ), 'and the backup address has its own count' );
	}

	public function test_one_connecting_address_has_its_own_share_of_an_addresss_sends(): void {
		$sent = 0;
		for ( $i = 0; $i < 5; $i++ ) {
			$sent += '' !== $this->ask( 'jordan@example.com', '198.51.100.20' ) ? 1 : 0;
		}
		$this->assertSame( 3, $sent, 'three codes for one address of one account from one connecting address' );
		$this->assertNotSame( '', $this->ask( 'jordan@example.com', '198.51.100.21' ), "so a stranger filling it from their own address does not stop the owner's ask from theirs" );
		$this->assertNotSame( '', $this->ask( 'casey.home@example.net', '198.51.100.20' ), 'another address has its own share' );
		$this->assertNotSame( '', $this->ask( 'casey@example.com', '198.51.100.20' ) );
		$this->assertNotSame( '', $this->ask( 'alex@example.com', '198.51.100.20' ), 'and so has another account' );

		$sent = 0;
		for ( $i = 0; $i < 5; $i++ ) {
			$sent += '' !== $this->ask( 'alex@example.com', '198.51.100.22' ) ? 1 : 0;
		}
		$this->assertSame( 3, $sent );
		$this->assertNotSame( '', $this->ask( 'alex.home@example.net', '198.51.100.22' ), "the share is per typed address: asks made with the work address from one connection never stop a code going to the same person's backup address from it" );
	}

	public function test_an_office_behind_one_connecting_address_still_gets_its_codes(): void {
		for ( $i = 20; $i < 32; $i++ ) {
			zdz_test_signin_user( $i, "crew$i@example.com", "Crew $i", array( 'zdz_tech' ) );
			$this->assertNotSame( '', $this->ask( "crew$i@example.com", '198.51.100.7' ) );
		}
		$this->assertCount( 12, self::mails(), 'twelve people asking from ONE address each get their code' );
		$stopped = 0;
		for ( $i = 0; $i < 25; $i++ ) {
			$this->from( '198.51.100.7' );
			$r        = $this->bridge->handle_send_code( new WP_REST_Request( array( 'email' => "nobody$i@example.com" ) ) );
			$stopped += ( $r instanceof WP_Error && 'rate_limited' === $r->get_error_code() ) ? 1 : 0;
		}
		$this->assertSame( 7, $stopped, 'one address is still stopped: after 30 asks in 10 minutes' );

		$stopped = 0;
		for ( $i = 0; $i < 70; $i++ ) {
			$stopped += 'rate_limited' === $this->claim( '123456', 'nobody@example.com', '198.51.100.8' )['err'] ? 1 : 0;
		}
		$this->assertSame( 10, $stopped, '... and after 60 claims in 5 minutes' );
		$this->assertSame( 'invalid_code', $this->claim( '123456', 'nobody@example.com', '198.51.100.9' )['err'], 'another address is not' );

		$GLOBALS['zdz_test_filters']['zdz_magic_link_ip_attempt_ceiling'] = function () { return 2; };
		$GLOBALS['zdz_test_filters']['zdz_magic_link_ip_send_ceiling']    = function () { return 1; };
		$this->claim( '123456', 'nobody@example.com', '198.51.100.30' );
		$this->claim( '123456', 'nobody@example.com', '198.51.100.30' );
		$this->assertSame( 'rate_limited', $this->claim( '123456', 'nobody@example.com', '198.51.100.30' )['err'], 'a site may set either ceiling' );
		$this->from( '198.51.100.31' );
		$this->bridge->handle_send_code( new WP_REST_Request( array( 'email' => 'nobody@example.com' ) ) );
		$r = $this->bridge->handle_send_code( new WP_REST_Request( array( 'email' => 'nobody@example.com' ) ) );
		$this->assertInstanceOf( 'WP_Error', $r );
	}

	public function test_the_limits_key_on_the_connecting_address_never_a_header_the_sender_writes(): void {
		$GLOBALS['zdz_test_filters']['zdz_magic_link_ip_attempt_ceiling'] = function () { return 2; };
		for ( $i = 0; $i < 3; $i++ ) {
			$_SERVER['REMOTE_ADDR']          = '198.51.100.40';
			$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.' . ( 50 + $i );
			$r = $this->bridge->handle_code_claim( new WP_REST_Request( array( 'code' => '123456', 'email' => 'nobody@example.com' ) ) );
		}
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		$this->assertSame( 'rate_limited', $r->get_error_code(), 'a new forwarded-for value each time is still the same connection' );
	}

	public function test_a_code_that_could_not_be_kept_is_not_emailed_and_every_lock_is_given_back(): void {
		$GLOBALS['wpdb']->allow = 1;   // the send is counted, then another request holds the account
		$this->from();
		$r = $this->bridge->handle_send_code( new WP_REST_Request( array( 'email' => 'riley@example.com' ) ) );
		$GLOBALS['wpdb']->allow = -1;
		$this->assertCount( 0, self::mails() );
		$this->assertTrue( $r->data['success'], 'the screen is told the same' );

		$this->claim( $this->ask( 'riley@example.com' ), 'riley@example.com' );
		$this->claim( '111111', 'riley@example.com' );
		$this->assertSame( array(), $GLOBALS['wpdb']->open_locks() );
		$this->assertGreaterThan( 3, count( $GLOBALS['wpdb']->taken ) );
	}

	/* ── a send the mailer refuses ─────────────────────────────────────────── */

	public function test_a_send_the_mailer_refuses_is_recorded_and_the_answer_is_the_same(): void {
		$GLOBALS['zdz_test_signin_mail_ok'] = false;
		$this->from();
		$r = $this->bridge->handle_send_code( new WP_REST_Request( array( 'email' => 'morgan@example.com' ) ) );
		$this->code_in( self::mails()[0] );
		$this->assertInstanceOf( 'WP_REST_Response', $r );
		$this->assertTrue( $r->data['success'], 'the screen is still told "sent"' );
		$this->assertCount( 1, self::events( 1, 'mail_failed' ), 'Sign-in routes has a "could not send" line for that account' );

		$unknown = $this->bridge->handle_send_code( new WP_REST_Request( array( 'email' => 'nobody@example.com' ) ) );
		$this->assertSame( $r->data, $unknown->data, 'and an address that is no account is told the same' );
	}

	/* ── an account in Privacy View ────────────────────────────────────────── */

	public function test_an_account_in_privacy_view_is_refused_every_sign_in_route(): void {
		$c = $this->ask( 'alex@example.com' );
		$GLOBALS['zdz_test_current_user'] = 3;   // Devin, in Privacy View, is who the request runs as
		$calls = array(
			'handle_init'       => array( 'request_id' => '00000000-0000-4000-8000-000000000000', 'email' => 'alex@example.com' ),
			'handle_status'     => array( 'request_id' => '00000000-0000-4000-8000-000000000000' ),
			'handle_claim'      => array( 'bridge_token' => str_repeat( 'a', 64 ) ),
			'handle_code_claim' => array( 'code' => $c, 'email' => 'alex@example.com' ),
			'handle_send_code'  => array( 'email' => 'alex@example.com' ),
		);
		foreach ( $calls as $handler => $params ) {
			$r = $this->bridge->$handler( new WP_REST_Request( $params ) );
			$this->assertInstanceOf( 'WP_Error', $r, $handler );
			$this->assertSame( 'zdz_privacy_view', $r->get_error_code(), $handler );
			$this->assertSame( 403, $r->get_error_data()['status'], $handler );
		}
		$this->assertSame( 3, $GLOBALS['zdz_test_current_user'], 'and it is still who it was' );
		$this->assertCount( 1, self::mails(), 'no second code was sent' );
		$this->assertSame( 11, $this->claim( $c, 'alex@example.com' )['in'], "Alex's code was not spent by the refusal" );

		$this->assertSame( 3, $this->claim( $this->ask( 'devin@example.com' ), 'devin@example.com' )['in'], 'signed out, that account still signs in with an emailed code to its own address' );
	}

	/* ── no code in any log line ───────────────────────────────────────────── */

	public function test_no_code_is_in_any_log_line_notice_audit_row_or_stored_value(): void {
		$this->claim( $this->ask( 'alex@example.com' ), 'alex@example.com' );
		$this->ask( 'alex.home@example.net' );
		$this->claim( $this->code_in( $this->link_mail( 'jordan@example.com' ) ), 'jordan@example.com' );
		$this->logged_in_page( 14 );
		$given = $this->give( 12 );
		$this->claim( $given, 'jordan@example.com' );
		$this->give( 13 );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->claim( '111111', 'alex.home@example.net' );
		}
		$this->assertGreaterThanOrEqual( 6, count( array_unique( $this->seen ) ) );

		$log = (string) file_get_contents( $this->log );
		$this->assertStringContainsString( 'ZDZ_Magic_Link_Bridge', $log, 'the PHP error log was written to (the check reads something)' );
		$text = $log . serialize( get_option( 'zdz_signin_log', array() ) ) . serialize( get_option( 'zdz_signin_events', array() ) )
			. serialize( $GLOBALS['wpdb']->written ) . serialize( $GLOBALS['zdz_test_user_meta'] ) . serialize( $GLOBALS['zdz_test_transients'] ) . serialize( $GLOBALS['zdz_test_options'] );
		foreach ( array_unique( $this->seen ) as $c ) {
			$cut = 8 === strlen( $c ) ? 4 : 3;
			$this->assertStringNotContainsString( $c, $text );
			$this->assertStringNotContainsString( substr( $c, 0, $cut ) . ' ' . substr( $c, $cut ), $text );
		}
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-magic-link-bridge.php' );
		$this->assertDoesNotMatchRegularExpression( '/error_log\([^;]*(short_code|code_display|\$code\b)/s', $src, 'the bridge has no log line that takes the code' );
		$this->assertSame( 2, substr_count( $src, "ZDZ_Login_Next::url_or( home_url( '/' ) )" ), 'both claims still return to the page that asked for the sign-in' );
	}
}
