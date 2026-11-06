<?php
/**
 * The home screen's server side: the launcher fields every app gets, and the home tiles' deciders.
 *
 *   - ZDZ_Plugin_API::short_name_for(): an app's own short name wins, then the platform's list, then the filter; text
 *     only, at most SHORT_NAME_MAX characters, else none. launcher_fields() puts the group's label, colour and ink on
 *     the config and keeps the app's own colour as cc_plugin; an app with no entry is grouped from its category.
 *   - ZDZ_KPI_Metrics::set_for(): a tile set per role; the shared device and unknown roles get none (deny by default).
 *   - build(): a role with a set gets only its own tiles; a tile whose app has no count says "does not answer here
 *     yet", never 0; a shop with no configured items draws Prep's queue alone, and the item tiles follow its list.
 *   - Money never reaches a revenue-denied person, even in a tile added later (is_financial_metric() on tiles).
 *   - clean_shop_items(): bad entries are dropped, never guessed; at most three.
 *   - stale_after(): a business may change an interval, never below a minute.
 *   - ZDZ_Orchestrator::quick_hint_pattern(): the business's trade words become one pattern of plain words only;
 *     anything that could act as a pattern is dropped, never escaped into one; none listed, no pattern.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/notify-media-stubs.php';
if ( ! defined( 'ZDZ_REST_NS' ) ) {
	define( 'ZDZ_REST_NS', 'zorderz/v1' );
}
if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $uid, $key, $value ) { $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] = $value; return true; }
}
if ( ! function_exists( 'error_log' ) ) {
	function error_log( $m ) { return true; }
}
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-app-groups.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-plugin-api.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-orchestrator.php';

final class ThemeHomeTilesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['zdz_test_user_roles'] = array(
			1 => array( 'zdz_owner' ), 2 => array( 'zdz_sales' ), 3 => array( 'zdz_general' ), 4 => array( 'zdz_mfg' ),
			5 => array( 'zdz_operator' ), 6 => array( 'zdz_tech' ), 7 => array( 'subscriber' ), 8 => array(),
		);
		ZDZ_KPI_Metrics::$now = gmmktime( 15, 0, 0, 6, 10, 2026 );
		if ( class_exists( 'ZDZ_Capabilities' ) ) {
			ZDZ_Capabilities::flush();
		}
	}

	protected function tearDown(): void {
		zdz_notify_test_reset();
		ZDZ_KPI_Metrics::$now = null;
		unset( $GLOBALS['zdz_test_user_meta'] );
	}

	/* ── Short names and groups ──────────────────────────────────────────── */

	public function test_short_name_order_and_limits(): void {
		$this->assertSame( 'Routes', ZDZ_Plugin_API::short_name_for( 'route-planner', array( 'nm' => 'Route Planner' ) ), "the platform's list" );
		$this->assertSame( 'Trips', ZDZ_Plugin_API::short_name_for( 'route-planner', array( 'short_name' => 'Trips' ) ), "the app's own wins" );
		$this->assertSame( 'Trips', ZDZ_Plugin_API::short_name_for( 'x-app', array( 'snm' => 'Trips' ) ), 'the compact key' );
		$this->assertSame( '', ZDZ_Plugin_API::short_name_for( 'x-app', array( 'short_name' => 'Far too long a name' ) ), 'over the limit: none' );
		$this->assertSame( '', ZDZ_Plugin_API::short_name_for( 'x-app', array( 'short_name' => array( 'x' ) ) ), 'not text: none' );
		$this->assertSame( '', ZDZ_Plugin_API::short_name_for( 'x-app', array( 'short_name' => '&lt;b&gt;' ) ), 'an encoded tag is decoded and stripped: never a tag' );
		$this->assertSame( 'A & B', ZDZ_Plugin_API::short_name_for( 'x-app', array( 'short_name' => 'A &amp; B' ) ), 'entities read as text' );
		$this->assertSame( 'Ab C', ZDZ_Plugin_API::short_name_for( 'x-app', array( 'short_name' => "  Ab \n  C " ) ), 'spaces squeezed' );
		$GLOBALS['zdz_test_filters']['zdz_app_short_name'] = function ( $short, $id ) { return 'team-roles' === $id ? 'Crew' : $short; };
		$this->assertSame( 'Crew', ZDZ_Plugin_API::short_name_for( 'team-roles', array() ), 'the filter has the last word' );
		$GLOBALS['zdz_test_filters']['zdz_app_short_name'] = function () { return 'Much too long here'; };
		$this->assertSame( '', ZDZ_Plugin_API::short_name_for( 'team-roles', array() ), "and the filter's answer is held to the same limit" );
	}

	public function test_launcher_fields_group_and_short_name(): void {
		$c = ZDZ_Plugin_API::launcher_fields( array( 'nm' => 'Route Planner', 'cc' => '#123456', 'cat' => 'field', 'short_name' => '' ), 'route-planner' );
		$this->assertSame( 'Routes', $c['snm'] );
		$this->assertArrayNotHasKey( 'short_name', $c );
		$this->assertSame( '#123456', $c['cc_plugin'], "the app's own colour is kept" );
		$this->assertSame( 'field', $c['grp'] );
		$this->assertSame( ZDZ_App_Groups::GROUPS['field']['color'], $c['cc'], 'the group decides the colour' );
		$this->assertSame( ZDZ_App_Groups::GROUPS['field']['ink'], $c['ink'] );

		$same = ZDZ_Plugin_API::launcher_fields( array( 'nm' => 'Hours', 'snm' => 'Hours' ), 'hours' );
		$this->assertArrayNotHasKey( 'snm', $same, 'a short name equal to the name is not sent' );

		$x = ZDZ_Plugin_API::launcher_fields( array( 'nm' => 'Example', 'cat' => 'finance' ), 'example-app' );
		$this->assertSame( 'office', $x['grp'], 'an app with no entry is grouped from its category' );
		$y = ZDZ_Plugin_API::launcher_fields( array( 'nm' => 'Example' ), 'another-app' );
		$this->assertSame( 'tools', $y['grp'], 'and with no category, Tools: never off the grid' );
		$this->assertNotSame( ZDZ_App_Groups::NEEDS_YOU, $y['cc'], 'the "needs you" colour is never a group colour' );
	}

	/* ── Tile sets ───────────────────────────────────────────────────────── */

	public function test_set_per_role_deny_by_default(): void {
		$this->assertSame( 'company', ZDZ_KPI_Metrics::set_for( 1 ) );
		$this->assertSame( 'sales', ZDZ_KPI_Metrics::set_for( 2 ) );
		$this->assertSame( '', ZDZ_KPI_Metrics::set_for( 3 ), 'the shared device gets none' );
		$this->assertSame( 'shop', ZDZ_KPI_Metrics::set_for( 4 ) );
		$this->assertSame( 'operator', ZDZ_KPI_Metrics::set_for( 5 ) );
		$this->assertSame( 'tech', ZDZ_KPI_Metrics::set_for( 6 ) );
		$this->assertSame( '', ZDZ_KPI_Metrics::set_for( 7 ), 'an unknown role gets none' );
		$this->assertSame( '', ZDZ_KPI_Metrics::set_for( 8 ), 'no role: none' );
		$this->assertSame( '', ZDZ_KPI_Metrics::set_for( 0 ), 'signed out: none' );
	}

	public function test_a_refused_role_gets_no_tiles(): void {
		$out = ZDZ_KPI_Metrics::build( 3 );
		$this->assertFalse( $out['success'] );
		$this->assertSame( array(), $out['tiles'] );
	}

	public function test_sales_set_is_counts_only_and_says_when_an_app_does_not_answer(): void {
		$out = ZDZ_KPI_Metrics::build( 2 );
		$this->assertSame( 'sales', $out['set'] );
		$this->assertSame( array( 'my_estimates', 'my_invoices', 'my_leads', 'my_installed' ), array_keys( $out['tiles'] ) );
		foreach ( $out['tiles'] as $k => $t ) {
			$this->assertSame( 'unavailable', $t['state'], "$k: no app answers it here" );
			$this->assertNull( $t['value'], "$k: never 0" );
			$this->assertStringContainsString( 'does not answer this here yet', $t['message'] );
			$this->assertFalse( ZDZ_KPI_Metrics::is_financial_metric( $k, $t ), "$k carries no money" );
		}
	}

	public function test_company_revenue_is_not_shown_without_the_right(): void {
		$out = ZDZ_KPI_Metrics::build( 1 );   // no ZDZ_Data_Permissions loaded: fails closed
		$this->assertSame( 'not_shown', $out['tiles']['ytd_revenue']['state'] );
		$this->assertSame( 'not_shown', $out['tiles']['mtd_revenue']['state'] );
		$this->assertSame( 'held', $out['tiles']['leads_contacted']['state'], 'Contacted: no number is sent' );
		$this->assertNull( $out['tiles']['leads_contacted']['value'] );
		$this->assertSame( 'empty', $out['tiles']['google_reviews']['state'], 'nothing typed yet' );
	}

	public function test_shop_draws_prep_alone_until_the_business_names_its_items(): void {
		$out = ZDZ_KPI_Metrics::build( 4 );
		$this->assertSame( array( 'shop_prep' ), $out['order'] );
		$this->assertSame( array( 'shop_prep' ), array_keys( $out['tiles'] ) );

		$GLOBALS['zdz_test_options'][ ZDZ_KPI_Metrics::SHOP_ITEMS_OPTION ] = array(
			array( 'label' => 'Widgets this month', 'counts' => array( 'widget_new', 'widget_fixed' ), 'words' => array( 'New', 'Fixed' ) ),
			array( 'label' => 'Add-ons', 'counts' => array( 'addon' ) ),
		);
		$out = ZDZ_KPI_Metrics::build( 4 );
		$this->assertSame( array( 'shop_prep', 'shop_item_1', 'shop_item_2' ), $out['order'] );
		$this->assertSame( 'Widgets this month', $out['tiles']['shop_item_1']['label'] );
		$this->assertSame( 'unavailable', $out['tiles']['shop_item_1']['state'], 'the Commission app does not answer here: said, never 0' );
	}

	public function test_shop_items_are_cleaned_never_guessed(): void {
		$clean = ZDZ_KPI_Metrics::clean_shop_items( array(
			array( 'label' => '', 'counts' => array( 'a' ) ),                                  // no label
			array( 'label' => 'Bad key', 'counts' => array( 'Bad-Key' ) ),                     // not a count key
			array( 'label' => 'Three', 'counts' => array( 'a', 'b', 'c' ) ),                   // more than two counts
			'not an entry',
			array( 'label' => '<b>Doors</b>', 'counts' => array( 'door_new', 'door_fixed' ), 'words' => array( 'New', 'Fixed' ) ),
			array( 'label' => 'One', 'counts' => array( 'one' ) ),
			array( 'label' => 'Two', 'counts' => array( 'two' ) ),
			array( 'label' => 'Four', 'counts' => array( 'four' ) ),                           // a fourth: dropped
		) );
		$this->assertSame( array( 'Doors', 'One', 'Two' ), array_column( $clean, 'label' ) );
		$this->assertSame( 'new/fixed', $clean[0]['detail'], 'two counts: their words' );
		$this->assertSame( array( 'one' ), $clean[1]['words'], 'no words: the key' );
	}

	public function test_money_is_withheld_from_any_tile(): void {
		$kpi = ZDZ_KPI_Metrics::get_instance();
		$m   = new ReflectionMethod( 'ZDZ_KPI_Metrics', 'filter_financial_for_user' );
		$m->setAccessible( true );
		$tiles = array(
			'mtd_revenue' => array( 'label' => 'Revenue this month', 'state' => 'ok', 'value' => 1200.0, 'display' => '$1.2K', 'parts' => array() ),
			'a_new_tile'  => array( 'label' => 'Something new', 'state' => 'ok', 'value' => 5, 'display' => '5', 'parts' => array( array( 'label' => 'Owed', 'display' => '€40.00' ) ) ),
			'collected_x' => array( 'label' => 'X', 'state' => 'ok', 'value' => 3, 'display' => '3', 'collected' => 10.0 ),
			'my_leads'    => array( 'label' => 'Leads to contact', 'state' => 'ok', 'value' => 4, 'display' => '4', 'parts' => array() ),
		);
		$out = $m->invoke( $kpi, $tiles, 2 );   // no permission layer loaded: revenue denied
		$this->assertSame( 'not_shown', $out['mtd_revenue']['state'] );
		$this->assertNull( $out['mtd_revenue']['value'] );
		$this->assertSame( 'not_shown', $out['a_new_tile']['state'], 'a sheet row in a currency is money' );
		$this->assertSame( 'not_shown', $out['collected_x']['state'], 'a collected amount is money' );
		$this->assertSame( 4, $out['my_leads']['value'], 'a count stays' );
		$this->assertStringNotContainsString( '$', json_encode( $out ) );
		$this->assertStringNotContainsString( '€', json_encode( $out, JSON_UNESCAPED_UNICODE ) );
	}

	public function test_stale_intervals_may_change_but_not_below_a_minute(): void {
		$this->assertSame( 7200, ZDZ_KPI_Metrics::stale_after( 'ledger' ) );
		$GLOBALS['zdz_test_filters']['zdz_kpi_stale_after'] = function ( $v, $kind ) { return 'calls' === $kind ? 3 * 86400 : 5; };
		$this->assertSame( 3 * 86400, ZDZ_KPI_Metrics::stale_after( 'calls' ) );
		$this->assertSame( 7200, ZDZ_KPI_Metrics::stale_after( 'ledger' ), 'five seconds is refused: the default stays' );
	}

	public function test_hours_and_minutes(): void {
		$this->assertSame( '0h', ZDZ_KPI_Metrics::hm( 0 ) );
		$this->assertSame( '45m', ZDZ_KPI_Metrics::hm( 45 ) );
		$this->assertSame( '31h 20m', ZDZ_KPI_Metrics::hm( 1880 ) );
		$this->assertSame( '2h', ZDZ_KPI_Metrics::hm( 120 ) );
		$this->assertSame( '0h', ZDZ_KPI_Metrics::hm( -5 ) );
	}

	public function test_tech_order_keeps_usable_apps_first_and_pool_order_without_history(): void {
		$this->assertSame( array( 'tech_jobs', 'tech_schedule', 'tech_media' ), ZDZ_KPI_Metrics::tech_order( 6 ), 'no history, no counts yet: the starting order' );
		$out = ZDZ_KPI_Metrics::build( 6 );
		$this->assertSame( 'tech_hours', $out['order'][0], 'My hours first' );
		$this->assertCount( 4, $out['tiles'] );
		$this->assertSame( '2026-06-10', $out['day'] );
	}
	/* ── The ask bar's trade words ───────────────────────────────────────── */

	public function test_quick_hint_pattern_is_plain_words_only(): void {
		unset( $GLOBALS['zdz_test_filters']['zdz_ask_quick_words'] );
		$this->assertSame( '', ZDZ_Orchestrator::quick_hint_pattern(), 'Core lists no words' );

		$GLOBALS['zdz_test_filters']['zdz_ask_quick_words'] = function () {
			return array( 'Panel', 'panels', 'roof  tile', 'tile', 'x', '(a|b)+', '.*', 'end$', array( 'nested' ), 7, 'Panel', 'café' );
		};
		$p = ZDZ_Orchestrator::quick_hint_pattern();
		unset( $GLOBALS['zdz_test_filters']['zdz_ask_quick_words'] );
		$this->assertSame( '(?:^|[\\s"\'(,.;:!?])(roof tile|panels|panel|tile|café)(?=$|[\\s"\'),.;:!?])', $p, 'plain words, lower case, once each, longest first' );

		$re = '/' . $p . '/iu';
		$this->assertSame( 1, preg_match( $re, 'how many panels did we sell?' ) );
		$this->assertSame( 1, preg_match( $re, 'roof tile count this month' ) );
		$this->assertSame( 1, preg_match( $re, 'who sold the café order' ), 'a word ending in a non-ASCII letter still matches' );
		$this->assertSame( 0, preg_match( $re, 'tiles on order' ), 'only the forms listed' );
		$this->assertSame( 0, preg_match( $re, 'paneling' ), 'whole words only' );

		$GLOBALS['zdz_test_filters']['zdz_ask_quick_words'] = function () {
			return array_map( function ( $i ) { return 'word' . $i; }, range( 1, 80 ) );
		};
		$many = ZDZ_Orchestrator::quick_hint_pattern();
		unset( $GLOBALS['zdz_test_filters']['zdz_ask_quick_words'] );
		$this->assertSame( ZDZ_Orchestrator::QUICK_WORDS_MAX, substr_count( $many, 'word' ), 'at most the cap' );

		$GLOBALS['zdz_test_filters']['zdz_ask_quick_words'] = function () { return 'panel'; };
		$this->assertSame( '', ZDZ_Orchestrator::quick_hint_pattern(), 'not a list: none' );
		unset( $GLOBALS['zdz_test_filters']['zdz_ask_quick_words'] );
	}
}
