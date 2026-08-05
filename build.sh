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
set -euo pipefail
cd "$(dirname "$0")"

VER="$(sed -n 's/^Version:[[:space:]]*//p' zorderz/style.css | head -1 | tr -d '\r')"
if [ -z "${VER}" ]; then
  echo "build.sh: could not read Version from zorderz/style.css" >&2
  exit 1
fi
echo "Building Zorderz ${VER}"

rm -rf dist
mkdir -p dist

# 1) The apps bundle on its own.
zip -rq "dist/zorderz-apps-${VER}.zip" zorderz-apps -x '*.git*' -x '*/.DS_Store'

# 2) Vendor the apps INTO the theme so one upload brings the whole platform.
rm -rf zorderz/bundled/zorderz-apps
mkdir -p zorderz/bundled
cp -r zorderz-apps zorderz/bundled/zorderz-apps
find zorderz/bundled/zorderz-apps -name '.git*' -prune -exec rm -rf {} + 2>/dev/null || true

# 3) The theme, now carrying the bundled apps.
zip -rq "dist/zorderz-theme-${VER}.zip" zorderz -x '*.git*' -x '*/.DS_Store'

echo "Done:"
ls -la dist/
