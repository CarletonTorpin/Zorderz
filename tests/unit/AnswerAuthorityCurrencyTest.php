<?php
/**
 * The money sign the chat's answers read figures with is the business's own: Business Profile → Locale → Currency
 * symbol (`locale.currency_sign`), "$" when none is set.
 */
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
}
if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string() { return 'UTC'; }
}
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-business-profile.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-answer-authority.php';

final class AnswerAuthorityCurrencyTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_options']['zdz_business_profile'], $GLOBALS['zdz_test_filters']['zdz_answer_authority_currency'] );
		ZDZ_Business_Profile::flush();
	}

	private static function profile( array $stored ): void {
		$GLOBALS['zdz_test_options']['zdz_business_profile'] = $stored;
		ZDZ_Business_Profile::flush();
	}

	public function test_the_business_profiles_currency_symbol_is_used(): void {
		self::profile( array( 'locale' => array( 'currency' => 'EUR', 'currency_sign' => '€' ) ) );
		$this->assertSame( '€', ZDZ_Answer_Authority::currency()['sigil'] );
	}

	public function test_a_site_with_no_symbol_set_reads_dollars(): void {
		self::profile( array() );
		$this->assertSame( '$', ZDZ_Answer_Authority::currency()['sigil'] );
	}

	public function test_the_sign_follows_the_currency_when_only_the_currency_was_given(): void {
		self::profile( array( 'locale' => array( 'currency' => 'GBP' ) ) );
		$this->assertSame( '£', ZDZ_Business_Profile::get( 'locale.currency_sign' ) );
		$this->assertSame( '£', ZDZ_Answer_Authority::currency()['sigil'] );
		self::profile( array( 'locale' => array( 'currency' => 'eur' ) ) );
		$this->assertSame( '€', ZDZ_Business_Profile::get( 'locale.currency_sign' ) );
		self::profile( array( 'locale' => array( 'currency' => 'CHF' ) ) );
		$this->assertSame( 'CHF ', ZDZ_Business_Profile::get( 'locale.currency_sign' ), 'a currency with no sign of its own reads as its code' );
		self::profile( array( 'locale' => array( 'currency' => 'GBP', 'currency_sign' => 'GBP ' ) ) );
		$this->assertSame( 'GBP ', ZDZ_Business_Profile::get( 'locale.currency_sign' ), 'a sign the business gave always wins' );
	}

	public function test_a_profile_stored_flat_is_still_read(): void {
		self::profile( array( 'currency_sign' => '£', 'locale' => array( 'currency_sign' => '' ) ) );
		$this->assertSame( '£', ZDZ_Answer_Authority::currency()['sigil'] );
	}

	public function test_the_filter_still_has_the_last_word(): void {
		self::profile( array( 'locale' => array( 'currency_sign' => '€' ) ) );
		$GLOBALS['zdz_test_filters']['zdz_answer_authority_currency'] = function ( $c ) {
			$c['sigil'] = 'CHF';
			return $c;
		};
		$this->assertSame( 'CHF', ZDZ_Answer_Authority::currency()['sigil'] );
	}
}
