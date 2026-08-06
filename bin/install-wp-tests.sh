#!/usr/bin/env bash
# Install the WordPress PHPUnit test library and a test database.
# Standard wp-cli scaffold script, used by the CI "integration" job.
#
#   bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-db-create]
#
set -euo pipefail

DB_NAME=${1-wordpress_test}
DB_USER=${2-root}
DB_PASS=${3-root}
DB_HOST=${4-127.0.0.1}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo "$TMPDIR" | sed -e "s/\/$//")
WP_TESTS_DIR=${WP_TESTS_DIR-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress}

download() {
  if command -v curl >/dev/null; then curl -s "$1" >"$2";
  elif command -v wget >/dev/null; then wget -nv -O "$2" "$1";
  fi
}

if [[ $WP_VERSION == 'latest' ]]; then
  WP_TESTS_TAG="trunk"
  download https://api.wordpress.org/core/version-check/1.7/ "$TMPDIR/wp-latest.json"
  LATEST_VERSION=$(grep -o '"version":"[^"]*' "$TMPDIR/wp-latest.json" | sed 's/"version":"//' | head -1)
  WP_TESTS_TAG="tags/$LATEST_VERSION"
else
  WP_TESTS_TAG="tags/$WP_VERSION"
fi

install_wp() {
  [ -d "$WP_CORE_DIR" ] && return
  mkdir -p "$WP_CORE_DIR"
  download "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" "$TMPDIR/wordpress.tar.gz" || \
    download "https://wordpress.org/latest.tar.gz" "$TMPDIR/wordpress.tar.gz"
  tar --strip-components=1 -zxmf "$TMPDIR/wordpress.tar.gz" -C "$WP_CORE_DIR"
}

install_test_suite() {
  mkdir -p "$WP_TESTS_DIR"
  if [ ! -d "$WP_TESTS_DIR/includes" ]; then
    svn export --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
    svn export --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/" "$WP_TESTS_DIR/data"
  fi
  if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
    download "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s/youremptytestdbnamehere/$DB_NAME/;s/yourusernamehere/$DB_USER/;s/yourpasswordhere/$DB_PASS/;s|localhost|${DB_HOST}|" "$WP_TESTS_DIR/wp-tests-config.php"
  fi
}

create_db() {
  [ "$SKIP_DB_CREATE" = "true" ] && return
  mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="${DB_HOST%%:*}" --protocol=tcp || true
}

install_wp
install_test_suite
create_db
echo "WordPress test suite ready in $WP_TESTS_DIR"
