<?php
/**
 * The Ai pages: the setup form's values are checked and never guessed, the usage page says plainly when no monthly
 * budget is set, and the golden sets the Ai checks run are whole, neutral, and pass with their own example answers.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/llm-test-doubles.php';

final class LlmAdminTest extends TestCase {

	const PRIMARY = 'example-large-1';
	const QUICK   = 'example-small-1';

	protected function setUp(): void {
		$GLOBALS['zdz_test_options'] = array(
			'zdz_model_slot_llm_primary' => self::PRIMARY,
			'zdz_model_slot_llm_quick'   => self::QUICK,
			'zdz_llm_model_prices'       => array( self::PRIMARY => array( 3, 3.75, 6, 0.3, 15 ) ),
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_filters'] );
	}

	/* ── admin: setup values and the usage page ─────────────────────────── */

	public function test_setup_values_are_checked_never_guessed(): void {
		$c = ZDZ_LLM_Admin::clean_setup( array(
			'models'     => array( 'primary' => ' example-large-1 ', 'quick' => 'bad model!', 'fallback' => '' ),
			'prices'     => array(
				array( 'model' => 'example-large-1', 'p' => array( '1', '1.25', '2', '0.1', '5' ) ),
				array( 'model' => 'not-a-slot', 'p' => array( 1, 1, 1, 1, 1 ) ),
				array( 'model' => 'example-large-1', 'p' => array( 1, 1, 1, 1 ) ),
			),
			'geo'        => 'EU',
			'geo_factor' => '0.5',
			'budget'     => array( 'app' => '', 'all' => '40' ),
		) );
		$this->assertSame( array( 'primary' => 'example-large-1', 'quick' => '', 'fallback' => '' ), $c['models'] );
		$this->assertSame( array( 'example-large-1' => array( 1.0, 1.25, 2.0, 0.1, 5.0 ) ), $c['prices'] );
		$this->assertSame( array( 'inference_geo' => 'eu', 'geo_price_factor' => 1.0 ), $c['connection'] );
		$this->assertSame( array(), $c['budgets'], 'no site line, no budget' );
		$c = ZDZ_LLM_Admin::clean_setup( array( 'budget' => array( 'app' => '25.5', 'ceiling' => '-3' ) ) );
		$this->assertSame( array( 'app' => 25.5 ), $c['budgets'] );
	}

	public function test_the_usage_page_says_when_no_budget_is_set(): void {
		unset( $GLOBALS['zdz_test_options']['zdz_llm_budgets'] );
		$h = ZDZ_LLM_Admin::usage_html( ZDZ_LLM_Ledger::summary( array() ), array( 'app' => 0.0, 'all' => 0.0 ), 'October 2026' );
		$this->assertStringContainsString( 'No monthly budget is set', $h );
		$this->assertStringContainsString( 'no budget set', $h );
		$GLOBALS['zdz_test_options']['zdz_llm_budgets'] = array( 'app' => 10 );
		$h = ZDZ_LLM_Admin::usage_html( ZDZ_LLM_Ledger::summary( array() ), array( 'app' => 2.5, 'all' => 2.5 ), 'October 2026' );
		$this->assertStringNotContainsString( 'No monthly budget is set', $h );
		$this->assertStringContainsString( '$2.50', $h );
		$this->assertStringContainsString( '25%', $h );
	}

	public function test_setup_shows_what_is_missing_and_never_a_secret(): void {
		$GLOBALS['zdz_test_filters']['zdz_anthropic_workspace_id'] = function () {
			return '';
		};
		$h = ZDZ_LLM_Admin::setup_html( '<form>' );
		$this->assertStringContainsString( 'not set: nothing is sent', $h );
		$this->assertStringContainsString( 'name="prices[0][model]" value="example-large-1"', $h );
		$this->assertStringContainsString( 'name="models[primary]" value="example-large-1"', $h );
		$this->assertStringNotContainsString( 'x-api-key', $h );
		$GLOBALS['zdz_test_filters']['zdz_anthropic_workspace_id'] = function () {
			return 'example-workspace';
		};
		$h = ZDZ_LLM_Admin::setup_html( '<form>' );
		$this->assertStringNotContainsString( 'example-workspace', $h, 'whether it is set, never its value' );
		$this->assertStringNotContainsString( 'not set: nothing is sent', $h );
	}

	/* ── the golden sets ────────────────────────────────────────────────── */

	public function test_the_golden_sets_are_whole_and_their_answers_fit_their_shapes(): void {
		$dir   = dirname( __DIR__, 2 ) . '/zorderz/data/llm-golden';
		$cases = ZDZ_LLM_Admin::golden( $dir );
		$roles = array();
		foreach ( $cases as $id => $c ) {
			$roles[ $c['role'] ] = true;
			$req = ZDZ_LLM_Admin::request_for( $c, $dir );
			$this->assertNotEmpty( $req['messages'], $id );
			foreach ( $req['messages'] as $m ) {
				foreach ( $m['content'] as $p ) {
					if ( in_array( $p['type'], array( 'image', 'pdf' ), true ) ) {
						$this->assertGreaterThan( 100, strlen( base64_decode( $p['data'] ) ), $id . ': its drawn document is there' );
					}
				}
			}
			if ( ! empty( $c['json_schema'] ) ) {
				$this->assertTrue( ZDZ_Core_LLM::matches( json_decode( $c['example_answer'], true ), $c['json_schema'] ), $id );
				$this->assertDoesNotMatchRegularExpression( '/\b[a-z]+-[a-z]+\b/', wp_json_encode( $c['json_schema']['properties'] ?? array() ), $id . ': a hyphenated id in a schema would be refused wherever a word of it is a surname' );
			}
			$this->assertTrue( ZDZ_LLM_Admin::score( $c, array( 'text' => (string) $c['example_answer'], 'json' => json_decode( (string) $c['example_answer'], true ), 'citations' => array( array( 'url' => 'https://example.com' ) ) ), new Zdz_Test_Coder() )[0], $id . ': its example answer scores a pass' );
		}
		$this->assertSame( array_keys( ZDZ_Core_LLM::ROLES ), array_keys( $roles ) );
		$this->assertGreaterThanOrEqual( 35, count( $cases ) );
		$this->assertSame( 1, ZDZ_Core_LLM::pdf_pages( base64_encode( (string) file_get_contents( $dir . '/media/invoice-ex-2002.pdf' ) ) ) );
		$by = ZDZ_LLM_Admin::summarize( array(
			array( 'role' => 'chat', 'pass' => true, 'ms' => 4000, 'usd' => 0.01, 'model' => self::PRIMARY ),
			array( 'role' => 'chat', 'pass' => false, 'ms' => 9000, 'usd' => 0.02, 'model' => self::QUICK ),
		) );
		$this->assertSame( 15, $by['chat']['line_s'], 'the quick-model line comes from the primary model\'s times only, never under 15 s' );
	}
}
