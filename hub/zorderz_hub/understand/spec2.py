"""The version-2 chart spec: sparse, period tokens, no dates.

A v2 spec says only what differs from the defaults. Dates are period TOKENS ("2025",
"2025-Q3", "ytd", "last_12w", "2016..now"); periods.py turns them into windows against the
data date, so neither the reader nor a model ever does date arithmetic.

    {"measure": "jobs", "periods": ["2025"], "grain": "month", "filters": {"lead_sources": ["Google Ads"]}}

Fields (every one optional):
  intent      chart | number | clarify | unsupported                     (chart)
  measure     revenue | jobs | customers | units | avg_job              (revenue)
  periods     period tokens; two or more = compared side by side         (["all"])
  compare     prior_year | prior_period | growth
  to_date     true: each period cut to the same day of the year as the data date
  to_date_cut [month, day]: each year cut at that day
  overlay     true: one line/bar set per period over the same calendar (years by month)
  months      [1..12]: only those calendar months, every year
  grain       none | year | quarter | month | week | day                 (none)
  date_part   month_of_year | quarter_of_year | week_of_year | day_of_week | season
  split, facet, filters, chart_type, orientation, labels, top_n   (as version 1)
  <name>_mode  a pack switch's value (rules.yml switches; the field always ends in _mode)
  filters adds exclude_products / exclude_tags / exclude_areas / exclude_salespeople /
              exclude_work_types / exclude_partners
  series      [{label, filters}]: the same chart once per series (existing vs new customers)
  merge       {"Classic": "Northstar"}: one group counted inside another
  agg         avg_per_period | per_job | jobs_per_customer | job_range
  rank        {dir: top|bottom, n, by: value|change}: name the top/bottom bar(s)
  share       true: parts of the whole, in percent
  clarify_question, clarify_options
  weather     {var, view, lag, by_group}: the weather where the jobs were, beside the chart;
              by_group true: one line per group of a place split, each over its own jobs' ZIPs
  zipfacts    {view, fact, per}: what each ZIP is like (Census, the state fire agency), on a chart by ZIP

Version 1 (the spec the website's chips edit) is still what compute.run reads; to_v1()
writes one per window. from_v1() reads a version-1 spec back (a follow-up to a chart the
site sent without its v2 copy).
"""
import copy
import re

from . import periods as P

INTENTS = ("chart", "number", "clarify", "unsupported")
MEASURES = ("revenue", "jobs", "customers", "units", "avg_job")
GRAINS = ("none", "year", "quarter", "month", "week", "day")
V1_GRAINS = ("none", "year", "quarter", "month")
DATE_PARTS = ("month_of_year", "quarter_of_year", "week_of_year", "day_of_week", "season")
COMPARES = ("prior_year", "prior_period", "growth")
AGGS = ("avg_per_period", "per_job", "jobs_per_customer", "job_range")
CHART_TYPES = ("auto", "stacked_bar", "grouped_bar", "percent_bar", "line", "area", "pie", "donut",
               "waffle", "venn", "single_number", "table", "map")
ORIENTATIONS = ("auto", "vertical", "horizontal")
LABELS = ("none", "value", "percent", "value_percent")
LIST_FILTERS = ("products", "work_types", "salespeople", "areas", "lead_sources", "tags", "accounts",
                "partners", "original_sources", "zips",
                "safety_sites", "safety_councils",   
                "tax_areas", "coast_bands",               
                "coast_sides",                            # Coastal / Inland
                "regions")                                # the pack's regions
EXCLUDES = ("exclude_products", "exclude_tags", "exclude_areas", "exclude_salespeople",
            "exclude_work_types", "exclude_partners", "exclude_lead_sources")
FACETS = ("none", "product", "work_type", "salesperson", "area", "account")
DEFAULTS = {"intent": "chart", "measure": "revenue", "periods": ["all"], "grain": "none"}
KEYS = ("intent", "measure", "periods", "compare", "to_date", "to_date_cut", "overlay", "months", "grain",
        "date_part", "split", "facet", "filters", "series", "merge", "agg", "rank", "top_n", "share",
        "chart_type", "orientation", "labels", "clarify_question", "clarify_options",
        "weather",                                    # weather beside the chart (weatherview.py)
        "zipfacts")                                   # Census and fire-zone facts by ZIP (zipview.py)

_ONE = r"(?:\d{4}(?:-(?:q[1-4]|h[12]|w\d{2}|winter|spring|summer|fall|\d{2}(?:-\d{2})?))?)"
PERIOD_RE = re.compile(r"^(?:all|ytd|qtd|mtd|this_year|last_\d{1,3}[dwmy]|" + _ONE +
                       r"|(?:" + _ONE + r"|start)?\.\.(?:" + _ONE + r"|now|today)?)$")


def switch_key(k):
    """A pack switch's field: lower-case words ending in _mode (pack.Switch)."""
    return isinstance(k, str) and re.fullmatch(r"[a-z_]{1,30}_mode", k) is not None


def full(spec):
    """The spec with every default filled in (a copy)."""
    out = copy.deepcopy(DEFAULTS)
    for k, v in (spec or {}).items():
        if k in KEYS or switch_key(k):
            out[k] = copy.deepcopy(v)
    out.setdefault("filters", {})
    if not out.get("periods"):
        out["periods"] = ["all"]
    return out


def sparse(spec):
    """Only what differs from the defaults: what the log and the model see."""
    out = {}
    for k in list(KEYS) + sorted(k for k in spec if switch_key(k)):
        if k not in spec:
            continue
        v = spec[k]
        if v in (None, [], {}, False, "") or DEFAULTS.get(k) == v:
            continue
        if k == "filters":
            v = {fk: fv for fk, fv in v.items() if fv not in (None, [], False, "", "any")}
            if not v:
                continue
        out[k] = v
    return out


# ---------------------------------------------------------------- schema (for the model)
def schema(pack, lead_sources=(), partners=(), accounts=()):
    """JSON schema for LM Studio's structured output: every value from a closed list, so a
    model can only pick, never invent. Short and sparse: nothing is required."""
    def arr(values):
        return {"type": "array", "items": {"type": "string", "enum": list(values)}, "uniqueItems": True}
    lead = list(lead_sources)
    filters = {
        "products": arr(pack.products), "work_types": arr(pack.work_types),
        "salespeople": arr(list(pack.salespeople) + [pack.not_recorded]),
        "areas": arr(pack.areas), "tags": arr(pack.tags),
        "customer_type": {"type": "string", "enum": ["any", "business", "residential"]},
        "payment": {"type": "string", "enum": ["any", "paid", "unpaid"]},      
        "repeat_customers_only": {"type": "boolean"},
    }
    if lead:
        filters["lead_sources"] = arr(lead)
        filters["original_sources"] = arr(lead)
        filters["exclude_lead_sources"] = arr(lead)
    if partners:
        filters["partners"] = arr(partners)
    if accounts:
        filters["accounts"] = arr(accounts)
    for k in ("products", "tags", "areas", "work_types", "salespeople"):
        filters["exclude_" + k] = filters[k]
    props = {
        "intent": {"type": "string", "enum": list(INTENTS)},
        "measure": {"type": "string", "enum": list(MEASURES)},
        "periods": {"type": "array", "maxItems": 12,
                    "items": {"type": "string", "pattern": PERIOD_RE.pattern}},
        "compare": {"type": "string", "enum": list(COMPARES)},
        "to_date": {"type": "boolean"},
        "overlay": {"type": "boolean"},
        "months": {"type": "array", "items": {"type": "integer", "minimum": 1, "maximum": 12}, "uniqueItems": True},
        "grain": {"type": "string", "enum": list(GRAINS)},
        "date_part": {"type": "string", "enum": list(DATE_PARTS)},
        "split": {"type": "string", "enum": list(pack.splits)},
        "facet": {"type": "string", "enum": list(FACETS)},
        "filters": {"type": "object", "additionalProperties": False, "properties": filters},
        "agg": {"type": "string", "enum": list(AGGS)},
        "rank": {"type": "object", "additionalProperties": False,
                 "properties": {"dir": {"type": "string", "enum": ["top", "bottom"]},
                                "n": {"type": "integer", "minimum": 1, "maximum": 15}}},
        "top_n": {"type": "integer", "minimum": 3, "maximum": 15},
        "share": {"type": "boolean"},
        "chart_type": {"type": "string", "enum": list(CHART_TYPES)},
        "clarify_question": {"type": "string", "maxLength": 200},
        "clarify_options": {"type": "array", "maxItems": 3, "items": {"type": "string", "maxLength": 160}},
    }
    return {"name": "chart_spec_v2", "strict": True,
            "schema": {"type": "object", "additionalProperties": False, "properties": props}}


# ---------------------------------------------------------------- checks
def check(spec, pack, anchor, first=None, partners=None):
    """Plain-words problems with a v2 spec; [] means it can run. Every value must come
    from the pack's own lists, and every period must resolve against the data date."""
    s = full(spec)
    problems = []
    if s["intent"] not in INTENTS:
        problems.append(f"intent {s['intent']!r} is not one of {', '.join(INTENTS)}")
    if s["measure"] not in MEASURES:
        problems.append(f"measure {s['measure']!r} is not one of {', '.join(MEASURES)}")
    if s["grain"] not in GRAINS:
        problems.append(f"grain {s['grain']!r} is not one of {', '.join(GRAINS)}")
    for k, allowed in (("compare", COMPARES), ("date_part", DATE_PARTS), ("agg", AGGS),
                       ("chart_type", CHART_TYPES), ("orientation", ORIENTATIONS), ("labels", LABELS)):
        if s.get(k) not in (None, "") and s[k] not in allowed:
            problems.append(f"{k} {s[k]!r} is not one of {', '.join(allowed)}")
    if s.get("split") and s["split"] not in pack.splits:
        problems.append(f"split {s['split']!r} is not one Charts has")
    if s.get("facet") and s["facet"] not in FACETS:
        problems.append(f"facet {s['facet']!r} is not one Charts has")
    if s.get("facet") and s.get("facet") != "none" and s.get("facet") == s.get("split"):
        problems.append("facet must be different from split")
    for t in s["periods"]:
        if not isinstance(t, str) or not PERIOD_RE.match(t.lower()):
            problems.append(f"period {t!r} isn't a period Charts reads")
            continue
        try:
            P.resolve(t, anchor, first)
        except P.PeriodError as e:
            problems.append(str(e))
    for m in s.get("months") or []:
        if not isinstance(m, int) or not 1 <= m <= 12:
            problems.append(f"month {m!r} must be 1–12")
    cut = s.get("to_date_cut")
    if cut is not None and not (isinstance(cut, (list, tuple)) and len(cut) == 2 and 1 <= cut[0] <= 12
                                and 1 <= cut[1] <= 31):
        problems.append("to_date_cut must be [month, day]")
    known = {"products": set(pack.products), "work_types": set(pack.work_types),
             "salespeople": set(pack.salespeople) | {pack.not_recorded}, "areas": set(pack.areas),
             "tags": set(pack.tags)}
    f = s.get("filters") or {}
    for k, v in f.items():
        base = k[len("exclude_"):] if k.startswith("exclude_") else k
        if k in ("customer_type",):
            if v not in ("any", "business", "residential"):
                problems.append(f"customer_type {v!r} is not any/business/residential")
            continue
        if k == "payment":                                        
            if v not in ("any", "paid", "unpaid"):
                problems.append(f"payment {v!r} is not any/paid/unpaid")
            continue
        if k in ("repeat_customers_only",):
            continue
        if k in ("job_min", "job_max"):                           # a job's size, dollars
            if v is not None and (isinstance(v, bool) or not isinstance(v, (int, float)) or not 0 <= v <= 10_000_000):
                problems.append(f"{k} must be a dollar amount")
            elif k == "job_max" and v is not None:
                lo = f.get("job_min")
                if isinstance(lo, (int, float)) and not isinstance(lo, bool) and lo >= v:
                    problems.append("job_min must be below job_max")
            continue
        if base not in LIST_FILTERS:
            problems.append(f"filter {k!r} is not one Charts has")
            continue
        if not isinstance(v, list):
            problems.append(f"filter {k} must be a list")
            continue
        if base == "regions":                                     # names or the words for them, case-folded
            drawn = {str(x).casefold() for x in list(getattr(pack, "regions", {}) or {}) +
                     list(getattr(pack, "region_words", {}) or {})}
            bad = [x for x in v if re.sub(r"^\s*the\s+", "", str(x).strip(), flags=re.I).casefold() not in drawn]
            if bad:
                problems.append(f"{k}: not a region Charts has: {', '.join(map(str, bad[:5]))}")
            continue
        if base in known:
            bad = [x for x in v if x not in known[base]]
            if bad:
                problems.append(f"{k}: not in the pack's list: {', '.join(map(str, bad))[:120]}")
    for sr in s.get("series") or []:
        if not isinstance(sr, dict) or not sr.get("label"):
            problems.append("each series needs a label")
    rank = s.get("rank")
    if rank and (rank.get("dir") not in ("top", "bottom")):
        problems.append("rank.dir must be top or bottom")
    if s.get("weather") is not None:                  
        from .weatherview import check as weather_check
        problems.extend(weather_check(s["weather"]))
    if s.get("zipfacts") is not None:                 
        from .zipview import check as zip_check
        problems.extend(zip_check(s["zipfacts"]))
    if s["intent"] in ("clarify", "unsupported") and not (s.get("clarify_question") or "").strip():
        problems.append(f'intent "{s["intent"]}" needs a clarify_question')
    return problems


# ---------------------------------------------------------------- version 1
def to_v1(spec, pack, window=None, filters_extra=None, grain=None, title=""):
    """One version-1 spec (compute.run's input) for one window of a v2 spec. Grains and
    filters version 1 has no words for (week, day, date parts, months, exclusions, merges)
    travel as extra keys that compute.run reads; to_public() strips them before the
    spec goes back to the website."""
    from ..compute import base_spec
    s = full(spec)
    v1 = base_spec(pack)
    v1["intent"] = s["intent"] if s["intent"] in INTENTS else "chart"
    measure = s["measure"]
    if s.get("agg") == "per_job":
        measure = "units"
    elif s.get("agg") == "jobs_per_customer":
        measure = "customers"
    v1["measure"] = measure
    v1["split"] = s.get("split") or "none"
    v1["facet"] = s.get("facet") or "none"
    v1["time_grain"] = grain or (s.get("date_part") or s["grain"])
    if window is not None:
        v1["date_from"], v1["date_to"] = window.as_spec_dates()
    f = v1["filters"]
    sf = copy.deepcopy(s.get("filters") or {})
    for extra in (filters_extra or {}).items():
        sf[extra[0]] = extra[1]
    for k, v in sf.items():
        if k in LIST_FILTERS or k in EXCLUDES:
            f[k] = list(v)
        elif k in ("customer_type", "repeat_customers_only", "payment"):
            f[k] = v
        elif k in ("job_min", "job_max") and v is not None:   
            f[k] = v
    if s.get("months"):
        f["months"] = list(s["months"])
    if s.get("to_date_cut"):
        f["day_cut"] = list(s["to_date_cut"])
    if s.get("merge"):
        v1["merge_groups"] = dict(s["merge"])
    for k in ("chart_type", "orientation", "labels"):
        if s.get(k):
            v1[k] = s[k]
    if s.get("top_n"):
        v1["top_n"] = int(s["top_n"])
    for k in [k for k in s if switch_key(k)]:
        if s.get(k) and k in v1:
            v1[k] = s[k]
    v1["clarify_question"] = s.get("clarify_question") if v1["intent"] in ("clarify", "unsupported") else None
    v1["clarify_options"] = list(s.get("clarify_options") or [])[:3] if v1["intent"] == "clarify" else []
    v1["title"] = title[:90]
    return v1


PUBLIC_FILTERS = ("products", "work_types", "salespeople", "areas", "lead_sources", "tags", "accounts",
                  "customer_type", "repeat_customers_only", "exclude_lead_sources", "original_sources",
                  "partners", "customers", "exclude_products", "exclude_tags", "exclude_areas",
                  "exclude_salespeople", "exclude_work_types", "exclude_partners", "months", "day_cut", "zips",
                  "safety_sites", "safety_councils", "tax_areas", "coast_bands", "coast_sides", "payment", "regions",
                  "job_min", "job_max")                                                   


def to_public(v1):
    """A version-1 spec the website's schema accepts: no extra grains or keys."""
    out = copy.deepcopy(v1)
    if out.get("time_grain") not in V1_GRAINS:
        out["time_grain"] = "none"
    for k in ("windows", "window_as", "keep_groups"):
        out.pop(k, None)
    if not out.get("merge_groups"):
        out.pop("merge_groups", None)
    f = out.get("filters") or {}
    out["filters"] = {k: v for k, v in f.items() if k in PUBLIC_FILTERS}
    if out.get("facet") not in FACETS:
        out["facet"] = "none"
    return out


def natural_token(a, b, anchor=None, first=None):
    """the period token that names exactly these dates ("2025", "Q3 2025", "Mar 2025", "2026" to the data
    date, "2024..now"), so a chart read back from version 1 keeps the label it was asked with; else a dated range."""
    import datetime as _dt
    try:
        a_ = _dt.date.fromisoformat(str(a)[:10]) if a else None
        b_ = _dt.date.fromisoformat(str(b)[:10]) if b else None
    except (TypeError, ValueError):
        return f"{a or 'start'}..{b or 'now'}"
    if a_ and b_ and a_ == b_:
        return str(a)                                # one day is that day, not "Q4"
    if a_ and b_ and anchor is not None:
        cands = []
        if a_.month == 1 and a_.day == 1:
            cands.append(str(a_.year))
            if b_.year > a_.year:
                cands += [f"{a_.year}..now", f"{a_.year}..{b_.year}"]
        if a_.day == 1:
            cands.append(f"{a_.year}-{a_.month:02d}")
        if a_.day == 1 and a_.month in (1, 4, 7, 10):
            cands.append(f"{a_.year}-q{(a_.month - 1) // 3 + 1}")
        if a_.day == 1 and a_.month in (1, 7):
            cands.append(f"{a_.year}-h{1 if a_.month == 1 else 2}")
        for t in cands:
            try:
                w = P.resolve(t, anchor, first)
            except Exception:                        # pragma: no cover - a token the period reader refuses
                continue
            # a quarter or half still running is a date range, not that quarter; a year or a month to
            # the data date keeps its name
            if w.start == a_ and w.end == b_ and (not w.partial or "-q" not in t and "-h" not in t):
                return t
    return f"{a or 'start'}..{b or 'now'}" if a != b else str(a)


def from_v1(v1, anchor=None, first=None):
    """A version-1 spec as v2 (a follow-up to a chart sent back without its v2 copy).
    the comparison windows (`windows` / `window_as`; callers turn the page's `compare_windows` into these
    first) come back as periods
    (side by side, or one line each over the calendar); calendar months, a day-of-year cut and paid / unpaid come
    back too; a range that is one natural period keeps that period's name."""
    s = {}
    if not isinstance(v1, dict):
        return s
    for k in ["intent", "measure", "split", "chart_type", "orientation", "labels"] + sorted(k for k in v1 if switch_key(k)):
        v = v1.get(k)
        if v not in (None, "", "none", "auto") or (k == "intent" and v):
            s[k] = v
    if v1.get("facet") not in (None, "none"):
        s["facet"] = v1["facet"]
    g = v1.get("time_grain")
    if g in (None, "", "none") and v1.get("grain_detail") in DATE_PARTS + ("week", "day"):
        g = v1["grain_detail"]                      # the finer grain the chips carry back
    if g in DATE_PARTS:
        s["date_part"], s["grain"] = g, "none"
    elif g:
        s["grain"] = g
    wins = v1.get("windows") if isinstance(v1.get("windows"), list) else None
    if wins and len(wins) >= 2:                      # periods compared side by side, or over one calendar
        s["periods"] = [natural_token(w.get("from"), w.get("to"), anchor, first) for w in wins]
        if v1.get("window_as") == "group":
            s["overlay"] = True
            cal = {"month_of_year": "month", "quarter_of_year": "quarter", "week_of_year": "week"}
            if s.get("date_part") in cal:            # the overlay's calendar is its grain ("by month")
                s["grain"] = cal[s.pop("date_part")]
            # a season or weekday overlay keeps its date part; execute._overlay draws it
        else:
            s.pop("grain", None)
            s.pop("date_part", None)
    else:
        a, b = v1.get("date_from"), v1.get("date_to")
        if a or b:
            s["periods"] = [natural_token(a, b, anchor, first) if a and b else f"{a or 'start'}..{b or 'now'}"]
    f = {}
    for k, v in (v1.get("filters") or {}).items():
        if k == "customers":
            continue                    # a customer never travels in a v2 spec (cards add it back)
        if k == "months":               # calendar months are the spec's own, not a filter list
            ms = [int(m) for m in v or [] if isinstance(m, int) and 1 <= m <= 12]
            if ms:
                s["months"] = sorted(set(ms))
            continue
        if k == "day_cut":              # each year cut at that day
            if isinstance(v, list) and len(v) == 2 and all(isinstance(x, int) for x in v):
                s["to_date_cut"] = list(v)
            continue
        if isinstance(v, list) and v:
            f[k] = list(v)
        elif k == "customer_type" and v not in (None, "any"):
            f[k] = v
        elif k == "repeat_customers_only" and v:
            f[k] = True
        elif k in ("job_min", "job_max") and v is not None:   
            f[k] = v
        elif k == "payment" and v in ("paid", "unpaid"):       
            f[k] = v
    if f:
        s["filters"] = f
    if v1.get("top_n") not in (None, 7):
        s["top_n"] = v1["top_n"]
    return s


# The hub now sends the windows with the chart as `compare_windows`; the page keeps them and sends them back with
# every edit, and run_spec counts the comparison again -- side by side, or one line each over the calendar, as the
# Time chip says.
COMPARE_MAX = 12                     # more than this and no comparison is sent (never a cut one)
_ISO = re.compile(r"^\d{4}-\d{2}-\d{2}$")
OVERLAY_OF = {"month": "month_of_year", "quarter": "quarter_of_year", "week": "week_of_year",
              "month_of_year": "month_of_year", "quarter_of_year": "quarter_of_year", "week_of_year": "week_of_year",
              "season": "season", "day_of_week": "day_of_week"}


def public_compare(kind, windows, date_from, date_to):
    """The comparison as the page keeps it: how it's drawn, each window, and the dates the hub put on the chips."""
    if kind not in ("windows", "overlay") or not windows or not 2 <= len(windows) <= COMPARE_MAX:
        return None
    return {"as": "period" if kind == "windows" else "group",
            "windows": [{"label": str(w.label)[:60], "from": w.start.isoformat(), "to": w.end.isoformat()}
                        for w in windows],
            "date_from": date_from, "date_to": date_to}


def compare_from_public(cw, v1):
    """The windows a page sent back, checked, or None. None too when the chart's dates are no longer the ones the
    hub sent (a date chip was changed: the chart is that range now)."""
    import datetime as _dt
    if not isinstance(cw, dict) or cw.get("as") not in ("period", "group"):
        return None
    ws = cw.get("windows")
    if not isinstance(ws, list) or not 2 <= len(ws) <= COMPARE_MAX:
        return None
    out = []
    for w in ws:
        if not isinstance(w, dict):
            return None
        a, b, label = w.get("from"), w.get("to"), w.get("label")
        if not (isinstance(a, str) and isinstance(b, str) and _ISO.match(a) and _ISO.match(b)):
            return None
        try:
            if _dt.date.fromisoformat(a) > _dt.date.fromisoformat(b):
                return None
        except ValueError:
            return None
        if not isinstance(label, str) or not label.strip() or len(label) > 60:
            return None
        out.append({"label": label.strip(), "from": a, "to": b})
    if len({w["label"] for w in out}) != len(out):
        return None
    if (v1.get("date_from"), v1.get("date_to")) != (cw.get("date_from"), cw.get("date_to")):
        return None
    return {"as": cw["as"], "windows": out}


def compare_plan(cw, v1):
    """How the comparison is drawn after this edit: the Time chip decides. A calendar grain (month, quarter, week, a
    date part) draws one line per window over it, unless the chart is split; otherwise the windows stand side by
    side. "By day" has no calendar to share, and a map or a Venn shows one range: the comparison is dropped,
    and the words say why. Returns ((as, time_grain), None) or (None, why)."""
    if v1.get("chart_type") == "map":
        return None, "A map shows one range, so this is the range on the chips; the comparison was left off"
    if v1.get("chart_type") == "venn":
        return None, "A Venn chart shows one range, so this is the range on the chips; the comparison was left off"
    g = v1.get("time_grain") or "none"
    if g == "none" and v1.get("grain_detail") in OVERLAY_OF:
        g = v1["grain_detail"]                       # the page's finer grain, sent back beside "none"
    if g == "day":
        return None, "By day, the compared periods can't share a calendar, so this is the one range on the chips"
    if g in OVERLAY_OF and (v1.get("split") or "none") == "none":
        return ("group", OVERLAY_OF[g]), None
    if g in OVERLAY_OF:                              # say why the Time pick isn't used
        return ("period", "none"), ("The compared periods stand side by side while the chart is split, so the time split "
                                    "was left off; remove the split and pick it again for one line per period")
    if g == "year":
        return ("period", "none"), "The compared periods stand side by side, so \"By year\" was left off"
    return ("period", "none"), None
