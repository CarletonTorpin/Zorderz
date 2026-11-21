<?php
/**
 * Business Profile → Locale: everything a person reads follows the business's country unless the business set it.
 *
 *   - a business in the United States (or one that names no country, on a site in US English) reads exactly as
 *     before: ZIP, State, inches and °F, "M j, Y", 2:30 pm, 1,234.56, dollars;
 *   - a business elsewhere gets its country's words, units, clock, grouping and currency, and textual dates day first;
 *   - a value the business stored always wins; a blank one follows the country again.
 */
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
}
if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string() { return 'UTC'; }
}
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-business-profile.php';

final class BusinessProfileLocaleTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_options']['zdz_business_profile'] );
		ZDZ_Business_Profile::flush();
	}

	private static function profile( array $stored ): void {
		$GLOBALS['zdz_test_options']['zdz_business_profile'] = $stored;
		ZDZ_Business_Profile::flush();
	}

	private static function in( string $country, array $locale = array() ): void {
		self::profile( array( 'contact' => array( 'address' => array( 'country' => $country ) ), 'locale' => $locale ) );
	}

	public function test_a_business_in_the_united_states_reads_as_before(): void {
		foreach ( array( array(), array( 'contact' => array( 'address' => array( 'country' => 'United States' ) ) ) ) as $stored ) {
			self::profile( $stored );
			$this->assertSame( 'US', ZDZ_Business_Profile::country() );
			$this->assertSame( 'ZIP', ZDZ_Business_Profile::postcode_label() );
			$this->assertSame( 'State', ZDZ_Business_Profile::region_label() );
			$this->assertSame( 'us', ZDZ_Business_Profile::units() );
			$this->assertSame( 'g:i a', ZDZ_Business_Profile::time_format() );
			$this->assertSame( 'n/j/Y', ZDZ_Business_Profile::date_format() );
			$this->assertSame( 'USD', ZDZ_Business_Profile::get( 'locale.currency' ) );
			$this->assertSame( '$1,080.50', ZDZ_Business_Profile::money( 1080.5 ) );
			$this->assertSame( '12,500', ZDZ_Business_Profile::number( 12500 ) );
			$this->assertSame( '72°F', ZDZ_Business_Profile::temperature( 72.4 ) );
			$this->assertSame( '0.25 in', ZDZ_Business_Profile::rain( 0.25 ) );
			$this->assertSame( 'en-US', ZDZ_Business_Profile::js_locale() );
			foreach ( array( 'M j, Y', 'M j, Y g:i a', 'D, M j', 'l, F j, Y', 'M j, Y \a\t g:i A T', 'M j, g:ia' ) as $f ) {
				$this->assertSame( $f, ZDZ_Business_Profile::date_text( $f ), "$f is untouched in the United States" );
			}
		}
	}

	public function test_a_business_in_the_united_kingdom_gets_its_own_words_and_measures(): void {
		self::in( 'United Kingdom' );
		$this->assertSame( 'GB', ZDZ_Business_Profile::country() );
		$this->assertSame( 'Postcode', ZDZ_Business_Profile::postcode_label() );
		$this->assertSame( 'County', ZDZ_Business_Profile::region_label() );
		$this->assertSame( 'metric', ZDZ_Business_Profile::units() );
		$this->assertSame( 'H:i', ZDZ_Business_Profile::time_format() );
		$this->assertSame( 'j/n/Y', ZDZ_Business_Profile::date_format(), 'a site left in US English still writes dates the British way' );
		$this->assertSame( 'GBP', ZDZ_Business_Profile::get( 'locale.currency' ) );
		$this->assertSame( '£1,080.50', ZDZ_Business_Profile::money( 1080.5 ) );
		$this->assertSame( '22°C', ZDZ_Business_Profile::temperature( 72 ) );
		$this->assertSame( '6 mm', ZDZ_Business_Profile::rain( 0.25 ) );
		$this->assertSame( 'en-GB', ZDZ_Business_Profile::js_locale() );
		$this->assertSame( 'j M Y', ZDZ_Business_Profile::date_text( 'M j, Y' ) );
		$this->assertSame( 'j M Y H:i', ZDZ_Business_Profile::date_text( 'M j, Y g:i a' ) );
		$this->assertSame( 'D j M', ZDZ_Business_Profile::date_text( 'D, M j' ) );
		$this->assertSame( 'D j M', ZDZ_Business_Profile::date_text( 'D M j' ) );
		$this->assertSame( 'l j F Y', ZDZ_Business_Profile::date_text( 'l, F j, Y' ) );
		$this->assertSame( 'j M Y \a\t H:i T', ZDZ_Business_Profile::date_text( 'M j, Y \a\t g:i A T' ), 'escaped letters are kept' );
		$this->assertSame( 'j M, H:i', ZDZ_Business_Profile::date_text( 'M j, g:ia' ) );
		$this->assertSame( 'j M Y · H:i', ZDZ_Business_Profile::date_text( 'M j, Y · g:i A' ) );
		$this->assertSame( 'jS M', ZDZ_Business_Profile::date_text( 'M jS' ) );
		$this->assertSame( 'F Y', ZDZ_Business_Profile::date_text( 'F Y' ), 'a month and year alone has no order to change' );
	}

	public function test_numbers_follow_the_language_and_the_country(): void {
		self::in( 'Germany', array( 'locale' => 'de_DE' ) );
		$this->assertSame( 'EUR', ZDZ_Business_Profile::get( 'locale.currency' ) );
		$this->assertSame( '€1.080,50', ZDZ_Business_Profile::money( 1080.5 ) );
		$this->assertSame( 'j.n.Y', ZDZ_Business_Profile::date_format() );
		$this->assertSame( array( 'Postcode', 'State', 'H:i' ), array( ZDZ_Business_Profile::postcode_label(), ZDZ_Business_Profile::region_label(), ZDZ_Business_Profile::time_format() ) );
		self::in( 'France', array( 'locale' => 'fr_FR' ) );
		$this->assertSame( "12\u{00A0}500,5", ZDZ_Business_Profile::number( 12500.5, 1 ) );
		self::in( 'Switzerland', array( 'locale' => 'de_CH' ) );
		$this->assertSame( "12\u{2019}500.50", ZDZ_Business_Profile::number( 12500.5, 2 ) );
		$this->assertSame( 'CHF', ZDZ_Business_Profile::get( 'locale.currency' ) );
		$this->assertSame( 'CHF 12', ZDZ_Business_Profile::money( 12, 0 ) );
	}

	public function test_a_stored_value_always_wins_and_a_blank_one_follows_the_country(): void {
		self::in( 'United Kingdom', array( 'units' => 'us', 'postcode_label' => 'Post code', 'region_label' => '', 'time_format' => 'g:i A', 'number_format' => '1.234,56', 'currency' => 'USD', 'date_format' => 'n/j/Y' ) );
		$this->assertSame( 'us', ZDZ_Business_Profile::units() );
		$this->assertSame( 'Post code', ZDZ_Business_Profile::postcode_label() );
		$this->assertSame( 'County', ZDZ_Business_Profile::region_label() );
		$this->assertSame( 'g:i A', ZDZ_Business_Profile::time_format() );
		$this->assertSame( '$1.080,50', ZDZ_Business_Profile::money( 1080.5 ) );
		$this->assertSame( 'M j, Y g:i A', ZDZ_Business_Profile::date_text( 'M j, Y g:i a' ), 'a month-first date format keeps textual dates month first' );

		self::in( 'United States', array( 'units' => 'imperial', 'time_format' => 'not a clock', 'number_format' => '1-234' ) );
		$this->assertSame( 'us', ZDZ_Business_Profile::units(), 'a value that is not a choice follows the country' );
		$this->assertSame( 'g:i a', ZDZ_Business_Profile::time_format() );
		$this->assertSame( '1,234', ZDZ_Business_Profile::number( 1234 ) );
	}

	public function test_the_country_is_read_from_a_name_a_code_or_the_language(): void {
		$this->assertSame( 'US', ZDZ_Business_Profile::country_code_from( '', '' ) );
		$this->assertSame( 'US', ZDZ_Business_Profile::country_code_from( 'U.S.A.', 'en_GB' ), 'the address beats the language' );
		$this->assertSame( 'GB', ZDZ_Business_Profile::country_code_from( 'uk', '' ) );
		$this->assertSame( 'NZ', ZDZ_Business_Profile::country_code_from( 'nz', '' ) );
		$this->assertSame( 'CA', ZDZ_Business_Profile::country_code_from( 'Atlantis', 'fr_CA' ), 'an unknown name falls to the language' );
		$this->assertSame( 'en_AU', ZDZ_Business_Profile::language_for_country( 'en_US', 'AU' ) );
		$this->assertSame( 'fr_FR', ZDZ_Business_Profile::language_for_country( 'fr_FR', 'BE' ), 'a language that says more is kept' );
		$this->assertSame( 'en_US', ZDZ_Business_Profile::language_for_country( 'en_US', 'US' ) );
		self::in( 'Canada' );
		$this->assertSame( array( 'Postal code', 'Province', 'metric', 'g:i a', 'CAD' ), array( ZDZ_Business_Profile::postcode_label(), ZDZ_Business_Profile::region_label(), ZDZ_Business_Profile::units(), ZDZ_Business_Profile::time_format(), ZDZ_Business_Profile::get( 'locale.currency' ) ) );
		self::in( 'Australia' );
		$this->assertSame( array( 'Postcode', 'State' ), array( ZDZ_Business_Profile::postcode_label(), ZDZ_Business_Profile::region_label() ) );
	}

	public function test_page_scripts_get_the_same_settings(): void {
		self::in( 'Ireland' );
		$this->assertSame(
			array( 'country' => 'IE', 'units' => 'metric', 'postcodeLabel' => 'Eircode', 'regionLabel' => 'County', 'dateOrder' => 'j', 'hour12' => false, 'decimal' => '.', 'thousands' => ',' ),
			ZDZ_Business_Profile::l10n_for_js()
		);
		self::profile( array() );
		$this->assertSame( array( 'n', true ), array( ZDZ_Business_Profile::l10n_for_js()['dateOrder'], ZDZ_Business_Profile::l10n_for_js()['hour12'] ) );
	}
}
