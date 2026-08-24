#!/usr/bin/env python3
"""
Diff-scoped PHP_CodeSniffer gate.

PHP_CodeSniffer reports on a whole file, but this codebase predates WPCS, so scanning a whole
changed file drowns a PR in pre-existing style debt and trains everyone to ignore the check.
This filter keeps only the findings that land on lines the PR actually ADDED or MODIFIED, so a
contributor is answerable for their own diff and nothing else. That is the behavior the security
gate was always meant to have (see .github/workflows/security.yml and docs/SECURITY-REVIEW.md).

Usage:
    phpcs --report=json --standard=phpcs-security.xml.dist <files> > phpcs.json || true
    python3 .github/scripts/phpcs-changed-lines.py <base_sha> <head_sha> phpcs.json

Exit code 1 if any ERROR-level finding falls on a changed line, else 0.
"""

import json
import os
import re
import subprocess
import sys


def sh(args):
    return subprocess.run(args, capture_output=True, text=True).stdout


def added_lines(base, head, relpath):
    """Line numbers added/modified in relpath between base and head (three-dot / merge-base diff)."""
    diff = sh(["git", "diff", "-U0", f"{base}...{head}", "--", relpath])
    lines = set()
    for m in re.finditer(r"^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@", diff, re.M):
        start = int(m.group(1))
        count = int(m.group(2)) if m.group(2) is not None else 1
        for i in range(start, start + count):
            lines.add(i)
    return lines


def main():
    if len(sys.argv) != 4:
        print("usage: phpcs-changed-lines.py <base_sha> <head_sha> <phpcs_json>", file=sys.stderr)
        return 2
    base, head, json_path = sys.argv[1], sys.argv[2], sys.argv[3]

    try:
        with open(json_path, encoding="utf-8") as fh:
            report = json.load(fh)
    except (OSError, ValueError) as exc:
        print(f"Could not read phpcs JSON ({exc}); treating as no findings.", file=sys.stderr)
        return 0

    repo_root = sh(["git", "rev-parse", "--show-toplevel"]).strip() or os.getcwd()
    files = report.get("files", {})
    offenders = []

    for path, data in files.items():
        rel = os.path.relpath(path, repo_root) if os.path.isabs(path) else path
        changed = added_lines(base, head, rel)
        if not changed:
            continue
        for msg in data.get("messages", []):
            if msg.get("type") != "ERROR":
                continue
            if msg.get("line") in changed:
                offenders.append((rel, msg.get("line"), msg.get("source", ""), msg.get("message", "")))

    if not offenders:
        print("PHPCS security gate: no findings on changed lines. Clean.")
        return 0

    print(f"PHPCS security gate: {len(offenders)} finding(s) on lines this change touched:\n")
    for rel, line, source, message in sorted(offenders):
        print(f"  {rel}:{line}  [{source}]\n      {message}")
    print("\nFix these on the lines you changed, or add a reviewed `// phpcs:ignore <sniff> -- reason`.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
