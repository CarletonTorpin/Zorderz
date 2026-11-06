<?php
/**
 * The words the access-code reader (ZDZ_Sealed) and "Ask chat about this" (ZDZ_Ask_Context) know about a business's
 * trade and region:
 *
 *   - Core knows generic building words only (door, window, opening, size); the trade's own words for things come from
 *     the catalog pack's vocabulary.things (the ask bar's option) and the zdz_sealed_trade_words filter, and a number
 *     beside one of them is a count, a size or a price rather than a code;
 *   - Core knows the street words that come before the name and are common across the United States (Via, Calle,
 *     Camino, Paseo, Avenida, Plaza); a region adds its own by the knowledge pack, an option or a filter, and the
 *     redactor and the map linkifier read the same list;
 *   - a code beside a trade word is still cut when a code word names it.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/zdz-pb-no-content' );
}
if ( ! function_exists( 'get_template_directory' ) ) {
	function get_template_directory() { return sys_get_temp_dir() . '/zdz-pb-no-theme'; }
}
require_once __DIR__ . '/support/privacy-stubs.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-sealed.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-ask-context.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-maps.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-business-profile.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-identity-pack.php';

final class SealedTradeAndStreetWordsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['zdz_test_options'] = array();
		unset( $GLOBALS['zdz_test_filters']['zdz_sealed_trade_words'], $GLOBALS['zdz_test_filters']['zdz_street_first_words'], $GLOBALS['zdz_test_filters']['zdz_street_grammar'] );
		ZDZ_Sealed::flush_lists();
	}

	protected function tearDown(): void {
		$GLOBALS['zdz_test_options'] = array();
		unset( $GLOBALS['zdz_test_filters']['zdz_sealed_trade_words'], $GLOBALS['zdz_test_filters']['zdz_street_first_words'], $GLOBALS['zdz_test_filters']['zdz_street_grammar'] );
		ZDZ_Sealed::flush_lists();
	}

	private static function redact( string $t ): string {
		$hidden = array();
		$map    = array();
		return ZDZ_Ask_Context::redact( $t, array(), array(), $hidden, $map );
	}

	/* ── trade words ──────────────────────────────────────────────────────── */

	public function test_core_reads_generic_door_words_without_any_trade_words(): void {
		$this->assertSame( 'Patio door #3080, French door no. 6080', ZDZ_Sealed::cut( 'Patio door #3080, French door no. 6080' ), 'a door size is not a code' );
		$this->assertStringContainsString( '[gate code sealed]', ZDZ_Sealed::cut( 'Gate code 1450 awnings' ), 'Core does not know the word, so the number stays cut' );
		$this->assertStringContainsString( '[lockbox code sealed]', ZDZ_Sealed::cut( 'lockbox 2290 skylight frame' ) );
	}

	public function test_the_business_adds_its_trade_words_by_the_catalog_option_and_a_filter(): void {
		$GLOBALS['zdz_test_options'][ ZDZ_Sealed::TRADE_WORDS_OPT ] = array( 'awning', 'bad|word' );
		$GLOBALS['zdz_test_filters']['zdz_sealed_trade_words']      = function ( $w ) { $w[] = 'skylight'; return $w; };
		ZDZ_Sealed::flush_lists();
		$this->assertSame( 'Gate code 1450 awnings', ZDZ_Sealed::cut( 'Gate code 1450 awnings' ), 'a count of the trade\'s things' );
		$this->assertSame( 'lockbox 2290 skylight frame', ZDZ_Sealed::cut( 'lockbox 2290 skylight frame' ), 'the filter adds a word' );
		$cut = ZDZ_Sealed::cut( 'Two awnings out back. Gate code is 4821.' );
		$this->assertStringNotContainsString( '4821', $cut, 'a named code is still cut' );
		$this->assertSame( ZDZ_Sealed::TRADE_WORDS_OPT, 'zdz_ask_trade_words', 'the same words the ask bar reads (catalog vocabulary.things)' );
	}

	/* ── street words written before the name ────────────────────────────── */

	public function test_core_street_first_words_are_the_common_ones(): void {
		$this->assertSame( array( 'via', 'vía', 'calle', 'camino', 'paseo', 'avenida', 'plaza' ), ZDZ_Sealed::street_first_words() );
		$this->assertSame( 'Meet at [address] tomorrow', self::redact( 'Meet at 1450 Via Example tomorrow' ) );
		$this->assertSame( array(), ZDZ_Maps::street_grammar(), 'the linkifier keeps its own defaults' );
	}

	public function test_a_region_adds_street_first_words_by_option_and_filter(): void {
		$this->assertSame( 'Meet at 1450 Rue Example tomorrow', self::redact( 'Meet at 1450 Rue Example tomorrow' ), 'Core does not know the word' );
		$GLOBALS['zdz_test_options'][ ZDZ_Sealed::STREET_FIRST_OPT ] = array( 'Rue', '12x', 'a|b' );
		$GLOBALS['zdz_test_filters']['zdz_street_first_words']       = function ( $w ) { $w[] = 'strada'; return $w; };
		$words = ZDZ_Sealed::street_first_words();
		$this->assertContains( 'rue', $words );
		$this->assertContains( 'strada', $words );
		$this->assertNotContains( '12x', $words, 'a word is letters only' );
		$this->assertNotContains( 'a|b', $words );
		$this->assertSame( 'Meet at [address] tomorrow', self::redact( 'Meet at 1450 Rue Example tomorrow' ) );
		$this->assertSame( 'Meet at [address] tomorrow', self::redact( 'Meet at 1450 Strada Example tomorrow' ) );
		$g = ZDZ_Maps::street_grammar();
		$this->assertContains( 'Rue', $g['spanish'], 'the linkifier reads the same list, capitalised' );
		$this->assertContains( 'Via', $g['spanish'] );
	}

	public function test_the_knowledge_pack_writes_the_street_words(): void {
		$p = ZDZ_Identity_Pack::plan_street_words( array( 'addresses.street_first_words' => array( 'Rue', 'Strada  Vecchia', '12x', 'rue' ) ) );
		$this->assertSame( array( 'addresses.street_first_words' ), array_column( $p['refused'], 'path' ), 'a word that is not letters is refused' );
		$this->assertSame( ZDZ_Sealed::STREET_FIRST_OPT, $p['writes'][0]['option'] );
		$this->assertSame( array( 'rue', 'strada vecchia' ), $p['writes'][0]['value'] );
		$this->assertArrayHasKey( 'street-words', ZDZ_Identity_Pack::settings_consumers() );
	}
}
