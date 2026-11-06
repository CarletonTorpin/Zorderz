<?php
/**
 * Hours: pay safety. Hours feed pay, so whose hours they are is decided by exact rules and a person, never a guess.
 *
 *   - Attribution: a reading's stretch belongs to a person only by an exact name (or initials written as initials);
 *     a photo with no name, a name that is nobody's, and a name that is two people's are review items, attributed to
 *     nobody (never the uploader, the owner or the closest name) until a person chooses. Someone else's exact name is
 *     theirs only.
 *   - The model's reading only proposes: reading words or a photo writes nothing, and the routes that read never write.
 *   - The model is reached only through the platform's router, as a registered caller, never by a model name.
 *   - Who may see whose hours: deny by default; the shared device and demo mode get nothing.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/llm-test-doubles.php'; // the platform's router, as the router tests load it

if ( ! function_exists( '_n' ) ) {
	function _n( $one, $many, $n, $d = null ) { return 1 === (int) $n ? $one : $many; }
}

require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/hours/includes/class-zhr-rules.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/hours/includes/class-zhr-time.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/hours/includes/class-zhr-attribution.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/hours/includes/class-zhr-access.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/hours/includes/class-zhr-parse.php';

/** A router that answers from a script and remembers what it was asked. */
class ZHR_Test_Hr_Router extends ZDZ_Core_LLM {
	public $calls  = array();
	public $answer = null;
	public function handle( $role, array $request, array $ctx ) {
		$this->calls[] = array( 'role' => $role, 'request' => $request, 'ctx' => $ctx );
		return $this->answer;
	}
}

/** Records any database call: the reading must make none. */
class ZHR_Test_Hr_Wpdb_Recorder {
	public $prefix = 'wp_';
	public $calls  = array();
	public function __call( $name, $args ) {
		$this->calls[] = $name;
		return null;
	}
}

final class HoursPaySafetyTest extends TestCase {

	private $saved_wpdb = null;

	protected function setUp(): void {
		$this->saved_wpdb = $GLOBALS['wpdb'] ?? null;
	}

	protected function tearDown(): void {
		ZDZ_Core_LLM::set_instance( null );
		if ( null === $this->saved_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->saved_wpdb;
		}
		unset( $GLOBALS['zdz_test_current_user'] );
	}

	private static function people() {
		return array(
			array( 'id' => 11, 'name' => 'Alex Morgan', 'first' => 'Alex', 'last' => 'Morgan', 'initials' => 'AM' ),
			array( 'id' => 12, 'name' => 'Jordan Quinn', 'first' => 'Jordan', 'last' => 'Quinn', 'initials' => 'JQ' ),
			array( 'id' => 13, 'name' => 'Sam Riley', 'first' => 'Sam', 'last' => 'Riley', 'initials' => 'SR' ),
			array( 'id' => 14, 'name' => 'Sam Riley', 'first' => 'Sam', 'last' => 'Riley', 'initials' => 'SR' ),
			array( 'id' => 15, 'name' => 'Casey Blake', 'first' => 'Casey', 'last' => 'Blake', 'initials' => 'CB' ),
		);
	}

	private static function ids() {
		return array( 11, 12, 13, 14, 15 );
	}

	/* ── attribution ─────────────────────────────────────────────────────── */

	public function test_words_without_a_name_belong_to_the_person_the_reading_is_for(): void {
		$a = ZHR_Attribution::attribute( array( 'name' => '' ), 'words', 11, self::people() );
		$this->assertSame( 11, $a['person'] );
		$this->assertSame( '', $a['review'] );
		$ok = ZHR_Attribution::may_add( $a, 11, 0, 11, false, self::ids() );
		$this->assertTrue( $ok['ok'] );
		$this->assertSame( 'exact', $ok['how'] );
	}

	public function test_a_photo_without_a_name_is_a_review_item_never_the_uploader(): void {
		$a = ZHR_Attribution::attribute( array( 'name' => '' ), 'photo', 11, self::people() );
		$this->assertSame( 0, $a['person'], 'nobody: not the person who sent the photo' );
		$this->assertSame( ZHR_Attribution::REVIEW_NO_NAME, $a['review'] );
		$no = ZHR_Attribution::may_add( $a, 11, 0, 11, false, self::ids() );
		$this->assertFalse( $no['ok'], 'a review item is never added without a person choosing' );
		$this->assertSame( 'needs_person', $no['why'] );
	}

	public function test_only_an_exact_name_is_a_match(): void {
		$people = self::people();
		$this->assertSame( 12, ZHR_Attribution::match( 'Jordan Quinn', $people )['id'] );
		$this->assertSame( 12, ZHR_Attribution::match( '  jordan   QUINN. ', $people )['id'], 'case, spacing and a stray dot are not part of a name' );
		foreach ( array( 'Jordan', 'Quinn', 'J. Quinn', 'Jordon Quinn', 'Jordan Q', 'Jordan Quinnn' ) as $close ) {
			$m = ZHR_Attribution::match( $close, $people );
			$this->assertSame( 'none', $m['status'], "'$close' is close, not exact" );
			$this->assertSame( 0, $m['id'] );
		}
		$a = ZHR_Attribution::attribute( array( 'name' => 'Jordan' ), 'photo', 12, $people );
		$this->assertSame( 0, $a['person'], 'a first name alone is never "the closest name", even for the person it might be' );
		$this->assertSame( ZHR_Attribution::REVIEW_NO_MATCH, $a['review'] );
	}

	public function test_initials_count_only_when_written_as_initials(): void {
		$people = self::people();
		$this->assertSame( 11, ZHR_Attribution::match( 'AM', $people )['id'] );
		$this->assertSame( 11, ZHR_Attribution::match( 'A.M.', $people )['id'] );
		$this->assertSame( 'none', ZHR_Attribution::match( 'am', $people )['status'], 'a lower-case word is a word, not initials' );
		$this->assertSame( 'none', ZHR_Attribution::match( 'Am', $people )['status'] );
		$this->assertSame( 'ambiguous', ZHR_Attribution::match( 'SR', $people )['status'], 'two people share these initials' );
	}

	public function test_a_name_that_is_two_peoples_is_never_guessed(): void {
		$a = ZHR_Attribution::attribute( array( 'name' => 'Sam Riley' ), 'photo', 13, self::people() );
		$this->assertSame( 0, $a['person'] );
		$this->assertSame( ZHR_Attribution::REVIEW_AMBIGUOUS, $a['review'] );
		$this->assertSame( array( 13, 14 ), $a['candidates'] );
		// the admin tier chooses among the two; one of them may say "mine"; nobody else may
		$this->assertSame( array( 13, 14 ), ZHR_Attribution::choices( $a, 11, true, self::ids() ) );
		$this->assertSame( array( 13 ), ZHR_Attribution::choices( $a, 13, false, self::ids() ) );
		$this->assertSame( array(), ZHR_Attribution::choices( $a, 15, false, self::ids() ) );
		$this->assertFalse( ZHR_Attribution::may_add( $a, 15, 15, 15, false, self::ids() )['ok'], 'not one of the two' );
		$this->assertFalse( ZHR_Attribution::may_add( $a, 11, 11, 99, true, self::ids() )['ok'], 'even the admin tier only between the two' );
		$this->assertTrue( ZHR_Attribution::may_add( $a, 14, 14, 99, true, self::ids() )['ok'] );
	}

	public function test_someone_elses_exact_name_is_theirs_and_only_theirs(): void {
		$a = ZHR_Attribution::attribute( array( 'name' => 'Casey Blake' ), 'photo', 11, self::people() );
		$this->assertSame( 15, $a['person'] );
		$this->assertSame( ZHR_Attribution::REVIEW_OTHER, $a['review'] );
		$this->assertSame( array(), ZHR_Attribution::choices( $a, 11, false, self::ids() ), 'nothing to choose: it is exactly someone' );
		$this->assertFalse( ZHR_Attribution::may_add( $a, 11, 11, 11, false, self::ids() )['ok'], 'the uploader can never claim it' );
		$this->assertFalse( ZHR_Attribution::may_add( $a, 11, 11, 99, true, self::ids() )['ok'], 'nor the admin tier, for the wrong person' );
		$this->assertSame( 'exact', ZHR_Attribution::may_add( $a, 15, 0, 99, true, self::ids() )['how'], 'added for the person it names' );
		// words that name someone else are theirs too, never the speaker's
		$w = ZHR_Attribution::attribute( array( 'name' => 'Casey Blake' ), 'words', 11, self::people() );
		$this->assertSame( 15, $w['person'] );
		$this->assertFalse( ZHR_Attribution::may_add( $w, 11, 0, 11, false, self::ids() )['ok'] );
	}

	public function test_a_review_item_needs_a_person_to_choose(): void {
		$a = ZHR_Attribution::attribute( array( 'name' => 'Robin' ), 'photo', 11, self::people() );
		$this->assertSame( ZHR_Attribution::REVIEW_NO_MATCH, $a['review'] );
		$this->assertFalse( ZHR_Attribution::may_add( $a, 11, 0, 11, false, self::ids() )['ok'], 'no choice, no hours' );
		$self = ZHR_Attribution::may_add( $a, 11, 11, 11, false, self::ids() );
		$this->assertTrue( $self['ok'], 'a person may say these are their own' );
		$this->assertSame( 'chosen', $self['how'] );
		$this->assertFalse( ZHR_Attribution::may_add( $a, 12, 12, 11, false, self::ids() )['ok'], 'but never choose someone else' );
		$this->assertTrue( ZHR_Attribution::may_add( $a, 12, 12, 99, true, self::ids() )['ok'], 'the admin tier chooses anyone who holds Hours' );
		$this->assertFalse( ZHR_Attribution::may_add( $a, 77, 77, 99, true, self::ids() )['ok'], 'never someone who does not hold Hours' );
		$this->assertFalse( ZHR_Attribution::may_add( $a, 12, 11, 99, true, self::ids() )['ok'], 'the choice must be the person the hours are added for' );
	}

	/* ── the model only proposes ─────────────────────────────────────────── */

	private function fake_router( array $json ) {
		$r         = new ZHR_Test_Hr_Router();
		$r->answer = array( 'text' => '', 'json' => $json, 'model' => 'm' );
		ZDZ_Core_LLM::set_instance( $r );
		return $r;
	}

	public function test_reading_words_proposes_and_writes_nothing(): void {
		$GLOBALS['wpdb'] = new ZHR_Test_Hr_Wpdb_Recorder();
		$r               = $this->fake_router( array(
			'entries' => array(
				array( 'date' => '2026-03-02', 'start' => '07:30', 'end' => '16:00', 'minutes' => 0, 'note' => 'inventory', 'name' => '' ),
				array( 'date' => '2026-03-03', 'start' => '', 'end' => '', 'minutes' => 480, 'note' => '', 'name' => 'Jordan Quinn' ),
			),
			'unclear' => array( 'The second line is faint.' ),
		) );
		$settings = ZHR_Time::default_settings();
		$out      = ZHR_Parse::read( 'I worked 7:30 to 4 on Monday', null, $settings, '2026-03-04' );
		$this->assertIsArray( $out );
		$this->assertSame( array(), $GLOBALS['wpdb']->calls, 'a reading never touches a table' );
		$this->assertSame( 'words', $out['kind'] );
		$this->assertCount( 2, $out['entries'] );
		$this->assertSame( '07:30', $out['entries'][0]['start'] );
		$this->assertSame( 'Jordan Quinn', $out['entries'][1]['name'], 'the name is transcribed, not decided' );
		$this->assertTrue( $out['entries'][1]['needs_start'], 'with no standard start, a length alone never gets a made-up start' );
		$this->assertSame( '', $out['entries'][1]['start'] );
		// one call, through the router, as this app, with no model named anywhere in it
		$this->assertCount( 1, $r->calls );
		$call = $r->calls[0];
		$this->assertSame( 'extract', $call['role'] );
		$this->assertSame( 'hours', $call['ctx']['caller'] );
		$this->assertArrayNotHasKey( 'model', $call['request'] );
		$this->assertArrayNotHasKey( 'model', $call['ctx'] );
		$this->assertTrue( $call['request']['system_company'] );
		$this->assertContains( 'name', $call['request']['json_schema']['properties']['entries']['items']['required'] );
	}

	public function test_a_photo_is_read_as_a_document(): void {
		$r   = $this->fake_router( array( 'entries' => array(), 'unclear' => array() ) );
		$out = ZHR_Parse::read( '', array( 'mime' => 'image/jpeg', 'data' => base64_encode( 'not-really-a-jpeg' ) ), ZHR_Time::default_settings(), '2026-03-04' );
		$this->assertSame( 'photo', $out['kind'] );
		$this->assertSame( 'read_document', $r->calls[0]['role'] );
		$parts = $r->calls[0]['request']['messages'][0]['content'];
		$this->assertSame( 'image', $parts[1]['type'] );
	}

	public function test_the_instructions_never_say_who_is_asking(): void {
		$p = ZHR_Parse::prompt( '2026-03-04', ZHR_Time::default_settings() );
		$this->assertStringNotContainsString( 'The worker is', $p );
		$this->assertStringContainsString( 'Never guess a name', $p );
		$this->assertStringNotContainsString( 'standard hours', $p, 'no standard hours set: none are described' );
	}

	public function test_a_router_refusal_is_said_in_plain_words(): void {
		ZDZ_Core_LLM::set_instance( new ZHR_Test_Hr_Router() );
		ZDZ_Core_LLM::instance()->answer = new WP_Error( 'zdz_llm_kiosk', 'x' );
		$e = ZHR_Parse::read( 'eight to four', null, ZHR_Time::default_settings(), '2026-03-04' );
		$this->assertInstanceOf( WP_Error::class, $e );
		$this->assertSame( 'reader_kiosk', $e->get_error_code() );
		ZDZ_Core_LLM::instance()->answer = array( 'text' => '', 'json' => null );
		$this->assertSame( 'reader_parse', ZHR_Parse::read( 'eight to four', null, ZHR_Time::default_settings(), '2026-03-04' )->get_error_code(), 'never an empty success' );
	}

	public function test_the_reading_routes_never_write(): void {
		$root  = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/hours/includes/';
		$parse = (string) file_get_contents( $root . 'class-zhr-parse.php' );
		foreach ( array( 'ZHR_DB', '$wpdb', 'update_option', 'update_user_meta' ) as $w ) {
			$this->assertStringNotContainsString( $w, $parse, "the reader must not use $w" );
		}
		$rest = (string) file_get_contents( $root . 'class-zhr-rest.php' );
		$this->assertSame( 1, preg_match( '/public static function parse\(.*?\n\t}\n/s', $rest, $m ) );
		foreach ( array( 'ZHR_DB::insert', 'ZHR_DB::update', 'soft_delete', 'set_meal_answer', 'finish_locked', '$wpdb' ) as $w ) {
			$this->assertStringNotContainsString( $w, $m[0], "the parse route must not call $w" );
		}
	}

	public function test_hours_reaches_the_model_only_as_a_registered_document_reader(): void {
		$this->assertArrayHasKey( 'hours', ZDZ_Core_LLM::CALLERS );
		$this->assertTrue( ZDZ_Core_LLM::CALLERS['hours']['documents'] );
		$this->assertFalse( ZDZ_Core_LLM::CALLERS['hours']['kiosk_safe'], 'never on the shared device' );
		$this->assertSame( 'hours', ZHR_Parse::CALLER );
		$all = '';
		foreach ( glob( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/hours/{,includes/,assets/}*.{php,js}', GLOB_BRACE ) as $f ) {
			$all .= file_get_contents( $f );
		}
		$this->assertSame( 0, preg_match( '/\b(gpt-\d|gemini-|claude-|opus|sonnet|haiku|api\.poe\.com|api\.anthropic\.com)/i', $all ), 'no model name or provider address in the app' );
	}

	/* ── who may see whose hours ──────────────────────────────────────────── */

	private static function f( array $over ) {
		return $over + array(
			'viewer' => 11, 'target' => 11, 'action' => 'view', 'viewer_shared' => false, 'viewer_holds' => true,
			'viewer_admin' => false, 'target_ok' => true, 'leads_target' => false, 'crew_leads' => false,
		);
	}

	public function test_own_hours_only_by_default(): void {
		$self = ZHR_Access::decide( self::f( array() ) );
		$this->assertTrue( $self['allow'] );
		$this->assertFalse( $self['full'], 'a person outside the admin tier gets their own plain view' );
		$other = ZHR_Access::decide( self::f( array( 'target' => 12 ) ) );
		$this->assertFalse( $other['allow'] );
		$this->assertSame( 'not_yours', $other['why'] );
	}

	public function test_shared_device_demo_and_no_app_get_nothing(): void {
		$this->assertFalse( ZHR_Access::decide( self::f( array( 'viewer_shared' => true ) ) )['allow'], 'not even "their own"' );
		$this->assertFalse( ZHR_Access::decide( self::f( array( 'viewer_shared' => true, 'viewer_admin' => true, 'target' => 12 ) ) )['allow'] );
		$this->assertFalse( ZHR_Access::decide( self::f( array( 'viewer_holds' => false ) ) )['allow'] );
		$this->assertSame( 'signed_out', ZHR_Access::decide( self::f( array( 'viewer' => 0 ) ) )['why'] );
		$this->assertSame( 'no_person', ZHR_Access::decide( self::f( array( 'target' => 0 ) ) )['why'] );
	}

	public function test_admin_tier_sees_and_changes_anyone_but_the_shared_device(): void {
		foreach ( array( 'view', 'change', 'export' ) as $act ) {
			$d = ZHR_Access::decide( self::f( array( 'viewer_admin' => true, 'target' => 12, 'action' => $act ) ) );
			$this->assertTrue( $d['allow'], $act );
			$this->assertTrue( $d['full'] );
		}
		$this->assertFalse( ZHR_Access::decide( self::f( array( 'viewer_admin' => true, 'target' => 12, 'target_ok' => false ) ) )['allow'], 'never the shared device' );
	}

	public function test_crew_lead_reads_their_crew_only_when_the_business_allows_and_never_changes(): void {
		$off = ZHR_Access::decide( self::f( array( 'target' => 12, 'leads_target' => true ) ) );
		$this->assertFalse( $off['allow'], 'off by default' );
		$on = ZHR_Access::decide( self::f( array( 'target' => 12, 'leads_target' => true, 'crew_leads' => true ) ) );
		$this->assertTrue( $on['allow'] );
		$this->assertFalse( $on['full'], 'as the person sees it, never the office\'s view' );
		foreach ( array( 'change', 'export' ) as $act ) {
			$this->assertFalse( ZHR_Access::decide( self::f( array( 'target' => 12, 'leads_target' => true, 'crew_leads' => true, 'action' => $act ) ) )['allow'], $act );
		}
		$this->assertFalse( ZHR_Access::decide( self::f( array( 'target' => 13, 'leads_target' => false, 'crew_leads' => true ) ) )['allow'], 'not someone else\'s crew' );
		$this->assertFalse( ZHR_Access::decide( self::f( array( 'target' => 12, 'leads_target' => true, 'crew_leads' => true, 'action' => 'anything' ) ) )['allow'], 'an unknown action is a change' );
	}
}
