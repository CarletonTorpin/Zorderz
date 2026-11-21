<?php
/**
 * Sign-in routes: the cases that need what the in-process unit tests run without.
 *
 *   - Demo Mode: an administrator whose device is in Demo Mode cannot take the page, manage, give a code or change
 *     an address, whoever the request claims to be.
 *   - The shared device is recognised by the platform's trait (the zdz_is_shared_device capability, whichever role
 *     carries it): such an account gets no backup route, even on an administrator role.
 *   - The wp-config.php line: define( 'ZDZ_SIGNIN_MANAGER', <user id> ) names a manager whatever the page holds. A
 *     line naming an account that cannot manage names nobody, keeps "I manage sign-in routes" closed, and the page
 *     says the line is wrong.
 *   - A WordPress network (multisite): an account belongs to every site of it, so only a network administrator
 *     manages sign-in routes or sets a backup address, and there is no second issuer.
 *
 * Standalone (its own PHP process): it loads the role and Demo Mode classes. The line is a constant, so each case of
 * it runs in a further process of its own: php tests/unit/test-signin-routes-standalone.php pin <user id>.
 * Run: php tests/unit/test-signin-routes-standalone.php
 *
 * Neutral data only.
 */

error_reporting( E_ALL & ~E_DEPRECATED );
require __DIR__ . '/../bootstrap-unit.php';
require __DIR__ . '/signin-test-doubles.php';
$root = dirname( __DIR__, 2 );
require $root . '/zorderz/inc/class-zdz-user-roles.php';
require $root . '/zorderz/inc/class-zdz-kiosk-demo.php';

// A WordPress network, for the cases that need one: a single site unless a case lists the network's administrators.
if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite() { return is_array( $GLOBALS['zdz_test_signin_network'] ?? null ); }
}
if ( ! function_exists( 'is_super_admin' ) ) {
	function is_super_admin( $uid = 0 ) { return in_array( (int) $uid, (array) ( $GLOBALS['zdz_test_signin_network'] ?? array() ), true ); }
}

function zdz_test_signin_page() {
	ob_start();
	ZDZ_Signin_Routes::render();
	return (string) ob_get_clean();
}

/* ── one case of the wp-config.php line, in its own process ───────────────── */

if ( 'pin' === ( $argv[1] ?? '' ) ) {
	define( 'ZDZ_SIGNIN_MANAGER', (int) ( $argv[2] ?? 0 ) );
	zdz_test_signin_reset();
	zdz_test_signin_people();
	zdz_test_signin_user( 4, 'sam@example.com', 'Sam Example', array( 'zdz_admin' ) );
	$r   = 'ZDZ_Signin_Routes';
	$out = array();
	update_option( 'zdz_signin_managers', array( 1 ) );   // Morgan was named on the page
	zdz_test_signin_as( 1 );
	$out['managers'] = $r::managers();
	zdz_test_signin_as( 2 );
	$out['two_manages'] = $r::may_manage( 2 );
	zdz_test_signin_as( 1 );
	$out['removed'] = $r::remove_manager( 1, 2 );
	$r::add_manager( 1, 2 );
	$r::add_manager( 1, 4 );
	$out['stored_after_add'] = get_option( 'zdz_signin_managers' );
	update_option( 'zdz_signin_managers', array() );   // nobody named on the page: the line alone
	$out['alone']      = $r::managers();
	$out['ever_named'] = $r::ever_named();
	$out['button']     = $r::may_claim( 1 );
	zdz_test_signin_as( 2 );
	$out['admin_edits'] = $r::may_edit_addresses( 2 ) && ! $r::may_manage( 2 );
	zdz_test_signin_as( 1 );
	$out['page'] = zdz_test_signin_page();
	echo json_encode( $out );
	exit( 0 );
}

$pass = 0;
$fail = 0;
function ok( bool $c, string $m ): void {
	global $pass, $fail;
	if ( $c ) {
		$pass++;
		echo "  ok   $m\n";
	} else {
		$fail++;
		echo "  FAIL $m\n";
	}
}
function fresh(): void {
	zdz_test_signin_reset();
	zdz_test_signin_people();
	unset( $GLOBALS['zdz_test_signin_network'] );
}
function who( $email, $code ): int {
	$c = ZDZ_Signin_Routes::claim( $email, $code );
	return $c ? (int) $c['user']->ID : 0;
}
$r = 'ZDZ_Signin_Routes';

echo "\nDemo Mode\n";
fresh();
zdz_test_signin_as( 1 );
ok( zdz_test_signin_demo( true ), 'the Demo Mode class is loaded here' );
ok( ! $r::may_claim( 1 ) && ! $r::claim_management( 1 ) && ! $r::may_open( 1 ), 'an administrator in Demo Mode cannot take the page' );
ok( array() === $r::managers() && ! $r::ever_named(), '... and nobody was named' );
ok( ! $r::may_edit_addresses( 1 ) && null === $r::save_addresses( 1, 12, 'jordan.home@example.net' ), 'nor, before anyone manages, change an address' );
ok( false !== strpos( zdz_test_signin_page(), 'Only the person who manages sign-in routes' ) && false === strpos( zdz_test_signin_page(), '<form' ), 'the page shows them the refusal and no button' );

zdz_test_signin_as( 1 );
ok( $r::claim_management( 1 ) && $r::set_issuer( 1, 2 ), 'as themselves they take it, and name the office administrator the second issuer' );
$given = $r::issue( 1, 11 );
zdz_test_signin_demo( true );
ok( ! $r::may_manage( 1 ), 'a manager in Demo Mode manages nothing' );
ok( is_wp_error( $r::issue( 1, 12 ) ) && is_wp_error( $r::issue( 1, 11, true ) ), '... gives no code' );
ok( ! $r::cancel_issued( 1, 11 ) && $r::issued_left( 11 ) > 0, '... takes none back' );
ok( null === $r::save_addresses( 1, 12, 'jordan.home@example.net' ) && array() === $r::addresses( 12 ), '... changes no address' );
ok( ! $r::add_manager( 1, 2 ) && ! $r::set_issuer( 1, 0 ) && null !== get_option( 'zdz_signin_issuer', null ), '... and names nobody' );
ok( false === strpos( zdz_test_signin_page(), '<table' ), 'the page lists nobody for them' );
zdz_test_signin_as( 2 );
zdz_test_signin_demo( true );
ok( ! $r::is_issuer( 2 ) && is_wp_error( $r::issue( 2, 12 ) ) && ! $r::may_open( 2 ), 'a second issuer in Demo Mode gives nothing' );
zdz_test_signin_as( 9 );
ok( ! $r::may_open( 9 ) && is_wp_error( $r::issue( 9, 12 ) ) && null === $r::save_addresses( 9, 12, '' ), 'and the shared device itself, which is who a device in Demo Mode runs as, does none of it' );
zdz_test_signin_as( 1 );
ok( 11 === who( 'alex@example.com', (string) $given ), 'the code given before is untouched' );

echo "\nThe shared-device trait on another role\n";
fresh();
zdz_test_signin_user( 30, 'front@example.com', 'Front Desk', array( 'zdz_admin' ), array( 'zdz_is_shared_device' ) );
update_user_meta( 30, 'zdz_login_aliases', array( 'front.old@example.net' ) );
zdz_test_signin_as( 30 );
ok( ! $r::gets_routes( 30 ) && ! $r::eligible_manager( 30 ), 'it gets no route, administrator role or not' );
ok( ! $r::may_claim( 30 ) && ! $r::claim_management( 30 ) && ! $r::may_open( 30 ), 'it cannot take the page' );
ok( ! $r::may_edit_addresses( 30 ) && null === $r::save_addresses( 30, 12, 'jordan.home@example.net' ), 'nor change an address before anyone manages' );
zdz_test_signin_as( 1 );
$r::claim_management( 1 );
ok( ! $r::may_issue_for( 1, 30 ) && is_wp_error( $r::issue( 1, 30 ) ) && ! $r::put_code( 30, '12345678', 'issued', 600 ), 'nobody gives it a code' );
ok( ! $r::set_issuer( 1, 30 ) && ! $r::add_manager( 1, 30 ), 'it is not named issuer or manager' );
ok( null === $r::save_addresses( 1, 30, 'front.new@example.net' ) && array() === $r::save_addresses( 1, 30, '' ) && array() === $r::addresses( 30 ), 'an address it already had can be removed, never replaced' );
ok( 1 === preg_match( '~<tr data-uid="30">(.*?)</tr>~s', zdz_test_signin_page(), $m ) && false !== strpos( $m[1], 'The shared device: no backup routes.' ) && false === strpos( $m[1], 'Give a' ), "its row on the manager's page says so and offers no code" );
ok( array() === array_filter( $GLOBALS['wpdb']->notices(), function ( $n ) { return 30 === $n['to']; } ), 'and it was told nothing when the page got its manager' );
ok( $r::put_code( 30, '246810', 'mail', 300, array( 'to' => 'front@example.com' ) ) && 30 === who( 'front@example.com', '246810' ), 'it still signs in with a code emailed to its own address' );

echo "\nRead afresh under the account's lock\n";
// WordPress loads a user's meta into the request's cache when the user is looked up, which is before the lock is
// held: so the copy is dropped once it is, and what is weighed is what the last request really left.
fresh();
$drops = function () {
	return count( array_keys( (array) $GLOBALS['zdz_test_signin_cache_drops'], 'user_meta:12', true ) );
};
ok( zdz_test_signin_has( 'wp_cache_delete' ) && 0 === $drops(), 'this process counts the drops itself' );
$r::put_code( 12, '135790', 'mail', 300, array( 'to' => 'jordan@example.com' ) );
ok( 1 === $drops(), "keeping a code drops the account's cached meta once the lock is held" );
ok( 0 === who( 'jordan@example.com', '135791' ) && 2 === $drops(), '... so does a wrong try' );
ok( $r::may_send( 12, 'jordan@example.com' ) && 3 === $drops(), '... and counting a send' );
ok( 12 === who( 'jordan@example.com', '135790' ) && 4 === $drops(), '... and the claim that signs them in' );
$GLOBALS['wpdb']->held = array( zdz_test_signin_lock( 12 ) );
ok( ! $r::put_code( 12, '135792', 'mail', 300, array( 'to' => 'jordan@example.com' ) ) && 4 === $drops(), 'while another request holds the lock nothing is dropped, and nothing is kept' );
$GLOBALS['wpdb']->held = array();
ok( array() === $GLOBALS['wpdb']->open_locks(), 'every lock was given back' );

echo "\nA WordPress network\n";
fresh();
ok( ! is_multisite(), 'a single site until a case says otherwise' );
zdz_test_signin_as( 2 );
ok( $r::eligible_manager( 2 ) && $r::may_claim( 2 ) && $r::may_edit_addresses( 2 ), 'control: on a single site the office administrator could take the page and set an address' );
$GLOBALS['zdz_test_signin_network'] = array( 1 );   // a network; Morgan is its only network administrator
ok( is_multisite() && is_super_admin( 1 ) && ! is_super_admin( 2 ), 'now a network, with one network administrator' );
ok( ! $r::eligible_manager( 2 ) && ! $r::may_claim( 2 ) && ! $r::claim_management( 2 ) && ! $r::may_open( 2 ), "one site's administrator cannot take the page" );
ok( ! $r::may_edit_addresses( 2 ) && null === $r::save_addresses( 2, 12, 'jordan.takeover@example.net' ) && array() === $r::addresses( 12 ), '... nor set a backup address before anyone manages' );
ok( ! $r::ever_named() && array() === $r::managers(), '... and nobody was named' );
$GLOBALS['zdz_test_signin_pages'] = array();
$r::add_page();
ok( array() === $GLOBALS['zdz_test_signin_pages'], '... and has no page' );

zdz_test_signin_as( 1 );
$page = zdz_test_signin_page();
ok( false !== strpos( $page, 'I manage sign-in routes' ) && false !== strpos( $page, 'only a network administrator can manage sign-in routes' ) && false === strpos( $page, 'any administrator can still edit' ), "the network administrator's first page says who can manage here" );
ok( $r::may_claim( 1 ) && $r::claim_management( 1 ) && array( 1 ) === $r::managers(), 'the network administrator takes it' );
ok( ! $r::add_manager( 1, 2 ) && array( 1 ) === $r::managers(), "... and cannot add one site's administrator beside them" );
$given = $r::issue( 1, 11 );
ok( is_string( $given ) && 11 === who( 'alex@example.com', $given ), '... gives a code, and it works' );
ok( array() === $r::save_addresses( 1, 12, 'jordan.home@example.net' ) && array( 'jordan.home@example.net' ) === $r::addresses( 12 ), '... and sets a backup address' );
ok( ! $r::set_issuer( 1, 11 ) && ! $r::set_issuer( 1, 11, 'anyone' ) && null === $r::issuer(), 'there is no second issuer to name' );
update_option( 'zdz_signin_issuer', array( 'uid' => 11, 'scope' => 'anyone' ) );   // one named before the site joined a network
ok( null === $r::issuer(), 'one named earlier is no issuer here' );
zdz_test_signin_as( 11 );
ok( ! $r::is_issuer( 11 ) && ! $r::may_open( 11 ) && is_wp_error( $r::issue( 11, 12 ) ) && ! $r::may_issue_for( 11, 12 ), '... and gives nothing' );
zdz_test_signin_as( 1 );
$page = zdz_test_signin_page();
ok( false !== strpos( $page, 'only network administrators give one-time codes' ) && false === strpos( $page, 'zdz_signin_scope' ), "the manager's page says so and offers no choice" );
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST                     = array( 'zdz_signin_do' => 'issuer', 'zdz_signin_uid' => 13, '_wpnonce' => zdz_test_signin_nonce( 'zdz_signin_routes' ) );
$page                      = html_entity_decode( zdz_test_signin_page(), ENT_QUOTES );
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST                     = array();
ok( false !== strpos( $page, "That account can't be the second issuer." ) && null === $r::issuer(), 'and a post that names one is refused' );

update_option( 'zdz_signin_managers', array( 2 ) );   // a site whose manager was named before it joined the network
ok( array() === $r::managers() && $r::ever_named(), "one site's administrator named earlier manages nothing here" );
zdz_test_signin_as( 2 );
ok( ! $r::may_manage( 2 ) && ! $r::may_edit_addresses( 2 ) && is_wp_error( $r::issue( 2, 12 ) ) && ! $r::shows_way_back( 2 ), '... gives no code, sets no address, and is not handed the line' );
zdz_test_signin_as( 1 );
$page = zdz_test_signin_page();
ok( $r::shows_way_back( 1 ) && ! $r::may_claim( 1 ) && false !== strpos( html_entity_decode( $page, ENT_QUOTES ), "define( 'ZDZ_SIGNIN_MANAGER', 1 );" ) && false === strpos( $page, '<form' ), 'the network administrator is shown the way back: the wp-config.php line' );
unset( $GLOBALS['zdz_test_signin_network'] );
zdz_test_signin_as( 2 );
ok( array( 2 ) === $r::managers() && $r::may_manage( 2 ), 'control: on a single site that same list is a manager' );

echo "\nThe wp-config.php line\n";
/** One case of the line, run in its own process: what that process found, or null. */
function pin_case( int $id ) {
	$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' pin ' . (int) $id;
	return json_decode( (string) shell_exec( $cmd . ' 2>&1' ), true ); // sentinel:allow every argument is escapeshellarg()'d or an integer; runs this test script only
}
ok( 0 === $r::pinned_manager(), 'with no line there is no pinned manager' );
$p = pin_case( 2 );
ok( is_array( $p ) && array( 1, 2 ) === $p['managers'] && true === $p['two_manages'], 'the line naming account 2 makes it a manager beside the one the page holds' );
ok( is_array( $p ) && false === $p['removed'], '... who cannot be removed on the page' );
ok( is_array( $p ) && array( 1, 4 ) === $p['stored_after_add'], '... and is never written into what the page holds (taking the line out takes them out)' );
ok( is_array( $p ) && array( 2 ) === $p['alone'] && true === $p['ever_named'] && false === $p['button'], 'with nobody named on the page: the line alone, and the button stays closed' );
ok( is_array( $p ) && false !== strpos( (string) $p['page'], 'Only the person who manages sign-in routes' ) && false === strpos( (string) $p['page'], 'ZDZ_SIGNIN_MANAGER' ), 'another administrator is shown the refusal, with no line to add: there is a manager' );
foreach ( array( 11 => 'a salesperson', 9 => 'the shared device', 3 => 'an account in Privacy View', 999 => 'nobody at all' ) as $id => $whom ) {
	$p = pin_case( $id );
	ok( is_array( $p ) && array( 1 ) === $p['managers'], "the line naming $whom adds nobody to the page's own manager" );
	ok( is_array( $p ) && array() === $p['alone'] && false === $p['button'], '... alone it names no manager, and the button stays closed' );
	ok( is_array( $p ) && false === $p['admin_edits'], '... addresses do not fall back to any administrator' );
	ok(
		is_array( $p ) && false !== strpos( (string) $p['page'], 'ZDZ_SIGNIN_MANAGER' ) && false !== strpos( (string) $p['page'], 'account ' . $id )
			&& false === strpos( (string) $p['page'], 'can no longer' ) && false === strpos( (string) $p['page'], '<form' ),
		'... and the page says the line is wrong'
	);
}

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
