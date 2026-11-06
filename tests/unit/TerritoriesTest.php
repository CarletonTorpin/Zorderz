<?php
/**
 * Territories: who gets a new stub and who does a won job.
 *
 *   - An empty configuration assigns nobody: the decision is marked for a person to review.
 *   - Shadow vs live: a rule in shadow never changes the answer; one turned live does.
 *   - Two-person rules: a named person takes their items, the rules below pick the rest ("AB & CD"),
 *     and an asker that writes one person keeps its own pick.
 *   - No-go zones: a person is never picked at a ZIP inside their no-go zone, and the trail line that
 *     says so is the admin tier's alone.
 *   - Rule history: an earlier version is put back as a new version, never turning a rule live.
 *   - The estimate and jobs hooks do nothing when Territories is absent.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $a = 0, $b = 0 ) { return random_int( $a, $b ); }
}
if ( ! function_exists( 'wp_hash' ) ) {
	function wp_hash( $s ) { return md5( 'unit|' . $s ); }
}
if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $uid, $key, $single = false ) { return $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] ?? ''; }
}
if ( ! defined( 'ZTER_APP_ID' ) ) {
	define( 'ZTER_APP_ID', 'territories' );
}

require_once __DIR__ . '/TeamRolesTest.php'; // its WordPress stubs and the Team Roles classes
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/territories/includes/functions-zter-people.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/territories/includes/class-zter-db.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/territories/includes/class-zter-kinds.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/territories/includes/class-zter-rules.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/territories/includes/class-zter-zones.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/territories/includes/class-zter-verbs.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/territories/includes/class-zter-rest.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/estimate/includes/class-zest-territories.php';

final class TerritoriesTest extends TestCase {

	const ALEX   = 21; // sales, AA
	const JORDAN = 22; // sales, JJ
	const SAM    = 23; // tech, SS
	const RILEY  = 24; // the shared device, RR
	const CASEY  = 25; // owner, CC

	public static function catalog() {
		return array(
			'gadget'  => array( 'id' => 'gadget', 'display_name' => 'Gadget', 'active' => true, 'sort_order' => 1, 'attributes' => array(), 'aliases' => array(), 'sellable' => 'standalone', 'parent_item_id' => '' ),
			'fixture' => array( 'id' => 'fixture', 'display_name' => 'Fixture', 'active' => true, 'sort_order' => 2, 'attributes' => array(), 'aliases' => array(), 'sellable' => 'standalone', 'parent_item_id' => '' ),
		);
	}

	protected function setUp(): void {
		$GLOBALS['zdz_test_options']    = array();
		$GLOBALS['zdz_test_user_roles'] = array(
			self::ALEX   => array( 'zdz_sales' ),
			self::JORDAN => array( 'zdz_sales' ),
			self::SAM    => array( 'zdz_tech' ),
			self::RILEY  => array( 'zdz_general' ),
			self::CASEY  => array( 'zdz_owner' ),
		);
		$GLOBALS['zdz_test_user_names'] = array( self::ALEX => 'Alex', self::JORDAN => 'Jordan', self::SAM => 'Sam', self::RILEY => 'Riley', self::CASEY => 'Casey' );
		$GLOBALS['zdz_test_users']      = TeamRolesTest::users();
		$GLOBALS['zdz_test_user_meta']  = array(
			self::ALEX   => array( 'zdz_user_initials' => 'AA' ),
			self::JORDAN => array( 'zdz_user_initials' => 'JJ' ),
			self::SAM    => array( 'zdz_user_initials' => 'SS' ),
			self::RILEY  => array( 'zdz_user_initials' => 'RR' ),
			self::CASEY  => array( 'zdz_user_initials' => 'CC' ),
		);
		$GLOBALS['zdz_test_filters'] = array(
			'zdz_abilities'  => array( 'ZRO_Roles', 'abilities_filter' ),
			'zdz_person_can' => array( 'ZRO_Roles', 'person_can_filter' ),
		);
		ZRO_DB::memory( true );
		ZTER_DB::memory( true );
		ZRO_Abilities::use_catalog( array( __CLASS__, 'catalog' ) );
		ZTER_Kinds::flush();
		ZDZ_Capabilities::flush();
		zro_team( true );
		zter_team( true );
		// Who can sell what (Team Roles), the words that name the items, and the territories.
		foreach ( array( array( self::ALEX, 'gadget__sell' ), array( self::JORDAN, 'gadget__sell' ), array( self::JORDAN, 'fixture__sell' ), array( self::SAM, 'gadget__install' ) ) as $m ) {
			ZRO_DB::set( $m[0], $m[1], 'can', '', '', self::CASEY );
		}
		$GLOBALS['zdz_test_options']['zter_words'] = array( 'rows' => array(
			array( 'id' => 'w1', 'type' => '', 'words' => array( 'gadget' ), 'repair' => 'any', 'item' => 'gadget' ),
			array( 'id' => 'w2', 'type' => '', 'words' => array( 'fixture' ), 'repair' => 'any', 'item' => 'fixture' ),
		), 'next' => 3 );
		ZTER_DB::set_zips( self::ALEX, array( '10001', '10003' ), self::CASEY );
		ZTER_DB::set_zips( self::JORDAN, array( '10001', '10002' ), self::CASEY );
	}

	protected function tearDown(): void {
		ZRO_DB::memory( false );
		ZTER_DB::memory( false );
		ZRO_Abilities::use_catalog( null );
		ZTER_Kinds::flush();
		ZDZ_Capabilities::flush();
		zro_team( true );
		zter_team( true );
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_user_roles'], $GLOBALS['zdz_test_user_names'], $GLOBALS['zdz_test_user_meta'], $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_users'] );
	}

	private function ask( $zip, array $lines, $default = 'QQ', $pairs = false, $stage = 'stub' ) {
		return ZTER_Rules::route( array(
			'zip' => $zip, 'lines' => array_map( function ( $l ) { return array( 'name' => $l, 'desc' => '' ); }, $lines ),
			'default_initials' => $default, 'asker_id' => self::CASEY, 'pairs' => $pairs, 'stage' => $stage,
		) );
	}

	private function rules( $stage, array $rows ) {
		$r = ZTER_Rules::save( $rows, self::CASEY, $stage );
		$this->assertIsArray( $r, is_wp_error( $r ) ? $r->get_error_message() : '' );
		return $r;
	}

	/* ── empty means nobody ───────────────────────────────────────────────────────────────── */

	public function test_an_empty_configuration_assigns_nobody(): void {
		$this->assertCount( 1, ZTER_Rules::get( 'stub' )['rows'], 'Core ships only "everything else"' );
		list( $answer, $rec ) = $this->ask( '10001', array( 'Gadget' ), '' );
		$this->assertSame( 0, $answer['user_id'] );
		$this->assertSame( '', $answer['initials'] );
		$this->assertTrue( $answer['review'], 'nobody named: a person decides' );
		$this->assertSame( 0, $rec['chosen_user_id'] );
		list( $inst ) = $this->ask( '10001', array( 'Gadget' ), '', false, 'install' );
		$this->assertSame( 0, $inst['user_id'], 'a won job gets no suggestion either' );
		$this->assertSame( '', $inst['initials'] );
	}

	/* ── shadow vs live ───────────────────────────────────────────────────────────────────── */

	public function test_shadow_keeps_the_askers_pick_and_live_answers(): void {
		$r = $this->rules( 'stub', array(
			array( 'id' => 'terr', 'who' => 'territory', 'need_can' => true ),
			array( 'id' => 'fallback', 'fallback' => true ),
		) );
		list( $a, $rec ) = $this->ask( '10002', array( 'Gadget' ) );
		$this->assertSame( 'shadow', $a['mode'] );
		$this->assertSame( 'QQ', $a['initials'], 'in shadow the asker keeps its own pick' );
		$this->assertSame( self::JORDAN, $rec['chosen_user_id'], 'the shadow record says who the rules would pick' );
		$live = ZTER_Rules::set_live( 'terr', true, $r['version'], self::CASEY, 'stub' );
		$this->assertIsArray( $live );
		list( $b ) = $this->ask( '10002', array( 'Gadget' ) );
		$this->assertSame( 'live', $b['mode'] );
		$this->assertSame( 'JJ', $b['initials'] );
		$this->assertSame( self::JORDAN, $b['user_id'] );
		$stale = ZTER_Rules::set_live( 'terr', false, $r['version'], self::CASEY, 'stub' );
		$this->assertTrue( is_wp_error( $stale ), 'a list changed since it was opened is refused' );
	}

	public function test_can_do_is_required_and_never_assumed(): void {
		$this->rules( 'stub', array(
			array( 'id' => 'terr', 'who' => 'territory', 'need_can' => true ),
			array( 'id' => 'fallback', 'fallback' => true ),
		) );
		list( , $rec ) = $this->ask( '10003', array( 'Fixture' ) ); // only Alex covers 10003, and he can't sell fixtures
		$this->assertSame( 0, $rec['chosen_user_id'] );
		$GLOBALS['zdz_test_filters']['zdz_person_can'] = function () { return null; }; // Team Roles absent
		list( , $rec2 ) = $this->ask( '10001', array( 'Gadget' ) );
		$this->assertSame( 0, $rec2['chosen_user_id'], 'without Team Roles a "can do" rule never matches' );
		$this->assertSame( 'off', $rec2['team_roles'] );
	}

	/* ── two people ───────────────────────────────────────────────────────────────────────── */

	public function test_a_named_person_takes_their_items_and_the_rest_goes_below(): void {
		$r = $this->rules( 'stub', array(
			array( 'id' => 'fix', 'who' => 'person', 'person' => self::JORDAN, 'kinds' => array( 'fixture' ), 'with_rest' => true ),
			array( 'id' => 'terr', 'who' => 'territory', 'need_can' => true ),
			array( 'id' => 'fallback', 'fallback' => true ),
		) );
		ZTER_Rules::set_live( 'fix', true, $r['version'], self::CASEY, 'stub' );
		$r = ZTER_Rules::get( 'stub' );
		ZTER_Rules::set_live( 'terr', true, $r['version'], self::CASEY, 'stub' );
		list( $pair, $rec ) = $this->ask( '10003', array( 'Fixture', 'Gadget' ), 'QQ', true );
		$this->assertSame( 'live', $pair['mode'] );
		$this->assertSame( 'JJ & AA', $pair['initials'] );
		$this->assertSame( array( self::JORDAN, self::ALEX ), array_map( function ( $p ) { return $p['user_id']; }, $pair['people'] ) );
		list( $one ) = $this->ask( '10003', array( 'Fixture', 'Gadget' ), 'QQ', false );
		$this->assertSame( 'QQ', $one['initials'], 'an asker that writes one person keeps its own pick' );
		$this->assertSame( 'shadow', $one['mode'] );
	}

	/* ── no-go zones ──────────────────────────────────────────────────────────────────────── */

	public function test_a_person_is_never_sent_into_their_no_go_zone(): void {
		$this->rules( 'stub', array(
			array( 'id' => 'terr', 'who' => 'territory', 'need_can' => true ),
			array( 'id' => 'fallback', 'fallback' => true ),
		) );
		$z = ZTER_Zones::save( array( array( 'label' => 'Not here', 'kind' => 'nogo', 'people' => array( self::ALEX ), 'zips' => '10003' ) ), self::CASEY );
		$this->assertIsArray( $z, is_wp_error( $z ) ? $z->get_error_message() : '' );
		$this->assertTrue( ZTER_Zones::excluded( self::ALEX, '10003' ) );
		$this->assertFalse( ZTER_Zones::excluded( self::ALEX, '10001' ) );
		list( , $rec ) = $this->ask( '10003', array( 'Gadget' ) );
		$this->assertSame( 0, $rec['chosen_user_id'], 'the only person covering it is kept out' );
		$marked = array_filter( $rec['trail'], function ( $t ) { return false !== strpos( $t, ZTER_Rules::NOGO_MARK ); } );
		$this->assertNotEmpty( $marked );
		$this->assertCount( count( $rec['trail'] ) - count( $marked ), ZTER_REST::trail_for( $rec['trail'], false ), 'outside the admin tier the line is left out' );
		$this->assertSame( array(), ZTER_Zones::visible_to( false, true ), 'a staff asker never sees a no-go zone' );
		$bad = ZTER_Zones::save( array( array( 'label' => 'Device', 'kind' => 'nogo', 'people' => array( self::RILEY ), 'zips' => '10001' ) ), self::CASEY );
		$this->assertTrue( is_wp_error( $bad ), 'only people on the team: never the shared device' );
	}

	public function test_a_job_outside_every_service_area_is_a_referral_naming_nobody(): void {
		$this->rules( 'stub', array(
			array( 'id' => 'refer', 'who' => 'refer' ),
			array( 'id' => 'terr', 'who' => 'territory', 'need_can' => true ),
			array( 'id' => 'fallback', 'fallback' => true ),
		) );
		ZTER_Zones::save( array( array( 'label' => 'Gadget area', 'kind' => 'service', 'abilities' => array( 'gadget' ), 'zips' => '10001 10002' ) ), self::CASEY );
		list( $a, $rec ) = $this->ask( '10003', array( 'Gadget' ) );
		$this->assertSame( 1, $rec['refer'], 'a shadow referral case' );
		$this->assertSame( 0, $rec['chosen_user_id'] );
		$this->assertFalse( $a['review'], 'a referral is its own case, not a review item' );
		list( , $in ) = $this->ask( '10001', array( 'Gadget' ) );
		$this->assertSame( 0, $in['refer'] );
	}

	/* ── rule history ─────────────────────────────────────────────────────────────────────── */

	public function test_an_earlier_list_is_put_back_as_a_new_version_without_going_live(): void {
		$v2 = $this->rules( 'stub', array(
			array( 'id' => 'terr', 'who' => 'territory', 'need_can' => true ),
			array( 'id' => 'fallback', 'fallback' => true ),
		) );
		$v3 = $this->rules( 'stub', array(
			array( 'id' => 'any', 'who' => 'anyone', 'need_can' => true ),
			array( 'id' => 'fallback', 'fallback' => true ),
		) );
		ZTER_Rules::set_live( 'any', true, $v3['version'], self::CASEY, 'stub' );
		$cur = ZTER_Rules::get( 'stub' );
		$this->assertTrue( is_wp_error( ZTER_Rules::revert( 'stub', $v2['version'], $cur['version'] - 1, self::CASEY ) ), 'stale page refused' );
		$back = ZTER_Rules::revert( 'stub', $v2['version'], $cur['version'], self::CASEY );
		$this->assertIsArray( $back );
		$now = ZTER_Rules::get( 'stub' );
		$this->assertSame( $cur['version'] + 1, $now['version'], 'a revert is a new version' );
		$this->assertSame( array( 'terr', 'fallback' ), array_map( function ( $r ) { return $r['id']; }, $now['rows'] ) );
		$this->assertFalse( $now['rows'][0]['live'], 'a revert never turns a rule live' );
		$this->assertNotNull( ZTER_Rules::rows_at( 'stub', $v3['version'] ), 'the replaced list stays in history' );
	}

	/* ── words and lines ──────────────────────────────────────────────────────────────────── */

	public function test_lines_are_read_into_items_and_work(): void {
		$k = ZTER_Kinds::classify( array( array( 'name' => 'Gadget', 'desc' => 'New install' ), array( 'name' => 'Fixture repair', 'desc' => '' ), array( 'name' => 'Location', 'desc' => '1 Example Way' ), array( 'name' => 'Trip charge', 'desc' => '' ) ) );
		$this->assertSame( array( 'gadget', 'fixture' ), $k['kinds'] );
		$this->assertSame( array( 'gadget' => 'new', 'fixture' => 'repair' ), $k['work'] );
		$this->assertSame( 'known', $k['state'] );
		$g = ZTER_Kinds::classify( array( array( 'name' => 'Repair', 'desc' => '' ) ) );
		$this->assertTrue( $g['generic'], 'a repair that names no item' );
		$this->assertSame( 'unknown', $g['state'] );
		$this->assertSame( 'none', ZTER_Kinds::classify( array() )['state'] );
	}

	/* ── the hooks stay quiet without Territories ─────────────────────────────────────────── */

	public function test_estimate_and_jobs_hooks_do_nothing_without_territories(): void {
		ZDZ_Capabilities::flush(); // no app registered a territories verb
		$r = ZEST_Territories::route( array( 'customer' => array( 'name' => 'Example Co', 'zip' => '10001' ), 'line_items' => array( array( 'description' => 'Gadget' ) ) ), 'QQ' );
		$this->assertSame( 'QQ', $r['initials'] );
		$this->assertSame( 'not active', $r['why'] );
		$this->assertSame( array( 'initials' => 'QQ', 'parenthetical' => '', 'user_id' => 9 ), ZEST_Territories::out_ctx( $r, array( 'initials' => 'QQ', 'parenthetical' => '', 'user_id' => 9 ) ) );
		$this->assertNull( ZJOB_Project_People::ask_installer( 'p1', array( array( 'name' => 'Gadget' ) ), '10001' ) );
		$this->assertSame( array( 'AB', 'CD' ), ZEST_Territories::split( '(AB & CD)' ) );
	}
}
