<?php
/**
 * The home screen's tiles and the apps that answer them.
 *
 *   - Every verb a tile asks is one an app registers, as a read (side_effect false), except the ones answered by
 *     parts not on every install (the Analytics ledger, the Jobs app's own step list): those tiles say the app does
 *     not answer here yet, never 0. The tech's Messages tile asks the verb Messages registers.
 *   - A business may choose its tiles: only from a set's own Core tiles, in its own order, never another role's;
 *     a filter cannot widen it. The answer's `order` is what the page draws.
 *   - rules.yml home_tiles: sets and shop items, refused whole when a set names a tile not its own or an item the
 *     catalog does not have.
 *   - items.sold_counts: counts only, the shared device refused, a failed read an error and a count not made yet
 *     "being counted", never 0. The installed tile shows a "marked" part only when its owner sends one.
 *
 * Neutral data only.
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
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'error_log' ) ) {
	function error_log( $m ) { return true; }
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' ); }
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $k ) { return $GLOBALS['zdz_test_transients'][ $k ] ?? false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['zdz_test_transients'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $k ) { unset( $GLOBALS['zdz_test_transients'][ $k ] ); return true; }
}
$zdz_kwa_root = dirname( __DIR__, 2 );
require_once $zdz_kwa_root . '/zorderz/inc/class-zdz-identity-pack.php';
require_once $zdz_kwa_root . '/zorderz/inc/class-zdz-item-engine.php';
require_once $zdz_kwa_root . '/zorderz-apps/apps/messaging/includes/class-zim-unread-counts.php';
require_once $zdz_kwa_root . '/zorderz-apps/apps/commission/includes/class-zcc-items-sold.php';

final class KWireHomeTilesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['zdz_test_user_roles'] = array(
			1 => array( 'zdz_owner' ), 2 => array( 'zdz_sales' ), 3 => array( 'zdz_general' ), 4 => array( 'zdz_mfg' ),
			5 => array( 'zdz_operator' ), 6 => array( 'zdz_tech' ),
		);
		$GLOBALS['zdz_test_options']    = array();
		$GLOBALS['zdz_test_transients'] = array();
		ZDZ_KPI_Metrics::$now           = gmmktime( 15, 0, 0, 6, 10, 2026 );
		ZCC_Items_Sold::$now            = gmmktime( 15, 0, 0, 6, 10, 2026 );
		if ( class_exists( 'ZDZ_Capabilities' ) ) {
			ZDZ_Capabilities::flush();
		}
	}

	protected function tearDown(): void {
		zdz_notify_test_reset();
		ZDZ_KPI_Metrics::$now        = null;
		ZCC_Items_Sold::$now         = null;
		ZCC_Items_Sold::$invoices    = null;
		ZDZ_Item_Engine::flush();
		unset( $GLOBALS['zdz_test_user_meta'], $GLOBALS['zdz_test_transients'], $GLOBALS['zdz_test_filters']['zdz_kpi_tile_sets'] );
		$GLOBALS['zdz_test_options'] = array();
	}

	private static function catalog( array $ids ): void {
		$p = new ReflectionProperty( 'ZDZ_Item_Engine', 'items_cache' );
		$p->setAccessible( true );
		$items = array();
		foreach ( $ids as $id ) {
			$items[ $id ] = array( 'id' => $id, 'active' => true );
		}
		$p->setValue( null, $items );
	}

	/* ── Every tile asks a verb an app registers ─────────────────────────── */

	public function test_every_tile_verb_but_the_ledger_is_registered_by_an_app_as_a_read(): void {
		$apps  = dirname( __DIR__, 2 ) . '/zorderz-apps/apps';
		$files = array();
		$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $apps, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( 'php' === $f->getExtension() && false === strpos( $f->getPathname(), '/tests/' ) ) {
				$files[] = $f->getPathname();
			}
		}
		// Answered by parts not on every install: the Analytics ledger's copies of billing and the CRM, and the Jobs
		// app's own list of each person's next steps. Their tiles say the app does not answer here yet.
		$elsewhere = array( 'ledger.home_figures', 'jobs.my_next_counts', 'jobs.installed_counts' );
		foreach ( array_keys( ZDZ_KPI_Metrics::VERBS ) as $verb ) {
			if ( in_array( $verb, $elsewhere, true ) ) {
				continue;
			}
			$found = false;
			foreach ( $files as $path ) {
				$src = (string) file_get_contents( $path );
				if ( false === strpos( $src, "'" . $verb . "'" ) || ! preg_match( "/'side_effect'\s*=>\s*false/", $src ) ) {
					continue;
				}
				// The app that describes it hooks it onto the registry (in this file or its app.php).
				$app = preg_replace( '#^(' . preg_quote( $apps, '#' ) . '/[^/]+)/.*$#', '$1', $path );
				if ( false !== strpos( $src, 'zdz_register_capabilities' ) || false !== strpos( (string) file_get_contents( $app . '/app.php' ), 'zdz_register_capabilities' ) ) {
					$found = true;
					break;
				}
			}
			$this->assertTrue( $found, "$verb is registered by an app, as a read" );
		}
	}

	public function test_the_messages_tile_asks_the_verb_messages_registers(): void {
		$this->assertSame( ZIM_Unread_Counts::VERB, ZDZ_KPI_Metrics::TECH_POOL['tech_messages']['verb'] );
		$this->assertArrayHasKey( ZIM_Unread_Counts::VERB, ZDZ_KPI_Metrics::VERBS );
		$caps = ZCC_Items_Sold::register( array() );
		$this->assertFalse( $caps[ ZCC_Items_Sold::VERB ]['descriptor']['side_effect'], 'a read' );
		$this->assertFalse( $caps[ ZCC_Items_Sold::VERB ]['descriptor']['kiosk'], 'never the shared device' );
		$this->assertSame( 'staff', $caps[ ZCC_Items_Sold::VERB ]['descriptor']['min_tier'] );
		$this->assertArrayHasKey( ZCC_Items_Sold::VERB, ZDZ_KPI_Metrics::VERBS );
	}

	/* ── A business chooses its tiles ────────────────────────────────────── */

	public function test_choices_only_narrow_and_order_a_sets_own_tiles(): void {
		$this->assertSame( ZDZ_KPI_Metrics::SETS['sales'], ZDZ_KPI_Metrics::chosen( 'sales' ), 'nothing chosen: Core\'s set' );
		$clean = ZDZ_KPI_Metrics::clean_tile_sets( array(
			'sales'    => array( 'my_installed', 'ytd_revenue', 'my_leads', 'my_installed', 7 ),   // another set's tile, a repeat
			'operator' => array(),                                                                 // a role with none
			'visitors' => array( 'op_to_call' ),                                                    // not a set
			'tech'     => 'tech_hours',                                                             // not a list
		) );
		$this->assertSame( array( 'sales' => array( 'my_installed', 'my_leads' ), 'operator' => array() ), $clean );

		$GLOBALS['zdz_test_options'][ ZDZ_KPI_Metrics::TILE_SETS_OPTION ] = array( 'sales' => array( 'my_leads', 'my_installed' ) );
		$this->assertSame( array( 'my_leads', 'my_installed' ), ZDZ_KPI_Metrics::chosen( 'sales' ) );
		$this->assertSame( ZDZ_KPI_Metrics::SETS['company'], ZDZ_KPI_Metrics::chosen( 'company' ), 'a set not chosen keeps Core\'s' );

		$GLOBALS['zdz_test_filters']['zdz_kpi_tile_sets'] = function ( $v ) { $v['sales'][] = 'mtd_revenue'; return $v; };
		$this->assertSame( array( 'my_leads', 'my_installed' ), ZDZ_KPI_Metrics::chosen( 'sales' ), 'a filter cannot add another set\'s tile' );
		$this->assertSame( array(), ZDZ_KPI_Metrics::chosen( 'nobody' ) );
	}

	public function test_the_answer_draws_the_chosen_tiles_in_the_chosen_order(): void {
		$GLOBALS['zdz_test_options'][ ZDZ_KPI_Metrics::TILE_SETS_OPTION ] = array(
			'sales'    => array( 'my_installed', 'my_leads' ),
			'operator' => array(),
			'tech'     => array( 'tech_schedule', 'tech_messages' ),
			'company'  => array( 'google_reviews', 'leads_contacted' ),
		);
		$s = ZDZ_KPI_Metrics::build( 2 );
		$this->assertSame( array( 'my_installed', 'my_leads' ), $s['order'] );
		$this->assertSame( array( 'my_installed', 'my_leads' ), array_keys( $s['tiles'] ) );

		$o = ZDZ_KPI_Metrics::build( 5 );
		$this->assertSame( array(), $o['tiles'], 'an empty choice: that role sees no tiles' );
		$this->assertSame( array(), $o['order'] );

		$t = ZDZ_KPI_Metrics::build( 6 );
		$this->assertNotContains( 'tech_hours', $t['order'], 'My hours left out' );
		$this->assertSame( array(), array_diff( $t['order'], array( 'tech_schedule', 'tech_messages' ) ), 'only the chosen pool' );

		$c = ZDZ_KPI_Metrics::build( 1 );
		$this->assertSame( array( 'google_reviews', 'leads_contacted' ), $c['order'] );
		$this->assertSame( 'held', $c['tiles']['leads_contacted']['state'] );
	}

	/* ── rules.yml home_tiles ────────────────────────────────────────────── */

	public function test_pack_sets_are_refused_whole_when_a_tile_is_not_the_sets_own(): void {
		$p = ZDZ_KPI_Metrics::plan_home_tiles( array( 'home_tiles.sets' => array(
			'sales'    => array( 'my_leads', 'ytd_revenue' ),
			'tech'     => array( 'tech_hours', 'tech_jobs' ),
			'visitors' => array( 'op_to_call' ),
			'shop'     => array( 'shop_prep', 'shop_prep' ),
		) ) );
		$this->assertSame( array( 'home_tiles.sets.sales', 'home_tiles.sets.visitors', 'home_tiles.sets.shop' ), array_column( $p['refused'], 'path' ) );
		$this->assertCount( 1, $p['writes'] );
		$this->assertSame( ZDZ_KPI_Metrics::TILE_SETS_OPTION, $p['writes'][0]['option'] );
		$this->assertSame( array( 'tech' => array( 'tech_hours', 'tech_jobs' ) ), $p['writes'][0]['value'], 'only the good set; the refused ones keep what they had' );

		$GLOBALS['zdz_test_options'][ ZDZ_KPI_Metrics::TILE_SETS_OPTION ] = array( 'sales' => array( 'my_leads' ) );
		$p = ZDZ_KPI_Metrics::plan_home_tiles( array( 'home_tiles.sets' => array( 'tech' => array( 'tech_jobs' ) ) ) );
		$this->assertSame( array( 'sales' => array( 'my_leads' ), 'tech' => array( 'tech_jobs' ) ), $p['writes'][0]['value'], 'a set the pack leaves out is kept' );
	}

	public function test_pack_shop_items_must_name_catalog_items(): void {
		self::catalog( array( 'panel-new', 'panel-repair' ) );
		$good = array( array( 'label' => 'Panels this month', 'counts' => array( 'panel-new', 'panel-repair' ), 'words' => array( 'New', 'Repaired' ) ) );
		$p    = ZDZ_KPI_Metrics::plan_home_tiles( array( 'home_tiles.shop_items' => $good ) );
		$this->assertSame( array(), $p['refused'] );
		$this->assertSame( ZDZ_KPI_Metrics::SHOP_ITEMS_OPTION, $p['writes'][0]['option'] );
		$this->assertSame( 'new/repaired', $p['writes'][0]['value'][0]['detail'] );

		$typo = array( array( 'label' => 'Panels', 'counts' => array( 'panel-nwe' ) ) );
		$p    = ZDZ_KPI_Metrics::plan_home_tiles( array( 'home_tiles.shop_items' => $typo ) );
		$this->assertSame( array( 'home_tiles.shop_items.1' ), array_column( $p['refused'], 'path' ), 'a count key no item has would read 0 for ever' );
		$this->assertSame( array(), $p['writes'] );

		$p = ZDZ_KPI_Metrics::plan_home_tiles( array( 'home_tiles.shop_items' => array( $good[0], $good[0], $good[0], $good[0] ) ) );
		$this->assertSame( array( 'home_tiles.shop_items' ), array_column( $p['refused'], 'path' ), 'at most three' );
		$p = ZDZ_KPI_Metrics::plan_home_tiles( array( 'home_tiles.shop_items' => array( array( 'label' => '', 'counts' => array( 'panel-new' ) ) ) ) );
		$this->assertSame( array( 'home_tiles.shop_items.1' ), array_column( $p['refused'], 'path' ) );
	}

	public function test_a_count_may_add_several_catalog_items_together(): void {
		$clean = ZDZ_KPI_Metrics::clean_shop_items( array(
			array( 'label' => 'Panels this month', 'counts' => array( array( 'panel-new', 'panel-new-wide', 'panel-new' ), 'panel-repair' ), 'words' => array( 'New', 'Repaired' ) ),
			array( 'label' => 'Mixed', 'counts' => array( array( 'panel-new', 'Not An Id' ) ) ),   // one bad id: the tile is dropped, never guessed
			array( 'label' => 'Too many', 'counts' => array( array_map( function ( $i ) { return 'item-' . $i; }, range( 1, ZDZ_KPI_Metrics::SHOP_COUNT_IDS + 1 ) ) ) ),
		) );
		$this->assertCount( 1, $clean );
		$this->assertSame( array( array( 'panel-new', 'panel-new-wide' ), 'panel-repair' ), $clean[0]['counts'] );

		$m = new ReflectionMethod( 'ZDZ_KPI_Metrics', 'items_tile' );
		$m->setAccessible( true );
		$t = $m->invoke( null, array( 'success' => true, 'counts' => array( 'panel-new' => 4, 'panel-new-wide' => 2, 'panel-repair' => 5 ), 'invoices' => 3, 'counted_at' => 1 ), $clean[0] );
		$this->assertSame( '6 / 5', $t['display'], 'new = both kinds of new item' );
		$this->assertSame( 11, $t['value'] );

		self::catalog( array( 'panel-new', 'panel-repair' ) );
		$p = ZDZ_KPI_Metrics::plan_home_tiles( array( 'home_tiles.shop_items' => array( array( 'label' => 'Panels', 'counts' => array( array( 'panel-new', 'panel-new-wide' ) ) ) ) ) );
		$this->assertStringContainsString( 'panel-new-wide', $p['refused'][0]['why'], 'every item of a list must be in the catalog' );
	}

	public function test_the_consumer_registers_on_the_pack_registry(): void {
		$c = ZDZ_KPI_Metrics::pack_consumer( array() );
		$this->assertSame( array( 'rules' ), $c['home-tiles']['files'] );
		$this->assertSame( array( 'rules' => array( 'home_tiles.sets', 'home_tiles.shop_items' ) ), $c['home-tiles']['paths'] );
		foreach ( array( 'preview', 'apply', 'snapshot', 'restore' ) as $k ) {
			$this->assertTrue( is_callable( $c['home-tiles'][ $k ] ), $k );
		}
	}

	/* ── the installed tile ─────────────────────────────────────────────── */

	public function test_the_installed_tile_shows_a_marked_part_only_when_its_owner_sends_one(): void {
		$m = new ReflectionMethod( 'ZDZ_KPI_Metrics', 'my_installed_tile' );
		$m->setAccessible( true );
		$c = array( 'label' => 'June 2026', 'zone' => 'site time, UTC' );
		$t = $m->invoke( null, array( 'success' => true, 'mine' => array( 'installed' => 3, 'by_jobs' => 3 ), 'read_at' => 1 ), $c );
		$this->assertSame( 3, $t['value'] );
		$this->assertSame( array( 'All work finished' ), array_column( $t['parts'], 'label' ), 'no mark kept: never "marked 0"' );
		$t = $m->invoke( null, array( 'success' => true, 'mine' => array( 'installed' => 3, 'marked' => 1, 'by_jobs' => 2 ), 'read_at' => 1 ), $c );
		$this->assertSame( array( 'Marked installed', 'All work finished (not marked)' ), array_column( $t['parts'], 'label' ) );
		$t = $m->invoke( null, array( 'success' => false, 'error' => 'Jobs could not be read just now.' ), $c );
		$this->assertSame( 'error', $t['state'] );
		$this->assertNull( $t['value'], 'a failed read is never 0' );
	}

	/* ── items.sold_counts ───────────────────────────────────────────────── */

	public function test_items_sold_counts_per_catalog_item_and_says_when_not_counted_yet(): void {
		$invoices = array(
			array( 'date_completed' => '2026-06-02', 'lines' => array( array( 'description' => 'a' ), array( 'description' => 'b' ), array( 'description' => 'fee' ) ) ),
			array( 'date_completed' => '2026-05-31', 'lines' => array( array( 'description' => 'a' ) ) ),   // May: left out
			array( 'date_completed' => '2026-06-09', 'lines' => array( array( 'description' => 'a' ), array( 'description' => 'loc' ) ) ),
		);
		$classify = function ( array $lines ) {
			$map = array(
				'a'   => array( 'item_id' => 'panel-new', 'quantity' => 2, 'sign' => 'positive', 'ledger_kind' => '' ),
				'b'   => array( 'item_id' => 'panel-repair', 'quantity' => 1, 'sign' => 'positive', 'ledger_kind' => '' ),
				'fee' => array( 'item_id' => '', 'quantity' => 1, 'sign' => 'positive', 'ledger_kind' => 'fee' ),
				'loc' => array( 'item_id' => '', 'quantity' => 1, 'is_location' => true ),
			);
			return array_map( function ( $l ) use ( $map ) { return $map[ $l['description'] ]; }, $lines );
		};
		$this->assertSame( array( 'panel-new' => 4, 'panel-repair' => 1 ), ZCC_Items_Sold::counts_from( $invoices, '2026-06-01', '2026-06-30', $classify ) );

		$r = ZCC_Items_Sold::from_capability( array( 'user_id' => 4 ) );
		$this->assertFalse( $r['success'] );
		$this->assertTrue( $r['counting'], 'no copy yet: being counted, never 0' );

		$GLOBALS['zdz_test_options'][ ZCC_Items_Sold::OPTION ] = array( 'month' => '2026-06', 'built_at' => ZCC_Items_Sold::$now - 9000, 'counts' => array( 'panel-new' => 4 ), 'invoices' => 2 );
		$r = ZCC_Items_Sold::from_capability( array( 'user_id' => 4 ) );
		$this->assertTrue( $r['success'] );
		$this->assertSame( array( 'panel-new' => 4 ), $r['counts'] );
		$this->assertTrue( $r['stale'], 'older than its interval' );

		$GLOBALS['zdz_test_options'][ ZCC_Items_Sold::OPTION ] = array( 'month' => '2026-05', 'built_at' => 1, 'counts' => array( 'panel-new' => 9 ), 'last_error' => 'count failed', 'error_month' => '2026-06' );
		$r = ZCC_Items_Sold::from_capability( array( 'user_id' => 4 ) );
		$this->assertFalse( $r['success'] );
		$this->assertArrayNotHasKey( 'counting', $r, 'a failed count is an error' );
		$this->assertArrayNotHasKey( 'counts', $r, 'last month\'s copy is never this month\'s' );
		$this->assertSame( 'kiosk', ZCC_Items_Sold::from_capability( array( 'user_id' => 3, 'is_kiosk' => true ) )['reason'] );
	}

	public function test_a_failed_count_keeps_the_last_good_copy(): void {
		$GLOBALS['zdz_test_options'][ ZCC_Items_Sold::OPTION ] = array( 'month' => '2026-06', 'built_at' => 100, 'counts' => array( 'panel-new' => 3 ), 'invoices' => 1 );
		ZCC_Items_Sold::$invoices = function () { return null; };
		$this->assertNull( ZCC_Items_Sold::refresh() );
		$kept = $GLOBALS['zdz_test_options'][ ZCC_Items_Sold::OPTION ];
		$this->assertSame( array( 'panel-new' => 3 ), $kept['counts'] );
		$this->assertSame( '2026-06', $kept['error_month'] );
	}
}
