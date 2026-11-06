"""The question reader: a question → a sparse chart spec, by rules, with no model.

read() places every word it can: dates (when.py), the pack's products, towns, people, brands
and sources (terms.py), and Charts' own words for measures, splits, comparisons, rankings and
chart looks (lexicon.py). It returns a Reading:

  status "complete"  every meaningful word was placed: the spec can run as it is, no model;
         "partial"   some words weren't placed: the model fills the rest, and may not change
                     the fields listed in `locked`;
         "refuse"    the question asks Charts to break its rules (names, prompt tricks).

The spec is "version 2" (spec2.py): only what differs from the defaults, plus period tokens.
The same question with the same data date always gives the same spec.
"""
import re
from dataclasses import dataclass, field

from . import lexicon as L
from .text import prepare
from .when import read_dates

NOT_A_PARTNER = "(no listed partner named)"
# initials that are also everyday words: only ever read when typed in capitals ("AS", not "as")
INITIAL_WORDS = {"as", "do", "am", "in", "or", "it", "me", "us", "so", "no", "on", "to", "at", "be", "by", "go",
                 "he", "if", "is", "my", "of", "up", "we", "an", "id", "ok", "tv", "pm", "ad", "ct", "tw", "sw"}
FILTER_KEYS = ("products", "work_types", "salespeople", "areas", "lead_sources", "tags", "accounts",
               "partners", "original_sources", "regions")                                  # regions
ENTITY_DIM = {"products": "product", "work_types": "work_type", "salespeople": "salesperson",
              "areas": "area", "lead_sources": "lead_source", "partners": "referral_partner",
              "accounts": "account", "regions": "region"}
GRAIN_NOUNS = {"week": "week", "weeks": "week", "month": "month", "months": "month", "year": "year",
               "years": "year", "day": "day", "days": "day", "quarter": "quarter", "quarters": "quarter",
               "season": "season", "seasons": "season", "weekday": "day_of_week"}


@dataclass
class Reading:
    question: str
    spec: dict
    status: str                                   # complete | partial | refuse
    unread: list = field(default_factory=list)    # words not placed
    locked: list = field(default_factory=list)    # fields the model may not change
    assumptions: list = field(default_factory=list)
    corrections: list = field(default_factory=list)
    reason: str = ""                              # for refuse / unsupported
    trace: list = field(default_factory=list)     # what each phrase was read as (for tests and the log)
    look_by: str = ""                             # words | rule | previous | "" (the shape chooses)


@dataclass
class Context:
    terms: object                                 # terms.Terms
    anchor: object                                # datetime.date: last day with data
    today: object = None
    first: object = None                          # first day with data
    pack: object = None
    territories: dict = None                      # {"safety_site": [names], "safety_council": [names]}


def _has(text, words):
    return any(re.search(r"(?<![\w])" + re.escape(w) + r"(?![\w])", text) for w in words)


def _families(ctx):
    """The pack's product families: [{name, products, words}] (catalog.yml charts.families)."""
    return list(getattr(getattr(ctx, "pack", None), "families", None) or [])


def _is_family(values, ctx):
    """These products are exactly one family's."""
    vs = set(values or [])
    return bool(vs) and any(vs == set(f["products"]) for f in _families(ctx))


def _in_one_family(values, ctx):
    """These products all belong to one family."""
    vs = set(values or [])
    return bool(vs) and any(vs <= set(f["products"]) for f in _families(ctx))


def _family_said(text, ctx):
    """The family whose words the question uses, or None."""
    for f in _families(ctx):
        if _has(text, f.get("words") or [f["name"].casefold()]):
            return f
    return None


def _add(lst, values):
    for v in values:
        if v not in lst:
            lst.append(v)


# "What's our best seller in Marston?" was answered by salesperson. A best
# seller is a product, unless the question is about people: "who is / who are …", a salesperson word
# ("by salesperson", "rep"), or a salesperson's initials or name ("Is GT our top seller?").
_BEST_SELLER = re.compile(r"\b(?:(?:best|top|biggest|#1|number one)[ -]?(?:sell[ae]?rs?|selers?)|best-?sellers?)\b", re.I)
_ABOUT_PEOPLE = re.compile(r"\bwho(?:'s|’s|'re|\s+(?:is|are|was|were|has|have|had))\b|\b(?:sales ?(?:person|persons|people|man|men|"
                           r"rep|reps)|reps?|staff|employees?|team members?)\b", re.I)


def best_seller_is_a_product(question, terms):
    q = str(question or "")
    m = _BEST_SELLER.search(q)
    if not m or _ABOUT_PEOPLE.search(q):
        return q
    words = re.findall(r"[A-Za-z']+", q)
    if any(w in terms.salespeople for w in words) or any(w.lower() in terms.person_names for w in words):
        return q
    return q[:m.start()] + "best selling product" + q[m.end():]


# a job's size is its whole invoice. "jobs over
# $5,000", "invoices under $500", "jobs between $1,000 and $5,000", "$10k+ jobs", "jobs in Cedar Hills over $5k". The
# amount has to sit on the jobs (not on "months with revenue over $100,000" or "months where jobs brought in over
# $100k"); only the comparator and the amount leave the question -- the place, year or product beside them stay.
_NUM = r"\d[\d,]*(?:\.\d+)?"
_WORDNUM = (r"(?:one|two|three|four|five|six|seven|eight|nine|ten|fifteen|twenty(?:[- ]five)?|fifty|a\s+hundred|"
            r"hundred)")
_WORD_VAL = {"one": 1, "two": 2, "three": 3, "four": 4, "five": 5, "six": 6, "seven": 7, "eight": 8, "nine": 9,
             "ten": 10, "fifteen": 15, "twenty": 20, "twenty five": 25, "twenty-five": 25, "fifty": 50,
             "a hundred": 100, "hundred": 100}
_MONEY = (r"(?:" + _WORDNUM + r"\s+(?:grand|thousand)\b|\$\s?" + _NUM + r"(?:\s*(?:k|thousand|grand|m|million))?(?![\w.])|" + _NUM +
          r"\s*(?:k|thousand|grand|million)(?![\w])|" + _NUM + r"\s*(?:dollars|bucks)\b)")
# a bare number right after a comparator on the jobs ("jobs over 5000"), never a count, a share or a time
_BARE = (_NUM + r"(?![\w%]|\s*(?:%|percent|years?|yrs?|months?|weeks?|days?|hours?|miles?|mi\b|units?|items?|"
         r"pieces?|homes?|houses?|feet|ft\b|sq|square|panels?|windows?|jobs?|invoices?|customers?|times|people|per\b))")
_AMT = r"(" + _MONEY + r"|" + _BARE + r")"
_AMT_M = r"(" + _MONEY + r")"
_JOBWORD = r"(?:jobs?|invoices?|orders?|tickets?|installs?|installations?|projects?)"
_LO = (r"(?:over|above|more\s+than|greater\s+than|bigger\s+than|larger\s+than|at\s+least|exceeding|upwards\s+of|"
       r"north\s+of|>=?)")
_HI = r"(?:under|below|less\s+than|smaller\s+than|cheaper\s+than|beneath|<)"
_HI_INCL = r"(?:at\s+most|up\s+to|no\s+more\s+than|<=)"
_CMP = r"(" + _LO + r"|" + _HI + r"|" + _HI_INCL + r")"
_UP = r"(?:\+|\s*(?:plus|and\s+up|and\s+over|and\s+above|or\s+more|or\s+above|or\s+over|or\s+higher))"
_DOWN = r"\s*(?:or\s+less|and\s+under|or\s+under|or\s+below|and\s+below|or\s+lower)"
# words that make the amount a total, not a job's size, when they sit between the jobs and the amount
_GAP_STOP = (r"(?:brought|bring|brings|bringing|made|make|makes|making|earned|earn|earns|generated|generate|"
             r"generates|came|come|comes|added|add|adds|totall?(?:ed|ing)?|totals?|summed|sum|sums|grossed|gross|"
             r"revenue|sales|sold\s+for|months?|weeks?|days?|quarters?|years|monthly|weekly|yearly|annually|per|by|"
             r"vs|versus|or|and|but|over|under|above|below|between|than|exceeding|beneath)")
_GAP = (r"(?:(?:by\s+[a-z]+|(?!" + _GAP_STOP + r"\b)(?:[a-z'-]+|(?:19|20)\d\d|9\d{4}|q[1-4]))\s+){0,5}?")   # "by month", a ZIP
# "with a total of", "that were", "worth", "of", "at", "valued at" just before the amount -- removed with it
_LEAD = (r"(?:(?:with|having|that\s+have|that\s+had)\s+(?:an?\s+)?(?:invoice\s+)?(?:totals?|amounts?|values?|"
         r"prices?|sizes?|tickets?)\s+(?:of\s+)?|(?:valued|priced|billed)\s+(?:at\s+)?|sized\s+)?")
_CONN = r"(?:of|worth|for|at|valued\s+at|priced\s+at)\s+"
_TOTAL_AFTER = re.compile(r"\s*(?:in\s+)?(?:total|combined|altogether|together|all\s+told|in\s+revenue|in\s+sales|"
                          r"of\s+revenue|of\s+sales)\b", re.I)
_TOTAL_CLAUSE = re.compile(r"\b(?:months?|weeks?|years?|days?|quarters?|salespe\w*|people|reps?|towns?|areas?|"
                           r"regions?|zips?|zip\s+codes?)\s+(?:where|when|in\s+which|whose)\b", re.I)
_ONE_JOB = re.compile(r"\b(?:each|apiece|a\s+job|per\s+job|single\s+jobs?|one\s+job|individual\s+jobs?)\b", re.I)

_AFTER = [   # (kind, pattern) tried at the end of a job word + gap; group 1 / 2 are the amounts
    ("range", re.compile(_LEAD + r"(?:between|from)\s+" + _AMT + r"\s*(?:and|to|-|–)\s*" + _AMT, re.I)),
    ("range_x", re.compile(_LEAD + _LO + r"\s+" + _AMT + r"\s*(?:,\s*)?(?:but|and)\s+(?:" + _HI + r")\s+" + _AMT, re.I)),
    ("range_incl", re.compile(_LEAD + _LO + r"\s+" + _AMT + r"\s*(?:,\s*)?(?:but|and)\s+(?:" + _HI_INCL + r")\s+" + _AMT, re.I)),
    ("range", re.compile(_LEAD + r"(?:" + _CONN + r")?" + _AMT_M + r"\s*(?:-|–|to)\s*" + _AMT, re.I)),
    ("lo", re.compile(_LEAD + r"(?:" + _CONN + r")?" + _AMT_M + _UP, re.I)),
    ("hi_incl", re.compile(_LEAD + r"(?:" + _CONN + r")?" + _AMT_M + _DOWN, re.I)),
    ("lo", re.compile(_LEAD + _LO + r"\s+" + _AMT, re.I)),
    ("hi", re.compile(_LEAD + _HI + r"\s+" + _AMT, re.I)),
    ("hi_incl", re.compile(_LEAD + _HI_INCL + r"\s+" + _AMT, re.I)),
]
_AFTER = [(k, re.compile(r"\s+" + _GAP + r"(" + p.pattern + r")", re.I)) for k, p in _AFTER]   # the gap words stay
_BEFORE = re.compile(r"(?:(" + _LO + r"|" + _HI + r"|" + _HI_INCL + r")\s+)?" + _AMT_M + r"(" + _UP + r"|" + _DOWN +
                     r")?\s*(?:-?\s*(?:sized?|size|dollar)\s+)?$", re.I)
_TWO_SIZES = re.compile(r"\s*,?\s*(?:or|vs\.?|versus|and)\s+(?:jobs?\s+)?" + _CMP + r"\s+\$?\d", re.I)
_JOB_RE = re.compile(r"\b" + _JOBWORD + r"\b", re.I)
# something that looks like a size the patterns above couldn't read: the reader says so instead of charting all jobs
_LGAP = (r"(?:(?!(?:brought|bring|brings|made|make|makes|earned|earns|generated|came|comes|added|adds|totall?ed|"
         r"totals?|summed|grossed|revenue|sales)\b)\S+\s+){0,6}?")
_LOOKS_SIZED = re.compile(r"\b" + _JOBWORD + r"\b\s+" + _LGAP + _LEAD + r"(?:" + _CMP + r"|between|" + _CONN + r")\s*"
                          r"(?:\$|\d[\d,.]*\s*(?:k|grand|thousand|dollars|bucks)\b|[a-z]+\s+(?:grand|thousand|dollars|bucks)\b)"
                          r"|\$\s?\d[\d,]*\s*(?:k\b)?\s*\+?\s*" + _JOBWORD + r"\b", re.I)


def _dollars(text):
    t = (text or "").lower().replace("$", "").replace(",", "").strip()
    w = re.match(r"(" + _WORDNUM + r")\s+(?:grand|thousand)\b", t)
    if w:
        return float(_WORD_VAL[re.sub(r"\s+", " ", w.group(1))] * 1000)        # "five grand"
    m = re.match(r"(\d+(?:\.\d+)?)\s*(k|thousand|grand|m|million)?", t)
    if not m:
        return None
    v = float(m.group(1)) * {"k": 1e3, "thousand": 1e3, "grand": 1e3, "m": 1e6, "million": 1e6}.get(m.group(2) or "", 1)
    return v if 0 <= v <= 10_000_000 else None


def _money_said(text):
    return bool(re.search(r"\$|\d\s*(?:k|thousand|grand|million)\b|dollars|bucks|\b(?:grand|thousand)\b", text or "", re.I))


def _bounds(kind, amts):
    vals = [_dollars(a) for a in amts if a]
    if not vals or any(v is None for v in vals):
        return None
    if kind in ("range", "range_incl", "range_x"):
        if len(vals) < 2:
            return None
        lo, hi = sorted(vals[:2])
        if lo == hi:
            return None
        if kind == "range_x":
            return lo, hi                                     # "over $1,000 but under $5,000"
        return lo, round(hi + 0.01, 2)                        # "between $1,000 and $5,000": $5,000 counts
    if kind == "lo":
        return (vals[0] or 0.01), None                        # "over $0": anything with a charge
    if kind == "hi":
        return (None, vals[0]) if vals[0] > 0 else None
    return None, round(vals[0] + 0.01, 2)                     # "at most $500": $500 counts


def job_size(question):
    """(job_min, job_max, spans) from the words, or None. Min inclusive, max exclusive ("at most $500" is a
    max a cent above, so $500 itself counts). `spans` are the (start, end) pieces to take out of the question --
    the comparator and the amount only; the job word and anything between stay."""
    q = question or ""
    one_job = bool(_ONE_JOB.search(q))
    for jm in _JOB_RE.finditer(q):
        if not one_job and _TOTAL_CLAUSE.search(q[:jm.start()]):
            continue                                          # "months where jobs …", "salespeople whose jobs …"
        # the amount after the jobs: "jobs [in Cedar Hills] over $5,000"
        for kind, pat in _AFTER:
            m = pat.match(q, jm.end())
            if not m:
                continue
            amts = [x for x in m.groups()[1:] if x]
            if not any(_money_said(x) for x in amts) and not re.match(r"\s*(?:dollars|bucks)\b", q[m.end():], re.I):
                # bare numbers ("jobs over 5000"): never small counts, never years ("jobs from 2020 to 2024")
                if any((_dollars(x) or 0) < 50 for x in amts) or \
                        any(re.fullmatch(r"(?:19|20)\d\d|9\d{4}", x.strip()) for x in amts):
                    continue
            if _TOTAL_AFTER.match(q, m.end()):
                return None                                   # "jobs were over $100,000 total"
            if _TWO_SIZES.match(q, m.end()):
                return None                                   # "over $5,000 or under $500": looks_sized asks
            b = _bounds(kind, amts)
            if b:
                end = m.end()
                d = re.match(r"\s*(?:dollars|bucks)\b", q[end:], re.I)
                if d:
                    end += d.end()
                return b[0], b[1], [(m.start(1), end)]
        # the amount before the jobs: "$10k+ jobs", "over $5,000 jobs", "$5k-and-up jobs"
        m = _BEFORE.search(q[:jm.start()])
        if m:
            cmp_, amt, suf = m.group(1), m.group(2), (m.group(3) or "")
            kind = None
            if cmp_:
                kind = "lo" if re.match(_LO, cmp_, re.I) else "hi_incl" if re.match(_HI_INCL, cmp_, re.I) else "hi"
            elif suf:
                kind = "lo" if re.match(_UP, suf, re.I) else "hi_incl"
            if kind:
                b = _bounds(kind, [amt])
                if b:
                    return b[0], b[1], [(m.start(), jm.start())]
    return None


def looks_sized(question):
    """words that look like a job size job_size() couldn't read ("jobs over five grand")."""
    q = question or ""
    one_job = bool(_ONE_JOB.search(q))
    for m in _LOOKS_SIZED.finditer(q):
        if one_job or not _TOTAL_CLAUSE.search(q[:m.start()]):
            return True
    return False


_SIZE_ADJ = re.compile(r"\b(?:big|bigger|biggest|large|larger|small|smaller|high[- ]dollar|low[- ]dollar|expensive|"
                       r"cheap|pricey)\s+(?=(?:[a-z'-]+\s+){0,2}?" + _JOBWORD + r"\b)", re.I)
_SIZE_ONLY = re.compile(r"\b(big|large|small|high[- ]dollar|low[- ]dollar|expensive|cheap|pricey)\s+"
                        r"((?:(?!business|commercial|residential|home|house)[a-z'-]+\s+){0,2}?)(" + _JOBWORD + r")\b",
                        re.I)
# a follow-up that changes only the size: "what about over $10k", "now under $500", "only the ones over $5k"
_SIZE_FOLLOWUP = re.compile(r"^\s*((?:(?:what|how)\s+about|and|now|same\s+(?:but|for)|but|ok(?:ay)?|then)?\s*,?\s*"
                            r"(?:(?:only|just)\s+)?(?:(?:the\s+)?ones\s+)?(?:that\s+(?:are|were)\s+)?)"
                            r"(?:(" + _LO + r")|(" + _HI + r")|(" + _HI_INCL + r"))\s+" + _AMT_M + r"(" + _UP + r"|" +
                            _DOWN + r")?", re.I)
_SIZE_CLEAR = re.compile(r"\b(?:(?:of\s+)?(?:any|every|all)\s+sizes?|regardless\s+of\s+(?:the\s+)?size|"
                         r"(?:no|without\s+the|drop\s+the|remove\s+the|clear\s+the|forget\s+the)\s+(?:job\s+)?size"
                         r"(?:\s+filter)?|all\s+(?:the\s+)?jobs)\b", re.I)


def _cut(question, spans):
    q = question
    for a, b in sorted(spans, reverse=True):
        q = q[:a] + " " + q[b:]
    return re.sub(r"\s{2,}", " ", q).strip()


def _with_size(r, question, lo, hi):
    r.question = question
    if r.spec.get("intent") in ("unsupported", "clarify") or r.status == "refuse":
        return r
    f = r.spec.setdefault("filters", {})
    for k, v in (("job_min", lo), ("job_max", hi)):      # a size said now replaces the one on screen, both ends
        if v is None:
            f.pop(k, None)
        else:
            f[k] = v
    r.trace = list(r.trace) + [f"job size → min {lo}, max {hi}"]
    if len(r.assumptions) < 4:
        r.assumptions = list(r.assumptions) + ["A job's size is its whole invoice"]
    return r


def _ask_how_big(question, why):
    m = _SIZE_ONLY.search(question or "")
    opts = []
    for size in ("over $5,000", "over $10,000", "under $500"):
        if m:
            o = question[:m.start()] + m.group(2) + m.group(3) + " " + size + question[m.end():]
            o = re.sub(r"\s{2,}", " ", o).strip()
            opts.append(o[:1].upper() + o[1:])
        else:
            opts.append("Jobs " + size)
    spec = {"intent": "clarify", "measure": "revenue", "grain": "year",
            "clarify_question": why, "clarify_options": opts}
    return Reading(question, spec, "complete", reason="job size without an amount",
                   trace=["size word with no readable amount → clarify"])


def read(question, ctx, previous=None):
    """a job size is taken out of the words, the rest read as before, then put back as job_min / job_max.
    "big jobs" with no amount asks how big; an amount it can't read asks too, rather than charting every job."""
    question = question or ""
    n_said = len(prepare(question, ctx.terms.known_words, ctx.terms.never_correct).tokens)
    js = job_size(question)
    pf = (previous or {}).get("filters") or {}
    sized_now = any(pf.get(k) is not None for k in ("job_min", "job_max"))
    if js is None and previous and (sized_now or previous.get("measure") in (None, "jobs")):
        m = _SIZE_FOLLOWUP.match(question)
        if m and not _TOTAL_AFTER.match(question, m.end()):
            kind = "lo" if m.group(2) else "hi" if m.group(3) else "hi_incl"
            suf = m.group(6) or ""
            if kind != "lo" and suf and re.match(_UP, suf, re.I):
                kind = "lo"
            b = _bounds(kind, [m.group(5)])
            if b:
                js = (b[0], b[1], [(m.end(1), m.end())])
    if js is None:
        if looks_sized(question):
            return _ask_how_big(question, "I couldn't read that job size. Ask with one dollar amount right after "
                                          "the jobs, for example \"jobs over $5,000 by month\" or \"jobs under $500\".")
        if _SIZE_ONLY.search(question):
            return _ask_how_big(question, "How big is a big job? Ask with an amount, for example \"jobs over $5,000\" "
                                          "or \"jobs under $500\".")
        cm = _SIZE_CLEAR.search(question) if previous and sized_now else None
        if cm:
            # "any size" leaves the question; "all jobs" stays in it ("what share of all jobs were chimney-cap")
            q = (_cut(question, [cm.span()]) or "same") if "size" in cm.group(0).lower() else question
            r = _read(q, ctx, previous, said_tokens=n_said)
            r.question = question
            if r.spec.get("intent") not in ("unsupported", "clarify") and r.status != "refuse":
                f = r.spec.setdefault("filters", {})
                f.pop("job_min", None)
                f.pop("job_max", None)
                r.trace = list(r.trace) + ["any size → job size cleared"]
            return r
        return _read(question, ctx, previous)
    lo, hi, spans = js
    q = _SIZE_ADJ.sub("", _cut(question, spans))
    r = _read(re.sub(r"\s{2,}", " ", q).strip() or "same", ctx, previous, said_tokens=n_said)
    return _with_size(r, question, lo, hi)


def _read(question, ctx, previous=None, said_tokens=None):
    terms = ctx.terms
    question = best_seller_is_a_product(question, terms)
    prep = prepare(question, terms.known_words, terms.never_correct)
    s = prep.text
    trace, assumptions = [], []
    for typed, fixed in prep.corrections:
        trace.append(f"typo: {typed} → {fixed}")

    # 0. Prompt tricks: refused before anything else is read.
    for pat in L.INJECTION:
        if re.search(pat, s):
            why = ("Charts only answers questions about the sales history. "
                   "It doesn't change how it works, and it never lists customers.")
            return Reading(question, {"intent": "unsupported", "clarify_question": why}, "refuse",
                           reason=why, trace=trace)

    # 1. Dates.
    lag_pre, lag_span, s_dates = None, None, s
    if _WEATHER_ANY.search(s):
        m_lag = re.search(L.WEATHER_LAG_ANY, s)
        if m_lag:
            n = m_lag.group("n")
            lag_pre = {"n": max(1, min(12, int(n) if n and n.isdigit() else _LAG_N.get(n or "a", 1))),
                       "unit": m_lag.group("unit").rstrip("s")}
            lag_span = (m_lag.start(), m_lag.end())
            s_dates = s[:m_lag.start()] + " " * (m_lag.end() - m_lag.start()) + s[m_lag.end():]
    # "per 1,000 homes" is a rate, not a number or a date ("1 000" after prepare)
    rate_base, rate_span = None, None
    m_rate = re.search(L.ZIP_RATE, s) or re.search(L.ZIP_PENETRATION, s)
    if m_rate:
        b = (m_rate.groupdict().get("base") or "households")
        rate_base = "homes" if b.startswith(("home", "house", "housing")) and not b.startswith("household") else \
            "households" if b.startswith("household") else "people"
        rate_span = (m_rate.start(), m_rate.end())
        s_dates = s_dates[:m_rate.start()] + " " * (m_rate.end() - m_rate.start()) + s_dates[m_rate.end():]
    # the region's fire-safe sites and councils ("by fire-safe site", "in councils", "jobs in the Oak Hills
    # site"): read before dates and phrases, like the rate
    terr = _territory_reading(s, getattr(ctx, "territories", None))
    _merge_place_reading(terr, _place_reading(s))                 # sales tax area, distance to the coast
    _merge_place_reading(terr, _coast_reading(s, _region_phrase_spans(s, ctx)))   # coastal / inland
    _merge_place_reading(terr, _payment_reading(s))               # paid or unpaid invoices
    wx_look = _weather_look_reading(s)                            # colored by / written on each bar
    _merge_place_reading(terr, {"_spans": wx_look.pop("_spans"), "_said": []})
    for a_, b_ in terr.get("_spans", []):
        s_dates = s_dates[:a_] + " " * (b_ - a_) + s_dates[b_:]
    when = read_dates(s_dates, ctx.anchor, ctx.today or ctx.anchor)
    time_tok = set()
    for a, b in when.spans:
        time_tok.update(prep.span_tokens(a, b))
    assumptions.extend(when.notes)

    # 2. Phrases.
    lag_tok = set(prep.span_tokens(*lag_span)) if lag_span else set()
    lag_tok |= set(prep.span_tokens(*rate_span)) if rate_span else set()        # the rate's words
    for a_, b_ in terr.get("_spans", []):                                        # the territory's words
        lag_tok |= set(prep.span_tokens(a_, b_))
    hits = terms.match(prep, skip=time_tok | lag_tok)
    zip_context = bool(rate_span or re.search(L.ZIP_CONTEXT, s) or re.search(L.ZIP_LINK, s))   # not "vs"
    weak = {h.phrase for h in hits if h.kind == "zipfact" and h.phrase in L.ZIP_FACT_WEAK
            and not zip_context and not re.search(r"\bby (?:the )?(?:share of |percent(?:age)? of )?" + re.escape(h.phrase) + r"\b", s)}
    # a weak profile word ("profile", "tell me about") is a ZIP profile only beside a town or a ZIP on the map
    pzips = getattr(getattr(ctx, "pack", None), "zips", None) or {}
    has_place = any(h.kind == "area" for h in hits) or any(z in pzips for z in re.findall(r"(?<!\d)(\d{5})(?!\d)", question))
    if not has_place:
        weak |= {h.phrase for h in hits if h.kind == "zip_profile" and h.phrase in L.ZIP_PROFILE_WEAK}
    strong = bool(rate_span or _PLACE_STRONG.search(s))
    weak |= {h.phrase for h in hits if h.kind == "zipfact" and h.phrase in L.ZIP_FACT_WEAK_0220
             and ((not strong and not re.search(r"\bby (?:the )?(?:share of |percent(?:age)? of )?" + re.escape(h.phrase)
                                                 + r"\b", s))
                  or re.search(r"\b" + re.escape(h.phrase) + _PRODUCT_AFTER, s))}
    if weak:
        hits = terms.match(prep, skip=time_tok | lag_tok, drop=frozenset(weak))
    used = set(time_tok) | lag_tok
    spec = {}
    filters = {k: [] for k in FILTER_KEYS}
    excl = {"lead_sources": [], "products": [], "tags": [], "areas": [], "salespeople": [], "partners": [],
            "work_types": []}
    notes_terms = []
    entity_hits = []          # (hit, filter_key, values)
    dims, grains, date_parts, measures, charts_ = [], [], [], [], []
    flags = {k: False for k in ("howmany", "share", "trend", "avg_per_period", "units_per_job", "repeat",
                                "new_customers", "jobs_per_customer", "zip", "customer_names", "others",
                                "region", "to_date", "followup", "typeword", "compare_lead")}
    vs_hits, rank_hits, exclude_hits, unsupported = [], [], [], []
    weather_hits = []                                  
    zipfact_hits, zip_profile = [], bool(re.search(L.ZIP_PROFILE_LIKE, s))   
    zip_profile_strong = False
    orientation = labels = None
    regions = []

    pending_initials = []

    def mark(h):
        used.update(range(h.start, h.end))

    for h in hits:
        k = h.kind
        if k == "term":
            unless = h.meaning.get("unless") or ()
            context = h.meaning.get("context") or ()
            if unless and _has(s, unless):
                continue
            if context and not _has(s, context):
                continue
            mark(h)
            got = False
            for key in ("products", "tags", "work_types"):
                vals = list(h.meaning.get(key) or ())
                if vals:
                    entity_hits.append((h, key, vals))
                    got = True
            ct = h.meaning.get("customer_type")
            if ct:
                spec.setdefault("filters", {})["customer_type"] = ct
                got = True
            if h.meaning.get("note"):
                _add(notes_terms, [h.meaning.get("note")])
            trace.append(f"{h.phrase} → {dict((a, b) for a, b in h.meaning.data if b and a in ('products', 'tags', 'work_types', 'customer_type'))}")
        elif k == "source":
            mark(h); entity_hits.append((h, "lead_sources", [h.value])); trace.append(f"{h.phrase} → source {h.value}")
        elif k in ("partner", "partner_ph"):
            mark(h); entity_hits.append((h, "partners", [h.value])); trace.append(f"{h.phrase} → partner")
        elif k == "account_ph":
            mark(h); entity_hits.append((h, "accounts", [h.value]))
        elif k == "person":
            mark(h); entity_hits.append((h, "salespeople", list(h.value)))
            trace.append(f"{h.phrase} → salesperson {'/'.join(h.value)}")
        elif k == "initials":
            tok = prep.tokens[h.start]
            if (tok.original.isupper() and not prep.shouting) or re.search(
                    r"\b(?:rep|salesperson|sold by|by)\s+" + re.escape(h.phrase) + r"\b", s):
                mark(h); entity_hits.append((h, "salespeople", [h.value]))
                trace.append(f"{tok.original} → salesperson {h.value}")
            elif h.phrase not in INITIAL_WORDS:
                pending_initials.append(h)          # "alex vs gt": decided once the rest is read
        elif k == "area":
            mark(h); entity_hits.append((h, "areas", [h.value])); trace.append(f"{h.phrase} → town {h.value}")
        elif k == "region":
            # a drawn region is a place filter (two or more named: the reader below makes it a split)
            mark(h); entity_hits.append((h, "regions", [h.value])); trace.append(f"{h.phrase} → region {h.value}")
        elif k == "region_undefined":
            mark(h); regions.append(h.value)
        elif k == "measure":
            mark(h); measures.append(h)
        elif k == "howmany":
            mark(h); flags["howmany"] = True
        elif k == "dim":
            mark(h); dims.append(h)
        elif k == "typeword":
            mark(h); flags["typeword"] = True; dims.append(h)
        elif k == "split_intro":
            mark(h)
        elif k == "grain":
            mark(h); grains.append(h.value)
        elif k == "date_part":
            mark(h); date_parts.append(h.value)
        elif k == "trend":
            mark(h); flags["trend"] = True
        elif k == "vs":
            mark(h); vs_hits.append(h)
        elif k == "compare_lead":
            mark(h); flags["compare_lead"] = True
        elif k == "rank":
            mark(h); rank_hits.append(h)
        elif k == "chart":
            mark(h); charts_.append(h.value)
        elif k == "orientation":
            mark(h); orientation = h.value
        elif k == "labels":
            mark(h); labels = h.value
            if h.value == "percent":
                flags["share"] = True
        elif k in ("share", "avg_per_period", "units_per_job", "repeat", "new_customers", "jobs_per_customer",
                   "zip", "customer_names", "others", "to_date", "followup"):
            mark(h); flags[k] = True
        elif k == "unsupported":
            mark(h); unsupported.append(h)
        elif k == "weather":
            weather_hits.append(h)                       # marked below, once it's clear they mean the weather
        elif k == "weather_view":
            mark(h); trace.append(f"{h.phrase} → weather view")
        elif k == "zipfact":
            mark(h); zipfact_hits.append(h); trace.append(f"{h.phrase} → ZIP fact {h.value}")
        elif k == "zip_profile":
            mark(h); zip_profile = True; trace.append(f"{h.phrase} → ZIP profile")
            zip_profile_strong = zip_profile_strong or h.phrase not in L.ZIP_PROFILE_WEAK
        elif k == "exclude":
            mark(h); exclude_hits.append(h)
        elif k == "prior_year":
            mark(h); when.compare = when.compare or "prior_year"
        elif k == "prior_period":
            mark(h); when.compare = when.compare or "prior_period"

    weather_hits = _weather_words(weather_hits, prep, s, measures, entity_hits)
    for h in weather_hits:
        mark(h); trace.append(f"{h.phrase} → weather {h.value}")

    # lower-case initials count when another salesperson is named, or they're compared ("alex vs gt")
    if pending_initials and (any(key == "salespeople" for _, key, _ in entity_hits) or vs_hits):
        for h in pending_initials:
            mark(h); entity_hits.append((h, "salespeople", [h.value]))
            trace.append(f"{h.phrase} → salesperson {h.value}")
        entity_hits.sort(key=lambda x: x[0].start)

    # "excluding ones that don't have a company referral": jobs with no partner leave the chart
    no_partner_excl = set()
    for eh in exclude_hits:
        cut_from = prep.tokens[eh.end - 1].end
        m_np = re.match(r"\s*(?:ones?|jobs?|those)?\s*(?:that|which|who)?\s*(?:don't|do not|doesn't|does not|have no|"
                        r"has no|without)\b.*?\b(?:company|partner|referral)(?:\s+(?:referrals?|partners?|names?))*",
                        s[cut_from:])
        if m_np:
            used.update(prep.span_tokens(cut_from, cut_from + m_np.end()))
            no_partner_excl.add(id(eh))

    # 3. Words nobody placed. Capitalised ones may be a customer's or a person's name.
    unread, names = [], []
    for i, t in enumerate(prep.tokens):
        if i in used or t.text in L.STOPWORDS or re.fullmatch(r"\d+(?:st|nd|rd|th)?", t.text):
            continue
        cap = (not prep.shouting) and t.original[:1].isupper() and i > 0
        (names if cap else unread).append(t.original if cap else t.text)
    if any(d.value in ("referral_partner", "salesperson", "area", "product", "lead_source") for d in dims
           if hasattr(d, "value")):
        unread = [w for w in unread if w not in ("names", "name", "list")]
    FILLER = ("include", "includes", "including", "within", "count", "only", "new", "become", "became",
                 "sent", "send", "sends", "come", "came", "brings", "bring", "usually", "typically", "have",
                 "had", "does", "do", "invoice", "invoiced", "done", "using", "based", "where", "kinds",
                 "anything", "whole", "section", "sections", "bar", "bars", "column", "columns", "each",
                 "much", "make", "made", "sold", "sell", "s")
    unread = [w for w in unread if w not in FILLER]
    names = [w for w in names if w.lower() not in FILLER and w.lower() not in L.STOPWORDS]

    # 4. Things Charts can't answer: say so plainly, and offer what it can.
    if _has(s, ["ignore"]) and flags["customer_names"]:
        return Reading(question, {"intent": "unsupported", "clarify_question": "Charts never lists customers."},
                       "refuse", reason="Charts never lists customers.", trace=trace)
    if unsupported and not flags["customer_names"] or (unsupported and unsupported[0].meaning.get("fallback") == "none"
                                                      and "customers" in unsupported[0].value):
        flags["customer_names"] = False
    possessive_name = re.search(r"\b([A-Z][a-z]+)\s+([A-Z][a-z]+)'s\b", question) or \
        re.search(r"\bcustomer(?:s)?\s+(?:named|called)\b", s)
    if flags["customer_names"] or (possessive_name and not entity_hits):
        why = ("Charts doesn't look up or list individual customers. Open the customer's "
               "invoice card to see their jobs.")
        spec = {"intent": "unsupported", "measure": "jobs", "clarify_question": why}
        return Reading(question, spec, "complete", assumptions=[], reason=why,
                       trace=trace + ["customer name → unsupported"])
    if unsupported:
        h = unsupported[0]
        fb = h.meaning.get("fallback")
        spec = {"intent": "unsupported", "clarify_question": h.value}
        if fb == "revenue_by_product":
            spec.update(measure="revenue", split="product", grain="none")
        elif fb == "recent_history":
            spec.update(measure="revenue", periods=["last_24m"], grain="month")
        elif fb == "revenue_by_month":
            spec.update(measure="revenue", periods=["last_12m"], grain="month")
        elif fb == "jobs_by_source":                          # realtors
            spec.update(measure="jobs", split="lead_source", grain="none")
        else:
            spec.update(measure="revenue", grain="year")
        return Reading(question, spec, "complete", reason=h.value, trace=trace + [f"{h.phrase} → unsupported"])
    if when.future:
        yrs = [y for y in when.future if y != "next"]
        reason = (f"There's no data for {', '.join(yrs)} yet; the data runs to {ctx.anchor:%b %-d, %Y}."
                  if yrs else "Charts shows what happened; it doesn't forecast.")
        spec = {"intent": "unsupported", "clarify_question": reason, "measure": "revenue",
                "periods": ["last_24m"], "grain": "month"}
        return Reading(question, spec, "complete", reason=reason, trace=trace + ["future → unsupported"])
    if regions:
        spec = {"intent": "clarify",
                "clarify_question": f"Charts has no area called \"{regions[0]}\". Which towns should it include?",
                "split": "area"}
        return Reading(question, spec, "complete", reason="undefined region", trace=trace)

    # 5. Entities → filters (with the comparison structure around "vs").
    for h, key, vals in entity_hits:
        _add(filters[key], vals)
    # "include Classic within Northstar's count": one group counted inside another
    merge = {}
    m = re.search(r"\binclude\s+(\w+)\s+(?:with(?:in)?|in|into|as part of|under)\s+(\w+)(?:'s)?\b", s)
    if m:
        a, b = m.group(1), m.group(2)
        ta = [vals for h, key, vals in entity_hits if h.phrase.split()[0] == a]
        tb = [vals for h, key, vals in entity_hits if h.phrase.split()[0] == b]
        tag_a = [v for vals in ta for v in vals if v in terms.tags]
        tag_b = [v for vals in tb for v in vals if v in terms.tags]
        if tag_a and tag_b:
            merge[tag_a[0]] = tag_b[0]
            assumptions.append(f"{tag_a[0]} counted within {tag_b[0]}")
    # Exclusions: the entity right after an exclude word leaves the filter.
    for eh in exclude_hits:
        after = [x for x in entity_hits if x[0].start >= eh.end and x[0].start <= eh.end + 3]
        tail = s[prep.tokens[eh.end - 1].end:] if eh.end - 1 < len(prep.tokens) else ""
        if id(eh) in no_partner_excl:
            excl["partners"].append(NOT_A_PARTNER)
            trace.append("exclude → jobs with no referral partner")
            continue
        if after:
            first_hit = after[0][0]
            same = [x for x in after if x[0] is first_hit]
            pick = next((x for x in same if x[1] == "tags"), same[0])
            h, key, vals = pick
            for v in vals:
                if v in filters[key]:
                    filters[key].remove(v)
                _add(excl[key if key in excl else "products"], [v])
            trace.append(f"exclude → {key} {vals}")
    if excl["tags"]:
        # tags excluded but kept elsewhere as a brand list: keep the product, drop the tag from the list
        filters["tags"] = [t for t in filters["tags"] if t not in excl["tags"]]

    split, facet = None, None
    series = []

    # Same kind of thing on both sides of "vs" (or "compare A, B and C"): one bar/line each.
    dims_by_key = {}
    for h, key, vals in entity_hits:
        dims_by_key.setdefault(key, []).append((h, vals))
    comparing = bool(vs_hits) or flags["compare_lead"] or "grouped_bar" in charts_
    if comparing:
        # new vs existing customers: two series of the same chart
        has_existing = "Existing Customer" in filters["lead_sources"]
        if flags["new_customers"] and has_existing and len(filters["lead_sources"]) == 1:
            filters["lead_sources"] = []
            series = [{"label": "Existing customers", "filters": {"lead_sources": ["Existing Customer"]}},
                      {"label": "New customers", "filters": {"exclude_lead_sources": ["Existing Customer"]}}]
            trace.append("existing vs new customers → two series")
        elif flags["new_customers"] and filters["lead_sources"] and not has_existing:
            return Reading(question, {"intent": "clarify",
                                      "clarify_question": "Compare Google Ads jobs with all other new-customer jobs, "
                                                          "or Google Ads customers who are new vs repeat?",
                                      "clarify_options": [
                                          f"{filters['lead_sources'][0]} jobs vs all other new-customer jobs by year",
                                          f"{filters['lead_sources'][0]} customers, new vs repeat, by year"],
                                      "measure": "jobs", "split": "lead_source"},
                           "complete", reason="two readings", trace=trace)
        else:
            for key in ("tags", "salespeople", "areas", "regions", "lead_sources", "partners", "accounts", "work_types",
                        "products"):
                vals = filters[key]
                distinct_hits = dims_by_key.get(key, [])
                if len(vals) >= 2 and len(distinct_hits) >= 2:
                    if key == "tags":
                        groups = {terms.tag_group_of.get(v) for v in vals}
                        if len(groups) == 1:
                            split = "tag_" + groups.pop()
                    elif key == "products" and _in_one_family(vals, ctx) and len(distinct_hits) == 1:
                        continue
                    else:
                        split = ENTITY_DIM[key]
                    if split:
                        trace.append(f"compare → split {split}")
                        break
            if split:
                for key in ("work_types", "tags", "salespeople", "areas", "regions", "lead_sources", "products"):
                    other = ENTITY_DIM.get(key) or ("tag_" + (terms.tag_group_of.get(filters[key][0], "")
                                                              if filters[key] else ""))
                    if key == "tags" and filters[key]:
                        g = {terms.tag_group_of.get(v) for v in filters[key]}
                        other = "tag_" + g.pop() if len(g) == 1 else ""
                    if other and other != split and len(filters[key]) >= 2 and \
                            len(dims_by_key.get(key, [])) >= 2 and key != "products":
                        facet = other if not other.startswith("tag_") else None
                        if facet is None and not split.startswith("tag_"):
                            facet, split = split, other
                        trace.append(f"second comparison → one small chart per {facet}")
                        break
            if not split and flags["others"]:
                # "Google Ads vs other sources": every one of that kind, side by side
                for key in ("lead_sources", "salespeople", "areas", "partners", "products", "tags"):
                    if filters[key]:
                        fam = _family_said(s, ctx) if key == "products" else None
                        if fam:
                            _add(filters["products"], fam["products"])
                            split = "product"
                        else:
                            assumptions.append(f"Every {ENTITY_DIM.get(key, key).replace('_', ' ')} shown, "
                                               f"so {', '.join(filters[key])} can be compared with the rest")
                            split = ENTITY_DIM.get(key) or ("tag_" + terms.tag_group_of.get(filters[key][0], ""))
                            filters[key] = []
                        break

    # "north side coastal vs inland" -- a region with a coast word in its name against the other
    # side is the two sibling regions compared, not the 3-mile band inside one region.
    tf = terr.get("filters") or {}
    pk = getattr(ctx, "pack", None)
    if comparing and not split and len(filters["regions"]) == 1 and len(tf.get("coast_sides") or []) == 1 and pk:
        regs, _towns = pk.regions_and_towns_of(filters["regions"])
        if len(regs) == 1 and re.search(r"\s(?:coastal|inland)$", regs[0], re.I):
            stem = re.sub(r"\s+(?:coastal|inland)$", "", regs[0], flags=re.I).casefold()
            sibs = [r for r in pk.regions if re.fullmatch(re.escape(stem) + r"\s+(?:coastal|inland)", r.casefold())]
            if len(sibs) == 2:
                filters["regions"] = sibs
                split = "region"
                tf.pop("coast_sides", None)
                assumptions.append(f"{sibs[0]} and {sibs[1]} compared (the regions, not the within-3-miles coast band)")
                trace.append("region coastal vs inland → split region")
    elif len(filters["regions"]) >= 1 and tf.get("coast_sides"):
        assumptions.append(f"{', '.join(tf['coast_sides'])} homes within {', '.join(filters['regions'])} only")

    # 6. Splits named in words ("by town", "which products", "who sold", "where did jobs come from").
    explicit_dims = []
    for h in dims:
        if h.kind == "typeword":
            prods = filters["products"]
            if len(prods) == 1 and terms.product_detail_split.get(prods[0]):
                explicit_dims.append((h.start, terms.product_detail_split[prods[0]]))
            elif _is_family(prods, ctx) or len(prods) > 1:
                explicit_dims.append((h.start, "product"))
            elif filters["tags"]:
                g = terms.tag_group_of.get(filters["tags"][0])
                if g:
                    explicit_dims.append((h.start, "tag_" + g))
            continue
        d = h.value
        if d == "area" and h.phrase in ("region", "regions"):
            if getattr(getattr(ctx, "pack", None), "regions", None):
                explicit_dims.append((h.start, "region"))      # "by region" is the pack's regions
                continue
            assumptions.append("Charts has no regions, so this is by town")
        if d == "product" and h.phrase in ("category", "categories") and explicit_dims:
            continue                      # "each category" after "types": the same split
        if d == "area" and h.phrase in ("area", "areas") and any(
                key == "areas" and eh.end in (h.start, h.start - 1) for eh, key, _ in entity_hits):
            continue                      # "the Oakdale area" is a place, not a split
        explicit_dims.append((h.start, d))
        if d == "lead_source" and "jobs" in h.phrase:
            flags["jobs_word"] = True
    seen_d, ordered = set(), []
    for _, d in sorted(explicit_dims):
        if d not in seen_d:
            seen_d.add(d)
            ordered.append(d)
    if ordered:
        if split and split in ordered:
            ordered.remove(split)
        if not split:
            # "broken down by X" names the split; another dimension becomes one small chart each
            bd = re.search(r"\b(?:broken down|broken out|break(?:ing)? (?:it )?down|split|section on the bar|"
                           r"within a column|each bar split)\b.*", s)
            pick = ordered[0]
            if bd and len(ordered) > 1:
                later = [d for (pos, d) in sorted(explicit_dims)
                         if prep.tokens[pos].start >= bd.start()]
                pick = later[0] if later else ordered[0]
            split = pick
            ordered.remove(pick)
        if ordered:
            facet = ordered[0] if ordered[0] != split and not ordered[0].startswith("tag_") else None
            if facet is None and ordered[0].startswith("tag_") and not split.startswith("tag_"):
                facet, split = split, ordered[0]
    if flags["zip"]:
        split = split or "area"
        if not (getattr(getattr(ctx, "pack", None), "zips", None)):
            assumptions.append("Charts groups by town, not ZIP code")   # a pack with no ZIP map
    if split in ("tag_brand",) and filters["salespeople"] and not facet:
        pass
    # Asked about jobs a partner sent: the partner kind's jobs, by partner
    if split == "referral_partner" and not filters["lead_sources"] and not filters["partners"]:
        pass

    # a family is several products; every bar is split by product
    # (the total is still the bar's height). "Total <family>" keeps one bar.
    if not split and not facet and _is_family(filters["products"], ctx) and \
            not _has(s, ["total", "totals", "altogether", "overall", "combined", "in all"]):
        split = "product"
        flags["family_types"] = True          # drawn as stacked bars (see 9.)
    # "sales category (service / new install)" names the two categories:
    # show those two, not every work type (the pack's mappings name them: service_install)
    wt_names = list(getattr(getattr(ctx, "pack", None), "work_types", []) or [])
    si = ((getattr(getattr(ctx, "pack", None), "text", None) or {}).get("service_install") or {})
    si_new, si_service = str(si.get("install") or ""), str(si.get("service") or "")
    if (split == "work_type" or facet == "work_type") and not filters["work_types"] and \
            si_new in wt_names and si_service in wt_names and \
            any(h.value == "work_type" and re.search(r"\b(service|repair)\b", h.phrase) and "install" in h.phrase
                for h in dims):
        filters["work_types"] = [si_new, si_service]
        assumptions.append(str(si.get("note") or f"Service = {si_service}, new install = {si_new} "
                                                 "(other work types left out)"))

    # 7. Measure.
    measure = None
    if measures:
        # "revenue instead of jobs": the word before "instead of" is the new one
        inst = re.search(r"\b(\w+)\s+instead of\s+(\w+)\b", s)
        vals = [m.value for m in measures]
        if inst:
            for m_ in measures:
                if m_.phrase.split()[0] == inst.group(1) or m_.phrase.split()[-1] == inst.group(1):
                    measure = m_.value
        if not measure:
            prefer = [v for v in vals if v != "revenue"] if "avg_job" in vals or "units" in vals or \
                "customers" in vals else vals
            measure = "avg_job" if "avg_job" in vals else ("units" if "units" in vals else prefer[0])
            if "jobs" in vals and "revenue" in vals and _has(s, ["and revenue", "revenue and", "jobs and"]):
                measure = "jobs"
                spec["chart_type"] = "table"
                assumptions.append("Jobs and revenue both shown in the table")
    if flags["units_per_job"]:
        measure = "units"
        spec["agg"] = "per_job"
    if flags["jobs_per_customer"]:
        spec["agg"] = "jobs_per_customer"
        measure = "customers"
    if flags["repeat"]:
        if filters["lead_sources"] and _has(s, ["became", "become", "turned into", "came back", "come back",
                                                "returned", "who came back"]):
            filters["original_sources"] = filters["lead_sources"]
            filters["lead_sources"] = []
            spec.setdefault("filters", {})["repeat_customers_only"] = True
            measure = measure or "customers"
            trace.append("source + became repeat → original source, repeat customers only")
        elif flags["share"] or comparing or _has(s, ["came from returning", "from returning", "from repeat",
                                                      "from existing"]):
            series = [{"label": "Existing customers", "filters": {"lead_sources": ["Existing Customer"]}},
                      {"label": "New customers", "filters": {"exclude_lead_sources": ["Existing Customer"]}}]
            trace.append("returning customers' share → existing vs new")
        else:
            spec.setdefault("filters", {})["repeat_customers_only"] = True
            measure = measure or "customers"
    elif flags["new_customers"] and not series and not filters["lead_sources"]:
        excl["lead_sources"].append("Existing Customer")
    if not measure and (flags.get("jobs_word") or split == "referral_partner" or
                        _has(s, ["how many times", "times they", "referred us", "refer us", "sent us"])):
        measure = "jobs"
    weather = _weather_reading(s, weather_hits, measures, entity_hits, dims, flags, lag_pre) if weather_hits else None
    if wx_look.get("color") or wx_look.get("labels"):             # a look asked for on the weather
        if not weather:
            weather = {"var": wx_look.get("var") or "tmax", "view": "overlay", "_said": []}
            trace.append(f"weather look → {weather['var']} overlay")
        elif weather.get("view") == "bands" and wx_look.get("color"):
            weather["view"] = "overlay"                             # "colored by temperature" is a look, not bands
        if wx_look.get("var") and weather.get("var") in (None, "tmax") and wx_look["var"] != "tmax":
            weather["var"] = wx_look["var"]
        if wx_look.get("color"):
            weather["color_by"] = True
        if wx_look.get("labels"):
            weather["labels"] = True
    if weather:
        assumptions.extend(weather.pop("_said", []))
        if weather["view"] == "only":
            measures = []
            measure = None
        elif not measure and _has(s, ["busy", "busier", "busiest", "slow", "slower", "slowest", "installs",
                                      "booked", "bookings", "calls"]):
            measure = "jobs"
    pack_zips_ = getattr(getattr(ctx, "pack", None), "zips", None) or {}
    zip_off = bool(previous) and bool(_ZIP_OFF.search(s))         # "without the rates": nothing new to read
    zf = _zip_reading(s, zipfact_hits, zip_profile, zip_profile_strong, rate_base, measures, filters, question,
                      pack_zips_) if (zipfact_hits or zip_profile or rate_base) and pack_zips_ and not zip_off else None
    if not zf and zip_profile_strong and not zip_off:      # "census of jobs": said, never silently swallowed
        assumptions.append("Census figures go with ZIP codes: ask \"map of median income\" or \"tell me about 00011\"")
    if zf:
        assumptions.extend(zf.pop("_said", []))
        if zf.get("_measure") and not measure:
            measure = zf["_measure"]
        if zf["view"] == "profile" and not grains and not date_parts and not when.periods and not vs_hits and not dims:
            grains.append("year")                       # the named place's sales by year, under its profile
    if not measure:
        if flags["howmany"] and not (weather and weather["view"] == "only"):
            measure = "jobs"
            if filters["products"] or filters["tags"]:
                assumptions.append("\"How many\" counted as jobs")
        else:
            measure = "revenue"               # the usual reading: said in "How I read this", not as a note
    spec["measure"] = measure

    # 8. Time: periods, comparisons, grain, date parts, "to date".
    periods = list(when.periods)
    periods, aligned = _align_periods(periods, s)
    if aligned:
        assumptions.append(aligned)
    # "the last two years" with no year-by-year breakdown is the last
    # 24 months to the data date (not calendar 2024–2025). "Last 10 years", "by year" keep whole years.
    m_y = re.fullmatch(r"last_(\d+)y", periods[0]) if len(periods) == 1 else None
    if m_y and int(m_y.group(1)) <= 3 and not grains and not when.grain_hint and not _has(
            s, ["by year", "each year", "per year", "yearly", "year by year", "year over year", "every year",
                "annual", "annually"]):
        periods = [f"last_{int(m_y.group(1)) * 12}m"]
    grain = None
    if grains:
        grain = grains[-1]
    elif when.grain_hint:
        grain = when.grain_hint
    for rh in rank_hits:             # "busiest week", "best month ever"
        nxt = prep.tokens[rh.end].text if rh.end < len(prep.tokens) else ""
        if nxt in GRAIN_NOUNS:
            g = GRAIN_NOUNS[nxt]
            used.add(rh.end)
            if g in ("season", "day_of_week"):
                date_parts.append(g)
            else:
                grain = grain or g
        spec["rank"] = {"dir": rh.value, "n": 1}
    # "the big 3 products" is the top 3 ("big three" too)
    s_num = re.sub(r"\b(big|biggest)\s+(three|five|ten)\b", lambda m: m.group(1) + " " + {"three": "3", "five": "5", "ten": "10"}[m.group(2)], s)
    # "slow"/"quiet" rank only beside a time word ("which months are slow", "slow season");
    # "slow paying customers", "a quiet word" are not rankings
    if spec.get("rank", {}).get("dir") == "bottom" and re.search(r"\b(?:slow|quiet)\b", s) and \
            not re.search(r"\b(?:slowest|quietest|worst|least|lowest|fewest|smallest|weakest|bottom)\b", s) and \
            not re.search(r"\b(?:months?|weeks?|seasons?|quarters?|days?|time\s+of\s+(?:the\s+)?year|period)\b", s):
        spec.pop("rank")
        slow_unread = [w for w in ("slow", "quiet") if re.search(r"\b" + w + r"\b", s)]
    else:
        slow_unread = []
    m_top = re.search(r"\b(top|bottom|best|worst|big|biggest)\s+(\d{1,2})\b", s_num)
    if m_top:
        spec["top_n"] = max(3, min(15, int(m_top.group(2))))
        spec.pop("rank", None)

    # "from 2024 to 2025" with growth words: two years compared, not a range
    growth = flags["trend"] or _has(s, ["grew", "grow", "growth", "increase", "increased", "decrease", "decline",
                                         "change", "changed", "went up", "went down"])
    if growth and len(periods) == 1 and re.fullmatch(r"\d{4}\.\.\d{4}", periods[0]) and \
            re.search(r"\bfrom\s+\d{4}\s+to\s+\d{4}\b", s) and (split or filters["products"]):
        a, b = periods[0].split("..")
        periods = [a, b]
        when.vs_between_periods = True
        spec["compare"] = "growth"
        if spec.get("rank"):
            spec["rank"]["by"] = "change"            # "grew the most": ranked by the change, not the total
    # "the last decade, including 2026": the range runs on to now
    if len(periods) >= 2 and any(p.startswith("last_") and p.endswith("y") for p in periods) and \
            _has(s, ["including", "include", "plus", "and this year"]):
        lp = next(p for p in periods if p.startswith("last_") and p.endswith("y"))
        n = int(lp[5:-1])
        start = ctx.anchor.year - n
        periods = [f"{start}..now"]
        grain = grain or "year"
    if when.months and not periods:
        spec["months"] = when.months
        if when.to_date_day:
            spec.pop("months")
            spec["to_date"] = True
            spec["to_date_cut"] = list(when.to_date_day)
        grain = grain or "year"
        periods = ["all"]
    elif when.months and periods:
        if when.to_date_day:
            spec["to_date"] = True
            spec["to_date_cut"] = list(when.to_date_day)
        else:
            spec["months"] = when.months
    if when.to_date or flags["to_date"]:
        spec["to_date"] = True
    if when.compare:
        spec["compare"] = when.compare
        if when.compare == "prior_year" and len(periods) > 1 and not when.vs_between_periods:
            pass
    if date_parts:
        spec["date_part"] = date_parts[0]
        # "which months are slow" names a few months of the year, not one
        if spec["date_part"] == "month_of_year" and spec.get("rank") and re.search(r"\b(?:which|what)\s+months\b", s):
            spec["rank"]["n"] = 3
        # "which month was our best ever": every month on record, not the month of the year; and "which months
        # had sales over $100,000" (no ranking) asks about the months themselves
        if spec["date_part"] == "month_of_year" and re.search(r"\b(?:which|what)\s+months?\b", s) and \
                not re.search(r"\bmonths?\s+of\s+(?:the\s+)?year\b", s) and \
                (re.search(r"\b(?:ever|of\s+all\s+time|all[ -]time|on\s+record|in\s+history)\b", s)
                 or not spec.get("rank")):
            spec.pop("date_part")
            grain = "month"
            if not periods:
                periods = ["all"]
    years_only = all(re.fullmatch(r"\d{4}", p) for p in periods) and len(periods) > 1
    if years_only:
        ys = sorted(int(p) for p in periods)
        contiguous = ys == list(range(ys[0], ys[-1] + 1))
        named_split = terr.get("split") or (split if split not in (None, "", "none") else None)
        if (grain in ("month", "quarter", "week") or spec.get("date_part")) and contiguous and named_split \
                and not when.vs_between_periods and not spec.get("date_part"):
            # "coastal vs inland by month across 2024, 2025 and 2026" -- the split is
            # what is compared; the years run on as one range (a line per year would drop the split).
            # ANY split the words name wins the same way -- "revenue by product
            # by month across 2024 and 2025" draws the products across one run of months, not a line per year with
            # the products lost. The year overlay is a default look for an unsplit multi-year ask, never a request.
            periods = [f"{ys[0]}..{ys[-1]}"]
            assumptions.append(f"{', '.join(str(y) for y in ys[:-1])} and {ys[-1]} shown as one range, "
                               f"by {_SPLIT_SAID.get(named_split, named_split.replace('_', ' '))}")
        elif grain in ("month", "quarter", "week") or spec.get("date_part"):
            periods = [str(y) for y in ys]           # one line per year over the same calendar
            spec["overlay"] = True
            if named_split and when.vs_between_periods:
                # the words compared the years outright ("compare 2024 and 2025 … by product"); the years
                # win, and the split cannot ride along on a one-line-per-year chart. Say so instead of dropping it quietly.
                said = _SPLIT_SAID.get(named_split, named_split.replace("_", " "))
                assumptions.append(f"One line per year, so the {said} split is not shown; ask 'by {said} by month "
                                   f"across {ys[0]} and {ys[-1]}' to see the {said}s over both years instead")
        elif contiguous and not when.vs_between_periods:
            periods = [f"{ys[0]}..{ys[-1]}"]
            grain = grain or "year"
        else:
            periods = [str(y) for y in ys]
    elif len(periods) > 1:
        periods = sorted(periods, key=_period_sort_key)
        if grain in ("month", "quarter", "week"):
            spec["overlay"] = True
    if periods:
        spec["periods"] = periods
    # grain defaults (the hub's rules, with Charts' clarifications)
    grain_said = bool(grain)                    # a follow-up copies only a grain the words gave
    if not grain:
        if spec.get("date_part"):
            grain = "none"
        elif flags["avg_per_period"]:
            grain = "month" if not _has(s, ["weekly", "per week", "yearly", "per year", "annual"]) else \
                ("week" if _has(s, ["weekly", "per week"]) else "year")
        elif spec.get("months") and len(periods) <= 1:
            grain = "year"
        else:
            grain = _default_grain(periods, split, flags, s, series, dict(spec, facet=facet) if facet else spec)
    if weather and spec.get("overlay") and all(re.fullmatch(r"\d{4}", p) for p in periods) and len(periods) > 1:
        ys = sorted(int(p) for p in periods)              # the weather goes on one run of months
        if ys == list(range(ys[0], ys[-1] + 1)):
            periods = [f"{ys[0]}..{ys[-1]}"]
            spec["periods"] = periods
            spec.pop("overlay", None)
            assumptions.append(f"{ys[0]}–{ys[-1]} shown as one run of {grain or 'month'}s, so the weather can go beside it")
    if weather and not grain_said and not spec.get("date_part"):
        grain = _weather_grain(periods or ["all"], ctx, weather["view"])   # enough periods to compare
    if flags["avg_per_period"]:
        spec["agg"] = "avg_per_period"
    if spec.get("agg") == "jobs_per_customer":
        grain = "none"
        spec.pop("rank", None)
    tops = {rh.value for rh in rank_hits}
    if tops == {"top", "bottom"} and (_has(s, ["jobs", "job", "invoices", "invoice"])):
        spec["agg"] = "job_range"
        spec.pop("rank", None)
        grain = "none" if not grains else grain
    if spec.get("compare") == "prior_year" and len(periods) == 1 and ".." in periods[0] and grain == "year":
        spec["compare"] = "growth"               # "growth year over year since 2018"
    spec["grain"] = grain
    if periods and periods[0].startswith("last_") and periods[0].endswith("y") and grain != "year":
        n = int(periods[0][5:-1])
        periods[0] = f"last_{12 * n}m"           # "last 3 years by month" = the last 36 complete months
        spec["periods"] = periods

    # 9. Split, facet, filters, chart look.
    if terr.get("split") and not split:
        split = terr["split"]
        if grain not in (None, "none") and not grain_said and not when.periods:
            grain = "none"
            spec["grain"] = grain
    elif terr.get("split") and split != terr["split"]:
        if split in _FACETS and not facet:
            facet, split = split, terr["split"]
        else:
            assumptions.append(f"One split at a time: by {_SPLIT_SAID.get(split, split.replace('_', ' '))} "
                               f"(by {_SPLIT_SAID.get(terr['split'], terr['split'].replace('_', ' '))} was left out)")
    if split:
        spec["split"] = split
    if facet:
        spec["facet"] = facet
    if series:
        spec["series"] = series
    f = spec.setdefault("filters", {})
    for k in FILTER_KEYS:
        if filters[k]:
            f[k] = filters[k]
    if filters["original_sources"]:
        f["original_sources"] = filters["original_sources"]
    for k, vals in excl.items():
        if vals:
            f["exclude_" + k] = sorted(set(vals))
    for k_, v_ in (terr.get("filters") or {}).items():          
        f[k_] = v_
    if terr.get("_said"):
        assumptions.extend(terr["_said"])
    if not f:
        spec.pop("filters")
    if merge:
        spec["merge"] = merge
    # a name with a current and an older code → both codes: one person, one bar
    people = [h for h, key, vals in entity_hits if key == "salespeople" and len(vals) > 1]
    for h in people:
        vals = list(h.value)
        for extra in vals[1:]:
            spec.setdefault("merge", {})[extra] = vals[0]
    if charts_:
        ct = charts_[-1]
        if ct == "grouped_bar" and (spec.get("overlay") or grain in ("month", "week")) and len(periods) > 1:
            ct = "line"
        spec["chart_type"] = spec.get("chart_type") or ct
    look_by = "words" if charts_ else ("rule" if spec.get("chart_type") else "")   
    if orientation:
        spec["orientation"] = orientation
    if labels or flags["share"]:
        spec["labels"] = "percent" if flags["share"] else labels
        if flags["share"]:
            spec["share"] = True
    # the window film split over time is "each year's bar split by film types"
    if flags.get("family_types") and not spec.get("chart_type") and grain not in (None, "none") and not charts_:
        spec["chart_type"] = "stacked_bar"
        look_by = "rule"
    # "what share … came from each product", one period → a pie
    if flags["share"] and not spec.get("chart_type") and spec.get("split") and grain in (None, "none") \
            and len(periods) <= 1 and not spec.get("facet"):
        spec["chart_type"] = "pie"
        look_by = "rule"
    # a pack switch set by words: its subject's words and a value's words ("only the ones that say X")
    for sw in (getattr(getattr(ctx, "pack", None), "switches", None) or []):
        if getattr(sw, "words", None) and _has(s, sw.words):
            for val, phrases in (getattr(sw, "value_words", None) or {}).items():
                if val in sw.values and _has(s, phrases):
                    spec[sw.field] = val
                    break
    if notes_terms:
        assumptions.extend(notes_terms)
    near = re.search(r"\b(?:near|close to|around|in the area of)\s+(\w+)", s)
    if near and filters["areas"]:
        assumptions.append(f"\"Near {filters['areas'][0]}\" read as {filters['areas'][0]} itself (Charts has towns, "
                           "not distances)")
    if spec.get("split") == "lead_source" or spec.get("split") == "referral_partner":
        pass
    intent = "chart"
    if grain == "none" and not spec.get("split") and not series and len(periods) <= 1 and \
            not spec.get("date_part") and not spec.get("compare") and spec.get("agg") != "jobs_per_customer":
        intent = "number"
    if terr.get("split") and spec.get("split") == terr["split"] and not charts_ and not spec.get("chart_type") \
            and terr["split"] not in ("coast_band", "coast", "payment"):   # no map for these
        spec["chart_type"] = "map"                              # the territories, outlined
        look_by = look_by or "rule"
        spec["intent"] = intent = "chart"
    if zf:
        spec["zipfacts"] = {k: v for k, v in zf.items() if not k.startswith("_") and v is not None}
        intent = "chart"
        if zf["view"] in ("rate", "shade", "dots"):
            was = spec.get("split")
            if was in ("safety_site", "safety_council") and zf["view"] == "rate":
                pass                                       # per 1,000 of the territory's own homes
            elif was and was != "area":
                word = {"product": "product", "work_type": "work type", "salesperson": "salesperson",
                        "lead_source": "lead source", "account": "account"}.get(was, was.replace("tag_", "").replace("_", " "))
                assumptions.append(f"The Census counts homes and people by ZIP, so this is by ZIP, not by {word}")
            elif re.search(r"\b(?:towns?|cit(?:y|ies)|neighbou?rhoods?)\b", s) and not flags["zip"]:
                assumptions.append("The Census counts homes and people by ZIP, so this is by ZIP rather than by town")
            if not (was in ("safety_site", "safety_council") and zf["view"] == "rate"):
                spec["split"] = "area"
            if not charts_:
                spec["chart_type"] = "map"
                look_by = "rule"
            if zf["view"] == "dots" and spec.get("grain") not in (None, "none"):
                if grain_said:
                    assumptions.append("One dot per ZIP over the whole range, not per period")
                spec["grain"] = "none"
                spec.pop("date_part", None)
            if zf["view"] in ("shade", "rate") and not grain_said and (zf["view"] == "shade" or not when.periods):
                spec["grain"] = "none"
    if weather:
        gs = weather.pop("_group_split", None)
        if weather.get("by_group") and not spec.get("split") and gs:
            spec["split"] = gs                                       # "rainfall per region" splits by region
            trace.append(f"weather per {gs} → split {gs}")
        elif weather.get("by_group") and gs and spec.get("split") not in (None, "", "none", gs):
            # "jobs by product … with rainfall per region" -- the chart is by product, so one line
            assumptions.append(f"Weather per {_SPLIT_SAID.get(gs, gs)} needs the chart split by "
                               f"{_SPLIT_SAID.get(gs, gs)}; it is by {_SPLIT_SAID.get(spec['split'], spec['split'])}, so one weather line")
            weather.pop("by_group", None)
        spec["weather"] = weather
        intent = "chart"
        if weather["view"] in ("scatter", "only", "bands") or spec.get("chart_type") in (None, "", "auto"):
            spec.pop("chart_type", None)
            if weather["view"] == "overlay":
                # Words that named lines keep their chart_type and never reach here.
                spec["chart_type"] = "grouped_bar"         # columns, with the weather as dotted lines over them
                look_by = look_by or "rule"
    spec["intent"] = intent

    # 10. Follow-ups: start from the chart on screen and change what was asked.
    # "tell me about 00011" or "zips by wildfire risk" after another chart is a new question,
    # not that chart changed (it kept the dots' jobs and split, then the next one kept 00011 as a filter). A bare
    # "per 1,000 homes" or "now by home value" still changes the chart on screen.
    fresh = bool(zf) and not flags["followup"] and (zf["view"] == "profile" or bool(zf.get("fact")))
    n_tok = len(prep.tokens) if said_tokens is None else said_tokens   # the words as asked, size and all
    if previous and not fresh and (flags["followup"] or n_tok <= 5):
        spec = _apply_followup(previous, spec, when, measures, dims, entity_hits, s, grain_said=grain_said)
        if not look_by and spec.get("chart_type") not in (None, "", "auto"):
            look_by = "previous"             # the chart on screen's type, a preference only
        assumptions = [a for a in assumptions if not a.startswith("Showing revenue (no measure")]
        trace.append("follow-up: changed the chart on screen")
    # "revenue on a map" = revenue by town. Only when nothing else splits the chart: a
    # follow-up "show that on a map" of a chart by salesperson stays by salesperson (and is
    # drawn as bars, with compute's note saying why) rather than quietly becoming towns.
    # ZIP codes. A ZIP the pack's map knows is a filter ("revenue in 00012 and
    # 00013"), never silently dropped; "by zip" means the map, the one chart drawn by ZIP.
    pack_zips = getattr(getattr(ctx, "pack", None), "zips", None) or {}
    said = sorted({z for z in re.findall(r"(?<!\d)(\d{5})(?!\d)", question) if z in pack_zips})
    if said:
        spec.setdefault("filters", {})["zips"] = said
    other = sorted({z for z in re.findall(r"(?<!\d)(9\d{4})(?!\d)", question) if z not in pack_zips})
    if other:
        unread = list(unread) + other                # a ZIP outside the map's list: partly read, said so
    if pack_zips and _has(s, ["zip", "zips", "zip code", "zip codes", "zipcode", "zipcodes", "postal code"]) \
            and spec.get("split", "none") in ("none", "area") and spec.get("chart_type") in (None, "", "auto", "map"):
        spec["chart_type"] = "map"
        spec["split"] = "area"
        look_by = "words"
    if said and spec.get("chart_type") == "map":
        spec["split"] = "area"
    if spec.get("chart_type") == "map" and spec.get("split", "none") == "none":
        spec["split"] = "area"
        spec["intent"] = "chart"
    if spec.get("chart_type") == "map":
        spec["intent"] = "chart"

    # a question that names no measure, split, filter,
    # period or chart is answered with the default chart, and says so once.
    if not previous and not measures and not dims and not entity_hits and not charts_ and not weather and \
            not periods and not grains and not date_parts and not spec.get("split") and not any((spec.get("filters") or {}).values()):
        assumptions.append("Nothing specific was asked, so this is revenue by year; name a product, "
                           "a town, a person or a period for more")

    if weather:                                       # the words the weather reading used
        unread = [w for w in unread if w not in _WEATHER_FILLER]
    if zf or spec.get("zipfacts") or zip_off:         # the words the ZIP-facts reading used
        unread = [w for w in unread if w not in _ZIP_FILLER]
    if weak:
        unread = [w for w in unread if w not in ("older", "old", "newer", "younger")]
    # 11. Done.
    unread = list(unread) + [w for w in slow_unread if w not in unread]   # not a ranking here: said, not dropped
    if spec.get("top_n") and re.search(r"\b(?:big|biggest)\s+(?:\d{1,2}|three|five|ten)\b", s):
        unread = [w for w in unread if w not in ("big", "biggest")]     # "the big 3" = the top 3
    status = "partial" if unread else "complete"
    if names and status == "complete":
        status = "partial"                     # a name Charts doesn't know is never silently dropped
        assumptions.append("A name Charts doesn't have in its lists was left out")
    locked = _locked(spec, when, entity_hits, dims, measures)
    if names:
        unread = unread + names
    seen_a, final = set(), []
    for a in assumptions:
        if a not in seen_a:
            seen_a.add(a)
            final.append(a)
    return Reading(question, spec, status, unread=unread, locked=locked, assumptions=final[:4],
                   corrections=prep.corrections, trace=trace, look_by=look_by)


_PART = re.compile(r"^(\d{4})-(q[1-4]|h[12]|\d{2}|winter|spring|summer|fall)$", re.I)
_WHOLE_YEAR = ("all of", "full year", "whole year", "entire year", "the year", "all year", "calendar year")


def _align_periods(periods, s):
    """"Q3 2025 vs Q3 2024 and 2026": a bare year in a list of same-part periods takes the
    others' part (Q3 2026), the way people shorten a list when they speak. Only when every
    other period names the same part, and never when the question says the whole year."""
    if len(periods) < 2 or _has(s, _WHOLE_YEAR):
        return periods, ""
    parts = {m.group(2).lower() for m in (_PART.match(p) for p in periods) if m}
    bare = [p for p in periods if re.fullmatch(r"\d{4}", p)]
    shaped = [p for p in periods if _PART.match(p)]
    if len(parts) != 1 or not bare or len(bare) + len(shaped) != len(periods):
        return periods, ""
    part = parts.pop()
    out = [f"{p}-{part.upper() if part[0] in 'qh' else part}" if p in bare else p for p in periods]
    names = {"q1": "Q1", "q2": "Q2", "q3": "Q3", "q4": "Q4", "h1": "H1", "h2": "H2"}
    word = names.get(part) or (part.title() if part.isalpha() else
                               ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov",
                                "Dec"][int(part) - 1])
    return out, f"{', '.join(bare)} read as {word} {', '.join(bare)}, like the others"


def _multi_year(p):
    if p == "all":
        return True
    m = re.fullmatch(r"last_(\d+)([my])", p)
    if m:
        return (int(m.group(1)) > 24) if m.group(2) == "m" else int(m.group(1)) >= 2
    m = re.fullmatch(r"(\d{4})\S*\.\.(\S+)", p)
    if m:
        end = m.group(2)
        return end == "now" or (end[:4].isdigit() and int(end[:4]) > int(m.group(1)))
    return False


def _period_sort_key(p):
    m = re.match(r"(\d{4})", p)
    return (int(m.group(1)) if m else 9999, p)


def _default_grain(periods, split, flags, s, series, spec):
    if spec.get("compare") == "prior_year" and len(periods) == 1 and _multi_year(periods[0]):
        return "year"                     # "growth year over year since 2018"
    if spec.get("compare") or (len(periods) > 1 and not spec.get("overlay")):
        return "none"
    if split and spec.get("facet") and not flags["trend"]:
        return "none"
    if series and not periods:
        return "year" if flags["trend"] else "none"
    p = periods[0] if periods else "all"
    multi_year = p == "all" or (".." in p) or re.fullmatch(r"last_\d+y", p) or \
        (re.fullmatch(r"last_(\d+)m", p) and int(re.fullmatch(r"last_(\d+)m", p).group(1)) > 24)
    counted = re.search(r"\b(?:last|past|previous|over)\s+(?:\d+|two|three|four|six|twelve|eighteen)\s+(?:weeks?|months?)\b", s)
    if split and not flags["trend"] and not _multi_year(p) and not counted and \
            not _has(s, ["over time", "each", "every"]):
        return "none"                     # "gutter guard types for the last year": the split is the answer
    m = re.fullmatch(r"last_(\d+)([dwmy])", p)
    if m and int(m.group(1)) >= 2 and m.group(2) in "wmy":
        return {"w": "week", "m": "month", "y": "year"}[m.group(2)]     # "last 24 months": by month
    if split and (".." in p) and _multi_year(p) and not _has(s, ["total", "overall", "altogether"]):
        return "year"                     # "since 2016, by type": a trend by year
    if split and not flags["trend"]:
        return "none"
    if _has(s, ["total", "how many", "how much", "overall", "altogether"]) and not flags["trend"] and not multi_year:
        return "none"
    if multi_year:
        if _has(s, ["total", "overall", "altogether"]) and not flags["trend"] and p == "all":
            return "none"
        return "year"
    m = re.fullmatch(r"last_(\d+)([dwmy])", p)
    if m:
        n, u = int(m.group(1)), m.group(2)
        return {"d": "none", "w": "week", "m": "month" if n >= 2 else "none", "y": "year"}[u]
    if p in ("ytd",):
        return "month" if flags["trend"] else "none"
    return "none"


def _apply_followup(prev, new, when, measures, dims, entity_hits, s, grain_said=True):
    out = {k: (dict(v) if isinstance(v, dict) else list(v) if isinstance(v, list) else v) for k, v in prev.items()}
    if when.periods:
        out["periods"] = new.get("periods", out.get("periods"))
        for k in ("compare", "overlay", "to_date", "months"):
            out.pop(k, None)
            if k in new:
                out[k] = new[k]
    if measures:
        out["measure"] = new["measure"]
    if new.get("split"):
        out["split"] = new["split"]
    if new.get("chart_type"):
        out["chart_type"] = new["chart_type"]
    nf = new.get("filters") or {}
    if nf:
        of = out.setdefault("filters", {})
        additive = _has(s, ["also", "add", "plus", "and also"])
        for k, v in nf.items():
            if isinstance(v, list) and additive:
                _add(of.setdefault(k, []), v)
            else:
                of[k] = v
    # "show that on a map" / "as a pie" got the default grain (year) of an
    # undated question and turned the chart on screen into one by year. Only a grain that was said counts.
    if new.get("grain") and (dims or when.grain_hint or (grain_said and new["grain"] != "none")):
        out["grain"] = new["grain"]
    out["intent"] = "chart" if out.get("split") or out.get("grain") not in (None, "none") else new.get("intent", "chart")
    if new.get("weather"):                              # "now rainfall", "as dots"
        w = dict(prev.get("weather") or {})
        w.update(new["weather"])
        out["weather"] = w
        if new["weather"].get("view") == "only" and (prev.get("weather") or {}).get("view") != "only":
            out["weather"]["view"] = "overlay" if prev.get("weather") else "only"
    if prev.get("weather") and not new.get("weather"):   # "as dots", "as a line" about the weather on screen
        if re.search(r"\b(?:scatter|dots?|as points)\b", s):
            out["weather"] = dict(prev["weather"], view="scatter")
        elif re.search(r"\b(?:as (?:a )?line|second axis|overlay|over the bars)\b", s):
            out["weather"] = dict(prev["weather"], view="overlay")
    if re.search(r"\b(?:without|no|hide|remove|drop|take off|turn off)\s+(?:the\s+)?weather\b", s):
        out.pop("weather", None)
    # "now by home value", "per household instead", "as dots", "without the rates"
    pz = prev.get("zipfacts") if isinstance(prev.get("zipfacts"), dict) else None
    nz = new.get("zipfacts") if isinstance(new.get("zipfacts"), dict) else None
    if nz:
        z = dict(pz or {})
        if pz and nz.get("view") == "shade" and nz.get("fact") and pz.get("view") == "dots":
            z["fact"] = nz["fact"]                      # a new fact for the dots on screen
        elif pz and nz.get("view") == "rate" and pz.get("view") == "dots":
            z["per"] = nz.get("per")                    # the dots, per 1,000 homes
        else:
            z = dict(nz)
        out["zipfacts"] = z
        if z.get("view") in ("rate", "shade", "dots"):
            out["split"] = "area"
            out["chart_type"] = new.get("chart_type") or out.get("chart_type") or "map"
            if out["chart_type"] not in ("map", "table"):
                out["chart_type"] = "map"
            if z.get("view") == "dots":
                out["grain"] = "none"
    elif pz:
        if re.search(r"\b(?:scatter|dots?|as points)\b", s) and pz.get("fact"):
            out["zipfacts"] = dict(pz, view="dots")
            out["grain"] = "none"
        elif re.search(r"\b(?:on|as) (?:a |the )?map\b", s) and pz.get("view") == "dots":
            out["zipfacts"] = dict(pz, view="shade")
    if _ZIP_OFF.search(s):
        out.pop("zipfacts", None)
    return out


def _locked(spec, when, entity_hits, dims, measures):
    locked = []
    if when.periods or when.months:
        locked += ["periods", "compare", "to_date", "months", "overlay"]
    if entity_hits:
        locked.append("filters")
    if dims:
        locked += ["split", "facet"]
    if measures:
        locked.append("measure")
    if spec.get("date_part"):
        locked.append("date_part")
    if spec.get("weather"):
        locked.append("weather")
    if spec.get("zipfacts"):
        locked.append("zipfacts")
    return locked


# ---------------------------------------------------------------- weather
_LAG_N = {"a": 1, "one": 1, "two": 2, "three": 3, "four": 4}
_WEATHER_FILLER = set("""after following later before affect affects affected affecting effect effects impact impacts
impacted correlate correlates correlated correlation correlations relationship related relate link linked links
influence influences influenced depend depends dependent drive drives driven scatter dots busy busier busiest slow
slower slowest business demand when gets get getting outside out""".split())
_NOT_SALES_MEASURES = {"how much", "how many", "count", "number of", "times", "how many times"}


_WEATHER_ANY = re.compile(r"\b(?:" + "|".join(sorted({re.escape(w) for ws in L.WEATHER_WORDS.values() for w in ws},
                                                       key=len, reverse=True)) + r")\b")


def _weather_words(hits, prep, s, measures=(), entity_hits=()):
    """The weather words that mean the weather. A plain weather noun always does ("rain", "temperature");
    an everyday adjective ("hot", "cold", "dry") only beside one, in a weather phrase ("when it's hot", "on
    rainy days", "how hot was it"), or before a time word with no sales word near ("the hottest month last
    year" is weather; "our hottest month for sales" is the best month)."""
    strong = [h for h in hits if h.phrase not in L.WEATHER_WEAK]
    if strong or re.search(L.WEATHER_BANDS, s):
        return hits
    if re.search(r"\bhow (?:hot|cold|warm|wet|dry|rainy)\b", s):
        return hits
    sales = [m for m in measures if m.phrase not in _NOT_SALES_MEASURES] or \
        [e for e in entity_hits if e[1] != "areas"] or _has(s, L.SALES_CONTEXT + ["best", "top", "record"])
    if sales:
        return []
    out = []
    toks = [t.text for t in prep.tokens]
    for h in hits:
        nxt = toks[h.end] if h.end < len(toks) else ""
        prev = toks[h.start - 1] if h.start > 0 else ""
        if nxt in L.WEATHER_WEAK_NEXT or (prev in L.WEATHER_WEAK_PREV and nxt in ("", "out", "outside", "and", "or")):
            out.append(h)
    return out


def _weather_reading(s, hits, measures, entity_hits, dims, flags, lag_pre=None):
    """{var, view, lag} for a question with weather words, plus "_said" (assumptions to show)."""
    said = []
    vars_ = [h.value for h in hits]
    var = vars_[0]
    for special in ("rain_days", "hot_days", "tavg", "tmin"):
        if special in vars_:
            var = special
            break
    kinds = {"prcp" if v in ("prcp", "rain_days") else "temp" for v in vars_}
    if len(kinds) > 1:
        first = "rainfall" if var in ("prcp", "rain_days") else "temperature"
        said.append(f"One kind of weather at a time: showing {first}; ask again for the other")
    view = "overlay"
    if re.search(L.WEATHER_BANDS, s):
        view = "bands"
        if var == "rain_days":
            var = "prcp"
        if var == "hot_days":
            var = "tmax"
    elif re.search(L.WEATHER_SCATTER, s) or re.search(r"\b(?:vs\.?|versus|against|compared (?:to|with))\s+(?:the\s+)?"
                                                       r"(?:\w+\s+)?(?:weather|temp\w*|rain\w*|heat|precip\w*)", s):
        view = "scatter"
    sales_measures = [m for m in measures if m.phrase not in _NOT_SALES_MEASURES]
    sales_entities = [e for e in entity_hits if e[1] not in ("areas",)]
    if view != "bands" and not sales_measures and not sales_entities and not dims and \
            not _has(s, L.SALES_CONTEXT):
        view = "only"
    if var == "tmax" and all(h.phrase == "weather" for h in hits):
        said.append("Weather read as the average daily high; ask about rain for rainfall")
    lag = lag_pre if view != "only" else None
    m = re.search(L.WEATHER_LAG, s)
    if lag:
        pass
    elif m and view != "only":
        n = m.group("n")
        n = int(n) if n and n.isdigit() else _LAG_N.get(n or "a", 1)
        unit = m.group("unit").rstrip("s")
        lag = {"n": max(1, min(12, n)), "unit": unit}
    elif view != "only" and re.search(r"\b(?:after|following)\s+(?:a |the |it )?(?:rain\w*|storm\w*|heat\w*|hot|cold|wet)\b", s):
        lag = {"n": 1, "unit": "day" if view == "bands" else "week"}
    out = {"var": var, "view": view, "_said": said}
    if lag:
        out["lag"] = lag
    # the weather of each group's own ZIPs.
    # "rainfall per region", "rain for each town", "each region's rainfall", "the weather in each" -- the kind of
    # place named (region, town, coastal / inland, ZIP, site, council) also names the split when the words did not.
    m = re.search(_WX_PER_GROUP, s)
    if m and view in ("overlay", "scatter"):
        out["by_group"] = True
        kind = (m.group("kind") or "").rstrip("s")
        out["_group_split"] = _WX_GROUP_SPLIT.get(kind)
    return out


_WX_PER_GROUP = re.compile(r"\b(?:per|for each|in each|of each|each|every|by each|across each|their own|respective)\s+"
                           r"(?:of\s+(?:the|these|those)\s+)?(?P<kind>regions?|towns?|cit(?:y|ies)|areas?|places?|groups?|sides?|"
                           r"bands?|zips?(?:\s*codes?)?|zip\s*codes?|sites?|councils?|coasts?|neighbou?rhoods?|communit(?:y|ies))\b"
                           r"|\b(?:each|every)\s+(?:one|of them|of these|of those)\b"
                           r"|\b(?:for|in|of)\s+each\s*(?:$|[,.;!?])")
_WX_GROUP_SPLIT = {"region": "region", "town": "area", "city": "area", "citie": "area", "area": "area", "place": "area",
                   "neighborhood": "area", "neighbourhood": "area", "community": "area", "communitie": "area",
                   "side": "coast", "coast": "coast", "band": "coast_band", "zip": "area", "zip code": "area",
                   "zipcode": "area", "site": "safety_site", "council": "safety_council"}


def _weather_grain(periods, ctx, view="overlay"):
    """A weather chart needs periods to compare: by month over a year and a half or more, by week down to two
    months, by day below that. The weather by itself reads best by month down to four months."""
    from . import periods as P
    try:
        ws = [P.resolve(t, ctx.anchor, ctx.first) for t in periods]
    except Exception:
        return "month"
    days = sum(w.days for w in ws)
    if view in ("only", "bands"):
        return "month" if days >= 120 else ("week" if days >= 28 else "day")
    if days >= 540:
        return "month"
    if days >= 60:
        return "week"
    return "day"


# ---------------------------------------------------------------- ZIP facts
_ZIP_FILLER = set("""shade shaded shades shading color colour colored coloured tint tinted heatmap heat tell about like
profile demographics demographic census data info information facts fact vs versus against compare compared
relationship correlation correlate correlates correlated link linked links related relate affect affects effect
impact track tracks tracked goes go dots dot scatter area areas neighborhood neighborhoods higher lower high low
more less fewer every lives live who rate rates rated whats map market no without hide remove drop raw plain
actual""".split())


_ZIP_OFF = re.compile(r"\b(?:without|no|hide|remove|drop|take off|turn off)\s+(?:the\s+)?(?:rates?|census|facts?|"
                      r"demographics|shading|profile)\b|\b(?:raw|plain|actual)\s+(?:numbers|totals|revenue|counts?)\b|"
                      r"\bnot per\b")


def _zip_reading(s, hits, profile, strong, rate_base, measures, filters, question, pack_zips):
    """{view, fact, per} for a question about what ZIPs are like, plus "_said" and "_measure". A profile word
    ("tell me about", "what's … like") counts only with a named ZIP or town; "census" or
    "demographics" alone, with no sales word, shades the map by income."""
    from .. import zipfacts as Z
    said = []
    facts = []
    for h in hits:
        if h.value not in facts:
            facts.append(h.value)
    fact = facts[0] if facts else None
    if len(facts) > 1:
        said.append(f"One ZIP fact at a time: {Z.label_of(fact).lower()}; ask again for "
                    f"{Z.label_of(facts[1]).lower()}")
    if fact == "owner_pct" and re.search(r"\brent(?:ers?|ing|als?)\b", s):
        said.append("Renters read as the owner-occupied share (the rest rent)")
    if fact == "single_family_pct" and re.search(r"\b(?:condos|apartments|townhomes)\b", s):
        said.append("Condos and apartments read as the share of single-family detached homes (the rest aren't)")
    sales = [m for m in measures if m.value in ("revenue", "jobs", "customers", "units", "avg_job")]
    named = [z for z in re.findall(r"(?<!\d)(\d{5})(?!\d)", question) if z in pack_zips] or list(filters.get("areas") or [])
    out = {"_said": said}
    if rate_base:
        out.update(view="rate", per=rate_base)
        if fact and re.search(L.ZIP_DOTS, s):
            out.update(view="dots", fact=fact)
        if re.search(L.ZIP_PENETRATION, s) and not sales:
            out["_measure"] = "customers"
            said.append("Read as customers per 1,000 households in each ZIP")
    elif fact and (re.search(L.ZIP_DOTS, s) or sales) and not re.search(L.ZIP_SHADE, s):
        out.update(view="dots", fact=fact)
    elif fact:
        out.update(view="shade", fact=fact)
    elif profile and named:
        out.update(view="profile")
    elif profile and strong and not sales:
        out.update(view="shade", fact="income")
        said.append("Shaded by median household income; pick another fact under Shade, or name a ZIP for its profile")
    else:
        return None
    if rate_base:
        m = re.search(L.ZIP_RATE, s)
        typed = re.search(r"\b(100|hundred|10 000|10000)\b", m.group(0)) if m else None
        if typed:
            said.append(f"Rates are per 1,000 (asked per {typed.group(1).replace(' ', ',')})")
    return out


# ---------------------------------------------------------------- the region's fire-safe sites and councils
# The words that name them are the region pack's (region.json "words": {"safety_site": [...],
# "safety_council": [...]}); a region that names none has none, and nothing here matches.
_NEVER = re.compile(r"(?!x)x")
_WORD_RX = {}


def _region_words(kind):
    from .. import region as R
    reg = R.current()
    words = tuple(str(w).casefold().strip() for w in ((reg.data.get("words") or {}).get(kind) or ()) if str(w).strip())
    key = (reg.id, kind, words)
    if key not in _WORD_RX:
        if not words:
            _WORD_RX[key] = _NEVER
        else:
            alts = "|".join(re.escape(w).replace(r"\ ", r"[ -]?") + r"s?" for w in sorted(words, key=len, reverse=True))
            _WORD_RX[key] = re.compile(r"\b(?:" + alts + r")\b")
    return _WORD_RX[key]


def _site_words():
    return _region_words("safety_site")


def _council_words():
    return _region_words("safety_council")


_SPLIT_BEFORE = re.compile(r"\b(?:by|per|each|every|across|which|what|compare|compared|between)\s+(?:\w+\s+){0,2}$")
_FILTER_BEFORE = re.compile(r"\b(?:in|inside|within|from|among|at|for)\s+(?:the\s+|a\s+|our\s+|any\s+)?$")
_NOUN_AFTER = re.compile(r"\s+(?:jobs?|customers?|clients?|revenue|sales|work|invoices?|homes?)\b")
_NAME_DROP = re.compile(r"\b(?:hoa|homeowners association|community association|association|community)\b")


def _name_forms(name):
    n = re.sub(r"[^a-z0-9 ]+", " ", str(name).casefold())
    n = re.sub(r"\s+", " ", n).strip()
    short = re.sub(r"\s+", " ", _NAME_DROP.sub(" ", n)).strip()
    for rx in (_site_words(), _council_words()):
        short = re.sub(r"\s+", " ", rx.sub(" ", short)).strip()
    group = re.sub(r"\s+(?:north|south|east|west)$", "", short).strip()     # "oak hills" = North and South
    return {x for x in (n, short, group) if x}


# a customer's place (places.py): "by sales tax area", "by city for sales tax", "by distance to the coast",
# "within 3 miles of the coast". A fact word alone ("sales tax rate", "distance to the coast") stays a ZIP fact.
_TAX_SPLIT = re.compile(r"\b(?:by|per|each|across|which)\s+(?:the\s+)?(?:sales\s+)?tax\s+(?:areas?|city|cities|"
                        r"jurisdictions?|districts?|rates?|zones?)\b|\bby\s+(?:city|cities|jurisdiction)\s+for\s+"
                        r"(?:the\s+)?(?:sales\s+)?tax\b")
_COAST_SPLIT = re.compile(r"\b(?:by|per|across)\s+(?:the\s+)?(?:distance|miles|how\s+far)\s+(?:to|from)\s+"
                          r"(?:the\s+)?(?:coast|ocean|beach|shore|water)\b|\bby\s+(?:coast|coastal)\s+distance\b")
_COAST_WITHIN = re.compile(r"\b(?:within|under|less\s+than)\s+(a|one|\d+(?:\.\d+)?)\s+miles?\s+(?:of|from)\s+"
                           r"(?:the\s+)?(?:coast|ocean|beach|shore|water)\b")


_FACETS = ("product", "work_type", "salesperson", "area", "account")           # spec2.FACETS, less "none"
_SPLIT_SAID = {"tax_area": "sales tax area", "coast_band": "distance to the coast", "safety_site": "fire-safe site",
               "coast": "coastal or inland", "payment": "paid or unpaid", "region": "region",
               "safety_council": "fire-safe council", "area": "town", "work_type": "work type"}
_PLACE_STRONG = re.compile(r"\b(?:zips?|zip codes?|zipcodes?|postal codes?)\b")
_PRODUCT_AFTER = r"\s+(?:units?|items?|products?|seasons?)\b"   # "patio light units", "fire season"

_ZIPWORD = re.compile(r"\b(?:zips?|zip codes?|zipcodes?|postal codes?)\b")


def _coast_known():
    """Does the region pack declare a coastline? Without one, coast words are not read as a place split."""
    try:
        from .. import region as R
        return R.current().has("coast")
    except Exception:
        return False


def _place_reading(s):
    out = {"_spans": [], "_said": []}
    if _ZIPWORD.search(s):
        return out                     # "zips by distance to the coast": the ZIP fact, not the customers' split
    m = _TAX_SPLIT.search(s)
    if m and re.search(r"\b(?:colou?red|shaded|shade|shading|tinted)\s*$", s[:m.start()]):
        m = None                       # "towns colored by tax rate" is a shading by the ZIP fact
    if m:
        out["split"] = "tax_area"
        out["_spans"].append((m.start(), m.end()))
    m = _COAST_SPLIT.search(s) if _coast_known() else None
    if m:
        out["split"] = out.get("split") or "coast_band"
        out["_spans"].append((m.start(), m.end()))
    m = _COAST_WITHIN.search(s) if _coast_known() else None
    if m:
        from ..places import COAST_BANDS
        n = 1.0 if m.group(1) in ("a", "one") else float(m.group(1))
        # the band that holds N is kept ("within 5 miles" keeps 3–10 mi too), never cut below what was asked
        bands, low = [], 0.0
        for top, label in COAST_BANDS:
            if low < max(n, 0.01):
                bands.append(label)
            low = top if top is not None else low
        if bands:
            out.setdefault("filters", {})["coast_bands"] = bands
            out["_spans"].append((m.start(), m.end()))
            if n not in (1, 3, 10, 25):
                edge = next((top for top, _l in COAST_BANDS if top is not None and top >= n), None)
                out["_said"].append((f"Within {edge:g} mile{'s' if edge != 1 else ''} of the coast, the nearest band edge past {n:g}" if edge
                                     else "Every distance from the coast") +
                                    " (the distance bands are under 1, 1–3, 3–10 and 10–25 miles)")
    return out


# "coastal" and "inland" are places, read from the same miles to the region's coastline
# as the distance bands: coastal = within the region's coast_side_miles (3 unless it says), inland = the rest.
# Only where the region pack declares a coastline. Both named, or "by
# coast", "coast vs inland", "coastal or inland" → a split; one named → a filter. "The coast" as a place
# ("near/on/along the coast", "coastal towns") is coastal; "distance to the coast" stays the bands.
_COAST_SIDE_WORD = {"coastal": "Coastal", "inland": "Inland", "coast": "Coastal", "beach": "Coastal",
                    "seaside": "Coastal", "oceanside": None}       # "Oceanside" is a town's name in several states
_COAST_BOTH = re.compile(r"\b(?:coastal|inland|coast)\b[^.?;]{0,24}?\b(?:vs\.?|versus|v\.?|against|and|or|/|compared\s+(?:to|with))"
                         r"\s*(?:the\s+)?\b(?:coastal|inland|coast)\b"
                         r"|\b(?:by|per|across|between|split\s+by|broken\s+down\s+by)\s+(?:the\s+)?(?:coast(?:al)?(?:\s*(?:(?:or|and|vs\.?|versus|/)\s*)?inland)?"
                         r"|inland(?:\s*(?:(?:or|and|vs\.?|versus|/)\s*)?coast(?:al)?)?)\b")   # "by coast inland": the "/" is normalised away
_COAST_ONE = re.compile(r"\b(coastal|inland|coast)\b(?!\s+(?:empire|revenue\s+service|guard|highway|hwy))"
                        r"|\b(?:near|on|along|at|by|beside|close\s+to)\s+the\s+(coast|beach|ocean|shore|water)\b")
_COAST_BANDWORDS = re.compile(r"\b(?:distance|miles|how\s+far)\s+(?:to|from)\s+(?:the\s+)?(?:coast|ocean|beach|shore|water)\b"
                              r"|\bwithin\s+\S+\s+miles?\s+(?:of|from)\b")


def _region_phrase_spans(s, ctx):
    """the spans of drawn region words that carry a coast word ("north side coastal", "north coastal"),
    so the coast reading leaves them alone -- they name a region, not the 3-mile band."""
    pack = getattr(ctx, "pack", None)
    words = list(getattr(pack, "region_words", None) or {}) + list(getattr(pack, "regions", None) or {})
    out = []
    for w in words:
        w = str(w).casefold()
        if re.search(r"\b(?:coastal|inland|coast)\b", w):
            for m in re.finditer(r"\b" + re.escape(w) + r"\b", s):
                out.append((m.start(), m.end()))
    return out


def _coast_reading(s, skip=()):
    out = {"_spans": [], "_said": []}
    if not _coast_known() or _ZIPWORD.search(s) or _COAST_BANDWORDS.search(s):
        return out                     # the ZIP fact / the distance bands keep their own words
    inside = lambda m: any(a <= m.start() < b for a, b in skip)
    m = _COAST_BOTH.search(s)
    if m and inside(m):
        m = None
    if m:
        out["split"] = "coast"
        out["_spans"].append((m.start(), m.end()))
        return out
    sides, spans = [], []
    for m in _COAST_ONE.finditer(s):
        if inside(m):
            continue
        word = (m.group(1) or "coast").lower()
        side = _COAST_SIDE_WORD.get(word)
        if side and side not in sides:
            sides.append(side)
        spans.append((m.start(), m.end()))
    if len(sides) == 1:
        out.setdefault("filters", {})["coast_sides"] = sides
        out["_spans"].extend(spans)
    elif len(sides) == 2:
        out["split"] = "coast"
        out["_spans"].extend(spans)
    return out


# paid / unpaid are FreshBooks' own
# statuses. "paid jobs", "collected", "money in" → paid only; "unpaid", "outstanding", "owed", "overdue",
# "open invoices", "part paid" → unpaid only; "paid vs unpaid", "by payment status", "paid or not" → a split.
# "paid search / ads / social / leads / media / traffic" are lead sources, never a payment word.
_PAY_NOT = r"(?!\s+(?:search|ads?|advertising|social|leads?|media|traffic|clicks?|campaigns?|placements?|listings?))"
_PAY_SPLIT = re.compile(r"\bpaid\b" + _PAY_NOT + r"\s*(?:vs\.?|versus|v\.?|and|or|/|against)\s*(?:un\s*paid|not\s+paid|outstanding|owed|open)\b"
                        r"|\b(?:un\s*paid|outstanding|owed|open)\s*(?:vs\.?|versus|v\.?|and|or|/|against)\s*paid\b"
                        r"|\b(?:by|per|split\s+by|broken\s+down\s+by)\s+(?:payment|paid)\s+(?:status|state|stats)\b"   # "status" normalises to "stats"
                        r"|\bpaid\s+or\s+not\b|\bpaid\s+and\s+not\s+paid\b")
_PAY_PAID = re.compile(r"\b(?:fully\s+)?paid\b" + _PAY_NOT + r"(?!\s+(?:vs|versus|or|and|against)\b)"
                       r"|\b(?:collected|money\s+(?:in|collected|received)|cash\s+(?:in|collected|received)|payments?\s+received)\b"
                       r"|\bthat\s+(?:have\s+)?paid\b|\bwho\s+(?:have\s+)?paid\b")
_PAY_UNPAID = re.compile(r"\b(?:un\s*paid|not\s+(?:yet\s+)?paid|outstanding|still\s+owed|owed|owing|overdue|past\s+due|"
                         r"open\s+invoices?|unsettled|part(?:ly|ially)?\s+paid|half\s+paid|awaiting\s+payment|unbilled)\b")


def _payment_reading(s):
    out = {"_spans": [], "_said": []}
    m = _PAY_SPLIT.search(s)
    if m:
        out["split"] = "payment"
        out["_spans"].append((m.start(), m.end()))
        return out
    mu = _PAY_UNPAID.search(s)
    if mu:
        out.setdefault("filters", {})["payment"] = "unpaid"
        out["_spans"].append((mu.start(), mu.end()))
        return out
    mp = _PAY_PAID.search(s)
    if mp and not re.search(r"\bpart(?:ly|ially)?\s*$", s[:mp.start()]):
        out.setdefault("filters", {})["payment"] = "paid"
        out["_spans"].append((mp.start(), mp.end()))
    return out



# "colored / shaded / in a gradient by temperature (rain)", "orange to blue", "red to blue", "warm to cool",
# "warmest to coolest", "hottest to coldest" → every bar colored by its period's weather; "label / write / list
# the weather (temperature, rain) on / above each bar (month)" → the weather written on each bar. "A green line
# for the rainfall" and "average rainfall" are the overlay line that already draws: the words are read, not
# left over. The weather itself (which var) comes from the phrase when no other weather word names it.
_WX_TEMP = r"(?:temperature|temps?|heat|hot(?:test|ter)?|warm(?:est|er)?|cool(?:est|er)?|cold(?:est|er)?|highs?|lows?)"
_WX_RAIN = r"(?:rain(?:fall|y)?|precip\w*|wet(?:test|ter)?|dry|dri(?:est|er)|showers?|storms?)"
_WX_COLOR = re.compile(r"\b(?:colou?r(?:ed|s)?|shad(?:ed|e|ing)|tint(?:ed)?|gradient|heat\s*map|heatmap)\b[^.?;]{0,40}?\b(?:by|from|with|of|denoting|showing|for|according to)\s+"
                       r"(?:the\s+|their\s+|each\s+month'?s?\s+|relative\s+)?(?:(?P<t>" + _WX_TEMP + r")|(?P<r>" + _WX_RAIN + r"))\b"
                       r"|\b(?:(?:orange|red|yellow|warm)\s+(?:to|→|->)\s+(?:blue|cool|cold)|(?:blue|cool|cold)\s+(?:to|→|->)\s+(?:orange|red|yellow|warm))\b"
                       r"|\b(?:warmest|hottest)\s+(?:to|and|vs\.?|versus)\s+(?:coolest|coldest)\b|\b(?:coolest|coldest)\s+(?:to|and)\s+(?:warmest|hottest)\b"
                       r"|\b(?:a\s+)?gradient\b|\bby\s+how\s+(?:hot|warm|cold|wet|rainy)\b")
_WX_LABEL = re.compile(r"\b(?:label(?:led|ed|s)?|write|written|list(?:ed)?|show(?:n)?|print(?:ed)?|annotat\w+|mark(?:ed)?|note(?:d)?)\s+"
                       r"(?:the\s+)?(?:(?:average|avg|mean|typical)\s+)?(?:(?P<t>" + _WX_TEMP + r")|(?P<r>" + _WX_RAIN + r")|weather)\s*(?:range)?\b"
                       r"[^.?;]{0,30}?\b(?:on|above|over|under|beside|next to|at|for)\s+(?:each|every|the)\b"
                       r"|\b(?:on|above|over)\s+(?:each|every)\s+(?:month|bar|week|quarter|year|period|column|point)\b[^.?;]{0,20}?"
                       r"\b(?:list|write|show|label|print|note)\b[^.?;]{0,30}?\b(?:(?P<t2>" + _WX_TEMP + r")|(?P<r2>" + _WX_RAIN + r")|weather)\b")
_WX_LABEL2 = re.compile(r"\b(?:label(?:led|ed|s)?|write|mark(?:ed)?|annotat\w+|tag(?:ged)?)\s+(?:each|every)\s+(?:month|bar|week|quarter|year|period|column|point)\s+"
                        r"with\s+(?:the\s+|its\s+|their\s+)?(?:(?:average|avg|mean)\s+)?(?:(?P<t>" + _WX_TEMP + r")|(?P<r>" + _WX_RAIN + r")|weather)\b")
# the words around a color ask: "use orange to blue to represent the warmest to cooler months", "denoting the relative
# hottest and coldest points of each month" ("denoting" is read as "renting" by the spelling fix)
_WX_COLOR_TAIL = re.compile(r"\b(?:use|using)\s+(?=(?:orange|red|yellow|blue|cool|warm|a\s+gradient))"
                            r"|\b(?:to\s+)?(?:represent(?:ing)?|renting|denot\w*|indicat\w*|show(?:ing|s)?|mark(?:ing|s)?|for)\s+(?:the\s+)?(?:relative(?:ly)?\s+)?"
                            r"(?:warmest|hottest|coolest|coldest|hotter|cooler|warmer|colder|wettest|driest|warm|hot|cool|cold)"
                            r"[^.?;]{0,30}?\b(?:months?|days?|weeks?|points?|periods?|bars?|ones?|times?)\b(?:\s+of\s+(?:each|every|the)\s+\w+)?")
_WX_LINE = re.compile(r"\b(?:(?:a|the|as a)\s+)?(?:green|red|blue|orange|gr[ae]y|black|dashed|dotted|thin|second|separate)\s+line\s*(?:for|of|showing|with|that shows)?\b"
                      r"|\b(?:as\s+)?(?:a|the)\s+line\s+(?:for|of|showing|that shows)\b|\bon\s+(?:a|the)\s+(?:second|right)\s+axis\b")
_WX_AVG = re.compile(r"\b(?:average|avg|mean|typical|usual)\s+(?=(?:monthly\s+|daily\s+|weekly\s+)?(?:" + _WX_RAIN + r"|" + _WX_TEMP + r"|weather)\b)")
_WX_RANGE = re.compile(r"\b(?:weather|temperature|temp)\s+range\b")


def _weather_look_reading(s):
    out = {"_spans": []}
    for m in _WX_COLOR.finditer(s):
        out["color"] = True
        out["_spans"].append((m.start(), m.end()))
        if m.groupdict().get("r"):
            out["var"] = "prcp"
        elif m.groupdict().get("t") and not out.get("var"):
            out["var"] = "tmax"
    for rx in (_WX_LABEL, _WX_LABEL2):
        for m in rx.finditer(s):
            out["labels"] = True
            out["_spans"].append((m.start(), m.end()))
            g = m.groupdict()
            if (g.get("r") or g.get("r2")) and not out.get("var"):
                out["var"] = "prcp"
            elif (g.get("t") or g.get("t2")) and not out.get("var"):
                out["var"] = "tmax"
    if out.get("color"):
        for m in _WX_COLOR_TAIL.finditer(s):
            out["_spans"].append((m.start(), m.end()))
    for rx in (_WX_LINE, _WX_AVG, _WX_RANGE):
        for m in rx.finditer(s):
            out["_spans"].append((m.start(), m.end()))
    return out


def _merge_place_reading(terr, pl):
    if pl.get("split") and not terr.get("split"):
        terr["split"] = pl["split"]
    elif pl.get("split"):                              # "by fire-safe site by distance to the coast"
        terr.setdefault("_said", []).append(f"One split at a time: by {_SPLIT_SAID.get(terr['split'], terr['split'])} "
                                            f"(by {_SPLIT_SAID.get(pl['split'], pl['split'])} was left out)")
    for k, v in (pl.get("filters") or {}).items():
        terr.setdefault("filters", {})[k] = v
    terr["_spans"] = list(terr.get("_spans") or []) + pl["_spans"]
    terr["_said"] = list(terr.get("_said") or []) + pl["_said"]


def _territory_reading(s, names):
    """{split, filters, _said, _spans} from the region's site and council words. A named site or council counts
    only when the question also says the kind's own word (many town names are also site or council names, and
    "jobs in <a town>" must never become the site named after the town). A one-word name needs its own kind's
    word; a longer name either kind's."""
    from .. import region as R
    out = {"_spans": [], "_said": []}
    kinds = [("safety_site", "safety_sites", _site_words()), ("safety_council", "safety_councils", _council_words())]
    found = {}
    for split, fkey, rx in kinds:
        for m in rx.finditer(s):
            found.setdefault(split, []).append(m)
    if not found:
        return out
    named = {}
    order = kinds if "safety_council" not in found or "safety_site" in found else kinds[::-1]
    taken = []
    for split, fkey, _rx in order:
        for name in (names or {}).get(split) or []:
            for form in sorted(_name_forms(name), key=len, reverse=True):
                words = form.split()
                if len(form) < 4 or (len(words) < 2 and split not in found):
                    continue
                m = re.search(r"(?<![\w])" + re.escape(form) + r"(?![\w])", s)
                if not m:
                    continue
                if any(a <= m.start() < b and k != fkey for a, b, k in taken):
                    break                                  # these words already name the other kind
                named.setdefault(fkey, []).append(name)
                taken.append((m.start(), m.end(), fkey))
                out["_spans"].append((m.start(), m.end()))
                break
    if named:
        out["filters"] = {k: sorted(set(v)) for k, v in named.items()}
        for fkey, v in out["filters"].items():
            kind = R.current().label("safety_site" if fkey == "safety_sites" else "safety_council")
            out["_said"].append(f"{kind}{'s' if len(v) > 1 else ''}: {', '.join(v)}")
        for split, ms in found.items():
            out["_spans"] += [(m.start(), m.end()) for m in ms]
        return out
    for split, fkey, _rx in kinds:
        for m in found.get(split, []):
            out["_spans"].append((m.start(), m.end()))
            before = s[:m.start()]
            after = s[m.end():]
            if (_FILTER_BEFORE.search(before) and not _SPLIT_BEFORE.search(before)) or (
                    _NOUN_AFTER.match(after) and not _SPLIT_BEFORE.search(before)):   # "<site word> jobs"
                out.setdefault("filters", {})[fkey] = ["*"]
            else:
                out["split"] = out.get("split") or split
    return out
