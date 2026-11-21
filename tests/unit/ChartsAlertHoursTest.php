<?php
/**
 * Charts e-mails a hub alert only in the business's hours. With none saved in Charts, those are the business's usual
 * hours (Core's setting, which an org pack writes as work_hours.default), else Monday to Friday 9 to 17; a window
 * already saved is kept, and saving the Charts settings never freezes the usual hours into Charts.
 */
use PHPUnit\Framework\TestCase;

foreach ( array( 'ZCH_OPTION' => 'zch_settings' ) as $zdz_test_ah_k => $zdz_test_ah_v ) {
	if ( ! defined( $zdz_test_ah_k ) ) {
		define( $zdz_test_ah_k, $zdz_test_ah_v );
	}
}
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-capabilities.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-for-you.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/charts/includes/class-zch-store.php';

final class ChartsAlertHoursTest extends TestCase {

	protected function setUp(): void {
		unset( $GLOBALS['zdz_test_options']['zdz_work_hours_default'], $GLOBALS['zdz_test_options'][ ZCH_OPTION ] );
		ZCH_Store::$settings = null;
	}

	protected function tearDown(): void {
		$this->setUp();
	}

	public function test_without_usual_hours_the_window_is_core_s(): void {
		$s = ZCH_Store::settings();
		$this->assertSame( array( 9, 17 ), $s['business_hours'] );
		$this->assertSame( array( 1, 2, 3, 4, 5 ), $s['business_days'] );
	}

	public function test_the_business_s_usual_hours_set_the_window(): void {
		$GLOBALS['zdz_test_options']['zdz_work_hours_default'] = array( 'days' => array( 2, 3, 4, 5, 6 ), 'start' => '08:00', 'end' => '16:30' );
		$s = ZCH_Store::settings();
		$this->assertSame( array( 8, 17 ), $s['business_hours'], 'an hour that has begun counts' );
		$this->assertSame( array( 2, 3, 4, 5, 6 ), $s['business_days'] );
	}

	public function test_hours_past_midnight_keep_the_plain_window(): void {
		$GLOBALS['zdz_test_options']['zdz_work_hours_default'] = array( 'days' => array( 1, 2 ), 'start' => '22:00', 'end' => '06:00' );
		$this->assertSame( array( 9, 17 ), ZCH_Store::settings()['business_hours'] );
	}

	public function test_a_saved_window_is_kept(): void {
		$GLOBALS['zdz_test_options']['zdz_work_hours_default'] = array( 'days' => array( 1, 2, 3 ), 'start' => '10:00', 'end' => '14:00' );
		$GLOBALS['zdz_test_options'][ ZCH_OPTION ]              = array( 'business_hours' => array( 6, 20 ), 'business_days' => array( 1, 2, 3, 4, 5, 6, 7 ) );
		$s = ZCH_Store::settings();
		$this->assertSame( array( 6, 20 ), $s['business_hours'] );
		$this->assertSame( array( 1, 2, 3, 4, 5, 6, 7 ), $s['business_days'] );
	}
}
