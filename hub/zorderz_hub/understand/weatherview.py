"""Weather beside a sales chart.

A v2 spec may carry   "weather": {"var": "tmax", "view": "overlay", "lag": {"n": 1, "unit": "week"}}

  var   tmax (average daily high, F) | tmin (average overnight low, F) | tavg (average temperature, F) |
        prcp (rainfall, inches) | rain_days | hot_days (days with a high of 90 F or more)
  view  overlay  the weather as a line on a second axis over the chart's periods, and how closely they move
        scatter  the same numbers, shown first as one dot per period (weather across, sales up)
        bands    day by day: what an average day sold, by how hot or wet the day was ("on hot days")
        only     the weather by itself ("how much did it rain in Cedar Hills last year")
  lag   the weather that many days/weeks/months BEFORE the sales ("jobs the week after it rains")
  by_group true: on a chart split by PLACE (region, town,
        coastal / inland, coast band, tax area, safety-site site or council, ZIP), the weather of EACH group's own
        ZIPs -- one line per group on the second axis, and a link per group. A split that is not a place (product,
        salesperson …) shares one weather and says so.

Where: the weather of the ZIP codes the chart's own jobs were in, each ZIP weighted by its number of jobs
(a chart filtered to Cedar Hills gets Cedar Hills's weather). When: a job counts on its invoice date, as everywhere in
Charts. Every number is NOAA's (weather.py) or the hub's own count; nothing here is a model's.

Two honest checks the caption repeats: a correlation over fewer than 6 periods isn't given, and for a chart by
month or week over two years or more, the link is ALSO measured against the usual for each month (or week) of
the year, because hot months are summer months: if summer is busy for other reasons, the plain link overstates
what the heat itself does.
"""
import datetime as dt
import math

import numpy as np
import pandas as pd

from .. import compute
from .. import weather as W

VARS = {
    "tmax": {"label": "Average daily high", "short": "high", "unit": "°F", "how": "mean", "noun": "average daily high"},
    "tmin": {"label": "Average overnight low", "short": "low", "unit": "°F", "how": "mean", "noun": "average overnight low"},
    "tavg": {"label": "Average temperature", "short": "temp", "unit": "°F", "how": "mean", "noun": "average temperature"},
    "prcp": {"label": "Rainfall", "short": "rain", "unit": "in", "how": "sum", "noun": "rainfall"},
    "rain_days": {"label": "Rainy days (0.1 in or more)", "short": "rainy days", "unit": "days", "how": "count",
                  "noun": "number of rainy days"},
    "hot_days": {"label": "Hot days (90°F or more)", "short": "hot days", "unit": "days", "how": "count",
                 "noun": "number of hot days"},
}
VIEWS = ("overlay", "scatter", "bands", "only")
LAG_UNITS = ("day", "week", "month")
TIME_GRAINS = ("year", "quarter", "month", "week", "day")
PARTS = ("month_of_year", "quarter_of_year", "week_of_year", "season")
MIN_POINTS = 12                    # fewer, and pure noise reads as "a strong link" too often
MIN_MEAN_COVER = 0.5               # a period's average needs half its days
MIN_SUM_COVER = 0.9                # a total (rain, days) needs nine days in ten: a missing day would hide rain
BANDS = {
    "tmax": [(None, 60, "Under 60°F"), (60, 70, "60–69°F"), (70, 80, "70–79°F"), (80, 90, "80–89°F"),
             (90, None, "90°F and up")],
    "tavg": [(None, 55, "Under 55°F"), (55, 65, "55–64°F"), (65, 75, "65–74°F"), (75, None, "75°F and up")],
    "tmin": [(None, 45, "Under 45°F"), (45, 55, "45–54°F"), (55, 65, "55–64°F"), (65, None, "65°F and up")],
    "prcp": [(None, 0.01, "Dry"), (0.01, 0.25, "Some rain (under ¼ in)"), (0.25, None, "Rain (¼ in or more)")],
}
BAND_VAR = {"tmax": "tmax", "tavg": "tavg", "tmin": "tmin", "prcp": "prcp", "rain_days": "prcp", "hot_days": "tmax"}
ADDITIVE = ("revenue", "jobs", "units")
MEASURE_WORD = {"revenue": "revenue", "jobs": "jobs", "units": "units", "customers": "customers",
                "avg_job": "the average job"}


# ---------------------------------------------------------------- spec checks
def check(w):
    """Plain-words problems with a spec's "weather" (spec2.check calls this)."""
    if w is None:
        return []
    if not isinstance(w, dict):
        return ["weather must be an object"]
    out = []
    if w.get("var", "tmax") not in VARS:
        out.append(f"weather.var {w.get('var')!r} is not one of {', '.join(VARS)}")
    if w.get("view", "overlay") not in VIEWS:
        out.append(f"weather.view {w.get('view')!r} is not one of {', '.join(VIEWS)}")
    lag = w.get("lag")
    if lag not in (None, {}):
        if not isinstance(lag, dict) or lag.get("unit") not in LAG_UNITS or not isinstance(lag.get("n"), int) \
                or not 0 <= lag["n"] <= 12:
            out.append("weather.lag must be {n: 0-12, unit: day|week|month}")
    for k in ("color_by", "labels", "by_group"):      # bars colored by the weather; the weather on each bar
        if k in w and not isinstance(w[k], bool):     # by_group -- the weather of each group's own ZIPs
            out.append(f"weather.{k} must be true or false")
    extra = set(w) - {"var", "view", "lag", "color_by", "labels", "by_group"}
    if extra:
        out.append("weather has unknown keys: " + ", ".join(sorted(extra)))
    return out


def full(w):
    w = dict(w or {})
    w.setdefault("var", "tmax")
    w.setdefault("view", "overlay")
    lag = w.get("lag") or {}
    w["lag"] = {"n": int(lag.get("n", 0)), "unit": lag.get("unit", "day")} if lag.get("n") else None
    for k in ("color_by", "labels", "by_group"):      # kept only when on (chips, saved charts, the chat)
        if w.get(k) is True:
            w[k] = True
        else:
            w.pop(k, None)
    return w


# ---------------------------------------------------------------- where the jobs were
def weights_for(runner, ran, store_zips, filters, named_first=False):
    """({zip: jobs}, jobs in the chart, jobs placed, basis words). The chart's own jobs, by customer ZIP.
    named_first (the weather by itself): a town's own ZIP codes before where its jobs were."""
    if named_first:
        asked = [z for z in (filters.get("zips") or []) if z in store_zips] or \
            area_zips(runner.pack, filters.get("areas") or [], store_zips)
        if asked:
            return {z: 1 for z in asked}, 0, 0, ("ZIP " + ", ".join(asked[:4]) + (" …" if len(asked) > 4 else ""))
    _s, rows, _n = runner.rows(ran)
    zmap = compute.zip_of_customer(runner.data)
    jobs = rows.drop_duplicates("invoice_no") if len(rows) else rows
    total = int(len(jobs))
    counts = {}
    for k in (jobs["customer_key"].astype(str) if total else []):
        z = zmap.get(k, "")
        if z in store_zips:
            counts[z] = counts.get(z, 0) + 1
    placed = sum(counts.values())
    if counts:
        n = len(counts)
        return counts, total, placed, (f"the {n} ZIP codes these jobs were in, weighted by jobs" if n > 1
                                       else "the ZIP code these jobs were in")
    asked = [z for z in (filters.get("zips") or []) if z in store_zips]
    if asked:
        return {z: 1 for z in asked}, total, 0, "the ZIP codes asked about"
    towns = area_zips(runner.pack, filters.get("areas") or [], store_zips)
    if towns:
        return {z: 1 for z in towns}, total, 0, f"the ZIP code{'s' if len(towns) > 1 else ''} named for " + \
            ", ".join(filters["areas"][:3])
    # No job with a known ZIP (a filter with no ZIPs, or no jobs at all): where ALL the jobs were.
    work = runner.data.work.drop_duplicates("invoice_no")
    for k in work["customer_key"].astype(str):
        z = zmap.get(k, "")
        if z in store_zips:
            counts[z] = counts.get(z, 0) + 1
    if counts:
        return counts, total, 0, "where all of your jobs were (these jobs have no ZIP code)"
    return {z: 1 for z in store_zips}, total, 0, "every ZIP code on the map, equally"


def area_zips(pack, areas, store_zips):
    """ZIP codes whose name on the map is one of the towns (the pack's own ZIP names: "00011 Northtown")."""
    want = {v.casefold() for a in areas for v in pack.area_variants(a)}
    return sorted(z for z, n in (getattr(pack, "zips", {}) or {}).items() if z in store_zips and str(n).casefold() in want)


def period_keys(window, grain):
    """The chart's period keys for a window (a weather-only chart with no jobs to give them)."""
    if grain in TIME_GRAINS and grain not in ("week", "day"):
        return compute.period_keys(window.start, window.end, grain)
    if grain in ("week", "day"):
        days = pd.Series(pd.date_range(pd.Timestamp(window.start), pd.Timestamp(window.end), freq="D"))
        return list(dict.fromkeys(compute._period(days, grain).tolist()))
    if grain in PARTS:
        return list(compute.DATE_PART_KEYS[grain])
    return []


# ---------------------------------------------------------------- days
def _day_filter(days, s):
    """The days the sales chart counts: only some months ("Decembers"), each year cut at a day."""
    keep = pd.Series(True, index=days)
    if s.get("months"):
        keep &= days.month.isin([int(m) for m in s["months"]])
    cut = s.get("to_date_cut")
    if cut:
        keep &= (days.month * 100 + days.day) <= int(cut[0]) * 100 + int(cut[1])
    return keep.values


def shift_days(days, lag):
    """The weather day for each sales day: the same day, or the lag earlier (a month earlier is the same date
    of the month before, clamped to its last day). One rule for every view, so a lag never drops days."""
    days = pd.DatetimeIndex(days)
    if not lag or not lag.get("n"):
        return days
    n, unit = lag["n"], lag["unit"]
    if unit == "day":
        return days - pd.Timedelta(days=n)
    if unit == "week":
        return days - pd.Timedelta(days=7 * n)
    return pd.DatetimeIndex([d - pd.DateOffset(months=n) for d in days])


def _lag_days(lag):
    """The most days a lag reaches back (for how much weather to read)."""
    if not lag or not lag.get("n"):
        return 0
    return lag["n"] * {"day": 1, "week": 7, "month": 31}[lag["unit"]]


def _weather_on(daily, sales_days, lag):
    """The weather rows for these sales days (NaN where none), indexed by the sales day."""
    w = daily.reindex(shift_days(sales_days, lag))
    w.index = pd.DatetimeIndex(sales_days)
    return w


def _summarise(sub, var):
    """One period's value from its days (a DataFrame of tmax/tmin/tavg/prcp, possibly with NaN days)."""
    n = len(sub)
    if not n:
        return None
    col = BAND_VAR[var] if var in ("rain_days", "hot_days") else var
    have = sub[col].notna()
    cover = have.mean()
    how = VARS[var]["how"]
    if how == "mean":
        if cover < MIN_MEAN_COVER:
            return None
        return float(sub.loc[have, col].mean())
    if cover < MIN_SUM_COVER:
        return None
    vals = sub.loc[have, col]
    scale = n / max(1, int(have.sum()))             # a missing day or two: scaled up, never left as a hole
    if var == "prcp":
        return float(vals.sum() * scale)
    if var == "rain_days":
        return float((vals >= W.RAIN_DAY_MM / W.MM_PER_IN).sum() * scale)
    return float((vals >= W.HOT_DAY_F).sum() * scale)


def _round(v, var):
    if v is None or (isinstance(v, float) and math.isnan(v)):
        return None
    if VARS[var]["unit"] == "in":
        return round(v, 2)
    if VARS[var]["unit"] == "days":
        return round(v, 1)
    return round(v, 1)


def _part_of(days, grain):
    if grain == "month_of_year":
        return pd.Index([f"{m:02d}" for m in days.month])
    if grain == "quarter_of_year":
        return pd.Index([f"Q{q}" for q in days.quarter])
    if grain == "week_of_year":
        return pd.Index([f"W{w:02d}" for w in days.isocalendar().week])
    return pd.Index([compute.SEASON_OF_MONTH[m] for m in days.month])


def _days_per_year(grain, key):
    """How many days a date part holds in an average year (4-year cycle, so February counts 28.25)."""
    days = pd.date_range("2021-01-01", "2024-12-31", freq="D")
    return float((_part_of(days, grain) == str(key)).sum()) / 4.0


def _per_year(sub, var, per_year):
    """A total (rain, rainy days, hot days) for an average year of a date part: the average per counted day
    times the part's days in a year, so a window that cuts a year doesn't shrink it."""
    col = BAND_VAR[var] if var in ("rain_days", "hot_days") else var
    vals = sub[col].dropna()
    if not len(vals) or len(vals) < MIN_SUM_COVER * len(sub):
        return None
    if var == "prcp":
        daily_mean = float(vals.mean())
    elif var == "rain_days":
        daily_mean = float((vals >= W.RAIN_DAY_MM / W.MM_PER_IN).mean())
    else:
        daily_mean = float((vals >= W.HOT_DAY_F).mean())
    return daily_mean * per_year


def period_values(daily, keys, grain, window, var, s, lag):
    """{period key: value} for the chart's periods. Only the window's days count (the first and last
    periods may be partial), and only the days the sales chart counts (months, a cut day)."""
    from .execute import _bucket
    out = {}
    if grain in TIME_GRAINS:
        for k in keys:
            if grain == "day":
                try:
                    a = b = dt.date.fromisoformat(str(k))
                except ValueError:
                    continue
            else:
                a, b = _bucket(k, grain)
            if a is None:
                continue
            a, b = max(a, window.start), min(b, window.end)
            if a > b:
                continue
            days = pd.date_range(pd.Timestamp(a), pd.Timestamp(b), freq="D")
            days = days[_day_filter(days, s)]                  # the sales days this period counts
            out[k] = _summarise(_weather_on(daily, days, lag), var)
        return out
    # a date part pooled over the years: "the average July"
    days = pd.date_range(pd.Timestamp(window.start), pd.Timestamp(window.end), freq="D")
    days = days[_day_filter(days, s)]
    if not len(days):
        return out
    sub = _weather_on(daily, days, lag)
    parts = _part_of(days, grain)                           # each sales day's part; its weather may be earlier
    for k in keys:
        part = sub[np.asarray(parts == str(k))]
        if not len(part):
            continue
        if VARS[var]["how"] == "mean":
            out[k] = _summarise(part, var)
        else:
            out[k] = _per_year(part, var, _days_per_year(grain, k))
    return out


# ---------------------------------------------------------------- how closely they move
def pearson(xs, ys):
    x, y = np.asarray(xs, dtype="float64"), np.asarray(ys, dtype="float64")
    if len(x) < 3 or np.std(x) <= 1e-9 * max(1.0, abs(float(np.mean(x)))) or \
            np.std(y) <= 1e-9 * max(1.0, abs(float(np.mean(y)))):
        return None                                        # one of them never moved: no link to measure
    return float(np.corrcoef(x, y)[0, 1])


def strength(r):
    a = abs(r)
    if a < 0.2:
        return "little or no link"
    if a < 0.4:
        return "a weak link"
    if a < 0.6:
        return "a moderate link"
    return "a strong link"


def _season_key(k, grain):
    k = str(k)
    if grain == "month":
        return k[5:7]
    if grain == "week":
        if "-W" not in k:
            return None
        w = k.split("-W")[1]
        return "52" if w == "53" else w                 # ISO week 53 (some years) goes with week 52
    return None


def correlation(values, totals, grain, skip):
    """The plain link, and (by month or week over 2+ years) the link against the usual for that time of year.
    `reason` says why there is no r: too few periods, or one of the two never moved."""
    pairs = [(k, values[k], totals.get(k)) for k in values
             if k not in skip and values[k] is not None and totals.get(k) is not None]
    out = {"n": len(pairs)}
    if len(pairs) < MIN_POINTS:
        out["r"], out["reason"] = None, "few"
        return out
    r = pearson([p[1] for p in pairs], [p[2] for p in pairs])
    out["r"] = None if r is None else round(r, 2)
    if r is None:
        out["reason"] = "flat"
        return out
    out["strength"] = strength(r)
    years = len({str(k)[:4] for k, _, _ in pairs})
    if grain in PARTS or (grain == "quarter" and years < 3):
        out["season"] = True                            # the periods are the seasons themselves
    if grain in ("month", "week"):
        need = 24 if grain == "month" else 104
        seasons = {}
        for k, x, y in pairs:
            sk = _season_key(k, grain)
            if sk is not None:
                seasons.setdefault(sk, []).append((k, x, y))
        kept = [p for v in seasons.values() if len(v) >= 2 for p in v]      # a time of year seen once can't be compared
        if len(kept) >= need and years >= 2:
            mx = {sk: np.mean([p[1] for p in v]) for sk, v in seasons.items()}
            my = {sk: np.mean([p[2] for p in v]) for sk, v in seasons.items()}
            ax = [x - mx[_season_key(k, grain)] for k, x, y in kept]
            ay = [y - my[_season_key(k, grain)] for k, x, y in kept]
            ra = pearson(ax, ay)
            if ra is not None:
                out["r_usual"] = round(ra, 2)
                out["n_usual"] = len(kept)
                out["strength_usual"] = strength(ra)
        if "r_usual" not in out:
            out["season"] = True                        # by month or week with no usual-for-the-time-of-year check
    return out


# ---------------------------------------------------------------- day by day
def bands(runner, s, window, filters_extra, daily, var, lag):
    """What an average day sold, by the day's weather. Also against a usual day of the same month and year,
    so a band full of summer days isn't credited with summer's business."""
    from . import spec2 as S
    measure = s.get("measure", "revenue")
    if measure not in ADDITIVE + ("avg_job",):
        return None, "Day-by-day weather works with revenue, jobs or units."
    base = dict(s, split=None, facet=None, grain="day", date_part=None, compare=None, agg=None, rank=None,
                series=None, chart_type=None)

    def series(m):
        v1 = S.to_v1(dict(base, measure=m), runner.pack, window, filters_extra, grain="day")
        _ran, r = runner.compute(v1)
        by = (r.get("totals") or {}).get("by_period") or {}
        return pd.Series({pd.Timestamp(k): float(v or 0) for k, v in by.items()}, dtype="float64")

    days = pd.date_range(pd.Timestamp(window.start), pd.Timestamp(window.end), freq="D")
    days = days[_day_filter(days, s)]
    if measure == "avg_job":
        rev = series("revenue").reindex(days, fill_value=0.0)
        num = series("jobs").reindex(days, fill_value=0.0)
    else:
        rev = series(measure).reindex(days, fill_value=0.0)
        num = None
    col = BAND_VAR[var]
    x = _weather_on(daily, days, lag)[col]                     # day d sold after day d - lag's weather
    ok = x.notna()
    if ok.sum() < 30:
        return None, "Too few days with weather to compare."
    frame = pd.DataFrame({"x": x[ok], "y": rev[ok]})
    if num is not None:
        frame["n"] = num[ok]
    ym = frame.index.year * 100 + frame.index.month
    usual = frame.groupby(ym)["y"].transform("mean")                # the usual day of that month and year
    frame["usual"] = usual
    edges = BANDS[col] if var not in ("hot_days",) else [(None, W.HOT_DAY_F, "Under 90°F"), (W.HOT_DAY_F, None, "90°F and up")]
    if var == "rain_days":
        edges = [(None, W.RAIN_DAY_MM / W.MM_PER_IN, "Dry or a trace"), (W.RAIN_DAY_MM / W.MM_PER_IN, None, "Rain (0.1 in or more)")]
    out = []
    for lo, hi, label in edges:
        m = pd.Series(True, index=frame.index)
        if lo is not None:
            m &= frame["x"] >= lo
        if hi is not None:
            m &= frame["x"] < hi
        sub = frame[m]
        nd = int(len(sub))
        if not nd:
            out.append({"label": label, "days": 0, "per_day": None, "vs_usual": None})
            continue
        if num is not None:
            jobs = float(sub["n"].sum())
            per = (float(sub["y"].sum()) / jobs) if jobs else None
            vs = None
        else:
            per = float(sub["y"].sum()) / nd
            exp = float(sub["usual"].sum())
            vs = round(float(sub["y"].sum()) / exp * 100) if exp > 0 else None
        out.append({"label": label, "days": nd, "per_day": None if per is None else round(per, 2), "vs_usual": vs,
                    "few": nd < 10})
    return {"bands": out, "days": int(ok.sum()), "per": "job" if num is not None else "day",
            "per_day_all": round(float(frame["y"].sum()) / len(frame), 2) if num is None else
            (round(float(frame["y"].sum()) / float(frame["n"].sum()), 2) if float(frame["n"].sum()) else None)}, None


# ---------------------------------------------------------------- putting it on the answer
PLACE_SPLITS_WX = ("region", "area", "coast", "coast_band", "tax_area", "safety_site", "safety_council")
MAX_WX_GROUPS = 25                 # a map by town has 100+ groups; the weather is reconstructed per group


def by_group(out, s, runner, ran, window, grain, result, store, store_zips, first, var, lag, skip, notes):
    """the weather of each group's own jobs' ZIPs, for a chart split by a place. Fills out["groups"] =
    {group: {values, corr, zips, jobs}} in the chart's group order ("All other" has no ZIPs of its own and is
    skipped). A split that is not a place gets no per-group weather and one note."""
    split = (ran or {}).get("split") or s.get("split") or "none"
    # the chart's groups, less "All other" (no ZIPs of its own) and the sentinels ("(not placed)", "(unknown)" …,
    # whose weather would be the chart-wide line again); at most MAX_WX_GROUPS, in the chart's order
    all_groups = [g for g in (result.get("groups") or []) if g != compute.ALL_OTHER and not str(g).startswith("(")]
    groups = all_groups[:MAX_WX_GROUPS]
    if split in ("none", "") or not groups:
        notes.append("Weather per group needs a chart split by a place (region, town, coastal or inland …).")
        out["by_group"] = "no_split"
        return
    if split not in PLACE_SPLITS_WX:
        notes.append(f"Every {split.replace('_', ' ')} shares the same weather (the split is not a place), so one line is shown.")
        out["by_group"] = "not_a_place"
        return
    v1, rows, _n = runner.rows(ran)
    try:
        grouped, _ov, empty = compute.group_rows(runner.data, runner.pack, v1, rows, [], v1.get("windows") or [])
    except Exception:
        out["by_group"] = "no_split"
        return
    if empty is not None or "group" not in grouped:
        out["by_group"] = "no_split"
        return
    zmap = compute.zip_of_customer(runner.data)
    cols = result.get("columns") or []
    ip, ig, iv = (cols.index(c) if c in cols else None for c in ("period", "group", "value"))
    per_group_totals = {}
    if ip is not None and ig is not None and iv is not None:
        for r in result.get("rows") or []:
            per_group_totals.setdefault(r[ig], {})[r[ip]] = _num(r[iv])
    keys = list(result.get("periods") or [])
    start = max(first, window.start - dt.timedelta(days=_lag_days(lag) + 40))
    per, weights, njobs = {}, {}, {}
    for g in groups:
        sub = grouped[grouped["group"].eq(g)]
        jobs = sub.drop_duplicates("invoice_no") if len(sub) and "invoice_no" in sub else sub
        counts = {}
        for k in jobs["customer_key"].astype(str):
            z = zmap.get(k, "")
            if z in store_zips:
                counts[z] = counts.get(z, 0) + 1
        if not counts and split == "area":
            counts = {z: 1 for z in area_zips(runner.pack, [g], store_zips)}
        if not counts and split == "region" and getattr(runner.pack, "regions", None):
            counts = {z: 1 for z in area_zips(runner.pack, runner.pack.regions.get(g) or [], store_zips)}
        njobs[g] = int(len(jobs))
        if not counts:
            per[g] = {"status": "no_zips", "jobs": njobs[g], "zips": 0, "values": {}}
            continue
        weights[g] = counts
    # the frame is
    # filtered and pivoted once for every group, not once per group
    dailies = store.daily_many(weights, start, window.end) if hasattr(store, "daily_many") else \
        {g: store.daily(w, start, window.end) for g, w in weights.items()}
    for g in groups:
        if g in per:
            continue
        counts, daily, jobs = weights[g], dailies[g], njobs[g]
        vals = period_values(daily, keys, grain, window, var, s, lag)
        values = {k: _round(v, var) for k, v in vals.items()}
        if VARS[var]["how"] != "mean":
            for k in skip:
                values[k] = None
        entry = {"status": "ok", "jobs": jobs, "zips": len(counts), "values": values}
        if out.get("view") != "only":
            entry["corr"] = correlation({k: v for k, v in vals.items()}, per_group_totals.get(g, {}), grain, set(skip))
        per[g] = entry
    out["groups"] = per
    out["by_group"] = "ok"
    shown = [g for g, e in per.items() if e.get("status") == "ok"]
    notes.append(f"Weather per {split.replace('_', ' ')}: each line is the average over that group's own jobs' ZIP codes"
                 + (f" ({len(shown)} of {len(groups)} groups have placed jobs)." if len(shown) < len(groups) else "."))
    if len(all_groups) > len(groups):
        out["groups_left_out"] = len(all_groups) - len(groups)
        notes.append(f"The weather is drawn for the first {len(groups)} groups; {len(all_groups) - len(groups)} more are not.")


def unavailable(reason):
    return {"status": "missing", "message": reason}


def partial_keys(keys, grain, window):
    """Periods the window cuts (the first or last month of a range that starts or ends mid-month): left out
    of the link, because a part-month's sales and a part-month's weather aren't like the others."""
    from .execute import _bucket
    if grain not in ("year", "quarter", "month", "week"):
        return []
    out = []
    for k in (keys[:1] + keys[-1:]) if keys else []:
        a, b = _bucket(k, grain)
        if a is not None and (a < window.start or b > window.end) and k not in out:
            out.append(k)
    return out


def attach(s, runner, window, grain, result, ran, notes, filters_extra):
    """Add result["weather"]; never raise (a weather problem must not cost the sales chart)."""
    try:
        return _attach(s, runner, window, grain, result, ran, notes, filters_extra)
    except Exception:                                     # pragma: no cover - never shown raw
        result["weather"] = unavailable("The weather couldn't be added to this chart.")
        notes.append("The weather couldn't be added to this chart.")
        return None


def _attach(s, runner, window, grain, result, ran, notes, filters_extra):
    w = full(s.get("weather"))
    var, view, lag = w["var"], w["view"], w["lag"]
    store = getattr(runner, "weather", None)
    if store is None:
        result["weather"] = unavailable("Weather is turned off on the hub.")
        notes.append("Weather is turned off on the hub.")
        return None
    first, last, prelim = store.coverage()
    if last is None:
        from .. import polite
        msg = ("The weather hasn't been downloaded yet. The hub downloads it "
               f"{polite.when_text(getattr(store, 'cfg', None))} (about 20 minutes the first time); on the hub computer now: "
               "zorderz_hub weather update")
        result["weather"] = unavailable(msg)
        notes.append(msg)
        return None
    if result.get("empty") and view != "only":
        result["weather"] = unavailable("No jobs to compare with the weather.")
        return None
    if window.end < first:
        msg = f"NOAA's weather here starts {first:%b} {first.day}, {first.year}, after this chart ends."
        result["weather"] = unavailable(msg)
        notes.append(msg)
        return None
    zips = store.zips()
    weights, total, placed, basis = weights_for(runner, ran, set(zips), s.get("filters") or {}, named_first=view == "only")
    start = max(first, window.start - dt.timedelta(days=_lag_days(lag) + 40))
    daily = store.daily(weights, start, window.end)
    info = VARS[var]
    noun = {"year": "year", "quarter": "quarter", "month": "month", "week": "week", "day": "day",
            "month_of_year": "month", "quarter_of_year": "quarter", "week_of_year": "week", "season": "season"}.get(grain, "period")
    out = {"status": "ok", "var": var, "label": info["label"], "unit": info["unit"], "how": info["how"], "noun": noun,
           "view": view, "lag": lag, "basis": basis, "jobs": total, "jobs_placed": placed,
           "zips": len(weights), "source": W.SOURCE, "first_day": first.isoformat(),
           "through": last.isoformat(), "prelim_from": _ym_day(prelim)}
    keys = list(result.get("periods") or [])
    labelled = None
    if view == "only" and not keys:                       # no jobs: the weather's own calendar
        keys = period_keys(window, grain)
        if grain in ("week", "day") or grain in PARTS:
            from .execute import _label_key
            one_year = window.start.year == window.end.year
            labelled = {k: _label_key(k, grain, one_year) for k in keys}
    out_periods = list(keys)
    skip = [] if (s.get("months") or s.get("to_date_cut")) else partial_keys(keys, grain, window)
    if grain == "day_of_week":
        notes.append("Weather isn't shown by weekday (every weekday has the same weather over a year).")
        result["weather"] = dict(out, status="not_by_weekday")
        return out
    if view in ("overlay", "scatter", "only"):
        if grain in TIME_GRAINS or grain in PARTS:
            vals = period_values(daily, keys, grain, window, var, s, lag)
            out["values"] = {k: _round(v, var) for k, v in vals.items()}
            totals = {k: _num((result.get("totals") or {}).get("by_period", {}).get(k)) for k in keys}
            if view != "only":
                c = correlation({k: v for k, v in vals.items()}, totals, grain, set(skip))
                out["corr"] = c
            out["excluded"] = skip
            if info["how"] != "mean":
                for k in skip:                             # a part month's rain total isn't comparable: left blank
                    out["values"][k] = None
            missing = [k for k in keys if out["values"].get(k) is None and k not in skip]
            if missing and len(missing) < len(keys):
                out["no_weather"] = missing[:24]
            out["periods"] = out_periods
            if labelled:
                out["values"] = {labelled.get(k, k): v for k, v in out["values"].items()}
                out["periods"] = [labelled.get(k, k) for k in out_periods]
        else:
            notes.append("Weather is shown on a chart over time (by month, week …); this chart has one total.")
            out["status"] = "needs_time"
    if w.get("by_group") and view in ("overlay", "scatter") and out.get("values") is not None:
        by_group(out, s, runner, ran, window, grain, result, store, set(zips), first, var, lag, skip, notes)
    if view == "bands":
        b, why = bands(runner, s, window, filters_extra, daily, var, lag)
        if b is None:
            notes.append(why)
            out["status"] = "no_bands"
        else:
            out.update(b)
            out["band_var"] = BAND_VAR[var]
    result["weather"] = out
    if view == "only":
        notes.append(f"Weather: {W.SOURCE}, the average over {basis}.")
    else:
        notes.append(f"Weather: {W.SOURCE}, the average over {basis}; a job counts on its invoice date.")
    if last < window.end:
        notes.append(f"NOAA's weather runs to {last:%b} {last.day}, {last.year}; later days have none yet.")
    if prelim and _ym_day(prelim) and dt.date.fromisoformat(_ym_day(prelim)) <= window.end:
        d = dt.date.fromisoformat(_ym_day(prelim))
        notes.append(f"Weather from {d:%b} {d.year} on is NOAA's preliminary data (re-checked within two months).")
    if first > window.start:
        notes.append(f"Weather starts {first:%b} {first.day}, {first.year}.")
    return out


def _ym_day(ym):
    if not ym:
        return None
    return f"{ym[:4]}-{ym[4:6]}-01"


def _num(v):
    try:
        if v is None or (isinstance(v, float) and math.isnan(v)):
            return None
        return float(v)
    except (TypeError, ValueError):
        return None


# ---------------------------------------------------------------- words
def lag_words(lag):
    if not lag:
        return ""
    n, unit = lag["n"], lag["unit"]
    return f"{'the' if n == 1 else n} {unit}{'' if n == 1 else 's'} before"


def describe(wx):
    """'How I read this' line."""
    if not wx or wx.get("status") == "missing":
        return None
    v = VARS.get(wx.get("var"), VARS["tmax"])
    view = {"overlay": "a line on a second axis", "scatter": "one dot per period", "bands": "days grouped by weather",
            "only": "by itself"}.get(wx.get("view"), "")
    lag = wx.get("lag")
    more = ""
    if wx.get("color_by"):
        more += ", each bar colored by it (warm to cool)" if v.get("kind", "temp") != "rain" and wx.get("var") != "prcp" \
            else ", each bar colored by it (dry to wet)"
    if wx.get("labels"):
        more += ", written on each bar"
    return f"Weather: {v['label'].lower()}" + (f", {lag_words(lag)}" if lag else "") + (f", as {view}" if view else "") + more


def _money(v):
    return f"${v:,.0f}" if v is not None else "—"


def sentences(wx, measure, period_noun):
    """One to three plain sentences about the link (or the bands). Numbers only from wx. They say how the two
    moved, never that the weather caused it."""
    if not wx or wx.get("status") != "ok":
        return []
    v = VARS[wx["var"]]
    out = []
    mword = MEASURE_WORD.get(measure, measure)
    noun = v["noun"]
    lagged = (" " + lag_words(wx["lag"])) if wx.get("lag") else ""
    if wx.get("view") == "bands" and wx.get("bands"):
        bs = [b for b in wx["bands"] if b.get("days") and b.get("per_day") is not None and not b.get("few")]
        low = lambda t: t[:1].lower() + t[1:]
        if len(bs) >= 2:
            if wx.get("per") == "job":                # average job: dollars a job, no "usual day"
                hi, lo = max(bs, key=lambda b: b["per_day"]), min(bs, key=lambda b: b["per_day"])
                out.append(f"The average job on {low(hi['label'])} days{lagged} was {_money(hi['per_day'])}; on "
                           f"{low(lo['label'])} days, {_money(lo['per_day'])} ({hi['days']} and {lo['days']} days)")
            elif all(b.get("vs_usual") is not None for b in bs):
                hi, lo = max(bs, key=lambda b: b["vs_usual"]), min(bs, key=lambda b: b["vs_usual"])
                out.append(f"Against a usual day of the same month, {low(hi['label'])} days{lagged} came to "
                           f"{hi['vs_usual']}% and {low(lo['label'])} days to {lo['vs_usual']}% "
                           f"({hi['days']} and {lo['days']} days)")
        return out
    c = wx.get("corr") or {}
    if wx.get("view") in ("overlay", "scatter"):
        if c.get("r") is None:
            if c.get("reason") == "flat":
                out.append(f"The {noun}{lagged} didn't change across these {period_noun}s, so there's no link to measure")
            elif c.get("n") is not None:
                out.append(f"Too few {period_noun}s ({c.get('n', 0)}; Charts needs {MIN_POINTS}) to say how {mword} "
                           f"and the {noun} move together")
            return out
        r = c["r"]
        if abs(r) < 0.2:
            out.append(f"Across {c['n']} {period_noun}s, {mword} and the {noun}{lagged} showed little or no link "
                       f"(r = {r:+.2f})")
        else:
            way = "moved together" if r > 0 else "moved in opposite directions"
            out.append(f"Across {c['n']} {period_noun}s, {mword} and the {noun}{lagged} {way} (r = {r:+.2f}, "
                       f"{c['strength']})")
        if c.get("r_usual") is not None:
            ru = c["r_usual"]
            word = "month" if period_noun == "month" else "week"
            tail = " — the time of year explains most of it" if abs(ru) < 0.2 <= abs(r) else ""
            out.append(f"Against the usual for each {word} of the year: {c['strength_usual']} (r = {ru:+.2f}){tail}")
        elif c.get("season") and abs(r) >= 0.2:
            out.append("These periods are mostly the seasons themselves, so this can't tell the weather from the "
                       "time of year; ask over two years or more by month to compare with the usual month")
    return out


def only_title(answer, wx, pack):
    """The title of a weather-only chart: "Rainfall in Cedar Hills by month, 2025"."""
    from . import describe as D
    s = answer.spec
    v = VARS[wx.get("var", "tmax")]
    f = s.get("filters") or {}
    where = ""
    if f.get("areas"):
        where = " in " + D._names(f["areas"], 3)
    elif f.get("zips"):
        where = " in ZIP " + D._names(f["zips"], 4)
    grain = s.get("date_part") or s.get("grain")
    by = (" by " + D.PART[grain]) if grain in D.PART else ((" by " + D.GRAIN[grain]) if grain in D.GRAIN else "")
    when = D._when(answer)
    return f"{v['label']}{where}{by}" + (f", {when}" if when else "")


def only_sentence(wx, grain=None):
    """'Rainfall totalled 9.8 in; the most, 3.1 in, in Feb 2025.'"""
    vals = {k: v for k, v in (wx.get("values") or {}).items() if v is not None}
    if not vals:
        return ""
    if grain in ("year", "quarter", "month"):
        vals = {compute.period_label(k, grain): v for k, v in vals.items()}
    v = VARS[wx["var"]]
    unit = v["unit"]
    def say(x):
        return f"{x:.2f} in" if unit == "in" else (f"{x:.0f}°F" if unit == "°F" else f"{x:.0f} days")
    top = max(vals, key=vals.get)
    low = min(vals, key=vals.get)
    if v["how"] == "mean":
        return f"Warmest: {top} ({say(vals[top])}); coolest: {low} ({say(vals[low])})" if wx["var"] != "tmin" else \
            f"Mildest nights: {top} ({say(vals[top])}); coldest: {low} ({say(vals[low])})"
    tot = sum(vals.values())
    return f"{v['label']} totalled {say(tot)}; the most, {say(vals[top])}, in {top}"


def attach_v1(spec_v1, weather, result, runner, first, anchor):
    """a chart re-run from the website's chips or a saved chart (version-1 spec) keeps its weather.
    Version-1 grains are year, quarter and month, whose keys need no relabelling. Returns the notes."""
    from . import periods as P
    from . import spec2 as S
    notes = []
    if check(weather) or not isinstance(result, dict) or result.get("empty"):
        return notes
    a = spec_v1.get("date_from")
    b = spec_v1.get("date_to")
    try:
        start = dt.date.fromisoformat(a) if a else first
        end = dt.date.fromisoformat(b) if b else anchor
    except ValueError:
        return notes
    start, end = max(start, first), min(end, anchor)
    if start > end:
        return notes
    window = P.Window(start, end, P._span_label(start, end), "..", False, end)
    s = S.from_v1(spec_v1)
    s["weather"] = weather
    grain = spec_v1.get("time_grain") or "none"
    if grain == "none":                              # the chips have no week or day
        result["weather"] = unavailable("The weather goes beside a chart over time: pick By month (or By year).")
        notes.append("The weather goes beside a chart over time: pick By month (or By year).")
        return notes
    attach(s, runner, window, grain, result, spec_v1, notes, {})
    return notes
