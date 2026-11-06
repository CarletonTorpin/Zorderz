"""The identity pack, as Charts reads it.

A business describes itself in the Zorderz pack files (profile, catalog, parties,
territories, mappings, rules); Charts reads the `charts:` section of each. From
that one source the hub builds:
  * the allowed vocabulary (products, work types, tags, people, areas),
  * the spec-writer and caption prompts (Core templates + the business's wording),
  * the JSON schema the model is forced to follow,
  * colours and the `vocab` op the plugin fills its chips from.
With no pack the vocabulary is empty and every question is refused as
"not configured": the Core default never invents a business's facts.
"""
import copy
import json
import re
from dataclasses import dataclass, field
from pathlib import Path

import yaml

TEMPLATES = Path(__file__).resolve().parent.parent / "templates"
PACK_FILES = ("profile", "catalog", "parties", "territories", "mappings", "rules")

# Splits that exist for every business. Tag-group splits are added from the pack.
CORE_SPLITS = ["none", "product", "work_type", "salesperson", "area", "lead_source", "original_source", "referral_partner", "account",
               "payment"]   # paid / unpaid / draft, from the invoice's own FreshBooks status
TERRITORY_LABELS = {"safety_site": "Safety site", "safety_council": "Safety council"}   
PLACE_LABELS = {"tax_area": "Sales tax area", "coast_band": "Distance to the coast",            
                "coast": "Coastal or inland",                                                   
                "region": "Region"}                                                             
COAST_SPLITS = ("coast_band", "coast")       # offered only where the region pack declares a coastline


def _coast_known():
    """Does the region pack declare a coast layer? Without one there is no distance to the coast to split by."""
    try:
        from . import region as R
        return R.current().has("coast")
    except Exception:
        return False


CORE_SPLIT_HINTS = {
    "salesperson": '("by rep", "who sold")',
    "account": '("by account", "which accounts"; named business accounts only)',
    "area": '("by town/city")',
    "lead_source": '("where leads come from", "marketing source")',
    "original_source": '("how the customer first found us": every job of a customer counts under their first source)',
    "referral_partner": '("which companies referred us": partner businesses by name)',
}
CORE_MEASURE_WORDS = {
    "revenue": 'money, sales, dollars, "how much", income, billed (line totals, gross, not cash collected)',
    "jobs": 'jobs, invoices, "how many times"',
    "customers": "customers, clients",
    "units": 'units, pieces (some counts are estimated; the hub labels them)',
    "avg_job": "average job, average ticket, typical job size",
}
MEASURES = ["revenue", "jobs", "customers", "units", "avg_job"]
CHART_TYPES = ["auto", "stacked_bar", "grouped_bar", "line", "single_number", "table"]
TIME_GRAINS = ["none", "year", "quarter", "month"]
# Okabe-Ito: the Core default when a pack names no colours.
CORE_FALLBACK_COLORS = ["#0072B2", "#E69F00", "#009E73", "#CC79A7", "#56B4E9",
                        "#D55E00", "#F0E442", "#000000", "#999999"]
CORE_OTHER_COLOR = "#F2F2F2"
UNKNOWN = "(not recorded)"


@dataclass
class Switch:
    """A switchable reclassification rule (a pack rule that recounts flagged lines as another product)."""
    field: str
    values: list
    default: str
    flag_column: str
    on_value: str
    set_product: str
    add_tag: str = ""
    note: str = ""
    label: str = ""
    title_words: dict = field(default_factory=dict)   # {value: words a title adds} for a value not the default
    words: list = field(default_factory=list)         # question words that name the rule's subject
    value_words: dict = field(default_factory=dict)   # {value: [phrases]} that, with `words`, choose that value


@dataclass
class Pack:
    company_name: str = ""
    description: str = ""
    caption_description: str = ""
    products: list = field(default_factory=list)
    work_types: list = field(default_factory=list)
    tag_groups: list = field(default_factory=list)   # [{key, name, tags:[name], words:[phrase]}]
    families: list = field(default_factory=list)     # [{name, products:[name], words:[phrase]}]
    salespeople: list = field(default_factory=list)
    salesperson_names: dict = field(default_factory=dict)   # {initials: [first names]}
    not_recorded: str = UNKNOWN
    areas: list = field(default_factory=list)
    aliases: dict = field(default_factory=dict)
    places: dict = field(default_factory=dict)  # {town: (lat, lon)} for maps
    zips: dict = field(default_factory=dict)  # {zip5: short name} the map can draw
    territory_splits: list = field(default_factory=list)  # ["safety_site", "safety_council"]
    place_splits: list = field(default_factory=list)  # ["tax_area", "coast_band"]
    regions: dict = field(default_factory=dict)  # {region: [towns]} -- every town in one
    region_words: dict = field(default_factory=dict)  # {phrase: [regions and/or towns]}
    colors: dict = field(default_factory=dict)
    fallback_colors: list = field(default_factory=lambda: list(CORE_FALLBACK_COLORS))
    other_color: str = CORE_OTHER_COLOR
    switches: list = field(default_factory=list)
    include_unpaid_default: bool = True
    bundle_product: str = ""                          # the product a bundled line starts as
    drill_min_jobs: int = 0                           # rules.yml; 0 = Core's compute.MIN_JOBS
    prospect: dict = field(default_factory=dict)      # rules.yml charts.prospect -- product lines, their job
                                                      # families, words and default weights; the origin
    text: dict = field(default_factory=dict)          # the pack's prompt wording
    source: str = ""

    # ------------------------------------------------------------ vocabulary
    @property
    def configured(self):
        return bool(self.products)

    @property
    def tags(self):
        return [t for g in self.tag_groups for t in g["tags"]]

    @property
    def offered_place_splits(self):
        """The pack's place splits this region can answer (no coast splits without a coastline)."""
        coast = _coast_known()
        return [s for s in self.place_splits if coast or s not in COAST_SPLITS]

    @property
    def splits(self):
        return CORE_SPLITS + list(self.territory_splits) + list(self.offered_place_splits) + \
            ["tag_" + g["key"] for g in self.tag_groups]

    # ------------------------------------------------------------ regions
    def region_of_area(self, area):
        """The region a town is in ("(other)" when the pack lists the town in none, or the town is unknown)."""
        key = str(area or "").casefold().strip()
        if not key:
            return "(other)"
        table = getattr(self, "_region_by_area", None)
        if table is None:
            table = {}
            for region, towns in (self.regions or {}).items():
                for t in towns:
                    for v in self.area_variants(t):
                        table[str(v).casefold()] = region
            self._region_by_area = table
        if key in table:
            return table[key]
        if "/" in key:                    # a ZIP label naming two places ("North Shore / Sandy Point"): the first drawn one
            for part in key.split("/"):
                if part.strip() in table:
                    return table[part.strip()]
        return "(other)"

    def regions_and_towns_of(self, words):
        """What a list of region words / names means, split two ways: (region names, bare towns) -- a word may name
        towns straight ("east of the highway" lists Oakdale … as well as East Region and Backlands)."""
        regions, towns = [], []
        for w in words or []:
            w = re.sub(r"^\s*the\s+", "", str(w or "").strip(), flags=re.I)
            key = w.casefold()
            meant = None
            if key in {r.casefold() for r in self.regions}:
                meant = [r for r in self.regions if r.casefold() == key]
            else:
                for phrase, m in (self.region_words or {}).items():
                    if str(phrase).casefold() == key:
                        meant = list(m)
                        break
            for m in meant or []:
                if m in self.regions:
                    if m not in regions:
                        regions.append(m)
                elif m not in towns:
                    towns.append(m)
        return regions, towns

    def region_names_of(self, words):
        """The region names a list of region words / names means (unknown words dropped; order kept)."""
        out = []
        for w in words or []:
            w = re.sub(r"^\s*the\s+", "", str(w or "").strip(), flags=re.I)
            key = w.casefold()
            hits = []
            if key in {r.casefold() for r in self.regions}:
                hits = [r for r in self.regions if r.casefold() == key]
            else:
                for phrase, meant in (self.region_words or {}).items():
                    if str(phrase).casefold() == key:
                        hits = [m for m in meant if m in self.regions]
                        break
            for h in hits:
                if h not in out:
                    out.append(h)
        return out

    def areas_of_regions(self, words):
        """Every town a list of region words / names covers (a word may name towns straight, "east of the highway")."""
        towns = []
        for w in words or []:
            w = re.sub(r"^\s*the\s+", "", str(w or "").strip(), flags=re.I)
            key = w.casefold()
            meant = None
            if key in {r.casefold() for r in self.regions}:
                meant = [r for r in self.regions if r.casefold() == key]
            else:
                for phrase, m in (self.region_words or {}).items():
                    if str(phrase).casefold() == key:
                        meant = list(m)
                        break
            for m in meant or []:
                for t in (self.regions.get(m) or [m]):
                    if t not in towns:
                        towns.append(t)
        return towns

    def tag_group_for_split(self, split):
        for g in self.tag_groups:
            if split == "tag_" + g["key"]:
                return g["name"]
        return None

    def area_variants(self, area):
        """The proper name plus the misspellings the data still holds."""
        return [area] + list(self.aliases.get(area, []))

    def switch_defaults(self):
        return {s.field: s.default for s in self.switches}

    # ------------------------------------------------------------ colours
    def color_for(self, groups):
        """Fixed colour per known name, then colour-blind-safe fallbacks."""
        used, out = set(), []
        fallback = iter(list(self.fallback_colors))
        for group in groups:
            color = self.colors.get(group)
            if group == "All other":
                color = self.other_color
            if color is None or (color in used and group not in self.colors):
                color = next((c for c in fallback if c not in used), "#BBBBBB")
            used.add(color)
            out.append(color)
        return out

    # ------------------------------------------------------------ prompts
    def _fill(self, template, values):
        def sub(m):
            key = m.group(1)
            if key not in values:
                raise KeyError(f"template placeholder {{{{{key}}}}} has no value")
            return values[key]
        return re.sub(r"\{\{(\w+)\}\}", sub, template)

    def spec_prompt(self):
        t = self.text
        words = {**CORE_MEASURE_WORDS, **t.get("measure_words", {})}
        split_parts = []
        for s in self.splits:
            hint = CORE_SPLIT_HINTS.get(s)
            split_parts.append(f"{s} {hint}" if hint else s)
        values = {
            "company_name": self.company_name,
            "company_description": self.description,
            "followup_examples": t.get("followup_examples", '"now by month", "make it a line"'),
            "data_coverage": t.get("data_coverage", "Invoices up to TODAY."),
            "measure_lines": "\n".join(f"{m:<9} {words[m]}" for m in MEASURES),
            "split_line": " | ".join(split_parts),
            "product_line": " | ".join(self.products),
            "work_type_line": " | ".join(self.work_types),
            "work_type_words": t.get("work_type_words", ""),
            "vocabulary": t.get("vocabulary", "(none)"),
            "salespeople_line": ", ".join(self.salespeople),
            "not_recorded": self.not_recorded,
            "lead_source_groups": t.get("lead_source_groups", "(as recorded)"),
            "salespeople_note": t.get("salespeople_note", ""),
            "salesperson_names_rule": self._names_rule(),
            "town_example_line": ", ".join(t.get("town_examples", self.areas[:12])),
            "town_rules": t.get("town_rules", ""),
            "assumption_examples": t.get("assumption_examples", '"All history (no dates given)"'),
            "title_example": t.get("title_example", '"Revenue by year"'),
            "examples": t.get("examples", ""),
        }
        return self._fill((TEMPLATES / "spec_system_prompt.tmpl").read_text(), values)

    NO_NAMES_RULE = 'If the user gives a first name, don\'t guess the initials: use intent "clarify".'

    def _names_rule(self):
        if not self.salesperson_names:
            return self.NO_NAMES_RULE
        by_name = {}
        for i, names in self.salesperson_names.items():
            for n in names:
                by_name.setdefault(n, []).append(i)
        pairs = "; ".join(f"{n} = {' or '.join(i)}" for n, i in by_name.items())
        return (f"Names: {pairs}. Use those initials when the user names one of these people. "
                "For any other name, don't guess the initials: use intent \"clarify\".")

    def names_in(self, question):
        """People named in a question, as [(name, initials)], in the order written.
        Whole words only, any case, with or without a possessive ('s)."""
        found = []
        for initials, names in self.salesperson_names.items():
            for n in names:
                m = re.search(r"(?<![\w])" + re.escape(n) + r"(?:['\u2019]s)?(?![\w])", question, re.I)
                if m:
                    found.append((m.start(), len(found), n, initials))
        seen, out = set(), []
        for _, _, n, i in sorted(found):
            if i not in seen:
                seen.add(i)
                out.append((n, i))
        return out

    def caption_prompt(self):
        t = self.text
        values = {
            "company_name": self.company_name,
            "caption_company_description": self.caption_description or self.description,
            "caption_estimate_example": t.get("caption_estimate_example", '"Unit counts include estimates"'),
            "caption_cause_example": t.get("caption_cause_example", '"because of the season"'),
        }
        return self._fill((TEMPLATES / "caption_prompt.tmpl").read_text(), values)

    # ------------------------------------------------------------ schema
    def schema(self):
        tmpl = json.loads((TEMPLATES / "chart_spec.schema.tmpl.json").read_text())
        enums = {
            "@products": list(self.products), "@work_types": list(self.work_types),
            "@salespeople": list(self.salespeople) + [self.not_recorded],
            "@areas": list(self.areas), "@tags": self.tags, "@splits": self.splits,
        }

        def walk(node):
            if isinstance(node, dict):
                return {k: walk(v) for k, v in node.items()}
            if isinstance(node, list):
                return [walk(v) for v in node]
            if isinstance(node, str) and node in enums:
                return copy.deepcopy(enums[node])
            return node

        out = walk(tmpl)
        props, new = out["schema"]["properties"], {}
        for k, v in props.items():
            if k == "@switches":
                for s in self.switches:
                    new[s.field] = {"type": "string", "enum": list(s.values)}
            else:
                new[k] = v
        out["schema"]["properties"] = new
        req = []
        for r in out["schema"]["required"]:
            req.extend([s.field for s in self.switches] if r == "@switches" else [r])
        out["schema"]["required"] = req
        return out

    # ------------------------------------------------------------ vocab op
    def vocab(self):
        return {
            "measures": MEASURES, "splits": self.splits,
            "split_labels": {**{s: s.replace("_", " ").capitalize() for s in CORE_SPLITS}, **{"payment": "Paid or unpaid"},
                             **{s: _region_label(s, TERRITORY_LABELS) for s in self.territory_splits},
                             **{s: _region_label(s, PLACE_LABELS) for s in self.offered_place_splits},
                             **{"tag_" + g["key"]: g["name"] for g in self.tag_groups}},
            "time_grains": TIME_GRAINS, "chart_types": CHART_TYPES,
            "regions": [{"name": r, "towns": list(t)} for r, t in (self.regions or {}).items()],       
            "region_words": {w: list(m) for w, m in (self.region_words or {}).items()},
            "products": self.products, "work_types": self.work_types,
            "tag_groups": [{"key": g["key"], "name": g["name"], "tags": g["tags"]}
                           for g in self.tag_groups],
            "areas": self.areas,
            "salespeople": self.salespeople + [self.not_recorded],
            "switches": [{"field": s.field, "values": s.values, "default": s.default,
                          "label": s.label} for s in self.switches],
            "colors": dict(self.colors), "other_color": self.other_color,
            "fallback_colors": list(self.fallback_colors),
            "include_unpaid_default": self.include_unpaid_default,
            "ask_example": self.text.get("ask_example", ""),
        }


def _region_label(split, defaults):
    """The region pack's label for a place split, else the default."""
    try:
        from . import region as R
        lab = R.current().labels.get(split)
    except Exception:
        lab = None
    return lab or defaults.get(split, split)


def _names(parties):
    """parties.yml charts.salesperson_names: {initials: [names]}. Only initials
    already listed under salespeople count; names must be plain words."""
    known = set(parties.get("salespeople", []))
    out = {}
    for initials, names in (parties.get("salesperson_names") or {}).items():
        if initials not in known:
            continue
        if isinstance(names, str):
            names = [names]
        good = [str(n).strip() for n in names or [] if re.fullmatch(r"[A-Za-z][A-Za-z .'-]{0,39}", str(n).strip())]
        if good:
            out[initials] = good
    return out


def _switch(raw):
    s = Switch(**raw)
    tw = s.title_words if isinstance(s.title_words, dict) else {}
    s.title_words = {str(k): str(v)[:60] for k, v in tw.items() if k in s.values and isinstance(v, str) and v.strip()}
    s.words = [str(w).casefold() for w in (s.words if isinstance(s.words, list) else [])]
    vw = s.value_words if isinstance(s.value_words, dict) else {}
    s.value_words = {str(k): [str(p).casefold() for p in (v or [])] for k, v in vw.items()
                     if k in s.values and isinstance(v, list)}
    # Core convention: switch fields end in _mode, so the web side can keep
    # them in saved specs without knowing any pack's rule names.
    if not re.fullmatch(r"[a-z_]{1,30}_mode", s.field) or s.default not in s.values \
            or s.on_value not in s.values:
        raise ValueError(f"bad switch definition: {s.field}")
    return s


def _min_jobs(raw):
    """rules.yml charts.drill_min_jobs: a whole number 1-1000, else 0 (Core default)."""
    if isinstance(raw, bool) or not isinstance(raw, int) or not 1 <= raw <= 1000:
        return 0
    return raw


def _places(raw):
    """town centres for the map, {name: (lat, lon)}. Anything that isn't a pair of
    numbers on the globe is left out, so a typo in the pack drops one town, not the map."""
    out = {}
    for name, v in (raw or {}).items():
        if not isinstance(v, (list, tuple)) or len(v) != 2 or \
                not all(isinstance(x, (int, float)) and not isinstance(x, bool) for x in v):
            continue                             # "40.1, -100.1" as one string is not a place
        lat, lon = float(v[0]), float(v[1])
        if -90 <= lat <= 90 and -180 <= lon <= 180:
            out[str(name)] = (round(lat, 5), round(lon, 5))
    return out


def _prospect(raw):
    """rules.yml charts.prospect, checked: products {key: {label, families, words, weights}},
    default_weights, hung_within_days, origin {street, zip, label}. Anything malformed is dropped, never fatal."""
    if not isinstance(raw, dict):
        return {}
    out = {}
    prods = {}
    for k, v in (raw.get("products") or {}).items():
        if not isinstance(v, dict):
            continue
        key = re.sub(r"[^a-z0-9_]", "_", str(k).strip().lower())
        prods[key] = {"label": str(v.get("label") or key.replace("_", " ")),
                      "families": [str(f) for f in (v.get("families") or [])],
                      "words": [str(w).casefold() for w in (v.get("words") or [])],
                      "weights": {str(a): float(b) for a, b in (v.get("weights") or {}).items()
                                  if isinstance(b, (int, float))}}
    out["products"] = prods
    dw = raw.get("default_weights")
    if isinstance(dw, dict):
        out["default_weights"] = {str(a): float(b) for a, b in dw.items() if isinstance(b, (int, float))}
    if isinstance(raw.get("hung_within_days"), int):
        out["hung_within_days"] = raw["hung_within_days"]
    o = raw.get("origin")
    if isinstance(o, dict) and o.get("street") and o.get("zip"):
        out["origin"] = {"street": str(o["street"]), "zip": str(o["zip"])[:5], "label": str(o.get("label") or "the shop")}
    return out


def load_pack(folder):
    """Read the Charts section of each pack file. Missing files are fine."""
    folder = Path(folder) if folder else None
    raw = {}
    for name in PACK_FILES:
        path = folder / f"{name}.yml" if folder else None
        data = yaml.safe_load(path.read_text()) if path and path.exists() else {}
        raw[name] = data or {}
    ch = {k: (v.get("charts") or {}) for k, v in raw.items()}
    catalog, parties, terr, maps, rules = (ch["catalog"], ch["parties"], ch["territories"],
                                            ch["mappings"], ch["rules"])
    colors = {}
    for item in catalog.get("products", []) + catalog.get("work_types", []):
        if item.get("color"):
            colors[item["name"]] = item["color"]
    groups = []
    for g in catalog.get("tag_groups", []):
        for t in g.get("tags", []):
            if t.get("color"):
                colors[t["name"]] = t["color"]
        groups.append({"key": g["key"], "name": g["name"],
                       "tags": [t["name"] for t in g.get("tags", [])],
                       "words": [str(w).casefold() for w in (g.get("words") or [])]})
    text = dict(maps)
    text["data_coverage"] = rules.get("data_coverage", "")
    ls = folder / "lead_sources.yml" if folder else None          
    if ls and ls.exists():
        grp = list(((yaml.safe_load(ls.read_text()) or {}).get("groups") or {}).keys())
        from .lead_sources import NEW_UNKNOWN                     # one name, from lead_sources
        text["lead_source_groups"] = " | ".join(grp + ["Existing Customer - from history", NEW_UNKNOWN, "Other"])
    text["salespeople_note"] = parties.get("note", "")
    text["town_examples"] = terr.get("prompt_examples") or []
    text["town_rules"] = terr.get("prompt_rules", "")
    if not text["town_examples"]:
        text.pop("town_examples")
    pack = Pack(
        company_name=raw["profile"].get("name", ""),
        description=ch["profile"].get("description", ""),
        caption_description=ch["profile"].get("caption_description", ""),
        products=[p["name"] for p in catalog.get("products", [])],
        work_types=[w["name"] for w in catalog.get("work_types", [])],
        tag_groups=groups,
        families=[{"name": str(f.get("name") or ""), "products": [str(p) for p in (f.get("products") or [])],
                   "words": [str(w).casefold() for w in (f.get("words") or [])]}
                  for f in (catalog.get("families") or []) if isinstance(f, dict) and f.get("name") and f.get("products")],
        salespeople=list(parties.get("salespeople", [])),
        salesperson_names=_names(parties),
        not_recorded=parties.get("not_recorded", UNKNOWN),
        areas=list(terr.get("areas", [])),
        aliases={k: list(v) for k, v in (terr.get("aliases") or {}).items()},
        places=_places(terr.get("places")),
        territory_splits=[x for x in (terr.get("territory_splits") or []) if x in TERRITORY_LABELS],
        place_splits=[x for x in (terr.get("place_splits") or []) if x in PLACE_LABELS],
        regions={str(k): [str(t) for t in (v or [])] for k, v in (terr.get("regions") or {}).items()},
        # keys lose a leading "the" like every lookup does ("the mountains" never matched "mountains")
        region_words={re.sub(r"^\s*the\s+", "", str(k).strip(), flags=re.I).casefold(): [str(t) for t in (v or [])]
                      for k, v in (terr.get("region_words") or {}).items()},
        zips={str(k).zfill(5): str(v or "") for k, v in (terr.get("zips") or {}).items()
              if str(k).isdigit() and len(str(k).zfill(5)) == 5},
        colors=colors,
        fallback_colors=catalog.get("fallback_colors") or list(CORE_FALLBACK_COLORS),
        other_color=catalog.get("other_color", CORE_OTHER_COLOR),
        switches=[_switch(s) for s in rules.get("switches", [])],
        include_unpaid_default=bool(rules.get("include_unpaid_default", True)),
        bundle_product=catalog.get("bundle_product", ""),
        drill_min_jobs=_min_jobs(rules.get("drill_min_jobs")),
        prospect=_prospect(rules.get("prospect")),
        text=text,
        source=str(folder or ""),
    )
    return pack
