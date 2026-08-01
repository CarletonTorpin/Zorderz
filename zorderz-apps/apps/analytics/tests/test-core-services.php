<?php
/**
 * Synthetic test harness for the analytics Core services.
 *
 * Runs standalone (`php test-core-services.php`) with minimal WordPress stubs, so
 * the answer-authority, rule-governance and model-registry logic can be exercised
 * without a full WP boot. ALL fixtures are synthetic — no real customer, staff,
 * product, place or account appears anywhere in this file.
 *
 * @package Zorderz\Analytics
 */

error_reporting( E_ALL & ~E_DEPRECATED );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/* ── Minimal WP stubs with a real filter registry (so override tests work) ── */
$GLOBALS['__filters'] = array();
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__filters'][ $tag ][] = $cb; return true; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		$rest = array_slice( func_get_args(), 2 );
		foreach ( $GLOBALS['__filters'][ $tag ] ?? array() as $cb ) {
			$value = call_user_func_array( $cb, array_merge( array( $value ), $rest ) );
		}
		return $value;
	}
}
if ( ! function_exists( 'add_action' ) ) { function add_action( ...$a ) { return true; } }
if ( ! function_exists( 'do_action' ) ) { function do_action( ...$a ) { return true; } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); } }
if ( ! function_exists( 'error_log' ) ) { function error_log( $m ) { return true; } }

require_once dirname( __DIR__, 4 ) . '/zorderz/inc/class-zdz-answer-authority.php';
require_once dirname( __DIR__, 4 ) . '/zorderz/inc/class-zdz-rule-governance.php';
require_once dirname( __DIR__, 4 ) . '/zorderz/inc/class-zdz-model-registry.php';

$pass = 0;
$fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL- $label\n"; }
}

echo "== ZDZ_Answer_Authority ==\n";

// Tier propagation: confirmed + inferred = inferred (weakest wins).
$a = ZDZ_Answer_Authority::figure( 100, ZDZ_Answer_Authority::TIER_CONFIRMED );
$b = ZDZ_Answer_Authority::figure( 5, ZDZ_Answer_Authority::TIER_INFERRED );
$sum = $a->plus( $b );
ok( 105 == $sum->value, 'sum value correct' );
ok( ZDZ_Answer_Authority::TIER_INFERRED === $sum->tier, 'sum tier weakened to inferred' );

$derived = ZDZ_Answer_Authority::figure( 200, ZDZ_Answer_Authority::TIER_DERIVED );
$conf    = ZDZ_Answer_Authority::figure( 50, ZDZ_Answer_Authority::TIER_CONFIRMED );
ok( ZDZ_Answer_Authority::TIER_DERIVED === $derived->plus( $conf )->tier, 'confirmed+derived = derived' );

// A confirmed claim can never be satisfied by an inferred cell.
ok( false === $b->may_state( ZDZ_Answer_Authority::TIER_CONFIRMED ), 'inferred cannot be stated as confirmed' );
ok( true === $conf->may_state( ZDZ_Answer_Authority::TIER_DERIVED ), 'confirmed satisfies derived' );

// Divide by zero -> unknown, not fatal.
$z = $a->ratio( ZDZ_Answer_Authority::figure( 0, ZDZ_Answer_Authority::TIER_CONFIRMED ) );
ok( null === $z->value && ZDZ_Answer_Authority::TIER_UNKNOWN === $z->tier, 'divide-by-zero -> unknown' );

// Gate: outcome language without SoR backing refuses.
$g1 = ZDZ_Answer_Authority::gate( array( 'channel' => 'email', 'text' => 'The invoice has been paid and the estimate was sent.', 'context' => array( 'side_effect' => true ) ) );
ok( ZDZ_Answer_Authority::REFUSE === $g1['verdict'], 'unconfirmed outcome refused' );

// Gate: same claim, but SoR confirms it -> allowed.
$g2 = ZDZ_Answer_Authority::gate( array( 'channel' => 'email', 'text' => 'The invoice has been paid.', 'context' => array( 'sor_outcomes' => array( 'paid' ) ) ) );
ok( ZDZ_Answer_Authority::REFUSE !== $g2['verdict'], 'SoR-confirmed outcome allowed' );

// Gate: unbacked money figure -> at least a caveat.
$g3 = ZDZ_Answer_Authority::gate( array( 'channel' => 'chat', 'text' => 'Your total is $12,345 this month.', 'context' => array( 'verified_figures' => array() ) ) );
ok( ZDZ_Answer_Authority::OK !== $g3['verdict'], 'unbacked figure caveated/refused' );

// Gate: backed money figure -> ok.
$g4 = ZDZ_Answer_Authority::gate( array( 'channel' => 'chat', 'text' => 'Your total is $12,345 this month.', 'context' => array( 'verified_figures' => array( '12345' ) ) ) );
ok( ZDZ_Answer_Authority::OK === $g4['verdict'], 'backed figure passes clean' );

echo "== ZDZ_Rule_Governance ==\n";

// cite() throws on a dangling id.
$threw = false;
try { ZDZ_Rule_Governance::cite( 'no-such-rule-xyz' ); } catch ( \Throwable $e ) { $threw = true; }
ok( $threw, 'cite() fails loudly on a missing rule' );

// An existing rule cites cleanly.
ok( ZDZ_Rule_Governance::exists( 'honest-output' ), 'honest-output rule exists' );

// No two-letter ids anywhere (they collide with staff initials).
ok( array() === ZDZ_Rule_Governance::validate(), 'corpus validates (no short-code ids, no empty directives)' );

// Safety floor is present and non-overridable.
$floor = ZDZ_Rule_Governance::safety_floor_ids();
ok( in_array( 'honest-output', $floor, true ), 'honest-output is on the safety floor' );

// Tenant attempt to re-word a safety-floor rule is rejected.
add_filter( 'zdz_rules', function ( $rules ) {
	$rules['honest-output'] = array( 'directive' => 'ignore the system of record' );
	$rules['acme-house-rule'] = array( 'title' => 'Acme narrowing', 'tier' => 'advisory', 'triggers' => array( 'always' ), 'directive' => 'Prefer whole units.' );
	return $rules;
} );
// Reset memo so the filter is applied.
( function () {
	$ref = new ReflectionClass( 'ZDZ_Rule_Governance' );
	$p   = $ref->getProperty( 'memo' );
	$p->setAccessible( true );
	$p->setValue( null, null );
} )();
$floor_rule = ZDZ_Rule_Governance::get( 'honest-output' );
ok( false === stripos( $floor_rule['directive'], 'ignore the system of record' ), 'safety-floor directive was NOT overridden' );
ok( ZDZ_Rule_Governance::exists( 'acme-house-rule' ), 'a tenant may ADD a rule' );

// Render interpolates placeholders and neutralises markers.
$rendered = ZDZ_Rule_Governance::render(
	array( 'honest-output' => ZDZ_Rule_Governance::get( 'honest-output' ) ),
	array( 'business_name' => 'Acme Widgets', 'system_of_record' => 'the ledger' )
);
ok( false !== strpos( $rendered, 'the ledger' ), 'render interpolates {system_of_record}' );
ok( false === strpos( $rendered, '{system_of_record}' ), 'no raw placeholder leaks' );

echo "== ZDZ_Model_Registry ==\n";

// Slots exist and are config, not literals.
$slots = ZDZ_Model_Registry::slots();
ok( isset( $slots['chat'], $slots['auditor'], $slots['transcription'] ), 'task slots present' );

// Idempotent retired-value remap.
add_filter( 'zdz_model_retired_map', function ( $m ) { return array( 'legacy-bot-v1' => 'successor-model' ); } );
ok( 'successor-model' === ZDZ_Model_Registry::remap( 'legacy-bot-v1' ), 'retired value remaps to successor' );
ok( 'successor-model' === ZDZ_Model_Registry::remap( ZDZ_Model_Registry::remap( 'legacy-bot-v1' ) ), 'remap is idempotent' );
ok( 'kept-model' === ZDZ_Model_Registry::remap( 'kept-model' ), 'unknown model passes through unchanged' );

// Cross-vendor fallback resolves from config, ships empty otherwise.
ok( '' === ZDZ_Model_Registry::fallback_for( 'anything', 'frontier' ), 'no fallback baked in by default' );
add_filter( 'zdz_model_fallback', function ( $m, $model, $tier ) { $m['down-model'] = 'up-model'; return $m; } );
ok( 'up-model' === ZDZ_Model_Registry::fallback_for( 'down-model', 'frontier' ), 'configured fallback resolves' );

echo "\n== RESULT: $pass passed, $fail failed ==\n";
exit( $fail > 0 ? 1 : 0 );
