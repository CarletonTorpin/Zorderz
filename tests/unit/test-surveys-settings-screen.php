<?php
/**
 * Surveys: the settings screen's Google reviews section and its "Review e-mails already sent"
 * list, the one place a person can ask for a second e-mail to a customer.
 *
 *   - the screen draws nothing for anybody but an administrator, and its script is loaded on
 *     this screen only, for an administrator only;
 *   - the two review windows are settings with their defaults, kept inside their ranges;
 *   - the Google line says whether the site is connected and which way recording works;
 *   - every customer in the list is text (a name is never markup), each with one button that
 *     carries that customer's own number, name and time; nobody e-mailed, no list.
 *
 * Standalone (its own PHP process): it stands in for the WordPress settings functions. Run:
 * php tests/unit/test-surveys-settings-screen.php
 *
 * Neutral data only.
 */

error_reporting( E_ALL & ~E_DEPRECATED );
require __DIR__ . '/../bootstrap-unit.php';

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
define( 'ZSV_NONCE', 'zsv_nonce' );
define( 'ZSV_URL', 'https://app.example.com/apps/surveys/' );
function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
function get_current_user_id() { return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ); }
function maybe_unserialize( $v ) {
	if ( is_string( $v ) ) {
		$u = @unserialize( $v, array( 'allowed_classes' => false ) );   // sentinel:allow the test stand-in for WordPress's maybe_unserialize(); no objects
		if ( false !== $u || 'b:0;' === $v ) {
			return $u;
		}
	}
	return $v;
}
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html_e( $s, $d = null ) { echo esc_html( $s ); }
function esc_attr_e( $s, $d = null ) { echo esc_attr( $s ); }
function esc_url_raw( $s ) { return (string) $s; }
function selected( $a, $b = true ) { if ( (string) $a === (string) $b ) { echo ' selected="selected"'; } }
function checked( $a, $b = true ) { if ( (string) $a === (string) $b ) { echo ' checked="checked"'; } }
function settings_fields( $g ) { echo '<input type="hidden" name="option_page" value="' . esc_attr( $g ) . '">'; }
function submit_button( $t = 'Save' ) { echo '<button type="submit">Save</button>'; }
function wp_dropdown_users( $a ) { echo '<select name="' . esc_attr( $a['name'] ) . '"></select>'; }
function admin_url( $p = '' ) { return 'https://app.example.com/wp-admin/' . $p; }
function wp_create_nonce( $a = -1 ) { return 'nonce-for-' . $a; }
function zsv_asset_ver( string $rel ): string { return '1'; }
function register_setting( $group, $name, $args = array() ) { $GLOBALS['registered'][ $name ] = array( $group, $args ); }
function wp_enqueue_script( ...$a ) { $GLOBALS['scripts'][ (string) $a[0] ] = (string) $a[1]; }
function wp_localize_script( $handle, $name, $data ) { $GLOBALS['localized'][ $handle ] = array( $name, $data ); }

/** The survey table and the options table in memory. */
class Sent_DB {
	public $prefix  = 'wp_';
	public $options = 'wp_options';
	public $rows    = array();
	public function prepare( $q, ...$a ) {
		if ( 1 === count( $a ) && is_array( $a[0] ) ) {
			$a = $a[0];
		}
		return array( 'q' => $q, 'a' => $a );
	}
	public function get_var( $p ) {
		$n = (string) ( $p['a'][0] ?? '' );
		return array_key_exists( $n, (array) ( $GLOBALS['zdz_test_options'] ?? array() ) ) ? serialize( $GLOBALS['zdz_test_options'][ $n ] ) : null;
	}
	public function get_results( $p, $out = null ) {
		$q = $p['q'];
		if ( false !== strpos( $q, 'WHERE id IN (' ) ) {
			$ids = array_map( 'intval', $p['a'] );
			return array_values( array_filter( $this->rows, static function ( $r ) use ( $ids ) { return in_array( (int) $r['id'], $ids, true ); } ) );
		}
		$hit = array_filter( $this->rows, static function ( $r ) { return null !== $r['email_sent_at']; } );
		usort( $hit, static function ( $x, $y ) { return strcmp( (string) $y['email_sent_at'], (string) $x['email_sent_at'] ); } );
		return array_slice( $hit, 0, (int) $p['a'][0] );
	}
}

$root = dirname( __DIR__, 2 );
$inc  = $root . '/zorderz-apps/apps/surveys/includes/';
foreach ( array( 'db', 'settings', 'budget', 'owed', 'note-reader', 'send-once', 'survey-manager', 'dashboard', 'google-reviews', 'admin' ) as $f ) {
	require $inc . 'class-zsv-' . $f . '.php';
}

const ADMIN    = 71;
const OPERATOR = 72;

$pass = 0; $fail = 0;
function ok( bool $c, string $m, $shown = null ): void {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ( null === $shown ? '' : ' -- ' . substr( (string) json_encode( $shown ), 0, 400 ) ) . "\n"; }
}
function screen(): string {
	ob_start();
	ZSV_Admin::render();
	return (string) ob_get_clean();
}
function fresh(): Sent_DB {
	$GLOBALS['zdz_test_user_roles']   = array( ADMIN => array( 'administrator' ), OPERATOR => array( 'zdz_operator' ) );
	$GLOBALS['zdz_test_current_user'] = ADMIN;
	$GLOBALS['zdz_test_options']      = array();
	$GLOBALS['scripts']               = array();
	$GLOBALS['localized']             = array();
	$GLOBALS['wpdb']                  = new Sent_DB();
	return $GLOBALS['wpdb'];
}
function sent( int $id, string $first, string $last, ?string $at, ?string $log = null ): array {
	return array( 'id' => $id, 'first_name' => $first, 'last_name' => $last, 'email' => 'customer' . $id . '@example.com', 'email_sent_at' => $at, 'email_send_log' => $log );
}

// ── who the screen is for ──────────────────────────────────────────────────
$db = fresh();
$GLOBALS['zdz_test_current_user'] = OPERATOR;
ok( '' === screen(), 'the screen draws nothing for somebody who is not an administrator' );
ZSV_Admin::assets( 'settings_page_' . ZSV_Admin::PAGE );
ok( array() === $GLOBALS['scripts'], 'and loads no script for them' );
$GLOBALS['zdz_test_current_user'] = ADMIN;
ZSV_Admin::assets( 'index.php' );
ZSV_Admin::assets( 'settings_page_another' );
ok( array() === $GLOBALS['scripts'], 'on any other screen the script is not loaded' );
ZSV_Admin::assets( 'settings_page_' . ZSV_Admin::PAGE );
ok( array( 'zsv-admin' => ZSV_URL . 'assets/js/admin.js' ) === $GLOBALS['scripts'], 'on the Surveys settings screen, for an administrator, it is' );
list( $name, $data ) = $GLOBALS['localized']['zsv-admin'];
ok( 'zsvAdmin' === $name && 'nonce-for-' . ZSV_NONCE === $data['nonce'] && 'https://app.example.com/wp-admin/admin-ajax.php' === $data['ajaxurl'] && ZSV_Admin::send_again_words() === $data['words'], 'with the Surveys nonce and its sentences' );

// ── the review windows ─────────────────────────────────────────────────────
$GLOBALS['registered'] = array();
ZSV_Admin::register();
foreach ( array( 'zsv_greviews_lookback_days' => array( 60, '365', 365 ), 'zsv_greviews_candidate_days' => array( 120, '730', 730 ) ) as $opt => $want ) {
	$reg = $GLOBALS['registered'][ $opt ] ?? null;
	ok( is_array( $reg ) && ZSV_Admin::GROUP === $reg[0] && is_callable( $reg[1]['sanitize_callback'] ?? null ), "$opt is a setting of this screen, with a check on what is typed" );
	$clean = $reg[1]['sanitize_callback'];
	ok( $want[0] === $clean( '' ) && $want[0] === $clean( '0' ) && $want[0] === $clean( 'many' ) && $want[0] === $clean( '99999' ) && $want[2] === $clean( $want[1] ) && 30 === $clean( '30' ), "$opt: whole days in range, anything else is the default ({$want[0]})" );
}
ok( ! isset( $GLOBALS['registered']['zsv_greviews_auto'] ), 'automatic recording is not a field of this screen: it is switched in the widget, with a question' );

$db = fresh();
$h  = screen();
ok( 1 === preg_match( '/name="zsv_greviews_lookback_days" value="60" min="1" max="365"/', $h ), 'the look-back shows its default, 60 days' );
ok( 1 === preg_match( '/name="zsv_greviews_candidate_days" value="120" min="1" max="730"/', $h ), 'the candidate window shows its default, 120 days' );
$GLOBALS['zdz_test_options'] = array( 'zsv_greviews_lookback_days' => 30, 'zsv_greviews_candidate_days' => 200 );
$h = screen();
ok( false !== strpos( $h, 'name="zsv_greviews_lookback_days" value="30"' ) && false !== strpos( $h, 'name="zsv_greviews_candidate_days" value="200"' ), 'and what was saved' );

// ── the Google line ────────────────────────────────────────────────────────
$db = fresh();
ok( 0 === strpos( ZSV_Admin::google_reviews_line(), 'Not connected.' ) && false !== strpos( screen(), 'Not connected. Connect Google Business Profile under Zorderz' ), 'not connected: the screen says so, and where to connect' );

// ── nobody e-mailed yet ────────────────────────────────────────────────────
$db = fresh();
$db->rows = array( 1 => sent( 1, 'Riley', 'Example', null ) );
$h = screen();
ok( false !== strpos( $h, 'No review e-mail has been sent yet.' ) && false === strpos( $h, 'id="zsv-sent"' ) && false === strpos( $h, 'zsv-send-again' ), 'nobody e-mailed: no list and no button' );

// ── the list ───────────────────────────────────────────────────────────────
$db = fresh();
$db->rows = array(
	1 => sent( 1, 'Riley', 'Example', '2026-03-05 09:30:12', '["2026-03-05 09:30:12"]' ),
	2 => sent( 2, 'Sam', 'Example', '2026-03-07 14:00:00', '["2026-03-01 08:00:00","2026-03-07 14:00:00"]' ),
	3 => sent( 3, 'Casey', 'Example', null ),
	4 => sent( 4, '<b>Blake</b>', 'O\'Example "x"', '2026-03-02 10:00:00' ),
	5 => sent( 5, '', '', '2026-03-01 10:00:00' ),
);
$h = screen();
ok( 1 === substr_count( $h, 'id="zsv-sent"' ), 'one list' );
ok( 4 === substr_count( $h, 'class="button zsv-send-again"' ), 'one button for each customer e-mailed, none for a customer never e-mailed' );
ok( false !== strpos( $h, 'data-id="2" data-name="Sam Example" data-at="2026-03-07 14:00">' ), 'the button carries that customer\'s own number, name and time' );
ok( 1 === preg_match( '#<td>Sam Example</td>\s*<td>2026-03-07 14:00</td>\s*<td class="zsv-sends">2</td>#', $h ), 'the row says when they were e-mailed and how many times' );
ok( at_of( $h, 'data-id="2"' ) < at_of( $h, 'data-id="1"' ) && at_of( $h, 'data-id="1"' ) < at_of( $h, 'data-id="4"' ), 'most recently e-mailed first' );
ok( false === strpos( $h, '<b>Blake</b>' ) && false !== strpos( $h, '&lt;b&gt;Blake&lt;/b&gt; O&#039;Example &quot;x&quot;' ), 'a name is text, in the row and in the button' );
ok( false !== strpos( $h, 'data-id="5" data-name="#5"' ), 'a customer with no name is shown by number' );
ok( false === strpos( $h, 'example.com' ), 'no address is on the screen' );
ok( false === strpos( $h, 'data-id="3"' ), 'a customer never e-mailed cannot be sent "again"' );
ok( 4 === substr_count( $h, 'class="zsv-send-again-result" role="status" aria-live="polite"' ), 'each button has its own place for what happened' );
ok( substr_count( $h, '<table' ) === substr_count( $h, '</table>' ) && substr_count( $h, '<tr' ) === substr_count( $h, '</tr>' ) && substr_count( $h, '<div' ) === substr_count( $h, '</div>' ), 'the markup is balanced' );

function at_of( string $h, string $needle ): int {
	$p = strpos( $h, $needle );
	return false === $p ? -1 : $p;
}

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
