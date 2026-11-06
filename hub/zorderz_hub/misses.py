"""Misses: the questions Charts couldn't answer well, kept to improve it.

What is recorded, on this computer only:
  * clarify       Charts asked a question back instead of drawing a chart
  * unsupported   Charts said it can't answer that
  * unreadable    the question reader couldn't turn it into a valid chart
  * empty         a chart came back with no matching jobs
  * asked_again   the person asked something new within 90 seconds (the
                  previous answer probably wasn't what they wanted)
  * flagged       the person pressed "Not right" on an answer, with their note

Each entry holds the question with named accounts already swapped for ACCOUNT_n and listed partner businesses
for "Partner n", emails/phones/addresses/links/long numbers removed, what Charts understood (title, measure,
split, filters, dates) and its notes. Never figures, never customers. `python -m zorderz_hub misses` turns a
period into a short report to bring into a chat; it runs the same removal again.
"""
import json
import os
import re
import threading
import time
from datetime import datetime, timedelta
from functools import lru_cache
from pathlib import Path

import yaml

from . import names

RETRY_SECONDS = 90
KINDS = {
    "clarify": "Charts asked a question back",
    "unsupported": "Charts said it can't answer",
    "unreadable": "The question reader couldn't make a chart from it",
    "empty": "The chart came back empty",
    "asked_again": "Asked something else within 90 seconds",
    "flagged": "Marked \"Not right\"",
    "asked": "Every question, while trial logging is on (0.13.1)",
    "review": "The model's second look: what it changed or suggested (0.13.5)",
    "partly_read": "The question reader couldn't place every word (0.13.0)",
    "reader_check": "The question reader's spec failed a check (0.13.0)",
    "shadow_differs": "Shadow mode: the reader and the model read it differently (0.13.0)",
}

_STREET = (r"(?:St|Street|Ave|Avenue|Rd|Road|Dr|Drive|Ln|Lane|Ct|Court|Way|Blvd|Boulevard|Pl|Place|"
           r"Cir|Circle|Ter|Terrace|Pkwy|Parkway|Hwy|Highway|Trl|Trail|Loop|Row|Glen|Point|Pt|Ridge|Run|Path)")
_REDACT = [
    (re.compile(r"[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}", re.I), "[email]"),
    (re.compile(r"\b(?:https?://|www\.)\S+", re.I), "[link]"),
    (re.compile(r"(?<![\w$])(?:\+?1[\s.\-]?)?\(?\d{3}\)?[\s.\-]?\d{3}[\s.\-]\d{4}(?!\w)"), "[phone]"),
    (re.compile(r"\b\d{1,6}[ \t]+(?:[NSEW]\.?[ \t]+)?(?:[A-Z][\w'.\-]*[ \t]+){0,4}" + _STREET + r"\b\.?"), "[address]"),
    (re.compile(r"(?<![\w$.,])\d{9,}(?!\w)"), "[number]"),
    (re.compile(r"\b(?:gate|lock\s?box|alarm|door|garage|access)\s*(?:code|combo|pin|#)\s*(?:is|:|=|-)?\s*[\w#*\-]{2,12}", re.I),
     "[code]"),
]


# people's names. The hub keeps no customer directory (the
# store drops every name column), so a name can't be looked up. Instead every
# Capitalised word is kept only if it is the hub's own vocabulary -- a word in the
# Identity Pack (towns, products, channel groups, partners, team names) or a word
# questions and charts are made of -- and becomes [name] otherwise. It errs toward
# hiding: this log exists to improve Charts, and "[name]" costs it little. Known
# limits: a name typed all in lower case, and a name that is also a pack word (a
# customer called Maple Falls, like the town) are not caught.
_COMMON = set("""
a an and or the of in on at for by to vs versus from with without per each all any every into out up down
show list compare chart graph table total totals revenue sales sold sell jobs job leads lead invoices invoice
estimates estimate count counts average avg median sum share percent percentage year years yearly month
months monthly week weeks weekly quarter quarters day days daily today yesterday tomorrow last this next
previous prior ago january february march april may june july august september october november december
jan feb mar apr jun jul aug sep sept oct nov dec monday tuesday wednesday thursday friday saturday sunday
q q1 q2 q3 q4 h1 h2 ytd mtd qtd why what how who which when where is are was were be been did do does done
has have had get got new existing customer customers source sources product products town towns area areas
city cities region zip zips code codes postal salesperson salespeople rep reps team top bottom best worst
most least more less than over under between since before after not no yes other others only just also
same different i me my mine we our ours us you your it its they them their please can could would should
will want need chart charts bar bars line lines pie number numbers drill open wrong right rate rates ratio
conversion close closed closing win won lost margin gross net profit cost costs price prices pricing paid
unpaid balance collected billed outstanding overdue due include including exclude excluding only cancelled
canceled pending approved sent draft booked scheduled install installs installed installation repeat
returning first time times growth trend trends change changes split breakdown group groups channel
channels type types category categories unit units item items size sizes door doors window
windows security
residential commercial business businesses homeowner homeowners referral referrals partner partners
google yelp facebook angi thumbtack nextdoor website web online phone call calls email walk walk-in
not-right
""".split())
_HONORIFIC = re.compile(r"\b(?:Mr|Mrs|Ms|Miss|Mx|Dr)\.?(?:[ \t]+[^\W\d_][\w'’\-]*){1,3}")
_WORD = re.compile(r"[^\W\d_][\w'’\-]*")
_NAMES_RUN = re.compile(r"\[name\](?:(?:[ \t]+(?:and|&|y)[ \t]+|[ \t,]+)\[name\])+")


@lru_cache(maxsize=4)
def _vocab(pack_dir=None):
    """Every word in the Identity Pack (keys and values, all files), lower-cased."""
    words = set()

    def walk(v):
        if isinstance(v, dict):
            for k, x in v.items():
                walk(k)
                walk(x)
        elif isinstance(v, (list, tuple)):
            for x in v:
                walk(x)
        elif isinstance(v, str):
            words.update(w.casefold() for w in _WORD.findall(v))

    for f in sorted(Path(pack_dir or names.PACK_DIR).glob("*.yml")):
        if f.name in names.PACK_REFERENCE_FILES:              
            continue
        try:
            walk(yaml.safe_load(f.read_text(encoding="utf-8")))
        except Exception:        # an unreadable pack file adds no words (so more is masked, never less)
            continue
    return frozenset(words)


def mask_names(text, pack_dir=None):
    known = _vocab(str(pack_dir) if pack_dir else None) | _COMMON
    text = _HONORIFIC.sub("[name]", text)

    def one(m):
        w = m.group(0)
        if not w[0].isupper() or w.startswith("ACCOUNT_"):
            return w
        core = re.sub(r"['’]s$", "", w)
        b = core.casefold()
        if b in known or all(p in known for p in re.split(r"[-'’]", b) if p):
            return w
        return "[name]" + w[len(core):]

    return _NAMES_RUN.sub("[name]", _WORD.sub(one, text))


def redact(text, pack_dir=None):
    text = str(text or "")
    for pat, rep in _REDACT:
        text = pat.sub(rep, text)
    try:
        from .partners import hide       # a listed partner's name never stays in clear
        text = mask_names(hide(text, pack_dir), pack_dir)
    except Exception:            # masking must never break a write; fail toward less text
        text = _WORD.sub(lambda m: m.group(0) if not m.group(0)[0].isupper() else "[word]", text)
    return text[:600]


def _clean(v, pack_dir=None, depth=0):
    """Any value from a spec, for the log: strings redacted, containers walked, the rest kept."""
    if depth > 4:
        return None
    if isinstance(v, str):
        return redact(v, pack_dir)[:200]
    if isinstance(v, dict):
        return {redact(str(k), pack_dir)[:40]: _clean(x, pack_dir, depth + 1) for k, x in list(v.items())[:20]}
    if isinstance(v, (list, tuple)):
        return [_clean(x, pack_dir, depth + 1) for x in list(v)[:20]]
    if isinstance(v, (int, float, bool)) or v is None:
        return v
    return redact(str(v), pack_dir)[:200]


_SPEC_WORD = re.compile(r"^[a-z_]{1,30}$")


def spec_summary(spec, pack_dir=None):
    """A "Not right" report carries a spec from the browser, so nothing in it is trusted to be clean."""
    spec = spec if isinstance(spec, dict) else {}
    f = spec.get("filters")
    f = f if isinstance(f, dict) else {}
    keep = {k: v for k, v in f.items() if v not in ([], None, False, "any")}
    if "accounts" in keep:
        n = keep["accounts"]
        keep["accounts"] = f"{len(n) if isinstance(n, (list, tuple)) else 1} named account(s)"
    keep = _clean(keep, pack_dir) or {}

    def enum(v):
        return v if isinstance(v, str) and _SPEC_WORD.match(v) else (None if v in (None, "") else "[other]")

    def day(v):
        return v if isinstance(v, str) and re.match(r"^\d{4}-\d{2}-\d{2}$", v) else None

    asm = spec.get("assumptions")
    asm = asm if isinstance(asm, (list, tuple)) else ([asm] if asm else [])
    return {k: v for k, v in {
        "title": redact(spec.get("title", ""), pack_dir),
        "intent": enum(spec.get("intent")),
        "measure": enum(spec.get("measure")), "split": enum(spec.get("split")), "facet": enum(spec.get("facet")),
        "time_grain": enum(spec.get("time_grain")), "date_from": day(spec.get("date_from")),
        "date_to": day(spec.get("date_to")),
        "chart_type": enum(spec.get("chart_type")), "filters": keep,
        "clarify_question": redact(spec.get("clarify_question", ""), pack_dir),
        "assumptions": [redact(a, pack_dir) for a in asm][:4],
        "reading": _reading(spec.get("v2"), pack_dir),
    }.items() if v not in (None, "", [], {}, "none", "auto")}


def _reading(v2, pack_dir=None):
    """the question reader's own reading (periods compared, overlay, averages,
    rankings), so a "Not right" shows what was actually run. Redacted like the rest."""
    if not isinstance(v2, dict) or not isinstance(v2.get("spec"), dict):
        return None
    sp = v2["spec"]
    out = {}
    for k in ("periods", "compare", "to_date", "to_date_cut", "overlay", "months", "grain", "date_part", "agg",
              "rank", "top_n", "share", "merge"):
        if k in sp:
            out[k] = sp[k]
    for k in ("kind", "source"):
        if isinstance(v2.get(k), str) and _SPEC_WORD.match(v2[k].replace("+", "_")):
            out[k] = v2[k]
    return _clean(out, pack_dir) or None


class MissLog:
    def __init__(self, path, pack_dir=None):
        self.path = Path(path)
        self.pack_dir = str(pack_dir) if pack_dir else None      # the configured pack's words
        self.lock = threading.Lock()
        self.last = {}           # user -> the previous ask, for "asked_again"

    def write(self, kind, user, question, spec=None, notes=None, **extra):
        entry = {"ts": time.strftime("%Y-%m-%dT%H:%M:%S"), "kind": kind, "user": str(user)[:40],
                 "question": redact(question, self.pack_dir)}
        if spec is not None:
            entry["understood"] = spec_summary(spec, self.pack_dir)
        if notes:
            entry["notes"] = [redact(n, self.pack_dir) for n in notes][:4]
        for k, v in extra.items():
            entry[k] = redact(v, self.pack_dir) if isinstance(v, str) else _clean(v, self.pack_dir)
        line = json.dumps(entry, ensure_ascii=False, separators=(",", ":"))
        with self.lock:
            self.path.parent.mkdir(parents=True, exist_ok=True)
            new = not self.path.exists()
            with open(self.path, "a", encoding="utf-8") as f:
                f.write(line + "\n")
            if new:
                os.chmod(self.path, 0o600)
        return entry

    def note_ask(self, user, question, spec, status, rows, notes, followup):
        """Called for every answered question. Logs the previous one as
        asked_again when a NEW question (not a follow-up) comes within 90 s."""
        now = time.time()
        prev = self.last.get(user)
        if prev and not followup and now - prev["t"] <= RETRY_SECONDS and not prev["logged"]:
            self.write("asked_again", user, prev["question"], prev["spec"], prev["notes"], then_asked=question)
        logged = False
        if status in ("clarify", "unsupported"):
            self.write(status, user, question, spec, notes)
            logged = True
        elif rows == 0:
            self.write("empty", user, question, spec, notes)
            logged = True
        self.last[user] = {"t": now, "question": question, "spec": spec, "notes": notes, "logged": logged}

    # ------------------------------------------------------------ report
    def read(self, since_days=7):
        cutoff = datetime.now() - timedelta(days=since_days)
        out = []
        try:
            with open(self.path, encoding="utf-8") as f:
                for line in f:
                    try:
                        e = json.loads(line)
                        if not isinstance(e, dict) or not isinstance(e.get("kind"), str):
                            continue
                        ts = datetime.fromisoformat(str(e["ts"]))
                        if ts.tzinfo is not None:         # a hand-edited or foreign line never stops a read
                            ts = ts.astimezone().replace(tzinfo=None)
                        if ts >= cutoff:
                            out.append(e)
                    except (ValueError, KeyError, TypeError, AttributeError):
                        continue
        except OSError:
            pass
        return out

    def report(self, since_days=7, hub_version=""):
        entries = self.read(since_days)
        lines = [f"# Charts misses — last {since_days} days (hub {hub_version})", "",
                 "Questions Charts couldn't answer well. Named accounts appear as ACCOUNT_n, partner businesses "
                 "as Partner n or [partner]; emails, phones, "
                 "addresses, links, long numbers and people's names ([name]) were removed. No figures are included.", ""]
        if not entries:
            lines.append("Nothing recorded in this period.")
            return "\n".join(lines) + "\n", {}
        counts = {}
        for kind, label in KINDS.items():
            mine = [e for e in entries if e["kind"] == kind]
            if not mine:
                continue
            counts[kind] = len(mine)
            lines += [f"## {label} ({len(mine)})", ""]
            for e in mine:
                lines.append(f"- **{e['ts'][:16].replace('T', ' ')}** · {e.get('user', '')} · \"{redact(e.get('question', ''), self.pack_dir)}\"")
                u = e.get("understood") or {}
                if u:
                    lines.append(f"  - understood as: {json.dumps(_clean(u, self.pack_dir), ensure_ascii=False)}")
                if e.get("notes"):
                    lines.append(f"  - notes: {' '.join(redact(n, self.pack_dir) for n in e['notes'])}")
                if e.get("then_asked"):
                    lines.append(f"  - then asked: \"{redact(e['then_asked'], self.pack_dir)}\"")
                if e.get("note"):
                    lines.append(f"  - their note: \"{redact(e['note'], self.pack_dir)}\"")
            lines.append("")
        return "\n".join(lines) + "\n", counts
