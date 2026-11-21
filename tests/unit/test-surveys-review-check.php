<?php
/**
 * Surveys: what a review check answers, so the page can say it in one plain sentence and can tell
 * whether asking again at once would help.
 *
 *   checked   lookups that completed (found, or a clean "no review"): stamped, not looked at
 *             again for six hours;
 *   found     of those, reviews found and recorded;
 *   errors    customers the review bridge gave no answer for: NOT stamped and NOT counted as
 *             checked, so they are looked at again by the next check;
 *   deferred  customers this pass did not start: not stamped either;
 *   error     set when the check could not run at all (and the counts are all there, as zeros).
 *
 * And who may press it: a signed request from an administrator, nobody else.
 *
 * Standalone (its own PHP process): it stands in for the review bridge and the service breaker,
 * which other in-process tests load for real. Run: php tests/unit/test-surveys-review-check.php
 *
 * Neutral data only.
 */

error_reporting( E_ALL & ~E_DEPRECATED );
require __DIR__ . '/../bootstrap-unit.php';

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
define( 'ZSV_NONCE', 'zsv_nonce' );
function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
function wp_cache_delete( ...$a ) { return true; }
function get_current_user_id() { return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ); }
function wp_date( $f, $ts = null ) { return gmdate( $f, null === $ts ? time() : (int) $ts ); }

/** What a handler answered: the reply helpers end it, as WordPress does. */
class Reply extends RuntimeException {
	public $ok;
	public $data;
	public function __construct( bool $ok, $data ) {
		parent::__construct( 'reply' );
		$this->ok   = $ok;
		$this->data = $data;
	}
}
function wp_send_json_success( $d = null ) { throw new Reply( true, $d ); }
function wp_send_json_error( $d = null ) { throw new Reply( false, $d ); }
function check_ajax_referer( $action = -1, $arg = false ) {
	$GLOBALS['referer'][] = array( $action, $arg );
	if ( ( $_POST[ (string) $arg ] ?? '' ) !== 'good-' . $action ) {
		throw new Reply( false, 'bad nonce' );
	}
	return 1;
}

/** The review bridge, scripted: an address => found | none | silent (no answer). */
class ZDZ_Core_ReviewBridge {
	public static $set_up  = true;
	public static $answers = array();
	public static $asked   = array();
	public static $bypass  = array();
	public function is_configured(): bool { return self::$set_up; }
	public function check_review( string $email, string $name = '', bool $bypass_cache = false ) {
		self::$asked[]  = $email;
		self::$bypass[] = $bypass_cache;
		ZSV_Budget::observe( 0.01, true );
		if ( null !== Zdz_Service_Breaker::$open_after && count( self::$asked ) >= Zdz_Service_Breaker::$open_after ) {
			Zdz_Service_Breaker::$open = true;   // the next customer is not started
		}
		$a = self::$answers[ $email ] ?? 'none';
		if ( 'silent' === $a ) {
			return null;
		}
		return 'found' === $a
			? array( 'found' => true, 'date' => '2026-03-08T15:30:00+00:00', 'snippet' => 'Tidy work, on time.' )
			: array( 'found' => false );
	}
}

/** The breaker that stops a run starting another customer. */
class Zdz_Service_Breaker {
	public static $open       = false;
	public static $open_after = null;
	public static function is_open( $service ): bool { return self::$open; }
	public static function record_success( $service ): void {}
	public static function record_failure( $service ): void {}
}

/** The survey table in memory. */
class Leads_DB {
	public $prefix = 'wp_';
	public $rows   = array();
	public function prepare( $q, ...$a ) { return array( 'q' => $q, 'a' => $a ); }
	public function get_var( $p ) { return '1'; }   // the owed-note store's lock is free
	public function get_results( $p, $out = null ) {
		$q = is_array( $p ) ? $p['q'] : (string) $p;
		if ( false === strpos( $q, 'review_checked_at IS NULL OR review_checked_at < %s' ) ) {
			return array();
		}
		$cut = (string) $p['a'][0];
		$hit = array_filter(
			$this->rows,
			static function ( $r ) use ( $cut ) {
				return null !== $r['email_sent_at'] && empty( $r['review_left'] ) && ( null === $r['review_checked_at'] || $r['review_checked_at'] < $cut );
			}
		);
		usort( $hit, static function ( $x, $y ) { return strcmp( $y['email_sent_at'], $x['email_sent_at'] ); } );
		return array_values( $hit );
	}
	public function update( $t, $data, $where, $f = null, $wf = null ) {
		$id = (int) $where['id'];
		if ( ! isset( $this->rows[ $id ] ) ) {
			return 0;
		}
		$this->rows[ $id ] = array_merge( $this->rows[ $id ], $data );
		return 1;
	}
	public function query( $p ) { return 1; }
	public function get_row( $p, $out = null ) { return null; }
}

$root = dirname( __DIR__, 2 );
$inc  = $root . '/zorderz-apps/apps/surveys/includes/';
foreach ( array( 'db', 'settings', 'budget', 'owed', 'note-reader', 'send-once', 'survey-manager', 'review-checker' ) as $f ) {
	require $inc . 'class-zsv-' . $f . '.php';
}

$pass = 0; $fail = 0;
function ok( bool $c, string $m, $shown = null ): void {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ( null === $shown ? '' : ' -- ' . json_encode( $shown ) ) . "\n"; }
}
function lead( int $id, string $sent, ?string $checked = null, int $reviewed = 0 ): array {
	return array(
		'id' => $id, 'email' => 'customer' . $id . '@example.com', 'first_name' => 'Riley', 'last_name' => 'Example ' . $id, 'crm_lead_id' => '0',
		'email_sent_at' => $sent, 'review_left' => $reviewed, 'review_source' => null, 'review_date' => null, 'review_snippet' => null, 'review_checked_at' => $checked,
	);
}
function fresh( array $rows ): Leads_DB {
	$db = new Leads_DB();
	foreach ( $rows as $r ) {
		$db->rows[ $r['id'] ] = $r;
	}
	$GLOBALS['wpdb']             = $db;
	$GLOBALS['zdz_test_options'] = array();
	ZDZ_Core_ReviewBridge::$set_up  = true;
	ZDZ_Core_ReviewBridge::$answers = array();
	ZDZ_Core_ReviewBridge::$asked   = array();
	ZDZ_Core_ReviewBridge::$bypass  = array();
	Zdz_Service_Breaker::$open       = false;
	Zdz_Service_Breaker::$open_after = null;
	ZSV_Budget::close();
	return $db;
}
function quiet( callable $fn ) {
	$tmp  = tempnam( sys_get_temp_dir(), 'zsv-rc-' );
	$prev = ini_set( 'error_log', $tmp );
	try {
		return $fn();
	} finally {
		ini_set( 'error_log', false === $prev ? '' : (string) $prev );
		unlink( $tmp );
	}
}
$recent = gmdate( 'Y-m-d H:i:s', time() - 3600 );      // checked an hour ago: not due
$stale  = gmdate( 'Y-m-d H:i:s', time() - 7 * 3600 );  // checked seven hours ago: due again

// ── the review bridge is not set up ────────────────────────────────────────
$db = fresh( array( lead( 1, '2026-03-01 10:00:00' ) ) );
ZDZ_Core_ReviewBridge::$set_up = false;
$r = quiet( static function () { return ZSV_Review_Checker::check_pending_leads( true ); } );
ok( array( 'checked' => 0, 'found' => 0, 'errors' => 0, 'deferred' => 0, 'error' => 'The review bridge is not set up.' ) === $r, 'not set up: said in a sentence, with every count there as zero', $r );
ok( array() === ZDZ_Core_ReviewBridge::$asked && null === $db->rows[1]['review_checked_at'], 'and nobody was looked up or stamped' );

// ── nobody is due ──────────────────────────────────────────────────────────
$db = fresh( array( lead( 1, '2026-03-01 10:00:00', $recent ), lead( 2, '2026-03-01 11:00:00', null, 1 ), array_merge( lead( 3, '2026-03-01 12:00:00' ), array( 'email_sent_at' => null ) ) ) );
$r  = quiet( static function () { return ZSV_Review_Checker::check_pending_leads( true ); } );
ok( array( 'checked' => 0, 'found' => 0, 'errors' => 0, 'deferred' => 0 ) === $r, 'checked in the last six hours, already reviewed, or never e-mailed: nobody to check', $r );
ok( array() === ZDZ_Core_ReviewBridge::$asked, 'the bridge was not asked' );

// ── found, a clean no, and no answer ───────────────────────────────────────
$db = fresh( array( lead( 1, '2026-03-01 10:00:00' ), lead( 2, '2026-03-02 10:00:00', $stale ), lead( 3, '2026-03-03 10:00:00' ) ) );
ZDZ_Core_ReviewBridge::$answers = array( 'customer1@example.com' => 'found', 'customer2@example.com' => 'none', 'customer3@example.com' => 'silent' );
$r = quiet( static function () { return ZSV_Review_Checker::check_pending_leads( true ); } );
ok( array( 'checked' => 2, 'found' => 1, 'errors' => 1, 'deferred' => 0 ) === $r, 'two lookups completed, one review, one customer the bridge gave no answer for', $r );
ok( array( 'customer3@example.com', 'customer2@example.com', 'customer1@example.com' ) === ZDZ_Core_ReviewBridge::$asked, 'most recently e-mailed first' );
ok( array( true, true, true ) === ZDZ_Core_ReviewBridge::$bypass, 'a person\'s press asks the bridge afresh' );
ok( null !== $db->rows[1]['review_checked_at'] && null !== $db->rows[2]['review_checked_at'] && $stale !== $db->rows[2]['review_checked_at'], 'a completed lookup is stamped' );
ok( null === $db->rows[3]['review_checked_at'], 'no answer is not a lookup: that customer is not stamped' );
ok( 1 === $db->rows[1]['review_left'] && 'review_bridge' === $db->rows[1]['review_source'] && '2026-03-08 15:30:00' === $db->rows[1]['review_date'] && 'Tidy work, on time.' === $db->rows[1]['review_snippet'], 'the review is recorded with its date and first words', $db->rows[1] );
ok( 0 === $db->rows[2]['review_left'] && 0 === $db->rows[3]['review_left'], 'and on nobody else' );

ZDZ_Core_ReviewBridge::$asked   = array();
ZDZ_Core_ReviewBridge::$answers = array( 'customer3@example.com' => 'none' );
$r = quiet( static function () { return ZSV_Review_Checker::check_pending_leads( true ); } );
ok( array( 'customer3@example.com' ) === ZDZ_Core_ReviewBridge::$asked, 'the next check looks only at the customer who got no answer' );
ok( array( 'checked' => 1, 'found' => 0, 'errors' => 0, 'deferred' => 0 ) === $r, 'and counts that lookup once', $r );

// ── a pass that does not get to everybody ──────────────────────────────────
$db = fresh( array( lead( 1, '2026-03-01 10:00:00' ), lead( 2, '2026-03-02 10:00:00' ), lead( 3, '2026-03-03 10:00:00' ), lead( 4, '2026-03-04 10:00:00' ) ) );
Zdz_Service_Breaker::$open_after = 1;   // after the first customer, no other is started
$r = quiet( static function () { return ZSV_Review_Checker::check_pending_leads( true ); } );
ok( array( 'checked' => 1, 'found' => 0, 'errors' => 0, 'deferred' => 3 ) === $r, 'one checked, three not started', $r );
ok( array( 'customer4@example.com' ) === ZDZ_Core_ReviewBridge::$asked, 'the bridge was asked for the one' );
ok( null !== $db->rows[4]['review_checked_at'] && null === $db->rows[1]['review_checked_at'] && null === $db->rows[2]['review_checked_at'] && null === $db->rows[3]['review_checked_at'], 'a customer not started is not stamped' );
Zdz_Service_Breaker::$open       = false;
Zdz_Service_Breaker::$open_after = null;
ZDZ_Core_ReviewBridge::$asked    = array();
$r = quiet( static function () { return ZSV_Review_Checker::check_pending_leads( true ); } );
ok( array( 'checked' => 3, 'found' => 0, 'errors' => 0, 'deferred' => 0 ) === $r, 'the next pass checks the three that were left, and only those', $r );
ok( ! ZSV_Budget::is_open(), 'no time budget is left open' );

// ── the hourly path does not bypass the bridge's own memory ────────────────
$db = fresh( array( lead( 1, '2026-03-01 10:00:00' ) ) );
quiet( static function () { ZSV_Review_Checker::run(); } );
ok( array( false ) === ZDZ_Core_ReviewBridge::$bypass, 'the scheduled check lets the bridge use what it remembers' );

// ── who may press it ───────────────────────────────────────────────────────
function press( array $post ): array {
	$_POST = $post;
	try {
		quiet( static function () { ZSV_Review_Checker::ajax_check(); } );
	} catch ( Reply $r ) {
		return array( $r->ok, $r->data );
	} finally {
		$_POST = array();
	}
	return array( null, null );
}
$GLOBALS['zdz_test_user_roles'] = array( 61 => array( 'administrator' ), 62 => array( 'zdz_operator' ) );
$db = fresh( array( lead( 1, '2026-03-01 10:00:00' ) ) );
$GLOBALS['zdz_test_current_user'] = 61;
ok( array( false, 'bad nonce' ) === press( array( 'nonce' => 'wrong' ) ), 'no nonce, no check, even for an administrator' );
ok( array( ZSV_NONCE, 'nonce' ) === end( $GLOBALS['referer'] ), 'the Surveys nonce is the one asked for' );
$GLOBALS['zdz_test_current_user'] = 62;
ok( array( false, 'Unauthorized' ) === press( array( 'nonce' => 'good-' . ZSV_NONCE ) ), 'somebody who is not an administrator is refused' );
$GLOBALS['zdz_test_current_user'] = 0;
ok( array( false, 'Unauthorized' ) === press( array( 'nonce' => 'good-' . ZSV_NONCE ) ), 'and so is nobody' );
ok( array() === ZDZ_Core_ReviewBridge::$asked && null === $db->rows[1]['review_checked_at'], 'a refused press looks nobody up' );
$GLOBALS['zdz_test_current_user'] = 61;
list( $ok_, $d ) = press( array( 'nonce' => 'good-' . ZSV_NONCE ) );
ok( true === $ok_ && array( 'checked' => 1, 'found' => 0, 'errors' => 0, 'deferred' => 0 ) === $d, 'an administrator\'s press runs the check and answers the four counts', $d );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
