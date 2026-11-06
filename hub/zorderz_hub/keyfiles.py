"""The computer's keys: present, missing, or changed. Never created, never read out.

Now nothing in the hub makes a key by itself (only `zorderz_hub keys init`, on a brand-new install), and this
module tells the self-check and `zorderz_hub keys status`:

  analytics_key   ok | missing | changed   (changed: its fingerprint is not the one the store was built with)
  hub secret      ok | missing | readable_by_others
  tunnel token    ok | missing | not_used  (not_used: the tunnel's service file doesn't name the token file)

with the names of near-miss files beside a missing one ("found analytics_key.txt; the hub reads analytics_key").
A fingerprint is the first 12 hex of the key's SHA-256. File names and fingerprints only, never a key.
"""
import hashlib
import os
from pathlib import Path

from . import config as cf, names as nm, store

# the service file that starts a tunnel for the hub, if the business runs one (it names the token file)
TUNNEL_PLIST = Path(os.path.expanduser(os.environ["ZORDERZ_HUB_TUNNEL_SERVICE_FILE"])) \
    if os.environ.get("ZORDERZ_HUB_TUNNEL_SERVICE_FILE") else None


def fingerprint(key):
    raw = key if isinstance(key, (bytes, bytearray)) else str(key).encode()
    return hashlib.sha256(bytes(raw)).hexdigest()[:12]


def _near_name(name):
    return name.casefold().replace("-", "_").replace(" ", "_")


def near_misses(path):
    """Files beside `path` whose names start like it but aren't it (analytics_key.txt, charts-hub.secret copy)."""
    path = Path(path)
    try:
        names = sorted(p.name for p in path.parent.iterdir() if p.is_file())
    except OSError:
        return []
    want = _near_name(path.name)
    return [n for n in names if n != path.name and _near_name(n).startswith(want)]


def _plist_names(path, plist=None):
    plist = plist or TUNNEL_PLIST
    if plist is None:
        return None
    plist = Path(plist)
    try:
        return Path(path).name in plist.read_text(errors="replace")
    except OSError:
        return None                     # no tunnel job here (a test machine, a new install)


def _file_state(path, env=None):
    if env:
        return "ok", "env"
    p = Path(path)
    if p.exists() and p.stat().st_size > 0:
        return ("ok" if cf.private_file_ok(p) else "readable_by_others"), str(p)
    return "missing", str(p)


def analytics(cfg, mod=None):
    """The analytics key against the store's record."""
    from . import importer
    mod = mod or importer.adapter(cfg["adapter"])
    if not hasattr(mod, "key_status"):
        return None
    a = dict(mod.key_status())
    a["store"] = store.read_meta(cfg["store"]).get("analytics_key_fp")
    if a["state"] == "ok" and a["store"] and a["fingerprint"] != a["store"]:
        a["state"] = "changed"
    if a["state"] == "ok" and not a["store"]:
        # Its record (importer.refused_path) names the refused fingerprint, never the key.
        import json
        from . import importer
        try:
            rec = json.loads(importer.refused_path(cfg).read_text())
            if rec.get("fingerprint") == a["fingerprint"]:
                a["state"] = "changed"
        except (OSError, ValueError):
            pass
    if a["state"] == "ok" and a.get("source") not in (None, "env") and not cf.private_file_ok(a["source"]):
        a["state"] = "readable_by_others"
    return a


def report(cfg, mod=None, config_dir=None, plist=None):
    """{label: {state, source, near_misses[, fingerprint, store]}}. Never a key."""
    d = Path(config_dir or nm.CONFIG_DIR)
    out = {}
    a = analytics(cfg, mod)
    if a is not None:
        out["analytics_key"] = a
    secret = d / nm.SECRET_FILE.name
    state, source = _file_state(secret, os.environ.get(nm.SECRET_ENV))
    out["hub secret"] = {"state": state, "source": source,
                         "near_misses": near_misses(secret) if state == "missing" else []}
    token = d / "tunnel.token"
    state, source = _file_state(token)
    if state == "missing" and not _plist_names(token, plist):
        state = "not_used"
    out["tunnel token"] = {"state": state, "source": source,
                           "near_misses": near_misses(token) if state != "ok" else []}
    return out
