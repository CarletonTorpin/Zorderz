"""The hub checks itself.

Three minutes after the hub starts (so right after every install) and every night at 02:17, the hub:

  1. asks itself a few questions about finished periods, through the same path a person's question takes, and
     compares each answer with the one it accepted earlier (a baseline kept beside the logs). An answer about a
     finished period should not move: if one does, something in reading or counting changed;
  2. compares every year that had ended when golden_numbers.json was written with the store (the accepted
     numbers, written by the first `selfcheck --accept`);
  3. checks the website's invoice feed and the data are recent (weekends and holidays don't count), and that the
     model is loaded.

The result is a short list of codes (never figures, never names) in logs/selfcheck.json. /health carries it, and
`attention`, one plain sentence for the Charts page and the website's alert email, when something needs a person.

a baseline is only recorded from a clean run (rules answered, rows present,
past years match, nothing failed) and `selfcheck --accept` re-records it after an intended change; years are
compared only up to the year the golden file was written, so New Year doesn't alarm; days are counted up to
yesterday and US holidays are skipped; the cheap checks (model, data) are re-done when /health asks, so a
2 a.m. result isn't shown all day; each check runs on its own, and a failure is reported, not hidden.
"""
import datetime as dt
import hashlib
import json
import logging
import os
import re
import threading
import time
from pathlib import Path

from . import compute
from . import feed as feed_mod
from . import names

_log = logging.getLogger("zorderz_hub.selfcheck")
VERSION = 1
AT = (2, 17)                      # 02:17 local, every night
FIRST_DELAY = 180                 # seconds after the hub starts
WEEKLY_DAY = 0                    # Monday: the weekly review is written after that night's check
REFRESH_SECONDS = 300             # /health re-does the cheap checks at most this often
# Questions about finished periods that the rules read alone and that skip the second look (one group, or one
# period with 10 groups or fewer), so the check is quick, needs no model and gives the same answer every night.
QUESTIONS = (
    "Revenue in Q3 2025 vs Q3 2024",
    "How much did we bill in summer 2025 (June through August)?",
    "How many jobs did we do in 2025?",
    "Units by month in 2025",
    "Revenue by product in 2025",
)
ACCESS = {"caller": "ui", "ops": ["health", "ask"], "scope": None, "money": True, "by_person": True,
          "line_items": "all", "kiosk": False}
FEED_BUSINESS_DAYS = 2            # full business days with no invoice batch from the website (live mode only)
DATA_BUSINESS_DAYS = 5            # full business days since the newest invoice's date (live mode only)
# the sentences hold only fixed limits, never a count that grows by the hour. The website's alert email
# sends each distinct sentence once a day, so "for 4 hours" then "for 5 hours" would have mailed every hour.
# The live counts stay in facts (logs/selfcheck.json) for whoever looks.
MESSAGES = {
    "no_data": "The hub has no data loaded.",
    "past_years_changed": "A past year's totals no longer match the accepted numbers.",
    "answers_changed": "An answer about a finished period changed since it was accepted. If that was intended "
                       "(a product renamed, an old invoice corrected), run: zorderz_hub selfcheck --accept",
    "feed_stale": "The website's invoice feed hasn't arrived for {feed} business days.",
    "feed_job_stale": "The website's hourly invoice job hasn't checked in for over {feed_job_limit} hours.",
    "ledger_stale": "The website's billing reader (the sales analytics ledger) hasn't succeeded for over "
                    "{ledger_limit} hours.",
    "website_silent": "The website's scheduled jobs haven't reached the hub for over {silent_limit} hours.",
    "data_stale": "The newest invoice is from {as_of}.",
    "model_missing": "The question model isn't loaded in LM Studio; Charts answers with its rules only.",
    "golden_missing": "The accepted numbers file (golden_numbers.json) wasn't found, so past years weren't checked.",
    "error": "Part of the self-check couldn't run ({what}).",
    # NOAA's newest day is normally 3-5 days old
    "weather_stale": "The weather (NOAA) hasn't updated for over 10 days. On the hub computer: zorderz_hub weather update",
    # the hub computer's keys. Never made by the hub; restore the saved copy.
    "analytics_key_missing": "The analytics key is missing on the hub computer ({key_near}). Imports stop until it is "
                             "back; the hub never makes a new one. Restore it from the saved copy.",
    "analytics_key_changed": "The analytics key on the hub computer isn't the one the store was built with. Imports "
                             "stop until the original is back in ~/.config/zorderz-hub/analytics_key.",
    "hub_secret_missing": "The hub's secret key on the hub computer is missing ({secret_near}). Charts stops at the "
                          "hub's next restart: restore it from the saved copy, never make a new one.",
    "tunnel_token_missing": "The tunnel's token file on the hub computer is missing ({token_near}). The "
                            "tunnel stops at its next restart: restore it from the saved copy.",
    "key_file_open": "A key file on the hub computer can be read by other users ({open_files}): chmod 600 it.",
    "keys_unchecked": "The Charts Mac's keys couldn't be checked ({keys_error}). On the hub computer: zorderz_hub keys status",
}
# what the page and the alert email show, most important first
ATTENTION = ("no_data", "analytics_key_changed", "analytics_key_missing", "hub_secret_missing",
             "tunnel_token_missing", "key_file_open", "keys_unchecked", "past_years_changed", "answers_changed", "website_silent", "ledger_stale", "feed_job_stale",
             "feed_stale", "data_stale", "model_missing", "error", "golden_missing", "weather_stale")
# re-checked whenever /health asks (at most every 5 minutes): cheap, and they change during the day
LIVE = ("no_data", "analytics_key_changed", "analytics_key_missing", "hub_secret_missing", "tunnel_token_missing",
        "key_file_open", "keys_unchecked", "model_missing", "website_silent", "ledger_stale", "feed_job_stale", "feed_stale", "data_stale",
        "weather_stale")
CHEAP = LIVE
# with the website's hourly heartbeat freshness is measured in hours, not business days
SILENT_HOURS = 2              # no scheduled call from the website at all
FEED_JOB_HOURS = 3            # the website calls, but its hourly feed job doesn't
LEDGER_HOURS = 6
SETTLE_SECONDS = 3 * 3600     # after the hub starts, wait this long before judging hours
WAKE_SECONDS = 30 * 60        # a sleep (or a break in the website's calls) this long starts the wait again


def _near(found, name):
    """"found analytics_key.txt; the hub reads analytics_key", or where the hub reads it."""
    found = [str(n) for n in (found or [])][:3]
    if found:
        return f"found {', '.join(found)}; the hub reads {name}"
    return f"the hub reads ~/.config/zorderz-hub/{name}"


# ---------------------------------------------------------------- business days
def _nth_weekday(year, month, weekday, n):
    d = dt.date(year, month, 1)
    d += dt.timedelta(days=(weekday - d.weekday()) % 7)
    return d + dt.timedelta(weeks=n - 1)


def _last_weekday(year, month, weekday):
    d = dt.date(year, month + 1, 1) - dt.timedelta(days=1) if month < 12 else dt.date(year, 12, 31)
    return d - dt.timedelta(days=(d.weekday() - weekday) % 7)


def holidays(year):
    """Days the office is closed and nothing is invoiced (US: New Year, Memorial Day, the Fourth, Labor Day,
    Thanksgiving and the day after, Christmas Eve and Day). A wrong guess only delays an alert by a day."""
    days = {dt.date(year, 1, 1), dt.date(year, 7, 4), dt.date(year, 12, 24), dt.date(year, 12, 25),
            _last_weekday(year, 5, 0), _nth_weekday(year, 9, 0, 1)}
    thanks = _nth_weekday(year, 11, 3, 4)
    days |= {thanks, thanks + dt.timedelta(days=1)}
    return days


def business_days_between(start, end):
    """Business days after `start` up to and including `end` (dates): weekdays that aren't holidays."""
    if not start or not end or end <= start:
        return 0
    n, d = 0, start
    while d < end:
        d += dt.timedelta(days=1)
        if d.weekday() < 5 and d not in holidays(d.year):
            n += 1
    return n


def full_business_days_since(start, today):
    """Business days that have fully passed since `start`: today, still going, doesn't count."""
    return business_days_between(start, today - dt.timedelta(days=1)) if start and today else 0


def _date(text):
    try:
        return dt.date.fromisoformat(str(text)[:10])
    except (TypeError, ValueError):
        return None


def fingerprint(data):
    """What an answer says, without the words around it: status, the spec's dates and the rows' figures."""
    res = data.get("result") or {}
    spec = data.get("spec") or {}
    rows = []
    for r in res.get("rows") or []:
        v = r[2] if len(r) > 2 else None
        rows.append([str(r[0]), str(r[1]), round(float(v), 2) if isinstance(v, (int, float)) else str(v)])
    body = {"status": data.get("status"), "from": spec.get("date_from"), "to": spec.get("date_to"),
            "rows": sorted(rows)}
    return hashlib.sha256(json.dumps(body, sort_keys=True).encode()).hexdigest()[:12]


def _usable(data):
    """An answer good enough to accept as the baseline: the rules answered it, with figures."""
    meta = data.get("meta") or {}
    res = data.get("result") or {}
    return data.get("status") in ("chart", "number") and meta.get("model") == "reader" \
        and bool(res.get("rows")) and not res.get("empty")



def _compare(a, b, path="", out=None):
    out = [] if out is None else out
    if isinstance(a, dict) and isinstance(b, dict):
        for k in sorted(set(a) | set(b), key=str):
            if k not in a or k not in b:
                out.append(f"{path}/{k}: only in {'hub' if k in a else 'other'}")
            else:
                _compare(a[k], b[k], f"{path}/{k}", out)
    elif isinstance(a, float) or isinstance(b, float):
        if a is None or b is None or abs(float(a) - float(b)) > 0.005:
            out.append(f"{path}: {a} != {b}")
    elif a != b:
        out.append(f"{path}: {a} != {b}")
    return out


def _split_by_year(diffs, this_year=None):
    """Differences in a year before this one vs everything else (this year, totals)."""
    import datetime
    import re
    this_year = this_year or datetime.date.today().year
    history, current = [], []
    for d in diffs:
        m = re.search(r"by_year/(\d{4})\b", d)           # any per-year section of the golden file
        (history if m and int(m.group(1)) < this_year else current).append(d)
    return history, current

class SelfCheck:
    def __init__(self, hub, folder=None, golden=None, clock=None):
        self.hub = hub
        cfg = hub.cfg
        self.folder = Path(folder or Path(cfg.get("audit_log") or "logs/audit.jsonl").parent)
        # the accepted numbers, in the hub's home folder (written by `selfcheck --accept`)
        self.golden = Path(golden or cfg.get("golden_file") or (names.HUB_HOME / "golden_numbers.json"))
        self.clock = clock or dt.datetime.now
        self.lock = threading.Lock()
        self._thread = None
        self._stop = threading.Event()
        self._live = None                       # (when, codes, facts) from the last cheap re-check
        self.started = time.time()              # ~ when the hub started; hours are only judged after SETTLE_SECONDS
        self.weekly = None                      # the last weekly review's summary
        # the monotonic clock stops while the hub computer sleeps and the wall clock doesn't, so the
        # gap between them grows by the length of each sleep. A jump is a wake.
        self._drift = time.time() - time.monotonic()

    def resettle(self, now_ts=None, why="wake"):
        """Start the settle wait again: the hub computer woke, or the website's calls resumed after a break. The
        heartbeat it holds is as old as the sleep, and the next hourly run will refresh it."""
        self.started = now_ts or time.time()
        self._live = None
        _log.info("self-check: waiting %d hours before judging the website again (%s)", SETTLE_SECONDS // 3600, why)

    def _woke(self):
        drift = time.time() - time.monotonic()
        jump, self._drift = drift - self._drift, drift
        return jump > WAKE_SECONDS

    # ------------------------------------------------------------ files
    @property
    def latest_path(self):
        return self.folder / "selfcheck.json"

    @property
    def baseline_path(self):
        return self.folder / "selfcheck-baseline.json"

    def latest(self):
        try:
            return json.loads(self.latest_path.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            return None

    def _write(self, path, obj):
        self.folder.mkdir(parents=True, exist_ok=True)
        tmp = path.with_suffix(path.suffix + ".tmp")
        tmp.write_text(json.dumps(obj, sort_keys=True, indent=1), encoding="utf-8")
        tmp.replace(path)

    def _baseline(self):
        try:
            base = json.loads(self.baseline_path.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            base = {}
        if base.get("version") != VERSION or not isinstance(base.get("answers"), dict):
            base = {"version": VERSION, "answers": {}}
        return base

    # ------------------------------------------------------------ the checks
    def _answers(self, today):
        """{key: fingerprint} of the answers that can be compared; plus which failed or weren't the rules'."""
        got, failed, skipped = {}, [], []
        for q in QUESTIONS:
            out = self.hub.dispatch({"op": "ask", "question": q, "today": today.isoformat(), "user": "selfcheck",
                                     "access": ACCESS, "_selfcheck": True})
            key = hashlib.sha256(q.encode()).hexdigest()[:12]
            if not out.get("ok"):
                failed.append(key)
            elif not _usable(out["data"]):
                skipped.append(key)            # the model wrote it, or no figures: nothing stable to compare
            else:
                got[key] = fingerprint(out["data"])
        return got, failed, skipped

    def golden_year(self):
        """The year golden_numbers.json was written: its own note's first date, else the file's date."""
        try:
            gold = json.loads(self.golden.read_text(encoding="utf-8"))
            m = re.search(r"\b(20\d\d)-\d\d-\d\d\b", json.dumps(gold.get("_about", "")))
            if m:
                return int(m.group(1))
        except (OSError, ValueError, AttributeError):
            pass
        try:
            return dt.date.fromtimestamp(self.golden.stat().st_mtime).year
        except OSError:
            return None

    def _write_golden(self, data, today):
        """The accepted numbers (customers, jobs, revenue by product and year), written once by --accept."""
        gold = dict(compute.golden(data, self.hub.pack))
        gold["_about"] = f"accepted {today.isoformat()} by selfcheck --accept"
        self.golden.parent.mkdir(parents=True, exist_ok=True)
        tmp = self.golden.with_suffix(".tmp")
        tmp.write_text(json.dumps(gold, sort_keys=True, indent=1, default=str), encoding="utf-8")
        os.chmod(tmp, 0o600)
        tmp.replace(self.golden)

    def _past_years(self, data, today):
        gold = json.loads(self.golden.read_text(encoding="utf-8"))
        now = compute.golden(data, self.hub.pack)
        wanted = {k: gold[k] for k in now if k in gold}
        # only years that had ended when the file was written
        upto = min(today.year, self.golden_year() or today.year)
        history, _current = _split_by_year(_compare(now, wanted), upto)
        return len(history)

    def _model_ok(self):
        want = [m for m in [self.hub.lm.spec_model] if m]
        return not want or set(want) <= set(self.hub.lm.models())

    def key_codes(self, report=None):
        """(codes, facts) for the hub computer's keys: states, fingerprints' agreement and near-miss file names."""
        from . import keyfiles
        rep = report if report is not None else keyfiles.report(self.hub.cfg)
        codes, facts = [], {"keys": {label: r["state"] for label, r in rep.items()}}
        a = rep.get("analytics_key")
        if a is not None:
            facts["analytics_key"] = a["state"]
            if a["state"] == "missing":
                codes.append("analytics_key_missing")
            elif a["state"] == "changed":
                codes.append("analytics_key_changed")
            facts["analytics_key_near_misses"] = list(a.get("near_misses") or []) if a["state"] != "ok" else []
        for label, code, fact in (("hub secret", "hub_secret_missing", "hub_secret_near_misses"),
                                  ("tunnel token", "tunnel_token_missing", "tunnel_token_near_misses")):
            r = rep.get(label)
            if r is None:
                continue
            if r["state"] == "missing":
                codes.append(code)
                facts[fact] = list(r.get("near_misses") or [])
        open_files = [Path(r["source"]).name for r in rep.values() if r.get("state") == "readable_by_others"]
        if open_files:
            codes.append("key_file_open")
            facts["key_files_open"] = open_files
        return codes, facts

    def _live_codes(self, today=None, now_ts=None):
        """(codes, facts) for what changes during the day: data loaded, the model, the website's jobs, the feed."""
        codes, facts = [], {}
        clock = self.clock()
        now_ts = now_ts or clock.timestamp()
        today = today or clock.date()
        data = self.hub.data
        if data is None:
            codes.append("no_data")
        if self.hub.cfg.get("check_keys", True):  # the hub computer's keys (states and file names only)
            try:
                k_codes, k_facts = self.key_codes()
                codes += k_codes
                facts.update(k_facts)
            except Exception as e:                   # a check that can't run is a problem, not silence
                codes.append("keys_unchecked")
                facts["keys_error"] = type(e).__name__
        try:
            if not self._model_ok():
                codes.append("model_missing")
        except Exception:
            codes.append("model_missing")
        wstore = getattr(self.hub, "weather", None)
        if wstore is not None:                       # judged only once a download has run
            try:
                from . import weather as weather_mod
                ws = weather_mod.summary(wstore, today)
                facts["weather_last_day"] = ws.get("last_day")
                if ws.get("stale") and wstore.status().get("finished"):
                    codes.append("weather_stale")
            except Exception:
                pass
        fs = feed_mod.status(self.hub.cfg["store"])
        facts["feed_mode"] = fs.get("mode")
        got = _date(fs.get("received_at"))
        facts["feed_received"] = got.isoformat() if got else None
        if fs.get("mode") != "live":
            return codes, facts                     # exports by hand: nothing arrives on its own
        if self._woke():
            self.resettle(time.time(), "the hub computer slept")
        settled = now_ts - self.started >= SETTLE_SECONDS
        seen = getattr(self.hub, "last_cron_seen", None)
        if seen:
            facts["silent_hours"] = int((now_ts - seen) // 3600)
        silent = settled and (seen is None or now_ts - seen > SILENT_HOURS * 3600)
        if silent:
            facts.setdefault("silent_hours", int((now_ts - self.started) // 3600))
            codes.append("website_silent")
        hb = feed_mod.heartbeat(self.hub.cfg["store"])
        if hb:
            facts["feed_hours"] = int((now_ts - hb["received_ts"]) // 3600)
            if settled and not silent and now_ts - hb["received_ts"] > FEED_JOB_HOURS * 3600:
                codes.append("feed_job_stale")
            if hb.get("ledger_ok_utc"):
                try:
                    ok = dt.datetime.strptime(hb["ledger_ok_utc"], "%Y-%m-%d %H:%M:%S").replace(
                        tzinfo=dt.timezone.utc).timestamp()
                    facts["ledger_hours"] = int((now_ts - ok) // 3600)
                    if settled and now_ts - ok > LEDGER_HOURS * 3600:
                        codes.append("ledger_stale")
                except ValueError:
                    pass
            facts["ledger_failing"] = bool(hb.get("ledger_failing"))
        else:                                       # an older website: full business days
            days = full_business_days_since(got, today) if got else None
            facts["feed_business_days"] = days
            if days is None or days > FEED_BUSINESS_DAYS:
                codes.append("feed_stale")
        as_of = _date(data.meta.get("data_as_of")) if data is not None else None
        facts["data_as_of"] = as_of.isoformat() if as_of else None
        if as_of and full_business_days_since(as_of, today) > DATA_BUSINESS_DAYS:
            codes.append("data_stale")
        return codes, facts

    def run(self, accept=False):
        """One check. `accept` re-records the baseline from this run's answers (after an intended change).
        Returns the record written to logs/selfcheck.json."""
        with self.lock:
            started = time.time()
            now = self.clock()
            today = now.date()
            problems, facts, broke = [], {}, []

            def step(name, fn):
                try:
                    fn()
                except Exception as e:             # one broken check must not hide the others
                    broke.append(name)
                    facts[f"{name}_error"] = type(e).__name__

            data = self.hub.data
            if data is None:
                problems.append("no_data")
            past = {}

            def past_years():
                if accept and data is not None and not self.golden.exists():
                    self._write_golden(data, today)
                if not self.golden.exists():
                    problems.append("golden_missing")
                    return
                past["n"] = self._past_years(data, today)
                facts["past_year_differences"] = past["n"]
                facts["golden_year"] = self.golden_year()
                if past["n"]:
                    problems.append("past_years_changed")

            def answers():
                got, failed, skipped = self._answers(today)
                base = self._baseline()
                changed = [k for k, fp in got.items() if k in base["answers"] and base["answers"][k] != fp]
                new = [k for k in got if k not in base["answers"]]
                facts.update(questions=len(QUESTIONS), answers_changed=len(changed), questions_failed=len(failed),
                             questions_not_by_rules=len(skipped))
                if failed:
                    broke.append("questions")
                # a baseline only from a clean run: every question answered by the rules, past years matching
                clean = not failed and not skipped and not past.get("n") and "golden_missing" not in problems
                if accept and clean:
                    base["answers"] = dict(got)
                    changed, new = [], []
                    facts["accepted"] = len(got)
                elif accept:
                    facts["accepted"] = 0
                if new and clean:
                    base["answers"].update({k: got[k] for k in new})
                facts["baseline_new"] = len(new) if clean else 0
                if (accept or new) and clean:
                    base["set_at"] = now.isoformat(timespec="seconds")
                    self._write(self.baseline_path, base)
                if changed:
                    problems.append("answers_changed")

            def live():
                codes, more = self._live_codes(today)
                facts.update(more)
                problems.extend(c for c in codes if c != "no_data" or "no_data" not in problems)

            if data is not None:
                step("past_years", past_years)
                step("answers", answers)
            step("live", live)
            if broke:
                problems.append("error")
                facts["broke"] = broke
            record = {"at": now.isoformat(timespec="seconds"), "ok": not problems, "problems": problems,
                      "facts": facts, "ms": int((time.time() - started) * 1000)}
            self._live = None
            try:
                self._write(self.latest_path, record)
                with open(self.folder / "selfcheck.jsonl", "a", encoding="utf-8") as f:
                    f.write(json.dumps(record, sort_keys=True) + "\n")
            except OSError:
                pass
            return record

    # ------------------------------------------------------------ what /health shows
    def current(self):
        """The last record, with the codes that change during the day (data, model, the website's jobs, the
        feed) re-checked now, at most every 5 minutes, so a problem fixed since 2 a.m. stops showing and one that
        starts at 10 a.m. shows before the next night. The file isn't rewritten."""
        r = self.latest()
        if not r:
            return None
        r = dict(r)
        now = time.time()
        if self._live is None or now - self._live[0] > REFRESH_SECONDS:
            try:
                codes, facts = self._live_codes()
            except Exception:
                codes, facts = [c for c in r.get("problems", []) if c in LIVE], {}
            self._live = (now, codes, facts)
        _, codes, facts = self._live
        r["problems"] = [c for c in r.get("problems", []) if c not in LIVE] + codes
        r["facts"] = {**(r.get("facts") or {}), **facts}
        r["ok"] = not r["problems"]
        return r

    def attention(self, record=None):
        """One plain sentence for the page and the alert email, or ""."""
        record = record if record is not None else self.current()
        if not record or record.get("ok"):
            return ""
        f = record.get("facts") or {}
        for code in ATTENTION:
            if code in record.get("problems", []):
                as_of = _date(f.get("data_as_of"))
                text = MESSAGES[code].format(feed=f.get("feed_business_days") or "several",
                                             feed_job_limit=FEED_JOB_HOURS, ledger_limit=LEDGER_HOURS,
                                             silent_limit=SILENT_HOURS,
                                             as_of=as_of.strftime("%a %-d %b") if as_of else "some time ago",
                                             what=", ".join(f.get("broke") or []) or "see the log",
                                             key_near=_near(f.get("analytics_key_near_misses"), "analytics_key"),
                                             secret_near=_near(f.get("hub_secret_near_misses"),
                                                               "charts-hub.secret"),
                                             token_near=_near(f.get("tunnel_token_near_misses"),
                                                              "cloudflared-charts.token"),
                                             open_files=", ".join(f.get("key_files_open") or []) or "see the log",
                                             keys_error=str(f.get("keys_error") or "see the log"))
                return text + " (self-check " + str(record.get("at", ""))[:16].replace("T", " ") + ")"
        return ""

    def summary(self):
        r = self.current()
        if not r:
            return None
        out = {"at": r.get("at"), "ok": bool(r.get("ok")), "problems": list(r.get("problems") or [])}
        if self.weekly:                         # that a review exists, and how many candidates; no words
            out["weekly_review"] = {"date": self.weekly.get("date"), "candidates": self.weekly.get("candidates", 0)}
        return out

    def weekly_review(self, today=None):
        """write this week's review (weekly.py) and remember its counts."""
        from . import weekly
        from . import HUB_VERSION
        try:
            _path, summary = weekly.write(self.hub.cfg, HUB_VERSION, today or self.clock().date())
            self.weekly = summary
            return summary
        except Exception as e:                  # never let the review take the check down
            _log.warning("weekly review not written (%s)", type(e).__name__)
            return None

    # ------------------------------------------------------------ schedule
    def next_run(self, now=None):
        now = now or self.clock()
        t = now.replace(hour=AT[0], minute=AT[1], second=0, microsecond=0)
        return t if t > now else t + dt.timedelta(days=1)

    def start(self, first_delay=FIRST_DELAY):
        def loop():
            if self._stop.wait(first_delay):
                return
            while not self._stop.is_set():
                self.run()
                if self.clock().weekday() == WEEKLY_DAY and self.clock().hour < 12:
                    self.weekly_review()            # Monday, after the night's check
                wait = (self.next_run() - self.clock()).total_seconds()
                if self._stop.wait(max(60, wait)):
                    return
        self._thread = threading.Thread(target=loop, name="charts-selfcheck", daemon=True)
        self._thread.start()

    def stop(self):
        self._stop.set()
