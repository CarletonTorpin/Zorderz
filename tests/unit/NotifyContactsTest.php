<?php
/**
 * The contact log behind "Contacted".
 *
 *   - Which answers reach a customer (Core: talked, replied; a business may change the list, never to
 *     something unknown); a later try never takes a reach back; a meeting always reached them.
 *   - A person who declined usage records is never named; the shared device is refused; the company
 *     count is for administrators; the verbs carry the right read/write labels.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/notify-media-stubs.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-contacts.php';

final class NotifyContactsTest extends TestCase {

	protected function tearDown(): void {
		zdz_notify_test_reset();
	}

	/* ── What reaches a customer ──────────────────────────────────────────── */

	public function test_core_reaching_answers_and_a_business_override(): void {
		$this->assertSame( array( 'talked', 'replied' ), ZDZ_Contacts::reaching_results() );
		$GLOBALS['zdz_test_filters']['zdz_contact_reaching_results'] = function () { return array( 'voicemail', 'talked', 'waved' ); };
		$this->assertSame( array( 'talked', 'voicemail' ), ZDZ_Contacts::reaching_results(), 'only known answers' );
		$GLOBALS['zdz_test_filters']['zdz_contact_reaching_results'] = function () { return array( 'waved' ); };
		$this->assertSame( array( 'talked', 'replied' ), ZDZ_Contacts::reaching_results(), 'nothing usable keeps Core' );
	}

	public function test_first_outcome(): void {
		$this->assertSame( 'reached', ZDZ_Contacts::initial_outcome( 'in_person', '' ), 'a meeting reached them' );
		$this->assertSame( 'attempt', ZDZ_Contacts::initial_outcome( 'voicemail', 'reached' ), 'a voicemail is a try' );
		$this->assertSame( 'attempt', ZDZ_Contacts::initial_outcome( 'call', '' ) );
		$this->assertSame( 'reached', ZDZ_Contacts::initial_outcome( 'email', 'reached' ) );
		$GLOBALS['zdz_test_filters']['zdz_contact_reaching_results'] = function () { return array( 'talked', 'replied', 'voicemail' ); };
		$this->assertSame( 'reached', ZDZ_Contacts::initial_outcome( 'voicemail', '' ), 'when the business counts a voicemail' );
	}

	public function test_answers_change_a_row_one_way(): void {
		$now = '2026-01-15 10:00:00';
		$try = array( 'channel' => 'call', 'outcome' => 'attempt' );
		$this->assertSame( array( 'result' => 'talked', 'outcome' => 'reached', 'reached_at' => $now ), ZDZ_Contacts::answer_changes( $try, 'talked', $now ) );
		$this->assertSame( array( 'result' => 'voicemail', 'channel' => 'voicemail' ), ZDZ_Contacts::answer_changes( $try, 'voicemail', $now ), 'a call becomes a voicemail' );
		$this->assertSame( array( 'result' => 'no_answer' ), ZDZ_Contacts::answer_changes( $try, 'no_answer', $now ) );
		$reached = array( 'channel' => 'call', 'outcome' => 'reached' );
		$this->assertNull( ZDZ_Contacts::answer_changes( $reached, 'no_answer', $now ), 'a later try never takes back a reach' );
		$this->assertNull( ZDZ_Contacts::answer_changes( $reached, 'voicemail', $now ) );
		$this->assertSame( array( 'result' => 'replied' ), ZDZ_Contacts::answer_changes( $reached, 'replied', $now ), 'stays reached, no new stamp' );
		$this->assertSame( array( 'result' => 'replied', 'outcome' => 'reached', 'reached_at' => $now ), ZDZ_Contacts::answer_changes( array( 'channel' => 'email', 'outcome' => 'attempt' ), 'replied', $now ) );
	}

	/* ── Who is named, who may ask ────────────────────────────────────────── */

	public function test_a_person_who_declined_usage_records_is_never_named(): void {
		$GLOBALS['zdz_test_user_roles'] = array( 70 => array( 'zdz_sales' ), 71 => array( 'zdz_sales' ) );
		$this->assertTrue( ZDZ_Contacts::may_name( 70 ), 'card off: records carry on' );
		$GLOBALS['zdz_test_options']['zdz_consent_card_on'] = '1';
		$this->assertFalse( ZDZ_Contacts::may_name( 70 ), 'card on, no answer: not named' );
		$GLOBALS['zdz_test_user_meta'][71]['zdz_consent'] = array( 'version' => ZDZ_Consent::version(), 'accepted_at' => '2026-01-01 09:00:00' );
		$this->assertTrue( ZDZ_Contacts::may_name( 71 ), 'agreed to the current notice' );
		$c = ZDZ_Contacts::counts( array( 'scope' => 'mine', 'uid' => 70, 'month' => '2026-01' ) );
		$this->assertFalse( $c['recorded'], 'their own count says it was not recorded' );
	}

	public function test_an_unnamed_contact_note_carries_no_name(): void {
		list( $text, $ref ) = ZDZ_Contacts::note_text( array(
			'id' => 9, 'user_id' => 0, 'channel' => 'call', 'outcome' => 'attempt', 'result' => 'no_answer',
			'ns_posted' => '', 'note' => '', 'occurred_at' => '2026-01-15 10:00:00', 'reached_at' => null,
		) );
		$this->assertSame( 'zdc-9-a', $ref );
		$this->assertStringContainsString( 'Contact log · Called — no answer (Jan 15, 2026 at 10:00 AM UTC)', $text );
		$this->assertStringContainsString( "\n\nRef: zdc-9-a", $text );
	}

	public function test_the_shared_device_and_the_company_count(): void {
		$GLOBALS['zdz_test_user_roles'] = array( 80 => array( 'zdz_general' ), 81 => array( 'zdz_sales' ) );
		$this->assertTrue( ZDZ_Contacts::is_blocked( 80 ) );
		$this->assertTrue( ZDZ_Contacts::is_blocked( 0 ) );
		$this->assertFalse( ZDZ_Contacts::is_blocked( 81 ) );
		$this->assertTrue( ZDZ_Contacts::counts_for( 80, array( 'scope' => 'mine' ) )['denied'] );
		$this->assertTrue( ZDZ_Contacts::counts_for( 81, array( 'scope' => 'company' ) )['denied'], 'administrators only' );
		$this->assertFalse( ZDZ_Contacts::may_answer( array( 'tap_key' => 'k', 'user_id' => 80, 'app' => 'leads' ), 80, 'k' ), 'never the shared device, even with the key' );
		$this->assertTrue( ZDZ_Contacts::may_answer( array( 'tap_key' => 'abcd', 'user_id' => 0, 'app' => 'leads' ), 81, 'abcd' ), 'the page holding its key' );
		$this->assertFalse( ZDZ_Contacts::may_answer( array( 'tap_key' => 'abcd', 'user_id' => 0, 'app' => 'leads' ), 81, 'nope' ) );
	}

	public function test_verbs_are_labelled_read_and_write(): void {
		$caps = ZDZ_Contacts::register_verbs( array() );
		$this->assertTrue( $caps['contact.log']['side_effect'], 'logging is a write, confirmed in chat' );
		$this->assertFalse( $caps['contact.counts']['side_effect'] );
		$ann = ZDZ_Contacts::annotate( array() );
		$this->assertTrue( $ann['contact.log']['outside'], 'it posts a CRM note' );
		$n = ZDZ_Capabilities::normalize( 'contact.log', $caps['contact.log'], $ann );
		$this->assertTrue( $n['side_effect'] );
		$this->assertSame( 'viewer', $n['tier'] );
	}

	public function test_only_registered_apps_write(): void {
		$this->assertContains( 'leads', ZDZ_Contacts::apps() );
		$this->assertNotContains( 'chat', ZDZ_Contacts::page_apps(), 'chat logs through its Confirm card' );
		$GLOBALS['zdz_test_filters']['zdz_contact_apps'] = function ( $a ) { return array_merge( $a, array( 'Bad App!', 'example-app' ) ); };
		$this->assertContains( 'example-app', ZDZ_Contacts::apps() );
		$this->assertNotContains( 'Bad App!', ZDZ_Contacts::apps() );
	}
}
