<?php
/**
 * Surveys: what the widget shows and asks for, and the "Send again" button of the settings screen.
 *
 *   A  the widget's decisions and sentences (assets/js/widget-logic.js), as plain functions;
 *   B  the shipped widget (assets/js/widget.js) on a stand-in page: call notes showing on a
 *      customer nobody has handled, one press of "Check reviews" to the end, the Send line (which
 *      never asks to e-mail anybody again), and the Google reviews panel;
 *   C  the shipped settings-screen script (assets/js/admin.js): a second e-mail is asked for only
 *      after a person answered OK, for that one customer;
 *   D  the settings screen's own helpers, and the stylesheet's colours.
 *
 * A, B and C run in node and skip themselves where node is not installed. The page is a stand-in
 * (a few named parts, a question box, a scripted network): what a browser lays out and paints is
 * NOT proven here.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( $v ) {
		if ( is_string( $v ) ) {
			$u = @unserialize( $v, array( 'allowed_classes' => false ) );   // sentinel:allow the test stand-in for WordPress's maybe_unserialize(); no objects
			if ( false !== $u || 'b:0;' === $v ) {
				return $u;
			}
		}
		return $v;
	}
}

$zsv210w = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/surveys/includes/';
require_once $zsv210w . 'class-zsv-db.php';
require_once $zsv210w . 'class-zsv-settings.php';
require_once $zsv210w . 'class-zsv-send-once.php';
require_once $zsv210w . 'class-zsv-admin.php';

/** The survey table and the options table, for the "already sent" list. */
if ( ! class_exists( 'ZSV210_Sent_DB' ) ) {
	class ZSV210_Sent_DB {
		public $prefix  = 'wp_';
		public $options = 'wp_options';
		public $rows    = array();
		public function prepare( $q, ...$a ) {
			if ( 1 === count( $a ) && is_array( $a[0] ) ) {
				$a = $a[0];
			}
			return array( 'q' => $q, 'a' => $a );
		}
		public function get_var( $p ) {
			$n = (string) ( $p['a'][0] ?? '' );
			return array_key_exists( $n, (array) ( $GLOBALS['zdz_test_options'] ?? array() ) ) ? serialize( $GLOBALS['zdz_test_options'][ $n ] ) : null;
		}
		public function get_results( $p, $out = null ) {
			$q = $p['q'];
			if ( false !== strpos( $q, 'WHERE id IN (' ) ) {
				$ids = array_map( 'intval', $p['a'] );
				return array_values( array_filter( $this->rows, static function ( $r ) use ( $ids ) { return in_array( (int) $r['id'], $ids, true ); } ) );
			}
			if ( false !== strpos( $q, 'WHERE email_sent_at IS NOT NULL ORDER BY email_sent_at DESC LIMIT %d' ) ) {
				$hit = array_filter( $this->rows, static function ( $r ) { return null !== $r['email_sent_at']; } );
				usort( $hit, static function ( $x, $y ) { return strcmp( (string) $y['email_sent_at'], (string) $x['email_sent_at'] ); } );
				return array_slice( $hit, 0, (int) $p['a'][0] );
			}
			return array();
		}
	}
}

final class SurveysWidgetTest extends TestCase {

	private static function asset( string $rel ): string {
		return dirname( __DIR__, 2 ) . '/zorderz-apps/apps/surveys/assets/' . $rel;
	}

	/** Run one of tests/unit/support/*.js in node on a JSON file made from $input; the decoded answer. */
	private function node( string $script, array $files, $input ): array {
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) ); // sentinel:allow fixed command, no input
		if ( '' === $node ) {
			$this->markTestSkipped( 'node is not installed here' );
		}
		$tmp = tempnam( sys_get_temp_dir(), 'zsv210-js-' );
		file_put_contents( $tmp, (string) json_encode( $input ) );
		$cmd = escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/support/' . $script );
		foreach ( $files as $f ) {
			$cmd .= ' ' . escapeshellarg( $f );
		}
		$out = shell_exec( $cmd . ' ' . escapeshellarg( $tmp ) . ' 2>&1' ); // sentinel:allow every argument is escapeshellarg()'d; runs the repository's own scripts
		unlink( $tmp );
		$res = json_decode( (string) $out, true );
		$this->assertIsArray( $res, 'node answered: ' . substr( (string) $out, 0, 600 ) );
		return $res;
	}

	private function logic( array $calls ): array {
		return $this->node( 'surveys-widget-logic.js', array( self::asset( 'js/widget-logic.js' ) ), $calls );
	}

	private function page( array $scenarios ): array {
		$res = $this->node( 'surveys-widget-page.js', array( self::asset( 'js/widget-logic.js' ), self::asset( 'js/widget.js' ) ), $scenarios );
		foreach ( $res as $name => $seen ) {
			$this->assertArrayNotHasKey( 'crashed', $seen, $name . ': ' . ( $seen['crashed'] ?? '' ) );
		}
		return $res;
	}

	private static function row( int $id, string $name, string $status, array $more = array() ): array {
		return array_merge(
			array( 'id' => $id, 'name' => $name, 'city' => '', 'status' => $status, 'invited' => false, 'emailed_at' => '', 'reviewed' => false, 'closed' => false, 'notes' => '', 'work' => '', 'date' => '2026-03-01 10:00:00' ),
			$more
		);
	}

	/** Eight customers: one nobody has handled, with notes; the rest each differ in one way. */
	private static function rows(): array {
		return array(
			self::row( 1, 'Riley Example', 'needs_attention', array( 'city' => 'Springfield', 'notes' => 'Good, will call back later.', 'work' => 'Annual service' ) ),
			self::row( 2, 'Sam Example', 'satisfied', array( 'notes' => 'Happy, send link', 'work' => 'Repair' ) ),
			self::row( 3, 'Casey Example', 'satisfied', array( 'invited' => true, 'emailed_at' => '2026-03-03 09:00:00', 'closed' => true, 'notes' => 'Happy, send link' ) ),
			self::row( 4, 'Blake Example', 'satisfied', array( 'reviewed' => true, 'notes' => 'Great' ) ),
			self::row( 5, 'Robin Example', 'needs_attention', array( 'closed' => true, 'notes' => 'Fine' ) ),
			self::row( 6, 'Devin Example', 'not_contacted', array( 'work' => 'Installation' ) ),
			self::row( 7, 'Morgan Example', 'not_contacted', array( 'notes' => 'Error: the CRM did not answer' ) ),
			self::row( 8, 'Alex <b>Example</b>', 'no_primary_account', array( 'notes' => '<img src=x onerror="window.x=1"> happy' ) ),
		);
	}

	private static function row_html( string $list, int $id ): string {
		$at = strpos( $list, '<div class="zsv-row" data-id="' . $id . '">' );
		if ( false === $at ) {
			return '';
		}
		$end = strpos( $list, '<div class="zsv-row" data-id="', $at + 1 );
		return false === $end ? substr( $list, $at ) : substr( $list, $at, $end - $at );
	}

	/* ── A. decisions and sentences ──────────────────────────────────────────────── */

	public function test_whose_notes_are_showing_and_what_the_line_under_a_customer_says(): void {
		$r     = self::rows();
		$calls = array();
		foreach ( $r as $row ) {
			$calls[] = array( 'first' . $row['id'], 'notesFirst', array( $row ) );
			$calls[] = array( 'hint' . $row['id'], 'hintLabel', array( $row, false ) );
			$calls[] = array( 'open' . $row['id'], 'hintLabel', array( $row, true ) );
		}
		$calls[] = array( 'blank', 'notesFirst', array( self::row( 9, 'Riley Example', 'satisfied', array( 'notes' => "  \n " ) ) ) );
		$calls[] = array( 'nothing', 'hintLabel', array( self::row( 9, 'Riley Example', 'satisfied', array( 'date' => '' ) ), false ) );
		$calls[] = array( 'none', 'notesFirst', array( null ) );
		$got     = $this->logic( $calls );

		$this->assertTrue( $got['first1'], 'nobody has handled them and there are notes: showing' );
		$this->assertTrue( $got['first2'] );
		$this->assertTrue( $got['first8'] );
		$this->assertFalse( $got['first3'], 'e-mailed: handled' );
		$this->assertFalse( $got['first4'], 'reviewed: handled' );
		$this->assertFalse( $got['first5'], 'closed in the CRM: handled' );
		$this->assertFalse( $got['first6'], 'no notes to show' );
		$this->assertFalse( $got['first7'], 'a message from a failed sync is not the operator\'s note' );
		$this->assertFalse( $got['blank'] );
		$this->assertFalse( $got['none'] );

		$this->assertSame( "Call notes \u{00B7} Tap for job details", $got['hint1'] );
		$this->assertSame( 'Tap for call notes', $got['hint3'] );
		$this->assertSame( 'Tap for call notes', $got['hint7'] );
		$this->assertSame( 'Tap for job details', $got['hint6'] );
		$this->assertSame( '', $got['nothing'], 'nothing to open, no line' );
		foreach ( $r as $row ) {
			$this->assertSame( 'Tap to close', $got[ 'open' . $row['id'] ] );
		}
	}

	public function test_who_the_send_button_offers(): void {
		$got = $this->logic( array( array( 'ids', 'sendable', array( self::rows() ) ), array( 'none', 'sendable', array( null ) ) ) );
		$this->assertSame( array( 2, 8 ), array_column( $got['ids'], 'id' ), 'satisfied or no primary account, never one already e-mailed or reviewed' );
		$this->assertSame( array(), $got['none'] );
	}

	public function test_the_send_line(): void {
		$sent    = array( 'id' => 1, 'result' => 'sent', 'reason' => '', 'send_no' => 1 );
		$already = array( 'id' => 2, 'result' => 'skipped', 'reason' => 'already_invited', 'already_emailed' => true, 'emailed_at' => '2026-03-08 10:00:00' );
		$running = array( 'id' => 3, 'result' => 'skipped', 'reason' => 'send_in_progress', 'in_progress' => true );
		$failed  = array( 'id' => 4, 'result' => 'failed', 'reason' => 'send_failed' );
		$held    = array( 'id' => 5, 'result' => 'skipped', 'reason' => 'status_needs_attention' );
		$skip    = static function ( string $why ): array {
			return array( 'id' => 6, 'result' => 'skipped', 'reason' => $why );
		};
		$got = $this->logic( array(
			array( 'all', 'sendSummary', array( array( $sent, $sent ), 2 ) ),
			array( 'one_already', 'sendSummary', array( array( $sent, $already ), 2 ) ),
			array( 'two_already', 'sendSummary', array( array( $already, $already ), 2 ) ),
			array( 'running', 'sendSummary', array( array( $sent, $running ), 2 ) ),
			array( 'failed', 'sendSummary', array( array( $failed, $sent ), 2 ) ),
			array( 'two_failed', 'sendSummary', array( array( $failed, $failed ), 2 ) ),
			array( 'held', 'sendSummary', array( array( $held, $held, $sent ), 3 ) ),
			array( 'lock', 'sendSummary', array( array( $skip( 'lock_unavailable' ) ), 1 ) ),
			array( 'unreadable', 'sendSummary', array( array( $skip( 'row_unreadable' ) ), 1 ) ),
			array( 'closed', 'sendSummary', array( array( $skip( 'crm_closed' ), $skip( 'no_email' ) ), 2 ) ),
			array( 'nothing', 'sendSummary', array( array(), 0 ) ),
		) );
		$this->assertSame( 'Sent 2 of 2.', $got['all'] );
		$this->assertSame( 'Sent 1 of 2. 1 was already e-mailed and was not sent again.', $got['one_already'] );
		$this->assertSame( 'Sent 0 of 2. 2 were already e-mailed and were not sent again.', $got['two_already'] );
		$this->assertSame( 'Sent 1 of 2. A send to 1 of them was already running: not sent again.', $got['running'] );
		$this->assertSame( 'Sent 1 of 2. 1 could not be sent: check that one before sending again.', $got['failed'] );
		$this->assertSame( 'Sent 0 of 2. 2 could not be sent: check those before sending again.', $got['two_failed'] );
		$this->assertSame( 'Sent 1 of 3. 2 were held back; see the list.', $got['held'] );
		$this->assertSame( 'Sent 0 of 1. Could not make sure a customer is e-mailed only once just now: not sent. Try again in a minute.', $got['lock'] );
		$this->assertSame( 'Sent 0 of 1. Could not read a customer\'s record just now: not sent.', $got['unreadable'] );
		$this->assertSame( 'Sent 0 of 2. A lead closed as lost or cancelled in the CRM was not e-mailed.', $got['closed'], 'one reason is said, the first' );
		$this->assertSame( 'Sent 0 of 0.', $got['nothing'] );
	}

	public function test_when_a_review_check_asks_again_by_itself(): void {
		$tot = static function ( int $passes ): array {
			return array( 'checked' => 10, 'found' => 0, 'passes' => $passes );
		};
		$got = $this->logic( array(
			array( 'max', 'REVIEW_MAX_PASSES', array() ),
			array( 'more', 'shouldCheckAgain', array( $tot( 1 ), array( 'checked' => 10, 'deferred' => 5 ) ) ),
			array( 'done', 'shouldCheckAgain', array( $tot( 1 ), array( 'checked' => 10, 'deferred' => 0 ) ) ),
			array( 'no_answer', 'shouldCheckAgain', array( $tot( 1 ), array( 'checked' => 10, 'deferred' => 5, 'errors' => 1 ) ) ),
			array( 'nobody', 'shouldCheckAgain', array( $tot( 1 ), array( 'checked' => 0, 'deferred' => 5 ) ) ),
			array( 'cannot_run', 'shouldCheckAgain', array( $tot( 1 ), array( 'checked' => 10, 'deferred' => 5, 'error' => 'The review bridge is not set up.' ) ) ),
			array( 'seventh', 'shouldCheckAgain', array( $tot( 7 ), array( 'checked' => 1, 'deferred' => 5 ) ) ),
			array( 'eighth', 'shouldCheckAgain', array( $tot( 8 ), array( 'checked' => 1, 'deferred' => 5 ) ) ),
			array( 'empty', 'shouldCheckAgain', array( $tot( 1 ), null ) ),
		) );
		$this->assertSame( 8, $got['max'] );
		$this->assertTrue( $got['more'], 'the last pass got somewhere and only time was in the way' );
		$this->assertFalse( $got['done'], 'nothing is left' );
		$this->assertFalse( $got['no_answer'], 'the review site gave no answer for somebody: asking at once is only refused again' );
		$this->assertFalse( $got['nobody'], 'a pass that checked nobody' );
		$this->assertFalse( $got['cannot_run'] );
		$this->assertTrue( $got['seventh'] );
		$this->assertFalse( $got['eighth'], 'a press always ends' );
		$this->assertFalse( $got['empty'] );
	}

	public function test_the_last_line_of_a_review_check(): void {
		$t   = static function ( int $checked, int $found, int $passes = 1 ): array {
			return array( 'checked' => $checked, 'found' => $found, 'passes' => $passes );
		};
		$got = $this->logic( array(
			array( 'plain', 'reviewResultText', array( $t( 36, 0, 2 ), array( 'checked' => 7 ) ) ),
			array( 'found', 'reviewResultText', array( $t( 25, 2, 2 ), array( 'checked' => 5, 'found' => 1 ) ) ),
			array( 'one', 'reviewResultText', array( $t( 1, 1 ), array( 'checked' => 1, 'found' => 1 ) ) ),
			array( 'errors', 'reviewResultText', array( $t( 10, 0 ), array( 'checked' => 10, 'errors' => 3 ) ) ),
			array( 'one_error', 'reviewResultText', array( $t( 10, 0 ), array( 'checked' => 10, 'errors' => 1 ) ) ),
			array( 'left', 'reviewResultText', array( $t( 8, 0, 8 ), array( 'checked' => 1, 'deferred' => 5 ) ) ),
			array( 'one_left', 'reviewResultText', array( $t( 8, 0 ), array( 'checked' => 8, 'deferred' => 1 ) ) ),
			array( 'none_started', 'reviewResultText', array( $t( 0, 0 ), array( 'deferred' => 4 ) ) ),
			array( 'nobody', 'reviewResultText', array( $t( 0, 0 ), array() ) ),
			array( 'unavailable', 'reviewResultText', array( $t( 0, 0 ), array( 'error' => 'The review bridge is not set up.' ) ) ),
			array( 'stopped', 'reviewStoppedText', array( $t( 12, 0 ), 'The review bridge is not set up.' ) ),
			array( 'stopped_found', 'reviewStoppedText', array( $t( 12, 1 ), '' ) ),
			array( 'refused', 'reviewFailedText', array( 'Unauthorized' ) ),
			array( 'failed', 'reviewFailedText', array( 'The server did not answer properly. Try again in a minute.' ) ),
			array( 'failed_blank', 'reviewFailedText', array( '' ) ),
			array( 'first_failed', 'reviewErrorText', array( $t( 0, 0, 0 ), 'Unauthorized' ) ),
			array( 'later_failed', 'reviewErrorText', array( $t( 12, 0, 1 ), 'offline' ) ),
		) );
		$this->assertSame( 'Checked 36 customers. No new reviews.', $got['plain'] );
		$this->assertSame( 'Checked 25 customers. 2 new reviews.', $got['found'] );
		$this->assertSame( 'Checked 1 customer. 1 new review.', $got['one'] );
		$this->assertSame( 'Checked 10 customers. No new reviews. 3 could not be checked just now (no answer from the review site) and are checked again automatically.', $got['errors'] );
		$this->assertSame( 'Checked 10 customers. No new reviews. 1 could not be checked just now (no answer from the review site) and is checked again automatically.', $got['one_error'] );
		$this->assertSame( 'Checked 8 customers. No new reviews. 5 were not checked this time and are checked again automatically.', $got['left'] );
		$this->assertSame( 'Checked 8 customers. No new reviews. 1 was not checked this time and is checked again automatically.', $got['one_left'] );
		$this->assertSame( 'Checked 0 customers. No new reviews. 4 were not checked this time and are checked again automatically.', $got['none_started'] );
		$this->assertSame( 'No customers to check right now.', $got['nobody'] );
		$this->assertSame( 'Review check unavailable: The review bridge is not set up.', $got['unavailable'] );
		$this->assertSame( 'Checked 12 customers, no new reviews, then the check stopped: The review bridge is not set up. Press Check reviews again.', $got['stopped'] );
		$this->assertSame( 'Checked 12 customers, 1 new review, then the check stopped: no answer. Press Check reviews again.', $got['stopped_found'] );
		$this->assertSame( 'Only an administrator can check reviews.', $got['refused'] );
		$this->assertSame( 'Review check failed: The server did not answer properly. Try again in a minute.', $got['failed'] );
		$this->assertSame( 'Review check failed: no answer', $got['failed_blank'] );
		$this->assertSame( 'Only an administrator can check reviews.', $got['first_failed'] );
		$this->assertSame( 'Checked 12 customers, no new reviews, then the check stopped: offline. Press Check reviews again.', $got['later_failed'] );
	}

	public function test_the_words_of_the_google_reviews_panel(): void {
		$cand = array( 'id' => 11, 'name' => 'Jordan Linden', 'reviewed' => false );
		$got  = $this->logic( array(
			array( 'watch', 'grModeText', array( false ) ),
			array( 'auto', 'grModeText', array( true ) ),
			array( 'why_known', 'grWhy', array( 'not_approved' ) ),
			array( 'why_new', 'grWhy', array( 'http_500' ) ),
			array( 'why_odd', 'grWhy', array( '<b>x</b>' ) ),
			array( 'last_ran', 'grLastText', array( array( 'at' => 'Mar 9, 2:00 PM', 'ran' => true, 'why' => '' ) ) ),
			array( 'last_more', 'grLastText', array( array( 'at' => 'Mar 9, 2:00 PM', 'ran' => true, 'why' => 'more_next_run' ) ) ),
			array( 'last_not', 'grLastText', array( array( 'at' => 'Mar 9, 2:00 PM', 'ran' => false, 'why' => 'not_approved' ) ) ),
			array( 'last_never', 'grLastText', array( null ) ),
			array( 'would', 'grStatus', array( array( 'status' => 'would', 'cands' => array( $cand ) ) ) ),
			array( 'possible', 'grStatus', array( array( 'status' => 'possible', 'cands' => array( $cand, array( 'id' => 12, 'name' => 'Jordan Larch', 'reviewed' => true ) ) ) ) ),
			array( 'gone', 'grStatus', array( array( 'status' => 'would', 'cands' => array() ) ) ),
			array( 'recorded', 'grStatus', array( array( 'status' => 'recorded', 'lead' => $cand ) ) ),
			array( 'confirmed', 'grStatus', array( array( 'status' => 'confirmed', 'lead' => $cand ) ) ),
			array( 'dismissed', 'grStatus', array( array( 'status' => 'dismissed', 'cands' => array( $cand ) ) ) ),
			array( 'none', 'grStatus', array( array( 'status' => 'none' ) ) ),
			array( 'stars', 'stars', array( 4 ) ),
			array( 'stars_odd', 'stars', array( 99 ) ),
			array( 'label', 'grOfferLabel', array( $cand, array( $cand ) ) ),
			array( 'label_day', 'grOfferLabel', array( array( 'id' => 11, 'name' => 'Jordan Linden', 'added' => '2026-03-02' ), array( array( 'id' => 11, 'name' => 'Jordan Linden', 'added' => '2026-03-02' ), array( 'id' => 12, 'name' => 'Jordan Linden', 'added' => '2026-02-10' ) ) ) ),
			array( 'label_twin', 'grOfferLabel', array( array( 'id' => 12, 'name' => 'Jordan Linden', 'added' => '2026-03-02' ), array( array( 'id' => 11, 'name' => 'Jordan Linden', 'added' => '2026-03-02' ), array( 'id' => 12, 'name' => 'Jordan Linden', 'added' => '2026-03-02' ) ) ) ),
		) );
		$this->assertSame( 'Jordan Linden', $got['label'] );
		$this->assertSame( "Jordan Linden \u{00B7} added 2026-03-02", $got['label_day'], 'two customers of one name are told apart by the day' );
		$this->assertSame( "Jordan Linden \u{00B7} added 2026-03-02 \u{00B7} #12", $got['label_twin'], 'and by their number when the day is the same too' );
		$this->assertSame( 'Watch-only: nothing is recorded until confirmed', $got['watch'] );
		$this->assertSame( 'Automatic: a sure match is recorded', $got['auto'] );
		$this->assertSame( 'waiting for Google to approve access', $got['why_known'] );
		$this->assertSame( 'http 500', $got['why_new'] );
		$this->assertSame( 'bxb', $got['why_odd'], 'a word the page does not know is shown as letters only' );
		$this->assertSame( 'checked Mar 9, 2:00 PM', $got['last_ran'] );
		$this->assertSame( 'checked Mar 9, 2:00 PM', $got['last_more'] );
		$this->assertSame( "checked Mar 9, 2:00 PM \u{00B7} waiting for Google to approve access", $got['last_not'] );
		$this->assertSame( '', $got['last_never'] );
		$this->assertSame( array( 'kind' => 'ask', 'text' => 'Would record on:', 'offer' => array( $cand ) ), $got['would'] );
		$this->assertSame( 'Could be:', $got['possible']['text'] );
		$this->assertCount( 2, $got['possible']['offer'] );
		$this->assertSame( 'none', $got['gone']['kind'], 'nobody left to offer' );
		$this->assertSame( array( 'kind' => 'done', 'text' => 'Recorded on Jordan Linden (automatic)', 'offer' => array() ), $got['recorded'] );
		$this->assertSame( 'Recorded on Jordan Linden', $got['confirmed']['text'] );
		$this->assertSame( array( 'kind' => 'none', 'text' => 'Not a surveyed customer', 'offer' => array() ), $got['dismissed'] );
		$this->assertSame( 'No surveyed customer matched', $got['none']['text'] );
		$this->assertSame( "\u{2605}\u{2605}\u{2605}\u{2605}\u{2606}", $got['stars'] );
		$this->assertSame( "\u{2605}\u{2605}\u{2605}\u{2605}\u{2605}", $got['stars_odd'] );
	}

	/* ── B. the shipped widget on a stand-in page ────────────────────────────────── */

	public function test_call_notes_are_showing_on_a_customer_nobody_has_handled(): void {
		$seen = $this->page( array( 'p' => array( 'rows' => self::rows(), 'steps' => array( array( 'hint' => 1 ), array( 'hint' => 1 ), array( 'hint' => 3 ), array( 'hint' => 3 ) ) ) ) )['p'];
		$list = $seen['loaded']['list'];

		$one = self::row_html( $list, 1 );
		$this->assertStringContainsString( 'class="zsv-row-panel zsv-notes-first"', $one, 'drawn with the notes showing, the rest folded' );
		$this->assertStringContainsString( '<div class="zsv-row-notes">Good, will call back later.</div>', $one );
		$this->assertStringContainsString( 'aria-expanded="false"', $one );
		$this->assertStringContainsString( "Call notes \u{00B7} Tap for job details", $one );
		foreach ( array( 3, 4, 5, 7 ) as $id ) {
			$html = self::row_html( $list, $id );
			$this->assertStringContainsString( 'class="zsv-row-panel"', $html, "customer $id stays folded" );
			$this->assertStringNotContainsString( 'zsv-notes-first', $html );
			$this->assertStringContainsString( 'Tap for call notes', $html );
		}
		$this->assertStringContainsString( 'Tap for job details', self::row_html( $list, 6 ) );
		$this->assertStringNotContainsString( 'zsv-row-notes', self::row_html( $list, 6 ) );

		// Names and notes are text, never markup.
		$eight = self::row_html( $list, 8 );
		$this->assertStringContainsString( 'Alex &lt;b&gt;Example&lt;/b&gt;', $eight );
		$this->assertStringContainsString( '&lt;img src=x onerror=&quot;window.x=1&quot;&gt; happy', $eight );
		$this->assertStringNotContainsString( '<img', $list );
		$this->assertStringNotContainsString( '<b>', $list );

		// A tap opens the whole panel; a second tap folds it back to its notes.
		$this->assertSame( array( 'classes' => array( 'zsv-expanded', 'zsv-notes-first' ), 'label' => 'Tap to close', 'expanded' => 'true' ), $seen['steps'][0]['tap'] );
		$this->assertSame( array( 'classes' => array( 'zsv-notes-first' ), 'label' => "Call notes \u{00B7} Tap for job details", 'expanded' => 'false' ), $seen['steps'][1]['tap'] );
		$this->assertSame( array( 'classes' => array( 'zsv-expanded' ), 'label' => 'Tap to close', 'expanded' => 'true' ), $seen['steps'][2]['tap'] );
		$this->assertSame( array( 'classes' => array(), 'label' => 'Tap for call notes', 'expanded' => 'false' ), $seen['steps'][3]['tap'] );

		$this->assertSame( array( 'zsv_stats', 'zsv_recent', 'zsv_greviews_list' ), array_column( $seen['requests'], 'action' ), 'showing the notes asks the site for nothing more' );
		$this->assertSame( 'Send 2 review e-mails', $seen['loaded']['send'] );
	}

	public function test_one_press_of_check_reviews_goes_to_the_end(): void {
		$r = static function ( array $d ): array {
			return array( 'success' => true, 'data' => $d + array( 'checked' => 0, 'found' => 0, 'errors' => 0, 'deferred' => 0 ) );
		};
		$not_set_up = 'The review bridge is not set up.';
		$cases      = array(
			'two passes'        => array( array( $r( array( 'checked' => 29, 'deferred' => 7 ) ), $r( array( 'checked' => 7 ) ) ), 2, 'Checked 36 customers. No new reviews.' ),
			'found'             => array( array( $r( array( 'checked' => 20, 'found' => 1, 'deferred' => 5 ) ), $r( array( 'checked' => 5, 'found' => 1 ) ) ), 2, 'Checked 25 customers. 2 new reviews.' ),
			'no answer'         => array( array( $r( array( 'checked' => 10, 'errors' => 3 ) ) ), 1, 'Checked 10 customers. No new reviews. 3 could not be checked just now (no answer from the review site) and are checked again automatically.' ),
			'no answer + left'  => array( array( $r( array( 'checked' => 10, 'errors' => 3, 'deferred' => 4 ) ), $r( array( 'checked' => 4 ) ) ), 1, 'Checked 10 customers. No new reviews. 3 could not be checked just now (no answer from the review site) and are checked again automatically. 4 were not checked this time and are checked again automatically.' ),
			'nobody started'    => array( array( $r( array( 'deferred' => 4 ) ) ), 1, 'Checked 0 customers. No new reviews. 4 were not checked this time and are checked again automatically.' ),
			'stops part-way'    => array( array( $r( array( 'checked' => 12, 'deferred' => 3 ) ), $r( array( 'error' => $not_set_up ) ) ), 2, 'Checked 12 customers, no new reviews, then the check stopped: The review bridge is not set up. Press Check reviews again.' ),
			'cannot run'        => array( array( $r( array( 'error' => $not_set_up ) ) ), 1, 'Review check unavailable: The review bridge is not set up.' ),
			'always ends'       => array( array( $r( array( 'checked' => 1, 'deferred' => 5 ) ) ), 8, 'Checked 8 customers. No new reviews. 5 were not checked this time and are checked again automatically.' ),
			'nobody to check'   => array( array( $r( array() ) ), 1, 'No customers to check right now.' ),
			'one'               => array( array( $r( array( 'checked' => 1, 'found' => 1 ) ) ), 1, 'Checked 1 customer. 1 new review.' ),
			'refused'           => array( array( array( 'success' => false, 'data' => 'Unauthorized' ) ), 1, 'Only an administrator can check reviews.' ),
			'fails part-way'    => array( array( $r( array( 'checked' => 12, 'deferred' => 3 ) ), '502' ), 2, 'Checked 12 customers, no new reviews, then the check stopped: The server did not answer properly. Try again in a minute. Press Check reviews again.' ),
			'fails at once'     => array( array( 'offline' ), 1, 'Review check failed: offline' ),
		);
		$scenarios  = array();
		foreach ( $cases as $name => $c ) {
			$scenarios[ $name ] = array( 'rows' => self::rows(), 'queue' => array( 'zsv_check_reviews' => $c[0] ), 'steps' => array( array( 'press' => 'zsv-check-reviews' ) ) );
		}
		$seen = $this->page( $scenarios );
		foreach ( $cases as $name => $c ) {
			$s      = $seen[ $name ];
			$passes = array_values( array_filter( $s['requests'], static function ( $f ) { return 'zsv_check_reviews' === $f['action']; } ) );
			$this->assertCount( $c[1], $passes, "$name: how many times the page asked" );
			$this->assertSame( $c[2], $s['steps'][0]['review_status'], $name );
			$this->assertSame( '', $s['steps'][0]['progress'], "$name: the result sits under the buttons, the progress line is cleared" );
			$this->assertSame( array( 'run' => false, 'sync' => false, 'check' => false, 'send' => false ), $s['steps'][0]['disabled'], "$name: every button is free again" );
			$this->assertNull( $s['steps'][0]['busy'] );
			foreach ( $s['during_check'] as $i => $d ) {
				$this->assertSame( array( 'run' => true, 'sync' => true, 'check' => true, 'busy' => 'true' ), $d, "$name: while pass " . ( $i + 1 ) . ' runs, the buttons are held' );
			}
			foreach ( $passes as $f ) {
				$this->assertSame( array( 'nonce' => 'n-1', 'action' => 'zsv_check_reviews' ), $f, "$name: a pass sends nothing but its nonce" );
			}
			$this->assertSame( 0, count( array_filter( $s['requests'], static function ( $f ) { return 0 === strpos( $f['action'], 'zsv_send' ); } ) ), "$name: a review check sends no e-mail" );
		}
		// A found review redraws the list; a check that found nothing leaves it alone.
		$this->assertSame( 2, count( array_filter( $seen['found']['requests'], static function ( $f ) { return 'zsv_recent' === $f['action']; } ) ) );
		$this->assertSame( 1, count( array_filter( $seen['two passes']['requests'], static function ( $f ) { return 'zsv_recent' === $f['action']; } ) ) );
	}

	public function test_the_send_button_never_asks_to_email_anybody_again(): void {
		$ok       = static function ( array $d ): array {
			return array( 'success' => true, 'data' => $d );
		};
		$seen     = $this->page( array(
			'p' => array(
				'rows'  => self::rows(),
				'queue' => array( 'zsv_send_invite' => array(
					$ok( array( 'id' => 2, 'result' => 'sent', 'reason' => '', 'crm' => 'won', 'send_no' => 1 ) ),
					$ok( array( 'id' => 8, 'result' => 'skipped', 'reason' => 'already_invited', 'crm' => 'none', 'already_emailed' => true, 'emailed_at' => '2026-03-08 10:00:00' ) ),
				) ),
				'steps' => array( array( 'press' => 'zsv-send' ) ),
			),
			'lost' => array(
				'rows'  => self::rows(),
				'queue' => array( 'zsv_send_invite' => array( 'offline', $ok( array( 'id' => 8, 'result' => 'skipped', 'reason' => 'send_in_progress', 'crm' => 'none', 'in_progress' => true ) ) ) ),
				'steps' => array( array( 'press' => 'zsv-send' ) ),
			),
		) );
		$sends = array_values( array_filter( $seen['p']['requests'], static function ( $f ) { return 'zsv_send_invite' === $f['action']; } ) );
		$this->assertSame( array( '2', '8' ), array_column( $sends, 'lead_id' ), 'one customer per request, in order' );
		foreach ( $sends as $f ) {
			$this->assertSame( array( 'nonce', 'lead_id', 'action' ), array_keys( $f ), 'the request names the customer and nothing else: no send_again' );
		}
		$this->assertSame( 'Sent 1 of 2. 1 was already e-mailed and was not sent again.', $seen['p']['steps'][0]['progress'] );
		$logs = array_values( array_filter( $seen['p']['requests'], static function ( $f ) { return 'zsv_send_log' === $f['action']; } ) );
		$this->assertCount( 1, $logs, 'one summary of the press goes back for the log' );
		$this->assertSame( array( 'sent', 'skipped' ), array_column( json_decode( $logs[0]['results'], true ), 'result' ) );

		// A request that got no answer may have been sent: it is not repeated, and the next customer still goes.
		$sends = array_values( array_filter( $seen['lost']['requests'], static function ( $f ) { return 'zsv_send_invite' === $f['action']; } ) );
		$this->assertSame( array( '2', '8' ), array_column( $sends, 'lead_id' ) );
		$this->assertSame( 'Sent 0 of 2. A send to 1 of them was already running: not sent again. 1 could not be sent: check that one before sending again.', $seen['lost']['steps'][0]['progress'] );

		// And in the file: the words appear in its comments only.
		foreach ( explode( "\n", (string) file_get_contents( self::asset( 'js/widget.js' ) ) ) as $n => $line ) {
			if ( false === strpos( $line, 'send_again' ) ) {
				continue;
			}
			$this->assertMatchesRegularExpression( '#^\s*(//|\*|/\*)#', $line, 'widget.js line ' . ( $n + 1 ) . ' names send_again in code' );
		}
	}

	private static function panel(): array {
		return array(
			'ready' => true, 'automatic' => false, 'can_admin' => true, 'lookback' => 60,
			'last'  => array( 'at' => 'Mar 9, 2:00 PM', 'ran' => true, 'why' => '' ),
			'items' => array(
				array( 'id' => 'g1', 'reviewer' => 'Jordan <i>Linden</i>', 'stars' => 5, 'date' => 'Mar 9', 'snippet' => 'Tidy <script>x()</script> work', 'status' => 'would', 'lead' => null, 'cands' => array( array( 'id' => 11, 'name' => 'Jordan Linden', 'reviewed' => false ) ) ),
				array( 'id' => 'g2', 'reviewer' => 'Morgan M', 'stars' => 4, 'date' => 'Mar 8', 'snippet' => '', 'status' => 'possible', 'lead' => null, 'cands' => array( array( 'id' => 12, 'name' => 'Morgan Moss', 'reviewed' => false ), array( 'id' => 13, 'name' => 'Morgan Mill', 'reviewed' => true ) ) ),
				array( 'id' => 'g3', 'reviewer' => 'Casey Bramble', 'stars' => 5, 'date' => 'Mar 7', 'snippet' => 'Great', 'status' => 'confirmed', 'lead' => array( 'id' => 14, 'name' => 'Casey Bramble', 'reviewed' => true ), 'cands' => array() ),
				array( 'id' => 'g4', 'reviewer' => '', 'stars' => 3, 'date' => 'Mar 6', 'snippet' => '', 'status' => 'none', 'lead' => null, 'cands' => array() ),
			),
		);
	}

	public function test_the_google_reviews_panel(): void {
		$gr      = self::panel();
		$after   = $gr;
		$after['items'][0] = array( 'status' => 'confirmed', 'lead' => array( 'id' => 11, 'name' => 'Jordan Linden', 'reviewed' => true ), 'cands' => array() ) + $gr['items'][0];
		$twins   = array( 'id' => 't1', 'reviewer' => 'Jordan Linden', 'stars' => 5, 'date' => 'Mar 9', 'snippet' => '', 'status' => 'possible', 'lead' => null, 'cands' => array(
			array( 'id' => 21, 'name' => 'Jordan Linden', 'reviewed' => false, 'added' => '2026-03-02' ),
			array( 'id' => 22, 'name' => 'Jordan Linden', 'reviewed' => false, 'added' => '2026-02-10' ),
			array( 'id' => 23, 'name' => 'Jordan Linden', 'reviewed' => true, 'added' => '2026-02-10' ),
		) );
		$confirm = array( 'panel' => array( 'cls' => 'zsv-gr-confirm', 'review' => 'g1', 'lead' => '11', 'name' => 'Jordan Linden' ) );
		$mode_on = array( 'panel' => array( 'id' => 'zsv-gr-mode', 'on' => '1' ) );
		$seen    = $this->page( array(
			'hidden'  => array( 'rows' => self::rows(), 'gr' => array( 'ready' => false ) ),
			'shown'   => array( 'rows' => self::rows(), 'gr' => $gr, 'queue' => array( 'zsv_greviews_confirm' => array( array( 'success' => true, 'data' => $after ) ) ), 'steps' => array( $confirm ) ),
			'twins'   => array( 'rows' => self::rows(), 'gr' => array( 'items' => array( $twins ) ) + $gr ),
			'reader'  => array( 'rows' => self::rows(), 'gr' => array( 'can_admin' => false ) + $gr ),
			'refused' => array( 'rows' => self::rows(), 'gr' => $gr, 'queue' => array( 'zsv_greviews_confirm' => array( array( 'success' => false, 'data' => 'That customer already has a review recorded.' ) ) ), 'steps' => array( $confirm ) ),
			'cancel'  => array( 'rows' => self::rows(), 'gr' => $gr, 'answer_ok' => false, 'steps' => array( $confirm, $mode_on, array( 'panel' => array( 'cls' => 'zsv-gr-dismiss', 'review' => 'g2' ) ) ) ),
			'admin'   => array( 'rows' => self::rows(), 'gr' => $gr, 'queue' => array( 'zsv_greviews_mode' => array( array( 'success' => true, 'data' => array( 'automatic' => true ) + $gr ), array( 'success' => true, 'data' => $gr ) ) ), 'steps' => array( $mode_on, array( 'panel' => array( 'id' => 'zsv-gr-mode', 'on' => '0' ) ), array( 'panel' => array( 'id' => 'zsv-gr-now' ) ) ) ),
			'other'   => array( 'rows' => self::rows(), 'gr' => array( 'can_admin' => false, 'items' => array(), 'last' => array( 'at' => 'Mar 9, 2:00 PM', 'ran' => false, 'why' => 'not_approved' ) ) + $gr ),
		) );

		$this->assertNull( $seen['hidden']['loaded']['greviews'], 'not connected: the panel stays hidden' );

		$html = $seen['shown']['loaded']['greviews'];
		$this->assertStringContainsString( 'Watch-only: nothing is recorded until confirmed', $html );
		$this->assertStringContainsString( 'checked Mar 9, 2:00 PM', $html );
		$this->assertStringContainsString( 'Jordan &lt;i&gt;Linden&lt;/i&gt;', $html, 'a reviewer\'s name is text' );
		$this->assertStringContainsString( 'Tidy &lt;script&gt;x()&lt;/script&gt; work', $html, 'and so are the review\'s words' );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<i>', $html );
		$this->assertSame( 2, substr_count( $html, 'zsv-gr-confirm' ), 'one Confirm for each customer offered who has no review yet' );
		$this->assertStringContainsString( 'data-review="g1" data-lead="11" data-name="Jordan Linden">Confirm: Jordan Linden</button>', $html );
		$this->assertStringContainsString( 'Morgan Mill (already has a review)', $html, 'a customer who already has a review is named, not offered' );
		$this->assertStringNotContainsString( 'data-lead="13"', $html );
		$this->assertSame( 2, substr_count( $html, 'zsv-gr-dismiss' ) );
		$this->assertStringContainsString( 'Recorded on Casey Bramble', $html );
		$this->assertStringContainsString( 'No surveyed customer matched', $html );
		$this->assertStringContainsString( 'A Google user', $html, 'a review with no name shown' );
		$this->assertStringContainsString( 'aria-label="5 stars"', $html );
		$this->assertStringContainsString( 'id="zsv-gr-now"', $html );
		$this->assertStringContainsString( 'Turn on automatic recording', $html );

		// Confirm asks first, then names the review and the customer offered.
		$this->assertCount( 1, $seen['shown']['questions'] );
		$this->assertStringContainsString( 'Record this Google review on Jordan Linden?', $seen['shown']['questions'][0] );
		$post = array_values( array_filter( $seen['shown']['requests'], static function ( $f ) { return 'zsv_greviews_confirm' === $f['action']; } ) );
		$this->assertSame( array( array( 'nonce' => 'n-1', 'review_id' => 'g1', 'lead_id' => '11', 'action' => 'zsv_greviews_confirm' ) ), $post );

		$this->assertStringContainsString( 'Recorded on Jordan Linden', (string) $seen['shown']['steps'][0]['greviews'], 'the panel is drawn again with what the site answered' );
		$this->assertSame( 1, substr_count( (string) $seen['shown']['steps'][0]['greviews'], 'zsv-gr-confirm' ) );
		$this->assertSame( 2, count( array_filter( $seen['shown']['requests'], static function ( $f ) { return 'zsv_recent' === $f['action']; } ) ), 'and the list of customers with it' );

		// The site said no: the panel stays as it was and the reason is said.
		$this->assertSame( 'That customer already has a review recorded.', $seen['refused']['steps'][0]['progress'] );
		$this->assertSame( $seen['refused']['loaded']['greviews'], $seen['refused']['steps'][0]['greviews'] );

		// Two customers of one name: no two buttons read the same.
		$html = $seen['twins']['loaded']['greviews'];
		$this->assertStringContainsString( "Confirm: Jordan Linden \u{00B7} added 2026-03-02</button>", $html );
		$this->assertStringContainsString( "Confirm: Jordan Linden \u{00B7} added 2026-02-10 \u{00B7} #22</button>", $html );
		$this->assertStringContainsString( "Jordan Linden \u{00B7} added 2026-02-10 \u{00B7} #23 (already has a review)", $html );
		$this->assertStringContainsString( "data-lead=\"22\" data-name=\"Jordan Linden \u{00B7} added 2026-02-10 \u{00B7} #22\"", $html, 'and the question will name that one' );

		// Somebody who may see the panel but is not an administrator: the list and Confirm, not the switch.
		$html = $seen['reader']['loaded']['greviews'];
		$this->assertSame( 2, substr_count( $html, 'zsv-gr-confirm' ) );
		$this->assertStringNotContainsString( 'id="zsv-gr-now"', $html );
		$this->assertStringNotContainsString( 'id="zsv-gr-mode"', $html );

		// Cancel: nothing is asked of the site, for Confirm or for the switch. "Not them" needs no question.
		$this->assertCount( 2, $seen['cancel']['questions'] );
		$this->assertStringContainsString( 'Record sure matches automatically from now on?', $seen['cancel']['questions'][1] );
		$this->assertStringContainsString( 'What is listed now is not recorded', $seen['cancel']['questions'][1] );
		$asked = array_values( array_diff( array_column( $seen['cancel']['requests'], 'action' ), array( 'zsv_stats', 'zsv_recent', 'zsv_greviews_list' ) ) );
		$this->assertSame( array( 'zsv_greviews_dismiss' ), $asked );
		$dismiss = array_values( array_filter( $seen['cancel']['requests'], static function ( $f ) { return 'zsv_greviews_dismiss' === $f['action']; } ) );
		$this->assertSame( 'g2', $dismiss[0]['review_id'] );

		// The switch: on asks first; off does not; "Check Google now" is one request.
		$this->assertCount( 1, $seen['admin']['questions'], 'only turning it ON asks' );
		$modes = array_values( array_filter( $seen['admin']['requests'], static function ( $f ) { return 'zsv_greviews_mode' === $f['action']; } ) );
		$this->assertSame( array( '1', '0' ), array_column( $modes, 'automatic' ) );
		$this->assertContains( 'zsv_greviews_check_now', array_column( $seen['admin']['requests'], 'action' ) );
		$this->assertStringContainsString( 'Automatic: a sure match is recorded', (string) $seen['admin']['steps'][0]['greviews'], 'the heading says which way it works now' );
		$this->assertStringContainsString( 'Turn off automatic recording', (string) $seen['admin']['steps'][0]['greviews'] );
		$this->assertStringContainsString( 'Watch-only', (string) $seen['admin']['steps'][1]['greviews'] );

		// Somebody who is not an administrator: no switch, no "check now", and why the last check did not run.
		$html = $seen['other']['loaded']['greviews'];
		$this->assertStringContainsString( 'No new Google reviews in the last 60 days yet.', $html );
		$this->assertStringContainsString( 'waiting for Google to approve access', $html );
		$this->assertStringNotContainsString( 'id="zsv-gr-now"', $html );
		$this->assertStringNotContainsString( 'id="zsv-gr-mode"', $html );
	}

	/* ── C. "Send again" on the settings screen ──────────────────────────────────── */

	public function test_a_second_email_is_asked_for_only_after_a_person_says_so(): void {
		$sent = array( 'success' => true, 'data' => array( 'id' => 7, 'result' => 'sent', 'reason' => '', 'crm' => 'won', 'send_no' => 2 ) );
		$skip = static function ( array $more ): array {
			return array( 'success' => true, 'data' => array( 'id' => 7, 'result' => 'skipped', 'crm' => 'none' ) + $more );
		};
		$seen = $this->node( 'surveys-send-again.js', array( self::asset( 'js/admin.js' ) ), array(
			'cancel'      => array( 'answer_ok' => false, 'reply' => $sent ),
			'ok'          => array( 'answer_ok' => true, 'reply' => $sent ),
			'twice'       => array( 'answer_ok' => true, 'reply' => $sent, 'twice' => true ),
			'unrecorded'  => array( 'answer_ok' => true, 'reply' => array( 'success' => true, 'data' => array( 'id' => 7, 'result' => 'sent', 'send_no' => 2, 'unrecorded' => true ) ) ),
			'refused'     => array( 'answer_ok' => true, 'reply' => $skip( array( 'reason' => 'already_invited', 'already_emailed' => true ) ) ),
			'running'     => array( 'answer_ok' => true, 'reply' => $skip( array( 'reason' => 'send_in_progress', 'in_progress' => true ) ) ),
			'lock'        => array( 'answer_ok' => true, 'reply' => $skip( array( 'reason' => 'lock_unavailable' ) ) ),
			'unreadable'  => array( 'answer_ok' => true, 'reply' => $skip( array( 'reason' => 'row_unreadable' ) ) ),
			'crm_closed'  => array( 'answer_ok' => true, 'reply' => $skip( array( 'reason' => 'crm_closed' ) ) ),
			'held'        => array( 'answer_ok' => true, 'reply' => $skip( array( 'reason' => 'status_needs_attention' ) ) ),
			'mail_failed' => array( 'answer_ok' => true, 'reply' => array( 'success' => true, 'data' => array( 'id' => 7, 'result' => 'failed', 'reason' => 'send_failed' ) ) ),
			'site_no'     => array( 'answer_ok' => true, 'reply' => array( 'success' => false, 'data' => 'Survey data access restricted.' ) ),
			'no_answer'   => array( 'answer_ok' => true, 'reply' => 'fails' ),
			'odd_name'    => array( 'answer_ok' => false, 'reply' => $sent, 'customer' => array( 'id' => 9, 'name' => 'Riley $& %2$s Example', 'at' => '2026-03-02 10:00' ) ),
			'no_list'     => array( 'answer_ok' => true, 'reply' => $sent, 'table' => false ),
		) );

		// Cancel: the question was asked, and nothing at all was sent to the site.
		$this->assertSame( array( 'Riley Example was ALREADY E-MAILED (2026-03-02 10:00). Send AGAIN?' ), $seen['cancel']['questions'], 'the question names the customer and when they were e-mailed' );
		$this->assertSame( array(), $seen['cancel']['requests'] );
		$this->assertFalse( $seen['cancel']['disabled'] );

		// OK: one request, for that customer, carrying their own number as send_again.
		$this->assertCount( 1, $seen['ok']['questions'] );
		$this->assertCount( 1, $seen['ok']['requests'] );
		$this->assertSame( 'POST', $seen['ok']['requests'][0]['method'] );
		$this->assertSame( array( 'action' => 'zsv_send_invite', 'nonce' => 'n-1', 'lead_id' => '7', 'send_again' => '7' ), $seen['ok']['requests'][0]['fields'] );
		$this->assertSame( 'Sent again (send #2).', $seen['ok']['said'] );
		$this->assertSame( '2', $seen['ok']['sends'], 'the row shows which send it was' );
		$this->assertTrue( $seen['ok']['disabled'], 'a request that went stays pressed' );

		// A second press while the first is on its way does nothing.
		$this->assertCount( 1, $seen['twice']['questions'] );
		$this->assertCount( 1, $seen['twice']['requests'] );

		$this->assertSame( 'Sent again, time not saved.', $seen['unrecorded']['said'] );
		$this->assertTrue( $seen['unrecorded']['disabled'] );

		$said = array( 'refused' => 'Not sent again.', 'running' => 'Another send is running.', 'lock' => 'Could not make sure.', 'unreadable' => 'Could not read.', 'crm_closed' => 'Closed in the CRM.', 'held' => 'Held back.', 'mail_failed' => 'The mail service did not take it.' );
		foreach ( $said as $name => $words ) {
			$this->assertSame( $words, $seen[ $name ]['said'], $name );
			$this->assertFalse( $seen[ $name ]['disabled'], "$name: nothing went, so it may be tried again" );
			$this->assertSame( '1', $seen[ $name ]['sends'], "$name: the count of sends is unchanged" );
		}

		// The site said no, or did not answer: the e-mail may have gone, so the button stays pressed.
		$this->assertSame( 'That did not work.', $seen['site_no']['said'] );
		$this->assertTrue( $seen['site_no']['disabled'] );
		$this->assertSame( 'That did not work.', $seen['no_answer']['said'] );
		$this->assertTrue( $seen['no_answer']['disabled'], 'never repeated automatically' );
		$this->assertCount( 1, $seen['no_answer']['requests'] );

		$this->assertSame( array( 'Riley $& %2$s Example was ALREADY E-MAILED (2026-03-02 10:00). Send AGAIN?' ), $seen['odd_name']['questions'], 'a name is put in as it is' );
		$this->assertFalse( $seen['no_list']['bound'], 'no list on the screen, nothing to press' );
		$this->assertSame( array(), $seen['no_list']['requests'] );
	}

	/* ── D. the settings screen's helpers, and the stylesheet ────────────────────── */

	public function test_the_words_the_send_again_button_uses(): void {
		$words = ZSV_Admin::send_again_words();
		$js    = (string) file_get_contents( self::asset( 'js/admin.js' ) );
		preg_match_all( '/words\.([a-z_]+)/', $js, $m );
		$used = array_values( array_unique( $m[1] ) );
		sort( $used );
		$have = array_keys( $words );
		sort( $have );
		$this->assertSame( $have, $used, 'the screen hands the script exactly the sentences it uses' );
		$this->assertStringContainsString( '%1$s', $words['ask'] );
		$this->assertStringContainsString( '%2$s', $words['ask'] );
		$this->assertStringContainsString( '%3$s', $words['sent'] );
		foreach ( $words as $k => $w ) {
			$this->assertNotSame( '', trim( $w ), $k );
		}
	}

	public function test_the_review_windows_take_whole_days_in_range_or_the_default(): void {
		$this->assertSame( 45, ZSV_Admin::sanitize_lookback_days( '45' ) );
		$this->assertSame( 45, ZSV_Admin::sanitize_lookback_days( 45 ) );
		$this->assertSame( 1, ZSV_Admin::sanitize_lookback_days( '1' ) );
		$this->assertSame( 365, ZSV_Admin::sanitize_lookback_days( '365' ) );
		foreach ( array( '', '0', '-5', '4.5', 'ten', '1e2', null, array( 3 ), ' ' ) as $bad ) {
			$this->assertSame( 60, ZSV_Admin::sanitize_lookback_days( $bad ), var_export( $bad, true ) . ' is the default' );
			$this->assertSame( 120, ZSV_Admin::sanitize_candidate_days( $bad ) );
		}
		$this->assertSame( 60, ZSV_Admin::sanitize_lookback_days( '366' ), 'past a year is the default' );
		$this->assertSame( 730, ZSV_Admin::sanitize_candidate_days( '730' ) );
		$this->assertSame( 120, ZSV_Admin::sanitize_candidate_days( '731' ) );
		$this->assertSame( 400, ZSV_Admin::sanitize_candidate_days( ' 400 ' ) );
	}

	public function test_the_list_of_emails_already_sent(): void {
		$prev            = $GLOBALS['wpdb'] ?? null;
		$db              = new ZSV210_Sent_DB();
		$GLOBALS['wpdb'] = $db;
		$GLOBALS['zdz_test_options'] = array( ZSV_Send_Once::UNRECORDED_OPTION => array( 4 => '2026-03-09 08:00:00', 2 => '2026-03-01 08:00:00', 99 => '2026-03-01 08:00:00' ) );
		$row = static function ( int $id, string $first, ?string $at, ?string $log ): array {
			return array( 'id' => $id, 'first_name' => $first, 'last_name' => 'Example', 'email_sent_at' => $at, 'email_send_log' => $log );
		};
		$db->rows = array(
			1 => $row( 1, 'Riley', '2026-03-05 09:30:12', '["2026-03-05 09:30:12"]' ),
			2 => $row( 2, 'Sam', '2026-03-07 14:00:00', '["2026-03-01 08:00:00","2026-03-07 14:00:00"]' ),
			3 => $row( 3, 'Casey', null, null ),
			4 => $row( 4, 'Blake', null, null ),
			5 => $row( 5, '', '2026-03-02 10:00:00', null ),
		);
		try {
			$rows = ZSV_Admin::sent_rows();
		} finally {
			$GLOBALS['wpdb'] = $prev;
			unset( $GLOBALS['zdz_test_options'] );
		}
		$this->assertSame(
			array(
				array( 'id' => 4, 'name' => 'Blake Example', 'at' => '2026-03-09 08:00', 'sends' => 1 ),   // e-mailed, though the record would not take the time
				array( 'id' => 2, 'name' => 'Sam Example', 'at' => '2026-03-07 14:00', 'sends' => 2 ),
				array( 'id' => 1, 'name' => 'Riley Example', 'at' => '2026-03-05 09:30', 'sends' => 1 ),
				array( 'id' => 5, 'name' => 'Example', 'at' => '2026-03-02 10:00', 'sends' => 1 ),
			),
			$rows,
			'everybody e-mailed, the remembered ones first; a customer never e-mailed is not in it'
		);
	}

	public function test_the_stylesheet_uses_only_colours_the_theme_defines(): void {
		$css   = (string) file_get_contents( self::asset( 'css/widget.css' ) );
		$theme = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz/style.css' );
		preg_match_all( '/var\(\s*(--[a-z0-9-]+)/', $css, $m );
		$used = array_values( array_unique( $m[1] ) );
		$this->assertGreaterThanOrEqual( 8, count( $used ) );
		foreach ( $used as $token ) {
			$this->assertMatchesRegularExpression( '/' . preg_quote( $token, '/' ) . '\s*:/', $theme, "$token is not a colour the theme defines, so it would never change with the display mode" );
		}
	}
}
