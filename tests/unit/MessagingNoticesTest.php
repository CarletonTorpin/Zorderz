<?php
/**
 * Messaging: another app's notice, quiet hours, the shared device, and push setup.
 *
 *   - zim_queue_notice (ZIM_Notices): a notice for someone with Messages is queued as one 'notice' row with no
 *     conversation; the shared device, a person without Messages and an account in Privacy View are refused; a link
 *     off this site is refused; the payload is four short strings; one notice is pushed as given, several as a digest.
 *   - Quiet hours: the person's own window, else the business's, else Core's (ZDZ_Quiet_Hours); "the same time twice"
 *     holds nothing; a malformed personal setting never stands in for a window.
 *   - messaging.push_setup: answers the person with Messages in a shape Settings -> Notifications accepts
 *     (ZDZ_Push_Setup::clean), and refuses the shared device.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/messaging-test-doubles.php';

final class MessagingNoticesTest extends TestCase {

	protected function setUp(): void {
		zdz_test_msg_reset();
		zdz_test_msg_people( array( 5 => 'staff', 6 => 'shared', 7 => 'none', 8 => 'admin', 9 => 'staff' ) );
	}

	protected function tearDown(): void {
		zdz_test_msg_teardown();
	}

	private static function at_time( string $hm, string $day = '2026-03-10' ): DateTimeImmutable {
		return new DateTimeImmutable( $day . ' ' . $hm . ':00', new DateTimeZone( 'UTC' ) );
	}

	/* ── zim_queue_notice ─────────────────────────────────────────────── */

	public function test_a_notice_is_queued_once_with_no_conversation(): void {
		$GLOBALS['zdz_test_user_meta'][5]['zim_quiet_hours_'] = array( 'start' => '00:00', 'end' => '00:00' );   // never held
		$id = ZIM_Notices::f_queue( null, 5, array( 'title' => 'Estimate accepted', 'body' => 'Tap to open.', 'url' => '/jobs/', 'tag' => 'zdz-alert-x' ) );
		$this->assertSame( 1, $id, 'the row id comes back' );
		$this->assertCount( 1, $GLOBALS['wpdb']->inserted );
		list( $table, $row ) = $GLOBALS['wpdb']->inserted[0];
		$this->assertSame( 'wp_zim_notification_queue', $table );
		$this->assertSame( 'notice', $row['kind'] );
		$this->assertSame( 0, $row['conversation_id'] );
		$p = json_decode( $row['payload_json'], true );
		$this->assertSame( array( 'title', 'body', 'url', 'tag' ), array_keys( $p ), 'four strings, nothing else' );
		$this->assertSame( 'https://app.example.com/jobs/', $p['url'], 'a path is rebuilt on this site' );
	}

	public function test_the_shared_device_never_gets_a_notice(): void {
		$r = ZIM_Notices::f_queue( null, 6, array( 'title' => 'Estimate accepted', 'url' => '/' ) );
		$this->assertInstanceOf( 'WP_Error', $r );
		$this->assertSame( 'zim_notice_refused', $r->get_error_code() );
		$this->assertSame( array(), $GLOBALS['wpdb']->inserted, 'nothing queued' );
		$this->assertTrue( ZIM_Notices::refused( 6 ) );
		$this->assertTrue( ZIM_Push::refused( 6 ), 'and no push of any kind' );
		$this->assertSame( array( 'sent' => 0, 'pruned' => 0 ), ZIM_Push::send_to_user( 6, array( 'title' => 'x' ) ) );
	}

	public function test_no_messages_no_privacy_view_no_stranger(): void {
		$this->assertTrue( ZIM_Notices::refused( 7 ), 'a role without Messages' );
		$this->assertTrue( ZIM_Notices::refused( 99 ), 'nobody' );
		$this->assertTrue( ZIM_Notices::refused( 0 ) );
		$GLOBALS['zdz_test_user_meta'][9]['zdz_privacy_view'] = '1';
		$this->assertTrue( ZIM_Notices::refused( 9 ), 'an account in Privacy View' );
		$this->assertSame( 0, ZIM_Notifications::queue( 9, 3, 4, 'mention', array() ), 'nor any message notification' );
		$this->assertSame( array(), $GLOBALS['wpdb']->inserted );
		$this->assertFalse( ZIM_Notices::refused( 5 ) );
	}

	public function test_an_answer_from_an_earlier_callback_passes_through(): void {
		$this->assertSame( 42, ZIM_Notices::f_queue( 42, 6, array() ) );
	}

	public function test_a_notice_needs_a_title_and_a_link_on_this_site(): void {
		$this->assertSame( 'zim_notice_empty', ZIM_Notices::f_queue( null, 5, array( 'title' => '  ', 'url' => '/' ) )->get_error_code() );
		$this->assertSame( 'zim_notice_url', ZIM_Notices::f_queue( null, 5, array( 'title' => 'x', 'url' => 'https://other.example.net/' ) )->get_error_code() );
		$this->assertSame( array(), $GLOBALS['wpdb']->inserted );
	}

	public function test_links_are_this_site_only(): void {
		$this->assertSame( 'https://app.example.com/', ZIM_Notices::clean_url( '' ), 'no link: the app' );
		$this->assertSame( 'https://app.example.com/a?b=1#c', ZIM_Notices::clean_url( '/a?b=1#c' ) );
		$this->assertSame( 'https://app.example.com/x', ZIM_Notices::clean_url( 'https://APP.example.com/x' ) );
		foreach ( array( '//evil.example.net/x', 'https://evil.example.net/', 'http://app.example.com/x', 'https://app.example.com:8443/x',
			'https://u:p@app.example.com/', "/a\\b", "/a b", "javascript:alert(1)", 'https://evil.example\\@app.example.com/' ) as $bad ) {
			$this->assertSame( '', ZIM_Notices::clean_url( $bad ), $bad );
		}
	}

	public function test_payload_is_short_plain_text_and_never_takes_a_conversation_tag(): void {
		$p = ZIM_Notices::clean_payload( array( 'title' => '<b>Hi</b> ' . str_repeat( 'a', 300 ), 'body' => "line\none", 'tag' => 'zim-12', 'extra' => 'dropped' ) );
		$this->assertSame( 120, mb_strlen( $p['title'] ) );
		$this->assertStringNotContainsString( '<b>', $p['title'] );
		$this->assertSame( 'line one', $p['body'] );
		$this->assertSame( 'x-zim-12', $p['tag'], 'zim-<n> is a conversation\'s own tag' );
		$this->assertArrayNotHasKey( 'extra', $p );
		$this->assertSame( 'zim-notice', ZIM_Notices::clean_payload( array() )['tag'] );
		$big = ZIM_Notices::clean_payload( array( 'title' => str_repeat( '😀', 120 ), 'body' => str_repeat( '😀', 200 ) ) );
		$this->assertLessThanOrEqual( ZIM_Notices::MAX_BYTES, strlen( json_encode( $big ) ), 'characters are not bytes' );
	}

	public function test_one_notice_is_pushed_as_given_several_as_one_digest(): void {
		$sent = array();
		ZIM_Notices::$sender = function ( $uid, $push ) use ( &$sent ) { $sent[] = array( $uid, $push ); return array( 'sent' => 1 ); };
		$row = function ( $p ) { return array( 'user_id' => 5, 'kind' => 'notice', 'payload_json' => json_encode( $p ) ); };
		ZIM_Notices::fire( array( $row( array( 'title' => 'Estimate accepted', 'body' => 'Tap to open.', 'url' => 'https://app.example.com/jobs/', 'tag' => 't' ) ) ) );
		$this->assertSame( 'Estimate accepted', $sent[0][1]['title'] );
		$this->assertSame( 'https://app.example.com/jobs/', $sent[0][1]['url'] );
		ZIM_Notices::fire( array( $row( array( 'title' => 'a', 'url' => 'https://app.example.com/' ) ), $row( array( 'title' => 'b', 'url' => 'https://app.example.com/' ) ), $row( array( 'title' => 'c', 'url' => 'https://app.example.com/' ) ) ) );
		$this->assertSame( '3 new notices', $sent[1][1]['title'] );
		$this->assertSame( 'zim-notices', $sent[1][1]['tag'] );
		$this->assertNull( ZIM_Notices::fire( array( $row( array( 'title' => 'x', 'url' => 'https://evil.example.net/' ) ) ) ), 'never off this site' );
		$this->assertNull( ZIM_Notices::fire( array( array( 'user_id' => 6, 'kind' => 'notice', 'payload_json' => json_encode( array( 'title' => 'x', 'url' => 'https://app.example.com/' ) ) ) ) ), 'never the shared device, even from the queue' );
		$this->assertCount( 2, $sent );
	}

	/* ── quiet hours ──────────────────────────────────────────────────── */

	public function test_core_quiet_hours_hold_a_night_notice_until_morning(): void {
		$this->assertSame( '2026-03-11 07:00:00', ZIM_Notifications::compute_release_at( 5, self::at_time( '22:00' ) ), 'queued at 22:00, out at 07:00' );
		$this->assertSame( '2026-03-10 07:00:00', ZIM_Notifications::compute_release_at( 5, self::at_time( '03:00' ) ) );
		$this->assertSame( '2026-03-10 12:00:00', ZIM_Notifications::compute_release_at( 5, self::at_time( '12:00' ) ), 'daytime: now' );
	}

	public function test_the_business_window_then_the_persons_own(): void {
		$GLOBALS['zdz_test_options']['zdz_quiet_hours'] = array( 'start' => '20:00', 'end' => '06:00' );
		$this->assertSame( '2026-03-11 06:00:00', ZIM_Notifications::compute_release_at( 5, self::at_time( '20:30' ) ), 'the business\'s window' );
		$GLOBALS['zdz_test_user_meta'][5]['zim_quiet_hours_'] = array( 'start' => '13:00', 'end' => '14:00' );
		$this->assertSame( '2026-03-10 20:30:00', ZIM_Notifications::compute_release_at( 5, self::at_time( '20:30' ) ), 'the person\'s own wins' );
		$this->assertSame( '2026-03-10 14:00:00', ZIM_Notifications::compute_release_at( 5, self::at_time( '13:15' ) ) );
		$this->assertSame( array( 'start' => '13:00', 'end' => '14:00' ), ZIM_Notifications::get_quiet_hours( 5 ) );
		$GLOBALS['zdz_test_filters']['zdz_quiet_hours_for_user'] = array( 'ZIM_Notifications', 'own_quiet_hours' );   // as app.php registers it
		$this->assertSame( array( 'start' => '13:00', 'end' => '14:00' ), ZDZ_Quiet_Hours::for_user( 5 ), 'the theme reads the same person' );
		$this->assertSame( array( 'start' => '20:00', 'end' => '06:00' ), ZDZ_Quiet_Hours::for_user( 8 ) );
	}

	public function test_same_time_twice_holds_nothing_and_a_broken_setting_is_ignored(): void {
		$GLOBALS['zdz_test_user_meta'][5]['zim_quiet_hours_'] = array( 'start' => '22:00', 'end' => '22:00' );
		$this->assertSame( '2026-03-10 23:00:00', ZIM_Notifications::compute_release_at( 5, self::at_time( '23:00' ) ), 'the person turned it off' );
		$GLOBALS['zdz_test_user_meta'][5]['zim_quiet_hours_'] = array( 'start' => '25:00', 'end' => 'x' );
		$this->assertSame( '2026-03-11 07:00:00', ZIM_Notifications::compute_release_at( 5, self::at_time( '23:00' ) ), 'a broken setting: Core\'s window' );
		$GLOBALS['zdz_test_options']['zdz_quiet_hours'] = array( 'start' => '00:00', 'end' => '00:00' );
		$this->assertSame( '2026-03-10 23:00:00', ZIM_Notifications::compute_release_at( 5, self::at_time( '23:00' ) ), 'the business turned it off' );
	}

	public function test_a_person_saves_only_real_times(): void {
		$this->assertInstanceOf( 'WP_Error', ZIM_Notifications::set_quiet_hours( 5, '25:00', '07:00' ) );
		$this->assertInstanceOf( 'WP_Error', ZIM_Notifications::set_quiet_hours( 5, '9:00', '07:00' ) );
		$this->assertTrue( ZIM_Notifications::set_quiet_hours( 5, '21:30', '06:15' ) );
		$this->assertSame( array( 'start' => '21:30', 'end' => '06:15' ), $GLOBALS['zdz_test_user_meta'][5]['zim_quiet_hours_'] );
	}

	/* ── push setup ───────────────────────────────────────────────────── */

	public function test_push_setup_answers_in_the_shape_settings_accepts(): void {
		$GLOBALS['zdz_test_options']['zim_vapid_public_b64'] = str_repeat( 'B', 87 );
		$GLOBALS['wpdb']->on_col = function () { return array( str_repeat( 'c', 64 ) ); };
		$a = ZIM_Push::from_capability( array( 'user_id' => 5 ) );
		$this->assertTrue( $a['success'] );
		$this->assertSame( 'messages', $a['channel'] );
		$this->assertSame( 'zim_push_test', $a['test']['action'] );
		$c = ZDZ_Push_Setup::clean( ZIM_Push::VERB_SETUP, $a );
		$this->assertNotNull( $c, 'every field passes the theme\'s checks' );
		$this->assertSame( array( str_repeat( 'c', 64 ) ), $c['subscribed_hashes'] );
		$this->assertSame( array( 'start' => '21:00', 'end' => '07:00', 'action' => 'zim_set_quiet_hours', 'nonce' => wp_create_nonce( ZIM_NONCE ) ), $c['quiet_hours'], 'Core\'s window, with the action to change it' );
	}

	public function test_push_setup_refuses_the_shared_device_and_privacy_view(): void {
		$GLOBALS['zdz_test_options']['zim_vapid_public_b64'] = str_repeat( 'B', 87 );
		$this->assertFalse( ZIM_Push::from_capability( array( 'user_id' => 6 ) )['success'] );
		$this->assertFalse( ZIM_Push::from_capability( array( 'user_id' => 5, 'is_kiosk' => true ) )['success'] );
		$this->assertFalse( ZIM_Push::from_capability( array( 'user_id' => 7 ) )['success'], 'no Messages' );
		$GLOBALS['zdz_test_user_meta'][5]['zdz_privacy_view'] = '1';
		$this->assertFalse( ZIM_Push::from_capability( array( 'user_id' => 5 ) )['success'] );
		$this->assertInstanceOf( 'WP_Error', ZIM_Push::send_test( 5 ) );
	}

	public function test_push_setup_registers_as_a_read_for_the_viewer_tier(): void {
		$caps = ZIM_Push::register_capabilities( array() );
		$this->assertArrayHasKey( 'messaging.push_setup', $caps );
		$d = $caps['messaging.push_setup']['descriptor'];
		$this->assertFalse( $d['side_effect'] );
		$this->assertFalse( $d['kiosk'] );
		$this->assertSame( 'viewer', $d['min_tier'] );
		$n = ZDZ_Capabilities::normalize( 'messaging.push_setup', $caps['messaging.push_setup'] );
		$this->assertNotNull( $n, 'the registry accepts it' );
		$this->assertSame( 'internal-messaging', $n['app'] );
	}

	public function test_the_test_push_names_the_business_not_the_platform(): void {
		$p = ZIM_Push::test_payload();
		$this->assertStringNotContainsString( 'Zorderz', $p['title'] );
		$this->assertSame( 'https://app.example.com/', $p['url'] );
	}
}
