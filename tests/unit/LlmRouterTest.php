<?php
/**
 * The Ai router, end to end against a scripted provider:
 *
 *   - roles resolve through the Model Registry's slots, and no model name is written in the code;
 *   - nothing is sent without a model, its prices, a monthly budget, or the customer-detail coder (fail closed);
 *   - a round trip codes customer details going out and brings the codes back, with one ledger row and no hold left;
 *   - short handles carry a check character that catches every one-character slip and neighbour swap;
 *   - retries, the fallback and the quick model follow the time left; primary_only only ever narrows;
 *   - every refusal happens before anything reaches the provider.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/llm-test-doubles.php';

final class LlmRouterTest extends TestCase {

	/** @var Zdz_Test_Router */
	private $r;

	const PRIMARY  = 'example-large-1';
	const QUICK    = 'example-small-1';
	const FALLBACK = 'example-large-0';

	protected function setUp(): void {
		$GLOBALS['wpdb']             = new Zdz_Test_Wpdb();
		$GLOBALS['zdz_test_options'] = array(
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
		unset( $GLOBALS['wpdb'], $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_user_roles'], $GLOBALS['zdz_test_current_user'] );
	}

	/* ── helpers ─────────────────────────────────────────────────────────── */

	private function script( array $answers ) {
		$r             = $this->r;
		$this->r->fake = new ZDZ_LLM_Fake( $answers, array(), function ( $s ) use ( $r ) {
			$r->clock += $s;
		} );
		return $this->r->fake;
	}

	private function ask( $role, array $req, array $ctx = array() ) {
		return $this->r->handle( $role, $req, $ctx + array( 'caller' => 'receipts', 'deadline' => $this->r->clock + 60 ) );
	}

	private static function q( $text ) {
		return array( 'system' => 'Answer briefly.', 'messages' => array( array( 'role' => 'user', 'content' => $text ) ) );
	}

	private function ledger_rows() {
		return $GLOBALS['wpdb']->rows( 'zdz_llm_ledger' );
	}

	private function last_outcome() {
		$rows = $this->ledger_rows();
		return $rows ? end( $rows )['outcome'] : null;
	}

	private function assertRefusedBeforeSending( $res, $code, $fake ) {
		$this->assertInstanceOf( 'WP_Error', $res, $code );
		$this->assertSame( 'zdz_llm_' . $code, $res->get_error_code() );
		$this->assertSame( array(), $fake->requests, $code . ': nothing reached the provider' );
		$this->assertSame( substr( 'refused_' . $code, 0, 24 ), $this->last_outcome(), $code . ': one ledger row says why' );
		$this->assertSame( array(), $GLOBALS['wpdb']->rows( 'zdz_llm_holds' ), $code . ': no hold left booked' );
	}

	/* ── models come from the registry ──────────────────────────────────── */

	public function test_roles_resolve_through_the_registry_slots(): void {
		$this->assertSame( array( 'primary' => self::PRIMARY, 'quick' => self::QUICK, 'fallback' => self::FALLBACK ), ZDZ_Core_LLM::models() );
		$this->assertSame( array( self::PRIMARY, self::QUICK, self::FALLBACK ), ZDZ_Core_LLM::approved_models() );
		$chat = ZDZ_Core_LLM::role( 'chat' );
		$this->assertSame( self::PRIMARY, $chat['model'] );
		$this->assertSame( 'anthropic', $chat['provider'] );
		$this->assertNull( ZDZ_Core_LLM::role( 'translate' ) );

		$GLOBALS['zdz_test_options']['zdz_llm_role_overrides'] = array(
			'write' => array( 'model' => self::QUICK, 'cap_usd' => 2, 'effort' => 'high' ),
			'chat'  => array( 'model' => 'not-one-of-the-slots', 'cap_usd' => 9, 'effort' => 'max' ),
		);
		$w = ZDZ_Core_LLM::role( 'write' );
		$this->assertSame( self::QUICK, $w['model'] );
		$this->assertSame( 2.0, $w['cap_usd'] );
		$this->assertSame( 'high', $w['effort'] );
		$c = ZDZ_Core_LLM::role( 'chat' );
		$this->assertSame( self::PRIMARY, $c['model'], 'a model no slot names is ignored' );
		$this->assertSame( 1.0, $c['cap_usd'], 'a cap above the most an admin may set is ignored' );
		$this->assertSame( 'medium', $c['effort'] );

		$GLOBALS['zdz_test_filters']['zdz_model_for'] = function ( $m, $slot ) {
			return 'llm_primary' === $slot ? 'example-large-2' : $m;
		};
		$this->assertSame( 'example-large-2', ZDZ_Core_LLM::role( 'extract' )['model'], 'a pack or plugin sets a slot through the registry' );
	}

	public function test_an_empty_slot_never_borrows_the_gateway_base_model(): void {
		unset( $GLOBALS['zdz_test_options']['zdz_model_slot_llm_primary'], $GLOBALS['zdz_test_options']['zdz_model_slot_llm_quick'], $GLOBALS['zdz_test_options']['zdz_model_slot_llm_fallback'] );
		$GLOBALS['zdz_test_filters']['zdz_model_base_default'] = function () {
			return 'gateway-bot';
		};
		$this->assertSame( 'gateway-bot', ZDZ_Model_Registry::model_for( 'chat' ), 'other slots still inherit the base' );
		$this->assertSame( array( 'primary' => '', 'quick' => '', 'fallback' => '' ), ZDZ_Core_LLM::models() );
		$this->assertSame( array(), ZDZ_Core_LLM::approved_models() );
		$fake = $this->script( array( ZDZ_LLM_Fake::answer( 'never' ) ) );
		$this->assertRefusedBeforeSending( $this->ask( 'chat', self::q( 'Hello' ) ), 'model', $fake );
	}

	public function test_no_model_name_or_price_is_written_in_the_code(): void {
		$root  = dirname( __DIR__, 2 ) . '/zorderz';
		$files = array_merge(
			array( "$root/inc/class-zdz-core-llm.php", "$root/inc/class-zdz-llm-guard.php", "$root/inc/class-zdz-llm-ledger.php", "$root/inc/class-zdz-llm-admin.php", "$root/inc/class-zdz-model-registry.php", "$root/db/migrate-llm-ledger.php" ),
			glob( "$root/inc/llm/*.php" ),
			glob( "$root/data/llm-golden/*.json" )
		);
		$this->assertGreaterThan( 10, count( $files ) );
		foreach ( $files as $f ) {
			$src = (string) file_get_contents( $f );
			$this->assertDoesNotMatchRegularExpression( '/\b(claude|opus|sonnet|haiku|gemini|gpt-?\d|llama|mistral|grok)\b/i', $src, basename( $f ) . ' names a model' );
		}
		$this->assertFalse( defined( 'ZDZ_LLM_Anthropic::PRICES' ), 'prices are configuration' );
		$this->assertNotEmpty( ZDZ_Core_LLM::price_table(), 'the prices this suite uses come from its own options' );
	}

	/* ── nothing is sent without its configuration ─────────────────────── */

	public function test_nothing_is_sent_without_prices_for_the_model(): void {
		unset( $GLOBALS['zdz_test_options']['zdz_llm_model_prices'][ self::PRIMARY ] );
		$fake = $this->script( array( ZDZ_LLM_Fake::answer( 'never' ) ) );
		$this->assertRefusedBeforeSending( $this->ask( 'chat', self::q( 'Hello' ) ), 'prices', $fake );
		$GLOBALS['zdz_test_options']['zdz_llm_model_prices'][ self::PRIMARY ] = array( 1, 2, 3 );   // not five numbers
		$this->assertNull( ZDZ_Core_LLM::prices_for( self::PRIMARY ) );
		$GLOBALS['zdz_test_options']['zdz_llm_model_prices'][ self::PRIMARY ] = array( 1, 2, 3, 4, -5 );
		$this->assertNull( ZDZ_Core_LLM::prices_for( self::PRIMARY ), 'a negative price is not a price' );
	}

	public function test_budgets_default_to_none_and_none_sends_nothing(): void {
		unset( $GLOBALS['zdz_test_options']['zdz_llm_budgets'] );
		$b = ZDZ_Core_LLM::budgets();
		$this->assertFalse( $b['set'] );
		$this->assertNull( $b['app'] );
		$fake = $this->script( array( ZDZ_LLM_Fake::answer( 'never' ) ) );
		$this->assertRefusedBeforeSending( $this->ask( 'chat', self::q( 'Hello' ) ), 'budget', $fake );

		foreach ( array( array( 'app' => -1 ), array( 'app' => 'lots' ), array( 'app' => 5e6 ), array( 'all' => 50 ), 'not an array' ) as $bad ) {
			$GLOBALS['zdz_test_options']['zdz_llm_budgets'] = $bad;
			$this->assertFalse( ZDZ_Core_LLM::budgets()['set'], wp_json_encode( $bad ) );
		}
		$GLOBALS['zdz_test_options']['zdz_llm_budgets'] = array( 'app' => 10 );
		$this->assertSame( array( 'set' => true, 'app' => 10.0, 'all' => 10.0, 'ceiling' => 10.0 ), ZDZ_Core_LLM::budgets(), 'the account line defaults to the site line' );
		$GLOBALS['zdz_test_options']['zdz_llm_budgets'] = array( 'app' => 10, 'all' => 12, 'ceiling' => 11 );
		$this->assertSame( 12.0, ZDZ_Core_LLM::budgets()['ceiling'], 'the ceiling is never shown below the stop line' );
		unset( $GLOBALS['zdz_test_options']['zdz_llm_budgets'] );
		$GLOBALS['zdz_test_filters']['zdz_llm_budgets'] = function () {
			return array( 'app' => 3 );
		};
		$this->assertTrue( ZDZ_Core_LLM::budgets()['set'], 'a pack or plugin can set the budget by filter' );
		unset( $GLOBALS['zdz_test_filters']['zdz_llm_budgets'] );

		// A budget of zero is a decision: nothing is spent.
		$GLOBALS['zdz_test_options']['zdz_llm_budgets'] = array( 'app' => 0 );
		$fake = $this->script( array( ZDZ_LLM_Fake::answer( 'never' ) ) );
		$this->assertRefusedBeforeSending( $this->ask( 'chat', self::q( 'Hello' ) ), 'month', $fake );
	}

	/**
	 * Run in its own process, where no other test file has loaded the coder (a runner that cannot do that skips it).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_without_the_coder_nothing_leaves_the_site(): void {
		$req = self::q( 'Please call Riley Example at 555-0142 about the order.' );

		$this->r->test_coder = null;
		$fake = $this->script( array( ZDZ_LLM_Fake::answer( 'never' ) ) );
		$this->assertRefusedBeforeSending( $this->ask( 'write', $req ), 'coder', $fake );

		$this->r->test_coder = new class() {
			public function encode( $t ) { return $t; }   // codes nothing back from a reader: not a coder the router accepts
		};
		$fake = $this->script( array( ZDZ_LLM_Fake::answer( 'never' ) ) );
		$this->assertRefusedBeforeSending( $this->ask( 'write', $req ), 'coder', $fake );

		if ( class_exists( 'ZDZ_Private' ) ) {
			$this->markTestSkipped( 'the real coder is loaded in this run' );
		}
		$this->r->use_real_coder = true;
		$fake = $this->script( array( ZDZ_LLM_Fake::answer( 'never' ) ) );
		$this->assertRefusedBeforeSending( $this->ask( 'web', array( 'messages' => array( array( 'role' => 'user', 'content' => 'What is the atomic number of tungsten?' ) ) ) ), 'coder', $fake );
	}

	/* ── a round trip ───────────────────────────────────────────────────── */

	public function test_a_round_trip_codes_details_out_and_back(): void {
		$fake = $this->script( array(
			function ( $n ) {
				preg_match_all( '/⟦e:([0-9a-z_]{5})⟧/u', $n['messages'][0]['content'][0]['text'], $m );
				return ZDZ_LLM_Fake::answer( 'Call ⟦e:' . $m[1][0] . '⟧ back on ⟦e:' . $m[1][1] . '⟧ today.', array( 'in' => 900, 'out' => 40 ), 2 );
			},
		) );
		$res = $this->ask( 'write', self::q( 'Please call Riley Example at 555-0142 about the order.' ) );
		$this->assertIsArray( $res );

		$sent = $fake->requests[0];
		$text = $sent['messages'][0]['content'][0]['text'];
		$this->assertStringNotContainsString( 'Riley', $text );
		$this->assertStringNotContainsString( '555-0142', $text );
		preg_match_all( '/⟦e:([^⟧]*)⟧/u', $text, $codes );
		$this->assertCount( 2, $codes[1] );
		foreach ( $codes[1] as $h ) {
			$this->assertTrue( ZDZ_Core_LLM::handle_ok( $h ), 'a sealed code goes out as a short handle' );
		}
		$this->assertSame( self::PRIMARY, $sent['model'] );
		$this->assertSame( 'medium', $sent['effort'] );

		$this->assertStringNotContainsString( 'Riley', $res['text'], 'the answer comes back still coded' );
		$this->assertSame( 'Call Riley Example back on 555-0142 today.', $this->r->test_coder->decode( $res['text'], true ) );
		$this->assertSame( 'Call [private] back on [private] today.', $this->r->test_coder->decode( $res['text'], false ) );
		$this->assertSame( 2, $res['coded_count'] );
		$this->assertSame( 'fake', $res['provider'] );
		$this->assertSame( 1, $res['tries'] );
		$this->assertGreaterThan( 0.0, $res['usd'] );

		$rows = $this->ledger_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'ok', $rows[0]['outcome'] );
		$this->assertSame( 'receipts', $rows[0]['caller'] );
		$this->assertSame( 'write', $rows[0]['role'] );
		$this->assertSame( self::PRIMARY, $rows[0]['model'] );
		$this->assertSame( 2000, $rows[0]['ms'] );
		$this->assertSame( (int) ceil( ( 900 * 3 + 40 * 15 ) / 1e6 * 1000 ), $rows[0]['usd_milli'] );
		foreach ( $rows[0] as $k => $v ) {
			$this->assertStringNotContainsString( 'Riley', (string) $v, $k );
		}
		$this->assertSame( array(), $GLOBALS['wpdb']->rows( 'zdz_llm_holds' ), 'the hold is released once the cost is in the ledger' );
	}

	public function test_business_text_comes_back_as_written(): void {
		$schema = array( 'type' => 'object', 'properties' => array( 'catalog_id' => array( 'type' => 'string' ) ), 'required' => array( 'catalog_id' ), 'additionalProperties' => false );
		$req    = array(
			'system'      => "Pick the catalog entry.\n- ID: widget-std | Example Widget, standard\n- ID: none | no entry fits",
			'messages'    => array( array( 'role' => 'user', 'content' => 'Invoice line: Example widget, standard size' ) ),
			'json_schema' => $schema,
		);
		$this->script( array( array( 'text' => '{"catalog_id":"widget-std"}', 'json' => array( 'catalog_id' => 'widget-std' ) ) ) );
		$res = $this->ask( 'classify', $req + array( 'system_company' => true ), array( 'caller' => 'commission-calculator' ) );
		$this->assertSame( 'widget-std', $res['json']['catalog_id'], 'an id of the business text comes back as written' );
		$this->assertStringContainsString( 'widget-std', $this->r->fake->requests[0]['system'] );

		$this->script( array( array( 'text' => '{"catalog_id":"widget-std"}', 'json' => array( 'catalog_id' => 'widget-std' ) ) ) );
		$res = $this->ask( 'classify', $req, array( 'caller' => 'commission-calculator' ) );
		$this->assertStringNotContainsString( 'widget-std', $res['json']['catalog_id'], 'without the mark the same word is coded' );
	}

	public function test_codes_a_reader_made_up_reveal_nothing(): void {
		$schema = array( 'type' => 'object' );
		$this->script( array( array( 'text' => '⟦c:9999⟧ and ⟦e:k7x2q⟧ and ⟦e:' . str_repeat( 'a', 72 ) . '⟧', 'json' => array( '⟦c:9999⟧' => 3 ) ) ) );
		$res = $this->ask( 'extract', array( 'messages' => array( array( 'role' => 'user', 'content' => 'Count the visits.' ) ), 'json_schema' => $schema ) );
		$this->assertSame( 'a customer and [private] and [private]', $res['text'] );
		$this->assertSame( array( 'a customer' => 3 ), $res['json'], 'keys are checked too' );
	}

	/* ── short handles ──────────────────────────────────────────────────── */

	public function test_the_check_character_catches_every_slip_and_swap(): void {
		// Why it is exact: a slip at weight w (1..5) by d (1..36) moves the sum by w*d, a swap of neighbours by (a-b);
		// 37 is prime and none of these is a multiple of it.
		for ( $w = 1; $w <= 5; $w++ ) {
			for ( $d = 1; $d <= 36; $d++ ) {
				$this->assertNotSame( 0, ( $w * $d ) % 37 );
			}
		}
		$abc  = ZDZ_Core_LLM::HANDLE_ABC;
		$last = $abc . '_';
		mt_srand( 20261105 );
		$checked = 0;
		for ( $n = 0; $n < 1500; $n++ ) {
			$body = '';
			for ( $i = 0; $i < 4; $i++ ) {
				$body .= $abc[ mt_rand( 0, 35 ) ];
			}
			$h = $body . ZDZ_Core_LLM::handle_check( $body );
			$this->assertTrue( ZDZ_Core_LLM::handle_ok( $h ) );
			for ( $p = 0; $p < 5; $p++ ) {
				$set = 4 === $p ? $last : $abc;
				for ( $k = 0; $k < strlen( $set ); $k++ ) {
					if ( $set[ $k ] === $h[ $p ] ) {
						continue;
					}
					$slip      = $h;
					$slip[ $p ] = $set[ $k ];
					if ( ZDZ_Core_LLM::handle_ok( $slip ) ) {
						$this->fail( "$h -> $slip passed its check" );
					}
					$checked++;
				}
			}
			for ( $p = 0; $p < 4; $p++ ) {
				if ( $h[ $p ] === $h[ $p + 1 ] ) {
					continue;
				}
				$swap           = $h;
				$swap[ $p ]     = $h[ $p + 1 ];
				$swap[ $p + 1 ] = $h[ $p ];
				if ( ZDZ_Core_LLM::handle_ok( $swap ) ) {
					$this->fail( "$h -> $swap (swap) passed its check" );
				}
				$checked++;
			}
		}
		$this->assertGreaterThan( 250000, $checked );
		foreach ( array( '', 'abcd', 'abcdef', 'ABCDE', 'ab-de' ) as $bad ) {
			$this->assertFalse( ZDZ_Core_LLM::handle_ok( $bad ), $bad );
		}
	}

	public function test_handles_come_back_only_as_given(): void {
		$code = str_repeat( 'Q', 72 );
		$map  = ZDZ_Core_LLM::handles( '', 'Reach ⟦e:' . $code . '⟧ today.', array() );
		$this->assertCount( 1, $map );
		$h = $map[ $code ];
		$this->assertSame( 'Reach ⟦e:' . $h . '⟧ today.', ZDZ_Core_LLM::to_handles( 'Reach ⟦e:' . $code . '⟧ today.', $map ) );
		$this->assertSame( 'x ⟦e:' . $code . '⟧', ZDZ_Core_LLM::from_handles( 'x ⟦e:' . $h . '⟧', $map ) );
		$slip      = $h;
		$slip[0]   = '0' === $h[0] ? '1' : '0';
		$this->assertSame( 'x ⟦e:' . $slip . '⟧', ZDZ_Core_LLM::from_handles( 'x ⟦e:' . $slip . '⟧', $map ), 'a slip opens nothing' );
		$other = 'zzzz' . ZDZ_Core_LLM::handle_check( 'zzzz' );
		if ( $other !== $h ) {
			$this->assertSame( 'x ⟦e:' . $other . '⟧', ZDZ_Core_LLM::from_handles( 'x ⟦e:' . $other . '⟧', $map ), 'a handle never given out stays a handle' );
		}
	}

	public function test_the_cached_prefix_gets_the_same_handles_every_call(): void {
		$code   = str_repeat( 'P', 72 );
		$prefix = 'Shared facts: ⟦e:' . $code . '⟧';
		$a      = ZDZ_Core_LLM::handles( $prefix, '', array() );
		$b      = ZDZ_Core_LLM::handles( $prefix, '', array() );
		$this->assertSame( $a, $b );
		$seen = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$seen[ ZDZ_Core_LLM::handles( '', 'Per call: ⟦e:' . $code . '⟧', array() )[ $code ] ] = true;
		}
		$this->assertGreaterThan( 1, count( $seen ), 'outside the prefix a handle is drawn fresh each call' );
	}

	/* ── time, retries and the fallback ─────────────────────────────────── */

	public function test_one_retry_then_the_fallback_model(): void {
		$busy = ZDZ_LLM_Wire::error( 'overloaded', 529 );
		$fake = $this->script( array( $busy, $busy, ZDZ_LLM_Fake::answer( 'Done.' ) ) );
		$res  = $this->ask( 'chat', self::q( 'Hello' ) );
		$this->assertIsArray( $res );
		$this->assertSame( array( self::PRIMARY, self::PRIMARY, self::FALLBACK ), array_column( $fake->requests, 'model' ) );
		$this->assertTrue( $res['fallback'] );
		$this->assertSame( 1, (int) $this->ledger_rows()[0]['fallback'] );
		$this->assertSame( self::FALLBACK, $this->ledger_rows()[0]['model'] );
	}

	public function test_the_quick_model_when_time_is_short(): void {
		$fake = $this->script( array( ZDZ_LLM_Fake::answer( 'Done.' ) ) );
		$this->ask( 'chat', self::q( 'Hello' ), array( 'deadline' => $this->r->clock + 30 ) );
		$this->assertSame( self::QUICK, $fake->requests[0]['model'] );

		$fake = $this->script( array( ZDZ_LLM_Fake::answer( 'Done.' ) ) );
		$res  = $this->ask( 'chat', self::q( 'Hello' ), array( 'deadline' => $this->r->clock + 5 ) );
		$this->assertSame( 'zdz_llm_time', $res->get_error_code(), 'under 8 s nothing is sent' );
		$this->assertSame( array(), $fake->requests );
	}

	public function test_primary_only_narrows_and_never_falls_back(): void {
		$busy = ZDZ_LLM_Wire::error( 'overloaded', 529 );
		$GLOBALS['zdz_test_options']['zdz_llm_role_overrides'] = array( 'chat' => array( 'model' => self::QUICK ) );
		$fake = $this->script( array( $busy, $busy, ZDZ_LLM_Fake::answer( 'never' ) ) );
		$res  = $this->ask( 'chat', self::q( 'Hello' ), array( 'deadline' => $this->r->clock + 30, 'primary_only' => true ) );
		$this->assertSame( array( self::PRIMARY, self::PRIMARY ), array_column( $fake->requests, 'model' ), 'the override is set aside; no quick model, no fallback' );
		$this->assertSame( 'zdz_llm_unreachable', $res->get_error_code() );

		$GLOBALS['zdz_test_options']['zdz_llm_role_overrides'] = array( 'chat' => array( 'provider' => 'poe' ) );
		$fake = $this->script( array( ZDZ_LLM_Fake::answer( 'never' ) ) );
		$this->assertRefusedBeforeSending( $this->ask( 'chat', self::q( 'Hello' ), array( 'primary_only' => true ) ), 'primary_only', $fake );
	}

	public function test_a_failure_that_another_model_would_share_is_not_retried(): void {
		$fake = $this->script( array( ZDZ_LLM_Wire::error( 'auth', 401 ), ZDZ_LLM_Fake::answer( 'never' ) ) );
		$res  = $this->ask( 'chat', self::q( 'Hello' ) );
		$this->assertCount( 1, $fake->requests );
		$this->assertSame( 'zdz_llm_auth', $res->get_error_code() );
		$this->assertSame( 'error_auth', $this->last_outcome() );
		$this->assertArrayHasKey( 'usd_milli', $res->get_error_data() );
	}

	/* ── refusals ───────────────────────────────────────────────────────── */

	public function test_every_refusal_happens_before_sending(): void {
		$long  = str_repeat( 'word ', 900 );
		$img   = array( 'type' => 'image', 'media_type' => 'image/png', 'data' => base64_encode( 'png' ) );
		$cases = array(
			'role'    => array( 'translate', self::q( 'Hello' ), array() ),
			'caller'  => array( 'chat', self::q( 'Hello' ), array( 'caller' => 'not-an-app' ) ),
			'picture' => array( 'classify', array( 'messages' => array( array( 'role' => 'user', 'content' => array( $img, array( 'type' => 'text', 'text' => 'What is this?' ) ) ) ) ), array() ),
			'web'     => array( 'web', array( 'messages' => array( array( 'role' => 'user', 'content' => $long ) ) ), array() ),
			'request' => array( 'chat', array( 'messages' => array( array( 'role' => 'assistant', 'content' => 'Hi' ) ) ), array() ),
			'schema'  => array( 'classify', self::q( 'Pick one' ) + array( 'json_schema' => array( 'type' => 'string', 'enum' => array( 'Riley Example', 'other' ) ) ), array() ),
		);
		foreach ( $cases as $code => $c ) {
			$fake = $this->script( array( ZDZ_LLM_Fake::answer( 'never' ) ) );
			$this->assertRefusedBeforeSending( $this->ask( $c[0], $c[1], $c[2] ), $code, $fake );
		}
		// pictures: only a document reader, only on read_document, only the four picture types
		$fake = $this->script( array( ZDZ_LLM_Fake::answer( 'never' ) ) );
		$this->assertRefusedBeforeSending( $this->ask( 'read_document', array( 'messages' => array( array( 'role' => 'user', 'content' => array( $img ) ) ) ), array( 'caller' => 'leads' ) ), 'picture', $fake );
		$fake = $this->script( array( ZDZ_LLM_Fake::answer( 'never' ) ) );
		$heic = array( 'type' => 'image', 'media_type' => 'image/heic', 'data' => 'AAAA' );
		$this->assertRefusedBeforeSending( $this->ask( 'read_document', array( 'messages' => array( array( 'role' => 'user', 'content' => array( $heic ) ) ) ) ), 'request', $fake );
		$fake = $this->script( array( ZDZ_LLM_Fake::answer( 'A drawn invoice.' ) ) );
		$this->assertIsArray( $this->ask( 'read_document', array( 'messages' => array( array( 'role' => 'user', 'content' => array( $img, array( 'type' => 'text', 'text' => 'What is this?' ) ) ) ) ) ) );
		// web: a question on its own, never with the cached business blocks, never a schema, never from another role
		foreach ( array(
			array( 'web', array( 'system_prefix' => 'Business facts.', 'messages' => array( array( 'role' => 'user', 'content' => 'Q?' ) ) ) ),
			array( 'web', array( 'messages' => array( array( 'role' => 'user', 'content' => 'Q?' ) ), 'json_schema' => array( 'type' => 'object' ) ) ),
			array( 'web', array( 'messages' => array( array( 'role' => 'user', 'content' => 'ZDZ-DATA-START jobs' ) ) ) ),
			array( 'chat', array( 'messages' => array( array( 'role' => 'user', 'content' => 'Q?' ) ), 'web' => true ) ),
		) as $w ) {
			$fake = $this->script( array( ZDZ_LLM_Fake::answer( 'never' ) ) );
			$this->assertRefusedBeforeSending( $this->ask( $w[0], $w[1] ), 'web', $fake );
		}
		$fake = $this->script( array( ZDZ_LLM_Fake::answer( 'In 1937.' ) ) );
		$this->assertIsArray( $this->ask( 'web', array( 'messages' => array( array( 'role' => 'user', 'content' => 'In what year did the Golden Gate Bridge open?' ) ) ) ) );
		$this->assertTrue( $fake->requests[0]['web'] );
	}

	public function test_the_shared_device_is_refused_whatever_user_id_is_passed(): void {
		$GLOBALS['zdz_test_user_roles'] = array( 9 => array( 'zdz_general' ), 4 => array( 'zdz_sales' ) );
		$this->assertTrue( ZDZ_Core_LLM::is_kiosk( 9 ) );
		$this->assertFalse( ZDZ_Core_LLM::is_kiosk( 4 ) );
		$fake = $this->script( array( ZDZ_LLM_Fake::answer( 'never' ) ) );
		$this->assertRefusedBeforeSending( $this->ask( 'chat', self::q( 'Hello' ), array( 'user_id' => 9 ) ), 'kiosk', $fake );
		$GLOBALS['zdz_test_current_user'] = 9;
		$fake = $this->script( array( ZDZ_LLM_Fake::answer( 'never' ) ) );
		$this->assertRefusedBeforeSending( $this->ask( 'chat', self::q( 'Hello' ), array( 'user_id' => 0 ) ), 'kiosk', $fake );
	}

	public function test_money_is_capped_before_a_call_is_sent(): void {
		$fake = $this->script( array( ZDZ_LLM_Fake::answer( 'never' ) ) );
		$this->assertRefusedBeforeSending( $this->ask( 'chat', self::q( 'Hello' ), array( 'cap_usd' => 0.0001 ) ), 'money', $fake );
		ZDZ_LLM_Ledger::write( array( 'caller' => 'receipts', 'usd_milli' => 10000, 'ts' => (int) $this->r->clock ) );
		ZDZ_LLM_Ledger::forget();
		$fake = $this->script( array( ZDZ_LLM_Fake::answer( 'never' ) ) );
		$this->assertRefusedBeforeSending( $this->ask( 'chat', self::q( 'Hello' ) ), 'month', $fake );
		$this->assertSame( 50, ZDZ_Core_LLM::alert_mark( 5.0, 10.0, array( 'at' => array() ) ) );
		$this->assertSame( 80, ZDZ_Core_LLM::alert_mark( 8.5, 10.0, array( 'at' => array() ) ), 'one notice for the highest mark crossed' );
		$this->assertNull( ZDZ_Core_LLM::alert_mark( 8.5, 10.0, array( 'at' => array( 50, 80 ) ) ) );
		$this->assertNull( ZDZ_Core_LLM::alert_mark( 4.9, 10.0, array( 'at' => array() ) ) );
	}

	public function test_a_failed_answer_is_said_as_one(): void {
		$this->script( array( ZDZ_LLM_Fake::answer( '   ' ) ) );
		$res = $this->ask( 'chat', self::q( 'Hello' ) );
		$this->assertSame( 'zdz_llm_empty', $res->get_error_code() );
		$this->assertArrayHasKey( 'usd_milli', $res->get_error_data() );
		$schema = array( 'type' => 'object', 'properties' => array( 'n' => array( 'type' => 'integer' ) ), 'required' => array( 'n' ) );
		$this->script( array( ZDZ_LLM_Fake::answer( '{"n":"three"}' ) ) );
		$res = $this->ask( 'extract', self::q( 'How many?' ) + array( 'json_schema' => $schema ) );
		$this->assertSame( 'zdz_llm_bad_json', $res->get_error_code() );
		$this->script( array( ZDZ_LLM_Fake::answer( "Here:\n```json\n{\"n\": 3}\n```" ) ) );
		$res = $this->ask( 'extract', self::q( 'How many?' ) + array( 'json_schema' => $schema ) );
		$this->assertSame( array( 'n' => 3 ), $res['json'] );
	}

	/* ── providers ──────────────────────────────────────────────────────── */

	public function test_the_native_adapter_sends_nothing_without_a_key_and_a_workspace(): void {
		$a = new ZDZ_LLM_Anthropic( function () {
			return 'test-key';
		}, null, function () {
			return '';
		} );
		$e = $a->send( array( 'model' => self::PRIMARY, 'messages' => array() ), time() + 60 );   // wp_remote_post is not defined here: reaching it would fail the run
		$this->assertSame( 'zdz_llm_no_workspace', $e->get_error_code() );
		$this->assertFalse( $e->get_error_data()['billed'] );
		$b = new ZDZ_LLM_Anthropic( function () {
			return '';
		} );
		$this->assertSame( 'zdz_llm_no_key', $b->send( array(), time() + 60 )->get_error_code() );
		$this->assertSame( ZDZ_Core_LLM::prices_for( self::PRIMARY ), $a->prices( self::PRIMARY ) );
	}

	public function test_an_openai_compatible_entry_needs_checked_terms_and_prices(): void {
		$entry = array( 'base' => 'https://llm.example.com', 'model' => 'example-local', 'key_name' => 'example_key' );
		$GLOBALS['zdz_test_options']['zdz_llm_openai_entries'] = array( 'local' => $entry );
		$GLOBALS['zdz_test_options']['zdz_llm_role_overrides'] = array( 'chat' => array( 'provider' => 'openai:local' ) );
		$this->assertSame( 'openai', ZDZ_Core_LLM::role( 'chat' )['provider'] );
		$this->assertSame( 'example-local', ZDZ_Core_LLM::role( 'chat' )['model'] );

		$this->r->fake = new ZDZ_LLM_OpenAI_Compatible( 'local', $entry );
		$this->assertSame( 'zdz_llm_terms', $this->ask( 'chat', self::q( 'Hello' ) )->get_error_code() );
		$checked = $entry + array( 'terms' => 'No retention.', 'terms_checked_by' => 1, 'terms_checked_at' => 1793000000 );
		$this->r->fake = new ZDZ_LLM_OpenAI_Compatible( 'local', $checked );
		$this->assertSame( 'zdz_llm_prices', $this->ask( 'chat', self::q( 'Hello' ) )->get_error_code() );
		$this->assertSame( array( 2.0, 2.0, 2.0, 2.0, 6.0 ), ( new ZDZ_LLM_OpenAI_Compatible( 'local', $checked + array( 'prices' => array( 2, 6 ) ) ) )->prices( 'x' ) );
	}

	public function test_a_poe_role_uses_the_registry_roster(): void {
		$GLOBALS['zdz_test_filters']['zdz_model_choices'] = function () {
			return array( 'bot-a' => 'Bot A', 'bot-b' => 'Bot B' );
		};
		$GLOBALS['zdz_test_filters']['zdz_model_base_default'] = function () {
			return 'bot-default';
		};
		$this->assertSame( 'bot-b', ZDZ_Core_LLM::poe_model( 'bot-b' ) );
		$this->assertSame( 'bot-default', ZDZ_Core_LLM::poe_model( 'bot-not-on-the-roster' ) );
		$GLOBALS['zdz_test_options']['zdz_llm_role_overrides'] = array( 'write' => array( 'provider' => 'poe', 'poe_model' => 'bot-a' ) );
		$w = ZDZ_Core_LLM::role( 'write' );
		$this->assertSame( array( 'poe', 'bot-a' ), array( $w['provider'], $w['model'] ) );
		$this->assertSame( 'bot-a', ZDZ_Core_LLM::model_code( 'Bot-A' ) );
		$this->assertSame( 'a-b', ZDZ_Core_LLM::model_code( 'A b' ) );
	}
}
