"""The website's envelope (PHP, libsodium) and the hub's (Python, PyNaCl) speak the same protocol.

Throwaway key pairs are made here for each run; PHP seals and Python opens, then Python seals and PHP opens.
Skipped when there is no PHP with the sodium extension.
"""
import json
import shutil
import subprocess
import unittest
from pathlib import Path

import _env  # noqa: F401
from zorderz_hub import names
from zorderz_hub.envelope import Envelope, keygen

PHP_CLASS = Path(__file__).resolve().parents[2] / "zorderz-apps" / "apps" / "charts" / "includes" / "class-zch-envelope.php"

PHP = r"""<?php
define( 'ABSPATH', '/' );
define( 'ZCH_WEB_PARTY', %(web)s );
define( 'ZCH_HUB_PARTY', %(hub)s );
require %(cls)s;
$in   = json_decode( stream_get_contents( STDIN ), true );
$seen = array();
$replay = function ( $n ) use ( &$seen ) { if ( isset( $seen[ $n ] ) ) { return false; } $seen[ $n ] = 1; return true; };
$env  = new ZCH_Envelope( ZCH_WEB_PARTY, $in['web_secret'], array( ZCH_HUB_PARTY => array( $in['hub_public'] ) ), $replay );
$out  = array();
if ( isset( $in['seal'] ) ) {
	$out['sealed'] = $env->seal( ZCH_HUB_PARTY, 'request', $in['seal'], 'rid-from-php' );
}
if ( isset( $in['open'] ) ) {
	try {
		$inner       = $env->open( $in['open'], ZCH_HUB_PARTY, 'response', 'rid-from-py' );
		$out['body'] = $inner['body'];
	} catch ( Exception $e ) {
		$out['error'] = $e->getMessage();
	}
}
if ( isset( $in['tampered'] ) ) {
	try {
		$env->open( $in['tampered'], ZCH_HUB_PARTY, 'response' );
		$out['tampered'] = 'opened';
	} catch ( Exception $e ) {
		$out['tampered'] = 'refused';
	}
}
echo json_encode( $out );
"""


def _php_ok():
    php = shutil.which("php")
    if not php or not PHP_CLASS.exists():
        return False
    r = subprocess.run([php, "-r", "echo function_exists('sodium_crypto_box') ? 'y' : 'n';"],
                       capture_output=True, text=True, timeout=30)
    return r.stdout.strip() == "y"


@unittest.skipUnless(_php_ok(), "PHP with sodium is not available")
class CrossLanguageTest(unittest.TestCase):
    def run_php(self, payload):
        script = PHP % {"web": json.dumps(names.WEB_PARTY), "hub": json.dumps(names.HUB_PARTY),
                        "cls": json.dumps(str(PHP_CLASS))}
        tmp = _env.BASE / "cross.php"
        tmp.write_text(script)
        r = subprocess.run(["php", str(tmp)], input=json.dumps(payload), capture_output=True, text=True, timeout=60)
        self.assertEqual(r.returncode, 0, r.stderr)
        return json.loads(r.stdout)

    def test_php_seals_python_opens_and_back(self):
        web_sk, web_pk = keygen()
        hub_sk, hub_pk = keygen()
        hub = Envelope(names.HUB_PARTY, hub_sk, {names.WEB_PARTY: [web_pk]})
        body = {"op": "ask", "question": "revenue by product", "n": [1, 2.5, None, True], "text": "naïve – ok"}
        got = self.run_php({"web_secret": web_sk, "hub_public": hub_pk, "seal": body})
        inner = hub.open(got["sealed"], names.WEB_PARTY, "request")
        self.assertEqual(inner["body"], body)
        self.assertEqual(inner["rid"], "rid-from-php")
        reply = {"ok": True, "data": {"value": 1880.5, "groups": ["Northtown", "Midvale"]}}
        sealed = hub.seal(names.WEB_PARTY, "response", reply, rid="rid-from-py")
        bad = dict(sealed, ct=sealed["ct"][:-2] + ("AA" if not sealed["ct"].endswith("AA") else "BB"))
        back = self.run_php({"web_secret": web_sk, "hub_public": hub_pk, "open": sealed, "tampered": bad})
        self.assertEqual(back.get("body"), reply, back)
        self.assertEqual(back.get("tampered"), "refused")

    def test_party_labels_match_the_website(self):
        app = (PHP_CLASS.parents[1] / "app.php").read_text()
        self.assertIn(f"'ZCH_WEB_PARTY', '{names.WEB_PARTY}'", app)
        self.assertIn(f"'ZCH_HUB_PARTY', '{names.HUB_PARTY}'", app)


if __name__ == "__main__":
    unittest.main()
