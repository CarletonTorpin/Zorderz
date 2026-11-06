"""A source adapter for a billing system's export of invoice lines (CSV).

Any billing system that can export one row per invoice line works. Drop the CSV files into the import folder;
column names are matched loosely (case, spaces and punctuation don't matter), and only these are read:

    invoice_no   Invoice Number | Invoice # | Invoice
    issued       Date Issued | Invoice Date | Date
    status       Status | Invoice Status                (optional; "paid", "sent", "draft", "void" ...)
    customer     Client Name | Customer | Customer Name  (who the invoice is for)
    business     Organization | Company                  (optional; filled = a business customer)
    email        Email | Client Email                    (optional; matching only, never stored)
    phone        Phone | Client Phone                    (optional; matching only, never stored)
    street       Street | Address | Address Line 1       (optional; placing only, never stored)
    town         Town | City                             (optional)
    zip          Zip | Postal Code | Zip Code            (optional)
    salesperson  Salesperson | Sales Rep | Rep           (optional)
    lead_source  Lead Source | Source                    (optional)
    description  Description | Item | Line Description   (the line's text)
    qty          Qty | Quantity                          (optional)
    rate         Rate | Unit Cost | Price                (optional)
    amount       Amount | Line Total | Total             (the line's amount, in dollars)

Each line is classified with the identity pack's own words (catalog.yml `charts:`):

    products[].words      phrases that make a line that product ("first match wins", longest phrase first)
    products[].units      true: the line's quantity is a count of units
    work_types[].words    phrases that make a line that work type; the work type with `default: true` (else the
                          first) when none matches
    tag_groups[].tags[].words   phrases that add the tag
    not_work_words        phrases that make a line not work (a deposit, a discount, a payment)

What is stored: code names (an HMAC of who the customer is, under the analytics key), dates, amounts, the pack's
own words for each line, the town, the ZIP and the place facts the region pack gives. Never a name, an e-mail, a
phone number or a street: the store refuses identity-looking columns anyway (store.py).
"""
import csv
import hashlib
import hmac
import io
import os
import re
import secrets
from pathlib import Path

import pandas as pd
import yaml

from .. import names

ADAPTER_NAME = "billing_csv"
COLUMNS = {
    "invoice_no": ["invoicenumber", "invoiceno", "invoice", "invoicenum"],
    "issued": ["dateissued", "invoicedate", "issued", "date", "issuedate"],
    "status": ["status", "invoicestatus"],
    "customer": ["clientname", "customer", "customername", "client"],
    "business": ["organization", "company", "business", "organisation"],
    "email": ["email", "clientemail", "customeremail"],
    "phone": ["phone", "clientphone", "customerphone", "phonenumber"],
    "street": ["street", "address", "addressline1", "streetaddress", "address1"],
    "town": ["town", "city"],
    "zip": ["zip", "zipcode", "postalcode", "postcode"],
    "salesperson": ["salesperson", "salesrep", "rep"],
    "lead_source": ["leadsource", "source", "referralsource"],
    "description": ["description", "item", "linedescription", "itemname", "lineitem"],
    "qty": ["qty", "quantity"],
    "rate": ["rate", "unitcost", "unitprice", "price"],
    "amount": ["amount", "linetotal", "total", "lineamount"],
}
VOID = {"void", "voided", "deleted", "cancelled", "canceled"}
BUSINESS_WORDS = re.compile(r"\b(?:inc|llc|ltd|corp|company|co|hoa|association|management|properties|group)\b\.?", re.I)
MAX_FILE_BYTES = 200 * 1024 * 1024


class AnalyticsKeyMissing(Exception):
    """There is no analytics key: customers can't be given code names, so nothing is imported."""


# ---------------------------------------------------------------- the analytics key
def _key_path():
    return Path(names.ANALYTICS_KEY_FILE)


def analytics_key():
    """The key customer code names are made with: the env variable, else the 0600 key file. Never made here."""
    env = os.environ.get(names.ANALYTICS_KEY_ENV, "").strip()
    if env:
        return env.encode()
    p = _key_path()
    if p.is_file() and p.stat().st_size > 0:
        return p.read_bytes().strip()
    raise AnalyticsKeyMissing(f"no analytics key at {p} (run: python -m zorderz_hub keys init on a new install)")


def key_status():
    source = "env" if os.environ.get(names.ANALYTICS_KEY_ENV, "").strip() else str(_key_path())
    try:
        k = analytics_key()
    except AnalyticsKeyMissing:
        return {"state": "missing", "fingerprint": None, "source": source, "near_misses": []}
    return {"state": "ok", "fingerprint": hashlib.sha256(k).hexdigest()[:12], "source": source, "near_misses": []}


def init_analytics_key():
    """A brand-new install's key (32 random bytes, hex, 0600). Refuses to replace one."""
    p = _key_path()
    p.parent.mkdir(parents=True, exist_ok=True)
    fd = os.open(p, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, "w") as f:
        f.write(secrets.token_hex(32) + "\n")
    return key_status()["fingerprint"]


def _code(key, kind, text):
    return hmac.new(key, f"{kind}:{text}".encode("utf-8"), hashlib.sha256).hexdigest()[:16]


# ---------------------------------------------------------------- reading the export
def _norm_col(c):
    return re.sub(r"[^a-z0-9]", "", str(c or "").casefold())


def _files(import_dir):
    d = Path(import_dir) if import_dir else None
    if d is None or not d.is_dir():
        return []
    return sorted(p for p in d.iterdir() if p.is_file() and p.suffix.lower() == ".csv"
                  and p.stat().st_size <= MAX_FILE_BYTES)


def source_fingerprint(import_dir, customer_list=None):
    """Changes when any export changes (name, size, time); the watcher re-imports then."""
    h = hashlib.sha256()
    for p in _files(import_dir):
        st = p.stat()
        h.update(f"{p.name}|{st.st_size}|{int(st.st_mtime)}\n".encode())
    for extra in (customer_list, ):
        if extra and Path(extra).is_file():
            st = Path(extra).stat()
            h.update(f"list|{st.st_size}|{int(st.st_mtime)}\n".encode())
    return h.hexdigest()[:16]


def read_rows(import_dir):
    """Every export's rows under the canonical column names, the newest file winning for an invoice number."""
    frames = []
    for i, p in enumerate(_files(import_dir)):
        raw = p.read_bytes().decode("utf-8-sig", errors="replace")
        rows = list(csv.DictReader(io.StringIO(raw)))
        if not rows:
            continue
        cols = {_norm_col(c): c for c in rows[0].keys()}
        pick = {}
        for canon, options in COLUMNS.items():
            for o in options:
                if o in cols:
                    pick[canon] = cols[o]
                    break
        if "invoice_no" not in pick or "amount" not in pick:
            continue
        f = pd.DataFrame([{k: (r.get(v) or "").strip() for k, v in pick.items()} for r in rows])
        for k in COLUMNS:
            if k not in f:
                f[k] = ""
        f["_file"], f["_order"] = i, range(len(f))
        frames.append(f)
    if not frames:
        return pd.DataFrame(columns=list(COLUMNS) + ["_file", "_order"])
    allrows = pd.concat(frames, ignore_index=True)
    allrows = allrows[allrows["invoice_no"].astype(str).str.strip().ne("")]
    newest = allrows.groupby("invoice_no")["_file"].transform("max")
    return allrows[allrows["_file"].eq(newest)].reset_index(drop=True)


def _money_cents(v):
    s = re.sub(r"[^0-9.\-]", "", str(v or ""))
    try:
        return int(round(float(s) * 100)) if s not in ("", "-", ".") else None
    except ValueError:
        return None


def _num(v):
    s = re.sub(r"[^0-9.\-]", "", str(v or ""))
    try:
        return float(s) if s not in ("", "-", ".") else None
    except ValueError:
        return None


# ---------------------------------------------------------------- the pack's words
def _catalog(pack_dir):
    p = Path(pack_dir) / "catalog.yml" if pack_dir else None
    raw = yaml.safe_load(p.read_text(encoding="utf-8")) if p and p.is_file() else {}
    return (raw or {}).get("charts") or {}


def _phrases(words):
    return sorted({str(w).casefold() for w in (words or []) if str(w).strip()}, key=len, reverse=True)


class Classifier:
    def __init__(self, catalog):
        self.products = [(str(p["name"]), _phrases(p.get("words") or [p["name"]]), bool(p.get("units")))
                         for p in catalog.get("products") or [] if isinstance(p, dict) and p.get("name")]
        wts = [w for w in catalog.get("work_types") or [] if isinstance(w, dict) and w.get("name")]
        self.work_types = [(str(w["name"]), _phrases(w.get("words"))) for w in wts]
        dflt = [w["name"] for w in wts if w.get("default")]
        self.default_work = str(dflt[0] if dflt else (wts[0]["name"] if wts else ""))
        # stored as "<group name>: <tag>" (compute reads a tag's group from it)
        self.tags = [(f"{g.get('name')}: {t['name']}", _phrases(t.get("words") or [t["name"]]))
                     for g in catalog.get("tag_groups") or [] for t in (g.get("tags") or []) if t.get("name")]
        self.not_work = _phrases(catalog.get("not_work_words"))
        self.other = str(catalog.get("other_product") or "Other")

    @staticmethod
    def _has(text, phrase):
        return re.search(r"(?<![a-z0-9])" + re.escape(phrase) + r"(?![a-z0-9])", text) is not None

    def line(self, text):
        t = str(text or "").casefold()
        product, counts, mentioned = self.other, False, []
        best = 0
        for name, phrases, units in self.products:
            for ph in phrases:
                if self._has(t, ph):
                    mentioned.append(name)
                    if len(ph) > best:
                        product, counts, best = name, units, len(ph)
                    break
        action = self.default_work
        for name, phrases in self.work_types:
            if any(self._has(t, ph) for ph in phrases):
                action = name
                break
        tags = [name for name, phrases in self.tags if any(self._has(t, ph) for ph in phrases)]
        work = not any(self._has(t, ph) for ph in self.not_work)
        return product, counts, list(dict.fromkeys(mentioned)), action, tags, work


def _clean_text(text, limit=120):
    """The line's text with anything that could be personal taken out (numbers of 5+ digits, e-mail and web
    addresses, phone-like runs)."""
    t = re.sub(r"\S+@\S+|https?://\S+|www\.\S+", " ", str(text or ""))
    t = re.sub(r"\+?\d[\d\s().-]{6,}\d", " ", t)
    t = re.sub(r"\d{5,}", " ", t)
    return " ".join(t.split())[:limit]


# ---------------------------------------------------------------- the people and places in the pack
def _parties(pack_dir):
    p = Path(pack_dir) / "parties.yml" if pack_dir else None
    raw = yaml.safe_load(p.read_text(encoding="utf-8")) if p and p.is_file() else {}
    ch = (raw or {}).get("charts") or {}
    people = [str(x) for x in ch.get("salespeople") or []]
    names_ = {}
    for ini, words in (ch.get("salesperson_names") or {}).items():
        for w in (words if isinstance(words, list) else [words]):
            names_[str(w).casefold()] = str(ini)
    return people, names_, str(ch.get("not_recorded") or "(not recorded)")


def _territories(pack_dir):
    p = Path(pack_dir) / "territories.yml" if pack_dir else None
    raw = yaml.safe_load(p.read_text(encoding="utf-8")) if p and p.is_file() else {}
    ch = (raw or {}).get("charts") or {}
    canon = {}
    for a in ch.get("areas") or []:
        canon[str(a).casefold()] = str(a)
    for a, vs in (ch.get("aliases") or {}).items():
        for v in vs or []:
            canon[str(v).casefold()] = str(a)
    return canon


def _salesperson(raw, people, by_name, none):
    v = str(raw or "").strip()
    if not v:
        return none
    if v in people:
        return v
    first = v.split()[0].casefold()
    return by_name.get(v.casefold()) or by_name.get(first) or none


# ---------------------------------------------------------------- build
def build(import_dir, customer_list=None, key=None, territory_index=None, place_index=None, pack_dir=None):
    """(tables, meta) from the import folder: the clean-table contract (store.REQUIRED)."""
    from .. import accounts as acct
    if key is None:
        key = analytics_key()
    key = key if isinstance(key, (bytes, bytearray)) else str(key).encode()
    pack_dir = pack_dir or str(names.PACK_DIR)
    cls = Classifier(_catalog(pack_dir))
    people, by_name, none = _parties(pack_dir)
    towns = _territories(pack_dir)
    rows = read_rows(import_dir)

    # who each invoice is for: a code name from the first of e-mail, phone, name and ZIP
    def who(r):
        email = str(r["email"]).casefold().strip()
        phone = re.sub(r"\D", "", str(r["phone"]))[-10:]
        name = re.sub(r"\s+", " ", str(r["customer"] or r["business"]).casefold()).strip()
        z = str(r["zip"])[:5]
        ident = email or (phone if len(phone) >= 7 else "") or f"{name}|{z}"
        return _code(key, "c", ident)

    def home(r):
        st = re.sub(r"\s+", " ", str(r["street"]).casefold()).strip()
        return _code(key, "h", f"{st}|{str(r['zip'])[:5]}") if st else None

    match_accounts = acct.matchers(acct.config_path(), key)
    lines, invoices, cust_rows = [], {}, {}
    for r in rows.to_dict("records"):
        inv = str(r["invoice_no"]).strip()
        issued = pd.to_datetime(r["issued"], errors="coerce")
        status = str(r["status"] or "").strip().casefold() or "sent"
        ck = who(r)
        hk = home(r) or ck
        area = towns.get(str(r["town"]).casefold().strip(), "")
        rep = _salesperson(r["salesperson"], people, by_name, none)
        product, counts, mentioned, action, tags, work = cls.line(r["description"])
        cents = _money_cents(r["amount"])
        qty = _num(r["qty"])
        if status in VOID:
            work = False
        lines.append({
            "line_id": f"{inv}-{len(lines) + 1}", "invoice_no": inv, "customer_key": ck, "issued": issued,
            "status": status, "description_clean": _clean_text(r["description"]), "family": product,
            "action": action, "families_mentioned": "; ".join(mentioned), "tags": "; ".join(tags),
            "inferred": "", "work": bool(work and cents is not None), "qty": qty,
            "units": qty if counts and qty is not None else None,
            "est_units": qty if counts and qty is not None else None, "est_basis": "",
            "line_cents": cents, "rate_cents": _money_cents(r["rate"]), "salesperson": rep, "area": area,
        })
        if inv not in invoices:
            invoices[inv] = {"invoice_no": inv, "customer_key": ck, "household_key": hk, "issued": issued,
                             "status": status, "lead_source": str(r["lead_source"] or "").strip(),
                             "salesperson": rep, "area": area}
        c = cust_rows.setdefault(ck, {"customer_key": ck, "household_key": hk,
                                      "is_business": bool(str(r["business"]).strip())
                                      or bool(BUSINESS_WORDS.search(str(r["customer"]))),
                                      "jobs": set(), "zip5": str(r["zip"])[:5] if re.match(r"^\d{5}", str(r["zip"])) else "",
                                      "account_key": None, "_street": str(r["street"]).strip()})
        if work and cents:
            c["jobs"].add(inv)
        if c["is_business"] and c["account_key"] is None:
            c["account_key"] = match_accounts.get(acct.norm(r["business"] or r["customer"]))

    placed = {"customers": 0, "in_site": 0, "in_council": 0, "with_street": 0}
    customers = []
    for c in cust_rows.values():
        street = c.pop("_street")
        c["jobs"] = len(c["jobs"])
        c["safety_site"], c["safety_council"] = "", ""
        c["tax_area"], c["tax_rate"], c["coast_mi"] = None, None, None
        if street and c["zip5"]:
            placed["with_street"] += 1
            if territory_index is not None:
                sites, council = territory_index.match(street, c["zip5"])
                c["safety_site"], c["safety_council"] = "; ".join(sites), council or ""
                placed["in_site"] += bool(sites)
                placed["in_council"] += bool(council)
            if place_index is not None:
                try:
                    at = place_index.locate(street, c["zip5"])
                    d = place_index.describe([at])[0] if at is not None else None
                except Exception:
                    d = None
                if d:
                    c["tax_area"], c["tax_rate"], c["coast_mi"] = d.get("tax_area"), d.get("tax_rate"), d.get("coast_mi")
                    placed["customers"] += 1
        customers.append(c)

    line_cols = ["line_id", "invoice_no", "customer_key", "issued", "status", "description_clean", "family",
                 "action", "families_mentioned", "tags", "inferred", "work", "qty", "units", "est_units",
                 "est_basis", "line_cents", "rate_cents", "salesperson", "area"]
    tables = {
        "lines_clean": pd.DataFrame(lines, columns=line_cols),
        "invoices_clean": pd.DataFrame(list(invoices.values()),
                                       columns=["invoice_no", "customer_key", "household_key", "issued", "status",
                                                "lead_source", "salesperson", "area"]),
        "customers_clean": pd.DataFrame(customers,
                                        columns=["customer_key", "household_key", "is_business", "jobs", "zip5",
                                                 "account_key", "safety_site", "safety_council", "tax_area",
                                                 "tax_rate", "coast_mi"]),
        "lot_components_clean": pd.DataFrame(columns=["line_id", "family", "est_units", "est_cents"]),
    }
    issued = tables["lines_clean"]["issued"].dropna()
    meta = {
        "adapter": ADAPTER_NAME,
        "fingerprint": source_fingerprint(import_dir, customer_list),
        "data_as_of": issued.max().strftime("%Y-%m-%d") if len(issued) else None,
        "files": len(_files(import_dir)),
        "territories": {"in_safety_site": placed["in_site"], "in_safety_council": placed["in_council"],
                        "with_street": placed["with_street"]},
        "places": {"placed": placed["customers"]},
    }
    return tables, meta


def suggest_accounts(import_dir, customer_list=None, min_jobs=1, limit=0):
    """(items, n): the business customers in the exports, most jobs first, for a starting accounts list."""
    from .. import accounts as acct
    rows = read_rows(import_dir)
    jobs = {}
    for r in rows.to_dict("records"):
        name = str(r["business"] or "").strip() or (str(r["customer"]).strip()
                                                    if BUSINESS_WORDS.search(str(r["customer"])) else "")
        if not name:
            continue
        k = acct.norm(name)
        e = jobs.setdefault(k, {"name": name, "other_spellings": set(), "jobs": set()})
        if name != e["name"]:
            e["other_spellings"].add(name)
        e["jobs"].add(str(r["invoice_no"]))
    items = [{"name": e["name"], "other_spellings": sorted(e["other_spellings"]), "jobs_when_listed": len(e["jobs"])}
             for e in jobs.values() if len(e["jobs"]) >= max(1, int(min_jobs or 1))]
    items.sort(key=lambda x: (-x["jobs_when_listed"], x["name"].casefold()))
    n = len(items)
    return (items[:limit] if limit else items), n
