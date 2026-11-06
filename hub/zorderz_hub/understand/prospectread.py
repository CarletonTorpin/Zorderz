"""Prospecting questions read by rule. The product words are the pack's (rules.yml
charts.prospect.products.*.words), the place names the pack's ZIP areas and regions.

"where should Alex hang chimney-cap hangers near Cedar Hills this week, not where we've been lately, sunny"
    -> {"product": "chimney-cap", "near": "Cedar Hills", "exclude": {"hung_within_days": 90},
        "ingredients": {"inland": 1.0}}   (the product's own weights stay; words only raise or add)

read() returns None for anything that isn't a prospecting question, so every other question goes on to the chart
reader exactly as before. A prospecting question is one that asks WHERE to hang, canvass or knock -- or names
door hangers with "where"/"which neighborhoods" -- not "how many door hangers did we hang in Cedar Hills" (a count the
chat answers from Doorhangers itself).
"""
import re

_WORDNUM = {"one": 1, "two": 2, "three": 3, "four": 4, "five": 5, "six": 6, "seven": 7, "eight": 8, "nine": 9,
            "ten": 10, "twelve": 12, "fifteen": 15, "twenty": 20, "a few": 3, "a couple of": 2, "a couple": 2}

_HANG = r"(?:hang|hanging|hung|drop|dropping|leave|leaving|put\s+out|canvass|canvassing|knock(?:ing)?\s+(?:on\s+)?doors?|" \
        r"door[\s-]?knock(?:ing)?|prospect(?:ing)?|flyer|flyering|flier)"
_HANGERS = r"(?:door[\s-]?hangers?|doorhangers?|hangers?|flyers?|fliers?|leaflets?)"
_WHERE = r"(?:where|which\s+(?:neighbou?rhoods?|areas?|streets?|parts?)|what\s+(?:neighbou?rhoods?|areas?|streets?)|" \
         r"best\s+(?:neighbou?rhoods?|areas?|streets?|places?)|good\s+(?:neighbou?rhoods?|areas?|streets?|places?)|" \
         r"neighbou?rhoods?\s+(?:to|for)|suggest|recommend|options?\s+for)"
_COUNTING = re.compile(r"\b(?:how\s+many|count|number\s+of|total|when\s+did|did\s+we|have\s+we|last\s+time)\b", re.I)
# Questions about how past hangers did, or where the stock is, are the chat's (Doorhangers' log, the stock list) --
# not "where next". A forward-looking word ("should", "next", "this week", "best areas to") keeps a question here.
_RESULTS = re.compile(
    r"\b(?:leads?|responses?|response\s+rate|results?|conver(?:t|ts|ted|sion|sions)|"
    r"work(?:ed|s)?\s+(?:best|well|out)|perform(?:ed|s|ance)?|"
    r"(?:got|had|get|gets|gotten|brought|bring)\s+(?:us\s+)?(?:the\s+)?(?:most|more|any|fewest|least|best)|"
    r"(?:come|comes|came)\s+from|inventory|stock|supply|supplies|boxes|"
    # "where are the door hanger customers / is the hanger inventory" -- not "where are the sunniest areas to hang"
    r"where\s+(?:are|is|were|was)\s+(?:the|our|my)\s+(?:[\w-]+\s+){0,2}"
    r"(?:door[\s-]?hangers?|hangers?|flyers?|fliers?|inventory|stock|boxes|customers|leads)|"
    r"did\s+(?:the|our|they|it|we|those|these))\b", re.I)
_FORWARD = re.compile(
    r"\b(?:should|shall|would|could|ought|next|this\s+(?:week|weekend|morning|afternoon|saturday|sunday|month)|"
    r"tomorrow|today|tonight|upcoming|plan(?:ning)?|suggest|recommend|where\s+to|let'?s|"
    r"can\s+(?:i|we|he|she|they)|(?:best|good|top|better)\s+(?:neighbou?rhoods?|areas?|streets?|places?|spots?)"
    r"\s+(?:to|for)|(?:neighbou?rhoods?|areas?|streets?|places?)\s+(?:to|for)\s+(?:hang|knock|canvass|drop|door|flyer)|"
    r"options?\s+for|go\s+(?:hang|knock|canvass|door))\b", re.I)

TRIGGER = re.compile(
    rf"\b{_WHERE}\b.*\b(?:{_HANG}|{_HANGERS})\b|\b(?:{_HANG})\b.*\b{_WHERE}\b|"
    rf"\b(?:{_HANGERS})\b.*\b(?:neighbou?rhoods?|areas?)\b.*\b(?:to|for|best|good|should|would)\b|"
    rf"\b(?:{_HANGERS})\s+(?:near|around|close\s+to|by)\b|"
    rf"\bprospecting\b|\bcanvass(?:ing)?\b.*\b(?:where|neighbou?rhoods?|areas?)\b", re.I)

# words that raise an ingredient: (pattern, {ingredient: weight})
RAISE = [
    (r"\b(?:sunny|sunniest|hot(?:test)?|inland|heat|warm(?:est)?|desert|away\s+from\s+the\s+coast)\b", {"inland": 1.0}),
    (r"\b(?:fire(?:\s*risk|\s*zones?|\s*danger|\s*hazard)?|wildfires?|burn(?:ed|t)?\s*(?:areas?|before)?|high\s+hazard)\b",
     {"fire_zone": 1.0, "burned_before": 0.7}),
    (r"\b(?:fire[- ]?safe|safety)\s+(?:sites?|areas?|communit(?:y|ies))\b", {"safety_area": 1.0}),
    (r"\b(?:big(?:ger)?|large(?:r)?|huge|acre(?:s|age)?|spacious)\s+(?:lots?|yards?|propert(?:y|ies)|parcels?)\b|"
     r"\bacreage\b|\bacre\s+lots?\b", {"lot_size": 1.0}),
    (r"\b(?:few(?:er)?|no|not\s+many|without)\s+(?:existing\s+|current\s+)?customers?\b|\bnew\s+(?:territory|areas?|ground)\b|"
     r"\bhaven'?t\s+(?:sold|worked)\b|\buntapped\b|\bfresh\s+(?:territory|ground)\b", {"few_customers": 1.0}),
    (r"\b(?:near|around|by|next\s+to)\s+(?:our\s+)?(?:existing\s+|past\s+|other\s+)?(?:[a-z-]+\s+){0,2}"
     r"(?:customers?|jobs?|installs?|work)\b|"
     r"\bwhere\s+we'?ve\s+(?:done|installed|worked)\b|\breferrals?\b|\bsocial\s+proof\b|\bneighbou?rs?\s+of\s+customers?\b",
     {"product_nearby": 1.0, "few_customers": 0.0}),
    (r"\b(?:close(?:\s+by|\s+to\s+(?:the\s+)?(?:shop|office|home|base))?|short\s+drive|not\s+far|nearby|local)\b",
     {"close": 1.0}),
    (r"\b(?:home\s*owners?|owner[\s-]occupied|owners)\b", {"owners": 0.9}),
    (r"\b(?:older|old)\s+(?:homes?|houses?|neighbou?rhoods?)\b", {"older_homes": 0.9}),
    (r"\bsingle[\s-]family\b|\bhouses\s+not\s+(?:apartments|condos)\b|\bno\s+(?:apartments|condos)\b",
     {"single_family": 1.0}),
]

_NOT_HUNG = re.compile(
    r"\b(?:not|never|haven'?t|hasn'?t|no)\b[^.,;]{0,30}\b(?:been|hung|gone|covered|done|visited|went)\b[^.,;]{0,30}"
    r"(?:\b(?:lately|recently|before|yet)\b|\bin\s+(?:the\s+)?(?:last|past)\s+(\d+|\w+)\s+(days?|weeks?|months?)\b)?|"
    r"\bsomewhere\s+(?:new|fresh)\b|\bnot\s+(?:the\s+)?same\s+(?:streets?|places?)\b", re.I)
_SINCE = re.compile(r"\b(?:in\s+(?:the\s+)?(?:last|past)|within)\s+(\d+|\w+)\s+(days?|weeks?|months?)\b", re.I)
_HOW_MANY = re.compile(r"\b(?:top|best|give\s+me|show\s+me|find|list|suggest)?\s*(\d{1,2}|" + "|".join(
    sorted(_WORDNUM, key=len, reverse=True)) + r")\s+(?:good\s+|best\s+|new\s+|more\s+|different\s+)?"
    r"(?:neighbou?rhoods?|areas?|places?|options?|spots?|streets?|routes?|walks?)\b", re.I)
_MILES = re.compile(r"\bwithin\s+(\d{1,3}(?:\.\d)?)\s*(?:mi\b|miles?)", re.I)
_NEAR = re.compile(r"\b(?:near|around|in|by|close\s+to|outside\s+of|over\s+in|up\s+in|out\s+in|down\s+in)\s+"
                   r"((?:the\s+)?[A-Za-z][A-Za-z .'-]{1,40}|\d{5})", re.I)
_STOP = re.compile(r"\b(?:this|next|that|these|those|on|for|with|and|but|not|where|which|today|tomorrow|week|weekend|"
                   r"month|please|thursday|friday|saturday|monday|tuesday|wednesday|sunday|morning|afternoon|"
                   r"hangers?|door|doorhangers?|flyers?|sunny|hot|fire|lately|recently)\b.*$", re.I)


def _num(w):
    w = w.lower().strip()
    if w.isdigit():
        return int(w)
    return _WORDNUM.get(w)


def is_prospecting(text):
    t = str(text or "")
    if not t.strip():
        return False
    if _COUNTING.search(t) and not re.search(r"\bwhere\s+should\b|\bwhich\s+neighbou?rhoods?\s+should\b", t, re.I):
        return False
    if _RESULTS.search(t) and not _FORWARD.search(t):
        return False
    return bool(TRIGGER.search(t))


def _places(pack, zip_names, communities=()):
    names = {}
    for z, n in (zip_names or {}).items():
        if n:
            names[n.casefold()] = n
        names[z] = z
    for r in (getattr(pack, "regions", None) or {}):
        names[r.casefold()] = r
    for w in (getattr(pack, "region_words", None) or {}):
        names[w.casefold()] = w
    for c in communities or ():
        if c:
            names.setdefault(c.casefold(), c)
    return names


def _match_at(cand, known):
    """The longest known place name at the start of `cand` (a phrase after near/in), or None."""
    cand = cand.strip()
    if cand[:5].isdigit():
        return known.get(cand[:5]) if len(cand) == 5 or not cand[5:6].isdigit() else None
    cand = _STOP.sub("", cand).strip(" .,'-")
    cand = re.sub(r"^the\s+", "", cand, flags=re.I)
    words = cand.split()
    for n in range(min(5, len(words)), 0, -1):
        k = " ".join(words[:n]).casefold()
        if k in known:
            return known[k]
    return None


_MORE = re.compile(r"^\s*(?:,\s*(?:or\s+|and\s+)?|\s+or\s+|\s+and\s+)(?:near\s+|in\s+|around\s+)?(?:the\s+)?"
                   r"([A-Za-z][A-Za-z .'-]{1,40}|\d{5})", re.I)


def find_places(text, pack, zip_names, communities=()):
    """The places named ("near Cedar Hills", "on the north side", "in 00011", "Maple Falls or Pine Ridge"), longest known name
    first; [] when none. A known town or ZIP said bare counts too ("northtown this week", "00011 on Saturday")."""
    known = _places(pack, zip_names, communities)
    for m in _NEAR.finditer(text):
        first = _match_at(m.group(1), known)
        if not first:
            continue
        got = [first]
        rest = text[m.start(1) + _span_len(m.group(1), first):]
        while len(got) < 5:
            mm = _MORE.match(rest)
            if not mm:
                break
            nxt = _match_at(mm.group(1), known)
            if not nxt:
                break
            if nxt not in got:
                got.append(nxt)
            rest = rest[mm.start(1) + _span_len(mm.group(1), nxt):]
        return got
    low = " " + re.sub(r"[^\w\s']", " ", text).casefold() + " "
    for k in sorted(known, key=len, reverse=True):
        if (len(k) >= 5 and not k.isdigit() and f" {k} " in low) or (k.isdigit() and len(k) == 5 and f" {k} " in low):
            return [known[k]]
    return []


def _span_len(phrase, name):
    """How much of `phrase` the place `name` took ("the Cedar Hills area" -> through "Cedar Hills")."""
    i = phrase.casefold().find(str(name).casefold())
    return (i + len(str(name))) if i >= 0 else len(phrase.split()[0]) if phrase.split() else 0


def find_place(text, pack, zip_names, communities=()):
    got = find_places(text, pack, zip_names, communities)
    return got[0] if got else None


_NOT_NEAR_CUSTOMERS = re.compile(
    r"\b(?:not|never|nowhere)\s+(?:near|around|by|next\s+to|close\s+to)\s+(?:our\s+|any\s+)?(?:existing\s+|past\s+|other\s+)?"
    r"(?:[a-z-]+\s+){0,2}customers?\b|\b(?:away\s+from|far\s+from|avoid(?:ing)?)\s+(?:our\s+|the\s+)?"
    r"(?:existing\s+|past\s+|other\s+)?(?:[a-z-]+\s+){0,2}customers?\b", re.I)
_NEG_BEFORE = re.compile(r"(?:\bnot|\bno|\bnever|\binstead\s+of|\brather\s+than|\bexcept|\bbut\s+not)\s+(?:the\s+|any\s+)?$", re.I)
_IN_TIME = re.compile(r"\bin\s+(?:over\s+|more\s+than\s+)?(?:a\s+|an\s+)?(\d+|\w+)?\s*(days?|weeks?|months?|years?)\b", re.I)


def _days(n, unit):
    unit = unit.lower()
    return n * (365 if unit.startswith("year") else 30 if unit.startswith("month") else 7 if unit.startswith("week") else 1)


def read(text, pack, zip_names=None, communities=()):
    """(recipe, read_notes) for a prospecting question; None when the question isn't one."""
    t = str(text or "")
    if not is_prospecting(t):
        return None
    recipe = {}
    notes = []
    places = find_places(t, pack, zip_names or {}, communities)
    # the rest is read without the places' names: "Fire Example" is a place, not a fire ask
    rest = t
    for pl in places:
        rest = re.sub(re.escape(str(pl)), " ", rest, flags=re.I)
    # "not near chimney-cap customers": not the product asked for, and not a "near our customers" ask
    nn = _NOT_NEAR_CUSTOMERS.search(rest)
    if nn:
        rest = rest[:nn.start()] + " " + rest[nn.end():]
    low = rest.casefold()
    prods = (getattr(pack, "prospect", None) or {}).get("products") or {}
    best, best_len, best_span = None, 0, None
    for key, p in prods.items():
        for w in p.get("words") or []:
            for m in re.finditer(r"\b" + re.escape(w) + r"\b", low):
                if _NEG_BEFORE.search(low[max(0, m.start() - 16):m.start()]):
                    continue                     # "not chimney caps, shade sails only"
                if len(w) > best_len:
                    best, best_len, best_span = key, len(w), (m.start(), m.end())
    if best:
        recipe["product"] = best
    ingredients = {}
    if nn:
        ingredients["few_customers"] = 1.0
        ingredients["product_nearby"] = 0.0
    for pat, raise_ in RAISE:
        # a word inside the product's own name ("fire" in a product called "fire guards") is the product, not an ask
        hits = [m_ for m_ in re.finditer(pat, low, re.I)
                if not (best_span and m_.start() >= best_span[0] and m_.end() <= best_span[1])]
        if hits:
            for k, v in raise_.items():
                if nn and k in ("product_nearby", "few_customers"):
                    continue
                ingredients[k] = max(ingredients.get(k, 0.0), v) if v > 0 else 0.0
    if ingredients:
        recipe["ingredients"] = ingredients
    ex = {}
    m = _NOT_HUNG.search(rest)
    if m:
        days = 90
        s = _SINCE.search(rest) or _IN_TIME.search(rest[m.start():])
        if s:
            n = _num(s.group(1)) if s.group(1) else 1
            if n:
                days = _days(n, s.group(2))
        ex["hung_within_days"] = max(1, min(3650, days))
        # (not said here: the answer's read_as already says "Left out: streets hung in the last N days")
    if ex:
        recipe["exclude"] = ex
    h = _HOW_MANY.search(rest)
    if h:
        n = _num(h.group(1))
        if n:
            recipe["how_many"] = n
    mm = _MILES.search(rest)
    if mm:
        recipe["max_miles"] = float(mm.group(1))
    if places:
        recipe["near"] = places[0] if len(places) == 1 else places
    return recipe, notes
