<?php
/**
 * How an administrator writes, learned from their edits to the chat's drafts (ZANA_Style_Memory), and Edit on a held
 * direct message (ZANA_DM_Card::revise):
 *
 *   - who: administrators only; never the shared device, an account in Privacy View, anyone else or nobody; one
 *     setting turns it off for everyone;
 *   - what is kept: a draft before and after (plain text, capped, the last 30), and a short typed correction; never a
 *     bare go-ahead or a long new request;
 *   - a rule is about style: no e-mail address, link, long number or customer's code; the person's own rules come
 *     first, are never repeated and never replaced by a pass;
 *   - the pass is ONE router call (role extract, a schema'd answer, the chat's caller); customer details are coded
 *     before it leaves; a failed call, a refusal or a wrong shape changes nothing;
 *   - the instructions get "HOW <NAME> WRITES" after "About you", for that person only;
 *   - a typed correction to an email, message or post draft is kept; a question or another app's action is not;
 *   - a held message's words may be changed only by its own person, in its own chat, for the same recipient, as plain
 *     text, within the time it had left; what Send sends is the server's copy;
 *   - the page offers Edit and "How you write" only when the server says so.
 *
 * Neutral data only (Alex, Jordan, Robin, Sam, Riley Example, example.com).
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/assistant-doubles.php';

$zdz_asst_root = dirname( __DIR__, 2 );
require_once $zdz_asst_root . '/zorderz/inc/class-zdz-capabilities.php';
require_once $zdz_asst_root . '/zorderz/inc/class-zdz-privacy-view.php';
foreach ( array( 'markers', 'db', 'allowance', 'llm-client', 'pseudonym-route', 'email-turn', 'user-memory', 'dm-card', 'style-memory', 'drafts-page' ) as $zdz_asst_f ) {
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

final class AssistantStyleMemoryTest extends TestCase {

	const ALEX   = 41;   // an administrator
	const JORDAN = 42;   // staff
	const ROBIN  = 43;   // an administrator's account put in Privacy View
	const SHARED = 44;   // the shared device
	const SAM    = 45;   // another administrator

	private static $js = null;

	/** What the router was asked: [ role, request, ctx ]. */
	private $asked = array();

	/** Who a pass was scheduled for. */
	private $scheduled = array();

	protected function setUp(): void {
		unset( $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_user_meta'], $GLOBALS['zdz_test_transients'], $GLOBALS['zdz_test_users'] );
		$GLOBALS['zdz_test_user_roles']   = array(
			self::ALEX   => array( 'administrator' ),
			self::JORDAN => array( 'zdz_tech' ),
			self::ROBIN  => array( 'administrator' ),
			self::SHARED => array( 'zdz_general' ),
			self::SAM    => array( 'administrator' ),
		);
		$GLOBALS['zdz_test_user_caps']    = array( self::JORDAN => array( 'zdz_access_app' ), self::SHARED => array( 'manage_options' ) );
		$GLOBALS['zdz_test_user_names']   = array( self::ALEX => 'Alex Example', self::JORDAN => 'Jordan Example', self::ROBIN => 'Robin', self::SAM => 'Sam Example' );
		$GLOBALS['zdz_test_user_meta'][ self::ROBIN ]['zdz_privacy_view'] = '1';
		$GLOBALS['zdz_test_current_user'] = self::ALEX;
		$GLOBALS['wpdb']                  = new ZDZ_Test_Asst_DB();
		ZDZ_Privacy_View::flush();
		ZDZ_Capabilities::flush();
		ZANA_Allowance::$today    = function () { return '2026-03-04'; };
		ZANA_Style_Memory::$now   = null;
		ZANA_Style_Memory::$ask   = null;
		ZANA_DM_Card::$recipient  = null;
		ZANA_DM_Card::$claim      = null;
		$this->asked              = array();
		$this->scheduled          = array();
		ZANA_Style_Memory::$scheduler = function ( $uid ) { $this->scheduled[] = (int) $uid; };
	}

	protected function tearDown(): void {
		unset( $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_user_meta'], $GLOBALS['zdz_test_transients'],
			$GLOBALS['zdz_test_user_roles'], $GLOBALS['zdz_test_user_caps'], $GLOBALS['zdz_test_user_names'], $GLOBALS['zdz_test_current_user'], $GLOBALS['wpdb'] );
		ZANA_Style_Memory::$now       = null;
		ZANA_Style_Memory::$ask       = null;
		ZANA_Style_Memory::$scheduler = null;
		ZANA_Allowance::$today        = null;
		ZANA_DM_Card::$recipient     = null;
		ZANA_DM_Card::$claim         = null;
		ZANA_LLM_Client::$decoder    = null;
		zdz_test_asst_router_off();
		ZDZ_Privacy_View::flush();
		ZDZ_Capabilities::flush();
	}

	/** The router answers with these rules (or with $answer as it is), and what it was asked is kept. */
	private function model( $answer ): void {
		ZANA_Style_Memory::$ask = function ( $role, $request, $ctx ) use ( $answer ) {
			$this->asked[] = array( $role, $request, $ctx );
			if ( $answer instanceof \Throwable ) {
				throw $answer;
			}
			return $answer;
		};
	}

	private static function rules( array $pairs ): array {
		return array( 'text' => '{}', 'json' => array( 'rules' => array_map( function ( $p ) {
			return array( 'audience' => $p[0], 'rule' => $p[1] );
		}, $pairs ) ), 'usd' => 0.004, 'model' => 'example-large-1', 'tries' => 1, 'stop' => 'end_turn' );
	}

	private static function clock( int $at ): void {
		ZANA_Style_Memory::$now = function () use ( $at ) { return $at; };
	}

	/* ── who ───────────────────────────────────────────────────────────────────── */

	public function test_only_an_administrator_on_their_own_account_is_learned_from(): void {
		$this->assertTrue( ZANA_Style_Memory::enabled_for( self::ALEX ) );
		$this->assertFalse( ZANA_Style_Memory::enabled_for( self::JORDAN ), 'staff' );
		$this->assertFalse( ZANA_Style_Memory::enabled_for( 0 ), 'nobody' );
		$this->assertFalse( ZANA_Style_Memory::enabled_for( 999 ), 'no such person' );
		$this->assertFalse( ZANA_Style_Memory::enabled_for( self::SHARED ), 'the shared device, whatever it was granted' );
		$this->assertFalse( ZANA_Style_Memory::enabled_for( self::ROBIN ), 'an administrator\'s account in Privacy View' );
		$GLOBALS['zdz_test_options'][ ZANA_Style_Memory::OPTION ] = '0';
		$this->assertFalse( ZANA_Style_Memory::enabled_for( self::ALEX ), 'off for everyone' );
		$this->assertSame( 'off', ZANA_Style_Memory::record_edit( self::ALEX, 'email', 'a draft', 'another draft' ) );
		$this->assertSame( '', ZANA_Style_Memory::prompt_block( self::ALEX ) );
		$this->assertSame( '0', ZANA_Style_Memory::sanitize_on( '' ) );
		$this->assertSame( '0', ZANA_Style_Memory::sanitize_on( '0' ) );
		$this->assertSame( '1', ZANA_Style_Memory::sanitize_on( 'yes' ) );
	}

	/* ── recording ───────────────────────────────────────────────────────────────────── */

	public function test_an_edit_is_kept_as_plain_text_and_only_when_the_words_changed(): void {
		$this->assertSame( 'off', ZANA_Style_Memory::record_edit( self::JORDAN, 'email', 'Hello there.', 'Hi.' ) );
		$this->assertSame( 'same', ZANA_Style_Memory::record_edit( self::ALEX, 'email', "Hello  there.\n", ' hello there.' ), 'only spacing and case' );
		$this->assertSame( 'empty', ZANA_Style_Memory::record_edit( self::ALEX, 'email', 'Hello there.', '<p> </p>' ) );
		$this->assertSame( 'empty', ZANA_Style_Memory::record_edit( self::ALEX, 'email', '', 'Hi.' ) );
		$this->assertSame( array(), ZANA_Style_Memory::events( self::ALEX ) );
		self::clock( 1000 );
		$this->assertSame( 'recorded', ZANA_Style_Memory::record_edit( self::ALEX, 'email', '<p>Dear Riley,</p><p>We are <b>delighted</b> to confirm.</p>', "Hi Riley,<br>Confirmed &amp; booked." ) );
		$e = ZANA_Style_Memory::events( self::ALEX );
		$this->assertCount( 1, $e );
		$this->assertSame( array( 't' => 1000, 'type' => 'edit', 'kind' => 'email', 'before' => "Dear Riley,\nWe are delighted to confirm.", 'after' => "Hi Riley,\nConfirmed & booked." ), $e[0] );
		$this->assertSame( 'recorded', ZANA_Style_Memory::record_edit( self::ALEX, 'fax', 'One way.', 'Another way.' ) );
		$this->assertSame( 'text', ZANA_Style_Memory::events( self::ALEX )[1]['kind'], 'an unknown kind is kept as text' );
		$this->assertSame( array(), ZANA_Style_Memory::events( self::JORDAN ), 'nobody else\'s' );
	}

	public function test_a_typed_correction_is_kept_and_a_go_ahead_or_a_new_request_is_not(): void {
		foreach ( array( 'ok', 'Okay.', 'yes', 'yep', 'sure', 'send it', 'looks good', 'Perfect!', 'great', 'thanks', 'thank you', 'go ahead', 'go' ) as $go ) {
			$this->assertSame( 'skipped', ZANA_Style_Memory::record_instruction( self::ALEX, $go ), $go );
		}
		$this->assertSame( 'skipped', ZANA_Style_Memory::record_instruction( self::ALEX, str_repeat( 'please write a new one about the gate. ', 12 ) ), 'a long message is a new request' );
		$this->assertSame( 'skipped', ZANA_Style_Memory::record_instruction( self::ALEX, 'no' ), 'too short to teach anything' );
		$this->assertSame( array(), ZANA_Style_Memory::events( self::ALEX ) );
		$this->assertSame( 'off', ZANA_Style_Memory::record_instruction( self::JORDAN, 'Shorter, and drop the exclamation marks.' ) );
		self::clock( 2000 );
		$this->assertSame( 'recorded', ZANA_Style_Memory::record_instruction( self::ALEX, 'Shorter, and drop the exclamation marks.', 'dm' ) );
		$this->assertSame( array( array( 't' => 2000, 'type' => 'instruction', 'kind' => 'dm', 'text' => 'Shorter, and drop the exclamation marks.' ) ), ZANA_Style_Memory::events( self::ALEX ) );
		$this->assertSame( array( self::ALEX ), $this->scheduled, 'a pass is scheduled for that person, and only for a kept correction' );
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/analytics/includes/class-zana-style-memory.php' );
		$this->assertStringContainsString( "if ( ! wp_next_scheduled( self::HOOK, \$args ) ) {\n\t\t\twp_schedule_single_event( time() + 15, self::HOOK, \$args );", $src, 'once, however many corrections arrive meanwhile' );
		$this->assertStringContainsString( "add_action( self::HOOK, array( __CLASS__, 'cron_learn' ) );", $src, 'the pass is a scheduled hook' );
	}

	public function test_only_the_last_events_are_kept_and_each_is_capped(): void {
		for ( $i = 1; $i <= ZANA_Style_Memory::MAX_EVENTS + 5; $i++ ) {
			self::clock( $i );
			ZANA_Style_Memory::record_edit( self::ALEX, 'email', 'Draft number ' . $i, 'Changed draft number ' . $i );
		}
		$e = ZANA_Style_Memory::events( self::ALEX );
		$this->assertCount( ZANA_Style_Memory::MAX_EVENTS, $e );
		$this->assertSame( 'Draft number 6', $e[0]['before'], 'the oldest make room' );
		ZANA_Style_Memory::record_edit( self::ALEX, 'email', str_repeat( 'long ', 1000 ), 'Short.' );
		$last = ZANA_Style_Memory::events( self::ALEX )[ ZANA_Style_Memory::MAX_EVENTS - 1 ];
		$this->assertSame( ZANA_Style_Memory::MAX_TEXT, mb_strlen( $last['before'] ) );
	}

	/* ── a rule ───────────────────────────────────────────────────────────────────── */

	public function test_a_rule_is_about_style_and_names_nothing(): void {
		$this->assertSame( '', ZANA_Style_Memory::rule_problem( 'Keep emails to customers under six sentences.' ) );
		$this->assertNotSame( '', ZANA_Style_Memory::rule_problem( 'Short.' ), 'too short' );
		$this->assertNotSame( '', ZANA_Style_Memory::rule_problem( str_repeat( 'Write plainly. ', 20 ) ), 'too long' );
		$this->assertNotSame( '', ZANA_Style_Memory::rule_problem( 'Always copy alex@example.com on quotes.' ), 'an e-mail address' );
		$this->assertNotSame( '', ZANA_Style_Memory::rule_problem( 'End with a call to 555-0100.' ), 'a phone or document number' );
		$this->assertNotSame( '', ZANA_Style_Memory::rule_problem( 'Link to https://example.com in every email.' ), 'a link' );
		$this->assertNotSame( '', ZANA_Style_Memory::rule_problem( 'Send people to www.example.com to book.' ), 'a link without its scheme' );
		$this->assertNotSame( '', ZANA_Style_Memory::rule_problem( 'Always greet ' . ZDZ_Core_LLM::OPEN . 'c:12' . ZDZ_Core_LLM::CLOSE . ' warmly.' ), 'a customer, as the router writes one' );
		$this->assertSame( ZDZ_Core_LLM::OPEN, ZANA_Style_Memory::CODE_OPEN );
		$this->assertSame( ZDZ_Core_LLM::CLOSE, ZANA_Style_Memory::CODE_CLOSE );
	}

	public function test_the_persons_own_rules_are_added_listed_first_and_deleted_by_them(): void {
		$r = ZANA_Style_Memory::add_rule( self::ALEX, 'staff', "  First   names only,\n no titles. " );
		$this->assertIsArray( $r );
		$this->assertSame( array( 'staff', 'First names only, no titles.', 'added' ), array( $r['audience'], $r['rule'], $r['source'] ) );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{10}$/', $r['id'] );
		$this->assertSame( 'That rule is already on the list.', ZANA_Style_Memory::add_rule( self::ALEX, 'all', 'first names only, no  titles.' ) );
		$this->assertSame( 0, strpos( (string) ZANA_Style_Memory::add_rule( self::ALEX, 'all', 'Tiny.' ), 'Not saved: ' ), 'a refused rule says why' );
		$this->assertSame( 'Style learning is for administrator accounts.', ZANA_Style_Memory::add_rule( self::JORDAN, 'all', 'Keep it brief and plain.' ) );
		$this->assertSame( 'Style learning is for administrator accounts.', ZANA_Style_Memory::add_rule( self::ROBIN, 'all', 'Keep it brief and plain.' ) );
		$second = ZANA_Style_Memory::add_rule( self::ALEX, 'nobody', '<b>No exclamation marks.</b>' );
		$this->assertSame( array( 'all', 'No exclamation marks.' ), array( $second['audience'], $second['rule'] ), 'an unknown audience is all drafts; tags are not words' );
		$this->assertSame( array( $second['id'], $r['id'] ), array_column( ZANA_Style_Memory::rules( self::ALEX ), 'id' ), 'the newest of their own first' );
		$this->assertFalse( ZANA_Style_Memory::delete_rule( self::ALEX, 'ffffffffff' ) );
		$this->assertFalse( ZANA_Style_Memory::delete_rule( self::SAM, $r['id'] ), 'another person\'s rule is not theirs to delete' );
		$this->assertTrue( ZANA_Style_Memory::delete_rule( self::ALEX, $r['id'] ) );
		$this->assertSame( array( $second['id'] ), array_column( ZANA_Style_Memory::rules( self::ALEX ), 'id' ) );
	}

	/* ── the pass ───────────────────────────────────────────────────────────────────── */

	public function test_a_pass_rewrites_the_learned_rules_and_keeps_the_persons_own(): void {
		$own = ZANA_Style_Memory::add_rule( self::ALEX, 'staff', 'First names only, no titles.' );
		self::clock( 100 );
		ZANA_Style_Memory::record_edit( self::ALEX, 'email', 'Dear Riley Example, we are delighted to confirm your visit!', 'Hi Riley, your visit is confirmed.' );
		self::clock( 200 );
		ZANA_Style_Memory::record_instruction( self::ALEX, 'Shorter, and say by when.', 'dm' );
		$this->model( self::rules( array(
			array( 'customer', 'Open with "Hi" and the first name.' ),
			array( 'customer', 'No exclamation marks.' ),
			array( 'staff', 'first names only,  no titles.' ),                 // the person's own rule again
			array( 'all', 'Copy alex@example.com on every email.' ),          // not style
			array( 'all', 'Mention ' . ZDZ_Core_LLM::OPEN . 'c:12' . ZDZ_Core_LLM::CLOSE . ' by name in every reply.' ),   // a customer
			array( 'everyone', 'Keep every draft under six sentences.' ),      // no such audience
			array( 'STAFF', "Say what, where and\nby when." ),
			array( 'customer', 'No exclamation marks.' ),                      // twice
			'not a rule',
		) ) );
		$this->assertSame( 'learned 3', ZANA_Style_Memory::learn( self::ALEX ) );
		$rules = ZANA_Style_Memory::rules( self::ALEX );
		$this->assertSame( array( 'First names only, no titles.', 'Open with "Hi" and the first name.', 'No exclamation marks.', 'Say what, where and by when.' ), array_column( $rules, 'rule' ) );
		$this->assertSame( array( 'added', 'learned', 'learned', 'learned' ), array_column( $rules, 'source' ) );
		$this->assertSame( array( 'staff', 'customer', 'customer', 'staff' ), array_column( $rules, 'audience' ) );
		$this->assertSame( $own['id'], $rules[0]['id'], 'their own rule is kept as it was, first' );
		$this->assertSame( 200, (int) $GLOBALS['zdz_test_user_meta'][ self::ALEX ][ ZANA_Style_Memory::META_LEARNED ], 'learned up to the newest event' );

		// one call, the chat's caller, for this person, with a schema'd answer
		$this->assertCount( 1, $this->asked );
		list( $role, $req, $ctx ) = $this->asked[0];
		$this->assertSame( 'extract', $role );
		$this->assertArrayHasKey( $role, ZDZ_Core_LLM::ROLES );
		$this->assertSame( array( 'caller' => ZANA_LLM_Client::CALLER, 'user_id' => self::ALEX ), array_intersect_key( $ctx, array( 'caller' => 1, 'user_id' => 1 ) ) );
		$this->assertGreaterThan( time(), $ctx['deadline'] );
		$this->assertSame( ZANA_Style_Memory::AUDIENCES, $req['json_schema']['properties']['rules']['items']['properties']['audience']['enum'] );
		$this->assertTrue( ZDZ_Core_LLM::matches( array( 'rules' => array( array( 'audience' => 'staff', 'rule' => 'x' ) ) ), $req['json_schema'] ) );
		$this->assertFalse( ZDZ_Core_LLM::matches( array( 'rules' => array( array( 'audience' => 'everyone', 'rule' => 'x' ) ) ), $req['json_schema'] ) );
		$sent = $req['messages'][0]['content'];
		$this->assertStringContainsString( "THE PERSON'S OWN RULES (kept as they are):\n- [staff] First names only, no titles.", $sent );
		$this->assertStringContainsString( "CURRENT LEARNED RULES:\n(none yet)", $sent );
		$this->assertStringContainsString( "(email draft, edited)\nBEFORE:\nDear Riley Example, we are delighted to confirm your visit!\nAFTER:\nHi Riley, your visit is confirmed.", $sent );
		$this->assertStringContainsString( "(dm draft, correction they typed)\nShorter, and say by when.", $sent );
		$this->assertStringContainsString( 'it never names a customer or any other person', $req['system'] );
		$this->assertStringNotContainsString( 'Alex', $req['system'] . $sent, 'the person is not named to the model' );
		$this->assertSame( 4, ZANA_Allowance::spent_milli( self::ALEX ), 'what it cost is on the person\'s day' );

		// nothing new since: no call
		$this->assertSame( 'nothing new', ZANA_Style_Memory::learn( self::ALEX ) );
		$this->assertCount( 1, $this->asked );
		// a later pass sees the learned rules and replaces them, never the person's own
		self::clock( 300 );
		ZANA_Style_Memory::record_instruction( self::ALEX, 'Sign off with just my first name.' );
		$this->model( self::rules( array( array( 'all', 'Sign off with the first name only.' ) ) ) );
		$this->assertSame( 'learned 1', ZANA_Style_Memory::learn( self::ALEX ) );
		$this->assertCount( 2, $this->asked );
		$second = $this->asked[1][1]['messages'][0]['content'];
		$this->assertStringContainsString( "CURRENT LEARNED RULES:\n- [customer] Open with \"Hi\" and the first name.\n- [customer] No exclamation marks.\n- [staff] Say what, where and by when.", $second );
		$this->assertStringNotContainsString( 'Dear Riley Example', $second, 'only what is new since the last pass' );
		$this->assertSame( array( 'First names only, no titles.', 'Sign off with the first name only.' ), array_column( ZANA_Style_Memory::rules( self::ALEX ), 'rule' ) );
	}

	public function test_a_failed_call_a_refusal_or_a_wrong_shape_changes_nothing(): void {
		ZANA_Style_Memory::add_rule( self::ALEX, 'all', 'No exclamation marks.' );
		self::clock( 100 );
		ZANA_Style_Memory::record_edit( self::ALEX, 'post', 'The depot will be opening late on Friday!', 'Depot opens late Friday.' );
		$before = $GLOBALS['zdz_test_user_meta'][ self::ALEX ];
		foreach ( array(
			'call failed'       => array(
				new WP_Error( 'zdz_llm_budget', 'No monthly Ai budget is set, so nothing was sent.' ),
				new WP_Error( 'zdz_llm_unreachable', 'x', array( 'usd_milli' => 0 ) ),
				new RuntimeException( 'the provider went away' ),
				null,
				'a sentence, not an answer',
			),
			'unreadable answer' => array(
				array( 'text' => 'Here are some thoughts.', 'json' => null, 'usd' => 0 ),
				array( 'text' => '{}', 'json' => array( 'rules' => 'none' ), 'usd' => 0 ),
				array( 'text' => '{}', 'json' => array( 'style' => array() ), 'usd' => 0 ),
			),
		) as $outcome => $answers ) {
			foreach ( $answers as $i => $a ) {
				$this->model( $a );
				$this->assertSame( $outcome, ZANA_Style_Memory::learn( self::ALEX ), $outcome . ' #' . $i );
				$this->assertSame( $before, $GLOBALS['zdz_test_user_meta'][ self::ALEX ], 'nothing changed: ' . $outcome . ' #' . $i );
			}
		}
		$this->assertSame( 'off', ZANA_Style_Memory::learn( self::JORDAN ) );
		$this->assertSame( 'off', ZANA_Style_Memory::learn( self::ROBIN ) );
		ZANA_Style_Memory::$ask = null;
		$this->asked            = array();
		// the scheduled pass never lets a failure out
		$this->model( new RuntimeException( 'again' ) );
		ZANA_Style_Memory::cron_learn( self::ALEX );
		$this->assertCount( 1, $this->asked );
		// a failed call that was charged is still counted on the day
		$this->model( new WP_Error( 'zdz_llm_time', 'x', array( 'usd_milli' => 7 ) ) );
		ZANA_Style_Memory::learn( self::ALEX );
		$this->assertSame( 7, ZANA_Allowance::spent_milli( self::ALEX ) );
	}

	public function test_customer_details_are_coded_before_the_pass_leaves_the_site(): void {
		$r = zdz_test_asst_router( array( ZDZ_LLM_Fake::answer( '{"rules":[{"audience":"customer","rule":"Open with Hi and the first name."}]}' ) ) );
		$GLOBALS['zdz_test_user_roles'][ self::ALEX ] = array( 'administrator' );
		self::clock( 100 );
		ZANA_Style_Memory::record_edit( self::ALEX, 'email', 'Dear Riley Example, write to riley@example.com or call 555-0100.', 'Hi Riley, call us on 555-0100.' );
		$this->assertSame( 'learned 1', ZANA_Style_Memory::learn( self::ALEX ) );
		$sent = json_encode( $r->fake->requests );
		$this->assertCount( 1, $r->fake->requests, 'one call' );
		$this->assertStringNotContainsString( 'Riley Example', $sent );
		$this->assertStringNotContainsString( 'riley@example.com', $sent );
		$this->assertStringNotContainsString( '555-0100', $sent );
		$this->assertStringContainsString( 'BEFORE:', $sent );
		$this->assertSame( array( 'Open with Hi and the first name.' ), array_column( ZANA_Style_Memory::rules( self::ALEX ), 'rule' ) );
		$rows = array_values( $GLOBALS['wpdb']->tables[ ZDZ_LLM_Ledger::table() ] ?? array() );
		$this->assertCount( 1, $rows, 'one ledger row' );
		$this->assertSame( ZANA_LLM_Client::CALLER, $rows[0]['caller'] );
		$this->assertSame( 'extract', $rows[0]['role'] );
	}

	public function test_the_pass_is_refused_for_the_shared_device_by_the_router_itself(): void {
		// even if the class's own check were skipped, the router serves no shared device
		$r   = zdz_test_asst_router( array( ZDZ_LLM_Fake::answer( '{"rules":[]}' ) ) );
		$res = ZDZ_Core_LLM::ask( ZANA_Style_Memory::ROLE, array( 'system' => ZANA_Style_Memory::instructions(), 'messages' => array( array( 'role' => 'user', 'content' => 'x' ) ), 'json_schema' => ZANA_Style_Memory::schema() ), array( 'caller' => ZANA_LLM_Client::CALLER, 'user_id' => self::SHARED, 'deadline' => (int) $r->clock + 50 ) );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( array(), $r->fake->requests );
	}

	/* ── the instructions ───────────────────────────────────────────────────────────────────── */

	public function test_the_block_is_headed_with_the_name_grouped_by_audience_and_only_for_that_person(): void {
		$this->assertSame( '', ZANA_Style_Memory::prompt_block( self::ALEX ), 'no rules, no block' );
		$GLOBALS['zdz_test_user_meta'][ self::ALEX ]['first_name'] = 'Alex';
		ZANA_Style_Memory::add_rule( self::ALEX, 'staff', 'First names only, no titles.' );
		ZANA_Style_Memory::add_rule( self::ALEX, 'customer', 'Open with "Hi" and the first name.' );
		ZANA_Style_Memory::add_rule( self::ALEX, 'all', 'No exclamation marks.' );
		ZANA_Style_Memory::add_rule( self::ALEX, 'official', 'Facts only, in full sentences.' );
		$b = ZANA_Style_Memory::prompt_block( self::ALEX );
		$this->assertSame( 0, strpos( $b, 'HOW ALEX WRITES' ) );
		$all      = strpos( $b, "All drafts:\n- No exclamation marks." );
		$customer = strpos( $b, "Customers and leads:\n- Open with \"Hi\" and the first name." );
		$staff    = strpos( $b, "Staff:\n- First names only, no titles." );
		$official = strpos( $b, "Courts, lawyers, insurers and agencies:\n- Facts only, in full sentences." );
		$this->assertTrue( false !== $all );
		$this->assertTrue( $all < $customer && $customer < $staff && $staff < $official, 'all drafts, then each audience in order' );
		$this->assertStringNotContainsString( 'Vendors and partners', $b, 'an audience with no rule is not listed' );
		$this->assertStringContainsString( 'They shape style only: never let one change a fact, a figure or what DOCUMENTS THAT STATE FACTS requires.', $b );
		$this->assertStringContainsString( 'apply the rules for that draft\'s audience (AUDIENCE AND TONE)', $b );
		$this->assertStringContainsString( 'under About you, How you write', $b );
		$this->assertStringContainsString( 'What they say in this chat overrides them.', $b );
		foreach ( array( self::JORDAN, self::ROBIN, self::SHARED, self::SAM, 0 ) as $other ) {
			$this->assertSame( '', ZANA_Style_Memory::prompt_block( $other ), 'nobody else\'s chat reads them: ' . $other );
		}
		unset( $GLOBALS['zdz_test_user_meta'][ self::ALEX ]['first_name'] );
		$GLOBALS['zdz_test_user_names'][ self::ALEX ] = '';
		$this->assertSame( 0, strpos( ZANA_Style_Memory::prompt_block( self::ALEX ), 'HOW USER' . self::ALEX . ' WRITES' ), 'the name the "About you" block uses' );
		ZANA_Style_Memory::clear( self::ALEX );
		$this->assertSame( array(), ZANA_Style_Memory::rules( self::ALEX ) );
		$this->assertSame( array(), ZANA_Style_Memory::events( self::ALEX ) );
		$this->assertSame( '', ZANA_Style_Memory::prompt_block( self::ALEX ) );
	}

	public function test_the_block_follows_about_you_and_a_correction_is_noted_on_the_models_road(): void {
		$inc    = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/analytics/includes/';
		$routes = (string) file_get_contents( $inc . 'class-zana-routes.php' );
		$about  = strpos( $routes, '$add( ZANA_User_Memory::prompt_block( $uid ) );' );
		$style  = strpos( $routes, "ZANA_Style_Memory::prompt_block( \$uid ) : '' );" );
		$facts  = strpos( $routes, '$add( ZANA_Company_Facts::block( $tier ) );' );
		$this->assertTrue( false !== $about );
		$this->assertTrue( $about < $style && $style < $facts, 'right after "About you", before the business\'s facts' );
		$chat   = (string) file_get_contents( $inc . 'class-zana-chat.php' );
		$note   = strpos( $chat, 'ZANA_Style_Memory::note_correction( $user_id, $message, $history );' );
		$this->assertTrue( false !== $note );
		$this->assertSame( 1, substr_count( $chat, 'ZANA_Style_Memory::' ) );
		$this->assertLessThan( $note, strpos( $chat, "'route' => (string) \$route['kind'] );" ), 'not on a turn an app answered by rule' );
		$this->assertLessThan( $note, strpos( $chat, 'ZANA_LLM_Client::is_shared_device( $user_id )' ), 'never reached by the shared device' );
		$this->assertLessThan( strpos( $chat, '$system = ZANA_Prompt_Builder::build( $user_id, $context );' ), $note, 'before the instructions are built' );
	}

	public function test_a_typed_correction_to_a_draft_is_noted_and_a_question_is_not(): void {
		$email = array(
			array( 'role' => 'user', 'content' => 'Write an email to riley@example.com about Friday.' ),
			array( 'role' => 'assistant', 'content' => "[ZDZ_EMAIL_DRAFT]to: riley@example.com\nsubject: Friday\n---\nDear Riley, we are delighted to confirm.[/ZDZ_EMAIL_DRAFT]" ),
		);
		$dm    = array(
			array( 'role' => 'user', 'content' => 'Message Jordan about the depot.' ),
			array( 'role' => 'assistant', 'content' => '[ZANA_DM_DRAFT:' . str_repeat( 'ab', 16 ) . '|@jordan|Jordan Example]The depot opens late on Friday.[/ZANA_DM_DRAFT]' ),
		);
		$post  = array(
			array( 'role' => 'user', 'content' => 'Post it for the crew.' ),
			array( 'role' => 'assistant', 'content' => '[ZANA_POST_DRAFT:#crew]The depot opens late on Friday.[/ZANA_POST_DRAFT]' ),
		);
		$this->assertSame( 'recorded', ZANA_Style_Memory::note_correction( self::ALEX, 'Make it shorter and less formal.', $email ) );
		$this->assertSame( 'email', ZANA_Style_Memory::events( self::ALEX )[0]['kind'] );
		$this->assertSame( 'recorded', ZANA_Style_Memory::note_correction( self::ALEX, 'Drop the greeting and say by when.', $dm ) );
		$this->assertSame( 'dm', ZANA_Style_Memory::events( self::ALEX )[1]['kind'] );
		$this->assertSame( 'recorded', ZANA_Style_Memory::note_correction( self::ALEX, 'Too long, tighten the wording.', $post ) );
		$this->assertSame( 'post', ZANA_Style_Memory::events( self::ALEX )[2]['kind'] );
		$n = 3;
		foreach ( array(
			array( 'How many jobs did we finish last month?', $dm ),             // a question about figures
			array( 'Add what we made in revenue this year.', $dm ),             // needs data: not a style correction
			array( 'Now schedule a visit for Friday.', $dm ),                     // another app's action
			array( 'Create an estimate for the gate.', $post ),
			array( 'What time does the depot open?', array( array( 'role' => 'assistant', 'content' => 'It opens at eight.' ) ) ),   // no draft before it
			array( 'Make it shorter.', array() ),                                 // nothing before it
			array( 'ok', $dm ),                                                   // a go-ahead
			array( 'Make it shorter.', array_merge( $dm, array( array( 'role' => 'user', 'content' => 'thanks' ), array( 'role' => 'assistant', 'content' => 'You are welcome.' ) ) ) ),   // the draft is not the newest answer
		) as $i => $case ) {
			$out = ZANA_Style_Memory::note_correction( self::ALEX, $case[0], $case[1] );
			$this->assertContains( $out, array( 'skipped', 'off' ), $i . ': ' . $case[0] );
			$this->assertCount( $n, ZANA_Style_Memory::events( self::ALEX ), $i . ': ' . $case[0] );
		}
		$this->assertSame( 'off', ZANA_Style_Memory::note_correction( self::JORDAN, 'Make it shorter and less formal.', $email ) );
		$this->assertSame( 'off', ZANA_Style_Memory::note_correction( self::ROBIN, 'Make it shorter and less formal.', $email ) );
		$this->assertSame( 'dm', ZANA_Style_Memory::edits_message_draft( 'make it friendlier', $dm ) );
		$this->assertSame( '', ZANA_Style_Memory::edits_message_draft( 'what is the link to the handbook', $dm ) );
	}

	/* ── the routes ───────────────────────────────────────────────────────────────────── */

	public function test_the_routes_are_for_an_administrator_with_the_chat_and_nobody_else(): void {
		foreach ( array( self::ALEX => true, self::SAM => true, self::JORDAN => false, self::ROBIN => false, self::SHARED => false, 0 => false ) as $uid => $may ) {
			$GLOBALS['zdz_test_current_user'] = $uid;
			$this->assertSame( $may, ZANA_Style_Memory::can_use(), 'user ' . $uid );
		}
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/analytics/includes/class-zana-style-memory.php' );
		$reg = substr( $src, (int) strpos( $src, 'public static function register_routes' ), 1800 );
		$this->assertSame( 6, substr_count( $reg, "'permission_callback' => \$can" ), 'every route asks' );
		$this->assertSame( 0, substr_count( $reg, '__return_true' ) );
		$this->assertSame( 0, substr_count( $src, 'wp_ajax_nopriv' ), 'no signed-out road' );
	}

	public function test_the_list_add_delete_and_clear_routes(): void {
		$add = ZANA_Style_Memory::rest_add( new WP_REST_Request( array( 'audience' => 'vendor', 'rule' => 'Order numbers and dates first.' ) ) );
		$new = $add->get_data()['rule'];
		$this->assertSame( array( 'audience' => 'vendor', 'label' => 'Vendors and partners', 'rule' => 'Order numbers and dates first.', 'source' => 'added' ), array_diff_key( $new, array( 'id' => 1 ) ) );
		$bad = ZANA_Style_Memory::rest_add( new WP_REST_Request( array( 'audience' => 'vendor', 'rule' => 'Call 555-0100.' ) ) );
		$this->assertInstanceOf( WP_Error::class, $bad );
		$this->assertSame( array( 'status' => 400 ), $bad->get_error_data() );
		ZANA_Style_Memory::record_edit( self::ALEX, 'email', 'One way of saying it.', 'Another way of saying it.' );
		$list = ZANA_Style_Memory::rest_list()->get_data();
		$this->assertSame( array( $new ), $list['rules'] );
		$this->assertSame( 1, $list['edits'] );
		$this->assertSame( ZANA_Style_Memory::AUDIENCES, array_column( $list['audiences'], 'id' ) );
		$this->assertSame( 'All drafts', $list['audiences'][0]['label'] );
		$this->assertFalse( ZANA_Style_Memory::rest_delete( new WP_REST_Request( array( 'id' => 'ffffffffff' ) ) )->get_data()['deleted'] );
		$this->assertTrue( ZANA_Style_Memory::rest_delete( new WP_REST_Request( array( 'id' => $new['id'] ) ) )->get_data()['deleted'] );
		$this->assertSame( array( 'cleared' => true ), ZANA_Style_Memory::rest_clear()->get_data() );
		$this->assertSame( array( 'rules' => array(), 'edits' => 0 ), array_diff_key( ZANA_Style_Memory::rest_list()->get_data(), array( 'audiences' => 1 ) ) );
	}

	public function test_an_edit_learns_at_once_and_is_tried_again_later_when_the_call_fails(): void {
		$this->model( self::rules( array( array( 'customer', 'Open with "Hi" and the first name.' ) ) ) );
		$out = ZANA_Style_Memory::rest_edit( new WP_REST_Request( array( 'kind' => 'email', 'before' => 'Dear Riley, we are delighted.', 'after' => 'Hi Riley, all set.' ) ) )->get_data();
		$this->assertSame( array( 'recorded' => 'recorded', 'learn' => 'learned 1', 'rules' => 1 ), $out );
		$this->assertSame( array(), $this->scheduled );
		$out = ZANA_Style_Memory::rest_edit( new WP_REST_Request( array( 'kind' => 'email', 'before' => 'Hi Riley, all set.', 'after' => ' hi riley,  all set. ' ) ) )->get_data();
		$this->assertSame( array( 'recorded' => 'same', 'learn' => '', 'rules' => 1 ), $out, 'nothing changed: nothing asked' );
		$this->assertCount( 1, $this->asked );
		self::clock( time() + 5 );
		$this->model( new WP_Error( 'zdz_llm_unreachable', 'x' ) );
		$out = ZANA_Style_Memory::rest_edit( new WP_REST_Request( array( 'kind' => 'dm', 'before' => 'The depot opens late.', 'after' => 'Depot opens late Friday.' ) ) )->get_data();
		$this->assertSame( array( 'recorded' => 'recorded', 'learn' => 'call failed', 'rules' => 1 ), $out );
		$this->assertSame( array( self::ALEX ), $this->scheduled, 'the pass is tried again' );
	}

	/* ── a held message's words ───────────────────────────────────────────────────────────────────── */

	/** A message the chat holds for Alex in chat 7, to Jordan, as hold() leaves it; its card is in the saved answer. */
	private function held(): string {
		$GLOBALS['zdz_test_filters']['zim_dm_send'] = function ( $prior, $from, $to, $body ) {
			$this->asked[] = array( (int) $from, (int) $to, (string) $body );
			return array( 'conversation_id' => 9, 'message_id' => 1 );
		};
		ZANA_DM_Card::$recipient = function () { return array( 'uid' => self::JORDAN, 'name' => 'Jordan Example', 'login' => 'jordan' ); };
		ZANA_DM_Card::$claim     = function () { return true; };
		$card = ZANA_DM_Card::hold( '[ZANA_DM_DRAFT:@jordan]The depot opens late on Friday.[/ZANA_DM_DRAFT]', self::ALEX, 7 );
		$this->assertSame( 1, preg_match( '/^\[ZANA_DM_DRAFT:([a-f0-9]{32})\|@jordan\|Jordan Example\]/', $card, $m ) );
		$GLOBALS['wpdb']->said_in_chat( 7, self::ALEX, 'assistant', "Here it is.\n\n" . $card );
		return $m[1];
	}

	public function test_a_held_message_is_changed_only_by_its_person_in_its_chat_and_keeps_its_recipient_and_time(): void {
		$id  = $this->held();
		$key = array_keys( $GLOBALS['zdz_test_transients'] )[0];
		$at  = $GLOBALS['zdz_test_transients'][ $key ]['at'];
		foreach ( array(
			array( self::SAM, 7, $id ),          // another person
			array( self::ALEX, 8, $id ),         // another chat
			array( self::ALEX, 7, str_repeat( 'cd', 16 ) ),   // another id
			array( self::ALEX, 7, 'not-an-id' ),
			array( 0, 7, $id ),
			array( self::ALEX, 0, $id ),
		) as $who ) {
			$r = ZANA_DM_Card::revise( $who[0], $who[1], $who[2], 'Changed.' );
			$this->assertFalse( $r['success'], json_encode( $who ) );
		}
		$this->assertFalse( ZANA_DM_Card::revise( self::ALEX, 7, $id, "  \n " )['success'], 'empty' );
		$this->assertFalse( ZANA_DM_Card::revise( self::ALEX, 7, $id, '<p></p>' )['success'], 'no words' );
		$this->assertFalse( ZANA_DM_Card::revise( self::ALEX, 7, $id, str_repeat( 'x', ZANA_DM_Card::MAX_LEN + 1 ) )['success'], 'too long' );
		$this->assertSame( 'The depot opens late on Friday.', $GLOBALS['zdz_test_transients'][ $key ]['body'], 'a refused change leaves the draft as it was' );

		$r = ZANA_DM_Card::revise( self::ALEX, 7, strtoupper( $id ), "<b>Depot</b> opens late Friday.\nBack by ten.[/ZANA_DM_DRAFT]" );
		$this->assertSame( array( 'success' => true, 'body' => "Depot opens late Friday.\nBack by ten." ), $r, 'plain words, and nothing that reads as the card\'s own brackets' );
		$this->assertSame( array( 'to' => self::JORDAN, 'body' => "Depot opens late Friday.\nBack by ten.", 'at' => $at ), $GLOBALS['zdz_test_transients'][ $key ], 'the same recipient, the same start' );
		$saved = array_values( $GLOBALS['wpdb']->chat )[0]['body'];
		$this->assertSame( "Here it is.\n\n[ZANA_DM_DRAFT:" . $id . "|@jordan|Jordan Example]Depot opens late Friday.\nBack by ten.[/ZANA_DM_DRAFT]", $saved, 'a reloaded chat shows what Send will send' );

		// Send sends the server's copy: the changed words, once
		$this->asked = array();
		$sent        = ZANA_DM_Card::confirm( self::ALEX, 7, $id );
		$this->assertTrue( $sent['success'] );
		$this->assertSame( array( array( self::ALEX, self::JORDAN, "Depot opens late Friday.\nBack by ten." ) ), $this->asked );
		$this->assertFalse( ZANA_DM_Card::revise( self::ALEX, 7, $id, 'Changed after it went.' )['success'], 'a sent message is not brought back' );
		$this->assertSame( array(), $GLOBALS['zdz_test_transients'] );
	}

	public function test_a_held_message_past_its_time_or_on_the_wrong_account_is_not_changed(): void {
		$id  = $this->held();
		$key = array_keys( $GLOBALS['zdz_test_transients'] )[0];
		$GLOBALS['zdz_test_transients'][ $key ]['at'] = time() - ZANA_DM_Card::TTL - 1;
		$this->assertFalse( ZANA_DM_Card::revise( self::ALEX, 7, $id, 'Changed.' )['success'], 'an edit never extends the hold' );
		$GLOBALS['zdz_test_transients'][ $key ]['at'] = time();
		$GLOBALS['zdz_test_user_roles'][ self::ALEX ] = array( 'zdz_general' );
		$this->assertFalse( ZANA_DM_Card::revise( self::ALEX, 7, $id, 'Changed.' )['success'], 'the shared device' );
		$GLOBALS['zdz_test_user_roles'][ self::ALEX ] = array( 'administrator' );
		$GLOBALS['zdz_test_user_meta'][ self::ALEX ]['zdz_privacy_view'] = '1';
		ZDZ_Privacy_View::flush();
		$this->assertFalse( ZANA_DM_Card::revise( self::ALEX, 7, $id, 'Changed.' )['success'], 'an account in Privacy View changes nothing' );
		$this->assertSame( 'The depot opens late on Friday.', $GLOBALS['zdz_test_transients'][ $key ]['body'] );
	}

	public function test_the_draft_route_changes_the_held_message_and_says_why_when_it_cannot(): void {
		$id = $this->held();
		$ok = ZANA_Style_Memory::rest_draft( new WP_REST_Request( array( 'card' => $id, 'session_id' => '7', 'body' => 'Depot opens late Friday.' ) ) );
		$this->assertSame( array( 'body' => 'Depot opens late Friday.' ), $ok->get_data() );
		$no = ZANA_Style_Memory::rest_draft( new WP_REST_Request( array( 'card' => $id, 'session_id' => '8', 'body' => 'Changed.' ) ) );
		$this->assertInstanceOf( WP_Error::class, $no );
		$this->assertSame( array( 'status' => 409 ), $no->get_error_data() );
		$this->assertNotSame( '', $no->get_error_message() );
	}

	/* ── the page ───────────────────────────────────────────────────────────────────── */

	public function test_the_page_offers_edit_only_when_the_server_says_so(): void {
		$this->assertTrue( ZANA_Drafts_Page::features( self::ALEX )['style'] );
		foreach ( array( self::JORDAN, self::ROBIN, self::SHARED, 0 ) as $uid ) {
			$this->assertFalse( ZANA_Drafts_Page::features( $uid )['style'], 'user ' . $uid );
		}
		$cfg = ZANA_Drafts_Page::config( self::ALEX );
		$this->assertSame( 'zana_ask', $cfg['askParam'] );
		$js  = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/analytics/assets/js/chat-drafts.js' );
		preg_match_all( "/\\bt\\('([a-zA-Z]+)'/", $js, $m );
		foreach ( array_unique( $m[1] ) as $key ) {
			$this->assertArrayHasKey( $key, $cfg['i18n'], 'the page\'s word "' . $key . '" comes from the server' );
			$this->assertDoesNotMatchRegularExpression( '/\bAI\b/', $cfg['i18n'][ $key ] );
		}
		$this->assertStringNotContainsString( 'innerHTML', $js, 'nothing drawn is ever read as HTML' );
		$this->assertStringContainsString( "if (CFG.style) {\n      C.on('card', onCard);\n      C.on('memory', onMemory);", $js, 'Edit and the list only when the server says so' );
		$this->assertStringContainsString( "rest('/style/draft', 'POST', { card: card.id,", $js, 'a held message changes on the server first' );
		$this->assertStringContainsString( "rest('/style/edit', 'POST', { kind: kind, before: before, after: words })", $js, 'the draft as written and as changed' );
		$this->assertStringContainsString( 'K.mailtoFor(card.to, card.subject, words)', $js, 'Open in Mail carries the changed words' );
		$chat = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/analytics/assets/js/chat.js' );
		$this->assertStringContainsString( "if (n) { hook('card', n, c, ctx.meta); }", $chat );
		$this->assertStringContainsString( "hook('memory', body);", $chat );
		$this->assertStringContainsString( 'try { fn(a, b, c); } catch (e) {', $chat, 'an add-on that throws leaves the card as it was' );
		$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/analytics/assets/css/chat-drafts.css' );
		$this->assertStringContainsString( '.zana-style-editing { outline: 2px solid var(--sys-brand', $css, 'an open edit is outlined in the theme\'s colour' );
		$this->assertSame( 0, preg_match( '/#[0-9a-fA-F]{3,8}\b/', $css ), 'no colour of its own' );
	}

	/** The helpers' cases, run once in Node; null when Node is not installed here. */
	private static function js(): ?array {
		if ( null !== self::$js ) {
			return self::$js ?: null;
		}
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) ); // sentinel:allow fixed command, no input
		if ( '' === $node ) {
			self::$js = array();
			return null;
		}
		$file = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/analytics/assets/js/chat-drafts.js';
		$code = file_get_contents( __DIR__ . '/support/chat-drafts-cases.js' );
		$out  = shell_exec( escapeshellarg( $node ) . ' -e ' . escapeshellarg( $code ) . ' ' . escapeshellarg( $file ) ); // sentinel:allow every argument is escapeshellarg()'d
		self::$js = json_decode( (string) $out, true ) ?: array( 'error' => (string) $out );
		return self::$js;
	}

	public function test_the_pages_helpers(): void {
		$r = self::js();
		if ( null === $r ) {
			$this->markTestSkipped( 'node is not installed here' );
		}
		$this->assertArrayNotHasKey( 'error', $r, (string) ( $r['error'] ?? '' ) );
		$id = '0123456789abcdef01234567';
		$this->assertSame( array( 'askId', 'editable', 'groupRules', 'heldId', 'kindOf', 'sameWords', 'withoutAsk' ), $r['exports'], 'loads without a page' );
		$this->assertSame( array( $id, $id, $id, '', '', '', '', '', '', '' ), $r['askId'], 'only an id as the server writes one' );
		$this->assertSame( array( $id, '', $id ), $r['askIdOther'] );
		$this->assertSame( array( '', '?a=1&b=2', '?a=1', '?a=1', '', '' ), $r['withoutAsk'], 'out of the address, whatever its value' );
		$this->assertSame( array( 'email', 'dm', 'post', '', '', '', '', '' ), $r['kindOf'] );
		$this->assertSame( array( true, true, true, false, false, false, false, false, false, false, false ), $r['editable'] );
		$this->assertSame( array( true, false, false, false, false, false ), $r['heldId'] );
		$this->assertSame( array( true, false, true, true ), $r['sameWords'] );
		$this->assertSame( array( array( 'id' => 'all', 'label' => 'All drafts', 'ids' => array( 'r2' ) ), array( 'id' => 'staff', 'label' => 'Staff', 'ids' => array( 'r1', 'r3' ) ) ), $r['groups'] );
		$this->assertSame( array( array(), array(), array() ), $r['noGroups'] );
	}
}
