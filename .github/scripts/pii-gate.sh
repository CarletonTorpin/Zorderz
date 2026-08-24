#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# PII / tenant-data scan gate for the PUBLIC Zorderz release artifacts.
#
# Fails the build if any confidential term (company / roster / product / customer)
# appears in the built zips. This is the safety gate that makes the open-source
# extraction safe to publish: Core must name no business.
#
# The wordlist is CONFIDENTIAL and MUST NOT live in this public repo. Supply it at
# runtime via the ZDZ_PII_WORDLIST environment variable — a newline-separated list
# of terms — wired from a GitHub Actions *secret*:
#
#     env:
#       ZDZ_PII_WORDLIST: ${{ secrets.ZDZ_PII_WORDLIST }}
#
# This script never prints the wordlist or the matched text — only the offending
# file and a redacted match count — so a PUBLIC CI log cannot leak the terms.
#
# Usage:  bash .github/scripts/pii-gate.sh [dist-dir]   (default: dist)
# Exit:   0 clean · 1 leak found · 2 misconfigured (wordlist/zips missing)
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail

if [ -z "${ZDZ_PII_WORDLIST:-}" ]; then
  echo "PII gate: ZDZ_PII_WORDLIST is not set." >&2
  echo "  Wire the confidential wordlist in as a repository secret and pass it into this" >&2
  echo "  step's env. Refusing to report a gate as passed when it never actually ran." >&2
  exit 2
fi

DIST_DIR="${1:-dist}"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

shopt -s nullglob
zips=( "$DIST_DIR"/*.zip )
if [ "${#zips[@]}" -eq 0 ]; then
  echo "PII gate: no zips found in '$DIST_DIR' — run ./build.sh first." >&2
  exit 2
fi

for z in "${zips[@]}"; do
  unzip -q -o "$z" -d "$work/$(basename "$z" .zip)"
done

# One fixed-string term per line; drop blanks. (Fixed strings, case-insensitive —
# no regex, so tenant terms with punctuation are matched literally.)
pat="$work/.patterns"
printf '%s\n' "$ZDZ_PII_WORDLIST" | sed '/^[[:space:]]*$/d' > "$pat"
terms="$(wc -l < "$pat" | tr -d ' ')"
echo "PII gate: scanning ${#zips[@]} artifact(s) against ${terms} confidential term(s)…"

fail=0
while IFS= read -r f; do
  n="$(grep -F -i -c -f "$pat" "$f" 2>/dev/null || true)"
  if [ "${n:-0}" -gt 0 ]; then
    echo "  LEAK: $(printf '%s' "$f" | sed "s#${work}/##")  (${n} match(es); term redacted)"
    fail=1
  fi
done < <(find "$work" -type f ! -name '.patterns' \
           \( -name '*.php' -o -name '*.js'  -o -name '*.css'  -o -name '*.html' \
           -o -name '*.json' -o -name '*.txt' -o -name '*.md'  -o -name '*.yml' \
           -o -name '*.yaml' -o -name '*.svg' -o -name '*.pot' -o -name '*.po' \) )

if [ "$fail" -ne 0 ]; then
  echo "PII gate: FAILED — confidential terms found in the release artifacts (terms redacted from this log)." >&2
  exit 1
fi
echo "PII gate: clean — 0 confidential terms in the release artifacts."
