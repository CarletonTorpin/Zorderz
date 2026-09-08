<?php
/**
 * Geo-backfill worklist sentence — the pure operator-visibility decider.
 * Pure PHP, no WP/DB. Run:  php tests/test-geo-worklist.php
 */
error_reporting( E_ALL & ~E_DEPRECATED );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

// Load only the class body without booting the plugin.
require __DIR__ . '/../includes/class-zjob-geo-backfill.php';

$GLOBALS['__pass'] = 0; $GLOBALS['__fail'] = 0; $GLOBALS['__fails'] = [];
function ok( bool $c, string $m ): void {
	if ( $c ) { $GLOBALS['__pass']++; echo "  \xE2\x9C\x93 $m\n"; }
	else { $GLOBALS['__fail']++; $GLOBALS['__fails'][] = $m; echo "  \xE2\x9C\x97 $m\n"; }
}

$D = 'Zjob_Geo_Backfill';

ok( strpos( $D::describe_status( 'unresolvable', 4, false ), 'Correct the address' ) !== false,
	"'unresolvable' -> tells the operator to fix the address (the one actionable row)" );
ok( strpos( $D::describe_status( 'unresolvable', 4, false ), '4 tries' ) !== false,
	"'unresolvable' reports the attempt count" );
ok( strpos( $D::describe_status( 'retry', 2, false, 4 ), 'attempt 2 of 4' ) !== false,
	"'retry' shows attempt N of ceiling" );
ok( strpos( $D::describe_status( 'retry', 2, true, 4 ), 'Parked' ) !== false,
	"'retry' + parked notes the ~6h park" );
ok( strpos( $D::describe_status( 'retry', 2, false, 4 ), 'Parked' ) === false,
	"'retry' NOT parked omits the park note" );
ok( strpos( $D::describe_status( 'ok', 1, false ), 'stamp on the next run' ) !== false,
	"'ok' -> resolved, will stamp next run" );
ok( strpos( $D::describe_status( 'no-row', 0, false ), 'No estimate row' ) !== false,
	"'no-row' -> no address to read (deleted/numberless)" );
ok( strpos( $D::describe_status( '', 0, false ), 'Not attempted yet' ) !== false,
	"unknown/blank -> not attempted yet" );

echo "\n" . str_repeat( '=', 56 ) . "\n";
printf( "  %d passed, %d failed\n", $GLOBALS['__pass'], $GLOBALS['__fail'] );
if ( $GLOBALS['__fail'] > 0 ) {
	foreach ( $GLOBALS['__fails'] as $f ) { echo "    - $f\n"; }
	exit( 1 );
}
echo "  ALL GREEN\n";
exit( 0 );
