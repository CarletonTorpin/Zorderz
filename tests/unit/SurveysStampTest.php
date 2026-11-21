<?php
/**
 * The notes Surveys leaves in the CRM, and the dates on its reviews panel, are written in the site's own date and time
 * formats (Settings → General), with plain English ones when the site has none.
 */
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/surveys/includes/class-zsv-settings.php';

final class SurveysStampTest extends TestCase {

	const MOMENT = 1700000000; // a fixed moment

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_options']['date_format'], $GLOBALS['zdz_test_options']['time_format'] );
	}

	public function test_a_note_uses_the_sites_formats(): void {
		$GLOBALS['zdz_test_options']['date_format'] = 'd/m/Y';
		$GLOBALS['zdz_test_options']['time_format'] = 'H:i';
		$this->assertSame( gmdate( 'd/m/Y H:i', self::MOMENT ), ZSV_Settings::stamp( self::MOMENT ) );
		$this->assertSame( gmdate( 'M j', self::MOMENT ) . ', ' . gmdate( 'H:i', self::MOMENT ), ZSV_Settings::short_stamp( self::MOMENT ) );
	}

	public function test_a_site_without_formats_gets_the_plain_english_ones(): void {
		$this->assertSame( gmdate( 'M j, Y g:i A', self::MOMENT ), ZSV_Settings::stamp( self::MOMENT ) );
		$this->assertSame( gmdate( 'M j', self::MOMENT ), ZSV_Settings::short_day( self::MOMENT ) );
	}
}
