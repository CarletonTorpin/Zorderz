#!/usr/bin/env bash
#
# Zorderz security sentinel.
#
# Scans the ADDED lines of a diff for the small set of "tells" that a disguised bug-fix uses
# to switch off a security property (see docs/SECURITY-REVIEW.md and the audit). It looks only
# at added lines, so it never trips on existing safe code — only on a change that introduces a
# dangerous pattern. Two tiers:
#
#   BLOCK  -> unambiguous smells. Exit 1 (fails the build).
#   WARN   -> needs a human look. Printed, does not fail the build.
#
# Escape hatch: append  // sentinel:allow <reason>  to a line to consciously override, with a
# reason that shows up in the diff and in review. Use it rarely.
#
# Usage:
#   .github/scripts/security-sentinel.sh [BASE_REF]
# BASE_REF defaults to the PR base in CI, else origin/main, else HEAD~1.
#
set -uo pipefail
cd "$(git rev-parse --show-toplevel 2>/dev/null || echo .)"

BASE="${1:-}"
if [ -z "${BASE}" ]; then
  if [ -n "${GITHUB_BASE_REF:-}" ]; then
    BASE="origin/${GITHUB_BASE_REF}"
  elif git rev-parse --verify -q origin/main >/dev/null 2>&1; then
    BASE="origin/main"
  else
    BASE="$(git rev-parse -q --verify HEAD~1 2>/dev/null || echo HEAD)"
  fi
fi

DIFF="$(git diff --no-color --unified=0 "${BASE}...HEAD" -- '*.php' 2>/dev/null)"
if [ -z "${DIFF}" ]; then
  echo "security-sentinel: no PHP changes against ${BASE}; nothing to check."
  exit 0
fi

echo "security-sentinel: scanning added lines against ${BASE}"

printf '%s\n' "${DIFF}" | awk '
  function trim(s){ gsub(/^[ \t]+/,"",s); return s }
  function is_comment(s,  t){ t=trim(s); return (t ~ /^(\/\/|\*|\/\*|#)/) }
  function block(msg){ printf("::error file=%s,line=%d::BLOCK %s\n", f, cur, msg); errs++ }
  function warn(msg){  printf("::warning file=%s,line=%d::WARN %s\n", f, cur, msg); warns++ }

  /^\+\+\+ / { f=$2; sub(/^b\//,"",f); next }
  /^@@/ {
    # new-file line number is the +start of the hunk header
    if (match($0, /\+[0-9]+/)) { ln = substr($0, RSTART+1, RLENGTH-1) + 0 }
    next
  }
  /^-/ { next }                         # removed line: does not advance new-file counter
  /^\+/ {
    line = substr($0, 2)                # strip the leading +
    cur  = ln; ln++

    if (line ~ /sentinel:allow/) next   # conscious, documented override

    comment = is_comment(line)

    # ---- BLOCK tier -------------------------------------------------------
    # Bare unserialize() of anything (maybe_unserialize is preceded by _, so excluded).
    if (!comment && line ~ /(^|[^_A-Za-z])unserialize[ \t]*\(/)
      block("bare unserialize() — use json/maybe_unserialize; object injection risk")

    # eval / create_function.
    if (!comment && line ~ /(^|[^_A-Za-z])(eval|create_function)[ \t]*\(/)
      block("eval()/create_function() introduced")

    # Zip extraction shortcut that bypasses the per-entry path + filetype guard.
    if (!comment && line ~ /->extractTo[ \t]*\(/)
      block("ZipArchive::extractTo() bypasses the per-entry path/filetype guard")

    # Direct move_uploaded_file (uploads must go through WP media handling).
    if (!comment && line ~ /move_uploaded_file[ \t]*\(/)
      block("move_uploaded_file() — route uploads through WP media handling")

    # Shell exec family without escapeshellarg on the same line.
    if (!comment && (line ~ /(^|[^_A-Za-z])(shell_exec|passthru|proc_open|popen|system)[ \t]*\(/ \
                     || line ~ /(^|[^_A-Za-z])exec[ \t]*\(/) \
                 && line !~ /escapeshellarg/)
      block("shell exec without escapeshellarg()")

    # is_admin() used inside the apps auto-installer (context != capability).
    if (!comment && f ~ /class-zdz-apps-autoinstall\.php$/ && line ~ /is_admin[ \t]*\(/)
      block("is_admin() in the auto-installer — must stay current_user_can(install/activate)")

    # Login identity must never be sourced from a request in the magic-link bridge (audit H1).
    if (!comment && f ~ /class-zdz-magic-link-bridge\.php$/ \
                 && (line ~ /get_user_by[ \t]*\([^)]*(email|login)/ \
                     || line ~ /get_param[ \t]*\([^)]*(user_id|email|login)/))
      block("identity sourced from a request in the login bridge — the user id must come only from the server-side transient")

    # __return_true wired to a permission_callback.
    if (!comment && line ~ /permission_callback/ && line ~ /__return_true/)
      block("permission_callback => __return_true (open endpoint)")

    # ---- WARN tier --------------------------------------------------------
    # A loose comparison next to a token/secret/signature — hash_equals must be used.
    if (!comment && line ~ /(token|signature|hmac|nonce|secret|[^a-z]mac[^a-z]|[^a-z]sig[^a-z])/ \
                 && line ~ /[^!=<>]==[^=]/)
      warn("comparison near a token/secret/signature — use hash_equals()")

    # $wpdb with a request variable on the same line — check for prepare()/casts.
    if (!comment && line ~ /\$wpdb->/ && line ~ /\$_(GET|POST|REQUEST)/)
      warn("$wpdb used with request input — confirm $wpdb->prepare() and (int) casts")

    # A new phpcs:ignore on a DB line silences the SQL scanner.
    if (!comment && line ~ /phpcs:ignore/ && (line ~ /\$wpdb/ || line ~ /PreparedSQL/))
      warn("new phpcs:ignore on a DB line — justify the raw query in review")

    # Re-introducing raw inline serving in a knowledge route (audit H3): route through the
    # zkv_serve_file_headers() helper (nosniff + safe disposition) instead.
    if (!comment && f ~ /knowledge/ && line ~ /Content-Disposition:[ \t]*inline/)
      warn("raw inline Content-Disposition in a knowledge route — use zkv_serve_file_headers()")

    next
  }
  END {
    printf("\nsecurity-sentinel: %d block, %d warn\n", errs+0, warns+0)
    if (errs+0 > 0) exit 1
  }
'
STATUS=$?
if [ "${STATUS}" -ne 0 ]; then
  echo "security-sentinel: BLOCKING findings above. If one is a deliberate, reviewed change,"
  echo "append '// sentinel:allow <reason>' to that line. Otherwise, fix it."
fi
exit "${STATUS}"
