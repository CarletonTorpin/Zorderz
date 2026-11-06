"""The sealed envelope: the hub's half of the protocol the website speaks.

Every request from the website and every answer back is one libsodium crypto_box (X25519 key
agreement, XSalsa20-Poly1305). A box the website made with its secret key and the hub's public
key opens only here, and only if it was made with the website's secret: one operation gives
secrecy and proof of sender. Any change to the bytes makes it fail.

Wire format (the HTTP body, JSON):

    {"v": 1, "kid_from": "<12 hex>", "kid_to": "<12 hex>", "nonce": "<base64url>", "ct": "<base64url>"}

Inside the box:

    {"v": 1, "from": <party>, "to": <party>, "typ": "request" | "response", "ts": <unix seconds>,
     "rid": "<request id; a response repeats its request's rid>", "body": {...}}

A key id is the first 12 hex characters of the SHA-256 of a public key. Keys travel as unpadded
base64url. The receiver refuses, in this order: an unknown version or a box not addressed to its
own key; an unknown sender key; a box that does not open; the wrong pair of parties (a message
redirected); the wrong message type (a response replayed as a request); a timestamp more than two
minutes from now; for a response, a rid that is not the request's; a nonce already seen in the last
ten minutes.

The website's side is zorderz-apps/apps/charts/includes/class-zch-envelope.php; tests/test_envelope.py
and tests/test_cross_language.py prove the two interoperate.
"""
import base64
import hashlib
import json
import os
import secrets
import stat
import threading
import time
from pathlib import Path

from nacl.exceptions import CryptoError
from nacl.public import Box, PrivateKey, PublicKey

from . import names

VERSION = 1
MAX_SKEW_SECONDS = 120
REPLAY_WINDOW_SECONDS = 600
NONCE_BYTES = Box.NONCE_SIZE           # 24
KEY_BYTES = 32
PARTIES = (names.WEB_PARTY, names.HUB_PARTY)


class EnvelopeError(Exception):
    """A message that must be refused. The text names the check, never a key or the body."""


def b64e(raw):
    return base64.urlsafe_b64encode(bytes(raw)).rstrip(b"=").decode("ascii")


def b64d(text):
    if isinstance(text, (bytes, bytearray)):
        text = bytes(text).decode("ascii", "replace")
    text = str(text).strip()
    if not text or any(c not in "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_=" for c in text):
        raise EnvelopeError("bad encoding")
    try:
        return base64.urlsafe_b64decode(text + "=" * (-len(text) % 4))
    except (ValueError, TypeError):
        raise EnvelopeError("bad encoding")


def key_id(public_key):
    return hashlib.sha256(bytes(public_key)).hexdigest()[:12]


def keygen():
    """A new key pair: (secret base64url, public base64url)."""
    sk = PrivateKey.generate()
    return b64e(bytes(sk)), b64e(bytes(sk.public_key))


def public_of(secret_b64):
    return b64e(bytes(PrivateKey(_key_bytes(secret_b64)).public_key))


def _key_bytes(k):
    raw = bytes(k) if isinstance(k, (bytes, bytearray)) and len(k) == KEY_BYTES else b64d(k)
    if len(raw) != KEY_BYTES:
        raise EnvelopeError("bad key")
    return raw


class ReplayCache:
    """Nonces seen in the replay window, in memory, safe across the server's threads."""

    def __init__(self, window=REPLAY_WINDOW_SECONDS):
        self.window = window
        self.seen = {}
        self._lock = threading.Lock()

    def check_and_add(self, nonce, now):
        with self._lock:
            cutoff = now - self.window
            if len(self.seen) > 1024:
                for n in [n for n, t in self.seen.items() if t < cutoff]:
                    self.seen.pop(n, None)
            t = self.seen.get(nonce)
            if t is not None and t >= cutoff:
                return False
            self.seen[nonce] = now
            return True


class Envelope:
    """One side of the protocol: `me` seals to and opens from the peers it knows."""

    def __init__(self, me, secret, peers, replay=None):
        if me not in PARTIES:
            raise ValueError("unknown party")
        self.me = me
        self._sk = PrivateKey(_key_bytes(secret))
        self.public_key = bytes(self._sk.public_key)
        self.my_kid = key_id(self.public_key)
        self.peers = {}                       # party -> {kid: PublicKey}, newest first
        for party, keys in (peers or {}).items():
            for k in keys if isinstance(keys, (list, tuple)) else [keys]:
                raw = _key_bytes(k)
                self.peers.setdefault(party, {})[key_id(raw)] = PublicKey(raw)
        self.replay = replay if replay is not None else ReplayCache()

    def seal(self, to, typ, body, rid=None, now=None):
        if not self.peers.get(to) or typ not in ("request", "response"):
            raise ValueError("bad seal arguments")
        kid_to = next(iter(self.peers[to]))                # the newest key first
        inner = {"v": VERSION, "from": self.me, "to": to, "typ": typ,
                 "ts": int(now if now is not None else time.time()),
                 "rid": rid if rid is not None else b64e(secrets.token_bytes(12)), "body": body}
        nonce = secrets.token_bytes(NONCE_BYTES)
        plain = json.dumps(inner, ensure_ascii=False, separators=(",", ":"), allow_nan=False).encode("utf-8")
        ct = Box(self._sk, self.peers[to][kid_to]).encrypt(plain, nonce).ciphertext
        return {"v": VERSION, "kid_from": self.my_kid, "kid_to": kid_to, "nonce": b64e(nonce), "ct": b64e(ct)}

    def open(self, outer, expect_from, expect_typ, expect_rid=None, now=None):
        now = now if now is not None else time.time()
        if not isinstance(outer, dict):
            raise EnvelopeError("not an envelope")
        v = outer.get("v")
        if type(v) is not int or v != VERSION or outer.get("kid_to") != self.my_kid:
            raise EnvelopeError("wrong version or not addressed to this key")
        pk = self.peers.get(expect_from, {}).get(outer.get("kid_from") or "")
        if pk is None:
            raise EnvelopeError("unknown sender key")
        nonce = b64d(outer.get("nonce") or "")
        if len(nonce) != NONCE_BYTES:
            raise EnvelopeError("cannot open")
        try:
            plain = Box(self._sk, pk).decrypt(b64d(outer.get("ct") or ""), nonce)
        except CryptoError:
            raise EnvelopeError("cannot open")
        try:
            inner = json.loads(plain.decode("utf-8"))
        except (UnicodeDecodeError, ValueError):
            raise EnvelopeError("cannot open")
        iv = inner.get("v") if isinstance(inner, dict) else None
        if type(iv) is not int or iv != VERSION or inner.get("from") != expect_from or inner.get("to") != self.me:
            raise EnvelopeError("wrong parties")
        if inner.get("typ") != expect_typ:
            raise EnvelopeError("wrong message type")
        ts = inner.get("ts")
        if type(ts) is not int or abs(now - ts) > MAX_SKEW_SECONDS:
            raise EnvelopeError("stale or future timestamp")
        if expect_rid is not None and not secrets.compare_digest(str(expect_rid), str(inner.get("rid", ""))):
            raise EnvelopeError("response does not match request")
        if not self.replay.check_and_add(outer.get("nonce"), now):
            raise EnvelopeError("replay")
        return inner


# ---------------------------------------------------------------- keys on disk

def secret_path(party=None):
    """Where a party's secret key file lives (the hub keeps only its own)."""
    if party in (None, names.HUB_PARTY):
        return names.SECRET_FILE
    return names.CONFIG_DIR / f"{party}.secret"


def private_file_ok(path):
    p = Path(path)
    return p.exists() and not (p.stat().st_mode & (stat.S_IRWXG | stat.S_IRWXO))


def load_secret(party=None):
    """The hub's secret key: from the environment, else from its 0600 file. Never made here."""
    env = os.environ.get(names.SECRET_ENV, "").strip()
    if env:
        return _key_bytes(env)
    p = secret_path(party)
    if not private_file_ok(p):
        raise OSError("no private secret key file")
    return _key_bytes(p.read_text().strip())


def write_secret(party=None, path=None):
    """Make and save a new secret key (0600), refusing to overwrite one. Returns the public key, base64url."""
    p = Path(path or secret_path(party))
    if p.exists():
        raise FileExistsError(f"{p} already exists: a working hub's key is never replaced by accident")
    p.parent.mkdir(parents=True, exist_ok=True)
    os.chmod(p.parent, 0o700)
    secret, public = keygen()
    fd = os.open(str(p), os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, "w") as fh:
        fh.write(secret + "\n")
    return public
