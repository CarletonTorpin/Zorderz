<?php
/**
 * The customer-detail coder (ZDZ_Private) and "Ask chat about this" (ZDZ_Ask_Context):
 *
 *   - coded words carry no original name, phone, e-mail, street or access code, and decode back exactly for the
 *     writer; for anyone else they read "a customer" / "[private]";
 *   - the business's own names and the team are never coded; company text keeps the one-word rules off;
 *   - Core ships no places and no product words: a business's region and catalog add them by option or filter;
 *   - one detail gets one code within a request, another in the next; a fixed salt keeps a prompt prefix's bytes;
 *   - the Hours purpose keeps its own key and seals codes as words; every other purpose cuts them;
 *   - a model can only hand back the codes it was given;
 *   - ask-context refuses who it must, hides what it must before anything leaves, and keeps its fence.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/privacy-stubs.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-sealed.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-ask-context.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-private.php';

final class PrivacyCoderTest extends TestCase {

	/** A coder whose ledger table exists in the in-memory database, with one known client. */
	private function coder( $purpose = 'unit-coder' ) {
		$c = ZDZ_Private::for( $purpose, array(
			'table_exists' => function ( $t ) { return 'wp_zana_ledger_invoices' === $t; },
			'team'         => function () { return array( 'Robin Casey' ); },
		) );
		$c->forget();
		$c->new_request();
		return $c;
	}

	private function idx() {
		return array(
			'ledger'  => array( 'alex example' => 42 ),
			'other'   => array( 'jordan sample' => true, 'quinn widget' => true ),
			'surname' => array( 'example' => true, 'sample' => true, 'widget' => true ),
			'team'    => array( 'robin casey' => true, 'robin' => true, 'casey' => true ),
			'first'   => array( 'alex' => true, 'jordan' => true, 'quinn' => true ),
			'sig'     => '',
		);
	}

	protected function setUp(): void {
		$GLOBALS['wpdb'] = new ZDZ_Test_Vault_DB();
		$GLOBALS['wpdb']->tables['wp_zana_ledger_invoices'] = array( 1 => array( 'id' => 1, 'customer_id' => 42, 'customer_name' => 'Alex Example', 'vis_state' => 0 ) );
		ZDZ_Private::flush_lists();
		ZDZ_Sealed::reset_state();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_transients'],
			$GLOBALS['zdz_test_user_roles'], $GLOBALS['zdz_test_user_meta'], $GLOBALS['zdz_test_current_user'], $GLOBALS['wpdb'] );
		ZDZ_Private::flush_lists();
		ZDZ_Sealed::reset_state();
	}

	/* ── the coder ────────────────────────────────────────────────────────── */

	public function test_coded_text_carries_no_detail_and_decodes_back_for_the_writer(): void {
		$c    = $this->coder();
		$text = 'Called Jordan Sample at 555-555-0142, wrote to jordan@example.com, met at 1450 Oak St. Alex Example paid. Robin Casey took it.';
		$out  = $c->encode( $text, $this->idx() );
		foreach ( array( 'Jordan', 'Sample', '555-0142', 'example.com', '1450 Oak St', 'Alex Example' ) as $gone ) {
			$this->assertStringNotContainsString( $gone, $out, "coded text still holds '$gone'" );
		}
		$this->assertStringContainsString( '⟦c:42⟧', $out, 'a ledger customer becomes its client code' );
		$this->assertStringContainsString( 'Robin Casey', $out, 'a team member is never coded' );
		$this->assertSame( $text, $c->decode( $out, true ), 'the writer reads back exactly what was written' );
		$hidden = $c->decode( $out, false );
		$this->assertStringContainsString( 'a customer', $hidden );
		$this->assertStringContainsString( '[private]', $hidden );
		foreach ( array( 'Jordan', '555-0142', 'Alex' ) as $gone ) {
			$this->assertStringNotContainsString( $gone, $hidden );
		}
	}

	public function test_access_codes_are_cut_never_sealed_for_a_model(): void {
		$out = $this->coder()->encode( 'Gate code is 4821, lockbox 5521.', $this->idx() );
		$this->assertStringNotContainsString( '4821', $out );
		$this->assertStringNotContainsString( '5521', $out );
		$this->assertStringContainsString( '[gate code sealed]', $out );
		$this->assertStringNotContainsString( '⟦e:', $out, 'a code never reaches a model in any form' );
	}

	public function test_hours_purpose_keeps_its_own_key_and_seals_codes_as_words(): void {
		$hours = ZDZ_Private::for( 'hours', array( 'table_exists' => function () { return false; }, 'team' => function () { return array(); } ) );
		$this->assertFalse( $hours->cuts_codes() );
		$this->assertTrue( $this->coder()->cuts_codes() );
		$out = $hours->encode( 'gate code 4821 at the side', $this->idx() );
		$this->assertStringNotContainsString( '4821', $out );
		$this->assertStringContainsString( '⟦e:', $out );
		$code = ZDZ_Private::sealed_codes( $out )[0];
		$this->assertSame( 'gate code 4821', $hours->open( $code ) );
		$this->assertNull( $this->coder( 'assistant-unit' )->open( $code ), "another purpose's key opens nothing" );
	}

	public function test_one_code_per_detail_within_a_request_and_a_fixed_salt_for_a_prefix(): void {
		$c = $this->coder();
		$a = $c->seal( 'Jordan Sample' );
		$this->assertSame( $a, $c->seal( 'Jordan Sample' ), 'the same detail, the same code, within one request' );
		$c->new_request();
		$b = $c->seal( 'Jordan Sample' );
		$this->assertNotSame( $a, $b, 'the next request gets another code' );
		$prev = $c->fixed_salt( 'prefix text' );
		$f1   = $c->seal( 'Jordan Sample' );
		$c->restore_salt( $prev );
		$c->new_request();
		$c->fixed_salt( 'prefix text' );
		$this->assertSame( $f1, $c->seal( 'Jordan Sample' ), 'a fixed salt keeps the prefix the same bytes on every call' );
		foreach ( array( $a, $b, $f1 ) as $code ) {
			$this->assertSame( 'Jordan Sample', $c->open( ZDZ_Private::sealed_codes( $code )[0] ) );
		}
	}

	public function test_team_and_the_business_own_names_are_never_coded(): void {
		$GLOBALS['zdz_test_filters']['zdz_private_own_names'] = function () { return array( 'Example Screen Co' ); };
		$GLOBALS['zdz_test_transients']['zdz_ask_customer_names'] = array( 'sam screen', 'jordan sample' );
		$c   = $this->coder( 'unit-own' );
		$idx = $c->index();
		$this->assertArrayHasKey( 'screen', $idx['team'], 'a word of the business name is set aside' );
		$out = $c->encode( 'Example Screen Co finished the Screen job. Sam Screen called. Spoke to Robin. The Sample job is next.' );
		$this->assertStringContainsString( 'Example Screen Co finished the Screen job', $out );
		$this->assertStringNotContainsString( 'Sam Screen', $out, 'a whole customer name is still coded' );
		$this->assertStringContainsString( 'Spoke to Robin', $out, "a team member's first name is never coded" );
		$this->assertStringNotContainsString( 'Sample job', $out, 'a customer surname in a work phrase is coded' );
	}

	public function test_company_text_keeps_one_word_rules_off_but_codes_whole_names(): void {
		$c   = $this->coder();
		$out = $c->encode( 'Widget kit, Sample size. Call Jordan Sample at 555-555-0142.', $this->idx(), false, null, array( 'company' => true ) );
		$this->assertStringContainsString( 'Widget kit, Sample size.', $out, 'a single catalog word stays as written' );
		$this->assertStringNotContainsString( 'Jordan Sample', $out );
		$this->assertStringNotContainsString( '555-0142', $out );
	}

	public function test_first_name_coded_only_in_a_contact_phrase(): void {
		$c = $this->coder();
		$this->assertStringNotContainsString( 'Jordan', $c->encode( 'spoke to Jordan about the gate', $this->idx() ) );
		$this->assertStringNotContainsString( 'Jordan', $c->encode( 'Jordan called back at noon', $this->idx() ) );
		$this->assertStringContainsString( 'Jordan', $c->encode( 'Drive to the Jordan valley overlook', $this->idx() ), 'outside a contact phrase a first name is a word' );
	}

	public function test_places_ship_empty_and_a_business_fills_them(): void {
		$this->assertSame( array(), ZDZ_Private::places(), 'Core ships no places' );
		$this->assertSame( array(), ZDZ_Private::PLACES );
		$c = $this->coder();
		$this->assertStringNotContainsString( 'Lake Example', $c->encode( 'Look up Lake Example Pools', $this->idx() ), 'without places "Example" is a surname' );

		$GLOBALS['zdz_test_filters']['zdz_private_places'] = function ( $p ) { $p[] = 'Lake Example'; $p[] = 'Springfield'; return $p; };
		ZDZ_Private::flush_lists();
		$this->assertSame( array( 'lake example' ), ZDZ_Private::places(), 'only places of two or more words are kept' );
		$this->assertStringContainsString( 'Lake Example Pools', $c->encode( 'Look up Lake Example Pools', $this->idx() ) );
		$this->assertStringNotContainsString( 'Example', $c->encode( 'the Example job', $this->idx() ), 'a word alone keeps its rules' );

		unset( $GLOBALS['zdz_test_filters']['zdz_private_places'] );
		$GLOBALS['zdz_test_options'][ ZDZ_Private::PLACES_OPT ] = array( 'North Example' );
		ZDZ_Private::flush_lists();
		$this->assertSame( array( 'north example' ), ZDZ_Private::places(), 'the option the Identity Pack writes fills it too' );
	}

	public function test_plain_words_join_common_by_option(): void {
		$c = $this->coder();
		$this->assertStringNotContainsString( 'Widget', $c->encode( 'Widget kits shipped today', $this->idx() ), 'a known surname with a capital is coded' );
		$this->assertArrayNotHasKey( 'widget', ZDZ_Private::common_words() );
		$GLOBALS['zdz_test_options'][ ZDZ_Private::PLAIN_WORDS_OPT ] = array( 'widget', 'two words' );
		ZDZ_Private::flush_lists();
		$this->assertArrayHasKey( 'widget', ZDZ_Private::common_words() );
		$this->assertArrayNotHasKey( 'two words', ZDZ_Private::common_words() );
		$this->assertStringContainsString( 'Widget kits shipped', $c->encode( 'Widget kits shipped today', $this->idx() ) );
		$this->assertStringNotContainsString( 'Widget job', $c->encode( 'the Widget job', $this->idx() ), 'still coded in a plain customer phrase' );
	}

	public function test_a_reader_keeps_only_the_codes_it_was_given(): void {
		$c     = $this->coder();
		$given = $c->encode( 'Jordan Sample and Alex Example', $this->idx() );
		$e     = ZDZ_Private::sealed_codes( $given );
		$back  = $c->from_reader( $given . ' and ⟦c:99⟧ and ⟦e:forged⟧', array( 42 ), $this->idx(), $e );
		$this->assertStringContainsString( '⟦c:42⟧', $back );
		$this->assertStringNotContainsString( '⟦c:99⟧', $back );
		$this->assertStringNotContainsString( 'forged', $back );
		$this->assertSame( $e, array_values( array_intersect( ZDZ_Private::sealed_codes( $back ), $e ) ) );
	}

	public function test_codes_from_outside_are_defused(): void {
		$out = $this->coder()->encode( 'note ⟦c:7⟧ from a form', $this->idx() );
		$this->assertStringContainsString( '[c:7]', $out );
		$this->assertSame( array(), ZDZ_Private::codes( $out ) );
	}

	/* ── ask context ──────────────────────────────────────────────────────── */

	public function test_ask_context_refuses_the_signed_out_and_the_shared_device(): void {
		$GLOBALS['zdz_test_current_user'] = 0;
		$this->assertFalse( ZDZ_Ask_Context::allowed() );
		$GLOBALS['zdz_test_current_user'] = 9;
		$GLOBALS['zdz_test_user_roles']   = array( 9 => array( 'zdz_general' ) );
		$this->assertFalse( ZDZ_Ask_Context::allowed(), 'never the shared device' );
		$GLOBALS['zdz_test_user_roles'] = array( 9 => array( 'zdz_sales' ) );
		// a person who may open the chat app (when the app registry is loaded it checks that too)
		$GLOBALS['zdz_test_user_meta'][9]['zdz_allowed_apps'] = ZDZ_Ask_Context::chat_apps();
		$this->assertTrue( ZDZ_Ask_Context::allowed() );
		$this->assertFalse( ZDZ_Ask_Context::viewer_sees_all( 0 ), 'no user: the strict path' );
		$this->assertSame( array( 'sales-analytics' ), ZDZ_Ask_Context::chat_apps() );
	}

	public function test_ask_context_hides_details_before_anything_leaves(): void {
		$text = "Customer: Sam Example\nPhone 555-555-0183\nGate code 4821\nJob at 1450 Oak St\nsam@example.com\nhttps://example.com/x";
		$out  = ZDZ_Ask_Context::prepare( 'jobs', 'Jobs', '', 'screen', $text, array( 'Pressed: Send' ), array(), array(), true );
		foreach ( array( 'Sam Example', '555-0183', '4821', '1450 Oak St', 'sam@example.com', 'example.com/x' ) as $gone ) {
			$this->assertStringNotContainsString( $gone, $out['text'], "'$gone' was shared" );
		}
		$this->assertSame( 0, strpos( $out['text'], ZDZ_Ask_Context::FENCE_HEAD ) );
		$this->assertStringEndsWithFence( $out['text'] );
		$this->assertArrayHasKey( 'phone numbers', $out['hidden'] );
		$this->assertArrayHasKey( 'access codes', $out['hidden'] );
		$this->assertStringContainsString( 'Name 1', $out['text'], 'the fail-closed path labels names, not customers' );
	}

	public function test_ask_context_known_names_become_customers_and_team_stays(): void {
		$out = ZDZ_Ask_Context::prepare( 'jobs', 'Jobs', '', 'screen', 'Robin Casey met sam example, then Example signed.', array(), array( 'sam example' ), array( 'robin casey' ), false );
		$this->assertStringContainsString( 'Robin Casey', $out['text'] );
		$this->assertStringNotContainsString( 'sam example', $out['text'] );
		$this->assertStringContainsString( 'Customer 1', $out['text'] );
	}

	public function test_ask_context_places_ship_empty_and_a_business_fills_them(): void {
		$this->assertSame( array(), ZDZ_Ask_Context::place_names() );
		$t = 'Jobs in Lake Example this week';
		$this->assertStringNotContainsString( 'Lake Example', ZDZ_Ask_Context::prepare( 'a', 'A', '', 'screen', $t, array(), array(), array(), true )['text'] );
		$GLOBALS['zdz_test_options'][ ZDZ_Ask_Context::PLACES_OPT ] = array( 'Lake Example' );
		$this->assertSame( array( 'lake example' ), ZDZ_Ask_Context::place_names() );
		$this->assertStringContainsString( 'Lake Example', ZDZ_Ask_Context::prepare( 'a', 'A', '', 'screen', $t, array(), array(), array(), true )['text'] );
	}

	public function test_ask_context_replaces_a_provider_fence_and_rescrub_keeps_its_own(): void {
		$in  = ZDZ_Ask_Context::FENCE_START . " from a chart\nTotal 12\n" . ZDZ_Ask_Context::FENCE_END . "\n";
		$this->assertSame( "Total 12\n", ZDZ_Ask_Context::strip_fence( $in ) );
		$block = ZDZ_Ask_Context::prepare( 'charts', 'Charts', '', 'provider', 'Total 12', array(), array(), array(), false )['text'];
		$again = ZDZ_Ask_Context::rescrub( $block . "\nCall Sam Example at 555-555-0199", array(), array(), true );
		$this->assertSame( 0, strpos( $again['text'], ZDZ_Ask_Context::FENCE_HEAD ) );
		$this->assertStringNotContainsString( '555-0199', $again['text'] );
		$this->assertStringNotContainsString( 'Sam Example', $again['text'] );
	}

	private function assertStringEndsWithFence( string $t ): void {
		$this->assertSame( ZDZ_Ask_Context::FENCE_END, substr( $t, -strlen( ZDZ_Ask_Context::FENCE_END ) ) );
	}
}
