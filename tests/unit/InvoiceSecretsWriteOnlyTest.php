<?php
/**
 * Invoices' settings screen never prints a stored secret: the Stripe secret key and webhook
 * signing secret, the FreshBooks client secret and the legacy static token render as empty
 * write-only boxes with a "Currently set" note, and a blank submit keeps the stored value.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $p = '' ) { return 'https://example.com/wp-admin/' . ltrim( (string) $p, '/' ); }
}
if ( ! function_exists( 'settings_fields' ) ) {
	function settings_fields( $g ) { echo '<input type="hidden" name="option_page" value="' . htmlspecialchars( (string) $g ) . '">'; }
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $a = -1, $n = '_wpnonce', $r = true, $echo = true ) { $h = '<input type="hidden" name="_wpnonce" value="nonce">'; if ( $echo ) { echo $h; } return $h; }
}
if ( ! function_exists( 'submit_button' ) ) {
	function submit_button( $t = 'Save' ) { echo '<button>' . htmlspecialchars( (string) $t ) . '</button>'; }
}

require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/invoice/includes/class-zic-admin.php';

final class InvoiceSecretsWriteOnlyTest extends TestCase {

	const SECRETS = array(
		'zic_stripe_secret'         => 'sk_test_example_secret_0001',
		'zic_stripe_webhook_secret' => 'whsec_example_signing_0001',
		'zic_fb_client_secret'      => 'billing-client-secret-0001',
		'zic_freshbooks_token'      => 'legacy-static-token-0001',
	);

	protected function setUp(): void {
		$GLOBALS['zdz_test_options'] = self::SECRETS + array(
			'zic_stripe_publishable' => 'pk_test_example_0001',
			'zic_fb_client_id'       => 'client-0001',
		);
		// the settings screen is for an administrator
		$GLOBALS['zdz_test_current_user'] = 1;
		$GLOBALS['zdz_test_user_roles']   = array( 1 => array( 'administrator' ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_current_user'], $GLOBALS['zdz_test_user_roles'] );
	}

	private function page(): string {
		ob_start();
		ZIC_Admin::render_settings();
		return (string) ob_get_clean();
	}

	public function test_no_stored_secret_is_printed_into_the_page(): void {
		$html = $this->page();
		foreach ( self::SECRETS as $opt => $secret ) {
			$this->assertStringNotContainsString( $secret, $html, $opt . ' must never reach the browser' );
			$this->assertMatchesRegularExpression( '/name="' . $opt . '" value=""/', $html, $opt . ' renders as an empty box' );
		}
		$this->assertStringContainsString( 'Currently set (hidden). Leave blank to keep it.', $html );
		$this->assertStringContainsString( 'pk_test_example_0001', $html, 'the publishable key is not a secret' );
	}

	public function test_a_blank_submit_keeps_the_stored_secret_and_a_typed_one_replaces_it(): void {
		$this->assertSame( self::SECRETS['zic_stripe_secret'], ZIC_Admin::sanitize_secret( '', 'zic_stripe_secret' ) );
		$this->assertSame( self::SECRETS['zic_stripe_secret'], ZIC_Admin::sanitize_secret( null, 'zic_stripe_secret' ) );
		$this->assertSame( 'sk_test_example_secret_0002', ZIC_Admin::sanitize_secret( '  sk_test_example_secret_0002 ', 'zic_stripe_secret' ) );
		$this->assertSame( 'pk_test_example_0001', ZIC_Admin::sanitize_kept_when_absent( null, 'zic_stripe_publishable' ), 'a field left out of the form keeps its value' );
		$this->assertSame( '', ZIC_Admin::sanitize_kept_when_absent( '', 'zic_stripe_connected_account' ), 'a field cleared on purpose is cleared' );
	}

	public function test_an_empty_secret_says_not_set(): void {
		unset( $GLOBALS['zdz_test_options']['zic_stripe_webhook_secret'] );
		$this->assertStringContainsString( 'placeholder="Not set"', ZIC_Admin::secret_input( 'zic_stripe_webhook_secret' ) );
		$this->assertStringNotContainsString( 'Currently set', ZIC_Admin::secret_input( 'zic_stripe_webhook_secret' ) );
	}
}
