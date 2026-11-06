<?php
/**
 * Prep cut state: the partition of a job into shop (bench) and field work, the four job
 * states and the two field-only class states, who cut last, what a mark changes, and the
 * items a plan produces. Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $n, $domain = null ) { return 1 === (int) $n ? $single : $plural; }
}

final class PrepCutStateTest extends TestCase {

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/prep/includes/class-zprep-cut-state.php';
	}

	protected function setUp(): void {
		// A piece's label looks its kind up in the Item Engine when another file loaded it: here the catalog is empty.
		$GLOBALS['wpdb'] = new ZDZ_Test_Empty_DB();
		if ( class_exists( 'ZDZ_Item_Engine' ) ) {
			ZDZ_Item_Engine::flush();
		}
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		if ( class_exists( 'ZDZ_Item_Engine' ) ) {
			ZDZ_Item_Engine::flush();
		}
	}

	private function row( string $class, string $key, int $total, int $cut, array $extra = array() ): array {
		return array_merge( array( 'job_key' => 'est:1001', 'work_class' => $class, 'item_key' => $key, 'item_label' => $key, 'qty_total' => $total, 'qty_cut' => $cut ), $extra );
	}

	public function test_no_rows_is_unknown(): void {
		$s = ZPREP_Cut_State::pick( array() );
		$this->assertSame( 'unknown', $s['state'] );
		$this->assertSame( '', $s['headline'] );
	}

	public function test_bench_transitions_uncut_partial_complete(): void {
		$this->assertSame( 'uncut', ZPREP_Cut_State::pick( array( $this->row( 'bench', 'a', 4, 0 ) ) )['state'] );
		$p = ZPREP_Cut_State::pick( array( $this->row( 'bench', 'a', 4, 1 ), $this->row( 'bench', 'b', 2, 0 ) ) );
		$this->assertSame( 'partial', $p['state'] );
		$this->assertSame( 'partial', $p['bench']['state'] );
		$this->assertCount( 2, $p['remaining'] );
		$c = ZPREP_Cut_State::pick( array( $this->row( 'bench', 'a', 4, 4 ), $this->row( 'bench', 'b', 2, 9 ) ) );
		$this->assertSame( 'complete', $c['state'], 'a cut count above the total is capped' );
		$this->assertSame( 6, $c['bench']['cut'] );
		$this->assertSame( array(), $c['remaining'] );
	}

	public function test_field_run_without_panels_is_unmeasured_and_keeps_the_job_open(): void {
		$s = ZPREP_Cut_State::pick( array( $this->row( 'bench', 'a', 3, 3 ), $this->row( 'field', 'run:0', 0, 0 ) ) );
		$this->assertSame( 'unmeasured', $s['field']['state'] );
		$this->assertSame( 'partial', $s['state'], 'shop done but the field still has work' );
		$only_run = ZPREP_Cut_State::pick( array( $this->row( 'field', 'run:0', 0, 0 ) ) );
		$this->assertSame( 'uncut', $only_run['state'] );
	}

	public function test_stock_pulled_is_its_own_field_state(): void {
		$s = ZPREP_Cut_State::pick( array( $this->row( 'field', 'run:1', 5, 0, array( 'stock_pulled' => 1 ) ) ) );
		$this->assertSame( 'stock_pulled', $s['field']['state'] );
		$this->assertSame( 'uncut', $s['state'] );
	}

	public function test_both_tracks_in_any_order_are_legal(): void {
		$field_first = ZPREP_Cut_State::pick( array( $this->row( 'bench', 'a', 3, 0 ), $this->row( 'field', 'run:1', 5, 5 ) ) );
		$this->assertSame( 'partial', $field_first['state'] );
		$this->assertSame( 'complete', $field_first['field']['state'] );
		$this->assertSame( 'uncut', $field_first['bench']['state'] );
	}

	public function test_latest_cutter_is_reported(): void {
		$s = ZPREP_Cut_State::pick( array(
			$this->row( 'bench', 'a', 2, 2, array( 'cut_by' => 7, 'cut_at' => '2026-03-01 10:00:00' ) ),
			$this->row( 'bench', 'b', 2, 1, array( 'cut_by' => 9, 'cut_at' => '2026-03-02 09:00:00' ) ),
			$this->row( 'bench', 'c', 2, 0, array( 'cut_by' => 11, 'cut_at' => '2026-03-05 09:00:00' ) ),
		) );
		$this->assertSame( 9, $s['cut_by'], 'a row with nothing cut does not count as a cut' );
		$this->assertSame( array( 7, 9 ), $s['cut_by_all'] );
	}

	public function test_mark_row_stamps_only_when_more_is_cut(): void {
		$now = '2026-04-01 12:00:00';
		$this->assertNull( ZPREP_Cut_State::mark_row( array( 'qty_cut' => 2, 'qty_total' => 4, 'stock_pulled' => 0 ), array( 'qty_total' => 4, 'qty_cut' => 2 ), 5, $now, 'shop' ), 'no change' );
		$up = ZPREP_Cut_State::mark_row( array( 'qty_cut' => 2, 'qty_total' => 4, 'stock_pulled' => 0 ), array( 'qty_total' => 4, 'qty_cut' => 3 ), 5, $now, 'site' );
		$this->assertSame( 5, $up['cut_by'] );
		$this->assertSame( 'site', $up['cut_where'] );
		$down = ZPREP_Cut_State::mark_row( array( 'qty_cut' => 3, 'qty_total' => 4, 'stock_pulled' => 0 ), array( 'qty_total' => 4, 'qty_cut' => 1 ), 5, $now, 'shop' );
		$this->assertArrayNotHasKey( 'cut_by', $down, 'lowering a count never claims a cut' );
		$new = ZPREP_Cut_State::mark_row( null, array( 'qty_total' => 3, 'qty_cut' => 0 ), 5, $now, 'nowhere' );
		$this->assertArrayNotHasKey( 'cut_by', $new );
		$this->assertSame( 'shop', ZPREP_Cut_State::mark_row( null, array( 'qty_total' => 3, 'qty_cut' => 9 ), 5, $now, 'nowhere' )['cut_where'] );
		$this->assertSame( 3, ZPREP_Cut_State::mark_row( null, array( 'qty_total' => 3, 'qty_cut' => 9 ), 5, $now, 'shop' )['qty_cut'] );
	}

	public function test_items_from_plan_bench_and_field(): void {
		$plan  = array(
			'batches' => array(
				array( 'key' => 'k1', 'kind' => 'custom', 'width_in' => 12.0, 'height_in' => 20.5, 'total_qty' => 4, 'plan' => array( 'sheets' => array( 1 ) ) ),
				array( 'key' => 'k2', 'kind' => 'custom', 'total_qty' => 2, 'plan' => array( 'not_cut' => true, 'sheets' => array() ) ),
				array( 'key' => 'k3', 'kind' => 'custom', 'total_qty' => 1, 'plan' => array( 'needs_dimensions' => true, 'sheets' => array() ) ),
			),
			'pages'   => array(
				array( 'compressed' => true, 'run_key' => 'r1', 'piece_count' => 6, 'sheet_length' => 300 ),
				array( 'compressed' => true, 'needs_sections' => true, 'run_length_in' => 500 ),
				array( 'roll_width_in' => 36 ),
			),
		);
		$items = ZPREP_Cut_State::items_from_plan( $plan );
		$this->assertCount( 3, $items );
		$this->assertSame( array( 'bench', 'k1', 4 ), array( $items[0]['work_class'], $items[0]['item_key'], $items[0]['qty_total'] ) );
		$this->assertStringContainsString( '12×20.5', $items[0]['item_label'] );
		$this->assertSame( array( 'field', 'run:r1', 6 ), array( $items[1]['work_class'], $items[1]['item_key'], $items[1]['qty_total'] ) );
		$this->assertSame( array( 'field', 'run:1', 0 ), array( $items[2]['work_class'], $items[2]['item_key'], $items[2]['qty_total'] ) );
	}

	public function test_default_job_key(): void {
		$this->assertSame( 'est:1001', ZPREP_Cut_State::default_job_key( 55, '1001' ) );
		$this->assertSame( 'lead:55', ZPREP_Cut_State::default_job_key( 55, '' ) );
		$this->assertSame( 'remake:3', ZPREP_Cut_State::default_job_key( 0, 'remake:3' ) );
		$this->assertSame( '', ZPREP_Cut_State::default_job_key( 0, '' ) );
	}
}
