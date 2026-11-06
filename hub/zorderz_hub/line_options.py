"""Tap-to-change line options for the Estimate Creator.

The vocabulary (which words in a line can be swapped for what) is the pack's line_options.json. This module
counts, on the hub's own invoice lines, how many jobs used each option in each product family, and returns the
map the website's Estimate Creator turns into press-and-hold menus. The result holds product words and counts
only: no names, addresses, money or invoice numbers, so it may leave this computer (op line_options, callers
"app:*" and "ui").

  family  = first family (in map order) with a match word in the line;
  hits    = every synonym of the family's slots + the colors, longest first, on word boundaries,
            never overlapping an earlier hit;
  sizes   = a slot with `needs_next` words counts only when one follows within two words of the same clause
            and the word before is not a number ("35 1/2" is a measurement);
  colors  = slot from the words after it in the same clause (color_context), else the family default.
"""
import collections
import datetime
import json
import re
import threading
from pathlib import Path

MAP_VERSION = 1
MIN_TEXT = 3
LEXICON_FILE = "line_options.json"
MAX_MAP_BYTES = 200_000

WORD = re.compile(r"[a-z0-9]")



def _is_word(ch):
    return bool(ch) and bool(WORD.match(ch))


def family_of(text, fmap):
    t = " " + norm(text) + " "
    for fam in fmap["families"]:
        for w in fam["match"]:
            if _find_bounded(t, w) is not None:
                return fam
    return None


def norm(s):
    s = (s or "").lower().replace("“", '"').replace("”", '"').replace("″", '"')
    return s.replace("’", "'").replace("–", "-").replace("—", "-")


def _find_bounded(t, w, start=0):
    i = t.find(w, start)
    while i != -1:
        before = t[i - 1] if i > 0 else ""
        after = t[i + len(w)] if i + len(w) < len(t) else ""
        if not _is_word(before) and not (_is_word(w[-1]) and _is_word(after)):
            return i
        i = t.find(w, i + 1)
    return None


def _all_bounded(t, w):
    out, i = [], 0
    while True:
        j = _find_bounded(t, w, i)
        if j is None:
            return out
        out.append(j)
        i = j + 1


CLAUSE_END = re.compile(r"[,;.+()\n]|\s-\s|&")


def _next_words(t, end, n):
    """Up to n words after `end`, never past the end of the clause (a comma, period, etc.)."""
    seg = t[end:end + 60]
    m = CLAUSE_END.search(seg)
    if m:
        seg = seg[:m.start()]
    return re.findall(r"[a-z0-9/%\"]+", seg)[:n]


def _prev_word(t, start):
    m = re.findall(r"[a-z0-9/%\"]+", t[max(0, start - 20):start])
    return m[-1] if m else ""


def candidates(fmap, fam):
    """(synonym, slot, option_id) for this family, longest synonym first. Color words carry
    slot None (resolved later)."""
    out = []
    slots = fam["slots"] if fam else []
    for sid in slots:
        slot = fmap["slots"][sid]
        if slot.get("kind") == "color":
            continue
        for opt in slot["options"]:
            for s in opt["say"]:
                out.append((norm(s), sid, opt["id"]))
    if fam and any(fmap["slots"][s].get("kind") == "color" for s in slots):
        for c in fmap["colors"]:
            for s in c["say"]:
                out.append((norm(s), None, c["id"]))
    out.sort(key=lambda x: (-len(x[0]), x[0]))
    return out


def color_slot(fmap, fam, t, end):
    after = " ".join(_next_words(t, end, 3))
    for slot in [x for x in fam["slots"] if fmap["slots"][x].get("kind") == "color"]:
        for w in (fmap.get("color_context") or {}).get(slot, []):
            w = norm(w)
            if after.startswith(w) and not (len(after) > len(w) and _is_word(after[len(w)]) and _is_word(w[-1])):
                return slot
    return fam.get("color_default")


def tokens(text, fmap, fam=None):
    """List of dicts {start, end, text, slot, option} over `text` (original offsets)."""
    if fam is None:
        fam = family_of(text, fmap)
    if not fam:
        return []
    t = norm(text)
    taken = [False] * len(t)
    out = []
    for syn, sid, oid in candidates(fmap, fam):
        for i in _all_bounded(t, syn):
            j = i + len(syn)
            if any(taken[i:j]):
                continue
            slot = sid
            if sid is not None and fmap["slots"][sid].get("needs_next"):
                nxt = _next_words(t, j, 2)
                need = fmap["slots"][sid].get("needs_next", [])
                if not any(w in need for w in nxt):
                    continue
                if re.fullmatch(r"\d+", _prev_word(t, i) or "x"):
                    continue
            if sid is None:
                slot = color_slot(fmap, fam, t, j)
                if not slot:
                    continue
            for k in range(i, j):
                taken[k] = True
            out.append({"start": i, "end": j, "text": text[i:j], "slot": slot, "option": oid})
    out.sort(key=lambda x: x["start"])
    return out


def apply_case(original, write):
    if len(original) > 1 and original.isupper():
        return write.upper()
    if original[:1].isupper():
        return write[:1].upper() + write[1:]
    return write


def build(lines, lexicon, today=None):
    seen = collections.defaultdict(set)          # (fam, slot, opt) -> invoices
    last = {}                                    # (fam, slot, opt) -> year
    fam_inv = collections.defaultdict(set)
    fam_lines = collections.Counter()
    fam_hit = collections.Counter()
    unknown = collections.defaultdict(collections.Counter)
    n = 0
    invs = set()
    dmin, dmax = "9999", "0000"
    known_words = set()
    for sl in lexicon["slots"].values():
        for o in sl.get("options", []):
            for s in o["say"]:
                known_words.update(re.findall(r"[a-z]+", norm(s)))
    for c in lexicon["colors"]:
        for s in c["say"]:
            known_words.update(re.findall(r"[a-z]+", norm(s)))

    for no, date, text, qty, rate, total in lines:
        if total == 0 or len(text) < MIN_TEXT:
            continue
        n += 1
        invs.add(no)
        dmin, dmax = min(dmin, date), max(dmax, date)
        fam = family_of(text, lexicon)
        if not fam:
            continue
        fid = fam["id"]
        fam_lines[fid] += 1
        fam_inv[fid].add(no)
        toks = tokens(text, lexicon, fam)
        if toks:
            fam_hit[fid] += 1
        for tk in toks:
            key = (fid, tk["slot"], tk["option"])
            seen[key].add(no)
            last[key] = max(last.get(key, "0000"), date[:4])
        # words near the swappable ones that the lexicon doesn't know (report only)
        for w in re.findall(r"[a-z][a-z-]{3,}", norm(text)):
            if w not in known_words:
                unknown[fid][w] += 1

    out_fams = []
    for f in lexicon["families"]:
        fid = f["id"]
        slots = {}
        for sid in f["slots"]:
            slot = lexicon["slots"][sid]
            opts = lexicon["colors"] if slot.get("kind") == "color" else slot["options"]
            ranked = []
            for o in opts:
                k = (fid, sid, o["id"])
                ranked.append({"id": o["id"], "jobs": len(seen.get(k, ())), "last": last.get(k)})
            ranked.sort(key=lambda r: (-r["jobs"], [o["id"] for o in opts].index(r["id"])))
            slots[sid] = ranked
        out_fams.append({
            "id": fid, "label": f["label"], "match": f["match"], "color_default": f.get("color_default"),
            "slots": f["slots"], "ranked": slots,
            "jobs": len(fam_inv[fid]), "lines": fam_lines[fid],
            "lines_with_a_choice": fam_hit[fid],
        })

    slots_out = {}
    for sid, slot in lexicon["slots"].items():
        s = {"label": slot["label"]}
        if slot.get("kind"):
            s["kind"] = slot["kind"]
        if "options" in slot:
            s["options"] = slot["options"]
        if "needs_next" in slot:
            s["needs_next"] = slot["needs_next"]
        slots_out[sid] = s

    fmap = {
        "map_version": MAP_VERSION,
        "built": (today or datetime.date.today()).isoformat(),
        "source": {"lines": n, "invoices": len(invs), "from": dmin if n else None, "to": dmax if n else None},
        "colors": lexicon["colors"],
        "color_context": {k: v for k, v in lexicon["color_context"].items() if not k.startswith("_")},
        "slots": slots_out,
        "families": out_fams,
    }
    # Only words seen 25+ times: product vocabulary recurs, a person's name almost never does.
    report = {f: [(w, c) for w, c in unknown[f].most_common(60) if c >= 25][:40] for f in unknown}
    return fmap, report


def write_report(fmap, unknown, path):
    L = ["# Line-option map report", "",
         f"Built {fmap['built']} from {fmap['source']['lines']:,} priced lines on {fmap['source']['invoices']:,} invoices "
         f"({fmap['source']['from']} to {fmap['source']['to']}).", "",
         "| Family | Jobs | Lines | Lines with at least one tappable choice |", "|---|---:|---:|---:|"]
    for f in fmap["families"]:
        pct = (100.0 * f["lines_with_a_choice"] / f["lines"]) if f["lines"] else 0
        L.append(f"| {f['label']} | {f['jobs']:,} | {f['lines']:,} | {f['lines_with_a_choice']:,} ({pct:.0f}%) |")
    L.append("")
    for f in fmap["families"]:
        L.append(f"## {f['label']}")
        for sid, ranked in f["ranked"].items():
            used = [f"{r['id']} {r['jobs']}" for r in ranked if r["jobs"]]
            L.append(f"- **{fmap['slots'][sid]['label']}**: " + (", ".join(used) if used else "(none seen)"))
        unk = unknown.get(f["id"], [])
        if unk:
            L.append("- Words the map doesn't know yet (counts): " + ", ".join(f"{w} {c}" for w, c in unk[:25]))
        L.append("")
    open(path, "w").write("\n".join(L))


def load_lexicon(pack_dir):
    """The pack's vocabulary, or None when the pack has none (the op then says not_configured)."""
    if not pack_dir:
        return None
    p = Path(pack_dir) / LEXICON_FILE
    if not p.exists():
        return None
    lex = json.loads(p.read_text())
    if not isinstance(lex.get("families"), list) or not isinstance(lex.get("slots"), dict):
        raise ValueError("line_options.json: families and slots are required")
    return lex


def lines_of(data):
    """(invoice_no, iso_date, text, qty, rate, total) for every priced line in the hub's store."""
    df = data.lines
    if df is None or not len(df):
        return []
    cols = ["invoice_no", "issued", "description_clean", "line_cents"]
    if any(c not in df for c in cols):
        return []
    out = []
    for no, issued, text, cents in df[cols].itertuples(index=False, name=None):
        try:
            total = float(cents or 0) / 100.0
        except (TypeError, ValueError):
            total = 0.0
        if total == 0 or not isinstance(text, str):
            continue
        day = issued.strftime("%Y-%m-%d") if hasattr(issued, "strftime") else str(issued or "")[:10]
        if not re.fullmatch(r"\d{4}-\d{2}-\d{2}", day):
            continue
        out.append((str(no), day, text, 1.0, total, total))
    return out


class Cache:
    """One built map per (data fingerprint, lexicon mtime). Building takes a second or two on
    the real store, so it happens once per import, not per request."""

    def __init__(self):
        self.lock = threading.Lock()
        self.key = None
        self.value = None

    def get(self, data, pack_dir):
        p = Path(pack_dir) / LEXICON_FILE if pack_dir else None
        mtime = p.stat().st_mtime if p and p.exists() else None
        fp = (data.meta.get("fingerprint") or data.meta.get("data_as_of")) if data is not None else None
        key = (fp, mtime)
        with self.lock:
            if self.key == key and self.value is not None:
                return self.value
        lex = load_lexicon(pack_dir)
        if lex is None:
            return None
        fmap, _unknown = build(lines_of(data) if data is not None else [], lex)
        size = len(json.dumps(fmap, ensure_ascii=False))
        if size > MAX_MAP_BYTES:
            raise ValueError("line options map too large")
        with self.lock:
            self.key, self.value = key, fmap
        return fmap
