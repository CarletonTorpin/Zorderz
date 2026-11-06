"""Turning a typed question into words the reader can match.

prepare() lower-cases, splits run-together words ("salesMirage"), standardises dashes and
quotes, and fixes typos ONLY toward words Charts knows (its own words, the pack's products,
towns, people, brands and sources). A word Charts doesn't know and can't place within one or
two letters is left as it is, and the reader reports it as unread. Capitalised words are never
"corrected": they may be names, and a name must never turn into a product or a month.
"""
import re
from dataclasses import dataclass, field

TOKEN = re.compile(r"[a-z0-9$%']+")
# Mistyped word breaks people make, fixed before anything else.
SPLIT_TYPOS = {"int eh": "in the", "fo the": "of the", "inthe": "in the", "ofthe": "of the",
               "teh": "the", "adn": "and", "tot he": "to the", "lsat": "last", "yr": "year", "yrs": "years",
               "mo": "month", "mos": "months", "thru": "through", "vs.": "vs", "v.": "vs", "w/o": "without",
               "w/": "with", "&": " and ", "jan.": "jan", "feb.": "feb", "aug.": "aug", "sept.": "sept",
               "oct.": "oct", "nov.": "nov", "dec.": "dec"}


@dataclass
class Token:
    text: str          # normalised, lower case
    start: int         # character span in Prepared.text
    end: int
    original: str      # as typed (for capitalisation)
    corrected_from: str = ""


@dataclass
class Prepared:
    question: str
    text: str                        # normalised: lower case, single spaces
    tokens: list
    corrections: list = field(default_factory=list)     # [(typed, read_as)]
    shouting: bool = False           # mostly capitals: capitalisation means nothing

    def span_tokens(self, start, end):
        """Indexes of the tokens inside a character span."""
        return [i for i, t in enumerate(self.tokens) if t.start < end and t.end > start]


def _osa(a, b, limit):
    """Optimal-string-alignment distance (a transposition counts as 1), stopping early past limit."""
    if abs(len(a) - len(b)) > limit:
        return limit + 1
    prev2, prev = None, list(range(len(b) + 1))
    for i in range(1, len(a) + 1):
        cur = [i] + [0] * len(b)
        best = cur[0]
        for j in range(1, len(b) + 1):
            cost = 0 if a[i - 1] == b[j - 1] else 1
            cur[j] = min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + cost)
            if i > 1 and j > 1 and a[i - 1] == b[j - 2] and a[i - 2] == b[j - 1]:
                cur[j] = min(cur[j], prev2[j - 2] + 1)
            best = min(best, cur[j])
        if best > limit:
            return limit + 1
        prev2, prev = prev, cur
    return prev[-1]


def nearest(word, known, protected=()):
    """The known word within reach of a typo, or None. Reach: 1 edit for 4-6 letters, 2 for
    7 or more. Ties go to the alphabetically first, so the answer never depends on order."""
    if len(word) < 4 or not word.isalpha():
        return None
    limit = 1 if len(word) <= 6 else 2
    best, best_d = None, limit + 1
    for k in known:
        if k in protected or abs(len(k) - len(word)) > limit or not k.isalpha() or len(k) < 3:
            continue
        if k[0] != word[0] and (len(k) < 2 or k[1] != word[1]):
            continue                      # typos rarely change both first letters
        d = _osa(word, k, limit)
        if d < best_d or (d == best_d and best is not None and k < best):
            best, best_d = k, d
    return best if best_d <= limit else None


def prepare(question, known_words=frozenset(), never_correct=frozenset()):
    q = str(question or "")
    letters = [c for c in q if c.isalpha()]
    shouting = bool(letters) and sum(c.isupper() for c in letters) / len(letters) > 0.6
    # Run-together words: "salesBrightline" → "sales Brightline" (not "McName" or "Clearline").
    q2 = re.sub(r"(?<=[a-z]{3})(?=[A-Z][a-z])", " ", q) if not shouting else q
    s = q2.replace("’", "'").replace("‘", "'").replace("“", '"').replace("”", '"')
    s = re.sub(r"[‐-―−]", "-", s)
    low = " " + s.lower() + " "
    for typo, fix in SPLIT_TYPOS.items():
        low = re.sub(r"(?<![\w$%])" + re.escape(typo) + r"(?![\w%])", fix, low)
    low = re.sub(r"\s+", " ", low).strip()
    # Original casing, aligned word by word where possible (for names and initials).
    orig_words = re.findall(r"[A-Za-z0-9$%']+", s)
    toks, corrections, joiners = [], [], []
    oi, last_end = 0, None
    for m in TOKEN.finditer(low):
        word = m.group(0).strip("'")
        if word.endswith("'s") and len(word) > 3:
            word = word[:-2]                 # "Northstar's" reads as "northstar"; the name check keeps the original
        if not word:
            continue
        gap = low[last_end:m.start()] if last_end is not None else ""
        last_end = m.end()
        joiners.append(gap if gap in ("-", "/", ":", ".") else " ")
        original = word
        for k in range(oi, min(oi + 3, len(orig_words))):     # look a few words ahead only
            if orig_words[k].lower().strip("'") == word:
                original = orig_words[k].strip("'")
                oi = k + 1
                break
        t = Token(word, m.start(), m.start() + len(m.group(0)), original)
        capitalised = (not shouting) and original[:1].isupper() and len(toks) > 0
        if known_words and word not in known_words and not capitalised and word not in never_correct:
            fix = nearest(word, known_words)
            if fix:
                corrections.append((word, fix))
                t.corrected_from, t.text = word, fix
        toks.append(t)
    # Rebuild the text from the (corrected) tokens so phrases and dates read the fixed words.
    text, out = "", []
    for t, j in zip(toks, joiners):
        if text:
            text += j
        start = len(text)
        text += t.text
        out.append(Token(t.text, start, len(text), t.original, t.corrected_from))
    return Prepared(question=str(question or ""), text=text, tokens=out, corrections=corrections,
                    shouting=shouting)


def words_of(phrase):
    """A phrase as the tokens prepare() would make of it."""
    low = " " + str(phrase).lower().replace("’", "'") + " "
    low = re.sub(r"[‐-―−]", "-", low)
    for typo, fix in SPLIT_TYPOS.items():
        if typo in ("&",):
            low = low.replace(typo, fix)
    return tuple(w.strip("'") for w in TOKEN.findall(low) if w.strip("'"))
