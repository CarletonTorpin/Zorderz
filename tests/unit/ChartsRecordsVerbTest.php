<?php
/**
 * The registry verb charts.records, run through the real ZDZ_Capabilities::invoke() with a stand-in hub:
 *
 *   - registered as a read for anyone signed in, never the shared device, beside charts.run and charts.prospect;
 *   - shut until the business shares records with the assistant (Core: off), and then only for the people the Charts
 *     page lets search lines: the owner gets lines and jobs; a salesperson with invoice cards but no line items, a
 *     field role without Charts, the shared device and nobody signed in get nothing, and the hub is never asked;
 *   - sent as the page's own access block, cut to the two ops and health, named accounts and invoice cards off; a
 *     person kept to their own jobs keeps that scope; the caller cannot name who is asking;
 *   - rows keep known keys only (a customer name or a link in a hub row never comes back), dollars only with money;
 *   - e-mail addresses, phone numbers and street addresses in line text are replaced; sizes and money stay;
 *   - whole words only when the caller asks, and the hub's method is said back;
 *   - no hub is "local mode" in words; a hub that failed is not asked again for five minutes; an answer without a list
 *     is an error, never an empty success.
 *
 * Invented data only (Alex, Jordan, Example Co, 555 numbers, example.com, towns that do not exist).
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

foreach ( array( 'ZCH_WEB_PARTY' => 'zorderz-site', 'ZCH_HUB_PARTY' => 'charts-hub', 'ZDZ_REST_NS' => 'zorderz/v1', 'ZCH_APP_ID' => 'charts', 'ZCH_OPTION' => 'zch_settings', 'ZCH_TABLE_AUDIT' => 'zch_audit' ) as $zdz_test_rec_k => $zdz_test_rec_v ) {
	if ( ! defined( $zdz_test_rec_k ) ) {
		define( $zdz_test_rec_k, $zdz_test_rec_v );
	}
}
if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $uid, $key, $single = false ) { return $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] ?? ''; }
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $k ) { return $GLOBALS['zdz_test_transients'][ $k ] ?? false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['zdz_test_transients'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $k ) { unset( $GLOBALS['zdz_test_options'][ $k ] ); return true; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ); }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component ); }
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $timestamp = null ) { return gmdate( (string) $format, null === $timestamp ? time() : (int) $timestamp ); }
}
if ( ! function_exists( '_n' ) ) {
	function _n( $one, $many, $n, $d = null ) { return 1 === (int) $n ? $one : $many; }
}

require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-capabilities.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-hierarchy.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-sealed.php';
$zdz_test_rec_dir = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/charts/includes/';
foreach ( array( 'envelope', 'config', 'access', 'hub-client', 'store', 'prospect', 'chat-bridge', 'prospect-verb', 'records-verb' ) as $zdz_test_rec_f ) {
	require_once $zdz_test_rec_dir . 'class-zch-' . $zdz_test_rec_f . '.php';
}

if ( ! class_exists( 'ZDZ_Test_Rec_Hub' ) ) {
	/** A hub that keeps every call it gets and answers from a callable or a fixed reply. */
	final class ZDZ_Test_Rec_Hub {
		public $last_ms = 4;
		public $calls   = array();
		public $answer;
		public function __construct( $answer ) { $this->answer = $answer; }
		public function call( $op, array $body, $user, $today ) {
			$this->calls[] = array( 'op' => $op, 'body' => $body, 'user' => $user, 'today' => $today );
			return is_callable( $this->answer ) ? call_user_func( $this->answer, $op, $body ) : $this->answer;
		}
	}
	/** The audit table: every row the verb writes. */
	final class ZDZ_Test_Rec_DB {
		public $prefix = 'wp_';
		public $rows   = array();
		public function insert( $table, $row, $fmt = null ) {
			$this->rows[] = array( $table, $row );
			return 1;
		}
	}
}

final class ChartsRecordsVerbTest extends TestCase {

	/** @var ZDZ_Test_Rec_Hub */
	private $hub;

	protected function setUp(): void {
		$GLOBALS['zdz_test_user_roles']  = array(
			1 => array( 'administrator' ),
			2 => array( 'zdz_sales' ),
			4 => array( 'zdz_tech' ),
			5 => array( 'zdz_general' ),
			6 => array( 'zdz_sales' ),
		);
		$GLOBALS['zdz_test_user_meta']   = array(
			1 => array( 'zdz_user_initials' => 'AB' ),
			2 => array( 'zdz_user_initials' => 'JM' ),
			4 => array( 'zdz_user_initials' => 'RT' ),
			6 => array( 'zdz_user_initials' => 'CQ', 'zch_permissions' => array( 'charts_line_items' => 'allow' ) ),
		);
		$GLOBALS['zdz_test_user_caps']   = array();
		$GLOBALS['zdz_test_transients']  = array();
		$GLOBALS['zdz_test_options']     = array();
		$GLOBALS['zdz_test_current_user'] = 0;
		$GLOBALS['wpdb']                 = new ZDZ_Test_Rec_DB();
		$GLOBALS['zdz_test_filters']     = array(
			// the platform's switches resolved by hand: a salesperson sees their own dollars and nothing of anyone else's
			'zch_role_defaults'         => function ( $d ) {
				foreach ( $d as $role => $keys ) {
					foreach ( $keys as $k => $v ) {
						if ( is_string( $v ) && 0 === strpos( $v, 'theme:' ) ) {
							$d[ $role ][ $k ] = 'zdz_sales' === $role ? ( 'charts_money' === $k ? 'allow' : 'deny' ) : 'allow';
						}
					}
				}
				return $d;
			},
			'zdz_register_capabilities' => function ( $caps ) {
				foreach ( array( 'ZCH_Chat_Bridge', 'ZCH_Prospect_Verb', 'ZCH_Records_Verb' ) as $c ) {
					$d                  = call_user_func( array( $c, 'get_capability_descriptor' ) );
					$caps[ $d['verb'] ] = $d;
				}
				return $caps;
			},
		);
		ZCH_Access::flush();
		ZCH_Store::$settings = array( 'bot_records' => true );
		ZDZ_Capabilities::flush();
		$this->hub = new ZDZ_Test_Rec_Hub( array( $this, 'answer' ) );
		ZCH_Records_Verb::$client = $this->hub;
	}

	protected function tearDown(): void {
		ZCH_Records_Verb::$client = null;
		ZCH_Store::$settings      = null;
		ZCH_Config::$stored       = null;
		ZCH_Access::flush();
		ZDZ_Capabilities::flush();
		unset( $GLOBALS['zdz_test_user_roles'], $GLOBALS['zdz_test_user_meta'], $GLOBALS['zdz_test_user_caps'], $GLOBALS['zdz_test_transients'],
			$GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_current_user'], $GLOBALS['wpdb'], $GLOBALS['zdz_test_filters'] );
	}

	/** The hub's ordinary answers: two lines (one carrying fields no caller may have) and one job. */
	public function answer( $op, $body ) {
		if ( 'search_lines' === $op ) {
			return array(
				'ok'   => true,
				'data' => array(
					'method'        => ! empty( $body['whole_words'] ) ? 'whole_words' : 'keyword',
					'total_matches' => 2,
					'meta'          => array( 'data_as_of' => '2026-03-02' ),
					'lines'         => array(
						array(
							'text' => 'Sample bracket, painted to match the trim', 'family' => 'Shade sails', 'tags' => array( 'Rush' ), 'date' => '2025-06-02',
							'town' => 'Northtown', 'invoice_no' => '990101', 'dollars' => 185.0, 'customer_name' => 'HIDDEN CUSTOMER', 'invoice_url' => 'https://billing.example.com/x',
						),
						array( 'text' => 'Sample painted hooks (12)', 'family' => 'Shade sails', 'tags' => array(), 'date' => '2024-03-09', 'town' => 'Southport', 'invoice_no' => '990102', 'dollars' => 24.5 ),
					),
				),
			);
		}
		if ( 'list_jobs' === $op ) {
			return array(
				'ok'   => true,
				'data' => array(
					'total' => 1,
					'jobs'  => array(
						array( 'invoice_no' => '990101', 'date' => '2025-06-02', 'town' => 'Northtown', 'products' => array( 'Shade sails' ), 'amount' => 4200.0, 'salesperson' => 'JM', 'source' => 'Sample', 'partner' => '', 'customer' => 'HIDDEN CUSTOMER' ),
					),
				),
			);
		}
		return array( 'ok' => false, 'error' => array( 'code' => 'bad_request' ) );
	}

	private function ask( int $uid, array $args ): array {
		$GLOBALS['zdz_test_current_user'] = $uid;
		$this->hub->calls                 = array();
		return ZDZ_Capabilities::invoke( 'charts.records', $args );
	}

	/* ── the verb on the registry ───────────────────────────────────────────────────────────────────── */

	public function test_it_is_a_read_for_anyone_signed_in_and_never_the_shared_device(): void {
		$c = ZDZ_Capabilities::get( 'charts.records' );
		$this->assertNotNull( $c );
		$this->assertSame( 'viewer', $c['tier'] );
		$this->assertFalse( $c['side_effect'] );
		$this->assertFalse( $c['kiosk'] );
		$this->assertFalse( $c['kiosk_bounded'] );
		$this->assertFalse( $c['needs_app'], 'Charts\' own access decides, not the Charts tile' );
		$this->assertSame( 'charts', $c['app'] );
		$this->assertNotNull( ZDZ_Capabilities::get( 'charts.run' ) );
		$this->assertNotNull( ZDZ_Capabilities::get( 'charts.prospect' ) );
	}

	/* ── the business's switch ──────────────────────────────────────────────────────────────────────── */

	public function test_core_ships_the_records_switch_off(): void {
		ZCH_Store::$settings = array();
		$this->assertFalse( ZCH_Store::settings()['bot_records'] );
		$this->assertSame( array(), ZCH_Store::settings()['records_common'] );
		$this->assertFalse( ZCH_Records_Verb::shared() );
		foreach ( array( 'search_lines', 'list_jobs' ) as $op ) {
			$r = $this->ask( 1, array( 'op' => $op, 'text' => 'painted' ) );
			$this->assertFalse( $r['success'] );
			$this->assertTrue( $r['denied'] );
			$this->assertSame( 'bot_records', $r['reason'] );
			$this->assertStringContainsString( 'not shared with the assistant', $r['message'] );
			$this->assertSame( array(), $this->hub->calls, 'the hub is never asked' );
		}
		// the totals switch is a different decision: it opens nothing here
		ZCH_Store::$settings = array( 'bot_totals' => true );
		$this->assertSame( 'bot_records', $this->ask( 1, array( 'op' => 'search_lines', 'text' => 'painted' ) )['reason'] );
	}

	/* ── the owner ──────────────────────────────────────────────────────────────────────────────────── */

	public function test_the_owner_searches_lines(): void {
		$a = $this->ask( 1, array(
			'op'        => 'search_lines',
			'text'      => '  painted  ',
			'filters'   => array( 'salespeople' => array( 'JM' ), 'nonsense' => array( 'x' ), 'products' => 'Shade sails', 'accounts' => array( 'k1' ) ),
			'date_from' => '2019-01-01',
			'date_to'   => 'not a date',
			'limit'     => 500,
		) );
		$this->assertTrue( $a['success'], json_encode( $a ) );
		$this->assertSame( 'search_lines', $a['op'] );
		$this->assertCount( 2, $a['lines'] );
		$this->assertSame(
			array( 'text' => 'Sample bracket, painted to match the trim', 'date' => '2025-06-02', 'town' => 'Northtown', 'invoice_no' => '990101', 'family' => 'Shade sails', 'tags' => array( 'Rush' ), 'dollars' => 185.0 ),
			$a['lines'][0]
		);
		$flat = json_encode( $a );
		$this->assertStringNotContainsString( 'HIDDEN', $flat, 'no customer name, even when a hub row carried one' );
		$this->assertStringNotContainsString( 'billing.example.com', $flat, 'no link out' );
		$this->assertSame( 2, $a['total_matches'] );
		$this->assertSame( '2026-03-02', $a['data_as_of'] );
		$this->assertTrue( $a['money'] );
		$this->assertSame( '2 invoice lines matched.', $a['message'], 'a caller that shows only words has something true to show' );

		$this->assertCount( 1, $this->hub->calls );
		$call = $this->hub->calls[0];
		$this->assertSame( 'search_lines', $call['op'] );
		$this->assertSame( 'wp_1', $call['user'], 'asked as the signed-in person' );
		$this->assertSame( 'painted', $call['body']['text'] );
		$this->assertSame( array( 'salespeople' => array( 'JM' ), 'products' => array( 'Shade sails' ) ), $call['body']['filters'], 'known filters only, each a list' );
		$this->assertSame( '2019-01-01', $call['body']['date_from'] );
		$this->assertArrayNotHasKey( 'date_to', $call['body'], 'a bad date is dropped' );
		$this->assertSame( ZCH_Records_Verb::MAX_LINES, $call['body']['limit'], 'the limit is capped at the hub\'s own' );
		$this->assertArrayNotHasKey( 'whole_words', $call['body'], 'not sent unless asked' );

		$acc = $call['body']['access'];
		$this->assertSame( 'ui', $acc['caller'], 'the page\'s own block' );
		$this->assertFalse( $acc['accounts'], 'never named accounts' );
		$this->assertSame( 'none', $acc['invoices'], 'never invoice cards' );
		$this->assertSame( array(), array_values( array_diff( $acc['ops'], array( 'health', 'search_lines', 'list_jobs' ) ) ), 'the ops are cut to the two and health' );
		$this->assertContains( 'search_lines', $acc['ops'] );
		$this->assertNull( $acc['scope'], 'the owner sees every salesperson\'s lines' );
		$this->assertSame( 'all', $acc['line_items'] );

		$audit = $GLOBALS['wpdb']->rows;
		$this->assertCount( 1, $audit, 'every hub call leaves an audit line' );
		$this->assertSame( 'wp_zch_audit', $audit[0][0] );
		$this->assertSame( array( 'search_lines', 1, 2, 'chat', null ), array( $audit[0][1]['op'], $audit[0][1]['ok'], $audit[0][1]['rows_out'], $audit[0][1]['caller'], $audit[0][1]['question'] ), 'the op and a count, never the words searched' );
	}

	public function test_the_owner_lists_jobs(): void {
		$j = $this->ask( 1, array( 'op' => 'list_jobs', 'filters' => array( 'salespeople' => array( 'JM' ) ), 'date_from' => '2024-01-01', 'limit' => 9999 ) );
		$this->assertTrue( $j['success'], json_encode( $j ) );
		$this->assertSame(
			array( 'invoice_no' => '990101', 'date' => '2025-06-02', 'town' => 'Northtown', 'products' => array( 'Shade sails' ), 'amount' => 4200.0, 'salesperson' => 'JM' ),
			$j['jobs'][0],
			'known keys only: no source, no partner, no customer'
		);
		$this->assertSame( 1, $j['total'] );
		$this->assertSame( '1 job found.', $j['message'] );
		$call = $this->hub->calls[0];
		$this->assertSame( 'list_jobs', $call['op'] );
		$this->assertSame( array( 'salespeople' => array( 'JM' ) ), $call['body']['spec']['filters'] );
		$this->assertSame( '2024-01-01', $call['body']['spec']['date_from'] );
		$this->assertSame( ZCH_Records_Verb::MAX_JOBS, $call['body']['limit'] );
		$this->assertSame( 'none', $call['body']['access']['invoices'] );
	}

	/* ── who may not ────────────────────────────────────────────────────────────────────────────────── */

	public function test_the_shared_device_and_nobody_signed_in_are_refused(): void {
		$k = $this->ask( 5, array( 'op' => 'search_lines', 'text' => 'painted' ) );
		$this->assertTrue( $k['denied'] );
		$this->assertSame( 'kiosk', $k['reason'], 'by the registry' );
		$this->assertArrayNotHasKey( 'lines', $k );
		$this->assertSame( array(), $this->hub->calls );

		// the verb's own door, should a caller ever reach it another way: by the stamp, and by the account itself
		$kd = ZCH_Records_Verb::from_capability( array( 'op' => 'search_lines', 'text' => 'painted', 'user_id' => 1, 'is_kiosk' => true ) );
		$this->assertSame( array( false, true, 'kiosk' ), array( $kd['success'], $kd['denied'], $kd['reason'] ) );
		$kr = ZCH_Records_Verb::from_capability( array( 'op' => 'search_lines', 'text' => 'painted', 'user_id' => 5, 'is_kiosk' => false ) );
		$this->assertSame( 'kiosk', $kr['reason'] );

		$s = $this->ask( 0, array( 'op' => 'search_lines', 'text' => 'painted' ) );
		$this->assertTrue( $s['denied'] );
		$this->assertSame( 'signed_out', $s['reason'] );
		$so = ZCH_Records_Verb::from_capability( array( 'op' => 'search_lines', 'text' => 'painted' ) );
		$this->assertSame( 'signed_out', $so['reason'] );
		$this->assertSame( array(), $this->hub->calls );
	}

	public function test_only_the_people_the_page_lets_search_lines(): void {
		$t = $this->ask( 4, array( 'op' => 'search_lines', 'text' => 'painted' ) );
		$this->assertSame( array( false, 'charts_access' ), array( $t['success'], $t['reason'] ), 'a field role without Charts' );
		$this->assertSame( array(), $this->hub->calls );

		// a salesperson opens their own invoice cards on the page, and so lists their own jobs there; the chat needs line items
		$this->assertContains( 'list_jobs', ZCH_Access::block( 2, 'ui' )['ops'] );
		$this->assertNotContains( 'search_lines', ZCH_Access::block( 2, 'ui' )['ops'] );
		foreach ( array( 'list_jobs', 'search_lines' ) as $op ) {
			$r = $this->ask( 2, array( 'op' => $op, 'text' => 'painted' ) );
			$this->assertFalse( $r['success'] );
			$this->assertSame( 'line_items', $r['reason'], $op );
			$this->assertSame( array(), $this->hub->calls, 'the hub is never asked' );
		}
		$this->assertNull( ZCH_Records_Verb::block_for( 2, 'list_jobs' ) );
		$this->assertNull( ZCH_Records_Verb::block_for( 4, 'search_lines' ) );
		$this->assertNull( ZCH_Records_Verb::block_for( 0, 'search_lines' ) );
	}

	public function test_a_person_kept_to_their_own_jobs_keeps_that_scope(): void {
		$r = $this->ask( 6, array( 'op' => 'search_lines', 'text' => 'painted', 'filters' => array( 'salespeople' => array( 'JM' ) ) ) );
		$this->assertTrue( $r['success'], json_encode( $r ) );
		$acc = $this->hub->calls[0]['body']['access'];
		$this->assertSame( array( 'CQ' ), $acc['scope'], 'the hub keeps the search to their own lines, whoever the filter names' );
		$this->assertSame( 'own', $acc['line_items'] );
		$this->assertSame( 'CQ', $acc['me'] );
		$this->assertFalse( $acc['by_person'] );
		$this->assertSame( 'wp_6', $this->hub->calls[0]['user'] );
		$j = $this->ask( 6, array( 'op' => 'list_jobs' ) );
		$this->assertSame( array( 'CQ' ), $this->hub->calls[0]['body']['access']['scope'] );
		$this->assertSame( 'JM', $j['jobs'][0]['salesperson'], 'a scoped person\'s rows say whose job each is (the hub sends only their own)' );
	}

	public function test_the_caller_cannot_name_who_is_asking_or_another_op(): void {
		$x = $this->ask( 1, array( 'op' => 'search_lines', 'text' => 'painted', 'user_id' => 2, 'requesting_user_id' => 2, 'is_kiosk' => true, 'tier' => 'admin' ) );
		$this->assertTrue( $x['success'] );
		$this->assertSame( 'wp_1', $this->hub->calls[0]['user'] );
		foreach ( array( 'drill', 'invoice', 'data_status', '', array( 'search_lines' ) ) as $op ) {
			$b = $this->ask( 1, array( 'op' => $op, 'text' => 'painted' ) );
			$this->assertSame( array( false, 'bad_request' ), array( $b['success'], $b['error'] ?? '' ) );
			$this->assertSame( array(), $this->hub->calls );
		}
		foreach ( array( '   ', '', array( 'painted' ), null ) as $text ) {
			$e = $this->ask( 1, array( 'op' => 'search_lines', 'text' => $text ) );
			$this->assertSame( array( false, 'bad_request' ), array( $e['success'], $e['error'] ?? '' ), 'a search with no words' );
			$this->assertSame( array(), $this->hub->calls );
		}
	}

	/* ── what comes back ────────────────────────────────────────────────────────────────────────────── */

	public function test_dollars_only_for_people_with_money(): void {
		$GLOBALS['zdz_test_user_meta'][1]['zch_permissions'] = array( 'charts_money' => 'deny' );
		ZCH_Access::flush();
		$a = $this->ask( 1, array( 'op' => 'search_lines', 'text' => 'painted' ) );
		$this->assertFalse( $a['money'] );
		$this->assertNull( $a['lines'][0]['dollars'], 'whatever the hub sent' );
		$j = $this->ask( 1, array( 'op' => 'list_jobs' ) );
		$this->assertNull( $j['jobs'][0]['amount'] );
		$this->assertFalse( $this->hub->calls[0]['body']['access']['money'] );
	}

	public function test_rows_are_bounded_and_odd_values_are_dropped(): void {
		$this->hub->answer = array(
			'ok'   => true,
			'data' => array(
				'total_matches' => 'many',
				'lines'         => array(
					'not a row',
					array( 'text' => array( 'x' ), 'date' => '2025-13-99x', 'town' => str_repeat( 't', 90 ), 'invoice_no' => 990103, 'family' => null, 'tags' => array( 'a', array( 'b' ), '', 7 ), 'dollars' => 'abc' ),
					array( 'text' => str_repeat( 'word ', 200 ) ),
				),
			),
		);
		$a = $this->ask( 1, array( 'op' => 'search_lines', 'text' => 'painted' ) );
		$this->assertCount( 2, $a['lines'] );
		$this->assertSame( array( 'text' => '', 'date' => null, 'town' => str_repeat( 't', 60 ), 'invoice_no' => '990103', 'family' => '', 'tags' => array( 'a', '7' ), 'dollars' => null ), $a['lines'][0] );
		$this->assertSame( ZCH_Records_Verb::MAX_TEXT, mb_strlen( $a['lines'][1]['text'] ) );
		$this->assertSame( 2, $a['total_matches'], 'a count that is not a number is the number of lines read' );
		$this->assertSame( '', $a['data_as_of'] );
	}

	public function test_contact_details_in_line_text_are_replaced(): void {
		$this->hub->answer = array( 'ok' => true, 'data' => array( 'total_matches' => 1, 'lines' => array(
			array( 'text' => 'Sample: call (555) 555-0142 or alex@example.com before 1234 Sample Test Ave, 12 ft x 3 ft painted', 'date' => '2025-01-02', 'town' => 'Southport', 'invoice_no' => '990103', 'dollars' => 10.0 ),
		) ) );
		$r  = $this->ask( 1, array( 'op' => 'search_lines', 'text' => 'painted' ) );
		$tx = $r['lines'][0]['text'];
		$this->assertSame( 'Sample: call [phone] or [email] before [address], 12 ft x 3 ft painted', $tx );
	}

	public function test_redact_keeps_sizes_and_money_and_takes_out_the_rest(): void {
		$keep = 'Item 12x30 $222.00, 4 pieces 2x4, part 100-2345-77, 3 Plaza items';
		$this->assertSame( $keep, ZCH_Records_Verb::redact( $keep ), 'money, sizes and part numbers are not phone numbers or addresses' );
		$this->assertSame( 'ring [phone]', ZCH_Records_Verb::redact( 'ring 555.555.0142' ) );
		$this->assertSame( 'ring [phone]', ZCH_Records_Verb::redact( 'ring 1-555-555-0142' ) );
		$this->assertSame( 'ring [phone] today', ZCH_Records_Verb::redact( 'ring +44 20 5555 0142 today' ), 'a number written with its country code' );
		$this->assertSame( 'at [address] gate', ZCH_Records_Verb::redact( 'at 48 N. Sample Hill Road gate' ) );
		$this->assertSame( 'at [address], rear', ZCH_Records_Verb::redact( 'at 120 Élan Court, rear' ), 'a street name with an accent' );
		$this->assertSame( 'at [address]', ZCH_Records_Verb::redact( 'at 1450 Via Sample Verde' ), 'the street word first, from the platform\'s list' );
		$this->assertSame( 'mail [email]', ZCH_Records_Verb::redact( 'mail JORDAN.Sample+jobs@Example.co.uk' ) );
		$this->assertSame( '', ZCH_Records_Verb::redact( '' ) );
		$this->assertStringNotContainsString( '555', ZCH_Records_Verb::redact( "bad \xC3\x28 bytes 555-555-0142" ), 'bytes that are not UTF-8 never stop the check' );

		// a region adds its own street endings, and may take more out of the text; it is never handed the original
		$GLOBALS['zdz_test_filters']['zch_records_street_words'] = function ( $w ) {
			return array_merge( $w, array( 'Gasse', 'bad|word', 12 ) );
		};
		$this->assertSame( 'at [address]', ZCH_Records_Verb::redact( 'at 12 Alte Sample Gasse' ) );
		$this->assertNotContains( 'bad|word', ZCH_Records_Verb::street_words(), 'letters only: a word never changes the pattern' );
		unset( $GLOBALS['zdz_test_filters']['zch_records_street_words'] );

		// a region's street endings from its Identity Pack (knowledge.yml addresses.street_end_words)
		$this->assertSame( 'at 7 Sample Close', ZCH_Records_Verb::redact( 'at 7 Sample Close' ), 'not a Core ending' );
		$GLOBALS['zdz_test_options'][ ZDZ_Sealed::STREET_END_OPT ] = array( 'Close', 'Crescent' );
		$this->assertSame( 'at [address], back gate', ZCH_Records_Verb::redact( 'at 7 Sample Close, back gate' ) );
		$this->assertSame( 'at [address]', ZCH_Records_Verb::redact( 'at 14 Sample Crescent' ) );
		unset( $GLOBALS['zdz_test_options'][ ZDZ_Sealed::STREET_END_OPT ] );
		$GLOBALS['zdz_test_filters']['zch_records_line_text'] = function ( $t ) {
			return str_replace( 'unit 7', '[unit]', $t );
		};
		$this->assertSame( 'key for [unit], ring [phone]', ZCH_Records_Verb::redact( 'key for unit 7, ring 555-555-0142' ) );
	}

	/* ── whole words ────────────────────────────────────────────────────────────────────────────────── */

	public function test_whole_words_only_when_the_caller_asks_and_the_method_is_said_back(): void {
		$this->hub->answer = function ( $op, $body ) {
			return array( 'ok' => true, 'data' => array( 'total_matches' => 0, 'lines' => array(), 'method' => ! empty( $body['whole_words'] ) ? 'whole_words' : 'keyword' ) );
		};
		$w = $this->ask( 1, array( 'op' => 'search_lines', 'text' => 'floor stand', 'whole_words' => true ) );
		$this->assertTrue( $this->hub->calls[0]['body']['whole_words'] );
		$this->assertSame( 'whole_words', $w['method'] );
		$this->assertSame( '0 invoice lines matched.', $w['message'] );
		$k = $this->ask( 1, array( 'op' => 'search_lines', 'text' => 'floor stand' ) );
		$this->assertArrayNotHasKey( 'whole_words', $this->hub->calls[0]['body'], 'the page\'s kind of search is unchanged' );
		$this->assertSame( 'keyword', $k['method'] );
		// a hub that does not know the switch says nothing, or says something else: the loose search
		foreach ( array( array(), array( 'method' => 'fuzzy' ), array( 'method' => array( 'whole_words' ) ) ) as $more ) {
			$this->hub->answer = array( 'ok' => true, 'data' => array( 'total_matches' => 0, 'lines' => array() ) + $more );
			$o                 = $this->ask( 1, array( 'op' => 'search_lines', 'text' => 'floor stand', 'whole_words' => true ) );
			$this->assertSame( 'keyword', $o['method'] );
		}
		$this->ask( 1, array( 'op' => 'list_jobs', 'whole_words' => true ) );
		$this->assertArrayNotHasKey( 'whole_words', $this->hub->calls[0]['body'], 'a job list has no words' );
	}

	/* ── no hub, and a hub that fails ───────────────────────────────────────────────────────────────── */

	public function test_no_hub_is_local_mode_in_words(): void {
		ZCH_Records_Verb::$client = false;
		$r                        = $this->ask( 1, array( 'op' => 'search_lines', 'text' => 'painted' ) );
		$this->assertSame( array( false, 'hub_unavailable' ), array( $r['success'], $r['error'] ) );
		$this->assertStringContainsString( 'local mode', $r['message'] );
		$this->assertStringContainsString( 'no Charts hub is connected', $r['message'] );
		$this->assertArrayNotHasKey( 'lines', $r, 'never an empty success' );
		$this->assertSame( array(), $GLOBALS['wpdb']->rows, 'no hub call, no audit line' );

		// with nothing injected the verb reads the site's own configuration: none here
		if ( ! defined( 'ZCH_HUB_URL' ) ) {
			ZCH_Records_Verb::$client = null;
			ZCH_Config::$stored       = array();
			$n                        = $this->ask( 1, array( 'op' => 'list_jobs' ) );
			$this->assertSame( 'hub_unavailable', $n['error'] );
			$this->assertStringContainsString( 'local mode', $n['message'] );
		}
	}

	public function test_a_failed_read_is_an_error_in_words_and_the_hub_gets_five_minutes(): void {
		$this->hub->answer = array( 'ok' => false, 'error' => array( 'code' => 'hub_unavailable' ) );
		$h                 = $this->ask( 1, array( 'op' => 'search_lines', 'text' => 'painted' ) );
		$this->assertSame( array( false, 'hub_unavailable' ), array( $h['success'], $h['error'] ) );
		$this->assertGreaterThan( 0, (int) get_option( 'zch_last_fail', 0 ), 'the back-off is set' );

		$this->hub->answer = array( $this, 'answer' );
		$h2                = $this->ask( 1, array( 'op' => 'search_lines', 'text' => 'painted' ) );
		$this->assertSame( 'hub_unavailable', $h2['error'] );
		$this->assertStringContainsString( 'a moment ago', $h2['message'] );
		$this->assertSame( array(), $this->hub->calls, 'within five minutes the hub is not asked again' );

		$GLOBALS['zdz_test_options']['zch_last_fail'] = time() - ZCH_Records_Verb::BACKOFF_S - 1;
		$h3 = $this->ask( 1, array( 'op' => 'search_lines', 'text' => 'painted' ) );
		$this->assertTrue( $h3['success'] );
		$this->assertFalse( get_option( 'zch_last_fail', false ), 'an answer clears the back-off' );

		foreach ( array( array( 'status' => 'x' ), array( 'lines' => 'none' ), null ) as $data ) {
			$this->hub->answer = array( 'ok' => true, 'data' => $data );
			$e                 = $this->ask( 1, array( 'op' => 'search_lines', 'text' => 'painted' ) );
			$this->assertSame( array( false, 'hub_error' ), array( $e['success'], $e['error'] ), 'an answer without a list of lines' );
			$j = $this->ask( 1, array( 'op' => 'list_jobs' ) );
			$this->assertSame( array( false, 'hub_error' ), array( $j['success'], $j['error'] ), 'or of jobs' );
		}
		$this->hub->answer = 'not an answer';
		$this->assertSame( 'hub_error', $this->ask( 1, array( 'op' => 'search_lines', 'text' => 'painted' ) )['error'] );
		$this->hub->answer = array( 'ok' => false, 'error' => array( 'code' => 'No Data!<b>' ) );
		$odd               = $this->ask( 1, array( 'op' => 'search_lines', 'text' => 'painted' ) );
		$this->assertSame( 'Charts did not answer (nodatab).', $odd['message'], 'a code is shown as letters only' );
	}

	public function test_the_hubs_own_refusal_is_a_refusal_for_the_person(): void {
		$this->hub->answer = array( 'ok' => false, 'error' => array( 'code' => 'forbidden' ) );
		$r                 = $this->ask( 1, array( 'op' => 'search_lines', 'text' => 'painted' ) );
		$this->assertSame( array( false, true, 'line_items' ), array( $r['success'], $r['denied'], $r['reason'] ) );
		$this->assertFalse( get_option( 'zch_last_fail', false ), 'a refusal is not an outage' );
	}

	public function test_charts_per_minute_budget_applies(): void {
		ZCH_Store::$settings = array( 'bot_records' => true, 'charts_per_minute' => 2 );
		$this->assertTrue( $this->ask( 1, array( 'op' => 'search_lines', 'text' => 'painted' ) )['success'] );
		$this->assertTrue( $this->ask( 1, array( 'op' => 'list_jobs' ) )['success'] );
		$r = $this->ask( 1, array( 'op' => 'search_lines', 'text' => 'painted' ) );
		$this->assertSame( array( false, 'rate_limited' ), array( $r['success'], $r['error'] ) );
		$this->assertSame( array(), $this->hub->calls );
	}

	/* ── the business's own words ───────────────────────────────────────────────────────────────────── */

	public function test_the_common_words_are_the_business_s_own_and_core_ships_none(): void {
		ZCH_Store::$settings = array();
		$this->assertSame( array(), ZCH_Records_Verb::common_words() );
		ZCH_Store::$settings = array( 'records_common' => array( ' Panel ', 'panel', 'x', 'drop;table', 'Ünit', str_repeat( 'a', 60 ) ) );
		$this->assertSame( array( 'panel', 'ünit' ), ZCH_Records_Verb::common_words() );
		$this->assertSame( array( 'panel', 'white trim', 'post-cap' ), ZCH_Records_Verb::clean_words( "Panel\nwhite   trim, post-cap\n\n<b>" ) );
		$this->assertCount( 80, ZCH_Records_Verb::clean_words( array_map( function ( $i ) { return 'word' . $i; }, range( 1, 200 ) ) ) );
	}
}
