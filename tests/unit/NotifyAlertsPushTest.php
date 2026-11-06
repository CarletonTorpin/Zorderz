<?php
/**
 * Notifications: quiet hours, the alert router's bell and push decisions, and push setup.
 *
 *   - ZDZ_Quiet_Hours: a window inside one day, a window across midnight, "no quiet hours", and the
 *     order person -> business -> Core; a broken setting never stands in for a window.
 *   - ZDZ_Alert_Router: the shared device never gets an alert; push carries the title only, a
 *     same-site link only; a category grant comes from configuration, only for a role really held;
 *     a person's on/off never widens a channel.
 *   - ZDZ_Push_Setup: an owner's answer is checked field by field (key, worker on this site, scope,
 *     actions, hashes, quiet hours), and the shared device is refused.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/notify-media-stubs.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-quiet-hours.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-alert-router.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-push-setup.php';

final class NotifyAlertsPushTest extends TestCase {

	protected function tearDown(): void {
		zdz_notify_test_reset();
		$r = new ReflectionProperty( 'ZDZ_Alert_Router', 'config_cache' );
		$r->setAccessible( true );
		$r->setValue( null, null );
	}

	private static function at_time( string $hm, string $day = '2026-01-15' ): DateTimeImmutable {
		return new DateTimeImmutable( $day . ' ' . $hm . ':00', new DateTimeZone( 'UTC' ) );
	}

	/* ── Quiet hours ──────────────────────────────────────────────────────── */

	public function test_overnight_window_holds_until_the_morning(): void {
		$w = array( 'start' => '21:00', 'end' => '07:00' );
		$this->assertTrue( ZDZ_Quiet_Hours::in_window( self::at_time( '22:30' ), $w ) );
		$this->assertSame( '2026-01-16 07:00', ZDZ_Quiet_Hours::release_at( self::at_time( '22:30' ), $w )->format( 'Y-m-d H:i' ), 'before midnight: tomorrow morning' );
		$this->assertSame( '2026-01-15 07:00', ZDZ_Quiet_Hours::release_at( self::at_time( '03:10' ), $w )->format( 'Y-m-d H:i' ), 'after midnight: this morning' );
		$this->assertFalse( ZDZ_Quiet_Hours::in_window( self::at_time( '07:00' ), $w ), 'the end itself is outside' );
		$this->assertSame( '2026-01-15 12:00', ZDZ_Quiet_Hours::release_at( self::at_time( '12:00' ), $w )->format( 'Y-m-d H:i' ), 'outside: now' );
		$this->assertTrue( ZDZ_Quiet_Hours::in_window( self::at_time( '21:00' ), $w ), 'the start itself is inside' );
	}

	public function test_window_within_one_day(): void {
		$w = array( 'start' => '13:00', 'end' => '14:00' );
		$this->assertTrue( ZDZ_Quiet_Hours::in_window( self::at_time( '13:30' ), $w ) );
		$this->assertSame( '14:00', ZDZ_Quiet_Hours::release_at( self::at_time( '13:30' ), $w )->format( 'H:i' ) );
		$this->assertFalse( ZDZ_Quiet_Hours::in_window( self::at_time( '12:59' ), $w ) );
		$this->assertFalse( ZDZ_Quiet_Hours::in_window( self::at_time( '23:00' ), $w ) );
	}

	public function test_same_start_and_end_holds_nothing(): void {
		$this->assertSame( array(), ZDZ_Quiet_Hours::normalize( array( 'start' => '08:00', 'end' => '08:00' ) ) );
		$this->assertFalse( ZDZ_Quiet_Hours::in_window( self::at_time( '08:00' ), array() ) );
		$this->assertNull( ZDZ_Quiet_Hours::normalize( array( 'start' => '25:00', 'end' => '07:00' ) ), 'not a time' );
		$this->assertNull( ZDZ_Quiet_Hours::normalize( array( 'start' => '9:00', 'end' => '07:00' ) ), 'HH:MM only' );
		$this->assertNull( ZDZ_Quiet_Hours::normalize( 'nonsense' ) );
	}

	public function test_person_then_business_then_core(): void {
		$this->assertSame( ZDZ_Quiet_Hours::CORE_DEFAULT, ZDZ_Quiet_Hours::for_user( 5 ), 'nothing set: Core' );
		$GLOBALS['zdz_test_options']['zdz_quiet_hours'] = array( 'start' => '20:00', 'end' => '06:30' );
		$this->assertSame( array( 'start' => '20:00', 'end' => '06:30' ), ZDZ_Quiet_Hours::for_user( 5 ), 'the business' );
		$GLOBALS['zdz_test_filters']['zdz_quiet_hours_for_user'] = function ( $w, $uid ) { return 5 === $uid ? array( 'start' => '23:00', 'end' => '05:00' ) : $w; };
		$this->assertSame( array( 'start' => '23:00', 'end' => '05:00' ), ZDZ_Quiet_Hours::for_user( 5 ), 'the person' );
		$this->assertSame( array( 'start' => '20:00', 'end' => '06:30' ), ZDZ_Quiet_Hours::for_user( 6 ) );
		$GLOBALS['zdz_test_options']['zdz_quiet_hours'] = array( 'start' => 'x', 'end' => '' );
		unset( $GLOBALS['zdz_test_filters'] );
		$this->assertSame( ZDZ_Quiet_Hours::CORE_DEFAULT, ZDZ_Quiet_Hours::for_user( 6 ), 'a broken setting is never a window' );
		$GLOBALS['zdz_test_options']['zdz_quiet_hours'] = array( 'start' => '00:00', 'end' => '00:00' );
		$this->assertSame( array(), ZDZ_Quiet_Hours::for_user( 6 ), 'the business turned quiet hours off' );
	}

	public function test_setting_keeps_only_two_valid_times(): void {
		$this->assertNull( ZDZ_Quiet_Hours::sanitize_setting( array( 'start' => '21:00' ) ) );
		$this->assertSame( array( 'start' => '21:30', 'end' => '06:00' ), ZDZ_Quiet_Hours::sanitize_setting( array( 'start' => '21:30', 'end' => '06:00', 'x' => 1 ) ) );
	}

	/* ── Alert router ─────────────────────────────────────────────────────── */

	public function test_the_shared_device_never_gets_an_alert(): void {
		$GLOBALS['zdz_test_user_roles'] = array( 40 => array( 'zdz_general', 'zdz_admin' ), 41 => array( 'zdz_admin' ) );
		$this->assertTrue( ZDZ_Alert_Router::is_kiosk_user( 40 ), 'whatever other roles it holds' );
		$this->assertFalse( ZDZ_Alert_Router::is_kiosk_user( 41 ) );
		$this->assertFalse( ZDZ_Alert_Router::is_kiosk_user( 0 ) );
		$this->assertSame( array(), ZDZ_Alert_Router::send( 'survey_note_forwarded', 40, 'A title', 'A message' ) );
	}

	public function test_push_carries_the_title_and_a_same_site_link_only(): void {
		$p = ZDZ_Alert_Router::push_payload( 'Jobs Estimate', str_repeat( 'T', 200 ), array(
			'url' => 'https://elsewhere.example.org/x', 'source_id' => '12<x>', 'push_body' => array( 'not', 'text' ),
		) );
		$this->assertSame( 120, strlen( $p['title'] ) );
		$this->assertSame( 'Tap to open.', $p['body'], 'an array is never "Array"' );
		$this->assertSame( 'https://app.example.com/', $p['url'], 'another site falls back to home' );
		$this->assertSame( 'zdz-alert-jobsestimate-12x', $p['tag'] );
		$this->assertSame( array( 'title', 'body', 'url', 'tag' ), array_keys( $p ), 'the message is never in a push' );
	}

	public function test_same_site_url_refuses_other_places(): void {
		$this->assertSame( '/vault/a', ZDZ_Alert_Router::same_site_url( '/vault/a' ) );
		$this->assertSame( '', ZDZ_Alert_Router::same_site_url( '//evil.example.net/a' ) );
		$this->assertSame( '', ZDZ_Alert_Router::same_site_url( 'https://evil.example.net/a' ) );
		$this->assertSame( '', ZDZ_Alert_Router::same_site_url( 'https://user:pw@app.example.com/a' ) );
		$this->assertSame( '', ZDZ_Alert_Router::same_site_url( "/a\\b" ) );
		$this->assertSame( '', ZDZ_Alert_Router::same_site_url( "/a b" ) );
		$this->assertSame( '', ZDZ_Alert_Router::same_site_url( 'http://app.example.com/a' ), 'another scheme' );
		$this->assertSame( 'https://app.example.com:443/a', ZDZ_Alert_Router::same_site_url( 'https://app.example.com:443/a' ), 'the default port is no port' );
		$this->assertSame( '', ZDZ_Alert_Router::same_site_url( 'https://app.example.com:8443/a' ) );
		$this->assertSame( '', ZDZ_Alert_Router::same_site_url( 'javascript:alert(1)' ) );
	}

	public function test_category_grants_come_from_configuration_and_are_cleaned(): void {
		$this->assertSame( array(), ZDZ_Alert_Router::role_category_grants(), 'Core grants nothing' );
		$GLOBALS['zdz_test_options']['zdz_alert_role_category_grants'] = array(
			'zdz_tech'    => array( 'jobs' => array( 'in_app', 'push', 'fax' ) ),
			'zdz_general' => array( 'jobs' => array( 'in_app' ) ),
			'made_up'     => array( 'jobs' => array( 'in_app' ) ),
		);
		$g = ZDZ_Alert_Router::role_category_grants();
		$this->assertSame( array( 'zdz_tech' => array( 'jobs' => array( 'in_app', 'push' ) ) ), $g, 'known roles and channels only; never the shared device' );
	}

	public function test_a_grant_opens_only_its_category_and_only_for_a_role_really_held(): void {
		$GLOBALS['zdz_test_user_roles'] = array( 50 => array( 'zdz_tech' ), 51 => array( 'subscriber' ), 52 => array( 'zdz_sales' ) );
		$r = ZDZ_Alert_Router::get_instance();
		$r->register_type( 'test_job_assigned', array( 'default_channels' => array( 'in_app', 'push', 'email' ), 'category' => 'jobs' ) );
		$r->register_type( 'test_survey', array( 'default_channels' => array( 'in_app', 'push' ), 'category' => 'surveys' ) );
		$this->assertSame( array(), $r->resolve_channels( 'test_job_assigned', 50 ), 'no grant: a field role gets nothing' );
		$this->assertSame( array( 'in_app', 'push' ), $r->resolve_channels( 'test_job_assigned', 52 ), 'sales: bell and push, never email' );
		$GLOBALS['zdz_test_options']['zdz_alert_role_category_grants'] = array( 'zdz_tech' => array( 'jobs' => array( 'in_app', 'push' ) ) );
		$this->assertSame( array( 'in_app', 'push' ), $r->resolve_channels( 'test_job_assigned', 50 ) );
		$this->assertSame( array(), $r->resolve_channels( 'test_survey', 50 ), 'another category stays closed' );
		$this->assertSame( array(), $r->resolve_channels( 'test_job_assigned', 51 ), 'no platform role: no grant with the fallback row' );
		$GLOBALS['zdz_test_user_meta'][50]['zdz_alert_prefs'] = array( 'test_job_assigned' => array() );
		$this->assertSame( array(), $r->resolve_channels( 'test_job_assigned', 50 ), 'turned off by the person' );
		$this->assertSame( array( 'in_app', 'push' ), $r->resolve_channels( 'test_job_assigned', 50, false ), 'before their own choices' );
		$GLOBALS['zdz_test_user_meta'][50]['zdz_alert_prefs'] = array( 'test_job_assigned' => array( 'email', 'in_app' ) );
		$this->assertSame( array( 'in_app' ), $r->resolve_channels( 'test_job_assigned', 50 ), 'a choice never widens past the role' );
	}

	public function test_a_preference_change_keeps_known_types_and_channels(): void {
		$known = array( 'a', 'b' );
		$p = ZDZ_Alert_Router::apply_pref( array( 'b' => array( 'in_app', 'sms' ), 'zz' => array( 'in_app' ) ), 'a', false, $known );
		$this->assertSame( array( 'b' => array( 'in_app' ), 'a' => array() ), $p );
		$p = ZDZ_Alert_Router::apply_pref( $p, 'a', true, $known );
		$this->assertSame( array( 'b' => array( 'in_app' ) ), $p, 'on again: back to the defaults' );
	}

	/* ── Push setup ───────────────────────────────────────────────────────── */

	private static function answer( array $over = array() ): array {
		return array_merge( array(
			'channel'           => 'messages',
			'label'             => 'Notices and messages',
			'parts'             => array( 'notices', 'Messages' ),
			'vapid_public_key'  => str_repeat( 'A', 87 ),
			'sw_url'            => 'https://app.example.com/zim-sw',
			'sw_scope'          => '/zim/',
			'subscribe'         => array( 'action' => 'zim_push_subscribe', 'nonce' => 'abc123' ),
			'unsubscribe'       => array( 'action' => 'zim_push_unsubscribe', 'nonce' => 'abc123' ),
			'subscribed_hashes' => array( str_repeat( 'a', 64 ), 'nothex' ),
			'current_hashes'    => array( str_repeat( 'a', 64 ), str_repeat( 'b', 64 ) ),
			'quiet_hours'       => array( 'start' => '21:00', 'end' => '07:00', 'action' => 'zim_set_quiet_hours', 'nonce' => 'n1' ),
		), $over );
	}

	public function test_push_setup_keeps_a_good_answer_field_by_field(): void {
		$c = ZDZ_Push_Setup::clean( 'messaging.push_setup', self::answer() );
		$this->assertNotNull( $c );
		$this->assertSame( array( str_repeat( 'a', 64 ) ), $c['subscribed_hashes'], 'hashes are 64 hex characters' );
		$this->assertSame( array( str_repeat( 'a', 64 ) ), $c['current_hashes'], 'only ones the list holds' );
		$this->assertSame( array( 'start' => '21:00', 'end' => '07:00', 'action' => 'zim_set_quiet_hours', 'nonce' => 'n1' ), $c['quiet_hours'] );
		$this->assertNull( $c['test'] );
	}

	public function test_push_setup_drops_an_unsafe_answer(): void {
		$this->assertNull( ZDZ_Push_Setup::clean( 'x.push_setup', self::answer( array( 'vapid_public_key' => 'short' ) ) ) );
		$this->assertNull( ZDZ_Push_Setup::clean( 'x.push_setup', self::answer( array( 'sw_url' => 'https://evil.example.net/sw' ) ) ), 'a worker on another site' );
		$this->assertNull( ZDZ_Push_Setup::clean( 'x.push_setup', self::answer( array( 'sw_scope' => '/a/../b/' ) ) ) );
		$this->assertNull( ZDZ_Push_Setup::clean( 'x.push_setup', self::answer( array( 'sw_scope' => 'https://app.example.com/' ) ) ) );
		$this->assertNull( ZDZ_Push_Setup::clean( 'x.push_setup', self::answer( array( 'subscribe' => array( 'action' => 'Bad Action', 'nonce' => 'x' ) ) ) ) );
		$c = ZDZ_Push_Setup::clean( 'x.push_setup', self::answer( array( 'quiet_hours' => array( 'start' => '9pm', 'end' => '07:00', 'action' => 'a_b', 'nonce' => 'n' ) ) ) );
		$this->assertArrayNotHasKey( 'quiet_hours', $c, 'quiet hours that are not times are left out' );
	}

	public function test_push_is_never_offered_to_the_shared_device(): void {
		$GLOBALS['zdz_test_user_roles'] = array( 60 => array( 'zdz_general' ), 61 => array( 'zdz_sales' ) );
		$this->assertFalse( ZDZ_Push_Setup::allowed( 60 ) );
		$this->assertFalse( ZDZ_Push_Setup::allowed( 0 ) );
		$this->assertTrue( ZDZ_Push_Setup::allowed( 61 ) );
	}
}
