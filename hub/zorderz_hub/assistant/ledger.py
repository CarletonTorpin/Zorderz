"""The engine's usage ledger: one line per turn, numbers and short code words only.

It never holds a question, an answer, a file name, a person or a conversation id. Every string value is a short
lower-case code word; anything else is written as "other".

HOLDS, so two turns at once can't both spend what is left of the month. Before its first call a turn books the most it
may cost in a holds file beside the ledger (<ledger>-holds.json, 0600), under an exclusive file lock, so every process
that runs turns sees the others' holds. Every month check counts live holds as spent. When the turn's row is written
its hold is released; a hold whose process died expires on its own (its turn's time limit plus a grace). A turn still
at work renews its hold before each call and each tool (touch), and its row names its hold (row_for_hold), so a
recovery never charges a turn twice.
"""
import fcntl
import json
import os
import re
import secrets
import threading
import time
from contextlib import contextmanager
from pathlib import Path

from .link import SITE_CALLER

CODE = re.compile(r"[a-z0-9_.:-]{1,32}")             # used with fullmatch: no trailing newline slips through
CALLERS = {SITE_CALLER}                               # who started the turn: a kind, never a person
ERRORS = {"overloaded", "rate_limited", "auth", "wrong_workspace", "bad_request", "server", "network", "other",
          "guard", "refused"}
FIELDS = ("op", "ok", "model", "stop", "fallback", "calls", "tool_calls", "compacted",
          "in_tokens", "out_tokens", "cache_read_tokens", "cache_write_tokens", "searches", "usd_milli", "turn_ms",
          "caller", "error", "hold")

HOLD_GRACE_S = 120               # a hold outlives its turn's time limit by this much, then stops counting
HOLD_MAX_S = 3600                # and never counts longer than an hour


def model_code(m):
    """A model id as a code word (lower-case letters, digits, . _ : -; at most 32 characters)."""
    c = re.sub(r"[^a-z0-9_.:-]+", "-", str(m or "").strip().lower()).strip("-")[:32]
    return c or "other"


class Ledger:
    def __init__(self, path, holds_path=None):
        self.path = Path(path)
        self.path.parent.mkdir(parents=True, exist_ok=True)
        self.lock = threading.Lock()
        self.holds_path = Path(holds_path) if holds_path else self.path.with_name(self.path.stem + "-holds.json")

    @staticmethod
    def row(**f):
        out = {"ts_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())}
        for k in FIELDS:
            if k not in f or f[k] is None:
                continue
            v = f[k]
            if k == "model":
                v = model_code(v)
            elif k == "caller" and v not in CALLERS:
                v = "other"
            elif k == "error" and v not in ERRORS:
                v = "other"
            if isinstance(v, bool) or isinstance(v, (int, float)):
                out[k] = v
            elif isinstance(v, str) and CODE.fullmatch(v):
                out[k] = v
            else:
                out[k] = "other"            # never a free-text value
        return out

    def write(self, **f):
        line = json.dumps(self.row(**f), separators=(",", ":"))
        with self.lock:
            new = not self.path.exists()
            with open(self.path, "a", encoding="utf-8") as fh:
                fh.write(line + "\n")
            if new:
                os.chmod(self.path, 0o600)
        return line

    def month_usd(self, month=None, exclude_caller=None):
        """Spend in a UTC month. exclude_caller: leave one caller's rows out (the website's own turns, which the
        website counts itself)."""
        month = month or time.strftime("%Y-%m", time.gmtime())
        total = 0
        if self.path.exists():
            for line in self.path.read_text(errors="replace").splitlines():
                try:
                    r = json.loads(line)
                    if str(r.get("ts_utc") or "").startswith(month) and \
                            not (exclude_caller and r.get("caller") == exclude_caller):
                        total += int(float(r.get("usd_milli") or 0))
                except (ValueError, TypeError, AttributeError):
                    continue                            # a torn or odd line never stops a turn
        return total / 1000.0

    # ------------------------------------------------------------------------------------------- holds
    @contextmanager
    def _holds_locked(self):
        """The holds file, read and written under one exclusive lock (flock on a lock file beside it)."""
        lock_path = str(self.holds_path) + ".lock"
        fd = os.open(lock_path, os.O_RDWR | os.O_CREAT, 0o600)
        try:
            fcntl.flock(fd, fcntl.LOCK_EX)
            try:
                with open(self.holds_path, encoding="utf-8") as fh:
                    data = json.load(fh)
                holds = [h for h in data.get("holds", []) if isinstance(h, dict)]
            except (OSError, ValueError, AttributeError):
                holds = []                              # no file, or a damaged one: no holds (a crash can't lock money)
            box = {"holds": holds, "dirty": False}
            yield box
            if box["dirty"]:
                tmp = self.holds_path.with_name(self.holds_path.name + ".%d.tmp" % os.getpid())
                fd2 = os.open(str(tmp), os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
                with os.fdopen(fd2, "w", encoding="utf-8") as fh:
                    json.dump({"holds": box["holds"]}, fh, separators=(",", ":"))
                os.replace(tmp, self.holds_path)
        finally:
            fcntl.flock(fd, fcntl.LOCK_UN)
            os.close(fd)

    @staticmethod
    def _live(holds, now):
        return [h for h in holds if float(h.get("expires") or 0) > now]

    def holds_usd(self, exclude_caller=None, now=None):
        """Live holds, in USD (exclude_caller: leave one caller's out)."""
        now = time.time() if now is None else now
        with self._holds_locked() as box:
            live = self._live(box["holds"], now)
        return sum(int(h.get("usd_milli") or 0) for h in live
                   if not (exclude_caller and h.get("caller") == exclude_caller)) / 1000.0

    def reserve(self, want_usd, cap_usd, life_s, caller=SITE_CALLER, now=None):
        """Book the most a turn may cost before its first call, atomically against every other process's holds.
        cap_usd None: the engine has no monthly line of its own, so only want_usd bounds it. -> (hold id or "", USD
        granted): never more than want_usd rounded up to a thousandth, never past cap_usd for the month (rows + live
        holds). Granted 0 when nothing is left."""
        now = time.time() if now is None else now
        want = int(-(-max(0.0, float(want_usd)) * 1000 // 1))    # rounded up to a thousandth, as the website's holds
        caller = caller if caller in CALLERS else "other"
        with self._holds_locked() as box:
            live = self._live(box["holds"], now)
            if len(live) != len(box["holds"]):
                box["holds"], box["dirty"] = live, True
            grant = want
            if cap_usd is not None:
                spent = int(round(self.month_usd() * 1000)) + sum(int(h.get("usd_milli") or 0) for h in live)
                grant = min(want, int(float(cap_usd) * 1000) - spent)
            if grant <= 0:
                return "", 0.0
            hid = secrets.token_hex(6)
            life = max(30.0, min(float(HOLD_MAX_S), float(life_s) + HOLD_GRACE_S))
            box["holds"].append({"id": hid, "usd_milli": grant, "expires": now + life, "caller": caller})
            box["dirty"] = True
        return hid, grant / 1000.0

    def touch(self, hold_id, life_s, now=None):
        """A turn still at work: its hold keeps counting for life_s more (plus the grace). False if it is gone."""
        if not hold_id:
            return False
        now = time.time() if now is None else now
        with self._holds_locked() as box:
            for h in box["holds"]:
                if h.get("id") == hold_id:
                    life = max(30.0, min(float(HOLD_MAX_S), float(life_s) + HOLD_GRACE_S))
                    h["expires"] = max(float(h.get("expires") or 0), now + life)
                    box["dirty"] = True
                    return True
        return False

    def row_for_hold(self, hold_id):
        """The row a turn wrote for its hold, or None (read from the end: it is recent)."""
        if not hold_id or not self.path.exists():
            return None
        for line in reversed(self.path.read_text(errors="replace").splitlines()):
            try:
                r = json.loads(line)
            except ValueError:
                continue
            if isinstance(r, dict) and r.get("hold") == hold_id:
                return r
        return None

    def release(self, hold_id):
        """The turn's row is written: its hold stops counting."""
        if not hold_id:
            return False
        with self._holds_locked() as box:
            kept = [h for h in box["holds"] if h.get("id") != hold_id]
            if len(kept) != len(box["holds"]):
                box["holds"], box["dirty"] = kept, True
                return True
        return False
