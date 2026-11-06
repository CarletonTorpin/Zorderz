<?php
/**
 * What an Identity Pack writes into apps that keep their own data: Territories (territories.yml) and
 * Team Roles marks (parties.yml), through the one consumer registry.
 *
 *   - Preview writes nothing; apply writes through each app's own checks; revert puts back what the pack replaced.
 *   - Territories rules a pack writes are in shadow, always, even when the file says live; a revert never turns a
 *     rule live either, and names the rules that were live before.
 *   - Unknown people, the shared device, people sharing initials, unknown items and bad ZIPs are skipped and listed.
 *   - Revert leaves alone what someone changed after the pack, and says so.
 *
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/zdz-pb-no-content' );
}
if ( ! function_exists( 'get_template_directory' ) ) {
	function get_template_directory() { return sys_get_temp_dir() . '/zdz-pb-no-theme'; }
}
if ( ! function_exists( 'wp_roles' ) ) {
	function wp_roles() { return (object) array( 'role_names' => array() ); }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ); }
}
if ( ! function_exists( 'get_role' ) ) {
	function get_role( $slug ) { return $GLOBALS['zdz_test_roles'][ $slug ] ?? null; }
}
if ( ! function_exists( '_n' ) ) {
	function _n( $one, $many, $n, $d = null ) { return 1 === (int) $n ? $one : $many; }
}

require_once __DIR__ . '/TerritoriesTest.php'; // its WordPress stubs, the Team Roles and Territories classes
require_once __DIR__ . '/notify-media-stubs.php'; // what the Business Profile reads (after the stubs above)
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-business-profile.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-identity-pack.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/territories/includes/class-zter-pack.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/team-roles/includes/class-zro-pack.php';

final class WrittenByAPackAppsTest extends TestCase {

	const ALEX   = 21; // sales, AA
	const JORDAN = 22; // sales, JJ
	const SAM    = 23; // tech, SS
	const RILEY  = 24; // the shared device, RR
	const CASEY  = 25; // owner, CC

	private $root;

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
		$GLOBALS['zdz_test_current_user'] = self::CASEY;
		$this->root                       = sys_get_temp_dir() . '/zdz-pack-apps-' . getmypid() . '-' . mt_rand();
		@mkdir( $this->root . '/example-co', 0700, true );
		$root                        = $this->root;
		$GLOBALS['zdz_test_filters'] = array(
			'zdz_abilities'               => array( 'ZRO_Roles', 'abilities_filter' ),
			'zdz_person_can'              => array( 'ZRO_Roles', 'person_can_filter' ),
			'zdz_identity_pack_paths'     => function () use ( $root ) { return array( $root ); },
			'zdz_identity_pack_consumers' => function ( $c ) { return ZRO_Pack::register( ZTER_Pack::register( $c ) ); },
		);
		ZRO_DB::memory( true );
		ZTER_DB::memory( true );
		ZRO_Abilities::use_catalog( array( 'TerritoriesTest', 'catalog' ) );
		ZTER_Kinds::flush();
		ZDZ_Capabilities::flush();
		zro_team( true );
		zter_team( true );
		ZRO_DB::set( self::JORDAN, 'gadget__sell', 'can', 'trained', '', self::CASEY );
		ZRO_DB::set( self::SAM, 'gadget__install', 'can', '', '', self::CASEY );
		ZTER_DB::set_zips( self::ALEX, array( '10001', '10003' ), self::CASEY );
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->root . '/example-co/*' ) as $f ) {
			@unlink( $f );
		}
		@rmdir( $this->root . '/example-co' );
		@rmdir( $this->root );
		ZRO_DB::memory( false );
		ZTER_DB::memory( false );
		ZRO_Abilities::use_catalog( null );
		ZTER_Kinds::flush();
		ZDZ_Capabilities::flush();
		zro_team( true );
		zter_team( true );
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_user_roles'], $GLOBALS['zdz_test_user_names'], $GLOBALS['zdz_test_user_meta'], $GLOBALS['zdz_test_filters'], $GLOBALS['zdz_test_users'], $GLOBALS['zdz_test_current_user'] );
	}

	private function file( $stem, $yaml ) {
		file_put_contents( $this->root . '/example-co/' . $stem . '.yml', $yaml );
	}

	private static function refused( array $p ) {
		return array_map( function ( $r ) { return $r['file'] . ':' . $r['path']; }, $p['refused'] );
	}

	/** A live stub rule for Alex, and one service zone: what the site had before the pack. */
	private function live_site() {
		$r = ZTER_Rules::save( array(
			array( 'id' => 'mine', 'zips' => array( '10003' ), 'who' => 'person', 'person' => self::ALEX ),
			array( 'id' => 'fallback', 'fallback' => true ),
		), self::CASEY, 'stub' );
		$this->assertIsArray( $r );
		$this->assertIsArray( ZTER_Rules::set_live( 'mine', true, $r['version'], self::CASEY, 'stub' ) );
		$this->assertIsArray( ZTER_Zones::save( array( array( 'label' => 'Old area', 'kind' => 'area', 'zips' => array( '10001' ) ) ), self::CASEY ) );
	}

	private const TERRITORIES = "zones:\n  - id: north\n    label: \"North side\"\n    kind: service\n    visible: staff\n    abilities: [gadget, nosuch]\n    zips: [\"10001\", \"10002\"]\n  - label: \"Kept out\"\n    kind: nogo\n    people: [SS, QQ]\n    zips: [\"10009\"]\n    prefixes: [\"104\"]\n  - label: \"Nobody known\"\n    kind: nogo\n    people: [QQ]\n    zips: [\"10008\"]\nrules:\n  stub:\n    - id: jj_north\n      zips: [\"10002\"]\n      who: person\n      person: JJ\n      live: true\n    - id: ghost\n      zips: [\"10004\"]\n      who: person\n      person: QQ\n    - id: kiosk\n      zips: [\"10006\"]\n      who: person\n      person: RR\n    - id: odd_item\n      kinds: [nosuch]\n      who: territory\n    - fallback: true\n      who: default\nterritories:\n  - person: AA\n    zips: [\"10005\"]\n  - person: ZZ\n    zips: [\"10007\"]\n  - person: JJ\n    zips: [\"1234\"]\n";

	/* ── territories.yml ─────────────────────────────────────────────────────────────────────── */

	public function test_territories_round_trip_in_shadow_with_unknowns_skipped(): void {
		$this->live_site();
		$rules_before = get_option( ZTER_Rules::OPTION );
		$zones_before = ZTER_Zones::all()['rows'];
		$this->file( 'territories', self::TERRITORIES );

		$p = ZDZ_Identity_Pack::preview( 'example-co' );
		$this->assertSame( array(), $p['errors'] );
		$paths = array_column( $p['changes'], 'path' );
		foreach ( array( 'zones.north', 'zones.kept_out', 'zones.old_area', 'rules.stub', 'territories.AA' ) as $want ) {
			$this->assertContains( $want, $paths );
		}
		$refused = self::refused( $p );
		foreach ( array( 'territories:zones.Kept out.people', 'territories:zones.Nobody known', 'territories:zones.North side.abilities', 'territories:rules.stub.ghost.person', 'territories:rules.stub.kiosk.person', 'territories:rules.stub.odd_item', 'territories:territories.ZZ', 'territories:territories.JJ' ) as $want ) {
			$this->assertContains( $want, $refused );
		}
		$this->assertStringContainsString( 'go back to shadow', implode( ' ', $p['notes'] ) );
		$this->assertSame( $rules_before, get_option( ZTER_Rules::OPTION ), 'a preview writes nothing' );
		$this->assertSame( array( '10001', '10003' ), ZTER_DB::zips_of( self::ALEX ) );

		$this->assertTrue( ZDZ_Identity_Pack::apply( 'example-co', true )['ok'] );
		$rules = ZTER_Rules::get( 'stub' )['rows'];
		$this->assertSame( array( 'jj_north', 'fallback' ), array_column( $rules, 'id' ) );
		$this->assertSame( array( false, false ), array_map( function ( $r ) { return (bool) $r['live']; }, $rules ), 'every rule a pack writes is in shadow, whatever the file says' );
		$this->assertSame( self::JORDAN, $rules[0]['person'] );
		$zones = ZTER_Zones::all()['rows'];
		$this->assertSame( array( 'north', 'kept_out' ), array_column( $zones, 'id' ) );
		$this->assertSame( array( self::SAM ), $zones[1]['people'], 'only the person on the team' );
		$this->assertSame( array( 'gadget' ), $zones[0]['abilities'] );
		$this->assertSame( array( '10005' ), ZTER_DB::zips_of( self::ALEX ) );
		$this->assertSame( array(), ZTER_DB::zips_of( self::JORDAN ), 'a territory with a bad ZIP is left as it is' );

		$v   = ZDZ_Identity_Pack::revert();
		$msg = implode( ' ', $v['messages'] );
		$this->assertSame( $zones_before, ZTER_Zones::all()['rows'], 'the zones are back' );
		$after = ZTER_Rules::get( 'stub' )['rows'];
		$this->assertSame( array( 'mine', 'fallback' ), array_column( $after, 'id' ), 'the rules are back' );
		$this->assertFalse( (bool) $after[0]['live'], 'a revert never turns a rule live' );
		$this->assertStringContainsString( 'Live before the pack: mine', $msg );
		$this->assertSame( array( '10001', '10003' ), ZTER_DB::zips_of( self::ALEX ), 'the ZIPs are back' );
		$this->assertArrayNotHasKey( ZTER_Pack::APPLIED_OPTION, $GLOBALS['zdz_test_options'] );
	}

	public function test_territories_revert_leaves_what_changed_since(): void {
		$this->file( 'territories', "rules:\n  stub:\n    - id: jj_north\n      zips: [\"10002\"]\n      who: person\n      person: JJ\nterritories:\n  - person: AA\n    zips: [\"10005\"]\n" );
		ZDZ_Identity_Pack::apply( 'example-co', true );
		$cur = ZTER_Rules::get( 'stub' );
		ZTER_Rules::set_live( 'jj_north', true, $cur['version'], self::CASEY, 'stub' );   // a person turns it live after the pack
		ZTER_DB::set_zips( self::ALEX, array( '10005', '10006' ), self::CASEY );

		$msg = implode( ' ', ZDZ_Identity_Pack::revert()['messages'] );
		$this->assertStringContainsString( 'stub rules changed since the pack', $msg );
		$this->assertTrue( (bool) ZTER_Rules::get( 'stub' )['rows'][0]['live'], 'a person\'s later choice stands' );
		$this->assertStringContainsString( 'ZIP codes changed since the pack', $msg );
		$this->assertSame( array( '10005', '10006' ), ZTER_DB::zips_of( self::ALEX ) );
	}

	public function test_the_shipped_example_names_people_by_initials_and_only_they_are_refused(): void {
		$GLOBALS['zdz_test_filters']['zdz_identity_pack_paths'] = function () { return array( dirname( __DIR__, 2 ) . '/zorderz/identity-packs' ); };
		$GLOBALS['zdz_test_user_meta'][ self::ALEX ]['zdz_user_initials'] = 'AE';
		zter_team( true );
		zro_team( true );
		$p = ZDZ_Identity_Pack::preview( 'example-business' );
		$this->assertSame( array(), $p['errors'] );
		$paths = array_column( $p['changes'], 'path' );
		foreach ( array( 'zones.service_area', 'rules.stub', 'rules.install', 'territories.AE' ) as $want ) {
			$this->assertContains( $want, $paths );
		}
		foreach ( $p['refused'] as $r ) {
			if ( false !== strpos( $r['why'], 'not loaded here' ) ) {
				continue; // a kernel service this test does not load
			}
			$this->assertMatchesRegularExpression( '/^(territories|parties):|vulnerability_feed/', $r['file'] . ':' . $r['path'], 'only people, items and rows this test site does not have' );
		}
		$this->assertContains( 'territories:territories.JE', self::refused( $p ), 'Jordan has other initials here' );
		$this->assertNotContains( 'territories:territories.AE', self::refused( $p ) );
	}

	/* ── parties.yml: Team Roles marks ───────────────────────────────────────────────────────── */

	public function test_team_role_marks_round_trip_with_unknowns_skipped(): void {
		$this->file( 'parties', "people:\n  - initials: JJ\n    name: \"Jordan\"\n    team_roles:\n      items:\n        gadget: { sell: learning, install: can }\n  - initials: SS\n    team_roles:\n      items:\n        gadget: { install: learning, fly: can }\n        nosuch: { sell: can }\n      abilities:\n        nosuch_task: can\n  - initials: RR\n    team_roles:\n      items:\n        gadget: { sell: can }\n  - initials: QQ\n    team_roles:\n      items:\n        gadget: { sell: can }\n  - initials: AA\n    email: \"alex@example.com\"\n    team_roles:\n      items:\n        fixture: { sell: maybe }\n" );
		$before = ZRO_DB::answers_for( array( self::ALEX, self::JORDAN, self::SAM, self::RILEY ) );

		$p = ZDZ_Identity_Pack::preview( 'example-co' );
		$this->assertSame( array( 'people.JJ.gadget__sell', 'people.JJ.gadget__install', 'people.SS.gadget__install' ), array_column( $p['changes'], 'path' ) );
		$refused = self::refused( $p );
		foreach ( array( 'parties:people.SS.team_roles.items.gadget.fly', 'parties:people.SS.team_roles.items.nosuch', 'parties:people.SS.team_roles.abilities.nosuch_task', 'parties:people.RR', 'parties:people.QQ', 'parties:people.AA.team_roles.items.fixture.sell' ) as $want ) {
			$this->assertContains( $want, $refused );
		}
		$this->assertContains( 'parties: people.AA.email', $p['unconsumed'] );
		$this->assertSame( $before, ZRO_DB::answers_for( array( self::ALEX, self::JORDAN, self::SAM, self::RILEY ) ), 'a preview writes nothing' );

		ZDZ_Identity_Pack::apply( 'example-co', true );
		$sell = ZRO_DB::answer( self::JORDAN, 'gadget__sell' );
		$this->assertSame( 'learning', $sell['level'] );
		$this->assertSame( 'trained', $sell['note'], 'a mark keeps the cell\'s note' );
		$this->assertSame( 'can', ZRO_DB::answer( self::JORDAN, 'gadget__install' )['level'] );
		$this->assertSame( 'learning', ZRO_DB::answer( self::SAM, 'gadget__install' )['level'] );
		$this->assertNull( ZRO_DB::answer( self::RILEY, 'gadget__sell' ), 'never the shared device' );

		ZDZ_Identity_Pack::revert();
		$this->assertSame( $before, ZRO_DB::answers_for( array( self::ALEX, self::JORDAN, self::SAM, self::RILEY ) ), 'every mark back as it was, unmarked included' );
	}

	public function test_team_role_revert_leaves_a_cell_changed_since(): void {
		$this->file( 'parties', "people:\n  - initials: JJ\n    team_roles:\n      items:\n        gadget: { sell: learning, repair: can }\n" );
		ZDZ_Identity_Pack::apply( 'example-co', true );
		ZRO_DB::set( self::JORDAN, 'gadget__repair', 'cannot', 'not yet', '', self::CASEY );   // changed after the pack
		$msg = implode( ' ', ZDZ_Identity_Pack::revert()['messages'] );
		$this->assertStringContainsString( 'gadget__repair', $msg );
		$this->assertSame( 'cannot', ZRO_DB::answer( self::JORDAN, 'gadget__repair' )['level'] );
		$this->assertSame( 'can', ZRO_DB::answer( self::JORDAN, 'gadget__sell' )['level'], 'the cell nobody touched is back' );
	}
}
