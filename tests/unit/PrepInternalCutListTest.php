<?php
/**
 * Internal orders in Prep's cut list: who is shown which shop jobs, who may work one, and how a cut is recorded.
 * The accounts are the six of ZPrepIo_Case; the orders, assignments and cut ledger are in a real database.
 *
 *   - a manager is shown every open internal order; anyone else with Prep, their own and nobody's; a person who
 *     holds only assignments, the jobs assigned to them; the shared device, none;
 *   - a card is shown exactly to those whose press it will take (the list and the permission agree);
 *   - holding an assignment does not narrow what a person with Prep may work;
 *   - "Mark as cut" records every bench piece under the person who pressed, counts the sheets, and can be taken
 *     back; a plan that leaves a piece off is not recorded as cut; nothing is written to the CRM or to billing;
 *   - the requests themselves: the nonce, the app, Privacy View, the manager-only ones, and one answer for
 *     "not there" and "not yours".
 *
 * @package Zorderz\Tests
 */

require_once __DIR__ . '/support/prep-internal-doubles.php';
require_once __DIR__ . '/support/prep-plan-checks.php';

final class PrepInternalCutListTest extends ZPrepIo_Case {

	const EVERYONE = array( self::ALEX, self::JORDAN, self::SAM, self::RILEY, self::DEVICE, self::CASEY );

	/** Run one request as one person; hand back what it sent ([ ok, status, data ]). */
	private function ask( string $handler, int $uid, array $post = array(), string $nonce = 'good' ): array {
		if ( ! zprepio_doubles_are_live( 'wp_send_json_error', 'wp_send_json_success', 'check_ajax_referer' ) ) {
			$this->markTestSkipped( 'another test file defined the JSON reply functions first' );
		}
		$GLOBALS['zdz_test_current_user'] = $uid;
		$_POST                            = array_merge( array( 'nonce' => $nonce ), $post );
		$level                            = ob_get_level();
		$sent                             = array( null, 0, 'the handler sent nothing' );
		try {
			call_user_func( array( 'ZPREP_Dashboard', $handler ) );
		} catch ( ZPrepIo_Stop $stop ) {
			$sent = array( $stop->ok, $stop->status, $stop->data );
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean(); // the request guard opens a buffer for its reply
			}
			$_POST                            = array();
			$GLOBALS['zdz_test_current_user'] = 0;
		}
		return $sent;
	}

	private static function job( array $row ): string {
		return ZPREP_Remakes::job_key( (int) $row['id'] );
	}

	private static function keys( array $cards ): array {
		return array_column( $cards, 'job_key' );
	}

	private function remake( string $name = 'Example Co' ): array {
		$row = ZPREP_Remakes::create( array( 'customer_label' => $name, 'pieces' => array( array( 'qty' => 2, 'width' => 10, 'height' => 12 ) ) ), self::ALEX );
		$this->assertIsArray( $row );
		return $row;
	}

	public function test_who_is_what(): void {
		$this->assertSame(
			array( 'manager', 'team', 'team', 'none', 'team', 'manager' ),
			array_map( array( 'ZPREP_Dashboard', 'queue_mode' ), self::EVERYONE )
		);
		$this->assertTrue( ZPREP_Assignments::is_shared_device( self::DEVICE ) );
		// A job assigned to a person without Prep lets them in, for that job.
		$this->assign( self::RILEY, $this->saved() );
		$this->assertSame( 'assignee', ZPREP_Dashboard::queue_mode( self::RILEY ) );
		$this->assertTrue( ZPREP_Dashboard::user_can_access( self::RILEY ) );
		$this->assertFalse( ZPREP_Dashboard::base_access( self::RILEY ), 'which is not Prep of their own' );
	}

	public function test_the_cut_list_by_person(): void {
		$free    = $this->saved( array( 'customer_label' => 'Jig covers' ) );
		$jordans = $this->saved( array( 'customer_label' => 'Booth panels' ) );
		$rileys  = $this->saved( array( 'customer_label' => 'Rack labels' ) );
		$cut     = $this->saved( array( 'customer_label' => 'Test strips' ) );
		$gone    = $this->saved( array( 'customer_label' => 'Saw guard covers' ) );
		$this->assign( self::JORDAN, $jordans );
		$this->assign( self::RILEY, $rileys );
		ZPREP_Remakes::set_status( (int) $cut['id'], 'cut' );
		ZPREP_Remakes::set_status( (int) $gone['id'], 'void' );
		$sams_remake = $this->remake();
		$free_remake = $this->remake();
		ZPREP_Assignments::create( self::ALEX, self::SAM, self::job( $sams_remake ), 'Example Co', 'remake' );

		$alex = ZPREP_Dashboard::shop_jobs_for( self::ALEX );
		$this->assertSame( array( self::job( $rileys ), self::job( $jordans ), self::job( $free ) ), self::keys( $alex['top'] ), 'a manager: every open internal order, newest first' );
		$this->assertSame( array( self::job( $free_remake ), self::job( $sams_remake ) ), self::keys( $alex['tail'] ), 'and the remakes, after the customer jobs as before' );
		$this->assertSame( $alex, ZPREP_Dashboard::shop_jobs_for( self::CASEY ), 'a manager by role sees the same' );

		$jordan = ZPREP_Dashboard::shop_jobs_for( self::JORDAN );
		$this->assertSame( array( self::job( $jordans ), self::job( $free ) ), self::keys( $jordan['top'] ), 'someone with Prep: their own and nobody\'s, never another person\'s' );
		$this->assertSame( array(), $jordan['tail'] );

		$sam = ZPREP_Dashboard::shop_jobs_for( self::SAM );
		$this->assertSame( array( self::job( $free ), self::job( $sams_remake ) ), self::keys( $sam['top'] ), 'and a remake that is theirs; not one that is nobody\'s' );

		$riley = ZPREP_Dashboard::shop_jobs_for( self::RILEY );
		$this->assertSame( array( self::job( $rileys ) ), self::keys( $riley['top'] ), 'someone who only holds assignments: those, and not the unassigned orders' );

		$this->assertSame( array( 'top' => array(), 'tail' => array() ), ZPREP_Dashboard::shop_jobs_for( self::DEVICE ), 'the shared device is shown no shop job, with Prep or without' );
		$this->assertSame( array( 'top' => array(), 'tail' => array() ), ZPREP_Dashboard::shop_jobs_for( 0 ) );

		// The list a screen is sent: the shop's own jobs first, marked so the screen keeps them above its fold; then
		// the customer jobs; then a manager's remakes, where they were.
		$customers = array(
			array( 'lead_id' => 71, 'estimate_number' => '1001', 'customer' => 'Example Co' ),
			array( 'lead_id' => 72, 'estimate_number' => '1002', 'customer' => 'Example Co' ),
		);
		$q = $this->list_sent_to( self::JORDAN, $customers );
		$this->assertTrue( $q['configured'] );
		$this->assertSame( array( self::job( $jordans ), self::job( $free ), '', '' ), array_map( static function ( $j ) { return (string) ( $j['job_key'] ?? '' ); }, $q['jobs'] ) );
		$this->assertSame( array( true, true, false, false ), self::pinned( $q['jobs'] ) );
		$qa = $this->list_sent_to( self::ALEX, $customers );
		$this->assertSame( array( true, true, true, false, false, false, false ), self::pinned( $qa['jobs'] ), 'a manager\'s remakes stay after the customer jobs' );
		$this->assertSame( array( 'internal', 'internal', 'internal', '', '', 'remake', 'remake' ), array_map( static function ( $j ) { return (string) ( $j['kind'] ?? '' ); }, $qa['jobs'] ) );

		// The shop's own jobs do not come from the CRM: with none set up they are still listed, and a list with
		// nothing in it still says the CRM is not set up.
		$this->assertTrue( $this->list_sent_to( self::JORDAN, array(), false )['configured'] );
		$this->assertFalse( $this->list_sent_to( self::DEVICE, array(), false )['configured'] );
		foreach ( array_merge( $q['jobs'], $qa['jobs'] ) as $card ) {
			$this->assertStringNotContainsString( 'sell', json_encode( $card ) );
		}
	}

	/** The cut list as a screen is sent it, from the customer jobs given (the CRM is not asked). */
	private function list_sent_to( int $uid, array $customer_jobs, bool $crm_set_up = true ): array {
		$finish = new ReflectionMethod( 'ZPREP_Dashboard', 'finish_queue' );
		$finish->setAccessible( true );
		return $finish->invoke( null, array( 'configured' => $crm_set_up, 'stage' => '', 'jobs' => $customer_jobs, 'trace' => array() ), $uid, ZPREP_Dashboard::queue_mode( $uid ) );
	}

	private static function pinned( array $jobs ): array {
		return array_map( static function ( $j ) {
			return ! empty( $j['pinned'] );
		}, $jobs );
	}

	public function test_a_card_is_shown_to_exactly_those_who_may_work_it(): void {
		$free    = $this->saved();
		$jordans = $this->saved();
		$rileys  = $this->saved();
		$this->assign( self::JORDAN, $jordans );
		$this->assign( self::RILEY, $rileys );
		$remake = $this->remake();
		ZPREP_Assignments::create( self::ALEX, self::SAM, self::job( $remake ), 'Example Co', 'remake' );
		foreach ( array( $free, $jordans, $rileys, $remake ) as $row ) {
			foreach ( self::EVERYONE as $uid ) {
				$jobs  = ZPREP_Dashboard::shop_jobs_for( $uid );
				$shown = in_array( self::job( $row ), self::keys( array_merge( $jobs['top'], $jobs['tail'] ) ), true );
				$this->assertSame( $shown, ZPREP_Dashboard::can_work_remake( $uid, (int) $row['id'] ), "job {$row['id']}, user {$uid}" );
			}
		}
	}

	public function test_who_may_work_a_shop_job(): void {
		$free = $this->saved();
		$id   = (int) $free['id'];
		$may  = static function ( int $rid ): array {
			return array_values( array_filter( self::EVERYONE, static function ( $uid ) use ( $rid ) {
				return ZPREP_Dashboard::can_work_remake( $uid, $rid );
			} ) );
		};
		$this->assertSame( array( self::ALEX, self::JORDAN, self::SAM, self::CASEY ), $may( $id ), 'open and nobody\'s: any Prep user; never a person without Prep, never the shared device' );

		// Once it is cut it has left the list: the person who cut it may still take that back, nobody else but a manager.
		ZPREP_Dashboard::mark_remake_cut( $free, self::JORDAN );
		$this->assertSame( 'cut', ZPREP_Remakes::get( $id )['status'] );
		$this->assertSame( array( self::ALEX, self::JORDAN, self::CASEY ), $may( $id ) );
		ZPREP_Remakes::set_status( $id, 'done' );
		$this->assertSame( array( self::ALEX, self::CASEY ), $may( $id ) );

		// Somebody's: theirs and a manager's.
		$jordans = $this->saved();
		$this->assign( self::JORDAN, $jordans );
		$this->assertSame( array( self::ALEX, self::JORDAN, self::CASEY ), $may( (int) $jordans['id'] ) );
		$rileys = $this->saved();
		$this->assign( self::RILEY, $rileys );
		$this->assertSame( array( self::ALEX, self::RILEY, self::CASEY ), $may( (int) $rileys['id'] ), 'a person without Prep, for the job they were given' );

		// A remake that is nobody's is not everyone's.
		$this->assertSame( array( self::ALEX, self::CASEY ), $may( (int) $this->remake()['id'] ) );
		$this->assertSame( array(), $may( 0 ) );
		$this->assertSame( array( self::ALEX, self::CASEY ), $may( 999 ), '(a manager is not refused here for a job that is not there: the request finds none)' );

		// The shared device is never given work, and is never the one who did it: even assigned by hand.
		$this->db->insert( ZPREP_Assignments::table_name(), array( 'created_at' => '2026-01-05 08:00:00', 'assigned_by' => self::ALEX, 'assignee_id' => self::DEVICE, 'job_key' => 'remake:' . $rileys['id'], 'visibility' => 'hidden' ) );
		ZPREP_Assignments::flush_memo();
		$this->assertFalse( ZPREP_Dashboard::can_work_remake( self::DEVICE, (int) $rileys['id'] ) );
		$this->assertTrue( is_wp_error( ZPREP_Assignments::create( self::ALEX, self::DEVICE, self::job( $free ), '', 'internal' ) ) );
	}

	public function test_one_rule_for_a_shop_job_whichever_request_asks(): void {
		$free    = $this->saved();
		$jordans = $this->saved();
		$this->assign( self::JORDAN, $jordans );
		$scope = static function ( int $uid, array $row ): bool {
			return ZPREP_Dashboard::job_scope_allows( $uid, 0, self::job( $row ) );
		};
		$this->assertTrue( $scope( self::SAM, $free ) );
		$this->assertFalse( $scope( self::SAM, $jordans ), 'another person\'s order cannot be opened by posting its key' );
		$this->assertTrue( $scope( self::JORDAN, $jordans ) );
		$this->assertTrue( $scope( self::ALEX, $jordans ) );
		$this->assertFalse( $scope( self::RILEY, $free ) );
		$this->assertFalse( $scope( self::DEVICE, $free ) );
		$this->assertFalse( ZPREP_Dashboard::job_scope_allows( 0, 0, self::job( $free ) ) );
		$this->assertFalse( ZPREP_Dashboard::job_scope_allows( self::SAM, 0, 'remake:999' ) );
	}

	public function test_holding_an_assignment_does_not_narrow_a_person_with_prep(): void {
		$this->assign( self::JORDAN, $this->saved() );
		$this->assertSame( 'team', ZPREP_Dashboard::queue_mode( self::JORDAN ), 'still everyone\'s list and the search, not only the assigned job' );
		$this->assertTrue( ZPREP_Dashboard::job_scope_allows( self::JORDAN, 77, '1001' ), 'a customer job that is nobody\'s' );

		// A person without Prep works only what they were given.
		$rileys = $this->saved();
		$this->assign( self::RILEY, $rileys );
		$this->assertFalse( ZPREP_Dashboard::job_scope_allows( self::RILEY, 77, '1001' ) );
		ZPREP_Assignments::create( self::ALEX, self::RILEY, 'est:1001', 'Example Co', '' );
		$this->assertTrue( ZPREP_Dashboard::job_scope_allows( self::RILEY, 77, '1001' ) );
		$this->assertTrue( ZPREP_Dashboard::job_scope_allows( self::RILEY, 0, self::job( $rileys ) ) );
	}

	public function test_marking_cut_and_taking_it_back(): void {
		$row = $this->saved(); // six 20 x 15 on 24 x 32 sheets: three sheets
		$id  = (int) $row['id'];
		$out = ZPREP_Dashboard::mark_remake_cut( $row, self::JORDAN );
		$this->assertSame( array( 1, true, false, '' ), array( $out['changed'], $out['complete'], $out['nothing'], $out['refused'] ) );
		$this->assertSame( 'cut', $out['remake']['status'] );
		$this->assertSame( 3, $out['remake']['sheets_used'], 'the sheets the plan takes are counted when it is cut' );
		$this->assertSame( array( 'complete', self::JORDAN, array( self::JORDAN ) ), array( $out['cut_state']['state'], (int) $out['cut_state']['cut_by'], array_map( 'intval', $out['cut_state']['cut_by_all'] ) ), 'under the person who pressed' );
		$ledger = ZPREP_Cut_State::rows_for_job( self::job( $row ) );
		$this->assertSame( array( '6', '6', 'shop' ), array( (string) $ledger[0]['qty_total'], (string) $ledger[0]['qty_cut'], $ledger[0]['cut_where'] ) );
		$this->assertSame( array(), ZPREP_Remakes::list_open_internal(), 'and it has left the cut list' );

		$again = ZPREP_Dashboard::mark_remake_cut( ZPREP_Remakes::get( $id ), self::SAM );
		$this->assertSame( array( 0, true ), array( $again['changed'], $again['complete'] ), 'pressing twice records nothing more' );
		$this->assertSame( self::JORDAN, (int) $again['cut_state']['cut_by'] );

		$back = ZPREP_Dashboard::mark_remake_cut( ZPREP_Remakes::get( $id ), self::JORDAN, true );
		$this->assertSame( array( 'open', 0, false ), array( $back['remake']['status'], $back['remake']['sheets_used'], $back['complete'] ) );
		$this->assertSame( 'uncut', $back['cut_state']['state'] );
		$this->assertSame( array( $id ), array_map( 'intval', array_column( ZPREP_Remakes::list_open_internal(), 'id' ) ), 'it is back in the list' );

		// Cut from a roll: marked the same way, and no sheets are counted.
		$roll = $this->saved( array( 'stock' => 'roll', 'sheet_w' => '', 'sheet_l' => '' ) );
		$out  = ZPREP_Dashboard::mark_remake_cut( $roll, self::SAM );
		$this->assertSame( array( true, 'cut', 0 ), array( $out['complete'], $out['remake']['status'], $out['remake']['sheets_used'] ) );
	}

	public function test_a_remake_can_be_marked_cut_too(): void {
		$row = $this->remake();
		ZPREP_Assignments::create( self::ALEX, self::SAM, self::job( $row ), 'Example Co', 'remake' );
		$out = ZPREP_Dashboard::mark_remake_cut( $row, self::SAM );
		$this->assertSame( array( true, 'cut', 'remake' ), array( $out['complete'], $out['remake']['status'], $out['remake']['kind'] ) );
		$this->assertArrayNotHasKey( 'sheets_used', $out['remake'] );
		$this->assertSame( array( self::job( $row ) ), self::keys( ZPREP_Dashboard::shop_jobs_for( self::ALEX )['tail'] ), 'a manager still sees it, as cut' );
		$this->assertSame( array(), ZPREP_Dashboard::shop_jobs_for( self::SAM )['top'], 'it has left the list of the person who cut it' );
	}

	public function test_a_job_with_long_pieces_still_to_cut_is_not_called_cut(): void {
		$row = ZPREP_Remakes::create( array( 'customer_label' => 'Example Co', 'pieces' => array( array( 'qty' => 2, 'width' => 10, 'height' => 12 ), array( 'qty' => 1, 'width' => 20, 'height' => 80 ) ) ), self::ALEX );
		$out = ZPREP_Dashboard::mark_remake_cut( $row, self::ALEX );
		$this->assertSame( array( 1, false, false, 'open' ), array( $out['changed'], $out['complete'], $out['nothing'], $out['remake']['status'] ) );
		$this->assertSame( array( 2, 2 ), array( (int) $out['cut_state']['bench']['total'], (int) $out['cut_state']['bench']['cut'] ), 'the bench pieces are marked' );
	}

	public function test_a_plan_that_leaves_a_piece_off_is_not_recorded_as_cut(): void {
		// Two 40 x 38 pieces come out of the wide roll only. The shop then stops stocking it.
		$row = $this->saved( array( 'stock' => 'roll', 'sheet_w' => '', 'sheet_l' => '', 'force_roll' => 60, 'pieces' => array( self::piece( 2, 40, 38 ) ) ) );
		$GLOBALS['zdz_test_options']['zprep_settings']['rolls'] = array( array( 'width_in' => 36, 'color' => 'grey', 'available' => true ) );
		$out = ZPREP_Dashboard::mark_remake_cut( $row, self::ALEX );
		$this->assertSame( array( 0, false, false ), array( $out['changed'], $out['complete'], $out['nothing'] ) );
		$this->assertStringContainsString( 'leaves 2 pieces off its sheets', $out['refused'] );
		$this->assertSame( 'open', ZPREP_Remakes::get( (int) $row['id'] )['status'] );
		foreach ( ZPREP_Cut_State::rows_for_job( self::job( $row ) ) as $r ) {
			$this->assertSame( 0, (int) $r['qty_cut'] );
		}
	}

	public function test_an_order_with_nothing_on_its_plan_has_nothing_to_mark(): void {
		$this->db->insert( ZPREP_Remakes::table_name(), array( 'created_at' => '2026-01-04 08:00:00', 'kind' => 'internal', 'purpose_code' => 'general', 'customer_label' => 'Rack labels', 'measurements' => '[]', 'status' => 'open' ) );
		$out = ZPREP_Dashboard::mark_remake_cut( ZPREP_Remakes::get( (int) $this->db->insert_id ), self::ALEX );
		$this->assertSame( array( 0, false, true, 'open' ), array( $out['changed'], $out['complete'], $out['nothing'], $out['remake']['status'] ) );
	}

	public function test_the_plan_of_an_order_is_the_orders_own(): void {
		$row = $this->saved();
		$this->assertSame( array( 'w' => 24, 'l' => 32.0 ), ZPREP_Dashboard::stock_sheet_for_job( self::job( $row ) ) );
		$this->assertNull( ZPREP_Dashboard::stock_sheet_for_job( self::job( $this->remake() ) ), 'a remake has no sheet' );
		$this->assertNull( ZPREP_Dashboard::stock_sheet_for_job( '1001' ), 'nor has a customer job' );
		ZPREP_Remakes::set_status( (int) $row['id'], 'void' );
		$this->assertNull( ZPREP_Dashboard::internal_row_for_job( self::job( $row ) ), 'a removed order is not planned' );

		// The request may say anything: other rows, a roll, the leftover library. The order's own rows and stock are planned.
		$mine = $this->saved();
		$sent = $this->ask( 'ajax_compute_cuts', self::JORDAN, array(
			'measurements'  => json_encode( array( array( 'kind' => 'custom', 'qty' => 40, 'width_in' => 5, 'height_in' => 5, 'shape' => 'rectangle', 'color' => 'sand' ) ) ),
			'source_job'    => self::job( $mine ),
			'lead_id'       => 0,
			'use_leftovers' => '1',
			'force_roll'    => 60,
			'workspace'     => 'roller',
			'customer_name' => 'Example Co',
		) );
		$this->assertTrue( $sent[0] );
		$plan = $sent[2];
		$this->assertSame( array( 'w' => 24, 'l' => 32.0 ), $plan['stock_sheet'] );
		$this->assertSame( 6, zprepio_plan_placed( $plan ), 'the six pieces the order holds, not the forty that were posted' );
		$this->assertSame( array( 'grey' ), array_values( array_unique( array_column( $plan['pages'], 'color' ) ) ) );
		$this->assertSame( 'uncut', $plan['cut_state']['state'], 'and its cut ledger is ready' );
		$this->assertSame( array(), array_filter( $this->db->log, static function ( $sql ) { return false !== strpos( $sql, ZPREP_Leftovers::table_name() ); } ), 'the leftover library is neither read nor added to' );
		$this->assertContains( array( 'cut_plan_computed', 0 ), $GLOBALS['zdz_test_prep_usage'] );
	}

	public function test_the_requests_check_the_nonce_the_app_and_the_person(): void {
		$free    = $this->saved();
		$jordans = $this->saved();
		$this->assign( self::JORDAN, $jordans );
		$mark = static function ( array $row ): array {
			return array( 'remake_id' => (int) $row['id'] );
		};

		$this->assertSame( array( false, 403 ), array_slice( $this->ask( 'ajax_remake_mark_cut', self::ALEX, $mark( $free ), 'stale' ), 0, 2 ), 'no nonce, no request' );
		$this->assertSame( array( false, 403, array( 'message' => 'Access denied.' ) ), $this->ask( 'ajax_remake_mark_cut', self::RILEY, $mark( $free ) ), 'without Prep' );
		$this->assertSame( array( false, 403, array( 'message' => 'Access denied.' ) ), $this->ask( 'ajax_remake_mark_cut', 0, $mark( $free ) ), 'signed out' );

		// One answer for "not there", "removed" and "not yours".
		$none = array( false, 404, array( 'message' => 'That job was not found.' ) );
		$this->assertSame( $none, $this->ask( 'ajax_remake_mark_cut', self::SAM, $mark( $jordans ) ), 'somebody else\'s' );
		$this->assertSame( $none, $this->ask( 'ajax_remake_mark_cut', self::DEVICE, $mark( $free ) ), 'the shared device' );
		$this->assertSame( $none, $this->ask( 'ajax_remake_mark_cut', self::ALEX, array( 'remake_id' => 999 ) ) );
		$this->assertSame( $none, $this->ask( 'ajax_remake_mark_cut', self::ALEX, array() ) );
		$this->assertSame( 'open', ZPREP_Remakes::get( (int) $jordans['id'] )['status'] );
		$this->assertSame( array(), $GLOBALS['zdz_test_prep_usage'], 'a refused press records nothing' );

		// Privacy View closes Prep to the account it is on, an administrator's included.
		$GLOBALS['zdz_test_user_meta'][ self::ALEX ][ ZDZ_Privacy_View::META ] = '1';
		$this->assertSame( array( false, 403, array( 'message' => 'Access denied.' ) ), $this->ask( 'ajax_remake_mark_cut', self::ALEX, $mark( $free ) ) );
		unset( $GLOBALS['zdz_test_user_meta'][ self::ALEX ][ ZDZ_Privacy_View::META ] );

		// Anyone with Prep records an order that is nobody's; the home screen's count is asked for again.
		$sent = $this->ask( 'ajax_remake_mark_cut', self::SAM, $mark( $free ) );
		$this->assertTrue( $sent[0] );
		$this->assertSame( array( 'remake', 'cut_state', 'changed', 'complete', 'nothing', 'refused' ), array_keys( $sent[2] ) );
		$this->assertSame( array( 'cut', true ), array( $sent[2]['remake']['status'], $sent[2]['complete'] ) );
		$this->assertSame( array( array( 'cut_marked', 0 ) ), $GLOBALS['zdz_test_prep_usage'] );
		$this->assertSame( array( self::SAM ), $GLOBALS['zdz_test_scheduled'] );

		// "Not cut yet": by the person who cut it or a manager, not by another Prep user.
		$undo = array( 'remake_id' => (int) $free['id'], 'undo' => '1' );
		$this->assertSame( $none, $this->ask( 'ajax_remake_mark_cut', self::JORDAN, $undo ) );
		$this->assertSame( 'open', $this->ask( 'ajax_remake_mark_cut', self::SAM, $undo )[2]['remake']['status'] );
		$this->assertSame( array( array( 'cut_marked', 0 ) ), $GLOBALS['zdz_test_prep_usage'], 'taking it back is not counted as a cut' );

		// A removed order.
		ZPREP_Remakes::set_status( (int) $free['id'], 'void' );
		$this->assertSame( $none, $this->ask( 'ajax_remake_mark_cut', self::ALEX, $mark( $free ) ) );

		// Opening a plan asks the same question.
		$open = static function ( array $row ): array {
			return array( 'measurements' => '[]', 'source_job' => self::job( $row ), 'lead_id' => 0 );
		};
		$this->assertSame( array( false, 403, array( 'message' => 'You can only work the jobs assigned to you.' ) ), $this->ask( 'ajax_compute_cuts', self::SAM, $open( $jordans ) ) );
		$this->assertTrue( $this->ask( 'ajax_compute_cuts', self::JORDAN, $open( $jordans ) )[0] );
	}

	public function test_hours_are_taken_from_whoever_may_work_the_order(): void {
		$jordans = $this->saved();
		$this->assign( self::JORDAN, $jordans );
		$post = array( 'remake_id' => (int) $jordans['id'], 'hours' => '2.25' );
		$none = array( false, 404, array( 'message' => 'That order was not found.' ) );
		$this->assertSame( $none, $this->ask( 'ajax_internal_set_labor', self::SAM, $post ) );
		$this->assertSame( $none, $this->ask( 'ajax_internal_set_labor', self::DEVICE, $post ) );
		$this->assertSame( array( false, 403 ), array_slice( $this->ask( 'ajax_internal_set_labor', self::RILEY, $post ), 0, 2 ) );
		$this->assertSame( 0, (int) ZPREP_Remakes::get( (int) $jordans['id'] )['labor_minutes'] );

		$sent = $this->ask( 'ajax_internal_set_labor', self::JORDAN, $post );
		$this->assertTrue( $sent[0] );
		$this->assertSame( array( 2.25, true, 'Jordan' ), array( $sent[2]['remake']['labor_hours'], $sent[2]['remake']['labor_set'], $sent[2]['remake']['labor_by_name'] ) );
		$bad = $this->ask( 'ajax_internal_set_labor', self::JORDAN, array( 'remake_id' => (int) $jordans['id'], 'hours' => 'a while' ) );
		$this->assertSame( array( false, 'Hours is a number from 0 to 1000.' ), array( $bad[0], $bad[2]['message'] ) );
		$this->assertSame( $none, $this->ask( 'ajax_internal_set_labor', self::ALEX, array( 'remake_id' => (int) $this->remake()['id'], 'hours' => '1' ) ), 'a remake keeps no hours' );
	}

	public function test_making_assigning_and_removing_are_a_managers(): void {
		$form = self::order();
		$form['pieces'] = json_encode( $form['pieces'] );
		$refused        = array( false, 403, array( 'message' => 'Only a manager can do that.' ) );
		$this->assertSame( $refused, $this->ask( 'ajax_remake_create', self::JORDAN, $form ) );
		$this->assertSame( array( false, 403 ), array_slice( $this->ask( 'ajax_remake_create', self::RILEY, $form ), 0, 2 ) );
		$this->assertSame( array(), ZPREP_Remakes::list_open_internal() );

		$sent = $this->ask( 'ajax_remake_create', self::ALEX, $form + array( 'assignee_id' => self::JORDAN ) );
		$this->assertTrue( $sent[0] );
		$card = $sent[2]['remake'];
		$this->assertSame( array( 'internal', 'Jig covers', self::JORDAN, 'Jordan' ), array( $card['kind'], $card['title'], $card['assignee_id'], $card['assignee_name'] ) );
		$this->assertSame( 'internal', ZPREP_Assignments::active_for_job( $card['job_key'] )['reason'] );
		$this->assertSame( array( $card['job_key'] ), self::keys( ZPREP_Dashboard::shop_jobs_for( self::JORDAN )['top'] ) );

		// The form's own wording comes back when an order is refused.
		$bad = $this->ask( 'ajax_remake_create', self::ALEX, array_merge( $form, array( 'customer_label' => '' ) ) );
		$this->assertSame( array( false, 'Give the order a name.' ), array( $bad[0], $bad[2]['message'] ) );

		// Removing: a manager's, and the person it was given to is not left holding it.
		$void = array( 'remake_id' => $card['remake_id'], 'status' => 'void' );
		$this->assertSame( $refused, $this->ask( 'ajax_remake_set_status', self::JORDAN, $void ) );
		$this->assertTrue( $this->ask( 'ajax_remake_set_status', self::ALEX, $void )[0] );
		ZPREP_Assignments::flush_memo();
		$this->assertNull( ZPREP_Assignments::active_for_job( $card['job_key'] ) );
		$this->assertSame( array(), ZPREP_Dashboard::shop_jobs_for( self::JORDAN )['top'] );
		$this->assertFalse( ZPREP_Assignments::has_live_grant( self::JORDAN ) );

		// The manager's window lists every order that is not removed; anyone else is sent only their own cards.
		$kept = $this->saved();
		ZPREP_Remakes::set_status( (int) $kept['id'], 'cut' );
		$list = $this->ask( 'ajax_remakes_list', self::ALEX );
		$this->assertSame( array( self::job( $kept ) ), self::keys( $list[2]['remakes'] ) );
		$this->assertSame( array(), $this->ask( 'ajax_remakes_list', self::SAM )[2]['remakes'] );
	}
}
