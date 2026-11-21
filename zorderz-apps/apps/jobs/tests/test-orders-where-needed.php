<?php
/**
 * Material orders — the forms show first only on a job that calls for an order, and nothing the server checks moved.
 *
 * ZJOB_Project_Orders::view() tells the screen whether what was sold needs a supplier's order (sold_needs). It is for
 * the screen alone: the same forms and the same "may start" go out either way, an order still starts on a job that
 * shows the one line instead, someone with no tie to the job is still refused, and the shared device still gets
 * nothing to start.
 *
 * Runs in its own process (it stands in for the Projects container, the visibility rule and the hierarchy).
 * Run:  php zorderz-apps/apps/jobs/tests/test-orders-where-needed.php
 */
error_reporting( E_ALL & ~E_DEPRECATED );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

$GLOBALS['options'] = array();
function apply_filters( $hook, $value = null, ...$args ) { return $value; }
function add_action( ...$a ) { return true; }
function add_filter( ...$a ) { return true; }
function __( $s, $d = null ) { return $s; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; }
function wp_unslash( $v ) { return $v; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function current_time( $type, $gmt = 0 ) { return gmdate( 'mysql' === $type ? 'Y-m-d H:i:s' : (string) $type ); }
class WP_Error {
	private $m;
	public function __construct( $c = '', $m = '' ) { $this->m = $m; }
	public function get_error_message() { return $this->m; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

/** Who is the shared device, and who administers the site. */
final class ZDZ_Hierarchy {
	public static $kiosk  = array();
	public static $admins = array();
	public static function is_kiosk( $uid ) { return in_array( (int) $uid, self::$kiosk, true ); }
	public static function is_admin( $uid ) { return in_array( (int) $uid, self::$admins, true ); }
}

/** The Projects and their jobs, as the container would answer. */
final class Zjob_Project {
	public static $rows = array();
	public static $jobs = array();
	public static function get( $id ) { return self::$rows[ (string) $id ] ?? null; }
	public static function jobs_for( $id ) { return self::$jobs[ (string) $id ] ?? array(); }
}

/** Each person's tie to every Project here: [ reading, acting ]. */
final class Zjob_Project_Visibility {
	const REL_ALL = 'all', REL_RELATED = 'related', REL_OVERSIGHT = 'oversight', REL_NONE = 'none';
	public static $rel = array();
	public static function relationship( int $uid, array $project, bool $for_reading = true ): string {
		if ( ZDZ_Hierarchy::is_kiosk( $uid ) ) {
			return self::REL_NONE;
		}
		$r = self::$rel[ $uid ] ?? array( self::REL_NONE, self::REL_NONE );
		return $for_reading ? $r[0] : $r[1];
	}
	public static function actor_may_use_project( int $uid, array $project ): bool { return self::REL_NONE !== self::relationship( $uid, $project, false ); }
	public static function may_view_project( int $uid, array $project ): bool { return self::REL_NONE !== self::relationship( $uid, $project, true ); }
}

/** The orders table, in memory: just the reads and writes the order panel makes. */
final class Orders_Where_Needed_DB {
	public $prefix    = 'wp_';
	public $insert_id = 0;
	public $rows      = array();
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		return array( 'sql' => (string) $sql, 'args' => array_values( $args ) );
	}
	public function get_results( $q, $o = null ) {
		$out = array();
		if ( is_array( $q ) && false !== strpos( $q['sql'], 'WHERE project_id = %s AND status <> %s' ) ) {
			foreach ( $this->rows as $r ) {
				if ( (string) $r['project_id'] === (string) $q['args'][0] && $r['status'] !== $q['args'][1] ) {
					$out[] = $r;
				}
			}
		}
		return $out;
	}
	public function get_row( $q, $o = null ) {
		return is_array( $q ) && false !== strpos( $q['sql'], 'WHERE id = %d' ) ? ( $this->rows[ (int) $q['args'][0] ] ?? null ) : null;
	}
	public function get_var( $q ) { return null; }
	public function insert( $table, $row ) {
		$id                = ++$this->insert_id;
		$this->rows[ $id ] = $row + array( 'id' => $id, 'po' => '', 'seller_ok' => null, 'operator_ok' => null, 'approval' => null, 'orig_project_id' => '', 'ordered_at' => null );
		return 1;
	}
	public function query( $q ) {
		// "Discard": the one UPDATE these checks reach.
		if ( is_array( $q ) && 0 === strpos( $q['sql'], 'UPDATE' ) && false !== strpos( $q['sql'], 'SET status = %s, updated_by = %d' ) ) {
			list( $status, , , $id, $was ) = $q['args'];
			if ( isset( $this->rows[ (int) $id ] ) && $this->rows[ (int) $id ]['status'] === $was ) {
				$this->rows[ (int) $id ]['status'] = $status;
				return 1;
			}
		}
		return 0;
	}
	public function get_charset_collate() { return ''; }
}
$GLOBALS['wpdb'] = new Orders_Where_Needed_DB();

require __DIR__ . '/../includes/class-zjob-my-next.php';
require __DIR__ . '/../includes/class-zjob-project-orders.php';

$GLOBALS['__pass'] = 0; $GLOBALS['__fail'] = 0; $GLOBALS['__fails'] = array();
function ok( bool $c, string $m ): void {
	if ( $c ) { $GLOBALS['__pass']++; echo "  \xE2\x9C\x93 $m\n"; }
	else { $GLOBALS['__fail']++; $GLOBALS['__fails'][] = $m; echo "  \xE2\x9C\x97 $m\n"; }
}

$O = 'ZJOB_Project_Orders';
const ADMIN = 1, SELLER = 31, OPERATOR = 21, READER = 40, LEAD = 41, STRANGER = 44, DEVICE = 9;
const P_ORDERED = '01HZZZZZZZZZZZZZZZZZZZZZA1', P_OTHER = '01HZZZZZZZZZZZZZZZZZZZZZA2', P_NOJOBS = '01HZZZZZZZZZZZZZZZZZZZZZA3', P_UNNAMED = '01HZZZZZZZZZZZZZZZZZZZZZA4';

ZDZ_Hierarchy::$admins        = array( ADMIN );
ZDZ_Hierarchy::$kiosk         = array( DEVICE );
Zjob_Project_Visibility::$rel = array(
	ADMIN    => array( 'all', 'all' ),
	SELLER   => array( 'related', 'related' ),
	OPERATOR => array( 'oversight', 'none' ),
	READER   => array( 'oversight', 'none' ),
	LEAD     => array( 'oversight', 'oversight' ), // oversees someone on the job: may use the Project, is no side of its orders
	DEVICE   => array( 'all', 'all' ), // even if something said so
);
foreach ( array( P_ORDERED => array( 'alpha' ), P_OTHER => array( 'gamma' ), P_NOJOBS => array(), P_UNNAMED => array( 'other', '' ) ) as $pid => $kinds ) {
	Zjob_Project::$rows[ $pid ] = array( 'id' => $pid, 'human_code' => 'PRJ-' . substr( $pid, -2 ), 'state' => 'open' );
	Zjob_Project::$jobs[ $pid ] = array();
	foreach ( $kinds as $i => $k ) {
		Zjob_Project::$jobs[ $pid ][] = array( 'id' => $i + 1, 'component' => $k, 'status' => 'open' );
	}
}
// This business orders materials for two kinds of work, and has named one person to check and order them.
$GLOBALS['options'][ ZJOB_My_Next::RULES_OPTION ]            = array( 'order' => array( 'kinds' => array( 'alpha', 'beta' ) ) );
$GLOBALS['options'][ ZJOB_Project_Orders::OPT_OPERATORS ]    = array( 'ids' => array( OPERATOR ) );
$GLOBALS['options'][ ZJOB_Project_Orders::OPT_READY ]        = 1;
$view = static function ( string $pid, int $uid ) use ( $O ) {
	return $O::view( Zjob_Project::get( $pid ), Zjob_Project::jobs_for( $pid ), $uid );
};

echo "The view carries it\n";
$v1 = $view( P_ORDERED, ADMIN );
$v2 = $view( P_OTHER, ADMIN );
ok( array_key_exists( 'sold_needs', $v1 ) && true === $v1['sold_needs'], 'a Project with an ordered kind: true' );
ok( array_key_exists( 'sold_needs', $v2 ) && false === $v2['sold_needs'], 'a Project whose kinds are known and not ordered: false' );
ok( true === $view( P_NOJOBS, ADMIN )['sold_needs'], 'a Project with no job yet: true (never hidden on a guess)' );
ok( true === $view( P_UNNAMED, ADMIN )['sold_needs'], 'a Project whose jobs name no kind: true' );
ok( false !== strpos( (string) json_encode( $v2 ), '"sold_needs":false' ), 'on the wire it is a real false (not "", not "0")' );
ok( $v1['forms'] === $v2['forms'] && count( $v2['forms'] ) >= 1, 'the Project that shows the line is still sent every form' );
ok( true === $v1['can_start'] && true === $v2['can_start'], 'who may start an order is the same on both' );
ok( true === $O::view( $O::stock_project(), array(), OPERATOR )['sold_needs'], 'the materials desk: true' );

echo "\nIt is not a permission\n";
ok( array( 'seller' ) === $O::sides( SELLER, Zjob_Project::get( P_OTHER ) ), 'the seller is still a side of an order on that Project' );
ok( array( 'operator' ) === $O::sides( OPERATOR, Zjob_Project::get( P_OTHER ) ), 'and so is the person who orders' );
$reader = $view( P_OTHER, READER );
ok( false === $reader['can_start'] && false === $reader['sold_needs'], 'someone who only reads the Project gets the same answer and nothing to start, as before' );
$device = $view( P_OTHER, DEVICE );
ok( array() === $O::sides( DEVICE, Zjob_Project::get( P_OTHER ) ) && false === $device['can_start'], 'the shared device still has nothing to start' );

$r = $O::act( SELLER, 'start', P_OTHER, array( 'form' => 'materials' ) );
ok( is_array( $r ) && 1 === count( $r['orders'] ), 'an order still starts on the job that shows the line: nothing the server checks changed' );
ok( is_array( $r ) && false === $r['sold_needs'], 'the reply after it still says what the job calls for' );
$started = is_array( $r ) ? (int) $r['orders'][0]['id'] : 0;
$r = $O::act( STRANGER, 'start', P_OTHER, array( 'form' => 'materials' ) );
ok( is_wp_error( $r ) && 'That project is not available.' === $r->get_error_message(), 'someone with no tie to the job is still refused' );
$r = $O::act( READER, 'start', P_OTHER, array( 'form' => 'materials' ) );
ok( is_wp_error( $r ), 'and so is someone who only reads it' );
$r = $O::act( LEAD, 'start', P_OTHER, array( 'form' => 'materials' ) );
ok( is_wp_error( $r ) && 'You can see this order but not change it.' === $r->get_error_message(), 'and someone who may use the Project but is no side of its orders' );
$r = $O::act( DEVICE, 'start', P_OTHER, array( 'form' => 'materials' ) );
ok( is_wp_error( $r ), 'and the shared device' );
ok( 1 === count( $O::for_project( P_OTHER ) ), 'none of the refusals wrote an order' );
$r = $O::act( SELLER, 'discard', P_OTHER, array( 'order_id' => $started ) );
ok( is_array( $r ) && array() === $r['orders'] && false === $r['sold_needs'], 'the order is discarded and the panel is back to its line' );
$r = $O::act( SELLER, 'start', P_ORDERED, array( 'form' => 'materials' ) );
ok( is_array( $r ) && 1 === count( $r['orders'] ) && true === $r['sold_needs'], 'an order starts on a job that needs one exactly as it did' );

echo "\n" . str_repeat( '=', 56 ) . "\n";
printf( "  %d passed, %d failed\n", $GLOBALS['__pass'], $GLOBALS['__fail'] );
if ( $GLOBALS['__fail'] > 0 ) {
	foreach ( $GLOBALS['__fails'] as $f ) { echo "    - $f\n"; }
	exit( 1 );
}
echo "  ALL GREEN\n";
exit( 0 );
