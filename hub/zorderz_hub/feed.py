"""The live FreshBooks feed: invoices the website sends every hour.

How it reaches the numbers, without touching any counting code:

    website parts ──► stage() ──► merge() into live-feed.json (newest `updated` wins)
                                        │
                     mode "shadow" ─────┤  nothing else happens; `zorderz_hub feed-check`
                                        │  compares it with the business's exports
                     mode "live" ───────┴► write_live(): one CSV in the exact columns of
                                           FreshBooks' own "Invoices Export", beside the
                                           customer list, plus a tombstone file for deleted
                                           invoices → the watcher rebuilds the store, and
                                           the importer's "newest file wins" rule makes the
                                           live rows beat older exports.

The mode starts as "shadow". An admin switches to "live" once `feed-check` shows the feed and the exports agree
on every invoice that hasn't changed since the export.

Everything here is validated whole: a part with one malformed invoice is refused. Nothing in this module talks
to any model or leaves the hub computer.
"""
import csv
import hashlib
import io
import json
import os
import re
import threading
import time
from pathlib import Path

FEED_FILE = "live-feed.json"
STAGING_FILE = "live-feed.staging.json"
MODE_FILE = "feed-mode.json"
LIVE_CSV = "FreshBooks - Live feed.csv"
TOMBSTONES = "FreshBooks - Live feed.deleted.json"
MODES = ("shadow", "live", "off")
DEFAULT_MODE = "shadow"

MAX_PER_PART = 400
MAX_PARTS = 200
MAX_LINES = 300
MAX_FEED = 200000

# The columns of FreshBooks' own "Invoices Export" CSV, in its order. The importer
# (app/customers.py read_invoice_exports) reads them by normalized name.
EXPORT_HEADER = ["Client Name", "Invoice #", "Date Issued", "Date Due", "Invoice Status", "Date Paid",
                 "Item Name", "Item Description", "Rate", "Quantity", "Discount Percentage",
                 "Line Subtotal", "Tax 1 Type", "Tax 1 Amount", "Tax 2 Type", "Tax 2 Amount",
                 "Line Total", "Currency"]

BATCH_ID = re.compile(r"^[a-f0-9]{8,32}$")
INVOICE_NO = re.compile(r"^[A-Za-z0-9-]{1,20}$")
DATE = re.compile(r"^\d{4}-\d{2}-\d{2}$")
STAMP = re.compile(r"^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$")
NUMBER = re.compile(r"^-?\d{1,10}(?:\.\d{1,8})?$")
STATUS = re.compile(r"^[a-z][a-z -]{0,19}$")
DIGITS = re.compile(r"^\d{1,15}$")
CONTROL = re.compile(r"[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]")


class FeedError(Exception):
    pass


# ---------------------------------------------------------------- validation
def _text(v, n, multiline=False):
    if not isinstance(v, str) or len(v) > n:
        raise FeedError("bad text")
    v = CONTROL.sub("", v.replace("\r\n", "\n").replace("\r", "\n"))
    if not multiline:
        v = " ".join(v.split())
    return v.strip()           # inner spacing and line breaks kept: the importer reads them as typed


def _num(v):
    v = str(v).strip() if isinstance(v, (str, int, float)) and not isinstance(v, bool) else None
    if v is None or not NUMBER.match(v):
        raise FeedError("bad number")
    return v


def _date(v, blank_ok=True):
    if v in ("", None) and blank_ok:
        return ""
    if not isinstance(v, str) or not DATE.match(v):
        raise FeedError("bad date")
    return v


def clean_invoice(raw):
    """One invoice from the website, checked field by field. Raises FeedError."""
    if not isinstance(raw, dict):
        raise FeedError("bad invoice")
    number = raw.get("n")
    if not isinstance(number, str) or not INVOICE_NO.match(number):
        raise FeedError("bad invoice number")
    fid = raw.get("id")
    if not isinstance(fid, int) or isinstance(fid, bool) or fid <= 0:
        raise FeedError("bad invoice id")
    vis = raw.get("v")
    if vis not in (0, 1, 2):
        raise FeedError("bad visibility")
    status = raw.get("s")
    if not isinstance(status, str) or not STATUS.match(status):
        raise FeedError("bad status")
    updated = raw.get("u")
    if not isinstance(updated, str) or not STAMP.match(updated):
        raise FeedError("bad updated")
    est = raw.get("e", "")
    if est not in ("", None) and not (isinstance(est, str) and DIGITS.match(est)):
        raise FeedError("bad estimate id")
    lines = raw.get("L")
    if not isinstance(lines, list) or len(lines) > MAX_LINES:
        raise FeedError("bad lines")
    clean_lines = []
    for ln in lines:
        if not isinstance(ln, dict):
            raise FeedError("bad line")
        clean_lines.append({
            "n": _text(ln.get("n", ""), 1000, multiline=True),   # item names can run to two lines
            "d": _text(ln.get("d", ""), 5000, multiline=True),
            "r": _num(ln.get("r", "0")),
            "q": _num(ln.get("q", "0")),
            "t": _num(ln.get("t", "0")),
        })
    return {
        "n": number.lstrip("0") or "0",
        "id": fid,
        "c": _text(raw.get("c", ""), 200),
        "i": _date(raw.get("i"), blank_ok=False),
        "d": _date(raw.get("d", "")),
        "p": _date(raw.get("p", "")),
        "s": status,
        "v": vis,
        "u": updated.replace("T", " "),
        "e": est or "",
        "L": clean_lines,
    }


def clean_part(invoices):
    if not isinstance(invoices, list) or not invoices or len(invoices) > MAX_PER_PART:
        raise FeedError("bad part")
    return [clean_invoice(x) for x in invoices]


# ---------------------------------------------------------------- files
def _dir(store_path):
    return Path(store_path).parent


def _write_json(path, obj):
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = _tmp(path)
    tmp.write_text(json.dumps(obj, sort_keys=True), encoding="utf-8")
    tmp.chmod(0o600)
    tmp.replace(path)


def _tmp(path):
    """A temp name no other writer (the CLI, another request thread) can share, and
    never ending in .csv, so the watcher never reads half a file."""
    return path.with_name(f"{path.name}.{os.getpid()}.{threading.get_ident()}.tmp")


def _read_json(path, default):
    try:
        return json.loads(Path(path).read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return default


def load_feed(store_path):
    raw = _read_json(_dir(store_path) / FEED_FILE, {})
    inv = raw.get("invoices") if isinstance(raw, dict) else None
    ret = raw.get("retired") if isinstance(raw, dict) else None
    first = raw.get("first") if isinstance(raw, dict) else None
    return {"invoices": inv if isinstance(inv, dict) else {},
            "retired": ret if isinstance(ret, dict) else {},
            "first": first if isinstance(first, dict) else {},
            "received_at": raw.get("received_at", "") if isinstance(raw, dict) else "",
            "batches": raw.get("batches", 0) if isinstance(raw, dict) else 0}


def _mode_raw(store_path):
    raw = _read_json(_dir(store_path) / MODE_FILE, {})
    return raw if isinstance(raw, dict) else {}


def get_mode(store_path):
    m = _mode_raw(store_path).get("mode")
    return m if m in MODES else DEFAULT_MODE


def include_archived(store_path):
    """Archived invoices go in the live CSV unless feed-mode recorded that the business's exports leave them
    out."""
    return _mode_raw(store_path).get("archived", "include") != "exclude"


def set_mode(store_path, mode, archived=None):
    if mode not in MODES or archived not in (None, "include", "exclude"):
        raise FeedError("bad mode")
    raw = _mode_raw(store_path)
    raw.update(mode=mode, set_at=time.strftime("%Y-%m-%d %H:%M:%S"))
    if archived:
        raw["archived"] = archived
    _write_json(_dir(store_path) / MODE_FILE, raw)
    return mode


# ---------------------------------------------------------------- staging + merge
def stage(store_path, batch, part, parts, invoices):
    """Parts of one batch are kept aside; returns every invoice of the batch once
    the last part is in, else None. A new batch id discards an unfinished one."""
    if not (isinstance(batch, str) and BATCH_ID.match(batch) and isinstance(part, int)
            and not isinstance(part, bool) and isinstance(parts, int) and not isinstance(parts, bool)
            and 1 <= part <= parts <= MAX_PARTS):
        raise FeedError("bad batch")
    clean = clean_part(invoices)
    path = _dir(store_path) / STAGING_FILE
    st = _read_json(path, None)
    if not isinstance(st, dict) or st.get("batch") != batch or st.get("parts") != parts:
        st = {"batch": batch, "parts": parts, "got": {}}
    st["got"][str(part)] = clean
    if len(st["got"]) < parts:
        _write_json(path, st)
        return None
    out = []
    for i in range(1, parts + 1):
        out += st["got"].get(str(i), [])
    try:
        path.unlink()
    except OSError:
        pass
    return out


def merge(store_path, invoices, received_at):
    """Fold a complete batch into the feed. Keyed by FreshBooks' invoice id; a copy
    with an older `updated` stamp never replaces a newer one."""
    feed = load_feed(store_path)
    have = feed["invoices"]
    retired = feed["retired"]
    first = feed["first"]
    changed = 0
    for inv in invoices:
        key = str(inv["id"])
        old = have.get(key)
        first.setdefault(key, identity(inv))  # what the business's exports most likely hold
        if old is None or str(old.get("u", "")) <= inv["u"]:
            if old != inv:
                changed += 1
            if old is not None and old.get("n") != inv["n"]:
                retired[str(old.get("n"))] = {"t": time.time(), **identity(old)}
            have[key] = inv
    if len(have) > MAX_FEED:
        raise FeedError("feed too large")
    feed.update(invoices=have, retired=retired, first=first, received_at=received_at,
                batches=int(feed.get("batches") or 0) + 1)
    _write_json(_dir(store_path) / FEED_FILE, feed)
    return changed, len(have)


# ---------------------------------------------------------------- the live CSV
def live_by_number(invoices):
    """{number: invoice} for invoices not deleted; when two FreshBooks invoices share a
    number, the one changed last."""
    out = {}
    for inv in invoices.values():
        if inv["v"] == 1:
            continue
        cur = out.get(inv["n"])
        if cur is None or (inv["u"], inv["id"]) > (cur["u"], cur["id"]):
            out[inv["n"]] = inv
    return out


def csv_text(invoices, include_archived=True, skip=()):
    """The feed as FreshBooks' "Invoices Export" CSV: one row per line, deleted invoices left out (and archived
    ones, when the business's exports leave them out, and any number in `skip`). One invoice per number.
    Sorted, so the same feed always writes the same bytes."""
    out = io.StringIO()
    w = csv.writer(out, lineterminator="\n")
    w.writerow(EXPORT_HEADER)
    for inv in sorted(live_by_number(invoices).values(), key=lambda x: (x["i"], x["n"])):
        if (inv["v"] == 2 and not include_archived) or inv["n"] in skip:
            continue
        lines = inv["L"] or [{"n": "", "d": "", "r": "0", "q": "0", "t": "0"}]
        for ln in lines:
            w.writerow([inv["c"], inv["n"], inv["i"], inv["d"], inv["s"], inv["p"],
                        ln["n"], ln["d"], ln["r"], ln["q"], "0", ln["t"], "", "0", "", "0", ln["t"], "USD"])
    return out.getvalue()


def _cents(v):
    try:
        return int(round(float(v) * 100))
    except (TypeError, ValueError):
        return 0


def identity(inv):
    """What says an export record IS this invoice: its issue date and its total."""
    return {"i": inv.get("i", ""), "c": sum(_cents(ln.get("t", 0)) for ln in inv.get("L") or [])}


def tombstones(invoices, retired=None, now=None, first=None):
    """{invoice number: {t: when we learned it, i: issue date, c: total cents}} for
    deleted invoices and the old numbers of renumbered ones. Never a number a live
    invoice holds, and the importer drops only an export record whose date
    and total are this invoice's, so a deleted duplicate can't take a real one with it."""
    now = now if now is not None else time.time()
    live = set(live_by_number(invoices))
    out = {}
    for key, inv in invoices.items():
        if inv["v"] != 1:
            continue
        ids = [identity(inv)]
        f = (first or {}).get(str(key))
        if isinstance(f, dict) and f not in ids:
            ids.append({"i": str(f.get("i", "")), "c": int(f.get("c", 0))})   # edited, then deleted
        out[inv["n"]] = {"t": now, **ids[0], "also": ids[1:]}
    for n, v in (retired or {}).items():
        if isinstance(v, dict):
            out.setdefault(str(n), {"t": float(v.get("t", now)), "i": v.get("i", ""), "c": int(v.get("c", 0)), "also": []})
    return {n: v for n, v in out.items() if n not in live and INVOICE_NO.match(n)}


def _list_path(cfg):
    from .importer import adapter
    mod = adapter(cfg["adapter"])
    return mod, Path(mod.find_customer_list(cfg["import_dir"], cfg.get("customer_list")))


def live_folder(cfg):
    """Beside the customer list: where the importer looks for exports."""
    return _list_path(cfg)[1].parent


def export_numbers(cfg):
    """{invoice number: newest mtime of an export that carries it}. Reads only
    the number column (fast); the live file itself is left out."""
    mod, lst = _list_path(cfg)
    ts = mod._customers_module()
    out = {}
    for p in ts.find_invoice_exports(lst):
        if Path(p).name == LIVE_CSV:
            continue
        mt = Path(p).stat().st_mtime
        try:
            with open(p, newline="", encoding="utf-8-sig", errors="replace") as h:
                r = csv.reader(h)
                head = [ts.normalized_header(x) for x in next(r, [])]
                col = head.index("invoice") if "invoice" in head else head.index("invoice_number")
                for row in r:
                    if len(row) > col and row[col].strip():
                        n = row[col].strip().lstrip("0") or "0"
                        out[n] = max(out.get(n, 0.0), mt)
        except (OSError, ValueError):
            continue
    return out


def superseded(invoices, exports, settle_s=None):
    """The export wins for those (it can't be beaten by a stale feed row)."""
    settle_s = SETTLE_S if settle_s is None else settle_s
    out = set()
    for n, inv in live_by_number(invoices).items():
        mt = exports.get(n)
        u = _stamp_epoch(inv["u"])
        if mt is not None and u is not None and u + settle_s < mt:
            out.add(n)
    return out


def write_live(cfg, store_path, folder=None):
    """Write the live CSV and the tombstones when they changed. Returns True if
    anything was written (the watcher then rebuilds within a minute)."""
    folder = Path(folder) if folder else live_folder(cfg)
    loaded = load_feed(store_path)
    feed = loaded["invoices"]
    text = csv_text(feed, include_archived(store_path), skip=superseded(feed, export_numbers(cfg)))
    target = folder / LIVE_CSV
    wrote = False
    old = target.read_text(encoding="utf-8") if target.exists() else None
    if old != text:
        tmp = _tmp(target)                            # not *.csv: the watcher never sees a half file
        tmp.write_text(text, encoding="utf-8")
        tmp.chmod(0o600)
        tmp.replace(target)
        wrote = True
    tpath = folder / TOMBSTONES
    old_t = _read_json(tpath, {}) if tpath.exists() else {}
    new_t = {}
    for n, v in tombstones(feed, loaded["retired"], first=loaded["first"]).items():
        was = old_t.get(n)
        if isinstance(was, dict) and was.get("i") == v["i"] and was.get("c") == v["c"] and was.get("also") == v["also"]:
            v = {**v, "t": was.get("t", v["t"])}            # keep the first time we knew
        new_t[n] = v
    if new_t != old_t:
        _write_json(tpath, new_t)
        wrote = True
    return wrote


def remove_live(folder):
    """feed-mode shadow/off: take the feed's rows out (the files are the hub's own)."""
    gone = []
    for name in (LIVE_CSV, TOMBSTONES):
        p = Path(folder) / name
        if p.exists():
            p.unlink()
            gone.append(name)
    return gone


def read_tombstones(folder):
    raw = _read_json(Path(folder) / TOMBSTONES, {})
    if not isinstance(raw, dict):
        return {}
    out = {}
    for k, v in raw.items():
        if not (isinstance(k, str) and INVOICE_NO.match(k)) or not isinstance(v, dict):
            continue
        try:
            ids = [(str(v.get("i", "")), int(v.get("c", 0)))]
            ids += [(str(a.get("i", "")), int(a.get("c", 0))) for a in v.get("also") or [] if isinstance(a, dict)]
            out[k.lstrip("0") or "0"] = {"t": float(v["t"]), "ids": ids}
        except (KeyError, TypeError, ValueError):
            continue
    return out


def drop_tombstoned(records, folder):
    """The importer's side: an export record of an invoice the feed says was deleted
    is dropped, unless its file is newer than the deletion (a later export wins)."""
    tomb = read_tombstones(folder)
    if not tomb:
        return records
    keep = []
    for r in records:
        t = tomb.get(str(r.get("number", "")).lstrip("0") or "0")
        if t is not None and r.get("kind", "invoice") == "invoice" and float(r.get("modified", 0)) <= t["t"] \
                and r.get("file") != LIVE_CSV \
                and (str(r.get("issued", "")), int(r.get("total_cents") or 0)) in t["ids"]:
            continue
        keep.append(r)
    return keep


# ---------------------------------------------------------------- heartbeat
HEARTBEAT_FILE = "feed-heartbeat.json"
_UTC_STAMP = re.compile(r"^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$")


def beat(store_path, raw, now=None):
    """The website's hourly feed job checked in, with or without invoices to send."""
    if not isinstance(raw, dict):
        raise FeedError("bad heartbeat")
    ok = raw.get("ledger_ok") or ""
    if not isinstance(ok, str) or (ok and not _UTC_STAMP.match(ok)):
        raise FeedError("bad heartbeat")
    try:
        queued = max(0, min(10 ** 6, int(raw.get("queued") or 0)))
    except (TypeError, ValueError):
        raise FeedError("bad heartbeat") from None
    now = now or time.time()
    rec = {"received_ts": int(now), "received": time.strftime("%Y-%m-%dT%H:%M:%S", time.localtime(now)),
           "ledger_ok_utc": ok, "ledger_failing": raw.get("ledger_failing") is True, "queued": queued}
    _write_json(_dir(store_path) / HEARTBEAT_FILE, rec)
    return rec


def heartbeat(store_path):
    raw = _read_json(_dir(store_path) / HEARTBEAT_FILE, None)
    return raw if isinstance(raw, dict) and isinstance(raw.get("received_ts"), int) else None


# ---------------------------------------------------------------- status + parity
def status(store_path):
    feed = load_feed(store_path)
    inv = feed["invoices"].values()
    newest = max((x["u"] for x in inv), default="")
    return {"mode": get_mode(store_path), "invoices": len(feed["invoices"]),
            "deleted": sum(1 for x in inv if x["v"] == 1),
            "with_estimate_link": sum(1 for x in inv if x.get("e")),
            "newest_change": newest, "received_at": feed["received_at"], "batches": feed["batches"]}


def fingerprint(store_path):
    return hashlib.sha256(json.dumps(load_feed(store_path)["invoices"], sort_keys=True).encode()).hexdigest()[:12]


# ---------------------------------------------------------------- feed-check
SETTLE_S = 86400       # FreshBooks' `updated` stamp has no time zone: a day's margin


def _stamp_epoch(u):
    try:
        return time.mktime(time.strptime(u, "%Y-%m-%d %H:%M:%S"))
    except (TypeError, ValueError):
        return None


def _norm_no(n):
    return str(n).strip().lstrip("0") or "0"


def _cmp_view(rec):
    """What the importer takes from one invoice: the fields compared."""
    items = [(i["label"], tuple(i["detail"]), int(i["rate"]), round(float(i["qty"]), 6), int(i["total"]))
             for i in rec["items"]]
    return {
        "issued": rec["issued"], "paid_date": rec["paid_date"], "status": rec["status"],
        "name": " ".join(str(rec["name"]).casefold().split()),
        "lines": len(items), "items": items, "total": rec["total_cents"],
    }


def parity(cfg, store_path, settle_s=SETTLE_S, examples=8):
    """Compare the feed with the business's own exports, exactly as the importer reads both. Returns a report
    dict (counts and invoice numbers only, no names)."""
    import tempfile
    from .importer import adapter
    mod = adapter(cfg["adapter"])
    ts = mod._customers_module()
    path = mod.find_customer_list(cfg["import_dir"], cfg.get("customer_list"))
    exports = [p for p in ts.find_invoice_exports(path) if Path(p).name != LIVE_CSV]
    ex_records, _ = ts.read_invoice_exports(exports)
    ex, raw_padded = {}, 0
    for r in sorted(ex_records, key=lambda r: r.get("modified", 0)):
        if str(r["number"]).startswith("0"):
            raw_padded += 1
        ex[_norm_no(r["number"])] = r
    feed = load_feed(store_path)["invoices"]
    with tempfile.TemporaryDirectory() as d:
        tmp = Path(d) / "feed.csv"
        tmp.write_text(csv_text(feed), encoding="utf-8")
        fr_records, _ = ts.read_invoice_exports([tmp])
    fr = {_norm_no(r["number"]): r for r in fr_records}
    live = live_by_number(feed)
    counts = {}
    for inv in feed.values():
        if inv["v"] != 1:
            counts[inv["n"]] = counts.get(inv["n"], 0) + 1
    by_no = dict(live)
    for inv in feed.values():                           # deleted ones, unless a live invoice holds the number
        if inv["v"] == 1 and inv["n"] not in live:
            by_no.setdefault(inv["n"], inv)
    export_months = {str(r.get("issued", ""))[:7] for r in ex.values()}
    rep = {"feed": len(feed), "deleted": sum(1 for x in feed.values() if x["v"] == 1),
           "with_estimate_link": sum(1 for x in feed.values() if x.get("e")),
           "export_files": len(exports), "export_invoices": len(ex), "padded_numbers_in_exports": raw_padded,
           "in_both": 0, "settled": 0, "settled_same": 0, "changed_since_export": 0, "feed_only": 0,
           "deleted_but_in_exports": 0, "archived_in_both": 0, "archived_feed_only": 0,
           "shared_numbers": sum(1 for c in counts.values() if c > 1),
           "renumbered": len(load_feed(store_path)["retired"]),
           "mismatch": {}, "changed_fields": {}}
    for no, inv in by_no.items():
        if inv["v"] == 1:
            if no in ex:
                rep["deleted_but_in_exports"] += 1
            continue
        if inv["v"] == 2:
            if no in ex:
                rep["archived_in_both"] += 1
            elif inv["i"][:7] in export_months and _stamp_epoch(inv["u"]) is not None:
                rep["archived_feed_only"] += 1        # a month your exports cover, yet not in them
        if no not in ex:
            rep["feed_only"] += 1
            continue
        rep["in_both"] += 1
        a, b = _cmp_view(fr[no]), _cmp_view(ex[no])
        diff = [k for k in a if a[k] != b[k]]
        upd = _stamp_epoch(inv["u"])
        settled = upd is not None and upd + settle_s < float(ex[no].get("modified", 0))
        if settled:
            rep["settled"] += 1
            if not diff:
                rep["settled_same"] += 1
            for k in diff:
                rep["mismatch"].setdefault(k, []).append(no)
        else:
            rep["changed_since_export"] += 1
            for k in diff:
                rep["changed_fields"][k] = rep["changed_fields"].get(k, 0) + 1
    bad = sum(len(v) for v in rep["mismatch"].values())
    rep["mismatch"] = {k: {"count": len(v), "examples": sorted(v)[:examples]} for k, v in rep["mismatch"].items()}
    reasons = []
    if rep["padded_numbers_in_exports"]:
        reasons.append("the exports write invoice numbers with leading zeros")
    if rep["settled"] < 10:
        reasons.append(f"only {rep['settled']} invoice(s) can be compared yet (need 10)")
    if bad:
        reasons.append(f"{rep['settled'] - rep['settled_same']} unchanged invoice(s) read differently")
    rep["ready"] = not reasons
    rep["why_not"] = reasons
    # Do the business's exports carry archived invoices? Evidence only from the feed's own
    # archived invoices: seen in an export → include; never seen (and some exist) → exclude.
    rep["archived"] = "exclude" if rep["archived_feed_only"] and not rep["archived_in_both"] else "include"
    rep["archived_evidence"] = bool(rep["archived_feed_only"] or rep["archived_in_both"])
    return rep


def format_parity(rep):
    out = ["Live feed vs your FreshBooks exports (as the importer reads both)", ""]
    out.append(f"  feed: {rep['feed']} invoices ({rep['deleted']} deleted in FreshBooks, "
               f"{rep['with_estimate_link']} linked to an estimate)")
    out.append(f"  exports: {rep['export_files']} file(s), {rep['export_invoices']} invoices")
    out.append(f"  in both: {rep['in_both']}  ·  newer than every export (new to the hub): {rep['feed_only']}")
    out.append(f"  unchanged since the export, so they must match: {rep['settled']}  ->  "
               f"identical: {rep['settled_same']}")
    for k, v in sorted(rep["mismatch"].items()):
        out.append(f"     ✗ {k}: {v['count']} differ, e.g. #{', #'.join(v['examples'])}")
    out.append(f"  changed in FreshBooks after the export (expected to differ): {rep['changed_since_export']}"
               + (f"  [{', '.join(f'{k} {n}' for k, n in sorted(rep['changed_fields'].items()))}]"
                  if rep["changed_fields"] else ""))
    if rep["archived_in_both"] or rep["archived_feed_only"]:
        out.append(f"  archived in FreshBooks: {rep['archived_in_both']} also in an export, "
                   f"{rep['archived_feed_only']} only in the feed -> live mode will "
                   f"{'leave archived invoices out, like your exports' if rep['archived'] == 'exclude' else 'include them'}")
    if rep.get("shared_numbers"):
        out.append(f"  invoice numbers FreshBooks uses twice: {rep['shared_numbers']} (the live file keeps the one "
                   "changed last; a deleted twin never removes it)")
    if rep.get("renumbered"):
        out.append(f"  renumbered in FreshBooks: {rep['renumbered']} (the old numbers are left out of every export)")
    if rep["deleted_but_in_exports"]:
        out.append(f"  deleted in FreshBooks but still in an export: {rep['deleted_but_in_exports']} "
                   "(live mode leaves these out)")
    out.append("")
    out.append("  ✓ READY: `python -m zorderz_hub feed-mode live` turns it on." if rep["ready"]
               else "  ✗ NOT READY: " + "; ".join(rep["why_not"]) + ".")
    return "\n".join(out)
