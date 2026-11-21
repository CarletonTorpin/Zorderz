<?php
/**
 * Estimates: the administrator's check "does the billing system give an estimate a customer link?".
 *
 *   - one estimate, by its number, and only a test customer's (by the mark the app already uses): any other customer
 *     is refused BEFORE the share-link request, which may mark the estimate sent;
 *   - three tries, in order; a value is a customer link only in the exact shape the billing system forms one, and only
 *     such a link is ever passed on, as text;
 *   - nothing is stored; the answer is fixed words.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/estimate-followup-stubs.php';

final class EstimateLinkTestTest extends TestCase {

	const LINK = 'https://my.freshbooks.com/#/link/abcDEF123._~-';

	/** @var ZDZ_Test_EstFu_Billing */
	private $billing;

	protected function setUp(): void {
		$GLOBALS['zdz_test_options'] = array();
		$GLOBALS['zdz_test_filters'] = array();
		zdz_test_estfu_flush();
		$this->billing               = new ZDZ_Test_EstFu_Billing();
		ZEST_Billing_Doc::$transport = $this->billing;
		ZEST_Billing_Doc::$account   = 'acct1';
	}

	protected function tearDown(): void {
		zdz_test_estfu_teardown();
	}

	private function test_doc( array $over = array() ): void {
		$this->billing->docs['501'] = ZDZ_Test_EstFu_Billing::doc( $over + array( 'fname' => 'Test Customer', 'lname' => 'One', 'v3_status' => 'draft' ) );
	}

	private function share_asked(): bool {
		return (bool) preg_grep( '#/share_link#', $this->billing->calls );
	}

	public function test_a_number_that_is_not_one_is_refused_before_anything_is_asked(): void {
		foreach ( array( '', '0', 'abc', '12a', '1234567890', '-5', '1 OR 1' ) as $n ) {
			$r = ZEST_Billing_Doc::probe_customer_link( $n );
			$this->assertSame( array( false, 'bad_number' ), array( $r['ok'], $r['why'] ), var_export( $n, true ) );
		}
		$this->assertSame( array(), $this->billing->calls );
	}

	public function test_any_other_customers_estimate_is_refused_before_the_request_that_may_mark_it_sent(): void {
		$this->billing->docs['501'] = ZDZ_Test_EstFu_Billing::doc( array( 'share_url' => self::LINK ) );   // Morgan Example: not a test customer
		$r = ZEST_Billing_Doc::probe_customer_link( '1001' );
		$this->assertSame( array( false, 'not_test', array() ), array( $r['ok'], $r['why'], $r['probes'] ) );
		$this->assertFalse( $this->share_asked() );
		$this->assertStringNotContainsString( 'link', json_encode( $r['probes'] ), 'not even a link the estimate already carried is passed on' );

		$this->assertSame( 'not_found', ZEST_Billing_Doc::probe_customer_link( '2002' )['why'] );
		$this->billing->down = true;
		$this->assertSame( 'unread', ZEST_Billing_Doc::probe_customer_link( '1001' )['why'] );
	}

	public function test_the_test_customer_is_the_one_the_app_already_marks(): void {
		$this->test_doc();
		$this->assertTrue( ZEST_Billing_Doc::probe_customer_link( '1001' )['ok'] );
		$GLOBALS['zdz_test_options']['zest_test_fixture'] = 'Sample';
		$this->assertSame( 'not_test', ZEST_Billing_Doc::probe_customer_link( '1001' )['why'], 'an administrator changed the mark: this one is a customer now' );
		$this->test_doc( array( 'fname' => '', 'lname' => '', 'organization' => 'Sample Works' ) );
		$this->assertTrue( ZEST_Billing_Doc::probe_customer_link( '0001001' )['ok'], 'by the organization too, and leading zeros aside' );
	}

	public function test_three_tries_in_order_and_only_a_customer_link_is_passed_on(): void {
		$this->test_doc( array( 'links' => array( 'client_view' => 'https://my.freshbooks.com/#/estimate/501' ), 'share_url' => 'abc123' ) );
		$this->billing->share['501'] = self::LINK;
		$r = ZEST_Billing_Doc::probe_customer_link( '1001' );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( '1001', $r['number'] );
		$this->assertSame( 'draft', $r['status'] );
		$this->assertSame( array( 'links.client_view', 'share_url', 'share_link' ), array_column( $r['probes'], 'label' ) );
		$this->assertSame( array( 'billing_address', 'bare', 'customer_link' ), array_column( $r['probes'], 'form' ) );
		$this->assertSame( array( '', '', self::LINK ), array_column( $r['probes'], 'link' ) );
		$this->assertTrue( $this->share_asked() );
		$this->assertSame( array(), preg_grep( '/^(PUT|POST|DELETE)/', $this->billing->calls ), 'it only reads' );

		unset( $this->billing->share['501'] );
		$this->test_doc();
		$r = ZEST_Billing_Doc::probe_customer_link( '1001' );
		$this->assertSame( array( 'none', 'none', 'none' ), array_column( $r['probes'], 'form' ) );
		$this->assertSame( 'share_link (no answer)', $r['probes'][2]['label'] );
	}

	public function test_only_the_exact_shape_is_a_customer_link(): void {
		foreach ( array(
			self::LINK                                              => 'customer_link',
			'https://freshbooks.com/#/link/abc'                     => 'customer_link',
			'https://my.freshbooks.com/#/link/abc/extra'            => 'billing_address',
			'https://my.freshbooks.com/#/link/abc?next=x'           => 'billing_address',
			"  https://my.freshbooks.com/#/link/abc\n"              => 'customer_link',   // spaces and a line end around it are not part of it
			"https://my.freshbooks.com/#/link/abc\nhttps://example.com/" => 'billing_address',
			'http://my.freshbooks.com/#/link/abc'                   => 'bare',
			'https://freshbooks.com.example.com/#/link/abc'         => 'bare',
			'https://example.com/?u=https://my.freshbooks.com/#/link/abc' => 'bare',
			'javascript:alert(1)'                                   => 'bare',
		) as $value => $form ) {
			$this->test_doc( array( 'share_url' => $value ) );
			$r = ZEST_Billing_Doc::probe_customer_link( '1001' );
			$this->assertSame( $form, $r['probes'][1]['form'], var_export( $value, true ) );
			$this->assertSame( 'customer_link' === $form ? trim( $value ) : '', $r['probes'][1]['link'], 'only a customer link is passed on' );
		}
	}

	public function test_the_answer_is_fixed_words(): void {
		foreach ( array( 'bad_number', 'unread', 'not_found', 'not_test', 'something else' ) as $why ) {
			$r = ZEST_Settings::link_test_reply( array( 'ok' => false, 'why' => $why ) );
			$this->assertFalse( $r['ok'] );
			$this->assertGreaterThan( 10, strlen( $r['message'] ) );
			$this->assertStringNotContainsString( 'freshbooks', strtolower( $r['message'] ), 'the screen says "the billing system"' );
		}
		$this->assertStringContainsString( 'test customer', ZEST_Settings::link_test_reply( array( 'ok' => false, 'why' => 'not_test' ) )['message'] );

		$r = ZEST_Settings::link_test_reply( array( 'ok' => true, 'number' => '1001', 'status' => 'draft', 'probes' => array(
			array( 'label' => 'links.client_view', 'form' => 'billing_address', 'customer_link' => false, 'link' => 'https://my.freshbooks.com/#/estimate/501' ),
			array( 'label' => 'share_url<script>', 'form' => 'customer_link', 'customer_link' => false, 'link' => self::LINK ),
			array( 'label' => 'share_link', 'form' => 'customer_link', 'customer_link' => true, 'link' => self::LINK ),
			array( 'label' => 'x', 'form' => 'made up', 'customer_link' => true, 'link' => 'javascript:alert(1)' ),
			'not a try',
		) ) );
		$this->assertTrue( $r['found'] );
		$this->assertSame( array( '', '', self::LINK, '' ), array_column( $r['tries'], 'link' ), 'only a try the adapter called a customer link carries one' );
		$this->assertSame( 'share_urlscript', $r['tries'][1]['label'] );
		$this->assertSame( $r['tries'][3]['gave'], ZEST_Settings::link_test_reply( array( 'ok' => true, 'probes' => array( array( 'form' => 'bare' ) ) ) )['tries'][0]['gave'], 'a form the screen does not know reads as "not an address"' );
		$this->assertStringContainsString( 'gave this estimate a customer link', $r['message'] );
		$this->assertStringContainsString( 'no customer link', ZEST_Settings::link_test_reply( array( 'ok' => true, 'probes' => array() ) )['message'] );
	}

	public function test_only_an_administrator_runs_it_and_the_button_is_hooked(): void {
		$s = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/estimate/includes/class-zest-settings.php' );
		$this->assertStringContainsString( "add_action( 'wp_ajax_zest_test_estimate_link', array( __CLASS__, 'ajax_test_estimate_link' ) );", $s );
		$this->assertMatchesRegularExpression( '/function ajax_test_estimate_link\(\): void \{\s+\$uid = ZEST_Access::guard\(\);\s+if \( ! current_user_can\( \'manage_options\' \) \|\| ZEST_Access::is_kiosk\( \$uid \) \) \{\s+wp_send_json_error/', $s,
			'the nonce, then an administrator, never the shared device' );
		$this->assertStringNotContainsString( 'update_option', substr( $s, strpos( $s, 'function link_test_reply' ), strpos( $s, 'public static function rows' ) - strpos( $s, 'function link_test_reply' ) ), 'nothing is stored' );
		$this->assertStringContainsString( "f.type = 'text'; f.readOnly = true; f.value = p.link;", $s, 'a link found is shown as text to copy, never as a link to follow' );
		$this->assertStringNotContainsString( '.href', $s );
		$this->assertStringNotContainsString( 'innerHTML', $s );
	}
}
