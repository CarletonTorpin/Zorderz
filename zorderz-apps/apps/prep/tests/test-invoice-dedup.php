<?php
/**
 * Invoice-arm de-dup: two jobs for ONE customer stay two cards.
 *
 * The Approved-to-Cut queue folds recent queue invoices in AFTER the CRM /
 * approved-estimate pass. A naive de-dup that seeds each shown invoice's OWN
 * customer would drop a SECOND, distinct invoice for the same customer. So an
 * invoice dedupes ONLY against OTHER-SOURCE (CRM) cards by customer, and against
 * another invoice only when they share a billing estimate id — two distinct
 * invoices for one customer stay two cards.
 *
 * Pins the pure decider ZPREP_Dashboard::classify_invoice_card() + the fuzzy
 * same_customer() rule + the install-date parser. Pure PHP, no WP/network.
 * Run:  php tests/test-invoice-dedup.php
 */
error_reporting( E_ALL & ~E_DEPRECATED );
if ( ! defined( 'ABSPATH' ) )           { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) )    { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $t, $v ) { return $v; } }

require __DIR__ . '/../includes/class-zprep-dashboard.php';
require __DIR__ . '/../includes/class-zprep-install-fallback.php';

/* ───────────────────────── tiny harness ───────────────────────── */
$GLOBALS['__pass'] = 0; $GLOBALS['__fail'] = 0; $GLOBALS['__fails'] = [];
function ok( bool $c, string $m ): void {
	if ( $c ) { $GLOBALS['__pass']++; echo "  \xE2\x9C\x93 $m\n"; }
	else { $GLOBALS['__fail']++; $GLOBALS['__fails'][] = $m; echo "  \xE2\x9C\x97 $m\n"; }
}
function section( string $s ): void { echo "\n$s\n" . str_repeat( '-', strlen( $s ) ) . "\n"; }

/*
 * The de-dup context the invoice arm holds after the CRM / approved-estimate pass,
 * built the way ajax_approved_to_cut() seeds it: a CRM card seeds c:<id>, n:<norm
 * name>, and $shown; an approved estimate seeds c:<id> and $shown. No invoice ever
 * seeds these — that is the fix.
 */
$seen_cust = [];
$shown     = [];
// CRM card — Casey Morgan (carries a billing customer id)
$seen_cust[ 'c:700' ] = true;
$seen_cust[ 'n:' . ZPREP_Dashboard::norm_name( 'Casey Morgan' ) ] = true;
$shown[] = [ 'Casey Morgan', '700' ];
// Approved estimate — the paired household "Sam / Robin Blake", seeded by id
$seen_cust[ 'c:899991' ] = true;
$shown[] = [ 'Sam / Robin Blake', '899991' ];

$nm_alex = ZPREP_Dashboard::norm_name( 'Alex Rivera' );

section( "classify_invoice_card(): two invoices for one customer are TWO jobs" );
$seen_est = [];
// Alex's first invoice (customer 900001, estimate id L) — no Alex seen yet.
ok( ZPREP_Dashboard::classify_invoice_card( 'Alex Rivera', '900001', $nm_alex, 'L', $seen_cust, $shown, $seen_est ) === 'new',
	"first Alex invoice -> new (shown)" );
$seen_est[ 'L' ] = true; // the arm records the estimate id it just showed (never the customer)
// Alex's second invoice (SAME customer, DIFFERENT estimate id C) — the bug case.
ok( ZPREP_Dashboard::classify_invoice_card( 'Alex Rivera', '900001', $nm_alex, 'C', $seen_cust, $shown, $seen_est ) === 'new',
	"second invoice, same customer, different estimate -> new (shown) — THE FIX" );

section( "classify_invoice_card(): a genuinely re-billed SAME estimate still folds" );
ok( ZPREP_Dashboard::classify_invoice_card( 'Alex Rivera', '900001', $nm_alex, 'L', $seen_cust, $shown, $seen_est ) === 'dup_invoice',
	"a second invoice for estimate L -> dup_invoice (deduped)" );
ok( ZPREP_Dashboard::classify_invoice_card( 'Alex Rivera', '900001', $nm_alex, '', $seen_cust, $shown, $seen_est ) === 'new',
	"no estimate id + no other-source match -> new (can't prove a duplicate, so show it)" );

section( "classify_invoice_card(): an invoice for an already-shown OTHER source folds" );
ok( ZPREP_Dashboard::classify_invoice_card( 'Casey Morgan', '700', ZPREP_Dashboard::norm_name( 'Casey Morgan' ), 'M', $seen_cust, $shown, $seen_est ) === 'other_source',
	"Casey's invoice -> other_source (folds into her CRM card)" );
ok( ZPREP_Dashboard::classify_invoice_card( 'Sam Blake', '899991', 'sam blake', 'S', $seen_cust, $shown, $seen_est ) === 'other_source',
	"Sam Blake invoice -> other_source (same customer id as the estimate)" );
ok( ZPREP_Dashboard::classify_invoice_card( 'Sam Blake', '', 'sam blake', 'S', $seen_cust, $shown, $seen_est ) === 'other_source',
	"Sam Blake invoice with NO id -> other_source (fuzzy surname+given vs \"Sam / Robin Blake\")" );
ok( ZPREP_Dashboard::classify_invoice_card( 'Jordan Blake', '', 'jordan blake', 'J', $seen_cust, $shown, $seen_est ) === 'new',
	"Jordan Blake (same surname, different given) -> new (NOT merged into Sam)" );

section( "same_customer(): the fuzzy household-pair rule" );
ok( ZPREP_Dashboard::same_customer( 'Sam / Robin Blake', '', 'Sam Blake', '' ) === true,
	"\"Sam / Robin Blake\" == \"Sam Blake\" (shared surname + given)" );
ok( ZPREP_Dashboard::same_customer( 'Sam Blake', '', 'Jordan Blake', '' ) === false,
	"\"Sam Blake\" != \"Jordan Blake\" (same surname, different given)" );
ok( ZPREP_Dashboard::same_customer( 'Alex Rivera', '900001', 'Alex Rivera', '900002' ) === false,
	"different customer ids never merge, even on an identical name" );

section( "the parser reads the exact 'Sched. M/D & M/D - 9 AM' lines" );
$club  = 'Downtown - (CT) (TC) Building A - Sched. 9/13 & 9/14 - 9 AM';
$lodge = 'Downtown - (CT) (TC) Building B - Sched. 9/16 & 9/17 - 9 AM';
$rc = ZPREP_Install_Fallback::from_text( $club );
$rl = ZPREP_Install_Fallback::from_text( $lodge );
ok( is_array( $rc ) && strpos( (string) $rc['start_utc'], '2026-09-13' ) === 0, "\"9/13 & 9/14\" -> 2026-09-13 (first day), start_utc set" );
ok( is_array( $rc ) && $rc['assignee'] === 'CT', "installer initials -> CT (first of (CT)(TC))" );
ok( is_array( $rl ) && strpos( (string) $rl['start_utc'], '2026-09-16' ) === 0, "\"9/16 & 9/17\" -> 2026-09-16, start_utc set" );

/* ───────────────────────── summary ───────────────────────── */
echo "\n" . str_repeat( '=', 60 ) . "\n";
printf( "  %d passed, %d failed\n", $GLOBALS['__pass'], $GLOBALS['__fail'] );
if ( $GLOBALS['__fail'] > 0 ) {
	echo "  FAILURES:\n";
	foreach ( $GLOBALS['__fails'] as $f ) { echo "    - $f\n"; }
	exit( 1 );
}
echo "  ALL GREEN\n";
exit( 0 );
