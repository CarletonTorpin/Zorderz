"""What each ZIP is like, on a chart.

The facts are the Census's and the state fire agency's (zipfacts.py); every sales number still comes from
compute.run, and is only divided here.

The spec key:

    zipfacts  {view, fact, per}
        view  rate     the chart's numbers per 1,000 homes / households / people of each ZIP  (needs per)
              shade    the ZIP map shaded by a fact (the sales stay a tap away in the shade picker)
              dots     one dot per ZIP: the fact across, the chart's number up, with r          (needs fact)
              profile  the named ZIPs' facts above the chart
        fact  a zipfacts.FACTS key
        per   homes | households | people  (rate, and optionally dots)

Every by-ZIP map also carries the facts of the map's ZIPs (result["zipfacts"]["values"]), so the page can shade
by a fact, rate by homes and show a ZIP's facts in its tooltip without asking again. A chart filtered to one to
three ZIPs (a tapped ZIP) carries their profile.
"""
import numpy as np

from .. import zipfacts as Z
from .weatherview import pearson, strength

VIEWS = ("rate", "shade", "dots", "profile")
RATE_MEASURES = ("revenue", "jobs", "customers", "units")
MIN_DOTS = 8
PROFILE_KEYS = ("population", "households", "homes", "owner_pct", "single_family_pct", "home_value", "income",
                "year_built", "pre1980_pct", "built_1980s_90s_pct", "built_2000_pct", "age", "age65_pct", "density",
                "fire_vh_pct", "fire_h_pct",
                "coast_mi", "fire_count", "last_fire_year", "burned_pct", "vector_q8")   
MEASURE_WORD = {"revenue": "revenue", "jobs": "jobs", "customers": "customers", "units": "units",
                "avg_job": "average job"}
MAX_PROFILE = 4


def check(z):
    """Problems with a zipfacts value (plain words), [] when it's fine."""
    if not isinstance(z, dict):
        return ["zipfacts must be an object"]
    out = []
    view = z.get("view")
    if view not in VIEWS:
        out.append("zipfacts.view must be one of " + ", ".join(VIEWS))
    fact = z.get("fact")
    if fact is not None and fact not in Z.FACTS:
        out.append("zipfacts.fact is not a ZIP fact")
    per = z.get("per")
    if per is not None and per not in Z.BASES:
        out.append("zipfacts.per must be homes, households or people")
    if view == "rate" and not per:
        out.append("a rate needs zipfacts.per")
    if view in ("shade", "dots") and not fact:
        out.append(f"zipfacts.view {view} needs a fact")
    return out


def full(z):
    return {"view": z.get("view"), "fact": z.get("fact"), "per": z.get("per")}


def clean(z):
    """A zipfacts value from outside (the website) when it's valid, else None."""
    if not isinstance(z, dict) or check(z):
        return None
    return {k: v for k, v in full(z).items() if v is not None}


def wants_map(z):
    return isinstance(z, dict) and z.get("view") in ("rate", "shade", "dots")


# ---------------------------------------------------------------- helpers
def _f(v):
    try:
        v = float(v)
    except (TypeError, ValueError):
        return None
    return None if np.isnan(v) else v


def _ok_base(facts_z, per, min_homes=None):
    if not facts_z:
        return None
    homes = _f(facts_z.get("homes"))
    if homes is None or homes < (Z.MIN_HOMES if min_homes is None else min_homes):
        return None
    b = _f(facts_z.get(Z.BASES[per][0]))
    return b if b and b > 0 else None


def _round(v, money):
    if v is None:
        return None
    return round(v, 0) if money and abs(v) >= 100 else round(v, 2)


def _layer(facts, zips_on_map):
    """The facts of the map's ZIPs: {zip: {key: value}} with only keys that have a value somewhere."""
    keys = [k for k in Z.FACTS if any(_f((facts.get(z) or {}).get(k)) is not None for z in zips_on_map)]
    vals = {}
    for z in zips_on_map:
        fz = facts.get(z) or {}
        row = {k: _f(fz.get(k)) for k in keys if _f(fz.get(k)) is not None}
        if row:
            vals[z] = row
    return keys, vals


def _label(pack, z):
    name = (getattr(pack, "zips", {}) or {}).get(z, "")
    return f"{z} {name}" if name else z


def profile_of(facts, pack, z):
    fz = facts.get(z) or {}
    items = []
    for k in PROFILE_KEYS:
        v = _f(fz.get(k))
        if v is None:
            continue
        items.append({"key": k, "label": Z.label_of(k), "text": Z.fmt(v, Z.unit_of(k))})
    # the place facts that are words, not numbers
    if _f(fz.get("tax_rate")) is not None:
        items.append({"key": "tax_rate", "label": "Sales tax", "text": f"{_f(fz['tax_rate']):.2f}%"
                      + (f" ({fz['tax_area']})" if fz.get("tax_area") else "")})
    if fz.get("mosquito_zone"):
        sp = fz.get("mosquito_species") or []
        items.append({"key": "mosquito_zone", "label": "Mosquito zone", "text": str(fz["mosquito_zone"])
                      + (": " + ", ".join(sp) + " found lately" if sp else ": no invasive mosquitoes lately")})
    if not items:
        return None
    pop = _f(fz.get("population"))
    return {"zip": z, "label": _label(pack, z), "facts": items,
            "small": bool(pop is not None and pop < Z.SMALL_PEOPLE)}


def zips_named(spec, pack, facts):
    """The ZIPs a chart is about: filters.zips, else the ZIPs named after its towns (largest first)."""
    f = spec.get("filters") or {}
    zs = [str(z)[:5] for z in f.get("zips") or [] if str(z)[:5] in facts]
    if zs:
        return zs
    towns = {str(a).casefold() for a in f.get("areas") or []}
    if not towns:
        return []
    named = [z for z, n in (getattr(pack, "zips", {}) or {}).items() if str(n).casefold() in towns and z in facts]
    named.sort(key=lambda z: -(_f((facts.get(z) or {}).get("population")) or 0))
    return named


# ---------------------------------------------------------------- the one entry point
def apply(spec, result, pack, store):
    """Add the ZIP facts to a result the website will draw (after compute, before captions). spec is the public
    (version 1) spec; spec["zipfacts"], when present, is a clean value. Returns notes to add."""
    if not isinstance(result, dict) or store is None:
        return []
    z = spec.get("zipfacts") if isinstance(spec.get("zipfacts"), dict) else None
    facts = store.zip_facts()
    on_map = isinstance(result.get("zips"), dict) and bool(result.get("zips"))
    if not facts:
        if z:
            result["zipfacts"] = {"status": "not_yet", "view": z.get("view")}
            from .. import polite
            return ["The ZIP facts (Census and hazard zones) haven't been downloaded yet. The hub reads them "
                    f"{polite.when_text(getattr(store, 'cfg', None))}; on the hub computer now: zorderz_hub zipfacts update"]
        return []
    notes = []
    zf = {"status": "ok", "sources": store.sources()}
    if on_map:
        area = sorted(z_ for z_ in facts if z_ in (getattr(pack, "zips", {}) or facts))
        keys, vals = _layer(facts, area)
        zf["facts"] = [{"key": k, "label": Z.label_of(k), "unit": Z.unit_of(k)} for k in keys]
        zf["values"] = vals
        zf["labels"] = {z_: _label(pack, z_) for z_ in vals}
        zf["min_homes"] = Z.MIN_HOMES
    if z:
        zf["view"] = z["view"]
        if z.get("fact"):
            zf["fact"] = z["fact"]
            zf["fact_label"] = Z.label_of(z["fact"])
            zf["fact_noun"] = Z.noun_of(z["fact"])
            zf["fact_unit"] = Z.unit_of(z["fact"])
        if z.get("per"):
            zf["per"] = z["per"]
            zf["per_words"] = Z.BASES[z["per"]][1]
    measure = spec.get("measure")
    if z and z["view"] in ("rate", "shade", "dots") and not on_map:
        notes.append("ZIP facts go with a chart by ZIP; this one isn't by ZIP, so they're left off. "
                     "Ask \"by ZIP\" (\"revenue per 1,000 homes by ZIP\").")
        zf["status"] = "not_this_chart"
    elif z and z["view"] == "rate":
        if measure not in RATE_MEASURES:
            notes.append(f"A rate per {Z.BASES[z['per']][1]} needs a total ({', '.join(RATE_MEASURES)}); "
                         "the average job is shown as it is.")
            zf["status"] = "not_this_chart"
        else:
            notes += _rate(result, facts, z["per"], measure, zf)
    elif z and z["view"] == "dots":
        notes += _dots(result, facts, z, measure, zf)
    elif z and z["view"] == "shade":
        _shade(facts, z["fact"], zf, pack)
    named = zips_named(spec, pack, facts)
    if (z and z["view"] == "profile") or (named and len((spec.get("filters") or {}).get("zips") or []) in (1, 2, 3)):
        cards = [p for p in (profile_of(facts, pack, z_) for z_ in named[:MAX_PROFILE]) if p]
        if cards:
            zf["profile"] = cards
            if len(named) > MAX_PROFILE:
                notes.append(f"{len(named)} ZIPs carry that town's name; the {MAX_PROFILE} with the most people "
                             "are profiled.")
        elif z and z["view"] == "profile":
            notes.append("No ZIP named here has Census figures on file; tap a ZIP on the map or name one "
                         "(\"tell me about 00011\").")
    if on_map or z or zf.get("profile"):
        result["zipfacts"] = zf
    if any(p.get("small") for p in zf.get("profile") or []):
        notes.append(f"Census figures for a ZIP under {Z.SMALL_PEOPLE:,} people are rough (wide margins of error).")
    return notes


# ---------------------------------------------------------------- the views
def _rate(result, facts, per, measure, zf, min_homes=None):
    """Every value becomes per 1,000 of the ZIP's homes (households, people). ZIPs too small to rate, and jobs
    with no ZIP, leave the chart and are named in a note with their own totals. The chart's total is the rated
    ZIPs' together: everything they did over all their homes."""
    money = measure == "revenue"
    zips = result["zips"]
    base = {g: _ok_base(facts.get(z_), per, min_homes) for g, z_ in zips.items()}
    dropped = [g for g in result.get("groups") or [] if not base.get(g)]
    raw_tot = {t["group"]: t.get("value") for t in result.get("group_totals") or []}
    rows, by_num, by_jobs, by_rev = [], {}, {}, {}
    for r in result.get("rows") or []:
        p, g, v = r[0], r[1], _f(r[2])
        if not base.get(g):
            continue
        rows.append([p, g, _round(v * 1000.0 / base[g], money) if v is not None else None, r[3], r[4], None])
        if v is not None:
            by_num[p] = by_num.get(p, 0.0) + v
            by_jobs[p] = by_jobs.get(p, 0) + int(r[3] or 0)
            by_rev[p] = by_rev.get(p, 0.0) + float(_f(r[4]) or 0.0)
    gts, raw_sum, base_sum, jobs_sum = [], 0.0, 0.0, 0
    for t in result.get("group_totals") or []:
        g = t["group"]
        if not base.get(g):
            continue
        v = _f(t.get("value"))
        gts.append(dict(t, value=_round(v * 1000.0 / base[g], money) if v is not None else None, pct=None))
        if v is not None:
            raw_sum += v                    # each ZIP's own total (a customer in two years counts once here)
            base_sum += base[g]
        jobs_sum += int(t.get("jobs") or 0)
    gts.sort(key=lambda t: -(t["value"] if t["value"] is not None else -1e18))
    result["rows"] = rows
    result["group_totals"] = gts
    result["groups"] = [t["group"] for t in gts]
    result["zips"] = {g: zips[g] for g in result["groups"] if g in zips}
    area = _round(raw_sum * 1000.0 / base_sum, money) if base_sum else None
    # every period over the same homes: the rated ZIPs', so the months add up to the year
    result["totals"] = {"value": area, "jobs": jobs_sum, "revenue": round(sum(by_rev.values()), 2),
                        "by_period": {p: _round(v * 1000.0 / base_sum, money) for p, v in by_num.items()} if base_sum else {},
                        "jobs_by_period": by_jobs}
    result["additive"] = False
    zf["rate"] = {"per": per, "words": Z.BASES[per][1], "area": area, "rated": len(gts),
                  "raw": {g: raw_tot.get(g) for g in result["groups"]}}
    notes = []
    if dropped:
        shown = [g for g in dropped if g in raw_tot][:6]
        more = len(dropped) - len(shown)
        why = "outside every area, or under" if zf.get("one") == "area" else "no Census figures, no ZIP, or under"
        notes.append(f"Not rated ({why} "
                     f"{Z.MIN_HOMES if min_homes is None else min_homes} homes): " + "; ".join(shown) + (f" and {more} more" if more > 0 else "") + ".")
    if not gts:
        result["empty"] = True
        zf["rate"]["none_rated"] = True             # there were jobs; none of their ZIPs can be rated
    return notes


def _shade(facts, fact, zf, pack):
    unit = Z.unit_of(fact)
    vals = sorted(((z_, _f((fz or {}).get(fact))) for z_, fz in facts.items()
                   if z_ in (getattr(pack, "zips", {}) or facts)), key=lambda x: -(x[1] if x[1] is not None else -1e18))
    vals = [(z_, v) for z_, v in vals if v is not None]
    if vals:
        zf["highest"] = [{"zip": z_, "label": _label(pack, z_), "text": Z.fmt(v, unit)} for z_, v in vals[:3]]
        zf["lowest"] = [{"zip": z_, "label": _label(pack, z_), "text": Z.fmt(v, unit)} for z_, v in vals[-2:][::-1]]


def _dots(result, facts, z, measure, zf):
    fact, per = z["fact"], z.get("per")
    money = measure in ("revenue", "avg_job")
    zips = result.get("zips") or {}
    pts = []
    for t in result.get("group_totals") or []:
        g = t["group"]
        z_ = zips.get(g)
        if not z_:
            continue
        x = _f((facts.get(z_) or {}).get(fact))
        y = _f(t.get("value"))
        if x is None or y is None:
            continue
        if per and measure in RATE_MEASURES:
            b = _ok_base(facts.get(z_), per)
            if not b:
                continue
            y = y * 1000.0 / b
        pts.append({"g": g, "z": z_, "x": x, "y": _round(y, money), "jobs": t.get("jobs")})
    zf["dots"] = pts
    zf["n"] = len(pts)
    notes = []
    if per and measure not in RATE_MEASURES:                # an average isn't divided by homes
        zf.pop("per", None)
        zf.pop("per_words", None)
        notes.append(f"The {MEASURE_WORD.get(measure, measure)} is shown as it is: only totals (revenue, jobs, "
                     "customers, units) are put per 1,000 homes.")
    if len(pts) < MIN_DOTS:
        zf["reason"] = "few"
        notes.append(f"Only {len(pts)} ZIP{'s' if len(pts) != 1 else ''} to compare; a link needs at least "
                     f"{MIN_DOTS}.")
        return notes
    r = pearson([p["x"] for p in pts], [p["y"] for p in pts])
    if r is None:
        zf["reason"] = "flat"
        return notes
    zf["r"] = round(r, 2)
    if not per and measure in RATE_MEASURES:
        # bigger ZIPs have more of everything: the same link per 1,000 homes, for comparison
        ys, xs = [], []
        for p in pts:
            b = _ok_base(facts.get(p["z"]), "homes")
            if b:
                ys.append(p["y"] * 1000.0 / b)
                xs.append(p["x"])
        r2 = pearson(xs, ys) if len(xs) >= MIN_DOTS else None
        if r2 is not None:
            zf["r_per_homes"] = round(r2, 2)
    return notes


# ---------------------------------------------------------------- words
def title(zf, base_title, measure):
    """The chart's title when ZIP facts shape it, else None."""
    if not isinstance(zf, dict) or zf.get("status") != "ok":
        return None
    m = MEASURE_WORD.get(measure, measure or "revenue")
    m = m[:1].upper() + m[1:]
    view = zf.get("view")
    if view == "rate" and zf.get("rate"):
        return f"{m} per {zf['rate']['words']} by {zf.get('by') or 'ZIP'}"
    if view == "dots" and zf.get("fact_label"):
        return f"{m}{' per ' + zf['per_words'] if zf.get('per_words') else ''} vs {zf.get('fact_noun') or zf['fact_label'].lower()}, by ZIP"
    if view == "shade" and zf.get("fact_label"):
        return f"ZIPs by {zf.get('fact_noun') or zf['fact_label'].lower()}"
    return None


def sentences(zf, measure, fmt_value):
    """What the facts add to the caption. fmt_value(v) writes one of the chart's own numbers."""
    if not isinstance(zf, dict) or zf.get("status") != "ok":
        return []
    out = []
    view = zf.get("view")
    if view == "rate" and zf.get("rate"):
        rt = zf["rate"]
        one, src = zf.get("one") or "ZIP", zf.get("base_source") or "Census"
        out.append(f"Each {one}'s {MEASURE_WORD.get(measure, measure)} divided by its {rt['words'][6:]} "
                   f"({src}), times 1,000.")
        if rt.get("area") is not None:
            out.append(f"The {rt['rated']} rated {one}{'s' if rt['rated'] != 1 else ''} together: "
                       f"{fmt_value(rt['area'])} per {rt['words']}.")
    elif view == "shade" and zf.get("highest"):
        hi = ", ".join(f"{x['label']} ({x['text']})" for x in zf["highest"])
        lo = ", ".join(f"{x['label']} ({x['text']})" for x in zf.get("lowest") or [])
        out.append(f"Shaded by {zf.get('fact_noun') or zf['fact_label'].lower()}: highest {hi}" + (f"; lowest {lo}" if lo else "") + ".")
        out.append("Tap Shade to switch back to the sales.")
    elif view == "dots":
        n = zf.get("n", 0)
        if zf.get("reason") == "few" or zf.get("r") is None:
            if zf.get("reason") == "flat":
                out.append("One side of the dots never changes, so there's no link to measure.")
            return out
        r = zf["r"]
        lab = zf.get("fact_noun") or zf.get("fact_label", "the fact").lower()
        word = MEASURE_WORD.get(measure, measure)
        if zf.get("per_words"):
            word += f" per {zf['per_words']}"
        direction = "more" if r > 0 else "less"
        out.append(f"Across {n} ZIPs, {strength(r)} between {lab} and {word} (r = {r:+.2f})" +
                   (f": ZIPs with a higher {lab} had {direction} {word}." if abs(r) >= 0.2 else "."))
        if zf.get("r_per_homes") is not None:
            out.append(f"Per 1,000 homes, which takes out each ZIP's size: r = {zf['r_per_homes']:+.2f}.")
        out.append("A link between ZIPs isn't a cause, and each ZIP counts once whatever its size.")
    if zf.get("profile") and view != "rate":
        p = zf["profile"][0]
        pick = {it["key"]: it["text"] for it in p["facts"]}
        bits = [f"{pick[k]} {w}" for k, w in (("homes", "homes"), ("income", "median household income"),
                                              ("home_value", "median home value"), ("year_built", "median year built"))
                if k in pick]
        if bits:
            out.append(f"{p['label']}: " + ", ".join(bits) + ".")
    return out


def describe(zf):
    """One "Read as" line."""
    if not isinstance(zf, dict) or not zf.get("view"):
        return None
    if zf.get("status") == "not_yet":
        return "ZIP facts: not downloaded yet"
    v = zf["view"]
    if v == "rate" and zf.get("per_words"):
        return f"Per {zf['per_words']} in each ZIP (Census)"
    if v == "dots" and zf.get("fact_label"):
        return f"One dot per ZIP: {zf.get('fact_noun') or zf['fact_label'].lower()} across, the chart's number up"
    if v == "shade" and zf.get("fact_label"):
        return f"ZIP map shaded by {zf.get('fact_noun') or zf['fact_label'].lower()}"
    if v == "profile":
        return "With each named ZIP's profile (Census, hazard zones)"
    return None


def fmt_value(v, measure):
    if v is None:
        return "—"
    v = float(v)
    if measure in ("revenue", "avg_job"):
        return f"${v:,.0f}"
    return f"{v:,.1f}" if abs(v) < 100 and v != int(v) else f"{v:,.0f}"


def caption(zf, result, measure, template=""):
    """The caption of a chart the ZIP facts shape: a rate's own sentence first (the template would read the
    rates as plain totals), the shading before the template, the dots instead of it."""
    fv = lambda v: fmt_value(v, measure)                       # noqa: E731
    more = sentences(zf, measure, fv)
    view = zf.get("view")
    if view == "rate" and zf.get("rate", {}).get("none_rated"):
        return ("Nothing to rate: none of these jobs' ZIPs has Census figures and at least "
                f"{Z.MIN_HOMES} homes. Ask without \"per 1,000 homes\" for the totals.")
    if view == "rate" and zf.get("rate"):
        top = [t for t in result.get("group_totals") or [] if t.get("value") is not None][:3]
        lead = (f"Highest per {zf['rate']['words']}: " + ", ".join(f"{t['group']} ({fv(t['value'])})" for t in top)
                + ".") if top else ""
        return " ".join(x.rstrip(".") + "." for x in [lead] + more if x)
    if view == "dots":
        n = zf.get("n", 0)
        lead = f"One dot per ZIP ({n} ZIP{'s' if n != 1 else ''})."
        return " ".join(x.rstrip(".") + "." for x in [lead] + more if x)
    if view == "shade":
        return " ".join(x.rstrip(".") + "." for x in more + ([template] if template else []) if x)
    if more:
        return " ".join(x.rstrip(".") + "." for x in ([template] if template else []) + more if x)
    return template


# ---------------------------------------------------------------- territories
MIN_TERRITORY_HOMES = 10


def apply_territory(spec, result, homes, kind_label):
    """A chart by safety-site site or safety council: "per 1,000 homes" uses each territory's own homes (the
    region address points inside it). The Census facts are by ZIP and don't apply here. Returns notes."""
    z = spec.get("zipfacts") if isinstance(spec.get("zipfacts"), dict) else None
    if not z or not isinstance(result, dict):
        return []
    zf = {"status": "ok", "view": z["view"], "sources": ["the region's address points"]}
    result["zipfacts"] = zf
    if z["view"] != "rate" or z.get("per") != "homes":
        zf["status"] = "not_this_chart"
        return [f"By {kind_label}, only \"per 1,000 homes\" is available (the region's address points); the "
                "Census facts are by ZIP."]
    if spec.get("measure") not in RATE_MEASURES:
        zf["status"] = "not_this_chart"
        return ["A rate per 1,000 homes needs a total (revenue, jobs, customers, units)."]
    zf["per"], zf["per_words"] = "homes", Z.BASES["homes"][1]
    zf.update(by=kind_label, one="area", base_source="the region's address points")
    facts = {n: {"homes": h} for n, h in (homes or {}).items() if h}
    if not result.get("zips"):
        result["zips"] = {g: g for g in result.get("groups") or [] if g in facts}
    return _rate(result, facts, "homes", spec.get("measure"), zf, MIN_TERRITORY_HOMES)
