"""What a place is like: the region's address points, parcels, fire history, sales tax areas,
vector surveillance zones and coastline, kept on the hub and read from the region pack's layers.

Every source is named by the region pack (region.py), so another county's public layers are used as
they are published, and a layer the region does not name is simply not read. The sources:

  addresses  the region's address points, every one, by OBJECTID range. Kept here so a customer's (or a
             home's) street address is placed without ever being sent anywhere. Re-read only when the
             layer changes (count, highest ID, sum of house numbers), at least every 90 days.
  points     the same address points as the layer publishes them, every field in its own words, for the
             canvassing app's lookups (points.py).
  parcels    public parcels: parcel number, acreage, building class and jurisdiction. Public fields only.
  fires      the region's fire history (perimeters with a name, year and acres), plus the years after it
             from the national wildfire perimeters (kind "wfigs"), when the region names that layer.
  tax        sales and use tax areas, with each area's rate.
  vector     vector surveillance zones, and for each quarter whether each invasive species was found.
  coast      the coastline (the ocean and the bays), for straight-line miles to the coast.

What leaves the hub: the queries for those public layers only. Customers are placed here, by the
import, and only what describes the place is kept with them (tax area and rate, miles to the coast,
vector zone, fire history, building class), never coordinates or the address.

Kept beside the store (an import never wipes it):
    <store folder>/places/addresses.sqlite   the address points (number, street, ZIP, unit, point, parcel)
    <store folder>/places/points.sqlite      the canvassing app's copy, in the layer's own words
    <store folder>/places/parcels.sqlite     parcel -> acres, building class, jurisdiction
    <store folder>/places/{fires,tax,vector,coast}.json
    <store folder>/places/zip_facts.json     what each service-area ZIP is like (merged into the ZIP facts)
    <store folder>/places/meta.json, status.json
"""
import datetime as dt
import functools
import gzip
import hashlib
import io
import json
import logging
import math
import os
import re
import sqlite3
import struct
import threading
import time
import zipfile
from pathlib import Path

import numpy as np

from . import safety as SA
from . import polite as P
from . import region as R
from . import zipfacts as Z

_log = logging.getLogger("zorderz_hub.places")

COAST_BASE = "https://www2.census.gov/geo/tiger"       # the Census coastline (kind "tiger_coastline")
RECENT_FIELDS = "attr_IncidentName,poly_IncidentName,poly_GISAcres,attr_FireDiscoveryDateTime,attr_IrwinID"
PAUSE = 1.0
BUDGET = 3200                          # requests a run at most (address points, parcels, the canvassing copy, the light ones)
MAX_RUN_HOURS = 3
AT_DEFAULT = (1, 10)                   # local; inside the off-peak hours, before the weather's
FIRST_DELAY = 1200
RETRY_HOURS = 20
HEAVY_GAP_HOURS = 20                   # one big download a night, also across two runs the same night
MI_PER_DEG = 69.17
ZIP_SAMPLE = 1500                      # address points per ZIP for the ZIP's facts
PROGRESS_EVERY = 30

# name: (check every N days, rebuild at least every N days, heavy: at most one heavy source a night)
SOURCES = {
    "addresses": (30, 90, True),
    "parcels": (90, 180, True),
    "fires": (7, 90, False),
    "tax": (30, 30, False),
    "vector": (7, 7, False),
    "coast": (365, 365, False),
    "points": (7, 90, True),
}
# the region layer each source reads
LAYER_OF = {"addresses": "addresses", "parcels": "parcels", "fires": "fires", "tax": "tax",
            "vector": "vector_zones", "coast": "coast", "points": "points"}
# how far from the coast a customer is, in bands (charts split by these)
COAST_BANDS = ((1, "Under 1 mi"), (3, "1–3 mi"), (10, "3–10 mi"), (25, "10–25 mi"), (None, "25 mi or more"))
COAST_BAND_NONE = "(not placed)"
COAST_SIDES = ("Coastal", "Inland")


def coast_side_miles():
    """Coastal means within this many miles of the coast (the region pack's coast_side_miles, default 3)."""
    return R.current().coast_side_miles


def coast_side(mi):
    """"Coastal" within coast_side_miles() of the coast, "Inland" otherwise, "(not placed)" with no distance."""
    if mi is None or (isinstance(mi, float) and math.isnan(mi)):
        return COAST_BAND_NONE
    return COAST_SIDES[0] if float(mi) < coast_side_miles() else COAST_SIDES[1]


TAX_NONE = "(not placed)"


def build_classes():
    """The region's building classes by the first letter of a parcel's class code ({} when it names none)."""
    return dict(R.current().data.get("building_classes") or {})


def invasive_species():
    """The species the region's vector layer counts as invasive (the region pack's vector.species)."""
    return tuple((R.current().data.get("vector") or {}).get("species") or ())


def quarter0():
    """The vector feed's quarter 0, as (year, quarter)."""
    q = (R.current().data.get("vector") or {}).get("quarter0") or [2010, 1]
    return int(q[0]), int(q[1])


class PlacesError(Exception):
    pass


def folder_of(cfg):
    return Path(cfg["store"]).with_name("places")


def settings(cfg):
    p = dict(cfg.get("places") or {})
    at = str(p.get("at", "%02d:%02d" % AT_DEFAULT))
    try:
        at_t = tuple(int(x) for x in at.split(":")[:2])
    except ValueError:
        at_t = AT_DEFAULT
    reg = R.load(cfg)
    want = p.get("sources") or list(SOURCES)
    return {
        "mode": str(p.get("mode", "on")).lower(),
        "fetch": str(p.get("fetch", "auto")).lower(),
        "county": reg.county_name,
        "region": reg,
        "coast_base": str(p.get("coast_base") or COAST_BASE).rstrip("/"),
        "sources": [s for s in want if s in SOURCES and reg.has(LAYER_OF[s])],
        "at": at_t,
    }


def _layer(s, name):
    lay = s["region"].layer(name) if s.get("region") else None
    if lay is None:
        raise PlacesError(f"the region pack names no {name} layer")
    return lay


# ---------------------------------------------------------------- geometry
def decode_polyline(text, precision=5):
    """An encoded polyline -> [[lon, lat], ...] (a vector feed's zones)."""
    out, lat, lon, i, n = [], 0, 0, 0, len(text)
    f = 10 ** precision
    while i < n:
        vals = []
        for _ in range(2):
            shift = res = 0
            while True:
                b = ord(text[i]) - 63
                i += 1
                res |= (b & 0x1F) << shift
                shift += 5
                if b < 0x20:
                    break
            vals.append(~(res >> 1) if res & 1 else res >> 1)
        lat += vals[0]
        lon += vals[1]
        out.append([lon / f, lat / f])
    return out


def grid():
    reg = R.current()
    return Z.Grid(reg.box, reg.cell)


def label_grid(features, g, fill=-1):
    """An int32 grid: the index of the feature covering each cell (later features win), fill elsewhere."""
    out = np.full((g.h, g.w), fill, dtype=np.int32)
    f, r, c0, c1 = Z.spans(features, g)
    for k, rr, a, b in zip(f.tolist(), r.tolist(), c0.tolist(), c1.tolist()):
        out[rr, a:b] = k
    return out


def cells(lon, lat, g):
    """(row, col, inside) for arrays of points."""
    lon, lat = np.asarray(lon, dtype=float), np.asarray(lat, dtype=float)
    r = np.floor((lat - g.lat0) / g.d).astype(np.int64)
    c = np.floor((lon - g.lon0) / g.d).astype(np.int64)
    ok = (r >= 0) & (r < g.h) & (c >= 0) & (c < g.w)
    return np.clip(r, 0, g.h - 1), np.clip(c, 0, g.w - 1), ok

def inside(lon, lat, rings):
    """Even-odd point-in-polygon for arrays of points against one feature's rings (exact; customers)."""
    lon, lat = np.asarray(lon, dtype=float), np.asarray(lat, dtype=float)
    hit = np.zeros(lon.shape, dtype=bool)
    for ring in rings:
        a = np.asarray(ring, dtype=float)
        if len(a) < 3:
            continue
        b = np.roll(a, -1, axis=0)
        for (x0, y0), (x1, y1) in zip(a.tolist(), b.tolist()):
            if y0 == y1:
                continue
            cross = (y0 > lat) != (y1 > lat)
            if not cross.any():
                continue
            xc = x0 + (lat - y0) * (x1 - x0) / (y1 - y0)
            hit ^= cross & (lon < xc)
    return hit


def bbox_of(rings):
    xs = [p[0] for r in rings for p in r]
    ys = [p[1] for r in rings for p in r]
    return (min(xs), min(ys), max(xs), max(ys)) if xs else (0, 0, 0, 0)



def miles_to(points_lon, points_lat, coast):
    """Straight-line miles from each point to the nearest coast sample (equirectangular at the region's
    latitude; well under 1% error across a county)."""
    if coast is None or not len(coast):
        return np.full(len(points_lon), np.nan)
    k = R.cos_lat()
    cx = coast[:, 0] * k * MI_PER_DEG
    cy = coast[:, 1] * MI_PER_DEG
    px = np.asarray(points_lon, dtype=float) * k * MI_PER_DEG
    py = np.asarray(points_lat, dtype=float) * MI_PER_DEG
    out = np.empty(len(px))
    step = max(1, 4_000_000 // max(1, len(cx)))
    for i in range(0, len(px), step):
        dx = px[i:i + step, None] - cx[None, :]
        dy = py[i:i + step, None] - cy[None, :]
        out[i:i + step] = np.sqrt((dx * dx + dy * dy).min(axis=1))
    return out


def coast_band(mi):
    if mi is None or (isinstance(mi, float) and math.isnan(mi)):
        return COAST_BAND_NONE
    for top, label in COAST_BANDS:
        if top is None or mi < top:
            return label
    return COAST_BANDS[-1][1]


def densify(line, step_mi=0.06):
    """Points every ~100 m along a polyline [[lon, lat], ...]."""
    out = []
    k = R.cos_lat()
    for (x0, y0), (x1, y1) in zip(line[:-1], line[1:]):
        dmi = math.hypot((x1 - x0) * k * MI_PER_DEG, (y1 - y0) * MI_PER_DEG)
        n = max(1, int(dmi / step_mi))
        for j in range(n):
            out.append([x0 + (x1 - x0) * j / n, y0 + (y1 - y0) * j / n])
    if line:
        out.append(list(line[-1]))
    return out


def read_shp_lines(data, box=None):
    """Polylines (shape type 3) from a .shp file's bytes, kept where they come within the box."""
    lat0, lat1, lon0, lon1 = box or R.box()
    if len(data) < 100 or struct.unpack(">i", data[:4])[0] != 9994:
        raise PlacesError("the coastline file isn't a shapefile")
    pos, out = 100, []
    while pos + 8 <= len(data):
        _, words = struct.unpack(">ii", data[pos:pos + 8])
        body = data[pos + 8:pos + 8 + words * 2]
        pos += 8 + words * 2
        if len(body) < 44 or struct.unpack("<i", body[:4])[0] not in (3, 13, 23):
            continue
        xmin, ymin, xmax, ymax = struct.unpack("<4d", body[4:36])
        if xmax < lon0 or xmin > lon1 or ymax < lat0 or ymin > lat1:
            continue
        nparts, npts = struct.unpack("<2i", body[36:44])
        parts = list(struct.unpack(f"<{nparts}i", body[44:44 + 4 * nparts])) + [npts]
        xy = np.frombuffer(body[44 + 4 * nparts:44 + 4 * nparts + 16 * npts], dtype="<f8").reshape(-1, 2)
        for a, b in zip(parts[:-1], parts[1:]):
            out.append(xy[a:b].tolist())
    return out


# ---------------------------------------------------------------- reading the sources
class Fetcher(SA.Fetcher):
    """safety.Fetcher (polite; POST for long queries), with this module's User-Agent and budget."""
    UA_TEXT = "zorderz-hub/{v} (places: the region's public layers; monthly)"

    def __init__(self, mode="auto", version="", pause=PAUSE, opener=None, cfg=None, budget=BUDGET, say=None):
        super().__init__(mode=mode, version=version, pause=pause, opener=opener, cfg=cfg, budget=budget, say=say)
        self.ua = P.user_agent(self.UA_TEXT.format(v=version or "?"), cfg)

    def q(self, url, params):
        try:
            return self.query(url, params)
        except SA.SafetyError as e:
            raise PlacesError(str(e)) from None


def layer_stats(f, lay, fields):
    """{name: value} of count / max OBJECTID / sums, one request (a layer's stand-in for an edit date).
    fields: (name, statistic, canonical field)."""
    stats = [{"statisticType": t, "onStatisticField": lay.f(fld), "outStatisticFieldName": n} for n, t, fld in fields]
    got = f.q(lay.url, {"where": lay.with_filter("1=1"), "outStatistics": json.dumps(stats, separators=(",", ":"))})
    a = ((got.get("features") or [{}])[0] or {}).get("attributes") or {}
    out = {n: a.get(n) for n, _t, _f in fields}
    if not all(isinstance(v, (int, float)) for v in out.values()):
        raise PlacesError(f"{Z._host(lay.url)} gave no summary of the layer")
    return out


def by_id_ranges(f, lay, hi, page, params, label="", say=None, total=None):
    """Every feature with OBJECTID 1..hi, in ranges of `page` (a range the server cuts short is split in two).
    Yields lists of features."""
    todo = [(lo, min(hi, lo + page)) for lo in range(0, int(hi), page)]
    done, told = 0, time.monotonic()
    oid = lay.f("OBJECTID")
    while todo:
        lo, up = todo.pop(0)
        got = f.q(lay.url, dict(params, where=lay.with_filter(f"{oid} > {lo} AND {oid} <= {up}")))
        feats = got.get("features") or []
        if got.get("exceededTransferLimit") and up - lo > 1:
            mid = (lo + up) // 2
            todo[:0] = [(lo, mid), (mid, up)]
            continue
        done += len(feats)
        if feats:
            yield feats
        if say and time.monotonic() - told >= PROGRESS_EVERY:
            say(f"    {label}: {done:,}" + (f" of {int(total):,}" if total else "") + " so far …")
            told = time.monotonic()


def addr_row(ft, lay):
    a = lay.attrs(ft)
    g = ft.get("geometry") or {}
    num = a.get("NUM")
    if not isinstance(num, (int, float)) or g.get("x") is None:
        return None
    line = SA.county_line({"num": int(num), "pdir": a.get("PREDIR"), "name": a.get("NAME"),
                           "sfx": a.get("SUFFIX"), "postd": a.get("POSTDIR")})
    _, st = SA.street_key(line)
    if not st:
        return None
    return (int(a.get("OBJECTID") or 0), int(num), st, SA._undirected(st), str(a.get("ZIP") or "")[:5],
            str(a.get("UNIT") or "").strip().casefold(), round(float(g["x"]), 6), round(float(g["y"]), 6),
            str(a.get("PARCEL") or "").strip() or None)


def points_row(ft, lay):
    """One address point as the layer wrote every field (points.py)."""
    a = {str(k).upper(): v for k, v in lay.attrs(ft).items()}
    g = ft.get("geometry") or {}
    oid = a.get("OBJECTID")
    if not isinstance(oid, (int, float)):
        return None

    def t(k, n):
        v = a.get(k)
        return None if v is None else str(v)[:n]
    num = a.get("NUM")
    seg = a.get("SEGMENT")
    x, y = g.get("x"), g.get("y")
    ok = isinstance(x, (int, float)) and isinstance(y, (int, float)) and x == x and y == y
    return (int(oid), float(num) if isinstance(num, (int, float)) else None, t("FRAC", 8), t("PREDIR", 4),
            t("NAME", 80), t("POSTDIR", 4), t("SUFFIX", 8), t("UNIT", 16), t("JURIS", 8), t("ZIP", 10),
            int(seg) if isinstance(seg, (int, float)) else None, t("PARCEL", 20), t("COMMUNITY", 60),
            t("KIND", 30), float(x) if ok else None, float(y) if ok else None)


def read_by_ids(f, lay, params, label, batch=500):
    """(ids, features): every feature of a layer, by OBJECTID (a reply cut short is never taken as complete).
    IDs a reply leaves out are asked for again (smaller batches when the server says it cut the reply); an ID
    missing twice is let go (deleted meanwhile), and more than 1% let go fails the read."""
    url = lay.url
    got = f.q(url, {"where": lay.with_filter("1=1"), "returnIdsOnly": "true"})
    oid = str(got.get("objectIdFieldName") or lay.f("OBJECTID"))
    ids = sorted({int(i) for i in (got.get("objectIds") or []) if isinstance(i, (int, float))})
    fields = params.get("outFields") or ""
    if oid not in fields.split(","):
        params = dict(params, outFields=f"{oid},{fields}" if fields else oid)
    pending, size, missed, dropped, out = ids, batch, {}, 0, []
    allowed = len(ids) // 100
    while pending:
        ask, pending = pending[:size], pending[size:]
        got = f.q(url, dict(params, objectIds=",".join(map(str, ask))))
        want, have = set(ask), set()
        for ft in got.get("features") or []:
            try:
                i = int((ft.get("attributes") or {}).get(oid))
            except (TypeError, ValueError):
                continue
            if i in want and i not in have:
                have.add(i)
                out.append(ft)
        missing = [i for i in ask if i not in have]
        if got.get("exceededTransferLimit") and have:
            size = max(1, len(have))
            pending = missing + pending
        elif missing:
            again = []
            for i in missing:
                missed[i] = missed.get(i, 0) + 1
                if missed[i] >= 2:
                    dropped += 1
                else:
                    again.append(i)
            if dropped > allowed:
                raise PlacesError(f"{label}: the server didn't send {dropped:,} of the {len(ids):,} asked for")
            pending = again + pending
        else:
            size = min(batch, size * 2)
    return ids, out


def _epoch_day(v, tz=None):
    """A layer's date (epoch milliseconds or an ISO date) as a date, or None."""
    if isinstance(v, (int, float)):
        try:
            return dt.datetime.fromtimestamp(v / 1000, tz or dt.timezone.utc).date()
        except (ValueError, OverflowError, OSError):
            return None
    try:
        return dt.date.fromisoformat(str(v)[:10])
    except ValueError:
        return None


def fetch_fires(f, s):
    lay = _layer(s, "fires")
    ids, feats = read_by_ids(f, lay, {"outFields": lay.out_fields(), "returnGeometry": "true", "outSR": "4326",
                                      "maxAllowableOffset": "0.0003", "geometryPrecision": "5"}, "fire history")
    out = []
    for ft in feats:
        a = lay.attrs(ft)
        rings = [r for r in ((ft.get("geometry") or {}).get("rings") or []) if len(r) >= 4]
        if not rings:
            continue
        try:
            year = int(a.get("YEAR"))
        except (TypeError, ValueError):
            continue
        alarm = _epoch_day(a.get("ALARM"))
        out.append({"name": str(a.get("NAME") or "").strip().title() or "(unnamed)", "year": year,
                    "acres": round(float(a.get("ACRES") or 0), 1), "cause": a.get("CAUSE"),
                    "alarm": alarm.isoformat() if alarm else None, "rings": rings})
    if not out:
        raise PlacesError(f"none of the {len(ids)} fire perimeters had an outline and a year")
    return out


def _local_tz():
    try:
        from zoneinfo import ZoneInfo
        return ZoneInfo(R.current().timezone or "UTC")
    except Exception:                                  # noqa: BLE001 — no tz database: UTC
        return dt.timezone.utc


def fetch_recent_fires(f, s, after_year, region_fires=()):
    """Wildfires discovered after `after_year` whose perimeter reaches the region's box (the national perimeters,
    kind "wfigs"), in the same shape as fetch_fires, marked "national". One request. Dates are local; a fire the
    region's own history also lists (same name and year) is the region's."""
    reg = s.get("region") or R.current()
    lay = reg.layer("recent_fires")
    if lay is None:
        return []
    lat0, lat1, lon0, lon1 = reg.box
    tz = _local_tz()
    start = dt.datetime(int(after_year) + 1, 1, 1, tzinfo=tz).astimezone(dt.timezone.utc)
    got = f.q(lay.url, {
        "where": f"attr_IncidentTypeCategory = 'WF' AND attr_FireDiscoveryDateTime >= "
                 f"TIMESTAMP '{start.strftime('%Y-%m-%d %H:%M:%S')}'",               # local midnight, in UTC
        "geometry": json.dumps({"xmin": lon0, "ymin": lat0, "xmax": lon1, "ymax": lat1, "spatialReference": {"wkid": 4326}},
                               separators=(",", ":")),
        "geometryType": "esriGeometryEnvelope", "inSR": "4326", "spatialRel": "esriSpatialRelIntersects",
        "outFields": RECENT_FIELDS, "returnGeometry": "true", "outSR": "4326", "maxAllowableOffset": "0.0003",
        "geometryPrecision": "5"})
    if got.get("exceededTransferLimit"):
        raise PlacesError("the national fire perimeters cut their list short; kept the recent fires on file")
    out, seen = [], set()
    known = {(str(x.get("name") or "").casefold(), x.get("year")) for x in region_fires}
    for ft in got.get("features") or []:
        a = ft.get("attributes") or {}
        rings = [r for r in ((ft.get("geometry") or {}).get("rings") or []) if len(r) >= 4]
        found = a.get("attr_FireDiscoveryDateTime")
        key = a.get("attr_IrwinID") or (a.get("attr_IncidentName"), found)
        if not rings or not isinstance(found, (int, float)) or key in seen:
            continue
        seen.add(key)
        try:
            day = dt.datetime.fromtimestamp(found / 1000, tz).date()
            acres = round(float(a.get("poly_GISAcres") or 0), 1)
        except (ValueError, OverflowError, OSError, TypeError):
            continue                                   # one bad record is skipped, not the list
        name = str(a.get("attr_IncidentName") or a.get("poly_IncidentName") or "").strip().title() or "(unnamed)"
        if day.year <= int(after_year) or (name.casefold(), day.year) in known:
            continue
        out.append({"name": name, "year": day.year, "acres": acres, "cause": None, "alarm": day.isoformat(),
                    "rings": rings, "source": "national"})
    return sorted(out, key=lambda x: (x["alarm"], x["name"]))


def fetch_tax(f, s):
    lay = _layer(s, "tax")
    got = f.q(lay.url, {"where": lay.with_filter("1=1"), "outFields": lay.out_fields(),
                        "returnGeometry": "true", "outSR": "4326", "maxAllowableOffset": "0.0001",
                        "geometryPrecision": "6"})
    if got.get("exceededTransferLimit"):
        raise PlacesError("the tax layer cut its list of the region's tax areas short; kept the ones on file")
    scale = float((lay.spec.get("rate_scale") or 100))       # a layer's rate as a fraction (x100) or a percent (x1)
    areas = []
    for ft in got.get("features") or []:
        a = lay.attrs(ft)
        rings = [r for r in ((ft.get("geometry") or {}).get("rings") or []) if len(r) >= 4]
        rate = a.get("RATE")
        if not rings or not isinstance(rate, (int, float)):
            continue
        stamp = _epoch_day(a.get("AS_OF"))
        areas.append({"name": str(a.get("NAME") or a.get("JURIS") or "").strip(),
                      "rate": round(float(rate) * scale, 3), "state": a.get("STATE_RATE"),
                      "county": a.get("COUNTY_RATE"), "city": a.get("CITY_RATE"), "code": a.get("CODE"),
                      "as_of": stamp.isoformat() if stamp else None, "rings": rings, "bbox": bbox_of(rings)})
    if not areas:
        raise PlacesError("the tax layer listed no tax areas in the region")
    return sorted(areas, key=lambda x: x["name"])


def fetch_vector(f, s, today):
    """The region's vector surveillance zones: kind "vectorsurv" (the feed's own JSON, polyline outlines and a
    quarterly timeline per species), or a GeoJSON file layer whose features carry name, agency and species
    {species: [0/1 per quarter]}."""
    lay = _layer(s, "vector_zones")
    reg = s.get("region") or R.current()
    data, _ = f.get(lay.url, limit=60 * 1024 * 1024)
    try:
        got = json.loads(data.decode("utf-8"))
    except ValueError:
        raise PlacesError("the vector feed answered something that isn't JSON") from None
    county = (lay.spec.get("county") or reg.county_name or "").casefold()
    zones = []
    for ft in got.get("features") or []:
        p = ft.get("properties") or {}
        if lay.kind == "vectorsurv":
            if county and str(p.get("county") or "").casefold() != county:
                continue
            rings = []
            for part in (ft.get("geometry") or {}).get("coordinates") or []:
                for seg in part:
                    pts = decode_polyline(str((seg or {}).get("point") or ""))
                    if len(pts) >= 3:
                        if pts[0] != pts[-1]:
                            pts.append(pts[0])
                        rings.append(pts)
            tl = p.get("timeline_data") or {}
            name, agency = str(p.get("subcounty") or "?"), str(p.get("agency") or "")
        else:
            g = ft.get("geometry") or {}
            c = g.get("coordinates") or []
            rings = [r for r in (c if g.get("type") == "Polygon" else [r for poly in c for r in poly]) if len(r) >= 4]
            tl = p.get("species") or {}
            name, agency = str(p.get("name") or "?"), str(p.get("agency") or "")
        species = {k: [int(bool(x)) for x in v] for k, v in tl.items() if isinstance(v, list) and k != "non-invasive"}
        if rings:
            zones.append({"name": name, "agency": agency, "rings": rings, "bbox": bbox_of(rings), "species": species})
    if not zones:
        raise PlacesError("the vector layer listed no zones in the region")
    # A feed may list an area twice (a neighbouring district's view): the region's own program's zones are drawn
    # last, so where two overlap the region's answer wins.
    zones.sort(key=lambda z: (bool(county) and county in z["agency"].casefold(), z["name"]))
    notices = []
    nl = reg.layer("vector_notices")
    if nl is not None:
        try:
            treat = f.q(nl.url, {"where": nl.with_filter("1=1"), "outFields": nl.out_fields(), "returnGeometry": "false"})
            notices = [{k.lower(): v for k, v in nl.attrs(x).items() if k in R.CANON["vector_notices"] and k != "OBJECTID"}
                       for x in treat.get("features") or []]
        except PlacesError:
            notices = []
    notices = [n for n in notices if n.get("treatment") or n.get("reason")]
    q0 = quarter0()
    return {"zones": zones, "quarter0": list(q0), "quarters": max((len(v) for z in zones
                                                                   for v in z["species"].values()), default=0),
            "notices": notices, "read": today.isoformat()}


def quarter_label(i):
    q0 = quarter0()
    y, q = q0[0] + (q0[1] - 1 + i) // 4, (q0[1] - 1 + i) % 4 + 1
    return f"Q{q} {y}"


def fetch_coast(f, s, today):
    """The coastline near the region: the Census coastline (kind "tiger_coastline"), or a file layer of lines."""
    lay = _layer(s, "coast")
    if lay.kind == "file":
        try:
            got = json.loads(lay.path().read_text(encoding="utf-8"))
        except (OSError, ValueError):
            raise PlacesError("the coastline file is missing or isn't JSON") from None
        lines = []
        for ft in got.get("features") or []:
            g = ft.get("geometry") or {}
            c = g.get("coordinates") or []
            lines.extend([c] if g.get("type") == "LineString" else c if g.get("type") == "MultiLineString" else [])
        pts = [p for ln in lines for p in densify(ln)]
        if not pts:
            raise PlacesError("the coastline file has no lines")
        return {"year": today.year, "points": [[round(x, 5), round(y_, 5)] for x, y_ in pts]}
    tried = []
    for y in range(today.year, today.year - 4, -1):
        url = f"{s['coast_base']}/TIGER{y}/COASTLINE/tl_{y}_us_coastline.zip"
        try:
            data, _ = f.get(url, limit=80 * 1024 * 1024)
        except Z.FactsError as e:
            tried.append(f"{y}: {e}")
            continue
        try:
            with zipfile.ZipFile(io.BytesIO(data)) as zf:
                name = next(n for n in zf.namelist() if n.lower().endswith(".shp"))
                lines = read_shp_lines(zf.read(name))
        except (zipfile.BadZipFile, StopIteration, KeyError, struct.error, ValueError) as e:
            tried.append(f"{y}: {type(e).__name__}")
            continue
        pts = [p for ln in lines for p in densify(ln)]
        if len(pts) < 50:
            tried.append(f"{y}: no coastline near the region")
            continue
        return {"year": y, "points": [[round(x, 5), round(y_, 5)] for x, y_ in pts]}
    raise PlacesError("no Census coastline found (" + "; ".join(tried[-2:]) + ")")


# ---------------------------------------------------------------- the store
class Store:
    def __init__(self, cfg, pack_dir=None):
        self.cfg = cfg
        self.folder = folder_of(cfg)
        self.pack_dir = pack_dir or cfg.get("pack_dir")
        self._cache = {}

    def path(self, name):
        return self.folder / name

    def _json(self, name):
        p = self.path(name)
        try:
            m = p.stat().st_mtime_ns
        except OSError:
            return {}
        hit = self._cache.get(name)
        if hit and hit[0] == m:
            return hit[1]
        try:
            val = json.loads(p.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            return hit[1] if hit else {}
        self._cache[name] = (m, val)
        return val

    def _write_json(self, name, val):
        self.folder.mkdir(parents=True, exist_ok=True)
        p = self.path(name)
        t = p.with_name(p.name + ".tmp")
        t.write_text(json.dumps(val, separators=(",", ":"), sort_keys=True), encoding="utf-8")
        t.replace(p)
        self._cache.pop(name, None)

    def meta(self):
        return self._json("meta.json") or {}

    def set_meta(self, source, **kw):
        m = dict(self.meta())
        m[source] = dict(m.get(source) or {}, **kw)
        self._write_json("meta.json", m)

    def status(self):
        return self._json("status.json") or {}

    def write_status(self, st):
        self._write_json("status.json", st)

    def fires(self):
        return (self._json("fires.json") or {}).get("fires") or []

    def tax(self):
        areas = (self._json("tax.json") or {}).get("areas") or []
        for a in areas:                      # a file written before the area's code was called "code"
            if isinstance(a, dict) and "code" not in a and "tac" in a:
                a["code"] = a.pop("tac")
        return areas

    def vector(self):
        return self._json("vector.json") or {}

    def coast(self):
        pts = (self._json("coast.json") or {}).get("points") or []
        return np.asarray(pts, dtype=float) if pts else None

    def zip_facts(self):
        return (self._json("zip_facts.json") or {}).get("zips") or {}

    def sources(self):
        """The sources behind what's on file, for a chart's footnote."""
        m, out = self.meta(), []
        reg = R.current()

        def src(layer, fallback):
            lay = reg.layer(layer)
            return (lay.source if lay is not None and lay.source else fallback)
        if (m.get("fires") or {}).get("built"):
            fm = m["fires"]
            name = src("fires", f"{reg.name} fire history".strip())
            out.append(f"{name} (to {fm['newest_region']})" if fm.get("newest_region") else name)
            if fm.get("recent"):
                out.append(f"{src('recent_fires', 'national wildfire perimeters')} ({fm['newest_region'] + 1} on)")
        if (m.get("tax") or {}).get("built"):
            out.append(src("tax", "sales tax rates"))
        if (m.get("vector") or {}).get("built"):
            out.append(src("vector_zones", "vector surveillance"))
        if (m.get("coast") or {}).get("built"):
            out.append(src("coast", "Census coastline"))
        if (m.get("parcels") or {}).get("built"):
            out.append(src("parcels", "public parcels"))
        return out

    def stamp(self):
        """Changes when a source the customers' place details come from is read again (the import's fingerprint),
        not when a nightly check finds it unchanged (that would re-run the import every night for nothing)."""
        # from what the customers' details come from ("cust": tax and vector outlines, the points' and parcels'
        # content), so a new vector detection or a 90-day re-read of the same points doesn't re-run the import,
        # and a moved tax boundary does
        m = self.meta()
        built = [(name, (m.get(name) or {}).get("cust") or (m.get(name) or {}).get("built")) for name in ORDER
                 if name != "points"]           # the canvassing app's copy; the customers never read it
        built.append(("match", MATCH_METHOD))      # new matching rules re-place the customers once
        if not any(b for _n, b in built) or not self.path("addresses.sqlite").exists():
            return ""
        return _hash(built)

    # the two databases
    def db(self, name):
        p = self.path(name)
        if not p.exists():
            return None
        # as a URI, so a "#", "?" or "%" in the folder's name can't cut the path short
        con = sqlite3.connect(p.resolve().as_uri() + "?mode=ro", uri=True, check_same_thread=False)
        return con

    def db_ok(self, name):
        """True when the database on file opens and is whole (a crash right after a swap could leave a
        broken file that the "unchanged" check would have kept for 90 days)."""
        try:
            con = self.db(name)
            if con is None:
                return False
            try:
                ok = con.execute("PRAGMA quick_check").fetchone()[0] == "ok"
                table = "pts" if name.startswith("addresses") else ("cpts" if name.startswith("points") else "parcels")
                return ok and con.execute(f"SELECT 1 FROM {table} LIMIT 1").fetchone() is not None
            finally:
                con.close()
        except sqlite3.Error:
            return False

    def new_db(self, name, schema):
        """A fresh database at <name>.<pid>.tmp (swapped in by commit_db)."""
        self.folder.mkdir(parents=True, exist_ok=True)
        for old in self.folder.glob(f"{name}.*.tmp"):        # a run killed part-way (one run at a time)
            old.unlink(missing_ok=True)
        tmp = self.path(f"{name}.{os.getpid()}.tmp")
        con = sqlite3.connect(str(tmp))
        con.executescript(schema)
        return con, tmp

    def commit_db(self, name, con, tmp):
        """Swap the new database in: on disk first (written without a journal, so a power cut after the
        rename could leave half a file), checked whole, then renamed, and the folder flushed."""
        con.commit()
        ok = con.execute("PRAGMA quick_check").fetchone()[0] == "ok"
        con.close()
        if not ok:
            raise PlacesError(f"the new {name} didn't check out; kept the old one")
        fd = os.open(str(tmp), os.O_RDONLY)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)
        tmp.replace(self.path(name))
        try:
            dfd = os.open(str(self.folder), os.O_RDONLY)
            try:
                os.fsync(dfd)
            finally:
                os.close(dfd)
        except OSError:
            pass


ADDR_SCHEMA = """
PRAGMA journal_mode=OFF; PRAGMA synchronous=OFF;
CREATE TABLE pts (oid INTEGER, num INTEGER, street TEXT, nodir TEXT, zip TEXT, unit TEXT, lon REAL, lat REAL,
                  apn TEXT);
"""
ADDR_INDEX = "CREATE INDEX ix_pts ON pts (zip, num); CREATE INDEX ix_apn ON pts (apn);"
# a number and street looked up anywhere in the region (a customer's ZIP missing or wrong)
ADDR_INDEX_NUM = ("CREATE INDEX IF NOT EXISTS ix_num_street ON pts (num, street); "
                  "CREATE INDEX IF NOT EXISTS ix_num_nodir ON pts (num, nodir);")
POINTS_SCHEMA = """
PRAGMA journal_mode=OFF; PRAGMA synchronous=OFF;
CREATE TABLE cpts (oid INTEGER PRIMARY KEY, num REAL, frac TEXT, pdir TEXT, name TEXT, postd TEXT, sfx TEXT, unit TEXT,
                   jur TEXT, zip TEXT, seg INTEGER, apn TEXT, community TEXT, atype TEXT, lon REAL, lat REAL);
"""
POINTS_INDEX = ("CREATE INDEX ix_c_name ON cpts (name, zip); CREATE INDEX ix_c_zip ON cpts (zip, num); "
                "CREATE INDEX ix_c_num ON cpts (num); CREATE INDEX ix_c_lat ON cpts (lat);")
PARCEL_SCHEMA = """
PRAGMA journal_mode=OFF; PRAGMA synchronous=OFF;
CREATE TABLE parcels (apn TEXT PRIMARY KEY, acres REAL, cls TEXT, juris TEXT);
"""


# ---------------------------------------------------------------- what a place is like
class Lookup:
    """The sources on file, ready for points: tax area, vector zone, fire history, miles to the coast."""

    def __init__(self, store):
        self.store = store
        self.g = g = grid()
        self.tax = store.tax()
        self.tax_grid = label_grid([a["rings"] for a in self.tax], g) if self.tax else None
        mz = store.vector()
        self.zones = mz.get("zones") or []
        self.zone_grid = label_grid([z["rings"] for z in self.zones], g) if self.zones else None
        self.quarters = int(mz.get("quarters") or 0)
        self.fires = store.fires()
        self.fire_n = np.zeros((g.h, g.w), dtype=np.int16)
        self.fire_last = np.zeros((g.h, g.w), dtype=np.int16)
        for fire in self.fires:
            _f, r, c0, c1 = Z.spans([fire["rings"]], g)
            y = int(fire["year"])
            for rr, a, b in zip(r.tolist(), c0.tolist(), c1.tolist()):
                self.fire_n[rr, a:b] += 1
                np.maximum(self.fire_last[rr, a:b], y, out=self.fire_last[rr, a:b])
        self.coast = store.coast()

    def invasive_quarters(self, zone_idx, last=8):
        """Of the last `last` quarters, how many found an invasive species in the zone (None: unknown)."""
        if zone_idx is None or zone_idx < 0 or zone_idx >= len(self.zones):
            return None
        sp = self.zones[zone_idx]["species"]
        n = self.quarters
        if not n:
            return None
        hit = 0
        for q in range(max(0, n - last), n):
            if any((sp.get(s) or [0] * n)[q] if q < len(sp.get(s) or []) else 0 for s in invasive_species()):
                hit += 1
        return hit

    def species_found(self, zone_idx, last=8):
        if zone_idx is None or zone_idx < 0 or zone_idx >= len(self.zones):
            return []
        sp, n = self.zones[zone_idx]["species"], self.quarters
        return [s for s in invasive_species() if any((sp.get(s) or [])[max(0, n - last):n])]

    def at(self, lon, lat, exact=False):
        """Arrays for points: tax area index, vector zone index, fires (count, last year), miles to the coast.
        exact: tax areas by the outline itself (customers), not the 100 m grid (ZIP facts)."""
        lon, lat = np.asarray(lon, dtype=float), np.asarray(lat, dtype=float)
        r, c, ok = cells(lon, lat, self.g)
        out = {"ok": ok}
        if self.tax_grid is not None:
            t = np.where(ok, self.tax_grid[r, c], -1)
            if exact:
                t = np.full(len(lon), -1)
                for k, a in enumerate(self.tax):
                    x0, y0, x1, y1 = a["bbox"]
                    near = (lon >= x0) & (lon <= x1) & (lat >= y0) & (lat <= y1)
                    if near.any():
                        idx = np.nonzero(near)[0]
                        t[idx[inside(lon[idx], lat[idx], a["rings"])]] = k
            out["tax"] = t
        if self.zone_grid is not None:
            out["zone"] = np.where(ok, self.zone_grid[r, c], -1)
        out["fires"] = np.where(ok, self.fire_n[r, c], 0)
        out["last_fire"] = np.where(ok, self.fire_last[r, c], 0)
        out["coast"] = miles_to(lon, lat, self.coast) if self.coast is not None else np.full(len(lon), np.nan)
        return out


def _median(v):
    v = [x for x in v if x is not None and not (isinstance(x, float) and math.isnan(x))]
    return float(np.median(v)) if v else None


def _mode(v):
    v = [x for x in v if x is not None and x >= 0]
    return max(set(v), key=v.count) if v else None


def derive(store, zip_areas, today=None):
    """{zip: {fact: value}} for the service area's ZIPs. Homes are the region's address points in the ZIP (a sample
    of up to ZIP_SAMPLE); with none on file yet, the ZIP's own area stands in (cells of its outline)."""
    lk = Lookup(store)
    g = lk.g
    zs = sorted(zip_areas)
    con = store.db("addresses.sqlite")
    pts = {}
    if con is not None:
        try:
            for z in zs:
                rows = con.execute("SELECT lon, lat, apn FROM pts WHERE zip = ? ORDER BY oid", (z,)).fetchall()
                if len(rows) > ZIP_SAMPLE:
                    step = len(rows) / ZIP_SAMPLE
                    rows = [rows[int(i * step)] for i in range(ZIP_SAMPLE)]
                if rows:
                    pts[z] = rows
        finally:
            con.close()
    # the ZIPs' outlines on the grid: which fires reached each ZIP, and cell centres where no homes are on file
    feats = [[ring for poly in zip_areas[z] for ring in poly] for z in zs]
    zf, zr, zc0, zc1 = Z.spans(feats, g)
    zip_cells = {}
    for k, rr, a, b in zip(zf.tolist(), zr.tolist(), zc0.tolist(), zc1.tolist()):
        zip_cells.setdefault(k, []).append((rr, a, b))
    parcels = store.db("parcels.sqlite")
    out = {}
    try:
        for k, z in enumerate(zs):
            spans_ = zip_cells.get(k) or []
            fires_here, last = 0, 0
            if spans_ and lk.fires:
                ids = _fires_in_cells(lk, spans_)                  # fires whose outline reached the ZIP
                fires_here = len(ids)
                last = max((lk.fires[i]["year"] for i in ids), default=0)
            rows = pts.get(z)
            basis = "homes"
            if not rows:
                basis = "area"
                cs = [(rr, cc) for rr, a, b in spans_ for cc in range(a, b)]
                if len(cs) > ZIP_SAMPLE:
                    step = len(cs) / ZIP_SAMPLE
                    cs = [cs[int(i * step)] for i in range(ZIP_SAMPLE)]
                rows = [(g.lon0 + (cc + 0.5) * g.d, g.lat0 + (rr + 0.5) * g.d, None) for rr, cc in cs]
            if not rows:
                # outside the region's grid (the service area reaches past the region): the outline's own
                # corners still say how far the coast is; the region's layers have nothing there
                verts = [p for poly in zip_areas[z] for ring in poly for p in ring]
                if not verts or lk.coast is None:
                    continue
                step = max(1, len(verts) // 200)
                v = np.asarray(verts[::step], dtype=float)
                out[z] = {"places_basis": "outline", "coast_mi": round(float(np.median(miles_to(v[:, 0], v[:, 1],
                                                                                               lk.coast))), 1)}
                continue
            lon = np.array([x[0] for x in rows])
            lat = np.array([x[1] for x in rows])
            at = lk.at(lon, lat)
            f = {"places_basis": basis}
            if lk.coast is not None:
                f["coast_mi"] = round(_median(at["coast"].tolist()), 1)
            # the region's layers cover the region only: a ZIP mostly outside it has no fire history here,
            # which is unknown, not "no fires" (the tax areas together are the region)
            in_county = bool("tax" not in at or float((at["tax"] >= 0).mean()) >= 0.5)
            if lk.fires and in_county:
                f["burned_pct"] = round(100.0 * float((at["fires"] > 0).mean()), 1) if basis == "homes" else None
                f["fire_count"] = fires_here
                f["last_fire_year"] = last or None
            if "tax" in at:
                t = _mode(at["tax"].tolist())
                f["tax_rate"] = lk.tax[t]["rate"] if t is not None else None
                f["tax_area"] = lk.tax[t]["name"] if t is not None else None
            if "zone" in at:
                zi = _mode(at["zone"].tolist())
                f["vector_q8"] = lk.invasive_quarters(zi)
                f["vector_zone"] = lk.zones[zi]["name"] if zi is not None else None
                f["vector_species"] = lk.species_found(zi)
            out[z] = f
    finally:
        if parcels is not None:
            parcels.close()
    return out


def _fires_in_cells(lk, spans_):
    """Indexes of the fires whose outline covers any of these grid spans (rows of cells)."""
    if not lk.fires:
        return set()
    rows = sorted({rr for rr, _a, _b in spans_})
    r0, r1 = rows[0], rows[-1]
    c0 = min(a for _r, a, _b in spans_)
    c1 = max(b for _r, _a, b in spans_)
    lat0, lat1 = lk.g.lat0 + r0 * lk.g.d, lk.g.lat0 + (r1 + 1) * lk.g.d
    lon0, lon1 = lk.g.lon0 + c0 * lk.g.d, lk.g.lon0 + c1 * lk.g.d
    want = np.zeros((r1 - r0 + 1, c1 - c0), dtype=bool)
    for rr, a, b in spans_:
        want[rr - r0, a - c0:b - c0] = True
    hit = set()
    for i, fire in enumerate(lk.fires):
        if "bbox" not in fire:
            fire["bbox"] = bbox_of(fire["rings"])
        x0, y0, x1, y1 = fire["bbox"]
        if x1 < lon0 or x0 > lon1 or y1 < lat0 or y0 > lat1:
            continue
        _f, r, a_, b_ = Z.spans([fire["rings"]], lk.g)
        for rr, a, b in zip(r.tolist(), a_.tolist(), b_.tolist()):
            if r0 <= rr <= r1:
                lo, hi = max(a, c0), min(b, c1)
                if hi > lo and want[rr - r0, lo - c0:hi - c0].any():
                    hit.add(i)
                    break
    return hit


# ---------------------------------------------------------------- customers
class PlaceIndex:
    """Places a street address and ZIP on the layer's address points and says what the place
    is like. Nothing is sent anywhere; only the description is kept with a customer."""

    def __init__(self, store):
        self.store = store
        self.con = store.db("addresses.sqlite")
        self.parcels = store.db("parcels.sqlite")
        self.lookup = Lookup(store)
        self._cand = {}
        self._in_zip = {}
        self._street_nums = {}
        self.how = {"exact": 0, "nearby number": 0, "near spelling": 0, "other ZIP": 0, "whole street": 0,
                    "known place": 0, "second look": 0}
        self._zip_parts = {}
        self.refine = REFINE                    # False leaves out the twin-street refinement
        self.known = _known_places(R.current())
        # the region-wide look needs the number index (added by the places update, under its lock); without it
        # each look would read the whole file, so it is skipped until then
        self.has_num_index = bool(self.con is not None and self.con.execute(
            "SELECT 1 FROM sqlite_master WHERE name = 'ix_num_nodir'").fetchone())

    def __bool__(self):
        return self.con is not None

    def close(self):
        for c in (self.con, self.parcels):
            if c is not None:
                c.close()

    def _rows(self, z, num):
        key = (z, num)
        if key not in self._cand:
            self._cand[key] = self.con.execute(
                "SELECT street, nodir, lon, lat, apn FROM pts WHERE zip = ? AND num = ?", key).fetchall()
        return self._cand[key]

    def locate(self, line, zip5, second=True):
        """_locate, then a known place the address names (the region's known_places.json), then the
        second look. second=False leaves the second look out: the import tries a customer's other streets
        before it, so nothing an earlier rule placed can move."""
        if self.con is None:
            return None
        z = str(zip5 or "")[:5]
        if SA.street_key(line)[0] is None:                  # no house number: a named place first
            return self._known(line, z) or self._locate(line, zip5)
        hit = self._locate(line, zip5) or self._known(line, z)
        return hit or (self.second_look(line, z) if second else None)

    def second_look(self, line, zip5):
        """The second look alone, for an address nothing else placed."""
        if self.con is None or SA.street_key(line)[0] is None:
            return None
        return self._second_look(line, str(zip5 or "")[:5])

    def _locate(self, line, zip5):
        """(lon, lat, apn) for a street address and ZIP, or None. self.how counts which rule placed it.

        1. exact: the street as written (then without a direction when only one place fits, then without trailing
           words) at the house number; also the other number of a range ("1200-02 3rd Ave" -> 1202) and the
           layer's own names for a highway ("Highway 12" -> "state route 12" / "hwy 12");
        2. nearby number: the customer's street IS in their ZIP but the layer has no point at their
           number (new homes, ADUs, a lot renumbered): the nearest number on that same street, same side of the
           street within NEARBY_SAME_SIDE, either side within NEARBY_ANY; the tax area, coast, vector zone are
           the block's (and "1200 2nd Ave" is never chosen for "1200-02 3rd Ave": the street must be theirs);
        3. near spelling: their street isn't in their ZIP: the one street of the ZIP their spelling can
           only mean, at their number;
        4. other ZIP: one of the region's ZIPs or none: the same number and street anywhere in the region,
           when it is one place."""
        num, st = SA.street_key(line)
        z = str(zip5 or "")[:5]
        if num is None:
            return self._whole_street(line, z)
        if not st:
            return None
        nums = [num] + [n for n in _range_numbers(line) if n != num]
        streets = _street_forms(st)
        for n in nums:
            rows = self._rows(z, n) if len(z) == 5 else []
            for form in streets:
                hit = _exact(rows, form.split()) if rows else None
                if hit:
                    self.how["exact"] += 1
                    return _best(hit)
        if len(z) == 5:
            mine = []
            for form in streets:
                mine += [m for m in self._zip_street_names(z, form.split()) if m not in mine]
            twin = self._twin(z, nums, streets) if self.refine else (None, False)
            if twin[0]:                                     # their number, exactly, on the street of
                self.how["near spelling"] += 1              # their name ("410 Larkspur": larkspur dr, not 402
                return _best(twin[0])                       # on the layer's one-point "larkspur")
            if mine:
                for n in nums:
                    hit = self._nearby(z, n, mine)
                    if hit:
                        self.how["nearby number"] += 1
                        return hit
                return None
        rows = self._rows(z, num) if len(z) == 5 else []
        if len(z) == 5 and twin[1] and REFUSE_TWINS:      # two streets of their name have their
            return None                                     # number ("525 Hawthorn Ave": dr and knl): no guess
        if len(z) == 5:
            fit = _spelled(st, self._zip_streets(z), {r[0] for r in rows})
            hit = _pick(fit, rows) if rows else None
            if not hit and len(fit) == 1:                   # "640 Juniper" -> the nearest number on juniper dr
                hit = [self._nearby(z, n, fit) for n in nums]
                hit = next((h for h in hit if h), None)
                if hit:
                    self.how["near spelling"] += 1
                    return hit
            if hit:
                self.how["near spelling"] += 1
                return _best(hit)
        if (len(z) == 5 and not _region_zip(z)) or (len(z) not in (0, 5)) or not self.has_num_index:
            return None
        for n in nums:
            for form in streets:
                hit = self._elsewhere(n, form)
                if hit:
                    self.how["other ZIP"] += 1
                    return _best(hit)
        if not z:                                           # no ZIP at all, the spelling a little off
            for n in nums:
                hit = self._spelled_elsewhere(n, st)
                if hit:
                    self.how["other ZIP"] += 1
                    return _best(hit)
        return None

    def _twin(self, z, nums, forms):
        """(rows, ambiguous) for the street of the customer's name at their exact number.
        rows: the rows on the one street of their ZIP that differs from what they wrote only by what they left out
        (a suffix or a direction) or by the layer's padding ("larkspur" -> larkspur dr, not a nearby number on the
        layer's one-point "larkspur"; "3rd ave" -> 03rd ave). ambiguous: two or more streets of their name have
        the number, each at most one written suffix or direction away, and nothing picks one ("525 Hawthorn
        Ave": hawthorn dr and hawthorn knl), or another street at the number has all their distinctive words
        ("120 Pine": pine st and pine hills rd)."""
        keys = []
        for f in forms:
            k = _twin_parts(f)
            if len("".join(k[1])) >= 3 and k not in keys:                # "3rd" yes; "e st" (no name) no
                keys.append(k)
        if not keys:
            return None, False
        for num in nums:
            rows = self._rows(z, num)
            if not rows:
                continue
            best = {}
            for r in rows:
                diffs = [_twin_diff(k, r[0]) for k in keys]
                diffs = [x for x in diffs if x is not None]
                if diffs:
                    best[r] = min(diffs)
            if not best:
                continue
            for r in rows:            # another street at the number with their distinctive words ("120 Pine":
                if r in best:         # pine hills rd; "707 Old Mill": mill park trl): the twin decides nothing
                    continue          # and the earlier rules go on as before
                cd, cn, _cs = _twin_parts(r[0])
                if any(_same_or_missing(kd, cd) and set(w for w in kn if w not in _generic()) <= set(cn)
                       and any(w not in _generic() for w in kn) for kd, kn, _ks in keys):
                    return None, False
            zero = [r for r in best if best[r] == 0]
            near = [r for r in best if best[r] <= 1]
            one = lambda rr: len({str(_twin_parts(r[0])) for r in rr}) == 1   # "e cedar dr" = "cedar dr e"
            if zero and (one(zero) or _one_spot(zero)):
                return zero, False
            if len(near) and not one(near) and not _one_spot(near):
                return None, True
            return None, False
        return None, False

    def _second_look(self, line, z):
        """the ways customers write a street that the layer writes otherwise, tried only after every rule
        above (and the known places) found nothing, all at the customer's own house number:
          1. other forms of their street, exactly (_more_forms): the layer's "06th st" for "6th St" and "Sixth
             St", its "rdg", "trls", "pky", "pte" for Ridge, Trails, Parkway, Pointe, "centre" for Center, "olde
             hwy 4", "rock mountain rd" for Rock Mt. Rd., "maple dr" for "Maple Ddrive", "hwy 9" for
             "Hyw 9"; in their ZIP, else (no ZIP, or not found in one of the region's ZIPs) anywhere in the region
             when it is one place;
          2. the same letters spaced otherwise: "Seaview Ave" -> sea view ave, "LindenAve." -> linden ave,
             "Parkwest Ave" -> park w ave;
          3. a suffix left out, when the streets of that name differ only in their suffix and one has the number
             ("1500 Old Mill" -> old mill dr, not old mill spur or old mill trl; "2400 Birchwood" -> birchwood rd),
             and no other street at that number shares their words;
          4. the wrong suffix, when the ZIP has one street of that name and it has the number;
          5. the name two letters off ("School Days Ln" -> school daze ln, "Larkspur Hollow Rd" -> larkspur
             holow rd), eight letters or more, the suffix and direction the same or left out, when it is the one
             street of the ZIP that close.
        Rules 4 and 5 are not tried when the street they wrote is a street of their ZIP (then it is theirs, and
        the layer has no point at their number)."""
        num, st = SA.street_key(line)
        if num is None or not st:
            return None
        if (len(z) == 5 and not _region_zip(z)) or len(z) not in (0, 5):
            return None
        nums = [num] + [n for n in _range_numbers(line) if n != num]
        seeds = _street_forms(st)
        more = [f for f in _more_forms(st) if f not in seeds]
        allf = seeds + more
        loose = [f for f in _more_forms(st, suffixes=False)]          # rules 3-5: the last word as they wrote it
        hit = None
        mine = []                                     # their street (in some form) is a street of their ZIP
        if len(z) == 5:
            streets = self._zip_streets(z)
            for form in allf:
                mine += [m for m in self._zip_street_names(z, form.split()) if m not in mine]
            for n in nums:
                rows = self._rows(z, n)
                if not rows:
                    continue
                for form in more:                                       # 1
                    hit = _exact(rows, form.split())
                    if hit:
                        break
                hit = hit or _spaced(allf, rows)                        # 2
                hit = hit or _pick(_left_out(loose, streets, rows), rows)   # 3
                own = any(f in streets for f in allf)
                if not hit and not own:
                    hit = _pick(_wrong_suffix(loose, streets), rows)    # 4
                if not hit and not own:
                    hit = _pick(_two_off(loose, self._parts_of(z)), rows)   # 5
                if hit:
                    break
        if not hit and mine:
            # their street is in their ZIP, so it is theirs: only a nearby number on it ("1400 5th St"
            # in one town is never another town's 1400 05th st), as the first look does (nearby number)
            names = []
            for form in more:
                names += [m for m in self._zip_street_names(z, form.split()) if m not in names]
            for n in nums:
                near = self._nearby(z, n, names)
                if near:
                    self.how["second look"] += 1
                    return near
            return None
        if not hit and self.has_num_index:
            for n in nums:
                for form in more:
                    hit = self._elsewhere(n, form, ONE_COMPLEX)
                    if hit:
                        break
                if hit:
                    break
        if not hit:
            return None
        self.how["second look"] += 1
        return _best(hit)

    def _parts_of(self, z):
        """[(street, directions, joined name, suffix)] of a ZIP's streets, for the two-letters look (once per ZIP)."""
        if z not in self._zip_parts:
            out = []
            for c in self._zip_streets(z):
                d, n, s = _parts2(c)
                g, j = _split_generic(n)
                if j.isalpha():
                    out.append((c, d, j, s, g))
            self._zip_parts[z] = out
        return self._zip_parts[z]

    def _whole_street(self, line, z):
        """an address with no house number ("Willow Run", ZIP 00011) on a street of the ZIP that is one
        place: at least WHOLE_STREET_MIN address points, all within ONE_PLACE (a cul-de-sac, a short lane). Its
        middle point, with no parcel (which house is unknown)."""
        if len(z) != 5:
            return None
        text = _UNIT.sub(" ", str(line or ""))
        text = re.sub(r"\b\d[\w-]*\b", " ", text)
        _n, st = SA.street_key("1 " + text.strip())
        if not st:
            return None
        streets = self._zip_streets(z)
        names = [st] if st in streets else []
        if not names:
            same = self._in_zip[z][2].get(_nodir(st))
            names = list(same or [])
        if not names and len(st.split()) >= 2 and _parts(st)[1]:       # "Willow Run" -> willow run dr; never "Morgan"
            names = [c for c in streets if c.startswith(st + " ") and _parts(c)[1] == _parts(st)[1]]
        if len(names) != 1:                             # names only, no spelling guesses: a person's name in the
            return None                                 # address line must not land on a street
        pts = self._nums_of(z).get(names[0], ())
        if len(pts) < WHOLE_STREET_MIN:
            return None
        lons, lats = sorted(p[1] for p in pts), sorted(p[2] for p in pts)
        if lons[-1] - lons[0] > ONE_PLACE or lats[-1] - lats[0] > ONE_PLACE:
            return None
        self.how["whole street"] += 1
        return lons[len(lons) // 2], lats[len(lats) // 2], None

    def _spelled_elsewhere(self, num, st):
        """For a customer with no ZIP: the region's streets anywhere with this house number, and among them
        the one the spelling can only mean ("1600 Quiet Valley" -> quiet valley ln; "680 Kingfisher Dr" ->
        kingfishers dr), when its rows are one place."""
        try:
            rows = self.con.execute("SELECT street, nodir, lon, lat, apn FROM pts WHERE num = ?", (num,)).fetchall()
        except sqlite3.Error:
            return None
        hit = _pick(_spelled(st, {r[0] for r in rows}, {r[0] for r in rows}), rows)
        if not hit:
            return None
        lons, lats = [r[2] for r in hit], [r[3] for r in hit]
        if max(lons) - min(lons) > ONE_PLACE or max(lats) - min(lats) > ONE_PLACE:
            return None
        return hit

    def _known(self, line, z):
        """(lon, lat, None) of a known place (pack known_places.yml) the address names, when nothing else placed
        it: "Example Park #12", ZIP 00011 or no ZIP."""
        low = " " + re.sub(r"[^a-z0-9]+", " ", str(line or "").casefold()) + " "
        for k in self.known:
            if ((z in k["zips"]) or (not z and k["no_zip"])) and any(f" {w} " in low for w in k["words"]):
                self.how["known place"] += 1
                return k["lon"], k["lat"], k.get("apn")
        return None

    def _zip_street_names(self, z, words):
        """The layer's street names of the ZIP the customer's street is (as written, or less up to three trailing
        words; with or without its directions, when that leaves one street)."""
        streets = self._zip_streets(z)
        by_nodir = self._in_zip[z][2]
        for cut in range(0, min(3, len(words) - 1) + 1):
            k = " ".join(words[:len(words) - cut])
            if k in streets:
                return [k]
            same = by_nodir.get(_nodir(k))
            if same:
                return list(same)                # several (N/S Main St): _nearby needs exactly one to choose
        return []

    def _nearby(self, z, num, names):
        """(lon, lat, apn) at the nearest house number on the same side of the customer's own street (within
        NEARBY_SAME_SIDE), else on either side (within NEARBY_ANY), or None."""
        if len(names) != 1:
            return None
        best = None
        for n, lon, lat, apn in self._nums_of(z).get(names[0], ()):
            d = abs(n - num)
            ok = d <= NEARBY_ANY or (d <= NEARBY_SAME_SIDE and n % 2 == num % 2)
            rank = (n % 2 != num % 2, d, n)              # the same side first, then the nearest, then the lower
            if ok and (best is None or rank < best[0]):
                best = (rank, (lon, lat, apn))
        return best[1] if best else None

    def _nums_of(self, z):
        """{street: [(num, lon, lat, apn)]} of a ZIP."""
        if z not in self._street_nums:
            by = {}
            for street, n, lon, lat, apn in self.con.execute(
                    "SELECT street, num, lon, lat, apn FROM pts WHERE zip = ?", (z,)):
                by.setdefault(street, []).append((n, lon, lat, apn))
            self._street_nums[z] = by
        return self._street_nums[z]

    def _zip_streets(self, z):
        """{street} and {street without its direction} of a ZIP (read once per ZIP)."""
        if z not in self._in_zip:
            got = self.con.execute("SELECT DISTINCT street, nodir FROM pts WHERE zip = ?", (z,)).fetchall()
            by_nodir = {}
            for r in got:
                by_nodir.setdefault(_nodir(r[0]), set()).add(r[0])
            self._in_zip[z] = ({r[0] for r in got}, {r[1] for r in got}, by_nodir)
        return self._in_zip[z][0]

    def _street_in_zip(self, z, words):
        """True when the customer's street (as written, or less its trailing words, with or without a direction)
        is a street of their ZIP."""
        self._zip_streets(z)
        streets, nodir = self._in_zip[z][:2]
        for cut in range(0, min(3, len(words) - 1) + 1):
            k = " ".join(words[:len(words) - cut])
            if k in streets or SA._undirected(k) in nodir:
                return True
        return False

    def _elsewhere(self, num, st, near=None):
        """The rows with this number and street anywhere in the region, when they are one place (a building's
        units share it; within `near`); None when the street is in two places or nowhere."""
        try:
            rows = self.con.execute("SELECT street, nodir, lon, lat, apn FROM pts WHERE num = ? AND street = ?",
                                    (num, st)).fetchall()
            if not rows:
                rows = self.con.execute("SELECT street, nodir, lon, lat, apn FROM pts WHERE num = ? AND nodir = ?",
                                        (num, SA._undirected(st))).fetchall()
        except sqlite3.Error:
            return None
        if not rows:
            return None
        near = near or ONE_PLACE
        lons, lats = [r[2] for r in rows], [r[3] for r in rows]
        if max(lons) - min(lons) > near or max(lats) - min(lats) > near:
            return None
        return rows

    def describe(self, located):
        """[{tax_area, tax_rate, coast_mi, mosquito_zone, fires_here, last_fire_year, build_class}] for
        a list of locate() results (None stays None)."""
        idx = [i for i, x in enumerate(located) if x is not None]
        out = [None] * len(located)
        if not idx:
            return out
        lon = np.array([located[i][0] for i in idx])
        lat = np.array([located[i][1] for i in idx])
        at = self.lookup.at(lon, lat, exact=True)
        lk = self.lookup
        for j, i in enumerate(idx):
            t = int(at["tax"][j]) if "tax" in at else -1
            zi = int(at["zone"][j]) if "zone" in at else -1
            coast = float(at["coast"][j])
            d = {"tax_area": lk.tax[t]["name"] if t >= 0 else None, "tax_rate": lk.tax[t]["rate"] if t >= 0 else None,
                 "coast_mi": round(coast, 1) if not math.isnan(coast) else None,
                 "vector_zone": lk.zones[zi]["name"] if zi >= 0 else None,
                 "fires_here": int(at["fires"][j]), "last_fire_year": int(at["last_fire"][j]) or None,
                 "build_class": None}
            apn = located[i][2]
            if apn and self.parcels is not None:
                row = self.parcels.execute("SELECT cls FROM parcels WHERE apn = ?", (apn,)).fetchone()
                if row:
                    d["build_class"] = build_classes().get((row[0] or " ")[:1].upper())
            out[i] = d
        return out


ONE_PLACE = 0.003            # degrees (about 300 m): rows closer than this are one place (a building's units)
ONE_COMPLEX = 0.006          # the second look only: one number on one street this close is one complex
                             # (one large apartment complex's units span about 330 m; two towns are miles apart)
_UNIT = re.compile(r"(?:#|\b(?:unit|apt|spc|space|ste|suite|no)\b\.?)\s*[\w-]+", re.I)    # "#20", "Unit B"
WHOLE_STREET_MIN = 3         # address points a street with no house number needs to be placed as one place
SAME_SPOT = 0.0005           # degrees (about 50 m): two street names at one house number this close are one place
# words that don't tell one street from another (suffixes, directions, and the Spanish and descriptive words that
# start whole families of streets: Camino Uno / Camino Dos, Old Mill Rd / Old Ridge Rd). Core keeps the ones common
# across the United States; a region adds its own (region pack: generic_street_words)
_GENERIC = set(SA._SUFFIX.values()) | set(SA._SUFFIX) | set(SA._DIRS) | {
    "de", "la", "el", "los", "las", "del", "the", "via", "calle", "camino", "paseo", "avenida", "plaza",
    "old", "mount", "mt", "point", "pt", "lake", "park", "hill", "hills", "valley", "view"}


def _generic():
    """Core's generic street words and the region's own (region pack: generic_street_words)."""
    extra = R.current().data.get("generic_street_words") or ()
    return _GENERIC | {str(w).casefold() for w in extra} if extra else _GENERIC


def _region_zip(z):
    """Is this ZIP one of the region's (its zip_prefixes)? A customer with a ZIP outside them is never placed in
    the region by the region-wide look. A region that names no prefixes takes every ZIP."""
    pre = tuple(str(x) for x in (R.current().data.get("zip_prefixes") or ()))
    return not pre or str(z).startswith(pre)


@functools.lru_cache(maxsize=500_000)
def _nodir(street):
    return SA._undirected(street)


@functools.lru_cache(maxsize=500_000)
def _parts(street):
    """(direction words, name words, suffix) of a normalized street ("n main st" -> (["n"], ["main"], "st"))."""
    words = street.split()
    sfx = words[-1] if len(words) > 1 and words[-1] in SA._SUFFIX.values() else ""
    body = words[:-1] if sfx else words
    dirs = [w for w in body if w in SA._DIRS.values()]
    return dirs, [w for w in body if w not in SA._DIRS.values()], sfx


def _same_or_missing(a, b):
    return not a or not b or a == b


def _edit1(a, b):
    """a and b differ by one letter added, dropped or changed."""
    if a == b or abs(len(a) - len(b)) > 1:
        return False
    if len(a) == len(b):
        return sum(x != y for x, y in zip(a, b)) == 1
    s_, t = (a, b) if len(a) < len(b) else (b, a)
    return any(t[:i] + t[i + 1:] == s_ for i in range(len(t)))


def _near_spelling(st, rows, zip_streets):
    """The rows at the customer's number on the one street of their ZIP their spelling can only mean (_spelled),
    or None."""
    return _pick(_spelled(st, zip_streets, {r[0] for r in rows}), rows)


def _spelled(st, zip_streets, with_number=()):
    """Among the streets of the customer's ZIP (only asked when the customer's own street isn't in it), the one
    street their spelling can only mean, as [street], else []:
      1. theirs is the start of it ("12 Pine Hills" -> "pine hills rd"), with a word of its own (not "100 Via");
      2. the same name with another or no suffix ("12 Oak St" when only "oak ct" has 12);
      3. the name one letter off, or only spaced differently, five
         letters or more and no digits ("12 Sycamorre Rd"; never 5th -> 4th, E -> F, Elm -> Ely), the suffix and
         direction the same or left out;
      4. every distinctive word of theirs in it ("12 Old Ridge Hwy" -> "ridge rd"; never Camino Uno ->
         Camino Dos).
    Each rule is tried on every street of the ZIP and must point to exactly one; two streets fitting is never a
    guess. The one exception: the
    streets that fit are one name, spaced alike or not, and differ only in a suffix or direction the customer
    left out ("350 region Club": country club rd, country club dr); they are all returned and _pick chooses by
    the house number."""
    streets = sorted(zip_streets)
    dirs, name, sfx = _parts(st)
    own = [w for w in st.split() if w not in _generic()]
    joined = "".join(name)

    def same_name(c):
        cd, cn, cs = _parts(c)
        return cn == name and _same_or_missing(dirs, cd) and cs != sfx

    def one_letter(c):
        cd, cn, cs = _parts(c)
        cj = "".join(cn)
        return (len(joined) >= 5 and len(cj) >= 5 and not any(ch.isdigit() for ch in joined + cj)
                and _same_or_missing(sfx, cs) and _same_or_missing(dirs, cd)
                and (cj == joined and cn != name or _edit1(joined, cj)))

    def all_words(c):
        mine = {w for w in own if len(w) >= 4 and not any(ch.isdigit() for ch in w)}
        return bool(mine) and mine <= set(c.split())

    tests = ((lambda c: bool(own) and c.startswith(st + " ")), same_name, one_letter, all_words)
    for test in tests:
        fit = [c for c in streets if test(c)]
        if len(fit) == 1:
            return fit
        if len(fit) > 1 and dirs and with_number:  # a direction they wrote picks among those with
            same = [c for c in fit if sorted(_parts(c)[0]) == sorted(dirs) and c in with_number]   # the number
            if len(same) == 1:                         # ("500 West Lake Blvd": w lake dr, not lake dr)
                return same
        if len(fit) > 1 and sfx and with_number:   # so does a suffix they wrote ("812 Mill Trail":
            same = [c for c in fit if _parts(c)[2] == sfx and c in with_number   # mill park trl has 812, old
                    and _same_or_missing(dirs, _parts(c)[0])]                 # mill trl doesn't)
            if len(same) == 1:
                return same
        if len(fit) > 1:
            return fit if _one_name(fit, dirs, sfx) else []
    return []


def _one_name(fit, dirs, sfx):
    """The streets that fit are one name (spaces aside) and differ only in what the customer left out: the
    suffix ("country club rd" / "country club dr" for "region Club") or the direction. A suffix or direction
    the customer wrote is never overruled ("5 Oak Ln" with Oak St and Oak Ct); different names (Pine St, Pine
    Hills Rd) never pass."""
    parts = [_parts(c) for c in fit]
    if len({"".join(p[1]) for p in parts}) != 1:
        return False
    if len({p[2] for p in parts}) > 1 and sfx:
        return False
    if len({tuple(p[0]) for p in parts}) > 1 and dirs:
        return False
    return True


def _pick(fit, rows):
    """The rows at the customer's number on the street _spelled found: the one street of several that has the
    number, or several at one spot (_one_spot); else None."""
    with_number = {r[0] for r in rows}
    have = [c for c in fit if c in with_number]
    if not have:
        return None
    hit = [r for r in rows if r[0] in have]
    return hit if len(have) == 1 or _one_spot(hit) else None


NEARBY_SAME_SIDE = 40       # house numbers: the nearest on the same side of the street within this …
NEARBY_ANY = 10             # … or on either side within this
_HIGHWAY = re.compile(r"^(?:[nsew] )?(?:hwy|state route|state hwy|sr|st rte|state rte|rte|route|highway)\s+(\d+)"
                      r"(?: [nsew])?$")
MATCH_METHOD = "1"           # how customers are placed: a change re-runs the import once (Store.stamp)


def _exact(rows, words):
    """The rows at this number on the customer's street: as written, then without a direction when only one
    street fits, then without up to three trailing words."""
    for cut in range(0, min(3, len(words) - 1) + 1):
        k = " ".join(words[:len(words) - cut])
        hit = [r for r in rows if r[0] == k]
        if not hit:
            nd = SA._undirected(k)
            near = [r for r in rows if r[1] == nd]
            if len({r[0] for r in near}) == 1 or _one_spot(near):
                hit = near
        if hit:
            return hit
    return None


def _one_spot(rows):
    """the rows are all at one spot (within SAME_SPOT), so the streets they are on are one place: a
    complex a layer files under "elm cir e" and "e elm cir" alike ("300 Elm Circle #9")."""
    if not rows:
        return False
    lons, lats = [r[2] for r in rows], [r[3] for r in rows]
    return max(lons) - min(lons) <= SAME_SPOT and max(lats) - min(lats) <= SAME_SPOT


def _range_numbers(line):
    """The other house number of a range: "3412-14 5th Ave" -> [3414], "12-14 Elm" -> [14]; else []."""
    m = re.match(r"^\s*(\d+)\s*-\s*(\d+)\b", str(line or ""))
    if not m:
        return []
    a, b = m.group(1), m.group(2)
    if len(b) < len(a):
        b = a[:len(a) - len(b)] + b
    n = int(b)
    return [n] if 0 < n - int(a) <= 20 else []


def _street_forms(st):
    """The customer's street, then the other ways the layer writes the same street: its highways
    ("hwy 12" -> "state route 12"), "terr" -> "ter", "mt" <-> "mount"."""
    forms = [st]
    m = _HIGHWAY.match(st)
    if m:
        forms += [f"state route {m.group(1)}", f"hwy {m.group(1)}"]
    words = st.split()
    if words and words[-1] in ("terr", "trce", "terace"):
        forms.append(" ".join(words[:-1] + ["ter"]))
    if words and words[0] in ("so", "no") and len(words) > 2:     # "So. Ashton Dr" = s ashton dr
        forms.append(" ".join([words[0][0]] + words[1:]))          # (no street starts so / no)
    if words and words[0] in ("mt", "mount", "mnt"):
        for alt in ("mt", "mount"):
            forms.append(" ".join([alt] + words[1:]))
    out = []
    for f in forms:
        if f not in out:
            out.append(f)
    return out


# ---------------------------------------------------------------- the second look
_ORDINAL_WORDS = {"first": "1st", "second": "2nd", "third": "3rd", "fourth": "4th", "fifth": "5th", "sixth": "6th",
                  "seventh": "7th", "eighth": "8th", "ninth": "9th", "tenth": "10th", "eleventh": "11th",
                  "twelfth": "12th"}
_ONE_DIGIT_ORD = re.compile(r"^0?([1-9](?:st|nd|rd|th))$")
# the layer's abbreviations of the words customers spell out, both ways
_SFX_ALT = {"ridge": ("rdg",), "rdg": ("ridge",), "trails": ("trls",), "trls": ("trails",), "pkwy": ("pky",),
            "pky": ("pkwy",), "pt": ("pte", "pointe"), "pte": ("pt", "pointe"), "pointe": ("pte", "pt"),
            "view": ("vw",), "vw": ("view",), "vista": ("vis",), "vis": ("vista",), "hill": ("hl",), "hl": ("hill",),
            "hills": ("hls",), "hls": ("hills",), "crossing": ("xing",), "xing": ("crossing",), "estates": ("ests",),
            "ests": ("estates",), "meadows": ("mdws",), "mdws": ("meadows",), "knoll": ("knl",), "knl": ("knoll",),
            "landing": ("lndg",), "lndg": ("landing",), "springs": ("spgs",), "spgs": ("springs",),
            "canyon": ("cyn",), "cyn": ("canyon",), "center": ("ctr",), "centre": ("ctr",), "ctr": ("center",),
            "plaza": ("plz",), "plz": ("plaza",), "gln": ("gl",), "gl": ("gln",), "driveway": ("drwy",),
            "drwy": ("driveway",)}
# suffix words only (never a street's name): what rules 3 and 4 may treat as a suffix
_STRICT_SFX = set(SA._SUFFIX.values()) | {"rdg", "trls", "pky", "pte", "xing", "spur", "ests", "mdws", "knl",
                                          "lndg", "spgs", "cyn", "ctr", "plz", "gl", "drwy", "vw", "hl", "hls", "vis"}
_SPELLED_SFX = {"drive": "dr", "avenue": "ave", "street": "st", "court": "ct", "place": "pl", "circle": "cir",
                "terrace": "ter", "boulevard": "blvd", "parkway": "pkwy", "highway": "hwy", "trail": "trl"}
_TYPO = {"hyw": "hwy", "hiway": "hwy", "hway": "hwy", "dirve": "dr", "drvie": "dr", "aveune": "ave"}
_JOINED = ("blvd", "ave", "cir", "ter", "way", "trl", "st", "dr", "ct", "rd", "ln", "pl")
_DIR_WORD = {"n": "north", "s": "south", "e": "east", "w": "west"}


def _swap1(a, b):
    """a and b differ by two neighbouring letters swapped ("dirve" / "drive")."""
    if len(a) != len(b) or a == b:
        return False
    d = [i for i in range(len(a)) if a[i] != b[i]]
    return len(d) == 2 and d[1] == d[0] + 1 and a[d[0]] == b[d[1]] and a[d[1]] == b[d[0]]


def _more_forms(st, suffixes=True):
    """Other ways to write the customer's street, beyond _street_forms. suffixes=False
    leaves their last word alone (rules 3-5 read it as a suffix or not: "Mountain Fairview" must never become a street
    "mountain" with the suffix "vis")."""
    def last_word(f):
        w = f.split()
        if len(w) < 2:
            return []
        out = [" ".join(w[:-1] + [a]) for a in _SFX_ALT.get(w[-1], ())]
        x = w[-1]
        if x in _TYPO:
            out.append(" ".join(w[:-1] + [_TYPO[x]]))
        elif x not in _STRICT_SFX and len(x) >= 5 and x.isalpha():
            for full, ab in _SPELLED_SFX.items():
                if _edit1(x, full) or _swap1(x, full):
                    out.append(" ".join(w[:-1] + [ab]))
                    break
            for ab in _JOINED:                                  # "lindenave" -> linden ave
                if x.endswith(ab) and len(x) - len(ab) >= 4:
                    out.append(" ".join(w[:-1] + [x[:-len(ab)], ab]))
                    break
        return out

    def words(f):
        w = f.split()
        out = []
        new = [_ORDINAL_WORDS.get(x, x) for x in w]
        if new != w:
            out.append(" ".join(new))
        for i, x in enumerate(w):
            m = _ONE_DIGIT_ORD.match(x)
            if m:
                alt = m.group(1) if x.startswith("0") else "0" + x     # a layer may write "06th st" (and "6th st")
                out.append(" ".join(w[:i] + [alt] + w[i + 1:]))
            if x in _TYPO and i == 0:
                out.append(" ".join([_TYPO[x]] + w[1:]))
            if x in ("center", "centre"):
                out.append(" ".join(w[:i] + ["centre" if x == "center" else "center"] + w[i + 1:]))
            if i > 0 and x in ("mt", "mtn"):
                out.append(" ".join(w[:i] + ["mountain"] + w[i + 1:]))
            if i == 0 and x in ("old", "olde") and len(w) > 1:
                out.append(" ".join(["olde" if x == "old" else "old"] + w[1:]))
        return out

    forms = list(_street_forms(st))
    for family in (words, words, last_word)[:3 if suffixes else 2]:      # twice: "sixth" -> 6th -> 06th
        for f in list(forms):
            for g in family(f):
                for h in _street_forms(g):                        # "hwy 9" -> state route 9
                    if h not in forms and len(forms) < 40:
                        forms.append(h)
    return forms


def _letters(street, expand=False):
    """The street's letters without spaces; expand: a direction after the first word spelled out ("park w ave"
    -> "parkwestave")."""
    w = street.split()
    if expand:
        w = w[:1] + [_DIR_WORD.get(x, x) for x in w[1:]]
    return "".join(w)


def _spaced(forms, rows):
    """Rule 2: the rows on the street with the customer's letters spaced otherwise (one street, or one spot)."""
    mine = {_letters(f) for f in forms}
    hit = [r for r in rows if _letters(r[0]) in mine or _letters(r[0], True) in mine]
    if hit and (len({r[0] for r in hit}) == 1 or _one_spot(hit)):
        return hit
    return []


def _parts2(street):
    """_parts, with the layer's other suffix abbreviations (_STRICT_SFX) read as a suffix."""
    words = street.split()
    sfx = words[-1] if len(words) > 1 and words[-1] in _STRICT_SFX else ""
    body = words[:-1] if sfx else words
    dirs = [w for w in body if w in SA._DIRS.values()]
    return dirs, [w for w in body if w not in SA._DIRS.values()], sfx


def _left_out(forms, streets, rows):
    """Rule 3: the streets that are the customer's street plus a suffix they left out; [] unless just one of them
    has the number and no other street at that number has their distinctive words ("707 Old Mill", with 707 on
    old mill spur and on mill park trl, is no guess)."""
    with_number = {r[0] for r in rows}
    fit = set()
    for f in forms:
        if _parts2(f)[2]:
            continue
        fit |= {c for c in streets if c.startswith(f + " ") and c[len(f) + 1:] in _STRICT_SFX}
    have = [c for c in fit if c in with_number]
    if len(have) != 1:
        return []
    own = [w for w in forms[0].split() if w not in _generic()]
    if own and any(set(own) <= set(c.split()) for c in with_number if c not in fit):
        return []
    return have


_LOOSE_SFX = {"pass", "path", "trace", "bend", "run", "alley"}   # suffixes a customer writes that are rarely a name


def _wrong_suffix(forms, streets):
    """Rule 4: the one street of the ZIP with the customer's name and another suffix ("385 Aspen Pass" -> aspen
    path; "12 Oak Ln" with Oak St and Oak Ct is no guess)."""
    fit = set()
    for f in forms:
        d, n, s = _parts2(f)
        w = f.split()
        if not s and len(w) > 1 and w[-1] in _LOOSE_SFX:
            n, s = [x for x in w[:-1] if x not in SA._DIRS.values()], w[-1]
        if len("".join(n)) < 4:
            continue
        if not s or not n:
            continue
        for c in streets:
            cd, cn, cs = _parts2(c)
            if cn == n and cs and cs != s and _same_or_missing(d, cd):
                fit.add(c)
    return sorted(fit) if len(fit) == 1 else []


def _osa(a, b, cap):
    """Edit distance with neighbouring swaps (optimal string alignment), or cap + 1 once it exceeds cap."""
    if abs(len(a) - len(b)) > cap:
        return cap + 1
    prev2, prev = None, list(range(len(b) + 1))
    for i in range(1, len(a) + 1):
        cur = [i] + [0] * len(b)
        for j in range(1, len(b) + 1):
            c = 0 if a[i - 1] == b[j - 1] else 1
            cur[j] = min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + c)
            if i > 1 and j > 1 and a[i - 1] == b[j - 2] and a[i - 2] == b[j - 1]:
                cur[j] = min(cur[j], prev2[j - 2] + 1)
        if min(cur) > cap:
            return cap + 1
        prev2, prev = prev, cur
    return prev[-1]


TWO_OFF_MIN = 8              # letters a name needs before two of them may be off (rule 5)


def _split_generic(name):
    """(the name's generic words in order, its other letters joined): ("old",), "mill" for old mill."""
    return tuple(w for w in name if w in _generic()), "".join(w for w in name if w not in _generic())


def _two_off(forms, zip_parts):
    """Rule 5: the one street of the ZIP whose name is one or two letters off theirs (two only at TWO_OFF_MIN
    letters or more; never a digit), the suffix and direction the same or left out. Only the distinctive words
    may be off; the generic ones must be the same ("Lakeview North" is never lakeview forth, nor "Old Mile"
    old mill)."""
    fit = set()
    for f in forms:
        d, n, s = _parts2(f)
        g, j = _split_generic(n)
        if len(j) < 5 or not j.isalpha():
            continue
        for c, cd, cj, cs, cg in zip_parts:
            if cg != g:
                continue
            if len(cj) < 5 or abs(len(cj) - len(j)) > 2:
                continue
            if not (_same_or_missing(s, cs) and _same_or_missing(d, cd)):
                continue
            cap = 2 if min(len(j), len(cj)) >= TWO_OFF_MIN else 1
            if 0 < _osa(j, cj, cap) <= cap:
                fit.add(c)
    return sorted(fit) if len(fit) == 1 else []


REFINE = True                # the street of their name with their number before a nearby number
REFUSE_TWINS = True          # two such streets with their number: no guess (not the near-spelling pick)
_PADDED = re.compile(r"^0(\d(?:st|nd|rd|th))$")


def _twin_parts(street):
    """(directions, name, suffix) for _twin: the layer's suffixes (_STRICT_SFX) and a direction after the suffix
    ("spruce st s") read as such, ordinal padding removed ("03rd" -> "3rd")."""
    words = street.split()
    dirs = sorted(w for w in words if w in SA._DIRS.values())
    body = [w for w in words if w not in SA._DIRS.values()]
    sfx = body[-1] if len(body) > 1 and body[-1] in _STRICT_SFX else ""
    name = body[:-1] if sfx else body
    return dirs, tuple(_PADDED.sub(r"\1", w) for w in name), sfx


def _twin_diff(key, street):
    """How far a layer's street is from the customer's (directions, name, suffix) when the names are the same
    (padding aside), else None: one for a suffix they wrote that differs, one for a direction they wrote that
    differs; nothing for what they left out."""
    kd, kn, ks = key
    cd, cn, cs = _twin_parts(street)
    if cn != kn:
        return None
    return (1 if ks and cs != ks else 0) + (1 if kd and sorted(cd) != sorted(kd) else 0)


def _best(rows):
    """(lon, lat, apn) of a matched place: a row with a parcel number when there is one."""
    r = next((x for x in rows if x[4]), rows[0])
    return r[2], r[3], r[4]


def _add_num_index(store):
    """The number+street index, added once to an address file built without it (a few seconds)."""
    p = store.path("addresses.sqlite")
    if not p.exists():
        return
    try:
        con = sqlite3.connect(str(p), timeout=30)
        try:
            if not con.execute("SELECT 1 FROM sqlite_master WHERE name = 'ix_num_nodir'").fetchone():
                con.executescript(ADDR_INDEX_NUM)
                con.commit()
        finally:
            con.close()
    except sqlite3.Error as e:
        _log.warning("places: couldn't add the number index (%s); other-ZIP matching is slower", e)


def _known_places(reg):
    """The region's known places (its known_places.json): named places customers write instead of a street
    address, each with the words that name it, its ZIPs and its point; [] when there are none."""
    rows = []
    p = reg.file("known_places") if reg is not None else None
    if p is not None:
        try:
            rows = json.loads(p.read_text(encoding="utf-8"))
        except (OSError, ValueError) as e:                 # never fails an import
            _log.warning("places: known_places.json unreadable (%s)", type(e).__name__)
            return []
    out = []
    for r in rows or []:
        try:
            lon, lat = float(r["lon"]), float(r["lat"])
            words = [re.sub(r"[^a-z0-9 ]+", " ", str(w).casefold()).strip() for w in r.get("words") or []]
            zips = [str(x)[:5] for x in r.get("zips") or []]
        except (KeyError, TypeError, ValueError):
            continue
        if reg.inside(lat, lon) and [w for w in words if w] and zips:
            parcel = re.sub(r"\D", "", str(r.get("parcel") or "")) or None     # its building class
            out.append({"name": str(r.get("name") or ""), "words": [w for w in words if w], "zips": zips,
                        "lon": lon, "lat": lat, "apn": parcel,
                        "no_zip": r.get("no_zip", True) is not False})   # false = its ZIP is required
    return out


def load_index(cfg):
    """The import's PlaceIndex, or None when no address points are on file yet."""
    try:
        if settings(cfg)["mode"] != "on":
            return None
        idx = PlaceIndex(Store(cfg))
        return idx if idx else None
    except Exception:                                   # noqa: BLE001 — places never fail an import
        return None


# ---------------------------------------------------------------- one run
ORDER = ("tax", "fires", "vector", "coast", "addresses", "parcels", "points")


class Updater:
    def __init__(self, cfg, store=None, fetcher=None, clock=None, version=""):
        self.cfg = cfg
        self.set = settings(cfg)
        self.store = store or Store(cfg)
        self.fetcher = fetcher or Fetcher(self.set["fetch"], version=version, cfg=cfg)
        self.clock = clock or dt.datetime.now
        self._stop = threading.Event()
        self._thread = None
        self.running = False
        self._load_areas = None

    def zip_areas(self):
        from . import weather as W
        return W.load_zip_areas()

    def due(self, now=None, by_hand=False):
        """The sources due for a check (in ORDER). A source that failed waits RETRY_HOURS in the background; by
        hand it is tried again at once (a "places update" by hand right after a failed run must read, not
        quietly skip)."""
        now = now or self.clock()
        meta, st = self.store.meta(), self.store.status()
        out = []
        for name in ORDER:
            if name not in self.set["sources"]:
                continue
            m = meta.get(name) or {}
            err = (st.get("errors") or {}).get(name)
            try:
                if err and not by_hand and \
                        (now - dt.datetime.fromisoformat(str(st.get("finished")))).total_seconds() < RETRY_HOURS * 3600:
                    continue
                if err and by_hand:
                    out.append(name)
                    continue
            except ValueError:
                pass
            last = [x for x in (m.get("built"), m.get("checked")) if x]
            try:
                age = (now - max(dt.datetime.fromisoformat(x) for x in last)).days if last else None
            except ValueError:
                age = None
            if age is None or age >= SOURCES[name][0] or m.get("method") != METHOD.get(name, 1):
                out.append(name)
        return out

    def run_once(self, say=None, force=False, only=None, by_hand=False, only_if_due=False):
        """Check (and where needed read) the sources due. A source that fails keeps what was on file. In the
        background at most one heavy source (addresses, parcels) is read in full a night; by hand, all are."""
        say = say or (lambda *_: None)
        with P.slot(self.cfg, "places", say=say):
            _add_num_index(self.store)                 # under the downloads lock, never beside a swap
            todo = [n for n in ORDER if n in (only or ORDER) and n in self.set["sources"]]
            if not force:
                due = self.due(by_hand=by_hand)
                todo = [n for n in todo if n in due]
            if only_if_due and not todo:
                return {"skipped": "not due"}
            f = self.fetcher
            f.requests = f.bytes = 0
            now = self.clock()
            f.deadline = P.run_deadline(self.cfg, now, MAX_RUN_HOURS, not by_hand)   # not past the window
            f.say = say
            self.running = True
            heavy_at = self.store.status().get("heavy_at")
            st = {"started": now.isoformat(timespec="seconds"), "errors": {}, "read": [], "checked": []}
            if heavy_at:
                st["heavy_at"] = heavy_at
            heavy_done = False
            if not by_hand and heavy_at:
                try:
                    heavy_done = (now - dt.datetime.fromisoformat(str(heavy_at))).total_seconds() < \
                        HEAVY_GAP_HOURS * 3600
                except ValueError:
                    pass
            changed = False
            try:
                for name in todo:
                    if SOURCES[name][2] and heavy_done and not by_hand:
                        say(f"{name}: left for the next night (one big download a night)")
                        continue
                    try:
                        read = getattr(self, "_" + name)(say, force, now)
                    except Exception as e:                       # noqa: BLE001 — one source, never the run
                        msg = str(e) if isinstance(e, (PlacesError, Z.FactsError, SA.SafetyError)) else \
                            P.unexpected(e, f"places {name}")
                        st["errors"][name] = msg[:200]
                        say(f"{name}: {msg}")
                        continue
                    (st["read"] if read else st["checked"]).append(name)
                    changed = changed or bool(read)
                    if read and SOURCES[name][2]:
                        heavy_done = True
                        st["heavy_at"] = now.isoformat(timespec="seconds")
                if changed or (force and not st["errors"]):
                    try:
                        facts = derive(self.store, self.zip_areas(), now.date())
                        self.store._write_json("zip_facts.json", {"built": now.isoformat(timespec="seconds"),
                                                                  "zips": facts})
                        say(f"ZIP facts: {len(facts)} ZIPs")
                    except Exception as e:                        # noqa: BLE001
                        st["errors"]["zip_facts"] = f"couldn't work out the ZIP facts ({type(e).__name__})"
                        _log.warning("places: zip facts failed: %s", e)
            finally:
                f.deadline = None
                self.running = False
            st.update(finished=self.clock().isoformat(timespec="seconds"), requests=f.requests, bytes=f.bytes,
                      error="; ".join(f"{k}: {v}" for k, v in st["errors"].items())[:300])
            self.store.write_status(st)
            return st

    # each source: True when it was read again (and written), False when only checked
    def _tax(self, say, force, now):
        areas = fetch_tax(self.fetcher, self.set)
        old = self.store.meta().get("tax") or {}
        _not_fewer("tax", "tax areas", len(areas), old, force)
        fp = _hash([(a["name"], a["rate"], a["code"], a["rings"]) for a in areas])    # the outlines too
        self.store._write_json("tax.json", {"areas": areas})
        same = old.get("fingerprint") == fp
        self.store.set_meta("tax", built=old.get("built") if same and old.get("built") else now.isoformat(timespec="seconds"),
                            checked=now.isoformat(timespec="seconds"), fingerprint=fp, cust=fp, rows=len(areas), method=1)
        rates = ", ".join(f"{a['name']} {a['rate']:.2f}%" for a in areas[:4])
        say(f"Sales tax: {len(areas)} areas ({rates} …)")
        return not same

    def _fires(self, say, force, now):
        f, s = self.fetcher, self.set
        lay = _layer(s, "fires")
        fp = layer_stats(f, lay, [("n", "count", "YEAR"), ("hi", "max", "YEAR"), ("a", "sum", "ACRES")])
        old = self.store.meta().get("fires") or {}
        age = _age(old.get("region_read") or old.get("built"), now)   # the region's own read
        on_file = self.store.fires()
        own = [x for x in on_file if x.get("source", "region") == "region"]
        recent_before = [x for x in on_file if x.get("source") == "national"]
        if not force and old.get("fingerprint") == fp and own and age is not None and age < SOURCES["fires"][1]:
            say(f"Fire history: the region's unchanged ({fp['n']:,} fires, newest {int(fp['hi'])})")
            region_read = old.get("region_read") or old.get("built")
        else:
            own = fetch_fires(f, s)
            region_read = now.isoformat(timespec="seconds")
            _not_fewer("fires", "fire perimeters", len(own), old, force)
            say(f"Fire history: {len(own):,} fires (newest {max(x['year'] for x in own)})")
        newest = max(x["year"] for x in own)
        try:
            recent = fetch_recent_fires(f, s, newest, own)      # the years after the region's own history
            if recent or s["region"].has("recent_fires"):
                say(f"Recent fires (national perimeters, {newest + 1} on): {len(recent)}"
                    + (f", newest {recent[-1]['name']} ({recent[-1]['alarm']})" if recent else ""))
        except Exception as e:                                  # noqa: BLE001 — never the region's read too
            msg = str(e) if isinstance(e, (PlacesError, Z.FactsError, SA.SafetyError)) else \
                P.unexpected(e, "places recent fires")
            recent = [x for x in recent_before if x["year"] > newest]
            say(f"Recent fires: {msg}; kept the {len(recent)} on file")
        fires = own + recent
        cust = _hash([(x["name"], x["year"], x["rings"]) for x in fires])
        changed = cust != old.get("cust") or not on_file
        if changed:
            self.store._write_json("fires.json", {"fires": fires})
        stamp = now.isoformat(timespec="seconds")
        self.store.set_meta("fires", built=stamp if changed else old.get("built") or stamp, checked=stamp,
                            region_read=region_read, fingerprint=fp, cust=cust, rows=len(own), recent=len(recent),
                            newest_region=newest, method=1)
        return changed

    def _vector(self, say, force, now):
        got = fetch_vector(self.fetcher, self.set, now.date())
        old = self.store.meta().get("vector") or {}
        _not_fewer("vector", "vector zones", len(got["zones"]), old, force)
        fp = _hash([(z["name"], z["agency"], z["species"]) for z in got["zones"]])
        self.store._write_json("vector.json", got)
        same = old.get("fingerprint") == fp
        self.store.set_meta("vector", built=old.get("built") if same and old.get("built") else now.isoformat(timespec="seconds"),
                            checked=now.isoformat(timespec="seconds"), fingerprint=fp, rows=len(got["zones"]), method=1,
                            cust=_hash([(z["name"], z["rings"]) for z in got["zones"]]))   # customers get the zone only
        say(f"Vector zones: {len(got['zones'])}, quarters to {quarter_label(got['quarters'] - 1)}; "
            f"{len(got['notices'])} treatment notice(s)")
        return not same

    def _coast(self, say, force, now):
        got = fetch_coast(self.fetcher, self.set, now.date())
        self.store._write_json("coast.json", got)
        self.store.set_meta("coast", built=now.isoformat(timespec="seconds"), checked=now.isoformat(timespec="seconds"),
                            rows=len(got["points"]), year=got["year"], method=1, cust=_hash(got["points"]))
        say(f"Coastline ({got['year']}): {len(got['points']):,} points along the region's coast and bays")
        return True

    def _addresses(self, say, force, now):
        f, s = self.fetcher, self.set
        lay = _layer(s, "addresses")
        stats = [("n", "count", "OBJECTID"), ("hi", "max", "OBJECTID"), ("s", "sum", "NUM")]
        fp = layer_stats(f, lay, stats)
        old = self.store.meta().get("addresses") or {}
        age = _age(old.get("built"), now)
        if not force and old.get("fingerprint") == fp and age is not None and age < SOURCES["addresses"][1] and \
                self.store.db_ok("addresses.sqlite"):
            self.store.set_meta("addresses", checked=now.isoformat(timespec="seconds"))
            say(f"Address points: unchanged ({int(fp['n']):,})")
            return False
        n_req = int(fp["hi"]) // SA.PAGE + 1
        say(f"Address points: reading {int(fp['n']):,} (about {n_req} request{'s' if n_req != 1 else ''}) …")
        con, tmp = self.store.new_db("addresses.sqlite", ADDR_SCHEMA)
        seen = kept = 0
        h = hashlib.sha1()
        try:
            params = {"outFields": lay.out_fields(R.CANON["addresses"]), "returnGeometry": "true", "outSR": "4326",
                      "geometryPrecision": "6"}
            for feats in by_id_ranges(f, lay, fp["hi"], SA.PAGE, params, "Address points", say, fp["n"]):
                seen += len(feats)
                rows = [r for r in (addr_row(x, lay) for x in feats) if r]
                kept += len(rows)
                h.update(repr(rows).encode())
                con.executemany("INSERT INTO pts VALUES (?,?,?,?,?,?,?,?,?)", rows)
            if seen < 0.999 * fp["n"]:
                raise PlacesError(f"read {seen:,} of {int(fp['n']):,} address points; kept the old ones")
            after = layer_stats(f, lay, stats)
            if after != fp:
                raise PlacesError("the address points changed while they were read; kept the old ones")
            con.executescript(ADDR_INDEX + ADDR_INDEX_NUM)
            self.store.commit_db("addresses.sqlite", con, tmp)
        except BaseException:
            con.close()
            tmp.unlink(missing_ok=True)
            raise
        self.store.set_meta("addresses", built=now.isoformat(timespec="seconds"), checked=now.isoformat(timespec="seconds"),
                            fingerprint=fp, rows=kept, method=1, cust=h.hexdigest()[:16])
        say(f"Address points: {kept:,} kept ({seen:,} read)")
        return True

    def _points(self, say, force, now):
        """The address points the canvassing app asks about, every field it reads exactly as the layer has it, with
        the layer's own row ids (points.py answers its questions from them, in the layer's order). Kept beside,
        never instead of, addresses.sqlite: the customers' places don't change when this is read. Checked weekly
        (one summary request), read again only when the layer changed."""
        f, s = self.fetcher, self.set
        lay = _layer(s, "points")
        stats = [("n", "count", "OBJECTID"), ("hi", "max", "OBJECTID"), ("s", "sum", "NUM")]
        fp = layer_stats(f, lay, stats)
        old = self.store.meta().get("points") or {}
        age = _age(old.get("built"), now)
        if not force and old.get("fingerprint") == fp and age is not None and age < SOURCES["points"][1] and \
                self.store.db_ok("points.sqlite"):
            self.store.set_meta("points", checked=now.isoformat(timespec="seconds"))
            say(f"Address points for canvassing: unchanged ({int(fp['n']):,})")
            return False
        n_req = int(fp["hi"]) // SA.PAGE + 1
        say(f"Address points for canvassing: reading {int(fp['n']):,} (about {n_req} requests) …")
        con, tmp = self.store.new_db("points.sqlite", POINTS_SCHEMA)
        seen = named = 0
        try:
            params = {"outFields": lay.out_fields(R.CANON["points"]), "returnGeometry": "true", "outSR": "4326"}
            for feats in by_id_ranges(f, lay, fp["hi"], SA.PAGE, params, "Address points for canvassing", say, fp["n"]):
                rows = [r for r in (points_row(x, lay) for x in feats) if r]
                seen += len(rows)
                named += sum(1 for r in rows if r[4])
                con.executemany("INSERT OR REPLACE INTO cpts VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", rows)
            # every point, not 99.9% of them: a street short by a few houses would be taken as whole
            if seen != int(fp["n"]):
                raise PlacesError(f"read {seen:,} of {int(fp['n']):,} address points for canvassing; kept the old ones")
            if named < 0.95 * seen:
                raise PlacesError("the address points came without their street names; kept the old ones")
            after = layer_stats(f, lay, stats)
            if after != fp:
                raise PlacesError("the address points changed while they were read; kept the old ones")
            con.executescript(POINTS_INDEX)
            self.store.commit_db("points.sqlite", con, tmp)
        except BaseException:
            con.close()
            tmp.unlink(missing_ok=True)
            raise
        self.store.set_meta("points", built=now.isoformat(timespec="seconds"), checked=now.isoformat(timespec="seconds"),
                            fingerprint=fp, rows=seen, method=1)
        say(f"Address points for canvassing: {seen:,} kept")
        return True

    def _parcels(self, say, force, now):
        f, s = self.fetcher, self.set
        lay = _layer(s, "parcels")
        fp = layer_stats(f, lay, [("n", "count", "OBJECTID"), ("hi", "max", "OBJECTID")])
        old = self.store.meta().get("parcels") or {}
        age = _age(old.get("built"), now)
        if not force and old.get("fingerprint") == fp and age is not None and age < SOURCES["parcels"][1] and \
                self.store.db_ok("parcels.sqlite"):
            self.store.set_meta("parcels", checked=now.isoformat(timespec="seconds"))
            say(f"Parcels: unchanged ({int(fp['n']):,})")
            return False
        say(f"Parcels: reading {int(fp['n']):,} (public fields only) …")
        con, tmp = self.store.new_db("parcels.sqlite", PARCEL_SCHEMA)
        seen = 0
        h = hashlib.sha1()
        try:
            params = {"outFields": lay.out_fields(R.CANON["parcels"]), "returnGeometry": "false"}
            for feats in by_id_ranges(f, lay, fp["hi"], 1000, params, "Parcels", say, fp["n"]):
                seen += len(feats)
                rows = []
                for x in feats:
                    a = lay.attrs(x)
                    parcel = str(a.get("PARCEL") or "").strip()
                    if parcel:
                        ac = a.get("ACRES")
                        rows.append((parcel, float(ac) if isinstance(ac, (int, float)) else None,
                                     str(a.get("CLASS") or "").strip() or None,
                                     str(a.get("JURIS") or "").strip() or None))
                h.update(repr(rows).encode())
                con.executemany("INSERT OR REPLACE INTO parcels VALUES (?,?,?,?)", rows)
            if seen < 0.999 * fp["n"]:
                raise PlacesError(f"read {seen:,} of {int(fp['n']):,} parcels; kept the old ones")
            self.store.commit_db("parcels.sqlite", con, tmp)
        except BaseException:
            con.close()
            tmp.unlink(missing_ok=True)
            raise
        self.store.set_meta("parcels", built=now.isoformat(timespec="seconds"), checked=now.isoformat(timespec="seconds"),
                            fingerprint=fp, rows=seen, method=1, cust=h.hexdigest()[:16])
        say(f"Parcels: {seen:,} read")
        return True

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
                        if not P.wait_off_peak(self.cfg, self._stop, self.clock, offset_min=60):
                            return
                        self.run_once(only_if_due=True)
                except Exception as e:                           # noqa: BLE001 — never take the hub down
                    _log.warning("places run failed: %s", type(e).__name__)
                    self.running = False
                if self._stop.wait(max(60, (self.next_run() - self.clock()).total_seconds())):
                    return
        self._thread = threading.Thread(target=loop, name="hub-places", daemon=True)
        self._thread.start()

    def stop(self):
        self._stop.set()


METHOD = {}                                       # source -> how it is read (bump to re-read one source everywhere)


def _hash(x):
    import hashlib
    return hashlib.sha1(json.dumps(x, sort_keys=True, default=str).encode()).hexdigest()[:16]


def _not_fewer(name, what, n, old, force):
    """A reply that lost part of a small source used to replace a good one (and stayed, while the layer's
    summary didn't change). Fewer than on file is taken only by hand with --force."""
    had = int(old.get("rows") or 0)
    if not force and had and n < had:
        raise PlacesError(f"{n:,} {what} came back, fewer than the {had:,} on file; kept those "
                          f"(zorderz_hub places update --force --only {name} takes the new list)")


def _age(when, now):
    try:
        return (now - dt.datetime.fromisoformat(str(when))).days
    except ValueError:
        return None


def summary(store):
    """What /health and `zorderz_hub places status` say: dates and counts only."""
    m, st = store.meta(), store.status()
    return {name: {"built": (m.get(name) or {}).get("built"), "checked": (m.get(name) or {}).get("checked"),
                   "rows": (m.get(name) or {}).get("rows")} for name in ORDER} | \
        {"last_try": st.get("finished"), "error": st.get("error") or "", "zips": len(store.zip_facts())}
