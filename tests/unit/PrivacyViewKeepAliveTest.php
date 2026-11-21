<?php
/**
 * The app's keep-alive for an account in Privacy View.
 *
 * Every reply to that account is masked. The keep-alive's reply carries a nonce, and a nonce is ten characters that
 * can read like anything: ten digits (a phone number), a number the business knows (an invoice), letters that spell
 * words. An action that hands the account a nonce may be on the allowed list only when the masks keep that reply's
 * nonce whole. This is that test:
 *
 *   - as the account, on the keep-alive's own request, the value under "nonce" comes back whole, for thousands of
 *     nonces and every shape that could look like a detail, in both pseudonym modes;
 *   - under "rest" the same masks do rewrite some of them, which is why the page reads "nonce" first;
 *   - the page's script reads "nonce" first, then "rest", and never keeps a value with a character a nonce does not
 *     have (a masked value has a "·" or brackets).
 *
 * The handler's own reply is pinned by tests/unit/test-privacy-view-keepalive.php. Neutral data only.
 *
 * @package Zorderz\Tests
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/privacy-view-gate-doubles.php';

final class PrivacyViewKeepAliveTest extends TestCase {

	protected function setUp(): void {
		ZDZ_Test_Kpv_Request::reset();
		ZDZ_Test_Kpv_Request::people();
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_known_docs'] = function () { return array( '30045' => 'invoice', '4005' => 'estimate' ); };
		ZDZ_Privacy_View::flush();
		ZDZ_Test_Kpv_Request::set( 'coder', new ZDZ_Test_Kpv_Coder() );
		ZDZ_Test_Kpv_Request::build( 'ajax', array(), array( 'action' => 'zdz_fresh_nonce' ) );
	}

	protected function tearDown(): void {
		ZDZ_Test_Kpv_Request::reset();
	}

	/** The keep-alive's reply, through the masks, as the account would be sent it. */
	private static function through( $nonce, $demo = false ) {
		ZDZ_Privacy_View::new_view();
		$r = json_decode( (string) ZDZ_Privacy_View::mask_response( json_encode( array( 'success' => true, 'data' => array( 'rest' => $nonce, 'nonce' => $nonce, 'demo' => $demo ) ) ), 'application/json; charset=UTF-8' ), true );
		return is_array( $r ) && is_array( $r['data'] ?? null ) ? $r['data'] : array();
	}

	public function test_the_masks_keep_the_nonce_whole_under_nonce(): void {
		// all digits, a phone's ten digits, a known invoice or estimate number inside, hex that spells words
		$shapes = array( '0123456789', '1234567890', '5550100142', '5550100199', '0030045000', '3004500000', '0000004005', '4005000000',
			'abcdefabcd', 'deadbeef00', 'face0cafe1', 'bead0added', 'a1b2c3d4e5', '00000000aa', 'ffffffffff' );
		foreach ( array( 'labelled pseudonyms' => true, 'the default (a new tag each reply)' => false ) as $mode => $labelled ) {
			if ( $labelled ) {
				$GLOBALS['zdz_test_filters']['zdz_privacy_view_labels'] = function () { return true; };
			} else {
				unset( $GLOBALS['zdz_test_filters']['zdz_privacy_view_labels'] );
			}
			$bad          = array();
			$rest_changed = 0;
			$n            = 0;
			foreach ( $shapes as $x ) {
				$d = self::through( $x );
				if ( ( $d['nonce'] ?? null ) !== $x ) {
					$bad[] = $x;
				}
				$rest_changed += ( $d['rest'] ?? null ) !== $x ? 1 : 0;
				$n++;
			}
			mt_srand( 20260 );
			for ( $i = 0; $i < 3000; $i++ ) {
				$x = substr( md5( 'example' . mt_rand() . '-' . $i ), -12, 10 );   // the shape WordPress makes
				if ( 0 === $i % 7 ) {
					$x = substr( str_pad( (string) mt_rand( 0, 999999999 ), 10, '0', STR_PAD_LEFT ), 0, 10 );   // all digits, often
				}
				$d = self::through( $x, 0 === $i % 2 );
				if ( ( $d['nonce'] ?? null ) !== $x ) {
					$bad[] = $x;
				}
				if ( ( $d['demo'] ?? null ) !== ( 0 === $i % 2 ) ) {
					$bad[] = 'demo:' . $x;
				}
				$rest_changed += ( $d['rest'] ?? null ) !== $x ? 1 : 0;
				$n++;
			}
			$this->assertSame( array(), array_slice( $bad, 0, 5 ), "$mode: every one of $n nonces comes back whole under \"nonce\"" );
			$this->assertGreaterThan( 0, $rest_changed, "$mode: under \"rest\" the same masks rewrote some of them" );
		}
		mt_srand();
	}

	public function test_a_reply_that_is_not_the_keep_alives_is_masked_as_ever(): void {
		$GLOBALS['zdz_test_filters']['zdz_privacy_view_labels'] = function () { return true; };
		ZDZ_Privacy_View::new_view();
		$r = json_decode( (string) ZDZ_Privacy_View::mask_response( json_encode( array( 'success' => true, 'data' => array( 'nonce' => 'a1b2c3d4e5', 'customer_name' => 'Alex Riley', 'note' => 'Call 555-010-0142.' ) ) ), 'application/json' ), true );
		$this->assertSame( 'a1b2c3d4e5', $r['data']['nonce'] ?? null );
		$this->assertStringNotContainsString( 'Riley', (string) json_encode( $r ), 'a name beside the nonce is still coded' );
		$this->assertStringNotContainsString( '0142', (string) json_encode( $r ), '... and a phone number' );
	}

	public function test_the_page_reads_nonce_first_and_never_keeps_a_masked_value(): void {
		$root = dirname( __DIR__, 2 );
		$app  = $root . '/zorderz/assets/js/app.js';
		$src  = (string) file_get_contents( $app );
		$this->assertStringContainsString( 'j.data.nonce', $src );
		$this->assertStringContainsString( '/^[A-Za-z0-9_-]{1,128}$/.test(fresh)', $src, 'the page checks the shape of what it keeps' );
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) ); // sentinel:allow fixed command, no input
		if ( '' === $node ) {
			$this->markTestSkipped( 'node is not installed here' );
		}
		$code = <<<'JS'
const fs = require('fs');
const vm = require('vm');
const src = fs.readFileSync(process.argv[1], 'utf8');
const a = src.indexOf('var _zdzNonceAt = Date.now();');
const b = src.indexOf('window.zdzFreshNonce = zdzFreshNonce;');
if (a < 0 || b <= a) { console.log(JSON.stringify({ missing: true })); process.exit(0); }
const code = src.slice(a, b);
function run(reply, status) {
  const ctx = {
    zdzData: { nonce: 'pagenonce1', ajaxUrl: '/wp-admin/admin-ajax.php' },
    URLSearchParams, Date, JSON, Error,
    sent: null,
    fetch: function (url, o) {
      ctx.sent = { url: url, method: o.method, body: String(o.body), credentials: o.credentials };
      return Promise.resolve({ ok: (status || 200) < 400, status: status || 200, text: function () { return Promise.resolve(typeof reply === 'string' ? reply : JSON.stringify(reply)); } });
    },
  };
  vm.createContext(ctx);
  vm.runInContext(code + '\nthis.__f = zdzFreshNonce;', ctx);
  return ctx.__f().then(function (r) { return { r: r, nonce: ctx.zdzData.nonce, sent: ctx.sent }; }, function (e) { return { err: e.message, nonce: ctx.zdzData.nonce, sent: ctx.sent }; });
}
(async function () {
  const out = {};
  out.first = await run({ success: true, data: { rest: 'Bcdf·7Q2KZ', nonce: 'a1b2c3d4e5', demo: false } });
  out.older = await run({ success: true, data: { rest: 'c0ffee1234', demo: true } });
  out.never = [];
  for (const v of ['Bcdf·7Q2KZ', 'phone·FRFLR', '[name]', 'Cust·AB12C', 'a1b2 c3d4', '', 12345, null, ['a1b2c3d4e5'], 'x'.repeat(129)]) {
    out.never.push(await run({ success: true, data: { nonce: v, demo: false } }));
  }
  out.rest_masked = await run({ success: true, data: { rest: 'phone·FRFLR', demo: false } });
  out.masked_then_rest = await run({ success: true, data: { nonce: '[name]', rest: 'c0ffee1234', demo: false } });
  out.signed_out = await run({ success: false, data: 'not_logged_in' }, 401);
  out.refused = await run({ success: false, data: { code: 'zdz_privacy_view' } }, 403);
  out.not_json = await run('<html>error</html>', 200);
  console.log(JSON.stringify(out));
})();
JS;
		$out = shell_exec( escapeshellarg( $node ) . ' -e ' . escapeshellarg( $code ) . ' ' . escapeshellarg( $app ) . ' 2>&1' ); // sentinel:allow every argument is escapeshellarg()'d
		$r   = json_decode( (string) $out, true );
		$this->assertIsArray( $r, 'the script ran: ' . substr( (string) $out, 0, 400 ) );
		$this->assertArrayNotHasKey( 'missing', $r, 'app.js has zdzFreshNonce' );

		$this->assertSame( 'a1b2c3d4e5', $r['first']['nonce'], '"nonce" first: a rewritten "rest" beside it is never used' );
		$this->assertSame( array( 'nonce' => 'a1b2c3d4e5', 'demo' => false ), $r['first']['r'] );
		$this->assertSame( array( 'url' => '/wp-admin/admin-ajax.php', 'method' => 'POST', 'body' => 'action=zdz_fresh_nonce', 'credentials' => 'same-origin' ), $r['first']['sent'],
			'the request carries the action and nothing else (what the gate allows the account)' );
		$this->assertSame( 'c0ffee1234', $r['older']['nonce'], 'a reply with "rest" only still works' );
		$this->assertTrue( $r['older']['r']['demo'] );
		$this->assertCount( 10, $r['never'] );
		foreach ( $r['never'] as $i => $x ) {
			$this->assertSame( 'pagenonce1', $x['nonce'], "a value a nonce never is (#$i): not kept; the page keeps its nonce" );
			$this->assertSame( 'no_answer', $x['err'] ?? null, "(#$i)" );
		}
		$this->assertSame( 'pagenonce1', $r['rest_masked']['nonce'], 'a rewritten "rest" alone: not kept' );
		$this->assertSame( 'no_answer', $r['rest_masked']['err'] ?? null );
		$this->assertSame( 'pagenonce1', $r['masked_then_rest']['nonce'], 'a "nonce" that is not one is never passed over for "rest"' );
		$this->assertSame( 'no_answer', $r['masked_then_rest']['err'] ?? null );
		$this->assertSame( 'signed_out', $r['signed_out']['err'] ?? null, 'signed out: said so' );
		$this->assertSame( 'pagenonce1', $r['signed_out']['nonce'], '... nothing kept' );
		$this->assertSame( 'no_answer', $r['refused']['err'] ?? null, 'refused: not "signed out" (the page does not reload to the sign-in page)' );
		$this->assertSame( 'no_answer', $r['not_json']['err'] ?? null, 'an error page is not an answer' );
	}
}
