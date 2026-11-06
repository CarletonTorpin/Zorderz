"""Fire-safe organisations and the homes they cover.

Many regions have recognised neighbourhood fire-safety sites (a national program publishes each
as a point) and fire-safe councils (a county publishes each as a polygon). Charts can split and
filter sales by them and rate them per 1,000 homes. The layers come from the region pack
(region.py: "safety_sites", "safety_councils", and its address points); with neither layer named,
nothing here runs and nothing is offered.

  * Sites: every recognised site, as a point, with its city, residents and recognition date. A
    mailing-address field a layer may carry is a resident's and is never asked for.
  * Councils: one polygon per council, with its website, fire plan and district. Name, phone and
    e-mail fields a layer may carry are a volunteer's and are never asked for.
  * The region's address points inside each site and council.
  * The region pack's safety_sites.json: how each site's territory was traced (streets, a polygon,
    its council, or a circle around the point), its sponsoring organisation and official pages.
    Organisations only.

What leaves the hub: queries for public layers (the region's filter, the councils' own polygons,
the traced streets and polygons). No customer, job or customer address is ever sent; customers are
matched to the address points here, by the import, and only the territory's name is kept with the
customer (never the address).

Kept beside the store (an import never wipes it):
    <store folder>/safety/territories.json   sites and councils: who runs them, how drawn, counts, outlines
    <store folder>/safety/addresses.csv.gz   the address points in every territory, with its names
    <store folder>/safety/status.json        the last run
"""
import csv
import datetime as dt
import gzip
import hashlib
import json
import logging
import math
import os
import re
import threading
import time
import urllib.parse
from pathlib import Path

from . import polite as P
from . import region as R
from . import zipfacts as Z

_log = logging.getLogger("zorderz_hub.safety")

PAGE = 2000
MAX_PAGES = 400                        # 800,000 points: far above the service area's territories
DEFAULT_RADIUS = 300                   # metres: a site with no traced recipe
AT_DEFAULT = (4, 50)
EVERY_DAYS = 30
FIRST_DELAY = 900
RETRY_HOURS = 20
PAUSE = 1.0                            # seconds between requests
BUDGET = 1500                          # requests a run at most (a full rebuild: about 370)
PROGRESS_EVERY = 30                    # seconds between "… so far" lines while one territory is read
MAX_RUN_HOURS = 2                      # a run still going after this stops cleanly and keeps the old data
REBUILD_DAYS = 90                      # rebuilt at least this often even when nothing seems to have changed
METHOD = 2                             # how points are read; a change rebuilds everything
NOT_IN_SITE = "(not in a listed site)"
NOT_IN_COUNCIL = "(no listed council)"
SPLITS = ("safety_site", "safety_council")
FILTERS = {"safety_sites": "safety_site", "safety_councils": "safety_council"}
ANY = "*"
UA = "zorderz-hub/{v} (fire-safe territories; the region's public layers; monthly)"


class SafetyError(Exception):
    pass


def folder_of(cfg):
    return Path(cfg["store"]).with_name("safety")


def settings(cfg):
    f = dict(cfg.get("safety") or {})
    at = str(f.get("at", "%02d:%02d" % AT_DEFAULT))
    try:
        at_t = tuple(int(x) for x in at.split(":")[:2])
    except ValueError:
        at_t = AT_DEFAULT
    reg = R.load(cfg)
    has = reg.has("safety_sites") and reg.has("addresses")
    return {
        "mode": str(f.get("mode", "on")).lower() if has else "off",
        "fetch": str(f.get("fetch", "auto")).lower(),
        "county": reg.name,
        "region": reg,
        "sites": reg.layer("safety_sites"),
        "councils_layer": reg.layer("safety_councils"),
        "addr": reg.layer("addresses"),
        "councils": "on" if reg.has("safety_councils") and str(f.get("councils", "on")).lower() == "on" else "off",
        "every_days": max(1, int(f.get("every_days", EVERY_DAYS) or EVERY_DAYS)),
        "at": at_t,
    }


def site_label():
    return R.current().label("safety_site")


def council_label():
    return R.current().label("safety_council")


# ---------------------------------------------------------------- addresses as people write them
_DIRS = {"n": "n", "north": "n", "s": "s", "south": "s", "e": "e", "east": "e", "w": "w", "west": "w"}
_SUFFIX = {"street": "st", "st": "st", "avenue": "ave", "ave": "ave", "av": "ave", "road": "rd", "rd": "rd",
           "drive": "dr", "dr": "dr", "lane": "ln", "ln": "ln", "court": "ct", "ct": "ct", "place": "pl",
           "pl": "pl", "boulevard": "blvd", "blvd": "blvd", "way": "way", "wy": "way", "circle": "cir",
           "cir": "cir", "terrace": "ter", "ter": "ter", "trail": "trl", "trl": "trl", "glen": "gln", "gln": "gln",
           "point": "pt", "pt": "pt", "parkway": "pkwy", "pkwy": "pkwy", "highway": "hwy", "hwy": "hwy",
           "row": "row", "loop": "loop", "cove": "cv", "cv": "cv", "crest": "crst", "crst": "crst",
           "heights": "hts", "hts": "hts", "path": "path", "walk": "walk", "square": "sq", "sq": "sq"}


def street_key(text):
    """'1234 N. Main Street, Apt 5' -> (1234, 'n main st'); (None, '') without a house number. The import's
    ZIP estimate reads an address with the same words, so both read it alike."""
    s = str(text or "").casefold()
    m = re.match(r"^\s*(\d+)(?:-?[a-z]\b|-\d+)?\s+(.*)$", s)
    if not m:
        return None, ""
    rest = re.sub(r"[#,].*$|\b(?:apt|unit|ste|suite|spc|space|bldg)\b.*$", "", m.group(2))
    rest = re.sub(r"^\s*\d+/\d+\s+", "", rest)                # "123 1/2 Main St": the half is the number's
    words = [w for w in re.split(r"[^a-z0-9]+", rest) if w]
    words = [_SUFFIX.get(_DIRS.get(w, w), _DIRS.get(w, w)) for w in words]
    return int(m.group(1)), " ".join(words)


def county_line(a):
    """The address point as one line ('1234 N MAIN ST')."""
    parts = [str(a.get(k) or "").strip() for k in ("num", "pdir", "name", "sfx", "postd")]
    return " ".join(p for p in parts if p and p != "0")


# ---------------------------------------------------------------- geometry (lon, lat degrees)
def _in_ring(x, y, ring):
    inside, n = False, len(ring)
    j = n - 1
    for i in range(n):
        xi, yi = ring[i][0], ring[i][1]
        xj, yj = ring[j][0], ring[j][1]
        if (yi > y) != (yj > y) and x < (xj - xi) * (y - yi) / ((yj - yi) or 1e-12) + xi:
            inside = not inside
        j = i
    return inside


def in_rings(x, y, rings):
    """Even-odd over all rings (ArcGIS rings: outer rings and holes together)."""
    inside = False
    for r in rings:
        if _in_ring(x, y, r):
            inside = not inside
    return inside


def circle(lon, lat, metres, n=32):
    dlat = metres / 111320.0
    dlon = metres / (111320.0 * math.cos(math.radians(lat)))
    ring = [[round(lon + dlon * math.cos(2 * math.pi * k / n), 6), round(lat + dlat * math.sin(2 * math.pi * k / n), 6)]
            for k in range(n)]
    return [ring + [ring[0]]]


def hull(points, pad_m=25.0):
    """Convex hull of [lon, lat] points, pushed out by pad_m (an outline for drawing, not for membership)."""
    pts = sorted({(round(p[0], 6), round(p[1], 6)) for p in points})
    if not pts:
        return []
    if len(pts) < 3:
        return circle(pts[0][0], pts[0][1], max(pad_m * 2, 40.0), 16)

    def cross(o, a, b):
        return (a[0] - o[0]) * (b[1] - o[1]) - (a[1] - o[1]) * (b[0] - o[0])
    lower, upper = [], []
    for p in pts:
        while len(lower) >= 2 and cross(lower[-2], lower[-1], p) <= 0:
            lower.pop()
        lower.append(p)
    for p in reversed(pts):
        while len(upper) >= 2 and cross(upper[-2], upper[-1], p) <= 0:
            upper.pop()
        upper.append(p)
    h = lower[:-1] + upper[:-1]
    if len(h) < 3:                                          # all in a line (one street): a padded box
        (x0, y0), (x1, y1) = pts[0], pts[-1]
        py = max(pad_m, 20.0) / 111320.0
        px = py / math.cos(math.radians(y0))
        lo_x, hi_x, lo_y, hi_y = min(x0, x1) - px, max(x0, x1) + px, min(y0, y1) - py, max(y0, y1) + py
        box = [[round(lo_x, 6), round(lo_y, 6)], [round(hi_x, 6), round(lo_y, 6)], [round(hi_x, 6), round(hi_y, 6)],
               [round(lo_x, 6), round(hi_y, 6)]]
        return [box + [box[0]]]
    cx = sum(p[0] for p in h) / len(h)
    cy = sum(p[1] for p in h) / len(h)
    out = []
    for x, y in h:
        dx, dy = (x - cx) * math.cos(math.radians(cy)), y - cy
        d = math.hypot(dx, dy) or 1e-9
        grow = pad_m / 111320.0
        out.append([round(x + (dx / d) * grow / math.cos(math.radians(cy)), 6), round(y + (dy / d) * grow, 6)])
    return [out + [out[0]]]


def simplify(rings, tol):
    """Douglas-Peucker per ring (degrees). A closed ring is cut at its farthest point from the first, so the two
    halves have real chords; a ring keeps at least 4 points (the first again at the end)."""
    def rdp(pts):
        if len(pts) < 3:
            return pts
        a, b = pts[0], pts[-1]
        dmax, idx = 0.0, 0
        den = math.hypot(b[1] - a[1], b[0] - a[0])
        for i in range(1, len(pts) - 1):
            p = pts[i]
            if den < 1e-12:
                d = math.hypot(p[0] - a[0], p[1] - a[1])
            else:
                d = abs((b[1] - a[1]) * p[0] - (b[0] - a[0]) * p[1] + b[0] * a[1] - b[1] * a[0]) / den
            if d > dmax:
                dmax, idx = d, i
        if dmax > tol:
            return rdp(pts[:idx + 1])[:-1] + rdp(pts[idx:])
        return [a, b]
    out = []
    for r in rings:
        pts = [[round(p[0], 6), round(p[1], 6)] for p in r]
        if len(pts) > 1 and pts[0] == pts[-1]:
            pts = pts[:-1]
        if len(pts) < 3:
            continue
        far = max(range(len(pts)), key=lambda i: math.hypot(pts[i][0] - pts[0][0], pts[i][1] - pts[0][1]))
        half1 = rdp(pts[:far + 1])
        half2 = rdp(pts[far:] + [pts[0]])
        ring = half1[:-1] + half2
        if len(ring) < 4:                                   # too thin to keep its shape: keep it as it was
            ring = pts + [pts[0]]
        out.append(ring)
    return out


def bbox(rings):
    xs = [p[0] for r in rings for p in r]
    ys = [p[1] for r in rings for p in r]
    return (min(xs), min(ys), max(xs), max(ys)) if xs else None


# ---------------------------------------------------------------- the services
class Fetcher(Z.Fetcher):
    """zipfacts.Fetcher (polite: one request a second at most, busy servers waited for) plus POST, for a council
    polygon too long for a URL. Tests pass an opener that gets the URL with the form appended as a query string."""

    def __init__(self, mode="auto", version="", pause=PAUSE, opener=None, cfg=None, budget=BUDGET, say=None):
        super().__init__(mode=mode, version=version, pause=pause, opener=opener, cfg=cfg, budget=budget, say=say)
        self.ua = P.user_agent(UA.format(v=version or "?"), cfg)

    def query(self, layer_url, params):
        params = dict(params, f="json")
        url = layer_url + "/query"
        body = urllib.parse.urlencode(params)
        try:
            if self.opener is not None or len(url) + len(body) < 1800:
                out = self.json(url + "?" + body)
            else:
                out = self.json(url, data=body.encode(), content_type="application/x-www-form-urlencoded")
        except Z.FactsError as e:
            raise SafetyError(str(e)) from None
        if not isinstance(out, dict):
            raise SafetyError(f"{Z._host(url)} answered something that isn't a query reply")
        return out

    def layer(self, layer_url):
        """The layer's description (?f=json): its editing dates, record limit and fields."""
        try:
            out = self.json(layer_url + "?f=json")
        except Z.FactsError as e:
            raise SafetyError(str(e)) from None
        return out if isinstance(out, dict) else {}


def _sql(v):
    return "'" + str(v).replace("'", "''") + "'"


def fetch_sites(f, s):
    lay = s["sites"]
    got = f.query(lay.url, {"where": lay.with_filter("1=1"), "outFields": lay.out_fields(R.CANON["safety_sites"]),
                            "returnGeometry": "true", "outSR": "4326", "orderByFields": lay.f("NAME"),
                            **({"cacheHint": "true"} if "arcgis.com" in lay.url else {})})
    out = []
    for ft in got.get("features") or []:
        a = lay.attrs(ft)
        g = ft.get("geometry") or {}
        if not a.get("NAME") or g.get("x") is None:
            continue
        rec = a.get("YEAR")
        try:
            rec = dt.datetime.fromtimestamp(rec / 1000, dt.timezone.utc).date().isoformat() \
                if isinstance(rec, (int, float)) and rec > 10000 else (str(rec) if rec else None)
        except (TypeError, ValueError, OverflowError):
            rec = None
        out.append({"name": str(a["NAME"]).strip(), "status": a.get("STATUS"), "city": a.get("CITY"),
                    "residents": a.get("RESIDENTS"), "recognised": rec,
                    "point": [round(float(g["x"]), 6), round(float(g["y"]), 6)]})
    if not out:
        raise SafetyError(f"the {site_label().lower()} layer listed none in {s['county']}")
    return out


def fetch_councils(f, s):
    lay = s["councils_layer"]
    skip = (lay.spec.get("skip_names") or "").strip()
    got = f.query(lay.url, {"where": lay.with_filter("NAME IS NOT NULL"), "outFields": lay.out_fields(R.CANON["safety_councils"]),
                            "returnGeometry": "true", "outSR": "4326", "geometryPrecision": "6",
                            "maxAllowableOffset": "0.00002"})
    by = {}
    for ft in got.get("features") or []:
        a = lay.attrs(ft)
        name = str(a.get("NAME") or "").strip()
        rings = (ft.get("geometry") or {}).get("rings") or []
        if not name or not rings or (skip and re.search(skip, name)) or str(a.get("ACTIVE") or "").lower() == "no":
            continue
        c = by.setdefault(name, {"name": name, "acres": a.get("ACRES"), "website": a.get("WEBSITE") or None,
                                 "cwpp": a.get("PLAN") or None, "district": (a.get("DISTRICT") or "").strip() or None,
                                 "rings": []})
        c["rings"] += [[[float(p[0]), float(p[1])] for p in r] for r in rings if len(r) >= 4]
    if not by:
        raise SafetyError(f"the {council_label().lower()} layer listed none")
    return sorted(by.values(), key=lambda c: c["name"])


def _addr_rows(got, lay):
    rows = []
    for ft in got.get("features") or []:
        a = lay.attrs(ft)
        g = ft.get("geometry") or {}
        num = a.get("NUM")
        rows.append({"oid": a.get("OBJECTID"), "num": int(num) if isinstance(num, (int, float)) else None,
                     "frac": a.get("FRAC") or "", "pdir": a.get("PREDIR") or "", "name": a.get("NAME") or "",
                     "postd": a.get("POSTDIR") or "", "sfx": a.get("SUFFIX") or "", "unit": a.get("UNIT") or "",
                     "zip": str(a.get("ZIP") or "")[:5], "apn": a.get("PARCEL") or "",
                     "community": a.get("COMMUNITY") or "",
                     "lon": round(float(g["x"]), 6) if g.get("x") is not None else None,
                     "lat": round(float(g["y"]), 6) if g.get("y") is not None else None})
    return rows


def fetch_addresses(f, s, where="1=1", geometry=None, distance=None):
    """Every address point the query selects."""
    rows = []
    for page in fetch_address_pages(f, s, where, geometry, distance):
        rows += page
    return rows


def fetch_address_pages(f, s, where="1=1", geometry=None, distance=None, geometry_out=True, label="", say=None):
    """The address points the query selects, a batch (list) at a time.

    By ID: a server may send short pages in the middle of an offset-paged list, and a short page taken as the
    end would cut a territory short. So every matching OBJECTID is asked for first (returnIdsOnly is not cut to
    the page size), then those points by objectIds, PAGE at a time; IDs a reply leaves out are asked for again in
    smaller batches, and a reply that brings none of them stops the run (so a territory is complete or not
    saved)."""
    lay = s["addr"]
    sel = {"where": lay.with_filter(where)}
    if geometry is not None:
        gtype = "esriGeometryPoint" if "x" in geometry else "esriGeometryPolygon"
        sel.update(geometry=json.dumps(dict(geometry, spatialReference={"wkid": 4326}), separators=(",", ":")),
                   geometryType=gtype, inSR="4326", spatialRel="esriSpatialRelIntersects")
        if distance:
            sel.update(distance=str(distance), units="esriSRUnit_Meter")
    got = f.query(lay.url, dict(sel, returnIdsOnly="true"))
    ids = sorted({int(i) for i in (got.get("objectIds") or []) if isinstance(i, (int, float))})
    if len(ids) > MAX_PAGES * PAGE:
        raise SafetyError(f"more address points than expected ({len(ids):,})")
    params = {"outFields": lay.out_fields(R.CANON["addresses"]), "returnGeometry": "true" if geometry_out else "false"}
    if geometry_out:
        params["outSR"] = "4326"
    pending, batch, done = ids, PAGE, 0
    missed, dropped = {}, 0
    allowed = max(5, len(ids) // 100)                      # points deleted while this run reads them
    told = time.monotonic()
    while pending:
        ask, pending = pending[:batch], pending[batch:]
        got = f.query(lay.url, dict(params, objectIds=",".join(map(str, ask))))
        want = set(ask)
        page = [r for r in _addr_rows(got, lay) if r["oid"] in want]
        have = {r["oid"] for r in page}
        missing = [i for i in ask if i not in have]
        capped = bool(got.get("exceededTransferLimit")) and bool(have)
        if capped:
            # the server's own reply size is smaller (it says so): follow it, and ask for the rest again
            batch = max(10, len(have))
            pending = missing + pending
        elif missing:
            # an ID missing from a reply that wasn't cut short is asked for once more, then let go (a point
            # deleted since the IDs were listed); too many let go fails the territory
            again = []
            for i in missing:
                missed[i] = missed.get(i, 0) + 1
                if missed[i] >= 2:
                    dropped += 1
                else:
                    again.append(i)
            if dropped > allowed:
                raise SafetyError(f"the layer didn't send {dropped:,} of the {len(ids):,} address points asked "
                                    "for" + (f" ({label})" if label else ""))
            pending = again + pending
        else:
            batch = min(PAGE, batch * 2)                   # grow back after a full reply
        if page:
            done += len(page)
            yield page
        if say and label and time.monotonic() - told >= PROGRESS_EVERY:
            say(f"    {label}: {done:,} of {len(ids):,} address points so far …")
            told = time.monotonic()


def address_stats(f, s):
    """(count, highest OBJECTID, sum of house numbers) of the whole address layer: one request that changes when
    the layer is reloaded or edited (such layers often publish no edit date). None when the server can't say."""
    lay = s["addr"]
    stats = [{"statisticType": t, "onStatisticField": lay.f(fld), "outStatisticFieldName": n}
             for t, fld, n in (("count", "OBJECTID", "n"), ("max", "OBJECTID", "hi"), ("sum", "NUM", "s"))]
    try:
        got = f.query(lay.url, {"where": lay.with_filter("1=1"), "outStatistics": json.dumps(stats, separators=(",", ":"))})
        a = ((got.get("features") or [{}])[0] or {}).get("attributes") or {}
        out = [a.get("n"), a.get("hi"), a.get("s")]
        return out if all(isinstance(x, (int, float)) for x in out) else None
    except (SafetyError, Z.FactsError, IndexError, AttributeError):
        return None


def streets_where(streets):
    """(ZIP='00011' AND ((NAME='OAK' AND SUFFIX='LN') OR …)) OR … per ZIP, in canonical field names (the layer
    puts its own names in: region.Layer.with_filter)."""
    by_zip = {}
    for st in streets or []:
        name = str(st.get("name") or "").strip().upper()
        if not name:
            continue
        sfx = str(st.get("sfx") or "").strip().upper()
        c = f"NAME={_sql(name)} AND " + (f"SUFFIX={_sql(sfx)}" if sfx else "(SUFFIX IS NULL OR SUFFIX='')")
        lo, hi = st.get("from"), st.get("to")
        if lo is not None and hi is not None:
            c += f" AND NUM>={int(lo)} AND NUM<={int(hi)}"
        by_zip.setdefault(str(st.get("zip") or "").strip(), []).append("(" + c + ")")
    parts = []
    for z, cs in sorted(by_zip.items()):
        inner = " OR ".join(cs)
        parts.append(f"(ZIP={_sql(z)} AND ({inner}))" if z else f"({inner})")
    return " OR ".join(parts)


# ---------------------------------------------------------------- recipes (the pack's traced boundaries)
def recipes_path(reg=None):
    """The region's tracing recipes for its safety sites (region.json files.safety_recipes), or None."""
    reg = reg or R.current()
    p = reg.folder / str((reg.data.get("files") or {}).get("safety_recipes") or "")
    return p if p.is_file() else None


def load_recipes(reg=None):
    """{site name: recipe} from the region's safety recipes file: how each site's territory was traced."""
    p = recipes_path(reg)
    if p is None:
        return {}
    try:
        raw = json.loads(p.read_text(encoding="utf-8")) or []
    except (OSError, ValueError):
        return {}
    out = {}
    for r in raw:
        if isinstance(r, dict) and r.get("site_name"):
            out[str(r["site_name"]).strip().casefold()] = r
    return out


def slug(name):
    return re.sub(r"[^a-z0-9]+", "-", str(name).casefold()).strip("-")


def _key(a):
    """One home: house number, street, unit and ZIP. Each unit counts (a mobile-home park at one house number is
    hundreds of homes, not one, or every rate per 1,000 homes there would be hundreds of times too high); a
    layer gives each unit its own point, and a duplicate point still counts once."""
    return (a.get("num"), street_key(county_line(a))[1], str(a.get("unit") or "").strip().casefold(), a.get("zip"))


def clockwise(ring):
    """The ring turning clockwise (an ArcGIS outer ring; a counter-clockwise ring is read as a hole)."""
    area = sum(ring[i][0] * ring[i + 1][1] - ring[i + 1][0] * ring[i][1] for i in range(len(ring) - 1))
    return list(reversed(ring)) if area > 0 else ring


# ---------------------------------------------------------------- the store
class Store:
    def __init__(self, cfg, pack_dir=None):
        self.cfg = cfg
        self.folder = folder_of(cfg)
        self.pack_dir = pack_dir or cfg.get("pack_dir")
        self._cache, self._mtime = None, None

    @property
    def path(self):
        return self.folder / "territories.json"

    def territories(self):
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

    def stamp(self):
        try:
            return self.path.stat().st_mtime_ns          # two runs in one second still differ
        except OSError:
            return 0

    ADDR_COLS = ["num", "frac", "pdir", "name", "sfx", "postd", "unit", "zip", "apn", "community", "lon", "lat",
                 "safety_sites", "safety_council"]

    def open_addresses(self):
        """A writer for a new addresses file (kept as .tmp until commit())."""
        self.folder.mkdir(parents=True, exist_ok=True)
        for old in self.folder.glob("addresses.*.csv.gz.tmp"):          # a run that died part-way
            try:
                old.unlink()
            except OSError:
                pass
        tmp = self._tmp = self.folder / f"addresses.{os.getpid()}.{threading.get_ident()}.csv.gz.tmp"
        fh = gzip.open(tmp, "wt", encoding="utf-8", newline="")
        w = csv.writer(fh)
        w.writerow(self.ADDR_COLS)
        return fh, w

    def commit(self, terr):
        """Both files written in full first, then swapped in (review: a full disk while writing
        territories.json used to leave new addresses beside old territories)."""
        t = self.path.with_suffix(".tmp")
        t.write_text(json.dumps(terr, indent=1, sort_keys=True), encoding="utf-8")
        self._tmp.replace(self.folder / "addresses.csv.gz")
        t.replace(self.path)
        self._cache, self._mtime = None, None

    def discard(self):
        tmp = getattr(self, "_tmp", None)
        if tmp is not None:
            try:
                tmp.unlink()
            except OSError:
                pass

    def lock(self):
        """One update at a time (the hub's own and `zorderz_hub safety update`): a file descriptor holding an
        exclusive lock, or None when another update holds it."""
        self.folder.mkdir(parents=True, exist_ok=True)
        fd = os.open(self.folder / "update.lock", os.O_RDWR | os.O_CREAT, 0o600)
        try:
            import fcntl
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except ImportError:
            pass
        except OSError:
            os.close(fd)
            return None
        return fd

    def addresses(self):
        return list(self.iter_addresses())

    def iter_addresses(self):
        p = self.folder / "addresses.csv.gz"
        if not p.exists():
            return
        with gzip.open(p, "rt", encoding="utf-8", newline="") as fh:
            yield from csv.DictReader(fh)

    def status(self):
        try:
            return json.loads((self.folder / "status.json").read_text(encoding="utf-8"))
        except (OSError, ValueError):
            return {}

    def write_status(self, st):
        self.folder.mkdir(parents=True, exist_ok=True)
        p = self.folder / "status.json"
        t = p.with_suffix(".tmp")
        t.write_text(json.dumps(st, indent=1, sort_keys=True), encoding="utf-8")
        t.replace(p)

    # what the hub's charts use
    def names(self, kind):
        t = self.territories() or {}
        return [x["name"] for x in t.get("sites" if kind == "safety_site" else "councils") or []]

    def shapes(self, kind, names=None):
        """A GeoJSON FeatureCollection of the territories' outlines (property z = the territory's name)."""
        t = self.territories() or {}
        items = t.get("sites" if kind == "safety_site" else "councils") or []
        feats = []
        for x in items:
            if names is not None and x["name"] not in names:
                continue
            rings = x.get("outline") or []
            if rings:
                feats.append({"type": "Feature", "properties": {"z": x["name"], "n": ""},
                              "geometry": {"type": "Polygon", "coordinates": rings}})
        return {"type": "FeatureCollection", "features": feats}

    def homes(self, kind):
        t = self.territories() or {}
        return {x["name"]: x.get("homes") for x in t.get("sites" if kind == "safety_site" else "councils") or []}


class AddressIndex:
    """The territories' address points, for matching customers on this computer (the import uses it)."""

    def __init__(self, rows):
        self.exact, self.loose = {}, {}
        vals = {}                                    # one tuple per distinct value (half a million rows share few)
        for a in rows:
            try:
                num = int(a.get("num") or 0)
            except ValueError:
                continue
            if not num:
                continue
            _, st = street_key(county_line(dict(a, num=num)))
            if not st:
                continue
            sites = tuple(x for x in str(a.get("safety_sites") or "").split("; ") if x)
            val = (sites, a.get("safety_council") or "")
            val = vals.setdefault(val, val)
            z = str(a.get("zip") or "")[:5]
            self.exact[(num, st, z)] = val
            self.loose.setdefault((num, _undirected(st), z), set()).add(val)

    def __len__(self):
        return len(self.exact)

    def match(self, address_line, zip5=""):
        """([site names], council name) for a customer's street address and ZIP, or ([], "").

        A ZIP is required. Within the ZIP, the street as written; then without a direction ("500 Broadway" for
        "500 N BROADWAY") when only one place fits; then without up to three trailing words ("500 N Broadway
        Oakdale", "Example Hills Rd")."""
        num, st = street_key(address_line)
        z = str(zip5 or "")[:5]
        if num is None or not st or len(z) != 5:
            return [], ""
        words = st.split()
        for cut in range(0, min(3, len(words) - 1) + 1):
            k = " ".join(words[:len(words) - cut])
            got = self.exact.get((num, k, z))
            if got is None:
                vs = self.loose.get((num, _undirected(k), z))
                got = next(iter(vs)) if vs and len(vs) == 1 else None
            if got is not None:
                return list(got[0]), got[1]
        return [], ""


def _undirected(st):
    return " ".join(w for w in st.split() if w not in ("n", "s", "e", "w")) or st


def load_index(cfg):
    """The address index for the import (None when there are no territories yet). Read row by row: the file
    holds half a million points."""
    try:
        idx = AddressIndex(Store(cfg).iter_addresses())
    except (OSError, EOFError, ValueError, csv.Error):
        return None
    return idx if len(idx) else None


# ---------------------------------------------------------------- one run
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

    def due(self, now=None):
        now = now or self.clock()
        t = self.store.territories() or {}
        st = self.store.status()
        if not t.get("sites"):
            if st.get("error"):
                try:
                    if (now - dt.datetime.fromisoformat(str(st.get("finished")))).total_seconds() < RETRY_HOURS * 3600:
                        return False
                except ValueError:
                    pass
            return True
        if st.get("error"):
            try:
                if (now - dt.datetime.fromisoformat(str(st.get("finished")))).total_seconds() < RETRY_HOURS * 3600:
                    return False
            except ValueError:
                return True
            return True
        if (t.get("fingerprint") or {}).get("method") != METHOD:
            return True                # built the old way: rebuild at the next quiet hour
        fresh = [t.get("built"), st.get("checked")]
        try:
            last = max(dt.datetime.fromisoformat(str(x)) for x in fresh if x)
        except ValueError:
            return True
        return (now - last).days >= self.set["every_days"]

    def run_once(self, say=None, force=False, only_if_due=False):
        """Read the sites and councils layers, build every territory, and write them. A run that fails part-way keeps
        the territories on file (nothing is written). when nothing the territories are built from has
        changed (the sites, the councils, the address points, the region's recipes), nothing is
        rebuilt (three small requests instead of about 370), unless `force` or the last build is 90 days old."""
        say = say or (lambda *_: None)
        with P.slot(self.cfg, "Fire-safe territories", say=say):              # never beside the weather or ZIP facts
            if only_if_due and not self.due():                     # done by hand while this waited
                return {"skipped": "not due any more"}
            fd = self.store.lock()
            if fd is None:                                         # two runs wrote one temp file
                say("Fire-safe territories: another update is running; this one wasn't started.")
                return {"skipped": "another update is running"}
            try:
                return self._run_locked(say, force, background=only_if_due)
            finally:
                os.close(fd)

    def _run_locked(self, say, force=False, background=False):
        self.running = True
        now = self.clock()
        f = self.fetcher
        f.requests = f.bytes = 0                                   # the budget is per run
        f.deadline = P.run_deadline(self.cfg, now, MAX_RUN_HOURS, background)   # not past the window
        before = self.store.status()
        st = {"started": now.isoformat(timespec="seconds")}
        if before.get("checked"):
            st["checked"] = before["checked"]                      # kept while this run goes on
        live = {"last": 0.0}

        def tell(text, every=PROGRESS_EVERY):                      # `safety status` shows it live
            if time.monotonic() - live["last"] >= every:
                live["last"] = time.monotonic()
                try:
                    self.store.write_status(dict(st, running=True, now=text.strip()[:160],
                                                 at=dt.datetime.now().isoformat(timespec="seconds"),
                                                 requests=f.requests))
                except OSError:
                    pass
            say(text)
        f.say = say
        tell("Starting …", every=0)
        try:
            got = self._build(tell, force)
            if got is None:                                        # nothing changed: nothing written
                st.update(error="", unchanged=True, checked=now.isoformat(timespec="seconds"))
                say("Nothing has changed in the sites, councils or address points since the last build "
                    f"({(self.store.territories() or {}).get('built')}); nothing was downloaded again.")
            else:
                terr, n_addr, notes = got
                terr["built"] = now.isoformat(timespec="seconds")
                self.store.commit(terr)
                st.update(error="", sites=len(terr["sites"]), councils=len(terr["councils"]),
                          addresses=n_addr, notes=notes[:20], checked=terr["built"])
        except Exception as e:                                     # noqa: BLE001 — never the hub
            self.store.discard()
            st["error"] = (str(e) if isinstance(e, (SafetyError, Z.FactsError)) else
                           P.unexpected(e, "safety"))[:300]
            say(f"Fire-safe territories: {st['error']}")
        finally:
            f.deadline = None
        st.update(finished=self.clock().isoformat(timespec="seconds"), requests=f.requests, bytes=f.bytes)
        self.store.write_status(st)
        self.running = False
        return st

    def fingerprint(self, sites, councils):
        """What the territories are built from, so an unchanged source isn't downloaded again. Such layers often
        publish no edit date, so: the sites and the councils as read (one request
        each, needed anyway), the address points' count, highest ID and sum of house numbers (one request), the
        pack's recipes, and how points are read (METHOD)."""
        def h(x):
            return hashlib.sha1(json.dumps(x, sort_keys=True, default=str).encode()).hexdigest()[:16]
        try:
            rp = recipes_path(self.set["region"])
            recipes = rp.read_bytes() if rp is not None else b""
        except (OSError, TypeError):
            recipes = b""
        return {"method": METHOD, "sites": h(sites), "councils": h([(c["name"], c.get("acres"), c["rings"])
                                                                   for c in councils]),
                "addresses": address_stats(self.fetcher, self.set), "recipes": hashlib.sha1(recipes).hexdigest()[:16]}

    def _build(self, say, force=False):
        f, s = self.fetcher, self.set
        notes = []
        sites = fetch_sites(f, s)
        say(f"{site_label()}s: {len(sites)} in {s['county']}")
        councils = fetch_councils(f, s) if s["councils"] == "on" else []
        say(f"{council_label()}s: {len(councils)}")
        fp = self.fingerprint(sites, councils)
        old = self.store.territories() or {}
        try:
            age = (self.clock() - dt.datetime.fromisoformat(str(old.get("built")))).days
        except ValueError:
            age = None
        if not force and old.get("sites") and fp["addresses"] and old.get("fingerprint") == fp and \
                age is not None and age < REBUILD_DAYS:
            return None
        council_by = {c["name"]: c for c in councils}
        recipes = load_recipes(self.set["region"])
        used = set()
        site_rows = {}                                             # oid -> address row (sites' points only)
        site_of = {}                                               # oid -> [site names]

        out_sites = []
        for site in sites:
            r = recipes.get(site["name"].casefold())
            if r:
                used.add(site["name"].casefold())
            b = (r or {}).get("boundary") or {"method": "radius", "radius_m": DEFAULT_RADIUS}
            method = b.get("method")
            rings, rows = [], []
            if method == "streets" and b.get("streets"):
                rows = fetch_addresses(f, s, where=streets_where(b["streets"]))
                rings = hull([[a["lon"], a["lat"]] for a in rows if a.get("lon") is not None])
            elif method == "polygon" and b.get("polygon"):
                ring = [[float(p[0]), float(p[1])] for p in b["polygon"]]
                if ring[0] != ring[-1]:
                    ring.append(ring[0])
                rings = [clockwise(ring)]                          # an anticlockwise ring is a hole
                rows = fetch_addresses(f, s, geometry={"rings": rings})
            elif method == "council" and b.get("council") in council_by:
                rings = council_by[b["council"]]["rings"]
                rows = fetch_addresses(f, s, geometry={"rings": rings})
            else:
                if method == "council":
                    notes.append(f"{site['name']}: council {b.get('council')!r} isn't in the councils layer; drawn as a circle")
                radius = int(b.get("radius_m") or DEFAULT_RADIUS)
                method = "radius"
                rings = circle(site["point"][0], site["point"][1], radius)
                rows = fetch_addresses(f, s, geometry={"x": site["point"][0], "y": site["point"][1]}, distance=radius)
            for a in rows:
                site_rows.setdefault(a["oid"], a)
                names = site_of.setdefault(a["oid"], [])
                if site["name"] not in names:
                    names.append(site["name"])
            homes = len({_key(a) for a in rows if a.get("num")})
            council = next((c["name"] for c in councils if in_rings(site["point"][0], site["point"][1], c["rings"])), None)
            sp = (r or {}).get("sponsor") or None
            out_sites.append({
                "id": slug(site["name"]), "name": site["name"], "city": site.get("city"),
                "status": site.get("status"), "recognised": site.get("recognised"),
                "listed_residents": site.get("residents"), "point": site["point"],
                "sponsor": {k: sp.get(k) for k in ("name", "type", "url")} if isinstance(sp, dict) else None,
                "official": list((r or {}).get("official") or []), "method": method,
                "confidence": (r or {}).get("confidence") or "low",
                "evidence": list((r or {}).get("evidence") or []), "notes": (r or {}).get("notes") or
                ("Not traced yet: a circle around the listed point." if not r else ""),
                "traced": bool(r), "council": council, "addresses": len(rows), "homes": homes,
                "zips": sorted({a["zip"] for a in rows if a.get("zip")}),
                "outline": simplify(rings, 0.00008)})
            say(f"  {site['name']}: {homes} homes ({method})")
        for name, r in recipes.items():
            if name not in used:
                notes.append(f"{r.get('site_name')}: no longer on the sites layer; left out")

        # the councils' address points go straight to the file, page by page (half a million of them)
        fh, w = self.store.open_addresses()
        written, n = set(), 0

        def put(a, council):
            nonlocal n
            if a["oid"] in written:
                return
            written.add(a["oid"])
            n += 1
            w.writerow([a.get("num"), a.get("frac"), a.get("pdir"), a.get("name"), a.get("sfx"), a.get("postd"),
                        a.get("unit"), a.get("zip"), a.get("apn"), a.get("community"), a.get("lon"), a.get("lat"),
                        "; ".join(site_of.get(a["oid"], [])), council])
        out_councils = []
        try:
            for c in councils:
                homes = set()
                count = 0
                if self._stop.is_set():
                    raise SafetyError("stopped: the hub is shutting down")
                for page in fetch_address_pages(f, s, geometry={"rings": c["rings"]}, geometry_out=False,
                                                label=c["name"], say=say):
                    for a in page:
                        a = site_rows.get(a["oid"], a)                  # a site's point keeps its coordinates
                        put(a, c["name"])
                        count += 1
                        if a.get("num"):
                            homes.add(_key(a))
                out_councils.append({
                    "id": slug(c["name"]), "name": c["name"], "acres": c.get("acres"), "website": c.get("website"),
                    "cwpp": c.get("cwpp"), "district": c.get("district"), "addresses": count, "homes": len(homes),
                    "sites": [x["name"] for x in out_sites if x["council"] == c["name"]],
                    "outline": simplify(c["rings"], 0.0003)})
                say(f"  {c['name']}: {len(homes)} homes")
            for oid, a in site_rows.items():                       # sites' points outside every council
                put(a, "")
        finally:
            fh.close()
        reg = s["region"]
        terr = {"version": 1, "county": s["county"], "sites": out_sites, "councils": out_councils, "fingerprint": fp,
                "sources": [x for x in (s["sites"].source, s["councils_layer"].source if s["councils_layer"] else "",
                                        s["addr"].source) if x]}
        return terr, n, notes

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
                        if not P.wait_off_peak(self.cfg, self._stop, self.clock, offset_min=40):
                            return
                        self.run_once(only_if_due=True)
                except Exception as e:                            # noqa: BLE001
                    _log.warning("safety run failed: %s", type(e).__name__)
                    self.running = False
                if self._stop.wait(max(60, (self.next_run() - self.clock()).total_seconds())):
                    return
        self._thread = threading.Thread(target=loop, name="hub-safety", daemon=True)
        self._thread.start()

    def stop(self):
        self._stop.set()


def summary(store):
    t = store.territories() or {}
    st = store.status()
    return {"sites": len(t.get("sites") or []), "councils": len(t.get("councils") or []),
            "traced": sum(1 for x in t.get("sites") or [] if x.get("traced")),
            "built": t.get("built"), "last_try": st.get("finished"), "error": st.get("error") or ""}


# ---------------------------------------------------------------- the CSVs (sales; public data + our own totals)
SITE_COLUMNS = ["kind", "name", "city", "sponsor", "sponsor_type", "sponsor_url", "official_pages", "recognised",
                "listed_residents", "region_addresses", "homes", "zips", "council", "boundary_method",
                "boundary_confidence", "boundary_evidence", "notes", "our_customers", "our_jobs", "our_revenue",
                "our_last_job", "customers_per_1000_homes"]


def export_rows(store, totals=None):
    """One row per fire-safe site and per council. totals: {(kind, name): {customers, jobs, revenue,
    last_job}} from the store (aggregates only)."""
    t = store.territories() or {}
    totals = totals or {}
    rows = []
    for x in t.get("sites") or []:
        g = totals.get(("safety_site", x["name"])) or {}
        sp = x.get("sponsor") or {}
        rows.append({"kind": site_label(), "name": x["name"], "city": x.get("city"), "sponsor": sp.get("name"),
                     "sponsor_type": sp.get("type"), "sponsor_url": sp.get("url"),
                     "official_pages": " ".join(x.get("official") or []), "recognised": x.get("recognised"),
                     "listed_residents": x.get("listed_residents"), "region_addresses": x.get("addresses"),
                     "homes": x.get("homes"), "zips": " ".join(x.get("zips") or []),
                     "council": x.get("council"), "boundary_method": x.get("method"),
                     "boundary_confidence": x.get("confidence"), "boundary_evidence": " ".join(x.get("evidence") or []),
                     "notes": x.get("notes"), **_ours(g, x.get("homes"))})
    for c in t.get("councils") or []:
        g = totals.get(("safety_council", c["name"])) or {}
        rows.append({"kind": council_label(), "name": c["name"], "city": None, "sponsor": c["name"],
                     "sponsor_type": "council", "sponsor_url": c.get("website"),
                     "official_pages": " ".join(u for u in (c.get("website"), c.get("cwpp")) if u),
                     "recognised": None, "listed_residents": None, "region_addresses": c.get("addresses"),
                     "homes": c.get("homes"), "zips": None, "council": c["name"],
                     "boundary_method": "published polygon", "boundary_confidence": "official",
                     "boundary_evidence": (R.current().url("safety_councils") or ""),
                     "notes": ("Sites inside: " + "; ".join(c["sites"]))
                     if c.get("sites") else "", **_ours(g, c.get("homes"))})
    return rows


def _ours(g, homes):
    cust = g.get("customers")
    return {"our_customers": cust, "our_jobs": g.get("jobs"),
            "our_revenue": round(g["revenue"], 2) if g.get("revenue") is not None else None,
            "our_last_job": g.get("last_job"),
            "customers_per_1000_homes": round(cust * 1000.0 / homes, 1) if cust is not None and homes else None}


def write_csv(path, rows, columns):
    """Rows (any iterable, read once) to a CSV; returns how many were written."""
    n = 0
    with open(path, "w", newline="", encoding="utf-8") as fh:
        w = csv.DictWriter(fh, fieldnames=columns, extrasaction="ignore")
        w.writeheader()
        for r in rows:
            w.writerow(r)
            n += 1
    return n


def territory_totals(data, pack):
    """{(kind, name): {customers, jobs, revenue, last_job}} from the store: aggregates only."""
    from . import compute
    lines = compute.select(data, pack, compute.base_spec(pack))
    out = {}
    for kind in SPLITS:
        tmap = compute.territory_of_customer(data, kind)
        if not tmap:
            continue
        keys = lines["customer_key"].astype(str)
        for name in sorted({n for v in tmap.values() for n in v}):
            sel = lines[keys.map(lambda k: name in (tmap.get(k) or ()))]
            if sel.empty:
                out[(kind, name)] = {"customers": 0, "jobs": 0, "revenue": 0.0, "last_job": None}
                continue
            out[(kind, name)] = {"customers": int(sel["customer_key"].nunique()),
                                 "jobs": int(sel["invoice_no"].nunique()),
                                 "revenue": round(float(sel["cents"].sum()) / 100.0, 2),
                                 "last_job": str(sel["issued"].max().date())}
    return out
