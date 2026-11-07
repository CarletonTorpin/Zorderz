<?php
/**
 * Leads reads the surveys app's billing-app copy under its current name, and the token service keeps it in step.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

final class SurveysBillingRenameTest extends TestCase {

	public function test_leads_reads_the_current_name(): void {
		$dir = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/leads/includes/';
		$this->assertStringContainsString( "'zdz_surveys_fb_client_id'", (string) file_get_contents( $dir . 'class-zl-admin.php' ) );
	}

	public function test_token_service_keeps_the_family_in_step(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-token-service.php' );
		$this->assertStringContainsString( "'zdz_surveys_fb_'", $src );
	}
}
