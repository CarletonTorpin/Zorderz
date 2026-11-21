<?php
/**
 * ZDZ_Signin_Routes, the codes: every sign-in code is kept per account as a salted hash and claimed with the email
 * it is for.
 *
 *   - Two people can be given the same digits; each signs in as themselves. A code is used once.
 *   - A wrong try costs only the codes asked for with the address typed in that claim (and the "bridge" codes); a
 *     wrong eight-digit try costs only a code a person gave. There is no lock on the account.
 *   - An emailed code is claimed only with the address it was asked with; six live codes are kept per address and
 *     six emails go to one address in fifteen minutes, so no ask inside the cap pushes a live code out.
 *   - One writer at a time per account; a code that could not be kept is never handed out.
 *   - A backup address counts as tested only when a code emailed to it signed the person in.
 *   - No code, and no address, is in a log, a notice, an audit row or any stored value; nothing is keyed by the
 *     WordPress salts.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/signin-test-doubles.php';

final class SigninCodesTest extends TestCase {

	const WORK   = 'jordan@example.com';
	const BACKUP = 'jordan.home@example.net';

	private $log_was = '';
	private $log     = '';

	protected function setUp(): void {
		zdz_test_signin_reset();
		zdz_test_signin_people();
		$GLOBALS['zdz_test_user_meta'][12]['zdz_login_aliases'] = array( self::BACKUP );
		$GLOBALS['zdz_test_options']['zdz_signin_managers']     = array( 1 );   // Morgan manages sign-in routes
		if ( ! ( get_user_by( 'email', 'JORDAN@example.com' ) instanceof WP_User ) || ! ( get_user_by( 'id', 12 ) instanceof WP_User ) ) {
			$this->markTestSkipped( 'a get_user_by() stand-in loaded earlier does not know these accounts' );
		}
		// What the code under test logs is read back, so "no code in any log line" is checked on what was written.
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

	/** Who a claim signs in: an account id, or 0. */
	private static function who( $email, $code ): int {
		$c = ZDZ_Signin_Routes::claim( $email, $code );
		return $c ? (int) $c['user']->ID : 0;
	}

	private static function codes( int $uid ): array {
		$c = get_user_meta( $uid, 'zdz_signin_codes', true );
		return is_array( $c ) ? $c : array();
	}

	private static function mail( int $uid, string $code, string $to, array $more = array() ): bool {
		return ZDZ_Signin_Routes::put_code( $uid, $code, 'mail', 300, array( 'to' => $to ) + $more );
	}

	/** The manager gives a code, as themselves. */
	private static function give( int $target, bool $replace = false ) {
		zdz_test_signin_as( 1 );
		return ZDZ_Signin_Routes::issue( 1, $target, $replace );
	}

	private static function told( int $uid, string $in_title ): array {
		return array_values( array_filter( $GLOBALS['wpdb']->notices(), function ( $n ) use ( $uid, $in_title ) {
			return $uid === $n['to'] && false !== strpos( $n['title'], $in_title );
		} ) );
	}

	private static function events( int $uid, string $what ): array {
		return array_values( array_filter( (array) get_option( 'zdz_signin_events', array() ), function ( $e ) use ( $uid, $what ) {
			return $uid === $e['u'] && $what === $e['what'];
		} ) );
	}

	/* ── a claim is the email and the code ─────────────────────────────────── */

	public function test_a_code_is_kept_as_a_salted_hash_and_claimed_with_its_email_once(): void {
		$this->assertTrue( self::mail( 11, '246810', 'alex@example.com' ) );
		$kept = self::codes( 11 );
		$this->assertCount( 1, $kept );
		$this->assertSame( array( 's', 'h', 'exp', 'kind', 'left', 'to', 'sent' ), array_keys( $kept[0] ) );
		$this->assertSame( 64, strlen( $kept[0]['h'] ), 'a SHA-256 of the code under its own salt' );
		$stored = serialize( $GLOBALS['zdz_test_user_meta'] ) . serialize( $GLOBALS['zdz_test_options'] ) . serialize( $GLOBALS['zdz_test_transients'] );
		$this->assertStringNotContainsString( '246810', $stored, 'the code itself is not stored' );
		$this->assertStringNotContainsString( '018642', $stored, 'nor backwards' );

		$this->assertSame( 0, self::who( 'jordan@example.com', '246810' ), "with someone else's email it signs nobody in" );
		$this->assertSame( 0, self::who( 'nobody@example.com', '246810' ), 'nor with an address that is no account' );
		$this->assertSame( 0, self::who( '', '246810' ), 'nor with none' );
		$this->assertSame( 11, self::who( 'ALEX@example.com', '246810' ), 'with its own email it does, however the address is spelled' );
		$this->assertSame( 0, self::who( 'alex@example.com', '246810' ), 'once' );
		$this->assertSame( array(), self::codes( 11 ) );
	}

	public function test_two_people_given_the_same_digits_each_sign_in_as_themselves(): void {
		self::mail( 11, '135790', 'alex@example.com' );
		self::mail( 12, '135790', self::WORK );
		$this->assertNotSame( self::codes( 11 )[0]['h'], self::codes( 12 )[0]['h'], 'the same digits are not the same record' );
		$this->assertSame( 11, self::who( 'alex@example.com', '135790' ) );
		$this->assertSame( 12, self::who( self::WORK, '135790' ) );
		$named = array_filter( array_keys( (array) $GLOBALS['zdz_test_transients'] ), function ( $k ) { return (bool) preg_match( '/\d{6}/', (string) $k ); } );
		$this->assertSame( array(), $named, 'nothing is stored under a name made of the code' );
	}

	public function test_only_a_code_of_the_right_shape_and_kind_is_kept(): void {
		$r = 'ZDZ_Signin_Routes';
		$this->assertFalse( $r::put_code( 12, '123456', 'issued', 600 ), 'a given code has eight digits' );
		$this->assertFalse( self::mail( 12, '12345678', self::WORK ), 'an emailed one has six' );
		$this->assertFalse( self::mail( 12, '12345', self::WORK ) );
		$this->assertFalse( self::mail( 12, '1234567', self::WORK ) );
		$this->assertFalse( $r::put_code( 12, '123456', 'sms', 300 ), 'a kind that does not exist' );
		$this->assertFalse( $r::put_code( 12, '123456', 'mail', 0, array( 'to' => self::WORK ) ), 'a life of nothing' );
		$this->assertFalse( $r::put_code( 12, '123456', 'mail', 300 ), 'an emailed code with no address could be claimed with none' );
		$this->assertFalse( $r::put_code( 12, '123456', 'mail', 300, array( 'to' => ' ' ) ) );
		$this->assertFalse( $r::put_code( 0, '123456', 'bridge', 300 ) );
		$this->assertFalse( $r::put_code( 9, '12345678', 'issued', 600 ), 'the shared device gets no given code' );
		$this->assertFalse( $r::put_code( 3, '12345678', 'issued', 600 ), 'nor an account in Privacy View' );
		$this->assertSame( array(), self::codes( 12 ) );

		self::mail( 12, '222222', self::WORK );
		$this->assertSame( 0, self::who( self::WORK, '2222' ), 'a short code' );
		$this->assertSame( 0, self::who( self::WORK, '2222221' ), 'a seven-digit code' );
		$this->assertSame( 5, self::codes( 12 )[0]['left'], 'neither is a try' );
		$this->assertSame( 8, strlen( $r::new_code() ) );
		$this->assertMatchesRegularExpression( '/^[1-9][0-9]{7}$/', $r::new_code() );
		$this->assertStringContainsString( 'return (string) random_int( 10000000, 99999999 );', (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-signin-routes.php' ), 'drawn from all 90 million eight-digit numbers' );
	}

	/* ── wrong tries are counted against the code ──────────────────────────── */

	public function test_a_wrong_try_costs_only_the_codes_asked_for_with_the_typed_address(): void {
		self::mail( 12, '333111', self::BACKUP );
		self::mail( 12, '333118', self::WORK );
		ZDZ_Signin_Routes::put_code( 12, '444114', 'bridge', 300 );
		self::who( self::WORK, '999999' );
		self::who( self::WORK, '999999' );
		$left = array_column( self::codes( 12 ), 'left', 'kind' );
		$this->assertSame( 3, $left['bridge'], 'each wrong try costs a code one of its five' );
		for ( $i = 0; $i < 3; $i++ ) {
			self::who( self::WORK, '999999' );
		}
		$this->assertSame( 0, self::who( self::WORK, '333118' ), 'five wrong tries typed with the work address kill what was asked for with it' );
		$this->assertSame( 0, self::who( self::WORK, '444114' ), '... and the "bridge" codes' );
		$this->assertSame( 12, self::who( self::BACKUP, '333111' ), '... and leave the code asked for with the backup address alone' );
	}

	public function test_there_is_no_lock_on_the_account(): void {
		self::mail( 12, '555111', self::WORK );
		for ( $i = 0; $i < 4; $i++ ) {
			self::who( self::WORK, '555000' );
		}
		$this->assertSame( 12, self::who( self::WORK, '555111' ), 'four wrong tries, then the right code: signed in' );

		self::mail( 12, '555222', self::WORK );
		for ( $i = 0; $i < 5; $i++ ) {
			self::who( self::WORK, '555000' );
		}
		$this->assertSame( 0, self::who( self::WORK, '555222' ), 'the fifth wrong try kills that code' );
		$this->assertCount( 1, self::told( 1, 'Wrong sign-in codes' ), 'the manager is told' );
		$this->assertCount( 1, self::events( 12, 'code_killed' ) );

		self::mail( 12, '555333', self::WORK );
		$this->assertSame( 12, self::who( self::WORK, '555333' ), 'the next code works at once' );

		self::mail( 12, '555444', self::WORK );
		for ( $i = 0; $i < 5; $i++ ) {
			self::who( self::WORK, '555000' );
		}
		$this->assertCount( 1, self::told( 1, 'Wrong sign-in codes' ), 'a second round in the same 15 minutes adds no second notice' );
		$this->assertCount( 1, self::events( 12, 'code_killed' ), '... and no second line' );
		self::mail( 11, '555555', 'alex@example.com' );
		$this->assertSame( 11, self::who( 'alex@example.com', '555555' ), 'another account is not affected' );
	}

	public function test_a_days_worth_of_wrong_tries_pauses_emailed_codes_and_nothing_else(): void {
		$this->assertSame( 50, ZDZ_Signin_Routes::day_misses() );
		for ( $i = 0; $i < 60; $i++ ) {
			self::who( self::WORK, '777000' );   // no live code to weigh against: these cannot succeed and are not counted
		}
		$this->assertFalse( ZDZ_Signin_Routes::codes_paused( 12 ), 'guesses with nothing live do not pause anyone' );

		$tries = 0;
		while ( $tries < 50 ) {
			self::mail( 12, '777111', self::WORK );
			for ( $i = 0; $i < 5 && $tries < 50; $i++, $tries++ ) {
				$this->assertFalse( ZDZ_Signin_Routes::codes_paused( 12 ) );
				self::who( self::WORK, '777000' );
			}
		}
		$this->assertTrue( ZDZ_Signin_Routes::codes_paused( 12 ), 'the fiftieth wrong try against a live code pauses emailed codes' );
		$this->assertFalse( ZDZ_Signin_Routes::may_send( 12, self::WORK ), 'none to the work address' );
		$this->assertFalse( ZDZ_Signin_Routes::may_send( 12, self::BACKUP ), 'none to the backup address' );
		$this->assertCount( 1, self::told( 1, 'Emailed sign-in codes paused' ), 'the manager is told once' );
		$this->assertCount( 1, self::events( 12, 'codes_paused' ) );

		$c = self::give( 12 );
		$this->assertSame( 12, self::who( self::WORK, $c ), 'a code a person gives still signs them in' );
		ZDZ_Signin_Routes::put_code( 12, '777222', 'bridge', 300 );
		$this->assertSame( 12, self::who( self::WORK, '777222' ), 'so does a "bridge" code' );
		$this->assertTrue( ZDZ_Signin_Routes::may_send( 11, 'alex@example.com' ), 'another account is not affected' );

		self::mail( 12, '777333', self::WORK );
		for ( $i = 0; $i < 5; $i++ ) {
			self::who( self::WORK, '777000' );
		}
		$this->assertCount( 1, self::told( 1, 'Emailed sign-in codes paused' ), 'more wrong tries the same day add no second notice' );

		ZDZ_Signin_Routes::$now += 86400;
		$this->assertFalse( ZDZ_Signin_Routes::codes_paused( 12 ) );
		$this->assertTrue( ZDZ_Signin_Routes::may_send( 12, self::WORK ), 'a day later codes are emailed again' );
	}

	public function test_a_site_may_tune_the_days_wrong_tries_within_bounds(): void {
		$GLOBALS['zdz_test_filters']['zdz_signin_day_misses'] = function () {
			return 3;
		};
		$this->assertSame( 10, ZDZ_Signin_Routes::day_misses(), 'never below ten' );
		$GLOBALS['zdz_test_filters']['zdz_signin_day_misses'] = function () {
			return 100000;
		};
		$this->assertSame( 200, ZDZ_Signin_Routes::day_misses(), 'never above two hundred' );
		$GLOBALS['zdz_test_filters']['zdz_signin_day_misses'] = function () {
			return 20;
		};
		$this->assertSame( 20, ZDZ_Signin_Routes::day_misses() );
	}

	public function test_six_digit_and_eight_digit_tries_are_counted_apart(): void {
		$c = self::give( 12 );
		$this->assertMatchesRegularExpression( '/^[1-9][0-9]{7}$/', (string) $c );
		for ( $i = 0; $i < 50; $i++ ) {
			self::who( self::WORK, '111111' );
		}
		$this->assertSame( 12, self::who( self::WORK, $c ), 'wrong six-digit tries never touch a given code' );

		self::mail( 12, '222333', self::WORK );
		$c = self::give( 12 );
		for ( $i = 0; $i < 300; $i++ ) {
			self::who( self::WORK, zdz_test_signin_wrong( $c ) );
		}
		$this->assertSame( 12, self::who( self::WORK, '222333' ), 'and wrong eight-digit tries never touch an emailed code' );
	}

	public function test_a_given_code_survives_299_wrong_tries_and_the_300th_cancels_it(): void {
		$c   = self::give( 12 );
		$bad = zdz_test_signin_wrong( $c );
		for ( $i = 0; $i < 299; $i++ ) {
			self::who( self::WORK, $bad );
		}
		$this->assertGreaterThan( 0, ZDZ_Signin_Routes::issued_left( 12 ), 'a stranger with a handful of tries cancels nothing' );
		self::who( self::WORK, $bad );
		$this->assertSame( 0, ZDZ_Signin_Routes::issued_left( 12 ) );
		$this->assertSame( 0, self::who( self::WORK, $c ), 'the 300th kills it' );
		$this->assertCount( 1, self::told( 1, 'cancelled' ) );

		$c = self::give( 12 );
		for ( $i = 0; $i < 300; $i++ ) {
			self::who( self::WORK, zdz_test_signin_wrong( $c ) );
		}
		$this->assertCount( 1, self::told( 1, 'cancelled' ), 'the manager is told once in 15 minutes, however many are cancelled' );
		$c = self::give( 12 );
		$this->assertSame( 12, self::who( self::WORK, $c ), 'there is no lock: the next code given works' );
	}

	/* ── what an account holds ─────────────────────────────────────────────── */

	public function test_six_live_codes_are_kept_per_address_and_three_bridge_codes(): void {
		foreach ( array( '111111', '111112', '111113', '111114', '111115', '111116', '111117' ) as $x ) {
			self::mail( 12, $x, self::WORK );
		}
		$this->assertCount( 6, self::codes( 12 ), 'six is the send cap, so no ask inside it pushes a live code out' );
		$this->assertSame( 0, self::who( self::WORK, '111111' ), 'a seventh pushes out the oldest' );
		$this->assertSame( 12, self::who( self::WORK, '111112' ) );

		delete_user_meta( 12, 'zdz_signin_codes' );
		self::mail( 12, '333111', self::BACKUP );
		foreach ( array( '333112', '333113', '333114', '333115', '333116', '333117', '333118' ) as $x ) {
			self::mail( 12, $x, self::WORK );
		}
		foreach ( array( '444111', '444112', '444113', '444114' ) as $x ) {
			ZDZ_Signin_Routes::put_code( 12, $x, 'bridge', 300 );
		}
		$kinds = array_count_values( array_column( self::codes( 12 ), 'kind' ) );
		$this->assertSame( 3, $kinds['bridge'], 'the newest three' );
		$this->assertSame( 7, $kinds['mail'], 'six for the work address and the one for the backup address' );
		$this->assertSame( 12, self::who( self::BACKUP, '333111' ), 'asks made with one address never push out a code asked for with another' );
	}

	public function test_a_code_is_claimed_only_with_the_address_it_was_asked_with(): void {
		self::mail( 12, '333222', self::BACKUP );
		$this->assertSame( 0, self::who( self::WORK, '333222' ), 'not with another address of the same account' );
		$this->assertSame( 12, self::who( 'JORDAN.Home@example.net', '333222' ) );

		ZDZ_Signin_Routes::put_code( 12, '444555', 'bridge', 300 );
		$this->assertSame( 12, self::who( self::BACKUP, '444555' ), 'a "bridge" code works with any address of the account' );
		$this->assertSame( 0, self::who( self::BACKUP, '444555' ), 'once' );

		$given = self::give( 12 );
		self::mail( 12, '222222', self::WORK );
		$this->assertSame( 12, self::who( self::WORK, $given ), 'asking for an emailed code does not cancel a code a person gave' );
	}

	public function test_six_sends_to_one_address_in_fifteen_minutes(): void {
		$sent = 0;
		foreach ( range( 1, 9 ) as $i ) {
			$sent += (int) ZDZ_Signin_Routes::may_send( 12, $i % 2 ? 'JORDAN@example.com' : ' jordan@EXAMPLE.com ' );
		}
		$this->assertSame( 6, $sent, 'however the address is spelled' );
		$this->assertTrue( ZDZ_Signin_Routes::may_send( 12, self::BACKUP ), 'the backup address has its own count' );
		$this->assertCount( 1, self::events( 12, 'send_refused' ), 'a refused send is one line on the page' );
		$sends = get_user_meta( 12, 'zdz_signin_sends', true );
		$this->assertSame( array( md5( self::WORK ), md5( self::BACKUP ) ), array_keys( $sends ), 'counted under a digest of the address, not the address' );
		ZDZ_Signin_Routes::$now += 901;
		$this->assertTrue( ZDZ_Signin_Routes::may_send( 12, self::WORK ), 'the count ends with the fifteen minutes' );
	}

	/* ── one writer at a time ──────────────────────────────────────────────── */

	public function test_while_another_request_holds_the_account_nothing_is_kept_given_or_counted(): void {
		$db = $GLOBALS['wpdb'];
		$this->assertTrue( self::mail( 12, '222666', self::WORK ) );
		$db->held = array( zdz_test_signin_lock( 12 ) );
		$this->assertSame( 0, self::who( self::WORK, '222666' ), 'a claim is refused' );
		$this->assertFalse( self::mail( 12, '222777', self::WORK ), 'a code is not kept' );
		$this->assertTrue( is_wp_error( self::give( 12, true ) ), 'a code is not given' );
		$this->assertFalse( ZDZ_Signin_Routes::may_send( 12, self::WORK ), 'a send is not counted' );
		$db->held = array();
		$this->assertSame( 12, self::who( self::WORK, '222666' ), 'once it is free the code that was kept works' );

		$given = self::give( 12 );
		$db->held = array( zdz_test_signin_lock( 12 ) );
		$this->assertFalse( ZDZ_Signin_Routes::cancel_issued( 1, 12 ), 'taking a code back waits for the same lock' );
		$this->assertGreaterThan( 0, ZDZ_Signin_Routes::issued_left( 12 ) );
		$db->held = array();
		$this->assertTrue( ZDZ_Signin_Routes::cancel_issued( 1, 12 ) );
		$this->assertSame( 0, self::who( self::WORK, (string) $given ) );
	}

	public function test_every_lock_is_given_back_and_named_for_the_install_and_the_account(): void {
		$db = $GLOBALS['wpdb'];
		self::mail( 12, '222666', self::WORK );
		self::who( self::WORK, '000000' );
		self::who( 'alex@example.com', '222666' );
		ZDZ_Signin_Routes::may_send( 11, 'alex@example.com' );
		$db->held = array( zdz_test_signin_lock( 12 ) );
		self::who( self::WORK, '222666' );
		$db->held = array();
		$this->assertSame( array(), $db->open_locks(), 'also after a refusal and a wrong try' );
		$this->assertGreaterThan( 3, count( $db->taken ) );
		$this->assertSame( count( $db->taken ), count( $db->released ) );
		$this->assertContains( zdz_test_signin_lock( 12 ), $db->taken );
		$this->assertContains( zdz_test_signin_lock( 11 ), $db->taken );
		$this->assertLessThanOrEqual( 64, strlen( zdz_test_signin_lock( 999999999 ) ), "it fits MySQL's 64 characters" );
		$this->assertSame( array(), array_values( array_filter( $db->taken, function ( $n ) { return false !== strpos( $n, 'wp_2_' ); } ) ), "the install's base prefix, not one site's" );
		$this->assertSame( array( 1 ), array_values( array_unique( $db->waits ) ), 'a request waits one second for it, no longer' );
		if ( zdz_test_signin_has( 'wp_cache_delete' ) ) {
			$this->assertContains( 'user_meta:12', $GLOBALS['zdz_test_signin_cache_drops'], "the account's meta is read afresh once the lock is held" );
		}
	}

	public function test_a_fault_while_the_lock_is_held_still_gives_it_back(): void {
		$m = new ReflectionMethod( 'ZDZ_Signin_Routes', 'locked' );
		$m->setAccessible( true );
		$threw = false;
		try {
			$m->invoke( null, 12, function () { throw new RuntimeException( 'a fault' ); } );
		} catch ( RuntimeException $e ) {
			$threw = true;
		}
		$this->assertTrue( $threw );
		$this->assertSame( array(), $GLOBALS['wpdb']->open_locks() );
		$this->assertSame( 'refused', $this->with_no_lock( $m ), 'when the lock cannot be had the caller gets its "otherwise"' );
	}

	private function with_no_lock( ReflectionMethod $m ) {
		$GLOBALS['wpdb']->allow = 0;
		$r = $m->invoke( null, 12, function () { return 'ran'; }, 'refused' );
		$GLOBALS['wpdb']->allow = -1;
		return $r;
	}

	/* ── a database with no named locks ────────────────────────────────────── */

	public function test_a_database_with_no_named_locks_keeps_and_claims_codes_one_request_at_a_time(): void {
		$db              = $GLOBALS['wpdb'];
		$db->no_get_lock = true;   // SQLite, for one: GET_LOCK answers nothing
		$this->assertTrue( self::mail( 12, '135790', self::WORK ), 'a code is kept' );
		$this->assertSame( 0, self::who( self::WORK, '135791' ), 'a wrong try is counted' );
		$this->assertSame( 4, self::codes( 12 )[0]['left'] );
		$this->assertSame( 12, self::who( self::WORK, '135790' ), 'and the code signs in' );
		$this->assertSame( 0, self::who( self::WORK, '135790' ), 'once' );
		$this->assertTrue( ZDZ_Signin_Routes::may_send( 12, self::WORK ), 'a send is counted' );
		$this->assertSame( 5, $db->rows_taken, 'each through the account\'s options row' );
		$this->assertSame( array(), $db->open_locks(), 'and each row was removed again' );
		if ( zdz_test_signin_has( 'wp_cache_delete' ) ) {
			$this->assertContains( 'user_meta:12', $GLOBALS['zdz_test_signin_cache_drops'], "the account's meta is read afresh once the row is held" );
		}

		// Another request holds the row: it is waited for about a second, then nothing is kept, and its row is left alone.
		$db->rows['zdz_signin_lock_12'] = (string) ZDZ_Signin_Routes::$now;
		$t = microtime( true );
		$this->assertFalse( self::mail( 12, '135792', self::WORK ) );
		$waited = microtime( true ) - $t;
		$this->assertGreaterThanOrEqual( 0.9, $waited, 'about a second' );
		$this->assertLessThan( 5.0, $waited );
		$this->assertSame( array(), self::codes( 12 ) );
		$this->assertSame( array( 'zdz_signin_lock_12' => (string) ZDZ_Signin_Routes::$now ), $db->rows );
		$this->assertSame( 0, self::who( self::WORK, '135792' ) );

		// A row left by a request that died is removed after ten seconds, and the lock is taken.
		$db->rows['zdz_signin_lock_12'] = (string) ( ZDZ_Signin_Routes::$now - 11 );
		$this->assertTrue( self::mail( 12, '135793', self::WORK ) );
		$this->assertSame( array(), $db->rows );
		$this->assertSame( 12, self::who( self::WORK, '135793' ) );
		$db->rows['zdz_signin_lock_12'] = (string) ( ZDZ_Signin_Routes::$now - 10 );
		$this->assertFalse( self::mail( 12, '135794', self::WORK ), 'ten seconds is not yet stale' );
		unset( $db->rows['zdz_signin_lock_12'] );

		// A lock another request holds on a database WITH named locks is never taken through the row.
		$db->no_get_lock = false;
		$db->held        = array( zdz_test_signin_lock( 12 ) );
		$taken           = $db->rows_taken;
		$this->assertFalse( self::mail( 12, '135795', self::WORK ) );
		$this->assertSame( $taken, $db->rows_taken );
		$db->held = array();
		$this->assertSame( array(), $db->open_locks() );

		// The rows are this install's state: a Company Data export never carries one, and an import never writes one.
		$GLOBALS['zdz_test_filters']['zdz_export_non_portable_option_patterns'] = array( 'ZDZ_Signin_Routes', 'non_portable_options' );
		$this->assertTrue( ZDZ_Data_Portability::is_non_portable_option( 'zdz_signin_lock_12' ) );
		foreach ( array( 'zdz_signin_log', 'zdz_signin_managers', 'zdz_signin_lock_', 'zdz_signin_lock_12x' ) as $kept ) {
			$this->assertFalse( ZDZ_Data_Portability::is_non_portable_option( $kept ), $kept );
		}
	}

	/* ── after a sign-in ───────────────────────────────────────────────────── */

	public function test_a_backup_address_counts_as_tested_only_when_the_code_was_mailed_to_it(): void {
		$r    = 'ZDZ_Signin_Routes';
		$user = get_user_by( 'id', 12 );
		$this->assertSame( 'untested', $r::cover( 12 ) );

		// a login plugin's own form: the backup address typed, the email to the account address
		self::mail( 12, '975310', self::BACKUP, array( 'sent' => self::WORK ) );
		$r::after_sign_in( $user, $r::claim( self::BACKUP, '975310' )['rec'] );
		$this->assertSame( 'untested', $r::cover( 12 ), 'its mailbox received nothing' );
		$this->assertSame( 0, $r::tested_at( 12, self::BACKUP ) );
		$this->assertSame( 'mail', get_user_meta( 12, 'zdz_signin_last', true )['route'] );
		$this->assertSame( array(), $GLOBALS['wpdb']->notices(), 'and nobody is told' );

		self::mail( 12, '135790', self::WORK );
		$r::after_sign_in( $user, $r::claim( self::WORK, '135790' )['rec'] );
		$this->assertSame( array(), $GLOBALS['wpdb']->notices(), 'an ordinary emailed code tells nobody' );
		$this->assertSame( 'mail', get_user_meta( 12, 'zdz_signin_last', true )['route'] );

		self::mail( 12, '246810', 'JORDAN.Home@example.net' );
		$r::after_sign_in( $user, $r::claim( self::BACKUP, '246810' )['rec'] );
		$this->assertSame( 'tested', $r::cover( 12 ) );
		$this->assertSame( ZDZ_Signin_Routes::$now, $r::tested_at( 12, self::BACKUP ) );
		$this->assertSame( 'backup', get_user_meta( 12, 'zdz_signin_last', true )['route'] );
		$this->assertCount( 1, self::told( 1, 'A backup address was used' ), 'the manager is told' );
		$this->assertCount( 1, self::events( 12, 'backup_used' ) );
		$this->assertStringNotContainsString( '@', serialize( $GLOBALS['wpdb']->notices() ), 'no notice names an address' );
	}

	public function test_a_person_who_signs_in_with_a_given_code_is_told_who_gave_it(): void {
		$r = 'ZDZ_Signin_Routes';
		$c = self::give( 11 );
		$this->assertCount( 0, self::told( 1, 'A sign-in code was given' ), 'the manager is not sent a notice about their own code' );
		$r::after_sign_in( get_user_by( 'id', 11 ), $r::claim( 'alex@example.com', $c )['rec'] );
		$to_alex = self::told( 11, 'You signed in with a code' );
		$this->assertCount( 1, $to_alex );
		$this->assertStringContainsString( 'Morgan Example', $to_alex[0]['message'] );
		$this->assertCount( 0, self::told( 1, 'A sign-in code was used' ) );
		$last = get_user_meta( 11, 'zdz_signin_last', true );
		$this->assertSame( 'issued', $last['route'] );
		$this->assertSame( 1, $last['by'] );
		$this->assertSame( 'signin_route', $to_alex[0]['type'] );
	}

	/* ── the logs ──────────────────────────────────────────────────────────── */

	public function test_what_a_stranger_can_cause_is_one_line_per_account_per_window_in_its_own_list(): void {
		$r = 'ZDZ_Signin_Routes';
		$r::log( 'manager_set', 1, 1 );
		for ( $i = 0; $i < 120; $i++ ) {
			$r::mail_failed( 12 );
			self::mail( 12, '555111', self::WORK );
			for ( $j = 0; $j < 5; $j++ ) {
				self::who( self::WORK, '555000' );
			}
		}
		$this->assertCount( 1, self::events( 12, 'mail_failed' ) );
		$this->assertCount( 1, self::events( 12, 'code_killed' ) );
		$this->assertLessThanOrEqual( $r::EVENTS_MAX, count( (array) get_option( 'zdz_signin_events', array() ) ) );
		$this->assertSame( 'manager_set', get_option( 'zdz_signin_log' )[0]['what'], 'and pushes out nothing people did on the page' );

		for ( $i = 0; $i < 320; $i++ ) {
			$r::log( 'issuer_removed', 0, 1 );
		}
		$events = array();
		for ( $i = 0; $i < 130; $i++ ) {
			$events[] = array( 't' => 1, 'what' => 'backup_used', 'u' => 11, 'by' => 0, 'n' => '' );
		}
		update_option( 'zdz_signin_events', $events );
		$r::mail_failed( 2 );
		$this->assertCount( 300, get_option( 'zdz_signin_log' ) );
		$this->assertCount( 100, get_option( 'zdz_signin_events' ) );
		$tail = get_option( 'zdz_signin_events' );
		$this->assertSame( 'mail_failed', end( $tail )['what'], 'both lists are trimmed to their size, the newest kept' );
	}

	public function test_no_code_and_no_address_is_in_a_log_a_notice_an_audit_row_or_any_stored_value(): void {
		$r     = 'ZDZ_Signin_Routes';
		$given = array();
		zdz_test_signin_as( 1 );
		$r::set_issuer( 1, 11 );
		foreach ( array( 12, 13, 14 ) as $target ) {
			$given[] = self::give( $target );
			$given[] = self::give( $target, true );
		}
		$r::after_sign_in( get_user_by( 'id', 13 ), $r::claim( 'riley@example.com', $given[3] )['rec'] );   // Riley uses the newer of the two
		$r::cancel_issued( 1, 12 );
		zdz_test_signin_as( 11 );
		$given[] = $r::issue( 11, 12 );
		for ( $i = 0; $i < 300; $i++ ) {
			self::who( self::WORK, zdz_test_signin_wrong( end( $given ) ) );
		}
		zdz_test_signin_as( 1 );
		$r::save_addresses( 1, 13, 'riley.home@example.net' );
		error_log( 'sign-in suite: the log is being written' );

		self::mail( 14, '555111', 'casey@example.com' );
		for ( $i = 0; $i < 5; $i++ ) {
			self::who( 'casey@example.com', '555000' );   // a line of the login screen's own list
		}
		$logs = array_merge( (array) get_option( 'zdz_signin_log', array() ), (array) get_option( 'zdz_signin_events', array() ) );
		$this->assertGreaterThan( 8, count( $logs ) );
		$this->assertContains( 'code_killed', array_column( $logs, 'what' ) );
		$this->assertContains( 'issued_killed', array_column( $logs, 'what' ) );
		$all = serialize( $logs ) . serialize( $GLOBALS['wpdb']->written ) . serialize( $GLOBALS['zdz_test_user_meta'] )
			. serialize( $GLOBALS['zdz_test_options'] ) . serialize( $GLOBALS['zdz_test_transients'] ) . (string) file_get_contents( $this->log );
		$this->assertStringContainsString( 'the log is being written', $all, 'the check reads what was logged' );
		$this->assertCount( 7, array_filter( $given, 'is_string' ) );
		foreach ( $given as $c ) {
			foreach ( array( $c, substr( $c, 0, 4 ) . ' ' . substr( $c, 4 ), substr( $c, 0, 4 ) . '-' . substr( $c, 4 ), strrev( $c ) ) as $shape ) {
				$this->assertStringNotContainsString( $shape, $all );
			}
		}
		$this->assertStringNotContainsString( '@', serialize( $logs ), 'no line names an address' );
		$this->assertStringNotContainsString( '@', serialize( $GLOBALS['wpdb']->written ), 'nor a notice or an audit row' );
		foreach ( $logs as $e ) {
			$this->assertSame( array( 't', 'what', 'u', 'by', 'n' ), array_keys( $e ) );
			$this->assertDoesNotMatchRegularExpression( '/\d{4}/', $e['what'] . '|' . $e['n'], 'no run of digits beside its time and its account ids' );
			$this->assertLessThan( 100, $e['u'] );
			$this->assertLessThan( 100, $e['by'] );
		}
	}

	/* ── nothing is keyed by the WordPress salts ───────────────────────────── */

	public function test_a_code_kept_before_a_change_of_salts_still_signs_in(): void {
		// A record written by hand, as an earlier request kept it: the hash is the code under the record's own salt and
		// nothing else. Whatever the site's secret keys are now, it is weighed the same way.
		$salt = 'a1b2c3d4e5f60718';
		$GLOBALS['zdz_test_user_meta'][12]['zdz_signin_codes'] = array(
			array( 's' => $salt, 'h' => hash( 'sha256', $salt . '|12|864209' ), 'exp' => ZDZ_Signin_Routes::$now + 120, 'kind' => 'mail', 'left' => 5, 'to' => self::BACKUP, 'sent' => self::BACKUP ),
			array( 's' => $salt, 'h' => hash( 'sha256', $salt . '|12|86420975' ), 'exp' => ZDZ_Signin_Routes::$now + 120, 'kind' => 'issued', 'left' => 300, 'to' => '', 'by' => 1 ),
		);
		$this->assertSame( 12, self::who( self::BACKUP, '864209' ), 'a code emailed to a backup address' );
		$this->assertSame( 12, self::who( self::WORK, '86420975' ), 'a code the manager gave' );
		$this->assertSame( array( 1 ), ZDZ_Signin_Routes::managers(), 'the manager is still named' );
		$this->assertSame( array( self::BACKUP ), ZDZ_Signin_Routes::addresses( 12 ), 'the backup address is still listed' );

		$src  = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-signin-routes.php' );
		$code = (string) preg_replace( '~/\*.*?\*/|//[^\n]*~s', '', $src );
		$this->assertDoesNotMatchRegularExpression( '/wp_salt\s*\(|wp_hash\s*\(|AUTH_KEY|AUTH_SALT|LOGGED_IN_|NONCE_KEY|NONCE_SALT|ZDZ_Core_Crypto|ZDZ_Sealed|ZDZ_Keys/', $code, 'no salt, no key constant and nothing sealed under one' );
	}

	/* ── what a site may set ───────────────────────────────────────────────── */

	public function test_how_long_a_given_code_lasts_is_ten_minutes_unless_a_site_changes_it_within_bounds(): void {
		$r = 'ZDZ_Signin_Routes';
		$this->assertSame( 600, $r::issued_ttl() );
		$c = self::give( 11 );
		ZDZ_Signin_Routes::$now += 599;
		$this->assertGreaterThan( 0, $r::issued_left( 11 ), 'time left at 9 min 59 s' );
		ZDZ_Signin_Routes::$now += 2;
		$this->assertSame( 0, $r::issued_left( 11 ) );
		$this->assertSame( 0, self::who( 'alex@example.com', $c ), 'at 10 min 1 s it is dead' );

		foreach ( array( 5 => 120, 0 => 120, -30 => 120, 900 => 900, 86400 => 3600 ) as $asked => $got ) {
			$GLOBALS['zdz_test_filters']['zdz_signin_issued_ttl'] = function () use ( $asked ) { return $asked; };
			$this->assertSame( $got, $r::issued_ttl(), 'asked for ' . $asked );
		}
		$GLOBALS['zdz_test_filters']['zdz_signin_issued_ttl'] = function () { return 900; };
		self::give( 12 );
		$this->assertSame( 900, $r::issued_left( 12 ) );
	}

	public function test_a_backup_address_at_the_work_mailboxes_mail_service_is_named_only_when_a_site_lists_its_domains(): void {
		$r = 'ZDZ_Signin_Routes';
		$this->assertSame( array(), $r::shared_outage_domains(), 'none by default: whose service hosts the mail is the business\'s own' );
		$this->assertSame( 'same', $r::address_flag( 'alex@example.com', 'alex.second@EXAMPLE.com' ), "the account address's own domain" );
		$this->assertSame( '', $r::address_flag( 'alex@example.com', 'alex@example.org' ) );
		$this->assertSame( '', $r::address_flag( 'alex@example.com', 'not-an-address' ) );

		update_option( 'zdz_signin_shared_outage_domains', array( 'Example.ORG', ' mail.example.net. ', 'no dot', 'bad_domain.example', 7 ) );
		$this->assertSame( array( 'example.org', 'mail.example.net' ), $r::shared_outage_domains(), 'whole mail domains only, lower case' );
		$this->assertSame( 'shared', $r::address_flag( 'alex@example.com', 'alex@EXAMPLE.org' ) );
		$this->assertSame( 'same', $r::address_flag( 'alex@example.org', 'alex.second@example.org' ), 'its own domain comes first' );
		$this->assertSame( '', $r::address_flag( 'alex@example.com', 'alex@example.net' ) );

		update_option( 'zdz_signin_shared_outage_domains', 'example.org, example.net' );
		$this->assertSame( array( 'example.org', 'example.net' ), $r::shared_outage_domains(), 'one comma-separated line reads the same' );
		$GLOBALS['zdz_test_filters']['zdz_signin_shared_outage_domains'] = function ( $d ) { return array_merge( $d, array( 'example.edu' ) ); };
		$this->assertContains( 'example.edu', $r::shared_outage_domains() );
		$GLOBALS['zdz_test_filters']['zdz_signin_shared_outage_domains'] = function () { return 'not a list'; };
		$this->assertSame( array(), $r::shared_outage_domains() );

		unset( $GLOBALS['zdz_test_filters']['zdz_signin_shared_outage_domains'] );
		update_user_meta( 11, 'zdz_login_aliases', array( 'alex@example.org' ) );
		$this->assertSame( 'untested', $r::cover( 11 ), 'such an address still counts as cover: it is said beside it, not refused' );
		update_user_meta( 11, 'zdz_login_aliases', array( 'alex.second@example.com' ) );
		$this->assertSame( 'none', $r::cover( 11 ), "an address on the account address's own mail domain counts for nothing" );
		$this->assertSame( 'none', $r::cover( 2 ), 'no address' );
	}

	public function test_a_live_code_and_the_one_press_token_never_travel_in_a_company_data_export(): void {
		$markers = ZDZ_Signin_Routes::export_secret_markers( array( 'api_key' ) );
		$this->assertSame( array( 'api_key', 'zdz_signin_codes', 'zdz_signin_form' ), $markers );
		$this->assertSame( array( 'zdz_signin_codes', 'zdz_signin_form' ), ZDZ_Signin_Routes::export_secret_markers( 'not a list' ) );

		$GLOBALS['zdz_test_filters']['zdz_export_secret_markers'] = array( 'ZDZ_Signin_Routes', 'export_secret_markers' );
		$is_secret = new ReflectionMethod( 'ZDZ_Data_Portability', 'is_secret' );
		$is_secret->setAccessible( true );
		$this->assertTrue( $is_secret->invoke( null, 'zdz_signin_codes' ) );
		$this->assertTrue( $is_secret->invoke( null, 'zdz_signin_form' ) );
		foreach ( array( 'zdz_login_aliases', 'zdz_signin_seen', 'zdz_signin_last', 'zdz_signin_managers', 'zdz_signin_issuer', 'zdz_signin_log' ) as $kept ) {
			$this->assertFalse( $is_secret->invoke( null, $kept ), $kept . ' is the business\'s own record and travels' );
		}
	}
}
