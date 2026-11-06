"""Being a good guest on public servers.

The hub reads four public sources: NOAA NCEI (weather), the Census Bureau (ACS, Gazetteer), the state fire
agency and the listing body (ArcGIS Online) and the region of Example City's GIS server. None publishes a hard
limit for this kind of use. What is published, and what this module does about it:

  * Esri: identical repeated queries can trip ArcGIS Online's rate limit (it answers 503, often inside a 200
    reply as {"error": {"code": 503}}); read-only queries should say cacheHint=true.     -> retry_json, cache hint
  * THREDDS servers (NOAA's weather runs on one) are known to block an address for >300 requests in 5 minutes
    (NCI Australia's, 2023).                                                          -> about one request a second
  * Every well-run server: 429 / 503 with Retry-After means "come back later".         -> wait as told, or stop

So, for every request the hub makes:
  1. One at a time per server, at least `pause` seconds apart (the callers use 1 s), across every thread.
  2. A reply that says "busy" (408, 425, 429, 500, 502, 503, 504, a timeout or a dropped connection) is tried
     again after Retry-After if the server gave one, else after 15 s, 60 s, 3 min (with jitter), at most three
     times; the gap to that server is doubled for the rest of the run. A server asking for more than 10 minutes
     is not waited on: the run stops, keeps what is on file, and tries the next night.
  3. Slow replies (over 5 s and over three times the usual) widen the gap too; it narrows again as replies
     come back quickly.
  4. Errors that won't get better by asking again (404, a bad query) are not retried.

And for every download run:
  5. Background runs start only in the off-peak hours (22:00-06:00 here by default: the region, Census and NOAA
     are US servers, busiest in US working hours). A run started by hand on the hub computer goes at once.
  6. One download at a time on this computer: the weather, ZIP facts and safety-site never overlap, whether the hub or
     the command line started them (a lock file beside the store).
  7. The User-Agent names the hub and its version, and a contact if an admin sets one ("downloads": {"contact":
     ...}); nothing personal is added by default.
"""
import contextlib
import datetime as dt
import email.utils
import http.client as _httpclient   # not `http`, which the function below rebinds
import logging
import os
import random
import re
import shutil
import ssl
import subprocess
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

_log = logging.getLogger("zorderz_hub.polite")

TRANSIENT = frozenset({408, 425, 429, 500, 502, 503, 504})
ARCGIS_BUSY = frozenset({429, 502, 503, 504})    # an {"error": {"code": …}} inside a 200 reply
BACKOFF = (15.0, 60.0, 180.0)            # seconds before the 1st, 2nd and 3rd retry (no Retry-After)
RETRIES = len(BACKOFF)
MAX_WAIT = 600.0                         # a server asking for longer than this: stop, try the next night
MAX_MULT = 8.0                           # the gap to a struggling server grows to at most 8x
SLOW_S = 5.0                             # a reply this slow, and 3x the usual, means the server is struggling
OFF_PEAK = "22:00-06:00"
_CURL_TRANSIENT = {6: "DNS", 7: "refused", 28: "timeout", 35: "TLS", 52: "empty reply", 55: "send", 56: "reset"}


class Trouble(Exception):
    """One request that didn't work. `transient`: asking again later may work."""

    def __init__(self, message, status=None, retry_after=None, kind="http"):
        super().__init__(message)
        self.message, self.status, self.retry_after, self.kind = message, status, retry_after, kind

    @property
    def transient(self):
        if self.kind in ("timeout", "network"):
            return True
        return self.kind == "http" and self.status in TRANSIENT


# ---------------------------------------------------------------- per server, for the whole hub
class _Host:
    def __init__(self):
        self.lock = threading.RLock()
        self.last = 0.0                  # when the last request ended (monotonic)
        self.mult = 1.0
        self.usual = None                # a running median-ish of reply times
        self.not_before = 0.0
        self.retries = 0
        self.slowdowns = 0


_HOSTS = {}
_HOSTS_LOCK = threading.Lock()
SLEEP = time.sleep                       # tests replace these two
CLOCK = time.monotonic


def host_of(url):
    return (urllib.parse.urlsplit(url).hostname or "").casefold()


def _host(name):
    with _HOSTS_LOCK:
        return _HOSTS.setdefault(name, _Host())


def reset():
    """Forget every server's state (tests)."""
    with _HOSTS_LOCK:
        _HOSTS.clear()


def backed_off(url):
    """True while a server has asked us to stay away (callers that loop over many requests stop early)."""
    return _host(host_of(url)).not_before - CLOCK() > MAX_WAIT


def state(url):
    """{gap multiplier, retries, slowdowns} for a server (status lines, tests)."""
    h = _host(host_of(url))
    return {"mult": round(h.mult, 2), "retries": h.retries, "slowdowns": h.slowdowns}


def request(url, attempt, pause=1.0, retries=RETRIES, say=None):
    """attempt() once per try, politely. attempt raises Trouble for a failed request; anything else it raises
    passes straight through (and is not retried)."""
    h = _host(host_of(url))
    tries = 0
    with h.lock:                                          # one request at a time to each server
        while True:
            if h.not_before - CLOCK() > MAX_WAIT:         # a server that said "come back later" isn't
                raise Trouble(f"{host_of(url)} asked us to come back later; nothing more is asked of it until "
                              "the next run", kind="busy")   # asked again a second later by the next caller
            wait = max(h.not_before - CLOCK(), h.last + pause * h.mult - CLOCK(), 0.0)
            if wait > 0:
                SLEEP(wait)
            t0 = CLOCK()
            try:
                out = attempt()
            except Trouble as e:
                h.last = CLOCK()
                if e.transient and e.retry_after is not None and e.retry_after > MAX_WAIT:
                    h.not_before = CLOCK() + e.retry_after
                    raise Trouble(f"{e.message}; the server asked us to wait {int(e.retry_after // 60)} minutes, "
                                  "so this run stops and tries again later", e.status, e.retry_after, "busy") from None
                if not e.transient:
                    raise
                if tries >= retries:                      # still busy after three waits: leave it alone a while
                    h.not_before = CLOCK() + MAX_WAIT + 1
                    h.mult = min(MAX_MULT, h.mult * 2)
                    raise
                delay = e.retry_after if e.retry_after is not None else BACKOFF[min(tries, len(BACKOFF) - 1)] * \
                    random.uniform(0.8, 1.2)
                tries += 1
                h.retries += 1
                h.mult = min(MAX_MULT, h.mult * 2)
                h.not_before = CLOCK() + delay
                _log.info("%s: %s; waiting %.0f s (try %d of %d)", host_of(url), e.message, delay, tries, retries)
                if say:
                    say(f"{host_of(url)} is busy ({e.message}); waiting {delay:.0f} s before asking again")
                continue
            except BaseException:
                h.last = CLOCK()                          # any failure still counts toward the gap
                raise
            took = CLOCK() - t0
            h.last = CLOCK()
            if h.usual is not None and took > SLOW_S and took > 3 * h.usual:
                h.mult = min(MAX_MULT, h.mult * 1.5)
                h.slowdowns += 1
            elif h.mult > 1.0:
                h.mult = max(1.0, h.mult * 0.9)
            h.usual = took if h.usual is None else 0.8 * h.usual + 0.2 * min(took, 3 * h.usual + 1)
            return out


# ---------------------------------------------------------------- one HTTP request, Python or the hub computer's curl
def retry_after(value, now=None):
    """Seconds from a Retry-After header (seconds or an HTTP date), or None."""
    if value is None:
        return None
    v = str(value).strip()
    if v.isdigit():
        return float(v)
    try:
        when = email.utils.parsedate_to_datetime(v)
    except (TypeError, ValueError):
        return None
    if when is None:
        return None
    now = now or dt.datetime.now(dt.timezone.utc)
    if when.tzinfo is None:
        when = when.replace(tzinfo=dt.timezone.utc)
    return max(0.0, (when - now).total_seconds())


def http(url, ua, timeout, limit, rng=None, data=None, content_type=None, curl=False):
    """(status, headers {lower name: value}, body). Raises Trouble for anything but a 2xx reply."""
    headers = {"User-Agent": str(ua)}
    if getattr(ua, "sender", None):
        headers["From"] = ua.sender                        # the contact, where servers expect it
    if rng:
        headers["Range"] = f"bytes={rng[0]}-{rng[1]}"
    if content_type:
        headers["Content-Type"] = content_type
    if curl:
        return _curl(url, headers, timeout, limit, data)
    req = urllib.request.Request(url, data=data, headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            body = r.read(limit + 1)
            hdrs = {k.lower(): v for k, v in r.headers.items()}
            want = hdrs.get("content-length")
            # a reply cut off part-way is a network trouble (asked again), not a broken answer
            if want and want.isdigit() and len(body) < min(int(want), limit + 1) and "content-encoding" not in hdrs:
                raise Trouble(f"{host_of(url) or 'the server'} cut its reply short ({len(body)} of {want} bytes)",
                              kind="network")
            return getattr(r, "status", 200), hdrs, body
    except urllib.error.HTTPError as e:
        raise Trouble(f"{host_of(url) or 'the server'} answered HTTP {e.code}", e.code,
                      retry_after(e.headers.get("Retry-After") if e.headers else None)) from None
    except urllib.error.URLError as e:
        reason = e.reason
        if isinstance(reason, ssl.SSLCertVerificationError) or "CERTIFICATE_VERIFY_FAILED" in str(reason):
            raise Trouble("certificate check failed", kind="cert") from None
        kind = "timeout" if isinstance(reason, TimeoutError) or "timed out" in str(reason) else "network"
        raise Trouble(f"{host_of(url) or 'the server'} unreachable ({type(reason).__name__})", kind=kind) from None
    except (TimeoutError, ConnectionError, OSError, _httpclient.HTTPException) as e:
        kind = "timeout" if isinstance(e, TimeoutError) else "network"
        raise Trouble(f"{host_of(url) or 'the server'} unreachable ({type(e).__name__})", kind=kind) from None


def _curl(url, headers, timeout, limit, data):
    exe = shutil.which("curl") or "/usr/bin/curl"
    cmd = [exe, "-sS", "-g", "-i", "--max-time", str(int(timeout)), "--max-filesize", str(limit + 65536)]
    for k, v in headers.items():
        cmd += ["-A", v] if k == "User-Agent" else ["-H", f"{k}: {v}"]
    if data is not None:
        cmd += ["--data-binary", "@-"]
    try:
        p = subprocess.run(cmd + [url], input=data, capture_output=True, timeout=timeout + 15)
    except subprocess.TimeoutExpired:
        raise Trouble(f"{host_of(url)} unreachable (curl timed out)", kind="timeout") from None
    except OSError as e:
        raise Trouble(f"curl failed ({type(e).__name__})", kind="other") from None
    if p.returncode != 0:
        why = _CURL_TRANSIENT.get(p.returncode)
        if p.returncode == 60:
            raise Trouble("certificate check failed", kind="cert")
        raise Trouble(f"curl failed (exit {p.returncode})", kind=("timeout" if p.returncode == 28 else "network")
                      if why else "other")
    out, status, hdrs = p.stdout, None, {}
    while out.startswith(b"HTTP/"):                       # header blocks (a proxy or a 100 may add one)
        cut = out.find(b"\r\n\r\n")
        if cut < 0:
            break
        head = out[:cut].decode("latin-1", "replace")
        out = out[cut + 4:]
        m = re.match(r"HTTP/\S+\s+(\d+)", head)
        status = int(m.group(1)) if m else status
        hdrs = {}
        for line in head.split("\r\n")[1:]:
            if ":" in line:
                k, v = line.split(":", 1)
                hdrs[k.strip().lower()] = v.strip()
    if status is not None and status >= 400:
        raise Trouble(f"{host_of(url)} answered HTTP {status}", status, retry_after(hdrs.get("retry-after")))
    return status or 200, hdrs, out


def arcgis_error(out, url):
    """ArcGIS answers most errors with HTTP 200 and {"error": {"code": ..., "message": ...}}. A busy code is
    returned as Trouble (so it is retried); None when the reply is not an error."""
    if not isinstance(out, dict) or not out.get("error"):
        return None
    err = out["error"]
    code = err.get("code") if isinstance(err, dict) else None
    msg = str(err.get("message") if isinstance(err, dict) else err)[:80]
    try:
        code = int(code)
    except (TypeError, ValueError):
        code = None
    # Inside a reply, 500 is how the region's server says "bad query", so only these mean "busy" here.
    return Trouble(f"{host_of(url)} said: {msg}", code, None, "http" if code in ARCGIS_BUSY else "other")


# ---------------------------------------------------------------- the run: when, and one at a time
def settings(cfg):
    d = dict((cfg or {}).get("downloads") or {})
    return {"off_peak": _window(d.get("off_peak", OFF_PEAK)), "contact": str(d.get("contact") or "").strip()[:120]}


def _window(text):
    """((h, m), (h, m)), or None for "any" (no rule). Anything unreadable means the default (review: "10pm-6am"
    used to mean no rule at all)."""
    if str(text or "").strip().lower() in ("any", "off", "none"):
        return None
    m = re.fullmatch(r"\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*", str(text or ""))
    ok = bool(m) and all(0 <= int(x) < lim for x, lim in zip(m.groups(), (24, 60, 24, 60)))
    if not ok:
        if str(text or "").strip() and str(text) != OFF_PEAK:
            _log.warning("downloads.off_peak %r isn't HH:MM-HH:MM; using %s", text, OFF_PEAK)
        return _window(OFF_PEAK) if str(text) != OFF_PEAK else ((22, 0), (6, 0))
    a, b, c, d = (int(x) for x in m.groups())
    return (a, b), (c, d)


def when_text(cfg):
    """How a "not downloaded yet" note says when the hub downloads: from the business's own setting."""
    w = settings(cfg)["off_peak"]
    if w is None:
        return "in the background"
    (a, b), (c, d) = w
    return f"between {a:02d}:{b:02d} and {c:02d}:{d:02d}"


class Agent(str):
    """The User-Agent text, carrying the business's contact separately (`.sender`), which is sent as the
    standard From: header.

    the region of Example City's firewall holds, without answering, any request whose User-Agent has an e-mail
    address or a web address in it ("contact: office@example.com", "office@example.com" and
    "+https://example.com" all timed out; the same request without them answered in 0.4 s, and with the address
    in From: in 0.2 s). So the address never goes in the User-Agent."""
    sender = None


def user_agent(base, cfg):
    ua = Agent(base)
    contact = settings(cfg)["contact"]
    ua.sender = contact if contact and re.fullmatch(r"[^\s@<>,;]+@[^\s@<>,;]+\.[A-Za-z]{2,}", contact) else None
    return ua


def in_off_peak(now, window):
    if window is None:
        return True
    (a, b), (c, d) = window
    m, start, end = now.hour * 60 + now.minute, a * 60 + b, c * 60 + d
    return start <= m < end if start < end else (m >= start or m < end)


def minutes_left(now, window):
    """Minutes until the window ends (now inside it)."""
    _, (c, d) = window
    end = now.replace(hour=c, minute=d, second=0, microsecond=0)
    if end <= now:
        end += dt.timedelta(days=1)
    return (end - now).total_seconds() / 60


def seconds_to_off_peak(now, window, offset_min=0, need_min=0):
    """0 inside the window with at least need_min minutes left; else seconds to its next start plus offset_min
    (so the three downloads don't all start at the same minute)."""
    if window is None or (in_off_peak(now, window) and minutes_left(now, window) >= need_min):
        return 0.0
    (a, b), _ = window
    start = now.replace(hour=a, minute=b, second=0, microsecond=0)
    if start <= now:
        start += dt.timedelta(days=1)
    return (start - now).total_seconds() + offset_min * 60


def run_deadline(cfg, now, hours, background):
    """time.monotonic() deadline for a run: `hours` from now, and in the background no later than the end of the
    off-peak window it started in."""
    secs = hours * 3600
    window = settings(cfg)["off_peak"]
    if background and window is not None and in_off_peak(now, window):
        secs = min(secs, minutes_left(now, window) * 60)
    return time.monotonic() + secs


def unexpected(e, what=""):
    """How a download's unforeseen error is reported: its kind and the hub file and line it came from, and
    logged in full."""
    where = ""
    try:
        import traceback
        frames = [f for f in traceback.extract_tb(e.__traceback__) if "zorderz_hub" in f.filename.replace("\\", "/")]
        if frames:
            where = f" at {os.path.basename(frames[-1].filename)}:{frames[-1].lineno}"
    except Exception:                                          # noqa: BLE001 — a message, never a second error
        pass
    _log.warning("%s: unexpected %s%s", what or "download", type(e).__name__, where, exc_info=e)
    return f"unexpected error ({type(e).__name__}{where})"


NEED_MIN = 45            # a background run doesn't start with less of the window left than this


def wait_off_peak(cfg, stop, clock, offset_min=0):
    """Background runs: sleep until the off-peak hours (with NEED_MIN minutes of them left). Re-checked at least
    hourly, so a computer that slept, or a clock change, doesn't start a run at the wrong time. False when
    the hub is stopping."""
    window = settings(cfg)["off_peak"]
    while True:
        now = clock()
        wait = seconds_to_off_peak(now, window, offset_min, NEED_MIN)
        if wait <= 0:
            return True
        target = now + dt.timedelta(seconds=wait)
        while True:                                        # by the wall clock, at least hourly
            left = (target - clock()).total_seconds()
            if left <= 0:
                break
            if stop.wait(min(left, 3600)):
                return False


_SLOT = threading.Lock()


@contextlib.contextmanager
def slot(cfg, name, say=None):
    """One download at a time on this computer (the hub's threads and the command line): waits for the other."""
    folder = Path((cfg or {}).get("store") or ".").parent
    folder.mkdir(parents=True, exist_ok=True)
    path = folder / "downloads.lock"
    if not _SLOT.acquire(blocking=False):
        if say:
            say("Waiting for another download in the hub to finish …")
        _SLOT.acquire()
    fd = None
    try:
        fd = os.open(path, os.O_RDWR | os.O_CREAT, 0o600)
        try:
            import fcntl
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except OSError:
                try:
                    holder = os.pread(fd, 80, 0).decode("utf-8", "replace").strip() or "another download"
                except OSError:
                    holder = "another download"
                if say:
                    say(f"Waiting for {holder} to finish …")
                fcntl.flock(fd, fcntl.LOCK_EX)
        except ImportError:                                # not macOS or Linux: the thread lock only
            pass
        try:
            os.ftruncate(fd, 0)
            os.pwrite(fd, f"the {name} download (process {os.getpid()})".encode(), 0)
        except OSError:
            pass
        yield
    finally:
        if fd is not None:
            os.close(fd)                                   # closing releases the lock
        _SLOT.release()

