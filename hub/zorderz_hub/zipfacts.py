"""What each ZIP is like; the ZIP areas and the hazard layer are the region pack's.

Homes and owners, people and income, hazard zones and the age of the homes; in Charts, ask by them, shade the ZIP
map by them, rate sales per 1,000 homes and show a ZIP's profile when it's tapped.

Sources, all public and free, no account or key:
  * US Census Bureau, American Community Survey 5-year estimates, table-based summary files: one pipe-separated
    file per table, every geography of the country, sorted by GEO_ID. A ZIP's row is "860Z200US00011|...". The
    hub reads only the ZIP rows of the service area by asking for parts of each file (HTTP Range): about 15
    small requests per table, not the 20-50 MB file.
  * US Census Bureau Gazetteer, ZIP Code Tabulation Areas: land area (for people per square mile). 1 MB.
  * the region pack's hazard layer (optional): zones with a class, of which the region names its top two
    ("very_high" and "high" in region.json) and what they are called (labels.hazard_vh / hazard_h / hazard).
    Only the zones inside the service area's rectangle are asked for, simplified to about 40 m.

What leaves this computer: the Census files are fetched by byte ranges. The ZIP range is found by reading the
file's own sorted rows and is never written in a request, but the last range read brackets the service area's
rows, so census.gov could tell which stretch of ZIP codes was read. The hazard request carries the rectangle
around the region's ZIP areas. No customer, job, address or single ZIP is ever sent.

Census numbers are survey estimates with margins of error; for a ZIP of a few hundred people they are wide.
Charts says so when a small ZIP is on a chart, and never rates sales over fewer than MIN_HOMES homes.

Kept beside the store (an import never wipes it):
    <store folder>/zipfacts/facts.json    each ZIP's facts, the sources and when they were read
    <store folder>/zipfacts/status.json   the last run: when, what it read, the last error
"""
import datetime as dt
import io
import json
import logging
import math
import re
import shutil
import ssl
import subprocess
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile
import zlib
from pathlib import Path

import numpy as np

from . import polite as P
from . import region as R

_log = logging.getLogger("zorderz_hub.zipfacts")

ACS_BASE = "https://www2.census.gov/programs-surveys/acs/summary_file"
GAZ_BASE = "https://www2.census.gov/geo/docs/maps-data/data/gazetteer"
HAZARD_LABEL = "Hazard zones"                 # the region's hazard layer, when it has no label of its own
ACS_FIRST_YEAR = 2024                 # the first release read in this format here; newer ones are found by trying
ZCTA = "860Z200US"                    # GEO_ID prefix of a ZIP Code Tabulation Area
AT_DEFAULT = (4, 20)                  # local; after the weather's 03:40
EVERY_DAYS = 30                       # facts older than this are read again (a new ACS year comes each December)
FIRST_DELAY = 600                     # seconds after the hub starts
RETRY_HOURS = 20                      # a failed part is tried again the next day
PAUSE = 1.0                           # seconds between requests
BUDGET = 800                          # requests a run at most (a normal run: about 180)
MAX_RUN_HOURS = 1                     # a run still going after this stops; what was read is kept
TIMEOUT = 90
MAX_BYTES = 30 * 1024 * 1024
PEEK = 4096                           # bytes read at each step of the search
MAX_LINE = 1024 * 1024                # a longer line means the file isn't what it should be
CLOSE_ENOUGH = 32 * 1024              # stop searching when the window is this small, then read it whole
MAX_BLOCK = 6 * 1024 * 1024           # the service area's rows of one table, with margin
CELL = 0.0015                         # degrees: the fire-zone grid (about 140 x 165 m here)
SIMPLIFY = 0.0004                     # degrees: the hazard polygons simplified on the server (about 40 m)
HAZARD_PAGE = 1000
HAZARD_MAX_PAGES = 60
MIN_HOMES = 200                       # fewer homes than this: no rate (one job would swing it wildly)
SMALL_PEOPLE = 1000                   # fewer people than this: "Census figures are rough here"
UA = "zorderz-hub/{v} (ZIP facts; Census ACS + hazard zones; one service area, monthly)"
ZIP_RE = re.compile(r"^\d{5}$")

# table -> the columns kept (estimates; the M twin is the margin of error)
TABLES = {
    "B01003": ["E001"],                                   # total population
    "B11001": ["E001"],                                   # households
    "B25001": ["E001"],                                   # housing units
    "B25003": ["E001", "E002"],                           # occupied homes; owner-occupied
    "B25024": ["E001", "E002"],                           # homes; 1-unit detached
    "B25077": ["E001"],                                   # median home value
    "B25035": ["E001"],                                   # median year built
    "B25034": ["E001", "E002", "E003", "E004", "E005", "E006", "E007", "E008", "E009", "E010", "E011"],
    "B19013": ["E001"],                                   # median household income
    "B01002": ["E001"],                                   # median age
    # sex by age; 65+ is men E020-E025, women E044-E049
    "B01001": ["E001", "E020", "E021", "E022", "E023", "E024", "E025", "E044", "E045", "E046", "E047", "E048", "E049"],
}
# B25034: E002 2020+, E003 2010-19, E004 2000-09, E005 1990-99, E006 1980-89, E007 1970-79, E008 1960-69,
# E009 1950-59, E010 1940-49, E011 1939 or earlier (ACS 2020-2024 table shells)

# key: (label, unit, what it is). Units: count, pct, money, year, years, sqmi, per_sqmi.
FACTS = {
    "population": ("People", "count", "Total population (ACS)"),
    "households": ("Households", "count", "Occupied homes (ACS)"),
    "homes": ("Homes", "count", "Housing units, lived in or not (ACS)"),
    "owner_pct": ("Owner-occupied", "pct", "Share of occupied homes lived in by their owner (ACS)"),
    "single_family_pct": ("Single-family detached", "pct", "Share of homes that are one-unit detached houses (ACS)"),
    "home_value": ("Median home value", "money", "Median value of owner-occupied homes (ACS)"),
    "year_built": ("Median year built", "year", "Median year the homes were built (ACS)"),
    "pre1980_pct": ("Built before 1980", "pct", "Share of homes built before 1980 (ACS)"),
    "built_1980s_90s_pct": ("Built 1980–1999", "pct", "Share of homes built 1980 to 1999 (ACS)"),
    "built_2000_pct": ("Built 2000 or later", "pct", "Share of homes built in 2000 or later (ACS)"),
    "income": ("Median household income", "money", "Median household income, past 12 months (ACS)"),
    "age": ("Median age", "years", "Median age of the people (ACS)"),
    "age65_pct": ("Aged 65 or older", "pct", "Share of the people aged 65 or older (ACS)"),
    "land_sqmi": ("Land area", "sqmi", "Land area in square miles (Census Gazetteer)"),
    "density": ("People per sq mi", "per_sqmi", "People per square mile of land"),
    # the hazard layer's classes: what they are called is the region's (labels.hazard_vh / hazard_h / hazard)
    "fire_vh_pct": ("In the top hazard class", "pct", "Share of the ZIP's area in the region's top hazard class"),
    "fire_h_pct": ("In the second hazard class", "pct", "Share of the ZIP's area in the region's second hazard class"),
    "fire_pct": ("In the top two hazard classes", "pct", "Share of the ZIP's area in the region's top two hazard "
                                                         "classes"),
    # the place facts (places.py), merged in by Store.zip_facts()
    "coast_mi": ("Miles to the coast", "miles", "Median straight-line miles from the ZIP's homes to the ocean or a bay "
                                                "(Census coastline)"),
    "burned_pct": ("Homes inside a past wildfire", "pct", "Share of the ZIP's homes inside a wildfire perimeter since "
                                                          "1950 (the region's fire history)"),
    "fire_count": ("Wildfires since 1950", "count", "Wildfires since 1950 whose perimeter reached the ZIP (the region's fire "
                                                    "history)"),
    "last_fire_year": ("Last wildfire", "year", "The most recent year a wildfire perimeter reached the ZIP"),
    # what the vector feed looks for is the region's (labels.vector, vector.species)
    "vector_q8": ("Watched species found", "quarters", "Of the last 8 quarters, how many found one of the region's "
                                                       "watched species in the ZIP's surveillance zone"),
    "tax_rate": ("Sales tax rate", "rate", "The sales tax rate where most of the ZIP's homes are (the region's tax "
                                           "areas)"),
}
PLACE_KEYS = ("coast_mi", "burned_pct", "fire_count", "last_fire_year", "vector_q8", "tax_rate")
ACS_KEYS = ("population", "households", "homes", "owner_pct", "single_family_pct", "home_value", "year_built",
            "pre1980_pct", "built_1980s_90s_pct", "built_2000_pct", "income", "age", "age65_pct")
GAZ_KEYS = ("land_sqmi", "density")
FIRE_KEYS = ("fire_vh_pct", "fire_h_pct", "fire_pct")
# what a rate is per: key -> (fact, words)
BASES = {"homes": ("homes", "1,000 homes"), "households": ("households", "1,000 households"),
         "people": ("population", "1,000 people")}


class FactsError(Exception):
    pass


def folder_of(cfg):
    return Path(cfg["store"]).with_name("zipfacts")


def settings(cfg):
    z = dict(cfg.get("zipfacts") or {})
    at = str(z.get("at", "%02d:%02d" % AT_DEFAULT))
    try:
        at_t = tuple(int(x) for x in at.split(":")[:2])
    except ValueError:
        at_t = AT_DEFAULT
    reg = R.load(cfg)
    hazard = reg.layer("hazard")
    if hazard is not None and not any(hazard_classes(hazard).values()):
        hazard = None                                             # a hazard layer whose classes the region doesn't name
    return {
        "mode": str(z.get("mode", "on")).lower(),                 # on | off (no fetching, no answers)
        "fetch": str(z.get("fetch", "auto")).lower(),             # auto | python | curl
        "fire": str(z.get("fire", "on")).lower() if hazard is not None else "off",   # on | off (no hazard layer)
        "acs_base": str(z.get("acs_base") or ACS_BASE).rstrip("/"),
        "gaz_base": str(z.get("gaz_base") or GAZ_BASE).rstrip("/"),
        "hazard": hazard,
        "every_days": max(1, int(z.get("every_days", EVERY_DAYS) or EVERY_DAYS)),
        "at": at_t,
    }


# how a fact reads inside a sentence
NOUNS = {"owner_pct": "owner-occupied share", "single_family_pct": "single-family share",
         "pre1980_pct": "share built before 1980", "built_1980s_90s_pct": "share built 1980–1999",
         "built_2000_pct": "share built 2000 or later", "fire_vh_pct": "share in the top hazard class",
         "fire_h_pct": "share in the second hazard class", "fire_pct": "share in the top two hazard classes",
         "density": "people per square mile", "land_sqmi": "land area", "population": "population",
         "homes": "number of homes", "households": "number of households", "age65_pct": "share aged 65 or older",
         "coast_mi": "miles to the coast", "burned_pct": "share of homes inside a past wildfire",
         "fire_count": "number of wildfires since 1950", "last_fire_year": "year of the last wildfire",
         "vector_q8": "quarters a watched species was found", "tax_rate": "sales tax rate"}

# The facts whose words are the region's: fact key -> the region pack's label key.
REGION_WORDED = {"fire_vh_pct": "hazard_vh", "fire_h_pct": "hazard_h", "fire_pct": "hazard", "vector_q8": "vector"}


def _region_word(key):
    """The region pack's label for a fact it words itself ("In a Very High fire zone"), or ""."""
    lk = REGION_WORDED.get(key)
    if not lk:
        return ""
    try:
        return str(R.current().labels.get(lk) or "").strip()
    except Exception:                       # no region loaded: Core's words
        return ""


def noun_of(key):
    lab = _region_word(key)
    if lab:
        low = lab[0].lower() + lab[1:]
        if key == "vector_q8":
            return "quarters with " + low
        return ("share " + low) if low.startswith("in ") else low
    return NOUNS.get(key) or label_of(key).lower()


def label_of(key):
    return _region_word(key) or (FACTS[key][0] if key in FACTS else key)


def unit_of(key):
    return FACTS[key][1] if key in FACTS else "count"


def fmt(v, unit, short=False):
    """A fact as people say it: "$812,000", "64%", "1986", "41.4 years", "1,250 per sq mi"."""
    if v is None:
        return "—"
    v = float(v)
    if unit == "money":
        if short and abs(v) >= 10000:
            return f"${v / 1000:,.0f}k"
        return f"${v:,.0f}"
    if unit == "pct":
        return f"{v:.0f}%" if abs(v) >= 10 or v == 0 else f"{v:.1f}%"
    if unit == "year":
        return f"{v:.0f}"
    if unit == "years":
        return f"{v:.1f} years"
    if unit == "sqmi":
        return f"{v:,.1f} sq mi"
    if unit == "per_sqmi":
        return f"{v:,.0f} per sq mi"
    if unit == "miles":                                   
        return f"{v:,.1f} mi" if v < 100 else f"{v:,.0f} mi"
    if unit == "acres":
        return f"{v:,.2f} acres" if v < 10 else f"{v:,.1f} acres"
    if unit == "rate":
        return f"{v:.2f}%"
    if unit == "quarters":
        return f"{v:.0f} of 8" if not short else f"{v:.0f}"
    return f"{v:,.0f}"


# ---------------------------------------------------------------- fetching
def _as_reply(out, rng):
    """(status, headers, body) from a request, or from a test's (body, total size) stand-in."""
    if len(out) == 3:
        return out
    body, total = out
    return (206 if rng else 200), ({"content-range": f"bytes 0-0/{total}"} if total else {}), body


class _Unchanged(Exception):
    """A part whose source hasn't changed since it was read (not an error)."""


class Fetcher:
    """HTTPS GETs, optionally for a byte range, through polite.request: one at a time per server, about a second
    apart, a busy server waited for and asked again, at most `budget` requests a run. A python.org Python on
    macOS may lack root certificates until its "Install Certificates" step has run, so on a certificate error
    the system curl is used (as weather.py does), and kept for the rest of the run."""

    def __init__(self, mode="auto", version="", pause=PAUSE, opener=None, cfg=None, budget=BUDGET, say=None):
        self.mode, self.pause = mode, pause
        self.ua = P.user_agent(UA.format(v=version or "?"), cfg)
        self.opener = opener                  # tests: a function (url, (a, b) | None) -> (bytes, total | None)
        self.used = mode if mode in ("python", "curl") else ""
        self.requests = 0
        self.bytes = 0
        self.budget = budget
        self.say = say
        self.deadline = None                  # time.monotonic() after which no more requests are made

    def _count(self, url):
        if self.deadline is not None and time.monotonic() > self.deadline:
            raise FactsError("stopped: this run went past its time limit (it keeps what is on file and tries "
                             "again later)")
        if self.budget is not None and self.requests >= self.budget:
            raise FactsError(f"stopped after {self.requests} requests (this run's limit; nothing more is asked of "
                             f"{_host(url)})")
        self.requests += 1

    def _raw(self, url, rng=None, timeout=TIMEOUT, limit=MAX_BYTES, data=None, content_type=None):
        """One try: (status, headers, body). Raises polite.Trouble."""
        if str(url).startswith("file:"):              # a region pack's own file layer: answered here
            return self._local(url, data)
        self._count(url)
        if self.opener is not None:
            return _as_reply(self.opener(url, rng), rng)
        extra = {"data": data, "content_type": content_type} if data is not None else {}
        if self.used != "curl":
            try:
                out = self._python(url, rng, timeout, limit, **extra)
                self.used = self.used or "python"
                return _as_reply(out, rng)
            except (P.Trouble, FactsError) as e:
                if not ((getattr(e, "kind", None) == "cert" or "certificate" in str(e).lower())
                        and self.mode == "auto" and shutil.which("curl")):
                    raise
                self.used = "curl"
        return _as_reply(self._curl(url, rng, timeout, limit, **extra), rng)

    def _python(self, url, rng, timeout, limit, data=None, content_type=None):
        return P.http(url, self.ua, timeout, limit, rng, data, content_type)

    def _curl(self, url, rng, timeout, limit, data=None, content_type=None):
        return P.http(url, self.ua, timeout, limit, rng, data, content_type, curl=True)

    @staticmethod
    def _local(url, data=None):
        from . import region as R
        q = url
        if data is not None:
            q = url + ("&" if "?" in url else "?") + bytes(data).decode("utf-8")
        try:
            return 200, {}, R.local_open(q)
        except (R.RegionError, OSError, ValueError) as e:
            raise FactsError(str(e)) from None

    def _polite(self, url, attempt):
        if str(url).startswith("file:"):              # no server to be polite to
            return attempt()
        try:
            return P.request(url, attempt, pause=self.pause, say=self.say)
        except P.Trouble as e:
            raise FactsError(e.message) from None

    def get(self, url, rng=None, timeout=TIMEOUT, limit=MAX_BYTES):
        """(bytes, total size or None). rng = (first byte, last byte), both included."""
        def attempt():
            status, headers, data = self._raw(url, rng, timeout, limit)
            if rng and status != 206:
                raise FactsError(f"{_host(url)} didn't answer a part request")
            if len(data) > limit:
                raise FactsError("reply too large")
            return data, _total_of(headers.get("content-range"))
        data, total = self._polite(url, attempt)
        self.bytes += len(data)
        return data, total

    def json(self, url, timeout=TIMEOUT, data=None, content_type=None):
        """A JSON reply; an ArcGIS "busy" error inside it is asked again like an HTTP 503."""
        def attempt():
            _, _, body = self._raw(url, None, timeout, MAX_BYTES, data, content_type)
            if len(body) > MAX_BYTES:
                raise FactsError("reply too large")
            self.bytes += len(body)
            try:
                out = json.loads(body.decode("utf-8"))
            except ValueError:
                raise FactsError(f"{_host(url)} answered something that isn't JSON") from None
            err = P.arcgis_error(out, url)
            if err is not None:
                if err.transient:
                    raise err
                raise FactsError(err.message)
            return out
        return self._polite(url, attempt)


def _host(url):
    return urllib.parse.urlsplit(url).hostname or "the server"


def _total_of(content_range):
    m = re.search(r"/(\d+)\s*$", str(content_range or ""))
    return int(m.group(1)) if m else None


# ---------------------------------------------------------------- Census ACS (by byte ranges)
def acs_url(base, year, table):
    return f"{base}/{year}/table-based-SF/data/5YRData/acsdt5y{year}-{table.lower()}.dat"


def acs_label(year):
    return f"Census ACS {int(year) - 4}–{year} 5-year estimates"


class SortedFile:
    """A remote text file of lines sorted by their first field, read in parts."""

    def __init__(self, fetcher, url):
        self.f, self.url = fetcher, url
        data, total = fetcher.get(url, (0, PEEK - 1))
        if not total:
            raise FactsError("census.gov didn't say how big the file is")
        self.size = total
        nl = data.find(b"\n")
        if nl < 0:
            raise FactsError("the Census file has no header line")
        self.header = data[:nl].decode("utf-8", "replace").strip().split("|")
        self.body_start = nl + 1

    def _first_key_after(self, off):
        """(the first whole line's key at or after byte off, where that line starts) or (None, size)."""
        if off <= self.body_start:
            data, _ = self.f.get(self.url, (self.body_start, min(self.size - 1, self.body_start + PEEK - 1)))
            return data.split(b"|", 1)[0].decode("ascii", "replace"), self.body_start
        at = off - 1
        for _ in range(MAX_LINE // PEEK):                  # a line longer than PEEK is read on
            data, _ = self.f.get(self.url, (at, min(self.size - 1, at + PEEK - 1)))
            nl = data.find(b"\n")
            if nl >= 0:
                break
            at += len(data)
            if not data or at >= self.size:
                return None, self.size                       # the last line: no line starts after off
        else:
            raise FactsError("a line in the Census file is longer than expected")
        start = at + nl + 1
        if start >= self.size:
            return None, self.size
        if b"|" not in data[nl + 1:] and b"\n" not in data[nl + 1:]:   # the next line's key runs past this part
            data, _ = self.f.get(self.url, (start, min(self.size - 1, start + PEEK - 1)))
            return data.split(b"|", 1)[0].decode("ascii", "replace"), start
        return data[nl + 1:].split(b"|", 1)[0].decode("ascii", "replace"), start

    def offset_of(self, key, lo=None):
        """A byte offset at or before the first line whose key is >= key (never after it). lo: a line start
        already known to be before it."""
        lo, hi = max(self.body_start, lo or 0), self.size
        while hi - lo > CLOSE_ENOUGH:
            mid = (lo + hi) // 2
            k, start = self._first_key_after(mid)
            if k is None or k >= key:
                hi = mid
            else:
                lo = start
        return lo

    def rows(self, key_from, key_to):
        """The lines with key_from <= key < key_to, as lists of fields. Reads on until a line at or past key_to
        (or the file's end) is in hand, so a long line can't hide the last rows."""
        a = self.offset_of(key_from)                  # always a line start (or the first line)
        b = min(self.size, self.offset_of(key_to, a) + CLOSE_ENOUGH + PEEK)
        while True:
            if b - a > MAX_BLOCK:
                raise FactsError("the Census file's ZIP rows are larger than expected")
            data, _ = self.f.get(self.url, (a, b - 1), limit=MAX_BLOCK + CLOSE_ENOUGH + PEEK)
            lines = data.split(b"\n")
            if a > self.body_start:
                lines = lines[1:]                     # a whole line before key_from (offset_of stops on one)
            if b < self.size:
                lines = lines[:-1]                    # a part line at the end
            keys = [ln.split(b"|", 1)[0].decode("ascii", "replace") for ln in lines if ln.strip()]
            if b >= self.size or any(k >= key_to for k in keys):
                break
            b = min(self.size, b + max(CLOSE_ENOUGH, b - a))
        out = []
        for raw in lines:
            line = raw.decode("utf-8", "replace").strip()
            if not line:
                continue
            fields = line.split("|")
            if key_from <= fields[0] < key_to:
                out.append(fields)
        return out


def _num(s):
    try:
        v = float(s)
    except (TypeError, ValueError):
        return None
    if v < 0 or math.isnan(v):            # -666666666 and the like: "not available" sentinels
        return None
    return v


def find_acs_year(fetcher, base, today):
    """The newest release that has the first table on census.gov (tried newest first)."""
    tried = []
    for y in range(today.year - 1, ACS_FIRST_YEAR - 1, -1):
        try:
            fetcher.get(acs_url(base, y, "B01003"), (0, 63))
            return y
        except FactsError as e:
            tried.append(f"{y}: {e}")
    raise FactsError("no ACS release found (" + "; ".join(tried[-2:]) + ")")


def read_acs(fetcher, base, year, zips):
    """{zip: {fact: value}}, {zip: {"population": margin, ...}} for the ZIPs asked about."""
    zs = sorted(zips)
    key_from, key_to = ZCTA + zs[0], ZCTA + "%05d" % (int(zs[-1]) + 1)
    want = set(zs)
    raw = {z: {} for z in zs}
    moe = {z: {} for z in zs}
    for table, cols in TABLES.items():
        sf = SortedFile(fetcher, acs_url(base, year, table))
        idx = {}
        for c in cols:
            for kind in ("E", "M"):
                name = f"{table}_{kind}{c[1:]}"
                if name in sf.header:
                    idx[(c, kind)] = sf.header.index(name)
                elif kind == "E":
                    raise FactsError(f"the Census changed table {table} (no {name})")
        for fields in sf.rows(key_from, key_to):
            z = fields[0][len(ZCTA):]
            if z not in want:
                continue
            for c in cols:
                i = idx[(c, "E")]
                raw[z][f"{table}_{c}"] = _num(fields[i]) if i < len(fields) else None
                j = idx.get((c, "M"))
                if c == "E001" and j is not None and j < len(fields):
                    moe[z][table] = _num(fields[j])
    out = {}
    for z in zs:
        r = raw[z]
        if not r:
            continue
        g = r.get
        f = {"population": g("B01003_E001"), "households": g("B11001_E001"), "homes": g("B25001_E001"),
             "home_value": g("B25077_E001"), "year_built": g("B25035_E001"), "income": g("B19013_E001"),
             "age": g("B01002_E001")}
        f["owner_pct"] = _pct(g("B25003_E002"), g("B25003_E001"))
        f["single_family_pct"] = _pct(g("B25024_E002"), g("B25024_E001"))
        tot = g("B25034_E001")
        parts = [g(f"B25034_E{n:03d}") for n in range(2, 12)]
        if tot and all(p is not None for p in parts):
            f["built_2000_pct"] = _pct(sum(parts[0:3]), tot)
            f["built_1980s_90s_pct"] = _pct(sum(parts[3:5]), tot)
            f["pre1980_pct"] = _pct(sum(parts[5:10]), tot)
        else:
            f["built_2000_pct"] = f["built_1980s_90s_pct"] = f["pre1980_pct"] = None
        old = [g(f"B01001_E{n:03d}") for n in (20, 21, 22, 23, 24, 25, 44, 45, 46, 47, 48, 49)]
        f["age65_pct"] = _pct(sum(old), g("B01001_E001")) if all(x is not None for x in old) else None
        if f.get("year_built") is not None and f["year_built"] < 1900:
            f["year_built"] = None
        out[z] = f
    m = {z: {"population": moe[z].get("B01003"), "income": moe[z].get("B19013"),
             "home_value": moe[z].get("B25077")} for z in out}
    return out, m


def _pct(a, b):
    if a is None or not b:
        return None
    return round(100.0 * a / b, 1)


# ---------------------------------------------------------------- Census Gazetteer (land area)
def read_gazetteer(fetcher, base, today, zips, newer_than=None):
    """({zip: land square miles}, year). with newer_than (the year on file), only newer years are looked
    for, each with a one-byte request first; None when there is none (the 1 MB file isn't downloaded again)."""
    tried = []
    stop = today.year - 4 if newer_than is None else max(today.year - 4, int(newer_than))
    for y in range(today.year, stop, -1):
        url = f"{base}/{y}_Gazetteer/{y}_Gaz_zcta_national.zip"
        if newer_than is not None:
            try:
                fetcher.get(url, (0, 0))
            except FactsError as e:
                tried.append(f"{y}: {e}")
                continue
        try:
            data, _ = fetcher.get(url, limit=8 * 1024 * 1024)
        except FactsError as e:
            tried.append(f"{y}: {e}")
            continue
        try:
            with zipfile.ZipFile(io.BytesIO(data)) as zf:
                name = next(n for n in zf.namelist() if n.lower().endswith(".txt"))
                text = zf.read(name).decode("utf-8", "replace")
        except (zipfile.BadZipFile, StopIteration, KeyError, OSError, ValueError, EOFError, zlib.error):
            tried.append(f"{y}: not a Gazetteer zip")
            continue
        lines = text.splitlines()
        head = [h.strip() for h in lines[0].split("\t")]
        if "GEOID" not in head or "ALAND_SQMI" not in head:
            tried.append(f"{y}: columns changed")
            continue
        gi, ai = head.index("GEOID"), head.index("ALAND_SQMI")
        out = {}
        want = set(zips)
        for line in lines[1:]:
            p = line.split("\t")
            if len(p) > max(gi, ai) and p[gi].strip() in want:
                out[p[gi].strip()] = _num(p[ai].strip())
        return out, y
    if newer_than is not None:
        return None
    raise FactsError("no Gazetteer found (" + "; ".join(tried[-2:]) + ")")


# ---------------------------------------------------------------- the region's hazard zones
def hazard_classes(lay):
    """{"vh": [class values], "h": [class values]}: the class values the region names for its hazard layer's top
    class ("very_high") and its second ("high"). Core assumes none: a layer whose region names no classes is not
    read (settings() turns the hazard facts off)."""
    c = dict((lay.spec.get("classes") if lay is not None else None) or {})
    vh = list(c.get("very_high")) if isinstance(c.get("very_high"), list) else []
    h = list(c.get("high")) if isinstance(c.get("high"), list) else []
    return {"vh": vh, "h": h}


def _lit(v):
    return str(int(v)) if isinstance(v, (int, float)) and not isinstance(v, bool) else "'" + str(v).replace("'", "''") + "'"


def hazard_query_url(lay, bounds_, offset):
    lat0, lat1, lon0, lon1 = bounds_
    cls = hazard_classes(lay)
    vals = ",".join(_lit(v) for v in cls["vh"] + cls["h"])
    q = {
        "where": lay.with_filter(f"CLASS IN ({vals})"),
        "geometry": json.dumps({"xmin": lon0, "ymin": lat0, "xmax": lon1, "ymax": lat1,
                                "spatialReference": {"wkid": 4326}}, separators=(",", ":")),
        "geometryType": "esriGeometryEnvelope", "inSR": "4326", "spatialRel": "esriSpatialRelIntersects",
        "outFields": lay.f("CLASS"), "returnGeometry": "true", "outSR": "4326",
        "maxAllowableOffset": str(SIMPLIFY), "geometryPrecision": "5",
        "orderByFields": lay.f("OBJECTID"), "resultOffset": str(offset), "resultRecordCount": str(HAZARD_PAGE),
    }
    if "arcgis.com" in lay.url:
        q["cacheHint"] = "true"            # Esri's advice for read-only, repeatable queries (polite.py)
    q["f"] = "json"
    return f"{lay.url}/query?" + urllib.parse.urlencode(q)


def read_hazard(fetcher, lay, bounds_, known=None):
    """{"vh": [rings per feature], "h": [...]}, {"layer": {"edited", ...}}. With `known` (the layer's info on
    file), returns None when its edit date and the rectangle are unchanged, after one small request (the zones
    aren't downloaded again)."""
    out = {"vh": [], "h": []}
    meta = fetcher.json(f"{lay.url}?f=json")
    fields = {str(f.get("name") or "").casefold() for f in meta.get("fields") or [] if isinstance(f, dict)}
    if fields and lay.f("CLASS").casefold() not in fields:
        raise FactsError(f"the region's hazard layer has no {lay.f('CLASS')} field")
    edited = (meta.get("editingInfo") or {}).get("lastEditDate")
    info = {"layer": {"url": lay.url if lay.kind != "file" else lay.spec.get("path"), "edited": edited,
                      "bounds": [round(float(x), 5) for x in bounds_],
                      "description": re.sub(r"<[^>]+>", " ", str(meta.get("description") or ""))[:200].strip()}}
    k = (known or {}).get("layer") or {}
    if known and edited is not None and k.get("edited") == edited and k.get("url") == info["layer"]["url"] \
            and k.get("bounds") == info["layer"]["bounds"]:
        return None
    cls = hazard_classes(lay)
    offset, n = 0, 0
    for _page in range(HAZARD_MAX_PAGES):
        got = fetcher.json(hazard_query_url(lay, bounds_, offset))
        feats = got.get("features") or []
        for ft in feats:
            c = lay.attrs(ft).get("CLASS")
            rings = (ft.get("geometry") or {}).get("rings") or []
            rings = [np.asarray(r, dtype=float)[:, :2] for r in rings if len(r) >= 3]
            if not rings:
                continue
            if c in cls["vh"]:
                out["vh"].append(rings)
            elif c in cls["h"]:
                out["h"].append(rings)
        n += len(feats)
        if not feats or not (got.get("exceededTransferLimit") or len(feats) >= HAZARD_PAGE):
            break
        offset += len(feats)
    else:
        raise FactsError("the region's hazard layer had more pages than expected")
    info["layer"]["features"] = n
    return out, info


# ---------------------------------------------------------------- polygons on a grid
class Grid:
    def __init__(self, bounds_, cell=CELL):
        self.lat0, lat1, self.lon0, lon1 = bounds_
        self.d = cell
        self.h = max(1, int(math.ceil((lat1 - self.lat0) / cell)))
        self.w = max(1, int(math.ceil((lon1 - self.lon0) / cell)))


def spans(features, grid):
    """Each feature (a list of rings, [[lon, lat], ...]) as runs of grid cells whose centres are inside it
    (even-odd, so holes are holes). Returns arrays (feature, row, first col, end col)."""
    fid, rows, xs = [], [], []
    d, lat0 = grid.d, grid.lat0
    for k, rings in enumerate(features):
        for ring in rings:
            a = np.asarray(ring, dtype=float)
            if len(a) < 3:
                continue
            b = np.roll(a, -1, axis=0)
            x0, y0, x1, y1 = a[:, 0], a[:, 1], b[:, 0], b[:, 1]
            keep = y0 != y1
            x0, y0, x1, y1 = x0[keep], y0[keep], x1[keep], y1[keep]
            if not len(x0):
                continue
            lo, hi = np.minimum(y0, y1), np.maximum(y0, y1)
            r0 = np.clip(np.ceil((lo - lat0) / d - 0.5).astype(np.int64), 0, grid.h)
            r1 = np.clip(np.ceil((hi - lat0) / d - 0.5).astype(np.int64), 0, grid.h)
            n = r1 - r0
            tot = int(n.sum())
            if tot <= 0:
                continue
            e = np.repeat(np.arange(len(n)), n)
            rr = r0[e] + (np.arange(tot) - np.repeat(np.cumsum(n) - n, n))
            yc = lat0 + (rr + 0.5) * d
            xx = x0[e] + (yc - y0[e]) * (x1[e] - x0[e]) / (y1[e] - y0[e])
            fid.append(np.full(tot, k, dtype=np.int64))
            rows.append(rr)
            xs.append(xx)
    if not fid:
        z = np.zeros(0, dtype=np.int64)
        return z, z, z, z
    fid, rows, xs = np.concatenate(fid), np.concatenate(rows), np.concatenate(xs)
    order = np.lexsort((xs, rows, fid))
    fid, rows, xs = fid[order], rows[order], xs[order]
    if len(xs) % 2:
        raise FactsError("a polygon didn't close")
    f, r = fid[0::2], rows[0::2]
    if not (np.array_equal(f, fid[1::2]) and np.array_equal(r, rows[1::2])):
        raise FactsError("a polygon didn't close")
    c0 = np.clip(np.ceil((xs[0::2] - grid.lon0) / d - 0.5).astype(np.int64), 0, grid.w)
    c1 = np.clip(np.ceil((xs[1::2] - grid.lon0) / d - 0.5).astype(np.int64), 0, grid.w)
    keep = c1 > c0
    return f[keep], r[keep], c0[keep], c1[keep]


def mask_of(features, grid):
    """The union of the features, as a boolean grid."""
    _, r, c0, c1 = spans(features, grid)
    diff = np.zeros((grid.h, grid.w + 1), dtype=np.int32)
    np.add.at(diff, (r, c0), 1)
    np.add.at(diff, (r, c1), -1)
    return np.cumsum(diff[:, :grid.w], axis=1) > 0


def shares(zip_areas, masks, grid):
    """{zip: {name: percent of the ZIP's cells in masks[name]}}. A ZIP too small for a cell centre is
    read at the cell under its first corner."""
    zs = sorted(zip_areas)
    feats = [[ring for poly in zip_areas[z] for ring in poly] for z in zs]
    f, r, c0, c1 = spans(feats, grid)
    cells = np.bincount(f, weights=(c1 - c0), minlength=len(zs))
    out = {z: {} for z in zs}
    for name, m in masks.items():
        pre = np.concatenate([np.zeros((grid.h, 1), dtype=np.int64), np.cumsum(m, axis=1, dtype=np.int64)], axis=1)
        hit = np.bincount(f, weights=(pre[r, c1] - pre[r, c0]), minlength=len(zs))
        for i, z in enumerate(zs):
            if cells[i] > 0:
                out[z][name] = round(100.0 * hit[i] / cells[i], 1)
            else:
                x, y = zip_areas[z][0][0][0]
                ri = int((y - grid.lat0) / grid.d)
                ci = int((x - grid.lon0) / grid.d)
                inside = 0 <= ri < grid.h and 0 <= ci < grid.w and bool(m[ri, ci])
                out[z][name] = 100.0 if inside else 0.0
    return out


def fire_shares(zip_areas, fhsz, bounds_):
    grid = Grid(bounds_)
    vh = mask_of(fhsz["vh"], grid)
    h = mask_of(fhsz["h"], grid) & ~vh
    got = shares(zip_areas, {"fire_vh_pct": vh, "fire_h_pct": h, "fire_pct": vh | h}, grid)
    return got


# ---------------------------------------------------------------- the store
class Store:
    def __init__(self, cfg, pack_dir=None):
        self.cfg = cfg
        self.set = settings(cfg)
        self.folder = folder_of(cfg)
        self.pack_dir = pack_dir or cfg.get("pack_dir")
        self._cache = None
        self._mtime = None

    @property
    def path(self):
        return self.folder / "facts.json"

    def facts(self):
        """The facts on file ({} until the first run), re-read when the file changes."""
        try:
            m = self.path.stat().st_mtime
        except OSError:
            return {}
        if self._cache is None or m != self._mtime:
            try:
                self._cache = json.loads(self.path.read_text(encoding="utf-8"))
                self._mtime = m
            except (OSError, ValueError):
                return self._cache or {}
        return self._cache

    def zip_facts(self):
        """The Census and the state fire agency facts, with the place facts added where they're on file."""
        base = (self.facts() or {}).get("zips") or {}
        extra = self._places_facts()
        if not extra:
            return base
        out = {z: dict(v) for z, v in base.items()}
        for z, v in extra.items():
            out.setdefault(z, {}).update({k: x for k, x in v.items() if x is not None})
        return out

    def _places_facts(self):
        try:
            from . import places
            if places.settings(self.cfg)["mode"] != "on":
                return {}
            if not hasattr(self, "_places"):
                self._places = places.Store(self.cfg, self.pack_dir)
            return self._places.zip_facts()
        except Exception:                                  # never break the Census facts over the place facts
            return {}

    def write(self, facts):
        self.folder.mkdir(parents=True, exist_ok=True)
        tmp = self.path.with_suffix(".tmp")
        tmp.write_text(json.dumps(facts, indent=1, sort_keys=True), encoding="utf-8")
        tmp.replace(self.path)
        self._cache, self._mtime = None, None

    def status(self):
        try:
            return json.loads((self.folder / "status.json").read_text(encoding="utf-8"))
        except (OSError, ValueError):
            return {}

    def write_status(self, st):
        self.folder.mkdir(parents=True, exist_ok=True)
        p = self.folder / "status.json"
        tmp = p.with_suffix(".tmp")
        tmp.write_text(json.dumps(st, indent=1, sort_keys=True), encoding="utf-8")
        tmp.replace(p)

    def sources(self):
        f = self.facts() or {}
        out = []
        if f.get("acs_year"):
            out.append(acs_label(f["acs_year"]))
        if f.get("fire"):
            out.append((((self.facts() or {}).get("fire") or {}).get("label")) or HAZARD_LABEL)
        try:
            if self._places_facts():
                out += self._places.sources()
        except Exception:
            pass
        return out


# ---------------------------------------------------------------- one run
class Updater:
    def __init__(self, cfg, store=None, fetcher=None, clock=None, version=""):
        from . import weather as W
        self.cfg = cfg
        self.set = settings(cfg)
        self.store = store or Store(cfg)
        self.fetcher = fetcher or Fetcher(self.set["fetch"], version=version, cfg=cfg)
        self.clock = clock or dt.datetime.now
        self._load_areas = W.load_zip_areas
        self._bounds = W.bounds
        self._stop = threading.Event()
        self._thread = None
        self.running = False

    def due(self, now=None):
        now = now or self.clock()
        f = self.store.facts() or {}
        st = self.store.status()
        if not f.get("zips"):
            return True
        try:
            built = max(dt.datetime.fromisoformat(str(x)) for x in (f.get("built"), f.get("checked")) if x)
        except ValueError:
            return True
        if st.get("error"):                                  # a failing part waits a day between tries
            try:
                tried = dt.datetime.fromisoformat(str(st.get("finished")))
            except ValueError:
                return True
            if (now - tried).total_seconds() < RETRY_HOURS * 3600:
                return False
            return True
        return (now - built).days >= self.set["every_days"]

    def run_once(self, today=None, say=None, force=False, only_if_due=False):
        """Read every source once; a part that fails keeps what was read before. Returns the status written. one
        download at a time on this computer; a Census year already on file, a Gazetteer with no newer year,
        and the state fire agency layers with the same edit date are not downloaded again (unless `force`)."""
        say = say or (lambda *_: None)
        with P.slot(self.cfg, "ZIP facts", say=say):
            if only_if_due and not self.due():                 # done by hand while this waited
                return {"skipped": "not due any more"}
            f = self.fetcher
            f.requests = f.bytes = 0                               # the budget is per run
            f.deadline = time.monotonic() + MAX_RUN_HOURS * 3600
            f.say = say
            try:
                return self._run(today, say, force)
            finally:
                f.deadline = None

    def _run(self, today, say, force):
        self.running = True
        now = self.clock()
        today = today or now.date()
        old = self.store.facts() or {}
        st = {"started": now.isoformat(timespec="seconds"), "errors": {}}
        try:
            areas = self._load_areas(self.store.pack_dir)
        except Exception as e:                                    # noqa: BLE001
            st.update(error=f"ZIP areas: {e}"[:200], finished=self.clock().isoformat(timespec="seconds"))
            self.store.write_status(st)
            self.running = False
            return st
        zips = sorted(areas)
        new = {"version": 1, "zips": {z: dict((old.get("zips") or {}).get(z) or {}) for z in zips},
               "moe": dict(old.get("moe") or {})}
        for k in ("acs_year", "gaz_year", "fire", "parts"):
            if k in old:
                new[k] = old[k]
        parts = dict(new.get("parts") or {})

        # 1. Census ACS
        try:
            year = find_acs_year(self.fetcher, self.set["acs_base"], today)
            have = old.get("zips") or {}
            if not force and year == old.get("acs_year") and (old.get("parts") or {}).get("acs") and \
                    all(all(k in (have.get(z) or {}) for k in ACS_KEYS) for z in zips):   # a new fact reads again
                say(f"Census: {acs_label(year)} already on file (a release doesn't change); not read again")
                raise _Unchanged()
            say(f"Census: {acs_label(year)}")
            acs, moe = read_acs(self.fetcher, self.set["acs_base"], year, zips)
            if len(acs) < max(1, len(zips) // 2):
                raise FactsError(f"only {len(acs)} of {len(zips)} ZIPs found in the Census file")
            for z in zips:
                for k in ACS_KEYS:
                    new["zips"][z][k] = (acs.get(z) or {}).get(k)
            new["moe"] = moe
            new["acs_year"] = year
            parts["acs"] = now.isoformat(timespec="seconds")
        except _Unchanged:
            pass
        except Exception as e:                                    # noqa: BLE001 — one part, never the run
            st["errors"]["acs"] = (str(e) if isinstance(e, FactsError) else P.unexpected(e, "zip facts acs"))[:200]
            say(f"Census: {st['errors']['acs']}")

        # 2. Gazetteer
        try:
            have = old.get("zips") or {}
            known = old.get("gaz_year") if not force and (old.get("parts") or {}).get("gazetteer") and \
                all("land_sqmi" in (have.get(z) or {}) for z in zips) else None
            got = read_gazetteer(self.fetcher, self.set["gaz_base"], today, zips, newer_than=known)
            if got is None:
                say(f"Gazetteer {known} already on file; no newer year published")
            else:
                land, gy = got
                for z in zips:
                    new["zips"][z]["land_sqmi"] = land.get(z)
                new["gaz_year"] = gy
                parts["gazetteer"] = now.isoformat(timespec="seconds")
                say(f"Gazetteer {gy}: {sum(1 for v in land.values() if v)} ZIPs")
        except Exception as e:                                    # noqa: BLE001
            st["errors"]["gazetteer"] = (str(e) if isinstance(e, FactsError) else P.unexpected(e, "zip facts gazetteer"))[:200]
            say(f"Gazetteer: {st['errors']['gazetteer']}")
        for z in zips:
            p, a = new["zips"][z].get("population"), new["zips"][z].get("land_sqmi")
            new["zips"][z]["density"] = round(p / a, 1) if p is not None and a else None

        # 3. the region's hazard zones
        if self.set["fire"] == "on":
            try:
                b = self._bounds(areas)
                have = old.get("zips") or {}
                known = (old.get("fire") or {}).get("layers") if not force and (old.get("parts") or {}).get("fire") \
                    and all(FIRE_KEYS[0] in (have.get(z) or {}) for z in zips) else None
                read = read_hazard(self.fetcher, self.set["hazard"], b, known=known)
                if read is None:
                    say("Hazard zones: the layer hasn't been edited since it was read; not read again")
                else:
                    fhsz, info = read
                    got = fire_shares(areas, fhsz, b)
                    for z in zips:
                        for k in FIRE_KEYS:
                            new["zips"][z][k] = got[z].get(k)
                    new["fire"] = {"label": self.set["hazard"].label or HAZARD_LABEL, "layers": info}
                    parts["fire"] = now.isoformat(timespec="seconds")
                    say(f"Hazard zones: {len(fhsz['vh'])} Very High and {len(fhsz['h'])} High areas")
            except FactsError as e:
                st["errors"]["fire"] = str(e)[:200]
                say(f"Hazard zones: {e}")
            except Exception as e:                                # noqa: BLE001
                st["errors"]["fire"] = f"couldn't read or place the zones ({type(e).__name__})"
                say(f"Hazard zones: {st['errors']['fire']}")
        else:
            for z in zips:
                for k in FIRE_KEYS:
                    new["zips"][z].pop(k, None)
            new.pop("fire", None)

        new["parts"] = parts
        stamp = now.isoformat(timespec="seconds")
        if not st["errors"]:
            new["checked"] = stamp                                 # due() counts a check as fresh
        if parts:
            # a run with a failed part keeps the older date, so the part is tried again tomorrow (due());
            # so does a run that found every source unchanged (nothing was read again)
            read = any(v == stamp for v in parts.values())
            new["built"] = old.get("built") if (st["errors"] or not read) and old.get("built") else stamp
            self.store.write(new)
        st.update(finished=self.clock().isoformat(timespec="seconds"), requests=self.fetcher.requests,
                  bytes=self.fetcher.bytes, zips=len(zips),
                  error="; ".join(f"{k}: {v}" for k, v in st["errors"].items())[:300])
        if not st["errors"]:
            st.pop("errors")
        self.store.write_status(st)
        self.running = False
        return st

    def next_run(self, now=None):
        now = now or self.clock()
        t = now.replace(hour=self.set["at"][0], minute=self.set["at"][1], second=0, microsecond=0)
        return t if t > now else t + dt.timedelta(days=1)

    def start(self, first_delay=FIRST_DELAY):
        if self.set["mode"] != "on":
            return

        def loop():
            if self._stop.wait(first_delay):
                return
            while not self._stop.is_set():
                try:
                    if self.due():
                        # a background run waits for the off-peak hours (polite.py)
                        if not P.wait_off_peak(self.cfg, self._stop, self.clock, offset_min=20):
                            return
                        self.run_once(only_if_due=True)
                except Exception as e:                            # noqa: BLE001 — never take the hub down
                    _log.warning("zip facts run failed: %s", type(e).__name__)
                    self.running = False
                wait = (self.next_run() - self.clock()).total_seconds()
                if self._stop.wait(max(60, wait)):
                    return
        self._thread = threading.Thread(target=loop, name="charts-zipfacts", daemon=True)
        self._thread.start()

    def stop(self):
        self._stop.set()


def summary(store, now=None):
    """What /health and the self-check say about the ZIP facts: dates, counts and sources only."""
    f = store.facts() or {}
    st = store.status()
    zips = f.get("zips") or {}
    out = {"zips": sum(1 for v in zips.values() if v.get("population") is not None),
           "acs": acs_label(f["acs_year"]) if f.get("acs_year") else None,
           "gazetteer": f.get("gaz_year"), "fire": bool(f.get("fire")),
           "built": f.get("built"), "last_try": st.get("finished"), "error": st.get("error") or ""}
    try:                                                  # a monthly check that found nothing new is fresh
        last = max(dt.datetime.fromisoformat(str(x)) for x in (f.get("built"), f.get("checked")) if x)
        age = ((now or dt.datetime.now()) - last).days
    except ValueError:
        age = None
    out["stale"] = bool(age is None or age > 120)
    return out
