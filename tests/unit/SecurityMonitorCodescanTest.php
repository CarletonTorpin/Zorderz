<?php
/**
 * Security Monitor: the daily check of your own code (ZSM_Codescan), pure part.
 * Each rule has a source that must be reported and a safe twin that must not; the identity
 * of a finding survives moving lines, a key inside a statement is never kept, and damaged or
 * pathological files are read quickly.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/security-monitor/includes/class-zsm-codescan.php';

final class SecurityMonitorCodescanTest extends TestCase {

	const PATH = 'plugins/example-own/example-own.php';

	/** Rules reported for a PHP snippet, as "rule:severity". */
	private static function rules( string $php, string $path = self::PATH ): array {
		$h   = ZSM_Codescan::scan_source( "<?php\n" . $php, $path );
		$idx = ZSM_Codescan::index_functions( "<?php\n" . $php, $path );
		$out = ZSM_Codescan::resolve_component( $h, $idx );
		return array_map(
			function ( $r ) {
				return $r['rule'] . ':' . $r['severity'];
			},
			$out
		);
	}

	private static function has( array $r, string $rule ): bool {
		foreach ( $r as $x ) {
			if ( 0 === strpos( $x, $rule . ':' ) ) {
				return true;
			}
		}
		return false;
	}

	public function test_request_input_reaching_a_sink(): void {
		$this->assertTrue( self::has( self::rules( 'include $_GET["page"] . ".php";' ), 'include-input' ) );
		$this->assertFalse( self::has( self::rules( 'include __DIR__ . "/x.php";' ), 'include-input' ) );
		$this->assertTrue( self::has( self::rules( '$wpdb->query( "DELETE FROM t WHERE id = " . $_POST["id"] );' ), 'sql-input' ) );
		$this->assertTrue( self::has( self::rules( '$wpdb->get_results( "SELECT * FROM t WHERE n = \'{$_GET[\'n\']}\'" );' ), 'sql-input' ) );
		$this->assertFalse( self::has( self::rules( '$wpdb->query( $wpdb->prepare( "DELETE FROM t WHERE id = %d", $_POST["id"] ) );' ), 'sql-input' ) );
		$this->assertFalse( self::has( self::rules( '$wpdb->query( "DELETE FROM t WHERE id = " . (int) $_POST["id"] );' ), 'sql-input' ) );
		$this->assertFalse( self::has( self::rules( '$wpdb->query( "DELETE FROM t WHERE id = " . absint( $_POST["id"] ) );' ), 'sql-input' ) );
		$this->assertTrue( self::has( self::rules( 'echo $_GET["q"];' ), 'echo-input' ) );
		$this->assertTrue( self::has( self::rules( 'echo "<p>" . wp_unslash( $_GET["q"] ) . "</p>";' ), 'echo-input' ), 'wp_unslash alone does not escape' );
		$this->assertFalse( self::has( self::rules( 'echo esc_html( wp_unslash( $_GET["q"] ) );' ), 'echo-input' ) );
		$this->assertFalse( self::has( self::rules( 'echo isset( $_GET["q"] ) ? "yes" : "no";' ), 'echo-input' ) );
		$this->assertFalse( self::has( self::rules( 'echo "a" === $_GET["q"] ? "yes" : "no";' ), 'echo-input' ) );
		$this->assertTrue( self::has( self::rules( '?><p><?= $_REQUEST["x"] ?></p><?php' ), 'echo-input' ) );
		$this->assertTrue( self::has( self::rules( 'printf( "<b>%s</b>", $_POST["n"] );' ), 'echo-input' ) );
		$this->assertFalse( self::has( self::rules( 'printf( "<b>%s</b>", esc_html( $_POST["n"] ) );' ), 'echo-input' ) );
		$this->assertTrue( self::has( self::rules( '$x = unserialize( $_COOKIE["c"] );' ), 'unserialize-input' ) ); // sentinel:allow test fixture: the pattern is test data or a stand-in, never run against input
		$this->assertFalse( self::has( self::rules( '$x = unserialize( get_option( "o" ) );' ), 'unserialize-input' ) ); // sentinel:allow test fixture: the pattern is test data or a stand-in, never run against input
		$this->assertFalse( self::has( self::rules( '$x = unserialize( $_COOKIE["c"], array( "allowed_classes" => false ) );' ), 'unserialize-input' ) ); // sentinel:allow test fixture: the pattern is test data or a stand-in, never run against input
		$this->assertTrue( self::has( self::rules( 'extract( $_POST );' ), 'extract-input' ) );
		$this->assertTrue( self::has( self::rules( 'unlink( WP_CONTENT_DIR . "/" . $_GET["f"] );' ), 'file-input' ) );
		$this->assertFalse( self::has( self::rules( 'unlink( WP_CONTENT_DIR . "/" . sanitize_file_name( $_GET["f"] ) );' ), 'file-input' ) );
		$this->assertTrue( self::has( self::rules( 'move_uploaded_file( $_FILES["f"]["tmp_name"], $dir . $_POST["name"] );' ), 'file-input' ) ); // sentinel:allow test fixture: the pattern is test data or a stand-in, never run against input
		$this->assertFalse( self::has( self::rules( 'move_uploaded_file( $_FILES["f"]["tmp_name"], $dir . "/a.jpg" );' ), 'file-input' ) ); // sentinel:allow test fixture: the pattern is test data or a stand-in, never run against input
		$this->assertTrue( self::has( self::rules( 'wp_redirect( $_GET["to"] );' ), 'redirect-input' ) );
		$this->assertFalse( self::has( self::rules( 'wp_safe_redirect( $_GET["to"] );' ), 'redirect-input' ) );
	}

	public function test_each_sink_has_its_own_safe_list(): void {
		$must = array(
			'prepare() elsewhere in the call'        => array( '$wpdb->get_var( $wpdb->prepare( "SELECT %d", 1 ) . " AND n = " . $_GET["n"] );', 'sql-input' ),
			'input inside prepare()\'s query string' => array( '$wpdb->query( $wpdb->prepare( "DELETE FROM t WHERE n = \'" . $_GET["n"] . "\' AND id = %d", 1 ) );', 'sql-input' ),
			'esc_sql unquoted'                       => array( '$wpdb->query( "DELETE FROM t WHERE id = " . esc_sql( $_GET["id"] ) );', 'sql-input' ),
			'sanitize_text_field in SQL'             => array( '$wpdb->query( "DELETE FROM t WHERE n = " . sanitize_text_field( $_GET["n"] ) );', 'sql-input' ),
			'$this->wpdb'                            => array( '$this->wpdb->query( "DELETE FROM t WHERE id = " . $_POST["id"] );', 'sql-input' ),
			'$wpdb?->'                               => array( '$wpdb?->get_row( "SELECT * FROM t WHERE n = " . $_GET["n"] );', 'sql-input' ),
			'nested call in prepare()\'s query'      => array( '$wpdb->get_results( $wpdb->prepare( "SELECT * FROM t WHERE id IN (" . implode( ",", $_GET["ids"] ) . ") AND s = %s", "x" ) );', 'sql-input' ),
			'include of esc_html()'                  => array( 'include WP_CONTENT_DIR . "/" . esc_html( $_GET["p"] );', 'include-input' ),
			'unserialize of sanitize_text_field()'   => array( '$x = unserialize( sanitize_text_field( $_COOKIE["c"] ) );', 'unserialize-input' ), // sentinel:allow test fixture: the pattern is test data or a stand-in, never run against input
			'wp_redirect( esc_url_raw() )'           => array( 'wp_redirect( esc_url_raw( $_GET["to"] ) );', 'redirect-input' ),
			'echo esc_sql()'                         => array( 'echo "<p>" . esc_sql( $_GET["q"] );', 'echo-input' ),
			'echo $_GET ?: default'                  => array( 'echo $_GET["q"] ?: "d";', 'echo-input' ),
			'unlink of $_FILES name'                 => array( 'unlink( $dir . $_FILES["f"]["name"] );', 'file-input' ),
		);
		foreach ( $must as $what => $c ) {
			$this->assertTrue( self::has( self::rules( $c[0] ), $c[1] ), "reported: $what" );
		}
		$safe = array(
			'a prepare() value'                 => array( '$wpdb->query( $wpdb->prepare( "DELETE FROM t WHERE n = %s", $_GET["n"] ) );', 'sql-input' ),
			'esc_sql inside quotes'             => array( '$wpdb->query( "DELETE FROM t WHERE n = \'" . esc_sql( $_GET["n"] ) . "\'" );', 'sql-input' ),
			'sanitize_key in ORDER BY'          => array( '$wpdb->get_col( "SELECT a FROM t ORDER BY " . sanitize_key( $_GET["o"] ) );', 'sql-input' ),
			'a wrapped prepare() value'         => array( '$wpdb->query( $wpdb->prepare( "DELETE FROM t WHERE n = %s", sanitize_text_field( $_GET["n"] ) ) );', 'sql-input' ),
			'include of sanitize_key()'         => array( 'include __DIR__ . "/" . sanitize_key( $_GET["p"] ) . ".php";', 'include-input' ),
			'echo of a fully-qualified escaper' => array( 'echo \\esc_html( $_GET["a"] );', 'echo-input' ),
			'reading an upload\'s tmp_name'     => array( '$raw = file_get_contents( $_FILES["csv"]["tmp_name"] );', 'file-input' ),
		);
		foreach ( $safe as $what => $c ) {
			$this->assertFalse( self::has( self::rules( $c[0] ), $c[1] ), "not reported: $what" );
		}
	}

	public function test_code_execution(): void {
		$this->assertContains( 'exec:high', self::rules( 'eval( $code );' ) ); // sentinel:allow test fixture: the pattern is test data or a stand-in, never run against input
		$this->assertContains( 'exec:high', self::rules( 'shell_exec( "ls " . $dir );' ) ); // sentinel:allow test fixture: the pattern is test data or a stand-in, never run against input
		$this->assertContains( 'exec:low', self::rules( 'exec( "convert " . escapeshellarg( $f ), $o, $r );' ) );
		$this->assertContains( 'exec:high', self::rules( '$x = `ls`;' ) );
		$this->assertFalse( self::has( self::rules( '$pdo->exec( "x" ); curl_multi_exec( $mh, $a );' ), 'exec' ), 'a method named exec is not a shell' ); // sentinel:allow test fixture: the pattern is test data or a stand-in, never run against input
		$this->assertContains( 'exec:high', self::rules( 'exec( "convert " . escapeshellarg( $in ) . " " . $_GET["out"] );' ) );
		$this->assertContains( 'exec:high', self::rules( '$a = escapeshellarg( $x ); shell_exec( "ls " . $_GET["dir"] );' ) );
		$this->assertContains( 'exec:low', self::rules( '$cmd = escapeshellarg( $bin ) . " " . escapeshellarg( $f ); exec( $cmd, $o, $r );' ) );
		$this->assertContains( 'exec:high', self::rules( '$cmd = escapeshellarg( $d ); $cmd .= " " . $_GET["x"]; exec( $cmd );' ) );
		$this->assertContains( 'exec:high', self::rules( '$cmd = escapeshellarg( $a ) . " " . $other; exec( $cmd );' ) );
	}

	public function test_tls_and_secrets(): void {
		$this->assertContains( 'tls-off:medium', self::rules( 'wp_remote_get( $url, array( "sslverify" => false ) );' ) );
		$this->assertContains( 'tls-off:low', self::rules( '$url = admin_url( "admin-ajax.php" ); wp_remote_post( $url, array( "sslverify" => false ) );' ), 'a loopback is low' );
		$this->assertContains( 'tls-off:low', self::rules( 'wp_remote_get( add_query_arg( "a", 1, site_url( "wp-cron.php" ) ), array( "sslverify" => false ) );' ) );
		$this->assertContains( 'tls-off:medium', self::rules( 'wp_remote_post( "https://api.example.com/oauth/token", array( "body" => array( "redirect_uri" => admin_url( "admin-post.php" ) ), "sslverify" => false ) );' ), 'an outside call that mentions admin_url() is still medium' );
		$this->assertContains( 'tls-off:medium', self::rules( 'wp_remote_post( EXAMPLE_API_BASE . "/token?redirect_uri=" . rawurlencode( admin_url() ), array( "sslverify" => false ) );' ) );
		$this->assertTrue( self::has( self::rules( 'curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );' ), 'tls-off' ) );
		$this->assertContains( 'tls-off:medium', self::rules( 'curl_setopt_array( $ch, array( CURLOPT_SSL_VERIFYPEER => false ) );' ) );
		$this->assertContains( 'tls-off:medium', self::rules( 'curl_setopt( $ch, \\CURLOPT_SSL_VERIFYPEER, false );' ) );
		$this->assertFalse( self::has( self::rules( 'wp_remote_get( $url, array( "sslverify" => true ) );' ), 'tls-off' ) );

		$key = 'sk_live_' . str_repeat( 'A1b2', 6 );
		$r   = ZSM_Codescan::scan_source( "<?php\n\$k = '$key';", self::PATH );
		$this->assertCount( 1, $r );
		$this->assertSame( 'secret-in-code', $r[0]['rule'] );
		$this->assertStringNotContainsString( $key, json_encode( $r ), 'the key value appears nowhere in the finding' );
		$this->assertStringNotContainsString( 'A1b2A1b2', json_encode( $r ) );
		$this->assertSame( array(), ZSM_Codescan::scan_source( "<?php\n\$k = 'sk_test_' . 'abc';", self::PATH ) );
		$this->assertSame( array(), ZSM_Codescan::scan_source( "<?php\n\$k = 'sk_live_" . str_repeat( 'X', 24 ) . "';", self::PATH ), 'a placeholder is not a key' );

		$probe = 'sk_live_' . 'PROBESECRETabcdefghijklmnop1234';
		$h     = ZSM_Codescan::scan_source( "<?php\nshell_exec( \"curl -H 'Authorization: Bearer $probe' x\" . \$_GET['x'] );", self::PATH );
		$this->assertCount( 2, $h );
		$this->assertStringNotContainsString( 'PROBESECRET', serialize( $h ), 'a key inside a reported statement is kept only as a hash' );
	}

	public function test_handlers_and_rest_routes(): void {
		$csrf = 'class A { function __construct() { add_action( "admin_post_example_delete", array( $this, "del" ) ); } function del() { delete_option( "x" ); } }';
		$this->assertTrue( self::has( self::rules( $csrf ), 'handler-no-nonce' ) );
		$safe = 'class A { function __construct() { add_action( "admin_post_example_delete", array( $this, "del" ) ); } function del() { self::gate(); delete_option( "x" ); } private static function gate() { check_admin_referer( "x" ); } }';
		$this->assertFalse( self::has( self::rules( $safe ), 'handler-no-nonce' ), 'a nonce one call away is found' );
		$this->assertFalse( self::has( self::rules( 'add_action( "wp_ajax_nopriv_example_x", "example_deny" ); function example_deny() { wp_send_json_error( "no", 403 ); }' ), 'handler-no-nonce' ), 'a hard deny' );
		$this->assertFalse( self::has( self::rules( 'add_action( "wp_ajax_example_status", "example_st" ); function example_st() { update_option( "a", 1 ); }' ), 'handler-no-nonce' ), 'a read-only-named hook' );
		$this->assertTrue( self::has( self::rules( 'add_action( "wp_ajax_example_update_status", "f" ); function f() { update_option( "s", 1 ); }' ), 'handler-no-nonce' ), '"update_status" writes' );
		$this->assertTrue( self::has( self::rules( 'add_action( "wp_ajax_example_x", function () { update_option( "a", 1 ); } );' ), 'handler-no-nonce' ) );
		$this->assertTrue( self::has( self::rules( 'add_action( "wp_ajax_example_x", static function () { delete_option( "x" ); } );' ), 'handler-no-nonce' ) );
		$this->assertTrue( self::has( self::rules( 'add_action( "wp_ajax_example_wipe", "f" ); function f() { global $wpdb; $wpdb->delete( "t", array( "id" => 1 ) ); wp_send_json_success(); }' ), 'handler-no-nonce' ), '$wpdb->delete() is not harmless' );
		$this->assertTrue( self::has( self::rules( 'add_action( "admin_post_example_x", "f" ); function f() { wp_verify_nonce( $_POST["n"], "x" ); delete_option( "x" ); }' ), 'handler-no-nonce' ), 'a nonce check whose result is thrown away' );
		$this->assertFalse( self::has( self::rules( 'add_action( "admin_post_example_x", "f" ); function f() { wp_verify_nonce( $_POST["n"], "x" ) or die(); delete_option( "x" ); }' ), 'handler-no-nonce' ) );
		$this->assertFalse( self::has( self::rules( 'add_action( "admin_post_example_go", "f" ); function f() { wp_safe_redirect( admin_url( "admin.php?page=x" ) ); exit; }' ), 'handler-no-nonce' ) );
		$this->assertFalse( self::has( self::rules( 'class A { function __construct() { add_action( "wp_ajax_example_x", function () { $this->go(); } ); } function go() { check_ajax_referer( "x" ); delete_option( "x" ); } }' ), 'handler-no-nonce' ) );
		$this->assertFalse( self::has( self::rules( 'class B extends A { function __construct() { add_action( "admin_post_example_x", array( $this, "h" ) ); } function h() { parent::guard(); delete_option( "x" ); } } class A { function guard() { check_admin_referer( "x" ); } }' ), 'handler-no-nonce' ) );
		$this->assertFalse( self::has( self::rules( 'add_action( "wp_ajax_example_x", array( "Unknown_Class", "m" ) );' ), 'handler-no-nonce' ), 'unresolvable: say nothing' );
		$this->assertContains( 'token-compare:low', self::rules( 'add_action( "wp_ajax_nopriv_example_w", "w" ); function w() { $token = $_POST["t"]; if ( $token !== get_transient( "k" ) ) { wp_die(); } update_option( "a", 1 ); }' ) );
		$this->assertFalse( self::has( self::rules( 'add_action( "wp_ajax_nopriv_example_w", "w" ); function w() { if ( ! hash_equals( get_transient( "k" ), (string) $_POST["t"] ) ) { wp_die(); } update_option( "a", 1 ); }' ), 'handler-no-nonce' ) );

		$rest = 'register_rest_route( "example/v1", "/a", array( "methods" => "GET", "callback" => "f", "permission_callback" => "__return_true" ) ); register_rest_route( "example/v1", "/b", array( "methods" => "GET", "callback" => "f", "permission_callback" => "__return_true" ) ); register_rest_route( "example/v1", "/c", array( "methods" => "POST", "callback" => "f" ) ); register_rest_route( "example/v1", "/d", array( "methods" => "POST", "callback" => "f", "permission_callback" => "can" ) );'; // sentinel:allow test fixture: the pattern is test data or a stand-in, never run against input
		$r    = self::rules( $rest );
		$this->assertCount( 1, array_filter( $r, function ( $x ) { return 0 === strpos( $x, 'rest-public' ); } ), 'public routes: one row per component' );
		$this->assertContains( 'rest-no-permission:low', $r );
		$this->assertCount( 2, $r );
		$this->assertContains( 'rest-public:info', self::rules( 'register_rest_route( "example/v1", "/a", array( "methods" => "GET", "callback" => "f", "permission_callback" => fn() => true ) );' ) );
		$this->assertTrue( self::has( self::rules( 'register_rest_route( "example/v1", "/a", array( array( "methods" => "GET", "callback" => "f", "permission_callback" => "can" ), array( "methods" => "POST", "callback" => "g" ) ) );' ), 'rest-no-permission' ) );
	}

	public function test_identity_paths_and_damaged_files(): void {
		$a = ZSM_Codescan::to_findings( ZSM_Codescan::scan_source( "<?php\necho \$_GET['q'];", self::PATH ) );
		$b = ZSM_Codescan::to_findings( ZSM_Codescan::scan_source( "<?php\n\n\n// moved down\n\necho  \$_GET['q'] ;", self::PATH ) );
		$this->assertSame( $a[0]['finding_key'], $b[0]['finding_key'], 'moving a line keeps the finding (an acknowledgement sticks)' );
		$c = ZSM_Codescan::to_findings( ZSM_Codescan::scan_source( "<?php\necho \$_GET['q']; echo \$_GET['q'];", self::PATH ) );
		$this->assertCount( 2, $c );
		$this->assertNotSame( $c[0]['finding_key'], $c[1]['finding_key'] );
		$this->assertTrue( ZSM_Codescan::skip_path( 'plugins/example-own/tests/t.php' ) );
		$this->assertTrue( ZSM_Codescan::skip_path( 'plugins/example-own/vendor/a/b.php' ) );
		$this->assertTrue( ZSM_Codescan::skip_path( 'plugins/example-own/fixtures/a.php' ) );
		$this->assertTrue( ZSM_Codescan::skip_path( 'themes/zorderz/bundled/zorderz-apps/apps/x/app.php' ), 'the theme\'s installer copy of the apps is not running code' );
		$this->assertFalse( ZSM_Codescan::skip_path( 'plugins/example-own/includes/a.php' ) );
		$this->assertSame( 'example-mu', ZSM_Codescan::component_of( 'mu-plugins/example-mu.php' ) );
		$this->assertSame( 'example-theme', ZSM_Codescan::component_of( 'themes/example-theme/inc/a.php' ) );
		$this->assertSame( array(), ZSM_Codescan::scan_source( "<?php\nfunction (", self::PATH ), 'a file that does not parse is skipped quietly' );
		$this->assertSame( array(), ZSM_Codescan::scan_source( 'just text, no php', self::PATH ) );
	}

	public function test_pathological_files_are_read_quickly(): void {
		$src = '';
		for ( $c = 0; $c < 300; $c++ ) {
			$src .= "class C$c extends P { function run() { parent::run(); \$this->go(); update_option( 'a', 1 ); } function go() { parent::go(); update_option( 'b', 2 ); } }\nadd_action( 'admin_post_example_h$c', array( 'C$c', 'run' ) );\n";
		}
		$t0 = microtime( true );
		self::rules( $src );
		$this->assertLessThan( 10.0, microtime( true ) - $t0, 'handler resolution over 300 parent:: classes stays fast' );

		$echo = "<?php\n" . str_repeat( "echo ( \$a . \$b ;\n", 7500 ) . str_repeat( "echo ( \$a\n", 15000 );
		$t0   = microtime( true );
		ZSM_Codescan::scan_source( $echo, self::PATH );
		$this->assertLessThan( 6.0, microtime( true ) - $t0, 'unclosed "echo (" statements are read in linear time' );

		$unbalanced = "<?php\n" . str_repeat( 'f( $a, ( $b', 6000 ) . ";\n";
		$t0         = microtime( true );
		ZSM_Codescan::scan_source( $unbalanced, self::PATH );
		ZSM_Codescan::index_functions( $unbalanced, self::PATH );
		$this->assertLessThan( 4.0, microtime( true ) - $t0, 'unbalanced brackets are read in linear time' );
	}
}
