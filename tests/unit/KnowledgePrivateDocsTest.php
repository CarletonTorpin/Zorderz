<?php
/**
 * Knowledge app: Private documents, outside links, file names, the chat rule, mail-in routing
 * and the model / key rules — the pure decisions and the deny-by-default gates.
 *
 * Neutral data only. The crypto runs the real libsodium code against a temporary folder.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ZKV_PRIVATE_KEK' ) ) {
	define( 'ZKV_PRIVATE_KEK', str_repeat( '3c', 32 ) ); // 64 hex: a test key-encryption key, never a real one
}
if ( ! defined( 'ZKV_VERSION' ) ) {
	define( 'ZKV_VERSION', '1.8.0' );
}
if ( ! defined( 'ZKV_VAULT_DIR' ) ) {
	define( 'ZKV_VAULT_DIR', sys_get_temp_dir() . '/zdz-test-kv-vault-' . getmypid() );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['zdz_test_options'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $k ) { unset( $GLOBALS['zdz_test_options'][ $k ] ); return true; }
}
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $a, $d = array() ) { return array_merge( (array) $d, (array) $a ); }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return (int) ( $GLOBALS['zdz_test_current_user'] ?? 0 ); }
}
if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( $v ) { return is_string( $v ) && false !== ( $u = @unserialize( $v, array( 'allowed_classes' => false ) ) ) ? $u : $v; }  // sentinel:allow test stand-in; allowed_classes false
}
if ( ! function_exists( 'maybe_serialize' ) ) {
	function maybe_serialize( $v ) { return is_array( $v ) || is_object( $v ) ? serialize( $v ) : $v; }
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() {
		$b = $GLOBALS['zdz_test_uploads'] ?? sys_get_temp_dir();
		return array( 'basedir' => $b, 'baseurl' => 'https://app.example.com/wp-content/uploads' );
	}
}
if ( ! function_exists( 'zkv_user_has_access' ) ) {
	function zkv_user_has_access( $uid = null ) { return in_array( (int) $uid, (array) ( $GLOBALS['zdz_test_kv_access'] ?? array() ), true ); }
}
if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( $path = '', $plugin = '' ) { return 'https://app.example.com/wp-content/plugins/zorderz-apps/apps/' . ltrim( (string) $path, '/' ); }
}
if ( ! function_exists( 'esc_sql' ) ) {
	function esc_sql( $s ) { return addslashes( (string) $s ); }
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( ...$a ) { return true; }
}

/** A small $wpdb stand-in: answers the ACL's party lookup and the link/document reads from arrays. */
if ( ! class_exists( 'ZDZ_Test_KV_DB' ) ) {
	class ZDZ_Test_KV_DB {
		public $prefix  = 'wp_';
		public $options = 'wp_options';
		public $rows    = array(); // the next get_row() answers
		public $updates = array();
		public $insert_id = 0;
		public function prepare( $q, ...$a ) {
			if ( 1 === count( $a ) && is_array( $a[0] ) ) { $a = $a[0]; }
			foreach ( $a as $v ) { $q = preg_replace( '/%[sd]/', is_int( $v ) ? (string) $v : "'" . addslashes( (string) $v ) . "'", $q, 1 ); }
			return $q;
		}
		public function get_col( $q ) { return array(); }
		public function get_var( $q ) { return null; }
		public function get_results( $q, $o = null ) { return array(); }
		public function get_row( $q, $o = null ) { return array_shift( $this->rows ); }
		public function query( $q ) { $this->updates[] = $q; return 1; }
		public function update( $t, $d, $w ) { $this->updates[] = array( $t, $d, $w ); return 1; }
		public function insert( $t, $d ) { $this->updates[] = array( 'insert', $t, $d ); $this->insert_id++; return 1; }
		public function delete( ...$a ) { return 1; }
		public function esc_like( $s ) { return addcslashes( (string) $s, '_%\\' ); }
	}
}

$zkv_inc = dirname( __DIR__, 2 ) . '/zorderz-apps/apps/knowledge/includes/';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-model-registry.php';
require_once $zkv_inc . 'class-zkv-acl.php';
require_once $zkv_inc . 'class-zkv-file-names.php';
require_once $zkv_inc . 'class-zkv-private.php';
require_once $zkv_inc . 'class-zkv-ext-links.php';
require_once $zkv_inc . 'class-zkv-viewer.php';
require_once $zkv_inc . 'class-zkv-mailbox.php';
require_once $zkv_inc . 'class-zkv-dashboard.php';
require_once $zkv_inc . 'class-zkv-indexer.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/scheduler/includes/class-zsch-settings.php';

final class KnowledgePrivateDocsTest extends TestCase {

	private $prev_db;
	private $dir;

	protected function setUp(): void {
		$this->prev_db      = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']    = new ZDZ_Test_KV_DB();
		$GLOBALS['zdz_test_options'] = array();
		$GLOBALS['zdz_test_filters'] = $GLOBALS['zdz_test_filters'] ?? array();
		$GLOBALS['zdz_test_user_roles'] = array( 7 => array( 'zdz_admin' ), 8 => array( 'zdz_sales' ), 9 => array( 'zdz_general' ) );
		$GLOBALS['zdz_test_user_caps']  = array( 7 => array( 'manage_options' ) );
		$GLOBALS['zdz_test_kv_access']  = array( 7, 8, 9 );
		foreach ( array( 'party_cache', 'whole_share_cache' ) as $p ) {
			$r = new ReflectionProperty( 'ZKV_ACL', $p );
			$r->setAccessible( true );
			$r->setValue( null, array() );
		}
		$this->set_dek( null );
		if ( ! is_dir( ZKV_VAULT_DIR ) ) {
			mkdir( ZKV_VAULT_DIR, 0700, true );
		}
		$this->dir = ZKV_VAULT_DIR;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->prev_db;
		foreach ( (array) glob( $this->dir . '/*' ) as $f ) {
			@unlink( $f );
		}
		unset( $GLOBALS['zdz_test_filters']['zdz_model_choices'], $GLOBALS['zdz_test_filters']['zdz_model_retired_map'], $GLOBALS['zdz_test_filters']['zdz_model_for'], $GLOBALS['zdz_test_filters']['zkv_ext_link_days'] );
		$this->set_dek( null );
		unset( $GLOBALS['zdz_test_options'], $GLOBALS['zdz_test_user_roles'], $GLOBALS['zdz_test_user_caps'], $GLOBALS['zdz_test_kv_access'], $GLOBALS['zdz_test_uploads'] );
	}

	private function assertEndsWith( string $end, string $s, string $m = '' ): void {
		$this->assertSame( $end, substr( $s, -strlen( $end ) ), $m );
	}

	private function set_dek( $v ): void {
		$r = new ReflectionProperty( 'ZKV_Private', 'dek' );
		$r->setAccessible( true );
		$r->setValue( null, $v );
	}

	/* ── the chat rule: a Private document never reaches a model, for anyone ───────────── */

	public function test_chat_rule_excludes_private_documents_for_everyone(): void {
		$this->assertEndsWith( "AND d.is_private = 0", ZKV_ACL::sql_where_chat( 0, 'd' ) );
		$this->assertStringContainsString( "d.visibility = 'all_employees'", ZKV_ACL::sql_where_chat( 8, 'd' ) );
		$this->assertEndsWith( "AND d.is_private = 0", ZKV_ACL::sql_where_chat( 8, 'd' ) );
		$admin = ZKV_ACL::sql_where_chat( 7, 'x' );
		$this->assertEndsWith( 'AND x.is_private = 0', $admin, 'admins included' );
		$this->assertEndsWith( 'AND d.is_private = 0', ZKV_ACL::sql_where_chat( 7, 'd;DROP' === 'never' ? '' : 'd' ) );
		$this->assertStringNotContainsString( ';', ZKV_ACL::sql_where_chat( 7, 'd; DROP TABLE' ) );
	}

	/* ── Private files: encrypted, whole or nothing, keys that lock and unlock ─────────── */

	public function test_encrypt_and_decrypt_round_trip(): void {
		$this->set_dek( random_bytes( 32 ) );
		$plain = str_repeat( "Line for a neutral note.\n", 6000 ); // over two 64 KiB chunks
		$enc   = ZKV_Private::encrypt_string( $plain );
		$this->assertNotSame( '', $enc );
		$this->assertTrue( ZKV_Private::is_encrypted_file( $enc ) );
		$this->assertStringNotContainsString( 'neutral note', (string) file_get_contents( $enc ), 'no plain text on disk' );
		$this->assertSame( $plain, ZKV_Private::decrypt_to_string( $enc ) );
		$this->assertTrue( ZKV_Private::verify_hash( $enc, hash( 'sha256', $plain ) ) );
		$this->assertFalse( ZKV_Private::verify_hash( $enc, hash( 'sha256', $plain . 'x' ) ) );
	}

	public function test_a_file_cut_short_never_reads_as_whole(): void {
		$this->set_dek( random_bytes( 32 ) );
		$enc = ZKV_Private::encrypt_string( str_repeat( 'abc', 50000 ) );
		$raw = file_get_contents( $enc );
		file_put_contents( $enc, substr( $raw, 0, strlen( $raw ) - 40 ) );
		$this->assertNull( ZKV_Private::decrypt_to_string( $enc ) );
	}

	public function test_a_wrong_key_opens_nothing(): void {
		$this->set_dek( random_bytes( 32 ) );
		$enc = ZKV_Private::encrypt_string( 'a short neutral note' );
		$this->set_dek( random_bytes( 32 ) );
		$this->assertSame( -1, ZKV_Private::decrypt_to( $enc, function () {} ) );
	}

	public function test_an_upload_that_only_looks_encrypted_is_not_ours(): void {
		$f = $this->dir . '/look-alike.pdf';
		file_put_contents( $f, ZKV_Private::MAGIC . 'rest of an ordinary file' );
		$this->assertFalse( ZKV_Private::is_encrypted_file( $f ), 'our extension AND our header' );
	}

	public function test_locked_key_and_recovery_code_unlock(): void {
		$dek = random_bytes( 32 );
		// A record wrapped under OTHER site keys (as after a salt reset): it must read as locked.
		$other = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$fp    = substr( hash( 'sha256', 'zkv-private-fp-v1|' . $dek ), 0, 16 );
		$GLOBALS['zdz_test_options'][ ZKV_Private::OPT_KEY ] = array( 'v' => 1, 'w' => base64_encode( $nonce . sodium_crypto_secretbox( $dek, $nonce, $other ) ), 'fp' => $fp, 'created' => '2026-01-01 00:00:00' );
		$this->assertSame( 'locked', ZKV_Private::key_state() );
		$this->assertNull( ZKV_Private::key( false ) );
		$this->assertSame( '', ZKV_Private::recovery_code(), 'no code while locked' );

		$this->assertIsString( ZKV_Private::unlock( 'AAAA BBBB CCCC' ), 'a wrong code is refused' );

		// The right code (the data key in base32 groups), typed with a look-alike 0 for O.
		$b32 = new ReflectionMethod( 'ZKV_Private', 'b32' );
		$b32->setAccessible( true );
		$code = trim( chunk_split( $b32->invoke( null, $dek ), 4, ' ' ) );
		$this->assertTrue( ZKV_Private::unlock( strtolower( str_replace( 'O', '0', $code ) ) ) );
		$this->set_dek( null );
		$this->assertSame( 'ready', ZKV_Private::key_state(), 'rewrapped under this site\'s keys' );
		$this->assertSame( $dek, ZKV_Private::key( false ) );
		$this->assertSame( $code, ZKV_Private::recovery_code() );
	}

	public function test_make_private_refuses_a_transcript(): void {
		$GLOBALS['wpdb']->rows = array( array( 'id' => 3, 'is_private' => 0, 'visibility' => 'transcript_private', 'file_url' => '', 'attachment_id' => 0, 'status' => 'indexed' ) );
		$this->assertIsString( ZKV_Private::make_private( 3, 7 ) );
	}

	/* ── outside links: who may, how long, and one answer for every miss ─────────────── */

	public function test_link_days_only_the_offered_choices(): void {
		$this->assertSame( 14, ZKV_Ext_Links::days( 14 ) );
		$this->assertSame( 30, ZKV_Ext_Links::days( 30 ) );
		$this->assertSame( ZKV_Ext_Links::DEFAULT, ZKV_Ext_Links::days( 365 ), 'an unoffered length falls back to the default' );
		$GLOBALS['zdz_test_filters']['zkv_ext_link_days'] = static function () { return array( 7 ); };
		$this->assertSame( 7, ZKV_Ext_Links::days( 7 ) );
	}

	public function test_a_malformed_token_is_refused_before_any_lookup(): void {
		$this->assertNull( ZKV_Ext_Links::live( 'short' ) );
		$this->assertNull( ZKV_Ext_Links::live( str_repeat( 'a', 42 ) . '!' ) );
		$this->assertSame( array(), $GLOBALS['wpdb']->updates );
	}

	public function test_expired_or_stopped_links_are_not_live(): void {
		$tok = str_repeat( 'b', 43 );
		$GLOBALS['wpdb']->rows = array( array( 'id' => 1, 'revoked_at' => '2026-01-01 00:00:00', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ), 'visibility' => 'all_employees', 'document_id' => 2, 'created_by' => 8 ) );
		$this->assertNull( ZKV_Ext_Links::live( $tok ), 'stopped' );
		$GLOBALS['wpdb']->rows = array( array( 'id' => 1, 'revoked_at' => null, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ), 'visibility' => 'all_employees', 'document_id' => 2, 'created_by' => 8 ) );
		$this->assertNull( ZKV_Ext_Links::live( $tok ), 'expired' );
		$GLOBALS['wpdb']->rows = array( array( 'id' => 1, 'revoked_at' => null, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ), 'visibility' => 'transcript_private', 'document_id' => 2, 'created_by' => 8 ) );
		$this->assertNull( ZKV_Ext_Links::live( $tok ), 'a transcript never leaves' );
	}

	public function test_a_link_whose_maker_lost_the_right_stops_for_good(): void {
		$tok = str_repeat( 'c', 43 );
		$GLOBALS['zdz_test_kv_access'] = array( 7 ); // the maker (8) no longer has the app
		$GLOBALS['wpdb']->rows = array( array( 'id' => 5, 'revoked_at' => null, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ), 'visibility' => 'all_employees', 'document_id' => 2, 'created_by' => 8 ) );
		$this->assertNull( ZKV_Ext_Links::live( $tok ) );
		$this->assertNotEmpty( $GLOBALS['wpdb']->updates, 'revoked, not merely refused' );
		$this->assertSame( 0, $GLOBALS['wpdb']->updates[0][1]['revoked_by'] );
	}

	public function test_who_may_share_outside(): void {
		$this->assertNull( ZKV_Ext_Links::shareable_doc( 2, 0 ), 'nobody signed in' );
		$this->assertNull( ZKV_Ext_Links::shareable_doc( 2, 9 ), 'never the shared device' );
		$GLOBALS['zdz_test_kv_access'] = array( 7 );
		$this->assertNull( ZKV_Ext_Links::shareable_doc( 2, 8 ), 'needs the Knowledge app' );
		$GLOBALS['zdz_test_kv_access'] = array( 7, 8 );
		$GLOBALS['wpdb']->rows = array( array( 'id' => 2, 'visibility' => 'all_employees', 'is_private' => 1, 'uploaded_by' => 7 ) );
		$this->assertNull( ZKV_Ext_Links::shareable_doc( 2, 8 ), 'a Private document: only its uploader or an admin' );
		$GLOBALS['wpdb']->rows = array( array( 'id' => 2, 'visibility' => 'all_employees', 'is_private' => 1, 'uploaded_by' => 8 ) );
		$this->assertNotNull( ZKV_Ext_Links::shareable_doc( 2, 8 ) );
		$GLOBALS['wpdb']->rows = array( array( 'id' => 2, 'visibility' => 'transcript_private', 'is_private' => 0, 'uploaded_by' => 8 ) );
		$this->assertNull( ZKV_Ext_Links::shareable_doc( 2, 8 ), 'transcripts never' );
	}

	/* ── unguessable names, and the folder a served file must be in ──────────────────── */

	public function test_random_names_and_the_allowed_folders(): void {
		$n = ZKV_File_Names::random_name( 'Price List 2026.XLSX' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}\.xlsx$/', $n );
		$this->assertSame( 1, preg_match( '/^[0-9a-f]{32}$/', ZKV_File_Names::random_name( 'no-extension' ) ) );
		$this->assertTrue( ZKV_File_Names::is_random_name( $n ) );
		$this->assertTrue( ZKV_File_Names::is_random_name( substr( $n, 0, 32 ) . '-150x150.jpg' ) );
		$this->assertFalse( ZKV_File_Names::is_random_name( 'price-list.xlsx' ) );

		$up = sys_get_temp_dir() . '/zdz-test-kv-up-' . getmypid();
		@mkdir( $up . '/zkv-vault', 0700, true );
		@mkdir( $up . '-evil', 0700, true );
		file_put_contents( $up . '/zkv-vault/a.txt', 'x' );
		file_put_contents( $up . '-evil/b.txt', 'x' );
		$GLOBALS['zdz_test_uploads'] = $up;
		$this->assertTrue( ZKV_File_Names::is_allowed_path( realpath( $up . '/zkv-vault/a.txt' ) ) );
		$this->assertFalse( ZKV_File_Names::is_allowed_path( realpath( $up . '-evil/b.txt' ) ), 'a sibling folder sharing the prefix is outside' );
		$this->assertFalse( ZKV_File_Names::is_allowed_path( '' ) );
		@unlink( $up . '/zkv-vault/a.txt' ); @unlink( $up . '-evil/b.txt' ); @rmdir( $up . '/zkv-vault' ); @rmdir( $up ); @rmdir( $up . '-evil' );
		unset( $GLOBALS['zdz_test_uploads'] );
	}

	/* ── the viewer page: no CDN, no eval, framed only by this site ──────────────────── */

	public function test_viewer_csp(): void {
		$csp = ZKV_Viewer::csp( 'abc' );
		$this->assertStringContainsString( "script-src 'nonce-abc' 'self'", $csp );
		$this->assertStringContainsString( "frame-ancestors 'self'", $csp );
		$this->assertStringContainsString( "default-src 'none'", $csp );
		$this->assertStringNotContainsString( 'unsafe-eval', $csp );
		$this->assertStringNotContainsString( 'https://', $csp, 'nothing from another origin' );
		$this->assertSame( 'pdf', ZKV_Viewer::kind( 'application/pdf' ) );
		$this->assertSame( 'text', ZKV_Viewer::kind( 'image/heic' ), 'a picture the browser cannot show gets the text view' );
		$urls = ZKV_Viewer::pdfjs_urls();
		$this->assertStringContainsString( 'estimate/assets/js/vendor/pdfjs/pdf.min.js', $urls['lib'] );
	}

	/* ── mail-in: the scheduler's Microsoft app is the fallback, under its current name ──── */

	public function test_mail_in_falls_back_to_the_scheduler_connection(): void {
		$this->assertSame( 'none', ZKV_Mailbox::effective_credentials()['source'] );
		$GLOBALS['zdz_test_options'][ ZSCH_Settings::OPT_CONFIG ] = array( 'tenant_id' => 'tenant-1', 'client_id' => 'client-1' );
		$GLOBALS['zdz_test_options'][ ZSCH_Settings::OPT_SECRET ] = 'secret-1';
		$c = ZKV_Mailbox::effective_credentials();
		$this->assertSame( 'scheduler', $c['source'] );
		$this->assertSame( 'tenant-1', $c['tenant_id'] );
		$GLOBALS['zdz_test_options']['zkv_mail_config'] = array( 'tenant_id' => 't2', 'client_id' => 'c2' );
		$GLOBALS['zdz_test_options']['zkv_mail_secret'] = 's2';
		$this->assertSame( 'vault', ZKV_Mailbox::effective_credentials()['source'], "the vault's own app first" );
	}

	/* ── models: only what the business offers; no model name in the app ─────────────── */

	public function test_model_rules(): void {
		$this->assertSame( '', ZKV_Dashboard::approved_model( '' ) );
		$this->assertSame( 'Model-A', ZKV_Dashboard::approved_model( 'Model-A' ), 'no roster: any handle stands' );
		$GLOBALS['zdz_test_filters']['zdz_model_choices'] = static function () { return array( 'Model-A' => 'Model A', 'Model-B' => 'Model B' ); };
		$this->assertSame( '', ZKV_Dashboard::approved_model( 'Model-Z' ), 'outside the roster: the platform model, never a guess' );
		$GLOBALS['zdz_test_filters']['zdz_model_retired_map'] = static function () { return array( 'Model-Old' => 'Model-B' ); };
		$this->assertSame( 'Model-B', ZKV_Dashboard::approved_model( 'Model-Old' ), 'a retired handle moves to its successor' );
		$GLOBALS['zdz_test_options']['zkv_ai_model'] = 'Model-Z';
		$GLOBALS['zdz_test_filters']['zdz_model_for'] = static function ( $m, $slot ) { return 'knowledge' === $slot ? 'Model-A' : $m; };
		$this->assertSame( 'Model-A', ZKV_Dashboard::get_ai_model(), 'a stored handle outside the roster falls to the slot' );
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/zorderz-apps/apps/knowledge/includes/class-zkv-dashboard.php' );
		$this->assertDoesNotMatchRegularExpression( '/(Gemini|Claude|GPT)-\d/', $src, 'no model name written into the app' );
	}

	public function test_key_reads_the_platform_credential_first(): void {
		$GLOBALS['zdz_test_options']['zkv_poe_api_key'] = 'own-copy-key-0123456789012345678901';
		$this->assertSame( 'own-copy-key-0123456789012345678901', ZKV_Dashboard::get_poe_api_key(), 'own copy when the platform holds none' );
		if ( class_exists( 'ZDZ_Core_Settings' ) && is_callable( array( 'ZDZ_Core_Settings', 'get_credential' ) ) ) {
			$GLOBALS['zdz_test_options']['zdz_core_poe_api_key'] = 'platform-key-012345678901234567890';
			$this->assertSame( 'platform-key-012345678901234567890', ZKV_Dashboard::get_poe_api_key(), 'platform first' );
		}
	}

	/* ── pdftotext: fixed paths only, no shell lookup ──────────────────────────────────── */

	public function test_pdftotext_paths_are_absolute_only(): void {
		$GLOBALS['zdz_test_filters']['zkv_pdftotext_paths'] = static function () { return array( 'pdftotext', '../bin/pdftotext', '' ); };
		$this->assertSame( '', ZKV_Indexer::pdftotext_bin(), 'a relative or bare name is never taken from the filter' );
		unset( $GLOBALS['zdz_test_filters']['zkv_pdftotext_paths'] );
	}
}
