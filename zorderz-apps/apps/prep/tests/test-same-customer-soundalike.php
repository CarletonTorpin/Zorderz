<?php
/**
 * §131 — Prep same_customer() soundalike de-dup is GIVEN-NAME GATED.
 *
 * Pins the safety property (the §131 review's top concern): a soundalike surname may
 * fold two Approved-to-Cut cards into one ONLY when a given name also agrees. A
 * soundalike surname with a divergent given name, or with surname-only on either side,
 * must stay DISTINCT — never merge two people on sound alone (INV-12).
 *
 * Uses a minimal ZDZ_Name_Match stand-in (generic, non-customer surnames) so the GATE
 * is tested without the theme present; the real matcher's linguistics are pinned in the
 * theme's tests/unit/test-name-match.php.
 *
 * Run:  php tests/test-same-customer-soundalike.php
 */
error_reporting( E_ALL & ~E_DEPRECATED );
if ( ! defined( 'ABSPATH' ) )        { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $t, $v ) { return $v; } }

// Minimal stand-in: only these generic surname pairs are declared soundalike.
if ( ! class_exists( 'ZDZ_Name_Match' ) ) {
	class ZDZ_Name_Match {
		public static function sounds_like( $a, $b ) {
			$a = strtolower( trim( $a ) ); $b = strtolower( trim( $b ) );
			foreach ( array( array( 'reed', 'read' ), array( 'shaw', 'schau' ) ) as $g ) {
				if ( in_array( $a, $g, true ) && in_array( $b, $g, true ) ) { return true; }
			}
			return $a === $b;
		}
		public static function soundalike_variants( $n ) { return array(); }
		public static function name_sound_key( $n ) { return ''; }
		public static function first_names_equivalent( $a, $b ) { return strtolower( trim( $a ) ) === strtolower( trim( $b ) ); }
	}
}

require __DIR__ . '/../includes/class-zprep-dashboard.php';
require __DIR__ . '/../includes/class-zprep-install-fallback.php';

$pass = 0; $fail = 0;
function ok( bool $c, string $m ): void {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  \xE2\x9C\x93 $m\n"; }
	else { $fail++; echo "  \xE2\x9C\x97 $m\n"; }
}
function fold( string $a, string $b ): bool { return ZPREP_Dashboard::same_customer( $a, '', $b, '' ); }

ok( fold( 'Steve Reed', 'Steve Read' ) === true,      'soundalike surname + same given -> FOLD (Reed/Read, both Steve)' );
ok( fold( 'Steve Reed', 'John Read' ) === false,      'soundalike surname + different given -> distinct (never merge)' );
ok( fold( 'Reed', 'Read' ) === false,                 'soundalike surname, surname-only -> distinct (never merge on sound alone)' );
ok( fold( 'Steve Shaw', 'Steve Schau' ) === true,     'second soundalike pair + same given -> FOLD' );
ok( fold( 'Alex Rivera', 'Alex Rivera' ) === true,    'exact surname + given -> FOLD (unchanged)' );
ok( fold( 'Jordan Rivera', 'Alex Rivera' ) === false, 'exact surname, different given -> distinct (unchanged)' );
ok( fold( 'Rivera', 'Rivera' ) === true,              'exact surname-only -> FOLD (unchanged)' );

echo $fail ? "\n$pass passed, $fail failed\n" : "\nAll {$pass} same_customer soundalike-gate assertions passed.\n";
exit( $fail ? 1 : 0 );
