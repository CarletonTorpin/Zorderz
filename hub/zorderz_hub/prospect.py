"""Prospecting: where to hang door hangers this week. The product lines, their job families, their
words and their default weights are the pack's (rules.yml charts.prospect).

What makes a neighborhood "good" depends on the week and the product, so nothing here is a fixed score. The hub ships INGREDIENTS (numbers it can compute per neighborhood) and the ask brings a RECIPE
(which ingredients, how much each matters, what to leave out, how many, where); every answer says its reasons,
so "no, weight the lot size more" makes the next ask different.

The unit. A neighborhood here is a patch of the grid below (CELL degrees, a few hundred metres across): the
size of a two-to-three-hour walk (Doorhangers' walk_size is 60 houses; most patches hold 60-200). Census block
groups would be the other choice; the hub holds no block-group outlines, and a block group (250-1,000+ homes)
is several walks. Each patch is described from what this computer already holds:

    homes          the region's address points without a unit number (addresses.sqlite)
    streets        the region's street names there, most homes first -- what Doorhangers plans a route from
    lot_size       the share of the patch's parcels of half an acre or more (parcels.sqlite). Many layers fill
                   acreage only for the larger parcels, so a parcel with none on file is counted as under half an
                   acre, not as unknown
    single_family  the share of those homes whose parcel has one address point (a proxy: the Assessor's use code
                   and year built are not public)
    burned_before  the share of homes inside a past fire's outline (1950 on), and the last such year (fires.json)
    inland         miles from the ocean or a bay, straight line (coast.json)
    safety       the share of homes in a safety site (safety/addresses.csv.gz)
    fire_zone, owners, older_homes   the ZIP's highest hazard class share, owner-occupied share and share built before
                   1980 (zipfacts) -- ZIP-wide, and said so
    few_customers, product_nearby   customers and a product line's jobs, counted per patch at import time
                   (the source adapter -> prospect_cells_clean); no customer's place is kept, only the counts
    not_hung       days since a door-hanger route covered one of the patch's streets in its ZIP, from Doorhangers via
                   the website (the request's `hung` list); never = best
    close          miles from the place in the ask, else from the pack's origin (the shop's street)

What leaves this module: counts, shares, distances and the region's street names. Never a customer's name,
address, invoice or amount.
"""
import datetime as dt
import gzip
import csv
import json
import logging
import math
import os
import sqlite3
import threading
import time
from pathlib import Path

import numpy as np
import pandas as pd

from . import region as R

_log = logging.getLogger("zorderz_hub.prospect")

CELL = 0.003                          # degrees: about 330 m north-south
COLS = 4000                           # key = row * COLS + col (12 degrees of longitude at most)
MIN_HOMES = 30                        # a patch with fewer homes is not described at all
RANK_MIN_HOMES = 50                   # nor offered by default (Doorhangers' walk_size is 60; a recipe may lower it)
BIG_LOT_ACRES = 0.5
BIG_HOMES = 200                       # more than this: "about N walks"
WALK_SIZE = 60                        # Doorhangers' walk_size
STREETS_KEPT = 12
CACHE_NAME = "prospect_cells.json.gz"
CACHE_VERSION = 1
MAX_HOW_MANY = 20
VOID_STATUSES = {"void", "voided", "deleted", "cancelled", "canceled"}
BUILD_RETRY = 600                     # seconds before a failed build of the patches is tried again
NEAR_MILES = 0.6                      # product_nearby counts the patch and its eight neighbours (about 1 km square)


# ------------------------------------------------------------------ the grid
def _origin():
    """(lat0, lon0, lat1, lon1): the region pack's box, the patches' grid."""
    lat0, lat1, lon0, lon1 = R.box()
    return lat0, lon0, lat1, min(lon1, lon0 + CELL * (COLS - 1))


def key_of(lon, lat):
    """int keys for arrays of points (-1 outside the box)."""
    lat0, lon0, lat1, lon1 = _origin()
    lon, lat = np.asarray(lon, dtype=float), np.asarray(lat, dtype=float)
    ok = (lat >= lat0) & (lat < lat1) & (lon >= lon0) & (lon < lon1)          # NaN is never ok
    lat, lon = np.where(ok, lat, lat0), np.where(ok, lon, lon0)
    r = np.floor((lat - lat0) / CELL).astype(np.int64)
    c = np.floor((lon - lon0) / CELL).astype(np.int64)
    return np.where(ok, r * COLS + c, -1)


def bounds_of(key):
    """(south, west, north, east) of a patch."""
    lat0, lon0, _lat1, _lon1 = _origin()
    r, c = divmod(int(key), COLS)
    s, w = lat0 + r * CELL, lon0 + c * CELL
    return (round(s, 5), round(w, 5), round(s + CELL, 5), round(w + CELL, 5))


def neighbours(key):
    r, c = divmod(int(key), COLS)
    return [(r + dr) * COLS + (c + dc) for dr in (-1, 0, 1) for dc in (-1, 0, 1)]


def miles(lat1, lon1, lat2, lon2):
    """Straight-line miles (haversine)."""
    p1, p2 = math.radians(lat1), math.radians(lat2)
    a = math.sin((p2 - p1) / 2) ** 2 + math.cos(p1) * math.cos(p2) * math.sin(math.radians(lon2 - lon1) / 2) ** 2
    return 3958.8 * 2 * math.asin(min(1.0, math.sqrt(a)))


_SFX = {"ave": "Ave", "st": "St", "rd": "Rd", "dr": "Dr", "ln": "Ln", "ct": "Ct", "pl": "Pl", "way": "Way",
        "blvd": "Blvd", "cir": "Cir", "ter": "Ter", "trl": "Trl", "pkwy": "Pkwy", "hwy": "Hwy", "sq": "Sq",
        "aly": "Aly", "row": "Row", "glen": "Glen", "pt": "Pt", "lp": "Loop", "loop": "Loop", "pass": "Pass",
        "path": "Path", "walk": "Walk", "run": "Run", "vw": "Vw", "xing": "Xing"}
_SMALL = {"de", "la", "el", "del", "los", "las", "y"}
# The region's street names abbreviate every suffix-like word wherever it falls ("gln oaks dr", "pt example ave"). Before
# the last word these are names, so they read in full; "st" and "dr" stay (Saint or Street, Doctor or Drive).
_FULL = {"gln": "Glen", "pt": "Point", "crst": "Crest", "hts": "Heights", "cv": "Cove", "sq": "Square",
         "ter": "Terrace", "trl": "Trail", "cir": "Circle", "mt": "Mount", "pkwy": "Parkway", "hwy": "Highway",
         "blvd": "Boulevard", "ln": "Lane", "rd": "Road", "ave": "Avenue", "ct": "Court", "pl": "Place"}
_DIRWORDS = ("n", "s", "e", "w", "ne", "nw", "se", "sw")


def street_key(name):
    """One key for a street name, however it was written: the region's ('lupine dr'), Doorhangers' ('Lupine
    Drive'), a person's ('N. Lupine Dr.'). The same words as safety.street_key, so the import, safety-site
    and here agree."""
    from .safety import street_key as _fw
    s = str(name or "").strip()
    if not s:
        return ""
    return _fw("1 " + s)[1]


def _town_case(s):
    """safety-site writes some communities in capitals ("CEDAR HILLS"): those read as "Cedar Hills"; mixed case is
    kept."""
    s = str(s or "").strip()
    return street_title(s) if s and s == s.upper() else s


def street_title(s):
    """'calle example' -> 'Calle Example'; 'n park dr' -> 'N Park Dr'; 'gln oaks dr' -> 'Glen Oaks Dr'."""
    out = []
    words = str(s or "").split()
    last = max((i for i, w in enumerate(words) if w.lower() not in _DIRWORDS), default=len(words) - 1)
    for i, w in enumerate(words):
        lw = w.lower()
        if lw in _FULL and i < last:
            out.append(_FULL[lw])
        elif lw in _SFX and i > 0:
            out.append(_SFX[lw])
        elif lw in ("n", "s", "e", "w", "ne", "nw", "se", "sw"):
            out.append(lw.upper())
        elif lw in _SMALL and i > 0:
            out.append(lw)
        else:
            out.append(lw[:1].upper() + lw[1:])
    return " ".join(out)


# ------------------------------------------------------------------ the patches (built once, cached beside the store)
def _sources(places_folder, safety_folder, zipfacts_folder):
    out = {}
    for name, p in (("addresses", places_folder / "addresses.sqlite"), ("parcels", places_folder / "parcels.sqlite"),
                    ("fires", places_folder / "fires.json"), ("coast", places_folder / "coast.json"),
                    ("safety_area", safety_folder / "addresses.csv.gz" if safety_folder else None),
                    ("zipfacts", zipfacts_folder / "facts.json" if zipfacts_folder else None)):
        try:
            st = p.stat() if p is not None else None
            out[name] = [st.st_size, int(st.st_mtime)] if st else None
        except OSError:
            out[name] = None
    return out


def build_cells(places_folder, safety_folder=None, zipfacts_folder=None, lookup=None, say=None):
    """Describe every patch with at least MIN_HOMES homes. Returns {"version", "sources", "built", "cells": [...]}.
    `lookup` is a places.Lookup (fires on the 100 m grid, miles to the coast); None leaves those ingredients out."""
    say = say or (lambda *_: None)
    t0 = time.time()
    places_folder = Path(places_folder)
    con = sqlite3.connect(f"file:{places_folder / 'addresses.sqlite'}?mode=ro", uri=True)
    try:
        pts = pd.read_sql_query("SELECT num, street, zip, unit, lon, lat, apn FROM pts", con)
    finally:
        con.close()
    pts["unit"] = pts["unit"].fillna("").astype(str)
    pts["apn"] = pts["apn"].fillna("").astype(str)
    per_apn = pts[pts["apn"] != ""].groupby("apn").size()
    homes = pts[pts["unit"] == ""].copy()
    # a home is one house number on one street. Timeshare resorts file every interval
    # as its own address point with its own parcel and no unit (thousands of points on one number), so
    # "homes" counted thousands of front doors that are one building, and they ranked as single-family. An address
    # with several such points is one home, and not single-family.
    has_num = homes["num"].notna()
    mult = homes[has_num].groupby(["num", "street", "zip"], dropna=False)["lon"].transform("size")
    homes["stacked"] = False
    homes.loc[has_num, "stacked"] = mult.values > 1
    homes = pd.concat([homes[~has_num], homes[has_num].drop_duplicates(["num", "street", "zip"])])
    homes["key"] = key_of(homes["lon"].values, homes["lat"].values)
    homes = homes[homes["key"] >= 0]
    say(f"prospect: {len(homes):,} homes on the region's address points (one per house number and street)")
    g = homes.groupby("key")
    n = g.size()
    keep = n[n >= MIN_HOMES].index
    homes = homes[homes["key"].isin(keep)]
    g = homes.groupby("key")
    cells = pd.DataFrame({"homes": g.size(), "lon": g["lon"].mean(), "lat": g["lat"].mean()})
    cells["zip"] = homes.groupby(["key", "zip"]).size().reset_index(name="n").sort_values(
        ["key", "n"], ascending=[True, False]).drop_duplicates("key").set_index("key")["zip"]
    # streets, most homes first: [display name, homes, lowest number, highest number, key]. The numbers let a
    # Doorhangers route that covered 100-180 Example Dr leave out only the patch those numbers are in.
    homes["num"] = pd.to_numeric(homes["num"], errors="coerce")
    st = homes.groupby(["key", "street"]).agg(n=("lon", "size"), lo=("num", "min"), hi=("num", "max")).reset_index()
    st = st.sort_values(["key", "n"], ascending=[True, False])
    skeys = {s: street_key(s) for s in st["street"].unique()}
    streets = {}
    for k, s, c, lo, hi in zip(st["key"], st["street"], st["n"], st["lo"], st["hi"]):
        lst = streets.setdefault(k, [])
        if len(lst) < STREETS_KEPT:
            lst.append([street_title(s), int(c), None if pd.isna(lo) else int(lo), None if pd.isna(hi) else int(hi),
                        skeys[s]])
    # single-family proxy: the home's parcel has one address point
    has_apn = homes["apn"] != ""
    single = homes.loc[has_apn, "apn"].map(per_apn).eq(1) & ~homes.loc[has_apn, "stacked"]
    cells["single_family"] = (single.groupby(homes.loc[has_apn, "key"]).mean() * 100).round(1)
    # lot size: the median acreage of the patch's parcels (each parcel once)
    par = places_folder / "parcels.sqlite"
    if par.exists():
        pc = sqlite3.connect(f"file:{par}?mode=ro", uri=True)
        try:
            acres = pd.read_sql_query("SELECT apn, acres FROM parcels", pc).set_index("apn")["acres"]
        finally:
            pc.close()
        hp = homes.loc[has_apn, ["key", "apn"]].drop_duplicates()
        hp["big"] = hp["apn"].map(acres).fillna(0).ge(BIG_LOT_ACRES)
        cells["lot_size"] = (hp.groupby("key")["big"].mean() * 100).round(1)
    # fires and the coast (places.Lookup)
    if lookup is not None:
        try:
            at = lookup.at(homes["lon"].values, homes["lat"].values)
            homes["burned"] = (np.asarray(at["fires"]) > 0).astype(float)
            homes["last_fire"] = np.asarray(at["last_fire"], dtype=float)
            cells["burned_before"] = (homes.groupby("key")["burned"].mean() * 100).round(1)
            lf = homes.groupby("key")["last_fire"].max()
            cells["last_fire_year"] = lf.where(lf > 0)
        except Exception as exc:
            say(f"prospect: fire history left out ({type(exc).__name__})")
        try:
            from .places import miles_to
            if getattr(lookup, "coast", None) is not None:
                cells["inland"] = np.round(miles_to(cells["lon"].values, cells["lat"].values, lookup.coast), 1)
        except Exception as exc:
            say(f"prospect: miles to the coast left out ({type(exc).__name__})")
    # safety sites
    community = {}
    fw = Path(safety_folder) / "addresses.csv.gz" if safety_folder else None
    if fw is not None and fw.exists():
        try:
            rows = []
            with gzip.open(fw, "rt", encoding="utf-8", newline="") as f:
                for r in csv.DictReader(f):
                    if (r.get("unit") or "").strip():
                        continue                 # homes are the points without a unit, as above
                    rows.append((float(r.get("lon") or "nan"), float(r.get("lat") or "nan"),
                                 bool((r.get("safety_sites") or "").strip()), (r.get("community") or "").strip()))
            fwd = pd.DataFrame(rows, columns=["lon", "lat", "site", "community"])
            fwd["key"] = key_of(fwd["lon"].values, fwd["lat"].values)
            fwd = fwd[fwd["key"].isin(cells.index)]
            in_site = fwd[fwd["site"]].groupby("key").size()
            cells["safety_area"] = (in_site.reindex(cells.index).fillna(0) / cells["homes"] * 100).clip(upper=100).round(1)
            cm = fwd[fwd["community"] != ""].groupby(["key", "community"]).size().reset_index(name="n")
            cm = cm.sort_values(["key", "n"], ascending=[True, False]).drop_duplicates("key")
            community = dict(zip(cm["key"].tolist(), cm["community"].tolist()))
        except Exception as exc:
            say(f"prospect: safety sites left out ({type(exc).__name__})")
    out = []
    for k, row in cells.iterrows():
        d = {"key": int(k), "homes": int(row["homes"]), "lat": round(float(row["lat"]), 5),
             "lon": round(float(row["lon"]), 5), "zip": str(row["zip"] or ""),
             "streets": streets.get(k, []), "community": _town_case(community.get(k, ""))}
        for col in ("single_family", "lot_size", "burned_before", "last_fire_year", "inland", "safety_area"):
            if col in cells and not pd.isna(row.get(col)):
                v = row[col]
                d[col] = int(v) if col == "last_fire_year" else float(v)
        out.append(d)
    say(f"prospect: {len(out):,} neighborhoods of {MIN_HOMES}+ homes in {time.time() - t0:.0f} s")
    return {"version": CACHE_VERSION, "built": dt.datetime.now().isoformat(timespec="seconds"),
            "sources": _sources(places_folder, Path(safety_folder) if safety_folder else None,
                                Path(zipfacts_folder) if zipfacts_folder else None),
            "cells": out}


class Cells:
    """The patches, built once and kept beside the store (<store>/places/prospect_cells.json.gz); rebuilt when
    the address points, parcels, fire history, coast or safety-site files change. Thread-safe; a build in
    progress makes get() return None (the op says "being prepared" rather than waiting a minute)."""

    def __init__(self, places_folder, safety_folder=None, zipfacts_folder=None, lookup_factory=None):
        self.places_folder = Path(places_folder)
        self.safety_folder = Path(safety_folder) if safety_folder else None
        self.zipfacts_folder = Path(zipfacts_folder) if zipfacts_folder else None
        self.lookup_factory = lookup_factory
        self._lock = threading.Lock()
        self._data = None
        self._building = False
        self.last_error = ""
        self.failed_at = 0.0
        self._data_sig = None

    @property
    def path(self):
        return self.places_folder / CACHE_NAME

    @property
    def available(self):
        """The region's address points are on this computer (places has read them at least once)."""
        return (self.places_folder / "addresses.sqlite").exists()

    def _fresh(self, data):
        if not data or data.get("version") != CACHE_VERSION:
            return False
        now = _sources(self.places_folder, self.safety_folder, self.zipfacts_folder)
        old = data.get("sources") or {}
        return all(now.get(k) == old.get(k) for k in ("addresses", "parcels", "fires", "coast", "safety_area"))

    def _sig(self):
        try:
            st = self.path.stat()
            return (st.st_size, st.st_mtime_ns)
        except OSError:
            return None

    def load(self, fresh_only=True):
        sig = self._sig()
        if sig is not None and sig == self._data_sig and self._data is not None:
            data = self._data                    # the file this hub already holds: not read again
        else:
            try:
                with gzip.open(self.path, "rt", encoding="utf-8") as f:
                    data = json.load(f)
            except (OSError, ValueError):
                return None
            self._data_sig = sig
        if not data or data.get("version") != CACHE_VERSION:
            return None
        return data if (not fresh_only or self._fresh(data)) else None

    def build(self, say=None):
        lookup = None
        if self.lookup_factory is not None:
            try:
                lookup = self.lookup_factory()
            except Exception as exc:
                (say or _log.info)(f"prospect: no places lookup ({type(exc).__name__})")
        data = build_cells(self.places_folder, self.safety_folder, self.zipfacts_folder, lookup, say=say)
        tmp = self.path.with_name(f"{self.path.name}.{os.getpid()}.{threading.get_ident()}.tmp")
        try:
            with gzip.open(tmp, "wt", encoding="utf-8") as f:
                json.dump(data, f, separators=(",", ":"))
            tmp.replace(self.path)
        finally:
            try:
                tmp.unlink()
            except OSError:
                pass
        return data

    def get(self, wait=False, say=None):
        """The patches. When the region's files have changed, the last build keeps answering while a new one is
        made (an update never leaves prospecting blank); a failed build is not retried for BUILD_RETRY
        seconds."""
        d = self._data
        if d is not None and self._fresh(d):
            return d
        with self._lock:
            if self._data is not None and self._fresh(self._data):
                return self._data
            d = self.load()
            if d is not None:
                self._data = d
                return d
            if not self.available:
                return None
            stale = self._data or self.load(fresh_only=False)
            if stale is not None:
                self._data = stale
            if self._building or (self.failed_at and time.time() - self.failed_at < BUILD_RETRY):
                return stale
            self._building = True
        if not wait:
            threading.Thread(target=self._build_bg, args=(say,), name="prospect-cells", daemon=True).start()
            return stale
        return self._build_bg(say) or stale

    def summary(self):
        """For health: what is in memory, never a file read on the request's thread."""
        d = self._data
        if d is None and not self._building:
            threading.Thread(target=self.get, kwargs={"wait": False}, name="prospect-warm", daemon=True).start()
        return {"neighborhoods": len(d["cells"]) if d else None, "built": d.get("built") if d else None,
                "building": bool(self._building), "error": self.last_error or None}

    def _build_bg(self, say=None):
        try:
            d = self.build(say=say)
            self._data = d
            self.last_error = ""
            self.failed_at = 0.0
            return d
        except Exception as exc:
            self.last_error = f"{type(exc).__name__}: {str(exc)[:160]}"
            self.failed_at = time.time()
            _log.warning("prospect: build failed: %s", self.last_error)
            return None
        finally:
            self._building = False


# ------------------------------------------------------------------ counted at import (the source adapter)
def cell_totals(customer_keys, cust_cells, lines, invoices=None):
    """prospect_cells_clean: per patch, how many customers and jobs, and jobs per product family. Counted from work
    lines only, void ones left out (the same lines every chart counts): a customer who only ever had a void estimate
    isn't one here. `cust_cells` is each customer's patch key (-1 unplaced), in the same row order as
    `customer_keys`. Only the counts leave here; the customer -> patch map is dropped with the import's memory."""
    cols = ["cell", "customers", "jobs", "families"]
    ck = pd.DataFrame({"customer_key": list(customer_keys),
                       "cell": [int(x) if x is not None and not (isinstance(x, float) and math.isnan(x)) else -1
                                for x in cust_cells]})
    ck = ck[ck["cell"] >= 0]
    if not len(ck) or lines is None or not len(lines) or "customer_key" not in lines:
        return pd.DataFrame(columns=cols)
    ln = lines
    if "work" in ln:
        ln = ln[ln["work"].fillna(False).astype(bool)]
    if "status" in ln:
        ln = ln[~ln["status"].fillna("").astype(str).str.strip().str.lower().isin(VOID_STATUSES)]
    keep = [c for c in ("invoice_no", "customer_key", "family") if c in ln]
    ln = ln[keep].merge(ck, on="customer_key", how="inner")
    if not len(ln):
        return pd.DataFrame(columns=cols)
    customers = ln.groupby("cell")["customer_key"].nunique()
    jobs = ln.groupby("cell")["invoice_no"].nunique()
    fams = {}
    if "family" in ln:
        f = ln[ln["family"].fillna("").astype(str) != ""].groupby(["cell", "family"])["invoice_no"].nunique()
        for (cell, fam), n in f.items():
            fams.setdefault(int(cell), {})[str(fam)] = int(n)
    out = pd.DataFrame({"cell": customers.index.astype(int), "customers": customers.values.astype(int),
                        "jobs": jobs.reindex(customers.index).fillna(0).astype(int).values})
    out["families"] = [json.dumps(fams.get(int(c), {}), sort_keys=True) for c in out["cell"]]
    return out[cols]


def cust_index(frame):
    """{cell: {"customers", "jobs", "families": {family: jobs}}} from prospect_cells_clean."""
    out = {}
    if frame is None or not len(frame):
        return out
    for r in frame.itertuples(index=False):
        try:
            fam = json.loads(r.families or "{}")
        except (TypeError, ValueError):
            fam = {}
        out[int(r.cell)] = {"customers": int(r.customers or 0), "jobs": int(r.jobs or 0),
                            "families": fam if isinstance(fam, dict) else {}}
    return out


# ------------------------------------------------------------------ ingredients
# name: (label, unit, direction (+1 more is better, -1 less is better), ZIP-wide?)
INGREDIENTS = {
    "few_customers":  ("Few customers there yet", "customers per 100 homes", -1, False),
    "product_nearby": ("The product line's jobs nearby", "jobs in it and the patches around it (about 1 km square)", +1, False),
    "fire_zone":      ("In the top hazard class", "% of the ZIP", +1, True),   # the region's words: ingredient_label()
    "burned_before":  ("A past fire reached it", "% of homes", +1, False),
    "safety_area":       ("In a safety site", "% of homes", +1, False),
    "inland":         ("Farther from the coast", "miles from the coast", +1, False),
    "lot_size":       ("Bigger lots", "% of lots half an acre or more", +1, False),
    "single_family":  ("Single-family homes", "% of homes", +1, False),
    "owners":         ("Owner-occupied", "% of the ZIP's homes", +1, True),
    "older_homes":    ("Older homes", "% of the ZIP built before 1980", +1, True),
    "not_hung":       ("Not hung recently", "days since a route covered it", +1, False),
    "close":          ("Close by", "miles", -1, False),
}
ORDER = list(INGREDIENTS)


def ingredient_label(name):
    """An ingredient's label; the hazard one in the region's own words (region.json labels.hazard_vh)."""
    if name == "fire_zone":
        from .zipfacts import label_of
        return label_of("fire_vh_pct")
    return INGREDIENTS[name][0]
DEFAULT_WEIGHTS = {"few_customers": 0.6, "single_family": 0.5, "owners": 0.5, "not_hung": 0.4, "close": 0.4}
DEFAULT_HUNG_DAYS = 90
NEVER_HUNG_DAYS = 3650                # "never" ranks as ten years


class RecipeError(Exception):
    """A recipe the hub can't run; the message is a question for the person."""


def products(pack):
    p = (getattr(pack, "prospect", None) or {}).get("products") or {}
    return {str(k): v for k, v in p.items() if isinstance(v, dict)}


def clean_recipe(raw, pack):
    """The recipe as the hub will run it, plus notes on anything it changed. Unknown ingredient names are dropped
    and said; weights are clipped to 0..1; how_many 1..MAX_HOW_MANY."""
    raw = raw if isinstance(raw, dict) else {}
    notes = []
    prods = products(pack)
    product = str(raw.get("product") or "").strip().lower().replace("-", "_").replace(" ", "_") or None
    if product and product not in prods:
        notes.append(f"Product line \"{raw.get('product')}\" isn't one prospecting knows; ranking for any product.")
        product = None
    base = dict((prods.get(product) or {}).get("weights") or (getattr(pack, "prospect", None) or {}).get(
        "default_weights") or DEFAULT_WEIGHTS)
    given = raw.get("ingredients")
    weights = {}
    if isinstance(given, dict) and given:
        if raw.get("replace_weights") in (True, 1, "1", "true"):
            base = {}
        for k, v in given.items():
            k = str(k).strip().lower()
            if k not in INGREDIENTS:
                notes.append(f"\"{k}\" isn't an ingredient; left out.")
                continue
            try:
                fv = float(v)
                if math.isnan(fv) or math.isinf(fv):
                    raise ValueError
                base[k] = max(0.0, min(1.0, fv))
            except (TypeError, ValueError):
                notes.append(f"The weight for {k} isn't a number; left out.")
    for k, v in base.items():
        if k in INGREDIENTS:
            try:
                w = float(v)
                if math.isnan(w) or math.isinf(w):
                    continue
                w = max(0.0, min(1.0, w))
            except (TypeError, ValueError):
                continue
            if w > 0:
                weights[k] = w
    ex = raw.get("exclude") if isinstance(raw.get("exclude"), dict) else {}
    pdefs = getattr(pack, "prospect", None) or {}

    def _int(v, lo, hi, dflt):
        try:
            return max(lo, min(hi, int(v)))
        except (TypeError, ValueError):
            return dflt
    hung_days = ex.get("hung_within_days", pdefs.get("hung_within_days", DEFAULT_HUNG_DAYS))
    hung_days = None if hung_days in (None, "", 0, "0", False) else _int(hung_days, 1, 3650, DEFAULT_HUNG_DAYS)
    max_cust = ex.get("max_customers")
    max_cust = None if max_cust in (None, "") else _int(max_cust, 0, 10000, None)
    out = {"product": product, "ingredients": {k: round(weights[k], 3) for k in ORDER if k in weights},
           "exclude": {"hung_within_days": hung_days, "max_customers": max_cust,
                       "min_homes": _int(ex.get("min_homes"), MIN_HOMES, 2000, RANK_MIN_HOMES)},
           "how_many": _int(raw.get("how_many"), 1, MAX_HOW_MANY, 5),
           "near": _clean_near(raw.get("near")),
           "max_miles": None, "spread": raw.get("spread") not in (False, 0, "0", "false"),
           "name": str(raw.get("name") or "")[:60]}
    try:
        mm = raw.get("max_miles")
        out["max_miles"] = None if mm in (None, "") else max(0.5, min(100.0, float(mm)))
    except (TypeError, ValueError):
        notes.append("The distance limit isn't a number; left out.")
    if not out["ingredients"]:
        raise RecipeError("Which matters most for this walk: few customers there yet, fire risk, sunny inland, "
                          "bigger lots, or close by?")
    return out, notes


# ------------------------------------------------------------------ where ("near")
MAX_PLACES = 5


def _clean_near(v):
    """A place, or up to MAX_PLACES of them ("Maple Falls or Pine Ridge"): a string, or a list of strings."""
    if isinstance(v, (list, tuple)):
        got = []
        for x in v:
            x = str(x).strip()[:60] if isinstance(x, (str, int)) and not isinstance(x, bool) else ""
            if x and _norm(x).strip(" -.,'") and x.casefold() not in {g.casefold() for g in got}:
                got.append(x)
        got = got[:MAX_PLACES]
        return got[0] if len(got) == 1 else (got or None)
    if not isinstance(v, (str, int)) or isinstance(v, bool):
        return None
    v = str(v).strip()[:60]
    return v if _norm(v).strip(" -.,'") else None


def near_list(near):
    return [near] if isinstance(near, str) else list(near or [])


def _norm(s):
    return " ".join(str(s or "").casefold().replace("-", " ").split())


def resolve_near(near, cells, pack, zip_names):
    """(set of patch keys, label, (lat, lon) centre) for a town, region, community or ZIP; RecipeError when the place
    is unknown (with the closest names)."""
    w = _norm(near)
    w = w[4:] if w.startswith("the ") else w
    if not w:
        return None, None, None
    if w.isdigit() and len(w) == 5:
        keys = {c["key"] for c in cells if c["zip"] == w}
        label = f"{zip_names.get(w, '')} {w}".strip()
    else:
        towns = set()
        for region, ts in (getattr(pack, "regions", None) or {}).items():
            if _norm(region) == w:
                towns |= {_norm(t) for t in ts}
                label = region
        for phrase, targets in (getattr(pack, "region_words", None) or {}).items():
            if _norm(phrase) == w:
                for t in targets:
                    towns |= {_norm(x) for x in (pack.regions.get(t) or [t])}
                label = near
        if not towns:
            towns = {w}
            label = near.strip()
        zips = {z for z, name in zip_names.items() if _norm(name) in towns}
        keys = {c["key"] for c in cells if c["zip"] in zips or _norm(c.get("community")) in towns}
        if not keys:
            names = sorted({n for n in zip_names.values() if n} | set(getattr(pack, "regions", {}) or {}))
            close = [n for n in names if _norm(n).startswith(w[:3])][:4]
            raise RecipeError(f"I don't know \"{near}\" as a town, region or ZIP." +
                              (f" Did you mean {', '.join(close)}?" if close else ""))
        label = label.title() if label == label.lower() else label
    if not keys:
        raise RecipeError(f"No neighborhoods with {MIN_HOMES} or more homes in {near}.")
    sel = [c for c in cells if c["key"] in keys]
    tot = sum(c["homes"] for c in sel) or 1
    centre = (sum(c["lat"] * c["homes"] for c in sel) / tot, sum(c["lon"] * c["homes"] for c in sel) / tot)
    return keys, label, centre


def street_centre(addresses_path, street, zip5):
    """(lat, lon) of a street's region address points in a ZIP (the pack's origin, e.g. the shop's street)."""
    try:
        con = sqlite3.connect(f"file:{addresses_path}?mode=ro", uri=True)
        try:
            row = con.execute("SELECT AVG(lat), AVG(lon), COUNT(*) FROM pts WHERE zip = ? AND street = ?",
                              (str(zip5)[:5], _norm(street))).fetchone()
        finally:
            con.close()
    except sqlite3.Error:
        return None
    if not row or not row[2]:
        return None
    return (float(row[0]), float(row[1]))


def zip_centre(z, cells):
    sel = [c for c in cells if c["zip"] == z]
    if not sel:
        return None
    tot = sum(c["homes"] for c in sel) or 1
    return (sum(c["lat"] * c["homes"] for c in sel) / tot, sum(c["lon"] * c["homes"] for c in sel) / tot)


# ------------------------------------------------------------------ the doorhanger log ("hung")
def _as_date(today):
    if isinstance(today, dt.datetime):
        return today.date()
    if isinstance(today, dt.date):
        return today
    try:
        return dt.date.fromisoformat(str(today or "")[:10])
    except ValueError:
        return dt.date.today()


def _num_or_none(v):
    try:
        n = int(float(v))
    except (TypeError, ValueError):
        return None
    return n if n >= 0 else None


def hung_index(hung, today):
    """{(street key, zip): [(days since, low, high), ...]} from the website's list [{street|streets, zip, date,
    low?, high?}]. A route with no ZIP is left out: "Main St" alone is every Main St in the county."""
    today = _as_date(today)
    out = {}
    for h in hung or ():
        if not isinstance(h, dict):
            continue
        try:
            d = dt.date.fromisoformat(str(h.get("date") or "")[:10])
        except ValueError:
            continue
        days = max(0, (today - d).days)
        z = "".join(ch for ch in str(h.get("zip") or "") if ch.isdigit())[:5]
        if len(z) != 5:
            continue
        names = h.get("streets")
        if isinstance(names, str):
            names = names.replace(";", ",").split(",")
        names = [x for x in (names if isinstance(names, list) else []) if isinstance(x, str)]
        if isinstance(h.get("street"), str) and h.get("street"):
            names.append(h["street"])
        lo, hi = _num_or_none(h.get("low")), _num_or_none(h.get("high"))
        if lo is not None and hi is not None and lo > hi:
            lo, hi = hi, lo
        for nm in names[:50]:
            k = street_key(nm[:120])
            if k:
                out.setdefault((k, z), []).append((days, lo, hi))
    return out


def _days_since_hung(cell, hidx):
    """Days since a route covered one of the patch's streets in its ZIP; a route with house numbers counts only
    where its numbers meet the street's numbers in this patch."""
    best = None
    for st in cell.get("streets") or ():
        if not isinstance(st, (list, tuple)) or not st:
            continue
        key = st[4] if len(st) > 4 and st[4] else street_key(st[0])
        lo = st[2] if len(st) > 2 else None
        hi = st[3] if len(st) > 3 else None
        for days, hlo, hhi in hidx.get((key, cell.get("zip") or ""), ()):
            if hlo is not None and hhi is not None and lo is not None and hi is not None and (hhi < lo or hlo > hi):
                continue
            best = days if best is None else min(best, days)
    return best


# ------------------------------------------------------------------ ranking
def _pct_rank(values, direction):
    """0..1 per value (None stays None): its rank among the others, oriented so 1 is best."""
    idx = [i for i, v in enumerate(values) if v is not None and not (isinstance(v, float) and math.isnan(v))]
    out = [None] * len(values)
    if not idx:
        return out
    vals = np.array([float(values[i]) for i in idx])
    if direction < 0:
        vals = -vals
    if len(idx) == 1 or np.all(vals == vals[0]):
        for i in idx:
            out[i] = 0.5
        return out
    # dense ranks over the distinct values: ties share a score, the best value is 1 and the worst 0 however many
    # patches share it
    ranks = pd.Series(vals).rank(method="dense").values
    top = float(ranks.max())
    for j, i in enumerate(idx):
        out[i] = float((ranks[j] - 1) / (top - 1)) if top > 1 else 0.5
    return out


def _fmt(v, nd=0):
    if v is None:
        return "?"
    if nd == 0:
        return f"{int(round(float(v))):,}"
    return f"{float(v):,.{nd}f}"


def _phrase(name, v, cell, product_label, near_label):
    if name == "few_customers":
        n = cell["_customers"]
        return "no customers here yet" if not n else f"{n} customer{'s' if n != 1 else ''} among {cell['homes']} homes"
    if name == "product_nearby":
        return f"{_fmt(v)} {product_label} job{'s' if v != 1 else ''} nearby" if v else f"no {product_label} jobs nearby"
    if name == "fire_zone":
        return f"{_fmt(v)}% of the ZIP: {ingredient_label('fire_zone').lower()}"
    if name == "burned_before":
        y = cell.get("last_fire_year")
        return f"a past fire reached {_fmt(v)}% of the homes" + (f" (last {y})" if y else "")
    if name == "safety_area":
        return f"{_fmt(v)}% of homes in a safety site"
    if name == "inland":
        return f"{_fmt(v, 1)} miles from the coast"
    if name == "lot_size":
        return f"{_fmt(v)}% of lots are half an acre or more"
    if name == "single_family":
        return f"{_fmt(v)}% single-family lots"
    if name == "owners":
        return f"{_fmt(v)}% owner-occupied (ZIP)"
    if name == "older_homes":
        return f"{_fmt(v)}% of the ZIP built before 1980"
    if name == "not_hung":
        return "not hung before" if v >= NEVER_HUNG_DAYS else f"last hung {_fmt(v)} days ago"
    if name == "close":
        return f"{_fmt(v, 1)} miles from {near_label}"
    return f"{name} {v}"


def rank(recipe, cells, cust, zipfacts, pack, zip_names, hung=None, today=None, origin=None):
    """The ranked neighborhoods for a cleaned recipe. Returns {"rows", "columns", "notes", "read_as", "recipe",
    "candidates", "title"}. Raises RecipeError for a question back to the person."""
    today = _as_date(today)
    notes = []
    prods = products(pack)
    product = recipe.get("product")
    pinfo = prods.get(product) or {}
    product_label = str(pinfo.get("label") or (product or "").replace("_", " ") or "matching")
    families = {str(f) for f in (pinfo.get("families") or [])}
    w = dict(recipe["ingredients"])
    if "product_nearby" in w and not families:
        notes.append("Jobs nearby needs a product line (the pack's prospect products); left out.")
        w.pop("product_nearby")
    zf = (zipfacts or {}).get("zips") if isinstance(zipfacts, dict) and "zips" in zipfacts else (zipfacts or {})
    ex = recipe["exclude"]
    # where
    near_keys, near_label, centres = None, None, []
    if recipe.get("near"):
        labels = []
        for place in near_list(recipe["near"]):
            k, lab, cen = resolve_near(place, cells, pack, zip_names)
            if k is None:
                continue                         # nothing nameable in it ("-")
            near_keys = set(k) if near_keys is None else near_keys | set(k)
            labels.append(lab)
            centres.append(cen)
        near_label = " or ".join(labels) or None
    # with no place in the ask, distance is from the pack's origin, which the op resolves to a point (street_centre)
    if not centres and origin:
        centres, near_label = [(origin[0], origin[1])], (origin[2] if len(origin) > 2 else "the shop")
    if not centres and "close" in w:
        notes.append("Close by needs a place (\"near Cedar Hills\"); left out.")
        w.pop("close")
    if not w:
        raise RecipeError("With those left out nothing is left to rank by. Which matters most: few customers, "
                          "fire risk, sunny inland, bigger lots?")
    hidx = hung_index(hung, today)
    if hung is None:
        if "not_hung" in w or ex.get("hung_within_days"):
            notes.append("Doorhangers' log wasn't available, so streets hung recently aren't left out.")
        w.pop("not_hung", None)
    # candidates
    cand, left = [], {"few homes": 0, "hung recently": 0, "too many customers": 0, "too far": 0}
    for c in cells:
        if near_keys is not None and c["key"] not in near_keys:
            continue
        if c["homes"] < ex["min_homes"]:
            left["few homes"] += 1
            continue
        cc = dict(c)
        ci = cust.get(c["key"]) or {}
        cc["_customers"] = int(ci.get("customers", 0))
        days = _days_since_hung(c, hidx) if hung is not None else None
        cc["_hung_days"] = days
        if ex.get("hung_within_days") and days is not None and days < ex["hung_within_days"]:
            left["hung recently"] += 1
            continue
        if ex.get("max_customers") is not None and cc["_customers"] > ex["max_customers"]:
            left["too many customers"] += 1
            continue
        if centres:
            cc["_miles"] = min(miles(p[0], p[1], c["lat"], c["lon"]) for p in centres)
            if recipe.get("max_miles") and cc["_miles"] > recipe["max_miles"]:
                left["too far"] += 1
                continue
        cand.append(cc)
    if not cand:
        raise RecipeError("Every neighborhood there was left out by the exclusions" +
                          "".join(f"; {n} {k}" for k, n in left.items() if n) + ". Loosen one?")
    # raw values
    described = {c["key"] for c in cells}
    raw = {k: [] for k in w}
    for c in cand:
        z = zf.get(c["zip"]) or {}
        for k in w:
            if k == "few_customers":
                v = 100.0 * c["_customers"] / max(1, c["homes"])
            elif k == "product_nearby":
                v = 0
                for nk in neighbours(c["key"]):
                    if nk not in described:
                        continue                 # the same patches every other count uses
                    fam = (cust.get(nk) or {}).get("families") or {}
                    v += sum(int(n) for f, n in fam.items() if f in families)
            elif k == "fire_zone":
                v = z.get("fire_vh_pct")
            elif k == "owners":
                v = z.get("owner_pct")
            elif k == "older_homes":
                v = z.get("pre1980_pct")
            elif k == "not_hung":
                v = NEVER_HUNG_DAYS if c["_hung_days"] is None else c["_hung_days"]
            elif k == "close":
                v = c.get("_miles")
            else:
                v = c.get(k)
            raw[k].append(v)
    scores = {k: _pct_rank(raw[k], INGREDIENTS[k][2]) for k in w}
    for k in list(w):
        if all(s is None for s in scores[k]):
            notes.append(f"{ingredient_label(k)}: no data here; left out.")
            w.pop(k)
    if not w:
        raise RecipeError("None of those ingredients has data for this area.")
    tot = sum(w.values())
    for i, c in enumerate(cand):
        # a patch with no number for an ingredient sits in the middle on it, neither helped nor hurt; leaving
        # the ingredient out of its mix instead let a patch missing its worst number outrank complete ones
        mix = sum(wt * (0.5 if scores[k][i] is None else scores[k][i]) for k, wt in w.items()) / tot if tot else 0
        c["_mix"] = mix
        c["_parts"] = [(k, wt * (0.5 if scores[k][i] is None else scores[k][i]), raw[k][i], scores[k][i])
                       for k, wt in w.items()]
    cand.sort(key=lambda c: (-c["_mix"], -c["homes"], c["key"]))
    chosen, taken = [], set()
    for c in cand:
        if recipe.get("spread", True) and c["key"] in taken:
            continue
        chosen.append(c)
        taken.update(neighbours(c["key"]))
        if len(chosen) >= recipe["how_many"]:
            break
    rows = []
    zn = zip_names
    for n, c in enumerate(chosen, 1):
        best = sorted([p for p in c["_parts"] if p[3] is not None], key=lambda p: -p[1])[:3]
        reason = "; ".join(_phrase(k, v, c, product_label, near_label or "") for k, _, v, _s in best)
        town = c.get("community") or zn.get(c["zip"], "")
        top = [st[0] for st in c["streets"][:3]]
        name = (f"{top[0]} area" if top else "Neighborhood") + (f", {town}" if town else "")
        ing = {}
        for k, _, v, s in c["_parts"]:
            ing[k] = {"value": None if v is None else (round(float(v), 2) if isinstance(v, float) else v),
                      "score": None if s is None else int(round(100 * s))}
        rows.append({"rank": n, "key": c["key"], "name": name, "town": town, "zip": c["zip"],
                     "region": pack.region_of_area(zn.get(c["zip"], "")) if hasattr(pack, "region_of_area") else "",
                     "homes": c["homes"], "walks": max(1, round(c["homes"] / WALK_SIZE)),
                     "streets": [st[0] for st in c["streets"][:8]], "street_homes": [st[1] for st in c["streets"][:8]],
                     "street_numbers": [[st[2], st[3]] if len(st) > 3 else [None, None] for st in c["streets"][:8]],
                     "customers": c["_customers"], "mix": int(round(100 * c["_mix"])), "reason": reason,
                     "lat": c["lat"], "lon": c["lon"], "bounds": bounds_of(c["key"]),
                     "miles": None if c.get("_miles") is None else round(c["_miles"], 1),
                     "last_hung_days": c["_hung_days"], "ingredients": ing})
        if c["homes"] > BIG_HOMES:
            rows[-1]["note"] = f"About {rows[-1]['walks']} walks of {WALK_SIZE}; plan part of it."
    used = [f"{ingredient_label(k).lower()} ({int(round(wt * 100))}%)" for k, wt in
            sorted(w.items(), key=lambda kv: -kv[1])]
    read_as = []
    read_as.append("Product line: " + (product_label if product else "any"))
    if near_label:
        read_as.append(f"Where: {near_label}" if recipe.get("near") else f"Distance from: {near_label}")
    read_as.append("Ranked by: " + ", ".join(used))
    exl = []
    if ex.get("hung_within_days") and hung is not None:
        exl.append(f"streets hung in the last {ex['hung_within_days']} days")
    if ex.get("max_customers") is not None:
        exl.append(f"more than {ex['max_customers']} customers")
    if recipe.get("max_miles") and centres:
        exl.append(f"farther than {recipe['max_miles']:g} miles from {near_label}")
    elif recipe.get("max_miles"):
        notes.append("The distance limit needs a place to measure from; left out.")
    if exl:
        read_as.append("Left out: " + ", ".join(exl))
    zip_wide = [ingredient_label(k).lower() for k in w if INGREDIENTS[k][3]]
    if zip_wide:
        notes.append("ZIP-wide, not per street: " + ", ".join(zip_wide) + " (Census and hazard zones by ZIP).")
    title = ("Where to hang " + (product_label + " " if product else "") + "door hangers" +
             (f" near {near_label}" if recipe.get("near") and near_label else ""))
    columns = ["rank", "name", "zip", "homes", "customers", "mix", "reason"]
    return {"rows": rows, "columns": columns, "notes": notes, "read_as": read_as, "title": title,
            "recipe": recipe, "candidates": len(cand), "left_out": {k: v for k, v in left.items() if v},
            "ingredients": {k: {"label": ingredient_label(k), "unit": INGREDIENTS[k][1],
                                "better": "more" if INGREDIENTS[k][2] > 0 else "less", "zip_wide": INGREDIENTS[k][3]}
                            for k in ORDER}}


def vocab(pack):
    """What a caller may write in a recipe (the chat's closed lists; the page's sliders)."""
    prods = products(pack)
    return {"products": {k: str(v.get("label") or k) for k, v in prods.items()},
            "ingredients": {k: {"label": v[0], "unit": v[1], "better": "more" if v[2] > 0 else "less",
                                "zip_wide": v[3]} for k, v in INGREDIENTS.items()},
            "defaults": {k: dict(v.get("weights") or {}) for k, v in prods.items()},
            "default_weights": dict((getattr(pack, "prospect", None) or {}).get("default_weights") or DEFAULT_WEIGHTS),
            "exclude": {"hung_within_days": "number of days", "max_customers": "a count", "min_homes": "a count"},
            "how_many_max": MAX_HOW_MANY}
