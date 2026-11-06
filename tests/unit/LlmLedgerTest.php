<?php
/**
 * The Ai ledger: one row per call, numbers and code words only -- never text; and the spend holds, booked before a
 * call is sent, released when its real cost is in the ledger, expiring on their own, and never letting two calls at
 * once spend the same headroom.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/llm-test-doubles.php';

final class LlmLedgerTest extends TestCase {

	private $t = 1793000000;   // a fixed clock

	protected function setUp(): void {
		$GLOBALS['wpdb']  = new Zdz_Test_Wpdb();
		$self             = $this;
		ZDZ_LLM_Ledger::$now = function () use ( $self ) {
			return $self->clock();
		};
		ZDZ_LLM_Ledger::forget();
	}

	public function clock() {
		return $this->t;
	}

	protected function tearDown(): void {
		ZDZ_LLM_Ledger::$now = null;
		ZDZ_LLM_Ledger::forget();
		unset( $GLOBALS['wpdb'], $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_filters'] );
	}

	private static function lines( $app, $all ) {
		return array( 'app' => (int) round( $app * 1000 ), 'all' => (int) round( $all * 1000 ) );
	}

	private static function held() {
		$sum = 0;
		foreach ( $GLOBALS['wpdb']->rows( 'zdz_llm_holds' ) as $r ) {
			$sum += (int) $r['usd_milli'];
		}
		return $sum;
	}

	/* ── the row ─────────────────────────────────────────────────────────── */

	public function test_a_row_keeps_numbers_and_code_words_only(): void {
		$row = ZDZ_LLM_Ledger::clean( array(
			'caller'      => 'receipts',
			'role'        => 'read_document',
			'provider'    => 'anthropic',
			'model'       => 'example-large-1',
			'outcome'     => 'Riley Example called from 555-0142 about the invoice',   // text: never kept
			'question'    => 'What did Riley Example say?',                              // not a column at all
			'answer'      => 'Something private',
			'in_tokens'   => '1200',
			'usd_milli'   => -5,
			'fallback'    => 7,
			'coded_count' => 3.6,
		) );
		$this->assertSame( array_merge( array( 'ts' ), ZDZ_LLM_Ledger::STRINGS, ZDZ_LLM_Ledger::NUMBERS ), array_keys( $row ) );
		$this->assertSame( 'other', $row['outcome'], 'a sentence is never stored, only a code word' );
		$this->assertArrayNotHasKey( 'question', $row );
		$this->assertArrayNotHasKey( 'answer', $row );
		$this->assertSame( 1200, $row['in_tokens'] );
		$this->assertSame( 0, $row['usd_milli'], 'never negative' );
		$this->assertSame( 1, $row['fallback'] );
		$this->assertSame( 4, $row['coded_count'] );
		foreach ( ZDZ_LLM_Ledger::STRINGS as $k ) {
			$this->assertMatchesRegularExpression( ZDZ_LLM_Ledger::CODE, $row[ $k ] );
		}
		foreach ( ZDZ_LLM_Ledger::NUMBERS as $k ) {
			$this->assertTrue( is_int( $row[ $k ] ), $k );
		}
		$f = ZDZ_LLM_Ledger::formats( $row );
		$this->assertSame( '%s', $f[ array_search( 'outcome', array_keys( $row ), true ) ], 'a string column is never inserted as %d' );
		$this->assertSame( '%d', $f[ array_search( 'usd_milli', array_keys( $row ), true ) ] );
	}

	public function test_the_tables_have_no_text_columns(): void {
		$this->assertTrue( ZDZ_LLM_Ledger::maybe_install() );
		$ddl = implode( "\n", $GLOBALS['wpdb']->ddl );
		$this->assertStringContainsString( 'wp_zdz_llm_ledger', $ddl );
		$this->assertStringContainsString( 'wp_zdz_llm_holds', $ddl );
		$this->assertDoesNotMatchRegularExpression( '/\b(text|tinytext|mediumtext|longtext|blob|json)\b/i', $ddl );
		preg_match_all( '/varchar\((\d+)\)/', $ddl, $m );
		foreach ( $m[1] as $len ) {
			$this->assertLessThanOrEqual( 48, (int) $len, 'only short code words fit' );
		}
		$this->assertSame( ZDZ_LLM_Ledger::DB_VERSION, $GLOBALS['zdz_test_options'][ ZDZ_LLM_Ledger::DB_OPTION ] );
		$GLOBALS['wpdb']->ddl = array();
		$this->assertTrue( ZDZ_LLM_Ledger::maybe_install() );
		$this->assertSame( array(), $GLOBALS['wpdb']->ddl, 'once per schema version' );
	}

	public function test_month_to_date_counts_this_month_and_keeps_external_spend_apart(): void {
		ZDZ_LLM_Ledger::write( array( 'caller' => 'receipts', 'usd_milli' => 1500 ) );
		ZDZ_LLM_Ledger::write( array( 'caller' => 'other-tool', 'usd_milli' => 700 ) );
		ZDZ_LLM_Ledger::write( array( 'caller' => 'receipts', 'usd_milli' => 9000, 'ts' => $this->t - 40 * 86400 ) );   // last month
		$m = ZDZ_LLM_Ledger::month_to_date();
		$this->near( 2.2, $m['app'], 0.0001 );
		$this->near( 2.2, $m['all'], 0.0001 );
		ZDZ_LLM_Ledger::forget();
		$GLOBALS['zdz_test_filters']['zdz_llm_external_callers'] = function () {
			return array( 'other-tool', 'Not A Code Word!' );
		};
		$this->assertSame( array( 'other-tool' ), ZDZ_LLM_Ledger::external_callers() );
		$m = ZDZ_LLM_Ledger::month_to_date();
		$this->near( 1.5, $m['app'], 0.0001, 'an external caller counts toward everything only' );
		$this->near( 2.2, $m['all'], 0.0001 );
	}

	/* ── holds ───────────────────────────────────────────────────────────── */

	private function near( $e, $a, $d, $m = '' ) {
		$this->assertTrue( abs( $e - $a ) <= $d, $m . " expected $e got $a" );
	}

	public function test_a_hold_books_at_most_what_is_left_and_release_gives_it_back(): void {
		$lines = self::lines( 1.0, 1.0 );
		$a = ZDZ_LLM_Ledger::reserve( 0.6, $lines, true, 60 );
		$this->assertGreaterThan( 0, $a['id'] );
		$this->near( 0.6, $a['usd'], 0.0001 );
		$b = ZDZ_LLM_Ledger::reserve( 0.6, $lines, true, 60 );
		$this->near( 0.4, $b['usd'], 0.0001, 'only what is left under the line' );
		$c = ZDZ_LLM_Ledger::reserve( 0.6, $lines, true, 60 );
		$this->assertSame( array( 'id' => 0, 'usd' => 0.0 ), $c, 'nothing left: nothing booked' );
		$this->assertSame( 1000, self::held() );
		// the first call finishes: its real cost is written, then its hold released
		ZDZ_LLM_Ledger::write( array( 'caller' => 'receipts', 'usd_milli' => 250 ) );
		$this->assertTrue( ZDZ_LLM_Ledger::release( $a['id'] ) );
		$this->assertSame( 400, self::held() );
		$d = ZDZ_LLM_Ledger::reserve( 1.0, $lines, true, 60 );
		$this->near( 0.35, $d['usd'], 0.0001, '1.000 - 0.250 spent - 0.400 still held' );
		$this->assertFalse( ZDZ_LLM_Ledger::release( 0 ) );
	}

	public function test_the_site_line_and_the_account_line_both_hold(): void {
		ZDZ_LLM_Ledger::write( array( 'caller' => 'receipts', 'usd_milli' => 1800 ) );
		$r = ZDZ_LLM_Ledger::reserve( 1.0, self::lines( 5.0, 2.0 ), true, 60 );
		$this->near( 0.2, $r['usd'], 0.0001, 'the account line is lower here' );
		$this->assertSame( 200, ZDZ_LLM_Ledger::room( array( 'app' => 1800, 'all' => 1800 ), self::lines( 5.0, 2.0 ), true ) );
		$this->assertSame( 3200, ZDZ_LLM_Ledger::room( array( 'app' => 1800, 'all' => 1800 ), self::lines( 5.0, 5.0 ), true ) );
		$this->assertSame( -800, ZDZ_LLM_Ledger::room( array( 'app' => 1800, 'all' => 1800 ), self::lines( 1.0, 5.0 ), true ) );
	}

	public function test_a_hold_expires_on_its_own(): void {
		$this->assertSame( 30, ZDZ_LLM_Ledger::life( 5 ), 'never under 30 s' );
		$this->assertSame( 900, ZDZ_LLM_Ledger::life( 100000 ), 'never over 15 minutes' );
		$this->assertSame( 181, ZDZ_LLM_Ledger::life( 180.2 ) );
		$lines = self::lines( 1.0, 1.0 );
		$a = ZDZ_LLM_Ledger::reserve( 0.9, $lines, true, 45 );   // a process that dies and never releases it
		$this->near( 0.9, $a['usd'], 0.0001 );
		$this->t += 44;
		$b = ZDZ_LLM_Ledger::reserve( 0.9, $lines, true, 45 );
		$this->near( 0.1, $b['usd'], 0.0001, 'still live' );
		$this->t += 2;
		$c = ZDZ_LLM_Ledger::reserve( 1.0, $lines, true, 45 );
		$this->near( 0.9, $c['usd'], 0.0001, 'the first hold expired; the second (0.1) still counts' );
		// the daily prune deletes holds that expired more than a day ago
		$this->t += 2 * 86400;
		ZDZ_LLM_Ledger::prune();
		$this->assertSame( array(), $GLOBALS['wpdb']->rows( 'zdz_llm_holds' ) );
	}

	public function test_two_calls_at_once_never_overspend(): void {
		$lines = self::lines( 1.5, 1.5 );
		$second = null;
		// Both read the month before either has written: the second runs completely inside the first's insert.
		$GLOBALS['wpdb']->on_hold_insert = function () use ( $lines, &$second ) {
			$second = ZDZ_LLM_Ledger::reserve( 1.0, $lines, true, 60 );
		};
		$first = ZDZ_LLM_Ledger::reserve( 1.0, $lines, true, 60 );
		$this->assertNotNull( $second );
		$this->assertLessThanOrEqual( 1500, self::held(), 'the two holds together stay under the line' );
		$this->near( 1.5, $first['usd'] + $second['usd'], 0.0001, 'and nothing is lost: the headroom is shared, not doubled' );
		$this->near( 1.0, $second['usd'], 0.0001 );
		$this->near( 0.5, $first['usd'], 0.0001, 'the one that added up last shrank its own hold' );

		// The other order: the first has written its hold when the second reads.
		$GLOBALS['wpdb'] = new Zdz_Test_Wpdb();
		$a = ZDZ_LLM_Ledger::reserve( 1.0, $lines, true, 60 );
		$b = ZDZ_LLM_Ledger::reserve( 1.0, $lines, true, 60 );
		$this->near( 1.0, $a['usd'], 0.0001 );
		$this->near( 0.5, $b['usd'], 0.0001 );
		$this->assertSame( 1500, self::held() );

		// Many at once, nested: never above the line.
		$GLOBALS['wpdb'] = new Zdz_Test_Wpdb();
		$got  = array();
		$nest = function ( $depth ) use ( &$nest, &$got, $lines ) {
			if ( $depth > 0 ) {
				$GLOBALS['wpdb']->on_hold_insert = function () use ( $nest, $depth ) {
					$nest( $depth - 1 );
				};
			}
			$got[] = ZDZ_LLM_Ledger::reserve( 0.7, $lines, true, 60 );
		};
		$nest( 4 );
		$this->assertLessThanOrEqual( 1500, self::held() );
	}

	public function test_spend_that_cannot_be_read_is_refused(): void {
		$GLOBALS['wpdb']->fail_reads = true;
		$this->assertNull( ZDZ_LLM_Ledger::totals() );
		$this->assertNull( ZDZ_LLM_Ledger::reserve( 0.5, self::lines( 1, 1 ), true, 60 ) );
		$this->assertNull( ZDZ_LLM_Ledger::month_to_date() );
		$GLOBALS['wpdb']->fail_reads  = false;
		$GLOBALS['wpdb']->fail_insert = true;
		$this->assertNull( ZDZ_LLM_Ledger::reserve( 0.5, self::lines( 1, 1 ), true, 60 ), 'a hold that cannot be written: refuse' );
		$this->assertFalse( ZDZ_LLM_Ledger::write( array( 'caller' => 'receipts', 'usd_milli' => 400 ) ) );
		$GLOBALS['wpdb']->fail_insert = false;
		$t = ZDZ_LLM_Ledger::totals();
		$this->assertSame( 400, $t['app'], 'spend whose row could not be written still counts in this process' );
	}

	public function test_summary_is_numbers_and_code_words(): void {
		foreach ( array( array( 'receipts', 'read_document', 1200, 'ok' ), array( 'leads', 'write', 300, 'refused_month' ) ) as $i => $x ) {
			ZDZ_LLM_Ledger::write( array( 'caller' => $x[0], 'role' => $x[1], 'model' => 'example-large-1', 'usd_milli' => $x[2], 'outcome' => $x[3], 'in_tokens' => 100, 'cache_read' => 300, 'ts' => $this->t + $i ) );
		}
		$s = ZDZ_LLM_Ledger::summary( ZDZ_LLM_Ledger::rows_since( ZDZ_LLM_Ledger::month_start() ) );
		$this->assertSame( 2, $s['calls'] );
		$this->near( 1.5, $s['usd'], 0.0001 );
		$this->near( 0.75, $s['cache_share'], 0.0001 );
		$this->assertSame( 'leads', $s['recent'][0]['caller'], 'newest first' );
		$this->assertSame( 1, $s['outcomes']['refused_month'] );
	}
}
