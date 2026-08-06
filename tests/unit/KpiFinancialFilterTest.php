<?php
/**
 * Company revenue must never reach a user who lacks view_company_revenue. The redaction now
 * decides per metric (is_financial_metric) instead of a fixed two-item denylist, so a NEW
 * dollar metric added later cannot slip through. This pins that: money is recognized by an
 * explicit flag, a currency-sigil value, or a money-named key; plain counts are not.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

final class KpiFinancialFilterTest extends TestCase {

	private static function is_financial( string $key, $data ): bool {
		return ZDZ_KPI_Metrics::is_financial_metric( $key, $data );
	}

	public function test_explicit_flag_is_financial(): void {
		$this->assertTrue( self::is_financial( 'anything', array( 'value' => 'x', 'financial' => true ) ) );
	}

	public function test_currency_valued_metric_is_financial(): void {
		$this->assertTrue( self::is_financial( 'weird_key', array( 'value' => '$100,000' ) ) );
		$this->assertTrue( self::is_financial( 'ytd_revenue', array( 'value' => '$1.2K' ) ) );
	}

	/** The known revenue tiles, and a NEW dollar metric a disguised PR might add via the filter. */
	public function test_money_named_metrics_are_financial(): void {
		foreach ( array( 'ytd_revenue', 'mtd_revenue', 'outstanding_ar', 'accounts_receivable', 'net_profit', 'monthly_income' ) as $key ) {
			$this->assertTrue( self::is_financial( $key, array( 'value' => '5' ) ), "Expected financial: {$key}" );
		}
	}

	/** Plain counts must survive redaction so the kiosk still shows non-money KPIs. */
	public function test_counts_are_not_financial(): void {
		foreach ( array(
			'estimates_mtd' => array( 'value' => '12', 'raw' => 12 ),
			'open_leads'    => array( 'value' => '7', 'raw' => 7 ),
			'google_reviews'=> array( 'value' => '48', 'raw' => 48 ),
		) as $key => $data ) {
			$this->assertFalse( self::is_financial( $key, $data ), "Should NOT be financial: {$key}" );
		}
	}

	public function test_a_revenue_denied_view_keeps_only_counts(): void {
		// Simulate the deny branch behavior against a realistic metric map.
		$metrics = array(
			'ytd_revenue'   => array( 'value' => '$100,000', 'raw' => 100000 ),
			'mtd_revenue'   => array( 'value' => '$8,000', 'raw' => 8000 ),
			'outstanding_ar'=> array( 'value' => '$3,200', 'raw' => 3200 ), // new dollar metric
			'estimates_mtd' => array( 'value' => '12', 'raw' => 12 ),
			'open_leads'    => array( 'value' => '7', 'raw' => 7 ),
		);
		$kept = array();
		foreach ( $metrics as $k => $d ) {
			if ( ! self::is_financial( $k, $d ) ) {
				$kept[ $k ] = $d;
			}
		}
		$this->assertSame( array( 'estimates_mtd', 'open_leads' ), array_keys( $kept ),
			'Only non-money counts should survive when revenue is denied.' );
	}
}
