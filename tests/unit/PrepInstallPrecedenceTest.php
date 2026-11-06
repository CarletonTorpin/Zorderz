<?php
/**
 * Prep install date: the jobs app's answer wins, a date read from billing paperwork fills
 * only a job with no known date — on the server (ZPREP_Install_Date::precedence) and in the
 * browser (install-merge.js), in the same order. Also the billing chain (newest document,
 * done, paid) and the year of a date written without one. Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

final class PrepInstallPrecedenceTest extends TestCase {

	public static function setUpBeforeClass(): void {
		$dir = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/prep/includes/';
		require_once $dir . 'class-zprep-install-date.php';
		require_once $dir . 'class-zprep-billing-chain.php';
		require_once $dir . 'class-zprep-install-fallback.php';
	}

	private static function payloads(): array {
		return array(
			'none'        => null,
			'jobs_known'  => array( 'line' => '2026-05-04', 'status' => 'scheduled', 'state' => 'known', 'source' => 'jobs' ),
			'billing'     => array( 'line' => 'Mon May 4 · per invoice #1001', 'status' => 'scheduled', 'state' => 'estimated', 'source' => 'billing' ),
			'unscheduled' => array( 'line' => 'Unscheduled', 'status' => 'unscheduled', 'state' => 'unknown', 'source' => 'jobs' ),
			'unknown'     => array( 'line' => 'Unknown', 'status' => 'unknown', 'state' => 'unknown', 'source' => 'jobs' ),
			'n_a'         => array( 'line' => 'Not applicable', 'status' => 'not_applicable', 'state' => 'not_applicable', 'source' => 'jobs' ),
		);
	}

	public function test_server_precedence(): void {
		$p = self::payloads();
		$this->assertSame( $p['jobs_known'], ZPREP_Install_Date::precedence( $p['jobs_known'], $p['billing'] ), 'the jobs answer wins' );
		$this->assertSame( $p['billing'], ZPREP_Install_Date::precedence( $p['unscheduled'], $p['billing'] ), 'the guess fills a job with no date' );
		$this->assertSame( $p['billing'], ZPREP_Install_Date::precedence( $p['unknown'], $p['billing'] ) );
		$this->assertSame( $p['billing'], ZPREP_Install_Date::precedence( null, $p['billing'] ) );
		$this->assertSame( $p['unscheduled'], ZPREP_Install_Date::precedence( $p['unscheduled'], null ) );
		$this->assertNull( ZPREP_Install_Date::precedence( null, null ) );
		$ranks = array_map( array( 'ZPREP_Install_Date', 'display_rank' ), $p );
		$this->assertSame( array( 'none' => -1, 'jobs_known' => 3, 'billing' => 2, 'unscheduled' => 1, 'unknown' => 0, 'n_a' => 0 ), $ranks );
	}

	public function test_browser_merge_uses_the_same_ranks_and_order(): void {
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) ); // sentinel:allow fixed command, no input
		if ( '' === $node ) {
			$this->assertTrue( true, 'node is not installed here; the ranks are pinned by the PHP test above' );
			return;
		}
		$js   = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/prep/assets/js/install-merge.js';
		$p    = self::payloads();
		$code = 'var m=require(' . json_encode( $js ) . ');var p=' . json_encode( $p ) . ';var o={rank:{},merge:{}};'
			. 'Object.keys(p).forEach(function(a){o.rank[a]=m.rank(p[a]);Object.keys(p).forEach(function(b){var r=m.merge(p[a],p[b]);'
			. 'var name=null;Object.keys(p).forEach(function(k){if(JSON.stringify(p[k])===JSON.stringify(r))name=k;});o.merge[a+">"+b]=name;});});'
			. 'process.stdout.write(JSON.stringify(o));';
		$out = json_decode( (string) shell_exec( escapeshellarg( $node ) . ' -e ' . escapeshellarg( $code ) ), true ); // sentinel:allow every argument is escapeshellarg()'d
		$this->assertIsArray( $out );
		foreach ( $p as $name => $payload ) {
			$this->assertSame( ZPREP_Install_Date::display_rank( $payload ), $out['rank'][ $name ], "rank of $name" );
		}
		foreach ( $p as $a => $pa ) {
			foreach ( $p as $b => $pb ) {
				$ra = ZPREP_Install_Date::display_rank( $pa );
				$rb = ZPREP_Install_Date::display_rank( $pb );
				$expect = ( $rb >= 0 && $rb >= $ra ) ? $b : ( null === $pa ? 'none' : $a );
				$this->assertSame( $expect, $out['merge'][ "$a>$b" ], "merge $a then $b" );
				// The browser never lets a lower answer replace a higher one, as the server bake.
				$server = ZPREP_Install_Date::precedence( 'billing' === $b ? $pa : $pb, 'billing' === $b ? $pb : ( 'billing' === $a ? $pa : null ) );
				if ( 'billing' === $a || 'billing' === $b ) {
					$this->assertSame( max( $ra, $rb ), ZPREP_Install_Date::display_rank( $server ), "server and browser agree on $a/$b" );
				}
			}
		}
	}

	public function test_billing_guess_is_shown_as_a_guess(): void {
		$p = ZPREP_Install_Date::billing_payload( array( 'start_utc' => '2026-05-04 07:00:00', 'date' => '2026-05-04', 'has_time' => false, 'doc' => 'invoice #1001', 'spread' => true ) );
		$this->assertSame( 'estimated', $p['state'], 'never "known"' );
		$this->assertSame( 'billing', $p['source'] );
		$this->assertStringContainsString( 'invoice #1001', $p['line'] );
		$this->assertStringContainsString( '(+more dates)', $p['line'] );
		$this->assertStringNotContainsString( '2026-05-04 07:00', $p['line'], 'no raw datetime reaches the browser' );
		$this->assertNull( ZPREP_Install_Date::billing_payload( array( 'start_utc' => '0000-00-00 00:00:00' ) ) );
	}

	public function test_chain_newest_dated_document_and_spread(): void {
		$sched = static function ( $utc ) { return array( 'state' => 'scheduled', 'start_utc' => $utc, 'source' => 'estimate' ); };
		$d     = ZPREP_Billing_Chain::decide( array(
			array( 'kind' => 'estimate', 'number' => '501', 'ts' => 100, 'sched' => $sched( '2026-05-01 07:00:00' ) ),
			array( 'kind' => 'invoice', 'number' => '1001', 'ts' => 100, 'sched' => $sched( '2026-05-08 07:00:00' ) ),
			array( 'kind' => 'invoice', 'number' => '1002', 'ts' => 50, 'sched' => null ),
		) );
		$this->assertSame( '2026-05-08 07:00:00', $d['env']['start_utc'], 'an invoice wins a tie with its estimate' );
		$this->assertTrue( $d['env']['spread'] );
		$this->assertFalse( $d['installed'] );
		$this->assertSame( 'invoice #1001', $d['newest'] );
	}

	public function test_chain_apply_drops_done_and_marks_paid(): void {
		$jobs = array(
			array( 'estimate_number' => '501', 'customer' => 'Alex Rivera' ),
			array( 'estimate_number' => '502', 'customer' => 'Sam Rivera' ),
			array( 'estimate_number' => '1003', 'billing_source' => 'invoice', 'billing_estimateid' => '9', 'fb_possible' => true ),
			array( 'estimate_number' => '777' ),
		);
		$approved = array(
			array( 'estimate_number' => '501', 'estimate_id' => '1', 'invoice_statuses' => array( 'paid' ), 'updated' => '2026-04-01 00:00:00' ),
			array( 'estimate_number' => '502', 'estimate_id' => '2', 'invoice_statuses' => array( 'sent' ) ),
		);
		$invoices = array(
			array( 'invoice_number' => '1001', 'estimateid' => '1', 'v3_status' => 'paid', 'installed' => false, 'scheduled' => array( 'start_utc' => '2026-05-08 07:00:00' ), 'updated' => '2026-04-02 00:00:00' ),
			array( 'invoice_number' => '1002', 'estimateid' => '2', 'v3_status' => 'sent', 'installed' => true ),
			array( 'invoice_number' => '1003', 'estimateid' => '9', 'v3_status' => 'draft', 'scheduled' => array( 'start_utc' => '2026-06-01 07:00:00' ) ),
		);
		$r = ZPREP_Billing_Chain::apply( $jobs, $approved, $invoices );
		$nums = array_map( static function ( $j ) { return $j['estimate_number']; }, $r['jobs'] );
		$this->assertSame( array( '501', '1003', '777' ), $nums, 'a chain with a receipt link is done; order is kept' );
		$this->assertTrue( $r['jobs'][0]['fb_paid'] );
		$this->assertSame( '2026-05-08 07:00:00', $r['jobs'][0]['billing_install']['start_utc'] );
		$this->assertFalse( $r['jobs'][1]['fb_possible'], 'a dated invoice is booked, not merely possible' );
		$this->assertArrayNotHasKey( 'billing_install', $r['jobs'][2], 'no paperwork, nothing added' );
		$this->assertFalse( ZPREP_Billing_Chain::paid_in_full( array() ) );
		$this->assertFalse( ZPREP_Billing_Chain::paid_in_full( array( 'paid', 'partial' ) ) );
		$this->assertTrue( ZPREP_Billing_Chain::paid_in_full( array( 'paid', '', 'autopaid' ) ) );
	}

	public function test_year_of_a_date_written_without_one(): void {
		$dec = strtotime( '2026-12-10 12:00:00 UTC' );
		$this->assertSame( 2027, ZPREP_Install_Fallback::year_for( 1, 12, $dec ), 'a December paper saying 1/12 means next January' );
		$this->assertSame( 2026, ZPREP_Install_Fallback::year_for( 11, 20, $dec ), 'a date shortly before the paper stays in its year' );
		$oct = strtotime( '2026-10-01 12:00:00 UTC' );
		$this->assertSame( 2026, ZPREP_Install_Fallback::year_for( 10, 7, $oct ) );
		$now = strtotime( '2026-03-01 12:00:00 UTC' );
		$this->assertSame( 2026, ZPREP_Install_Fallback::year_for( 2, 1, null, $now ) );
		$this->assertSame( 2027, ZPREP_Install_Fallback::year_for( 6, 1, null, strtotime( '2026-12-31 12:00:00 UTC' ) ) );
	}
}
