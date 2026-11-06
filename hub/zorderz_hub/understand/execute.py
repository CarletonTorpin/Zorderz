"""Running a version-2 spec: every number still comes from compute.run.

    answer = execute.run(spec2, Runner(data, pack, access), anchor=data_as_of, first=first_day)

What this adds on top of compute.run, all by rule and all on the hub:
  * periods side by side (Q3 2024 vs Q3 2025, 2020 vs 2021 vs 2022), one compute pass with
    labelled windows, so groups rank and "All other" add up across the windows;
  * prior year / prior period: the same days a year (or a period) earlier, never a model's
    arithmetic; a partial period is compared with the same partial span;
  * years over one calendar (2023, 2024, 2025 by month): one series per year;
  * "to date": each year cut at the same day; the data date wins when it is earlier;
  * date parts (weekday, season, month of year) pooled over the years asked about;
  * averages per month/week/year (empty months count as 0), units per job, jobs per
    customer, each group's largest and smallest job, the top/bottom bar named, growth.

The answer keeps the version-1 result shape (period, group, value, jobs, revenue, pct)
so the website draws it as before; window labels and date parts arrive as readable keys.
"""
import copy
import datetime as dt
import math
from dataclasses import dataclass, field

import pandas as pd

from .. import access as acc
from .. import compute
from . import periods as P
from . import spec2 as S

MONTH_ABBR = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"]
DOW = {"1": "Mon", "2": "Tue", "3": "Wed", "4": "Thu", "5": "Fri", "6": "Sat", "7": "Sun"}
SEASON_LABEL = {"1-winter": "Winter (Dec–Feb)", "2-spring": "Spring (Mar–May)", "3-summer": "Summer (Jun–Aug)",
                "4-fall": "Fall (Sep–Nov)"}
OVERLAY_PART = {"month": "month_of_year", "quarter": "quarter_of_year", "week": "week_of_year",
                "day": "month_of_year"}
JPC_BUCKETS = [(1, 1, "1 job"), (2, 2, "2 jobs"), (3, 3, "3 jobs"), (4, 5, "4–5 jobs"), (6, 10, "6–10 jobs"),
               (11, 10 ** 9, "11+ jobs")]
ADDITIVE = {"revenue", "jobs", "units"}


class ExecuteError(ValueError):
    pass


@dataclass
class Runner:
    """What execute needs from the hub: the data, the pack, and the caller's access
    (applied to EVERY computation, exactly as ops._compute does)."""
    data: object
    pack: object
    access: dict = None
    extra_filters: dict = None       # a customer kept from an invoice card's chart (follow-ups)
    weather: object = None           # weather.Store, or None when the hub has weather off
    dry: bool = False                # plan only -- the spec as it would run, nothing counted (a title)
    v1_fields: dict = None           # version-1 settings v2 has no words for (include_unpaid, *_mode)

    def restrict(self, v1):
        v1 = copy.deepcopy(v1)
        for k, v in (self.extra_filters or {}).items():
            v1.setdefault("filters", {})[k] = copy.deepcopy(v)
        for k, v in (self.v1_fields or {}).items():
            v1[k] = copy.deepcopy(v)
        if not self.access:
            return v1, []
        return acc.restrict_spec(v1, self.access, self.pack.not_recorded)

    def compute(self, v1):
        s, notes = self.restrict(v1)
        if self.dry:                 # no data read; the kinds below see an empty result
            r = compute._empty("")
            r["notes"], r["dry"] = list(notes), True
            return s, r
        r = compute.run(self.data, self.pack, s)
        if self.access:
            r = acc.restrict_result(r, self.access, s)
        r["notes"] = notes + [n for n in r.get("notes", []) if n not in notes]
        return s, r

    def rows(self, v1):
        s, notes = self.restrict(v1)
        if self.dry:
            return s, pd.DataFrame(), notes
        return s, compute.select(self.data, self.pack, s), notes


@dataclass
class Answer:
    spec: dict                 # the v2 spec as it ran (full)
    v1: dict                   # the version-1 spec that ran (restricted; extra keys kept)
    result: dict
    windows: list              # periods.Window, in the order shown
    kind: str                  # plain | windows | overlay | series | jobs_per_customer | job_range | per_job
    notes: list = field(default_factory=list)
    extra: dict = field(default_factory=dict)    # deltas, growth, average, rank: the facts' raw parts


# ---------------------------------------------------------------- helpers
def _window_all(anchor, first):
    return P.resolve("all", anchor, first)


def _resolve_all(tokens, anchor, first):
    out = []
    for t in tokens or ["all"]:
        out.append(P.resolve(t, anchor, first))
    return out


def _years_like(w):
    return w.start.month == 1 and w.start.day == 1 and w.start.year == w.end.year


def _union(ws):
    a = min(w.start for w in ws)
    b = max(w.end for w in ws)
    return P.Window(a, b, P._span_label(a, b), "..", any(w.partial for w in ws), b)


def _md(d):
    return (d.month, d.day)


def _num(v):
    try:
        if v is None or (isinstance(v, float) and math.isnan(v)):
            return None
        return float(v)
    except (TypeError, ValueError):
        return None


def _label_key(key, grain, one_year=None):
    """A period key as people say it, for the grains version 1 doesn't draw."""
    k = str(key)
    if grain == "week":
        y, w = k.split("-W")
        d = dt.date.fromisocalendar(int(y), int(w), 1)
        return f"Week of {d:%b} {d.day}" + ("" if one_year else f", {d.year}")
    if grain == "day":
        d = dt.date.fromisoformat(k)
        return f"{d:%b} {d.day}" + ("" if one_year else f", {d.year}")
    if grain == "month_of_year":
        return MONTH_ABBR[int(k) - 1]
    if grain == "quarter_of_year":
        return k
    if grain == "week_of_year":
        return f"Week {int(k[1:])}"
    if grain == "day_of_week":
        return DOW.get(k, k)
    if grain == "season":
        return SEASON_LABEL.get(k, k)
    return k


def relabel(result, mapping):
    """Rename period keys everywhere they appear in a result (and its small charts)."""
    if not mapping:
        return result
    r = result
    r["periods"] = [mapping.get(p, p) for p in r.get("periods", [])]
    wx = r.get("weather")
    if isinstance(wx, dict):                          # the weather's periods are the chart's
        for k in ("values",):
            if isinstance(wx.get(k), dict):
                wx[k] = {mapping.get(p, p): v for p, v in wx[k].items()}
        for k in ("excluded", "no_weather", "periods"):
            if isinstance(wx.get(k), list):
                wx[k] = [mapping.get(p, p) for p in wx[k]]
        for g in (wx.get("groups") or {}).values():   # each group's own values too
            if isinstance(g, dict) and isinstance(g.get("values"), dict):
                g["values"] = {mapping.get(p, p): v for p, v in g["values"].items()}
    for row in r.get("rows", []):
        row[0] = mapping.get(row[0], row[0])
    t = r.get("totals") or {}
    for k in ("by_period", "jobs_by_period"):
        if isinstance(t.get(k), dict):
            t[k] = {mapping.get(p, p): v for p, v in t[k].items()}
    for f in r.get("facets", []) or []:
        relabel(f["result"], mapping)
    return r


def _order_rows(result):
    """Rows in the order of the axis (window labels don't sort as text)."""
    pos = {p: i for i, p in enumerate(result.get("periods", []))}
    gpos = {g: i for i, g in enumerate(result.get("groups", []))}
    result["rows"] = sorted(result.get("rows", []),
                            key=lambda r: (pos.get(r[0], 10 ** 6), gpos.get(r[1], 10 ** 6)))
    for f in result.get("facets", []) or []:
        _order_rows(f["result"])
    return result


def _cell(result, period, group):
    for r in result.get("rows", []):
        if r[0] == period and r[1] == group:
            return _num(r[2])
    return None


def _period_value(result, period):
    v = (result.get("totals") or {}).get("by_period", {}).get(period)
    return _num(v)


def _pct_change(a, b):
    if a is None or b is None or a == 0:
        return None
    return round((b - a) / abs(a) * 100, 1)


def _effective_cut(cut, ws, anchor):
    """The day each year is cut at: the asked day, or the data date when that is earlier
    and the asked years reach it (every year then covers the same days)."""
    m, d = int(cut[0]), int(cut[1])
    if any(w.end >= anchor for w in ws) and _md(anchor) < (m, d):
        return [anchor.month, anchor.day], True
    return [m, d], False


# ---------------------------------------------------------------- the plan
def run(spec, runner, anchor, first=None):
    """Run a v2 spec. Raises ExecuteError (plain words) when it can't."""
    s = S.full(spec)
    pack = runner.pack
    first = first or dt.date(anchor.year - 10, 1, 1)
    try:
        ws = _resolve_all(s["periods"], anchor, first)
    except P.PeriodError as e:
        raise ExecuteError(str(e)) from None
    notes, extra = [], {}
    grain = s.get("date_part") or s.get("grain") or "none"
    cmp = s.get("compare")

    # comparisons the question asked for by name
    if cmp == "prior_year" and len(ws) == 1:
        ws = [P.shift_year(ws[0]), ws[0]]
    elif cmp == "prior_period" and len(ws) == 1:
        ws = [P.shift_period(ws[0]), ws[0]]
    # "to date" across several years: each cut at the data date's day of the year
    if s.get("to_date") and len(ws) > 1 and all(_years_like(w) or w.partial for w in ws) and not s.get("to_date_cut"):
        cut = _md(max(w.end for w in ws))
        ws = [P.cut_each_year(w, *cut) if not (w.partial and w.end == anchor) else w for w in ws]
        notes.append(f"Each year runs Jan 1–{MONTH_ABBR[cut[0] - 1]} {cut[1]}, so the years cover the same days")
    if len(ws) > 1 and not cmp and not s.get("overlay") and not s.get("to_date"):
        ws, said = _match_partial(ws)
        if said:
            notes.append(said)
    filters_extra = {}
    if s.get("to_date_cut"):
        cut, moved = _effective_cut(s["to_date_cut"], ws, anchor)
        filters_extra["day_cut"] = cut
        s["to_date_cut"] = cut
        label = f"Jan 1–{MONTH_ABBR[cut[0] - 1]} {cut[1]}"
        notes.append(f"Every year counts {label} only" +
                     (f" (the data runs to {anchor:%b} {anchor.day}, so every year stops there)" if moved else ""))

    if s.get("agg") == "jobs_per_customer":
        a = _jobs_per_customer(s, runner, ws, notes, extra, anchor)
    elif s.get("agg") == "job_range":
        a = _job_range(s, runner, ws, notes, extra, filters_extra)
    elif s.get("agg") == "per_job":
        a = _per_job(s, runner, ws, grain, notes, extra, filters_extra)
    elif s.get("series"):
        a = _series(s, runner, ws, grain, notes, extra, filters_extra, anchor)
    elif len(ws) > 1 and s.get("overlay"):
        a = _overlay(s, runner, ws, grain, notes, extra, filters_extra, anchor)
    elif len(ws) > 1:
        a = _windows(s, runner, ws, notes, extra, filters_extra, anchor)
    else:
        a = _plain(s, runner, ws[0], grain, notes, extra, filters_extra, anchor)
    if s.get("weather") and a.kind != "plain":
        a.notes.append("Weather is shown beside one chart over time; this one compares periods or groups side "
                       "by side, so it's left off. Ask for one period (\"by month in 2025 with the temperature\").")
        a.result["weather"] = {"status": "not_this_chart"}
    _sync(a)
    return a


def _sync(a):
    """What the caller may see can be less than was asked (scope, money, per-person
    rights): the v2 spec is brought in line with the version 1 that ran, so the title
    and captions never describe a chart that wasn't drawn."""
    ran, s = a.v1 or {}, a.spec
    f, rf = s.setdefault("filters", {}), ran.get("filters") or {}
    if s.get("split") and ran.get("split", "none") == "none" and a.kind in ("plain", "windows"):
        s.pop("split", None)
        s.pop("merge", None)
    if s.get("facet") and ran.get("facet", "none") == "none" and a.kind == "plain":
        s.pop("facet", None)
    for k in ("salespeople", "accounts"):
        if k in rf and list(rf.get(k) or []) != list(f.get(k) or []):
            if rf.get(k):
                f[k] = list(rf[k])
            else:
                f.pop(k, None)
    if ran.get("measure") == "jobs" and s.get("measure") in ("revenue", "avg_job") and not s.get("agg"):
        s["measure"] = "jobs"


# ---------------------------------------------------------------- kinds
def _plain(s, runner, w, grain, notes, extra, filters_extra, anchor):
    v1 = S.to_v1(s, runner.pack, w, filters_extra, grain=grain)
    ran, result = runner.compute(v1)
    one_year = w.start.year == w.end.year
    wx_notes = []
    if s.get("weather"):
        # the weather for the chart's own periods, while they still have their raw keys
        # (relabel below makes week/day keys readable, the weather's too)
        from . import weatherview
        weatherview.attach(s, runner, w, grain, result, ran, wx_notes, filters_extra)
    if grain in ("week", "day") or grain in compute.DATE_PART_KEYS:
        mapping = {p: _label_key(p, grain, one_year) for p in result.get("periods", [])}
        relabel(result, mapping)
    if s.get("months") and grain == "year" and not result.get("empty"):
        _drop_periods(result, [p for p in result["periods"] if not _months_in_window(int(p), s["months"], w)])
    if grain in ("year", "quarter", "month", "week") and not result.get("empty") and result.get("periods"):
        first_p, last_p = result["periods"][0], result["periods"][-1]
        a0, _ = _bucket(first_p, grain)
        _, b1 = _bucket(last_p, grain)
        cut_days = s.get("months") or s.get("to_date_cut")
        if b1 and b1 > w.end and not cut_days:
            notes.append(f"{_say(last_p, grain)} is partial: the data runs to {w.end:%b} {w.end.day}, {w.end.year}"
                         if w.end >= anchor else f"{_say(last_p, grain)} is cut at {w.end:%b} {w.end.day}")
            extra["partial_period"] = last_p
        if a0 and a0 < w.start and not cut_days:
            notes.append(f"{_say(first_p, grain)} starts {w.start:%b} {w.start.day}, {w.start.year}"
                         + (" (the first invoice)" if w.token == "all" or w.token.startswith("..") else ""))
            extra["partial_first"] = first_p
    if s.get("compare") == "growth" and grain in ("year", "quarter", "month") and not result.get("empty"):
        extra["growth"] = _growth(result, runner, ran, s, w, grain, anchor)
    if s.get("agg") == "avg_per_period" and not result.get("empty"):
        extra["average"] = _average(result, s, w, grain, anchor)
    _rank(s, result, extra, partial_last=bool(extra.get("partial_period")), partial_first=extra.get("partial_first"))
    return Answer(s, ran, _order_rows(result), [w], "plain", notes + wx_notes, extra)


def _windows(s, runner, ws, notes, extra, filters_extra, anchor):
    u = _union(ws)
    v1 = S.to_v1(s, runner.pack, u, filters_extra, grain="none")
    v1["windows"] = [{"label": w.label, "from": w.start.isoformat(), "to": w.end.isoformat()} for w in ws]
    v1["window_as"] = "period"
    if s.get("facet"):
        notes.append("One chart for the compared periods; small charts left out")
    v1["facet"] = "none"
    ran, result = runner.compute(v1)
    if not result.get("empty"):
        extra["deltas"] = _deltas(result, ws)
        if any(w.partial for w in ws):
            pw = [w for w in ws if w.partial][-1]
            if not any(n.startswith(pw.label) for n in notes):
                notes.append(f"{pw.label}: the data runs to {anchor:%b} {anchor.day}, {anchor.year}")
    _rank(s, result, extra, partial_last=False, windows=ws)
    return Answer(s, ran, _order_rows(result), ws, "windows", notes, extra)


def _overlay(s, runner, ws, grain, notes, extra, filters_extra, anchor):
    part = OVERLAY_PART.get(grain, grain if grain in compute.DATE_PART_KEYS else "month_of_year")
    u = _union(ws)
    s2 = dict(s)
    if s.get("split"):
        notes.append("One line per year; the split was left out so the years can be compared")
        s2["split"] = None
    v1 = S.to_v1(s2, runner.pack, u, filters_extra, grain=part)
    v1["split"] = "none"
    v1["windows"] = [{"label": w.label, "from": w.start.isoformat(), "to": w.end.isoformat()} for w in ws]
    v1["window_as"] = "group"
    ran, result = runner.compute(v1)
    mapping = {p: _label_key(p, part) for p in result.get("periods", [])}
    relabel(result, mapping)
    if any(w.partial for w in ws):
        pw = [w for w in ws if w.partial][-1]
        notes.append(f"{pw.label}: the data runs to {anchor:%b} {anchor.day}, so its later "
                     f"{'months' if part == 'month_of_year' else 'periods'} are empty")
    if not result.get("empty"):
        extra["deltas"] = _overlay_totals(result, ws)
    _rank(s, result, extra, partial_last=False)
    return Answer(s, ran, _order_rows(result), ws, "overlay", notes, extra)


def _series(s, runner, ws, grain, notes, extra, filters_extra, anchor):
    w = ws[0] if len(ws) == 1 else _union(ws)
    if len(ws) > 1:
        notes.append(f"Shown over {w.label} as one range")
    results, ran_first = [], None
    s2 = dict(s)
    if s.get("split"):
        notes.append("One bar per series; the split was left out")
        s2["split"] = None
    for sr in s["series"]:
        fx = dict(filters_extra)
        for k, v in (sr.get("filters") or {}).items():
            fx[k] = v
        v1 = S.to_v1(s2, runner.pack, w, fx, grain=grain)
        v1["split"] = "none"
        v1["top_n"] = 7
        ran, r = runner.compute(v1)
        ran_first = ran_first or ran
        results.append((sr["label"], r))
    result = _combine_series(results, s["measure"], runner.pack)
    one_year = w.start.year == w.end.year
    if grain in ("week", "day") or grain in compute.DATE_PART_KEYS:
        relabel(result, {p: _label_key(p, grain, one_year) for p in result.get("periods", [])})
    if not result.get("empty") and len(result["groups"]) >= 2:
        a, b = result["group_totals"][0], result["group_totals"][1]
        extra["series_totals"] = [{"label": g["group"], "value": g["value"], "pct": g["pct"]}
                                  for g in result["group_totals"]]
    if w.partial and grain in ("year", "quarter", "month"):
        notes.append(f"{result['periods'][-1] if result.get('periods') else w.label} is partial: "
                     f"the data runs to {anchor:%b} {anchor.day}, {anchor.year}")
    _rank(s, result, extra, partial_last=w.partial)
    return Answer(s, ran_first, _order_rows(result), [w], "series", notes, extra)


def _combine_series(results, measure, pack):
    live = [(label, r) for label, r in results if not r.get("empty")]
    if not live:
        return compute._empty("No matching jobs for these filters.")
    periods = []
    for _, r in live:
        for p in r["periods"]:
            if p not in periods:
                periods.append(p)
    rows, group_totals = [], []
    additive = measure in ADDITIVE
    per_period = {p: 0.0 for p in periods}
    for label, r in live:
        for p, g, v, j, rev, pct in r["rows"]:
            rows.append([p, label, v, j, rev, None])
            if additive and v is not None:
                per_period[p] += float(v)
    for row in rows:
        tot = per_period.get(row[0]) or 0
        row[5] = round(float(row[2]) / tot * 100, 1) if additive and tot > 0 and row[2] is not None else None
    whole = sum(_num(r["totals"]["value"]) or 0 for _, r in live) if additive else None
    for label, r in live:
        v = r["totals"]["value"]
        group_totals.append({"group": label, "value": v, "jobs": r["totals"].get("jobs"),
                             "pct": round(float(v) / whole * 100, 1) if additive and whole and v is not None else None})
    by_period = {p: (round(per_period[p], 2) if additive else None) for p in periods}
    jobs_by_period = {p: sum((r["totals"].get("jobs_by_period") or {}).get(p) or 0 for _, r in live) for p in periods}
    labels = [label for label, _ in live]
    notes = []
    for _, r in live:
        for n in r.get("notes", []):
            if n not in notes and not n.startswith("Leaves out jobs tagged"):
                notes.append(n)
    return {"columns": ["period", "group", "value", "jobs", "revenue", "pct"], "rows": rows,
            "totals": {"value": round(whole, 2) if whole is not None else None,
                       "jobs": sum(r["totals"].get("jobs") or 0 for _, r in live),
                       "revenue": round(sum(r["totals"].get("revenue") or 0 for _, r in live), 2),
                       "by_period": by_period, "jobs_by_period": jobs_by_period},
            "units_estimated": any(r.get("units_estimated") for _, r in live),
            "group_totals": group_totals, "groups": labels, "periods": periods,
            "colors": dict(zip(labels, pack.color_for(labels))), "notes": notes,
            "chart_type": "stacked_bar" if additive and len(periods) > 1 else "grouped_bar", "empty": False}


def _jobs_per_customer(s, runner, ws, notes, extra, anchor):
    w = ws[0] if len(ws) == 1 else _union(ws)
    v1 = S.to_v1(s, runner.pack, w, grain="none")
    ran, rows, rnotes = runner.rows(v1)
    if rows.empty:
        return Answer(s, ran, compute._empty("No matching jobs for these filters."), [w], "jobs_per_customer",
                      notes + rnotes, extra)
    counts = rows.groupby("customer_key")["invoice_no"].nunique()
    out_rows, labels, total = [], [], int(counts.size)
    for lo, hi, label in JPC_BUCKETS:
        n = int(((counts >= lo) & (counts <= hi)).sum())
        labels.append(label)
        out_rows.append([label, "Customers", n, None, None, round(n / total * 100, 1) if total else None])
    extra["jobs_per_customer"] = {"customers": total, "average": round(float(counts.mean()), 2),
                                  "median": float(counts.median()),
                                  "one_job_pct": round(float((counts == 1).mean() * 100), 1)}
    notes.append("Counts customers by how many jobs they had in these dates")
    result = {"columns": ["period", "group", "value", "jobs", "revenue", "pct"], "rows": out_rows,
              "totals": {"value": total, "jobs": int(rows["invoice_no"].nunique()), "revenue": None,
                         "by_period": {r[0]: r[2] for r in out_rows}, "jobs_by_period": {}},
              "units_estimated": False, "group_totals": [{"group": "Customers", "value": total, "jobs": None,
                                                          "pct": 100.0}],
              "groups": ["Customers"], "periods": labels,
              "colors": dict(zip(["Customers"], runner.pack.color_for(["Customers"]))),
              "notes": rnotes, "chart_type": "grouped_bar", "empty": False}
    ran = dict(ran, measure="customers")
    return Answer(s, ran, result, [w], "jobs_per_customer", notes, extra)


def _job_range(s, runner, ws, notes, extra, filters_extra):
    w = ws[0] if len(ws) == 1 else _union(ws)
    v1 = S.to_v1(s, runner.pack, w, filters_extra, grain="none")
    v1["measure"] = "revenue"
    ran, rows, rnotes = runner.rows(v1)
    if ran.get("measure") != "revenue":           # no dollar figures for this caller
        s2 = dict(s)
        s2.pop("agg", None)
        a = _plain(s2, runner, w, "none", notes + rnotes, extra, filters_extra, w.end)
        a.kind = "plain"
        return a
    split = ran.get("split") or "none"
    if rows.empty:
        return Answer(s, ran, compute._empty("No matching jobs for these filters."), [w], "job_range",
                      notes + rnotes, extra)
    col = {"salesperson": "salesperson", "area": "area", "product": "product", "work_type": "action"}.get(split)
    per_job = rows.groupby("invoice_no").agg(cents=("cents", "sum"))
    if col:
        first = rows.groupby("invoice_no")[col].first().fillna(compute.UNKNOWN).replace("", compute.UNKNOWN)
        per_job["group"] = first
    else:
        per_job["group"] = "All jobs"
    per_job = per_job[per_job["cents"].astype(float) > 0]
    g = per_job.groupby("group")["cents"]
    table = pd.DataFrame({"hi": g.max().astype(float) / 100, "lo": g.min().astype(float) / 100,
                          "jobs": per_job.groupby("group").size()}).sort_values("hi", ascending=False)
    groups = table.index.tolist()[:15]
    out_rows = []
    for grp in groups:
        r = table.loc[grp]
        out_rows.append(["Largest job", grp, round(float(r["hi"]), 2), int(r["jobs"]), round(float(r["hi"]), 2), None])
        out_rows.append(["Smallest job", grp, round(float(r["lo"]), 2), int(r["jobs"]), round(float(r["lo"]), 2), None])
    notes.append("Each job's whole amount for what's charted (work lines only); no customer details")
    result = {"columns": ["period", "group", "value", "jobs", "revenue", "pct"], "rows": out_rows,
              "totals": {"value": None, "jobs": int(per_job.shape[0]), "revenue": None,
                         "by_period": {"Largest job": None, "Smallest job": None}, "jobs_by_period": {}},
              "units_estimated": False,
              "group_totals": [{"group": grp, "value": round(float(table.loc[grp, "hi"]), 2),
                                "jobs": int(table.loc[grp, "jobs"]), "pct": None} for grp in groups],
              "groups": groups, "periods": ["Largest job", "Smallest job"],
              "colors": dict(zip(groups, runner.pack.color_for(groups))), "notes": rnotes,
              "chart_type": "grouped_bar", "empty": False}
    extra["job_range"] = [{"group": grp, "largest": round(float(table.loc[grp, "hi"]), 2),
                           "smallest": round(float(table.loc[grp, "lo"]), 2), "jobs": int(table.loc[grp, "jobs"])}
                          for grp in groups]
    return Answer(s, ran, result, [w], "job_range", notes, extra)


def _per_job(s, runner, ws, grain, notes, extra, filters_extra):
    w = ws[0] if len(ws) == 1 else _union(ws)
    g = grain if grain in ("none", "year", "quarter", "month") else "none"
    v1 = S.to_v1(s, runner.pack, w, filters_extra, grain=g)
    v1["measure"] = "units"
    ran, rows, rnotes = runner.rows(v1)
    if rows.empty:
        return Answer(s, ran, compute._empty("No matching jobs for these filters."), [w], "per_job",
                      notes + rnotes, extra)
    rows = rows.assign(period=compute._period(rows["issued"], g))
    counted = rows[rows["units"].notna()]
    per_inv = counted.groupby(["period", "invoice_no"])["units"].sum()
    by_p = per_inv.groupby(level=0)
    periods = sorted(rows["period"].unique())
    out_rows, by_period = [], {}
    for p in periods:
        if p in by_p.groups:
            vals = per_inv.loc[p]
            avg = round(float(vals.sum()) / len(vals), 1)
            out_rows.append([p, "Units per job", avg, int(len(vals)), None, None])
            by_period[p] = avg
        else:
            by_period[p] = None
    whole = counted.groupby("invoice_no")["units"].sum()
    total_jobs = int(rows["invoice_no"].nunique())
    blank = total_jobs - int(whole.size)
    extra["per_job"] = {"jobs": total_jobs, "jobs_with_count": int(whole.size),
                        "units": float(whole.sum()), "average": round(float(whole.mean()), 1) if whole.size else None,
                        "most": float(whole.max()) if whole.size else None,
                        "fewest": float(whole.min()) if whole.size else None}
    if blank:
        notes.append(f"{blank} of {total_jobs} jobs have no unit count; the average uses the other "
                     f"{int(whole.size)}")
    if rows["estimated"].any():
        notes.append("Units include estimates for lot bids and bundled lines")
    result = {"columns": ["period", "group", "value", "jobs", "revenue", "pct"], "rows": out_rows,
              "totals": {"value": extra["per_job"]["average"], "jobs": total_jobs, "revenue": None,
                         "by_period": by_period, "jobs_by_period": {}},
              "units_estimated": bool(rows["estimated"].any()),
              "group_totals": [{"group": "Units per job", "value": extra["per_job"]["average"],
                                "jobs": int(whole.size), "pct": None}],
              "groups": ["Units per job"], "periods": periods if g != "none" else ["All"],
              "colors": dict(zip(["Units per job"], runner.pack.color_for(
                  (s.get("filters") or {}).get("products") or ["Units per job"]))),
              "notes": rnotes, "chart_type": "single_number" if g == "none" else "grouped_bar", "empty": False}
    if g == "none":
        result["rows"] = [["All", "Units per job", extra["per_job"]["average"], int(whole.size), None, None]]
        result["totals"]["by_period"] = {"All": extra["per_job"]["average"]}
    return Answer(s, ran, result, [w], "per_job", notes, extra)


def _kind_of(token):
    import re
    t = token.lower()
    for kind, pat in (("year", r"\d{4}"), ("quarter", r"\d{4}-q[1-4]"), ("half", r"\d{4}-h[12]"),
                      ("month", r"\d{4}-\d{2}"), ("season", r"\d{4}-(?:winter|spring|summer|fall)"),
                      ("week", r"\d{4}-w\d{2}")):
        if re.fullmatch(pat, t):
            return kind
    return None


def _match_partial(ws):
    """Like with like: when the latest of several same-kind periods is still running
, the earlier ones are cut to the same days."""
    last = ws[-1]
    kind = _kind_of(last.token)
    if not last.partial or not kind or any(_kind_of(w.token) != kind for w in ws):
        return ws, ""
    span = last.end - last.start
    out = []
    for w in ws[:-1]:
        end = min(w.start + span, w.end)
        lab = (f"{w.start:%b} {w.start.day}–{end:%b} {end.day}, {end.year}" if w.start.month != end.month
               else f"{w.start:%b} {w.start.day}–{end.day}, {end.year}")
        out.append(P.Window(w.start, end, lab, w.token, False, w.natural_end))
    out.append(last)
    return out, (f"{last.label}: the data runs to {last.end:%b} {last.end.day}, so the other "
                 f"period{'s are' if len(ws) > 2 else ' is'} cut to the same days")


def _bucket(key, grain):
    """(first day, last day) of one axis period, or (None, None)."""
    if grain == "week":
        try:
            y, wk = str(key).split("-W")
            a = dt.date.fromisocalendar(int(y), int(wk), 1)
            return a, a + dt.timedelta(days=6)
        except (ValueError, TypeError):
            return None, None
    win = compute.period_window(key, grain)
    if win is None:
        return None, None
    return win[0].date(), win[1].date()


def _say(key, grain):
    if grain in ("year", "quarter", "month"):
        return compute.period_label(key, grain)
    return str(key)


def _months_in_window(year, months, w):
    for m in months:
        a = dt.date(year, int(m), 1)
        b = (a.replace(day=28) + dt.timedelta(days=4))
        b = b - dt.timedelta(days=b.day)
        if a <= w.end and b >= w.start:
            return True
    return False


def _drop_periods(result, keys):
    """Remove axis periods that can't hold data (December 2026 before it happens)."""
    if not keys:
        return
    gone = set(keys)
    result["periods"] = [p for p in result["periods"] if p not in gone]
    result["rows"] = [r for r in result["rows"] if r[0] not in gone]
    t = result.get("totals") or {}
    for k in ("by_period", "jobs_by_period"):
        if isinstance(t.get(k), dict):
            t[k] = {p: v for p, v in t[k].items() if p not in gone}


# ---------------------------------------------------------------- derived facts
def _deltas(result, ws):
    """Change from the first window to the last: overall and per group."""
    labels = [w.label for w in ws]
    a_l, b_l = labels[0], labels[-1]
    a, b = _period_value(result, a_l), _period_value(result, b_l)
    out = {"from": a_l, "to": b_l, "a": a, "b": b,
           "change": None if a is None or b is None else round(b - a, 2), "pct": _pct_change(a, b),
           "values": [{"label": l, "value": _period_value(result, l)} for l in labels], "groups": []}
    if result.get("groups") and result["groups"] != ["All jobs"]:
        for g in result["groups"]:
            ga, gb = _cell(result, a_l, g) or 0.0, _cell(result, b_l, g) or 0.0
            out["groups"].append({"group": g, "a": ga, "b": gb, "change": round(gb - ga, 2),
                                  "pct": _pct_change(ga, gb)})
    return out


def _overlay_totals(result, ws):
    """Each year's total over the calendar shown (overlay charts)."""
    vals = []
    for w in ws:
        tot = 0.0
        hit = False
        for r in result.get("rows", []):
            if r[1] == w.label and r[2] is not None:
                tot += float(r[2])
                hit = True
        vals.append({"label": w.label, "value": round(tot, 2) if hit else 0.0, "partial": w.partial})
    a, b = vals[0]["value"], vals[-1]["value"]
    return {"from": vals[0]["label"], "to": vals[-1]["label"], "a": a, "b": b,
            "change": round(b - a, 2), "pct": _pct_change(a, b), "values": vals, "groups": []}


def _growth(result, runner, ran, s, w, grain, anchor):
    """Year-over-year (period-over-period) change of each bar; a partial last bar is
    compared with the same days one period earlier, never with a whole period."""
    ps = result["periods"]
    vals = [_period_value(result, p) for p in ps]
    rows = []
    for i in range(1, len(ps)):
        rows.append({"period": ps[i], "value": vals[i], "prev": vals[i - 1], "pct": _pct_change(vals[i - 1], vals[i])})
    last_end = _bucket(ps[-1], grain)[1] if ps else None
    if rows and last_end and last_end > w.end:
        last = ps[-1]
        start, _ = compute.period_window(last, grain) or (None, None)
        if start is not None:
            sd = start.date()
            token = {"year": f"{sd.year}", "quarter": f"{sd.year}-q{(sd.month - 1) // 3 + 1}",
                     "month": f"{sd.year}-{sd.month:02d}"}[grain]
            cur = P.Window(sd, w.end, "", token, True, compute.period_window(last, grain)[1].date())
            prev = P.shift_year(cur) if grain == "year" else P.shift_period(cur)
            v1 = copy.deepcopy(ran)
            v1["time_grain"] = "none"
            v1["date_from"], v1["date_to"] = prev.start.isoformat(), prev.end.isoformat()
            _, r_prev = runner.compute(v1)
            pv = _num(r_prev["totals"]["value"]) if not r_prev.get("empty") else 0.0
            rows[-1].update(prev=pv, pct=_pct_change(pv, vals[-1]),
                            same_days=f"{prev.start:%b} {prev.start.day}–{prev.end:%b} {prev.end.day}, {prev.end.year}")
    return rows


def _average(result, s, w, grain, anchor):
    """Average per period over the window; empty periods count as 0 and a partial last
    period is left out (and said)."""
    ps = list(result["periods"])
    left_out = None
    if ps and (_bucket(ps[-1], grain)[1] or w.end) > w.end:
        left_out = ps[-1]
        ps = ps[:-1]
    vals = [(_period_value(result, p) or 0.0) for p in ps]
    if not vals:
        return None
    avg = sum(vals) / len(vals)
    hi = max(range(len(vals)), key=lambda i: vals[i])
    lo = min(range(len(vals)), key=lambda i: vals[i])
    return {"per": grain, "value": round(avg, 2), "periods": len(vals), "left_out": left_out,
            "high": {"period": ps[hi], "value": vals[hi]}, "low": {"period": ps[lo], "value": vals[lo]}}


def _rank(s, result, extra, partial_last=False, windows=None, partial_first=None):
    """Name the top (or bottom) bar(s) the question asked for."""
    rk = s.get("rank")
    if not rk or result.get("empty"):
        return
    n = int(rk.get("n") or 1)
    top = rk.get("dir", "top") == "top"
    items = []
    if rk.get("by") == "change" and extra.get("deltas", {}).get("groups"):
        gs = sorted([g for g in extra["deltas"]["groups"] if g["group"] != compute.ALL_OTHER],
                    key=lambda g: g["change"], reverse=top)
        items = [{"label": g["group"], "value": g["change"], "pct": g["pct"], "by": "change"} for g in gs[:n]]
    elif result.get("groups") and result["groups"] not in (["All jobs"],) and len(result["groups"]) > 1 \
            and len(result.get("periods", [])) <= 1:
        gt = [g for g in result.get("group_totals", []) if g["group"] not in ("All other",) and g["value"] is not None]
        gt = sorted(gt, key=lambda g: float(g["value"]), reverse=top)
        items = [{"label": g["group"], "value": g["value"], "by": "value"} for g in gt[:n]]
    else:
        ps = list(result.get("periods", []))
        if partial_last and ps and not top:
            ps = ps[:-1]                       # a partial period isn't "the worst"
        if partial_first and ps and not top and ps[0] == partial_first:
            ps = ps[1:]
        bp = (result.get("totals") or {}).get("by_period", {})
        pv = [(p, _num(bp.get(p))) for p in ps if _num(bp.get(p)) is not None]
        pv = sorted(pv, key=lambda x: x[1], reverse=top)
        items = [{"label": p, "value": v, "by": "value"} for p, v in pv[:n]]
        if len(result.get("groups", [])) > 1 and result["groups"] != ["All jobs"]:
            gt = sorted([g for g in result.get("group_totals", []) if g["value"] is not None
                         and g["group"] != compute.ALL_OTHER],
                        key=lambda g: float(g["value"]), reverse=top)
            extra["rank_groups"] = [{"label": g["group"], "value": g["value"]} for g in gt[:n]]
    extra["rank"] = {"dir": "top" if top else "bottom", "n": n, "items": items}
