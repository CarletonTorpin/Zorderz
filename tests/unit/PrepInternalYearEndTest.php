<?php
/**
 * The year-end page for Prep's internal orders: one line an order, the year's totals by what the orders were for,
 * the spreadsheet, and who may open it.
 *
 *   - every figure is read from the order and the cut ledger; a figure that is missing is said to be missing and
 *     counted beside its total (a cost with no rate on file, a row with no price, hours nobody typed): never 0;
 *   - a total with nothing in it is blank; minutes are added before they become hours;
 *   - a typed cell can never reach a spreadsheet as a formula, whatever quotes and backslashes it holds;
 *   - everything typed is escaped on the page; the page, and the spreadsheet, are an administrator's.
 *
 * @package Zorderz\Tests
 */

require_once __DIR__ . '/support/prep-internal-doubles.php';

final class PrepInternalYearEndTest extends ZPrepIo_Case {

	/** @var array the order cut from sheets: priced, costed, hours typed, cut. */
	private $full;
	/** @var array the order cut from a roll, made before a cost was on file: nothing priced, no hours, not cut. */
	private $bare;

	private function two_orders(): void {
		$this->three_purposes();
		$this->bare = $this->saved( array( 'customer_label' => 'Test strips', 'purpose_code' => 'testing', 'for_whom' => '', 'reason_text' => '', 'stock' => 'roll', 'sheet_w' => '', 'sheet_l' => '', 'force_roll' => 60, 'pieces' => array( self::piece( 4, 10, 8 ) ) ) );
		$GLOBALS['zdz_test_options'][ ZPREP_Settings::COST_RATES_OPT ] = array( '*' => 0.01 );
		$this->full = $this->saved( array( 'purpose_code' => 'fixtures', 'pieces' => array( self::piece( 6, 20, 15, '12.50' ), self::piece( 2, 10, 8 ) ) ) );
		ZPREP_Remakes::set_labor( (int) $this->full['id'], '1.5', self::JORDAN );
		ZPREP_Dashboard::mark_remake_cut( ZPREP_Remakes::get( (int) $this->full['id'] ), self::JORDAN );
	}

	private function this_year(): int {
		return (int) gmdate( 'Y' );
	}

	/** What a page or a download printed, and the stop that ended it (or null). */
	private function printed( callable $fn ): array {
		$stop  = null;
		$level = ob_get_level();
		ob_start();
		try {
			$fn();
		} catch ( ZPrepIo_Stop $s ) {
			$stop = $s;
		} finally {
			$out = '';
			while ( ob_get_level() > $level ) {
				$out = ob_get_clean() . $out;
			}
		}
		return array( $out, $stop );
	}

	public function test_a_line_says_what_an_order_added_up_to_and_what_is_missing(): void {
		$this->two_orders();
		$lines = ZPREP_Internal_Report::rows( $this->this_year() );
		$this->assertSame( array( 'Test strips', 'Jig covers' ), array_column( $lines, 'name' ), 'the year\'s orders, oldest first' );

		$full = $lines[1];
		$this->assertSame( 'INT-FIX-' . gmdate( 'y' ) . '-002', $full['ref_code'] );
		$this->assertSame( array( 'Shop fixtures', 'the bench crew', 'to keep dust off the jigs', 'grey', '24 x 32 in. sheets' ), array( $full['purpose'], $full['for_whom'], $full['why'], $full['color'], $full['stock'] ) );
		$this->assertSame( array( 8, 1960.0, 4, 3072.0 ), array( $full['pieces'], $full['sq_in'], $full['sheets_used'], $full['sheet_sq_in'] ), '6 x 300 + 2 x 80 sq in, on four sheets' );
		$this->assertSame( array( 0.01, 19.6, '' ), array( $full['cost_rate'], $full['material_cost'], $full['cost_note'] ) );
		$this->assertSame( array( 75.0, 1 ), array( $full['sell_total'], $full['rows_unpriced'] ), 'the priced row, and one row counted as not priced' );
		$this->assertSame( array( true, 90, 1.5, 'Jordan' ), array( $full['labor_set'], $full['labor_minutes'], $full['labor_hours'], $full['labor_by'] ) );
		$this->assertSame( array( true, 'Jordan', 'Alex' ), array( $full['cut'], $full['cut_by'], $full['made_by'] ) );
		$this->assertSame( gmdate( 'Y-m-d' ), $full['cut_on'] );
		$this->assertSame( gmdate( 'Y-m-d' ), $full['made_on'] );

		$bare = $lines[0];
		$this->assertSame( array( 'Material testing', '60 in. roll', 4, 320.0, 0, 0.0 ), array( $bare['purpose'], $bare['stock'], $bare['pieces'], $bare['sq_in'], $bare['sheets_used'], $bare['sheet_sq_in'] ) );
		$this->assertNull( $bare['material_cost'], 'made with no rate on file: not costed, even though a rate was set later' );
		$this->assertSame( 'no rate on file', $bare['cost_note'] );
		$this->assertNull( $bare['sell_total'] );
		$this->assertSame( array( false, 0.0, '' ), array( $bare['labor_set'], (float) $bare['labor_hours'], $bare['labor_by'] ) );
		$this->assertSame( array( false, '', '' ), array( $bare['cut'], $bare['cut_by'], $bare['cut_on'] ) );

		// A roll the engine picks is just "roll"; a removed order is not on the page; another year is another page.
		$auto = $this->saved( array( 'purpose_code' => 'donated', 'stock' => 'roll', 'sheet_w' => '', 'sheet_l' => '' ) );
		$this->assertSame( 'roll', ZPREP_Internal_Report::line( $auto )['stock'] );
		ZPREP_Remakes::set_status( (int) $auto['id'], 'void' );
		$this->assertCount( 2, ZPREP_Internal_Report::rows( $this->this_year() ) );
		$this->assertSame( array(), ZPREP_Internal_Report::rows( $this->this_year() - 1 ) );
	}

	public function test_totals_never_hide_a_gap(): void {
		$this->two_orders();
		$tot = ZPREP_Internal_Report::totals( ZPREP_Internal_Report::rows( $this->this_year() ) );
		$this->assertSame( array( 'fixtures', 'testing' ), array_keys( $tot['by_purpose'] ), 'in the order the form lists them, only those with an order' );

		$all = $tot['all'];
		$this->assertSame( array( 2, 1, 12, 2280.0, 4 ), array( $all['orders'], $all['cut'], $all['pieces'], $all['sq_in'], $all['sheets_used'] ) );
		$this->assertSame( array( 19.6, 1 ), array( $all['material_cost'], $all['orders_no_cost'] ), 'the cost that is known, and one order beside it with none' );
		$this->assertSame( array( 75.0, 1, 2 ), array( $all['sell_total'], $all['orders_priced'], $all['rows_unpriced'] ) );
		$this->assertSame( array( 1.5, 1 ), array( $all['labor_hours'], $all['orders_no_hours'] ) );

		$testing = $tot['by_purpose']['testing'];
		$this->assertSame( 'Material testing', $testing['label'] );
		$this->assertNull( $testing['material_cost'], 'no order here had a cost: blank, not 0.00' );
		$this->assertNull( $testing['sell_total'] );
		$this->assertNull( $testing['labor_hours'] );
		$this->assertSame( array( 1, 1, 1 ), array( $testing['orders_no_cost'], $testing['rows_unpriced'], $testing['orders_no_hours'] ) );

		// A purpose the business has stopped offering keeps its line, after the ones on the form.
		$GLOBALS['zdz_test_options'][ ZPREP_Settings::INTERNAL_OPT ] = array( 'purposes' => array( array( 'key' => 'testing', 'label' => 'Material testing', 'code' => 'TST' ) ) );
		$tot = ZPREP_Internal_Report::totals( ZPREP_Internal_Report::rows( $this->this_year() ) );
		$this->assertSame( array( 'testing', 'fixtures' ), array_keys( $tot['by_purpose'] ) );
		$this->assertSame( 'Fixtures', $tot['by_purpose']['fixtures']['label'] );

		$this->assertSame( 0, ZPREP_Internal_Report::totals( array() )['all']['orders'] );
	}

	public function test_minutes_are_added_before_they_become_hours(): void {
		$line = static function ( int $minutes, bool $typed ): array {
			return array(
				'purpose_code' => 'general', 'purpose' => 'Internal use', 'cut' => false, 'pieces' => 1, 'sq_in' => 10.0, 'sheets_used' => 0, 'sheet_sq_in' => 0.0,
				'material_cost' => null, 'sell_total' => null, 'rows_unpriced' => 1, 'labor_set' => $typed, 'labor_minutes' => $minutes,
			);
		};
		$tot = ZPREP_Internal_Report::totals( array( $line( 20, true ), $line( 20, true ), $line( 20, true ), $line( 0, false ) ) );
		$this->assertSame( 1.0, $tot['all']['labor_hours'], 'three 20-minute orders are one hour' );
		$this->assertSame( 1, $tot['all']['orders_no_hours'] );
		$zero = ZPREP_Internal_Report::totals( array( $line( 0, true ) ) );
		$this->assertSame( 0.0, $zero['all']['labor_hours'], 'a typed 0 is hours; only untyped hours are blank' );
	}

	public function test_the_spreadsheet_is_plain_values_a_line_an_order(): void {
		$this->two_orders();
		$rows = ZPREP_Internal_Report::csv_rows( ZPREP_Internal_Report::rows( $this->this_year() ) );
		$this->assertCount( 3, $rows );
		$this->assertCount( 23, $rows[0] );
		foreach ( $rows as $r ) {
			$this->assertCount( 23, $r );
		}
		$full = array_combine( $rows[0], $rows[2] );
		$this->assertSame( 'Jig covers', $full['Order'] );
		$this->assertSame( array( 8, '1960', 4, '3072', '0.01', '19.60', '', '75.00', 1, '1.5', 'Jordan', 'yes', 'Jordan' ), array( $full['Pieces'], $full['Sq in (pieces)'], $full['Sheets used'], $full['Sq in (whole sheets)'], $full['Cost rate per sq in'], $full['Material cost'], $full['Cost note'], $full['Would sell for'], $full['Rows with no price'], $full['Hours'], $full['Hours entered by'], $full['Cut'], $full['Cut by'] ) );
		$bare = array_combine( $rows[0], $rows[1] );
		$this->assertSame( array( '', '', 'no rate on file', '', '', 'no' ), array( $bare['Cost rate per sq in'], $bare['Material cost'], $bare['Cost note'], $bare['Would sell for'], $bare['Hours'], $bare['Cut'] ), 'a missing figure is an empty cell, never a 0' );
	}

	public function test_a_typed_cell_never_reaches_a_spreadsheet_as_a_formula(): void {
		foreach ( array( '=1+1', '+1', '-1', '@sum' ) as $typed ) {
			$this->assertSame( "'" . $typed, ZPREP_Internal_Report::csv_text( $typed ) );
		}
		$this->assertSame( "'=1+1", ZPREP_Internal_Report::csv_text( "  =1+1 " ), 'leading space does not hide it' );
		$this->assertSame( 'one two three', ZPREP_Internal_Report::csv_text( "one\r\ntwo\tthree" ), 'one cell, one line' );
		$this->assertSame( 'Jig covers', ZPREP_Internal_Report::csv_text( 'Jig covers' ) );
		$this->assertSame( '', ZPREP_Internal_Report::csv_text( '' ) );

		// A backslash before a quote must not end the cell early and hand the next comma-separated piece to the
		// spreadsheet as a cell of its own.
		$sly = 'x\\",=1+1,"';
		$fh  = fopen( 'php://memory', 'w+' );
		ZPREP_Internal_Report::write_csv( $fh, array( array( 'Order', 'Why' ), array( ZPREP_Internal_Report::csv_text( $sly ), 'plain' ) ) );
		rewind( $fh );
		$text = stream_get_contents( $fh );
		$this->assertSame( "\xEF\xBB\xBF", substr( $text, 0, 3 ), 'marked as UTF-8' );
		rewind( $fh );
		fread( $fh, 3 );
		$this->assertSame( array( 'Order', 'Why' ), fgetcsv( $fh, 0, ',', '"', '' ) );
		$this->assertSame( array( $sly, 'plain' ), fgetcsv( $fh, 0, ',', '"', '' ), 'still two cells, the typed one whole' );
		fclose( $fh );
		$this->assertStringContainsString( '"x\\"",=1+1,"""', $text, 'every quote is doubled' );
	}

	public function test_the_page_escapes_what_was_typed_and_says_what_is_missing(): void {
		$this->two_orders();
		$this->saved( array( 'customer_label' => 'Corner plate <b>x</b>', 'purpose_code' => 'donated', 'for_whom' => '"the shop"', 'reason_text' => 'a & b' ) );
		$GLOBALS['zdz_test_current_user'] = self::ALEX;
		list( $html, $stop ) = $this->printed( array( 'ZPREP_Internal_Report', 'render_page' ) );
		$this->assertNull( $stop );
		$this->assertStringContainsString( 'Corner plate &lt;b&gt;x&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( '<b>x</b>', $html );
		$this->assertStringContainsString( '&quot;the shop&quot;', $html );
		$this->assertStringContainsString( 'a &amp; b', $html );
		$this->assertStringContainsString( 'INT-FIX-' . gmdate( 'y' ) . '-002', $html );
		$this->assertStringContainsString( '19.60', $html, 'the cost that is on file' );
		$this->assertStringContainsString( '75.00', $html );
		foreach ( array( 'no rate on file', 'not priced', 'not entered', 'Not yet', '1 row with no price', '2 orders with no hours', 'entered by Jordan', 'Shop fixtures', 'Material testing', 'Donated work', 'All internal orders' ) as $said ) {
			$this->assertStringContainsString( $said, $html, $said );
		}
		$this->assertMatchesRegularExpression( '/action=' . ZPREP_Internal_Report::CSV_ACTION . '&(amp;)?year=' . $this->this_year() . '\b/', $html, 'the download is for the year shown' );
		$this->assertStringContainsString( '_wpnonce=', $html, 'and carries a nonce' );
		$this->assertMatchesRegularExpression( '/<option value="' . $this->this_year() . '"\s+selected/', $html );
	}

	public function test_a_year_with_no_orders_says_so(): void {
		$GLOBALS['zdz_test_current_user'] = self::ALEX;
		list( $html ) = $this->printed( array( 'ZPREP_Internal_Report', 'render_page' ) );
		$this->assertStringContainsString( 'No internal orders were made in ' . $this->this_year(), $html );
		$this->assertStringNotContainsString( ZPREP_Internal_Report::CSV_ACTION, $html, 'nothing to download' );
	}

	public function test_the_year_asked_for_is_kept_sensible(): void {
		$now = $this->this_year();
		foreach ( array( '1999' => $now, 'last' => $now, (string) ( $now + 2 ) => $now, (string) ( $now - 3 ) => $now - 3, (string) ( $now + 1 ) => $now + 1, '2000' => 2000 ) as $asked => $shown ) {
			$_GET['year'] = $asked;
			$this->assertSame( $shown, ZPREP_Internal_Report::asked_year(), (string) $asked );
		}
		unset( $_GET['year'] );
		$this->assertSame( $now, ZPREP_Internal_Report::asked_year() );
	}

	public function test_the_page_and_the_spreadsheet_are_an_administrators(): void {
		if ( ! zprepio_doubles_are_live( 'wp_die', 'check_admin_referer' ) ) {
			$this->markTestSkipped( 'another test file defined wp_die() first' );
		}
		$this->two_orders();
		// Casey manages Prep by role but does not hold the WordPress capability the page asks for.
		foreach ( array( self::JORDAN, self::RILEY, self::DEVICE, self::CASEY, 0 ) as $uid ) {
			$GLOBALS['zdz_test_current_user'] = $uid;
			foreach ( array( 'render_page', 'handle_csv' ) as $door ) {
				list( $out, $stop ) = $this->printed( array( 'ZPREP_Internal_Report', $door ) );
				$this->assertInstanceOf( 'ZPrepIo_Stop', $stop, "{$door}, user {$uid}" );
				$this->assertSame( 403, $stop->status );
				$this->assertSame( '', $out, 'and nothing is printed first' );
			}
		}
		// An administrator without the download's nonce gets no file.
		$GLOBALS['zdz_test_current_user'] = self::ALEX;
		list( $out, $stop ) = $this->printed( array( 'ZPREP_Internal_Report', 'handle_csv' ) );
		$this->assertInstanceOf( 'ZPrepIo_Stop', $stop );
		$this->assertSame( 'bad nonce', $stop->getMessage() );
		$this->assertSame( '', $out );
		$this->assertSame( 'manage_options', ZPREP_Internal_Report::CAP );
	}

	public function test_only_someone_who_may_open_the_page_is_sent_its_address(): void {
		$app = new ReflectionClass( 'ZPREP_Internal_Report' );
		$this->assertSame( 'zprep-internal-orders', $app->getConstant( 'SLUG' ) );
		// The cut list's cards and the form's data are built for the person asking: the money page is named to an
		// administrator alone (ZPREP_App::internal_form_data() asks current_user_can()).
		$GLOBALS['zdz_test_current_user'] = self::CASEY;
		$this->assertFalse( current_user_can( ZPREP_Internal_Report::CAP ) );
		$GLOBALS['zdz_test_current_user'] = self::ALEX;
		$this->assertTrue( current_user_can( ZPREP_Internal_Report::CAP ) );
	}
}
