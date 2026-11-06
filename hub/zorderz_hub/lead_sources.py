"""Lead-source groups: raw words → a short list of channels.

The receptionist and installers type where a customer came from in their own words ("Referred by Acme Lawn",
"Prev. cust.", "Internet", "Install Tuesday"). The Identity Pack's lead_sources.yml names the groups and their
words; this module applies them. Raw labels are never kept: the hub stores the group only, so a referrer's
personal name can't reach a table, a chart or a model.

Blank stays blank (unknown), with one approved exception done in store: a job with no source whose household had
an earlier job with us reads "Existing Customer - from history", a group of its own.

a note word ("paid", "check", "install" …) blanks a label unless a listed partner or a named platform/brand
(lead_sources.yml `strong`) is also in it. "Paid", "paid in full", "Paid online", "Installed by Sam", "check
#1234", "Install Tuesday 2 pm" are still notes (blank); "Google paid search" and "Yelp check-in" are read as
their channel.


Labels that name a channel loosely are read by the pack's words: a plain "google" is organic Google, an internet
ad is Google Ads, a general "online" label is "Online organic - not Google". A household's FIRST job with no source
reads "Organic - no source given" (a customer who names no source most likely found the business online). A
household's LATER blank job still reads "Existing Customer - from history", and a later job that does name a source
still decides the household's original source. Saved charts that filter on the older name still match
(OLD_NEW_UNKNOWN, read in compute._with_history).
"""
import re
from pathlib import Path

import yaml

from . import names

OTHER = "Other"
FROM_HISTORY = "Existing Customer - from history"
EXISTING = "Existing Customer"
NEW_UNKNOWN = "Organic - no source given"               # a first job, no source; counted as organic
OLD_NEW_UNKNOWN = "New customer - source not recorded"


def _word_re(words):
    words = sorted({str(w).strip().casefold() for w in words if str(w).strip()}, key=len, reverse=True)
    if not words:
        return None
    return re.compile(r"(?<![a-z0-9])(?:" + "|".join(re.escape(w) for w in words) + r")(?![a-z0-9])")


_CACHE = {}


def forget():
    """Drop the loaded rules (tests; a pack edited while the hub runs)."""
    _CACHE.clear()


def _load(path):
    try:
        raw = yaml.safe_load(path.read_text(encoding="utf-8"))
    except (OSError, yaml.YAMLError):
        return None
    return raw if isinstance(raw, dict) else None


def rules(pack_dir=None):
    path = Path(pack_dir or names.PACK_DIR) / "lead_sources.yml"
    key = str(path)
    if key in _CACHE:
        return _CACHE[key]
    raw = _load(path) or {}
    groups = [(str(g)[:40], _word_re(ws or [])) for g, ws in (raw.get("groups") or {}).items()]
    partners = [(str(n)[:40], _word_re(list(ws or []) + [n])) for n, ws in (raw.get("partners") or {}).items()]
    # only these rescue a label that holds a note word. A strong
    # word must also be one of some group's words: the list can't invent a channel.
    group_words = {str(w).strip().casefold() for ws in (raw.get("groups") or {}).values() for w in ws or []}
    built = {"skip": _word_re(raw.get("not_a_source") or []),
             "strong": _word_re([w for w in raw.get("strong") or [] if str(w).strip().casefold() in group_words]),
             "groups": [(g, r) for g, r in groups if r is not None],
             "partners": [(n, r) for n, r in partners if r is not None],
             # the spellings as written, for masking partners from the models
             "partner_words": [(str(n)[:40], [str(n)] + [str(w) for w in ws or [] if str(w).strip()])
                               for n, ws in (raw.get("partners") or {}).items()],
             # the group for "referred by <a person we can't place>" is the
             # pack's to name (lead_sources.yml referred_by_a_person); Core names no group.
             "person_group": str(raw.get("referred_by_a_person") or "").strip()[:40]}
    if built["groups"]:
        _CACHE[key] = built
    return built


# times, dates and numbers in a note ("2 pm", "10:30am", "#1234", "11/2").
# Taken out before looking for a channel so "2 pm" never reads as "pm" (Property manager).
# A lone "am"/"pm" in a note is a time too ("Install Tues pm"), never Property manager.
_NUMBERISH = re.compile(r"(?<![a-z0-9])(?:#?\d[\d/.,:\-]*(?:\s*[ap]\.?m\b\.?)?|[ap]\.?m\b\.?)(?![a-z0-9])")


def _note(low, r, old_rule=False):
    """(text to classify, is_a_note). any note word makes the
    whole label a note. unless a STRONG channel term is also in it -- a listed
    partner or a named platform/brand from the pack's `strong` list ("Google paid
    search", "Yelp check-in", "Acme Lawn - paid"). Generic group words (team names,
    online, sign, glass, lawn, family, HOA, repeat, ...) never override a note word
    ("Installed by Sam", "Paid online", "thanks family" stay notes). Numbers and
    times aside, the rescued label is then classified as usual."""
    if r["skip"] is None or not r["skip"].search(low):
        return low, False
    if old_rule:
        return low, True
    rest = " ".join(_NUMBERISH.sub(" ", low).split())
    strong = r.get("strong")
    if any(p.search(rest) for _, p in r["partners"]) or (strong is not None and strong.search(rest)):
        return rest, False
    return low, True


def partner_of(label, pack_dir=None, old_rule=False):
    """A named partner business from the business's list, or ''."""
    low = " ".join(str(label or "").split()).casefold()
    if not low:
        return ""
    r = rules(pack_dir)
    low, note = _note(low, r, old_rule)
    if note:
        return ""
    for name, pat in r["partners"]:
        if low == name.casefold() or pat.search(low):
            return name
    return ""


def group_of(label, pack_dir=None, old_rule=False):
    """One raw label → its group ('' = not a source / not recorded)."""
    text = " ".join(str(label or "").split())
    if not text:
        return ""
    r = rules(pack_dir)
    if not r["groups"]:
        return OTHER                       # no usable pack file -> fail CLOSED, never the raw label
    low = text.casefold()
    names_ = {g for g, _ in r["groups"]} | {OTHER, FROM_HISTORY, NEW_UNKNOWN}
    if text in names_:
        return text                        # already a group
    if text == OLD_NEW_UNKNOWN:
        return NEW_UNKNOWN                 # the group's old name
    low, note = _note(low, r, old_rule)
    if note:
        return ""
    for name, pat in r["partners"]:              # a listed partner decides the group
        if pat.search(low) and name.casefold() != low:
            g = group_of(name, pack_dir)
            if g and g != OTHER:
                return g
    for g, pat in r["groups"]:
        if g.startswith("Referral") and low.startswith("referred by "):
            return r["person_group"] or g            # referred by a person we can't place
        if pat.search(low):
            return g
    return OTHER


def group_series(series, pack_dir=None, old_rule=False):
    cache = {}
    def one(v):
        if v not in cache:
            cache[v] = group_of(v, pack_dir, old_rule)
        return cache[v]
    return series.fillna("").astype(str).map(one)


_CONNECTORS = {"by", "to", "of", "the", "a", "and", "from", "via", "on", "in", "am", "pm"}
_TOKEN = re.compile(r"\[[a-z]+\]|[^\W\d_][\w'’.]*", re.I)


def label_shape(label, pack_dir=None):
    """a source label as `sources-diff` may print it. A whitelist:
    a listed partner becomes [partner]; emails, phones, addresses, links become their
    tags (as in the misses log); every word that isn't a group name or word, a strong
    word, a note word or a short connector becomes [word], whatever its case
    ("paid by pat quillby" -> "paid by [word] [word]"). Numbers and punctuation stay."""
    from .misses import _REDACT
    from .partners import hide
    text = " ".join(str(label or "").split())
    for pat, rep in _REDACT:
        text = pat.sub(rep, text)
    text = hide(text, pack_dir)
    path = Path(pack_dir or names.PACK_DIR) / "lead_sources.yml"
    raw = _load(path) or {}
    known = set(_CONNECTORS)
    for phrase in (list((raw.get("groups") or {}).keys()) +
                   [w for ws in (raw.get("groups") or {}).values() for w in ws or []] +
                   list(raw.get("strong") or []) + list(raw.get("not_a_source") or [])):
        known.update(t.casefold().strip(".") for t in _TOKEN.findall(str(phrase)))

    def one(m):
        w = m.group(0)
        if w.startswith("["):
            return w
        return w if w.casefold().strip(".") in known else "[word]"
    return _TOKEN.sub(one, text)[:80]
