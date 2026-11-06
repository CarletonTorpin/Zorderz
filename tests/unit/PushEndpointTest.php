<?php
/**
 * Web Push endpoints: the server stores and sends to a browser's push endpoint only when it is on a known push
 * service (ZDZ_Push_Endpoint), and both apps that send push (the chat's "Chat answers", Messages' notices and
 * messages) check it when a browser subscribes and again before every send.
 *
 *   - Accepted: https on port 443, on fcm.googleapis.com, updates.push.services.mozilla.com, web.push.apple.com,
 *     *.push.apple.com or *.notify.windows.com.
 *   - Refused: http, any IP address (dotted, short, decimal, hex, IPv6), loopback and internal names, user names or
 *     passwords, any other port, fragments, backslash and "@" tricks, a host that only ends like a push service.
 *   - zdz_push_endpoint_hosts can add a host, never an IP address, a scheme, a port or "*.com", and never remove one.
 *   - post() never reaches the network for a refused endpoint, and always sends with redirects off and private
 *     addresses refused.
 *   - A stored row on an unknown host is dropped at send time and never sent to; a refusal reaches the person as the
 *     status code in words, never any of the service's reply.
 *
 * Neutral data only (example.com, invented tokens).
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/messaging-test-doubles.php';
require_once dirname( __DIR__, 2 ) . '/zorderz/inc/class-zdz-push-endpoint.php';
require_once dirname( __DIR__, 2 ) . '/zorderz-apps/apps/analytics/includes/class-zana-push.php';

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''; }
}

/** The messaging database stand-in, also recording deletes. */
class ZDZ_Test_Secfix_Push_DB extends ZDZ_Test_Msg_DB {
	public $deleted = array();
	public function delete( ...$a ) {
		$this->deleted[] = $a;
		return 1;
	}
	public function get_charset_collate() { return ''; }
}

final class PushEndpointTest extends TestCase {

	const FCM     = 'https://fcm.googleapis.com/fcm/send/dExampleToken0:APA91bExampleExampleExample_-';
	const MOZILLA = 'https://updates.push.services.mozilla.com/wpush/v2/gAAAAABexampleexampleexample';
	const APPLE   = 'https://web.push.apple.com/QExampleExampleExample-_';
	const WINDOWS = 'https://wns2-by3p.notify.windows.com/w/?token=BQYAAABexample%2bexample%3d%3d';

	/** @var array[] every call that reached the transport: [ url, args ] */
	private $sent = array();

	protected function setUp(): void {
		zdz_test_msg_reset();
		zdz_test_msg_people( array( 5 => 'staff', 6 => 'shared' ) );
		$GLOBALS['wpdb']               = new ZDZ_Test_Secfix_Push_DB();
		$this->sent                    = array();
		$sent                          = &$this->sent;
		$status                        = &$this->status;
		ZDZ_Push_Endpoint::$transport  = function ( $url, $args ) use ( &$sent, &$status ) {
			$sent[] = array( $url, $args );
			return array( 'response' => array( 'code' => $status ), 'body' => '{"reason":"internal-host-detail 10.0.0.7 admin panel"}' );
		};
		$this->status = 201;
	}

	/** @var int what the transport answers */
	private $status = 201;

	protected function tearDown(): void {
		ZDZ_Push_Endpoint::$transport = null;
		zdz_test_msg_teardown();
	}

	/* ── the check ───────────────────────────────────────────────────────── */

	public function test_the_browser_push_services_are_accepted(): void {
		foreach ( array( self::FCM, self::MOZILLA, self::APPLE, self::WINDOWS,
			'https://api.push.apple.com/3/device/example', 'https://FCM.googleapis.com:443/fcm/send/abc' ) as $u ) {
			$this->assertSame( $u, ZDZ_Push_Endpoint::validate( $u ), $u );
		}
		$this->assertSame( self::FCM, ZDZ_Push_Endpoint::validate( '  ' . self::FCM . "\n" ), 'trimmed' );
	}

	public function test_internal_and_tricky_endpoints_are_refused(): void {
		$bad = array(
			'http://fcm.googleapis.com/fcm/send/abc',                     // not https
			'https://127.0.0.1/x', 'https://10.0.0.7/x', 'https://169.254.169.254/latest/meta-data/',
			'https://127.1/x', 'https://2130706433/x', 'https://0x7f.0x1/x', 'https://[::1]/x', 'https://[fd00::1]:443/x',
			'https://localhost/x', 'https://intranet/x', 'https://push.example.com/x', 'https://app.example.com/wp-admin/',
			'https://user:pw@fcm.googleapis.com/fcm/send/abc',            // credentials
			'https://fcm.googleapis.com@10.0.0.7/x',                      // "@" trick
			'https://fcm.googleapis.com:8443/fcm/send/abc',               // another port
			'https://fcm.googleapis.com:0443/fcm/send/abc',
			'https://fcm.googleapis.com/fcm/send/abc#frag',
			'https://fcm.googleapis.com\\@10.0.0.7/x',                    // backslash
			'https://fcm.googleapis.com.attacker.example/x',              // only starts like one
			'https://evilpush.apple.com/x',                               // only ends like one
			'https://push.apple.com/x',                                   // a wildcard never matches the domain itself
			'https://notify.windows.com/x',
			'https://fcm.googleapis.com./x',                              // trailing dot
			'https://fcm.googleapis.com/fcm/send/a b', "https://fcm.googleapis.com/fcm/send/a\x00b", 'https://fcm.googleapis.com/"x',
			'https://', '', 'javascript:alert(1)', 'https:///fcm.googleapis.com/x',
			'https://' . str_repeat( 'a', 2050 ) . '.push.apple.com/x',
		);
		foreach ( $bad as $u ) {
			$this->assertSame( '', ZDZ_Push_Endpoint::validate( $u ), 'refused: ' . $u );
		}
		$this->assertSame( '', ZDZ_Push_Endpoint::validate( array( self::FCM ) ) );
		$this->assertSame( '', ZDZ_Push_Endpoint::validate( null ) );
	}

	public function test_a_business_can_add_a_host_but_never_an_address_or_a_wider_rule(): void {
		$GLOBALS['zdz_test_filters']['zdz_push_endpoint_hosts'] = function ( $hosts ) {
			return array( 'push.example.com', '*.push.example.org', '10.0.0.7', '127.1', '[::1]', 'https://other.example.net',
				'example.net:8443', '*.com', 'localhost', '*', 42, array( 'x' ) );   // and Core's own list left out
		};
		$hosts = ZDZ_Push_Endpoint::hosts();
		$this->assertContains( 'push.example.com', $hosts );
		$this->assertContains( '*.push.example.org', $hosts );
		foreach ( ZDZ_Push_Endpoint::CORE_HOSTS as $core ) {
			$this->assertContains( $core, $hosts, 'a filter never removes a push service' );
		}
		$this->assertCount( count( ZDZ_Push_Endpoint::CORE_HOSTS ) + 2, $hosts, 'every other rule is ignored' );
		$this->assertSame( 'https://push.example.com/x', ZDZ_Push_Endpoint::validate( 'https://push.example.com/x' ) );
		$this->assertSame( 'https://a.push.example.org/x', ZDZ_Push_Endpoint::validate( 'https://a.push.example.org/x' ) );
		$this->assertSame( '', ZDZ_Push_Endpoint::validate( 'https://10.0.0.7/x' ) );
		$this->assertSame( '', ZDZ_Push_Endpoint::validate( 'http://push.example.com/x' ), 'still https only' );
		$this->assertSame( '', ZDZ_Push_Endpoint::validate( 'https://push.example.com:8443/x' ), 'still port 443 only' );
		$this->assertSame( '', ZDZ_Push_Endpoint::validate( 'https://example.com/x' ) );
	}

	public function test_post_never_sends_to_a_refused_endpoint_and_always_turns_redirects_off(): void {
		$r = ZDZ_Push_Endpoint::post( 'https://10.0.0.7/x', array( 'body' => 'x' ) );
		$this->assertInstanceOf( 'WP_Error', $r );
		$this->assertSame( 'zdz_push_endpoint_refused', $r->get_error_code() );
		$this->assertSame( array(), $this->sent, 'nothing reached the network' );

		ZDZ_Push_Endpoint::post( self::MOZILLA, array( 'body' => 'x', 'redirection' => 5, 'reject_unsafe_urls' => false ) );
		$this->assertCount( 1, $this->sent );
		$this->assertSame( 0, $this->sent[0][1]['redirection'], 'redirects are off whatever the caller asked' );
		$this->assertTrue( $this->sent[0][1]['reject_unsafe_urls'], 'private addresses refused' );
	}

	public function test_status_words_are_plain_and_never_carry_the_reply(): void {
		foreach ( array( 0, 301, 400, 401, 403, 404, 410, 413, 429, 500, 503 ) as $code ) {
			$w = ZDZ_Push_Endpoint::status_words( $code );
			$this->assertNotSame( '', $w );
			$this->assertDoesNotMatchRegularExpression( '/[0-9]/', $w, 'words, not the code again' );
		}
	}

	/* ── the chat's "Chat answers" channel ───────────────────────────────── */

	public function test_chat_refuses_to_store_an_endpoint_off_the_push_services(): void {
		$this->assertFalse( ZANA_Push::save_subscription( 5, 'https://10.0.0.7:8080/admin', 'p', 'a' ) );
		$this->assertFalse( ZANA_Push::save_subscription( 5, 'https://intranet.example.com/hook', 'p', 'a' ) );
		$this->assertSame( array(), $GLOBALS['wpdb']->inserted, 'nothing stored' );
		$this->assertTrue( ZANA_Push::save_subscription( 5, self::FCM, 'p', 'a' ) );
		$this->assertCount( 1, $GLOBALS['wpdb']->inserted );
	}

	public function test_chat_drops_a_stored_row_off_the_push_services_without_sending(): void {
		$r = ZANA_Push::send( array( 'id' => 3, 'endpoint' => 'https://169.254.169.254/latest/meta-data/', 'p256dh' => 'p', 'auth' => 'a' ), 'Chat', 'Your answer is ready.' );
		$this->assertFalse( $r['success'] );
		$this->assertTrue( $r['dropped'] );
		$this->assertSame( array(), $this->sent, 'never sent' );
		$this->assertCount( 1, $GLOBALS['wpdb']->deleted, 'the row is removed' );
		$this->assertSame( hash( 'sha256', 'https://169.254.169.254/latest/meta-data/' ), $GLOBALS['wpdb']->deleted[0][1]['endpoint_hash'] );
	}

	public function test_chat_never_passes_the_reply_back(): void {
		$sub          = self::subscriber();
		$this->status = 400;
		$r            = ZANA_Push::send( array( 'endpoint' => self::FCM ) + $sub, 'Chat', 'Your answer is ready.' );
		$this->assertCount( 1, $this->sent, 'sent once' );
		$this->assertSame( 0, $this->sent[0][1]['redirection'] );
		$this->assertFalse( $r['success'] );
		$this->assertSame( 400, $r['status'] );
		$this->assertStringNotContainsString( 'internal-host-detail', (string) $r['error'] );
		$words = ZANA_Push::refusal( $r, self::FCM );
		$this->assertStringNotContainsString( 'internal', $words );
		$this->assertStringNotContainsString( '10.0.0.7', $words );
		$this->assertStringContainsString( 'fcm.googleapis.com', $words );
		$this->assertStringContainsString( '(400)', $words );
		$this->assertStringNotContainsString( 'internal', ZANA_Push::refusal( array( 'status' => 500, 'error' => 'internal-host-detail' ), self::FCM ) );
	}

	/* ── Messages' notices and messages ──────────────────────────────────── */

	public function test_messages_refuses_to_store_an_endpoint_off_the_push_services(): void {
		$r = ZIM_Push::subscribe( 5, 'https://127.0.0.1:9200/_cluster', 'p', 'a', '', '' );
		$this->assertInstanceOf( 'WP_Error', $r );
		$this->assertSame( 'zim_bad_sub', $r->get_error_code() );
		$this->assertSame( array(), $GLOBALS['wpdb']->inserted );
		$this->assertTrue( is_int( ZIM_Push::subscribe( 5, self::APPLE, 'p', 'a', '', '' ) ) );
		$this->assertCount( 1, $GLOBALS['wpdb']->inserted );
	}

	public function test_messages_drops_stored_rows_off_the_push_services_without_sending(): void {
		$GLOBALS['wpdb']->on_results = function () {
			return array( (object) array( 'id' => 11, 'endpoint' => 'https://10.0.0.7/hook', 'p256dh' => 'p', 'auth' => 'a' ) );
		};
		$this->assertSame( array( 'sent' => 0, 'pruned' => 1 ), ZIM_Push::send_to_user( 5, array( 'title' => 'x' ) ) );
		$this->assertSame( array(), $this->sent, 'never sent' );
		$this->assertSame( array( 'id' => 11 ), $GLOBALS['wpdb']->deleted[0][1] );

		$this->assertSame( 'zim_endpoint_refused', ZIM_Push::send_one( 'https://localhost/x', 'p', 'a', '{}' )->get_error_code() );
		$this->assertSame( array(), $this->sent );
	}

	public function test_messages_sends_with_redirects_off_and_passes_back_only_the_code(): void {
		$sub          = self::subscriber();
		$this->status = 500;
		$r            = ZIM_Push::send_one( self::WINDOWS, $sub['p256dh'], $sub['auth'], '{"title":"x"}' );
		$this->assertCount( 1, $this->sent );
		$this->assertSame( self::WINDOWS, $this->sent[0][0] );
		$this->assertSame( 0, $this->sent[0][1]['redirection'] );
		$this->assertInstanceOf( 'WP_Error', $r );
		$this->assertStringNotContainsString( 'internal', $r->get_error_message() );
		$this->status = 201;
		$this->assertTrue( ZIM_Push::send_one( self::WINDOWS, $sub['p256dh'], $sub['auth'], '{"title":"x"}' ) );
	}

	/** A browser subscription's keys (a fresh P-256 public key and a 16-byte secret), base64url. */
	private static function subscriber(): array {
		$k   = openssl_pkey_new( array( 'curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC ) );
		$d   = openssl_pkey_get_details( $k );
		$raw = "\x04" . str_pad( $d['ec']['x'], 32, "\0", STR_PAD_LEFT ) . str_pad( $d['ec']['y'], 32, "\0", STR_PAD_LEFT );
		$b64 = function ( $s ) { return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' ); };
		return array( 'p256dh' => $b64( $raw ), 'auth' => $b64( random_bytes( 16 ) ) );
	}
}
