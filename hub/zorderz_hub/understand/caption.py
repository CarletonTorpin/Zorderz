"""Captions: facts first, then words.

facts() lists what the chart shows (total, change, highest and lowest, partial periods,
averages, rankings) as numbers already formatted the way the chart says them. template()
turns the facts into one or two plain sentences with no model at all: the default, and
the fallback whenever a model's caption fails a check.

A model may phrase the caption instead (caption_mode "model"): it gets the short fact list
(not the rows), and its caption is kept only if
  * every number in it is one of the facts' numbers,
  * it says "average" only when the chart is an average,
  * it says grew/fell/up/down only when the facts include a change, and never about a
    partial period on its own,
  * every capitalised word is a word from the facts or ordinary English.
Otherwise the template caption is shown, and the reason is returned for the log.
"""
import re

from .. import compute
from . import describe

TREND = re.compile(r"\b(?:grew|grow(?:s|ing|th)?|rose|rise|rising|increas\w*|decreas\w*|declin\w*|drop\w*|fell|"
                   r"fall(?:s|ing)?|up|down|jump\w*|surg\w*|plung\w*)\b", re.I)
AVERAGE = re.compile(r"\b(?:average|avg|mean|typical(?:ly)?|per month|per week|per year|a month|a week)\b", re.I)
NUM = re.compile(r"(?<![A-Za-z])\$?\d[\d,]*(?:\.\d+)?%?")
COMMON = {"The", "This", "In", "On", "Of", "And", "For", "From", "With", "Over", "Across", "At", "By", "To", "It",
          "Total", "Totals", "Revenue", "Jobs", "Job", "Customers", "Units", "Most", "Least", "Highest",
          "Lowest", "Largest", "Smallest", "Biggest", "Top", "Each", "All", "Every", "Compared", "Versus", "Vs",
          "Both", "Together", "About", "Nearly", "Almost", "Just", "Only", "Q1", "Q2", "Q3", "Q4", "H1", "H2",
          "Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec", "January",
          "February", "March", "April", "June", "July", "August", "September", "October", "November",
          "December", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun", "Winter", "Spring", "Summer", "Fall",
          "Week", "Weeks", "Year", "Years", "Month", "Months", "Note", "Partial", "Data", "Existing", "New",
          "Other", "One", "Two", "Three", "There", "These", "That", "Those", "Their", "They", "Its", "Since",
          "Through", "Until", "Than", "More", "Less", "Fewer", "Share", "Shares", "Percent", "Average"}


def money(v):
    """Whole dollars rounded half up, the way the chart's big number shows them
    ($259,520.50 → $259,521; Python's round() would give $259,520)."""
    from decimal import Decimal, ROUND_HALF_UP
    v = float(v)
    if abs(v) >= 100 or v.is_integer():
        d = Decimal(str(v)).quantize(Decimal("1"), rounding=ROUND_HALF_UP)
        return f"${int(d):,}"
    return f"${v:,.2f}"


def fmt(value, measure, noun="units"):
    if value is None:
        return "no figure"
    if measure in ("revenue", "avg_job"):
        return money(value)
    return compute.total_text(value, measure, noun)


def pct(v):
    if v is None:
        return None
    return f"{abs(v):.0f}%" if abs(v) >= 10 else f"{abs(v):.1f}%"


def _say(key, answer):
    g = answer.spec.get("date_part") or answer.spec.get("grain")
    if answer.kind == "plain" and g in ("year", "quarter", "month"):
        try:
            return compute.period_label(str(key), g)
        except (ValueError, IndexError):
            return str(key)
    return str(key)


def facts(answer, pack):
    """The caption's facts: [(key, sentence-part)] and the set of numbers they contain."""
    a = answer
    r = a.result
    measure = (a.v1 or {}).get("measure", a.spec.get("measure"))
    noun = compute.unit_noun(a.v1 or {}, pack) if measure == "units" else "units"
    out = []
    if r.get("empty"):
        return [("empty", "No matching jobs for these filters.")], measure
    tot = r.get("totals") or {}
    ex = a.extra
    if a.kind == "jobs_per_customer" and ex.get("jobs_per_customer"):
        j = ex["jobs_per_customer"]
        out.append(("total", f"{j['customers']:,} customers"))
        out.append(("one_job", f"{pct(j['one_job_pct'])} had one job"))
        out.append(("average", f"{j['average']:.1f} jobs each on average"))
        return out, measure
    if a.kind == "job_range" and ex.get("job_range"):
        top = ex["job_range"][0]
        out.append(("largest", f"largest job {money(top['largest'])} ({top['group']})"))
        low = min(ex["job_range"], key=lambda g: g["smallest"])
        out.append(("smallest", f"smallest job {money(low['smallest'])} ({low['group']})"))
        return out, measure
    if a.kind == "per_job" and ex.get("per_job") and ex["per_job"].get("average") is not None:
        p = ex["per_job"]
        out.append(("average", f"{p['average']:.1f} {noun if noun != 'units' else 'units'} per job on average"))
        out.append(("jobs", f"{p['jobs_with_count']:,} jobs with a count"))
        if p.get("most") is not None:
            out.append(("range", f"{p['fewest']:.0f} to {p['most']:.0f} per job"))
        return out, measure
    if ex.get("average"):
        av = ex["average"]
        out.append(("average", f"average {fmt(av['value'], measure, noun)} a {av['per']} over {av['periods']} "
                               f"{av['per']}s"))
        out.append(("high", f"highest {_say(av['high']['period'], a)} ({fmt(av['high']['value'], measure, noun)})"))
        out.append(("low", f"lowest {_say(av['low']['period'], a)} ({fmt(av['low']['value'], measure, noun)})"))
        if av.get("left_out"):
            out.append(("partial", f"{_say(av['left_out'], a)} is partial and left out of the average"))
        return out, measure
    if ex.get("deltas") and a.kind in ("windows", "overlay"):
        d = ex["deltas"]
        vals = d["values"]
        out.append(("values", "; ".join(f"{v['label']}: {fmt(v['value'], measure, noun)}" for v in vals)))
        if d.get("change") is not None and d.get("a") not in (None, 0):
            word = "up" if d["change"] > 0 else "down" if d["change"] < 0 else "level"
            if word == "level":
                out.append(("change", f"{d['to']} level with {d['from']}"))
            else:
                out.append(("change", f"{d['to']} {word} {fmt(abs(d['change']), measure, noun)} "
                                      f"({pct(d['pct'])}) on {d['from']}"))
        gs = [g for g in d.get("groups") or [] if g["group"] != "All other"]
        if gs and ex.get("rank", {}).get("items") and ex["rank"]["items"][0].get("by") == "change":
            g = ex["rank"]["items"][0]
            word = "grew most" if ex["rank"]["dir"] == "top" else "fell most"
            out.append(("rank", f"{g['label']} {word}: {fmt(g['value'], measure, noun)}"
                                + (f" ({pct(g['pct'])})" if g.get("pct") is not None else "")))
        elif gs:
            big = max(gs, key=lambda g: abs(g["change"]))
            if big["change"]:
                out.append(("group_change", f"biggest change {big['group']} "
                                            f"({'+' if big['change'] > 0 else '−'}{fmt(abs(big['change']), measure, noun)})"))
        return out, measure
    if a.kind == "series" and ex.get("series_totals"):
        out.append(("series", "; ".join(f"{g['label']}: {fmt(g['value'], measure, noun)}"
                                         + (f" ({g['pct']:.0f}%)" if g.get("pct") is not None else "")
                                         for g in ex["series_totals"])))
        return out, measure
    # plain charts
    n_periods = len(r.get("periods", []))
    groups = [g for g in r.get("group_totals", []) if g["group"] not in ("All jobs",)]
    if tot.get("value") is not None and measure != "avg_job":
        t = f"total {fmt(tot['value'], measure, noun)}"
        if measure == "revenue" and tot.get("jobs"):
            t += f" from {tot['jobs']:,} jobs"
        out.append(("total", t))
    elif measure == "avg_job" and tot.get("value") is not None:
        out.append(("total", f"average job {money(tot['value'])} across {tot.get('jobs', 0):,} jobs"))
    if ex.get("rank", {}).get("items"):
        rk = ex["rank"]
        it = rk["items"][0]
        word = "highest" if rk["dir"] == "top" else "lowest"
        out.append(("rank", f"{word}: {_say(it['label'], a)} ({fmt(it['value'], measure, noun)})"))
        if ex.get("rank_groups"):
            g = ex["rank_groups"][0]
            out.append(("rank_group", f"{'most' if rk['dir'] == 'top' else 'least'} overall: {g['label']} "
                                      f"({fmt(g['value'], measure, noun)})"))
    elif len(groups) >= 2:
        g = groups[0]
        share = f", {g['pct']:.0f}% of the total" if g.get("pct") is not None else ""
        out.append(("top_group", f"largest: {g['group']} ({fmt(g['value'], measure, noun)}{share})"))
        if len(groups) >= 3 and groups[1]["group"] != "All other":
            out.append(("second", f"then {groups[1]['group']} ({fmt(groups[1]['value'], measure, noun)})"))
    elif n_periods >= 2:
        bp = tot.get("by_period") or {}
        ps = [p for p in r["periods"] if bp.get(p) is not None]
        ps_full = [p for p in ps if p not in (ex.get("partial_period"), ex.get("partial_first"))] or ps
        if ps_full:
            hi = max(ps_full, key=lambda p: bp[p])
            lo = min(ps_full, key=lambda p: bp[p])
            out.append(("high", f"highest {_say(hi, a)} ({fmt(bp[hi], measure, noun)})"))
            if lo != hi:
                out.append(("low", f"lowest {_say(lo, a)} ({fmt(bp[lo], measure, noun)})"))
    if ex.get("growth"):
        gr = [g for g in ex["growth"] if g.get("pct") is not None]
        if gr:
            last = gr[-1]
            if last.get("same_days"):
                out.append(("growth", f"{_say(last['period'], a)} {'up' if last['pct'] > 0 else 'down'} "
                                      f"{pct(last['pct'])} on the same days of the year before"))
            else:
                out.append(("growth", f"{_say(last['period'], a)} {'up' if last['pct'] > 0 else 'down'} "
                                      f"{pct(last['pct'])} on the year before"))
    if ex.get("partial_period"):
        out.append(("partial", f"{_say(ex['partial_period'], a)} is partial"))
    return out, measure


def template(answer, pack):
    """One or two sentences, no model."""
    fs, _ = facts(answer, pack)
    if not fs:
        return ""
    if fs[0][0] == "empty":
        return fs[0][1]
    parts = [t.rstrip(".") for _, t in fs]

    def sentence(items):
        text = "; ".join(items)
        return text[0].upper() + text[1:] + "."
    out = [sentence(parts[:1])]
    if len(parts) > 1:
        out.append(sentence(parts[1:3]))
    if len(parts) > 3:
        out.append(sentence(parts[3:5]))
    return " ".join(out)


def fact_numbers(fs):
    out = set()
    for _, t in fs:
        for m in NUM.findall(t):
            out.add(_norm(m))
    return out


def _norm(m):
    m = m.rstrip(",.").replace("$", "").replace(",", "").rstrip("%")
    try:
        return round(float(m), 1)
    except ValueError:
        return m


def check(text, answer, pack):
    """'' when a model caption may be shown; otherwise the reason it can't."""
    fs, measure = facts(answer, pack)
    allowed = fact_numbers(fs)
    for y in range(2000, 2100):
        allowed.add(float(y))
    for m in NUM.findall(text or ""):
        n = _norm(m)
        if n not in allowed:
            return f"number {m} is not in the facts"
    fact_text = " ".join(t for _, t in fs)
    if AVERAGE.search(text) and not AVERAGE.search(fact_text) and measure != "avg_job":
        return "says average, but the chart isn't one"
    if TREND.search(text) and not TREND.search(fact_text):
        return "describes a trend the facts don't state"
    known = set(re.findall(r"[A-Za-z][\w&'/-]*", fact_text)) | COMMON | set(describe.title(answer, pack).split())
    for w in re.findall(r"(?<![.!?]\s)(?<!^)\b[A-Z][a-z][\w'-]*", text):
        if w not in known and w.rstrip("'s") not in known:
            return f"word {w} is not in the facts"
    return ""


SYSTEM = ("You write one or two short, plain sentences under a business chart. Use ONLY the facts given, "
          "with their numbers exactly as written. No new numbers, no names not in the facts, no advice, "
          "no guesses about causes. Don't call a partial period higher or lower than a full one.")


def model_caption(lm, model, answer, pack, sampling, mask=None, unmask=None):
    """A model's wording of the facts, or ('', reason). mask(text) -> (text, state) hides
    names before the model sees them; unmask(text, state) puts them back after the checks."""
    fs, _ = facts(answer, pack)
    if not fs or fs[0][0] == "empty":
        return "", "empty"
    user = "CHART: " + describe.title(answer, pack) + "\nFACTS:\n" + "\n".join(f"- {t}" for _, t in fs)
    state = None
    if mask:
        user, state = mask(user)
    try:
        text, _ = lm.chat(model, SYSTEM, user, sampling)
    except Exception:
        return "", "caption model unavailable"
    text = (text or "").strip().strip('"')
    if unmask:
        text = unmask(text, state)
    why = check(text, answer, pack)
    return ("", why) if why else (text, "")
