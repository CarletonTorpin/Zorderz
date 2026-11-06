"""The website's Ai Workspace (PHP) and the hub's assistant runner (Python) speak the same sealed protocol.

Throwaway key pairs are made for each run. PHP seals a turn's body exactly as the workspace's fetch does (to the hub,
as a response, its request id the turn id) and the runner's pull opens it; the runner seals a site tool call and the
workspace's own tool endpoint (ZAW_Tools::answer) opens it and seals its refusal back. Skipped when there is no PHP
with the sodium extension.
"""
import hashlib
import json
import shutil
import subprocess
import time
import unittest
from pathlib import Path

import _env
from zorderz_hub import names
from zorderz_hub.access import ASSISTANT_CALLER
from zorderz_hub.assistant import link as L
from zorderz_hub.assistant import runner as R
from zorderz_hub.envelope import Envelope, keygen

ROOT = Path(__file__).resolve().parents[2]
APP = ROOT / "zorderz-apps" / "apps" / "ai-workspace"
ENVELOPE = ROOT / "zorderz-apps" / "apps" / "charts" / "includes" / "class-zch-envelope.php"

PHP = r"""<?php
define( 'ABSPATH', '/' );
define( 'ZCH_WEB_PARTY', %(web)s );
define( 'ZCH_HUB_PARTY', %(hub)s );
define( 'ZAW_DB_VERSION', '1' );
define( 'ARRAY_A', 'ARRAY_A' );
function apply_filters( $h, $v = null ) { return $v; }
function get_option( $k, $d = false ) { return $d; }
function get_userdata( $uid ) { return (object) array( 'ID' => (int) $uid, 'roles' => array( 'administrator' ) ); }
function __( $s, $d = null ) { return $s; }
class WP_Error { public $code; public function __construct( $c = '', $m = '', $d = '' ) { $this->code = $c; } }
require %(env)s;
foreach ( array( 'db', 'settings', 'grant', 'tools' ) as $f ) { require %(app)s . '/includes/class-zaw-' . $f . '.php'; }
$in   = json_decode( stream_get_contents( STDIN ), true );
$seen = array();
$replay = function ( $n ) use ( &$seen ) { if ( isset( $seen[ $n ] ) ) { return false; } $seen[ $n ] = 1; return true; };
$env  = new ZCH_Envelope( ZCH_WEB_PARTY, $in['web_secret'], array( ZCH_HUB_PARTY => array( $in['hub_public'] ) ), $replay );
$out  = array();
if ( isset( $in['body'] ) ) {
	$out['sealed'] = $env->seal( ZCH_HUB_PARTY, 'response', $in['body'], $in['body']['turn_id'] );
}
if ( isset( $in['tool'] ) ) {
	class Fake_DB {
		public $prefix = 'wp_';
		public $row;
		public function prepare( $q, ...$a ) { return vsprintf( str_replace( '%%s', "'%%s'", $q ), $a ); }
		public function get_row( $q, $o = null ) { return false !== strpos( $q, $this->row['turn_id'] ) ? $this->row : null; }
		public function query( $q ) { return 1; }
	}
	$GLOBALS['wpdb'] = new Fake_DB();
	$GLOBALS['wpdb']->row = $in['turn'];
	$r = ZAW_Tools::answer( $env, $in['tool'] );
	$out['reply'] = $r instanceof WP_Error ? array( 'error' => $r->code ) : $r;
}
$src = file_get_contents( %(app)s . '/includes/class-zaw-hub.php' );
$out['caller'] = preg_match( "/const CALLER = '([^']+)'/", $src, $m ) ? $m[1] : '';
echo json_encode( $out );
"""


def _php_ok():
    php = shutil.which("php")
    if not php or not ENVELOPE.exists() or not APP.exists():
        return False
    r = subprocess.run([php, "-r", "echo function_exists('sodium_crypto_box') ? 'y' : 'n';"],
                       capture_output=True, text=True, timeout=30)
    return r.stdout.strip() == "y"


@unittest.skipUnless(_php_ok(), "PHP with sodium is not available")
class AssistantCrossLanguageTest(unittest.TestCase):
    def run_php(self, payload):
        script = PHP % {"web": json.dumps(names.WEB_PARTY), "hub": json.dumps(names.HUB_PARTY),
                        "env": json.dumps(str(ENVELOPE)), "app": json.dumps(str(APP))}
        tmp = _env.BASE / "assistant-cross.php"
        tmp.write_text(script)
        r = subprocess.run(["php", str(tmp)], input=json.dumps(payload), capture_output=True, text=True, timeout=60)
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
        return json.loads(r.stdout)

    def test_a_turn_body_and_a_tool_call_cross_between_php_and_python(self):
        web_sk, web_pk = keygen()
        hub_sk, hub_pk = keygen()
        hub = Envelope(names.HUB_PARTY, hub_sk, {names.WEB_PARTY: [web_pk]})
        tid, k, tok = "1f" * 16, "2e" * 32, "3d" * 32
        body = {"turn_id": tid, "conv": "", "text": "Who can do it? ⟦e:abc⟧", "key": "test-key-" + "x" * 40,
                "workspace": "wrkspc_example0001", "site_tools": [], "tool_token": tok,
                "caps": {"usd": 1.0, "seconds": 600}, "headroom_usd": 1.0, "effort": "medium", "web": False,
                "models": {"primary": "example-model-large", "fallback": ""},
                "prices": {"example-model-large": [4.0, 5.0, 8.0, 0.2, 20.0]}, "geo": "", "geo_factor": 1.0,
                "search_usd": 0.01}
        got = self.run_php({"web_secret": web_sk, "hub_public": hub_pk, "body": body})
        self.assertEqual(got["caller"], ASSISTANT_CALLER, "one relay id on both sides")
        sealed = json.dumps(got["sealed"]).encode()
        s = L.settings({"assistant": {"mode": "on", "site": "https://app.example.com"}})
        link = R.SiteLink(s, hub, transport=lambda m, u, d, t, mb: (200, sealed))
        pulled = link.pull("https://app.example.com/wp-json/zorderz/v1/ai-workspace/turn-fetch/%s?k=%s" % (tid, k), tid)
        self.assertEqual(pulled, body)
        self.assertEqual(R.check_body(pulled, tid)["plan"].primary, "example-model-large")

        turn = {"id": 3, "turn_id": tid, "user_id": 7, "conv_key": "4c" * 8, "state": "running",
                "tool_hash": hashlib.sha256(tok.encode()).hexdigest(), "site_tools": '["app_roles_who_can"]',
                "created": time.strftime("%Y-%m-%d %H:%M:%S", time.gmtime())}
        seen = {}

        def transport(method, url, data, timeout, max_bytes):
            seen["url"] = url
            out = self.run_php({"web_secret": web_sk, "hub_public": hub_pk, "tool": json.loads(data.decode()),
                                "turn": turn})
            return 200, json.dumps(out["reply"]).encode()
        link = R.SiteLink(s, hub, transport=transport)
        text, err = link.tool(tid, tok, "app_roles_who_can", {"query": "work at height"})
        self.assertTrue(err)
        self.assertIn("no longer on for this person", text, "the endpoint opened the call and sealed its refusal")
        self.assertEqual(seen["url"], "https://app.example.com/wp-json/zorderz/v1/ai-workspace/tool")
        text, err = link.tool(tid, "5b" * 32, "app_roles_who_can", {})
        self.assertTrue(err, "a wrong token gets a bare 404, never a reason")


if __name__ == "__main__":
    unittest.main()
