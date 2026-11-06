"""Territory questions read by rule.

This reader only says WHICH territory question was asked; the website then asks Territories as the person asking
(territories.of_person / for_zip / map) and draws the answer.

    "what are my territories?"          -> {"who": "me"}
    "my zip codes"                      -> {"who": "me"}
    "who covers 00014?"                 -> {"who": "zip:00014"}
    "is 00014 in my territory?"         -> {"who": "zip:00014"}
    "show all territories"              -> {"who": "all"}
    "show Jordan's territory"            -> {"who": "person:jordan"}   (the website matches the word to a person)

read() returns None for anything else, so every other question goes on to the chart reader exactly as before. A
territory question is made ONLY of territory words (the closed list _WORDS below), at most one ZIP and, for a
person, one name that is not a place. Anything more ("population of my zip codes", "who has the most in 00014",
"how is my territory doing", "my zip codes in 2024", "00014 vs 00015") asks for a figure or a comparison: that
is a chart, and the hub never learns which ZIPs are whose. A fixed-shape question is answered by code, not by a
model.
"""
import re

WHO_ME, WHO_ALL = "me", "all"

# Every word a territory question may contain (lower case, after "'s" is split off). Anything else: not one.
_WORDS = set("""
what whats which where who whos whose is are was do does did can could would will i we me my mine our ours us the a an
all every everyone everybody each team company salespeople salesperson salesman salesmen rep reps person people
show see view list give tell display draw open pull up look at map maps of for in on to please just only now currently
territory territories zip zips code codes area areas service turf covers cover covered covering has have owns own owned
gets get handles handle takes take belongs belong it that this there here
""".split())
_AFTER_SALES = {"territory", "territories", "team", "person", "people", "rep", "reps", "zip", "zips", "area", "areas"}
_TERR = r"(?:sales\s+)?(?:territor(?:y|ies)|zip\s*codes?|zips|zip\s+areas?|service\s+areas?|turf)"
_ZIP = re.compile(r"(?<!\d)(\d{5})(?:-\d{4})?(?!\d)")
_NOT_A_TERRITORY = re.compile(r"\b(?:new|fresh|untapped|virgin)\s+(?:territor(?:y|ies)|ground|areas?)\b", re.I)

_ME = re.compile(
    r"\bmy\s+(?:own\s+)?" + _TERR + r"\b|"
    r"\b" + _TERR + r"\s+(?:are|is)\s+mine\b|"
    r"\bwhich\s+(?:zip\s*codes?|zips|areas?|territor(?:y|ies))\s+(?:are|is|do)\s+(?:mine|i\s+(?:have|cover|own|get))\b|"
    r"\bwhat\s+(?:zip\s*codes?|zips|areas?)\s+do\s+i\s+(?:have|cover|own|get)\b", re.I)
_ALL = re.compile(
    r"\b(?:all|every(?:one|body)?|the|our|team|company|sales(?:people|\s+team)?|each)\s+(?:'s\s+)?"
    r"(?:the\s+)?(?:sales\s+)?territor(?:y|ies)\b|"
    r"\bterritor(?:y|ies)\s+maps?\b|\bmaps?\s+(?:of\s+)?(?:the\s+|all\s+|our\s+)?(?:sales\s+)?territor(?:y|ies)\b|"
    r"\bwho\s+covers\s+(?:what|which\s+(?:zips?|zip\s*codes?|areas?))\b|"
    r"^\s*(?:show\s+(?:me\s+)?)?(?:the\s+)?(?:sales\s+)?territor(?:y|ies)\s*\??\s*$", re.I)
# a ZIP question needs one of these (or the word territory with the ZIP)
_ZIP_ASK = re.compile(
    r"\bwho\b|\bwhose\b|\bwhich\s+(?:sales\s*(?:person|people|man|men)|salesperson|salesman|reps?|person)\b|"
    r"\bmine\b|\bmy\s+" + _TERR + r"\b|\b(?:do|does)\s+(?:i|we)\s+(?:cover|have|own|get)\b|\bterritor(?:y|ies)\b", re.I)
# "jordan 's territory" (after the split below), "the territory of FT", "territories for Jordan"
_PERSON = re.compile(
    r"\b([a-z][a-z.-]{1,19})\s+'s\s+(?:" + _TERR + r")\b|"
    r"\b" + _TERR + r"\s+(?:of|for)\s+([a-z][a-z.-]{1,19})\b", re.I)


def _norm(text):
    t = " ".join(str(text or "").replace("’", "'").split())
    t = re.sub(r"(?<=[A-Za-z])'s\b", " 's", t)            # "Jordan's" -> "Jordan 's"
    t = re.sub(r"(?<=[A-Za-z]s)'(?=\s|$)", " 's", t)       # "Jameses'" -> "Jameses 's"
    return t


def _words(t):
    """The question's words, without punctuation, ZIP codes and the possessive 's."""
    w = re.findall(r"[a-z0-9]+", _ZIP.sub(" ", t.lower()))
    return [x for x in w if x != "s"]


def is_territory_question(text, places=()):
    return read(text, places) is not None


def read(text, places=()):
    """{"who", "title", "read_as"} for a territory question; None when the question isn't one.
    `places`: lower-case names of towns and areas (a name in a "<word>'s territory" question that is a place is not a
    person: "Cedar Hills's zip codes" goes to the chart reader)."""
    t = _norm(text)
    if not t or len(t) > 200 or _NOT_A_TERRITORY.search(t):
        return None
    words = _words(t)
    zips = list(dict.fromkeys(_ZIP.findall(t)))
    if len(zips) > 1:
        return None                                    # several ZIPs: a comparison, a chart
    person = None
    m = _PERSON.search(t)
    if m:
        cand = (m.group(1) or m.group(2) or "").strip(".-").lower()
        if cand and cand not in _WORDS and cand not in {str(p).lower() for p in places or ()}:
            person = cand
    # "sales" only as in "sales territory / sales team / sales person": "sales in my territory" is a chart
    unknown = [w for i, w in enumerate(words) if w not in _WORDS and w != person
               and not (w == "sales" and i + 1 < len(words) and words[i + 1] in _AFTER_SALES)]
    if unknown:
        return None                                    # a word a territory question doesn't use: leave it to charts
    if zips:
        if not _ZIP_ASK.search(t):
            return None
        z = zips[0]
        mine = bool(re.search(r"\b(?:mine|my|i)\b", t, re.I))
        return {"who": "zip:" + z, "title": "Who covers " + z,
                "read_as": ["Is " + z + " in your territory" if mine else "Who covers " + z,
                            "From Territories, as you may see it"]}
    if _ME.search(t):
        return {"who": WHO_ME, "title": "Your territory",
                "read_as": ["Your territory: the ZIP codes in Territories under your name"]}
    if person:
        shown = person.upper() if len(person) <= 2 else person[:1].upper() + person[1:]
        return {"who": "person:" + person, "title": shown + "'s territory",
                "read_as": [shown + "'s territory, if your account may see it"]}
    if _ALL.search(t):
        return {"who": WHO_ALL, "title": "All territories",
                "read_as": ["Every territory your account may see, each person in their own color"]}
    return None
