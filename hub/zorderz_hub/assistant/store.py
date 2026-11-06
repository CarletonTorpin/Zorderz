"""Conversations at rest, sealed.

One file per conversation in a 0700 folder, each sealed with PyNaCl's SecretBox (XSalsa20-Poly1305). The key is 32
random bytes in a 0600 file in the hub's 0700 config folder, beside the hub's own secret key: secrets live in
owner-only files, never in the config. Losing the key loses the conversations, and nothing else.

A conversation file holds its id, when it was made and changed, a mark for whether a tool has answered in it, and the
messages in the provider's block format. Nothing is written in plain text: not a title, not an index. The words in it
are the ones the website sent, already coded: a customer's details never reach this disk in the clear.
"""
import json
import os
import re
import secrets
import time
from pathlib import Path

import nacl.exceptions
import nacl.secret
import nacl.utils

CONV_ID = re.compile(r"[a-f0-9]{16}")         # used with fullmatch


class StoreError(Exception):
    pass


def load_or_make_key(path):
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    try:
        os.chmod(path.parent, 0o700)
    except OSError:
        pass
    if path.exists():
        if path.stat().st_mode & 0o077:
            raise StoreError("the store key file is readable by others; fix with chmod 600")
        key = path.read_bytes()
        if len(key) != nacl.secret.SecretBox.KEY_SIZE:
            raise StoreError("the store key file is damaged")
        return key
    key = nacl.utils.random(nacl.secret.SecretBox.KEY_SIZE)
    try:
        fd = os.open(str(path), os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    except FileExistsError:                      # another process made it a moment ago: use that one
        return load_or_make_key(path)
    with os.fdopen(fd, "wb") as f:
        f.write(key)
    return key


class Store:
    def __init__(self, folder, key):
        self.dir = Path(folder)
        self.dir.mkdir(parents=True, exist_ok=True)
        os.chmod(self.dir, 0o700)
        self.box = nacl.secret.SecretBox(key)

    def _path(self, cid):
        if not isinstance(cid, str) or not CONV_ID.fullmatch(cid):
            raise StoreError("bad conversation id")
        return self.dir / ("%s.conv" % cid)

    def new(self, **extra):
        cid = secrets.token_hex(8)
        conv = {"id": cid, "created": time.time(), "updated": time.time(), "messages": []}
        conv.update(extra)
        self.save(conv)
        return conv

    def save(self, conv):
        conv["updated"] = time.time()
        sealed = self.box.encrypt(json.dumps(conv, ensure_ascii=False).encode("utf-8"))
        p = self._path(conv["id"])
        tmp = p.with_name("%s.%d.%s.tmp" % (p.name, os.getpid(), secrets.token_hex(3)))
        fd = os.open(str(tmp), os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        with os.fdopen(fd, "wb") as f:
            f.write(bytes(sealed))
        os.replace(tmp, p)

    def load(self, cid):
        p = self._path(cid)
        if not p.exists():
            raise StoreError("no such conversation")
        try:
            return json.loads(self.box.decrypt(p.read_bytes()).decode("utf-8"))
        except nacl.exceptions.CryptoError:
            raise StoreError("this conversation can't be opened with this computer's key") from None

    def delete(self, cid):
        p = self._path(cid)
        if p.exists():
            p.unlink()
