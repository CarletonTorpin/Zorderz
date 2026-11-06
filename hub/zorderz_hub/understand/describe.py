"""Titles and "How I read this", written from what ran.

The title is never a model's words: it is built from the spec that was computed, so it
cannot say "2025" over a chart of 2024, or "Brightline" over a chart that isn't filtered to it.
read_as() lists each part of the reading in plain words, marking the parts Charts assumed
(the person didn't say them), so a wrong guess is easy to spot and fix.
"""
from .. import compute
from . import spec2 as S

MEASURE = {"revenue": "Revenue", "jobs": "Jobs", "customers": "Customers", "units": "Units",
           "avg_job": "Average job"}
SPLIT = {"product": "product", "work_type": "work type", "salesperson": "salesperson", "area": "town",
         "lead_source": "lead source", "original_source": "first source", "referral_partner": "referring company",
         "account": "account", "safety_site": "safety site", "safety_council": "safety council",
         "tax_area": "sales tax area", "coast_band": "distance to the coast",   
         "coast": "coastal or inland", "payment": "paid or unpaid",             
         "region": "region"}                                                    
GRAIN = {"year": "year", "quarter": "quarter", "month": "month", "week": "week", "day": "day"}
PART = {"month_of_year": "month of the year", "quarter_of_year": "quarter of the year",
        "week_of_year": "week of the year", "day_of_week": "weekday", "season": "season"}


def _names(values, limit=2, joiner=" and "):
    values = list(values)
    if len(values) <= limit:
        return joiner.join(values) if len(values) < 3 else ", ".join(values[:-1]) + " and " + values[-1]
    return ", ".join(values[:limit]) + f" and {len(values) - limit} more"


def _products(ps, pack=None):
    """Product names, a whole family named once ("Window film" for its three kinds)."""
    ps = list(ps)
    for fam in (getattr(pack, "families", None) or []):
        members = set(fam["products"])
        if members and set(ps) >= members:
            rest = [p for p in ps if p not in members]
            return _names([fam["name"]] + rest)
    return _names(ps)


def subject(s, pack):
    """What the chart is about: "Shade sails", "New / install window film", "Google Ads"."""
    f = s.get("filters") or {}
    parts = []
    if f.get("tags"):
        parts.append(_names(f["tags"], 3))
    if f.get("products"):
        parts.append(_products(f["products"], pack))
    if f.get("work_types"):
        wt = _names(f["work_types"], 2, " or ")
        parts.insert(0, wt)
    who = []
    if f.get("lead_sources"):
        who.append("from " + _names(f["lead_sources"], 2, " or "))
    if f.get("original_sources"):
        who.append("first found us via " + _names(f["original_sources"], 2, " or "))
    if f.get("partners"):
        named = [p for p in f["partners"] if not str(p).startswith("(")]
        if named:
            who.append("sent by " + _names(named, 2, " or "))
    if f.get("areas"):
        who.append("in " + _names(f["areas"], 3))
    if f.get("zips"):
        who.append("in ZIP " + _names(f["zips"], 4))      
    for k_, word in (("safety_sites", "safety sites"), ("safety_councils", "safety councils")):   
        v_ = f.get(k_) or []
        if v_:
            who.append(("in " + word) if "*" in v_ else "in " + _names(v_, 3))
    if f.get("tax_areas"):                                                                                  
        who.append("in the " + _names(f["tax_areas"], 3) + " tax area" + ("s" if len(f["tax_areas"]) > 1 else ""))
    if f.get("coast_bands"):
        who.append(_names(f["coast_bands"], 3, " or ") + " from the coast")
    if f.get("regions"):                                                                                    
        who.append("in " + _names([str(r) for r in f["regions"]], 3, " or "))
    if f.get("coast_sides"):                                                                                
        who.append(_names([v.lower() for v in f["coast_sides"]], 2, " or ") + " customers")
    if f.get("payment") in ("paid", "unpaid"):
        who.append(f["payment"] + " invoices only")
    if f.get("salespeople"):
        who.append("sold by " + _names(f["salespeople"], 3))
    if f.get("customer_type") in ("business", "residential"):
        who.append("business customers" if f["customer_type"] == "business" else "homeowners")
    if f.get("repeat_customers_only"):
        who.append("repeat customers")
    js = job_size_phrase(f)                                                                                  
    if js:
        # "Jobs of $5,000 or more each" / "Revenue from jobs of $5,000 or more"
        who.append(js[len("jobs "):] + " each" if s.get("measure") == "jobs" else "from " + js)
    return " ".join(parts), who


def settings_words(v1, f, pack):
    """"paid invoices only" when the Include unpaid box is off (and the pack counts unpaid by default), or
    "paid and unpaid invoices" when it is on against a paid-only default. Nothing when a "paid" filter already says it."""
    v = (v1 or {}).get("include_unpaid")
    if not isinstance(v, bool) or (f or {}).get("payment") == "paid":
        return ""
    default = bool(getattr(pack, "include_unpaid_default", True))
    if v == default:
        return ""
    return "paid and unpaid invoices" if v else "paid invoices only"


def switch_words(v1, pack):
    """the words a pack gives a switch's value when it isn't the default ("only invoices that say shade-sail")."""
    out = []
    for sw in getattr(pack, "switches", None) or []:
        val = (v1 or {}).get(sw.field)
        if val is not None and val != sw.default:
            w = (getattr(sw, "title_words", None) or {}).get(str(val))
            if w:
                out.append(w)
    return out


def _money(v):
    v = float(v)
    if v >= 1_000_000 and v % 100_000 == 0:
        return f"${v / 1_000_000:g} million"
    return f"${v:,.0f}" if v == int(v) else f"${v:,.2f}"


def job_size_phrase(f):
    """"jobs of $5,000 or more", "jobs under $500", "jobs of $1,000 to under $5,000" -- min inclusive, max
    exclusive; a max a cent above a round amount ("at most $500") reads "$500 or less"."""
    def num(x):
        try:
            v = float(x)
            return v if v == v and v > 0 else None             # not a number, NaN, 0 or less: no bound
        except (TypeError, ValueError):
            return None
    lo, hi = num((f or {}).get("job_min")), num((f or {}).get("job_max"))
    if lo is None and hi is None:
        return ""
    at_most = hi is not None and round(hi * 100) % 100 == 1
    hi_txt = (_money(round(hi - 0.01, 2)) + " or less") if at_most else ("under " + _money(hi)) if hi is not None else ""
    if lo is not None and hi is not None:
        return f"jobs of {_money(lo)} to " + (_money(round(hi - 0.01, 2)) if at_most else hi_txt)
    if lo is not None:
        return f"jobs of {_money(lo)} or more"
    return f"jobs of {hi_txt}" if at_most else f"jobs {hi_txt}"


FILTER_OF = {"product": "products", "area": "areas", "salesperson": "salespeople", "lead_source": "lead_sources",
             "work_type": "work_types", "referral_partner": "partners", "original_source": "original_sources",
             "account": "accounts", "safety_site": "safety_sites", "safety_council": "safety_councils",
             "tax_area": "tax_areas", "coast_band": "coast_bands", "coast": "coast_sides", "region": "regions"}


def months_phrase(months):
    """"Nov–Mar" for a run of months (wrapping the year end), else the names ("Jan, Mar and Jun")."""
    ms = sorted({int(m) for m in months if 1 <= int(m) <= 12})
    if not ms:
        return ""
    if len(ms) >= 2:
        for start in ms:                       # a run that may wrap: Nov, Dec, Jan, Feb, Mar
            run = [((start - 1 + i) % 12) + 1 for i in range(len(ms))]
            if sorted(run) == ms and len(ms) < 12:
                return compute.MONTHS[run[0] - 1] + "\u2013" + compute.MONTHS[run[-1] - 1]
    return _names([compute.MONTHS[m - 1] for m in ms], 3)


def _versus(s):
    """"Brightline vs Northstar (Classic counted as Northstar)" when the split's own values were named."""
    split = s.get("split") or ""
    key = "tags" if split.startswith("tag_") else FILTER_OF.get(split)
    vals = list((s.get("filters") or {}).get(key) or []) if key else []
    merge = s.get("merge") or {}
    shown = [v for v in vals if v not in merge]
    if len(shown) < 2:
        return None, None
    text = " vs ".join(shown)
    if merge:
        text += " (" + ", ".join(f"{a} counted as {b}" for a, b in merge.items()) + ")"
    return text, key


def title(answer, pack):
    s, v1 = answer.spec, answer.v1 or {}
    versus, vkey = _versus(s)
    s_subj = dict(s)
    fkey = FILTER_OF.get(s.get("facet") or "")
    drop = {k for k in (vkey, fkey) if k}
    if drop:
        s_subj["filters"] = {k: v for k, v in (s.get("filters") or {}).items() if k not in drop}
    what, who = subject(s_subj, pack)
    f = s.get("filters") or {}
    unpaid = settings_words(v1, f, pack)                      # the chips' settings v2 has no words for
    if unpaid:
        who.append(unpaid)
    measure = v1.get("measure") or s.get("measure")
    if answer.kind == "jobs_per_customer":
        head = "Customers by number of jobs"
    elif answer.kind == "job_range":
        head = "Largest and smallest jobs"
    elif answer.kind == "per_job":
        noun = compute.unit_noun(v1, pack)
        head = (f"{what}: " if what else "") + f"{noun} per job"
    elif measure == "units":
        noun = compute.unit_noun(v1, pack)
        head = f"Number of {what}" if what and what.lower().endswith(noun) else \
            (f"{what} {noun}" if what else noun.capitalize())
    elif measure == "customers" and f.get("repeat_customers_only"):
        head = (f"{what} repeat customers" if what else "Repeat customers")
        who = [w for w in who if w != "repeat customers"]
    elif measure == "avg_job":
        head = f"{what}: average job" if what else "Average job"
    elif s.get("agg") == "avg_per_period":
        per = {"week": "weekly", "year": "yearly", "day": "daily", "quarter": "quarterly"}.get(s.get("grain"), "monthly")
        head = f"Average {per} {MEASURE.get(measure, 'revenue').lower()}" + (f": {what}" if what else "")
    else:
        word = MEASURE.get(measure, "Revenue")
        head = f"{what} {word.lower()}" if what else word
    head = head[0].upper() + head[1:] if head else head
    text = head
    if who:
        text += " " + " ".join(who)
    if answer.kind == "series":
        text += ": " + " vs ".join(sr["label"].lower() for sr in s.get("series") or [])
    elif versus:
        text += ": " + versus
    by = []
    split = s.get("split")
    one_value = split and len((s.get("filters") or {}).get(
        "tags" if str(split).startswith("tag_") else FILTER_OF.get(split, ""), []) or []) == 1
    if split and split != "none" and not versus and not one_value and answer.kind not in ("series", "overlay"):
        if split.startswith("tag_"):
            by.append("by " + (pack.tag_group_for_split(split) or split[4:]).lower())
        elif split == "area" and s.get("chart_type") == "map" and getattr(pack, "zips", None):
            by.append("by ZIP code")             # the map is drawn by ZIP
        else:
            by.append("by " + SPLIT.get(split, split.replace("_", " ")))
    grain = s.get("date_part") or s.get("grain")
    if answer.kind == "overlay" and s.get("date_part") in ("season", "day_of_week"):
        by.append("by " + PART[s["date_part"]])   # years by season are not "by month"
    elif answer.kind == "overlay":
        by.append(f"by {GRAIN.get(s.get('grain') or 'month', 'month')}")
    elif grain in PART:
        by.append("by " + PART[grain])
    elif grain in GRAIN and answer.kind in ("plain", "series", "per_job") and s.get("agg") != "avg_per_period":
        by.append("by " + GRAIN[grain])
    if by:
        text += (", " if versus or answer.kind == "series" else " ") + " and ".join(by)
    if s.get("facet") and answer.kind == "plain":
        text += f", one chart per {SPLIT.get(s['facet'], s['facet'])}"
    if s.get("months"):
        text += ", " + months_phrase(s["months"]) + " only"
    for words in switch_words(v1, pack):                       # a pack rule set off its default
        text += ", " + words
    when = _when(answer)
    sep = ": " if answer.kind in ("windows", "overlay") and not versus else ", "
    full = text + (sep + when if when else "")
    return full


def short(full, limit=90):
    """The title cut to the website's 90 characters: first without the date's
    parenthesis, then at a word, never mid-word."""
    if len(full) <= limit:
        return full
    import re
    t = re.sub(r"\s*\([^)]*\)", "", full)
    if len(t) <= limit:
        return t
    cut = t[:limit - 1].rsplit(" ", 1)[0].rstrip(",:;")
    return cut + "…"


def _when(answer):
    ws = answer.windows
    s = answer.spec
    if not ws:
        return ""
    if answer.kind in ("windows", "overlay"):
        labels = [w.label for w in ws]
        if len(labels) == 2:
            return f"{labels[0]} vs {labels[1]}"
        return ", ".join(labels[:-1]) + " and " + labels[-1]
    w = ws[0]
    label = w.label
    if s.get("to_date_cut"):
        m, d = s["to_date_cut"]
        label = f"{w.start.year}–{w.end.year}, Jan 1–{compute.MONTHS[m - 1]} {d} of each year"
    return label


def read_as(answer, assumptions=()):
    """'How I read this': each part of the reading in plain words."""
    s = answer.spec
    out = []
    out.append("Measure: " + MEASURE.get(s.get("measure"), s.get("measure")))
    out.append("Dates: " + (_when(answer) or "all history"))
    f = s.get("filters") or {}
    for k, label in (("products", "Products"), ("tags", "Details"), ("work_types", "Work"),
                     ("areas", "Towns"), ("regions", "Region"), ("salespeople", "Salespeople"),
                     ("lead_sources", "Lead source"),
                     ("partners", "Referring company"), ("original_sources", "First source"),
                     ("zips", "ZIP codes")):
        if f.get(k):
            out.append(f"{label}: " + ", ".join(map(str, f[k][:6])) + (" …" if len(f[k]) > 6 else ""))
    for k in S.EXCLUDES:
        if f.get(k):
            out.append("Left out: " + ", ".join(map(str, f[k][:6])))
    js = job_size_phrase(f)                                                                   
    if js:
        out.append("Job size: " + js[0].upper() + js[1:] + " (the whole invoice)")
    if s.get("split"):
        sp = s["split"]
        word = "ZIP code (each job in its customer's ZIP)" if sp == "area" \
            and "zips" in (getattr(answer, "result", None) or {}) else None      # the ZIP map
        out.append("Split: " + (word or (sp[4:].replace("_", " ") if sp.startswith("tag_") else SPLIT.get(sp, sp))))
    if s.get("compare"):
        out.append("Compared with: " + {"prior_year": "the same days a year earlier",
                                        "prior_period": "the period just before",
                                        "growth": "each period's change"}[s["compare"]])
    if s.get("rank"):
        out.append(("Top " if s["rank"].get("dir") == "top" else "Bottom ") + str(s["rank"].get("n", 1)) +
                   (" by change" if s["rank"].get("by") == "change" else ""))
    for a in assumptions:
        out.append("Assumed: " + a)
    return out[:10]
