<?php
/**
 * The Estimates screens, run in Node as they ship (tests/unit/support/estimate-view-cases.js).
 *
 *   - the customer lookup: one match alone; the same name, or one letter apart, together; never more than two before
 *     "More matches (n)"; the name searched for first, whatever order the server ranks them in; a contact match first
 *     of all; "More matches" opens every match, puts the focus on the first card added and never on a Use button;
 *   - an e-mail address typed into the lookup loses its sentence's punctuation, as the server's cleaner does it;
 *   - each estimate on a list is one box with its follow-up; declined ones fold at the foot of Open; a server without
 *     follow-ups still gets its plain rows;
 *   - the follow-up strip offers only what the person may do, and for a look-only list nothing but History;
 *   - for an account in Privacy View the widget sends nothing but the list reads, and a row opens the look-only sheet,
 *     which holds no control and no price.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/estimate-followup-stubs.php';

final class EstimateViewTest extends TestCase {

	/** @var array|null */
	private static $cases = null;

	/** Run every case in one Node process; null when Node is not installed here. */
	private static function cases(): ?array {
		if ( null !== self::$cases ) {
			return self::$cases ?: null;
		}
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) ); // sentinel:allow fixed command, no input
		if ( '' === $node ) {
			self::$cases = array();
			return null;
		}
		$dir  = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/estimate/assets/js';
		$code = file_get_contents( __DIR__ . '/support/estimate-view-cases.js' );
		$out  = shell_exec( escapeshellarg( $node ) . ' -e ' . escapeshellarg( $code ) . ' ' . escapeshellarg( $dir ) . ' 2>&1' ); // sentinel:allow every argument is escapeshellarg()'d
		self::$cases = json_decode( (string) $out, true ) ?: array( 'error' => (string) $out );
		return self::$cases;
	}

	private function r( string $key ) {
		$c = self::cases();
		if ( null === $c ) {
			$this->markTestSkipped( 'node is not installed here' );
		}
		$this->assertArrayNotHasKey( 'error', $c, substr( (string) ( $c['error'] ?? '' ), 0, 600 ) );
		$this->assertArrayHasKey( $key, $c );
		return $c[ $key ];
	}

	/* ── the customer lookup ─────────────────────────────────────────────── */

	public function test_one_match_shows_alone_and_near_names_show_together(): void {
		$m = $this->r( 'match' );
		$this->assertSame( array( array( '1' ), 0 ), array( $m['one']['shown'], $m['one']['more'] ) );
		$this->assertSame( array( array( '1' ), 1 ), array( $m['two_far']['shown'], $m['two_far']['more'] ), 'the name searched for, alone: the other is behind More' );
		foreach ( array( 'two_letter', 'two_changed', 'two_same', 'two_spaced', 'two_blank' ) as $case ) {
			$this->assertSame( array( '1', '2' ), $m[ $case ]['shown'], $case . ': the same name, or one letter apart, may be two people: both show' );
		}
		foreach ( array( 'two_two_letters', 'two_two_changed', 'two_swapped' ) as $case ) {
			$this->assertSame( array( '1' ), $m[ $case ]['shown'], $case . ': two letters apart is not "the same"' );
		}
		$this->assertSame( array(), $m['none']['shown'] );
		$this->assertSame( array( true, true, true, true, false, false, true, true ), $this->r( 'near' ) );
		$this->assertSame( array( 'morganoexample', '', 'sammorganexampleco' ), $this->r( 'keyOf' ), 'capitals, spaces and punctuation aside' );
	}

	public function test_never_more_than_two_before_more_matches(): void {
		$m = $this->r( 'match' );
		foreach ( $m as $case => $r ) {
			$this->assertLessThanOrEqual( 2, count( $r['shown'] ), $case );
			$this->assertSame( count( $r['order'] ), count( $r['shown'] ) + $r['more'], $case . ': every match the server sent can be reached' );
		}
		$this->assertSame( 3, $m['five']['more'] );
		$this->assertSame( 8, $m['ten']['more'] );
		$this->assertSame( array( array( '10' ), 9 ), array( $m['ten_with_exact']['shown'], $m['ten_with_exact']['more'] ), 'ranked last of ten by the server, the name searched for is still the one card shown' );
		$this->assertSame( array( array( '1', '2' ), 2 ), array( $m['near_then_rest']['shown'], $m['near_then_rest']['more'] ) );
		$this->assertSame( array( array( '1', '2' ), 1 ), array( $m['three_same']['shown'], $m['three_same']['more'] ) );
	}

	public function test_the_name_searched_for_comes_first_whatever_the_servers_order(): void {
		$m = $this->r( 'match' );
		$this->assertSame( array( '2', '1' ), $m['nickname_first']['order'], 'a longer first name ranked above it by the server' );
		$this->assertSame( array( '2' ), $m['couple_first']['shown'], 'a couple\'s record ranked above it: the name searched for is the card shown' );
		$this->assertSame( array( '1', '3' ), $m['same_split']['shown'], 'two records under the one name both show' );
		$this->assertSame( array( '1', '2', '3' ), $m['none_exact']['order'], 'no record under that name: the server\'s order stands' );
		$this->assertSame( array( '2' ), $m['spaced_exact']['shown'], 'hyphenated on file is still the name searched for' );
		$this->assertSame( array( '1', '2' ), $m['two_contacts']['shown'], 'a contact match is first of all' );
		$this->assertSame( array( '1', '3', '2' ), $m['contact_other_name']['order'], 'then the name, then the rest' );
	}

	public function test_more_matches_opens_every_match_and_the_keyboard_cannot_pick_one_by_accident(): void {
		$d = $this->r( 'draw' );
		$this->assertSame( 2, $d['before'] );
		$this->assertSame( 'More matches (8)', $d['moreLabel'] );
		$this->assertSame( 10, $d['after'] );
		$this->assertTrue( $d['moreGone'] );
		$this->assertTrue( $d['staysOpen'], 'typing on keeps the list open' );
		$this->assertSame( -1, $d['cardTab'] );
		$this->assertTrue( $d['focusOnThirdCard'], 'the focus goes to the first card added' );
		$this->assertTrue( $d['noButtonFocused'], 'never to a Use button: a second Enter must not pick a customer' );
		$this->assertSame( array( '7', '2' ), $d['used'], 'Use on any card fills that customer and no neighbour' );
		$this->assertTrue( $d['evilIsText'], 'a name is written as text, never as markup' );
		$this->assertStringContainsString( 'contact on file', $d['withheld'] );
	}

	public function test_the_pages_email_cleaner_agrees_with_the_servers(): void {
		$js = $this->r( 'email' );
		$this->assertGreaterThan( 15, count( $js ) );
		foreach ( $js as $typed => $cleaned ) {
			$this->assertSame( ZEST_FreshBooks::clean_email( $typed ), $cleaned, var_export( $typed, true ) );
		}
		$this->assertSame( 'alex@example.com', $js['alex@example.com.'] );
		$this->assertSame( '', $this->r( 'emailNull' ) );
		$this->assertSame( '', $this->r( 'emailLong' ) );
		$this->assertTrue( $this->r( 'emailLongFast' ), 'a very long paste is refused, not scanned' );
	}

	/* ── the lists ───────────────────────────────────────────────────────── */

	public function test_each_estimate_is_one_box_and_declined_ones_fold_at_the_foot(): void {
		$w = $this->r( 'widget' );
		$this->assertSame( array( 'zest_list_open' ), $w['sent'] );
		$this->assertSame( 3, $w['cards'] );
		$this->assertSame( 3, $w['strips'], 'each box holds the strip its follow-up is drawn in' );
		$this->assertTrue( $w['declinedGroup'] );
		$this->assertTrue( $w['declinedLast'] );
		$this->assertTrue( $w['escaped'] );
		$this->assertSame( 1, $w['decorated'] );
		$this->assertSame( array( 'cards' => 0, 'rows' => 3, 'decorated' => 0 ), $this->r( 'widgetNoFollowups' ), 'a server without follow-ups: the plain rows, as before' );
	}

	public function test_the_strip_offers_only_what_the_person_may_do(): void {
		$s = $this->r( 'strip' );
		$this->assertSame( array( 'send', 'answer' ), $s['none_admin'] );
		$this->assertSame( array( 'answer' ), $s['none_sales'], 'anyone records a follow-up; only some re-send' );
		$this->assertSame( array(), $s['none_closed'], 'nothing to follow up on an estimate that is not open' );
		$this->assertSame( array( 'history' ), $s['history_only'] );
		$this->assertSame( array( 'answer', 'answer', 'answer', 'history' ), $s['mine'], 'asked of this person: the three answers, and no take-back of the ask' );
		$this->assertSame( array( 'send', 'answer', 'undo', 'history' ), $s['asked_admin'] );
		$this->assertSame( array( 'answer', 'undo', 'history' ), $s['asked_by_me'], 'whoever sent it may take it back' );
		$this->assertSame( array( 'answer', 'history' ), $s['asked_other'] );
		$this->assertSame( array( 'answer', 'undo', 'history' ), $s['tried'] );
		$this->assertSame( array( 'reopen', 'undo', 'history' ), $s['declined'] );
		$this->assertSame( 'retry', $s['failed'][0], 'a line that did not get through can be tried again' );
		$this->assertSame( array( 'history' ), $s['read_only'], 'look only: nothing but History' );
		$this->assertSame( '', $s['read_only_none'] );
		$w = $this->r( 'stripWords' );
		$this->assertSame( array( 'yours' => true, 'answers' => 3, 'declinedTag' => true, 'skippedSaysWhy' => true, 'escaped' => true, 'noProviderNamed' => true ), $w );
		$h = $this->r( 'history' );
		$this->assertSame( '<p>Nothing recorded for this estimate.</p>', $h['empty'] );
		unset( $h['empty'] );
		$this->assertSame( array( 'takenBack' => true, 'escaped' => true, 'line' => true, 'crm' => true, 'undone' => true ), $h );
	}

	/* ── look only ───────────────────────────────────────────────────────── */

	public function test_look_only_sends_the_list_read_and_opens_the_look_only_sheet(): void {
		$l = $this->r( 'look' );
		foreach ( array( 'boolean:true', 'number:1', 'string:1' ) as $flag ) {
			$this->assertSame( array( 'zest_list_open' ), $l[ $flag ]['sent'], $flag . ': the setting is read as the page sends it' );
			$this->assertSame( array( '7', '7' ), $l[ $flag ]['looked'], 'a tap and Enter open it; another key and a tap elsewhere do not' );
			$this->assertTrue( $l[ $flag ]['testTag'] );
			$this->assertTrue( $l[ $flag ]['readOnly'] );
		}
		foreach ( array( 'off:', 'off:0', 'off:false', 'off:undefined', 'off:yes' ) as $flag ) {
			$this->assertSame( 0, $l[ $flag ], $flag . ': the team\'s widget is not look-only' );
		}
		$this->assertTrue( $this->r( 'lookGuard' ), 'no other request can leave the look-only widget' );
	}

	public function test_the_look_only_sheet_holds_no_control_and_no_price(): void {
		$s = $this->r( 'sheet' );
		$this->assertSame( array( 'escaped' => true, 'noControl' => true, 'noMoney' => true, 'rows' => 3, 'noWords' => true, 'hidden' => true, 'ours' => true, 'test' => true, 'empty' => true ), $s );
		$this->assertSame( array( array( 'zest_pv_estimate', 'id,nonce' ), array( 'zest_pv_estimate', 'id,nonce' ) ), $this->r( 'sheetAsks' ), 'only a whole number is ever asked for, with nothing but the id' );
	}

	public function test_the_new_estimate_screen_has_a_camera_and_no_box_a_phone_would_zoom(): void {
		$root = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/estimate/';
		$app  = (string) file_get_contents( $root . 'includes/class-zest-app.php' );
		$this->assertMatchesRegularExpression( '/<label class="zest-upload" for="zest-file">\s*<input type="file" id="zest-file" accept="image\/\*" multiple hidden \/>\s*<svg class="zest-upload-icon" data-icon="camera" aria-hidden="true"/', $app,
			'the camera is on the control that still opens the phone\'s own picker for several images' );
		$css = (string) file_get_contents( $root . 'assets/css/widget.css' );
		$this->assertMatchesRegularExpression( '/\.zest-w \.zest-textarea, \.zest-w \.zest-input, \.zest-w select,\s*\.zest-sheet \.zest-textarea, \.zest-sheet \.zest-input, \.zest-sheet select \{ font-size: max\(16px, 1em\); \}/', $css );
		$this->assertStringContainsString( '.zest-fu { color: var(--sys-text, CanvasText); font-size: 16px;', (string) file_get_contents( $root . 'assets/css/followup.css' ) );
	}
}
