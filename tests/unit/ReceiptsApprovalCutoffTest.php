<?php
/**
 * Receipts: the approval rule does not break links shared before it existed.
 *
 *   - The first run records the moment (option zrcpt_approval_cutoff_gmt), once; a stored
 *     value, including an empty one, is never moved.
 *   - A receipt created and last changed before that moment, and never reviewed, keeps
 *     its public link (grandfathered).
 *   - A receipt created after it needs an approval (or a send) before its link opens.
 *
 * Loads the real ZRCPT_Receipt without booting it. Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $u ) { return (string) $u; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ); }
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
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key = '', $single = false ) {
		$v = $GLOBALS['zdz_test_post_meta'][ (int) $id ][ $key ] ?? '';
		return $single ? $v : array( $v );
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) {
		$p = $GLOBALS['zdz_test_posts'][ (int) $id ] ?? null;
		return is_array( $p ) ? (object) $p : $p;
	}
}

final class ReceiptsApprovalCutoffTest extends TestCase {

	private const KEY = 'zrcpt_approval_cutoff_gmt';

	/** @var ZRCPT_Receipt */
	private $receipts;

	public static function setUpBeforeClass(): void {
		$dir = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/receipts/includes/';
		require_once $dir . 'class-zrcpt-sanitize.php';
		require_once $dir . 'class-zrcpt-finalize.php';
		if ( ! class_exists( 'ZRCPT_Receipt' ) ) {
			require_once $dir . 'class-zrcpt-receipt.php';
		}
	}

	protected function setUp(): void {
		unset( $GLOBALS['zdz_test_options'][ self::KEY ], $GLOBALS['zdz_test_filters']['zrcpt_public_view_allowed'], $GLOBALS['zdz_test_filters']['zrcpt_app_id'] );
		$GLOBALS['zdz_test_current_user'] = 0;
		$GLOBALS['zdz_test_post_meta']    = array();
		$GLOBALS['zdz_test_posts']        = array();
		$this->receipts = ( new ReflectionClass( 'ZRCPT_Receipt' ) )->newInstanceWithoutConstructor();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_options'][ self::KEY ], $GLOBALS['zdz_test_post_meta'], $GLOBALS['zdz_test_posts'] );
		$GLOBALS['zdz_test_current_user'] = 0;
	}

	private function receipt( int $id, string $created, string $changed, array $meta = array() ): void {
		$GLOBALS['zdz_test_posts'][ $id ]     = array( 'ID' => $id, 'post_date_gmt' => $created, 'post_modified_gmt' => $changed );
		$GLOBALS['zdz_test_post_meta'][ $id ] = $meta;
	}

	public function test_the_first_run_records_the_moment_once(): void {
		$this->assertSame( '2026-03-01 08:00:00', ZRCPT_Receipt::approval_cutoff_to_record( false, '2026-03-01 08:00:00' ) );
		$this->assertNull( ZRCPT_Receipt::approval_cutoff_to_record( '2026-01-01 00:00:00', '2026-03-01 08:00:00' ) );
		$this->assertNull( ZRCPT_Receipt::approval_cutoff_to_record( '', '2026-03-01 08:00:00' ), 'an empty value an administrator set is kept' );

		$this->receipts->maybe_set_approval_cutoff();
		$first = (string) get_option( self::KEY, '' );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $first );

		$GLOBALS['zdz_test_options'][ self::KEY ] = '2026-01-01 00:00:00';
		$this->receipts->maybe_set_approval_cutoff();
		$this->assertSame( '2026-01-01 00:00:00', get_option( self::KEY, '' ), 'a later run never moves it' );
	}

	public function test_the_hook_runs_before_anything_else_on_init(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/receipts/includes/class-zrcpt-receipt.php' );
		$this->assertStringContainsString( "add_action( 'init', [ \$this, 'maybe_set_approval_cutoff' ], 0 );", $src );
	}

	public function test_a_link_shared_before_the_rule_keeps_working(): void {
		$GLOBALS['zdz_test_options'][ self::KEY ] = '2026-03-01 08:00:00';
		$this->receipt( 11, '2026-02-10 15:00:00', '2026-02-11 09:00:00' );
		$this->assertTrue( $this->receipts->public_view_allowed( 11 ), 'grandfathered: made before the rule, never reviewed' );

		// Opened for review since (an approval log exists): the rule applies to it now.
		$this->receipt( 12, '2026-02-10 15:00:00', '2026-02-11 09:00:00', array( '_approval_log' => array( array( 'event' => 'opened' ) ) ) );
		$this->assertFalse( $this->receipts->public_view_allowed( 12 ) );

		// Redone after the rule started: the redo needs an approval.
		$this->receipt( 13, '2026-02-10 15:00:00', '2026-03-05 09:00:00' );
		$this->assertFalse( $this->receipts->public_view_allowed( 13 ) );
	}

	public function test_a_new_receipt_needs_an_approval(): void {
		$GLOBALS['zdz_test_options'][ self::KEY ] = '2026-03-01 08:00:00';
		$this->receipt( 21, '2026-03-02 10:00:00', '2026-03-02 10:00:00' );
		$this->assertFalse( $this->receipts->public_view_allowed( 21 ), 'made after the rule started, not approved: no public link' );

		$this->receipt( 22, '2026-03-02 10:00:00', '2026-03-02 10:00:00', array( '_approved_at' => '2026-03-02 11:00:00' ) );
		$this->assertTrue( $this->receipts->public_view_allowed( 22 ) );

		$this->receipt( 23, '2026-03-02 10:00:00', '2026-03-02 10:00:00', array( '_sent_at' => '2026-03-02 11:00:00' ) );
		$this->assertTrue( $this->receipts->public_view_allowed( 23 ) );
	}

	public function test_an_empty_cutoff_grandfathers_nothing(): void {
		$GLOBALS['zdz_test_options'][ self::KEY ] = '';
		$this->receipt( 31, '2025-01-01 10:00:00', '2025-01-01 10:00:00' );
		$this->assertFalse( $this->receipts->public_view_allowed( 31 ) );
	}
}
