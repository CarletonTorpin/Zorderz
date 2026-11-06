<?php
/**
 * Estimates: "My documents", re-assigning stubs, the chat's verbs, and the approval a Send is bound to.
 *
 *   Billing view: a document is the admin tier's, or its own salesperson's (this app's row for it, or the invoice's
 *   estimate's row, or a location line naming exactly them); never the shared device, never someone who may not make
 *   estimates; deny by default. A save re-reads the document, refuses a moved copy, writes the history first, then the
 *   billing system, and is refused for someone else's document. A priced estimate never goes to nothing; an invoice
 *   never below what was paid.
 *
 *   Re-assign: the hand-out codes; an estimate is eligible only when it is positively the same open estimate of the same
 *   customer naming only a hand-out code; Territories' proposal stands only for exactly one account; the rewrite changes
 *   the location line's code and the provenance line only; one notice per person, with numbers and codes, never a
 *   customer.
 *
 *   Chat: the verbs' rows (tiers, writes, the bounded shared-device read), the marker blocks the chat runs, the
 *   envelope, who is asking (the session first), Send only for one's own approved copy, a lookup that withholds what is
 *   someone else's, the ask bar's route.
 *
 *   Approval: bound to the copy it was given; any change means approving again.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/estimate-stubs.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-doc-conventions.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-capabilities.php';

if ( ! class_exists( 'ZDZ_Test_Fe_DB' ) ) {
	/** Three tables in memory: estimates, line changes, re-assign batches. */
	final class ZDZ_Test_Fe_DB {
		public $prefix    = 'wp_';
		public $insert_id = 0;
		public $t         = array( 'est' => array(), 'chg' => array(), 'rsg' => array() );
		private static function which( $sql ) {
			return false !== strpos( $sql, 'zest_line_changes' ) ? 'chg' : ( false !== strpos( $sql, 'zest_reassign' ) ? 'rsg' : 'est' );
		}
		public function prepare( $sql, ...$args ) {
			$args = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
			$i    = 0;
			return preg_replace_callback( '/%[dsf]/', function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ] ?? '';
				return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
			}, $sql );
		}
		public function esc_like( $s ) { return $s; }
		public function get_row( $q, $o = null ) {
			$t = self::which( $q );
			if ( preg_match( '/WHERE id = (\d+)/', $q, $m ) ) {
				return $this->t[ $t ][ (int) $m[1] ] ?? null;
			}
			foreach ( array( 'billing_doc_id', 'billing_doc_num' ) as $col ) {
				if ( preg_match( "/WHERE {$col} = '([^']*)'/", $q, $m ) ) {
					foreach ( array_reverse( $this->t[ $t ], true ) as $r ) {
						if ( (string) ( $r[ $col ] ?? '' ) === $m[1] ) {
							return $r;
						}
					}
					return null;
				}
			}
			if ( preg_match( "/TRIM\(LEADING '0' FROM billing_doc_num\) = '([^']*)'/", $q, $m ) ) {
				foreach ( array_reverse( $this->t['est'], true ) as $r ) {
					if ( ltrim( (string) ( $r['billing_doc_num'] ?? '' ), '0' ) === $m[1] ) {
						return $r;
					}
				}
			}
			return null;
		}
		public function get_var( $q ) {
			if ( false !== strpos( $q, 'GET_LOCK' ) || false !== strpos( $q, 'RELEASE_LOCK' ) ) {
				return '1';
			}
			return null;
		}
		public function get_results( $q, $o = null ) {
			if ( 'est' === self::which( $q ) && preg_match( "/customer_name LIKE '%([^%']*)%'/", $q, $m ) ) {
				return array_values( array_filter( $this->t['est'], function ( $r ) use ( $m ) { return false !== stripos( (string) $r['customer_name'], $m[1] ); } ) );
			}
			return array();
		}
		public function get_col( $q ) { return array(); }
		public function query( $q ) { return 0; }
		public function insert( $table, $row ) {
			$t = self::which( $table );
			$this->insert_id = count( $this->t[ $t ] ) + 1;
			$this->t[ $t ][ $this->insert_id ] = array( 'id' => $this->insert_id ) + $row;
			return 1;
		}
		public function update( $table, $data, $where ) {
			$t = self::which( $table );
			$n = 0;
			foreach ( $this->t[ $t ] as $id => $r ) {
				$hit = true;
				foreach ( $where as $k => $v ) {
					if ( (string) ( $r[ $k ] ?? '' ) !== (string) $v ) {
						$hit = false;
					}
				}
				if ( $hit ) {
					$this->t[ $t ][ $id ] = array_merge( $r, $data );
					$n++;
				}
			}
			return $n;
		}
	}
}

final class EstimateBillingViewTest extends TestCase {

	const ALEX   = 71; // sales, AA
	const JORDAN = 72; // sales, JJ
	const SAM    = 73; // operator, OP
	const RILEY  = 74; // the shared device
	const CASEY  = 75; // owner

	/** @var array id => billing document */
	private $docs = array();
	/** @var array the last write */
	private $put = null;

	protected function setUp(): void {
		$GLOBALS['zdz_test_options']      = array( 'zdz_document_conventions' => array( 'estimate' => array( 'provenance_line' => array( 'enabled' => true ) ) ) );
		$GLOBALS['zdz_test_transients']   = array();
		$GLOBALS['zdz_test_filters']      = array();
		$GLOBALS['zdz_test_user_roles']   = array( self::ALEX => array( 'zdz_sales' ), self::JORDAN => array( 'zdz_sales' ), self::SAM => array( 'zdz_operator' ), self::RILEY => array( 'zdz_general' ), self::CASEY => array( 'zdz_owner' ) );
		$GLOBALS['zdz_test_user_meta']    = array( self::ALEX => array( 'zdz_user_initials' => 'AA' ), self::JORDAN => array( 'zdz_user_initials' => 'JJ' ), self::SAM => array( 'zdz_user_initials' => 'OP' ) );
		$GLOBALS['zdz_test_users']        = array();
		foreach ( $GLOBALS['zdz_test_user_roles'] as $id => $r ) {
			$GLOBALS['zdz_test_users'][] = (object) array( 'ID' => $id, 'roles' => $r, 'display_name' => 'Person ' . $id );
		}
		$GLOBALS['zdz_test_current_user'] = self::ALEX;
		$GLOBALS['wpdb']                  = new ZDZ_Test_Fe_DB();
		$GLOBALS['wpdb']->t['est'][7]     = array(
			'id' => 7, 'created_by' => self::ALEX, 'salesperson' => 'AA', 'notes' => '', 'billing_doc_num' => '1042', 'billing_doc_id' => '555',
			'billing_cust_id' => '901', 'customer_name' => 'Morgan Example', 'items_json' => '[]', 'seal_hold' => '', 'status' => 'created',
			'created_at' => '2026-01-02 10:00:00', 'item_count' => 1, 'approved_fp' => '',
		);
		$this->docs = array( '555' => self::doc(), '556' => self::doc( array( 'id' => '556', 'estimate_number' => '1043', 'lines' => array(
			array( 'name' => 'Location', 'description' => 'Exampleton - (JJ)', 'qty' => '1', 'unit_cost' => array( 'amount' => '0' ) ),
			array( 'name' => 'Example widget', 'description' => 'Plain', 'qty' => '1', 'unit_cost' => array( 'amount' => '100.00' ) ),
		) ) ) );
		$this->put = null;
		$self = $this;
		ZEST_Billing_Doc::$account   = 'acct1';
		ZEST_Billing_Doc::$transport = static function ( $method, $path, $body ) use ( $self ) {
			return $self->answer( $method, $path, $body );
		};
		ZDZ_Doc_Conventions::flush();
		ZEST_Salesperson::flush();
		ZDZ_Capabilities::flush();
	}

	public function answer( $method, $path, $body ) {
		if ( ! preg_match( '#/(estimates|invoices)/\1/(\d+)#', $path, $m ) ) {
			return null;
		}
		$kind = 'estimates' === $m[1] ? 'estimate' : 'invoice';
		if ( 'PUT' === $method ) {
			$this->put = $body[ $kind ];
			$this->docs[ $m[2] ] = array_merge( $this->docs[ $m[2] ], $body[ $kind ] );
		}
		return isset( $this->docs[ $m[2] ] ) ? array( 'response' => array( 'result' => array( $kind => $this->docs[ $m[2] ] ) ) ) : null;
	}

	protected function tearDown(): void {
		ZEST_Billing_Doc::$account   = null;
		ZEST_Billing_Doc::$transport = null;
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_transients'], $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_user_roles'],
			$GLOBALS['zdz_test_user_meta'], $GLOBALS['zdz_test_users'], $GLOBALS['zdz_test_current_user'], $GLOBALS['wpdb'] );
		ZDZ_Doc_Conventions::flush();
		ZEST_Salesperson::flush();
		ZDZ_Capabilities::flush();
	}

	private static function doc( array $over = array() ): array {
		return $over + array(
			'id' => '555', 'estimate_number' => '1042', 'customerid' => '901', 'create_date' => '2026-01-02', 'notes' => '', 'terms' => '', 'vis_state' => 0,
			'fname' => 'Morgan', 'lname' => 'Example', 'amount' => array( 'amount' => '200.00' ),
			'lines' => array(
				array( 'name' => 'Location', 'description' => 'Exampleton - (AA)', 'qty' => '1', 'unit_cost' => array( 'amount' => '0' ) ),
				array( 'name' => 'Example widget', 'description' => 'Plain', 'qty' => '2', 'unit_cost' => array( 'amount' => '100.00' ) ),
			),
		);
	}

	/* ── who may see a billing document ───────────────────────────────── */

	public function test_a_document_is_its_own_salespersons_or_the_admin_tiers(): void {
		$this->assertTrue( ZEST_Billing_View::owns( self::doc(), 'estimate', self::ALEX ), 'this app\'s row is theirs' );
		$this->assertFalse( ZEST_Billing_View::owns( self::doc(), 'estimate', self::JORDAN ) );
		$this->assertTrue( ZEST_Billing_View::owns( self::doc(), 'estimate', self::CASEY ), 'the admin tier' );
		$this->assertFalse( ZEST_Billing_View::owns( self::doc(), 'estimate', self::RILEY ), 'never the shared device' );
		$this->assertFalse( ZEST_Billing_View::owns( self::doc(), 'estimate', 0 ) );
		$this->assertTrue( ZEST_Billing_View::owns( $this->docs['556'], 'estimate', self::JORDAN ), 'no row: the location line names exactly them' );
		$this->assertFalse( ZEST_Billing_View::owns( $this->docs['556'], 'estimate', self::ALEX ) );
		$this->assertTrue( ZEST_Billing_View::owns( array( 'id' => '9', 'estimateid' => '555', 'lines' => array() ), 'invoice', self::ALEX ), 'an invoice made from their estimate' );
		$this->assertFalse( ZEST_Billing_View::owns( array( 'id' => '9', 'estimateid' => '555', 'lines' => array() ), 'invoice', self::JORDAN ) );
		$GLOBALS['zdz_test_user_meta'][ self::ALEX ]['zest_can_create_estimate'] = '0';
		$this->assertFalse( ZEST_Billing_View::owns( self::doc(), 'estimate', self::ALEX ), 'someone who may not make estimates' );
		$GLOBALS['zdz_test_user_meta'][ self::ALEX ]['zest_can_create_estimate'] = '';
		$GLOBALS['zdz_test_user_meta'][ self::ALEX ][ ZEST_Access_Requests::REVOKED ] = array( 'row:7' => time() );
		$this->assertFalse( ZEST_Billing_View::owns( self::doc(), 'estimate', self::ALEX ), 'an administrator removed their access' );
	}

	public function test_a_save_is_checked_against_the_document_as_it_is_now(): void {
		$lines = ZEST_Line_Edits::lines_from_doc( self::doc() );
		$base  = ZEST_Line_Edits::base_hash( $lines, '' );
		$moved = ZEST_Billing_View::save_locked( 'estimate', '555', str_repeat( 'a', 24 ), str_repeat( 'b', 32 ), '', array( array( 'type' => 'qty', 'line' => 1, 'to' => '3' ) ), self::ALEX );
		$this->assertTrue( $moved['moved'] );
		$this->assertNull( $this->put, 'nothing written' );
		$this->assertIsString( ZEST_Billing_View::save_locked( 'estimate', '555', $base, str_repeat( 'b', 32 ), '', array( array( 'type' => 'qty', 'line' => 1, 'to' => '3' ) ), self::JORDAN ), 'someone else\'s' );
		$this->assertNull( $this->put );

		$ok = ZEST_Billing_View::save_locked( 'estimate', '555', $base, str_repeat( 'c', 32 ), 'customer asked', array( array( 'type' => 'qty', 'line' => 1, 'to' => '3' ) ), self::ALEX );
		$this->assertTrue( $ok['saved'], is_string( $ok ) ? $ok : '' );
		$this->assertSame( '3', $this->put['lines'][1]['qty'] );
		$this->assertStringContainsString( 'Edited by: AA', $this->put['notes'] );
		$chg = array_values( $GLOBALS['wpdb']->t['chg'] );
		$this->assertCount( 1, $chg );
		$this->assertSame( 'saved', $chg[0]['state'], 'the history is written first, then marked' );
		$this->assertSame( 'customer asked', $chg[0]['reason'] );
	}

	public function test_money_is_never_taken_below_what_it_must_be(): void {
		$this->assertNotSame( '', ZEST_Billing_View::money_refusal( 'estimate', array(), 200.0, 0.0 ) );
		$this->assertSame( '', ZEST_Billing_View::money_refusal( 'estimate', array(), 200.0, 150.0 ) );
		$this->assertNotSame( '', ZEST_Billing_View::money_refusal( 'invoice', array( 'paid' => '150', 'amount' => '200' ), 200.0, 100.0 ), 'below what was paid' );
		$this->assertSame( '', ZEST_Billing_View::money_refusal( 'invoice', array( 'paid' => '150', 'amount' => '200' ), 200.0, 180.0 ) );
	}

	public function test_prices_are_shown_only_to_someone_who_sees_them(): void {
		$GLOBALS['zdz_test_filters']['zest_field_permissions'] = static function ( $p ) { $p['view_pricing'] = false; return $p; };
		$p = ZEST_Billing_View::payload( self::doc(), 'estimate', self::ALEX );
		$this->assertNull( $p['totals'] );
		$this->assertNull( $p['lines'][1]['rate'] );
		$this->assertFalse( $p['rights']['price'] );
		$this->assertSame( 7, $p['row_id'] );
	}

	/* ── re-assigning stubs ───────────────────────────────────────────── */

	private function item( array $over = array() ): array {
		return $over + array( 'bid' => '556', 'row_id' => 8, 'num' => '1043', 'cust' => '901', 'zip' => '00000', 'test' => false, 'state' => 'unread' );
	}

	public function test_the_hand_out_codes(): void {
		$this->assertSame( array( 'OP' ), ZEST_Reassign::from_codes( array( 'created_by' => self::SAM ) ), 'the operator who typed it' );
		$this->assertSame( array(), ZEST_Reassign::from_codes( array( 'created_by' => self::ALEX ) ), 'a salesperson\'s own work is not handed out' );
		$GLOBALS['zdz_test_options'][ ZEST_Reassign::OPT_CODES ] = array( 'qq', 'bad code' );
		$this->assertSame( array( 'QQ', 'OP' ), ZEST_Reassign::from_codes( array( 'created_by' => self::SAM ) ) );
	}

	public function test_an_estimate_is_eligible_only_when_it_positively_is_the_one(): void {
		$op  = self::doc( array( 'id' => '556', 'estimate_number' => '1043', 'lines' => array(
			array( 'name' => 'Location', 'description' => 'Exampleton - (OP)', 'qty' => '1', 'unit_cost' => array( 'amount' => '0' ) ),
			array( 'name' => 'Example widget', 'description' => 'Plain', 'qty' => '1', 'unit_cost' => array( 'amount' => '0' ) ),
		) ) );
		$ok = ZEST_Reassign::snapshot( $this->item(), $op, array( 'OP' ) );
		$this->assertSame( 'eligible', $ok['state'], (string) ( $ok['why'] ?? '' ) );
		$this->assertSame( 'OP', $ok['from'] );
		$this->assertSame( 0, $ok['at'] );
		$this->assertSame( array( array( 'description' => 'Example widget', 'sub_description' => 'Plain' ) ), $ok['work'], 'the street is not the work' );
		$this->assertSame( 'skip', ZEST_Reassign::snapshot( $this->item(), null, array( 'OP' ) )['state'] );
		$this->assertSame( 'skip', ZEST_Reassign::snapshot( $this->item( array( 'cust' => '902' ) ), $op, array( 'OP' ) )['state'], 'another customer' );
		$this->assertSame( 'skip', ZEST_Reassign::snapshot( $this->item( array( 'cust' => '' ) ), $op, array( 'OP' ) )['state'], 'no customer on record' );
		$this->assertSame( 'skip', ZEST_Reassign::snapshot( $this->item(), array( 'vis_state' => 1 ) + $op, array( 'OP' ) )['state'], 'archived' );
		$this->assertSame( 'skip', ZEST_Reassign::snapshot( $this->item( array( 'bid' => '557' ) ), $op, array( 'OP' ) )['state'], 'another document answered' );
		$this->assertSame( 'skip', ZEST_Reassign::snapshot( $this->item(), self::doc( array( 'id' => '556' ) ), array( 'OP' ) )['state'], 'already has a salesperson' );
		$sub = ZEST_Reassign::snapshot( $this->item(), array( 'notes' => 'Submitted by: JJ' ) + $op, array( 'OP' ) );
		$this->assertSame( 'skip', $sub['state'], 'a salesperson made it: theirs' );
		$test = ZEST_Reassign::snapshot( $this->item( array( 'test' => true ) ), $op, array( 'OP' ), static function ( $n ) { return 0 === strpos( $n, 'Test' ); } );
		$this->assertSame( 'skip', $test['state'], 'the test and real split agrees with the billing system' );
	}

	public function test_a_proposal_stands_only_for_exactly_one_account(): void {
		$it     = array( 'state' => 'eligible', 'from' => 'OP', 'work' => array() );
		$holder = static function ( $c ) { return array( 'JJ' => 72, 'AA' => 71 )[ $c ] ?? 0; };
		$p      = ZEST_Reassign::propose_one( $it, array( 'change' => true, 'mode' => 'live', 'user_id' => 72, 'initials' => 'JJ', 'rule_id' => 'r1', 'rule_no' => 2 ), $holder );
		$this->assertSame( 'proposed', $p['state'] );
		$this->assertSame( 'JJ', $p['to'] );
		$this->assertArrayNotHasKey( 'work', $p );
		$this->assertSame( 'keep', ZEST_Reassign::propose_one( $it, array( 'change' => true, 'mode' => 'live', 'user_id' => 99, 'initials' => 'JJ' ), $holder )['state'], 'the code is someone else\'s' );
		$this->assertSame( 'keep', ZEST_Reassign::propose_one( $it, array( 'change' => true, 'mode' => 'shadow', 'user_id' => 72, 'initials' => 'JJ' ), $holder )['state'], 'shadow decides nothing' );
		$this->assertSame( 'keep', ZEST_Reassign::propose_one( $it, null, $holder )['state'] );
	}

	public function test_the_rewrite_changes_only_the_codes(): void {
		$doc = self::doc( array( 'notes' => "Thanks\nSubmitted by: OP", 'lines' => array(
			array( 'name' => 'Location', 'description' => 'Exampleton - (OP)', 'qty' => '1', 'unit_cost' => array( 'amount' => '0' ) ),
			array( 'name' => 'Example widget', 'description' => 'Plain', 'qty' => '1', 'unit_cost' => array( 'amount' => '0' ) ),
		) ) );
		$r = ZEST_Reassign::rewrite( $doc, 'OP', 'JJ', '', 'CC', '1/2/2026' );
		$this->assertIsArray( $r );
		$this->assertSame( 'Exampleton - (JJ)', $r[0][0]['description'] );
		$this->assertSame( $doc['lines'][1], $r[0][1], 'the work is untouched' );
		$this->assertStringContainsString( 'Submitted by: JJ', $r[1] );
		$this->assertStringNotContainsString( 'Submitted by: OP', $r[1] );
		$this->assertStringContainsString( 'Edited by: CC - 1/2/2026', $r[1] );
		$this->assertSame( 0, strpos( $r[1], 'Thanks' ) );
		$this->assertIsString( ZEST_Reassign::rewrite( self::doc( array( 'lines' => array() ) ), 'OP', 'JJ', '', 'CC', '1/2/2026' ) );
	}

	public function test_one_notice_per_person_with_numbers_never_a_customer(): void {
		$items = array(
			array( 'state' => 'applied', 'to_id' => 72, 'to' => 'JJ', 'from' => 'OP', 'num' => '1043', 'test' => false ),
			array( 'state' => 'applied', 'to_id' => 72, 'to' => 'JJ', 'from' => 'OP', 'num' => '1044', 'test' => false ),
			array( 'state' => 'changed', 'to_id' => 71, 'to' => 'AA', 'from' => 'OP', 'num' => '1045', 'test' => false ),
		);
		$n = ZEST_Reassign::notices( $items, 'CC' );
		$this->assertSame( array( 72 ), array_keys( $n ) );
		$this->assertSame( 2, $n[72]['count'] );
		$this->assertStringContainsString( '#1043, #1044', $n[72]['message'] );
		$this->assertStringContainsString( 'by CC', $n[72]['message'] );
		$this->assertStringNotContainsString( 'Morgan', $n[72]['message'] );
	}

	/* ── the chat ─────────────────────────────────────────────────────── */

	public function test_the_verbs_and_markers_the_chat_runs(): void {
		$rows = ZEST_ZANA_Bridge::verbs();
		$n    = ZDZ_Capabilities::normalize( 'estimate.lookup', $rows['estimate.lookup'] );
		$this->assertFalse( $n['side_effect'] );
		$this->assertTrue( $n['kiosk_bounded'], 'the shared device gets the bounded read' );
		$this->assertSame( 'viewer', $n['tier'] );
		foreach ( array( 'estimate.create', 'estimate.modify', 'estimate.send', 'estimate.attach_email' ) as $v ) {
			$w = ZDZ_Capabilities::normalize( $v, $rows[ $v ] );
			$this->assertTrue( $w['side_effect'], $v . ' writes, so it waits for Confirm' );
			$this->assertSame( 'staff', $w['tier'], $v );
		}
		$this->assertSame( 'admin', ZDZ_Capabilities::normalize( 'estimate.stub', $rows['estimate.stub'] )['tier'] );
		// the app's marker blocks have the shape the chat app runs: "[NAME]…[/NAME]" with the verb named
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/estimate/app.php' );
		$this->assertSame( 1, preg_match( "/\\\$block = static function \( string \\\$tok \): string \{\s*return \\\$tok \. '…' \. str_replace\( '\[', '\[\/', \\\$tok \);/u", $src ) );
		$tok = '[ZDZ_EST_SEND]' . '…' . str_replace( '[', '[/', '[ZDZ_EST_SEND]' );
		$this->assertSame( 1, preg_match( '/^(\[([A-Z][A-Z0-9_]{2,40})\])…(\[\/\2\])$/u', $tok ) );
		foreach ( array( 'estimate.create', 'estimate.modify', 'estimate.send', 'estimate.stub', 'estimate.lookup', 'estimate.attach_email' ) as $v ) {
			$this->assertStringContainsString( "'verb' => '" . $v . "'", $src, $v . ' names its verb' );
		}
	}

	public function test_the_envelope_and_who_is_asking(): void {
		$this->assertTrue( ZEST_ZANA_Bridge::envelope( array( 'ok' => true ), 'Done.' )['success'] );
		$this->assertSame( 'Done.', ZEST_ZANA_Bridge::envelope( array( 'ok' => true ), 'Done.' )['message'] );
		$e = ZEST_ZANA_Bridge::envelope( array( 'ok' => false, 'error' => 'No.' ) );
		$this->assertFalse( $e['success'] );
		$this->assertSame( 'No.', $e['message'] );
		$this->assertSame( self::ALEX, ZEST_ZANA_Bridge::acting_uid( array( 'requesting_user_id' => self::CASEY ) ), 'the session, never the payload' );
		$GLOBALS['zdz_test_current_user'] = 0;
		$this->assertSame( self::CASEY, ZEST_ZANA_Bridge::acting_uid( array( 'requesting_user_id' => self::CASEY ) ), 'no session: a scheduled run' );
	}

	public function test_send_is_only_for_ones_own_estimate_and_never_the_shared_device(): void {
		$GLOBALS['zdz_test_current_user'] = self::JORDAN;
		$r = ZEST_ZANA_Bridge::send_from_chat( array( 'estimate_number' => '1042' ) );
		$this->assertFalse( $r['success'] );
		$this->assertStringContainsString( 'your own', $r['message'] );
		$GLOBALS['zdz_test_current_user'] = self::RILEY;
		$this->assertFalse( ZEST_ZANA_Bridge::send_from_chat( array( 'estimate_number' => '1042' ) )['success'] );
		$GLOBALS['zdz_test_current_user'] = self::ALEX;
		$r = ZEST_ZANA_Bridge::send_from_chat( array( 'estimate_number' => '1042' ) );
		$this->assertFalse( $r['success'], 'never approved: not sent' );
		$this->assertStringContainsString( 'Approve', $r['message'] );
	}

	public function test_a_lookup_withholds_what_is_someone_elses(): void {
		$GLOBALS['zdz_test_current_user'] = self::JORDAN;
		$r = ZEST_ZANA_Bridge::lookup_for_chat( array( 'customer' => 'Morgan' ) );
		$this->assertTrue( $r['success'] );
		$this->assertSame( array(), $r['documents'] );
		$this->assertSame( 1, $r['withheld'] );
		$this->assertTrue( $r['not_yours'] );
		$GLOBALS['zdz_test_current_user'] = self::ALEX;
		$r = ZEST_ZANA_Bridge::lookup_for_chat( array( 'customer' => 'Morgan' ) );
		$this->assertSame( '1042', $r['documents'][0]['number'] );
		$this->assertStringContainsString( '#1042', $r['message'] );
		$GLOBALS['zdz_test_current_user'] = self::RILEY;
		$k = ZEST_ZANA_Bridge::lookup_for_chat( array( 'customer' => 'Morgan' ) );
		$this->assertFalse( $k['success'], 'the shared device does not look customers up unless the business allows it' );
		$this->assertArrayNotHasKey( 'documents', array_filter( (array) $k, function ( $v ) { return ! empty( $v ); } ) );
		$GLOBALS['zdz_test_filters']['zest_shared_device_lookup'] = function () { return true; };
		$k = ZEST_ZANA_Bridge::lookup_for_chat( array( 'customer' => 'Morgan' ) );
		unset( $GLOBALS['zdz_test_filters']['zest_shared_device_lookup'] );
		$this->assertTrue( $k['success'] );
		$this->assertSame( '1042', $k['documents'][0]['number'], 'allowed: the bounded shape' );
		$this->assertNull( $k['documents'][0]['total'], 'the shared device: no amounts' );
	}

	public function test_the_ask_bar_answers_only_for_ones_own_estimate(): void {
		$a = ZEST_ZANA_Bridge::quick( 'estimate 1042', self::ALEX );
		$this->assertSame( 'estimate', $a[0] );
		$this->assertStringContainsString( 'Morgan Example', $a[1] );
		$this->assertNull( ZEST_ZANA_Bridge::quick( 'estimate 1042', self::JORDAN ), 'someone else\'s: not even that it exists' );
		$this->assertNull( ZEST_ZANA_Bridge::quick( 'estimate 1042', self::RILEY ) );
		$this->assertNull( ZEST_ZANA_Bridge::quick( 'what is the weather', self::ALEX ) );
	}

	/* ── approval ─────────────────────────────────────────────────────── */

	public function test_an_approval_is_bound_to_the_copy_it_was_given(): void {
		$doc = self::doc();
		$fp  = ZEST_Approve::approval_fp( $doc );
		$this->assertSame( 64, strlen( $fp ) );
		$this->assertNull( ZEST_Approve::approval_refusal( array( 'approved_fp' => $fp ), $doc ) );
		$this->assertSame( 'needs_approval', ZEST_Approve::approval_refusal( array( 'approved_fp' => '' ), $doc )['code'], 'never approved' );
		$changed = $doc;
		$changed['lines'][1]['qty'] = '3';
		$this->assertSame( 'needs_approval', ZEST_Approve::approval_refusal( array( 'approved_fp' => $fp ), $changed )['code'], 'a line changed since' );
		$this->assertNotSame( $fp, ZEST_Approve::approval_fp( array( 'notes' => 'More words' ) + $doc ) );
		$this->assertNotSame( $fp, ZEST_Approve::approval_fp( array( 'street' => '2 Example Way' ) + $doc ) );
	}
}
