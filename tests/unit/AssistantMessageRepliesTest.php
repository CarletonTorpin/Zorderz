<?php
/**
 * The assistant answering in a Privacy View account's Messages thread (ZANA_PV_DM_Agent), with the real Messages
 * classes behind it:
 *
 *   - who: only the administrator chosen for Privacy View accounts, signed in and typing it themselves, in THE direct
 *     conversation with an account in Privacy View; never the account's own message, a scheduled job, another
 *     conversation or a channel; and only once a daily line is set;
 *   - when: every message there except a short acknowledgement;
 *   - one turn at a time for a thread, a message written meanwhile answered next, twenty an hour, a daily line in
 *     dollars that each call's cap is taken from, counted from what the chat was charged, failing closed;
 *   - the turn is the chat's own, run as the account, reading how replies work, notes from its chats and the thread;
 *   - the answer is posted once, by the account, through Messages' own seam; it is stored with the real details and
 *     masked again on every view by the account; something to do is a draft with a link that gives the request's
 *     words only to the administrator, once;
 *   - its notes chat is never listed and closed to everything but these replies.
 *
 * Neutral data only (Alex, Jordan, Robin, Sam, Riley Example, Example Co, 555 numbers, example.com).
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/messaging-test-doubles.php';
require_once __DIR__ . '/support/assistant-doubles.php';

$zdz_asst_root = dirname( __DIR__, 2 );
foreach ( array( 'mentions', 'dms', 'attachments' ) as $zdz_asst_f ) {
	require_once $zdz_asst_root . '/zorderz-apps/apps/messaging/includes/class-zim-' . $zdz_asst_f . '.php';
}
require_once $zdz_asst_root . '/zorderz/inc/class-zdz-capabilities.php';
foreach ( array( 'markers', 'db', 'allowance', 'llm-client', 'pseudonym-route', 'actions', 'prompt-builder', 'user-memory', 'chat', 'style-memory', 'drafts-page', 'pv-dm-agent' ) as $zdz_asst_f ) {
	require_once $zdz_asst_root . '/zorderz-apps/apps/analytics/includes/class-zana-' . $zdz_asst_f . '.php';
}

if ( ! class_exists( 'ZANA_REST' ) ) {
	/** The chat's access check, as the real class answers it for a person the chat app is granted to. */
	class ZANA_REST {
		public static function can_access(): bool {
			$uid = (int) get_current_user_id();
			return $uid > 0 && ( user_can( $uid, 'zdz_access_app' ) || user_can( $uid, 'manage_options' ) );
		}
	}
}

if ( ! class_exists( 'ZDZ_Test_Asst_Pv_Coder' ) ) {
	/** A stand-in for the coder Privacy View masks with: it knows one customer. */
	final class ZDZ_Test_Asst_Pv_Coder {
		public $names = array( 1 => 'Riley Example' );
		private function sub( $t ) {
			foreach ( $this->names as $id => $n ) {
				$t = preg_replace( '/(?<![\p{L}])' . preg_quote( $n, '/' ) . '(?![\p{L}])/iu', '⟦c:' . $id . '⟧', $t );
			}
			return $t;
		}
		public function encode( $t ) { return $this->sub( (string) $t ); }
		public function names_only( $t ) { return $this->sub( (string) $t ); }
		public function open( $c ) { return null; }
		public function ledger_name( $id ) { return $this->names[ (int) $id ] ?? null; }
		public function index() {
			$l = array();
			foreach ( $this->names as $id => $n ) {
				$l[ ZDZ_Privacy_View::norm( $n ) ] = $id;
			}
			return array( 'ledger' => $l, 'other' => array() );
		}
	}
}

final class AssistantMessageRepliesTest extends TestCase {

	const ALEX   = 51;   // the administrator chosen for Privacy View accounts
	const ROBIN  = 52;   // the account in Privacy View
	const SAM    = 53;   // another administrator
	const JORDAN = 54;   // staff
	const SHARED = 55;   // the shared device
	const THREAD = 77;   // the direct conversation between Alex and Robin

	/** Starts the agent asked for: [ conversation, token ]. */
	private $kicks = array();

	/** What the chat's turn was given: [ pv, session, question, history, signed-in user, in a turn?, the call's cap ]. */
	private $turns = array();

	/** What the chat's turn answers next. */
	private $answer = array( 'ok' => true, 'answer' => 'It opens at eight.' );

	/** Thousandths of a dollar the next turn's calls cost. */
	private $cost = 0;

	protected function setUp(): void {
		zdz_test_msg_reset();
		unset( $GLOBALS['zdz_test_doing_cron'], $GLOBALS['zdz_test_user_names'] );
		zdz_test_msg_people( array( self::ALEX => 'admin', self::ROBIN => 'staff', self::SAM => 'admin', self::JORDAN => 'staff', self::SHARED => 'shared' ) );
		$GLOBALS['zdz_test_user_names'] = array( self::ALEX => 'Alex Example', self::ROBIN => 'Robin', self::SAM => 'Sam Example', self::JORDAN => 'Jordan Example' );
		$GLOBALS['zdz_test_user_meta'][ self::ROBIN ]['zdz_privacy_view'] = '1';
		$GLOBALS['zdz_test_options'][ ZDZ_Privacy_View::DM_OPTION ]       = self::ALEX;
		$GLOBALS['zdz_test_options'][ ZANA_PV_DM_Agent::DAILY_OPT ]       = 2;
		$GLOBALS['zdz_test_filters']['zim_dm_send']                       = array( 'ZIM_DM_Send', 'send' );   // Messages' own seam
		$GLOBALS['zdz_test_current_user']                                 = self::ALEX;
		$db = new ZDZ_Test_Asst_DB();
		$db->conversation( self::THREAD, self::ALEX, self::ROBIN );
		$db->conversation( 78, self::ALEX, self::JORDAN );
		$db->conversation( 79, self::ALEX, self::ROBIN, 'channel' );
		$GLOBALS['wpdb'] = $db;
		ZDZ_Capabilities::flush();
		ZANA_Allowance::$today      = function () { return '2026-03-04'; };
		$this->kicks                = array();
		$this->turns                = array();
		$this->answer               = array( 'ok' => true, 'answer' => 'It opens at eight.' );
		$this->cost                 = 0;
		ZANA_PV_DM_Agent::$kicker   = function ( $conv, $token ) { $this->kicks[] = array( (int) $conv, (string) $token ); };
		ZANA_PV_DM_Agent::$turn     = function ( $pv, $session, $question, $history ) {
			$this->turns[] = array( $pv, $session, $question, $history, (int) get_current_user_id(), ZANA_PV_DM_Agent::in_turn( (int) $pv ), ZANA_LLM_Client::call_cap( (int) $pv ) );
			ZANA_Allowance::add_milli( (int) $pv, $this->cost );   // as the chat's client counts each call's charge
			if ( $this->answer instanceof \Throwable ) {
				throw $this->answer;
			}
			return $this->answer;
		};
		// the agent's line for a call, as the app registers it
		$GLOBALS['zdz_test_filters']['zana_llm_call_cap_usd'] = array( 'ZANA_PV_DM_Agent', 'call_cap' );
	}

	protected function tearDown(): void {
		ZANA_PV_DM_Agent::$kicker = null;
		ZANA_PV_DM_Agent::$turn   = null;
		ZANA_Allowance::$today    = null;
		ZANA_LLM_Client::$decoder = null;
		zdz_test_asst_router_off();
		unset( $GLOBALS['zdz_test_doing_cron'], $GLOBALS['zdz_test_user_names'] );
		$pv = new ReflectionProperty( 'ZDZ_Privacy_View', 'coder' );
		$pv->setAccessible( true );
		$pv->setValue( null, null );
		ZDZ_Capabilities::flush();
		zdz_test_msg_teardown();
	}

	/** Someone posts in a conversation through Messages, and Messages says so (as its action does). */
	private function posts( int $who, string $text, int $conv = self::THREAD, ?int $signed_in = null ): int {
		$GLOBALS['zdz_test_current_user'] = null === $signed_in ? $who : $signed_in;
		$r = ZIM_Messages::post( $conv, $who, $text );
		$this->assertIsArray( $r, is_wp_error( $r ) ? $r->get_error_message() : '' );
		ZANA_PV_DM_Agent::on_posted( $r['message_id'], $conv, $who );
		return (int) $r['message_id'];
	}

	/** The start that was asked for runs, as the loopback would run it: nobody signed in. */
	private function runs(): string {
		$this->assertNotEmpty( $this->kicks, 'a turn was started' );
		list( $conv, $token ) = array_pop( $this->kicks );
		$this->kicks                      = array();
		$GLOBALS['zdz_test_current_user'] = 0;
		return ZANA_PV_DM_Agent::run( $conv, $token );
	}

	/** The bodies the account has posted in the thread, oldest first. */
	private function replies(): array {
		$out = array();
		foreach ( $GLOBALS['wpdb']->msgs as $m ) {
			if ( self::THREAD === (int) $m['conversation_id'] && self::ROBIN === (int) $m['author_user_id'] ) {
				$out[] = (string) $m['body'];
			}
		}
		return $out;
	}

	private static function lock(): string {
		return '_zana_pv_dm_lock_' . self::THREAD;
	}

	/* ── who ───────────────────────────────────────────────────────────────────── */

	public function test_only_the_chosen_administrator_typing_in_the_accounts_thread(): void {
		$this->assertTrue( ZANA_PV_DM_Agent::enabled() );
		$this->assertSame( self::ROBIN, ZANA_PV_DM_Agent::who( 1, self::THREAD, self::ALEX ) );
		$GLOBALS['zdz_test_current_user'] = self::ROBIN;
		$this->assertSame( 0, ZANA_PV_DM_Agent::who( 1, self::THREAD, self::ROBIN ), 'the account\'s own message: it never answers itself' );
		$GLOBALS['zdz_test_current_user'] = 0;
		$this->assertSame( 0, ZANA_PV_DM_Agent::who( 1, self::THREAD, self::ALEX ), 'posted in their name with nobody signed in (a reply that came by e-mail)' );
		$GLOBALS['zdz_test_current_user'] = self::SAM;
		$this->assertSame( 0, ZANA_PV_DM_Agent::who( 1, self::THREAD, self::ALEX ), 'posted in their name by someone else' );
		$GLOBALS['zdz_test_current_user'] = self::ALEX;
		$GLOBALS['zdz_test_doing_cron']   = true;
		$this->assertSame( 0, ZANA_PV_DM_Agent::who( 1, self::THREAD, self::ALEX ), 'during a scheduled job: no automatic answer to an automatic message' );
		unset( $GLOBALS['zdz_test_doing_cron'] );
		$this->assertSame( 0, ZANA_PV_DM_Agent::who( 1, 78, self::ALEX ), 'their conversation with someone else' );
		$this->assertSame( 0, ZANA_PV_DM_Agent::who( 1, 79, self::ALEX ), 'a channel' );
		$this->assertSame( 0, ZANA_PV_DM_Agent::who( 1, 999, self::ALEX ), 'no such conversation' );
		$this->assertSame( 0, ZANA_PV_DM_Agent::who( 0, self::THREAD, self::ALEX ), 'no message' );
		$GLOBALS['zdz_test_current_user'] = self::SAM;
		$this->assertSame( 0, ZANA_PV_DM_Agent::who( 1, self::THREAD, self::SAM ), 'another administrator, not the one chosen' );
		$GLOBALS['zdz_test_current_user'] = self::ALEX;
	}

	public function test_nothing_happens_when_the_choice_the_account_or_the_setting_is_gone(): void {
		$GLOBALS['zdz_test_options'][ ZDZ_Privacy_View::DM_OPTION ] = 0;
		$this->assertSame( 0, ZANA_PV_DM_Agent::who( 1, self::THREAD, self::ALEX ), 'nobody chosen' );
		$GLOBALS['zdz_test_options'][ ZDZ_Privacy_View::DM_OPTION ] = self::ALEX;
		zdz_test_msg_people( array( self::ALEX => 'staff' ) );
		$this->assertSame( 0, ZANA_PV_DM_Agent::who( 1, self::THREAD, self::ALEX ), 'no longer an administrator' );
		zdz_test_msg_people( array( self::ALEX => 'admin' ) );
		unset( $GLOBALS['zdz_test_user_meta'][ self::ROBIN ]['zdz_privacy_view'] );
		ZDZ_Privacy_View::flush();
		$this->assertSame( 0, ZANA_PV_DM_Agent::who( 1, self::THREAD, self::ALEX ), 'the other member is no longer in Privacy View' );
		$GLOBALS['zdz_test_user_meta'][ self::ROBIN ]['zdz_privacy_view'] = '1';
		ZDZ_Privacy_View::flush();
		$this->assertSame( self::ROBIN, ZANA_PV_DM_Agent::who( 1, self::THREAD, self::ALEX ) );
		unset( $GLOBALS['zdz_test_filters']['zim_dm_send'] );
		$this->assertSame( 0, ZANA_PV_DM_Agent::who( 1, self::THREAD, self::ALEX ), 'Messages\' seam is not answering' );
		$GLOBALS['zdz_test_filters']['zim_dm_send'] = array( 'ZIM_DM_Send', 'send' );
		$GLOBALS['zdz_test_options'][ ZANA_PV_DM_Agent::DAILY_OPT ] = 0;
		$this->assertFalse( ZANA_PV_DM_Agent::enabled(), 'no daily line: the account does not reply' );
		$this->assertSame( 0, ZANA_PV_DM_Agent::who( 1, self::THREAD, self::ALEX ) );
		$this->posts( self::ALEX, 'What time does the depot open?' );
		$this->assertSame( array(), $this->kicks );
		$this->assertSame( array(), $this->replies() );
	}

	public function test_the_daily_line_is_a_setting_that_is_off_until_set_and_never_guessed(): void {
		unset( $GLOBALS['zdz_test_options'][ ZANA_PV_DM_Agent::DAILY_OPT ] );
		$this->assertSame( 0, ZANA_PV_DM_Agent::daily_milli(), 'Core: off' );
		foreach ( array( array( '1.5', 1500 ), array( 0.25, 250 ), array( -3, 0 ), array( 'lots', 0 ), array( array( 5 ), 0 ), array( 9999, 50000 ) ) as $case ) {
			$GLOBALS['zdz_test_options'][ ZANA_PV_DM_Agent::DAILY_OPT ] = $case[0];
			$this->assertSame( $case[1], ZANA_PV_DM_Agent::daily_milli(), json_encode( $case[0] ) );
		}
		$GLOBALS['zdz_test_options'][ ZANA_PV_DM_Agent::DAILY_OPT ] = 2;
		$GLOBALS['zdz_test_filters'][ ZANA_PV_DM_Agent::DAILY_OPT ] = function () { return 'unlimited'; };
		$this->assertSame( 0, ZANA_PV_DM_Agent::daily_milli(), 'a line that cannot be read is no line' );
		$GLOBALS['zdz_test_filters'][ ZANA_PV_DM_Agent::DAILY_OPT ] = function ( $v ) { return $v / 2; };
		$this->assertSame( 1000, ZANA_PV_DM_Agent::daily_milli() );
		$this->assertSame( 50, ZANA_PV_DM_Agent::floor_milli(), 'a twentieth of the line' );
		$this->assertSame( 0.0, ZANA_PV_DM_Agent::sanitize_usd( 'x' ) );
		$this->assertSame( 50.0, ZANA_PV_DM_Agent::sanitize_usd( 80 ) );
		$this->assertSame( 2.35, ZANA_PV_DM_Agent::sanitize_usd( '2.349' ) );
	}

	/* ── when ───────────────────────────────────────────────────────────────────── */

	public function test_every_message_wants_an_answer_except_a_short_acknowledgement(): void {
		$names = array( 'robin', 'sage' );
		foreach ( array( 'What time does the depot open?', 'ok?', 'Robin, the gate code changed', '  Sage: note this', 'The gate code changed, keep that in mind', 'Draft a note to the crew about Friday', 'ok can you check the gate', 'thanks, please also list the open jobs', 'yes', 'no', 'Friday at eight' ) as $t ) {
			$this->assertTrue( ZANA_PV_DM_Agent::asks( $t, $names ), $t );
		}
		foreach ( array( 'Thanks', 'thanks!', 'Thank you.', 'ok', 'Okay', 'k', 'got it', 'Sounds good', 'Perfect, thanks', 'thx', "Thanks, that\u{2019}s helpful", 'great work', 'noted', 'lol', "\u{1F44D}", '!!', '', '   ' ) as $t ) {
			$this->assertFalse( ZANA_PV_DM_Agent::asks( $t, $names ), '"' . $t . '"' );
		}
		$this->assertTrue( ZANA_PV_DM_Agent::acknowledgement( 'Thanks' ) );
		$this->assertFalse( ZANA_PV_DM_Agent::acknowledgement( 'thanks for the nine reasons you gave me about the gate and the depot' ), 'not short' );
		$this->assertTrue( ZANA_PV_DM_Agent::asks( 'Robin thanks', $names ), 'addressed by name' );
		$this->assertFalse( ZANA_PV_DM_Agent::asks( 'Thanks Robin', $names ), 'a thank-you that happens to name it' );
		$this->assertFalse( ZANA_PV_DM_Agent::asks( 'Thanks', array( '', 'x.' ) ) );
	}

	public function test_the_names_it_answers_to_are_the_assistants_and_the_accounts(): void {
		$this->assertSame( array( 'robin' ), ZANA_PV_DM_Agent::names( self::ROBIN ) );
		$GLOBALS['zdz_test_options'][ ZANA_Prompt_Builder::VOICE_OPT ]    = array( 'name' => 'Sage', 'lines' => array() );
		$GLOBALS['zdz_test_user_meta'][ self::ROBIN ]['first_name'] = 'Rob';
		$this->assertSame( array( 'sage', 'rob', 'robin' ), ZANA_PV_DM_Agent::names( self::ROBIN ) );
		$this->posts( self::ALEX, 'Sage thanks' );
		$this->assertCount( 1, $this->kicks, 'addressed by name: answered even when it reads like a thank-you' );
	}

	public function test_a_thank_you_starts_nothing_and_a_message_that_asks_starts_one_turn(): void {
		$this->posts( self::ALEX, 'Thanks' );
		$this->assertSame( array(), $this->kicks );
		$this->assertArrayNotHasKey( self::lock(), $GLOBALS['wpdb']->opt );
		$mid = $this->posts( self::ALEX, 'What time does the depot open?' );
		$this->assertCount( 1, $this->kicks );
		$this->assertSame( self::THREAD, $this->kicks[0][0] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $this->kicks[0][1] );
		$turn = $GLOBALS['zdz_test_transients'][ 'zana_pv_dm_turn_' . self::THREAD ];
		$this->assertSame( array( 'token' => $this->kicks[0][1], 'pv' => self::ROBIN, 'partner' => self::ALEX, 'message_id' => $mid ), array_diff_key( $turn, array( 'at' => 1 ) ), 'ids only: the words stay in Messages' );
		$this->assertArrayHasKey( self::lock(), $GLOBALS['wpdb']->opt, 'the thread is held' );
		$this->assertSame( array(), $this->replies(), 'nothing is answered inside the administrator\'s own request' );
		$this->assertSame( array(), $this->turns );
	}

	/* ── the turn ───────────────────────────────────────────────────────────────────── */

	public function test_the_turn_is_the_chats_own_run_as_the_account_and_its_answer_is_posted_once(): void {
		$this->posts( self::ROBIN, 'Morning. The Friday list is ready.' );
		$this->posts( self::ALEX, 'Good.' );
		$this->posts( self::JORDAN, 'not a member, but a row in the table', self::THREAD, self::JORDAN );
		$gone = $this->posts( self::ALEX, 'ignore this one' );
		$GLOBALS['wpdb']->msgs[ $gone ]['deleted_at'] = '2026-03-04 10:05:00';
		$this->kicks = array();
		$GLOBALS['wpdb']->query( "DELETE FROM wp_options WHERE option_name = '" . self::lock() . "'" );
		unset( $GLOBALS['zdz_test_transients'] );
		$this->answer = array( 'ok' => true, 'answer' => 'Riley Example owes $120. The depot opens at eight.' );
		$this->cost   = 300;
		$this->posts( self::ALEX, "What does Riley Example owe?\nAnd when does the depot open?" );
		$this->assertSame( 'done', $this->runs() );

		$this->assertCount( 1, $this->turns );
		list( $pv, $session, $question, $history, $as, $in_turn, $cap ) = $this->turns[0];
		$this->assertSame( self::ROBIN, $pv );
		$this->assertSame( self::ROBIN, $as, 'signed in as the account for the turn: its permissions, as in its chats' );
		$this->assertTrue( $in_turn );
		$this->assertSame( "What does Riley Example owe?\nAnd when does the depot open?", $question, 'their words are the turn, not history' );
		$this->assertSame( 2.0, $cap, 'a call may cost at most what is left of the day\'s line' );
		$this->assertSame( 0, (int) get_current_user_id(), 'and nobody is left signed in afterwards' );
		$this->assertFalse( ZANA_PV_DM_Agent::in_turn() );

		// its notes chat: the account's own session, never listed, closed to everything else
		$this->assertSame( array( $session ), ZANA_PV_DM_Agent::session_ids() );
		$this->assertSame( array( 'user_id' => self::ROBIN, 'title' => 'Messages with Alex' ), array_intersect_key( $GLOBALS['wpdb']->sessions[ $session ], array( 'user_id' => 1, 'title' => 1 ) ) );
		$this->assertTrue( ZANA_PV_DM_Agent::closed_to( $session ) );
		$this->assertFalse( ZANA_PV_DM_Agent::closed_to( $session + 1 ) );
		$this->assertSame( array(), ZANA_Chat::sessions( self::ROBIN ), 'not among its chats' );

		// what it read first: how replies here work, then the thread (the administrator asks, the account answers)
		$this->assertSame( 'user', $history[0]['role'] );
		$this->assertStringContainsString( 'a direct-message thread with Alex, not the chat', $history[0]['content'] );
		$this->assertStringContainsString( 'You cannot carry anything out from here.', $history[0]['content'] );
		$this->assertStringContainsString( '"Draft (nothing was done):"', $history[0]['content'] );
		$this->assertSame( array( 'role' => 'assistant', 'content' => 'Understood.' ), $history[1] );
		$this->assertSame( array(
			array( 'role' => 'assistant', 'content' => 'Morning. The Friday list is ready.' ),   // its label taken off
			array( 'role' => 'user', 'content' => 'Good.' ),
		), array_slice( $history, 2 ), 'nobody else\'s message, no deleted one, and not the message being answered' );

		// posted by the account, in the thread, through Messages: its label, plain text, the real details
		$this->assertSame( array( 'From a Privacy View account: Morning. The Friday list is ready.', 'From a Privacy View account: Riley Example owes $120. The depot opens at eight.' ), $this->replies() );
		$this->assertArrayNotHasKey( self::lock(), $GLOBALS['wpdb']->opt, 'the thread is free again' );
		$this->assertArrayNotHasKey( 'zana_pv_dm_turn_' . self::THREAD, (array) $GLOBALS['zdz_test_transients'] );
		$this->assertSame( array(), array_filter( array_keys( $GLOBALS['wpdb']->opt ), function ( $k ) { return 0 === strpos( $k, '_zana_pv_dm_run_' ); } ), 'no claim left behind' );
		$this->assertSame( 300, ZANA_PV_DM_Agent::spent_today(), 'what the chat was charged for the turn' );
		// Messages says the reply was posted too: the account's own message starts nothing
		$mine = max( array_keys( $GLOBALS['wpdb']->msgs ) );
		ZANA_PV_DM_Agent::on_posted( $mine, self::THREAD, self::ROBIN );
		$GLOBALS['zdz_test_current_user'] = self::ROBIN;
		ZANA_PV_DM_Agent::on_posted( $mine, self::THREAD, self::ROBIN );
		$this->assertSame( array(), $this->kicks, 'it never answers itself' );
	}

	public function test_a_turn_is_taken_by_one_runner_and_its_answer_is_never_posted_twice(): void {
		$this->posts( self::ALEX, 'What time does the depot open?' );
		list( $conv, $token ) = $this->kicks[0];
		$GLOBALS['zdz_test_current_user'] = 0;
		$this->assertSame( 'none', ZANA_PV_DM_Agent::run( $conv, str_repeat( 'f', 32 ) ), 'not this turn\'s token' );
		$this->assertSame( 'none', ZANA_PV_DM_Agent::run( 78, $token ), 'not this turn\'s thread' );
		$this->assertSame( 'none', ZANA_PV_DM_Agent::run( $conv, '' ) );
		$this->assertSame( array(), $this->turns );
		// another runner already has it
		$claim = '_zana_pv_dm_run_' . md5( $conv . '|' . $token );
		$GLOBALS['wpdb']->opt[ $claim ] = (string) time();
		$this->assertSame( 'taken', ZANA_PV_DM_Agent::run( $conv, $token ) );
		$this->assertSame( array(), $this->turns );
		$this->assertArrayHasKey( self::lock(), $GLOBALS['wpdb']->opt, 'still held by the runner that has it' );
		unset( $GLOBALS['wpdb']->opt[ $claim ] );
		$this->assertSame( 'done', ZANA_PV_DM_Agent::run( $conv, $token ) );
		$this->assertSame( 'none', ZANA_PV_DM_Agent::run( $conv, $token ), 'the loopback and the scheduler both arrived: one answer' );
		ZANA_PV_DM_Agent::watch( $conv, $token, ZANA_PV_DM_Agent::WATCH_TRIES );
		$this->assertCount( 1, $this->replies() );
		$this->assertCount( 1, $this->turns );
	}

	public function test_everything_is_checked_again_when_the_turn_runs(): void {
		$mid = $this->posts( self::ALEX, 'What time does the depot open?' );
		$GLOBALS['zdz_test_options'][ ZDZ_Privacy_View::DM_OPTION ] = self::SAM;   // another administrator was chosen meanwhile
		$this->assertSame( 'gone', $this->runs() );
		$this->assertSame( array(), $this->turns );
		$this->assertSame( array(), $this->replies(), 'nothing is said in a thread that is no longer this account\'s with them' );
		$this->assertArrayNotHasKey( self::lock(), $GLOBALS['wpdb']->opt );
		$GLOBALS['zdz_test_options'][ ZDZ_Privacy_View::DM_OPTION ] = self::ALEX;
		$mid = $this->posts( self::ALEX, 'And on Saturday?' );
		$GLOBALS['wpdb']->msgs[ $mid ]['deleted_at'] = '2026-03-04 10:05:00';      // they deleted it meanwhile
		$this->assertSame( 'gone', $this->runs() );
		$this->assertSame( array(), $this->turns );
		$this->posts( self::ALEX, 'And on Sunday?' );
		$GLOBALS['zdz_test_options'][ ZANA_PV_DM_Agent::DAILY_OPT ] = 0;           // the line was taken away meanwhile
		$this->assertSame( 'gone', $this->runs() );
		$this->assertSame( array(), $this->turns, 'no line, no call' );
	}

	public function test_a_failed_turn_says_so_once_and_frees_the_thread(): void {
		$this->answer = array( 'ok' => false, 'error' => 'The Ai is busy or didn\'t answer in time. Try again in a minute.', 'answer' => 'The Ai is busy.' );
		$this->posts( self::ALEX, 'What time does the depot open?' );
		$this->assertSame( 'failed', $this->runs() );
		$this->assertSame( array( 'From a Privacy View account: I couldn\'t answer that just now. Please ask again, or ask in the chat.' ), $this->replies() );
		$this->assertArrayNotHasKey( self::lock(), $GLOBALS['wpdb']->opt );
		// the chat's turn threw: the same note, the thread free, nobody left signed in as the account
		$this->answer = new RuntimeException( 'boom' );
		$this->posts( self::ALEX, 'And on Saturday?' );
		$this->assertSame( 'error', $this->runs() );
		$this->assertCount( 2, $this->replies() );
		$this->assertSame( 0, (int) get_current_user_id() );
		$this->assertFalse( ZANA_PV_DM_Agent::in_turn() );
		$this->assertArrayNotHasKey( self::lock(), $GLOBALS['wpdb']->opt );
		// an answer with no words in it
		$this->answer = array( 'ok' => true, 'answer' => "<sub>_small print_</sub>\n<!-- ZANA_OPEN_PAGE: x -->" );
		$this->posts( self::ALEX, 'And on Sunday?' );
		$this->assertSame( 'done', $this->runs() );
		$this->assertSame( 'From a Privacy View account: I couldn\'t put that answer into a message. Please ask in the chat.', $this->replies()[2] );
	}

	/* ── one at a time, and limits ───────────────────────────────────────────────────────────────────── */

	public function test_a_message_written_while_a_turn_runs_is_answered_next(): void {
		$this->posts( self::ALEX, 'What time does the depot open?' );
		$this->assertCount( 1, $this->kicks );
		$second = $this->posts( self::ALEX, 'And who has the key?' );
		$this->assertSame( $second, $GLOBALS['zdz_test_transients'][ 'zana_pv_dm_again_' . self::THREAD ] );
		$this->assertCount( 2, $this->kicks, 'the turn still waiting to start is started again' );
		$this->assertSame( $this->kicks[0], $this->kicks[1], 'the same turn: no second one' );
		$third = $this->posts( self::ALEX, 'And is the gate locked?' );
		$this->assertCount( 2, $this->kicks, 'at most once a minute' );
		$this->assertSame( $third, $GLOBALS['zdz_test_transients'][ 'zana_pv_dm_again_' . self::THREAD ], 'the newest waits; the thread holds the others' );
		$this->assertSame( 1, count( array_filter( array_keys( $GLOBALS['wpdb']->opt ), function ( $k ) { return self::lock() === $k; } ) ), 'one lock row' );
		$this->assertSame( array(), $this->replies() );

		$this->assertSame( 'done', $this->runs() );
		$this->assertCount( 1, $this->turns );
		$this->assertSame( 'What time does the depot open?', $this->turns[0][2] );
		// the thread was freed and the message written meanwhile took it, with nobody signed in
		$this->assertCount( 1, $this->kicks );
		$this->assertArrayHasKey( self::lock(), $GLOBALS['wpdb']->opt );
		$this->assertSame( $third, $GLOBALS['zdz_test_transients'][ 'zana_pv_dm_turn_' . self::THREAD ]['message_id'] );
		$this->assertSame( 'done', $this->runs() );
		$this->assertSame( 'And is the gate locked?', $this->turns[1][2] );
		$this->assertSame( array( 'user', 'user', 'assistant' ), array_column( array_slice( $this->turns[1][3], 2 ), 'role' ), 'it reads the earlier questions and its own answer' );
		$this->assertCount( 2, $this->replies() );
		$this->assertArrayNotHasKey( self::lock(), $GLOBALS['wpdb']->opt );
		$this->assertSame( array(), $this->kicks );
	}

	public function test_the_lock_is_one_row_and_a_lock_left_by_a_turn_that_died_is_taken_over_once(): void {
		$this->assertTrue( ZANA_PV_DM_Agent::lock( self::THREAD ) );
		$this->assertFalse( ZANA_PV_DM_Agent::lock( self::THREAD ), 'two at once cannot both start' );
		$this->assertTrue( ZANA_PV_DM_Agent::lock( 78 ), 'another thread is another lock' );
		$GLOBALS['wpdb']->opt[ self::lock() ] = (string) ( time() - ZANA_PV_DM_Agent::BUSY_TTL - 5 );
		$this->assertTrue( ZANA_PV_DM_Agent::lock( self::THREAD ), 'older than any turn can be: taken over' );
		$this->assertFalse( ZANA_PV_DM_Agent::lock( self::THREAD ), 'by one taker' );
		ZANA_PV_DM_Agent::unlock( self::THREAD );
		$this->assertTrue( ZANA_PV_DM_Agent::lock( self::THREAD ) );
		$sql = implode( "\n", $GLOBALS['wpdb']->log );
		$this->assertStringContainsString( 'INSERT IGNORE INTO wp_options (option_name, option_value, autoload) VALUES (\'' . self::lock() . '\'', $sql, 'one statement' );
		$this->assertStringContainsString( 'AND CAST(option_value AS UNSIGNED) < ', $sql, 'one conditional statement for the take-over' );
		unset( $GLOBALS['wpdb'] );
		$this->assertFalse( ZANA_PV_DM_Agent::lock( self::THREAD ), 'no database, no lock, no turn' );
	}

	public function test_the_days_line_is_counted_capped_and_said_once(): void {
		$this->cost = 900;
		$this->posts( self::ALEX, 'What time does the depot open?' );
		$this->runs();
		$this->assertSame( 900, ZANA_PV_DM_Agent::spent_today() );
		$this->assertSame( 1100, ZANA_PV_DM_Agent::left_milli() );
		$this->cost = 0;
		$this->posts( self::ALEX, 'And who has the key?' );
		$this->runs();
		$this->assertSame( 1.1, $this->turns[1][6], 'the next call\'s cap is what is left' );
		$this->assertSame( 1000, ZANA_PV_DM_Agent::spent_today(), 'a reply never counts for less than a twentieth of the line' );
		$this->cost = 5000;                                // one costly turn: the line is crossed
		$this->posts( self::ALEX, 'List every open job with its notes.' );
		$this->runs();
		$this->assertSame( 0, ZANA_PV_DM_Agent::left_milli() );
		$n = count( $this->replies() );
		$this->posts( self::ALEX, 'And the closed ones?' );
		$this->assertSame( array(), $this->kicks, 'no turn is started' );
		$this->assertCount( $n + 1, $this->replies() );
		$this->assertStringContainsString( 'I\'ve used today\'s limit for answering here', $this->replies()[ $n ] );
		$this->assertArrayNotHasKey( self::lock(), $GLOBALS['wpdb']->opt );
		$this->posts( self::ALEX, 'Are you there?' );
		$this->assertCount( $n + 1, $this->replies(), 'said once, then nothing more today' );
		$this->assertCount( 3, $this->turns );
		ZANA_Allowance::$today = function () { return '2026-03-05'; };
		$this->assertSame( 0, ZANA_PV_DM_Agent::spent_today(), 'a new day starts at nothing' );
		$this->assertSame( 2000, ZANA_PV_DM_Agent::left_milli() );
	}

	public function test_the_line_running_out_between_the_start_and_the_run_sends_nothing(): void {
		$this->posts( self::ALEX, 'What time does the depot open?' );
		ZANA_PV_DM_Agent::add_spend( 2000 );
		$this->assertSame( 'cap', $this->runs() );
		$this->assertSame( array(), $this->turns );
		$this->assertCount( 1, $this->replies() );
		$this->assertStringContainsString( 'today\'s limit', $this->replies()[0] );
	}

	public function test_each_call_of_the_turn_is_capped_at_what_is_left_and_nobody_elses_is_touched(): void {
		$this->assertNull( ZANA_PV_DM_Agent::call_cap( null, self::ROBIN ), 'outside a turn: no line of its own' );
		$this->assertSame( 0.4, ZANA_PV_DM_Agent::call_cap( 0.4, self::ROBIN ) );
		ZANA_PV_DM_Agent::add_spend( 1500 );
		$seen = array();
		ZANA_PV_DM_Agent::$turn = function ( $pv ) use ( &$seen ) {
			$seen[] = ZANA_PV_DM_Agent::call_cap( null, $pv );          // 0.50 left
			$seen[] = ZANA_PV_DM_Agent::call_cap( 0.2, $pv );           // an earlier, narrower line stays
			$seen[] = ZANA_PV_DM_Agent::call_cap( 3.0, $pv );           // a wider one is narrowed
			$seen[] = ZANA_PV_DM_Agent::call_cap( null, self::ALEX );   // someone else's call
			ZANA_Allowance::add_milli( $pv, 350 );                      // a first call was charged
			$seen[] = ZANA_PV_DM_Agent::call_cap( null, $pv );          // 0.15 left for the next
			ZANA_Allowance::add_milli( $pv, 400 );
			$seen[] = ZANA_PV_DM_Agent::call_cap( null, $pv );          // nothing left: nothing may be sent
			$seen[] = ZANA_LLM_Client::call_cap( $pv );
			return array( 'ok' => true, 'answer' => 'Done.' );
		};
		$this->posts( self::ALEX, 'What time does the depot open?' );
		$this->runs();
		$this->assertSame( array( '0.5', '0.2', '0.5', 'none', '0.15', '0', '0' ), array_map( function ( $v ) { return null === $v ? 'none' : (string) round( $v, 4 ); }, $seen ) );
		$this->assertSame( 2250, ZANA_PV_DM_Agent::spent_today() );
	}

	public function test_the_chats_client_sends_nothing_once_the_line_is_used_up(): void {
		$r = zdz_test_asst_router( array( ZDZ_LLM_Fake::answer( 'It opens at eight.' ), ZDZ_LLM_Fake::answer( 'It opens at eight.' ) ) );
		$coder                    = $r->test_coder;
		ZANA_LLM_Client::$decoder = function ( $t ) use ( $coder ) { return $coder->decode( $t, true ); };
		$GLOBALS['zdz_test_user_roles'][ self::ROBIN ] = array( 'zdz_tech' );
		$ask = array( array( 'role' => 'user', 'content' => 'What time does the depot open?' ) );
		$cap = null;
		$GLOBALS['zdz_test_filters']['zana_llm_call_cap_usd'] = function ( $c, $uid ) use ( &$cap ) { return self::ROBIN === (int) $uid ? $cap : $c; };

		$cap = 0.0;
		$out = ( new ZANA_LLM_Client( self::ROBIN ) )->chat( $ask );
		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'allowance', $out['kind'] );
		$this->assertSame( array(), $r->fake->requests, 'used up: nothing reached the provider' );
		$cap = 'not a number';
		$this->assertFalse( ( new ZANA_LLM_Client( self::ROBIN ) )->chat( $ask )['ok'], 'a line that cannot be read is never ignored' );
		$this->assertSame( array(), $r->fake->requests );
		$cap = 0.000001;
		$out = ( new ZANA_LLM_Client( self::ROBIN ) )->chat( $ask );
		$this->assertFalse( $out['ok'], 'the router refuses a call that would cost more than the line has left' );
		$this->assertSame( array(), $r->fake->requests );
		$cap = 0.5;
		$out = ( new ZANA_LLM_Client( self::ROBIN ) )->chat( $ask );
		$this->assertTrue( $out['ok'], (string) $out['error'] );
		$this->assertCount( 1, $r->fake->requests );
		$cap = null;
		$this->assertTrue( ( new ZANA_LLM_Client( self::ROBIN ) )->chat( $ask )['ok'], 'no line of its own: the router\'s cap' );
		$this->assertNull( ZANA_LLM_Client::call_cap( self::ALEX ) );
	}

	public function test_twenty_an_hour_then_a_note_and_no_turn(): void {
		$GLOBALS['zdz_test_transients'][ 'zana_pv_dm_hour_' . self::THREAD ] = array_merge( array( time() - 4000 ), array_fill( 0, ZANA_PV_DM_Agent::HOURLY_MAX - 1, time() - 60 ) );
		$this->posts( self::ALEX, 'What time does the depot open?' );
		$this->assertCount( 1, $this->kicks, 'the one from over an hour ago does not count' );
		$this->runs();
		$n = count( $this->replies() );
		$this->posts( self::ALEX, 'And who has the key?' );
		$this->assertSame( array(), $this->kicks );
		$this->assertStringContainsString( 'That\'s a lot of questions in an hour', $this->replies()[ $n ] );
		$this->posts( self::ALEX, 'Hello?' );
		$this->assertCount( $n + 1, $this->replies(), 'said once' );
		$this->assertArrayNotHasKey( self::lock(), $GLOBALS['wpdb']->opt );
	}

	public function test_the_watchdog_starts_a_waiting_turn_again_and_gives_up_in_the_end(): void {
		$this->posts( self::ALEX, 'What time does the depot open?' );
		list( $conv, $token ) = $this->kicks[0];
		$this->kicks                      = array();
		$GLOBALS['zdz_test_current_user'] = 0;
		for ( $try = 1; $try < ZANA_PV_DM_Agent::WATCH_TRIES; $try++ ) {
			ZANA_PV_DM_Agent::watch( $conv, $token, $try );
		}
		$this->assertCount( ZANA_PV_DM_Agent::WATCH_TRIES - 1, $this->kicks, 'each time it looks' );
		$this->assertSame( array(), $this->replies(), 'it waits while the turn may still finish' );
		ZANA_PV_DM_Agent::watch( $conv, str_repeat( 'e', 32 ), ZANA_PV_DM_Agent::WATCH_TRIES );
		$this->assertSame( array(), $this->replies(), 'not this turn: nothing' );
		ZANA_PV_DM_Agent::watch( $conv, $token, ZANA_PV_DM_Agent::WATCH_TRIES );
		$this->assertSame( array( 'From a Privacy View account: I couldn\'t answer that just now. Please ask again, or ask in the chat.' ), $this->replies() );
		$this->assertArrayNotHasKey( self::lock(), $GLOBALS['wpdb']->opt, 'and the thread is free' );
		$this->assertSame( 'none', ZANA_PV_DM_Agent::run( $conv, $token ), 'a runner that turns up afterwards says nothing more' );
		ZANA_PV_DM_Agent::watch( $conv, $token, ZANA_PV_DM_Agent::WATCH_TRIES );
		$this->assertCount( 1, $this->replies() );
		$this->assertSame( array(), $this->turns );
	}

	/* ── the answer as a message ───────────────────────────────────────────────────────────────────── */

	public function test_something_to_do_is_a_draft_in_words_with_a_link_that_carries_no_words(): void {
		$answer = "Here is a note for the crew.\n\n"
			. "[ZANA_POST_DRAFT:#crew]The depot opens late on Friday.\n\nBring your own keys.[/ZANA_POST_DRAFT]\n\n"
			. "See [the handbook](https://example.com/handbook?x=(1)) or www.example.org/a, and ![a picture](https://example.com/p.png).\n"
			. "[ZDZ_CONFIRM:" . str_repeat( 'ab', 16 ) . "]\n"
			. '<button type="button" class="zana-charts-open" data-zch="abc">Chart 1: Jobs</button>'
			. "\n\n<sub>_Anything above that came from the web is unverified._</sub>";
		$ask    = 'Post a note to the crew: the depot opens late on Friday, bring keys.';
		$body   = ZANA_PV_DM_Agent::for_dm( $answer, $ask, self::ALEX );
		$this->assertStringContainsString( "Draft (nothing was done):\nThe depot opens late on Friday.\n\nBring your own keys.", $body );
		$this->assertStringContainsString( 'See the handbook or [link removed], and a picture.', $body, 'a link keeps its words; no address a model wrote is passed on' );
		foreach ( array( 'ZANA_POST_DRAFT', 'ZDZ_CONFIRM', '<button', 'Chart 1', '<sub>', 'unverified', 'example.com/handbook', 'example.org', 'p.png', '[/' ) as $gone ) {
			$this->assertStringNotContainsString( $gone, $body, $gone );
		}
		$this->assertSame( 1, preg_match( '~\n\nTo do this, open it in your chat \(your request is sent there; then press the button on the card\):\nhttps://app\.example\.com/\?zana_ask=([0-9a-f]{24})$~', $body, $m ), $body );
		$this->assertSame( 1, preg_match_all( '~https?://~', $body ), 'the only address is the one built here' );
		$this->assertStringNotContainsString( 'crew', (string) parse_url( substr( $body, (int) strrpos( $body, 'https://' ) ), PHP_URL_QUERY ), 'an opaque id, never the request\'s words' );
		// the words: only for the person the link was made for, and only once
		$this->assertSame( '', ZANA_PV_DM_Agent::ask_text( $m[1], self::ROBIN ), 'not the account' );
		$this->assertSame( '', ZANA_PV_DM_Agent::ask_text( $m[1], self::SAM ), 'not another administrator' );
		$this->assertSame( '', ZANA_PV_DM_Agent::ask_text( str_repeat( '0', 24 ), self::ALEX ), 'not a made-up id' );
		$this->assertSame( '', ZANA_PV_DM_Agent::ask_text( strtoupper( $m[1] ), self::ALEX ) );
		$this->assertSame( '', ZANA_PV_DM_Agent::ask_text( $m[1], 0 ) );
		$this->assertSame( $ask, ZANA_PV_DM_Agent::ask_text( $m[1], self::ALEX ) );
		$this->assertSame( '', ZANA_PV_DM_Agent::ask_text( $m[1], self::ALEX ), 'a second opening sends nothing' );
	}

	public function test_which_answers_get_the_link_and_how_drafts_are_read(): void {
		$plain = ZANA_PV_DM_Agent::for_dm( 'It opens at eight.', 'When does it open?', self::ALEX );
		$this->assertSame( 'It opens at eight.', $plain, 'an answer is just an answer' );
		$refused = ZANA_Actions::refusal_words( 'privacy_view', '' ) . "\n\nI can draft it instead.";
		$this->assertStringContainsString( '?zana_ask=', ZANA_PV_DM_Agent::for_dm( $refused, 'Book Friday at eight.', self::ALEX ), 'it said it could not do it from this account' );
		$own = "Draft (nothing was done):\nTo Jordan: the depot opens late.";
		$this->assertStringContainsString( '?zana_ask=', ZANA_PV_DM_Agent::for_dm( $own, 'Tell Jordan.', self::ALEX ), 'the model wrote its draft as it was told to' );
		$this->assertStringNotContainsString( '?zana_ask=', ZANA_PV_DM_Agent::for_dm( $own, '', self::ALEX ), 'no request, no link' );
		$this->assertStringNotContainsString( '?zana_ask=', ZANA_PV_DM_Agent::for_dm( $own, 'Tell Jordan.', 0 ), 'nobody to make it for' );
		// an email draft and a message draft are drafts too; one left open ends at the next blank line
		$n = 0;
		$w = ZANA_PV_DM_Agent::words( "[ZDZ_EMAIL_DRAFT:Riley]to: riley@example.com\nsubject: Friday\n---\nHi Riley, confirmed.[/ZDZ_EMAIL_DRAFT]\n\n[ZANA_DM_DRAFT:@jordan]The depot opens late.\n\nThe rest of the answer stays.", $n );
		$this->assertSame( 2, $n );
		$this->assertSame( "Draft (nothing was done):\nto: riley@example.com\nsubject: Friday\n---\nHi Riley, confirmed.\n\nDraft (nothing was done):\nThe depot opens late.\n\nThe rest of the answer stays.", $w );
		$n = 0;
		$this->assertSame( 'Open the estimate and check the total.', ZANA_PV_DM_Agent::words( '[ZEST_OPEN:12]Open the estimate[/ZEST_OPEN] and check the total.', $n ), 'any other token: its words stay, and it is no draft' );
		$this->assertSame( 0, $n );
		$long = ZANA_PV_DM_Agent::for_dm( str_repeat( 'word ', 3000 ), 'x', self::ALEX );
		$this->assertLessThanOrEqual( ZANA_PV_DM_Agent::BODY_MAX + 2, mb_strlen( $long ) );
		$this->assertSame( "a\n\nb", ZANA_PV_DM_Agent::plain( "a\n\n\n\nb" ) );
		$this->assertSame( "one\ntwo & three", ZANA_PV_DM_Agent::plain( 'one<br>two &amp; <b>three</b>' ) );
	}

	public function test_a_draft_in_a_reply_ends_with_the_link_and_the_request_route_gives_the_words_once(): void {
		$this->answer = array( 'ok' => true, 'answer' => "Draft (nothing was done):\nTo Jordan: the depot opens late on Friday." );
		$this->posts( self::ALEX, 'Tell Jordan the depot opens late on Friday.' );
		$this->runs();
		$reply = $this->replies()[0];
		$this->assertSame( 1, preg_match( '~\?zana_ask=([0-9a-f]{24})$~', $reply, $m ), $reply );
		$GLOBALS['zdz_test_current_user'] = self::ROBIN;
		$this->assertFalse( ZANA_PV_DM_Agent::can_ask(), 'never an account in Privacy View' );
		$GLOBALS['zdz_test_current_user'] = self::SHARED;
		$this->assertFalse( ZANA_PV_DM_Agent::can_ask(), 'never the shared device' );
		$GLOBALS['zdz_test_current_user'] = 0;
		$this->assertFalse( ZANA_PV_DM_Agent::can_ask() );
		$GLOBALS['zdz_test_current_user'] = self::SAM;
		$this->assertTrue( ZANA_PV_DM_Agent::can_ask() );
		$no = ZANA_PV_DM_Agent::rest_ask( new WP_REST_Request( array( 'id' => $m[1] ) ) );
		$this->assertInstanceOf( WP_Error::class, $no, 'signed in, but the link is not theirs' );
		$this->assertSame( array( 'status' => 404 ), $no->get_error_data() );
		$GLOBALS['zdz_test_current_user'] = self::ALEX;
		$this->assertSame( array( 'text' => 'Tell Jordan the depot opens late on Friday.' ), ZANA_PV_DM_Agent::rest_ask( new WP_REST_Request( array( 'id' => $m[1] ) ) )->get_data() );
		$this->assertInstanceOf( WP_Error::class, ZANA_PV_DM_Agent::rest_ask( new WP_REST_Request( array( 'id' => $m[1] ) ) ), 'once' );
		// the page offers it to a person, never to the account or the shared device
		$this->assertTrue( ZANA_Drafts_Page::features( self::ALEX )['ask'] );
		$this->assertTrue( ZANA_Drafts_Page::features( self::JORDAN )['ask'] );
		$this->assertFalse( ZANA_Drafts_Page::features( self::ROBIN )['ask'] );
		$this->assertFalse( ZANA_Drafts_Page::features( self::SHARED )['ask'] );
		$this->assertFalse( ZANA_Drafts_Page::features( 0 )['ask'] );
		$this->assertSame( ZANA_PV_DM_Agent::ASK_PARAM, ZANA_Drafts_Page::config( self::ALEX )['askParam'] );
	}

	/* ── what is stored, and what the account sees ───────────────────────────────────────────────────────────────────── */

	public function test_the_reply_is_stored_with_the_real_details_and_masked_again_on_every_view(): void {
		$this->answer = array( 'ok' => true, 'answer' => 'Riley Example owes $120 and is waiting for a call.' );
		$this->posts( self::ALEX, 'What does Riley Example owe?' );
		$this->runs();
		$stored = end( $GLOBALS['wpdb']->msgs );
		$this->assertSame( self::ROBIN, (int) $stored['author_user_id'] );
		$this->assertSame( 'From a Privacy View account: Riley Example owes $120 and is waiting for a call.', $stored['body'], 'the administrator reads the real details' );

		// the account opens its thread: Messages reads the row, and the reply it is sent is masked, key by key
		$GLOBALS['zdz_test_current_user'] = self::ROBIN;
		$coder = new ReflectionProperty( 'ZDZ_Privacy_View', 'coder' );
		$coder->setAccessible( true );
		$coder->setValue( null, new ZDZ_Test_Asst_Pv_Coder() );
		$rows = ZIM_Messages::fetch_before( self::THREAD, 0, 10 );
		$mine = end( $rows );
		$this->assertSame( $stored['body'], $mine['body_raw'], 'what Messages reads is the stored row' );
		$json = (string) json_encode( array( 'success' => true, 'data' => array(
			'messages' => array( $mine ),
			'dms'      => array( array( 'id' => self::THREAD, 'last_message' => array( 'preview' => mb_substr( $mine['body'], 0, 60 ), 'html' => '<p>' . $mine['body'] . '</p>' ) ) ),
			'quote'    => array( 'of' => array( 'body' => $mine['body'] ) ),
		) ) );
		$views = array();
		foreach ( array( 'Bcdf', 'Gkmn' ) as $tag ) {
			ZDZ_Privacy_View::new_view( $tag );
			$masked = ZDZ_Privacy_View::mask_json_text( $json );
			$this->assertIsString( $masked );
			$this->assertStringNotContainsString( 'Riley', $masked, 'in no key: body, body_raw, preview, html or a quoted message' );
			$this->assertStringNotContainsString( 'Example owes', $masked );
			$out = json_decode( $masked, true );
			$this->assertIsArray( $out );
			$this->assertSame( 1, preg_match( '/([BCDFGHJKLMNPQRSTVWXZ][bcdfghjklmnpqrstvwxz]{3}·[0-9A-Z]{4}[0-9A-Z_]) owes/u', $out['data']['messages'][0]['body'], $m ), $out['data']['messages'][0]['body'] );
			$this->assertStringContainsString( $m[1] . ' owes', $out['data']['messages'][0]['body_raw'] );
			$this->assertStringContainsString( $m[1] . ' owes', $out['data']['dms'][0]['last_message']['preview'] );
			$this->assertStringContainsString( $m[1] . ' owes', $out['data']['dms'][0]['last_message']['html'] );
			$this->assertStringContainsString( $m[1] . ' owes', $out['data']['quote']['of']['body'] );
			$this->assertStringContainsString( 'is waiting for a call', $out['data']['messages'][0]['body'], 'the words around the name stay' );
			$views[] = $m[1];
		}
		$this->assertNotSame( $views[0], $views[1], 'a new code on every view' );
		$this->assertSame( 'From a Privacy View account: Riley Example owes $120 and is waiting for a call.', end( $GLOBALS['wpdb']->msgs )['body'], 'the stored row never changes' );
	}

	/* ── its chats ───────────────────────────────────────────────────────────────────── */

	public function test_it_reads_the_very_answer_a_message_came_from_in_its_own_chats_only(): void {
		$db    = $GLOBALS['wpdb'];
		$chat  = $db->session( self::ROBIN, 'Friday list' );
		$db->said_in_chat( $chat, self::ROBIN, 'user', 'An older question' );
		$db->said_in_chat( $chat, self::ROBIN, 'assistant', 'An older answer' );
		$db->said_in_chat( $chat, self::ROBIN, 'user', 'Which jobs are on Friday?' );
		$a     = $db->said_in_chat( $chat, self::ROBIN, 'assistant', "Three jobs are on Friday.\n[ZDZ_CONFIRM:" . str_repeat( 'ab', 16 ) . "]\n<sub>_small print_</sub> See https://example.com/x" );
		$db->said_in_chat( $chat, self::ROBIN, 'user', 'A later question' );
		$db->said_in_chat( $chat, self::ROBIN, 'assistant', 'A later answer' );
		$other = $db->session( self::SAM, 'Not its chat' );
		$db->said_in_chat( $other, self::SAM, 'user', 'A private question' );
		$b     = $db->said_in_chat( $other, self::SAM, 'assistant', 'A private answer' );
		$last  = $db->session( self::ROBIN, 'Keys' );
		$db->said_in_chat( $last, self::ROBIN, 'user', 'Who has the key?' );
		$db->said_in_chat( $last, self::ROBIN, 'assistant', 'Jordan has the key.' );
		$db->said_in_chat( $last, self::ROBIN, 'user', 'Not answered yet' );

		$m1 = $this->posts( self::ROBIN, 'Three jobs are on Friday.' );
		$m2 = $this->posts( self::ROBIN, 'Something from a chat that is not its own.' );
		$m3 = $this->posts( self::ROBIN, 'Jordan has the key.' );
		$m4 = $this->posts( self::ALEX, 'Posted by the administrator: no chat of the account behind it.' );
		$this->kicks = array();
		$db->query( "DELETE FROM wp_options WHERE option_name = '" . self::lock() . "'" );
		unset( $GLOBALS['zdz_test_transients'] );
		$GLOBALS['zdz_test_user_meta'][ self::ROBIN ][ ZIM_DM_Send::SOURCES_META ] = array(
			$m1 => array( 's' => $chat, 'm' => $a ),
			$m2 => array( 's' => $other, 'm' => $b ),
			$m3 => array( 's' => $last, 'm' => 0 ),      // the message does not say which answer: the chat's newest pair
			$m4 => array( 's' => $chat, 'm' => $a ),     // not the account's message: never read
		);
		$rows  = ZIM_Messages::fetch_before( self::THREAD, 0, 30 );
		$notes = ZANA_PV_DM_Agent::chat_notes( self::ROBIN, $rows );
		$this->assertSame( "Asked: Who has the key?\nAnswer: Jordan has the key.\n\nAsked: Which jobs are on Friday?\nAnswer: Three jobs are on Friday.\n\n See [link removed]", $notes, 'the newest message first; no live card, no small print, no address' );
		$this->assertStringNotContainsString( 'private', $notes, 'never someone else\'s chat' );
		$this->assertStringNotContainsString( 'later', $notes, 'the very answer, not that chat\'s latest' );

		$this->posts( self::ALEX, 'So who opens on Friday?' );
		$this->runs();
		$history = $this->turns[0][3];
		$this->assertSame( 0, strpos( $history[2]['content'], "Notes from the chats your messages here came from (background, not a question):\n\nAsked: Who has the key?" ) );
		$this->assertSame( array( 'role' => 'assistant', 'content' => 'Noted.' ), $history[3] );
		$this->assertSame( 'Three jobs are on Friday.', $history[4]['content'] );

		// a thread's own notes are never read back as one of its chats
		$notes_chat = $this->turns[0][1];
		$GLOBALS['zdz_test_user_meta'][ self::ROBIN ][ ZIM_DM_Send::SOURCES_META ] = array( $m1 => array( 's' => $notes_chat, 'm' => 0 ) );
		$this->assertSame( '', ZANA_PV_DM_Agent::chat_notes( self::ROBIN, $rows ) );
		$this->assertSame( '', ZANA_PV_DM_Agent::chat_notes( self::ROBIN, array() ) );
	}

	public function test_its_notes_are_never_listed_and_nobody_writes_in_them(): void {
		$db    = $GLOBALS['wpdb'];
		$chat  = $db->session( self::ROBIN, 'Friday list' );
		$this->posts( self::ALEX, 'What time does the depot open?' );
		$this->runs();
		$notes = $this->turns[0][1];
		$this->assertNotSame( $chat, $notes );
		$this->assertSame( array( $chat ), array_column( ZANA_Chat::sessions( self::ROBIN ), 'id' ), 'its chats, without the notes' );
		$before = $db->chat;
		$GLOBALS['zdz_test_current_user'] = self::ROBIN;
		$r = ZANA_Chat::send( self::ROBIN, $notes, 'hello' );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'These are notes for a Messages thread; ask in a chat.', $r['error'] );
		$this->assertSame( $before, $db->chat, 'nothing was stored' );
		$this->assertSame( 0, $r['session_id'] );
		// the same notes chat is used for the next reply in the thread
		$this->posts( self::ALEX, 'And who has the key?' );
		$this->runs();
		$this->assertSame( $notes, $this->turns[1][1] );
		$this->assertCount( 2, $db->sessions );
	}

	/* ── how it is wired ───────────────────────────────────────────────────────────────────── */

	public function test_the_wiring_of_the_listener_the_runner_and_the_chats_own_turn(): void {
		$inc   = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/analytics/';
		$agent = (string) file_get_contents( $inc . 'includes/class-zana-pv-dm-agent.php' );
		$this->assertStringContainsString( "add_action( 'zim_message_posted', array( __CLASS__, 'on_posted' ), 10, 3 );", $agent, 'it listens to Messages with the message, the conversation and the author' );
		$this->assertStringContainsString( "add_filter( 'zana_llm_call_cap_usd', array( __CLASS__, 'call_cap' ), 10, 2 );", $agent );
		foreach ( array( "'wp_ajax_' . self::RUN_ACTION", "'wp_ajax_nopriv_' . self::RUN_ACTION" ) as $hook ) {
			$this->assertStringContainsString( "add_action( $hook, array( __CLASS__, 'ajax_run' ) );", $agent );
		}
		$run = substr( $agent, (int) strpos( $agent, 'public static function ajax_run' ), 1500 );
		$this->assertLessThan( strpos( $run, 'self::run( $conv, $token );' ), strpos( $run, 'hash_equals( self::sig( $conv, $token ), $sig )' ), 'the signature is checked before anything runs' );
		$this->assertStringContainsString( "wp_die( '', '', array( 'response' => 403 ) );", $run );
		$loop = substr( $agent, (int) strpos( $agent, 'private static function loopback' ), 900 );
		$this->assertStringNotContainsString( 'cookies', $loop, 'the start carries nobody\'s sign-in' );
		$this->assertStringContainsString( "'blocking'  => false", $loop, 'and the administrator\'s post does not wait for it' );
		$this->assertStringNotContainsString( 'ZIM_Messages::post', $agent, 'it posts through Messages\' seam' );
		$this->assertStringContainsString( "apply_filters( 'zim_dm_send', null, \$pv, \$partner, \$body, array() )", $agent );
		$this->assertSame( 0, preg_match( '/error_log\([^;]*\$(?:text|body|question|ask)\b/', $agent ), 'a message\'s words are never logged' );

		$chat = (string) file_get_contents( $inc . 'includes/class-zana-chat.php' );
		$this->assertStringContainsString( 'public static function send( int $user_id, int $session_id, string $message, array $opts = array() ): array {', $chat );
		$this->assertStringContainsString( "\$history    = isset( \$opts['history'] ) && is_array( \$opts['history'] ) ? \$opts['history'] : self::history( \$session_id, \$user_id );", $chat );
		$this->assertLessThan( strpos( $chat, '$session_id = self::ensure_session(' ), strpos( $chat, 'ZANA_PV_DM_Agent::closed_to( $session_id )' ), 'the notes are refused before anything is stored' );
		foreach ( array( 'class-zana-rest.php', 'class-zana-ajax.php', 'class-zana-background.php' ) as $f ) {
			$this->assertSame( 1, preg_match_all( '/=\s*ZANA_Chat::send\(([^;]*?)\);/s', (string) file_get_contents( $inc . 'includes/' . $f ), $calls ), $f );
			foreach ( $calls[1] as $args ) {
				$this->assertSame( 2, substr_count( $args, ',' ), $f . ': the person, the chat and the message; never a history of a request\'s making' );
			}
		}
		$app = (string) file_get_contents( $inc . 'app.php' );
		foreach ( array( 'answer-rules', 'fact-starters', 'style-memory', 'pv-dm-agent', 'drafts-page' ) as $f ) {
			$this->assertStringContainsString( "require_once ZANA_DIR . 'includes/class-zana-" . $f . ".php';", $app );
			$this->assertFileExists( $inc . 'includes/class-zana-' . $f . '.php' );
		}
		$this->assertStringContainsString( 'ZANA_PV_DM_Agent::boot();', $app );
	}
}
