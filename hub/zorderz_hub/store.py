"""The hub's table store: one SQLite file of de-identified tables.

Contract: the adapter hands over clean tables only. store.save()
refuses any table or column whose name suggests an identity,
and the store is derived and disposable: delete it and re-import.
"""
import contextlib
import json
import os
import re
import sqlite3
import time
import uuid
from dataclasses import dataclass, field
from pathlib import Path

import pandas as pd

REQUIRED = {
    "lines_clean": ["line_id", "invoice_no", "customer_key", "issued", "status",
                    "description_clean", "family", "action", "families_mentioned",
                    "tags", "inferred", "work", "qty", "units", "est_units", "est_basis",
                    "line_cents", "salesperson", "area"],
    "invoices_clean": ["invoice_no", "customer_key", "issued", "status",
                       "lead_source", "salesperson", "area"],
    "customers_clean": ["customer_key", "is_business", "jobs"],
    "lot_components_clean": ["line_id", "family", "est_units", "est_cents"],
}
FORBIDDEN_COLUMN = re.compile(
    r"(?i)^(?:name|first_name|last_name|customer|organization|email|phone|"
    r"address(?:_line_\d)?|street|notes?|description|raw_invoice|site|"
    r"customer_id|gate_code|lockbox)$")
DATE_COLUMNS = {"issued", "paid", "date", "first_job", "last_job"}


class StoreError(Exception):
    pass


# ---------------------------------------------------------------- measured counts
# How many of a product a job really had, measured on the job (e.g. the count
# in the job's measurement note), sent by the website as numbers only:
# {product: {invoice_no: count}}. Kept beside the store, not in it, because an
# import rebuilds the store and must not wipe what the website sent.
MEASURED_FILE = "measured-counts.json"
INVOICE_NO = re.compile(r"^[A-Za-z0-9-]{1,20}$")
MAX_MEASURED = 20000
MAX_COUNT = 999


def measured_path(store_path):
    return Path(store_path).with_name(MEASURED_FILE)


def clean_counts(counts):
    """Only invoice numbers and whole positive counts get through."""
    if not isinstance(counts, dict) or len(counts) > MAX_MEASURED:
        raise StoreError("bad counts")
    out = {}
    for k, v in counts.items():
        if not isinstance(k, str) or not INVOICE_NO.match(k):
            raise StoreError("bad invoice number")
        if isinstance(v, bool) or not isinstance(v, int) or not 0 < v <= MAX_COUNT:
            raise StoreError("bad count")
        k = k.lstrip("0") or "0"          # "0015558" is invoice 15558
        out[k] = max(v, out.get(k, 0))
    return out


# ---------------------------------------------------------------- lead sources
# Where each invoice's customer came from, as the website's own ledger reads it
# from FreshBooks. The monthly FreshBooks export carries neither field, so
# without this most invoices read "(not recorded)". Labels only ({invoice_no:
# "Google Ads"}), cleaned here: no digits runs, emails, or anything long enough
# to carry a sentence.
SOURCES_FILE = "lead-sources.json"
SOURCE_LABEL = re.compile(r"^[A-Za-z][A-Za-z0-9 &'./-]{0,39}$")
MAX_SOURCES = 50000


def sources_path(store_path):
    return Path(store_path).with_name(SOURCES_FILE)


def clean_sources(sources):
    if not isinstance(sources, dict) or len(sources) > MAX_SOURCES:
        raise StoreError("bad sources")
    out = {}
    for k, v in sources.items():
        if not isinstance(k, str) or not INVOICE_NO.match(k) or not isinstance(v, str):
            raise StoreError("bad source row")
        v = " ".join(v.split())
        if not v:
            continue
        if not SOURCE_LABEL.match(v) or re.search(r"\d{3,}|@", v):
            v = "Other"                       # never pass free text through
        out[k.lstrip("0") or "0"] = v
    return out


def load_sources(store_path):
    try:
        raw = json.loads(sources_path(store_path).read_text(encoding="utf-8"))
        return group_sources(clean_sources(raw.get("sources") or {}))
    except (OSError, ValueError, StoreError, AttributeError):
        return {}


def group_sources(sources):
    """Raw labels → the pack's groups; notes and blanks dropped. A listed
    partner business keeps its listed name; nothing else typed is kept."""
    from .lead_sources import group_of, partner_of
    out = {}
    for k, v in sources.items():
        g = partner_of(v) or group_of(v)
        if g:
            out[k] = g
    return out


def save_sources(store_path, sources, received_at):
    sources = group_sources(clean_sources(sources))
    path = sources_path(store_path)
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(".tmp")
    tmp.write_text(json.dumps({"sources": sources, "received_at": received_at}, sort_keys=True), encoding="utf-8")
    tmp.chmod(0o600)
    tmp.replace(path)
    return sources


BATCH_ID = re.compile(r"^[a-f0-9]{8,32}$")
MAX_PARTS = 200                      # lead-name parts come after the source parts


def stage_sources(store_path, batch, part, parts, sources, received_at, lead_names=None):
    """A large set arrives in parts (the hub takes at most 64 KB per request).
    Parts of one batch are kept aside; the set replaces the old one only when
    every part has arrived. Returns the saved set, or None while parts are missing.
    a part may also carry `lead_names` (invoice → its Nutshell lead's source
    name, from the website); when any part of the batch did, that set is replaced too."""
    if not (isinstance(batch, str) and BATCH_ID.match(batch) and isinstance(part, int)
            and isinstance(parts, int) and 1 <= part <= parts <= MAX_PARTS):
        raise StoreError("bad batch")
    clean = clean_sources(sources)
    leads = clean_lead_names(lead_names) if lead_names is not None else None
    path = sources_path(store_path).with_name("lead-sources.staging.json")
    try:
        st = json.loads(path.read_text(encoding="utf-8"))
        if st.get("batch") != batch or st.get("parts") != parts:
            st = None
    except (OSError, ValueError):
        st = None
    st = st or {"batch": batch, "parts": parts, "got": {}}
    st["got"][str(part)] = clean
    if leads is not None:
        st.setdefault("leads", {})[str(part)] = leads
    if len(st["got"]) < parts:
        tmp = path.with_suffix(".tmp")
        tmp.write_text(json.dumps(st), encoding="utf-8")
        tmp.chmod(0o600)
        tmp.replace(path)
        return None
    merged = {}
    for i in range(1, parts + 1):
        merged.update(st["got"].get(str(i), {}))
    if st.get("leads"):
        all_leads = {}
        for i in range(1, parts + 1):
            all_leads.update(st["leads"].get(str(i), {}))
        save_web_lead_links(store_path, all_leads, received_at, cleaned=True)
    try:
        path.unlink()
    except OSError:
        pass
    return save_sources(store_path, merged, received_at)


# the website's own estimate link. The website sends {invoice: source name}; like
# the source names above, only the GROUP is kept.
WEB_LEAD_LINKS_FILE = "lead-links-web.json"


def web_lead_links_path(store_path):
    return Path(store_path).with_name(WEB_LEAD_LINKS_FILE)


def clean_lead_names(names):
    from .lead_sources import group_of, partner_of
    if not isinstance(names, dict) or len(names) > MAX_SOURCES:
        raise StoreError("bad lead names")
    out = {}
    for k, v in names.items():
        if not isinstance(k, str) or not INVOICE_NO.match(k) or not isinstance(v, str) or len(v) > 200:
            raise StoreError("bad lead name row")
        v = " ".join(v.split())[:80]
        if not v or re.search(r"\d{3,}|@", v):
            continue
        g = partner_of(v) or group_of(v)
        if g:
            out[k.lstrip("0") or "0"] = g
    return out


def save_web_lead_links(store_path, links, received_at, cleaned=False):
    groups = links if cleaned else clean_lead_names(links)
    path = web_lead_links_path(store_path)
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(".tmp")
    tmp.write_text(json.dumps({"groups": groups, "received_at": received_at}, sort_keys=True), encoding="utf-8")
    tmp.chmod(0o600)
    tmp.replace(path)
    return groups


def load_web_lead_links(store_path):
    try:
        raw = json.loads(web_lead_links_path(store_path).read_text(encoding="utf-8"))
        groups = raw.get("groups") or {}
        return {str(k): str(v) for k, v in groups.items() if INVOICE_NO.match(str(k)) and isinstance(v, str)}
    except (OSError, ValueError, AttributeError):
        return {}


def apply_web_lead_links(invoices, links):
    """A job with no source of its own takes its Nutshell lead's source, as the
    website linked it through the estimate (before the contact-matched export link)."""
    if not links or not len(invoices) or "lead_source" not in invoices:
        return invoices
    have = invoices["lead_source"].fillna("").astype(str).str.strip()
    fill = invoices["invoice_no"].map(lambda n: links.get(str(n).lstrip("0") or "0", "")).fillna("")
    invoices = invoices.copy()
    invoices["lead_source"] = have.where(have.ne(""), fill)
    return invoices


def apply_sources(invoices, sources):
    """Fill an invoice's lead source from the website's ledger where the store has
    none (the PDF Notes box, when there was a PDF, stays)."""
    if not sources or not len(invoices) or "lead_source" not in invoices:
        return invoices
    have = invoices["lead_source"].fillna("").astype(str).str.strip()
    fill = invoices["invoice_no"].map(sources)
    invoices = invoices.copy()
    invoices["lead_source"] = have.where(have.ne(""), fill.fillna(""))
    return invoices


# Nutshell source numbers → groups, as the website names them.
SOURCE_NAMES_FILE = "nutshell-source-groups.json"
SOURCE_NO = re.compile(r"^\d{1,10}$")


def source_names_path(store_path):
    return Path(store_path).with_name(SOURCE_NAMES_FILE)


def clean_source_names(names):
    """{source number: name} from the website → {source number: group}. The
    names themselves are never kept, only the group each falls in."""
    from .lead_sources import group_of, partner_of
    if not isinstance(names, dict) or len(names) > 2000:
        raise StoreError("bad source names")
    out = {}
    for k, v in names.items():
        k = str(k).strip()
        if not SOURCE_NO.match(k) or not isinstance(v, str):
            raise StoreError("bad source name row")
        v = " ".join(v.split())[:80]
        if not v or re.search(r"\d{3,}|@", v):
            continue
        g = partner_of(v) or group_of(v)
        if g:
            out[k] = g
    return out


def save_source_names(store_path, names, received_at):
    groups = clean_source_names(names)
    path = source_names_path(store_path)
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(".tmp")
    tmp.write_text(json.dumps({"groups": groups, "received_at": received_at}, sort_keys=True), encoding="utf-8")
    tmp.chmod(0o600)
    tmp.replace(path)
    return groups


def load_source_names(store_path):
    try:
        raw = json.loads(source_names_path(store_path).read_text(encoding="utf-8"))
        groups = raw.get("groups") or {}
        return {str(k): str(v) for k, v in groups.items() if SOURCE_NO.match(str(k)) and isinstance(v, str)}
    except (OSError, ValueError, AttributeError):
        return {}


def apply_lead_links(invoices, links, names):
    """A job with no source on its invoice (or from the ledger) takes its
    Nutshell lead's source, when the website has told us that source's name."""
    if not names or links is None or not len(links) or not len(invoices) or "lead_source" not in invoices:
        return invoices
    by_inv = {str(r.invoice_no).lstrip("0") or "0": names.get(str(r.nutshell_source), "")
              for r in links.itertuples()}
    have = invoices["lead_source"].fillna("").astype(str).str.strip()
    fill = invoices["invoice_no"].map(lambda n: by_inv.get(str(n).lstrip("0") or "0", "")).fillna("")
    invoices = invoices.copy()
    invoices["lead_source"] = have.where(have.ne(""), fill)
    return invoices


def group_and_infer(invoices, old_rule=False):
    """every source into its group, then a job with no
    source whose household had an EARLIER job with us reads "Existing Customer -
    from history" (its own group, so it's never mistaken for a tagged one).
    Everything else with no source stays blank: unknown."""
    from .lead_sources import FROM_HISTORY, NEW_UNKNOWN, group_series, partner_of
    if not len(invoices) or "lead_source" not in invoices:
        return invoices
    invoices = invoices.copy()
    # the named partner business behind a job, from the business's list only.
    raw = invoices["lead_source"].fillna("").astype(str)
    cache = {v: partner_of(v, old_rule=old_rule) for v in raw.unique()}
    invoices["partner"] = raw.map(cache)
    invoices["lead_source"] = group_series(invoices["lead_source"], old_rule=old_rule)  # old_rule: sources-diff only
    who = None
    for c in ("household_key", "customer_key"):
        if c in invoices and invoices[c].notna().any():
            who = invoices[c].where(invoices[c].notna() & invoices[c].astype(str).ne(""),
                                    invoices.get("customer_key"))
            break
    if who is None or "issued" not in invoices:
        return invoices
    when = pd.to_datetime(invoices["issued"], errors="coerce")
    first = when.groupby(who).transform("min")
    earlier = when.notna() & first.notna() & (when > first)
    blank = invoices["lead_source"].eq("")
    invoices.loc[blank & earlier, "lead_source"] = FROM_HISTORY
    # what's left is a household's FIRST job with no source.
    first = when.notna() & first.notna() & (when == first)
    invoices.loc[blank & first, "lead_source"] = NEW_UNKNOWN
    # A channel that brought a new customer keeps the credit for that
    # customer's later jobs (they count as that channel's repeat customer).
    # Each household's ORIGINAL source: its first job's source when that
    # says where they came from; else the earliest job that does. Every job of the
    # household carries it. Blank when no job ever says.
    invoices["original_source"] = original_sources(invoices["lead_source"], who, when)
    # and the partner behind that first source, carried the same way
    invoices["original_partner"] = original_sources(invoices["lead_source"], who, when,
                                                    invoices["partner"])
    return invoices


def original_sources(source, who, when, carry=None):
    """Per invoice: how its household first found us (see group_and_infer). With
    carry, the value of that column on the job that decided it instead."""
    from .lead_sources import EXISTING, FROM_HISTORY, NEW_UNKNOWN, OTHER
    not_a_channel = {"", EXISTING, FROM_HISTORY, NEW_UNKNOWN, OTHER}
    frame = pd.DataFrame({"src": source.fillna("").astype(str), "who": who, "when": when,
                          "out": (carry if carry is not None else source).fillna("").astype(str)})
    frame = frame[frame["who"].notna() & frame["when"].notna() & ~frame["src"].isin(not_a_channel)]
    first = frame.sort_values("when", kind="stable").drop_duplicates("who")
    by_who = dict(zip(first["who"], first["out"]))
    return who.map(by_who).fillna("")


def load_measured(store_path):
    path = measured_path(store_path)
    try:
        raw = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return {}
    out = {}
    for product, entry in (raw.get("products") or {}).items():
        try:
            out[str(product)] = clean_counts(entry.get("counts") or {})
        except (StoreError, AttributeError):
            continue          # a damaged entry is dropped, never half-used
    return out


def save_measured(store_path, product, counts, received_at):
    """Replace one product's counts (the website always sends the whole set)."""
    counts = clean_counts(counts)
    path = measured_path(store_path)
    path.parent.mkdir(parents=True, exist_ok=True)
    try:
        raw = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        raw = {}
    products = raw.get("products") if isinstance(raw.get("products"), dict) else {}
    products[product] = {"counts": counts, "received_at": received_at}
    tmp = path.with_suffix(".tmp")
    tmp.write_text(json.dumps({"products": products}, sort_keys=True), encoding="utf-8")
    tmp.chmod(0o600)
    tmp.replace(path)
    return counts


def check_tables(tables):
    for name, cols in REQUIRED.items():
        frame = tables.get(name)
        if frame is None:
            raise StoreError(f"adapter did not produce {name}")
        missing = [c for c in cols if c not in frame.columns]
        if missing and len(frame):
            raise StoreError(f"{name} is missing columns {missing}")
    for name, frame in tables.items():
        bad = [c for c in frame.columns if FORBIDDEN_COLUMN.match(str(c))]
        if bad:
            raise StoreError(f"{name} has identity-looking columns {bad}; refusing to store")


@contextlib.contextmanager
def build_lock(path):
    """an exclusive lock beside the store while a rebuild reads the exports and saves, so two rebuilds
    (the watcher's and `zorderz_hub import`, or a rebuild left over from a restart) never write at once."""
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    fd = os.open(path.with_name(path.name + ".lock"), os.O_RDWR | os.O_CREAT, 0o600)
    try:
        try:
            import fcntl
            fcntl.flock(fd, fcntl.LOCK_EX)
        except ImportError:                   # not on macOS or Linux: no lock, as before
            pass
        yield
    finally:
        os.close(fd)                          # closing releases the lock


def _old_temps(path):
    """Temp stores left by a rebuild that was stopped mid-save (older than an hour)."""
    cutoff = time.time() - 3600
    for f in path.parent.glob(f".{path.stem}.*.tmp"):
        try:
            if f.stat().st_mtime < cutoff:
                f.unlink()
        except OSError:
            pass


class KeyChanged(StoreError):
    """the analytics key the import would use is not the one the store was built with."""


def read_meta(path):
    """the store's meta table, or {} when there is no store (read-only; never the data)."""
    path = Path(path)
    if not path.exists():
        return {}
    con = sqlite3.connect(f"file:{path}?mode=ro", uri=True)
    try:
        return {k: json.loads(v) for k, v in con.execute("SELECT k, v FROM meta")}
    except sqlite3.Error:
        return {}
    finally:
        con.close()


def household_keys(path):
    """Empty when there is no store or no such column."""
    path = Path(path)
    if not path.exists():
        return set()
    con = sqlite3.connect(f"file:{path}?mode=ro", uri=True)
    try:
        return {str(r[0]) for r in con.execute('SELECT household_key FROM "customers_clean"') if r[0] is not None}
    except sqlite3.Error:
        return set()
    finally:
        con.close()


def save(path, tables, meta):
    check_tables(tables)
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.parent.chmod(0o700)
    _old_temps(path)
    # a temp name of its own, so a second save can't write into (or delete) the first one's file
    tmp = path.with_name(f".{path.stem}.{os.getpid()}.{uuid.uuid4().hex[:8]}.tmp")
    try:
        con = sqlite3.connect(tmp)
        try:
            for name, frame in tables.items():
                if not len(frame.columns):
                    continue
                out = frame.copy()
                for c in out.columns:
                    if pd.api.types.is_datetime64_any_dtype(out[c]):
                        out[c] = out[c].dt.strftime("%Y-%m-%d")
                out.to_sql(name, con, index=False)
            meta = {**meta, "built_at": time.strftime("%Y-%m-%dT%H:%M:%S"),
                    "row_counts": {k: int(len(v)) for k, v in tables.items()}}
            con.execute("CREATE TABLE meta (k TEXT PRIMARY KEY, v TEXT)")
            con.executemany("INSERT INTO meta VALUES (?, ?)",
                            [(k, json.dumps(v)) for k, v in meta.items()])
            con.commit()
        finally:
            con.close()
    except BaseException:
        try:
            tmp.unlink()
        except OSError:
            pass
        raise
    tmp.chmod(0o600)
    tmp.replace(path)          # atomic swap: readers never see half a store
    return meta


@dataclass
class Data:
    lines: pd.DataFrame
    invoices: pd.DataFrame
    customers: pd.DataFrame
    lot_parts: pd.DataFrame
    unplaced: pd.DataFrame
    import_log: pd.DataFrame
    failures: pd.DataFrame
    meta: dict
    measured: dict = field(default_factory=dict)
    # estimates (number, customer key, date, total, products, rep, town) for the
    # invoice card's estimate view. Empty when the import had none.
    estimates: pd.DataFrame = field(default_factory=pd.DataFrame)
    # per prospecting patch, counts only (cell, customers, jobs, families), from work lines that aren't void.
    prospect_cells: pd.DataFrame = field(default_factory=pd.DataFrame)

    @property
    def work(self):
        return self._work

    def __post_init__(self):
        self._work = self.lines[self.lines["work"]].copy()


def _read(con, name):
    try:
        frame = pd.read_sql(f'SELECT * FROM "{name}"', con)
    except Exception:
        return pd.DataFrame()
    for c in frame.columns:
        if c in DATE_COLUMNS:
            frame[c] = pd.to_datetime(frame[c], errors="coerce")
    return frame


def _labelled_invoices(con, path):
    """invoices_clean with each job's source label as typed (scrubbed), before grouping."""
    invoices = _read(con, "invoices_clean")
    invoices["invoice_no"] = invoices["invoice_no"].astype(str)
    invoices = apply_sources(invoices, load_sources(path))
    invoices = apply_web_lead_links(invoices, load_web_lead_links(path))       
    return apply_lead_links(invoices, _read(con, "lead_links_clean"), load_source_names(path))


def labelled_invoices(path):
    """the invoices with their lead-source labels, as the store reads them."""
    path = Path(path)
    if not path.exists():
        raise StoreError("no store yet: run an import")
    con = sqlite3.connect(f"file:{path}?mode=ro", uri=True)
    try:
        return _labelled_invoices(con, path)
    finally:
        con.close()


def load(path):
    path = Path(path)
    if not path.exists():
        raise StoreError("no store yet: run an import")
    con = sqlite3.connect(f"file:{path}?mode=ro", uri=True)
    try:
        meta = {k: json.loads(v) for k, v in con.execute("SELECT k, v FROM meta")}
        lines = _read(con, "lines_clean")
        lines["work"] = lines["work"].fillna(0).astype(bool)
        for c in ["line_cents", "rate_cents"]:
            if c in lines:
                lines[c] = pd.array(lines[c], dtype="Int64")
        for c in ["invoice_no", "line_id", "customer_key"]:
            lines[c] = lines[c].astype(str)
        for c in ["qty", "units", "est_units"]:              # an all-blank column reads back as text
            if c in lines:
                lines[c] = pd.to_numeric(lines[c], errors="coerce").astype(float)
        for c in ["tags", "inferred", "families_mentioned", "est_basis", "description_clean"]:
            lines[c] = lines[c].fillna("")
        invoices = group_and_infer(_labelled_invoices(con, path))
        customers = _read(con, "customers_clean")
        customers["is_business"] = customers["is_business"].fillna(0).astype(bool)
        if "account_key" not in customers:
            customers["account_key"] = None
        parts = _read(con, "lot_components_clean")
        if len(parts):
            parts["line_id"] = parts["line_id"].astype(str)
        measured = {}
        m = _read(con, "measured_clean")
        for r in m.itertuples() if len(m) else []:
            try:
                k = str(r.invoice_no).lstrip("0") or "0"
                u = int(r.units)
            except (TypeError, ValueError):
                continue
            if 0 < u <= MAX_COUNT and INVOICE_NO.match(k):
                measured.setdefault(str(r.product), {})[k] = u
        for product, counts in load_measured(path).items():     # the website's, if any, win
            measured.setdefault(product, {}).update(counts)
        estimates = _read(con, "estimates_clean")
        if len(estimates) and "estimate_no" in estimates:
            estimates["estimate_no"] = estimates["estimate_no"].astype(str).str.replace(r"\.0$", "", regex=True)
            estimates["customer_key"] = estimates.get("customer_key", pd.Series(dtype=str)).astype(str)
        else:
            estimates = pd.DataFrame()
        return Data(lines=lines, invoices=invoices, customers=customers, lot_parts=parts,
                    unplaced=_read(con, "unplaced_clean"),
                    import_log=_read(con, "import_log_clean"),
                    failures=_read(con, "import_failures_clean"), meta=meta,
                    measured=measured, estimates=estimates,
                    prospect_cells=_read(con, "prospect_cells_clean"))
    finally:
        con.close()
