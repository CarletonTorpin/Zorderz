"""The phrase book: every phrase Charts knows, with what it means.

Built once per pack from the core lexicon and the pack's own files (catalog, parties,
territories, lead_sources, terms.yml). match() reads a prepared question left to right and
takes the longest known phrase at each place; a word already read as part of a date is skipped.
"""
import re
from dataclasses import dataclass, field
from pathlib import Path

import yaml

from . import lexicon as L
from .text import words_of

MONTHS = {"january": 1, "jan": 1, "february": 2, "feb": 2, "march": 3, "mar": 3, "april": 4, "apr": 4,
          "may": 5, "june": 6, "jun": 6, "july": 7, "jul": 7, "august": 8, "aug": 8, "september": 9,
          "sept": 9, "sep": 9, "october": 10, "oct": 10, "november": 11, "nov": 11, "december": 12, "dec": 12}
OTHERS = ["other", "others", "all other", "all others", "the rest", "rest", "everything else", "each other",
          "other types", "other sources", "other channels", "other referral sources", "all the other",
          "every other", "anything else", "all other types"]
EXCLUDE = ["exclude", "excludes", "excluding", "except", "except for", "without", "not including",
           "leave out", "leaving out", "minus", "other than", "but not", "omit", "omitting", "ignore",
           "ignoring", "drop", "remove", "excluding ones"]
EXTRA_KNOWN = ("revenue jobs units customers average items item sold sell sales installed "
               "install installs referral referred refer company companies partner partners lead leads source "
               "sources salesperson salespeople town towns city cities product products year years month months "
               "week weeks quarter quarters day days season seasons summer winter spring fall autumn compare "
               "compared versus each every total number decade since through last this same point time today "
               "now date dates side stacked line chart pie table busiest best most top share percent growth "
               "trend weekly monthly yearly quarterly daily repairs installations neighborhoods "
               "region regions category categories types type styles style sales person people").split()


def unit_words(pack):
    """The pack's own nouns for units (mappings.yml measure_words.units, a comma list), "units" left out."""
    text = str(((getattr(pack, "text", None) or {}).get("measure_words") or {}).get("units") or "")
    words = {w.strip().casefold() for w in re.sub(r'\(.*?\)|"[^"]*"', "", text).split(",")}
    return sorted(w for w in words if re.fullmatch(r"[a-z]+(?: [a-z]+)?", w) and w not in ("unit", "units"))


@dataclass(frozen=True)
class Meaning:
    kind: str
    value: object = None
    data: tuple = ()          # frozen (key, value) pairs

    def get(self, key, default=None):
        for k, v in self.data:
            if k == key:
                return v
        return default


@dataclass
class Hit:
    start: int                # token index
    end: int                  # one past the last token
    meaning: Meaning
    phrase: str

    @property
    def kind(self):
        return self.meaning.kind

    @property
    def value(self):
        return self.meaning.value


def _freeze(d):
    out = []
    for k, v in d.items():
        if isinstance(v, list):
            v = tuple(v)
        out.append((k, v))
    return tuple(out)


@dataclass
class Terms:
    phrases: dict = field(default_factory=dict)      # first word -> [(words, Meaning)] longest first
    known_words: frozenset = frozenset()
    never_correct: frozenset = frozenset()
    products: list = field(default_factory=list)
    tags: list = field(default_factory=list)
    tag_group_of: dict = field(default_factory=dict)  # tag -> group key
    work_types: list = field(default_factory=list)
    areas: list = field(default_factory=list)
    salespeople: list = field(default_factory=list)
    person_names: dict = field(default_factory=dict)  # lower name -> [initials]
    source_groups: list = field(default_factory=list)
    partners: list = field(default_factory=list)
    regions_undefined: list = field(default_factory=list)
    product_detail_split: dict = field(default_factory=dict)
    company_names: list = field(default_factory=list)
    problems: list = field(default_factory=list)      # pack entries skipped (never guessed)

    # ------------------------------------------------------------ building
    def add(self, phrase, meaning, replace=False):
        words = phrase if isinstance(phrase, tuple) else words_of(phrase)
        if not words:
            return
        bucket = self.phrases.setdefault(words[0], [])
        for i, (w, m) in enumerate(bucket):
            if w == words:
                if replace:
                    bucket[i] = (words, meaning)
                return                      # first meaning registered wins
        bucket.append((words, meaning))
        bucket.sort(key=lambda x: -len(x[0]))

    def finish(self):
        known = set(L.STOPWORDS) | set(MONTHS) | set(EXTRA_KNOWN)
        for bucket in self.phrases.values():
            for words, _ in bucket:
                known.update(w for w in words if w.isalpha())
        self.known_words = frozenset(known)
        return self

    # ------------------------------------------------------------ matching
    def match(self, prepared, skip=frozenset(), drop=frozenset()):
        """drop: phrases not to match this time (a shorter phrase, or none, takes the words)."""
        toks = [t.text for t in prepared.tokens]
        hits, i = [], 0
        while i < len(toks):
            if i in skip:
                i += 1
                continue
            got = None
            for words, meaning in self.phrases.get(toks[i], []):
                n = len(words)
                if drop and " ".join(words) in drop:
                    continue
                if tuple(toks[i:i + n]) == words and not any(j in skip for j in range(i, i + n)):
                    got = Hit(i, i + n, meaning, " ".join(words))
                    break
            if got:
                hits.append(got)
                i = got.end
            else:
                i += 1
        return hits


def _load_yaml(path):
    try:
        return (yaml.safe_load(Path(path).read_text()) or {}) if path and Path(path).exists() else {}
    except Exception:
        return {}


def build(pack, pack_dir=None):
    """The phrase book for this pack. Unknown names in terms.yml are reported, not used."""
    t = Terms()
    pack_dir = Path(pack_dir or getattr(pack, "source", "") or ".")
    products = list(getattr(pack, "products", []) or [])
    t.products, t.work_types = products, list(getattr(pack, "work_types", []) or [])
    t.tags = list(getattr(pack, "tags", []) or [])
    for g in getattr(pack, "tag_groups", []) or []:
        for name in g["tags"]:
            t.tag_group_of[name] = g["key"]
    t.areas = [a for a in (getattr(pack, "areas", []) or []) if not a.startswith("(")]
    t.salespeople = list(getattr(pack, "salespeople", []) or [])

    terms_raw = (_load_yaml(pack_dir / "terms.yml").get("charts") or {})
    ls_raw = _load_yaml(pack_dir / "lead_sources.yml")

    # 1. Pack terms (highest priority after dates).
    for entry in terms_raw.get("terms") or []:
        words = [str(w).lower() for w in entry.get("words") or []]
        prods = [p for p in entry.get("products") or [] if p in products]
        tags = [x for x in entry.get("tags") or [] if x in t.tags]
        wts = [w for w in entry.get("work_types") or [] if w in t.work_types]
        bad = [p for p in entry.get("products") or [] if p not in products] + \
              [x for x in entry.get("tags") or [] if x not in t.tags] + \
              [w for w in entry.get("work_types") or [] if w not in t.work_types]
        if bad:
            t.problems.append(f"terms.yml {words[:1]}: unknown name(s) {bad}")
            continue
        meaning = Meaning("term", None, _freeze({
            "products": prods, "tags": tags, "work_types": wts,
            "customer_type": entry.get("customer_type") or "", "repeat": bool(entry.get("repeat")),
            "note": entry.get("note") or "",
            "context": [str(c).lower() for c in entry.get("context") or []],
            "unless": [str(c).lower() for c in entry.get("unless") or []]}))
        for w in words:
            t.add(w, meaning)
    # Exact catalog names always work too.
    for p in products:
        t.add(p, Meaning("term", None, _freeze({"products": [p], "tags": [], "work_types": [],
                                                 "customer_type": "", "repeat": False, "note": "",
                                                 "context": [], "unless": []})))
    area_names = {x.lower() for x in t.areas}
    for x in t.tags:
        if x.lower() in area_names:
            continue                     # a town name is a town first; terms.yml says when it's a product
        t.add(x, Meaning("term", None, _freeze({"products": [], "tags": [x], "work_types": [],
                                                 "customer_type": "", "repeat": False, "note": "",
                                                 "context": [], "unless": []})))
    for w in t.work_types:
        t.add(w, Meaning("term", None, _freeze({"products": [], "tags": [], "work_types": [w],
                                                 "customer_type": "", "repeat": False, "note": "",
                                                 "context": [], "unless": []})))

    # 2. Lead sources (question words), partners, people, places.
    from ..lead_sources import NEW_UNKNOWN                     # one name, from lead_sources
    groups = list(((ls_raw.get("groups") or {}).keys())) + ["Existing Customer - from history",
                                                             NEW_UNKNOWN, "Other"]
    t.source_groups = groups
    for group, words in (terms_raw.get("sources") or {}).items():
        if group not in groups:
            t.problems.append(f"terms.yml sources: unknown group {group}")
            continue
        for w in words or []:
            t.add(str(w).lower(), Meaning("source", group))
    for g in groups:
        if g != "Other":
            t.add(g.lower(), Meaning("source", g))
    for name, spellings in (ls_raw.get("partners") or {}).items():
        t.partners.append(name)
        for w in [name] + list(spellings or []):
            t.add(str(w).lower(), Meaning("partner", name))
    for n in range(1, 31):                              # what partners.mask() leaves for the model
        t.add(("partner", str(n)), Meaning("partner_ph", f"Partner {n}"))
    for n in range(1, 10):                              # what accounts.mask() leaves
        t.add(("account", str(n)), Meaning("account_ph", f"ACCOUNT_{n}"))
    for initials, names in (getattr(pack, "salesperson_names", {}) or {}).items():
        for n in names:
            key = n.lower()
            t.person_names.setdefault(key, [])
            if initials not in t.person_names[key]:
                t.person_names[key].append(initials)
    for key, initials in t.person_names.items():
        t.add(key, Meaning("person", tuple(initials), _freeze({"name": key.title()})))
    for initials in t.salespeople:
        t.add(initials.lower(), Meaning("initials", initials))
    for a in t.areas:
        t.add(a.lower(), Meaning("area", a))
    for a, variants in (getattr(pack, "aliases", {}) or {}).items():
        for v in variants:
            t.add(str(v).lower(), Meaning("area", a))
    for short, a in (terms_raw.get("area_short") or {}).items():
        if a in t.areas:
            t.add(str(short).lower(), Meaning("area", a))
    # a region the pack draws (territories.yml regions / region_words) is read as one; only a word in
    # regions_undefined that the pack does NOT draw still asks "which towns?".
    drawn = {str(k).casefold() for k in (getattr(pack, "regions", {}) or {})} | set((getattr(pack, "region_words", {}) or {}).keys())
    for r in drawn:
        t.add(r, Meaning("region", r))
        if not r.startswith("the "):
            t.add("the " + r, Meaning("region", r))
    t.regions_undefined = [str(r).lower() for r in terms_raw.get("regions_undefined") or [] if str(r).lower() not in drawn]
    for r in t.regions_undefined:
        t.add(r, Meaning("region_undefined", r))
    t.product_detail_split = dict(terms_raw.get("product_detail_split") or {})
    t.company_names = list(terms_raw.get("company_names") or [getattr(pack, "company_name", "")])

    # 3. Core words.
    for kind_value, words in L.MEASURE_WORDS.items():
        for w in words:
            t.add(w, Meaning("measure", kind_value))
    for w in L.HOW_MANY:
        t.add(w, Meaning("howmany"))
    for dim, words in L.DIMENSION_WORDS.items():
        for w in words:
            t.add(w, Meaning("dim", dim))
    group_keys = {g["key"] for g in (getattr(pack, "tag_groups", None) or [])}
    for g in (getattr(pack, "tag_groups", None) or []):
        for w in g.get("words") or []:
            t.add(str(w).casefold(), Meaning("dim", "tag_" + g["key"]))
    for key, words in L.DETAIL_SPLIT_WORDS.items():
        if key not in group_keys:
            continue
        for w in words:
            t.add(w, Meaning("dim", "tag_" + key))
    # the pack's own words for units ("panels", "sails"): a measure, and "<word> per job"
    for w in unit_words(pack):
        for ph in (w, "number of " + w, "how many " + w, w + " installed", "total number of " + w, w + " count"):
            t.add(ph, Meaning("measure", "units"))
        for ph in (w + " per job", w + " per each job", "number of " + w + " per job"):
            t.add(ph, Meaning("units_per_job"))
    for w in L.TYPE_WORDS:
        t.add(w, Meaning("typeword"))
    for w in L.SPLIT_INTRO:
        t.add(w, Meaning("split_intro"))
    for g, words in L.GRAIN_WORDS.items():
        for w in words:
            t.add(w, Meaning("grain", g))
    for g, words in L.DATE_PART_WORDS.items():
        for w in words:
            t.add(w, Meaning("date_part", g))
    for w in L.TREND_WORDS:
        t.add(w, Meaning("trend"))
    for w in L.COMPARE_SPLITTERS:
        t.add(w, Meaning("vs"))
    for w in L.COMPARE_LEAD:
        t.add(w, Meaning("compare_lead"))
    for w in L.RANK_TOP:
        t.add(w, Meaning("rank", "top"))
    for w in L.RANK_BOTTOM:
        t.add(w, Meaning("rank", "bottom"))
    for ct, words in L.CHART_WORDS.items():
        for w in words:
            t.add(w, Meaning("chart", ct))
    for o, words in L.ORIENTATION_WORDS.items():
        for w in words:
            t.add(w, Meaning("orientation", o))
    for lab, words in L.LABEL_WORDS.items():
        for w in words:
            t.add(w, Meaning("labels", lab))
    for w in L.SHARE_WORDS:
        t.add(w, Meaning("share"))
    for w in L.AVG_PER_PERIOD:
        t.add(w, Meaning("avg_per_period"))
    for w in L.UNITS_PER_JOB:
        t.add(w, Meaning("units_per_job"))
    for w in L.REPEAT_WORDS:
        t.add(w, Meaning("repeat"))
    for w in L.NEW_CUSTOMER_WORDS:
        t.add(w, Meaning("new_customers"))
    for w in L.JOBS_PER_CUSTOMER:
        t.add(w, Meaning("jobs_per_customer"))
    for words, reason, fallback in L.UNSUPPORTED:
        for w in words:
            t.add(w, Meaning("unsupported", reason, _freeze({"fallback": fallback})))
    for w in L.ZIP_WORDS:
        t.add(w, Meaning("zip"))
    for var, words in L.WEATHER_WORDS.items():          
        for w in words:
            t.add(w, Meaning("weather", var))
    for w in L.WEATHER_VIEW_WORDS:
        t.add(w, Meaning("weather_view"))
    for key, words in L.ZIP_FACT_WORDS.items():         
        for w in words:
            t.add(w, Meaning("zipfact", key))
    for w in L.ZIP_PROFILE_WORDS:
        t.add(w, Meaning("zip_profile"))
    for w in L.CUSTOMER_NAME_WORDS:
        t.add(w, Meaning("customer_names"))
    for w in OTHERS:
        t.add(w, Meaning("others"))
    for w in EXCLUDE:
        t.add(w, Meaning("exclude"))
    for w in L.TO_DATE_WORDS:
        t.add(w, Meaning("to_date"))
    for w in L.PRIOR_YEAR:
        t.add(w, Meaning("prior_year"))
    for w in L.PRIOR_PERIOD:
        t.add(w, Meaning("prior_period"))
    for w in L.FOLLOWUP_WORDS:
        t.add(w, Meaning("followup"))
    from .lexicon import COMMON_WORDS
    t.never_correct = frozenset({i.lower() for i in t.salespeople} | COMMON_WORDS)
    return t.finish()
