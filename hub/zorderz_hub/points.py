"""The region's address points, answered on this computer for the canvassing app.

The canvassing app's address lookups (a street's houses, a house number, a ZIP's count, the points in a small box)
are sent here first, in a closed shape, and this module answers them from places' `points.sqlite`: the region's
`points` layer read with its own values, unchanged (the name, suffix, directions, fraction, unit, road segment,
community and address type exactly as the layer writes them), so what comes back is what the layer's own server
would have sent -- the same rows, in the same order, cut at the same 2,000:

    {"name": "MAIN", "zip": "00011"}                  name = 'MAIN' AND zip = '00011'
    {"name": "MAIN", "like": true}                    name LIKE '%MAIN%'
    {"num": 123, "name": "MAIN", "zip": ...}          num = 123 AND name = ...
    {"num": 123, "zip": "00011"}                      num = 123 AND zip = '00011'
    {"zip": "00011", "count_only": true}              the connection check's count
    {"envelope": [xmin, ymin, xmax, ymax]}            every point in a box (lng/lat)

-> {"features": [{"attributes": {num, frac, predir, name, suffix, postdir, unit, zip, community, juris, segment,
   parcel, kind}, "geometry": {"x": lng, "y": lat} | null}], "exceeded": bool}
   or {"count": n}; None when the copy isn't on this computer yet (the canvassing app then asks the layer itself).

Public region data only; nothing here reads the store's customers. One read-only connection per thread.
"""
import math
import os
import sqlite3
import threading

MAX_RECORDS = 2000
TABLE = "cpts"
COLS = "oid, num, frac, pdir, name, postd, sfx, unit, jur, zip, seg, apn, community, atype, lon, lat"


def _num(v):
    if v is None:
        return None
    f = float(v)
    return int(f) if f.is_integer() else f


def _s(v):
    return "" if v is None else str(v)


class Points:
    def __init__(self, path):
        self.path = str(path)
        self._local = threading.local()
        self._all = []                                     # every connection opened, so close() can shut them
        self._all_lock = threading.Lock()

    def close(self):
        with self._all_lock:
            for c in self._all:
                try:
                    c.close()
                except sqlite3.Error:
                    pass
            self._all = []
        self._local = threading.local()

    def _signature(self):
        try:
            st = os.stat(self.path)
            return (st.st_size, st.st_mtime_ns, st.st_ino)
        except OSError:
            return None

    def _con(self):
        """This thread's connection, opened again when places swapped a new copy in."""
        sig = self._signature()
        if sig is None:
            return None
        c = getattr(self._local, "con", None)
        if c is not None and getattr(self._local, "sig", None) == sig:
            return c
        if c is not None:
            try:
                c.close()
            except sqlite3.Error:
                pass
            with self._all_lock:
                self._all = [x for x in self._all if x is not c]
        uri = "file:" + self.path.replace("?", "%3f").replace("#", "%23") + "?mode=ro"
        try:
            c = sqlite3.connect(uri, uri=True, check_same_thread=False)
            if c.execute("SELECT name FROM sqlite_master WHERE type='table' AND name=?", (TABLE,)).fetchone() is None:
                c.close()
                return None
        except sqlite3.Error:
            return None
        self._local.con, self._local.sig = c, sig
        with self._all_lock:
            self._all.append(c)
        return c

    def available(self):
        return self._con() is not None

    @staticmethod
    def feature(r):
        (oid, num, frac, pdir, name, postd, sfx, unit, jur, zip5, seg, apn, community, atype, lon, lat) = r
        geo = None
        if lon is not None and lat is not None and not (math.isnan(lon) or math.isnan(lat)):
            geo = {"x": lon, "y": lat}
        return {"attributes": {"num": _num(num), "frac": _s(frac), "predir": _s(pdir), "name": _s(name),
                               "suffix": _s(sfx), "postdir": _s(postd), "unit": _s(unit), "zip": _s(zip5),
                               "community": _s(community), "juris": _s(jur),
                               "segment": int(seg) if seg is not None else None, "parcel": _s(apn),
                               "kind": _s(atype)},
                "geometry": geo}

    def query(self, q):
        """One closed question -> the region's answer, or None when the copy isn't here."""
        con = self._con()
        if con is None:
            return None
        where, args = [], []
        env = q.get("envelope")
        if env is not None:
            xmin, ymin, xmax, ymax = (float(v) for v in env)
            where += ["lat BETWEEN ? AND ?", "lon BETWEEN ? AND ?"]
            args += [ymin, ymax, xmin, xmax]
        name = q.get("name")
        if name is not None:
            name = str(name)
            if q.get("like"):
                where.append("instr(name, ?) > 0")  # the region's LIKE '%x%': x anywhere in the name
            else:
                where.append("name = ?")
            args.append(name)
        num = q.get("num")
        if num is not None:
            where.append("num = ?")
            args.append(int(num))
        zip5 = q.get("zip")
        if zip5 is not None:
            where.append("zip = ?")
            args.append(str(zip5))
        if not where:
            return {"features": [], "exceeded": False}
        w = " AND ".join(where)
        if q.get("count_only"):
            return {"count": int(con.execute(f"SELECT COUNT(*) FROM {TABLE} WHERE {w}", args).fetchone()[0])}
        limit = max(1, min(MAX_RECORDS, int(q.get("limit") or MAX_RECORDS)))
        rows = con.execute(f"SELECT {COLS} FROM {TABLE} WHERE {w} ORDER BY oid LIMIT ?", args + [limit + 1]).fetchall()
        return {"features": [self.feature(r) for r in rows[:limit]], "exceeded": len(rows) > limit}

    def nearest_zip(self, lat, lon, within_m=600.0):
        """The ZIP of the nearest address point within `within_m` metres (a drop's place), else None."""
        con = self._con()
        if con is None:
            return None
        d = 0.01
        rows = con.execute(f"SELECT zip, lat, lon FROM {TABLE} WHERE lat BETWEEN ? AND ? AND lon BETWEEN ? AND ? "
                           "AND zip IS NOT NULL AND zip != ''", (lat - d, lat + d, lon - d, lon + d)).fetchall()
        if not rows:
            return None
        kx = 111320.0 * math.cos(math.radians(lat))
        best = min(rows, key=lambda r: math.hypot((r[2] - lon) * kx, (r[1] - lat) * 110540.0))
        dist = math.hypot((best[2] - lon) * kx, (best[1] - lat) * 110540.0)
        return str(best[0])[:5] if dist <= within_m else None
