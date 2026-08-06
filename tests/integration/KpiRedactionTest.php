<?php
/**
 * Company revenue must never reach a user who lacks view_company_revenue (the shared kiosk, and
 * any non-financial role). The KPI endpoint strips the dollar-valued tiles on the way out, and
 * fails closed if the permission layer is missing. A disguised "the endpoint gate is redundant"
 * or "fix blank tiles" fix would expose revenue site-wide. This pins the redaction.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

final class KpiRedactionTest extends WP_UnitTestCase {

	/** Invoke the private per-user financial filter on the singleton. */
	private function filter_for( array $metrics, int $uid ): array {
		$kpi = ZDZ_KPI_Metrics::get_instance();
		$m   = new ReflectionMethod( 'ZDZ_KPI_Metrics', 'filter_financial_for_user' );
		$m->setAccessible( true );
		return (array) $m->invoke( $kpi, $metrics, $uid );
	}

	private function sample_metrics(): array {
		return array(
			'ytd_revenue' => array( 'value' => '$100,000', 'raw' => 100000 ),
			'mtd_revenue' => array( 'value' => '$10,000', 'raw' => 10000 ),
			'open_leads'  => array( 'value' => 12, 'raw' => 12 ),
		);
	}

	public function test_revenue_stripped_for_non_privileged_user(): void {
		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$out = $this->filter_for( $this->sample_metrics(), $uid );

		$this->assertArrayNotHasKey( 'ytd_revenue', $out, 'YTD revenue must be dropped for a revenue-denied user.' );
		$this->assertArrayNotHasKey( 'mtd_revenue', $out, 'MTD revenue must be dropped for a revenue-denied user.' );
		$this->assertArrayHasKey( 'open_leads', $out, 'Non-financial counts must remain.' );
	}

	public function test_revenue_kept_for_admin(): void {
		$uid = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$out = $this->filter_for( $this->sample_metrics(), $uid );

		$this->assertArrayHasKey( 'ytd_revenue', $out, 'An administrator with revenue permission should see revenue.' );
		$this->assertArrayHasKey( 'open_leads', $out );
	}

	public function test_fails_closed_for_user_zero(): void {
		// user 0 (logged-out / unknown) must never receive revenue.
		$out = $this->filter_for( $this->sample_metrics(), 0 );
		$this->assertArrayNotHasKey( 'ytd_revenue', $out );
		$this->assertArrayNotHasKey( 'mtd_revenue', $out );
	}
}
