<?php
/**
 * The surveys app's billing-app copy is carried across by the rename migration, and Leads reads
 * it under its current name.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'ZDZ_Rename_Migration' ) ) {
	require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-rename-migration.php';
}

final class SurveysBillingRenameTest extends TestCase {

	public function test_map_carries_the_surveys_billing_family(): void {
		$opts = ZDZ_Rename_Migration::map()['options'];
		foreach ( array( 'client_id', 'client_secret', 'access_token', 'refresh_token', 'account_id', 'expires_at' ) as $suf ) {
			$this->assertArrayHasKey( 'ts_surveys_fb_' . $suf, $opts );
			$this->assertSame( 'zdz_surveys_fb_' . $suf, $opts[ 'ts_surveys_fb_' . $suf ] );
		}
	}

	public function test_leads_reads_only_the_current_names(): void {
		$dir = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/leads/includes/';
		foreach ( glob( $dir . '*.php' ) as $f ) {
			$this->assertStringNotContainsString( "'ts_surveys_fb_", (string) file_get_contents( $f ), basename( $f ) );
		}
		$this->assertStringContainsString( "'zdz_surveys_fb_client_id'", (string) file_get_contents( $dir . 'class-zl-admin.php' ) );
	}

	public function test_token_service_keeps_the_family_in_step(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-token-service.php' );
		$this->assertStringContainsString( "'zdz_surveys_fb_'", $src );
	}
}
