<?php
/**
 * An opened Project carries what its estimates say in words, whole, to the people who may open it and to nobody else.
 *
 * The real request handler (ZJOB_Projects_Ajax), the real resolver, the real code reader (ZJOB_Sealed on the
 * platform's vault) and the app's own Privacy View shaping, over a Project and its estimates held in memory:
 *
 *   - a long dictation arrives to its last word; a note is held to a bound counted in characters and, past it, ends
 *     with a sentence saying where the rest is; a cut never lands inside a character;
 *   - every amount is taken out, and an access code is cut, before the bound is applied, so neither is ever left
 *     half showing at the cut;
 *   - nothing is read for the shared device, for a request without its nonce, or for a person who may not open the
 *     Project, and a missing Project and a forbidden one get the same answer;
 *   - without the estimate app, or with nothing written, the Project still opens and no notes are drawn;
 *   - a Privacy View account gets the Project's own customer and estimate number as pseudonyms;
 *   - opening a Project writes nothing.
 *
 * Runs in its own process (it stands in for the Projects container, the visibility rule, the hierarchy and the
 * platform's Privacy View). Run:  php zorderz-apps/apps/jobs/tests/test-estimate-notes-whole.php
 */
error_reporting( E_ALL & ~E_DEPRECATED );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! defined( 'ZJOB_NONCE' ) ) { define( 'ZJOB_NONCE', 'zjob_nonce' ); }

$GLOBALS['options']    = array();
$GLOBALS['me']         = 0;
$GLOBALS['nonce_ok']   = true;
$GLOBALS['nonce_seen'] = array();

/** How a request ends here: what the handler answered, and with which status. */
final class Notes_Whole_Reply extends Exception {
	public $ok;
	public $data;
	public $status;
	public function __construct( bool $ok, $data, int $status ) {
		parent::__construct( 'reply' );
		$this->ok     = $ok;
		$this->data   = $data;
		$this->status = $status;
	}
}

function apply_filters( $hook, $value = null, ...$args ) { return $value; }
function add_action( ...$a ) { return true; }
function add_filter( ...$a ) { return true; }
function __( $s, $d = null ) { return $s; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; }
function wp_unslash( $v ) { return $v; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function wp_salt( $scheme = 'auth' ) { return 'a-test-salt-that-is-long-enough-to-be-one-0123456789-' . $scheme; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function get_current_user_id() { return (int) $GLOBALS['me']; }
function get_userdata( $id ) { return (object) array( 'ID' => (int) $id, 'display_name' => 'Person ' . (int) $id ); }
function check_ajax_referer( $action, $arg = false ) {
	$GLOBALS['nonce_seen'][] = array( $action, $arg );
	if ( ! $GLOBALS['nonce_ok'] ) {
		throw new Notes_Whole_Reply( false, '-1', 403 );
	}
	return 1;
}
function wp_send_json_error( $data = null, $status = 200 ) { throw new Notes_Whole_Reply( false, $data, (int) $status ); }
function wp_send_json_success( $data = null, $status = 200 ) { throw new Notes_Whole_Reply( true, $data, (int) $status ); }

/** Who is the shared device. */
final class ZDZ_Hierarchy {
	public static $kiosk = array();
	public static function is_kiosk( $uid ) { return in_array( (int) $uid, self::$kiosk, true ); }
	public static function is_admin( $uid ) { return false; }
}

/** The Projects and their jobs, as the container would answer. */
final class Zjob_Project {
	public static $rows = array();
	public static function get( $id ) { return self::$rows[ (string) $id ] ?? null; }
	public static function jobs_for( $id ) { return array(); }
}

/** Who may open which Project. */
final class Zjob_Project_Visibility {
	const REL_ALL = 'all', REL_RELATED = 'related', REL_OVERSIGHT = 'oversight', REL_NONE = 'none';
	public static $may   = array(); // person => [ project ids ]
	public static $asked = 0;
	public static function may_view_project( int $uid, array $project ): bool {
		self::$asked++;
		return ! ZDZ_Hierarchy::is_kiosk( $uid ) && in_array( (string) ( $project['id'] ?? '' ), self::$may[ $uid ] ?? array(), true );
	}
}

/** The platform's Privacy View: on or off, and its pseudonyms. */
final class ZDZ_Privacy_View {
	public static $on = false;
	public static function is_on( $uid = null ) { return self::$on; }
	public static function customer( $name ) { return 'Customer-K7'; }
	public static function staff( $uid ) { return 'Staff-' . (int) $uid; }
	public static function street( $s ) { return 'Street-Q2'; }
	public static function doc( $kind, $number ) { return strtoupper( substr( (string) $kind, 0, 3 ) ) . '-X' . strlen( (string) $number ); }
}

/** The two tables an opened Project's notes are read from, in memory; every statement is kept. */
final class Notes_Whole_DB {
	public $prefix    = 'wp_';
	public $refs      = array(); // [ [ project id, estimate id, recorded order ] ]
	public $estimates = array(); // estimate id => row
	public $queries   = array();
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback( '/%[dsf]/', function ( $m ) use ( &$i, $args ) {
			$v = $args[ $i++ ] ?? '';
			return '%s' === $m[0] ? "'" . addslashes( (string) $v ) . "'" : (string) (int) $v;
		}, $sql );
	}
	public function get_results( $sql, $o = null ) {
		$this->queries[] = $sql;
		$out             = array();
		if ( false !== strpos( $sql, 'FROM wp_zdz_work_item_refs' ) && preg_match( "/work_item_id = '([^']+)'/", $sql, $m ) && false !== strpos( $sql, "= 'estimate'" ) ) {
			foreach ( $this->refs as $r ) {
				if ( $r[0] === $m[1] ) {
					$out[] = array( 'work_item_id' => $r[0], 'system' => 'estimate', 'entity' => 'document', 'external_id' => (string) $r[1], 'ref_seq' => $r[2] );
				}
			}
		}
		return $out;
	}
	public function get_row( $sql, $o = null ) {
		$this->queries[] = $sql;
		return preg_match( '/^SELECT doc_number, billing_doc_num, notes, input_text FROM wp_zest_estimates WHERE id = (\d+)$/', $sql, $m ) ? ( $this->estimates[ (int) $m[1] ] ?? null ) : null;
	}
	public function get_var( $sql ) { $this->queries[] = $sql; return null; }
	public function get_col( $sql ) { $this->queries[] = $sql; return array(); }
	public function query( $sql ) { $this->queries[] = $sql; return 0; }
	public function insert( $t, $r ) { $this->queries[] = 'INSERT ' . $t; return 0; }
	public function update( $t, $r, $w ) { $this->queries[] = 'UPDATE ' . $t; return 0; }
	public function delete( $t, $w ) { $this->queries[] = 'DELETE ' . $t; return 0; }
	public function get_charset_collate() { return ''; }
}
$GLOBALS['wpdb'] = new Notes_Whole_DB();

$zjob_root = dirname( __DIR__, 4 );
require $zjob_root . '/zorderz/inc/class-zdz-sealed.php';
require __DIR__ . '/../includes/flow/class-zdz-flow-db.php';
require __DIR__ . '/../includes/flow/class-zdz-flow-refs.php';
require __DIR__ . '/../includes/class-zjob-sealed.php';
require __DIR__ . '/../includes/class-zjob-privacy-view.php';
require __DIR__ . '/../includes/class-zjob-project-resolver.php';
require __DIR__ . '/../includes/class-zjob-projects-ajax.php';

$GLOBALS['__pass'] = 0; $GLOBALS['__fail'] = 0; $GLOBALS['__fails'] = array();
function ok( bool $c, string $m ): void {
	if ( $c ) { $GLOBALS['__pass']++; echo "  \xE2\x9C\x93 $m\n"; }
	else { $GLOBALS['__fail']++; $GLOBALS['__fails'][] = $m; echo "  \xE2\x9C\x97 $m\n"; }
}

/** Open a Project as the handler would for $uid: [ answered ok, data, status, statements made ]. */
function open_project( int $uid, string $pid ): array {
	$GLOBALS['me']            = $uid;
	$GLOBALS['wpdb']->queries = array();
	$_POST                    = array( 'project_id' => $pid, 'nonce' => 'n' );
	try {
		ZJOB_Projects_Ajax::ajax_detail();
	} catch ( Notes_Whole_Reply $r ) {
		return array( $r->ok, $r->data, $r->status, $GLOBALS['wpdb']->queries );
	}
	return array( null, null, 0, $GLOBALS['wpdb']->queries );
}
function chars( string $s ): int { return mb_strlen( $s, 'UTF-8' ); }

const MEMBER = 31, STRANGER = 44, DEVICE = 9;
const P_ONE = '01HZZZZZZZZZZZZZZZZZZZZZB1', P_MANY = '01HZZZZZZZZZZZZZZZZZZZZZB2', P_BARE = '01HZZZZZZZZZZZZZZZZZZZZZB3', P_QUIET = '01HZZZZZZZZZZZZZZZZZZZZZB4',
	P_LONG = '01HZZZZZZZZZZZZZZZZZZZZZB5', P_EDGE = '01HZZZZZZZZZZZZZZZZZZZZZB6', P_GONE = '01HZZZZZZZZZZZZZZZZZZZZZB9';
const TAIL = "\u{2026}\n\n[This note is longer than Projects shows. The rest is on the estimate.]";
const MAX  = 60000;

ZDZ_Hierarchy::$kiosk = array( DEVICE );
foreach ( array( P_ONE, P_MANY, P_BARE, P_QUIET, P_LONG, P_EDGE ) as $pid ) {
	Zjob_Project::$rows[ $pid ] = array(
		'id'         => $pid,
		'human_code' => 'PRJ-' . substr( $pid, -2 ),
		'state'      => 'open',
		'created_at' => '2026-03-02 10:00:00',
		'customer'   => array( 'name' => 'Riley Casey', 'business' => 'Example Co', 'address' => '14 Example Street' ),
	);
}
Zjob_Project_Visibility::$may = array(
	MEMBER => array( P_ONE, P_MANY, P_BARE, P_QUIET, P_LONG, P_EDGE ),
	DEVICE => array( P_ONE ), // even if something said so
);

/* What was written, with what an opened Project must not carry mixed in. */
$dictation = '';
for ( $i = 1; chars( $dictation ) < 9000; $i++ ) {
	$dictation .= sprintf( "Opening %d is 12 by 9 on the north wall, cut to fit, painted white.\n", $i );
}
$dictation .= 'LAST-WORD';
$notes      = "Riley Casey asked for the back wall first.\nMaterials \$1,111.00 and labor 222.00, total 2,222.\nGate code is 4821. Two pieces, 48 in wide.";
$curly      = str_repeat( "\u{201C}6\u{2033}\u{201D} \u{2014}", 9999 ) . 'END'; // 59,997 characters, 139,989 bytes
$paste      = str_repeat( 'Row of the pasted sheet, twelve words long, with nothing in it to take out. ', 933 ); // 70,908 characters
$four       = str_repeat( "\u{1F6E0}", MAX + 1 );
$db         = $GLOBALS['wpdb'];
$db->refs   = array(
	array( P_ONE, 40, 1 ),
	array( P_MANY, 52, 2 ), array( P_MANY, 51, 1 ), array( P_MANY, 53, 3 ), array( P_MANY, 54, 4 ), array( P_MANY, 55, 5 ), array( P_MANY, 56, 6 ),
	array( P_QUIET, 60, 1 ),
	array( P_LONG, 70, 1 ), array( P_LONG, 71, 2 ), array( P_LONG, 72, 3 ),
	array( P_EDGE, 80, 1 ), array( P_EDGE, 81, 2 ),
);
$db->estimates = array(
	40 => array( 'doc_number' => '0001204', 'notes' => $notes, 'input_text' => $dictation ),
	51 => array( 'doc_number' => '0001301', 'notes' => 'First recorded.', 'input_text' => '' ),
	52 => array( 'doc_number' => '', 'billing_doc_num' => 'B-7702', 'notes' => '', 'input_text' => 'Second recorded.' ), // numbered by the billing system
	53 => array( 'doc_number' => '0001303', 'notes' => '   ', 'input_text' => '' ), // nothing written
	54 => array( 'doc_number' => '0001304', 'notes' => 'Fourth recorded.', 'input_text' => '' ),
	55 => array( 'doc_number' => '0001305', 'notes' => 'Fifth recorded: never read.', 'input_text' => '' ),
	56 => array( 'doc_number' => '0001306', 'notes' => 'Sixth recorded: never read.', 'input_text' => '' ),
	60 => array( 'doc_number' => '0001400', 'notes' => '', 'input_text' => '' ),
	70 => array( 'doc_number' => '0001501', 'notes' => $curly, 'input_text' => $paste ),
	71 => array( 'doc_number' => '0001502', 'notes' => 'ok', 'input_text' => $four ),
	72 => array( 'doc_number' => '0001503', 'notes' => "caf\xE9 on the left", 'input_text' => '' ), // one byte that is not text
	// What sits right on the bound: an amount, and an access code.
	80 => array( 'doc_number' => '0001601', 'notes' => str_repeat( 'a', MAX - 6 ) . ' 1111 USD then the rest of the note', 'input_text' => '' ),
	81 => array( 'doc_number' => '0001602', 'notes' => str_repeat( 'b', MAX - 16 ) . ' gate code is 482193 then the rest of the note', 'input_text' => '' ),
);

echo "The estimate app is not there\n";
list( $ok, $d, , $q ) = open_project( MEMBER, P_ONE );
ok( true === $ok && P_ONE === ( $d['project']['id'] ?? '' ), 'the Project still opens' );
ok( array_key_exists( 'said', (array) $d ) && null === $d['said'], 'with no notes: nothing is guessed from anywhere else' );
ok( ! preg_grep( '/zest_estimates/', $q ), 'and its table is not asked' );
$r = Zjob_Project_Resolver::src_said( 0, Zjob_Project::get( P_ONE ), false );
ok( Zjob_Project_Resolver::STATE_UNAVAILABLE === $r['state'] && null === $r['data'], 'the notes are reported as not available, which is not "nothing was written"' );

require $zjob_root . '/zorderz-apps/apps/estimate/includes/class-zest-db.php';

echo "\nA Project with one estimate\n";
list( $ok, $d, , $q ) = open_project( MEMBER, P_ONE );
$e = $d['said']['estimates'][0] ?? array();
ok( true === $ok && 1 === count( $d['said']['estimates'] ?? array() ) && 0 === ( $d['said']['more'] ?? -1 ), 'it carries the estimate\'s words' );
ok( '0001204' === ( $e['estimate_num'] ?? '' ), 'under the estimate\'s number' );
ok( chars( $e['said'] ?? '' ) > 9000 && 'LAST-WORD' === substr( $e['said'], -9 ) && false === strpos( $e['said'], TAIL ), 'a dictation of more than 9,000 characters arrives to its last word' );
ok( $dictation === ( $e['said'] ?? '' ), 'exactly as it was written, line breaks and all' );
ok( "Riley Casey asked for the back wall first.\nMaterials and.\n[gate code sealed]. Two pieces, 48 in wide." === ( $e['notes'] ?? '' ), 'the notes come without their amounts and without the gate code, on the lines they were written on' );
ok( ! preg_match( '/1,111|222|2,222|\$/', (string) ( $e['notes'] ?? '' ) ), 'no amount is in them' );
ok( false === strpos( (string) ( $e['notes'] ?? '' ), '4821' ) && false === strpos( json_encode( $d ), '4821' ), 'the gate code is nowhere in the reply' );
ok( false !== strpos( (string) ( $e['notes'] ?? '' ), '48 in wide' ) && false !== strpos( $e['notes'], 'Two pieces' ), 'a size and a count stay' );
ok( array() === array_filter( $q, static function ( $s ) { return 0 !== strpos( ltrim( $s ), 'SELECT' ); } ) && count( $q ) >= 2, 'opening it writes nothing' );
ok( array( array( ZJOB_NONCE, 'nonce' ) ) === array_slice( $GLOBALS['nonce_seen'], -1 ), 'and the request\'s nonce was checked first' );

echo "\nWho gets nothing\n";
list( $ok, $d, $st, $q ) = open_project( DEVICE, P_ONE );
ok( false === $ok && 403 === $st && array() === $q, 'the shared device is refused before anything is read' );
$asked = Zjob_Project_Visibility::$asked;
list( $ok, $d, $st, $q ) = open_project( STRANGER, P_ONE );
$forbidden = array( $ok, $d, $st );
ok( false === $ok && 404 === $st && Zjob_Project_Visibility::$asked === $asked + 1, 'a person who may not open the Project is refused' );
ok( ! preg_grep( '/zest_estimates|work_item_refs/', $q ), 'and no estimate is read for them' );
ok( $forbidden === array_slice( open_project( STRANGER, P_GONE ), 0, 3 ), 'with the answer a Project that does not exist gets' );
ok( false === strpos( json_encode( $forbidden ), 'Opening' ) && false === strpos( json_encode( $forbidden ), 'Riley' ), 'which holds none of its words' );
$GLOBALS['nonce_ok'] = false;
list( $ok, $d, $st, $q ) = open_project( MEMBER, P_ONE );
ok( false === $ok && 403 === $st && array() === $q, 'a request without its nonce reads nothing' );
$GLOBALS['nonce_ok'] = true;
$GLOBALS['me']       = 0;
list( $ok, $d, $st, $q ) = open_project( 0, P_ONE );
ok( false === $ok && 403 === $st && array() === $q, 'nor does a request from nobody' );

echo "\nNothing written\n";
list( $ok, $d ) = open_project( MEMBER, P_BARE );
ok( true === $ok && null === $d['said'], 'a Project with no estimate opens with no notes' );
list( $ok, $d ) = open_project( MEMBER, P_QUIET );
ok( true === $ok && null === $d['said'], 'so does one whose estimate has no words' );
$r = Zjob_Project_Resolver::src_said( 0, Zjob_Project::get( P_QUIET ), false );
ok( Zjob_Project_Resolver::STATE_EMPTY === $r['state'] && null === $r['data'], 'which is reported as nothing written' );

echo "\nSeveral estimates\n";
list( $ok, $d, , $q ) = open_project( MEMBER, P_MANY );
$nums = array_column( $d['said']['estimates'] ?? array(), 'estimate_num' );
ok( array( '0001301', 'B-7702', '0001304' ) === $nums, 'the first four recorded are read, in that order, and one with no words is left out' );
ok( 2 === ( $d['said']['more'] ?? -1 ), 'the rest are counted, not read' );
ok( 4 === count( preg_grep( '/zest_estimates/', $q ) ) && ! preg_grep( '/id = 5[56]$/', $q ), 'four reads, none past the fourth' );
ok( 'Second recorded.' === ( $d['said']['estimates'][1]['said'] ?? '' ) && '' === ( $d['said']['estimates'][1]['notes'] ?? 'x' ), 'each with what it has' );

echo "\nThe bound\n";
list( $ok, $d ) = open_project( MEMBER, P_LONG );
$es = $d['said']['estimates'] ?? array();
ok( true === $ok && 3 === count( $es ), 'three long estimates open' );
ok( 59997 === chars( $curly ) && strlen( $curly ) > 2 * MAX && $curly === ( $es[0]['notes'] ?? '' ), 'a note under the bound in characters is untouched, however many bytes its marks take' );
$held = (string) ( $es[0]['said'] ?? '' );
ok( chars( $paste ) > MAX + 10000 && rtrim( mb_substr( $paste, 0, MAX, 'UTF-8' ) ) . TAIL === $held, 'a paste over the bound is held to its first sixty thousand characters and ends by saying where the rest is' );
ok( chars( $held ) <= MAX + chars( TAIL ) && chars( $held ) > MAX, 'so the reply is bounded' );
$tools = (string) ( $es[1]['said'] ?? '' );
ok( str_repeat( "\u{1F6E0}", MAX ) . TAIL === $tools, 'sixty thousand and one four-byte characters: sixty thousand whole ones, then the sentence' );
ok( mb_check_encoding( $tools, 'UTF-8' ) && false !== json_encode( $d ), 'the cut is on a character, and the reply still goes out' );
ok( 'ok' === ( $es[1]['notes'] ?? '' ), 'a short note is exactly what was written' );
ok( 'caf on the left' === ( $es[2]['notes'] ?? '' ), 'a byte that is not text is dropped and the note is still shown' );

echo "\nWhat sits on the bound\n";
list( $ok, $d ) = open_project( MEMBER, P_EDGE );
$es   = $d['said']['estimates'] ?? array();
$cash = (string) ( $es[0]['notes'] ?? '' );
ok( 2 === count( $es ) && false !== strpos( $cash, 'rest is on the estimate' ), 'both notes reach the bound' );
ok( ! preg_match( '/\d|USD/', $cash ), 'an amount that the bound would have cut from its currency is gone whole: no digit' );
$code = (string) ( $es[1]['notes'] ?? '' );
ok( false !== strpos( $code, 'rest is on the estimate' ) && ! preg_match( '/\d/', $code ), 'an access code that the bound would have cut in two is gone whole: no digit' );
ok( false !== strpos( $code, '[gate' ), 'with its label where it was' );

echo "\nA Privacy View account\n";
ZDZ_Privacy_View::$on = true;
list( $ok, $d, , $q ) = open_project( MEMBER, P_ONE );
$e = $d['said']['estimates'][0] ?? array();
ok( true === $ok && false === strpos( json_encode( $d ), 'Riley' ) && false === strpos( json_encode( $d ), 'Casey' ), 'the customer\'s name is nowhere in the reply, the notes included' );
ok( 0 === strpos( (string) ( $e['notes'] ?? '' ), 'Customer-K7 asked for the back wall first.' ), 'the notes read with the pseudonym in its place' );
ok( 'EST-X4' === ( $e['estimate_num'] ?? '' ), 'the estimate\'s number is a document pseudonym' );
ok( 'LAST-WORD' === substr( (string) ( $e['said'] ?? '' ), -9 ), 'and the dictation is still whole' );
ok( array() === array_filter( $q, static function ( $s ) { return 0 !== strpos( ltrim( $s ), 'SELECT' ); } ), 'opening it writes nothing' );
ZDZ_Privacy_View::$on = false;

echo "\n" . str_repeat( '=', 56 ) . "\n";
printf( "  %d passed, %d failed\n", $GLOBALS['__pass'], $GLOBALS['__fail'] );
if ( $GLOBALS['__fail'] > 0 ) {
	foreach ( $GLOBALS['__fails'] as $f ) { echo "    - $f\n"; }
	exit( 1 );
}
echo "  ALL GREEN\n";
exit( 0 );
