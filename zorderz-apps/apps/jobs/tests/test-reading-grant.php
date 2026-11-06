<?php
/**
 * Projects — the reading grant (`view_team_projects`, a theme data permission).
 *
 * A person holding it SEES every Project's work (oversight: never amounts) and has every Project listed under
 * "All I can see"; it never counts for a write gate (attach an appointment, place an order, schedule). Core allows
 * it for the owner, admin and sales roles; it is switched per person; it never reaches the shared device.
 *
 * Runs in its own process (it loads the real permission engine, which other unit tests expect to be absent).
 * Run:  php zorderz-apps/apps/jobs/tests/test-reading-grant.php
 */
error_reporting( E_ALL & ~E_DEPRECATED );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

function apply_filters( $hook, $value = null, ...$args ) { return $value; }
function add_action( ...$a ) { return true; }
function add_filter( ...$a ) { return true; }
function __( $s, $d = null ) { return $s; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function get_option( $k, $d = false ) { return $d; }
function get_userdata( $uid ) {
	$roles = $GLOBALS['roles'][ (int) $uid ] ?? null;
	return null === $roles ? false : (object) array( 'ID' => (int) $uid, 'roles' => $roles );
}
function get_user_meta( $uid, $key, $single = false ) { return $GLOBALS['meta'][ (int) $uid ][ $key ] ?? ''; }
function user_can( $uid, $cap ) { return false; }
function get_users( $args = array() ) { return array(); }
function get_current_user_id() { return 0; }

/** Records the Projects list query so the test can see whether it was scoped. */
final class Reading_Grant_DB {
	public $prefix = 'wp_';
	public $sql    = array();
	public function prepare( $sql, ...$args ) { return (string) $sql; }
	public function get_results( $sql, $o = null ) { $this->sql[] = (string) $sql; return array(); }
	public function get_var( $sql ) { return null; }
	public function get_col( $sql ) { return array(); }
}
$GLOBALS['wpdb'] = new Reading_Grant_DB();

$root = dirname( __DIR__, 4 );
require $root . '/zorderz/inc/class-zdz-hierarchy.php';
require $root . '/zorderz/inc/class-zdz-data-permissions.php';
require __DIR__ . '/../includes/flow/class-zdz-flow.php';
require __DIR__ . '/../includes/class-zjob-db.php';
require __DIR__ . '/../includes/class-zjob-scope.php';
require __DIR__ . '/../includes/class-zjob-project.php';
require __DIR__ . '/../includes/class-zjob-project-visibility.php';

$GLOBALS['__pass'] = 0; $GLOBALS['__fail'] = 0; $GLOBALS['__fails'] = array();
function ok( bool $c, string $m ): void {
	if ( $c ) { $GLOBALS['__pass']++; echo "  \xE2\x9C\x93 $m\n"; }
	else { $GLOBALS['__fail']++; $GLOBALS['__fails'][] = $m; echo "  \xE2\x9C\x97 $m\n"; }
}
function perms( array $meta ): void {
	$GLOBALS['meta'] = $meta;
	ZDZ_Data_Permissions::flush_cache();
}

const SALES = 2, TECH = 3, DEVICE = 4;
$GLOBALS['roles'] = array( SALES => array( 'zdz_sales' ), TECH => array( 'zdz_tech' ), DEVICE => array( 'zdz_general' ) );
perms( array() );

$V = 'Zjob_Project_Visibility';
$p = array( 'id' => '01HZZZZZZZZZZZZZZZZZZZZZZ1', 'created_by' => 0, 'participants' => array(), 'sp_code' => '' );

// Seeing, never acting, never amounts.
ok( $V::sees_all_basic( SALES ), 'Core allows the reading grant for sales' );
ok( $V::REL_OVERSIGHT === $V::relationship( SALES, $p ), 'reading: oversight (the work)' );
ok( $V::may_view_project( SALES, $p ), 'the read gate opens' );
ok( $V::REL_NONE === $V::relationship( SALES, $p, false ), 'a write gate never counts it' );
ok( ! $V::actor_may_use_project( SALES, $p ), 'cannot act on a Project it only reads' );
ok( ! $V::decide_money( SALES, $p ), 'never the amounts' );

// Follows the permission; never the shared device.
ok( ! $V::may_view_project( TECH, $p ), 'Core denies it for field staff' );
ok( ! $V::may_view_project( DEVICE, $p ), 'the shared device reads nothing' );
perms( array(
	TECH   => array( 'zdz_data_permissions' => array( 'view_team_projects' => 'allow' ) ),
	SALES  => array( 'zdz_data_permissions' => array( 'view_team_projects' => 'deny' ) ),
	DEVICE => array( 'zdz_data_permissions' => array( 'view_team_projects' => 'allow' ) ),
) );
ok( $V::may_view_project( TECH, $p ), 'switched on for one person' );
ok( ! $V::may_view_project( SALES, $p ), 'switched off for one person' );
ok( ! $V::may_view_project( DEVICE, $p ), 'a per-person allow never reaches the shared device' );
perms( array() );

// The list: every Project only for the reading grant, and only when reading.
$db = $GLOBALS['wpdb'];
Zjob_Project::list_for( SALES, array( 'reading' => true ) );
Zjob_Project::list_for( SALES, array() );
Zjob_Project::list_for( TECH, array( 'reading' => true ) );
ok( 3 === count( $db->sql ), 'three list reads' );
ok( false === strpos( $db->sql[0] ?? '', 'wi.id IN' ), '"All I can see" with the grant: every Project' );
ok( false !== strpos( $db->sql[1] ?? '', 'wi.id IN' ), 'choosing a Project to act on stays scoped' );
ok( false !== strpos( $db->sql[2] ?? '', 'wi.id IN' ), 'without the grant: still scoped' );
ok( array() === Zjob_Project::list_for( DEVICE, array( 'reading' => true ) ) && 3 === count( $db->sql ), 'the shared device lists nothing' );

// The people confirmed on a Project read it (deny by default otherwise); they never act on it through that.
$GLOBALS['roles'][ 5 ] = array( 'zdz_tech' );
$GLOBALS['roles'][ 6 ] = array( 'zdz_tech' );
$GLOBALS['roles'][ 7 ] = array( 'zdz_tech' );
perms( array(
	5 => array( 'zdz_data_permissions' => array( 'view_own_commission' => 'allow' ) ),
	SALES => array( 'zdz_data_permissions' => array( 'view_team_projects' => 'deny', 'view_own_commission' => 'allow' ) ),
) );
$c = $p + array( 'confirmed' => array( 'seller' => SALES, 'installer' => 6, 'orderer' => array( 'user_id' => 7 ) ) );
ok( $V::REL_RELATED === $V::relationship( SALES, $c ), 'the confirmed salesperson: related (the sale is theirs)' );
ok( $V::decide_money( SALES, $c ), 'their own sale\'s money, with their own-commission permission' );
ok( $V::REL_OVERSIGHT === $V::relationship( 6, $c ), 'a confirmed installer reads the work' );
ok( $V::may_view_project( 7, $c ), 'a confirmed orderer reads it (either answer shape)' );
ok( ! $V::decide_money( 6, $c ), 'never the amounts for another step' );
ok( ! $V::actor_may_use_project( 6, $c ) && ! $V::actor_may_use_project( SALES, $c ), 'nobody acts on it by being confirmed' );
ok( $V::REL_NONE === $V::relationship( 5, $c ), 'someone not confirmed gains nothing' );
ok( ! $V::may_view_project( 6, $p ), 'no people list: no tie (deny by default)' );
ok( ! $V::may_view_project( DEVICE, $p + array( 'confirmed' => array( 'installer' => DEVICE ) ) ), 'the shared device, even if listed, reads nothing' );
perms( array() );

// The list carries the Projects a person is confirmed on only when asked for it, and only with the people table.
final class ZJOB_Project_People {
	const ROLES = array( 'seller', 'installer', 'installer2', 'orderer', 'preparer' );
	public static function ready(): bool { return true; }
	public static function table(): string { return 'wp_zjob_project_people'; }
	public static function confirmed( string $pid ): array { return $GLOBALS['confirmed'][ $pid ] ?? array(); }
}
$db->sql = array();
Zjob_Project::list_for( TECH, array( 'confirmed' => true ) );
Zjob_Project::list_for( TECH, array() );
ok( false !== strpos( $db->sql[0] ?? '', 'wp_zjob_project_people' ), 'a list people read adds the Projects they are confirmed on' );
ok( false === strpos( $db->sql[1] ?? '', 'zjob_project_people' ), 'choosing a Project to act on does not' );
$GLOBALS['confirmed'][ $p['id'] ] = array( 'installer' => array( 'user_id' => 6 ) );
ok( $V::may_view_project( 6, $p ), 'without an enriched list the people table is asked' );
ok( ! $V::actor_may_use_project( 6, $p ), 'and still never for acting' );

echo "\n" . str_repeat( '=', 56 ) . "\n";
printf( "  %d passed, %d failed\n", $GLOBALS['__pass'], $GLOBALS['__fail'] );
if ( $GLOBALS['__fail'] > 0 ) {
	foreach ( $GLOBALS['__fails'] as $f ) { echo "    - $f\n"; }
	exit( 1 );
}
echo "  ALL GREEN\n";
exit( 0 );
