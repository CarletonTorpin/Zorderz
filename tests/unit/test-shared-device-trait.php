<?php
/**
 * The shared device is recognised by the platform's trait (ZDZ_User_Roles::is_shared_device(): the
 * zdz_is_shared_device capability, whichever role carries it, or the shared-device role), never by the role slug
 * alone. An account whose role an administrator relabelled or swapped, but that still carries the trait, stays the
 * shared device everywhere:
 *
 *   - ZDZ_Hierarchy::is_kiosk (and with it every app check that asks it), ZDZ_Capabilities::tier_of;
 *   - ZDZ_Data_Permissions: the shared-device profile, never the profile of the role it holds;
 *   - "Ask chat about this": never the business-wide customer list, nor on a device in Demo Mode;
 *   - Messages, Email and Scheduler: read-only (Email: no access at all), whatever the read-only role lists say;
 *   - Leads: never a salesperson; Knowledge: never a party or a recipient.
 *
 * Standalone (its own PHP process): it loads the role, permission and Demo Mode classes, which the in-process unit
 * tests run without. Run: php tests/unit/test-shared-device-trait.php
 *
 * Neutral data only.
 */

error_reporting( E_ALL & ~E_DEPRECATED );
require __DIR__ . '/../bootstrap-unit.php';
require __DIR__ . '/messaging-test-doubles.php';
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) { return user_can( get_current_user_id(), $cap ); }
}
$root = dirname( __DIR__, 2 );
require $root . '/zorderz/inc/class-zdz-user-roles.php';
require_once $root . '/zorderz/inc/class-zdz-hierarchy.php';
require_once $root . '/zorderz/inc/class-zdz-capabilities.php';
require $root . '/zorderz/inc/class-zdz-data-permissions.php';
require $root . '/zorderz/inc/class-zdz-ask-context.php';
require $root . '/zorderz/inc/class-zdz-kiosk-demo.php';
require $root . '/zorderz-apps/apps/inbox/app.php';
require $root . '/zorderz-apps/apps/scheduler/app.php';
require $root . '/zorderz-apps/apps/leads/includes/class-zl-lead-assignment.php';
require $root . '/zorderz-apps/apps/knowledge/includes/class-zkv-acl.php';

const RELABELLED = 41;   // holds a field role, and the shared-device trait
const PERSON     = 42;   // the same field role, no trait
const SHARED     = 43;   // the shared-device role
const OWNER_DEV  = 44;   // an owner-level role carrying the trait
const OWNER      = 45;

$pass = 0; $fail = 0;
function ok( bool $c, string $m ): void {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m\n"; }
}
function demo( bool $on ): void {
	$r = new ReflectionProperty( 'ZDZ_Kiosk_Demo', 'switched' );
	$r->setAccessible( true );
	$r->setValue( null, $on );
}
function fresh(): void {
	zdz_test_msg_reset();
	$GLOBALS['zdz_test_user_roles'] = array(
		RELABELLED => array( 'zdz_tech' ),
		PERSON     => array( 'zdz_tech' ),
		SHARED     => array( 'zdz_general' ),
		OWNER_DEV  => array( 'zdz_owner' ),
		OWNER      => array( 'zdz_owner' ),
	);
	$GLOBALS['zdz_test_user_caps'] = array(
		RELABELLED => array( 'zdz_access_app', ZDZ_User_Roles::SHARED_DEVICE_CAP ),
		PERSON     => array( 'zdz_access_app' ),
		SHARED     => array( 'zdz_access_app', ZDZ_User_Roles::SHARED_DEVICE_CAP ),
		OWNER_DEV  => array( 'zdz_access_app', ZDZ_User_Roles::SHARED_DEVICE_CAP ),
		OWNER      => array( 'zdz_access_app' ),
	);
	$r = new ReflectionProperty( 'ZDZ_Data_Permissions', 'resolved_cache' );
	$r->setAccessible( true );
	$r->setValue( null, array() );
	demo( false );
}

// ── The hierarchy and the tier ─────────────────────────────────────────────
fresh();
ok( ZDZ_Hierarchy::is_kiosk( RELABELLED ), 'the hierarchy reads the trait, whatever the role' );
ok( ZDZ_Hierarchy::is_kiosk( SHARED ), 'the shared-device role' );
ok( ! ZDZ_Hierarchy::is_kiosk( PERSON ), 'a person is not the shared device' );
ok( ! ZDZ_Hierarchy::is_kiosk( 0 ), 'nobody is not the shared device' );
ok( 'kiosk' === ZDZ_Capabilities::tier_of( RELABELLED ), 'the kiosk tier for the trait' );
ok( 'kiosk' === ZDZ_Capabilities::tier_of( OWNER_DEV ), 'the kiosk tier even on an owner-level role' );
ok( 'kiosk' !== ZDZ_Capabilities::tier_of( PERSON ), 'not for a person' );

// ── Data permissions ───────────────────────────────────────────────────────
fresh();
$shared = ZDZ_Data_Permissions::resolve( SHARED );
ok( $shared === ZDZ_Data_Permissions::resolve( RELABELLED ), 'the shared-device profile for the trait on a field role' );
ok( $shared === ZDZ_Data_Permissions::resolve( OWNER_DEV ), 'and never the owner profile' );
ok( 'allow' === ZDZ_Data_Permissions::resolve( OWNER )['view_company_revenue'], 'an owner still sees revenue' );
ok( 'deny' === ZDZ_Data_Permissions::resolve( OWNER_DEV )['view_company_revenue'], 'the shared device does not' );

// ── "Ask chat about this" ──────────────────────────────────────────────────
fresh();
ok( ZDZ_Ask_Context::viewer_sees_all( OWNER ), 'an owner may have the business-wide list consulted' );
ok( ! ZDZ_Ask_Context::viewer_sees_all( OWNER_DEV ), 'never the shared device on an owner-level role' );
$GLOBALS['zdz_test_current_user'] = OWNER;
demo( true );
ok( ! ZDZ_Ask_Context::viewer_sees_all(), 'never a device in Demo Mode' );
demo( false );
ok( ZDZ_Ask_Context::viewer_sees_all(), 'back out of Demo Mode' );

// ── Messages, Email and Scheduler ──────────────────────────────────────────
fresh();
foreach ( array( 'zim_read_only_roles', 'zib_read_only_roles', 'zsch_read_only_roles' ) as $hook ) {
	$GLOBALS['zdz_test_filters'][ $hook ] = function () { return array(); };   // a filter cannot lift it
}
foreach ( array( 'zim_user_is_read_only', 'zib_user_is_read_only', 'zsch_user_is_read_only' ) as $fn ) {
	ok( $fn( RELABELLED ), "$fn: the trait is read-only" );
	ok( $fn( SHARED ), "$fn: the shared-device role is read-only" );
	ok( ! $fn( PERSON ), "$fn: a person is not" );
}
ok( ! zib_user_has_access( RELABELLED ), 'no mailbox for the shared device' );
ok( zib_user_has_access( PERSON ), 'a person keeps theirs' );

// ── Leads and Knowledge ────────────────────────────────────────────────────
fresh();
ok( ZL_Lead_Assignment::is_kiosk( RELABELLED ), 'Leads: the trait is never a salesperson' );
ok( ZL_Lead_Assignment::is_kiosk( SHARED ), 'Leads: the shared-device role' );
ok( ! ZL_Lead_Assignment::is_kiosk( PERSON ), 'Leads: a person' );
$GLOBALS['zdz_test_current_user'] = PERSON;
demo( true );
ok( ZL_Lead_Assignment::is_kiosk(), 'Leads: a device in Demo Mode' );
demo( false );
ok( ZKV_ACL::is_shared_device( RELABELLED ), 'Knowledge: the trait' );
ok( ZKV_ACL::is_shared_device( (object) array( 'ID' => RELABELLED, 'roles' => array( 'zdz_tech' ) ) ), 'Knowledge: a user object' );
ok( ZKV_ACL::is_shared_device( SHARED ), 'Knowledge: the shared-device role' );
ok( ! ZKV_ACL::is_shared_device( PERSON ), 'Knowledge: a person' );
ok( ! ZKV_ACL::is_shared_device( 0 ), 'Knowledge: nobody' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
