<?php
/**
 * What a business sets for Prep's internal orders, and where it comes from: the settings page, an Identity Pack, the
 * catalog. And the home tile's count once internal orders are in the cut list.
 *
 *   - purposes, the sheet the form starts from and the material cost are data, cleaned value by value: a purpose
 *     needs a key, a label and a code of its own; a sheet's shorter side is its width; a cost is a small positive
 *     number; anything else is left out, and with nothing left Core's one purpose is offered;
 *   - the settings page's boxes and an Identity Pack write the same two options; a pack can be put back exactly;
 *   - the kinds of piece on the form are the catalog's that are cut as typed, for the configured product line, and
 *     Custom; an order may hold one of those and no other;
 *   - the form's data is sent whole to a manager, and the year-end page's address only to an administrator;
 *   - an open internal order in a person's list counts as a job to cut; a cut one, and a remake, do not.
 *
 * @package Zorderz\Tests
 */

require_once __DIR__ . '/support/prep-internal-doubles.php';

final class PrepInternalSettingsTest extends ZPrepIo_Case {

	protected function tearDown(): void {
		ZPREP_Queue_Counts::$now   = null;
		ZPREP_Queue_Counts::$build = null;
		parent::tearDown();
	}

	public function test_a_purpose_needs_a_key_a_label_and_a_code_of_its_own(): void {
		$clean = ZPREP_Settings::clean_internal_purposes( array(
			array( 'key' => 'Fixtures', 'label' => ' Shop fixtures ', 'code' => 'fix' ),
			array( 'key' => 'testing', 'label' => '<b>Material</b> testing', 'code' => 'T-S-T' ),
			array( 'key' => 'testing', 'label' => 'A second testing', 'code' => 'TX2' ),   // the key is taken
			array( 'key' => 'donated', 'label' => 'Donated work', 'code' => 'FIX' ),        // the code is taken
			array( 'key' => 'nolabel', 'label' => '', 'code' => 'NOL' ),
			array( 'key' => 'short', 'label' => 'Short code', 'code' => 'S' ),
			array( 'key' => 'long', 'label' => 'Long code', 'code' => 'TOOLONG' ),
			array( 'key' => '', 'label' => 'No key', 'code' => 'NOK' ),
			'not a purpose',
		) );
		$this->assertSame(
			array( 'fixtures' => array( 'label' => 'Shop fixtures', 'code' => 'FIX' ), 'testing' => array( 'label' => 'Material testing', 'code' => 'TST' ) ),
			$clean
		);
		// A map keyed by purpose reads the same.
		$this->assertSame( $clean, ZPREP_Settings::clean_internal_purposes( array( 'fixtures' => array( 'label' => 'Shop fixtures', 'code' => 'FIX' ), 'testing' => array( 'label' => 'Material testing', 'code' => 'TST' ) ) ) );
		$this->assertSame( array(), ZPREP_Settings::clean_internal_purposes( 'fixtures' ) );
		$many = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$many[] = array( 'key' => 'p' . $i, 'label' => 'Purpose ' . $i, 'code' => 'P' . ( 10 + $i ) );
		}
		$this->assertCount( ZPREP_Settings::MAX_PURPOSES, ZPREP_Settings::clean_internal_purposes( $many ) );
	}

	public function test_the_business_sets_its_own_and_core_stands_in_until_it_does(): void {
		$this->assertSame( array( 'general' ), array_keys( ZPREP_Remakes::purposes() ) );
		$this->three_purposes();
		$this->assertSame( array( 'fixtures', 'testing', 'donated' ), array_keys( ZPREP_Remakes::purposes() ), 'Core\'s purpose is not added to the business\'s own' );
		$this->assertSame( 'Material testing', ZPREP_Remakes::purpose_label( 'testing' ) );

		// Stored nonsense is no setting: Core's one purpose is offered, never an empty picker.
		$GLOBALS['zdz_test_options'][ ZPREP_Settings::INTERNAL_OPT ] = array( 'purposes' => array( array( 'key' => 'x' ) ), 'default_sheet' => array( 'w' => 500, 'l' => 2 ) );
		$this->assertSame( array( 'general' ), array_keys( ZPREP_Settings::internal_purposes() ) );
		$this->assertNull( ZPREP_Settings::internal_default_sheet() );

		$GLOBALS['zdz_test_options'][ ZPREP_Settings::INTERNAL_OPT ] = array( 'default_sheet' => array( 'w' => '32', 'l' => '24' ) );
		$this->assertSame( array( 'w' => 24, 'l' => 32.0 ), ZPREP_Settings::internal_default_sheet(), 'the shorter side is the width' );

		// A site may also say so in code; what it says is cleaned the same way.
		$GLOBALS['zdz_test_filters']['zprep_internal_purposes']      = static function () {
			return array( array( 'key' => 'donated', 'label' => 'Donated work', 'code' => 'DON' ), array( 'key' => 'bad' ) );
		};
		$GLOBALS['zdz_test_filters']['zprep_internal_default_sheet'] = static function () {
			return array( 'w' => 20, 'l' => 500 );
		};
		$this->assertSame( array( 'donated' ), array_keys( ZPREP_Settings::internal_purposes() ) );
		$this->assertNull( ZPREP_Settings::internal_default_sheet() );
	}

	public function test_a_cost_is_a_small_positive_number_for_every_colour_or_for_one(): void {
		$this->assertSame( array( '*' => 0.0125 ), ZPREP_Settings::clean_cost_rates( '0.0125' ) );
		$this->assertSame( array( 'grey' => 0.01, '*' => 0.02 ), ZPREP_Settings::clean_cost_rates( array( 'Grey' => '0.01', '*' => 0.02, 'sand' => 0, 'rust' => -1, 'teal' => 100, 'not a colour!' => 1, 'blue' => 'cheap' ) ) );
		$this->assertSame( array(), ZPREP_Settings::clean_cost_rates( null ) );
		$this->assertSame( array( '*' => 0.123457 ), ZPREP_Settings::clean_cost_rates( 0.1234567 ), 'kept to six places' );

		$GLOBALS['zdz_test_options'][ ZPREP_Settings::COST_RATES_OPT ] = array( 'grey' => 0.01, '*' => 0.02 );
		$this->assertSame( array( 0.01, 0.02, 0.02 ), array( ZPREP_Settings::material_cost_rate( 'GREY ' ), ZPREP_Settings::material_cost_rate( 'sand' ), ZPREP_Settings::material_cost_rate( '' ) ) );
		$GLOBALS['zdz_test_filters']['zprep_material_cost_rate'] = static function ( $rate, $color ) {
			return 'sand' === $color ? 0.5 : 5000;
		};
		$this->assertSame( array( 0.0, 0.5 ), array( ZPREP_Settings::material_cost_rate( 'grey' ), ZPREP_Settings::material_cost_rate( 'sand' ) ), 'a figure from code is held to the same bounds' );
	}

	public function test_the_settings_page_boxes_become_the_stored_settings(): void {
		$stored = ZPREP_Admin::sanitize_internal_orders( array(
			'purposes_text' => "fixtures,FIX,Shop fixtures\r\n testing , tst , Material testing, small runs \n\nnot a line\nbad,X,Too short a code\n",
			'sheet_w'       => '32',
			'sheet_l'       => '24',
		) );
		$this->assertSame(
			array(
				'purposes'      => array( 'fixtures' => array( 'label' => 'Shop fixtures', 'code' => 'FIX' ), 'testing' => array( 'label' => 'Material testing, small runs', 'code' => 'TST' ) ),
				'default_sheet' => array( 'w' => 24, 'l' => 32.0 ),
			),
			$stored,
			'a label may hold a comma; a line that is not a purpose is left out'
		);
		$this->assertSame( array( 'purposes' => array(), 'default_sheet' => null ), ZPREP_Admin::sanitize_internal_orders( array( 'purposes_text' => '', 'sheet_w' => '', 'sheet_l' => '' ) ), 'emptied boxes store nothing' );
		$this->assertSame( array( 'purposes' => array(), 'default_sheet' => null ), ZPREP_Admin::sanitize_internal_orders( 'junk' ) );
		// What is already stored (a pack wrote it) passes through unchanged, so saving it again changes nothing.
		$this->assertSame( $stored, ZPREP_Admin::sanitize_internal_orders( $stored ) );

		$this->assertSame( array( '*' => 0.02, 'grey' => 0.0125 ), ZPREP_Admin::sanitize_cost_rates( array( 'rates_text' => "*,0.02\ngrey, 0.0125\nsand\nrust,free\n" ) ) );
		$this->assertSame( array(), ZPREP_Admin::sanitize_cost_rates( array( 'rates_text' => '' ) ) );
		$this->assertSame( array( '*' => 0.02 ), ZPREP_Admin::sanitize_cost_rates( array( '*' => 0.02 ) ) );
		$this->assertSame( array( '*' => 0.02 ), ZPREP_Admin::sanitize_cost_rates( '0.02' ) );
	}

	public function test_the_side_numbering_is_the_businesss_own_line_or_cores(): void {
		unset( $GLOBALS['zdz_test_options'][ ZPREP_Settings::SIDES_OPT ] );
		$this->assertSame( ZPREP_Settings::DEFAULT_SIDES, ZPREP_Settings::side_convention() );
		$this->assertSame( 'Side 1 faces the street; the others go clockwise.', ZPREP_Settings::clean_side_convention( "  Side 1 faces the street;\n the others \t go clockwise. " ) );
		$this->assertSame( 300, mb_strlen( ZPREP_Settings::clean_side_convention( str_repeat( 'é', 400 ) ) ) );
		$this->assertSame( '', ZPREP_Settings::clean_side_convention( array( 'x' ) ) );

		$GLOBALS['zdz_test_options'][ ZPREP_Settings::SIDES_OPT ] = 'Side 1 faces the street; the others go clockwise.';
		$this->assertSame( 'Side 1 faces the street; the others go clockwise.', ZPREP_Settings::side_convention() );
		$GLOBALS['zdz_test_filters']['zprep_side_convention'] = function ( $line ) {
			return $line . ' Side 5 is the roof.';
		};
		try {
			$this->assertSame( 'Side 1 faces the street; the others go clockwise. Side 5 is the roof.', ZPREP_Settings::side_convention(), 'code changes it after the setting' );
			$GLOBALS['zdz_test_filters']['zprep_side_convention'] = function () {
				return array();
			};
			$this->assertSame( ZPREP_Settings::DEFAULT_SIDES, ZPREP_Settings::side_convention(), 'nothing usable falls back to Core\'s line' );
		} finally {
			unset( $GLOBALS['zdz_test_filters']['zprep_side_convention'], $GLOBALS['zdz_test_options'][ ZPREP_Settings::SIDES_OPT ] );
		}

		require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/prep/includes/class-zprep-parser.php';
		$GLOBALS['zdz_test_options'][ ZPREP_Settings::SIDES_OPT ] = 'Side A is the street side.';
		try {
			$prompt = ( new ReflectionClass( 'ZPREP_Parser' ) )->newInstanceWithoutConstructor()->system_prompt(); // no Ai client needed to read the prompt
			$this->assertStringContainsString( "SIDE DETECTION:\nSide A is the street side. Extract the numeric side", $prompt );
		} finally {
			unset( $GLOBALS['zdz_test_options'][ ZPREP_Settings::SIDES_OPT ] );
		}

		$plan = ZPREP_Pack::plan( array( 'rules' => array( 'prep' => array( 'side_convention' => "Side 1 faces the street;\nthe rest clockwise." ) ) ) );
		$this->assertSame( array( ZPREP_Settings::SIDES_OPT => 'Side 1 faces the street; the rest clockwise.' ), $plan, 'rules.yml prep.side_convention, cleaned the way the screen cleans it' );
		$rows = ZPREP_Pack::preview( array( 'rules' => array( 'prep' => array( 'side_convention' => 'Side 1 faces the street.' ) ) ) );
		$this->assertSame( array( 'rules', 'prep.side_convention' ), array( $rows[0]['file'], $rows[0]['path'] ) );
		$kept = ZPREP_Pack::snapshot( array( 'rules' => array( 'prep' => array( 'side_convention' => 'Side 1 faces the street.' ) ) ) );
		ZPREP_Pack::apply( array( 'rules' => array( 'prep' => array( 'side_convention' => 'Side 1 faces the street.' ) ) ) );
		$this->assertSame( 'Side 1 faces the street.', get_option( ZPREP_Settings::SIDES_OPT ) );
		ZPREP_Pack::restore( $kept );
		$this->assertFalse( get_option( ZPREP_Settings::SIDES_OPT ), 'a revert removes what was not set before' );
	}

	public function test_an_identity_pack_sets_them_and_can_be_put_back(): void {
		$files = array(
			'rules' => array( 'prep' => array( 'internal_orders' => array(
				'purposes'      => array( array( 'key' => 'fixtures', 'label' => 'Shop fixtures', 'code' => 'FIX' ), array( 'key' => 'bad' ) ),
				'default_sheet' => array( 'w' => 32, 'l' => 24 ),
			) ) ),
			'costs' => array( 'prep' => array( 'material_cost_per_sq_in' => array( '*' => 0.02, 'grey' => 0.0125, 'sand' => 'dear' ) ) ),
		);
		$plan = ZPREP_Pack::plan( $files );
		$this->assertSame(
			array(
				ZPREP_Settings::COST_RATES_OPT => array( '*' => 0.02, 'grey' => 0.0125 ),
				ZPREP_Settings::INTERNAL_OPT   => array( 'purposes' => array( 'fixtures' => array( 'label' => 'Shop fixtures', 'code' => 'FIX' ) ), 'default_sheet' => array( 'w' => 24, 'l' => 32.0 ) ),
			),
			$plan
		);
		$this->assertSame( array( '*' => 0.02 ), ZPREP_Pack::plan( array( 'costs' => array( 'prep' => array( 'material_cost_per_sq_in' => 0.02 ) ) ) )[ ZPREP_Settings::COST_RATES_OPT ], 'one number is every colour' );
		$this->assertSame( array(), ZPREP_Pack::plan( array( 'rules' => array( 'prep' => array() ), 'costs' => array( 'prep' => array() ) ) ), 'a pack that says nothing about them changes nothing' );
		$this->assertSame( array(), ZPREP_Pack::plan( array( 'costs' => array( 'price_book' => array( 'material_cost_per_sq_in' => 1 ) ) ) ), 'only Prep\'s own part of the costs file is read' );

		$consumer = ZPREP_Pack::register( array() )['prep'];
		$this->assertSame( array( 'rules', 'costs' ), $consumer['files'] );
		$this->assertSame( array( 'rules' => array( 'prep' ), 'costs' => array( 'prep' ) ), $consumer['paths'] );

		$preview = ZPREP_Pack::preview( $files );
		$this->assertSame( array( array( 'costs', 'prep.material_cost_per_sq_in' ), array( 'rules', 'prep.internal_orders' ) ), array_map( static function ( $r ) { return array( $r['file'], $r['path'] ); }, $preview ) );
		$this->assertSame( '(none)', $preview[0]['from'] );

		// One was already set by hand; the other was not set at all.
		$GLOBALS['zdz_test_options'][ ZPREP_Settings::COST_RATES_OPT ] = array( '*' => 0.5 );
		$kept = ZPREP_Pack::snapshot( $files );
		$this->assertSame( array( ZPREP_Settings::COST_RATES_OPT => array( '*' => 0.5 ), ZPREP_Settings::INTERNAL_OPT => null ), $kept );

		$this->assertCount( 2, ZPREP_Pack::apply( $files ) );
		$this->assertSame( array( 'fixtures' ), array_keys( ZPREP_Settings::internal_purposes() ) );
		$this->assertSame( array( 'w' => 24, 'l' => 32.0 ), ZPREP_Settings::internal_default_sheet() );
		$this->assertSame( 0.0125, ZPREP_Settings::material_cost_rate( 'grey' ) );
		$this->assertSame( array(), ZPREP_Pack::preview( $files ), 'applied: nothing left to change' );

		ZPREP_Pack::restore( $kept + array( 'siteurl' => 'https://example.com' ) );
		$this->assertSame( array( '*' => 0.5 ), $GLOBALS['zdz_test_options'][ ZPREP_Settings::COST_RATES_OPT ], 'put back as it was' );
		$this->assertArrayNotHasKey( ZPREP_Settings::INTERNAL_OPT, $GLOBALS['zdz_test_options'], 'and "not set at all" is not set at all again' );
		$this->assertArrayNotHasKey( 'siteurl', $GLOBALS['zdz_test_options'], 'an option that is not Prep\'s is never written' );
		$this->assertSame( array( 'general' ), array_keys( ZPREP_Settings::internal_purposes() ) );
	}

	public function test_the_kinds_on_the_form_are_the_catalogs_that_are_cut_as_typed(): void {
		$this->catalog( array(
			'coverplate' => array( 'covers', 'Cover plate', array( 'is_fabricated' => true ) ),
			'endcap'     => array( 'covers', 'End cap', array( 'is_fabricated' => false ) ),   // bought in, not cut
			'plaincover' => array( 'covers', 'Plain cover', array() ),                          // says nothing: a rectangle is cut
			'bracket'    => array( 'hardware', 'Bracket', array( 'is_fabricated' => true ) ),  // another product line
			'oldcover'   => array( 'covers', 'Old cover', array( 'is_fabricated' => true ), 0 ), // no longer offered
		) );
		$this->assertSame( array( 'coverplate' => 'Cover plate', 'plaincover' => 'Plain cover', 'bracket' => 'Bracket', 'custom' => 'Custom' ), ZPREP_Settings::internal_kinds() );

		$GLOBALS['zdz_test_options']['zprep_settings']['queue_subtype'] = 'covers';
		$this->assertSame( array( 'coverplate' => 'Cover plate', 'plaincover' => 'Plain cover', 'custom' => 'Custom' ), ZPREP_Settings::internal_kinds(), 'only the product line this queue is for' );

		// An order holds a kind that is on the form, and no other.
		$ok = $this->saved( array( 'pieces' => array( self::piece( 2, 12, 9, '', 'coverplate' ) ) ) );
		$this->assertSame( 'coverplate', json_decode( $ok['measurements'], true )[0]['kind'] );
		foreach ( array( 'endcap', 'bracket', 'oldcover', 'nosuchkind' ) as $kind ) {
			$e = ZPREP_Remakes::create( self::order( array( 'pieces' => array( self::piece( 1, 12, 9, '', $kind ) ) ) ), self::ALEX );
			$this->assertTrue( is_wp_error( $e ) && 'zprep_internal_kind' === $e->get_error_code(), $kind );
		}

		// A site may add or take away kinds in code. Custom is always there, last, and is not renamed.
		$GLOBALS['zdz_test_filters']['zprep_internal_kinds'] = static function ( $kinds ) {
			unset( $kinds['plaincover'] );
			return $kinds + array( 'trim-strip' => 'Trim strip', 'Not A Slug' => 'Dropped', 'blank' => ' ', 'custom' => 'Renamed' );
		};
		$this->assertSame( array( 'coverplate' => 'Cover plate', 'trim-strip' => 'Trim strip', 'custom' => 'Custom' ), ZPREP_Settings::internal_kinds() );
	}

	public function test_the_form_is_sent_what_the_business_set(): void {
		$this->three_purposes();
		$GLOBALS['zdz_test_options'][ ZPREP_Settings::INTERNAL_OPT ]['default_sheet'] = array( 'w' => 24, 'l' => 32 );
		$GLOBALS['zdz_test_options']['zprep_settings']['rolls'][]                    = array( 'width_in' => 48, 'color' => 'grey', 'available' => false );
		$form = new ReflectionMethod( 'ZPREP_App', 'internal_form_data' );
		$form->setAccessible( true );

		$GLOBALS['zdz_test_current_user'] = self::ALEX;
		$data                             = $form->invoke( new ZPREP_App() );
		$this->assertSame( array( 'purposes', 'kinds', 'sheet', 'widths', 'reportUrl' ), array_keys( $data ) );
		$this->assertSame( array( array( 'key' => 'fixtures', 'label' => 'Shop fixtures' ), array( 'key' => 'testing', 'label' => 'Material testing' ), array( 'key' => 'donated', 'label' => 'Donated work' ) ), $data['purposes'], 'the codes stay on the server' );
		$this->assertSame( array( array( 'id' => 'custom', 'label' => 'Custom' ) ), $data['kinds'] );
		$this->assertSame( array( 'w' => 24, 'l' => 32.0 ), $data['sheet'] );
		$this->assertSame( array( 36, 60 ), $data['widths'], 'the rolls the shop has, each once' );
		$this->assertStringContainsString( 'page=' . ZPREP_Internal_Report::SLUG, $data['reportUrl'] );

		// A manager by role makes orders, but is not sent the address of the page that shows money.
		$GLOBALS['zdz_test_current_user'] = self::CASEY;
		$this->assertSame( '', $form->invoke( new ZPREP_App() )['reportUrl'] );

		// Nothing set: one purpose, an empty sheet size.
		unset( $GLOBALS['zdz_test_options'][ ZPREP_Settings::INTERNAL_OPT ] );
		$bare = $form->invoke( new ZPREP_App() );
		$this->assertSame( array( array( 'key' => 'general', 'label' => 'Internal use' ) ), $bare['purposes'] );
		$this->assertNull( $bare['sheet'] );
	}

	public function test_an_open_internal_order_in_the_list_is_a_job_to_cut(): void {
		$now     = 1800000000;
		$payload = array(
			'configured' => true,
			'jobs'       => array(
				array( 'kind' => 'internal', 'status' => 'open', 'job_key' => 'remake:1' ),
				array( 'kind' => 'internal', 'status' => 'cut', 'job_key' => 'remake:2' ),    // an assignee's card after the cut
				array( 'kind' => 'remake', 'status' => 'open', 'job_key' => 'remake:3' ),
				array( 'lead_id' => 71, 'install_ts' => $now + 86400 ),
				array( 'lead_id' => 72 ),
				array( 'lead_id' => 73, 'fb_possible' => true ),
			),
		);
		$this->assertSame( array( 'to_cut' => 3, 'this_week' => 1, 'possible' => 1 ), ZPREP_Queue_Counts::counts_from( $payload, $now ), 'the open order counts; it has no install day; the cut one and the remake do not' );
		$without = $payload;
		array_splice( $without['jobs'], 0, 3 );
		$this->assertSame( array( 'to_cut' => 2, 'this_week' => 1, 'possible' => 1 ), ZPREP_Queue_Counts::counts_from( $without, $now ), 'the customer jobs count exactly as they did' );
		$this->assertNull( ZPREP_Queue_Counts::counts_from( array( 'configured' => false, 'jobs' => array() ), $now ) );
		$this->assertStringContainsString( 'internal orders', ZPREP_Queue_Counts::definitions()['to_cut'], 'and the count says what it holds' );
	}

	public function test_the_count_is_read_again_for_the_person_who_asked(): void {
		ZPREP_Queue_Counts::request_refresh( self::JORDAN );
		ZPREP_Queue_Counts::request_refresh( self::SAM );
		ZPREP_Queue_Counts::request_refresh( 0 );
		$this->assertSame( array( self::JORDAN, self::SAM ), $GLOBALS['zdz_test_scheduled'], 'a read is asked for, for a person' );

		// The read itself: each person's own list, counted for them.
		$this->saved();
		$this->assign( self::SAM, $this->saved() );
		ZPREP_Queue_Counts::$now   = 1800000000;
		ZPREP_Queue_Counts::$build = static function ( int $uid ): array {
			$shop = ZPREP_Dashboard::shop_jobs_for( $uid );
			return array( 'configured' => true, 'stage' => '', 'jobs' => array_merge( $shop['top'], $shop['tail'] ) );
		};
		foreach ( array( self::ALEX, self::JORDAN, self::SAM, self::RILEY, self::DEVICE ) as $uid ) {
			ZPREP_Queue_Counts::refresh_for( $uid );
		}
		$kept = $GLOBALS['zdz_test_options'][ ZPREP_Queue_Counts::OPTION ];
		$this->assertSame( array( self::ALEX => 2, self::JORDAN => 1, self::SAM => 2, self::DEVICE => 0 ), array_map( static function ( $k ) { return $k['counts']['to_cut']; }, $kept ), 'a manager: every open order; anyone else: their own and nobody\'s; the shared device: none' );
		$this->assertArrayNotHasKey( self::RILEY, $kept, 'no count is kept for a person without Prep' );
	}
}
