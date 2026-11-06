"""There is no plaintext mode. A request that is not a valid envelope from the website gets a bare
400 "rejected" and nothing else. The server refuses to start without its keys, and refuses to
listen anywhere but loopback or a private overlay-network address: the website
reaches it through a tunnel or a reverse proxy the business runs, never an open port.
"""
import ipaddress
import json
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

from . import names
from .envelope import Envelope, EnvelopeError, ReplayCache, b64d, load_secret

TAILNET = ipaddress.ip_network("100.64.0.0/10")


def check_listen(addr):
    ip = ipaddress.ip_address(addr)
    if not (ip.is_loopback or ip in TAILNET):
        raise SystemExit("Refusing to listen on %s: loopback or a private overlay-network address only." % addr)


def make_envelope(cfg):
    keys = [b64d(k) for k in cfg.get("web_public_keys", []) if k]
    if not keys:
        raise SystemExit("No website public key in the hub config (web_public_keys): refusing to start.")
    try:
        secret = load_secret(names.HUB_PARTY)
    except (OSError, ValueError, EnvelopeError):
        raise SystemExit(f"No hub secret key ({names.SECRET_FILE}, mode 0600): restore the saved copy. "
                         "Never make a new one on a working hub: the website would be cut off.")
    return Envelope(names.HUB_PARTY, secret, {names.WEB_PARTY: keys}, replay=ReplayCache())


def finite_or_error(hub, reply):
    """The hub never sends NaN or infinity. PHP's json_decode refuses them, and the site would
    then drop the WHOLE answer without a word. A reply that holds one is replaced here by a plain
    internal error, and the op is named in the audit log and on stderr, so it fails loudly here."""
    try:
        json.dumps(reply, allow_nan=False)
        return reply
    except ValueError:
        op = reply.get("op") if isinstance(reply, dict) else None
        try:
            hub.audit.write(op=op, ok=False, error="non_finite")
        except Exception:
            pass
        print(f"zorderz-hub: refused to send a non-finite number (op {op}); see the audit log.",
              file=sys.stderr, flush=True)
        return {"ok": False, "op": op, "error": {"code": "internal",
                                                 "message": "Something went wrong on the hub."}}


def make_handler(hub, envelope, max_body):
    log_lock = threading.Lock()

    class Handler(BaseHTTPRequestHandler):
        server_version = "hub"
        sys_version = ""

        def log_message(self, fmt, *args):      # no request lines in stderr
            return

        def _bare(self, code, text=b"rejected"):
            self.send_response(code)
            self.send_header("Content-Type", "text/plain")
            self.send_header("Content-Length", str(len(text)))
            self.end_headers()
            self.wfile.write(text)

        def do_GET(self):
            self._bare(404, b"")

        do_PUT = do_DELETE = do_PATCH = do_HEAD = do_GET

        def do_POST(self):
            if self.path != "/v1/rpc":
                return self._bare(404, b"")
            try:
                length = int(self.headers.get("Content-Length", "0"))
            except ValueError:
                return self._bare(400)
            if length <= 0 or length > max_body:
                return self._bare(400)
            raw = self.rfile.read(length)
            try:
                outer = json.loads(raw)
                if not isinstance(outer, dict):
                    raise ValueError
                inner = envelope.open(outer, names.WEB_PARTY, "request")
                body = inner.get("body")
                if not isinstance(body, dict):
                    raise EnvelopeError("no body")
            except (EnvelopeError, ValueError, TypeError, KeyError):
                with log_lock:
                    hub.audit.write(op="rejected", ok=False)
                return self._bare(400)
            body = {k: v for k, v in body.items() if not str(k).startswith("_")}   # hub-internal flags are never taken from outside
            reply = finite_or_error(hub, hub.dispatch(body))
            sealed = json.dumps(envelope.seal(names.WEB_PARTY, "response", reply,
                                              rid=inner["rid"])).encode()
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(sealed)))
            self.send_header("Cache-Control", "no-store")
            self.end_headers()
            self.wfile.write(sealed)

    return Handler


def serve(cfg, hub):
    check_listen(cfg["listen"])
    envelope = make_envelope(cfg)
    handler = make_handler(hub, envelope, int(cfg["limits"]["body_bytes"]))
    httpd = ThreadingHTTPServer((cfg["listen"], int(cfg["port"])), handler)
    httpd.daemon_threads = True
    return httpd
