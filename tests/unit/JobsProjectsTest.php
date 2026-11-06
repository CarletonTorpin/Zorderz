<?php
/**
 * The jobs app's published faces and deciders:
 *
 *   - ZJOB_Scope: who may see which job rows (tiers; the shared device and nobody signed in see nothing; a filter can
 *     never widen past a well-formed scope; salesperson initials are their own dimension).
 *   - ZJOB_Install_Date: the three-state envelope, the per-row gate failing closed, the drift subscriber writing only
 *     rows whose time changed (and calling a row "moved" only when its start moved), a deleted appointment breaking
 *     the link, and the lead-reference reconcile sweep.
 *   - ZJOB_Project_Orders / ZJOB_Order_Gate / ZJOB_Supplier_Invoices: PO numbers, shop-stock approval, who may confirm
 *     and sign off, the comparison of two confirmations, the go sign from the agreement, matching an invoice by PO.
 *   - ZJOB_Repair_Log: when a logged repair joins a known home, and when it stays on its own.
 *   - jobs.handoff is a write: the verb registry returns needs_confirm and never runs it.
 *   - ZJOB_Accept_Notice words and recipients; ZJOB_Sealed's "on it today" rule; ZJOB_Privacy_View shaping.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $uid, $key, $single = false ) { return $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] ?? ''; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ); }
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $k ) { return $GLOBALS['zdz_test_transients'][ $k ] ?? false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $e = 0 ) { $GLOBALS['zdz_test_transients'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
}
if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string() { return 'UTC'; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( $k, $v = '', $d = '', $autoload = null ) {
		if ( isset( $GLOBALS['zdz_test_options'][ $k ] ) ) {
			return false;
		}
		$GLOBALS['zdz_test_options'][ $k ] = $v;
		return true;
	}
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

/** A scripted stand-in for $wpdb: reads answered by pattern, writes recorded. */
class ZJOB_Test_W6_Wpdb {
	public $prefix     = 'wp_';
	public $last_error = '';
	public $insert_id  = 0;
	public $reads      = array(); // [ [ regex, value-or-callable ], ... ]
	public $updates    = array();
	public $inserts    = array();
	public $queries    = array();

	public function prepare( $q, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback( '/%[dsf]/', function ( $m ) use ( &$i, $args ) {
			$v = $args[ $i++ ] ?? '';
			return '%s' === $m[0] ? "'" . addslashes( (string) $v ) . "'" : (string) ( 0 + $v );
		}, $q );
	}
	private function read( $sql, $default ) {
		foreach ( $this->reads as $r ) {
			if ( preg_match( $r[0], $sql ) ) {
				return is_callable( $r[1] ) ? call_user_func( $r[1], $sql ) : $r[1];
			}
		}
		return $default;
	}
	public function get_var( $sql ) { return $this->read( $sql, null ); }
	public function get_col( $sql ) { return $this->read( $sql, array() ); }
	public function get_row( $sql, $o = null ) { return $this->read( $sql, null ); }
	public function get_results( $sql, $o = null ) { return $this->read( $sql, array() ); }
	public function update( $t, $data, $where, $f = null, $wf = null ) { $this->updates[] = array( $t, $data, $where ); return 1; }
	public function insert( $t, $data, $f = null ) { $this->inserts[] = array( $t, $data ); return ++$this->insert_id; }
	public function query( $sql ) { $this->queries[] = $sql; return 1; }
	public function esc_like( $s ) { return addcslashes( (string) $s, '_%\\' ); }
	public function get_charset_collate() { return ''; }
}

$zjob_inc = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/includes/';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-hierarchy.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-capabilities.php';
require_once $zjob_inc . 'class-zjob-db.php';
require_once $zjob_inc . 'class-zjob-scope.php';
require_once $zjob_inc . 'class-zjob-project.php';
require_once $zjob_inc . 'class-zjob-appointment-link.php';
require_once $zjob_inc . 'class-zjob-install-date.php';
require_once $zjob_inc . 'class-zjob-handoff.php';
require_once $zjob_inc . 'class-zjob-project-refs.php';
require_once $zjob_inc . 'class-zjob-order-gate.php';
require_once $zjob_inc . 'class-zjob-project-orders.php';
require_once $zjob_inc . 'class-zjob-supplier-invoices.php';
require_once $zjob_inc . 'class-zjob-repair-log.php';
require_once $zjob_inc . 'class-zjob-accept-notice.php';
require_once $zjob_inc . 'class-zjob-sealed.php';
require_once $zjob_inc . 'class-zjob-privacy-view.php';
require_once $zjob_inc . 'class-zjob-chat-bridge.php';
$zsch_appts = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/scheduler/includes/class-zsch-appointments.php';
if ( ! class_exists( 'ZSCH_Appointments' ) ) {
	require_once $zsch_appts;
}

final class JobsProjectsTest extends TestCase {

	private $saved_wpdb = null;

	protected function setUp(): void {
		$this->saved_wpdb  = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = new ZJOB_Test_W6_Wpdb();
		ZJOB_Scope::flush_memo();
		Zjob_Install_Date::flush_memo();
		ZJOB_Project_Orders::flush_memo();
		ZJOB_Order_Gate::forget();
		ZJOB_Sealed::flush_memo();
		ZDZ_Capabilities::flush();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->saved_wpdb;
		unset( $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_user_roles'], $GLOBALS['zdz_test_user_meta'],
			$GLOBALS['zdz_test_current_user'], $GLOBALS['zdz_test_user_caps'], $GLOBALS['zdz_test_users'], $GLOBALS['zdz_test_user_names'],
			$GLOBALS['zdz_test_transients'] );
		ZJOB_Scope::flush_memo();
		ZDZ_Capabilities::flush();
	}

	/** People: 1 admin, 2 production, 3 salesperson "AB", 4 field tech, 5 the shared device. */
	private function people(): void {
		$GLOBALS['zdz_test_user_roles'] = array( 1 => array( 'administrator' ), 2 => array( 'zdz_mfg' ), 3 => array( 'zdz_sales' ), 4 => array( 'zdz_tech' ), 5 => array( 'zdz_general' ) );
		$GLOBALS['zdz_test_user_caps']  = array( 1 => array( 'manage_options' ) );
		$GLOBALS['zdz_test_user_meta']  = array( 3 => array( 'zdz_user_initials' => 'ab' ), 4 => array( 'zdz_user_initials' => 'Q' ) );
	}

	private function row( int $id, array $o = array() ): array {
		return array_merge( array(
			'id' => $id, 'parent_lead_id' => 0, 'crm_child_lead_id' => 0, 'estimate_id' => 0, 'created_by' => 9, 'assigned_user_id' => 9,
			'sp_code' => '', 'status' => 'open', 'scheduled_appt_id' => 0, 'scheduled_start_utc' => null, 'scheduled_end_utc' => null, 'scheduled_tz' => '',
		), $o );
	}

	/* ── Scope ────────────────────────────────────────────────────────────── */

	public function test_scope_nobody_and_the_shared_device_see_nothing(): void {
		$this->people();
		$this->assertSame( 'none', ZJOB_Scope::for_user( 0 )['tier'] );
		$this->assertSame( 'none', ZJOB_Scope::for_user( 5 )['tier'] );
		$this->assertFalse( ZJOB_Scope::can_see( 5, $this->row( 1, array( 'created_by' => 5 ) ) ), 'even its own row' );
		// A filter is never offered the shared device.
		$GLOBALS['zdz_test_filters']['zjob_scope'] = function ( $s ) { return array( 'tier' => 'all', 'sees_all' => true ); };
		ZJOB_Scope::flush_memo();
		$this->assertSame( 'none', ZJOB_Scope::for_user( 5 )['tier'] );
		$this->assertSame( array( '1 = 0', array() ), ZJOB_Scope::scope_predicate( ZJOB_Scope::for_user( 0 ) ) );
	}

	public function test_scope_tiers_and_initials(): void {
		$this->people();
		$this->assertSame( 'all', ZJOB_Scope::for_user( 1 )['tier'] );
		$this->assertSame( 'shop', ZJOB_Scope::for_user( 2 )['tier'] );
		$this->assertNull( ZJOB_Scope::scope_predicate( ZJOB_Scope::for_user( 2 ) ), 'the shop sees the whole floor' );
		$own = ZJOB_Scope::for_user( 3 );
		$this->assertSame( 'own', $own['tier'] );
		$this->assertSame( array( 'AB' ), $own['sp_codes'] );
		$this->assertTrue( ZJOB_Scope::can_see( 3, $this->row( 1, array( 'sp_code' => 'ab' ) ) ), 'a sale keeps sight of its job after hand-off' );
		$this->assertTrue( ZJOB_Scope::can_see( 3, $this->row( 2, array( 'assigned_user_id' => 3 ) ) ) );
		$this->assertFalse( ZJOB_Scope::can_see( 3, $this->row( 3 ) ) );
		$this->assertSame( array(), ZJOB_Scope::sp_codes_for( 4 ), 'a one-letter code is never a scope key' );
		$pred = ZJOB_Scope::scope_predicate( $own );
		$this->assertStringContainsString( 'sp_code IN', $pred[0] );
	}

	public function test_scope_filter_fails_closed(): void {
		$this->people();
		$GLOBALS['zdz_test_filters']['zjob_scope'] = function ( $s ) { return array( 'tier' => 'everything' ); };
		$this->assertSame( 'own', ZJOB_Scope::for_user( 4 )['tier'], 'a malformed answer is ignored' );
		ZJOB_Scope::flush_memo();
		$GLOBALS['zdz_test_filters']['zjob_scope'] = function ( $s ) { return array( 'tier' => 'own', 'user_ids' => array( 4 ), 'sees_all' => true ); };
		$s = ZJOB_Scope::for_user( 4 );
		$this->assertFalse( $s['sees_all'], '"sees all" rides only the tiers that mean it' );
		$this->assertFalse( ZJOB_Scope::can_see( 4, $this->row( 7 ) ) );
	}

	/* ── Install date ─────────────────────────────────────────────────────── */

	public function test_install_date_three_states(): void {
		$sched = Zjob_Install_Date::pick( array(
			$this->row( 1, array( 'scheduled_appt_id' => 11, 'scheduled_start_utc' => '2030-05-02 16:00:00', 'scheduled_tz' => 'UTC' ) ),
			$this->row( 2, array( 'scheduled_appt_id' => 12, 'scheduled_start_utc' => '2030-05-01 16:00:00', 'scheduled_tz' => 'UTC' ) ),
		) );
		$this->assertSame( 'scheduled', $sched['status'] );
		$this->assertTrue( Zjob_Install_Date::is_scheduled( $sched ) );
		$this->assertSame( '2030-05-01', $sched['date'], 'earliest wins' );
		$this->assertSame( 2, $sched['job_id'] );
		$this->assertTrue( $sched['spread'] );
		$this->assertSame( 2, $sched['job_count'] );

		$un = Zjob_Install_Date::pick( array(
			$this->row( 3 ),
			$this->row( 4, array( 'scheduled_appt_id' => 0, 'scheduled_start_utc' => '2030-05-01 16:00:00' ) ),
			$this->row( 5, array( 'scheduled_appt_id' => 13, 'scheduled_start_utc' => '0000-00-00 00:00:00' ) ),
		) );
		$this->assertSame( 'unscheduled', $un['status'], 'a time with no appointment, or a zeroed time, is not a booking' );
		$this->assertNull( $un['date'] );
		$this->assertSame( 'unknown', $un['state'] );

		$unknown = Zjob_Install_Date::pick( array() );
		$this->assertSame( 'unknown', $unknown['status'] );
		$this->assertNotSame( Zjob_Install_Date::paper_line( $un ), Zjob_Install_Date::paper_line( $unknown ), 'the two nothings print differently' );

		$dead = Zjob_Install_Date::pick( array( $this->row( 6, array( 'status' => 'done', 'scheduled_appt_id' => 14, 'scheduled_start_utc' => '2030-05-01 16:00:00' ) ), $this->row( 7, array( 'status' => 'cancelled' ) ) ) );
		$this->assertSame( 'not_applicable', $dead['status'], 'done and cancelled jobs never carry a live date' );
	}

	public function test_install_date_gate_runs_per_row_and_fails_closed(): void {
		$this->people();
		$GLOBALS['wpdb']->reads[] = array( '/FROM wp_zdz_jobs WHERE parent_lead_id IN \(4242\)/', array(
			$this->row( 1, array( 'parent_lead_id' => 4242, 'created_by' => 4, 'assigned_user_id' => 4 ) ),
			// Someone else's job on the same lead, scheduled: its date must not reach the tech.
			$this->row( 2, array( 'parent_lead_id' => 4242, 'created_by' => 9, 'assigned_user_id' => 9, 'scheduled_appt_id' => 21, 'scheduled_start_utc' => '2030-06-01 15:00:00' ) ),
		) );
		$tech = Zjob_Install_Date::for_leads( array( 4242 ), 4 );
		$this->assertSame( 'unscheduled', $tech['4242']['status'], 'the visible row has no date; the other row gives none' );
		$this->assertSame( 1, $tech['4242']['job_count'], 'a row the viewer may not see is not even counted' );

		$admin = Zjob_Install_Date::for_leads( array( 4242 ), 1 );
		$this->assertSame( 'scheduled', $admin['4242']['status'] );

		$this->assertSame( 'unknown', Zjob_Install_Date::for_leads( array( 4242 ), 0 )['4242']['status'], 'nobody signed in' );
		$this->assertSame( 'unknown', Zjob_Install_Date::for_leads( array( 4242 ), 5 )['4242']['status'], 'the shared device' );
		$this->assertSame( array(), Zjob_Install_Date::visible_rows( 0, array( $this->row( 1 ) ) ) );
		$this->assertArrayHasKey( '77', Zjob_Install_Date::for_leads( array( 77 ), 1 ), 'every requested key is answered' );
	}

	public function test_drift_plan_only_moved_starts_are_moves(): void {
		$raw  = array( 'start_utc' => '2030-07-01 16:00:00', 'end_utc' => '2030-07-01 18:00:00', 'time_zone' => 'UTC' );
		$plan = Zjob_Install_Date::drift_plan( array(
			array( 'id' => 1, 'scheduled_start_utc' => '2030-07-01 16:00:00', 'scheduled_end_utc' => '2030-07-01 18:00:00', 'scheduled_tz' => 'UTC' ),
			array( 'id' => 2, 'scheduled_start_utc' => '2030-07-01 16:00:00', 'scheduled_end_utc' => '2030-07-01 17:00:00', 'scheduled_tz' => 'UTC' ),
			array( 'id' => 3, 'scheduled_start_utc' => '2030-06-30 16:00:00', 'scheduled_end_utc' => '2030-06-30 18:00:00', 'scheduled_tz' => 'UTC' ),
		), $raw );
		$this->assertSame( array( 2, 3 ), array_column( $plan, 'id' ), 'an unchanged row is not written' );
		$this->assertFalse( $plan[0]['moved'], 'only the end changed' );
		$this->assertTrue( $plan[1]['moved'] );
		$this->assertSame( '2030-06-30 16:00:00', $plan[1]['from'] );
	}

	public function test_drift_subscriber_writes_only_changed_rows(): void {
		$db          = $GLOBALS['wpdb'];
		$db->reads[] = array( '/FROM wp_zsch_appointments WHERE id = 31/', array( 'id' => 31, 'start_utc' => '2030-08-02 15:00:00', 'end_utc' => '2030-08-02 17:00:00', 'time_zone' => 'UTC', 'status' => 'confirmed', 'deleted_at' => null ) );
		$db->reads[] = array( '/SELECT id, scheduled_start_utc, scheduled_end_utc, scheduled_tz FROM wp_zdz_jobs WHERE scheduled_appt_id = 31/', array(
			array( 'id' => 8, 'scheduled_start_utc' => '2030-08-02 15:00:00', 'scheduled_end_utc' => '2030-08-02 17:00:00', 'scheduled_tz' => 'UTC' ),
			array( 'id' => 9, 'scheduled_start_utc' => '2030-08-01 15:00:00', 'scheduled_end_utc' => '2030-08-01 17:00:00', 'scheduled_tz' => 'UTC' ),
		) );
		Zjob_Install_Date::on_appointment_changed( 31 );
		$this->assertCount( 1, $db->updates );
		$this->assertSame( array( 'id' => 9 ), $db->updates[0][2] );
		$this->assertSame( '2030-08-02 15:00:00', $db->updates[0][1]['scheduled_start_utc'] );

		// A cancelled appointment is a delete: the link is broken on every job that cached it.
		$db2             = new ZJOB_Test_W6_Wpdb();
		$GLOBALS['wpdb'] = $db2;
		$db2->reads[]    = array( '/FROM wp_zsch_appointments WHERE id = 32/', array( 'id' => 32, 'start_utc' => '2030-08-02 15:00:00', 'status' => 'cancelled' ) );
		$db2->reads[]    = array( '/SELECT id FROM wp_zdz_jobs WHERE scheduled_appt_id = 32/', array( 5, 6 ) );
		Zjob_Install_Date::on_appointment_changed( 32 );
		$this->assertCount( 2, $db2->updates );
		$this->assertSame( 0, $db2->updates[0][1]['scheduled_appt_id'] );
		$this->assertNull( $db2->updates[1][1]['scheduled_start_utc'] );
	}

	public function test_reconcile_sweep_writes_the_lead_ref_once_the_estimate_has_one(): void {
		$db  = $GLOBALS['wpdb'];
		$pid = '01HZZZZZZZZZZZZZZZZZZZZZZZ';
		$GLOBALS['zdz_test_filters']['zdz_work_item_ref_namespaces'] = array( 'Zjob_Project', 'register_namespaces' );
		$db->reads[] = array( '/SELECT er.work_item_id/', array( $pid ) );
		$db->reads[] = array( "/WHERE work_item_id = '" . $pid . "' AND `system` = 'estimate'/", array( array( 'external_id' => '7' ) ) );
		// No lead id on the estimate yet: nothing is written, and the Project stays a candidate.
		$this->assertSame( 0, Zjob_Install_Date::reconcile_lead_refs( 10 ) );
		$this->assertCount( 0, $db->inserts );
		// The lead arrives: the next pass writes the reference.
		$GLOBALS['zdz_test_filters']['zdz_estimate_lead_id'] = function ( $v, $eid ) { return 7 === (int) $eid ? 4242 : 0; };
		$this->assertSame( 1, Zjob_Install_Date::reconcile_lead_refs( 10 ) );
		$this->assertCount( 1, $db->inserts );
		$this->assertSame( 'lead', $db->inserts[0][1]['system'] );
		$this->assertSame( '4242', $db->inserts[0][1]['external_id'] );
	}

	/* ── Material orders ─────────────────────────────────────────────────── */

	public function test_po_comes_from_the_job_and_never_from_nothing(): void {
		$s = ZJOB_Project_Orders::po_settings();
		$this->assertSame( 'PO-1042', ZJOB_Project_Orders::po_from( '1042', $s ) );
		$this->assertSame( '', ZJOB_Project_Orders::po_from( '', $s ), 'no invoice, no PO' );
		$this->assertSame( '', ZJOB_Project_Orders::po_from( '0000', $s ), 'all zeros is no number' );
		$GLOBALS['zdz_test_options']['zjob_order_po'] = array( 'prefix' => 'ex', 'sep' => '', 'drop_zeros' => 1 );
		$this->assertSame( 'EX1042', ZJOB_Project_Orders::po_from( '001042', ZJOB_Project_Orders::po_settings() ) );
		$this->assertTrue( ZJOB_Project_Orders::po_required( 'materials' ), 'every supplier requires a PO unless the business says no' );
		$draft = array( 'id' => 3, 'project_id' => 'p1', 'form' => 'materials', 'po' => '', 'status' => 'open', 'body' => array() );
		$this->assertNotSame( '', ZJOB_Project_Orders::po_refusal( $draft ) );
	}

	public function test_shop_stock_needs_a_yes_for_the_order_as_it_stands(): void {
		$body  = array( 'units' => array( array( 'k' => 'u1', 'item' => 'Mesh roll', 'qty' => '2' ) ), 'parts' => array(), 'notes' => 'Restock' );
		$order = array( 'id' => 57, 'project_id' => 'stock', 'form' => 'materials', 'status' => 'open', 'body' => $body, 'approval' => array( 'state' => 'asked' ) );
		$this->assertSame( 'asked', ZJOB_Project_Orders::stock_state( $order ) );
		$this->assertSame( '', ZJOB_Project_Orders::stock_po( $order ), 'no PO before the yes' );
		$order['approval'] = array( 'state' => 'approved', 'body' => md5( wp_json_encode( ZJOB_Project_Orders::approval_basis( $body ) ) ) );
		$this->assertSame( 'PO-STOCK-57', ZJOB_Project_Orders::stock_po( $order ) );
		$order['body']['units'][0]['qty'] = '6';
		$this->assertSame( 'asked', ZJOB_Project_Orders::stock_state( $order ), 'changed since the yes: asked again' );
	}

	public function test_who_may_confirm_and_sign_off(): void {
		$this->people();
		$GLOBALS['zdz_test_options']['zjob_order_operators'] = array( 'ids' => array( 4 ) );
		$this->assertSame( array( 'seller' ), ZJOB_Project_Orders::sides_for( 3, 'related' ) );
		$this->assertSame( array(), ZJOB_Project_Orders::sides_for( 3, 'oversight' ), 'oversight alone is read-only' );
		$this->assertSame( array(), ZJOB_Project_Orders::sides_for( 5, 'all' ), 'never the shared device' );
		$this->assertSame( array( 'operator' ), ZJOB_Project_Orders::sides_for( 4, 'none' ) );

		$order = array( 'id' => 1, 'created_by' => 4, 'rev' => 2, 'form' => 'materials', 'status' => 'open', 'po' => 'PO-1', 'body' => array(), 'seller_ok' => null, 'operator_ok' => null );
		$this->assertFalse( ZJOB_Project_Orders::may_sign( $order, 4, array( 'operator' ), false ), 'an operator\'s own order needs a second check' );
		$GLOBALS['zdz_test_options']['zjob_order_operators']['self_ok'] = array( 4 );
		$this->assertTrue( ZJOB_Project_Orders::may_sign( $order, 4, array( 'operator' ), false ) );

		$order['seller_ok'] = array( 'by' => 4, 'rev' => 2, 'po' => 'PO-1', 'body' => array() );
		$this->assertFalse( ZJOB_Project_Orders::may_confirm( $order, 'operator', 4, array( 'seller', 'operator' ), false ), 'one person never confirms both sides' );
		$this->assertTrue( ZJOB_Project_Orders::may_confirm( $order, 'operator', 1, array( 'seller', 'operator' ), true ), 'an administrator may' );
	}

	public function test_two_confirmations_are_compared_in_words(): void {
		$a = array( 'units' => array( array( 'k' => 'u1', 'item' => 'Door kit', 'qty' => '1', 'size' => '36 x 80' ), array( 'k' => 'u2', 'item' => 'Handle' ) ), 'notes' => '', 'kind' => 'new' );
		$b = array( 'units' => array( array( 'k' => 'u1', 'item' => 'Door kit', 'qty' => '2', 'size' => '36 x 80' ), array( 'k' => 'u3', 'item' => 'Sill' ) ), 'notes' => '', 'kind' => 'addon' );
		$d = ZJOB_Project_Orders::compare( 'materials', $a, $b );
		$what = array_column( $d, 'what' );
		$this->assertContains( 'Quantity', $what );
		$this->assertContains( 'added', $what );
		$this->assertContains( 'removed', $what );
		$this->assertContains( 'For', $what );
		$this->assertStringContainsString( '"1" -> "2"', ZJOB_Project_Orders::diff_words( $d ) );
		$this->assertTrue( ZJOB_Project_Orders::same( 'materials', $a, $a ) );
	}

	public function test_an_order_is_placed_only_when_everything_says_so(): void {
		$snap  = array( 'by' => 4, 'rev' => 3, 'po' => 'PO-9', 'body' => array() );
		$order = array( 'id' => 2, 'created_by' => 3, 'rev' => 3, 'form' => 'materials', 'status' => 'open', 'po' => 'PO-9', 'body' => array(), 'seller_ok' => null, 'operator_ok' => $snap );
		$this->assertSame( '', ZJOB_Project_Orders::order_refusal( $order, 4, array( 'operator' ), false, '' ) );
		$this->assertSame( 'Wait', ZJOB_Project_Orders::order_refusal( $order, 4, array( 'operator' ), false, 'Wait' ), 'the go sign holds it' );
		$this->assertNotSame( '', ZJOB_Project_Orders::order_refusal( $order, 3, array( 'seller' ), false, '' ), 'a seller never places it' );
		$stale = array_merge( $order, array( 'po' => 'PO-10' ) );
		$this->assertNotSame( '', ZJOB_Project_Orders::order_refusal( $stale, 4, array( 'operator' ), false, '' ), 'confirmed on another PO' );
		$nopo = array_merge( $order, array( 'po' => '', 'operator_ok' => array_merge( $snap, array( 'po' => '' ) ) ) );
		$this->assertNotSame( '', ZJOB_Project_Orders::order_refusal( $nopo, 4, array( 'operator' ), false, '' ), 'no PO, still a draft' );
	}

	public function test_forms_are_data_and_prefill_never_guesses(): void {
		$this->assertNull( ZJOB_Project_Orders::clean_form( array( 'label' => 'Empty' ) ), 'a form needs fields' );
		$rows = ZJOB_Project_Orders::prefill( 'materials', array(
			array( 'desc' => 'Door kit', 'sub' => 'white', 'qty' => 2, 'size' => '36 x 80' ),
			array( 'desc' => '', 'qty' => 1 ),
		) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'Door kit', $rows[0]['item'] );
		$this->assertSame( '2', $rows[0]['qty'] );
		$this->assertSame( 'estimate', $rows[0]['src'] );
		$this->assertArrayNotHasKey( 'colour', $rows[0], 'a colour is never guessed from the words' );
	}

	/* ── The go sign ──────────────────────────────────────────────────────── */

	public function test_gate_reads_the_agreement_answer(): void {
		list( $a, $failed, $denied ) = ZJOB_Order_Gate::decode( array( 'success' => true, 'denied' => true ) );
		$this->assertNull( $a );
		$this->assertTrue( $denied );
		$this->assertFalse( $failed );
		list( $a, $failed ) = ZJOB_Order_Gate::decode( array( 'success' => true, 'error' => true ) );
		$this->assertNull( $a );
		$this->assertTrue( $failed, 'a failed read is allowed, and noted' );
		list( $a ) = ZJOB_Order_Gate::decode( array( 'success' => true, 'ok' => true, 'state' => 'draft', 'unsignable_why' => 'language_no' ) );
		$this->assertNull( $a, 'a decision releases an unsigned agreement' );
		list( $a ) = ZJOB_Order_Gate::decode( array( 'success' => true, 'ok' => true, 'state' => 'ready', 'signable' => true ) );
		$this->assertSame( 'unsigned', ZJOB_Order_Gate::reason( $a ) );
		list( $a ) = ZJOB_Order_Gate::decode( array( 'success' => true, 'ok' => true, 'state' => 'signed', 'cancel_by' => '2030-11-06' ) );
		$this->assertStringContainsString( 'November 6', ZJOB_Order_Gate::words( $a ) );
	}

	public function test_gate_decides_over_a_projects_agreements(): void {
		$signed   = array( 'state' => 'window_closed', 'ok_to_order' => true, 'cancel_by' => '', 'change_pending' => false, 'why' => '', 'signed_at' => '2030-01-10T10:00:00Z', 'made_at' => '2030-01-01T10:00:00Z', 'signed_via' => 'online' );
		$option   = array( 'state' => 'ready', 'ok_to_order' => false, 'cancel_by' => '', 'change_pending' => false, 'why' => '', 'signed_at' => '', 'made_at' => '2030-01-02T10:00:00Z', 'signed_via' => '' );
		$later    = array_merge( $option, array( 'made_at' => '2030-01-20T10:00:00Z' ) );
		$cancel   = array_merge( $signed, array( 'state' => 'cancelled', 'ok_to_order' => false ) );
		$this->assertTrue( ZJOB_Order_Gate::decide( array( $signed, $option ) )['ok_to_order'], 'an unsigned option beside a signed one does not hold' );
		$this->assertFalse( ZJOB_Order_Gate::decide( array( $signed, $later ) )['ok_to_order'], 'one made after the signature is a second sale' );
		$d = ZJOB_Order_Gate::decide( array( $later, $cancel ) );
		$this->assertSame( 'cancelled', $d['state'], 'a cancellation is said first' );
		$t = ZJOB_Order_Gate::texts();
		$this->assertSame( $t['cancel'], ZJOB_Order_Gate::warranty_words( $t['cancel'] ) );
		$this->assertSame( $t['warranty'], ZJOB_Order_Gate::warranty_words( $t['unsigned'] ) );
	}

	/* ── Supplier invoices ────────────────────────────────────────────────── */

	public function test_an_invoice_matches_by_its_po_alone(): void {
		$s = array( 'prefix' => 'PO', 'stock_prefix' => 'STOCK' );
		$c = ZJOB_Supplier_Invoices::classify( 'po-001042', $s );
		$this->assertSame( 'job', $c['kind'] );
		$this->assertSame( 'p1', ZJOB_Supplier_Invoices::decide( $c, array( 'p1', 'p1' ) )['project_id'] );
		$this->assertSame( '', ZJOB_Supplier_Invoices::decide( $c, array( 'p1', 'p2' ) )['project_id'], 'two jobs: flagged, never picked' );
		$this->assertSame( '', ZJOB_Supplier_Invoices::decide( $c, array() )['project_id'] );
		$this->assertSame( 'none', ZJOB_Supplier_Invoices::classify( '', $s )['kind'] );
		$this->assertSame( 'foreign', ZJOB_Supplier_Invoices::classify( 'Picked up by Sam', $s )['kind'] );
		$st = ZJOB_Supplier_Invoices::classify( 'PO-STOCK-57', $s );
		$this->assertSame( 'stock', $st['kind'] );
		$this->assertSame( 57, ZJOB_Supplier_Invoices::decide( $st, array(), 57 )['stock_order_id'] );
	}

	/* ── Log a repair ─────────────────────────────────────────────────────── */

	public function test_a_repair_joins_a_home_only_on_good_evidence(): void {
		$houses = array( '12 oak st' => array( 'points' => array( array( 40.0, -75.0 ) ), 'home_ids' => array( 7 ) ) );
		$near   = array( 'lat' => 40.0003, 'lng' => -75.0, 'acc' => 20.0 );
		$this->assertSame( 'no_fix', ZJOB_Repair_Log::decide_join( array(), '12 Oak St', $houses )['why'] );
		$this->assertSame( 'no_accuracy', ZJOB_Repair_Log::decide_join( array( array( 'lat' => 40.0, 'lng' => -75.0, 'acc' => null ) ), '12 Oak St', $houses )['why'] );
		$this->assertSame( 'vague', ZJOB_Repair_Log::decide_join( array( array( 'lat' => 40.0, 'lng' => -75.0, 'acc' => 400.0 ) ), '12 Oak St', $houses )['why'] );
		$this->assertSame( 'no_street', ZJOB_Repair_Log::decide_join( array( $near ), 'Oak Street', $houses )['why'] );
		$this->assertSame( 'no_house', ZJOB_Repair_Log::decide_join( array( $near ), '14 Oak St', $houses )['why'] );
		$this->assertSame( 'far', ZJOB_Repair_Log::decide_join( array( array( 'lat' => 40.05, 'lng' => -75.0, 'acc' => 10.0 ) ), '12 Oak St', $houses )['why'] );
		$two = array( '12 oak st' => array( 'points' => array( array( 40.0, -75.0 ) ), 'home_ids' => array( 7, 8 ) ) );
		$this->assertSame( 'two_homes', ZJOB_Repair_Log::decide_join( array( $near ), '12 Oak St', $two )['why'] );
		$ok = ZJOB_Repair_Log::decide_join( array( $near ), '12 Oak Street, Springfield', $houses );
		$this->assertSame( '', $ok['why'] );
		$this->assertSame( 7, $ok['home_id'] );
		$this->assertSame( ZJOB_Repair_Log::addr_key( '12 oak st' ), ZJOB_Repair_Log::addr_key( '12 Oak Street, Unit 4, Springfield' ) );
		$v = ZJOB_Repair_Log::visits( array(
			array( 'media_id' => 3, 'at' => '2030-01-01 15:00:00', 'lat' => 40.0, 'lng' => -75.0 ),
			array( 'media_id' => 2, 'at' => '2030-01-01 14:50:00', 'lat' => 40.0, 'lng' => -75.0 ),
			array( 'media_id' => 1, 'at' => '2030-01-01 11:00:00', 'lat' => 40.0, 'lng' => -75.0 ),
		) );
		$this->assertCount( 2, $v, 'more than an hour apart is two visits' );
	}

	public function test_logging_a_repair_is_off_until_turned_on(): void {
		$this->people();
		$this->assertFalse( ZJOB_Repair_Log::can( 4 ) );
		$GLOBALS['zdz_test_options']['zjob_log_repair'] = 1;
		$this->assertTrue( ZJOB_Repair_Log::can( 4 ) );
		$this->assertFalse( ZJOB_Repair_Log::can( 5 ), 'never the shared device' );
		$GLOBALS['zdz_test_filters']['zjob_log_repair_allowed'] = function () { return true; };
		$GLOBALS['zdz_test_options']['zjob_log_repair']        = 0;
		$this->assertFalse( ZJOB_Repair_Log::can( 4 ), 'a filter may narrow, never switch it on' );
	}

	/* ── The handoff verb ─────────────────────────────────────────────────── */

	public function test_handoff_verb_is_a_write_that_waits_for_confirmation(): void {
		$this->people();
		$GLOBALS['zdz_test_current_user'] = 3;
		$ran = new ArrayObject();
		$GLOBALS['zdz_test_filters']['zdz_register_capabilities'] = function ( $caps ) use ( $ran ) {
			$caps['jobs.handoff'] = array(
				'callable'   => function ( $a ) use ( $ran ) { $ran['yes'] = true; return array( 'success' => true ); },
				'descriptor' => ZJOB_Chat_Bridge::get_capability_descriptor(),
			);
			return $caps;
		};
		ZDZ_Capabilities::flush();
		$row = ZDZ_Capabilities::get( 'jobs.handoff' );
		$this->assertTrue( $row['side_effect'] );
		// a person who may open the Jobs app (when the app registry is loaded it checks that first)
		$GLOBALS['zdz_test_user_meta'][3]['zdz_allowed_apps'] = array( $row['app'] );
		$out = ZDZ_Capabilities::invoke( 'jobs.handoff', array( 'component' => 'other' ) );
		$this->assertTrue( ! empty( $out['needs_confirm'] ), 'a write is never run from the registry' );
		$this->assertArrayNotHasKey( 'yes', $ran->getArrayCopy() );
	}

	/* ── Notices ──────────────────────────────────────────────────────────── */

	public function test_accept_notice_words_follow_what_happened(): void {
		$GLOBALS['zdz_test_user_meta'] = array( 4 => array( 'first_name' => 'Robin' ) );
		$row = array( 'id' => 9, 'doc_number' => '1042', 'customer_name' => 'Jordan Example', 'accepted_by' => 4 );
		$this->assertStringContainsString( 'marked accepted by Robin', ZJOB_Accept_Notice::words( $row, 'manual', array() )['title'] );
		$this->assertStringContainsString( 'Your customer accepted estimate #1042', ZJOB_Accept_Notice::words( $row, 'billing', array( 'who' => 'customer' ) )['title'] );
		$this->assertStringContainsString( 'turned into an invoice', ZJOB_Accept_Notice::words( $row, 'billing', array( 'billing_state' => 'invoiced' ) )['title'] );
		$nobody = ZJOB_Accept_Notice::words( $row, 'billing', array( 'billing_state' => 'accepted' ), true );
		$this->assertStringContainsString( 'No salesperson found on estimate #1042', $nobody['title'] );
		$this->assertStringContainsString( '(test)', ZJOB_Accept_Notice::words( array( 'customer_name' => 'Test Customer' ), 'billing', array() )['title'] );
		$this->assertStringNotContainsString( 'customer accepted', ZJOB_Accept_Notice::words( $row, 'billing', array( 'billing_state' => 'accepted' ) )['title'], 'never "your customer" unless the estimate app says so' );
	}

	public function test_accept_notice_falls_back_to_a_review_item(): void {
		$this->people();
		$GLOBALS['zdz_test_users'] = array( (object) array( 'ID' => 1, 'roles' => array( 'administrator' ) ) );
		$who = ZJOB_Accept_Notice::recipients( '', array( 'accepted_by' => 0 ), 'billing', array( 'salesperson_uids' => array( 5 ) ) );
		$this->assertTrue( $who['nobody'], 'the shared device is never the salesperson' );
		$this->assertSame( array( 1 ), $who['people'] );
		$who2 = ZJOB_Accept_Notice::recipients( '', array( 'accepted_by' => 3 ), 'manual', array( 'salesperson_uids' => array( 3 ) ) );
		$this->assertNotContains( 3, $who2['people'], 'the person who recorded a manual accept knows already' );
		$this->assertSame( 'AB', ZJOB_Accept_Notice::adopt_code( array( 'salesperson_initials' => array( 'ab' ) ) ) );
		$this->assertSame( '', ZJOB_Accept_Notice::adopt_code( array( 'salesperson_initials' => array( 'AB', 'CD' ) ) ), 'two codes: no guess' );
	}

	/* ── Sealed codes ─────────────────────────────────────────────────────── */

	public function test_a_jobs_code_opens_for_its_person_on_its_day(): void {
		$GLOBALS['zdz_test_filters']['zdz_sealed_now'] = function () { return strtotime( '2030-03-04 12:00:00 UTC' ); };
		$job = $this->row( 12, array( 'assigned_user_id' => 4, 'scheduled_appt_id' => 41, 'scheduled_start_utc' => '2030-03-04 09:00:00', 'scheduled_end_utc' => '2030-03-04 11:00:00' ) );
		$this->assertSame( 'job #12 today', ZJOB_Sealed::job_today_for_row( $job, 4, array() ) );
		$this->assertSame( '', ZJOB_Sealed::job_today_for_row( $job, 3, array() ), 'not their job' );
		$this->assertSame( 'job #12 today', ZJOB_Sealed::job_today_for_row( $job, 3, array( 41 => array() ) ), 'on its appointment today' );
		$other_day = array_merge( $job, array( 'scheduled_start_utc' => '2030-03-05 09:00:00', 'scheduled_end_utc' => '2030-03-05 11:00:00' ) );
		$this->assertSame( '', ZJOB_Sealed::job_today_for_row( $other_day, 4, array() ) );
		$this->assertSame( '', ZJOB_Sealed::job_today_for_row( array_merge( $job, array( 'status' => 'done' ) ), 4, array() ), 'a finished job keeps its code shut' );
		$this->assertSame( 'kept', ZJOB_Sealed::may_open( 'kept', array( 'scope_type' => 'job', 'scope_id' => '12' ), 4 ), 'another app\'s yes is kept' );
		$this->assertFalse( ZJOB_Sealed::may_open( false, array( 'scope_type' => 'estimate', 'scope_id' => '12' ), 4 ) );
	}

	/* ── Privacy View ─────────────────────────────────────────────────────── */

	public function test_privacy_view_reply_writes_nothing_and_hides_numbers(): void {
		$out = ZJOB_Privacy_View::shape( array(
			'can_start' => true,
			'orders'    => array( array( 'po' => 'PO-1042', 'can_confirm' => array( 'seller' => true, 'operator' => true ), 'can_order' => true ) ),
		) );
		$this->assertFalse( $out['can_start'] );
		$this->assertFalse( $out['orders'][0]['can_order'] );
		$this->assertSame( array( 'seller' => false, 'operator' => false ), $out['orders'][0]['can_confirm'] );
		$this->assertStringNotContainsString( '1042', $out['orders'][0]['po'] );
	}
}
