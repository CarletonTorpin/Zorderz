"""run_spec: turn a validated chart spec into a result table.

  * a job is a distinct invoice with a work line (price > 0, qty > 0),
  * revenue is gross work-line totals, split across products with the lot
    components so product revenue adds up exactly (allocate()),
  * a job covering two products counts under each; the period totals are the
    true, de-duplicated totals,
  * units may include estimates and are labelled; blank means unknown, never 0,
  * a job whose count was measured on the job (the website sends it, numbers
    only) counts that measured number for that product (apply_measured()).
with the reference functions on the samples.
"""
import math

import numpy as np
import pandas as pd

from .pack import UNKNOWN

ALL_OTHER = "All other"


class SpecError(ValueError):
    pass


# ---------------------------------------------------------------- helpers
def _tag_names(tags):
    return {t.split(": ", 1)[1] for t in str(tags or "").split("; ") if ": " in t}


def apply_switches(lines, pack, spec):
    """Switchable reclassification rules from the pack: flagged lines are
    recounted as another product."""
    for s in pack.switches:
        value = spec.get(s.field, s.default)
        if value != s.on_value or s.flag_column not in lines:
            continue
        mask = lines[s.flag_column].fillna(False).astype(bool)
        if not mask.any():
            continue
        lines = lines.copy()
        lines.loc[mask, "family"] = s.set_product
        lines.loc[mask, "families_mentioned"] = s.set_product
        if s.note:
            lines.loc[mask, "inferred"] = lines.loc[mask, "inferred"].fillna("").map(
                lambda t: f"{t}; {s.note}" if t else s.note)
        if s.add_tag:
            lines.loc[mask, "tags"] = lines.loc[mask, "tags"].fillna("").map(
                lambda t: t if s.add_tag in t else (f"{t}; {s.add_tag}" if t else s.add_tag))
    return lines


def allocate(work, lot_parts, bundle="Mixed products"):
    """One row per (line, product) with cents that add up exactly: a bundled line (the pack's bundle product) is
    replaced by its estimated components."""
    cols = ["line_id", "invoice_no", "customer_key", "issued", "family", "action",
            "salesperson", "area", "line_cents", "tags"]
    if "town_basis" in work:
        cols.append("town_basis")               # "zip" when the town came from the customer's ZIP
    base = work[cols].copy()
    base["units"] = work["est_units"]
    base["estimated"] = work["est_basis"].fillna("").ne("") | \
        (work["est_units"].notna() & work["units"].isna())
    base = base.rename(columns={"line_cents": "cents", "family": "product"})
    if lot_parts is None or not len(lot_parts):
        return base
    mixed = base["product"].eq(bundle) & base["line_id"].isin(lot_parts["line_id"])
    if not mixed.any():
        return base
    parts = lot_parts[lot_parts["line_id"].isin(base.loc[mixed, "line_id"])].merge(
        base.loc[mixed].drop(columns=["product", "cents", "units", "estimated"]), on="line_id")
    parts = parts.rename(columns={"family": "product", "est_cents": "cents",
                                  "est_units": "units"})
    parts["estimated"] = True
    return pd.concat([base.loc[~mixed], parts[base.columns]], ignore_index=True)


def apply_measured(rows, measured):
    """Measured counts win: for each (product, invoice) the website sent a count
    for, the job's units for that product ARE that count, put on its first row
    (its other rows for the product get 0, so the job adds up to the count)."""
    rows = rows.copy()
    rows["measured"] = False
    if not measured or not len(rows):
        return rows
    for product, counts in measured.items():
        hit = rows["product"].eq(product) & rows["invoice_no"].isin(counts.keys())
        if not hit.any():
            continue
        idx = rows.index[hit]
        first = ~rows.loc[idx, "invoice_no"].duplicated()
        head, rest = idx[first.to_numpy()], idx[~first.to_numpy()]
        rows.loc[head, "units"] = rows.loc[head, "invoice_no"].map(counts).astype(float)
        rows.loc[rest, "units"] = 0.0
        rows.loc[idx, "measured"] = True
        rows.loc[idx, "estimated"] = False
    return rows


# finer grains and date parts (the question reader's "by week", "which weekday",
# "which season"). Keys sort in calendar order; ops/understand relabel them for people.
DATE_PART_KEYS = {
    "month_of_year": [f"{m:02d}" for m in range(1, 13)],
    "quarter_of_year": ["Q1", "Q2", "Q3", "Q4"],
    "week_of_year": [f"W{w:02d}" for w in range(1, 54)],
    "day_of_week": [str(d) for d in range(1, 8)],
    "season": ["1-winter", "2-spring", "3-summer", "4-fall"],
}
SEASON_OF_MONTH = {12: "1-winter", 1: "1-winter", 2: "1-winter", 3: "2-spring", 4: "2-spring", 5: "2-spring",
                   6: "3-summer", 7: "3-summer", 8: "3-summer", 9: "4-fall", 10: "4-fall", 11: "4-fall"}


def _iso_week_key(issued):
    iso = issued.dt.isocalendar()
    return iso["year"].astype(int).astype(str) + "-W" + iso["week"].astype(int).map("{:02d}".format)


def _period(issued, grain):
    if grain == "year":
        return issued.dt.strftime("%Y")
    if grain == "quarter":
        return issued.dt.to_period("Q").astype(str)
    if grain == "month":
        return issued.dt.strftime("%Y-%m")
    if grain == "week":
        return _iso_week_key(issued)
    if grain == "day":
        return issued.dt.strftime("%Y-%m-%d")
    if grain == "month_of_year":
        return issued.dt.strftime("%m")
    if grain == "quarter_of_year":
        return "Q" + issued.dt.quarter.astype(str)
    if grain == "week_of_year":
        return "W" + issued.dt.isocalendar()["week"].astype(int).map("{:02d}".format)
    if grain == "day_of_week":
        return (issued.dt.dayofweek + 1).astype(str)
    if grain == "season":
        return issued.dt.month.map(SEASON_OF_MONTH)
    return pd.Series("All", index=issued.index)


def _aggregate(frame, by):
    if by:
        g = frame.groupby(by, sort=False)
        out = pd.DataFrame({
            "revenue": g["cents"].sum().astype(float) / 100,
            "jobs": g["invoice_no"].nunique(),
            "customers": g["customer_key"].nunique(),
            "units": g["units"].sum(min_count=1).round(),
        })
        out["avg_job"] = _avg_dollars(g["cents"].sum().to_numpy(), out["jobs"].to_numpy())
        return out.reset_index()
    cents = pd.to_numeric(frame["cents"]).sum()
    revenue = float(cents) / 100
    jobs = int(frame["invoice_no"].nunique())
    units = frame["units"].sum(min_count=1)
    return {"revenue": revenue, "jobs": jobs,
            "customers": int(frame["customer_key"].nunique()),
            "units": None if pd.isna(units) else float(round(units)),
            "avg_job": float(_avg_dollars(np.array([cents]), np.array([jobs]))[0]) if jobs else None}


def _avg_dollars(cents, jobs):
    """Average job in whole dollars, rounded ONCE from whole cents (half up), the same way for a chart's bars
    and its totals."""
    c = np.rint(np.asarray(cents, dtype="float64")).astype("int64")
    j = np.asarray(jobs, dtype="int64")
    safe = np.where(j > 0, j, 1)
    out = np.floor_divide(2 * c + 100 * safe, 200 * safe).astype("float64")
    return np.where(j > 0, out, np.nan)


def _clean_number(v, money=False):
    if v is None:
        return None
    try:
        if pd.isna(v):
            return None
    except (TypeError, ValueError):
        pass
    if isinstance(v, (np.integer,)):
        return int(v)
    v = float(v)
    if money:
        return round(v, 2)
    return int(v) if v.is_integer() else round(v, 2)


# ---------------------------------------------------------------- selection
NOT_A_PARTNER = "(no listed partner named)"
# A saved chart or an open panel may still send it as a filter; it means exactly
# the same thing.
OLD_NOT_A_PARTNER = "(not a listed partner)"

# ---------------------------------------------------------------- drill-down
# Drilling down: a clicked bar (a referral category, say) expands into a new bar
# underneath it that splits it one level down (the companies within that
# category), and a click on one of those shows its jobs. A clicked group
# becomes a filter; the child chart splits it one level down.
GROUP_FILTER = {"product": "products", "work_type": "work_types", "salesperson": "salespeople",
                "area": "areas", "lead_source": "lead_sources", "original_source": "original_sources",
                "referral_partner": "partners", "safety_site": "safety_sites",
                "safety_council": "safety_councils",   
                "tax_area": "tax_areas", "coast_band": "coast_bands",   
                "coast": "coast_sides", "payment": "payments",          
                "region": "regions"}                                    
DRILL_TO = {"lead_source": "referral_partner", "original_source": "referral_partner",
            "product": "work_type", "work_type": "product", "salesperson": "product", "area": "product",
            "safety_site": "product", "safety_council": "product", "tax_area": "product", "coast_band": "product",
            "coast": "product", "payment": "product", "region": "area"}   # a region drills to its towns
NOT_FILTERABLE = {"All other", "(unknown)", "Other"}


def with_group(spec, group, pack=None):
    """The spec narrowed to one of its own groups, or None when that group can't
    be a filter (a catch-all, an unknown, a split with no filter)."""
    import copy
    split = spec.get("split", "none")
    g = str(group or "").strip()
    if not g or g in NOT_FILTERABLE or (g.startswith("(") and split != "referral_partner"):
        return None
    if g == UNKNOWN or (pack is not None and g == getattr(pack, "not_recorded", None) and split != "salesperson"):
        return None
    out = copy.deepcopy(spec)
    f = out.setdefault("filters", {})
    if split == "area" and spec.get("chart_type") == "map" and g[:5].isdigit() and len(g) >= 5:
        f["zips"] = [g[:5]]                      # a tapped ZIP area
        return out
    if split.startswith("tag_"):
        key = "tags"
    else:
        key = GROUP_FILTER.get(split)
    if not key:
        return None
    # a merged group ("Classic counted within Northstar") is all of its parts
    parts = [k for k, v in (spec.get("merge_groups") or {}).items() if v == g]
    f[key] = [g] + parts
    return out


def drill_spec(data, pack, spec, group):
    """The child chart for a clicked group: that group only, split one level down,
    the spec's date range as one 100% bar. None when there's nowhere to go.
    ops.op_drill narrows the spec to the tapped bar's period first
    (narrow_to_period), so "the spec's date range" is that period; without one it
    is the chart's whole range, as before. op_drill writes the final title."""
    narrowed = with_group(spec, group, pack)
    if narrowed is None:
        return None
    split = spec.get("split", "none")
    child = "product" if split.startswith("tag_") else DRILL_TO.get(split)
    if not child:
        return None
    if child == "referral_partner" and "partner" in data.invoices:
        # partners only mean something for referrers; otherwise show what they bought
        rows = select(data, pack, narrowed)
        named = data.invoices.set_index("invoice_no")["partner"].reindex(rows["invoice_no"].unique())
        if not named.fillna("").ne("").any():
            child = "product"
    narrowed.update(split=child, facet="none", time_grain="none", chart_type="percent_bar",
                    orientation="horizontal", labels="value_percent", top_n=15, venn_by="none", venn_sets=[])
    narrowed["title"] = f"{group}: by {child.replace('_', ' ').replace('referral partner', 'company')}"[:90]
    return narrowed


# A tapped period's bar now opens THAT period only; the plugin sends the period key
# exactly as _period() wrote it, and the hub turns it back into a date window with
# the same calendar _period() uses.
PERIOD_FORMAT = {"year": r"[0-9]{4}", "quarter": r"[0-9]{4}Q[1-4]", "month": r"[0-9]{4}-(?:0[1-9]|1[0-2])"}
PERIOD_FREQ = {"year": "Y", "quarter": "Q", "month": "M"}
MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"]


def period_window(period, grain):
    """(first day, last day) of one chart period, or None when `period` is not a key
    _period() could have written for this grain. Strict: exact format, ASCII digits."""
    import re
    fmt = PERIOD_FORMAT.get(grain)
    if fmt is None or not isinstance(period, str) or not re.fullmatch(fmt, period, flags=re.ASCII):
        return None
    try:
        p = pd.Period(period, freq=PERIOD_FREQ[grain])
    except (ValueError, TypeError):
        return None
    start, end = p.start_time.normalize(), p.end_time.normalize()
    # the same calendar as the chart: both ends map back to this very key
    if _period(pd.Series([start, end]), grain).tolist() != [period, period]:
        return None
    return start, end


def narrow_to_period(spec, period, grain):
    """The spec cut to one period, intersected with its own dates (never widened).
    Returns (spec, empty): empty is True when the period lies outside the chart's dates."""
    import copy
    start, end = period_window(period, grain)
    lo, hi = start.date(), end.date()
    if spec.get("date_from"):
        lo = max(lo, pd.Timestamp(spec["date_from"]).date())
    if spec.get("date_to"):
        hi = min(hi, pd.Timestamp(spec["date_to"]).date())
    out = copy.deepcopy(spec)
    if lo > hi:
        return out, True
    out["date_from"], out["date_to"] = lo.isoformat(), hi.isoformat()
    return out, False


def period_label(period, grain):
    """How a person says the period: 2020, Q3 2020, Mar 2020."""
    if grain == "quarter":
        return f"Q{period[-1]} {period[:4]}"
    if grain == "month":
        return f"{MONTHS[int(period[5:7]) - 1]} {period[:4]}"
    return period


def range_label(date_from, date_to, grain="year"):
    """A date range in words: 2016–2025, Mar–May 2020, Q2 2020–Q1 2021, or the days."""
    a, b = pd.Timestamp(date_from), pd.Timestamp(date_to)
    whole_months = a.day == 1 and b.is_month_end
    if whole_months and a.month == 1 and b.month == 12:
        return str(a.year) if a.year == b.year else f"{a.year}–{b.year}"
    if whole_months and grain == "quarter" and a.month % 3 == 1 and b.month % 3 == 0:
        qa, qb = f"Q{(a.month - 1) // 3 + 1} {a.year}", f"Q{(b.month - 1) // 3 + 1} {b.year}"
        return qa if qa == qb else f"{qa}–{qb}"
    if whole_months:
        ma, mb = MONTHS[a.month - 1], MONTHS[b.month - 1]
        if (a.year, a.month) == (b.year, b.month):
            return f"{ma} {a.year}"
        return f"{ma}–{mb} {a.year}" if a.year == b.year else f"{ma} {a.year}–{mb} {b.year}"
    return f"{MONTHS[a.month - 1]} {a.day}, {a.year}–{MONTHS[b.month - 1]} {b.day}, {b.year}"


def chart_span(data, pack, spec, grain="year"):
    """(date_from, date_to) the chart covers, as ISO days: its own dates, and where it
    has none, its first and last matching job, rounded out to the chart's grain.
    None when nothing matches and the chart names no dates."""
    lo, hi = spec.get("date_from"), spec.get("date_to")
    if not (lo and hi):
        rows = select(data, pack, spec)
        if rows.empty:
            return None
        freq = PERIOD_FREQ.get(grain, "Y")
        if not lo:
            lo = pd.Period(rows["issued"].min(), freq).start_time.normalize().date().isoformat()
        if not hi:
            hi = pd.Period(rows["issued"].max(), freq).end_time.normalize().date().isoformat()
    return str(lo), str(hi)


def drill_range(data, pack, spec, grain="year"):
    """The range a whole-range drill covers, in words (chart_span, said as a range)."""
    span = chart_span(data, pack, spec, grain)
    return range_label(*span, grain) if span else None


# A window that reaches the whole range is not added: the whole-range bar already is
# that. A pack may tune this in rules.yml (charts.drill_min_jobs).
MIN_JOBS = 10


def period_keys(date_from, date_to, grain):
    """Every period key _period() would write between two days, in order."""
    freq = PERIOD_FREQ.get(grain)
    if freq is None:
        return []
    rng = pd.period_range(pd.Period(pd.Timestamp(date_from), freq), pd.Period(pd.Timestamp(date_to), freq), freq=freq)
    starts = pd.Series([p.start_time for p in rng])
    return _period(starts, grain).tolist() if len(starts) else []


def widen(n, i, jobs_in, min_jobs=MIN_JOBS):
    """The smallest window (a, b) of periods around period i (of n) with at least
    min_jobs jobs, growing one period each side per step (clipped at 0 and n-1; the
    other side keeps growing). None when only the whole range (0, n-1) gets there, or
    not even it: the whole-range bar already shows that. jobs_in(a, b) counts the
    distinct jobs in periods a..b."""
    a = b = i
    while a > 0 or b < n - 1:
        a, b = max(a - 1, 0), min(b + 1, n - 1)
        if a == 0 and b == n - 1:
            return None
        if jobs_in(a, b) >= min_jobs:
            return a, b
    return None


def window_counter(data, pack, spec, group, keys, grain):
    """jobs_in(a, b) for widen(): distinct jobs (invoices) of `group` in periods
    keys[a..b], within the spec's own dates. Counts jobs, never units."""
    narrowed = with_group(spec, group, pack)
    rows = select(data, pack, narrowed) if narrowed is not None else None
    if rows is None or rows.empty:
        return lambda a, b: 0
    per = rows.assign(_p=_period(rows["issued"], grain)).groupby("_p")["invoice_no"].unique()
    sets = [set(per.get(k, [])) for k in keys]
    return lambda a, b: len(set().union(*sets[a:b + 1]))


def window_dates(keys, a, b, grain, span):
    """(date_from, date_to) of periods keys[a..b], clipped to the chart's span."""
    lo = period_window(keys[a], grain)[0].date().isoformat()
    hi = period_window(keys[b], grain)[1].date().isoformat()
    return max(lo, span[0]), min(hi, span[1])


def history_jobs(data, pack, spec):
    """How many of this chart's jobs are there only because "Existing Customer" also
    means "Existing Customer - from history" (_with_history): the untagged repeat jobs.
    0 when the spec doesn't fold them in (no Existing Customer filter, or it asks for
    the from-history group by name). Exact: distinct invoices, same rows as the chart."""
    return history_part(data, pack, spec)[0]


def history_part(data, pack, spec):
    """(jobs, amount) folded in from history, as history_jobs. `amount` is in the spec's
    own measure for units and revenue: the bar's total minus what the same
    rows come to without those jobs, each aggregated exactly as the chart does, so
    bar - amount == the chart's typed bar to the unit shown. None for other measures."""
    from .lead_sources import EXISTING, FROM_HISTORY
    want = {str(x).casefold() for x in (spec.get("filters") or {}).get("lead_sources") or []}
    if EXISTING.casefold() not in want or FROM_HISTORY.casefold() in want:
        return 0, None
    rows = select(data, pack, spec)
    if rows.empty:
        return 0, None
    src = data.invoices.drop_duplicates("invoice_no").set_index("invoice_no")["lead_source"]
    got = src.reindex(pd.Index(rows["invoice_no"].unique())).fillna("").astype(str)
    hist = set(got.index[got.str.casefold().eq(FROM_HISTORY.casefold())])
    n = len(hist)
    measure = spec.get("measure")
    if not n or measure not in {"units", "revenue"}:
        return n, None
    whole = _aggregate(rows, [])[measure]
    rest_rows = rows[~rows["invoice_no"].isin(hist)]
    rest = _aggregate(rest_rows, [])[measure] if not rest_rows.empty else (0.0 if measure == "revenue" else None)
    if whole is None:
        return n, None
    amount = whole - (rest or 0)
    return n, (round(amount, 2) if measure == "revenue" else float(amount))


def history_text(n, amount=None, measure=None, noun="units"):
    """"includes 9 repeat jobs (39 doors) found from history"; jobs only when the chart
    counts jobs, customers or an average (those don't subtract)."""
    if not n:
        return None
    jobs = f"{n:,} repeat job{'' if n == 1 else 's'}"
    if amount is not None and measure in {"units", "revenue"}:
        jobs += f" ({total_text(amount, measure, noun)})"
    return f"includes {jobs} found from history"


def unit_noun(spec, pack):
    """The word for `units` in this chart: the one noun every product in it shares
    ("doors" for window film and patio lights), when the pack's own words for units
    list it; otherwise "units"."""
    import re
    from .pack import CORE_MEASURE_WORDS
    text = (getattr(pack, "text", None) or {}).get("measure_words", {}).get("units") \
        or CORE_MEASURE_WORDS["units"]
    words = {w.strip().lower() for w in re.sub(r'\(.*?\)|"[^"]*"', "", text).split(",")}
    words = {w for w in words if re.fullmatch(r"[a-z]+", w)}
    products = (spec.get("filters") or {}).get("products") or []
    nouns = {re.sub(r"\s*\(.*?\)\s*", " ", str(p)).strip().split(" ")[-1].lower() for p in products}
    if len(nouns) == 1 and next(iter(nouns)) in words:
        return next(iter(nouns))
    return "units"


def total_text(value, measure, noun="units"):
    """The panel's total the way the chart says it: 74 doors, 12 jobs, $4,210."""
    if value is None:
        return "no matching jobs" if measure != "units" else "no unit count"
    v = float(value)
    if measure in {"revenue", "avg_job"}:
        money = f"${v:,.0f}" if v.is_integer() or abs(v) >= 1000 else f"${v:,.2f}"
        return money + (" average job" if measure == "avg_job" else "")
    word = {"jobs": "jobs", "customers": "customers", "units": noun}.get(measure, "units")
    if v == 1 and word.endswith("s"):
        word = word[:-1]
    n = f"{int(v):,}" if v.is_integer() else f"{v:,.1f}"
    return f"{n} {word}"


def list_jobs(data, pack, spec, money=True, by_person=True, limit=100):
    """The jobs behind a chart (or one piece of it): one row per invoice, newest first.
    Invoice numbers, dates, towns, products and amounts only: no customer details."""
    rows = select(data, pack, spec)
    if rows.empty:
        return {"jobs": [], "total": 0}
    inv = data.invoices.set_index("invoice_no")
    if "full_cents" in rows:
        # a job list shows each job's whole amount, once, whoever it is credited to
        rows = rows.drop_duplicates(["line_id", "product"]).assign(cents=lambda r: r["full_cents"])
    g = rows.groupby("invoice_no", sort=False)
    out = pd.DataFrame({
        "issued": g["issued"].min(),
        "town": g["area"].first(),
        "products": g["product"].agg(lambda p: sorted({str(x) for x in p if str(x) and str(x) != "nan"})),
        "cents": g["cents"].sum(),
        "salesperson": g["salesperson"].first(),
    }).sort_values("issued", ascending=False)
    total = int(len(out))
    jobs = []
    for no, r in out.head(limit).iterrows():
        src = inv["lead_source"].get(no, "") if "lead_source" in inv else ""
        partner = inv["partner"].get(no, "") if "partner" in inv else ""
        jobs.append({
            "invoice_no": str(no),
            "date": r["issued"].strftime("%Y-%m-%d") if hasattr(r["issued"], "strftime") else None,
            "town": r["town"] if isinstance(r["town"], str) else "",
            "products": r["products"][:4],
            "amount": round(float(r["cents"]) / 100, 2) if money and pd.notna(r["cents"]) else None,
            "salesperson": (r["salesperson"] if isinstance(r["salesperson"], str) else "") if by_person else None,
            "source": src if isinstance(src, str) else "",
            "partner": partner if isinstance(partner, str) else "",
        })
    return {"jobs": jobs, "total": total}


def _with_history(labels):
    """"Existing Customer" also means the untagged jobs we know are repeat ones.
    the no-source group's old name (a saved chart) means its new one."""
    from .lead_sources import EXISTING, FROM_HISTORY, NEW_UNKNOWN, OLD_NEW_UNKNOWN
    if EXISTING.casefold() in labels:
        labels = labels | {FROM_HISTORY.casefold()}
    if OLD_NEW_UNKNOWN.casefold() in labels:
        labels = labels | {NEW_UNKNOWN.casefold()}
    return labels


def normalise_towns(data, pack):
    """one town per job, the same for filters, bars, drills and cards.
    1. The pack's spellings fold into its towns ("Ctr Hills" -> Cedar Hills).
    2. A job whose invoice named no town the import could read ("(other)", or blank) takes its
       customer's ZIP town when that ZIP is one pack town (not "Green Valley / Sunny Springs"),
       and is marked town_basis = "zip" so a chart can say how many.
    In memory only; the store on disk is untouched. Returns how many jobs took a ZIP town."""
    if data is None or getattr(data, "_towns_done", False):
        return 0
    canon = {v.casefold(): a for a in pack.areas for v in pack.area_variants(a)}
    zmap = zip_of_customer(data)
    single = {z: n for z, n in (getattr(pack, "zips", {}) or {}).items() if n in pack.areas}
    filled = set()

    def fix(frame):
        if frame is None or not len(frame) or "area" not in frame:
            return
        raw = frame["area"].fillna("").astype(str)
        new = raw.map(lambda a: canon.get(a.casefold(), a))
        if "customer_key" in frame:
            blank = new.isin(["", "(other)", "(unknown)"])
            via = frame.loc[blank, "customer_key"].astype(str).map(lambda k: single.get(zmap.get(k, ""), ""))
            got = via[via.ne("")]
            new.loc[got.index] = got
            frame["town_basis"] = "invoice"
            frame.loc[got.index, "town_basis"] = "zip"
            if "invoice_no" in frame:
                filled.update(frame.loc[got.index, "invoice_no"].astype(str))
        frame["area"] = new.where(new.ne(""), frame["area"])

    fix(data.lines)
    fix(data.invoices)
    try:
        data.__post_init__()                      # work = the fixed lines' work rows
        data._towns_done = True
    except Exception:
        pass
    return len(filled)


def _town_of_rows(data, pack, rows):
    """the town of each row -- the invoice's own, else the customer's ZIP town (pack zips); "" when neither."""
    town = rows["area"].fillna("").astype(str) if "area" in rows else pd.Series("", index=rows.index)
    if "customer_key" in rows and getattr(pack, "zips", None):
        zmap = zip_of_customer(data)
        by_zip = rows["customer_key"].astype(str).map(lambda k: pack.zips.get(zmap.get(k, ""), ""))
        town = town.where(town.ne(""), by_zip)
    return town


def zip_of_customer(data):
    """customer key -> 5-digit ZIP from customers_clean.zip5 ("" when unknown). Cached on
    the data object: the store is reloaded, never edited, so the map stays right for its life."""
    got = getattr(data, "_zip_by_key", None)
    if got is None:
        cust = data.customers
        if "zip5" in cust:
            z = cust["zip5"].fillna("").astype(str).str.extract(r"(\d{5})")[0].fillna("")
            got = dict(zip(cust["customer_key"].astype(str), z))
        else:
            got = {}
        try:
            data._zip_by_key = got
        except Exception:             # a frozen stand-in: just don't cache
            pass
    return got


def estimated_zip_keys(data):
    """customer keys whose ZIP the import estimated from the street or town (zip_basis)."""
    got = getattr(data, "_zip_estimated", None)
    if got is None:
        cust = data.customers
        got = set(cust.loc[cust["zip_basis"].fillna("").eq("estimated"), "customer_key"].astype(str)) \
            if "zip_basis" in cust else set()
        try:
            data._zip_estimated = got
        except Exception:
            pass
    return got


NO_ZIP = "(no ZIP)"

# safety sites and safety councils. The import matches each customer's address to the region's
# address points on this computer and keeps only the territory names (customers_clean columns).
TERRITORY_SPLITS = {"safety_site": "(not in a safety site)", "safety_council": "(no safety council)"}
TERRITORY_FILTERS = {"safety_sites": "safety_site", "safety_councils": "safety_council"}
TERRITORY_ANY = "*"


# what a customer's place is like (places.py; the import places each customer on the region's address points on this
# computer and keeps only these descriptions). The sales tax area (with its rate) and the straight-line distance to
# the coast, in bands.
PLACE_SPLITS = {"tax_area": "(not placed)", "coast_band": "(not placed)", "coast": "(not placed)"}   # coast
PLACE_FILTERS = {"tax_areas": "tax_area", "coast_bands": "coast_band", "coast_sides": "coast"}
SHAPE_SPLITS = set(TERRITORY_SPLITS) | {"tax_area"}            # splits whose groups have outlines on the map


def tax_label(name, rate):
    """ "Northtown 7.00%" — how a sales tax area shows on a chart (the rate tells two areas apart)."""
    try:
        return f"{name} {float(rate):.2f}%"
    except (TypeError, ValueError):
        return str(name)


# every invoice carries FreshBooks' status.
# "Paid" is status paid; "Unpaid" is sent, viewed, overdue or part paid (money still owed); a draft was never
# sent, so it is "Draft", not unpaid. Payment is a filter (`payment`: paid | unpaid) and a split (`payment`).
PAYMENT_STATES = ("paid", "unpaid")
PAYMENT_LABEL = {"paid": "Paid", "unpaid": "Unpaid", "draft": "Draft"}
PAYMENT_ORDER = ("Paid", "Unpaid", "Draft")


def payment_of_invoice(data):
    """{invoice_no: "Paid" | "Unpaid" | "Draft"} for every invoice in the store (cached on the data)."""
    inv = data.invoices
    held = getattr(data, "_payment_of", None)
    if held is not None and held[0] is inv:
        return held[1]
    status = inv["status"].fillna("").astype(str).str.lower() if "status" in inv else pd.Series("", index=inv.index)
    labels = ["Paid" if s == "paid" else "Draft" if s == "draft" else "Unpaid" for s in status]
    out = dict(zip(inv["invoice_no"].astype(str), labels))
    try:
        data._payment_of = (inv, out)
    except Exception:
        pass
    return out


def job_total_of_invoice(data):
    """{invoice_no: the job's whole amount in dollars} -- every line of the invoice, before any filter
    (cached on the data). A job's size is the whole invoice, whatever part of it a chart is about."""
    work = data.work
    held = getattr(data, "_job_total_of", None)
    if held is not None and held[0] is work:
        return held[1]
    col = "line_cents" if "line_cents" in work else "cents"          # the work table keeps each line's own cents
    if len(work) and col in work and "invoice_no" in work:
        one = work.drop_duplicates("line_id") if "line_id" in work else work   # a line counted once
        g = pd.to_numeric(one[col], errors="coerce").fillna(0).groupby(one["invoice_no"].astype(str)).sum()
        out = {k: float(v) / 100 for k, v in g.items()}
    else:
        out = {}
    try:
        data._job_total_of = (work, out)
    except Exception:
        pass
    return out


def job_size_of(f):
    """(min, max) dollars from a filters dict: min inclusive, max exclusive; None when not set."""
    def num(x):
        try:
            return None if x in (None, "") else float(x)
        except (TypeError, ValueError):
            return None
    return num((f or {}).get("job_min")), num((f or {}).get("job_max"))


def place_of_customer(data, split):
    """{customer_key: group label} for a place split ("(not placed)" when the import couldn't place them)."""
    cust = data.customers
    held = getattr(data, "_place_of", None)
    if held is None or held[0] is not cust:
        held = (cust, {})
        try:
            data._place_of = held
        except Exception:
            pass
    cache = held[1]
    if split not in cache:
        none = PLACE_SPLITS[split]
        keys = cust["customer_key"].astype(str)
        if split == "tax_area" and "tax_area" in cust:
            rates = cust["tax_rate"] if "tax_rate" in cust else pd.Series([None] * len(cust), index=cust.index)
            labels = [tax_label(n, r) if str(n or "").strip() else none
                      for n, r in zip(cust["tax_area"].fillna(""), rates)]
        elif split == "coast_band" and "coast_mi" in cust:
            from .places import coast_band
            labels = [coast_band(float(v)) if pd.notna(v) else none
                      for v in pd.to_numeric(cust["coast_mi"], errors="coerce")]
        elif split == "coast" and "coast_mi" in cust:                 # coastal (within 3 mi) or inland
            from .places import coast_side
            labels = [coast_side(float(v)) if pd.notna(v) else none
                      for v in pd.to_numeric(cust["coast_mi"], errors="coerce")]
        else:
            labels = [none] * len(cust)
        cache[split] = dict(zip(keys, labels))
    return cache[split]


def territory_of_customer(data, col):
    """{customer_key: [territory names]} ("; "-joined in the store; a site can overlap another)."""
    cust = data.customers
    held = getattr(data, "_territory_of", None)
    if held is None or held[0] is not cust:                       # tied to this customers table, not to `data`
        held = (cust, {})
        try:
            data._territory_of = held
        except Exception:
            pass
    cache = held[1]
    if col not in cache:
        if col in cust:
            cache[col] = {str(k): [x for x in str(v or "").split("; ") if x]
                          for k, v in zip(cust["customer_key"], cust[col].fillna(""))}
        else:
            cache[col] = {}
    return cache[col]


def zip_label(pack, z):
    """How a ZIP shows on a chart: "00013 Eastvale"; just the number when the pack has no name."""
    if not z:
        return NO_ZIP
    name = (getattr(pack, "zips", {}) or {}).get(z, "")
    return f"{z} {name}" if name else z


def map_by_zip(spec, pack):
    """a map of towns is drawn as ZIP areas when the pack has ZIPs."""
    return spec.get("chart_type") == "map" and spec.get("split") == "area" and bool(getattr(pack, "zips", None))


def select(data, pack, spec):
    """The allocated rows the spec asks about, plus flags for the notes."""
    f = spec["filters"]
    lines = apply_switches(data.work, pack, spec)
    if not spec.get("include_unpaid", True):
        paid = set(data.invoices.loc[data.invoices["status"].eq("paid"), "invoice_no"])
        lines = lines[lines["invoice_no"].isin(paid)]
    pay = f.get("payment", "any")                                  # paid / unpaid
    if pay in PAYMENT_STATES:
        state = payment_of_invoice(data)
        lines = lines[lines["invoice_no"].astype(str).map(lambda n: state.get(n) == PAYMENT_LABEL[pay])]
    jmin, jmax = job_size_of(f)                                    # a job's size (its whole invoice)
    if jmin is not None or jmax is not None:
        tot = job_total_of_invoice(data)
        keep = {n for n, v in tot.items() if (jmin is None or v >= jmin) and (jmax is None or v < jmax)}
        lines = lines[lines["invoice_no"].astype(str).isin(keep)]
    if spec.get("date_from"):
        lines = lines[lines["issued"] >= pd.Timestamp(spec["date_from"])]
    if spec.get("date_to"):
        lines = lines[lines["issued"] < pd.Timestamp(spec["date_to"]) + pd.Timedelta(days=1)]
    ctype = f.get("customer_type", "any")
    if ctype != "any" or f.get("repeat_customers_only"):
        cust = data.customers
        keep = pd.Series(True, index=cust.index)
        if ctype == "business":
            keep &= cust["is_business"]
        elif ctype == "residential":
            keep &= ~cust["is_business"]
        if f.get("repeat_customers_only"):
            keep &= cust["jobs"].ge(2)
        lines = lines[lines["customer_key"].isin(set(cust.loc[keep, "customer_key"]))]
    for fk, col in TERRITORY_FILTERS.items():
        # jobs whose customer's address is in a safety site / safety council ("*" = any of them)
        want = f.get(fk)
        if want:
            tmap = territory_of_customer(data, col)
            anyone = TERRITORY_ANY in want
            wanted = set(want)
            lines = lines[lines["customer_key"].astype(str).map(
                lambda k: bool(tmap.get(k)) and (anyone or bool(wanted & set(tmap.get(k) or ()))))]
    for fk, split in PLACE_FILTERS.items():
        # jobs whose customer is in one of these sales tax areas / distance bands
        want = f.get(fk)
        if want:
            pmap = place_of_customer(data, split)
            wanted = set(want)
            lines = lines[lines["customer_key"].astype(str).map(lambda k: pmap.get(k) in wanted)]
    if f.get("zips"):
        # a tapped ZIP on the map (the customer's ZIP)
        want = {str(z)[:5] for z in f["zips"]}
        zmap = zip_of_customer(data)
        lines = lines[lines["customer_key"].astype(str).map(lambda k: zmap.get(k, "") in want)]
    if f.get("customers"):
        # one customer, by its de-identified key (an invoice card's pivots).
        lines = lines[lines["customer_key"].isin(set(f["customers"]))]
    if f.get("accounts"):
        cust = data.customers
        keys = set(cust.loc[cust["account_key"].isin(f["accounts"]), "customer_key"]) \
            if "account_key" in cust else set()
        lines = lines[lines["customer_key"].isin(keys)]
    if f.get("months"):
        # only these calendar months, every year ("December each year")
        lines = lines[lines["issued"].dt.month.isin([int(m) for m in f["months"]])]
    if f.get("day_cut"):
        # each year cut at the same day
        m, d = int(f["day_cut"][0]), int(f["day_cut"][1])
        md = lines["issued"].dt.month * 100 + lines["issued"].dt.day
        lines = lines[md <= m * 100 + d]
    people = _people_of(data)
    if f.get("salespeople"):
        if people is not None:
            # anyone on the job (salesperson or installer) counts for its filter
            want = set(f["salespeople"])
            on = people.map(lambda ps: bool(want & set(ps)))
            lines = lines[lines["invoice_no"].isin(set(on.index[on]))]
        else:
            lines = lines[lines["salesperson"].fillna(UNKNOWN).isin(f["salespeople"])]
    if f.get("exclude_salespeople"):
        lines = lines[~lines["salesperson"].fillna(UNKNOWN).isin(f["exclude_salespeople"])]
    if f.get("lead_sources"):
        want = _with_history({str(x).casefold() for x in f["lead_sources"]})
        src = data.invoices.set_index("invoice_no")["lead_source"].fillna("")
        keep = set(src[src.str.casefold().isin(want)].index)
        lines = lines[lines["invoice_no"].isin(keep)]
    if f.get("original_sources") and "original_source" in data.invoices:
        # customers who first came to us through these sources, every job of theirs
        want = {str(x).casefold() for x in f["original_sources"]}
        src = data.invoices.set_index("invoice_no")["original_source"].fillna("")
        keep = set(src[src.str.casefold().isin(want)].index)
        lines = lines[lines["invoice_no"].isin(keep)]
    if f.get("partners") and "partner" in data.invoices:
        # jobs a named partner sent us; "(no listed partner named)" = none named
        from .lead_sources import partner_of
        # "Acme" finds "Acme Lawn": the business's spellings list
        # decides
        want = {(partner_of(x) or str(x)).casefold() for x in f["partners"]}
        src = data.invoices.set_index("invoice_no")["partner"].fillna("")
        hit = src.str.casefold().isin(want)
        if NOT_A_PARTNER.casefold() in want or OLD_NOT_A_PARTNER.casefold() in want:
            hit = hit | src.eq("")
        lines = lines[lines["invoice_no"].isin(set(src[hit].index))]
    if f.get("exclude_partners") and "partner" in data.invoices:
        # "excluding ones that don't have a company referral"
        from .lead_sources import partner_of
        drop = {(partner_of(x) or str(x)).casefold() for x in f["exclude_partners"]}
        src = data.invoices.set_index("invoice_no")["partner"].fillna("")
        hit = src.str.casefold().isin(drop)
        if NOT_A_PARTNER.casefold() in drop or OLD_NOT_A_PARTNER.casefold() in drop:
            hit = hit | src.eq("")
        lines = lines[~lines["invoice_no"].isin(set(src[hit].index))]
    if f.get("exclude_lead_sources"):
        # "non-repeat" = every job except those tagged
        # Existing Customer. Jobs with no lead source recorded stay in; run() says how many.
        drop = _with_history({str(x).casefold() for x in f["exclude_lead_sources"]})
        src = data.invoices.set_index("invoice_no")["lead_source"].fillna("")
        gone = set(src[src.str.casefold().isin(drop)].index)
        lines = lines[~lines["invoice_no"].isin(gone)]
    if f.get("areas"):
        wanted = {v.casefold() for a in f["areas"] for v in pack.area_variants(a)}
        lines = lines[lines["area"].fillna("").str.casefold().isin(wanted)]
    if f.get("exclude_areas"):
        gone = {v.casefold() for a in f["exclude_areas"] for v in pack.area_variants(a)}
        lines = lines[~lines["area"].fillna("").str.casefold().isin(gone)]
    if f.get("regions"):
        # a region is its towns (pack regions / region_words); a job counts by its invoice's town, else by
        # its customer's ZIP town.
        regions, towns = pack.regions_and_towns_of(f["regions"])
        wanted = {v.casefold() for a in towns for v in pack.area_variants(a)}
        town = _town_of_rows(data, pack, lines)
        in_region = town.map(pack.region_of_area).isin(set(regions))
        in_town = town.str.casefold().isin(wanted) | town.str.casefold().map(
            lambda s: any(p.strip() in wanted for p in s.split("/")) if "/" in s else False)
        lines = lines[in_region | in_town]
    if f.get("work_types"):
        lines = lines[lines["action"].isin(f["work_types"])]
    if f.get("exclude_work_types"):
        lines = lines[~lines["action"].isin(f["exclude_work_types"])]
    if f.get("tags"):
        want = set(f["tags"])
        lines = lines[lines["tags"].map(lambda t: bool(_tag_names(t) & want)).astype(bool)]
    if f.get("exclude_tags"):
        gone_tags = set(f["exclude_tags"])
        lines = lines[~lines["tags"].map(lambda t: bool(_tag_names(t) & gone_tags)).astype(bool)]
    rows = allocate(lines, data.lot_parts, pack.bundle_product).dropna(subset=["issued"])
    if f.get("products"):
        rows = rows[rows["product"].isin(f["products"])]
    if f.get("exclude_products"):
        rows = rows[~rows["product"].isin(f["exclude_products"])]
    rows = apply_measured(rows, getattr(data, "measured", None))
    if people is not None and (f.get("salespeople") or spec.get("split") == "salesperson") and len(rows):
        rows = credit_people(rows, people, f.get("salespeople"))
    return rows


def _people_of(data):
    """invoice_no -> [people on the job] when the import recorded them, else None."""
    inv = getattr(data, "invoices", None)
    if inv is None or "people" not in inv:
        return None
    cached = getattr(data, "_people_cache", None)
    if cached is not None and cached[0] is inv:
        return cached[1]
    s = inv.drop_duplicates("invoice_no").set_index("invoice_no")["people"].fillna("").astype(str)
    out = s.map(lambda t: [p for p in t.split(";") if p])
    try:
        data._people_cache = (inv, out)
    except Exception:
        pass
    return out


def credit_people(rows, people, wanted=None):
    """a job with two people on it (a salesperson and an
    installer) counts for each of them, with an equal share of its revenue and units, so
    the people's revenue adds up to the job's and no figure is counted twice. Rows gain
    `person`, `share` and `full_cents` (the job line's whole amount, for job lists).
    With `wanted`, only those people's shares are kept."""
    rows = rows.copy()
    rows["full_cents"] = rows["cents"]
    plist = rows["invoice_no"].map(people)
    fallback = rows["salesperson"].fillna(UNKNOWN).replace("", UNKNOWN)
    plist = [p if isinstance(p, list) and p else [fb] for p, fb in zip(plist, fallback)]
    rows["person"] = plist
    rows["share"] = [1.0 / len(p) for p in plist]
    rows = rows.explode("person")
    if wanted:
        rows = rows[rows["person"].isin(set(wanted))]
    rows["cents"] = pd.to_numeric(rows["cents"], errors="coerce").astype(float) * rows["share"].astype(float)
    rows["units"] = pd.to_numeric(rows["units"], errors="coerce").astype(float) * rows["share"].astype(float)
    rows["salesperson"] = rows["person"]
    return rows


def _fill_periods(periods, grain, date_from=None, date_to=None, as_of=None):
    """A time axis has no holes: a period with no matching jobs still shows,
    from the spec's start (or the first job) to its end (or the first job's
    last period), never past the data's newest invoice."""
    if grain in DATE_PART_KEYS or not periods:
        return periods
    if grain in {"week", "day"}:
        def day_of(key):
            if grain == "day":
                return pd.Timestamp(key)
            y, w = key.split("-W")
            import datetime as _dt
            return pd.Timestamp(_dt.date.fromisocalendar(int(y), int(w), 1))
        lo, hi = day_of(periods[0]), day_of(periods[-1])
        if date_from:
            lo = min(lo, pd.Timestamp(date_from))
        if date_to:
            cap = min(pd.Timestamp(date_to), pd.Timestamp(as_of)) if as_of else pd.Timestamp(date_to)
            hi = max(hi, cap)
        days = pd.Series(pd.date_range(lo, hi, freq="D"))
        keys = _period(days, grain)
        return list(dict.fromkeys(keys.tolist()))
    if grain not in {"year", "quarter", "month"}:
        return periods
    freq = {"year": "Y", "quarter": "Q", "month": "M"}[grain]
    start, end = pd.Period(periods[0], freq), pd.Period(periods[-1], freq)
    if date_from:
        start = min(start, pd.Period(date_from, freq))
    if date_to:
        cap = min(pd.Timestamp(date_to), pd.Timestamp(as_of)) if as_of else pd.Timestamp(date_to)
        end = max(end, pd.Period(cap, freq))
    rng = pd.period_range(start, end, freq=freq)
    fmt = {"year": "%Y", "month": "%Y-%m"}.get(grain)
    return [str(p) if grain == "quarter" else p.strftime(fmt) for p in rng]


def part_keys(grain, months=None):
    """Every key of a date part, in calendar order; with a months filter, only the parts
    those months fall in (December only: one month bar, the Winter season)."""
    keys = DATE_PART_KEYS[grain]
    if not months:
        return list(keys)
    ms = {int(m) for m in months}
    if grain == "month_of_year":
        return [k for k in keys if int(k) in ms]
    if grain == "quarter_of_year":
        return [k for k in keys if any((m - 1) // 3 + 1 == int(k[1]) for m in ms)]
    if grain == "season":
        return [k for k in keys if any(SEASON_OF_MONTH[m] == k for m in ms)]
    return list(keys)


def _colors(pack, spec, order):
    """Fixed pack colours; a one-series chart of one product wears its colour."""
    names = list(order)
    products = spec["filters"].get("products") or []
    if order == ["All jobs"] and len(products) == 1:
        names = products
    return dict(zip(order, pack.color_for(names)))


def _empty(note):
    return {"columns": ["period", "group", "value", "jobs", "revenue", "pct"], "rows": [],
            "totals": {"value": None, "by_period": {}}, "units_estimated": False,
            "group_totals": [],
            "groups": [], "periods": [], "colors": {}, "notes": [note],
            "chart_type": "table", "empty": True}


FACET_FILTER = {"product": "products", "work_type": "work_types", "salesperson": "salespeople",
                "area": "areas", "account": "accounts"}
MAX_FACETS = 6


def run(data, pack, spec, top_n=None):
    """Compute the result for a normalized spec. With a facet, the result is the
    overall chart plus one small chart per facet value ("small multiples"),
    all sharing the overall chart's colours."""
    import copy
    facet = spec.get("facet") or "none"
    if facet == "none" or facet not in FACET_FILTER or spec.get("chart_type") == "venn" \
            or facet == spec.get("split"):
        return _run_one(data, pack, spec, top_n)
    base = copy.deepcopy(spec)
    base["facet"] = "none"
    overall = _run_one(data, pack, base, top_n)
    if overall["empty"]:
        return overall
    key = FACET_FILTER[facet]
    values = list(spec["filters"].get(key) or [])
    if not values:
        rank_spec = copy.deepcopy(base)
        rank_spec.update(split=facet, time_grain="none", chart_type="auto")
        ranked = _run_one(data, pack, rank_spec, MAX_FACETS + 1)
        from .accounts import OTHER as ACCT_OTHER
        values = [g for g in ranked["groups"] if g not in (ALL_OTHER, ACCT_OTHER)]
    shown = values[:MAX_FACETS]
    facets = []
    for v in shown:
        sub = copy.deepcopy(base)
        sub["filters"][key] = [v]
        r = _run_one(data, pack, sub, top_n)
        r["colors"] = {g: overall["colors"].get(g, c) for g, c in r["colors"].items()}
        facets.append({"name": v, "result": r})
    overall["facet"] = facet
    overall["facets"] = facets
    if len(values) > MAX_FACETS:
        overall["notes"].append(f"One small chart each for the top {MAX_FACETS}; the rest are "
                                "in the overall chart.")
    return overall



def group_rows(data, pack, spec, rows, notes=None, windows=None):
    """each row's group under the spec's split -- the one grouping rule, factored out of _run_one so the
    weather can be measured per group (weatherview). Returns (rows, overlap, empty): `empty` is an _empty() result when
    the split leaves nothing (a tag split with no such tags), else None."""
    split = spec["split"]
    notes = [] if notes is None else notes
    windows = spec.get("windows") or [] if windows is None else windows
    overlap = False
    rows = rows.copy()
    if split == "product":
        rows["group"] = rows["product"]
    elif split == "work_type":
        rows["group"] = rows["action"]
    elif split == "salesperson":
        rows["group"] = rows["salesperson"].fillna(UNKNOWN).replace("", UNKNOWN)
    elif split == "area" and map_by_zip(spec, pack):
        # a map by ZIP draws the ZIP outlines, not circles: each job counts in its customer's ZIP;
        # a town is however many ZIPs it covers.
        zmap = zip_of_customer(data)
        rows["group"] = rows["customer_key"].astype(str).map(lambda k: zip_label(pack, zmap.get(k, "")))
        est = estimated_zip_keys(data)
        if est and "invoice_no" in rows:
            n_est = rows.loc[rows["customer_key"].astype(str).isin(est), "invoice_no"].nunique()
            n_all = rows["invoice_no"].nunique()
            if n_est:
                notes.append(f"{n_est:,} of {n_all:,} jobs are placed by an estimated ZIP: the customer's record "
                             "has no ZIP, so it was taken from the street or town.")
    elif split == "area":
        if "town_basis" in rows:
            n_zip = rows.loc[rows["town_basis"].eq("zip"), "invoice_no"].nunique()
            if n_zip:
                notes.append(f"{n_zip:,} job{'s' if n_zip != 1 else ''} named no town the invoice could be read for; "
                             "they are counted in their customer's ZIP town.")
        # the store keeps towns as typed, so "Ntown", "W Brook", "Westbrok" were bars
        # (and map rows) of their own. The pack's aliases already fold them for filters; fold them here too.
        canon = {v.casefold(): a for a in pack.areas for v in pack.area_variants(a)}
        rows["group"] = rows["area"].fillna("").map(lambda a: canon.get(str(a).casefold(), a) if a else "(unknown)")
    elif split == "region":
        # each job in the region its town is in ("(other)" for a town the pack lists in no region)
        rows["group"] = _town_of_rows(data, pack, rows).map(pack.region_of_area)
        if not getattr(pack, "regions", None):
            notes.append("This pack draws no regions yet (territories.yml: regions), so every job is \"(other)\".")
    elif split in TERRITORY_SPLITS:
        # each job in its customer's safety-site site / safety council (the first, if two overlap)
        tmap = territory_of_customer(data, "safety_site" if split == "safety_site" else "safety_council")
        none = TERRITORY_SPLITS[split]
        rows["group"] = rows["customer_key"].astype(str).map(lambda k: (tmap.get(k) or [none])[0])
        if not any(tmap.values()):                                 # every customer is a key
            notes.append("No customer has been matched to these areas yet: the next import does it (the areas "
                         "are read first; see zorderz_hub safety status).")
    elif split in PLACE_SPLITS:
        # each job in its customer's sales tax area / distance band from the coast
        pmap = place_of_customer(data, split)
        none = PLACE_SPLITS[split]
        rows["group"] = rows["customer_key"].astype(str).map(lambda k: pmap.get(k, none))
        if all(v == none for v in pmap.values()):
            notes.append("No customer has been placed yet: the import does it once the region's address points are "
                         "on the hub computer (see zorderz_hub places status).")
        elif split == "coast":                                     # say the rule once
            from .places import coast_side_miles
            mi = coast_side_miles()
            notes.append(f"Coastal = the customer's home within {mi:g} miles of the coast "
                         f"(straight line, the region's coastline); inland = {mi:g} miles or more.")
    elif split == "payment":                                       # each job by its invoice's payment state
        state = payment_of_invoice(data)
        rows["group"] = rows["invoice_no"].astype(str).map(lambda n: state.get(n, "Unpaid"))
    elif split == "account":
        from .accounts import OTHER as ACCT_OTHER
        amap = data.customers.set_index("customer_key")["account_key"] \
            if "account_key" in data.customers else pd.Series(dtype=object)
        rows["group"] = rows["customer_key"].map(amap).fillna(ACCT_OTHER)
    elif split == "lead_source":
        src = data.invoices.set_index("invoice_no")["lead_source"]
        rows["group"] = rows["invoice_no"].map(src).fillna("").replace("", UNKNOWN)
    elif split == "referral_partner":
        # the partner business written as the job's source;
        # "how many times did they refer us" counts those jobs.
        col = "partner" if "partner" in data.invoices else None
        src = data.invoices.set_index("invoice_no")[col] if col else pd.Series(dtype=object)
        rows["group"] = rows["invoice_no"].map(src).fillna("").replace("", NOT_A_PARTNER)
    elif split == "original_source":
        col = "original_source" if "original_source" in data.invoices else "lead_source"
        src = data.invoices.set_index("invoice_no")[col]
        rows["group"] = rows["invoice_no"].map(src).fillna("").replace("", UNKNOWN)
    elif split.startswith("tag_"):
        group_name = pack.tag_group_for_split(split)
        if group_name is None:
            raise SpecError("unknown split")
        rows = rows.assign(tag=rows["tags"].fillna("").str.split("; ")).explode("tag")
        rows = rows[rows["tag"].fillna("").str.startswith(group_name + ": ")]
        rows["group"] = rows["tag"].str.split(": ", n=1).str[1]
        # a split by a tag group under a tag filter shows only the tags asked for
        # (and any merged into them): a Brightline line also tagged Classic is a Brightline bar,
        # not a stray Classic one.
        asked = set((spec.get("filters") or {}).get("tags") or [])
        merged = {k for k, v in (spec.get("merge_groups") or {}).items() if v in asked}
        if asked & set(rows["group"].dropna()):
            rows = rows[rows["group"].isin(asked | merged)]
            # a line tagged with two of the brands asked for ("Brightline" and
            # "Northstar") counts once, under the one named first on it, and the chart says so;
            # the groups are then parts of one whole and can be stacked or shown as shares.
            twice = rows.index.duplicated(keep="first")
            if twice.any():
                n_both = int(rows.index[twice].nunique())
                rows = rows[~twice]
                both = " and ".join(sorted(asked & set(rows["group"].dropna())))
                notes.append(f"{n_both:,} line{'' if n_both == 1 else 's'} named both {both}: "
                             f"{'it is' if n_both == 1 else 'each is'} counted once, under the one named first.")
        overlap = True
        if rows.empty:
            return rows, overlap, _empty("Nothing to show for that split.")
    else:
        rows["group"] = "All jobs"
    window_as = spec.get("window_as", "period") if windows else None
    if window_as == "group":
        rows["group"] = rows["_win"]
        overlap = False
    if spec.get("merge_groups"):
        # "include Classic within Northstar's count"; one row counts once per group
        rows["group"] = rows["group"].replace(dict(spec["merge_groups"]))
        rows = rows.assign(_rid=rows.index).drop_duplicates(["_rid", "group"]).drop(columns="_rid")
    if overlap and split.startswith("tag_") and rows.index.is_unique:
        # with only the tags asked for, no line sits in two groups, so the
        # groups are parts of one whole and can be stacked or shown as shares, like products.
        overlap = False

    return rows, overlap, None


def _run_one(data, pack, spec, top_n=None):
    """Compute one chart for a normalized spec. top_n=None uses the spec's."""
    if spec.get("chart_type") == "venn":
        return venn(data, pack, spec)
    measure, split, grain = spec["measure"], spec["split"], spec["time_grain"]
    top_n = spec.get("top_n", 7) if top_n is None else top_n
    if spec.get("split") in ("lead_source", "original_source", "referral_partner", "salesperson") and top_n == 7:
        top_n = 25          # every channel group gets its own bar, not "All other"
    if spec.get("chart_type") == "map" and (split == "area" or split in SHAPE_SPLITS):
        top_n = 0           # a map shows every town, whatever Top says; "All other" has no place
    rows = select(data, pack, spec)
    notes, overlap = [], False
    if (spec.get("filters") or {}).get("regions"):            # a region nothing is drawn for
        _r, _t = pack.regions_and_towns_of(spec["filters"]["regions"])
        if not _r and not _t:
            notes.append("No region called " + ", ".join(map(str, spec["filters"]["regions"][:3])) +
                         " is drawn (territories.yml: regions), so nothing matched.")
    money = measure in {"revenue", "avg_job"}
    windows = spec.get("windows") or []
    if windows and not rows.empty:
        # periods compared side by side.
        # Each window takes its own rows (a row in two windows counts in both), labelled;
        # window_as "period" puts the windows along the axis, "group" makes one series each.
        parts = []
        for w in windows:
            a, b = pd.Timestamp(w["from"]), pd.Timestamp(w["to"]) + pd.Timedelta(days=1)
            parts.append(rows[(rows["issued"] >= a) & (rows["issued"] < b)].assign(_win=w["label"]))
        rows = pd.concat(parts, ignore_index=True)

    if rows.empty:
        return _empty(notes[0] if notes else "No matching jobs for these filters.")

    rows, overlap, empty = group_rows(data, pack, spec, rows, notes, windows)
    if empty is not None:
        return empty
    window_as = spec.get("window_as", "period") if windows else None
    rows["period"] = rows["_win"] if window_as == "period" else _period(rows["issued"], grain)
    ranking = _aggregate(rows, ["group"]).sort_values(measure, ascending=False, kind="stable")
    keep = ranking["group"].head(top_n).tolist() if top_n else ranking["group"].tolist()
    if window_as == "group":
        keep = [w["label"] for w in windows if w["label"] in set(ranking["group"])]
    if split == "coast_band":
        from .places import COAST_BANDS
        band_at = {label: i for i, (_top, label) in enumerate(COAST_BANDS)}
        keep = sorted(keep, key=lambda g: band_at.get(g, len(band_at)))
    elif split == "coast":                          # coastal, then inland, then the unplaced
        from .places import COAST_SIDES
        keep = sorted(keep, key=lambda g: (COAST_SIDES + ("(not placed)",)).index(g) if g in COAST_SIDES + ("(not placed)",) else 9)
    elif split == "payment":
        keep = sorted(keep, key=lambda g: PAYMENT_ORDER.index(g) if g in PAYMENT_ORDER else 9)
    rows["group"] = rows["group"].where(rows["group"].isin(keep), ALL_OTHER)
    table = _aggregate(rows, ["period", "group"])
    by_period = _aggregate(rows, ["period"]).set_index("period")
    grand = _aggregate(rows, [])

    order = [g for g in keep if g in set(table["group"])]
    if ALL_OTHER in set(table["group"]):
        order.append(ALL_OTHER)
    rank = {g: i for i, g in enumerate(order)}
    table = table.assign(_r=table["group"].map(rank)).sort_values(["period", "_r"])

    periods = _fill_periods(sorted(by_period.index), grain, spec.get("date_from"),
                            spec.get("date_to"), data.meta.get("data_as_of"))
    if window_as == "period":
        periods = [w["label"] for w in windows]
    elif grain in DATE_PART_KEYS:
        periods = part_keys(grain, (spec.get("filters") or {}).get("months"))
    additive = measure in {"revenue", "jobs", "units"} and not overlap
    bar_sum = table.groupby("period")[measure].transform("sum")
    pct = (table[measure] / bar_sum * 100).where(bar_sum > 0)
    out_rows = [[p, g, _clean_number(v, money), int(j), _clean_number(r, True),
                 (round(float(x), 1) if additive and x == x else None)]
                for p, g, v, j, r, x in zip(table["period"], table["group"], table[measure],
                                            table["jobs"], table["revenue"], pct)]
    # Whole-range totals per group: what pie, donut and waffle draw.
    per_group = _aggregate(rows, ["group"]).set_index("group")
    whole = float(per_group[measure].sum()) if additive else 0.0
    group_totals = [{"group": g, "value": _clean_number(per_group.loc[g, measure], money),
                     "jobs": int(per_group.loc[g, "jobs"]),
                     # NaN here is not JSON the site can read, and it used to drop the whole
                     # answer.
                     "pct": (round(float(per_group.loc[g, measure]) / whole * 100, 1)
                             if additive and whole > 0 and pd.notna(per_group.loc[g, measure]) else None)}
                    for g in order]
    # A period with NO jobs sold none: 0 revenue, jobs, customers AND units
    # (units there were shown blank, which read as "unknown" for the years before a
    # product was sold). Blank stays for jobs whose count is unknown, and
    # for an average of nothing.
    zero_ok = measure in {"revenue", "jobs", "customers", "units"}

    def per(p, col, money_col=False):
        if p in by_period.index:
            return _clean_number(by_period.loc[p, col], money_col)
        return 0 if zero_ok or col == "jobs" else None
    totals = {
        "value": _clean_number(grand[measure], money),
        "jobs": grand["jobs"], "revenue": round(grand["revenue"], 2),
        "by_period": {p: per(p, measure, money) for p in periods},
        "jobs_by_period": {p: per(p, "jobs") for p in periods},
    }
    estimated = bool(measure == "units" and rows["estimated"].any())
    if measure == "units":
        blank = rows.loc[rows["units"].isna(), "invoice_no"].nunique()
        measured_jobs = rows.loc[rows["measured"], "invoice_no"].nunique() \
            if "measured" in rows else 0
        if measured_jobs:
            notes.append(f"{measured_jobs} job{'' if measured_jobs == 1 else 's'} use{'s' if measured_jobs == 1 else ''} a unit count from the job's measurement note "
                         "or the units listed on its invoice.")
        if estimated:
            notes.append("Units include estimates for lot bids and bundled lines.")
        if blank:
            notes.append(f"{blank} jobs have no unit count; they are left blank, not zero.")
    if (spec.get("filters") or {}).get("exclude_lead_sources"):
        src = data.invoices.set_index("invoice_no")["lead_source"].fillna("").astype(str).str.strip()
        jobs = set(rows["invoice_no"].unique())
        from .lead_sources import NEW_UNKNOWN                     # one name, from lead_sources
        unknown = int(src[src.index.isin(jobs)].isin(["", NEW_UNKNOWN]).sum())
        left = ", ".join((spec.get("filters") or {})["exclude_lead_sources"])
        notes.append(f"Leaves out jobs tagged {left}. {unknown} of the {len(jobs)} jobs shown "
                     "have no lead source recorded; they are counted, since they aren't tagged "
                     + left + ".")
    if "share" in rows and (rows["share"] < 1).any():
        n_shared = int(rows.loc[rows["share"] < 1, "invoice_no"].nunique())
        notes.append(f"{n_shared:,} job{'' if n_shared == 1 else 's'} had two people on {'it' if n_shared == 1 else 'them'} (a salesperson and an installer): each gets "
                     "credit for the job and an equal share of its revenue, so the totals are not "
                     "counted twice.")
    rest = [g for g in ranking["group"].tolist() if g not in keep]
    if rest and split not in ("account", "none"):
        notes.append(f"{ALL_OTHER}: " + ", ".join(str(g) for g in rest[:12]) +
                     (f" and {len(rest) - 12} more" if len(rest) > 12 else ""))
    if (split == "salesperson" or (spec.get("filters") or {}).get("salespeople")) \
            and "salesperson_basis" in data.invoices:
        # jobs whose invoice named nobody carry the pack's default salesperson
        basis = data.invoices.drop_duplicates("invoice_no").set_index("invoice_no")["salesperson_basis"]
        shown = pd.Index(rows["invoice_no"].unique())
        n_default = int(basis.reindex(shown).fillna("").eq("default").sum())
        if n_default:
            notes.append(f"{n_default:,} of the {len(shown):,} jobs shown had no salesperson's initials on the "
                         "invoice; they are counted under the default salesperson set at import.")
    if overlap:
        notes.append("A job can carry several details, so groups overlap.")
    if split == "product" and measure in {"jobs", "customers"}:
        notes.append("A job covering two products counts once under each, so groups can "
                     "add up to more than the total, which is the true total.")

    stackable = additive
    chart_type = spec.get("chart_type", "auto")
    if chart_type in {"pie", "donut", "waffle"}:
        if not stackable:
            chart_type = "grouped_bar"
            notes.append("Shown as bars: a pie, donut or waffle needs parts of one whole, and "
                         "customers, averages or overlapping groups don't add up that way.")
        elif len(order) < 2:
            chart_type = "single_number"
            notes.append("Only one group to show, so there's nothing to divide into slices.")
        elif len(periods) > 1:
            notes.append("Slices show the whole date range combined, not period by period.")
    if chart_type == "percent_bar" and not stackable:
        chart_type = "line" if len(periods) > 1 else "grouped_bar"
        notes.append("Shown without percentages: customers, averages and overlapping details "
                     "don't add up to 100%.")
    if chart_type == "area" and not stackable:
        chart_type = "line"
    if chart_type in ("line", "area") and len(periods) <= 1:
        # one period has no line to draw
        chart_type = "grouped_bar"
    if chart_type == "map" and split != "area" and split not in SHAPE_SPLITS:
        chart_type = "grouped_bar"            # only towns have places on the map
        notes.append("Shown as bars: a map shows towns, and this chart isn't split by town.")
    single = len(periods) == 1 and len(order) == 1
    if chart_type == "auto":
        if single or spec.get("intent") == "number":
            chart_type = "single_number"
        elif not stackable:
            chart_type = "line" if len(periods) > 1 else "grouped_bar"
        else:
            chart_type = "stacked_bar"
    elif chart_type == "stacked_bar" and not stackable:
        chart_type = "line" if len(periods) > 1 else "grouped_bar"
        notes.append("Shown unstacked: customers, averages and overlapping details can't be stacked.")

    if spec.get("labels") in {"percent", "value_percent"} and not stackable:
        notes.append("Percentages left off: these groups don't add up to one total.")

    out = {
        "columns": ["period", "group", "value", "jobs", "revenue", "pct"],
        "rows": out_rows, "totals": totals, "units_estimated": estimated,
        "group_totals": group_totals,
        "groups": order, "periods": periods,
        "colors": _colors(pack, spec, order),
        "notes": notes, "chart_type": chart_type, "empty": False,
        "additive": bool(additive),           # groups add up to the total (stacks and pies allowed)
    }
    if split == "area" and chart_type == "map" and map_by_zip(spec, pack):
        # which ZIP each group is, for the page's ZIP shapes ("(no ZIP)" has none)
        out["zips"] = {g: g[:5] for g in order if g[:5].isdigit()}
        nz = next((t["value"] for t in group_totals if t["group"] == NO_ZIP), None)
        if nz:
            notes.append("Jobs are placed by their customer's ZIP; \"(no ZIP)\" is customers with none on file.")
    return out


# ---------------------------------------------------------------- venn
VENN_MIN = 5          # overlaps smaller than this are shown as "fewer than 5"


def venn(data, pack, spec):
    """Overlap of 2-3 groups: how many customers (or jobs) fall in each region.
    Counts only: no dollars, no rows that could point at a customer. Regions
    smaller than VENN_MIN are suppressed, so a tiny overlap can't single
    anyone out. Runs within all of the spec's filters (and the caller's scope)."""
    by, sets = spec.get("venn_by", "none"), list(spec.get("venn_sets") or [])[:3]
    unit = "jobs" if spec.get("measure") == "jobs" else "customers"
    rows = select(data, pack, spec)
    if rows.empty:
        return _empty("No matching jobs for these filters.")
    if by == "product":
        rows["member"] = rows["product"]
    elif by == "work_type":
        rows["member"] = rows["action"]
    elif by == "salesperson":
        rows["member"] = rows["salesperson"].fillna(UNKNOWN).replace("", UNKNOWN)
    elif by == "tag":
        rows = rows.assign(member=rows["tags"].fillna("").str.split("; ")).explode("member")
        rows["member"] = rows["member"].fillna("").str.split(": ", n=1).str[-1]
    else:
        raise SpecError("venn needs venn_by")
    key = "invoice_no" if unit == "jobs" else "customer_key"
    rows = rows[rows["member"].isin(sets)]
    if rows.empty:
        return _empty("None of those groups had matching jobs.")
    member = rows.groupby(key)["member"].agg(lambda m: frozenset(m))
    from itertools import combinations
    regions, periods, out_rows = [], [], []
    for n in range(1, len(sets) + 1):
        for combo in combinations(sets, n):
            exact = int((member == frozenset(combo)).sum())
            hidden = 0 < exact < VENN_MIN
            label = " + ".join(combo) + (" only" if n == 1 and len(sets) > 1 else "")
            regions.append({"sets": list(combo), "count": None if hidden else exact,
                            "suppressed": hidden})
            periods.append(label)
            out_rows.append([label, unit.capitalize(), None if hidden else exact, None, None, None])
    hidden_any = any(r["suppressed"] for r in regions)
    notes = [f"Counts {unit}: someone in two circles bought (or had) both."]
    if hidden_any:
        notes.append(f"Overlaps under {VENN_MIN} are shown as \"fewer than {VENN_MIN}\" "
                     "so a small group can't point to anyone.")
    notes.append("Circle sizes aren't drawn to scale; the numbers are exact.")
    return {"columns": ["period", "group", "value", "jobs", "revenue", "pct"],
            "rows": out_rows,
            # No circle totals or overall total when a region is hidden: they would
            # let anyone subtract their way back to the hidden number.
            "totals": {"value": None if hidden_any else int(len(member)), "by_period": {}},
            "units_estimated": False, "group_totals": [],
            "groups": [unit.capitalize()], "periods": periods, "colors": dict(zip(sets, pack.color_for(sets))),
            "venn": {"by": by, "sets": sets, "unit": unit, "regions": regions,
                     "min": VENN_MIN},
            "notes": notes, "chart_type": "venn", "empty": False}


# ---------------------------------------------------------------- golden
def golden(data, pack):
    """The golden_numbers.json shape, computed from the store alone."""
    def section(mode):
        spec = base_spec(pack)
        for s in pack.switches:
            spec[s.field] = mode if mode in s.values else s.default
        rows = select(data, pack, spec)
        counts = rows.groupby("customer_key")["invoice_no"].nunique()
        buckets = pd.cut(counts, [0, 1, 2, 3, 5, 10, math.inf],
                         labels=["1 job", "2 jobs", "3 jobs", "4–5 jobs",
                                 "6–10 jobs", "11+ jobs"])
        dist = buckets.value_counts().reindex(buckets.cat.categories, fill_value=0)
        prod = _aggregate(rows, ["product"]).sort_values("revenue", ascending=False)
        year = rows.assign(y=rows["issued"].dt.year.astype(str))
        by_year = _aggregate(year, ["y"]).sort_values("y")
        switch_product = pack.switches[0].set_product if pack.switches else None
        sp_year = _aggregate(year[year["product"].eq(switch_product)], ["y"]).sort_values("y") \
            if switch_product else pd.DataFrame()
        work_value = float(pd.to_numeric(data.work["line_cents"]).sum()) / 100
        cust = data.customers
        listed = cust["listed_invoices"] if "listed_invoices" in cust else None
        return {
            "customer_records": int(len(cust)),
            "customers_with_no_invoices": int(listed.eq(0).sum()) if listed is not None else None,
            "customers_with_a_job": int(counts.size),
            "jobs": int(rows["invoice_no"].nunique()),
            "repeat_customers_2plus": int(counts.ge(2).sum()),
            "repeat_jobs_after_first": int((counts - 1).clip(lower=0).sum()),
            "work_value_usd": round(work_value, 2),
            "revenue_allocation_total_usd": round(float(rows["cents"].sum()) / 100, 2),
            "repeat_distribution": {k: int(v) for k, v in dist.items()},
            "revenue_by_product": {r.product: {"revenue_usd": round(r.revenue, 2), "jobs": int(r.jobs)}
                                   for r in prod.itertuples()},
            "by_year": {r.y: {"jobs": int(r.jobs), "revenue_usd": round(r.revenue, 2)}
                        for r in by_year.itertuples()},
            _slug(switch_product) + "_by_year": {r.y: {"jobs": int(r.jobs), "revenue_usd": round(r.revenue, 2)}
                                for r in sp_year.itertuples()},
        }
    modes = [s.values for s in pack.switches][:1]
    if not modes:
        return {"default": section(None)}
    on, off = pack.switches[0].on_value, [v for v in modes[0] if v != pack.switches[0].on_value][0]
    # Section names follow the golden-number file: "default" and "strict_<rule>".
    return {"default": section(on), "strict_" + pack.switches[0].field[:-len("_mode")]: section(off)}


def _slug(text):
    import re
    return re.sub(r"[^a-z0-9]+", "_", str(text or "product").lower()).strip("_")


def base_spec(pack):
    """The neutral spec: everything, all history. Chips start from this."""
    spec = {
        "intent": "chart", "title": "", "measure": "revenue", "split": "none",
        "time_grain": "year", "facet": "none", "date_from": None, "date_to": None,
        "filters": {"products": [], "work_types": [], "salespeople": [], "areas": [], "lead_sources": [],
                    "tags": [], "accounts": [], "customer_type": "any", "repeat_customers_only": False},
        "include_unpaid": pack.include_unpaid_default, "chart_type": "auto",
        "orientation": "auto", "labels": "none", "venn_by": "none", "venn_sets": [], "top_n": 7,
        "assumptions": [], "clarify_question": None, "clarify_options": [],
    }
    spec.update(pack.switch_defaults())
    return spec


FLOOR_FIELDS = ("measure", "split", "time_grain", "facet", "date_from", "date_to", "include_unpaid")


def floor_key(spec, view="chart"):
    """A short, stable key for 'what this floor shows'. Presentation (title, chart type,
    orientation, labels, top_n) does not change the floor; the question does. `view` is
    "chart", "jobs" or "invoice:<no>" / "estimate:<no>"."""
    import hashlib
    import json
    spec = spec if isinstance(spec, dict) else {}
    canon = {"view": str(view)}
    if not str(view).startswith(("invoice:", "estimate:")):
        canon.update({k: spec.get(k) for k in FLOOR_FIELDS})
        canon.update({k: v for k, v in spec.items() if isinstance(k, str) and k.endswith("_mode")})
        f = spec.get("filters") if isinstance(spec.get("filters"), dict) else {}
        canon["filters"] = {k: (sorted(str(x) for x in v) if isinstance(v, list) else v)
                            for k, v in sorted(f.items()) if v not in ([], None, False, "any", "")}
        if spec.get("chart_type") == "venn":
            canon["venn"] = [spec.get("venn_by"), sorted(str(x) for x in spec.get("venn_sets") or [])]
        cw = spec.get("compare_windows")                # "2024 vs 2025" is not the 2025 floor
        if isinstance(cw, dict) and isinstance(cw.get("windows"), list):
            canon["compare"] = [cw.get("as"), [[w.get("from"), w.get("to")] for w in cw["windows"]
                                               if isinstance(w, dict)]]
        if canon.get("measure") in {None, ""}:
            canon.pop("measure")
    raw = json.dumps(canon, sort_keys=True, separators=(",", ":"), default=str)
    return "f_" + hashlib.sha256(raw.encode()).hexdigest()[:16]


# Names and contact details are never here; the website adds them from its own FreshBooks
# ledger for the people allowed to see them.
INVOICE_NO = r"^[A-Za-z0-9-]{1,20}$"


def norm_no(no):
    import re
    s = str(no or "").strip()
    if not re.fullmatch(INVOICE_NO, s, flags=re.ASCII):
        return None
    return s.lstrip("0") or "0"


def _day(v):
    return v.strftime("%Y-%m-%d") if hasattr(v, "strftime") and not pd.isna(v) else None


def _str(v):
    return v if isinstance(v, str) else ""


def _num(v):
    try:
        if v is None or pd.isna(v):
            return None
    except (TypeError, ValueError):
        return None
    f = float(v)
    return int(f) if f.is_integer() else round(f, 2)


def invoice_row(data, invoice_no):
    """The invoice's row (a Series), or None."""
    no = norm_no(invoice_no)
    if no is None or data is None:
        return None
    inv = data.invoices
    hit = inv[inv["invoice_no"].astype(str).str.lstrip("0").replace("", "0") == no]
    return None if hit.empty else hit.iloc[0]


def _key(v):
    """A customer key, or None (blank, NaN, "nan")."""
    if v is None:
        return None
    try:
        if pd.isna(v):
            return None
    except (TypeError, ValueError):
        pass
    v = str(v).strip()
    return v if v and v.casefold() not in {"nan", "none", "<na>"} else None


def customer_history(data, customer_key, issued=None, invoice_no=None, rep=None):
    """(jobs_total, job_index, first, last) of one customer, by its key."""
    if customer_key is None:
        return 0, None, None, None
    w = data.work
    mine = w[w["customer_key"].astype(str) == str(customer_key)]
    if rep is not None:
        # the invoice's own salesperson, the one the card's access check reads
        theirs = set(data.invoices.loc[data.invoices["salesperson"].fillna("") == rep, "invoice_no"].astype(str))
        mine = mine[mine["invoice_no"].astype(str).isin(theirs)]
    per = mine.groupby("invoice_no")["issued"].min().sort_values(kind="stable")
    total = int(len(per))
    index = None
    if invoice_no is not None and str(invoice_no) in set(per.index.astype(str)):
        index = int((per < per.loc[str(invoice_no)]).sum()) + 1
    first = _day(per.min()) if total else None
    last = _day(per.max()) if total else None
    return total, index, first, last


def invoice_card(data, pack, invoice_no, money=True, by_person=True, rep=None):
    """(card, ctx) for one invoice, or (None, None). ctx is the hub's own context for the
    pivots (never sent as is: it holds the customer key). With `rep` (a salesperson's own
    card), everything about the customer's other jobs is that rep's jobs only."""
    from .lead_sources import FROM_HISTORY
    row = invoice_row(data, invoice_no)
    if row is None:
        return None, None
    no = str(row["invoice_no"])
    lines = data.lines[data.lines["invoice_no"].astype(str) == no].copy()
    lines = lines.sort_values("line_id", kind="stable")
    measured = getattr(data, "measured", None) or {}
    out_lines, products, works, tags = [], [], [], []
    work_cents = 0
    for r in lines.itertuples():
        product = _str(r.family)
        work = bool(r.work)
        cents = None if pd.isna(r.line_cents) else int(r.line_cents)
        if work and cents is not None:
            work_cents += cents
        rate = getattr(r, "rate_cents", None)
        rate = None if rate is None or pd.isna(rate) else int(rate)
        est = r.est_units if not pd.isna(r.est_units) else None
        units = r.units if not pd.isna(r.units) else None
        line_tags = sorted(_tag_names(r.tags))
        # a product line can be tapped even when the store doesn't count it as work (a
        # draft, a $0 line): the pivot itself counts by the chart rules
        sold = product in pack.products
        if sold and product not in products:
            products.append(product)
        if sold and _str(r.action) in pack.work_types and r.action not in works:
            works.append(r.action)
        for t in line_tags:
            if sold and t not in tags:
                tags.append(t)
        out_lines.append({
            "line_id": str(r.line_id), "text": _str(r.description_clean), "product": product,
            "work_type": _str(r.action), "work": work, "qty": _num(r.qty), "units": _num(units),
            "est_units": _num(est) if (est is not None and units is None) or _str(r.est_basis) else None,
            "estimated_basis": _str(r.est_basis) or None,
            "rate": round(rate / 100, 2) if money and rate is not None else None,
            "amount": round(cents / 100, 2) if money and cents is not None else None,
            "tags": line_tags,
            "measured": next((int(c[no]) for p, c in measured.items() if p == product and no in c), None) if work else None,
            "tappable": sold,
        })
    ckey = _key(row.get("customer_key"))
    total, index, first, last = customer_history(data, ckey, row.get("issued"), no, rep)
    is_business = None
    if ckey and "customer_key" in data.customers:
        c = data.customers[data.customers["customer_key"].astype(str) == ckey]
        if not c.empty:
            is_business = bool(c.iloc[0]["is_business"])
    est_n = 0
    ests = getattr(data, "estimates", None)
    if ckey and ests is not None and len(ests) and "customer_key" in ests:
        hit = ests["customer_key"].astype(str) == ckey
        if rep is not None:
            hit &= ests["salesperson"].fillna("") == rep
        est_n = int(hit.sum())
    parsed = row.get("parsed_cents") if "parsed_cents" in row else None
    parsed = None if parsed is None or pd.isna(parsed) else int(parsed)
    source = _str(row.get("lead_source"))
    original = _str(row.get("original_source")) or None
    if rep is not None:
        # "found from history" and "first found us" are read off the customer's OTHER jobs,
        # which may be another rep's: not on a salesperson's card
        original = None
        if source == FROM_HISTORY:
            source = ""
    rep_of = _str(row.get("salesperson")) or UNKNOWN
    card = {
        "kind": "invoice", "invoice_no": no, "date": _day(row.get("issued")),
        "paid_date": _day(row.get("paid")) if "paid" in row else None,
        "status": _str(row.get("status")) or None,
        "salesperson": rep_of,
        "town": _str(row.get("area")) or None,
        "lead_source": source or None, "partner": _str(row.get("partner")) or None,
        "original_source": original,
        "from_history": source == FROM_HISTORY,
        "history": {"is_business": is_business, "jobs_total": total, "job_index": index,
                    "first_job": first, "last_job": last, "estimates": est_n},
        "lines": out_lines,
        "totals": {"work": round(work_cents / 100, 2) if money else None,
                   "invoice": round(parsed / 100, 2) if money and parsed is not None else None,
                   "lines": len(out_lines), "work_lines": sum(1 for x in out_lines if x["work"])},
        "money": bool(money),
    }
    ctx = {"kind": "invoice", "no": no, "customer": ckey or None, "products": products, "works": works,
           "tags": tags, "area": _str(row.get("area")) or None, "rep": rep_of, "source": source or None,
           "partner": _str(row.get("partner")) or None,
           "original_source": original,
           "issued": row.get("issued"), "customer_jobs": total, "is_business": is_business,
           "own_rep": rep,
           "estimates": est_n,
           "lines": {x["line_id"]: {"product": x["product"], "work": x["work_type"], "tags": x["tags"]}
                     for x in out_lines if x["tappable"]}}
    return card, ctx


def estimate_row(data, estimate_no):
    no = norm_no(estimate_no)
    ests = getattr(data, "estimates", None)
    if no is None or ests is None or not len(ests) or "estimate_no" not in ests:
        return None
    hit = ests[ests["estimate_no"].astype(str).str.lstrip("0").replace("", "0") == no]
    return None if hit.empty else hit.iloc[0]


def estimate_card(data, pack, estimate_no, money=True, rep=None):
    """(card, ctx) for one estimate (no line items: the store keeps an estimate's products
    and total only), or (None, None)."""
    from .lead_sources import group_of
    row = estimate_row(data, estimate_no)
    if row is None:
        return None, None
    import re
    raw = row.get("products")
    if isinstance(raw, (list, tuple)):
        products = [str(p) for p in raw if str(p)]
    else:
        products = [p.strip() for p in re.split(r";|,(?! )", _str(raw)) if p.strip()]
    products = [p for p in products if p in pack.products]
    ckey = _key(row.get("customer_key"))
    when = pd.to_datetime(row.get("date"), errors="coerce")
    total, _, first, last = customer_history(data, ckey, rep=rep)
    cents = row.get("total_cents")
    cents = None if cents is None or pd.isna(cents) else int(cents)
    src = group_of(_str(row.get("lead_source"))) if _str(row.get("lead_source")) else ""
    rep_of = _str(row.get("salesperson")) or UNKNOWN
    became = row.get("became_job")
    card = {
        "kind": "estimate", "invoice_no": str(row["estimate_no"]), "date": _day(when),
        "status": None, "salesperson": rep_of, "town": _str(row.get("area")) or None,
        "lead_source": src or None, "partner": None, "original_source": None, "from_history": False,
        "became_job": None if became is None or pd.isna(became) else bool(became),
        "history": {"is_business": None, "jobs_total": total, "job_index": None,
                    "first_job": first, "last_job": last, "estimates": None},
        "lines": [{"line_id": f"p{i}", "text": "", "product": p, "work_type": "", "work": True, "qty": None,
                   "units": None, "est_units": None, "estimated_basis": None, "rate": None, "amount": None,
                   "tags": [], "measured": None, "tappable": True} for i, p in enumerate(products)],
        "totals": {"work": None, "invoice": round(cents / 100, 2) if money and cents is not None else None,
                   "lines": len(products), "work_lines": len(products)},
        "money": bool(money),
    }
    ctx = {"kind": "estimate", "no": str(row["estimate_no"]), "customer": ckey or None, "products": products,
           "works": [], "tags": [], "area": _str(row.get("area")) or None, "rep": rep_of, "source": src or None,
           "partner": None, "original_source": None, "issued": when, "customer_jobs": total,
           "is_business": None, "estimates": None, "own_rep": rep,
           "lines": {f"p{i}": {"product": p, "work": "", "tags": []} for i, p in enumerate(products)}}
    return card, ctx


def list_estimates(data, customer_key, money=True, scope=None, limit=100, by_person=True):
    """One customer's estimates, newest first (an invoice card's "estimates" pivot)."""
    ests = getattr(data, "estimates", None)
    if ests is None or not len(ests) or "customer_key" not in ests:
        return {"jobs": [], "total": 0}
    mine = ests[ests["customer_key"].astype(str) == str(customer_key)].copy()
    if scope is not None:
        mine = mine[mine["salesperson"].fillna(UNKNOWN).isin(scope)]
    mine["when_"] = pd.to_datetime(mine["date"], errors="coerce")
    mine = mine.sort_values("when_", ascending=False, kind="stable")
    out = []
    for r in mine.head(limit).itertuples():
        cents = getattr(r, "total_cents", None)
        prods = getattr(r, "products", "")
        out.append({"invoice_no": str(r.estimate_no), "kind": "estimate", "date": _day(r.when_),
                    "town": _str(getattr(r, "area", "")),
                    "products": [p.strip() for p in str(prods or "").split(";") if p.strip()][:4],
                    "amount": round(int(cents) / 100, 2) if money and cents is not None and not pd.isna(cents) else None,
                    "salesperson": _str(getattr(r, "salesperson", "")) if by_person else None,
                    "source": "", "partner": ""})
    return {"jobs": out, "total": int(len(mine))}
