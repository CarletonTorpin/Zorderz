<?php
/**
 * No-WordPress harness for ZDZ_Report_Spec + ZDZ_Report_Sources (C-15).
 * Run:  php -d zend.assertions=1 harness-report-sources.php
 *
 * Proves the security boundary:
 *   (1) a hostile spec (unknown source / un-allow-listed entity / un-allow-listed filter /
 *       future-only / too-wide window) is REFUSED — a refusal, not a query; NOTHING is gathered.
 *   (2) a customer-audience reader never selects an internal-visibility event (both the SQL
 *       visibility set and the per-row PHP guard).
 *   (3) money entitlement is fail-closed: a money source / money axis is refused for an
 *       unentitled viewer BEFORE any gathering.
 *   (4) the validate() boundary cannot be skipped: handing an un-validated spec to the query
 *       builder trips an assertion under zend.assertions=1.
 *   (5) attribution reuses the compensation contract; an unresolvable dot lands on the explicit
 *       Unattributed row (never hidden); a missing override resolver logs a disposition.
 *   (6) the reader makes ZERO network calls; the UTC->tenant-tz day-bucket has no off-by-one.
 */

error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );

/* ── minimal hook registry ─────────────────────────────────────────── */
$GLOBALS['__hooks'] = array();
function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['__hooks'][ $h ][] = $cb; return true; }
function has_filter( $h, $cb = false ) { return ! empty( $GLOBALS['__hooks'][ $h ] ); }
function apply_filters( $h, $value = null ) {
	$args = array_slice( func_get_args(), 1 );
	if ( ! empty( $GLOBALS['__hooks'][ $h ] ) ) {
		foreach ( $GLOBALS['__hooks'][ $h ] as $cb ) { $args[0] = call_user_func_array( $cb, $args ); }
	}
	return $args[0];
}
$GLOBALS['__disp'] = array();
function do_action( $h ) {
	if ( 'zdz_disposition' === $h ) { $GLOBALS['__disp'][] = array_slice( func_get_args(), 1 ); }
}
function __( $s, $d = null ) { return $s; }
function wp_json_encode( $v ) { return json_encode( $v ); }

/* ── user / caps ───────────────────────────────────────────────────── */
$GLOBALS['__caps']         = array();
$GLOBALS['__current_user'] = 0;
function get_current_user_id() { return (int) $GLOBALS['__current_user']; }
function user_can( $uid, $cap ) { return ! empty( $GLOBALS['__caps'][ $uid ][ $cap ] ); }

/* ── network tripwire (must never fire on the read path) ───────────── */
$GLOBALS['__net'] = 0;
function wp_remote_post() { $GLOBALS['__net']++; return array(); }
function wp_remote_get() { $GLOBALS['__net']++; return array(); }
function wp_remote_request() { $GLOBALS['__net']++; return array(); }

/* ── WP_Error ──────────────────────────────────────────────────────── */
class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }

/* ── Core dependency stubs ─────────────────────────────────────────── */
class ZDZ_Data_Permissions {
	public static $grant = array(); // uid => bool for view_company_revenue
	public static function can( $uid, $perm ) { return ! empty( self::$grant[ $uid ] ); }
}
class ZDZ_Compensation {
	public static function attribution() {
		return array(
			'precedence'          => array( 'document_code', 'override_row', 'inference' ),
			'inferred_is_payable' => false,
			'code_format'         => '/^[A-Z]{2,4}$/',
			'reserved_tokens'     => array( 'WEB' ),
		);
	}
	public static function plan_by_code( $code ) {
		$code = strtoupper( trim( (string) $code ) );
		$map  = array( 'AB' => array( 'party_id' => 11, 'name' => 'Rep-11' ), 'CD' => array( 'party_id' => 22, 'name' => 'Rep-22' ) );
		return $map[ $code ] ?? null;
	}
}
class ZDZ_Hierarchy {
	public static $kiosks = array();
	public static function is_kiosk( $uid ) { return in_array( (int) $uid, self::$kiosks, true ); }
}
class Zdz_Flow_DB {
	public static function outbox() { return 'wp_zdz_flow_outbox'; }
	public static function tenant_id() { return 1; }
}
class ZDZ_Business_Profile {
	public static $tz = 'UTC';
	public static function get( $k ) { return 'locale.timezone' === $k ? self::$tz : ''; }
}

/* ── the REAL shipping classes (theme inc/, four levels up from this tests dir) ── */
$BASE = dirname( __DIR__, 4 ) . '/zorderz/inc/';
require $BASE . 'class-zdz-report-spec.php';
require $BASE . 'class-zdz-report-sources.php';

/* ── injectable reader subclass ────────────────────────────────────── */
class Harness_Sources extends ZDZ_Report_Sources {
	public static $rows        = array();
	public static $query_calls = 0;
	protected static function query_rows( array $args ): array { self::$query_calls++; return self::$rows; }
	public static function expose_build( $clean, $source ) { return static::build_query_args( $clean, $source ); }
}

/* ── register synthetic sources (Core ships EMPTY; a tenant would do this) ─ */
add_filter( 'zdz_report_sources', function ( $s ) {
	$s[] = array(
		'key'              => 'sale_events',
		'label'            => 'Sale events',
		'entities'         => array( 'rep', 'customer' ),
		'family'           => 'sale',
		'stage'            => 'accepted',
		'event_types'      => array( 'sale.accepted.v1' ),
		'visibility'       => 'internal', // ceiling: an internal reader sees all tiers.
		'money_class'      => false,
		'filterable'       => array( 'region' ),
		'amount_path'      => 'data.collected_cents',
		'attribution_path' => 'data.sales_code',
		'entity_paths'     => array( 'customer' => 'data.customer_ref' ),
	);
	$s[] = array(
		'key'         => 'revenue_booked',
		'label'       => 'Revenue booked',
		'entities'    => array( 'rep' ),
		'event_types' => array( 'invoice.paid.v1' ),
		'visibility'  => 'staff',
		'money_class' => true,
		'amount_path' => 'data.collected_cents',
	);
	return $s;
} );

/* ── row builder ───────────────────────────────────────────────────── */
function ev( $vis, $iso, $data, $type = 'sale.accepted.v1' ) {
	static $n = 0;
	$n++;
	// A plausible ULID-shaped id (only ordering matters for the harness reader, which ignores it).
	$id = str_pad( strtoupper( base_convert( (string) ( 1000 + $n ), 10, 32 ) ), 26, '0', STR_PAD_LEFT );
	return array(
		'id'         => $id,
		'event_type' => $type,
		'visibility' => $vis,
		'envelope'   => json_encode( array( 'time' => $iso, 'visibility' => $vis, 'data' => $data ) ),
	);
}

/* ── test scaffolding ──────────────────────────────────────────────── */
$PASS = 0; $FAIL = 0;
function check( $cond, $label ) {
	global $PASS, $FAIL;
	if ( $cond ) { $PASS++; echo "  PASS  $label\n"; }
	else { $FAIL++; echo "  FAIL  $label\n"; }
}
function refused( $r, $code = '' ) {
	if ( ! is_array( $r ) || ! empty( $r['ok'] ) ) { return false; }
	return '' === $code ? true : ( ( $r['error'] ?? '' ) === $code );
}
function reset_query() { Harness_Sources::$query_calls = 0; }

echo "== (1) hostile spec => refusal, nothing gathered ==\n";

$GLOBALS['__current_user'] = 7; $GLOBALS['__caps'][7] = array( 'manage_options' => true );
$win = array( 'from' => '2026-03-01', 'to' => '2026-03-31' );

reset_query();
$r = Harness_Sources::read( array( 'source' => 'nope', 'entity' => 'rep', 'window' => $win, '__viewer' => 7 ) );
check( refused( $r, 'zdz_report_unknown_source' ) && 0 === Harness_Sources::$query_calls, 'unknown source refused, query never ran' );

reset_query();
$r = Harness_Sources::read( array( 'source' => 'sale_events', 'entity' => 'ssn', 'window' => $win, '__viewer' => 7 ) );
check( refused( $r, 'zdz_report_unlisted_entity' ) && 0 === Harness_Sources::$query_calls, 'un-allow-listed entity/group refused, nothing gathered' );

reset_query();
$r = Harness_Sources::read( array( 'source' => 'sale_events', 'entity' => 'rep', 'window' => $win, 'filters' => array( 'ssn' => '123' ), '__viewer' => 7 ) );
check( refused( $r, 'zdz_report_unlisted_filter' ) && 0 === Harness_Sources::$query_calls, 'un-allow-listed filter field refused' );

$r = Harness_Sources::read( array( 'source' => 'sale_events', 'entity' => 'rep', 'window' => array( 'from' => '2099-01-01', 'to' => '2099-01-10' ), '__viewer' => 7 ) );
check( refused( $r, 'zdz_report_window_future' ), 'future-only window refused' );

$r = Harness_Sources::read( array( 'source' => 'sale_events', 'entity' => 'rep', 'window' => array( 'from' => '2020-01-01', 'to' => '2026-01-01' ), '__viewer' => 7 ) );
check( refused( $r, 'zdz_report_window_too_wide' ), 'over-wide window refused' );

$r = Harness_Sources::read( array( 'source' => 'sale_events', 'entity' => 'rep', 'window' => array( 'from' => 'not-a-date', 'to' => '2026-03-31' ), '__viewer' => 7 ) );
check( refused( $r, 'zdz_report_bad_window' ), 'malformed window refused' );

echo "\n== (2) visibility: a customer reader never selects an internal event ==\n";

// A customer viewer (uid 3) and an internal/admin viewer (uid 7). Mixed-visibility rows.
$GLOBALS['__caps'][3] = array(); // no manage_options -> staff by default...
ZDZ_Hierarchy::$kiosks = array( 3 ); // ...but on a shared device -> customer audience.
Harness_Sources::$rows = array(
	ev( 'customer', '2026-03-05T10:00:00Z', array( 'sales_code' => 'AB', 'collected_cents' => 100 ) ),
	ev( 'internal', '2026-03-06T10:00:00Z', array( 'sales_code' => 'CD', 'collected_cents' => 200 ) ),
);

// First enforcement point: the SQL visibility set for a customer audience is {customer} only.
$clean = ZDZ_Report_Sources::validate( array( 'source' => 'sale_events', 'entity' => 'rep', 'window' => $win, '__viewer' => 3 ) );
$args  = Harness_Sources::expose_build( $clean, ZDZ_Report_Sources::source( 'sale_events' ) );
check( array( 'customer' ) === $args['visibilities'], 'SQL visibility set for customer audience is {customer} only' );

// Second enforcement point: the per-row PHP guard drops the internal row from the output.
$GLOBALS['__disp'] = array();
reset_query();
$r = Harness_Sources::read( array( 'source' => 'sale_events', 'entity' => 'rep', 'window' => $win, '__viewer' => 3 ) );
$internal_present = isset( $r['cells']['p:22'] ); // CD => rep 22 (the internal event)
check( ! empty( $r['ok'] ) && 1 === (int) $r['total'] && ! $internal_present, 'customer read excludes the internal event (total=1, rep 22 absent)' );
$dropped = 0; foreach ( $GLOBALS['__disp'] as $d ) { if ( ( $d[1]['code'] ?? '' ) === 'visibility_drop' ) { $dropped++; } }
check( $dropped >= 1, 'a visibility_drop disposition was logged for the withheld internal event' );

// The internal/admin viewer (uid 7) sees BOTH tiers.
$r = Harness_Sources::read( array( 'source' => 'sale_events', 'entity' => 'rep', 'window' => $win, '__viewer' => 7 ) );
check( 2 === (int) $r['total'] && isset( $r['cells']['p:11'] ) && isset( $r['cells']['p:22'] ), 'internal reader sees both the customer and internal events' );

echo "\n== (3) money entitlement fail-closed (refused BEFORE any gather) ==\n";

ZDZ_Hierarchy::$kiosks     = array();
$GLOBALS['__caps'][9]      = array(); ZDZ_Data_Permissions::$grant[9] = false; // staff, no revenue right

reset_query();
$r = Harness_Sources::read( array( 'source' => 'revenue_booked', 'entity' => 'rep', 'window' => $win, '__viewer' => 9 ) );
check( refused( $r, 'zdz_report_forbidden_money' ) && 0 === Harness_Sources::$query_calls, 'money source refused for unentitled viewer, nothing gathered' );

reset_query();
$r = Harness_Sources::read( array( 'source' => 'sale_events', 'entity' => 'rep', 'axis' => 'amount', 'window' => $win, '__viewer' => 9 ) );
check( refused( $r, 'zdz_report_forbidden_money' ) && 0 === Harness_Sources::$query_calls, 'amount axis refused for unentitled viewer, nothing gathered' );

ZDZ_Data_Permissions::$grant[9] = true; // grant revenue right
$c = ZDZ_Report_Sources::validate( array( 'source' => 'revenue_booked', 'entity' => 'rep', 'window' => $win, '__viewer' => 9 ) );
check( is_array( $c ) && ! ZDZ_Report_Spec::is_error( $c ), 'entitled viewer passes the money gate' );

echo "\n== (4) validate() cannot be skipped (assert tripwire) ==\n";

if ( 1 != ini_get( 'zend.assertions' ) ) {
	echo "  SKIP  (run with -d zend.assertions=1 to exercise the tripwire)\n";
} else {
$threw = false;
try {
	// An un-validated (un-stamped) spec handed straight to the query builder.
	Harness_Sources::expose_build(
		array( 'source' => 'sale_events', 'entity' => 'rep', 'axis' => 'count', 'window' => $win, 'window_from_ts' => 1, 'window_to_ts' => 2, '__viewer' => 7 ),
		ZDZ_Report_Sources::source( 'sale_events' )
	);
} catch ( \AssertionError $e ) {
	$threw = true;
}
check( $threw, 'query builder asserts on an un-validated spec (skip-validate mutation fails)' );
}

echo "\n== (5) attribution: reused contract; Unattributed never hidden ==\n";

Harness_Sources::$rows = array(
	ev( 'internal', '2026-03-10T12:00:00Z', array( 'sales_code' => 'AB' ) ),  // -> rep 11
	ev( 'internal', '2026-03-11T12:00:00Z', array( 'sales_code' => 'ZZ' ) ),  // valid format, no plan -> Unattributed
	ev( 'internal', '2026-03-12T12:00:00Z', array( 'sales_code' => 'WEB' ) ), // reserved token -> Unattributed
);
$GLOBALS['__disp'] = array();
$r = Harness_Sources::read( array( 'source' => 'sale_events', 'entity' => 'rep', 'window' => $win, '__viewer' => 7 ) );
check( isset( $r['cells']['p:11'] ), 'document code AB attributed to rep 11 (via plan_by_code)' );
check( isset( $r['cells']['__unattributed'] ) && 2 === array_sum( $r['cells']['__unattributed'] ), 'unresolvable + reserved dots land on the explicit Unattributed row (2), not hidden' );
$warned = 0; foreach ( $GLOBALS['__disp'] as $d ) { if ( ( $d[1]['code'] ?? '' ) === 'override_lookup_unavailable' ) { $warned++; } }
check( $warned >= 1, 'a disposition warns that override_row is unwired (never a silent fall-through)' );

echo "\n== (6) no network; tenant-tz day-bucket has no off-by-one ==\n";

check( 0 === $GLOBALS['__net'], 'read path made ZERO network calls' );

ZDZ_Business_Profile::$tz = 'America/Los_Angeles';
Harness_Sources::$rows   = array( ev( 'internal', '2026-03-02T02:00:00Z', array( 'sales_code' => 'AB' ) ) ); // 02:00 UTC = 2026-03-01 18:00 PST
$r = Harness_Sources::read( array( 'source' => 'sale_events', 'entity' => 'rep', 'window' => array( 'from' => '2026-02-25', 'to' => '2026-03-05' ), '__viewer' => 7 ) );
check( isset( $r['cells']['p:11']['2026-03-01'] ), 'a 02:00Z event buckets to 2026-03-01 in America/Los_Angeles (no UTC shift)' );
ZDZ_Business_Profile::$tz = 'UTC';

echo "\n---------------------------------------------\n";
echo "PASS=$PASS  FAIL=$FAIL\n";
exit( $FAIL ? 1 : 0 );
