"""The hub's HTTP front door: sealed requests only, sealed replies, nothing in plaintext."""
import http.client
import json
import threading
import unittest

import _env  # noqa: F401
from zorderz_hub import names, server
from zorderz_hub.envelope import Envelope, ReplayCache, keygen


class FakeHub:
    def __init__(self):
        self.calls = []

        class A:
            def write(self, **kw):
                pass
        self.audit = A()

    def dispatch(self, body):
        self.calls.append(body)
        return {"ok": True, "op": body.get("op"), "data": {"echo": body.get("n")}}


class ServerTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        web_sk, web_pk = keygen()
        hub_sk, hub_pk = keygen()
        cls.web = Envelope(names.WEB_PARTY, web_sk, {names.HUB_PARTY: [hub_pk]})
        hub_env = Envelope(names.HUB_PARTY, hub_sk, {names.WEB_PARTY: [web_pk]}, replay=ReplayCache())
        cls.hub = FakeHub()
        handler = server.make_handler(cls.hub, hub_env, 65536)
        cls.httpd = server.ThreadingHTTPServer(("127.0.0.1", 0), handler)
        cls.port = cls.httpd.server_address[1]
        threading.Thread(target=cls.httpd.serve_forever, daemon=True).start()

    @classmethod
    def tearDownClass(cls):
        cls.httpd.shutdown()
        cls.httpd.server_close()

    def post(self, path, raw):
        c = http.client.HTTPConnection("127.0.0.1", self.port, timeout=10)
        c.request("POST", path, body=raw, headers={"Content-Type": "application/json"})
        r = c.getresponse()
        return r.status, r.read()

    def test_sealed_round_trip(self):
        req = self.web.seal(names.HUB_PARTY, "request", {"op": "health", "n": 7, "_access": {"caller": "cron"}})
        status, raw = self.post("/v1/rpc", json.dumps(req))
        self.assertEqual(status, 200)
        inner = self.web.open(json.loads(raw), names.HUB_PARTY, "response")
        self.assertEqual(inner["body"]["data"], {"echo": 7})
        self.assertNotIn("_access", self.hub.calls[-1])       # hub-internal flags never come from outside

    def test_plaintext_and_replays_are_rejected(self):
        status, raw = self.post("/v1/rpc", json.dumps({"op": "health"}))
        self.assertEqual((status, raw), (400, b"rejected"))
        req = json.dumps(self.web.seal(names.HUB_PARTY, "request", {"op": "health"}))
        self.assertEqual(self.post("/v1/rpc", req)[0], 200)
        self.assertEqual(self.post("/v1/rpc", req)[0], 400)
        self.assertEqual(self.post("/other", req)[0], 404)

    def test_listen_only_on_loopback_or_overlay(self):
        server.check_listen("127.0.0.1")
        with self.assertRaises(SystemExit):
            server.check_listen("0.0.0.0")
        with self.assertRaises(SystemExit):
            server.check_listen("192.168.1.10")

    def test_refuses_to_start_without_keys(self):
        with self.assertRaises(SystemExit):
            server.make_envelope({"web_public_keys": []})


if __name__ == "__main__":
    unittest.main()
