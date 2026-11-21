<?php
/**
 * Estimates follow-ups: the words.
 *
 *   - the answers, channels and outcomes are closed lists; "reached" and "handled" need an outcome, "tried" has none; a
 *     voicemail is a try and meeting in person is a reach;
 *   - the one line a follow-up may write on a billing estimate is built only from fixed phrases, a date and a short
 *     code: nothing typed can become one, and the writer's last check (line_ok) refuses anything else;
 *   - a business words the phrases in its document conventions; a phrase with a digit, "@" or "#" is not taken, and a
 *     line written with the default wording is still recognised afterwards;
 *   - the notes get the line at the bottom, replacing an earlier one of the app's own, never a person's words; taking
 *     a line back removes exactly that line and puts back the one it had replaced;
 *   - the switch: off, test customers only (the default), on;
 *   - an Identity Pack sets the switch and the phrases, refusing what it cannot read.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/estimate-followup-stubs.php';

final class EstimateFollowupWordsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['zdz_test_options'] = array();
		$GLOBALS['zdz_test_filters'] = array();
		zdz_test_estfu_flush();
	}

	protected function tearDown(): void {
		zdz_test_estfu_teardown();
	}

	private static function conventions( array $followup ): void {
		$GLOBALS['zdz_test_options']['zdz_document_conventions'] = array( 'estimate' => array( 'followup' => $followup ) );
		ZDZ_Doc_Conventions::flush();
		ZEST_Followup::reset();
	}

	/* ── closed lists ────────────────────────────────────────────────────── */

	public function test_every_outcome_has_a_label_and_a_fixed_phrase(): void {
		$labels = ZEST_Followup::outcome_labels();
		foreach ( array_keys( ZEST_Followup::OUTCOMES ) as $code ) {
			$this->assertArrayHasKey( $code, $labels );
			$this->assertArrayHasKey( $code, ZEST_Followup::WORDS );
			$this->assertTrue( ZEST_Followup::valid_phrase( ZEST_Followup::WORDS[ $code ] ), $code );
		}
		$this->assertSame( array( 'other_provider', 'not_needed' ), array_keys( array_filter( ZEST_Followup::OUTCOMES ) ), 'the two that move an estimate to Declined' );
		$this->assertTrue( ZEST_Followup::is_declined( 'other_provider' ) );
		$this->assertFalse( ZEST_Followup::is_declined( 'deciding' ) );
		$this->assertFalse( ZEST_Followup::is_declined( 'nothing' ) );
		$this->assertSame( '', ZEST_Followup::outcome_label( 'nothing' ) );
		$this->assertSame( ZEST_Followup::CHANNELS, array_keys( ZEST_Followup::channels() ) );
	}

	public function test_an_answer_needs_its_outcome_and_a_channel_that_fits(): void {
		$this->assertSame( '', ZEST_Followup::outcome_for( 'tried', 'deciding' ), 'a try has no outcome, whatever was sent' );
		$this->assertSame( 'deciding', ZEST_Followup::outcome_for( 'reached', 'deciding' ) );
		$this->assertSame( 'other', ZEST_Followup::outcome_for( 'handled', 'other' ) );
		$this->assertNull( ZEST_Followup::outcome_for( 'reached', '' ), 'reached needs one' );
		$this->assertNull( ZEST_Followup::outcome_for( 'reached', 'made_up' ) );
		$this->assertNull( ZEST_Followup::outcome_for( 'sold', 'deciding' ), 'an answer that is not one of the three' );

		$this->assertSame( 'call', ZEST_Followup::channel_for( 'reached', 'call' ) );
		$this->assertSame( 'voicemail', ZEST_Followup::channel_for( 'tried', 'voicemail' ) );
		$this->assertNull( ZEST_Followup::channel_for( 'reached', 'voicemail' ), 'a voicemail is a try, never a reach' );
		$this->assertNull( ZEST_Followup::channel_for( 'tried', 'in_person' ), 'meeting them is reaching them' );
		$this->assertSame( 'in_person', ZEST_Followup::channel_for( 'handled', 'in_person' ) );
		$this->assertNull( ZEST_Followup::channel_for( 'reached', 'fax' ) );
	}

	/* ── the line ────────────────────────────────────────────────────────── */

	public function test_the_line_is_fixed_words_a_date_and_a_code(): void {
		$this->assertSame( 'Follow-up 6/1/2026 (AB): Customer chose another provider.', ZEST_Followup::line( 'other_provider', 'ab', '6/1/2026' ) );
		$this->assertSame( 'Follow-up 12/31/2026 (A1): Estimate reopened.', ZEST_Followup::line( 'reopened', 'A1', '12/31/2026' ) );
		$this->assertSame( 'Follow-up 6/1/2026: Customer is still deciding.', ZEST_Followup::line( 'deciding', '', '6/1/2026' ), 'no code: left off' );
		$this->assertSame( 'Follow-up 6/1/2026: Customer is still deciding.', ZEST_Followup::line( 'deciding', 'Jordan Blake', '6/1/2026' ), 'a name is never used as the code' );
		$this->assertSame( '', ZEST_Followup::line( 'lead', 'AB', '6/1/2026' ), 'the lead is not a phrase' );
		$this->assertSame( '', ZEST_Followup::line( 'anything typed', 'AB', '6/1/2026' ) );
		$this->assertSame( '', ZEST_Followup::line( 'deciding', 'AB', 'June 1' ), 'a date that is not one' );
		$this->assertSame( '', ZEST_Followup::line( 'deciding', 'AB', "6/1/2026\n" ) );
	}

	public function test_the_last_check_refuses_everything_that_is_not_the_apps_own_line(): void {
		$this->assertTrue( ZEST_Followup::line_ok( 'Follow-up 6/1/2026 (AB): Customer will call back.' ) );
		$this->assertTrue( ZEST_Followup::line_ok( 'Follow-up 6/1/2026: Spoke with customer.' ) );
		foreach ( array(
			'Follow-up 6/1/2026 (AB): Customer said the price is too high.',   // typed words
			'Follow-up 6/1/2026 (AB): Customer will call back. Call 555-0100',   // a tail
			"Follow-up 6/1/2026 (AB): Customer will call back.\n",              // a second line
			'Follow-up 6/1/2026 (Jordan): Customer will call back.',            // a name where the code goes
			'Follow-up (AB): Customer will call back.',                         // no date
			'follow-up 6/1/2026 (AB): Customer will call back.',                // not the lead as written
			'Note 6/1/2026 (AB): Customer will call back.',
			'',
		) as $line ) {
			$this->assertFalse( ZEST_Followup::line_ok( $line ), $line );
		}
		$this->assertSame( 'Follow-up 6/1/2026: Customer will call back.', ZEST_Followup::line_without_code( 'Follow-up 6/1/2026 (AB): Customer will call back.' ) );
		$this->assertSame( '', ZEST_Followup::line_without_code( 'Follow-up 6/1/2026 (AB): something typed' ) );
	}

	public function test_a_business_words_the_phrases_and_a_bad_one_is_not_taken(): void {
		self::conventions( array(
			'lead'       => 'Checked in',
			'deciding'   => 'Still thinking it over.',
			'callback'   => 'Will ring 555 0100',       // a digit: refused, the default stays
			'changes'    => "Wants changes.\nSecond line", // two lines: refused
			'reopened'   => 'Back on the table. <b>',   // markup: refused
			'made_up'    => 'Not a key this app knows.',
		) );
		$w = ZEST_Followup::words();
		$this->assertSame( 'Checked in', $w['lead'] );
		$this->assertSame( 'Still thinking it over.', $w['deciding'] );
		$this->assertSame( ZEST_Followup::WORDS['callback'], $w['callback'] );
		$this->assertSame( ZEST_Followup::WORDS['changes'], $w['changes'] );
		$this->assertSame( ZEST_Followup::WORDS['reopened'], $w['reopened'] );
		$this->assertFalse( isset( $w['made_up'] ) );
		$this->assertSame( 'Checked in 6/1/2026 (AB): Still thinking it over.', ZEST_Followup::line( 'deciding', 'AB', '6/1/2026' ) );
		$this->assertTrue( ZEST_Followup::line_ok( 'Follow-up 5/1/2026 (AB): Customer is still deciding.' ), 'a line written before the wording changed is still the app\'s own' );
		$this->assertFalse( ZEST_Followup::line_ok( 'Checked in 6/1/2026 (AB): Customer said something else.' ) );

		self::conventions( array( 'lead' => 'Call 5550100' ) );
		$this->assertSame( 'Follow-up', ZEST_Followup::words()['lead'], 'a lead is letters, spaces and hyphens' );
		$this->assertFalse( ZEST_Followup::valid_lead( '' ) );
		$this->assertFalse( ZEST_Followup::valid_lead( str_repeat( 'a', 31 ) ) );
		$this->assertFalse( ZEST_Followup::valid_phrase( str_repeat( 'a', 121 ) ) );
		$this->assertFalse( ZEST_Followup::valid_phrase( ' padded ' ) );
		$this->assertFalse( ZEST_Followup::valid_phrase( 'mail alex@example.com' ) );
		$this->assertFalse( ZEST_Followup::valid_phrase( 'see #12' ) );
	}

	/* ── the notes ───────────────────────────────────────────────────────── */

	public function test_the_line_goes_to_the_bottom_and_replaces_the_apps_earlier_one(): void {
		$old = 'Follow-up 5/1/2026 (AB): Customer is still deciding.';
		$new = 'Follow-up 6/1/2026 (AB): Customer plans to go ahead.';
		$r   = ZEST_Followup::with_line( "Gate is on the left.\n" . $old . "\nFollow-up call went fine, said the customer.", $new );
		$this->assertTrue( $r['changed'] );
		$this->assertSame( $old, $r['before'] );
		$this->assertSame( "Gate is on the left.\nFollow-up call went fine, said the customer.\n" . $new, $r['notes'], 'a person\'s own line that starts with the word stays' );

		$r = ZEST_Followup::with_line( '', $new );
		$this->assertSame( $new, $r['notes'] );
		$this->assertSame( '', $r['before'] );

		$r = ZEST_Followup::with_line( "Words.\n" . $new, $new );
		$this->assertFalse( $r['changed'], 'already the last line: nothing to write' );
		$this->assertSame( "Words.\n" . $new, $r['notes'] );

		$r = ZEST_Followup::with_line( $new . "\nWords.", $new );
		$this->assertTrue( $r['changed'], 'found higher up: it moves to the bottom' );
		$this->assertSame( "Words.\n" . $new, $r['notes'] );
	}

	public function test_taking_a_line_back_removes_only_it_and_restores_the_one_before(): void {
		$mine  = 'Follow-up 6/1/2026 (AB): Customer chose another provider.';
		$prev  = 'Follow-up 5/1/2026 (CD): Customer is still deciding.';
		$notes = "Gate is on the left.\r\n" . $mine;

		$r = ZEST_Followup::without_lines( $notes, array( $mine ), $prev );
		$this->assertTrue( $r['found'] );
		$this->assertSame( $prev, $r['restored'] );
		$this->assertSame( "Gate is on the left.\r\n" . $prev, $r['notes'], 'the notes keep their own line endings' );

		$r = ZEST_Followup::without_lines( $notes, array( $mine ) );
		$this->assertSame( 'Gate is on the left.', $r['notes'] );
		$this->assertSame( '', $r['restored'] );

		$r = ZEST_Followup::without_lines( 'Gate is on the left.', array( $mine ), $prev );
		$this->assertFalse( $r['found'], 'not there: nothing changes, nothing is put back' );
		$this->assertSame( 'Gate is on the left.', $r['notes'] );

		$r = ZEST_Followup::without_lines( "A person wrote this.\n" . $mine, array( 'A person wrote this.' ), '' );
		$this->assertFalse( $r['found'], 'a line that is not the app\'s own is never taken out' );

		$r = ZEST_Followup::without_lines( $mine, array( $mine ), 'Customer rang, wants a discount.' );
		$this->assertSame( '', $r['restored'], 'only one of the app\'s own lines is ever put back' );
		$this->assertSame( '', $r['notes'] );

		$r = ZEST_Followup::without_lines( $prev . "\n" . $mine, array( $mine ), $prev );
		$this->assertSame( $prev, $r['notes'], 'the earlier line is already the last one: not added twice' );
	}

	public function test_typed_words_are_kept_as_typed_within_limits(): void {
		$this->assertSame( "quoted <900, wants >10% off\n\nsecond", ZEST_Followup::clean_note( "  quoted <900, wants >10% off\r\n\r\n\r\n\r\nsecond\x07 " ) );
		$this->assertSame( ZEST_Followup::MAX_NOTE, strlen( ZEST_Followup::clean_note( str_repeat( 'a', 5000 ) ) ) );
		$this->assertSame( '', ZEST_Followup::clean_note( array( 'not', 'text' ) ) );
	}

	/* ── sentences and the switch ────────────────────────────────────────── */

	public function test_the_sentences_name_people_only_by_what_they_are_given(): void {
		$this->assertSame( 'CC asked AA to follow up', ZEST_Followup::status_words( array( 'state' => 'open' ), 'AA', 'CC', '' ) );
		$this->assertSame( 'Asked the salesperson to follow up', ZEST_Followup::status_words( array( 'state' => 'open' ), '', '', '' ) );
		$this->assertSame( 'AA tried, no answer yet', ZEST_Followup::status_words( array( 'state' => 'tried', 'tries' => 1 ), 'AA', 'CC', 'AA' ) );
		$this->assertSame( 'AA tried 3 times, no answer yet', ZEST_Followup::status_words( array( 'state' => 'tried', 'tries' => 3 ), 'AA', 'CC', 'AA' ) );
		$this->assertSame( 'AA reached the customer: Still deciding', ZEST_Followup::status_words( array( 'state' => 'done', 'answer' => 'reached', 'outcome' => 'deciding' ), 'AA', 'CC', 'AA' ) );
		$this->assertSame( 'Someone had already handled it: Wants to go ahead', ZEST_Followup::status_words( array( 'state' => 'done', 'answer' => 'handled', 'outcome' => 'going_ahead' ), '', '', '' ) );
		$this->assertSame( '', ZEST_Followup::status_words( array( 'state' => 'withdrawn' ), 'AA', 'CC', 'AA' ) );
	}

	public function test_why_the_billing_line_was_left_alone_is_a_code_with_fixed_words(): void {
		foreach ( array( 'no_line', 'bad_line', 'not_connected', 'local', 'mode_off', 'mode_test', 'busy', 'taken_back', 'unread', 'other_doc', 'customer_unread', 'customer_old',
			'customer', 'archived', 'accepted', 'not_open', 'no_lines', 'history', 'refused', 'not_last', 'still_there', 'undo_unfinished', 'error' ) as $code ) {
			$this->assertGreaterThan( 10, strlen( ZEST_Followup::why_words( $code ) ), $code );
		}
		$this->assertStringContainsString( 'line 4', ZEST_Followup::why_words( 'differs:4' ) );
		$this->assertStringContainsString( 'different number of lines', ZEST_Followup::why_words( 'differs:0' ) );
		$this->assertSame( '', ZEST_Followup::why_words( 'Morgan Example said no' ), 'anything that is not a code says nothing' );
		$this->assertSame( '', ZEST_Followup::why_words( '' ) );
	}

	public function test_the_switch_is_test_customers_only_until_changed(): void {
		$this->assertSame( 'test', ZEST_Followup::mode() );
		$this->assertTrue( ZEST_Followup::line_wanted( true ) );
		$this->assertFalse( ZEST_Followup::line_wanted( false ) );
		$GLOBALS['zdz_test_options']['zest_followup_line_mode'] = 'on';
		$this->assertTrue( ZEST_Followup::line_wanted( false ) );
		$GLOBALS['zdz_test_options']['zest_followup_line_mode'] = 'off';
		$this->assertFalse( ZEST_Followup::line_wanted( true ) );
		$GLOBALS['zdz_test_options']['zest_followup_line_mode'] = 'everything';
		$this->assertSame( 'test', ZEST_Followup::mode(), 'a value that cannot be read is the careful one' );
	}

	/* ── the Identity Pack ───────────────────────────────────────────────── */

	public function test_a_pack_sets_the_switch_and_the_phrases(): void {
		$p = ZEST_Pack::plan( array(
			'rules'                => array( 'estimates' => array( 'followup' => array( 'billing_line' => 'on', 'reminder_days' => 3 ) ) ),
			'document-conventions' => array( 'estimate' => array( 'followup' => array( 'lead' => 'Checked in', 'deciding' => 'Still thinking it over.' ) ) ),
		) );
		$this->assertSame( 'on', $p['writes']['zest_followup_line_mode'] );
		$this->assertSame( array( 'lead' => 'Checked in', 'deciding' => 'Still thinking it over.' ), $p['doc']['followup'] );
		$this->assertContains( 'estimates.followup.reminder_days', $p['unconsumed'], 'a key the app does not know is reported, never guessed at' );
		$this->assertSame( array(), $p['refused'] );
		$this->assertContains( 'zest_followup_line_mode', ZEST_Pack::OPTIONS, 'a revert puts the switch back' );
	}

	public function test_a_pack_gives_the_import_reader_its_own_paperwork_rules(): void {
		$p = ZEST_Pack::plan( array( 'document-conventions' => array( 'estimate' => array( 'import_rules' => array(
			'A line that starts with "Opt." is an optional extra: kind "note".',
			'  The salesperson code follows "Rep:".  ',
		) ) ) ) );
		$this->assertSame( array( 'A line that starts with "Opt." is an optional extra: kind "note".', 'The salesperson code follows "Rep:".' ), $p['writes'][ ZEST_Pack::IMPORT_RULES_OPT ] );
		$this->assertSame( array(), $p['refused'] );
		$this->assertContains( ZEST_Pack::IMPORT_RULES_OPT, ZEST_Pack::OPTIONS, 'a revert puts the rules back' );
		$row = array_values( array_filter( ZEST_Pack::preview( array( 'document-conventions' => array( 'estimate' => array( 'import_rules' => array( 'One rule.' ) ) ) ) ), function ( $r ) {
			return ZEST_Pack::IMPORT_RULES_OPT === $r['path'];
		} ) );
		$this->assertSame( 'document-conventions', $row[0]['file'] );

		foreach ( array( array(), 'one string', array( "two\nlines" ), array( str_repeat( 'x', 401 ) ), array_fill( 0, 21, 'A rule.' ), array( 'ok', 5 ) ) as $bad ) {
			$p = ZEST_Pack::plan( array( 'document-conventions' => array( 'estimate' => array( 'import_rules' => $bad ) ) ) );
			$this->assertArrayNotHasKey( ZEST_Pack::IMPORT_RULES_OPT, $p['writes'], wp_json_encode( $bad ) );
			$this->assertContains( 'estimate.import_rules', array_column( $p['refused'], 'path' ) );
		}

		// The reader takes the business's list in place of the built-in one, and code still has the last word.
		require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/estimate/includes/class-zest-engine.php';
		$read = function () {
			$m = new ReflectionMethod( 'ZEST_Estimate_Engine', 'import_paperwork_rules' );
			$m->setAccessible( true );
			return $m->invoke( ( new ReflectionClass( 'ZEST_Estimate_Engine' ) )->newInstanceWithoutConstructor() );
		};
		$builtin = $read();
		$this->assertCount( 4, $builtin );
		$GLOBALS['zdz_test_options'][ ZEST_Pack::IMPORT_RULES_OPT ] = array( 'A line that starts with "Opt." is an optional extra: kind "note".' );
		try {
			$this->assertSame( array( '- A line that starts with "Opt." is an optional extra: kind "note".' ), $read() );
			$GLOBALS['zdz_test_filters']['zest_import_paperwork_rules'] = function ( $rules ) {
				return array_merge( $rules, array( 'Code adds one.' ) );
			};
			$this->assertSame( array( '- A line that starts with "Opt." is an optional extra: kind "note".', '- Code adds one.' ), $read() );
		} finally {
			unset( $GLOBALS['zdz_test_options'][ ZEST_Pack::IMPORT_RULES_OPT ], $GLOBALS['zdz_test_filters']['zest_import_paperwork_rules'] );
		}
		$this->assertSame( $builtin, $read(), 'with nothing stored the built-in rules are back' );
	}

	public function test_a_pack_value_that_cannot_be_read_changes_nothing(): void {
		$p = ZEST_Pack::plan( array(
			'rules'                => array( 'estimates' => array( 'followup' => array( 'billing_line' => 'always' ) ) ),
			'document-conventions' => array( 'estimate' => array( 'followup' => array( 'deciding' => 'Still deciding.', 'callback' => 'Will ring 555 0100' ) ) ),
		) );
		$this->assertFalse( isset( $p['writes']['zest_followup_line_mode'] ) );
		$this->assertFalse( isset( $p['doc']['followup'] ), 'one bad phrase: none of them is taken' );
		$paths = array_column( $p['refused'], 'path' );
		$this->assertContains( 'estimates.followup.billing_line', $paths );
		$this->assertContains( 'estimate.followup', $paths );

		$p = ZEST_Pack::plan( array( 'document-conventions' => array( 'estimate' => array( 'followup' => array( 'made_up' => 'Words.' ) ) ) ) );
		$this->assertContains( 'estimate.followup', array_column( $p['refused'], 'path' ) );
	}
}
