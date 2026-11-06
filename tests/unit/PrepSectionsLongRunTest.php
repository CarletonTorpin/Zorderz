<?php
/**
 * Long runs and field-measured sections in Prep, with neutral materials (a grey roll in two widths):
 *
 *   - ZPREP_Engine::classify(): too LONG for every sheet but ripable is a run; too WIDE for every roll stays a piece;
 *   - split_by_length() (bench / field / too wide) and lay_run() (measured order kept, cumulative tape marks);
 *   - compute_plan(): an unmeasured run prints a "needs a field measurement" page with its material reserved and a
 *     not-cut deliverable; measured sections print one continuous pull per run with crosscuts; a piece no roll can
 *     take is named in `dropped` and the warnings, never swallowed; ordinary pieces still pack on sheets;
 *   - ZPREP_Sections: panels cleaned in order, measured length, the three tolerance verdicts at their boundaries,
 *     panels turned into section measurements of one run.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( '_n' ) ) {
	function _n( $one, $many, $n, $d = null ) { return 1 === (int) $n ? $one : $many; }
}

$zprep_inc = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/prep/includes/';
require_once $zprep_inc . 'class-zprep-settings.php';
require_once $zprep_inc . 'class-zprep-nesting.php';
require_once $zprep_inc . 'class-zprep-engine.php';
require_once $zprep_inc . 'class-zprep-sections.php';

class PrepSectionsLongRunTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['zdz_test_options']['zprep_settings'] = array(
			'rolls' => array(
				array( 'width_in' => 24, 'color' => 'grey', 'available' => true ),
				array( 'width_in' => 48, 'color' => 'grey', 'available' => true ),
			),
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_options']['zprep_settings'] );
	}

	private function cap(): callable {
		return function ( int $w ) { return 60.0; };
	}

	public function test_too_long_is_a_run_too_wide_stays_a_piece() {
		$w = array( 24, 48 );
		$this->assertSame( 'run', ZPREP_Engine::classify( 30, 100, $w, 0.5, $this->cap() ) );
		$this->assertSame( 'run', ZPREP_Engine::classify( 200, 20, $w, 0.5, $this->cap() ) );
		$this->assertSame( 'piece', ZPREP_Engine::classify( 30, 50, $w, 0.5, $this->cap() ) );
		$this->assertSame( 'piece', ZPREP_Engine::classify( 50, 70, $w, 0.5, $this->cap() ) ); // wider than every roll
		$this->assertSame( 'piece', ZPREP_Engine::classify( 30, 60, $w, 0.5, $this->cap() ) ); // exactly at the cap
	}

	public function test_split_by_length_partitions_bench_field_and_too_wide() {
		list( $bench, $field, $wide ) = ZPREP_Engine::split_by_length( array(
			array( 'w' => 20, 'h' => 30 ),
			array( 'w' => 20, 'h' => 90 ),
			array( 'w' => 30, 'h' => 40 ),
		), 24, 0.5, 60 );
		$this->assertCount( 1, $bench );
		$this->assertSame( 'bench', $bench[0]['work_class'] );
		$this->assertCount( 1, $field );
		$this->assertSame( 'field', $field[0]['work_class'] );
		$this->assertCount( 1, $wide );
	}

	public function test_a_run_keeps_its_measured_order_and_marks_from_one_end() {
		$lay = ZPREP_Engine::lay_run( array(
			array( 'w' => 30, 'h' => 72, 'label' => 'a' ),
			array( 'w' => 68, 'h' => 30, 'label' => 'b' ),
			array( 'w' => 28, 'h' => 64, 'label' => 'c' ),
			array( 'w' => 60, 'h' => 61, 'label' => 'too wide' ),
		), 47.5 );
		$this->assertSame( array( 'a', 'b', 'c' ), array_column( $lay['placed'], 'label' ) );
		$this->assertSame( array( 72.0, 140.0, 204.0 ), array_column( $lay['crosscuts'], 'at_in' ) );
		$this->assertSame( array( 0.0, 72.0, 140.0 ), array_column( $lay['placed'], 'y' ) );
		$this->assertSame( 30.0, $lay['widest'] );
		$this->assertSame( 204.0, $lay['length'] );
	}

	public function test_an_unmeasured_run_reserves_material_and_says_it_needs_measuring() {
		$plan = ( new ZPREP_Engine() )->compute_plan( array(
			array( 'kind' => '', 'qty' => 1, 'width_in' => 504, 'height_in' => 30, 'color' => 'grey' ),
			array( 'kind' => '', 'qty' => 2, 'width_in' => 20, 'height_in' => 14, 'color' => 'grey' ),
		) );
		$runs = array_values( array_filter( $plan['pages'], function ( $p ) { return ! empty( $p['needs_sections'] ); } ) );
		$this->assertCount( 1, $runs );
		$this->assertSame( 504.0, $runs[0]['run_length_in'] );
		$this->assertSame( 30.0, $runs[0]['trim_to_in'] );
		$this->assertSame( 48, $runs[0]['roll_width_in'] );   // the narrowest roll that rips 30" wide
		$this->assertSame( 0, $runs[0]['piece_count'] );
		$this->assertFalse( $runs[0]['leftover_saveable'] );
		$this->assertTrue( $runs[0]['compressed'] );
		$deliv = array_values( array_filter( $plan['deliverables'], function ( $d ) { return ! empty( $d['is_run'] ); } ) );
		$this->assertCount( 1, $deliv );
		$this->assertTrue( $deliv[0]['not_cut'] );
		$this->assertCount( 1, $plan['runs'] );
		$this->assertNotEmpty( array_filter( $plan['warnings'], function ( $w ) { return 0 === strpos( $w, 'FIELD MEASUREMENT NEEDED' ); } ) );
		// The ordinary pieces still pack on a sheet.
		$sheets = array_values( array_filter( $plan['pages'], function ( $p ) { return empty( $p['compressed'] ); } ) );
		$this->assertSame( 2, array_sum( array_column( $sheets, 'piece_count' ) ) );
		$this->assertSame( array(), $plan['dropped'] );
	}

	public function test_measured_sections_cut_on_one_pull_in_order_with_tape_marks() {
		$m = ZPREP_Sections::to_measurements( array(
			'id'     => 9,
			'name'   => 'North side',
			'color'  => 'grey',
			'panels' => array(
				array( 'h' => 30, 'w' => 72 ),
				array( 'h' => 30, 'w' => 68, 'label' => 'by the steps' ),
				array( 'h' => 28, 'w' => 64, 'qty' => 2 ),
			),
		) );
		$this->assertCount( 3, $m );
		$this->assertSame( 'section', $m[0]['measure'] );
		$this->assertSame( 'sec:9', $m[0]['run_key'] );
		$this->assertSame( 30.0, $m[0]['width_in'] );   // across the roll: the panel's height
		$this->assertSame( 72.0, $m[0]['height_in'] );  // along it: its length
		$plan  = ( new ZPREP_Engine() )->compute_plan( $m );
		$pulls = array_values( array_filter( $plan['pages'], function ( $p ) { return ! empty( $p['compressed'] ); } ) );
		$this->assertCount( 1, $pulls );
		$this->assertSame( 'sec:9', $pulls[0]['run_key'] );
		$this->assertSame( array( 72.0, 140.0, 204.0, 268.0 ), array_column( $pulls[0]['crosscuts'], 'at_in' ) );
		$this->assertSame( 30.0, $pulls[0]['trim_to_in'] );
		$this->assertSame( 4, $pulls[0]['piece_count'] );
		$this->assertSame( 4, $plan['summary']['total_pieces'] );
		$this->assertSame( 'field', $pulls[0]['work_class'] );
	}

	public function test_a_piece_no_roll_can_take_is_named_never_swallowed() {
		$plan = ( new ZPREP_Engine() )->compute_plan( array(
			array( 'kind' => '', 'qty' => 1, 'width_in' => 50, 'height_in' => 55, 'color' => 'grey' ),
		) );
		$this->assertCount( 1, $plan['dropped'] );
		$this->assertNotEmpty( array_filter( $plan['warnings'], function ( $w ) { return 0 === strpos( $w, 'NOT ON ANY SHEET' ); } ) );
		$this->assertSame( 0, array_sum( array_column( $plan['pages'], 'piece_count' ) ) );
	}

	public function test_a_long_piece_with_no_measure_said_is_pulled_not_dropped() {
		// Over the cap on the 24" roll only because a force pinned it: what it IS does not move with the toggle.
		$plan = ( new ZPREP_Engine() )->compute_plan( array(
			array( 'kind' => '', 'qty' => 1, 'width_in' => 20, 'height_in' => 58, 'color' => 'grey' ),
		), array( 'force_roll' => 24 ) );
		$this->assertSame( array(), $plan['dropped'] );
		$this->assertSame( array(), $plan['runs'] );
	}

	public function test_panels_are_cleaned_in_order() {
		$p = ZPREP_Sections::clean_panels( json_encode( array(
			array( 'h' => 30, 'w' => 72, 'qty' => 0 ),
			array( 'h' => 0, 'w' => 10 ),
			'junk',
			array( 'h' => 30.126, 'w' => 68, 'qty' => 2000, 'label' => "a\nb" ),
		) ) );
		$this->assertCount( 2, $p );
		$this->assertSame( 1, $p[0]['qty'] );
		$this->assertSame( 30.13, $p[1]['h'] );
		$this->assertSame( 999, $p[1]['qty'] );
		$this->assertSame( 'a b', $p[1]['label'] );
		$this->assertSame( 72.0 + 68.0 * 999, ZPREP_Sections::measured_in( $p ) );
	}

	public function test_tolerance_verdicts_at_their_boundaries() {
		$this->assertSame( 'ok', ZPREP_Sections::tolerance( 504, 504, 6 )['level'] );
		$this->assertSame( 'warn', ZPREP_Sections::tolerance( 504.01, 504, 6 )['level'] );
		$this->assertSame( 'warn', ZPREP_Sections::tolerance( 510, 504, 6 )['level'] );
		$this->assertSame( 'over', ZPREP_Sections::tolerance( 510.01, 504, 6 )['level'] );
		$this->assertSame( 'none', ZPREP_Sections::tolerance( 100, 0, 6 )['level'] );
		$t = ZPREP_Sections::tolerance( 480, 504, 6 );
		$this->assertSame( 24.0, $t['remaining_in'] );
		$this->assertSame( 6.0, ZPREP_Sections::grace_in() );
		$h = ZPREP_Sections::hydrate( array( 'id' => 3, 'run_length_in' => '100', 'panels' => json_encode( array( array( 'h' => 10, 'w' => 50, 'qty' => 2 ) ) ) ) );
		$this->assertSame( 100, $h['coverage_pct'] );
		$this->assertSame( 2, $h['panel_count'] );
		$this->assertSame( 'ok', $h['tolerance']['level'] );
	}

	public function test_the_browser_verdict_matches_the_servers() {
		$js = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/prep/assets/js/sections.js';
		if ( ! is_executable( '/usr/bin/node' ) && ! is_executable( '/usr/local/bin/node' ) ) {
			$this->markTestSkipped( 'node is not available' );
		}
		$cases = array( array( 504, 504 ), array( 504.01, 504 ), array( 510, 504 ), array( 510.01, 504 ), array( 100, 0 ) );
		$script = 'global.window={};require(' . json_encode( $js ) . ');var c=' . json_encode( $cases ) . ';console.log(JSON.stringify(c.map(function(x){return window.ZPREPSections.tolerance(x[0],x[1],6).level;})));';
		$out = shell_exec( 'node -e ' . escapeshellarg( $script ) . ' 2>/dev/null' );
		$want = array_map( function ( $x ) { return ZPREP_Sections::tolerance( $x[0], $x[1], 6 )['level']; }, $cases );
		$this->assertSame( $want, json_decode( trim( (string) $out ), true ) );
	}
}
