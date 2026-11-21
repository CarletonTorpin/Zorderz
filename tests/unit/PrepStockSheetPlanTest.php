<?php
/**
 * Prep's cut engine planning from stock sheets of a stated size (the `stock_sheet` plan option), and planning
 * everything else exactly as it did without the option.
 *
 *   - one page is one sheet: as wide and as long as the sheet, no grungy edge, no roll price, no offcut logged;
 *   - a sheet's shorter side is its width, whichever way round it was given; a part inch of width is dropped;
 *   - a piece that does not come out of the sheet is named as dropped: never laid past the edge, never turned into
 *     a continuous pull or an area waiting for a field measurement;
 *   - the Material table counts sheets; the leftover library (roll strips) is neither drawn on nor added to;
 *   - an option that is not a sheet plans on a roll; an engine that planned a sheet plans a roll afterwards as if
 *     it never had; three roll plans are held to the bytes they had before the option existed.
 *
 * Neutral materials: a grey roll in two widths and a sand roll in one.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( '_n' ) ) {
	function _n( $one, $many, $n, $d = null ) { return 1 === (int) $n ? $one : $many; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ); }
}
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );

$zprep_sheet_inc = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/prep/includes/';
require_once $zprep_sheet_inc . 'class-zprep-settings.php';
require_once $zprep_sheet_inc . 'class-zprep-nesting.php';
require_once $zprep_sheet_inc . 'class-zprep-engine.php';
require_once $zprep_sheet_inc . 'class-zprep-leftovers.php';
require_once __DIR__ . '/support/prep-plan-checks.php';

/** A database with no rows that keeps every read it is asked, so "the library was not looked in" can fail. */
class ZPrepIo_Recording_DB extends ZDZ_Test_Empty_DB {
	public $reads = array();
	public function get_var( $sql ) { $this->reads[] = (string) $sql; return null; }
	public function get_col( $sql ) { $this->reads[] = (string) $sql; return array(); }
	public function get_row( $sql, $out = null ) { $this->reads[] = (string) $sql; return null; }
	public function get_results( $sql, $out = null ) { $this->reads[] = (string) $sql; return array(); }
	/** The reads and writes that name a table. */
	public function touched( string $table ): int {
		$n = 0;
		foreach ( $this->reads as $sql ) {
			$n += ( false !== strpos( $sql, $table ) ) ? 1 : 0;
		}
		foreach ( $this->written as $w ) {
			$n += ( false !== strpos( (string) $w[1], $table ) ) ? 1 : 0;
		}
		return $n;
	}
}

final class PrepStockSheetPlanTest extends TestCase {

	const SHEET = array( 'stock_sheet' => array( 'w' => 24, 'l' => 32 ) );

	/** @var ZPrepIo_Recording_DB */
	private $db;

	protected function setUp(): void {
		$GLOBALS['zdz_test_options']['zprep_settings'] = array(
			'rolls' => array(
				array( 'width_in' => 36, 'color' => 'grey', 'available' => true ),
				array( 'width_in' => 60, 'color' => 'grey', 'available' => true ),
				array( 'width_in' => 36, 'color' => 'sand', 'available' => true ),
			),
		);
		$this->db        = new ZPrepIo_Recording_DB();
		$GLOBALS['wpdb'] = $this->db;
		if ( class_exists( 'ZDZ_Item_Engine' ) ) {
			ZDZ_Item_Engine::flush();
		}
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_options']['zprep_settings'], $GLOBALS['wpdb'] );
		if ( class_exists( 'ZDZ_Item_Engine' ) ) {
			ZDZ_Item_Engine::flush();
		}
	}

	private static function rect( int $qty, float $w, float $h, string $color = 'grey' ): array {
		return array( array( 'kind' => 'custom', 'qty' => $qty, 'width_in' => $w, 'height_in' => $h, 'shape' => 'rectangle', 'color' => $color ) );
	}

	private static function plan( array $m, array $opt = array() ): array {
		return ( new ZPREP_Engine() )->compute_plan( $m, $opt );
	}

	private static function placed( array $plan ): int {
		return zprepio_plan_placed( $plan );
	}

	public function test_what_a_sheet_is(): void {
		$this->assertSame( array( 'w' => 24, 'l' => 32.0 ), ZPREP_Engine::clean_stock_sheet( array( 'w' => 24, 'l' => 32 ) ) );
		$this->assertSame( array( 'w' => 24, 'l' => 32.0 ), ZPREP_Engine::clean_stock_sheet( array( 'w' => '32', 'l' => '24' ) ), 'the shorter side is the width, whichever box it was typed in' );
		$this->assertSame( array( 'w' => 23, 'l' => 32.5 ), ZPREP_Engine::clean_stock_sheet( array( 'w' => '23.9', 'l' => '32.74' ) ), 'a part inch is dropped, never rounded up' );
		$this->assertSame( array( 'w' => 120, 'l' => 120.0 ), ZPREP_Engine::clean_stock_sheet( array( 'w' => 120, 'l' => 120 ) ) );
		foreach ( array( null, array(), 'a sheet', 40, array( 'w' => 0, 'l' => 32 ), array( 'l' => 32 ), array( 'w' => 24 ), array( 'w' => 'wide', 'l' => 32 ), array( 'w' => 24, 'l' => 121 ), array( 'w' => 0.5, 'l' => 32 ), array( 'w' => -24, 'l' => 32 ) ) as $bad ) {
			$this->assertNull( ZPREP_Engine::clean_stock_sheet( $bad ), json_encode( $bad ) );
		}
	}

	public function test_one_page_is_one_sheet(): void {
		$p = self::plan( self::rect( 6, 20, 15 ), self::SHEET );
		$this->assertSame( array( 2, 2, 2 ), array_column( $p['pages'], 'piece_count' ), 'six 20 x 15 pieces: three sheets, two a sheet' );
		$pg = $p['pages'][0];
		$this->assertSame( 24, $pg['roll_width_in'] );
		$this->assertSame( 32.0, (float) $pg['sheet_length'] );
		$this->assertSame( array( 'w' => 24, 'l' => 32.0 ), $pg['stock_sheet'], 'the page is named as a sheet' );
		$this->assertSame( 0.0, (float) $pg['cost'], 'no roll price' );
		$this->assertSame( 0.0, (float) $pg['grungy_margin'], 'no grungy edge' );
		$this->assertFalse( $pg['leftover_saveable'], 'no offcut for the roll-strip library' );
		$this->assertSame( '', zprepio_plan_off_stock( $p ) );
		$this->assertSame( array( 'w' => 24, 'l' => 32.0 ), $p['stock_sheet'], 'the plan says it is a sheet plan' );
		$this->assertSame( array(), $p['dropped'] );
		$this->assertSame( array(), $p['warnings'] );
	}

	public function test_the_material_table_counts_sheets(): void {
		$p   = self::plan( self::rect( 6, 20, 15 ), self::SHEET );
		$row = $p['material_cost']['rolls'][0];
		$this->assertSame( 3, $row['sheets'] );
		$this->assertSame( 16.0, (float) $row['sq_ft'], 'three sheets of 24 x 32, whole' );
		$this->assertSame( 0.0, (float) $row['cost'] );
		$this->assertSame( 0.0, (float) $p['material_cost']['total'] );
		$this->assertStringContainsString( '24" x 32" sheet', $row['label'] );
		$this->assertStringContainsString( 'Grey', $row['label'] );
	}

	public function test_a_piece_the_size_of_the_sheet_fits_it(): void {
		$p = self::plan( self::rect( 1, 24, 32 ), self::SHEET );
		$this->assertCount( 1, $p['pages'], 'a roll would lose its grungy half inch; a sheet does not' );
		$this->assertSame( array(), $p['dropped'] );
	}

	public function test_a_piece_that_does_not_come_out_of_the_sheet_is_named(): void {
		$wide = self::plan( self::rect( 1, 25, 25 ), self::SHEET );
		$this->assertSame( 0, self::placed( $wide ), 'the sheet is not treated as wider than it is' );
		$this->assertNotSame( array(), $wide['warnings'] );
		$this->assertCount( 1, $wide['dropped'] );

		$long = self::plan( self::rect( 1, 10, 33 ), self::SHEET );
		$this->assertSame( 0, self::placed( $long ) );
		$this->assertCount( 1, $long['dropped'] );

		$longer = self::plan( self::rect( 1, 20, 40 ), self::SHEET );
		$this->assertSame( array(), array_filter( $longer['pages'], static function ( $pg ) { return ! empty( $pg['compressed'] ); } ), 'never a continuous pull' );
		$this->assertCount( 1, $longer['dropped'] );
		$this->assertStringContainsString( '24" x 32" sheet', $longer['dropped'][0]['message'] );
		$this->assertStringContainsString( 'NOT ON ANY SHEET', implode( ' ', $longer['warnings'] ) );
	}

	public function test_nothing_is_a_run_on_a_sheet_plan(): void {
		// On a roll this size is an area waiting for a field measurement; a sheet has no roll to pull it from.
		$this->assertCount( 1, self::plan( self::rect( 1, 20, 80 ) )['runs'], '(on a roll it is a run: the case is live)' );
		$p = self::plan( self::rect( 1, 20, 80 ), self::SHEET );
		$this->assertSame( array(), $p['runs'] );
		$this->assertCount( 1, $p['dropped'] );
		$this->assertSame( 0, self::placed( $p ) );
		$this->assertSame( array(), $p['pages'] );
	}

	public function test_a_sheet_given_wider_than_long_is_the_same_sheet(): void {
		$p = self::plan( self::rect( 4, 31, 10 ), array( 'stock_sheet' => array( 'w' => 32, 'l' => 24 ) ) );
		$this->assertSame( 4, self::placed( $p ) );
		$this->assertSame( array( 'w' => 24, 'l' => 32.0 ), $p['stock_sheet'] );
		$this->assertSame( array(), $p['dropped'] );
		$this->assertSame( '', zprepio_plan_off_stock( $p ) );
	}

	public function test_a_sheet_as_wide_as_a_stocked_roll_is_still_a_sheet(): void {
		// Sand is stocked 36 wide only. A 36 x 20 sheet must not borrow that roll's rules.
		$p = self::plan( self::rect( 2, 30, 10, 'sand' ), array( 'stock_sheet' => array( 'w' => 36, 'l' => 20 ) ) );
		$this->assertSame( 2, self::placed( $p ) );
		$this->assertSame( array(), $p['runs'] );
		$this->assertSame( array(), $p['dropped'] );
		$this->assertSame( 20, $p['pages'][0]['roll_width_in'] );
		$this->assertSame( 'sand', $p['pages'][0]['color'] );
	}

	public function test_a_colour_with_no_roll_still_plans_on_a_sheet(): void {
		$GLOBALS['zdz_test_options']['zprep_settings']['rolls'] = array( array( 'width_in' => 36, 'color' => 'grey', 'available' => true ) );
		$p = self::plan( self::rect( 3, 12, 20 ), self::SHEET );
		$this->assertSame( 3, self::placed( $p ) );
		$this->assertSame( 24, $p['pages'][0]['roll_width_in'], 'the sheet is the only stock a sheet plan has' );
	}

	public function test_many_small_pieces_fill_sheets_without_losing_one(): void {
		// Each of these came back a piece short before the planner kept its count: the second 14 x 17 only fits
		// turned, behind the first.
		foreach ( array( array( 2, 14.0, 17.0 ), array( 4, 7.0, 18.0 ), array( 3, 10.0, 20.0 ), array( 16, 16.0, 18.0 ) ) as $c ) {
			$p = self::plan( self::rect( $c[0], $c[1], $c[2] ), self::SHEET );
			$this->assertSame( $c[0], self::placed( $p ), json_encode( $c ) );
			$this->assertSame( array(), $p['dropped'] );
			$this->assertSame( '', zprepio_plan_off_stock( $p ) );
		}
	}

	public function test_made_up_sheet_jobs_place_every_piece_on_its_sheet(): void {
		// Only pieces that come out of the sheet, one way round or the other.
		$jobs = zprepio_made_up_jobs( 30, static function ( float $w, float $h ): bool {
			return ( $w <= 24 && $h <= 32 ) || ( $h <= 24 && $w <= 32 );
		} );
		$this->assertCount( 30, $jobs );
		foreach ( $jobs as $c => $job ) {
			$p = self::plan( $job[0], self::SHEET );
			$this->assertSame( $job[1], self::placed( $p ), "job {$c}" );
			$this->assertSame( array(), $p['dropped'], "job {$c}" );
			$this->assertSame( '', zprepio_plan_off_stock( $p ), "job {$c}" );
		}
	}

	public function test_a_sheet_plan_never_touches_the_leftover_library(): void {
		$table = ZPREP_Leftovers::table_name();
		self::plan( self::rect( 6, 20, 15 ), array( 'use_leftovers' => true, 'source_job' => 'remake:1' ) );
		$this->assertGreaterThan( 0, $this->db->touched( $table ), '(a roll plan asked to use leftovers does look in the library: the stand-in is live)' );

		$this->db->reads   = array();
		$this->db->written = array();
		self::plan( self::rect( 6, 20, 15 ), self::SHEET + array( 'use_leftovers' => true, 'source_job' => 'remake:1' ) );
		$this->assertSame( 0, $this->db->touched( $table ), 'even when asked to' );
	}

	public function test_an_option_that_is_not_a_sheet_plans_on_a_roll(): void {
		foreach ( array( null, array(), 'a sheet', array( 'w' => 0, 'l' => 32 ), array( 'w' => 24 ), array( 'w' => 'wide', 'l' => 32 ), array( 'w' => 24, 'l' => 121 ) ) as $i => $bad ) {
			$p = self::plan( self::rect( 6, 20, 15 ), array( 'stock_sheet' => $bad ) );
			$this->assertArrayNotHasKey( 'stock_sheet', $p, "#{$i}" );
			$this->assertSame( 36, $p['pages'][0]['roll_width_in'], "#{$i}" );
			$this->assertSame( 0.5, (float) $p['pages'][0]['grungy_margin'], "#{$i}" );
		}
	}

	public function test_an_engine_that_planned_a_sheet_plans_a_roll_as_if_it_never_had(): void {
		$engine = new ZPREP_Engine();
		$engine->compute_plan( self::rect( 6, 20, 15 ), self::SHEET );
		$this->assertSame(
			json_encode( ( new ZPREP_Engine() )->compute_plan( self::rect( 6, 20, 15 ) ) ),
			json_encode( $engine->compute_plan( self::rect( 6, 20, 15 ) ) ),
			'the margin and the sheet are put back'
		);
	}

	/**
	 * Three jobs under three sets of options, held to the SHA-256 of their plans. Every number in them is what the engine
	 * made before it knew what a stock sheet was; the hashes were last moved when the sheet labels' punctuation changed
	 * (words only, checked line by line). A roll plan that moves by one character fails here.
	 */
	public function test_roll_plans_are_what_they_were_to_the_byte(): void {
		$jobs = array(
			'panels' => self::rect( 6, 20, 15 ),
			'mixed'  => array(
				array( 'kind' => 'custom', 'qty' => 3, 'width_in' => 20, 'height_in' => 15, 'shape' => 'rectangle', 'color' => 'grey' ),
				array( 'kind' => 'custom', 'qty' => 10, 'width_in' => 21, 'height_in' => 5.5, 'shape' => 'rectangle', 'color' => 'grey' ),
				array( 'kind' => 'custom', 'qty' => 4, 'width_in' => 11.5, 'height_in' => 16, 'shape' => 'rectangle', 'color' => 'sand' ),
				array( 'kind' => 'custom', 'qty' => 2, 'width_in' => 9, 'height_in' => null, 'shape' => 'circle', 'color' => 'grey' ),
				array( 'kind' => 'custom', 'qty' => 1, 'width_in' => 31, 'height_in' => 56, 'shape' => 'rectangle', 'color' => 'grey' ),
			),
			'long'   => array(
				array( 'kind' => 'custom', 'qty' => 2, 'width_in' => 28, 'height_in' => 76, 'shape' => 'rectangle', 'color' => 'grey' ),
				array( 'kind' => 'custom', 'qty' => 3, 'width_in' => null, 'height_in' => null, 'shape' => 'rectangle', 'color' => 'grey' ),
			),
		);
		$held = array(
			'default' => array( array(), '7b1045c2a4665cb41fa803924b77871adc62173cb8f7bb809aed605a467a310d' ),
			'roller'  => array( array( 'workspace' => 'roller' ), '1262ecde932cebe1e41f1abdf4abce035d564a4bb2564adfe605ef59f0a707cb' ),
			'wide'    => array( array( 'force_roll' => 60, 'debug' => false ), '9042eae7ff6f0c996d085df7afe3ebb5c4954ecf4436de0272d497dcc19c98ff' ),
		);
		$was = ini_set( 'serialize_precision', '-1' );
		try {
			foreach ( $held as $name => $h ) {
				$all = array();
				foreach ( $jobs as $k => $m ) {
					$all[ $k ] = self::plan( $m, $h[0] );
				}
				$this->assertSame( $h[1], hash( 'sha256', json_encode( $all ) ), $name );
			}
		} finally {
			if ( false !== $was ) {
				ini_set( 'serialize_precision', (string) $was );
			}
		}
	}
}
