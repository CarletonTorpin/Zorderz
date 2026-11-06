import unittest

import _env  # noqa: F401  (scratch folders first)
from zorderz_hub import names
from zorderz_hub.envelope import Envelope, EnvelopeError, ReplayCache, b64d, b64e, key_id, keygen, public_of

WEB, HUB = names.WEB_PARTY, names.HUB_PARTY


def pair():
    web_sk, web_pk = keygen()
    hub_sk, hub_pk = keygen()
    web = Envelope(WEB, web_sk, {HUB: [hub_pk]})
    hub = Envelope(HUB, hub_sk, {WEB: [web_pk]})
    return web, hub


class EnvelopeTest(unittest.TestCase):
    def test_round_trip_both_ways(self):
        web, hub = pair()
        req = web.seal(HUB, "request", {"op": "health", "n": 1})
        inner = hub.open(req, WEB, "request")
        self.assertEqual(inner["body"], {"op": "health", "n": 1})
        resp = hub.seal(WEB, "response", {"ok": True}, rid=inner["rid"])
        back = web.open(resp, HUB, "response", expect_rid=inner["rid"])
        self.assertEqual(back["body"], {"ok": True})

    def test_outer_shape_and_key_ids(self):
        web, hub = pair()
        out = web.seal(HUB, "request", {})
        self.assertEqual(sorted(out), ["ct", "kid_from", "kid_to", "nonce", "v"])
        self.assertEqual(out["kid_to"], hub.my_kid)
        self.assertEqual(len(out["kid_from"]), 12)
        self.assertNotIn("=", out["ct"])                 # base64url without padding
        self.assertEqual(key_id(hub.public_key), hub.my_kid)

    def test_replay_is_refused(self):
        web, hub = pair()
        req = web.seal(HUB, "request", {})
        hub.open(req, WEB, "request")
        with self.assertRaisesRegex(EnvelopeError, "replay"):
            hub.open(req, WEB, "request")

    def test_stale_and_future_are_refused(self):
        web, hub = pair()
        import time
        old = web.seal(HUB, "request", {}, now=int(time.time()) - 600)
        with self.assertRaisesRegex(EnvelopeError, "timestamp"):
            hub.open(old, WEB, "request")
        future = web.seal(HUB, "request", {}, now=int(time.time()) + 600)
        with self.assertRaisesRegex(EnvelopeError, "timestamp"):
            hub.open(future, WEB, "request")

    def test_wrong_type_rid_and_sender(self):
        web, hub = pair()
        with self.assertRaisesRegex(EnvelopeError, "message type"):
            hub.open(web.seal(HUB, "request", {}), WEB, "response")
        resp = hub.seal(WEB, "response", {}, rid="abc")
        with self.assertRaisesRegex(EnvelopeError, "does not match"):
            web.open(resp, HUB, "response", expect_rid="xyz")
        stranger_sk, _ = keygen()
        stranger = Envelope(WEB, stranger_sk, {HUB: [b64e(hub.public_key)]})
        with self.assertRaisesRegex(EnvelopeError, "unknown sender"):
            hub.open(stranger.seal(HUB, "request", {}), WEB, "request")

    def test_tampering_is_refused(self):
        web, hub = pair()
        req = web.seal(HUB, "request", {"a": 1})
        ct = bytearray(b64d(req["ct"]))
        ct[-1] ^= 1
        bad = dict(req, ct=b64e(bytes(ct)))
        with self.assertRaisesRegex(EnvelopeError, "cannot open"):
            hub.open(bad, WEB, "request")
        with self.assertRaises(EnvelopeError):
            hub.open(dict(req, v=True), WEB, "request")     # a bool is not the version number
        with self.assertRaises(EnvelopeError):
            hub.open(dict(req, kid_to="000000000000"), WEB, "request")

    def test_strict_base64url(self):
        with self.assertRaises(EnvelopeError):
            b64d("abc+def")
        with self.assertRaises(EnvelopeError):
            b64d("ab cd")
        self.assertEqual(b64d(b64e(b"\x00\xff")), b"\x00\xff")

    def test_public_of_and_newest_key_first(self):
        sk, pk = keygen()
        self.assertEqual(public_of(sk), pk)
        web_sk, _ = keygen()
        hub_sk, hub_pk = keygen()
        _, old_pk = keygen()
        web = Envelope(WEB, web_sk, {HUB: [hub_pk, old_pk]})
        self.assertEqual(web.seal(HUB, "request", {})["kid_to"], key_id(b64d(hub_pk)))

    def test_replay_cache_window(self):
        rc = ReplayCache(window=10)
        self.assertTrue(rc.check_and_add("n1", 100))
        self.assertFalse(rc.check_and_add("n1", 105))
        self.assertTrue(rc.check_and_add("n1", 120))     # outside the window again


if __name__ == "__main__":
    unittest.main()
