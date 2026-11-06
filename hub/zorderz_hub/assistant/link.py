"""The assistant's link to the website: the three ops the hub server answers, with files only.

    WEBSITE (the Ai Workspace app)          HUB SERVER (these ops)                  RUNNER (runner.py)
    assistant_turn {turn_id, fetch} ──────► checks the ticket, writes
                                            jobs/<turn_id>.json, answers at once ─► sees it, PULLS the turn's body once
    GET <site><fetch_path><id>?k=… ◄─────────────────────────────────────────────── from the website's own address
                                                                                    runs the turn; a site tool is POSTed
    POST <site><tool_path> (sealed) ◄────────────────────────────────────────────── back to the website, sealed
    assistant_status {turn_id, offset} ───► reads <turn_id>.state.json and the sealed events: one page (<= 48 KB)
    assistant_cancel {turn_id} ───────────► writes <turn_id>.stop; the runner stops before its next call

The ops only write and read files in the job folder, so a runner that crashes never takes the hub down. The runner
knows a turn id, a single-use link, a tool token, a budget and a tool list: never a person. The website sends no user
id (its `user` is a fixed word), and nothing here keeps one.

The job folder (<hub home>/assistant/jobs, 0700; every file 0600; cleared after keep_hours):
  <id>.json          the ticket: the turn id and its single-use fetch link (good for 15 minutes on the website)
  <id>.state.json    numbers and code words: state, phase, usage, the conversation id, the stop reason
  <id>.events        what the website polls: progress lines and the answer, CODED by the website's customer-detail
                     coder, and sealed here at rest with the assistant's store key, so no turn's words sit on the hub
                     computer's disk in the clear
  <id>.stop          a cancel
  runner.beat        the runner's heartbeat (pid, time, version)

Money: every status answer carries the engine's month from its own ledger (ledger.py): everything it spent, and the
part that is NOT the website's own turns (`other_usd`), which the website adds to its own total. The website's own
turns are rows in the website's Ai ledger, so they are never counted twice.
"""
import json
import os
import re
import secrets
import time
import urllib.parse
from pathlib import Path

from .. import access as acc
from .. import names

DEFAULTS = {
    "mode": "off",                  # "on" to answer turns
    "site": "",                     # the website's own address (https://…); empty = every turn refused
    "max_jobs": 8,                  # turns waiting or running at once
    "parallel": 2,                  # turns the runner works on side by side
    "keep_hours": 24,               # a finished turn's files are cleared after this
    "ticket_max_age_s": 240,        # a ticket the runner reaches later than this is never pulled
    "fetch_seconds": 30,
    "tool_seconds": 60,
    "max_body": 1048576,
    "month_cap_usd": None,          # the engine's own monthly line, on top of the website's; None = none of its own
    "fetch_path": "/wp-json/zorderz/v1/ai-workspace/turn-fetch/",
    "tool_path": "/wp-json/zorderz/v1/ai-workspace/tool",
    "instructions_file": "",        # the business's own instructions for the assistant (plain text); empty = none
}
TURN_ID = re.compile(r"[a-f0-9]{32}")           # used with fullmatch
CONV_ID = re.compile(r"[a-f0-9]{16}")
TOKEN = re.compile(r"[a-f0-9]{64}")
PAGE_BYTES = 48 * 1024                            # one status page's events, as sent
EVENT_BYTES = 30000                               # one event at most, as sent (the runner cuts the answer by it)
TICKET_BYTES = 16 * 1024                          # a ticket is small: an id and a link
FINAL = ("done", "error", "cancelled", "failed", "expired")
SITE_CALLER = acc.ASSISTANT_CALLER                # the one id of the website's relay: access block and engine ledger
BEAT_STALE_S = 30


class LinkError(Exception):
    """A refusal; its message is a short code word the ops turn into a plain sentence."""


def settings(cfg):
    s = dict(DEFAULTS)
    s.update((cfg or {}).get("assistant") or {})
    return s


def home(cfg):
    return Path((cfg or {}).get("assistant_dir") or (names.HUB_HOME / "assistant"))


def jobs_dir(cfg, make=True):
    d = home(cfg) / "jobs"
    if make:
        d.mkdir(parents=True, exist_ok=True)
        for p in (home(cfg), d):
            try:
                os.chmod(p, 0o700)
            except OSError:
                pass
    return d


def ledger_file(cfg):
    return Path((cfg or {}).get("assistant_ledger") or (names.HUB_HOME / "logs" / "assistant.jsonl"))


def store_key_file(cfg):
    return Path((cfg or {}).get("assistant_store_key") or (names.CONFIG_DIR / "assistant-store.key"))


def month_cap(cfg):
    """The engine's own monthly line in USD, or None when it has none (the website's budget governs every turn)."""
    v = settings(cfg).get("month_cap_usd")
    if v is None or isinstance(v, bool):
        return None
    try:
        v = float(v)
    except (TypeError, ValueError):
        return 0.0                                  # a line nobody can read is no room at all
    return max(0.0, v)


# ------------------------------------------------------------------------------------------------- small file helpers
def write_json(path, data):
    path = Path(path)
    tmp = path.with_name("%s.%d.%s.tmp" % (path.name, os.getpid(), secrets.token_hex(3)))
    fd = os.open(str(tmp), os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w", encoding="utf-8") as f:
        json.dump(data, f, separators=(",", ":"))
    os.replace(tmp, path)


def write_json_new(path, data):
    """Create path with data only if it doesn't exist yet, in one step (a link can't overwrite). -> True if made.
    The runner's first state and a cancel's 'cancelled' are both made this way, so exactly one of them wins."""
    path = Path(path)
    tmp = path.with_name("%s.%d.%s.tmp" % (path.name, os.getpid(), secrets.token_hex(3)))
    fd = os.open(str(tmp), os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w", encoding="utf-8") as f:
        json.dump(data, f, separators=(",", ":"))
    try:
        os.link(tmp, path)
        return True
    except FileExistsError:
        return False
    finally:
        try:
            os.unlink(tmp)
        except OSError:
            pass


def read_json(path, default=None):
    try:
        with open(path, encoding="utf-8") as f:
            return json.load(f)
    except (OSError, ValueError):
        return default


def size(e):
    """An event's size as the envelope sends it (JSON with ensure_ascii: a code bracket is 6 bytes, an emoji 12)."""
    return len(json.dumps(e, separators=(",", ":"), ensure_ascii=True))


def utc(ts=None):
    return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(ts if ts is not None else time.time()))


# ------------------------------------------------------------------------------------------------- the website's address
def site_parts(site):
    s = urllib.parse.urlsplit(str(site))
    return (s.scheme or "").lower(), (s.hostname or "").lower(), s.port


def same_site(url, site):
    """https on the website's own host and port (http only on loopback, for tests); no user, no password."""
    try:
        u = urllib.parse.urlsplit(str(url))
        port = u.port
    except ValueError:
        return False
    scheme, host, sport = site_parts(site)
    if not host or not u.hostname or u.hostname.lower() != host or port != sport or u.username or u.password:
        return False
    if u.scheme != "https" and not (u.scheme == "http" and u.hostname in ("127.0.0.1", "localhost")):
        return False
    return u.scheme == scheme


def fetch_ok(url, s, turn_id):
    """The ticket's link: exactly <site><fetch_path><this turn id>?k=<64 hex>, nothing else."""
    if not isinstance(url, str) or len(url) > 600 or not same_site(url, s["site"]):
        return False
    u = urllib.parse.urlsplit(url)
    path = urllib.parse.unquote(u.path)
    if ".." in path or path != str(s["fetch_path"]) + turn_id or u.fragment:
        return False
    return bool(re.fullmatch(r"k=[a-f0-9]{64}", u.query or ""))


def tool_url(s):
    scheme, host, port = site_parts(s["site"])
    return "%s://%s%s%s" % (scheme, host, (":%d" % port) if port else "", s["tool_path"])


# ------------------------------------------------------------------------------------------------- sealed events
class Events:
    """A turn's progress lines and answer, sealed at rest with the assistant's store key (SecretBox)."""

    def __init__(self, key):
        import nacl.secret
        self.box = nacl.secret.SecretBox(key)

    def write(self, path, events):
        sealed = self.box.encrypt(json.dumps(events, ensure_ascii=False).encode("utf-8"))
        path = Path(path)
        tmp = path.with_name("%s.%d.%s.tmp" % (path.name, os.getpid(), secrets.token_hex(3)))
        fd = os.open(str(tmp), os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        with os.fdopen(fd, "wb") as f:
            f.write(bytes(sealed))
        os.replace(tmp, path)

    def read(self, path):
        try:
            raw = Path(path).read_bytes()
        except OSError:
            return []
        try:
            out = json.loads(self.box.decrypt(raw).decode("utf-8"))
        except Exception:                                  # a damaged or foreign file is no events, never a crash
            return []
        return out if isinstance(out, list) else []


def page(events, offset, budget=PAGE_BYTES):
    """Events from offset on, whole, until the page would pass `budget` bytes as sent (at least one; the runner keeps
    every event under EVENT_BYTES, so one always fits). -> (page, next | None)."""
    try:
        offset = max(0, int(offset or 0))
    except (TypeError, ValueError):
        offset = 0
    out, total = [], 0
    for i in range(offset, len(events)):
        e = events[i]
        n = size(e) + 1
        if out and total + n > budget:
            return out, i
        out.append(e)
        total += n
    return out, None


# ------------------------------------------------------------------------------------------------- the ops
class Link:
    """The three ops, as the hub server runs them: files only."""

    def __init__(self, cfg):
        self.cfg = cfg
        self.s = settings(cfg)

    def _paths(self, tid):
        d = jobs_dir(self.cfg)
        return d / (tid + ".json"), d / (tid + ".state.json"), d / (tid + ".events"), d / (tid + ".stop")

    def runner_up(self, now=None):
        b = read_json(jobs_dir(self.cfg) / "runner.beat", {}) or {}
        now = time.time() if now is None else now
        return isinstance(b.get("ts"), (int, float)) and now - b["ts"] <= BEAT_STALE_S

    def money(self):
        """The engine's month, from its own ledger (numbers only). other_usd: everything but the website's own turns."""
        try:
            from .ledger import Ledger
            led = Ledger(ledger_file(self.cfg))
            month = time.strftime("%Y-%m", time.gmtime())
            everything = led.month_usd(month) + led.holds_usd()
            other = led.month_usd(month, exclude_caller=SITE_CALLER) + led.holds_usd(exclude_caller=SITE_CALLER)
            return {"month": month, "engine_usd": round(everything, 4), "other_usd": round(other, 4),
                    "cap_usd": month_cap(self.cfg), "as_of": utc()}
        except Exception:
            return {"error": "unreadable"}               # the website then keeps its last figure, or the cap

    def _live_jobs(self):
        n, now = 0, time.time()
        keep = float(self.s["keep_hours"]) * 3600
        for p in jobs_dir(self.cfg).glob("*.json"):
            if p.name.endswith(".state.json") or not TURN_ID.fullmatch(p.stem):
                continue
            st = read_json(p.with_name(p.stem + ".state.json"), {}) or {}
            if st.get("state") in FINAL:
                continue
            try:
                if now - p.stat().st_mtime > keep:
                    continue
            except OSError:
                continue
            n += 1
        return n

    def _on(self):
        if str(self.s.get("mode")).lower() != "on" or not str(self.s.get("site") or "").strip():
            raise LinkError("off")

    def turn(self, body):
        self._on()
        tid = body.get("turn_id")
        if not isinstance(tid, str) or not TURN_ID.fullmatch(tid):
            raise LinkError("bad_request")
        ticket = {"turn_id": tid, "fetch": body.get("fetch")}
        sent = {k: v for k, v in body.items() if not str(k).startswith("_")}    # what the website sent, not our flags
        if len(json.dumps(sent, ensure_ascii=False, default=str).encode("utf-8")) > TICKET_BYTES:
            raise LinkError("too_big")                    # a ticket is an id and a link; a turn's body is pulled
        if not fetch_ok(ticket["fetch"], self.s, tid):
            raise LinkError("bad_url")
        tpath, spath, _e, _s = self._paths(tid)
        if tpath.exists():                                 # the website asked twice (a retry): the same turn
            st = read_json(spath, {}) or {}
            return {"turn": tid, "state": st.get("state", "queued"), "runner": "up" if self.runner_up() else "down"}
        if self._live_jobs() >= int(self.s["max_jobs"]):
            raise LinkError("busy")
        now = time.time()
        write_json(tpath, dict(ticket, created_ts=now, created_utc=utc(now)))
        return {"turn": tid, "state": "queued", "runner": "up" if self.runner_up() else "down"}

    def status(self, body, key_loader=None):
        out = {"money": self.money(), "runner": "up" if self.runner_up() else "down"}
        tid = body.get("turn_id")
        if tid in (None, ""):
            return out
        if not isinstance(tid, str) or not TURN_ID.fullmatch(tid):
            raise LinkError("bad_request")
        tpath, spath, epath, stop = self._paths(tid)
        if not tpath.exists() and not spath.exists():
            raise LinkError("not_found")
        st = read_json(spath, {}) or {}
        out["turn"] = {k: st[k] for k in ("state", "phase", "conv", "stop", "usage", "model", "fallback", "error",
                                          "reserved_usd", "est", "started_utc", "finished_utc", "events_n")
                       if k in st}
        out["turn"].setdefault("state", "queued")
        if stop.exists() and out["turn"]["state"] not in FINAL:
            out["turn"]["cancelling"] = True
        evs = []
        if epath.exists():
            key = (key_loader or self._key)()
            evs = Events(key).read(epath)
        pg, nxt = page(evs, body.get("offset") or 0)
        out["events"], out["next"] = pg, nxt
        return out

    def cancel(self, body):
        tid = body.get("turn_id")
        if not isinstance(tid, str) or not TURN_ID.fullmatch(tid):
            raise LinkError("bad_request")
        tpath, spath, _e, stop = self._paths(tid)
        if not tpath.exists():
            raise LinkError("not_found")
        st = read_json(spath, {}) or {}
        if st.get("state") in FINAL:
            return {"turn": tid, "state": st["state"]}
        fd = os.open(str(stop), os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        os.close(fd)
        # Not picked up yet: it never runs and nothing is spent. Made only if the runner hasn't made its first state:
        # whichever is first wins, and a runner that has started sees the stop file before its first call.
        if write_json_new(spath, {"state": "cancelled", "phase": "queued", "stop": "cancelled",
                                  "usage": {"usd": 0.0}, "finished_utc": utc()}):
            return {"turn": tid, "state": "cancelled"}
        st = read_json(spath, {}) or {}
        if st.get("state") in FINAL:
            return {"turn": tid, "state": st["state"]}
        return {"turn": tid, "state": st.get("state", "queued"), "cancelling": True}

    def _key(self):
        from .store import load_or_make_key
        return load_or_make_key(store_key_file(self.cfg))

    def purge(self, now=None):
        """Clear a finished or abandoned turn's files after keep_hours (the conversation itself stays, sealed)."""
        now = time.time() if now is None else now
        keep = float(self.s["keep_hours"]) * 3600
        gone = 0
        for p in jobs_dir(self.cfg).iterdir():
            if p.name == "runner.beat":
                continue
            try:
                if now - p.stat().st_mtime > keep:
                    p.unlink()
                    gone += 1
            except OSError:
                pass
        return gone


def health(cfg):
    """What the website's relay may learn: whether the runner is up. Nothing of the store's."""
    s = settings(cfg)
    if str(s.get("mode")).lower() != "on":
        return {"runner": "off"}
    return {"runner": "up" if Link(cfg).runner_up() else "down"}


def status(cfg):
    """For `python -m zorderz_hub assistant status`: the mode, the runner, the month and the turns in flight."""
    s = settings(cfg)
    if str(s.get("mode")).lower() != "on":
        return {"mode": "off"}
    link = Link(cfg)
    return {"mode": "on", "site": s.get("site") or "", "runner": "up" if link.runner_up() else "down",
            "money": link.money(), "live_turns": link._live_jobs()}
