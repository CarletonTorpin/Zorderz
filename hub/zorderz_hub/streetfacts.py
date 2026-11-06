"""Street facts for Doorhangers.

The region's address points are on this computer already (places: addresses.sqlite), so a street's ZIP, its
house numbers and how many homes a route covered are read here before Doorhangers asks the region's website.
Only the region's own public data leaves: the street's name as the region writes it, a ZIP, the lowest and
highest house numbers and counts. Never a customer (nothing here reads the store).

    facts(addresses, [{"name": "Lupine Drive", "path": [[lat, lng], ...]}], near=(lat, lng))
        -> [{"name": "Lupine Drive", "street": "Lupine Dr", "zip": "00011", "low": 702, "high": 777,
             "homes": 30, "homes_on_street": 36, "addresses": 31, "lat": .., "lon": ..}, ...]

A path (a drawn route) keeps only the points within PATH_METRES of it, so "the part of the street we walked" is what is
counted; without one the whole street in the chosen ZIP is. A street in more than one ZIP picks the ZIP the path or the
`near` point is in, else the one with the most homes.
"""
import math
import sqlite3

import numpy as np

from .prospect import street_key, street_title

PATH_METRES = 60.0
MAX_STREETS = 12
MAX_PATH_POINTS = 600


def _metres_to_path(lat, lon, path):
    """Each point's distance in metres to a polyline [[lat, lng], ...] (flat-earth, fine at a street's scale)."""
    lat0 = math.radians(float(np.mean([p[0] for p in path])))
    kx, ky = 111320.0 * math.cos(lat0), 110540.0
    px, py = np.asarray(lon, dtype=float) * kx, np.asarray(lat, dtype=float) * ky
    best = np.full(px.shape, np.inf)
    for (a_lat, a_lon), (b_lat, b_lon) in zip(path[:-1], path[1:]):
        ax, ay, bx, by = a_lon * kx, a_lat * ky, b_lon * kx, b_lat * ky
        dx, dy = bx - ax, by - ay
        L2 = dx * dx + dy * dy
        t = np.zeros(px.shape) if L2 == 0 else np.clip(((px - ax) * dx + (py - ay) * dy) / L2, 0.0, 1.0)
        d = np.hypot(px - (ax + t * dx), py - (ay + t * dy))
        best = np.minimum(best, d)
    return best


def _clean_path(path):
    out = []
    for p in (path if isinstance(path, list) else [])[:MAX_PATH_POINTS]:
        if isinstance(p, (list, tuple)) and len(p) >= 2:
            try:
                la, lo = float(p[0]), float(p[1])
            except (TypeError, ValueError):
                continue
            if -90 <= la <= 90 and -180 <= lo <= 180 and not (math.isnan(la) or math.isnan(lo)):
                out.append((la, lo))
    return out if len(out) >= 2 else []


def _near(v):
    try:
        la, lo = float(v[0]), float(v[1])
        return (la, lo) if -90 <= la <= 90 and -180 <= lo <= 180 else None
    except (TypeError, ValueError, IndexError, KeyError):
        return None


def facts(addresses_path, streets, near=None, zip_names=None):
    zip_names = zip_names or {}
    near = _near(near) if near is not None else None
    wanted = []
    for s in (streets if isinstance(streets, list) else [])[:MAX_STREETS]:
        if isinstance(s, str):
            s = {"name": s}
        if not isinstance(s, dict) or not isinstance(s.get("name"), str):
            continue
        name = s["name"].strip()[:120]
        key = street_key(name)
        if key:
            wanted.append((name, key, _clean_path(s.get("path"))))
    if not wanted:
        return []
    con = sqlite3.connect(f"file:{addresses_path}?mode=ro", uri=True)
    out = []
    try:
        for name, key, path in wanted:
            rows = con.execute("SELECT num, street, zip, unit, lat, lon FROM pts WHERE street = ?", (key,)).fetchall()
            if not rows:  # "Meadow Glen Way" for the region's "meadow gln way e"
                rows = con.execute("SELECT num, street, zip, unit, lat, lon FROM pts WHERE nodir = ?",
                                   (key,)).fetchall()
            if not rows:
                out.append({"name": name, "found": False})
                continue
            num = np.array([r[0] if r[0] is not None else -1 for r in rows], dtype=float)
            zips = np.array([str(r[2] or "")[:5] for r in rows])
            unit = np.array([bool(str(r[3] or "").strip()) for r in rows])
            lat = np.array([r[4] for r in rows], dtype=float)
            lon = np.array([r[5] for r in rows], dtype=float)
            on = np.ones(len(rows), dtype=bool)
            if path:
                on = _metres_to_path(lat, lon, path) <= PATH_METRES
            # which ZIP: the one the walked part is in, else nearest `near`, else the most homes
            choices = sorted(set(zips[on] if on.any() else zips))
            if len(choices) > 1:
                if path and on.any():
                    pick = max(choices, key=lambda z: int(((zips == z) & on).sum()))
                elif near is not None:
                    def dist(z):
                        m = zips == z
                        return (float(np.mean(lat[m])) - near[0]) ** 2 + \
                               ((float(np.mean(lon[m])) - near[1]) * math.cos(math.radians(near[0]))) ** 2
                    pick = min(choices, key=dist)
                else:
                    pick = max(choices, key=lambda z: int(((zips == z) & ~unit).sum()))
            else:
                pick = choices[0]
            inz = zips == pick
            sel = inz & on if (path and (inz & on).any()) else inz
            nums = num[sel & (num > 0)]
            homes = sel & ~unit
            # one home per house number
            n_homes = len(set(num[homes & (num > 0)].tolist())) + int((homes & ~(num > 0)).sum())
            n_street = len(set(num[inz & ~unit & (num > 0)].tolist())) + int((inz & ~unit & ~(num > 0)).sum())
            out.append({
                "name": name, "found": True, "street": street_title(rows[0][1]), "zip": str(pick),
                "city": str(zip_names.get(str(pick), "")),
                "low": int(nums.min()) if len(nums) else None, "high": int(nums.max()) if len(nums) else None,
                "homes": int(n_homes), "addresses": int(sel.sum()), "homes_on_street": int(n_street),
                "on_path": bool(path) and bool((inz & on).any()),
                "lat": round(float(np.mean(lat[sel])), 6), "lon": round(float(np.mean(lon[sel])), 6),
            })
    finally:
        con.close()
    return out
