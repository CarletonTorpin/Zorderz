#!/usr/bin/env bash
#
# Build the two Zorderz release artifacts.
#
#   ./build.sh
#
# Produces, in dist/:
#   zorderz-theme-<version>.zip   The theme (platform kernel + Core services), with
#                                 the apps bundle VENDORED under zorderz/bundled/ so
#                                 a first install is ONE upload: once the theme is
#                                 active it installs + activates the apps itself
#                                 (see inc/class-zdz-apps-autoinstall.php).
#   zorderz-apps-<version>.zip    The apps bundle on its own. This is the update
#                                 path, and the manual second artifact for hosts
#                                 where the theme cannot write to wp-content/plugins.
#
# The version is read from the theme's style.css, so both artifacts always agree.
# The vendored copy under zorderz/bundled/zorderz-apps/ is a build product and is
# gitignored; only zorderz/bundled/.gitkeep is tracked.
#
# ── Completeness / packaging gate (PLAN AC3, the "partial-zip" defense) ──
# Before ANY packaging, and again after vendoring, the build refuses to produce a
# zip that is not whole: every file the app loaders reference must resolve, no
# include may be pulled through a silent glob(), declared hot-path classes must be
# defined in the tree, every app PHP file must lint, and the vendored file set must
# equal the source set. A missing includes/*.php that would only fatal later at
# wp_head (§74) is caught here instead of shipping.
#
set -euo pipefail
cd "$(dirname "$0")"

VER="$(sed -n 's/^Version:[[:space:]]*//p' zorderz/style.css | head -1 | tr -d '\r')"
if [ -z "${VER}" ]; then
  echo "build.sh: could not read Version from zorderz/style.css" >&2
  exit 1
fi
echo "Building Zorderz ${VER}"

# ── Hot-path classes per app slug — code metadata only (no business literal).
#    Mirrors ZDZ_Plugin_API::default_required_classes() (which keys by config id;
#    here we key by the manifest slug / directory name). Extend as apps declare
#    render-time classes.
declare -A HOT_CLASSES=(
  [surveys]="ZSV_Survey_Manager ZSV_DB"
  [analytics]="ZANA_Chat ZANA_Prompt_Builder ZANA_Markers"
)

gate_fail=0
gate_note() { echo "  build.sh GATE: $1" >&2; gate_fail=$((gate_fail + 1)); }

echo "Gate: verifying app-bundle completeness..."
manifest="zorderz-apps/zorderz-apps.php"
if [ ! -f "${manifest}" ]; then
  echo "build.sh: apps manifest not found: ${manifest}" >&2
  exit 1
fi

# Every "file => 'apps/<slug>/app.php'" the loader lists must resolve to a real file.
manifest_files="$(grep -oE "'file'[[:space:]]*=>[[:space:]]*'apps/[^']+\.php'" "${manifest}" \
  | sed -E "s/.*'(apps\/[^']+\.php)'.*/\1/" || true)"
if [ -z "${manifest_files}" ]; then
  gate_note "could not read any app 'file' entries from the manifest"
fi

while IFS= read -r rel; do
  [ -z "${rel}" ] && continue
  appfile="zorderz-apps/${rel}"
  appdir="$(dirname "${appfile}")"
  slug="$(basename "${appdir}")"

  if [ ! -f "${appfile}" ]; then
    gate_note "manifest lists ${rel} but it does not exist"
    continue
  fi

  # (b) Every APP-LOCAL loader-referenced *.php file must resolve. This is the
  #     independent source-of-truth: a partial SOURCE (a dropped includes/*.php the
  #     loader still requires) cannot pass. WordPress-core requires (ABSPATH .
  #     'wp-admin/…', wp-includes/…) are not app files and are skipped.
  while IFS= read -r line; do
    case "${line}" in
      *ABSPATH*|*WP_PLUGIN_DIR*|*WPMU_PLUGIN_DIR*|*WP_CONTENT_DIR*|*WPINC*) continue ;;
    esac
    ref="$(printf '%s' "${line}" | grep -oE "'[^']+\.php'|\"[^\"]+\.php\"" | head -1 | tr -d "\"'" || true)"
    [ -z "${ref}" ] && continue
    case "${ref}" in
      wp-admin/*|wp-includes/*) continue ;;
    esac
    target="${appdir}/${ref#/}"
    if [ ! -f "${target}" ]; then
      gate_note "${slug}: loader requires '${ref}' but ${target} is missing (partial zip)"
    fi
  done < <(grep -E '(require|include)(_once)?([[:space:]]|\()' "${appfile}" || true)

  # (b') A silent glob()-driven include is the exact §74 failure mode — forbid it
  #      on the load path (an enumerated require_once cannot silently skip a file).
  #      Catch a require/include and a glob() together on one line, in either order.
  if grep -E 'glob[[:space:]]*\(' "${appfile}" | grep -qE '(require|include)'; then
    gate_note "${slug}: app.php loads includes through glob() — enumerate require_once explicitly"
  fi

  # (d) Declared hot-path classes must be defined somewhere in the app tree (proves
  #     the defining file is present in the package).
  for cls in ${HOT_CLASSES[${slug}]:-}; do
    if ! grep -rqE "(^|[[:space:]])(abstract[[:space:]]+|final[[:space:]]+)?(class|interface|trait)[[:space:]]+${cls}\b" "${appdir}"; then
      gate_note "${slug}: declared hot-path class ${cls} is not defined under ${appdir} (missing file?)"
    fi
  done
done <<< "${manifest_files}"

# (e) Lint every app PHP file; a syntax error fatals the whole app at load.
while IFS= read -r -d '' php; do
  if ! php -l "${php}" >/dev/null 2>&1; then
    gate_note "PHP lint failed: ${php}"
  fi
done < <(find zorderz-apps -name '*.php' -not -path '*/.git*' -print0)

if [ "${gate_fail}" -ne 0 ]; then
  echo "build.sh: REFUSING to package — ${gate_fail} completeness problem(s) above." >&2
  exit 1
fi
echo "Gate: apps complete."

rm -rf dist
mkdir -p dist

# 1) The apps bundle on its own.
zip -rq "dist/zorderz-apps-${VER}.zip" zorderz-apps -x '*.git*' -x '*/.DS_Store'

# 2) Vendor the apps INTO the theme so one upload brings the whole platform.
rm -rf zorderz/bundled/zorderz-apps
mkdir -p zorderz/bundled
cp -r zorderz-apps zorderz/bundled/zorderz-apps
find zorderz/bundled/zorderz-apps -name '.git*' -prune -exec rm -rf {} + 2>/dev/null || true

# 2b) The packaged (vendored) file set MUST equal the source set — no stray, no
#     missing. Catches a copy that dropped or added a file before it ships.
if ! diff <(cd zorderz-apps && find . -type f -not -path '*/.git*' | sort) \
          <(cd zorderz/bundled/zorderz-apps && find . -type f -not -path '*/.git*' | sort) >/dev/null; then
  echo "build.sh: REFUSING to package — vendored apps set differs from source:" >&2
  diff <(cd zorderz-apps && find . -type f -not -path '*/.git*' | sort) \
       <(cd zorderz/bundled/zorderz-apps && find . -type f -not -path '*/.git*' | sort) >&2 || true
  exit 1
fi

# 3) The theme, now carrying the bundled apps.
zip -rq "dist/zorderz-theme-${VER}.zip" zorderz -x '*.git*' -x '*/.DS_Store'

echo "Done:"
ls -la dist/
