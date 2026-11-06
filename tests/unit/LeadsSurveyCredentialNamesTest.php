<?php
/**
 * Leads: the CRM login and the Ai key come from the platform's credentials first, then this app's own copies, then
 * the copies an earlier surveys app kept, under their renamed zdz_ names (carried across by rename rows).
 *
 *   - No Leads file reads the surveys app's pre-rename option names any more.
 *   - The rename rows carry the three options across; the migration's version is left as it is.
 *   - The fallback reads (and decrypts) the renamed copy when nothing earlier answers.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/leads/includes/class-zl-oauth-state.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/leads/includes/class-zl-admin.php';

final class LeadsSurveyCredentialNamesTest extends TestCase {

	private const DIR = __DIR__ . '/../../zorderz-apps/apps/leads/';

	protected function setUp(): void {
		foreach ( array( 'zl_ns_email', 'zl_ns_api_key', 'zl_poe_api_key', 'zdz_surveys_ns_email', 'zdz_surveys_ns_api_key', 'zdz_surveys_poe_api_key' ) as $k ) {
			unset( $GLOBALS['zdz_test_options'][ $k ] );
		}
	}

	protected function tearDown(): void {
		$this->setUp();
	}

	/** The encryption the surveys app and this app used for their own copies. */
	private function sealed( string $plain ): string {
		$key = wp_salt( 'auth' );
		return (string) openssl_encrypt( $plain, 'AES-256-CBC', $key, 0, substr( hash( 'sha256', $key ), 0, 16 ) );
	}

	public function test_no_leads_file_reads_the_old_names(): void {
		foreach ( array( 'includes/class-zl-admin.php', 'includes/class-zl-lead-generator.php', 'includes/class-zl-nutshell-writeback.php' ) as $f ) {
			$src = (string) file_get_contents( self::DIR . $f );
			$this->assertDoesNotMatchRegularExpression( "/'ts_surveys_(?:ns|poe)_[a-z_]*'/", $src, $f );
			$this->assertStringContainsString( "'zdz_surveys_", $src, $f . ' reads the renamed copies' );
		}
		$gen = (string) file_get_contents( self::DIR . 'includes/class-zl-lead-generator.php' );
		$this->assertStringContainsString( "ZL_Admin::decrypt_shared( 'zl_ns_api_key', 'zdz_surveys_ns_api_key' )", $gen );
		$this->assertStringContainsString( "ZL_Admin::decrypt_shared( 'zl_poe_api_key', 'zdz_surveys_poe_api_key' )", $gen );
	}

	public function test_rename_rows_carry_the_three_options_across(): void {
		$app = (string) file_get_contents( self::DIR . 'app.php' );
		foreach ( array( 'ns_email', 'ns_api_key', 'poe_api_key' ) as $k ) {
			$this->assertMatchesRegularExpression( "/'ts_surveys_{$k}'\s*=>\s*'zdz_surveys_{$k}'/", $app );
		}
		$mig = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-rename-migration.php' );
		$this->assertMatchesRegularExpression( "/const VERSION\s*=\s*2;/", $mig, 'the migration version is left as it is' );
	}

	public function test_the_platform_is_asked_first(): void {
		$src = (string) file_get_contents( self::DIR . 'includes/class-zl-admin.php' );
		foreach ( array( 'get_shared_option', 'decrypt_shared' ) as $fn ) {
			$start = strpos( $src, 'public static function ' . $fn . '(' );
			$body  = substr( $src, $start, strpos( $src, "\n    }\n", $start ) - $start );
			$this->assertLessThan( strpos( $body, 'get_option' ) ?: strpos( $body, 'get_shared_encrypted' ), strpos( $body, 'self::core_first(' ), $fn . ' asks Core first' );
		}
	}

	public function test_the_renamed_surveys_copy_answers_last(): void {
		$GLOBALS['zdz_test_options']['zdz_surveys_ns_email']   = 'crm@example.com';
		$GLOBALS['zdz_test_options']['zdz_surveys_ns_api_key'] = $this->sealed( 'crm-key-0123456789' );
		$this->assertSame( 'crm@example.com', ZL_Admin::get_shared_option( 'zl_ns_email', 'zdz_surveys_ns_email' ) );
		$this->assertSame( 'crm-key-0123456789', ZL_Admin::decrypt_shared( 'zl_ns_api_key', 'zdz_surveys_ns_api_key' ) );

		// This app's own copy wins over the surveys copy.
		$GLOBALS['zdz_test_options']['zl_ns_email']   = 'own@example.com';
		$GLOBALS['zdz_test_options']['zl_ns_api_key'] = $this->sealed( 'own-key-0123456789' );
		$this->assertSame( 'own@example.com', ZL_Admin::get_shared_option( 'zl_ns_email', 'zdz_surveys_ns_email' ) );
		$this->assertSame( 'own-key-0123456789', ZL_Admin::decrypt_shared( 'zl_ns_api_key', 'zdz_surveys_ns_api_key' ) );

		// Nothing anywhere: nothing.
		$this->setUp();
		$this->assertSame( '', ZL_Admin::decrypt_shared( 'zl_poe_api_key', 'zdz_surveys_poe_api_key' ) );
	}
}
