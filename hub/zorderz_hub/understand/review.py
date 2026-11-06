"""The model's second look at a reading.

The spec-writer model then gets the question, how it was read and the chart's shape (no figures), and may think
briefly (at most three sentences, 450 tokens). It answers with one JSON object:

    {"verdict": "keep" | "change",
     "look":    {"chart_type": …, "orientation": …, "labels": …, "grain": …, "top_n": …},
     "reading": {any v2 field: periods, compare, measure, split, filters, …},
     "why":     "one short sentence"}

What the hub does with it:
  * `look` is applied: how the chart is drawn, and its time grain;
  * `reading` is applied only when the rules could not place every word (and never to a
    field the rules were sure of); otherwise it is kept in the log as a suggestion;
  * nothing is applied that fails spec2.check, and nothing waits past the time budget:
    the rules' chart is shown instead.
Each review is cached by question and reading, so the same question gets the same answer.
"""
import hashlib
import json
import re
import threading
import time
from collections import OrderedDict

from . import spec2 as S

LOOK_KEYS = ("chart_type", "orientation", "labels", "grain", "top_n")
READING_KEYS = ("measure", "periods", "compare", "to_date", "overlay", "months", "date_part", "split", "facet",
                "filters", "agg", "rank", "share", "series")
# 900 tokens took ~10.3 s at 88 tokens/s, longer than the 10 s limit, so a rambling reply was
# cut off by the clock and the person waited for nothing. 450 tokens
# (~5 s on 8-bit) is far more than any useful reply (median 66, longest useful 643 incl. thinking).
REVIEW_MAX_TOKENS = 450
SAMPLING = dict(temperature=0.0, top_p=1.0, top_k=1, min_p=0.0, repeat_penalty=1.0, max_tokens=REVIEW_MAX_TOKENS, seed=42)

# the 6-bit Qwen 9B gave no usable reply to 34 of 95 looks (the 35B: 2 of 100),
# most likely thinking past the token cap. LM Studio is told the reply's exact shape instead, so the model
# writes only this object: every reply parses, and there's no free text beyond a short reason.
# Only keywords LM Studio's engines accept: type, enum, properties,
# required, additionalProperties.
SCHEMA = {
    "name": "second_look",
    "strict": True,
    "schema": {
        "type": "object",
        "properties": {
            "verdict": {"type": "string", "enum": ["keep", "change"]},
            "look": {
                "type": "object",
                "properties": {
                    "chart_type": {"type": "string", "enum": [c for c in S.CHART_TYPES if c != "venn"]},
                    "orientation": {"type": "string", "enum": list(S.ORIENTATIONS)},
                    "labels": {"type": "string", "enum": list(S.LABELS)},
                    "grain": {"type": "string", "enum": list(S.GRAINS)},
                    "top_n": {"type": "integer", "enum": list(range(3, 16))},
                },
                "additionalProperties": False,
            },
            "reading": {"type": "object"},
            "why": {"type": "string"},
        },
        "required": ["verdict", "look", "reading", "why"],
        "additionalProperties": False,
    },
}
_NO_SCHEMA = set()          # models whose engine refused the schema once: plain text from then on
_UNSUPPORTED = ("uniqueItems", "pattern", "maxLength", "minimum", "maximum", "maxItems")
LIST_FILTERS = ("products", "work_types", "salespeople", "areas", "tags", "lead_sources", "original_sources")


def _strip(o):
    if isinstance(o, dict):
        return {k: _strip(v) for k, v in o.items() if k not in _UNSUPPORTED}
    if isinstance(o, list):
        return [_strip(x) for x in o]
    return o


def schema_for(pack):
    """SCHEMA with the reading's fields spelled out from the pack's own lists, so a
    model picks a value instead of guessing its form (run 14: "periods": "2012..2026" as one text,
    "agg": "revenue"). Only keywords every engine accepts."""
    import copy
    try:
        props = S.schema(pack)["schema"]["properties"]
    except Exception:
        return SCHEMA
    reading = {k: v for k, v in props.items() if k in READING_KEYS}
    out = copy.deepcopy(SCHEMA)
    out["schema"]["properties"]["reading"] = {"type": "object", "properties": _strip(reading),
                                              "additionalProperties": False}
    return out

_CACHE = OrderedDict()
_LOCK = threading.Lock()
_CACHE_MAX = 500


def system_prompt(pack):
    tags = "; ".join(f"{g['name']}: {', '.join(g['tags'][:12])}" for g in pack.tag_groups)
    return f"""You check how Charts read a sales question about {pack.company_name or 'a small business'} and
how it will draw the answer. Charts' rules already read the question and ran the numbers. Look
again and say whether a different chart, or a more precise reading, would answer the person better.
Decide in at most three short sentences. Keep what they clearly said. Prefer the simplest chart that
answers. When in doubt, keep.

Chart choices: big number for one value; columns for 2–4 periods compared; one line per year over
Jan–Dec for years by month; line for 13+ months/weeks/days; columns for years and short series;
columns in calendar order for weekday/season/month-of-year; sorted horizontal bars for rankings and
for 5+ groups in one period; pie only for 2–3 parts of a whole; 100% bars for shares over time;
stacked columns for parts over time; side-by-side (grouped) bars or lines when named things are
compared; a map only when the person asks for one and the chart is by town (a map is drawn by ZIP and
has its own period buttons, so keep a map the person asked for). Never a pie of averages
or customers.

The reading uses these fields (only the ones that change):
measure: revenue|jobs|customers|units|avg_job · periods: tokens like "2025", "2025-q3", "2025-h1",
"2025-09", "2025-w14", "2025-summer", "ytd", "last_12m", "last_90d", "2018..now", "all" ·
compare: prior_year|prior_period|growth · overlay: true (years over one calendar) · months: [1–12] ·
date_part: month_of_year|quarter_of_year|week_of_year|day_of_week|season · split: {', '.join(pack.splits)} ·
filters: products, work_types, salespeople, areas, tags, lead_sources (+ exclude_… of each) ·
agg: avg_per_period|per_job|jobs_per_customer|job_range · rank: {{"dir":"top"|"bottom","n":1}}.
Products: {', '.join(pack.products)}.
Work types: {', '.join(pack.work_types)}.
Salespeople: {', '.join(pack.salespeople)}.
Details (tags): {tags}.

Look: chart_type (auto|grouped_bar|stacked_bar|percent_bar|line|area|pie|donut|single_number|table|map),
orientation (vertical|horizontal), labels (none|value|percent|value_percent), grain
(none|year|quarter|month|week|day), top_n (3–15).

Keep a chart type the person named ("stacked", "pie", "a section on the bar", "on a map").
Charts already keeps every bar for a ranking ("best month", "busiest week") and names the top one,
and it marks a partial last period itself; a partial last year or quarter is fine on any chart. Don't
change a chart only because of these.
Answer with ONE JSON object and nothing after it:
{{"verdict":"keep"|"change","look":{{…}},"reading":{{…}},"why":"one short sentence"}}
Use "keep" with empty look and reading when the chart is already right. Put in the reading only the
fields you change, and only for words in the question: agg only when it asks for an average, per job or a
range; an exclude_ filter only when it says not, except or without; "lately" means the last year or two. Never add names of people
or customers. Never invent a product, town or detail that isn't in the lists."""


def payload(question, reading, answer, chosen):
    """What the model sees: the question, the reading and the chart's shape. No figures."""
    r = answer.result
    periods = list(r.get("periods") or [])
    groups = [g for g in (r.get("groups") or []) if g != "All jobs"]
    shape = {
        "kind": answer.kind,
        "last_period_partial": bool(answer.extra.get("partial_period")),
        "ranking_asked": bool(reading.spec.get("rank")),
        "chart": chosen,
        "periods": len(periods),
        "first_period": periods[0] if periods else None,
        "last_period": periods[-1] if periods else None,
        "groups": groups[:15],
        "empty": bool(r.get("empty")),
    }
    return (f"QUESTION: {question}\n"
            f"READ AS: {json.dumps(S.sparse(reading.spec), ensure_ascii=False)}\n"
            f"WORDS NOT PLACED: {', '.join(w for w in reading.unread if w[:1].islower()) or 'none'}\n"
            f"CHART: {json.dumps(shape, ensure_ascii=False)}\n"
            "Decide in at most three short sentences, then the JSON object.")


def _key(text):
    return hashlib.sha256(text.encode()).hexdigest()


def _parse(text):
    """The last JSON object in the reply (a thinking model may write before it)."""
    text = re.sub(r"<think>.*?</think>", "", text or "", flags=re.S)
    text = re.sub(r"```(?:json)?", "", text)
    ends = [m.start() for m in re.finditer(r"\}", text)]
    for end in reversed(ends):
        depth = 0
        for i in range(end, -1, -1):
            if text[i] == "}":
                depth += 1
            elif text[i] == "{":
                depth -= 1
                if depth == 0:
                    try:
                        obj = json.loads(text[i:end + 1])
                    except ValueError:
                        break
                    if isinstance(obj, dict) and "verdict" in obj:
                        return obj
                    break
    return None


def clean(obj):
    """Only the fields Charts knows, with values of the right kind."""
    if not isinstance(obj, dict):
        return None
    look = {}
    for k, v in (obj.get("look") or {}).items() if isinstance(obj.get("look"), dict) else []:
        if k == "chart_type" and v in S.CHART_TYPES and v not in ("venn",):
            look[k] = v
        elif k == "orientation" and v in S.ORIENTATIONS:
            look[k] = v
        elif k == "labels" and v in S.LABELS:
            look[k] = v
        elif k == "grain" and v in S.GRAINS:
            look[k] = v
        elif k == "top_n" and isinstance(v, int) and 3 <= v <= 15:
            look[k] = v
    reading = {}
    raw = obj.get("reading") if isinstance(obj.get("reading"), dict) else {}
    for k, v in raw.items():
        # common slips tidied, not fatal: null / empty = "no change"; one period
        # or month written alone instead of in a list; a single filter value instead of a list
        if k not in READING_KEYS or v is None or v == "" or v == [] or v == {}:
            continue
        if k in ("periods", "months") and not isinstance(v, list):
            v = [v]
        if k == "filters" and isinstance(v, dict):
            v = {fk: (fv if isinstance(fv, list) or not any(fk.endswith(x) for x in LIST_FILTERS) else [fv])
                 for fk, fv in v.items() if fv not in (None, "", [])}
            if not v:
                continue
        reading[k] = v
    verdict = obj.get("verdict") if obj.get("verdict") in ("keep", "change") else "keep"
    why = re.sub(r"\s+", " ", str(obj.get("why") or ""))[:200]
    if verdict == "keep" and (look or reading):
        # "keep … the chart is already right" with a look attached
        # changed the chart 18 times in 56. A keep keeps; what it wrote is only logged.
        return {"verdict": verdict, "look": {}, "reading": {}, "why": why, "said": {"look": look, "reading": reading}}
    return {"verdict": verdict, "look": look, "reading": reading, "why": why}


def review(lm, model, system, user, seconds, slot=None, structured=True, max_tokens=None, no_think=False,
           info=None, schema=None):
    """. Never waits past `seconds`. `structured` sends the reply's shape (SCHEMA); `max_tokens` overrides the
    cap; `no_think` adds Qwen's "/no_think" switch; `info` (a dict) is filled with the reply's finish, tokens
    and format. Reasons are short codes (no_json, cut_off, empty_reply, timeout, unreachable, model_error,
    model_busy, no_time), so the hub computer's log says why a look failed."""
    info = info if isinstance(info, dict) else {}
    if no_think and "/no_think" not in user:
        user = user + "\n/no_think"
    k = _key(model + "\0" + user)
    with _LOCK:
        if k in _CACHE:
            _CACHE.move_to_end(k)
            return _CACHE[k], "cached", 0
    started = time.time()
    got = True
    if slot is not None:
        got = slot.acquire(timeout=min(2.0, seconds / 4))
        if not got:
            return None, "model_busy", int((time.time() - started) * 1000)
    sampling = dict(SAMPLING)
    if max_tokens:
        sampling["max_tokens"] = int(max_tokens)
    use_schema = bool(structured) and model not in _NO_SCHEMA
    info["format"] = "schema" if use_schema else "text"
    try:
        left = seconds - (time.time() - started)
        if left < 2:
            return None, "no_time", int((time.time() - started) * 1000)
        try:
            r = lm.chat_full(model, system, user, sampling, (schema or SCHEMA) if use_schema else None, timeout=left,
                             tool_calls=True)
        except Exception as e:
            # an engine that refuses the schema answers "http 400": once, then plain text for this model
            if use_schema and "400" in str(e) and seconds - (time.time() - started) >= 2:
                info["format"] = "text (schema refused)"
                r = lm.chat_full(model, system, user, sampling, None,
                                 timeout=seconds - (time.time() - started), tool_calls=True)
                # remembered only when the plain reply works, so an unrelated 400 (a
                # context overflow) doesn't switch the schema off for the life of the process
                _NO_SCHEMA.add(model)
            else:
                raise
    except Exception as e:                      # timeout, model unloaded, bad reply
        msg = str(e).lower()
        code = "timeout" if ("timed out" in msg or isinstance(e, TimeoutError)) else \
            ("unreachable" if "unreachable" in msg else "model_error")
        return None, code, int((time.time() - started) * 1000)
    finally:
        if slot is not None and got:
            slot.release()
    text = r.get("text") or ""
    info.update(finish=r.get("finish", ""), tokens=r.get("tokens"), thought=bool(r.get("thought")))
    if r.get("from_tool_call"):
        info["format"] = info.get("format", "text") + " (from a tool call)"
    out = clean(_parse(text))
    ms = int((time.time() - started) * 1000)
    if out is None:
        if r.get("finish") == "length":
            return None, "cut_off", ms
        return None, ("empty_reply" if not text.strip() else "no_json"), ms
    with _LOCK:
        _CACHE[k] = out
        while len(_CACHE) > _CACHE_MAX:
            _CACHE.popitem(last=False)
    return out, "", ms


def merge_checked(spec, rev, reading, partial, answer, ok, terms=None):
    """The look first; then each reading field on its own, kept only if the spec still checks. Returns (new
    spec, applied, suggested, dropped)."""
    out, applied, suggested = merge(spec, {**rev, "reading": {}}, reading, partial, answer, terms)
    if applied and not ok(out):
        return spec, [], suggested + applied, list(applied)
    applied, dropped = list(applied), []
    for k, v in (rev.get("reading") or {}).items():
        trial, a, sug = merge(out, {**rev, "look": {}, "reading": {k: v}}, reading, partial, answer, terms)
        if a and ok(trial):
            out = trial
            applied += [x for x in a if x not in applied]
        elif a:
            dropped += [x for x in a if x not in dropped]
        suggested += [x for x in sug if x not in suggested]
    return out, applied, suggested + [d for d in dropped if d not in suggested], dropped


# A reading field from the model now needs words in the question that call for it. Fields without an entry
# here (periods inside the asked year, months, date_part, grain, inclusion filters) are what partly read words
# usually mean ("holidays", "fire season"), so they pass as before.
_W = r"(?<![\w])(?:{})(?![\w])"
_ANCHOR = {
    "agg": {
        "per_job": r"average|avg|mean|per job|each job|a job|per install|typical|typically|usual|usually|job size",
        "avg_per_period": r"average|avg|mean|per month|per year|per week|per quarter|monthly average|a month|a year|typical",
        "jobs_per_customer": r"per customer|each customer|a customer|jobs does|jobs do",
        "job_range": r"highest|lowest|biggest|smallest|largest|range|min|max",
    },
    "rank": r"top|best|most|least|worst|biggest|largest|highest|lowest|slow|slowest|busiest|leading|fewest|ranked|rank",
    "compare": r"vs|v|versus|compare|compared|comparing|than|growth|grew|grow|growing|change|changed|against|"
               r"same time|same point|year over year|year-over-year|yoy",
    "overlay": r"side by side|each year|every year|year over year|year-over-year|yoy|overlay|on top of",
    "share": r"share|percent|percentage|pct|%|portion|proportion|mix|split of",
    "split": r"by|per|each|split|broken down|break down|breakdown|across|which|who|where",
    "measure": {
        "revenue": r"revenue|sales|sold|sell|made|make|bring|brought|billed|bill|money|earn|earned|income|dollars|worth|\$",
        "jobs": r"jobs?|installs?|installations?|visits?|orders?|how many|count|number of",
        "units": r"units?|items?|panels?|pieces|how many|count|number of",
        "customers": r"customers?|clients?|households|homes|houses|people",
        "avg_job": r"average|avg|typical|job size|mean",
    },
}
_NEGATION = (r"not|no|without|except|excluding|exclude|excludes|excl|other than|non|besides|minus|apart from|"
             r"aside from|leave out|leaving out|skip|skipping|but not|w/o|"
             r"(?:everything|everyone|all|anything|any) but")          # "everything but Northstar"
_NT = re.compile(r"\w(?:n't|n’t)(?![\w])", re.I)     # "don't", "doesn’t": the boundary rule can't see these
_RECENT = r"lately|recently|recent|these days|nowadays|right now|currently"


_GRAIN_WORDS = {
    "year": r"year|years|yearly|annual|annually|per year|by year",
    "quarter": r"quarter|quarters|quarterly|q1|q2|q3|q4",
    "month": r"month|months|monthly|holidays|holiday|season|seasons|summer|winter|spring|fall",
    "week": r"week|weeks|weekly",
    "day": r"day|days|daily",
}
_TREND = r"trend|trends|trending|over time|lately|recently|recent|growth|grew|history"
TIME_TYPES = ("line", "area", "grouped_bar", "stacked_bar", "percent_bar")


def grain_anchored(grain, question):
    """a look that moves a chart onto a time grain changes the question ("revenue by town
    in 2025" by month, stacked, top 5), so the question's words have to call for that grain or a trend."""
    if grain in (None, "", "none"):
        return True
    return _says(question, _GRAIN_WORDS.get(grain, r"(?!x)x")) or _says(question, _TREND)


def _says(question, pattern):
    q = question or ""
    if callable(pattern):
        return bool(pattern(q))                  # a job size is judged by the reader's own words
    if ("\\$" in pattern and "$" in q) or ("%" in pattern and "%" in q):
        return True                          # "$5,000", "10%": symbols sit next to digits
    return bool(re.search(_W.format(pattern), q, re.I))


def anchored(key, value, question):
    """may the second look's reading field `key` = `value` apply to this question?"""
    q = (question or "").lower()
    rule = _ANCHOR.get(key)
    if key == "periods":
        years = [int(y) for y in re.findall(r"(?<!\d)(20\d\d|19\d\d)(?!\d)", json.dumps(value))]
        if _says(q, _RECENT) and years and min(years) < time.localtime().tm_year - 2:
            return False                     # "lately" is not every year since 2012
        named = [int(y) for y in re.findall(r"(?<!\d)(20\d\d)(?!\d)", q)]
        ranged = _says(q, r"since|between|through|thru|until") or re.search(r"(?:from|to)\s+(?:19|20)\d\d", q)
        if named and years and not set(named) & set(years) and not ranged:
            return False                     # the question named a year the model's periods leave out
        return True
    if rule is None:
        return True
    if isinstance(rule, dict):
        pat = rule.get(value if isinstance(value, str) else "")
        return True if pat is None else _says(q, pat)
    if value in (None, False, "", [], {}):
        return True
    return _says(q, rule)


_TOKEN = re.compile(r"[\w$%/'’.-]+|,|;")
_CLAUSE = {"in", "at", "for", "by", "from", "during", "near", "around", "over", "since", "across", "within", "this",
           "last", "per", "each"}
# a reply's "No, …" only with the punctuation after it: "No Cedar Hills jobs" excludes Cedar Hills.
_LEADING_NO = re.compile(r"^\s*(?:nope\b[\s,.!:;-]*|(?:no|not quite|not that|not really)\s*[,.!:;-]+\s*)", re.I)
_JOINERS = {",", "and", "or", "nor", "&", "/", "plus"}
_NOT_A_NEGATION = re.compile(r"\b(?:not sure|not quite sure|no idea|no worries|not bad)\b", re.I)
NEAR = 6                                   # words before the excluded thing that may hold the negation


def _negates(text):
    return _says(text, _NEGATION) or bool(_NT.search(text or "")) or "w/o" in (text or "").lower()


def _scope(tokens, i, listed=frozenset()):
    """The words before tokens[i] that a negation could still govern: at most NEAR words, and not back past a
    comma that opens a new clause ("…, not the repairs, in Cedar Hills": the "not" is about the repairs).
    a list carries its negation to the end. The other values of the same exclusion (`listed`, token
    indexes) and the words that join them don't count toward NEAR, so "excluding Cedar Hills, Maple Falls, Pine Ridge, Millbrook,
    Fairview and Brookfield" excludes Brookfield too."""
    out, j, counted = [], i - 1, 0
    while j >= 0 and counted < NEAR:
        t = tokens[j]
        if t in (",", ";"):
            nxt = tokens[j + 1] if j + 1 < len(tokens) else ""
            if t == ";" or nxt in _CLAUSE:
                break
            # ", and …" starts a new clause unless it ends a list ("Cedar Hills, Maple Falls, and Pine Ridge")
            before = j - 1
            in_list = before in listed or (before > 0 and tokens[before - 1] in _JOINERS)
            if nxt in ("and", "or", "but") and not in_list:
                break
        else:
            out.insert(0, t)
            item = 0 < j < len(tokens) - 1 and tokens[j - 1] in _JOINERS and tokens[j + 1] in _JOINERS
            if j not in listed and t not in _JOINERS and not item:
                counted += 1
        j -= 1
    return " ".join(out)


def _span(tokens, value):
    """(index, length) of the first place `value` (one or more words) is named, or (-1, 0)."""
    want = [w for w in _TOKEN.findall(str(value).lower()) if w not in (",", ";")]
    if not want:
        return -1, 0
    for i in range(len(tokens) - len(want) + 1):
        if all(tokens[i + j].startswith(want[j]) or want[j].startswith(tokens[i + j]) and len(tokens[i + j]) >= 4
               for j in range(len(want))):
            return i, len(want)
    return -1, 0


def _mentions(tokens, value):
    """Index of the first token where `value` (one or more words) starts, or -1."""
    return _span(tokens, value)[0]


def anchored_filters(filters, question, said=frozenset(), have=None):
    """an exclusion needs a word that excludes ("in the mountains" is not "except the mountains").
    the word must also be near what it excludes. When the question
    names an excluded value, a negation has to sit in the few words before it ("Cedar Hills and Maple Falls, not Pine Ridge"
    excludes Pine Ridge only); when it names none (the model turned "the mountains" into towns), any negation counts
    except a reply's leading "No, …"."""
    q = _NOT_A_NEGATION.sub(" ", _LEADING_NO.sub("", question or "", count=1))
    tokens = _TOKEN.findall(q.lower())
    keep, held = {}, []
    for k, v in filters.items():
        if not str(k).startswith("exclude_"):
            # an added value has to be something the question says. "big jobs over $5,000"
            # became "New / install" only; the look may fill a gap the words leave, not invent a filter.
            if have and k in have:            # the rules' own filter: not the look's to answer for
                keep[k] = v
                continue
            if isinstance(v, list) and k in _GROUNDED_KEYS:
                ok = [x for x in v if grounded(x, question, said)]
                if len(ok) != len(v):
                    held.append(k)
                if ok:
                    keep[k] = ok
                continue
            if k in _GROUNDED_SCALARS and v not in (None, "", False, "any", "all") and \
                    not _says(question, _GROUNDED_SCALARS[k]):
                held.append(k)
                continue
            keep[k] = v
            continue
        values = v if isinstance(v, list) else [v]
        spans = [(_span(tokens, x), x) for x in values]
        named = [(i, x) for (i, _n), x in spans if i >= 0]
        listed = frozenset(i + d for (i, n), _x in spans if i >= 0 for d in range(n))
        if named:
            # all or nothing. A look that excludes something the question plainly includes ("Cedar Hills and
            # Maple Falls, not Pine Ridge" → exclude Pine Ridge and Cedar Hills) has misread it, so none of that key is trusted;
            # the rules' own reading stands.
            if all(_negates(_scope(tokens, i, listed)) for i, _x in named):
                keep[k] = v
            else:
                held.append(k)
        elif _negates(q):
            keep[k] = v
        else:
            held.append(k)
    return keep, held


def _vet_look(look, spec, answer):
    """the 9B changed the hub's chart 21 times, sometimes against the hub's own rules ("Top 5 products" as a
    stacked bar, rankings as upright columns)."""
    from . import looks as L
    look, refused = dict(look), []
    r = answer.result if isinstance(answer.result, dict) else {}
    for k in ("orientation", "labels"):
        if k in look and look[k] != spec.get(k):
            refused.append(k)
        look.pop(k, None)
    groups = [g for g in (r.get("groups") or []) if g != "All jobs"]
    if "top_n" in look:
        n = look["top_n"]
        if not ((len(groups) > 10 or "All other" in groups) and isinstance(n, int) and n < len(groups)):
            refused.append("top_n")
            look.pop("top_n")
    # the 9B sends "grain": "none" with almost every look. Only a grain that differs from the
    # one that ran changes the answer's shape; an unchanged one is dropped so it can't wave the type through.
    if "grain" in look and look["grain"] == (spec.get("grain") or "none"):
        look.pop("grain")
    ct = look.get("chart_type")
    if ct and ct != spec.get("chart_type") and "grain" in look and ct not in TIME_TYPES:
        refused.append("chart_type")        # onto a time grain, only a chart that draws time
        look.pop("chart_type")
        ct = None
    if ct and ct != spec.get("chart_type") and "grain" not in look:
        scores = None
        try:
            from . import charttype as CT
            if spec.get("chart_type") in (None, "", "auto") and ct == CT.choose(answer)[0]:
                look.pop("chart_type")      # the hub's own choice: nothing to change (and no "model" boost)
                return look, refused
            v1 = answer.v1 or {}
            sh = L.shape(v1, r, answer.kind, answer.spec)
            scores = L._scores(sh, r, answer.kind, v1)
            best = max([v for (t, _o), v in scores.items() if t == ct] or [0])
        except Exception:
            best = 0                        # can't tell, so the hub's chart stays
        if best < 45:
            refused.append("chart_type")
            look.pop("chart_type")
        elif ct == "grouped_bar" and scores and not spec.get("orientation"):
            # a type set outright would default to upright bars; keep the direction the hub rates best
            look["orientation"] = max(((v, o) for (t, o), v in scores.items() if t == ct and o),
                                      default=(0, "vertical"))[1]
    return look, refused


def guard_look(look, spec, answer):
    """Looks that would hide the answer are refused. Returns (allowed look, refused keys)."""
    look = dict(look)
    refused = []
    r = answer.result if answer is not None else {}
    n_periods = len(r.get("periods") or [])
    n_groups = len([g for g in (r.get("groups") or []) if g != "All jobs"])
    asked_grain = spec.get("grain") or "none"
    shaped = spec.get("rank") or spec.get("date_part") or spec.get("overlay") or spec.get("compare") or \
        spec.get("agg") or spec.get("months") or spec.get("to_date_cut")
    if "grain" in look and (shaped or (asked_grain != "none" and look["grain"] == "none")
                            or "grain" in getattr(answer, "locked", ())):
        refused.append("grain")
        look.pop("grain")
    if answer is not None and look.get("chart_type") in ("line", "area") and n_periods <= 1 and "grain" not in look:
        refused.append("chart_type"); look.pop("chart_type")       # one period has no line to draw
    if look.get("chart_type") in ("single_number",) and (n_periods > 1 or n_groups > 1):
        refused.append("chart_type")
        look.pop("chart_type")
    partial_short = answer is not None and answer.extra.get("partial_period") and \
        (spec.get("grain") in ("month", "week", "day") or look.get("grain") in ("month", "week", "day"))
    if look.get("chart_type") in ("line", "area") and partial_short:
        refused.append("chart_type")            # a few days drawn as a cliff at the end of a line
        look.pop("chart_type")
    named = spec.get("chart_type") not in (None, "", "auto")
    if named and look.get("chart_type") not in (None, spec.get("chart_type")):
        # a map the person asked for stays a map (live: "by zip by year" became town bars).
        # so does any chart they named (live Q51/Q52: "each neighbor is a section on the bar"
        # was read as stacked, and the look made it side-by-side bars).
        refused.append("chart_type")
        look.pop("chart_type")
        if spec.get("chart_type") == "map":
            look.pop("top_n", None)
    if spec.get("orientation") and look.get("orientation") not in (None, spec.get("orientation")):
        refused.append("orientation")           # a direction the person named stays
        look.pop("orientation")
    if spec.get("chart_type") in (None, "", "auto") and n_periods <= 1 and n_groups <= 1 and \
            look.get("chart_type") not in (None, "single_number", "table"):
        refused.append("chart_type")            # one value stays one number (live: a one-bar chart)
        look.pop("chart_type")
    if look.get("chart_type") == "map" and (spec.get("split") != "area" or spec.get("chart_type") != "map"):
        refused.append("chart_type")            # a map only for towns, and only when asked for
        look.pop("chart_type")
    if look.get("chart_type") in ("pie", "donut", "waffle") and (n_groups > 3 or n_groups < 2):
        refused.append("chart_type")
        look.pop("chart_type")
    if answer is not None:
        look, more = _vet_look(look, spec, answer)
        refused += [k for k in more if k not in refused]
    return look, refused


def merge(spec, rev, reading, partial, answer=None, terms=None):
    """The spec with the review applied by the business's rule: look always (unless it would hide the answer);
    reading only for a partly read question, never over a field the rules were sure of. Returns (new spec,
    applied fields, suggested-only fields)."""
    out = dict(spec)
    applied, suggested = [], []
    question = getattr(reading, "question", "") or ""
    for a, b in (getattr(reading, "corrections", None) or [])[:6]:      # "avrage" read as "average"
        question = question.replace(str(a), str(b))
    raw_look = dict(rev["look"])
    if "grain" in raw_look and raw_look["grain"] != (spec.get("grain") or "none") \
            and not grain_anchored(raw_look["grain"], question):
        raw_look.pop("grain")
        suggested.append("grain")
    look, refused = guard_look(raw_look, spec, answer)
    suggested += refused
    for k, v in look.items():
        if out.get(k) != v:
            out[k] = v
            applied.append(k)
    for k, v in rev["reading"].items():
        if out.get(k) == v:
            continue
        if k == "filters" and isinstance(v, dict):
            # a model never names a partner or an account (they reach it masked)
            v = {fk: fv for fk, fv in v.items() if fk not in ("partners", "accounts", "customers",
                                                              "exclude_partners")}
            have = out.get("filters") or {}
            v, held = anchored_filters(v, question, said_values(question, terms), have)   
            if held:
                suggested.append("filters")
            new = {fk: fv for fk, fv in v.items() if fk not in have}
            if partial and new:                 # it may add what the rules missed, never replace
                out["filters"] = {**have, **new}
                applied.append("filters")
            elif {fk: fv for fk, fv in v.items() if have.get(fk) != fv}:
                suggested.append("filters")
            continue
        if partial and k not in reading.locked and anchored(k, v, question):
            out[k] = v
            applied.append(k)
        else:
            suggested.append(k)
    return out, applied, suggested


# When the rules can't check their own reading, the model writes the whole chart, and a model left alone can fill
# every filter list with every value and add filters nobody asked for (an excluded lead source, a tag, a customer
# type). The same rules as a look therefore apply: an exclusion needs a negation near it, and a value the filter
# keeps has to be something the question says (its own words, or a word the pack's terms map to it).
_GENERIC = {"unit", "units", "item", "items", "type", "stated", "work", "other", "mixed", "item", "items",
            "jobs", "job", "new", "add", "and", "the", "with", "fees", "charges", "calls", "service", "parts",
            "county", "north", "south", "east", "west", "central", "valley", "beach", "lake",
            "san", "santa", "springs", "customer", "customers", "from", "history", "recorded", "unknown"}
_GROUNDED_KEYS = ("products", "work_types", "tags", "areas", "regions", "lead_sources", "salespeople")
_GROUNDED_SCALARS = {
    "customer_type": r"home|homes|homeowners?|residential|residents?|houses?|businesses|business customers|"
                     r"business clients|commercial|compan(?:y|ies)|landlords?|hoas?",
    "repeat_customers_only": r"repeat|returning|existing customers?|came back|come back|loyal",
    "payment": r"paid|unpaid|outstanding|owed|owing|overdue",
    # a job size needs an amount on the jobs in the question (not any "$": "months with revenue over
    # $100,000" is no job size), so the reader's own size words decide
    "job_min": lambda q: _job_size_said(q),
    "job_max": lambda q: _job_size_said(q),
}


def _job_size_said(question):
    from .read import job_size
    return job_size(question) is not None              # the reader reads size follow-ups itself


def said_values(question, terms):
    """Every filter value the pack's terms find in the question (case-folded): "shade-sail" → shade-sail, "2nd story" →
    the ladder tag, "google ads" → Google Ads, a town or region by any of its names."""
    out = set()
    if terms is None:
        return out
    from .text import prepare
    try:
        prep = prepare(question, terms.known_words, terms.never_correct)
        hits = terms.match(prep)
    except Exception:                       # a guard never takes an answer down
        return out
    for h in hits:
        m = h.meaning
        for v in [m.value] + [x for _k, x in m.data]:
            for x in (v if isinstance(v, (list, tuple)) else [v]):
                if isinstance(x, str) and x:
                    out.add(x.casefold())
    return out


def grounded(value, question, said=frozenset()):
    """Does the question name this filter value, in its own words or the pack's?"""
    v = str(value or "")
    if not v:
        return False
    if v.casefold() in said:
        return True
    words = [w for w in re.findall(r"[a-z0-9]+", v.lower()) if len(w) >= 4 and w not in _GENERIC]
    if not words and not re.fullmatch(r"[A-Z]{2,4}", v):
        return False                 # "Other", "Units (type not stated)": only the pack's words can name these
    tokens = _TOKEN.findall((question or "").lower())
    if _span(tokens, v)[0] >= 0:
        return True
    w = words[0] if words else ""
    return bool(w) and any(t.startswith(w) or (len(t) >= 5 and w.startswith(t)) for t in tokens)


# An exclusion the question implies without a negation: "new customers" leaves out Existing Customer.
_EXCL_IMPLIED = {"exclude_lead_sources": r"new customers?|new clients?|first[- ]time|non[- ]repeat|never been"}


def vet_written(spec, question, terms=None, prev=None, defaults=None):
    """a chart the model wrote whole keeps only the filters the question asks for -- or that the chart on
    screen already had (a follow-up, "now by month", keeps the earlier filters). `defaults` are the empty filter
    values (compute.base_spec). Returns (spec, dropped: list of filter keys taken out or cut down)."""
    f = dict(spec.get("filters") or {})
    if not f:
        return spec, []
    pf = dict((prev or {}).get("filters") or {})
    said = said_values(question, terms)
    defaults = defaults or {}
    dropped = []

    def reset(k):
        if k in defaults:
            f[k] = defaults[k] if not isinstance(defaults[k], list) else []
        elif isinstance(f.get(k), list):
            f[k] = []
        else:
            f.pop(k, None)

    excl = {k: v for k, v in f.items() if str(k).startswith("exclude_") and v and v != pf.get(k)}
    if excl:
        _keep, held = anchored_filters(excl, question)
        for k in held:
            if k in _EXCL_IMPLIED and _says(question, _EXCL_IMPLIED[k]):
                continue
            reset(k)
            dropped.append(k)
    for k in _GROUNDED_KEYS:
        vals = f.get(k)
        if not isinstance(vals, list) or not vals:
            continue
        before = set(pf.get(k) or [])
        keep = [x for x in vals if x in before or grounded(x, question, said)]
        if len(keep) != len(vals):
            f[k] = keep
            dropped.append(k)
    for k, pat in _GROUNDED_SCALARS.items():
        v = f.get(k)
        if v not in (None, "", False, "any", "all") and v != pf.get(k) and not _says(question, pat):
            reset(k)
            dropped.append(k)
    out = dict(spec)
    out["filters"] = f
    return out, dropped
