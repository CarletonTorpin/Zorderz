"""Partner businesses, kept from the local models.

People still SEE partners by name; only the text that goes TO a model changes, and the hub puts the names back
after. The same shape as accounts.Directory.mask / mask_spec / unmask:

  * question reader: a listed partner's name, or a spelling an admin listed for it
    (lead_sources.yml `partners`), typed in a question becomes "Partner 1",
    "Partner 2", ... before the model reads it. The model copies the placeholder into
    filters.partners ("its name exactly as the user wrote it"); resolve() puts the
    real name back. The previous spec of a follow-up is masked the same way.
  * caption writer: a chart's partner groups, its partners filter, title, assumptions
    and clarify text reach it as placeholders; unmask() puts the names back in the
    caption a person reads.
  * misses log: questions and specs keep the placeholder; misses.redact() also hides
    any listed spelling that reached the log some other way ("[partner]").

"Partner n" fits the spec schema's partners pattern, and "partner" is one of the
misses log's own words, so neither had to change. A spelling made only of everyday
words ("Top Shade", "Mobile Fix") is matched only when typed with capitals, the
way accounts' short nicknames are case-sensitive, so "top shade jobs" and
"mobile fix repairs" still mean the words, not the company.
"""
import copy
import re
from functools import lru_cache
from pathlib import Path

import yaml

from . import names
from .lead_sources import partner_of, rules

PLACEHOLDER = re.compile(r"(?<![\w])Partner ([1-9]\d?)(?![\w])")
MAX_PLACEHOLDERS = 30
HIDDEN = "[partner]"


@lru_cache(maxsize=4)
def _everyday(pack_dir):
    """Words that aren't a company's own: the misses log's chart words and every word
    in the Identity Pack outside lead_sources.yml (products, tags, towns, ...)."""
    from .misses import _COMMON, _WORD
    words = set(_COMMON)

    def walk(v):
        if isinstance(v, dict):
            for k, x in v.items():
                walk(k)
                walk(x)
        elif isinstance(v, (list, tuple)):
            for x in v:
                walk(x)
        elif isinstance(v, str):
            words.update(w.casefold() for w in _WORD.findall(v))

    for f in sorted(Path(pack_dir).glob("*.yml")):
        if f.name in names.PACK_REFERENCE_FILES:          
            continue
        if f.name == "lead_sources.yml":
            continue
        try:
            walk(yaml.safe_load(f.read_text(encoding="utf-8")))
        except Exception:        # an unreadable file adds no everyday words (so more is masked)
            continue
    return frozenset(words)


@lru_cache(maxsize=4)
def _phrases(pack_dir):
    """Whole phrases the pack uses for something else: every string in
    the pack outside lead_sources.yml, the quoted phrases inside its long texts, and
    the lead-source groups' own words (a group's word is never a partner's)."""
    out = set()

    def add(v):
        v = " ".join(str(v).split()).casefold()
        if v:
            out.add(v)
            out.update(" ".join(q.split()) for q in re.findall(r'"([^"]{2,60})"', v))

    def walk(v):
        if isinstance(v, dict):
            for k, x in v.items():
                add(k)
                walk(x)
        elif isinstance(v, (list, tuple)):
            for x in v:
                walk(x)
        elif isinstance(v, str):
            add(v)

    for f in sorted(Path(pack_dir).glob("*.yml")):
        if f.name in names.PACK_REFERENCE_FILES:          
            continue
        try:
            raw = yaml.safe_load(f.read_text(encoding="utf-8"))
        except Exception:
            continue
        if f.name == "lead_sources.yml":
            raw = (raw or {}).get("groups") if isinstance(raw, dict) else None
        walk(raw)
    return frozenset(out)


_SEP = r"[\s\-]*"     # "Acme Lawn", "Acme-Lawn", "AcmeLawn", "Sun&Shade"


def _capitals(word):
    """A pattern for a spelling typed with each word capitalised ("Acme Lawn")."""
    out = []
    for tok in word.split(" "):
        head, tail = tok[:1], tok[1:]
        out.append((re.escape(head.upper()) if head.isalpha() else re.escape(head)) +
                   (f"(?i:{re.escape(tail)})" if tail else ""))
    return _SEP.join(out)


_MATCHERS = {}


def matchers(pack_dir=None):
    """[(pattern, partner name)], longest spelling first."""
    pack_dir = str(pack_dir or names.PACK_DIR)
    r = rules(pack_dir)
    key = (pack_dir, id(r))
    if key in _MATCHERS:
        return _MATCHERS[key]
    from .misses import _WORD
    everyday, phrases = _everyday(pack_dir), _phrases(pack_dir)
    pairs = []
    for name, words in r.get("partner_words") or []:
        for w in dict.fromkeys(" ".join(str(x).split()) for x in words):
            if len(w) < 2:
                continue
            toks = [t.casefold() for t in _WORD.findall(w)]
            shared = w.casefold() in phrases and toks and all(t in everyday for t in toks)
            body = _capitals(w) if shared else "(?i:" + _SEP.join(re.escape(t) for t in w.split(" ")) + ")"
            pairs.append((len(w), re.compile(r"(?<![\w])" + body + r"(?:['’]s)?(?![\w])"), name))
    out = [(pat, name) for _, pat, name in sorted(pairs, key=lambda x: -x[0])]
    _MATCHERS.clear()
    _MATCHERS[key] = out
    return out


def listed(pack_dir=None):
    """The partner names on the business's list."""
    return {n for n, _ in rules(pack_dir)["partners"]}


def next_placeholder(mapping):
    """The next free "Partner n": after the highest one handed out or reserved."""
    used = [int(m.group(1)) for k in mapping for m in [PLACEHOLDER.fullmatch(k)] if m]
    n = max(used, default=0) + 1
    return f"Partner {n}" if n <= 99 and len(mapping) < MAX_PLACEHOLDERS else None


def _placeholder(name, mapping, back):
    ph = back.get(name)
    if ph is None:
        ph = next_placeholder(mapping)
        if ph:
            mapping[ph], back[name] = name, ph
    return ph


def mask(text, mapping=None, pack_dir=None):
    """Every listed partner spelling → "Partner n". Returns (text, mapping {"Partner n": name})."""
    mapping = dict(mapping or {})
    out = str(text or "")
    # "Partner 2" the PERSON typed is their own text. It is reserved
    # (maps to None): numbering goes past it, resolve() never maps it, unmask() keeps it.
    for m in PLACEHOLDER.finditer(out):
        mapping.setdefault(m.group(0), None)
    back = {v: k for k, v in mapping.items() if v is not None}
    found = {}
    for pat, name in matchers(pack_dir):          # numbered in the order they're written
        m = pat.search(out)
        if m:
            found[name] = min(found.get(name, m.start()), m.start())
    for name in sorted(found, key=found.get):
        _placeholder(name, mapping, back)
    for pat, name in matchers(pack_dir):          # replaced longest spelling first
        out = pat.sub(back.get(name) or HIDDEN, out)
    return out, mapping


def hide(text, pack_dir=None):
    """Every listed partner spelling → "[partner]" (the misses log's last line of defence)."""
    out = str(text or "")
    for pat, _ in matchers(pack_dir):
        out = pat.sub(HIDDEN, out)
    return out


def mask_spec(spec, mapping=None, pack_dir=None):
    """A spec going to a model (a follow-up's previous spec, a caption's spec, the
    misses log): partners filter, title, clarify text and assumptions masked."""
    spec = copy.deepcopy(spec)
    mapping = dict(mapping or {})
    back = {v: k for k, v in mapping.items() if v is not None}
    f = spec.get("filters")
    if isinstance(f, dict) and isinstance(f.get("partners"), list):
        out = []
        for p in f["partners"]:
            p = str(p)
            if PLACEHOLDER.fullmatch(p):
                if mapping.get(p):
                    out.append(p)
                continue
            name = partner_of(p, pack_dir)
            if name:
                ph = _placeholder(name, mapping, back)
                out.append(ph or HIDDEN)
            else:
                t, mapping = mask(p, mapping, pack_dir)
                back = {v: k for k, v in mapping.items() if v is not None}
                out.append(t)
        f["partners"] = list(dict.fromkeys(out))
    for k in ("title", "clarify_question"):
        if isinstance(spec.get(k), str):
            spec[k], mapping = mask(spec[k], mapping, pack_dir)
    for k in ("assumptions", "clarify_options"):
        if isinstance(spec.get(k), list):
            done = []
            for a in spec[k]:
                t, mapping = mask(a, mapping, pack_dir)
                done.append(t)
            spec[k] = done
    return spec, mapping


def resolve(spec, mapping):
    """The model's placeholders in filters.partners → the real names. A placeholder
    the hub didn't hand out (or one the person typed themselves) is dropped, and so is
    "[partner]" (a partner past the 30-placeholder limit, which can't be resolved:
    ); anything else (the "(no listed partner named)" choice) is kept."""
    f = spec.setdefault("filters", {})
    out = []
    for p in f.get("partners") or []:
        if isinstance(p, str) and PLACEHOLDER.fullmatch(p):
            if mapping.get(p):
                out.append(mapping[p])
        elif isinstance(p, str) and HIDDEN in p:
            continue
        else:
            out.append(p)
    f["partners"] = list(dict.fromkeys(out))
    return spec


def unmask(text, mapping):
    """Placeholders → the partner's name, in text going to a person."""
    def sub(m):
        ph = m.group(0)
        if ph in mapping and mapping[ph] is None:
            return ph                       # the person's own words
        return mapping.get(ph) or "a partner"
    return PLACEHOLDER.sub(sub, str(text or ""))
