<?php
/**
 * Prep's internal orders (work the shop cuts for itself): what is saved, what is refused, and what an order adds up
 * to. The orders are written to and read back from a real database (SQLite, behind the $wpdb the classes use).
 *
 *   - an older remakes table gains the new columns once, and every row it held is still a remake;
 *   - nothing ships filled in: one general purpose, no sheet size, no cost, Custom as the only kind of piece;
 *   - only a manager makes an order; each thing wrong with one is refused by name, and an order the cut engine
 *     cannot lay out is not saved at all;
 *   - a saved order keeps its purpose, who it is for, its stock and a reference code that counts the year's orders;
 *   - a remake is saved exactly as it was before internal orders existed;
 *   - a card (which goes to whoever cuts) carries no money; the figures do, and they come from the order's own rows;
 *   - the cost rate is kept on the order when it is made; hours are typed by a person and say who typed them.
 *
 * @package Zorderz\Tests
 */

require_once __DIR__ . '/support/prep-internal-doubles.php';

final class PrepInternalOrdersTest extends ZPrepIo_Case {

	/** The remakes table as it was before internal orders: no kind, no purpose, no sheet, no hours. */
	const TABLE_BEFORE = "CREATE TABLE wp_zprep_remakes (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			parent_job_key VARCHAR(80) NOT NULL DEFAULT '',
			parent_label VARCHAR(191) NOT NULL DEFAULT '',
			customer_label VARCHAR(191) NOT NULL DEFAULT '',
			reason_code VARCHAR(40) NOT NULL DEFAULT 'other',
			reason_text VARCHAR(255) NOT NULL DEFAULT '',
			standard_note TEXT NULL,
			color VARCHAR(32) NOT NULL DEFAULT '',
			force_roll SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			install_date VARCHAR(32) NOT NULL DEFAULT '',
			measurements LONGTEXT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'open',
			PRIMARY KEY  (id),
			KEY idx_created (created_at),
			KEY idx_parent (parent_job_key),
			KEY idx_status (status)
		) ;";

	const NEW_COLUMNS = array( 'kind', 'purpose_code', 'for_whom', 'ref_code', 'sheet_w', 'sheet_l', 'sheets_used', 'labor_minutes', 'labor_by', 'labor_at', 'cost_rate' );

	private function year2(): string {
		return gmdate( 'y' );
	}

	private function order_count(): int {
		return (int) $this->db->get_var( 'SELECT COUNT(*) FROM `' . ZPREP_Remakes::table_name() . '`' );
	}

	public function test_an_older_table_gains_the_columns_and_its_rows_stay_remakes(): void {
		$this->assertSame( 'wp_zprep_remakes', ZPREP_Remakes::table_name(), '(the table text above names the right table)' );
		$this->db             = new ZPrepIo_Sqlite_DB();
		$GLOBALS['wpdb']      = $this->db;
		ZPREP_Remakes::$delta = array( $this->db, 'delta' );
		$this->db->delta( self::TABLE_BEFORE );
		$this->db->insert( ZPREP_Remakes::table_name(), array(
			'created_at'     => '2025-06-02 09:00:00',
			'created_by'     => self::ALEX,
			'customer_label' => 'Example Co',
			'reason_code'    => 'miscut',
			'measurements'   => '[{"kind":"custom","qty":2,"width":10,"height":12,"shape":"rectangle","color":""}]',
			'status'         => 'open',
		) );
		$this->assertSame( array(), array_values( array_intersect( self::NEW_COLUMNS, $this->db->columns( ZPREP_Remakes::table_name() ) ) ), '(the table starts without them)' );
		unset( $GLOBALS['zdz_test_options'][ ZPREP_Remakes::SCHEMA_OPT ] );

		$before = $this->db->deltas;
		ZPREP_Remakes::maybe_upgrade();
		$this->assertSame( $before + 1, $this->db->deltas );
		$this->assertSame( self::NEW_COLUMNS, array_values( array_intersect( $this->db->columns( ZPREP_Remakes::table_name() ), self::NEW_COLUMNS ) ) );
		$this->assertContains( 'idx_kind', $this->db->indexes( ZPREP_Remakes::table_name() ) );
		$this->assertSame( ZPREP_Remakes::DB_SCHEMA, (int) $GLOBALS['zdz_test_options'][ ZPREP_Remakes::SCHEMA_OPT ] );

		$old = ZPREP_Remakes::get( 1 );
		$this->assertSame( 'remake', $old['kind'], 'a row from before is a remake' );
		$this->assertFalse( ZPREP_Remakes::is_internal( $old ) );
		$this->assertSame( array( 'Example Co', 'miscut', 'open' ), array( $old['customer_label'], $old['reason_code'], $old['status'] ), 'and nothing it held has moved' );
		$this->assertSame( 'remake', ZPREP_Remakes::card( $old )['kind'] );
		$this->assertNull( ZPREP_Remakes::stock_sheet( $old ) );
		$this->assertSame( array(), ZPREP_Remakes::list_open_internal() );

		ZPREP_Remakes::maybe_upgrade();
		ZPREP_Remakes::list_internal( 2025 );
		$this->assertSame( $before + 1, $this->db->deltas, 'the table is looked at once per update, not on every request' );
	}

	public function test_a_table_made_today_needs_no_upgrade(): void {
		$made = $this->db->deltas;
		ZPREP_Remakes::maybe_upgrade();
		$this->assertSame( $made, $this->db->deltas );
		$this->assertContains( 'idx_kind', $this->db->indexes( ZPREP_Remakes::table_name() ) );
	}

	public function test_nothing_ships_filled_in(): void {
		$this->assertSame( array( 'general' => array( 'label' => 'Internal use', 'code' => 'GEN' ) ), ZPREP_Settings::internal_purposes() );
		$this->assertNull( ZPREP_Settings::internal_default_sheet(), 'no sheet size until the business sets one' );
		$this->assertSame( 0.0, ZPREP_Settings::material_cost_rate( 'grey' ), 'no cost until the business sets one' );
		$this->assertSame( array( 'custom' => 'Custom' ), ZPREP_Settings::internal_kinds(), 'with nothing in the catalog, a piece is typed by its size' );
	}

	public function test_only_a_manager_makes_an_order(): void {
		foreach ( array( self::JORDAN, self::RILEY, self::DEVICE, 0 ) as $uid ) {
			$e = ZPREP_Remakes::create( self::order(), $uid );
			$this->assertTrue( is_wp_error( $e ), "user {$uid}" );
			$this->assertSame( 'zprep_remake_forbidden', $e->get_error_code() );
			$this->assertSame( 'Only a manager can add an internal order.', $e->get_error_message() );
		}
		$this->assertSame( 0, $this->order_count() );
		$this->assertIsArray( ZPREP_Remakes::create( self::order(), self::CASEY ), 'a manager by role, without the WordPress capability' );
		$this->assertSame( 1, $this->order_count() );
	}

	public function test_what_an_order_is_refused_for(): void {
		$wrong = array(
			'no_name'    => array( 'customer_label' => '   ' ),
			'no_pieces'  => array( 'pieces' => array( self::piece( 0, 10, 10 ) ) ),
			'no_purpose' => array( 'purpose_code' => 'fixtures' ), // not a purpose this business offers
			'bad_sheet'  => array( 'sheet_w' => '', 'sheet_l' => '' ),
			'round'      => array( 'pieces' => array( array( 'kind' => 'custom', 'qty' => 1, 'width' => 9, 'height' => 9, 'shape' => 'circle' ) ) ),
			'kind'       => array( 'pieces' => array( self::piece( 1, 10, 10, '', 'side_panel' ) ) ),
			'no_size'    => array( 'pieces' => array( array( 'kind' => 'custom', 'qty' => 1, 'width' => 10, 'height' => '' ) ) ),
			'no_fit'     => array( 'pieces' => array( self::piece( 1, 20, 40 ) ) ),
		);
		foreach ( $wrong as $code => $over ) {
			$e = ZPREP_Remakes::create( self::order( $over ), self::ALEX );
			$this->assertTrue( is_wp_error( $e ), $code );
			$this->assertSame( 'zprep_internal_' . $code, $e->get_error_code() );
			$this->assertNotSame( '', $e->get_error_message(), $code );
		}
		$this->assertSame( 0, $this->order_count(), 'a refused order leaves nothing behind' );

		$e = ZPREP_Remakes::create( self::order( $wrong['no_fit'] ), self::ALEX );
		$this->assertStringContainsString( '20 x 40 in.', $e->get_error_message(), 'the piece is named' );
		$this->assertStringContainsString( '24 x 32 in. sheet', $e->get_error_message(), 'and the sheet' );

		// "Sheets" with a size left at nothing is refused: it is never saved as an order cut from a roll.
		foreach ( array( array( '0', '0' ), array( '24', '' ), array( '24', '121' ), array( 'wide', '32' ) ) as $size ) {
			$e = ZPREP_Remakes::create( self::order( array( 'sheet_w' => $size[0], 'sheet_l' => $size[1] ) ), self::ALEX );
			$this->assertTrue( is_wp_error( $e ) && 'zprep_internal_bad_sheet' === $e->get_error_code(), json_encode( $size ) );
		}
	}

	public function test_an_order_the_engine_cannot_lay_out_is_not_saved(): void {
		// Cut from a roll: 61 inches across fits neither roll.
		$e = ZPREP_Remakes::create( self::order( array( 'stock' => 'roll', 'sheet_w' => '', 'sheet_l' => '', 'pieces' => array( self::piece( 1, 61, 70 ) ) ) ), self::ALEX );
		$this->assertTrue( is_wp_error( $e ) );
		$this->assertSame( 'zprep_internal_no_plan', $e->get_error_code() );
		// Longer than a bench piece can be: on a roll that is a pull measured on site, which an order does not hold.
		$e = ZPREP_Remakes::create( self::order( array( 'stock' => 'roll', 'sheet_w' => '', 'sheet_l' => '', 'pieces' => array( self::piece( 1, 20, 80 ) ) ) ), self::ALEX );
		$this->assertTrue( is_wp_error( $e ) );
		$this->assertSame( 'zprep_internal_no_plan', $e->get_error_code() );
		$this->assertSame( 0, $this->order_count() );
	}

	public function test_a_saved_order_keeps_what_was_typed(): void {
		$row = $this->saved( array( 'pieces' => array( self::piece( 6, 20, 15, '12.50' ), self::piece( 2, 10, 8 ) ) ) );
		$this->assertSame( 'internal', $row['kind'] );
		$this->assertTrue( ZPREP_Remakes::is_internal( $row ) );
		$this->assertSame(
			array( 'Jig covers', 'general', 'the bench crew', 'to keep dust off the jigs', 'grey', 'open', (string) self::ALEX, '' ),
			array( $row['customer_label'], $row['purpose_code'], $row['for_whom'], $row['reason_text'], $row['color'], $row['status'], (string) $row['created_by'], $row['parent_job_key'] )
		);
		$this->assertSame( array( 'w' => 24, 'l' => 32.0 ), ZPREP_Remakes::stock_sheet( $row ) );
		$this->assertSame( 0, (int) $row['force_roll'], 'an order cut from sheets names no roll' );
		$this->assertSame( 'INT-GEN-' . $this->year2() . '-001', $row['ref_code'] );
		$rows = json_decode( $row['measurements'], true );
		$this->assertSame( array( 12.5, '' ), array( $rows[0]['sell_each'], $rows[1]['sell_each'] ), 'a price left blank stays blank' );

		// The sheet typed the other way round is the same sheet.
		$turned = $this->saved( array( 'sheet_w' => '32', 'sheet_l' => '24' ) );
		$this->assertSame( array( 'w' => 24, 'l' => 32.0 ), ZPREP_Remakes::stock_sheet( $turned ) );

		// Cut from a roll: no sheet; a roll width the shop stocks is kept, any other means "pick one".
		$roll = $this->saved( array( 'stock' => 'roll', 'sheet_w' => '', 'sheet_l' => '', 'force_roll' => 60 ) );
		$this->assertNull( ZPREP_Remakes::stock_sheet( $roll ) );
		$this->assertSame( 60, (int) $roll['force_roll'] );
		$this->assertSame( 0, (int) $this->saved( array( 'stock' => 'roll', 'sheet_w' => '', 'sheet_l' => '', 'force_roll' => 44 ) )['force_roll'] );

		$this->assertSame(
			array( 'use_leftovers' => false, 'source_job' => 'remake:' . $row['id'], 'workspace' => 'flat', 'force_roll' => 0, 'stock_sheet' => array( 'w' => 24, 'l' => 32.0 ) ),
			ZPREP_Remakes::plan_options( $row ),
			'the plan is made from the order: its own stock, and never the leftover library'
		);
	}

	public function test_a_reference_code_counts_the_years_orders(): void {
		$this->three_purposes();
		$yy = $this->year2();
		$a  = $this->saved( array( 'purpose_code' => 'fixtures' ) );
		$this->assertSame( "INT-FIX-{$yy}-001", $a['ref_code'] );

		// A remake in between is not an internal order, and is not counted.
		ZPREP_Remakes::create( array( 'customer_label' => 'Example Co', 'pieces' => array( array( 'qty' => 1, 'width' => 10, 'height' => 12 ) ) ), self::ALEX );
		$b = $this->saved( array( 'purpose_code' => 'testing' ) );
		$this->assertSame( "INT-TST-{$yy}-002", $b['ref_code'], 'one count for the year, whatever each order is for' );

		// A removed order keeps its number: the next one does not take it.
		ZPREP_Remakes::set_status( (int) $b['id'], 'void' );
		$c = $this->saved( array( 'purpose_code' => 'donated' ) );
		$this->assertSame( "INT-DON-{$yy}-003", $c['ref_code'] );

		// The count is a year's: an order dated in another year is that year's first.
		$this->db->insert( ZPREP_Remakes::table_name(), array( 'created_at' => '2031-01-04 08:00:00', 'kind' => 'internal', 'purpose_code' => 'fixtures', 'customer_label' => 'Rack labels', 'measurements' => '[]' ) );
		$id = (int) $this->db->insert_id;
		$this->assertSame( 'INT-FIX-31-001', ZPREP_Remakes::ref_code_for( $id, 'fixtures', '2031-01-04 08:00:00' ) );
		$this->assertSame( "INT-FIX-{$yy}-001", ZPREP_Remakes::ref_code_for( (int) $a['id'], 'fixtures', (string) $a['created_at'] ), 'and can be worked out again at any time' );

		// A purpose the business no longer offers: the order keeps a readable name, and a code is still made.
		$GLOBALS['zdz_test_options'][ ZPREP_Settings::INTERNAL_OPT ] = array();
		$this->assertSame( 'Fixtures', ZPREP_Remakes::purpose_label( 'fixtures' ) );
		$this->assertSame( "INT-GEN-{$yy}-001", ZPREP_Remakes::ref_code_for( (int) $a['id'], 'fixtures', (string) $a['created_at'] ) );
	}

	public function test_a_remake_is_saved_as_it_always_was(): void {
		$row = ZPREP_Remakes::create(
			array( 'customer_label' => 'Example Co', 'reason_code' => 'miscut', 'color' => 'sand', 'force_roll' => 36, 'parent_job_key' => 'est:1001', 'pieces' => array( array( 'qty' => 2, 'kind' => 'custom', 'width' => 10, 'height' => 12 ) ) ),
			self::ALEX
		);
		$this->assertIsArray( $row );
		$this->assertSame( 'remake', $row['kind'] );
		$this->assertSame( array( '', '', '', '0' ), array( $row['purpose_code'], $row['for_whom'], $row['ref_code'], (string) (int) $row['sheets_used'] ) );
		$this->assertNull( ZPREP_Remakes::stock_sheet( $row ) );
		$this->assertNull( ZPREP_Remakes::plan_options( $row )['stock_sheet'], 'a remake is never planned on a sheet' );
		$card = ZPREP_Remakes::card( $row );
		$this->assertSame(
			array( 'kind', 'remake_id', 'job_key', 'source_job', 'title', 'customer', 'parent_label', 'parent_job_key', 'reason_code', 'reason_label', 'reason_text', 'standard_note', 'color', 'force_roll', 'install_date', 'status', 'assignee_id', 'assignee_name', 'measurements' ),
			array_keys( $card ),
			'a remake card has the fields it had, and no more'
		);
		$this->assertSame( 'remake', $card['kind'] );
		$e = ZPREP_Remakes::create( array( 'customer_label' => 'Example Co', 'pieces' => array( array( 'qty' => 1 ) ) ), self::JORDAN );
		$this->assertSame( 'Only a manager can add a remake.', $e->get_error_message() );
	}

	public function test_a_card_carries_no_money(): void {
		$GLOBALS['zdz_test_options'][ ZPREP_Settings::COST_RATES_OPT ] = array( '*' => 0.0125 );
		$row  = $this->saved( array( 'pieces' => array( self::piece( 6, 20, 15, '12.50' ) ) ) );
		$this->assertGreaterThan( 0.0, (float) $row['cost_rate'], '(the order does hold a rate and a price: the card has something to leave out)' );
		$card = ZPREP_Remakes::card( $row );
		$this->assertSame(
			array( 'ref_code', 'purpose_code', 'purpose_label', 'for_whom', 'sheet', 'sheets_used', 'pieces', 'sq_in', 'labor_hours', 'labor_set', 'labor_by_name' ),
			array_values( array_diff( array_keys( $card ), array( 'kind', 'remake_id', 'job_key', 'source_job', 'title', 'customer', 'parent_label', 'parent_job_key', 'reason_code', 'reason_label', 'reason_text', 'standard_note', 'color', 'force_roll', 'install_date', 'status', 'assignee_id', 'assignee_name', 'measurements' ) ) ),
			'what an internal order adds to a card'
		);
		$json = json_encode( $card );
		foreach ( array( 'sell', 'cost', 'rate', 'price', '12.5', '0.0125', '22.5' ) as $word ) {
			$this->assertStringNotContainsString( $word, $json, $word );
		}
		$this->assertSame( array( 'internal', 'Internal use', 6, 1800.0, array( 'w' => 24, 'l' => 32.0 ) ), array( $card['kind'], $card['purpose_label'], $card['pieces'], $card['sq_in'], $card['sheet'] ) );
	}

	public function test_the_figures_come_from_the_orders_own_rows(): void {
		$row = $this->saved( array( 'pieces' => array( self::piece( 6, 20, 15, '12.50' ), self::piece( 2, 10, 8 ), self::piece( 1, 5, 4, '0' ) ) ) );
		$f   = ZPREP_Remakes::figures( $row );
		$this->assertSame( 9, $f['pieces'] );
		$this->assertSame( 1980.0, $f['sq_in'], '6 x 300 + 2 x 80 + 1 x 20' );
		$this->assertSame( 75.0, $f['sell_total'], 'six at 12.50, and one priced at nothing' );
		$this->assertSame( array( 2, 1 ), array( $f['rows_priced'], $f['rows_unpriced'] ), 'a row with no price is counted, never guessed' );
		$this->assertNull( $f['material_cost'], 'no rate was on file when it was made' );
		$this->assertSame( 'no rate on file', $f['cost_note'] );
		$this->assertSame( array( 0, 0.0 ), array( $f['sheets_used'], $f['sheet_sq_in'] ), 'no sheets are counted before the order is cut' );

		$none = ZPREP_Remakes::figures( $this->saved() );
		$this->assertNull( $none['sell_total'], 'no row has a price: not 0.00' );

		ZPREP_Remakes::set_sheets_used( (int) $row['id'], 4 );
		$this->assertSame( array( 4, 3072.0 ), array_values( array_intersect_key( ZPREP_Remakes::figures( ZPREP_Remakes::get( (int) $row['id'] ) ), array( 'sheets_used' => 1, 'sheet_sq_in' => 1 ) ) ), 'four sheets of 24 x 32, whole' );
	}

	public function test_the_cost_rate_is_kept_on_the_order(): void {
		$GLOBALS['zdz_test_options'][ ZPREP_Settings::COST_RATES_OPT ] = array( '*' => 0.01, 'sand' => 0.02 );
		$grey = $this->saved();
		$sand = $this->saved( array( 'color' => 'sand' ) );
		$this->assertSame( 18.0, ZPREP_Remakes::figures( $grey )['material_cost'], '1800 sq in at the rate for every colour' );
		$this->assertSame( 36.0, ZPREP_Remakes::figures( $sand )['material_cost'], 'a colour with its own rate uses it' );

		// The business changes its rate, then takes it away: the orders already made do not move.
		$GLOBALS['zdz_test_options'][ ZPREP_Settings::COST_RATES_OPT ] = array( '*' => 0.5 );
		$this->assertSame( 18.0, ZPREP_Remakes::figures( ZPREP_Remakes::get( (int) $grey['id'] ) )['material_cost'] );
		unset( $GLOBALS['zdz_test_options'][ ZPREP_Settings::COST_RATES_OPT ] );
		$this->assertSame( 36.0, ZPREP_Remakes::figures( ZPREP_Remakes::get( (int) $sand['id'] ) )['material_cost'] );
		$this->assertNull( ZPREP_Remakes::figures( $this->saved() )['material_cost'], 'and one made with no rate on file has none' );

		// A rate that is not a sensible cost per square inch is no rate.
		foreach ( array( 0, -1, 100, 'a lot', array( 'grey' => 250 ) ) as $bad ) {
			$GLOBALS['zdz_test_options'][ ZPREP_Settings::COST_RATES_OPT ] = $bad;
			$this->assertSame( 0.0, ZPREP_Settings::material_cost_rate( 'grey' ), json_encode( $bad ) );
		}
	}

	public function test_hours_are_typed_by_a_person(): void {
		$row = $this->saved();
		$id  = (int) $row['id'];
		$this->assertFalse( ZPREP_Remakes::card( $row )['labor_set'], 'hours nobody has typed are not zero hours' );

		$after = ZPREP_Remakes::set_labor( $id, '1.5', self::JORDAN );
		$this->assertIsArray( $after );
		$this->assertSame( array( 90, self::JORDAN ), array( (int) $after['labor_minutes'], (int) $after['labor_by'] ) );
		$this->assertNotSame( '', (string) $after['labor_at'] );
		$card = ZPREP_Remakes::card( $after );
		$this->assertSame( array( 1.5, true, 'Jordan' ), array( $card['labor_hours'], $card['labor_set'], $card['labor_by_name'] ) );

		$zero = ZPREP_Remakes::card( ZPREP_Remakes::set_labor( $id, 0, self::SAM ) );
		$this->assertSame( array( 0.0, true, 'Sam' ), array( (float) $zero['labor_hours'], $zero['labor_set'], $zero['labor_by_name'] ), 'a typed 0 is an answer, and says whose' );

		foreach ( array( '', 'two', '-1', '1000.5', null ) as $bad ) {
			$e = ZPREP_Remakes::set_labor( $id, $bad, self::JORDAN );
			$this->assertTrue( is_wp_error( $e ) && 'zprep_internal_bad_hours' === $e->get_error_code(), json_encode( $bad ) );
		}
		$this->assertSame( 0, (int) ZPREP_Remakes::get( $id )['labor_minutes'], 'a refused entry changes nothing' );

		// Hours belong to an internal order that is still on the books.
		$remake = ZPREP_Remakes::create( array( 'customer_label' => 'Example Co', 'pieces' => array( array( 'qty' => 1, 'width' => 10, 'height' => 12 ) ) ), self::ALEX );
		$this->assertSame( 'zprep_internal_missing', ZPREP_Remakes::set_labor( (int) $remake['id'], 1, self::ALEX )->get_error_code() );
		ZPREP_Remakes::set_status( $id, 'void' );
		$this->assertSame( 'zprep_internal_missing', ZPREP_Remakes::set_labor( $id, 1, self::ALEX )->get_error_code() );
		$this->assertSame( 'zprep_internal_missing', ZPREP_Remakes::set_labor( 999, 1, self::ALEX )->get_error_code() );
	}

	public function test_the_lists_the_pages_read(): void {
		$this->three_purposes();
		$a = $this->saved( array( 'purpose_code' => 'fixtures' ) );
		$b = $this->saved( array( 'purpose_code' => 'testing' ) );
		$c = $this->saved( array( 'purpose_code' => 'donated' ) );
		ZPREP_Remakes::create( array( 'customer_label' => 'Example Co', 'pieces' => array( array( 'qty' => 1, 'width' => 10, 'height' => 12 ) ) ), self::ALEX );
		ZPREP_Remakes::set_status( (int) $b['id'], 'cut' );
		ZPREP_Remakes::set_status( (int) $c['id'], 'void' );
		$this->db->insert( ZPREP_Remakes::table_name(), array( 'created_at' => '2031-01-04 08:00:00', 'kind' => 'internal', 'purpose_code' => 'fixtures', 'customer_label' => 'Rack labels', 'measurements' => '[]', 'status' => 'open' ) );
		$later = (int) $this->db->insert_id;

		$ids = static function ( array $rows ): array {
			return array_map( 'intval', array_column( $rows, 'id' ) );
		};
		$this->assertSame( array( $later, (int) $a['id'] ), $ids( ZPREP_Remakes::list_open_internal() ), 'still to cut, newest first: not the cut one, the removed one or the remake' );
		$year = (int) gmdate( 'Y' );
		$this->assertSame( array( (int) $a['id'], (int) $b['id'] ), $ids( ZPREP_Remakes::list_internal( $year ) ), 'the year made, oldest first, cut or not; removed ones left out' );
		$this->assertSame( array( $later ), $ids( ZPREP_Remakes::list_internal( 2031 ) ) );
		$this->assertSame( array( 2031, $year ), ZPREP_Remakes::internal_years() );
	}
}
