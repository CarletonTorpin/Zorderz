#!/usr/bin/env python3
"""
phpcs security baseline — generate an accepted-findings baseline, or subtract it
from a phpcs JSON report.

The Zorderz security gate scopes findings to the lines a change touches
(phpcs-changed-lines.py, see docs/SECURITY-REVIEW.md). That is ideal for ongoing
PRs, but the one-time extraction from the pre-WPCS private app adds the whole
codebase as "changed" in a single merge, which would light up hundreds of
pre-existing findings (overwhelmingly WPCS false positives — the SQL is
$wpdb->prepare()'d, nonces sit on REST/alias-map handlers, etc.). This baseline
grandfathers that import: CI subtracts baselined findings BEFORE the changed-lines
gate, so genuinely new risk on changed code still fails while inherited noise does
not. Full analysis: claude/ts-delta-analysis/phpcs-security-gate-findings.md.

A finding's signature is line-number-INDEPENDENT — sha1(relpath \\0 sniff \\0
normalized source-line text) — so the baseline survives edits that shift lines.

Usage:
  phpcs-baseline.py generate <phpcs.json> <baseline_out.json>
  phpcs-baseline.py filter   <phpcs.json> <baseline.json>   # prints pruned report JSON to stdout
"""
import hashlib
import json
import os
import re
import subprocess
import sys


def _repo_root():
    out = subprocess.run(
        ["git", "rev-parse", "--show-toplevel"], capture_output=True, text=True
    ).stdout.strip()
    return out or os.getcwd()


_ROOT = _repo_root()
_LINE_CACHE = {}


def _relpath(path):
    return os.path.relpath(path, _ROOT) if os.path.isabs(path) else path


def _norm_line(relpath, line):
    if relpath not in _LINE_CACHE:
        full = os.path.join(_ROOT, relpath)
        try:
            with open(full, encoding="utf-8", errors="replace") as fh:
                _LINE_CACHE[relpath] = fh.read().splitlines()
        except OSError:
            _LINE_CACHE[relpath] = []
    lines = _LINE_CACHE[relpath]
    text = lines[line - 1] if 0 < line <= len(lines) else ""
    return re.sub(r"\s+", " ", text).strip()


def _signature(relpath, source, line):
    raw = relpath + "\0" + source + "\0" + _norm_line(relpath, line)
    return hashlib.sha1(raw.encode("utf-8")).hexdigest()


def _load(path):
    with open(path, encoding="utf-8") as fh:
        return json.load(fh)


def generate(report_path, out_path):
    report = _load(report_path)
    sigs = set()
    for path, data in report.get("files", {}).items():
        rel = _relpath(path)
        for msg in data.get("messages", []):
            if msg.get("type") != "ERROR":
                continue
            sigs.add(_signature(rel, msg.get("source", ""), int(msg.get("line", 0))))
    payload = {
        "_note": (
            "Accepted phpcs security baseline for the Zorderz extraction. CI subtracts these "
            "signatures BEFORE the changed-lines gate so the one-time pre-WPCS import is "
            "grandfathered; new findings on changed code still fail. Signatures are "
            "line-number-independent hashes of (path, sniff, source-line text). Regenerate "
            "deliberately via a full-tree scan and review the diff; new code should be FIXED, "
            "not baselined."
        ),
        "count": len(sigs),
        "signatures": sorted(sigs),
    }
    with open(out_path, "w", encoding="utf-8") as fh:
        json.dump(payload, fh, indent=2)
        fh.write("\n")
    print(f"phpcs baseline: wrote {len(sigs)} signature(s) to {out_path}", file=sys.stderr)
    return 0


def filter_report(report_path, baseline_path):
    report = _load(report_path)
    baseline = set()
    if os.path.isfile(baseline_path):
        baseline = set(_load(baseline_path).get("signatures", []))
    removed = 0
    for path, data in report.get("files", {}).items():
        rel = _relpath(path)
        kept = []
        for msg in data.get("messages", []):
            if msg.get("type") == "ERROR" and _signature(
                rel, msg.get("source", ""), int(msg.get("line", 0))
            ) in baseline:
                removed += 1
                continue
            kept.append(msg)
        data["messages"] = kept
        if "errors" in data:
            data["errors"] = sum(1 for m in kept if m.get("type") == "ERROR")
        if "warnings" in data:
            data["warnings"] = sum(1 for m in kept if m.get("type") == "WARNING")
    json.dump(report, sys.stdout)
    print(f"phpcs baseline: subtracted {removed} baselined finding(s).", file=sys.stderr)
    return 0


def main():
    args = sys.argv[1:]
    if len(args) == 3 and args[0] == "generate":
        return generate(args[1], args[2])
    if len(args) == 3 and args[0] == "filter":
        return filter_report(args[1], args[2])
    print(
        "usage:\n"
        "  phpcs-baseline.py generate <phpcs.json> <baseline_out.json>\n"
        "  phpcs-baseline.py filter   <phpcs.json> <baseline.json>",
        file=sys.stderr,
    )
    return 2


if __name__ == "__main__":
    sys.exit(main())
