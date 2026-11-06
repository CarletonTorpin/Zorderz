<?php
/**
 * Leads: the billing sign-in's CSRF state, Core-first credentials, the forward notice, the no-web rule and the
 * lead.counts month window.
 *
 *   - A state works once, for the admin it was issued to, before it expires; a forged, altered, replayed, expired
 *     or someone else's state is refused, and nothing is exchanged.
 *   - A credential is read from the platform's copy first; a paired login (CRM email + key, billing id + secret)
 *     comes from the platform only when both halves are there.
 *   - A forward's push title never names the customer.
 *   - No leads Ai call may search the web.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['zdz_test_transients'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $k ) { return $GLOBALS['zdz_test_transients'][ $k ] ?? false; }
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $k ) { unset( $GLOBALS['zdz_test_transients'][ $k ] ); return true; }
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $p = '' ) { return 'https://example.com/wp-admin/' . ltrim( (string) $p, '/' ); }
}

require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/leads/includes/class-zl-oauth-state.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/leads/includes/class-zl-admin.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/leads/includes/class-zl-freshbooks.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/leads/includes/class-zl-lead-interaction.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/leads/includes/class-zl-poe-client.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/leads/includes/class-zl-counts.php';

final class LeadsSignInAndCountsTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_transients'] );
		ZL_Counts::$now = null;
	}

	/* ── the sign-in state ────────────────────────────────────────────── */

	public function test_a_state_works_once_for_its_admin(): void {
		$now   = 1800000000;
		$state = ZL_OAuth_State::issue( 4, $now );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}\.[0-9]+\.[a-f0-9]{64}$/', $state );
		$this->assertSame( '', ZL_OAuth_State::verify( $state, 4, $now + 30 ) );
		$this->assertSame( 'used', ZL_OAuth_State::verify( $state, 4, $now + 31 ), 'a replay is refused' );
	}

	public function test_a_state_expires(): void {
		$now   = 1800000000;
		$state = ZL_OAuth_State::issue( 4, $now );
		$this->assertSame( 'expired', ZL_OAuth_State::verify( $state, 4, $now + ZL_OAuth_State::TTL + 1 ) );
		$this->assertSame( 'used', ZL_OAuth_State::verify( $state, 4, $now + 5 ), 'an expired state is spent too' );
	}

	public function test_someone_elses_or_an_altered_state_is_refused(): void {
		$now   = 1800000000;
		$state = ZL_OAuth_State::issue( 4, $now );
		$this->assertSame( 'bad-signature', ZL_OAuth_State::verify( $state, 5, $now ), 'issued to another admin' );
		$parts    = explode( '.', $state );
		$parts[1] = (string) ( (int) $parts[1] + 3600 );
		$this->assertSame( 'bad-signature', ZL_OAuth_State::verify( implode( '.', $parts ), 4, $now ), 'a stretched expiry' );
		$this->assertSame( '', ZL_OAuth_State::verify( $state, 4, $now ), 'the untouched state still works' );
	}

	public function test_missing_or_malformed_state_is_refused(): void {
		$this->assertSame( 'missing', ZL_OAuth_State::verify( '', 4 ) );
		$this->assertSame( 'malformed', ZL_OAuth_State::verify( 'abc', 4 ) );
		$this->assertSame( '', ZL_OAuth_State::issue( 0 ), 'nobody signed in: no state' );
		$forged = str_repeat( 'a', 32 ) . '.1800000600.' . str_repeat( 'b', 64 );
		$this->assertSame( 'bad-signature', ZL_OAuth_State::verify( $forged, 4, 1800000000 ) );
	}

	public function test_refusals_are_plain_words(): void {
		foreach ( array( 'expired', 'used', 'bad-signature', 'missing' ) as $why ) {
			$this->assertStringContainsString( 'Connect', ZL_OAuth_State::refusal( $why ) );
		}
	}

	/* ── Core-first credentials ───────────────────────────────────────── */

	public function test_the_connect_url_is_the_providers_with_a_state_for_this_admin(): void {
		$url = ZL_Admin::authorize_url_for( 'client-abc', 4 );
		$this->assertSame( 0, strpos( $url, 'https://' ), 'the credentials screen only follows https' );
		$this->assertStringContainsString( 'client_id=client-abc', $url );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
		$state = rawurldecode( (string) ( $q['state'] ?? '' ) );
		$this->assertNotSame( '', $state );
		$this->assertSame( 'bad-signature', ZL_OAuth_State::verify( $state, 5 ), 'bound to the admin it was made for' );
		$this->assertSame( '', ZL_OAuth_State::verify( $state, 4 ), 'and good for that admin' );
		$this->assertSame( '', ZL_Admin::authorize_url_for( '', 4 ), 'no client id, no link' );
		$this->assertSame( '', ZL_Admin::authorize_url_for( 'client-abc', 0 ), 'no signed-in admin, no link' );
		$this->assertSame( 'https://already.example/x', ZL_Admin::billing_connect_url( 'https://already.example/x', 'client-abc' ), 'an offered link is left alone' );
	}

	public function test_core_copy_is_read_first(): void {
		$core = array( 'poe_api_key' => 'core-key', 'ns_email' => 'crm@example.com', 'ns_api_key' => 'core-crm' );
		$look = function ( $k ) use ( $core ) { return $core[ $k ] ?? ''; };
		$this->assertSame( 'core-key', ZL_Admin::core_first( 'zl_poe_api_key', $look ) );
		$this->assertSame( 'crm@example.com', ZL_Admin::core_first( 'zl_ns_email', $look ) );
		$this->assertSame( 'core-crm', ZL_Admin::core_first( 'zl_ns_api_key', $look ) );
		$this->assertSame( '', ZL_Admin::core_first( 'zl_some_other_option', $look ), 'only known credentials' );
	}

	public function test_a_half_moved_pair_falls_back_whole(): void {
		$look = function ( $k ) { return 'fb_client_id' === $k ? 'core-id' : ''; };
		$this->assertSame( '', ZL_Admin::core_first( 'zl_fb_client_id', $look ), 'no platform secret: this app keeps its own id too' );
		$this->assertSame( '', ZL_Admin::core_first( 'zl_fb_client_secret', $look ) );
	}

	public function test_without_the_credentials_service_nothing_is_read_from_core(): void {
		$this->assertSame( '', ZL_Admin::core_credential( 'poe_api_key' ) );
	}

	/* ── the forward notice ───────────────────────────────────────────── */

	public function test_a_forward_push_never_names_the_customer(): void {
		$w = ZL_Lead_Interaction::forward_alert_words( array( 'first_name' => 'Riley', 'last_name' => 'Example', 'city' => 'Springfield' ), 'Sam', 'Call back Tuesday', true );
		$this->assertSame( 'Lead forwarded', $w['title'] );
		$this->assertStringNotContainsString( 'Riley', $w['title'] );
		$this->assertSame( 0, strpos( $w['message'], 'Task: Sam forwarded you Riley Example (Springfield).' ) );
		$this->assertStringContainsString( 'Note: Call back Tuesday', $w['message'] );
		$w2 = ZL_Lead_Interaction::forward_alert_words( array(), '', '', false );
		$this->assertSame( 'You were forwarded a lead.', $w2['message'] );
	}

	/* ── no web search ────────────────────────────────────────────────── */

	public function test_no_leads_call_searches_the_web(): void {
		$this->assertSame( array( 'thinking_budget' => 10 ), @ZL_Poe_Client::without_web( array( 'thinking_budget' => 10, 'web_search' => true ) ) );
		$this->assertSame( array(), ZL_Poe_Client::without_web( array( 'web_search' => false ) ) );
	}

	/* ── lead.counts ──────────────────────────────────────────────────── */

	public function test_month_window_edges_and_refusals(): void {
		ZL_Counts::$now = gmmktime( 12, 0, 0, 3, 15, 2027 );
		$w = ZL_Counts::month_window( '' );
		$this->assertSame( '2027-03', $w['month'] );
		$this->assertSame( '2027-03-01', $w['from'] );
		$this->assertSame( '2027-03-31', $w['to'] );
		$this->assertSame( '2027-04-01 00:00:00', $w['to_utc'] );
		$this->assertArrayHasKey( 'error', ZL_Counts::month_window( '2027-04' ), 'a future month' );
		$this->assertArrayHasKey( 'error', ZL_Counts::month_window( '2027-3' ), 'malformed' );
		$this->assertArrayHasKey( 'error', ZL_Counts::month_window( '2023-01' ), 'too far back' );
	}

	public function test_counts_verb_is_a_read_not_on_the_shared_device(): void {
		$caps = ZL_Counts::register( array() );
		$d    = $caps['lead.counts']['descriptor'];
		$this->assertFalse( $d['side_effect'] );
		$this->assertFalse( $d['kiosk'] );
		$this->assertSame( 'leads', $d['app'] );
		$r = ZL_Counts::from_capability( array( 'user_id' => 3, 'is_kiosk' => true ) );
		$this->assertTrue( $r['denied'] );
		$this->assertSame( 'kiosk', $r['reason'] );
		$this->assertSame( 'signed_out', ZL_Counts::from_capability( array() )['reason'] );
	}
}
