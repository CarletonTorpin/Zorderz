"""The region pack: where the business works, as data.

Charts can place customers on a street map and describe each ZIP (its fire history, its distance
to the coast, its sales tax area, the safety organisations that cover it). Every one of those
facts comes from public layers that differ from one county to the next, so none of them is in the
code: a REGION PACK names them. What a region's layers mean is the region's to say too: the
names of its hazard classes, what its vector surveillance looks for, its fire season. The hub reads one region pack, from the folder `region_dir` in the
hub's configuration (default: hub/regions/example, a small fictional region for trying the hub).

A region pack is a folder with `region.json`:

    {
      "id": "example", "name": "Example region", "state": "XX", "timezone": "America/Chicago",
      "box": [lat0, lat1, lon0, lon1],        the region with a margin; grids and downloads stay inside it
      "cell": 0.001,                           grid cell size in degrees
      "county_name": "EXAMPLE",                the name some layers filter by (as the layer writes it)
      "census": {"zips": ["00011", ...]},      the ZIPs whose census facts are read (optional)
      "labels": {"safety_site": "Safety site", ...},   also, for the layers that have them:
                                               hazard_vh / hazard_h / hazard (the hazard layer's top class, its
                                               second, either) and vector (what the vector feed finds)
      "fire_season": [6, 11],                  first and last month (optional; none = no fire season)
      "vector": {"species": [...], "quarter0": [2010, 1]},   the vector feed's watched species and first quarter
      "layers": {
        "addresses":  {"kind": "arcgis" | "file", "url" | "path": ..., "fields": {canonical: the layer's own name}},
        "points":     the address points the canvassing app asks about, kept in the layer's own words
        "parcels", "fires", "recent_fires", "tax", "vector_zones", "vector_notices", "coast",
        "hazard", "safety_sites", "safety_councils", "zip_areas", "known_places"
      }
    }

A layer that is not listed is simply not there: its splits, filters, facts and ingredients are not
offered, and nothing is guessed. Two kinds of layer:

    arcgis  a public ArcGIS feature layer, read politely (polite.py) and only within the region's box;
    file    a file in the region folder (GeoJSON, or ArcGIS JSON), answered here by a small query engine
            with the same replies, so every reader in the hub works the same on either kind.

Each layer maps the hub's canonical field names (below) onto the layer's own, so a county's layer
is used as it is published. A few published sources need no mapping and are named by kind: the
Census coastline ("tiger_coastline"), the national wildfire perimeters ("wfigs"), and a vector
surveillance feed that publishes zone outlines and a quarterly timeline per species
("vectorsurv"). A region with no coast layer has no distance-to-the-coast facts or splits.
"""
import json
import math
import re
import threading
import urllib.parse
from pathlib import Path

from . import names

# The hub's canonical fields, per layer. A region's layer maps each to its own field name.
CANON = {
    "addresses": ("OBJECTID", "NUM", "FRAC", "PREDIR", "NAME", "POSTDIR", "SUFFIX", "UNIT", "ZIP", "PARCEL", "COMMUNITY"),
    "points": ("OBJECTID", "NUM", "FRAC", "PREDIR", "NAME", "POSTDIR", "SUFFIX", "UNIT", "JURIS", "ZIP", "SEGMENT",
               "PARCEL", "COMMUNITY", "KIND"),
    "parcels": ("OBJECTID", "PARCEL", "ACRES", "CLASS", "JURIS"),
    "fires": ("OBJECTID", "YEAR", "NAME", "ACRES", "ALARM", "CAUSE", "AGENCY"),
    "tax": ("OBJECTID", "NAME", "JURIS", "RATE", "START", "CODE", "AS_OF", "STATE_RATE", "COUNTY_RATE", "CITY_RATE"),
    "vector_notices": ("OBJECTID", "PROJECT", "STATUS", "TREATMENT", "METHOD", "REASON", "DATE", "COMMUNITY", "ENTERED"),
    "hazard": ("OBJECTID", "CLASS"),
    "safety_sites": ("OBJECTID", "NAME", "STATUS", "CITY", "RESIDENTS", "YEAR"),
    "safety_councils": ("OBJECTID", "NAME", "ACRES", "WEBSITE", "PLAN", "DISTRICT", "ENTITY", "ACTIVE", "UPDATED"),
}

# Labels with a meaning every region shares. What a hazard class or a vector feed is called is the region's
# (labels.hazard_vh / hazard_h / hazard / vector in region.json); without one, the ZIP facts use neutral words.
DEFAULT_LABELS = {
    "safety_site": "Safety site",
    "safety_council": "Safety council",
    "tax_area": "Sales tax area",
    "coast_band": "Distance to the coast",
    "coast": "Coastal or inland",
    "region": "Region",
}


class RegionError(Exception):
    pass


class Layer:
    """One layer of a region: where it is read from and how its fields map onto the hub's."""

    def __init__(self, region, name, spec):
        self.region, self.name = region, name
        self.spec = dict(spec or {})
        self.kind = str(self.spec.get("kind") or "arcgis").lower()
        self.fields = {str(k).upper(): str(v) for k, v in (self.spec.get("fields") or {}).items()}
        self.where = str(self.spec.get("where") or "")
        self.label = str(self.spec.get("label") or "")
        self.source = str(self.spec.get("source") or "")

    # -------------------------------------------------- where it is
    @property
    def url(self):
        """The layer's address: an https URL, or a file: URL into the region folder."""
        if self.kind == "file":
            p = self.region.folder / str(self.spec.get("path") or "")
            return "file:" + urllib.parse.quote(str(p))
        return str(self.spec.get("url") or "").rstrip("/")

    def path(self):
        return self.region.folder / str(self.spec.get("path") or "") if self.kind == "file" else None

    # -------------------------------------------------- field names
    def f(self, canon):
        """The layer's own name for a canonical field."""
        c = str(canon).upper()
        return self.fields.get(c, c)

    def out_fields(self, canons=None):
        cs = canons or CANON.get(self.name, ())
        return ",".join(self.f(c) for c in cs)

    def sql(self, text):
        """A where clause written with canonical field names, in the layer's own names."""
        out = str(text)
        for c in sorted(self.fields, key=len, reverse=True):
            out = re.sub(r"\b%s\b" % re.escape(c), self.fields[c], out)
        return out

    def attrs(self, feature):
        """A feature's attributes under the hub's canonical names (others kept as they are)."""
        a = dict((feature or {}).get("attributes") or (feature or {}).get("properties") or {})
        back = {v.casefold(): k for k, v in self.fields.items()}
        out = {}
        for k, v in a.items():
            out[back.get(str(k).casefold(), str(k).upper() if str(k).upper() in CANON.get(self.name, ()) else k)] = v
        return out

    def with_filter(self, where):
        """A where clause and the layer's own standing filter (the region's county, say)."""
        w = self.sql(where) if where else "1=1"
        return f"({self.where}) AND ({w})" if self.where else w


class Region:
    def __init__(self, folder, data):
        self.folder = Path(folder)
        self.data = dict(data or {})
        self.id = str(self.data.get("id") or self.folder.name)
        self.name = str(self.data.get("name") or "")
        self.state = str(self.data.get("state") or "")
        self.timezone = str(self.data.get("timezone") or "UTC")
        b = self.data.get("box") or [0.0, 0.0, 0.0, 0.0]
        self.box = tuple(float(x) for x in b[:4])
        self.cell = float(self.data.get("cell") or 0.001)
        self.county_name = str(self.data.get("county_name") or "")
        lat = (self.box[0] + self.box[1]) / 2.0
        self.cos_lat = math.cos(math.radians(lat))
        self.labels = dict(DEFAULT_LABELS, **(self.data.get("labels") or {}))
        self.layers = {k: Layer(self, k, v) for k, v in (self.data.get("layers") or {}).items() if isinstance(v, dict)}
        self.census = dict(self.data.get("census") or {})
        self.coast_side_miles = float(self.data.get("coast_side_miles") or 3.0)

    @property
    def configured(self):
        return bool(self.name) and self.box[1] > self.box[0] and self.box[3] > self.box[2]

    def has(self, layer):
        return layer in self.layers

    def layer(self, name):
        return self.layers.get(name)

    def url(self, name):
        lay = self.layers.get(name)
        return lay.url if lay else ""

    def label(self, key):
        return self.labels.get(key, key)

    def file(self, name):
        """A data file of the region folder (known_places.json, zip_areas.json …), or None."""
        lay = self.layers.get(name)
        if lay and lay.kind == "file" and lay.path() and lay.path().exists():
            return lay.path()
        p = self.folder / str(self.data.get("files", {}).get(name) or "")
        return p if p.is_file() else None

    def inside(self, lat, lon):
        return self.box[0] <= lat <= self.box[1] and self.box[2] <= lon <= self.box[3]


EMPTY = Region(Path("."), {})
_cache = {}
_current = {"region": EMPTY}
_lock = threading.Lock()


def load_folder(folder):
    folder = Path(folder)
    with _lock:
        key = str(folder.resolve()) if folder.exists() else str(folder)
        if key in _cache:
            return _cache[key]
        f = folder / "region.json"
        if not f.is_file():
            reg = Region(folder, {})
        else:
            try:
                reg = Region(folder, json.loads(f.read_text(encoding="utf-8")))
            except (OSError, ValueError) as e:
                raise RegionError(f"the region pack at {folder} could not be read: {e}") from None
        _cache[key] = reg
        return reg


def load(cfg=None):
    """The configured region (and it becomes the current one)."""
    folder = (cfg or {}).get("region_dir") or str(names.REGION_DIR)
    reg = load_folder(folder)
    _current["region"] = reg
    return reg


def current():
    return _current["region"]


def set_current(reg):
    _current["region"] = reg or EMPTY
    return _current["region"]


def box():
    return current().box


def cos_lat():
    return current().cos_lat


def clear_cache():
    with _lock:
        _cache.clear()


# ---------------------------------------------------------------- local layers
# A file layer answers the queries the hub's readers send to an ArcGIS layer: a where clause made of
# comparisons joined by AND, returnIdsOnly, objectIds, returnCountOnly, outStatistics, an envelope, a
# polygon or a point and distance, paging by resultOffset / resultRecordCount.

_COND = re.compile(r"""^\s*(?P<f>[A-Za-z_][A-Za-z0-9_]*)\s*(?:(?P<op>>=|<=|<>|!=|=|>|<)\s*(?P<v>'(?:[^']|'')*'|-?\d+(?:\.\d+)?)
                       |\s+(?P<in>NOT\s+IN|IN)\s*\((?P<list>[^)]*)\)
                       |\s+IS\s+(?P<isnot>NOT\s+)?NULL
                       |\s+LIKE\s+(?P<like>'(?:[^']|'')*'))\s*$""", re.I | re.X)


def _lit(text):
    text = text.strip()
    if text.startswith("'"):
        return text[1:-1].replace("''", "'")
    try:
        return float(text) if "." in text else int(text)
    except ValueError:
        return text


def _split_top(where, word):
    """Split on a top-level AND / OR (outside quotes and parentheses)."""
    sep = f" {word} "
    parts, depth, cur, i, q = [], 0, "", 0, False
    s = where
    while i < len(s):
        ch = s[i]
        if ch == "'":
            q = not q
        if not q:
            if ch == "(":
                depth += 1
            elif ch == ")":
                depth -= 1
            elif depth == 0 and s[i:i + len(sep)].upper() == sep:
                parts.append(cur)
                cur, i = "", i + len(sep)
                continue
        cur += ch
        i += 1
    parts.append(cur)
    return [p.strip() for p in parts if p.strip()]


def _split_and(where):
    return _split_top(where, "AND")


def _strip_parens(p):
    p = p.strip()
    while p.startswith("(") and p.endswith(")"):
        depth, ok = 0, True
        for i, ch in enumerate(p):
            depth += ch == "("
            depth -= ch == ")"
            if depth == 0 and i < len(p) - 1:
                ok = False
                break
        if not ok:
            break
        p = p[1:-1].strip()
    return p


def _match_where(where, attrs):
    w = (where or "").strip()
    if not w or w == "1=1":
        return True
    up = {str(k).upper(): v for k, v in attrs.items()}
    ors = _split_top(_strip_parens(w), "OR")
    if len(ors) > 1:
        return any(_match_where(o, attrs) for o in ors)
    for part in _split_and(_strip_parens(w)):
        part = _strip_parens(part)
        if part == "1=1":
            continue
        if " AND " in part.upper() or " OR " in part.upper():
            if not _match_where(part, attrs):
                return False
            continue
        m = _COND.match(part)
        if not m:
            raise RegionError(f"a file layer cannot answer the condition {part!r}")
        v = up.get(m.group("f").upper())
        if m.group("op"):
            lit, op = _lit(m.group("v")), m.group("op")
            if v is None:
                return False
            try:
                a, b = (float(v), float(lit)) if isinstance(lit, (int, float)) else (str(v), str(lit))
            except (TypeError, ValueError):
                return False
            ok = {"=": a == b, ">=": a >= b, "<=": a <= b, ">": a > b, "<": a < b, "<>": a != b, "!=": a != b}[op]
            if not ok:
                return False
        elif m.group("in"):
            items = [_lit(x) for x in re.findall(r"'(?:[^']|'')*'|-?\d+(?:\.\d+)?", m.group("list"))]
            hit = any((str(v) == str(x)) or (isinstance(x, (int, float)) and v is not None and _num_eq(v, x)) for x in items)
            if hit == m.group("in").upper().startswith("NOT"):
                return False
        elif m.group("like"):
            pat = "^" + re.escape(_lit(m.group("like"))).replace("%", ".*").replace("_", ".") + "$"
            if v is None or not re.match(pat, str(v), re.I):
                return False
        else:
            is_null = v is None or v == ""
            if bool(m.group("isnot")) == is_null:
                return False
    return True


def _num_eq(a, b):
    try:
        return float(a) == float(b)
    except (TypeError, ValueError):
        return False


def _in_ring(x, y, ring):
    inside, j = False, len(ring) - 1
    for i in range(len(ring)):
        xi, yi = ring[i][0], ring[i][1]
        xj, yj = ring[j][0], ring[j][1]
        if (yi > y) != (yj > y) and x < (xj - xi) * (y - yi) / ((yj - yi) or 1e-12) + xi:
            inside = not inside
        j = i
    return inside


def _feature_points(geom):
    if not geom:
        return []
    if "x" in geom and "y" in geom:
        return [(float(geom["x"]), float(geom["y"]))]
    pts = []
    for ring in geom.get("rings") or geom.get("paths") or []:
        pts.extend((float(p[0]), float(p[1])) for p in ring)
    return pts


def _geom_test(params):
    g = params.get("geometry")
    if not g:
        return None
    g = json.loads(g) if isinstance(g, str) else g
    dist = float(params.get("distance") or 0)
    if "xmin" in g:
        x0, y0, x1, y1 = float(g["xmin"]), float(g["ymin"]), float(g["xmax"]), float(g["ymax"])
        return lambda pts: any(x0 <= x <= x1 and y0 <= y <= y1 for x, y in pts)
    if "rings" in g:
        rings = g["rings"]
        return lambda pts: any(sum(_in_ring(x, y, r) for r in rings) % 2 == 1 for x, y in pts)
    if "x" in g:
        cx, cy = float(g["x"]), float(g["y"])
        k = math.cos(math.radians(cy))

        def near(pts):
            return any(math.hypot((x - cx) * k * 111320.0, (y - cy) * 111320.0) <= dist for x, y in pts)
        return near
    return None


def _to_esri(feature):
    """A GeoJSON feature (or an ArcGIS one) as ArcGIS JSON: {attributes, geometry}."""
    if "attributes" in feature:
        return feature
    g = feature.get("geometry") or {}
    t, c = g.get("type"), g.get("coordinates")
    geom = None
    if t == "Point" and c:
        geom = {"x": c[0], "y": c[1]}
    elif t == "Polygon" and c:
        geom = {"rings": c}
    elif t == "MultiPolygon" and c:
        geom = {"rings": [r for poly in c for r in poly]}
    elif t == "LineString" and c:
        geom = {"paths": [c]}
    elif t == "MultiLineString" and c:
        geom = {"paths": c}
    return {"attributes": dict(feature.get("properties") or {}), "geometry": geom}


class LocalLayer:
    """A file layer, answering ArcGIS-style queries from memory."""

    _loaded = {}

    def __init__(self, path):
        self.path = Path(path)
        key = (str(self.path), self.path.stat().st_mtime if self.path.exists() else 0)
        if key not in LocalLayer._loaded:
            data = json.loads(self.path.read_text(encoding="utf-8")) if self.path.exists() else {}
            feats = [_to_esri(f) for f in (data.get("features") or [])]
            for i, f in enumerate(feats, 1):
                a = f["attributes"]
                if not any(str(k).upper() == "OBJECTID" for k in a):
                    a["OBJECTID"] = i
            if len(LocalLayer._loaded) > 16:
                LocalLayer._loaded.clear()
            LocalLayer._loaded[key] = feats
        self.features = LocalLayer._loaded[key]

    @staticmethod
    def _oid(f):
        for k, v in f["attributes"].items():
            if str(k).upper() == "OBJECTID":
                return int(v)
        return 0

    def query(self, params):
        params = {k: (v[0] if isinstance(v, list) else v) for k, v in dict(params).items()}
        if params.get("_describe"):
            return {"name": self.path.stem, "fields": [{"name": k} for k in (self.features[0]["attributes"] if self.features else {})],
                    "maxRecordCount": 100000, "editingInfo": {"lastEditDate": int(self.path.stat().st_mtime * 1000)}}
        where = params.get("where") or "1=1"
        test = _geom_test(params)
        ids = None
        if params.get("objectIds"):
            ids = {int(x) for x in str(params["objectIds"]).split(",") if x.strip().lstrip("-").isdigit()}
        hits = [f for f in self.features
                if (ids is None or self._oid(f) in ids) and _match_where(where, f["attributes"])
                and (test is None or test(_feature_points(f.get("geometry"))))]
        if str(params.get("returnCountOnly", "")).lower() == "true":
            return {"count": len(hits)}
        if str(params.get("returnIdsOnly", "")).lower() == "true":
            return {"objectIdFieldName": "OBJECTID", "objectIds": [self._oid(f) for f in hits]}
        if params.get("outStatistics"):
            stats = json.loads(params["outStatistics"])
            row = {}
            for s in stats:
                fld, kind, name = s["onStatisticField"], s["statisticType"].lower(), s["outStatisticFieldName"]
                vals = []
                for f in hits:
                    for k, v in f["attributes"].items():
                        if str(k).upper() == str(fld).upper() and isinstance(v, (int, float)):
                            vals.append(v)
                row[name] = {"count": len(hits), "max": max(vals) if vals else None, "min": min(vals) if vals else None,
                             "sum": sum(vals) if vals else 0}.get(kind)
            return {"features": [{"attributes": row}]}
        hits.sort(key=self._oid)
        off = int(params.get("resultOffset") or 0)
        n = int(params.get("resultRecordCount") or 0) or len(hits)
        page = hits[off:off + n]
        fields = str(params.get("outFields") or "*")
        want = None if fields.strip() in ("", "*") else {x.strip().upper() for x in fields.split(",")}
        geo = str(params.get("returnGeometry", "true")).lower() != "false"
        out = []
        for f in page:
            a = {k: v for k, v in f["attributes"].items() if want is None or str(k).upper() in want}
            item = {"attributes": a}
            if geo:
                item["geometry"] = f.get("geometry")
            out.append(item)
        return {"features": out, "exceededTransferLimit": off + n < len(hits)}


def local_open(url):
    """Answer a `file:` URL the readers built: <path>/query?<params> or <path>?f=json (the layer's description)."""
    parsed = urllib.parse.urlparse(url)
    path = urllib.parse.unquote(parsed.path)
    params = urllib.parse.parse_qs(parsed.query)
    describe = False
    if path.endswith("/query"):
        path = path[:-len("/query")]
    elif str((params.get("f") or [""])[0]).lower() == "json" and not params.get("where"):
        describe = True
    p = Path(path)
    if not p.is_file():
        raise RegionError(f"the region pack has no file {p.name}")
    if not p.suffix.lower().endswith("json"):
        return p.read_bytes()
    lay = LocalLayer(p)
    out = lay.query(dict(params, _describe=describe) if describe else params)
    return json.dumps(out).encode("utf-8")
