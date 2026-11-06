<?php
/**
 * Zorderz → Settings no longer holds the Ai gateway, CRM or billing credentials.
 *
 * Standalone (its own PHP process: it loads the real ZDZ_Core_Settings, which the unit bootstrap stands in for).
 * Run: php tests/unit/test-settings-secrets.php
 *
 *   - The form registers only its own options: never a credential that Zorderz → Credentials owns (options.php writes
 *     every registered option of the group, so a registered credential missing from the form would be emptied).
 *   - The page prints no stored secret, no input for a moved credential, and a link to Zorderz → Credentials.
 *   - The Review Bridge key is write-only: blank keeps it, a new value is sealed at rest, and reads open it.
 *   - Reads of the moved credentials are unchanged (get_credential()).
 *   - The Google count keeps the date it was typed, only when it changed or was confirmed.
 *
 * Neutral values only.
 */

error_reporting( E_ALL & ~E_DEPRECATED );
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['opts']       = array();
$GLOBALS['registered'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function register_setting( $group, $name, $args = array() ) { $GLOBALS['registered'][ $name ] = $args; }
function add_action( ...$a ) { return true; }
function add_filter( ...$a ) { return true; }
function do_action( ...$a ) { return null; }
function apply_filters( $h, $v, ...$a ) { return $v; }
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_attr__( $s, $d = null ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_html_e( $s, $d = null ) { echo htmlspecialchars( $s, ENT_QUOTES ); }
function esc_attr_e( $s, $d = null ) { echo htmlspecialchars( $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function admin_url( $p = '' ) { return 'https://app.example.com/wp-admin/' . $p; }
function home_url( $p = '' ) { return 'https://app.example.com/' . ltrim( $p, '/' ); }
function current_user_can( $c ) { return true; }
function settings_fields( $g ) { echo '<input type="hidden" name="option_page" value="' . $g . '">'; }
function do_settings_sections( $g ) {}
function submit_button() { echo '<button>Save</button>'; }
function selected( $a, $b, $e = true ) { return $a === $b ? ' selected' : ''; }
function checked( $a, $b = true, $e = true ) { return ''; }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
function absint( $v ) { return abs( (int) $v ); }
function wp_date( $f, $t ) { return gmdate( $f, $t ); }
function wp_salt( $s = 'auth' ) { return 'unit-test-salt::' . $s; }
function get_user_meta( ...$a ) { return ''; }
function is_wp_error( $x ) { return false; }

require __DIR__ . '/../../zorderz/inc/class-zdz-core-crypto.php';
require __DIR__ . '/../../zorderz/inc/class-zdz-core-settings.php';

$pass = 0; $fail = 0;
function ok( bool $c, string $m ): void {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m\n"; }
}

$moved = array( 'poe_api_key', 'fb_client_id', 'fb_client_secret', 'fb_access_token', 'fb_refresh_token', 'fb_account_id', 'ns_email', 'ns_api_key' );

// ── What the form registers ───────────────────────────────────────────────
$s = ZDZ_Core_Settings::get_instance();
$s->register_settings();
foreach ( $moved as $f ) {
	ok( ! isset( $GLOBALS['registered'][ 'zdz_core_' . $f ] ), "the form does not register $f (Credentials owns it)" );
}
foreach ( array( 'ai_model', 'review_bridge_url', 'review_bridge_key', 'google_reviews_count' ) as $f ) {
	ok( isset( $GLOBALS['registered'][ 'zdz_core_' . $f ] ), "the form still registers $f" );
}
ok( ! isset( $GLOBALS['registered']['zdz_core_website_reviews_count'] ), 'the website count is not typed any more' );

// ── What the page prints ──────────────────────────────────────────────────
$secrets = array(
	'zdz_core_poe_api_key'      => 'gw-key-EXAMPLE-0001',
	'zdz_core_ns_email'         => 'crm-login@example.com',
	'zdz_core_ns_api_key'       => 'crm-key-EXAMPLE-0002',
	'zdz_core_fb_client_secret' => 'billing-secret-EXAMPLE-0003',
	'zdz_core_fb_access_token'  => 'billing-access-EXAMPLE-0004',
	'zdz_core_fb_refresh_token' => 'billing-refresh-EXAMPLE-0005',
	'zdz_core_fb_client_id'     => 'billing-client-EXAMPLE-0006',
	'zdz_core_fb_account_id'    => 'ACCT-EXAMPLE-0007',
);
foreach ( $secrets as $k => $v ) {
	$GLOBALS['opts'][ $k ] = in_array( substr( $k, 9 ), ZDZ_Core_Settings::secret_fields(), true ) ? ZDZ_Core_Settings::seal_secret( $v ) : $v;
}
$GLOBALS['opts']['zdz_core_review_bridge_url'] = 'https://www.example.com/wp-json/bridge/v1';
$GLOBALS['opts']['zdz_core_review_bridge_key'] = ZDZ_Core_Settings::seal_secret( 'bridge-key-EXAMPLE-0008' );
ob_start();
$s->render_settings();
$html = ob_get_clean();
foreach ( $secrets + array( 'rb' => 'bridge-key-EXAMPLE-0008' ) as $k => $v ) {
	ok( false === strpos( $html, $v ), "the page never prints $k" );
	if ( 'rb' !== $k ) {
		ok( false === strpos( $html, (string) $GLOBALS['opts'][ $k ] ) || $GLOBALS['opts'][ $k ] === $v, "the page never prints $k as stored" );
	}
}
foreach ( $moved as $f ) {
	ok( false === strpos( $html, 'name="zdz_core_' . $f . '"' ), "no input for $f" );
}
ok( false !== strpos( $html, 'page=zdz-core-credentials' ), 'the page links to Zorderz → Credentials' );
ok( (bool) preg_match( '/name="zdz_core_review_bridge_key"[^>]*value=""/', $html ) || (bool) preg_match( '/value=""[^>]*name="zdz_core_review_bridge_key"/', $html ) || false !== strpos( $html, 'name="zdz_core_review_bridge_key" value=""' ), 'the bridge key field is empty' );
ok( false !== strpos( $html, 'https://www.example.com/wp-json/bridge/v1' ), 'the bridge URL (not a secret) is shown' );

// ── Reads are unchanged ───────────────────────────────────────────────────
ok( 'gw-key-EXAMPLE-0001' === ZDZ_Core_Settings::get_poe_api_key(), 'the gateway key still reads (opened)' );
ok( 'crm-key-EXAMPLE-0002' === ZDZ_Core_Settings::get_ns_api_key(), 'the CRM key still reads (opened)' );
ok( 'ACCT-EXAMPLE-0007' === ZDZ_Core_Settings::get_fb_account_id(), 'the billing account still reads' );
ok( 'bridge-key-EXAMPLE-0008' === ZDZ_Core_Settings::get_review_bridge_key(), 'the bridge key reads opened, never as stored' );

// ── The bridge key is write-only ──────────────────────────────────────────
$stored = (string) $GLOBALS['opts']['zdz_core_review_bridge_key'];
ok( $stored === ZDZ_Core_Settings::sanitize_secret_submit( '', $stored ), 'a blank submit keeps the stored key' );
ok( $stored === ZDZ_Core_Settings::sanitize_secret_submit( '   ', $stored ), 'spaces are blank' );
$new = ZDZ_Core_Settings::sanitize_secret_submit( 'bridge-key-EXAMPLE-0009', $stored );
ok( 'bridge-key-EXAMPLE-0009' !== $new && '' !== $new, 'a new key is sealed at rest' );
ok( 'bridge-key-EXAMPLE-0009' === ZDZ_Core_Crypto::plain( $new ), 'and opens to what was typed' );
ok( $new === ZDZ_Core_Settings::seal_secret( $new ), 'a sealed value is never sealed twice' );

// ── The Google count's date ───────────────────────────────────────────────
$GLOBALS['opts']['zdz_core_google_reviews_count'] = 40;
unset( $GLOBALS['opts']['zdz_core_google_reviews_entered_at'] );
ZDZ_Core_Settings::sanitize_google_reviews_count( '40' );
ok( ! isset( $GLOBALS['opts']['zdz_core_google_reviews_entered_at'] ), 'the same number, not confirmed: no date' );
ZDZ_Core_Settings::sanitize_google_reviews_count( '41' );
ok( (int) ( $GLOBALS['opts']['zdz_core_google_reviews_entered_at'] ?? 0 ) > 0, 'a new number keeps the date' );
unset( $GLOBALS['opts']['zdz_core_google_reviews_entered_at'] );
$_POST['zdz_core_google_reviews_confirm'] = '1';
ZDZ_Core_Settings::sanitize_google_reviews_count( '40' );
ok( (int) ( $GLOBALS['opts']['zdz_core_google_reviews_entered_at'] ?? 0 ) > 0, '"I checked Google today" keeps the date' );
unset( $_POST['zdz_core_google_reviews_confirm'] );
ok( 7 === ZDZ_Core_Settings::sanitize_google_reviews_count( '-7' ), 'a count is never negative' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
