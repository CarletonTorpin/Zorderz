<?php
/**
 * Surveys: a call note with a send instruction is read in context.
 *
 *   G  Glued sentences ("again.Send", "Happy!Send") read as two sentences; "v.m.", "a.m.", "e.g.",
 *      titles, decimals and web addresses are left alone.
 *   S  Clauses (comma, semicolon, colon, spaced dash) are judged one by one: the send clause must
 *      be clean, and a SOFT aside elsewhere (the customer may call later: the whole clause, with
 *      only a short allowed tail) does not hold the e-mail, unless the note names open work or
 *      money anywhere, or the send itself is conditional.
 *   H  HARD caveats hold it WHEREVER they sit in the note (another clause or another sentence): the
 *      business's own follow-up, try again, come back, reschedule, and any call still owed.
 *   U  Kept on purpose: a happy note that only says "will call again" still waits for a person;
 *      issues, voicemail, a send that waits and a negated send win as before.
 *   V  The word lists are the business's: a stored list or a filter replaces Core's.
 *   P  An Identity Pack sets them through ZSV_Pack, which never lets a hold list be emptied.
 *
 * Made-up notes only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $k ) { unset( $GLOBALS['zdz_test_options'][ $k ] ); return true; }
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( ...$a ) { return true; }
}

$zsv210n = dirname( __DIR__, 2 );
require_once $zsv210n . '/zorderz/inc/class-zdz-business-profile.php';
require_once $zsv210n . '/zorderz/inc/class-zdz-identity-pack.php';
require_once $zsv210n . '/zorderz-apps/apps/surveys/includes/class-zsv-db.php';
require_once $zsv210n . '/zorderz-apps/apps/surveys/includes/class-zsv-settings.php';
require_once $zsv210n . '/zorderz-apps/apps/surveys/includes/class-zsv-budget.php';
require_once $zsv210n . '/zorderz-apps/apps/surveys/includes/class-zsv-owed.php';
require_once $zsv210n . '/zorderz-apps/apps/surveys/includes/class-zsv-note-reader.php';
require_once $zsv210n . '/zorderz-apps/apps/surveys/includes/class-zsv-send-once.php';
require_once $zsv210n . '/zorderz-apps/apps/surveys/includes/class-zsv-survey-manager.php';
require_once $zsv210n . '/zorderz-apps/apps/surveys/includes/class-zsv-pack.php';

final class SurveysNoteReaderTest extends TestCase {

	/** @var ZSV_Survey_Manager */
	private $m;

	protected function setUp(): void {
		$GLOBALS['zdz_test_options'] = array();
		unset( $GLOBALS['zdz_test_filters']['zsv_status_vocabulary'], $GLOBALS['zdz_test_filters']['zsv_note_reading'], $GLOBALS['zdz_test_filters']['zsv_negation_words'] );
		$this->m = ( new ReflectionClass( 'ZSV_Survey_Manager' ) )->newInstanceWithoutConstructor();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_filters']['zsv_status_vocabulary'], $GLOBALS['zdz_test_filters']['zsv_note_reading'], $GLOBALS['zdz_test_filters']['zsv_negation_words'], $GLOBALS['zdz_test_filters']['zdz_identity_pack_consumers'] );
	}

	private function status( string $note ): string {
		return $this->m->parse_single_status( $note );
	}

	/** Every note in $notes reads as $want. */
	private function all( string $want, array $notes ): void {
		foreach ( $notes as $n ) {
			$this->assertSame( $want, $this->status( '3/9/26 ' . $n ), $n );
		}
	}

	/** A business whose notes also name somebody who answers for the customer. */
	private function with_third_party_word(): void {
		$GLOBALS['zdz_test_filters']['zsv_status_vocabulary'] = function ( $v ) {
			$v['attention'][] = 'receptionist';
			return $v;
		};
	}

	/* ── G: glued sentences ────────────────────────────────────────────────────────── */

	public function test_a_note_typed_with_no_space_after_the_stop_reads_like_the_same_note_with_one(): void {
		$this->all( 'satisfied', array(
			'Great! Will Call again.Send review link',
			'Will call again.Send the link',
			'Great! Will call again. Send review link',
			'Happy!Send link',
		) );
		$r = $this->m->parse_status_multi( array( '3/6/26 Left VM', '3/9/26 Great! Will Call again.Send review link' ) );
		$this->assertSame( 'satisfied', $r['status'], 'an older voicemail, then the glued note' );
	}

	public function test_what_ungluing_leaves_alone(): void {
		$same = array( 'left v.m. today', 'at 9 a.m.call', 'e.g.the order', 'see example.com', 'example.net', 'example.app', 'alex@example.co.uk', 'Mr.Morgan', 'Elm St.send', 'rated 5.5', 'Dr.Reed called' );
		foreach ( $same as $s ) {
			$this->assertSame( $s, ZSV_Note_Reader::unglue_sentences( $s ), $s );
		}
		$this->assertSame( 'again. Send', ZSV_Note_Reader::unglue_sentences( 'again.Send' ) );
		$this->assertSame( 'Great! Send', ZSV_Note_Reader::unglue_sentences( 'Great!Send' ) );
		$this->assertSame( 'Really?! Yes', ZSV_Note_Reader::unglue_sentences( 'Really?!Yes' ) );
		$this->assertSame( array( 'Good.', 'Will call again.', 'Send the link' ), ZSV_Note_Reader::sentences( '3/9/26  Good.Will call again.Send the link' ), 'the call date is dropped, the note split' );
	}

	public function test_a_voicemail_still_wins_over_a_glued_send(): void {
		$this->assertSame( 'left_vm', $this->status( '3/9/26 Left VM.Send review link' ) );
		$this->assertSame( 'left_vm', $this->status( '3/9/26 No answer.Will try again' ) );
		// A business whose operator writes "v.m.": the abbreviation is not split, and still means voicemail.
		$GLOBALS['zdz_test_filters']['zsv_status_vocabulary'] = function ( $v ) {
			$v['voicemail'][] = 'left v.m.';
			return $v;
		};
		$this->assertSame( 'left_vm', $this->status( '3/9/26 Left v.m.Will try again' ) );
	}

	/* ── S: a soft aside in another clause ─────────────────────────────────────────── */

	public function test_a_soft_aside_in_another_clause_does_not_hold_the_send(): void {
		$this->all( 'satisfied', array(
			'Good, will call again, send review link',
			'Very happy, she will call back if anything comes up, send the link',
			'Loves it - will call again - send review link',
			"Happy; he'll get back to us if needed; ok to send",
			'Great: they will call us anytime, send link',
			'Very happy! She will call back if anything comes up. Send review link.',
			'Great! Send review link.',
			'Good, she will call back if she needs anything, send link',
			'Will call again. Ok to send',
		) );
	}

	/* ── H: hard caveats hold it for a person ──────────────────────────────────────── */

	public function test_hard_caveats_in_another_clause_hold_it(): void {
		$this->all( 'needs_attention', array(
			'Good. Follow up next week, send review link',
			'Good, try again tomorrow, send review link',
			'Need to call her partner, send review link later',
			"Good, I'll call back tomorrow, send review link",
			'Good, will call her back, send review link',
			'Good, we will call the manager, send link',
			'Good, tech to come back, send link',
			'Good, will call again and send review link',
			'Good, reschedule, send link',
			'Good, manager not available, send link',
		) );
	}

	public function test_hard_caveats_glued_or_in_another_sentence_hold_it(): void {
		$this->all( 'needs_attention', array(
			'Good!Follow up next wk.Send link',
			'Good, follow up.Send link',
			'Great! Follow up next week. Send review link.',
			'Talked briefly.Will call back.Send link',
		) );
	}

	public function test_somebody_else_answering_holds_it_wherever_the_word_sits(): void {
		$this->with_third_party_word();
		$this->all( 'needs_attention', array(
			'Spoke to receptionist, send review link',
			'Contact receptionist Morgan before the review link goes out',
			'Receptionist said good.Send link',
			'Ok.Contact receptionist.Send link',
			'Talked to receptionist.Will call back.Send link',
			'Spoke to receptionist Mr.Morgan, send link',
			'Receptionist at 12 Elm St.send link',
			'Spoke to receptionist. Send review link.',
			'Good, she will call back if the receptionist agrees, send link',
		) );
	}

	public function test_calls_still_owed_are_not_soft(): void {
		$this->all( 'needs_attention', array(
			'Need to get back to her about pricing, send link',
			'Good, will get back to her, send link',
			'Customer wants a callback, send link',
			'Good, asked for a call back, send link',
			'Good, requested a callback, send link',
			'Give her a call back tomorrow, send link',
			'Great, call back re warranty, send link',
			'Callback Monday, send link',
			'Busy, will call back later, send link',
			'Good, Alex will call back about the timing, send link',
			'Good, tech will call back to set up the install, send link',
			'Good, rep will call back w/ quote, send link',
			'Will call when the part comes in, send review link',
			'Will call back once the parts arrive, send link',
			'Good, will call landlord back, send link',
			'Good, will call his wife back, send link',
			'Good, will call mrs morgan back, send link',
			'Good, will call again for the install, send link',
			'Good, she will call back to schedule, send link',
			'Good, she will call back if the manager agrees, send link',
			'Good, she will call back if you send link',
		) );
	}

	public function test_tails_job_words_and_the_account_holder_not_reached(): void {
		$this->all( 'needs_attention', array(
			'Loves it - will call again for more work - send review link',
			'Happy; he will get back to us about the timing; ok to send',
			'Great: they will call us for the upstairs, send link',
			'Great, she will call back, to schedule the last visit, send link',
			"Great, she'll call back - about the install upstairs - send link",
			'Great, customer will call back, for the service call, send link',
			'Good, she will call back for her refund, send link',
			'Good, he will call back for the balance he disputes, send link',
			'Good, client will call back for the remaining items, send link',
			'Good, will call again for the other 3 rooms, send link',
			'Good, will call us for the second room, send link',
			'Good, she will call back if it acts up again, send link',
			"Good, she'll call back whenever the manager approves it, send link",
			'Good, she will get back to us when the part is replaced, send link',
			'Good, he will get back to us about the credit, send link',
			'Great, get back to us re the order, send link',
			'Renter answered, owner to call back, send link',
			'Husband answered, wife to call back, send link',
			'Husband answered, wife will call back, send link',
			'Good, she will call back, send link if ok',
			'Good, she will call back if anything comes up, wants a price on another one, send link',
		) );
	}

	public function test_why_an_instruction_does_not_stand(): void {
		$v    = ZSV_Settings::status_vocabulary();
		$read = function ( string $note ) use ( $v ) {
			return ZSV_Note_Reader::send_instruction( $note, $v['send_instruction'], $v['attention'] );
		};
		$this->assertSame( array( 'stands' => true, 'why' => '' ), $read( 'Good, will call again, send link' ) );
		$this->assertSame( array( 'stands' => true, 'why' => '' ), $read( 'Send the link' ) );
		$this->assertSame( 'none', $read( 'Good, will call again' )['why'], 'no instruction at all' );
		$this->assertSame( 'none', $read( 'Good, will call again and send link' )['why'], 'the instruction shares its clause with an attention word' );
		$this->assertSame( 'none', $read( 'Good, she will call back, send link if ok' )['why'], 'a conditional send is not a clean instruction' );
		$this->assertSame( 'none', $read( 'Good, will call again, send link unless she rings first' )['why'] );
		$this->assertSame( 'hard_caveat', $read( 'Good, will call back later, send link' )['why'] );
		$this->assertSame( 'hard_caveat', $read( 'Follow up next week. Send link.' )['why'], 'in another sentence too' );
		$this->assertSame( 'open_work', $read( 'Great, she will call back, to schedule the last visit, send link' )['why'] );
		$this->assertSame( 'open_work', $read( 'Paid the balance, will call again, send link' )['why'], 'money named anywhere in the note' );
	}

	/* ── negations ─────────────────────────────────────────────────────────────────── */

	public function test_a_negated_send_instruction_is_not_an_instruction(): void {
		$v    = ZSV_Settings::status_vocabulary();
		$read = function ( string $note ) use ( $v ) {
			return ZSV_Note_Reader::send_instruction( $note, $v['send_instruction'], $v['attention'] )['stands'];
		};
		foreach ( array(
			"Good, will call again, don't send link",
			'Good, will call again, do not send the link',
			'Good, will call again, never send link',
			'Good, will call again, do not ever send the link',
			'Good, will call again, she declined so skip the send link step',
		) as $n ) {
			$this->assertFalse( $read( $n ), $n );
		}
		$this->assertTrue( $read( 'Not home earlier. Send link now, will call again' ), 'a negation in one sentence does not reach into the next' );
		$this->assertSame( 'needs_attention', $this->status( '3/9/26 Good, will call again, do not ever send the link' ) );
		$this->assertSame( 'no_online_review', $this->status( "3/9/26 Everything is fine. Don't send the review" ), 'a "do not send" note is never read as a send' );
	}

	public function test_where_a_negator_counts(): void {
		$this->assertTrue( ZSV_Note_Reader::has_negator_before( "don't send link", 6 ) );
		$this->assertTrue( ZSV_Note_Reader::has_negator_before( 'do not ever send link', 12 ) );
		$this->assertFalse( ZSV_Note_Reader::has_negator_before( 'not home. send link', 10 ), 'stops at the sentence break' );
		$this->assertFalse( ZSV_Note_Reader::has_negator_before( 'send link', 0 ) );
		$this->assertFalse( ZSV_Note_Reader::has_negator_before( 'no one was in when we first rang but later she said send link', 52 ), 'more than a few words back' );
		$this->assertSame( 10, ZSV_Note_Reader::find_unnegated( 'not home. send link', array( 'send link' ) ) );
		$this->assertFalse( ZSV_Note_Reader::find_unnegated( "don't send link, send link", array( 'send link' ) ), 'a comma does not end the reach of a negator' );
		$this->assertSame( 32, ZSV_Note_Reader::find_unnegated( "don't send link. happy with it, send link", array( 'send link' ) ), 'the next one that is not negated' );
		$this->assertFalse( ZSV_Note_Reader::find_unnegated( 'never send link', array( 'send link', '' ) ) );
	}

	/* ── U: kept on purpose ────────────────────────────────────────────────────────── */

	public function test_rules_this_reading_keeps(): void {
		$this->assertSame( 'needs_attention', $this->status( '3/9/26 Good! Will call again.' ), 'no send instruction: it could mean either, so a person reads it' );
		$this->assertSame( 'follow_up', $this->status( '3/9/26 Good! Lock is still broken.Send review link' ), 'an open issue wins' );
		$this->assertSame( 'follow_up', $this->status( '3/9/26 Great. Send link after the last one goes in.' ), 'a send that waits on unfinished work is a hold' );
		$this->assertSame( 'follow_up', $this->status( '3/9/26 Great, needs a call back, send link' ) );
		$this->assertSame( 'left_vm', $this->status( '3/9/26 Not home.' ) );
		$this->assertSame( 'no_primary_account', $this->status( '3/9/26 Happy, no google account, send the other link' ) );
		$this->assertSame( 'needs_attention', $this->status( '3/9/26 Spoke for a while' ), 'nothing recognised: a person reads it' );
		$this->assertSame( 'not_contacted', $this->status( '   ' ) );
	}

	public function test_a_send_that_waits(): void {
		$this->assertTrue( ZSV_Note_Reader::send_waits( 'Great. Send link after the last one goes in.' ) );
		$this->assertTrue( ZSV_Note_Reader::send_waits( 'Send her the review link once the part is in' ) );
		$this->assertTrue( ZSV_Note_Reader::send_waits( 'Happy.Send it when the invoice is settled' ) );
		$this->assertFalse( ZSV_Note_Reader::send_waits( 'Send the link when you get a chance' ), 'not waiting on the work' );
		$this->assertFalse( ZSV_Note_Reader::send_waits( 'Send link. Called after lunch.' ), 'the waiting word is in another sentence' );
		$this->assertFalse( ZSV_Note_Reader::send_waits( 'Great, send link' ) );
		$this->assertSame( 'satisfied', $this->status( '3/9/26 Great, send the link when you get a chance' ) );
	}

	public function test_an_aside_about_calling_later_is_not_an_issue(): void {
		$this->assertSame( array( 'she will call back if she needs anything' ), ZSV_Note_Reader::soft_asides( 'Good, she will call back if she needs anything, send link' ) );
		$this->assertSame( 'needs_attention', $this->status( '3/9/26 Good, she will call back if she needs anything' ), 'held for a person, not filed as an open issue' );
		$this->assertSame( 'follow_up', $this->status( '3/9/26 Good, she will call back if she needs anything, needs a new part' ), 'an issue outside the aside still counts' );
		$this->assertSame( array(), ZSV_Note_Reader::soft_asides( 'Good, will call back later' ) );
	}

	/* ── the helpers ───────────────────────────────────────────────────────────────── */

	public function test_what_a_soft_aside_is(): void {
		foreach ( array(
			'will call again', 'Will Call again.', 'she will call back if anything comes up', "she'll call back", "he'll get back to us",
			'she will call back if anything else comes up', 'customer will call us anytime', 'and he will call us', 'they may call again in the future',
			'client might call us back if needed', 'they can call back if any questions', 'she will callback', 'he will call-back any time',
			'may call us if they need anything else',
		) as $c ) {
			$this->assertTrue( ZSV_Note_Reader::is_soft_callback_clause( $c ), $c );
		}
		foreach ( array(
			'will call back later', 'will call when the part comes in', 'alex will call back', 'will get back to her', 'customer wants a callback',
			'will call again for the install', 'she will call back to schedule', "i'll call back", 'will call back', 'call her back',
			'they will call us for the upstairs', 'he will get back to us about the timing', 'get back to us', 'owner to call back', 'customer to call back',
			'wife will call back', 'she will call back if it acts up again', 'she may get back to us', 'will call again tomorrow', '',
		) as $c ) {
			$this->assertFalse( ZSV_Note_Reader::is_soft_callback_clause( $c ), $c );
		}
	}

	public function test_how_a_sentence_splits_into_clauses(): void {
		$this->assertSame( array( 'good', 'will call again', 'send link' ), ZSV_Note_Reader::split_clauses( 'good, will call again, send link' ) );
		$this->assertSame( array( 'loves it', 'send link' ), ZSV_Note_Reader::split_clauses( 'loves it - send link' ) );
		$this->assertSame( array( 'call-back later' ), ZSV_Note_Reader::split_clauses( 'call-back later' ), 'a hyphen inside a word is kept' );
		$this->assertSame( array( 'good', 'send link' ), ZSV_Note_Reader::split_clauses( "good \u{2013} send link" ), 'an en dash' );
		$this->assertSame( array( 'happy', 'ok to send' ), ZSV_Note_Reader::split_clauses( 'happy; ok to send' ) );
		$this->assertSame( array( 'great', 'send link' ), ZSV_Note_Reader::split_clauses( 'great: send link' ) );
	}

	public function test_open_work_is_a_word_beginning(): void {
		foreach ( array( 'to schedule the next visit', 'the install', 'her refund', 'second room', 'needs repairs', 'a new part friday', 'awaiting approval' ) as $s ) {
			$this->assertTrue( ZSV_Note_Reader::mentions_open_work( $s ), $s );
		}
		foreach ( array( 'very happy', 'will call again', 'departed early', 'reinstated' ) as $s ) {
			$this->assertFalse( ZSV_Note_Reader::mentions_open_work( $s ), $s );
		}
	}

	/* ── V: the word lists are the business's ──────────────────────────────────────── */

	public function test_core_lists_name_no_business_and_carry_the_new_keys(): void {
		$v = ZSV_Settings::status_vocabulary();
		$this->assertContains( 'will call', $v['attention'] );
		$this->assertContains( 'send link', $v['send_instruction'] );
		$this->assertNotContains( 'call back', $v['issue'], 'a call back is a note for a person, not an open issue' );
		$r = ZSV_Settings::note_reading();
		foreach ( ZSV_Pack::READING_KEYS as $k ) {
			$this->assertNotEmpty( $r[ $k ], $k );
		}
		foreach ( ZSV_Pack::VOCABULARY_KEYS as $k ) {
			$this->assertNotEmpty( $v[ $k ], $k );
		}
		$this->assertContains( "don't", ZSV_Settings::negation_words() );
	}

	public function test_a_stored_list_replaces_cores_one_key_at_a_time(): void {
		$GLOBALS['zdz_test_options'][ ZSV_Settings::VOCABULARY_OPTION ] = array( 'send_instruction' => array( ' Mail  the Invite ', 'mail the invite', 7 => array( 'x' ) ) );
		$v = ZSV_Settings::status_vocabulary();
		$this->assertSame( array( 'mail the invite' ), $v['send_instruction'], 'cleaned, lower case, once' );
		$this->assertContains( 'will call', $v['attention'], 'a list not stored keeps Core\'s' );
		$this->assertSame( 'satisfied', $this->status( '3/9/26 Will call again, mail the invite' ) );
		$this->assertSame( 'needs_attention', $this->status( '3/9/26 Will call again, ok to send' ), 'Core\'s wording is no longer this business\'s instruction' );

		$GLOBALS['zdz_test_options'][ ZSV_Settings::READING_OPTION ] = array( 'subjects' => array( 'member' ), 'tails' => array( 'if they wish' ) );
		$this->assertTrue( ZSV_Note_Reader::is_soft_callback_clause( 'member will call back if they wish' ) );
		$this->assertFalse( ZSV_Note_Reader::is_soft_callback_clause( 'she will call back' ), 'the stored subjects replaced Core\'s' );
		$this->assertTrue( ZSV_Note_Reader::is_soft_callback_clause( 'will call again' ), 'the other shapes are untouched' );
	}

	public function test_a_filter_may_change_the_reading_lists_but_not_their_shape(): void {
		$GLOBALS['zdz_test_filters']['zsv_note_reading'] = function ( $r ) {
			$r['conditions'][] = 'provided';
			$r['open_work']    = 'not a list';
			$r['made_up']      = array( 'x' );
			return $r;
		};
		$r = ZSV_Settings::note_reading();
		$this->assertContains( 'provided', $r['conditions'] );
		$this->assertContains( 'refund', $r['open_work'], 'a key that is not a list keeps Core\'s list' );
		$this->assertArrayNotHasKey( 'made_up', $r );
		$this->assertTrue( ZSV_Note_Reader::is_conditional( 'send link provided she agrees' ) );
		$GLOBALS['zdz_test_filters']['zsv_note_reading'] = function () { return 'nonsense'; };
		$this->assertContains( 'if', ZSV_Settings::note_reading()['conditions'] );
	}

	public function test_phrase_lists_are_cleaned(): void {
		$this->assertSame( array( "don't send", 'ok' ), ZSV_Settings::clean_words( array( " Don\u{2019}t   Send ", 'OK', 'ok', '', array( 'x' ), str_repeat( 'a', ZSV_Settings::WORD_LENGTH_MAX + 1 ) ) ) );
		$this->assertSame( array(), ZSV_Settings::clean_words( 'not a list' ) );
		$this->assertCount( ZSV_Settings::WORDS_MAX, ZSV_Settings::clean_words( array_map( function ( $i ) { return 'w' . $i; }, range( 1, ZSV_Settings::WORDS_MAX + 20 ) ) ) );
	}

	/* ── P: an Identity Pack ───────────────────────────────────────────────────────── */

	public function test_the_pack_consumers_are_registered_for_voice_and_rules(): void {
		$GLOBALS['zdz_test_filters']['zdz_identity_pack_consumers'] = array( 'ZSV_Pack', 'register' );
		$c = ZDZ_Identity_Pack::consumers();
		$this->assertSame( array( 'voice' ), $c['surveys-call-notes']['files'] );
		$this->assertSame( array( 'surveys.status_vocabulary', 'surveys.note_reading' ), $c['surveys-call-notes']['paths']['voice'] );
		$this->assertSame( array( 'rules' ), $c['surveys-reviews']['files'] );
		$this->assertSame( array( 'surveys.google_reviews' ), $c['surveys-reviews']['paths']['rules'] );
	}

	public function test_a_pack_sets_lists_one_at_a_time(): void {
		$GLOBALS['zdz_test_options'][ ZSV_Settings::VOCABULARY_OPTION ] = array( 'satisfied' => array( 'delighted' ) );
		$plan = ZSV_Pack::plan_words( array(
			'surveys.status_vocabulary' => array( 'attention' => array( 'Ring Back', 'follow up' ), 'send_instruction' => 'mail the invite, ok to mail' ),
			'surveys.note_reading'      => array( 'subjects' => array( 'she', 'member' ) ),
		) );
		$this->assertSame( array(), $plan['refused'] );
		$writes = array();
		foreach ( $plan['writes'] as $w ) {
			$writes[ $w['option'] . ':' . $w['key'] ] = $w['value'];
		}
		$this->assertSame(
			array(
				ZSV_Settings::VOCABULARY_OPTION . ':attention'        => array( 'ring back', 'follow up' ),
				ZSV_Settings::VOCABULARY_OPTION . ':send_instruction' => array( 'mail the invite', 'ok to mail' ),
				ZSV_Settings::READING_OPTION . ':subjects'            => array( 'she', 'member' ),
			),
			$writes,
			'each list is one key of its option: what the pack leaves out is left as it is'
		);
		$this->assertCount( 3, $plan['changes'] );
		$this->assertNotEmpty( $plan['notes'] );
	}

	public function test_a_pack_cannot_empty_a_list_that_holds_notes_back(): void {
		$plan = ZSV_Pack::plan_words( array(
			'surveys.status_vocabulary' => array( 'attention' => array(), 'satisfied' => array(), 'nonsense' => array( 'x' ), 'issue' => 5 ),
			'surveys.note_reading'      => array( 'open_work' => '', 'tails' => array(), 'conditions' => array( 'if', str_repeat( 'x', 200 ) ) ),
		) );
		$refused = array_column( $plan['refused'], 'path' );
		sort( $refused );
		$this->assertSame(
			array(
				'surveys.note_reading.conditions',
				'surveys.note_reading.open_work',
				'surveys.status_vocabulary.attention',
				'surveys.status_vocabulary.issue',
				'surveys.status_vocabulary.nonsense',
			),
			$refused
		);
		$keys = array_map( function ( $w ) { return $w['key']; }, $plan['writes'] );
		sort( $keys );
		$this->assertSame( array( 'satisfied', 'tails' ), $keys, 'a list that only lets e-mails go may be emptied: that is the careful direction' );
		foreach ( ZSV_Pack::HOLD_LISTS as $k ) {
			$this->assertTrue( in_array( $k, ZSV_Pack::VOCABULARY_KEYS, true ) || in_array( $k, ZSV_Pack::READING_KEYS, true ), $k );
		}
		$this->assertSame( array( 'surveys.status_vocabulary' ), array_column( ZSV_Pack::plan_words( array( 'surveys.status_vocabulary' => 'x' ) )['refused'], 'path' ) );
	}

	public function test_a_pack_sets_the_review_windows_but_never_automatic_recording(): void {
		$plan = ZSV_Pack::plan_reviews( array( 'surveys.google_reviews' => array( 'lookback_days' => 45, 'candidate_days' => '90', 'automatic' => true ) ) );
		$this->assertSame( array( 'surveys.google_reviews.automatic' ), array_column( $plan['refused'], 'path' ) );
		$this->assertSame(
			array( array( 'option' => 'zsv_greviews_lookback_days', 'key' => null, 'value' => 45 ), array( 'option' => 'zsv_greviews_candidate_days', 'key' => null, 'value' => 90 ) ),
			$plan['writes']
		);
		$bad = ZSV_Pack::plan_reviews( array( 'surveys.google_reviews' => array( 'lookback_days' => 0, 'candidate_days' => 9999, 'other' => 1 ) ) );
		$this->assertSame( array(), $bad['writes'] );
		$this->assertCount( 3, $bad['refused'] );
		$this->assertSame( array(), ZSV_Pack::plan_reviews( array( 'surveys.google_reviews' => array( 'lookback_days' => 60.5 ) ) )['writes'] );
		$this->assertSame( array( 'surveys.google_reviews' ), array_column( ZSV_Pack::plan_reviews( array( 'surveys.google_reviews' => 7 ) )['refused'], 'path' ) );

		$GLOBALS['zdz_test_options']['zsv_greviews_lookback_days'] = '45';
		$same = ZSV_Pack::plan_reviews( array( 'surveys.google_reviews' => array( 'lookback_days' => 45 ) ) );
		$this->assertSame( array(), $same['writes'], 'a stored "45" and a pack\'s 45 are the same setting' );
	}

	public function test_the_review_windows_have_the_same_defaults_and_stay_in_range(): void {
		$this->assertSame( 60, ZSV_Settings::greviews_lookback_days() );
		$this->assertSame( 120, ZSV_Settings::greviews_candidate_days() );
		$GLOBALS['zdz_test_options']['zsv_greviews_lookback_days']  = 100000;
		$GLOBALS['zdz_test_options']['zsv_greviews_candidate_days'] = -4;
		$this->assertSame( 365, ZSV_Settings::greviews_lookback_days() );
		$this->assertSame( 1, ZSV_Settings::greviews_candidate_days() );
	}
}
