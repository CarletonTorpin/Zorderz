<?php
/**
 * Surveys: who may see survey customers, who may ask for a second e-mail, and who may change how
 * the Google review check works, with the platform's real role and permission classes.
 *
 *   - the survey list, the sends and the Google reviews panel all ask one question
 *     (ZSV_Dashboard::can_see): an administrator, or somebody the data permissions let see other
 *     people's customers; never the shared device, whatever role it holds;
 *   - "send again" counts only from an administrator (ZSV_Dashboard::asked_again);
 *   - switching automatic recording and "check now" are an administrator's
 *     (ZSV_Google_Reviews::refusal with $admin);
 *   - "Check reviews" is drawn beside the sync button only for the people the site lets run it,
 *     and the widget is never drawn on the shared device.
 *
 * Standalone (its own PHP process): it loads the role and permission classes, which the in-process
 * unit tests run without. Run: php tests/unit/test-surveys-who-may.php
 *
 * Neutral data only.
 */

error_reporting( E_ALL & ~E_DEPRECATED );
require __DIR__ . '/../bootstrap-unit.php';

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
define( 'ZSV_NONCE', 'zsv_nonce' );
define( 'ZSV_VERSION', '0' );
define( 'ZSV_URL', 'https://app.example.com/apps/surveys/' );
if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $uid, $key, $single = false ) { return $GLOBALS['zdz_test_user_meta'][ (int) $uid ][ $key ] ?? ''; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ); }
}
function admin_url( $p = '' ) { return 'https://app.example.com/wp-admin/' . $p; }
function esc_url( $s ) { return (string) $s; }
function esc_html_e( $s, $d = null ) { echo htmlspecialchars( (string) $s, ENT_QUOTES ); }
function wp_enqueue_style( ...$a ) { $GLOBALS['zsv_styles'][] = (string) $a[0]; }
function wp_enqueue_script( ...$a ) { $GLOBALS['zsv_enqueued'][] = (string) $a[0]; $GLOBALS['zsv_needs'][ (string) $a[0] ] = (array) ( $a[2] ?? array() ); }
function wp_localize_script( ...$a ) { return true; }
function wp_create_nonce( $a = -1 ) { return 'nonce'; }
function zsv_asset_ver( string $rel ): string { return '0'; }

$root = dirname( __DIR__, 2 );
require $root . '/zorderz/inc/class-zdz-user-roles.php';
require_once $root . '/zorderz/inc/class-zdz-hierarchy.php';
require $root . '/zorderz/inc/class-zdz-data-permissions.php';
require $root . '/zorderz/inc/interface-zdz-app.php';
$inc = $root . '/zorderz-apps/apps/surveys/includes/';
foreach ( array( 'db', 'settings', 'budget', 'owed', 'note-reader', 'send-once', 'survey-manager', 'dashboard', 'google-reviews', 'admin', 'app' ) as $f ) {
	require $inc . 'class-zsv-' . $f . '.php';
}

const ADMIN      = 51;   // an administrator
const OPERATOR   = 52;   // may see other people's customers; not an administrator
const SALES      = 53;   // may not see other people's customers
const SHARED     = 54;   // the shared-device role
const ADMIN_DEV  = 55;   // an administrator's account that carries the shared-device trait
const ALLOWED    = 56;   // a salesperson an administrator allowed, one by one
const DENIED     = 57;   // an operator an administrator switched off
const STRANGER   = 58;   // a role the platform does not know

$pass = 0; $fail = 0;
function ok( bool $c, string $m ): void {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m\n"; }
}
function fresh(): void {
	$GLOBALS['zdz_test_user_roles'] = array(
		ADMIN     => array( 'administrator' ),
		OPERATOR  => array( 'zdz_operator' ),
		SALES     => array( 'zdz_sales' ),
		SHARED    => array( ZDZ_User_Roles::SHARED_DEVICE_ROLE ),
		ADMIN_DEV => array( 'administrator' ),
		ALLOWED   => array( 'zdz_sales' ),
		DENIED    => array( 'zdz_operator' ),
		STRANGER  => array( 'subscriber' ),
	);
	$GLOBALS['zdz_test_user_caps'] = array(
		SHARED    => array( ZDZ_User_Roles::SHARED_DEVICE_CAP ),
		ADMIN_DEV => array( ZDZ_User_Roles::SHARED_DEVICE_CAP ),
	);
	$GLOBALS['zdz_test_user_meta'] = array(
		ALLOWED => array( 'zdz_data_permissions' => array( 'view_others_data' => 'allow' ) ),
		DENIED  => array( 'zdz_data_permissions' => array( 'view_others_data' => 'deny' ) ),
	);
	$r = new ReflectionProperty( 'ZDZ_Data_Permissions', 'resolved_cache' );
	$r->setAccessible( true );
	$r->setValue( null, array() );
}

// ── who may see survey customers at all ────────────────────────────────────
fresh();
ok( ZSV_Dashboard::can_see( ADMIN ), 'an administrator' );
ok( ZSV_Dashboard::can_see( OPERATOR ), 'somebody the data permissions let see other people\'s customers' );
ok( ! ZSV_Dashboard::can_see( SALES ), 'not somebody who sees their own customers only' );
ok( ZSV_Dashboard::can_see( ALLOWED ), 'somebody an administrator allowed, one by one' );
ok( ! ZSV_Dashboard::can_see( DENIED ), 'not somebody an administrator switched off' );
ok( ! ZSV_Dashboard::can_see( STRANGER ), 'not a role the platform does not know' );
ok( ! ZSV_Dashboard::can_see( SHARED ), 'never the shared device' );
ok( ! ZSV_Dashboard::can_see( ADMIN_DEV ), 'never the shared device, even on an administrator\'s account' );
ok( ! ZSV_Dashboard::can_see( 0 ), 'nobody signed in' );

// ── the Google reviews panel ───────────────────────────────────────────────
fresh();
foreach ( array( ADMIN, OPERATOR, ALLOWED ) as $u ) {
	ok( '' === ZSV_Google_Reviews::refusal( $u ), "the panel, Confirm and Not them: user $u may" );
}
foreach ( array( SALES, DENIED, STRANGER, SHARED, ADMIN_DEV, 0 ) as $u ) {
	ok( 'Survey data access restricted.' === ZSV_Google_Reviews::refusal( $u ), "the panel names other people's customers: user $u is refused" );
}
ok( '' === ZSV_Google_Reviews::refusal( ADMIN, true ), 'an administrator switches automatic recording and checks now' );
foreach ( array( OPERATOR, ALLOWED, SALES, DENIED, STRANGER, SHARED, ADMIN_DEV, 0 ) as $u ) {
	ok( 'Unauthorized' === ZSV_Google_Reviews::refusal( $u, true ), "the mode and check now are an administrator's: user $u is refused" );
}
ok( true === ZSV_Google_Reviews::list_payload( ADMIN )['can_admin'], 'the panel is told an administrator may switch the mode' );
ok( false === ZSV_Google_Reviews::list_payload( OPERATOR )['can_admin'], 'and that anybody else may not' );

// ── "send again" ───────────────────────────────────────────────────────────
fresh();
ok( array( 7 ) === ZSV_Dashboard::asked_again( array( 7 ), '7', ADMIN ), 'an administrator may ask for a second e-mail' );
foreach ( array( OPERATOR, ALLOWED, SALES, STRANGER, SHARED, ADMIN_DEV, 0 ) as $u ) {
	ok( array() === ZSV_Dashboard::asked_again( array( 7 ), '7', $u ), "send again from user $u means nothing" );
}
ok( array() === ZSV_Dashboard::asked_again( array( 7 ), '8', ADMIN ), 'a customer the request does not name is not sent again' );
ok( array() === ZSV_Dashboard::asked_again( array( 7 ), '', ADMIN ), 'no send_again, no second e-mail' );
ok( array() === ZSV_Dashboard::asked_again( array( 7 ), null, ADMIN ), 'none at all' );

// ── the widget ─────────────────────────────────────────────────────────────
fresh();
ok( ZSV_App::check_beside( ADMIN ), 'Check reviews beside the sync button for an administrator' );
foreach ( array( OPERATOR, SALES, SHARED, STRANGER, 0 ) as $u ) {
	ok( ! ZSV_App::check_beside( $u ), "under More tools for user $u" );
}
$GLOBALS['zdz_test_current_user'] = ADMIN;
ok( ! ZSV_App::check_beside( OPERATOR ), 'the person the widget is drawn for decides, not whoever is signed in' );
$app = new ZSV_App();
ok( null === $app->render_dashboard_widget( SHARED ), 'the widget is never drawn on the shared device' );
ok( null === $app->render_dashboard_widget( ADMIN_DEV ), 'whatever role the device holds' );
ok( in_array( 'zsv-widget-logic', $GLOBALS['zsv_enqueued'] ?? array(), true ) === false, 'nothing is loaded for a widget that is not drawn' );
function at_of( string $h, string $needle ): int {
	$p = strpos( $h, $needle );
	return false === $p ? -1 : $p;
}

// For an administrator: the third button of the top row.
$h = (string) $app->render_dashboard_widget( ADMIN );
ok( false !== strpos( $h, 'class="zsv-w-actions zsv-w-actions-trio"' ), 'an administrator: the top row is marked as three buttons' );
ok( at_of( $h, 'id="zsv-run-batch"' ) < at_of( $h, '<div class="zsv-w-actions-pair">' ) && at_of( $h, '<div class="zsv-w-actions-pair">' ) < at_of( $h, 'id="zsv-sync"' ), 'the sync button and Check reviews share one wrapper, after Run batch' );
ok( at_of( $h, 'id="zsv-sync"' ) < at_of( $h, 'id="zsv-check-reviews"' ) && at_of( $h, 'id="zsv-check-reviews"' ) < at_of( $h, 'id="zsv-progress"' ), 'Check reviews comes right after the sync button, before the progress line' );
ok( 1 === substr_count( $h, 'id="zsv-check-reviews"' ) && 1 === substr_count( $h, 'id="zsv-review-status"' ), 'one Check reviews and one result region' );
ok( at_of( $h, 'id="zsv-progress"' ) < at_of( $h, 'id="zsv-review-status"' ) && at_of( $h, 'id="zsv-review-status"' ) < at_of( $h, 'id="zsv-list"' ), 'the result sits under the buttons, above the list' );
ok( false === strpos( $h, 'zsv-more' ), 'no empty More tools is left behind' );
ok( 1 === preg_match( '/<div class="zsv-review-status" id="zsv-review-status" role="status" aria-live="polite" hidden>/', $h ), 'the result region is a status region, hidden until a check ran' );
ok( 1 === preg_match( '/<div class="zsv-greviews" id="zsv-greviews" hidden><\/div>/', $h ), 'the Google reviews panel starts hidden and empty' );
ok( substr_count( $h, '<div' ) === substr_count( $h, '</div>' ), 'the markup is balanced' );
ok( 3 === substr_count( substr( $h, at_of( $h, 'zsv-w-actions' ), at_of( $h, 'id="zsv-progress"' ) - at_of( $h, 'zsv-w-actions' ) ), '<button' ), 'three buttons in the top row' );

// For anybody else: the two buttons, and Check reviews under More tools, as before.
$h = (string) $app->render_dashboard_widget( OPERATOR );
ok( false !== strpos( $h, 'id="zsv-widget"' ) && false === strpos( $h, 'zsv-w-actions-trio' ) && false !== strpos( $h, '<div class="zsv-w-actions">' ), 'an operator: the top row is the two buttons' );
ok( false === strpos( $h, 'zsv-w-actions-pair' ), 'no pair wrapper' );
ok( false === strpos( substr( $h, at_of( $h, 'id="zsv-sync"' ), at_of( $h, 'id="zsv-progress"' ) - at_of( $h, 'id="zsv-sync"' ) ), 'zsv-check-reviews' ), 'nothing sits between the sync button and the progress line' );
ok( 1 === substr_count( $h, 'id="zsv-check-reviews"' ) && at_of( $h, '<details class="zsv-more">' ) < at_of( $h, 'id="zsv-check-reviews"' ) && at_of( $h, 'id="zsv-check-reviews"' ) < at_of( $h, '</details>' ), 'Check reviews is drawn once, under More tools' );
ok( 1 === substr_count( $h, 'id="zsv-review-status"' ) && at_of( $h, 'id="zsv-check-reviews"' ) < at_of( $h, 'id="zsv-review-status"' ) && at_of( $h, 'id="zsv-review-status"' ) < at_of( $h, '</details>' ), 'and so is its result' );
ok( substr_count( $h, '<div' ) === substr_count( $h, '</div>' ), 'the markup is balanced' );
ok( 1 === substr_count( $h, 'id="zsv-greviews"' ), 'the Google reviews panel has its place for everybody the widget is drawn for' );
ok( ZSV_App::markup( true ) === (string) $app->render_dashboard_widget( ADMIN ) && ZSV_App::markup( false ) === $h, 'the two layouts are the only two' );
ok( array( 'zsv-widget-logic', 'zsv-widget' ) === array_slice( $GLOBALS['zsv_enqueued'], 0, 2 ) && array( 'zsv-widget-logic' ) === $GLOBALS['zsv_needs']['zsv-widget'], 'the widget\'s decisions are loaded before the widget, which says it needs them' );

// The check itself still asks for an administrator, whatever the page draws.
$src = (string) file_get_contents( $inc . 'class-zsv-review-checker.php' );
ok( 1 === preg_match( "/public static function ajax_check\\(\\): void \\{\\s+check_ajax_referer\\( ZSV_NONCE, 'nonce' \\);\\s+if \\( ! current_user_can\\( 'manage_options' \\) \\) \\{\\s+wp_send_json_error\\( 'Unauthorized' \\);/", $src ), 'the check asks for the nonce and then for an administrator, and nothing else' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
