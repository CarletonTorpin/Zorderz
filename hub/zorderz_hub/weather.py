"""Weather where the jobs are; the ZIP areas file is the region pack's (zip_areas).

Temperature and rain from NOAA (free, public), beside the Charts questions, with the hub fetching new days on
its own every night.

Daily maximum and minimum temperature (degrees C) and precipitation (mm) on a 1/24-degree grid (about 4.6 km)
over the contiguous US since 1951, built from the GHCN-Daily weather stations. One file per month; the newest
months are "prelim" and are replaced by a "scaled" file once NOAA has checked them against its monthly product
(about two months later).

What leaves this computer: the hub asks NCEI's OPeNDAP service for ONE rectangle (the corners of the region's ZIP
areas, padded a little) one month at a time, as plain text. No ZIP list, customer, job or address is ever sent.

What is kept, beside the store (an import never wipes it):
    <store folder>/weather/grids/YYYYMM-<scaled|prelim>.npz   the rectangle's cells, per day (float32)
    <store folder>/weather/weather.sqlite                     each ZIP's daily values, built from the grids
    <store folder>/weather/status.json                        what was fetched, when, and the last error

A ZIP's value for a day is the mean of the grid cells whose centres fall inside the ZIP's area (ocean cells have
no value and are skipped); a ZIP too small to hold a cell centre takes the nearest land cell. NOAA notes that a
single cell on a single day can be noisy and recommends adding up over areas or time, which is what Charts does:
by week, month or more, over the ZIPs the jobs were in.
"""
import datetime as dt
import hashlib
import json
import logging
import math
import re
import shutil
import sqlite3
import threading
import time
import urllib.parse
from pathlib import Path

import numpy as np
import pandas as pd

from . import polite as P
from . import region as R

_log = logging.getLogger("zorderz_hub.weather")

SOURCE = "NOAA nClimGrid-Daily"
SOURCE_LONG = "NOAA NCEI nClimGrid-Daily v1 (public domain)"
BASE = "https://www.ncei.noaa.gov/thredds"
COLLECTION = "nclimgrid-daily"
VARS = ("tmax", "tmin", "prcp")               # tavg is (tmax + tmin) / 2, as NOAA computes it
EPOCH = dt.date(1800, 1, 1)                   # "days since 1800-01-01"
PAD = 0.06                                    # degrees around the ZIP areas (about one and a half cells)
FIRST_MONTH_DEFAULT = "2012-01"
NIGHTLY_AT = (3, 40)                          # local; after the 02:17 self-check
FIRST_DELAY = 240                             # seconds after the hub starts
RETRY_SECONDS = 3600                          # an unfinished download tries again an hour later
PAUSE = 1.0                                   # seconds between requests: one at a time, gently (polite.py)
BUDGET = 1200                                 # requests a run at most (the first fill: about 15 + 3 a month)
MAX_RUN_HOURS = 2                             # a run still going after this stops; the rest is fetched next time
TIMEOUT = 90
MAX_BYTES = 20 * 1024 * 1024                  # one month of the rectangle is about 1.5 MB of text
PRELIM_COOLDOWN = 6 * 3600                    # a prelim month is fetched again after six hours, not hourly
STALE_DAYS = 10                               # the newest day is normally 2-4 days old
UA = "zorderz-hub/{v} (nClimGrid-Daily; one small area, monthly)"
FILE_RE = re.compile(r"ncdd-(\d{6})-grd-(scaled|prelim)\.nc")
ZIP_RE = re.compile(r"^\d{5}$")
MM_PER_IN = 25.4
FILL_BELOW = -900.0                           # NOAA's fill value in prelim files is -999.99
RAIN_DAY_MM = 2.54                            # a rainy day: 0.1 inch or more
HOT_DAY_F = 90.0                              # a hot day: a high of 90 F or more


class WeatherError(Exception):
    pass


def c_to_f(c):
    return c * 9.0 / 5.0 + 32.0


def folder_of(cfg):
    return Path(cfg["store"]).with_name("weather")


def settings(cfg):
    w = dict(cfg.get("weather") or {})
    R.load(cfg)
    return {
        "mode": str(w.get("mode", "on")).lower(),                 # on | off (no fetching, no answers)
        "fetch": str(w.get("fetch", "auto")).lower(),             # auto | python | curl
        "first_month": str(w.get("first_month") or FIRST_MONTH_DEFAULT),
        "base": str(w.get("base") or BASE).rstrip("/"),
        "at": tuple(int(x) for x in str(w.get("at", "%02d:%02d" % NIGHTLY_AT)).split(":")[:2]),
    }


# ---------------------------------------------------------------- ZIP areas → grid cells
def zip_areas_path(pack_dir=None):
    """The ZIP outlines: the region pack's zip_areas file, else a zip_areas.json in `pack_dir`, else None."""
    p = R.current().file("zip_areas")
    if p is not None:
        return p
    q = Path(pack_dir) / "zip_areas.json" if pack_dir else None
    return q if q is not None and q.is_file() else None


def load_zip_areas(pack_dir=None):
    """{zip5: [polygon, ...]}, each polygon a list of rings [[lon, lat], ...] (the first ring is the outside)."""
    p = zip_areas_path(pack_dir)
    if p is None:
        raise WeatherError("no ZIP areas: the region pack has no zip_areas file")
    raw = json.loads(Path(p).read_text(encoding="utf-8"))
    out = {}
    for f in raw.get("features") or []:
        z = str((f.get("properties") or {}).get("z") or "").strip()
        g = f.get("geometry") or {}
        if not ZIP_RE.match(z) or g.get("type") not in ("Polygon", "MultiPolygon"):
            continue
        polys = [g["coordinates"]] if g["type"] == "Polygon" else g["coordinates"]
        out.setdefault(z, []).extend(polys)
    if not out:
        raise WeatherError("the region's zip_areas file has no ZIP areas")
    return out


def bounds(areas, pad=PAD):
    lons, lats = [], []
    for polys in areas.values():
        for poly in polys:
            for x, y in poly[0]:
                lons.append(x)
                lats.append(y)
    return (min(lats) - pad, max(lats) + pad, min(lons) - pad, max(lons) + pad)


def _in_ring(x, y, ring):
    inside, n = False, len(ring)
    j = n - 1
    for i in range(n):
        xi, yi = ring[i]
        xj, yj = ring[j]
        if (yi > y) != (yj > y) and x < (xj - xi) * (y - yi) / ((yj - yi) or 1e-12) + xi:
            inside = not inside
        j = i
    return inside


def _in_polys(x, y, polys):
    for poly in polys:
        if _in_ring(x, y, poly[0]) and not any(_in_ring(x, y, hole) for hole in poly[1:]):
            return True
    return False


def zip_cells(areas, lats, lons, land):
    """{zip: (flat cell indexes, basis)} where basis is "inside" or "nearest". `land` is a bool grid
    (lat x lon) of cells that have values; ocean cells never count."""
    out = {}
    lat_idx = np.arange(len(lats))
    for z, polys in sorted(areas.items()):
        xs = [x for poly in polys for x, _ in poly[0]]
        ys = [y for poly in polys for _, y in poly[0]]
        box = (min(ys), max(ys), min(xs), max(xs))
        cand_i = lat_idx[(lats >= box[0]) & (lats <= box[1])]
        cand_j = np.arange(len(lons))[(lons >= box[2]) & (lons <= box[3])]
        cells = [int(i * len(lons) + j) for i in cand_i for j in cand_j
                 if land[i, j] and _in_polys(float(lons[j]), float(lats[i]), polys)]
        if cells:
            out[z] = (cells, "inside")
            continue
        cy, cx = (box[0] + box[1]) / 2, (box[2] + box[3]) / 2
        li, lj = np.nonzero(land)
        if not len(li):
            continue
        d = (lats[li] - cy) ** 2 + ((lons[lj] - cx) * math.cos(math.radians(cy))) ** 2
        k = int(np.argmin(d))
        out[z] = ([int(li[k] * len(lons) + lj[k])], "nearest")
    return out


def cells_hash(cells):
    h = hashlib.sha256(json.dumps({z: c[0] for z, c in sorted(cells.items())}).encode()).hexdigest()
    return h[:16]


# ---------------------------------------------------------------- talking to NCEI
def _is_cert(e):
    return getattr(e, "kind", None) == "cert" or "certificate" in str(e).lower()


class Fetcher:
    """Plain HTTPS GETs through polite.request: one at a time, a second apart, a busy NCEI waited for and asked
    again, at most `budget` requests a run. A python.org Python on macOS may lack root certificates until its
    "Install Certificates" step has run, so on a certificate error the system curl (which uses the system's
    certificates) is tried once, and kept for the rest of the run."""

    def __init__(self, base=BASE, mode="auto", version="", pause=PAUSE, opener=None, cfg=None, budget=BUDGET,
                 say=None):
        self.base, self.mode, self.pause = base, mode, pause
        self.ua = P.user_agent(UA.format(v=version or "?"), cfg)
        self.opener = opener                  # tests: a function url -> bytes
        self.used = mode if mode in ("python", "curl") else ""
        self.requests = 0
        self.bytes = 0
        self.budget = budget
        self.say = say
        self.deadline = None

    def _raw(self, url, timeout):
        if self.deadline is not None and time.monotonic() > self.deadline:
            raise WeatherError("stopped: this run went past its time limit (the rest is fetched next time)")
        if self.budget is not None and self.requests >= self.budget:
            raise WeatherError(f"stopped after {self.requests} requests (this run's limit)")
        self.requests += 1
        if self.opener is not None:
            return self.opener(url)
        if self.used != "curl":
            try:
                data = self._python(url, timeout)
                self.used = self.used or "python"
                return data
            except (P.Trouble, WeatherError) as e:
                if not (_is_cert(e) and self.mode == "auto" and shutil.which("curl")):
                    raise
                self.used = "curl"
        return self._curl(url, timeout)

    def _python(self, url, timeout):
        return P.http(url, self.ua, timeout, MAX_BYTES)[2]

    def _curl(self, url, timeout):
        return P.http(url, self.ua, timeout, MAX_BYTES, curl=True)[2]

    def get(self, url, timeout=TIMEOUT):
        try:
            data = P.request(url, lambda: self._raw(url, timeout), pause=self.pause, say=self.say)
        except P.Trouble as e:
            raise WeatherError(e.message.replace(P.host_of(url) or "NCEI", "NCEI", 1)) from None
        if len(data) > MAX_BYTES:
            raise WeatherError("reply too large")
        self.bytes += len(data)
        return data

    # -- NCEI paths
    def catalog_url(self, year):
        return f"{self.base}/catalog/{COLLECTION}/{year}/catalog.xml"

    def dods_url(self, ym, kind):
        return f"{self.base}/dodsC/{COLLECTION}/{ym[:4]}/ncdd-{ym}-grd-{kind}.nc"

    def months_of_year(self, year):
        """{"YYYYMM": "scaled" | "prelim"} listed in NCEI's catalog for one year (scaled wins)."""
        text = self.get(self.catalog_url(year), timeout=60).decode("utf-8", "replace")
        out = {}
        for ym, kind in FILE_RE.findall(text):
            if ym[:4] != str(year):
                continue
            if out.get(ym) != "scaled":
                out[ym] = kind
        return out

    def time_length(self, ym, kind):
        text = self.get(self.dods_url(ym, kind) + ".dds", timeout=60).decode("utf-8", "replace")
        m = re.search(r"Int32\s+time\[time\s*=\s*(\d+)\]", text)
        if not m:
            raise WeatherError("no time axis in the dataset description")
        return int(m.group(1))

    def coords(self, ym, kind):
        url = self.dods_url(ym, kind) + ".ascii?" + urllib.parse.quote("lat,lon", safe=",")
        arrays = parse_ascii(self.get(url, timeout=60).decode("utf-8", "replace"))
        lat, lon = arrays.get("lat"), arrays.get("lon")
        if lat is None or lon is None:
            raise WeatherError("no latitude/longitude in the reply")
        return lat, lon

    def month(self, ym, kind, i0, i1, j0, j1):
        """One month of the rectangle: {"time", "lat", "lon", "tmax", "tmin", "prcp"} (numpy arrays)."""
        n = self.time_length(ym, kind)
        if n < 1 or n > 31:
            raise WeatherError(f"unexpected number of days ({n})")
        sel = f"[0:1:{n - 1}][{i0}:1:{i1}][{j0}:1:{j1}]"
        query = ",".join(v + sel for v in VARS)
        url = self.dods_url(ym, kind) + ".ascii?" + urllib.parse.quote(query, safe=",:")
        arrays = parse_ascii(self.get(url).decode("utf-8", "replace"))
        for v in VARS:
            if v not in arrays or arrays[v].shape != (n, i1 - i0 + 1, j1 - j0 + 1):
                raise WeatherError(f"{v}: unexpected shape")
        return arrays


# ---------------------------------------------------------------- OPeNDAP ASCII
_HEAD = re.compile(r"^([A-Za-z_][\w]*)(?:\.([A-Za-z_][\w]*))?((?:\[\d+\])+)\s*$")
_ROW = re.compile(r"^((?:\[\d+\])+),\s*(.*)$")


def parse_ascii(text):
    """OPeNDAP's .ascii reply → {name: numpy array}. A Grid's own array is kept under the grid's name
    ("tmax.tmax" → "tmax"), and its maps once ("tmax.lat" → "lat"). Values are floats ("NaN" kept)."""
    m = re.search(r"^-{20,}\s*$", text, re.M)
    if m:
        text = text[m.end():]
    out, name, shape, buf = {}, None, None, []

    def flush():
        if name is None:
            return
        arr = np.array(buf, dtype="float64")
        arr[arr <= FILL_BELOW] = np.nan          # a "prelim" month's days not made yet read -999.99
        size = int(np.prod(shape)) if shape else len(arr)
        if arr.size != size:
            raise WeatherError(f"{name}: expected {size} values, got {arr.size}")
        out.setdefault(name, arr.reshape(shape))

    for line in text.splitlines():
        line = line.strip()
        if not line:
            continue
        m = _HEAD.match(line)
        if m:
            flush()
            grid, member, dims = m.group(1), m.group(2), m.group(3)
            name = member if member and member != grid and member in ("time", "lat", "lon") else grid
            shape = tuple(int(x) for x in re.findall(r"\[(\d+)\]", dims))
            buf = []
            continue
        if name is None:
            continue
        r = _ROW.match(line)
        values = r.group(2) if r else line
        try:
            buf.extend(float(v) for v in values.split(",") if v.strip())
        except ValueError:
            raise WeatherError("a value in the reply isn't a number") from None
    flush()
    return out


def days_of(time_values):
    return [EPOCH + dt.timedelta(days=int(round(t))) for t in np.asarray(time_values).ravel()]


# ---------------------------------------------------------------- the local copy
class Store:
    """The grids on disk and each ZIP's daily values in SQLite. One writer at a time (the updater)."""

    def __init__(self, cfg, pack_dir=None):
        self.cfg = cfg
        self.folder = folder_of(cfg)
        self.grids = self.folder / "grids"
        self.db_path = self.folder / "weather.sqlite"
        self.status_path = self.folder / "status.json"
        self.pack_dir = Path(pack_dir or cfg.get("pack_dir") or "")
        self.lock = threading.Lock()
        self._frame = None
        self._frame_stamp = None
        self.last_failed = []

    # -- status
    def status(self):
        try:
            return json.loads(self.status_path.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            return {}

    def write_status(self, st):
        self.folder.mkdir(parents=True, exist_ok=True)
        tmp = self.status_path.with_suffix(".tmp")
        tmp.write_text(json.dumps(st, sort_keys=True, indent=1), encoding="utf-8")
        tmp.replace(self.status_path)

    # -- grids
    def grid_files(self):
        """{"YYYYMM": (kind, path)} for the grids on disk (scaled wins over prelim)."""
        out = {}
        if not self.grids.exists():
            return out
        for p in sorted(self.grids.glob("*.npz")):
            m = re.fullmatch(r"(\d{6})-(scaled|prelim)\.npz", p.name)
            if m and out.get(m.group(1), ("",))[0] != "scaled":
                out[m.group(1)] = (m.group(2), p)
        return out

    def save_grid(self, ym, kind, arrays, box):
        self.grids.mkdir(parents=True, exist_ok=True)
        tmp = self.grids / f".{ym}-{kind}.tmp.npz"
        np.savez_compressed(tmp, time=np.asarray(arrays["time"], dtype="int32"),
                            lat=np.asarray(arrays["lat"], dtype="float32"),
                            lon=np.asarray(arrays["lon"], dtype="float32"),
                            box=np.asarray(box, dtype="int32"),
                            **{v: np.round(np.asarray(arrays[v], dtype="float64"), 2).astype("float32") for v in VARS})
        final = self.grids / f"{ym}-{kind}.npz"
        tmp.replace(final)
        other = self.grids / f"{ym}-{'prelim' if kind == 'scaled' else 'scaled'}.npz"
        if kind == "scaled" and other.exists():
            other.unlink()
        return final

    @staticmethod
    def read_grid(path):
        with np.load(path) as z:
            return {k: z[k] for k in z.files}

    # -- ZIP cells
    def cells(self, lat, lon, land):
        areas = load_zip_areas(self.pack_dir)
        return zip_cells(areas, np.asarray(lat, dtype="float64"), np.asarray(lon, dtype="float64"), land)

    # -- ZIP days
    def _connect(self):
        self.folder.mkdir(parents=True, exist_ok=True)
        con = sqlite3.connect(self.db_path, timeout=30)
        con.execute("CREATE TABLE IF NOT EXISTS zip_day (zip TEXT NOT NULL, day TEXT NOT NULL, tmax REAL, "
                    "tmin REAL, prcp REAL, PRIMARY KEY (zip, day)) WITHOUT ROWID")
        con.execute("CREATE TABLE IF NOT EXISTS months (ym TEXT PRIMARY KEY, kind TEXT, days INTEGER, "
                    "last_day TEXT, built_at TEXT, grid TEXT)")
        con.execute("CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT)")
        return con

    @staticmethod
    def grid_stamp(path):
        """What a month's ZIP days were built from: the grid file's name, size and time."""
        st = Path(path).stat()
        return f"{Path(path).name}:{st.st_size}:{st.st_mtime_ns}"

    @staticmethod
    def grid_box(path):
        try:
            with np.load(path) as z:
                return tuple(int(x) for x in z["box"])
        except (OSError, KeyError, ValueError):
            return None

    def build_month(self, con, ym, kind, grid, cells, stamp=""):
        days = days_of(grid["time"])
        if any(d.strftime("%Y%m") != ym for d in days):
            raise WeatherError(f"{ym}: the file's days aren't all in that month")
        rows = []
        flat ={v: grid[v].reshape(grid[v].shape[0], -1) for v in VARS}
        for z, (idx, _basis) in cells.items():
            vals = {}
            with np.errstate(all="ignore"):
                for v in VARS:
                    sub = flat[v][:, idx]
                    ok = ~np.isnan(sub)
                    cnt = ok.sum(axis=1)
                    tot = np.where(ok, sub, 0.0).sum(axis=1)
                    vals[v] = np.where(cnt > 0, tot / np.maximum(cnt, 1), np.nan)
            for k, d in enumerate(days):
                r = [vals[v][k] for v in VARS]
                if all(math.isnan(x) for x in r):
                    continue
                rows.append((z, d.isoformat()) + tuple(None if math.isnan(x) else round(float(x), 2) for x in r))
        con.execute("DELETE FROM zip_day WHERE day >= ? AND day <= ?",
                    (f"{ym[:4]}-{ym[4:]}-01", f"{ym[:4]}-{ym[4:]}-31"))
        con.executemany("INSERT OR REPLACE INTO zip_day VALUES (?, ?, ?, ?, ?)", rows)
        made = sorted({r[1] for r in rows})
        con.execute("INSERT OR REPLACE INTO months VALUES (?, ?, ?, ?, ?, ?)",
                    (ym, kind, len(made), made[-1] if made else None,
                     dt.datetime.now().isoformat(timespec="seconds"), stamp))
        return len(rows)

    def rebuild(self, box=None, everything=False):
        """ZIP days from the grids on disk, local only (no network). A month is built when its grid file isn't
        the one it was last built from (a new or replaced file, or a run that stopped before building), or when
        the ZIP cells changed; each month in its own transaction, so one bad month never undoes the others.
        Grids of another rectangle (the ZIP areas grew) are left alone until they are fetched again.
        Returns (months built, rows)."""
        files = self.grid_files()
        if not files:
            return 0, 0
        boxes = {ym: self.grid_box(p) for ym, (_k, p) in files.items()}
        if box is None:
            box = next((boxes[ym] for ym in sorted(files, reverse=True) if boxes[ym]), None)   # the newest rectangle
        if box is None:
            return 0, 0
        box = tuple(box)
        same = sorted(ym for ym in files if boxes.get(ym) == box)
        if not same:
            return 0, 0
        built = rows = 0
        failed = sorted(ym for ym, b in boxes.items() if b is None)      # a damaged file: fetched again next run
        with self.lock:
            # Land: any cell with a value in ANY grid of this rectangle. Ocean cells never have one.
            ref, land = None, None
            for ym in same:
                try:
                    g = self.read_grid(files[ym][1])
                except (OSError, ValueError, KeyError):
                    continue
                has = ~(np.isnan(g["tmax"]).all(axis=0) & np.isnan(g["prcp"]).all(axis=0))
                if not has.any():
                    continue
                land = has if land is None else (land | has)
                ref = ref or g
            if ref is None:
                return 0, 0                              # nothing with values yet: leave what's stored alone
            cells = self.cells(ref["lat"], ref["lon"], land)
            if not cells:
                raise WeatherError("no ZIP area has a grid cell with values")
            h = cells_hash(cells) + ":" + ",".join(map(str, box))
            con = self._connect()
            try:
                old = dict(con.execute("SELECT k, v FROM meta").fetchall())
                done = {r[0]: r[1] for r in con.execute("SELECT ym, grid FROM months")}
                if everything:
                    con.execute("DELETE FROM zip_day")
                    con.execute("DELETE FROM months")
                    con.commit()
                    done = {}
                elif old.get("cells") != h:
                    done = {}                            # every month again, each replaced as it's built; months of
                    #                                      an older rectangle keep their rows until fetched again
                for ym in same:
                    kind, path = files[ym]
                    stamp = self.grid_stamp(path)
                    if done.get(ym) == stamp:
                        continue
                    try:
                        g = self.read_grid(path)
                        if g["lat"].shape != ref["lat"].shape or not np.allclose(g["lat"], ref["lat"]) \
                                or not np.allclose(g["lon"], ref["lon"]):
                            raise WeatherError(f"{ym}: the grid's cells don't match")
                        rows += self.build_month(con, ym, kind, g, cells, stamp)
                        con.commit()
                        built += 1
                    except (WeatherError, OSError, ValueError, KeyError) as e:
                        con.rollback()
                        failed.append(ym)
                        _log.warning("weather month %s not built (%s)", ym, type(e).__name__)
                # months whose grid file is gone: their rows go
                gone = [ym for ym in dict(con.execute("SELECT ym, grid FROM months").fetchall()) if ym not in files]
                for ym in gone:
                    con.execute("DELETE FROM zip_day WHERE day >= ? AND day <= ?",
                                (f"{ym[:4]}-{ym[4:]}-01", f"{ym[:4]}-{ym[4:]}-31"))
                    con.execute("DELETE FROM months WHERE ym = ?", (ym,))
                con.execute("INSERT OR REPLACE INTO meta VALUES ('cells', ?)", (h,))
                con.execute("INSERT OR REPLACE INTO meta VALUES ('zips', ?)", (json.dumps(
                    {z: {"cells": len(c[0]), "basis": c[1]} for z, c in cells.items()}, sort_keys=True),))
                con.commit()
            finally:
                con.close()
        self._frame = None
        self.last_failed = failed
        return built, rows

    def built_months(self):
        """{ym: grid stamp} of the months in weather.sqlite."""
        try:
            con = sqlite3.connect(f"file:{self.db_path}?mode=ro", uri=True, timeout=30)
        except sqlite3.Error:
            return {}
        try:
            return {r[0]: r[1] for r in con.execute("SELECT ym, grid FROM months")}
        except sqlite3.Error:
            return {}
        finally:
            con.close()

    # -- reading
    def frame(self):
        """Every ZIP-day as one DataFrame (zip, day, tmax, tmin, prcp in C / mm), cached until the file changes."""
        try:
            stamp = self.db_path.stat().st_mtime_ns
        except OSError:
            return pd.DataFrame(columns=["zip", "day", "tmax", "tmin", "prcp"])
        if self._frame is not None and self._frame_stamp == stamp:
            return self._frame
        con = sqlite3.connect(f"file:{self.db_path}?mode=ro", uri=True, timeout=30)
        try:
            f = pd.read_sql("SELECT zip, day, tmax, tmin, prcp FROM zip_day", con)
        finally:
            con.close()
        f["day"] = pd.to_datetime(f["day"])
        self._frame, self._frame_stamp = f, stamp
        return f

    def zips(self):
        try:
            con = sqlite3.connect(f"file:{self.db_path}?mode=ro", uri=True, timeout=30)
        except sqlite3.Error:
            return {}
        try:
            row = con.execute("SELECT v FROM meta WHERE k = 'zips'").fetchone()
            return json.loads(row[0]) if row else {}
        except sqlite3.Error:
            return {}
        finally:
            con.close()

    def coverage(self):
        """(first day, last day, first prelim month or None) of what is stored."""
        try:
            con = sqlite3.connect(f"file:{self.db_path}?mode=ro", uri=True, timeout=30)
        except sqlite3.Error:
            return None, None, None
        try:
            a, b = con.execute("SELECT MIN(day), MAX(day) FROM zip_day").fetchone()
            p = con.execute("SELECT MIN(ym) FROM months WHERE kind = 'prelim'").fetchone()[0]
            return (dt.date.fromisoformat(a) if a else None, dt.date.fromisoformat(b) if b else None, p)
        except sqlite3.Error:
            return None, None, None
        finally:
            con.close()

    def daily(self, weights, start, end):
        """One row per day from start to end (inclusive): the weighted mean over the given ZIPs of tmax,
        tmin, tavg (F) and prcp (inches). A ZIP with no value that day drops out and the others' weights
        are rescaled. Days with no value at all are NaN."""
        idx = pd.date_range(pd.Timestamp(start), pd.Timestamp(end), freq="D")
        cols = ["tmax", "tmin", "tavg", "prcp"]
        w = {z: float(v) for z, v in (weights or {}).items() if v and v > 0}
        f = self.frame()
        if not w or not len(f) or not len(idx):
            return pd.DataFrame(index=idx, columns=cols, dtype="float64")
        f = f[f["zip"].isin(list(w)) & (f["day"] >= idx[0]) & (f["day"] <= idx[-1])]
        out = {}
        for v in ("tmax", "tmin", "prcp"):
            p = f.pivot_table(index="day", columns="zip", values=v, aggfunc="mean")
            ww = pd.Series({z: w[z] for z in p.columns}, dtype="float64")
            have = p.notna()
            num = p.fillna(0.0).mul(ww, axis=1).sum(axis=1)
            den = have.mul(ww, axis=1).sum(axis=1)
            out[v] = (num / den.where(den > 0)).reindex(idx)
        d = pd.DataFrame(out, index=idx)
        d["tmax"] = c_to_f(d["tmax"])
        d["tmin"] = c_to_f(d["tmin"])
        d["tavg"] = (d["tmax"] + d["tmin"]) / 2.0
        d["prcp"] = d["prcp"] / MM_PER_IN
        return d[cols]


    def daily_many(self, weights_by_group, start, end):
        """daily() for several weightings at once -- the frame is filtered and pivoted ONCE over the union of
        the ZIPs, then each group's weighted mean is a vector product. {group: DataFrame as daily() gives}."""
        idx = pd.date_range(pd.Timestamp(start), pd.Timestamp(end), freq="D")
        cols = ["tmax", "tmin", "tavg", "prcp"]
        ws = {g: {z: float(v) for z, v in (w or {}).items() if v and v > 0} for g, w in (weights_by_group or {}).items()}
        empty = pd.DataFrame(index=idx, columns=cols, dtype="float64")
        union = sorted({z for w in ws.values() for z in w})
        f = self.frame()
        if not union or not len(f) or not len(idx):
            return {g: empty.copy() for g in ws}
        f = f[f["zip"].isin(union) & (f["day"] >= idx[0]) & (f["day"] <= idx[-1])]
        pivots = {v: f.pivot_table(index="day", columns="zip", values=v, aggfunc="mean").reindex(idx) for v in ("tmax", "tmin", "prcp")}
        out = {}
        for g, w in ws.items():
            if not w:
                out[g] = empty.copy()
                continue
            d = {}
            for v, p in pivots.items():
                zs = [z for z in p.columns if z in w]
                if not zs:
                    d[v] = pd.Series(np.nan, index=idx)
                    continue
                sub = p[zs]
                ww = pd.Series({z: w[z] for z in zs}, dtype="float64")
                num = sub.fillna(0.0).mul(ww, axis=1).sum(axis=1)
                den = sub.notna().mul(ww, axis=1).sum(axis=1)
                d[v] = num / den.where(den > 0)
            dd = pd.DataFrame(d, index=idx)
            dd["tmax"] = c_to_f(dd["tmax"])
            dd["tmin"] = c_to_f(dd["tmin"])
            dd["tavg"] = (dd["tmax"] + dd["tmin"]) / 2.0
            dd["prcp"] = dd["prcp"] / MM_PER_IN
            out[g] = dd[cols]
        return out


# ---------------------------------------------------------------- the updater
def month_list(first, last):
    """["YYYYMM", ...] from first ("YYYY-MM") to last (a date), inclusive."""
    y, m = int(first[:4]), int(first[5:7])
    out = []
    while (y, m) <= (last.year, last.month):
        out.append(f"{y:04d}{m:02d}")
        m += 1
        if m > 12:
            y, m = y + 1, 1
    return out


def box_indexes(lat, lon, box):
    """(i0, i1, j0, j1) of the cells inside (south, north, west, east)."""
    s, n, w, e = box
    ii = np.nonzero((lat >= s) & (lat <= n))[0]
    jj = np.nonzero((lon >= w) & (lon <= e))[0]
    if not len(ii) or not len(jj):
        raise WeatherError("the ZIP areas are outside NOAA's grid")
    return int(ii[0]), int(ii[-1]), int(jj[0]), int(jj[-1])


class Updater:
    """Fetches what is missing or still preliminary, then rebuilds those months' ZIP days. runs in the
    off-peak hours only (polite.py), every night at 03:40; an unfinished download retries hourly inside them."""

    def __init__(self, cfg, store=None, fetcher=None, clock=None, version=""):
        self.cfg = cfg
        self.set = settings(cfg)
        self.store = store or Store(cfg)
        self.fetcher = fetcher or Fetcher(self.set["base"], self.set["fetch"], version, cfg=cfg)
        self.clock = clock or dt.datetime.now
        self._stop = threading.Event()
        self._thread = None
        self.running = False
        self.progress = ""

    def run_once(self, today=None, limit=None, say=None):
        """One pass. Returns the status record it wrote. `limit` caps the months fetched (tests, CLI). one
        download at a time on this computer (polite.slot), a request budget and a time limit per run."""
        say = say or (lambda text: None)
        with P.slot(self.cfg, "weather", say=say):
            f = self.fetcher
            f.requests = f.bytes = 0
            f.deadline = time.monotonic() + MAX_RUN_HOURS * 3600
            f.say = say
            try:
                return self._run(today, limit, say)
            finally:
                f.deadline = None

    def _run(self, today=None, limit=None, say=None):
        today = today or self.clock().date()
        say = say or (lambda text: None)
        st = self.store.status()
        st.update(started=dt.datetime.now().isoformat(timespec="seconds"), error="", source=SOURCE_LONG)
        self.running = True
        fetched, failed = [], []
        try:
            areas = load_zip_areas(self.store.pack_dir)
            box = bounds(areas)
            have = self.store.grid_files()
            wanted = month_list(self.set["first_month"], today)
            listed = {}
            # a year whose every month is on file as final ("scaled") never changes again, so its NCEI
            # catalog isn't listed every night (it was: 15 requests for 2012-2026); all years again if the
            # rectangle (the ZIP areas) changed.
            # "final" also needs every month's file on the rectangle last fetched and readable, or a run
            # cut short after the ZIP areas grew (or a damaged file) would never be fetched again.
            box_key = [round(float(x), 5) for x in box]
            years = sorted({ym[:4] for ym in wanted})
            idx_was = st.get("grid_idx")
            final = set() if st.get("box") != box_key or not idx_was else {
                y for y in years if all(have.get(ym, ("",))[0] == "scaled"
                                        and list(self.store.grid_box(have[ym][1]) or ()) == list(idx_was)
                                        for ym in wanted if ym[:4] == y)}
            st["box"] = box_key
            for year in years:
                if year in final:
                    continue
                try:
                    listed.update(self.fetcher.months_of_year(year))
                except WeatherError as e:          # a year NOAA hasn't started, or a hiccup: the rest go on
                    st["error"] = f"{year}: {e}"[:160]
            idx = None
            lat = lon = None
            if any(ym in listed for ym in wanted):
                probe = next(ym for ym in reversed(wanted) if ym in listed)
                lat, lon = self.fetcher.coords(probe, listed[probe])
                idx = box_indexes(np.asarray(lat), np.asarray(lon), box)
                st["grid_idx"] = [int(x) for x in idx]
            now = time.time()
            todo = []
            for ym in wanted:
                kind = listed.get(ym)
                if not kind:
                    continue
                mine, path = have.get(ym, ("", None))
                other_box = path is not None and idx is not None and self.store.grid_box(path) != idx
                if mine == "scaled" and not other_box:
                    continue
                if mine == "prelim" and kind == "prelim" and not other_box and \
                        now - Path(path).stat().st_mtime < PRELIM_COOLDOWN:
                    continue                       # fetched a few hours ago (an hourly retry): not again yet
                todo.append((ym, kind))            # new, upgraded to scaled, a growing prelim, or a new rectangle
            if limit is not None:
                todo = todo[:limit]
            for n, (ym, kind) in enumerate(todo, 1):
                if self._stop.is_set():
                    break
                self.progress = f"{n} of {len(todo)} months"
                try:
                    arrays = self.fetcher.month(ym, kind, *idx)
                    exp_lat = np.asarray(lat)[idx[0]:idx[1] + 1]
                    exp_lon = np.asarray(lon)[idx[2]:idx[3] + 1]
                    if not (np.allclose(arrays["lat"], exp_lat, atol=1e-3) and np.allclose(arrays["lon"], exp_lon, atol=1e-3)):
                        raise WeatherError(f"{ym}: the reply's cells aren't the ones asked for")
                    self.store.save_grid(ym, kind, arrays, idx)
                    fetched.append(ym)
                    say(f"{ym} ({kind}): {arrays['time'].size} days")
                except WeatherError as e:
                    failed.append(ym)
                    st["error"] = str(e)[:160]
                    say(f"{ym} ({kind}): {e}")
                    if (len(failed) >= 3 and not fetched) or P.backed_off(self.fetcher.base):
                        break                      # NCEI down, blocked, or asking us to come back later
            if self.store.grid_files():            # whatever isn't built yet, this run's or an earlier one's
                built, rows = self.store.rebuild(box=idx)
                if built:
                    say(f"ZIP days built for {built} month(s) ({rows} rows)")
                if getattr(self.store, "last_failed", None):
                    st["error"] = f"{len(self.store.last_failed)} month(s) couldn't be built: " + \
                        ", ".join(self.store.last_failed[:4])
        except WeatherError as e:
            st["error"] = str(e)[:160]
        except Exception as e:                     # never let the weather take Charts down
            st["error"] = f"{type(e).__name__}"
            _log.warning("weather update failed (%s)", type(e).__name__)
        finally:
            self.running = False
            self.progress = ""
        first, last, prelim = self.store.coverage()
        have = self.store.grid_files()
        built = self.store.built_months()
        missing = [ym for ym in month_list(self.set["first_month"], today) if ym not in built]
        st.update(finished=dt.datetime.now().isoformat(timespec="seconds"), fetched=len(fetched),
                  failed=failed[:12], first_day=first.isoformat() if first else None,
                  last_day=last.isoformat() if last else None, prelim_from=prelim,
                  months=len(built), missing=len(missing), via=self.fetcher.used or "",
                  requests=self.fetcher.requests, bytes=self.fetcher.bytes)
        if fetched:
            st["last_fetch"] = st["finished"]
        self.store.write_status(st)
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
                # background downloads wait for the off-peak hours (polite.py)
                if not P.wait_off_peak(self.cfg, self._stop, self.clock):
                    return
                st = self.run_once()
                wait = (self.next_run() - self.clock()).total_seconds()
                if st.get("missing") or st.get("error"):
                    wait = min(wait, RETRY_SECONDS)
                if self._stop.wait(max(60, wait)):
                    return
        self._thread = threading.Thread(target=loop, name="charts-weather", daemon=True)
        self._thread.start()

    def stop(self):
        self._stop.set()


def summary(store, today=None):
    """What /health and the self-check say about the weather: dates and counts only."""
    st = store.status()
    first, last, prelim = store.coverage()
    today = today or dt.date.today()
    out = {"source": SOURCE, "first_day": first.isoformat() if first else None,
           "last_day": last.isoformat() if last else None,
           "prelim_from": f"{prelim[:4]}-{prelim[4:6]}" if prelim else None,
           "months": st.get("months", 0), "missing_months": st.get("missing"),
           "last_fetch": st.get("last_fetch"), "error": st.get("error") or ""}
    out["stale"] = bool(last is None or (today - last).days > STALE_DAYS)
    return out
