<?php
/**
 * Team Roles: what each person can and can't do.
 *
 *   - Deny by default: an unknown person, item, ability or role, an item asked without a role, a
 *     retired ability, the shared device, someone not on the team and a cell nobody marked all
 *     answer can = false. A person's answer never comes from another person's marks.
 *   - Items come from the catalog: none without one; Team Roles never creates an item.
 *   - A kind of work moved into items answers from the item cells it covered.
 *   - The one-time move: role defaults, the lower of two levels, never over a newer mark, once.
 *   - The chat verb's word match: catalog aliases, role words, a tie is a question.
 *   - The Jobs kinds of project are configuration: an empty list names nothing.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $uid, $key, $single = false ) { return $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] ?? ''; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( $k, $v = '', $d = '', $autoload = null ) {
		if ( isset( $GLOBALS['zdz_test_options'][ $k ] ) ) {
			return false;
		}
		$GLOBALS['zdz_test_options'][ $k ] = $v;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $k ) { unset( $GLOBALS['zdz_test_options'][ $k ] ); return true; }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
}
if ( ! defined( 'ZRO_APP_ID' ) ) {
	define( 'ZRO_APP_ID', 'team-roles' );
}

require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-capabilities.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-hierarchy.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/team-roles/includes/class-zro-db.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/team-roles/includes/class-zro-abilities.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/team-roles/includes/class-zro-roles.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/team-roles/includes/class-zro-migrate.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/team-roles/includes/class-zro-bridge.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/team-roles/includes/functions-zro-people.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/includes/class-zjob-project-types.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/jobs/includes/class-zjob-project-people.php';

final class TeamRolesTest extends TestCase {

	const ALEX   = 11; // sales
	const JORDAN = 12; // tech
	const SAM    = 13; // the shared device
	const RILEY  = 14; // not on the team
	const CASEY  = 15; // owner (admin tier)
	const BLAKE  = 16; // operator

	/** A neutral catalog: two items, one inactive, one add-on. */
	public static function catalog() {
		return array(
			'gadget'   => array( 'id' => 'gadget', 'display_name' => 'Gadget', 'active' => true, 'sort_order' => 1, 'attributes' => array( 'project_type' => 'alpha' ), 'aliases' => array( 'widget frame' ), 'sellable' => 'standalone', 'parent_item_id' => '' ),
			'fixture'  => array( 'id' => 'fixture', 'display_name' => 'Fixture', 'active' => true, 'sort_order' => 2, 'attributes' => array( 'work_roles' => array( 'sell', 'repair' ) ), 'aliases' => array(), 'sellable' => 'standalone', 'parent_item_id' => '' ),
			'trim-kit' => array( 'id' => 'trim-kit', 'display_name' => 'Trim kit', 'active' => true, 'sort_order' => 3, 'attributes' => array(), 'aliases' => array(), 'sellable' => 'component', 'parent_item_id' => '' ),
			'old-part' => array( 'id' => 'old-part', 'display_name' => 'Old part', 'active' => false, 'sort_order' => 4, 'attributes' => array(), 'aliases' => array(), 'sellable' => 'standalone', 'parent_item_id' => '' ),
		);
	}

	/** The site's users as WordPress hands them out (every account, any role). */
	public static function users() {
		$out = array();
		foreach ( (array) $GLOBALS['zdz_test_user_roles'] as $id => $roles ) {
			$out[] = (object) array( 'ID' => (int) $id, 'display_name' => (string) ( $GLOBALS['zdz_test_user_names'][ $id ] ?? '' ), 'roles' => $roles );
		}
		return $out;
	}

	protected function setUp(): void {
		$GLOBALS['zdz_test_options']    = array();
		$GLOBALS['zdz_test_user_roles'] = array(
			self::ALEX   => array( 'zdz_sales' ),
			self::JORDAN => array( 'zdz_tech' ),
			self::SAM    => array( 'zdz_general' ),
			self::RILEY  => array( 'subscriber' ),
			self::CASEY  => array( 'zdz_owner' ),
			self::BLAKE  => array( 'zdz_operator' ),
		);
		$GLOBALS['zdz_test_user_names'] = array( self::ALEX => 'Alex', self::JORDAN => 'Jordan', self::SAM => 'Sam', self::RILEY => 'Riley', self::CASEY => 'Casey', self::BLAKE => 'Blake' );
		$GLOBALS['zdz_test_users']      = self::users();
		ZRO_DB::memory( true );
		ZRO_Abilities::use_catalog( array( __CLASS__, 'catalog' ) );
		ZRO_Bridge::flush();
		ZJOB_Project_Types::flush_memo();
		ZDZ_Capabilities::flush();
		zro_team( true );
	}

	protected function tearDown(): void {
		ZRO_DB::memory( false );
		ZRO_Abilities::use_catalog( null );
		ZJOB_Project_Types::flush_memo();
		zro_team( true );
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_user_roles'], $GLOBALS['zdz_test_user_names'], $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_users'] );
	}

	private function mark( $uid, $key, $level, $note = '' ) {
		$r = ZRO_DB::set( $uid, $key, $level, $note, '', self::CASEY );
		$this->assertIsArray( $r );
	}

	/* ── deny by default ──────────────────────────────────────────────────────────────────── */

	public function test_unknown_everything_answers_no(): void {
		$this->mark( self::ALEX, 'gadget__sell', 'can' );
		$cases = array(
			array( 999, 'gadget__sell', 'an unknown person' ),
			array( 0, 'gadget__sell', 'no person' ),
			array( self::ALEX, 'no-such-item__sell', 'an unknown item' ),
			array( self::ALEX, 'gadget__polish', 'a word that is not a role' ),
			array( self::ALEX, 'fixture__install', 'a role the item does not have' ),
			array( self::ALEX, 'gadget', 'an item asked without a role' ),
			array( self::ALEX, '', 'nothing asked' ),
			array( self::SAM, 'gadget__sell', 'the shared device' ),
			array( self::RILEY, 'gadget__sell', 'someone not on the team' ),
			array( self::ALEX, 'old-part__sell', 'an item the catalog retired' ),
		);
		foreach ( $cases as $c ) {
			$a = ZRO_Roles::person_can( $c[0], $c[1] );
			$this->assertSame( array( 'can', 'level', 'why' ), array_keys( $a ), 'always the three-key shape: ' . $c[2] );
			$this->assertFalse( $a['can'], $c[2] . ' must answer no' );
			$this->assertSame( 'cannot', $a['level'], $c[2] );
		}
	}

	public function test_in_use_only_once_someone_holds_a_mark(): void {
		$this->assertFalse( ZRO_Roles::in_use_filter( null ), 'no marks at all: not in use' );
		$this->assertFalse( ZRO_DB::has_marks() );
		$this->mark( self::JORDAN, 'gadget__install', 'cannot' );
		$this->assertFalse( ZRO_Roles::in_use_filter( null ), 'a bare Can\'t is the default and is not stored' );
		$this->mark( self::JORDAN, 'gadget__install', 'learning' );
		$this->assertTrue( ZRO_Roles::in_use_filter( null ), 'any stored mark puts Team Roles in use' );
		$this->assertTrue( ZRO_Roles::in_use_filter( false ), 'the app is the authority on the answer' );
	}

	public function test_an_unmarked_cell_reads_cant_and_never_borrows_another_persons_mark(): void {
		$this->mark( self::ALEX, 'gadget__install', 'can' );
		$alex   = ZRO_Roles::person_can( self::ALEX, 'gadget__install' );
		$jordan = ZRO_Roles::person_can( self::JORDAN, 'gadget__install' );
		$this->assertTrue( $alex['can'] );
		$this->assertFalse( $jordan['can'], 'Jordan has no mark: Alex\'s is not his' );
		$this->assertStringContainsString( 'not marked', $jordan['why'] );
		$this->assertFalse( zdz_person_can( self::JORDAN, 'gadget', 'install' )['can'] );
		$this->assertTrue( zdz_person_can( self::ALEX, 'gadget', 'install' )['can'], 'the two-part form asks the same cell' );
	}

	public function test_learning_is_not_can_and_a_note_is_carried(): void {
		$this->mark( self::JORDAN, 'gadget__install', 'learning', 'with a partner' );
		$a = ZRO_Roles::person_can( self::JORDAN, 'gadget__install' );
		$this->assertFalse( $a['can'] );
		$this->assertSame( 'learning', $a['level'] );
		$this->assertStringContainsString( 'with a partner', $a['why'] );
		$this->assertFalse( ZRO_Roles::can( self::JORDAN, 'gadget__install' ) );
	}

	public function test_who_can_lists_can_then_learning_and_never_for_a_bare_item(): void {
		$this->mark( self::ALEX, 'gadget__sell', 'learning' );
		$this->mark( self::CASEY, 'gadget__sell', 'can' );
		$this->mark( self::SAM, 'gadget__sell', 'can' ); // the shared device is never on the team list
		$rows = ZRO_Roles::who_can( 'gadget__sell', true );
		$this->assertSame( array( self::CASEY, self::ALEX ), array_map( function ( $r ) { return $r['user_id']; }, $rows ) );
		$this->assertCount( 1, ZRO_Roles::who_can( 'gadget__sell', false ) );
		$this->assertSame( array(), ZRO_Roles::who_can( 'gadget', true ), 'selling is not installing: an item without a role names no one' );
		$this->assertSame( array(), ZRO_Roles::who_can( 'no-such', true ) );
	}

	/* ── items from the catalog ───────────────────────────────────────────────────────────── */

	public function test_items_come_only_from_the_catalog(): void {
		ZRO_Abilities::use_catalog( function () { return array(); } );
		$this->assertSame( array(), ZRO_Abilities::items( true ), 'no catalog: no items' );
		$r = ZRO_Abilities::add( 'Gizmo', 'item', '', self::CASEY );
		$this->assertTrue( is_wp_error( $r ), 'Team Roles never creates an item' );
		ZRO_Abilities::use_catalog( array( __CLASS__, 'catalog' ) );
		$items = ZRO_Abilities::items();
		$this->assertSame( array( 'gadget', 'fixture', 'trim-kit' ), array_keys( $items ) );
		$this->assertSame( array( 'sell', 'repair' ), ZRO_Abilities::roles_of( $items['fixture'] ), 'the item attribute decides its roles' );
		$this->assertTrue( $items['trim-kit']['addon'], 'a component is an add-on' );
		$this->assertTrue( ZRO_Abilities::get( 'old-part' )['retired'] );
	}

	public function test_item_roles_and_tracking_are_kept_here_with_their_answers(): void {
		$this->mark( self::ALEX, 'gadget__repair', 'can' );
		$r = ZRO_Abilities::edit( 'gadget', null, null, null, self::CASEY, array( 'roles' => array( 'sell' ) ) );
		$this->assertSame( array( 'sell' ), ZRO_Abilities::roles_of( $r ) );
		$this->assertFalse( ZRO_Roles::person_can( self::ALEX, 'gadget__repair' )['can'], 'a dropped role answers no' );
		ZRO_Abilities::edit( 'gadget', null, null, null, self::CASEY, array( 'roles' => array( 'sell', 'repair' ) ) );
		$this->assertTrue( ZRO_Roles::person_can( self::ALEX, 'gadget__repair' )['can'], 'and its answer comes back with it' );
		ZRO_Abilities::retire( 'gadget', true, self::CASEY );
		$this->assertFalse( ZRO_Roles::person_can( self::ALEX, 'gadget__repair' )['can'], 'an item not tracked answers no' );
		$this->assertTrue( is_wp_error( ZRO_Abilities::move( 'gadget', -1, self::CASEY ) ), 'items keep the catalog order' );
	}

	public function test_plain_abilities_are_added_renamed_and_retired_by_key(): void {
		$a = ZRO_Abilities::add( 'Ladder work', 'site', '', self::CASEY );
		$this->assertSame( 'ladder_work', $a['key'] );
		$this->assertTrue( is_wp_error( ZRO_Abilities::add( 'ladder work', 'site', '', self::CASEY ) ), 'one name, one column' );
		$this->mark( self::JORDAN, 'ladder_work', 'can' );
		ZRO_Abilities::edit( 'ladder_work', 'Ladders', null, null, self::CASEY );
		$this->assertTrue( ZRO_Roles::person_can( self::JORDAN, 'ladder_work' )['can'], 'renaming keeps the answer' );
		ZRO_Abilities::retire( 'ladder_work', true, self::CASEY );
		$this->assertFalse( ZRO_Roles::person_can( self::JORDAN, 'ladder_work' )['can'] );
		$back = ZRO_Abilities::add( 'Ladders', 'site', '', self::CASEY );
		$this->assertSame( 'ladder_work', $back['key'], 'the same name brings the retired ability back' );
		$this->assertTrue( ZRO_Roles::person_can( self::JORDAN, 'ladder_work' )['can'] );
	}

	/* ── a kind of work moved into items ──────────────────────────────────────────────────── */

	public function test_a_merged_kind_answers_from_the_cells_it_covered(): void {
		ZRO_Abilities::add( 'Fixture repairs', 'work', '', self::CASEY );
		ZRO_Abilities::set_merged( 'fixture_repairs', array( array( 'item' => 'fixture', 'work' => 'repair' ), array( 'item' => 'gadget', 'work' => 'repair' ) ) );
		$this->mark( self::JORDAN, 'gadget__install', 'can' );     // new work: not what a repair kind covered
		$this->assertFalse( ZRO_Roles::person_can( self::JORDAN, 'fixture_repairs' )['can'] );
		$this->mark( self::JORDAN, 'fixture__repair', 'learning' );
		$this->assertSame( 'learning', ZRO_Roles::person_can( self::JORDAN, 'fixture_repairs' )['level'] );
		$this->mark( self::JORDAN, 'gadget__repair', 'can' );
		$this->assertTrue( ZRO_Roles::person_can( self::JORDAN, 'fixture_repairs' )['can'], 'the best of the cells it covered' );
		$this->assertSame( array( self::JORDAN ), array_map( function ( $r ) { return $r['user_id']; }, ZRO_Roles::who_can( 'fixture_repairs' ) ) );
	}

	/* ── the one-time move ────────────────────────────────────────────────────────────────── */

	public function test_the_move_uses_role_defaults_takes_the_lower_level_and_runs_once(): void {
		ZRO_Abilities::add( 'Gadget work', 'work', '', self::CASEY );
		$GLOBALS['zdz_test_options']['zro_move_defaults'] = array( 'gadget_work' => array( array( 'item' => 'gadget', 'work' => 'any' ) ) );
		$this->mark( self::ALEX, 'gadget_work', 'can' );       // sales role: sells
		$this->mark( self::JORDAN, 'gadget_work', 'learning' ); // tech role: installs and repairs
		$this->mark( self::BLAKE, 'gadget_work', 'can' );       // operator: does neither by default
		$this->mark( self::JORDAN, 'gadget__repair', 'cannot', 'newer mark' ); // already on the new grid
		$this->assertTrue( ZRO_Migrate::needed() );
		$p = ZRO_Migrate::preview();
		$this->assertSame( 0, ZRO_DB::count_for( array( 'gadget__sell' ) ), 'a preview writes nothing' );
		$this->assertSame( 1, $p['kept'], 'a cell already marked is left alone' );
		$rec = ZRO_Migrate::apply( array(), array(), self::CASEY );
		$this->assertIsArray( $rec );
		$this->assertTrue( ZRO_Roles::person_can( self::ALEX, 'gadget__sell' )['can'] );
		$this->assertFalse( ZRO_Roles::person_can( self::ALEX, 'gadget__install' )['can'], 'a seller is not made an installer' );
		$this->assertSame( 'learning', ZRO_Roles::person_can( self::JORDAN, 'gadget__install' )['level'], 'the lower of the mark and the role' );
		$this->assertSame( 'cannot', ZRO_Roles::person_can( self::JORDAN, 'gadget__repair' )['level'], 'never over a newer mark' );
		$this->assertStringContainsString( 'not marked', ZRO_Roles::person_can( self::BLAKE, 'gadget__sell' )['why'], 'nothing written for a role that does neither' );
		$this->assertSame( 'learning', ZRO_Roles::person_can( self::JORDAN, 'gadget_work' )['level'], 'the moved kind answers from the items' );
		$this->assertTrue( is_wp_error( ZRO_Migrate::apply( array(), array(), self::CASEY ) ), 'the move runs once' );
		$this->assertFalse( ZRO_Migrate::needed() );
	}

	public function test_the_move_never_plans_someone_off_the_team(): void {
		ZRO_Abilities::add( 'Gadget work', 'work', '', self::CASEY );
		$GLOBALS['zdz_test_options']['zro_move_defaults'] = array( 'gadget_work' => array( array( 'item' => 'gadget', 'work' => 'new' ) ) );
		$this->mark( self::RILEY, 'gadget_work', 'can' );
		$this->mark( self::SAM, 'gadget_work', 'can' );
		ZRO_Migrate::apply( array( array( 'id' => self::RILEY, 'sells' => 'can', 'installs' => 'can' ) ), array(), self::CASEY );
		$this->assertSame( 0, ZRO_DB::count_for( array( 'gadget__sell', 'gadget__install' ) ), 'unknown people are skipped, never written' );
	}

	public function test_the_move_finds_a_persons_own_task_mark_first(): void {
		ZRO_Abilities::add( 'Selling', 'task', '', self::CASEY, array( 'job_role' => 'seller' ) );
		$this->mark( self::JORDAN, 'selling', 'can' );
		$d = ZRO_Migrate::person_defaults( self::JORDAN );
		$this->assertSame( 'can', $d['sells'], 'their own Sell task beats their role' );
		$this->assertSame( 'can', $d['installs'], 'a tech installs by role' );
		$this->assertSame( 'cannot', ZRO_Migrate::person_defaults( self::BLAKE )['installs'] );
	}

	/* ── the chat verb's words ────────────────────────────────────────────────────────────── */

	public function test_word_match_reads_catalog_names_and_role_words(): void {
		$m = ZRO_Bridge::match( 'who can install gadgets' );
		$this->assertSame( 'gadget', $m['item']['key'] ?? '' );
		$this->assertSame( 'install', $m['role'] );
		$m = ZRO_Bridge::match( 'who does the widget frame' );
		$this->assertSame( 'gadget', $m['item']['key'] ?? '', 'a catalog alias names the item' );
		$this->assertSame( '', $m['role'], 'no role word: every role is answered' );
		ZRO_Abilities::add( 'Gadget lifting', 'site', '', self::CASEY );
		$m = ZRO_Bridge::match( 'who can do gadgets' );
		$this->assertArrayHasKey( 'options', $m, 'a tie is a question, never a guess' );
		$this->assertSame( array(), ZRO_Bridge::match( 'the and of' ) );
	}

	public function test_the_verb_is_a_read_for_the_app_and_refuses_the_shared_device(): void {
		$d = ZRO_Bridge::descriptor();
		$this->assertFalse( $d['side_effect'] );
		$this->assertFalse( $d['kiosk'] );
		$this->assertSame( 'team-roles', $d['app'] );
		$this->assertSame( 'viewer', $d['tier'] );
		$r = ZRO_Bridge::who_can_for_chat( array( 'requesting_user_id' => self::SAM, 'query' => 'gadget' ) );
		$this->assertTrue( $r['denied'] );
	}

	/* ── kinds of project are configuration ───────────────────────────────────────────────── */

	public function test_kinds_of_project_ship_empty_and_validate(): void {
		$this->assertSame( array(), ZJOB_Project_Types::types() );
		$this->assertSame( '', ZJOB_Project_Types::type_for_item( self::catalog()['gadget'] ), 'a kind the business has not named is no kind' );
		$GLOBALS['zdz_test_options']['zjob_project_types'] = array( 'alpha' => 'Alpha work', 'b<a>d' => '', array( 'slug' => 'beta', 'label' => 'Beta work' ) );
		ZJOB_Project_Types::flush_memo();
		$this->assertSame( array( 'alpha' => 'Alpha work', 'beta' => 'Beta work' ), ZJOB_Project_Types::types() );
		$this->assertSame( 'alpha', ZJOB_Project_Types::type_for_item( self::catalog()['gadget'] ) );
		$this->assertSame( ',alpha,beta,', ZJOB_Project_Types::encode( array( 'beta', 'zeta', 'alpha' ) ) );
		$this->assertTrue( ZJOB_Project_Types::is_repair_text( 'Repair the hinge' ) );
		$this->assertFalse( ZJOB_Project_Types::is_repair_text( 'no repair needed' ) );
	}

	public function test_job_steps_are_never_the_shared_device_and_a_seller_is_staff(): void {
		$this->assertFalse( ZJOB_Project_People::eligible( self::SAM, 'installer' ) );
		$this->assertFalse( ZJOB_Project_People::eligible( self::JORDAN, 'seller' ), 'a tech is not staff' );
		$this->assertTrue( ZJOB_Project_People::eligible( self::JORDAN, 'installer' ) );
		$this->assertTrue( ZJOB_Project_People::eligible( self::ALEX, 'seller' ) );
		$this->assertFalse( ZJOB_Project_People::eligible( self::RILEY, 'installer' ), 'no rung, no step' );
		$this->assertFalse( ZJOB_Project_People::eligible( self::ALEX, 'nonsense' ) );
	}
}
