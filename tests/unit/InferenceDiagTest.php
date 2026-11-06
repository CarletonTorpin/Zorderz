<?php
/**
 * Schedule inference: the no-date diagnostic is bounded and never logs a customer's text.
 *
 * - shape() keeps a line's form (first letter + length per word, digits, punctuation, a short
 *   code in parentheses) and drops what it says;
 * - diag() logs a document at most once a day, at most diag_budget() lines per tick, and only a
 *   line actually written uses the budget;
 * - a parser's no-date hint with 'diag' reaches the log through cron_tick's own path.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $k ) { return $GLOBALS['zdz_test_transients'][ $k ] ?? false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['zdz_test_transients'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'wp_doing_cron' ) ) {
	function wp_doing_cron() { return ! empty( $GLOBALS['zdz_test_doing_cron'] ); }
}

require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-inference.php';

final class InferenceDiagTest extends TestCase {

	private $log;
	private $prev_log;

	protected function setUp(): void {
		$GLOBALS['zdz_test_transients'] = array();
		$GLOBALS['zdz_test_filters']    = $GLOBALS['zdz_test_filters'] ?? array();
		unset( $GLOBALS['zdz_test_filters']['zdz_inference_diag_budget'] );
		$this->reset_budget();
		$this->log      = tempnam( sys_get_temp_dir(), 'zdz-infer' );
		$this->prev_log = ini_get( 'error_log' );
		ini_set( 'error_log', $this->log );
	}

	protected function tearDown(): void {
		ini_set( 'error_log', $this->prev_log );
		@unlink( $this->log );
		unset( $GLOBALS['zdz_test_filters']['zdz_inference_diag_budget'], $GLOBALS['zdz_test_filters']['zdz_inference_sources'], $GLOBALS['zdz_test_filters']['zdz_inference_parser'], $GLOBALS['zdz_test_filters']['zdz_inference_force_tick'] );
		$GLOBALS['zdz_test_doing_cron'] = false;
		$this->reset_budget();
		unset( $GLOBALS['zdz_test_transients'], $GLOBALS['zdz_test_options'] );
	}

	private function reset_budget(): void {
		$p = new ReflectionProperty( 'Zdz_Inference', 'diag_left' );
		$p->setAccessible( true );
		$p->setValue( null, null );
	}

	private function logged(): string {
		return (string) file_get_contents( $this->log );
	}

	public function test_shape_keeps_form_not_words(): void {
		$this->assertSame( 'A4 J6, O3 S6', Zdz_Inference::shape( 'Alex Jordan, Oak Street' ) );
		$this->assertSame( '12345 E7 - (AB)', Zdz_Inference::shape( '12345 Example - (AB)' ) );
		$this->assertSame( 'J4 P4', Zdz_Inference::shape( 'José Peña' ) );
		$this->assertSame( '', Zdz_Inference::shape( '' ) );
		$this->assertSame( 'S5 8 A2 8/14', Zdz_Inference::shape( 'Sched 8 AM 8/14' ) );
	}

	public function test_diag_logs_shape_never_the_text(): void {
		$wrote = Zdz_Inference::diag( 'estimates', '1042', 'billed, no date on the document or its invoice', array( 'location' => 'Location - Riley Morgan, Example Town (AB)' ) );
		$this->assertTrue( $wrote );
		$log = $this->logged();
		$this->assertStringContainsString( 'estimates #1042 produced no date', $log );
		$this->assertStringContainsString( 'location="L8 - R5 M6, E7 T4 (AB)"', $log );
		$this->assertStringNotContainsString( 'Riley', $log );
		$this->assertStringNotContainsString( 'Morgan', $log );
		$this->assertStringNotContainsString( 'Example Town', $log );
	}

	public function test_diag_once_a_day_per_document(): void {
		$this->assertTrue( Zdz_Inference::diag( 'estimates', '7', 'no date' ) );
		$this->assertFalse( Zdz_Inference::diag( 'estimates', '7', 'no date' ), 'the same document is not logged twice in a day' );
		$this->assertTrue( Zdz_Inference::diag( 'invoices', '7', 'no date' ), 'another source is another document' );
		$this->assertSame( 2, substr_count( $this->logged(), 'produced no date' ) );
		$this->assertSame( 2, count( $GLOBALS['zdz_test_transients'] ) );
		foreach ( array_keys( $GLOBALS['zdz_test_transients'] ) as $k ) {
			$this->assertSame( 0, strpos( $k, Zdz_Inference::DIAG_PREFIX ) );
		}
	}

	public function test_budget_counts_only_written_lines(): void {
		$GLOBALS['zdz_test_filters']['zdz_inference_diag_budget'] = static function () { return 2; };
		$this->reset_budget();
		Zdz_Inference::diag( 'estimates', '1', 'no date' );
		Zdz_Inference::diag( 'estimates', '1', 'no date' ); // already reported: costs nothing
		Zdz_Inference::diag( 'estimates', '1', 'no date' );
		$this->assertTrue( Zdz_Inference::diag( 'estimates', '2', 'no date' ), 'second written line fits the budget of 2' );
		$this->assertFalse( Zdz_Inference::diag( 'estimates', '3', 'no date' ), 'third written line is over budget' );
		$this->assertSame( 2, substr_count( $this->logged(), 'produced no date' ) );
	}

	public function test_zero_budget_logs_nothing(): void {
		$GLOBALS['zdz_test_filters']['zdz_inference_diag_budget'] = static function () { return 0; };
		$this->reset_budget();
		$this->assertFalse( Zdz_Inference::diag( 'estimates', '9', 'no date' ) );
		$this->assertSame( '', $this->logged() );
	}

	public function test_parser_no_date_hint_reaches_the_diagnostic_through_cron_tick(): void {
		$GLOBALS['zdz_test_doing_cron'] = true;
		$GLOBALS['zdz_test_filters']['zdz_inference_sources'] = static function () {
			return array(
				array(
					'key'    => 'estimates',
					'parser' => 'neutral',
					'reader' => static function () {
						return array( array( 'id' => 55, 'note' => 'Location - Casey Quinn, Example Road' ) );
					},
				),
			);
		};
		$GLOBALS['zdz_test_filters']['zdz_inference_parser'] = static function () {
			return array(
				'neutral' => static function ( $doc ) {
					return array( 'diag' => 'no date on the document', 'diag_text' => array( 'note' => $doc['note'] ) );
				},
			);
		};
		Zdz_Inference::cron_tick();
		$log = $this->logged();
		$this->assertStringContainsString( 'estimates #55 produced no date', $log );
		$this->assertStringNotContainsString( 'Casey', $log );
		$this->assertStringNotContainsString( 'Quinn', $log );
		$this->assertStringContainsString( 'note="L8 - C5 Q5, E7 R4"', $log );
	}
}
