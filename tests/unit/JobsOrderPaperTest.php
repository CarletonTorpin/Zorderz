<?php
/**
 * A material order printed on a supplier's own form (ZJOB_Order_Paper), with neutral forms only:
 *
 *   - the sample blanks Core ships match their pins, and the spreadsheet fills;
 *   - a paper map is checked field by field (bad boxes, cells and file names are dropped);
 *   - layout(): the rows, grid and sheet-per-unit layouts put each value in its box; the spreadsheet half writes
 *     numbers only where the map says; a draft says so on every page, in the notes cell and in the file name; required
 *     fields left blank and lines the form has no room for are named; an access code never reaches a form;
 *   - write_cells() keeps a cell's style, adds missing rows and cells in order;
 *   - the pack consumer refuses the Core form's key and anything that is not a form, and never points at Core's files.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

$zjob_paper_inc = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/includes/';
require_once $zjob_paper_inc . 'class-zjob-project-orders.php';
require_once $zjob_paper_inc . 'class-zjob-order-paper.php';
require_once $zjob_paper_inc . 'class-zjob-my-next.php';
require_once $zjob_paper_inc . 'class-zjob-pack.php';
require_once $zjob_paper_inc . 'class-zjob-order-forms-admin.php';

class JobsOrderPaperTest extends TestCase {

	protected function setUp(): void {
		ZJOB_Project_Orders::flush_memo();
		unset( $GLOBALS['zdz_test_options'][ ZJOB_Project_Orders::OPT_FORMS ] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_options'] );
	}

	private function order( array $units, array $over = array() ): array {
		return array_merge( array(
			'id'          => 7,
			'form'        => 'materials',
			'status'      => 'open',
			'po'          => 'PO-1001',
			'rev'         => 2,
			'body'        => array( 'units' => $units, 'parts' => array(), 'notes' => '' ),
			'operator_ok' => null,
		), $over );
	}

	private function company(): array {
		return array( 'ship' => array( 'Example Co', '1 Sample Way', 'Springfield', '', '' ), 'bill' => array( 'Example Co', 'PO Box 5', '', '', '' ) );
	}

	private function find( array $page, string $text ): ?array {
		foreach ( $page as $t ) {
			if ( $t['text'] === $text ) {
				return $t;
			}
		}
		return null;
	}

	public function test_the_shipped_sample_blanks_match_their_pins() {
		$paper = ZJOB_Order_Paper::sample_paper();
		$this->assertTrue( ZJOB_Order_Paper::blank_ok( $paper, 'pdf' ) );
		$this->assertTrue( ZJOB_Order_Paper::blank_ok( $paper, 'xlsx' ) );
		$this->assertNotNull( ZJOB_Order_Paper::clean_paper( $paper ) );
		$changed                     = $paper;
		$changed['pdf']['sha256']    = str_repeat( 'a', 64 );
		$this->assertFalse( ZJOB_Order_Paper::blank_ok( $changed, 'pdf' ) );
	}

	public function test_the_core_form_prints_on_the_sample() {
		$f = ZJOB_Project_Orders::form( 'materials' );
		$this->assertNotEmpty( $f['paper']['pdf'] );
		$this->assertNotEmpty( $f['paper']['xlsx'] );
	}

	public function test_a_paper_map_is_checked_value_by_value() {
		$p = ZJOB_Order_Paper::clean_paper( array(
			'pdf'  => array(
				'file' => '../../etc/passwd.pdf', 'sha256' => str_repeat( 'b', 64 ), 'w' => 612, 'h' => 792,
				'po' => array( 10, 10, 5, 20 ), 'date' => array( 10, 10, 50, 20 ), 'layout' => 'nonsense',
				'unit_cols' => array( 'qty' => array( 1, 2 ), 'bad' => array( 5, 1 ) ),
			),
			'xlsx' => array( 'file' => 'form.xls', 'sha256' => str_repeat( 'c', 64 ) ),
		) );
		$this->assertSame( 'passwd.pdf', $p['pdf']['file'] );  // only ever a bare name in the forms folder
		$this->assertArrayNotHasKey( 'po', $p['pdf'] );          // an upside-down box is no box
		$this->assertSame( array( 10.0, 10.0, 50.0, 20.0 ), $p['pdf']['date'] );
		$this->assertSame( 'rows', $p['pdf']['layout'] );
		$this->assertSame( array( 'qty' ), array_keys( $p['pdf']['unit_cols'] ) );
		$this->assertArrayNotHasKey( 'xlsx', $p );               // not an .xlsx name: no spreadsheet
		$this->assertSame( 'site', $p['pdf']['where'] );
		$this->assertNull( ZJOB_Order_Paper::clean_paper( array( 'pdf' => array( 'file' => 'a.pdf' ) ) ) ); // no pin
	}

	public function test_rows_layout_puts_each_value_in_its_box_and_cell() {
		$form = ZJOB_Project_Orders::form( 'materials' );
		$l    = ZJOB_Order_Paper::layout( $this->order( array(
			array( 'k' => 'u1', 'item' => 'Panel', 'qty' => '3', 'size' => '24 x 36', 'colour' => '', 'note' => '' ),
			array( 'k' => 'u2', 'item' => 'Trim', 'qty' => '10', 'size' => '', 'colour' => 'Grey', 'note' => 'cut long' ),
		), array( 'body' => array( 'units' => array(
			array( 'k' => 'u1', 'item' => 'Panel', 'qty' => '3', 'size' => '24 x 36' ),
			array( 'k' => 'u2', 'item' => 'Trim', 'qty' => '10', 'colour' => 'Grey', 'note' => 'cut long' ),
		), 'parts' => array(), 'notes' => 'Leave at the side gate' ) ) ), $form, $this->company(), '2026-02-03', false );
		$page = $l['pages'][0];
		$this->assertSame( array( 430.0, 30.0, 576.0, 46.0 ), $this->find( $page, 'PO-1001' )['box'] );
		$this->assertSame( array( 430.0, 50.0, 576.0, 66.0 ), $this->find( $page, '2026-02-03' )['box'] );
		$this->assertSame( array( 36.0, 98.0, 290.0, 112.0 ), $this->find( $page, 'Example Co' )['box'] );
		$qty = $this->find( $page, '10' );
		$this->assertSame( array( 250.0, 198.0, 300.0, 216.0 ), $qty['box'] );
		$this->assertSame( 'center', $qty['align'] );
		$this->assertTrue( $this->find( $page, 'Leave at the side gate' )['wrap'] );
		$this->assertNull( $this->find( $page, 'DRAFT - not confirmed by the operator' ) );
		$this->assertSame( 'Panel', $l['cells']['A12'] );
		$this->assertSame( 3, $l['cells']['B12'] );
		$this->assertSame( 10, $l['cells']['B13'] );
		$this->assertSame( 'PO-1001', $l['cells']['H1'] );
		$this->assertSame( 'Example Co', $l['cells']['E5'] );
		$this->assertSame( 'Materials-order-PO-PO-1001-2026-02-03', $l['name'] );
		$this->assertSame( array(), $l['problems'] );
	}

	public function test_a_draft_says_so_everywhere_and_blanks_are_named() {
		$form = ZJOB_Project_Orders::form( 'materials' );
		$l    = ZJOB_Order_Paper::layout( $this->order( array( array( 'k' => 'u1', 'item' => '', 'qty' => '2' ) ) ), $form, $this->company(), '2026-02-03', true );
		$this->assertSame( 'draft', $this->find( $l['pages'][0], 'DRAFT - not confirmed by the operator' )['mark'] );
		$this->assertStringContainsString( 'DRAFT', $l['cells']['A29'] );
		$this->assertSame( 0, strpos( $l['name'], 'DRAFT-' ) );
		$this->assertContains( 'Line 1 has no item.', $l['problems'] );
		$none = ZJOB_Order_Paper::layout( $this->order( array() ), $form, array( 'ship' => array(), 'bill' => array() ), '2026-02-03', true );
		$this->assertContains( 'Nothing is on this order yet.', $none['problems'] );
		$this->assertCount( 2, $none['problems'] );
	}

	public function test_lines_beyond_the_forms_rows_are_left_off_and_said() {
		$form  = ZJOB_Project_Orders::form( 'materials' );
		$units = array();
		for ( $i = 0; $i < 17; $i++ ) {
			$units[] = array( 'k' => 'u' . $i, 'item' => 'Piece ' . $i, 'qty' => '1' );
		}
		$l = ZJOB_Order_Paper::layout( $this->order( $units ), $form, $this->company(), '2026-02-03', false );
		$this->assertNotNull( $this->find( $l['pages'][0], 'Piece 14' ) );
		$this->assertNull( $this->find( $l['pages'][0], 'Piece 15' ) );
		$this->assertNotEmpty( array_filter( $l['problems'], function ( $p ) { return false !== strpos( $p, 'room for 15' ); } ) );
	}

	public function test_an_access_code_never_reaches_a_form() {
		require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-sealed.php';
		require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/includes/class-zjob-sealed.php';
		if ( ! ZJOB_Sealed::on() ) {
			$this->markTestSkipped( 'the vault cannot run here (no libsodium)' );
		}
		$form = ZJOB_Project_Orders::form( 'materials' );
		$o    = $this->order( array( array( 'k' => 'u1', 'item' => 'Panel', 'qty' => '1' ) ) );
		$o['body']['notes'] = 'gate code 4821';
		$l = ZJOB_Order_Paper::layout( $o, $form, $this->company(), '2026-02-03', false );
		$this->assertStringNotContainsString( '4821', json_encode( $l ) );
	}

	private function grid_form(): array {
		return ZJOB_Project_Orders::clean_form( array(
			'label'       => 'Stock sizes',
			'unit_fields' => array(
				array( 'k' => 'size', 'label' => 'Size', 'type' => 'choice', 'options' => array( array( 'S', 'Small' ), array( 'M', 'Medium' ) ) ),
				array( 'k' => 'color', 'label' => 'Colour', 'type' => 'choice', 'options' => array( array( 'White', 'White' ), array( 'Black', 'Black' ) ) ),
				array( 'k' => 'qty', 'label' => 'Qty', 'type' => 'number' ),
			),
			'paper'       => array(
				'name' => 'Stock',
				'pdf'  => array(
					'file' => 'stock.pdf', 'sha256' => str_repeat( 'd', 64 ), 'w' => 600, 'h' => 800, 'layout' => 'grid',
					'grid' => array( 'row' => 'size', 'col' => 'color', 'qty' => 'qty' ),
					'size_rows' => array( 'S' => array( 100, 120 ), 'M' => array( 120, 140 ) ),
					'grid_cols' => array( 'White' => array( 300, 350 ), 'Black' => array( 350, 400 ) ),
					'header' => array( 20, 20, 580, 40 ),
					'date_format' => 'd/m/Y',
				),
			),
		) );
	}

	public function test_grid_layout_sums_quantities_where_row_meets_column() {
		$f = $this->grid_form();
		$this->assertSame( 'grid', $f['paper']['pdf']['layout'] );
		$l = ZJOB_Order_Paper::layout( $this->order( array(
			array( 'k' => 'a', 'size' => 'S', 'color' => 'White', 'qty' => '2' ),
			array( 'k' => 'b', 'size' => 'S', 'color' => 'White', 'qty' => '3' ),
			array( 'k' => 'c', 'size' => 'M', 'color' => 'Black', 'qty' => '1' ),
			array( 'k' => 'd', 'size' => 'M', 'color' => '', 'qty' => '1' ),
		), array( 'form' => 'stock' ) ), $f, $this->company(), '2026-02-03', false );
		$page = $l['pages'][0];
		$this->assertSame( array( 300.0, 100.0, 350.0, 120.0 ), $this->find( $page, '5' )['box'] );
		$this->assertSame( array( 350.0, 120.0, 400.0, 140.0 ), $this->find( $page, '1' )['box'] );
		$this->assertSame( 'Example Co   -   PO PO-1001   -   03/02/2026', $page[ count( $page ) - 1 ]['text'] );
		$this->assertCount( 1, $l['problems'] );
	}

	public function test_sheet_per_unit_marks_the_chosen_option_on_its_own_page() {
		$f = ZJOB_Project_Orders::clean_form( array(
			'label'       => 'One sheet each',
			'unit_fields' => array(
				array( 'k' => 'handle', 'label' => 'Handle', 'type' => 'choice', 'options' => array( array( 'A', 'Type A' ), array( 'B', 'Type B' ) ), 'required' => true ),
				array( 'k' => 'width', 'label' => 'Width' ),
				array( 'k' => 'tag', 'label' => 'Tag' ),
			),
			'paper' => array( 'pdf' => array(
				'file' => 'each.pdf', 'sha256' => str_repeat( 'e', 64 ), 'w' => 792, 'h' => 612, 'layout' => 'sheet_per_unit',
				'fields' => array( 'width' => array( 100, 100, 200, 120 ) ),
				'marks'  => array( 'handle' => array( 'A' => array( 300, 100, 320, 120 ), 'B' => array( 300, 120, 320, 140 ) ) ),
				'page_note' => array( 20, 580, 700, 600 ), 'customer_field' => 'tag',
			) ),
		) );
		$l = ZJOB_Order_Paper::layout( $this->order( array(
			array( 'k' => 'a', 'handle' => 'B', 'width' => '35 1/2', 'tag' => 'Back door' ),
			array( 'k' => 'b', 'handle' => '', 'width' => '30' ),
		), array( 'form' => 'each' ) ), $f, $this->company(), '2026-02-03', false );
		$this->assertCount( 2, $l['pages'] );
		$this->assertSame( array( 300.0, 120.0, 320.0, 140.0 ), $this->find( $l['pages'][0], 'X' )['box'] );
		$this->assertSame( 'Example Co   -   PO PO-1001   -   Back door   -   Unit 1 of 2', $this->find( $l['pages'][0], 'Example Co   -   PO PO-1001   -   Back door   -   Unit 1 of 2' )['text'] );
		$this->assertNull( $this->find( $l['pages'][1], 'X' ) );
		$this->assertContains( 'Line 2 has no handle.', $l['problems'] );
	}

	public function test_write_cells_keeps_style_and_adds_in_order() {
		$xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
			. '<row r="2"><c r="B2" s="3"><v>9</v></c><c r="D2"/></row><row r="5"><c r="A5"/></row></sheetData></worksheet>';
		$out = ZJOB_Order_Paper::write_cells( $xml, array( 'B2' => 'Text & more', 'C2' => 4, 'A3' => 'new row', 'Z9' => 'end', 'bad' => 'x' ) );
		$this->assertStringContainsString( '<c r="B2" s="3" t="inlineStr"><is><t xml:space="preserve">Text &amp; more</t></is></c><c r="C2"><v>4</v></c><c r="D2"/>', $out );
		$this->assertMatchesRegularExpression( '#<row r="2">.*</row><row r="3"><c r="A3"#', $out );
		$this->assertMatchesRegularExpression( '#<row r="5">.*</row><row r="9"><c r="Z9"#', $out );
		$this->assertSame( '', ZJOB_Order_Paper::write_cells( '<not xml', array( 'A1' => 'x' ) ) );
		$this->assertSame( 26, ZJOB_Order_Paper::col_num( 'Z' ) );
		$this->assertSame( 28, ZJOB_Order_Paper::col_num( 'AB' ) );
	}

	public function test_the_sample_spreadsheet_fills() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'no zip support' );
		}
		$bytes = ZJOB_Order_Paper::xlsx_bytes( array( 'A12' => 'Panel', 'B12' => 3, 'H1' => 'PO-1001' ), ZJOB_Order_Paper::sample_paper() );
		$this->assertNotSame( '', $bytes );
		$tmp = tempnam( sys_get_temp_dir(), 'zjobt' );
		file_put_contents( $tmp, $bytes );
		$z = new ZipArchive();
		$this->assertTrue( true === $z->open( $tmp ) );
		$sheet = (string) $z->getFromName( 'xl/worksheets/sheet1.xml' );
		$z->close();
		unlink( $tmp );
		$this->assertStringContainsString( '<c r="B12"><v>3</v></c>', $sheet );
		$this->assertStringContainsString( 'PO-1001', $sheet );
	}

	public function test_dates_are_written_the_way_the_form_wants() {
		$this->assertSame( '2026-02-03', ZJOB_Order_Paper::day_as( '2026-02-03', '' ) );
		$this->assertSame( '2/3/2026', ZJOB_Order_Paper::day_as( '2026-02-03', 'n/j/Y' ) );
		$this->assertSame( '2026-02-03', ZJOB_Order_Paper::day_as( '2026-02-03', '<script>' ) );
	}

	public function test_the_pack_refuses_the_core_key_and_anything_that_is_not_a_form() {
		$plan = ZJOB_Pack::plan( array(
			'mappings' => array( 'order_forms' => array(
				'materials'  => array( 'unit_fields' => array( array( 'k' => 'a', 'label' => 'A' ) ) ),
				'supplier-a' => array( 'unit_fields' => array( array( 'k' => 'a', 'label' => 'A' ) ), 'paper' => array( 'pdf' => array( 'where' => 'core', 'file' => 'materials-sample.pdf' ) ) ),
				'empty'      => array( 'label' => 'No fields' ),
			) ),
			'flows' => array( 'steps' => array( 'order' => array( 'kinds' => array( 'Install!', 'repair' ) ) ) ),
		) );
		$this->assertSame( array( 'supplier-a' ), array_keys( $plan['write'][ ZJOB_Project_Orders::OPT_FORMS ] ) );
		$this->assertSame( 'site', $plan['write'][ ZJOB_Project_Orders::OPT_FORMS ]['supplier-a']['paper']['pdf']['where'] );
		$this->assertCount( 2, $plan['refused'] );
		$this->assertSame( array( 'install', 'repair' ), $plan['write'][ ZJOB_My_Next::RULES_OPTION ]['order']['kinds'] );
	}

	public function test_the_shop_stock_panel_is_the_desks_alone_with_no_job_po_and_no_go_sign() {
		$GLOBALS['zdz_test_options'][ ZJOB_Project_Orders::OPT_OPERATORS ] = array( 'ids' => array( 61 ) );
		$v = ZJOB_Project_Orders::view( ZJOB_Project_Orders::stock_project(), array(), 61 );
		$this->assertTrue( $v['stock'] );
		$this->assertSame( '', $v['po'] );
		$this->assertSame( '', $v['gate'] );
		$this->assertTrue( $v['can_start'] );
		$this->assertSame( array( 'operator' ), ZJOB_Project_Orders::sides( 61, ZJOB_Project_Orders::stock_project() ) );
		$this->assertSame( array(), ZJOB_Project_Orders::sides( 62, ZJOB_Project_Orders::stock_project() ) );
		$this->assertFalse( ZJOB_Project_Orders::view( ZJOB_Project_Orders::stock_project(), array(), 62 )['can_start'] );
		unset( $GLOBALS['zdz_test_options'][ ZJOB_Project_Orders::OPT_OPERATORS ] );
	}

	public function test_only_a_real_pdf_is_taken_as_a_blank() {
		$this->assertTrue( ZJOB_Order_Forms_Admin::looks_pdf( '%PDF-1.4' ) );
		$this->assertFalse( ZJOB_Order_Forms_Admin::looks_pdf( '<html>' ) );
	}
}
