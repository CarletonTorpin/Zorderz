"""The six operations.

dispatch() takes the opened request body and returns the response body. It
never raises: every failure becomes {"ok": false, "error": {code, message}}
with a safe, plain message: no stack traces, paths or data.
"""
import collections
import copy
import json
import logging
import datetime as dt
import re
import threading
import time
from pathlib import Path

from . import HUB_VERSION, access as acc, compute, names, store
from . import feed as feed_mod
from . import line_options as line_options_mod
from . import misses as misses_mod
from . import partners as partners_mod
from . import polite as P
from . import pivots as pivots_mod
from . import region as R
from .llm import Busy, InvalidSpec, ModelUnavailable, SpecWriter
from .spec import normalize, validate

_log = logging.getLogger("zorderz_hub.ops")
GRAIN_DETAILS = ("week", "day", "month_of_year", "quarter_of_year", "week_of_year", "day_of_week", "season")

SC_WAKE_SECONDS = 30 * 60      # a break this long in the website's scheduled calls (a sleep, an outage)

MESSAGES = {
    "bad_request": "That request wasn't understood.",
    "invalid_spec": "I couldn't turn that into a chart. Try rewording it, or use the chips.",
    "unsupported": "Charts can't answer that.",
    "not_configured": "Charts isn't set up yet on the hub.",
    "no_data": "The hub has no data yet. Drop the source files into the import folder.",
    "model_unavailable": "The question reader is offline. Chips and saved graphs still work.",
    "busy": "Charts is busy. Try again in a few seconds.",
    "rate_limited": "Too many questions in a minute. Try again shortly.",
    "forbidden": "Your account doesn't have access to that part of Charts.",
    "not_found": "That invoice isn't in the hub's data.",
    "internal": "Something went wrong on the hub.",
    # the Route Planner's recorded drives (survey.py)
    "survey_off": "Reading survey drives is turned off on the hub computer.",
    "bad_url": "That recording's address isn't the website's own.",
    "too_big": "That recording is too large for the hub computer.",
    "job_not_found": "That survey job isn't on the hub computer (it may have been finished and cleared).",
    "survey_busy": "The hub computer already has several of your recordings to read; this one waits on the website.",
    # an assistant module's link (zorderz_hub.assistant, when one is installed beside the hub)
    "assistant_off": "No assistant is installed or turned on on the hub computer.",
    "assistant_busy": "The hub computer already has several Assistant turns waiting; try again in a minute.",
    "turn_not_found": "That Assistant turn isn't on the hub computer (it may have been cleared).",
    "bad_fetch": "That turn's address isn't the website's own.",
    "ticket_too_big": "That Assistant turn's ticket is too large for the hub computer.",
}


FOLLOWUP_START = re.compile(
    r"^\s*(?:and|but|now|then|also|instead|same|just|only|except|without|with|plus|add|remove|drop|"
    r"make\s+it|show\s+(?:it|that|this|them)|change|switch|split|break\s+(?:it|that|this)|"
    r"by|per|as\s+(?:a|an)|in\s+(?:a|an)|what\s+about|how\s+about|and\s+for|for\s+(?:just|only)|"
    r"same\s+(?:but|for|thing)|that|this|those|these|it)\b", re.I)
FOLLOWUP_WORDS = re.compile(
    r"\b(?:instead|same\s+(?:chart|thing|but)|that\s+chart|this\s+chart|the\s+chart|previous|"
    r"horizontal|vertical|sideways|pie|donut|waffle|stacked|side\s+by\s+side|percent(?:ages)?|"
    r"numbers\s+on|labels?|flip|zoom)\b", re.I)


def is_followup(question):
    """Is this question about the chart already on screen? A short phrase, one
    that starts like a continuation ("now by month", "as a pie", "only GT"), or
    one that talks about the chart itself. Anything else is a new question."""
    q = question.strip()
    if not q:
        return False
    words = len(q.split())
    return words <= 4 or bool(FOLLOWUP_START.search(q)) or (words <= 12 and bool(FOLLOWUP_WORDS.search(q)))


class OpError(Exception):
    def __init__(self, code):
        super().__init__(code)
        self.code = code


def _say_prospect(text):
    """the neighborhoods' build, in the hub's log (as the import says its rebuilds)."""
    import sys
    print(f"{time.strftime('%Y-%m-%d %H:%M:%S')} {text}", file=sys.stderr, flush=True)


def _today(body):
    try:
        return dt.date.fromisoformat(str(body.get("today", ""))).isoformat()
    except ValueError:
        raise OpError("bad_request") from None


class RateLimiter:
    def __init__(self, per_minute):
        self.per_minute = per_minute
        self.hits = collections.defaultdict(collections.deque)
        self.lock = threading.Lock()

    def allow(self, user):
        now = time.time()
        with self.lock:
            q = self.hits[user]
            while q and now - q[0] > 60:
                q.popleft()
            if len(q) >= self.per_minute:
                return False
            q.append(now)
            return True



def _assistant_module():
    """The assistant plug-in: a module `zorderz_hub.assistant` installed beside the hub, with Link(cfg) (turn,
    status, cancel; raising LinkError("off" | "busy" | "not_found" | "bad_url" | "too_big")) and health(cfg).
    None when no assistant is installed."""
    try:
        from . import assistant
    except ImportError:
        return None
    return assistant

class Hub:
    def __init__(self, cfg, pack, lm, audit, accounts_loader=None):
        self.cfg, self.pack, self.lm, self.audit = cfg, pack, lm, audit
        from .accounts import Directory
        self.accounts_loader = accounts_loader or Directory
        self.accounts = Directory()
        self.writer = SpecWriter(lm, pack)
        self.limiter = RateLimiter(cfg["limits"]["ask_per_minute_per_user"])
        self.data = None
        self.data_lock = threading.Lock()
        self.feed_lock = threading.Lock()                # one feed write at a time
        self.pivots, self.pivots_source = list(pivots_mod.DEFAULT_SELECTED), "default"
        self.line_options = line_options_mod.Cache()     
        # NOAA's daily weather by ZIP, kept beside the store (weather.py). None when turned off.
        from . import weather as weather_mod
        self.weather = weather_mod.Store(cfg) if weather_mod.settings(cfg)["mode"] == "on" else None
        self.weather_updater = None
        # what each ZIP is like (Census ACS, Gazetteer, the state fire agency), kept beside the store
        # (zipfacts.py)
        from . import zipfacts as zipfacts_mod
        self.zipfacts = zipfacts_mod.Store(cfg, cfg.get("pack_dir") or getattr(pack, "source", None)) \
            if zipfacts_mod.settings(cfg)["mode"] == "on" else None
        self.zipfacts_updater = None
        # safety sites and safety councils (safety.py), kept beside the store
        from . import safety as safety_mod
        self.safety = safety_mod.Store(cfg, cfg.get("pack_dir") or getattr(pack, "source", None)) \
            if safety_mod.settings(cfg)["mode"] == "on" else None
        self.safety_updater = None
        # Example City places (places.py): address points, parcels, fire history, tax areas, mosquitoes, coast
        from . import places as places_mod
        self.places = places_mod.Store(cfg, cfg.get("pack_dir") or getattr(pack, "source", None)) \
            if places_mod.settings(cfg)["mode"] == "on" else None
        self.places_updater = None
        # prospecting (prospect.py): the neighborhoods, described once from the files above and cached beside
        # them; built in the background on first use or when a source file changes
        self.prospect_cells = None
        self._prospect_origin = None
        if self.places is not None:
            try:
                from . import prospect as prospect_mod
                fw_folder = safety_mod.folder_of(cfg) if self.safety is not None else None
                zf_folder = zipfacts_mod.folder_of(cfg) if self.zipfacts is not None else None
                self.prospect_cells = prospect_mod.Cells(self.places.folder, fw_folder, zf_folder,
                                                         lookup_factory=lambda: places_mod.Lookup(self.places))
            except Exception:              # prospecting must never take Charts down
                self.prospect_cells = None
        # the region's address points in their own words for the canvassing app's lookups (points.py)
        self._address_points = None
        self._place_lookup = None
        self.watcher = None
        # the Route Planner's recorded drives, transcribed and read here (survey.py); its worker starts with
        # the first job, or now when jobs were waiting when the hub stopped
        self._survey = None
        try:
            from . import survey as survey_mod
            if any(survey_mod.folder(cfg).glob("*.json")):
                self.survey_jobs().start()
        except Exception:                  # the survey must never take Charts down
            pass
        # questions Charts couldn't answer well, on this computer only.
        from pathlib import Path
        from .misses import MissLog
        self.misses = MissLog(Path(cfg.get("audit_log") or names.HUB_HOME / "logs" / "audit.jsonl").parent / "misses.jsonl",
                              cfg.get("pack_dir"))
        # the question reader (understand/): rules, no model, for every question it can place
        # word by word. "on" = it answers; a partly read question goes to the model with the
        # reader's sure parts locked. "shadow" = the model answers, the reader is only
        # compared in the log.
        self.reader_mode = str(cfg.get("reader_mode", "on")).lower()
        self.review_mode = str(cfg.get("review_mode", "off")).lower()
        try:
            self.review_seconds = max(3.0, min(30.0, float(cfg.get("review_seconds", 10))))
        except (TypeError, ValueError):
            self.review_seconds = 10.0
        self._review_system = None
        # the reply's shape is sent
        # (review_format "schema" | "text"); the look runs only where it can still change the chart
        # (review_scope "useful" | "all"); optional reply cap and Qwen's /no_think switch.
        self.review_format = str(cfg.get("review_format", "schema")).lower()
        self.review_scope = str(cfg.get("review_scope", "useful")).lower()
        self.review_no_think = bool(cfg.get("review_no_think", False))
        try:
            self.review_max_tokens = max(150, min(1500, int(cfg.get("review_max_tokens", 0)))) \
                if cfg.get("review_max_tokens") else None
        except (TypeError, ValueError):
            self.review_max_tokens = None
        self.caption_mode = str(cfg.get("caption_mode", "template")).lower()
        self.terms = None
        if self.reader_mode in ("on", "shadow"):
            try:
                from .understand import terms as terms_mod
                self.terms = terms_mod.build(pack, cfg.get("pack_dir") or getattr(pack, "source", None))
            except Exception:             # a broken terms file must not take Charts down
                self.terms = None
        self.reload()

    # ------------------------------------------------------------ data
    def reload(self, meta=None):
        try:
            data = store.load(self.cfg["store"])
        except store.StoreError:
            data = None
        try:
            directory = self.accounts_loader()
        except Exception:                 # a broken list must not take Charts down
            from .accounts import Directory
            directory = Directory()
        try:
            chosen = pivots_mod.load_selected(self.cfg)
        except Exception:                 # a damaged file must not take Charts down
            chosen = (list(pivots_mod.DEFAULT_SELECTED), "default")
        try:
            compute.normalise_towns(data, self.pack)    # one town per job, in memory
        except Exception:                 # never let a town fix take Charts down
            pass
        with self.data_lock:
            self.data = data
            self.accounts = directory
            self.pivots, self.pivots_source = chosen
        self.writer.clear_cache()

    def _data(self):
        data = self.data
        if data is None:
            raise OpError("no_data")
        return data

    # ------------------------------------------------------------ dispatch
    def dispatch(self, body):
        started = time.time()
        op = body.get("op") if isinstance(body, dict) else None
        user = str(body.get("user", ""))[:40] if isinstance(body, dict) else ""
        audit = {"op": op, "user": user}
        try:
            access = acc.parse(body.get("access") if isinstance(body, dict) else None)
            audit["caller"] = access["caller"]
            if op in acc.ALL_OPS:
                try:
                    acc.require(access, op)
                except acc.Forbidden:
                    raise OpError("forbidden") from None
            body = {**body, "_access": access}
            if user == "wp_cron":
                now = time.time()
                prev = getattr(self, "last_cron_seen", None)
                sc = getattr(self, "selfcheck", None)
                if prev and sc is not None and now - prev > SC_WAKE_SECONDS:
                    sc.resettle(now, "the website's calls resumed")     
                self.last_cron_seen = now           # the website's scheduled jobs reach the hub
            fn = {"health": self.op_health, "vocab": self.op_vocab, "ask": self.op_ask,
                  "run_spec": self.op_run_spec, "run_v2": self.op_run_v2,      # the app's chat
                  "search_lines": self.op_search_lines,
                  "drill": self.op_drill, "list_jobs": self.op_list_jobs,
                  "invoice": self.op_invoice, "pivot": self.op_pivot,
                  "data_status": self.op_data_status,
                  "put_counts": self.op_put_counts,
                  "put_sources": self.op_put_sources,
                  "put_invoices": self.op_put_invoices,
                  "line_options": self.op_line_options,
                  "survey_submit": self.op_survey_submit,          
                  "survey_result": self.op_survey_result,
                  "assistant_turn": self.op_assistant_turn,        
                  "assistant_status": self.op_assistant_status,
                  "assistant_cancel": self.op_assistant_cancel,
                  "flag": self.op_flag}.get(op)
            if fn is None:
                raise OpError("bad_request")
            data = fn(body, audit)
            audit.update(ok=True)
            return {"ok": True, "op": op, "data": data}
        except OpError as e:
            audit.update(ok=False, error=e.code)
            return {"ok": False, "op": op, "error": {"code": e.code, "message": MESSAGES[e.code]}}
        except Exception as e:                       # never leak details
            audit.update(ok=False, error="internal", exc=type(e).__name__)
            return {"ok": False, "op": op, "error": {"code": "internal", "message": MESSAGES["internal"]}}
        finally:
            audit["ms"] = int((time.time() - started) * 1000)
            self.audit.write(**audit)

    # ------------------------------------------------------------ ops
    def op_health(self, body, audit):
        if body["_access"].get("assistant_only"):
            # the Assistant's relay learns the version and whether the runner agent is up, nothing of the store's
            out = {"status": "ok", "hub_version": HUB_VERSION}
            mod = _assistant_module()
            try:
                out["assistant"] = mod.health(self.cfg) if mod is not None else {"runner": "not_installed"}
            except Exception:
                out["assistant"] = {"runner": "unknown"}
            return out
        if body["_access"].get("survey_only"):
            # a survey caller learns the version and whether this computer can read drives, nothing of the
            # store's
            out = {"status": "ok", "hub_version": HUB_VERSION}
            try:
                out["survey"] = self.survey_jobs().status()
            except Exception:
                out["survey"] = {"mode": "error"}
            return out
        data = self.data
        out = {
            "status": "ok" if data is not None and self.pack.configured else
                      ("not_configured" if not self.pack.configured else "no_data"),
            "data_as_of": (data.meta.get("data_as_of") if data else None),
            "hub_version": HUB_VERSION,
        }
        if "ask" in body["_access"]["ops"] or "data_status" in body["_access"]["ops"]:
            loaded = self.lm.models()
            want = {m for m in [self.lm.spec_model, self.lm.caption_model] if m}
            if "data_status" in body["_access"]["ops"]:
                out["models_loaded"] = {m: (m in loaded) for m in sorted(want)}
            else:
                out["reader_online"] = (bool(want) and want <= set(loaded)) or \
                    (self.reader_mode == "on" and self.terms is not None)
            out["reader_mode"] = self.reader_mode if self.terms is not None else "off"
        if self.weather is not None and ("ask" in body["_access"]["ops"] or "data_status" in body["_access"]["ops"]):
            try:                                 # dates and counts only
                from . import weather as weather_mod
                out["weather"] = weather_mod.summary(self.weather)
                up = self.weather_updater
                if up is not None and up.running:
                    out["weather"]["updating"] = up.progress or True
            except Exception:
                pass
        if self.safety is not None and ("ask" in body["_access"]["ops"] or "data_status" in body["_access"]["ops"]):
            try:                                 # counts and dates only
                from . import safety as safety_mod
                out["safety"] = safety_mod.summary(self.safety)
                if self.safety_updater is not None and self.safety_updater.running:
                    out["safety"]["updating"] = True
            except Exception:
                pass
        if self.places is not None and ("ask" in body["_access"]["ops"] or "data_status" in body["_access"]["ops"]):
            try:                                 # dates and counts only
                from . import places as places_mod
                out["places"] = places_mod.summary(self.places)
                if self.places_updater is not None and self.places_updater.running:
                    out["places"]["updating"] = True
            except Exception:
                pass
        if self.prospect_cells is not None and ("ask" in body["_access"]["ops"] or
                                                "data_status" in body["_access"]["ops"]):
            try:                                 # counts and a date only; never a file read here
                cust = getattr(self.data, "prospect_cells", None) if self.data is not None else None
                out["prospect"] = dict(self.prospect_cells.summary(),
                                       customers_counted=bool(cust is not None and len(cust)))
            except Exception:
                pass
        if self.zipfacts is not None and ("ask" in body["_access"]["ops"] or "data_status" in body["_access"]["ops"]):
            try:                                 # dates, counts and source names only
                from . import zipfacts as zipfacts_mod
                out["zipfacts"] = zipfacts_mod.summary(self.zipfacts)
                if self.zipfacts_updater is not None and self.zipfacts_updater.running:
                    out["zipfacts"]["updating"] = True
            except Exception:
                pass
        if "survey_submit" in body["_access"]["ops"]:
            try:  # is this computer ready to read drives (tools present, on/off)
                out["survey"] = self.survey_jobs().status()
            except Exception:
                pass
        sc = getattr(self, "selfcheck", None)
        if sc is not None:                       # codes and one plain sentence; no figures, no names
            try:
                out["selfcheck"] = sc.summary()
                out["attention"] = sc.attention()
            except Exception:
                pass
        return out

    # ------------------------------------------------------------ survey drives
    def survey_jobs(self):
        if self._survey is None:
            from . import survey as survey_mod
            self._survey = survey_mod.Jobs(self.cfg, self.lm, say=lambda m: _log.warning("%s", m))
        return self._survey

    def _survey_call(self, fn, body, audit):
        try:
            return fn(body)
        except ValueError as e:
            code = {"off": "survey_off", "bad_url": "bad_url", "too_big": "too_big",
                    "not_found": "job_not_found", "busy": "survey_busy"}.get(str(e), "bad_request")
            raise OpError(code) from None

    def op_survey_submit(self, body, audit):
        out = self._survey_call(self.survey_jobs().submit, body, audit)
        audit["kind"] = str(body.get("kind"))[:12]
        return out

    def op_survey_result(self, body, audit):
        out = self._survey_call(self.survey_jobs().result, body, audit)
        audit["status"] = out.get("status")
        return out

    # ------------------------------------------------------------ the Assistant's link
    def _assistant(self, name, body, audit):
        mod = _assistant_module()
        if mod is None or str((self.cfg.get("assistant") or {}).get("mode", "off")).lower() != "on":
            raise OpError("assistant_off")
        try:
            return getattr(mod.Link(self.cfg), name)(body)
        except mod.LinkError as e:
            raise OpError({"off": "assistant_off", "busy": "assistant_busy", "not_found": "turn_not_found",
                           "bad_url": "bad_fetch", "too_big": "ticket_too_big"}.get(str(e), "bad_request")) from None

    def op_assistant_turn(self, body, audit):
        out = self._assistant("turn", body, audit)
        audit["state"] = out.get("state")
        return out

    def op_assistant_status(self, body, audit):
        out = self._assistant("status", body, audit)
        audit["state"] = (out.get("turn") or {}).get("state", "none")
        return out

    def op_assistant_cancel(self, body, audit):
        out = self._assistant("cancel", body, audit)
        audit["state"] = out.get("state")
        return out

    def op_line_options(self, body, audit):
        """the Estimate Creator's tap-to-change map (product words + job counts only)."""
        pack_dir = self.cfg.get("pack_dir") or getattr(self.pack, "source", None)
        fmap = self.line_options.get(self.data, pack_dir)
        if fmap is None:
            raise OpError("not_configured")
        audit["rows"] = len(fmap.get("families", []))
        return {"map": fmap, "hub_version": HUB_VERSION,
                "data_as_of": self.data.meta.get("data_as_of") if self.data is not None else None}

    def op_vocab(self, body, audit):
        v = self.pack.vocab()
        data = self.data
        if data is not None:
            src = data.invoices["lead_source"].fillna("").replace("", None).dropna()
            v["lead_sources"] = src.value_counts().head(40).index.tolist()
            years = data.work["issued"].dt.year.dropna().astype(int)
            v["years"] = [int(years.min()), int(years.max())] if len(years) else []
            v["data_as_of"] = data.meta.get("data_as_of")
        v["base_spec"] = compute.base_spec(self.pack)
        v["payments"] = list(compute.PAYMENT_ORDER)            # paid / unpaid / draft (a filter and a split)
        try:                                    # what a version-2 spec may say (closed lists only)
            from .understand import spec2 as S
            v["spec2"] = {"keys": list(S.KEYS), "intents": ["chart", "number"], "measures": list(S.MEASURES),
                          "grains": list(S.GRAINS), "date_parts": list(S.DATE_PARTS), "compares": list(S.COMPARES),
                          "aggs": list(S.AGGS), "chart_types": list(S.CHART_TYPES),
                          "list_filters": list(S.LIST_FILTERS), "excludes": list(S.EXCLUDES),
                          "facets": list(S.FACETS), "splits": list(self.pack.splits),
                          "period_forms": ["all", "ytd", "qtd", "mtd", "this_year", "last_<n>d|w|m|y (last_60m)",
                                           "YYYY", "YYYY-q1..q4", "YYYY-h1|h2", "YYYY-MM", "YYYY-MM-DD",
                                           "YYYY-winter|spring|summer|fall", "A..B (2021..now, 2019..2023)"]}
        except Exception:
            pass
        if self.safety is not None:
            try:
                for kind, key in (("safety_site", "safety_sites"), ("safety_council", "safety_councils")):
                    names_ = self.safety.names(kind)
                    if names_:
                        v[key] = ["*"] + names_
            except Exception:
                pass
        if self.places is not None:              # the tax area / distance chips' choices
            try:
                from . import places as places_mod
                areas = self.places.tax()
                if areas:
                    v["tax_areas"] = [compute.tax_label(a["name"], a["rate"]) for a in areas]
                if R.current().has("coast"):     # distance bands only where the region declares a coastline
                    v["coast_bands"] = [label for _top, label in places_mod.COAST_BANDS]
                    v["coast_sides"] = list(places_mod.COAST_SIDES)
                    v["coast_side_miles"] = places_mod.coast_side_miles()
            except Exception:
                pass
        # the region's own place filters, for the website's filter chips: [{key, label, values}]
        reg = R.current()
        pf = []
        for key, split in (("safety_sites", "safety_site"), ("safety_councils", "safety_council"),
                           ("tax_areas", "tax_area"), ("coast_bands", "coast_band"), ("coast_sides", "coast")):
            vals = [x for x in (v.get(key) or []) if x != "*"]
            if vals:
                pf.append({"key": key, "label": reg.label(split), "values": vals})
        v["place_filters"] = pf
        v["accounts"] = [{"key": a.key, "label": a.label} for a in self.accounts.accounts]
        v.setdefault("split_labels", {})["account"] = "Account"
        if self.prospect_cells is not None:      # what a prospecting recipe may say
            try:
                from . import prospect as prospect_mod
                v["prospect"] = prospect_mod.vocab(self.pack)
            except Exception:
                pass
        return acc.restrict_vocab(v, body["_access"])

    def _compute(self, spec, audit, access):
        """Restrict the spec to what this caller may see, compute, then strip
        anything the caller may not receive. Returns the RESTRICTED spec."""
        data = self._data()
        started = time.time()
        spec, notes = acc.restrict_spec(spec, access, self.pack.not_recorded)
        result = compute.run(data, self.pack, spec)
        result = acc.restrict_result(result, access, spec)
        result["notes"] = notes + [n for n in result.get("notes", []) if n not in notes]
        audit["rows"] = len(result["rows"])
        return spec, result, int((time.time() - started) * 1000), data.meta.get("data_as_of")

    def op_run_spec(self, body, audit):
        if not self.pack.configured:
            raise OpError("not_configured")
        spec = normalize(body.get("spec"), self.pack)
        if validate(spec, self.pack, self.writer.schema):
            raise OpError("bad_request")
        known = self.accounts.by_key
        spec["filters"]["accounts"] = [k for k in spec["filters"].get("accounts") or [] if k in known]
        # a by-week (by-day, by month-of-year…) chart re-run from the chips kept only
        # "No time split" (version-1 grains are year, quarter, month). The page now sends the finer grain back
        # as grain_detail, and it is used while the chips' own grain is "none".
        gd = (body.get("spec") or {}).get("grain_detail") if isinstance(body.get("spec"), dict) else None
        gd = gd if gd in GRAIN_DETAILS and spec.get("time_grain", "none") == "none" else None
        if gd:
            spec["time_grain"] = gd
        # a comparison ("2024 vs 2025") comes back from the page as compare_windows and is counted again
        from .understand import spec2 as S2
        raw_spec = body.get("spec") if isinstance(body.get("spec"), dict) else {}
        cw = S2.compare_from_public(raw_spec.get("compare_windows"), spec)
        plan, why = S2.compare_plan(cw, spec) if cw else (None, None)
        cmp_notes = []
        if cw and plan and why:
            cmp_notes.append(why)
        if cw and plan and plan[0] != cw["as"] and spec.get("chart_type") not in ("map", "venn"):
            spec["chart_type"] = "auto"      # the Time chip changed how it's drawn; the old type was for the other
        if cw and plan:
            spec["windows"], (spec["window_as"], spec["time_grain"]) = cw["windows"], plan
            spec["date_from"] = min(w["from"] for w in cw["windows"])
            spec["date_to"] = max(w["to"] for w in cw["windows"])
            gd = plan[1] if plan[0] == "group" else None
            if spec.get("facet") not in (None, "none"):
                spec["facet"] = "none"
                cmp_notes.append("One chart for the compared periods; small charts left out")
        elif cw and why:
            cmp_notes.append(why)
        spec, result, ms, as_of = self._compute(spec, audit, body["_access"])
        if cmp_notes:
            result["notes"] = cmp_notes + [n for n in result.get("notes") or [] if n not in cmp_notes]
        for_title = copy.deepcopy(spec) if body.get("retitle") else None    # what was counted, as counted
        if spec.get("windows"):                             # the page keeps the comparison, not the windows
            ws_ = spec.pop("windows")
            as_ = "group" if spec.pop("window_as", "period") == "group" else "period"
            if as_ == "period":
                spec["date_from"], spec["date_to"] = ws_[-1]["from"], ws_[-1]["to"]
            spec["compare_windows"] = {"as": as_, "windows": ws_, "date_from": spec.get("date_from"),
                                       "date_to": spec.get("date_to")}
        raw_w = (body.get("spec") or {}).get("weather") if isinstance(body.get("spec"), dict) else None
        if raw_w and spec.get("compare_windows"):           # as an asked comparison: weather left off
            result["notes"] = list(result.get("notes") or []) + [
                "Weather is shown beside one chart over time; this one compares periods side by side, so it's left off."]
            result["weather"] = {"status": "not_this_chart"}
            if self.weather is not None:
                from .understand import weatherview
                if not weatherview.check(raw_w):
                    spec["weather"] = weatherview.full(raw_w)
            raw_w = None
        if raw_w and self.weather is not None:              # a chip edit or a saved chart keeps its weather
            from .understand import execute as ex, weatherview
            data = self._data()
            try:
                today = _today(body)
            except OpError:
                today = dt.date.today().isoformat()
            ctx = self._reader_context(data, today)
            runner = ex.Runner(data, self.pack, body["_access"], None, weather=self.weather)
            wnotes = weatherview.attach_v1(spec, raw_w, result, runner, ctx.first or ctx.anchor, ctx.anchor)
            result["notes"] = list(result.get("notes") or []) + [n for n in wnotes if n]
            if not weatherview.check(raw_w):
                spec["weather"] = weatherview.full(raw_w)
        if gd:
            from .understand import execute as ex
            keys = [str(p) for p in result.get("periods") or []]
            a_, b_ = str(spec.get("date_from") or ""), str(spec.get("date_to") or "")
            one_year = bool(a_ and b_ and a_[:4] == b_[:4])
            ex.relabel(result, {p: ex._label_key(p, gd, one_year) for p in keys})
            spec["time_grain"], spec["grain_detail"] = "none", gd       # what the page's chips and schema know
        raw_z = (body.get("spec") or {}).get("zipfacts") if isinstance(body.get("spec"), dict) else None
        if raw_z and (self.zipfacts is not None or spec.get("split") in compute.TERRITORY_SPLITS):   
            from .understand import zipview
            z = zipview.clean(raw_z)
            if z:
                spec["zipfacts"] = z
        caption = None
        if body.get("caption") and "ask" in body["_access"]["ops"]:
            caption = self._caption(spec, result, {}, body["_access"])
        spec, result = self._present(spec, result, {}, body["_access"])
        raw = body.get("spec") if isinstance(body.get("spec"), dict) else {}
        if for_title is not None:
            # Not for saved charts, a named account, or a weather-only chart.
            from .understand import describe
            full = self._retitle(for_title, raw, body)
            if full:
                zf = result.get("zipfacts")
                if isinstance(zf, dict) and zf.get("view"):     # a ZIP-fact map says what was divided
                    from .understand import zipview
                    full = zipview.title(zf, describe.short(full), spec.get("measure")) or full
                spec["title"] = describe.short(full)
                result["title_full"] = full
        v2 = (raw.get("v2") or {}).get("spec") if isinstance(raw.get("v2"), dict) else None
        kind_ = (raw.get("v2") or {}).get("kind") if isinstance(raw.get("v2"), dict) else None
        cw_ = spec.get("compare_windows")
        if kind_ is None and isinstance(cw_, dict):          # years over a calendar are lines, not stacks
            kind_ = "overlay" if cw_.get("as") == "group" else "windows"
        result["look"] = self._look(spec, result, kind_, v2 if isinstance(v2, dict) else None, "spec")
        if result["look"] and spec.get("chart_type") in (None, "", "auto") and result["look"].get("type"):
            result["chart_type"] = result["look"]["type"]     # "auto" from the page: the shape's choice
        out = {"spec": spec, "result": result, "floor_key": compute.floor_key(spec),
               "meta": {"compute_ms": ms, "data_as_of": as_of, "cached": False}}
        if caption is not None:
            out["caption"] = caption
            out["meta"]["model"] = self.lm.caption_model
        return out

    # ------------------------------------------------------------ run a v2 spec
    def _retitle(self, v1, raw, body):
        """the title a version-1 spec would get had it been asked: read back as v2 (with its weather and its
        customer kept out of the reading, as _previous_v2 does) and described the way an answer is. None when it
        can't be said safely: a named account, a Venn chart, a weather-only view, a range from_v1 can't place, any
        error (logged)."""
        from .understand import describe as D, execute
        from .understand import spec2 as S
        v1 = copy.deepcopy(v1)
        f = v1.setdefault("filters", {})
        if f.get("accounts") or v1.get("split") == "account" or v1.get("facet") == "account":
            return None
        if v1.get("chart_type") == "venn":
            return None
        w = raw.get("weather") if isinstance(raw.get("weather"), dict) else None
        if w and w.get("view") == "only":
            return None
        customers = list(f.pop("customers", None) or [])
        wins = v1.get("windows") if isinstance(v1.get("windows"), list) and len(v1.get("windows")) >= 2 else None
        try:
            data = self._data()
            try:
                today = _today(body)
            except OpError:
                today = dt.date.today().isoformat()
            ctx = self._reader_context(data, today)
            s2 = S.from_v1(v1, ctx.anchor, ctx.first)
            dated = bool(v1.get("date_from") or v1.get("date_to"))
            if dated and not s2.get("periods"):
                return None                               # never call a dated chart "all history"
            # a range from the first job to the last is "all history", as the question that drew it said
            try:
                if wins:
                    raise ValueError                      # a comparison names its own windows
                a_ = dt.date.fromisoformat(str(v1.get("date_from") or "")[:10]) if v1.get("date_from") else None
                b_ = dt.date.fromisoformat(str(v1.get("date_to") or "")[:10]) if v1.get("date_to") else None
                issued = data.work["issued"].dropna() if "issued" in data.work else None
                last = issued.max().date() if issued is not None and len(issued) else None
                if (a_ is None or (ctx.first and a_ <= ctx.first)) and \
                        (b_ is None or b_ >= ctx.anchor or (last and b_ >= last)):
                    s2["periods"] = ["all"]
            except (TypeError, ValueError):
                pass
            if not s2.get("periods"):
                s2["periods"] = ["all"]
            if w and self.weather is not None:           # the weather beside the chart is in its title
                from .understand import weatherview
                if not weatherview.check(w):
                    s2["weather"] = {k: v for k, v in weatherview.full(w).items() if v is not None}
            # planned, not counted (the chart above was the one count); the chips' own settings ride along
            keep = {k: v1[k] for k in v1 if k == "include_unpaid" or (isinstance(k, str) and k.endswith("_mode"))}
            runner = execute.Runner(data, self.pack, body["_access"], {"customers": customers[:1]} if customers else None,
                                    weather=None, dry=True, v1_fields=keep)
            answer = execute.run(s2, runner, ctx.anchor, ctx.first)
            if wins and answer.kind in ("windows", "overlay"):   # the windows exactly as counted, with their names
                from .understand import periods as P_
                answer.windows = [P_.Window(dt.date.fromisoformat(w["from"]), dt.date.fromisoformat(w["to"]),
                                            str(w.get("label") or ""), "..") for w in wins]
            elif wins:
                return None                               # never a title for a comparison the plan didn't keep
            full = D.title(answer, self.pack)
        except Exception as e:                            # the old title stays; the reason is logged
            _log.warning("retitle skipped: %s", type(e).__name__)
            return None
        if not full:
            return None
        if customers:
            full = "This customer: " + full
        return full[:200]

    def op_run_v2(self, body, audit):
        """run a version-2
        (sparse) spec as written -- for the app's chat, which writes the spec itself from the
        closed lists `vocab` publishes. No reader, no model, no second look, no caption model:
        the rows come back in milliseconds and every number is the hub's own. The same access
        floors as run_spec (`bot` callers never get names or line items). A spec that fails
        the checks is answered, not guessed: `status: "invalid"` and the plain-words problems,
        so the chat can fix its spec and ask again."""
        from .understand import caption as cap_mod, charttype, describe, execute
        from .understand import spec2 as S
        if not self.pack.configured:
            raise OpError("not_configured")
        raw = body.get("spec")
        if not isinstance(raw, dict) or not raw:
            raise OpError("bad_request")
        if body["_access"].get("public_only") and set(raw) - {"points", "place"}:
            raise OpError("forbidden")           # a Doorhangers walker without Charts: public data only
        if body["_access"].get("public_only") and len(raw) != 1:
            raise OpError("forbidden")
        if "prospect" in raw:                    # where to hang door hangers (prospect.py)
            return self._prospect(raw, body, audit)
        if "streets" in raw:  # Doorhangers asks the region's points here first
            return self._streets(raw, body, audit)
        if "points" in raw:  # the canvassing app's address-point queries, answered here
            return self._address_points_query(raw, body, audit)
        if "place" in raw:                       # what a drop's place is like (ZIP facts, fire history, coast)
            return self._place(raw, body, audit)
        started = time.time()
        data = self._data()
        access = body["_access"]
        today = _today(body)
        ctx = self._reader_context(data, today)
        anchor, first = ctx.anchor, ctx.first
        spec2_ = S.full(raw)
        f = spec2_.setdefault("filters", {})
        # Named accounts are for people at the screen only (the bot's models run elsewhere).
        if not access.get("accounts"):
            f.pop("accounts", None)
        if spec2_.get("intent") not in ("chart", "number"):
            spec2_["intent"] = "chart"
        problems = S.check(spec2_, self.pack, anchor, first)
        if problems:
            audit["reader"] = "invalid"
            return {"status": "invalid", "problems": [str(x)[:200] for x in problems[:6]],
                    "meta": {"compute_ms": 0, "data_as_of": data.meta.get("data_as_of")}}
        runner = execute.Runner(data, self.pack, access, {}, weather=self.weather)
        try:
            answer = execute.run(spec2_, runner, anchor, first)
        except execute.ExecuteError as e:
            audit["reader"] = "clarify"
            return {"status": "clarify", "clarify_question": str(e)[:200],
                    "meta": {"compute_ms": 0, "data_as_of": data.meta.get("data_as_of")}}
        intent = answer.spec.get("intent", "chart")
        status = intent if intent in {"chart", "number", "clarify", "unsupported"} else "invalid"
        pub = S.to_public(answer.v1)
        if (answer.v1 or {}).get("time_grain") in GRAIN_DETAILS:
            pub["grain_detail"] = answer.v1["time_grain"]
        pub["intent"] = intent
        full_title = describe.title(answer, self.pack)
        wx = answer.result.get("weather") if isinstance(answer.result, dict) else None
        if answer.spec.get("weather"):
            from .understand import weatherview
            pub["weather"] = weatherview.full(answer.spec["weather"])
            if isinstance(wx, dict) and wx.get("view") == "only" and wx.get("status") == "ok":
                full_title = weatherview.only_title(answer, wx, self.pack)
        if answer.spec.get("zipfacts") and (self.zipfacts is not None or
                                            answer.spec.get("split") in compute.TERRITORY_SPLITS):
            from .understand import zipview
            z = zipview.clean(answer.spec["zipfacts"])
            if z:
                pub["zipfacts"] = z
        pub["title"] = describe.short(full_title)
        pub["assumptions"] = [str(a)[:160] for a in list(answer.v1.get("assumptions") or [])][:4]
        pub["clarify_question"] = (answer.spec.get("clarify_question") or None) \
            if intent in ("clarify", "unsupported") else None
        pub["clarify_options"] = []
        if answer.kind == "windows" and answer.windows:
            last = answer.windows[-1]
            pub["date_from"], pub["date_to"] = last.start.isoformat(), last.end.isoformat()
        cmp_ = S.public_compare(answer.kind, answer.windows, pub.get("date_from"), pub.get("date_to"))
        if cmp_:                                           # the page keeps the comparison through chip edits
            pub["compare_windows"] = cmp_
        charttype.apply(answer, pub, "shape")
        pub["v2"] = {"spec": S.sparse(answer.spec),
                     "windows": {w.label: [w.start.isoformat(), w.end.isoformat()] for w in answer.windows}
                     if answer.kind in ("windows", "overlay") else {},
                     "kind": answer.kind, "source": "spec"}
        result = answer.result
        result["notes"] = [n for n in answer.notes if n] + [n for n in result.get("notes", []) if n not in answer.notes]
        result["title_full"] = full_title
        result["read_as"] = describe.read_as(answer, [])
        if answer.spec.get("weather"):
            from .understand import weatherview
            line = weatherview.describe(result.get("weather"))
            if line:
                result["read_as"].insert(min(2, len(result["read_as"])), line)
        ms = int((time.time() - started) * 1000)
        pub, result = self._present(pub, result, {}, access)
        result["look"] = self._look(pub, result, answer.kind, answer.spec, "shape",
                                    charttype.choose(answer)[0] if not result.get("empty") else "table")
        caption = ""
        if status in {"chart", "number"} and not result.get("empty"):
            answer.result = result
            caption = cap_mod.template(answer, self.pack)
        elif result.get("empty"):
            caption = "No matching jobs for these filters."
        wx = result.get("weather")
        if isinstance(wx, dict) and wx.get("status") == "ok" and status in {"chart", "number"}:
            from .understand import weatherview
            noun = {"month": "month", "week": "week", "day": "day", "year": "year", "quarter": "quarter"}.get(
                answer.spec.get("grain"), "period")
            more = weatherview.sentences(wx, (pub.get("measure") or answer.spec.get("measure")), noun)
            if more:
                caption = (caption.rstrip() + " " if caption else "") + " ".join(t.rstrip(".") + "." for t in more)
        audit.update(model="spec", reader="spec", rows=len(result.get("rows") or []))
        return {"spec": pub, "status": status, "result": result, "caption": caption,
                "floor_key": compute.floor_key({k: v for k, v in pub.items() if k != "v2"}),
                "meta": {"model": "spec", "backup_model_used": False, "cached": False, "llm_ms": 0,
                         "reader": "spec", "compute_ms": ms, "data_as_of": data.meta.get("data_as_of")}}

    # ------------------------------------------------------------ prospecting
    def _zip_names(self):
        names = getattr(self, "_zip_names_cache", None)
        if names is not None:
            return names
        names = {}
        try:
            from . import weather as W
            folder = self.cfg.get("pack_dir") or getattr(self.pack, "source", None)
            gj = json.loads(Path(W.zip_areas_path(folder)).read_text(encoding="utf-8"))
            for f in gj.get("features") or []:
                pr = f.get("properties") or {}
                if pr.get("z") and pr.get("n"):
                    names[str(pr["z"])[:5]] = str(pr["n"])
        except Exception:
            pass
        for z, n in (self.pack.zips or {}).items():
            names.setdefault(z, n)
        self._zip_names_cache = names
        return names

    def _origin(self):
        """(lat, lon, label) of the pack's origin (the shop's street); None when the pack has none. A miss (the
        address points not read yet) is tried again after ten minutes, not kept for the hub's life."""
        if self._prospect_origin is not None:
            if self._prospect_origin or time.time() - getattr(self, "_prospect_origin_at", 0) < 600:
                return self._prospect_origin or None
        self._prospect_origin_at = time.time()
        o = (getattr(self.pack, "prospect", None) or {}).get("origin")
        got = False
        if o and self.places is not None:
            from . import prospect as prospect_mod
            c = prospect_mod.street_centre(self.places.folder / "addresses.sqlite", o["street"], o["zip"])
            if c:
                got = (c[0], c[1], o.get("label") or "the shop")
        self._prospect_origin = got
        return got or None

    def _ask_territories(self, body, audit, question):
        """The territories live in the website's Territories app; the website asks it as the person asking and
        draws the map. No territory list, person or ZIP assignment is ever sent here or kept here; the answer
        is the reading alone (status `territories`, no rows). None for any other question."""
        from .understand import territoryread
        try:                                      # a town is not a person: "Cedar Hills's zip codes" is a chart
            places = {str(n).lower() for n in self._zip_names().values()}
            places |= {str(a).lower() for a in (getattr(self.pack, "areas", None) or [])}
        except Exception:
            places = set()
        got = territoryread.read(question, places)
        if got is None:
            return None
        audit.update(model="rule", reader="territories", rows=0)
        return {"status": "territories", "question": question,
                "spec": {"territories": {"who": got["who"]}, "title": got["title"]},
                "result": {"rows": [], "read_as": list(got["read_as"]), "notes": [], "title_full": got["title"]},
                "caption": "",
                "meta": {"model": "rule", "reader": "territories", "backup_model_used": False, "cached": False,
                         "llm_ms": 0, "compute_ms": 0, "data_as_of": None}}

    def _ask_prospect(self, body, audit, question):
        """A prospecting question in words -> the same answer as a recipe (understand/prospectread.py). None for any
        other question, which goes on to the chart reader untouched."""
        from .understand import prospectread
        cells = self.prospect_cells._data if self.prospect_cells is not None else None
        comm = sorted({c.get("community") for c in (cells or {}).get("cells", []) if c.get("community")})
        got = prospectread.read(question, self.pack, self._zip_names(), comm)
        if got is None:
            return None
        recipe, read_notes = got
        out = self._prospect({"prospect": recipe}, body, audit)
        if out.get("status") == "prospect" and read_notes:
            out["result"]["read_as"] = list(out["result"].get("read_as") or []) + read_notes
        out["question"] = question
        return out

    def _prospect(self, raw, body, audit):
        """A prospecting recipe -> the ranked neighborhoods. Every caller that may run_v2 may ask: the answer is
        counts, shares, distances and the region's street names, never a customer, an amount or an invoice.
        The website adds `hung` (Doorhangers' streets and dates, read through WordPress); it is never stored
        here."""
        from . import prospect as prospect_mod
        started = time.time()
        audit["reader"] = "prospect"
        data = self.data
        meta = {"compute_ms": 0, "data_as_of": (data.meta.get("data_as_of") if data is not None else None)}
        if self.prospect_cells is None or not self.prospect_cells.available:
            return {"status": "unsupported", "meta": meta,
                    "clarify_question": "Prospecting needs the region's address points on the hub computer (Places is off, "
                                        "or hasn't read them yet)."}
        spec_in = {"prospect": raw.get("prospect") if isinstance(raw.get("prospect"), dict) else {}}
        cells = self.prospect_cells.get(wait=False, say=_say_prospect)
        if cells is None:
            return {"status": "clarify", "meta": meta, "spec": spec_in,
                    "clarify_question": "The neighborhoods are being prepared from the region's address points "
                                        "(about a minute after an update). Ask again shortly."}
        hung = body.get("hung")
        if hung is not None:
            hung = [h for h in hung if isinstance(h, dict)][:2000] if isinstance(hung, list) else None
        try:
            recipe, notes = prospect_mod.clean_recipe(raw.get("prospect"), self.pack)
            cust_frame = getattr(data, "prospect_cells", None) if data is not None else None
            cust = prospect_mod.cust_index(cust_frame)
            zf = self.zipfacts.facts() if self.zipfacts is not None and hasattr(self.zipfacts, "facts") else {}
            try:
                today = dt.date.fromisoformat(_today(body))
            except OpError:
                today = dt.date.today()
            out = prospect_mod.rank(recipe, cells["cells"], cust, zf, self.pack, self._zip_names(), hung=hung,
                                    today=today, origin=self._origin())
        except prospect_mod.RecipeError as e:
            return {"status": "clarify", "clarify_question": str(e)[:300], "meta": meta, "spec": spec_in}
        if not cust:
            notes.append("Customers and jobs aren't counted per neighborhood yet (the next import does it).")
        out["notes"] = notes + out["notes"]
        rows = out["rows"]
        lines = [f"{r['rank']}. {r['name']} {r['zip']} -- {r['homes']} homes: {r['reason']}." for r in rows]
        caption = (out["title"] + ": " + " ".join(lines)) if rows else "No neighborhoods matched."
        meta["compute_ms"] = int((time.time() - started) * 1000)
        meta["neighborhoods_built"] = cells.get("built")
        if not self.prospect_cells._fresh(cells):
            out["notes"].append("The region's files changed; these are last build's neighborhoods while new ones "
                                "are made.")
        audit.update(model="spec", rows=len(rows))
        return {"status": "prospect", "spec": {"prospect": recipe, "title": out["title"]}, "result": out,
                "caption": caption, "meta": meta}

    def _streets(self, raw, body, audit):
        """Doorhangers' street lookups, answered from the region's address points on this computer
        (streetfacts.py) before Doorhangers asks the region's website. Public region data only."""
        from . import streetfacts
        audit["reader"] = "streets"
        q = raw.get("streets")
        if self.places is None or not (self.places.folder / "addresses.sqlite").exists():
            return {"status": "unsupported", "clarify_question": "The region's address points aren't on the hub computer."}
        if not isinstance(q, dict) or not isinstance(q.get("streets"), list):
            raise OpError("bad_request")
        got = streetfacts.facts(self.places.folder / "addresses.sqlite", q["streets"], q.get("near"),
                                self._zip_names())
        audit.update(model="spec", rows=len(got))
        return {"status": "streets", "result": {"streets": got, "source": "region address points (the hub computer)"}}

    def _points(self):
        from . import points as points_mod
        if self.places is None:
            return None
        if self._address_points is None:
            self._address_points = points_mod.Points(self.places.folder / "points.sqlite")
        return self._address_points if self._address_points.available() else None

    def _address_points_query(self, raw, body, audit):
        """one of the canvassing app's address-point queries (a closed shape, points.py) -> the region's own
        values from the copy on this computer. Public region data only."""
        audit["reader"] = "points"
        q = raw.get("points")
        pts = self._points()
        if pts is None:
            return {"status": "unsupported", "reason": "no_copy",
                    "clarify_question": "The region's address points aren't on the hub computer yet (places update --only points)."}
        if not isinstance(q, dict):
            raise OpError("bad_request")
        if (q.get("kind") or "points") != "points":  # a ZIP's outline stays the region's (not points')
            raise OpError("bad_request")
        env = q.get("envelope")
        if env is not None:
            if not (isinstance(env, list) and len(env) == 4 and all(isinstance(v, (int, float)) for v in env)):
                raise OpError("bad_request")
            if env[2] - env[0] > 0.2 or env[3] - env[1] > 0.2:      # ~20 km: never the whole county in one answer
                raise OpError("bad_request")
        name = q.get("name")
        if name is not None and (not isinstance(name, str) or not name.strip() or len(name) > 80):
            raise OpError("bad_request")
        zip5 = q.get("zip")
        if zip5 is not None and not (isinstance(zip5, str) and len(zip5) == 5 and zip5.isdigit()):
            raise OpError("bad_request")
        num = q.get("num")
        if num is not None and not (isinstance(num, int) and not isinstance(num, bool) and num > 0):
            raise OpError("bad_request")
        lim = q.get("limit")
        if lim is not None and not (isinstance(lim, int) and not isinstance(lim, bool)):
            raise OpError("bad_request")
        if env is None and not name and not zip5:
            raise OpError("bad_request")
        out = pts.query({"name": name, "like": bool(q.get("like")), "num": num, "zip": zip5, "envelope": env,
                         "count_only": bool(q.get("count_only")), "limit": lim})
        if out is None:
            return {"status": "unsupported", "reason": "no_copy", "clarify_question": "The region's copy isn't readable."}
        audit.update(model="spec", rows=len(out.get("features") or []))
        return {"status": "points", "result": out, "source": "the region's address points (the hub computer)"}

    def _place(self, raw, body, audit):
        """what a drop's place is like, from this computer: its ZIP and town, the ZIP's Census figures
        (zipfacts), whether a past fire reached the spot and the last year, miles from the coast. For
        Doorhangers' walk log, in place of the Census website (which wants a key) -- ZIP-wide figures, said
        so."""
        audit["reader"] = "place"
        q = raw.get("place")
        if not isinstance(q, dict):
            raise OpError("bad_request")
        try:
            lat, lng = float(q.get("lat")), float(q.get("lng"))
        except (TypeError, ValueError):
            raise OpError("bad_request") from None
        if not R.current().inside(lat, lng):
            return {"status": "unsupported", "clarify_question": "That place is outside the region the hub covers."}
        pts = self._points()
        zip5 = pts.nearest_zip(lat, lng) if pts is not None else None
        out = {"zip": zip5, "town": self._zip_names().get(zip5 or "", "") if zip5 else ""}
        zf = self.zipfacts.facts() if self.zipfacts is not None and hasattr(self.zipfacts, "facts") else {}
        f = ((zf or {}).get("zips") or {}).get(zip5 or "") if zip5 else None
        if f:
            keep = ("income", "home_value", "year_built", "owner_pct", "population", "households", "homes",
                    "single_family_pct", "pre1980_pct", "fire_vh_pct", "age")
            out["zip_facts"] = {k: f.get(k) for k in keep if f.get(k) is not None}
            out["acs_year"] = zf.get("acs_year")
        if self.places is not None:
            try:
                from . import places as places_mod
                m = self.places.meta() if hasattr(self.places, "meta") else {}
                key = tuple((m.get(n) or {}).get("built") for n in ("fires", "coast"))   # new fires reach it
                if self._place_lookup is None or getattr(self, "_place_lookup_key", None) != key:
                    self._place_lookup = places_mod.Lookup(self.places)
                    self._place_lookup_key = key
                at = self._place_lookup.at([lng], [lat])
                if at["ok"][0]:
                    out["fires"] = int(at["fires"][0])
                    out["last_fire_year"] = int(at["last_fire"][0]) or None
                c = float(at["coast"][0])
                out["miles_to_coast"] = None if c != c else round(c, 1)
            except Exception:
                pass
        audit.update(model="spec", rows=1)
        return {"status": "place", "result": out,
                "source": "the hub computer (Census ACS by ZIP, the region's fire history, the coastline)"}

    # ------------------------------------------------------------ drill-down
    def _group_arg(self, body):
        g = body.get("group")
        if g is None:
            return None
        g = str(g).strip()
        if not g or len(g) > 80:
            raise OpError("bad_request")
        return g

    @staticmethod
    def _period_arg(body, spec):
        """the tapped bar's period, exactly as the chart wrote it ("2020",
        "2020Q3", "2020-03") for the spec's own time grain. Absent/null = the whole
        range. Anything else is refused, never guessed."""
        p = body.get("period")
        if p is None:
            return None
        if compute.period_window(p, spec.get("time_grain")) is None:
            raise OpError("bad_request")
        return p

    @staticmethod
    def _window_of(raw, spec, group):
        """a line of a years-over-one-calendar chart ("2024") is that year's window:
        the tapped line's jobs, within the chart's own dates (never wider)."""
        v2 = raw.get("v2") if isinstance(raw, dict) else None
        wins = v2.get("windows") if isinstance(v2, dict) else None
        if not isinstance(wins, dict) and isinstance(raw, dict):
            # the page sends no v2 copy; a line of a by-calendar comparison is its window
            from .understand import spec2 as S
            cw = S.compare_from_public(raw.get("compare_windows"), spec)
            if cw and cw["as"] == "group":
                wins = {w["label"]: [w["from"], w["to"]] for w in cw["windows"]}
        if not isinstance(wins, dict) or group not in wins or spec.get("split", "none") != "none":
            return spec
        try:
            a, b = (dt.date.fromisoformat(str(x)) for x in wins[group])
        except (TypeError, ValueError):
            return spec
        lo = max(a, dt.date.fromisoformat(spec["date_from"])) if spec.get("date_from") else a
        hi = min(b, dt.date.fromisoformat(spec["date_to"])) if spec.get("date_to") else b
        if lo > hi:
            return spec
        return {**spec, "date_from": lo.isoformat(), "date_to": hi.isoformat()}

    DRILL_WORD = {"referral_partner": "company"}
    UNIT_WORD = {"jobs": "jobs", "customers": "customers", "revenue": "$", "avg_job": "$"}

    def _min_jobs(self):
        return self.pack.drill_min_jobs or compute.MIN_JOBS

    def op_drill(self, body, audit):
        """A clicked bar or slice, one level down: that group only, as 100% bars. with `period`, that period
        only (the bar that was tapped); without it, the chart's whole range. Each bar is labelled with its
        own range and totals and, where "Existing Customer" folds in from-history jobs, how many."""
        import copy
        started = time.time()                     # every step counts
        if not self.pack.configured:
            raise OpError("not_configured")
        spec = normalize(body.get("spec"), self.pack)
        group = self._group_arg(body)
        if group is None or validate(spec, self.pack, self.writer.schema):
            raise OpError("bad_request")
        period = self._period_arg(body, spec)
        spec = self._window_of(body.get("spec"), spec, group)
        grain = spec.get("time_grain", "none")
        data = self._data()
        access = body["_access"]
        spec, _ = acc.restrict_spec(spec, access, self.pack.not_recorded)
        span = compute.chart_span(data, self.pack, spec, grain)
        whole = compute.range_label(*span, grain) if span else None
        where = compute.period_label(period, grain) if period else whole
        all_label = f"All {whole}" if whole and grain in {"year", "none"} else "All periods"
        empty = False
        base = spec
        if period:
            audit["period"] = period
            base, empty = compute.narrow_to_period(spec, period, grain)
            start, end = compute.period_window(period, grain)
            if not empty and (base["date_from"], base["date_to"]) != (start.date().isoformat(), end.date().isoformat()):
                # the chart's own dates cut this period short: say which part it is
                where = compute.range_label(base["date_from"], base["date_to"], grain)
        if spec.get("split", "none") == "none" and not empty:
            # a chart with no split (one bar per period): a tapped bar lists that
            # period's jobs, the floor below it. The spec already is the whole group.
            can_list = "list_jobs" in access["ops"] and not acc.is_comparison(spec, access)   # own jobs only, not under another's name
            return {"drill": None,
                    "jobs_of": {"group": group, "list_group": None, "spec": base, "period": period,
                                "label": (f"{spec.get('title') or 'All jobs'}, {where}" if where else
                                          (spec.get("title") or "All jobs"))[:120],
                                "floor_key": compute.floor_key(base, "jobs")} if can_list else None}
        child = compute.drill_spec(data, self.pack, base, group)
        info = {"group": group, "period": period, "period_label": where if period else None,
                "range_label": whole, "all_label": all_label}
        if empty:
            # No spec goes back, so nothing can list wider jobs.
            split = child.get("split") if child else None
            word = self.DRILL_WORD.get(split, (split or "").replace("_", " "))
            title = f"{group}, {where}" + (f" \u2014 by {word}" if word else "") + " \u00b7 no matching jobs"
            return {"spec": None,
                    "result": compute._empty(f"No jobs in {where} within this chart's dates."),
                    "drill": {**info, "split": split, "jobs": False, "title": title, "unit": None,
                              "bars": [], "thin": None},
                    "meta": {"compute_ms": 0, "data_as_of": data.meta.get("data_as_of")}}
        if child is None:
            # the bottom level (a company, …): its jobs, when this person may list them
            can_list = "list_jobs" in access["ops"] and \
                access.get("line_items", "none") != "none" and \
                not acc.is_comparison(spec, access) and \
                compute.with_group(base, group, self.pack) is not None
            return {"drill": None,
                    "jobs_of": {"group": group, "list_group": group, "spec": base, "period": period,
                                "label": f"{group}, {where}" if where else group,
                                "floor_key": compute.floor_key(compute.with_group(base, group, self.pack) or base,
                                                               "jobs")} if can_list else None}
        # One child split for every bar, chosen over the whole range, so the bars compare.
        whole_spec = copy.deepcopy(spec)
        if span:
            whole_spec["date_from"], whole_spec["date_to"] = span
        template = compute.drill_spec(data, self.pack, whole_spec, group) or child
        word = self.DRILL_WORD.get(template.get("split"), str(template.get("split")).replace("_", " "))
        # an average (or a count of customers) can't make a 100% bar;
        # those drills are side-by-side bars from the start, never the "needs parts" fallback.
        layout = "percent"
        if template.get("measure") in {"avg_job", "customers"}:
            template = {**template, "chart_type": "grouped_bar", "labels": "value"}
            layout = "side_by_side"

        windows = []                               # (kind, date_from, date_to, range words)
        if period:
            windows.append(("period", base["date_from"], base["date_to"], where))
        thin = None
        if span and not (period and (base["date_from"], base["date_to"]) == tuple(span)):
            windows.append(("all", span[0], span[1], whole))
        elif not span and not period:
            windows.append(("all", spec.get("date_from"), spec.get("date_to"), whole))
        bars = [b for b in (self._drill_bar(kind, template, lo, hi, rng, group, word, audit, access)
                            for kind, lo, hi, rng in windows) if b is not None]
        if not bars:
            return {"drill": None}
        if period and bars[0]["kind"] == "period":
            need = self._min_jobs()
            n = bars[0]["jobs"]
            if n < need:
                thin = {"jobs": n, "min_jobs": need,
                        "text": f"{where}: " + ("no jobs" if n == 0 else f"only {n:,} job" + ("" if n == 1 else "s"))}
                keys = compute.period_keys(span[0], span[1], grain) if span else []
                if period in keys:
                    count = compute.window_counter(data, self.pack, spec, group, keys, grain)
                    win = compute.widen(len(keys), keys.index(period), count, need)
                    if win is not None:
                        lo, hi = compute.window_dates(keys, win[0], win[1], grain, span)
                        wide = self._drill_bar("widened", template, lo, hi, compute.range_label(lo, hi, grain),
                                               group, word, audit, access)
                        if wide is not None:
                            bars.insert(1, wide)
        self._same_colors(bars)
        first = bars[0]
        title = f"{group}, {first['range_label']} \u2014 by {word} \u00b7 {first['total_text']}" \
            if first["range_label"] else f"{group} \u2014 by {word} \u00b7 {first['total_text']}"
        first["spec"]["title"] = title[:90]
        audit["drill"] = first["spec"].get("split")
        audit["bars"] = len(bars)
        # under a comparison the only jobs to list are the person's own -- not "GT's jobs"
        can_list = "list_jobs" in access["ops"] and not acc.is_comparison(spec, access)
        prof = self._zip_profile(spec, group)            # a tapped ZIP shows what it's like
        return {"spec": first["spec"], "result": first["result"],
                "drill": {**info, "split": first["spec"].get("split"), "title": title, "unit": first["unit"],
                          "jobs": can_list, "bars": bars, "thin": thin, "layout": layout,
                          **({"profile": prof} if prof else {})},
                "meta": {"compute_ms": int((time.time() - started) * 1000),
                         "data_as_of": data.meta.get("data_as_of")}}

    def _zip_profile(self, spec, group):
        """the Census and fire-zone facts of a tapped ZIP on a ZIP map (public figures only)."""
        if self.zipfacts is None or spec.get("split") != "area" or not compute.map_by_zip(spec, self.pack):
            return None
        z = str(group)[:5]
        if not z.isdigit():
            return None
        try:
            from .understand import zipview
            p = zipview.profile_of(self.zipfacts.zip_facts(), self.pack, z)
        except Exception:
            return None
        if p:
            p["sources"] = self.zipfacts.sources()
        return p

    def _drill_bar(self, kind, template, lo, hi, rng, group, word, audit, access):
        """One 100% bar of the drill panel: the template's split over [lo, hi], access
        re-applied (_compute), labelled with its own range and totals."""
        import copy
        s = copy.deepcopy(template)
        s["date_from"], s["date_to"] = lo, hi
        s = normalize(s, self.pack)
        if validate(s, self.pack, self.writer.schema):
            return None
        s, result, _, _ = self._compute(s, audit, access)
        history, history_amount = compute.history_part(self._data(), self.pack, s)   # the restricted spec: exact
        s, result = self._present(s, result, {}, access)
        measure = s.get("measure")
        noun = compute.unit_noun(s, self.pack) if measure == "units" else None
        is_empty = bool(result.get("empty"))
        total = None if is_empty else (result.get("totals") or {}).get("value")
        jobs = 0 if is_empty else int((result.get("totals") or {}).get("jobs") or 0)
        text = "no matching jobs" if is_empty else compute.total_text(total, measure, noun or "units")
        parts = [(f"All {rng}" if kind == "all" else rng) or "All dates"]
        if kind == "widened" and measure != "jobs" and not is_empty:
            parts.append(compute.total_text(jobs, "jobs"))
        parts.append(text)
        note = compute.history_text(history, history_amount, measure, noun or "units")
        if note:
            parts.append(note)
        label = " \u00b7 ".join(parts)
        s["title"] = f"{group}, {label}"[:90]
        return {"kind": kind, "label": label, "range_label": rng, "date_from": s.get("date_from"),
                "date_to": s.get("date_to"), "total": total, "total_text": text, "jobs": jobs,
                "history_jobs": history, "history_amount": history_amount, "note": note, "measure": measure,
                "unit": noun or self.UNIT_WORD.get(measure), "spec": s, "result": result,
                "floor_key": compute.floor_key(s)}

    def _same_colors(self, bars):
        """Every bar wears the same colour per category, and lists them in one order
        (the whole range's, then any the others add; "All other" last)."""
        order = []
        for b in sorted(bars, key=lambda b: b["kind"] != "all"):
            for g in b["result"].get("groups") or []:
                if g not in order and g != compute.ALL_OTHER:
                    order.append(g)
        if any(compute.ALL_OTHER in (b["result"].get("groups") or []) for b in bars):
            order.append(compute.ALL_OTHER)
        colors = dict(zip(order, self.pack.color_for(order)))
        rank = {g: i for i, g in enumerate(order)}
        for b in bars:
            r = b["result"]
            if r.get("empty"):
                continue
            r["groups"] = sorted(r.get("groups") or [], key=lambda g: rank.get(g, len(rank)))
            r["group_totals"] = sorted(r.get("group_totals") or [], key=lambda g: rank.get(g["group"], len(rank)))
            r["rows"] = sorted(r.get("rows") or [], key=lambda row: (str(row[0]), rank.get(row[1], len(rank))))
            r["colors"] = {g: colors.get(g, c) for g, c in (r.get("colors") or {}).items()}

    def op_list_jobs(self, body, audit):
        """The jobs behind a chart piece: invoice number, date, town, products,
        amount. Needs the line-items right, like line-item search.
        with `period`, only that period of the chart (the same window the
        drill panel uses); outside the chart's dates it is empty, never widened."""
        access = body["_access"]
        if access.get("line_items", "none") == "none" and access.get("invoices", "none") == "none":
            raise OpError("forbidden")
        spec = normalize(body.get("spec"), self.pack)
        if validate(spec, self.pack, self.writer.schema):
            raise OpError("bad_request")
        period = self._period_arg(body, spec)
        grain = spec.get("time_grain", "none")
        group = self._group_arg(body)
        if group is not None:
            spec = compute.with_group(spec, group, self.pack)
            if spec is None:
                return {"jobs": [], "total": 0, "note": "These jobs can't be listed on their own."}
            spec = normalize(spec, self.pack)
            if validate(spec, self.pack, self.writer.schema):
                raise OpError("bad_request")
        if period is not None:
            audit["period"] = period
            spec, empty = compute.narrow_to_period(spec, period, grain)
            if empty:
                return {"jobs": [], "total": 0,
                        "note": f"No jobs in {compute.period_label(period, grain)} within this chart's dates."}
        spec, _ = acc.restrict_spec(spec, access, self.pack.not_recorded)
        spec = self._own_jobs(spec, access)
        limit = max(1, min(int(body.get("limit", 100) or 100), 200))
        out = compute.list_jobs(self._data(), self.pack, spec, money=access["money"] and not acc.is_comparison(spec, access),
                                by_person=access["by_person"] or access["scope"] is not None, limit=limit)
        out["floor_key"] = compute.floor_key(spec, "jobs")
        audit["rows"] = len(out["jobs"])
        return out

    @staticmethod
    def _own_jobs(spec, access):
        """someone who may open only their own invoice cards (and has no
        line-items right) lists only their own jobs, whatever the chart covered."""
        if access.get("line_items", "none") == "none" and access.get("invoices") == "own":
            spec = {**spec, "filters": {**spec["filters"], "salespeople": [access["me"]]}}
        # a comparison chart is counts over everyone; the jobs under it are still only the person's own

        if acc.is_comparison(spec, access):
            mine = [access["me"]] if access.get("me") else list(access.get("scope") or [])
            spec = {**spec, "filters": {**spec["filters"], "salespeople": mine, "exclude_salespeople": []}}
        return spec

    # ------------------------------------------------------------ invoice card
    def _card(self, body, access):
        """(card, ctx) for the invoice or estimate in the body, checked against this
        person's right to open it. A person who may open only their own jobs is told
        "forbidden" for a job that isn't theirs AND for one that doesn't exist, so the
        card can't be used to learn which invoice numbers exist."""
        kind = body.get("kind", "invoice")
        if kind not in ("invoice", "estimate"):
            raise OpError("bad_request")
        if compute.norm_no(body.get("invoice_no")) is None:
            raise OpError("bad_request")
        data = self._data()
        # who sold it is checked BEFORE the card is built, so a refusal costs
        # the same whether or not the number exists
        row = (compute.invoice_row if kind == "invoice" else compute.estimate_row)(data, body.get("invoice_no"))
        if row is None:
            raise OpError("not_found" if access.get("invoices") == "all" else "forbidden")
        from .pack import UNKNOWN
        sold_by = (row.get("salesperson") if isinstance(row.get("salesperson"), str) else "") or UNKNOWN
        if not acc.may_open(access, sold_by):
            raise OpError("forbidden")
        own = access["me"] if access.get("invoices") == "own" else None
        build = compute.invoice_card if kind == "invoice" else compute.estimate_card
        card, ctx = build(data, self.pack, body.get("invoice_no"), money=access["money"], rep=own)
        if card is None or not acc.may_open(access, ctx["rep"]):
            raise OpError("forbidden")
        if not access.get("by_person") and own is None:
            card["salesperson"] = None
        return card, ctx

    def _pivot_entry(self, t, spec, label, access, line_id=None, more=False):
        rs, _ = acc.restrict_spec(spec, access, self.pack.not_recorded)
        rs = self._own_jobs(rs, access) if t["kind"] != "chart" else rs
        view = {"chart": "chart", "jobs": "jobs", "estimates": "estimates"}[t["kind"]]
        return {"template": t["id"], "entity": t["entity"], "kind": t["kind"], "label": label,
                "line_id": line_id, "floor_key": compute.floor_key(rs, view), "spec": rs, "more": more}

    def _entries(self, offered, access, line_id=None):
        return [self._pivot_entry(t, sp, lb, access, line_id, more=i >= pivots_mod.PER_ENTITY)
                for i, (t, sp, lb) in enumerate(offered)]

    def op_invoice(self, body, audit):
        """An invoice (or estimate) card: its lines, totals, the customer's history by
        key, and the pivots each part of it offers. The website adds the name."""
        access = body["_access"]
        card, ctx = self._card(body, access)
        audit["kind"] = card["kind"]
        data = self._data()
        offers = {}
        for entity in ("customer", "area", "rep", "source", "date"):
            offers[entity] = self._entries(pivots_mod.offer(data, self.pack, ctx, access, self.pivots,
                                                            entity=entity), access)
        per_line, seen = {}, set()
        for line_id, ln in (ctx.get("lines") or {}).items():
            key = (ln.get("product"), ln.get("work"))
            if key in seen:
                continue
            seen.add(key)
            per_line[line_id] = self._entries(pivots_mod.offer(data, self.pack, ctx, access, self.pivots,
                                                               line_id=line_id, entity="line"), access, line_id)
        offers["line"] = per_line
        card["pivots"] = offers
        card["pivot_set"] = self.pivots_source
        card["floor_key"] = compute.floor_key(None, f"{card['kind']}:{card['invoice_no']}")
        audit["rows"] = len(card["lines"])
        return {"card": card, "meta": {"data_as_of": data.meta.get("data_as_of")}}

    LINE_ID = re.compile(r"^[A-Za-z0-9:_-]{1,40}$")

    def op_pivot(self, body, audit):
        """One pivot from a card: the template (one of the 40 in use), rebuilt here from
        the card's own context. The browser names the template; it never sends the spec."""
        access = body["_access"]
        tid = str(body.get("template") or "")
        t = pivots_mod.BY_ID.get(tid)
        if t is None or tid not in self.pivots:
            raise OpError("bad_request")
        line_id = body.get("line_id")
        if line_id is not None and not (isinstance(line_id, str) and self.LINE_ID.match(line_id)):
            raise OpError("bad_request")
        card, ctx = self._card(body, access)
        audit["template"] = tid
        data = self._data()
        offered = pivots_mod.offer(data, self.pack, ctx, access, [tid], line_id=line_id, entity=t["entity"])
        if not offered:
            raise OpError("bad_request")              # not on offer for this card, for this person
        _, spec, label = offered[0]
        started = time.time()
        if t["kind"] == "estimates":
            scope = access["scope"]
            if access.get("invoices") == "own":
                scope = [access["me"]]
            out = compute.list_estimates(data, ctx["customer"], money=access["money"], scope=scope,
                                         by_person=access["by_person"] or scope is not None)
            entry = self._pivot_entry(t, spec, label, access, line_id)
            audit["rows"] = len(out["jobs"])
            return {"kind": "estimates", "template": tid, "label": label, "spec": entry["spec"],
                    "floor_key": entry["floor_key"], "jobs": out["jobs"], "total": out["total"],
                    "meta": {"compute_ms": int((time.time() - started) * 1000),
                             "data_as_of": data.meta.get("data_as_of")}}
        spec = normalize(spec, self.pack)
        if validate(spec, self.pack, self.writer.schema):
            raise OpError("bad_request")
        if t["kind"] == "jobs":
            rs, notes = acc.restrict_spec(spec, access, self.pack.not_recorded)
            rs = self._own_jobs(rs, access)
            out = compute.list_jobs(data, self.pack, rs, money=access["money"] and not acc.is_comparison(rs, access),
                                    by_person=access["by_person"] or access["scope"] is not None, limit=100)
            audit["rows"] = len(out["jobs"])
            return {"kind": "jobs", "template": tid, "label": label, "spec": rs, "notes": notes,
                    "floor_key": compute.floor_key(rs, "jobs"), "jobs": out["jobs"], "total": out["total"],
                    "meta": {"compute_ms": int((time.time() - started) * 1000),
                             "data_as_of": data.meta.get("data_as_of")}}
        rs, result, _, as_of = self._compute(spec, audit, access)
        rs, result = self._present(rs, result, {}, access)
        return {"kind": "chart", "template": tid, "label": label, "spec": rs, "result": result,
                "floor_key": compute.floor_key(rs),
                "meta": {"compute_ms": int((time.time() - started) * 1000), "data_as_of": as_of}}

    def op_ask(self, body, audit):
        if not self.pack.configured:
            raise OpError("not_configured")
        question = str(body.get("question", "")).strip()
        if not question or len(question) > 500:
            raise OpError("bad_request")
        audit["question"] = question
        today = _today(body)
        user = str(body.get("user", ""))
        if not self.limiter.allow(user):
            raise OpError("rate_limited")
        if self.prospect_cells is not None:      # "where should I hang chimney-cap hangers near Cedar Hills?"
            out = self._ask_prospect(body, audit, question)
            if out is not None:
                return out
        out = self._ask_territories(body, audit, question)   # "what are my territories?"
        if out is not None:
            return out
        if self.terms is not None and self.reader_mode == "on":
            out = self._ask_reader(body, audit, question, today, user)
            if out is not None:
                return out
        prev = body.get("previous_spec")
        # the chart on screen goes to the question reader only when the
        # question is a follow-up to it ("now by month", "as a pie"). A new question
        # starts clean, so an old chart's title, filters and clarify text can't leak in

        if prev is not None and not is_followup(str(body.get("question") or "")):
            audit["followup"] = False
            prev = None
        prev_customers = []
        if prev is not None:
            prev = normalize(prev, self.pack)
            # The customer is put back after the model, here, for the follow-up ("now by
            # month") to stay about them.
            prev_customers = list((prev.get("filters") or {}).get("customers") or [])
            if prev_customers:
                prev["filters"]["customers"] = []
                prev["title"] = ""
            blank = compute.base_spec(self.pack)
            for k in ("clarify_question", "clarify_options", "assumptions"):
                if k in blank:
                    prev[k] = blank[k]
                else:
                    prev.pop(k, None)
            if validate(prev, self.pack, self.writer.schema):
                prev = None
        # Zero trust: no account name reaches a model. Names and nicknames in
        # the question, and account keys/labels in the previous spec, become
        # ACCOUNT_n placeholders; the mapping stays here.
        masked, mapping = self.accounts.mask(question)
        if prev is not None:
            prev, mapping = self.accounts.mask_spec(prev, mapping)
        # partner businesses too. A listed partner
        # typed in the question, and the partners/title/notes of the previous spec,
        # become "Partner n"; the names are put back below (filters) and in _present.
        masked, pmap = partners_mod.mask(masked)
        if prev is not None:
            prev, pmap = partners_mod.mask_spec(prev, pmap)
        try:
            spec, meta = self.writer.write(masked, prev, today)
        except Busy:
            raise OpError("busy") from None
        except ModelUnavailable:
            raise OpError("model_unavailable") from None
        except InvalidSpec:
            self._miss("unreadable", user, masked, None, None)
            raise OpError("invalid_spec") from None
        audit.update(model=meta["model"], backup=meta["backup_model_used"], cached=meta["cached"],
                     llm_ms=meta["llm_ms"])
        spec = self._apply_names(masked, spec)
        spec = self._resolve_accounts(spec, mapping)
        spec = partners_mod.resolve(spec, pmap)
        # the filters a written chart keeps must be ones the question asks for (runs 20-21)
        from .understand import review as review_mod
        spec, vet_dropped = review_mod.vet_written(spec, masked, self.terms, prev,
                                                   compute.base_spec(self.pack).get("filters"))
        if vet_dropped:
            audit["vetted"] = vet_dropped[:8]
            self._miss("written_vetted", user, masked, None, [f"held: {', '.join(vet_dropped[:8])}"])
        spec.setdefault("filters", {})["customers"] = []      # a model never targets a customer
        if prev is not None and prev_customers:
            spec["filters"]["customers"] = prev_customers[:1]   # the follow-up stays about them
            t = str(spec.get("title") or "").strip()
            if not t.lower().startswith("this customer"):
                spec["title"] = ("This customer: " + t if t else "This customer")[:90]
        spec = self._apply_compare(masked, spec)
        if spec.get("intent") in {"chart", "number"}:
            spec["clarify_options"] = []            # choices only go with a question
        else:
            spec["clarify_options"] = [str(o).strip()[:160] for o in spec.get("clarify_options") or []
                                       if str(o).strip()][:3]
        status = spec["intent"] if spec["intent"] in {"chart", "number", "clarify", "unsupported"} else "invalid"
        spec, result, ms, as_of = self._compute(spec, audit, body["_access"])
        caption = ""
        if status in {"chart", "number"} and not result["empty"]:
            caption = self._caption(spec, result, mapping, body["_access"], pmap)
        elif result["empty"]:
            caption = "No matching jobs for these filters."
        try:
            self.misses.note_ask(user, masked, self._miss_spec(spec, mapping, pmap), status,
                                 0 if result.get("empty") else 1, result.get("notes"),
                                 is_followup(question))
            self._log_all(user, masked, spec, mapping, pmap, status, answered_by="model",
                          rows=len(result.get("rows") or []))
        except Exception:                 # the log must never break an answer
            pass
        if self.terms is not None and self.reader_mode == "shadow":
            self._shadow(body, question, today, user, spec)
        spec, result = self._present(spec, result, mapping, body["_access"], pmap)
        result["look"] = self._look(spec, result, None, None,
                                    "model" if spec.get("chart_type") not in (None, "", "auto") else "shape")
        if result["look"]:
            result["look"]["followup"] = prev is not None
        return {"spec": spec, "status": status, "result": result, "caption": caption,
                "floor_key": compute.floor_key(spec),
                "meta": {**meta, "compute_ms": ms, "data_as_of": as_of,
                         "caption_model": self.lm.caption_model if caption else None}}

    # ------------------------------------------------------------ the question reader
    def _reader_context(self, data, today):
        from .understand import read as read_mod
        as_of = data.meta.get("data_as_of")
        anchor = dt.date.fromisoformat(str(as_of)[:10]) if as_of else dt.date.fromisoformat(today)
        issued = data.work["issued"].dropna() if "issued" in data.work else None
        first = issued.min().date() if issued is not None and len(issued) else None
        terr = None
        if self.safety is not None:  # the sites' and councils' names, for "in Westbrook council"
            try:
                terr = {k: self.safety.names(k) for k in ("safety_site", "safety_council")}
            except Exception:
                terr = None
        return read_mod.Context(self.terms, anchor=anchor, today=dt.date.fromisoformat(today), first=first,
                                pack=self.pack, territories=terr)

    def _previous_v2(self, raw, ctx=None):
        """The chart on screen as a v2 spec: its own v2 copy, or read back from version 1.
        A customer on it is returned separately: it never goes into a reading."""
        from .understand import spec2 as S
        if not isinstance(raw, dict):
            return None, []
        customers = list(((raw.get("filters") or {}) if isinstance(raw.get("filters"), dict) else {})
                         .get("customers") or [])[:1]
        v2 = raw.get("v2") if isinstance(raw.get("v2"), dict) else None
        if v2 and isinstance(v2.get("spec"), dict):
            return {k: v for k, v in v2["spec"].items() if k in S.KEYS}, customers
        try:
            prev = normalize(raw, self.pack)
            if validate(prev, self.pack, self.writer.schema):
                return None, customers
        except Exception:
            return None, customers
        if raw.get("grain_detail") in GRAIN_DETAILS:  # a by-week chart sent back without its v2
            prev["grain_detail"] = raw["grain_detail"]
        cw = S.compare_from_public(raw.get("compare_windows"), prev)   # the comparison on screen
        plan = S.compare_plan(cw, prev)[0] if cw else None
        if cw and plan:
            prev["windows"], prev["window_as"] = cw["windows"], plan[0]
            prev["time_grain"] = plan[1]
            prev.pop("grain_detail", None)
        out = S.from_v1(prev, ctx.anchor if ctx else None, ctx.first if ctx else None)   # periods' names
        w = raw.get("weather")                       # the website sends the chart without its v2
        if isinstance(w, dict):                      # copy, but keeps "weather"
            from .understand import weatherview
            if not weatherview.check(w):
                out["weather"] = {k: v for k, v in weatherview.full(w).items() if v is not None}
        z = raw.get("zipfacts")                      # the same for the ZIP facts
        if isinstance(z, dict):
            from .understand import zipview
            z = zipview.clean(z)
            if z:
                out["zipfacts"] = z
        return out, customers

    def _ask_reader(self, body, audit, question, today, user):
        """Answer by rule. None = hand the question to the model path unchanged."""
        from .understand import caption as cap_mod, charttype, describe, execute, read as read_mod
        from .understand import spec2 as S
        data = self._data()
        access = body["_access"]
        started = time.time()
        followup = body.get("previous_spec") is not None and is_followup(question)
        ctx = self._reader_context(data, today)
        prev2, prev_customers = self._previous_v2(body.get("previous_spec"), ctx) if followup else (None, [])
        # Accounts become ACCOUNT_n before anything reads the question (the reader resolves
        # them back from the mapping); partner names are the pack's own words.
        masked, mapping = self.accounts.mask(question)
        reading = read_mod.read(masked, ctx, previous=prev2)
        logged_q, pmap_log = partners_mod.mask(masked)
        audit["reader"] = reading.status
        if reading.status == "partial" and self.review_mode == "on":
            spec2_, source = dict(reading.spec), "reader"     # the second look fills the gaps
        elif reading.status == "partial":
            merged = self._reader_with_model(reading, masked, body, today, audit, followup=prev2 is not None)
            if merged is None:
                return None
            spec2_, source = merged
        else:
            spec2_, source = reading.spec, "reader"
        anchor, first = ctx.anchor, ctx.first
        if reading.status == "refuse":
            spec2_ = {"intent": "unsupported", "clarify_question": reading.reason}
        if spec2_.get("intent") == "unsupported" and reading.reason and not spec2_.get("clarify_question"):
            spec2_["clarify_question"] = reading.reason
        problems = S.check(spec2_, self.pack, anchor, first)
        if problems:
            self._miss("reader_check", user, logged_q, None, problems[:4])
            if source == "reader" and reading.status == "complete":
                return None                       # never guess: the model path takes it
            spec2_ = reading.spec
            if S.check(spec2_, self.pack, anchor, first):
                return None
        # accounts: placeholders back to keys (unknown ones dropped)
        f = spec2_.setdefault("filters", {})
        if f.get("accounts"):
            f["accounts"] = [mapping[a] for a in f["accounts"] if a in mapping]
        extra_filters = {"customers": prev_customers} if prev_customers else {}
        runner = execute.Runner(data, self.pack, access, extra_filters, weather=self.weather)
        try:
            answer = execute.run(spec2_, runner, anchor, first)
        except execute.ExecuteError as e:
            spec2_ = {"intent": "clarify", "clarify_question": str(e)[:200]}
            answer = execute.run({"intent": "clarify", "clarify_question": str(e)[:200], "measure": "revenue"},
                                 runner, anchor, first)
        review_line = None
        worth = self._look_worth_it(reading, answer) and not answer.spec.get("weather") \
            and not answer.spec.get("zipfacts")      # nor a ZIP-facts one (rate, map, dots, profile)
        # a weather chart's
        # look is fixed by the weather view (a line on a second axis, dots, bands); the model has no weather words
        if self.review_mode == "on" and not body.get("_selfcheck") and answer.spec.get("intent", "chart") in ("chart", "number") \
                and reading.status != "refuse" and not worth:
            audit["review"] = "skipped"
            try:
                self._miss("review", user, partners_mod.mask(masked)[0], None, None, outcome="skipped",
                           reason=self._skip_reason(reading, answer), ms=0, reader=reading.status)
            except Exception:
                pass
        if self.review_mode == "on" and not body.get("_selfcheck") and answer.spec.get("intent", "chart") in ("chart", "number") \
                and reading.status != "refuse" and worth:
            answer, review_line, source = self._second_look(masked, reading, answer, runner, anchor, first,
                                                            audit, user, source)
        intent = answer.spec.get("intent", "chart")
        status = intent if intent in {"chart", "number", "clarify", "unsupported"} else "invalid"
        # the spec the website gets: version 1 (chips, drill, saved charts) + its v2 copy
        pub = S.to_public(answer.v1)
        if (answer.v1 or {}).get("time_grain") in GRAIN_DETAILS:     # the chips send it back
            pub["grain_detail"] = answer.v1["time_grain"]
        pub["intent"] = intent
        full_title = describe.title(answer, self.pack)
        wx = answer.result.get("weather") if isinstance(answer.result, dict) else None
        if answer.spec.get("weather"):
            from .understand import weatherview
            pub["weather"] = weatherview.full(answer.spec["weather"])          # chips and saved charts keep it
            if isinstance(wx, dict) and wx.get("view") == "only" and wx.get("status") == "ok":
                full_title = weatherview.only_title(answer, wx, self.pack)
        if answer.spec.get("zipfacts") and (self.zipfacts is not None or
                                            answer.spec.get("split") in compute.TERRITORY_SPLITS):
            from .understand import zipview                # drawn and captioned in _present / below
            z = zipview.clean(answer.spec["zipfacts"])
            if z:
                pub["zipfacts"] = z
        elif answer.spec.get("zipfacts"):                  # say so, never a plain map passed off as one
            answer.notes.append("ZIP facts (Census, hazard zones) are turned off on this hub, so this is the plain chart. "
                                "On the hub computer: set \"zipfacts\": {\"mode\": \"on\"} in the hub's settings.")
        pub["title"] = describe.short(full_title)
        assumptions = [a for a in reading.assumptions]
        if prev_customers:
            pub.setdefault("filters", {})["customers"] = prev_customers
            pub["title"] = ("This customer: " + pub["title"])[:90]
        pub["assumptions"] = [str(a)[:160] for a in (list(answer.v1.get("assumptions") or []) + assumptions)][:4]
        pub["clarify_question"] = (answer.spec.get("clarify_question") or None) if intent in ("clarify", "unsupported") else None
        pub["clarify_options"] = [str(o)[:160] for o in answer.spec.get("clarify_options") or []][:3] \
            if intent == "clarify" else []
        if answer.kind == "windows" and answer.windows:
            last = answer.windows[-1]
            pub["date_from"], pub["date_to"] = last.start.isoformat(), last.end.isoformat()
        cmp_ = S.public_compare(answer.kind, answer.windows, pub.get("date_from"), pub.get("date_to"))
        if cmp_:                                           # the page keeps the comparison through chip edits
            pub["compare_windows"] = cmp_
        look_by = reading.look_by or "shape"
        charttype.apply(answer, pub, look_by)
        asked = answer.spec.get("chart_type")
        if look_by in ("words", "rule", "model") and asked not in (None, "", "auto") \
                and answer.result.get("chart_type") not in (None, asked):
            look_by = "shape"     # compute couldn't draw what was asked; nobody chose this one
        pub["v2"] = {"spec": S.sparse(answer.spec),
                     "windows": {w.label: [w.start.isoformat(), w.end.isoformat()] for w in answer.windows}
                     if answer.kind in ("windows", "overlay") else {},
                     "kind": answer.kind, "source": source}
        result = answer.result
        result["notes"] = [n for n in answer.notes if n] + [n for n in result.get("notes", []) if n not in answer.notes]
        result["title_full"] = full_title
        result["read_as"] = describe.read_as(answer, reading.assumptions)
        if answer.spec.get("weather"):
            from .understand import weatherview
            line = weatherview.describe(result.get("weather"))
            if line:
                result["read_as"].insert(min(2, len(result["read_as"])), line)
        if review_line:
            result["read_as"].append(review_line)
        if reading.corrections:
            result["read_as"].append("Read " + ", ".join(f"\"{a}\" as \"{b}\"" for a, b in reading.corrections[:3]))
        ms = int((time.time() - started) * 1000)
        try:
            if body.get("_selfcheck"):
                raise StopIteration      # the self-check's questions aren't a person's
            self.misses.note_ask(user, logged_q, self._miss_spec({k: v for k, v in pub.items() if k != "v2"}, mapping,
                                                                pmap_log),
                                 status, 0 if result.get("empty") else 1, result.get("notes"), followup)
            self._log_all(user, logged_q, {k: v for k, v in pub.items()}, mapping, pmap_log, status,
                          answered_by=source, reader=reading.status, rows=len(result.get("rows") or []),
                          chart_kind=answer.kind)
            if reading.status == "partial":
                words = [w for w in reading.unread if w[:1].islower()][:8]
                self._miss("partly_read", user, logged_q, None, None, unread=words,
                           named=len([w for w in reading.unread if w[:1].isupper()]), answered_by=source)
        except (Exception, StopIteration):
            pass
        pub, result = self._present(pub, result, mapping, access, pmap_log)
        zf = result.get("zipfacts")
        if isinstance(zf, dict) and zf.get("view"):
            from .understand import zipview                # the title and "Read as" say what was divided
            t = zipview.title(zf, pub.get("title"), pub.get("measure"))
            if t:
                pub["title"] = describe.short(t)
                result["title_full"] = t + (" — " + full_title.split(" — ", 1)[1] if " — " in full_title else "")
            line = zipview.describe(zf)
            if line:
                result["read_as"].insert(min(2, len(result["read_as"])), line)
        result["look"] = self._look(pub, result, answer.kind, answer.spec, look_by,
                                    charttype.choose(answer)[0] if not result.get("empty") else "table")
        if result["look"]:
            result["look"]["followup"] = prev2 is not None     # the page's "previous chart" boost
        caption = ""
        caption_model = None
        if status in {"chart", "number"} and not result.get("empty"):
            answer.result = result
            caption = cap_mod.template(answer, self.pack)
            if self.caption_mode == "model" and not body.get("_selfcheck"):
                text, why = self._model_caption(answer)
                if text:
                    caption, caption_model = text, self.lm.caption_model
                elif why:
                    audit["caption_fallback"] = why[:80]
        elif result.get("empty") and not ((result.get("weather") or {}).get("view") == "only"):
            caption = "No matching jobs for these filters."
        elif status in ("clarify", "unsupported"):
            caption = ""
        wx = result.get("weather")
        if isinstance(wx, dict) and wx.get("status") == "ok" and status in {"chart", "number"}:
            from .understand import weatherview            # every number here is NOAA's or the hub's own
            if wx.get("view") == "only":
                caption = weatherview.only_sentence(wx, answer.spec.get("date_part") or answer.spec.get("grain")).rstrip(".") + "."
                result["notes"] = [n for n in result.get("notes") or [] if n != "No matching jobs for these filters."]
            else:
                noun = {"month": "month", "week": "week", "day": "day", "year": "year", "quarter": "quarter"}.get(
                    answer.spec.get("grain"), "period")
                more = weatherview.sentences(wx, (pub.get("measure") or answer.spec.get("measure")), noun)
                if more:
                    caption = (caption.rstrip() + " " if caption else "") + " ".join(t.rstrip(".") + "." for t in more)
        zf = result.get("zipfacts")
        if isinstance(zf, dict) and zf.get("status") == "ok" and (zf.get("rate") or {}).get("none_rated"):
            from .understand import zipview                # jobs, but no ZIP to rate them by
            caption = zipview.caption(zf, result, pub.get("measure"), "")
        elif isinstance(zf, dict) and zf.get("status") == "ok" and status in {"chart", "number"} and not result.get("empty"):
            from .understand import zipview                # the Census's and the hub's own numbers only
            caption = zipview.caption(zf, result, pub.get("measure") or answer.spec.get("measure"), caption)
        elif isinstance(zf, dict) and zf.get("status") == "ok" and zf.get("profile") and result.get("empty"):
            from .understand import zipview                # a ZIP with no jobs yet still has its profile
            caption = zipview.caption(zf, result, pub.get("measure") or answer.spec.get("measure"), "").rstrip(".") + \
                ". No jobs there for these filters yet."
        audit.update(model=source, backup=False, cached=False, llm_ms=audit.get("llm_ms", 0))
        return {"spec": pub, "status": status, "result": result, "caption": caption,
                "floor_key": compute.floor_key({k: v for k, v in pub.items() if k != "v2"}),
                "meta": {"model": source, "backup_model_used": False, "cached": False,
                         "llm_ms": audit.get("llm_ms", 0), "reader": reading.status,
                         "compute_ms": ms, "data_as_of": data.meta.get("data_as_of"),
                         "caption_model": caption_model}}

    def _log_all(self, user, masked_question, spec, mapping, pmap, status, **extra):
        """while `log_all_until` (a date) hasn't passed, every question goes to the
        misses log as "asked": masked like every other entry, no figures."""
        until = str(self.cfg.get("log_all_until") or "")
        try:
            if not until or dt.date.today() > dt.date.fromisoformat(until):
                return
        except ValueError:
            return
        try:
            self.misses.write("asked", user, masked_question, self._miss_spec(spec, mapping, pmap), None,
                              status=status, **extra)
        except Exception:
            pass

    def _look_worth_it(self, reading, answer):
        """the second look runs where it can still change what the person sees. It still runs for a question the
        rules only partly read (the model fills the gaps), when how many groups to show is a judgement ("All
        other", or more than 10), and when a time run has several groups and nobody named the chart (stacked,
        lines or side by side)."""
        if self.review_scope == "all":
            return True
        if reading.status == "partial":
            return True
        r = answer.result if isinstance(answer.result, dict) else {}
        if r.get("empty"):
            return False
        groups = [g for g in (r.get("groups") or []) if g != "All jobs"]
        periods = r.get("periods") or []
        if compute.ALL_OTHER in groups or len(groups) > 10:
            return True
        named = answer.spec.get("chart_type") not in (None, "", "auto")
        return bool(not named and len(periods) > 1 and len(groups) >= 2)

    def _skip_reason(self, reading, answer):
        r = answer.result if isinstance(answer.result, dict) else {}
        if r.get("empty"):
            return "empty"
        if answer.spec.get("chart_type") not in (None, "", "auto"):
            return "type_chosen"
        groups = [g for g in (r.get("groups") or []) if g != "All jobs"]
        return "one_group" if len(groups) <= 1 else "page_decides"

    def _second_look(self, masked, reading, answer, runner, anchor, first, audit, user, source):
        """Returns (answer, read_as line, source)."""
        from .understand import charttype, execute, review as rv
        from .understand import spec2 as S
        if self._review_system is None:
            self._review_system = rv.system_prompt(self.pack)
            self._review_schema = rv.schema_for(self.pack)
        named = answer.spec.get("chart_type")
        chosen = named if named not in (None, "", "auto") else charttype.choose(answer)[0]
        user_msg, _ = partners_mod.mask(rv.payload(masked, reading, answer, chosen))
        info = {}
        rev, why, ms = rv.review(self.lm, self.lm.spec_model, self._review_system, user_msg,
                                 self.review_seconds, slot=self.lm.slot,
                                 structured=self.review_format != "text", max_tokens=self.review_max_tokens,
                                 no_think=self.review_no_think, info=info,
                                 schema=getattr(self, "_review_schema", None))
        audit["review_ms"] = ms
        logged_q = partners_mod.mask(masked)[0]
        partial = reading.status == "partial"
        if rev is None:
            audit["review"] = why[:40]
            if partial:
                words = ", ".join(w for w in reading.unread if w[:1].islower())[:80]
                if words:
                    reading.assumptions = reading.assumptions + [f"Not used: {words}"]
            self._miss("review", user, logged_q, None, None, outcome="no review", reason=why[:80], ms=ms,
                       finish=info.get("finish"), tokens=info.get("tokens"), format=info.get("format"),
                       reader=reading.status)
            return answer, None, source
        new_spec, applied, suggested = rv.merge(answer.spec, rev, reading, partial, answer)
        dropped = []
        if applied and S.check(new_spec, self.pack, anchor, first):
            # keep what checks, drop only the field that doesn't (was: lose the whole look)
            new_spec, applied, suggested, dropped = rv.merge_checked(
                answer.spec, rev, reading, partial, answer,
                lambda sp: not S.check(sp, self.pack, anchor, first), self.terms)
        outcome = "kept"
        if applied and not S.check(new_spec, self.pack, anchor, first):
            try:
                old_notes = list(answer.notes)
                answer = execute.run(new_spec, runner, anchor, first)
                merged = answer.notes + [n for n in old_notes if n not in answer.notes]
                # a note said twice (the re-run's shorter "Every year counts…") keeps the longer one
                answer.notes = [n for n in merged if not any(o != n and o.startswith(n) for o in merged)]
                outcome = "applied"
                source = source + "+review"
                if "chart_type" in applied and reading.look_by in ("", "previous"):
                    reading.look_by = "model"         # the page gives the model's look +15
            except execute.ExecuteError:
                applied, outcome = [], "could not run"
        elif applied:
            applied, outcome = [], "failed checks"
        if partial and "filters" not in applied and "measure" not in applied:
            words = ", ".join(w for w in reading.unread if w[:1].islower())[:80]
            if words and outcome != "applied":
                reading.assumptions = reading.assumptions + [f"Not used: {words}"]
        self._miss("review", user, logged_q, None, None, outcome=outcome, verdict=rev["verdict"],
                   applied=applied, suggested=suggested, look=rev["look"] or (rev.get("said") or {}).get("look", {}),
                   kept_with_look=bool(rev.get("said")),
                   reading={k: v for k, v in rev["reading"].items() if k != "filters"},
                   why=rev["why"], ms=ms, reader=reading.status,
                   tokens=info.get("tokens"), format=info.get("format"), dropped=dropped)
        audit["review"] = outcome
        line = None
        if applied:
            # a long reason cut the "(chart_type, …)" list off; shorten the reason
            keys = f" ({', '.join(applied)})"
            why = rev["why"] or "chart adjusted"
            room = 200 - len("Second look: ") - len(keys)
            if len(why) > room:
                why = why[:max(room - 1, 20)].rstrip() + "…"
            line = "Second look: " + why + keys
        return answer, line if line else None, source

    def _reader_with_model(self, reading, masked, body, today, audit, followup=False):
        """None when the model can't be reached AND the reader's own guess isn't worth showing (nothing placed
        at all)."""
        from .understand import spec2 as S
        m_masked, pmap = partners_mod.mask(masked)
        try:
            spec, meta = self.writer.write(m_masked, None, today)
            audit.update(llm_ms=meta.get("llm_ms", 0), cached=meta.get("cached"))
        except (Busy, ModelUnavailable, InvalidSpec):
            if not reading.locked:
                return None
            words = ", ".join(w for w in reading.unread if w[:1].islower())[:80]
            if words:
                reading.assumptions = reading.assumptions + [f"Not used: {words}"]
            return dict(reading.spec), "reader"
        spec = partners_mod.resolve(spec, pmap)
        model2 = S.from_v1(spec)
        for k in ("intent", "clarify_question", "clarify_options"):
            if spec.get(k):
                model2[k] = spec[k]
        merged = dict(model2)
        for k in reading.locked:
            if k in reading.spec:
                merged[k] = reading.spec[k]
            else:
                merged.pop(k, None)
        if followup:                      # the model didn't see the chart on screen: keep its parts
            for k, v in reading.spec.items():
                if k in reading.locked:
                    continue
                if k == "filters":
                    merged["filters"] = {**(model2.get("filters") or {}), **v}
                elif k == "periods" or k not in model2:
                    merged[k] = v
        for k in ("agg", "rank", "series", "merge", "share", "top_n", "date_part", "overlay", "to_date_cut"):
            if k in reading.spec and k not in merged:
                merged[k] = reading.spec[k]
        return merged, "reader+model"

    def _model_caption(self, answer):
        """caption_mode "model": the caption model words the facts; names masked first."""
        from .llm import CAPTION_SAMPLING
        from .understand import caption as cap_mod
        def mask(t):
            a, amap = self.accounts.mask(t)
            b, pmap = partners_mod.mask(a)
            return b, (amap, pmap)

        def unmask(t, state):
            amap, pmap = state
            return self.accounts.unmask(partners_mod.unmask(t, pmap), amap, True)
        return cap_mod.model_caption(self.lm, self.lm.caption_model, answer, self.pack, CAPTION_SAMPLING,
                                     mask=mask, unmask=unmask)

    def _shadow(self, body, question, today, user, model_spec):
        """reader_mode "shadow": read the question by rule too, and log whether the two
        readings agree on what matters (no answer changes)."""
        try:
            from .understand import execute
            data = self._data()
            masked, mapping = self.accounts.mask(question)
            ctx = self._reader_context(data, today)
            from .understand import read as read_mod
            reading = read_mod.read(masked, ctx)
            if reading.status == "refuse":
                return
            a = execute.run(reading.spec, execute.Runner(data, self.pack, body["_access"]), ctx.anchor, ctx.first)
            mine = a.v1
            same = {k: mine.get(k) == model_spec.get(k) for k in ("measure", "split", "time_grain", "date_from",
                                                                  "date_to")}
            same["filters"] = {k: v for k, v in (mine.get("filters") or {}).items() if v} == \
                {k: v for k, v in (model_spec.get("filters") or {}).items() if v and k != "customers"}
            if not all(same.values()):
                logged_q, _ = partners_mod.mask(masked)
                self._miss("shadow_differs", user, logged_q, None, None, reader=reading.status,
                           differs=[k for k, v in same.items() if not v])
        except Exception:
            pass

    # ------------------------------------------------------------ misses
    def _miss_spec(self, spec, mapping=None, pmap=None):
        """What Charts understood, with named accounts as ACCOUNT_n again. never
        a customer's key. partners as "Partner n" again."""
        if not isinstance(spec, dict):
            return None
        if isinstance(spec.get("filters"), dict) and spec["filters"].get("customers"):
            spec = {**spec, "filters": {**spec["filters"], "customers": ["one customer"]}, "title": ""}
        masked, mapping = self.accounts.mask_spec(spec, mapping)
        masked["assumptions"] = [self.accounts.mask(str(a), mapping)[0]
                                 for a in masked.get("assumptions") or []]
        return partners_mod.mask_spec(masked, pmap)[0]

    def _miss(self, kind, user, question, spec, notes, **extra):
        try:
            self.misses.write(kind, user, question, spec, notes, **extra)
        except Exception:
            pass

    def op_flag(self, body, audit):
        """"Not right" on an answer: the question, what Charts understood, and the person's note, written to the
        misses log on this computer. Nothing else."""
        question = str(body.get("question") or "").strip()[:500]
        note = str(body.get("note") or "").strip()[:500]
        if not question and not note:
            raise OpError("bad_request")
        user = str(body.get("user", ""))
        if not self.limiter.allow(user):
            raise OpError("rate_limited")
        masked, mapping = self.accounts.mask(question)
        note_m, mapping = self.accounts.mask(note, mapping)
        masked, pmap = partners_mod.mask(masked)                 
        note_m, pmap = partners_mod.mask(note_m, pmap)
        spec = body.get("spec")
        # a malformed spec ({"filters": "x"}) raised inside the
        # masking and came back as an internal error; the report is still noted, without it.
        try:
            spec = self._miss_spec(spec, mapping, pmap) if isinstance(spec, dict) else None
            if spec is not None:
                json.dumps(misses_mod.spec_summary(spec))
        except Exception:
            spec = None
        self.misses.write("flagged", user, masked, spec, None, note=note_m)
        audit["flagged"] = True
        return {"noted": True}

    # ------------------------------------------------------------ accounts
    def _resolve_accounts(self, spec, mapping):
        """Model placeholders → account keys. Unknown placeholders are dropped."""
        f = spec.setdefault("filters", {})
        f["accounts"] = [mapping[p] for p in f.get("accounts") or [] if p in mapping]
        return spec

    def _placeholders_for(self, result, mapping):
        """Give every account key in a result a placeholder, for the caption model."""
        mapping = dict(mapping)
        back = {k: p for p, k in mapping.items()}
        for g in result.get("groups", []):
            if g in self.accounts.by_key and g not in back and len(mapping) < 9:
                p = f"ACCOUNT_{len(mapping) + 1}"
                mapping[p], back[g] = g, p
        return mapping

    @staticmethod
    def _relabel(result, fn):
        """Rename account-key groups everywhere they appear in a result."""
        import copy
        r = copy.deepcopy(result)
        r["groups"] = [fn(g) for g in r.get("groups", [])]
        for row in r.get("rows", []):
            if len(row) > 1 and isinstance(row[1], str):
                row[1] = fn(row[1])
        r["colors"] = {fn(k): v for k, v in (r.get("colors") or {}).items()}
        for g in r.get("group_totals", []):
            g["group"] = fn(g["group"])
        for f in r.get("facets", []):
            f["name"] = fn(f["name"])
            f["result"] = Hub._relabel(f["result"], fn)
        return r

    def _caption(self, spec, result, mapping, access, pmap=None):
        mapping = self._placeholders_for(result, mapping)
        back = {k: p for p, k in mapping.items()}
        # every listed partner among the chart's labels (groups, facets)
        # reaches the caption model as "Partner n"; the caption gets the names back.
        pmap, listed, seen = dict(pmap or {}), partners_mod.listed(), []
        self._relabel(result, lambda g: seen.append(g) or g)
        pback = {v: k for k, v in pmap.items() if v is not None}
        for g in seen:
            if g in listed and g not in pback:
                ph = partners_mod.next_placeholder(pmap)
                if ph:
                    pback[g], pmap[ph] = ph, g
        masked_result = self._relabel(
            result, lambda g: back.get(g, pback.get(g, partners_mod.HIDDEN if g in listed else g)))
        masked_spec, mapping = self.accounts.mask_spec(spec, mapping)
        masked_spec, pmap = partners_mod.mask_spec(masked_spec, pmap)
        if (masked_spec.get("filters") or {}).get("customers"):
            masked_spec["filters"]["customers"] = []       # no key reaches a model
            masked_spec["title"] = "One customer's jobs"
        text = self.writer.caption(masked_spec, masked_result)
        return partners_mod.unmask(self.accounts.unmask(text, mapping, access.get("accounts")), pmap)

    def _present(self, spec, result, mapping, access, pmap=None):
        """What a person sees: labels for callers allowed to see accounts, a
        neutral word for everyone else. Keys stay keys in the spec (chips).
        partners the question reader saw as "Partner n" get their names back."""
        show = bool(access.get("accounts"))
        by_key = self.accounts.by_key
        numbered = {}

        def label(g):
            if g not in by_key:
                return g
            if show:
                return by_key[g].label
            numbered.setdefault(g, f"Named account {len(numbered) + 1}")
            return numbered[g]
        result = self._relabel(result, label)
        for k in ("title", "clarify_question"):
            if isinstance(spec.get(k), str):
                spec[k] = self.accounts.unmask(spec[k], mapping, show)
        spec["assumptions"] = [self.accounts.unmask(a, mapping, show) for a in spec.get("assumptions") or []]
        spec["clarify_options"] = [self.accounts.unmask(o, mapping, show) for o in spec.get("clarify_options") or []]
        if pmap is not None:
            for k in ("title", "clarify_question"):
                if isinstance(spec.get(k), str):
                    spec[k] = partners_mod.unmask(spec[k], pmap)
            for k in ("assumptions", "clarify_options"):
                spec[k] = [partners_mod.unmask(a, pmap) for a in spec.get(k) or []]
        self._add_places(spec, result)
        territory = spec.get("split") in compute.TERRITORY_SPLITS
        if territory and isinstance(result, dict):
            try:                                          # the territories' outlines, a rate by their homes
                from .understand import zipview
                self._add_territories(spec, result)
                kind = spec["split"]
                homes = self.safety.homes(kind) if self.safety is not None else {}
                zn = zipview.apply_territory(spec, result, homes, "safety site" if kind == "safety_site"
                                             else "safety council")
                if zn:
                    result["notes"] = list(result.get("notes") or []) + [n for n in zn if n not in (result.get("notes") or [])]
            except Exception as e:
                _log.warning("territories not attached: %s", type(e).__name__)
        if spec.get("split") == "tax_area" and isinstance(result, dict):
            try:                                          # the tax areas' outlines
                self._add_tax_areas(spec, result)
            except Exception as e:
                _log.warning("tax areas not attached: %s", type(e).__name__)
        if self.zipfacts is not None and isinstance(result, dict) and not territory and \
                spec.get("split") not in compute.PLACE_SPLITS:
            try:                                          # the ZIP facts beside a chart by ZIP
                from .understand import zipview
                zn = zipview.apply(spec, result, self.pack, self.zipfacts)
                if zn:
                    result["notes"] = list(result.get("notes") or []) + [n for n in zn if n not in (result.get("notes") or [])]
            except Exception as e:                        # never break an answer over the facts
                _log.warning("zip facts not attached: %s", type(e).__name__)
        if show:
            result["account_labels"] = {k: by_key[k].label for k in spec["filters"].get("accounts") or [] if k in by_key}
        return spec, result

    @staticmethod
    def _look(spec, result, kind, v2, by, shape_pick=None):
        """every chart that could draw this answer, scored by its shape (understand.looks).
        Never allowed to break an answer."""
        from .understand import looks
        try:
            return looks.build(spec, result, kind, v2, by, shape_pick)
        except Exception:
            return None

    def _add_territories(self, spec, result):
        """a map by safety-site site / safety council carries the territories' outlines (public data: the
        listing body points, region polygons, outlines traced from the pack). The page draws them like ZIP
        areas."""
        if self.safety is None or result.get("chart_type") != "map" or result.get("empty"):
            return
        kind = spec.get("split")
        fc = self.safety.shapes(kind)
        if not fc.get("features"):
            result.setdefault("notes", []).append(
                "The safety areas haven't been read yet; the hub reads them "
                f"{P.when_text(self.cfg)} (on the hub computer now: zorderz_hub safety update). Shown as bars meanwhile.")
            result["chart_type"] = "grouped_bar"
            return
        have = {ft["properties"]["z"] for ft in fc["features"]}
        result["zips"] = {g: g for g in result.get("groups") or [] if g in have}
        result["shapes"] = fc
        result["area_word"] = "safety site" if kind == "safety_site" else "safety council"

    def _add_tax_areas(self, spec, result):
        """a map by sales tax area carries the areas' outlines (the state tax agency's public boundaries), drawn
        like ZIPs."""
        if result.get("chart_type") != "map" or result.get("empty"):
            return
        areas = self.places.tax() if self.places is not None else []
        if not areas:
            result.setdefault("notes", []).append(
                "The sales tax areas haven't been read yet; the hub reads them "
                f"{P.when_text(self.cfg)} (on the hub computer now: zorderz_hub places update). Shown as bars meanwhile.")
            result["chart_type"] = "grouped_bar"
            return
        from .safety import simplify
        feats = [{"type": "Feature", "properties": {"z": compute.tax_label(a["name"], a["rate"]), "n": ""},
                  "geometry": {"type": "Polygon", "coordinates": simplify(a["rings"], 0.0004)}} for a in areas]
        have = {ft["properties"]["z"] for ft in feats}
        result["zips"] = {g: g for g in result.get("groups") or [] if g in have}
        result["shapes"] = {"type": "FeatureCollection", "features": feats}
        result["area_word"] = "tax area"

    def _add_places(self, spec, result):
        """a chart split by town carries each town's centre, so the page can draw it on
        a map. Only the pack's own coordinates, only for towns in the chart; nothing per job."""
        if not isinstance(result, dict) or spec.get("split") != "area" or not self.pack.places:
            return
        by = {k.casefold(): v for k, v in self.pack.places.items()}
        for r in [result] + [f.get("result") for f in result.get("facets") or [] if isinstance(f, dict)]:
            if not isinstance(r, dict):
                continue                         # "one chart per": each small chart gets its towns
            places = {g: list(by[g.casefold()]) for g in r.get("groups") or []
                      if isinstance(g, str) and g.casefold() in by}
            if places:
                r["places"] = places

    COMPARE = re.compile(r"\b(?:vs\.?|versus|compar\w*|against|side by side|each)\b", re.I)

    def _apply_compare(self, question, spec):
        """Safety net: "X compared to Y" must show X and Y separately. If the model
        filtered two or more of something but left split "none", split by it."""
        if spec.get("intent") not in {"chart", "number"} or spec.get("chart_type") == "venn":
            return spec
        if not self.COMPARE.search(question or ""):
            return spec
        f = spec.get("filters") or {}
        taken = spec.get("split", "none")
        for key, split in [("products", "product"), ("salespeople", "salesperson"),
                           ("accounts", "account"), ("areas", "area"), ("work_types", "work_type")]:
            if len(f.get(key) or []) >= 2 and split != taken:
                if taken != "none":
                    # Split already used for something else: one small chart each.
                    if spec.get("facet", "none") == "none":
                        spec["facet"] = split
                        notes = spec.setdefault("assumptions", [])
                        if len(notes) < 4:
                            notes.append("One small chart each so they can be compared")
                    break
                spec["split"] = split
                if spec.get("intent") == "number":
                    spec["intent"] = "chart"
                if spec.get("chart_type") == "single_number":
                    spec["chart_type"] = "auto"
                notes = spec.setdefault("assumptions", [])
                if len(notes) < 4:
                    notes.append("Shown separately so they can be compared")
                break
        return spec

    def _apply_names(self, question, spec):
        """Safety net for people named in the question (pack salesperson_names).
        The model is told the names; if it still wrote a chart with no salesperson
        filter, add one and say so as an assumption. Runs BEFORE the access check
        in _compute, so someone without per-salesperson rights still gets the
        combined figures and the usual note: naming a person grants nothing."""
        if spec.get("intent") not in {"chart", "number"}:
            return spec
        named = self.pack.names_in(question)
        if not named:
            return spec
        filters = spec.setdefault("filters", {})
        if filters.get("salespeople"):
            return spec
        filters["salespeople"] = [i for _, i in named]
        notes = spec.setdefault("assumptions", [])
        text = ", ".join(f"{n} = {i}" for n, i in named) + " (from the names list)"
        if text not in notes and len(notes) < 4:
            notes.append(text[:160])
        return spec

    def op_search_lines(self, body, audit):
        data = self._data()
        text = str(body.get("text", "")).strip()[:200]
        if not text:
            raise OpError("bad_request")
        limit = max(1, min(int(body.get("limit", 20) or 20), self.cfg["limits"]["search_limit"]))
        spec = normalize({"filters": body.get("filters") or {}}, self.pack)
        for k in ("date_from", "date_to"):
            if body.get(k):
                spec[k] = body[k]
        if validate(spec, self.pack, self.writer.schema):
            raise OpError("bad_request")
        access = body["_access"]
        spec, _ = acc.restrict_spec(spec, access, self.pack.not_recorded)
        comparison = acc.is_comparison(spec, access)  # judged on the ask, before the lines are kept to their own
        spec = self._own_jobs(spec, access)           # never another salesperson's lines
        money = access["money"] and not comparison
        rows = compute.select(data, self.pack, spec)
        ids = set(rows["line_id"])
        lines = data.work[data.work["line_id"].isin(ids)]
        words = [w for w in re.split(r"\s+", text.casefold()) if w]
        hay = lines["description_clean"].str.casefold()
        mask = hay.map(lambda h: all(w in h for w in words))
        hits = lines[mask].sort_values("issued", ascending=False).head(limit)
        audit["rows"] = int(len(hits))
        return {"lines": [{
            "text": r.description_clean, "family": r.family,
            "tags": [t for t in str(r.tags).split("; ") if t],
            "date": r.issued.strftime("%Y-%m-%d") if hasattr(r.issued, "strftime") else None,
            "town": r.area, "invoice_no": r.invoice_no,
            "dollars": None if not money or r.line_cents is None or str(r.line_cents) == "<NA>"
            else round(int(r.line_cents) / 100, 2),
        } for r in hits.itertuples()], "total_matches": int(mask.sum()), "method": "keyword"}

    def op_put_counts(self, body, audit):
        """The website hands over measured counts for one product: invoice
        number -> count, the whole set each time. Numbers only; a malformed
        payload is refused whole."""
        product = body.get("product")
        if product not in self.pack.products or body.get("complete") is not True:
            raise OpError("bad_request")
        received = _today(body)
        try:
            counts = store.save_measured(self.cfg["store"], product, body.get("counts"), received)
        except store.StoreError:
            raise OpError("bad_request") from None
        # the website's set used to REPLACE the measured counts in memory,
        # dropping the ones the import reads from measurement notes and invoice unit lists (a product's
        # units fell until the next restart). Load again: the import's counts, then the
        # website's on top, exactly as at start-up.
        self.reload()
        with self.data_lock:
            data = self.data
            known = set(data.invoices["invoice_no"]) if data is not None else set()
        matched = sum(1 for k in counts if k in known)
        audit.update(product=product, counts=len(counts), matched=matched)
        return {"product": product, "stored": len(counts), "matched": matched}

    def op_put_sources(self, body, audit):
        """The website hands over each invoice's lead source (labels only, the
        whole set each time). A malformed payload is refused whole."""
        received = _today(body)
        try:
            if body.get("source_names") is not None:   # Nutshell source numbers → names
                store.save_source_names(self.cfg["store"], body.get("source_names"), received)
            if body.get("batch") is not None:            # a big set comes in parts
                sources = store.stage_sources(self.cfg["store"], body.get("batch"), body.get("part"),
                                              body.get("parts"), body.get("sources"), received,
                                              lead_names=body.get("lead_names"))      
                if sources is None:
                    audit.update(part=body.get("part"), parts=body.get("parts"))
                    return {"stored": 0, "matched": 0, "waiting": True}
            elif body.get("complete") is True:
                store.clean_sources(body.get("sources"))           # refuse whole before writing anything
                if body.get("lead_names") is not None:                              
                    store.save_web_lead_links(self.cfg["store"], body.get("lead_names"), received)
                sources = store.save_sources(self.cfg["store"], body.get("sources"), received)
            else:
                raise OpError("bad_request")
        except store.StoreError:
            raise OpError("bad_request") from None
        self.reload()
        data = self.data
        known = set(data.invoices["invoice_no"]) if data is not None else set()
        matched = sum(1 for k in sources if k in known)
        audit.update(sources=len(sources), matched=matched)
        return {"stored": len(sources), "matched": matched}

    def op_put_invoices(self, body, audit):
        """the website's hourly invoice feed (new or changed FreshBooks
        invoices with their lines), in parts of one batch. Folded into the feed once
        the last part is in; in "live" mode the feed is then written beside the
        customer list as an export the importer reads (see feed.py). A malformed
        part is refused whole. Nothing is read back."""
        if body.get("heartbeat") is not None and not body.get("invoices") and body.get("batch") is None:
            # the website's hourly job checking in, invoices or not
            try:
                rec = feed_mod.beat(self.cfg["store"], body.get("heartbeat"))
            except feed_mod.FeedError:
                raise OpError("bad_request") from None
            audit.update(heartbeat=True, queued=rec["queued"], ledger_failing=rec["ledger_failing"])
            return {"stored": 0, "heartbeat": True, "mode": feed_mod.get_mode(self.cfg["store"])}
        received = _today(body)
        with self.feed_lock:                     # staging and the feed: one writer at a time
            try:
                got = feed_mod.stage(self.cfg["store"], body.get("batch"), body.get("part"),
                                     body.get("parts"), body.get("invoices"))
            except feed_mod.FeedError:
                raise OpError("bad_request") from None
            if got is None:
                audit.update(part=body.get("part"), parts=body.get("parts"))
                return {"stored": 0, "waiting": True}
            try:
                changed, total = feed_mod.merge(self.cfg["store"], got, received)
            except feed_mod.FeedError:
                raise OpError("bad_request") from None
            mode = feed_mod.get_mode(self.cfg["store"])
            wrote = False
            if mode == "live":
                try:
                    wrote = feed_mod.write_live(self.cfg, self.cfg["store"])
                except Exception as e:           # the feed is kept; the next batch retries
                    audit.update(write_error=type(e).__name__)
        audit.update(invoices=len(got), changed=changed, feed=total, mode=mode, wrote=wrote)
        return {"stored": len(got), "changed": changed, "feed": total, "mode": mode, "written": wrote}

    def op_data_status(self, body, audit):
        data = self._data()
        m = data.meta
        return {
            "data_as_of": m.get("data_as_of"), "last_import": m.get("built_at"),
            "import_seconds": m.get("import_seconds"), "row_counts": m.get("row_counts"),
            "source_file_count": len(m.get("source_files", [])),
            "import_log": data.import_log.fillna("").astype(str).to_dict("records")[-200:],
            "import_failures": data.failures.fillna("").astype(str).to_dict("records"),
            "unplaced": data.unplaced.head(50).fillna("").to_dict("records"),
            "watcher_error": getattr(self.watcher, "last_error", "") if self.watcher else "",
            "measured_counts": {p: len(c) for p, c in (data.measured or {}).items()},
            "measured_import": m.get("measured"),
            "live_feed": feed_mod.status(self.cfg["store"]),     
        }
