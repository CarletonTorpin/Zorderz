<?php
/**
 * Prep's cut planner never leaves a piece off its sheets without saying so.
 *
 * When the packer merges part-full sheets it re-lays each one; a re-lay that would hold fewer pieces than the sheet
 * did must leave the sheet as it was packed. And whatever the packer is handed, it counts what it placed against what
 * it was given and names any shortfall, so a piece is on a sheet or it is in the plan's `dropped` list (printed as
 * "NOT ON ANY SHEET"). And a piece whose size is nothing (0 or less) is asked for like one with no size: it does not
 * stop the plan and it does not vanish from it. Neutral materials: a grey roll in two widths.
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

$zprep_planner_inc = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/prep/includes/';
require_once $zprep_planner_inc . 'class-zprep-settings.php';
require_once $zprep_planner_inc . 'class-zprep-nesting.php';
require_once $zprep_planner_inc . 'class-zprep-engine.php';
require_once __DIR__ . '/support/prep-plan-checks.php';

final class PrepPlannerKeepsEveryPieceTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['zdz_test_options']['zprep_settings'] = array(
			'rolls' => array(
				array( 'width_in' => 36, 'color' => 'grey', 'available' => true ),
				array( 'width_in' => 60, 'color' => 'grey', 'available' => true ),
			),
		);
		// A piece's kind is looked up in the Item Engine when another file loaded it: here the catalog is empty.
		$GLOBALS['wpdb'] = new ZDZ_Test_Empty_DB();
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

	/** One line of rectangles. */
	private static function rect( int $qty, float $w, float $h ): array {
		return array( array( 'kind' => 'custom', 'qty' => $qty, 'width_in' => $w, 'height_in' => $h, 'shape' => 'rectangle', 'color' => 'grey' ) );
	}

	/**
	 * Jobs of one size that came back a piece or two short: the last part-full sheet was laid out again and the
	 * new layout held fewer than the old one.
	 */
	public function test_one_size_jobs_are_all_laid_out(): void {
		$jobs = array(
			'three 19 x 22 on the narrow roll'      => array( 3, 19, 22, array( 'force_roll' => 36 ) ),
			'seven 11 x 24, the engine picks'       => array( 7, 11, 24, array() ),
			'six 13 x 21 on the narrow roll'        => array( 6, 13, 21, array( 'force_roll' => 36 ) ),
			'seventeen 15 x 17 on the wide roll'    => array( 17, 15, 17, array( 'force_roll' => 60 ) ),
		);
		foreach ( $jobs as $name => $j ) {
			$plan = ( new ZPREP_Engine() )->compute_plan( self::rect( $j[0], $j[1], $j[2] ), $j[3] );
			$this->assertSame( $j[0], zprepio_plan_placed( $plan ), $name );
			$this->assertSame( array(), $plan['dropped'], $name );
			$this->assertSame( array(), $plan['warnings'], $name );
			$this->assertSame( '', zprepio_plan_off_stock( $plan ), $name );
		}
	}

	public function test_the_packer_alone_holds_all_three(): void {
		$packer = new ZPREP_Nesting( 36, 0.5, 60.0 );
		$pieces = array_fill( 0, 3, array( 'w' => 19.0, 'h' => 22.0, 'label' => '19×22', 'kind' => 'custom', 'color' => 'grey', 'rotatable' => true ) );
		$pages  = $packer->pack( $pieces );
		$this->assertSame( 3, array_sum( array_column( $pages, 'piece_count' ) ) );
		$this->assertSame( array(), $packer->get_dropped(), 'a sound layout names nothing' );
	}

	public function test_the_packer_names_what_it_was_handed_and_did_not_place(): void {
		$packer = new ZPREP_Nesting( 36, 0.5, 60.0 );
		$fits   = array( 'w' => 10.0, 'h' => 20.0, 'label' => '10×20', 'kind' => 'custom', 'color' => 'grey', 'rotatable' => true );
		$wide   = array( 'w' => 40.0, 'h' => 50.0, 'label' => '40×50', 'kind' => 'panel', 'color' => 'grey', 'rotatable' => true );
		$flat   = array( 'w' => 0.0, 'h' => 20.0, 'label' => '0×20', 'kind' => 'custom', 'color' => 'grey', 'rotatable' => true );

		$pages = $packer->pack( array( $fits, $wide, $fits, $flat, $wide ) );
		$this->assertSame( 2, array_sum( array_column( $pages, 'piece_count' ) ), 'the two that fit are laid out' );
		$named = $packer->get_dropped();
		$this->assertCount( 3, $named, 'every piece that is on no sheet is named, one entry a piece' );
		$this->assertSame( array( '0×20', '40×50', '40×50' ), self::sorted( array_column( $named, 'label' ) ) );
		foreach ( $named as $d ) {
			$this->assertSame( 'not_placed', $d['reason'] );
			$this->assertSame( array( 'label', 'kind', 'w', 'h', 'reason' ), array_keys( $d ) );
		}

		$this->assertSame( array(), $packer->pack( array( $wide ) ), 'nothing fits: no page' );
		$this->assertCount( 1, $packer->get_dropped(), 'and the piece is still named' );

		$packer->pack( array( $fits ) );
		$this->assertSame( array(), $packer->get_dropped(), 'each pack() starts its own list' );
	}

	/**
	 * A width or height of 0, less than 0, or not a number at all. Before, a 0 stopped the whole plan with an error
	 * and a negative size left its pieces out of the plan without a word.
	 */
	public function test_a_size_of_nothing_is_flagged_and_the_rest_of_the_job_is_planned(): void {
		foreach ( array( array( 0, 10 ), array( 10, 0 ), array( 0, 0 ), array( -5, 10 ), array( 10, -5 ), array( 'wide', 10 ), array( '0', '0.0' ) ) as $size ) {
			$plan = ( new ZPREP_Engine() )->compute_plan( array(
				array( 'kind' => 'custom', 'qty' => 2, 'width_in' => $size[0], 'height_in' => $size[1], 'shape' => 'rectangle', 'color' => 'grey' ),
				array( 'kind' => 'custom', 'qty' => 4, 'width_in' => 10, 'height_in' => 12, 'shape' => 'rectangle', 'color' => 'grey' ),
			) );
			$name = json_encode( $size );
			$this->assertSame( 4, zprepio_plan_placed( $plan ), $name . ': the pieces that have a size are laid out' );
			$said = implode( ' ', $plan['warnings'] );
			$this->assertStringContainsString( 'needs dimensions', $said, $name . ': the two without one are asked for' );
			$this->assertStringContainsString( '(qty 2)', $said, $name );
			$this->assertSame( '', zprepio_plan_off_stock( $plan ), $name );
		}
	}

	private static function sorted( array $a ): array {
		sort( $a );
		return $a;
	}

	public function test_made_up_jobs_place_or_name_every_piece_and_keep_them_on_the_stock(): void {
		// Every size here is one any of the rolls takes whole.
		$jobs = zprepio_made_up_jobs( 36, static function ( float $w, float $h ): bool {
			return min( $w, $h ) <= 35.5 && max( $w, $h ) <= 48;
		} );
		$this->assertCount( 36, $jobs );
		$short = array();
		$off   = array();
		foreach ( $jobs as $c => $job ) {
			$plan = ( new ZPREP_Engine() )->compute_plan( $job[0], array( 'force_roll' => array( 0, 36, 60 )[ $c % 3 ] ) );
			if ( zprepio_plan_placed( $plan ) + count( $plan['dropped'] ) !== $job[1] ) {
				$short[] = $c;
			}
			if ( '' !== zprepio_plan_off_stock( $plan ) ) {
				$off[] = $c;
			}
		}
		$this->assertSame( array(), $short, 'jobs whose plan holds fewer pieces than were asked for, with nothing named' );
		$this->assertSame( array(), $off, 'jobs with a piece off its sheet or overlapping another' );
	}
}
