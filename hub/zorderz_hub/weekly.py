"""The weekly review: what Charts struggled with, turned into rule candidates.

All three are great."): a weekly summary of the questions Charts struggled with, to feed new wording into its
rules.

The misses log already records everything needed, on this computer only and with names masked: words the rules
couldn't place (`partly_read`), what the model's second look changed or was held back from, and the questions
Charts couldn't answer well (clarify, unsupported, empty, asked again, "Not right"). This module turns a week of
it into one short report, sorted by what would most improve the rules:

  1. words the rules didn't know, by how often they came up, each with a question it came from and what the
     second look did with it. A word that keeps coming up is a candidate for terms.yml or rules.yml;
  2. reading fields the second look wanted but the word gate held, by field. "agg held 9 times" with
     "lately" in every question is a reading rule to write, not a model to trust;
  3. the questions Charts couldn't answer, by kind.

It is written every Monday after the 02:17 self-check as logs/weekly-review-<date>.md, private to an admin
(0600). It holds questions as the misses log keeps them (accounts, partners and names masked), never figures.
Nothing leaves the hub computer: the self-check's /health block only says that a review exists and how many
candidates it has.
"""
import collections
import datetime as dt
import os
import re
from pathlib import Path

from .misses import KINDS, MissLog, redact

WEEKDAY = 0                   # Monday
SKIP_USERS = {"selfcheck"}    # the hub's own questions
TOP_WORDS = 25
EXAMPLES = 2
TROUBLE = ("clarify", "unsupported", "empty", "asked_again", "flagged")
_STOP = set("""a an and or the of in on at for by to from with into per each all any every our we us my me i you
it its is are was were be been do did does done how what which who when where why this that these those
""".split())


def _words(entry):
    raw = entry.get("unread") or []
    raw = [raw] if isinstance(raw, str) else raw if isinstance(raw, (list, tuple)) else []
    return [w for w in raw if isinstance(w, str) and w and w.lower() not in _STOP]


def _keys(value):
    """A list of field names, whatever shape a hand-edited line gave it."""
    value = [value] if isinstance(value, str) else value if isinstance(value, (list, tuple)) else []
    return [k for k in value if isinstance(k, str) and k]


def _tidy(e):
    """one entry with the types the report expects; a malformed line can't stop the weekly review."""
    e = dict(e)
    for k in ("question", "then_asked", "note", "outcome", "user"):
        if k in e and not isinstance(e[k], str):
            e[k] = "" if e[k] is None else str(e[k])
    e["applied"], e["suggested"] = _keys(e.get("applied")), _keys(e.get("suggested"))
    return e


def build(log_path, pack_dir=None, days=7, hub_version="", today=None):
    """(markdown text, summary dict) for the last `days` days of the misses log."""
    log = MissLog(log_path, pack_dir)
    entries = [_tidy(e) for e in log.read(days) if e.get("user") not in SKIP_USERS]
    today = today or dt.date.today()
    reviews = [e for e in entries if e.get("kind") == "review"]
    by_q = collections.defaultdict(list)
    for e in reviews:
        by_q[e.get("question", "")].append(e)

    # 1. words the rules didn't place
    words = collections.Counter()
    seen_q = collections.defaultdict(list)
    for e in entries:
        if e.get("kind") != "partly_read":
            continue
        for w in set(_words(e)):
            words[w] += 1
            if len(seen_q[w]) < EXAMPLES and e.get("question") not in seen_q[w]:
                seen_q[w].append(e.get("question", ""))
    # 2. what the second look was held back from, and what it applied
    held, applied, outcomes = collections.Counter(), collections.Counter(), collections.Counter()
    held_q = collections.defaultdict(list)
    for e in reviews:
        outcomes[e.get("outcome", "?")] += 1
        for k in e.get("applied") or []:
            applied[k] += 1
        for k in e.get("suggested") or []:
            if k in ("orientation", "labels"):
                continue                  # the page's, by design: not a rule to write
            held[k] += 1
            if len(held_q[k]) < EXAMPLES and e.get("question") not in held_q[k]:
                held_q[k].append(e.get("question", ""))
    # 3. questions Charts couldn't answer well
    trouble = {k: [e for e in entries if e.get("kind") == k] for k in TROUBLE}

    asked = sum(1 for e in entries if e.get("kind") == "asked")
    lines = [f"# Charts weekly review — {today.isoformat()} (last {days} days, hub {hub_version})", "",
             "What Charts struggled with, sorted by what would most improve its rules. Questions appear as the "
             "misses log keeps them: accounts, partners and people's names are masked. No figures.", ""]
    if asked:
        lines += [f"Questions asked (trial logging on): {asked}.", ""]

    lines += ["## 1. Words the rules didn't know", ""]
    if words:
        lines += ["A word that keeps coming up is a candidate for `terms.yml` (a synonym) or `rules.yml` (a reading).",
                  "", "| Word | Times | A question it came from | What the second look did |", "|---|---|---|---|"]
        for w, n in words.most_common(TOP_WORDS):
            q = seen_q[w][0] if seen_q[w] else ""
            r = by_q.get(q, [])
            did = r[-1].get("outcome", "") if r else "no look"
            if r and r[-1].get("applied"):
                did += " (" + ", ".join(r[-1]["applied"]) + ")"
            lines.append(f"| {_cell(w)} | {n} | {_cell(redact(q, pack_dir))} | {_cell(did)} |")
        lines.append("")
    else:
        lines += ["None this week.", ""]

    lines += ["## 2. What the second look wanted but wasn't allowed", "",
              "Held by the word and shape gates (0.16.6–0.18.0). A field held often, with the same kind of wording, "
              "is a reading the rules should make themselves.", ""]
    if held:
        lines += ["| Field | Held | Example questions |", "|---|---|---|"]
        for k, n in held.most_common():
            ex = " · ".join(f"\"{_cell(redact(q, pack_dir))}\"" for q in held_q[k])
            lines.append(f"| `{k}` | {n} | {ex} |")
        lines.append("")
    else:
        lines += ["Nothing held this week.", ""]
    if outcomes:
        lines += ["Looks: " + ", ".join(f"{k} {v}" for k, v in outcomes.most_common())
                  + ("; applied fields: " + ", ".join(f"{k} {v}" for k, v in applied.most_common()) if applied else "")
                  + ".", ""]

    lines += ["## 3. Questions Charts couldn't answer well", ""]
    any_trouble = False
    for k in TROUBLE:
        mine = trouble[k]
        if not mine:
            continue
        any_trouble = True
        lines += [f"**{KINDS[k]}** ({len(mine)})", ""]
        for e in mine[:10]:
            extra = ""
            if e.get("then_asked"):
                extra = f" → then asked \"{_cell(redact(e['then_asked'], pack_dir))}\""
            if e.get("note"):
                extra = f" — their note: \"{_cell(redact(e['note'], pack_dir))}\""
            lines.append(f"- \"{_cell(redact(e.get('question', ''), pack_dir))}\"{extra}")
        if len(mine) > 10:
            lines.append(f"- … and {len(mine) - 10} more (`zorderz_hub misses --since {days}d`)")
        lines.append("")
    if not any_trouble:
        lines += ["None this week.", ""]

    summary = {"date": today.isoformat(), "days": days, "words": len(words), "held": sum(held.values()),
               "trouble": sum(len(v) for v in trouble.values())}
    summary["candidates"] = summary["words"] + len(held)
    return "\n".join(lines) + "\n", summary


def _cell(text):
    return re.sub(r"\s+", " ", str(text)).replace("|", "/")[:160]


def write(cfg, hub_version="", today=None, days=7):
    """Write logs/weekly-review-<date>.md and return (path, summary)."""
    folder = Path(cfg.get("audit_log") or "logs/audit.jsonl").parent
    text, summary = build(folder / "misses.jsonl", cfg.get("pack_dir"), days, hub_version, today)
    path = folder / f"weekly-review-{summary['date']}.md"
    folder.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(f".{os.getpid()}.tmp")
    # created private (0600) from the start, not made private after the words are written
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w", encoding="utf-8") as f:
        f.write(text)
    os.chmod(tmp, 0o600)                       # in case the file was already there with wider rights
    tmp.replace(path)
    return path, summary


def latest(cfg):
    """{date, path name} of the newest review, or None."""
    folder = Path(cfg.get("audit_log") or "logs/audit.jsonl").parent
    found = sorted(folder.glob("weekly-review-*.md"))
    if not found:
        return None
    m = re.search(r"(\d{4}-\d\d-\d\d)", found[-1].name)
    return {"date": m.group(1) if m else None, "file": found[-1].name}
