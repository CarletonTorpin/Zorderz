<?php
/**
 * The model policy every road to an Ai shares:
 *
 *   - another model is tried after a failure it might not have (the network, 400 / 404 / 408 / 413 / 422, 429, 5xx,
 *     529), never after 401 / 403 (a key problem); a missing model or a refused body skips the retry of the same model;
 *   - a model the provider answered 404 for is remembered for an hour and not asked again as a fallback;
 *   - a model without web search never gets a web search: the router's `web` role uses a slot that can search, and
 *     the gateway asks the `web` slot for findings first (a short question only) or tells the model it has none.
 *
 * No model name is real: example handles only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/llm-test-doubles.php';
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $k ) { return $GLOBALS['zdz_test_transients'][ $k ] ?? false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['zdz_test_transients'][ $k ] = $v; return true; }
}
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-model-registry.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-core-poe.php';

if ( ! class_exists( 'ZDZ_Test_Kwa_Poe' ) ) {
	/** The gateway with its HTTP answers scripted: [ code, body ] or a WP_Error per post. */
	class ZDZ_Test_Kwa_Poe extends ZDZ_Core_Poe {
		public $script = array();
		public $sent   = array();
		protected function post( array $body, int $budget_s = 0 ): array {
			$this->sent[] = $body;
			$next         = array_shift( $this->script );
			if ( $next instanceof WP_Error ) {
				return array( 0, '', $next );
			}
			return array( (int) $next[0], (string) $next[1], null );
		}
	}
}

final class KWireModelPolicyTest extends TestCase {

	/** @var Zdz_Test_Router */
	private $r;

	const PRIMARY  = 'example-large-1';
	const QUICK    = 'example-small-1';
	const FALLBACK = 'example-large-0';

	protected function setUp(): void {
		$GLOBALS['wpdb']                = new Zdz_Test_Wpdb();
		$GLOBALS['zdz_test_transients'] = array();
		$GLOBALS['zdz_test_options']    = array(
			'zdz_model_slot_llm_primary'  => self::PRIMARY,
			'zdz_model_slot_llm_quick'    => self::QUICK,
			'zdz_model_slot_llm_fallback' => self::FALLBACK,
			'zdz_llm_model_prices'        => array(
				self::PRIMARY  => array( 3, 3.75, 6, 0.3, 15 ),
				self::QUICK    => array( 1, 1.25, 2, 0.1, 5 ),
				self::FALLBACK => array( 6, 7.5, 12, 0.6, 30 ),
			),
			'zdz_llm_budgets'             => array( 'app' => 10, 'all' => 12 ),
			'zdz_llm_ledger_db'           => ZDZ_LLM_Ledger::DB_VERSION,
		);
		$this->r             = new Zdz_Test_Router();
		$this->r->test_coder = new Zdz_Test_Coder();
		$r                   = $this->r;
		ZDZ_LLM_Ledger::$now = function () use ( $r ) {
			return (int) $r->clock;
		};
		ZDZ_LLM_Ledger::forget();
	}

	protected function tearDown(): void {
		ZDZ_LLM_Ledger::$now = null;
		ZDZ_LLM_Ledger::forget();
		unset( $GLOBALS['wpdb'], $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_transients'] );
		unset( $GLOBALS['zdz_test_filters']['zdz_model_web_capable'], $GLOBALS['zdz_test_filters']['zdz_model_fallback'] );
	}

	private function script( array $answers ) {
		$r             = $this->r;
		$this->r->fake = new ZDZ_LLM_Fake( $answers, array(), function ( $s ) use ( $r ) {
			$r->clock += $s;
		} );
		return $this->r->fake;
	}

	private function ask( $role, $text ) {
		return $this->r->handle( $role, array( 'system' => 'Answer briefly.', 'messages' => array( array( 'role' => 'user', 'content' => $text ) ) ), array( 'caller' => 'receipts', 'deadline' => $this->r->clock + 60 ) );
	}

	/* ── which failures go to another model ─────────────────────────────── */

	public function test_the_failover_statuses(): void {
		foreach ( array( 0, 400, 404, 408, 413, 422, 429, 500, 502, 503, 504, 529, 599 ) as $st ) {
			$this->assertTrue( ZDZ_Model_Registry::fails_over( $st ), "$st fails over" );
		}
		foreach ( array( 401, 403, 200, 302, 409 ) as $st ) {
			$this->assertFalse( ZDZ_Model_Registry::fails_over( $st ), "$st does not" );
		}
		$this->assertFalse( ZDZ_Core_LLM::fails_over( ZDZ_LLM_Wire::error( 'auth', 403 ) ), 'a key problem: never another model' );
		$this->assertFalse( ZDZ_Core_LLM::fails_over( ZDZ_LLM_Wire::error( 'refused', 0 ) ) );
		$this->assertFalse( ZDZ_Core_LLM::fails_over( ZDZ_LLM_Wire::error( 'other', 0 ) ), 'no status, no known kind: not guessed' );
		$this->assertTrue( ZDZ_Core_LLM::fails_over( ZDZ_LLM_Wire::error( 'other', 404 ) ) );
		$this->assertTrue( ZDZ_Core_LLM::fails_over( ZDZ_LLM_Wire::error( 'bad_request', 413 ) ) );
		$this->assertTrue( ZDZ_Core_LLM::fails_over( ZDZ_LLM_Wire::error( 'network', 0 ) ) );
	}

	public function test_a_refused_body_goes_to_the_fallback_without_retrying_the_same_model(): void {
		$fake = $this->script( array( ZDZ_LLM_Wire::error( 'bad_request', 400 ), ZDZ_LLM_Fake::answer( 'Done.' ) ) );
		$res  = $this->ask( 'chat', 'Hello' );
		$this->assertIsArray( $res );
		$this->assertSame( array( self::PRIMARY, self::FALLBACK ), array_column( $fake->requests, 'model' ) );
		$this->assertTrue( $res['fallback'] );
	}

	public function test_a_key_problem_is_never_sent_to_another_model(): void {
		$fake = $this->script( array( ZDZ_LLM_Wire::error( 'auth', 403 ), ZDZ_LLM_Fake::answer( 'never' ) ) );
		$res  = $this->ask( 'chat', 'Hello' );
		$this->assertCount( 1, $fake->requests );
		$this->assertSame( 'zdz_llm_auth', $res->get_error_code() );
	}

	/* ── a 404 is remembered for an hour ────────────────────────────────── */

	public function test_a_missing_model_is_remembered_and_skipped_as_a_fallback(): void {
		$fake = $this->script( array( ZDZ_LLM_Wire::error( 'other', 404 ), ZDZ_LLM_Wire::error( 'other', 404 ) ) );
		$res  = $this->ask( 'chat', 'Hello' );
		$this->assertInstanceOf( 'WP_Error', $res );
		$this->assertSame( array( self::PRIMARY, self::FALLBACK ), array_column( $fake->requests, 'model' ), 'no retry of a missing model' );
		$this->assertTrue( ZDZ_Model_Registry::is_gone( self::PRIMARY, 'anthropic' ) );
		$this->assertTrue( ZDZ_Model_Registry::is_gone( self::FALLBACK, 'anthropic' ) );
		$this->assertFalse( ZDZ_Model_Registry::is_gone( self::FALLBACK, 'poe' ), 'remembered per provider' );

		// the next call: the primary is asked again, but the fallback that answered 404 is skipped for the quick model
		$GLOBALS['zdz_test_transients'] = array();
		ZDZ_Model_Registry::mark_gone( self::FALLBACK, 'anthropic' );
		$busy = ZDZ_LLM_Wire::error( 'overloaded', 529 );
		$fake = $this->script( array( $busy, $busy, ZDZ_LLM_Fake::answer( 'Done.' ) ) );
		$res  = $this->ask( 'chat', 'Hello' );
		$this->assertIsArray( $res );
		$this->assertSame( array( self::PRIMARY, self::PRIMARY, self::QUICK ), array_column( $fake->requests, 'model' ) );
	}

	/* ── web search only where a model can search ───────────────────────── */

	public function test_the_web_role_uses_a_slot_that_can_search(): void {
		$GLOBALS['zdz_test_filters']['zdz_model_web_capable'] = function () { return array( self::PRIMARY => false ); };
		$this->assertSame( self::FALLBACK, ZDZ_Core_LLM::role( 'web' )['model'] );
		$this->assertSame( self::PRIMARY, ZDZ_Core_LLM::role( 'chat' )['model'], 'other roles keep their model' );
		$fake = $this->script( array( ZDZ_LLM_Fake::answer( 'In 1937.' ) ) );
		$this->assertIsArray( $this->ask( 'web', 'In what year did the bridge open?' ) );
		$this->assertSame( self::FALLBACK, $fake->requests[0]['model'] );

		$GLOBALS['zdz_test_filters']['zdz_model_web_capable'] = function () { return array( self::PRIMARY => false, self::FALLBACK => false, self::QUICK => false ); };
		$this->assertSame( '', ZDZ_Core_LLM::role( 'web' )['model'], 'none can search: no model, so nothing is sent' );
	}

	public function test_the_gateway_asks_the_web_slot_first_for_a_model_that_cannot_search(): void {
		$GLOBALS['zdz_test_options']['zdz_model_slot_web'] = 'example-search-1';
		$GLOBALS['zdz_test_filters']['zdz_model_web_capable'] = function () { return array( 'example-plain-1' => false ); };
		$poe         = new ZDZ_Test_Kwa_Poe( 'k-test', 'example-plain-1' );
		$poe->script = array(
			array( 200, json_encode( array( 'choices' => array( array( 'message' => array( 'content' => 'It opened in 1937 (https://example.com/a).' ) ) ) ) ) ),
			array( 200, json_encode( array( 'choices' => array( array( 'message' => array( 'content' => 'In 1937.' ) ) ) ) ) ),
		);
		$r = $poe->request( array( array( 'role' => 'user', 'content' => 'When did the bridge open?' ) ), 0.0, array( 'web_search' => true ) );
		$this->assertSame( 'In 1937.', $r['text'] );
		$this->assertSame( 'example-search-1', $poe->sent[0]['model'] );
		$this->assertTrue( $poe->sent[0]['web_search'], 'the slot that can search searches' );
		$this->assertSame( 'example-plain-1', $poe->sent[1]['model'] );
		$this->assertArrayNotHasKey( 'web_search', $poe->sent[1], 'never to a model that cannot search' );
		$this->assertStringContainsString( 'WEB FINDINGS', $poe->sent[1]['messages'][0]['content'] );

		// business data never travels to the web: the model is told it has no findings instead
		$poe         = new ZDZ_Test_Kwa_Poe( 'k-test', 'example-plain-1' );
		$poe->script = array( array( 200, json_encode( array( 'choices' => array( array( 'message' => array( 'content' => 'I could not look it up.' ) ) ) ) ) ) );
		$poe->request( array( array( 'role' => 'user', 'content' => str_repeat( 'invoice line ', 500 ) ) ), 0.0, array( 'web_search' => true ) );
		$this->assertCount( 1, $poe->sent, 'no web call' );
		$this->assertSame( ZDZ_Core_Poe::no_web_note(), $poe->sent[0]['messages'][0]['content'] );
	}

	public function test_the_gateway_fails_over_on_the_list_and_never_on_a_key_problem(): void {
		$GLOBALS['zdz_test_filters']['zdz_model_fallback'] = function () { return array( 'example-a' => 'example-b' ); };
		$ok = array( 200, json_encode( array( 'choices' => array( array( 'message' => array( 'content' => 'Fine.' ) ) ) ) ) );

		$poe         = new ZDZ_Test_Kwa_Poe( 'k-test', 'example-a' );
		$poe->script = array( array( 404, '{}' ), $ok );
		$r           = $poe->request( array( array( 'role' => 'user', 'content' => 'Hi' ) ) );
		$this->assertSame( 'example-b', $r['model'] );
		$this->assertTrue( ZDZ_Model_Registry::is_gone( 'example-a', 'poe' ) );

		$poe         = new ZDZ_Test_Kwa_Poe( 'k-test', 'example-a' );
		$poe->script = array( array( 401, '{}' ), $ok );
		$r           = $poe->request( array( array( 'role' => 'user', 'content' => 'Hi' ) ) );
		$this->assertInstanceOf( 'WP_Error', $r );
		$this->assertCount( 1, $poe->sent, 'a refused key is not a model problem' );

		ZDZ_Model_Registry::mark_gone( 'example-b', 'poe' );
		$poe         = new ZDZ_Test_Kwa_Poe( 'k-test', 'example-a' );
		$poe->script = array( array( 503, '{}' ), $ok );
		$r           = $poe->request( array( array( 'role' => 'user', 'content' => 'Hi' ) ) );
		$this->assertInstanceOf( 'WP_Error', $r );
		$this->assertCount( 1, $poe->sent, 'a fallback that answered 404 within the hour is not asked' );
	}
}
