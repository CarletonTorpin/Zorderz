<?php
/**
 * A name the question writes two ways (ZANA_Name_Variants): one message can name the same customer with two spellings,
 * and the chat is told so in one line instead of answering as if they were two jobs.
 *
 *   - a pair is two capitalised words of six letters or more that are one letter apart, or the same letters with two
 *     moved; a possessive and a quoted word are read as the name; letters are counted, not bytes;
 *   - never the same word, two short names a letter apart, two changes that are not letters moved, lower-case words,
 *     the words of paperwork, or a word and its plain plural (unless one of them is a known name);
 *   - the business's own words for what it sells are not names (its catalog writes them; Core ships none), and a pair
 *     its sound-alike list records as two different names is never called one;
 *   - with the platform's word lists, an ordinary word that is nobody's name is left out, and a surname with and
 *     without its s is a pair;
 *   - the note names the pair in the person's own spelling and says what to do; at most three pairs.
 *
 * Invented names only (Morgan, Jordan, Devin, Casey, Garland, Example Glass, and letter runs that are nobody's name).
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/privacy-stubs.php';

$zdz_test_nv_root = dirname( __DIR__, 2 );
foreach ( array( 'privacy-view', 'name-match', 'ask-phrases' ) as $zdz_test_nv_f ) {
	require_once $zdz_test_nv_root . '/zorderz/inc/class-zdz-' . $zdz_test_nv_f . '.php';
}
require_once $zdz_test_nv_root . '/zorderz-apps/apps/analytics/includes/class-zana-name-variants.php';

final class ChatNameVariantsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['zdz_test_options']    = array();
		$GLOBALS['zdz_test_filters']    = array();
		ZANA_Name_Variants::$map        = array();
		ZANA_Name_Variants::$word_class = false;
	}

	protected function tearDown(): void {
		ZANA_Name_Variants::$map        = null;
		ZANA_Name_Variants::$word_class = null;
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_filters'] );
	}

	public function test_a_name_written_two_ways_is_found_by_its_letters(): void {
		$msg = 'Paint work for the Morgan job at the house. Also the Mogran gutter job is a good example. Today we meet in Northtown.';
		$this->assertSame( array( array( 'Morgan', 'Mogran' ) ), ZANA_Name_Variants::find( $msg ), 'two letters moved' );
		$this->assertSame( array( array( 'Jordan', 'Jordon' ) ), ZANA_Name_Variants::find( 'The Jordan job, the Jordon invoice' ), 'one letter changed' );
		$this->assertSame( array( array( 'Morgann', 'Morgan' ) ), ZANA_Name_Variants::find( 'Call Morgann. Then Morgan again.' ), 'a letter missing' );
		$this->assertSame( array( array( 'Morgan', 'Mogran' ) ), ZANA_Name_Variants::find( "Morgan's quote and the 'Mogran' invoice" ), 'a possessive and a quoted word are read as the name' );
		$this->assertSame( array( array( 'Élodie', 'Élodei' ) ), ZANA_Name_Variants::find( 'For Élodie and then Élodei' ), 'letters, not bytes' );

		$this->assertSame( array(), ZANA_Name_Variants::find( 'Morgan and Morgan again, and MORGAN' ), 'the same word is not a pair' );
		$this->assertSame( array(), ZANA_Name_Variants::find( 'Devin and Kevin both called' ), 'short names a letter apart are two names' );
		$this->assertSame( array(), ZANA_Name_Variants::find( 'Casey and Kasey' ) );
		$this->assertSame( array(), ZANA_Name_Variants::find( 'Jordan and Morgan' ), 'two changes that are not letters moved: different names' );
		$this->assertSame( array(), ZANA_Name_Variants::find( 'today the invoice for monday' ), 'lower-case words are not names' );
		$this->assertSame( array(), ZANA_Name_Variants::find( 'Invoice Invoices Estimate Estimates Customer Customers' ), 'the words of paperwork are not names' );
		$this->assertSame( array(), ZANA_Name_Variants::find( 'Ladder and Ladders, Bucket and Buckets' ), 'a word and its plural are one word' );
		$this->assertSame( array(), ZANA_Name_Variants::find( 'Invoice for the job. Invocie again.' ), 'a typo of a paperwork word is not a name' );
		$this->assertSame( array(), ZANA_Name_Variants::find( '' ) );
		$this->assertSame( array(), ZANA_Name_Variants::find( array( 'Morgan', 'Mogran' ) ) );

		$many = 'Abcdefg Bacdefg Hijklmn Ihjklmn Opqrstu Poqrstu Vwxyzab Wvxyzab';
		$this->assertCount( ZANA_Name_Variants::MAX_PAIRS, ZANA_Name_Variants::find( $many ), 'at most three pairs' );
	}

	public function test_the_note_names_the_pair_and_says_what_to_do(): void {
		$n = ZANA_Name_Variants::note( 'The Morgan job and the Mogran job' );
		$this->assertSame( 0, strpos( $n, "NAME SPELLINGS (a note from the app about the person's own message; not a record)\n" ) );
		$this->assertStringContainsString( '"Morgan" and "Mogran"', $n );
		$this->assertStringContainsString( 'most likely ONE name written two ways', $n );
		$this->assertStringContainsString( 'the jobs the question names with either spelling as the SAME job', $n );
		$this->assertStringContainsString( 'Never report two jobs, or "not found", on a spelling alone.', $n );
		$this->assertSame( '', ZANA_Name_Variants::note( 'Morgan only' ), 'no pair, no note' );
	}

	public function test_the_business_s_own_words_and_names_decide(): void {
		// the words for what it sells are not names (its catalog writes them; Core ships none)
		$this->assertSame( array( array( 'Gutter', 'Guttre' ) ), ZANA_Name_Variants::find( 'Gutter for Sample. Guttre again.' ) );
		$GLOBALS['zdz_test_options']['zdz_ask_trade_words'] = array( 'gutter', 'chimney cap' );
		$this->assertSame( array(), ZANA_Name_Variants::find( 'Gutter for Sample. Guttre again.' ) );
		$this->assertArrayHasKey( 'gutters', ZANA_Name_Variants::not_names() );
		$GLOBALS['zdz_test_filters']['zana_name_variants_not_names'] = function ( $w ) {
			return array( 'Morgan' );
		};
		$this->assertSame( array(), ZANA_Name_Variants::find( 'The Morgan job and the Mogran job' ) );
		unset( $GLOBALS['zdz_test_filters']['zana_name_variants_not_names'] );

		// the business's sound-alike list says these two are different names: never called one
		$this->assertSame( array( array( 'Exemple', 'Example' ) ), ZANA_Name_Variants::find( 'Exemple Glass and Example Glass' ) );
		ZANA_Name_Variants::$map = array(
			'surnames'                => array( array( 'spellings' => array( 'Morgan', 'Morgen' ) ) ),
			'distinct_but_confusable' => array( array( 'names' => array( 'Example Glass', 'Exemple Glass' ), 'status' => 'distinct' ) ),
			'not_soundalike_flags'    => array( array( 'names' => array( 'Jordan', 'Jordon' ) ), 'not a group' ),
		);
		$this->assertSame( array(), ZANA_Name_Variants::find( 'Exemple Glass and Example Glass' ) );
		$this->assertSame( array(), ZANA_Name_Variants::find( 'Jordan first, Jordon second' ) );
		$this->assertTrue( ZANA_Name_Variants::known_distinct( 'example', 'exemple' ) );
		$this->assertFalse( ZANA_Name_Variants::known_distinct( 'glass', 'glass' ), 'the same word of both names is not a pair of names' );
		$this->assertSame( array( array( 'Morgan', 'Morgen' ) ), ZANA_Name_Variants::find( 'Morgan or Morgen' ), 'a group of spellings of one name changes nothing' );
		// with no list injected the platform's own is read (Core ships none)
		ZANA_Name_Variants::$map = null;
		$this->assertSame( array( array( 'Morgan', 'Mogran' ) ), ZANA_Name_Variants::find( 'The Morgan job and the Mogran job' ) );
		$this->assertTrue( ZANA_Name_Variants::close( 'morgan', 'mogran' ) );
		$this->assertFalse( ZANA_Name_Variants::close( 'morgan', 'morgan' ) );
		$this->assertFalse( ZANA_Name_Variants::close( 'kevin', 'devin' ) );
	}

	public function test_an_ordinary_word_is_not_a_name_when_the_platform_s_word_lists_are_there(): void {
		// without the lists (as set up): two capitalised ordinary words a letter apart read as a pair
		$this->assertSame( array( array( 'Painted', 'Painter' ) ), ZANA_Name_Variants::find( 'Painted trim. Painter came.' ) );
		// with them: an ordinary word that is nobody's name is left out; a misspelled name still pairs with a name
		ZANA_Name_Variants::$word_class = null;
		$this->assertSame( array(), ZANA_Name_Variants::find( 'Painted trim. Painter came.' ) );
		$this->assertSame( array(), ZANA_Name_Variants::find( 'Shutter on the left. Shuttre on the right.' ), 'a typo of an ordinary word is not a name' );
		$this->assertSame( array( array( 'Morgan', 'Mogran' ) ), ZANA_Name_Variants::find( 'The Morgan job and the Mogran job' ) );
		$this->assertSame( array( array( 'Jordan', 'Jodran' ) ), ZANA_Name_Variants::find( 'Jordan then Jodran' ) );
		// a surname with and without its s is the commonest slip: a plural is one word only when neither is a known name
		$this->assertSame( array( array( 'Garland', 'Garlands' ) ), ZANA_Name_Variants::find( 'The Garland job. The Garlands called.' ) );
		$this->assertSame( array(), ZANA_Name_Variants::find( 'Ladder on the left. Ladders on the right.' ) );
		$seen                           = array();
		ZANA_Name_Variants::$word_class = function ( $w ) use ( &$seen ) {
			$seen[] = $w;
			return array( 'cls' => 'name', 'cl' => 'Abcdefg' === $w );   // one word the lists know as a surname; the rest are in no list
		};
		$this->assertSame( array( array( 'Abcdefg', 'Abcdefgs' ) ), ZANA_Name_Variants::find( 'Abcdefg and Abcdefgs' ), 'a known surname and its s' );
		$this->assertSame( array(), ZANA_Name_Variants::find( 'Hijklmn and Hijklmns' ), 'a word in no list and its plural: one word' );
		$this->assertSame( array( array( 'Hijklmn', 'Ihjklmn' ) ), ZANA_Name_Variants::find( 'Hijklmn and Ihjklmn' ), 'a word in no list may be a name' );
		$this->assertContains( 'Hijklmn', $seen, 'asked as the person wrote it' );
		ZANA_Name_Variants::$word_class = function ( $w ) {
			return array( 'cls' => 'hon' );
		};
		$this->assertSame( array(), ZANA_Name_Variants::find( 'Hijklmn and Ihjklmn' ), 'a title is nobody\'s name' );
		ZANA_Name_Variants::$word_class = function ( $w ) {
			return array( 'cls' => 'safe', 'cf' => true );
		};
		$this->assertSame( array(), ZANA_Name_Variants::find( 'Hijklmn and Ihjklmn' ), 'a word the lists hold safe is never a name here' );
		// a word check that fails changes nothing
		ZANA_Name_Variants::$word_class = function ( $w ) {
			throw new RuntimeException( 'no lists' );
		};
		$this->assertSame( array( array( 'Morgan', 'Mogran' ) ), ZANA_Name_Variants::find( 'The Morgan job and the Mogran job' ) );
	}
}
