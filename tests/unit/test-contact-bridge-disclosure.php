<?php
/**
 * ZDZ_Contact_Bridge::full_disclosure_ok() PRECISION GATE.
 *
 * Security property: the contact card discloses FULL customer PII (phone/email/address)
 * ONLY when it is confident the resolved record IS the asked person. A low-confidence
 * resolution — an unrelated surname from a first-name/near fallback, or a near surname
 * with no corroborating first name — must return name + city ONLY, never the wrong
 * person's contact details.
 *
 * Tests the SHIPPED method directly (no replica). Pins the rule that a tier 'none' match and
 * a surname-only 'strong' match never disclose full PII. Invented names only.
 *
 * Run: php tests/unit/test-contact-bridge-disclosure.php
 */
error_reporting( E_ALL & ~E_DEPRECATED );
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $h, $v ) { return $v; } }

require __DIR__ . '/../../zorderz/inc/class-zdz-name-match.php';
require __DIR__ . '/../../zorderz/inc/class-zdz-contact-bridge.php';

$pass = 0; $fail = 0;
function ok( bool $c, string $m ): void {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  \xE2\x9C\x93 $m\n"; }
	else { $fail++; echo "  \xE2\x9C\x97 $m\n"; }
}
function full( string $q, string $r ): bool { return ZDZ_Contact_Bridge::full_disclosure_ok( $q, $r ); }

ok( method_exists( 'ZDZ_Contact_Bridge', 'full_disclosure_ok' ), 'full_disclosure_ok() exists' );

/* ── MUST be name+city only (low confidence — no full PII) ── */
ok( full( 'Robin Hale', 'Riley Haley' ) === false,   'divergent first name (Robin Hale -> Riley Haley) -> NO full PII' );
ok( full( 'John Smith', 'John Anderson' ) === false, 'unrelated surname via first-name fallback -> NO full PII' );
ok( full( 'Smith', 'John Anderson' ) === false,      'surname-only query, unrelated resolved -> NO full PII' );
ok( full( 'Jones', 'Robert Jonas' ) === false,       'surname-only, near-but-different surname, no corroboration -> NO full PII' );
ok( full( 'Blake Marsh', 'Devin Marsh' ) === false,  'divergent first name over an exact surname (Blake Marsh -> Devin Marsh) -> NO full PII' );
ok( full( '', 'John Smith' ) === false,              'empty query -> NO full PII' );

/* ── SHOULD be full disclosure (confident same person / org) ── */
ok( full( 'John Smith', 'John Smith' ) === true,     'exact match -> full' );
ok( full( 'Jon Smith', 'John Smith' ) === true,      'nickname/soundalike first + exact surname -> full' );
ok( full( 'Robin Hale', 'Robin Haile' ) === true,   'variant surname spelling + exact first (recall) -> full' );
ok( full( 'Quinn Marsh', 'Quinn Marsch' ) === true,  'second variant spelling + exact first -> full' );
ok( full( 'Robin Hale', 'Robin Haley' ) === true,   'near surname CORROBORATED by exact first name -> full' );
ok( full( 'Smith', 'John Smith' ) === true,          'surname-only query contained in resolved name -> full' );
ok( full( 'Marsh', 'Devin Marsh' ) === true,         'surname-only unique exact-surname match (scope-gated downstream) -> full' );
ok( full( 'Examplewood', 'Examplewood Property Management' ) === true, 'organisation single token contained -> full' );

echo $fail ? "\n$pass passed, $fail failed\n" : "\nAll {$pass} disclosure-gate assertions passed.\n";
exit( $fail ? 1 : 0 );
