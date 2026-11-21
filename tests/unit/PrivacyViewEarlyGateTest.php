<?php
/**
 * ZDZ_Privacy_View: the gate before any plugin loads (the optional must-use file hands the request to early_gate()).
 *
 *   - Three looks: before the plugins on the sign-in cookie's word (early_verdict()); at plugins_loaded, first of all,
 *     once WordPress says whose cookie it is (early_confirm()); and the gate proper when the theme loads.
 *   - Before the plugins no app has loaded: Core's lists stand, the apps' own declarations are read from the note kept
 *     of them, and what only an app can answer is asked later. One field more is refused at every look.
 *   - wp-login.php, the sign-in routes and the sign-in pages are let by before the plugins (a browser can carry the
 *     account's cookie after its session ended) and refused for the account itself at plugins_loaded.
 *   - The account keeps only its sign-in cookies; every other cookie is taken out of the request, never refused.
 *   - A request header some code acts on refuses the request; a host's or page cache's own header is declared.
 *   - $pagenow is worked out the way WordPress does before WordPress has set it.
 *
 * tests/unit/test-privacy-view-early-gate.php runs the must-use file itself, in WordPress's loading order.
 * Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/privacy-view-gate-doubles.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/messaging/includes/class-zim-privacy-view.php';

final class PrivacyViewEarlyGateTest extends TestCase {

	/** What a refusal before the theme loads would have sent (the test seam replaces the ending). */
	private $halted = null;

	protected function setUp(): void {
		ZDZ_Test_Kpv_Request::reset();
		ZDZ_Test_Kpv_Request::people();
		$GLOBALS['zdz_test_options'][ ZDZ_Privacy_View::DM_OPTION ] = ZDZ_Test_Kpv_Request::ADMIN;
		$this->halted           = null;
		ZDZ_Privacy_View::$halt = function ( $why, $json, $body ) {
			$this->halted = array( $why, $json, $body );
		};
	}

	protected function tearDown(): void {
		foreach ( array( 'HTTP_X_METHOD_OVERRIDE', 'HTTP_X_ORIGINAL_URL', 'HTTP_X_REWRITE_URL', 'HTTP_X_EXAMPLE_CACHE_WARMUP', 'HTTP_X_WP_NONCE', 'HTTP_X_REQUESTED_WITH',
			'HTTP_X_FORWARDED_FOR', 'HTTP_SEC_FETCH_DEST', 'HTTP_REFERER', 'HTTP_USER_AGENT', 'http_x_rewrite_url' ) as $h ) {
			unset( $_SERVER[ $h ] );
		}
		ZDZ_Test_Kpv_Request::reset();
	}

	private static function hit( $kind, array $get = array(), array $post = array(), array $o = array(), array $files = array() ) {
		return ZDZ_Test_Kpv_Request::hit( $kind, $get, $post, $o, $files );
	}

	private static function early( $kind, array $get = array(), array $post = array(), array $o = array(), array $files = array() ) {
		return ZDZ_Test_Kpv_Request::early( $kind, $get, $post, $o, $files );
	}

	private static function own( $action, array $extra = array() ) {
		return ZDZ_Test_Kpv_Request::own( $action, $extra );
	}

	/** The verdict at plugins_loaded for the request just built: null when it goes on, else the reason it was refused. */
	private function confirm( $kind, array $get = array(), array $post = array(), array $o = array() ) {
		$this->halted = null;
		ZDZ_Test_Kpv_Request::build( $kind, $get, $post, $o );
		ZDZ_Privacy_View::early_confirm();
		return null === $this->halted ? null : (string) $this->halted[0];
	}

	/** Cookies as PHP parses a Cookie header (the first of a name; "." and " " in a name become "_"; "[" makes a list). */
	private static function cookies( $header ) {
		$_SERVER['HTTP_COOKIE'] = $header;
		$_COOKIE                = array();
		foreach ( explode( ';', $header ) as $p ) {
			$p  = ltrim( $p );
			$eq = strpos( $p, '=' );
			if ( false === $eq ) {
				continue;
			}
			$n = strtr( substr( $p, 0, $eq ), array( '.' => '_', ' ' => '_' ) );
			if ( false !== strpos( $n, '[' ) ) {
				$_COOKIE[ substr( $n, 0, strpos( $n, '[' ) ) ] = array( 'x' );
				continue;
			}
			if ( ! isset( $_COOKIE[ $n ] ) ) {
				$_COOKIE[ $n ] = urldecode( substr( $p, $eq + 1 ) );
			}
		}
	}

	/* ── the lists of this stage ─────────────────────────────────────────────── */

	public function test_the_cookie_and_header_lists_are_exactly_these(): void {
		$this->assertSame( array( 'HTTP_X_METHOD_OVERRIDE', 'HTTP_X_ORIGINAL_URL', 'HTTP_X_REWRITE_URL' ), ZDZ_Privacy_View::TRIGGER_HEADERS, 'Core names no host\'s or page cache\'s header' );
		$this->assertSame( ZDZ_Privacy_View::TRIGGER_HEADERS, ZDZ_Privacy_View::trigger_headers() );
		$this->assertCount( 7, ZDZ_Privacy_View::COOKIE_KEEP, 'seven kinds of cookie are kept' );
		foreach ( array( 'early_gate', 'early_verdict', 'early_confirm', 'pagenow', 'keep_cookies', 'remember_declared', 'forget_declared' ) as $m ) {
			$this->assertTrue( method_exists( 'ZDZ_Privacy_View', $m ), $m );
		}
	}

	/* ── $pagenow, worked out before WordPress sets it ───────────────────────── */

	public function test_pagenow_is_worked_out_as_wordpress_would_name_the_script(): void {
		$cases = array(   // PHP_SELF, in wp-admin?, the script WordPress would name
			array( '/wp-admin/admin-ajax.php', true, 'admin-ajax.php' ), array( '/wp-admin/', true, 'index.php' ), array( '/wp-admin/index.php', true, 'index.php' ),
			array( '/wp-admin/profile.php', true, 'profile.php' ), array( '/wp-admin/admin.php', true, 'admin.php' ), array( '/wp-admin/Options.PHP', true, 'options.php' ),
			array( '/wp-admin/edit', true, 'edit.php' ), array( '/wp-admin/profile.php/x', true, 'profile.php' ), array( '/wp-login.php', false, 'wp-login.php' ),
			array( '/index.php', false, 'index.php' ), array( '/', false, 'index.php' ), array( '/wp-json/zorderz/v1/consent', false, 'index.php' ), array( '/xmlrpc.php', false, 'xmlrpc.php' ),
			array( '/wp-cron.php', false, 'wp-cron.php' ), array( '/index.php/wp-json/zorderz/v1/consent', false, 'index.php' ), array( '/WP-LOGIN.PHP', false, 'wp-login.php' ),
		);
		$bad = array();
		foreach ( $cases as $c ) {
			$_SERVER['PHP_SELF']       = $c[0];
			$GLOBALS['current_screen'] = new ZDZ_Test_Kpv_Screen( $c[1] );
			$GLOBALS['pagenow']        = 'not-set-yet.php';
			ZDZ_Test_Kpv_Request::set( 'early', true );
			$got = ZDZ_Privacy_View::pagenow();
			ZDZ_Test_Kpv_Request::set( 'early', false );
			if ( $got !== $c[2] ) {
				$bad[] = $c[0] . ' => ' . $got;
			}
		}
		$this->assertSame( array(), $bad );
		$GLOBALS['pagenow']        = 'profile.php';
		$_SERVER['PHP_SELF']       = '/index.php';
		$GLOBALS['current_screen'] = new ZDZ_Test_Kpv_Screen( true );
		$this->assertSame( 'profile.php', ZDZ_Privacy_View::pagenow(), 'once WordPress has set $pagenow, its word is taken' );
	}

	/* ── before the plugins, what the account's scripts send still goes through ─ */

	public function test_before_the_plugins_the_accounts_own_requests_go_through(): void {
		$bad = array();
		foreach ( ZDZ_Test_Kpv_Request::all() as $action ) {
			if ( '' !== self::early( 'ajax', array(), self::own( $action ), array( 'php_request' => self::own( $action ) + array( 'example_cookie' => '1' ) ) ) ) {
				$bad[] = $action . ' (a form)';
			}
			if ( in_array( $action, ZDZ_Privacy_View::AJAX_GET, true ) && '' !== self::early( 'ajax', self::own( $action ) ) ) {
				$bad[] = $action . ' (by GET)';
			}
		}
		$this->assertSame( array(), $bad, 'each action as its script sends it (even with a cookie in PHP\'s own $_REQUEST)' );
		$this->assertSame( '', self::early( 'front' ), 'the app shell' );
		$this->assertSame( '', self::early( 'front', array( 'cb' => '2.1.0' ) ) );
		$this->assertSame( '', self::early( 'front', array( 'zdz_media' => '77', 't' => 'a1b2', 's' => 'thumb', 'v' => '1' ) ), 'a photo' );
		$this->assertSame( '', self::early( 'rest', array(), array(), array( 'uri' => '/wp-json/zorderz/v1/notifications/unread-count' ) ), 'the two REST reads' );
		$this->assertSame( '', self::early( 'rest', array( 'rest_route' => '/zorderz/v1/consent' ), array(), array( 'uri' => '/?rest_route=/zorderz/v1/consent' ) ) );
		$this->assertSame( '', self::early( 'admin' ), 'its profile' );
		$this->assertSame( '', self::early( 'admin', array( 'page' => 'zdz-llm-usage' ), array(), array( 'uri' => '/wp-admin/admin.php?page=zdz-llm-usage', 'page' => 'admin.php' ) ), 'Ai usage' );
		$this->assertSame( '', self::early( 'login', array( 'action' => 'logout', '_wpnonce' => 'n' ) ), 'signing out' );
	}

	/* ── what only an app can answer is asked again later ────────────────────── */

	public function test_the_direct_message_partner_is_asked_at_plugins_loaded_and_the_fields_at_every_look(): void {
		$GLOBALS['zdz_test_options'][ ZDZ_Privacy_View::DM_OPTION ] = 0;   // no administrator chosen
		$this->assertFalse( ZDZ_Privacy_View::ajax_allowed( 'zim_sidebar' ) );
		$this->assertNotSame( '', self::hit( 'ajax', self::own( 'zim_sidebar' ) ), 'at the gate, with nobody chosen, the messaging list is refused' );
		$this->assertSame( '', self::early( 'ajax', self::own( 'zim_sidebar' ) ), 'before the plugins it is let by (there is no messaging app yet to ask)' );
		$this->assertSame( 'ajax:zim_sidebar', $this->confirm( 'ajax', self::own( 'zim_sidebar' ) ), '... and refused at plugins_loaded: by then it is known that nobody was chosen' );
		$this->assertNotNull( $this->confirm( 'front', array( 'zim_page' => '1', 'zdz_embed' => 'dock', 'zim_compact' => '1', 'c' => '12' ) ), 'the docked thread page too' );
		$GLOBALS['zdz_test_options'][ ZDZ_Privacy_View::DM_OPTION ] = ZDZ_Test_Kpv_Request::ADMIN;
		$this->assertNull( $this->confirm( 'ajax', self::own( 'zim_sidebar' ) ), 'with an administrator chosen it goes on at plugins_loaded' );
		$this->assertNull( $this->confirm( 'front', array( 'zim_page' => '1', 'zdz_embed' => 'dock', 'zim_compact' => '1', 'c' => '12' ) ) );
		$this->assertSame( 'ajax-field:zim_sidebar:example_probe', $this->confirm( 'ajax', self::own( 'zim_sidebar', array( 'example_probe' => '1' ) ) ), '... but still held to its fields there' );
		$this->assertSame( 'ajax-field:zana_list_sessions:option_page', $this->confirm( 'ajax', array(), self::own( 'zana_list_sessions', array( 'option_page' => 'x' ) ) ) );
		$this->assertSame( 'ajax:zsch_settings_save', $this->confirm( 'ajax', array(), array( 'action' => 'zsch_settings_save', 'nonce' => 'n' ) ), 'an action on no list is refused there, whatever is asked later' );
		$this->assertFalse( ZDZ_Test_Kpv_Request::get( 'defer_ready' ), 'the waiting is over once that look is (it never reaches the gate proper)' );
		$GLOBALS['zdz_test_current_user'] = ZDZ_Test_Kpv_Request::MEMBER;
		$this->assertNull( $this->confirm( 'ajax', array(), self::own( 'zana_list_sessions', array( 'option_page' => 'x' ) ) ), 'a request WordPress says is someone else\'s is not judged (the cookie only claimed the account)' );
		$GLOBALS['zdz_test_current_user'] = 0;
		$this->assertNull( $this->confirm( 'ajax', array(), self::own( 'zana_list_sessions', array( 'option_page' => 'x' ) ) ), '... nor one that signs nobody in' );
	}

	/** An app's own reads: before the app has loaded, the note kept of what it declared stands in for it. */
	public function test_an_apps_declared_reads_pass_the_early_looks_from_the_kept_note(): void {
		$get  = array();
		$read = array( 'action' => 'example_one', 'nonce' => 'n', 'example_id' => '5' );
		$this->assertSame( 'ajax:example_one', self::early( 'ajax', $get, $read ), 'nothing declared, nothing kept: refused before the plugins' );
		// the app declares itself (as it would on plugins_loaded), and an administrator's wp-admin request notes it
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_allowed_ajax'] = function ( $list ) {
			return array_merge( (array) $list, array( 'example_one', 'example_list' ) );
		};
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_ajax_keys'] = function ( $rows ) {
			$rows['example_one'] = array( 'example_id' );
			return $rows;
		};
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_allowed_rest'] = function ( $routes ) {
			return array_merge( (array) $routes, array( '/zorderz/v1/example-read' ) );
		};
		$this->assertFalse( ZDZ_Privacy_View::remember_declared( true ), 'the account itself never writes the note' );
		$this->assertArrayNotHasKey( ZDZ_Privacy_View::DECLARED_OPT, (array) ( $GLOBALS['zdz_test_options'] ?? array() ) );
		$GLOBALS['zdz_test_current_user'] = ZDZ_Test_Kpv_Request::MEMBER;
		$this->assertFalse( ZDZ_Privacy_View::remember_declared( true ), 'nor a team member who does not manage the site' );
		$GLOBALS['zdz_test_current_user'] = ZDZ_Test_Kpv_Request::ADMIN;
		if ( ! defined( 'ZDZ_PV_GATE_VERSION' ) ) {
			$this->assertFalse( ZDZ_Privacy_View::remember_declared(), 'without the must-use file there is nothing to keep it for' );
			$this->assertArrayNotHasKey( ZDZ_Privacy_View::DECLARED_OPT, (array) ( $GLOBALS['zdz_test_options'] ?? array() ) );
		}
		$this->assertTrue( ZDZ_Privacy_View::remember_declared( true ), 'an administrator\'s request, the file being installed: kept' );
		$this->assertFalse( ZDZ_Privacy_View::remember_declared( true ), 'nothing changed: not written again' );
		$this->assertSame( array( 'ajax' => array( 'example_list' => array(), 'example_one' => array( 'example_id' ) ), 'rest' => array( '/zorderz/v1/example-read' ), 'signin' => array(), 'headers' => array(), 'login' => array() ),
			$GLOBALS['zdz_test_options'][ ZDZ_Privacy_View::DECLARED_OPT ] );
		// the next request from the account: no app has loaded yet (no filter), the note answers
		unset( $GLOBALS['zdz_test_filters'] );
		$GLOBALS['zdz_test_current_user'] = ZDZ_Test_Kpv_Request::ACCOUNT;
		$this->assertSame( '', self::early( 'ajax', $get, $read ), 'before the plugins: let by, with its own fields' );
		$this->assertSame( 'ajax-field:example_one:option_page', self::early( 'ajax', $get, $read + array( 'option_page' => 'x' ) ), '... and one field more refused, before any plugin loads' );
		$this->assertSame( 'ajax-field:example_list:example_id', self::early( 'ajax', $get, array( 'action' => 'example_list', 'nonce' => 'n', 'example_id' => '5' ) ), 'a read that declared no field carries none' );
		$this->assertNotSame( '', self::early( 'ajax', $read ), '... by POST only' );
		$this->assertSame( '', self::early( 'rest', array(), array(), array( 'uri' => '/wp-json/zorderz/v1/example-read' ) ) );
		$this->assertNull( $this->confirm( 'ajax', $get, $read ), 'at plugins_loaded, first of all (the app adds its filters later on that hook): goes on' );
		$this->assertSame( 'ajax-field:example_one:option_page', $this->confirm( 'ajax', $get, $read + array( 'option_page' => 'x' ) ) );
		$this->assertSame( 'ajax:example_one', self::hit( 'ajax', $get, $read ), 'at the gate proper the app itself is asked: gone, so refused (a stale note opens nothing)' );
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_allowed_ajax'] = function ( $list ) {
			return array_merge( (array) $list, array( 'example_one' ) );
		};
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_ajax_keys'] = function ( $rows ) {
			$rows['example_one'] = array( 'example_id' );
			return $rows;
		};
		$this->assertSame( '', self::hit( 'ajax', $get, $read ), '... and with the app there, allowed' );
		ZDZ_Privacy_View::forget_declared();
		$this->assertArrayNotHasKey( ZDZ_Privacy_View::DECLARED_OPT, (array) $GLOBALS['zdz_test_options'], 'the file removed: its note goes with it' );
		if ( ! defined( 'ZDZ_PV_GATE_VERSION' ) ) {
			// the file deleted by hand leaves its note behind: an administrator's next wp-admin request takes it away
			$GLOBALS['zdz_test_options'][ ZDZ_Privacy_View::DECLARED_OPT ] = array( 'ajax' => array( 'example_one' => array() ) );
			$this->assertFalse( ZDZ_Privacy_View::remember_declared() );
			$this->assertArrayHasKey( ZDZ_Privacy_View::DECLARED_OPT, (array) $GLOBALS['zdz_test_options'], 'the account\'s own request changes nothing' );
			$GLOBALS['zdz_test_current_user'] = ZDZ_Test_Kpv_Request::MEMBER;
			$this->assertFalse( ZDZ_Privacy_View::remember_declared() );
			$this->assertArrayHasKey( ZDZ_Privacy_View::DECLARED_OPT, (array) $GLOBALS['zdz_test_options'], 'nor a team member\'s' );
			$GLOBALS['zdz_test_current_user'] = ZDZ_Test_Kpv_Request::ADMIN;
			$this->assertFalse( ZDZ_Privacy_View::remember_declared() );
			$this->assertArrayNotHasKey( ZDZ_Privacy_View::DECLARED_OPT, (array) $GLOBALS['zdz_test_options'], 'an administrator\'s does: a note nobody reads is not kept' );
		}
	}

	public function test_the_kept_note_is_held_to_its_shape_whatever_it_holds(): void {
		$GLOBALS['zdz_test_options'][ ZDZ_Privacy_View::DECLARED_OPT ] = array(
			'ajax'    => array(
				'zana_list_sessions' => array( 'page', 'code' ),          // a row of Core's
				'zim_post'           => array( 'option_page' ),           // a direct-message action
				'Other_Read'         => array( 'x' ),                     // not a plain lower-case name
				'example_read'       => array( 'ok_field', 'bad field', 'action', 7, array( 'x' ), str_repeat( 'f', 80 ) ),
				5                    => array( 'x' ),
			),
			'rest'    => array( '/zorderz/v1/example', '//x', '/x/../y', 'no-slash', 9, '/zorderz/v1/magic-link-claim' ),
			'signin'  => array( '/zorderz/v1/example-sign-in', '/a b' ),
			'headers' => array( 'http_x_example_cache', 'HTTP_', 'HTTP_X', 'HTTP_USER', 'X_EXAMPLE', 'HTTP_X_WP' ),
			'login'   => array( 'example-host-login', 'log', 'PWD', 'a b' ),
			'more'    => 'ignored',
		);
		ZDZ_Test_Kpv_Request::set( 'early', true );
		$keys     = ZDZ_Privacy_View::ajax_keys( 'example_read' );
		$core     = ZDZ_Privacy_View::ajax_keys( 'zana_list_sessions' );
		$dm       = ZDZ_Privacy_View::ajax_keys( 'zim_post' );
		$headers  = ZDZ_Privacy_View::trigger_headers();
		$login    = ZDZ_Privacy_View::login_keys();
		$signin   = ZDZ_Privacy_View::signin_rest();
		$odd      = ZDZ_Privacy_View::ajax_allowed( 'other_read' );
		$claim    = ZDZ_Privacy_View::rest_allowed( '/zorderz/v1/magic-link-claim', 'GET' );
		$example  = ZDZ_Privacy_View::rest_allowed( '/zorderz/v1/example', 'GET' );
		$traverse = ZDZ_Privacy_View::rest_allowed( '/x/../y', 'GET' );
		ZDZ_Test_Kpv_Request::set( 'early', false );
		$this->assertSame( array( 'action', 'nonce', 'ok_field' ), $keys, 'only plain field names are taken' );
		$this->assertSame( array( 'action', 'nonce' ), $core, 'a row of Core\'s cannot be replaced from the note' );
		$this->assertSame( array( 'action', 'nonce', 'conversation_id', 'body', 'attachment_ids', 'share_refs' ), $dm );
		$this->assertSame( array( 'HTTP_X_METHOD_OVERRIDE', 'HTTP_X_ORIGINAL_URL', 'HTTP_X_REWRITE_URL', 'HTTP_X_EXAMPLE_CACHE' ), $headers, 'a header every browser sends is never a trigger' );
		$this->assertSame( array( 'action', '_wpnonce', 'redirect_to', 'loggedout', 'reauth', 'interim-login', 'wp_lang', 'example-host-login' ), $login );
		$this->assertContains( '/zorderz/v1/example-sign-in', $signin );
		$this->assertNotContains( '/a b', $signin );
		$this->assertFalse( $odd );
		$this->assertFalse( $claim, 'a sign-in route is never the account\'s to read' );
		$this->assertTrue( $example );
		$this->assertFalse( $traverse );
		$this->assertSame( array( 'ajax' => array(), 'rest' => array(), 'signin' => array(), 'headers' => array(), 'login' => array() ), ZDZ_Privacy_View::clean_declared( 'nonsense' ) );
	}

	/* ── before the plugins, one thing more is refused ───────────────────────── */

	public function test_before_the_plugins_one_thing_more_is_refused(): void {
		$this->assertNotSame( '', self::early( 'ajax', array(), self::own( 'zana_list_sessions', array( 'option_page' => 'example_settings' ) ) ), 'a settings save\'s field beside a read' );
		$this->assertNotSame( '', self::early( 'ajax', array(), self::own( 'zana_list_sessions', array( 'exampleCacheWebhook' => 'config', 'token' => 'x' ) ) ), 'a page cache\'s webhook keys' );
		$this->assertNotSame( '', self::early( 'front', array(), array( 'exampleCacheHeartbeat' => '1' ), array( 'method' => 'POST' ) ), '... its heartbeat' );
		$this->assertNotSame( '', self::early( 'front', array( 'previewmode' => '1' ) ), '... its preview switch' );
		$this->assertNotSame( '', self::early( 'ajax', array(), array( 'nonce' => 'n' ), array( 'php_request' => array( 'action' => 'zana_list_sessions' ) ) ),
			'an action only PHP\'s own $_REQUEST holds (a cookie named "action"): not what WordPress will run' );
		$this->assertNotSame( '', self::early( 'ajax', array( 'action' => 'zana_list_sessions' ), array( 'action' => 'zsch_settings_save', 'nonce' => 'n' ) ), 'the address naming one action and the body another' );
		$this->assertNotSame( '', self::early( 'admin', array(), array(), array( 'uri' => '/wp-admin/options.php', 'page' => 'options.php' ) ), 'a screen it may not open (the address says options.php, whatever $pagenow says)' );
		$this->assertNotSame( '', self::early( 'admin', array( 'rest_route' => '/zorderz/v1/consent' ), array(), array( 'uri' => '/wp-admin/options.php?rest_route=/zorderz/v1/consent', 'page' => 'options.php' ) ),
			'... also addressed as a REST read' );
		$this->assertNotSame( '', self::early( 'rest', array(), array(), array( 'uri' => '/wp-json/zorderz/v1/example', 'method' => 'GET' ) ), 'a REST read not on its list' );
		$this->assertNotSame( '', self::early( 'rest', array(), array( 'x' => '1' ), array( 'uri' => '/wp-json/zorderz/v1/consent', 'method' => 'POST' ) ), 'a REST write' );
	}

	/* ── sign-in: let by before the plugins, judged at plugins_loaded ────────── */

	public function test_sign_in_is_let_by_before_the_plugins_and_refused_for_the_account_itself(): void {
		$json = array( 'method' => 'POST', 'type' => 'application/json', 'input' => '{"email":"alex@example.com"}' );
		foreach ( ZDZ_Privacy_View::signin_rest() as $route ) {
			$this->assertSame( '', self::early( 'rest', array(), array(), array( 'uri' => '/wp-json' . $route ) + $json ), 'before the plugins: POST ' . $route );
			$this->assertNotNull( $this->confirm( 'rest', array(), array(), array( 'uri' => '/wp-json' . $route ) + $json ), '... and refused at plugins_loaded when it really is the account\'s' );
		}
		$this->assertSame( '', self::early( 'rest', array( 'rest_route' => '/zorderz/v1/magic-link-code-claim' ), array(), array( 'uri' => '/?rest_route=/zorderz/v1/magic-link-code-claim' ) + $json ), 'written as ?rest_route= too' );
		$this->assertNotSame( '', self::early( 'rest', array(), array(), array( 'uri' => '/wp-json/zorderz/v1/magic-link-code-claim-x' ) + $json ), 'nothing that merely begins like one' );
		$this->assertNotSame( '', self::early( 'rest', array(), array(), array( 'uri' => '/wp-json/zorderz/v1/magic-link-sendcode' ) + $json ) );
		$this->assertSame( '', self::early( 'front', array(), array(), array( 'uri' => '/login/' ) ), 'the sign-in page' );
		$this->assertSame( '', self::early( 'front', array(), array(), array( 'uri' => '/login' ) ) );
		$this->assertSame( '', self::early( 'front', array(), array(), array( 'uri' => '/register/' ) ), 'the register page' );
		$this->assertSame( '', self::early( 'front', array( 'magic-login-bridge' => '1', 'bridge_code' => '123456', 'zdz_next' => '/jobs' ), array(),
			array( 'uri' => '/login/?magic-login-bridge=1&bridge_code=123456&zdz_next=%252Fjobs' ) ), 'with the bridge\'s keys (a %-written return path too)' );
		foreach ( array(
			'another key'              => array( array( 'x' => '1' ), array(), array( 'uri' => '/login/?x=1' ) ),
			'a POST'                   => array( array(), array( 'x' => '1' ), array( 'uri' => '/login/', 'method' => 'POST' ) ),
			'a PUT'                    => array( array(), array(), array( 'uri' => '/login/', 'method' => 'PUT', 'type' => null ) ),
			'a DELETE'                 => array( array(), array(), array( 'uri' => '/login/', 'method' => 'DELETE', 'type' => null ) ),
			'a pair PHP drops'         => array( array( 'zdz_next' => 'x' ), array(), array( 'uri' => '/login/?zdz_next=x&%5Bcode=1' ) ),
			'a name PHP rewrites'      => array( array( 'redirect_to' => 'x' ), array(), array( 'uri' => '/login/?redirect.to=x' ) ),
			'a GET with a body'        => array( array(), array(), array( 'uri' => '/login/', 'method' => 'GET', 'type' => 'application/x-www-form-urlencoded', 'input' => 'code=1' ) ),
			'a page under it'          => array( array(), array(), array( 'uri' => '/login/x' ) ),
			'a page of that name elsewhere' => array( array(), array(), array( 'uri' => '/x/login/' ) ),
			'a name that begins like it'    => array( array(), array(), array( 'uri' => '/logins/' ) ),
		) as $what => $c ) {
			$this->assertNotSame( '', self::early( 'front', $c[0], $c[1], $c[2] ), 'the sign-in page, nothing else: ' . $what );
		}
		$this->assertNotNull( $this->confirm( 'front', array(), array(), array( 'uri' => '/login/' ) ), 'the account itself, signed in, is refused the sign-in page at plugins_loaded' );
		$this->assertNotSame( '', self::early( 'admin', array( 'rest_route' => '/zorderz/v1/magic-link-init' ), array(), array( 'uri' => '/wp-admin/options.php?rest_route=/zorderz/v1/magic-link-init', 'page' => 'options.php' ) ),
			'a wp-admin screen addressed as a sign-in route is not one' );
		$this->assertNotSame( '', self::early( 'front', array( 'x' => 'wp-login.php' ), array(), array( 'uri' => '/?x=wp-login.php' ) ), '"wp-login" in a value is not wp-login.php' );
		$this->assertNotSame( '', self::early( 'front', array(), array(), array( 'uri' => '/wp-login-x/', 'self' => '/index.php' ) ), '... nor a path that is not wp-login.php' );
		$this->assertNotSame( '', self::early( 'ajax', array(), array( 'action' => 'magic_login_ajax_request', 'email' => 'alex@example.com' ) ), 'a sign-in plugin\'s own AJAX form is not a sign-in route here' );
		$this->assertSame( '', self::early( 'login', array(), array( 'log' => 'alex', 'pwd' => 'x' ), array( 'method' => 'POST' ) ), 'wp-login.php: the form' );
		$this->assertSame( '', self::early( 'login', array( 'magic-login' => '1', 'user_id' => '3', 'token' => 'x' ) ), '... a sign-in link' );
		$this->assertNotNull( $this->confirm( 'login', array( 'magic-login' => '1', 'user_id' => '3', 'token' => 'x' ) ), '... refused at plugins_loaded when it is the account\'s' );
		$_SERVER['HTTP_X_REWRITE_URL'] = '/x';
		$this->assertSame( 'header:HTTP_X_REWRITE_URL', self::early( 'login', array( 'action' => 'logout', '_wpnonce' => 'n' ) ), 'but a trigger header is refused there too' );
		$this->assertSame( 'header:HTTP_X_REWRITE_URL', self::early( 'rest', array(), array(), array( 'uri' => '/wp-json/zorderz/v1/magic-link-init' ) + $json ) );
		unset( $_SERVER['HTTP_X_REWRITE_URL'] );
		$this->assertSame( 'override', self::early( 'rest', array(), array(), array( 'uri' => '/wp-json/zorderz/v1/magic-link-init', 'server' => array( 'HTTP_X_HTTP_METHOD_OVERRIDE' => 'DELETE' ) ) + $json ), '... and so is a method override' );
	}

	public function test_a_declared_sign_in_route_is_let_by_early_and_never_opened_to_the_account(): void {
		$json = array( 'method' => 'POST', 'type' => 'application/json', 'input' => '{}' );
		$this->assertNotSame( '', self::early( 'rest', array(), array(), array( 'uri' => '/wp-json/zorderz/v1/example-sign-in' ) + $json ) );
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_signin_rest'] = function ( $routes ) {
			return array_merge( (array) $routes, array( '/zorderz/v1/example-sign-in' ) );
		};
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_allowed_rest'] = function ( $routes ) {
			return array_merge( (array) $routes, array( '/zorderz/v1/example-sign-in' ) );   // even listed as a read
		};
		$this->assertContains( '/zorderz/v1/example-sign-in', ZDZ_Privacy_View::signin_rest() );
		$this->assertFalse( ZDZ_Privacy_View::rest_allowed( '/zorderz/v1/example-sign-in', 'GET' ), 'the account itself gets no sign-in route' );
		$GLOBALS['zdz_test_current_user'] = ZDZ_Test_Kpv_Request::ADMIN;
		$this->assertTrue( ZDZ_Privacy_View::remember_declared( true ) );
		unset( $GLOBALS['zdz_test_filters'] );
		$GLOBALS['zdz_test_current_user'] = ZDZ_Test_Kpv_Request::ACCOUNT;
		$this->assertSame( '', self::early( 'rest', array(), array(), array( 'uri' => '/wp-json/zorderz/v1/example-sign-in' ) + $json ), 'a browser holding a stale cookie can use it' );
		$this->assertNotNull( $this->confirm( 'rest', array(), array(), array( 'uri' => '/wp-json/zorderz/v1/example-sign-in' ) + $json ), '... and the account, signed in, is refused it at plugins_loaded' );
	}

	/* ── trigger headers, at every look ──────────────────────────────────────── */

	public function test_a_header_some_code_acts_on_refuses_the_request_at_every_look(): void {
		foreach ( array( 'HTTP_X_METHOD_OVERRIDE', 'HTTP_X_ORIGINAL_URL', 'HTTP_X_REWRITE_URL', 'http_x_rewrite_url' ) as $h ) {
			$_SERVER[ $h ] = '1';
			$v1            = self::hit( 'ajax', array(), self::own( 'zana_list_sessions' ) );
			$v2            = self::early( 'ajax', array(), self::own( 'zana_list_sessions' ) );
			$v3            = $this->confirm( 'ajax', array(), self::own( 'zana_list_sessions' ) );
			unset( $_SERVER[ $h ] );
			$this->assertSame( 'header:' . strtoupper( $h ), $v1, $h . ' at the gate' );
			$this->assertSame( 'header:' . strtoupper( $h ), $v2, $h . ' before the plugins' );
			$this->assertSame( 'header:' . strtoupper( $h ), $v3, $h . ' at plugins_loaded' );
			$this->assertSame( 'header', ZDZ_Privacy_View::why_word( $v1 ) );
		}
		foreach ( array( 'HTTP_X_WP_NONCE', 'HTTP_X_REQUESTED_WITH', 'HTTP_X_FORWARDED_FOR', 'HTTP_SEC_FETCH_DEST', 'HTTP_REFERER', 'HTTP_USER_AGENT' ) as $h ) {
			$_SERVER[ $h ] = 'x';
			$v             = self::hit( 'ajax', array(), self::own( 'zana_list_sessions' ) );
			unset( $_SERVER[ $h ] );
			$this->assertSame( '', $v, $h . ' (a browser\'s or a proxy\'s) is not a trigger' );
		}
	}

	public function test_a_hosts_or_page_caches_own_header_is_declared_not_built_in(): void {
		$_SERVER['HTTP_X_EXAMPLE_CACHE_WARMUP'] = '1';
		$this->assertSame( '', self::hit( 'ajax', array(), self::own( 'zana_list_sessions' ) ), 'Core knows no page cache\'s header' );
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_trigger_headers'] = function ( $headers ) {
			return array_merge( array( 'HTTP_X_EXAMPLE_CACHE', 'HTTP_USER_AGENT', 'not a header' ), array() );   // Core's own left out: they stay all the same
		};
		$this->assertSame( array( 'HTTP_X_METHOD_OVERRIDE', 'HTTP_X_ORIGINAL_URL', 'HTTP_X_REWRITE_URL', 'HTTP_X_EXAMPLE_CACHE' ), ZDZ_Privacy_View::trigger_headers() );
		$this->assertSame( 'header:HTTP_X_EXAMPLE_CACHE_WARMUP', self::hit( 'ajax', array(), self::own( 'zana_list_sessions' ) ), 'a site that declares it: refused at the gate' );
		$this->assertSame( '', self::early( 'ajax', array(), self::own( 'zana_list_sessions' ) ), 'before the plugins nothing has declared it yet ...' );
		$GLOBALS['zdz_test_current_user'] = ZDZ_Test_Kpv_Request::ADMIN;
		ZDZ_Privacy_View::remember_declared( true );
		$GLOBALS['zdz_test_current_user'] = ZDZ_Test_Kpv_Request::ACCOUNT;
		unset( $GLOBALS['zdz_test_filters'] );
		$this->assertSame( 'header:HTTP_X_EXAMPLE_CACHE_WARMUP', self::early( 'ajax', array(), self::own( 'zana_list_sessions' ) ), '... until the note of it is kept: then before any plugin loads' );
	}

	/* ── cookies: kept and taken ─────────────────────────────────────────────── */

	public function test_the_account_keeps_only_its_sign_in_cookies(): void {
		$h = 'wordpress_logged_in_' . md5( 'example' );
		self::cookies( $h . '=privacy-account%7C1%7C2%7C3; wordpress_sec_' . md5( 'example' ) . '=s; wordpress_' . md5( 'example' ) . '=a; wordpress_test_cookie=WP%20Cookie%20check; wp-settings-9=a; wp-settings-time-9=1; wp_lang=en_US; zdz_next=%2Fjobs'
			. '; example_cache_page=1; _ga=GA1.x; zdz_view_as=3; zdz_view_as_user=4; example-account-override=1; example_proxy_auth=x; __example_bm=x; example-host-auth=x; action=zsch_settings_save; a.b=1; arr[x]=1; wordpress_logged_in=x; wordpress_x=1; '
			. $h . '=second' );
		$_REQUEST = array( 'action' => 'zsch_settings_save', 'example_cache_page' => '1' );
		$_GET     = array();
		$_POST    = array( 'action' => 'zsch_settings_save' );
		$gone     = ZDZ_Privacy_View::keep_cookies();
		$kept     = array_keys( $_COOKIE );
		sort( $kept );
		$want = array( $h, 'wordpress_sec_' . md5( 'example' ), 'wordpress_' . md5( 'example' ), 'wordpress_test_cookie', 'wp-settings-9', 'wp-settings-time-9', 'wp_lang', 'zdz_next' );
		sort( $want );
		$this->assertSame( $want, $kept, 'kept: the sign-in cookies (any site hash), the cookie test, screen settings, the sign-in language, the come-back-here path' );
		$this->assertSame( array(), array_values( array_diff( array( 'example_cache_page', '_ga', 'zdz_view_as', 'zdz_view_as_user', 'example-account-override', 'example_proxy_auth', '__example_bm', 'example-host-auth',
			'action', 'a_b', 'arr', 'wordpress_logged_in', 'wordpress_x' ), $gone ) ), 'taken: a page cache\'s, analytics\', View As\', a proxy\'s, a host\'s, one named "action", one PHP renamed, a list, look-alikes of the sign-in names' );
		$this->assertSame( 0, preg_match( '/example_cache_page|_ga=|zdz_view_as|example_proxy_auth|example-host-auth|action=|a\.b=|arr\[|=second/', (string) $_SERVER['HTTP_COOKIE'] ) );
		$this->assertStringContainsString( $h . '=privacy-account%7C1%7C2%7C3', (string) $_SERVER['HTTP_COOKIE'], 'the Cookie header holds only the kept ones, as written, and only the first of a name sent twice' );
		$this->assertArrayNotHasKey( 'example_cache_page', $_REQUEST, 'a cookie PHP put in $_REQUEST is taken out of it' );
		$this->assertSame( 'zsch_settings_save', $_REQUEST['action'] ?? null, '... a field of the same name stays: it is the field\'s' );
		$this->assertSame( array(), preg_grep( '/=|privacy-account|GA1/', $gone ), 'what is reported is names, never values' );

		self::cookies( $h . '=x; wp_lang[0]=en; wp.lang=en_US; wp-settings-9=a' );
		ZDZ_Privacy_View::keep_cookies();
		$this->assertSame( array( $h, 'wp-settings-9' ), array_keys( $_COOKIE ), 'a kept name that PHP made a list is taken' );
		self::cookies( $h . '=x; wp.lang=en_US' );
		ZDZ_Privacy_View::keep_cookies();
		$this->assertSame( array( $h, 'wp_lang' ), array_keys( $_COOKIE ), 'a name PHP rewrote ("wp.lang" is wp_lang to PHP): what PHP kept ...' );
		$this->assertStringContainsString( 'wp.lang=en_US', (string) $_SERVER['HTTP_COOKIE'], '... the header keeps' );
		self::cookies( $h . '=x; wordpress_test_cookie=WP, example_cache_page=1; wp_lang=en_US' );
		ZDZ_Privacy_View::keep_cookies();
		$this->assertSame( $h . '=x; wp_lang=en_US', $_SERVER['HTTP_COOKIE'], 'a kept cookie whose value a looser reader would split stays out of the header' );
		self::cookies( '' );
		ZDZ_Privacy_View::keep_cookies();
		$this->assertSame( array(), $_COOKIE );
		$this->assertSame( '', $_SERVER['HTTP_COOKIE'], 'no cookies: nothing to do' );
	}

	public function test_the_gate_proper_takes_the_cookies_too_and_only_the_accounts(): void {
		$h = 'wordpress_logged_in_' . md5( 'example' );
		ZDZ_Test_Kpv_Request::set( 'started', true );   // no output buffer in a test
		ZDZ_Test_Kpv_Request::build( 'ajax', array(), self::own( 'zana_list_sessions' ) );
		self::cookies( $h . '=x; example_cache_page=1; zdz_view_as=3' );
		ZDZ_Privacy_View::gate();   // an allowed request: the gate returns
		$this->assertSame( array( $h ), array_keys( $_COOKIE ), 'on a site without the must-use file the account keeps only its sign-in cookie, from after_setup_theme on' );
		$this->assertSame( $h . '=x', $_SERVER['HTTP_COOKIE'] );
		$GLOBALS['zdz_test_current_user'] = ZDZ_Test_Kpv_Request::MEMBER;
		self::cookies( $h . '=x; example_cache_page=1' );
		ZDZ_Privacy_View::gate();
		$this->assertSame( array( $h, 'example_cache_page' ), array_keys( $_COOKIE ), '... and nobody else loses one' );
	}

	/* ── early_gate(): the request handed over by the must-use file ──────────── */

	public function test_early_gate_judges_once_takes_cookies_and_rebuilds_request(): void {
		$h = 'wordpress_logged_in_' . md5( 'example' );
		ZDZ_Test_Kpv_Request::build( 'ajax', array(), self::own( 'zana_list_sessions' ) );
		$GLOBALS['pagenow'] = 'not-set-yet.php';
		$_COOKIE            = array( 'example_cache_page' => '1', $h => 'privacy-account|1|2|3' );
		$_SERVER['HTTP_COOKIE'] = 'example_cache_page=1; ' . $h . '=x';
		$_REQUEST           = array( 'example_cache_page' => '1' );
		ZDZ_Privacy_View::early_gate( 'privacy-account' );
		$this->assertNull( $this->halted, 'an allowed request: nothing refused' );
		$this->assertSame( array( $h ), array_keys( $_COOKIE ), '$_COOKIE kept to the sign-in cookie' );
		$this->assertSame( self::own( 'zana_list_sessions' ), $_REQUEST, '$_REQUEST is $_GET and $_POST, as WordPress will make it (no cookie in it)' );
		$this->assertSame( 'privacy-account', ZDZ_Test_Kpv_Request::get( 'early_login' ), 'it remembers which account the cookie named' );
		ZDZ_Test_Kpv_Request::build( 'ajax', array(), self::own( 'zana_list_sessions', array( 'option_page' => 'x' ) ) );
		ZDZ_Privacy_View::early_gate( 'privacy-account' );
		$this->assertNull( $this->halted, 'it runs once a request' );

		ZDZ_Test_Kpv_Request::set( 'early_done', false );
		ZDZ_Test_Kpv_Request::build( 'ajax', array(), self::own( 'zana_list_sessions', array( 'option_page' => 'x' ) ) );
		$GLOBALS['pagenow'] = 'not-set-yet.php';
		ZDZ_Privacy_View::early_gate( 'privacy-account' );
		$this->assertIsArray( $this->halted, 'a refused one: refused' );
		$this->assertTrue( $this->halted[1], 'as JSON for an AJAX request' );
		$this->assertSame( array( 'success' => false, 'data' => array( 'code' => 'zdz_privacy_view', 'message' => 'Not available in Privacy View.', 'why' => 'field' ) ), json_decode( (string) $this->halted[2], true ),
			'its reply is the gate\'s own: success false, the code, the sentence, one word' );

		ZDZ_Test_Kpv_Request::set( 'early_done', false );
		$this->halted = null;
		ZDZ_Test_Kpv_Request::build( 'front', array( 'example_probe' => '1' ) );
		$GLOBALS['pagenow'] = 'not-set-yet.php';
		ZDZ_Privacy_View::early_gate( 'privacy-account' );
		$this->assertIsArray( $this->halted );
		$this->assertFalse( $this->halted[1], 'a page: a plain page saying so' );
		$this->assertStringContainsString( 'not available in Privacy View', (string) $this->halted[2] );
		$this->assertStringNotContainsString( 'example_probe', (string) $this->halted[2], '... with nothing the request wrote in it' );
	}

	public function test_a_refusal_before_the_theme_loads_logs_one_short_plain_line(): void {
		$log = tempnam( sys_get_temp_dir(), 'zdzkpv' );
		$was = ini_get( 'error_log' );
		ini_set( 'error_log', $log );
		try {
			ZDZ_Test_Kpv_Request::build( 'ajax', array(), self::own( 'zana_list_sessions', array( "x\nSECOND LINE\r\x07<b>Alex Riley owes \$1,234</b>" . str_repeat( 'x', 400 ) => '1' ) ) );
			$GLOBALS['pagenow'] = 'not-set-yet.php';
			ZDZ_Privacy_View::early_gate( 'privacy-account' );
		} finally {
			ini_set( 'error_log', (string) $was );
		}
		$lines = array_values( array_filter( explode( "\n", (string) file_get_contents( $log ) ), 'strlen' ) );
		unlink( $log );
		$this->assertCount( 1, $lines, 'one line, whatever the field was called' );
		$this->assertStringContainsString( 'Zorderz Privacy View: refused (before plugins) ajax-field:zana_list_sessions:x?SECOND LINE???b?Alex Riley owes ?1?234', $lines[0] );
		$this->assertLessThan( 240, strlen( $lines[0] ) );
		$this->assertSame( 0, preg_match( '/[<>$,]/', substr( $lines[0], (int) strpos( $lines[0], 'refused' ) ) ), 'plain characters only' );
	}

	public function test_a_named_account_is_always_asked_its_own_setting(): void {
		ZDZ_Test_Kpv_Request::set( 'early_login', 'privacy-account' );
		$this->assertFalse( ZDZ_Privacy_View::is_on( ZDZ_Test_Kpv_Request::MEMBER ) );
		$this->assertTrue( ZDZ_Privacy_View::is_on( ZDZ_Test_Kpv_Request::ACCOUNT ) );
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			$GLOBALS['zdz_test_current_user'] = 0;
			$this->assertTrue( ZDZ_Privacy_View::is_on(), 'asked before WordPress can say who is signed in, after the early gate found the account\'s cookie: yes, never a quiet "no"' );
		}
		ZDZ_Test_Kpv_Request::set( 'early_login', '' );
		$GLOBALS['zdz_test_current_user'] = 0;
		$this->assertFalse( ZDZ_Privacy_View::is_on(), 'with no early word, it asks WordPress, as before' );
	}
}
