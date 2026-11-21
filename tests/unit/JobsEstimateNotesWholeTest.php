<?php
/**
 * An opened Project carries what its estimate says in words, whole.
 *
 *   - Zjob_Project_Resolver::clip(): a note is held to a bound counted in characters, never bytes; under the bound
 *     it is untouched; over it, it is cut on a character and ends with a plain sentence saying where the rest is.
 *     The same without the multibyte functions, and without an error on text that is not UTF-8.
 *   - without_amounts(): every amount of money is taken out (this panel is read by people without money access);
 *     measurements, counts and document numbers stay; line breaks are kept; the business's own currency counts.
 *   - said_from_rows() / src_said(): each estimate's notes and what the salesperson wrote or said, in the order the
 *     Project recorded its estimates, a few of them at most with the rest counted; a work-class source.
 *   - The Projects script draws them whole and escaped, and nothing in the stylesheet cuts them short.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

$zjob_test_notes_root = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/';
require_once $zjob_test_notes_root . 'jobs/includes/class-zjob-project-resolver.php';
require_once $zjob_test_notes_root . 'estimate/includes/class-zest-db.php';
unset( $zjob_test_notes_root );

/** A $wpdb that knows one Project's estimate references and the estimate rows they point at. */
final class ZJOB_Test_Estimate_Notes_DB {
	public $prefix  = 'wp_';
	public $refs    = array(); // [ [ work_item_id, external_id, ref_seq ] ]
	public $rows    = array(); // estimate id => row
	public $queries = array();

	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback( '/%[dsf]/', function ( $m ) use ( &$i, $args ) {
			$v = $args[ $i++ ] ?? '';
			return '%s' === $m[0] ? "'" . addslashes( (string) $v ) . "'" : (string) ( 0 + $v );
		}, $sql );
	}
	public function get_results( $sql, $o = null ) {
		$this->queries[] = $sql;
		$out             = array();
		if ( false !== strpos( $sql, 'wp_zdz_work_item_refs' ) && preg_match( "/work_item_id = '([^']+)'/", $sql, $m ) && false !== strpos( $sql, "= 'estimate'" ) ) {
			foreach ( $this->refs as $r ) {
				if ( $r[0] === $m[1] ) {
					$out[] = array( 'work_item_id' => $r[0], 'system' => 'estimate', 'entity' => 'document', 'external_id' => (string) $r[1], 'ref_seq' => $r[2] );
				}
			}
		}
		return $out;
	}
	public function get_row( $sql, $o = null ) {
		$this->queries[] = $sql;
		if ( preg_match( '/FROM wp_zest_estimates WHERE id = (\d+)/', $sql, $m ) ) {
			return $this->rows[ (int) $m[1] ] ?? null;
		}
		return null;
	}
	public function get_var( $sql ) { return null; }
	public function get_col( $sql ) { return array(); }
}

final class JobsEstimateNotesWholeTest extends TestCase {

	const PID  = '01HJOBSNOTES00000000000001';
	const TAIL = 'The rest is on the estimate.]';
	const USD  = array( 'code' => 'USD', 'sign' => '$' );

	private $saved_wpdb = null;

	protected function setUp(): void {
		$this->saved_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']  = new ZJOB_Test_Estimate_Notes_DB();
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_filters'] );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->saved_wpdb;
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_filters'] );
	}

	/** A dictation of at least $chars characters, one sentence per opening. */
	private function dictation( int $chars ): string {
		$out = '';
		for ( $i = 1; mb_strlen( $out ) < $chars; $i++ ) {
			$out .= sprintf( 'Opening %d is 12 by 9 on the north wall, cut to fit, painted white. ', $i );
		}
		return $out;
	}

	private function max(): int {
		return Zjob_Project_Resolver::SAID_MAX;
	}

	/** $s starts with $with. */
	private function starts( string $with, string $s, string $why = '' ): void {
		$this->assertTrue( 0 === strncmp( $s, $with, strlen( $with ) ), '' !== $why ? $why : 'it starts with ' . substr( $with, 0, 60 ) );
	}

	/** $s ends with $with. */
	private function ends( string $with, string $s, string $why = '' ): void {
		$this->assertSame( $with, (string) substr( $s, -strlen( $with ) ), $why );
	}

	/** A search found what it looked for. */
	private function found( $at, string $why = '' ): void {
		$this->assertTrue( false !== $at, $why );
	}

	/* ── The bound ────────────────────────────────────────────────────────── */

	public function test_the_bound_is_sixty_thousand_characters_and_ends_in_a_plain_sentence(): void {
		$this->assertSame( 60000, $this->max() );
		$this->assertSame( "\n\n[This note is longer than Projects shows. The rest is on the estimate.]", Zjob_Project_Resolver::said_more() );
	}

	public function test_a_short_note_is_untouched(): void {
		foreach ( array( null, false ) as $mb ) {
			$this->assertSame( 'Short note.', Zjob_Project_Resolver::clip( 'Short note.', $this->max(), $mb ) );
			$this->assertSame( '', Zjob_Project_Resolver::clip( '', $this->max(), $mb ) );
		}
	}

	public function test_a_long_dictation_arrives_whole_to_its_last_word(): void {
		$long = rtrim( $this->dictation( 9000 ) ) . ' LAST-WORD';
		$this->assertGreaterThan( 9000, mb_strlen( $long ) );
		foreach ( array( null, false ) as $mb ) {
			$out = Zjob_Project_Resolver::clip( $long, $this->max(), $mb );
			$this->assertSame( $long, $out, 'nine thousand characters, every one of them' );
			$this->ends( 'LAST-WORD', $out );
			$this->assertStringNotContainsString( "\u{2026}", $out, 'no ellipsis' );
		}
	}

	public function test_it_is_counted_in_characters_never_in_bytes(): void {
		$curly = str_repeat( "The 14\u{201D} opening on the \u{201C}north\u{201D} wall \u{2014} cut to fit. ", 1150 );
		$this->assertLessThan( 60000, mb_strlen( $curly ), 'fixture: under the bound in characters' );
		$this->assertGreaterThan( 60000, strlen( $curly ), 'fixture: over it in bytes' );
		foreach ( array( null, false ) as $mb ) {
			$out = Zjob_Project_Resolver::clip( $curly, $this->max(), $mb );
			$this->assertSame( $curly, $out, 'curly quotes and dashes do not get it cut' );
			$this->assertTrue( mb_check_encoding( $out, 'UTF-8' ) );
			$this->found( json_encode( array( 'said' => $out ) ), 'and the reply can still be sent as JSON' );
		}
		// Exactly at the bound: still whole. One over: cut.
		$exact = str_repeat( "\u{00E9}", 60000 );
		$this->assertSame( $exact, Zjob_Project_Resolver::clip( $exact, $this->max() ) );
		$this->assertSame( $exact, Zjob_Project_Resolver::clip( $exact, $this->max(), false ) );
		$this->assertNotSame( $exact . 'x', Zjob_Project_Resolver::clip( $exact . 'x', $this->max() ) );
	}

	public function test_a_paste_over_the_bound_is_held_to_it_and_says_where_the_rest_is(): void {
		$huge = $this->dictation( 70000 );
		$outs = array();
		foreach ( array( null, false ) as $mb ) {
			$out    = Zjob_Project_Resolver::clip( $huge, $this->max(), $mb );
			$outs[] = $out;
			$this->assertGreaterThanOrEqual( 59900, mb_strlen( $out ) );
			$this->assertLessThan( 60200, mb_strlen( $out ), 'held to the bound' );
			$this->ends( self::TAIL, $out, 'it says where the rest is' );
			$this->assertStringContainsString( "\u{2026}\n\n[This note is longer than Projects shows.", $out );
			$this->starts( mb_substr( $huge, 0, 59000 ), $out, 'what is kept is the start, unchanged' );
			$this->assertStringNotContainsString( " \u{2026}", $out, 'no space left hanging before the ellipsis' );
		}
		$this->assertSame( $outs[0], $outs[1], 'the same answer with or without the multibyte functions' );
	}

	public function test_a_cut_never_lands_inside_a_character(): void {
		$texts = array(
			'four bytes each' => str_repeat( "\u{1F525}", 60001 ),
			'three bytes each' => str_repeat( "\u{20AC}", 60010 ),
			'two bytes each' => str_repeat( "\u{00E9}", 61000 ),
			'a mix'          => str_repeat( "a\u{00E9}\u{20AC}\u{1F525}", 15001 ),
		);
		foreach ( $texts as $what => $text ) {
			foreach ( array( null, false ) as $mb ) {
				$out = Zjob_Project_Resolver::clip( $text, $this->max(), $mb );
				$this->assertTrue( mb_check_encoding( $out, 'UTF-8' ), $what . ': still text' );
				$this->found( json_encode( array( 'said' => $out ) ), $what . ': still goes out as JSON' );
				$this->ends( self::TAIL, $out, $what );
				$this->assertSame( mb_substr( $text, 0, 60000 ), mb_substr( $out, 0, 60000 ), $what . ': exactly the first sixty thousand characters' );
				$this->assertSame( 60000 + mb_strlen( "\u{2026}" . Zjob_Project_Resolver::said_more() ), mb_strlen( $out ), $what );
			}
		}
	}

	public function test_text_that_is_not_utf8_is_cut_plainly_and_without_an_error(): void {
		$bad = str_repeat( "ab\xFFcd ", 12001 ); // 72,006 bytes, none of it valid
		foreach ( array( null, false ) as $mb ) {
			$out = Zjob_Project_Resolver::clip( $bad, $this->max(), $mb );
			$this->ends( self::TAIL, $out );
			$this->assertLessThan( 60200, strlen( $out ) );
			$this->assertSame( 1, preg_match( '/^(?:ab.cd ){500}/s', $out ), 'what could be read is kept in place' );
			$this->assertSame( 1, preg_match( '/^(?:ab.cd )+ab/s', substr( $out, 59400, 300 ) ), 'right up to the bound' );
		}
		// Without the multibyte functions the cut is on a byte, since no character can be told from another.
		$this->starts( "ab\xFFcd ab\xFFcd ", Zjob_Project_Resolver::clip( $bad, $this->max(), false ) );
		$short = "caf\xE9 on the left";
		$this->assertSame( $short, Zjob_Project_Resolver::clip( $short, $this->max() ), 'under the bound it is left as it is' );
		$this->assertSame( $short, Zjob_Project_Resolver::clip( $short, $this->max(), false ) );
	}

	public function test_small_bounds_and_the_space_before_the_cut(): void {
		$more = "\u{2026}" . Zjob_Project_Resolver::said_more();
		foreach ( array( null, false ) as $mb ) {
			$this->assertSame( 'abc' . $more, Zjob_Project_Resolver::clip( 'abcdef', 3, $mb ) );
			$this->assertSame( 'ab' . $more, Zjob_Project_Resolver::clip( 'ab   cdef', 5, $mb ), 'trailing space is dropped before the ellipsis' );
			$this->assertSame( array( 'abcdef', false ), Zjob_Project_Resolver::first_chars( 'abcdef', 6, $mb ) );
			$this->assertSame( array( '', true ), Zjob_Project_Resolver::first_chars( 'abcdef', 0, $mb ) );
			$this->assertSame( array( '', true ), Zjob_Project_Resolver::first_chars( 'abcdef', -5, $mb ) );
		}
	}

	public function test_without_the_multibyte_functions_a_long_run_is_taken_in_steps(): void {
		$text = str_repeat( "\u{00E9}", 150000 );
		list( $head, $cut ) = Zjob_Project_Resolver::first_chars( $text, 130000, false );
		$this->assertTrue( $cut );
		$this->assertSame( 130000, mb_strlen( $head ) );
		$this->assertSame( array( $text, false ), Zjob_Project_Resolver::first_chars( $text, 150000, false ) );
		$this->assertSame( array( $text, false ), Zjob_Project_Resolver::first_chars( $text, 200000, false ) );
		$this->assertSame( Zjob_Project_Resolver::first_chars( $text, 130000 ), Zjob_Project_Resolver::first_chars( $text, 130000, false ) );
	}

	/* ── No amounts ───────────────────────────────────────────────────────── */

	public function amounts(): array {
		return array(
			array( 'Panel 12x24 $1,111.00', 'Panel 12x24' ),
			array( 'Kit 12x12 x6 @ $37 each', 'Kit 12x12 x6' ),
			array( 'Total = $2,222.00. Left side', 'Left side' ),
			array( '1111.00 USD deposit', 'deposit' ),
			array( 'Tax: $120.50', '' ),
			array( 'Total 2,222', '' ),
			array( 'Cost: 1111', '' ),
			array( 'Price 1111.00', '' ),
			array( 'Labor 222.00', '' ),
			array( 'Labour 222.00', '' ),
			array( 'Qty 2 @ 333', 'Qty 2' ),
			array( 'Deposit 50% = 444', '' ),
			array( 'USD 500', '' ),
			array( "\u{20AC}500", '' ),
			array( "\u{00A3}300", '' ),
			array( "1.250,00 \u{20AC}", '' ),
			array( '$-125', '' ),
			array( 'US$500', '' ),
			array( '$1.2k', '' ),
			array( '1111/ea', '' ),
			array( 'Quoted at $1,111', '' ),
			array( 'Standard height 90" - $1,111.00', 'Standard height 90"' ),
			array( 'Is the standard height 80" or 90"? Quoted at $1,111.', 'Is the standard height 80" or 90"?' ),
			array( 'paid 500 AUD, 20 CHF', '' ),
			array( 'Two openings on the back wall. Quoted at $1,111.00 for both. Then the rest.', 'Two openings on the back wall. for both. Then the rest.' ),
			array( 'Materials $1,111.00 and labor 222.00, total 2,222. Back wall first.', 'Materials and. Back wall first.' ),
		);
	}

	/**
	 * @dataProvider amounts
	 */
	public function test_every_amount_is_taken_out( string $in, string $want ): void {
		$out = Zjob_Project_Resolver::without_amounts( $in, self::USD );
		$this->assertSame( $want, $out );
		$this->assertStringNotContainsString( '$', $out );
		$this->assertDoesNotMatchRegularExpression( '/1,?111|2,?222|222\.00|\b333\b|\b444\b|\b500\b/', $out );
	}

	public function what_stays(): array {
		return array(
			array( 'Trim 2" brown, opening 40" wide' ),
			array( '6 kits 12x12, 2 pieces @ 90" tall' ),
			array( 'Total 3 pieces' ),
			array( 'Total 250 pieces' ),
			array( 'Total 120 units' ),
			array( 'Total 120 inches of trim' ),
			array( 'Quote 1001 for the Example job' ),
			array( 'Invoice 1002 sent' ),
			array( 'width 30 1/2 x 90' ),
			array( 'Price: for the pair' ),
			array( 'rate 45' ),
			array( 'due 2026' ),
			array( '5 try again' ),
			array( 'Side gate is on the left.' ),
		);
	}

	/**
	 * @dataProvider what_stays
	 */
	public function test_measurements_counts_and_document_numbers_stay( string $in ): void {
		$this->assertSame( $in, Zjob_Project_Resolver::without_amounts( $in, self::USD ) );
	}

	public function test_line_breaks_are_kept(): void {
		$in  = "Line one costs \$40.\nLine two: 12 pieces\n\nLine four total 2,222\n  Indented: back wall first";
		$out = Zjob_Project_Resolver::without_amounts( $in, self::USD );
		$this->assertSame( "Line one.\nLine two: 12 pieces\n\nLine four\n Indented: back wall first", $out );
		$this->assertSame( '', Zjob_Project_Resolver::without_amounts( '', self::USD ) );
	}

	public function test_a_word_is_never_eaten_with_its_amount(): void {
		$this->assertSame( 'plus for the bonus part', Zjob_Project_Resolver::without_amounts( 'plus $50 for the bonus $5 part', self::USD ) );
		$this->assertSame( 'minus on the status line', Zjob_Project_Resolver::without_amounts( 'minus $5 on the status $9 line', self::USD ) );
		$this->assertSame( 'and and', Zjob_Project_Resolver::without_amounts( 'A$500 and NZ$20 and R$7', self::USD ), 'letters joined to the sign go with it' );
	}

	public function test_the_business_own_currency_is_money_too(): void {
		$sek = array( 'code' => 'SEK', 'sign' => 'kr' );
		$this->assertSame( 'Totalt', Zjob_Project_Resolver::without_amounts( 'Totalt 1 250 kr', $sek ) );
		$this->assertSame( 'och.', Zjob_Project_Resolver::without_amounts( 'kr 500 och 20 kr.', $sek ), 'before the number or after it' );
		$this->assertSame( 'krona 500', Zjob_Project_Resolver::without_amounts( 'krona 500', $sek ), 'only as a whole word' );
		$this->assertSame( 'kr 500', Zjob_Project_Resolver::without_amounts( 'kr 500', self::USD ), 'and only for a business that uses it' );
		// A sign and a code Core does not list.
		$own = array( 'code' => 'TRY', 'sign' => "\u{20BA}" );
		$this->assertSame( 'and', Zjob_Project_Resolver::without_amounts( "\u{20BA}500 and 20\u{20BA}", $own ) );
		$this->assertSame( 'and 5 try again', Zjob_Project_Resolver::without_amounts( '500 TRY and 5 try again', $own ), 'the code as written; an ordinary word that spells it is left alone' );
		$this->assertSame( "\u{20BA}500", Zjob_Project_Resolver::without_amounts( "\u{20BA}500", self::USD ) );
		// A sign that is not one: digits, spaces or too long are ignored rather than turned into a pattern.
		foreach ( array( '12', 'a b', 'abcdefgh', '' ) as $junk ) {
			$this->assertSame( 'Panel 12x24', Zjob_Project_Resolver::without_amounts( 'Panel 12x24 $1,111.00', array( 'code' => 'x', 'sign' => $junk ) ) );
		}
		$this->assertSame( 'Panel', Zjob_Project_Resolver::without_amounts( 'Panel $5 (.*)9', array( 'code' => 'USD', 'sign' => '(.*)' ) ), 'a sign is taken as written, never as a pattern' );
	}

	public function test_a_business_names_what_it_counts(): void {
		$this->assertSame( 'tiles', Zjob_Project_Resolver::without_amounts( 'Total 250 tiles', self::USD ), 'a bare number after a money word is money until the business says it is a count' );
		$GLOBALS['zdz_test_options']['zjob_amount_count_words'] = array( 'tile', 'Pallet!', 9, 'x' );
		$this->assertSame( 'Total 250 tiles', Zjob_Project_Resolver::without_amounts( 'Total 250 tiles', self::USD ) );
		$this->assertSame( 'Total 120 pallets', Zjob_Project_Resolver::without_amounts( 'Total 120 pallets', self::USD ) );
		$this->assertSame( 'Total 250 pieces', Zjob_Project_Resolver::without_amounts( 'Total 250 pieces', self::USD ), 'Core\'s own stay' );
		unset( $GLOBALS['zdz_test_options'] );
		$GLOBALS['zdz_test_filters']['zjob_amount_count_words'] = function ( $words ) {
			return array_merge( $words, array( 'tree' ) );
		};
		$this->assertSame( 'Total 300 trees', Zjob_Project_Resolver::without_amounts( 'Total 300 trees', self::USD ) );
		// It never keeps a number that carries a currency mark.
		$GLOBALS['zdz_test_filters']['zjob_amount_count_words'] = function ( $words ) {
			return array( 'dollar', 'tree' );
		};
		$this->assertSame( '', Zjob_Project_Resolver::without_amounts( 'Total 500 dollars', self::USD ) );
		$this->assertSame( 'trees', Zjob_Project_Resolver::without_amounts( 'Total $300 trees', self::USD ) );
		$GLOBALS['zdz_test_filters']['zjob_amount_count_words'] = function ( $words ) {
			return 'not a list';
		};
		$this->assertSame( 'Total 250 pieces', Zjob_Project_Resolver::without_amounts( 'Total 250 pieces', self::USD ), 'a filter answer that is not a list is ignored' );
	}

	public function test_text_the_amounts_cannot_be_taken_out_of_comes_back_empty(): void {
		$this->assertSame( '', Zjob_Project_Resolver::without_amounts( "Total \$500 caf\xE9", self::USD ), 'nothing, rather than a text with its amounts still in it' );
	}

	/* ── What an opened Project carries ───────────────────────────────────── */

	public function test_each_estimates_words_are_shaped_whole_and_without_amounts(): void {
		$long = rtrim( $this->dictation( 9000 ) ) . ' LAST-WORD';
		$d    = Zjob_Project_Resolver::said_from_rows( array(
			array( 'doc_number' => ' 1042<b> ', 'notes' => '  Side gate is on the left. Total: $4,980.00  ', 'input_text' => 'Two openings on the back wall. Quoted at $1,111.00 for both. ' . $long ),
			array( 'doc_number' => '1043', 'notes' => '', 'input_text' => "   \n " ),
			'not a row',
			array( 'doc_number' => 'E-7', 'billing_doc_num' => '900', 'notes' => null, 'input_text' => 'Short note.' ),
			array( 'doc_number' => '  ', 'billing_doc_num' => ' B-7702 ', 'notes' => 'Numbered by the billing system.', 'input_text' => '' ),
			array( 'notes' => 'No number at all.', 'input_text' => '' ),
		), 2 );
		$this->assertSame( 2, $d['more'] );
		$this->assertCount( 4, $d['estimates'], 'an estimate with no words is left out' );
		$first = $d['estimates'][0];
		$this->assertSame( array( 'estimate_num', 'notes', 'said' ), array_keys( $first ) );
		$this->assertSame( '1042b', $first['estimate_num'], 'letters, digits and dashes only' );
		$this->assertSame( 'Side gate is on the left.', $first['notes'] );
		$this->starts( 'Two openings on the back wall.', $first['said'] );
		$this->ends( 'LAST-WORD', $first['said'], 'whole, to its last word' );
		$this->assertStringNotContainsString( '1,111', $first['said'], 'money is still taken out of it' );
		$this->assertStringNotContainsString( '$', (string) json_encode( $d ) );
		$this->assertSame( array( 'estimate_num' => 'E-7', 'notes' => '', 'said' => 'Short note.' ), $d['estimates'][1], 'the estimate\'s own number comes first' );
		$this->assertSame( 'B-7702', $d['estimates'][2]['estimate_num'], 'with none of its own, the number the billing system gave it' );
		$this->assertSame( '', $d['estimates'][3]['estimate_num'], 'and an estimate with no number still shows its words' );
		$this->assertSame( 'No number at all.', $d['estimates'][3]['notes'] );
		$this->assertSame( array( 'estimates' => array(), 'more' => 0 ), Zjob_Project_Resolver::said_from_rows( array(), -3 ) );
	}

	public function test_a_paste_is_held_to_the_bound_after_its_amounts_are_out(): void {
		$paste = str_repeat( 'Side gate on the left, 12 pieces at $40.00 each. ', 7000 ); // about 340,000 characters
		$this->assertGreaterThan( 300000, mb_strlen( $paste ) );
		$t0   = microtime( true );
		$d    = Zjob_Project_Resolver::said_from_rows( array( array( 'doc_number' => '1042', 'notes' => $paste, 'input_text' => $this->dictation( 70000 ) ) ) );
		$took = microtime( true ) - $t0;
		foreach ( array( 'notes', 'said' ) as $k ) {
			$out = $d['estimates'][0][ $k ];
			$this->assertLessThan( 60200, mb_strlen( $out ), $k . ': held to the bound' );
			$this->assertGreaterThanOrEqual( 59900, mb_strlen( $out ), $k );
			$this->ends( self::TAIL, $out, $k . ': and it says where the rest is' );
			$this->assertStringNotContainsString( '$', $out );
		}
		$this->starts( 'Side gate on the left, 12 pieces at each. Side gate', $d['estimates'][0]['notes'] );
		$this->assertLessThan( 5.0, $took, 'a pasted document is not read to its end' );
	}

	/** @return array<string,array{0:string,1:string}> */
	public function not_quite_text(): array {
		return array(
			'a letter from an older encoding'    => array( "caf\xE9 on the left", 'caf on the left' ),
			'one stray byte and nothing else'    => array( "\xFF", '' ),
			'the start of a character, no end'   => array( "a\xC3", 'a' ),
			'a real two-byte character'          => array( "a\xC3\xA9b", "a\u{00E9}b" ),
			'a letter written the long way'      => array( "\xC0\xAFx", 'x' ),
			'half of a pair'                     => array( "\xED\xA0\x80x", 'x' ),
			'past the last character there is'   => array( "\xF4\x90\x80\x80x", 'x' ),
			'a character cut short at the end'   => array( "\u{1F525} ok \xF0\x9F\x94", "\u{1F525} ok " ),
			'stray bytes around plain letters'   => array( "\x80\x80\x80abc\x80", 'abc' ),
			'line breaks and tabs are text'      => array( "line one\nline\xA0two\r\n\ttab", "line one\nlinetwo\r\n\ttab" ),
			'text is returned as it is'          => array( "\u{201C}6\u{2033}\u{201D} \u{2014} \u{1F6E0}", "\u{201C}6\u{2033}\u{201D} \u{2014} \u{1F6E0}" ),
			'nothing'                            => array( '', '' ),
		);
	}

	/** @dataProvider not_quite_text */
	public function test_only_the_bytes_that_are_text_are_kept( string $in, string $want ): void {
		$out = Zjob_Project_Resolver::text_only( $in );
		$this->assertSame( $want, $out );
		$this->assertSame( 1, preg_match( '//u', $out ), 'what is left is text' );
	}

	public function test_a_very_long_text_with_a_stray_byte_is_still_read(): void {
		$unit = "a\u{00E9}\u{20AC}\u{1F525} wall ";
		$long = str_repeat( $unit, 40000 ); // 640,000 bytes
		$this->assertSame( $long, Zjob_Project_Resolver::text_only( $long . "\xFF" ) );
		$this->assertSame( str_repeat( 'a', 500000 ), Zjob_Project_Resolver::text_only( str_repeat( "a\xFF", 500000 ) ) );
		$this->assertSame( '', Zjob_Project_Resolver::text_only( str_repeat( "\xFF", 500000 ) ) );
	}

	public function test_a_note_with_a_stray_byte_is_shown_with_the_rest_of_its_words(): void {
		$d = Zjob_Project_Resolver::said_from_rows( array( array( 'doc_number' => '1042', 'notes' => "Caf\xE9 side first.\nTotal \$500. Back wall 48 in.", 'input_text' => "\xFF\xFE" ) ) );
		$this->assertSame( "Caf side first.\nBack wall 48 in.", $d['estimates'][0]['notes'] );
		$this->assertSame( '', $d['estimates'][0]['said'], 'nothing but stray bytes is nothing written' );
	}

	public function test_codes_come_out_before_amounts_and_the_bound_comes_last(): void {
		$src  = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/includes/class-zjob-project-resolver.php' );
		$body = substr( $src, (int) strpos( $src, 'private static function said_text(' ) );
		$body = substr( $body, 0, (int) strpos( $body, 'public static function without_edge(' ) );
		$code = strpos( $body, '$text = (string) ZJOB_Sealed::cut( $text );' );
		$cash = strpos( $body, '$text = self::without_amounts( $text );' );
		$last = strpos( $body, 'list( $head, $cut ) = self::first_chars( $text, self::SAID_MAX );' );
		$this->found( $code );
		$this->found( $cash );
		$this->found( $last );
		$this->assertLessThan( $cash, $code, 'an access code is cut first' );
		$this->assertLessThan( $last, $cash, 'and the bound comes after both, so it can never leave half of a code or of an amount' );
	}

	public function test_a_text_many_times_the_bound_is_not_read_to_its_end_and_says_so(): void {
		// 204,002 characters, most of them amounts: what is left of the part that is read is far under the bound.
		$sheet = "p\n" . str_repeat( "x 1234567.00 USD\n", 12000 );
		$d     = Zjob_Project_Resolver::said_from_rows( array( array( 'doc_number' => '1042', 'notes' => $sheet, 'input_text' => '' ) ) );
		$out   = $d['estimates'][0]['notes'];
		$this->starts( "p\nx\nx\nx\n", $out );
		$this->ends( self::TAIL, $out, 'it was not read to its end, so it says where the rest is' );
		$this->assertLessThan( 20000, mb_strlen( $out ) );
		$this->assertDoesNotMatchRegularExpression( '/\d|USD/', $out, 'the amount the reading stopped inside is not left showing' );
	}

	public function test_a_long_dictation_with_no_amounts_is_held_to_the_bound_as_before(): void {
		$long = $this->dictation( 200000 );
		$d    = Zjob_Project_Resolver::said_from_rows( array( array( 'doc_number' => '1042', 'notes' => '', 'input_text' => $long ) ) );
		$this->assertSame( Zjob_Project_Resolver::clip( $long, $this->max() ), $d['estimates'][0]['said'] );
	}

	public function test_the_end_of_a_text_is_left_off_on_a_whole_character(): void {
		$this->assertSame( 'abc', Zjob_Project_Resolver::without_edge( 'abcdef', 3 ) );
		$this->assertSame( 'abcdef', Zjob_Project_Resolver::without_edge( 'abcdef', 0 ) );
		$this->assertSame( '', Zjob_Project_Resolver::without_edge( 'abcdef', 6 ) );
		$this->assertSame( '', Zjob_Project_Resolver::without_edge( 'abcdef', 60 ) );
		$this->assertSame( '', Zjob_Project_Resolver::without_edge( '', 4 ) );
		$fire = "\u{1F525}";
		foreach ( array( 1, 2, 3, 4 ) as $bytes ) {
			$this->assertSame( 'ab' . $fire, Zjob_Project_Resolver::without_edge( 'ab' . $fire . $fire, $bytes ), $bytes . ' bytes back lands inside the last character: all of it goes' );
		}
		$this->assertSame( 'ab', Zjob_Project_Resolver::without_edge( 'ab' . $fire . $fire, 5 ) );
		$text = str_repeat( "a\u{00E9}\u{20AC}" . $fire, 300 );
		for ( $bytes = 390; $bytes <= 410; $bytes++ ) {
			$out = Zjob_Project_Resolver::without_edge( $text, $bytes );
			$this->assertSame( 1, preg_match( '//u', $out ) );
			$this->assertSame( 0, strpos( $text, $out ) );
			$this->assertGreaterThanOrEqual( $bytes, strlen( $text ) - strlen( $out ) );
			$this->assertLessThan( $bytes + 4, strlen( $text ) - strlen( $out ) );
		}
	}

	/* ── Read from the estimate app ───────────────────────────────────────── */

	private function project(): array {
		return array( 'id' => self::PID, 'human_code' => 'PRJ-0001', 'state' => 'open' );
	}

	public function test_the_projects_estimates_are_read_in_the_order_it_recorded_them(): void {
		$db       = $GLOBALS['wpdb'];
		$db->refs = array( array( self::PID, 42, 2 ), array( self::PID, 40, 1 ), array( '01HJOBSNOTESOTHER000000002', 77, 3 ) );
		$db->rows = array(
			40 => array( 'doc_number' => '1040', 'notes' => 'First estimate.', 'input_text' => 'Back wall, two openings.' ),
			42 => array( 'doc_number' => '1042', 'notes' => 'Second estimate.', 'input_text' => '' ),
			77 => array( 'doc_number' => '1077', 'notes' => 'Another project.', 'input_text' => '' ),
		);
		$r = Zjob_Project_Resolver::src_said( 5, $this->project(), false );
		$this->assertSame( Zjob_Project_Resolver::STATE_OK, $r['state'] );
		$this->assertSame( array( '1040', '1042' ), array_column( $r['data']['estimates'], 'estimate_num' ), 'the first recorded first; never another Project\'s' );
		$this->assertSame( 'Back wall, two openings.', $r['data']['estimates'][0]['said'] );
		$this->assertSame( 0, $r['data']['more'] );
	}

	public function test_a_few_estimates_are_read_and_the_rest_are_counted(): void {
		$db = $GLOBALS['wpdb'];
		for ( $i = 1; $i <= 7; $i++ ) {
			$db->refs[]        = array( self::PID, 100 + $i, $i );
			$db->rows[ 100 + $i ] = array( 'doc_number' => (string) ( 2000 + $i ), 'notes' => 'Estimate ' . $i . '.', 'input_text' => '' );
		}
		$r = Zjob_Project_Resolver::src_said( 5, $this->project(), false );
		$this->assertSame( Zjob_Project_Resolver::SAID_ESTIMATES, count( $r['data']['estimates'] ) );
		$this->assertSame( array( '2001', '2002', '2003', '2004' ), array_column( $r['data']['estimates'], 'estimate_num' ) );
		$this->assertSame( 3, $r['data']['more'], 'said, never dropped without a word' );
		$reads = array_filter( $db->queries, function ( $q ) {
			return false !== strpos( $q, 'wp_zest_estimates' );
		} );
		$this->assertCount( Zjob_Project_Resolver::SAID_ESTIMATES, $reads, 'one read per estimate shown, none for the rest' );
		foreach ( $reads as $q ) {
			$this->assertStringContainsString( 'SELECT doc_number, billing_doc_num, notes, input_text FROM', $q, 'the words and the document\'s numbers: never an amount column' );
		}
	}

	public function test_nothing_written_is_a_fact_and_is_not_drawn(): void {
		$db = $GLOBALS['wpdb'];
		$this->assertSame( Zjob_Project_Resolver::STATE_EMPTY, Zjob_Project_Resolver::src_said( 5, $this->project(), false )['state'], 'no estimate on the Project' );
		$db->refs = array( array( self::PID, 40, 1 ) );
		$db->rows = array( 40 => array( 'doc_number' => '1040', 'notes' => '', 'input_text' => '' ) );
		$r        = Zjob_Project_Resolver::src_said( 5, $this->project(), false );
		$this->assertSame( Zjob_Project_Resolver::STATE_EMPTY, $r['state'], 'an estimate with no words' );
		$this->assertNull( $r['data'] );
		$db->rows = array();
		$this->assertSame( Zjob_Project_Resolver::STATE_EMPTY, Zjob_Project_Resolver::src_said( 5, $this->project(), false )['state'], 'an estimate that is gone' );
		$this->assertSame( Zjob_Project_Resolver::STATE_EMPTY, Zjob_Project_Resolver::src_said( 5, array(), false )['state'] );
	}

	public function test_the_words_are_a_work_class_source_and_the_opened_project_carries_them(): void {
		$m = new ReflectionMethod( 'Zjob_Project_Resolver', 'sources' );
		$m->setAccessible( true );
		$by = array();
		foreach ( $m->invoke( null, 5, $this->project() ) as $s ) {
			$by[ $s['id'] ] = $s;
		}
		$this->assertArrayHasKey( 'said', $by );
		$this->assertSame( Zjob_Project_Resolver::CLASS_WORK, $by['said']['class'], 'read by whoever may open the Project: so it carries no amount' );
		$this->assertSame( array( 'Zjob_Project_Resolver', 'src_said' ), $by['said']['gather'] );
		$ajax = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/includes/class-zjob-projects-ajax.php' );
		$this->assertStringContainsString( "'said'    => self::said( \$p ),", $ajax );
		$reply = substr( $ajax, (int) strpos( $ajax, 'public static function detail_reply(' ) );
		$this->assertLessThan( strpos( $reply, "'said'    => self::said( \$p )," ), strpos( $reply, 'Zjob_Project_Visibility::may_view_project( $uid, $p )' ), 'only after the Project passed the read gate' );
		$this->assertLessThan( strpos( $reply, 'ZJOB_Sealed::deep( $out )' ), strpos( $reply, "'said'    => self::said( \$p )," ), 'and before the reply\'s access codes are cut' );
	}

	/* ── The screen ───────────────────────────────────────────────────────── */

	private static function fn( string $name ): string {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/assets/js/projects.js' );
		$at  = strpos( $src, 'function ' . $name . '(' );
		if ( false === $at ) {
			return '';
		}
		$depth = 0;
		$len   = strlen( $src );
		for ( $i = (int) strpos( $src, '{', $at ); $i < $len; $i++ ) {
			if ( '{' === $src[ $i ] ) {
				$depth++;
			} elseif ( '}' === $src[ $i ] ) {
				$depth--;
				if ( 0 === $depth ) {
					return substr( $src, $at, $i - $at + 1 );
				}
			}
		}
		return '';
	}

	private function run_js( string $body ) {
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) ); // sentinel:allow fixed command, no input
		if ( '' === $node ) {
			$this->markTestSkipped( 'node is not installed here' );
		}
		$helpers = '';
		foreach ( array( 'esc', 'word', 'saidHtml' ) as $name ) {
			$one = self::fn( $name );
			$this->assertNotSame( '', $one, $name . '() is in the page script' );
			$helpers .= $one . "\n";
		}
		$code = "var CFG = {};\nfunction cfg() { return CFG; }\n" . $helpers . 'console.log(JSON.stringify((function(){' . $body . '})()));';
		$out  = shell_exec( escapeshellarg( $node ) . ' -e ' . escapeshellarg( $code ) . ' 2>&1' );
		$res  = json_decode( (string) $out, true );
		$this->assertNotNull( $res, 'node ran the helpers: ' . substr( (string) $out, 0, 400 ) );
		return $res;
	}

	public function test_the_script_draws_the_words_whole_and_escaped(): void {
		$r = $this->run_js( "var long = new Array(251).join('Opening <b>one</b> is 12 by 9 & cut to fit. ') + 'LAST-WORD'; var html = saidHtml({ more: 0, estimates: [{ estimate_num: '1042', notes: 'Side gate is on the left.', said: long }] });"
			. " return { len: long.length, whole: html.indexOf('LAST-WORD</p>') !== -1, raw: html.indexOf('<b>') !== -1, esc: html.indexOf('Opening &lt;b&gt;one&lt;/b&gt; is 12 by 9 &amp; cut to fit.') !== -1, notes: html.indexOf('<h5 class=\"zprj-said-h\">Estimate notes</h5><p>Side gate is on the left.</p>') !== -1,"
			. " said: html.indexOf('<h5 class=\"zprj-said-h\">What the salesperson wrote or said</h5><p>Opening') !== -1, title: html.indexOf('<h4>From the estimate</h4>') !== -1, num: html.indexOf('1042') !== -1, amounts: html.indexOf('Amounts are left out here.') !== -1, cut: /\\u2026|\\.\\.\\./.test(html) };" );
		$this->assertGreaterThan( 8000, $r['len'] );
		$this->assertTrue( $r['whole'], 'to its last word' );
		$this->assertFalse( $r['cut'], 'the script adds no ellipsis of its own' );
		$this->assertFalse( $r['raw'] );
		$this->assertTrue( $r['esc'], 'what was typed is shown as typed, never run as markup' );
		$this->assertTrue( $r['notes'] );
		$this->assertTrue( $r['said'] );
		$this->assertTrue( $r['title'] );
		$this->assertFalse( $r['num'], 'one estimate needs no number beside its words' );
		$this->assertTrue( $r['amounts'], 'and it says why a sentence may read short' );
	}

	public function test_everything_the_server_sent_is_escaped_and_the_opened_project_draws_it(): void {
		$bad = '<img src=x onerror=alert(1)>';
		$r   = $this->run_js( "var bad = '" . $bad . "'; CFG = { words: { saidTitle: bad, estimate: bad, estimateNotes: bad, saidWords: bad, moreEstimates: bad, noAmounts: bad } };"
			. " return { html: saidHtml({ more: 2, estimates: [{ estimate_num: bad, notes: bad, said: bad }, { estimate_num: '7', notes: bad, said: '' }] }) };" );
		$this->assertStringNotContainsString( '<img', $r['html'], 'neither a note, a number nor a word of the screen is ever markup' );
		$this->assertSame( 12, substr_count( $r['html'], '&lt;img src=x onerror=alert(1)&gt;' ), 'each of the twelve is shown as text' );

		$detail = self::fn( 'renderDetail' );
		$draw   = strpos( $detail, 'html += saidHtml(d.said);' );
		$this->found( $draw, 'an opened Project draws what its estimates say' );
		$this->assertLessThan( strpos( $detail, 'html += ordersHtml(d.orders, ro, p.id);' ), $draw, 'above its material orders, where whoever orders reads it first' );
	}

	public function test_the_script_draws_nothing_when_there_is_nothing_and_names_each_estimate_when_there_are_several(): void {
		$r = $this->run_js( "return { none: saidHtml(null), empty: saidHtml({ estimates: [], more: 0 }), undef: saidHtml(undefined),"
			. " two: saidHtml({ more: 0, estimates: [{ estimate_num: '1040', notes: 'First.', said: '' }, { estimate_num: '1042', notes: '', said: 'Second.' }] }),"
			. " more: saidHtml({ more: 3, estimates: [{ estimate_num: '2001', notes: 'Only one read.', said: '' }] }) };" );
		$this->assertSame( '', $r['none'] );
		$this->assertSame( '', $r['empty'] );
		$this->assertSame( '', $r['undef'] );
		$this->assertStringContainsString( '<div class="zprj-meta">Estimate 1040</div>', $r['two'] );
		$this->assertStringContainsString( '<div class="zprj-meta">Estimate 1042</div>', $r['two'] );
		$this->assertSame( 1, substr_count( $r['two'], 'Estimate notes' ), 'a heading only where there are words under it' );
		$this->assertSame( 1, substr_count( $r['two'], 'What the salesperson wrote or said' ) );
		$this->assertLessThan( strpos( $r['two'], 'Second.' ), strpos( $r['two'], 'First.' ), 'in the order the server sent them' );
		$this->assertStringContainsString( 'Estimate 2001', $r['more'] );
		$this->assertStringContainsString( 'This project has more estimates. Their notes are on the estimates themselves.', $r['more'] );
		$this->assertStringNotContainsString( 'more estimates', $r['two'] );
	}

	public function test_the_words_come_in_the_persons_language_and_nothing_in_the_stylesheet_cuts_the_note(): void {
		$app = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/includes/class-zjob-projects-app.php' );
		foreach ( array( 'saidTitle' => 'From the estimate', 'estimate' => 'Estimate', 'estimateNotes' => 'Estimate notes', 'saidWords' => 'What the salesperson wrote or said', 'noAmounts' => 'Amounts are left out here.' ) as $key => $words ) {
			$this->assertMatchesRegularExpression( "/'" . $key . "'\\s*=> __\\( '" . preg_quote( $words, '/' ) . "', 'zorderz' \\),/", $app );
			$this->assertStringContainsString( "word('" . $key . "', '" . $words . "')", self::fn( 'saidHtml' ) );
		}
		$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/assets/css/projects.css' );
		$this->assertSame( 1, preg_match( '/\.zprj-said p\s*\{([^}]*)\}/', $css, $m ) );
		$this->assertStringContainsString( 'white-space: pre-wrap', $m[1], 'line breaks are kept' );
		$this->assertStringContainsString( 'overflow-wrap: anywhere', $m[1], 'a long run with no space in it wraps' );
		$this->assertStringContainsString( 'font-size: var(--zdz-type-meta, 14px)', $m[1] );
		foreach ( array( 'max-height', 'line-clamp', 'text-overflow', 'overflow:', 'overflow-y', 'height:' ) as $cutter ) {
			$this->assertStringNotContainsString( $cutter, str_replace( 'line-height:', '', $m[1] ), 'nothing cuts the note short' );
		}
		$this->assertSame( 0, preg_match( '/\.zprj-said[^{]*\{[^}]*(?:max-height|line-clamp|text-overflow)/', $css ) );
	}
}
