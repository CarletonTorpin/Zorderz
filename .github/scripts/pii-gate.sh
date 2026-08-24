#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# PII / tenant-data scan gate for the PUBLIC Zorderz release artifacts.
#
# Fails the build if any confidential term (company / roster / product / customer)
# appears in the built zips. This is the safety gate that makes the open-source
# extraction safe to publish: Core must name no business.
#
# The wordlist is CONFIDENTIAL and MUST NEVER be handed to this repository — not
# committed, and NOT stored as a GitHub Actions secret (a repo secret still lives
# with the repo). This gate therefore runs PRIVATELY, never in public CI: run it in
# a private environment (e.g. a local checkout or a trusted session) where the
# wordlist is supplied out-of-band via the ZDZ_PII_WORDLIST environment variable —
# a newline-separated list of terms — and never persisted:
#
#     ZDZ_PII_WORDLIST="$(cat /path/to/private/wordlist.txt)" \
#       bash .github/scripts/pii-gate.sh dist
#
# The script itself holds no terms, so it is safe to keep in the repo. It never
# prints the wordlist or the matched text — only the offending file and a redacted
# match count — so even its output cannot leak the terms.
#
# Usage:  bash .github/scripts/pii-gate.sh [dist-dir]   (default: dist)
# Exit:   0 clean · 1 leak found · 2 misconfigured (wordlist/zips missing)
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail

if [ -z "${ZDZ_PII_WORDLIST:-}" ]; then
  echo "PII gate: ZDZ_PII_WORDLIST is not set." >&2
  echo "  Supply the confidential wordlist OUT-OF-BAND from a local file — never commit it and" >&2
  echo "  never store it as a repo / GitHub Actions secret (see the header). For example:" >&2
  echo "    ZDZ_PII_WORDLIST=\"\$(cat /path/to/wordlist.txt)\" bash \$0 dist" >&2
  echo "  Refusing to report a gate as passed when it never actually ran." >&2
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

# One fixed-string term per line; drop blanks. (Fixed strings, case-insensitive, matched on
# WORD BOUNDARIES via grep -F -w: a real token — a name like "Geoff" or a code like "EM" — is
# caught as a whole word, but NOT as a substring inside ordinary code such as "destroy",
# "system", or "ImageOffset". Dash-/space-separated tokens like "92119-EM" still match, since
# the separators are themselves word boundaries.)
pat="$work/.patterns"
printf '%s\n' "$ZDZ_PII_WORDLIST" | sed '/^[[:space:]]*$/d' > "$pat"
terms="$(wc -l < "$pat" | tr -d ' ')"
echo "PII gate: scanning ${#zips[@]} artifact(s) against ${terms} confidential term(s)…"

fail=0
while IFS= read -r f; do
  n="$(grep -F -w -i -c -f "$pat" "$f" 2>/dev/null || true)"
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
