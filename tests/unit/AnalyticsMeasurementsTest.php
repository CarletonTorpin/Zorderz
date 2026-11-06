<?php
/**
 * Analytics: measured sizes from the measurement notes the ledger keeps (ZANA_Measurements).
 *
 *   - sizes as written: "A x B" with inch and foot marks, fractions, a diameter; three numbers in a row are not read
 *     unless the spacing says which is the count; outside the bounds is not a size;
 *   - a line's kind is the catalog item it names; labour, fees, links, address lines and the business's own words are
 *     skipped; a line with no size is "no size written", never filled in;
 *   - per job the newest measurement block that carries a size; a person's own notes only when no block has one;
 *     machine notes are never read;
 *   - the question needs a size word and a catalog kind (or "all", "average"); years narrow it;
 *   - the answer names no customer, street or person; staff and the admin tier only, never the shared device.
 *
 * Neutral data only ("widget" and "panel" are made-up catalog items).
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ledger-test-doubles.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/analytics/includes/class-zana-measurements.php';

final class AnalyticsMeasurementsTest extends TestCase {

	private const BLOCK = array( 'Example Estimates', 'MEASURED LIST' );

	protected function setUp(): void {
		ZANA_Measurements::$classify = static function ( $line ) {
			$l = strtolower( (string) $line );
			return false !== strpos( $l, 'widget' ) ? 'widget' : ( false !== strpos( $l, 'panel' ) ? 'panel' : '' );
		};
		ZANA_Measurements::$label = static function ( $k ) {
			return ucfirst( (string) $k ) . 's';
		};
		ZANA_Measurements::$kinds = array( 'widget' => array( 'Widget' ), 'panel' => array( 'Panel', 'board panel' ) );
		ZANA_Measurements::$rows  = null;
		$GLOBALS['zdz_test_filters']['zana_measure_block_markers'] = static function () {
			return array( self::BLOCK );
		};
		$GLOBALS['zdz_test_filters']['zana_measure_machine_markers'] = static function () {
			return array( 'Example cut sheet' );
		};
	}

	protected function tearDown(): void {
		ZANA_Measurements::$classify = null;
		ZANA_Measurements::$label    = null;
		ZANA_Measurements::$kinds    = null;
		ZANA_Measurements::$rows     = null;
		ZANA_Ledger_Access::$context = null;
		ZANA_Ledger_Access::flush();
		unset( $GLOBALS['zdz_test_filters']['zana_measure_block_markers'], $GLOBALS['zdz_test_filters']['zana_measure_machine_markers'],
			$GLOBALS['zdz_test_filters']['zana_measure_skip_words'], $GLOBALS['zdz_test_filters']['zana_measure_bounds'] );
	}

	private static function block( string $lines ): string {
		return "=== Example Estimates — MEASURED LIST ===\n" . $lines;
	}

	public function test_sizes_as_written(): void {
		$this->assertSame( array( 'kind' => 'rect', 'a' => 14.0, 'b' => 24.0 ), ZANA_Measurements::size_in( 'Widget 24 x 14' ) );
		$this->assertSame( array( 'kind' => 'rect', 'a' => 14.5, 'b' => 24.0 ), ZANA_Measurements::size_in( 'Widget [14 1/2" x 24"]' ) );
		$this->assertSame( array( 'kind' => 'rect', 'a' => 24.0, 'b' => 36.0 ), ZANA_Measurements::size_in( "Panel 2' x 3'" ), 'feet are turned into inches' );
		$this->assertSame( array( 'kind' => 'round', 'd' => 8.0 ), ZANA_Measurements::size_in( 'Round widget 8" dia' ) );
		$this->assertSame( array( 'kind' => 'ambiguous' ), ZANA_Measurements::size_in( 'Widget 14 x 24 x 2' ), 'which number is the count cannot be told' );
		$this->assertSame( 2, ZANA_Measurements::size_in( 'Widget 14x24 x2' )['qty'] );
		$this->assertNull( ZANA_Measurements::size_in( 'Widget, size to be checked' ) );
		$GLOBALS['zdz_test_filters']['zana_measure_bounds'] = static function () {
			return array( 1, 96 );
		};
		$this->assertNull( ZANA_Measurements::size_in( 'Panel 14 x 120' ), 'outside the business\'s bounds is not a size' );
	}

	public function test_what_a_line_is(): void {
		$this->assertTrue( ZANA_Measurements::skipped( 'Installation of widgets' ) );
		$this->assertTrue( ZANA_Measurements::skipped( 'Address: 12 Example Lane' ) );
		$this->assertTrue( ZANA_Measurements::skipped( 'See https://example.com/x' ) );
		$this->assertFalse( ZANA_Measurements::skipped( 'Widget 14 x 24' ) );
		$GLOBALS['zdz_test_filters']['zana_measure_skip_words'] = static function () {
			return array( 'ready-made' );
		};
		$this->assertTrue( ZANA_Measurements::skipped( 'Ready-made widget 14 x 24' ), 'the business\'s own words' );

		$this->assertSame( 3, ZANA_Measurements::qty_in( '1. Widget [14 x 24] × 3' ) );
		$this->assertSame( 1, ZANA_Measurements::qty_in( '1. Widget [14 x 24]' ) );
		$this->assertSame( 2, ZANA_Measurements::qty_in( 'Widgets (2) 14 x 24', false ) );
		$this->assertSame( 4, ZANA_Measurements::qty_in( 'Widget 14 x 24 qty 4', false ) );

		$p = ZANA_Measurements::parse_text( "Widget 14 x 24 × 2\nPanel × 3\nSomething else 10 x 10\nLabour 1 x 1", true );
		$this->assertCount( 1, $p['items'], 'a line naming no catalog item is not counted' );
		$this->assertSame( array( 'panel' => 3 ), $p['unsized'], 'no size written is counted as such, never filled in' );
	}

	public function test_which_notes_on_a_job(): void {
		$newest = self::block( "1. Widget [16 x 20] × 2" );
		$older  = self::block( "1. Widget [14 x 24] × 5" );
		$own    = 'Widget 30 x 30 (1)';
		$li     = ZANA_Measurements::lead_items( array( $newest, $own, $older ) );
		$this->assertSame( 'block', $li['from'] );
		$this->assertSame( 16.0, $li['items'][0]['a'], 'the newest block with a size' );
		$this->assertCount( 1, $li['items'], 'a person\'s notes are not added to a sized block' );

		$unsized = self::block( "1. Widget × 2" );
		$li      = ZANA_Measurements::lead_items( array( $unsized, $own ) );
		$this->assertSame( 'notes', $li['from'], 'no block carries a size: the person\'s own notes are read' );
		$this->assertSame( 30.0, $li['items'][0]['a'] );

		$li = ZANA_Measurements::lead_items( array( "Example cut sheet\nWidget 10 x 10 × 9" ) );
		$this->assertSame( array(), $li['items'], 'a machine note is never read for sizes' );
	}

	public function test_the_question(): void {
		$now = gmmktime( 12, 0, 0, 6, 1, 2026 );
		$this->assertSame( array( 'kinds' => array( 'widget' ), 'years' => array() ), ZANA_Measurements::detect( 'What widget sizes have we measured?', $now ) );
		$this->assertSame( array( 'kinds' => array( 'panel' ), 'years' => array( 2025 ) ), ZANA_Measurements::detect( 'Board panel dimensions last year', $now ) );
		$this->assertSame( array( 'kinds' => array(), 'years' => array( 2024, 2026 ) ), ZANA_Measurements::detect( 'average measurements in 2024 and this year', $now ) );
		$this->assertNull( ZANA_Measurements::detect( 'how many widgets did we sell', $now ), 'no size word' );
		$this->assertNull( ZANA_Measurements::detect( 'what size is the widget on #1234', $now ), 'one document is not this route\'s' );
		$this->assertNull( ZANA_Measurements::detect( 'what size shoes', $now ), 'no catalog kind and not "all"' );
	}

	public function test_counting_and_the_words(): void {
		$leads = array(
			11 => ZANA_Measurements::lead_items( array( self::block( "1. Widget [14 x 24] × 2\n2. Panel × 1" ) ) ),
			12 => ZANA_Measurements::lead_items( array( self::block( "1. Widget [14 x 24] × 1\n2. Widget [16 x 20] × 1" ) ) ),
		);
		$agg = ZANA_Measurements::aggregate( $leads );
		$this->assertSame( 2, $agg['leads'] );
		$this->assertSame( 4, $agg['kinds']['widget']['units'] );
		$this->assertSame( 2, $agg['kinds']['widget']['jobs'] );
		$this->assertSame( array( 'units' => 3, 'jobs' => 2 ), $agg['kinds']['widget']['sizes']['14 × 24'] );
		$this->assertSame( 1, $agg['kinds']['panel']['unsized'] );
		$this->assertSame( array( 14.0, 24.0 ), ZANA_Measurements::median_sides( $agg['kinds']['widget']['rect'] ) );
		$only = ZANA_Measurements::aggregate( $leads, array( 'panel' ) );
		$this->assertSame( array( 'panel' ), array_keys( $only['kinds'] ) );

		$md = ZANA_Measurements::render( $agg, array( 'years' => array() ) );
		$this->assertStringContainsString( '| 14 × 24 | 3 | 2 |', $md );
		$this->assertStringContainsString( 'never guessed', $md );
		$this->assertStringNotContainsString( 'Example Lane', $md );
	}

	public function test_who_may_ask_and_the_answer_from_the_copy(): void {
		ZANA_Measurements::$rows = array(
			array( 'lead_id' => 11, 'created_at' => '2026-02-01 10:00:00', 'body' => self::block( "Customer: Alex Example\n1. Widget [14 x 24] × 2" ) ),
			array( 'lead_id' => 12, 'created_at' => '2025-02-01 10:00:00', 'body' => 'Widget 16 x 20 (1)' ),
		);
		ZANA_Ledger_Access::$context = static function ( $uid ) {
			return array( 7 => array( 'tier' => 'staff' ), 8 => array( 'tier' => 'kiosk' ), 9 => array( 'tier' => 'viewer' ) )[ (int) $uid ] ?? array();
		};
		$md = ZANA_Measurements::answer( 7, array( 'kinds' => array( 'widget' ), 'years' => array( 2026 ) ) );
		$this->assertStringContainsString( '| 14 × 24 | 2 | 1 |', $md );
		$this->assertStringNotContainsString( '16 × 20', $md, 'the year narrows it' );
		$this->assertStringNotContainsString( 'Alex', $md, 'no customer is named' );
		$this->assertStringNotContainsString( '14 × 24', ZANA_Measurements::answer( 8, array( 'kinds' => array(), 'years' => array() ) ), 'never the shared device' );
		$this->assertStringNotContainsString( '14 × 24', ZANA_Measurements::answer( 9, array( 'kinds' => array(), 'years' => array() ) ), 'staff and the admin tier only' );
		$this->assertStringNotContainsString( '14 × 24', ZANA_Measurements::answer( 0, array( 'kinds' => array(), 'years' => array() ) ) );
	}

	public function test_wired_into_the_chat_and_the_ask_bar(): void {
		$routes = ZANA_Measurements::quick_route( array() );
		$this->assertCount( 1, $routes );
		$this->assertNull( call_user_func( $routes[0], 'how many widgets did we sell', 7 ), 'not a measurement question: the next route answers' );
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/analytics/includes/class-zana-routes.php' );
		$this->assertStringContainsString( 'ZANA_Measurements::route( $uid, $message )', $src );
		$app = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/analytics/app.php' );
		$this->assertStringContainsString( "'ledger-admin', 'measurements' )", $app );
		$this->assertStringNotContainsString( 'and the stored-data ledger behind them', $app, 'the header no longer lists the ledger as missing' );
	}
}
