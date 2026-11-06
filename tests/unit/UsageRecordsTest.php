<?php
/**
 * Usage records (ZDZ_Admin_Dashboard's log and /track, ZDZ_Usage, ZDZ_Consent's notice):
 *
 *   - a usage row follows consent and never keeps the network address; an accountability row (a sign-in) always does;
 *   - /track keeps only the box and the kind of result for searches, only registered app actions, only digits as a
 *     reference, and writes a resent batch once;
 *   - pruning keeps raw rows at least 90 days, counts each batch into daily totals (time open too), and a filter may
 *     only take kinds away, never add an accountability row to what is pruned;
 *   - an app's own action counts only when its key is registered;
 *   - the notice says what the code does: no address on usage rows, how long detailed rows are kept.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/privacy-stubs.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-consent.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-admin-dashboard.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-usage.php';

final class UsageRecordsTest extends TestCase {

	const PERSON = 7;
	const SHARED = 8;

	protected function setUp(): void {
		$GLOBALS['wpdb']                  = new ZDZ_Test_Vault_DB();
		$GLOBALS['zdz_test_user_roles']   = array( self::PERSON => array( 'zdz_sales' ), self::SHARED => array( 'zdz_general' ) );
		$GLOBALS['zdz_test_current_user'] = self::PERSON;
		$GLOBALS['zdz_test_options']['zdz_audit_log_version'] = ZDZ_Admin_Dashboard::DB_VERSION;
		$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_transients'],
			$GLOBALS['zdz_test_user_roles'], $GLOBALS['zdz_test_current_user'], $GLOBALS['wpdb'], $_SERVER['REMOTE_ADDR'] );
		unset( $GLOBALS['zdz_test_user_meta'] );
	}

	private function rows() {
		return $GLOBALS['wpdb']->rows( 'zdz_audit_log' );
	}

	public function test_usage_rows_follow_consent_and_never_keep_the_address(): void {
		$this->assertTrue( ZDZ_Admin_Dashboard::log_action( self::PERSON, 'app_open', 'Jobs', 'jobs' ), 'card off: kept as before' );
		$this->assertTrue( ZDZ_Admin_Dashboard::log_action( self::PERSON, 'login', 'signed in' ) );
		$rows = $this->rows();
		$this->assertSame( '', $rows[0]['ip_address'], 'a usage row never keeps the network address' );
		$this->assertSame( '192.0.2.10', $rows[1]['ip_address'], 'a sign-in keeps it, for security' );

		$GLOBALS['zdz_test_options'][ ZDZ_Consent::OPT_ON ] = '1';   // the card is on; this person has not agreed
		$this->assertFalse( ZDZ_Admin_Dashboard::may_record( self::PERSON ) );
		$this->assertFalse( ZDZ_Admin_Dashboard::log_action( self::PERSON, 'heartbeat', 'sv-dash' ), 'no row about a person who has not agreed' );
		$this->assertTrue( ZDZ_Admin_Dashboard::log_action( self::PERSON, 'logout', 'signed out' ), 'accountability rows are always kept' );
		$this->assertCount( 3, $this->rows() );

		$GLOBALS['zdz_test_user_meta'][ self::PERSON ][ ZDZ_Consent::META ] = array( 'version' => ZDZ_Consent::version(), 'accepted_at' => '2026-01-01T00:00:00Z' );
		$this->assertTrue( ZDZ_Admin_Dashboard::log_action( self::PERSON, 'heartbeat', 'sv-dash' ) );
		$this->assertFalse( ZDZ_Admin_Dashboard::may_record( 0 ), 'a signed-out request is never recorded' );
		unset( $GLOBALS['zdz_test_user_meta'] );
	}

	public function test_track_keeps_kinds_not_words_and_a_resent_batch_once(): void {
		$GLOBALS['zdz_test_filters']['zdz_register_usage_actions'] = function ( $a ) { $a['jobs'] = array( 'job_done' => 'Finished a job' ); return $a; };
		$dash = ZDZ_Admin_Dashboard::get_instance();
		$req  = new WP_REST_Request( array(), array( 'bid' => 'b1abcdef', 'events' => array(
			array( 'action_type' => 'search', 'detail' => 'Sam Example gate code', 'sid' => 'abc123' ),
			array( 'action_type' => 'search', 'detail' => 'ask', 'app_id' => 'x' ),
			array( 'action_type' => 'app_action', 'app_id' => 'jobs', 'act' => 'job_done', 'ref' => 'Sam Example' ),
			array( 'action_type' => 'app_action', 'app_id' => 'jobs', 'act' => 'not_registered' ),
			array( 'action_type' => 'app_close', 'secs' => 30 ),
			array( 'action_type' => 'login' ),
			array( 'action_type' => 'view_switch', 'detail' => '<b>free text, with a name</b>' ),
		) ) );
		$out  = $dash->rest_track( $req )->get_data();
		$this->assertSame( 4, $out['logged'] );
		$details = array_column( $this->rows(), 'action_detail' );
		$this->assertSame( array( '', 'ask', 'job_done', '' ), $details, 'never the typed words, never free text' );
		$meta = json_decode( (string) $this->rows()[2]['meta_json'], true );
		$this->assertSame( 'job_done', $meta['act'] );
		$this->assertArrayNotHasKey( 'ref', $meta, 'a reference is digits only' );
		$this->assertTrue( $dash->rest_track( $req )->get_data()['duplicate'], 'a resent batch is written once' );

		$GLOBALS['zdz_test_options'][ ZDZ_Consent::OPT_ON ] = '1';
		$no = $dash->rest_track( new WP_REST_Request( array(), array( 'events' => array( array( 'action_type' => 'heartbeat' ) ) ) ) )->get_data();
		$this->assertFalse( $no['recording'] );
		$this->assertSame( 0, $no['logged'] );
	}

	public function test_pruning_keeps_ninety_days_at_least_and_rolls_up_counts_and_time(): void {
		$now = gmmktime( 12, 0, 0, 6, 30, 2026 );
		$this->assertSame( 180, ZDZ_Admin_Dashboard::raw_days() );
		$this->assertSame( '2026-01-01 00:00:00', ZDZ_Admin_Dashboard::prune_cutoff( $now, 180 ) );
		$this->assertSame( ZDZ_Admin_Dashboard::prune_cutoff( $now, 90 ), ZDZ_Admin_Dashboard::prune_cutoff( $now, 10 ), 'never under 90 days' );
		$GLOBALS['zdz_test_filters']['zdz_audit_raw_days'] = function () { return 30; };
		$this->assertSame( 90, ZDZ_Admin_Dashboard::raw_days() );
		$GLOBALS['zdz_test_filters']['zdz_audit_raw_days'] = function () { return 365; };
		$this->assertSame( 365, ZDZ_Admin_Dashboard::raw_days() );

		list( $groups, $secs, $ids ) = ZDZ_Admin_Dashboard::rollup( array(
			array( 'id' => 5, 'user_id' => 7, 'action_type' => 'app_open', 'app_id' => 'jobs', 'created_at' => '2025-12-01 08:00:00', 'secs' => null ),
			array( 'id' => 6, 'user_id' => 7, 'action_type' => 'app_open', 'app_id' => 'jobs', 'created_at' => '2025-12-01 17:59:59', 'secs' => null ),
			array( 'id' => 7, 'user_id' => 7, 'action_type' => 'app_close', 'app_id' => 'jobs', 'created_at' => '2025-12-01 09:00:00', 'secs' => 300 ),
			array( 'id' => 8, 'user_id' => 7, 'action_type' => 'app_close', 'app_id' => 'jobs', 'created_at' => '2025-12-01 18:00:00', 'secs' => 45 ),
			array( 'id' => 9, 'user_id' => 8, 'action_type' => 'app_open', 'app_id' => 'jobs', 'created_at' => '2025-12-02 08:00:00', 'secs' => null ),
		) );
		$this->assertSame( array( 5, 6, 7, 8, 9 ), $ids );
		$this->assertSame( 2, $groups['2025-12-01|7|app_open|jobs'] );
		$this->assertSame( 2, $groups['2025-12-01|7|app_close|jobs'] );
		$this->assertSame( 345, $secs['2025-12-01|7|app_close|jobs'], 'time open survives as a daily total' );
		$this->assertSame( 1, $groups['2025-12-02|8|app_open|jobs'] );
	}

	public function test_a_filter_may_only_take_kinds_away_from_pruning(): void {
		$GLOBALS['zdz_test_filters']['zdz_audit_usage_kinds'] = function () { return array( 'app_open', 'login', 'role_change', 'heartbeat' ); };
		$this->assertSame( array( 'app_open', 'heartbeat' ), ZDZ_Admin_Dashboard::usage_kinds(), 'an accountability row is never pruned' );
	}

	public function test_page_detail_keeps_the_path_never_the_query_and_device_from_agent(): void {
		$this->assertSame( 'SPA shell loaded: /app/jobs', ZDZ_Admin_Dashboard::page_detail( '/app/jobs?q=Sam+Example#x' ) );
		$this->assertSame( 'phone', ZDZ_Admin_Dashboard::device_from_agent( 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0)' ) );
		$this->assertSame( 'browser', ZDZ_Admin_Dashboard::device_from_agent( 'Mozilla/5.0 (X11; Linux x86_64)' ) );
	}

	public function test_an_app_counts_only_its_registered_actions(): void {
		// the registry is read once a request, as apps register on load
		$GLOBALS['zdz_test_filters']['zdz_register_usage_actions'] = function ( $a ) { $a['jobs'] = array( 'job_done' => 'Finished a job' ); return $a; };
		$this->assertFalse( ZDZ_Usage::record( 'jobs', 'not_registered', 12 ), 'only a registered key' );
		$this->assertFalse( ZDZ_Usage::record( 'Jobs App!', 'job_done', 12 ), 'a clean app id only' );
		$this->assertTrue( ZDZ_Usage::record( 'jobs', 'job_done', 12, array( 'once' => 'day' ) ) );
		$this->assertFalse( ZDZ_Usage::record( 'jobs', 'job_done', 12, array( 'once' => 'day' ) ), 'once a day when asked' );
		$meta = json_decode( (string) $this->rows()[0]['meta_json'], true );
		$this->assertSame( '12', $meta['ref'] );
		$this->assertTrue( ZDZ_Usage::is_shared( self::SHARED ) );
		$this->assertFalse( ZDZ_Usage::is_shared( self::PERSON ) );
	}

	public function test_the_notice_says_what_the_code_records(): void {
		$this->assertSame( 2, ZDZ_Consent::VERSION );
		$w = ZDZ_Consent::wording();
		$this->assertStringContainsString( 'usage records themselves never do', $w );
		$this->assertStringContainsString( 'deleted after 180 days', $w );
		$this->assertStringNotContainsString( '{raw_days}', $w );
		$this->assertStringNotContainsString( '{business}', $w );
		$s = ZDZ_Consent::summary();
		$this->assertStringContainsString( '180 days', $s['you'] );
		$GLOBALS['zdz_test_filters']['zdz_audit_raw_days'] = function () { return 365; };
		$this->assertStringContainsString( 'deleted after 365 days', ZDZ_Consent::wording(), 'the notice follows the site\'s own setting' );
	}
}
